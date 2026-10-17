<?php 
/**
 * CEISA 4.0 Status Check by Perusahaan (status.php)
 * Mengambil seluruh status dan respon dokumen pabean untuk perusahaan dari API CEISA 4.0.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/datalogin.php';
require_once __DIR__ . '/init.php';

$targetNpwp = !empty($_GET['npwp']) ? trim($_GET['npwp']) : $npwp;
if (empty($targetNpwp)) {
    die("Nomor NPWP perusahaan tidak ditemukan!");
}

$hostprd = "https://apis-gw.beacukai.go.id/openapi/status?idPerusahaan=" . urlencode($targetNpwp);
$bearer  = $_SESSION["token"] ?? '';

$curl = curl_init(); 
curl_setopt_array($curl, [
    CURLOPT_URL => $hostprd,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "GET",
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $bearer",
        "Content-Type: application/json"
    ],
]);

$response = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);

if ($err) {
    die("cURL Error #: " . htmlspecialchars($err));
}

// Simpan respon mentah ke log
if (isset($koneksi) && $koneksi) {
    $stmtLog = $koneksi->prepare("INSERT INTO bc_ceisa_respon_logs (respon) VALUES (?)");
    if ($stmtLog) {
        $stmtLog->bind_param("s", $response);
        $stmtLog->execute();
        $stmtLog->close();
    }
}

$data = json_decode($response, true);
$status = $data['status'] ?? '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Status Dokumen Pabean - <?= htmlspecialchars($namaperusahaan ?: $targetNpwp) ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; padding: 20px; font-size: 13px; }
        h2 { font-size: 16px; margin: 20px 0 10px; color: #38bdf8; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 24px; background: #1e293b; border-radius: 8px; overflow: hidden; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #334155; text-align: left; }
        th { background: #0b1120; color: #94a3b8; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
        tr:hover td { background: rgba(255,255,255,0.02); }
        .badge { padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .badge-success { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        .badge-info { background: rgba(56, 189, 248, 0.2); color: #38bdf8; }
    </style>
</head>
<body>

<h1>Dokumen Pabean: <?= htmlspecialchars($namaperusahaan ?: $targetNpwp) ?></h1>
<p style="color:#94a3b8; margin-top:4px;">ID Perusahaan: <code><?= htmlspecialchars($targetNpwp) ?></code> | Company ID: <code><?= htmlspecialchars($cmp_id) ?></code></p>

<?php if ($status === 'Success' && isset($koneksi) && $koneksi): ?>

    <!-- Bagian 1: Status -->
    <h2>Status Dokumen Perekaman</h2>
    <table>
        <thead>
            <tr>
                <th>Nomor Aju</th>
                <th>Nomor Daftar</th>
                <th>Tanggal Daftar</th>
                <th>Kode Proses</th>
                <th>Waktu Status</th>
                <th>Keterangan</th>
                <th>Kode Dokumen</th>
                <th>Database</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $stmtStatus = $koneksi->prepare("
                INSERT INTO bc_ceisa_status 
                (cmp_id, nomor_aju, nomor_daftar, tanggal_daftar, kode_proses, waktu_status, keterangan, kode_dokumen) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if (!empty($data['dataStatus']) && is_array($data['dataStatus'])):
                foreach ($data['dataStatus'] as $r):
                    $nomorAju      = $r['nomorAju'] ?? '';
                    $nomorDaftar   = $r['nomorDaftar'] ?? '';
                    $tanggalDaftar = $r['tanggalDaftar'] ?? '';
                    $kodeProses    = $r['kodeProses'] ?? '';
                    $waktuStatus   = $r['waktuStatus'] ?? '';
                    $keterangan    = $r['keterangan'] ?? '';
                    $kodeDokumen   = $r['kodeDokumen'] ?? '';

                    $saved = false;
                    if ($stmtStatus) {
                        $stmtStatus->bind_param("ssssssss", $cmp_id, $nomorAju, $nomorDaftar, $tanggalDaftar, $kodeProses, $waktuStatus, $keterangan, $kodeDokumen);
                        $saved = $stmtStatus->execute();
                    }
            ?>
                <tr>
                    <td><strong><?= htmlspecialchars($nomorAju) ?></strong></td>
                    <td><?= htmlspecialchars($nomorDaftar) ?></td>
                    <td><?= htmlspecialchars($tanggalDaftar) ?></td>
                    <td><span class="badge badge-info"><?= htmlspecialchars($kodeProses) ?></span></td>
                    <td><?= htmlspecialchars($waktuStatus) ?></td>
                    <td><?= htmlspecialchars($keterangan) ?></td>
                    <td>BC <?= htmlspecialchars($kodeDokumen) ?></td>
                    <td><?= $saved ? '<span class="badge badge-success">OK</span>' : '<span style="color:#ef4444;">Error</span>' ?></td>
                </tr>
            <?php 
                endforeach;
            else:
            ?>
                <tr><td colspan="8" style="text-align:center; color:#94a3b8; padding:20px;">Tidak ada data status.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Bagian 2: Respon -->
    <h2>Respon Kepabeanan</h2>
    <table>
        <thead>
            <tr>
                <th>Nomor Aju</th>
                <th>Nomor Daftar</th>
                <th>Kode Respon</th>
                <th>Nomor Respon</th>
                <th>Tanggal Respon</th>
                <th>Keterangan</th>
                <th>PDF</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($data['dataRespon']) && is_array($data['dataRespon'])):
                foreach ($data['dataRespon'] as $r):
            ?>
                <tr>
                    <td><strong><?= htmlspecialchars($r['nomorAju'] ?? '') ?></strong></td>
                    <td><?= htmlspecialchars($r['nomorDaftar'] ?? '') ?></td>
                    <td><span class="badge badge-info"><?= htmlspecialchars($r['kodeRespon'] ?? '') ?></span></td>
                    <td><?= htmlspecialchars($r['nomorRespon'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['tanggalRespon'] ?? ($r['waktuRespon'] ?? '')) ?></td>
                    <td><?= htmlspecialchars($r['keterangan'] ?? '') ?></td>
                    <td>
                        <?php if (!empty($r['pdf'])): ?>
                            <a href="pdf.php?aju=<?= urlencode($r['nomorAju']) ?>&pdf=<?= urlencode($r['pdf']) ?>" target="_blank" style="color:#38bdf8; text-decoration:none;">📄 Download PDF</a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
            <?php 
                endforeach;
            else:
            ?>
                <tr><td colspan="7" style="text-align:center; color:#94a3b8; padding:20px;">Tidak ada data respon.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

<?php else: ?>
    <div style="background:#7f1d1d; color:#fca5a5; padding:16px; border-radius:8px; margin-top:20px;">
        <strong>Respon API:</strong> <?= htmlspecialchars($response) ?>
    </div>
<?php endif; ?>

</body>
</html>
<?php
/**
 * CEISA 4.0 - Master Jaminan (jaminan.php)
 * Mengadopsi w_bc_ceisa_jaminan_master.srw & d_bc_ceisa_jaminan_master.srd
 * Mendukung manajemen data jaminan: Tunai, Bank Garansi, Customs Bond, Jamtup, Corporate Guarantee.
 * Fitur: List, Tambah, Edit, Hapus, Export Excel, & Import Otomatis dari Data Ceisa (BC 261/262).
 */
session_start();
require_once __DIR__ . '/koneksi.php';

$isAdmin = !empty($_SESSION['is_admin']) || (isset($_GET['is_admin']) && $_GET['is_admin'] === '1');
$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_GET['cmp_id'] ?? '');
$themeParam = $_GET['theme'] ?? 'light';
if ($themeParam !== 'dark' && $themeParam !== 'light') $themeParam = 'light';

// Pastikan tabel bc_ceisa_jaminan_master ada
if (isset($koneksi) && $koneksi) {
    $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_ceisa_jaminan_master` (
      `id_jaminan` INT NOT NULL AUTO_INCREMENT,
      `cmp_id` VARCHAR(50) DEFAULT '',
      `kode_jenis_jaminan` VARCHAR(5) DEFAULT '',
      `uraian_jenis_jaminan` VARCHAR(100) DEFAULT '',
      `nomor_jaminan` VARCHAR(100) DEFAULT '',
      `penjamin` VARCHAR(200) DEFAULT '',
      `nilai_jaminan` DECIMAL(18,2) DEFAULT '0.00',
      `mata_uang` VARCHAR(5) DEFAULT 'IDR',
      `nomor_bpj` VARCHAR(100) DEFAULT '',
      `tanggal_bpj` DATE DEFAULT NULL,
      `tanggal_jaminan` DATE DEFAULT NULL,
      `tanggal_jatuh_tempo` DATE DEFAULT NULL,
      `status_jaminan` VARCHAR(20) DEFAULT 'AKTIF',
      `keterangan` VARCHAR(255) DEFAULT '',
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id_jaminan`),
      KEY `idx_nomor_jaminan` (`nomor_jaminan`),
      KEY `idx_cmp` (`cmp_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}

$action = $_REQUEST['action'] ?? '';

// 1. AJAX: LIST DATA JAMINAN
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $search = trim($_GET['q'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $cmp_id = trim($_GET['cmp_id'] ?? $sessionCmpId);

    $where = [];
    if (!empty($cmp_id) && !$isAdmin) {
        $where[] = "cmp_id = '" . $koneksi->real_escape_string($cmp_id) . "'";
    }
    if (!empty($status)) {
        $where[] = "status_jaminan = '" . $koneksi->real_escape_string($status) . "'";
    }
    if (!empty($search)) {
        $s = $koneksi->real_escape_string($search);
        $where[] = "(nomor_jaminan LIKE '%$s%' OR penjamin LIKE '%$s%' OR nomor_bpj LIKE '%$s%' OR uraian_jenis_jaminan LIKE '%$s%')";
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    $sql = "SELECT * FROM bc_ceisa_jaminan_master $whereSql ORDER BY id_jaminan DESC";
    $res = $koneksi->query($sql);
    $data = [];
    $totNilai = 0;
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $totNilai += (float)($r['nilai_jaminan'] ?? 0);
            $data[] = $r;
        }
    }

    echo json_encode(['status' => 'OK', 'data' => $data, 'count' => count($data), 'total_nilai' => $totNilai]);
    exit;
}

// 2. AJAX: SIMPAN / UPDATE JAMINAN
if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    $id_jaminan = intval($_POST['id_jaminan'] ?? 0);
    $cmp_id     = trim($_POST['cmp_id'] ?? $sessionCmpId);
    $kode_jenis = trim($_POST['kode_jenis_jaminan'] ?? '');
    $uraian_jenis = trim($_POST['uraian_jenis_jaminan'] ?? '');
    $nomor_jaminan = trim($_POST['nomor_jaminan'] ?? '');
    $penjamin   = trim($_POST['penjamin'] ?? '');
    $nilai      = floatval(str_replace(',', '', $_POST['nilai_jaminan'] ?? '0'));
    $mata_uang  = strtoupper(trim($_POST['mata_uang'] ?? 'IDR'));
    $nomor_bpj  = trim($_POST['nomor_bpj'] ?? '');
    $tgl_bpj    = !empty($_POST['tanggal_bpj']) ? "'" . $koneksi->real_escape_string($_POST['tanggal_bpj']) . "'" : "NULL";
    $tgl_jam    = !empty($_POST['tanggal_jaminan']) ? "'" . $koneksi->real_escape_string($_POST['tanggal_jaminan']) . "'" : "NULL";
    $tgl_tempo  = !empty($_POST['tanggal_jatuh_tempo']) ? "'" . $koneksi->real_escape_string($_POST['tanggal_jatuh_tempo']) . "'" : "NULL";
    $status_jam = trim($_POST['status_jaminan'] ?? 'AKTIF');
    $keterangan = trim($_POST['keterangan'] ?? '');

    if (empty($nomor_jaminan)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor Jaminan wajib diisi.']);
        exit;
    }

    $escCmp   = $koneksi->real_escape_string($cmp_id);
    $escKode  = $koneksi->real_escape_string($kode_jenis);
    $escUraian = $koneksi->real_escape_string($uraian_jenis);
    $escNomor = $koneksi->real_escape_string($nomor_jaminan);
    $escPenj  = $koneksi->real_escape_string($penjamin);
    $escUang  = $koneksi->real_escape_string($mata_uang);
    $escBpj   = $koneksi->real_escape_string($nomor_bpj);
    $escStat  = $koneksi->real_escape_string($status_jam);
    $escKet   = $koneksi->real_escape_string($keterangan);

    if ($id_jaminan > 0) {
        $sql = "UPDATE bc_ceisa_jaminan_master SET
            cmp_id = '$escCmp',
            kode_jenis_jaminan = '$escKode',
            uraian_jenis_jaminan = '$escUraian',
            nomor_jaminan = '$escNomor',
            penjamin = '$escPenj',
            nilai_jaminan = $nilai,
            mata_uang = '$escUang',
            nomor_bpj = '$escBpj',
            tanggal_bpj = $tgl_bpj,
            tanggal_jaminan = $tgl_jam,
            tanggal_jatuh_tempo = $tgl_tempo,
            status_jaminan = '$escStat',
            keterangan = '$escKet'
            WHERE id_jaminan = $id_jaminan";
        $ok = $koneksi->query($sql);
        if ($ok) {
            echo json_encode(['status' => 'OK', 'message' => 'Data jaminan berhasil diperbarui!']);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal update: ' . $koneksi->error]);
        }
    } else {
        $sql = "INSERT INTO bc_ceisa_jaminan_master (
            cmp_id, kode_jenis_jaminan, uraian_jenis_jaminan, nomor_jaminan,
            penjamin, nilai_jaminan, mata_uang, nomor_bpj, tanggal_bpj,
            tanggal_jaminan, tanggal_jatuh_tempo, status_jaminan, keterangan
        ) VALUES (
            '$escCmp', '$escKode', '$escUraian', '$escNomor',
            '$escPenj', $nilai, '$escUang', '$escBpj', $tgl_bpj,
            $tgl_jam, $tgl_tempo, '$escStat', '$escKet'
        )";
        $ok = $koneksi->query($sql);
        if ($ok) {
            echo json_encode(['status' => 'OK', 'message' => 'Data jaminan baru berhasil disimpan!']);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal simpan: ' . $koneksi->error]);
        }
    }
    exit;
}

// 3. AJAX: HAPUS JAMINAN
if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $id = intval($_POST['id_jaminan'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID Jaminan tidak valid.']);
        exit;
    }
    $koneksi->query("DELETE FROM bc_ceisa_jaminan_master WHERE id_jaminan = $id");
    echo json_encode(['status' => 'OK', 'message' => 'Data jaminan berhasil dihapus.']);
    exit;
}

// 4. AJAX: IMPORT OTOMATIS DARI DOKUMEN CEISA 4.0 (BC 261 / 262 / ALL)
if ($action === 'import_ceisa') {
    header('Content-Type: application/json; charset=utf-8');
    $cmp_id = trim($_POST['cmp_id'] ?? $sessionCmpId);
    $escCmp = $koneksi->real_escape_string($cmp_id);

    // Ambil data jaminan dari staging bc_ceisa_jaminan
    $sql = "SELECT j.*, h.cmp_id as doc_cmp 
            FROM bc_ceisa_jaminan j
            LEFT JOIN bc_ceisa_header h ON j.nomor_aju = h.nomor_aju
            WHERE j.nomor_jaminan IS NOT NULL AND j.nomor_jaminan <> ''";
    if (!empty($cmp_id) && !$isAdmin) {
        $sql .= " AND h.cmp_id = '$escCmp'";
    }

    $res = $koneksi->query($sql);
    $imported = 0;
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $nomor = $koneksi->real_escape_string(trim($r['nomor_jaminan'] ?? ''));
            $chk = $koneksi->query("SELECT 1 FROM bc_ceisa_jaminan_master WHERE nomor_jaminan = '$nomor' LIMIT 1");
            if ($chk && $chk->num_rows === 0) {
                $targetCmp = $r['doc_cmp'] ?: $cmp_id;
                $kodeJenis = $koneksi->real_escape_string($r['kode_jenis_jaminan'] ?? '');
                $penjamin  = $koneksi->real_escape_string($r['penjamin'] ?? '-');
                $nilai     = floatval($r['nilai_jaminan'] ?? 0);
                $nomorBpj  = $koneksi->real_escape_string($r['nomor_bpj'] ?? '');
                $tglBpj    = !empty($r['tanggal_bpj']) ? "'" . $koneksi->real_escape_string($r['tanggal_bpj']) . "'" : "NULL";
                $tglJam    = !empty($r['tanggal_jaminan']) ? "'" . $koneksi->real_escape_string($r['tanggal_jaminan']) . "'" : "NULL";
                $tglTempo  = !empty($r['tanggal_jatuh_tempo']) ? "'" . $koneksi->real_escape_string($r['tanggal_jatuh_tempo']) . "'" : "NULL";

                $koneksi->query("INSERT INTO bc_ceisa_jaminan_master (
                    cmp_id, kode_jenis_jaminan, nomor_jaminan, penjamin, nilai_jaminan, nomor_bpj, tanggal_bpj, tanggal_jaminan, tanggal_jatuh_tempo, status_jaminan, keterangan
                ) VALUES (
                    '$targetCmp', '$kodeJenis', '$nomor', '$penjamin', $nilai, '$nomorBpj', $tglBpj, $tglJam, $tglTempo, 'AKTIF', 'Import otomatis dari Dokumen Ceisa ($r[nomor_aju])'
                )");
                $imported++;
            }
        }
    }

    echo json_encode([
        'status' => 'OK',
        'message' => "Import otomatis selesai! Berhasil menambahkan $imported data jaminan baru.",
        'imported_count' => $imported
    ]);
    exit;
}

// 5. AJAX: IMPORT DATA DARI EXCEL (BATCH UPSERT JAMINAN)
if ($action === 'import_excel') {
    header('Content-Type: application/json; charset=utf-8');
    $rowsJson = $_POST['rows'] ?? '[]';
    $rows = json_decode($rowsJson, true);
    if (!is_array($rows) || empty($rows)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Data impor kosong atau format salah.']);
        exit;
    }

    $inserted = 0;
    $updated = 0;
    foreach ($rows as $r) {
        $nomor = trim($r['nomor_jaminan'] ?? ($r['NOMOR_JAMINAN'] ?? ($r['No Jaminan'] ?? ($r['Nomor Jaminan'] ?? ''))));
        if (empty($nomor)) continue;

        $kodeJenis = trim($r['kode_jenis_jaminan'] ?? ($r['KODE_JENIS_JAMINAN'] ?? ($r['Jenis Jaminan'] ?? '1')));
        $uraianJenis = trim($r['uraian_jenis_jaminan'] ?? ($r['URAIAN_JENIS_JAMINAN'] ?? ($r['Uraian Jenis'] ?? '')));
        $penjamin = trim($r['penjamin'] ?? ($r['PENJAMIN'] ?? ($r['Penjamin'] ?? '-')));
        $nilai = floatval(str_replace(',', '', $r['nilai_jaminan'] ?? ($r['NILAI_JAMINAN'] ?? ($r['Nilai'] ?? 0))));
        $uang = strtoupper(trim($r['mata_uang'] ?? ($r['MATA_UANG'] ?? ($r['Valuta'] ?? 'IDR'))));
        $nomorBpj = trim($r['nomor_bpj'] ?? ($r['NOMOR_BPJ'] ?? ($r['No BPJ'] ?? '')));
        $tglBpj = !empty($r['tanggal_bpj']) ? "'" . $koneksi->real_escape_string($r['tanggal_bpj']) . "'" : "NULL";
        $tglJam = !empty($r['tanggal_jaminan']) ? "'" . $koneksi->real_escape_string($r['tanggal_jaminan']) . "'" : "NULL";
        $tglTempo = !empty($r['tanggal_jatuh_tempo']) ? "'" . $koneksi->real_escape_string($r['tanggal_jatuh_tempo']) . "'" : "NULL";
        $status = strtoupper(trim($r['status_jaminan'] ?? ($r['STATUS_JAMINAN'] ?? ($r['Status'] ?? 'AKTIF'))));
        $ket = trim($r['keterangan'] ?? ($r['KETERANGAN'] ?? ($r['Keterangan'] ?? 'Import Excel')));

        $escNomor = $koneksi->real_escape_string($nomor);
        $escKode = $koneksi->real_escape_string($kodeJenis);
        $escUraian = $koneksi->real_escape_string($uraianJenis);
        $escPenj = $koneksi->real_escape_string($penjamin);
        $escUang = $koneksi->real_escape_string($uang);
        $escBpj = $koneksi->real_escape_string($nomorBpj);
        $escStat = $koneksi->real_escape_string($status);
        $escKet = $koneksi->real_escape_string($ket);
        $escCmp = $koneksi->real_escape_string($sessionCmpId);

        $chk = $koneksi->query("SELECT id_jaminan FROM bc_ceisa_jaminan_master WHERE nomor_jaminan = '$escNomor' LIMIT 1");
        if ($chk && $ex = $chk->fetch_assoc()) {
            $id = $ex['id_jaminan'];
            $koneksi->query("UPDATE bc_ceisa_jaminan_master SET
                kode_jenis_jaminan = '$escKode',
                uraian_jenis_jaminan = '$escUraian',
                penjamin = '$escPenj',
                nilai_jaminan = $nilai,
                mata_uang = '$escUang',
                nomor_bpj = '$escBpj',
                tanggal_bpj = $tglBpj,
                tanggal_jaminan = $tglJam,
                tanggal_jatuh_tempo = $tglTempo,
                status_jaminan = '$escStat',
                keterangan = '$escKet'
                WHERE id_jaminan = $id");
            $updated++;
        } else {
            $koneksi->query("INSERT INTO bc_ceisa_jaminan_master (
                cmp_id, kode_jenis_jaminan, uraian_jenis_jaminan, nomor_jaminan, penjamin,
                nilai_jaminan, mata_uang, nomor_bpj, tanggal_bpj, tanggal_jaminan,
                tanggal_jatuh_tempo, status_jaminan, keterangan
            ) VALUES (
                '$escCmp', '$escKode', '$escUraian', '$escNomor', '$escPenj',
                $nilai, '$escUang', '$escBpj', $tglBpj, $tglJam,
                $tglTempo, '$escStat', '$escKet'
            )");
            $inserted++;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'message' => "Proses Impor Jaminan Berhasil: $inserted jaminan baru, $updated jaminan diperbarui.",
        'inserted' => $inserted,
        'updated' => $updated
    ]);
    exit;
}

// 6. REFERENSI JENIS JAMINAN
$refJenisJaminan = [];
if (isset($koneksi) && $koneksi) {
    $qJ = $koneksi->query("SELECT kode, teks FROM bc_ceisa_reff WHERE reff = 'JENIS JAMINAN' AND (status IS NULL OR status <> 'N') ORDER BY kode ASC");
    if ($qJ) {
        while ($rj = $qJ->fetch_assoc()) {
            $refJenisJaminan[] = $rj;
        }
    }
}
if (empty($refJenisJaminan)) {
    $refJenisJaminan = [
        ['kode' => '1', 'teks' => 'JAMINAN TUNAI'],
        ['kode' => '2', 'teks' => 'JAMINAN BANK (BANK GARANSI)'],
        ['kode' => '3', 'teks' => 'CUSTOMS BOND'],
        ['kode' => '4', 'teks' => 'JAMINAN INDONESIA EXIMBANK'],
        ['kode' => '5', 'teks' => 'CORPORATE GUARANTEE'],
        ['kode' => '6', 'teks' => 'JAMINAN TERTULIS (JAMTUP)'],
        ['kode' => '7', 'teks' => 'JAMINAN LAINNYA']
    ];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Jaminan - INHOST CEISA 4.0</title>
    <script>
        (function() {
            var urlParams = new URLSearchParams(window.location.search);
            var theme = urlParams.get('theme') || localStorage.getItem('ceisa_theme') || 'light';
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>
    <link rel="stylesheet" href="/assets/sweetalert2.min.css">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Source+Sans+Pro:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root, html[data-theme="light"], body[data-theme="light"] {
            --bg-base: #f0f2f5;
            --bg-card: #ffffff;
            --border-card: #e8e8e8;
            --border-table: #e8e8e8;
            --table-head-bg: #fafafa;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --text-main: rgba(0, 0, 0, 0.75);
            --text-heading: #262626;
            --text-muted: #8c8c8c;
            --bg-filter: #fafafa;
            --bg-input: #ffffff;
            --border-input: #d9d9d9;
            --kpi-bg: #ffffff;
            --kpi-border: #e8e8e8;
            --row-hover: #f0f7ff;
            --danger: #ff4d4f;
            --success: #52c41a;
            --warning: #faad14;
            color-scheme: light;
        }
        html[data-theme="dark"], body[data-theme="dark"] {
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --border-card: #303030;
            --border-table: #303030;
            --table-head-bg: #1d1d1d;
            --primary: #177ddc;
            --primary-hover: #1890ff;
            --text-main: rgba(255, 255, 255, 0.85);
            --text-heading: #ffffff;
            --text-muted: rgba(255, 255, 255, 0.45);
            --bg-filter: #191919;
            --bg-input: #141414;
            --border-input: #434343;
            --kpi-bg: #1f1f1f;
            --kpi-border: #303030;
            --row-hover: #262638;
            --danger: #a61d24;
            --success: #49aa19;
            --warning: #d89614;
            color-scheme: dark;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: var(--bg-base);
            color: var(--text-main);
            font-family: 'Poppins', 'Source Sans Pro', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 10.5px;
            line-height: 1.45;
            padding: 6px 10px;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        .ant-card {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 3px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            padding: 6px 10px;
        }
        .ant-card-head {
            min-height: 36px;
            padding: 0 0 6px 0;
            border-bottom: 1px solid var(--border-card);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 6px;
        }
        .ant-card-title {
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--text-heading);
        }
        .ant-toolbar {
            display: flex;
            gap: 4px;
            align-items: center;
            flex-wrap: wrap;
        }
        .ant-btn {
            height: 22px;
            padding: 0 7px;
            font-size: 10px;
            border-radius: 2px;
            border: 1px solid var(--border-input);
            background: var(--bg-card);
            color: var(--text-main);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-weight: 400;
            line-height: 1.4;
            transition: all 0.2s cubic-bezier(.645, .045, .355, 1);
            text-decoration: none;
            user-select: none;
            font-family: inherit;
        }
        .ant-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--bg-card);
        }
        .ant-btn-primary {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
            font-weight: 500;
        }
        .ant-btn-primary:hover {
            background: var(--primary-hover);
            color: #fff;
            border-color: var(--primary-hover);
        }
        .ant-btn-success {
            background: var(--success);
            color: #fff;
            border-color: var(--success);
            font-weight: 600;
        }
        .ant-btn-success:hover {
            background: #49ad16;
            color: #fff;
            border-color: #49ad16;
        }
        .ant-btn-warning {
            background: var(--warning);
            color: #fff;
            border-color: var(--warning);
            font-weight: 500;
        }
        .ant-btn-warning:hover {
            background: #d48806;
            color: #fff;
            border-color: #d48806;
        }
        .ant-btn-danger {
            color: var(--danger);
            border-color: var(--danger);
        }
        .ant-btn-danger:hover {
            background: var(--danger);
            color: #fff;
        }
        .ant-btn-sm {
            height: 18px;
            padding: 0 5px;
            font-size: 9px;
            border-radius: 2px;
        }
        .filter-panel {
            padding: 5px 8px;
            background: var(--bg-filter);
            border: 1px solid var(--border-card);
            border-radius: 2px;
            display: flex;
            gap: 6px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 6px;
            font-size: 10px;
        }
        .filter-panel label {
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--text-muted);
        }
        .form-control {
            height: 22px;
            padding: 1px 6px;
            font-size: 9.5px;
            border: 1px solid var(--border-input);
            border-radius: 2px;
            background: var(--bg-input);
            color: var(--text-main);
            font-family: inherit;
            outline: none;
        }
        .form-control:focus {
            border-color: var(--primary);
        }
        select.form-control option {
            background-color: var(--bg-card);
            color: var(--text-main);
        }
        .kpi-container {
            display: flex;
            gap: 6px;
            padding: 0 0 6px 0;
            flex-wrap: wrap;
        }
        .kpi-badge {
            background: var(--kpi-bg);
            border: 1px solid var(--kpi-border);
            border-radius: 2px;
            padding: 2px 8px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 9.5px;
            height: 24px;
        }
        .kpi-title {
            font-size: 9px;
            color: var(--text-muted);
            text-transform: uppercase;
            font-weight: 600;
        }
        .kpi-val {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-heading);
        }
        .table-responsive {
            max-height: calc(100vh - 170px);
            overflow: auto;
            width: 100%;
            border: 1px solid var(--border-card);
            border-radius: 3px;
            background: var(--bg-card);
        }
        table.ant-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 10px;
        }
        table.ant-table th {
            background: var(--table-head-bg);
            color: var(--text-heading);
            font-weight: 600;
            padding: 4px 6px;
            border-bottom: 1px solid var(--border-table);
            font-size: 9.5px;
            white-space: nowrap;
        }
        table.ant-table td {
            padding: 3px 6px;
            border-bottom: 1px solid var(--border-table);
            font-size: 10px;
            white-space: nowrap;
            vertical-align: middle;
            color: var(--text-main);
        }
        table.ant-table tbody tr:hover td {
            background: var(--row-hover) !important;
        }
        .tag-status {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 2px;
            font-size: 9px;
            font-weight: 600;
        }
        .tag-aktif { background: rgba(82, 196, 26, 0.12); color: #52c41a; }
        .tag-tempo { background: rgba(255, 77, 79, 0.12); color: #ff4d4f; }
        .tag-cair { background: rgba(24, 144, 255, 0.12); color: #1890ff; }
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(2px);
        }
        .modal-card {
            background: var(--bg-card);
            width: 95%;
            max-width: 620px;
            border: 1px solid var(--border-card);
            border-radius: 3px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            max-height: 90vh;
        }
        .modal-header {
            padding: 6px 12px;
            border-bottom: 1px solid var(--border-card);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text-heading);
            background: var(--bg-card);
        }
        .modal-body {
            padding: 8px 12px;
            overflow-y: auto;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            font-size: 10px;
            color: var(--text-main);
        }
        .modal-body .full-width { grid-column: 1 / -1; }
        .modal-footer {
            padding: 6px 12px;
            border-top: 1px solid var(--border-card);
            display: flex;
            justify-content: flex-end;
            gap: 6px;
            background: var(--bg-card);
        }
        .form-group { display: flex; flex-direction: column; gap: 2px; }
        .form-group label { font-size: 9px; font-weight: 600; text-transform: uppercase; color: var(--text-muted); }
    </style>
</head>
<body>
    <div class="ant-card">
        <div class="ant-card-head">
            <div class="ant-card-title">
                <span>🛡️</span> Master Jaminan Pabean (CEISA 4.0)
            </div>
            <div class="ant-toolbar">
                <button type="button" class="ant-btn ant-btn-primary" onclick="openAddModal()">
                    <span>➕</span> Tambah Jaminan
                </button>
                <button type="button" class="ant-btn ant-btn-success" onclick="openImportModal()">
                    <span>📗</span> Import Excel
                </button>
                <button type="button" class="ant-btn ant-btn-warning" onclick="importFromCeisa()" title="Import Otomatis dari Dokumen BC 262/261">
                    <span>⚡</span> Import dari Dokumen CEISA
                </button>
                <button type="button" class="ant-btn" onclick="exportExcel()">
                    <span>📥</span> Export Excel
                </button>
                <button type="button" class="ant-btn" onclick="loadData()">
                    <span>🔄</span> Muat Ulang
                </button>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-panel">
            <div style="display:flex; align-items:center; gap:6px;">
                <label>Cari:</label>
                <input type="text" id="filterSearch" class="form-control" style="width:200px;" placeholder="No Jaminan, Penjamin, BPJ..." onkeyup="if(event.key==='Enter') loadData()">
            </div>
            <div style="display:flex; align-items:center; gap:6px;">
                <label>Status:</label>
                <select id="filterStatus" class="form-control" onchange="loadData()">
                    <option value="">Semua Status</option>
                    <option value="AKTIF">AKTIF</option>
                    <option value="JATUH TEMPO">JATUH TEMPO</option>
                    <option value="DICAIRKAN">DICAIRKAN</option>
                    <option value="DIKEMBALIKAN">DIKEMBALIKAN</option>
                </select>
            </div>
            <button type="button" class="ant-btn ant-btn-primary" onclick="loadData()">
                <span>🔍 Filter</span>
            </button>
        </div>

        <!-- KPI Cards -->
        <div class="kpi-container">
            <div class="kpi-badge">
                <span class="kpi-title">Total Jaminan</span>
                <span class="kpi-val" id="kpiTotal">0</span>
            </div>
            <div class="kpi-badge">
                <span class="kpi-title">Total Nilai (IDR)</span>
                <span class="kpi-val" id="kpiNilai" style="color:var(--primary);">Rp 0</span>
            </div>
            <div class="kpi-badge">
                <span class="kpi-title">Status Aktif</span>
                <span class="kpi-val" id="kpiAktif" style="color:var(--success);">0</span>
            </div>
            <div class="kpi-badge">
                <span class="kpi-title">Jatuh Tempo</span>
                <span class="kpi-val" id="kpiTempo" style="color:var(--danger);">0</span>
            </div>
        </div>

        <!-- Grid Table -->
        <div class="table-responsive">
            <table class="ant-table">
                <thead>
                    <tr>
                        <th style="width:40px; text-align:center;">#</th>
                        <th>Jenis Jaminan</th>
                        <th>Nomor Jaminan</th>
                        <th>Penjamin</th>
                        <th>Nilai Jaminan</th>
                        <th>Mata Uang</th>
                        <th>Nomor BPJ</th>
                        <th>Tgl BPJ</th>
                        <th>Tgl Jaminan</th>
                        <th>Jatuh Tempo</th>
                        <th>Status</th>
                        <th>Keterangan</th>
                        <th style="text-align:center; width:110px;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <tr><td colspan="13" style="text-align:center; padding:30px; color:var(--text-muted);">Memuat data master jaminan...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL FORM JAMINAN -->
    <div class="modal-overlay" id="modalJaminan">
        <div class="modal-card">
            <div class="modal-header">
                <span id="modalTitle">➕ Tambah Master Jaminan</span>
                <button type="button" class="ant-btn" style="padding:2px 8px;" onclick="closeModal()">✕</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="jamId" value="0">
                <div class="form-group">
                    <label>Jenis Jaminan *</label>
                    <select id="jamJenis" class="form-control" required>
                        <option value="">-- Pilih Jenis Jaminan --</option>
                        <?php foreach ($refJenisJaminan as $rj): ?>
                            <option value="<?= htmlspecialchars($rj['kode']) ?>" data-uraian="<?= htmlspecialchars($rj['teks']) ?>"><?= htmlspecialchars($rj['kode'] . ' - ' . $rj['teks']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Nomor Jaminan *</label>
                    <input type="text" id="jamNomor" class="form-control" placeholder="Contoh: 123/BG/2026" required>
                </div>
                <div class="form-group full-width">
                    <label>Penjamin (Bank / Lembaga Asuransi) *</label>
                    <input type="text" id="jamPenjamin" class="form-control" placeholder="Nama Bank atau Lembaga Penjamin" required>
                </div>
                <div class="form-group">
                    <label>Nilai Jaminan *</label>
                    <input type="number" step="0.01" id="jamNilai" class="form-control" placeholder="0.00" required>
                </div>
                <div class="form-group">
                    <label>Mata Uang</label>
                    <select id="jamUang" class="form-control">
                        <option value="IDR">IDR (Rupiah)</option>
                        <option value="USD">USD (US Dollar)</option>
                        <option value="EUR">EUR (Euro)</option>
                        <option value="JPY">JPY (Yen)</option>
                        <option value="SGD">SGD (Singapore Dollar)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Nomor BPJ</label>
                    <input type="text" id="jamBpj" class="form-control" placeholder="Nomor Bukti Penerimaan Jaminan">
                </div>
                <div class="form-group">
                    <label>Tanggal BPJ</label>
                    <input type="date" id="jamTglBpj" class="form-control">
                </div>
                <div class="form-group">
                    <label>Tanggal Jaminan</label>
                    <input type="date" id="jamTglJaminan" class="form-control">
                </div>
                <div class="form-group">
                    <label>Tanggal Jatuh Tempo</label>
                    <input type="date" id="jamTglTempo" class="form-control">
                </div>
                <div class="form-group">
                    <label>Status Jaminan</label>
                    <select id="jamStatus" class="form-control">
                        <option value="AKTIF">AKTIF</option>
                        <option value="JATUH TEMPO">JATUH TEMPO</option>
                        <option value="DICAIRKAN">DICAIRKAN</option>
                        <option value="DIKEMBALIKAN">DIKEMBALIKAN</option>
                    </select>
                </div>
                <div class="form-group full-width">
                    <label>Keterangan</label>
                    <textarea id="jamKet" class="form-control" rows="2" placeholder="Catatan tambahan jaminan..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="ant-btn" onclick="closeModal()">Batal</button>
                <button type="button" class="ant-btn ant-btn-primary" onclick="submitJaminan()">💾 Simpan Data</button>
            </div>
        </div>
    </div>

    <!-- MODAL IMPORT EXCEL -->
    <div class="modal-overlay" id="modalImport">
        <div class="modal-card" style="max-width: 750px;">
            <div class="modal-header">
                <span>📗 Import Master Jaminan dari Excel</span>
                <button type="button" class="ant-btn" style="padding:2px 8px;" onclick="closeImportModal()">✕</button>
            </div>
            <div class="modal-body" style="display:flex; flex-direction:column; gap:12px;">
                <div style="background:rgba(24,144,255,0.08); border:1px solid #91d5ff; border-radius:6px; padding:10px 14px; font-size:12px; color:var(--text-main);">
                    <strong>Petunjuk Import:</strong>
                    <ul style="margin:4px 0 0 16px; padding:0;">
                        <li>Format kolom: <code>nomor_jaminan</code>, <code>kode_jenis_jaminan</code>, <code>penjamin</code>, <code>nilai_jaminan</code>, <code>mata_uang</code>, <code>nomor_bpj</code>, <code>tanggal_bpj</code>, <code>tanggal_jaminan</code>, <code>tanggal_jatuh_tempo</code>, <code>status_jaminan</code>, <code>keterangan</code></li>
                        <li>Format tanggal: YYYY-MM-DD (contoh: 2026-10-16).</li>
                        <li>Data dengan Nomor Jaminan yang sudah ada akan otomatis diperbarui (upsert).</li>
                    </ul>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <button type="button" class="ant-btn" onclick="downloadTemplate()">
                        <span>📄 Download Format Template (.xlsx)</span>
                    </button>
                    <input type="file" id="excelFileInput" accept=".xlsx, .xls, .csv" style="display:none;" onchange="handleExcelUpload(event)">
                    <button type="button" class="ant-btn ant-btn-primary" onclick="document.getElementById('excelFileInput').click()">
                        <span>📁 Pilih File Excel...</span>
                    </button>
                </div>

                <div id="dropZone" style="border: 2px dashed var(--border-color); border-radius: 8px; padding: 25px; text-align: center; cursor: pointer; transition: all 0.2s; background: rgba(0,0,0,0.02);" onclick="document.getElementById('excelFileInput').click()">
                    <div style="font-size: 28px; margin-bottom: 6px;">📥</div>
                    <div style="font-weight: 600; font-size: 13px;">Tarik dan lepaskan file Excel di sini atau Klik untuk memilih file</div>
                    <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">Mendukung format .xlsx, .xls, atau .csv</div>
                </div>

                <!-- Preview Table -->
                <div id="importPreviewContainer" style="display:none; max-height: 250px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 6px;">
                    <div style="padding: 6px 12px; background: var(--table-header); font-weight: 600; font-size: 12px; border-bottom: 1px solid var(--border-color); display:flex; justify-content:space-between;">
                        <span id="previewCountText">Preview Data: 0 baris</span>
                        <span style="color:var(--text-muted); font-size:11px;">Maksimal 10 baris pertama ditampilkan</span>
                    </div>
                    <table class="ant-table" style="font-size: 11px;">
                        <thead>
                            <tr>
                                <th>No Jaminan</th>
                                <th>Jenis</th>
                                <th>Penjamin</th>
                                <th>Nilai</th>
                                <th>Val</th>
                                <th>BPJ</th>
                                <th>Jatuh Tempo</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="previewBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="ant-btn" onclick="closeImportModal()">Batal</button>
                <button type="button" class="ant-btn ant-btn-success" id="btnExecuteImport" style="display:none;" onclick="executeImport()">
                    <span>🚀 Mulai Impor Data</span>
                </button>
            </div>
        </div>
    </div>

    <script>
        let rawJaminanData = [];

        async function loadData() {
            const q = document.getElementById('filterSearch').value.trim();
            const status = document.getElementById('filterStatus').value;
            const tbody = document.getElementById('tableBody');
            tbody.innerHTML = '<tr><td colspan="13" style="text-align:center; padding:24px; color:var(--text-muted);"><span class="spinner"></span> Memuat data...</td></tr>';

            try {
                const res = await fetch(`jaminan.php?action=list&q=${encodeURIComponent(q)}&status=${encodeURIComponent(status)}`);
                const json = await res.json();
                if (json.status === 'OK') {
                    rawJaminanData = json.data || [];
                    renderTable(rawJaminanData, json.total_nilai || 0);
                } else {
                    tbody.innerHTML = `<tr><td colspan="13" style="text-align:center; color:var(--danger);">${json.message}</td></tr>`;
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="13" style="text-align:center; color:var(--danger);">Gagal memuat: ${err.message}</td></tr>`;
            }
        }

        function renderTable(data, totalNilai) {
            const tbody = document.getElementById('tableBody');
            tbody.innerHTML = '';

            let cntAktif = 0;
            let cntTempo = 0;
            const today = new Date().toISOString().slice(0, 10);

            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="13" style="text-align:center; padding:24px; color:var(--text-muted);">Tidak ada data jaminan yang ditemukan.</td></tr>';
                document.getElementById('kpiTotal').innerText = '0';
                document.getElementById('kpiNilai').innerText = 'Rp 0';
                document.getElementById('kpiAktif').innerText = '0';
                document.getElementById('kpiTempo').innerText = '0';
                return;
            }

            data.forEach((r, idx) => {
                const isTempo = r.tanggal_jatuh_tempo && r.tanggal_jatuh_tempo < today;
                if (r.status_jaminan === 'AKTIF') cntAktif++;
                if (isTempo || r.status_jaminan === 'JATUH TEMPO') cntTempo++;

                let statusBadge = `<span class="tag-status tag-aktif">${escapeHtml(r.status_jaminan || 'AKTIF')}</span>`;
                if (isTempo || r.status_jaminan === 'JATUH TEMPO') {
                    statusBadge = `<span class="tag-status tag-tempo">JATUH TEMPO</span>`;
                } else if (r.status_jaminan === 'DICAIRKAN') {
                    statusBadge = `<span class="tag-status tag-cair">DICAIRKAN</span>`;
                }

                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td style="text-align:center;">${idx + 1}</td>
                    <td><strong>${escapeHtml(r.kode_jenis_jaminan || '-')}</strong> <small style="color:var(--text-muted);">${escapeHtml(r.uraian_jenis_jaminan || '')}</small></td>
                    <td style="font-weight:600; color:var(--primary);">${escapeHtml(r.nomor_jaminan || '-')}</td>
                    <td>${escapeHtml(r.penjamin || '-')}</td>
                    <td style="text-align:right; font-weight:700;">${parseFloat(r.nilai_jaminan || 0).toLocaleString('id-ID', { minimumFractionDigits: 2 })}</td>
                    <td>${escapeHtml(r.mata_uang || 'IDR')}</td>
                    <td>${escapeHtml(r.nomor_bpj || '-')}</td>
                    <td>${escapeHtml(r.tanggal_bpj || '-')}</td>
                    <td>${escapeHtml(r.tanggal_jaminan || '-')}</td>
                    <td style="color:${isTempo ? 'var(--danger)' : 'inherit'}; font-weight:${isTempo ? '700' : '400'};">${escapeHtml(r.tanggal_jatuh_tempo || '-')}</td>
                    <td>${statusBadge}</td>
                    <td><small>${escapeHtml(r.keterangan || '-')}</small></td>
                    <td style="text-align:center;">
                        <button type="button" class="ant-btn" style="padding:2px 6px; font-size:11px;" onclick="openEditModal(${idx})" title="Edit Jaminan">✏️</button>
                        <button type="button" class="ant-btn ant-btn-danger" style="padding:2px 6px; font-size:11px;" onclick="deleteJaminan(${r.id_jaminan}, '${escapeHtml(r.nomor_jaminan)}')" title="Hapus Jaminan">🗑️</button>
                    </td>
                `;
                tbody.appendChild(tr);
            });

            document.getElementById('kpiTotal').innerText = data.length.toLocaleString('id-ID');
            document.getElementById('kpiNilai').innerText = 'Rp ' + totalNilai.toLocaleString('id-ID', { minimumFractionDigits: 2 });
            document.getElementById('kpiAktif').innerText = cntAktif.toLocaleString('id-ID');
            document.getElementById('kpiTempo').innerText = cntTempo.toLocaleString('id-ID');
        }

        function openAddModal() {
            document.getElementById('modalTitle').innerText = '➕ Tambah Master Jaminan';
            document.getElementById('jamId').value = '0';
            document.getElementById('jamJenis').value = '';
            document.getElementById('jamNomor').value = '';
            document.getElementById('jamPenjamin').value = '';
            document.getElementById('jamNilai').value = '';
            document.getElementById('jamUang').value = 'IDR';
            document.getElementById('jamBpj').value = '';
            document.getElementById('jamTglBpj').value = '';
            document.getElementById('jamTglJaminan').value = new Date().toISOString().slice(0, 10);
            document.getElementById('jamTglTempo').value = '';
            document.getElementById('jamStatus').value = 'AKTIF';
            document.getElementById('jamKet').value = '';
            document.getElementById('modalJaminan').style.display = 'flex';
        }

        function openEditModal(idx) {
            const r = rawJaminanData[idx];
            if (!r) return;
            document.getElementById('modalTitle').innerText = '✏️ Edit Master Jaminan';
            document.getElementById('jamId').value = r.id_jaminan;
            document.getElementById('jamJenis').value = r.kode_jenis_jaminan || '';
            document.getElementById('jamNomor').value = r.nomor_jaminan || '';
            document.getElementById('jamPenjamin').value = r.penjamin || '';
            document.getElementById('jamNilai').value = r.nilai_jaminan || '';
            document.getElementById('jamUang').value = r.mata_uang || 'IDR';
            document.getElementById('jamBpj').value = r.nomor_bpj || '';
            document.getElementById('jamTglBpj').value = r.tanggal_bpj || '';
            document.getElementById('jamTglJaminan').value = r.tanggal_jaminan || '';
            document.getElementById('jamTglTempo').value = r.tanggal_jatuh_tempo || '';
            document.getElementById('jamStatus').value = r.status_jaminan || 'AKTIF';
            document.getElementById('jamKet').value = r.keterangan || '';
            document.getElementById('modalJaminan').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('modalJaminan').style.display = 'none';
        }

        async function submitJaminan() {
            const id = document.getElementById('jamId').value;
            const selJenis = document.getElementById('jamJenis');
            const kodeJenis = selJenis.value;
            const uraianJenis = selJenis.options[selJenis.selectedIndex]?.dataset?.uraian || '';
            const nomor = document.getElementById('jamNomor').value.trim();
            const penjamin = document.getElementById('jamPenjamin').value.trim();
            const nilai = document.getElementById('jamNilai').value;
            const uang = document.getElementById('jamUang').value;
            const bpj = document.getElementById('jamBpj').value.trim();
            const tglBpj = document.getElementById('jamTglBpj').value;
            const tglJam = document.getElementById('jamTglJaminan').value;
            const tglTempo = document.getElementById('jamTglTempo').value;
            const status = document.getElementById('jamStatus').value;
            const ket = document.getElementById('jamKet').value.trim();

            if (!nomor) {
                Swal.fire('Validasi', 'Nomor Jaminan wajib diisi!', 'warning');
                return;
            }

            const fd = new FormData();
            fd.append('action', 'save');
            fd.append('id_jaminan', id);
            fd.append('kode_jenis_jaminan', kodeJenis);
            fd.append('uraian_jenis_jaminan', uraianJenis);
            fd.append('nomor_jaminan', nomor);
            fd.append('penjamin', penjamin);
            fd.append('nilai_jaminan', nilai);
            fd.append('mata_uang', uang);
            fd.append('nomor_bpj', bpj);
            fd.append('tanggal_bpj', tglBpj);
            fd.append('tanggal_jaminan', tglJam);
            fd.append('tanggal_jatuh_tempo', tglTempo);
            fd.append('status_jaminan', status);
            fd.append('keterangan', ket);

            try {
                const res = await fetch('jaminan.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire('Berhasil', json.message, 'success');
                    closeModal();
                    loadData();
                } else {
                    Swal.fire('Error', json.message, 'error');
                }
            } catch (err) {
                Swal.fire('Error Jaringan', err.message, 'error');
            }
        }

        async function deleteJaminan(id, nomor) {
            const ok = await Swal.fire({
                title: 'Konfirmasi Hapus',
                html: `Yakin ingin menghapus data jaminan nomor: <strong>${escapeHtml(nomor)}</strong>?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ff4d4f',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: 'Ya, Hapus',
                cancelButtonText: 'Batal'
            });
            if (!ok.isConfirmed) return;

            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('id_jaminan', id);

            try {
                const res = await fetch('jaminan.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire('Berhasil', json.message, 'success');
                    loadData();
                } else {
                    Swal.fire('Error', json.message, 'error');
                }
            } catch (err) {
                Swal.fire('Error Jaringan', err.message, 'error');
            }
        }

        async function importFromCeisa() {
            const ok = await Swal.fire({
                title: 'Import Otomatis Jaminan',
                html: 'Tarik seluruh data jaminan yang tersimpan pada dokumen pabean (BC 2.6.1, BC 2.6.2, dll.) ke Master Jaminan?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#1890ff',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: 'Ya, Mulai Import',
                cancelButtonText: 'Batal'
            });
            if (!ok.isConfirmed) return;

            Swal.fire({
                title: 'Sedang Mengimpor...',
                text: 'Memeriksa dan menyelaraskan data jaminan...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            const fd = new FormData();
            fd.append('action', 'import_ceisa');

            try {
                const res = await fetch('jaminan.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire('Selesai', json.message, 'success');
                    loadData();
                } else {
                    Swal.fire('Error', json.message, 'error');
                }
            } catch (err) {
                Swal.fire('Error Jaringan', err.message, 'error');
            }
        }

        let importedRowsData = [];

        function openImportModal() {
            importedRowsData = [];
            document.getElementById('excelFileInput').value = '';
            document.getElementById('importPreviewContainer').style.display = 'none';
            document.getElementById('btnExecuteImport').style.display = 'none';
            document.getElementById('modalImport').style.display = 'flex';
        }

        function closeImportModal() {
            document.getElementById('modalImport').style.display = 'none';
        }

        function downloadTemplate() {
            const template = [
                {
                    nomor_jaminan: "JAM-2026-001",
                    kode_jenis_jaminan: "2",
                    uraian_jenis_jaminan: "JAMINAN BANK (BANK GARANSI)",
                    penjamin: "BANK MANDIRI",
                    nilai_jaminan: 150000000,
                    mata_uang: "IDR",
                    nomor_bpj: "BPJ-00123/2026",
                    tanggal_bpj: "2026-01-15",
                    tanggal_jaminan: "2026-01-15",
                    tanggal_jatuh_tempo: "2026-12-31",
                    status_jaminan: "AKTIF",
                    keterangan: "Jaminan Fasilitas Kawasan Berikat"
                }
            ];
            const ws = XLSX.utils.json_to_sheet(template);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Template_Jaminan");
            XLSX.writeFile(wb, "Template_Master_Jaminan.xlsx");
        }

        function handleExcelUpload(e) {
            const files = e.target.files;
            if (!files || files.length === 0) return;
            const file = files[0];
            const reader = new FileReader();
            reader.onload = function(evt) {
                try {
                    const data = new Uint8Array(evt.target.result);
                    const workbook = XLSX.read(data, { type: 'array' });
                    const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
                    const jsonData = XLSX.utils.sheet_to_json(firstSheet, { defval: "" });

                    if (!jsonData || jsonData.length === 0) {
                        Swal.fire('Format Salah', 'File Excel kosong atau sheet pertama tidak berisi data.', 'warning');
                        return;
                    }

                    importedRowsData = jsonData;
                    renderImportPreview(jsonData);
                } catch (err) {
                    Swal.fire('Error Baca File', 'Gagal memproses file Excel: ' + err.message, 'error');
                }
            };
            reader.readAsArrayBuffer(file);
        }

        function renderImportPreview(rows) {
            const tbody = document.getElementById('previewBody');
            tbody.innerHTML = '';
            document.getElementById('previewCountText').innerText = `Preview Data: ${rows.length} baris ditemukan`;

            const max = Math.min(rows.length, 10);
            for (let i = 0; i < max; i++) {
                const r = rows[i];
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td><strong>${escapeHtml(r.nomor_jaminan || r.NOMOR_JAMINAN || r['No Jaminan'] || '')}</strong></td>
                    <td>${escapeHtml(r.kode_jenis_jaminan || r.KODE_JENIS_JAMINAN || '')}</td>
                    <td>${escapeHtml(r.penjamin || r.PENJAMIN || '')}</td>
                    <td>${Number(r.nilai_jaminan || r.NILAI_JAMINAN || 0).toLocaleString('id-ID')}</td>
                    <td>${escapeHtml(r.mata_uang || r.MATA_UANG || 'IDR')}</td>
                    <td>${escapeHtml(r.nomor_bpj || r.NOMOR_BPJ || '')}</td>
                    <td>${escapeHtml(r.tanggal_jatuh_tempo || r.TANGGAL_JATUH_TEMPO || '')}</td>
                    <td>${escapeHtml(r.status_jaminan || r.STATUS_JAMINAN || 'AKTIF')}</td>
                `;
                tbody.appendChild(tr);
            }

            document.getElementById('importPreviewContainer').style.display = 'block';
            document.getElementById('btnExecuteImport').style.display = 'inline-flex';
        }

        async function executeImport() {
            if (!importedRowsData || importedRowsData.length === 0) {
                Swal.fire('Error', 'Tidak ada data untuk diimpor.', 'warning');
                return;
            }

            const ok = await Swal.fire({
                title: 'Konfirmasi Impor',
                html: `Import <strong>${importedRowsData.length}</strong> data jaminan ke sistem?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#52c41a',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: 'Ya, Impor Sekarang',
                cancelButtonText: 'Batal'
            });
            if (!ok.isConfirmed) return;

            Swal.fire({
                title: 'Sedang Mengimpor Data...',
                text: 'Harap tunggu, proses penyimpanan sedang berjalan...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            const fd = new FormData();
            fd.append('action', 'import_excel');
            fd.append('rows', JSON.stringify(importedRowsData));

            try {
                const res = await fetch('jaminan.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire('Berhasil', json.message, 'success');
                    closeImportModal();
                    loadData();
                } else {
                    Swal.fire('Gagal Impor', json.message, 'error');
                }
            } catch (err) {
                Swal.fire('Error Jaringan', err.message, 'error');
            }
        }

        function exportExcel() {
            if (rawJaminanData.length === 0) {
                Swal.fire('Informasi', 'Tidak ada data untuk diexport.', 'info');
                return;
            }
            const dataToExport = rawJaminanData.map(r => ({
                'ID Jaminan': r.id_jaminan,
                'Kode Jenis': r.kode_jenis_jaminan,
                'Jenis Jaminan': r.uraian_jenis_jaminan,
                'Nomor Jaminan': r.nomor_jaminan,
                'Penjamin': r.penjamin,
                'Nilai Jaminan': Number(r.nilai_jaminan || 0),
                'Mata Uang': r.mata_uang,
                'Nomor BPJ': r.nomor_bpj,
                'Tgl BPJ': r.tanggal_bpj,
                'Tgl Jaminan': r.tanggal_jaminan,
                'Jatuh Tempo': r.tanggal_jatuh_tempo,
                'Status': r.status_jaminan,
                'Keterangan': r.keterangan
            }));
            const ws = XLSX.utils.json_to_sheet(dataToExport);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Master_Jaminan");
            XLSX.writeFile(wb, `Master_Jaminan_${new Date().toISOString().slice(0,10)}.xlsx`);
        }

        function escapeHtml(text) {
            if (!text) return '';
            return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        window.onload = loadData;
    </script>
</body>
</html>

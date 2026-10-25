<?php
/**
 * CEISA 4.0 - Form Editor & Entry Dokumen Pabean (editor.php)
 * Form Interface Dokumen Pabean berbasis Web murni (100% fungsional).
 * Mengedit tabel riil: Header, Barang, Entitas, Dokumen, Bahan Baku, Barang Tarif,
 * Bahan Baku Tarif, Kemasan, Pengangkut, Kontainer, Pungutan, Jaminan, Bank Devisa, Kesiapan Barang.
 * Mendukung Tema Dark & Light, Saring Kolom Dinamis (varheader),
 * Saring Tab Dokumen BC, dan Referensi Dropdown Terintegrasi (bc_ceisa_reff).
 */
session_start();
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/CeisaJsonBuilder.php';

// Jika editor.php dibuka langsung (bukan dalam iframe portal), alihkan ke index.php agar sidebar menu tetap tampil
if (empty($_REQUEST['action']) && empty($_REQUEST['embedded'])) {
    $targetAju = trim($_GET['nomor_aju'] ?? '');
    $targetCmp = trim($_GET['cmp_id'] ?? '');
    $ajuParam  = !empty($targetAju) ? '&nomor_aju=' . urlencode($targetAju) : '';
    $cmpParam  = !empty($targetCmp) ? '&cmp_id=' . urlencode($targetCmp) : '';
    header("Location: /index.php?tab=editor{$ajuParam}{$cmpParam}");
    exit;
}

// Cek status role Admin vs User
$isAdmin = (!empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true)
        || (!empty($_SESSION['user_id']) && strtolower($_SESSION['user_id']) === 'admin')
        || (!empty($_REQUEST['is_admin']) && $_REQUEST['is_admin'] == '1');

// Ambil filter cmp_id jika ada dari request atau session
$filterCmp = trim($_REQUEST['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ''));
$selectedAjuParam = trim($_GET['nomor_aju'] ?? '');
if (empty($filterCmp) && !empty($selectedAjuParam) && isset($koneksi) && $koneksi) {
    $qAjuCmp = $koneksi->query("SELECT cmp_id FROM bc_ceisa_header WHERE nomor_aju = '" . $koneksi->real_escape_string($selectedAjuParam) . "' LIMIT 1");
    if ($qAjuCmp && $rAjuCmp = $qAjuCmp->fetch_assoc()) {
        $filterCmp = trim($rAjuCmp['cmp_id'] ?? '');
    }
}
$companyErpName = '-';
$companyRoles = 'tpb';
$companyAllowedDocsStr = null;
if (!empty($filterCmp) && isset($koneksi) && $koneksi) {
    $qErp = $koneksi->query("SELECT ERP_Name, IFNULL(company_roles, 'tpb') AS company_roles, allowed_docs FROM bc_ceisa_company WHERE cmp_id = '" . $koneksi->real_escape_string($filterCmp) . "' LIMIT 1");
    if ($qErp && $rErp = $qErp->fetch_assoc()) {
        $companyErpName = trim($rErp['ERP_Name'] ?? '-');
        $companyRoles = trim($rErp['company_roles'] ?? 'tpb');
        $companyAllowedDocsStr = $rErp['allowed_docs'] ?? null;
    }
}
$isErpConfigured = (!empty($companyErpName) && $companyErpName !== '-' && strtoupper($companyErpName) !== 'NONE');

// Resolusi Dokumen BC yang Diizinkan untuk Perusahaan ini (mengacu allowed_docs & preset role)
$companyAllowedDocs = ceisa_resolve_allowed_docs($companyRoles, $companyAllowedDocsStr);
$effectiveAllowedDocs = ceisa_get_effective_allowed_docs($companyAllowedDocs);

// Katalog Dokumen Pabean Berdasarkan Role Perusahaan (Grup Pemasukan dulu baru Pengeluaran, urut kode numerik)
$allBcCatalog = [
    // GRUP PEMASUKAN (INBOUND)
    '16'  => ['label' => 'BC 1.6 — Pemasukan ke PLB',                              'role' => 'plb',   'type' => 'inbound'],
    '20'  => ['label' => 'BC 2.0 — Impor Non-Fasilitas (PIB)',                    'role' => 'pib',   'type' => 'inbound'],
    '23'  => ['label' => 'BC 2.3 — Impor ke Kawasan Berikat (TPB)',               'role' => 'tpb',   'type' => 'inbound'],
    '262' => ['label' => 'BC 2.6.2 — Pemasukan Subcon Kembali',                   'role' => 'tpb',   'type' => 'inbound'],
    '27IN'=> ['label' => 'BC 2.7 IN — Pemasukan dari TPB Lain',                   'role' => 'tpb',   'type' => 'inbound'],
    '28'  => ['label' => 'BC 2.8 — Impor dari PLB',                               'role' => 'plb',   'type' => 'inbound'],
    '40'  => ['label' => 'BC 4.0 — Pemasukan dari Lokal ke TPB',                  'role' => 'tpb',   'type' => 'inbound'],
    '611' => ['label' => 'PPKEK 6.1.1 — Pemasukan ke KEK dari Luar Negeri',        'role' => 'ppkek', 'type' => 'inbound'],
    '613' => ['label' => 'PPKEK 6.1.3 — Pemasukan ke KEK dari Lokal',              'role' => 'ppkek', 'type' => 'inbound'],

    // GRUP PENGELUARAN (OUTBOUND)
    '25'  => ['label' => 'BC 2.5 — Pengeluaran ke Lokal (Asal Impor / BC 2.3)',         'role' => 'tpb',   'type' => 'outbound'],
    '261' => ['label' => 'BC 2.6.1 — Pengeluaran Subcon',                         'role' => 'tpb',   'type' => 'outbound'],
    '27'  => ['label' => 'BC 2.7 — Pengeluaran ke TPB Lain',                       'role' => 'tpb',   'type' => 'outbound'],
    '30'  => ['label' => 'BC 3.0 — Ekspor Barang (PEB)',                           'role' => 'peb',   'type' => 'outbound'],
    '33'  => ['label' => 'BC 3.3 — Ekspor dari PLB',                              'role' => 'plb',   'type' => 'outbound'],
    '331' => ['label' => 'BC 3.3.1 — P3BET PLB',                                 'role' => 'plb',   'type' => 'outbound'],
    '41'  => ['label' => 'BC 4.1 — Pengeluaran ke Lokal (Asal Lokal / BC 4.0)',         'role' => 'tpb',   'type' => 'outbound'],
    '631' => ['label' => 'PPKEK 6.3.1 — Pengeluaran dari KEK ke Luar Negeri',      'role' => 'ppkek', 'type' => 'outbound'],
];

$allowedBcInbound = [];
$allowedBcOutbound = [];
foreach ($allBcCatalog as $code => $info) {
    $codeStr = (string)$code;
    $isPermitted = !empty($_SESSION['is_super_admin']) || 
                   in_array('*', $effectiveAllowedDocs) || 
                   in_array($codeStr, $effectiveAllowedDocs) ||
                   ($codeStr === '27IN' && in_array('27', $effectiveAllowedDocs));
    if ($isPermitted) {
        if (($info['type'] ?? '') === 'inbound') {
            $allowedBcInbound[$code] = $info['label'];
        } else {
            $allowedBcOutbound[$code] = $info['label'];
        }
    }
}
if (empty($allowedBcInbound) && empty($allowedBcOutbound)) {
    foreach ($allBcCatalog as $code => $info) {
        $codeStr = (string)$code;
        $isPermitted = !empty($_SESSION['is_super_admin']) || 
                       in_array('*', $companyAllowedDocs) || 
                       in_array($codeStr, $companyAllowedDocs) ||
                       ($codeStr === '27IN' && in_array('27', $companyAllowedDocs));
        if ($isPermitted) {
            if (($info['type'] ?? '') === 'inbound') {
                $allowedBcInbound[$code] = $info['label'];
            } else {
                $allowedBcOutbound[$code] = $info['label'];
            }
        }
    }
}
$allowedBcOptions = $allowedBcInbound + $allowedBcOutbound;

$whereConditions = [];
if (!empty($filterCmp)) {
    $whereConditions[] = "a.cmp_id = '" . $koneksi->real_escape_string($filterCmp) . "'";
}
if (empty($_SESSION['is_super_admin']) && !empty($effectiveAllowedDocs) && !in_array('*', $effectiveAllowedDocs)) {
    $filterDocs = array_unique(array_map(function($d) {
        return $d === '27IN' ? '27' : (string)$d;
    }, $effectiveAllowedDocs));
    $escapedDocs = array_map(function($d) use ($koneksi) { return "'" . $koneksi->real_escape_string($d) . "'"; }, $filterDocs);
    $whereConditions[] = "a.kode_dokumen IN (" . implode(',', $escapedDocs) . ")";
}
$whereCmp = !empty($whereConditions) ? "WHERE " . implode(' AND ', $whereConditions) : "";

// Ambil daftar AJU untuk selector dropdown
$ajuList = [];
if (isset($koneksi) && $koneksi) {
    $resAju = $koneksi->query("SELECT a.nomor_aju, a.kode_dokumen, a.tanggal_pernyataan, a.cmp_id, 
        IFNULL(c.cmp_name, a.cmp_id) AS nama_perusahaan,
        (SELECT COUNT(1) FROM bc_ceisa_aju_posted p WHERE p.nomor_aju = a.nomor_aju) AS posted
        FROM bc_ceisa_header a
        LEFT JOIN bc_ceisa_company c ON a.cmp_id = c.cmp_id
        $whereCmp
        ORDER BY a.tanggal_pernyataan DESC, a.nomor_aju DESC LIMIT 100");
    if ($resAju) {
        while ($r = $resAju->fetch_assoc()) {
            $ajuList[] = $r;
        }
    }

    $selectedAjuParam = trim($_GET['nomor_aju'] ?? '');
    if (!empty($selectedAjuParam)) {
        $foundInList = false;
        foreach ($ajuList as $item) {
            if ($item['nomor_aju'] === $selectedAjuParam) {
                $foundInList = true;
                break;
            }
        }
        if (!$foundInList) {
            $stmtSelected = $koneksi->prepare("SELECT a.nomor_aju, a.kode_dokumen, a.tanggal_pernyataan, a.cmp_id, 
                IFNULL(c.cmp_name, a.cmp_id) AS nama_perusahaan,
                (SELECT COUNT(1) FROM bc_ceisa_aju_posted p WHERE p.nomor_aju = a.nomor_aju) AS posted
                FROM bc_ceisa_header a
                LEFT JOIN bc_ceisa_company c ON a.cmp_id = c.cmp_id
                WHERE a.nomor_aju = ? LIMIT 1");
            if ($stmtSelected) {
                $stmtSelected->bind_param("s", $selectedAjuParam);
                $stmtSelected->execute();
                $resSel = $stmtSelected->get_result();
                if ($resSel && $rowSel = $resSel->fetch_assoc()) {
                    array_unshift($ajuList, $rowSel);
                }
                $stmtSelected->close();
            }
        }
    }
}

// -------------------------------------------------------------
// AJAX API HANDLERS
// -------------------------------------------------------------
require_once __DIR__ . '/erp/ErpManager.php';
$action = $_REQUEST['action'] ?? '';

// 1. Ambil Konfigurasi Format Penomoran AJU (26 Digit)
if ($action === 'get_config_aju') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpId = trim($_GET['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ''));
    $bc    = substr(str_pad(preg_replace('/[^0-9]/', '', $_GET['bc'] ?? '23'), 2, '0', STR_PAD_LEFT), 0, 2);

    $escCmp = $koneksi->real_escape_string($cmpId);
    $escBc  = $koneksi->real_escape_string($bc);

    $qCmp = $koneksi->query("SELECT * FROM bc_ceisa_company WHERE cmp_id = '$escCmp' LIMIT 1");
    $comp = $qCmp ? $qCmp->fetch_assoc() : [];

    $qSet = $koneksi->query("SELECT * FROM bc_ceisa_setaju WHERE cmp_id = '$escCmp' AND bc = '$escBc' LIMIT 1");
    if ($qSet && $rSet = $qSet->fetch_assoc()) {
        $a = $rSet['a'];
        $b = $rSet['b'];
        $aju = intval($rSet['aju']);
        $nama_ttd = $rSet['nama_ttd'] ?? '';
        $jabatan_ttd = $rSet['jabatan_ttd'] ?? '';
        $kota_ttd = $rSet['kota_ttd'] ?? '';
    } else {
        $a = !empty($comp['kode_kantor']) ? substr(preg_replace('/[^0-9]/', '', $comp['kode_kantor']), 0, 4) : '0000';
        $a = str_pad($a, 4, '0', STR_PAD_LEFT);
        $b = substr(preg_replace('/[^A-Za-z0-9]/', '', $cmpId), 0, 6);
        $b = str_pad($b, 6, '0', STR_PAD_RIGHT);
        $aju = 0;
        $nama_ttd = '';
        $jabatan_ttd = '';
        $kota_ttd = '';
    }

    echo json_encode([
        'status' => 'OK',
        'data' => [
            'cmp_id'      => $cmpId,
            'bc'          => $bc,
            'a'           => $a,
            'b'           => $b,
            'aju'         => $aju,
            'nama_ttd'    => $nama_ttd,
            'jabatan_ttd' => $jabatan_ttd,
            'kota_ttd'    => $kota_ttd,
            'preview'     => sprintf('%04s%02s%06s%s%06d', $a, $bc, $b, date('Ymd'), $aju + 1)
        ]
    ]);
    exit;
}

// 2. Simpan Konfigurasi Penomoran AJU ke bc_ceisa_setaju
if ($action === 'save_config_aju') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpId       = trim($_POST['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ''));
    $bc          = substr(str_pad(preg_replace('/[^0-9]/', '', $_POST['bc'] ?? '23'), 2, '0', STR_PAD_LEFT), 0, 2);
    $a           = str_pad(substr(preg_replace('/[^0-9]/', '', $_POST['a'] ?? '0000'), 0, 4), 4, '0', STR_PAD_LEFT);
    $b           = str_pad(substr(preg_replace('/[^A-Za-z0-9]/', '', $_POST['b'] ?? '000000'), 0, 6), 6, '0', STR_PAD_RIGHT);
    $aju         = intval($_POST['aju'] ?? 0);
    $nama_ttd    = trim($_POST['nama_ttd'] ?? '');
    $jabatan_ttd = trim($_POST['jabatan_ttd'] ?? '');
    $kota_ttd    = trim($_POST['kota_ttd'] ?? '');

    $escCmp   = $koneksi->real_escape_string($cmpId);
    $escBc    = $koneksi->real_escape_string($bc);
    $escA     = $koneksi->real_escape_string($a);
    $escB     = $koneksi->real_escape_string($b);
    $escNama  = $koneksi->real_escape_string($nama_ttd);
    $escJab   = $koneksi->real_escape_string($jabatan_ttd);
    $escKota  = $koneksi->real_escape_string($kota_ttd);

    $sql = "INSERT INTO bc_ceisa_setaju (cmp_id, bc, a, b, aju, nama_ttd, jabatan_ttd, kota_ttd)
            VALUES ('$escCmp', '$escBc', '$escA', '$escB', $aju, '$escNama', '$escJab', '$escKota')
            ON DUPLICATE KEY UPDATE a = '$escA', b = '$escB', aju = $aju, nama_ttd = '$escNama', jabatan_ttd = '$escJab', kota_ttd = '$escKota'";
    if ($koneksi->query($sql)) {
        echo json_encode(['status' => 'OK', 'message' => 'Konfigurasi Penomoran AJU berhasil disimpan!']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyimpan konfigurasi: ' . $koneksi->error]);
    }
    exit;
}

// -------------------------------------------------------------
// KONFIGURASI URUTAN DEFAULT ENTITAS (bc_ceisa_entidx)
// -------------------------------------------------------------
if ($action === 'get_entidx') {
    header('Content-Type: application/json; charset=utf-8');
    $bc = preg_replace('/[^0-9]/', '', $_GET['bc'] ?? '40');
    if (empty($bc)) $bc = '40';
    $cmpId = trim($_GET['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ''));

    $escBc = $koneksi->real_escape_string($bc);
    $escCmp = $koneksi->real_escape_string($cmpId);

    // Cek apakah ada setting khusus perusahaan, jika tidak fallback ke global (cmp_id = '')
    $qCheck = $koneksi->query("SELECT COUNT(1) FROM bc_ceisa_entidx WHERE bc = '$escBc' AND cmp_id = '$escCmp'");
    $hasCmpSetting = ($qCheck && $qCheck->fetch_row()[0] > 0);
    $targetCmp = $hasCmpSetting ? $escCmp : '';

    $sql = "SELECT a.cmp_id, a.bc, a.kode_entitas, a.idx, IFNULL(r.teks, a.kode_entitas) AS nama_entitas_reff
            FROM bc_ceisa_entidx a
            LEFT JOIN bc_ceisa_reff r ON r.reff = 'ENTITAS' AND r.kode = a.kode_entitas
            WHERE a.bc = '$escBc' AND (a.cmp_id = '$targetCmp' OR a.cmp_id = '' OR a.cmp_id IS NULL)
            ORDER BY a.idx ASC";
    $res = $koneksi->query($sql);
    $list = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $list[] = [
                'idx'                => intval($r['idx']),
                'kode_entitas'       => $r['kode_entitas'],
                'nama_entitas_reff'  => $r['nama_entitas_reff'],
                'cmp_id'             => $r['cmp_id'] ?? ''
            ];
        }
    }

    echo json_encode([
        'status' => 'OK',
        'bc'     => $bc,
        'cmp_id' => $targetCmp,
        'is_custom_company' => $hasCmpSetting,
        'data'   => $list
    ]);
    exit;
}

if ($action === 'save_entidx') {
    header('Content-Type: application/json; charset=utf-8');
    $bc = preg_replace('/[^0-9]/', '', $_POST['bc'] ?? '40');
    if (empty($bc)) $bc = '40';
    $scope = trim($_POST['scope'] ?? 'global'); // 'global' atau 'company'
    $cmpId = trim($_POST['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ''));
    $items = json_decode($_POST['items'] ?? '[]', true);

    $escBc = $koneksi->real_escape_string($bc);
    $targetCmp = ($scope === 'company' && !empty($cmpId)) ? $koneksi->real_escape_string($cmpId) : '';

    $koneksi->begin_transaction();
    try {
        $koneksi->query("DELETE FROM bc_ceisa_entidx WHERE bc = '$escBc' AND cmp_id = '$targetCmp'");
        if (!empty($items) && is_array($items)) {
            $idx = 1;
            foreach ($items as $it) {
                $kd = $koneksi->real_escape_string(trim($it['kode_entitas'] ?? ''));
                if (!empty($kd)) {
                    $koneksi->query("INSERT INTO bc_ceisa_entidx (cmp_id, bc, kode_entitas, idx) VALUES ('$targetCmp', '$escBc', '$kd', $idx)");
                    $idx++;
                }
            }
        }
        $koneksi->commit();
        echo json_encode(['status' => 'OK', 'message' => "Setting list entitas default BC $bc berhasil disimpan!"]);
    } catch (\Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyimpan: ' . $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------
// KONFIGURASI URUTAN DEFAULT DOKUMEN LAMPIRAN (bc_ceisa_docidx)
// -------------------------------------------------------------
if ($action === 'get_docidx') {
    header('Content-Type: application/json; charset=utf-8');
    $bc = preg_replace('/[^0-9]/', '', $_GET['bc'] ?? '40');
    if (empty($bc)) $bc = '40';
    $cmpId = trim($_GET['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ''));

    $escBc = $koneksi->real_escape_string($bc);
    $escCmp = $koneksi->real_escape_string($cmpId);

    // Cek apakah ada setting khusus perusahaan, jika tidak fallback ke global (cmp_id = '')
    $qCheck = $koneksi->query("SELECT COUNT(1) FROM bc_ceisa_docidx WHERE bc = '$escBc' AND cmp_id = '$escCmp'");
    $hasCmpSetting = ($qCheck && $qCheck->fetch_row()[0] > 0);
    $targetCmp = $hasCmpSetting ? $escCmp : '';

    $sql = "SELECT a.cmp_id, a.bc, a.kode_dokumen, a.idx, IFNULL(r.teks, a.kode_dokumen) AS nama_dokumen_reff
            FROM bc_ceisa_docidx a
            LEFT JOIN bc_ceisa_reff r ON r.reff = 'DOKUMEN' AND r.kode = a.kode_dokumen
            WHERE a.bc = '$escBc' AND (a.cmp_id = '$targetCmp' OR a.cmp_id = '' OR a.cmp_id IS NULL)
            ORDER BY a.idx ASC";
    $res = $koneksi->query($sql);
    $list = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $list[] = [
                'idx'                => intval($r['idx']),
                'kode_dokumen'       => $r['kode_dokumen'],
                'nama_dokumen_reff'  => $r['nama_dokumen_reff'],
                'cmp_id'             => $r['cmp_id'] ?? ''
            ];
        }
    }

    echo json_encode([
        'status' => 'OK',
        'bc'     => $bc,
        'cmp_id' => $targetCmp,
        'is_custom_company' => $hasCmpSetting,
        'data'   => $list
    ]);
    exit;
}

if ($action === 'save_docidx') {
    header('Content-Type: application/json; charset=utf-8');
    $bc = preg_replace('/[^0-9]/', '', $_POST['bc'] ?? '40');
    if (empty($bc)) $bc = '40';
    $scope = trim($_POST['scope'] ?? 'global'); // 'global' atau 'company'
    $cmpId = trim($_POST['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ''));
    $items = json_decode($_POST['items'] ?? '[]', true);

    $escBc = $koneksi->real_escape_string($bc);
    $targetCmp = ($scope === 'company' && !empty($cmpId)) ? $koneksi->real_escape_string($cmpId) : '';

    $koneksi->begin_transaction();
    try {
        $koneksi->query("DELETE FROM bc_ceisa_docidx WHERE bc = '$escBc' AND cmp_id = '$targetCmp'");
        if (!empty($items) && is_array($items)) {
            $idx = 1;
            foreach ($items as $it) {
                $kd = $koneksi->real_escape_string(trim($it['kode_dokumen'] ?? ''));
                if (!empty($kd)) {
                    $koneksi->query("INSERT INTO bc_ceisa_docidx (cmp_id, bc, kode_dokumen, idx) VALUES ('$targetCmp', '$escBc', '$kd', $idx)");
                    $idx++;
                }
            }
        }
        $koneksi->commit();
        echo json_encode(['status' => 'OK', 'message' => "Setting list dokumen default BC $bc berhasil disimpan!"]);
    } catch (\Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyimpan: ' . $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------
// PENCARIAN RIWAYAT ENTITAS (LOOKUP HISTORY)
// -------------------------------------------------------------
if ($action === 'search_entitas_history') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    $cmpId = trim($_GET['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ''));
    $kodeEntitas = trim($_GET['kode_entitas'] ?? '');

    $whereClauses = ["IFNULL(a.nama_entitas, '') <> ''"];
    if (!empty($q)) {
        $escQ = $koneksi->real_escape_string($q);
        $whereClauses[] = "(a.nama_entitas LIKE '%$escQ%' OR a.nomor_identitas LIKE '%$escQ%' OR a.alamat_entitas LIKE '%$escQ%')";
    }
    if (!empty($kodeEntitas)) {
        $escKd = $koneksi->real_escape_string($kodeEntitas);
        // Prioritaskan kode entitas yang sama jika diminta
        $whereClauses[] = "a.kode_entitas = '$escKd'";
    }

    $whereSql = implode(' AND ', $whereClauses);

    $sql = "SELECT a.kode_entitas,
                   IFNULL(r.teks, a.kode_entitas) AS peran_entitas,
                   a.kode_jenis_identitas,
                   a.nomor_identitas,
                   a.nama_entitas,
                   a.alamat_entitas,
                   a.nib_entitas,
                   a.nomor_ijin_entitas,
                   a.tanggal_ijin_entitas,
                   a.kode_negara,
                   COUNT(1) as frekuensi,
                   MAX(a.upd_time) as last_used
            FROM bc_ceisa_entitas a
            LEFT JOIN bc_ceisa_reff r ON r.reff = 'ENTITAS' AND r.kode = a.kode_entitas
            WHERE $whereSql
            GROUP BY a.nomor_identitas, a.nama_entitas, a.alamat_entitas, a.kode_jenis_identitas, a.nib_entitas, a.kode_negara
            ORDER BY frekuensi DESC, last_used DESC
            LIMIT 50";

    $res = $koneksi->query($sql);
    $data = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $data[] = $row;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'data'   => $data
    ]);
    exit;
}

// -------------------------------------------------------------
// RESET ENTITAS SESUAI DEFAULT DOKUMEN BC (bc_ceisa_entidx)
// -------------------------------------------------------------
if ($action === 'reset_entitas_default') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_POST['nomor_aju'] ?? '');
    $kodeBc    = trim($_POST['kode_dokumen'] ?? '40');
    $cmpId     = trim($_POST['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ''));

    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak valid.']);
        exit;
    }

    $cleanBc = preg_replace('/[^0-9]/', '', $kodeBc);
    $escBc = $koneksi->real_escape_string($cleanBc);
    $escCmp = $koneksi->real_escape_string($cmpId);
    $escAju = $koneksi->real_escape_string($nomor_aju);

    // Ambil profil company
    $comp = [];
    $qCmp = $koneksi->query("SELECT * FROM bc_ceisa_company WHERE cmp_id = '$escCmp' LIMIT 1");
    if ($qCmp && $rCmp = $qCmp->fetch_assoc()) {
        $comp = $rCmp;
    }
    $npwp   = $comp['npwp'] ?? '';
    $nama   = $comp['cmp_name'] ?? '';
    $alamat = $comp['alamat'] ?? '';
    $nib    = $comp['nib'] ?? '';

    // Ambil urutan bc_ceisa_entidx
    $qIdx = $koneksi->query("SELECT kode_entitas, idx FROM bc_ceisa_entidx 
                             WHERE bc = '$escBc' AND (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)
                             ORDER BY IF(cmp_id = '$escCmp', 0, 1) ASC, idx ASC");
    $entRows = [];
    $seenIdx = [];
    if ($qIdx) {
        while ($rIdx = $qIdx->fetch_assoc()) {
            $idxNum = intval($rIdx['idx']);
            if (isset($seenIdx[$idxNum])) continue;
            $seenIdx[$idxNum] = true;
            $entRows[] = $rIdx;
        }
    }

    if (empty($entRows)) {
        // Fallback default jika di entidx belum ada
        if ($cleanBc === '30') {
            $entRows = [
                ['kode_entitas' => '2', 'idx' => 1],
                ['kode_entitas' => '7', 'idx' => 2],
                ['kode_entitas' => '8', 'idx' => 3],
                ['kode_entitas' => '6', 'idx' => 4],
                ['kode_entitas' => '4', 'idx' => 5],
                ['kode_entitas' => '23', 'idx' => 6]
            ];
        } else {
            $entRows = [
                ['kode_entitas' => '3', 'idx' => 1],
                ['kode_entitas' => '7', 'idx' => 2],
                ['kode_entitas' => '9', 'idx' => 3]
            ];
        }
    }

    $koneksi->begin_transaction();
    try {
        $koneksi->query("DELETE FROM bc_ceisa_entitas WHERE nomor_aju = '$escAju'");
        $seri = 1;
        $insertedData = [];
        foreach ($entRows as $er) {
            $kd = $er['kode_entitas'];
            // Apakah entitas ini adalah perusahaan sendiri?
            // 3 = Pengusaha TPB, 7 = Pemilik Barang, 2 = Importir / Eksportir
            $isOwnCompany = in_array($kd, ['3', '7', '2']);
            $nomorId = $isOwnCompany ? $npwp : '';
            $namaEnt = $isOwnCompany ? $nama : '';
            $almtEnt = $isOwnCompany ? $alamat : '';
            $nibEnt  = $isOwnCompany ? $nib : '';
            $jenisId = $isOwnCompany ? '5' : '5'; // 5 = NPWP 16 Digit / NITKU
            $kdNegara= $isOwnCompany ? 'ID' : '';

            $escNomorId = $koneksi->real_escape_string($nomorId);
            $escNamaEnt = $koneksi->real_escape_string($namaEnt);
            $escAlmtEnt = $koneksi->real_escape_string($almtEnt);
            $escNibEnt  = $koneksi->real_escape_string($nibEnt);
            $escKdNeg   = $koneksi->real_escape_string($kdNegara);

            $sqlIns = "INSERT INTO bc_ceisa_entitas (
                cmp_id, nomor_aju, seri, kode_entitas, kode_jenis_identitas,
                nomor_identitas, nama_entitas, alamat_entitas, nib_entitas, kode_negara
            ) VALUES (
                '$escCmp', '$escAju', $seri, '$kd', '$jenisId',
                '$escNomorId', '$escNamaEnt', '$escAlmtEnt', '$escNibEnt', '$escKdNeg'
            )";
            $koneksi->query($sqlIns);

            $insertedData[] = [
                'seri'                  => $seri,
                'kode_entitas'          => $kd,
                'kode_jenis_identitas'  => $jenisId,
                'nomor_identitas'       => $nomorId,
                'nama_entitas'          => $namaEnt,
                'alamat_entitas'        => $almtEnt,
                'nib_entitas'           => $nibEnt,
                'nomor_ijin_entitas'    => '',
                'tanggal_ijin_entitas'  => '',
                'kode_negara'           => $kdNegara
            ];
            $seri++;
        }
        $koneksi->commit();

        echo json_encode([
            'status'  => 'OK',
            'message' => "Entitas berhasil direset sesuai default dokumen BC $cleanBc!",
            'data'    => $insertedData
        ]);
    } catch (\Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal mereset entitas: ' . $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------
// RESET DOKUMEN SESUAI DEFAULT DOKUMEN BC (bc_ceisa_docidx)
// -------------------------------------------------------------
if ($action === 'reset_dokumen_default') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_POST['nomor_aju'] ?? '');
    $kodeBc    = trim($_POST['kode_dokumen'] ?? '40');
    $cmpId     = trim($_POST['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ''));

    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak valid.']);
        exit;
    }

    $cleanBc = preg_replace('/[^0-9]/', '', $kodeBc);
    $escBc = $koneksi->real_escape_string($cleanBc);
    $escCmp = $koneksi->real_escape_string($cmpId);
    $escAju = $koneksi->real_escape_string($nomor_aju);

    // Ambil tanggal hari ini untuk default tanggal dokumen
    $todayDate = date('Y-m-d');

    // Ambil urutan bc_ceisa_docidx
    $qIdx = $koneksi->query("SELECT kode_dokumen, idx FROM bc_ceisa_docidx 
                             WHERE bc = '$escBc' AND (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)
                             ORDER BY IF(cmp_id = '$escCmp', 0, 1) ASC, idx ASC");
    $docRows = [];
    $seenIdx = [];
    if ($qIdx) {
        while ($rIdx = $qIdx->fetch_assoc()) {
            $idxNum = intval($rIdx['idx']);
            if (isset($seenIdx[$idxNum])) continue;
            $seenIdx[$idxNum] = true;
            $docRows[] = $rIdx;
        }
    }

    if (empty($docRows)) {
        // Fallback default jika di docidx belum ada
        if ($cleanBc === '40') {
            $docRows = [
                ['kode_dokumen' => '630', 'idx' => 1],
                ['kode_dokumen' => '388', 'idx' => 2],
                ['kode_dokumen' => '380', 'idx' => 3],
                ['kode_dokumen' => '217', 'idx' => 4],
                ['kode_dokumen' => '315', 'idx' => 5]
            ];
        } else {
            $docRows = [
                ['kode_dokumen' => '380', 'idx' => 1],
                ['kode_dokumen' => '217', 'idx' => 2]
            ];
        }
    }

    $koneksi->begin_transaction();
    try {
        $koneksi->query("DELETE FROM bc_ceisa_dokumen WHERE nomor_aju = '$escAju'");
        $seri = 1;
        $insertedData = [];
        foreach ($docRows as $dr) {
            $kd = $dr['kode_dokumen'];
            $nomorDok = '';
            $tglDok = $todayDate;

            $escKd = $koneksi->real_escape_string($kd);
            $escNomorDok = $koneksi->real_escape_string($nomorDok);
            $escTglDok = $koneksi->real_escape_string($tglDok);

            $sqlIns = "INSERT INTO bc_ceisa_dokumen (
                cmp_id, nomor_aju, seri, kode_dokumen, nomor_dokumen, tanggal_dokumen
            ) VALUES (
                '$escCmp', '$escAju', $seri, '$escKd', '$escNomorDok', '$escTglDok'
            )";
            $koneksi->query($sqlIns);

            $insertedData[] = [
                'seri'            => $seri,
                'kode_dokumen'    => $kd,
                'nomor_dokumen'   => $nomorDok,
                'tanggal_dokumen' => $tglDok
            ];
            $seri++;
        }
        $koneksi->commit();

        echo json_encode([
            'status'  => 'OK',
            'message' => "Dokumen lampiran berhasil disesuaikan dengan default dokumen BC $cleanBc!",
            'data'    => $insertedData
        ]);
    } catch (\Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal mereset dokumen lampiran: ' . $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------
// FILTER SETTINGS: SARING TAB & SARING KOLOM (GLOBAL & PER COMPANY)
// -------------------------------------------------------------
if ($action === 'get_filter_settings') {
    header('Content-Type: application/json; charset=utf-8');
    $bc = preg_replace('/[^0-9]/', '', $_GET['bc'] ?? '23');
    if (empty($bc)) $bc = '23';
    $cmpId = trim($_GET['cmp_id'] ?? '');

    $escBc = $koneksi->real_escape_string($bc);
    $escCmp = $koneksi->real_escape_string($cmpId);

    // Ambil daftar seluruh perusahaan untuk dropdown scope
    $compList = [];
    $qc = $koneksi->query("SELECT cmp_id, cmp_name FROM bc_ceisa_company ORDER BY cmp_name ASC");
    if ($qc) {
        while ($rc = $qc->fetch_assoc()) {
            $compList[] = [
                'cmp_id' => $rc['cmp_id'],
                'cmp_name' => $rc['cmp_name']
            ];
        }
    }

    // TAB VISIBILITY
    $companyTabSetting = [];
    $hasCompanyTabCustom = false;
    if (!empty($cmpId)) {
        $qt = $koneksi->query("SELECT tab_key, is_visible FROM bc_ceisa_setting_tab WHERE cmp_id = '$escCmp' AND bc = '$escBc'");
        if ($qt && $qt->num_rows > 0) {
            $hasCompanyTabCustom = true;
            while ($r = $qt->fetch_assoc()) {
                $companyTabSetting[$r['tab_key']] = (int)$r['is_visible'];
            }
        }
    }

    $globalTabSetting = [];
    $qtG = $koneksi->query("SELECT tab_key, is_visible FROM bc_ceisa_setting_tab WHERE cmp_id = '' AND bc = '$escBc'");
    if ($qtG && $qtG->num_rows > 0) {
        while ($r = $qtG->fetch_assoc()) {
            $globalTabSetting[$r['tab_key']] = (int)$r['is_visible'];
        }
    }

    // KOLOM VISIBILITY
    $colFlag = 'v' . $bc;
    $chkCol = $koneksi->query("SHOW COLUMNS FROM bc_ceisa_varheader LIKE '$colFlag'");
    if (!$chkCol || $chkCol->num_rows == 0) {
        $colFlag = 'v23';
    }

    $companyColSetting = [];
    $hasCompanyColCustom = false;
    if (!empty($cmpId)) {
        $qcC = $koneksi->query("SELECT tabel, col_id, is_visible FROM bc_ceisa_setting_kolom WHERE cmp_id = '$escCmp' AND bc = '$escBc'");
        if ($qcC && $qcC->num_rows > 0) {
            $hasCompanyColCustom = true;
            while ($r = $qcC->fetch_assoc()) {
                $companyColSetting[$r['tabel']][$r['col_id']] = (int)$r['is_visible'];
            }
        }
    }

    $globalColSetting = [];
    $hasGlobalColCustom = false;
    $qcG = $koneksi->query("SELECT tabel, col_id, is_visible FROM bc_ceisa_setting_kolom WHERE cmp_id = '' AND bc = '$escBc'");
    if ($qcG && $qcG->num_rows > 0) {
        $hasGlobalColCustom = true;
        while ($r = $qcG->fetch_assoc()) {
            $globalColSetting[$r['tabel']][$r['col_id']] = (int)$r['is_visible'];
        }
    }

    // Metadata kolom dari bc_ceisa_varheader
    $columns = ['header' => [], 'barang' => []];
    $qvh = $koneksi->query("SELECT tabel, col_id, col_txt, reff, `$colFlag` AS base_visible, isnum, isdate FROM bc_ceisa_varheader WHERE tabel IN ('header', 'barang') ORDER BY idx ASC");
    if ($qvh) {
        while ($vr = $qvh->fetch_assoc()) {
            $tbl = $vr['tabel'];
            $cid = $vr['col_id'];
            $baseVis = (int)($vr['base_visible'] ?? 0);
            
            // Tentukan status saat ini
            $currVis = $baseVis;
            if ($hasCompanyColCustom && isset($companyColSetting[$tbl][$cid])) {
                $currVis = $companyColSetting[$tbl][$cid];
            } elseif ($hasGlobalColCustom && isset($globalColSetting[$tbl][$cid])) {
                $currVis = $globalColSetting[$tbl][$cid];
            }

            $columns[$tbl][] = [
                'col_id' => $cid,
                'col_txt' => $vr['col_txt'],
                'reff' => $vr['reff'],
                'base_visible' => $baseVis,
                'global_visible' => $globalColSetting[$tbl][$cid] ?? $baseVis,
                'company_visible' => $companyColSetting[$tbl][$cid] ?? null,
                'current_visible' => $currVis
            ];
        }
    }

    // FORMAT DESIMAL (bc_ceisa_setting_desimal)
    $companyDecSetting = [];
    $hasCompanyDecCustom = false;
    if (!empty($cmpId)) {
        $qdC = $koneksi->query("SELECT category, decimals FROM bc_ceisa_setting_desimal WHERE cmp_id = '$escCmp'");
        if ($qdC && $qdC->num_rows > 0) {
            $hasCompanyDecCustom = true;
            while ($r = $qdC->fetch_assoc()) {
                $companyDecSetting[$r['category']] = (int)$r['decimals'];
            }
        }
    }

    $globalDecSetting = [];
    $qdG = $koneksi->query("SELECT category, decimals FROM bc_ceisa_setting_desimal WHERE cmp_id = ''");
    if ($qdG && $qdG->num_rows > 0) {
        while ($r = $qdG->fetch_assoc()) {
            $globalDecSetting[$r['category']] = (int)$r['decimals'];
        }
    }

    $defaultDecSetting = [
        'qty' => 2,
        'kemasan' => 0,
        'uang' => 2,
        'berat' => 4,
        'harga_satuan' => 4,
        'tarif' => 2
    ];
    $currentDecSetting = array_merge($defaultDecSetting, $globalDecSetting);
    if ($hasCompanyDecCustom) {
        $currentDecSetting = array_merge($currentDecSetting, $companyDecSetting);
    }

    echo json_encode([
        'status' => 'OK',
        'bc' => $bc,
        'cmp_id' => $cmpId,
        'companies' => $compList,
        'tab' => [
            'has_company_custom' => $hasCompanyTabCustom,
            'company_rules' => $companyTabSetting,
            'global_rules' => $globalTabSetting
        ],
        'kolom' => [
            'has_company_custom' => $hasCompanyColCustom,
            'has_global_custom' => $hasGlobalColCustom,
            'columns' => $columns
        ],
        'desimal' => [
            'has_company_custom' => $hasCompanyDecCustom,
            'company_rules' => $companyDecSetting,
            'global_rules' => $globalDecSetting,
            'current' => $currentDecSetting,
            'defaults' => $defaultDecSetting
        ]
    ]);
    exit;
}

if ($action === 'save_filter_settings') {
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true) ?: $_POST;

    $bc = preg_replace('/[^0-9]/', '', $payload['bc'] ?? '23');
    if (empty($bc)) $bc = '23';
    $cmpId = trim($payload['cmp_id'] ?? '');
    $saveType = trim($payload['save_type'] ?? 'all');
    $user = $_SESSION['user_id'] ?? 'USER';

    $escBc = $koneksi->real_escape_string($bc);
    $escCmp = $koneksi->real_escape_string($cmpId);
    $escUser = $koneksi->real_escape_string($user);

    // 1. Simpan TAB jika diminta
    if (($saveType === 'all' || $saveType === 'tab') && isset($payload['tabs']) && is_array($payload['tabs'])) {
        $stmtTab = $koneksi->prepare("INSERT INTO bc_ceisa_setting_tab (cmp_id, bc, tab_key, is_visible, updated_by) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE is_visible = VALUES(is_visible), updated_by = VALUES(updated_by)");
        foreach ($payload['tabs'] as $tabKey => $vis) {
            $tabKey = trim($tabKey);
            $v = $vis ? 1 : 0;
            $stmtTab->bind_param("sssis", $cmpId, $bc, $tabKey, $v, $user);
            $stmtTab->execute();
        }
    }

    // 2. Simpan KOLOM jika diminta
    if (($saveType === 'all' || $saveType === 'kolom') && isset($payload['cols']) && is_array($payload['cols'])) {
        $stmtCol = $koneksi->prepare("INSERT INTO bc_ceisa_setting_kolom (cmp_id, bc, tabel, col_id, is_visible, updated_by) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE is_visible = VALUES(is_visible), updated_by = VALUES(updated_by)");
        foreach (['header', 'barang'] as $tbl) {
            if (isset($payload['cols'][$tbl]) && is_array($payload['cols'][$tbl])) {
                foreach ($payload['cols'][$tbl] as $colId => $vis) {
                    $colId = trim($colId);
                    $v = $vis ? 1 : 0;
                    $stmtCol->bind_param("ssssis", $cmpId, $bc, $tbl, $colId, $v, $user);
                    $stmtCol->execute();
                }
            }
        }
    }

    // 3. Simpan FORMAT DESIMAL jika diminta
    if (($saveType === 'all' || $saveType === 'desimal') && isset($payload['decimals']) && is_array($payload['decimals'])) {
        $stmtDec = $koneksi->prepare("INSERT INTO bc_ceisa_setting_desimal (cmp_id, category, decimals, updated_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE decimals = VALUES(decimals), updated_by = VALUES(updated_by)");
        $validCats = ['qty', 'kemasan', 'uang', 'berat', 'harga_satuan', 'tarif'];
        foreach ($validCats as $cat) {
            if (isset($payload['decimals'][$cat])) {
                $decVal = max(0, min(6, (int)$payload['decimals'][$cat]));
                $stmtDec->bind_param("ssis", $cmpId, $cat, $decVal, $user);
                $stmtDec->execute();
            }
        }
    }

    $targetName = !empty($cmpId) ? "Perusahaan [$cmpId]" : "Global (Default Semua Perusahaan)";
    echo json_encode([
        'status' => 'OK',
        'message' => "Pengaturan {$targetName} untuk BC {$bc} berhasil disimpan."
    ]);
    exit;
}

if ($action === 'reset_filter_settings') {
    header('Content-Type: application/json; charset=utf-8');
    $bc = preg_replace('/[^0-9]/', '', $_POST['bc'] ?? '23');
    $cmpId = trim($_POST['cmp_id'] ?? '');
    $resetType = trim($_POST['reset_type'] ?? 'all');

    if (empty($cmpId)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Hanya pengaturan Perusahaan yang dapat direset ke Global.']);
        exit;
    }

    $escBc = $koneksi->real_escape_string($bc);
    $escCmp = $koneksi->real_escape_string($cmpId);

    if ($resetType === 'all' || $resetType === 'tab') {
        $koneksi->query("DELETE FROM bc_ceisa_setting_tab WHERE cmp_id = '$escCmp' AND bc = '$escBc'");
    }

    if ($resetType === 'all' || $resetType === 'kolom') {
        $koneksi->query("DELETE FROM bc_ceisa_setting_kolom WHERE cmp_id = '$escCmp' AND bc = '$escBc'");
    }

    if ($resetType === 'all' || $resetType === 'desimal') {
        $koneksi->query("DELETE FROM bc_ceisa_setting_desimal WHERE cmp_id = '$escCmp'");
    }

    echo json_encode([
        'status' => 'OK',
        'message' => "Pengaturan Perusahaan [$cmpId] telah berhasil direset dan kembali mengikuti Pengaturan Global."
    ]);
    exit;
}

// 3. Tarik Nilai NDPBM (Kurs Pajak Bea Cukai)
if ($action === 'get_ndpbm') {
    header('Content-Type: application/json; charset=utf-8');
    $valuta  = strtoupper(trim($_GET['valuta'] ?? ($_GET['kode'] ?? 'USD')));
    $cmpId   = trim($_GET['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ''));

    if ($valuta === 'IDR') {
        echo json_encode(['status' => 'OK', 'valuta' => 'IDR', 'ndpbm' => 1.0, 'source' => 'STANDAR IDR']);
        exit;
    }

    $bearer = $_SESSION['token'] ?? '';
    if (empty($bearer) && !empty($cmpId)) {
        $escCmp = $koneksi->real_escape_string($cmpId);
        $qT = $koneksi->query("SELECT token_ceisa FROM bc_ceisa_company WHERE cmp_id = '$escCmp' LIMIT 1");
        if ($qT && $rT = $qT->fetch_assoc()) {
            $bearer = $rT['token_ceisa'] ?? '';
        }
    }

    $rate = null;
    $source = 'CEISA 4.0 API LIVE';

    if (!empty($bearer)) {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => "https://apis-gw.beacukai.go.id/openapi/kurs/" . urlencode(strtolower($valuta)),
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $bearer",
                "Content-Type: application/json"
            ]
        ]);
        $res = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if (!$err && !empty($res)) {
            $j = json_decode($res, true);
            if (!empty($j['data'][0]['nilaiKurs'])) {
                $rate = floatval($j['data'][0]['nilaiKurs']);
            }
        }
    }

    if ($rate === null || $rate <= 0) {
        $escVal = $koneksi->real_escape_string($valuta);
        $qH = $koneksi->query("SELECT ndpbm FROM bc_ceisa_header WHERE kode_valuta = '$escVal' AND ndpbm > 1 ORDER BY tanggal_pernyataan DESC LIMIT 1");
        if ($qH && $rH = $qH->fetch_assoc()) {
            $rate = floatval($rH['ndpbm']);
            $source = 'DATABASE TERAKHIR';
        }
    }

    if ($rate === null || $rate <= 0) {
        $fallbackRates = [
            'USD' => 16250.00,
            'EUR' => 17620.00,
            'SGD' => 12240.00,
            'JPY' => 108.50,
            'CNY' => 2245.00,
            'GBP' => 20850.00,
            'AUD' => 10620.00,
            'KRW' => 12.15,
            'MYR' => 3680.00,
            'HKD' => 2080.00,
        ];
        $rate = $fallbackRates[$valuta] ?? 16250.00;
        $source = 'REFERENSI STANDAR';
    }

    echo json_encode([
        'status' => 'OK',
        'valuta' => $valuta,
        'ndpbm'  => $rate,
        'source' => $source
    ]);
    exit;
}

// 4. Buat Dokumen Baru (Generate Nomor AJU 26 Digit & Inisialisasi Staging)
if ($action === 'create_new_aju') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpId   = trim($_POST['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ''));
    $kodeBc  = trim($_POST['kode_dokumen'] ?? '23');
    $ndpbm   = floatval($_POST['ndpbm'] ?? 0);
    $valuta  = strtoupper(trim($_POST['valuta'] ?? 'USD'));
    $tanggal = trim($_POST['tanggal_pernyataan'] ?? date('Y-m-d'));

    if (empty($cmpId)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Perusahaan (cmp_id) tidak valid atau belum dipilih.']);
        exit;
    }
    if (empty($kodeBc)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Pilih Jenis Dokumen BC terlebih dahulu!']);
        exit;
    }
    if ($ndpbm <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nilai NDPBM harus lebih dari 0. Silakan tarik kurs NDPBM terlebih dahulu!']);
        exit;
    }

    $cleanBc = preg_replace('/[^0-9]/', '', $kodeBc);
    $cleanBc2 = substr(str_pad($cleanBc, 2, '0', STR_PAD_LEFT), 0, 2);
    $ymd = date('Ymd', strtotime($tanggal));

    $escCmp = $koneksi->real_escape_string($cmpId);
    $escBc2 = $koneksi->real_escape_string($cleanBc2);

    $comp = [];
    $qCmp = $koneksi->query("SELECT * FROM bc_ceisa_company WHERE cmp_id = '$escCmp' LIMIT 1");
    if ($qCmp && $rCmp = $qCmp->fetch_assoc()) {
        $comp = $rCmp;
    }

    $qSet = $koneksi->query("SELECT * FROM bc_ceisa_setaju WHERE cmp_id = '$escCmp' AND bc = '$escBc2' LIMIT 1");
    if (!$qSet || $qSet->num_rows === 0) {
        $a = !empty($comp['kode_kantor']) ? substr(preg_replace('/[^0-9]/', '', $comp['kode_kantor']), 0, 4) : '0000';
        $a = str_pad($a, 4, '0', STR_PAD_LEFT);
        $b = substr(preg_replace('/[^A-Za-z0-9]/', '', $cmpId), 0, 6);
        $b = str_pad($b, 6, '0', STR_PAD_RIGHT);

        $todayPrefix = $a . $cleanBc2 . $b . $ymd;
        $qMax = $koneksi->query("SELECT nomor_aju FROM bc_ceisa_header WHERE nomor_aju LIKE '{$todayPrefix}%' ORDER BY nomor_aju DESC LIMIT 1");
        $lastSeq = 0;
        if ($qMax && $rMax = $qMax->fetch_assoc()) {
            $lastSeq = intval(substr($rMax['nomor_aju'], -6));
        }
        $nextSeq = $lastSeq + 1;
        $koneksi->query("INSERT INTO bc_ceisa_setaju (cmp_id, bc, a, b, aju) VALUES ('$escCmp', '$escBc2', '$a', '$b', $nextSeq)");
    } else {
        $rSet = $qSet->fetch_assoc();
        $a = str_pad(substr($rSet['a'] ?? '0000', 0, 4), 4, '0', STR_PAD_LEFT);
        $b = str_pad(substr($rSet['b'] ?? '000000', 0, 6), 6, '0', STR_PAD_RIGHT);
        $nextSeq = intval($rSet['aju'] ?? 0) + 1;
        $koneksi->query("UPDATE bc_ceisa_setaju SET aju = $nextSeq WHERE cmp_id = '$escCmp' AND bc = '$escBc2'");
    }

    // Nomor AJU 26 Digit: A (4) + BC (2) + B (6) + YYYYMMDD (8) + SEQ (6)
    $nomorAju = sprintf('%04s%02s%06s%08s%06d', $a, $cleanBc2, $b, $ymd, $nextSeq);
    $escAju   = $koneksi->real_escape_string($nomorAju);
    $escBc    = $koneksi->real_escape_string($cleanBc);
    $escKantor= $koneksi->real_escape_string($comp['kode_kantor'] ?? $a);
    $escVal   = $koneksi->real_escape_string($valuta);

    $koneksi->begin_transaction();
    try {
        // 1. Insert Header Staging
        $sqlHdr = "INSERT INTO bc_ceisa_header (
            nomor_aju, kode_dokumen, cmp_id, tanggal_pernyataan, kode_kantor, kode_valuta, ndpbm,
            fob, cif, netto, bruto
        ) VALUES (
            '$escAju', '$escBc', '$escCmp', '$tanggal', '$escKantor', '$escVal', $ndpbm,
            0, 0, 0, 0
        )";
        if (!$koneksi->query($sqlHdr)) {
            throw new Exception("Gagal membuat header: " . $koneksi->error);
        }

        if ($cleanBc === '30') {
            $koneksi->query("UPDATE bc_ceisa_header SET kode_jenis_ekspor = '1', kode_kategori_ekspor = '1', kode_cara_dagang = '1' WHERE nomor_aju = '$escAju'");
        } elseif ($cleanBc === '40') {
            $koneksi->query("UPDATE bc_ceisa_header SET kode_tujuan_pemasukan = '1' WHERE nomor_aju = '$escAju'");
        }

        // 2. Insert Pengangkut Seri 1
        $caraAngkut = in_array($cleanBc, ['40', '41', '25', '27']) ? '3' : '1';
        $koneksi->query("INSERT INTO bc_ceisa_pengangkut (cmp_id, nomor_aju, seri, kode_cara_angkut) VALUES ('$escCmp', '$escAju', 1, '$caraAngkut')");

        // 3. Insert Kemasan Seri 1
        $koneksi->query("INSERT INTO bc_ceisa_kemasan (cmp_id, nomor_aju, seri, jumlah_kemasan, kode_kemasan) VALUES ('$escCmp', '$escAju', 1, 1, 'PX')");

        // 4. Insert Entitas Default berdasarkan Urutan Konfigurasi Dokumen BC (bc_ceisa_entidx)
        $npwp   = $koneksi->real_escape_string($comp['npwp'] ?? '');
        $nama   = $koneksi->real_escape_string($comp['cmp_name'] ?? '');
        $alamat = $koneksi->real_escape_string($comp['alamat'] ?? '');
        $nib    = $koneksi->real_escape_string($comp['nib'] ?? '');

        $qIdx = $koneksi->query("SELECT kode_entitas, idx FROM bc_ceisa_entidx 
                                 WHERE bc = '$escBc' AND (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)
                                 ORDER BY IF(cmp_id = '$escCmp', 0, 1) ASC, idx ASC");
        $entRows = [];
        $seenIdx = [];
        if ($qIdx) {
            while ($rIdx = $qIdx->fetch_assoc()) {
                $idxNum = intval($rIdx['idx']);
                if (isset($seenIdx[$idxNum])) continue;
                $seenIdx[$idxNum] = true;
                $entRows[] = $rIdx;
            }
        }

        if (empty($entRows)) {
            if ($cleanBc === '30') {
                $entRows = [
                    ['kode_entitas' => '2', 'idx' => 1],
                    ['kode_entitas' => '7', 'idx' => 2],
                    ['kode_entitas' => '8', 'idx' => 3],
                    ['kode_entitas' => '6', 'idx' => 4],
                    ['kode_entitas' => '4', 'idx' => 5],
                    ['kode_entitas' => '23', 'idx' => 6]
                ];
            } else {
                $entRows = [
                    ['kode_entitas' => '3', 'idx' => 1],
                    ['kode_entitas' => '7', 'idx' => 2],
                    ['kode_entitas' => '9', 'idx' => 3]
                ];
            }
        }

        $seriEnt = 1;
        foreach ($entRows as $er) {
            $kd = $er['kode_entitas'];
            $isOwn = in_array($kd, ['3', '7', '2']);
            $nomorId = $isOwn ? $npwp : '';
            $namaEnt = $isOwn ? $nama : '';
            $almtEnt = $isOwn ? $alamat : '';
            $nibEnt  = $isOwn ? $nib : '';
            $jenisId = '5';
            $kdNeg   = $isOwn ? 'ID' : '';

            $escNomorId = $koneksi->real_escape_string($nomorId);
            $escNamaEnt = $koneksi->real_escape_string($namaEnt);
            $escAlmtEnt = $koneksi->real_escape_string($almtEnt);
            $escNibEnt  = $koneksi->real_escape_string($nibEnt);
            $escKdNeg   = $koneksi->real_escape_string($kdNeg);

            $koneksi->query("INSERT INTO bc_ceisa_entitas (
                cmp_id, nomor_aju, seri, kode_entitas, kode_jenis_identitas,
                nomor_identitas, nama_entitas, alamat_entitas, nib_entitas, kode_negara
            ) VALUES (
                '$escCmp', '$escAju', $seriEnt, '$kd', '$jenisId',
                '$escNomorId', '$escNamaEnt', '$escAlmtEnt', '$escNibEnt', '$escKdNeg'
            )");
            $seriEnt++;
        }

        // 5. Insert Dokumen Default berdasarkan Urutan Konfigurasi Dokumen BC (bc_ceisa_docidx)
        $qDocIdx = $koneksi->query("SELECT kode_dokumen, idx FROM bc_ceisa_docidx 
                                    WHERE bc = '$escBc' AND (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)
                                    ORDER BY IF(cmp_id = '$escCmp', 0, 1) ASC, idx ASC");
        $docRows = [];
        $seenDocIdx = [];
        if ($qDocIdx) {
            while ($rDocIdx = $qDocIdx->fetch_assoc()) {
                $idxNum = intval($rDocIdx['idx']);
                if (isset($seenDocIdx[$idxNum])) continue;
                $seenDocIdx[$idxNum] = true;
                $docRows[] = $rDocIdx;
            }
        }
        if (empty($docRows)) {
            if ($cleanBc === '40') {
                $docRows = [
                    ['kode_dokumen' => '630', 'idx' => 1],
                    ['kode_dokumen' => '388', 'idx' => 2],
                    ['kode_dokumen' => '380', 'idx' => 3],
                    ['kode_dokumen' => '217', 'idx' => 4],
                    ['kode_dokumen' => '315', 'idx' => 5]
                ];
            } else {
                $docRows = [
                    ['kode_dokumen' => '380', 'idx' => 1],
                    ['kode_dokumen' => '217', 'idx' => 2]
                ];
            }
        }
        $seriDoc = 1;
        $todayDate = date('Y-m-d');
        foreach ($docRows as $dr) {
            $kdDoc = $koneksi->real_escape_string($dr['kode_dokumen']);
            $koneksi->query("INSERT INTO bc_ceisa_dokumen (cmp_id, nomor_aju, seri, kode_dokumen, nomor_dokumen, tanggal_dokumen) 
                             VALUES ('$escCmp', '$escAju', $seriDoc, '$kdDoc', '', '$todayDate')");
            $seriDoc++;
        }

        if ($cleanBc === '30') {
            $koneksi->query("INSERT INTO bc_ceisa_kontainer (cmp_id, nomor_aju, seri, kode_tipe_kontainer, kode_ukuran_kontainer) VALUES ('$escCmp', '$escAju', 1, '1', '20')");

            $qBank = $koneksi->query("SELECT kode, nama FROM bc_ceisa_bankdevisa WHERE kode IS NOT NULL AND kode <> '' ORDER BY seri DESC LIMIT 1");
            $kodeBank = 'BCA';
            $namaBank = 'BANK CENTRAL ASIA';
            if ($qBank && $rBank = $qBank->fetch_assoc()) {
                $kodeBank = $rBank['kode'];
                $namaBank = $rBank['nama'];
            }
            $escKdBk = $koneksi->real_escape_string($kodeBank);
            $escNmBk = $koneksi->real_escape_string($namaBank);
            $koneksi->query("INSERT INTO bc_ceisa_bankdevisa (cmp_id, nomor_aju, seri, kode, nama) VALUES ('$escCmp', '$escAju', 1, '$escKdBk', '$escNmBk')");
        }

        $koneksi->commit();
        echo json_encode([
            'status'       => 'OK',
            'nomor_aju'    => $nomorAju,
            'kode_dokumen' => $cleanBc,
            'message'      => "Dokumen AJU Baru berhasil dibuat: $nomorAju"
        ]);
    } catch (\Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => $e->getMessage()]);
    }
    exit;
}

// 5. Informasi Driver ERP untuk Perusahaan Aktif
if ($action === 'erp_info') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpId = trim($_GET['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ''));
    $escCmp = $koneksi->real_escape_string($cmpId);
    $qCmp = $koneksi->query("SELECT cmp_id, cmp_name, ERP_Name, ERP_User, ERP_Constring FROM bc_ceisa_company WHERE cmp_id = '$escCmp' LIMIT 1");
    $comp = $qCmp ? $qCmp->fetch_assoc() : [];

    $configuredErp = trim($comp['ERP_Name'] ?? '-');
    $hasErp = (!empty($configuredErp) && $configuredErp !== '-' && strtoupper($configuredErp) !== 'NONE');
    $activeDriver  = $hasErp ? ErpManager::getDriver($koneksi, $cmpId) : null;

    echo json_encode([
        'status'         => 'OK',
        'has_erp'        => $hasErp,
        'company'        => $comp,
        'configured_erp' => $configuredErp,
        'active_driver'  => $activeDriver ? [
            'name'        => $activeDriver->getName(),
            'title'       => $activeDriver->getTitle(),
            'description' => $activeDriver->getDescription()
        ] : null,
        'all_drivers'    => ErpManager::listDrivers()
    ]);
    exit;
}

// 6. Cari Data Transaksi di ERP
if ($action === 'erp_search') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpId       = trim($_GET['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ''));
    $driver      = ErpManager::getDriver($koneksi, $cmpId);

    if (!$driver) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Sistem ERP belum dikonfigurasi untuk perusahaan ini.']);
        exit;
    }

    $filters = [
        'doc_type'     => trim($_GET['doc_type'] ?? 'outbound'),
        'search_q'     => trim($_GET['search_q'] ?? ''),
        'date_from'    => trim($_GET['date_from'] ?? date('Y-m-01')),
        'date_to'      => trim($_GET['date_to'] ?? date('Y-m-d')),
        'kode_dokumen' => trim($_GET['kode_dokumen'] ?? '')
    ];

    $results = $driver->search($filters);
    echo json_encode([
        'status'  => 'OK',
        'driver'  => $driver->getName(),
        'results' => $results
    ]);
    exit;
}

// 7. Preview Rincian Dokumen ERP
if ($action === 'erp_preview') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpId       = trim($_GET['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ''));
    $docId       = trim($_GET['doc_id'] ?? '');
    $kodeBc      = trim($_GET['kode_dokumen'] ?? '');

    if (empty($docId)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Parameter doc_id diperlukan.']);
        exit;
    }

    $driver = ErpManager::getDriver($koneksi, $cmpId);
    if (!$driver) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Sistem ERP belum dikonfigurasi untuk perusahaan ini.']);
        exit;
    }

    $preview = $driver->getPreview($docId, $kodeBc);

    echo json_encode([
        'status'  => 'OK',
        'driver'  => $driver->getName(),
        'preview' => $preview
    ]);
    exit;
}

// 8. Eksekusi Integrasi ERP ke Staging CEISA 4.0
if ($action === 'erp_integrate') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpId       = trim($_POST['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ''));
    $nomorAju    = trim($_POST['nomor_aju'] ?? '');
    $docId       = trim($_POST['doc_id'] ?? '');
    $kodeBc      = trim($_POST['kode_dokumen'] ?? '');

    if (empty($nomorAju) || empty($docId)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU dan ID Dokumen ERP harus diisi.']);
        exit;
    }

    $chkIt = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE nomor_aju = '" . $koneksi->real_escape_string($nomorAju) . "'")->fetch_row()[0] ?? 0;
    if ($chkIt > 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Dokumen AJU $nomorAju sudah MASUK IT Inventory! Dokumen terkunci."]);
        exit;
    }

    $driver = ErpManager::getDriver($koneksi, $cmpId);
    if (!$driver) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Sistem ERP belum dikonfigurasi untuk perusahaan ini.']);
        exit;
    }

    $res = $driver->integrate($nomorAju, $docId, $kodeBc);

    echo json_encode($res);
    exit;
}

if ($action === 'load_all') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_REQUEST['nomor_aju'] ?? ($_GET['nomor_aju'] ?? ''));
    $requestedBc = trim($_REQUEST['kode_dokumen'] ?? ($_GET['kode_dokumen'] ?? '23'));

    $response = [
        'status' => 'OK',
        'nomor_aju' => $nomor_aju,
        'tables' => []
    ];

    $tables = [
        'header'           => "SELECT * FROM bc_ceisa_header WHERE nomor_aju = %s LIMIT 1",
        'barang'           => "SELECT * FROM bc_ceisa_barang WHERE nomor_aju = %s ORDER BY seri_barang ASC",
        'entitas'          => "SELECT * FROM bc_ceisa_entitas WHERE nomor_aju = %s ORDER BY seri ASC",
        'dokumen'          => "SELECT * FROM bc_ceisa_dokumen WHERE nomor_aju = %s ORDER BY seri ASC",
        'bahanbaku'        => "SELECT * FROM bc_ceisa_bahanbaku WHERE nomor_aju = %s ORDER BY seri_barang ASC, seri_bahan_baku ASC",
        'barangtarif'      => "SELECT * FROM bc_ceisa_barangtarif WHERE nomor_aju = %s ORDER BY seri_barang ASC, kode_pungutan ASC",
        'bahanbakutarif'   => "SELECT * FROM bc_ceisa_bahanbakutarif WHERE nomor_aju = %s ORDER BY seri_barang ASC, seri_bahan_baku ASC, kode_pungutan ASC",
        'kemasan'          => "SELECT * FROM bc_ceisa_kemasan WHERE nomor_aju = %s ORDER BY seri ASC",
        'pengangkut'       => "SELECT * FROM bc_ceisa_pengangkut WHERE nomor_aju = %s ORDER BY seri ASC",
        'kontainer'        => "SELECT * FROM bc_ceisa_kontainer WHERE nomor_aju = %s ORDER BY seri ASC",
        'pungutan'         => "SELECT * FROM bc_ceisa_pungutan WHERE nomor_aju = %s ORDER BY kode_jenis_pungutan ASC",
        'bankdevisa'       => "SELECT * FROM bc_ceisa_bankdevisa WHERE nomor_aju = %s ORDER BY seri ASC",
        'jaminan'          => "SELECT * FROM bc_ceisa_jaminan WHERE nomor_aju = %s ORDER BY nomor_jaminan ASC",
        'komponenbiaya'    => "SELECT * FROM bc_ceisa_komponenbiaya WHERE nomor_aju = %s",
        'barangdokumen'    => "SELECT * FROM bc_ceisa_barangdokumen WHERE nomor_aju = %s ORDER BY seri_barang ASC, seri_dokumen ASC",
        'barangentitas'    => "SELECT * FROM bc_ceisa_barangentitas WHERE nomor_aju = %s ORDER BY seri_barang ASC, seri_entitas ASC",
        'barangspekkhusus' => "SELECT * FROM bc_ceisa_barangspekkhusus WHERE nomor_aju = %s ORDER BY seri_barang ASC",
        'barangvd'         => "SELECT * FROM bc_ceisa_barangvd WHERE nomor_aju = %s ORDER BY seri_barang ASC",
        'bahanbakudokumen' => "SELECT * FROM bc_ceisa_bahanbakudokumen WHERE nomor_aju = %s ORDER BY seri_barang ASC, seri_bahan_baku ASC, seri_dokumen ASC",
        'kesiapanbarang'   => "SELECT * FROM bc_ceisa_kesiapanbarang WHERE nomor_aju = %s LIMIT 1",
        'barangbom'        => "SELECT id, cmp_id, aju, seri, kode_barang_parent, kode_barang, uraian, kode_satuan, qty, harga_penyerahan, cif FROM bc_ceisa_barangbom WHERE aju = %s ORDER BY seri ASC, id ASC",
        'tracebb'          => "SELECT id, cmp_id, id_bom, aju, nomor_aju, seri, seri_barang, kode_brg AS kode_barang, kode_brg, nama_brg AS nama_barang, nama_brg AS uraian, nama_brg, qty_use, sat AS satuan, sat, jenis_bc, no_daftar, tgl_daftar, hs, harga, cif, ndpbm, gross, netto FROM bc_ceisa_tracebb WHERE aju = %s OR nomor_aju = %s ORDER BY seri_barang ASC, id ASC"
    ];

    if (empty($nomor_aju)) {
        foreach (array_keys($tables) as $key) {
            $response['tables'][$key] = ($key === 'header' || $key === 'kesiapanbarang') ? [] : [];
        }
        $response['tables']['header'] = [
            'kode_dokumen' => $requestedBc
        ];
    } else {
        $escaped = "'" . $koneksi->real_escape_string($nomor_aju) . "'";
        foreach ($tables as $key => $sqlTemplate) {
            $cnt = substr_count($sqlTemplate, '%s');
            $args = array_fill(0, $cnt, $escaped);
            $sql = vsprintf($sqlTemplate, $args);
            try {
                $res = $koneksi->query($sql);
            } catch (Throwable $e) {
                error_log("Error loading table $key for aju $nomor_aju: " . $e->getMessage());
                $res = false;
            }
            if ($res) {
                if ($key === 'header' || $key === 'kesiapanbarang') {
                    $row = $res->fetch_assoc() ?: [];
                    foreach ($row as $k => &$v) {
                        if ($v === '0000-00-00' || $v === '0000-00-00 00:00:00' || $v === 'null') {
                            $v = '';
                        }
                    }
                    unset($v);
                    $response['tables'][$key] = $row;
                } else {
                    $rows = [];
                    while ($r = $res->fetch_assoc()) {
                        foreach ($r as $k => &$v) {
                            if ($v === '0000-00-00' || $v === '0000-00-00 00:00:00' || $v === 'null') {
                                $v = '';
                            }
                        }
                        unset($v);
                        $rows[] = $r;
                    }
                    $response['tables'][$key] = $rows;
                }
            } else {
                $response['tables'][$key] = ($key === 'header' || $key === 'kesiapanbarang') ? [] : [];
            }
        }
    }

    // Ambil aturan visibilitas kolom & referensi dropdown dari bc_ceisa_varheader
    $kode_dokumen = trim($response['tables']['header']['kode_dokumen'] ?? ($requestedBc ?: '23'));
    $cleanBc = preg_replace('/[^0-9]/', '', $kode_dokumen);
    $activeCmp = trim($response['tables']['header']['cmp_id'] ?? ($_REQUEST['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? '')));
    $escCmp = $koneksi->real_escape_string($activeCmp);
    $escBc = $koneksi->real_escape_string($cleanBc);

    $colFlag = 'v' . $cleanBc;
    $chkCol = $koneksi->query("SHOW COLUMNS FROM bc_ceisa_varheader LIKE '$colFlag'");
    if (!$chkCol || $chkCol->num_rows == 0) {
        $colFlag = 'v23';
    }

    // 1. Cek override kolom: Company dahulu, jika tidak ada fallback ke Global
    $colOverrides = [];
    $colSource = 'varheader';
    if (!empty($activeCmp)) {
        $qCmpCol = $koneksi->query("SELECT tabel, col_id, is_visible FROM bc_ceisa_setting_kolom WHERE cmp_id = '$escCmp' AND bc = '$escBc'");
        if ($qCmpCol && $qCmpCol->num_rows > 0) {
            $colSource = 'company';
            while ($cr = $qCmpCol->fetch_assoc()) {
                $colOverrides[$cr['tabel']][$cr['col_id']] = (int)$cr['is_visible'];
            }
        }
    }

    if (empty($colOverrides)) {
        $qGlobCol = $koneksi->query("SELECT tabel, col_id, is_visible FROM bc_ceisa_setting_kolom WHERE cmp_id = '' AND bc = '$escBc'");
        if ($qGlobCol && $qGlobCol->num_rows > 0) {
            $colSource = 'global';
            while ($cr = $qGlobCol->fetch_assoc()) {
                $colOverrides[$cr['tabel']][$cr['col_id']] = (int)$cr['is_visible'];
            }
        }
    }

    $vhRes = $koneksi->query("SELECT tabel, col_id, col_txt, reff, `$colFlag` AS is_visible, isnum, isdate FROM bc_ceisa_varheader WHERE tabel IN ('header', 'barang') ORDER BY idx ASC");
    $varRules = ['header' => [], 'barang' => []];
    $neededReffs = [];
    if ($vhRes) {
        while ($vr = $vhRes->fetch_assoc()) {
            $t = $vr['tabel'];
            $colId = $vr['col_id'];
            $vis = isset($colOverrides[$t][$colId]) ? $colOverrides[$t][$colId] : (int)($vr['is_visible'] ?? 0);
            $varRules[$t][$colId] = [
                'txt' => $vr['col_txt'],
                'visible' => $vis,
                'reff' => !empty($vr['reff']) && $vr['reff'] !== '-' ? $vr['reff'] : null,
                'isnum' => $vr['isnum'],
                'isdate' => $vr['isdate']
            ];
            if (!empty($vr['reff']) && $vr['reff'] !== '-') {
                $neededReffs[$vr['reff']] = true;
            }
        }
    }

    // 2. Cek aturan visibilitas TAB: Company dahulu, jika tidak ada fallback ke Global, jika tidak ada fallback ke default
    $tabRulesRes = null;
    $tabSource = 'default';
    if (!empty($activeCmp)) {
        $qCmpTab = $koneksi->query("SELECT tab_key, is_visible FROM bc_ceisa_setting_tab WHERE cmp_id = '$escCmp' AND bc = '$escBc'");
        if ($qCmpTab && $qCmpTab->num_rows > 0) {
            $tabSource = 'company';
            $tabRulesRes = [];
            while ($tr = $qCmpTab->fetch_assoc()) {
                if ((int)$tr['is_visible'] === 1) {
                    $tabRulesRes[] = $tr['tab_key'];
                }
            }
        }
    }

    if ($tabRulesRes === null) {
        $qGlobTab = $koneksi->query("SELECT tab_key, is_visible FROM bc_ceisa_setting_tab WHERE cmp_id = '' AND bc = '$escBc'");
        if ($qGlobTab && $qGlobTab->num_rows > 0) {
            $tabSource = 'global';
            $tabRulesRes = [];
            while ($tr = $qGlobTab->fetch_assoc()) {
                if ((int)$tr['is_visible'] === 1) {
                    $tabRulesRes[] = $tr['tab_key'];
                }
            }
        }
    }

    // Pastikan referensi inti untuk tabel child dan form selalu dimuat
    $neededReffs['ENTITAS'] = true;
    $neededReffs['DOKUMEN'] = true;
    $neededReffs['CARA ANGKUT'] = true;
    $neededReffs['NEGARA'] = true;
    $neededReffs['JENIS IDENTITAS'] = true;
    $neededReffs['JENIS KEMASAN'] = true;
    $neededReffs['UKURAN KONTAINER'] = true;
    $neededReffs['JENIS KONTAINER'] = true;
    $neededReffs['TIPE KONTAINER'] = true;
    $neededReffs['BANK'] = true;
    $neededReffs['JENIS JAMINAN'] = true;
    $neededReffs['JENIS PUNGUTAN'] = true;
    $neededReffs['FASILITAS TARIF'] = true;
    $neededReffs['JENIS TARIF'] = true;
    $neededReffs['FASILITAS'] = true;
    $neededReffs['ASAL BARANG'] = true;
    $neededReffs['KONDISI BARANG'] = true;
    $neededReffs['JENIS GUDANG'] = true;
    $neededReffs['JENIS BARANG EKSPOR'] = true;
    $neededReffs['JENIS PARTOF'] = true;
    $neededReffs['CARA STUFFING'] = true;
    $neededReffs['ASAL BAHAN BAKU'] = true;
    $neededReffs['INCOTERM'] = true;

    // Ambil opsi dari bc_ceisa_reff untuk seluruh reff yang dibutuhkan
    $reffOptions = [];
    if (!empty($neededReffs)) {
        $otherReffs = [];
        $needPelabuhan = false;
        foreach (array_keys($neededReffs) as $rf) {
            if ($rf === 'PELABUHAN') {
                $needPelabuhan = true;
            } else {
                $otherReffs[] = $rf;
            }
        }

        if (!empty($otherReffs)) {
            $escReffs = "'" . implode("', '", array_map([$koneksi, 'real_escape_string'], $otherReffs)) . "'";
            $rRes = $koneksi->query("SELECT reff, kode, teks FROM bc_ceisa_reff WHERE reff IN ($escReffs) AND (status IS NULL OR status <> 'N') ORDER BY reff ASC, (kode + 0) ASC, kode ASC");
            if ($rRes) {
                while ($rr = $rRes->fetch_assoc()) {
                    $reffOptions[$rr['reff']][] = [
                        'kode' => $rr['kode'],
                        'teks' => $rr['teks']
                    ];
                }
            }
        }

        // Khusus PELABUHAN: muat pelabuhan Indonesia (ID) + pelabuhan yang sedang digunakan di header saat ini
        if ($needPelabuhan) {
            $hdr = $response['tables']['header'] ?? [];
            $usedPorts = array_filter([
                $hdr['kode_pelabuhan_muat'] ?? '',
                $hdr['kode_pelabuhan_bongkar'] ?? '',
                $hdr['kode_pelabuhan_transit'] ?? '',
                $hdr['kode_pelabuhan_tujuan'] ?? '',
                $hdr['kode_pelabuhan_ekspor'] ?? ''
            ]);
            $wherePort = "kode LIKE 'ID%'";
            if (!empty($usedPorts)) {
                $escPorts = "'" . implode("', '", array_map([$koneksi, 'real_escape_string'], $usedPorts)) . "'";
                $wherePort = "(kode LIKE 'ID%' OR kode IN ($escPorts))";
            }
            $pRes = $koneksi->query("SELECT kode, teks FROM bc_ceisa_reff WHERE reff = 'PELABUHAN' AND $wherePort ORDER BY kode ASC");
            if ($pRes) {
                while ($pr = $pRes->fetch_assoc()) {
                    $reffOptions['PELABUHAN'][] = [
                        'kode' => $pr['kode'],
                        'teks' => $pr['teks']
                    ];
                }
            }
        }
    }

    // Cek status IT Inventory (bc_1ab2)
    if (!empty($nomor_aju)) {
        $chkIt = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE nomor_aju = $escaped")->fetch_row()[0] ?? 0;
        $response['is_it_inventory'] = ($chkIt > 0);
    } else {
        $response['is_it_inventory'] = false;
    }

    $response['varheader'] = $varRules;
    $response['reff_options'] = $reffOptions;
    $response['kode_dokumen'] = $cleanBc;
    $response['tab_rules'] = $tabRulesRes;
    $response['tab_source'] = $tabSource;
    $response['col_source'] = $colSource;
    $response['active_cmp_id'] = $activeCmp;

    // 3. Resolusi Aturan FORMAT DESIMAL: Company -> Global -> Bawaan
    $defaultDecimals = [
        'qty' => 2,
        'kemasan' => 0,
        'uang' => 2,
        'berat' => 4,
        'harga_satuan' => 4,
        'tarif' => 2
    ];
    $activeDecimals = $defaultDecimals;
    $decimalSource = 'default';

    // Ambil Global
    $qGlobDec = $koneksi->query("SELECT category, decimals FROM bc_ceisa_setting_desimal WHERE cmp_id = ''");
    if ($qGlobDec && $qGlobDec->num_rows > 0) {
        $decimalSource = 'global';
        while ($dr = $qGlobDec->fetch_assoc()) {
            $activeDecimals[$dr['category']] = (int)$dr['decimals'];
        }
    }

    // Ambil Company jika ada
    if (!empty($activeCmp)) {
        $qCmpDec = $koneksi->query("SELECT category, decimals FROM bc_ceisa_setting_desimal WHERE cmp_id = '$escCmp'");
        if ($qCmpDec && $qCmpDec->num_rows > 0) {
            $decimalSource = 'company';
            while ($dr = $qCmpDec->fetch_assoc()) {
                $activeDecimals[$dr['category']] = (int)$dr['decimals'];
            }
        }
    }

    $response['decimal_settings'] = $activeDecimals;
    $response['decimal_source'] = $decimalSource;

    echo json_encode($response);
    exit;
}

// Hapus Data AJU (Tabel Staging)
if ($action === 'delete_aju') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_POST['nomor_aju'] ?? '');
    $confirm   = strtoupper(trim($_POST['confirm'] ?? ''));

    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak boleh kosong.']);
        exit;
    }

    if ($confirm !== 'YA') {
        echo json_encode(['status' => 'ERROR', 'message' => 'Konfirmasi gagal. Wajib mengetik "YA" untuk menghapus data AJU.']);
        exit;
    }

    $escAju = $koneksi->real_escape_string($nomor_aju);

    // Cek apakah dokumen ada
    $chkHdr = $koneksi->query("SELECT nomor_aju, cmp_id, kode_dokumen FROM bc_ceisa_header WHERE nomor_aju = '$escAju' LIMIT 1");
    $hasHdr = ($chkHdr && $chkHdr->num_rows > 0);
    $chkData = $koneksi->query("SELECT nomorAju, cmp_id FROM bc_ceisa_data WHERE nomorAju = '$escAju' LIMIT 1");
    $hasData = ($chkData && $chkData->num_rows > 0);

    if (!$hasHdr && !$hasData) {
        echo json_encode(['status' => 'ERROR', 'message' => "Dokumen AJU $nomor_aju tidak ditemukan di database."]);
        exit;
    }

    // Cek apakah dokumen sudah terkunci / masuk IT Inventory (bc_1ab2)
    $chkIt = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE nomor_aju = '$escAju'");
    if ($chkIt && ($rIt = $chkIt->fetch_row()) && (int)$rIt[0] > 0) {
        echo json_encode([
            'status' => 'ERROR',
            'message' => "Dokumen AJU $nomor_aju tidak dapat dihapus karena sudah dikunci (masuk ke IT Inventory). Silakan lakukan Unposting IT Inventory terlebih dahulu."
        ]);
        exit;
    }

    $stagingTables = [
        'bc_ceisa_header', 'bc_ceisa_barang', 'bc_ceisa_barangtarif', 'bc_ceisa_barangdokumen',
        'bc_ceisa_barangentitas', 'bc_ceisa_barangspekkhusus', 'bc_ceisa_barangvd', 'bc_ceisa_barangbom',
        'bc_ceisa_bahanbaku', 'bc_ceisa_bahanbakudokumen', 'bc_ceisa_bahanbakutarif', 'bc_ceisa_dokumen',
        'bc_ceisa_entitas', 'bc_ceisa_kemasan', 'bc_ceisa_pengangkut', 'bc_ceisa_kontainer',
        'bc_ceisa_bankdevisa', 'bc_ceisa_pungutan', 'bc_ceisa_jaminan', 'bc_ceisa_komponenbiaya',
        'bc_ceisa_kesiapanbarang', 'bc_ceisa_aju_posted', 'bc_ceisa_status', 'bc_ceisa_respons',
        'bc_ceisa_respon_logs', 'bc_ceisa_json', 'bc_ceisa_data_compareit', 'bc_ceisa_req_unpost',
        'bc_ceisa_docidx', 'bc_ceisa_entidx', 'bc_ceisa_scan27', 'bc_si_aju_ceisa'
    ];

    $deletedCounts = [];
    foreach ($stagingTables as $tb) {
        $chkTable = $koneksi->query("SHOW TABLES LIKE '$tb'");
        if ($chkTable && $chkTable->num_rows > 0) {
            $chkCol = $koneksi->query("SHOW COLUMNS FROM `$tb` LIKE 'nomor_aju'");
            if ($chkCol && $chkCol->num_rows > 0) {
                $koneksi->query("DELETE FROM `$tb` WHERE `nomor_aju` = '$escAju'");
                if ($koneksi->affected_rows > 0) {
                    $deletedCounts[$tb] = $koneksi->affected_rows;
                }
            }
        }
    }

    // Hapus dari bc_ceisa_data jika ada
    $chkDataTb = $koneksi->query("SHOW TABLES LIKE 'bc_ceisa_data'");
    if ($chkDataTb && $chkDataTb->num_rows > 0) {
        $koneksi->query("DELETE FROM `bc_ceisa_data` WHERE `nomorAju` = '$escAju'");
        if ($koneksi->affected_rows > 0) {
            $deletedCounts['bc_ceisa_data'] = $koneksi->affected_rows;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'message' => "Dokumen AJU $nomor_aju beserta seluruh data staging berhasil dihapus.",
        'deleted_details' => $deletedCounts
    ]);
    exit;
}

if ($action === 'search_reff') {
    header('Content-Type: application/json; charset=utf-8');
    $reff = trim($_GET['reff'] ?? '');
    $q    = trim($_GET['q'] ?? '');

    if (empty($reff)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Parameter reff diperlukan.']);
        exit;
    }

    $escReff = $koneksi->real_escape_string($reff);
    $escQ    = $koneksi->real_escape_string($q);
    $whereQ  = !empty($escQ) ? "AND (kode LIKE '%$escQ%' OR teks LIKE '%$escQ%')" : "";

    $res = $koneksi->query("SELECT kode, teks FROM bc_ceisa_reff WHERE reff = '$escReff' $whereQ AND (status IS NULL OR status <> 'N') ORDER BY kode ASC LIMIT 50");
    $items = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $items[] = $r;
        }
    }
    echo json_encode(['status' => 'OK', 'reff' => $reff, 'data' => $items]);
    exit;
}

if ($action === 'save_header') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_POST['nomor_aju'] ?? '');
    $data = $_POST['data'] ?? [];

    if (empty($nomor_aju) || empty($data)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Data header tidak lengkap.']);
        exit;
    }

    // Kunci IT Inventory: Cegah update jika dokumen sudah masuk IT Inventory
    $chkIt = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE nomor_aju = '" . $koneksi->real_escape_string($nomor_aju) . "'")->fetch_row()[0] ?? 0;
    if ($chkIt > 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Dokumen AJU $nomor_aju sudah MASUK IT Inventory! Dokumen terkunci dan tidak dapat diedit atau disimpan."]);
        exit;
    }

    // Ambil kolom yang valid di bc_ceisa_header
    $cRes = $koneksi->query("DESCRIBE bc_ceisa_header");
    $validCols = [];
    while ($cr = $cRes->fetch_assoc()) {
        $validCols[$cr['Field']] = true;
    }

    $setPairs = [];
    foreach ($data as $k => $v) {
        if ($k === 'nomor_aju' || !isset($validCols[$k])) continue;
        if ($v === '' || $v === null) {
            $setPairs[] = "`$k` = NULL";
        } else {
            if (is_string($v) && preg_match('/^-?[\d,]+(?:\.\d+)?$/', trim($v)) && strpos($v, ',') !== false) {
                $v = str_replace(',', '', trim($v));
            }
            $valEsc = $koneksi->real_escape_string($v);
            $setPairs[] = "`$k` = '$valEsc'";
        }
    }

    if (empty($setPairs)) {
        echo json_encode(['status' => 'OK', 'message' => 'Tidak ada kolom yang diubah.']);
        exit;
    }

    $escAju = $koneksi->real_escape_string($nomor_aju);
    $updateSql = "UPDATE bc_ceisa_header SET " . implode(', ', $setPairs) . " WHERE nomor_aju = '$escAju'";
    if ($koneksi->query($updateSql)) {
        echo json_encode(['status' => 'OK', 'message' => 'Header berhasil diperbarui!']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal update header: ' . $koneksi->error]);
    }
    exit;
}

if ($action === 'save_table') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_POST['nomor_aju'] ?? '');
    $tableKey  = trim($_POST['table'] ?? '');
    $rows      = json_decode($_POST['rows'] ?? '[]', true);

    $tableMap = [
        'barang'           => 'bc_ceisa_barang',
        'entitas'          => 'bc_ceisa_entitas',
        'dokumen'          => 'bc_ceisa_dokumen',
        'bahanbaku'        => 'bc_ceisa_bahanbaku',
        'barangtarif'      => 'bc_ceisa_barangtarif',
        'bahanbakutarif'   => 'bc_ceisa_bahanbakutarif',
        'kemasan'          => 'bc_ceisa_kemasan',
        'pengangkut'       => 'bc_ceisa_pengangkut',
        'kontainer'        => 'bc_ceisa_kontainer',
        'pungutan'         => 'bc_ceisa_pungutan',
        'bankdevisa'       => 'bc_ceisa_bankdevisa',
        'jaminan'          => 'bc_ceisa_jaminan',
        'komponenbiaya'    => 'bc_ceisa_komponenbiaya',
        'barangdokumen'    => 'bc_ceisa_barangdokumen',
        'barangentitas'    => 'bc_ceisa_barangentitas',
        'barangspekkhusus' => 'bc_ceisa_barangspekkhusus',
        'barangvd'         => 'bc_ceisa_barangvd',
        'bahanbakudokumen' => 'bc_ceisa_bahanbakudokumen',
        'barangbom'        => 'bc_ceisa_barangbom',
        'tracebb'          => 'bc_ceisa_tracebb'
    ];

    if (!isset($tableMap[$tableKey])) {
        echo json_encode(['status' => 'ERROR', 'message' => "Tabel '$tableKey' tidak valid untuk disimpan."]);
        exit;
    }

    // Kunci IT Inventory: Cegah update tabel jika dokumen sudah masuk IT Inventory
    $chkIt = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE nomor_aju = '" . $koneksi->real_escape_string($nomor_aju) . "'")->fetch_row()[0] ?? 0;
    if ($chkIt > 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Dokumen AJU $nomor_aju sudah MASUK IT Inventory! Dokumen terkunci dan tidak dapat diedit atau disimpan."]);
        exit;
    }

    $targetTable = $tableMap[$tableKey];

    // Ambil cmp_id dari header
    $cmp_id = '';
    $hRes = $koneksi->query("SELECT cmp_id FROM bc_ceisa_header WHERE nomor_aju = '" . $koneksi->real_escape_string($nomor_aju) . "' LIMIT 1");
    if ($hRes && $hr = $hRes->fetch_assoc()) {
        $cmp_id = $hr['cmp_id'];
    }

    // Ambil kolom yang valid di tabel target
    $cRes = $koneksi->query("DESCRIBE `$targetTable`");
    $validCols = [];
    while ($cr = $cRes->fetch_assoc()) {
        $validCols[$cr['Field']] = true;
    }

    $koneksi->begin_transaction();
    try {
        // Hapus baris lama untuk nomor_aju ini
        $escAju = $koneksi->real_escape_string($nomor_aju);
        if ($tableKey === 'barangbom') {
            $koneksi->query("DELETE FROM `bc_ceisa_barangbom` WHERE aju = '$escAju'");
        } elseif ($tableKey === 'tracebb') {
            $koneksi->query("DELETE FROM `bc_ceisa_tracebb` WHERE aju = '$escAju' OR nomor_aju = '$escAju'");
        } else {
            $koneksi->query("DELETE FROM `$targetTable` WHERE nomor_aju = '$escAju'");
        }

        // Insert baris-baris baru
        $insertedCount = 0;
        foreach ($rows as $row) {
            $filteredRow = [];
            foreach ($row as $k => $v) {
                if (isset($validCols[$k])) {
                    if ($v === '' || $v === null) {
                        $filteredRow[$k] = null;
                    } else {
                        // Bersihkan pemisah ribuan (koma) pada string angka sebelum disimpan ke MySQL
                        if (is_string($v) && preg_match('/^-?[\d,]+(?:\.\d+)?$/', trim($v)) && strpos($v, ',') !== false) {
                            $v = str_replace(',', '', trim($v));
                        }
                        // Normalisasi tanggal
                        if (is_string($v) && (strpos($k, 'tanggal') !== false || strpos($k, 'tgl') !== false || $k === 'jatuh_tempo')) {
                            require_once __DIR__ . '/CeisaJsonBuilder.php';
                            $v = CeisaJsonBuilder::normalizeDateStr($v);
                        }
                        $filteredRow[$k] = $v;
                    }
                }
            }

            // Pastikan nomor_aju dan cmp_id selalu konsisten
            if (isset($validCols['nomor_aju'])) {
                $filteredRow['nomor_aju'] = $nomor_aju;
            }
            if (isset($validCols['aju'])) {
                $filteredRow['aju'] = $nomor_aju;
            }
            if (isset($validCols['id']) && empty($filteredRow['id'])) {
                $filteredRow['id'] = md5(uniqid(rand(), true));
            }
            if (isset($validCols['cmp_id']) && !empty($cmp_id)) {
                $filteredRow['cmp_id'] = $cmp_id;
            }

            // Pemetaan alias untuk tracebb
            if ($tableKey === 'tracebb') {
                if (isset($validCols['kode_brg']) && empty($filteredRow['kode_brg']) && !empty($row['kode_barang'])) {
                    $filteredRow['kode_brg'] = $row['kode_barang'];
                }
                if (isset($validCols['nama_brg']) && empty($filteredRow['nama_brg']) && !empty($row['nama_barang'])) {
                    $filteredRow['nama_brg'] = $row['nama_barang'];
                }
                if (isset($validCols['sat']) && empty($filteredRow['sat']) && !empty($row['satuan'])) {
                    $filteredRow['sat'] = $row['satuan'];
                }
            }

            if (!empty($filteredRow)) {
                $cols = array_keys($filteredRow);
                $quotedCols = array_map(function($c) { return "`$c`"; }, $cols);
                $placeholders = array_fill(0, count($cols), '?');

                $sql = "INSERT INTO `$targetTable` (" . implode(', ', $quotedCols) . ") VALUES (" . implode(', ', $placeholders) . ")";
                $stmt = $koneksi->prepare($sql);
                if ($stmt) {
                    $vals = array_values($filteredRow);
                    $types = str_repeat('s', count($vals));
                    $stmt->bind_param($types, ...$vals);
                    $stmt->execute();
                    $stmt->close();
                    $insertedCount++;
                }
            }
        }

        $koneksi->commit();

        // Jika tabel barang disimpan, otomatis sinkronkan total CIF & bobot di header
        if ($tableKey === 'barang') {
            require_once __DIR__ . '/ceisa_calc_helper.php';
            ceisa_sync_header_totals($koneksi, $nomor_aju);
        }

        echo json_encode(['status' => 'OK', 'message' => "Tabel $tableKey berhasil disimpan ($insertedCount baris)."]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyimpan data: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'save_kesiapanbarang') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_POST['nomor_aju'] ?? '');
    $data = $_POST['data'] ?? [];

    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak valid.']);
        exit;
    }

    // Kunci IT Inventory: Cegah update kesiapan barang jika dokumen sudah masuk IT Inventory
    $chkIt = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE nomor_aju = '" . $koneksi->real_escape_string($nomor_aju) . "'")->fetch_row()[0] ?? 0;
    if ($chkIt > 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Dokumen AJU $nomor_aju sudah MASUK IT Inventory! Dokumen terkunci dan tidak dapat diedit atau disimpan."]);
        exit;
    }

    $cRes = $koneksi->query("DESCRIBE bc_ceisa_kesiapanbarang");
    $validCols = [];
    while ($cr = $cRes->fetch_assoc()) {
        $validCols[$cr['Field']] = true;
    }

    $cmp_id = '';
    $hRes = $koneksi->query("SELECT cmp_id FROM bc_ceisa_header WHERE nomor_aju = '" . $koneksi->real_escape_string($nomor_aju) . "' LIMIT 1");
    if ($hRes && $hr = $hRes->fetch_assoc()) {
        $cmp_id = $hr['cmp_id'];
    }

    $escAju = $koneksi->real_escape_string($nomor_aju);
    $koneksi->query("DELETE FROM bc_ceisa_kesiapanbarang WHERE nomor_aju = '$escAju'");

    $filteredRow = ['nomor_aju' => $nomor_aju];
    if (isset($validCols['cmp_id']) && !empty($cmp_id)) {
        $filteredRow['cmp_id'] = $cmp_id;
    }
    foreach ($data as $k => $v) {
        if ($k === 'nomor_aju' || !isset($validCols[$k])) continue;
        if ($v === '' || $v === null) {
            $filteredRow[$k] = null;
        } else {
            if (is_string($v) && (strpos($k, 'tanggal') !== false || strpos($k, 'waktu') !== false || strpos($k, 'tgl') !== false || $k === 'jatuh_tempo')) {
                require_once __DIR__ . '/CeisaJsonBuilder.php';
                $v = CeisaJsonBuilder::normalizeDateStr($v);
            }
            $filteredRow[$k] = $v;
        }
    }

    $cols = array_keys($filteredRow);
    $quotedCols = array_map(function($c) { return "`$c`"; }, $cols);
    $placeholders = array_fill(0, count($cols), '?');

    $sql = "INSERT INTO bc_ceisa_kesiapanbarang (" . implode(', ', $quotedCols) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $koneksi->prepare($sql);
    if ($stmt) {
        $vals = array_values($filteredRow);
        $types = str_repeat('s', count($vals));
        $stmt->bind_param($types, ...$vals);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'OK', 'message' => 'Kesiapan barang berhasil disimpan!']);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal simpan kesiapan barang: ' . $stmt->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Query error: ' . $koneksi->error]);
    }
    exit;
}

if ($action === 'calc_pungutan') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_REQUEST['nomor_aju'] ?? '');
    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak valid.']);
        exit;
    }

    $chkIt = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE nomor_aju = '" . $koneksi->real_escape_string($nomor_aju) . "'")->fetch_row()[0] ?? 0;
    if ($chkIt > 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Dokumen AJU $nomor_aju sudah MASUK IT Inventory! Dokumen terkunci."]);
        exit;
    }

    $escAju = $koneksi->real_escape_string($nomor_aju);
    $hRes = $koneksi->query("SELECT cmp_id, kode_dokumen FROM bc_ceisa_header WHERE nomor_aju = '$escAju' LIMIT 1");
    $cmp_id = '';
    $kode_dokumen = '';
    if ($hRes && $hr = $hRes->fetch_assoc()) {
        $cmp_id = $hr['cmp_id'];
        $kode_dokumen = trim($hr['kode_dokumen']);
    }

    $existingNpwp = '';
    $pCheck = $koneksi->query("SELECT npwp_billing FROM bc_ceisa_pungutan WHERE nomor_aju = '$escAju' AND npwp_billing IS NOT NULL AND npwp_billing != '' LIMIT 1");
    if ($pCheck && $pr = $pCheck->fetch_assoc()) {
        $existingNpwp = $pr['npwp_billing'];
    }

    // Ambil rekap dari bc_ceisa_bahanbakutarif
    $bbSummary = [];
    $bbSumTotal = 0;
    $resBb = $koneksi->query("SELECT kode_pungutan, kode_fasilitas, 
        SUM(COALESCE(nilai_bayar,0)) as total_bayar, 
        SUM(COALESCE(nilai_fasilitas,0)) as total_fasilitas, 
        SUM(COALESCE(nilai_sudah_dilunasi,0)) as total_lunas 
        FROM bc_ceisa_bahanbakutarif 
        WHERE nomor_aju = '$escAju' 
        GROUP BY kode_pungutan, kode_fasilitas");
    if ($resBb) {
        while ($r = $resBb->fetch_assoc()) {
            $tot = floatval($r['total_bayar']) + floatval($r['total_fasilitas']) + floatval($r['total_lunas']);
            if ($tot > 0) {
                $bbSummary[] = [
                    'kode_pungutan' => trim($r['kode_pungutan']),
                    'kode_fasilitas' => trim($r['kode_fasilitas']),
                    'total' => $tot
                ];
                $bbSumTotal += $tot;
            }
        }
    }

    // Ambil rekap dari bc_ceisa_barangtarif
    $btSummary = [];
    $btSumTotal = 0;
    $resBt = $koneksi->query("SELECT kode_pungutan, kode_fasilitas, 
        SUM(COALESCE(nilai_bayar,0)) as total_bayar, 
        SUM(COALESCE(nilai_fasilitas,0)) as total_fasilitas, 
        SUM(COALESCE(nilai_sudah_dilunasi,0)) as total_lunas 
        FROM bc_ceisa_barangtarif 
        WHERE nomor_aju = '$escAju' 
        GROUP BY kode_pungutan, kode_fasilitas");
    if ($resBt) {
        while ($r = $resBt->fetch_assoc()) {
            $tot = floatval($r['total_bayar']) + floatval($r['total_fasilitas']) + floatval($r['total_lunas']);
            if ($tot > 0) {
                $btSummary[] = [
                    'kode_pungutan' => trim($r['kode_pungutan']),
                    'kode_fasilitas' => trim($r['kode_fasilitas']),
                    'total' => $tot
                ];
                $btSumTotal += $tot;
            }
        }
    }

    // Tentukan sumber data
    $source = '';
    $finalSummary = [];
    if (($kode_dokumen === '25' || $kode_dokumen === '261' || $kode_dokumen === '262') && $bbSumTotal > 0) {
        $finalSummary = $bbSummary;
        $source = 'Bahan Baku Tarif';
    } elseif ($btSumTotal > 0) {
        $finalSummary = $btSummary;
        $source = 'Barang Tarif';
    } elseif ($bbSumTotal > 0) {
        $finalSummary = $bbSummary;
        $source = 'Bahan Baku Tarif';
    }

    if (empty($finalSummary)) {
        echo json_encode([
            'status' => 'WARNING', 
            'message' => 'Tidak ditemukan data tarif bernilai > 0 pada Barang Tarif maupun Bahan Baku Tarif. Silakan klik "Hitung Tarif" terlebih dahulu!'
        ]);
        exit;
    }

    // Nilai pungutan adalah akumulasi riil dari Barang Tarif / Bahan Baku Tarif (persis sesuai kalkulasi CEISA 4.0)
    $newPungutan = [];
    foreach ($finalSummary as $item) {
        $kp = strtoupper($item['kode_pungutan']);
        if ($kp === 'PPNLOKAL' && $item['total'] == 0) continue;

        $nilai = round($item['total'], 2);
        $newPungutan[] = [
            'cmp_id' => $cmp_id,
            'nomor_aju' => $nomor_aju,
            'kode_jenis_pungutan' => $item['kode_pungutan'],
            'kode_fasilitas_tarif' => $item['kode_fasilitas'],
            'nilai_pungutan' => $nilai,
            'npwp_billing' => $existingNpwp ?: null
        ];
    }

    $koneksi->begin_transaction();
    try {
        $koneksi->query("DELETE FROM bc_ceisa_pungutan WHERE nomor_aju = '$escAju'");
        $insStmt = $koneksi->prepare("INSERT INTO bc_ceisa_pungutan (cmp_id, nomor_aju, kode_jenis_pungutan, kode_fasilitas_tarif, nilai_pungutan, npwp_billing) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($newPungutan as $np) {
            $valStr = (string)$np['nilai_pungutan'];
            $insStmt->bind_param('ssssss', $np['cmp_id'], $np['nomor_aju'], $np['kode_jenis_pungutan'], $np['kode_fasilitas_tarif'], $valStr, $np['npwp_billing']);
            $insStmt->execute();
        }
        $insStmt->close();
        $koneksi->commit();

        echo json_encode([
            'status' => 'OK',
            'message' => "Kalkulasi pungutan sukses! Dihitung " . count($newPungutan) . " jenis pungutan dari $source (sesuai akumulasi tarif CEISA 4.0).",
            'source' => $source,
            'rows' => $newPungutan
        ]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyimpan kalkulasi pungutan: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'calc_tarif') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_REQUEST['nomor_aju'] ?? '');
    $target = trim($_REQUEST['target'] ?? 'all');

    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak valid.']);
        exit;
    }

    $chkIt = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE nomor_aju = '" . $koneksi->real_escape_string($nomor_aju) . "'")->fetch_row()[0] ?? 0;
    if ($chkIt > 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Dokumen AJU $nomor_aju sudah MASUK IT Inventory! Dokumen terkunci."]);
        exit;
    }

    $escAju = $koneksi->real_escape_string($nomor_aju);
    $hRes = $koneksi->query("SELECT cmp_id, kode_dokumen, ndpbm, cif, harga_penyerahan, nilai_barang FROM bc_ceisa_header WHERE nomor_aju = '$escAju' LIMIT 1");
    if (!$hRes || !($hdr = $hRes->fetch_assoc())) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Header dokumen tidak ditemukan.']);
        exit;
    }
    $cmp_id = $hdr['cmp_id'];
    $ndpbm = floatval($hdr['ndpbm'] ?? 1);
    if ($ndpbm <= 0) $ndpbm = 1;

    $updatedBarangTarif = 0;
    $updatedBahanBakuTarif = 0;

    // 1. HITUNG BARANG TARIF
    if ($target === 'barang' || $target === 'all') {
        $bRes = $koneksi->query("SELECT seri_barang, cif, cif_rupiah, harga_penyerahan, nilai_barang, hs FROM bc_ceisa_barang WHERE nomor_aju = '$escAju' ORDER BY seri_barang ASC");
        $barangList = [];
        while ($b = $bRes->fetch_assoc()) {
            $seri = intval($b['seri_barang']);
            $cifRupiah = floatval($b['cif_rupiah'] ?? 0);
            if ($cifRupiah <= 0) {
                $cif = floatval($b['cif'] ?? 0);
                if ($cif > 0) $cifRupiah = $cif * $ndpbm;
                elseif (floatval($b['harga_penyerahan'] ?? 0) > 0) $cifRupiah = floatval($b['harga_penyerahan']);
                elseif (floatval($b['nilai_barang'] ?? 0) > 0) $cifRupiah = floatval($b['nilai_barang']);
            }
            $barangList[$seri] = [
                'cif_rupiah' => $cifRupiah,
                'hs' => trim($b['hs'] ?? '')
            ];
        }

        $btRes = $koneksi->query("SELECT * FROM bc_ceisa_barangtarif WHERE nomor_aju = '$escAju' ORDER BY seri_barang ASC, kode_pungutan ASC");
        $btRows = [];
        while ($r = $btRes->fetch_assoc()) {
            $btRows[] = $r;
        }

        if (!empty($btRows)) {
            foreach ($btRows as $row) {
                $seri = intval($row['seri_barang']);
                $cifRupiah = $barangList[$seri]['cif_rupiah'] ?? 0;
                $kp = strtoupper(trim($row['kode_pungutan']));
                $tarifPct = floatval($row['tarif'] ?? 0);
                $fas = trim($row['kode_fasilitas'] ?? '1');

                $val = 0;
                if ($kp === 'BM') {
                    $val = round($cifRupiah * ($tarifPct / 100), 2);
                } else {
                    $bmTarifPct = 0;
                    foreach ($btRows as $otherRow) {
                        if (intval($otherRow['seri_barang']) === $seri && strtoupper(trim($otherRow['kode_pungutan'])) === 'BM') {
                            $bmTarifPct = floatval($otherRow['tarif'] ?? 0);
                            break;
                        }
                    }
                    $nilaiBm = round($cifRupiah * ($bmTarifPct / 100), 2);
                    $nilaiImpor = $cifRupiah + $nilaiBm;
                    $val = round($nilaiImpor * ($tarifPct / 100), 2);
                }

                $bayar = 0.00;
                $fasilitas = 0.00;
                $lunas = 0.00;
                if ($fas === '1') {
                    $bayar = $val;
                } elseif ($fas === '7') {
                    $lunas = $val;
                } else {
                    $fasilitas = $val;
                }

                $uStmt = $koneksi->prepare("UPDATE bc_ceisa_barangtarif SET nilai_bayar = ?, nilai_fasilitas = ?, nilai_sudah_dilunasi = ? WHERE nomor_aju = ? AND seri_barang = ? AND kode_pungutan = ?");
                $uStmt->bind_param('dddsis', $bayar, $fasilitas, $lunas, $escAju, $seri, $row['kode_pungutan']);
                $uStmt->execute();
                $uStmt->close();
                $updatedBarangTarif++;
            }
        }
    }

    // 2. HITUNG BAHAN BAKU TARIF
    if ($target === 'bahanbaku' || $target === 'all') {
        $bbRes = $koneksi->query("SELECT seri_barang, seri_bahan_baku, kode_asal_bahan_baku, cif, cif_rupiah, harga_penyerahan, harga_perolehan FROM bc_ceisa_bahanbaku WHERE nomor_aju = '$escAju'");
        $bbList = [];
        while ($bb = $bbRes->fetch_assoc()) {
            $k = intval($bb['seri_barang']) . '|' . intval($bb['seri_bahan_baku']) . '|' . trim($bb['kode_asal_bahan_baku'] ?? '0');
            $cifRupiah = floatval($bb['cif_rupiah'] ?? 0);
            if ($cifRupiah <= 0) {
                $cif = floatval($bb['cif'] ?? 0);
                if ($cif > 0) $cifRupiah = $cif * $ndpbm;
                elseif (floatval($bb['harga_perolehan'] ?? 0) > 0) $cifRupiah = floatval($bb['harga_perolehan']);
                elseif (floatval($bb['harga_penyerahan'] ?? 0) > 0) $cifRupiah = floatval($bb['harga_penyerahan']);
            }
            $bbList[$k] = $cifRupiah;
        }

        $bbtRes = $koneksi->query("SELECT * FROM bc_ceisa_bahanbakutarif WHERE nomor_aju = '$escAju'");
        $bbtRows = [];
        while ($r = $bbtRes->fetch_assoc()) {
            $bbtRows[] = $r;
        }

        if (!empty($bbtRows)) {
            foreach ($bbtRows as $row) {
                $seriB = intval($row['seri_barang']);
                $seriBb = intval($row['seri_bahan_baku']);
                $asal = trim($row['kode_asal_bahan_baku'] ?? '0');
                $k = "$seriB|$seriBb|$asal";
                $cifRupiah = $bbList[$k] ?? 0;

                $kp = strtoupper(trim($row['kode_pungutan']));
                $tarifPct = floatval($row['tarif'] ?? 0);
                $fas = trim($row['kode_fasilitas'] ?? '1');

                $val = 0;
                if ($kp === 'BM') {
                    $val = round($cifRupiah * ($tarifPct / 100), 2);
                } else {
                    $bmTarifPct = 0;
                    foreach ($bbtRows as $otherRow) {
                        if (intval($otherRow['seri_barang']) === $seriB && intval($otherRow['seri_bahan_baku']) === $seriBb && trim($otherRow['kode_asal_bahan_baku'] ?? '0') === $asal && strtoupper(trim($otherRow['kode_pungutan'])) === 'BM') {
                            $bmTarifPct = floatval($otherRow['tarif'] ?? 0);
                            break;
                        }
                    }
                    $nilaiBm = round($cifRupiah * ($bmTarifPct / 100), 2);
                    $nilaiImpor = $cifRupiah + $nilaiBm;
                    $val = round($nilaiImpor * ($tarifPct / 100), 2);
                }

                $bayar = 0.00;
                $fasilitas = 0.00;
                $lunas = 0.00;
                if ($fas === '1') {
                    $bayar = $val;
                } elseif ($fas === '7') {
                    $lunas = $val;
                } else {
                    $fasilitas = $val;
                }

                $uStmt = $koneksi->prepare("UPDATE bc_ceisa_bahanbakutarif SET nilai_bayar = ?, nilai_fasilitas = ?, nilai_sudah_dilunasi = ? WHERE nomor_aju = ? AND seri_barang = ? AND seri_bahan_baku = ? AND kode_asal_bahan_baku = ? AND kode_pungutan = ?");
                $uStmt->bind_param('dddsiiss', $bayar, $fasilitas, $lunas, $escAju, $seriB, $seriBb, $asal, $row['kode_pungutan']);
                $uStmt->execute();
                $uStmt->close();
                $updatedBahanBakuTarif++;
            }
        }
    }

    echo json_encode([
        'status' => 'OK',
        'message' => "Kalkulasi tarif berhasil! Diperbarui $updatedBarangTarif baris Barang Tarif & $updatedBahanBakuTarif baris Bahan Baku Tarif.",
        'updated_barang' => $updatedBarangTarif,
        'updated_bahanbaku' => $updatedBahanBakuTarif
    ]);
    exit;
}

require_once __DIR__ . '/ceisa_calc_helper.php';

// MASTER ACTION: Auto-Generate Semua Tarif & Pungutan
if ($action === 'auto_generate_all_tarifs') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_REQUEST['nomor_aju'] ?? '');
    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak valid.']);
        exit;
    }
    $chkIt = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE nomor_aju = '" . $koneksi->real_escape_string($nomor_aju) . "'")->fetch_row()[0] ?? 0;
    if ($chkIt > 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Dokumen AJU $nomor_aju sudah MASUK IT Inventory! Dokumen terkunci."]);
        exit;
    }
    $res = ceisa_auto_generate_all_tarifs($koneksi, $nomor_aju);
    echo json_encode($res);
    exit;
}

// Generate Barang Tarif
if ($action === 'generate_barang_tarif') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_REQUEST['nomor_aju'] ?? '');
    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak valid.']);
        exit;
    }
    $chkIt = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE nomor_aju = '" . $koneksi->real_escape_string($nomor_aju) . "'")->fetch_row()[0] ?? 0;
    if ($chkIt > 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Dokumen AJU $nomor_aju sudah MASUK IT Inventory! Dokumen terkunci."]);
        exit;
    }
    $res = ceisa_generate_barang_tarif($koneksi, $nomor_aju);
    echo json_encode($res);
    exit;
}

// Generate Bahan Baku Tarif
if ($action === 'generate_bahanbaku_tarif') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_REQUEST['nomor_aju'] ?? '');
    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak valid.']);
        exit;
    }
    $chkIt = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE nomor_aju = '" . $koneksi->real_escape_string($nomor_aju) . "'")->fetch_row()[0] ?? 0;
    if ($chkIt > 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Dokumen AJU $nomor_aju sudah MASUK IT Inventory! Dokumen terkunci."]);
        exit;
    }
    $res = ceisa_generate_bahanbaku_tarif($koneksi, $nomor_aju);
    echo json_encode($res);
    exit;
}

// Fix HS Code
if ($action === 'fix_hs_code') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_REQUEST['nomor_aju'] ?? '');
    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak valid.']);
        exit;
    }
    try {
        $res = ceisa_fix_hs_code($koneksi, $nomor_aju);
        echo json_encode($res);
    } catch (\Throwable $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal FIX HS CODE: ' . $e->getMessage()]);
    }
    exit;
}

// Fix Netto
if ($action === 'fix_netto') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_REQUEST['nomor_aju'] ?? '');
    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak valid.']);
        exit;
    }
    try {
        $res = ceisa_fix_netto($koneksi, $nomor_aju);
        echo json_encode($res);
    } catch (\Throwable $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal FIX NETTO: ' . $e->getMessage()]);
    }
    exit;
}

// Edit Seri Barang
if ($action === 'edit_seri') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_REQUEST['nomor_aju'] ?? '');
    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak valid.']);
        exit;
    }
    try {
        $res = ceisa_edit_seri_barang($koneksi, $nomor_aju);
        echo json_encode($res);
    } catch (\Throwable $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal mengurutkan seri barang: ' . $e->getMessage()]);
    }
    exit;
}

// Urutkan Seri Bahan Baku
if ($action === 'seq_bahanbaku') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_REQUEST['nomor_aju'] ?? '');
    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak valid.']);
        exit;
    }
    try {
        $res = ceisa_seq_bahanbaku($koneksi, $nomor_aju);
        echo json_encode($res);
    } catch (\Throwable $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal mengurutkan seri bahan baku: ' . $e->getMessage()]);
    }
    exit;
}

// Delete ALL Trace
if ($action === 'delete_all_trace') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_REQUEST['nomor_aju'] ?? '');
    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak valid.']);
        exit;
    }
    $res = ceisa_delete_all_trace($koneksi, $nomor_aju);
    echo json_encode($res);
    exit;
}

// Load ALL BOM Barang
if ($action === 'load_all_bom') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_REQUEST['nomor_aju'] ?? '');
    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak valid.']);
        exit;
    }
    $res = ceisa_load_all_bom($koneksi, $nomor_aju);
    echo json_encode($res);
    exit;
}

// Load BOM untuk Barang Tertentu (ue_bomload per seri)
if ($action === 'load_bom_barang') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_REQUEST['nomor_aju'] ?? '');
    $seri = intval($_REQUEST['seri'] ?? 0);
    if (empty($nomor_aju) || $seri <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU dan Seri Barang tidak valid.']);
        exit;
    }
    require_once __DIR__ . '/ceisa_calc_helper.php';
    $res = ceisa_load_all_bom($koneksi, $nomor_aju, $seri);
    echo json_encode($res);
    exit;
}

// Transfer Trace Ex-BC ke Bahan Baku
if ($action === 'transfer_trace_to_bb') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_REQUEST['nomor_aju'] ?? '');
    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak valid.']);
        exit;
    }
    $res = ceisa_transfer_trace_to_bb($koneksi, $nomor_aju);
    echo json_encode($res);
    exit;
}

// Mulai Trace FIFO Dokumen Asal
if ($action === 'trace_fifo') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_REQUEST['nomor_aju'] ?? '');
    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak valid.']);
        exit;
    }
    $res = ceisa_trace_fifo($koneksi, $nomor_aju);
    echo json_encode($res);
    exit;
}

// Quick Picker: Get Master Material
if ($action === 'get_material_picker') {
    header('Content-Type: application/json; charset=utf-8');
    $search = trim($_GET['search'] ?? '');
    $klas = trim($_GET['klas'] ?? '');
    $limit = intval($_GET['limit'] ?? 60);
    $res = ceisa_get_material_picker($koneksi, $search, $klas, $limit);
    echo json_encode($res);
    exit;
}

// Quick Picker: Insert from Material
if ($action === 'insert_from_material') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_POST['nomor_aju'] ?? '');
    $rawItems = $_POST['items'] ?? '[]';
    $items = json_decode($rawItems, true);
    if (!is_array($items) || empty($items)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Format item tidak valid.']);
        exit;
    }
    $res = ceisa_insert_from_material($koneksi, $nomor_aju, $items);
    echo json_encode($res);
    exit;
}

// Quick Picker: Insert BOM from Material (bc_material)
if ($action === 'insert_bom_from_material') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_POST['nomor_aju'] ?? '');
    $seri = intval($_POST['seri'] ?? 0);
    $kode_barang_parent = trim($_POST['kode_barang_parent'] ?? '');
    $rawItems = $_POST['items'] ?? '[]';
    $items = json_decode($rawItems, true);
    if (empty($nomor_aju) || $seri <= 0 || !is_array($items) || empty($items)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Format komponen atau parameter barang tidak valid.']);
        exit;
    }
    require_once __DIR__ . '/ceisa_calc_helper.php';
    $res = ceisa_insert_bom_from_material($koneksi, $nomor_aju, $seri, $kode_barang_parent, $items);
    echo json_encode($res);
    exit;
}

// Quick Picker: Get Incoming Documents (bc_1ab2 AB = 'A')
if ($action === 'get_incoming_picker') {
    header('Content-Type: application/json; charset=utf-8');
    $search = trim($_GET['search'] ?? '');
    $bc = trim($_GET['bc'] ?? '');
    $limit = intval($_GET['limit'] ?? 60);
    $res = ceisa_get_incoming_picker($koneksi, $search, $bc, $limit);
    echo json_encode($res);
    exit;
}

// Quick Picker: Insert from Incoming Documents
if ($action === 'insert_from_incoming') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_POST['nomor_aju'] ?? '');
    $seri_barang = intval($_POST['seri_barang'] ?? 1);
    $rawItems = $_POST['items'] ?? '[]';
    $items = json_decode($rawItems, true);
    if (!is_array($items) || empty($items)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Format item dokumen masuk tidak valid.']);
        exit;
    }
    $res = ceisa_insert_from_incoming($koneksi, $nomor_aju, $seri_barang, $items);
    echo json_encode($res);
    exit;
}

// Quick Picker: Insert Trace from Incoming Documents
if ($action === 'insert_trace_from_incoming') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_POST['nomor_aju'] ?? '');
    $seri_barang = intval($_POST['seri_barang'] ?? 1);
    $id_bom = trim($_POST['id_bom'] ?? '');
    $rawItems = $_POST['items'] ?? '[]';
    $items = json_decode($rawItems, true);
    if (!is_array($items) || empty($items)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Format item dokumen masuk tidak valid.']);
        exit;
    }
    require_once __DIR__ . '/ceisa_calc_helper.php';
    $res = ceisa_insert_trace_from_incoming($koneksi, $nomor_aju, $seri_barang, $id_bom, $items);
    echo json_encode($res);
    exit;
}

// Get Recap Tarif (Summary Box)
if ($action === 'get_recap_tarif') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_REQUEST['nomor_aju'] ?? '');
    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak valid.']);
        exit;
    }
    $escAju = $koneksi->real_escape_string($nomor_aju);
    $rows = [];
    $q = $koneksi->query("SELECT kode_pungutan, 
        SUM(COALESCE(nilai_bayar,0)) as nilai_bayar, 
        SUM(COALESCE(nilai_fasilitas,0)) as nilai_fasilitas, 
        SUM(COALESCE(nilai_sudah_dilunasi,0)) as nilai_sudah_dilunasi 
        FROM bc_ceisa_barangtarif 
        WHERE nomor_aju = '$escAju' 
        GROUP BY kode_pungutan");
    if ($q) {
        while ($r = $q->fetch_assoc()) {
            $rows[] = [
                'kode_pungutan' => $r['kode_pungutan'],
                'nilai_bayar' => floatval($r['nilai_bayar']),
                'nilai_fasilitas' => floatval($r['nilai_fasilitas']),
                'nilai_sudah_dilunasi' => floatval($r['nilai_sudah_dilunasi']),
                'source' => 'Barang'
            ];
        }
    }
    $qBb = $koneksi->query("SELECT kode_pungutan, 
        SUM(COALESCE(nilai_bayar,0)) as nilai_bayar, 
        SUM(COALESCE(nilai_fasilitas,0)) as nilai_fasilitas, 
        SUM(COALESCE(nilai_sudah_dilunasi,0)) as nilai_sudah_dilunasi 
        FROM bc_ceisa_bahanbakutarif 
        WHERE nomor_aju = '$escAju' 
        GROUP BY kode_pungutan");
    $bbRows = [];
    if ($qBb) {
        while ($r = $qBb->fetch_assoc()) {
            $bbRows[] = [
                'kode_pungutan' => $r['kode_pungutan'],
                'nilai_bayar' => floatval($r['nilai_bayar']),
                'nilai_fasilitas' => floatval($r['nilai_fasilitas']),
                'nilai_sudah_dilunasi' => floatval($r['nilai_sudah_dilunasi']),
                'source' => 'Bahan Baku'
            ];
        }
    }
    echo json_encode(['status' => 'OK', 'barang' => $rows, 'bahanbaku' => $bbRows]);
    exit;
}

// Get List & Save Tarif BM
if ($action === 'get_tarifbm_list') {
    header('Content-Type: application/json; charset=utf-8');
    $q = $koneksi->query("SELECT hs, tarif_bm, tgl_update FROM bc_ceisa_tarifbm ORDER BY hs ASC LIMIT 500");
    $list = [];
    if ($q) {
        while ($r = $q->fetch_assoc()) {
            $list[] = $r;
        }
    }
    echo json_encode(['status' => 'OK', 'data' => $list]);
    exit;
}
if ($action === 'save_tarifbm') {
    header('Content-Type: application/json; charset=utf-8');
    $hs = trim($_POST['hs'] ?? '');
    $tarif_bm = floatval($_POST['tarif_bm'] ?? 0);
    if (empty($hs)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kode HS tidak boleh kosong.']);
        exit;
    }
    $escHs = $koneksi->real_escape_string($hs);
    $koneksi->query("INSERT INTO bc_ceisa_tarifbm (hs, tarif_bm, tgl_update) VALUES ('$escHs', $tarif_bm, CURDATE()) ON DUPLICATE KEY UPDATE tarif_bm = $tarif_bm, tgl_update = CURDATE()");
    echo json_encode(['status' => 'OK', 'message' => "Tarif BM untuk HS $hs berhasil disimpan: $tarif_bm%"]);
    exit;
}

// =============================================================
// AI DOCUMENT READER AJAX ACTIONS (PDF / Word / Excel / Gambar)
// =============================================================

// 1. Test Koneksi AI Provider
if ($action === 'ai_test_conn') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/ai/AiExtractor.php';

    $rawInput = file_get_contents('php://input');
    $config = json_decode($rawInput, true);
    if (!is_array($config) || empty($config)) {
        if (!empty($_POST['config'])) {
            $config = json_decode($_POST['config'], true);
        } elseif (!empty($_POST['provider'])) {
            $config = $_POST;
        }
    }
    if (!is_array($config) || empty($config)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Konfigurasi AI tidak valid (format JSON salah atau data kosong).']);
        exit;
    }

    $res = AiExtractor::testConnection($config);
    echo json_encode($res);
    exit;
}

// 2. Ekstrak Data Dokumen Menggunakan AI
if ($action === 'ai_extract') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/ai/AiExtractor.php';

    $configJson = $_POST['config'] ?? '';
    $config = json_decode($configJson, true);
    if (!is_array($config) || empty($config)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Konfigurasi AI tidak ditemukan. Silakan atur di menu Pengaturan AI terlebih dahulu.']);
        exit;
    }

    $kodeDokumen = trim($_POST['kode_dokumen'] ?? '20');

    if (empty($_FILES['documents']) || empty($_FILES['documents']['name'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Tidak ada berkas dokumen yang diunggah. Silakan unggah minimal 1 berkas.']);
        exit;
    }

    $uploadedFiles = $_FILES['documents'];
    $fileCount = is_array($uploadedFiles['name']) ? count($uploadedFiles['name']) : 1;

    $allFilesData = [];
    $allImages = [];

    for ($i = 0; $i < $fileCount; $i++) {
        $tmpName  = is_array($uploadedFiles['tmp_name']) ? $uploadedFiles['tmp_name'][$i] : $uploadedFiles['tmp_name'];
        $origName = is_array($uploadedFiles['name']) ? $uploadedFiles['name'][$i] : $uploadedFiles['name'];
        $mime     = is_array($uploadedFiles['type']) ? $uploadedFiles['type'][$i] : $uploadedFiles['type'];
        $err      = is_array($uploadedFiles['error']) ? $uploadedFiles['error'][$i] : $uploadedFiles['error'];

        if ($err !== UPLOAD_ERR_OK || empty($tmpName) || !file_exists($tmpName)) {
            continue;
        }

        $res = AiExtractor::extractTextFromFile($tmpName, $mime, $origName);
        if (!empty($res['text'])) {
            $allFilesData[] = [
                'name' => $origName,
                'text' => $res['text']
            ];
        }
        if (!empty($res['image'])) {
            $allImages[] = $res['image'];
        }
    }

    if (empty($allFilesData) && empty($allImages)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal membaca teks atau gambar dari dokumen yang diunggah. Pastikan format berkas valid (PDF/Word/Excel/Gambar).']);
        exit;
    }

    $prompt = AiExtractor::buildPrompt($allFilesData, $kodeDokumen);
    $aiRes  = AiExtractor::callAi($prompt, $allImages, $config);

    echo json_encode($aiRes);
    exit;
}

// 3. Masukkan Hasil Ekstraksi AI ke Staging AJU (Header, Barang, Entitas, Dokumen, Kemasan)
if ($action === 'ai_inject') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/ai/AiExtractor.php';

    $rawInput = file_get_contents('php://input');
    $payload  = json_decode($rawInput, true);
    if (!is_array($payload) || empty($payload)) {
        if (!empty($_POST['payload'])) {
            $payload = json_decode($_POST['payload'], true);
        } elseif (!empty($_POST['data'])) {
            $payload = $_POST;
        }
    }

    $targetAju = trim($payload['nomor_aju'] ?? '');
    $cmpId     = trim($payload['cmp_id'] ?? $filterCmp);
    $kodeBc    = trim($payload['kode_dokumen'] ?? '20');
    $data      = $payload['data'] ?? [];

    if (empty($cmpId)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Perusahaan (cmp_id) belum terpilih.']);
        exit;
    }
    if (empty($data) || !is_array($data)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Data hasil ekstraksi AI kosong atau tidak valid.']);
        exit;
    }

    // Jika user memilih buat Dokumen AJU Baru secara otomatis
    if (empty($targetAju) || $targetAju === 'NEW') {
        $cleanBc = preg_replace('/[^0-9]/', '', $kodeBc);
        $cleanBc2 = substr(str_pad($cleanBc, 2, '0', STR_PAD_LEFT), 0, 2);
        $ymd = date('Ymd');
        $escCmp = $koneksi->real_escape_string($cmpId);
        $escBc2 = $koneksi->real_escape_string($cleanBc2);

        $comp = [];
        $qCmp = $koneksi->query("SELECT * FROM bc_ceisa_company WHERE cmp_id = '$escCmp' LIMIT 1");
        if ($qCmp && $rCmp = $qCmp->fetch_assoc()) {
            $comp = $rCmp;
        }

        $qSet = $koneksi->query("SELECT * FROM bc_ceisa_setaju WHERE cmp_id = '$escCmp' AND bc = '$escBc2' LIMIT 1");
        if (!$qSet || $qSet->num_rows === 0) {
            $a = !empty($comp['kode_kantor']) ? substr(preg_replace('/[^0-9]/', '', $comp['kode_kantor']), 0, 4) : '0000';
            $a = str_pad($a, 4, '0', STR_PAD_LEFT);
            $b = substr(preg_replace('/[^A-Za-z0-9]/', '', $cmpId), 0, 6);
            $b = str_pad($b, 6, '0', STR_PAD_RIGHT);

            $todayPrefix = $a . $cleanBc2 . $b . $ymd;
            $qMax = $koneksi->query("SELECT nomor_aju FROM bc_ceisa_header WHERE nomor_aju LIKE '{$todayPrefix}%' ORDER BY nomor_aju DESC LIMIT 1");
            $lastSeq = 0;
            if ($qMax && $rMax = $qMax->fetch_assoc()) {
                $lastSeq = intval(substr($rMax['nomor_aju'], -6));
            }
            $nextSeq = $lastSeq + 1;
            $koneksi->query("INSERT INTO bc_ceisa_setaju (cmp_id, bc, a, b, aju) VALUES ('$escCmp', '$escBc2', '$a', '$b', $nextSeq)");
        } else {
            $rSet = $qSet->fetch_assoc();
            $a = str_pad(substr($rSet['a'] ?? '0000', 0, 4), 4, '0', STR_PAD_LEFT);
            $b = str_pad(substr($rSet['b'] ?? '000000', 0, 6), 6, '0', STR_PAD_RIGHT);
            $nextSeq = intval($rSet['aju'] ?? 0) + 1;
            $koneksi->query("UPDATE bc_ceisa_setaju SET aju = $nextSeq WHERE cmp_id = '$escCmp' AND bc = '$escBc2'");
        }

        $targetAju = sprintf('%04s%02s%06s%08s%06d', $a, $cleanBc2, $b, $ymd, $nextSeq);
        $escAju = $koneksi->real_escape_string($targetAju);
        $escKantor = $koneksi->real_escape_string($comp['kode_kantor'] ?? $a);
        $tglNow = date('Y-m-d');

        // Buat skeleton header dasar
        $valuta = !empty($data['header']['kode_valuta']) ? $koneksi->real_escape_string($data['header']['kode_valuta']) : 'USD';
        $koneksi->query("INSERT INTO bc_ceisa_header (
            nomor_aju, kode_dokumen, cmp_id, tanggal_pernyataan, kode_kantor, kode_valuta, ndpbm, fob, cif, netto, bruto
        ) VALUES (
            '$escAju', '$escBc2', '$escCmp', '$tglNow', '$escKantor', '$valuta', 16250, 0, 0, 0, 0
        )");
    }

    $res = AiExtractor::injectToStaging($koneksi, $targetAju, $cmpId, $data);
    echo json_encode($res);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Form Editor Dokumen Pabean CEISA 4.0</title>
    <script>
        // Jika dibuka langsung di browser luar iframe portal, segera alihkan ke index.php agar sidebar menu tetap ada
        if (window.self === window.top) {
            const p = new URLSearchParams(window.location.search);
            const aju = p.get('nomor_aju') || '';
            const cmp = p.get('cmp_id') || '';
            let target = '/index.php?tab=editor';
            if (aju) target += '&nomor_aju=' + encodeURIComponent(aju);
            if (cmp) target += '&cmp_id=' + encodeURIComponent(cmp);
            window.location.replace(target);
        }
        (function() {
            var theme = localStorage.getItem('ceisa_theme') || 'light';
            document.documentElement.setAttribute('data-theme', theme);
        })();
        const isEmbeddedMode = (window.self !== window.top) || (new URLSearchParams(window.location.search).get('embedded') === '1');
        if (isEmbeddedMode) {
            document.documentElement.classList.add('is-embedded');
        }
        const isAdminUser = <?= $isAdmin ? 'true' : 'false' ?> || (new URLSearchParams(window.location.search).get('is_admin') === '1');
        if (isAdminUser) {
            document.documentElement.classList.add('is-admin');
        }
    </script>
    <link rel="icon" type="image/png" href="/assets/logo.png">
    <link rel="shortcut icon" type="image/png" href="/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js"></script>
    <style>
        :root, body[data-theme="light"], html[data-theme="light"] body {
            /* Official CEISA 4.0 Light Theme (DEFAULT) */
            --bg-base: #f0f2f5;
            --bg-card: #ffffff;
            --bg-input: #ffffff;
            --border: #e8e8e8;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --accent: #52c41a;
            --warning: #faad14;
            --danger: #ff4d4f;
            --text-main: #262626;
            --text-muted: #8c8c8c;
            color-scheme: light;
        }
        body[data-theme="dark"], html[data-theme="dark"] body {
            /* CEISA Dark Theme Option */
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --bg-input: #141414;
            --border: #303030;
            --primary: #177ddc;
            --primary-hover: #1890ff;
            --accent: #49aa19;
            --warning: #d89614;
            --danger: #a61d24;
            --text-main: rgba(255, 255, 255, 0.85);
            --text-muted: rgba(255, 255, 255, 0.45);
            color-scheme: dark;
        }

        /* Filter Styles */
        .filter-cols-active .field-group.col-hidden,
        .filter-cols-active .form-section.card-hidden,
        .filter-cols-active .form-col.col-hidden,
        .filter-cols-active .form-col:not(:has(> .form-section:not(.card-hidden))),
        .filter-cols-active th.col-hidden,
        .filter-cols-active td.col-hidden {
            display: none !important;
        }
        .filter-tabs-active .tab-btn.tab-filtered-hidden {
            display: none !important;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: var(--bg-base);
            color: var(--text-main);
            font-size: 10px;
            line-height: 1.4;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* Top Navigation Header */
        .topbar {
            background: var(--bg-card);
            border-bottom: 1px solid var(--border);
            padding: 4px 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        /* Mode Embedded (Saat dimuat di dalam Iframe Portal CEISA) */
        html.is-embedded .brand,
        body.is-embedded .brand,
        html.is-embedded .theme-select,
        body.is-embedded .theme-select {
            display: none !important;
        }
        html.is-embedded .topbar,
        body.is-embedded .topbar {
            padding: 3px 8px !important;
        }

        /* Nama tabel internal database hanya tampil untuk Administrator */
        .tbl-tech-name {
            display: none !important;
        }
        html.is-admin .tbl-tech-name,
        body.is-admin .tbl-tech-name,
        body[data-is-admin="1"] .tbl-tech-name {
            display: inline !important;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .btn-back-host {
            font-weight: 700;
            font-size: 11px;
            background: #e6f7ff;
            color: #1890ff;
            border: 1px solid #91d5ff;
            border-radius: 4px;
            padding: 4px 10px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s ease;
        }
        .btn-back-host:hover {
            background: #1890ff;
            color: #ffffff;
            border-color: #1890ff;
            box-shadow: 0 2px 5px rgba(24, 144, 255, 0.3);
        }
        body[data-theme="dark"] .btn-back-host {
            background: rgba(24, 144, 255, 0.15);
            color: #69c0ff;
            border-color: #177ddc;
        }
        body[data-theme="dark"] .btn-back-host:hover {
            background: #177ddc;
            color: #ffffff;
        }
        .brand {
            font-weight: 700;
            font-size: 11px;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .brand svg {
            width: 14px;
            height: 14px;
        }
        .aju-selector {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        select, input, textarea {
            background: var(--bg-input);
            border: 1px solid var(--border);
            color: var(--text-main);
            padding: 1px 6px;
            border-radius: 2px;
            font-size: 10px;
            height: 22px;
            outline: none;
            transition: border-color 0.2s;
            font-family: inherit;
        }
        textarea {
            height: auto;
            min-height: 42px;
        }
        select:focus, input:focus, textarea:focus {
            border-color: var(--primary);
        }

        /* High-Contrast Calendar Picker Indicator */
        input[type="date"] {
            color-scheme: dark;
            position: relative;
        }
        body[data-theme="light"] input[type="date"] {
            color-scheme: light;
        }
        input[type="date"]::-webkit-calendar-picker-indicator {
            cursor: pointer;
            opacity: 0.85;
            padding: 2px;
            border-radius: 2px;
            transition: all 0.2s;
        }
        input[type="date"]::-webkit-calendar-picker-indicator:hover {
            opacity: 1;
            transform: scale(1.1);
            background: rgba(54, 153, 255, 0.2);
        }
        .btn {
            background: var(--primary);
            color: #0f172a;
            border: none;
            padding: 0 7px;
            height: 22px;
            border-radius: 2px;
            font-size: 10px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
            transition: all 0.2s;
            font-family: inherit;
        }
        .btn:hover { background: var(--primary-hover); }
        .btn-success { background: var(--accent); color: #fff; }
        .btn-success:hover { background: #059669; }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-secondary { background: var(--bg-card); color: var(--text-main); border: 1px solid var(--border); }
        .btn-secondary:hover { background: var(--border); }
        .btn-warning { background: #d97706; color: #fff; border: 1px solid #b45309; }
        .btn-warning:hover { background: #b45309; color: #fff; }
        .btn-sm { padding: 0 5px; height: 20px; font-size: 9px; }

        /* Filter Toggle Buttons */
        .btn-toggle {
            background: var(--bg-input);
            border: 1px solid var(--border);
            color: var(--text-muted);
            padding: 0 6px;
            height: 20px;
            border-radius: 2px;
            font-size: 9px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        .btn-toggle.active {
            background: rgba(56, 189, 248, 0.15);
            border-color: var(--primary);
            color: var(--primary);
        }

        /* Theme Switcher */
        .theme-select {
            display: inline-flex;
            gap: 1px;
            background: var(--bg-input);
            padding: 1px;
            border-radius: 3px;
            border: 1px solid var(--border);
        }
        .theme-btn {
            background: transparent;
            border: none;
            cursor: pointer;
            padding: 1px 4px;
            border-radius: 2px;
            font-size: 9px;
            color: var(--text-muted);
        }
        .theme-btn.active {
            background: var(--primary);
            color: #000;
            font-weight: 700;
        }

        /* Meta Banner */
        .meta-banner {
            background: var(--bg-card);
            border-bottom: 1px solid var(--border);
            padding: 3px 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 9.5px;
            flex-wrap: wrap;
            gap: 6px;
        }
        .meta-item { display: flex; align-items: center; gap: 4px; }
        .meta-label { color: var(--text-muted); font-weight: 600; text-transform: uppercase; font-size: 8.5px; }
        .meta-val { color: var(--text-main); font-weight: 700; font-family: monospace; font-size: 9.5px; }
        .badge { padding: 1px 4px; border-radius: 2px; font-size: 8.5px; font-weight: 600; }
        .badge-bc { background: rgba(56, 189, 248, 0.2); color: var(--primary); border: 1px solid rgba(56, 189, 248, 0.4); }

        /* Tab Navigation Bar */
        .tab-bar {
            background: var(--bg-input);
            border-bottom: 1px solid var(--border);
            padding: 0 8px;
            display: flex;
            gap: 2px;
            overflow-x: auto;
        }
        .tab-btn {
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            color: var(--text-muted);
            padding: 5px 8px;
            font-size: 10px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s;
        }
        .tab-btn:hover { color: var(--text-main); }
        .tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: rgba(56, 189, 248, 0.05);
        }
        .tab-count {
            background: rgba(255,255,255,0.1);
            padding: 0 4px;
            border-radius: 6px;
            font-size: 8.5px;
        }

        /* Main Workspace Container & Entry Sidebar */
        .editor-main-container {
            display: flex;
            flex: 1;
            overflow: hidden;
            position: relative;
            width: 100%;
        }
        .sidebar-entry {
            width: 295px;
            min-width: 295px;
            max-width: 295px;
            background: var(--bg-card);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            transition: margin-left 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 10;
            padding: 8px;
            gap: 8px;
        }
        .sidebar-entry.collapsed {
            margin-left: -295px;
            display: none !important;
        }
        .sidebar-entry-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 6px;
            border-bottom: 1px solid var(--border);
            font-size: 11px;
            font-weight: 700;
            color: var(--primary);
        }
        .entry-card {
            background: var(--bg-base);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 8px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .entry-card-title {
            font-size: 9.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .entry-live-preview {
            background: var(--bg-card);
            border: 1px dashed var(--primary);
            border-radius: 4px;
            padding: 6px;
            font-family: monospace;
            font-size: 11px;
            font-weight: 700;
            color: var(--primary);
            text-align: center;
            word-break: break-all;
            user-select: all;
        }
        .btn-erp {
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%) !important;
            color: #ffffff !important;
            border: none !important;
            font-weight: 700 !important;
            letter-spacing: 0.3px;
            box-shadow: 0 2px 6px rgba(79, 70, 229, 0.25);
            transition: all 0.2s ease;
        }
        .btn-erp:hover {
            background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%) !important;
            box-shadow: 0 4px 10px rgba(79, 70, 229, 0.35);
            transform: translateY(-1px);
        }
        .btn-ai-reader {
            background: linear-gradient(135deg, #8b5cf6 0%, #ec4899 100%) !important;
            color: #ffffff !important;
            border: none !important;
            font-weight: 700 !important;
            letter-spacing: 0.3px;
            box-shadow: 0 2px 6px rgba(139, 92, 246, 0.35);
            transition: all 0.2s ease;
        }
        .btn-ai-reader:hover {
            background: linear-gradient(135deg, #7c3aed 0%, #db2777 100%) !important;
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.45);
            transform: translateY(-1px);
        }
        .ai-dropzone {
            border: 2px dashed #8b5cf6;
            background: rgba(139, 92, 246, 0.04);
            border-radius: 8px;
            padding: 24px 16px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .ai-dropzone:hover, .ai-dropzone.dragover {
            border-color: #ec4899;
            background: rgba(236, 72, 153, 0.08);
            transform: scale(1.005);
        }
        .ai-file-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            padding: 4px 10px;
            border-radius: 16px;
            font-size: 11px;
            margin: 3px;
        }
        .ai-preview-tabs {
            display: flex;
            gap: 4px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 8px;
            overflow-x: auto;
        }
        .ai-preview-tab-btn {
            background: transparent;
            border: none;
            padding: 6px 12px;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            border-bottom: 2px solid transparent;
            white-space: nowrap;
        }
        .ai-preview-tab-btn.active {
            color: #8b5cf6;
            border-bottom-color: #8b5cf6;
        }
        .ai-pulse {
            animation: aiPulse 1.5s infinite;
        }
        @keyframes aiPulse {
            0% { opacity: 0.6; transform: scale(0.98); }
            50% { opacity: 1; transform: scale(1.02); }
            100% { opacity: 0.6; transform: scale(0.98); }
        }
        .btn-xs {
            padding: 2px 6px !important;
            font-size: 9px !important;
            border-radius: 4px !important;
        }
        .btn-block {
            width: 100% !important;
            display: block !important;
            text-align: center !important;
        }
        .editor-content-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            min-width: 0;
        }
        .workspace {
            padding: 6px 10px;
            flex: 1;
            overflow-y: auto;
            position: relative;
        }
        .panel {
            display: none;
            position: relative;
            min-height: 250px;
        }
        .panel.active { display: block; }

        /* Form Layout for Header */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            align-items: start;
            gap: 10px;
        }
        .form-col {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .form-section {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 8px 11px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            height: auto;
            transition: all 0.2s;
        }
        .section-title {
            font-size: 11px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 6px;
            padding-bottom: 3px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .field-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 6px 8px;
            margin-bottom: 5px;
        }
        .field-row-3 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(90px, 1fr));
            gap: 6px 8px;
            margin-bottom: 5px;
        }
        .field-row > .field-group,
        .field-row-3 > .field-group {
            margin-bottom: 0;
        }
        .field-group {
            display: flex;
            flex-direction: column;
            gap: 2px;
            margin-bottom: 5px;
            position: relative;
            min-width: 0;
        }
        .field-group label {
            font-size: 9px;
            color: var(--text-muted);
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .field-group label .reff-badge {
            font-size: 8px;
            color: var(--primary);
            background: rgba(56, 189, 248, 0.15);
            padding: 0 3px;
            border-radius: 2px;
            cursor: pointer;
        }
        .field-group input, .field-group select {
            width: 100%;
        }
        .input-group-reff {
            display: flex;
            gap: 3px;
        }
        .input-group-reff input {
            flex: 1;
        }
        .btn-lookup {
            background: var(--bg-input);
            border: 1px solid var(--border);
            color: var(--primary);
            padding: 0 5px;
            height: 22px;
            border-radius: 2px;
            cursor: pointer;
            font-size: 10px;
        }
        .btn-lookup:hover {
            border-color: var(--primary);
        }

        /* Clean Date Picker Group (Header & Table) */
        .input-date-group {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }
        .input-date-group input.hdr-date-input {
            width: 100%;
            padding-right: 22px;
            font-family: monospace, inherit;
            font-size: 10px;
        }
        .input-date-group input.hdr-date-native {
            position: absolute;
            right: 2px;
            bottom: 2px;
            width: 1px;
            height: 1px;
            opacity: 0;
            pointer-events: none;
            border: none;
            padding: 0;
            margin: 0;
        }
        .input-date-group .btn-hdr-date {
            position: absolute;
            right: 3px;
            top: 50%;
            transform: translateY(-50%);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 11px;
            color: var(--text-muted);
            opacity: 0.7;
            padding: 0;
            margin: 0;
            transition: opacity 0.2s, color 0.2s;
            user-select: none;
            line-height: 1;
        }
        .input-date-group .btn-hdr-date:hover {
            opacity: 1;
            color: var(--primary);
        }

        /* Datagrid Table Layout */
        .grid-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
        }
        .grid-wrap {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 3px;
            overflow: auto;
            max-height: calc(100vh - 180px);
        }
        table.datagrid {
            width: max-content;
            min-width: 100%;
            border-collapse: collapse;
            font-size: 10px;
            text-align: left;
            table-layout: auto;
        }
        table.datagrid thead {
            background: var(--bg-input);
            position: sticky;
            top: 0;
            z-index: 10;
        }
        table.datagrid th {
            position: relative;
            padding: 5px 14px 5px 8px;
            color: var(--text-muted);
            font-weight: 700;
            font-size: 9.5px;
            white-space: nowrap;
            border: 1px solid var(--border);
            box-shadow: inset 0 -1px 0 var(--border);
            background: var(--bg-input);
            user-select: none;
            cursor: pointer;
            transition: background 0.15s, color 0.15s;
        }
        table.datagrid th:hover {
            color: var(--text-main);
            background: rgba(24, 144, 255, 0.08);
        }
        table.datagrid th.no-sort,
        table.datagrid th.th-action {
            cursor: default !important;
            padding-right: 8px !important;
        }
        table.datagrid th.no-sort:hover,
        table.datagrid th.th-action:hover {
            color: var(--text-muted) !important;
            background: var(--bg-input) !important;
        }

        /* Sorting Indicators */
        table.datagrid th .th-sort-icon {
            display: inline-block;
            margin-left: 5px;
            font-size: 9px;
            line-height: 1;
            opacity: 0.35;
            transition: opacity 0.15s, color 0.15s;
            vertical-align: middle;
            pointer-events: none;
        }
        table.datagrid th:hover .th-sort-icon {
            opacity: 0.75;
        }
        table.datagrid th.sort-asc,
        table.datagrid th.sort-desc {
            color: var(--primary) !important;
            background: rgba(24, 144, 255, 0.12) !important;
        }
        table.datagrid th.sort-asc .th-sort-icon,
        table.datagrid th.sort-desc .th-sort-icon {
            opacity: 1 !important;
            color: var(--primary) !important;
            font-weight: 900 !important;
        }

        /* Column Resizer Handle */
        .col-resizer {
            position: absolute;
            top: 0;
            right: 0;
            width: 7px;
            height: 100%;
            cursor: col-resize;
            user-select: none;
            z-index: 15;
            background: transparent;
            transition: background-color 0.15s;
        }
        .col-resizer:hover,
        .col-resizer.resizing {
            background-color: var(--primary);
            box-shadow: 0 0 4px var(--primary);
        }
        body.grid-resizing {
            cursor: col-resize !important;
            user-select: none !important;
        }
        body.grid-resizing * {
            user-select: none !important;
        }
        table.datagrid td {
            padding: 2px 4px;
            border: 1px solid var(--border);
            vertical-align: middle;
            white-space: nowrap;
        }
        table.datagrid tfoot {
            position: sticky;
            bottom: 0;
            z-index: 10;
            background: var(--bg-input);
        }
        table.datagrid tfoot tr.tfoot-row {
            background: var(--bg-input);
        }
        table.datagrid tfoot td {
            padding: 5px 8px;
            font-size: 10px;
            font-weight: 700;
            border: 1px solid var(--border);
            white-space: nowrap;
            background: var(--bg-input);
            color: var(--text-main);
            box-shadow: inset 0 2px 0 var(--border), inset 0 -1px 0 var(--border);
        }
        table.datagrid tfoot td.td-number {
            text-align: right !important;
            font-variant-numeric: tabular-nums;
            padding-right: 8px !important;
            color: var(--primary);
            font-weight: 700;
        }
        table.datagrid tfoot td.tfoot-label {
            text-align: center !important;
            font-weight: 800 !important;
            letter-spacing: 0.5px;
            color: var(--text-muted);
        }
        table.datagrid td.td-wrap {
            white-space: normal;
            vertical-align: top;
            padding: 2px 4px;
        }
        table.datagrid td.td-date {
            vertical-align: middle;
        }
        table.datagrid tbody tr:hover {
            background: rgba(24, 144, 255, 0.04);
        }

        /* Master-Detail Selected Row Highlight (PowerBuilder 1:1 interaction) */
        table.datagrid tbody tr.selected-row,
        table.datagrid tbody tr.selected-row > td {
            background-color: #dbeafe !important;
            border-color: #93c5fd !important;
        }
        [data-theme="dark"] table.datagrid tbody tr.selected-row,
        [data-theme="dark"] table.datagrid tbody tr.selected-row > td {
            background-color: #1e3a8a !important;
            border-color: #3b82f6 !important;
        }
        table.datagrid tbody tr.selected-row > td:first-child {
            border-left: 4px solid #2563eb !important;
            font-weight: 700;
        }
        #table-barang tbody tr,
        #table-barangbom tbody tr,
        #table-tracebb tbody tr {
            cursor: pointer;
        }

        /* Modal Lookup Entitas Table Styling - Clean, Compact & No Header Overlap */
        #tableLookupEntitas {
            border-collapse: separate !important;
            border-spacing: 0 !important;
            width: 100%;
        }
        #tableLookupEntitas thead {
            position: sticky;
            top: 0;
            z-index: 30;
        }
        #tableLookupEntitas thead th {
            position: sticky !important;
            top: 0 !important;
            z-index: 30 !important;
            background: var(--bg-card) !important;
            color: var(--text-muted) !important;
            font-size: 9.5px !important;
            font-weight: 700 !important;
            padding: 6px 8px !important;
            border: none !important;
            border-bottom: 1px solid var(--border) !important;
            box-shadow: inset 0 -1px 0 var(--border), 0 1px 2px rgba(0,0,0,0.06);
            white-space: nowrap;
        }
        #tableLookupEntitas tbody tr {
            transition: background 0.1s;
        }
        #tableLookupEntitas tbody tr:hover {
            background-color: rgba(24, 144, 255, 0.08) !important;
        }
        #tableLookupEntitas tbody td {
            padding: 4px 6px !important;
            font-size: 10.5px;
            border: none !important;
            border-bottom: 1px solid var(--border) !important;
            vertical-align: middle;
            background: transparent;
        }

        /* Standar input di dalam cell (Full Width 100% sesuai lebar kolom) */
        table.datagrid td input.cell-input {
            width: 100% !important;
            min-width: 0 !important;
            max-width: 100% !important;
            padding: 2px 5px;
            font-size: 10px;
            height: 22px;
            border: 1px solid transparent;
            background: transparent;
            color: var(--text-main);
            border-radius: 2px;
            transition: all 0.15s;
            font-family: inherit;
            box-sizing: border-box;
        }
        table.datagrid td input.cell-input:hover,
        table.datagrid td input.cell-input:focus {
            background: var(--bg-input);
            border-color: var(--primary);
        }

        /* Hilangkan spinner naik-turun pada seluruh input number di aplikasi */
        input[type="number"]::-webkit-outer-spin-button,
        input[type="number"]::-webkit-inner-spin-button {
            -webkit-appearance: none !important;
            margin: 0 !important;
        }
        input[type="number"] {
            -moz-appearance: textfield !important;
            appearance: textfield !important;
        }

        /* Rata kanan untuk semua input angka di form card Header & Form Section */
        .field-group input[type="number"],
        input.input-number {
            text-align: right !important;
            font-variant-numeric: tabular-nums;
            font-family: inherit;
            padding-right: 8px;
        }

        /* Datagrid header dan sel angka */
        table.datagrid th.th-number {
            text-align: right !important;
            padding-right: 10px !important;
        }
        table.datagrid td.td-number {
            text-align: right !important;
        }
        table.datagrid td input.cell-input.cell-number,
        table.datagrid td input[type="number"].cell-input {
            text-align: right !important;
            font-variant-numeric: tabular-nums;
            font-family: inherit;
            padding-right: 8px !important;
            padding-left: 6px !important;
        }

        /* Kolom Seri standar 45px di semua tab datagrid */
        table.datagrid th[data-col="seri"],
        table.datagrid th[data-col="seri_barang"],
        table.datagrid td[data-col="seri"],
        table.datagrid td[data-col="seri_barang"],
        #table-barang th[data-col="seri_barang"],
        #table-barang td[data-col="seri_barang"],
        table.datagrid td.td-seri {
            width: 45px !important;
            min-width: 45px !important;
            max-width: 45px !important;
            text-align: center !important;
            padding-left: 2px !important;
            padding-right: 2px !important;
            box-sizing: border-box !important;
        }
        table.datagrid th[data-col="seri_bahan_baku"],
        table.datagrid td[data-col="seri_bahan_baku"] {
            width: 50px !important;
            min-width: 50px !important;
            max-width: 50px !important;
            text-align: center !important;
            padding-left: 2px !important;
            padding-right: 2px !important;
            box-sizing: border-box !important;
        }
        table.datagrid td[data-col="seri"] input,
        table.datagrid td[data-col="seri_barang"] input,
        table.datagrid td[data-col="seri_bahan_baku"] input,
        table.datagrid td.td-seri input {
            text-align: center !important;
            padding-left: 2px !important;
            padding-right: 2px !important;
        }

        /* Lebar awal standar untuk kolom-kolom tabel (bebas di-resize & di-sort pengguna) */
        table.datagrid th[data-col="kode_kemasan"] { width: 125px; min-width: 60px; }
        table.datagrid th[data-col="kode_negara_asal"],
        table.datagrid th[data-col="kode_negara"],
        table.datagrid th[data-col="kode_bendera"],
        #table-pengangkut th[data-col="kode_bendera"] { width: 135px; min-width: 60px; }
        table.datagrid th[data-col="kode"],
        #table-bankdevisa th[data-col="kode"] { width: 140px; min-width: 60px; }
        #table-pengangkut th[data-col="nama_pengangkut"] { min-width: 180px; }
        #table-bankdevisa th[data-col="nama"] { min-width: 200px; }
        table.datagrid th[data-col="kode_dokumen"] { width: 145px; min-width: 60px; }
        table.datagrid th[data-col="kode_entitas"] { width: 130px; min-width: 60px; }
        table.datagrid th[data-col="kode_jenis_identitas"] { width: 125px; min-width: 60px; }
        table.datagrid th[data-col="kode_cara_angkut"] { width: 110px; min-width: 60px; }
        table.datagrid th[data-col="kode_ukuran_kontainer"] { width: 105px; min-width: 60px; }
        table.datagrid th[data-col="kode_jenis_kontainer"] { width: 115px; min-width: 60px; }
        table.datagrid th[data-col="kode_tipe_kontainer"] { width: 120px; min-width: 60px; }
        table.datagrid th[data-col="kode_kondisi_barang"] { width: 110px; min-width: 60px; }


        /* Auto-wrapping textarea (Full Width 100% sesuai lebar kolom) */
        table.datagrid td textarea.cell-wrap {
            width: 100% !important;
            min-width: 0 !important;
            max-width: 100% !important;
            padding: 3px 5px;
            font-size: 10px;
            line-height: 1.35;
            min-height: 22px;
            border: 1px solid transparent;
            background: transparent;
            color: var(--text-main);
            border-radius: 2px;
            resize: none;
            white-space: pre-wrap;
            word-break: break-word;
            font-family: inherit;
            box-sizing: border-box;
            overflow-y: hidden;
            field-sizing: content;
            display: block;
            transition: border-color 0.15s;
        }
        table.datagrid td textarea.cell-wrap:hover,
        table.datagrid td textarea.cell-wrap:focus {
            background: var(--bg-input);
            border-color: var(--primary);
        }

        /* Dropdown Referensi di tabel datagrid (Full Width 100% sesuai lebar kolom) */
        table.datagrid td select.cell-select {
            width: 100% !important;
            min-width: 0 !important;
            max-width: 100% !important;
            height: 22px;
            padding: 1px 20px 1px 5px;
            font-size: 10px;
            font-weight: 500;
            border: 1px solid transparent;
            background: transparent;
            color: var(--text-main);
            border-radius: 2px;
            outline: none;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.15s;
            box-sizing: border-box;
            white-space: nowrap;
            text-overflow: ellipsis;
        }
        table.datagrid td select.cell-select:hover,
        table.datagrid td select.cell-select:focus {
            background: var(--bg-input);
            border-color: var(--primary);
        }
        table.datagrid td select.cell-select option {
            background: var(--bg-card);
            color: var(--text-main);
            padding: 4px;
        }

        /* Jika dropdown bernilai kosong: tetap full width agar mudah diklik */
        table.datagrid td select.cell-select.is-empty {
            appearance: none !important;
            -webkit-appearance: none !important;
            -moz-appearance: none !important;
            background: transparent !important;
            border-color: transparent !important;
            color: transparent !important;
            width: 100% !important;
            min-width: 0 !important;
            max-width: 100% !important;
        }
        table.datagrid td select.cell-select.is-empty:hover,
        table.datagrid td select.cell-select.is-empty:focus {
            appearance: auto !important;
            -webkit-appearance: auto !important;
            -moz-appearance: auto !important;
            background: var(--bg-input) !important;
            border-color: var(--primary) !important;
            color: var(--text-main) !important;
            width: 100% !important;
            min-width: 0 !important;
            max-width: 100% !important;
        }



        /* Format Tanggal Datagrid (yyyy-mm-dd, Kosong jika blank) */
        /* Format Tanggal Datagrid (yyyy-mm-dd, Kosong jika blank) */
        .date-cell-wrap {
            display: flex;
            align-items: center;
            position: relative;
            width: 100%;
            height: 22px;
            border: 1px solid transparent;
            border-radius: 2px;
            box-sizing: border-box;
            background: transparent;
            transition: border-color 0.15s, background-color 0.15s;
        }
        .date-cell-wrap:hover,
        .date-cell-wrap:focus-within {
            background: var(--bg-input);
            border-color: var(--primary);
        }
        .date-cell-wrap input.cell-date-input {
            width: 100%;
            height: 100%;
            padding: 1px 22px 1px 5px;
            font-size: 10px;
            font-family: monospace, inherit;
            border: none !important;
            background: transparent !important;
            color: var(--text-main);
            border-radius: 2px;
            box-sizing: border-box;
            outline: none !important;
        }
        .date-cell-wrap .btn-date-trigger {
            position: absolute;
            right: 3px;
            top: 50%;
            transform: translateY(-50%);
            width: 18px;
            height: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 11px;
            padding: 0;
            margin: 0;
            color: var(--text-muted);
            opacity: 0.65;
            transition: opacity 0.2s, color 0.2s;
            user-select: none;
            line-height: 1;
            z-index: 2;
        }
        .date-cell-wrap .btn-date-trigger:hover {
            opacity: 1;
            color: var(--primary);
        }
        .date-cell-wrap input.cell-date-native {
            position: absolute;
            right: 0;
            top: 0;
            width: 0;
            height: 0;
            opacity: 0;
            pointer-events: none;
            border: none;
            padding: 0;
            margin: 0;
        }

        /* Toast Notifications */
        #toast {
            position: fixed;
            bottom: 14px;
            right: 14px;
            background: #10b981;
            color: #fff;
            padding: 6px 12px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            display: none;
            box-shadow: 0 4px 10px rgba(0,0,0,0.3);
            z-index: 2000;
            align-items: center;
            gap: 6px;
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.75);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            padding: 12px;
        }
        .modal {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 3px;
            width: 100%;
            max-width: 780px;
            max-height: 88vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
        }
        .modal-header {
            padding: 8px 12px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--bg-input);
        }
        .modal-body { padding: 10px 12px; overflow-y: auto; flex: 1; }
        .modal-footer { padding: 8px 12px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 6px; background: var(--bg-input); }
        pre.code-view {
            background: var(--bg-input);
            border: 1px solid var(--border);
            padding: 8px 10px;
            border-radius: 3px;
            color: var(--primary);
            font-family: monospace;
            font-size: 10px;
            white-space: pre-wrap;
            word-break: break-all;
            max-height: 400px;
            overflow-y: auto;
        }
        /* ========================================================
           GLOBAL MODERN LOADER & PROGRESS ANIMATIONS
           ======================================================== */
        /* ========================================================
           GLOBAL ULTRA-MODERN CYBER LOADER & PIPELINE ANIMATIONS
           ======================================================== */
        .app-loader-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px 20px 20px 20px;
            text-align: center;
            position: relative;
        }

        .app-loader-icon-wrap {
            position: relative;
            width: 86px;
            height: 86px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 18px;
        }

        .app-loader-aura {
            position: absolute;
            inset: -12px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(24, 144, 255, 0.22) 0%, rgba(0, 210, 255, 0.08) 50%, transparent 70%);
            animation: app-aura-pulse 2.2s ease-in-out infinite;
            pointer-events: none;
        }

        .app-loader-orbit {
            position: absolute;
            inset: -4px;
            border-radius: 50%;
            border: 1.5px dashed rgba(24, 144, 255, 0.35);
            animation: app-orbit 14s linear infinite;
        }

        .app-loader-spinner {
            position: absolute;
            inset: 0;
            border-radius: 50%;
            border: 3.5px solid transparent;
            border-top-color: #1890ff;
            border-right-color: #00d2ff;
            animation: app-spin 0.9s cubic-bezier(0.45, 0.05, 0.55, 0.95) infinite;
            box-sizing: border-box;
            filter: drop-shadow(0 0 6px rgba(24, 144, 255, 0.4));
        }

        .app-loader-spinner-inner {
            position: absolute;
            inset: 7px;
            border-radius: 50%;
            border: 2.5px solid transparent;
            border-bottom-color: #52c41a;
            border-left-color: #13c2c2;
            animation: app-spin-reverse 1.3s cubic-bezier(0.45, 0.05, 0.55, 0.95) infinite;
            box-sizing: border-box;
        }

        .app-loader-center-disc {
            position: relative;
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: radial-gradient(circle at 30% 30%, #ffffff, #e6f7ff);
            box-shadow: 0 4px 14px rgba(24, 144, 255, 0.18), inset 0 1px 2px rgba(255, 255, 255, 0.9);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2;
        }

        html[data-theme="dark"] .app-loader-center-disc {
            background: radial-gradient(circle at 30% 30%, #1f2a37, #111827);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.4), inset 0 1px 1px rgba(255, 255, 255, 0.1);
        }

        .app-loader-center-icon {
            font-size: 24px;
            color: #1890ff;
            animation: app-rocket-float 2s ease-in-out infinite;
            display: inline-block;
            filter: drop-shadow(0 2px 8px rgba(24, 144, 255, 0.4));
        }

        @keyframes app-spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        @keyframes app-spin-reverse {
            from { transform: rotate(360deg); }
            to { transform: rotate(0deg); }
        }

        @keyframes app-orbit {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        @keyframes app-aura-pulse {
            0%, 100% { transform: scale(0.95); opacity: 0.6; }
            50% { transform: scale(1.15); opacity: 1; }
        }

        @keyframes app-rocket-float {
            0%, 100% { transform: translateY(0) rotate(-4deg); }
            50% { transform: translateY(-4px) rotate(4deg); }
        }

        .app-loader-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-heading, #1f1f1f);
            margin-bottom: 8px;
            letter-spacing: -0.3px;
        }

        .app-loader-desc {
            font-size: 12px;
            color: var(--text-muted, #595959);
            max-width: 480px;
            line-height: 1.5;
            margin-bottom: 16px;
            min-height: 20px;
            transition: all 0.3s ease;
        }

        /* Modern Document Meta Card */
        .app-loader-doc-card {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 5px 14px;
            background: rgba(24, 144, 255, 0.06);
            border: 1px solid rgba(24, 144, 255, 0.22);
            border-radius: 20px;
            margin-bottom: 16px;
            font-size: 11.5px;
        }

        html[data-theme="dark"] .app-loader-doc-card {
            background: rgba(24, 144, 255, 0.12);
            border-color: rgba(24, 144, 255, 0.3);
        }

        .app-loader-doc-card .meta-bc {
            background: #1890ff;
            color: #ffffff;
            font-weight: 700;
            font-size: 10px;
            padding: 1px 7px;
            border-radius: 10px;
            letter-spacing: 0.3px;
        }

        .app-loader-doc-card .meta-aju {
            font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
            font-weight: 600;
            color: var(--text-heading, #1f1f1f);
            letter-spacing: 0.5px;
        }

        /* 3-Step Live Pipeline Visualizer */
        .app-pipeline-container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin-bottom: 16px;
            max-width: 440px;
            width: 100%;
        }

        .app-pipeline-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            opacity: 0.45;
            transition: all 0.4s ease;
            flex: 1;
        }

        .app-pipeline-step.active {
            opacity: 1;
            transform: scale(1.04);
        }

        .app-pipeline-step.done {
            opacity: 0.95;
        }

        .pipeline-circle {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--bg-card, #ffffff);
            border: 2px solid #d9d9d9;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            color: #8c8c8c;
            transition: all 0.4s ease;
        }

        .app-pipeline-step.active .pipeline-circle {
            border-color: #1890ff;
            color: #1890ff;
            box-shadow: 0 0 12px rgba(24, 144, 255, 0.45);
            background: rgba(24, 144, 255, 0.08);
            animation: pulse-ring 1.8s infinite;
        }

        .app-pipeline-step.done .pipeline-circle {
            border-color: #52c41a;
            background: #52c41a;
            color: #ffffff;
        }

        .pipeline-label {
            font-size: 10px;
            font-weight: 600;
            color: var(--text-muted, #8c8c8c);
            white-space: nowrap;
        }

        .app-pipeline-step.active .pipeline-label {
            color: #1890ff;
            font-weight: 700;
        }

        .app-pipeline-step.done .pipeline-label {
            color: #52c41a;
        }

        .app-pipeline-line {
            flex: 1;
            height: 2.5px;
            background: rgba(0, 0, 0, 0.08);
            border-radius: 2px;
            position: relative;
            overflow: hidden;
            margin-bottom: 14px;
        }

        html[data-theme="dark"] .app-pipeline-line {
            background: rgba(255, 255, 255, 0.12);
        }

        .app-pipeline-line .line-flow {
            position: absolute;
            top: 0;
            left: 0;
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #1890ff, #52c41a);
            transition: width 0.6s ease;
        }

        .app-pipeline-line.active .line-flow {
            width: 100%;
        }

        @keyframes pulse-ring {
            0%, 100% { box-shadow: 0 0 0 0 rgba(24, 144, 255, 0.5); }
            50% { box-shadow: 0 0 0 6px rgba(24, 144, 255, 0); }
        }

        /* Glowing Progress Track */
        .app-loader-progress-track {
            width: 100%;
            max-width: 380px;
            height: 6px;
            background: rgba(0, 0, 0, 0.06);
            border-radius: 999px;
            overflow: hidden;
            position: relative;
            margin-bottom: 14px;
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.06);
        }

        html[data-theme="dark"] .app-loader-progress-track {
            background: rgba(255, 255, 255, 0.1);
        }

        .app-loader-progress-bar {
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            width: 50%;
            background: linear-gradient(90deg, #1890ff 0%, #00d2ff 40%, #52c41a 80%, #1890ff 100%);
            background-size: 200% 100%;
            border-radius: 999px;
            animation: app-bar-indeterminate 1.4s ease-in-out infinite;
            box-shadow: 0 0 10px rgba(0, 210, 255, 0.5);
        }

        @keyframes app-bar-indeterminate {
            0% { left: -50%; }
            50% { left: 40%; }
            100% { left: 100%; }
        }

        /* Live Cyber Timer Badge */
        .app-loader-cyber-timer {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 4px 12px;
            border-radius: 20px;
            background: rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.06);
            font-size: 11px;
            color: var(--text-muted, #8c8c8c);
        }

        html[data-theme="dark"] .app-loader-cyber-timer {
            background: rgba(255, 255, 255, 0.06);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .live-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #52c41a;
            box-shadow: 0 0 8px #52c41a;
            animation: live-blink 1.2s ease-in-out infinite;
        }

        @keyframes live-blink {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.3; transform: scale(0.7); }
        }
            color: var(--text-muted, #8c8c8c);
            background: rgba(0, 0, 0, 0.04);
            padding: 3px 10px;
            border-radius: 12px;
        }

        html[data-theme="dark"] .app-loader-timer {
            background: rgba(255, 255, 255, 0.08);
        }

        /* Universal Spinner */
        .spinner, .app-spinner-sm {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(24, 144, 255, 0.25);
            border-radius: 50%;
            border-top-color: #1890ff;
            animation: app-spin 0.75s linear infinite;
            vertical-align: middle;
            box-sizing: border-box;
        }
        .btn-primary .spinner, .btn-success .spinner, .btn-warning .spinner, .btn-danger .spinner {
            border-color: rgba(255, 255, 255, 0.35);
            border-top-color: #ffffff;
        }

        /* ========================================================
           TABLE & PANEL CENTER HOURGLASS LOADER (SMOOTH & RESPONSIVE)
           ======================================================== */
        .tab-table-loader {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            min-height: 220px;
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: blur(2.5px);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 35;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 0.2s cubic-bezier(0.4, 0, 0.2, 1), visibility 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        html[data-theme="dark"] .tab-table-loader {
            background: rgba(20, 24, 33, 0.88);
        }
        .tab-table-loader.active {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }
        .tab-table-loader-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 18px 28px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            text-align: center;
            min-width: 210px;
        }
        html[data-theme="dark"] .tab-table-loader-card {
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.45);
        }
        .hourglass-wrap {
            position: relative;
            width: 46px;
            height: 46px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 8px;
        }
        .hourglass-ring {
            position: absolute;
            inset: 0;
            border-radius: 50%;
            border: 2.5px solid transparent;
            border-top-color: var(--primary);
            border-right-color: #13c2c2;
            animation: app-spin 0.9s cubic-bezier(0.45, 0.05, 0.55, 0.95) infinite;
        }
        .hourglass-icon {
            font-size: 22px;
            display: inline-block;
            animation: hourglass-flip 1.4s cubic-bezier(0.65, 0, 0.35, 1) infinite;
            filter: drop-shadow(0 2px 5px rgba(24, 144, 255, 0.35));
            user-select: none;
        }
        @keyframes hourglass-flip {
            0% { transform: rotate(0deg) scale(1); }
            45% { transform: rotate(180deg) scale(1.12); }
            50% { transform: rotate(180deg) scale(1); }
            95% { transform: rotate(360deg) scale(1.12); }
            100% { transform: rotate(360deg) scale(1); }
        }
        .tab-loader-text {
            font-size: 11.5px;
            font-weight: 700;
            color: var(--primary);
            margin-top: 2px;
            letter-spacing: 0.2px;
        }
        .tab-loader-subtext {
            font-size: 9.5px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        /* Workspace Full Overlay Loader (Saat Buka Dokumen AJU) */
        .workspace-loader-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.90);
            backdrop-filter: blur(3.5px);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 99;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 0.22s ease, visibility 0.22s ease;
        }
        html[data-theme="dark"] .workspace-loader-overlay {
            background: rgba(17, 24, 39, 0.92);
        }
        .workspace-loader-overlay.active {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }
        .workspace-loader-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px 34px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 10px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
            min-width: 290px;
            text-align: center;
        }
        html[data-theme="dark"] .workspace-loader-card {
            box-shadow: 0 10px 32px rgba(0, 0, 0, 0.5);
        }

        /* Context Menu (DataWindow & Excel Interop Style) */
        .context-menu {
            position: fixed;
            z-index: 99999;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 6px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.22);
            min-width: 220px;
            padding: 4px 0;
            font-size: 11px;
            display: none;
            backdrop-filter: blur(4px);
        }
        .context-menu-header {
            padding: 5px 12px;
            font-size: 9.5px;
            font-weight: 700;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 2px;
        }
        .context-menu-item {
            padding: 6px 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-main);
            transition: all .12s;
            user-select: none;
        }
        .context-menu-item:hover {
            background: var(--primary);
            color: #ffffff;
        }
        .context-menu-item .menu-icon {
            font-size: 13px;
            width: 16px;
            text-align: center;
            flex-shrink: 0;
        }
        .context-menu-divider {
            height: 1px;
            background: var(--border);
            margin: 3px 0;
        }

        /* ========================================================
           DROPDOWN MENU KONFIGURASI (GROUPED ACTION BAR)
           ======================================================== */
        .config-dropdown-container {
            position: relative;
            display: inline-block;
        }
        .config-dropdown-menu {
            position: absolute;
            top: calc(100% + 5px);
            left: 0;
            width: 310px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.18);
            padding: 6px;
            z-index: 1000;
            display: none;
            opacity: 0;
            transform: translateY(-5px);
            transition: opacity 0.15s ease, transform 0.15s ease;
        }
        html[data-theme="dark"] .config-dropdown-menu {
            background: #18202f;
            border-color: #2b384e;
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.65);
        }
        .config-dropdown-menu.show {
            display: block;
            opacity: 1;
            transform: translateY(0);
        }
        .config-dropdown-header {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            padding: 6px 9px 3px 9px;
        }
        .config-dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 7px 9px;
            border-radius: 6px;
            color: var(--text-main);
            text-decoration: none;
            cursor: pointer;
            transition: background 0.15s ease;
            user-select: none;
        }
        .config-dropdown-item:hover {
            background: rgba(24, 144, 255, 0.08);
            color: var(--primary);
        }
        html[data-theme="dark"] .config-dropdown-item:hover {
            background: rgba(24, 144, 255, 0.16);
        }
        .config-dropdown-icon {
            font-size: 15px;
            width: 20px;
            text-align: center;
            flex-shrink: 0;
        }
        .config-dropdown-text {
            flex: 1;
            min-width: 0;
        }
        .config-dropdown-title {
            font-size: 11px;
            font-weight: 600;
            line-height: 1.3;
        }
        .config-dropdown-desc {
            font-size: 9.5px;
            color: var(--text-muted);
            line-height: 1.25;
            margin-top: 1px;
        }
        .config-dropdown-badge {
            font-size: 9px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 10px;
            letter-spacing: 0.3px;
        }
        .config-badge-on {
            background: #e6f7ff;
            color: #1890ff;
            border: 1px solid rgba(24, 144, 255, 0.3);
        }
        html[data-theme="dark"] .config-badge-on {
            background: rgba(24, 144, 255, 0.2);
            color: #40a9ff;
        }
        .config-badge-off {
            background: rgba(140, 140, 140, 0.15);
            color: var(--text-muted);
            border: 1px solid var(--border);
        }
        .config-dropdown-divider {
            height: 1px;
            background: var(--border);
            margin: 4px 6px;
        }

        /* ========================================================
           FLOATING REKAP TARIF WINDOW & LAUNCHER PILL
           ======================================================== */
        .floating-summary-window {
            position: fixed;
            bottom: 24px;
            right: 28px;
            width: 460px;
            max-width: calc(100vw - 40px);
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 10px;
            box-shadow: 0 12px 36px rgba(0, 0, 0, 0.20), 0 2px 8px rgba(0, 0, 0, 0.08);
            z-index: 1050;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            animation: float-slide-up 0.22s cubic-bezier(0.16, 1, 0.3, 1);
        }
        html[data-theme="dark"] .floating-summary-window {
            background: #182232;
            border-color: #2b3a4e;
            box-shadow: 0 14px 42px rgba(0, 0, 0, 0.65);
        }
        @keyframes float-slide-up {
            from {
                opacity: 0;
                transform: translateY(16px) scale(0.97);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        .floating-summary-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 12px;
            background: var(--bg-input);
            border-bottom: 1px solid var(--border);
            cursor: move;
            user-select: none;
        }
        .floating-summary-title {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11.5px;
            font-weight: 700;
            color: var(--primary);
        }
        .drag-handle {
            font-size: 13px;
            color: var(--text-muted);
            cursor: grab;
            line-height: 1;
        }
        .floating-summary-actions {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .btn-float-action {
            background: transparent;
            border: none;
            cursor: pointer;
            font-size: 12px;
            color: var(--text-muted);
            padding: 2px 6px;
            border-radius: 4px;
            line-height: 1.2;
            transition: background 0.15s, color 0.15s;
        }
        .btn-float-action:hover {
            background: rgba(0, 0, 0, 0.08);
            color: var(--text-main);
        }
        html[data-theme="dark"] .btn-float-action:hover {
            background: rgba(255, 255, 255, 0.12);
        }
        .floating-summary-body {
            padding: 10px 12px;
            max-height: 320px;
            overflow-y: auto;
            font-size: 11px;
        }
        .summary-grand-total {
            display: flex;
            justify-content: space-between;
            background: rgba(24, 144, 255, 0.06);
            border: 1px solid rgba(24, 144, 255, 0.2);
            border-radius: 6px;
            padding: 7px 12px;
            margin-bottom: 8px;
            font-size: 11px;
        }
        html[data-theme="dark"] .summary-grand-total {
            background: rgba(24, 144, 255, 0.12);
            border-color: rgba(24, 144, 255, 0.3);
        }
        .summary-pungutan-grid {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .summary-pungutan-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--bg-base);
            border: 1px solid var(--border);
            border-radius: 5px;
            padding: 5px 8px;
            font-size: 10.5px;
        }
        .summary-code-badge {
            font-weight: 700;
            color: var(--primary);
            min-width: 80px;
        }
        .summary-val-wrap {
            display: flex;
            gap: 10px;
            font-variant-numeric: tabular-nums;
        }

        /* Floating Launcher Pill */
        .floating-summary-pill {
            position: fixed;
            bottom: 24px;
            right: 28px;
            z-index: 1040;
            display: flex;
            align-items: center;
            gap: 7px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 24px;
            padding: 7px 14px;
            box-shadow: 0 6px 18px rgba(24, 144, 255, 0.4);
            cursor: pointer;
            font-family: inherit;
            font-size: 11px;
            font-weight: 600;
            transition: transform 0.15s, box-shadow 0.15s, background-color 0.15s;
            user-select: none;
        }
        .floating-summary-pill:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 22px rgba(24, 144, 255, 0.5);
            background: #40a9ff;
        }
        .pill-icon {
            font-size: 13px;
        }
        .pill-badge {
            background: rgba(255, 255, 255, 0.22);
            padding: 1px 7px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.2px;
        }

        /* Modal Validasi Mandatory Swal2 Compact Styling */
        div.swal2-popup.swal2-mandatory-modal {
            width: 640px !important;
            max-width: 95vw !important;
            padding: 16px 22px 18px 22px !important;
            border-radius: 8px !important;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3) !important;
        }
        div.swal2-popup.swal2-mandatory-modal .swal2-icon {
            width: 48px !important;
            height: 48px !important;
            margin: 4px auto 10px auto !important;
            border-width: 2.5px !important;
        }
        div.swal2-popup.swal2-mandatory-modal .swal2-icon .swal2-icon-content {
            font-size: 26px !important;
            line-height: 48px !important;
        }
        div.swal2-popup.swal2-mandatory-modal .swal2-title {
            padding: 0 !important;
            margin: 0 0 8px 0 !important;
            line-height: 1.25 !important;
        }
        div.swal2-popup.swal2-mandatory-modal .swal2-html-container {
            padding: 0 !important;
            margin: 0 !important;
        }
        div.swal2-popup.swal2-mandatory-modal .swal2-actions {
            margin: 12px 0 0 0 !important;
            gap: 8px !important;
        }
        div.swal2-popup.swal2-mandatory-modal .swal2-actions button {
            font-size: 11px !important;
            font-weight: 600 !important;
            padding: 6px 14px !important;
            border-radius: 4px !important;
        }

    </style>
</head>
<?php 
$isEmbedded = (!empty($_GET['embedded']) && $_GET['embedded'] == '1'); 
$isEditMode = !empty($selectedAjuParam);
?>
<body data-theme="light" class="filter-cols-active filter-tabs-active <?= $isEmbedded ? 'is-embedded' : '' ?> <?= $isAdmin ? 'is-admin' : '' ?>" data-is-admin="<?= $isAdmin ? '1' : '0' ?>">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="topbar-left">
            <button type="button" class="btn btn-sm btn-back-host" id="btnBackToTransferLeft" onclick="backToHostToHost()" title="Kembali ke halaman Data Host to Host ke posisi baris yang sama">
                ◀ Kembali
            </button>
            <div class="brand">
                <svg style="width:20px;height:20px;color:var(--primary);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                Editor Dokumen Pabean
            </div>
            <div class="aju-selector" style="display:none;">
                <select id="selectAju" onchange="changeAju(this.value)" style="min-width:300px; font-weight:700;">
                    <option value="" <?= empty($selectedAjuParam) ? 'selected' : '' ?>>-- Form Kosong (Dokumen Baru) --</option>
                    <?php if (!empty($ajuList)): ?>
                        <?php foreach ($ajuList as $a): ?>
                            <option value="<?= htmlspecialchars($a['nomor_aju']) ?>" <?= (!empty($selectedAjuParam) && $selectedAjuParam === $a['nomor_aju']) ? 'selected' : '' ?>>
                                [BC <?= htmlspecialchars($a['kode_dokumen']) ?>] <?= htmlspecialchars($a['nomor_aju']) ?> - <?= htmlspecialchars($a['nama_perusahaan']) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <button class="btn btn-secondary btn-sm" onclick="reloadCurrentAju()">🔄</button>
            </div>
            <!-- Setup AJU Baru & ERP Integration Buttons -->
            <button id="btnToggleEntry" class="btn btn-warning btn-sm <?= $isEditMode ? '' : 'active' ?>" onclick="toggleEntryPanel()" style="<?= $isEditMode ? 'display:none;' : '' ?>">
                <?= $isEditMode ? '✨ Setup AJU Baru' : '✕ Tutup Setup' ?>
            </button>
            <button id="btnTopErp" class="btn btn-erp btn-sm" onclick="openErpModal()" style="<?= $isErpConfigured ? '' : 'display:none;' ?>">
                🔌 Integrasi ERP
            </button>
            <button id="btnTopAiReader" class="btn btn-ai-reader btn-sm" onclick="openAiReaderModal()" title="AI Reader Dokumen Pabean (PDF/Word/Excel/Gambar)">
                🤖 AI Reader
            </button>

            <!-- DROPDOWN MENU KONFIGURASI (GROUPED ACTION BAR) -->
            <div class="config-dropdown-container" id="dropdownConfigMenu">
                <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" id="btnConfigDropdown" onclick="toggleConfigDropdown(event)" title="Pengaturan & Konfigurasi Dokumen">
                    ⚙️ Konfigurasi <span style="font-size:8px;margin-left:3px;">▼</span>
                </button>
                <div class="config-dropdown-menu" id="configDropdownPanel">
                    <div class="config-dropdown-header">
                        ⚙️ Standar Dokumen
                    </div>
                    <div class="config-dropdown-item" onclick="openConfigAjuModal(); closeConfigDropdown();">
                        <span class="config-dropdown-icon">⚙️</span>
                        <div class="config-dropdown-text">
                            <div class="config-dropdown-title">Konfig AJU</div>
                            <div class="config-dropdown-desc">Atur default kantor, valuta, & profil AJU</div>
                        </div>
                    </div>

                    <div class="config-dropdown-divider"></div>
                    <div class="config-dropdown-header">
                        👁️ Saringan Tampilan
                    </div>
                    <div class="config-dropdown-item" id="btnToggleCols" onclick="toggleColumnFilter();">
                        <span class="config-dropdown-icon">👁️</span>
                        <div class="config-dropdown-text">
                            <div class="config-dropdown-title" id="dropTitleCols">Saring Kolom: Aktif</div>
                            <div class="config-dropdown-desc">Sembunyikan kolom tidak relevan (varheader)</div>
                        </div>
                        <span class="config-dropdown-badge config-badge-on" id="dropBadgeCols">ON</span>
                    </div>
                    <div class="config-dropdown-item" id="btnToggleTabs" onclick="toggleTabFilter();">
                        <span class="config-dropdown-icon">📑</span>
                        <div class="config-dropdown-text">
                            <div class="config-dropdown-title" id="dropTitleTabs">Saring Tab BC: Aktif</div>
                            <div class="config-dropdown-desc">Batasi tab sesuai jenis dokumen BC</div>
                        </div>
                        <span class="config-dropdown-badge config-badge-on" id="dropBadgeTabs">ON</span>
                    </div>

                    <div class="config-dropdown-divider"></div>
                    <div class="config-dropdown-header">
                        🛠️ Kelola Aturan (Global & Perusahaan)
                    </div>
                    <div class="config-dropdown-item" onclick="openFilterSettingsModal('tab'); closeConfigDropdown();">
                        <span class="config-dropdown-icon">📑</span>
                        <div class="config-dropdown-text">
                            <div class="config-dropdown-title">Atur Tab</div>
                            <div class="config-dropdown-desc">Kelola visibilitas tab per jenis dokumen BC</div>
                        </div>
                    </div>
                    <div class="config-dropdown-item" onclick="openFilterSettingsModal('kolom'); closeConfigDropdown();">
                        <span class="config-dropdown-icon">📋</span>
                        <div class="config-dropdown-text">
                            <div class="config-dropdown-title">Atur Kolom</div>
                            <div class="config-dropdown-desc">Pilih kolom aktif (Global & Per Perusahaan)</div>
                        </div>
                    </div>
                    <div class="config-dropdown-item" onclick="openFilterSettingsModal('desimal'); closeConfigDropdown();">
                        <span class="config-dropdown-icon">🔢</span>
                        <div class="config-dropdown-text">
                            <div class="config-dropdown-title">Atur Desimal</div>
                            <div class="config-dropdown-desc">Konfigurasi jumlah koma angka & tarif</div>
                        </div>
                    </div>
                </div>
            </div>

            <button class="btn btn-danger btn-sm" id="btnTopDeleteAju" onclick="deleteCurrentAju()" title="Hapus Semua Data Staging Dokumen AJU Ini" style="background:#ff4d4f; border-color:#ff4d4f; color:#fff;">
                🗑️ Hapus AJU
            </button>
        </div>
        <div style="display:flex; gap:8px; align-items:center;">
            <!-- Theme Switcher -->
            <div class="theme-select">
                <button class="theme-btn" data-theme="dark" onclick="setTheme('dark')">🌙 Dark</button>
                <button class="theme-btn" data-theme="light" onclick="setTheme('light')">☀️ Light</button>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" onclick="downloadCeisaExcel()" title="Unduh file Excel 21 sheet resmi CEISA 4.0">📥 Unduh Excel</button>
            <button class="btn btn-secondary btn-sm" onclick="previewJson()">👁️ Preview JSON</button>
            <button class="btn btn-success btn-sm" onclick="saveCurrentTab()">💾 Simpan Perubahan</button>
            <button class="btn btn-primary btn-sm" id="btnTopTransferCeisa" onclick="transferCeisa()">🚀 Transfer ke CEISA</button>
        </div>
    </div>

    <!-- META BANNER -->
    <div class="meta-banner">
        <div style="display:flex; gap:16px; align-items:center;">
            <div class="meta-item">
                <span class="meta-label">Nomor AJU:</span>
                <span class="meta-val" id="metaAju">-</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Dokumen:</span>
                <span class="badge badge-bc" id="metaBc">-</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Perusahaan:</span>
                <span class="meta-val" id="metaCmp">-</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Tgl Pernyataan:</span>
                <span class="meta-val" id="metaTgl">-</span>
            </div>
        </div>
        <div style="display:flex; gap:12px; align-items:center;">
            <span id="metaFilterStatus" style="font-size:11px; color:var(--text-muted);">Saring: Kolom & Tab Aktif</span>
            <span id="metaPostedBadge" class="badge" style="background:var(--border); color:var(--text-muted);">Status Memuat...</span>
        </div>
    </div>

    <!-- IT INVENTORY LOCKED BANNER -->
    <div id="itInventoryLockBanner" style="display:none; background:#ff4d4f; color:#ffffff; padding:6px 14px; font-weight:600; font-size:11px; align-items:center; gap:8px; border-bottom:1px solid #cf1322;">
        <span style="font-size:14px;">🔒</span>
        <span style="flex:1;"><strong>DOKUMEN TERKUNCI (IT INVENTORY):</strong> Dokumen ini sudah masuk ke IT Inventory dan berstatus <em>Read-Only</em>. Tombol Simpan &amp; Transfer CEISA dinonaktifkan. Lakukan <em>Unposting</em> di menu Daftar Dokumen jika ingin membuka kunci.</span>
    </div>

    <!-- EDITOR MAIN CONTAINER (Split Layout: Left Sidebar Entry + Right Content Area) -->
    <div class="editor-main-container">
        <!-- LEFT SIDEBAR: ENTRY AJU BARU & INTEGRASI ERP (Persis PowerBuilder w_bc_ceisa40_interface2) -->
        <aside class="sidebar-entry <?= $isEditMode ? 'collapsed' : '' ?>" id="sidebarEntry" style="<?= $isEditMode ? 'display:none;' : '' ?>">
            <div class="sidebar-entry-header">
                <span>📋 Setup AJU Baru & ERP</span>
                <button type="button" class="btn btn-secondary btn-xs" onclick="toggleEntryPanel()" title="Tutup Panel">✕</button>
            </div>

            <!-- CARD 1: PILIH JENIS DOKUMEN -->
            <div class="entry-card">
                <div class="entry-card-title">
                    <span>1. Jenis Dokumen BC</span>
                </div>
                <select id="entryDocBc" onchange="onEntryBcChange(this.value)" style="font-weight:700; color:var(--primary);">
                    <?php 
                    $firstBc = true;
                    if (!empty($allowedBcInbound)): ?>
                    <optgroup label="── PEMASUKAN (INBOUND) ──">
                        <?php foreach ($allowedBcInbound as $bcCode => $bcLabel): ?>
                            <option value="<?= htmlspecialchars($bcCode) ?>" <?= $firstBc ? 'selected' : '' ?>><?= htmlspecialchars($bcLabel) ?></option>
                        <?php 
                            $firstBc = false;
                        endforeach; ?>
                    </optgroup>
                    <?php endif; ?>
                    <?php if (!empty($allowedBcOutbound)): ?>
                    <optgroup label="── PENGELUARAN (OUTBOUND) ──">
                        <?php foreach ($allowedBcOutbound as $bcCode => $bcLabel): ?>
                            <option value="<?= htmlspecialchars($bcCode) ?>" <?= $firstBc ? 'selected' : '' ?>><?= htmlspecialchars($bcLabel) ?></option>
                        <?php 
                            $firstBc = false;
                        endforeach; ?>
                    </optgroup>
                    <?php endif; ?>
                </select>
                <div style="display:flex; gap:4px; margin-top:2px;">
                    <button type="button" class="btn btn-secondary btn-xs" style="flex:1;" onclick="openConfigAjuModal()">⚙️ Konfig AJU</button>
                    <button type="button" class="btn btn-secondary btn-xs" style="flex:1;" onclick="resetEntryDraft()">🔄 Reset</button>
                </div>
            </div>

            <!-- CARD 2: NDPBM (KURS PAJAK BEA CUKAI) -->
            <div class="entry-card">
                <div class="entry-card-title">
                    <span>2. Tarik Kurs NDPBM</span>
                    <span id="ndpbmSourceBadge" style="font-size:8px; color:var(--primary);">CEISA API</span>
                </div>
                <div style="display:flex; flex-direction:column; gap:4px;">
                    <div>
                        <label style="font-size:9px; color:var(--text-muted); display:block; margin-bottom:2px;">Tgl Kurs:</label>
                        <input type="date" id="entryNdpbmDate" value="<?= date('Y-m-d') ?>" style="width:100%;">
                    </div>
                    <div style="display:flex; gap:4px;">
                        <div style="flex:1;">
                            <label style="font-size:9px; color:var(--text-muted); display:block; margin-bottom:2px;">Valuta:</label>
                            <select id="entryValuta" onchange="fetchLiveNdpbm(this.value)" style="width:100%; font-weight:700;">
                                <option value="USD" selected>USD ($)</option>
                                <option value="IDR">IDR (Rp)</option>
                                <option value="EUR">EUR (€)</option>
                                <option value="SGD">SGD (S$)</option>
                                <option value="JPY">JPY (¥)</option>
                                <option value="CNY">CNY (¥)</option>
                                <option value="GBP">GBP (£)</option>
                                <option value="AUD">AUD (A$)</option>
                                <option value="KRW">KRW (₩)</option>
                                <option value="MYR">MYR (RM)</option>
                                <option value="HKD">HKD (HK$)</option>
                            </select>
                        </div>
                        <div style="flex:1.4;">
                            <label style="font-size:9px; color:var(--text-muted); display:block; margin-bottom:2px;">Nilai NDPBM:</label>
                            <input type="text" inputmode="decimal" id="entryNdpbmVal" value="16,250.00" class="input-number" style="width:100%; font-weight:700; color:var(--success); text-align:right;">
                        </div>
                    </div>
                    <button type="button" class="btn btn-secondary btn-xs" id="btnFetchNdpbm" onclick="fetchLiveNdpbm()" style="margin-top:2px;">
                        ⚡ Ambil Kurs dari Bea Cukai
                    </button>
                </div>
            </div>

            <!-- CARD 3: BUAT DOKUMEN BARU -->
            <div class="entry-card" style="border-color:rgba(16, 185, 129, 0.4); background:rgba(16, 185, 129, 0.03);">
                <div class="entry-card-title" style="color:var(--success);">
                    <span>3. Eksekusi AJU Baru</span>
                </div>
                <button type="button" class="btn btn-success btn-block" id="btnExecNewAju" onclick="executeCreateNewAju()" style="padding:8px 10px; font-weight:700;">
                    📄 Buat Dokumen Baru
                </button>
                <div style="font-size:8.5px; color:var(--text-muted); line-height:1.3; text-align:center;">
                    Menyusun TAB & KOLOM sesuai jenis dokumen BC, serta generate nomor AJU 26 digit resmi.
                </div>
            </div>

            <!-- CARD 4: NOMOR AJU TERBIT -->
            <div class="entry-card">
                <div class="entry-card-title">
                    <span>Nomor AJU Terbit (26 Digit)</span>
                    <button type="button" class="btn btn-secondary btn-xs" onclick="copyAjuText()" title="Salin Nomor AJU">📋</button>
                </div>
                <div class="entry-live-preview" id="entryAjuDisplay">
                    - Silakan Buat Dokumen -
                </div>
            </div>

            <!-- CARD 5: INTEGRASI DATABASE ERP (PERSIS POWERBUILDER) -->
            <div class="entry-card" id="cardSidebarErp" style="border-color:rgba(99, 102, 241, 0.4); background:rgba(99, 102, 241, 0.04); <?= $isErpConfigured ? '' : 'display:none;' ?>">
                <div class="entry-card-title" style="color:#6366f1;">
                    <span>4. Integrasi ERP</span>
                    <span id="sidebarDriverBadge" class="badge" style="background:#6366f1; color:#fff; font-size:8px;">Memuat...</span>
                </div>
                <button type="button" class="btn btn-primary btn-block btn-erp" id="btnSidebarErp" onclick="openErpModal()" style="padding:8px 10px;">
                    🔌 INTEGRASI DATABASE ERP
                </button>
                <div style="font-size:8.5px; color:var(--text-muted); line-height:1.3; text-align:center;">
                    Tarik data barang, dokumen, dan kemasan secara otomatis dari database ERP aktif.
                </div>
                <button type="button" class="btn btn-secondary btn-block btn-xs" onclick="openExcelImport()" style="margin-top:2px;">
                    📊 Load Excel ERP
                </button>
            </div>

            <!-- CARD 6: AI READER DOKUMEN (INVOICE / BL / PACKING LIST) -->
            <div class="entry-card" id="cardSidebarAiReader" style="border-color:rgba(139, 92, 246, 0.4); background:rgba(139, 92, 246, 0.04);">
                <div class="entry-card-title" style="color:#8b5cf6;">
                    <span>5. AI Document Reader</span>
                    <span id="sidebarAiBadge" class="badge" style="background:#8b5cf6; color:#fff; font-size:8px;">Ready</span>
                </div>
                <button type="button" class="btn btn-primary btn-block btn-ai-reader" id="btnSidebarAiReader" onclick="openAiReaderModal()" style="padding:8px 10px;">
                    🤖 AI READER DOKUMEN
                </button>
                <div style="font-size:8.5px; color:var(--text-muted); line-height:1.3; text-align:center;">
                    Ekstrak Invoice, B/L, & PL (PDF / Word / Excel / Gambar) langsung ke Staging Pabean.
                </div>
                <button type="button" class="btn btn-secondary btn-block btn-xs" onclick="openAiConfigModal()" style="margin-top:2px;">
                    ⚙️ Pengaturan AI
                </button>
            </div>
        </aside>

        <!-- RIGHT CONTENT AREA -->
        <div class="editor-content-area">
            <!-- TAB NAVIGATION BAR -->
            <div class="tab-bar">
                <button class="tab-btn active" data-tab="header" onclick="switchTab('header')">📋 Header</button>
                <button class="tab-btn" data-tab="entitas" onclick="switchTab('entitas')">🏢 Entitas <span class="tab-count" id="count-entitas">0</span></button>
                <button class="tab-btn" data-tab="dokumen" onclick="switchTab('dokumen')">📄 Dokumen <span class="tab-count" id="count-dokumen">0</span></button>
                <button class="tab-btn" data-tab="barang" onclick="switchTab('barang')">📦 Barang <span class="tab-count" id="count-barang">0</span></button>
                <button class="tab-btn" data-tab="bahanbaku" onclick="switchTab('bahanbaku')">🏭 Bahan Baku <span class="tab-count" id="count-bahanbaku">0</span></button>
                <button class="tab-btn" data-tab="barangtarif" onclick="switchTab('barangtarif')">🏷️ Barang Tarif <span class="tab-count" id="count-barangtarif">0</span></button>
                <button class="tab-btn" data-tab="bahanbakutarif" onclick="switchTab('bahanbakutarif')">🏷️ Bahan Baku Tarif <span class="tab-count" id="count-bahanbakutarif">0</span></button>
                <button class="tab-btn" data-tab="kemasan" onclick="switchTab('kemasan')">📦 Kemasan <span class="tab-count" id="count-kemasan">0</span></button>
                <button class="tab-btn" data-tab="pengangkut" onclick="switchTab('pengangkut')">🚢 Pengangkut <span class="tab-count" id="count-pengangkut">0</span></button>
                <button class="tab-btn" data-tab="kontainer" onclick="switchTab('kontainer')">🚛 Kontainer <span class="tab-count" id="count-kontainer">0</span></button>
                <button class="tab-btn" data-tab="pungutan" onclick="switchTab('pungutan')">💰 Pungutan <span class="tab-count" id="count-pungutan">0</span></button>
                <button class="tab-btn" data-tab="bankdevisa" onclick="switchTab('bankdevisa')">🏦 Bank Devisa <span class="tab-count" id="count-bankdevisa">0</span></button>
                <button class="tab-btn" data-tab="jaminan" onclick="switchTab('jaminan')">🛡️ Jaminan <span class="tab-count" id="count-jaminan">0</span></button>
                <button class="tab-btn" data-tab="komponenbiaya" onclick="switchTab('komponenbiaya')">💰 Biaya <span class="tab-count" id="count-komponenbiaya">0</span></button>
                <button class="tab-btn" data-tab="barangdokumen" onclick="switchTab('barangdokumen')">📑 Barang Dokumen <span class="tab-count" id="count-barangdokumen">0</span></button>
                <button class="tab-btn" data-tab="barangentitas" onclick="switchTab('barangentitas')">👥 Barang Entitas <span class="tab-count" id="count-barangentitas">0</span></button>
                <button class="tab-btn" data-tab="barangspekkhusus" onclick="switchTab('barangspekkhusus')">🔬 Spek Khusus <span class="tab-count" id="count-barangspekkhusus">0</span></button>
                <button class="tab-btn" data-tab="barangvd" onclick="switchTab('barangvd')">⚖️ Nilai VD <span class="tab-count" id="count-barangvd">0</span></button>
                <button class="tab-btn" data-tab="bahanbakudokumen" onclick="switchTab('bahanbakudokumen')">📑 BB Dokumen <span class="tab-count" id="count-bahanbakudokumen">0</span></button>
                <button class="tab-btn" data-tab="kesiapanbarang" onclick="switchTab('kesiapanbarang')">⏱️ Kesiapan Barang</button>
            </div>

    <!-- WORKSPACE PANELS -->
    <div class="workspace">

        <!-- WORKSPACE OVERLAY LOADER (Saat Buka / Pindah Dokumen AJU) -->
        <div id="workspaceAjuLoader" class="workspace-loader-overlay">
            <div class="workspace-loader-card">
                <div class="hourglass-wrap" style="width:52px;height:52px;margin-bottom:12px;">
                    <div class="hourglass-ring" style="border-width:3px;"></div>
                    <div class="hourglass-icon" style="font-size:26px;">⏳</div>
                </div>
                <div class="tab-loader-text" id="wsLoaderTitle" style="font-size:13px; font-weight:700; color:var(--primary);">Memuat Dokumen Pabean...</div>
                <div class="tab-loader-subtext" id="wsLoaderDesc" style="font-size:11px; margin-top:4px; color:var(--text-muted);">Menghubungkan ke database & memformat staging AJU...</div>
                <div class="app-loader-progress-track" style="max-width:200px; margin-top:12px; height:3px;">
                    <div class="app-loader-progress-bar"></div>
                </div>
            </div>
        </div>

        <!-- 1. TAB HEADER -->
        <div class="panel active" id="panel-header">
            <div class="form-grid">
                <!-- Kolom 1: Identitas & Nilai Transaksi -->
                <div class="form-col">
                    <!-- S1: Identitas & Pendaftaran Dokumen -->
                    <div class="form-section">
                        <div class="section-title">📄 Identitas & Pendaftaran Dokumen</div>
                        <div class="field-group" data-col="nomor_aju">
                            <label>Nomor AJU (26 Digit)</label>
                            <input type="text" id="hdr_nomor_aju" readonly style="font-weight:700; color:var(--primary);">
                        </div>
                        <div class="field-row">
                            <div class="field-group" data-col="kode_dokumen">
                                <label>Kode Dokumen BC</label>
                                <input type="text" id="hdr_kode_dokumen">
                            </div>
                            <div class="field-group" data-col="kode_kantor">
                                <label>Kode Kantor Pabean <span class="reff-badge" onclick="openReffLookup('KANTOR', 'hdr_kode_kantor')">KANTOR 🔍</span></label>
                                <div class="input-group-reff">
                                    <input type="text" id="hdr_kode_kantor" list="dl_reff_KANTOR">
                                    <button type="button" class="btn-lookup" onclick="openReffLookup('KANTOR', 'hdr_kode_kantor')">🔍</button>
                                </div>
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="field-group" data-col="nomor_daftar">
                                <label>Nomor Pendaftaran</label>
                                <input type="text" id="hdr_nomor_daftar">
                            </div>
                            <div class="field-group" data-col="tanggal_daftar">
                                <label>Tanggal Pendaftaran</label>
                                <div class="input-date-group">
                                    <input type="text" id="hdr_tanggal_daftar" class="hdr-date-input" placeholder="" maxlength="10" title="Format: yyyy-mm-dd">
                                    <input type="date" class="hdr-date-native" tabindex="-1">
                                    <button type="button" class="btn-hdr-date" onclick="triggerHdrDatePicker(this)">📅</button>
                                </div>
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="field-group" data-col="nomor_bc11">
                                <label style="display: flex; justify-content: space-between; align-items: center;">
                                    <span>Nomor BC 1.1</span>
                                    <button type="button" class="btn-hdr-ue-bc11" onclick="triggerUeBc11()" style="height: 18px; padding: 0 7px; font-size: 10px; font-weight: 700; line-height: 1; border-radius: 3px; background: #2563eb; color: #fff; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 3px;" title="Ambil data Manifest BC 1.1 otomatis dari Host BL (PowerBuilder ue_bc11)">
                                        🔍 Get BC 1.1
                                    </button>
                                </label>
                                <input type="text" id="hdr_nomor_bc11">
                            </div>
                            <div class="field-group" data-col="tanggal_bc11">
                                <label>Tanggal BC 1.1</label>
                                <div class="input-date-group">
                                    <input type="text" id="hdr_tanggal_bc11" class="hdr-date-input" placeholder="" maxlength="10" title="Format: yyyy-mm-dd">
                                    <input type="date" class="hdr-date-native" tabindex="-1">
                                    <button type="button" class="btn-hdr-date" onclick="triggerHdrDatePicker(this)">📅</button>
                                </div>
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="field-group" data-col="nomor_pos">
                                <label>Nomor Pos BC 1.1</label>
                                <input type="text" id="hdr_nomor_pos">
                            </div>
                            <div class="field-group" data-col="nomor_sub_pos">
                                <label>Sub Pos BC 1.1</label>
                                <input type="text" id="hdr_nomor_sub_pos">
                            </div>
                        </div>
                        <div class="field-group" data-col="kode_tutup_pu">
                            <label>Kode Tutup PU <span class="reff-badge" onclick="openReffLookup('TUTUP PU', 'hdr_kode_tutup_pu')">TUTUP PU 🔍</span></label>
                            <select id="hdr_kode_tutup_pu" data-reff="TUTUP PU"></select>
                        </div>
                    </div>

                    <!-- S4: Nilai Transaksi & Pabean -->
                    <div class="form-section">
                        <div class="section-title">💵 Nilai Transaksi & Pabean</div>
                        <div class="field-row">
                            <div class="field-group" data-col="kode_valuta">
                                <label>Valuta <span class="reff-badge" onclick="openReffLookup('VALUTA', 'hdr_kode_valuta')">VALUTA 🔍</span></label>
                                <select id="hdr_kode_valuta" data-reff="VALUTA"></select>
                            </div>
                            <div class="field-group" data-col="ndpbm">
                                <label>NDPBM (Kurs)</label>
                                <input type="text" inputmode="decimal" id="hdr_ndpbm" class="input-number">
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="field-group" data-col="kode_incoterm">
                                <label>Incoterm <span class="reff-badge" onclick="openReffLookup('INCOTERM', 'hdr_kode_incoterm')">INCOTERM 🔍</span></label>
                                <select id="hdr_kode_incoterm" data-reff="INCOTERM"></select>
                            </div>
                            <div class="field-group" data-col="nilai_incoterm">
                                <label>Nilai Incoterm</label>
                                <input type="text" inputmode="decimal" id="hdr_nilai_incoterm" class="input-number">
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="field-group" data-col="fob">
                                <label>Nilai FOB</label>
                                <input type="text" inputmode="decimal" id="hdr_fob" class="input-number">
                            </div>
                            <div class="field-group" data-col="cif">
                                <label>Nilai CIF</label>
                                <input type="text" inputmode="decimal" id="hdr_cif" class="input-number">
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="field-group" data-col="asuransi">
                                <label>Nilai Asuransi</label>
                                <input type="text" inputmode="decimal" id="hdr_asuransi" class="input-number">
                            </div>
                            <div class="field-group" data-col="freight">
                                <label>Nilai Freight</label>
                                <input type="text" inputmode="decimal" id="hdr_freight" class="input-number">
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="field-group" data-col="harga_penyerahan">
                                <label>Harga Penyerahan</label>
                                <input type="text" inputmode="decimal" id="hdr_harga_penyerahan" class="input-number">
                            </div>
                            <div class="field-group" data-col="nilai_barang">
                                <label>Nilai Barang</label>
                                <input type="text" inputmode="decimal" id="hdr_nilai_barang" class="input-number">
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="field-group" data-col="kode_jasa_kena_pajak">
                                <label>Jasa Kena Pajak <span class="reff-badge" onclick="openReffLookup('JASA KENA PAJAK', 'hdr_kode_jasa_kena_pajak')">REFF 🔍</span></label>
                                <select id="hdr_kode_jasa_kena_pajak" data-reff="JASA KENA PAJAK"></select>
                            </div>
                            <div class="field-group" data-col="dasar_pengenaan_pajak">
                                <label>Dasar Pengenaan Pajak (DPP)</label>
                                <input type="text" inputmode="decimal" id="hdr_dasar_pengenaan_pajak" class="input-number">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Kolom 2: Prosedur & Berat/Pernyataan -->
                <div class="form-col">
                    <!-- S2: Prosedur, Jenis & Jalur Perdagangan -->
                    <div class="form-section">
                        <div class="section-title">⚖️ Prosedur & Kategori Kepabeanan</div>
                        <div class="field-row">
                            <div class="field-group" data-col="kode_cara_bayar">
                                <label>Cara Bayar <span class="reff-badge" onclick="openReffLookup('CARA BAYAR', 'hdr_kode_cara_bayar')">CARA BAYAR 🔍</span></label>
                                <select id="hdr_kode_cara_bayar" data-reff="CARA BAYAR"></select>
                            </div>
                            <div class="field-group" data-col="kode_cara_dagang">
                                <label>Cara Dagang <span class="reff-badge" onclick="openReffLookup('CARA DAGANG', 'hdr_kode_cara_dagang')">CARA DAGANG 🔍</span></label>
                                <select id="hdr_kode_cara_dagang" data-reff="CARA DAGANG"></select>
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="field-group" data-col="kode_jenis_impor">
                                <label>Jenis Impor <span class="reff-badge" onclick="openReffLookup('JENIS IMPOR', 'hdr_kode_jenis_impor')">JENIS IMPOR 🔍</span></label>
                                <select id="hdr_kode_jenis_impor" data-reff="JENIS IMPOR"></select>
                            </div>
                            <div class="field-group" data-col="kode_jenis_ekspor">
                                <label>Jenis Ekspor <span class="reff-badge" onclick="openReffLookup('JENIS EKSPOR', 'hdr_kode_jenis_ekspor')">JENIS EKSPOR 🔍</span></label>
                                <select id="hdr_kode_jenis_ekspor" data-reff="JENIS EKSPOR"></select>
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="field-group" data-col="kode_kategori_ekspor">
                                <label>Kategori Ekspor <span class="reff-badge" onclick="openReffLookup('KATEGORI EKSPOR', 'hdr_kode_kategori_ekspor')">KATEGORI EKSPOR 🔍</span></label>
                                <select id="hdr_kode_kategori_ekspor" data-reff="KATEGORI EKSPOR"></select>
                            </div>
                            <div class="field-group" data-col="kode_jenis_tpb">
                                <label>Jenis TPB <span class="reff-badge" onclick="openReffLookup('JENIS TPB', 'hdr_kode_jenis_tpb')">JENIS TPB 🔍</span></label>
                                <select id="hdr_kode_jenis_tpb" data-reff="JENIS TPB"></select>
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="field-group" data-col="kode_tujuan_pemasukan">
                                <label>Tujuan Pemasukan <span class="reff-badge" onclick="openReffLookup('TUJUAN PEMASUKAN', 'hdr_kode_tujuan_pemasukan')">TUJUAN PEMASUKAN 🔍</span></label>
                                <select id="hdr_kode_tujuan_pemasukan" data-reff="TUJUAN PEMASUKAN"></select>
                            </div>
                            <div class="field-group" data-col="kode_tujuan_pengeluaran">
                                <label>Tujuan Pengeluaran <span class="reff-badge" onclick="openReffLookup('TUJUAN PENGELUARAN', 'hdr_kode_tujuan_pengeluaran')">TUJUAN PENGELUARAN 🔍</span></label>
                                <select id="hdr_kode_tujuan_pengeluaran" data-reff="TUJUAN PENGELUARAN"></select>
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="field-group" data-col="kode_tujuan_pengiriman">
                                <label>Tujuan Pengiriman <span class="reff-badge" onclick="openReffLookup('TUJUAN PENGIRIMAN', 'hdr_kode_tujuan_pengiriman')">TUJUAN PENGIRIMAN 🔍</span></label>
                                <select id="hdr_kode_tujuan_pengiriman" data-reff="TUJUAN PENGIRIMAN"></select>
                            </div>
                            <div class="field-group" data-col="kode_lokasi_bayar">
                                <label>Lokasi Bayar <span class="reff-badge" onclick="openReffLookup('LOKASI BAYAR', 'hdr_kode_lokasi_bayar')">LOKASI BAYAR 🔍</span></label>
                                <select id="hdr_kode_lokasi_bayar" data-reff="LOKASI BAYAR"></select>
                            </div>
                        </div>
                    </div>

                    <!-- S5: Berat, Kemasan & Pernyataan -->
                    <div class="form-section">
                        <div class="section-title">⚖️ Berat & Pernyataan</div>
                        <div class="field-row-3">
                            <div class="field-group" data-col="bruto">
                                <label>Bruto (kg)</label>
                                <input type="text" inputmode="decimal" id="hdr_bruto" class="input-number">
                            </div>
                            <div class="field-group" data-col="netto">
                                <label>Netto (kg)</label>
                                <input type="text" inputmode="decimal" id="hdr_netto" class="input-number">
                            </div>
                            <div class="field-group" data-col="volume">
                                <label>Volume</label>
                                <input type="text" inputmode="decimal" id="hdr_volume" class="input-number">
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="field-group" data-col="nama_pernyataan">
                                <label>Nama Pernyataan (TTD)</label>
                                <input type="text" id="hdr_nama_pernyataan">
                            </div>
                            <div class="field-group" data-col="jabatan_pernyataan">
                                <label>Jabatan Pernyataan</label>
                                <input type="text" id="hdr_jabatan_pernyataan">
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="field-group" data-col="kota_pernyataan">
                                <label>Kota Pernyataan</label>
                                <input type="text" id="hdr_kota_pernyataan">
                            </div>
                            <div class="field-group" data-col="tanggal_pernyataan">
                                <label>Tanggal Pernyataan</label>
                                <div class="input-date-group">
                                    <input type="text" id="hdr_tanggal_pernyataan" class="hdr-date-input" placeholder="" maxlength="10" title="Format: yyyy-mm-dd">
                                    <input type="date" class="hdr-date-native" tabindex="-1">
                                    <button type="button" class="btn-hdr-date" onclick="triggerHdrDatePicker(this)">📅</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Kolom 3: Pelabuhan, Tempat & Jalur -->
                <div class="form-col">
                    <!-- S3: Pelabuhan, Tempat & Jalur -->
                    <div class="form-section">
                        <div class="section-title">⚓ Pelabuhan, Tempat & Jalur</div>
                        <div class="field-row">
                            <div class="field-group" data-col="kode_pelabuhan_muat">
                                <label>Pelabuhan Muat <span class="reff-badge" onclick="openReffLookup('PELABUHAN', 'hdr_kode_pelabuhan_muat')">PELABUHAN 🔍</span></label>
                                <div class="input-group-reff">
                                    <input type="text" id="hdr_kode_pelabuhan_muat" list="dl_reff_PELABUHAN">
                                    <button type="button" class="btn-lookup" onclick="openReffLookup('PELABUHAN', 'hdr_kode_pelabuhan_muat')">🔍</button>
                                </div>
                            </div>
                            <div class="field-group" data-col="kode_pelabuhan_bongkar">
                                <label>Pelabuhan Bongkar <span class="reff-badge" onclick="openReffLookup('PELABUHAN', 'hdr_kode_pelabuhan_bongkar')">PELABUHAN 🔍</span></label>
                                <div class="input-group-reff">
                                    <input type="text" id="hdr_kode_pelabuhan_bongkar" list="dl_reff_PELABUHAN">
                                    <button type="button" class="btn-lookup" onclick="openReffLookup('PELABUHAN', 'hdr_kode_pelabuhan_bongkar')">🔍</button>
                                </div>
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="field-group" data-col="kode_pelabuhan_transit">
                                <label>Pelabuhan Transit <span class="reff-badge" onclick="openReffLookup('PELABUHAN', 'hdr_kode_pelabuhan_transit')">PELABUHAN 🔍</span></label>
                                <div class="input-group-reff">
                                    <input type="text" id="hdr_kode_pelabuhan_transit" list="dl_reff_PELABUHAN">
                                    <button type="button" class="btn-lookup" onclick="openReffLookup('PELABUHAN', 'hdr_kode_pelabuhan_transit')">🔍</button>
                                </div>
                            </div>
                            <div class="field-group" data-col="kode_pelabuhan_tujuan">
                                <label>Pelabuhan Tujuan <span class="reff-badge" onclick="openReffLookup('PELABUHAN', 'hdr_kode_pelabuhan_tujuan')">PELABUHAN 🔍</span></label>
                                <div class="input-group-reff">
                                    <input type="text" id="hdr_kode_pelabuhan_tujuan" list="dl_reff_PELABUHAN">
                                    <button type="button" class="btn-lookup" onclick="openReffLookup('PELABUHAN', 'hdr_kode_pelabuhan_tujuan')">🔍</button>
                                </div>
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="field-group" data-col="kode_pelabuhan_ekspor">
                                <label>Pelabuhan Ekspor <span class="reff-badge" onclick="openReffLookup('PELABUHAN', 'hdr_kode_pelabuhan_ekspor')">PELABUHAN 🔍</span></label>
                                <div class="input-group-reff">
                                    <input type="text" id="hdr_kode_pelabuhan_ekspor" list="dl_reff_PELABUHAN">
                                    <button type="button" class="btn-lookup" onclick="openReffLookup('PELABUHAN', 'hdr_kode_pelabuhan_ekspor')">🔍</button>
                                </div>
                            </div>
                            <div class="field-group" data-col="kode_kantor_muat">
                                <label>Kantor Muat <span class="reff-badge" onclick="openReffLookup('KANTOR', 'hdr_kode_kantor_muat')">KANTOR 🔍</span></label>
                                <div class="input-group-reff">
                                    <input type="text" id="hdr_kode_kantor_muat" list="dl_reff_KANTOR">
                                    <button type="button" class="btn-lookup" onclick="openReffLookup('KANTOR', 'hdr_kode_kantor_muat')">🔍</button>
                                </div>
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="field-group" data-col="kode_kantor_bongkar">
                                <label>Kantor Bongkar <span class="reff-badge" onclick="openReffLookup('KANTOR', 'hdr_kode_kantor_bongkar')">KANTOR 🔍</span></label>
                                <div class="input-group-reff">
                                    <input type="text" id="hdr_kode_kantor_bongkar" list="dl_reff_KANTOR">
                                    <button type="button" class="btn-lookup" onclick="openReffLookup('KANTOR', 'hdr_kode_kantor_bongkar')">🔍</button>
                                </div>
                            </div>
                            <div class="field-group" data-col="kode_kantor_tujuan">
                                <label>Kantor Tujuan <span class="reff-badge" onclick="openReffLookup('KANTOR', 'hdr_kode_kantor_tujuan')">KANTOR 🔍</span></label>
                                <div class="input-group-reff">
                                    <input type="text" id="hdr_kode_kantor_tujuan" list="dl_reff_KANTOR">
                                    <button type="button" class="btn-lookup" onclick="openReffLookup('KANTOR', 'hdr_kode_kantor_tujuan')">🔍</button>
                                </div>
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="field-group" data-col="kode_tps">
                                <label>Kode TPS <span class="reff-badge" onclick="openReffLookup('TPS', 'hdr_kode_tps')">TPS 🔍</span></label>
                                <div class="input-group-reff">
                                    <input type="text" id="hdr_kode_tps" list="dl_reff_TPS">
                                    <button type="button" class="btn-lookup" onclick="openReffLookup('TPS', 'hdr_kode_tps')">🔍</button>
                                </div>
                            </div>
                            <div class="field-group" data-col="kode_negara_tujuan">
                                <label>Negara Tujuan <span class="reff-badge" onclick="openReffLookup('NEGARA', 'hdr_kode_negara_tujuan')">NEGARA 🔍</span></label>
                                <div class="input-group-reff">
                                    <input type="text" id="hdr_kode_negara_tujuan" list="dl_reff_NEGARA">
                                    <button type="button" class="btn-lookup" onclick="openReffLookup('NEGARA', 'hdr_kode_negara_tujuan')">🔍</button>
                                </div>
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="field-group" data-col="tanggal_tiba">
                                <label>Tanggal Tiba / Masuk</label>
                                <div class="input-date-group">
                                    <input type="text" id="hdr_tanggal_tiba" class="hdr-date-input" placeholder="" maxlength="10" title="Format: yyyy-mm-dd">
                                    <input type="date" class="hdr-date-native" tabindex="-1">
                                    <button type="button" class="btn-hdr-date" onclick="triggerHdrDatePicker(this)">📅</button>
                                </div>
                            </div>
                            <div class="field-group" data-col="tanggal_ekspor">
                                <label>Tanggal Ekspor</label>
                                <div class="input-date-group">
                                    <input type="text" id="hdr_tanggal_ekspor" class="hdr-date-input" placeholder="" maxlength="10" title="Format: yyyy-mm-dd">
                                    <input type="date" class="hdr-date-native" tabindex="-1">
                                    <button type="button" class="btn-hdr-date" onclick="triggerHdrDatePicker(this)">📅</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. TAB BARANG -->
        <div class="panel" id="panel-barang">
            <div class="grid-toolbar" style="flex-wrap:wrap; gap:8px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="font-size:12px; color:var(--text-muted);">Tabel Rincian Barang <span class="tbl-tech-name">(<code>bc_ceisa_barang</code>)</span></span>
                </div>
                <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                    <button type="button" class="btn btn-warning btn-sm" onclick="autoGenerateAllTarifs()" style="font-weight:700; background:#faad14; border-color:#d48806; color:#000;" title="Otomatis sinkronisasi Total CIF Header, generate Barang Tarif, Bahan Baku Tarif, dan hitung Pungutan sekaligus!">⚡ Auto-Generate Tarif & Pungutan</button>
                    <button type="button" class="btn btn-info btn-sm" id="btnToggleTrace" onclick="toggleTracePanel()" style="background:#13c2c2; border-color:#08979c; color:#fff;" title="Buka / Tutup panel pelacakan Dokumen Asal / Eks BC Bahan Baku">🔍 Show Trace</button>
                    <button type="button" class="btn btn-danger btn-sm" onclick="deleteAllTrace()" title="Hapus semua data Trace & BOM pada AJU ini">🗑️ Delete ALL Trace</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="fixHsCode()" title="Isi otomatis Pos Tarif (HS Code) yang kosong dari data historis atau referensi">🔍 FIX HS CODE</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="fixNetto()" title="Isi otomatis Netto yang kosong berdasarkan rasio historis per satuan">⚖️ FIX NETTO</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="editSeriBarang()" title="Tata ulang nomor urut Seri Barang 1..N">🔢 Edit Seri</button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="openMaterialPickerModal()" style="font-weight:700;" title="Pilih dan masukkan barang dari master material bc_material">📦 Tambah dari Master Barang</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="addRow('barang')">➕ Tambah Barang</button>
                    <button type="button" class="btn btn-success btn-sm" onclick="saveTable('barang')">💾 Simpan Barang</button>
                </div>
            </div>
            <div class="grid-wrap">
                <table class="datagrid" id="table-barang">
                    <thead>
                        <tr id="thead-row-barang">
                            <!-- Populated dynamically based on varRules -->
                        </tr>
                    </thead>
                    <tbody></tbody>
                    <tfoot>
                        <tr id="tfoot-row-barang" class="tfoot-row">
                            <!-- Populated dynamically matching thead-row-barang -->
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- TRACE & BILL OF MATERIAL CONTAINER -->
            <div id="traceSubPanel" style="display:none; margin-top:12px; border-top:2px solid var(--border-color); padding-top:10px;">
                <div style="background:var(--bg-secondary); border-radius:6px; border:1px solid var(--border-color); padding:10px; margin-bottom:12px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; flex-wrap:wrap; gap:6px;">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span style="font-weight:700; font-size:12px; color:var(--text-color);">:: Komponen Bahan Baku / Bill of Material (BOM) ::</span>
                            <span id="badgeActiveBarangBom" class="badge" style="background:#2563eb; color:#fff; font-size:11px; padding:3px 8px; border-radius:4px; display:none;"></span>
                        </div>
                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                            <button type="button" class="btn btn-info btn-xs" onclick="loadSelectedBarangBom()" title="Ambil BOM untuk barang aktif dari master bc_ceisa_bom (ue_bomload)">📥 Load BOM Barang Ini</button>
                            <button type="button" class="btn btn-primary btn-xs" onclick="loadAllBomBarang()" title="Ambil BOM seluruh barang dari master bc_ceisa_bom (ue_bomall)">📥 Load ALL BOM Barang</button>
                            <button type="button" class="btn btn-warning btn-xs" onclick="openBomMaterialPickerModal()" style="font-weight:700; background:#eab308; border-color:#ca8a04; color:#000;" title="Pilih & masukkan komponen dari master barang (bc_material)">📦 Tambah dari Master Barang</button>
                            <button type="button" class="btn btn-secondary btn-xs" onclick="addRow('barangbom')">➕ Tambah BOM Manual</button>
                            <button type="button" class="btn btn-success btn-xs" onclick="saveTable('barangbom')">💾 Simpan BOM</button>
                        </div>
                    </div>
                    <div class="grid-wrap" style="max-height:180px;">
                        <table class="datagrid" id="table-barangbom">
                            <thead>
                                <tr>
                                    <th style="width:50px;" data-col="seri">Seri</th>
                                    <th style="width:120px;" data-col="kode_barang_parent">Kd Brg Induk</th>
                                    <th style="width:120px;" data-col="kode_barang">Kd Komponen</th>
                                    <th data-col="uraian">Uraian Komponen</th>
                                    <th style="width:70px;" data-col="kode_satuan">Satuan</th>
                                    <th style="width:100px;" data-col="qty">Qty Dibutuhkan</th>
                                    <th style="width:40px; text-align:center;" class="th-action no-sort">Hapus</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

                <div style="background:var(--bg-secondary); border-radius:6px; border:1px solid var(--border-color); padding:10px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; flex-wrap:wrap; gap:6px;">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span style="font-weight:700; font-size:12px; color:var(--text-color);">:: Tracking Dokumen Asal / Eks BC ::</span>
                            <span id="badgeActiveBomTrace" class="badge" style="background:#0891b2; color:#fff; font-size:11px; padding:3px 8px; border-radius:4px; display:none;"></span>
                        </div>
                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                            <button type="button" class="btn btn-primary btn-xs" onclick="startFifoTrace()" style="font-weight:700; background:#2563eb; border-color:#1d4ed8; color:#fff;" title="Otomatis mencocokkan BOM dengan saldo masuk bc_1ab2 secara FIFO">⚡ Mulai Trace FIFO (Auto)</button>
                            <button type="button" class="btn btn-info btn-xs" onclick="openIncomingPickerModal('trace')" style="background:#0891b2; border-color:#0e7490; color:#fff;" title="Pilih manual dokumen pemasukan eks-BC dari IT Inventory">📥 Tambah Dokumen Asal</button>
                            <button type="button" class="btn btn-success btn-xs" onclick="transferTraceToBahanBaku()" style="font-weight:700;">🔄 Transfer Data Trace ke Bahan Baku</button>
                            <button type="button" class="btn btn-secondary btn-xs" onclick="addRow('tracebb')">➕ Tambah Trace Manual</button>
                            <button type="button" class="btn btn-success btn-xs" onclick="saveTable('tracebb')">💾 Simpan Trace</button>
                        </div>
                    </div>
                    <div class="grid-wrap" style="max-height:200px;">
                        <table class="datagrid" id="table-tracebb">
                            <thead>
                                <tr>
                                    <th style="width:50px;" data-col="seri_barang">Seri Brg</th>
                                    <th style="width:110px;" data-col="kode_barang">Kd Barang</th>
                                    <th data-col="nama_barang">Nama Barang</th>
                                    <th style="width:80px;" data-col="qty_use">Qty Use</th>
                                    <th style="width:60px;" data-col="satuan">Sat</th>
                                    <th style="width:60px;" data-col="jenis_bc">BC</th>
                                    <th style="width:150px;" data-col="nomor_aju">No AJU Asal</th>
                                    <th style="width:100px;" data-col="no_daftar">No Daftar</th>
                                    <th style="width:90px;" data-col="tgl_daftar">Tgl Daftar</th>
                                    <th style="width:90px;" data-col="hs">HS Code</th>
                                    <th style="width:40px; text-align:center;" class="th-action no-sort">Hapus</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. TAB ENTITAS -->
        <div class="panel" id="panel-entitas">
            <div class="grid-toolbar" style="flex-wrap:wrap; gap:8px;">
                <span style="font-size:12px; color:var(--text-muted);">Tabel Entitas Terlibat <span class="tbl-tech-name">(<code>bc_ceisa_entitas</code>)</span></span>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="openModalSettingEntidx()" style="font-weight:600; border:1px solid var(--primary); color:var(--primary); background:transparent;" title="Pengaturan urutan default entitas per dokumen BC (bc_ceisa_entidx)">⚙️ Setting List Entitas Default</button>
                    <button type="button" class="btn btn-info btn-sm" onclick="resetEntitasToDefault()" style="font-weight:600; color:#fff;" title="Susun ulang entitas dokumen ini sesuai setting default urutan entitas BC">⚡ Reset Entitas Default</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="addRow('entitas')">➕ Tambah Entitas</button>
                    <button type="button" class="btn btn-success btn-sm" onclick="saveTable('entitas')">💾 Simpan Entitas</button>
                </div>
            </div>
            <div class="grid-wrap">
                <table class="datagrid" id="table-entitas">
                    <thead>
                        <tr>
                            <th style="width:45px;" data-col="seri">Seri</th>
                            <th style="width:130px; max-width:130px;" data-col="kode_entitas">Kode Entitas</th>
                            <th style="min-width:180px;" data-col="nama_entitas">Nama Entitas</th>
                            <th style="width:195px; min-width:185px;" data-col="nomor_identitas">Nomor Identitas (NPWP)</th>
                            <th style="width:125px; max-width:125px;" data-col="kode_jenis_identitas">Jenis Identitas</th>
                            <th style="min-width:200px;" data-col="alamat_entitas">Alamat Entitas</th>
                            <th style="min-width:110px;" data-col="nib_entitas">NIB</th>
                            <th style="min-width:110px;" data-col="nomor_ijin_entitas">No Ijin</th>
                            <th style="width:105px; max-width:105px;" data-col="tanggal_ijin_entitas">Tgl Ijin</th>
                            <th style="width:135px; max-width:135px;" data-col="kode_negara">Negara</th>
                            <th style="width:40px; text-align:center;" class="th-action no-sort">Hapus</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <!-- 4. TAB DOKUMEN -->
        <div class="panel" id="panel-dokumen">
            <div class="grid-toolbar" style="flex-wrap:wrap; gap:8px;">
                <span style="font-size:12px; color:var(--text-muted);">Tabel Dokumen Lampiran <span class="tbl-tech-name">(<code>bc_ceisa_dokumen</code>)</span></span>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="openModalSettingDocidx()" style="font-weight:600; border:1px solid var(--primary); color:var(--primary); background:transparent;" title="Pengaturan urutan default dokumen lampiran per dokumen BC (bc_ceisa_docidx)">⚙️ Setting List Dokumen Default</button>
                    <button type="button" class="btn btn-info btn-sm" onclick="resetDokumenToDefault()" style="font-weight:600; color:#fff;" title="Susun ulang dokumen lampiran dokumen ini sesuai setting default urutan dokumen BC">⚡ Reset Dokumen Default</button>
                    <button class="btn btn-secondary btn-sm" onclick="addRow('dokumen')">➕ Tambah Dokumen</button>
                    <button class="btn btn-success btn-sm" onclick="saveTable('dokumen')">💾 Simpan Dokumen</button>
                </div>
            </div>
            <div class="grid-wrap">
                <table class="datagrid" id="table-dokumen">
                    <thead>
                        <tr>
                            <th style="width:45px;" data-col="seri">Seri</th>
                            <th style="width:145px; max-width:145px;" data-col="kode_dokumen">Kode Dokumen</th>
                            <th style="min-width:160px;" data-col="nomor_dokumen">Nomor Dokumen</th>
                            <th style="width:110px; max-width:110px;" data-col="tanggal_dokumen">Tanggal Dokumen</th>
                            <th style="width:40px; text-align:center;">Hapus</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <!-- 5. TAB BAHAN BAKU -->
        <div class="panel" id="panel-bahanbaku">
            <div class="grid-toolbar" style="flex-wrap:wrap; gap:8px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="font-size:12px; color:var(--text-muted);">Tabel Bahan Baku / Subkontrak <span class="tbl-tech-name">(<code>bc_ceisa_bahanbaku</code>)</span></span>
                </div>
                <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                    <button type="button" class="btn btn-warning btn-sm" onclick="generateBahanBakuTarif()" style="font-weight:700;" title="Generate otomatis baris tarif untuk seluruh bahan baku (BM, PPH, PPN)">⚡ Generate Bahan Baku Tarif</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="fixHsCode()" title="Isi Pos Tarif (HS Code) kosong pada Bahan Baku">🔍 FIX HS CODE</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="seqBahanBaku()" title="Urutkan Seri Bahan Baku 1..N per Seri Barang">🔢 Urutkan Seri BB</button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="openIncomingPickerModal('bahanbaku')" style="font-weight:700;" title="Pilih dan masukkan dokumen pemasukan eks-BC dari IT Inventory">📥 Tambah dari Dokumen Masuk (Eks BC)</button>
                    <button class="btn btn-secondary btn-sm" onclick="addRow('bahanbaku')">➕ Tambah Bahan Baku</button>
                    <button class="btn btn-success btn-sm" onclick="saveTable('bahanbaku')">💾 Simpan Bahan Baku</button>
                </div>
            </div>
            <div class="grid-wrap">
                <table class="datagrid" id="table-bahanbaku">
                    <thead>
                        <tr>
                            <th style="width:50px;" data-col="seri_barang">Seri Brg</th>
                            <th style="width:50px;" data-col="seri_bahan_baku">Seri BB</th>
                            <th style="min-width:110px;" data-col="kode_asal_bahan_baku">Asal BB</th>
                            <th style="width:100px;" data-col="hs">HS Code</th>
                            <th style="width:110px;" data-col="kode_barang">Kode Barang</th>
                            <th data-col="uraian">Uraian Bahan Baku</th>
                            <th style="width:80px;" data-col="jumlah_satuan">Jml Satuan</th>
                            <th style="width:70px;" data-col="kode_satuan">Satuan</th>
                            <th style="width:100px;" data-col="cif">Nilai CIF</th>
                            <th style="width:160px;" data-col="nomor_aju_asal">No AJU Asal</th>
                            <th style="width:110px;" data-col="nomor_daftar_asal">No Daftar Asal</th>
                            <th style="width:40px; text-align:center;">Hapus</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                    <tfoot>
                        <tr class="tfoot-row">
                            <td data-col="seri_barang" class="tfoot-label">TOTAL</td>
                            <td data-col="seri_bahan_baku"></td>
                            <td data-col="kode_asal_bahan_baku"></td>
                            <td data-col="hs"></td>
                            <td data-col="kode_barang"></td>
                            <td data-col="uraian"></td>
                            <td data-col="jumlah_satuan" class="td-number">0.0000</td>
                            <td data-col="kode_satuan"></td>
                            <td data-col="cif" class="td-number">0.00</td>
                            <td data-col="nomor_aju_asal"></td>
                            <td data-col="nomor_daftar_asal"></td>
                            <td style="text-align:center;"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- 6. TAB BARANG TARIF -->
        <div class="panel" id="panel-barangtarif">
            <div class="grid-toolbar" style="flex-wrap:wrap; gap:8px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="font-size:12px; color:var(--text-muted);">Tabel Tarif & Pungutan Barang <span class="tbl-tech-name">(<code>bc_ceisa_barangtarif</code>)</span></span>
                </div>
                <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                    <button type="button" class="btn btn-warning btn-sm" onclick="generateBarangTarif()" style="font-weight:700;" title="Generate otomatis baris tarif barang">⚡ Generate Barang Tarif</button>
                    <button type="button" class="btn btn-info btn-sm btn-recap-toggle" onclick="toggleFloatingSummaryTarif()" title="Buka / Tutup Floating Window Rekap Tarif & Pungutan" style="background:#08979c; border-color:#006d75; color:#fff; font-weight:600;">📊 Rekap Tarif</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="openTarifBmModal()" title="Buka master tarif Bea Masuk (BM) per HS Code">⚙️ Konfig Tarif BM</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="hitungTarif('barang')" title="Hitung ulang nilai bayar/fasilitas tarif barang">⚡ Hitung Tarif</button>
                    <button class="btn btn-secondary btn-sm" onclick="addRow('barangtarif')">➕ Tambah Tarif</button>
                    <button class="btn btn-success btn-sm" onclick="saveTable('barangtarif')">💾 Simpan Barang Tarif</button>
                </div>
            </div>

            <div class="grid-wrap">
                <table class="datagrid" id="table-barangtarif">
                    <thead>
                        <tr>
                            <th style="width:50px;" data-col="seri_barang">Seri Brg</th>
                            <th style="min-width:130px;" data-col="kode_pungutan">Jenis Pungutan</th>
                            <th style="min-width:105px;" data-col="kode_tarif">Jenis Tarif</th>
                            <th style="width:90px;" data-col="tarif">Tarif (%)</th>
                            <th style="width:110px;" data-col="nilai_bayar">Nilai Bayar</th>
                            <th style="width:110px;" data-col="nilai_fasilitas">Nilai Fasilitas</th>
                            <th style="min-width:110px;" data-col="kode_fasilitas">Kd Fasilitas</th>
                            <th style="width:90px;" data-col="jumlah_satuan">Jml Satuan</th>
                            <th style="width:40px; text-align:center;">Hapus</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                    <tfoot>
                        <tr class="tfoot-row">
                            <td data-col="seri_barang" class="tfoot-label">TOTAL</td>
                            <td data-col="kode_pungutan"></td>
                            <td data-col="kode_tarif"></td>
                            <td data-col="tarif"></td>
                            <td data-col="nilai_bayar" class="td-number">0.00</td>
                            <td data-col="nilai_fasilitas" class="td-number">0.00</td>
                            <td data-col="kode_fasilitas"></td>
                            <td data-col="jumlah_satuan" class="td-number">0.0000</td>
                            <td style="text-align:center;"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- 7. TAB BAHAN BAKU TARIF -->
        <div class="panel" id="panel-bahanbakutarif">
            <div class="grid-toolbar" style="flex-wrap:wrap; gap:8px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="font-size:12px; color:var(--text-muted);">Tabel Tarif Bahan Baku <span class="tbl-tech-name">(<code>bc_ceisa_bahanbakutarif</code>)</span></span>
                </div>
                <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                    <button type="button" class="btn btn-warning btn-sm" onclick="generateBahanBakuTarif()" style="font-weight:700;" title="Generate ulang seluruh Bahan Baku Tarif">⚡ Generate Bahan Baku Tarif</button>
                    <button type="button" class="btn btn-info btn-sm btn-recap-toggle" onclick="toggleFloatingSummaryTarif()" title="Buka / Tutup Floating Window Rekap Tarif & Pungutan" style="background:#08979c; border-color:#006d75; color:#fff; font-weight:600;">📊 Rekap Tarif</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="openTarifBmModal()" title="Buka master tarif Bea Masuk (BM) per HS Code">⚙️ Konfig Tarif BM</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="hitungTarif('bahanbaku')" title="Hitung ulang nilai bayar/fasilitas tarif bahan baku">⚡ Hitung Tarif BB</button>
                    <button class="btn btn-secondary btn-sm" onclick="addRow('bahanbakutarif')">➕ Tambah Tarif BB</button>
                    <button class="btn btn-success btn-sm" onclick="saveTable('bahanbakutarif')">💾 Simpan Tarif BB</button>
                </div>
            </div>
            <div class="grid-wrap">
                <table class="datagrid" id="table-bahanbakutarif">
                    <thead>
                        <tr>
                            <th style="width:50px;" data-col="seri_barang">Seri Brg</th>
                            <th style="width:50px;" data-col="seri_bahan_baku">Seri BB</th>
                            <th style="min-width:130px;" data-col="kode_pungutan">Jenis Pungutan</th>
                            <th style="width:90px;" data-col="tarif">Tarif (%)</th>
                            <th style="width:110px;" data-col="nilai_bayar">Nilai Bayar</th>
                            <th style="width:110px;" data-col="nilai_fasilitas">Nilai Fasilitas</th>
                            <th style="min-width:110px;" data-col="kode_fasilitas">Kd Fasilitas</th>
                            <th style="width:40px; text-align:center;">Hapus</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                    <tfoot>
                        <tr class="tfoot-row">
                            <td data-col="seri_barang" class="tfoot-label">TOTAL</td>
                            <td data-col="seri_bahan_baku"></td>
                            <td data-col="kode_pungutan"></td>
                            <td data-col="tarif"></td>
                            <td data-col="nilai_bayar" class="td-number">0.00</td>
                            <td data-col="nilai_fasilitas" class="td-number">0.00</td>
                            <td data-col="kode_fasilitas"></td>
                            <td style="text-align:center;"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- 8. TAB KEMASAN -->
        <div class="panel" id="panel-kemasan">
            <div class="grid-toolbar">
                <span style="font-size:12px; color:var(--text-muted);">Tabel Kemasan <span class="tbl-tech-name">(<code>bc_ceisa_kemasan</code>)</span></span>
                <div style="display:flex; gap:8px;">
                    <button class="btn btn-secondary btn-sm" onclick="addRow('kemasan')">➕ Tambah Kemasan</button>
                    <button class="btn btn-success btn-sm" onclick="saveTable('kemasan')">💾 Simpan Kemasan</button>
                </div>
            </div>
            <div class="grid-wrap">
                <table class="datagrid" id="table-kemasan">
                    <thead>
                        <tr>
                            <th style="width:45px;" data-col="seri">Seri</th>
                            <th style="width:110px; max-width:110px;" data-col="jumlah_kemasan">Jumlah Kemasan</th>
                            <th style="width:125px; max-width:125px;" data-col="kode_kemasan">Kode Kemasan</th>
                            <th style="min-width:140px;" data-col="merek">Merek Kemasan</th>
                            <th style="width:40px; text-align:center;" class="th-action no-sort">Hapus</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                    <tfoot>
                        <tr class="tfoot-row">
                            <td data-col="seri" class="tfoot-label">TOTAL</td>
                            <td data-col="jumlah_kemasan" class="td-number">0.00</td>
                            <td data-col="kode_kemasan"></td>
                            <td data-col="merek"></td>
                            <td style="text-align:center;"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- 9. TAB PENGANGKUT -->
        <div class="panel" id="panel-pengangkut">
            <div class="grid-toolbar">
                <span style="font-size:12px; color:var(--text-muted);">Tabel Sarana Pengangkut <span class="tbl-tech-name">(<code>bc_ceisa_pengangkut</code>)</span></span>
                <div style="display:flex; gap:8px;">
                    <button class="btn btn-secondary btn-sm" onclick="addRow('pengangkut')">➕ Tambah Pengangkut</button>
                    <button class="btn btn-success btn-sm" onclick="saveTable('pengangkut')">💾 Simpan Pengangkut</button>
                </div>
            </div>
            <div class="grid-wrap">
                <table class="datagrid" id="table-pengangkut">
                    <thead>
                        <tr>
                            <th style="width:45px;" data-col="seri">Seri</th>
                            <th style="width:110px; max-width:110px;" data-col="kode_cara_angkut">Cara Angkut</th>
                            <th style="min-width:180px;" data-col="nama_pengangkut">Nama Sarana Pengangkut</th>
                            <th style="min-width:140px;" data-col="nomor_pengangkut">Nomor Pengangkut / Voyage</th>
                            <th style="width:135px; max-width:135px;" data-col="kode_bendera">Bendera</th>
                            <th style="min-width:100px;" data-col="call_sign">Call Sign</th>
                            <th style="width:40px; text-align:center;" class="th-action no-sort">Hapus</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <!-- 10. TAB KONTAINER -->
        <div class="panel" id="panel-kontainer">
            <div class="grid-toolbar">
                <span style="font-size:12px; color:var(--text-muted);">Tabel Kontainer <span class="tbl-tech-name">(<code>bc_ceisa_kontainer</code>)</span></span>
                <div style="display:flex; gap:8px;">
                    <button class="btn btn-secondary btn-sm" onclick="addRow('kontainer')">➕ Tambah Kontainer</button>
                    <button class="btn btn-success btn-sm" onclick="saveTable('kontainer')">💾 Simpan Kontainer</button>
                </div>
            </div>
            <div class="grid-wrap">
                <table class="datagrid" id="table-kontainer">
                    <thead>
                        <tr>
                            <th style="width:45px;" data-col="seri">Seri</th>
                            <th style="min-width:130px;" data-col="nomor_kontainer">Nomor Kontainer</th>
                            <th style="width:105px; max-width:105px;" data-col="kode_ukuran_kontainer">Ukuran</th>
                            <th style="width:115px; max-width:115px;" data-col="kode_jenis_kontainer">Jenis</th>
                            <th style="width:120px; max-width:120px;" data-col="kode_tipe_kontainer">Tipe</th>
                            <th style="width:40px; text-align:center;" class="th-action no-sort">Hapus</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <!-- 11. TAB PUNGUTAN -->
        <div class="panel" id="panel-pungutan">
            <div class="grid-toolbar">
                <span style="font-size:12px; color:var(--text-muted);">Tabel Pungutan <span class="tbl-tech-name">(<code>bc_ceisa_pungutan</code>)</span></span>
                <div style="display:flex; gap:8px;">
                    <button type="button" class="btn btn-warning btn-sm" onclick="hitungPungutan()" title="Kalkulasi otomatis total pungutan negara dari data Tarif Dokumen sesuai aturan CEISA 4.0">⚡ Hitung Pungutan</button>
                    <button class="btn btn-secondary btn-sm" onclick="addRow('pungutan')">➕ Tambah Pungutan</button>
                    <button class="btn btn-success btn-sm" onclick="saveTable('pungutan')">💾 Simpan Pungutan</button>
                </div>
            </div>
            <div class="grid-wrap">
                <table class="datagrid" id="table-pungutan">
                    <thead>
                        <tr>
                            <th style="width:240px; min-width:220px; max-width:300px;" data-col="kode_jenis_pungutan">Jenis Pungutan</th>
                            <th style="width:280px; min-width:260px; max-width:360px;" data-col="kode_fasilitas_tarif">Kd Fasilitas Tarif</th>
                            <th style="min-width:140px;" data-col="nilai_pungutan">Nilai Pungutan (Rp)</th>
                            <th style="width:160px; min-width:160px;" data-col="npwp_billing">NPWP Billing</th>
                            <th style="width:40px; text-align:center;">Hapus</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                    <tfoot>
                        <tr class="tfoot-row">
                            <td data-col="kode_jenis_pungutan" class="tfoot-label">TOTAL</td>
                            <td data-col="kode_fasilitas_tarif"></td>
                            <td data-col="nilai_pungutan" class="td-number">0.00</td>
                            <td data-col="npwp_billing"></td>
                            <td style="text-align:center;"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- 12. TAB BANK DEVISA -->
        <div class="panel" id="panel-bankdevisa">
            <div class="grid-toolbar">
                <span style="font-size:12px; color:var(--text-muted);">Tabel Bank Devisa <span class="tbl-tech-name">(<code>bc_ceisa_bankdevisa</code>)</span></span>
                <div style="display:flex; gap:8px;">
                    <button class="btn btn-secondary btn-sm" onclick="addRow('bankdevisa')">➕ Tambah Bank</button>
                    <button class="btn btn-success btn-sm" onclick="saveTable('bankdevisa')">💾 Simpan Bank</button>
                </div>
            </div>
            <div class="grid-wrap">
                <table class="datagrid" id="table-bankdevisa">
                    <thead>
                        <tr>
                            <th style="width:45px;" data-col="seri">Seri</th>
                            <th style="width:140px; max-width:140px;" data-col="kode">Kode Bank</th>
                            <th style="min-width:200px;" data-col="nama">Nama Bank Devisa</th>
                            <th style="width:40px; text-align:center;">Hapus</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <!-- 13. TAB JAMINAN -->
        <div class="panel" id="panel-jaminan">
            <div class="grid-toolbar">
                <span style="font-size:12px; color:var(--text-muted);">Tabel Jaminan <span class="tbl-tech-name">(<code>bc_ceisa_jaminan</code>)</span></span>
                <div style="display:flex; gap:8px;">
                    <button class="btn btn-secondary btn-sm" onclick="addRow('jaminan')">➕ Tambah Jaminan</button>
                    <button class="btn btn-success btn-sm" onclick="saveTable('jaminan')">💾 Simpan Jaminan</button>
                </div>
            </div>
            <div class="grid-wrap">
                <table class="datagrid" id="table-jaminan">
                    <thead>
                        <tr>
                            <th style="min-width:130px;" data-col="kode_jaminan">Jenis Jaminan</th>
                            <th data-col="nomor_jaminan">Nomor Jaminan</th>
                            <th style="width:110px;" data-col="tanggal_jaminan">Tgl Jaminan</th>
                            <th style="width:120px;" data-col="nilai_jaminan">Nilai Jaminan</th>
                            <th data-col="penjamin">Penjamin</th>
                            <th style="width:110px;" data-col="tanggal_jatuh_tempo">Jatuh Tempo</th>
                            <th style="width:120px;" data-col="nomor_bpj">Nomor BPJ</th>
                            <th style="width:110px;" data-col="tanggal_bpj">Tgl BPJ</th>
                            <th style="width:40px; text-align:center;">Hapus</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                    <tfoot>
                        <tr class="tfoot-row">
                            <td data-col="kode_jaminan" class="tfoot-label">TOTAL</td>
                            <td data-col="nomor_jaminan"></td>
                            <td data-col="tanggal_jaminan"></td>
                            <td data-col="nilai_jaminan" class="td-number">0.00</td>
                            <td data-col="penjamin"></td>
                            <td data-col="tanggal_jatuh_tempo"></td>
                            <td data-col="nomor_bpj"></td>
                            <td data-col="tanggal_bpj"></td>
                            <td style="text-align:center;"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- 14. TAB KOMPONEN BIAYA (BIAYA) -->
        <div class="panel" id="panel-komponenbiaya">
            <div class="grid-toolbar">
                <span style="font-size:12px; color:var(--text-muted);">Tabel Komponen Biaya <span class="tbl-tech-name">(<code>bc_ceisa_komponenbiaya</code>)</span></span>
                <div style="display:flex; gap:8px;">
                    <button class="btn btn-secondary btn-sm" onclick="addRow('komponenbiaya')">➕ Tambah Biaya</button>
                    <button class="btn btn-success btn-sm" onclick="saveTable('komponenbiaya')">💾 Simpan Biaya</button>
                </div>
            </div>
            <div class="grid-wrap">
                <table class="datagrid" id="table-komponenbiaya">
                    <thead>
                        <tr>
                            <th style="min-width:110px;" data-col="jenis_nilai">Jenis Nilai</th>
                            <th style="min-width:110px;" data-col="harga_invoice">Harga Invoice</th>
                            <th style="min-width:115px;" data-col="pembayaran_tidak_langsung">Byr Tdk Langsung</th>
                            <th style="min-width:95px;" data-col="diskon">Diskon</th>
                            <th style="min-width:110px;" data-col="komisi_penjualan">Komisi Penjualan</th>
                            <th style="min-width:105px;" data-col="biaya_pengemasan">Biaya Kemas</th>
                            <th style="min-width:105px;" data-col="biaya_pengepakan">Biaya Pak</th>
                            <th style="min-width:95px;" data-col="assist">Assist</th>
                            <th style="min-width:95px;" data-col="royalti">Royalti</th>
                            <th style="min-width:95px;" data-col="proceeds">Proceeds</th>
                            <th style="min-width:120px;" data-col="biaya_transportasi">Transportasi / Freight</th>
                            <th style="min-width:110px;" data-col="biaya_pemuatan">Biaya Pemuatan</th>
                            <th style="min-width:100px;" data-col="asuransi">Asuransi</th>
                            <th style="min-width:95px;" data-col="garansi">Garansi</th>
                            <th style="min-width:105px;" data-col="biaya_kepentingan_sendiri">Kpt. Sendiri</th>
                            <th style="min-width:105px;" data-col="biaya_pasca_impor">Pasca Impor</th>
                            <th style="min-width:105px;" data-col="biaya_pajak_internal">Pajak Internal</th>
                            <th style="min-width:95px;" data-col="bunga">Bunga</th>
                            <th style="min-width:95px;" data-col="deviden">Deviden</th>
                            <th style="width:40px; text-align:center;">Hapus</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                    <tfoot>
                        <tr class="tfoot-row">
                            <td data-col="jenis_nilai" class="tfoot-label">TOTAL</td>
                            <td data-col="harga_invoice" class="td-number">0.00</td>
                            <td data-col="pembayaran_tidak_langsung" class="td-number">0.00</td>
                            <td data-col="diskon" class="td-number">0.00</td>
                            <td data-col="komisi_penjualan" class="td-number">0.00</td>
                            <td data-col="biaya_pengemasan" class="td-number">0.00</td>
                            <td data-col="biaya_pengepakan" class="td-number">0.00</td>
                            <td data-col="assist" class="td-number">0.00</td>
                            <td data-col="royalti" class="td-number">0.00</td>
                            <td data-col="proceeds" class="td-number">0.00</td>
                            <td data-col="biaya_transportasi" class="td-number">0.00</td>
                            <td data-col="biaya_pemuatan" class="td-number">0.00</td>
                            <td data-col="asuransi" class="td-number">0.00</td>
                            <td data-col="garansi" class="td-number">0.00</td>
                            <td data-col="biaya_kepentingan_sendiri" class="td-number">0.00</td>
                            <td data-col="biaya_pasca_impor" class="td-number">0.00</td>
                            <td data-col="biaya_pajak_internal" class="td-number">0.00</td>
                            <td data-col="bunga" class="td-number">0.00</td>
                            <td data-col="deviden" class="td-number">0.00</td>
                            <td style="text-align:center;"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- 15. TAB BARANG DOKUMEN -->
        <div class="panel" id="panel-barangdokumen">
            <div class="grid-toolbar">
                <span style="font-size:12px; color:var(--text-muted);">Tabel Dokumen Fasilitas Barang <span class="tbl-tech-name">(<code>bc_ceisa_barangdokumen</code>)</span></span>
                <div style="display:flex; gap:8px;">
                    <button class="btn btn-secondary btn-sm" onclick="addRow('barangdokumen')">➕ Tambah Dokumen Barang</button>
                    <button class="btn btn-success btn-sm" onclick="saveTable('barangdokumen')">💾 Simpan Dokumen Barang</button>
                </div>
            </div>
            <div class="grid-wrap">
                <table class="datagrid" id="table-barangdokumen">
                    <thead>
                        <tr>
                            <th style="width:55px;" data-col="seri_barang">Seri Brg</th>
                            <th style="width:95px;" data-col="seri_dokumen">Seri Dokumen</th>
                            <th style="min-width:140px;" data-col="seri_izin">Seri Fasilitas / Izin</th>
                            <th style="width:40px; text-align:center;">Hapus</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <!-- 16. TAB BARANG ENTITAS -->
        <div class="panel" id="panel-barangentitas">
            <div class="grid-toolbar">
                <span style="font-size:12px; color:var(--text-muted);">Tabel Relasi Entitas Barang <span class="tbl-tech-name">(<code>bc_ceisa_barangentitas</code>)</span></span>
                <div style="display:flex; gap:8px;">
                    <button class="btn btn-secondary btn-sm" onclick="addRow('barangentitas')">➕ Tambah Entitas Barang</button>
                    <button class="btn btn-success btn-sm" onclick="saveTable('barangentitas')">💾 Simpan Entitas Barang</button>
                </div>
            </div>
            <div class="grid-wrap">
                <table class="datagrid" id="table-barangentitas">
                    <thead>
                        <tr>
                            <th style="width:55px;" data-col="seri_barang">Seri Brg</th>
                            <th style="width:95px;" data-col="seri_entitas">Seri Entitas</th>
                            <th style="width:40px; text-align:center;">Hapus</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <!-- 17. TAB BARANG SPEK KHUSUS -->
        <div class="panel" id="panel-barangspekkhusus">
            <div class="grid-toolbar">
                <span style="font-size:12px; color:var(--text-muted);">Tabel Spesifikasi Khusus Barang <span class="tbl-tech-name">(<code>bc_ceisa_barangspekkhusus</code>)</span></span>
                <div style="display:flex; gap:8px;">
                    <button class="btn btn-secondary btn-sm" onclick="addRow('barangspekkhusus')">➕ Tambah Spek Khusus</button>
                    <button class="btn btn-success btn-sm" onclick="saveTable('barangspekkhusus')">💾 Simpan Spek Khusus</button>
                </div>
            </div>
            <div class="grid-wrap">
                <table class="datagrid" id="table-barangspekkhusus">
                    <thead>
                        <tr>
                            <th style="width:55px;" data-col="seri_barang">Seri Brg</th>
                            <th style="min-width:130px;" data-col="kode">Kode Spek Khusus</th>
                            <th style="min-width:250px;" data-col="uraian">Uraian Spesifikasi Khusus</th>
                            <th style="width:40px; text-align:center;">Hapus</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <!-- 18. TAB BARANG NILAI VD -->
        <div class="panel" id="panel-barangvd">
            <div class="grid-toolbar">
                <span style="font-size:12px; color:var(--text-muted);">Tabel Voluntary Declaration (VD) Barang <span class="tbl-tech-name">(<code>bc_ceisa_barangvd</code>)</span></span>
                <div style="display:flex; gap:8px;">
                    <button class="btn btn-secondary btn-sm" onclick="addRow('barangvd')">➕ Tambah Nilai VD</button>
                    <button class="btn btn-success btn-sm" onclick="saveTable('barangvd')">💾 Simpan Nilai VD</button>
                </div>
            </div>
            <div class="grid-wrap">
                <table class="datagrid" id="table-barangvd">
                    <thead>
                        <tr>
                            <th style="width:55px;" data-col="seri_barang">Seri Brg</th>
                            <th style="min-width:100px;" data-col="kode_vd">Kode VD</th>
                            <th style="min-width:115px;" data-col="nilai_barang">Nilai Barang</th>
                            <th style="min-width:115px;" data-col="biaya_tambahan">Biaya Tambahan</th>
                            <th style="min-width:115px;" data-col="biaya_pengurang">Biaya Pengurang</th>
                            <th style="width:110px;" data-col="jatuh_tempo">Jatuh Tempo</th>
                            <th style="width:40px; text-align:center;">Hapus</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                    <tfoot>
                        <tr class="tfoot-row">
                            <td data-col="seri_barang" class="tfoot-label">TOTAL</td>
                            <td data-col="kode_vd"></td>
                            <td data-col="nilai_barang" class="td-number">0.00</td>
                            <td data-col="biaya_tambahan" class="td-number">0.00</td>
                            <td data-col="biaya_pengurang" class="td-number">0.00</td>
                            <td data-col="jatuh_tempo"></td>
                            <td style="text-align:center;"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- 19. TAB BAHAN BAKU DOKUMEN (BB DOKUMEN) -->
        <div class="panel" id="panel-bahanbakudokumen">
            <div class="grid-toolbar">
                <span style="font-size:12px; color:var(--text-muted);">Tabel Dokumen Bahan Baku <span class="tbl-tech-name">(<code>bc_ceisa_bahanbakudokumen</code>)</span></span>
                <div style="display:flex; gap:8px;">
                    <button class="btn btn-secondary btn-sm" onclick="addRow('bahanbakudokumen')">➕ Tambah BB Dokumen</button>
                    <button class="btn btn-success btn-sm" onclick="saveTable('bahanbakudokumen')">💾 Simpan BB Dokumen</button>
                </div>
            </div>
            <div class="grid-wrap">
                <table class="datagrid" id="table-bahanbakudokumen">
                    <thead>
                        <tr>
                            <th style="width:55px;" data-col="seri_barang">Seri Brg</th>
                            <th style="width:55px;" data-col="seri_bahan_baku">Seri BB</th>
                            <th style="width:110px;" data-col="kode_asal_bahan_baku">Asal BB</th>
                            <th style="width:90px;" data-col="seri_dokumen">Seri Dokumen</th>
                            <th style="min-width:120px;" data-col="seri_izin">Seri Fasilitas / Izin</th>
                            <th style="width:40px; text-align:center;">Hapus</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <!-- 20. TAB KESIAPAN BARANG -->
        <div class="panel" id="panel-kesiapanbarang">
            <div class="form-section" style="max-width:800px;">
                <div class="section-title">⏱️ Data Kesiapan Barang Ekspor (BC 3.0)</div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div class="field-group">
                        <label>Nama PIC</label>
                        <input type="text" id="kb_nama_pic">
                    </div>
                    <div class="field-group">
                        <label>Nomor Telp PIC</label>
                        <input type="text" id="kb_telp_pic">
                    </div>
                </div>
                <div class="field-group">
                    <label>Alamat PIC / Lokasi Gudang</label>
                    <input type="text" id="kb_alamat_pic">
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div class="field-group">
                        <label>Lokasi Siap Periksa</label>
                        <input type="text" id="kb_lokasi_siap_periksa">
                    </div>
                    <div class="field-group">
                        <label>Waktu Siap Periksa</label>
                        <div class="input-date-group">
                            <input type="text" id="kb_waktu_siap_periksa" class="hdr-date-input" placeholder="" maxlength="10" title="Format: yyyy-mm-dd">
                            <input type="date" class="hdr-date-native" tabindex="-1">
                            <button type="button" class="btn-hdr-date" onclick="triggerHdrDatePicker(this)">📅</button>
                        </div>
                    </div>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px;">
                    <div class="field-group">
                        <label>Cara Stuffing</label>
                        <select id="kb_kode_cara_stuffing" data-reff="CARA STUFFING"></select>
                    </div>
                    <div class="field-group">
                        <label>Kontainer 20ft</label>
                        <input type="number" id="kb_jumlah_kontainer20">
                    </div>
                    <div class="field-group">
                        <label>Kontainer 40ft</label>
                        <input type="number" id="kb_jumlah_kontainer40">
                    </div>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:12px; margin-top:8px;">
                    <div class="field-group">
                        <label>Jenis Barang</label>
                        <select id="kb_kode_jenis_barang" data-reff="JENIS BARANG EKSPOR"></select>
                    </div>
                    <div class="field-group">
                        <label>Jenis Gudang</label>
                        <select id="kb_kode_jenis_gudang" data-reff="JENIS GUDANG"></select>
                    </div>
                    <div class="field-group">
                        <label>Jenis Partof</label>
                        <select id="kb_kode_jenis_partof" data-reff="JENIS PARTOF"></select>
                    </div>
                    <div class="field-group">
                        <label>Tanggal PKB</label>
                        <div class="input-date-group">
                            <input type="text" id="kb_tanggal_pkb" class="hdr-date-input" placeholder="" maxlength="10" title="Format: yyyy-mm-dd">
                            <input type="date" class="hdr-date-native" tabindex="-1">
                            <button type="button" class="btn-hdr-date" onclick="triggerHdrDatePicker(this)">📅</button>
                        </div>
                    </div>
                </div>
                <div style="margin-top:16px;">
                    <button class="btn btn-success" onclick="saveKesiapanBarang()">💾 Simpan Kesiapan Barang</button>
                </div>
            </div>
        </div>

    </div><!-- .workspace -->
        </div><!-- .editor-content-area -->
    </div><!-- .editor-main-container -->

    <!-- DATALISTS CONTAINER FOR REFF OPTIONS -->
    <div id="datalistsContainer"></div>

    <!-- TOAST NOTIFICATION -->
    <div id="toast">✓ Data berhasil disimpan!</div>

    <!-- FILTER SETTINGS MODAL (TAB & KOLOM: GLOBAL & PER PERUSAHAAN) -->
    <div class="modal-overlay" id="modalFilterSettings" style="display:none;">
        <div class="modal" style="max-width:880px; width:95vw; height:88vh; display:flex; flex-direction:column;">
            <div class="modal-header">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="font-size:18px;">⚙️</span>
                    <div>
                        <h3 style="margin:0; font-size:14px; font-weight:700;">Pengaturan Saring Tab &amp; Kolom Dokumen Pabean</h3>
                        <span style="font-size:10px; color:var(--text-muted);">Konfigurasi visibilitas Global &amp; Per Perusahaan (Override &amp; Fallback)</span>
                    </div>
                </div>
                <button class="btn btn-secondary btn-sm" onclick="closeModal('modalFilterSettings')">✕</button>
            </div>
            
            <!-- CONTROLS BAR: BC SELECTOR, SCOPE SELECTOR, STATUS BADGE -->
            <div style="padding:10px 14px; background:var(--bg-card); border-bottom:1px solid var(--border); display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between;">
                <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                    <div>
                        <label style="font-size:10px; font-weight:700; color:var(--text-muted); display:block; margin-bottom:2px;">DOKUMEN BC:</label>
                        <select id="cfgFilterDocBc" onchange="loadFilterSettingsData()" style="font-weight:700; padding:4px 8px; font-size:11px; border:1px solid var(--border); border-radius:4px; background:var(--bg-input); color:var(--text-main);">
                            <optgroup label="── PEMASUKAN (INBOUND) ──">
                                <option value="16">BC 1.6 — Pemasukan ke PLB</option>
                                <option value="20">BC 2.0 — Impor Non-Fasilitas (PIB)</option>
                                <option value="23" selected>BC 2.3 — Impor ke Kawasan Berikat (TPB)</option>
                                <option value="262">BC 2.6.2 — Pemasukan Subcon Kembali</option>
                                <option value="28">BC 2.8 — Impor dari PLB</option>
                                <option value="40">BC 4.0 — Pemasukan dari Lokal ke TPB</option>
                                <option value="611">PPKEK 6.1.1 — Pemasukan ke KEK dari Luar Negeri</option>
                                <option value="613">PPKEK 6.1.3 — Pemasukan ke KEK dari Lokal</option>
                            </optgroup>
                            <optgroup label="── PENGELUARAN (OUTBOUND) ──">
                                <option value="25">BC 2.5 — Pengeluaran ke Lokal (Asal Impor / BC 2.3)</option>
                                <option value="261">BC 2.6.1 — Pengeluaran Subcon</option>
                                <option value="27">BC 2.7 — Pengeluaran ke TPB Lain</option>
                                <option value="30">BC 3.0 — Ekspor Barang (PEB)</option>
                                <option value="33">BC 3.3 — Ekspor dari PLB</option>
                                <option value="331">BC 3.3.1 — P3BET PLB</option>
                                <option value="41">BC 4.1 — Pengeluaran ke Lokal (Asal Lokal / BC 4.0)</option>
                                <option value="631">PPKEK 6.3.1 — Pengeluaran dari KEK ke Luar Negeri</option>
                            </optgroup>
                        </select>
                    </div>

                    <div>
                        <label style="font-size:10px; font-weight:700; color:var(--text-muted); display:block; margin-bottom:2px;">LINGKUP (TARGET SETTING):</label>
                        <select id="cfgFilterScopeCmp" onchange="onFilterScopeChange()" style="font-weight:700; padding:4px 8px; font-size:11px; border:1px solid var(--border); border-radius:4px; background:var(--bg-input); color:var(--text-main); min-width:260px;">
                            <option value="">🌐 GLOBAL (Default Semua Perusahaan)</option>
                        </select>
                    </div>
                </div>

                <div id="cfgFilterStatusBadgeContainer" style="display:flex; align-items:center; gap:6px;">
                    <span id="cfgFilterStatusBadge" class="badge" style="font-size:10px; padding:4px 10px; border-radius:4px;">Memuat status...</span>
                </div>
            </div>

            <!-- MODAL SUB-TAB BAR (TAB vs KOLOM vs DESIMAL) -->
            <div style="display:flex; border-bottom:1px solid var(--border); background:var(--bg-base); padding:0 14px;">
                <button type="button" id="btnSubTabFilterTab" class="btn btn-tab-sub active" onclick="switchFilterSettingsSubTab('tab')" style="padding:8px 16px; border:none; border-bottom:2px solid var(--primary); background:transparent; font-weight:700; font-size:12px; cursor:pointer; color:var(--primary);">
                    📑 Saring Tab BC
                </button>
                <button type="button" id="btnSubTabFilterKolom" class="btn btn-tab-sub" onclick="switchFilterSettingsSubTab('kolom')" style="padding:8px 16px; border:none; border-bottom:2px solid transparent; background:transparent; font-weight:600; font-size:12px; cursor:pointer; color:var(--text-muted);">
                    👁️ Saring Kolom (Header &amp; Barang)
                </button>
                <button type="button" id="btnSubTabFilterDesimal" class="btn btn-tab-sub" onclick="switchFilterSettingsSubTab('desimal')" style="padding:8px 16px; border:none; border-bottom:2px solid transparent; background:transparent; font-weight:600; font-size:12px; cursor:pointer; color:var(--text-muted);">
                    🔢 Format Desimal (Jumlah Koma)
                </button>
            </div>

            <div class="modal-body" style="flex:1; overflow-y:auto; padding:12px 14px;">
                <!-- SUB-PANEL 1: SARING TAB -->
                <div id="subPanelFilterTab">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
                        <span style="font-size:11px; color:var(--text-muted);">Centang tab yang diizinkan tampil pada dokumen BC yang dipilih. Tab tanpa centang akan disembunyikan.</span>
                        <div style="display:flex; gap:6px;">
                            <button type="button" class="btn btn-secondary btn-xs" onclick="setAllFilterTabs(true)">Pilih Semua</button>
                            <button type="button" class="btn btn-secondary btn-xs" onclick="setAllFilterTabs(false)">Kosongkan Opsional</button>
                            <button type="button" class="btn btn-secondary btn-xs" onclick="applyDefaultBcTabPreset()">Gunakan Rekomendasi Standar</button>
                        </div>
                    </div>
                    
                    <div id="filterTabGrid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(230px, 1fr)); gap:8px;">
                        <!-- Tab Checkboxes dynamically rendered here -->
                    </div>
                </div>

                <!-- SUB-PANEL 2: SARING KOLOM -->
                <div id="subPanelFilterKolom" style="display:none;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; flex-wrap:wrap; gap:8px;">
                        <div style="display:flex; gap:8px; align-items:center;">
                            <input type="text" id="cfgColSearchInput" onkeyup="filterColumnListUI()" placeholder="🔍 Cari nama / label kolom..." style="padding:4px 8px; font-size:11px; border:1px solid var(--border); border-radius:4px; width:220px; background:var(--bg-input); color:var(--text-main);">
                            <div class="btn-group" style="display:inline-flex; border:1px solid var(--border); border-radius:4px; overflow:hidden;">
                                <button type="button" id="btnColTableHdr" class="btn btn-xs btn-primary" onclick="switchColTableFilter('header')" style="border-radius:0;">Header</button>
                                <button type="button" id="btnColTableBrg" class="btn btn-xs btn-secondary" onclick="switchColTableFilter('barang')" style="border-radius:0;">Barang</button>
                            </div>
                        </div>
                        <div style="display:flex; gap:6px;">
                            <button type="button" class="btn btn-secondary btn-xs" onclick="setAllFilterCols(true)">Pilih Semua</button>
                            <button type="button" class="btn btn-secondary btn-xs" onclick="setAllFilterCols(false)">Kosongkan Semua</button>
                        </div>
                    </div>

                    <div style="max-height:420px; overflow-y:auto; border:1px solid var(--border); border-radius:4px;">
                        <table class="datagrid" id="tableFilterCols">
                            <thead>
                                <tr>
                                    <th style="width:40px; text-align:center;"><input type="checkbox" id="chkAllVisibleCols" onchange="toggleCheckAllCols(this.checked)"></th>
                                    <th style="width:180px;">Nama Field (col_id)</th>
                                    <th>Label Kolom</th>
                                    <th style="width:140px;">Keterangan / Reff</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Columns dynamically rendered here -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- SUB-PANEL 3: FORMAT DESIMAL (JUMLAH KOMA) -->
                <div id="subPanelFilterDesimal" style="display:none;">
                    <!-- Penjelasan Rujukan CEISA & Rekomendasi -->
                    <div style="background:var(--bg-input); border:1px solid var(--border); border-left:4px solid var(--primary); padding:7px 10px; border-radius:4px; margin-bottom:10px; font-size:9.5px; line-height:1.45;">
                        <div style="font-weight:700; color:var(--primary); margin-bottom:3px; font-size:10px;">💡 Rujukan Standar Format Data OpenAPI CEISA 4.0:</div>
                        <div style="color:var(--text-main); margin-bottom:3px;">
                            • <strong>Nilai Uang (CIF, FOB, Harga Penyerahan, Nilai Barang, Tarif):</strong> Format resmi CEISA menggunakan <strong>2 desimal</strong> (contoh: <code>4,208,141.25</code>).<br>
                            • <strong>Jumlah Kemasan:</strong> Umumnya bilangan bulat / <strong>0 desimal</strong> (contoh: <code>95</code>) atau maksimal 2 desimal.<br>
                            • <strong>Jumlah Satuan (Qty):</strong> Database menyimpan hingga <code>decimal(18,4)</code> (contoh: <code>7.0000</code>). Untuk satuan fisik/utuh (<code>NIU</code>, <code>PCE</code>, <code>SET</code>, <code>UNIT</code>) dapat diubah menjadi <strong>0 desimal</strong> (<code>7</code>) atau <strong>2 desimal</strong> (<code>7.00</code>) agar rapi dan tidak mengganggu operator.<br>
                            • <strong>Berat &amp; Ukuran (Netto, Bruto, Volume):</strong> Standar kepabeanan memakai <strong>4 desimal</strong> (contoh: <code>3,841.8340</code>) atau 2 desimal.
                        </div>
                        <div style="font-size:9px; color:var(--text-muted);">
                            * Perubahan format koma di bawah ini berlaku untuk tampilan tabel, form input, dan footer summary. Nilai numerik asli tetap terjaga presisi saat transfer ke server CEISA.
                        </div>
                    </div>

                    <!-- Tombol Cepat / Presets (Samakan Semua Kolom) -->
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; flex-wrap:wrap; gap:6px;">
                        <div style="font-size:10px; font-weight:700; color:var(--text-main);">
                            ⚡ Tombol Cepat (Samakan Semua / Terapkan Preset):
                        </div>
                        <div style="display:flex; gap:5px; flex-wrap:wrap;">
                            <button type="button" class="btn btn-secondary btn-xs" onclick="equalizeAllDecimals(0)" style="font-size:9.5px; padding:2px 6px;" title="Samakan semua kolom angka menjadi bilangan bulat tanpa koma (0 desimal)">⚡ Samakan Semua: 0 Koma</button>
                            <button type="button" class="btn btn-secondary btn-xs" onclick="equalizeAllDecimals(2)" style="font-size:9.5px; padding:2px 6px;" title="Samakan semua kolom angka menjadi 2 desimal standar akuntansi">⚡ Samakan Semua: 2 Koma</button>
                            <button type="button" class="btn btn-secondary btn-xs" onclick="equalizeAllDecimals(4)" style="font-size:9.5px; padding:2px 6px;" title="Samakan semua kolom angka menjadi 4 desimal teknis bea cukai">⚡ Samakan Semua: 4 Koma</button>
                            <button type="button" class="btn btn-primary btn-xs" onclick="applyDecimalPreset('optimal')" style="font-size:9.5px; padding:2px 6px;" title="Terapkan rekomendasi optimal: Qty (0), Kemasan (0), Uang (2), Berat (4), Harga (4), Tarif (2)">⭐ Rekomendasi Optimal</button>
                        </div>
                    </div>

                    <!-- Grid Konfigurasi Desimal Per Kategori -->
                    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(340px, 1fr)); gap:8px;">
                        
                        <!-- 1. Qty / Jumlah Satuan -->
                        <div style="border:1px solid var(--border); border-radius:6px; padding:7px 10px; background:var(--bg-card);">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px; gap:8px;">
                                <div style="min-width:0;">
                                    <strong style="font-size:10.5px; color:var(--text-main); display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">1. Jumlah Satuan (Qty Barang &amp; Bahan Baku)</strong>
                                    <div style="font-size:9px; color:var(--text-muted); font-family:monospace; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">jumlah_satuan (barang, bahanbaku, tarif)</div>
                                </div>
                                <select id="cfgDecQty" onchange="updateDecimalLivePreviews()" style="padding:2px 6px; font-size:10px; font-weight:600; height:24px; border:1px solid var(--border); border-radius:4px; background:var(--bg-input); color:var(--primary); cursor:pointer; flex-shrink:0;">
                                    <option value="0">0 Desimal (7 - Tanpa Koma)</option>
                                    <option value="1">1 Desimal (7.0)</option>
                                    <option value="2">2 Desimal (7.00)</option>
                                    <option value="3">3 Desimal (7.000)</option>
                                    <option value="4" selected>4 Desimal (7.0000 - Bawaan)</option>
                                    <option value="5">5 Desimal</option>
                                    <option value="6">6 Desimal</option>
                                </select>
                            </div>
                            <div style="font-size:9.5px; background:var(--bg-input); padding:3px 8px; border-radius:4px; display:flex; justify-content:space-between; align-items:center;">
                                <span style="color:var(--text-muted);">Preview Tampilan:</span>
                                <span id="prevDecQty" style="font-weight:700; font-family:monospace; color:var(--text-main); font-size:10px;">7</span>
                            </div>
                        </div>

                        <!-- 2. Jumlah Kemasan -->
                        <div style="border:1px solid var(--border); border-radius:6px; padding:7px 10px; background:var(--bg-card);">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px; gap:8px;">
                                <div style="min-width:0;">
                                    <strong style="font-size:10.5px; color:var(--text-main); display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">2. Jumlah Kemasan</strong>
                                    <div style="font-size:9px; color:var(--text-muted); font-family:monospace; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">jumlah_kemasan (barang, kemasan)</div>
                                </div>
                                <select id="cfgDecKemasan" onchange="updateDecimalLivePreviews()" style="padding:2px 6px; font-size:10px; font-weight:600; height:24px; border:1px solid var(--border); border-radius:4px; background:var(--bg-input); color:var(--primary); cursor:pointer; flex-shrink:0;">
                                    <option value="0" selected>0 Desimal (95 - Bulat)</option>
                                    <option value="1">1 Desimal (95.0)</option>
                                    <option value="2">2 Desimal (95.00)</option>
                                    <option value="3">3 Desimal</option>
                                    <option value="4">4 Desimal</option>
                                </select>
                            </div>
                            <div style="font-size:9.5px; background:var(--bg-input); padding:3px 8px; border-radius:4px; display:flex; justify-content:space-between; align-items:center;">
                                <span style="color:var(--text-muted);">Preview Tampilan:</span>
                                <span id="prevDecKemasan" style="font-weight:700; font-family:monospace; color:var(--text-main); font-size:10px;">95</span>
                            </div>
                        </div>

                        <!-- 3. Nilai Uang & Transaksi -->
                        <div style="border:1px solid var(--border); border-radius:6px; padding:7px 10px; background:var(--bg-card);">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px; gap:8px;">
                                <div style="min-width:0;">
                                    <strong style="font-size:10.5px; color:var(--text-main); display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">3. Nilai Uang / Transaksi (CIF, FOB, Pungutan, dll)</strong>
                                    <div style="font-size:9px; color:var(--text-muted); font-family:monospace; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">cif, fob, harga_penyerahan, nilai_barang, pungutan</div>
                                </div>
                                <select id="cfgDecUang" onchange="updateDecimalLivePreviews()" style="padding:2px 6px; font-size:10px; font-weight:600; height:24px; border:1px solid var(--border); border-radius:4px; background:var(--bg-input); color:var(--primary); cursor:pointer; flex-shrink:0;">
                                    <option value="0">0 Desimal (Rp 4,208,141)</option>
                                    <option value="2" selected>2 Desimal (Rp 4,208,141.25 - Standar)</option>
                                    <option value="3">3 Desimal</option>
                                    <option value="4">4 Desimal</option>
                                </select>
                            </div>
                            <div style="font-size:9.5px; background:var(--bg-input); padding:3px 8px; border-radius:4px; display:flex; justify-content:space-between; align-items:center;">
                                <span style="color:var(--text-muted);">Preview Tampilan:</span>
                                <span id="prevDecUang" style="font-weight:700; font-family:monospace; color:var(--text-main); font-size:10px;">4,208,141.25</span>
                            </div>
                        </div>

                        <!-- 4. Harga Satuan Barang -->
                        <div style="border:1px solid var(--border); border-radius:6px; padding:7px 10px; background:var(--bg-card);">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px; gap:8px;">
                                <div style="min-width:0;">
                                    <strong style="font-size:10.5px; color:var(--text-main); display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">4. Harga Satuan Barang</strong>
                                    <div style="font-size:9px; color:var(--text-muted); font-family:monospace; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">harga_satuan (barang)</div>
                                </div>
                                <select id="cfgDecHargaSatuan" onchange="updateDecimalLivePreviews()" style="padding:2px 6px; font-size:10px; font-weight:600; height:24px; border:1px solid var(--border); border-radius:4px; background:var(--bg-input); color:var(--primary); cursor:pointer; flex-shrink:0;">
                                    <option value="0">0 Desimal (601,163)</option>
                                    <option value="2">2 Desimal (601,163.04)</option>
                                    <option value="4" selected>4 Desimal (601,163.0357 - Presisi)</option>
                                    <option value="5">5 Desimal</option>
                                    <option value="6">6 Desimal</option>
                                </select>
                            </div>
                            <div style="font-size:9.5px; background:var(--bg-input); padding:3px 8px; border-radius:4px; display:flex; justify-content:space-between; align-items:center;">
                                <span style="color:var(--text-muted);">Preview Tampilan:</span>
                                <span id="prevDecHargaSatuan" style="font-weight:700; font-family:monospace; color:var(--text-main); font-size:10px;">601,163.0357</span>
                            </div>
                        </div>

                        <!-- 5. Berat & Ukuran -->
                        <div style="border:1px solid var(--border); border-radius:6px; padding:7px 10px; background:var(--bg-card);">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px; gap:8px;">
                                <div style="min-width:0;">
                                    <strong style="font-size:10.5px; color:var(--text-main); display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">5. Berat &amp; Ukuran (Netto, Bruto, Volume)</strong>
                                    <div style="font-size:9px; color:var(--text-muted); font-family:monospace; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">netto, bruto, volume (header &amp; barang)</div>
                                </div>
                                <select id="cfgDecBerat" onchange="updateDecimalLivePreviews()" style="padding:2px 6px; font-size:10px; font-weight:600; height:24px; border:1px solid var(--border); border-radius:4px; background:var(--bg-input); color:var(--primary); cursor:pointer; flex-shrink:0;">
                                    <option value="0">0 Desimal (3,842 kg)</option>
                                    <option value="2">2 Desimal (3,841.83 kg)</option>
                                    <option value="3">3 Desimal (3,841.834 kg)</option>
                                    <option value="4" selected>4 Desimal (3,841.8340 kg - Standar BC)</option>
                                    <option value="5">5 Desimal</option>
                                    <option value="6">6 Desimal</option>
                                </select>
                            </div>
                            <div style="font-size:9.5px; background:var(--bg-input); padding:3px 8px; border-radius:4px; display:flex; justify-content:space-between; align-items:center;">
                                <span style="color:var(--text-muted);">Preview Tampilan:</span>
                                <span id="prevDecBerat" style="font-weight:700; font-family:monospace; color:var(--text-main); font-size:10px;">3,841.8340</span>
                            </div>
                        </div>

                        <!-- 6. Tarif Pajak & Fasilitas -->
                        <div style="border:1px solid var(--border); border-radius:6px; padding:7px 10px; background:var(--bg-card);">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px; gap:8px;">
                                <div style="min-width:0;">
                                    <strong style="font-size:10.5px; color:var(--text-main); display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">6. Tarif Pajak / Fasilitas (%)</strong>
                                    <div style="font-size:9px; color:var(--text-muted); font-family:monospace; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">tarif, tarif_ppn_pajak, tarif_ppnbm_pajak</div>
                                </div>
                                <select id="cfgDecTarif" onchange="updateDecimalLivePreviews()" style="padding:2px 6px; font-size:10px; font-weight:600; height:24px; border:1px solid var(--border); border-radius:4px; background:var(--bg-input); color:var(--primary); cursor:pointer; flex-shrink:0;">
                                    <option value="0">0 Desimal (11%)</option>
                                    <option value="1">1 Desimal (11.0%)</option>
                                    <option value="2" selected>2 Desimal (11.00% - Standar)</option>
                                    <option value="3">3 Desimal</option>
                                    <option value="4">4 Desimal</option>
                                </select>
                            </div>
                            <div style="font-size:9.5px; background:var(--bg-input); padding:3px 8px; border-radius:4px; display:flex; justify-content:space-between; align-items:center;">
                                <span style="color:var(--text-muted);">Preview Tampilan:</span>
                                <span id="prevDecTarif" style="font-weight:700; font-family:monospace; color:var(--text-main); font-size:10px;">11.00</span>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- MODAL FOOTER -->
            <div class="modal-footer" style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <button type="button" id="btnResetToGlobal" class="btn btn-danger btn-sm" onclick="resetScopeToGlobal()" style="display:none; background:#ff4d4f; border-color:#cf1322; color:#fff;" title="Hapus setting khusus perusahaan ini agar kembali mengikuti Global">
                        🔄 Reset ke Settingan Global
                    </button>
                </div>
                <div style="display:flex; gap:6px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('modalFilterSettings')">Batal</button>
                    <button type="button" class="btn btn-primary" id="btnSaveFilterSettings" onclick="saveFilterSettings()">💾 Simpan Pengaturan</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL SETTING LIST ENTITAS DEFAULT (bc_ceisa_entidx) -->
    <div class="modal-overlay" id="modalSettingEntidx" style="display:none;">
        <div class="modal" style="max-width:760px; width:95vw; height:85vh; display:flex; flex-direction:column;">
            <div class="modal-header">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="font-size:18px;">⚙️</span>
                    <div>
                        <h3 style="margin:0; font-size:14px; font-weight:700;">Setting List Entitas Default Dokumen BC</h3>
                        <span style="font-size:10px; color:var(--text-muted);">Atur urutan default entitas yang otomatis dibuat saat AJU Baru (tabel <code>bc_ceisa_entidx</code>)</span>
                    </div>
                </div>
                <button class="btn btn-secondary btn-sm" onclick="closeModal('modalSettingEntidx')">✕</button>
            </div>

            <!-- CONTROLS BAR: BC SELECTOR, SCOPE SELECTOR -->
            <div style="padding:10px 14px; background:var(--bg-card); border-bottom:1px solid var(--border); display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between;">
                <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                    <div>
                        <label style="font-size:10px; font-weight:700; color:var(--text-muted); display:block; margin-bottom:2px;">DOKUMEN BC:</label>
                        <select id="selEntidxBc" onchange="loadEntidxList()" style="font-weight:700; padding:4px 8px; font-size:11px; border:1px solid var(--border); border-radius:4px; background:var(--bg-input); color:var(--text-main);">
                            <optgroup label="── PEMASUKAN ──">
                                <option value="16">BC 1.6 — Pemasukan ke PLB</option>
                                <option value="20">BC 2.0 — Impor Non-Fasilitas (PIB)</option>
                                <option value="23">BC 2.3 — Impor ke Kawasan Berikat</option>
                                <option value="262">BC 2.6.2 — Pemasukan Subcon Kembali</option>
                                <option value="27">BC 2.7 — Pemasukan Antar TPB</option>
                                <option value="28">BC 2.8 — Impor dari PLB</option>
                                <option value="40" selected>BC 4.0 — Pemasukan dari Lokal</option>
                                <option value="611">PPKEK 6.1.1 — Pemasukan ke KEK</option>
                            </optgroup>
                            <optgroup label="── PENGELUARAN ──">
                                <option value="25">BC 2.5 — Pengeluaran ke Lokal</option>
                                <option value="261">BC 2.6.1 — Pengeluaran Subkontrak</option>
                                <option value="30">BC 3.0 — Ekspor</option>
                                <option value="33">BC 3.3 — Ekspor dari PLB</option>
                                <option value="331">BC 3.3.1 — P3BET PLB</option>
                                <option value="41">BC 4.1 — Pengeluaran ke Lokal (Asal BC 4.0)</option>
                                <option value="613">PPKEK 6.1.3 — Pengeluaran dari KEK</option>
                            </optgroup>
                        </select>
                    </div>

                    <div>
                        <label style="font-size:10px; font-weight:700; color:var(--text-muted); display:block; margin-bottom:2px;">LINGKUP (TARGET):</label>
                        <select id="selEntidxScope" onchange="loadEntidxList()" style="font-weight:700; padding:4px 8px; font-size:11px; border:1px solid var(--border); border-radius:4px; background:var(--bg-input); color:var(--text-main); min-width:240px;">
                            <option value="global">🌐 GLOBAL (Semua Perusahaan)</option>
                            <option value="company" id="optEntidxCmpScope">🏢 PERUSAHAAN AKTIF</option>
                        </select>
                    </div>
                </div>

                <div style="display:flex; gap:6px;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="addEntidxRow()">➕ Tambah Baris</button>
                </div>
            </div>

            <!-- TABLE WRAPPER -->
            <div style="flex:1; overflow-y:auto; padding:12px 14px; background:var(--bg-base);">
                <table class="datagrid" id="tableEntidxSetting" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="width:50px; text-align:center;">Urutan</th>
                            <th style="width:280px;">Role / Kode Entitas</th>
                            <th>Keterangan / Peran</th>
                            <th style="width:110px; text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyEntidxSetting">
                        <!-- Loaded dynamically -->
                    </tbody>
                </table>
            </div>

            <!-- MODAL FOOTER -->
            <div class="modal-footer" style="display:flex; justify-content:space-between; align-items:center;">
                <span id="entidxStatusInfo" style="font-size:11px; color:var(--text-muted);">Memuat setting...</span>
                <div style="display:flex; gap:6px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('modalSettingEntidx')">Tutup</button>
                    <button type="button" class="btn btn-primary" onclick="saveEntidxSetting()">💾 Simpan Setting Entitas</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL SETTING LIST DOKUMEN DEFAULT (bc_ceisa_docidx) -->
    <div class="modal-overlay" id="modalSettingDocidx" style="display:none;">
        <div class="modal" style="max-width:760px; width:95vw; height:85vh; display:flex; flex-direction:column;">
            <div class="modal-header">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="font-size:18px;">⚙️</span>
                    <div>
                        <h3 style="margin:0; font-size:14px; font-weight:700;">Setting List Dokumen Lampiran Default Dokumen BC</h3>
                        <span style="font-size:10px; color:var(--text-muted);">Atur urutan default dokumen lampiran yang otomatis dibuat saat AJU Baru (tabel <code>bc_ceisa_docidx</code>)</span>
                    </div>
                </div>
                <button class="btn btn-secondary btn-sm" onclick="closeModal('modalSettingDocidx')">✕</button>
            </div>

            <!-- CONTROLS BAR: BC SELECTOR, SCOPE SELECTOR -->
            <div style="padding:10px 14px; background:var(--bg-card); border-bottom:1px solid var(--border); display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between;">
                <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                    <div>
                        <label style="font-size:10px; font-weight:700; color:var(--text-muted); display:block; margin-bottom:2px;">DOKUMEN BC:</label>
                        <select id="selDocidxBc" onchange="loadDocidxList()" style="font-weight:700; padding:4px 8px; font-size:11px; border:1px solid var(--border); border-radius:4px; background:var(--bg-input); color:var(--text-main);">
                            <optgroup label="── PEMASUKAN ──">
                                <option value="16">BC 1.6 — Pemasukan ke PLB</option>
                                <option value="20">BC 2.0 — Impor Non-Fasilitas (PIB)</option>
                                <option value="23">BC 2.3 — Impor ke Kawasan Berikat</option>
                                <option value="262">BC 2.6.2 — Pemasukan Subcon Kembali</option>
                                <option value="27">BC 2.7 — Pemasukan Antar TPB</option>
                                <option value="28">BC 2.8 — Impor dari PLB</option>
                                <option value="40" selected>BC 4.0 — Pemasukan dari Lokal</option>
                                <option value="611">PPKEK 6.1.1 — Pemasukan ke KEK</option>
                            </optgroup>
                            <optgroup label="── PENGELUARAN ──">
                                <option value="25">BC 2.5 — Pengeluaran ke Lokal</option>
                                <option value="261">BC 2.6.1 — Pengeluaran Subkontrak</option>
                                <option value="30">BC 3.0 — Ekspor</option>
                                <option value="33">BC 3.3 — Ekspor dari PLB</option>
                                <option value="331">BC 3.3.1 — P3BET PLB</option>
                                <option value="41">BC 4.1 — Pengeluaran ke Lokal (Asal BC 4.0)</option>
                                <option value="613">PPKEK 6.1.3 — Pengeluaran dari KEK</option>
                            </optgroup>
                        </select>
                    </div>

                    <div>
                        <label style="font-size:10px; font-weight:700; color:var(--text-muted); display:block; margin-bottom:2px;">LINGKUP (TARGET):</label>
                        <select id="selDocidxScope" onchange="loadDocidxList()" style="font-weight:700; padding:4px 8px; font-size:11px; border:1px solid var(--border); border-radius:4px; background:var(--bg-input); color:var(--text-main); min-width:240px;">
                            <option value="global">🌐 GLOBAL (Semua Perusahaan)</option>
                            <option value="company" id="optDocidxCmpScope">🏢 PERUSAHAAN AKTIF</option>
                        </select>
                    </div>
                </div>

                <div style="display:flex; gap:6px;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="addDocidxRow()">➕ Tambah Baris</button>
                </div>
            </div>

            <!-- TABLE WRAPPER -->
            <div style="flex:1; overflow-y:auto; padding:12px 14px; background:var(--bg-base);">
                <table class="datagrid" id="tableDocidxSetting" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="width:50px; text-align:center;">Urutan</th>
                            <th style="width:320px;">Jenis / Kode Dokumen Lampiran</th>
                            <th>Keterangan / Fungsi Dokumen</th>
                            <th style="width:110px; text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyDocidxSetting">
                        <!-- Loaded dynamically -->
                    </tbody>
                </table>
            </div>

            <!-- MODAL FOOTER -->
            <div class="modal-footer" style="display:flex; justify-content:space-between; align-items:center;">
                <span id="docidxStatusInfo" style="font-size:11px; color:var(--text-muted);">Memuat setting...</span>
                <div style="display:flex; gap:6px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('modalSettingDocidx')">Tutup</button>
                    <button type="button" class="btn btn-primary" onclick="saveDocidxSetting()">💾 Simpan Setting Dokumen</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL CARI ENTITAS DARI HISTORY (LOOKUP ENTITAS) -->
    <div class="modal-overlay" id="modalLookupEntitas" style="display:none;">
        <div class="modal" style="max-width:980px; width:95vw; height:85vh; display:flex; flex-direction:column;">
            <div class="modal-header">
                <div style="display:flex; align-items:center; gap:6px;">
                    <span style="font-size:13px;">🔍</span>
                    <div>
                        <h3 style="margin:0; font-size:11.5px; font-weight:700; color:var(--text-main);">Cari Entitas dari History Dokumen</h3>
                        <span style="font-size:9.5px; color:var(--text-muted);">Pilih data entitas (Partner / Pemasok / Pengirim / Penerima) yang pernah digunakan sebelumnya</span>
                    </div>
                </div>
                <button class="btn btn-secondary btn-sm" onclick="closeModal('modalLookupEntitas')">✕</button>
            </div>

            <!-- SEARCH BAR -->
            <div style="padding:8px 12px; background:var(--bg-card); border-bottom:1px solid var(--border); display:flex; flex-wrap:wrap; gap:8px; align-items:center; justify-content:space-between;">
                <div style="display:flex; gap:8px; align-items:center; flex:1; min-width:300px;">
                    <div style="position:relative; flex:1;">
                        <input type="text" id="lookupEntitasQuery" class="form-control" placeholder="🔍 Ketik Nama PT, NPWP, atau Alamat..." oninput="debounceLookupEntitas()" style="padding-left:26px; font-size:11px; height:28px; width:100%;">
                        <span style="position:absolute; left:7px; top:6px; font-size:11px; opacity:0.5;">🔎</span>
                    </div>
                    <select id="lookupEntitasRoleFilter" onchange="fetchLookupEntitas()" style="padding:3px 6px; font-size:11px; border:1px solid var(--border); border-radius:4px; background:var(--bg-input); color:var(--text-main); height:28px;">
                        <option value="">Semua Role Entitas</option>
                        <option value="9">Pengirim (9)</option>
                        <option value="5">Pemasok / Supplier (5)</option>
                        <option value="8">Penerima (8)</option>
                        <option value="6">Pembeli / Buyer (6)</option>
                        <option value="3">Pengusaha TPB (3)</option>
                        <option value="7">Pemilik Barang (7)</option>
                        <option value="2">Importir (2)</option>
                        <option value="4">PPJK (4)</option>
                    </select>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="fetchLookupEntitas()" style="height:28px; padding:2px 10px; font-size:11px;">Cari</button>
                </div>
                <span id="lookupEntitasCount" style="font-size:10.5px; color:var(--text-muted); font-weight:600;">0 entitas ditemukan</span>
            </div>

            <!-- RESULT TABLE -->
            <div style="flex:1; overflow-y:auto; overflow-x:auto; background:var(--bg-card); position:relative;">
                <table class="datagrid" id="tableLookupEntitas" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="width:36px; text-align:center;">No</th>
                            <th style="width:220px;">Nama Entitas</th>
                            <th style="width:160px;">NPWP / NITKU</th>
                            <th style="width:85px;">Role</th>
                            <th>Alamat Entitas</th>
                            <th style="width:100px;">NIB</th>
                            <th style="width:50px; text-align:center;">Negara</th>
                            <th style="width:65px; text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyLookupEntitas">
                        <!-- Loaded dynamically -->
                    </tbody>
                </table>
            </div>

            <!-- MODAL FOOTER -->
            <div class="modal-footer" style="display:flex; justify-content:space-between; align-items:center;">
                <span style="font-size:11px; color:var(--text-muted);">💡 Tip: Klik tombol "Pilih" atau Dobel Klik pada baris untuk langsung mengisi form entitas aktif.</span>
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalLookupEntitas')">Tutup</button>
            </div>
        </div>
    </div>


    <!-- MODAL PASTE DARI EXCEL -->
    <div class="modal-overlay" id="modalPasteExcel" style="display:none;">
        <div class="modal" style="max-width:850px; width:95vw; height:85vh; display:flex; flex-direction:column;">
            <div class="modal-header">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="font-size:18px;">📥</span>
                    <div>
                        <h3 style="margin:0; font-size:13.5px; font-weight:700;" id="pasteExcelModalTitle">Paste Data dari Excel</h3>
                        <span style="font-size:10px; color:var(--text-muted);">Salin (Ctrl+C) data baris dari Microsoft Excel / Spreadsheet lalu tempel (Ctrl+V) di kotak bawah</span>
                    </div>
                </div>
                <button class="btn btn-secondary btn-sm" onclick="closeModal('modalPasteExcel')">✕</button>
            </div>

            <!-- OPTIONS BAR -->
            <div style="padding:10px 14px; background:var(--bg-card); border-bottom:1px solid var(--border); display:flex; flex-wrap:wrap; gap:14px; align-items:center; justify-content:space-between;">
                <div style="display:flex; gap:16px; align-items:center; flex-wrap:wrap;">
                    <label style="font-size:11px; font-weight:600; display:flex; align-items:center; gap:6px; cursor:pointer;">
                        <input type="radio" name="pasteExcelMode" value="append" checked style="cursor:pointer;">
                        <span>➕ Tambahkan ke Baris yang Ada (Append)</span>
                    </label>
                    <label style="font-size:11px; font-weight:600; display:flex; align-items:center; gap:6px; cursor:pointer; color:#ef4444;">
                        <input type="radio" name="pasteExcelMode" value="replace" style="cursor:pointer;">
                        <span>⚠️ Ganti / Kosongkan Baris Sebelumnya (Replace)</span>
                    </label>
                </div>
                <div style="display:flex; gap:8px;">
                    <button type="button" class="btn btn-secondary btn-xs" onclick="copyTableHeadersForExcel()" title="Salin daftar nama kolom tabel ini ke clipboard agar bisa dijadikan acuan di Excel">
                        📋 Copy Header Template
                    </button>
                </div>
            </div>

            <!-- TEXTAREA INPUT & PREVIEW -->
            <div style="flex:1; display:flex; flex-direction:column; padding:12px 14px; background:var(--bg-base); gap:10px; overflow:hidden;">
                <div style="flex:1; display:flex; flex-direction:column;">
                    <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                        <span style="font-size:10.5px; font-weight:700; color:var(--text-muted);">TEMPATKAN HASIL COPY EXCEL DI SINI (TAB-DELIMITED):</span>
                        <span id="pasteExcelStats" style="font-size:10.5px; color:var(--primary); font-weight:600;">0 baris terdeteksi</span>
                    </div>
                    <textarea id="pasteExcelTextarea" class="form-control" placeholder="Contoh format: Sorot baris di Excel -> Ctrl+C -> Klik di sini -> Ctrl+V..." style="flex:1; width:100%; font-family:Consolas, monospace; font-size:11px; white-space:pre; overflow:auto; resize:none; padding:8px;" oninput="parseExcelPreview()"></textarea>
                </div>

                <div style="height:140px; border:1px solid var(--border); border-radius:4px; background:var(--bg-card); overflow:auto; padding:0;">
                    <table class="datagrid" id="tablePasteExcelPreview" style="width:100%; font-size:10px;">
                        <thead style="position:sticky; top:0; z-index:5;">
                            <tr id="theadPasteExcelPreview">
                                <th style="width:40px; text-align:center;">#</th>
                                <th>Preview Kolom</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyPasteExcelPreview">
                            <tr><td colspan="5" style="text-align:center; color:var(--text-muted); padding:20px;">Belum ada data yang ditempel.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- MODAL FOOTER -->
            <div class="modal-footer" style="display:flex; justify-content:space-between; align-items:center;">
                <span style="font-size:10px; color:var(--text-muted);">💡 Nilai koma ribuan/titik otomatis dibersihkan dan disesuaikan tipe kolom (angka, teks, tanggal).</span>
                <div style="display:flex; gap:8px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('modalPasteExcel')">Batal</button>
                    <button type="button" class="btn btn-success" onclick="applyParsedExcelData()">📥 Masukkan Data ke Tabel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- PREVIEW MODAL -->
    <div class="modal-overlay" id="modalPreview">
        <div class="modal">
            <div class="modal-header">
                <h3>Preview JSON CEISA 4.0 (Live Builder)</h3>
                <button class="btn btn-secondary btn-sm" onclick="closeModal('modalPreview')">✕</button>
            </div>
            <div class="modal-body">
                <div style="display:flex; justify-content:space-between; margin-bottom:10px; font-size:12px; color:var(--text-muted);">
                    <span id="previewMeta">Ukuran: -</span>
                    <button class="btn btn-secondary btn-sm" onclick="copyPreviewText()">📋 Copy JSON</button>
                </div>
                <pre class="code-view" id="previewContent"></pre>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('modalPreview')">Tutup</button>
                <button class="btn btn-primary" id="btnModalTransferCeisa" onclick="transferFromModal()">🚀 Transfer ke CEISA 4.0</button>
            </div>
        </div>
    </div>

    <!-- RESULT MODAL -->
    <div class="modal-overlay" id="modalResult">
        <div class="modal" style="max-width: 860px; width: 94%;">
            <div class="modal-header">
                <h3 id="modalResultTitle">Hasil Transfer Gateway CEISA 4.0</h3>
                <button class="btn btn-secondary btn-sm" id="btnModalResultClose" onclick="closeModal('modalResult')">✕</button>
            </div>
            <div class="modal-body" id="modalResultBody"></div>
            <div class="modal-footer">
                <button class="btn btn-primary" id="btnModalResultOk" onclick="closeModal('modalResult'); reloadCurrentAju();">OK, Selesai</button>
            </div>
        </div>
    </div>

    <!-- REFF SEARCH MODAL -->
    <div class="modal-overlay" id="modalReffLookup">
        <div class="modal" style="max-width:600px;">
            <div class="modal-header">
                <h3 id="reffLookupTitle">Cari Referensi</h3>
                <button class="btn btn-secondary btn-sm" onclick="closeModal('modalReffLookup')">✕</button>
            </div>
            <div class="modal-body">
                <div style="margin-bottom:12px; display:flex; gap:8px;">
                    <input type="text" id="reffSearchQuery" placeholder="Ketik kode atau nama..." style="flex:1;" onkeyup="handleReffSearch(event)">
                    <button class="btn btn-primary btn-sm" onclick="performReffSearch()">Cari</button>
                </div>
                <div style="max-height:360px; overflow-y:auto; border:1px solid var(--border); border-radius:6px;">
                    <table class="datagrid" id="tableReffResults">
                        <thead>
                            <tr>
                                <th style="width:100px;">Kode</th>
                                <th>Deskripsi</th>
                                <th style="width:60px; text-align:center;">Pilih</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('modalReffLookup')">Tutup</button>
            </div>
        </div>
    </div>

    <!-- CONFIG AJU MODAL (bc_ceisa_setaju) -->
    <div class="modal-overlay" id="modalConfigAju">
        <div class="modal" style="max-width:550px;">
            <div class="modal-header">
                <h3>⚙️ Konfigurasi Format Penomoran AJU (26 Digit)</h3>
                <button class="btn btn-secondary btn-sm" onclick="closeModal('modalConfigAju')">✕</button>
            </div>
            <div class="modal-body">
                <div style="background:var(--bg-base); border:1px solid var(--border); border-radius:6px; padding:10px; margin-bottom:12px;">
                    <div style="font-size:10px; font-weight:700; color:var(--text-muted); margin-bottom:6px;">LIVE PREVIEW NOMOR AJU (26 DIGIT):</div>
                    <div id="cfgPreviewAju" style="font-family:monospace; font-size:13px; font-weight:700; color:var(--primary); background:var(--bg-card); padding:8px; border:1px dashed var(--primary); border-radius:4px; text-align:center;">
                        00002301081620261016000001
                    </div>
                    <div style="display:flex; justify-content:space-between; font-size:9px; color:var(--text-muted); margin-top:4px;">
                        <span>A: 4 digit Kantor</span>
                        <span>BC: 2 digit</span>
                        <span>B: 6 digit Unik</span>
                        <span>Tgl: YYYYMMDD</span>
                        <span>Seq: 6 digit</span>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px;">
                    <div>
                        <label style="font-size:10px; font-weight:600; display:block; margin-bottom:2px;">Jenis Dokumen BC:</label>
                        <select id="cfgBc" onchange="loadConfigAjuForBc(this.value)" style="width:100%;">
                            <optgroup label="── PEMASUKAN (INBOUND) ──">
                                <option value="16">BC 1.6</option>
                                <option value="20">BC 2.0</option>
                                <option value="23" selected>BC 2.3</option>
                                <option value="262">BC 2.6.2</option>
                                <option value="28">BC 2.8</option>
                                <option value="40">BC 4.0</option>
                                <option value="611">PPKEK 6.1.1</option>
                                <option value="613">PPKEK 6.1.3</option>
                            </optgroup>
                            <optgroup label="── PENGELUARAN (OUTBOUND) ──">
                                <option value="25">BC 2.5</option>
                                <option value="261">BC 2.6.1</option>
                                <option value="27">BC 2.7</option>
                                <option value="30">BC 3.0</option>
                                <option value="33">BC 3.3</option>
                                <option value="331">BC 3.3.1</option>
                                <option value="41">BC 4.1</option>
                                <option value="631">PPKEK 6.3.1</option>
                            </optgroup>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:10px; font-weight:600; display:block; margin-bottom:2px;">Sequence Terakhir (bigint):</label>
                        <input type="number" id="cfgAju" min="0" value="0" style="width:100%;" oninput="updateConfigAjuPreview()">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px;">
                    <div>
                        <label style="font-size:10px; font-weight:600; display:block; margin-bottom:2px;">A - Kode Kantor (4 Digit):</label>
                        <input type="text" id="cfgA" maxlength="4" value="0000" style="width:100%; font-family:monospace;" oninput="updateConfigAjuPreview()">
                    </div>
                    <div>
                        <label style="font-size:10px; font-weight:600; display:block; margin-bottom:2px;">B - Unik Perusahaan (6 Digit):</label>
                        <input type="text" id="cfgB" maxlength="6" value="010816" style="width:100%; font-family:monospace;" oninput="updateConfigAjuPreview()">
                    </div>
                </div>

                <div style="border-top:1px solid var(--border); padding-top:10px; margin-top:10px;">
                    <div style="font-size:10px; font-weight:700; color:var(--text-muted); margin-bottom:6px;">DATA TANDA TANGAN PEMBERITAHU (OPSIONAL):</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px;">
                        <div>
                            <label style="font-size:9.5px; display:block; margin-bottom:2px;">Nama TTD:</label>
                            <input type="text" id="cfgNamaTtd" style="width:100%;">
                        </div>
                        <div>
                            <label style="font-size:9.5px; display:block; margin-bottom:2px;">Jabatan:</label>
                            <input type="text" id="cfgJabatanTtd" style="width:100%;">
                        </div>
                        <div>
                            <label style="font-size:9.5px; display:block; margin-bottom:2px;">Kota:</label>
                            <input type="text" id="cfgKotaTtd" style="width:100%;">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('modalConfigAju')">Batal</button>
                <button class="btn btn-primary" onclick="saveConfigAju()">💾 Simpan Konfigurasi</button>
            </div>
        </div>
    </div>

    <!-- ERP INTEGRATION MODAL -->
    <div class="modal-overlay" id="modalErpIntegration">
        <div class="modal" style="max-width:920px; width:95vw;">
            <div class="modal-header" style="background:linear-gradient(135deg, rgba(99, 102, 241, 0.1), transparent);">
                <div>
                    <h3 style="color:#6366f1; display:flex; align-items:center; gap:6px;">
                        <span>🔌 Integrasi Database ERP</span>
                        <span id="erpModalDriverBadge" class="badge" style="background:#6366f1; color:#fff; font-size:10px;">FRAMAS</span>
                    </h3>
                    <div id="erpModalDriverDesc" style="font-size:10px; color:var(--text-muted); margin-top:2px;">
                        Integrasi sistem ERP ke tabel staging CEISA 4.0 (Header, Barang, Dokumen, Kemasan).
                    </div>
                </div>
                <button class="btn btn-secondary btn-sm" onclick="closeModal('modalErpIntegration')">✕</button>
            </div>
            <div class="modal-body" style="display:flex; flex-direction:column; gap:10px;">
                <!-- SETTING PROFIL & DRIVER SELECTOR -->
                <div style="background:var(--bg-base); border:1px solid var(--border); border-radius:6px; padding:8px 10px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                    <div style="display:flex; gap:12px; align-items:center;">
                        <span style="font-size:10px; color:var(--text-muted);">Perusahaan: <strong id="erpCompanyLabel" style="color:var(--text-main);">-</strong></span>
                        <span style="font-size:10px; color:var(--text-muted);">AJU Aktif: <strong id="erpAjuLabel" style="color:var(--primary);">-</strong></span>
                    </div>
                    <div style="display:flex; gap:6px; align-items:center;">
                        <span style="font-size:10px; color:var(--text-muted);">Driver ERP:</span>
                        <div style="display:inline-flex; align-items:center; gap:6px; background:rgba(99, 102, 241, 0.08); border:1px solid rgba(99, 102, 241, 0.25); border-radius:4px; padding:3px 9px;" title="Driver ERP dikonfigurasi melalui Administrator - Kelola Perusahaan">
                            <span id="erpDriverDisplay" style="font-weight:700; color:#6366f1; font-size:11px;">Memuat...</span>
                            <span style="font-size:9px; color:var(--text-muted); background:var(--bg-card); border:1px solid var(--border); padding:1px 5px; border-radius:3px; display:inline-flex; align-items:center; gap:3px;">
                                🔒 Kelola Perusahaan
                            </span>
                        </div>
                    </div>
                </div>

                <!-- FILTER SEARCH ERP -->
                <div style="background:var(--bg-base); border:1px solid var(--border); border-radius:6px; padding:8px 10px;">
                    <div style="display:grid; grid-template-columns:1.2fr 1fr 1fr 1fr auto; gap:8px; align-items:end;">
                        <div>
                            <label style="font-size:9.5px; color:var(--text-muted); display:block; margin-bottom:2px;">Jenis Transaksi:</label>
                            <select id="erpFilterType" style="width:100%; font-weight:600;">
                                <option value="outbound">Outbound (Delivery Order / Sales Invoice)</option>
                                <option value="inbound">Inbound (Purchase Order / Goods Receipt)</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:9.5px; color:var(--text-muted); display:block; margin-bottom:2px;">No Referensi / DO / PO:</label>
                            <input type="text" id="erpFilterQuery" placeholder="Cari nomor DO / PO..." style="width:100%;" onkeyup="if(event.key==='Enter') searchErpDocuments()">
                        </div>
                        <div>
                            <label style="font-size:9.5px; color:var(--text-muted); display:block; margin-bottom:2px;">Tgl Dari:</label>
                            <input type="date" id="erpFilterDateFrom" value="<?= date('Y-m-01') ?>" style="width:100%;">
                        </div>
                        <div>
                            <label style="font-size:9.5px; color:var(--text-muted); display:block; margin-bottom:2px;">Tgl Sampai:</label>
                            <input type="date" id="erpFilterDateTo" value="<?= date('Y-m-d') ?>" style="width:100%;">
                        </div>
                        <div>
                            <button type="button" class="btn btn-primary" onclick="searchErpDocuments()" style="padding:6px 14px;">
                                🔍 Cari Data
                            </button>
                        </div>
                    </div>
                </div>

                <!-- SPLIT VIEW: DAFTAR DOKUMEN & RINCIAN BARANG -->
                <div style="display:grid; grid-template-columns:1fr 1.3fr; gap:10px; height:320px;">
                    <!-- LEFT LIST OF ERP DOCUMENTS -->
                    <div style="border:1px solid var(--border); border-radius:6px; display:flex; flex-direction:column; overflow:hidden;">
                        <div style="background:var(--bg-base); padding:6px 10px; font-size:10px; font-weight:700; border-bottom:1px solid var(--border); display:flex; justify-content:space-between;">
                            <span>DAFTAR DOKUMEN ERP</span>
                            <span id="erpDocCount" style="color:var(--text-muted);">0 dokumen</span>
                        </div>
                        <div id="erpDocList" style="flex:1; overflow-y:auto; padding:4px;">
                            <div style="padding:20px; text-align:center; color:var(--text-muted); font-size:11px;">
                                Klik tombol <strong>"Cari Data"</strong> untuk memuat transaksi dari ERP.
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT PREVIEW OF ITEMS & DETAILS -->
                    <div style="border:1px solid var(--border); border-radius:6px; display:flex; flex-direction:column; overflow:hidden;">
                        <div style="background:var(--bg-base); padding:6px 10px; font-size:10px; font-weight:700; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
                            <span>RINCIAN BARANG & DOKUMEN</span>
                            <span id="erpSelectedDocId" class="badge" style="background:var(--primary); color:#fff; font-size:9px;">Belum Dipilih</span>
                        </div>
                        <div id="erpDocPreviewArea" style="flex:1; overflow-y:auto; padding:8px; font-size:10px;">
                            <div style="padding:40px 20px; text-align:center; color:var(--text-muted);">
                                Pilih salah satu dokumen di sebelah kiri untuk melihat rincian barang.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="display:flex; justify-content:space-between; align-items:center;">
                <div style="font-size:10px; color:var(--text-muted);" id="erpIntegrationHelpTip">
                    💡 Data akan otomatis mengisi <strong>Header</strong>, <strong>Barang</strong>, <strong>Dokumen</strong>, dan <strong>Kemasan</strong>.
                </div>
                <div style="display:flex; gap:6px;">
                    <button class="btn btn-secondary" onclick="closeModal('modalErpIntegration')">Batal</button>
                    <button class="btn btn-primary btn-erp" id="btnExecuteIntegration" onclick="executeErpIntegration()" disabled>
                        ⚡ Masukkan ke Dokumen AJU (Staging)
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- AI DOCUMENT READER MODAL -->
    <div class="modal-overlay" id="modalAiReader">
        <div class="modal" style="max-width:1050px; width:96vw; max-height:92vh; display:flex; flex-direction:column;">
            <div class="modal-header" style="background:linear-gradient(135deg, rgba(139, 92, 246, 0.12), rgba(236, 72, 153, 0.08));">
                <div>
                    <h3 style="color:#8b5cf6; display:flex; align-items:center; gap:8px;">
                        <span>🤖 AI Reader Dokumen Pabean</span>
                        <span id="aiActiveModelBadge" class="badge" style="background:#8b5cf6; color:#fff; font-size:9px;">Gemini 2.0 Flash</span>
                    </h3>
                    <div style="font-size:10px; color:var(--text-muted); margin-top:2px;">
                        Ekstraksi cerdas dokumen impor (Invoice, Bill of Lading, Packing List) ke tabel staging CEISA 4.0 (Header, Barang, Entitas, Dokumen, Kemasan).
                    </div>
                </div>
                <div style="display:flex; gap:6px; align-items:center;">
                    <button class="btn btn-secondary btn-sm" onclick="openAiConfigModal()" title="Konfigurasi AI Provider, Model, & API Key">
                        ⚙️ Pengaturan AI
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="closeModal('modalAiReader')">✕</button>
                </div>
            </div>

            <div class="modal-body" style="display:flex; flex-direction:column; gap:12px; overflow-y:auto; flex:1;">
                <!-- DOKUMEN TARGET & PILIHAN JENIS BC -->
                <div style="background:var(--bg-base); border:1px solid var(--border); border-radius:6px; padding:10px 12px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div style="display:flex; gap:14px; align-items:center; flex-wrap:wrap;">
                        <div>
                            <label style="font-size:10px; font-weight:700; color:var(--text-muted); display:block; margin-bottom:2px;">TARGET AJU STAGING:</label>
                            <select id="aiTargetAju" class="form-control" style="font-size:11px; font-weight:600; padding:4px 8px; min-width:240px;">
                                <option value="CURRENT">Dokumen AJU Aktif Saat Ini</option>
                                <option value="NEW" selected>✨ [+ Buat Dokumen AJU Baru Otomatis]</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:10px; font-weight:700; color:var(--text-muted); display:block; margin-bottom:2px;">JENIS DOKUMEN PABEAN:</label>
                            <select id="aiKodeBc" class="form-control" style="font-size:11px; font-weight:600; padding:4px 8px; min-width:200px;">
                                <optgroup label="── PEMASUKAN (INBOUND) ──">
                                    <option value="16">BC 1.6 - Pemasukan ke PLB</option>
                                    <option value="20" selected>BC 2.0 - Impor untuk Dipakai (PIB)</option>
                                    <option value="23">BC 2.3 - Impor ke TPB (Kawasan Berikat)</option>
                                    <option value="40">BC 4.0 - Pemasukan TLDDP ke TPB</option>
                                </optgroup>
                                <optgroup label="── PENGELUARAN (OUTBOUND) ──">
                                    <option value="30">BC 3.0 - Pemberitahuan Ekspor Barang (PEB)</option>
                                </optgroup>
                            </select>
                        </div>
                    </div>
                    <div id="aiConfigStatusBadge" style="font-size:11px; display:flex; align-items:center; gap:6px;">
                        <!-- Will display config status e.g. API Key Ready -->
                    </div>
                </div>

                <!-- DRAG & DROP UPLOAD ZONE -->
                <div class="ai-dropzone" id="aiDropZone" onclick="document.getElementById('aiFileInput').click()">
                    <input type="file" id="aiFileInput" multiple accept=".pdf,.docx,.doc,.xlsx,.xls,.csv,.txt,.png,.jpg,.jpeg" style="display:none;" onchange="handleAiFileSelect(event)">
                    <div style="font-size:32px; margin-bottom:4px;">📄 📂 📥</div>
                    <div style="font-weight:700; font-size:13px; color:#8b5cf6;">
                        Seret & Lepas Dokumen Impor ke Sini
                    </div>
                    <div style="font-size:10.5px; color:var(--text-muted); margin-top:4px;">
                        Mendukung <strong>PDF (Invoice / BL), Word (.docx), Excel (.xlsx / .csv), dan Gambar Scan (.png, .jpg)</strong>.
                    </div>
                    <div style="font-size:9.5px; color:var(--text-muted); margin-top:2px;">
                        💡 Anda dapat memilih beberapa file sekaligus (misal: Invoice + B/L + Packing List).
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm" style="margin-top:8px; pointer-events:none;">
                        📁 Pilih Berkas Dari Komputer
                    </button>
                </div>

                <!-- QUEUE LIST BERKAS TERPILIH -->
                <div id="aiFileListContainer" style="display:none; background:var(--bg-base); border:1px solid var(--border); border-radius:6px; padding:8px 10px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                        <span style="font-size:11px; font-weight:700;">Daftar Berkas Terpilih (<span id="aiFileCount">0</span>):</span>
                        <button type="button" class="btn btn-secondary btn-xs" onclick="clearAiFiles()">Kosongkan Berkas</button>
                    </div>
                    <div id="aiFileList" style="display:flex; flex-wrap:wrap; gap:4px;"></div>
                </div>

                <!-- PROCESSING / STATUS STEPPER -->
                <div id="aiProcessContainer" style="display:none; background:rgba(139, 92, 246, 0.05); border:1px solid rgba(139, 92, 246, 0.3); border-radius:6px; padding:12px; text-align:center;">
                    <div class="ai-pulse" style="font-size:24px; margin-bottom:6px;">🤖 ⚡</div>
                    <div id="aiProcessStatusText" style="font-weight:700; font-size:12px; color:#8b5cf6;">
                        Sedang membaca & mengekstrak data kepabeanan...
                    </div>
                    <div style="font-size:10px; color:var(--text-muted); margin-top:4px;">
                        Mohon tunggu beberapa saat selagi AI memproses dokumen & mencocokkan ke format CEISA 4.0.
                    </div>
                </div>

                <!-- ACTION BUTTON: MULAI EKSTRAKSI -->
                <div id="aiExtractActionRow" style="display:flex; justify-content:flex-end;">
                    <button type="button" class="btn btn-primary btn-ai-reader" id="btnRunAiExtraction" onclick="runAiExtraction()" disabled style="padding:8px 16px; font-size:12px;">
                        🚀 Mulai Baca & Ekstrak Data Dokumen
                    </button>
                </div>

                <!-- EXTRACTION PREVIEW CONTAINER (TABS) -->
                <div id="aiResultPreviewContainer" style="display:none; border:1px solid var(--border); border-radius:6px; overflow:hidden;">
                    <div style="background:var(--bg-card); padding:8px 12px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
                        <div style="font-weight:700; font-size:12px; color:var(--primary); display:flex; align-items:center; gap:6px;">
                            <span>✅ Hasil Ekstraksi AI (Review Pabean)</span>
                        </div>
                        <div style="font-size:10px; color:var(--text-muted);">
                            Periksa atau sesuaikan data di bawah sebelum dimasukkan ke staging
                        </div>
                    </div>

                    <div style="background:var(--bg-base); padding:6px 10px;">
                        <div class="ai-preview-tabs">
                            <button type="button" class="ai-preview-tab-btn active" data-tab="aiTabHeader" onclick="switchAiPreviewTab('aiTabHeader')">
                                📋 Header & Valuta
                            </button>
                            <button type="button" class="ai-preview-tab-btn" data-tab="aiTabEntitas" onclick="switchAiPreviewTab('aiTabEntitas')">
                                🏢 Entitas (<span id="aiBadgeEntitasCount">0</span>)
                            </button>
                            <button type="button" class="ai-preview-tab-btn" data-tab="aiTabDokumen" onclick="switchAiPreviewTab('aiTabDokumen')">
                                📄 Dokumen Pelengkap (<span id="aiBadgeDokumenCount">0</span>)
                            </button>
                            <button type="button" class="ai-preview-tab-btn" data-tab="aiTabKemasan" onclick="switchAiPreviewTab('aiTabKemasan')">
                                📦 Kemasan (<span id="aiBadgeKemasanCount">0</span>)
                            </button>
                            <button type="button" class="ai-preview-tab-btn" data-tab="aiTabBarang" onclick="switchAiPreviewTab('aiTabBarang')">
                                📦 Rincian Barang (<span id="aiBadgeBarangCount">0</span> item)
                            </button>
                        </div>

                        <!-- TAB CONTENT: HEADER -->
                        <div id="aiTabHeader" class="ai-preview-tab-content">
                            <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:8px;">
                                <div class="form-group">
                                    <label>Valuta (Mata Uang):</label>
                                    <input type="text" id="aiPrevValuta" class="form-control" style="text-transform:uppercase;">
                                </div>
                                <div class="form-group">
                                    <label>Total Nilai FOB:</label>
                                    <input type="number" step="0.01" id="aiPrevFob" class="form-control" oninput="recalcAiCif()">
                                </div>
                                <div class="form-group">
                                    <label>Freight:</label>
                                    <input type="number" step="0.01" id="aiPrevFreight" class="form-control" oninput="recalcAiCif()">
                                </div>
                                <div class="form-group">
                                    <label>Asuransi:</label>
                                    <input type="number" step="0.01" id="aiPrevAsuransi" class="form-control" oninput="recalcAiCif()">
                                </div>
                                <div class="form-group">
                                    <label>Total Nilai CIF:</label>
                                    <input type="number" step="0.01" id="aiPrevCif" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label>Cara Angkut:</label>
                                    <select id="aiPrevCaraAngkut" class="form-control">
                                        <option value="1">1 - LAUT</option>
                                        <option value="4">4 - UDARA</option>
                                        <option value="3">3 - DARAT</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Nama Pengangkut (Kapal/Pesawat):</label>
                                    <input type="text" id="aiPrevNamaAngkut" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label>Nomor Pengangkut (Voy / Flight):</label>
                                    <input type="text" id="aiPrevNoAngkut" class="form-control">
                                </div>
                            </div>
                        </div>

                        <!-- TAB CONTENT: ENTITAS -->
                        <div id="aiTabEntitas" class="ai-preview-tab-content" style="display:none;">
                            <div class="table-container" style="max-height:220px;">
                                <table class="data-table" id="aiTableEntitas">
                                    <thead>
                                        <tr>
                                            <th>Peran Entitas</th>
                                            <th>Nama Perusahaan / Supplier</th>
                                            <th>Alamat Lengkap</th>
                                            <th>Negara</th>
                                            <th>No. Identitas / Tax ID</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>

                        <!-- TAB CONTENT: DOKUMEN -->
                        <div id="aiTabDokumen" class="ai-preview-tab-content" style="display:none;">
                            <div class="table-container" style="max-height:220px;">
                                <table class="data-table" id="aiTableDokumen">
                                    <thead>
                                        <tr>
                                            <th>Kode Dok</th>
                                            <th>Jenis Dokumen Pabean</th>
                                            <th>Nomor Dokumen</th>
                                            <th>Tanggal Dokumen</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>

                        <!-- TAB CONTENT: KEMASAN -->
                        <div id="aiTabKemasan" class="ai-preview-tab-content" style="display:none;">
                            <div class="table-container" style="max-height:220px;">
                                <table class="data-table" id="aiTableKemasan">
                                    <thead>
                                        <tr>
                                            <th>Seri</th>
                                            <th>Jumlah Kemasan</th>
                                            <th>Kode Jenis (CT/PK/PX)</th>
                                            <th>Merek Kemasan</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>

                        <!-- TAB CONTENT: BARANG -->
                        <div id="aiTabBarang" class="ai-preview-tab-content" style="display:none;">
                            <div class="table-container" style="max-height:250px;">
                                <table class="data-table" id="aiTableBarang">
                                    <thead>
                                        <tr>
                                            <th>Seri</th>
                                            <th>Kode Barang</th>
                                            <th>Pos Tarif / HS Code</th>
                                            <th>Uraian Deskripsi Barang</th>
                                            <th>Jumlah (Qty)</th>
                                            <th>Satuan</th>
                                            <th>Harga Satuan</th>
                                            <th>Total Nilai FOB</th>
                                            <th>Negara Asal</th>
                                            <th>Netto (KG)</th>
                                            <th>Bruto (KG)</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer" style="display:flex; justify-content:space-between; align-items:center;">
                <div style="font-size:10.5px; color:var(--text-muted);" id="aiFooterSummary">
                    💡 Seret dokumen Invoice / B/L Anda, lalu klik Mulai Ekstrak.
                </div>
                <div style="display:flex; gap:6px;">
                    <button class="btn btn-secondary" onclick="closeModal('modalAiReader')">Tutup</button>
                    <button class="btn btn-primary btn-ai-reader" id="btnExecuteAiInject" onclick="executeAiInject()" disabled>
                        ⚡ Masukkan ke Staging AJU
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- AI CONFIGURATION MODAL -->
    <div class="modal-overlay" id="modalAiConfig">
        <div class="modal" style="max-width:720px; width:92vw; max-height:90vh; display:flex; flex-direction:column;">
            <div class="modal-header" style="background:linear-gradient(135deg, rgba(139, 92, 246, 0.15), transparent);">
                <div>
                    <h3 style="color:#8b5cf6; display:flex; align-items:center; gap:8px;">
                        <span>⚙️ Konfigurasi AI Provider & API Key</span>
                    </h3>
                    <div style="font-size:10px; color:var(--text-muted); margin-top:2px;">
                        Atur penyedia model AI (Google Gemini, OpenAI, Claude, DeepSeek, Ollama, dll.) atau gunakan raw JSON fleksibel.
                    </div>
                </div>
                <button class="btn btn-secondary btn-sm" onclick="closeModal('modalAiConfig')">✕</button>
            </div>

            <div class="modal-body" style="display:flex; flex-direction:column; gap:12px; overflow-y:auto; flex:1;">
                <!-- TAB SELECTOR MODE: VISUAL vs RAW JSON -->
                <div class="ai-preview-tabs" style="margin-bottom:0;">
                    <button type="button" class="ai-preview-tab-btn active" id="btnConfigTabVisual" onclick="switchConfigMode('visual')">
                        🎨 Mode Visual (Form Pilihan)
                    </button>
                    <button type="button" class="ai-preview-tab-btn" id="btnConfigTabJson" onclick="switchConfigMode('json')">
                        📝 Mode Raw JSON (Bebas / Fleksibel)
                    </button>
                </div>

                <!-- PRESET QUICK BUTTONS -->
                <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                    <span style="font-size:10px; font-weight:700; color:var(--text-muted);">Pilih Template Cepat:</span>
                    <button type="button" class="btn btn-secondary btn-xs" onclick="applyAiPreset('gemini')">✨ Gemini 2.0 Flash</button>
                    <button type="button" class="btn btn-secondary btn-xs" onclick="applyAiPreset('openai')">🤖 OpenAI GPT-4o-mini</button>
                    <button type="button" class="btn btn-secondary btn-xs" onclick="applyAiPreset('claude')">🧠 Claude 3.5 Sonnet</button>
                    <button type="button" class="btn btn-secondary btn-xs" onclick="applyAiPreset('deepseek')">⚡ DeepSeek V3</button>
                    <button type="button" class="btn btn-secondary btn-xs" onclick="applyAiPreset('ollama')">🖥️ Ollama (Lokal)</button>
                </div>

                <!-- VISUAL FORM CONTAINER -->
                <div id="aiConfigVisualContainer" style="display:flex; flex-direction:column; gap:10px;">
                    <div class="form-group">
                        <label>Penyedia AI (Provider):</label>
                        <select id="cfgAiProvider" class="form-control" onchange="onProviderChange()">
                            <option value="gemini">Google Gemini (Sangat Cepat & Disarankan)</option>
                            <option value="openai">OpenAI (ChatGPT / GPT-4o / GPT-4o-mini)</option>
                            <option value="claude">Anthropic Claude (Claude 3.5 Sonnet)</option>
                            <option value="deepseek">DeepSeek (DeepSeek Chat V3)</option>
                            <option value="groq">Groq (Ultra Fast Llama / Mixtral)</option>
                            <option value="ollama">Ollama (Server Lokal / Tanpa Kuota)</option>
                            <option value="openai_compatible">OpenAI-Compatible Custom (OpenRouter / Mistral / Lainnya)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Nama Model AI:</label>
                        <input type="text" id="cfgAiModel" class="form-control" placeholder="Contoh: gemini-2.0-flash">
                        <small style="color:var(--text-muted); font-size:9.5px;" id="cfgModelHelp">
                            Model default: gemini-2.0-flash (rekomendasi untuk OCR & ekstraksi cepat).
                        </small>
                    </div>

                    <div class="form-group">
                        <label style="display:flex; justify-content:space-between;">
                            <span>API Key:</span>
                            <a href="#" id="cfgApiKeyLink" target="_blank" style="font-size:10px; color:#8b5cf6;">Dapatkan API Key di sini ↗</a>
                        </label>
                        <div style="display:flex; gap:6px;">
                            <input type="password" id="cfgAiApiKey" class="form-control" placeholder="Masukkan API Key Anda..." style="font-family:monospace;">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="toggleApiKeyVisibility()" id="btnToggleApiKeyVis">👁️</button>
                        </div>
                        <small style="color:var(--text-muted); font-size:9.5px;">
                            🔒 API Key Anda disimpan secara aman di peramban (Local Storage).
                        </small>
                    </div>

                    <div class="form-group" id="cfgEndpointGroup" style="display:none;">
                        <label>Custom Endpoint URL (API URL):</label>
                        <input type="text" id="cfgAiEndpoint" class="form-control" placeholder="https://api.deepseek.com/v1/chat/completions">
                        <small style="color:var(--text-muted); font-size:9.5px;">
                            URL endpoint lengkap untuk API yang kompatibel dengan protokol OpenAI Chat Completion.
                        </small>
                    </div>
                </div>

                <!-- RAW JSON CONTAINER -->
                <div id="aiConfigJsonContainer" style="display:none; flex-direction:column; gap:6px;">
                    <label style="font-size:11px; font-weight:700;">Konfigurasi JSON AI (Fleksibel):</label>
                    <textarea id="cfgAiRawJson" class="form-control" style="font-family:monospace; font-size:11px; height:180px; resize:vertical;"></textarea>
                    <small style="color:var(--text-muted); font-size:9.5px;">
                        Anda dapat memasukkan format JSON bebas sesuai kebutuhan custom API gateway, headers, dsb.
                    </small>
                </div>

                <!-- TEST CONNECTION STATUS -->
                <div id="aiTestConnResult" style="display:none; padding:8px 10px; border-radius:4px; font-size:11px;"></div>
            </div>

            <div class="modal-footer" style="display:flex; justify-content:space-between; align-items:center;">
                <button type="button" class="btn btn-secondary" id="btnTestAiConn" onclick="testAiConnection()">
                    🔌 Uji Koneksi AI
                </button>
                <div style="display:flex; gap:6px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('modalAiConfig')">Batal</button>
                    <button type="button" class="btn btn-primary btn-ai-reader" onclick="saveAiConfig()">
                        💾 Simpan Konfigurasi
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- MASTER TARIF BM MODAL -->
    <div class="modal-overlay" id="modalTarifBm">
        <div class="modal" style="max-width:700px; width:95vw;">
            <div class="modal-header" style="background:linear-gradient(135deg, rgba(245, 158, 11, 0.1), transparent);">
                <div>
                    <h3 style="color:#d97706; display:flex; align-items:center; gap:6px;">
                        <span>⚙️ Master Konfigurasi Tarif Bea Masuk (BM)</span>
                    </h3>
                    <div style="font-size:10px; color:var(--text-muted); margin-top:2px;">
                        Tabel referensi persentase Bea Masuk (BM) per Kode HS (bc_ceisa_tarifbm) untuk kalkulasi otomatis barang & bahan baku.
                    </div>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeModal('modalTarifBm')">✕</button>
            </div>
            <div class="modal-body" style="padding:14px;">
                <!-- BARIS INPUT HS BARU -->
                <div style="background:var(--bg-base); border:1px solid var(--border); border-radius:6px; padding:10px; margin-bottom:12px; display:flex; gap:8px; align-items:flex-end;">
                    <div style="flex:2;">
                        <label style="font-size:10px; font-weight:700; display:block; margin-bottom:3px;">Kode HS Baru:</label>
                        <input type="text" id="newBmHs" placeholder="Contoh: 39011012" style="width:100%; font-family:monospace;">
                    </div>
                    <div style="flex:1;">
                        <label style="font-size:10px; font-weight:700; display:block; margin-bottom:3px;">Tarif BM (%):</label>
                        <input type="number" id="newBmTarif" step="0.01" min="0" max="100" value="5.00" style="width:100%; text-align:right;">
                    </div>
                    <div>
                        <button type="button" class="btn btn-primary btn-sm" onclick="addNewTarifBm()">➕ Tambah / Update</button>
                    </div>
                </div>

                <!-- FILTER & REFRESH -->
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <input type="text" id="searchTarifBm" placeholder="Cari Kode HS..." oninput="filterTarifBmTable()" style="width:220px; font-size:11px; padding:4px 8px;">
                    <button type="button" class="btn btn-secondary btn-xs" onclick="loadTarifBmList()">🔄 Muat Ulang</button>
                </div>

                <!-- TABLE LIST TARIF BM -->
                <div style="max-height:320px; overflow-y:auto; border:1px solid var(--border); border-radius:6px;">
                    <table class="datagrid" style="width:100%;">
                        <thead>
                            <tr>
                                <th style="width:140px;">Kode HS</th>
                                <th style="width:130px;">Tarif BM (%)</th>
                                <th>Tgl Update</th>
                                <th style="width:80px; text-align:center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyTarifBm"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalTarifBm')">Tutup</button>
            </div>
        </div>
    </div>

    <!-- QUICK PICKER MODAL: MASTER BARANG (bc_material) -->
    <div class="modal-overlay" id="modalMaterialPicker">
        <div class="modal" style="max-width:920px; width:95vw;">
            <div class="modal-header" style="background:linear-gradient(135deg, rgba(37, 99, 235, 0.1), transparent);">
                <div>
                    <h3 style="color:#2563eb; display:flex; align-items:center; gap:6px;">
                        <span>📦 Tambah Barang dari Master Material (<code>bc_material</code>)</span>
                    </h3>
                    <div style="font-size:10px; color:var(--text-muted); margin-top:2px;">
                        Pilih barang jadi / bahan dari master produk untuk dimasukkan langsung ke tabel Rincian Barang dokumen AJU ini.
                    </div>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeModal('modalMaterialPicker')">✕</button>
            </div>
            <div class="modal-body" style="padding:14px;">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:10px; flex-wrap:wrap;">
                    <div style="display:flex; gap:8px; flex:1; max-width:550px;">
                        <input type="text" id="searchMaterialPicker" placeholder="Cari Kode Barang / Uraian / HS Code / Model..." onkeyup="debounceSearchMaterial()" style="flex:1; font-size:11px; padding:6px 10px;">
                        <select id="filterKlasMaterialPicker" onchange="loadMaterialPickerList()" style="width:140px; font-size:11px;">
                            <option value="">-- Semua Klas --</option>
                            <option value="FG">FG (Barang Jadi)</option>
                            <option value="WIP">WIP (Barang Setengah Jadi)</option>
                            <option value="RAW">RAW (Bahan Baku)</option>
                            <option value="SCRAP">SCRAP (Sisa/Scrap)</option>
                            <option value="MESIN">MESIN (Peralatan)</option>
                        </select>
                    </div>
                    <div style="display:flex; gap:6px; align-items:center;">
                        <span id="badgeSelectedMaterialCount" style="font-size:11px; font-weight:700; color:var(--primary); background:rgba(37, 99, 235, 0.1); padding:4px 8px; border-radius:4px;">0 item dipilih</span>
                        <button type="button" class="btn btn-secondary btn-xs" onclick="loadMaterialPickerList()">🔄 Segarkan</button>
                    </div>
                </div>

                <div style="max-height:360px; overflow-y:auto; border:1px solid var(--border); border-radius:6px;">
                    <table class="datagrid" style="width:100%;">
                        <thead>
                            <tr>
                                <th style="width:36px; text-align:center;">
                                    <input type="checkbox" id="chkSelectAllMaterial" onchange="toggleSelectAllMaterial(this.checked)">
                                </th>
                                <th style="width:120px;">Kode Barang</th>
                                <th>Uraian Barang</th>
                                <th style="width:95px;">HS Code</th>
                                <th style="width:65px;">Satuan</th>
                                <th style="width:75px; text-align:right;">Berat (Kg)</th>
                                <th style="width:85px; text-align:right;">Harga Satuan</th>
                                <th style="width:90px; text-align:right;">Qty Masuk</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyMaterialPicker"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer" style="display:flex; justify-content:space-between; align-items:center;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalMaterialPicker')">Batal</button>
                <button type="button" class="btn btn-primary" onclick="insertSelectedMaterials()" style="font-weight:700;">
                    ➕ Masukkan Barang Terpilih ke Dokumen
                </button>
            </div>
        </div>
    </div>

    <!-- QUICK PICKER MODAL: KOMPONEN BOM DARI MASTER BARANG (bc_material) -->
    <div class="modal-overlay" id="modalBomMaterialPicker">
        <div class="modal" style="max-width:920px; width:95vw;">
            <div class="modal-header" style="background:linear-gradient(135deg, rgba(234, 179, 8, 0.15), transparent);">
                <div>
                    <h3 style="color:#d97706; display:flex; align-items:center; gap:6px;">
                        <span>📦 Tambah Komponen BOM dari Master Material (<code>bc_material</code>)</span>
                    </h3>
                    <div id="bomPickerTargetInfo" style="font-size:11px; color:var(--text-color); margin-top:2px; font-weight:600;">
                        Target: Barang Seri #1
                    </div>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeModal('modalBomMaterialPicker')">✕</button>
            </div>
            <div class="modal-body" style="padding:14px;">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:10px; flex-wrap:wrap;">
                    <div style="display:flex; gap:8px; flex:1; max-width:550px;">
                        <input type="text" id="searchBomMaterialPicker" placeholder="Cari Kode Komponen / Uraian / HS Code / Model..." onkeyup="debounceSearchBomMaterial()" style="flex:1; font-size:11px; padding:6px 10px;">
                        <select id="filterKlasBomMaterialPicker" onchange="loadBomMaterialPickerList()" style="width:140px; font-size:11px;">
                            <option value="">-- Semua Klas --</option>
                            <option value="RAW" selected>RAW (Bahan Baku)</option>
                            <option value="WIP">WIP (Setengah Jadi)</option>
                            <option value="FG">FG (Barang Jadi)</option>
                            <option value="SCRAP">SCRAP (Sisa/Scrap)</option>
                            <option value="MESIN">MESIN (Peralatan)</option>
                        </select>
                    </div>
                    <div style="display:flex; gap:6px; align-items:center;">
                        <span id="badgeSelectedBomMaterialCount" style="font-size:11px; font-weight:700; color:#d97706; background:rgba(234, 179, 8, 0.15); padding:4px 8px; border-radius:4px;">0 komponen dipilih</span>
                        <button type="button" class="btn btn-secondary btn-xs" onclick="loadBomMaterialPickerList()">🔄 Segarkan</button>
                    </div>
                </div>

                <div style="max-height:360px; overflow-y:auto; border:1px solid var(--border); border-radius:6px;">
                    <table class="datagrid" style="width:100%;">
                        <thead>
                            <tr>
                                <th style="width:36px; text-align:center;">
                                    <input type="checkbox" id="chkSelectAllBomMaterial" onchange="toggleSelectAllBomMaterial(this.checked)">
                                </th>
                                <th style="width:130px;">Kode Komponen</th>
                                <th>Uraian Komponen</th>
                                <th style="width:95px;">HS Code</th>
                                <th style="width:65px;">Satuan</th>
                                <th style="width:80px;">Klas</th>
                                <th style="width:100px; text-align:right;">Qty Butuh</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyBomMaterialPicker"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer" style="display:flex; justify-content:space-between; align-items:center;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalBomMaterialPicker')">Batal</button>
                <button type="button" class="btn btn-warning" onclick="insertSelectedBomMaterials()" style="font-weight:700; background:#eab308; border-color:#ca8a04; color:#000;">
                    ➕ Masukkan ke BOM Barang Terpilih
                </button>
            </div>
        </div>
    </div>

    <!-- QUICK PICKER MODAL: DOKUMEN MASUK EKS-BC (bc_1ab2) -->
    <div class="modal-overlay" id="modalIncomingPicker">
        <div class="modal" style="max-width:980px; width:96vw;">
            <div class="modal-header" style="background:linear-gradient(135deg, rgba(8, 145, 178, 0.1), transparent);">
                <div>
                    <h3 style="color:#0891b2; display:flex; align-items:center; gap:6px;">
                        <span>📥 Pilih Dokumen Pemasukan Eks-BC (<code>bc_1ab2</code> IT Inventory)</span>
                    </h3>
                    <div style="font-size:10px; color:var(--text-muted); margin-top:2px;">
                        Pilih saldo dokumen pemasukan (BC 2.3 / 4.0 / 2.7 / 2.0) dari IT Inventory untuk dialokasikan ke Bahan Baku atau Tracking Trace.
                    </div>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeModal('modalIncomingPicker')">✕</button>
            </div>
            <div class="modal-body" style="padding:14px;">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:10px; flex-wrap:wrap;">
                    <div style="display:flex; gap:8px; flex:1; max-width:620px; align-items:center;">
                        <div id="wrapperTargetSeri" style="display:flex; align-items:center; gap:4px;">
                            <label style="font-size:10px; font-weight:700; white-space:nowrap;">Target Barang:</label>
                            <select id="selectTargetSeriBarang" style="font-size:11px; padding:4px 6px;"></select>
                        </div>
                        <input type="text" id="searchIncomingPicker" placeholder="Cari No AJU / No Daftar / Kode Barang / Supplier..." onkeyup="debounceSearchIncoming()" style="flex:1; font-size:11px; padding:6px 10px;">
                        <select id="filterBcIncomingPicker" onchange="loadIncomingPickerList()" style="width:110px; font-size:11px;">
                            <option value="">-- Semua BC --</option>
                            <option value="16">BC 1.6</option>
                            <option value="20">BC 2.0</option>
                            <option value="23">BC 2.3</option>
                            <option value="262">BC 2.6.2</option>
                            <option value="27">BC 2.7 IN</option>
                            <option value="28">BC 2.8</option>
                            <option value="40">BC 4.0</option>
                        </select>
                    </div>
                    <div style="display:flex; gap:6px; align-items:center;">
                        <span id="badgeSelectedIncomingCount" style="font-size:11px; font-weight:700; color:#0891b2; background:rgba(8, 145, 178, 0.1); padding:4px 8px; border-radius:4px;">0 dokumen dipilih</span>
                        <button type="button" class="btn btn-secondary btn-xs" onclick="loadIncomingPickerList()">🔄 Segarkan</button>
                    </div>
                </div>

                <div style="max-height:360px; overflow-y:auto; border:1px solid var(--border); border-radius:6px;">
                    <table class="datagrid" style="width:100%;">
                        <thead>
                            <tr>
                                <th style="width:36px; text-align:center;">Pilih</th>
                                <th style="width:45px; text-align:center;">BC</th>
                                <th style="width:140px;">No AJU Asal</th>
                                <th style="width:120px;">No / Tgl Daftar</th>
                                <th style="width:105px;">Kode Barang</th>
                                <th>Uraian & Supplier</th>
                                <th style="width:60px;">Sat</th>
                                <th style="width:85px; text-align:right;">Saldo Sisa</th>
                                <th style="width:95px; text-align:right;">Qty Pakai</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyIncomingPicker"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer" style="display:flex; justify-content:space-between; align-items:center;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalIncomingPicker')">Batal</button>
                <button type="button" class="btn btn-primary" id="btnInsertIncoming" onclick="insertSelectedIncoming()" style="font-weight:700; background:#0891b2; border-color:#0e7490;">
                    ➕ Masukkan ke Dokumen
                </button>
            </div>
        </div>
    </div>

    <!-- GET MANIFEST BC 1.1 MODAL (PowerBuilder ue_bc11 & w_bc_ceisa_getbc11) -->
    <div class="modal-overlay" id="modalUeBc11">
        <div class="modal" style="max-width: 950px; width: 95vw; max-height: 90vh; display: flex; flex-direction: column;">
            <div class="modal-header" style="background: linear-gradient(135deg, rgba(37, 99, 235, 0.1), transparent);">
                <div>
                    <h3 style="color: #2563eb; display: flex; align-items: center; gap: 8px; margin: 0;">
                        <span>🔍 Get Manifest BC 1.1 (ue_bc11)</span>
                    </h3>
                    <div style="font-size: 11px; color: var(--text-muted); margin-top: 3px;">
                        Mengambil data pos/subpos manifes kedatangan sarana pengangkut (BC 1.1) dari API Gateway Bea Cukai.
                    </div>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeModal('modalUeBc11')">✕</button>
            </div>

            <div class="modal-body" style="display: flex; flex-direction: column; gap: 12px; overflow-y: auto; flex: 1; padding: 16px;">
                <!-- SEARCH / INQUIRY BAR -->
                <div style="display: flex; gap: 10px; align-items: flex-end; background: rgba(0,0,0,0.02); padding: 12px; border-radius: 6px; border: 1px solid var(--border); flex-wrap: wrap;">
                    <div style="flex: 2; min-width: 200px;">
                        <label style="font-size: 11px; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 4px;">HOST AWB / HOST BL (740/705):</label>
                        <input type="text" id="ueBc11HostBl" class="form-control" style="font-weight: 700; text-transform: uppercase;" placeholder="Nomor Host BL...">
                    </div>
                    <div style="width: 140px;">
                        <label style="font-size: 11px; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 4px;">TGL HOST BL:</label>
                        <input type="date" id="ueBc11Tgl" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div style="width: 120px;">
                        <label style="font-size: 11px; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 4px;">KANTOR BONGKAR:</label>
                        <input type="text" id="ueBc11Kantor" class="form-control" placeholder="040300" maxlength="6">
                    </div>
                    <div style="display: flex; gap: 6px;">
                        <button type="button" class="btn btn-primary" id="btnUeBc11Fetch" onclick="fetchUeBc11Data()">
                            <i class="fa-solid fa-magnifying-glass"></i> Cari BC 1.1
                        </button>
                        <button type="button" class="btn btn-info" id="btnUeBc11Tps" onclick="fetchUeBc11Tps()">
                            <i class="fa-solid fa-warehouse"></i> Get TPS
                        </button>
                    </div>
                </div>

                <!-- DUA PANEL: KIRI DATA BC 1.1, KANAN DAFTAR TPS -->
                <div style="display: grid; grid-template-columns: 1fr 340px; gap: 14px; min-height: 320px;">
                    <!-- LEFT: BC 1.1 PREVIEW -->
                    <div style="border: 1px solid var(--border); border-radius: 6px; padding: 12px; display: flex; flex-direction: column;">
                        <div style="font-size: 12px; font-weight: 700; color: var(--primary); margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
                            <span>Hasil Pencarian Manifest BC 1.1</span>
                            <span id="ueBc11StatusBadge" style="font-size: 10px; padding: 2px 6px; border-radius: 4px; background: rgba(0,0,0,0.05); color: var(--text-muted);">Menunggu input</span>
                        </div>
                        <div id="ueBc11PreviewContainer" style="flex: 1; overflow-y: auto;">
                            <table class="datagrid" style="width: 100%; font-size: 11px;">
                                <tbody>
                                    <tr><td style="width: 160px; font-weight: 600;">Nomor BC 1.1</td><td id="prevUeNomorDaftar" style="font-weight: 700; color: #2563eb;">-</td></tr>
                                    <tr><td style="font-weight: 600;">Tanggal BC 1.1</td><td id="prevUeTanggalDaftar">-</td></tr>
                                    <tr><td style="font-weight: 600;">Nomor Pos / Sub Pos</td><td><strong id="prevUePos">-</strong> / <span id="prevUeSubPos">-</span></td></tr>
                                    <tr><td style="font-weight: 600;">Kantor Bongkar</td><td id="prevUeKantor">-</td></tr>
                                    <tr><td style="font-weight: 600;">Mother Vessel (Pengangkut)</td><td id="prevUeVessel" style="font-weight: 600;">-</td></tr>
                                    <tr><td style="font-weight: 600;">Voyage / Tanggal Tiba</td><td><span id="prevUeVoyage">-</span> / <span id="prevUeTglTiba">-</span></td></tr>
                                    <tr><td style="font-weight: 600;">Pelabuhan Bongkar / Muat</td><td><span id="prevUePelBongkar">-</span> / <span id="prevUePelMuat">-</span></td></tr>
                                    <tr><td style="font-weight: 600;">Pelabuhan Transit / Akhir</td><td><span id="prevUePelTransit">-</span> / <span id="prevUePelAkhir">-</span></td></tr>
                                    <tr><td style="font-weight: 600;">Master BL (741)</td><td><span id="prevUeNoBl" style="font-weight: 600;">-</span> (<span id="prevUeTglBl">-</span>)</td></tr>
                                    <tr><td style="font-weight: 600;">Berat Bruto</td><td id="prevUeBruto">-</td></tr>
                                    <tr><td style="font-weight: 600;">Kemasan</td><td><span id="prevUeJmlKemas">-</span> <span id="prevUeKdKemas">-</span></td></tr>
                                    <tr><td style="font-weight: 600;">Pengirim</td><td id="prevUePengirim">-</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- RIGHT: TPS LIST & SELECTION -->
                    <div style="border: 1px solid var(--border); border-radius: 6px; padding: 12px; display: flex; flex-direction: column;">
                        <div style="font-size: 12px; font-weight: 700; margin-bottom: 6px; display: flex; justify-content: space-between; align-items: center;">
                            <span>Pilih Gudang TPS</span>
                            <span id="ueSelectedTpsBadge" style="background: #fef08a; color: #854d0e; font-weight: 800; padding: 2px 8px; border-radius: 4px; font-size: 11px;">-</span>
                        </div>
                        <input type="text" id="ueSearchTps" class="form-control" placeholder="Cari TPS..." onkeyup="filterUeTpsList()" style="margin-bottom: 8px; font-size: 11px;">
                        <div style="flex: 1; max-height: 250px; overflow-y: auto; border: 1px solid var(--border); border-radius: 4px;">
                            <table class="datagrid" style="width: 100%; font-size: 11px;">
                                <thead>
                                    <tr>
                                        <th style="width: 65px;">Kode</th>
                                        <th>Nama TPS</th>
                                    </tr>
                                </thead>
                                <tbody id="tbodyUeTps">
                                    <tr><td colspan="2" style="text-align: center; color: var(--text-muted); padding: 14px;">Belum memuat TPS</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalUeBc11')">Batal</button>
                <button type="button" class="btn btn-primary" id="btnTransferUeBc11" onclick="applyUeBc11ToDocument()" style="font-size: 13px; font-weight: 700; padding: 6px 20px; background: #059669; border-color: #047857;" disabled>
                    ⚡ Transfer ke Dokumen &gt;
                </button>
            </div>
        </div>
    </div>

    <!-- CONTEXT MENU (KLIK KANAN) UNIFIED (EXCEL & ROW ACTIONS) -->
    <div id="editorContextMenu" class="context-menu">
        <div id="ctxHeaderTitle" class="context-menu-header">
            <span id="ctxTitleText">AKSI TABEL</span>
            <span style="font-size:8.5px; opacity:0.6; font-weight:500;">EXCEL INTEROP</span>
        </div>

        <!-- EXCEL TOOLS -->
        <div class="context-menu-item" onclick="execExcelContextMenu('copy_all')">
            <span class="menu-icon">📑</span>
            <span>Copy Semua Baris ke Excel</span>
        </div>
        <div class="context-menu-item" id="excelMenuCopySelected" onclick="execExcelContextMenu('copy_selected')">
            <span class="menu-icon">📋</span>
            <span>Copy Baris Ini ke Excel</span>
        </div>
        <div class="context-menu-item" onclick="execExcelContextMenu('paste')">
            <span class="menu-icon">📥</span>
            <span>Paste dari Excel...</span>
        </div>
        <div class="context-menu-item" onclick="execExcelContextMenu('copy_template')">
            <span class="menu-icon">📊</span>
            <span>Copy Header Template Kolom</span>
        </div>

        <!-- KHUSUS TABEL BARANG -->
        <div id="ctxBarangOptions" style="display:none;">
            <div class="context-menu-divider"></div>
            <div class="context-menu-item" onclick="triggerContextAction('add_material')">
                <span class="menu-icon">📦</span>
                <span>Tambah dari Master Barang</span>
            </div>
            <div class="context-menu-item" onclick="triggerContextAction('fifo_trace')">
                <span class="menu-icon">⚡</span>
                <span>Mulai Trace FIFO Dokumen Asal</span>
            </div>
            <div class="context-menu-item" onclick="triggerContextAction('toggle_trace')">
                <span class="menu-icon">🔍</span>
                <span>Show / Hide Trace Panel</span>
            </div>
            <div class="context-menu-divider"></div>
            <div class="context-menu-item" onclick="triggerContextAction('fix_hs')">
                <span class="menu-icon">🔍</span>
                <span>FIX HS CODE</span>
            </div>
            <div class="context-menu-item" onclick="triggerContextAction('fix_netto')">
                <span class="menu-icon">⚖️</span>
                <span>FIX NETTO</span>
            </div>
        </div>

        <!-- KHUSUS TABEL BAHAN BAKU -->
        <div id="ctxBahanBakuOptions" style="display:none;">
            <div class="context-menu-divider"></div>
            <div class="context-menu-item" onclick="triggerContextAction('add_incoming')">
                <span class="menu-icon">📥</span>
                <span>Tambah dari Dokumen Masuk (Eks BC)</span>
            </div>
            <div class="context-menu-item" onclick="triggerContextAction('generate_bbt')">
                <span class="menu-icon">⚡</span>
                <span>Generate Bahan Baku Tarif</span>
            </div>
            <div class="context-menu-item" onclick="triggerContextAction('seq_bb')">
                <span class="menu-icon">🔢</span>
                <span>Urutkan Seri Bahan Baku</span>
            </div>
        </div>

        <!-- HAPUS BARIS -->
        <div id="ctxRowDeleteOption" style="display:none;">
            <div class="context-menu-divider"></div>
            <div class="context-menu-item" onclick="triggerContextAction('delete_row')" style="color:#ef4444;">
                <span class="menu-icon">❌</span>
                <span>Hapus Baris Ini</span>
            </div>
        </div>
    </div>

    <!-- FLOATING REKAP TARIF WINDOW -->
    <div id="floatingSummaryTarif" class="floating-summary-window" style="display:none;">
        <div class="floating-summary-header" id="floatingSummaryHeader">
            <div class="floating-summary-title">
                <span class="drag-handle" title="Klik dan geser untuk memindahkan posisi window">⠿</span>
                <span>📊 Rekap Tarif & Pungutan</span>
            </div>
            <div class="floating-summary-actions">
                <button type="button" class="btn-float-action" onclick="loadRecapTarif()" title="Segarkan Data Rekap">🔄</button>
                <button type="button" class="btn-float-action" onclick="closeFloatingSummaryTarif()" title="Tutup / Sembunyikan">✕</button>
            </div>
        </div>
        <div class="floating-summary-body" id="summaryTarifBox">
            <!-- Isi rekap di-render secara dinamis oleh loadRecapTarif() -->
        </div>
    </div>

    <!-- FLOATING LAUNCHER PILL (Saat Window Ditutup) -->
    <button type="button" id="btnFloatingSummaryLauncher" class="floating-summary-pill" onclick="openFloatingSummaryTarif()" title="Buka Rekapitulasi Tarif & Pungutan" style="display:none;">
        <span class="pill-icon">📊</span>
        <span class="pill-label">Rekap Tarif</span>
        <span class="pill-badge" id="pillTotalBayar">Rp 0</span>
    </button>

    <script>
        // Jika dibuka langsung di browser luar iframe portal, alihkan ke index.php agar sidebar menu tetap tampil
        if (window.self === window.top) {
            const p = new URLSearchParams(window.location.search);
            const aju = p.get('nomor_aju') || '';
            const cmp = p.get('cmp_id') || '';
            let target = '/index.php?tab=editor';
            if (aju) target += '&nomor_aju=' + encodeURIComponent(aju);
            if (cmp) target += '&cmp_id=' + encodeURIComponent(cmp);
            window.location.replace(target);
        }

        let currentAju = '';
        let activeTab = 'header';
        let currentData = {};
        let currentVarRules = { header: {}, barang: {} };
        let currentReffOptions = {};
        let activeLookupTarget = null;
        let activeLookupReff = null;
        let isCurrentDocLocked = false;
        let activeErpDriver = 'FRAMAS';
        let selectedErpDocId = null;
        let erpSearchResults = [];
        let currentTabRules = null;
        let currentTabSource = 'default';
        let currentColSource = 'varheader';
        let currentActiveCmpId = '';
        let filterModalActiveSubTab = 'tab';
        let filterModalActiveColTable = 'header';
        let filterModalCacheData = null;

        // -------------------------------------------------------------
        // SETUP AJU BARU & INTEGRASI ERP LOGIC (Persis PowerBuilder)
        // -------------------------------------------------------------
        function toggleEntryPanel(forceOpen) {
            const sidebar = document.getElementById('sidebarEntry');
            const btn = document.getElementById('btnToggleEntry');
            if (!sidebar) return;
            if (forceOpen === true) {
                sidebar.classList.remove('collapsed');
                sidebar.style.display = 'flex';
            } else if (forceOpen === false) {
                sidebar.classList.add('collapsed');
                sidebar.style.display = 'none';
            } else {
                sidebar.classList.toggle('collapsed');
                sidebar.style.display = sidebar.classList.contains('collapsed') ? 'none' : 'flex';
            }
            const isOpen = !sidebar.classList.contains('collapsed');
            if (btn) {
                btn.classList.toggle('active', isOpen);
                btn.innerText = isOpen ? '✕ Tutup Setup' : '✨ Setup AJU Baru';
            }
        }

        function onEntryBcChange(bc) {
            const valuta = document.getElementById('entryValuta')?.value || 'USD';
            fetchLiveNdpbm(valuta);
        }

        function resetEntryDraft() {
            const docBcEl = document.getElementById('entryDocBc');
            if (docBcEl && docBcEl.options.length > 0) {
                docBcEl.selectedIndex = 0;
            }
            document.getElementById('entryValuta').value = 'USD';
            document.getElementById('entryNdpbmDate').value = new Date().toISOString().slice(0, 10);
            fetchLiveNdpbm('USD');
            document.getElementById('entryAjuDisplay').innerText = '- Silakan Buat Dokumen -';
            showToast('Draft entri direset');
        }

        function copyAjuText() {
            const txt = document.getElementById('entryAjuDisplay')?.innerText || '';
            if (txt && !txt.includes('Silakan')) {
                navigator.clipboard.writeText(txt).then(() => showToast('Nomor AJU disalin!'));
            }
        }

        async function fetchLiveNdpbm(valuta) {
            valuta = (valuta || document.getElementById('entryValuta')?.value || 'USD').toUpperCase();
            const cmpId = new URLSearchParams(window.location.search).get('cmp_id') || '';
            const badge = document.getElementById('ndpbmSourceBadge');
            const btn = document.getElementById('btnFetchNdpbm');
            if (btn) btn.innerHTML = '<span class="spinner"></span> Mengambil Kurs...';
            try {
                const res = await fetch(`editor.php?action=get_ndpbm&valuta=${encodeURIComponent(valuta)}&cmp_id=${encodeURIComponent(cmpId)}`);
                const json = await res.json();
                if (json.status === 'OK') {
                    const input = document.getElementById('entryNdpbmVal');
                    if (input) input.value = formatCeisaNumber(json.ndpbm, 2);
                    if (badge) badge.innerText = json.source || 'LIVE';
                    showToast(`Kurs ${valuta}: Rp ${parseFloat(json.ndpbm).toLocaleString('id-ID')}`);
                }
            } catch(e) {
                console.warn('Gagal ambil kurs live:', e);
            } finally {
                if (btn) btn.innerHTML = '⚡ Ambil Kurs dari Bea Cukai';
            }
        }

        async function executeCreateNewAju() {
            const kodeBc = document.getElementById('entryDocBc')?.value || '23';
            const valuta = document.getElementById('entryValuta')?.value || 'USD';
            const ndpbm = parseInputNumber(document.getElementById('entryNdpbmVal')?.value || 0);
            const tanggal = document.getElementById('entryNdpbmDate')?.value || new Date().toISOString().slice(0, 10);
            const cmpId = new URLSearchParams(window.location.search).get('cmp_id') || '';

            if (ndpbm <= 0) {
                alert('Silakan isi atau tarik nilai NDPBM terlebih dahulu!');
                fetchLiveNdpbm(valuta);
                return;
            }

            const btn = document.getElementById('btnExecNewAju');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner"></span> Membuat Dokumen...';
            }

            try {
                const fd = new FormData();
                fd.append('kode_dokumen', kodeBc);
                fd.append('valuta', valuta);
                fd.append('ndpbm', ndpbm);
                fd.append('tanggal_pernyataan', tanggal);
                fd.append('cmp_id', cmpId);

                const res = await fetch('editor.php?action=create_new_aju', {
                    method: 'POST',
                    body: fd
                });
                const json = await res.json();
                if (json.status === 'OK') {
                    const newAju = json.nomor_aju;
                    document.getElementById('entryAjuDisplay').innerText = newAju;

                    // Tambahkan ke dropdown aju
                    const sel = document.getElementById('selectAju');
                    if (sel) {
                        const opt = document.createElement('option');
                        opt.value = newAju;
                        opt.textContent = `[BC ${json.kode_dokumen}] ${newAju} - BARU`;
                        sel.prepend(opt);
                        sel.value = newAju;
                    }

                    // Perbarui URL tanpa reload penuh
                    const url = new URL(window.location);
                    url.searchParams.set('nomor_aju', newAju);
                    window.history.replaceState({}, '', url);

                    // Load dokumen baru: otomatis menyusun TAB dan KOLOM sesuai BC!
                    await loadAjuData(newAju);

                    // Aktifkan badge driver ERP
                    loadErpProfile();

                    showToast(`Dokumen AJU Baru [BC ${json.kode_dokumen}] berhasil dibuat!`);
                } else {
                    alert('Gagal membuat dokumen baru: ' + (json.message || 'Terjadi kesalahan'));
                }
            } catch(e) {
                alert('Kesalahan jaringan: ' + e.message);
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '📄 Buat Dokumen Baru';
                }
            }
        }

        // -------------------------------------------------------------
        // KONFIG AJU MODAL LOGIC (bc_ceisa_setaju)
        // -------------------------------------------------------------
        async function openConfigAjuModal() {
            const currentBc = document.getElementById('entryDocBc')?.value || (currentData.header?.kode_dokumen || '23');
            const selBc = document.getElementById('cfgBc');
            if (selBc) selBc.value = currentBc.replace(/[^0-9]/g, '');
            await loadConfigAjuForBc(selBc ? selBc.value : '23');
            document.getElementById('modalConfigAju').style.display = 'flex';
        }

        async function loadConfigAjuForBc(bc) {
            const cmpId = new URLSearchParams(window.location.search).get('cmp_id') || '';
            try {
                const res = await fetch(`editor.php?action=get_config_aju&bc=${encodeURIComponent(bc)}&cmp_id=${encodeURIComponent(cmpId)}`);
                const json = await res.json();
                if (json.status === 'OK' && json.data) {
                    const d = json.data;
                    document.getElementById('cfgA').value = d.a || '0000';
                    document.getElementById('cfgB').value = d.b || '010816';
                    document.getElementById('cfgAju').value = d.aju || 0;
                    document.getElementById('cfgNamaTtd').value = d.nama_ttd || '';
                    document.getElementById('cfgJabatanTtd').value = d.jabatan_ttd || '';
                    document.getElementById('cfgKotaTtd').value = d.kota_ttd || '';
                    updateConfigAjuPreview();
                }
            } catch(e) {
                console.warn('Gagal memuat konfigurasi AJU:', e);
            }
        }

        function updateConfigAjuPreview() {
            const bc = (document.getElementById('cfgBc')?.value || '23').replace(/[^0-9]/g, '').padStart(2, '0').slice(0, 2);
            const a = (document.getElementById('cfgA')?.value || '0000').replace(/[^0-9]/g, '').padStart(4, '0').slice(0, 4);
            const b = (document.getElementById('cfgB')?.value || '000000').padStart(6, '0').slice(0, 6);
            const seq = parseInt(document.getElementById('cfgAju')?.value || 0, 10) + 1;
            const seqStr = String(seq).padStart(6, '0').slice(-6);
            const ymd = new Date().toISOString().slice(0, 10).replace(/-/g, '');

            const full26 = `${a}${bc}${b}${ymd}${seqStr}`;
            const prevEl = document.getElementById('cfgPreviewAju');
            if (prevEl) {
                prevEl.innerText = full26;
            }
        }

        async function saveConfigAju() {
            const bc = document.getElementById('cfgBc')?.value || '23';
            const a = document.getElementById('cfgA')?.value || '0000';
            const b = document.getElementById('cfgB')?.value || '000000';
            const aju = document.getElementById('cfgAju')?.value || '0';
            const nama_ttd = document.getElementById('cfgNamaTtd')?.value || '';
            const jabatan_ttd = document.getElementById('cfgJabatanTtd')?.value || '';
            const kota_ttd = document.getElementById('cfgKotaTtd')?.value || '';
            const cmpId = new URLSearchParams(window.location.search).get('cmp_id') || '';

            const fd = new FormData();
            fd.append('bc', bc);
            fd.append('a', a);
            fd.append('b', b);
            fd.append('aju', aju);
            fd.append('nama_ttd', nama_ttd);
            fd.append('jabatan_ttd', jabatan_ttd);
            fd.append('kota_ttd', kota_ttd);
            fd.append('cmp_id', cmpId);

            try {
                const res = await fetch('editor.php?action=save_config_aju', {
                    method: 'POST',
                    body: fd
                });
                const json = await res.json();
                if (json.status === 'OK') {
                    showToast('Konfigurasi Penomoran AJU disimpan!');
                    closeModal('modalConfigAju');
                } else {
                    alert('Gagal simpan konfigurasi: ' + (json.message || ''));
                }
            } catch(e) {
                alert('Kesalahan jaringan: ' + e.message);
            }
        }

        // -------------------------------------------------------------
        // INTEGRASI DATABASE ERP MODAL & LOGIC
        // -------------------------------------------------------------
        async function loadErpProfile() {
            let cmpId = new URLSearchParams(window.location.search).get('cmp_id') || '';
            if (!cmpId && typeof currentData !== 'undefined' && currentData.header && currentData.header.cmp_id) {
                cmpId = currentData.header.cmp_id;
            }
            try {
                const res = await fetch(`editor.php?action=erp_info&cmp_id=${encodeURIComponent(cmpId)}`);
                const json = await res.json();
                const topBtn = document.getElementById('btnTopErp');
                const cardSidebarErp = document.getElementById('cardSidebarErp');

                if (json.status === 'OK' && json.has_erp && json.active_driver) {
                    const drv = json.active_driver;
                    activeErpDriver = drv.name;
                    if (topBtn) {
                        topBtn.style.display = 'inline-flex';
                        topBtn.innerText = `🔌 Integrasi ERP (${drv.name})`;
                    }
                    if (cardSidebarErp) {
                        cardSidebarErp.style.display = '';
                    }
                    const badge = document.getElementById('sidebarDriverBadge');
                    if (badge) badge.innerText = drv.name;
                    const modalBadge = document.getElementById('erpModalDriverBadge');
                    if (modalBadge) modalBadge.innerText = drv.name;
                    const modalDesc = document.getElementById('erpModalDriverDesc');
                    if (modalDesc) modalDesc.innerText = drv.description;
                    const drvDisp = document.getElementById('erpDriverDisplay');
                    if (drvDisp) drvDisp.innerText = drv.title || drv.name;
                    const cmpLbl = document.getElementById('erpCompanyLabel');
                    if (cmpLbl) cmpLbl.innerText = `${json.company?.cmp_name || cmpId}`;
                } else {
                    activeErpDriver = null;
                    if (topBtn) topBtn.style.display = 'none';
                    if (cardSidebarErp) cardSidebarErp.style.display = 'none';
                }
            } catch(e) {
                console.warn('Gagal memuat profil ERP:', e);
            }
        }

        async function openErpModal() {
            if (!currentAju) {
                alert('Silakan pilih atau buat dokumen AJU terlebih dahulu sebelum integrasi ERP!');
                toggleEntryPanel(true);
                return;
            }
            await loadErpProfile();
            if (!activeErpDriver) {
                alert('Sistem ERP belum dikonfigurasi untuk perusahaan ini.');
                return;
            }
            document.getElementById('erpAjuLabel').innerText = currentAju;

            const kodeBc = (currentData.header?.kode_dokumen || document.getElementById('entryDocBc')?.value || '23').replace(/[^0-9]/g, '');
            const typeSel = document.getElementById('erpFilterType');
            if (typeSel) {
                if (['30', '25', '27', '41', '261', '33'].includes(kodeBc)) {
                    typeSel.value = 'outbound';
                } else {
                    typeSel.value = 'inbound';
                }
            }

            document.getElementById('modalErpIntegration').style.display = 'flex';
            searchErpDocuments();
        }

        async function searchErpDocuments() {
            const cmpId = new URLSearchParams(window.location.search).get('cmp_id') || '';
            const docType = document.getElementById('erpFilterType')?.value || 'outbound';
            const q = document.getElementById('erpFilterQuery')?.value || '';
            const dt1 = document.getElementById('erpFilterDateFrom')?.value || '';
            const dt2 = document.getElementById('erpFilterDateTo')?.value || '';
            const kodeBc = (currentData.header?.kode_dokumen || '23').replace(/[^0-9]/g, '');

            const listEl = document.getElementById('erpDocList');
            if (listEl) {
                listEl.innerHTML = `
                    <div class="app-loader-container" style="padding:30px 15px;">
                        <div class="app-loader-icon-wrap" style="width:44px;height:44px;">
                            <div class="app-loader-spinner spinner-primary" style="width:44px;height:44px;"></div>
                            <div class="app-loader-center-icon" style="font-size:18px;">🔌</div>
                        </div>
                        <div class="app-loader-title" style="font-size:13px; color:#1890ff;">Mencari Dokumen di Database ERP...</div>
                        <div class="app-loader-progress-track" style="max-width:200px; margin-top:8px;">
                            <div class="app-loader-progress-bar"></div>
                        </div>
                    </div>
                `;
            }

            try {
                const url = `editor.php?action=erp_search&cmp_id=${encodeURIComponent(cmpId)}&doc_type=${encodeURIComponent(docType)}&search_q=${encodeURIComponent(q)}&date_from=${encodeURIComponent(dt1)}&date_to=${encodeURIComponent(dt2)}&kode_dokumen=${encodeURIComponent(kodeBc)}`;
                const res = await fetch(url);
                const json = await res.json();
                if (json.status === 'OK') {
                    erpSearchResults = json.results || [];
                    renderErpDocumentList(erpSearchResults);
                } else {
                    listEl.innerHTML = `<div style="padding:15px; color:var(--danger);">${json.message || 'Gagal mencari data'}</div>`;
                }
            } catch(e) {
                listEl.innerHTML = `<div style="padding:15px; color:var(--danger);">${e.message}</div>`;
            }
        }

        function renderErpDocumentList(docs) {
            const listEl = document.getElementById('erpDocList');
            const countEl = document.getElementById('erpDocCount');
            if (countEl) countEl.innerText = `${docs.length} dokumen`;

            if (!docs || docs.length === 0) {
                listEl.innerHTML = '<div style="padding:20px; text-align:center; color:var(--text-muted);">Tidak ada transaksi yang cocok.</div>';
                return;
            }

            let html = '<div style="display:flex; flex-direction:column; gap:4px;">';
            docs.forEach((d, idx) => {
                const simBadge = d.is_simulated ? '<span style="font-size:8px; background:rgba(234,179,8,0.2); color:#eab308; padding:1px 4px; border-radius:3px; margin-left:4px;">Simulasi ERP</span>' : '';
                html += `
                    <div class="erp-item-card" id="erp-card-${idx}" onclick="selectErpDoc('${escapeHtml(d.doc_id)}', ${idx})" style="background:var(--bg-card); border:1px solid var(--border); border-radius:4px; padding:6px 8px; cursor:pointer; transition:all 0.15s ease;">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <strong style="color:var(--primary); font-size:11px;">${escapeHtml(d.doc_id)}</strong>
                            <span style="font-size:9px; color:var(--text-muted);">${escapeHtml(d.doc_date)}</span>
                        </div>
                        <div style="font-size:9.5px; margin:2px 0; color:var(--text-main); font-weight:600;">
                            ${escapeHtml(d.partner_name || '-')} ${simBadge}
                        </div>
                        <div style="display:flex; justify-content:space-between; font-size:9px; color:var(--text-muted);">
                            <span>${d.total_items} items | Net: ${parseFloat(d.total_netto).toFixed(1)} kg</span>
                            <strong style="color:var(--success);">${escapeHtml(d.valuta)} ${parseFloat(d.total_fob).toLocaleString('id-ID')}</strong>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            listEl.innerHTML = html;

            if (docs.length > 0) {
                selectErpDoc(docs[0].doc_id, 0);
            }
        }

        async function selectErpDoc(docId, idx) {
            selectedErpDocId = docId;
            document.querySelectorAll('.erp-item-card').forEach(c => {
                c.style.borderColor = 'var(--border)';
                c.style.background = 'var(--bg-card)';
            });
            const selCard = document.getElementById(`erp-card-${idx}`);
            if (selCard) {
                selCard.style.borderColor = 'var(--primary)';
                selCard.style.background = 'rgba(56, 189, 248, 0.08)';
            }
            document.getElementById('erpSelectedDocId').innerText = docId;

            const previewArea = document.getElementById('erpDocPreviewArea');
            if (previewArea) previewArea.innerHTML = '<div style="padding:30px; text-align:center;"><span class="spinner"></span> Mengambil rincian barang...</div>';

            const cmpId = new URLSearchParams(window.location.search).get('cmp_id') || '';
            const kodeBc = (currentData.header?.kode_dokumen || '23').replace(/[^0-9]/g, '');

            try {
                const res = await fetch(`editor.php?action=erp_preview&cmp_id=${encodeURIComponent(cmpId)}&driver_name=${encodeURIComponent(activeErpDriver)}&doc_id=${encodeURIComponent(docId)}&kode_dokumen=${encodeURIComponent(kodeBc)}`);
                const json = await res.json();
                if (json.status === 'OK') {
                    renderErpPreview(json.preview);
                    document.getElementById('btnExecuteIntegration').disabled = false;
                }
            } catch(e) {
                previewArea.innerHTML = `<div style="padding:15px; color:var(--danger);">${e.message}</div>`;
            }
        }

        function renderErpPreview(preview) {
            const previewArea = document.getElementById('erpDocPreviewArea');
            if (!preview) return;

            const hdr = preview.header || {};
            const items = preview.items || [];
            const docs = preview.documents || [];
            const pkgs = preview.packages || [];

            let html = `
                <div style="background:var(--bg-base); border:1px solid var(--border); border-radius:4px; padding:6px 8px; margin-bottom:8px;">
                    <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                        <span>Dokumen Invoice: <strong>${escapeHtml(hdr.invoice_no || '-')}</strong></span>
                        <span>Valuta: <strong>${escapeHtml(hdr.valuta || 'USD')}</strong></span>
                    </div>
                    <div style="display:flex; justify-content:space-between; font-weight:700;">
                        <span>Total Nilai: <span style="color:var(--success);">${escapeHtml(hdr.valuta)} ${parseFloat(hdr.total_fob).toLocaleString('id-ID')}</span></span>
                        <span>Netto: ${parseFloat(hdr.total_netto).toFixed(2)} kg | Bruto: ${parseFloat(hdr.total_bruto).toFixed(2)} kg</span>
                    </div>
                </div>

                <div style="font-weight:700; margin-bottom:4px; font-size:10px;">📦 Rincian Item Barang (${items.length} seri):</div>
                <div style="border:1px solid var(--border); border-radius:4px; overflow-x:auto; margin-bottom:8px;">
                    <table class="datagrid" style="width:100%; font-size:9.5px;">
                        <thead>
                            <tr>
                                <th style="width:30px; text-align:center;">#</th>
                                <th>Kode Barang</th>
                                <th>Uraian Barang</th>
                                <th style="text-align:right;">Qty</th>
                                <th>Satuan</th>
                                <th style="text-align:right;">Harga Satuan</th>
                                <th style="text-align:right;">FOB / Nilai</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            items.forEach(it => {
                html += `
                    <tr>
                        <td style="text-align:center;">${it.seri_barang}</td>
                        <td style="font-family:monospace; font-weight:600;">${escapeHtml(it.kode_barang)}</td>
                        <td>${escapeHtml(it.uraian)}</td>
                        <td style="text-align:right; font-weight:600;">${parseFloat(it.jumlah_satuan).toLocaleString('id-ID')}</td>
                        <td>${escapeHtml(it.kode_satuan)}</td>
                        <td style="text-align:right;">${parseFloat(it.harga_satuan).toLocaleString('id-ID')}</td>
                        <td style="text-align:right; font-weight:700; color:var(--success);">${parseFloat(it.fob).toLocaleString('id-ID')}</td>
                    </tr>
                `;
            });

            html += `
                        </tbody>
                    </table>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                    <div style="background:var(--bg-base); border:1px solid var(--border); border-radius:4px; padding:6px 8px;">
                        <div style="font-weight:700; margin-bottom:3px;">📄 Dokumen Pelengkap (${docs.length}):</div>
                        ${docs.map(d => `<div>• Kode ${d.kode_dokumen}: <strong>${escapeHtml(d.nomor_dokumen)}</strong> (${d.tanggal_dokumen})</div>`).join('')}
                    </div>
                    <div style="background:var(--bg-base); border:1px solid var(--border); border-radius:4px; padding:6px 8px;">
                        <div style="font-weight:700; margin-bottom:3px;">📦 Kemasan (${pkgs.length}):</div>
                        ${pkgs.map(p => `<div>• ${p.jumlah_kemasan} ${p.kode_kemasan} (Merek: ${escapeHtml(p.merek || '-')})</div>`).join('')}
                    </div>
                </div>
            `;

            previewArea.innerHTML = html;
        }

        async function executeErpIntegration() {
            if (!currentAju || !selectedErpDocId) return;

            const btn = document.getElementById('btnExecuteIntegration');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner"></span> Mengintegrasikan ke Staging...';
            }

            const cmpId = new URLSearchParams(window.location.search).get('cmp_id') || '';
            const kodeBc = (currentData.header?.kode_dokumen || '23').replace(/[^0-9]/g, '');

            try {
                const fd = new FormData();
                fd.append('nomor_aju', currentAju);
                fd.append('doc_id', selectedErpDocId);
                fd.append('driver_name', activeErpDriver);
                fd.append('kode_dokumen', kodeBc);
                fd.append('cmp_id', cmpId);

                const res = await fetch('editor.php?action=erp_integrate', {
                    method: 'POST',
                    body: fd
                });
                const json = await res.json();
                if (json.status === 'OK') {
                    closeModal('modalErpIntegration');
                    await loadAjuData(currentAju);
                    showToast(json.message || 'Integrasi ERP berhasil! Seluruh data staging terisi.');
                } else {
                    alert('Gagal integrasi ERP: ' + (json.message || ''));
                }
            } catch(e) {
                alert('Kesalahan jaringan: ' + e.message);
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '⚡ Masukkan ke Dokumen AJU (Staging)';
                }
            }
        }

        function openExcelImport() {
            if (window.parent && typeof window.parent.switchMainTab === 'function') {
                window.parent.switchMainTab('import');
            } else {
                window.location.href = '/import_excel.php';
            }
        }

        // =============================================================
        // AI DOCUMENT READER & MULTI-PROVIDER AI LOGIC
        // =============================================================
        let aiSelectedFiles = [];
        let aiExtractedData = null;
        let aiActiveConfigTab = 'visual';

        const AI_DEFAULT_CONFIG = {
            provider: 'gemini',
            model: 'gemini-2.0-flash',
            api_key: '',
            endpoint: '',
            temperature: 0.1,
            customHeaders: {}
        };

        const AI_PROVIDER_PRESETS = {
            gemini: {
                provider: 'gemini',
                model: 'gemini-2.0-flash',
                endpoint: '',
                help: 'Model default: gemini-2.0-flash (Sangat cepat & akurat untuk ekstraksi dokumen teks & gambar).',
                keyUrl: 'https://aistudio.google.com/app/apikey'
            },
            openai: {
                provider: 'openai',
                model: 'gpt-4o-mini',
                endpoint: 'https://api.openai.com/v1/chat/completions',
                help: 'Model rekomendasi: gpt-4o-mini atau gpt-4o untuk pengenalan dokumen kompleks.',
                keyUrl: 'https://platform.openai.com/api-keys'
            },
            claude: {
                provider: 'claude',
                model: 'claude-3-5-sonnet-20241022',
                endpoint: 'https://api.anthropic.com/v1/messages',
                help: 'Model Anthropic Claude 3.5 Sonnet dengan pemahaman dokumen dan tabel yang luar biasa.',
                keyUrl: 'https://console.anthropic.com/settings/keys'
            },
            deepseek: {
                provider: 'deepseek',
                model: 'deepseek-chat',
                endpoint: 'https://api.deepseek.com/v1/chat/completions',
                help: 'DeepSeek-V3 / DeepSeek-R1 via endpoint resmi DeepSeek API.',
                keyUrl: 'https://platform.deepseek.com/api_keys'
            },
            groq: {
                provider: 'groq',
                model: 'llama-3.3-70b-versatile',
                endpoint: 'https://api.groq.com/openai/v1/chat/completions',
                help: 'Groq LPU ultra-fast inference untuk model open-source.',
                keyUrl: 'https://console.groq.com/keys'
            },
            ollama: {
                provider: 'ollama',
                model: 'llama3.2',
                endpoint: 'http://localhost:11434/v1/chat/completions',
                help: 'Server Ollama lokal (pastikan Ollama aktif di komputer atau server lokal).',
                keyUrl: ''
            },
            openai_compatible: {
                provider: 'openai_compatible',
                model: 'custom-model',
                endpoint: 'https://api.openai.com/v1/chat/completions',
                help: 'Gunakan endpoint OpenAI-compatible apa pun (OpenRouter, Mistral, Together, vLLM, LocalAI).',
                keyUrl: ''
            }
        };

        function getAiConfig() {
            try {
                const raw = localStorage.getItem('ceisa_ai_config');
                if (raw) {
                    const parsed = JSON.parse(raw);
                    return { ...AI_DEFAULT_CONFIG, ...parsed };
                }
            } catch(e) {
                console.warn('Gagal membaca ceisa_ai_config:', e);
            }
            return { ...AI_DEFAULT_CONFIG };
        }

        function setAiConfig(cfg) {
            localStorage.setItem('ceisa_ai_config', JSON.stringify(cfg, null, 2));
            updateAiBadges();
        }

        function updateAiBadges() {
            const cfg = getAiConfig();
            const badgeTop = document.getElementById('aiActiveModelBadge');
            const badgeSidebar = document.getElementById('sidebarAiBadge');
            const statusBadge = document.getElementById('aiConfigStatusBadge');

            let provName = (cfg.provider || 'gemini').toUpperCase();
            let modelName = cfg.model || 'default';
            let hasKey = !!cfg.api_key || cfg.provider === 'ollama';

            if (badgeTop) {
                badgeTop.innerText = `${provName}: ${modelName}`;
            }
            if (badgeSidebar) {
                badgeSidebar.innerText = hasKey ? 'Ready' : 'Set Key';
                badgeSidebar.style.background = hasKey ? '#8b5cf6' : '#f59e0b';
            }
            if (statusBadge) {
                if (hasKey) {
                    statusBadge.innerHTML = `<span class="badge" style="background:#10b981; color:#fff;">✓ AI Siap (${provName})</span>`;
                } else {
                    statusBadge.innerHTML = `<span class="badge" style="background:#f59e0b; color:#fff; cursor:pointer;" onclick="openAiConfigModal()">⚠️ API Key Belum Diset</span>`;
                }
            }
        }

        function initAiReader() {
            updateAiBadges();

            // Setup Drag & Drop listeners
            const dropZone = document.getElementById('aiDropZone');
            if (dropZone) {
                ['dragenter', 'dragover'].forEach(eventName => {
                    dropZone.addEventListener(eventName, (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        dropZone.classList.add('dragover');
                    }, false);
                });

                ['dragleave', 'drop'].forEach(eventName => {
                    dropZone.addEventListener(eventName, (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        dropZone.classList.remove('dragover');
                    }, false);
                });

                dropZone.addEventListener('drop', (e) => {
                    const dt = e.dataTransfer;
                    if (dt && dt.files && dt.files.length > 0) {
                        addAiFiles(dt.files);
                    }
                }, false);
            }
        }

        function openAiReaderModal() {
            // Check active aju
            const targetSel = document.getElementById('aiTargetAju');
            if (targetSel) {
                const optCurr = targetSel.querySelector('option[value="CURRENT"]');
                if (currentAju) {
                    if (optCurr) {
                        optCurr.text = `Dokumen Aktif: ${currentAju}`;
                        optCurr.disabled = false;
                    }
                    targetSel.value = 'CURRENT';
                } else {
                    if (optCurr) {
                        optCurr.text = 'Dokumen AJU Aktif (Belum Ada Dokumen Terpilih)';
                        optCurr.disabled = true;
                    }
                    targetSel.value = 'NEW';
                }
            }

            // Sync kode_dokumen from active header if available
            const kodeBcSel = document.getElementById('aiKodeBc');
            if (kodeBcSel && currentData.header && currentData.header.kode_dokumen) {
                const clean = currentData.header.kode_dokumen.replace(/[^0-9]/g, '');
                if (clean) kodeBcSel.value = clean;
            }

            updateAiBadges();

            // If API key is not configured, give a friendly hint
            const cfg = getAiConfig();
            if (!cfg.api_key && cfg.provider !== 'ollama') {
                openAiConfigModal();
                showToast('Silakan atur API Key AI Anda terlebih dahulu.');
                return;
            }

            document.getElementById('modalAiReader').style.display = 'flex';
        }

        function handleAiFileSelect(event) {
            if (event.target && event.target.files) {
                addAiFiles(event.target.files);
            }
        }

        function addAiFiles(fileList) {
            for (let i = 0; i < fileList.length; i++) {
                const f = fileList[i];
                const exists = aiSelectedFiles.some(item => item.name === f.name && item.size === f.size);
                if (!exists) {
                    aiSelectedFiles.push(f);
                }
            }
            renderAiFileList();
        }

        function removeAiFile(idx) {
            aiSelectedFiles.splice(idx, 1);
            renderAiFileList();
        }

        function clearAiFiles() {
            aiSelectedFiles = [];
            const inp = document.getElementById('aiFileInput');
            if (inp) inp.value = '';
            renderAiFileList();
        }

        function renderAiFileList() {
            const container = document.getElementById('aiFileListContainer');
            const listEl = document.getElementById('aiFileList');
            const countEl = document.getElementById('aiFileCount');
            const btnExtract = document.getElementById('btnRunAiExtraction');

            if (!container || !listEl) return;

            if (aiSelectedFiles.length === 0) {
                container.style.display = 'none';
                listEl.innerHTML = '';
                if (btnExtract) btnExtract.disabled = true;
                return;
            }

            container.style.display = 'block';
            if (countEl) countEl.innerText = aiSelectedFiles.length;
            if (btnExtract) btnExtract.disabled = false;

            listEl.innerHTML = aiSelectedFiles.map((f, idx) => {
                const ext = f.name.split('.').pop().toLowerCase();
                let icon = '📄';
                if (ext === 'pdf') icon = '📕';
                else if (['doc', 'docx'].includes(ext)) icon = '📘';
                else if (['xls', 'xlsx', 'csv'].includes(ext)) icon = '📗';
                else if (['png', 'jpg', 'jpeg'].includes(ext)) icon = '🖼️';

                const sizeKb = (f.size / 1024).toFixed(1);
                return `
                    <div class="ai-file-chip">
                        <span>${icon} <strong>${escapeHtml(f.name)}</strong> (${sizeKb} KB)</span>
                        <button type="button" onclick="removeAiFile(${idx})" style="background:none; border:none; color:var(--danger); cursor:pointer; font-weight:700; padding:0 3px;">✕</button>
                    </div>
                `;
            }).join('');
        }

        async function runAiExtraction() {
            if (aiSelectedFiles.length === 0) {
                alert('Silakan pilih atau seret minimal 1 berkas dokumen pabean!');
                return;
            }

            const cfg = getAiConfig();
            if (!cfg.api_key && cfg.provider !== 'ollama') {
                openAiConfigModal();
                alert('API Key belum diisi. Silakan masukkan API Key AI Anda terlebih dahulu.');
                return;
            }

            const btnExtract = document.getElementById('btnRunAiExtraction');
            const procContainer = document.getElementById('aiProcessContainer');
            const statusText = document.getElementById('aiProcessStatusText');
            const previewContainer = document.getElementById('aiResultPreviewContainer');
            const btnInject = document.getElementById('btnExecuteAiInject');

            if (btnExtract) btnExtract.disabled = true;
            if (procContainer) procContainer.style.display = 'block';
            if (previewContainer) previewContainer.style.display = 'none';
            if (btnInject) btnInject.disabled = true;

            if (statusText) statusText.innerText = `Sedang membaca berkas dan mengirim ke ${cfg.provider.toUpperCase()} (${cfg.model})...`;

            try {
                const fd = new FormData();
                aiSelectedFiles.forEach(f => {
                    fd.append('documents[]', f);
                });
                fd.append('config', JSON.stringify(cfg));
                fd.append('kode_dokumen', document.getElementById('aiKodeBc')?.value || '20');

                const res = await fetch('editor.php?action=ai_extract', {
                    method: 'POST',
                    body: fd
                });
                const json = await res.json();

                if (json.status === 'OK' && json.data) {
                    aiExtractedData = json.data;
                    renderAiExtractedPreview(aiExtractedData);
                    if (procContainer) procContainer.style.display = 'none';
                    if (previewContainer) previewContainer.style.display = 'block';
                    if (btnInject) btnInject.disabled = false;
                    showToast('✓ Ekstraksi AI Berhasil! Silakan periksa data sebelum disimpan.');
                } else {
                    if (procContainer) procContainer.style.display = 'none';
                    alert('Gagal Ekstraksi AI:\n' + (json.message || 'Terjadi kesalahan saat memproses dokumen.'));
                }
            } catch(e) {
                if (procContainer) procContainer.style.display = 'none';
                alert('Kesalahan Jaringan atau Server:\n' + e.message);
            } finally {
                if (btnExtract) btnExtract.disabled = false;
            }
        }

        function renderAiExtractedPreview(data) {
            if (!data) return;

            // 1. Header
            const hdr = data.header || {};
            const valutaEl = document.getElementById('aiPrevValuta');
            const fobEl    = document.getElementById('aiPrevFob');
            const freightEl= document.getElementById('aiPrevFreight');
            const asurEl   = document.getElementById('aiPrevAsuransi');
            const cifEl    = document.getElementById('aiPrevCif');
            const caraEl   = document.getElementById('aiPrevCaraAngkut');
            const namaEl   = document.getElementById('aiPrevNamaAngkut');
            const noEl     = document.getElementById('aiPrevNoAngkut');

            if (valutaEl) valutaEl.value = hdr.kode_valuta || 'USD';
            if (fobEl)    fobEl.value = parseFloat(hdr.fob || 0).toFixed(2);
            if (freightEl)freightEl.value = parseFloat(hdr.freight || 0).toFixed(2);
            if (asurEl)   asurEl.value = parseFloat(hdr.asuransi || 0).toFixed(2);
            if (cifEl)    cifEl.value = parseFloat(hdr.cif || 0).toFixed(2);
            if (caraEl && hdr.kode_cara_angkut) caraEl.value = hdr.kode_cara_angkut;
            if (namaEl)   namaEl.value = hdr.nama_pengangkut || '';
            if (noEl)     noEl.value = hdr.nomor_pengangkut || '';

            // 2. Entitas
            const entitas = data.entitas || [];
            const tbEntitas = document.querySelector('#aiTableEntitas tbody');
            const bdgEntitas = document.getElementById('aiBadgeEntitasCount');
            if (bdgEntitas) bdgEntitas.innerText = entitas.length;
            if (tbEntitas) {
                tbEntitas.innerHTML = entitas.map((e, idx) => {
                    let peran = 'Lainnya';
                    if (e.kode_entitas == '1') peran = 'Eksportir / Pemasok (1)';
                    else if (e.kode_entitas == '2') peran = 'Importir (2)';
                    else if (e.kode_entitas == '3') peran = 'Pengusaha TPB (3)';
                    else if (e.kode_entitas == '7') peran = 'Pemilik Barang (7)';
                    return `
                        <tr>
                            <td><strong>${peran}</strong></td>
                            <td>${escapeHtml(e.nama_entitas || '-')}</td>
                            <td>${escapeHtml(e.alamat_entitas || '-')}</td>
                            <td><code>${escapeHtml(e.kode_negara || '-')}</code></td>
                            <td>${escapeHtml(e.nomor_identitas || '-')}</td>
                        </tr>
                    `;
                }).join('') || '<tr><td colspan="5" style="text-align:center; color:var(--text-muted);">Tidak ada entitas yang terdeteksi</td></tr>';
            }

            // 3. Dokumen
            const dokumen = data.dokumen || [];
            const tbDokumen = document.querySelector('#aiTableDokumen tbody');
            const bdgDokumen = document.getElementById('aiBadgeDokumenCount');
            if (bdgDokumen) bdgDokumen.innerText = dokumen.length;
            if (tbDokumen) {
                tbDokumen.innerHTML = dokumen.map((d, idx) => {
                    let jns = 'Dokumen Lainnya';
                    if (d.kode_dokumen == '380') jns = 'Commercial Invoice';
                    else if (d.kode_dokumen == '705') jns = 'Bill of Lading (B/L)';
                    else if (d.kode_dokumen == '740') jns = 'Air Waybill (AWB)';
                    else if (d.kode_dokumen == '217') jns = 'Packing List';
                    return `
                        <tr>
                            <td><code>${escapeHtml(d.kode_dokumen || '380')}</code></td>
                            <td><strong>${jns}</strong></td>
                            <td><strong>${escapeHtml(d.nomor_dokumen || '-')}</strong></td>
                            <td>${escapeHtml(d.tanggal_dokumen || '-')}</td>
                        </tr>
                    `;
                }).join('') || '<tr><td colspan="4" style="text-align:center; color:var(--text-muted);">Tidak ada dokumen pelengkap terdeteksi</td></tr>';
            }

            // 4. Kemasan
            const kemasan = data.kemasan || [];
            const tbKemasan = document.querySelector('#aiTableKemasan tbody');
            const bdgKemasan = document.getElementById('aiBadgeKemasanCount');
            if (bdgKemasan) bdgKemasan.innerText = kemasan.length;
            if (tbKemasan) {
                tbKemasan.innerHTML = kemasan.map((k, idx) => {
                    return `
                        <tr>
                            <td>${idx + 1}</td>
                            <td><strong>${k.jumlah_kemasan || 1}</strong></td>
                            <td><code>${escapeHtml(k.kode_kemasan || 'CT')}</code></td>
                            <td>${escapeHtml(k.merek || '-')}</td>
                        </tr>
                    `;
                }).join('') || '<tr><td colspan="4" style="text-align:center; color:var(--text-muted);">Tidak ada data kemasan terdeteksi</td></tr>';
            }

            // 5. Barang
            const barang = data.barang || [];
            const tbBarang = document.querySelector('#aiTableBarang tbody');
            const bdgBarang = document.getElementById('aiBadgeBarangCount');
            if (bdgBarang) bdgBarang.innerText = barang.length;
            if (tbBarang) {
                tbBarang.innerHTML = barang.map((b, idx) => {
                    return `
                        <tr>
                            <td>${b.seri_barang || (idx + 1)}</td>
                            <td><code>${escapeHtml(b.kode_barang || '-')}</code></td>
                            <td><strong style="color:var(--primary);">${escapeHtml(b.hs || '-')}</strong></td>
                            <td>${escapeHtml(b.uraian || '-')}</td>
                            <td style="text-align:right;"><strong>${parseFloat(b.jumlah_satuan || 0).toLocaleString()}</strong></td>
                            <td><code>${escapeHtml(b.kode_satuan || 'PCE')}</code></td>
                            <td style="text-align:right;">${parseFloat(b.harga_satuan || 0).toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
                            <td style="text-align:right;"><strong>${parseFloat(b.fob || 0).toLocaleString(undefined, {minimumFractionDigits: 2})}</strong></td>
                            <td><code>${escapeHtml(b.kode_negara_asal || '-')}</code></td>
                            <td style="text-align:right;">${parseFloat(b.netto || 0).toFixed(4)}</td>
                            <td style="text-align:right;">${parseFloat(b.bruto || 0).toFixed(4)}</td>
                        </tr>
                    `;
                }).join('') || '<tr><td colspan="11" style="text-align:center; color:var(--text-muted);">Tidak ada baris barang yang terdeteksi</td></tr>';
            }

            // Footer summary
            const footerSummary = document.getElementById('aiFooterSummary');
            if (footerSummary) {
                const totalFob = parseFloat(hdr.fob || 0).toLocaleString(undefined, {minimumFractionDigits: 2});
                footerSummary.innerHTML = `<strong>Ringkasan Ekstraksi:</strong> ${barang.length} Item Barang | Total FOB: <strong>${hdr.kode_valuta || 'USD'} ${totalFob}</strong> | ${dokumen.length} Dokumen | ${kemasan.length} Kemasan`;
            }
        }

        function switchAiPreviewTab(tabId) {
            document.querySelectorAll('#modalAiReader .ai-preview-tab-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.tab === tabId);
            });
            document.querySelectorAll('#modalAiReader .ai-preview-tab-content').forEach(c => {
                c.style.display = (c.id === tabId) ? 'block' : 'none';
            });
        }

        function recalcAiCif() {
            const fob = parseFloat(document.getElementById('aiPrevFob')?.value || 0);
            const freight = parseFloat(document.getElementById('aiPrevFreight')?.value || 0);
            const asur = parseFloat(document.getElementById('aiPrevAsuransi')?.value || 0);
            const cifEl = document.getElementById('aiPrevCif');
            if (cifEl) {
                cifEl.value = (fob + freight + asur).toFixed(2);
            }
        }

        async function executeAiInject() {
            if (!aiExtractedData) {
                alert('Belum ada data ekstraksi AI yang dapat dimasukkan!');
                return;
            }

            // Gather edited values from Header
            const editedData = JSON.parse(JSON.stringify(aiExtractedData));
            if (!editedData.header) editedData.header = {};

            editedData.header.kode_valuta      = document.getElementById('aiPrevValuta')?.value.trim().toUpperCase() || 'USD';
            editedData.header.fob              = parseFloat(document.getElementById('aiPrevFob')?.value || 0);
            editedData.header.freight          = parseFloat(document.getElementById('aiPrevFreight')?.value || 0);
            editedData.header.asuransi         = parseFloat(document.getElementById('aiPrevAsuransi')?.value || 0);
            editedData.header.cif              = parseFloat(document.getElementById('aiPrevCif')?.value || (editedData.header.fob + editedData.header.freight + editedData.header.asuransi));
            editedData.header.kode_cara_angkut = document.getElementById('aiPrevCaraAngkut')?.value || '1';
            editedData.header.nama_pengangkut  = document.getElementById('aiPrevNamaAngkut')?.value.trim() || '';
            editedData.header.nomor_pengangkut = document.getElementById('aiPrevNoAngkut')?.value.trim() || '';

            const targetChoice = document.getElementById('aiTargetAju')?.value || 'NEW';
            const targetAju = (targetChoice === 'CURRENT' && currentAju) ? currentAju : 'NEW';
            const cmpId = new URLSearchParams(window.location.search).get('cmp_id') || (currentData.header?.cmp_id || '');
            const kodeBc = document.getElementById('aiKodeBc')?.value || '20';

            const btn = document.getElementById('btnExecuteAiInject');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner"></span> Memasukkan ke Staging...';
            }

            try {
                const res = await fetch('editor.php?action=ai_inject', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        nomor_aju: targetAju,
                        cmp_id: cmpId,
                        kode_dokumen: kodeBc,
                        data: editedData
                    })
                });

                const json = await res.json();
                if (json.status === 'OK') {
                    const finalAju = json.nomor_aju || targetAju;
                    closeModal('modalAiReader');

                    // If a new AJU was generated, prepend to selector
                    const sel = document.getElementById('selectAju');
                    if (sel && finalAju !== currentAju) {
                        let optFound = false;
                        for (let i = 0; i < sel.options.length; i++) {
                            if (sel.options[i].value === finalAju) {
                                optFound = true;
                                break;
                            }
                        }
                        if (!optFound) {
                            const newOpt = document.createElement('option');
                            newOpt.value = finalAju;
                            newOpt.textContent = `[BC ${kodeBc}] ${finalAju} (AI Staging)`;
                            sel.prepend(newOpt);
                        }
                        sel.value = finalAju;
                    }

                    // Reload editor with the new or updated AJU
                    await loadAjuData(finalAju);
                    showToast(`🤖 ${json.message || 'Ekstraksi AI berhasil dimasukkan ke staging!'}`);
                } else {
                    alert('Gagal memasukkan data ke staging:\n' + (json.message || 'Terjadi kesalahan pada database.'));
                }
            } catch(e) {
                alert('Kesalahan jaringan: ' + e.message);
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '⚡ Masukkan ke Staging AJU';
                }
            }
        }

        // -------------------------------------------------------------
        // AI CONFIGURATION MODAL LOGIC
        // -------------------------------------------------------------
        function openAiConfigModal() {
            const cfg = getAiConfig();
            const provSel = document.getElementById('cfgAiProvider');
            const modelInp= document.getElementById('cfgAiModel');
            const keyInp  = document.getElementById('cfgAiApiKey');
            const endpInp = document.getElementById('cfgAiEndpoint');
            const rawJson = document.getElementById('cfgAiRawJson');

            if (provSel) provSel.value = cfg.provider || 'gemini';
            if (modelInp) modelInp.value = cfg.model || 'gemini-2.0-flash';
            if (keyInp) keyInp.value = cfg.api_key || '';
            if (endpInp) endpInp.value = cfg.endpoint || '';
            if (rawJson) rawJson.value = JSON.stringify(cfg, null, 2);

            onProviderChange();
            switchConfigMode('visual');

            const resDiv = document.getElementById('aiTestConnResult');
            if (resDiv) resDiv.style.display = 'none';

            document.getElementById('modalAiConfig').style.display = 'flex';
        }

        function switchConfigMode(mode) {
            aiActiveConfigTab = mode;
            const btnVis = document.getElementById('btnConfigTabVisual');
            const btnJson= document.getElementById('btnConfigTabJson');
            const visDiv = document.getElementById('aiConfigVisualContainer');
            const jsonDiv= document.getElementById('aiConfigJsonContainer');

            if (btnVis) btnVis.classList.toggle('active', mode === 'visual');
            if (btnJson) btnJson.classList.toggle('active', mode === 'json');

            if (mode === 'visual') {
                if (visDiv) visDiv.style.display = 'flex';
                if (jsonDiv) jsonDiv.style.display = 'none';
                // Sync from JSON back to form if valid
                try {
                    const raw = document.getElementById('cfgAiRawJson')?.value;
                    if (raw) {
                        const parsed = JSON.parse(raw);
                        if (parsed.provider) document.getElementById('cfgAiProvider').value = parsed.provider;
                        if (parsed.model) document.getElementById('cfgAiModel').value = parsed.model;
                        if (parsed.api_key !== undefined) document.getElementById('cfgAiApiKey').value = parsed.api_key;
                        if (parsed.endpoint !== undefined) document.getElementById('cfgAiEndpoint').value = parsed.endpoint;
                        onProviderChange();
                    }
                } catch(e) {}
            } else {
                if (visDiv) visDiv.style.display = 'none';
                if (jsonDiv) jsonDiv.style.display = 'flex';
                // Sync from form to JSON
                const currentFromForm = {
                    provider: document.getElementById('cfgAiProvider')?.value || 'gemini',
                    model: document.getElementById('cfgAiModel')?.value.trim() || 'gemini-2.0-flash',
                    api_key: document.getElementById('cfgAiApiKey')?.value.trim() || '',
                    endpoint: document.getElementById('cfgAiEndpoint')?.value.trim() || '',
                    temperature: 0.1
                };
                if (jsonDiv) document.getElementById('cfgAiRawJson').value = JSON.stringify(currentFromForm, null, 2);
            }
        }

        function onProviderChange() {
            const prov = document.getElementById('cfgAiProvider')?.value || 'gemini';
            const preset = AI_PROVIDER_PRESETS[prov] || AI_PROVIDER_PRESETS.gemini;
            const modelInp = document.getElementById('cfgAiModel');
            const helpEl = document.getElementById('cfgModelHelp');
            const endpGrp = document.getElementById('cfgEndpointGroup');
            const endpInp = document.getElementById('cfgAiEndpoint');
            const linkEl = document.getElementById('cfgApiKeyLink');

            if (helpEl) helpEl.innerText = preset.help;

            // Show custom endpoint input for ollama, deepseek, groq, openai_compatible
            const needsEndpoint = ['ollama', 'deepseek', 'groq', 'openai_compatible', 'custom'].includes(prov);
            if (endpGrp) endpGrp.style.display = needsEndpoint ? 'block' : 'none';
            if (needsEndpoint && endpInp && !endpInp.value) {
                endpInp.value = preset.endpoint || '';
            }

            if (linkEl) {
                if (preset.keyUrl) {
                    linkEl.href = preset.keyUrl;
                    linkEl.style.display = 'inline';
                } else {
                    linkEl.style.display = 'none';
                }
            }
        }

        function applyAiPreset(presetKey) {
            const preset = AI_PROVIDER_PRESETS[presetKey];
            if (!preset) return;

            const provSel = document.getElementById('cfgAiProvider');
            const modelInp= document.getElementById('cfgAiModel');
            const endpInp = document.getElementById('cfgAiEndpoint');

            if (provSel) provSel.value = preset.provider;
            if (modelInp) modelInp.value = preset.model;
            if (endpInp) endpInp.value = preset.endpoint || '';

            onProviderChange();

            // If in JSON mode, update JSON textarea as well
            if (aiActiveConfigTab === 'json') {
                const currentFromForm = {
                    provider: preset.provider,
                    model: preset.model,
                    api_key: document.getElementById('cfgAiApiKey')?.value.trim() || '',
                    endpoint: preset.endpoint || '',
                    temperature: 0.1
                };
                document.getElementById('cfgAiRawJson').value = JSON.stringify(currentFromForm, null, 2);
            }

            showToast(`Preset ${preset.model} diterapkan!`);
        }

        function toggleApiKeyVisibility() {
            const inp = document.getElementById('cfgAiApiKey');
            const btn = document.getElementById('btnToggleApiKeyVis');
            if (inp) {
                if (inp.type === 'password') {
                    inp.type = 'text';
                    if (btn) btn.innerText = '🙈';
                } else {
                    inp.type = 'password';
                    if (btn) btn.innerText = '👁️';
                }
            }
        }

        async function testAiConnection() {
            let cfg = {};
            if (aiActiveConfigTab === 'json') {
                try {
                    cfg = JSON.parse(document.getElementById('cfgAiRawJson').value);
                } catch(e) {
                    alert('Format JSON Konfigurasi tidak valid:\n' + e.message);
                    return;
                }
            } else {
                cfg = {
                    provider: document.getElementById('cfgAiProvider')?.value || 'gemini',
                    model: document.getElementById('cfgAiModel')?.value.trim() || '',
                    api_key: document.getElementById('cfgAiApiKey')?.value.trim() || '',
                    endpoint: document.getElementById('cfgAiEndpoint')?.value.trim() || '',
                    temperature: 0.1
                };
            }

            const btn = document.getElementById('btnTestAiConn');
            const resDiv = document.getElementById('aiTestConnResult');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner"></span> Menguji...';
            }
            if (resDiv) {
                resDiv.style.display = 'block';
                resDiv.style.background = 'var(--bg-base)';
                resDiv.style.border = '1px solid var(--border)';
                resDiv.style.color = 'var(--text-main)';
                resDiv.innerHTML = '⏳ Menghubungi AI endpoint & memverifikasi respons...';
            }

            try {
                const res = await fetch('editor.php?action=ai_test_conn', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(cfg)
                });
                const json = await res.json();
                if (json.status === 'OK') {
                    resDiv.style.background = 'rgba(16, 185, 129, 0.1)';
                    resDiv.style.borderColor = '#10b981';
                    resDiv.style.color = '#10b981';
                    resDiv.innerHTML = `<strong>✓ Terhubung!</strong> ${escapeHtml(json.message || 'Koneksi ke AI Provider berhasil.')}`;
                } else {
                    resDiv.style.background = 'rgba(239, 68, 68, 0.1)';
                    resDiv.style.borderColor = '#ef4444';
                    resDiv.style.color = '#ef4444';
                    resDiv.innerHTML = `<strong>✕ Gagal Koneksi:</strong> ${escapeHtml(json.message || 'Tidak ada respon dari server.')}`;
                }
            } catch(e) {
                if (resDiv) {
                    resDiv.style.background = 'rgba(239, 68, 68, 0.1)';
                    resDiv.style.borderColor = '#ef4444';
                    resDiv.style.color = '#ef4444';
                    resDiv.innerHTML = `<strong>✕ Error Jaringan:</strong> ${escapeHtml(e.message)}`;
                }
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '🔌 Uji Koneksi AI';
                }
            }
        }

        function saveAiConfig() {
            let cfg = {};
            if (aiActiveConfigTab === 'json') {
                try {
                    cfg = JSON.parse(document.getElementById('cfgAiRawJson').value);
                } catch(e) {
                    alert('Format JSON Konfigurasi tidak valid:\n' + e.message);
                    return;
                }
            } else {
                cfg = {
                    provider: document.getElementById('cfgAiProvider')?.value || 'gemini',
                    model: document.getElementById('cfgAiModel')?.value.trim() || 'gemini-2.0-flash',
                    api_key: document.getElementById('cfgAiApiKey')?.value.trim() || '',
                    endpoint: document.getElementById('cfgAiEndpoint')?.value.trim() || '',
                    temperature: 0.1
                };
            }

            setAiConfig(cfg);
            closeModal('modalAiConfig');
            showToast('✓ Konfigurasi AI berhasil disimpan!');
        }

        const headerFields = [
            'nomor_aju', 'kode_dokumen', 'kode_kantor', 'nomor_daftar', 'tanggal_daftar',
            'nomor_bc11', 'tanggal_bc11', 'nomor_pos', 'nomor_sub_pos', 'kode_tutup_pu',
            'kode_cara_bayar', 'kode_cara_dagang', 'kode_jenis_impor', 'kode_jenis_ekspor',
            'kode_kategori_ekspor', 'kode_jenis_tpb', 'kode_tujuan_pemasukan', 'kode_tujuan_pengeluaran',
            'kode_tujuan_pengiriman', 'kode_lokasi_bayar',
            'kode_pelabuhan_muat', 'kode_pelabuhan_bongkar', 'kode_pelabuhan_transit', 'kode_pelabuhan_tujuan',
            'kode_pelabuhan_ekspor', 'kode_kantor_muat', 'kode_kantor_bongkar', 'kode_kantor_tujuan',
            'kode_tps', 'kode_negara_tujuan', 'tanggal_tiba', 'tanggal_ekspor',
            'kode_valuta', 'ndpbm', 'kode_incoterm', 'nilai_incoterm', 'fob', 'cif', 'asuransi', 'freight',
            'harga_penyerahan', 'nilai_barang', 'kode_jasa_kena_pajak', 'dasar_pengenaan_pajak',
            'bruto', 'netto', 'volume', 'nama_pernyataan', 'jabatan_pernyataan', 'kota_pernyataan', 'tanggal_pernyataan'
        ];

        function formatDateYmd(val) {
            if (!val || val === '0000-00-00' || String(val).startsWith('0000-00-00') || val === 'null' || val === 'undefined') {
                return '';
            }
            const str = String(val).trim();
            // YYYY-MM-DD or YYYY-MM-DD HH:mm:ss
            const mIso = str.match(/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})/);
            if (mIso) {
                return `${mIso[1]}-${String(mIso[2]).padStart(2, '0')}-${String(mIso[3]).padStart(2, '0')}`;
            }
            // DD/MM/YYYY or DD-MM-YYYY
            const mDmy = str.match(/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})/);
            if (mDmy) {
                return `${mDmy[3]}-${String(mDmy[2]).padStart(2, '0')}-${String(mDmy[1]).padStart(2, '0')}`;
            }
            return str;
        }

        function triggerHdrDatePicker(btn) {
            const group = btn.closest('.input-date-group');
            if (!group) return;
            const txt = group.querySelector('.hdr-date-input');
            const nat = group.querySelector('.hdr-date-native');
            if (!nat) return;
            nat.value = formatDateYmd(txt.value);
            nat.onchange = function() {
                txt.value = formatDateYmd(this.value);
                txt.dispatchEvent(new Event('change'));
            };
            try {
                if (typeof nat.showPicker === 'function') {
                    nat.showPicker();
                } else {
                    nat.focus();
                }
            } catch (e) {
                nat.focus();
            }
        }

        function parseInputNumber(val) {
            if (val === null || val === undefined) return NaN;
            let s = String(val).trim();
            if (s === '') return NaN;
            if (/^-?\d{1,3}(\.\d{3})+,\d+$/.test(s)) {
                s = s.replace(/\./g, '').replace(',', '.');
            } else if (/^-?\d+,\d+$/.test(s) && !s.includes('.')) {
                s = s.replace(',', '.');
            } else {
                s = s.replace(/,/g, '');
            }
            return parseFloat(s);
        }

        function formatCeisaNumber(val, decimals) {
            if (val === null || val === undefined || String(val).trim() === '') return '';
            const n = parseInputNumber(val);
            if (isNaN(n)) return val;
            const dec = typeof decimals === 'number' ? decimals : 2;
            return Number(n).toLocaleString('en-US', {
                minimumFractionDigits: dec,
                maximumFractionDigits: dec
            });
        }

        function stripNumberCommas(val) {
            if (val === null || val === undefined) return '';
            let s = String(val).trim();
            if (s === '') return '';
            if (/^-?\d{1,3}(\.\d{3})+,\d+$/.test(s)) {
                s = s.replace(/\./g, '').replace(',', '.');
            } else if (/^-?\d+,\d+$/.test(s) && !s.includes('.')) {
                s = s.replace(',', '.');
            } else {
                s = s.replace(/,/g, '');
            }
            return s;
        }

        const headerNumberDecimals = {
            ndpbm: 4,
            bruto: 4,
            netto: 4,
            volume: 4,
            cif: 2,
            fob: 2,
            asuransi: 2,
            asuransi1: 2,
            freight: 2,
            nilai_incoterm: 2,
            harga_penyerahan: 2,
            nilai_barang: 2,
            dasar_pengenaan_pajak: 2,
            biaya_tambahan: 2,
            biaya_pengurang: 2,
            vd: 2,
            uang_muka: 2,
            nilai_jasa: 2,
            total_dana_sawit: 2,
            ppn_pajak: 2,
            ppnbm_pajak: 2,
            tarif_ppn_pajak: 2,
            tarif_ppnbm_pajak: 2
        };

        let currentDecimalSettings = {
            qty: 2,
            kemasan: 0,
            uang: 2,
            harga_satuan: 4,
            berat: 4,
            tarif: 2
        };

        function applyDecimalSettingsToConfigs(settings) {
            if (!settings) return;
            currentDecimalSettings = Object.assign({}, currentDecimalSettings, settings);

            const decQty = (typeof settings.qty === 'number') ? settings.qty : parseInt(settings.qty || 2, 10);
            const decKemasan = (typeof settings.kemasan === 'number') ? settings.kemasan : parseInt(settings.kemasan || 0, 10);
            const decUang = (typeof settings.uang === 'number') ? settings.uang : parseInt(settings.uang || 2, 10);
            const decHargaSatuan = (typeof settings.harga_satuan === 'number') ? settings.harga_satuan : parseInt(settings.harga_satuan || 4, 10);
            const decBerat = (typeof settings.berat === 'number') ? settings.berat : parseInt(settings.berat || 4, 10);
            const decTarif = (typeof settings.tarif === 'number') ? settings.tarif : parseInt(settings.tarif || 2, 10);

            // 1. Update headerNumberDecimals
            headerNumberDecimals.netto = decBerat;
            headerNumberDecimals.bruto = decBerat;
            headerNumberDecimals.volume = decBerat;

            headerNumberDecimals.cif = decUang;
            headerNumberDecimals.fob = decUang;
            headerNumberDecimals.asuransi = decUang;
            headerNumberDecimals.asuransi1 = decUang;
            headerNumberDecimals.freight = decUang;
            headerNumberDecimals.nilai_incoterm = decUang;
            headerNumberDecimals.harga_penyerahan = decUang;
            headerNumberDecimals.nilai_barang = decUang;
            headerNumberDecimals.dasar_pengenaan_pajak = decUang;
            headerNumberDecimals.biaya_tambahan = decUang;
            headerNumberDecimals.biaya_pengurang = decUang;
            headerNumberDecimals.vd = decUang;
            headerNumberDecimals.uang_muka = decUang;
            headerNumberDecimals.nilai_jasa = decUang;
            headerNumberDecimals.total_dana_sawit = decUang;
            headerNumberDecimals.ppn_pajak = decUang;
            headerNumberDecimals.ppnbm_pajak = decUang;

            headerNumberDecimals.tarif_ppn_pajak = decTarif;
            headerNumberDecimals.tarif_ppnbm_pajak = decTarif;

            // 2. Update gridConfigs
            if (gridConfigs.barang) {
                gridConfigs.barang.forEach(col => {
                    if (col.key === 'jumlah_satuan') col.decimals = decQty;
                    else if (col.key === 'jumlah_kemasan') col.decimals = decKemasan;
                    else if (['netto', 'bruto', 'volume'].includes(col.key)) col.decimals = decBerat;
                    else if (col.key === 'harga_satuan') col.decimals = decHargaSatuan;
                    else if (['cif', 'cif_rupiah', 'fob', 'harga_penyerahan', 'nilai_barang'].includes(col.key)) col.decimals = decUang;
                });
            }

            if (gridConfigs.bahanbaku) {
                gridConfigs.bahanbaku.forEach(col => {
                    if (col.key === 'jumlah_satuan') col.decimals = decQty;
                    else if (col.key === 'cif') col.decimals = decUang;
                });
            }

            if (gridConfigs.barangtarif) {
                gridConfigs.barangtarif.forEach(col => {
                    if (col.key === 'tarif') col.decimals = decTarif;
                    else if (['nilai_bayar', 'nilai_fasilitas'].includes(col.key)) col.decimals = decUang;
                    else if (col.key === 'jumlah_satuan') col.decimals = decQty;
                });
            }

            if (gridConfigs.bahanbakutarif) {
                gridConfigs.bahanbakutarif.forEach(col => {
                    if (col.key === 'tarif') col.decimals = decTarif;
                    else if (['nilai_bayar', 'nilai_fasilitas'].includes(col.key)) col.decimals = decUang;
                });
            }

            if (gridConfigs.kemasan) {
                gridConfigs.kemasan.forEach(col => {
                    if (col.key === 'jumlah_kemasan') col.decimals = decKemasan;
                });
            }

            if (gridConfigs.pungutan) {
                gridConfigs.pungutan.forEach(col => {
                    if (col.key === 'nilai_pungutan') col.decimals = decUang;
                });
            }

            if (gridConfigs.jaminan) {
                gridConfigs.jaminan.forEach(col => {
                    if (col.key === 'nilai_jaminan') col.decimals = decUang;
                });
            }

            if (gridConfigs.komponenbiaya) {
                gridConfigs.komponenbiaya.forEach(col => {
                    if (col.type === 'number') col.decimals = decUang;
                });
            }

            if (gridConfigs.barangvd) {
                gridConfigs.barangvd.forEach(col => {
                    if (['nilai_barang', 'biaya_tambahan', 'biaya_pengurang'].includes(col.key)) col.decimals = decUang;
                });
            }
        }

        function refreshActiveGridDecimals() {
            // Re-format header input elements
            Object.entries(headerNumberDecimals).forEach(([f, dec]) => {
                const el = document.getElementById('hdr_' + f);
                if (el && el.value !== '') {
                    el.value = formatCeisaNumber(el.value, dec);
                }
            });

            // Re-format cells and footer summary in all active grid tables
            Object.keys(gridConfigs).forEach(tableKey => {
                const table = document.getElementById('table-' + tableKey);
                if (!table) return;

                const colMap = {};
                (gridConfigs[tableKey] || []).forEach(c => { colMap[c.key] = c; });

                table.querySelectorAll('tbody tr').forEach(tr => {
                    tr.querySelectorAll('input[data-col]').forEach(inp => {
                        const colKey = inp.dataset.col;
                        const col = colMap[colKey];
                        if (col && col.type === 'number' && typeof col.decimals === 'number' && inp.value !== '') {
                            inp.value = formatCeisaNumber(inp.value, col.decimals);
                        }
                    });
                });

                updateTableTotals(tableKey);
            });
        }

        function autoSizeCellInput(inp, label, isNumber) {
            inp.style.width = '100%';
            inp.style.maxWidth = '100%';
            inp.style.minWidth = '0';
            inp.style.boxSizing = 'border-box';
        }

        function autoSizeSelect(sel) {
            if (!sel) return;
            sel.style.width = '100%';
            sel.style.maxWidth = '100%';
            sel.style.minWidth = '0';
            sel.style.boxSizing = 'border-box';
        }

        function autoResizeTextarea(ta) {
            if (!ta) return;
            // Jika elemen berada dalam tab tersembunyi (offsetParent null), lewati dulu sampai tab aktif dibuka
            if (ta.offsetParent === null) return;
            ta.style.height = 'auto';
            const newH = Math.max(22, ta.scrollHeight + 2);
            ta.style.height = newH + 'px';
        }

        function autoResizeAllTextareas(container) {
            const scope = container || document;
            scope.querySelectorAll('textarea.cell-wrap').forEach(autoResizeTextarea);
        }

        /* ==========================================================================
           GRID RESIZING & SORTING SUBSYSTEM (Vanilla JS / Pure CSS)
           - Drag border header (th) untuk resize kolom tabel secara bebas & instan
           - Konten dropdown & input otomatis mengikuti lebar kolom baru (100% full width)
           - Klik header (th) untuk sorting ASC (▲) / DESC (▼) / Natural
           - Klik 2x resizer untuk me-reset lebar kolom ke standar default
           - Menyimpan lebar kolom ke localStorage secara persisten
           ========================================================================== */
        let _isGridResizing = false;

        function getSavedColumnWidths(tableKey) {
            try {
                const raw = localStorage.getItem('ceisa_grid_widths_' + tableKey);
                return raw ? JSON.parse(raw) : {};
            } catch (e) {
                return {};
            }
        }

        function saveColumnWidth(tableKey, colKey, width) {
            try {
                const saved = getSavedColumnWidths(tableKey);
                saved[colKey] = width;
                localStorage.setItem('ceisa_grid_widths_' + tableKey, JSON.stringify(saved));
            } catch (e) {}
        }

        function removeSavedColumnWidth(tableKey, colKey) {
            try {
                const saved = getSavedColumnWidths(tableKey);
                delete saved[colKey];
                localStorage.setItem('ceisa_grid_widths_' + tableKey, JSON.stringify(saved));
            } catch (e) {}
        }

        function getGridRowCellValue(tr, colKey) {
            const el = tr.querySelector(`input[data-col="${colKey}"], select[data-col="${colKey}"], textarea[data-col="${colKey}"]`);
            if (el) {
                if (el.tagName === 'SELECT') {
                    const opt = el.options[el.selectedIndex];
                    return opt ? (opt.text || opt.value || '') : (el.value || '');
                }
                return el.value !== undefined ? el.value.trim() : '';
            }
            const td = tr.querySelector(`td[data-col="${colKey}"]`);
            if (td) {
                const inner = td.querySelector('input, select, textarea');
                if (inner) {
                    if (inner.tagName === 'SELECT') {
                        const opt = inner.options[inner.selectedIndex];
                        return opt ? (opt.text || opt.value || '') : (inner.value || '');
                    }
                    return inner.value !== undefined ? inner.value.trim() : '';
                }
                return td.innerText.trim();
            }
            return '';
        }

        function sortGridTable(table, th, colKey) {
            if (!table || !th || !colKey) return;
            const tableKey = table.id ? table.id.replace('table-', '') : '';
            const tbody = table.querySelector('tbody');
            if (!tbody) return;

            const trs = Array.from(tbody.querySelectorAll('tr'));
            if (trs.length <= 1) return;

            let currentDir = th.dataset.sortDir || 'none';
            let newDir = currentDir === 'asc' ? 'desc' : 'asc';

            table.querySelectorAll('thead tr th').forEach(otherTh => {
                if (otherTh !== th) {
                    delete otherTh.dataset.sortDir;
                    otherTh.classList.remove('sort-asc', 'sort-desc');
                    const icon = otherTh.querySelector('.th-sort-icon');
                    if (icon) icon.innerHTML = '⇅';
                }
            });

            th.dataset.sortDir = newDir;
            th.classList.remove('sort-asc', 'sort-desc');
            th.classList.add(newDir === 'asc' ? 'sort-asc' : 'sort-desc');
            const myIcon = th.querySelector('.th-sort-icon');
            if (myIcon) myIcon.innerHTML = newDir === 'asc' ? '▲' : '▼';

            const conf = gridConfigs[tableKey];
            let colType = 'text';
            if (conf) {
                const colDef = conf.find(c => c.key === colKey);
                if (colDef) {
                    colType = colDef.type || 'text';
                    if (colDef.key.startsWith('seri') || colDef.decimals !== undefined) colType = 'number';
                }
            } else if (colKey.startsWith('seri')) {
                colType = 'number';
            }

            const isNum = colType === 'number';
            const isDate = colType === 'date' || colKey.includes('tanggal') || colKey.includes('tgl');

            trs.sort((a, b) => {
                const valA = getGridRowCellValue(a, colKey);
                const valB = getGridRowCellValue(b, colKey);

                let res = 0;
                if (isNum) {
                    const numA = parseFloat(typeof stripNumberCommas === 'function' ? stripNumberCommas(valA) : String(valA).replace(/,/g, '')) || 0;
                    const numB = parseFloat(typeof stripNumberCommas === 'function' ? stripNumberCommas(valB) : String(valB).replace(/,/g, '')) || 0;
                    res = numA - numB;
                } else if (isDate) {
                    const dateA = valA ? new Date(valA).getTime() : 0;
                    const dateB = valB ? new Date(valB).getTime() : 0;
                    res = dateA - dateB;
                } else {
                    res = valA.localeCompare(valB, undefined, { numeric: true, sensitivity: 'base' });
                }
                return newDir === 'asc' ? res : -res;
            });

            const frag = document.createDocumentFragment();
            trs.forEach(tr => frag.appendChild(tr));
            tbody.appendChild(frag);
        }

        function setupGridTable(tableKeyOrEl) {
            let table = null;
            if (typeof tableKeyOrEl === 'string') {
                table = document.getElementById(tableKeyOrEl.startsWith('table-') ? tableKeyOrEl : 'table-' + tableKeyOrEl);
            } else {
                table = tableKeyOrEl;
            }
            if (!table) return;

            const tableKey = table.id ? table.id.replace('table-', '') : '';
            const ths = table.querySelectorAll('thead tr th');
            if (ths.length === 0) return;

            const savedWidths = tableKey ? getSavedColumnWidths(tableKey) : {};

            ths.forEach((th, idx) => {
                const txt = th.innerText.trim().toLowerCase();
                const isAction = txt === 'hapus' || txt === 'aksi' || txt === 'pilih' || th.classList.contains('th-action') || !!th.querySelector('input[type="checkbox"]');

                if (isAction) {
                    th.classList.add('no-sort', 'th-action');
                    return;
                }

                // Bersihkan th-action jika bukan kolom aksi sesungguhnya
                th.classList.remove('th-action');

                // Dapatkan atau turunkan colKey
                let colKey = th.dataset.col;
                if (!colKey && tableKey && gridConfigs[tableKey] && gridConfigs[tableKey][idx]) {
                    colKey = gridConfigs[tableKey][idx].key;
                    th.dataset.col = colKey;
                }
                if (!colKey) {
                    colKey = txt.replace(/[^a-z0-9_]/g, '_').toLowerCase() || ('col_' + idx);
                    th.dataset.col = colKey;
                }

                // Restore saved width if present
                if (tableKey && colKey && savedWidths && savedWidths[colKey]) {
                    const sw = savedWidths[colKey];
                    th.style.width = sw;
                    th.style.minWidth = sw;
                    th.style.maxWidth = sw;
                }

                // Ensure sort icon exists (kecuali jika eksplisit no-sort)
                if (!th.classList.contains('no-sort')) {
                    let sortIcon = th.querySelector('.th-sort-icon');
                    if (!sortIcon) {
                        sortIcon = document.createElement('span');
                        sortIcon.className = 'th-sort-icon';
                        sortIcon.innerHTML = '⇅';
                        th.appendChild(sortIcon);
                    }
                }

                // Ensure resizer handle exists untuk semua kolom non-aksi
                let resizer = th.querySelector('.col-resizer');
                if (!resizer) {
                    resizer = document.createElement('div');
                    resizer.className = 'col-resizer';
                    resizer.title = 'Tarik untuk ubah lebar kolom (Klik 2x reset)';
                    th.appendChild(resizer);

                    // Double click to reset width
                    resizer.addEventListener('dblclick', function(e) {
                        e.stopPropagation();
                        e.preventDefault();
                        th.style.width = '';
                        th.style.minWidth = '';
                        th.style.maxWidth = '';
                        if (tableKey && colKey) {
                            removeSavedColumnWidth(tableKey, colKey);
                        }
                    });

                    // Resizer mousedown logic
                    resizer.addEventListener('mousedown', function(e) {
                        e.stopPropagation();
                        e.preventDefault();
                        _isGridResizing = true;

                        const startX = e.pageX;
                        const startWidth = th.offsetWidth;
                        resizer.classList.add('resizing');
                        document.body.classList.add('grid-resizing');

                        function onMouseMove(moveEvent) {
                            const diff = moveEvent.pageX - startX;
                            const newWidth = Math.max(35, startWidth + diff);
                            const wStr = newWidth + 'px';
                            th.style.width = wStr;
                            th.style.minWidth = wStr;
                            th.style.maxWidth = wStr;
                        }

                        function onMouseUp() {
                            document.removeEventListener('mousemove', onMouseMove);
                            document.removeEventListener('mouseup', onMouseUp);
                            resizer.classList.remove('resizing');
                            document.body.classList.remove('grid-resizing');

                            if (th.style.width && tableKey && colKey) {
                                saveColumnWidth(tableKey, colKey, th.style.width);
                            }

                            setTimeout(() => {
                                _isGridResizing = false;
                            }, 120);
                        }

                        document.addEventListener('mousemove', onMouseMove);
                        document.addEventListener('mouseup', onMouseUp);
                    });
                }

                // Sorting click on th (idempotent listener)
                if (!th._hasSortListener && !th.classList.contains('no-sort')) {
                    th._hasSortListener = true;
                    th.addEventListener('click', function(e) {
                        if (_isGridResizing) return;
                        if (e.target && (e.target.classList.contains('col-resizer') || e.target.tagName === 'INPUT')) return;
                        sortGridTable(table, th, colKey);
                    });
                }
            });
        }

        function setupAllGridTables() {
            document.querySelectorAll('table.datagrid').forEach(table => {
                setupGridTable(table);
            });
        }


        const gridConfigs = {
            barang: [
                { key: 'seri_barang', type: 'number', label: 'Seri', decimals: 0, width: '45px', minWidth: '45px', maxWidth: '45px' },
                { key: 'kode_barang', type: 'text', label: 'Kode Barang', minWidth: '120px' },
                { key: 'hs', type: 'text', label: 'HS Code', minWidth: '95px' },
                { key: 'uraian', type: 'text', label: 'Uraian Barang', minWidth: '220px' },
                { key: 'merek', type: 'text', label: 'Merek', minWidth: '100px' },
                { key: 'tipe', type: 'text', label: 'Tipe', minWidth: '100px' },
                { key: 'ukuran', type: 'text', label: 'Ukuran', minWidth: '100px' },
                { key: 'spesifikasi_lain', type: 'text', label: 'Spesifikasi', minWidth: '120px' },
                { key: 'jumlah_satuan', type: 'number', label: 'Jml Satuan', decimals: 4, minWidth: '95px' },
                { key: 'kode_satuan', type: 'text', label: 'Satuan', reff: 'SATUAN BARANG', minWidth: '75px' },
                { key: 'jumlah_kemasan', type: 'number', label: 'Jml Kemasan', decimals: 2, minWidth: '95px' },
                { key: 'kode_kemasan', type: 'select', label: 'Kemasan', reff: 'JENIS KEMASAN', width: '125px', minWidth: '125px', maxWidth: '125px' },
                { key: 'netto', type: 'number', label: 'Netto (kg)', decimals: 4, minWidth: '95px' },
                { key: 'bruto', type: 'number', label: 'Bruto (kg)', decimals: 4, minWidth: '95px' },
                { key: 'volume', type: 'number', label: 'Volume', decimals: 4, minWidth: '95px' },
                { key: 'cif', type: 'number', label: 'Nilai CIF', decimals: 2, minWidth: '105px' },
                { key: 'cif_rupiah', type: 'number', label: 'CIF (Rp)', decimals: 2, minWidth: '115px' },
                { key: 'fob', type: 'number', label: 'FOB', decimals: 2, minWidth: '105px' },
                { key: 'harga_satuan', type: 'number', label: 'Harga Satuan', decimals: 4, minWidth: '105px' },
                { key: 'harga_penyerahan', type: 'number', label: 'Harga Penyerahan', decimals: 2, minWidth: '115px' },
                { key: 'nilai_barang', type: 'number', label: 'Nilai Barang', decimals: 2, minWidth: '115px' },
                { key: 'kode_kategori_barang', type: 'text', label: 'Kategori', reff: 'KATEGORI BARANG', minWidth: '100px' },
                { key: 'kode_negara_asal', type: 'select', label: 'Negara Asal', reff: 'NEGARA', width: '135px', minWidth: '135px', maxWidth: '135px' },
                { key: 'kode_guna_barang', type: 'text', label: 'Penggunaan', reff: 'PENGGUNAAN', minWidth: '100px' },
                { key: 'kode_kondisi_barang', type: 'select', label: 'Kondisi', reff: 'KONDISI BARANG', width: '110px', minWidth: '110px', maxWidth: '110px' }
            ],
            entitas: [
                { key: 'seri', type: 'number', label: 'Seri', decimals: 0, width: '45px', minWidth: '45px', maxWidth: '45px' },
                { key: 'kode_entitas', type: 'select', label: 'Kode Entitas', reff: 'ENTITAS', width: '130px', minWidth: '130px', maxWidth: '130px' },
                { key: 'nama_entitas', type: 'wrap_text', label: 'Nama Entitas' },
                { key: 'nomor_identitas', type: 'text', label: 'Nomor Identitas (NPWP)', width: '195px', minWidth: '185px' },
                { key: 'kode_jenis_identitas', type: 'select', label: 'Jenis Identitas', reff: 'JENIS IDENTITAS', width: '125px', minWidth: '125px', maxWidth: '125px' },
                { key: 'alamat_entitas', type: 'wrap_text', label: 'Alamat Entitas' },
                { key: 'nib_entitas', type: 'text', label: 'NIB' },
                { key: 'nomor_ijin_entitas', type: 'text', label: 'No Ijin' },
                { key: 'tanggal_ijin_entitas', type: 'date', label: 'Tgl Ijin', width: '105px', minWidth: '105px', maxWidth: '105px' },
                { key: 'kode_negara', type: 'select', label: 'Negara', reff: 'NEGARA', width: '135px', minWidth: '135px', maxWidth: '135px' }
            ],
            dokumen: [
                { key: 'seri', type: 'number', label: 'Seri', decimals: 0, width: '45px', minWidth: '45px', maxWidth: '45px' },
                { key: 'kode_dokumen', type: 'select', label: 'Kode Dokumen', reff: 'DOKUMEN', width: '145px', minWidth: '145px', maxWidth: '145px' },
                { key: 'nomor_dokumen', type: 'text', label: 'Nomor Dokumen' },
                { key: 'tanggal_dokumen', type: 'date', label: 'Tanggal Dokumen', width: '110px', minWidth: '110px', maxWidth: '110px' }
            ],
            bahanbaku: [
                { key: 'seri_barang', type: 'number', label: 'Seri Brg', decimals: 0, width: '50px', minWidth: '50px', maxWidth: '50px' },
                { key: 'seri_bahan_baku', type: 'number', label: 'Seri BB', decimals: 0, width: '50px', minWidth: '50px', maxWidth: '50px' },
                { key: 'kode_asal_bahan_baku', type: 'select', label: 'Asal BB', reff: 'ASAL BAHAN BAKU', width: '110px', minWidth: '110px', maxWidth: '110px' },
                { key: 'hs', type: 'text', label: 'HS Code' },
                { key: 'kode_barang', type: 'text', label: 'Kode Barang' },
                { key: 'uraian', type: 'wrap_text', label: 'Uraian Bahan Baku' },
                { key: 'jumlah_satuan', type: 'number', label: 'Jml Satuan', decimals: 4 },
                { key: 'kode_satuan', type: 'text', label: 'Satuan', reff: 'SATUAN BARANG' },
                { key: 'cif', type: 'number', label: 'Nilai CIF', decimals: 2 },
                { key: 'kode_dokumen_asal', type: 'select', label: 'Dok Asal', reff: 'DOKUMEN', width: '100px', minWidth: '100px', maxWidth: '100px' },
                { key: 'nomor_aju_asal', type: 'text', label: 'No AJU Asal' },
                { key: 'nomor_daftar_asal', type: 'text', label: 'No Daftar Asal' },
                { key: 'tanggal_daftar_asal', type: 'date', label: 'Tgl Daftar Asal', width: '110px', minWidth: '110px', maxWidth: '110px' }
            ],
            barangtarif: [
                { key: 'seri_barang', type: 'number', label: 'Seri Brg', decimals: 0, width: '50px', minWidth: '50px', maxWidth: '50px' },
                { key: 'kode_pungutan', type: 'select', label: 'Jenis Pungutan', reff: 'JENIS PUNGUTAN', width: '120px', minWidth: '120px', maxWidth: '120px' },
                { key: 'kode_tarif', type: 'select', label: 'Jenis Tarif', reff: 'JENIS TARIF', width: '110px', minWidth: '110px', maxWidth: '110px' },
                { key: 'tarif', type: 'number', label: 'Tarif (%)', decimals: 2 },
                { key: 'nilai_bayar', type: 'number', label: 'Nilai Bayar', decimals: 2 },
                { key: 'nilai_fasilitas', type: 'number', label: 'Nilai Fasilitas', decimals: 2 },
                { key: 'kode_fasilitas', type: 'select', label: 'Kd Fasilitas', reff: 'FASILITAS TARIF', width: '120px', minWidth: '120px', maxWidth: '120px' },
                { key: 'jumlah_satuan', type: 'number', label: 'Jml Satuan', decimals: 4 }
            ],
            bahanbakutarif: [
                { key: 'seri_barang', type: 'number', label: 'Seri Brg', decimals: 0, width: '50px', minWidth: '50px', maxWidth: '50px' },
                { key: 'seri_bahan_baku', type: 'number', label: 'Seri BB', decimals: 0, width: '50px', minWidth: '50px', maxWidth: '50px' },
                { key: 'kode_pungutan', type: 'select', label: 'Jenis Pungutan', reff: 'JENIS PUNGUTAN', width: '120px', minWidth: '120px', maxWidth: '120px' },
                { key: 'tarif', type: 'number', label: 'Tarif (%)', decimals: 2 },
                { key: 'nilai_bayar', type: 'number', label: 'Nilai Bayar', decimals: 2 },
                { key: 'nilai_fasilitas', type: 'number', label: 'Nilai Fasilitas', decimals: 2 },
                { key: 'kode_fasilitas', type: 'select', label: 'Kd Fasilitas', reff: 'FASILITAS TARIF', width: '120px', minWidth: '120px', maxWidth: '120px' }
            ],
            kemasan: [
                { key: 'seri', type: 'number', label: 'Seri', decimals: 0, width: '45px', minWidth: '45px', maxWidth: '45px' },
                { key: 'jumlah_kemasan', type: 'number', label: 'Jumlah Kemasan', decimals: 2, width: '110px', minWidth: '110px', maxWidth: '110px' },
                { key: 'kode_kemasan', type: 'select', label: 'Kode Kemasan', reff: 'JENIS KEMASAN', width: '125px', minWidth: '125px', maxWidth: '125px' },
                { key: 'merek', type: 'text', label: 'Merek Kemasan' }
            ],
            pengangkut: [
                { key: 'seri', type: 'number', label: 'Seri', decimals: 0, width: '45px', minWidth: '45px', maxWidth: '45px' },
                { key: 'kode_cara_angkut', type: 'select', label: 'Cara Angkut', reff: 'CARA ANGKUT', width: '110px', minWidth: '110px', maxWidth: '110px' },
                { key: 'nama_pengangkut', type: 'text', label: 'Nama Sarana Pengangkut', minWidth: '180px' },
                { key: 'nomor_pengangkut', type: 'text', label: 'Nomor Pengangkut / Voyage', minWidth: '140px' },
                { key: 'kode_bendera', type: 'select', label: 'Bendera', reff: 'NEGARA', width: '135px', minWidth: '135px', maxWidth: '135px' },
                { key: 'call_sign', type: 'text', label: 'Call Sign', minWidth: '100px' }
            ],
            kontainer: [
                { key: 'seri', type: 'number', label: 'Seri', decimals: 0, width: '45px', minWidth: '45px', maxWidth: '45px' },
                { key: 'nomor_kontainer', type: 'text', label: 'Nomor Kontainer' },
                { key: 'kode_ukuran_kontainer', type: 'select', label: 'Ukuran', reff: 'UKURAN KONTAINER', width: '105px', minWidth: '105px', maxWidth: '105px' },
                { key: 'kode_jenis_kontainer', type: 'select', label: 'Jenis', reff: 'JENIS KONTAINER', width: '115px', minWidth: '115px', maxWidth: '115px' },
                { key: 'kode_tipe_kontainer', type: 'select', label: 'Tipe', reff: 'TIPE KONTAINER', width: '120px', minWidth: '120px', maxWidth: '120px' }
            ],
            pungutan: [
                { key: 'kode_jenis_pungutan', type: 'select', label: 'Jenis Pungutan', reff: 'JENIS PUNGUTAN', width: '240px', minWidth: '220px', maxWidth: '300px' },
                { key: 'kode_fasilitas_tarif', type: 'select', label: 'Fasilitas Tarif', reff: 'FASILITAS TARIF', width: '280px', minWidth: '260px', maxWidth: '360px' },
                { key: 'nilai_pungutan', type: 'number', label: 'Nilai Pungutan', decimals: 2, minWidth: '140px' },
                { key: 'npwp_billing', type: 'text', label: 'NPWP Billing', minWidth: '160px' }
            ],
            bankdevisa: [
                { key: 'seri', type: 'number', label: 'Seri', decimals: 0, width: '45px', minWidth: '45px', maxWidth: '45px' },
                { key: 'kode', type: 'select', label: 'Kode Bank', reff: 'BANK', width: '140px', minWidth: '140px', maxWidth: '140px' },
                { key: 'nama', type: 'text', label: 'Nama Bank Devisa', minWidth: '200px' }
            ],
            jaminan: [
                { key: 'kode_jaminan', type: 'select', label: 'Jenis Jaminan', reff: 'JENIS JAMINAN', width: '130px', minWidth: '130px', maxWidth: '130px' },
                { key: 'nomor_jaminan', type: 'text', label: 'Nomor Jaminan' },
                { key: 'tanggal_jaminan', type: 'date', label: 'Tanggal Jaminan', width: '110px' },
                { key: 'nilai_jaminan', type: 'number', label: 'Nilai Jaminan', decimals: 2 },
                { key: 'penjamin', type: 'text', label: 'Penjamin' },
                { key: 'tanggal_jatuh_tempo', type: 'date', label: 'Jatuh Tempo', width: '110px' },
                { key: 'nomor_bpj', type: 'text', label: 'Nomor BPJ' },
                { key: 'tanggal_bpj', type: 'date', label: 'Tanggal BPJ', width: '110px' }
            ],
            komponenbiaya: [
                { key: 'jenis_nilai', type: 'select', label: 'Jenis Nilai', reff: 'INCOTERM', width: '110px', minWidth: '110px', maxWidth: '110px' },
                { key: 'harga_invoice', type: 'number', label: 'Harga Invoice', decimals: 2, minWidth: '110px' },
                { key: 'pembayaran_tidak_langsung', type: 'number', label: 'Byr Tdk Langsung', decimals: 2, minWidth: '115px' },
                { key: 'diskon', type: 'number', label: 'Diskon', decimals: 2, minWidth: '95px' },
                { key: 'komisi_penjualan', type: 'number', label: 'Komisi Penjualan', decimals: 2, minWidth: '110px' },
                { key: 'biaya_pengemasan', type: 'number', label: 'Biaya Kemas', decimals: 2, minWidth: '105px' },
                { key: 'biaya_pengepakan', type: 'number', label: 'Biaya Pak', decimals: 2, minWidth: '105px' },
                { key: 'assist', type: 'number', label: 'Assist', decimals: 2, minWidth: '95px' },
                { key: 'royalti', type: 'number', label: 'Royalti', decimals: 2, minWidth: '95px' },
                { key: 'proceeds', type: 'number', label: 'Proceeds', decimals: 2, minWidth: '95px' },
                { key: 'biaya_transportasi', type: 'number', label: 'Transportasi / Freight', decimals: 2, minWidth: '120px' },
                { key: 'biaya_pemuatan', type: 'number', label: 'Biaya Pemuatan', decimals: 2, minWidth: '110px' },
                { key: 'asuransi', type: 'number', label: 'Asuransi', decimals: 2, minWidth: '100px' },
                { key: 'garansi', type: 'number', label: 'Garansi', decimals: 2, minWidth: '95px' },
                { key: 'biaya_kepentingan_sendiri', type: 'number', label: 'Kpt. Sendiri', decimals: 2, minWidth: '105px' },
                { key: 'biaya_pasca_impor', type: 'number', label: 'Pasca Impor', decimals: 2, minWidth: '105px' },
                { key: 'biaya_pajak_internal', type: 'number', label: 'Pajak Internal', decimals: 2, minWidth: '105px' },
                { key: 'bunga', type: 'number', label: 'Bunga', decimals: 2, minWidth: '95px' },
                { key: 'deviden', type: 'number', label: 'Deviden', decimals: 2, minWidth: '95px' }
            ],
            barangdokumen: [
                { key: 'seri_barang', type: 'number', label: 'Seri Brg', decimals: 0, width: '55px', minWidth: '55px', maxWidth: '55px' },
                { key: 'seri_dokumen', type: 'number', label: 'Seri Dokumen', decimals: 0, width: '95px', minWidth: '95px', maxWidth: '95px' },
                { key: 'seri_izin', type: 'text', label: 'Seri Fasilitas / Izin', minWidth: '140px' }
            ],
            barangentitas: [
                { key: 'seri_barang', type: 'number', label: 'Seri Brg', decimals: 0, width: '55px', minWidth: '55px', maxWidth: '55px' },
                { key: 'seri_entitas', type: 'number', label: 'Seri Entitas', decimals: 0, width: '95px', minWidth: '95px', maxWidth: '95px' }
            ],
            barangspekkhusus: [
                { key: 'seri_barang', type: 'number', label: 'Seri Brg', decimals: 0, width: '55px', minWidth: '55px', maxWidth: '55px' },
                { key: 'kode', type: 'text', label: 'Kode Spek Khusus', minWidth: '130px' },
                { key: 'uraian', type: 'wrap_text', label: 'Uraian Spesifikasi Khusus' }
            ],
            barangvd: [
                { key: 'seri_barang', type: 'number', label: 'Seri Brg', decimals: 0, width: '55px', minWidth: '55px', maxWidth: '55px' },
                { key: 'kode_vd', type: 'text', label: 'Kode VD', minWidth: '100px' },
                { key: 'nilai_barang', type: 'number', label: 'Nilai Barang', decimals: 2, minWidth: '115px' },
                { key: 'biaya_tambahan', type: 'number', label: 'Biaya Tambahan', decimals: 2, minWidth: '115px' },
                { key: 'biaya_pengurang', type: 'number', label: 'Biaya Pengurang', decimals: 2, minWidth: '115px' },
                { key: 'jatuh_tempo', type: 'date', label: 'Jatuh Tempo', width: '110px' }
            ],
            bahanbakudokumen: [
                { key: 'seri_barang', type: 'number', label: 'Seri Brg', decimals: 0, width: '55px', minWidth: '55px', maxWidth: '55px' },
                { key: 'seri_bahan_baku', type: 'number', label: 'Seri BB', decimals: 0, width: '55px', minWidth: '55px', maxWidth: '55px' },
                { key: 'kode_asal_bahan_baku', type: 'select', label: 'Asal BB', reff: 'ASAL BAHAN BAKU', width: '110px', minWidth: '110px', maxWidth: '110px' },
                { key: 'seri_dokumen', type: 'number', label: 'Seri Dokumen', decimals: 0, width: '90px', minWidth: '90px', maxWidth: '90px' },
                { key: 'seri_izin', type: 'text', label: 'Seri Fasilitas / Izin', minWidth: '120px' }
            ],
            barangbom: [
                { key: 'seri', type: 'number', label: 'Seri', decimals: 0, width: '50px', minWidth: '50px', maxWidth: '50px' },
                { key: 'kode_barang_parent', type: 'text', label: 'Kd Brg Induk', width: '120px', minWidth: '120px' },
                { key: 'kode_barang', type: 'text', label: 'Kd Komponen', width: '120px', minWidth: '120px' },
                { key: 'uraian', type: 'wrap_text', label: 'Uraian Komponen' },
                { key: 'kode_satuan', type: 'text', label: 'Satuan', reff: 'SATUAN BARANG', width: '70px', minWidth: '70px' },
                { key: 'qty', type: 'number', label: 'Qty Butuh', decimals: 4, width: '100px', minWidth: '100px' }
            ],
            tracebb: [
                { key: 'seri_barang', type: 'number', label: 'Seri Brg', decimals: 0, width: '50px', minWidth: '50px', maxWidth: '50px' },
                { key: 'kode_barang', type: 'text', label: 'Kd Barang', width: '110px', minWidth: '110px' },
                { key: 'nama_barang', type: 'wrap_text', label: 'Nama Barang' },
                { key: 'qty_use', type: 'number', label: 'Qty Use', decimals: 4, width: '80px', minWidth: '80px' },
                { key: 'satuan', type: 'text', label: 'Sat', reff: 'SATUAN BARANG', width: '60px', minWidth: '60px' },
                { key: 'jenis_bc', type: 'text', label: 'BC', width: '60px', minWidth: '60px' },
                { key: 'nomor_aju', type: 'text', label: 'No AJU Asal', width: '150px', minWidth: '150px' },
                { key: 'no_daftar', type: 'text', label: 'No Daftar', width: '100px', minWidth: '100px' },
                { key: 'tgl_daftar', type: 'date', label: 'Tgl Daftar', width: '100px', minWidth: '100px' },
                { key: 'hs', type: 'text', label: 'HS Code', width: '90px', minWidth: '90px' }
            ]
        };

        async function init() {
            // Inisialisasi tema
            const savedTheme = localStorage.getItem('ceisa_theme') || 'light';
            setTheme(savedTheme);

            // Inisialisasi datagrid column resizing & sorting
            setupAllGridTables();

            _activePanelEl = document.getElementById('panel-' + activeTab) || document.querySelector('.panel.active');

            // Bind format desimal blur untuk semua field angka di Header
            Object.entries(headerNumberDecimals).forEach(([f, dec]) => {
                const el = document.getElementById('hdr_' + f);
                if (el) {
                    el.type = 'text';
                    el.inputMode = 'decimal';
                    el.classList.add('input-number');
                    el.addEventListener('focus', () => el.select());
                    el.addEventListener('blur', () => {
                        if (el.value !== '') {
                            el.value = formatCeisaNumber(el.value, dec);
                        }
                    });
                }
            });

            const entryNdpbmEl = document.getElementById('entryNdpbmVal');
            if (entryNdpbmEl) {
                entryNdpbmEl.addEventListener('focus', () => entryNdpbmEl.select());
                entryNdpbmEl.addEventListener('blur', () => {
                    if (entryNdpbmEl.value !== '') entryNdpbmEl.value = formatCeisaNumber(entryNdpbmEl.value, 2);
                });
            }

            const params = new URLSearchParams(window.location.search);
            const ajuParam = params.get('nomor_aju');
            const sel = document.getElementById('selectAju');

            if (ajuParam) {
                toggleEntryPanel(false);
                const btnEntry = document.getElementById('btnToggleEntry');
                if (btnEntry) btnEntry.style.display = 'none';
                if (sel) {
                    sel.value = ajuParam;
                    if (sel.value !== ajuParam) {
                        const opt = document.createElement('option');
                        opt.value = ajuParam;
                        opt.textContent = `${ajuParam} (Dipilih)`;
                        sel.prepend(opt);
                        sel.value = ajuParam;
                    }
                }
                await loadAjuData(ajuParam);
                loadErpProfile();
            } else {
                toggleEntryPanel(true);
                const btnEntry = document.getElementById('btnToggleEntry');
                if (btnEntry) btnEntry.style.display = '';
                if (sel) sel.value = '';
                await loadAjuData('');
                loadErpProfile();
            }
            initAiReader();
            initEditorContextMenu();
            syncConfigDropdownState();

            // Inisialisasi draggable & visibilitas floating summary tarif
            const floatWin = document.getElementById('floatingSummaryTarif');
            const floatHdr = document.getElementById('floatingSummaryHeader');
            if (floatWin && floatHdr) {
                makeDraggable(floatWin, floatHdr);
            }
            updateFloatingSummaryTabVisibility(activeTab);
        }

        async function changeAju(aju) {
            if (!aju) {
                const url = new URL(window.location);
                url.searchParams.delete('nomor_aju');
                window.history.replaceState({}, '', url);
                toggleEntryPanel(true);
                const btnEntry = document.getElementById('btnToggleEntry');
                if (btnEntry) btnEntry.style.display = '';
                await loadAjuData('');
                return;
            }
            const url = new URL(window.location);
            url.searchParams.set('nomor_aju', aju);
            window.history.replaceState({}, '', url);
            toggleEntryPanel(false);
            const btnEntry = document.getElementById('btnToggleEntry');
            if (btnEntry) btnEntry.style.display = 'none';
            await loadAjuData(aju);
        }

        async function reloadCurrentAju() {
            const btnReload = document.querySelector('button[onclick="reloadCurrentAju()"]');
            if (btnReload) {
                btnReload.disabled = true;
                btnReload.innerHTML = '<span class="spinner" style="width:12px;height:12px;display:inline-block;"></span>';
            }
            try {
                if (currentAju) await loadAjuData(currentAju);
            } finally {
                if (btnReload) {
                    btnReload.disabled = false;
                    btnReload.innerHTML = '🔄';
                }
            }
        }

        // ========================================================
        // TAB & WORKSPACE LOADER HELPERS (SMOOTH & RESPONSIVE UX)
        // ========================================================
        function getTabTitle(tabName) {
            const titles = {
                'header': 'Header & Identitas',
                'barang': 'Rincian Barang',
                'entitas': 'Entitas Dokumen',
                'dokumen': 'Dokumen Lampiran',
                'bahanbaku': 'Bahan Baku',
                'barangtarif': 'Tarif & Pungutan Barang',
                'bahanbakutarif': 'Tarif Bahan Baku',
                'kemasan': 'Kemasan',
                'pengangkut': 'Sarana Pengangkut',
                'kontainer': 'Kontainer',
                'pungutan': 'Pungutan',
                'bankdevisa': 'Bank Devisa',
                'jaminan': 'Jaminan',
                'komponenbiaya': 'Komponen Biaya',
                'barangdokumen': 'Dokumen Fasilitas Barang',
                'barangentitas': 'Relasi Entitas Barang',
                'barangspekkhusus': 'Spesifikasi Khusus Barang',
                'barangvd': 'Voluntary Declaration (VD)',
                'bahanbakudokumen': 'Dokumen Bahan Baku',
                'kesiapanbarang': 'Kesiapan Barang Ekspor'
            };
            return titles[tabName] || tabName;
        }

        function createTabLoaderEl(tabName) {
            const el = document.createElement('div');
            el.className = 'tab-table-loader';
            el.id = 'loader-' + tabName;
            el.innerHTML = `
                <div class="tab-table-loader-card">
                    <div class="hourglass-wrap">
                        <div class="hourglass-ring"></div>
                        <div class="hourglass-icon">⏳</div>
                    </div>
                    <div class="tab-loader-text">Memuat Data ${escapeHtml(getTabTitle(tabName))}...</div>
                    <div class="tab-loader-subtext">Menyiapkan baris tabel & format kolom</div>
                </div>
            `;
            return el;
        }

        function showTabLoader(tabName, customTitle) {
            const pnl = document.getElementById('panel-' + tabName);
            if (!pnl) return;
            let loader = pnl.querySelector('.tab-table-loader');
            if (!loader) {
                loader = createTabLoaderEl(tabName);
                pnl.prepend(loader);
            }
            const titleEl = loader.querySelector('.tab-loader-text');
            if (titleEl) {
                titleEl.innerText = customTitle || `Memuat Data ${getTabTitle(tabName)}...`;
            }
            loader.classList.add('active');
        }

        function hideTabLoader(tabName) {
            const pnl = document.getElementById('panel-' + tabName);
            if (!pnl) return;
            const loader = pnl.querySelector('.tab-table-loader');
            if (loader) {
                loader.classList.remove('active');
            }
        }

        function showWorkspaceLoader(title, subtitle) {
            const loader = document.getElementById('workspaceAjuLoader');
            if (loader) {
                const tEl = document.getElementById('wsLoaderTitle');
                if (tEl) tEl.innerText = title || 'Memuat Dokumen Pabean...';
                const dEl = document.getElementById('wsLoaderDesc');
                if (dEl) dEl.innerText = subtitle || (currentAju ? `Nomor AJU: ${currentAju}` : 'Memuat data formulir...');
                loader.classList.add('active');
            }
        }

        function hideWorkspaceLoader() {
            const loader = document.getElementById('workspaceAjuLoader');
            if (loader) {
                loader.classList.remove('active');
            }
        }

        async function loadAjuData(aju) {
            currentAju = aju || '';
            const isEdit = !!currentAju;
            toggleEntryPanel(!isEdit);
            const btnEntry = document.getElementById('btnToggleEntry');
            if (btnEntry) btnEntry.style.display = isEdit ? 'none' : '';

            showWorkspaceLoader(
                isEdit ? 'Memuat Dokumen Pabean...' : 'Menyiapkan Editor Dokumen...',
                isEdit ? `Nomor AJU: ${currentAju}` : 'Menghubungkan ke database...'
            );

            const postedBadge = document.getElementById('metaPostedBadge');
            if (postedBadge) {
                postedBadge.innerHTML = '<span class="spinner" style="width:10px;height:10px;display:inline-block;vertical-align:middle;margin-right:4px;"></span> Memuat Data...';
            }

            try {
                const cmpUrl = new URLSearchParams(window.location.search).get('cmp_id') || '';
                const docBcParam = document.getElementById('entryDocBc')?.value || '23';
                const res = await fetch(`editor.php?action=load_all&nomor_aju=${encodeURIComponent(currentAju)}&cmp_id=${encodeURIComponent(cmpUrl)}&kode_dokumen=${encodeURIComponent(docBcParam)}`);
                const json = await res.json();
                if (json.status === 'OK') {
                    currentData = json.tables || {};
                    currentVarRules = json.varheader || { header: {}, barang: {} };
                    currentReffOptions = json.reff_options || {};
                    currentTabRules = json.tab_rules || null;
                    currentTabSource = json.tab_source || 'default';
                    currentColSource = json.col_source || 'varheader';
                    currentActiveCmpId = json.active_cmp_id || cmpUrl || (currentData.header ? currentData.header.cmp_id : '');
                    const kodeDokumen = json.kode_dokumen || (currentData.header ? currentData.header.kode_dokumen : docBcParam);

                    // Sinkronisasi dengan Entry Sidebar
                    const entryAjuEl = document.getElementById('entryAjuDisplay');
                    if (entryAjuEl) entryAjuEl.innerText = aju ? aju : '- Silakan Buat Dokumen -';
                    const entryBcEl = document.getElementById('entryDocBc');
                    if (entryBcEl && kodeDokumen) {
                        let optFound = false;
                        for (let i = 0; i < entryBcEl.options.length; i++) {
                            if (entryBcEl.options[i].value === String(kodeDokumen)) { optFound = true; break; }
                        }
                        if (!optFound) {
                            const opt = document.createElement('option');
                            opt.value = kodeDokumen;
                            opt.textContent = `BC ${kodeDokumen}`;
                            entryBcEl.appendChild(opt);
                        }
                        entryBcEl.value = kodeDokumen;
                    }
                    const entryValutaEl = document.getElementById('entryValuta');
                    if (entryValutaEl && currentData.header?.kode_valuta) entryValutaEl.value = currentData.header.kode_valuta;
                    const entryNdpbmEl = document.getElementById('entryNdpbmVal');
                    if (entryNdpbmEl && currentData.header?.ndpbm) entryNdpbmEl.value = formatCeisaNumber(currentData.header.ndpbm, 2);
                    const entryDateEl = document.getElementById('entryNdpbmDate');
                    if (entryDateEl && currentData.header?.tanggal_pernyataan) entryDateEl.value = currentData.header.tanggal_pernyataan;

                    if (json.decimal_settings) {
                        applyDecimalSettingsToConfigs(json.decimal_settings);
                    }

                    setupDatalists();
                    populateHeaderSelects();
                    populateHeader(currentData.header || {});
                    populateKesiapan(currentData.kesiapanbarang || {});
                    renderBarangTableHeader();
                    populateGrids(currentData);
                    
                    // Status IT Inventory Lock
                    isCurrentDocLocked = !!json.is_it_inventory;
                    const lockBanner = document.getElementById('itInventoryLockBanner');
                    if (lockBanner) lockBanner.style.display = isCurrentDocLocked ? 'flex' : 'none';

                    const btnSave = document.querySelector('button[onclick="saveCurrentTab()"]');
                    if (btnSave) {
                        btnSave.disabled = isCurrentDocLocked;
                        btnSave.style.opacity = isCurrentDocLocked ? '0.4' : '1';
                        btnSave.style.cursor = isCurrentDocLocked ? 'not-allowed' : 'pointer';
                        btnSave.title = isCurrentDocLocked ? 'Dokumen terkunci karena sudah masuk IT Inventory (bc_1ab2)' : 'Simpan Perubahan';
                    }

                    const btnDel = document.getElementById('btnTopDeleteAju');
                    if (btnDel) {
                        btnDel.disabled = isCurrentDocLocked;
                        btnDel.style.opacity = isCurrentDocLocked ? '0.4' : '1';
                        btnDel.style.cursor = isCurrentDocLocked ? 'not-allowed' : 'pointer';
                        btnDel.title = isCurrentDocLocked ? 'Dokumen terkunci karena sudah masuk IT Inventory (bc_1ab2)' : 'Hapus Semua Data Staging Dokumen AJU Ini';
                    }

                    const btnTransfer = document.getElementById('btnTopTransferCeisa') || document.querySelector('button[onclick="transferCeisa()"]');
                    if (btnTransfer) {
                        btnTransfer.disabled = isCurrentDocLocked;
                        btnTransfer.style.opacity = isCurrentDocLocked ? '0.4' : '1';
                        btnTransfer.style.cursor = isCurrentDocLocked ? 'not-allowed' : 'pointer';
                        btnTransfer.title = isCurrentDocLocked ? 'Dokumen terkunci karena sudah masuk IT Inventory (bc_1ab2). Transfer ke CEISA dinonaktifkan.' : 'Transfer ke CEISA';
                    }

                    const btnModalTransfer = document.getElementById('btnModalTransferCeisa');
                    if (btnModalTransfer) {
                        btnModalTransfer.disabled = isCurrentDocLocked;
                        btnModalTransfer.style.opacity = isCurrentDocLocked ? '0.4' : '1';
                        btnModalTransfer.style.cursor = isCurrentDocLocked ? 'not-allowed' : 'pointer';
                        btnModalTransfer.title = isCurrentDocLocked ? 'Dokumen terkunci karena sudah masuk IT Inventory (bc_1ab2). Transfer ke CEISA dinonaktifkan.' : 'Transfer ke CEISA 4.0';
                    }

                    updateMetaBanner(currentData.header || {});

                    // Terapkan visibilitas kolom & sheet per dokumen BC
                    applyColumnVisibility(currentVarRules);
                    applyTabVisibility(kodeDokumen, currentData, currentTabRules);
                    loadErpProfile();

                    // Status filter di banner
                    const metaFilterEl = document.getElementById('metaFilterStatus');
                    if (metaFilterEl) {
                        const tabLabel = currentTabSource === 'company' ? `Khusus [${currentActiveCmpId}]` : 'Global';
                        const colLabel = currentColSource === 'company' ? `Khusus [${currentActiveCmpId}]` : (currentColSource === 'global' ? 'Global' : 'Bawaan');
                        const decLabel = json.decimal_source === 'company' ? `Khusus [${currentActiveCmpId}]` : (json.decimal_source === 'global' ? 'Global' : 'Bawaan');
                        metaFilterEl.innerText = `Saring: Tab (${tabLabel}) • Kolom (${colLabel}) • Desimal (${decLabel})`;
                    }
                } else {
                    showError('Gagal memuat data AJU: ' + (json.message || 'Error'));
                }
            } catch (err) {
                showError('Koneksi gagal: ' + err.message);
            } finally {
                hideWorkspaceLoader();
            }
        }

        function setupDatalists() {
            const container = document.getElementById('datalistsContainer');
            container.innerHTML = '';
            const frag = document.createDocumentFragment();
            for (const [reff, opts] of Object.entries(currentReffOptions)) {
                const dl = document.createElement('datalist');
                dl.id = 'dl_reff_' + reff;
                opts.forEach(o => {
                    const opt = document.createElement('option');
                    opt.value = o.kode;
                    opt.label = `${o.kode} - ${o.teks}`;
                    dl.appendChild(opt);
                });
                frag.appendChild(dl);
            }
            container.appendChild(frag);
        }

        function populateHeaderSelects() {
            // Isi semua elemen select yang memiliki data-reff di Header dan Kesiapan Barang
            document.querySelectorAll('#panel-header select[data-reff], #panel-kesiapanbarang select[data-reff]').forEach(sel => {
                const rf = sel.dataset.reff;
                const opts = currentReffOptions[rf] || [];
                const frag = document.createDocumentFragment();
                const defaultOpt = document.createElement('option');
                defaultOpt.value = '';
                defaultOpt.innerText = '-- Pilih --';
                frag.appendChild(defaultOpt);
                opts.forEach(o => {
                    const opt = document.createElement('option');
                    opt.value = o.kode;
                    opt.innerText = `${o.kode} - ${o.teks}`;
                    frag.appendChild(opt);
                });
                sel.innerHTML = '';
                sel.appendChild(frag);
            });
        }

        function populateHeader(hdr) {
            headerFields.forEach(f => {
                const el = document.getElementById('hdr_' + f);
                if (el) {
                    let v = hdr[f] !== null && hdr[f] !== undefined ? hdr[f] : '';
                    if (f.startsWith('tanggal_') || f.endsWith('_tanggal') || el.classList.contains('hdr-date-input')) {
                        v = formatDateYmd(v);
                    } else if (headerNumberDecimals[f] !== undefined && v !== '') {
                        v = formatCeisaNumber(v, headerNumberDecimals[f]);
                    }
                    el.value = v;
                    if (el.classList.contains('hdr-date-input')) {
                        const grp = el.closest('.input-date-group');
                        if (grp) {
                            const nat = grp.querySelector('.hdr-date-native');
                            if (nat) nat.value = v;
                        }
                    }
                }
            });
        }

        function populateKesiapan(kb) {
            const kbFields = [
                'nama_pic', 'alamat_pic', 'telp_pic', 'lokasi_siap_periksa',
                'waktu_siap_periksa', 'kode_cara_stuffing', 'jumlah_kontainer20',
                'jumlah_kontainer40', 'kode_jenis_barang', 'kode_jenis_gudang', 'kode_jenis_partof', 'tanggal_pkb'
            ];
            kbFields.forEach(f => {
                const el = document.getElementById('kb_' + f);
                if (el) {
                    let v = kb[f] || '';
                    if (f === 'waktu_siap_periksa' || f === 'tanggal_pkb' || el.classList.contains('hdr-date-input')) {
                        v = formatDateYmd(v);
                    }
                    el.value = v;
                    if (el.classList.contains('hdr-date-input')) {
                        const grp = el.closest('.input-date-group');
                        if (grp) {
                            const nat = grp.querySelector('.hdr-date-native');
                            if (nat) nat.value = v;
                        }
                    }
                }
            });
        }

        function renderBarangTableHeader() {
            const theadTr = document.getElementById('thead-row-barang');
            theadTr.innerHTML = '';
            const savedWidths = getSavedColumnWidths('barang');
            gridConfigs.barang.forEach(col => {
                const th = document.createElement('th');
                th.dataset.col = col.key;
                if (col.key === 'seri_barang') {
                    th.style.width = '45px';
                    th.style.minWidth = '45px';
                    th.style.maxWidth = '45px';
                    th.style.textAlign = 'center';
                } else {
                    if (savedWidths && savedWidths[col.key]) {
                        const sw = savedWidths[col.key];
                        th.style.width = sw;
                        th.style.minWidth = sw;
                        th.style.maxWidth = sw;
                    } else if (col.width) {
                        th.style.width = col.width;
                        if (col.minWidth) th.style.minWidth = col.minWidth;
                    } else if (col.minWidth) {
                        th.style.minWidth = col.minWidth;
                    }
                    if (col.type === 'number') {
                        th.classList.add('th-number');
                    }
                }
                th.innerText = col.label;
                const rule = currentVarRules.barang ? currentVarRules.barang[col.key] : null;
                if (rule && rule.visible === 0) {
                    th.classList.add('col-hidden');
                }
                theadTr.appendChild(th);
            });

            // Action delete th
            const thAction = document.createElement('th');
            thAction.className = 'no-sort th-action';
            thAction.style.width = '40px';
            thAction.style.minWidth = '40px';
            thAction.style.maxWidth = '40px';
            thAction.style.textAlign = 'center';
            thAction.innerText = 'Hapus';
            theadTr.appendChild(thAction);

            setupGridTable('barang');
            renderBarangTableFooter();
        }

        function renderBarangTableFooter() {
            const tfootTr = document.getElementById('tfoot-row-barang');
            if (!tfootTr) return;
            tfootTr.innerHTML = '';

            const sumCols = ['jumlah_satuan', 'jumlah_kemasan', 'netto', 'bruto', 'volume', 'cif', 'cif_rupiah', 'fob', 'harga_penyerahan', 'nilai_barang'];

            gridConfigs.barang.forEach(col => {
                const td = document.createElement('td');
                td.dataset.col = col.key;
                if (col.key === 'seri_barang') {
                    td.style.width = '45px';
                    td.style.minWidth = '45px';
                    td.style.maxWidth = '45px';
                    td.style.textAlign = 'center';
                    td.classList.add('tfoot-label');
                    td.innerText = 'TOTAL';
                } else {
                    if (col.width) td.style.width = col.width;
                    if (col.minWidth) td.style.minWidth = col.minWidth;
                    if (col.maxWidth) td.style.maxWidth = col.maxWidth;
                    if (sumCols.includes(col.key)) {
                        td.classList.add('td-number');
                        const dec = typeof col.decimals === 'number' ? col.decimals : 2;
                        td.innerText = formatCeisaNumber(0, dec);
                    } else {
                        td.innerText = '';
                    }
                }
                const rule = currentVarRules.barang ? currentVarRules.barang[col.key] : null;
                if (rule && rule.visible === 0) {
                    td.classList.add('col-hidden');
                }
                tfootTr.appendChild(td);
            });

            // Action delete empty td
            const tdAction = document.createElement('td');
            tdAction.style.width = '40px';
            tdAction.style.minWidth = '40px';
            tdAction.style.maxWidth = '40px';
            tdAction.style.textAlign = 'center';
            tfootTr.appendChild(tdAction);
        }

        function formatSummaryNumber(num, decimals = 2) {
            return formatCeisaNumber(num, decimals);
        }

        function updateTableTotals(tableKey) {
            const table = document.getElementById('table-' + tableKey);
            if (!table) return;
            const tfoot = table.querySelector('tfoot');
            if (!tfoot) return;

            const footerCells = tfoot.querySelectorAll('td[data-col]');
            if (footerCells.length === 0) return;

            const colConfigs = gridConfigs[tableKey] || [];
            const configMap = {};
            colConfigs.forEach(c => { configMap[c.key] = c; });

            // Ambil semua kolom yang memiliki class td-number di footer
            const sumCols = [];
            footerCells.forEach(cell => {
                if (cell.classList.contains('td-number') && cell.dataset.col) {
                    sumCols.push(cell.dataset.col);
                }
            });

            if (sumCols.length === 0) return;

            // Inisialisasi total akumulasi
            const sums = {};
            sumCols.forEach(k => { sums[k] = 0; });

            // Iterasi baris tabel pada tbody
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(tr => {
                if (tr.style.display === 'none') return;
                sumCols.forEach(colKey => {
                    const el = tr.querySelector(`input[data-col="${colKey}"], select[data-col="${colKey}"]`);
                    if (el) {
                        const val = parseInputNumber(el.value);
                        if (!isNaN(val)) {
                            sums[colKey] += val;
                        }
                    }
                });
            });

            // Format dan render nilai ke elemen sel tfoot
            footerCells.forEach(cell => {
                const colKey = cell.dataset.col;
                if (colKey && sumCols.includes(colKey)) {
                    const cConf = configMap[colKey];
                    const decimals = cConf && typeof cConf.decimals === 'number' ? cConf.decimals : 2;
                    const total = sums[colKey];
                    cell.innerText = formatSummaryNumber(total, decimals);
                    cell.title = `Total: ${formatCeisaNumber(total, decimals)} (${rows.length} baris)`;
                }
            });
        }

        // Status render per tabel untuk optimalisasi performa (lazy rendering)
        let _tableRendered = {};

        function renderTableGrid(t) {
            const rows = currentData[t] || [];
            const countBadge = document.getElementById('count-' + t);
            if (countBadge) countBadge.innerText = rows.length;

            // Sinkronisasi class th-number untuk kolom angka di semua tabel & restore saved column widths
            const theadThs = document.querySelectorAll(`#table-${t} thead tr th`);
            const conf = gridConfigs[t];
            const savedWidths = getSavedColumnWidths(t);
            if (theadThs.length > 0 && conf) {
                conf.forEach((col, idx) => {
                    if (theadThs[idx]) {
                        theadThs[idx].dataset.col = col.key;
                        if (savedWidths && savedWidths[col.key]) {
                            const sw = savedWidths[col.key];
                            theadThs[idx].style.width = sw;
                            theadThs[idx].style.minWidth = sw;
                            theadThs[idx].style.maxWidth = sw;
                        }
                        if (col.type === 'number' && col.key !== 'seri' && col.key !== 'seri_barang' && col.key !== 'seri_bahan_baku' && col.key !== 'seri_dokumen' && col.key !== 'seri_entitas') {
                            theadThs[idx].classList.add('th-number');
                        }
                    }
                });
            }
            setupGridTable(t);

            const tbody = document.querySelector(`#table-${t} tbody`);
            if (!tbody) return;
            tbody.innerHTML = '';

            // Gunakan DocumentFragment agar insert dilakukan satu kali ke DOM (menghindari reflow berulang)
            const frag = document.createDocumentFragment();
            rows.forEach((r, idx) => {
                const tr = createGridRow(t, r, idx);
                frag.appendChild(tr);
            });
            tbody.appendChild(frag);

            // Jika tabel barang, sinkronkan visibilitas kolom varRules
            if (t === 'barang' && currentVarRules && currentVarRules.barang) {
                document.querySelectorAll('#table-barang th[data-col], #table-barang td[data-col]').forEach(el => {
                    const col = el.dataset.col;
                    const rule = currentVarRules.barang[col];
                    if (rule && rule.visible === 0) {
                        el.classList.add('col-hidden');
                    } else {
                        el.classList.remove('col-hidden');
                    }
                });
            }

            // Hitung total footer untuk tabel ini
            updateTableTotals(t);

            // Jika tabel barang, sinkronkan pemilihan baris aktif untuk BOM & Trace (1:1 PowerBuilder)
            if (t === 'barang') {
                const trs = tbody.querySelectorAll('tr');
                let targetTr = null;
                let targetData = null;
                let targetIdx = 0;

                if (activeBarangSeri !== null) {
                    trs.forEach((tr, idx) => {
                        if (String(tr.dataset.seri) === String(activeBarangSeri)) {
                            targetTr = tr;
                            targetData = rows[idx];
                            targetIdx = idx;
                        }
                    });
                }
                if (!targetTr && trs.length > 0) {
                    targetTr = trs[0];
                    targetData = rows[0];
                    targetIdx = 0;
                }

                if (targetTr && targetData) {
                    selectBarangRow(targetTr, targetData, targetIdx);
                } else {
                    activeBarangSeri = null;
                    activeBarangKode = '';
                    activeBarangUraian = '';
                    activeBarangTr = null;
                    const badge = document.getElementById('badgeActiveBarangBom');
                    if (badge) badge.style.display = 'none';
                    renderBomForActiveBarang();
                }
            }
        }

        function populateTable(tableKey, rows) {
            if (rows) currentData[tableKey] = rows;
            renderTableGrid(tableKey);
            _tableRendered[tableKey] = true;
            const countBadge = document.getElementById('count-' + tableKey);
            if (countBadge && rows) countBadge.innerText = rows.length;
        }

        function populateGrids(tables) {
            // Update counter badges di seluruh tab secara instan (0ms)
            Object.keys(gridConfigs).forEach(t => {
                const rows = tables[t] || [];
                const countBadge = document.getElementById('count-' + t);
                if (countBadge) countBadge.innerText = rows.length;
            });

            // Reset status render tabel & flag resized
            _tableRendered = {};
            document.querySelectorAll('.panel').forEach(p => { delete p.dataset.resized; });
            _recapTarifDirty = true;

            // Render hanya tab yang sedang aktif jika tab tersebut adalah tabel datagrid
            if (activeTab && gridConfigs[activeTab]) {
                renderTableGrid(activeTab);
                _tableRendered[activeTab] = true;
            }

            // Pastikan select referensi & textarea di panel aktif ter-resize rapi
            setTimeout(() => {
                const activePnl = document.querySelector('.panel.active');
                if (activePnl) {
                    activePnl.querySelectorAll('table.datagrid td select.cell-select').forEach(autoSizeSelect);
                    autoResizeAllTextareas(activePnl);
                    setupAllGridTables();
                    activePnl.dataset.resized = '1';
                }
            }, 50);
        }

        function createGridRow(tableKey, data, idx) {
            const tr = document.createElement('tr');
            const conf = gridConfigs[tableKey];
            if (!conf) return tr;

            if (tableKey === 'barang') {
                tr.dataset.seri = data.seri_barang || (idx + 1);
                tr.dataset.kode = data.kode_barang || '';
                tr.addEventListener('click', function(e) {
                    if (e.target && (e.target.closest('.btn-danger') || e.target.closest('button'))) return;
                    selectBarangRow(this, data, idx);
                });
            } else if (tableKey === 'barangbom') {
                tr.dataset.id = data.id || ('bom_' + Math.random().toString(36).substr(2, 9));
                tr.dataset.seri = data.seri || (activeBarangSeri || 1);
                tr.dataset.kode = data.kode_barang || '';
                tr.dataset.kodeParent = data.kode_barang_parent || activeBarangKode;
                tr.addEventListener('click', function(e) {
                    if (e.target && (e.target.closest('.btn-danger') || e.target.closest('button'))) return;
                    selectBomRow(this, data, idx);
                });
            } else if (tableKey === 'tracebb') {
                tr.dataset.id = data.id || ('tr_' + Math.random().toString(36).substr(2, 9));
                tr.dataset.idBom = data.id_bom || (activeBomId || '');
                tr.dataset.seriBarang = data.seri_barang || data.seri || (activeBarangSeri || 1);
                tr.dataset.kodeBarang = data.kode_barang || data.kode_brg || activeBomKode;
            } else if (tableKey === 'entitas') {
                tr.dataset.seri = data.seri || (idx + 1);
                tr.title = 'Dobel-klik baris ini untuk mencari entitas dari history transaksi sebelumnya';
                tr.style.cursor = 'pointer';
                tr.addEventListener('dblclick', function(e) {
                    if (e.target && (e.target.closest('.btn-danger') || e.target.closest('button'))) return;
                    openLookupEntitasModal(this);
                });
            }

            conf.forEach(col => {
                const td = document.createElement('td');
                td.dataset.col = col.key;
                const rawVal = data[col.key] !== null && data[col.key] !== undefined ? data[col.key] : '';

                if (col.type === 'select') {
                    // Dropdown referensi (e.g. kode_entitas, kode_dokumen, kode_cara_angkut, kode_bendera, kode_jenis_identitas)
                    const sel = document.createElement('select');
                    sel.className = 'cell-select';
                    sel.dataset.col = col.key;

                    const reffName = col.reff;
                    const opts = currentReffOptions[reffName] || [];

                    const optEmpty = document.createElement('option');
                    optEmpty.value = '';
                    optEmpty.innerText = '';
                    sel.appendChild(optEmpty);

                    let valFound = false;
                    const cleanCode = String(rawVal).trim();

                    opts.forEach(o => {
                        const opt = document.createElement('option');
                        opt.value = o.kode;
                        opt.innerText = `${o.kode} | ${o.teks}`;
                        if (cleanCode && String(o.kode).trim() === cleanCode) {
                            opt.selected = true;
                            valFound = true;
                        }
                        sel.appendChild(opt);
                    });

                    // Jika rawVal dari DB ada tetapi belum ada di daftar options master
                    if (cleanCode && !valFound) {
                        const optExtra = document.createElement('option');
                        optExtra.value = cleanCode;
                        optExtra.innerText = `${cleanCode} | (Kode ${cleanCode})`;
                        optExtra.selected = true;
                        sel.appendChild(optExtra);
                    }

                    const updateSelectEmptyState = () => {
                        if (!sel.value || sel.value.trim() === '') {
                            sel.classList.add('is-empty');
                        } else {
                            sel.classList.remove('is-empty');
                        }
                        autoSizeSelect(sel);
                    };
                    // Khusus bankdevisa: saat memilih Kode Bank, otomatis isikan Nama Bank Devisa
                    if (tableKey === 'bankdevisa' && col.key === 'kode') {
                        sel.addEventListener('change', () => {
                            const selectedCode = sel.value;
                            const bankObj = opts.find(o => String(o.kode).trim() === String(selectedCode).trim());
                            const namaInp = tr.querySelector('input[data-col="nama"]');
                            if (namaInp && bankObj) {
                                namaInp.value = bankObj.teks;
                                autoSizeCellInput(namaInp, 'Nama Bank Devisa', false);
                            }
                        });
                        if (cleanCode && (!data.nama || data.nama.trim() === '')) {
                            const bankObj = opts.find(o => String(o.kode).trim() === cleanCode);
                            if (bankObj) {
                                setTimeout(() => {
                                    const namaInp = tr.querySelector('input[data-col="nama"]');
                                    if (namaInp && !namaInp.value) {
                                        namaInp.value = bankObj.teks;
                                        autoSizeCellInput(namaInp, 'Nama Bank Devisa', false);
                                    }
                                }, 50);
                            }
                        }
                    }
                    sel.addEventListener('change', updateSelectEmptyState);
                    updateSelectEmptyState();

                    td.appendChild(sel);

                } else if (col.type === 'wrap_text') {
                    // Auto-wrapping textarea (wrap teks ke bawah dan auto-resize tinggi baris)
                    td.classList.add('td-wrap');
                    const ta = document.createElement('textarea');
                    ta.className = 'cell-wrap';
                    ta.dataset.col = col.key;
                    ta.value = rawVal;
                    ta.rows = 1;
                    ta.maxLength = 250;

                    const resizeTa = () => {
                        autoResizeTextarea(ta);
                    };

                    ta.addEventListener('input', resizeTa);
                    ta.addEventListener('focus', resizeTa);
                    setTimeout(resizeTa, 10);

                    td.appendChild(ta);

                    if (tableKey === 'entitas' && col.key === 'nama_entitas') {
                        ta.placeholder = '(Dobel-klik cari history...)';
                        const btnLookup = document.createElement('button');
                        btnLookup.type = 'button';
                        btnLookup.className = 'btn btn-secondary btn-xs';
                        btnLookup.style.position = 'absolute';
                        btnLookup.style.right = '2px';
                        btnLookup.style.top = '2px';
                        btnLookup.style.padding = '1px 4px';
                        btnLookup.style.fontSize = '10px';
                        btnLookup.style.opacity = '0.7';
                        btnLookup.innerText = '🔍';
                        btnLookup.title = 'Cari data entitas dari history transaksi';
                        btnLookup.onclick = (e) => {
                            e.stopPropagation();
                            openLookupEntitasModal(tr);
                        };
                        td.style.position = 'relative';
                        td.appendChild(btnLookup);
                    }

                } else if (col.type === 'date') {
                    // Input tanggal bersih yyyy-mm-dd (jika kosong, tampil blank tanpa placeholder dd/mm/yyyy)
                    td.classList.add('td-date');
                    const wrap = document.createElement('div');
                    wrap.className = 'date-cell-wrap';

                    const cleanDate = formatDateYmd(rawVal);

                    const textInp = document.createElement('input');
                    textInp.type = 'text';
                    textInp.className = 'cell-date-input';
                    textInp.dataset.col = col.key;
                    textInp.placeholder = '';
                    textInp.maxLength = 10;
                    textInp.value = cleanDate;
                    textInp.title = 'Format: yyyy-mm-dd';

                    const nativeInp = document.createElement('input');
                    nativeInp.type = 'date';
                    nativeInp.className = 'cell-date-native';
                    nativeInp.tabIndex = -1;
                    nativeInp.value = cleanDate;

                    const btnTrigger = document.createElement('button');
                    btnTrigger.type = 'button';
                    btnTrigger.className = 'btn-date-trigger';
                    btnTrigger.innerText = '📅';
                    btnTrigger.title = 'Buka Kalender';

                    btnTrigger.onclick = function() {
                        try {
                            if (typeof nativeInp.showPicker === 'function') {
                                nativeInp.showPicker();
                            } else {
                                nativeInp.focus();
                            }
                        } catch(e) {
                            nativeInp.focus();
                        }
                    };

                    nativeInp.onchange = function() {
                        const ymd = formatDateYmd(this.value);
                        textInp.value = ymd;
                        textInp.dispatchEvent(new Event('change'));
                    };

                    textInp.onchange = function() {
                        const formatted = formatDateYmd(this.value);
                        this.value = formatted;
                        nativeInp.value = formatted;
                    };

                    wrap.appendChild(textInp);
                    wrap.appendChild(nativeInp);
                    wrap.appendChild(btnTrigger);
                    td.appendChild(wrap);

                } else {
                    // Standar input dengan autowidth max 100 karakter
                    const inp = document.createElement('input');
                    const isNum = col.type === 'number';
                    const isSeri = col.key === 'seri' || col.key === 'seri_barang' || col.key === 'seri_bahan_baku' || col.key === 'seri_dokumen' || col.key === 'seri_entitas';
                    inp.type = (isNum && !isSeri) ? 'text' : (isNum ? 'number' : (col.type || 'text'));
                    if (isNum && !isSeri) {
                        inp.inputMode = 'decimal';
                    }
                    inp.className = 'cell-input' + (isNum && !isSeri ? ' cell-number' : '');
                    inp.dataset.col = col.key;
                    
                    if (isNum && !isSeri) {
                        td.classList.add('td-number');
                        inp.value = formatCeisaNumber(rawVal, col.decimals);

                        inp.addEventListener('focus', () => {
                            inp.select();
                        });

                        inp.addEventListener('blur', () => {
                            if (inp.value !== '') {
                                inp.value = formatCeisaNumber(inp.value, col.decimals);
                            }
                            autoSizeCellInput(inp, col.label, true);
                            updateTableTotals(tableKey);
                        });
                    } else if (isSeri) {
                        td.classList.add('td-seri');
                        if (col.key === 'seri_bahan_baku') {
                            td.style.width = '50px';
                            td.style.minWidth = '50px';
                            td.style.maxWidth = '50px';
                        } else if (col.key === 'seri_dokumen' || col.key === 'seri_entitas') {
                            td.style.width = '90px';
                            td.style.minWidth = '90px';
                            td.style.maxWidth = '90px';
                        } else {
                            td.style.width = '45px';
                            td.style.minWidth = '45px';
                            td.style.maxWidth = '45px';
                        }
                        td.style.textAlign = 'center';
                        inp.value = rawVal;
                        inp.style.textAlign = 'center';
                    } else {
                        inp.value = rawVal;
                    }

                    // Terapkan autowidth berdasarkan panjang karakter (max 100)
                    autoSizeCellInput(inp, col.label, isNum && !isSeri);
                    inp.addEventListener('input', () => {
                        autoSizeCellInput(inp, col.label, isNum && !isSeri);
                        if (isNum && !isSeri) {
                            updateTableTotals(tableKey);
                        }
                    });

                    // Datalist jika ada referensi untuk kolom barang ini
                    if (col.reff && currentReffOptions[col.reff]) {
                        inp.setAttribute('list', 'dl_reff_' + col.reff);
                        inp.title = 'Pilih dari ' + col.reff;
                    }

                    // Terapkan visibility class pada td
                    if (tableKey === 'barang' && currentVarRules.barang && currentVarRules.barang[col.key]) {
                        if (currentVarRules.barang[col.key].visible === 0) {
                            td.classList.add('col-hidden');
                        }
                    }

                    td.appendChild(inp);
                }

                tr.appendChild(td);
            });

            // Action delete button
            const tdAction = document.createElement('td');
            tdAction.style.textAlign = 'center';
            tdAction.style.width = '40px';
            const btnDel = document.createElement('button');
            btnDel.className = 'btn btn-danger btn-sm';
            btnDel.style.padding = '2px 6px';
            btnDel.innerText = '✕';
            btnDel.title = 'Hapus Baris';
            btnDel.onclick = function(e) {
                if (e) e.stopPropagation();
                if (tableKey === 'barangbom') {
                    const rowId = tr.dataset.id;
                    if (rowId && currentData.barangbom) {
                        currentData.barangbom = currentData.barangbom.filter(b => b.id !== rowId);
                    }
                    if (rowId && currentData.tracebb) {
                        currentData.tracebb = currentData.tracebb.filter(t => t.id_bom !== rowId);
                    }
                    tr.remove();
                    if (tr === activeBomTr) {
                        activeBomTr = null;
                        activeBomId = null;
                        activeBomKode = '';
                        activeBomUraian = '';
                        clearTraceTable('Komponen BOM telah dihapus.');
                        const nextTr = document.querySelector('#table-barangbom tbody tr');
                        if (nextTr) {
                            nextTr.click();
                        }
                    }
                } else if (tableKey === 'tracebb') {
                    const rowId = tr.dataset.id;
                    if (rowId && currentData.tracebb) {
                        currentData.tracebb = currentData.tracebb.filter(t => t.id !== rowId);
                    }
                    tr.remove();
                } else if (tableKey === 'barang') {
                    const seri = tr.dataset.seri;
                    if (seri && currentData.barangbom) {
                        currentData.barangbom = currentData.barangbom.filter(b => String(b.seri) !== String(seri));
                    }
                    if (seri && currentData.tracebb) {
                        currentData.tracebb = currentData.tracebb.filter(t => String(t.seri_barang || t.seri) !== String(seri));
                    }
                    tr.remove();
                    if (tr === activeBarangTr) {
                        activeBarangTr = null;
                        activeBarangSeri = null;
                        activeBarangKode = '';
                        activeBarangUraian = '';
                        const nextTr = document.querySelector('#table-barang tbody tr');
                        if (nextTr) {
                            nextTr.click();
                        } else {
                            renderBomForActiveBarang();
                        }
                    }
                } else {
                    tr.remove();
                }
                updateTableTotals(tableKey);
            };
            tdAction.appendChild(btnDel);
            tr.appendChild(tdAction);

            return tr;
        }

        function addRow(tableKey) {
            const tbody = document.querySelector(`#table-${tableKey} tbody`);
            if (!tbody) return;
            const newObj = {};
            const conf = gridConfigs[tableKey];
            const currentCount = tbody.querySelectorAll('tr').length;
            conf.forEach(c => {
                if (c.key === 'seri' || c.key === 'seri_barang' || c.key === 'seri_bahan_baku') {
                    newObj[c.key] = currentCount + 1;
                } else if (c.key === 'seri_dokumen' || c.key === 'seri_entitas') {
                    newObj[c.key] = currentCount + 1;
                } else {
                    newObj[c.key] = '';
                }
            });

            if (tableKey === 'barangbom') {
                if (activeBarangSeri === null) {
                    Swal.fire('Perhatian', 'Pilih baris barang pada tabel Rincian Barang terlebih dahulu.', 'warning');
                    return;
                }
                newObj['seri'] = activeBarangSeri;
                newObj['kode_barang_parent'] = activeBarangKode || '';
                newObj['id'] = 'bom_' + Math.random().toString(36).substr(2, 9) + Date.now().toString(36);
                newObj['kode_satuan'] = 'PCE';
                newObj['qty'] = 1.0000;
            } else if (tableKey === 'tracebb') {
                if (!activeBomId && !activeBomKode) {
                    Swal.fire('Perhatian', 'Pilih salah satu komponen pada tabel BOM terlebih dahulu.', 'warning');
                    return;
                }
                newObj['seri_barang'] = activeBarangSeri || 1;
                newObj['kode_barang'] = activeBomKode || '';
                newObj['nama_barang'] = activeBomUraian || '';
                newObj['id_bom'] = activeBomId || '';
                newObj['id'] = 'tr_' + Math.random().toString(36).substr(2, 9) + Date.now().toString(36);
                newObj['satuan'] = 'PCE';
                newObj['qty_use'] = 1.0000;
            }

            const tr = createGridRow(tableKey, newObj, currentCount);
            tbody.appendChild(tr);
            tr.scrollIntoView({ behavior: 'smooth' });
            requestAnimationFrame(() => {
                autoResizeAllTextareas(tr);
            });
            updateTableTotals(tableKey);

            if (tableKey === 'barangbom') {
                selectBomRow(tr, newObj, currentCount);
            }
        }

        // Track panel aktif & urutan tab switch untuk mencegah race condition
        let _activePanelEl = null;
        let _tabSwitchSeq = 0;
        let _recapTarifDirty = true; // flag: perlu reload recap tarif

        function switchTab(tabName) {
            activeTab = tabName;
            const seq = ++_tabSwitchSeq;

            // 1. INSTAN: Swap tab button active (0ms respon visual langsung)
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            const activeBtn = document.querySelector(`.tab-btn[data-tab="${tabName}"]`);
            if (activeBtn) activeBtn.classList.add('active');

            // 2. INSTAN: Berpindah dulu ke tab baru (tampilkan panel target, sembunyikan panel lain)
            document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
            const activePnl = document.getElementById('panel-' + tabName);
            if (!activePnl) {
                _activePanelEl = null;
                return;
            }
            activePnl.classList.add('active');
            _activePanelEl = activePnl;

            // Update status visibilitas floating summary tarif
            updateFloatingSummaryTabVisibility(tabName);

            // 3. Tentukan apakah tab ini butuh render data atau reload rekap tarif
            const isDatagrid = !!gridConfigs[tabName];
            const needRender = isDatagrid && !_tableRendered[tabName];
            const needRecap = (tabName === 'barangtarif' || tabName === 'bahanbakutarif') && _recapTarifDirty;

            // Tampilkan loader hourglass di tengah tabel jika data sedang dimuat/dirender
            if (needRender || needRecap) {
                showTabLoader(tabName, `Memuat Data ${getTabTitle(tabName)}...`);
            }

            // 4. DEFER eksekusi perenderan data ke paint frame berikutnya:
            // Browser mengecat tab baru & loader TERLEBIH DAHULU sehingga user langsung melihat perpindahan tab tanpa lag/hang!
            requestAnimationFrame(() => {
                setTimeout(async () => {
                    // Batalkan jika pengguna sudah mengklik tab lain dengan lebih cepat
                    if (seq !== _tabSwitchSeq) return;

                    try {
                        // Render tabel jika belum pernah dirender untuk AJU ini
                        if (needRender) {
                            renderTableGrid(tabName);
                            _tableRendered[tabName] = true;
                        }

                        // Auto-resize textarea jika panel ini belum pernah di-resize
                        if (!activePnl.dataset.resized) {
                            autoResizeAllTextareas(activePnl);
                            activePnl.dataset.resized = '1';
                        }

                        // Auto-size dropdown referensi & init grid column resizer/sorter
                        activePnl.querySelectorAll('table.datagrid td select.cell-select').forEach(autoSizeSelect);
                        setupGridTable(tabName);

                        // Reload rekap tarif jika dirty
                        if (needRecap) {
                            await loadRecapTarif();
                            _recapTarifDirty = false;
                        }
                    } catch (err) {
                        console.error('Error saat render tab ' + tabName + ':', err);
                    } finally {
                        if (seq === _tabSwitchSeq) {
                            hideTabLoader(tabName);
                        }
                    }
                }, 20);
            });
        }

        // Tandai recap tarif perlu reload saat ada simpan/hitung
        function markRecapTarifDirty() { _recapTarifDirty = true; }

        window.addEventListener('resize', () => {
            if (window._taResizeTimer) clearTimeout(window._taResizeTimer);
            window._taResizeTimer = setTimeout(() => {
                const activePnl = document.querySelector('.panel.active');
                if (activePnl) autoResizeAllTextareas(activePnl);
            }, 100);
        });

        function updateMetaBanner(hdr) {
            document.getElementById('metaAju').innerText = hdr.nomor_aju || '-';
            document.getElementById('metaBc').innerText = 'BC ' + (hdr.kode_dokumen || '-');
            document.getElementById('metaCmp').innerText = hdr.cmp_id || '-';
            document.getElementById('metaTgl').innerText = formatDateYmd(hdr.tanggal_pernyataan) || '-';

            const sel = document.getElementById('selectAju');
            const opt = sel ? sel.options[sel.selectedIndex] : null;
            const isPosted = opt ? opt.text.includes('Posted') : false;
            const badge = document.getElementById('metaPostedBadge');
            if (badge) {
                if (!hdr.nomor_aju) {
                    badge.innerText = '✨ FORM KOSONG (BARU)';
                    badge.style.background = '#e2e8f0';
                    badge.style.color = '#475569';
                } else if (isCurrentDocLocked) {
                    badge.innerText = '🟢 IT INVENTORY (TERKUNCI)';
                    badge.style.background = '#52c41a';
                    badge.style.color = '#ffffff';
                } else if (isPosted) {
                    badge.innerText = '🚀 TERKIRIM (H2H)';
                    badge.style.background = '#1890ff';
                    badge.style.color = '#ffffff';
                } else {
                    badge.innerText = 'BELUM KIRIM';
                    badge.style.background = 'var(--border)';
                    badge.style.color = 'var(--text-muted)';
                }
            }
        }

        // -------------------------------------------------------------
        // VISIBILITAS KOLOM (bc_ceisa_varheader) & TAB DOKUMEN BC
        // -------------------------------------------------------------
        function applyColumnVisibility(varRules) {
            if (!varRules || !varRules.header) return;

            // Saring kolom di Header
            document.querySelectorAll('#panel-header .field-group[data-col]').forEach(fg => {
                const col = fg.dataset.col;
                const rule = varRules.header[col];
                if (rule && rule.visible === 0) {
                    fg.classList.add('col-hidden');
                } else {
                    fg.classList.remove('col-hidden');
                }
            });

            // Saring Card (.form-section): Jika seluruh field di dalam Card disembunyikan (col-hidden), sembunyikan Card-nya!
            document.querySelectorAll('#panel-header .form-section').forEach(card => {
                const fields = card.querySelectorAll('.field-group[data-col]');
                if (fields.length > 0) {
                    const visibleFields = card.querySelectorAll('.field-group[data-col]:not(.col-hidden)');
                    if (visibleFields.length === 0) {
                        card.classList.add('card-hidden');
                    } else {
                        card.classList.remove('card-hidden');
                    }
                } else {
                    card.classList.remove('card-hidden');
                }
            });

            // Sembunyikan form-col jika seluruh card di dalamnya tersembunyi
            document.querySelectorAll('#panel-header .form-col').forEach(col => {
                const visibleCards = col.querySelectorAll('.form-section:not(.card-hidden)');
                if (visibleCards.length === 0) {
                    col.classList.add('col-hidden');
                } else {
                    col.classList.remove('col-hidden');
                }
            });

            // Saring kolom di Tabel Barang
            document.querySelectorAll('#table-barang th[data-col], #table-barang td[data-col]').forEach(el => {
                const col = el.dataset.col;
                const rule = varRules.barang ? varRules.barang[col] : null;
                if (rule && rule.visible === 0) {
                    el.classList.add('col-hidden');
                } else {
                    el.classList.remove('col-hidden');
                }
            });
            updateTableTotals('barang');
        }

        function applyTabVisibility(kodeDokumen, tables, customAllowedTabs) {
            const bc = String(kodeDokumen || '').replace(/[^0-9]/g, '');

            // Aturan visibilitas tab berdasarkan spesifikasi dokumen pabean
            const tabRules = {
                '20':  ['pengangkut', 'kemasan', 'kontainer', 'pungutan', 'barangtarif', 'komponenbiaya', 'bankdevisa', 'barangdokumen', 'barangvd', 'barangspekkhusus', 'barangentitas'],
                '23':  ['pungutan', 'barangtarif', 'kontainer', 'pengangkut', 'kemasan', 'komponenbiaya', 'barangdokumen', 'barangvd', 'barangspekkhusus', 'barangentitas'],
                '25':  ['pungutan', 'barangtarif', 'bahanbaku', 'bahanbakutarif', 'bahanbakudokumen', 'pengangkut', 'kemasan', 'komponenbiaya', 'barangdokumen'],
                '27':  ['pengangkut', 'kemasan', 'bahanbaku', 'bahanbakutarif', 'bahanbakudokumen', 'barangdokumen'],
                '30':  ['kesiapanbarang', 'bankdevisa', 'kontainer', 'pengangkut', 'kemasan', 'barangdokumen', 'barangspekkhusus'],
                '33':  ['bankdevisa', 'kontainer', 'pengangkut', 'kemasan', 'barangtarif', 'barangdokumen'],
                '40':  ['pungutan', 'barangtarif', 'pengangkut', 'kemasan', 'barangdokumen'],
                '41':  ['pengangkut', 'kemasan', 'bahanbaku', 'bahanbakudokumen', 'barangdokumen'],
                '16':  ['pengangkut', 'kemasan', 'kontainer', 'pungutan', 'barangtarif', 'barangdokumen'],
                '28':  ['pengangkut', 'kemasan', 'kontainer', 'barangtarif', 'barangdokumen'],
                '331': ['pengangkut', 'kemasan', 'kontainer', 'barangtarif', 'barangdokumen'],
                'p3bet': ['pengangkut', 'kemasan', 'kontainer', 'barangtarif', 'barangdokumen'],
                '261': ['pengangkut', 'kemasan', 'bahanbaku', 'bahanbakutarif', 'bahanbakudokumen', 'pungutan', 'jaminan', 'barangdokumen'],
                '262': ['pengangkut', 'kemasan', 'bahanbaku', 'bahanbakutarif', 'bahanbakudokumen', 'pungutan', 'jaminan', 'barangdokumen'],
                '611': ['pengangkut', 'kemasan', 'kontainer', 'pungutan', 'barangtarif', 'barangdokumen', 'bankdevisa'],
                '613': ['pengangkut', 'kemasan', 'kontainer', 'pungutan', 'barangtarif', 'bankdevisa']
            };

            const baseAlwaysVisible = ['header', 'barang', 'entitas', 'dokumen'];
            const allowedTabs = new Set(baseAlwaysVisible);

            if (customAllowedTabs !== null && customAllowedTabs !== undefined && Array.isArray(customAllowedTabs)) {
                customAllowedTabs.forEach(t => allowedTabs.add(t));
            } else if (tabRules[bc]) {
                tabRules[bc].forEach(t => allowedTabs.add(t));
            } else {
                // Dokumen BC lain: izinkan semua tab umum
                ['pengangkut', 'kemasan', 'kontainer', 'pungutan', 'barangtarif', 'bahanbaku', 'bahanbakutarif', 'jaminan', 'bankdevisa', 'komponenbiaya', 'barangdokumen', 'barangentitas', 'barangspekkhusus', 'barangvd', 'bahanbakudokumen'].forEach(t => allowedTabs.add(t));
            }

            // Pertahankan tab tetap terlihat jika tabel tersebut memiliki data (sehingga tidak ada data hilang)
            if (tables) {
                Object.keys(tables).forEach(t => {
                    if (Array.isArray(tables[t]) && tables[t].length > 0) {
                        allowedTabs.add(t);
                    } else if (t === 'kesiapanbarang' && tables[t] && Object.keys(tables[t]).length > 0) {
                        allowedTabs.add(t);
                    }
                });
            }

            document.querySelectorAll('.tab-btn[data-tab]').forEach(btn => {
                const tabKey = btn.dataset.tab;
                if (allowedTabs.has(tabKey)) {
                    btn.classList.remove('tab-filtered-hidden');
                } else {
                    btn.classList.add('tab-filtered-hidden');
                }
            });

            // Jika tab aktif saat ini tersembunyi karena filter, beralih ke 'header'
            if (!allowedTabs.has(activeTab)) {
                switchTab('header');
            }
        }

        // -------------------------------------------------------------
        // TOGGLE SARING KOLOM & TAB + DROPDOWN MENU KONFIGURASI
        // -------------------------------------------------------------
        function syncConfigDropdownState() {
            const body = document.body;
            const isColsActive = body.classList.contains('filter-cols-active');
            const isTabsActive = body.classList.contains('filter-tabs-active');

            const titleCols = document.getElementById('dropTitleCols');
            const badgeCols = document.getElementById('dropBadgeCols');
            if (titleCols) titleCols.innerText = isColsActive ? 'Saring Kolom: Aktif' : 'Saring Kolom: Nonaktif (Semua Tampil)';
            if (badgeCols) {
                badgeCols.innerText = isColsActive ? 'ON' : 'OFF';
                badgeCols.className = 'config-dropdown-badge ' + (isColsActive ? 'config-badge-on' : 'config-badge-off');
            }

            const titleTabs = document.getElementById('dropTitleTabs');
            const badgeTabs = document.getElementById('dropBadgeTabs');
            if (titleTabs) titleTabs.innerText = isTabsActive ? 'Saring Tab BC: Aktif' : 'Saring Tab BC: Nonaktif (Semua Tampil)';
            if (badgeTabs) {
                badgeTabs.innerText = isTabsActive ? 'ON' : 'OFF';
                badgeTabs.className = 'config-dropdown-badge ' + (isTabsActive ? 'config-badge-on' : 'config-badge-off');
            }
        }

        function toggleConfigDropdown(e) {
            if (e) e.stopPropagation();
            const menu = document.getElementById('configDropdownPanel');
            const btn = document.getElementById('btnConfigDropdown');
            if (!menu) return;
            const isOpen = menu.classList.contains('show');
            if (isOpen) {
                closeConfigDropdown();
            } else {
                syncConfigDropdownState();
                menu.classList.add('show');
                if (btn) btn.classList.add('active');
            }
        }

        function closeConfigDropdown() {
            const menu = document.getElementById('configDropdownPanel');
            const btn = document.getElementById('btnConfigDropdown');
            if (menu) menu.classList.remove('show');
            if (btn) btn.classList.remove('active');
        }

        document.addEventListener('click', (e) => {
            const container = document.getElementById('dropdownConfigMenu');
            if (container && !container.contains(e.target)) {
                closeConfigDropdown();
            }
        });

        function toggleColumnFilter() {
            const body = document.body;
            body.classList.toggle('filter-cols-active');
            syncConfigDropdownState();
        }

        function toggleTabFilter() {
            const body = document.body;
            body.classList.toggle('filter-tabs-active');
            syncConfigDropdownState();
        }

        // -------------------------------------------------------------
        // PENGATURAN SARING TAB & SARING KOLOM (GLOBAL & PER PERUSAHAAN)
        // -------------------------------------------------------------
        const allSystemTabs = [
            { key: 'header',           label: 'Header (Utama)', icon: '📋', required: true },
            { key: 'barang',           label: 'Barang', icon: '📦', required: true },
            { key: 'entitas',          label: 'Entitas', icon: '🏢', required: true },
            { key: 'dokumen',          label: 'Dokumen', icon: '📄', required: true },
            { key: 'pengangkut',       label: 'Pengangkut', icon: '🚢', required: false },
            { key: 'kemasan',          label: 'Kemasan', icon: '📦', required: false },
            { key: 'kontainer',        label: 'Kontainer', icon: '🚛', required: false },
            { key: 'pungutan',         label: 'Pungutan', icon: '💰', required: false },
            { key: 'barangtarif',      label: 'Barang Tarif', icon: '🏷️', required: false },
            { key: 'bahanbaku',        label: 'Bahan Baku', icon: '🏭', required: false },
            { key: 'bahanbakutarif',   label: 'Bahan Baku Tarif', icon: '🏷️', required: false },
            { key: 'bahanbakudokumen', label: 'BB Dokumen', icon: '📑', required: false },
            { key: 'jaminan',          label: 'Jaminan', icon: '🛡️', required: false },
            { key: 'bankdevisa',       label: 'Bank Devisa', icon: '🏦', required: false },
            { key: 'komponenbiaya',    label: 'Biaya', icon: '💰', required: false },
            { key: 'barangdokumen',    label: 'Barang Dokumen', icon: '📑', required: false },
            { key: 'barangentitas',    label: 'Barang Entitas', icon: '👥', required: false },
            { key: 'barangspekkhusus', label: 'Spek Khusus', icon: '🔬', required: false },
            { key: 'barangvd',         label: 'Nilai VD', icon: '⚖️', required: false },
            { key: 'kesiapanbarang',   label: 'Kesiapan Barang', icon: '⏱️', required: false }
        ];

        function openFilterSettingsModal(initialSubTab) {
            if (initialSubTab === 'kolom') filterModalActiveSubTab = 'kolom';
            else if (initialSubTab === 'desimal') filterModalActiveSubTab = 'desimal';
            else filterModalActiveSubTab = 'tab';
            switchFilterSettingsSubTab(filterModalActiveSubTab);

            // Tentukan default Dokumen BC yang dipilih
            const docBcEl = document.getElementById('cfgFilterDocBc');
            let activeBc = '23';
            if (currentData && currentData.header && currentData.header.kode_dokumen) {
                activeBc = String(currentData.header.kode_dokumen).replace(/[^0-9]/g, '');
            } else {
                const entryBc = document.getElementById('entryDocBc')?.value;
                if (entryBc) activeBc = String(entryBc).replace(/[^0-9]/g, '');
            }
            if (docBcEl && activeBc) docBcEl.value = activeBc;

            // Inisialisasi dropdown scope perusahaan
            const scopeEl = document.getElementById('cfgFilterScopeCmp');
            const targetCmp = currentActiveCmpId || (currentData && currentData.header ? currentData.header.cmp_id : '') || new URLSearchParams(window.location.search).get('cmp_id') || '';
            if (targetCmp && scopeEl) {
                let found = false;
                for (let i = 0; i < scopeEl.options.length; i++) {
                    if (scopeEl.options[i].value === targetCmp) { found = true; break; }
                }
                if (!found) {
                    const opt = document.createElement('option');
                    opt.value = targetCmp;
                    opt.textContent = `🏢 Perusahaan: ${targetCmp}`;
                    scopeEl.appendChild(opt);
                }
                scopeEl.value = targetCmp;
            } else if (scopeEl) {
                scopeEl.value = '';
            }

            document.getElementById('modalFilterSettings').style.display = 'flex';
            loadFilterSettingsData();
        }

        function switchFilterSettingsSubTab(subTab) {
            filterModalActiveSubTab = subTab;
            const isTab = (subTab === 'tab');
            const isCol = (subTab === 'kolom');
            const isDec = (subTab === 'desimal');

            document.getElementById('subPanelFilterTab').style.display = isTab ? 'block' : 'none';
            document.getElementById('subPanelFilterKolom').style.display = isCol ? 'block' : 'none';
            const panelDec = document.getElementById('subPanelFilterDesimal');
            if (panelDec) panelDec.style.display = isDec ? 'block' : 'none';

            const btnTab = document.getElementById('btnSubTabFilterTab');
            const btnCol = document.getElementById('btnSubTabFilterKolom');
            const btnDec = document.getElementById('btnSubTabFilterDesimal');

            [ [btnTab, isTab], [btnCol, isCol], [btnDec, isDec] ].forEach(([btn, active]) => {
                if (btn) {
                    btn.style.borderBottomColor = active ? 'var(--primary)' : 'transparent';
                    btn.style.color = active ? 'var(--primary)' : 'var(--text-muted)';
                    btn.style.fontWeight = active ? '700' : '600';
                }
            });
        }

        function onFilterScopeChange() {
            loadFilterSettingsData();
        }

        async function loadFilterSettingsData() {
            const bc = document.getElementById('cfgFilterDocBc')?.value || '23';
            const scopeEl = document.getElementById('cfgFilterScopeCmp');
            const cmpId = scopeEl ? scopeEl.value : '';

            const badge = document.getElementById('cfgFilterStatusBadge');
            if (badge) {
                badge.style.background = 'var(--border)';
                badge.style.color = 'var(--text-muted)';
                badge.innerText = 'Memuat konfigurasi...';
            }

            const gridTab = document.getElementById('filterTabGrid');
            if (gridTab) gridTab.innerHTML = '<div style="padding:20px; text-align:center; color:var(--text-muted);"><span class="spinner"></span> Memuat aturan tab...</div>';

            const tbodyCol = document.querySelector('#tableFilterCols tbody');
            if (tbodyCol) tbodyCol.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:20px; color:var(--text-muted);"><span class="spinner"></span> Memuat aturan kolom...</td></tr>';

            try {
                const res = await fetch(`editor.php?action=get_filter_settings&bc=${encodeURIComponent(bc)}&cmp_id=${encodeURIComponent(cmpId)}`);
                const json = await res.json();
                if (json.status !== 'OK') {
                    alert('Gagal memuat pengaturan: ' + (json.message || 'Error'));
                    return;
                }

                filterModalCacheData = json;

                // Update dropdown list perusahaan jika tersedia
                if (scopeEl && Array.isArray(json.companies) && json.companies.length > 0) {
                    const currentVal = scopeEl.value;
                    scopeEl.innerHTML = '<option value="">🌐 GLOBAL (Default Semua Perusahaan)</option>';
                    json.companies.forEach(c => {
                        const opt = document.createElement('option');
                        opt.value = c.cmp_id;
                        opt.textContent = `🏢 ${c.cmp_name} (${c.cmp_id})`;
                        scopeEl.appendChild(opt);
                    });
                    scopeEl.value = currentVal;
                }

                // Status Badge & Reset Button
                const btnReset = document.getElementById('btnResetToGlobal');
                if (!cmpId) {
                    // Scope Global
                    if (badge) {
                        badge.style.background = '#722ed1';
                        badge.style.color = '#ffffff';
                        badge.innerText = '🌐 PENGATURAN GLOBAL (Default Master)';
                    }
                    if (btnReset) btnReset.style.display = 'none';
                } else {
                    // Scope Perusahaan
                    const hasCustom = !!(json.tab.has_company_custom || json.kolom.has_company_custom || json.desimal?.has_company_custom);
                    if (hasCustom) {
                        if (badge) {
                            badge.style.background = '#52c41a';
                            badge.style.color = '#ffffff';
                            badge.innerText = '🏢 KHUSUS PERUSAHAAN (Custom Override)';
                        }
                        if (btnReset) btnReset.style.display = 'inline-block';
                    } else {
                        if (badge) {
                            badge.style.background = '#1890ff';
                            badge.style.color = '#ffffff';
                            badge.innerText = '🌐 MENGIKUTI PENGATURAN GLOBAL';
                        }
                        if (btnReset) btnReset.style.display = 'none';
                    }
                }

                // Render TAB Checklist
                renderFilterTabChecklist(json);

                // Render KOLOM Datagrid
                renderFilterColTable(json);

                // Render FORMAT DESIMAL Form
                renderFilterDecimalsForm(json);

            } catch (err) {
                if (gridTab) gridTab.innerHTML = `<div style="color:var(--danger); padding:10px;">Gagal: ${err.message}</div>`;
                if (tbodyCol) tbodyCol.innerHTML = `<tr><td colspan="4" style="color:var(--danger); padding:10px;">Gagal: ${err.message}</td></tr>`;
            }
        }

        function renderFilterDecimalsForm(data) {
            if (!data || !data.desimal) return;
            const cur = data.desimal.current || data.desimal.defaults || {};
            const setVal = (id, val) => {
                const el = document.getElementById(id);
                if (el && val !== undefined && val !== null) {
                    el.value = String(val);
                }
            };
            setVal('cfgDecQty', cur.qty ?? 2);
            setVal('cfgDecKemasan', cur.kemasan ?? 0);
            setVal('cfgDecUang', cur.uang ?? 2);
            setVal('cfgDecHargaSatuan', cur.harga_satuan ?? 4);
            setVal('cfgDecBerat', cur.berat ?? 4);
            setVal('cfgDecTarif', cur.tarif ?? 2);
            updateDecimalLivePreviews();
        }

        function updateDecimalLivePreviews() {
            const getVal = id => parseInt(document.getElementById(id)?.value ?? 2, 10);
            const formatSample = (num, dec) => Number(num).toLocaleString('en-US', {
                minimumFractionDigits: dec,
                maximumFractionDigits: dec
            });

            const pQty = document.getElementById('prevDecQty');
            if (pQty) pQty.innerText = formatSample(7, getVal('cfgDecQty'));

            const pKemasan = document.getElementById('prevDecKemasan');
            if (pKemasan) pKemasan.innerText = formatSample(95, getVal('cfgDecKemasan'));

            const pUang = document.getElementById('prevDecUang');
            if (pUang) pUang.innerText = formatSample(4208141.25, getVal('cfgDecUang'));

            const pHarga = document.getElementById('prevDecHargaSatuan');
            if (pHarga) pHarga.innerText = formatSample(601163.0357, getVal('cfgDecHargaSatuan'));

            const pBerat = document.getElementById('prevDecBerat');
            if (pBerat) pBerat.innerText = formatSample(3841.834, getVal('cfgDecBerat'));

            const pTarif = document.getElementById('prevDecTarif');
            if (pTarif) pTarif.innerText = formatSample(11, getVal('cfgDecTarif'));
        }

        function equalizeAllDecimals(decVal) {
            const v = String(decVal);
            ['cfgDecQty', 'cfgDecKemasan', 'cfgDecUang', 'cfgDecHargaSatuan', 'cfgDecBerat', 'cfgDecTarif'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = v;
            });
            updateDecimalLivePreviews();
            showToast(`Semua kolom format angka telah disamakan menjadi ${decVal} desimal (koma).`);
        }

        function applyDecimalPreset(presetType) {
            if (presetType === 'optimal') {
                const map = {
                    cfgDecQty: 0,
                    cfgDecKemasan: 0,
                    cfgDecUang: 2,
                    cfgDecHargaSatuan: 4,
                    cfgDecBerat: 4,
                    cfgDecTarif: 2
                };
                Object.entries(map).forEach(([id, val]) => {
                    const el = document.getElementById(id);
                    if (el) el.value = String(val);
                });
                updateDecimalLivePreviews();
                showToast('Rekomendasi optimal CEISA (Qty & Kemasan bulat 0 koma, Uang 2 koma, Berat & Harga 4 koma) telah diterapkan.');
            }
        }

        function renderFilterTabChecklist(data) {
            const gridTab = document.getElementById('filterTabGrid');
            if (!gridTab) return;
            gridTab.innerHTML = '';

            const cmpId = document.getElementById('cfgFilterScopeCmp')?.value || '';
            const isCompany = !!cmpId;
            const rules = (isCompany && data.tab.has_company_custom) ? data.tab.company_rules : data.tab.global_rules;

            allSystemTabs.forEach(t => {
                const isRequired = !!t.required;
                let isChecked = isRequired;
                if (!isRequired) {
                    isChecked = (rules && rules[t.key] !== undefined) ? (rules[t.key] === 1) : false;
                }

                const card = document.createElement('label');
                card.className = 'filter-tab-card';
                card.style.cssText = 'display:flex; align-items:center; gap:10px; padding:8px 12px; border:1px solid var(--border); border-radius:6px; background:var(--bg-input); cursor:pointer; user-select:none;';
                card.innerHTML = `
                    <input type="checkbox" name="cfg_tab" value="${t.key}" ${isChecked ? 'checked' : ''} ${isRequired ? 'disabled' : ''} style="width:16px; height:16px; cursor:pointer;">
                    <span style="font-size:16px;">${t.icon}</span>
                    <div style="flex:1;">
                        <div style="font-weight:600; font-size:12px; color:var(--text-main);">${t.label}</div>
                        <div style="font-size:10px; color:var(--text-muted); font-family:monospace;">${t.key}</div>
                    </div>
                    ${isRequired ? '<span class="badge" style="background:#52c41a; color:#fff; font-size:9px;">Wajib</span>' : ''}
                `;
                gridTab.appendChild(card);
            });
        }

        function setAllFilterTabs(val) {
            document.querySelectorAll('#filterTabGrid input[name="cfg_tab"]').forEach(chk => {
                if (!chk.disabled) chk.checked = !!val;
            });
        }

        function applyDefaultBcTabPreset() {
            const bc = document.getElementById('cfgFilterDocBc')?.value || '23';
            const tabRules = {
                '20':  ['pengangkut', 'kemasan', 'kontainer', 'pungutan', 'barangtarif', 'komponenbiaya', 'bankdevisa', 'barangdokumen', 'barangvd', 'barangspekkhusus', 'barangentitas'],
                '23':  ['pungutan', 'barangtarif', 'kontainer', 'pengangkut', 'kemasan', 'komponenbiaya', 'barangdokumen', 'barangvd', 'barangspekkhusus', 'barangentitas'],
                '25':  ['pungutan', 'barangtarif', 'bahanbaku', 'bahanbakutarif', 'bahanbakudokumen', 'pengangkut', 'kemasan', 'komponenbiaya', 'barangdokumen'],
                '27':  ['pengangkut', 'kemasan', 'bahanbaku', 'bahanbakutarif', 'bahanbakudokumen', 'barangdokumen'],
                '30':  ['kesiapanbarang', 'bankdevisa', 'kontainer', 'pengangkut', 'kemasan', 'barangdokumen', 'barangspekkhusus'],
                '33':  ['bankdevisa', 'kontainer', 'pengangkut', 'kemasan', 'barangtarif', 'barangdokumen'],
                '40':  ['pungutan', 'barangtarif', 'pengangkut', 'kemasan', 'barangdokumen'],
                '41':  ['pengangkut', 'kemasan', 'bahanbaku', 'bahanbakudokumen', 'barangdokumen'],
                '16':  ['pengangkut', 'kemasan', 'kontainer', 'pungutan', 'barangtarif', 'barangdokumen'],
                '28':  ['pengangkut', 'kemasan', 'kontainer', 'barangtarif', 'barangdokumen'],
                '331': ['pengangkut', 'kemasan', 'kontainer', 'barangtarif', 'barangdokumen'],
                'p3bet': ['pengangkut', 'kemasan', 'kontainer', 'barangtarif', 'barangdokumen'],
                '261': ['pengangkut', 'kemasan', 'bahanbaku', 'bahanbakutarif', 'bahanbakudokumen', 'pungutan', 'jaminan', 'barangdokumen'],
                '262': ['pengangkut', 'kemasan', 'bahanbaku', 'bahanbakutarif', 'bahanbakudokumen', 'pungutan', 'jaminan', 'barangdokumen'],
                '611': ['pengangkut', 'kemasan', 'kontainer', 'pungutan', 'barangtarif', 'barangdokumen', 'bankdevisa'],
                '613': ['pengangkut', 'kemasan', 'kontainer', 'pungutan', 'barangtarif', 'bankdevisa']
            };
            const preset = new Set(tabRules[bc] || []);
            document.querySelectorAll('#filterTabGrid input[name="cfg_tab"]').forEach(chk => {
                if (!chk.disabled) {
                    chk.checked = preset.has(chk.value);
                }
            });
            showToast(`Rekomendasi standar untuk BC ${bc} telah diterapkan pada checklist.`);
        }

        function switchColTableFilter(tbl) {
            filterModalActiveColTable = tbl;
            const btnHdr = document.getElementById('btnColTableHdr');
            const btnBrg = document.getElementById('btnColTableBrg');
            if (btnHdr && btnBrg) {
                btnHdr.className = tbl === 'header' ? 'btn btn-xs btn-primary' : 'btn btn-xs btn-secondary';
                btnBrg.className = tbl === 'barang' ? 'btn btn-xs btn-primary' : 'btn btn-xs btn-secondary';
            }
            if (filterModalCacheData) {
                renderFilterColTable(filterModalCacheData);
            }
        }

        function renderFilterColTable(data) {
            const tbody = document.querySelector('#tableFilterCols tbody');
            if (!tbody || !data || !data.kolom || !data.kolom.columns) return;
            tbody.innerHTML = '';

            const tbl = filterModalActiveColTable;
            const cols = data.kolom.columns[tbl] || [];
            if (cols.length === 0) {
                tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; padding:15px; color:var(--text-muted);">Tidak ada kolom untuk tabel ${tbl}.</td></tr>`;
                return;
            }

            cols.forEach(c => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td style="text-align:center;">
                        <input type="checkbox" class="cfg-col-chk" data-table="${tbl}" data-col="${c.col_id}" ${c.current_visible ? 'checked' : ''} onchange="updateColCheckStats()">
                    </td>
                    <td style="font-family:monospace; font-weight:600; font-size:11px; color:var(--primary);">${escapeHtml(c.col_id)}</td>
                    <td style="font-size:11px;">${escapeHtml(c.col_txt)}</td>
                    <td style="font-size:10px; color:var(--text-muted);">${c.reff ? `<span class="reff-badge" style="font-size:9px;">${escapeHtml(c.reff)}</span>` : '-'}</td>
                `;
                tbody.appendChild(tr);
            });

            updateColCheckStats();
            filterColumnListUI();
        }

        function filterColumnListUI() {
            const q = (document.getElementById('cfgColSearchInput')?.value || '').toLowerCase().trim();
            document.querySelectorAll('#tableFilterCols tbody tr').forEach(tr => {
                const text = tr.innerText.toLowerCase();
                tr.style.display = (!q || text.includes(q)) ? '' : 'none';
            });
        }

        function setAllFilterCols(val) {
            document.querySelectorAll('#tableFilterCols tbody input.cfg-col-chk').forEach(chk => {
                const tr = chk.closest('tr');
                if (tr && tr.style.display !== 'none') {
                    chk.checked = !!val;
                }
            });
            updateColCheckStats();
        }

        function toggleCheckAllCols(val) {
            setAllFilterCols(val);
        }

        function updateColCheckStats() {
            const all = document.querySelectorAll('#tableFilterCols tbody input.cfg-col-chk');
            const chkAll = document.getElementById('chkAllVisibleCols');
            if (!chkAll || all.length === 0) return;
            const checked = document.querySelectorAll('#tableFilterCols tbody input.cfg-col-chk:checked');
            chkAll.checked = (all.length > 0 && all.length === checked.length);
            chkAll.indeterminate = (checked.length > 0 && checked.length < all.length);
        }

        async function saveFilterSettings() {
            const btn = document.getElementById('btnSaveFilterSettings');
            const bc = document.getElementById('cfgFilterDocBc')?.value || '23';
            const scopeEl = document.getElementById('cfgFilterScopeCmp');
            const cmpId = scopeEl ? scopeEl.value : '';

            // Tabs
            const tabs = {};
            document.querySelectorAll('#filterTabGrid input[name="cfg_tab"]').forEach(chk => {
                tabs[chk.value] = chk.checked ? 1 : 0;
            });

            // Columns
            const cols = { header: {}, barang: {} };
            if (filterModalCacheData && filterModalCacheData.kolom && filterModalCacheData.kolom.columns) {
                ['header', 'barang'].forEach(tbl => {
                    (filterModalCacheData.kolom.columns[tbl] || []).forEach(c => {
                        cols[tbl][c.col_id] = c.current_visible ? 1 : 0;
                    });
                });
            }
            document.querySelectorAll('#tableFilterCols tbody input.cfg-col-chk').forEach(chk => {
                const tbl = chk.dataset.table;
                const col = chk.dataset.col;
                if (cols[tbl]) {
                    cols[tbl][col] = chk.checked ? 1 : 0;
                }
            });

            // Decimals
            const decimals = {
                qty: parseInt(document.getElementById('cfgDecQty')?.value ?? 2, 10),
                kemasan: parseInt(document.getElementById('cfgDecKemasan')?.value ?? 0, 10),
                uang: parseInt(document.getElementById('cfgDecUang')?.value ?? 2, 10),
                harga_satuan: parseInt(document.getElementById('cfgDecHargaSatuan')?.value ?? 4, 10),
                berat: parseInt(document.getElementById('cfgDecBerat')?.value ?? 4, 10),
                tarif: parseInt(document.getElementById('cfgDecTarif')?.value ?? 2, 10)
            };

            try {
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<span class="spinner"></span> Menyimpan...';
                }
                const res = await fetch('editor.php?action=save_filter_settings', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        bc: bc,
                        cmp_id: cmpId,
                        save_type: 'all',
                        tabs: tabs,
                        cols: cols,
                        decimals: decimals
                    })
                });
                const json = await res.json();
                if (json.status === 'OK') {
                    showToast('✓ ' + (json.message || 'Pengaturan berhasil disimpan!'));
                    applyDecimalSettingsToConfigs(decimals);
                    refreshActiveGridDecimals();
                    await loadFilterSettingsData();
                    if (currentAju) {
                        await loadAjuData(currentAju);
                    } else {
                        await loadAjuData('');
                    }
                } else {
                    alert('Gagal menyimpan pengaturan: ' + (json.message || 'Error'));
                }
            } catch (e) {
                alert('Kesalahan jaringan: ' + e.message);
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '💾 Simpan Pengaturan';
                }
            }
        }

        async function resetScopeToGlobal() {
            const bc = document.getElementById('cfgFilterDocBc')?.value || '23';
            const scopeEl = document.getElementById('cfgFilterScopeCmp');
            const cmpId = scopeEl ? scopeEl.value : '';
            if (!cmpId) {
                alert('Pengaturan Global tidak dapat direset.');
                return;
            }

            const selCmpText = scopeEl.selectedOptions[0]?.text || cmpId;
            const ok = await showConfirm(
                `Apakah Anda yakin ingin menghapus pengaturan khusus untuk:<br><br><strong>Perusahaan:</strong> ${escapeHtml(selCmpText)}<br><strong>Dokumen BC:</strong> BC ${escapeHtml(bc)}<br><br>Setelah direset, perusahaan ini akan kembali mengikuti <strong>Pengaturan Global</strong> secara otomatis.`,
                'Reset ke Pengaturan Global',
                'Ya, Reset Sekarang',
                'Batal',
                'warning'
            );
            if (!ok) return;

            try {
                const fd = new FormData();
                fd.append('bc', bc);
                fd.append('cmp_id', cmpId);
                fd.append('reset_type', 'all');

                const res = await fetch('editor.php?action=reset_filter_settings', {
                    method: 'POST',
                    body: fd
                });
                const json = await res.json();
                if (json.status === 'OK') {
                    showToast('✓ ' + (json.message || 'Berhasil direset ke Global!'));
                    await loadFilterSettingsData();
                    if (currentAju) {
                        await loadAjuData(currentAju);
                    } else {
                        await loadAjuData('');
                    }
                } else {
                    alert('Gagal mereset: ' + (json.message || 'Error'));
                }
            } catch (e) {
                alert('Kesalahan jaringan: ' + e.message);
            }
        }

        // -------------------------------------------------------------
        // REFERENSI LOOKUP MODAL (bc_ceisa_reff)
        // -------------------------------------------------------------
        function openReffLookup(reffName, targetInputId) {
            activeLookupTarget = targetInputId;
            activeLookupReff = reffName;
            document.getElementById('reffLookupTitle').innerText = 'Cari Referensi: ' + reffName;
            document.getElementById('reffSearchQuery').value = '';
            document.getElementById('modalReffLookup').style.display = 'flex';
            loadReffLookupData('');
        }

        async function loadReffLookupData(query) {
            const tbody = document.querySelector('#tableReffResults tbody');
            tbody.innerHTML = '<tr><td colspan="3" style="text-align:center; padding:15px; color:var(--text-muted);">Memuat data...</td></tr>';
            try {
                const res = await fetch(`editor.php?action=search_reff&reff=${encodeURIComponent(activeLookupReff)}&q=${encodeURIComponent(query)}`);
                const json = await res.json();
                if (json.status === 'OK') {
                    tbody.innerHTML = '';
                    if (json.data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="3" style="text-align:center; padding:15px; color:var(--text-muted);">Tidak ada data yang cocok.</td></tr>';
                        return;
                    }
                    json.data.forEach(item => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td><strong style="color:var(--primary);">${escapeHtml(item.kode)}</strong></td>
                            <td>${escapeHtml(item.teks)}</td>
                            <td style="text-align:center;">
                                <button class="btn btn-secondary btn-sm" onclick="selectReffItem('${escapeHtml(item.kode)}')">Pilih</button>
                            </td>
                        `;
                        tbody.appendChild(tr);
                    });
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="3" style="color:var(--danger); padding:10px;">Error: ${err.message}</td></tr>`;
            }
        }

        function handleReffSearch(e) {
            if (e.key === 'Enter') {
                performReffSearch();
            }
        }

        function performReffSearch() {
            const q = document.getElementById('reffSearchQuery').value.trim();
            loadReffLookupData(q);
        }

        function selectReffItem(code) {
            if (activeLookupTarget) {
                const targetEl = document.getElementById(activeLookupTarget);
                if (targetEl) {
                    targetEl.value = code;
                    targetEl.dispatchEvent(new Event('change'));
                }
            }
            closeModal('modalReffLookup');
        }

        // -------------------------------------------------------------
        // SAVE & TRANSFER OPERATIONS
        // -------------------------------------------------------------
        async function deleteCurrentAju() {
            if (!currentAju) {
                showWarning('Tidak ada dokumen AJU yang sedang dibuka di Editor.', 'Form Kosong');
                return;
            }

            if (isCurrentDocLocked) {
                showWarning(
                    `Dokumen AJU <strong>${escapeHtml(currentAju)}</strong> tidak dapat dihapus karena sudah dikunci (masuk ke IT Inventory).<br><br><small style="color:var(--text-muted);">Silakan lakukan <strong>Unposting IT Inventory</strong> terlebih dahulu untuk membuka kunci dokumen ini.</small>`,
                    'AJU Terkunci (IT Inventory)'
                );
                return;
            }

            Swal.fire({
                title: '⚠️ Konfirmasi Hapus Data AJU',
                html: `
                    <div style="font-size:13px; text-align:left; line-height:1.5;">
                        <p>Anda akan <strong>MENGHAPUS PERMANEN</strong> seluruh data staging dokumen ini:</p>
                        <div style="background:rgba(255, 77, 79, 0.08); padding:8px 12px; border-radius:6px; border:1px solid rgba(255, 77, 79, 0.2); margin:10px 0; font-family:monospace; font-size:13px; font-weight:700; color:#ff4d4f;">
                            AJU: ${escapeHtml(currentAju)}
                        </div>
                        <p style="color:var(--text-muted, #666); font-size:12px;">Data tabel Header, Barang, Entitas, Dokumen, Kemasan, Pengangkut, Kontainer, Pungutan, dan respon pabean terkait akan dibersihkan dari database.</p>
                        <div style="margin-top:12px; font-weight:600; color:#ff4d4f;">
                            Ketik <span style="background:#ff4d4f; color:#fff; padding:1px 6px; border-radius:3px; letter-spacing:1px;">YA</span> untuk mengonfirmasi:
                        </div>
                    </div>
                `,
                input: 'text',
                inputPlaceholder: 'Ketik YA di sini...',
                inputAttributes: {
                    autocapitalize: 'characters',
                    autocomplete: 'off',
                    spellcheck: 'false'
                },
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ff4d4f',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: '🗑️ Hapus Data AJU',
                cancelButtonText: 'Batal',
                showLoaderOnConfirm: true,
                inputValidator: (val) => {
                    if (!val || val.trim().toUpperCase() !== 'YA') {
                        return 'Wajib mengetik "YA" dengan benar untuk konfirmasi penghapusan.';
                    }
                },
                preConfirm: (val) => {
                    const formData = new FormData();
                    formData.append('action', 'delete_aju');
                    formData.append('nomor_aju', currentAju);
                    formData.append('confirm', val.trim());

                    return fetch('editor.php', { method: 'POST', body: formData })
                        .then(r => r.json())
                        .then(res => {
                            if (res.status !== 'OK') {
                                throw new Error(res.message || 'Gagal menghapus data AJU.');
                            }
                            return res;
                        })
                        .catch(err => {
                            Swal.showValidationMessage(`${err.message}`);
                        });
                }
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    Swal.fire({
                        title: 'Berhasil Dihapus',
                        html: result.value.message || 'Data dokumen AJU berhasil dihapus.',
                        icon: 'success',
                        confirmButtonColor: '#1890ff'
                    }).then(() => {
                        const urlParams = new URLSearchParams(window.location.search);
                        const cmpId = urlParams.get('cmp_id') || '';
                        const cmpParam = cmpId ? '&cmp_id=' + encodeURIComponent(cmpId) : '';
                        window.location.href = 'editor.php?embedded=1' + cmpParam;
                    });
                }
            });
        }

        async function saveCurrentTab() {
            if (!currentAju) {
                showWarning('Silakan buat dokumen baru melalui panel "✨ Setup AJU Baru" atau pilih dokumen AJU yang ada untuk disimpan.', 'Form Kosong');
                return;
            }
            if (isCurrentDocLocked) {
                showWarning(
                    `Dokumen AJU <strong>${escapeHtml(currentAju)}</strong> sudah MASUK IT Inventory!<br><small style="color:var(--text-muted);">Dokumen berstatus Read-Only dan tidak dapat diedit atau disimpan.<br>Silakan lakukan Unposting di menu Daftar Dokumen untuk membuka kunci edit.</small>`,
                    'Dokumen Terkunci'
                );
                return;
            }
            if (activeTab === 'header') {
                await saveHeader();
            } else if (activeTab === 'kesiapanbarang') {
                await saveKesiapanBarang();
            } else {
                await saveTable(activeTab);
            }
        }

        // ============================================================
        // VALIDASI ELEMEN MANDATORY SESUAI SKEMA CEISA 4.0
        // ============================================================
        function getActiveDocBc() {
            let bc = '40';
            if (currentData && currentData.header && currentData.header.kode_dokumen) {
                bc = String(currentData.header.kode_dokumen).trim();
            } else {
                const hdrBc = document.getElementById('hdr_kode_dokumen');
                if (hdrBc && hdrBc.value) bc = hdrBc.value.trim();
                else {
                    const selBc = document.getElementById('selectBc');
                    if (selBc && selBc.value) bc = selBc.value.trim();
                }
            }
            return bc.replace(/[^0-9]/g, '') || '40';
        }

        function getMandatoryErrors(scope, dataOrRows, bc) {
            const errors = [];
            const cleanBc = String(bc || '40').replace(/[^0-9]/g, '') || '40';

            if (scope === 'header') {
                const d = dataOrRows || {};

                // 1. Kantor Pabean (Semua BC)
                if (!d.kode_kantor || d.kode_kantor.trim() === '') {
                    errors.push({ section: 'Header', field: 'Kantor Bea Cukai', message: 'Kode Kantor Pabean wajib dipilih.' });
                }

                // 2. Pernyataan / TTD (Semua BC)
                if (!d.nama_pernyataan || d.nama_pernyataan.trim() === '') {
                    errors.push({ section: 'Header', field: 'Nama Pernyataan (TTD)', message: 'Nama penandatangan dokumen wajib diisi.' });
                }
                if (!d.kota_pernyataan || d.kota_pernyataan.trim() === '') {
                    errors.push({ section: 'Header', field: 'Kota Pernyataan', message: 'Kota tempat penandatanganan wajib diisi.' });
                }
                if (!d.tanggal_pernyataan || d.tanggal_pernyataan.trim() === '') {
                    errors.push({ section: 'Header', field: 'Tanggal Pernyataan', message: 'Tanggal pernyataan / aju wajib diisi (yyyy-mm-dd).' });
                }

                // 3. Aturan Khusus Dokumen TPB (BC 4.0, 2.3, 2.5, 2.6.1, 2.6.2, 2.7)
                if (['40', '23', '25', '261', '262', '27'].includes(cleanBc)) {
                    if (!d.kode_jenis_tpb || d.kode_jenis_tpb.trim() === '') {
                        errors.push({ section: 'Header', field: 'Jenis TPB', message: 'Jenis TPB wajib dipilih (misal: Kawasan Berikat).' });
                    }
                }

                // 4. Tujuan Pengiriman / Pemasukan / Pengeluaran
                if (['40', '27', '261', '262'].includes(cleanBc)) {
                    if (!d.kode_tujuan_pengiriman || d.kode_tujuan_pengiriman.trim() === '') {
                        errors.push({ section: 'Header', field: 'Tujuan Pengiriman', message: 'Kode tujuan pengiriman wajib dipilih.' });
                    }
                } else if (cleanBc === '23') {
                    if (!d.kode_tujuan_pemasukan || d.kode_tujuan_pemasukan.trim() === '') {
                        errors.push({ section: 'Header', field: 'Tujuan Pemasukan', message: 'Kode tujuan pemasukan wajib dipilih.' });
                    }
                } else if (cleanBc === '25') {
                    if (!d.kode_tujuan_pengeluaran || d.kode_tujuan_pengeluaran.trim() === '') {
                        errors.push({ section: 'Header', field: 'Tujuan Pengeluaran', message: 'Kode tujuan pengeluaran wajib dipilih.' });
                    }
                }

                // 5. Kantor Tujuan untuk BC 2.7
                if (cleanBc === '27' && (!d.kode_kantor_tujuan || d.kode_kantor_tujuan.trim() === '')) {
                    errors.push({ section: 'Header', field: 'Kantor Pabean Tujuan', message: 'Kode Kantor Tujuan wajib dipilih untuk BC 2.7.' });
                }

                // 6. Berat Bruto & Netto
                const bruto = parseFloat(d.bruto || 0);
                const netto = parseFloat(d.netto || 0);
                if (bruto <= 0) {
                    errors.push({ section: 'Header', field: 'Berat Kotor (Bruto)', message: 'Nilai Bruto harus lebih besar dari 0 kg.' });
                }
                if (netto <= 0) {
                    errors.push({ section: 'Header', field: 'Berat Bersih (Netto)', message: 'Nilai Netto harus lebih besar dari 0 kg.' });
                }
                if (bruto > 0 && netto > 0 && netto > bruto) {
                    errors.push({ section: 'Header', field: 'Validasi Netto vs Bruto', message: `Berat Netto (${netto} kg) tidak boleh melebihi Bruto (${bruto} kg).` });
                }

                // 7. Nilai Transaksi Sesuai Jenis BC
                if (['40', '25', '27', '41'].includes(cleanBc)) {
                    const hp = parseFloat(d.harga_penyerahan || 0);
                    if (hp <= 0 && currentData && Array.isArray(currentData.barang) && currentData.barang.length > 0) {
                        errors.push({ section: 'Header', field: 'Harga Penyerahan', message: 'Harga Penyerahan total harus lebih besar dari 0.' });
                    }
                } else if (['20', '23', '28'].includes(cleanBc)) {
                    const cif = parseFloat(d.cif || 0);
                    if (cif <= 0 && currentData && Array.isArray(currentData.barang) && currentData.barang.length > 0) {
                        errors.push({ section: 'Header', field: 'Nilai CIF', message: 'Nilai CIF total harus lebih besar dari 0.' });
                    }
                    if (!d.kode_valuta || d.kode_valuta.trim() === '') {
                        errors.push({ section: 'Header', field: 'Valuta / Mata Uang', message: 'Kode valuta wajib dipilih.' });
                    }
                    if (parseFloat(d.ndpbm || 0) <= 0) {
                        errors.push({ section: 'Header', field: 'Kurs NDPBM', message: 'Kurs NDPBM harus lebih besar dari 0.' });
                    }
                } else if (cleanBc === '30') {
                    const fob = parseFloat(d.fob || 0);
                    if (fob <= 0 && currentData && Array.isArray(currentData.barang) && currentData.barang.length > 0) {
                        errors.push({ section: 'Header', field: 'Nilai FOB', message: 'Nilai FOB ekspor harus lebih besar dari 0.' });
                    }
                }

            } else if (scope === 'barang') {
                const rows = Array.isArray(dataOrRows) ? dataOrRows : [];
                if (rows.length === 0) {
                    errors.push({
                        section: 'Rincian Barang',
                        field: 'Tabel Barang Kosong',
                        message: `Tabel Barang masih 0 baris. Dokumen BC ${cleanBc} mewajibkan minimal 1 item barang.`
                    });
                    return errors;
                }

                rows.forEach((r, idx) => {
                    const seri = r.seri || r.seri_barang || (idx + 1);
                    const kode = (r.kode_barang && r.kode_barang.trim()) ? r.kode_barang.trim() : `Baris #${seri}`;
                    const sec = `Barang #${seri} (${kode})`;

                    if (!r.kode_barang || r.kode_barang.trim() === '') {
                        errors.push({ section: sec, field: 'Kode Barang', message: 'Kode barang / part number tidak boleh kosong.' });
                    }
                    if (!r.uraian || r.uraian.trim() === '') {
                        errors.push({ section: sec, field: 'Uraian Barang', message: 'Uraian / deskripsi barang tidak boleh kosong.' });
                    }
                    const cleanHs = String(r.hs || '').replace(/[^0-9]/g, '');
                    if (!cleanHs) {
                        errors.push({ section: sec, field: 'Pos Tarif (HS Code)', message: 'Pos Tarif (HS Code) wajib diisi 8 digit angka.' });
                    } else if (cleanHs.length !== 8) {
                        errors.push({ section: sec, field: 'Pos Tarif (HS Code)', message: `HS Code harus tepat 8 digit angka (saat ini: ${cleanHs.length} digit: '${r.hs}').` });
                    }
                    if (parseFloat(r.jumlah_satuan || 0) <= 0) {
                        errors.push({ section: sec, field: 'Jumlah Satuan', message: 'Jumlah satuan barang harus lebih besar dari 0.' });
                    }
                    if (!r.kode_satuan || r.kode_satuan.trim() === '' || r.kode_satuan.trim() === '-') {
                        errors.push({ section: sec, field: 'Satuan Barang', message: 'Kode satuan barang wajib diisi (misal: KGM, PCE, SET).' });
                    }
                    if (parseFloat(r.netto || 0) <= 0) {
                        errors.push({ section: sec, field: 'Netto Barang', message: 'Berat bersih (netto) barang harus lebih besar dari 0.' });
                    }
                    if (parseFloat(r.jumlah_kemasan || 0) <= 0) {
                        errors.push({ section: sec, field: 'Jumlah Kemasan', message: 'Jumlah kemasan barang harus lebih besar dari 0.' });
                    }
                    if (!r.kode_kemasan || r.kode_kemasan.trim() === '' || r.kode_kemasan.trim() === '-') {
                        errors.push({ section: sec, field: 'Jenis Kemasan', message: 'Kode kemasan barang wajib diisi (misal: PK, CT, BX).' });
                    }

                    // Nilai Transaksi per Barang
                    if (['40', '25', '27', '41'].includes(cleanBc)) {
                        if (parseFloat(r.harga_penyerahan || 0) <= 0) {
                            errors.push({ section: sec, field: 'Harga Penyerahan', message: 'Harga penyerahan barang harus lebih besar dari 0.' });
                        }
                    } else if (['20', '23', '28'].includes(cleanBc)) {
                        if (parseFloat(r.cif || 0) <= 0) {
                            errors.push({ section: sec, field: 'Nilai CIF', message: 'Nilai CIF barang harus lebih besar dari 0.' });
                        }
                    } else if (cleanBc === '30') {
                        if (parseFloat(r.fob || 0) <= 0) {
                            errors.push({ section: sec, field: 'Nilai FOB', message: 'Nilai FOB barang harus lebih besar dari 0.' });
                        }
                    }
                });

            } else if (scope === 'entitas') {
                const rows = Array.isArray(dataOrRows) ? dataOrRows : [];
                if (rows.length === 0) {
                    errors.push({
                        section: 'Entitas Dokumen',
                        field: 'Tabel Entitas Kosong',
                        message: `Tabel Entitas masih 0 baris. Dokumen BC ${cleanBc} mewajibkan pengisian entitas terkait.`
                    });
                    return errors;
                }

                // Cek Peran Wajib (Mandatory Roles) sesuai jenis BC
                const roleDict = {
                    '1': 'Importir [1]',
                    '2': 'Eksportir [2]',
                    '3': 'Pengusaha TPB [3]',
                    '4': 'PPJK [4]',
                    '5': 'Pemasok / Supplier [5]',
                    '6': 'Pembeli / Buyer [6]',
                    '7': 'Pemilik Barang [7]',
                    '8': 'Penerima Barang [8]',
                    '9': 'Pengirim Barang [9]',
                    '10': 'Penjual [10]'
                };

                let requiredRoles = [];
                if (cleanBc === '40') {
                    requiredRoles = ['3', '7', '9']; // Pengusaha TPB, Pemilik Barang, Pengirim
                } else if (cleanBc === '20') {
                    requiredRoles = ['1', '7', '9']; // Importir, Pemilik, Pengirim
                } else if (cleanBc === '23') {
                    requiredRoles = ['3', '7', '9']; // Pengusaha, Pemilik, Pengirim
                } else if (cleanBc === '27') {
                    requiredRoles = ['3', '8', '7']; // Pengirim TPB, Penerima TPB, Pemilik
                } else if (cleanBc === '30') {
                    requiredRoles = ['2', '7', '6']; // Eksportir, Pemilik, Pembeli
                } else if (cleanBc === '25') {
                    requiredRoles = ['3', '7', '6']; // Pengusaha TPB, Pemilik, Pembeli
                } else if (cleanBc === '261' || cleanBc === '262') {
                    requiredRoles = ['3', '7'];      // Pengusaha TPB, Pemilik
                }

                const existingRoles = rows.map(r => String(r.kode_entitas || '').trim());
                requiredRoles.forEach(reqRole => {
                    if (!existingRoles.includes(reqRole)) {
                        errors.push({
                            section: 'Entitas Wajib',
                            field: roleDict[reqRole] || `Role [${reqRole}]`,
                            message: `Dokumen BC ${cleanBc} mewajibkan adanya entitas ${roleDict[reqRole] || reqRole}.`
                        });
                    }
                });

                // Cek Isian Field Tiap Baris
                rows.forEach((r, idx) => {
                    const seri = r.seri || (idx + 1);
                    const roleName = roleDict[r.kode_entitas] || `Role ${r.kode_entitas || '-'}`;
                    const sec = `Entitas #${seri} (${roleName})`;

                    if (!r.kode_entitas || r.kode_entitas.trim() === '') {
                        errors.push({ section: sec, field: 'Kode Entitas', message: 'Kode / Peran entitas wajib dipilih.' });
                    }
                    if (!r.nama_entitas || r.nama_entitas.trim() === '') {
                        errors.push({ section: sec, field: 'Nama Entitas', message: 'Nama perusahaan / pihak terlibat wajib diisi.' });
                    }
                    if (!r.alamat_entitas || r.alamat_entitas.trim() === '') {
                        errors.push({ section: sec, field: 'Alamat Entitas', message: 'Alamat entitas wajib diisi.' });
                    }
                    if (!r.nomor_identitas || r.nomor_identitas.trim() === '' || r.nomor_identitas.trim() === '-') {
                        errors.push({ section: sec, field: 'Nomor Identitas (NPWP)', message: 'Nomor NPWP / NIK / Identitas wajib diisi.' });
                    }
                    if (!r.kode_jenis_identitas || r.kode_jenis_identitas.trim() === '') {
                        errors.push({ section: sec, field: 'Jenis Identitas', message: 'Jenis identitas wajib dipilih (NPWP 15/16 Digit, NIK, dll).' });
                    }
                });

            } else if (scope === 'dokumen') {
                const rows = Array.isArray(dataOrRows) ? dataOrRows : [];
                if (rows.length === 0) {
                    errors.push({
                        section: 'Dokumen Pelengkap',
                        field: 'Tabel Dokumen Kosong',
                        message: `Tabel Dokumen masih 0 baris. Minimal wajib melampirkan 1 dokumen pelengkap (Invoice, Packing List, atau Surat Jalan).`
                    });
                    return errors;
                }

                rows.forEach((r, idx) => {
                    const seri = r.seri || (idx + 1);
                    const sec = `Dokumen #${seri}`;
                    if (!r.kode_dokumen || r.kode_dokumen.trim() === '') {
                        errors.push({ section: sec, field: 'Kode Dokumen', message: 'Jenis dokumen pelengkap wajib dipilih.' });
                    }
                    if (!r.nomor_dokumen || r.nomor_dokumen.trim() === '' || r.nomor_dokumen.trim() === '-') {
                        errors.push({ section: sec, field: 'Nomor Dokumen', message: 'Nomor dokumen pelengkap wajib diisi.' });
                    }
                    if (!r.tanggal_dokumen || r.tanggal_dokumen.trim() === '') {
                        errors.push({ section: sec, field: 'Tanggal Dokumen', message: 'Tanggal dokumen pelengkap wajib diisi (yyyy-mm-dd).' });
                    }
                });

            } else if (scope === 'kemasan') {
                const rows = Array.isArray(dataOrRows) ? dataOrRows : [];
                if (rows.length === 0) {
                    errors.push({
                        section: 'Kemasan',
                        field: 'Tabel Kemasan Kosong',
                        message: `Tabel Kemasan masih 0 baris. Wajib memiliki minimal 1 data kemasan pengangkutan.`
                    });
                    return errors;
                }

                rows.forEach((r, idx) => {
                    const seri = r.seri || (idx + 1);
                    const sec = `Kemasan #${seri}`;
                    if (parseFloat(r.jumlah_kemasan || 0) <= 0) {
                        errors.push({ section: sec, field: 'Jumlah Kemasan', message: 'Jumlah kemasan harus lebih besar dari 0.' });
                    }
                    if (!r.kode_kemasan || r.kode_kemasan.trim() === '' || r.kode_kemasan.trim() === '-') {
                        errors.push({ section: sec, field: 'Jenis Kemasan', message: 'Kode jenis kemasan wajib dipilih (misal PK, CT, BX).' });
                    }
                });

            } else if (scope === 'pengangkut') {
                const rows = Array.isArray(dataOrRows) ? dataOrRows : [];
                if (rows.length === 0) {
                    errors.push({
                        section: 'Sarana Pengangkut',
                        field: 'Tabel Pengangkut Kosong',
                        message: `Tabel Pengangkut masih 0 baris. Wajib mencantumkan minimal 1 sarana pengangkut.`
                    });
                    return errors;
                }

                rows.forEach((r, idx) => {
                    const seri = r.seri || (idx + 1);
                    const sec = `Pengangkut #${seri}`;
                    if (!r.nama_pengangkut || r.nama_pengangkut.trim() === '' || r.nama_pengangkut.trim() === '-') {
                        errors.push({ section: sec, field: 'Nama Pengangkut', message: 'Nama sarana pengangkut / nomor polisi wajib diisi.' });
                    }
                    if (!r.kode_cara_angkut || r.kode_cara_angkut.trim() === '') {
                        errors.push({ section: sec, field: 'Cara Pengangkutan', message: 'Cara pengangkutan wajib dipilih (Darat, Laut, Udara).' });
                    }
                });
            }

            return errors;
        }

        function getAllMandatoryErrors(bc) {
            const allErrors = [];
            const cleanBc = String(bc || getActiveDocBc()).replace(/[^0-9]/g, '') || '40';

            // 1. Validasi Sheet Header
            const hdrData = {};
            headerFields.forEach(f => {
                const el = document.getElementById('hdr_' + f);
                if (el) {
                    let v = el.value !== undefined ? el.value.trim() : '';
                    if (headerNumberDecimals[f] !== undefined && v !== '') {
                        v = stripNumberCommas(v);
                    }
                    hdrData[f] = v;
                } else if (currentData && currentData.header && currentData.header[f] !== undefined) {
                    hdrData[f] = currentData.header[f];
                }
            });
            const hdrErrors = getMandatoryErrors('header', hdrData, cleanBc);
            allErrors.push(...hdrErrors);

            // Helper untuk mengambil data tab (jika DOM tabel ada barisnya pakai DOM, jika tidak pakai currentData)
            const getTabData = (tabKey) => {
                const tbody = document.querySelector(`#table-${tabKey} tbody`);
                if (tbody) {
                    const trs = tbody.querySelectorAll('tr:not(.tr-empty)');
                    if (trs.length > 0) {
                        const rows = [];
                        trs.forEach(tr => {
                            const rowObj = {};
                            tr.querySelectorAll('input[data-col], select[data-col], textarea[data-col]').forEach(el => {
                                if (el.dataset.col) {
                                    let v = el.value !== undefined ? el.value.trim() : '';
                                    if (el.classList.contains('cell-number') || el.type === 'number') {
                                        v = stripNumberCommas(v);
                                    }
                                    rowObj[el.dataset.col] = v;
                                }
                            });
                            rows.push(rowObj);
                        });
                        return rows;
                    }
                }
                return (currentData && Array.isArray(currentData[tabKey])) ? currentData[tabKey] : [];
            };

            // 2. Validasi Sheet Barang
            const barangRows = getTabData('barang');
            allErrors.push(...getMandatoryErrors('barang', barangRows, cleanBc));

            // 3. Validasi Sheet Entitas
            const entitasRows = getTabData('entitas');
            allErrors.push(...getMandatoryErrors('entitas', entitasRows, cleanBc));

            // 4. Validasi Sheet Dokumen
            const dokumenRows = getTabData('dokumen');
            allErrors.push(...getMandatoryErrors('dokumen', dokumenRows, cleanBc));

            // 5. Validasi Sheet Kemasan
            const kemasanRows = getTabData('kemasan');
            allErrors.push(...getMandatoryErrors('kemasan', kemasanRows, cleanBc));

            // 6. Validasi Sheet Pengangkut
            const pengangkutRows = getTabData('pengangkut');
            allErrors.push(...getMandatoryErrors('pengangkut', pengangkutRows, cleanBc));

            return allErrors;
        }

        async function promptMandatoryWarning(scopeName, errors, bc) {
            const htmlRows = errors.map((err, idx) => `
                <tr style="border-bottom:1px solid rgba(255,255,255,0.08); ${idx % 2 === 1 ? 'background:rgba(255,255,255,0.02);' : ''}">
                    <td style="padding:4px 8px; font-weight:700; color:var(--primary); font-size:10.5px; white-space:nowrap; vertical-align:top; border-right:1px solid rgba(255,255,255,0.05);">${escapeHtml(err.section)}</td>
                    <td style="padding:4px 8px; font-weight:700; color:#ef4444; font-size:10.5px; white-space:nowrap; vertical-align:top; border-right:1px solid rgba(255,255,255,0.05);">✕ ${escapeHtml(err.field)}</td>
                    <td style="padding:4px 8px; color:var(--text-main); font-size:10px; line-height:1.35; vertical-align:top; word-break:break-word;">${escapeHtml(err.message)}</td>
                </tr>
            `).join('');

            const swalBase = (typeof getSwalThemeOptions === 'function') ? getSwalThemeOptions() : {};
            const res = await Swal.fire({
                ...swalBase,
                icon: 'warning',
                title: `<span style="font-size:14px; font-weight:700; letter-spacing:0.2px;">Validasi Kolom Mandatory Belum Lengkap</span>`,
                html: `
                    <div style="text-align:left; font-size:11px; margin-top:2px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; padding-bottom:6px; border-bottom:1px solid rgba(255,255,255,0.08);">
                            <span style="font-weight:600; color:var(--text-main); font-size:11px;">
                                Ditemukan <b style="color:#ef4444; font-size:11.5px;">${errors.length} data mandatory</b> belum lengkap di seluruh dokumen:
                            </span>
                            <span class="badge" style="background:#2563eb; color:#fff; font-size:9.5px; font-weight:700; padding:2px 7px; border-radius:3px;">Dokumen BC ${bc}</span>
                        </div>
                        
                        <div style="max-height:220px; overflow-y:auto; overflow-x:hidden; border:1px solid var(--border); border-radius:4px; margin-bottom:8px; background:var(--bg-input);">
                            <table style="width:100%; border-collapse:collapse; table-layout:fixed;">
                                <thead style="background:var(--bg-card); position:sticky; top:0; z-index:10; border-bottom:1px solid var(--border);">
                                    <tr>
                                        <th style="padding:5px 8px; text-align:left; font-size:9.5px; font-weight:700; color:var(--text-muted); width:95px; border-right:1px solid rgba(255,255,255,0.05);">Tab / Sheet</th>
                                        <th style="padding:5px 8px; text-align:left; font-size:9.5px; font-weight:700; color:var(--text-muted); width:155px; border-right:1px solid rgba(255,255,255,0.05);">Kolom Mandatory</th>
                                        <th style="padding:5px 8px; text-align:left; font-size:9.5px; font-weight:700; color:var(--text-muted);">Keterangan Masalah</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${htmlRows}
                                </tbody>
                            </table>
                        </div>

                        <div style="background:rgba(239, 68, 68, 0.08); border-left:3px solid #ef4444; padding:6px 9px; border-radius:3px; font-size:10px; color:var(--text-main); margin-bottom:10px; line-height:1.4;">
                            ⚠️ <b>Peringatan CEISA 4.0:</b> Dokumen dengan kolom mandatory kosong berisiko ditolak (<i>Reject 1028</i>) saat proses transfer.
                        </div>

                        <p style="margin:0; text-align:center; font-weight:600; color:var(--text-main); font-size:11px;">
                            Apakah Anda tetap ingin <b>melanjutkan penyimpanan</b> data ini?
                        </p>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: '⚠️ Tetap Lanjut Simpan',
                cancelButtonText: '✕ Perbaiki Dulu',
                confirmButtonColor: '#d97706',
                cancelButtonColor: '#2563eb',
                reverseButtons: true,
                focusCancel: true,
                width: '620px',
                customClass: {
                    popup: 'swal2-mandatory-modal',
                    confirmButton: 'btn-sm',
                    cancelButton: 'btn-sm'
                }
            });

            return res.isConfirmed;
        }

        async function saveHeader(force = false) {
            const data = {};
            headerFields.forEach(f => {
                const el = document.getElementById('hdr_' + f);
                if (el) {
                    let v = el.value !== undefined ? el.value.trim() : '';
                    if (headerNumberDecimals[f] !== undefined && v !== '') {
                        v = stripNumberCommas(v);
                    }
                    data[f] = v;
                }
            });

            // Validasi Seluruh Tab/Sheet Mandatory Dokumen
            if (!force) {
                const bc = getActiveDocBc();
                const errors = getAllMandatoryErrors(bc);
                if (errors.length > 0) {
                    const proceed = await promptMandatoryWarning('Semua Tab', errors, bc);
                    if (!proceed) return;
                }
            }

            const saveBtns = document.querySelectorAll('button[onclick="saveCurrentTab()"]');
            saveBtns.forEach(b => {
                b.disabled = true;
                b._origHtml = b.innerHTML;
                b.innerHTML = '<span class="spinner"></span> Menyimpan...';
            });

            const formData = new FormData();
            formData.append('action', 'save_header');
            formData.append('nomor_aju', currentAju);
            for (const [k, v] of Object.entries(data)) {
                formData.append(`data[${k}]`, v);
            }

            try {
                const res = await fetch('editor.php', { method: 'POST', body: formData });
                const json = await res.json();
                if (json.status === 'OK') {
                    showSuccess('Header berhasil diperbarui!');
                } else {
                    showError(json.message);
                }
            } catch (err) {
                showError('Error: ' + err.message);
            } finally {
                saveBtns.forEach(b => {
                    b.disabled = false;
                    if (b._origHtml) b.innerHTML = b._origHtml;
                });
            }
        }

        async function saveTable(tableKey, force = false) {
            if (!currentAju) return;
            const tbody = document.querySelector(`#table-${tableKey} tbody`);
            if (!tbody) return;

            let rows = [];
            if (tableKey === 'barangbom') {
                syncDomToBomData();
                rows = currentData.barangbom || [];
            } else if (tableKey === 'tracebb') {
                syncDomToTraceData();
                rows = currentData.tracebb || [];
            } else {
                const trs = tbody.querySelectorAll('tr');
                trs.forEach(tr => {
                    const rowObj = {};
                    tr.querySelectorAll('input[data-col], select[data-col], textarea[data-col]').forEach(el => {
                        if (el.dataset.col) {
                            let v = el.value !== undefined ? el.value.trim() : '';
                            if (el.classList.contains('cell-number') || el.type === 'number') {
                                v = stripNumberCommas(v);
                            }
                            rowObj[el.dataset.col] = v;
                        }
                    });
                    rows.push(rowObj);
                });
            }

            if (tableKey === 'barang') {
                currentData.barang = rows;
            }

            // Validasi Seluruh Tab/Sheet Mandatory Dokumen
            if (!force) {
                const bc = getActiveDocBc();
                const errors = getAllMandatoryErrors(bc);
                if (errors.length > 0) {
                    const proceed = await promptMandatoryWarning('Semua Tab', errors, bc);
                    if (!proceed) return;
                }
            }

            const saveBtns = document.querySelectorAll(`button[onclick="saveCurrentTab()"], button[onclick="saveTable('${tableKey}')"]`);
            saveBtns.forEach(b => {
                b.disabled = true;
                b._origHtml = b.innerHTML;
                b.innerHTML = '<span class="spinner"></span> Menyimpan...';
            });

            const formData = new FormData();
            formData.append('action', 'save_table');
            formData.append('nomor_aju', currentAju);
            formData.append('table', tableKey);
            formData.append('rows', JSON.stringify(rows));

            try {
                const res = await fetch('editor.php', { method: 'POST', body: formData });
                const json = await res.json();
                if (json.status === 'OK') {
                    showSuccess(`Tabel ${tableKey} berhasil disimpan (${rows.length} baris)!`);
                    const countBadge = document.getElementById('count-' + tableKey);
                    if (countBadge) countBadge.innerText = rows.length;

                    if (tableKey === 'barangbom') {
                        renderBomForActiveBarang();
                    } else if (tableKey === 'tracebb') {
                        renderTraceForActiveBom();
                    }
                } else {
                    showError(json.message);
                }
            } catch (err) {
                showError('Error: ' + err.message);
            } finally {
                saveBtns.forEach(b => {
                    b.disabled = false;
                    if (b._origHtml) b.innerHTML = b._origHtml;
                });
            }
        }

        async function hitungPungutan() {
            if (!currentAju) {
                showWarning('Silakan pilih nomor AJU terlebih dahulu!');
                return;
            }
            if (isCurrentDocLocked) {
                showWarning('Dokumen AJU ini sudah MASUK IT Inventory (terkunci)!');
                return;
            }

            const btn = document.querySelector('button[onclick="hitungPungutan()"]');
            const origHtml = btn ? btn.innerHTML : '';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '⏳ Menghitung...';
            }

            try {
                const formData = new FormData();
                formData.append('action', 'calc_pungutan');
                formData.append('nomor_aju', currentAju);

                const res = await fetch('editor.php', { method: 'POST', body: formData });
                const json = await res.json();

                if (json.status === 'OK') {
                    const rows = json.rows || [];
                    currentData.pungutan = rows;

                    const tbody = document.querySelector('#table-pungutan tbody');
                    if (tbody) {
                        tbody.innerHTML = '';
                        rows.forEach((r, idx) => {
                            const tr = createGridRow('pungutan', r, idx);
                            tbody.appendChild(tr);
                        });
                        setTimeout(() => {
                            document.querySelectorAll('#table-pungutan select.cell-select').forEach(autoSizeSelect);
                        }, 50);
                    }

                    const countBadge = document.getElementById('count-pungutan');
                    if (countBadge) countBadge.innerText = rows.length;
                    updateTableTotals('pungutan');

                    showSuccess(json.message, 'Kalkulasi Pungutan Sukses');
                } else if (json.status === 'WARNING') {
                    showWarning(json.message);
                } else {
                    showError(json.message || 'Gagal menghitung pungutan.');
                }
            } catch (err) {
                showError('Error saat kalkulasi pungutan: ' + err.message);
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = origHtml;
                }
            }
        }

        async function hitungTarif(target) {
            if (!currentAju) {
                showWarning('Silakan pilih nomor AJU terlebih dahulu!');
                return;
            }
            if (isCurrentDocLocked) {
                showWarning('Dokumen AJU ini sudah MASUK IT Inventory (terkunci)!');
                return;
            }

            const labelTarget = target === 'bahanbaku' ? 'Bahan Baku Tarif' : 'Barang Tarif';
            const btn = document.querySelector(`button[onclick="hitungTarif('${target}')"]`);
            const origHtml = btn ? btn.innerHTML : '';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '⏳ Menghitung...';
            }

            try {
                const formData = new FormData();
                formData.append('action', 'calc_tarif');
                formData.append('nomor_aju', currentAju);
                formData.append('target', target);

                const res = await fetch('editor.php', { method: 'POST', body: formData });
                const json = await res.json();

                if (json.status === 'OK') {
                    const reloadTables = target === 'all' ? ['barangtarif', 'bahanbakutarif'] : (target === 'bahanbaku' ? ['bahanbakutarif'] : ['barangtarif']);
                    const tRes = await fetch(`editor.php?action=load_all&nomor_aju=${encodeURIComponent(currentAju)}`);
                    const tJson = await tRes.json();
                    if (tJson.status === 'OK' && tJson.tables) {
                        reloadTables.forEach(tbl => {
                            currentData[tbl] = tJson.tables[tbl] || [];
                            const tbody = document.querySelector(`#table-${tbl} tbody`);
                            if (tbody) {
                                tbody.innerHTML = '';
                                const frag = document.createDocumentFragment();
                                (currentData[tbl]).forEach((r, idx) => {
                                    const tr = createGridRow(tbl, r, idx);
                                    frag.appendChild(tr);
                                });
                                tbody.appendChild(frag);
                                setTimeout(() => {
                                    document.querySelectorAll(`#table-${tbl} select.cell-select`).forEach(autoSizeSelect);
                                }, 50);
                            }
                            const countBadge = document.getElementById('count-' + tbl);
                            if (countBadge) countBadge.innerText = currentData[tbl].length;
                            updateTableTotals(tbl);
                        });
                        // Tandai recap tarif perlu reload
                        markRecapTarifDirty();
                        loadRecapTarif();
                    }

                    showSuccess(json.message, 'Kalkulasi Tarif Sukses');
                } else if (json.status === 'WARNING') {
                    showWarning(json.message);
                } else {
                    showError(json.message || `Gagal menghitung tarif ${labelTarget}.`);
                }
            } catch (err) {
                showError(`Error saat kalkulasi tarif ${labelTarget}: ` + err.message);
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = origHtml;
                }
            }
        }

        async function saveKesiapanBarang() {
            if (!currentAju) {
                showWarning('Pilih nomor AJU terlebih dahulu!');
                return;
            }
            const saveBtns = document.querySelectorAll('button[onclick="saveCurrentTab()"]');
            saveBtns.forEach(b => {
                b.disabled = true;
                b._origHtml = b.innerHTML;
                b.innerHTML = '<span class="spinner"></span> Menyimpan...';
            });

            const kbFields = [
                'nama_pic', 'alamat_pic', 'telp_pic', 'lokasi_siap_periksa',
                'waktu_siap_periksa', 'kode_cara_stuffing', 'jumlah_kontainer20',
                'jumlah_kontainer40', 'kode_jenis_barang', 'kode_jenis_gudang', 'kode_jenis_partof', 'tanggal_pkb'
            ];
            const data = {};
            kbFields.forEach(f => {
                const el = document.getElementById('kb_' + f);
                if (el) data[f] = el.value.trim();
            });

            const formData = new FormData();
            formData.append('nomor_aju', currentAju);
            for (const [k, v] of Object.entries(data)) {
                formData.append(`data[${k}]`, v);
            }

            try {
                const res = await fetch('editor.php?action=save_kesiapanbarang', {
                    method: 'POST',
                    body: formData
                });
                const json = await res.json();
                if (json.status === 'OK') {
                    showSuccess('Kesiapan barang berhasil disimpan!');
                } else {
                    showError(json.message);
                }
            } catch (err) {
                showError('Koneksi gagal: ' + err.message);
            } finally {
                saveBtns.forEach(b => {
                    b.disabled = false;
                    if (b._origHtml) b.innerHTML = b._origHtml;
                });
            }
        function downloadCeisaExcel() {
            if (!currentAju) {
                showWarning('Silakan buat dokumen baru atau pilih nomor AJU terlebih dahulu untuk mengunduh Excel.', 'Pilih Dokumen');
                return;
            }
            showToast(`Mengunduh file Excel CEISA 4.0 untuk AJU ${currentAju}...`);
            window.location.href = `export_ceisa_excel.php?nomor_aju=${encodeURIComponent(currentAju)}`;
        }

        async function previewJson() {
            if (!currentAju) {
                showWarning('Silakan buat dokumen baru atau pilih nomor AJU terlebih dahulu untuk melihat preview JSON.', 'Form Kosong');
                return;
            }
            const previewMeta = document.getElementById('previewMeta');
            const previewContent = document.getElementById('previewContent');
            if (previewMeta) previewMeta.innerText = 'Menyusun JSON segar dari database...';
            if (previewContent) {
                previewContent.innerHTML = `
                    <div class="app-loader-container" style="padding:40px 20px; background:transparent;">
                        <div class="app-loader-icon-wrap" style="width:48px;height:48px;">
                            <div class="app-loader-spinner spinner-primary" style="width:48px;height:48px;"></div>
                            <div class="app-loader-center-icon" style="font-size:20px;">👁️</div>
                        </div>
                        <div class="app-loader-title" style="font-size:13px; color:#1890ff;">Menyusun Data JSON CEISA 4.0</div>
                        <div class="app-loader-desc" style="font-size:11px; max-width:320px;">Mengekstrak dan memformat seluruh tabel dokumen AJU dari database...</div>
                        <div class="app-loader-progress-track" style="max-width:240px; margin-top:10px;">
                            <div class="app-loader-progress-bar"></div>
                        </div>
                    </div>
                `;
            }
            document.getElementById('modalPreview').style.display = 'flex';

            try {
                const res = await fetch(`transfer.php?action=preview_json&nomor_aju=${encodeURIComponent(currentAju)}`);
                const json = await res.json();
                if (json.status === 'OK') {
                    if (previewMeta) previewMeta.innerText = `Ukuran: ${json.bytes.toLocaleString()} bytes | Dokumen: BC ${json.kode_dokumen}`;
                    if (previewContent) previewContent.innerText = JSON.stringify(json.data, null, 2);
                } else {
                    if (previewContent) previewContent.innerText = `ERROR: ${json.message}`;
                }
            } catch (err) {
                if (previewContent) previewContent.innerText = `ERROR: ${err.message}`;
            }
        }

        function backToHostToHost() {
            if (window.parent && typeof window.parent.switchMainTab === 'function') {
                window.parent.switchMainTab('transfer', currentAju || '');
            } else {
                const url = currentAju ? `/transfer.php?nomor_aju=${encodeURIComponent(currentAju)}` : '/transfer.php';
                window.location.href = url;
            }
        }

        // -------------------------------------------------------------
        // RENDER CEISA GATEWAY RESPONSE (USER FRIENDLY & ERROR TABLE)
        // -------------------------------------------------------------
        function renderCeisaGatewayResponse(data, nomorAju, activeBc) {
            let payload = data;
            if (data && typeof data === 'object' && data.response !== undefined && data.response !== null) {
                payload = data.response;
            }

            // Status check
            let isSuccess = false;
            let statusBadgeText = 'UNKNOWN';

            // Check if inner payload has status
            const innerStatus = (payload && typeof payload === 'object' && payload.status) ? String(payload.status).toLowerCase().trim() : '';
            const outerStatus = (data && typeof data === 'object' && data.status) ? String(data.status).toLowerCase().trim() : '';

            if (innerStatus === 'ok' || innerStatus === 'success' || innerStatus === 'saved' || innerStatus === 'receive') {
                isSuccess = true;
                statusBadgeText = (payload.status || 'BERHASIL').toUpperCase();
            } else if (innerStatus === 'failed' || innerStatus === 'reject' || innerStatus === 'error' || outerStatus === 'error') {
                isSuccess = false;
                statusBadgeText = (payload?.status || data?.status || 'GAGAL / REJECT').toUpperCase();
            } else if (outerStatus === 'ok' && !innerStatus) {
                const msgStr = JSON.stringify(payload || '');
                if (msgStr.includes('invalid') || msgStr.includes('error') || msgStr.includes('failed') || msgStr.includes('is required')) {
                    isSuccess = false;
                    statusBadgeText = 'DITOLAK';
                } else {
                    isSuccess = true;
                    statusBadgeText = 'BERHASIL';
                }
            } else if (outerStatus === 'raw') {
                isSuccess = false;
                statusBadgeText = 'RESPON MENTAH';
            }

            // Extract message(s)
            let rawMessages = [];
            if (payload && payload.message) {
                if (Array.isArray(payload.message)) {
                    rawMessages = payload.message;
                } else {
                    rawMessages = [String(payload.message)];
                }
            } else if (data && data.message) {
                if (Array.isArray(data.message)) {
                    rawMessages = data.message;
                } else {
                    rawMessages = [String(data.message)];
                }
            } else if (data && data.raw_response) {
                rawMessages = [String(data.raw_response)];
            }

            if (rawMessages.length === 0) {
                rawMessages = [isSuccess ? 'Dokumen berhasil diterima oleh CEISA 4.0' : 'Tidak ada rincian pesan dari server.'];
            }

            // Dictionary for CEISA fields -> Indonesian
            const fieldDict = {
                'tanggalDaftarDokAsal': 'Tanggal Daftar Dok Asal',
                'nomorDaftarDokAsal': 'Nomor Daftar Dok Asal',
                'kodeDokumenAsal': 'Kode Dokumen Asal',
                'nomorAjuDokAsal': 'Nomor AJU Dok Asal',
                'seriBahanBaku': 'Seri Bahan Baku',
                'seriBarang': 'Seri Barang',
                'seri': 'Nomor Seri',
                'kodeBarang': 'Kode Barang',
                'uraianBarang': 'Uraian Barang',
                'uraian': 'Uraian Barang',
                'posTarif': 'HS Code / Pos Tarif',
                'kodeSatuanBarang': 'Kode Satuan Barang',
                'kodeSatuan': 'Kode Satuan',
                'jumlahSatuan': 'Jumlah Satuan',
                'hargaPenyerahan': 'Harga Penyerahan',
                'hargaCif': 'Nilai CIF',
                'cif': 'Nilai CIF',
                'fob': 'Nilai FOB',
                'asuransi': 'Nilai Asuransi',
                'freight': 'Nilai Freight',
                'nilaiBarang': 'Nilai Barang',
                'nomorDokumen': 'Nomor Dokumen',
                'tanggalDokumen': 'Tanggal Dokumen',
                'kodeDokumen': 'Jenis / Kode Dokumen',
                'kodeEntitas': 'Kode Peran Entitas',
                'nomorIdentitas': 'Nomor NPWP / NIK Entitas',
                'namaEntitas': 'Nama Perusahaan / Entitas',
                'alamatEntitas': 'Alamat Entitas',
                'kodeKantor': 'Kantor Bea Cukai',
                'kodeKantorBongkar': 'Kantor Bongkar',
                'kodeKantorTujuan': 'Kantor Tujuan',
                'kodeValuta': 'Valuta / Mata Uang',
                'ndpbm': 'Kurs NDPBM',
                'bruto': 'Berat Kotor (Bruto)',
                'netto': 'Berat Bersih (Netto)',
                'volume': 'Volume',
                'kodeNegara': 'Negara',
                'kodeCaraAngkut': 'Cara Pengangkutan',
                'namaPengangkut': 'Nama Sarana Pengangkut',
                'nomorPengangkut': 'Nomor Pengangkut (Voyage/Flight)'
            };

            function humanizeField(f) {
                if (!f) return '-';
                if (fieldDict[f]) return fieldDict[f];
                return f.replace(/([A-Z])/g, ' $1').replace(/^./, str => str.toUpperCase());
            }

            function humanizePath(pathStr) {
                if (!pathStr || pathStr === '$') return 'Dokumen Utama (Header)';
                let clean = pathStr.replace(/^\$\.?/, '');
                let parts = clean.split('.');
                let labels = [];
                for (let p of parts) {
                    let m = p.match(/^([a-zA-Z0-9_]+)(?:\[(\d+)\])?$/);
                    if (m) {
                        let name = m[1];
                        let idx = m[2] !== undefined ? (parseInt(m[2]) + 1) : null;
                        let lbl = name;
                        if (name === 'barang') lbl = 'Barang #' + idx;
                        else if (name === 'bahanBaku') lbl = 'Bahan Baku #' + idx;
                        else if (name === 'dokumen') lbl = 'Dokumen #' + idx;
                        else if (name === 'entitas') lbl = 'Entitas #' + idx;
                        else if (name === 'kemasan') lbl = 'Kemasan #' + idx;
                        else if (name === 'kontainer') lbl = 'Kontainer #' + idx;
                        else if (name === 'pengangkut') lbl = 'Pengangkut #' + idx;
                        else if (name === 'header') lbl = 'Header';
                        else lbl = humanizeField(name) + (idx ? ' #' + idx : '');
                        labels.push(lbl);
                    } else {
                        labels.push(p);
                    }
                }
                return labels.join(' <span style="color:#bfbfbf;">➔</span> ');
            }

            function parseMessage(raw) {
                let text = String(raw).trim();
                let m = text.match(/^\$?\.?([a-zA-Z0-9_\[\].]+?)(?:\.([a-zA-Z0-9_]+))?:\s*(.*)$/);
                if (m) {
                    let path = m[1];
                    let field = m[2] || '';
                    let detail = m[3] || '';
                    if (!field && !path.endsWith(']')) {
                        let parts = path.split('.');
                        field = parts.pop();
                        path = parts.join('.');
                    }
                    return {
                        location: humanizePath(path),
                        fieldRaw: field,
                        fieldName: humanizeField(field),
                        detail: detail
                    };
                }
                return {
                    location: 'Gateway CEISA',
                    fieldRaw: '',
                    fieldName: '-',
                    detail: text
                };
            }

            function analyzeSolution(issue) {
                let d = issue.detail.toLowerCase();
                let f = (issue.fieldRaw || '').toLowerCase();

                if (d.includes('invalid date')) {
                    let dateMatch = issue.detail.match(/(\d{4})-(\d{1,2})-(\d{1,2})/);
                    if (dateMatch && parseInt(dateMatch[2]) > 12) {
                        return `<span style="color:#cf1322; font-weight:600;"><i class="fa-solid fa-triangle-exclamation"></i> Format Tanggal Terbalik:</span> Posisi Bulan & Hari tertukar (<code>${dateMatch[0]}</code>). Seharusnya <code>${dateMatch[1]}-${dateMatch[3].padStart(2,'0')}-${dateMatch[2].padStart(2,'0')}</code>. Tanggal di database telah dinormalisasi secara otomatis.`;
                    }
                    return '<span style="color:#cf1322; font-weight:600;">Format Tanggal Salah:</span> Gunakan format standar <code>YYYY-MM-DD</code> (contoh: <code>2026-08-31</code>).';
                }
                if (d.includes('is missing but it is required') || d.includes('is required')) {
                    return `Kolom <strong>${issue.fieldName}</strong> wajib diisi dan tidak boleh dikosongkan.`;
                }
                if (d.includes('kodedokumen tidak dikenali') && (activeBc === '613' || activeBc === '611' || activeBc === '612')) {
                    return `<strong>Khusus Dokumen PPKEK (${activeBc}):</strong> CEISA OpenAPI hanya menerima BC standar. Gunakan menu <strong>Posting INSW</strong> untuk dokumen ini.`;
                }
                if (d.includes('unauthorized') || d.includes('token expired') || d.includes('invalid token')) {
                    return 'Token sesi Bea Cukai telah kedaluwarsa. Sistem perlu melakukan refresh token login CEISA.';
                }
                if (d.includes('enum') || d.includes('is not one of')) {
                    return `Nilai pada kolom <strong>${issue.fieldName}</strong> tidak sesuai dengan tabel referensi resmi Bea Cukai.`;
                }
                return 'Periksa kembali data pada tab terkait di Editor AJU.';
            }

            // Build HTML
            let html = '';

            if (isSuccess) {
                html += `
                    <div style="background:#f6ffed; border:1px solid #b7eb8f; border-radius:8px; padding:16px 18px; margin-bottom:14px; display:flex; align-items:flex-start; gap:12px;">
                        <div style="font-size:26px; line-height:1; color:#52c41a;">&#9989;</div>
                        <div style="flex:1;">
                            <div style="font-size:14px; font-weight:700; color:#276722; margin-bottom:4px;">Berhasil Terkirim ke Portal CEISA 4.0!</div>
                            <div style="font-size:11.5px; color:#595959; margin-bottom:6px;">
                                <strong>Nomor AJU:</strong> <code style="color:#096dd9; font-size:12px;">${escapeHtml(nomorAju || '-')}</code>
                                &nbsp;&nbsp;|&nbsp;&nbsp; <strong>Status:</strong> <span style="background:#52c41a; color:#fff; padding:1px 6px; border-radius:4px; font-size:10px; font-weight:600;">${statusBadgeText}</span>
                            </div>
                            <div style="font-size:11.5px; color:#389e0d; background:#fff; border:1px solid #d9f7be; border-radius:4px; padding:8px 10px;">
                                ${escapeHtml(rawMessages.join(' | '))}
                            </div>
                        </div>
                    </div>
                `;
            } else {
                html += `
                    <div style="background:#fff2f0; border:1px solid #ffccc7; border-radius:8px; padding:16px 18px; margin-bottom:14px; display:flex; align-items:flex-start; gap:12px;">
                        <div style="font-size:26px; line-height:1; color:#ff4d4f;">&#10060;</div>
                        <div style="flex:1;">
                            <div style="font-size:14px; font-weight:700; color:#cf1322; margin-bottom:4px;">Validasi Ditolak oleh Gateway CEISA 4.0</div>
                            <div style="font-size:11.5px; color:#595959; margin-bottom:6px;">
                                <strong>Nomor AJU:</strong> <code style="color:#d4380d; font-size:12px;">${escapeHtml(nomorAju || '-')}</code>
                                &nbsp;&nbsp;|&nbsp;&nbsp; <strong>Respon Gateway:</strong> <span style="background:#ff4d4f; color:#fff; padding:1px 6px; border-radius:4px; font-size:10px; font-weight:600;">${statusBadgeText}</span>
                            </div>
                            <div style="font-size:11px; color:#8c8c8c;">
                                Ditemukan <strong style="color:#cf1322;">${rawMessages.length} kendala validasi data</strong> yang belum memenuhi aturan skema Bea Cukai.
                            </div>
                        </div>
                    </div>
                `;

                // Render error table
                html += `
                    <div style="border:1px solid #f0f0f0; border-radius:6px; overflow:hidden; margin-bottom:12px; box-shadow:0 1px 2px rgba(0,0,0,0.03);">
                        <div style="background:#fafafa; padding:8px 12px; border-bottom:1px solid #e8e8e8; font-weight:700; font-size:11.5px; color:#262626; display:flex; align-items:center; justify-content:space-between;">
                            <span><i class="fa-solid fa-list-check" style="color:#1890ff; margin-right:5px;"></i> Rincian Kesalahan Validasi (${rawMessages.length} Item)</span>
                            <span style="font-size:10.5px; color:#8c8c8c; font-weight:normal;">Periksa dan perbaiki kolom di bawah ini</span>
                        </div>
                        <div style="max-height:260px; overflow-y:auto;">
                            <table style="width:100%; border-collapse:collapse; font-size:11px; text-align:left;">
                                <thead>
                                    <tr style="background:#f5f5f5; color:#595959; border-bottom:1px solid #e8e8e8;">
                                        <th style="padding:6px 8px; width:30px; text-align:center;">#</th>
                                        <th style="padding:6px 8px; width:160px;">Lokasi Data</th>
                                        <th style="padding:6px 8px; width:140px;">Kolom / Field</th>
                                        <th style="padding:6px 8px;">Pesan Validasi</th>
                                        <th style="padding:6px 8px; width:240px;">Saran Solusi & Penjelasan</th>
                                    </tr>
                                </thead>
                                <tbody>
                `;

                rawMessages.forEach((msg, idx) => {
                    const parsed = parseMessage(msg);
                    const solution = analyzeSolution(parsed);
                    const rowBg = idx % 2 === 0 ? '#ffffff' : '#fafafa';
                    html += `
                        <tr style="background:${rowBg}; border-bottom:1px solid #f0f0f0; vertical-align:top;">
                            <td style="padding:7px 8px; text-align:center; color:#8c8c8c; font-weight:600;">${idx + 1}</td>
                            <td style="padding:7px 8px; font-weight:600; color:#1890ff;">${parsed.location}</td>
                            <td style="padding:7px 8px;">
                                <div style="font-weight:600; color:#262626;">${escapeHtml(parsed.fieldName)}</div>
                                ${parsed.fieldRaw ? `<code style="font-size:9.5px; color:#8c8c8c;">${escapeHtml(parsed.fieldRaw)}</code>` : ''}
                            </td>
                            <td style="padding:7px 8px; color:#cf1322; word-break:break-word;">
                                ${escapeHtml(parsed.detail)}
                            </td>
                            <td style="padding:7px 8px; color:#595959; line-height:1.4;">
                                ${solution}
                            </td>
                        </tr>
                    `;
                });

                html += `
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;

                html += `
                    <div style="font-size:11px; color:#595959; margin-bottom:10px;">
                        💡 <em>Tip: Klik tab yang sesuai pada grid editor di atas untuk memeriksa dan memperbaiki data, lalu tekan tombol <strong>💾 Simpan Tab</strong>.</em>
                    </div>
                `;
            }

            // Collapsible raw JSON
            const rawDisplay = typeof payload === 'object' ? JSON.stringify(payload, null, 2) : String(payload);
            html += `
                <details style="background:#f9f9f9; border:1px solid #e8e8e8; border-radius:6px; padding:6px 10px; margin-top:8px;">
                    <summary style="cursor:pointer; font-size:11px; font-weight:600; color:#595959; outline:none;">
                        <i class="fa-solid fa-code" style="color:#8c8c8c; margin-right:4px;"></i> Lihat Respon Teknis Asli (JSON Gateway)
                    </summary>
                    <pre class="code-view" style="margin-top:6px; max-height:200px; font-size:10.5px; background:#1e1e1e; color:#d4d4d4; padding:8px; border-radius:4px; overflow-x:auto;">${escapeHtml(rawDisplay)}</pre>
                </details>
            `;

            return html;
        }

        async function transferCeisa() {
            if (!currentAju) {
                showWarning('Silakan buat dokumen baru atau pilih nomor AJU terlebih dahulu untuk melakukan transfer ke CEISA.', 'Form Kosong');
                return;
            }
            if (isCurrentDocLocked) {
                showWarning(
                    `Dokumen AJU <strong>${escapeHtml(currentAju)}</strong> sudah MASUK IT Inventory!<br><small style="color:var(--text-muted);">Dokumen berstatus Terkunci dan tidak dapat ditransfer ke CEISA.<br>Silakan lakukan Unposting terlebih dahulu jika ingin membuka kunci.</small>`,
                    'Dokumen Terkunci'
                );
                return;
            }
            const ok = await showConfirm(
                `Konfirmasi Pengiriman ke Portal CEISA 4.0:<br><br><strong>Nomor AJU:</strong> <code>${escapeHtml(currentAju)}</code><br><br>Lanjutkan proses transfer sekarang?`,
                'Konfirmasi Transfer CEISA 4.0',
                'Ya, Transfer Sekarang',
                'Batal',
                'info'
            );
            if (!ok) return;

            const modalTitle = document.getElementById('modalResultTitle');
            const modalBody = document.getElementById('modalResultBody');
            const btnClose = document.getElementById('btnModalResultClose');
            const btnOk = document.getElementById('btnModalResultOk');

            if (modalTitle) modalTitle.innerText = '🚀 Pengiriman ke Portal CEISA 4.0';
            if (btnClose) btnClose.disabled = true;
            if (btnOk) {
                btnOk.disabled = true;
                btnOk.innerHTML = '<span class="spinner"></span> Mengirim ke CEISA...';
            }

            let secondsElapsed = 0;
            let currentStage = 1;
            const updateTimer = () => {
                secondsElapsed++;
                const mins = String(Math.floor(secondsElapsed / 60)).padStart(2, '0');
                const secs = String(secondsElapsed % 60).padStart(2, '0');
                const timerEl = document.getElementById('editorTransferTimerCounter');
                if (timerEl) timerEl.innerText = `${mins}:${secs}`;

                // Live dynamic pipeline stages
                const statusMsg = document.getElementById('editorTransferStatusMessage');
                const step1 = document.getElementById('editorPipeStep1');
                const line1 = document.getElementById('editorPipeLine1');
                const step2 = document.getElementById('editorPipeStep2');
                const line2 = document.getElementById('editorPipeLine2');
                const step3 = document.getElementById('editorPipeStep3');

                if (secondsElapsed >= 1 && currentStage === 1) {
                    currentStage = 2;
                    if (step1) { step1.classList.remove('active'); step1.classList.add('done'); }
                    if (line1) line1.classList.add('active');
                    if (step2) step2.classList.add('active');
                    if (statusMsg) statusMsg.innerHTML = 'Melakukan otentikasi Bearer Token & enkripsi sesi Bea Cukai...';
                }
                if (secondsElapsed >= 2 && currentStage === 2) {
                    currentStage = 3;
                    if (step2) { step2.classList.remove('active'); step2.classList.add('done'); }
                    if (line2) line2.classList.add('active');
                    if (step3) step3.classList.add('active');
                    if (statusMsg) statusMsg.innerHTML = 'Mengirim transmisi ke API Gateway Bea Cukai & memverifikasi respon...';
                }
            };

            const docTypeForBadge = document.getElementById('hdr_kode_dokumen')?.value || '25';
            modalBody.innerHTML = `
                <div class="app-loader-container">
                    <div class="app-loader-icon-wrap">
                        <div class="app-loader-aura"></div>
                        <div class="app-loader-orbit"></div>
                        <div class="app-loader-spinner"></div>
                        <div class="app-loader-spinner-inner"></div>
                        <div class="app-loader-center-disc">
                            <i class="fa-solid fa-rocket app-loader-center-icon"></i>
                        </div>
                    </div>

                    <div class="app-loader-title">Transmisi Dokumen ke Portal CEISA 4.0</div>

                    <div class="app-loader-doc-card">
                        <span class="meta-bc">BC ${escapeHtml(docTypeForBadge)}</span>
                        <span class="meta-aju">${escapeHtml(currentAju)}</span>
                    </div>

                    <div class="app-pipeline-container">
                        <div class="app-pipeline-step active" id="editorPipeStep1">
                            <div class="pipeline-circle"><i class="fa-solid fa-file-shield"></i></div>
                            <span class="pipeline-label">1. Validasi Data</span>
                        </div>
                        <div class="app-pipeline-line" id="editorPipeLine1">
                            <div class="line-flow"></div>
                        </div>
                        <div class="app-pipeline-step" id="editorPipeStep2">
                            <div class="pipeline-circle"><i class="fa-solid fa-key"></i></div>
                            <span class="pipeline-label">2. Otentikasi Sesi</span>
                        </div>
                        <div class="app-pipeline-line" id="editorPipeLine2">
                            <div class="line-flow"></div>
                        </div>
                        <div class="app-pipeline-step" id="editorPipeStep3">
                            <div class="pipeline-circle"><i class="fa-solid fa-satellite-dish"></i></div>
                            <span class="pipeline-label">3. Transmisi Gateway</span>
                        </div>
                    </div>

                    <div class="app-loader-desc" id="editorTransferStatusMessage">
                        Mempersiapkan struktur data dokumen, verifikasi entitas & tarif pabean...
                    </div>

                    <div class="app-loader-progress-track">
                        <div class="app-loader-progress-bar"></div>
                    </div>

                    <div class="app-loader-cyber-timer">
                        <span class="live-dot"></span>
                        <span style="font-size:10px; font-weight:700; letter-spacing:0.5px;">LIVE TRANSMISSION</span>
                        <span style="opacity:0.4;">|</span>
                        <i class="fa-regular fa-clock" style="font-size:11px;"></i>
                        <span id="editorTransferTimerCounter" style="font-family:ui-monospace,SFMono-Regular,Consolas,monospace; font-weight:700;">00:00</span>
                    </div>
                </div>
            `;
            document.getElementById('modalResult').style.display = 'flex';
            const timerInterval = setInterval(updateTimer, 1000);

            try {
                const docType = document.getElementById('hdr_kode_dokumen')?.value || '';
                const res = await fetch(`transfer.php?action=transfer&nomor_aju=${encodeURIComponent(currentAju)}`, { method: 'POST' });
                const text = await res.text();
                let json;
                try {
                    json = JSON.parse(text);
                } catch (e) {
                    json = { status: 'ERROR', message: text || 'Respon server tidak valid' };
                }
                if (modalTitle) modalTitle.innerText = 'Hasil Transfer Gateway CEISA 4.0';
                modalBody.innerHTML = renderCeisaGatewayResponse(json, currentAju, docType);
            } catch (err) {
                if (modalTitle) modalTitle.innerText = 'Gagal Koneksi';
                modalBody.innerHTML = renderCeisaGatewayResponse({ status: 'ERROR', message: err.message }, currentAju, '');
            } finally {
                clearInterval(timerInterval);
                if (btnClose) btnClose.disabled = false;
                if (btnOk) {
                    btnOk.disabled = false;
                    btnOk.innerHTML = 'OK, Selesai';
                }
            }
        }

        function transferFromModal() {
            closeModal('modalPreview');
            transferCeisa();
        }

        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }

        // ============================================================
        // MODAL 1: SETTING LIST ENTITAS DEFAULT (bc_ceisa_entidx)
        // ============================================================
        let entidxMasterOpts = [];

        function openModalSettingEntidx() {
            // Ambil opsi entitas dari master jika tersedia
            entidxMasterOpts = currentReffOptions['ENTITAS'] || [];
            
            // Set dokumen terpilih sesuai dokumen aktif
            const activeBc = currentData && currentData.header && currentData.header.kode_dokumen 
                ? String(currentData.header.kode_dokumen).trim() 
                : (document.getElementById('selectBc') ? document.getElementById('selectBc').value : '40');
            const cleanBc = activeBc.replace(/[^0-9]/g, '');
            const selBc = document.getElementById('selEntidxBc');
            if (selBc && cleanBc) {
                selBc.value = cleanBc;
            }

            // Atur pilihan scope perusahaan
            const optCmp = document.getElementById('optEntidxCmpScope');
            const currentCmp = (typeof currentCompanyCmpId !== 'undefined' && currentCompanyCmpId) 
                ? currentCompanyCmpId 
                : (document.getElementById('filterCmpId') ? document.getElementById('filterCmpId').value : '');
            if (optCmp) {
                if (currentCmp) {
                    optCmp.textContent = `🏢 Perusahaan: ${currentCmp}`;
                    optCmp.disabled = false;
                } else {
                    optCmp.textContent = `🏢 Perusahaan: (Tidak ada cmp_id)`;
                    optCmp.disabled = true;
                }
            }

            document.getElementById('modalSettingEntidx').style.display = 'flex';
            loadEntidxList();
        }

        async function loadEntidxList() {
            const bc = document.getElementById('selEntidxBc').value;
            const scope = document.getElementById('selEntidxScope').value;
            const currentCmp = (typeof currentCompanyCmpId !== 'undefined' && currentCompanyCmpId) 
                ? currentCompanyCmpId 
                : (document.getElementById('filterCmpId') ? document.getElementById('filterCmpId').value : '');
            const cmpId = (scope === 'company') ? currentCmp : '';

            const statusEl = document.getElementById('entidxStatusInfo');
            statusEl.innerText = 'Memuat konfigurasi...';

            const tbody = document.getElementById('tbodyEntidxSetting');
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:15px;">⏳ Mengambil data setting...</td></tr>';

            try {
                const res = await fetch(`editor.php?action=get_entidx&bc=${encodeURIComponent(bc)}&cmp_id=${encodeURIComponent(cmpId)}`);
                const json = await res.json();
                tbody.innerHTML = '';

                if (json.status !== 'OK' || !json.data || json.data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; color:var(--text-muted); padding:15px;">Belum ada urutan default untuk dokumen ini. Silakan klik <b>➕ Tambah Baris</b>.</td></tr>';
                    statusEl.innerText = 'Daftar kosong.';
                    return;
                }

                statusEl.innerText = json.is_custom_company 
                    ? `Status: Menggunakan setting khusus perusahaan (${json.cmp_id}).` 
                    : `Status: Menggunakan setting Global.`;

                json.data.forEach((item, i) => {
                    renderEntidxRow(item.idx || (i + 1), item.kode_entitas);
                });
                renumberEntidxRows();
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; color:#ff4d4f; padding:15px;">Gagal memuat: ${e.message}</td></tr>`;
                statusEl.innerText = 'Error koneksi.';
            }
        }

        function renderEntidxRow(idx, selectedKode = '') {
            const tbody = document.getElementById('tbodyEntidxSetting');
            if (tbody.querySelector('td[colspan]')) {
                tbody.innerHTML = '';
            }

            const tr = document.createElement('tr');
            tr.className = 'entidx-row';

            // Col 1: Seri / idx
            const tdIdx = document.createElement('td');
            tdIdx.style.textAlign = 'center';
            tdIdx.style.fontWeight = '700';
            tdIdx.className = 'entidx-num';
            tdIdx.innerText = idx;
            tr.appendChild(tdIdx);

            // Col 2: Dropdown Kode Entitas
            const tdKode = document.createElement('td');
            const sel = document.createElement('select');
            sel.className = 'cell-select entidx-kode-sel';
            sel.style.width = '100%';
            sel.style.fontWeight = '600';

            const optEmpty = document.createElement('option');
            optEmpty.value = '';
            optEmpty.innerText = '-- Pilih Kode Entitas --';
            sel.appendChild(optEmpty);

            const opts = entidxMasterOpts.length > 0 ? entidxMasterOpts : [
                { kode: '1', teks: 'IMPORTIR' },
                { kode: '2', teks: 'EKSPORTIR / IMPORTIR' },
                { kode: '3', teks: 'PENGUSAHA TPB' },
                { kode: '4', teks: 'PPJK' },
                { kode: '5', teks: 'PEMASOK / SUPPLIER' },
                { kode: '6', teks: 'PEMBELI / BUYER' },
                { kode: '7', teks: 'PEMILIK BARANG' },
                { kode: '8', teks: 'PENERIMA BARANG' },
                { kode: '9', teks: 'PENGIRIM BARANG' },
                { kode: '10', teks: 'PENJUAL' },
                { kode: '11', teks: 'PEMUSATAN' },
                { kode: '12', teks: 'PENGANGKUT' },
                { kode: '23', teks: 'PENERIMA FASILITAS' }
            ];

            let matched = false;
            opts.forEach(o => {
                const opt = document.createElement('option');
                opt.value = o.kode;
                opt.innerText = `[${o.kode}] ${o.teks}`;
                if (String(o.kode).trim() === String(selectedKode).trim()) {
                    opt.selected = true;
                    matched = true;
                }
                sel.appendChild(opt);
            });

            if (selectedKode && !matched) {
                const optExtra = document.createElement('option');
                optExtra.value = selectedKode;
                optExtra.innerText = `[${selectedKode}] Entitas ${selectedKode}`;
                optExtra.selected = true;
                sel.appendChild(optExtra);
            }

            tdKode.appendChild(sel);
            tr.appendChild(tdKode);

            // Col 3: Keterangan / Peran
            const tdKet = document.createElement('td');
            tdKet.className = 'entidx-desc';
            const updateKet = () => {
                const v = sel.value;
                if (v === '3' || v === '7' || v === '2') {
                    tdKet.innerHTML = '<span style="color:#059669; font-weight:600;">🏢 Perusahaan Sendiri</span> <span style="font-size:10px; color:var(--text-muted);">(Otomatis ambil Profil Perusahaan)</span>';
                } else if (v === '9' || v === '5' || v === '8' || v === '6') {
                    tdKet.innerHTML = '<span style="color:#2563eb; font-weight:600;">🤝 Mitra / Partner</span> <span style="font-size:10px; color:var(--text-muted);">(Bisa dobel-klik cari history)</span>';
                } else {
                    tdKet.innerHTML = '<span style="color:var(--text-muted);">(Kosong siap diisi)</span>';
                }
            };
            sel.addEventListener('change', updateKet);
            updateKet();
            tr.appendChild(tdKet);

            // Col 4: Action (Naik, Turun, Hapus)
            const tdAct = document.createElement('td');
            tdAct.style.textAlign = 'center';
            tdAct.innerHTML = `
                <div style="display:inline-flex; gap:3px;">
                    <button type="button" class="btn btn-secondary btn-xs" onclick="moveEntidxRow(this, -1)" title="Pindah Naik">⬆️</button>
                    <button type="button" class="btn btn-secondary btn-xs" onclick="moveEntidxRow(this, 1)" title="Pindah Turun">⬇️</button>
                    <button type="button" class="btn btn-danger btn-xs" onclick="deleteEntidxRow(this)" title="Hapus Baris">✕</button>
                </div>
            `;
            tr.appendChild(tdAct);

            tbody.appendChild(tr);
        }

        function addEntidxRow() {
            const tbody = document.getElementById('tbodyEntidxSetting');
            const count = tbody.querySelectorAll('tr.entidx-row').length;
            renderEntidxRow(count + 1, '');
            renumberEntidxRows();
        }

        function moveEntidxRow(btn, dir) {
            const tr = btn.closest('tr');
            if (!tr) return;
            if (dir === -1 && tr.previousElementSibling) {
                tr.parentNode.insertBefore(tr, tr.previousElementSibling);
            } else if (dir === 1 && tr.nextElementSibling) {
                tr.parentNode.insertBefore(tr.nextElementSibling, tr);
            }
            renumberEntidxRows();
        }

        function deleteEntidxRow(btn) {
            const tr = btn.closest('tr');
            if (tr) {
                tr.remove();
                renumberEntidxRows();
            }
        }

        function renumberEntidxRows() {
            const tbody = document.getElementById('tbodyEntidxSetting');
            const rows = tbody.querySelectorAll('tr.entidx-row');
            rows.forEach((r, idx) => {
                const numEl = r.querySelector('.entidx-num');
                if (numEl) numEl.innerText = idx + 1;
            });
        }

        async function saveEntidxSetting() {
            const bc = document.getElementById('selEntidxBc').value;
            const scope = document.getElementById('selEntidxScope').value;
            const currentCmp = (typeof currentCompanyCmpId !== 'undefined' && currentCompanyCmpId) 
                ? currentCompanyCmpId 
                : (document.getElementById('filterCmpId') ? document.getElementById('filterCmpId').value : '');

            const rows = document.querySelectorAll('#tbodyEntidxSetting tr.entidx-row');
            const items = [];
            rows.forEach((r, idx) => {
                const sel = r.querySelector('.entidx-kode-sel');
                if (sel && sel.value) {
                    items.push({
                        idx: idx + 1,
                        kode_entitas: sel.value.trim()
                    });
                }
            });

            if (items.length === 0) {
                Swal.fire('Perhatian', 'Tambahkan minimal satu baris entitas sebelum menyimpan.', 'warning');
                return;
            }

            try {
                const fd = new FormData();
                fd.append('bc', bc);
                fd.append('scope', scope);
                fd.append('cmp_id', currentCmp);
                fd.append('items', JSON.stringify(items));

                const res = await fetch('editor.php?action=save_entidx', {
                    method: 'POST',
                    body: fd
                });
                const json = await res.json();
                if (json.status === 'OK') {
                    showToast('✓ ' + json.message);
                    closeModal('modalSettingEntidx');
                } else {
                    Swal.fire('Gagal Simpan', json.message || 'Terjadi kesalahan.', 'error');
                }
            } catch (e) {
                Swal.fire('Error', e.message, 'error');
            }
        }

        // ============================================================
        // RESET ENTITAS DEFAULT PADA DOKUMEN AKTIF
        // ============================================================
        async function resetEntitasToDefault() {
            const activeAju = (currentData && currentData.header && currentData.header.nomor_aju)
                ? currentData.header.nomor_aju
                : (document.getElementById('entryAjuDisplay') ? document.getElementById('entryAjuDisplay').innerText.trim() : '');
            const activeBc = (currentData && currentData.header && currentData.header.kode_dokumen)
                ? String(currentData.header.kode_dokumen).trim()
                : (document.getElementById('selectBc') ? document.getElementById('selectBc').value : '40');
            const currentCmp = (typeof currentCompanyCmpId !== 'undefined' && currentCompanyCmpId) 
                ? currentCompanyCmpId 
                : (document.getElementById('filterCmpId') ? document.getElementById('filterCmpId').value : '');

            if (!activeAju || activeAju === 'MEMUAT...' || activeAju.length < 10) {
                Swal.fire('Perhatian', 'Silakan pilih atau muat dokumen AJU terlebih dahulu.', 'warning');
                return;
            }

            const confirmRes = await Swal.fire({
                title: 'Reset Entitas Default?',
                html: `Baris entitas pada AJU <b>${activeAju}</b> akan dikosongkan dan disusun ulang sesuai urutan setting default dokumen <b>BC ${activeBc}</b>.<br><br>Lanjutkan?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#2563eb',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Ya, Susun Ulang',
                cancelButtonText: 'Batal'
            });

            if (!confirmRes.isConfirmed) return;

            try {
                const fd = new FormData();
                fd.append('nomor_aju', activeAju);
                fd.append('kode_dokumen', activeBc);
                fd.append('cmp_id', currentCmp);

                const res = await fetch('editor.php?action=reset_entitas_default', {
                    method: 'POST',
                    body: fd
                });
                const json = await res.json();
                if (json.status === 'OK' && json.data) {
                    currentData.entitas = json.data;
                    renderTable('entitas', json.data);
                    showToast('✓ ' + json.message);
                } else {
                    Swal.fire('Gagal Reset', json.message || 'Terjadi kesalahan.', 'error');
                }
            } catch (e) {
                Swal.fire('Error', e.message, 'error');
            }
        }

        // ============================================================
        // MODAL SETTING LIST DOKUMEN DEFAULT (bc_ceisa_docidx)
        // ============================================================
        let docidxMasterOpts = [];

        function openModalSettingDocidx() {
            // Ambil opsi dokumen lampiran dari master jika tersedia
            docidxMasterOpts = currentReffOptions['DOKUMEN'] || [];
            
            // Set dokumen terpilih sesuai dokumen aktif
            const activeBc = currentData && currentData.header && currentData.header.kode_dokumen 
                ? String(currentData.header.kode_dokumen).trim() 
                : (document.getElementById('selectBc') ? document.getElementById('selectBc').value : '40');
            const cleanBc = activeBc.replace(/[^0-9]/g, '');
            const selBc = document.getElementById('selDocidxBc');
            if (selBc && cleanBc) {
                selBc.value = cleanBc;
            }

            // Atur pilihan scope perusahaan
            const optCmp = document.getElementById('optDocidxCmpScope');
            const currentCmp = (typeof currentCompanyCmpId !== 'undefined' && currentCompanyCmpId) 
                ? currentCompanyCmpId 
                : (document.getElementById('filterCmpId') ? document.getElementById('filterCmpId').value : '');
            if (optCmp) {
                if (currentCmp) {
                    optCmp.textContent = `🏢 Perusahaan: ${currentCmp}`;
                    optCmp.disabled = false;
                } else {
                    optCmp.textContent = `🏢 Perusahaan: (Tidak ada cmp_id)`;
                    optCmp.disabled = true;
                }
            }

            document.getElementById('modalSettingDocidx').style.display = 'flex';
            loadDocidxList();
        }

        async function loadDocidxList() {
            const bc = document.getElementById('selDocidxBc').value;
            const scope = document.getElementById('selDocidxScope').value;
            const currentCmp = (typeof currentCompanyCmpId !== 'undefined' && currentCompanyCmpId) 
                ? currentCompanyCmpId 
                : (document.getElementById('filterCmpId') ? document.getElementById('filterCmpId').value : '');
            const cmpId = (scope === 'company') ? currentCmp : '';

            const statusEl = document.getElementById('docidxStatusInfo');
            statusEl.innerText = 'Memuat konfigurasi...';

            const tbody = document.getElementById('tbodyDocidxSetting');
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:15px;">⏳ Mengambil data setting...</td></tr>';

            try {
                const res = await fetch(`editor.php?action=get_docidx&bc=${encodeURIComponent(bc)}&cmp_id=${encodeURIComponent(cmpId)}`);
                const json = await res.json();
                tbody.innerHTML = '';

                if (json.status !== 'OK' || !json.data || json.data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; color:var(--text-muted); padding:15px;">Belum ada urutan default dokumen lampiran untuk BC ini. Silakan klik <b>➕ Tambah Baris</b>.</td></tr>';
                    statusEl.innerText = 'Daftar kosong.';
                    return;
                }

                statusEl.innerText = json.is_custom_company 
                    ? `Status: Menggunakan setting khusus perusahaan (${json.cmp_id}).` 
                    : `Status: Menggunakan setting Global.`;

                json.data.forEach((item, i) => {
                    renderDocidxRow(item.idx || (i + 1), item.kode_dokumen);
                });
                renumberDocidxRows();
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; color:#ff4d4f; padding:15px;">Gagal memuat: ${e.message}</td></tr>`;
                statusEl.innerText = 'Error koneksi.';
            }
        }

        function renderDocidxRow(idx, selectedKode = '') {
            const tbody = document.getElementById('tbodyDocidxSetting');
            if (tbody.querySelector('td[colspan]')) {
                tbody.innerHTML = '';
            }

            const tr = document.createElement('tr');
            tr.className = 'docidx-row';

            // Col 1: Seri / idx
            const tdIdx = document.createElement('td');
            tdIdx.style.textAlign = 'center';
            tdIdx.style.fontWeight = '700';
            tdIdx.className = 'docidx-num';
            tdIdx.innerText = idx;
            tr.appendChild(tdIdx);

            // Col 2: Dropdown Kode Dokumen
            const tdKode = document.createElement('td');
            const sel = document.createElement('select');
            sel.className = 'cell-select docidx-kode-sel';
            sel.style.width = '100%';
            sel.style.fontWeight = '600';

            const optEmpty = document.createElement('option');
            optEmpty.value = '';
            optEmpty.innerText = '-- Pilih Kode Dokumen --';
            sel.appendChild(optEmpty);

            const opts = docidxMasterOpts.length > 0 ? docidxMasterOpts : [
                { kode: '630', teks: 'Surat Jalan' },
                { kode: '388', teks: 'FAKTUR PAJAK' },
                { kode: '380', teks: 'INVOICE' },
                { kode: '217', teks: 'PACKING LIST' },
                { kode: '315', teks: 'KONTRAK' },
                { kode: '705', teks: 'B/L (BILL OF LADING)' },
                { kode: '740', teks: 'AWB (AIR WAYBILL)' },
                { kode: '741', teks: 'MASTER AWB' },
                { kode: '999', teks: 'DOKUMEN LAINNYA' }
            ];

            let matched = false;
            opts.forEach(o => {
                const opt = document.createElement('option');
                opt.value = o.kode;
                opt.innerText = `[${o.kode}] ${o.teks}`;
                if (String(o.kode).trim() === String(selectedKode).trim()) {
                    opt.selected = true;
                    matched = true;
                }
                sel.appendChild(opt);
            });

            if (selectedKode && !matched) {
                const optExtra = document.createElement('option');
                optExtra.value = selectedKode;
                optExtra.innerText = `[${selectedKode}] Dokumen ${selectedKode}`;
                optExtra.selected = true;
                sel.appendChild(optExtra);
            }

            tdKode.appendChild(sel);
            tr.appendChild(tdKode);

            // Col 3: Keterangan / Fungsi Dokumen
            const tdKet = document.createElement('td');
            tdKet.className = 'docidx-desc';
            const updateKet = () => {
                const v = sel.value;
                if (v === '630') {
                    tdKet.innerHTML = '<span style="color:#059669; font-weight:600;">🚚 Dokumen Pengangkutan / Delivery</span> <span style="font-size:10px; color:var(--text-muted);">(Surat Jalan / Delivery Order)</span>';
                } else if (v === '380') {
                    tdKet.innerHTML = '<span style="color:#2563eb; font-weight:600;">💰 Dokumen Komersial</span> <span style="font-size:10px; color:var(--text-muted);">(Commercial Invoice)</span>';
                } else if (v === '388') {
                    tdKet.innerHTML = '<span style="color:#7c3aed; font-weight:600;">🧾 Dokumen Perpajakan</span> <span style="font-size:10px; color:var(--text-muted);">(Faktur Pajak Pertambahan Nilai)</span>';
                } else if (v === '217') {
                    tdKet.innerHTML = '<span style="color:#0284c7; font-weight:600;">📦 Dokumen Pengepakan</span> <span style="font-size:10px; color:var(--text-muted);">(Packing List Rincian Barang)</span>';
                } else if (v === '315') {
                    tdKet.innerHTML = '<span style="color:#d97706; font-weight:600;">📑 Perjanjian / Kontrak Kerja</span> <span style="font-size:10px; color:var(--text-muted);">(Sales Contract / PO)</span>';
                } else if (v === '705' || v === '740') {
                    tdKet.innerHTML = '<span style="color:#059669; font-weight:600;">🚢 Sarana Transportasi</span> <span style="font-size:10px; color:var(--text-muted);">(Konosemen B/L atau AWB)</span>';
                } else {
                    tdKet.innerHTML = '<span style="color:var(--text-muted);">(Dokumen Pelengkap Pabean)</span>';
                }
            };
            sel.addEventListener('change', updateKet);
            updateKet();
            tr.appendChild(tdKet);

            // Col 4: Action (Naik, Turun, Hapus)
            const tdAct = document.createElement('td');
            tdAct.style.textAlign = 'center';
            tdAct.innerHTML = `
                <div style="display:inline-flex; gap:3px;">
                    <button type="button" class="btn btn-secondary btn-xs" onclick="moveDocidxRow(this, -1)" title="Pindah Naik">⬆️</button>
                    <button type="button" class="btn btn-secondary btn-xs" onclick="moveDocidxRow(this, 1)" title="Pindah Turun">⬇️</button>
                    <button type="button" class="btn btn-danger btn-xs" onclick="deleteDocidxRow(this)" title="Hapus Baris">✕</button>
                </div>
            `;
            tr.appendChild(tdAct);

            tbody.appendChild(tr);
        }

        function addDocidxRow() {
            const tbody = document.getElementById('tbodyDocidxSetting');
            const count = tbody.querySelectorAll('tr.docidx-row').length;
            renderDocidxRow(count + 1, '');
            renumberDocidxRows();
        }

        function moveDocidxRow(btn, dir) {
            const tr = btn.closest('tr');
            if (!tr) return;
            if (dir === -1 && tr.previousElementSibling) {
                tr.parentNode.insertBefore(tr, tr.previousElementSibling);
            } else if (dir === 1 && tr.nextElementSibling) {
                tr.parentNode.insertBefore(tr.nextElementSibling, tr);
            }
            renumberDocidxRows();
        }

        function deleteDocidxRow(btn) {
            const tr = btn.closest('tr');
            if (tr) {
                tr.remove();
                renumberDocidxRows();
            }
        }

        function renumberDocidxRows() {
            const tbody = document.getElementById('tbodyDocidxSetting');
            const rows = tbody.querySelectorAll('tr.docidx-row');
            rows.forEach((r, idx) => {
                const numEl = r.querySelector('.docidx-num');
                if (numEl) numEl.innerText = idx + 1;
            });
        }

        async function saveDocidxSetting() {
            const bc = document.getElementById('selDocidxBc').value;
            const scope = document.getElementById('selDocidxScope').value;
            const currentCmp = (typeof currentCompanyCmpId !== 'undefined' && currentCompanyCmpId) 
                ? currentCompanyCmpId 
                : (document.getElementById('filterCmpId') ? document.getElementById('filterCmpId').value : '');

            const rows = document.querySelectorAll('#tbodyDocidxSetting tr.docidx-row');
            const items = [];
            rows.forEach((r, idx) => {
                const sel = r.querySelector('.docidx-kode-sel');
                if (sel && sel.value) {
                    items.push({
                        idx: idx + 1,
                        kode_dokumen: sel.value.trim()
                    });
                }
            });

            if (items.length === 0) {
                Swal.fire('Perhatian', 'Tambahkan minimal satu baris dokumen lampiran sebelum menyimpan.', 'warning');
                return;
            }

            try {
                const fd = new FormData();
                fd.append('bc', bc);
                fd.append('scope', scope);
                fd.append('cmp_id', currentCmp);
                fd.append('items', JSON.stringify(items));

                const res = await fetch('editor.php?action=save_docidx', {
                    method: 'POST',
                    body: fd
                });
                const json = await res.json();
                if (json.status === 'OK') {
                    showToast('✓ ' + json.message);
                    closeModal('modalSettingDocidx');
                } else {
                    Swal.fire('Gagal Simpan', json.message || 'Terjadi kesalahan.', 'error');
                }
            } catch (e) {
                Swal.fire('Error', e.message, 'error');
            }
        }

        // ============================================================
        // RESET DOKUMEN DEFAULT PADA DOKUMEN AKTIF
        // ============================================================
        async function resetDokumenToDefault() {
            const activeAju = (currentData && currentData.header && currentData.header.nomor_aju)
                ? currentData.header.nomor_aju
                : (document.getElementById('entryAjuDisplay') ? document.getElementById('entryAjuDisplay').innerText.trim() : '');
            const activeBc = (currentData && currentData.header && currentData.header.kode_dokumen)
                ? String(currentData.header.kode_dokumen).trim()
                : (document.getElementById('selectBc') ? document.getElementById('selectBc').value : '40');
            const currentCmp = (typeof currentCompanyCmpId !== 'undefined' && currentCompanyCmpId) 
                ? currentCompanyCmpId 
                : (document.getElementById('filterCmpId') ? document.getElementById('filterCmpId').value : '');

            if (!activeAju || activeAju === 'MEMUAT...' || activeAju.length < 10) {
                Swal.fire('Perhatian', 'Silakan pilih atau muat dokumen AJU terlebih dahulu.', 'warning');
                return;
            }

            const confirmRes = await Swal.fire({
                title: 'Reset Dokumen Lampiran Default?',
                html: `Baris dokumen lampiran pada AJU <b>${activeAju}</b> akan dikosongkan dan disusun ulang sesuai urutan setting default dokumen <b>BC ${activeBc}</b>.<br><br>Lanjutkan?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#2563eb',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Ya, Susun Ulang',
                cancelButtonText: 'Batal'
            });

            if (!confirmRes.isConfirmed) return;

            try {
                const fd = new FormData();
                fd.append('nomor_aju', activeAju);
                fd.append('kode_dokumen', activeBc);
                fd.append('cmp_id', currentCmp);

                const res = await fetch('editor.php?action=reset_dokumen_default', {
                    method: 'POST',
                    body: fd
                });
                const json = await res.json();
                if (json.status === 'OK' && json.data) {
                    currentData.dokumen = json.data;
                    renderTable('dokumen', json.data);
                    showToast('✓ ' + json.message);
                } else {
                    Swal.fire('Gagal Reset', json.message || 'Terjadi kesalahan.', 'error');
                }
            } catch (e) {
                Swal.fire('Error', e.message, 'error');
            }
        }

        // ============================================================
        // MODAL 2: CARI ENTITAS DARI HISTORY (LOOKUP ENTITAS)
        // ============================================================
        let activeEntitasTr = null;
        let lookupEntitasDebounceTimer = null;

        function openLookupEntitasModal(tr) {
            activeEntitasTr = tr;
            const selKode = tr.querySelector('select[data-col="kode_entitas"]');
            const rowKode = selKode && selKode.value ? selKode.value.trim() : '';
            const filterRole = document.getElementById('lookupEntitasRoleFilter');

            if (filterRole) {
                // Sinkronkan daftar opsi dari master referensi ENTITAS jika tersedia
                const entMaster = currentReffOptions['ENTITAS'] || [];
                if (entMaster.length > 0) {
                    const prevVal = rowKode || filterRole.value;
                    filterRole.innerHTML = '<option value="">Semua Role Entitas</option>';
                    entMaster.forEach(o => {
                        const opt = document.createElement('option');
                        opt.value = o.kode;
                        opt.innerText = `${o.teks} (${o.kode})`;
                        filterRole.appendChild(opt);
                    });
                } else if (rowKode) {
                    let optExists = Array.from(filterRole.options).some(o => String(o.value).trim() === rowKode);
                    if (!optExists) {
                        const opt = document.createElement('option');
                        opt.value = rowKode;
                        opt.innerText = `Role (${rowKode})`;
                        filterRole.appendChild(opt);
                    }
                }

                // Otomatis pilih role sesuai kode_entitas baris aktif
                if (rowKode) {
                    filterRole.value = rowKode;
                } else {
                    filterRole.value = '';
                }
            }

            const inpQuery = document.getElementById('lookupEntitasQuery');
            if (inpQuery) {
                inpQuery.value = '';
            }

            document.getElementById('modalLookupEntitas').style.display = 'flex';
            fetchLookupEntitas();
            if (inpQuery) setTimeout(() => { inpQuery.focus(); }, 100);
        }

        function debounceLookupEntitas() {
            clearTimeout(lookupEntitasDebounceTimer);
            lookupEntitasDebounceTimer = setTimeout(fetchLookupEntitas, 300);
        }

        async function fetchLookupEntitas() {
            const q = document.getElementById('lookupEntitasQuery') ? document.getElementById('lookupEntitasQuery').value.trim() : '';
            const role = document.getElementById('lookupEntitasRoleFilter') ? document.getElementById('lookupEntitasRoleFilter').value.trim() : '';
            const currentCmp = (typeof currentCompanyCmpId !== 'undefined' && currentCompanyCmpId) 
                ? currentCompanyCmpId 
                : (document.getElementById('filterCmpId') ? document.getElementById('filterCmpId').value : '');

            const tbody = document.getElementById('tbodyLookupEntitas');
            const countEl = document.getElementById('lookupEntitasCount');
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:15px;">⏳ Mencari data entitas dari history...</td></tr>';

            try {
                const url = `editor.php?action=search_entitas_history&q=${encodeURIComponent(q)}&kode_entitas=${encodeURIComponent(role)}&cmp_id=${encodeURIComponent(currentCmp)}`;
                const res = await fetch(url);
                const json = await res.json();
                tbody.innerHTML = '';

                if (json.status !== 'OK' || !json.data || json.data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; color:var(--text-muted); padding:15px;">Tidak ada riwayat entitas yang cocok dengan pencarian.</td></tr>';
                    countEl.innerText = '0 entitas ditemukan';
                    return;
                }

                countEl.innerText = `${json.data.length} entitas ditemukan`;

                json.data.forEach((row, i) => {
                    const tr = document.createElement('tr');
                    tr.style.cursor = 'pointer';
                    tr.title = 'Dobel-klik untuk memilih';
                    tr.innerHTML = `
                        <td style="text-align:center; font-weight:600; font-size:10px; color:var(--text-muted);">${i + 1}</td>
                        <td style="font-weight:700; font-size:10.5px; color:var(--primary);">${escapeHtml(row.nama_entitas)}</td>
                        <td style="font-family:monospace; font-size:10.5px; font-weight:600;">${escapeHtml(row.nomor_identitas || '-')}</td>
                        <td><span class="badge" style="background:var(--bg-secondary); border:1px solid var(--border); font-size:9.5px; padding:1px 5px; font-weight:600;">${escapeHtml(row.peran_entitas || row.kode_entitas || '-')}</span></td>
                        <td style="font-size:10px; max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${escapeHtml(row.alamat_entitas || '')}">${escapeHtml(row.alamat_entitas || '-')}</td>
                        <td style="font-size:10px; font-family:monospace;">${escapeHtml(row.nib_entitas || '-')}</td>
                        <td style="text-align:center; font-weight:700; font-size:10px;">${escapeHtml(row.kode_negara || '-')}</td>
                        <td style="text-align:center;">
                            <button type="button" class="btn btn-primary btn-xs" style="font-weight:600; padding:1px 8px; font-size:10px; border-radius:3px;">Pilih</button>
                        </td>
                    `;

                    // Klik tombol pilih
                    const btnPilih = tr.querySelector('button');
                    btnPilih.onclick = (e) => {
                        e.stopPropagation();
                        applySelectedLookupEntitas(row);
                    };

                    // Dobel klik baris
                    tr.ondblclick = () => {
                        applySelectedLookupEntitas(row);
                    };

                    tbody.appendChild(tr);
                });
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; color:#ff4d4f; padding:15px;">Error: ${e.message}</td></tr>`;
                countEl.innerText = 'Error';
            }
        }

        function applySelectedLookupEntitas(item) {
            if (!activeEntitasTr) {
                closeModal('modalLookupEntitas');
                return;
            }

            const setFieldVal = (col, val) => {
                // Cari elemen form (input, textarea, select) baik yang memiliki data-col langsung atau berada dalam td[data-col]
                let el = activeEntitasTr.querySelector(`input[data-col="${col}"], textarea[data-col="${col}"], select[data-col="${col}"]`);
                if (!el) {
                    const td = activeEntitasTr.querySelector(`td[data-col="${col}"]`);
                    if (td) {
                        el = td.querySelector('input, textarea, select');
                    }
                }
                if (!el) return;

                const cleanVal = (val !== null && val !== undefined) ? String(val).trim() : '';

                if (el.tagName === 'TEXTAREA') {
                    el.value = cleanVal;
                    if (typeof autoResizeTextarea === 'function') {
                        autoResizeTextarea(el);
                    }
                    el.dispatchEvent(new Event('input', { bubbles: true }));
                    el.dispatchEvent(new Event('change', { bubbles: true }));
                } else if (el.tagName === 'SELECT') {
                    if (cleanVal !== '') {
                        let optFound = Array.from(el.options).some(o => String(o.value).trim() === cleanVal);
                        if (!optFound) {
                            const opt = document.createElement('option');
                            opt.value = cleanVal;
                            opt.innerText = `${cleanVal} | (Kode ${cleanVal})`;
                            el.appendChild(opt);
                        }
                        el.value = cleanVal;
                    } else {
                        el.value = '';
                    }
                    if (typeof autoSizeSelect === 'function') {
                        autoSizeSelect(el);
                    }
                    el.dispatchEvent(new Event('change', { bubbles: true }));
                } else if (el.tagName === 'INPUT') {
                    if (el.classList.contains('cell-date-input')) {
                        const cleanDate = (typeof formatDateYmd === 'function') ? formatDateYmd(cleanVal) : cleanVal;
                        el.value = cleanDate;
                        const td = el.closest('td');
                        if (td) {
                            const nativeInp = td.querySelector('.cell-date-native');
                            if (nativeInp) nativeInp.value = cleanDate;
                        }
                    } else {
                        el.value = cleanVal;
                        if (typeof autoSizeCellInput === 'function') {
                            autoSizeCellInput(el, '', false);
                        }
                    }
                    el.dispatchEvent(new Event('input', { bubbles: true }));
                    el.dispatchEvent(new Event('change', { bubbles: true }));
                }
            };

            // Timpa field entitas aktif dengan data hasil history
            setFieldVal('nama_entitas', item.nama_entitas);
            setFieldVal('nomor_identitas', item.nomor_identitas);
            setFieldVal('alamat_entitas', item.alamat_entitas);
            setFieldVal('nib_entitas', item.nib_entitas);
            if (item.kode_jenis_identitas) {
                setFieldVal('kode_jenis_identitas', item.kode_jenis_identitas);
            }
            if (item.nomor_ijin_entitas) {
                setFieldVal('nomor_ijin_entitas', item.nomor_ijin_entitas);
            }
            if (item.tanggal_ijin_entitas) {
                setFieldVal('tanggal_ijin_entitas', item.tanggal_ijin_entitas);
            }
            if (item.kode_negara) {
                setFieldVal('kode_negara', item.kode_negara);
            }

            // Jika kode_entitas pada baris masih belum ada/kosong, isi dengan kode_entitas dari history
            const currentKodeEnt = activeEntitasTr.querySelector('select[data-col="kode_entitas"]');
            if (currentKodeEnt && (!currentKodeEnt.value || currentKodeEnt.value.trim() === '') && item.kode_entitas) {
                setFieldVal('kode_entitas', item.kode_entitas);
            }

            // Beri efek highlight visual pada baris yang baru saja diisi
            try {
                activeEntitasTr.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                const origBg = activeEntitasTr.style.backgroundColor;
                activeEntitasTr.style.transition = 'background-color 0.4s ease';
                activeEntitasTr.style.backgroundColor = 'rgba(37, 99, 235, 0.25)';
                setTimeout(() => {
                    activeEntitasTr.style.backgroundColor = origBg || '';
                }, 1200);
            } catch(e) {}

            closeModal('modalLookupEntitas');
            showToast(`✓ Entitas '${item.nama_entitas}' berhasil diterapkan!`);
        }

        function copyPreviewText() {
            const txt = document.getElementById('previewContent').innerText;
            navigator.clipboard.writeText(txt).then(() => {
                showToast('JSON berhasil disalin!');
            });
        }

        function showToast(msg) {
            const t = document.getElementById('toast');
            t.innerText = msg;
            t.style.display = 'flex';
            setTimeout(() => { t.style.display = 'none'; }, 2500);
        }

        // ============================================================
        // EXCEL INTEROP: COPY KE EXCEL & PASTE DARI EXCEL (KLIK KANAN)
        // ============================================================
        let activeContextMenuTable = null;
        let activeContextMenuTr = null;
        let parsedExcelRows = [];

        // Daftar tabel yang mendukung fitur Copy / Paste Excel
        const EXCEL_SUPPORTED_TABLES = [
            'barang', 'bahanbaku', 'barangtarif', 'bahanbakutarif',
            'dokumen', 'barangdokumen', 'bahanbakudokumen', 'kemasan',
            'pengangkut', 'kontainer', 'komponenbiaya', 'bankdevisa',
            'jaminan', 'barangvd', 'barangspekkhusus', 'entitas'
        ];

        function getVisibleGridCols(tableKey) {
            const conf = gridConfigs[tableKey] || [];
            return conf.filter(c => {
                // Sembunyikan kolom jika ada rule visibility = 0
                if (tableKey === 'barang' && currentVarRules && currentVarRules.barang && currentVarRules.barang[c.key]) {
                    if (currentVarRules.barang[c.key].visible === 0) return false;
                }
                return true;
            });
        }

        function cleanExcelNumber(val) {
            if (val === null || val === undefined) return '';
            let s = String(val).replace(/[^0-9,.-]/g, '').trim();
            if (!s) return '';

            const lastDot = s.lastIndexOf('.');
            const lastComma = s.lastIndexOf(',');

            if (lastDot > -1 && lastComma > -1) {
                if (lastComma > lastDot) {
                    // Format Indo/Eropa: 1.250,50 -> ribuan titik, desimal koma
                    s = s.replace(/\./g, '').replace(',', '.');
                } else {
                    // Format US: 1,250.50 -> ribuan koma, desimal titik
                    s = s.replace(/,/g, '');
                }
            } else if (lastComma > -1) {
                // Misal "1250,50" -> desimal koma
                s = s.replace(',', '.');
            }
            return s;
        }

        function execExcelContextMenu(action) {
            const menu = document.getElementById('editorContextMenu');
            if (menu) menu.style.display = 'none';

            const tblKey = activeContextMenuTable || activeContextTable;
            if (!tblKey) return;

            if (action === 'copy_all') {
                copyTableToClipboard(tblKey, false);
            } else if (action === 'copy_selected') {
                copyTableToClipboard(tblKey, true);
            } else if (action === 'paste') {
                openModalPasteExcel(tblKey);
            } else if (action === 'copy_template') {
                copyTableHeadersForExcel(tblKey);
            }
        }

        function getRowDataFromDom(tr, cols) {
            const obj = {};
            cols.forEach(c => {
                let v = '';
                const td = tr.querySelector(`td[data-col="${c.key}"]`);
                if (td) {
                    const inp = td.querySelector('input, select, textarea');
                    if (inp) {
                        v = inp.value !== undefined ? inp.value.trim() : '';
                        if (inp.classList.contains('cell-number') || inp.type === 'number') {
                            v = stripNumberCommas(v);
                        }
                    } else {
                        v = td.innerText ? td.innerText.trim() : '';
                    }
                }
                obj[c.key] = v;
            });
            return obj;
        }

        function copyTableToClipboard(tableKey, onlySelected = false) {
            const cols = getVisibleGridCols(tableKey);
            if (cols.length === 0) return;

            const headerRow = cols.map(c => c.label || c.key).join('\t');
            const dataLines = [];

            const tbody = document.querySelector(`#table-${tableKey} tbody`);
            let trs = [];

            if (onlySelected && activeContextMenuTr) {
                trs = [activeContextMenuTr];
            } else if (tbody) {
                trs = Array.from(tbody.querySelectorAll('tr:not(.tr-empty)'));
            }

            if (trs.length === 0) {
                showWarning(`Tabel ${getTabTitle(tableKey)} masih kosong. Tidak ada data yang bisa disalin.`);
                return;
            }

            trs.forEach(tr => {
                const rowObj = getRowDataFromDom(tr, cols);
                const lineVals = cols.map(c => {
                    let val = rowObj[c.key] !== undefined ? String(rowObj[c.key]) : '';
                    // Bersihkan newline atau tab agar tidak merusak format Excel TSV
                    val = val.replace(/[\r\n\t]+/g, ' ').trim();
                    return val;
                });
                dataLines.push(lineVals.join('\t'));
            });

            const fullTsv = headerRow + '\r\n' + dataLines.join('\r\n');

            navigator.clipboard.writeText(fullTsv).then(() => {
                showToast(`✓ Berhasil menyalin ${dataLines.length} baris ${getTabTitle(tableKey)} ke clipboard (siap paste di Excel)!`);
            }).catch(err => {
                // Fallback jika API navigator.clipboard ditolak
                const ta = document.createElement('textarea');
                ta.value = fullTsv;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                showToast(`✓ Berhasil menyalin ${dataLines.length} baris ${getTabTitle(tableKey)} ke clipboard!`);
            });
        }

        function copyTableHeadersForExcel(customTblKey) {
            const tblKey = customTblKey || activeContextMenuTable || activeTab;
            const cols = getVisibleGridCols(tblKey);
            if (cols.length === 0) return;

            const headerLabels = cols.map(c => c.label || c.key).join('\t');
            navigator.clipboard.writeText(headerLabels).then(() => {
                showToast(`✓ Header template kolom ${getTabTitle(tblKey)} berhasil disalin (siap paste di baris 1 Excel)!`);
            });
        }

        function openModalPasteExcel(tableKey) {
            activeContextMenuTable = tableKey;
            parsedExcelRows = [];

            const titleEl = document.getElementById('pasteExcelModalTitle');
            if (titleEl) {
                titleEl.innerText = `Paste Data Excel -> ${getTabTitle(tableKey)} (${tableKey})`;
            }

            const ta = document.getElementById('pasteExcelTextarea');
            if (ta) {
                ta.value = '';
            }

            const stats = document.getElementById('pasteExcelStats');
            if (stats) {
                stats.innerText = '0 baris terdeteksi';
            }

            // Siapkan header preview
            const theadTr = document.getElementById('theadPasteExcelPreview');
            const cols = getVisibleGridCols(tableKey);
            if (theadTr) {
                theadTr.innerHTML = '<th style="width:36px; text-align:center;">#</th>' + 
                    cols.map(c => `<th style="padding:4px 6px; font-size:9.5px; white-space:nowrap;">${escapeHtml(c.label || c.key)}</th>`).join('');
            }

            const tbody = document.getElementById('tbodyPasteExcelPreview');
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="' + (cols.length + 1) + '" style="text-align:center; color:var(--text-muted); padding:20px;">Silakan tempel (Ctrl+V) data baris dari Excel pada kotak di atas.</td></tr>';
            }

            document.getElementById('modalPasteExcel').style.display = 'flex';
            if (ta) setTimeout(() => { ta.focus(); }, 100);
        }

        function parseExcelPreview() {
            const tableKey = activeContextMenuTable;
            if (!tableKey) return;

            const ta = document.getElementById('pasteExcelTextarea');
            const rawText = ta ? ta.value : '';
            const cols = getVisibleGridCols(tableKey);
            const stats = document.getElementById('pasteExcelStats');
            const tbody = document.getElementById('tbodyPasteExcelPreview');

            parsedExcelRows = [];

            if (!rawText.trim()) {
                if (stats) stats.innerText = '0 baris terdeteksi';
                if (tbody) tbody.innerHTML = '<tr><td colspan="' + (cols.length + 1) + '" style="text-align:center; color:var(--text-muted); padding:20px;">Belum ada data yang ditempel.</td></tr>';
                return;
            }

            const lines = rawText.split(/\r?\n/).filter(l => l.trim() !== '');
            if (lines.length === 0) return;

            // Cek apakah baris pertama adalah Header teks Excel
            let startIndex = 0;
            const firstRowCells = lines[0].split('\t').map(c => c.trim().toLowerCase());
            const colKeysLower = cols.map(c => (c.key || '').toLowerCase());
            const colLabelsLower = cols.map(c => (c.label || '').toLowerCase());

            let headerMatches = 0;
            firstRowCells.forEach(cell => {
                if (colKeysLower.includes(cell) || colLabelsLower.includes(cell) || cell.includes('seri') || cell.includes('uraian') || cell.includes('kode')) {
                    headerMatches++;
                }
            });

            if (headerMatches >= 2 || (firstRowCells.length > 0 && isNaN(firstRowCells[0]) && colKeysLower.includes(firstRowCells[0]))) {
                startIndex = 1; // Lewati baris pertama karena merupakan header
            }

            for (let i = startIndex; i < lines.length; i++) {
                const line = lines[i];
                if (!line.trim()) continue;
                const cells = line.split('\t');
                const rowObj = {};

                cols.forEach((col, cIdx) => {
                    let cellVal = cells[cIdx] !== undefined ? cells[cIdx].trim() : '';
                    
                    // Pembersihan angka jika tipe number
                    if (col.type === 'number') {
                        cellVal = cleanExcelNumber(cellVal);
                    } else if (col.type === 'date') {
                        cellVal = formatDateYmd(cellVal);
                    }
                    rowObj[col.key] = cellVal;
                });

                parsedExcelRows.push(rowObj);
            }

            if (stats) {
                stats.innerText = `${parsedExcelRows.length} baris terdeteksi (siap diimpor)`;
            }

            // Render preview maksimal 10 baris pertama
            if (tbody) {
                tbody.innerHTML = '';
                const previewSlice = parsedExcelRows.slice(0, 15);
                previewSlice.forEach((r, idx) => {
                    const tr = document.createElement('tr');
                    let tds = `<td style="text-align:center; font-weight:700; color:var(--text-muted);">${idx + 1}</td>`;
                    cols.forEach(c => {
                        const val = r[c.key] !== undefined ? r[c.key] : '';
                        tds += `<td style="padding:3px 6px; font-size:10px; max-width:140px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${escapeHtml(val)}</td>`;
                    });
                    tr.innerHTML = tds;
                    tbody.appendChild(tr);
                });

                if (parsedExcelRows.length > 15) {
                    const trMore = document.createElement('tr');
                    trMore.innerHTML = `<td colspan="${cols.length + 1}" style="text-align:center; font-style:italic; color:var(--text-muted); padding:4px;">... dan ${parsedExcelRows.length - 15} baris lainnya</td>`;
                    tbody.appendChild(trMore);
                }
            }
        }

        function applyParsedExcelData() {
            const tableKey = activeContextMenuTable;
            if (!tableKey || parsedExcelRows.length === 0) {
                showWarning('Belum ada data valid yang dapat dimasukkan dari Excel.');
                return;
            }

            const modeEl = document.querySelector('input[name="pasteExcelMode"]:checked');
            const mode = modeEl ? modeEl.value : 'append'; // 'append' atau 'replace'
            const tbody = document.querySelector(`#table-${tableKey} tbody`);
            if (!tbody) return;

            const cols = gridConfigs[tableKey] || [];
            let currentCount = 0;

            if (mode === 'replace') {
                tbody.innerHTML = '';
                if (currentData) {
                    currentData[tableKey] = [];
                }
            } else {
                const emptyTr = tbody.querySelector('.tr-empty');
                if (emptyTr) emptyTr.remove();
                currentCount = tbody.querySelectorAll('tr:not(.tr-empty)').length;
            }

            const frag = document.createDocumentFragment();

            parsedExcelRows.forEach((rowObj, idx) => {
                const finalRow = Object.assign({}, rowObj);

                // Auto numbering seri jika kosong atau mode append
                const targetSeri = currentCount + idx + 1;
                if (!finalRow.seri && cols.some(c => c.key === 'seri')) {
                    finalRow.seri = targetSeri;
                }
                if (!finalRow.seri_barang && cols.some(c => c.key === 'seri_barang')) {
                    finalRow.seri_barang = targetSeri;
                }
                if (!finalRow.seri_bahan_baku && cols.some(c => c.key === 'seri_bahan_baku')) {
                    finalRow.seri_bahan_baku = targetSeri;
                }

                // Tambahkan ke currentData
                if (currentData && Array.isArray(currentData[tableKey])) {
                    currentData[tableKey].push(finalRow);
                }

                const tr = createGridRow(tableKey, finalRow, currentCount + idx);
                frag.appendChild(tr);
            });

            tbody.appendChild(frag);

            // Update badge dan total footer
            const totalCount = tbody.querySelectorAll('tr:not(.tr-empty)').length;
            const badge = document.getElementById('count-' + tableKey);
            if (badge) badge.innerText = totalCount;

            updateTableTotals(tableKey);

            closeModal('modalPasteExcel');
            showSuccess(`Berhasil memasukkan ${parsedExcelRows.length} baris ke tabel ${getTabTitle(tableKey)}! Total baris sekarang: ${totalCount}.`);
        }

        function escapeHtml(text) {
            if (!text) return '';
            return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // Multi-Theme Manager (Dark & Light)
        function setTheme(theme) {
            if (theme !== 'light' && theme !== 'dark') theme = 'light';
            document.documentElement.setAttribute('data-theme', theme);
            document.body.setAttribute('data-theme', theme);
            localStorage.setItem('ceisa_theme', theme);
            document.querySelectorAll('.theme-btn').forEach(b => {
                b.classList.toggle('active', b.dataset.theme === theme);
            });
        }
        setTheme(localStorage.getItem('ceisa_theme') || 'light');

        window.addEventListener('message', function(e) {
            if (e.data && e.data.type === 'SET_THEME') {
                setTheme(e.data.theme);
            }
            if (e.data && e.data.type === 'SET_USER_ROLE') {
                const admin = !!e.data.isAdmin;
                document.body.classList.toggle('is-admin', admin);
                document.documentElement.classList.toggle('is-admin', admin);
                document.body.setAttribute('data-is-admin', admin ? '1' : '0');
            }
        });

        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(() => {
                document.querySelectorAll('table.datagrid td select.cell-select').forEach(autoSizeSelect);
            });
        }

        // =============================================================
        // OTOMASI TARIF, PUNGUTAN & TRACE HELPER FUNCTIONS
        // =============================================================

        async function autoGenerateAllTarifs() {
            if (!currentAju) {
                Swal.fire('Perhatian', 'Silakan buka atau pilih dokumen AJU terlebih dahulu.', 'warning');
                return;
            }
            const resConfirm = await Swal.fire({
                title: '⚡ Auto-Generate Semua Tarif & Pungutan?',
                html: `Sistem akan otomatis menghitung secara menyeluruh:<br>
                    <div style="text-align:left; font-size:12px; margin-top:8px; line-height:1.7;">
                    1. <b>Barang Tarif</b> untuk seluruh seri barang (BM, PPH, PPN).<br>
                    2. <b>Bahan Baku Tarif</b> (BM, PPN, PPH) untuk seluruh bahan baku impor/lokal.<br>
                    3. <b>Pungutan Dokumen</b> (bc_ceisa_pungutan) sesuai akumulasi tarif CEISA 4.0.<br>
                    4. <b>Sinkronisasi CIF & Total Header</b> (mencegah error Header CIF tidak sama dengan Barang).
                    </div><br>
                    Lanjutkan kalkulasi otomatis?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#3b82f6',
                confirmButtonText: '⚡ Ya, Jalankan',
                cancelButtonText: 'Batal'
            });
            if (!resConfirm.isConfirmed) return;

            Swal.fire({
                title: 'Sedang Mengkalkulasi...',
                text: 'Memproses tarif barang, tarif bahan baku, pungutan, dan rekonsiliasi CIF header...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            try {
                const resp = await fetch(`editor.php?action=auto_generate_all_tarifs&nomor_aju=${encodeURIComponent(currentAju)}`);
                const data = await resp.json();
                if (data.status === 'OK') {
                    const bt = data.barang_tarif || {};
                    const bbt = data.bahanbaku_tarif || {};
                    const pung = data.pungutan || {};
                    const hdr = data.header_sync || {};

                    let summaryHtml = `<div style="text-align:left; font-size:12px; line-height:1.7;">
                        <b>✅ Hasil Kalkulasi Otomatis:</b><br>
                        • <b>Barang Tarif:</b> ${bt.inserted || 0} baris tarif berhasil digenerate.<br>
                        • <b>Bahan Baku Tarif:</b> ${bbt.inserted || 0} baris tarif berhasil digenerate (${bbt.total_bb || 0} bahan baku).<br>
                        • <b>Pungutan Terhitung:</b> PPN: Rp ${Number(pung.ppn || 0).toLocaleString('id-ID')}, BM: Rp ${Number(pung.bm || 0).toLocaleString('id-ID')}, PPH: Rp ${Number(pung.pph || 0).toLocaleString('id-ID')}.<br>
                        • <b>Header CIF Disinkronkan:</b> Rp ${Number(hdr.cif || 0).toLocaleString('id-ID', {minimumFractionDigits: 2})}.
                    </div>`;

                    await Swal.fire({
                        title: 'Kalkulasi Berhasil!',
                        html: summaryHtml,
                        icon: 'success'
                    });

                    await reloadCurrentAju();
                    loadRecapTarif();
                } else {
                    Swal.fire('Gagal Kalkulasi', data.message || 'Terjadi kesalahan sistem.', 'error');
                }
            } catch (e) {
                Swal.fire('Error', e.message || 'Gagal memanggil API kalkulasi.', 'error');
            }
        }

        async function generateBarangTarif() {
            if (!currentAju) {
                Swal.fire('Perhatian', 'Silakan buka atau pilih dokumen AJU terlebih dahulu.', 'warning');
                return;
            }
            Swal.fire({
                title: 'Mengkalkulasi Barang Tarif...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            try {
                const resp = await fetch(`editor.php?action=generate_barang_tarif&nomor_aju=${encodeURIComponent(currentAju)}`);
                const data = await resp.json();
                if (data.status === 'OK') {
                    await Swal.fire('Sukses', data.message || 'Barang tarif berhasil digenerate.', 'success');
                    await reloadCurrentAju();
                    loadRecapTarif();
                } else {
                    Swal.fire('Gagal', data.message || 'Gagal generate barang tarif.', 'error');
                }
            } catch(e) {
                Swal.fire('Error', e.message, 'error');
            }
        }

        async function generateBahanBakuTarif() {
            if (!currentAju) {
                Swal.fire('Perhatian', 'Silakan buka atau pilih dokumen AJU terlebih dahulu.', 'warning');
                return;
            }
            Swal.fire({
                title: 'Mengkalkulasi Bahan Baku Tarif...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            try {
                const resp = await fetch(`editor.php?action=generate_bahanbaku_tarif&nomor_aju=${encodeURIComponent(currentAju)}`);
                const data = await resp.json();
                if (data.status === 'OK') {
                    await Swal.fire('Sukses', data.message || 'Bahan baku tarif berhasil digenerate.', 'success');
                    await reloadCurrentAju();
                    loadRecapTarif();
                } else {
                    Swal.fire('Gagal', data.message || 'Gagal generate bahan baku tarif.', 'error');
                }
            } catch(e) {
                Swal.fire('Error', e.message, 'error');
            }
        }

        async function fixHsCode() {
            if (!currentAju) return;
            Swal.fire({
                title: 'Membersihkan Format HS Code...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            try {
                const resp = await fetch(`editor.php?action=fix_hs_code&nomor_aju=${encodeURIComponent(currentAju)}`);
                const data = await resp.json();
                if (data.status === 'OK') {
                    Swal.fire('Sukses', data.message || 'Format HS Code dibersihkan (hanya digit).', 'success');
                    await reloadCurrentAju();
                } else {
                    Swal.fire('Gagal', data.message, 'error');
                }
            } catch(e) {
                Swal.fire('Error', e.message, 'error');
            }
        }

        async function fixNetto() {
            if (!currentAju) return;
            Swal.fire({
                title: 'Menyesuaikan Netto & Bruto...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            try {
                const resp = await fetch(`editor.php?action=fix_netto&nomor_aju=${encodeURIComponent(currentAju)}`);
                const data = await resp.json();
                if (data.status === 'OK') {
                    Swal.fire('Sukses', data.message || 'Netto & Bruto berhasil disinkronkan.', 'success');
                    await reloadCurrentAju();
                } else {
                    Swal.fire('Gagal', data.message, 'error');
                }
            } catch(e) {
                Swal.fire('Error', e.message, 'error');
            }
        }

        async function editSeriBarang() {
            if (!currentAju) return;
            const resConfirm = await Swal.fire({
                title: 'Urutkan Nomor Seri Barang?',
                text: 'Nomor seri barang akan diurutkan ulang berurutan dari 1 beserta relasi barang tarif dan bahan baku.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya, Urutkan'
            });
            if (!resConfirm.isConfirmed) return;

            try {
                const resp = await fetch(`editor.php?action=edit_seri&nomor_aju=${encodeURIComponent(currentAju)}`);
                const data = await resp.json();
                if (data.status === 'OK') {
                    Swal.fire('Sukses', data.message || 'Seri barang berhasil diurutkan.', 'success');
                    await reloadCurrentAju();
                } else {
                    Swal.fire('Gagal', data.message, 'error');
                }
            } catch(e) {
                Swal.fire('Error', e.message, 'error');
            }
        }

        async function seqBahanBaku() {
            if (!currentAju) return;
            try {
                const resp = await fetch(`editor.php?action=seq_bahanbaku&nomor_aju=${encodeURIComponent(currentAju)}`);
                const data = await resp.json();
                if (data.status === 'OK') {
                    Swal.fire('Sukses', data.message || 'Seri bahan baku berhasil diurutkan.', 'success');
                    await reloadCurrentAju();
                } else {
                    Swal.fire('Gagal', data.message, 'error');
                }
            } catch(e) {
                Swal.fire('Error', e.message, 'error');
            }
        }

        async function deleteAllTrace() {
            if (!currentAju) return;
            const res = await Swal.fire({
                title: 'Hapus Seluruh Data Trace?',
                text: 'Semua data tracking BOM dan Dokumen Asal pada dokumen ini akan dihapus permanen!',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                confirmButtonText: 'Ya, Hapus Semua'
            });
            if (!res.isConfirmed) return;

            try {
                const resp = await fetch(`editor.php?action=delete_all_trace&nomor_aju=${encodeURIComponent(currentAju)}`);
                const data = await resp.json();
                if (data.status === 'OK') {
                    Swal.fire('Terhapus', data.message, 'success');
                    await reloadCurrentAju();
                } else {
                    Swal.fire('Gagal', data.message, 'error');
                }
            } catch(e) {
                Swal.fire('Error', e.message, 'error');
            }
        }

        function toggleTracePanel() {
            const pnl = document.getElementById('traceSubPanel');
            const btn = document.getElementById('btnToggleTrace');
            if (!pnl) return;
            if (pnl.style.display === 'none' || pnl.style.display === '') {
                pnl.style.display = 'block';
                if (btn) btn.innerHTML = '🔍 Hide Trace';
                if (activeBarangSeri === null) {
                    const firstTr = document.querySelector('#table-barang tbody tr');
                    if (firstTr) {
                        firstTr.click();
                    } else {
                        renderBomForActiveBarang();
                    }
                } else {
                    renderBomForActiveBarang();
                }
            } else {
                pnl.style.display = 'none';
                if (btn) btn.innerHTML = '🔍 Show Trace';
            }
        }

        async function loadAllBomBarang() {
            if (!currentAju) return;
            Swal.fire({
                title: 'Memuat BOM Seluruh Barang...',
                text: 'Mengambil data bill of material komponen dari master produk...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            try {
                const resp = await fetch(`editor.php?action=load_all_bom&nomor_aju=${encodeURIComponent(currentAju)}`);
                const data = await resp.json();
                if (data.status === 'OK') {
                    Swal.fire('Sukses', data.message, 'success');
                    await reloadCurrentAju();
                } else {
                    Swal.fire(data.status === 'WARNING' ? 'Perhatian' : 'Gagal', data.message, data.status === 'WARNING' ? 'warning' : 'error');
                }
            } catch(e) {
                Swal.fire('Error', e.message, 'error');
            }
        }

        async function transferTraceToBahanBaku() {
            if (!currentAju) return;
            const confirmRes = await Swal.fire({
                title: 'Transfer Trace ke Bahan Baku?',
                text: 'Hasil tracing dokumen asal eks-BC akan dipindahkan ke tabel Bahan Baku dan otomatis mengurutkan seri serta membuat tarif bahan baku!',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '🔄 Ya, Transfer Sekarang'
            });
            if (!confirmRes.isConfirmed) return;

            Swal.fire({
                title: 'Mentransfer & Memproses Data...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            try {
                const resp = await fetch(`editor.php?action=transfer_trace_to_bb&nomor_aju=${encodeURIComponent(currentAju)}`);
                const data = await resp.json();
                if (data.status === 'OK') {
                    await Swal.fire('Sukses Transfer!', data.message, 'success');
                    await reloadCurrentAju();
                } else {
                    Swal.fire(data.status === 'WARNING' ? 'Perhatian' : 'Gagal', data.message, data.status === 'WARNING' ? 'warning' : 'error');
                }
            } catch(e) {
                Swal.fire('Error', e.message, 'error');
            }
        }

        // =============================================================
        // FLOATING SUMMARY TARIF & PUNGUTAN CONTROLLER
        // =============================================================

        function makeDraggable(el, handle) {
            let pos1 = 0, pos2 = 0, pos3 = 0, pos4 = 0;
            handle.onmousedown = dragMouseDown;

            function dragMouseDown(e) {
                if (e.target.closest('button')) return; // Abaikan klik tombol action
                e.preventDefault();
                pos3 = e.clientX;
                pos4 = e.clientY;
                document.onmouseup = closeDragElement;
                document.onmousemove = elementDrag;
            }

            function elementDrag(e) {
                e.preventDefault();
                pos1 = pos3 - e.clientX;
                pos2 = pos4 - e.clientY;
                pos3 = e.clientX;
                pos4 = e.clientY;
                el.style.top = (el.offsetTop - pos2) + "px";
                el.style.left = (el.offsetLeft - pos1) + "px";
                el.style.bottom = 'auto';
                el.style.right = 'auto';
            }

            function closeDragElement() {
                document.onmouseup = null;
                document.onmousemove = null;
            }
        }

        function openFloatingSummaryTarif() {
            const win = document.getElementById('floatingSummaryTarif');
            const pill = document.getElementById('btnFloatingSummaryLauncher');
            if (win) {
                win.style.display = 'flex';
                // Jika belum pernah dimuat, load datanya
                const box = document.getElementById('summaryTarifBox');
                if (box && (!box.innerHTML || box.innerHTML.trim() === '')) {
                    loadRecapTarif();
                }
            }
            if (pill) pill.style.display = 'none';
            localStorage.setItem('ceisa_summary_tarif_open', '1');
        }

        function closeFloatingSummaryTarif() {
            const win = document.getElementById('floatingSummaryTarif');
            const pill = document.getElementById('btnFloatingSummaryLauncher');
            if (win) win.style.display = 'none';
            if (pill && (activeTab === 'barangtarif' || activeTab === 'bahanbakutarif')) {
                pill.style.display = 'flex';
            }
            localStorage.setItem('ceisa_summary_tarif_open', '0');
        }

        function toggleFloatingSummaryTarif() {
            const win = document.getElementById('floatingSummaryTarif');
            if (win && win.style.display !== 'none') {
                closeFloatingSummaryTarif();
            } else {
                openFloatingSummaryTarif();
            }
        }

        function updateFloatingSummaryTabVisibility(tabName) {
            const win = document.getElementById('floatingSummaryTarif');
            const pill = document.getElementById('btnFloatingSummaryLauncher');
            const isTarifTab = (tabName === 'barangtarif' || tabName === 'bahanbakutarif');

            if (!isTarifTab) {
                if (win) win.style.display = 'none';
                if (pill) pill.style.display = 'none';
            } else {
                const shouldBeOpen = localStorage.getItem('ceisa_summary_tarif_open') === '1';
                if (shouldBeOpen) {
                    if (win) win.style.display = 'flex';
                    if (pill) pill.style.display = 'none';
                } else {
                    if (win) win.style.display = 'none';
                    if (pill) pill.style.display = 'flex';
                }
            }
        }

        async function loadRecapTarif() {
            if (!currentAju) return;
            const box = document.getElementById('summaryTarifBox');
            if (!box) return;
            try {
                const resp = await fetch(`editor.php?action=get_recap_tarif&nomor_aju=${encodeURIComponent(currentAju)}`);
                const data = await resp.json();
                if (data.status === 'OK') {
                    const allItems = [...(data.barang || []), ...(data.bahanbaku || [])];
                    if (allItems.length === 0) {
                        box.innerHTML = `
                            <div style="text-align:center; padding:18px 10px; color:var(--text-muted);">
                                <div style="font-size:24px; margin-bottom:6px;">📊</div>
                                <div style="font-weight:700; color:var(--text-main); margin-bottom:4px;">Belum Ada Data Tarif & Pungutan</div>
                                <div style="font-size:10px; max-width:320px; margin:0 auto; line-height:1.4;">Klik tombol <b>⚡ Auto-Generate Tarif & Pungutan</b> pada tab Barang untuk menghitung otomatis.</div>
                            </div>
                        `;
                        const pillBayar = document.getElementById('pillTotalBayar');
                        if (pillBayar) pillBayar.innerText = 'Rp 0';
                        return;
                    }

                    let totalBayar = 0;
                    let totalFasilitas = 0;
                    const grouped = {};
                    allItems.forEach(item => {
                        const k = item.kode_pungutan || 'LAINNYA';
                        if (!grouped[k]) grouped[k] = { bayar: 0, fasilitas: 0, lunas: 0 };
                        const b = Number(item.nilai_bayar || 0);
                        const f = Number(item.nilai_fasilitas || 0);
                        grouped[k].bayar += b;
                        grouped[k].fasilitas += f;
                        grouped[k].lunas += Number(item.nilai_sudah_dilunasi || 0);
                        totalBayar += b;
                        totalFasilitas += f;
                    });

                    // Update total bayar di launcher pill
                    const pillBayar = document.getElementById('pillTotalBayar');
                    if (pillBayar) {
                        pillBayar.innerText = `Rp ${totalBayar.toLocaleString('id-ID', {maximumFractionDigits: 0})}`;
                    }

                    // Render Grand Total card & Breakdown list
                    let html = `
                        <div class="summary-grand-total">
                            <div>
                                <div style="font-size:9.5px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Total Bayar</div>
                                <div style="font-size:13px; font-weight:800; color:#52c41a;">Rp ${totalBayar.toLocaleString('id-ID', {minimumFractionDigits: 2})}</div>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-size:9.5px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Total Fasilitas</div>
                                <div style="font-size:13px; font-weight:800; color:var(--primary);">Rp ${totalFasilitas.toLocaleString('id-ID', {minimumFractionDigits: 2})}</div>
                            </div>
                        </div>
                        <div class="summary-pungutan-grid">
                    `;

                    for (const [pungutan, vals] of Object.entries(grouped)) {
                        html += `
                            <div class="summary-pungutan-row">
                                <div class="summary-code-badge">${escapeHtml(pungutan)}</div>
                                <div class="summary-val-wrap">
                                    <span>Bayar: <b style="color:var(--text-main);">Rp ${vals.bayar.toLocaleString('id-ID', {minimumFractionDigits: 2})}</b></span>
                                    <span style="color:var(--text-muted);">|</span>
                                    <span>Fasilitas: <b style="color:var(--primary);">Rp ${vals.fasilitas.toLocaleString('id-ID', {minimumFractionDigits: 2})}</b></span>
                                </div>
                            </div>
                        `;
                    }
                    html += `</div>`;
                    box.innerHTML = html;
                }
            } catch(e) {
                console.error('Gagal load recap tarif:', e);
                box.innerHTML = '<div style="color:var(--danger); padding:10px; text-align:center;">Gagal memuat rekap tarif.</div>';
            }
        }

        // =============================================================
        // MASTER TARIF BM MODAL CONTROLLER
        // =============================================================

        function openTarifBmModal() {
            document.getElementById('modalTarifBm').style.display = 'flex';
            loadTarifBmList();
        }

        let tarifBmCache = [];
        async function loadTarifBmList() {
            const tbody = document.getElementById('tbodyTarifBm');
            if (!tbody) return;
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:15px;">Sedang memuat data master tarif BM...</td></tr>';
            try {
                const resp = await fetch('editor.php?action=get_tarifbm_list');
                const res = await resp.json();
                if (res.status === 'OK') {
                    tarifBmCache = res.data || [];
                    renderTarifBmTable(tarifBmCache);
                } else {
                    tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; color:red; padding:15px;">Gagal: ${res.message}</td></tr>`;
                }
            } catch(e) {
                tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; color:red; padding:15px;">Error: ${e.message}</td></tr>`;
            }
        }

        function renderTarifBmTable(list) {
            const tbody = document.getElementById('tbodyTarifBm');
            if (!tbody) return;
            if (list.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; color:var(--text-muted); padding:15px;">Belum ada master tarif BM.</td></tr>';
                return;
            }
            let html = '';
            list.forEach(r => {
                const hs = escapeHtml(r.hs || '');
                const tarif = Number(r.tarif_bm || 0);
                const tgl = escapeHtml(r.tgl_update || '');
                html += `<tr>
                    <td style="font-family:monospace; font-weight:700;">${hs}</td>
                    <td>
                        <input type="number" step="0.01" min="0" max="100" class="input-sm" id="input_bm_${hs}" value="${tarif}" style="width:85px; text-align:right;"> %
                    </td>
                    <td style="font-size:11px; color:var(--text-muted);">${tgl}</td>
                    <td style="text-align:center;">
                        <button type="button" class="btn btn-primary btn-xs" onclick="saveTarifBmRow('${hs}')">💾 Simpan</button>
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html;
        }

        function filterTarifBmTable() {
            const q = (document.getElementById('searchTarifBm')?.value || '').toLowerCase().trim();
            if (!q) {
                renderTarifBmTable(tarifBmCache);
                return;
            }
            const filtered = tarifBmCache.filter(r => (r.hs || '').toLowerCase().includes(q));
            renderTarifBmTable(filtered);
        }

        async function saveTarifBmRow(hs) {
            const inp = document.getElementById('input_bm_' + hs);
            const tarif = inp ? inp.value : 0;
            try {
                const fd = new FormData();
                fd.append('hs', hs);
                fd.append('tarif_bm', tarif);
                const resp = await fetch('editor.php?action=save_tarifbm', { method: 'POST', body: fd });
                const res = await resp.json();
                if (res.status === 'OK') {
                    showToast(`Tarif BM untuk ${hs} disimpan: ${tarif}%`);
                    loadTarifBmList();
                } else {
                    Swal.fire('Gagal', res.message, 'error');
                }
            } catch(e) {
                Swal.fire('Error', e.message, 'error');
            }
        }

        async function addNewTarifBm() {
            const hs = (document.getElementById('newBmHs')?.value || '').trim();
            const tarif = (document.getElementById('newBmTarif')?.value || '0').trim();
            if (!hs) {
                Swal.fire('Perhatian', 'Kode HS wajib diisi.', 'warning');
                return;
            }
            try {
                const fd = new FormData();
                fd.append('hs', hs);
                fd.append('tarif_bm', tarif);
                const resp = await fetch('editor.php?action=save_tarifbm', { method: 'POST', body: fd });
                const res = await resp.json();
                if (res.status === 'OK') {
                    showToast(`Tarif BM untuk ${hs} berhasil ditambahkan!`);
                    document.getElementById('newBmHs').value = '';
                    document.getElementById('newBmTarif').value = '5';
                    loadTarifBmList();
                } else {
                    Swal.fire('Gagal', res.message, 'error');
                }
            } catch(e) {
                Swal.fire('Error', e.message, 'error');
            }
        }

        // =============================================================
        // TAHAP 3: MESIN TRACE FIFO DOKUMEN ASAL
        // =============================================================

        async function startFifoTrace() {
            if (!currentAju) {
                Swal.fire('Perhatian', 'Silakan buka atau pilih dokumen AJU terlebih dahulu.', 'warning');
                return;
            }
            const confirmRes = await Swal.fire({
                title: '⚡ Jalankan Mesin Trace FIFO?',
                html: `Sistem akan:<br>
                    <div style="text-align:left; font-size:12px; margin-top:6px; line-height:1.7;">
                    1. Membaca kebutuhan komponen BOM pada setiap barang.<br>
                    2. Mencocokkan saldo pemasukan di <b>IT Inventory (bc_1ab2)</b> secara <b>First-In First-Out</b>.<br>
                    3. Mengisi tabel Tracking Eks-BC (<code>bc_ceisa_tracebb</code>) dengan No AJU, No Daftar, Tgl Daftar, HS, & Nilai CIF asal.
                    </div><br>
                    Lanjutkan proses trace otomatis?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#2563eb',
                confirmButtonText: '⚡ Ya, Mulai Trace FIFO'
            });
            if (!confirmRes.isConfirmed) return;

            Swal.fire({
                title: 'Sedang Melacak Saldo FIFO...',
                text: 'Mencocokkan komponen BOM dengan pemasukan IT Inventory...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            try {
                const resp = await fetch(`editor.php?action=trace_fifo&nomor_aju=${encodeURIComponent(currentAju)}`);
                const data = await resp.json();
                if (data.status === 'OK') {
                    // Pastikan panel trace terbuka agar user langsung melihat hasilnya
                    const pnl = document.getElementById('traceSubPanel');
                    const btn = document.getElementById('btnToggleTrace');
                    if (pnl) pnl.style.display = 'block';
                    if (btn) btn.innerHTML = '🔍 Hide Trace';

                    await Swal.fire({
                        title: 'Trace FIFO Selesai!',
                        html: `<div style="text-align:left; font-size:12px; line-height:1.7;">
                            <b>✅ Hasil Pelacakan FIFO:</b><br>
                            • Total baris dokumen asal terhubung: <b>${data.total_rows || 0}</b> baris.<br>
                            ${data.unmatched > 0 ? `• Komponen saldo standby: <b>${data.unmatched}</b> item.<br>` : ''}
                            <br>
                            💡 <i>Klik tombol <b>"🔄 Transfer Data Trace ke Bahan Baku"</b> jika Anda ingin memindahkan data trace ini ke tab Bahan Baku & mengenerate tarifnya!</i>
                        </div>`,
                        icon: 'success'
                    });

                    await reloadCurrentAju();
                } else {
                    Swal.fire(data.status === 'WARNING' ? 'Perhatian' : 'Gagal Trace', data.message || 'Terjadi kendala saat melacak data.', data.status === 'WARNING' ? 'warning' : 'error');
                }
            } catch(e) {
                Swal.fire('Error', e.message || 'Gagal memanggil API Trace FIFO.', 'error');
            }
        }

        // =============================================================
        // MASTER-DETAIL REACTIVE LINKING: BARANG ➔ BOM ➔ TRACE EKS-BC
        // 1:1 PowerBuilder w_bc_ceisa40_interface2.srw
        // =============================================================
        let activeBarangSeri = null;
        let activeBarangKode = '';
        let activeBarangUraian = '';
        let activeBarangTr = null;

        let activeBomId = null;
        let activeBomKode = '';
        let activeBomUraian = '';
        let activeBomTr = null;

        function selectBarangRow(tr, data, idx) {
            if (!tr) return;
            if (activeBarangTr) activeBarangTr.classList.remove('selected-row');
            tr.classList.add('selected-row');
            activeBarangTr = tr;

            const seriInp = tr.querySelector('input[data-col="seri_barang"]');
            const kbInp = tr.querySelector('input[data-col="kode_barang"]');
            const urTa = tr.querySelector('textarea[data-col="uraian"]');

            activeBarangSeri = seriInp ? parseInt(seriInp.value, 10) : (data?.seri_barang || 1);
            activeBarangKode = kbInp ? kbInp.value.trim() : (data?.kode_barang || '');
            activeBarangUraian = urTa ? urTa.value.trim() : (data?.uraian || '');

            // Update badge info di header BOM
            const badge = document.getElementById('badgeActiveBarangBom');
            if (badge) {
                badge.innerText = `Barang Seri #${activeBarangSeri}: ${activeBarangKode} - ${activeBarangUraian.substring(0, 30)}`;
                badge.title = `${activeBarangKode} - ${activeBarangUraian}`;
                badge.style.display = 'inline-block';
            }

            renderBomForActiveBarang();
        }

        function renderBomForActiveBarang() {
            const tbody = document.querySelector('#table-barangbom tbody');
            if (!tbody) return;
            tbody.innerHTML = '';

            if (activeBarangSeri === null) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:18px; color:var(--text-muted); font-style:italic;">Pilih salah satu baris di Tabel Rincian Barang di atas untuk melihat komponen BOM.</td></tr>';
                clearTraceTable('Pilih baris barang dan komponen BOM di atas untuk melihat tracking dokumen asal.');
                return;
            }

            const allBoms = currentData.barangbom || [];
            const filtered = allBoms.filter(b => {
                const bSeri = parseInt(b.seri, 10);
                if (bSeri === activeBarangSeri) return true;
                if (activeBarangKode && b.kode_barang_parent === activeBarangKode) return true;
                return false;
            });

            if (filtered.length === 0) {
                tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:18px; color:var(--text-muted); font-style:italic;">
                    Belum ada data komponen BOM untuk <b>Barang Seri #${activeBarangSeri} (${escapeHtml(activeBarangKode)})</b>.<br>
                    <span style="font-size:10px;">Gunakan tombol <b>"📦 Tambah dari Master Barang"</b>, <b>"📥 Load BOM Barang Ini"</b>, atau <b>"➕ Tambah BOM Manual"</b> di atas.</span>
                </td></tr>`;
                clearTraceTable('Belum ada komponen BOM terpilih.');
                return;
            }

            const frag = document.createDocumentFragment();
            filtered.forEach((r, idx) => {
                const tr = createGridRow('barangbom', r, idx);
                tr.dataset.id = r.id || '';
                tr.dataset.seri = r.seri || activeBarangSeri;
                tr.dataset.kode = r.kode_barang || '';
                tr.dataset.kodeParent = r.kode_barang_parent || activeBarangKode;
                frag.appendChild(tr);
            });
            tbody.appendChild(frag);

            // Auto-select row pertama BOM (1:1 sesuai PB w_bc_ceisa40_interface2: if rowcount() > 0 then trace3.retrieve)
            const firstTr = tbody.querySelector('tr');
            if (firstTr) {
                selectBomRow(firstTr, filtered[0], 0);
            }
        }

        function selectBomRow(tr, data, idx) {
            if (!tr) return;
            if (activeBomTr) activeBomTr.classList.remove('selected-row');
            tr.classList.add('selected-row');
            activeBomTr = tr;

            const kbInp = tr.querySelector('input[data-col="kode_barang"]');
            const urTa = tr.querySelector('textarea[data-col="uraian"]');

            activeBomId = tr.dataset.id || data?.id || '';
            activeBomKode = kbInp ? kbInp.value.trim() : (data?.kode_barang || '');
            activeBomUraian = urTa ? urTa.value.trim() : (data?.uraian || '');

            // Update badge info di header Trace Dokumen Asal
            const badge = document.getElementById('badgeActiveBomTrace');
            if (badge) {
                badge.innerText = `Komponen: ${activeBomKode} - ${activeBomUraian.substring(0, 30)}`;
                badge.title = `${activeBomKode} - ${activeBomUraian}`;
                badge.style.display = 'inline-block';
            }

            renderTraceForActiveBom();
        }

        function renderTraceForActiveBom() {
            const tbody = document.querySelector('#table-tracebb tbody');
            if (!tbody) return;
            tbody.innerHTML = '';

            if (!activeBomId && !activeBomKode) {
                clearTraceTable('Pilih komponen BOM di atas untuk melihat rekaman dokumen asal eks-BC.');
                return;
            }

            const allTraces = currentData.tracebb || [];
            const filtered = allTraces.filter(t => {
                if (activeBomId && t.id_bom && t.id_bom === activeBomId) return true;
                const tSeri = parseInt(t.seri_barang || t.seri, 10);
                const tKode = t.kode_barang || t.kode_brg;
                if (tSeri === activeBarangSeri && tKode === activeBomKode) return true;
                return false;
            });

            if (filtered.length === 0) {
                tbody.innerHTML = `<tr><td colspan="11" style="text-align:center; padding:18px; color:var(--text-muted); font-style:italic;">
                    Belum ada rekaman tracking dokumen asal (eks-BC) untuk <b>Komponen ${escapeHtml(activeBomKode)}</b>.<br>
                    <span style="font-size:10px;">Gunakan tombol <b>"⚡ Mulai Trace FIFO (Auto)"</b> atau <b>"📥 Tambah Dokumen Asal"</b> di atas.</span>
                </td></tr>`;
                return;
            }

            const frag = document.createDocumentFragment();
            filtered.forEach((r, idx) => {
                const tr = createGridRow('tracebb', r, idx);
                tr.dataset.id = r.id || '';
                tr.dataset.idBom = r.id_bom || activeBomId || '';
                tr.dataset.seriBarang = r.seri_barang || r.seri || activeBarangSeri;
                tr.dataset.kodeBarang = r.kode_barang || r.kode_brg || activeBomKode;
                frag.appendChild(tr);
            });
            tbody.appendChild(frag);
        }

        function clearTraceTable(message) {
            const tbody = document.querySelector('#table-tracebb tbody');
            if (tbody) {
                tbody.innerHTML = `<tr><td colspan="11" style="text-align:center; padding:18px; color:var(--text-muted); font-style:italic;">${message}</td></tr>`;
            }
            const badge = document.getElementById('badgeActiveBomTrace');
            if (badge) badge.style.display = 'none';
        }

        function syncDomToBomData() {
            if (activeBarangSeri === null) return;
            const tbody = document.querySelector('#table-barangbom tbody');
            if (!tbody) return;
            const domRows = [];
            tbody.querySelectorAll('tr').forEach(tr => {
                if (tr.querySelector('.btn-danger')) {
                    const rowObj = {
                        id: tr.dataset.id || ('bom_' + Math.random().toString(36).substr(2, 9)),
                        seri: activeBarangSeri,
                        kode_barang_parent: tr.dataset.kodeParent || activeBarangKode
                    };
                    tr.querySelectorAll('input[data-col], select[data-col], textarea[data-col]').forEach(el => {
                        if (el.dataset.col) {
                            let v = el.value !== undefined ? el.value.trim() : '';
                            if (el.classList.contains('cell-number') || el.type === 'number') {
                                v = stripNumberCommas(v);
                            }
                            rowObj[el.dataset.col] = v;
                        }
                    });
                    domRows.push(rowObj);
                }
            });

            // Hapus yang seri aktif lalu gabungkan domRows
            const otherBoms = (currentData.barangbom || []).filter(b => parseInt(b.seri, 10) !== activeBarangSeri);
            currentData.barangbom = otherBoms.concat(domRows);
        }

        function syncDomToTraceData() {
            if (!activeBomId) return;
            const tbody = document.querySelector('#table-tracebb tbody');
            if (!tbody) return;
            const domRows = [];
            tbody.querySelectorAll('tr').forEach(tr => {
                if (tr.querySelector('.btn-danger')) {
                    const rowObj = {
                        id: tr.dataset.id || ('tr_' + Math.random().toString(36).substr(2, 9)),
                        id_bom: activeBomId,
                        seri_barang: activeBarangSeri,
                        seri: activeBarangSeri
                    };
                    tr.querySelectorAll('input[data-col], select[data-col], textarea[data-col]').forEach(el => {
                        if (el.dataset.col) {
                            let v = el.value !== undefined ? el.value.trim() : '';
                            if (el.classList.contains('cell-number') || el.type === 'number') {
                                v = stripNumberCommas(v);
                            }
                            rowObj[el.dataset.col] = v;
                        }
                    });
                    domRows.push(rowObj);
                }
            });

            const otherTraces = (currentData.tracebb || []).filter(t => t.id_bom !== activeBomId);
            currentData.tracebb = otherTraces.concat(domRows);
        }

        async function loadSelectedBarangBom() {
            if (!currentAju) return;
            if (activeBarangSeri === null) {
                Swal.fire('Perhatian', 'Pilih baris barang pada tabel Rincian Barang terlebih dahulu.', 'warning');
                return;
            }

            Swal.fire({
                title: `Memuat BOM Barang Seri #${activeBarangSeri}...`,
                text: `Mengambil struktur BOM untuk ${activeBarangKode}...`,
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            try {
                const resp = await fetch(`editor.php?action=load_bom_barang&nomor_aju=${encodeURIComponent(currentAju)}&seri=${activeBarangSeri}`);
                const data = await resp.json();
                if (data.status === 'OK') {
                    // Muat ulang data AJU
                    const tRes = await fetch(`editor.php?action=load_all&nomor_aju=${encodeURIComponent(currentAju)}`);
                    const tJson = await tRes.json();
                    if (tJson.status === 'OK' && tJson.tables) {
                        currentData.barangbom = tJson.tables.barangbom || [];
                        currentData.tracebb = tJson.tables.tracebb || [];
                    }
                    renderBomForActiveBarang();
                    Swal.fire('Sukses', data.message, 'success');
                } else {
                    Swal.fire(data.status === 'WARNING' ? 'Perhatian' : 'Gagal', data.message, data.status === 'WARNING' ? 'warning' : 'error');
                }
            } catch(e) {
                Swal.fire('Error', e.message, 'error');
            }
        }

        // =============================================================
        // QUICK PICKER: KOMPONEN BOM DARI MASTER BARANG (bc_material)
        // =============================================================
        let bomMaterialPickerCache = [];
        let searchBomMaterialTimer = null;

        function openBomMaterialPickerModal() {
            if (!currentAju) {
                Swal.fire('Perhatian', 'Pilih atau buka dokumen AJU terlebih dahulu.', 'warning');
                return;
            }
            if (activeBarangSeri === null) {
                const firstTr = document.querySelector('#table-barang tbody tr');
                if (firstTr) {
                    firstTr.click();
                } else {
                    Swal.fire('Perhatian', 'Belum ada data barang. Silakan isi Rincian Barang terlebih dahulu.', 'warning');
                    return;
                }
            }

            const infoEl = document.getElementById('bomPickerTargetInfo');
            if (infoEl) {
                infoEl.innerHTML = `Target Barang Jadi: <b>Seri #${activeBarangSeri}</b> - <code>${escapeHtml(activeBarangKode)}</code> (${escapeHtml(activeBarangUraian)})`;
            }

            document.getElementById('modalBomMaterialPicker').style.display = 'flex';
            document.getElementById('searchBomMaterialPicker').value = '';
            document.getElementById('filterKlasBomMaterialPicker').value = 'RAW';
            loadBomMaterialPickerList();
        }

        function debounceSearchBomMaterial() {
            if (searchBomMaterialTimer) clearTimeout(searchBomMaterialTimer);
            searchBomMaterialTimer = setTimeout(loadBomMaterialPickerList, 300);
        }

        async function loadBomMaterialPickerList() {
            const tbody = document.getElementById('tbodyBomMaterialPicker');
            if (!tbody) return;
            const search = encodeURIComponent((document.getElementById('searchBomMaterialPicker')?.value || '').trim());
            const klas = encodeURIComponent(document.getElementById('filterKlasBomMaterialPicker')?.value || '');

            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:20px;"><span class="spinner" style="width:14px;height:14px;display:inline-block;margin-right:6px;"></span> Memuat master barang...</td></tr>';

            try {
                const resp = await fetch(`editor.php?action=get_material_picker&search=${search}&klas=${klas}`);
                const res = await resp.json();
                if (res.status === 'OK') {
                    bomMaterialPickerCache = res.data || [];
                    renderBomMaterialPickerTable(bomMaterialPickerCache);
                } else {
                    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; color:red; padding:15px;">Gagal: ${res.message}</td></tr>`;
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; color:red; padding:15px;">Error: ${e.message}</td></tr>`;
            }
        }

        function renderBomMaterialPickerTable(list) {
            const tbody = document.getElementById('tbodyBomMaterialPicker');
            const chkAll = document.getElementById('chkSelectAllBomMaterial');
            if (chkAll) chkAll.checked = false;
            updateSelectedBomMaterialBadge();

            if (!tbody) return;
            if (list.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; color:var(--text-muted); padding:20px;">Tidak ada barang yang cocok.</td></tr>';
                return;
            }

            let html = '';
            list.forEach((m, idx) => {
                const kb = escapeHtml(m.kode_barang || '');
                const uraian = escapeHtml(m.uraian || '');
                const hs = escapeHtml(m.pos_tarif || '');
                const sat = escapeHtml(m.kode_satuan || 'PCE');
                const klas = escapeHtml(m.klas || '-');

                html += `<tr>
                    <td style="text-align:center;">
                        <input type="checkbox" class="chk-bom-material-row" data-idx="${idx}" onchange="updateSelectedBomMaterialBadge()">
                    </td>
                    <td style="font-family:monospace; font-weight:700;">${kb}</td>
                    <td>${uraian}</td>
                    <td style="font-family:monospace;">${hs}</td>
                    <td>${sat}</td>
                    <td><span class="badge" style="background:var(--bg-card); font-size:9.5px;">${klas}</span></td>
                    <td style="text-align:right;">
                        <input type="number" step="0.0001" min="0.0001" value="1.0000" class="input-sm inp-bom-material-qty" data-idx="${idx}" style="width:85px; text-align:right;">
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html;
        }

        function toggleSelectAllBomMaterial(checked) {
            document.querySelectorAll('.chk-bom-material-row').forEach(c => c.checked = checked);
            updateSelectedBomMaterialBadge();
        }

        function updateSelectedBomMaterialBadge() {
            const checkedCount = document.querySelectorAll('.chk-bom-material-row:checked').length;
            const badge = document.getElementById('badgeSelectedBomMaterialCount');
            if (badge) badge.innerText = `${checkedCount} komponen dipilih`;
        }

        async function insertSelectedBomMaterials() {
            if (!currentAju || activeBarangSeri === null) return;
            const checkedBoxes = document.querySelectorAll('.chk-bom-material-row:checked');
            if (checkedBoxes.length === 0) {
                Swal.fire('Perhatian', 'Pilih minimal satu komponen untuk dimasukkan ke BOM.', 'warning');
                return;
            }

            const itemsToInsert = [];
            checkedBoxes.forEach(chk => {
                const idx = parseInt(chk.dataset.idx, 10);
                const mat = bomMaterialPickerCache[idx];
                if (mat) {
                    const qtyInp = document.querySelector(`.inp-bom-material-qty[data-idx="${idx}"]`);
                    const qtyVal = qtyInp ? parseFloat(qtyInp.value) || 1 : 1;
                    itemsToInsert.push({
                        kode_barang: mat.kode_barang,
                        uraian: mat.uraian,
                        kode_satuan: mat.kode_satuan || 'PCE',
                        qty: qtyVal
                    });
                }
            });

            Swal.fire({
                title: 'Memasukkan Komponen BOM...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            try {
                const fd = new FormData();
                fd.append('nomor_aju', currentAju);
                fd.append('seri', activeBarangSeri);
                fd.append('kode_barang_parent', activeBarangKode);
                fd.append('items', JSON.stringify(itemsToInsert));

                const resp = await fetch('editor.php?action=insert_bom_from_material', { method: 'POST', body: fd });
                const res = await resp.json();
                if (res.status === 'OK') {
                    closeModal('modalBomMaterialPicker');

                    // Refresh data AJU & render BOM
                    const tRes = await fetch(`editor.php?action=load_all&nomor_aju=${encodeURIComponent(currentAju)}`);
                    const tJson = await tRes.json();
                    if (tJson.status === 'OK' && tJson.tables) {
                        currentData.barangbom = tJson.tables.barangbom || [];
                    }
                    renderBomForActiveBarang();

                    Swal.fire('Sukses', res.message, 'success');
                } else {
                    Swal.fire('Gagal', res.message, 'error');
                }
            } catch(e) {
                Swal.fire('Error', e.message, 'error');
            }
        }

        // =============================================================
        // TAHAP 4: QUICK PICKER MASTER BARANG (bc_material)
        // =============================================================

        let materialPickerCache = [];
        let searchMaterialTimer = null;

        function openMaterialPickerModal() {
            document.getElementById('modalMaterialPicker').style.display = 'flex';
            document.getElementById('searchMaterialPicker').value = '';
            document.getElementById('filterKlasMaterialPicker').value = '';
            loadMaterialPickerList();
        }

        function debounceSearchMaterial() {
            if (searchMaterialTimer) clearTimeout(searchMaterialTimer);
            searchMaterialTimer = setTimeout(loadMaterialPickerList, 300);
        }

        async function loadMaterialPickerList() {
            const tbody = document.getElementById('tbodyMaterialPicker');
            if (!tbody) return;
            const search = encodeURIComponent((document.getElementById('searchMaterialPicker')?.value || '').trim());
            const klas = encodeURIComponent(document.getElementById('filterKlasMaterialPicker')?.value || '');

            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:20px;"><span class="spinner" style="width:14px;height:14px;display:inline-block;margin-right:6px;"></span> Memuat master barang...</td></tr>';

            try {
                const resp = await fetch(`editor.php?action=get_material_picker&search=${search}&klas=${klas}`);
                const res = await resp.json();
                if (res.status === 'OK') {
                    materialPickerCache = res.data || [];
                    renderMaterialPickerTable(materialPickerCache);
                } else {
                    tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; color:red; padding:15px;">Gagal: ${res.message}</td></tr>`;
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; color:red; padding:15px;">Error: ${e.message}</td></tr>`;
            }
        }

        function renderMaterialPickerTable(list) {
            const tbody = document.getElementById('tbodyMaterialPicker');
            const chkAll = document.getElementById('chkSelectAllMaterial');
            if (chkAll) chkAll.checked = false;
            updateSelectedMaterialBadge();

            if (!tbody) return;
            if (list.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; color:var(--text-muted); padding:20px;">Tidak ada barang yang cocok.</td></tr>';
                return;
            }

            let html = '';
            list.forEach((m, idx) => {
                const kb = escapeHtml(m.kode_barang || '');
                const uraian = escapeHtml(m.uraian || '');
                const hs = escapeHtml(m.pos_tarif || '');
                const sat = escapeHtml(m.kode_satuan || 'PCE');
                const berat = Number(m.berat || 0);
                const price = Number(m.unit_price || 0);

                html += `<tr>
                    <td style="text-align:center;">
                        <input type="checkbox" class="chk-material-row" data-idx="${idx}" onchange="updateSelectedMaterialBadge()">
                    </td>
                    <td style="font-family:monospace; font-weight:700;">${kb}</td>
                    <td>${uraian}</td>
                    <td style="font-family:monospace;">${hs}</td>
                    <td>${sat}</td>
                    <td style="text-align:right;">${berat.toLocaleString('id-ID', {minimumFractionDigits: 2})}</td>
                    <td style="text-align:right;">${price.toLocaleString('id-ID', {minimumFractionDigits: 2})}</td>
                    <td style="text-align:right;">
                        <input type="number" step="0.0001" min="0.0001" value="1.0000" class="input-sm inp-material-qty" data-idx="${idx}" style="width:75px; text-align:right;">
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html;
        }

        function toggleSelectAllMaterial(checked) {
            document.querySelectorAll('.chk-material-row').forEach(c => c.checked = checked);
            updateSelectedMaterialBadge();
        }

        function updateSelectedMaterialBadge() {
            const checkedCount = document.querySelectorAll('.chk-material-row:checked').length;
            const badge = document.getElementById('badgeSelectedMaterialCount');
            if (badge) badge.innerText = `${checkedCount} item dipilih`;
        }

        async function insertSelectedMaterials() {
            if (!currentAju) return;
            const checkedBoxes = document.querySelectorAll('.chk-material-row:checked');
            if (checkedBoxes.length === 0) {
                Swal.fire('Perhatian', 'Pilih minimal satu barang untuk dimasukkan.', 'warning');
                return;
            }

            const itemsToInsert = [];
            checkedBoxes.forEach(chk => {
                const idx = parseInt(chk.dataset.idx, 10);
                const mat = materialPickerCache[idx];
                if (mat) {
                    const qtyInp = document.querySelector(`.inp-material-qty[data-idx="${idx}"]`);
                    const qtyVal = qtyInp ? parseFloat(qtyInp.value) || 1 : 1;
                    itemsToInsert.push({
                        kode_barang: mat.kode_barang,
                        uraian: mat.uraian,
                        pos_tarif: mat.pos_tarif,
                        kode_satuan: mat.kode_satuan,
                        unit_price: mat.unit_price,
                        berat: mat.berat,
                        jumlah_satuan: qtyVal
                    });
                }
            });

            Swal.fire({
                title: 'Memasukkan Barang...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            try {
                const fd = new FormData();
                fd.append('nomor_aju', currentAju);
                fd.append('items', JSON.stringify(itemsToInsert));

                const resp = await fetch('editor.php?action=insert_from_material', { method: 'POST', body: fd });
                const res = await resp.json();
                if (res.status === 'OK') {
                    closeModal('modalMaterialPicker');
                    await Swal.fire('Sukses', res.message, 'success');
                    await reloadCurrentAju();
                } else {
                    Swal.fire('Gagal', res.message, 'error');
                }
            } catch(e) {
                Swal.fire('Error', e.message, 'error');
            }
        }

        // =============================================================
        // TAHAP 4: QUICK PICKER DOKUMEN MASUK EKS-BC (bc_1ab2)
        // =============================================================

        let incomingPickerCache = [];
        let incomingSourceContext = 'bahanbaku'; // 'bahanbaku' or 'trace'
        let searchIncomingTimer = null;

        function openIncomingPickerModal(sourceContext = 'bahanbaku') {
            incomingSourceContext = sourceContext;
            const modal = document.getElementById('modalIncomingPicker');
            modal.style.display = 'flex';

            // Populate Target Seri Barang dropdown from current table-barang
            const selectTarget = document.getElementById('selectTargetSeriBarang');
            const wrapperTarget = document.getElementById('wrapperTargetSeri');
            if (selectTarget) {
                selectTarget.innerHTML = '';
                const barangRows = document.querySelectorAll('#table-barang tbody tr');
                if (barangRows.length > 0) {
                    barangRows.forEach(tr => {
                        const seriInp = tr.querySelector('input[data-col="seri_barang"]');
                        const kbInp = tr.querySelector('input[data-col="kode_barang"]');
                        const seriVal = seriInp ? seriInp.value : '1';
                        const kbVal = kbInp ? kbInp.value : '';
                        const opt = document.createElement('option');
                        opt.value = seriVal;
                        opt.innerText = `Seri ${seriVal}: ${kbVal}`;
                        selectTarget.appendChild(opt);
                    });
                    if (wrapperTarget) wrapperTarget.style.display = 'flex';
                } else {
                    const opt = document.createElement('option');
                    opt.value = '1';
                    opt.innerText = 'Seri 1 (Default)';
                    selectTarget.appendChild(opt);
                }
                if (sourceContext === 'trace' && activeBarangSeri !== null) {
                    selectTarget.value = String(activeBarangSeri);
                }
            }

            document.getElementById('searchIncomingPicker').value = (sourceContext === 'trace' && activeBomKode) ? activeBomKode : '';
            document.getElementById('filterBcIncomingPicker').value = '';
            loadIncomingPickerList();
        }

        function debounceSearchIncoming() {
            if (searchIncomingTimer) clearTimeout(searchIncomingTimer);
            searchIncomingTimer = setTimeout(loadIncomingPickerList, 300);
        }

        async function loadIncomingPickerList() {
            const tbody = document.getElementById('tbodyIncomingPicker');
            if (!tbody) return;
            const search = encodeURIComponent((document.getElementById('searchIncomingPicker')?.value || '').trim());
            const bc = encodeURIComponent(document.getElementById('filterBcIncomingPicker')?.value || '');

            tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:20px;"><span class="spinner" style="width:14px;height:14px;display:inline-block;margin-right:6px;"></span> Memuat saldo pemasukan IT Inventory...</td></tr>';

            try {
                const resp = await fetch(`editor.php?action=get_incoming_picker&search=${search}&bc=${bc}`);
                const res = await resp.json();
                if (res.status === 'OK') {
                    incomingPickerCache = res.data || [];
                    renderIncomingPickerTable(incomingPickerCache);
                } else {
                    tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; color:red; padding:15px;">Gagal: ${res.message}</td></tr>`;
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; color:red; padding:15px;">Error: ${e.message}</td></tr>`;
            }
        }

        function renderIncomingPickerTable(list) {
            const tbody = document.getElementById('tbodyIncomingPicker');
            updateSelectedIncomingBadge();

            if (!tbody) return;
            if (list.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; color:var(--text-muted); padding:20px;">Tidak ada dokumen pemasukan yang cocok.</td></tr>';
                return;
            }

            let html = '';
            list.forEach((doc, idx) => {
                const bc = escapeHtml(doc.bc || '');
                const aju = escapeHtml(doc.nomor_aju || '');
                const noDaftar = escapeHtml(doc.nomor_daftar || '-');
                const tglDaftar = escapeHtml(doc.tanggal_daftar || '-');
                const kb = escapeHtml(doc.kode_barang || '');
                const uraian = escapeHtml(doc.uraian || '');
                const supplier = escapeHtml(doc.nama_bs || '');
                const sat = escapeHtml(doc.unit || 'PCE');
                const avail = Number(doc.qty_avail || 0);

                html += `<tr>
                    <td style="text-align:center;">
                        <input type="checkbox" class="chk-incoming-row" data-idx="${idx}" onchange="updateSelectedIncomingBadge()">
                    </td>
                    <td style="text-align:center; font-weight:700;"><span class="badge" style="background:#0891b2; color:#fff;">BC ${bc}</span></td>
                    <td style="font-family:monospace; font-size:10.5px;">${aju}</td>
                    <td style="font-size:10.5px;"><b>${noDaftar}</b><br><span style="color:var(--text-muted); font-size:9.5px;">${tglDaftar}</span></td>
                    <td style="font-family:monospace; font-weight:700;">${kb}</td>
                    <td style="font-size:11px;">${uraian}<br><small style="color:var(--text-muted);">${supplier}</small></td>
                    <td>${sat}</td>
                    <td style="text-align:right; font-weight:700; color:#10b981;">${avail.toLocaleString('id-ID', {minimumFractionDigits: 4})}</td>
                    <td style="text-align:right;">
                        <input type="number" step="0.0001" min="0.0001" max="${avail > 0 ? avail : 999999}" value="${avail > 0 ? Math.min(avail, 1.0) : 1.0}" class="input-sm inp-incoming-qty" data-idx="${idx}" style="width:85px; text-align:right;">
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html;
        }

        function updateSelectedIncomingBadge() {
            const count = document.querySelectorAll('.chk-incoming-row:checked').length;
            const badge = document.getElementById('badgeSelectedIncomingCount');
            if (badge) badge.innerText = `${count} dokumen dipilih`;
        }

        async function insertSelectedIncoming() {
            if (!currentAju) return;
            const checkedBoxes = document.querySelectorAll('.chk-incoming-row:checked');
            if (checkedBoxes.length === 0) {
                Swal.fire('Perhatian', 'Pilih minimal satu dokumen pemasukan untuk dimasukkan.', 'warning');
                return;
            }

            const targetSeri = document.getElementById('selectTargetSeriBarang')?.value || '1';
            const itemsToInsert = [];

            checkedBoxes.forEach(chk => {
                const idx = parseInt(chk.dataset.idx, 10);
                const doc = incomingPickerCache[idx];
                if (doc) {
                    const qtyInp = document.querySelector(`.inp-incoming-qty[data-idx="${idx}"]`);
                    const qtyVal = qtyInp ? parseFloat(qtyInp.value) || 1 : 1;
                    itemsToInsert.push({
                        bc: doc.bc,
                        nomor_aju: doc.nomor_aju,
                        nomor_daftar: doc.nomor_daftar,
                        tanggal_daftar: doc.tanggal_daftar,
                        seri_barang: doc.seri_barang,
                        kode_barang: doc.kode_barang,
                        uraian: doc.uraian,
                        pos_tarif: doc.pos_tarif,
                        unit: doc.unit,
                        qty_pakai: qtyVal,
                        unit_cif: doc.cif > 0 ? doc.cif / (doc.qty_total || 1) : 0,
                        unit_harga: doc.harga > 0 ? doc.harga / (doc.qty_total || 1) : 0,
                        ndpbm: doc.ndpbm,
                        gross: doc.gross,
                        netto: doc.netto
                    });
                }
            });

            if (incomingSourceContext === 'trace') {
                if (!activeBomId && !activeBomKode) {
                    Swal.fire('Perhatian', 'Pilih salah satu komponen BOM terlebih dahulu.', 'warning');
                    return;
                }
                Swal.fire({
                    title: 'Memasukkan Dokumen Asal...',
                    text: 'Memasukkan data ke Tracking Dokumen Asal (Trace Eks-BC)...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });

                try {
                    const fd = new FormData();
                    fd.append('nomor_aju', currentAju);
                    fd.append('seri_barang', targetSeri || activeBarangSeri || 1);
                    fd.append('id_bom', activeBomId || '');
                    fd.append('items', JSON.stringify(itemsToInsert));

                    const resp = await fetch('editor.php?action=insert_trace_from_incoming', { method: 'POST', body: fd });
                    const res = await resp.json();
                    if (res.status === 'OK') {
                        closeModal('modalIncomingPicker');
                        // Muat ulang data AJU
                        const tRes = await fetch(`editor.php?action=load_all&nomor_aju=${encodeURIComponent(currentAju)}`);
                        const tJson = await tRes.json();
                        if (tJson.status === 'OK' && tJson.tables) {
                            currentData.tracebb = tJson.tables.tracebb || [];
                        }
                        renderTraceForActiveBom();
                        await Swal.fire('Sukses', res.message, 'success');
                    } else {
                        Swal.fire('Gagal', res.message, 'error');
                    }
                } catch(e) {
                    Swal.fire('Error', e.message, 'error');
                }
                return;
            }

            Swal.fire({
                title: 'Memasukkan Dokumen Asal...',
                text: 'Memasukkan data ke Bahan Baku & mengkalkulasi tarif...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            try {
                const fd = new FormData();
                fd.append('nomor_aju', currentAju);
                fd.append('seri_barang', targetSeri);
                fd.append('items', JSON.stringify(itemsToInsert));

                const resp = await fetch('editor.php?action=insert_from_incoming', { method: 'POST', body: fd });
                const res = await resp.json();
                if (res.status === 'OK') {
                    closeModal('modalIncomingPicker');
                    await Swal.fire('Sukses', res.message, 'success');
                    await reloadCurrentAju();
                    loadRecapTarif();
                } else {
                    Swal.fire('Gagal', res.message, 'error');
                }
            } catch(e) {
                Swal.fire('Error', e.message, 'error');
            }
        }

        // =============================================================
        // =============================================================
        // CONTEXT MENU (KLIK KANAN) CONTROLLER (EXCEL & ROW ACTIONS)
        // =============================================================

        let activeContextRow = null;
        let activeContextTable = null;

        function initEditorContextMenu() {
            const ctx = document.getElementById('editorContextMenu');
            if (!ctx) return;

            document.addEventListener('contextmenu', function(e) {
                // Jangan intervensi jika klik kanan di dalam modal
                if (e.target && (e.target.closest('#modalPasteExcel') || e.target.closest('.modal-overlay') || e.target.closest('button'))) {
                    return;
                }

                // Cari apakah target berada di dalam table bertaraf datagrid atau panel tabel
                const table = e.target.closest('table');
                let tblKey = '';

                if (table && table.id && table.id.startsWith('table-')) {
                    tblKey = table.id.replace('table-', '');
                } else {
                    const panel = e.target.closest('.panel');
                    if (panel && panel.id && panel.id.startsWith('panel-')) {
                        const candidate = panel.id.replace('panel-', '');
                        if (EXCEL_SUPPORTED_TABLES.includes(candidate)) {
                            tblKey = candidate;
                        }
                    }
                }

                if (!tblKey || !EXCEL_SUPPORTED_TABLES.includes(tblKey)) {
                    ctx.style.display = 'none';
                    return;
                }

                e.preventDefault();
                e.stopPropagation();

                activeContextTable = tblKey;
                activeContextMenuTable = tblKey;

                // Cek apakah klik spesifik pada sebuah baris data tr di tbody
                const tr = e.target.closest('tr');
                const isRealRow = tr && tr.closest('tbody') && !tr.classList.contains('tr-empty');
                activeContextRow = isRealRow ? tr : null;
                activeContextMenuTr = activeContextRow;

                // Judul Context Menu
                const titleTextEl = document.getElementById('ctxTitleText');
                if (titleTextEl) {
                    const rowInfo = activeContextRow ? ` (Baris ${activeContextRow.rowIndex || ''})` : '';
                    titleTextEl.innerText = `${getTabTitle(tblKey)}${rowInfo}`;
                }

                // Opsi Salin Baris Ini
                const optSelected = document.getElementById('excelMenuCopySelected');
                if (optSelected) {
                    optSelected.style.display = activeContextRow ? 'flex' : 'none';
                }

                // Opsi Hapus Baris
                const optDelete = document.getElementById('ctxRowDeleteOption');
                if (optDelete) {
                    optDelete.style.display = activeContextRow ? 'block' : 'none';
                }

                // Opsi Khusus Barang
                const optBarang = document.getElementById('ctxBarangOptions');
                if (optBarang) {
                    optBarang.style.display = (tblKey === 'barang') ? 'block' : 'none';
                }

                // Opsi Khusus Bahan Baku
                const optBahanBaku = document.getElementById('ctxBahanBakuOptions');
                if (optBahanBaku) {
                    optBahanBaku.style.display = (tblKey === 'bahanbaku') ? 'block' : 'none';
                }

                // Tampilkan & hitung posisi layar agar tidak terpotong
                ctx.style.display = 'block';
                const menuW = ctx.offsetWidth || 220;
                const menuH = ctx.offsetHeight || 260;
                const winW = window.innerWidth;
                const winH = window.innerHeight;

                let left = e.clientX;
                let top = e.clientY;

                if (left + menuW > winW) left = winW - menuW - 10;
                if (top + menuH > winH) top = winH - menuH - 10;

                ctx.style.left = Math.max(10, left) + 'px';
                ctx.style.top = Math.max(10, top) + 'px';
            });

            document.addEventListener('click', function(e) {
                if (!e.target.closest('#editorContextMenu')) {
                    ctx.style.display = 'none';
                }
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && ctx.style.display === 'block') {
                    ctx.style.display = 'none';
                }
            });
        }

        function triggerContextAction(act) {
            const ctx = document.getElementById('editorContextMenu');
            if (ctx) ctx.style.display = 'none';

            if (act === 'add_material') {
                openMaterialPickerModal();
            } else if (act === 'fifo_trace') {
                startFifoTrace();
            } else if (act === 'toggle_trace') {
                toggleTracePanel();
            } else if (act === 'fix_hs') {
                fixHsCode();
            } else if (act === 'fix_netto') {
                fixNetto();
            } else if (act === 'add_incoming') {
                openIncomingPickerModal('bahanbaku');
            } else if (act === 'generate_bbt') {
                generateBahanBakuTarif();
            } else if (act === 'seq_bb') {
                seqBahanBaku();
            } else if (act === 'delete_row') {
                if (activeContextRow) {
                    activeContextRow.remove();
                    const curTbl = activeContextTable || activeContextMenuTable;
                    if (curTbl) {
                        updateTableTotals(curTbl);
                        const curTbody = document.querySelector(`#table-${curTbl} tbody`);
                        const totalCount = curTbody ? curTbody.querySelectorAll('tr:not(.tr-empty)').length : 0;
                        const badge = document.getElementById('count-' + curTbl);
                        if (badge) badge.innerText = totalCount;
                    }
                    showToast('Baris dihapus. Klik Simpan untuk memperbarui database.');
                }
            }
        }

        // =============================================================
        // EVENT ue_bc11() & w_bc_ceisa_getbc11 MODAL INTEGRATION
        // Persis PowerBuilder w_bc_ceisa40_interface2.srw
        // =============================================================
        let ueBc11CurrentResult = null;
        let ueBc11TpsList = [];
        let ueBc11SelectedTps = '';

        function triggerUeBc11() {
            if (!currentAju || !currentData.header) {
                alert('Silakan pilih atau buat dokumen AJU terlebih dahulu!');
                return;
            }

            // 1. Ambil kode kantor bongkar dari header
            const hdrKantor = document.getElementById('hdr_kode_kantor_bongkar')?.value || (currentData.header.kode_kantor_bongkar || '');
            document.getElementById('ueBc11Kantor').value = hdrKantor;

            // 2. Cari Host BL (kode 740 atau 705) dari Dokumen Lampiran (persis PowerBuilder)
            let hostBl = '';
            let tglHostBl = '';
            if (Array.isArray(currentData.dokumen)) {
                const found740 = currentData.dokumen.find(d => String(d.kode_dokumen).trim() === '740' || String(d.kode_dokumen).trim() === '705');
                if (found740) {
                    hostBl = found740.nomor_dokumen || '';
                    tglHostBl = found740.tanggal_dokumen || '';
                }
            }

            if (hostBl) document.getElementById('ueBc11HostBl').value = hostBl;
            if (tglHostBl) document.getElementById('ueBc11Tgl').value = formatDateYmd(tglHostBl);

            // 3. Pre-select TPS jika sudah ada di header
            const curTps = document.getElementById('hdr_kode_tps')?.value || (currentData.header.kode_tps || '');
            ueBc11SelectedTps = curTps;
            document.getElementById('ueSelectedTpsBadge').innerText = curTps || '-';

            // Buka Modal
            openModal('modalUeBc11');

            // 4. Jika Host BL ada, langsung jalankan fetch otomatis (persis PB: if isdate(ls_tgl) then cb_1.triggerevent(clicked!))
            if (hostBl) {
                fetchUeBc11Data();
            }
        }

        async function fetchUeBc11Data() {
            const hostBl = document.getElementById('ueBc11HostBl').value.trim();
            const kantor = document.getElementById('ueBc11Kantor').value.trim();
            const cmpId  = currentData.header?.cmp_id || (new URLSearchParams(window.location.search).get('cmp_id') || '');

            if (!hostBl) {
                alert('Nomor Host AWB / Host BL wajib diisi!');
                document.getElementById('ueBc11HostBl').focus();
                return;
            }

            const btn = document.getElementById('btnUeBc11Fetch');
            const badge = document.getElementById('ueBc11StatusBadge');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Mencari...';
            badge.innerText = 'Mengambil data dari Bea Cukai...';
            badge.style.background = '#dbeafe';
            badge.style.color = '#1e40af';

            try {
                const res = await fetch(`getbl.php?a=${encodeURIComponent(hostBl)}&format=json&cmp_id=${encodeURIComponent(cmpId)}`);
                const json = await res.json();

                if (json.status === 'OK' && json.data) {
                    ueBc11CurrentResult = json.data;
                    renderUeBc11Preview(json.data);

                    badge.innerText = 'Data ditemukan';
                    badge.style.background = '#dcfce7';
                    badge.style.color = '#166534';
                    document.getElementById('btnTransferUeBc11').disabled = false;

                    // Update Kantor jika kosong
                    if (!kantor && json.data.kodeKantor) {
                        document.getElementById('ueBc11Kantor').value = json.data.kodeKantor;
                    }

                    // Otomatis tarik TPS
                    fetchUeBc11Tps();
                } else {
                    badge.innerText = 'Tidak ditemukan';
                    badge.style.background = '#fee2e2';
                    badge.style.color = '#991b1b';
                    document.getElementById('btnTransferUeBc11').disabled = true;
                    alert(json.message || 'Data Manifest BC 1.1 tidak ditemukan untuk Host BL ini.');
                }
            } catch(e) {
                badge.innerText = 'Error';
                badge.style.background = '#fee2e2';
                badge.style.color = '#991b1b';
                alert('Koneksi gagal: ' + e.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i> Cari BC 1.1';
            }
        }

        function renderUeBc11Preview(d) {
            document.getElementById('prevUeNomorDaftar').innerText = d.nomorDaftar || '-';
            document.getElementById('prevUeTanggalDaftar').innerText = d.tanggalDaftar || '-';
            document.getElementById('prevUePos').innerText = d.nomorPosHeader || (d.nomorPos ? d.nomorPos.substring(0, 4) : '-');
            document.getElementById('prevUeSubPos').innerText = d.nomorSubPos || (d.nomorPos ? d.nomorPos.substring(4) : '-');
            document.getElementById('prevUeKantor').innerText = d.kodeKantor || '-';
            document.getElementById('prevUeVessel').innerText = d.motherVessel || '-';
            document.getElementById('prevUeVoyage').innerText = d.nomorVoyage_detail || d.nomorVoyage || '-';
            document.getElementById('prevUeTglTiba').innerText = d.tanggalTiba || '-';
            document.getElementById('prevUePelBongkar').innerText = d.kodePelabuhanBongkar || '-';
            document.getElementById('prevUePelMuat').innerText = d.kodePelabuhanAsal || '-';
            document.getElementById('prevUePelTransit').innerText = d.kodePelabuhanTransit || '-';
            document.getElementById('prevUePelAkhir').innerText = d.kodePelabuhanAkhir || '-';
            document.getElementById('prevUeNoBl').innerText = d.nomorBl || '-';
            document.getElementById('prevUeTglBl').innerText = d.tanggalBl || '-';
            document.getElementById('prevUeBruto').innerText = Number(d.berat || 0).toLocaleString('id-ID', { minimumFractionDigits: 2 }) + ' kg';
            document.getElementById('prevUeJmlKemas').innerText = Number(d.jumlahKemasan || 0).toLocaleString('id-ID');
            document.getElementById('prevUeKdKemas').innerText = d.jenisKemasan || '-';
            document.getElementById('prevUePengirim').innerText = d.namaPengirim || '-';
        }

        async function fetchUeBc11Tps() {
            const kantor = document.getElementById('ueBc11Kantor').value.trim();
            const cmpId  = currentData.header?.cmp_id || (new URLSearchParams(window.location.search).get('cmp_id') || '');
            const btn = document.getElementById('btnUeBc11Tps');

            if (!kantor) {
                alert('Kode Kantor Bongkar harus diisi!');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Ambil TPS...';

            try {
                const res = await fetch(`gettps.php?a=${encodeURIComponent(kantor)}&format=json&cmp_id=${encodeURIComponent(cmpId)}`);
                const json = await res.json();
                if (json.status === 'OK' && Array.isArray(json.data)) {
                    ueBc11TpsList = json.data;
                    renderUeTpsTable(ueBc11TpsList);
                } else {
                    document.getElementById('tbodyUeTps').innerHTML = `<tr><td colspan="2" style="text-align: center; color: var(--text-muted); padding: 14px;">Tidak ada TPS untuk kantor ${kantor}</td></tr>`;
                }
            } catch(e) {
                console.warn('Gagal ambil TPS:', e);
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-warehouse"></i> Get TPS';
            }
        }

        function renderUeTpsTable(list) {
            const tbody = document.getElementById('tbodyUeTps');
            tbody.innerHTML = '';

            if (list.length === 0) {
                tbody.innerHTML = `<tr><td colspan="2" style="text-align: center; color: var(--text-muted); padding: 14px;">Tidak ada TPS yang cocok</td></tr>`;
                return;
            }

            list.forEach(item => {
                const tr = document.createElement('tr');
                if (item.kodeGudang === ueBc11SelectedTps) {
                    tr.style.background = 'rgba(59, 130, 246, 0.2)';
                    tr.style.fontWeight = '700';
                }
                tr.style.cursor = 'pointer';
                tr.onclick = () => selectUeTps(item.kodeGudang, tr);
                tr.innerHTML = `
                    <td style="font-weight: 700; color: var(--primary);">${escapeHtml(item.kodeGudang)}</td>
                    <td>${escapeHtml(item.namaGudang)}</td>
                `;
                tbody.appendChild(tr);
            });
        }

        function selectUeTps(kode, trElement) {
            ueBc11SelectedTps = kode;
            document.querySelectorAll('#tbodyUeTps tr').forEach(r => {
                r.style.background = '';
                r.style.fontWeight = 'normal';
            });
            if (trElement) {
                trElement.style.background = 'rgba(59, 130, 246, 0.2)';
                trElement.style.fontWeight = '700';
            }
            document.getElementById('ueSelectedTpsBadge').innerText = kode;
        }

        function filterUeTpsList() {
            const q = document.getElementById('ueSearchTps').value.trim().toUpperCase();
            if (!q) {
                renderUeTpsTable(ueBc11TpsList);
                return;
            }
            const filtered = ueBc11TpsList.filter(item => {
                const text = `${item.kodeGudang} ${item.namaGudang}`.toUpperCase();
                return text.includes(q);
            });
            renderUeTpsTable(filtered);
        }

        // -------------------------------------------------------------
        // TRANSFER KE DOKUMEN (Persis w_bc_ceisa_getbc11 cb_2 clicked!)
        // -------------------------------------------------------------
        function applyUeBc11ToDocument() {
            if (!ueBc11CurrentResult) {
                alert('Belum ada data BC 1.1 yang dapat ditransfer!');
                return;
            }

            const d = ueBc11CurrentResult;
            const fullPos = d.nomorPos || '';
            const pos4 = (fullPos.length >= 4) ? fullPos.substring(0, 4) : fullPos;
            const subPos8 = (fullPos.length > 4) ? fullPos.substring(4) : '';

            // 1. TRANSFER KE HEADER INPUTS
            const setField = (id, val) => {
                const el = document.getElementById(id);
                if (el && val !== undefined && val !== null) {
                    el.value = val;
                    if (el.classList.contains('hdr-date-input')) {
                        const grp = el.closest('.input-date-group');
                        if (grp) {
                            const nat = grp.querySelector('.hdr-date-native');
                            if (nat) nat.value = val;
                        }
                    }
                }
            };

            setField('hdr_nomor_bc11', d.nomorDaftar || '');
            setField('hdr_tanggal_bc11', formatDateYmd(d.tanggalDaftar || ''));
            setField('hdr_nomor_pos', pos4);
            setField('hdr_nomor_sub_pos', subPos8);
            setField('hdr_kode_pelabuhan_bongkar', d.kodePelabuhanBongkar || '');
            setField('hdr_kode_pelabuhan_muat', d.kodePelabuhanAsal || '');
            setField('hdr_kode_pelabuhan_transit', d.kodePelabuhanTransit || '');
            setField('hdr_kode_pelabuhan_muat_akhir', d.kodePelabuhanAkhir || '');
            setField('hdr_kode_kantor_bongkar', d.kodeKantor || '');
            setField('hdr_kode_tutup_pu', '11');
            setField('hdr_kode_tujuan_tpb', '1');
            setField('hdr_kode_asuransi', 'LN');
            setField('hdr_kode_jasa_kena_pajak', '1');

            if (ueBc11SelectedTps) {
                setField('hdr_kode_tps', ueBc11SelectedTps);
            }

            // Tanggal Tiba format YYYY-MM-DD
            if (d.tanggalTiba) {
                let tglTibaFormatted = formatDateYmd(d.tanggalTiba);
                setField('hdr_tanggal_tiba', tglTibaFormatted);
            }

            // Bruto jika saat ini 0
            const curBruto = parseInputNumber(document.getElementById('hdr_bruto')?.value || 0);
            if (curBruto === 0 && d.berat) {
                const decBerat = headerNumberDecimals.bruto || 4;
                setField('hdr_bruto', formatCeisaNumber(d.berat, decBerat));
            }

            // Sync ke currentData.header
            if (currentData.header) {
                currentData.header.nomor_bc11 = d.nomorDaftar || '';
                currentData.header.tanggal_bc11 = formatDateYmd(d.tanggalDaftar || '');
                currentData.header.nomor_pos = pos4;
                currentData.header.nomor_sub_pos = subPos8;
                currentData.header.kode_pelabuhan_bongkar = d.kodePelabuhanBongkar || '';
                currentData.header.kode_pelabuhan_muat = d.kodePelabuhanAsal || '';
                currentData.header.kode_pelabuhan_transit = d.kodePelabuhanTransit || '';
                currentData.header.kode_pelabuhan_muat_akhir = d.kodePelabuhanAkhir || '';
                currentData.header.kode_kantor_bongkar = d.kodeKantor || '';
                currentData.header.kode_tutup_pu = '11';
                currentData.header.kode_tujuan_tpb = '1';
                currentData.header.kode_asuransi = 'LN';
                currentData.header.kode_jasa_kena_pajak = '1';
                if (ueBc11SelectedTps) currentData.header.kode_tps = ueBc11SelectedTps;
                if (d.tanggalTiba) currentData.header.tanggal_tiba = formatDateYmd(d.tanggalTiba);
            }

            // 2. TRANSFER KE KEMASAN
            const kodeKemasan = d.jenisKemasan || '';
            const jmlKemasan = parseFloat(d.jumlahKemasan) || 0;
            if (kodeKemasan) {
                if (!Array.isArray(currentData.kemasan)) currentData.kemasan = [];
                if (currentData.kemasan.length === 0) {
                    currentData.kemasan.push({ seri: 1, kode_kemasan: kodeKemasan, jumlah_kemasan: jmlKemasan, merek: '' });
                } else if (currentData.kemasan.length === 1) {
                    currentData.kemasan[0].kode_kemasan = kodeKemasan;
                    currentData.kemasan[0].jumlah_kemasan = jmlKemasan;
                }
                populateTable('kemasan', currentData.kemasan);

                // Update kemasan barang jika ada
                if (Array.isArray(currentData.barang)) {
                    currentData.barang.forEach(b => {
                        b.kode_kemasan = kodeKemasan;
                        if (currentData.barang.length === 1) b.jumlah_kemasan = jmlKemasan;
                    });
                    populateTable('barang', currentData.barang);
                }

                // Update kontainer jika 1 baris
                if (Array.isArray(currentData.kontainer) && currentData.kontainer.length === 1) {
                    currentData.kontainer[0].kode_kemasan = kodeKemasan;
                    currentData.kontainer[0].jumlah_kemasan = jmlKemasan;
                    populateTable('kontainer', currentData.kontainer);
                }
            }

            // 3. TRANSFER KE DOKUMEN LAMPIRAN (Seri 1: 380, Seri 2: 740/705, Seri 3: 741)
            const kodeKantor = d.kodeKantor || document.getElementById('hdr_kode_kantor_bongkar')?.value || '';
            const kodeDokHost = (kodeKantor === '040300') ? '705' : '740';

            if (!Array.isArray(currentData.dokumen)) currentData.dokumen = [];

            // Seri 1: Invoice (380)
            let dok1 = currentData.dokumen.find(doc => parseInt(doc.seri, 10) === 1);
            if (!dok1) {
                dok1 = { seri: 1, kode_dokumen: '380', nomor_dokumen: '', tanggal_dokumen: '' };
                currentData.dokumen.push(dok1);
            } else if (!dok1.kode_dokumen) {
                dok1.kode_dokumen = '380';
            }

            // Seri 2: Host BL (740 / 705)
            let dok2 = currentData.dokumen.find(doc => parseInt(doc.seri, 10) === 2);
            if (!dok2) {
                dok2 = { seri: 2, kode_dokumen: kodeDokHost, nomor_dokumen: d.nomorHostBl || '', tanggal_dokumen: formatDateYmd(d.tanggalHostBl || '') };
                currentData.dokumen.push(dok2);
            } else {
                dok2.kode_dokumen = kodeDokHost;
                if (d.nomorHostBl) dok2.nomor_dokumen = d.nomorHostBl;
                if (d.tanggalHostBl) dok2.tanggal_dokumen = formatDateYmd(d.tanggalHostBl);
            }

            // Seri 3: Master BL (741)
            if (d.nomorBl) {
                let dok3 = currentData.dokumen.find(doc => parseInt(doc.seri, 10) === 3);
                if (!dok3) {
                    dok3 = { seri: 3, kode_dokumen: '741', nomor_dokumen: d.nomorBl, tanggal_dokumen: formatDateYmd(d.tanggalBl || '') };
                    currentData.dokumen.push(dok3);
                } else {
                    dok3.kode_dokumen = '741';
                    dok3.nomor_dokumen = d.nomorBl;
                    if (d.tanggalBl) dok3.tanggal_dokumen = formatDateYmd(d.tanggalBl);
                }
            }

            // Urutkan dokumen berdasarkan nomor seri
            currentData.dokumen.sort((a, b) => (parseInt(a.seri, 10) || 0) - (parseInt(b.seri, 10) || 0));
            populateTable('dokumen', currentData.dokumen);

            // 4. TRANSFER KE PENGANGKUT
            if (!Array.isArray(currentData.pengangkut)) currentData.pengangkut = [];
            const caraAngkut = (d.kodePelabuhanBongkar === 'IDCGK') ? '4' : '1';
            const vesselName = d.motherVessel || '';
            const voyNo = d.nomorVoyage_detail || d.nomorVoyage || '';
            const bendera = (d.namaPelabuhanAsal && d.namaPelabuhanAsal.length >= 2) ? d.namaPelabuhanAsal.substring(0, 2).toUpperCase() : '';

            if (currentData.pengangkut.length === 0) {
                currentData.pengangkut.push({
                    seri: 1,
                    kode_cara_angkut: caraAngkut,
                    nama_pengangkut: vesselName,
                    nomor_pengangkut: voyNo,
                    kode_bendera: bendera,
                    call_sign: ''
                });
            } else {
                currentData.pengangkut[0].kode_cara_angkut = caraAngkut;
                if (vesselName) currentData.pengangkut[0].nama_pengangkut = vesselName;
                if (voyNo) currentData.pengangkut[0].nomor_pengangkut = voyNo;
                if (bendera) currentData.pengangkut[0].kode_bendera = bendera;
            }
            populateTable('pengangkut', currentData.pengangkut);

            // Tutup Modal & Notifikasi Sukses
            closeModal('modalUeBc11');
            showToast('✅ Data BC 1.1 berhasil ditransfer ke Header, Kemasan, Dokumen & Pengangkut!');
        }

        window.addEventListener('DOMContentLoaded', init);
    </script>
</body>
</html>

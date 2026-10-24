<?php
/**
 * CEISA 4.0 - IT Inventory Web Application
 * Style: Ant Design 5.0 / Metronic Clean Dashboard
 * Sesuai sumber PowerBuilder:
 *   - w_rpt_1a.srw & d_rpt_1a.srd (1A - Pemasukan Barang)
 *   - w_rpt_1b.srw & d_rpt_1b.srd (1B - Pengeluaran Barang)
 *   - w_rpt_1c.srw & d_rpt_1c.srd (1C - Posisi Barang Dalam Proses / WIP)
 *   - w_rpt_1d.srw & d_rpt_1d_2025.srd (1D - Pertanggungjawaban Mutasi Barang)
 *   - w_bc_ceisa_unposting_rpt.srw (Log Perubahan Data & Revisi)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/koneksi.php';

// Authentication & Session Guard
$user_id   = $_SESSION['user_id'] ?? 'user';
$user_name = $_SESSION['user_name'] ?? 'User Operator';
$user_role = $_SESSION['user_role'] ?? 'operator';
$cmp_id    = $_GET['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? 'ALL'));
$cmp_name  = $_SESSION['company_name'] ?? '';
$is_admin  = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1 ? 1 : 0;
if (isset($_GET['is_admin'])) {
    $is_admin = (int)$_GET['is_admin'];
}

$escCmp = (!empty($cmp_id) && $cmp_id !== 'ALL' && isset($koneksi) && $koneksi) ? $koneksi->real_escape_string($cmp_id) : '';
$cmpFilter = (!empty($escCmp)) ? " AND (cmp_id = '$escCmp' OR cmp_id = '')" : "";
$cmpHdrFilter = (!empty($escCmp)) ? " AND (h.cmp_id = '$escCmp' OR h.cmp_id = '')" : "";

$is_inv = 1;
$targetCompanyRoles = 'tpb';
$targetCompanyAllowedDocs = null;
if (!empty($cmp_id) && $cmp_id !== 'ALL' && isset($koneksi) && $koneksi) {
    $qInv = $koneksi->query("SELECT IFNULL(IS_INV, 1) as is_inv, cmp_name, IFNULL(company_roles, 'tpb') as company_roles, allowed_docs FROM bc_ceisa_company WHERE cmp_id = '" . $koneksi->real_escape_string($cmp_id) . "' LIMIT 1");
    if ($qInv && $row = $qInv->fetch_assoc()) {
        $is_inv = (int)$row['is_inv'];
        if (empty($cmp_name) && !empty($row['cmp_name'])) {
            $cmp_name = $row['cmp_name'];
        }
        $targetCompanyRoles = $row['company_roles'];
        $targetCompanyAllowedDocs = $row['allowed_docs'];
    }
} else {
    if (!empty($_SESSION['company_roles'])) {
        $targetCompanyRoles = $_SESSION['company_roles'];
    }
    if (isset($_SESSION['allowed_docs_raw'])) {
        $targetCompanyAllowedDocs = $_SESSION['allowed_docs_raw'];
    }
}
if (empty($cmp_name)) {
    $cmp_name = 'PT. FRAMAS INDONESIA';
}

$companyAllowedDocs = ceisa_resolve_allowed_docs($targetCompanyRoles, $targetCompanyAllowedDocs);
$effectiveAllowedDocs = ceisa_get_effective_allowed_docs($companyAllowedDocs);

if (!function_exists('isItDocPermitted')) {
    function isItDocPermitted($d) {
        global $is_admin, $cmp_id, $companyAllowedDocs, $effectiveAllowedDocs;
        if (!empty($is_admin) && (empty($cmp_id) || $cmp_id === 'ALL')) {
            return true;
        }
        if (!empty($is_admin)) {
            return in_array((string)$d, $companyAllowedDocs);
        }
        return in_array((string)$d, $effectiveAllowedDocs);
    }
}

$allowedInbound = array_values(array_filter(['20', '23', '40', '16', '261', '28', '611'], 'isItDocPermitted'));
$allowedOutbound = array_values(array_filter(['30', '25', '41', '262', '33', '331', '613', '631'], 'isItDocPermitted'));

// AJAX Endpoint: Detail Arsip Revisi (bc_1ab2_revisi)
if (isset($_GET['action']) && $_GET['action'] === 'get_revisi') {
    $nomor_aju = $_GET['aju'] ?? '';
    $cek_id    = (int)($_GET['cek'] ?? 0);
    $stmt = $koneksi->prepare("SELECT * FROM bc_1ab2_revisi WHERE (nomor_aju = ? OR CEK = ?)$cmpFilter");
    if ($stmt) {
        $stmt->bind_param("si", $nomor_aju, $cek_id);
        $stmt->execute();
        $res = $stmt->get_result();
        echo '<table class="ant-table" style="width:100%; border:1px solid var(--border-table);">';
        echo '<thead><tr><th>No</th><th>BC</th><th>No Daftar</th><th>Kode Barang</th><th>Uraian</th><th style="text-align:right;">Qty Aktual</th><th>Unit</th><th style="text-align:right;">Nilai</th><th>Keterangan</th></tr></thead><tbody>';
        $no = 1;
        if ($res->num_rows === 0) {
            echo '<tr><td colspan="9" style="text-align:center; padding:16px; color:var(--text-muted);">Tidak ada rincian data arsip untuk request ini.</td></tr>';
        } else {
            while ($rv = $res->fetch_assoc()) {
                echo '<tr>';
                echo '<td style="text-align:center;">' . $no++ . '</td>';
                echo '<td><span class="ant-badge ant-badge-blue">' . htmlspecialchars($rv['BC'] ?? '-') . '</span></td>';
                echo '<td>' . htmlspecialchars($rv['NOMOR_DAFTAR'] ?? '-') . '</td>';
                echo '<td style="font-family:monospace; font-weight:600;">' . htmlspecialchars($rv['KODE_BARANG'] ?? '-') . '</td>';
                echo '<td>' . htmlspecialchars($rv['URAIAN'] ?? '-') . '</td>';
                echo '<td style="text-align:right; font-weight:600;">' . number_format((float)($rv['QTY_AKTUAL'] ?? 0), 2) . '</td>';
                echo '<td>' . htmlspecialchars($rv['UNIT'] ?? '-') . '</td>';
                echo '<td style="text-align:right; font-weight:600; color:#52c41a;">' . number_format((float)($rv['NILAI'] ?? 0), 2) . '</td>';
                echo '<td>' . htmlspecialchars($rv['MEMO'] ?? '-') . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        $stmt->close();
    }
    exit;
}

$theme = $_GET['theme'] ?? 'light';
$activeTab = $_GET['tab'] ?? '';
if (empty($activeTab)) {
    $activeTab = ($user_role === 'bc') ? 'home' : '1a';
}
if (!in_array($activeTab, ['home', '1a', '1b', '1c', '1d', 'log'])) {
    $activeTab = 'home';
}

// Format Default Tanggal
$tgl_awal_default  = date('Y-01-01');
$tgl_akhir_default = date('Y-m-d');

$tgl1 = $_GET['tgl1'] ?? $tgl_awal_default;
$tgl2 = $_GET['tgl2'] ?? $tgl_akhir_default;
$bc_filter = $_GET['bc'] ?? '%';
if ($bc_filter !== '%' && !empty($bc_filter) && !isItDocPermitted($bc_filter)) {
    $bc_filter = '%';
}
$search_field = $_GET['search_field'] ?? 'all';
$search_kw = trim($_GET['search_kw'] ?? '');
$klas_filter = trim($_GET['klas'] ?? '');
if (empty($klas_filter) || $klas_filter === '%') {
    $klas_filter = 'Raw Materials';
}

// Helper Format Angka
function fnum($val, $dec = 2) {
    if ($val === null || $val === '') return '0.00';
    return number_format((float)$val, $dec, '.', ',');
}

// Ambil Referensi Jenis Klas dari bc_tipe untuk Tab 1D Mutasi Barang
// Filter sesuai spesifikasi: RM, FG, WI, CG (Mold, Machine, Part Machine, Tools, Factory Equipment), dan W
// Mengacu ke SQL: SELECT * FROM bc_tipe WHERE NOT grp IN ('FA', 'Consumables') ORDER BY idx
$cgTypes = [];
$resKlas = $koneksi->query("SELECT * FROM bc_tipe WHERE NOT grp IN ('FA', 'Consumables') ORDER BY idx");
if ($resKlas) {
    while ($rk = $resKlas->fetch_assoc()) {
        $g = trim($rk['grp'] ?? '');
        $t = trim($rk['tipe'] ?? '');
        if ($g === 'CG' && !empty($t)) {
            $cgTypes[] = $t;
        }
    }
}
if (empty($cgTypes)) {
    $cgTypes = ['Mold', 'Machine', 'Part Machine', 'Tools', 'Factory Equipment'];
}

// =========================================================================
// QUERY BUILDER BERDASARKAN TAB AKTIF
// =========================================================================

$dataRows = [];
$stats = [
    'total_rows' => 0,
    'total_qty'  => 0,
    'total_nilai' => 0,
    'total_cif'  => 0,
    'total_awal' => 0,
    'total_masuk' => 0,
    'total_keluar' => 0,
    'total_adj' => 0,
    'total_akhir' => 0,
    'total_so' => 0,
    'total_selisih' => 0
];

if ($activeTab === 'home') {
    // Mode Dashboard Home untuk Bea Cukai - tidak perlu jalankan query data tabel besar
    $dataRows = [];
} elseif ($activeTab === '1a') {
    // -------------------------------------------------------------
    // TAB 1A: PEMASUKAN BARANG (Inbound)
    // -------------------------------------------------------------
    $check1ab = $koneksi->query("SELECT COUNT(*) FROM bc_1ab2 WHERE ab = 'A'$cmpFilter")->fetch_row()[0];

    if ($check1ab > 0) {
        $sql = "SELECT ID_HEADER ID, SERI_BARANG ID_BRG, BC JENIS_DOC, NOMOR_DAFTAR NO_DOC, TANGGAL_DAFTAR TGL_DOC, 
                NO_BUKTI, TGL_BUKTI, NAMA_BS NAMA_PENERIMA_BARANG, NAMA_BS VENDOR, KODE_BARANG, URAIAN NAMA_BARANG, 
                QTY_AKTUAL JUMLAH, UNIT SATUAN, NILAI, POS_TARIF, NOMOR_AJU NO_AJU, SJ, INVOICE, FAKTUR, NOMOR_PO, 
                TUJUAN_PENGIRIMAN, TUJUAN_PEMASUKAN, ifnull(KURS, 'IDR') KODE_VALUTA, KLAS, 
                coalesce(TGL_PROSES, TANGGAL_DAFTAR) TANGGAL_PROSES, NETTO, CIF 
                FROM bc_1ab2 
                WHERE ab = 'A' AND coalesce(TGL_PROSES, TANGGAL_DAFTAR) BETWEEN ? AND ? $cmpFilter ";
        $params = [$tgl1, $tgl2];
        $types = "ss";

        if ($bc_filter !== '%' && !empty($bc_filter)) {
            $sql .= " AND BC = ? ";
            $params[] = $bc_filter;
            $types .= "s";
        } elseif (!($is_admin && (empty($cmp_id) || $cmp_id === 'ALL'))) {
            $inList = !empty($allowedInbound) ? "'" . implode("','", array_map([$koneksi, 'real_escape_string'], $allowedInbound)) . "'" : "'__NONE__'";
            $sql .= " AND BC IN ($inList) ";
        }

        if (!empty($search_kw)) {
            if ($search_field === 'no_daftar') {
                $sql .= " AND NOMOR_DAFTAR LIKE ? ";
                $params[] = "%$search_kw%";
                $types .= "s";
            } elseif ($search_field === 'kode_barang') {
                $sql .= " AND KODE_BARANG LIKE ? ";
                $params[] = "%$search_kw%";
                $types .= "s";
            } elseif ($search_field === 'nama_barang') {
                $sql .= " AND URAIAN LIKE ? ";
                $params[] = "%$search_kw%";
                $types .= "s";
            } elseif ($search_field === 'vendor') {
                $sql .= " AND NAMA_BS LIKE ? ";
                $params[] = "%$search_kw%";
                $types .= "s";
            } elseif ($search_field === 'no_bukti') {
                $sql .= " AND NO_BUKTI LIKE ? ";
                $params[] = "%$search_kw%";
                $types .= "s";
            } else {
                $sql .= " AND (KODE_BARANG LIKE ? OR URAIAN LIKE ? OR NOMOR_DAFTAR LIKE ? OR NAMA_BS LIKE ? OR NOMOR_AJU LIKE ?) ";
                $kw = "%$search_kw%";
                $params[] = $kw; $params[] = $kw; $params[] = $kw; $params[] = $kw; $params[] = $kw;
                $types .= "sssss";
            }
        }
        $sql .= " ORDER BY TGL_DOC DESC, NO_DOC DESC, ID_BRG ASC LIMIT 1000";

        $stmt = $koneksi->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $dataRows[] = $row;
                $stats['total_qty'] += (float)$row['JUMLAH'];
                $stats['total_nilai'] += (float)$row['NILAI'];
                $stats['total_cif'] += (float)$row['CIF'];
            }
            $stmt->close();
        }
    } else {
        // Fallback / Live CEISA Headers for Inbound
        // Inbound BC Codes: 20, 23, 40, 16, 261, 28, 611
        $inListFallback = !empty($allowedInbound) ? "'" . implode("','", array_map([$koneksi, 'real_escape_string'], $allowedInbound)) . "'" : "'__NONE__'";
        if ($is_admin && (empty($cmp_id) || $cmp_id === 'ALL')) {
            $inListFallback = "'20', '23', '40', '16', '261', '28', '611'";
        }
        $sql = "SELECT h.nomor_aju AS ID, b.seri_barang AS ID_BRG, CONCAT('BC ', h.kode_dokumen) AS JENIS_DOC,
                h.nomor_daftar AS NO_DOC, h.tanggal_daftar AS TGL_DOC,
                (SELECT d.nomor_dokumen FROM bc_ceisa_dokumen d WHERE d.nomor_aju = h.nomor_aju AND d.kode_dokumen IN ('380','217','999') LIMIT 1) AS NO_BUKTI,
                (SELECT d.tanggal_dokumen FROM bc_ceisa_dokumen d WHERE d.nomor_aju = h.nomor_aju AND d.kode_dokumen IN ('380','217','999') LIMIT 1) AS TGL_BUKTI,
                coalesce((SELECT e.nama_entitas FROM bc_ceisa_entitas e WHERE e.nomor_aju = h.nomor_aju LIMIT 1), 'PERUSAHAAN') AS NAMA_PENERIMA_BARANG,
                coalesce((SELECT e.nama_entitas FROM bc_ceisa_entitas e WHERE e.nomor_aju = h.nomor_aju AND e.kode_entitas IN ('PENGIRIM','PENJUAL','PEMASOK','5','7') LIMIT 1), 'PEMASOK') AS VENDOR,
                b.kode_barang AS KODE_BARANG, b.uraian AS NAMA_BARANG, b.jumlah_satuan AS JUMLAH, b.kode_satuan AS SATUAN,
                coalesce(b.cif, b.harga_penyerahan, 0) AS NILAI, b.hs AS POS_TARIF, h.nomor_aju AS NO_AJU,
                (SELECT d.nomor_dokumen FROM bc_ceisa_dokumen d WHERE d.nomor_aju = h.nomor_aju AND d.kode_dokumen = '217' LIMIT 1) AS SJ,
                (SELECT d.nomor_dokumen FROM bc_ceisa_dokumen d WHERE d.nomor_aju = h.nomor_aju AND d.kode_dokumen = '380' LIMIT 1) AS INVOICE,
                (SELECT d.nomor_dokumen FROM bc_ceisa_dokumen d WHERE d.nomor_aju = h.nomor_aju AND d.kode_dokumen = '408' LIMIT 1) AS FAKTUR,
                '' AS NOMOR_PO, '' AS TUJUAN_PENGIRIMAN, '' AS TUJUAN_PEMASUKAN,
                coalesce(h.kode_valuta, 'IDR') AS KODE_VALUTA, b.tipe AS KLAS,
                h.tanggal_daftar AS TANGGAL_PROSES, b.netto AS NETTO, b.cif AS CIF
                FROM bc_ceisa_header h
                JOIN bc_ceisa_barang b ON h.nomor_aju = b.nomor_aju
                WHERE h.kode_dokumen IN ($inListFallback)$cmpHdrFilter ";

        $params = [];
        $types = "";

        if ($bc_filter !== '%' && !empty($bc_filter)) {
            $cleanBc = str_replace('BC ', '', $bc_filter);
            $sql .= " AND h.kode_dokumen = ? ";
            $params[] = $cleanBc;
            $types .= "s";
        }

        if (!empty($search_kw)) {
            if ($search_field === 'no_daftar') {
                $sql .= " AND h.nomor_daftar LIKE ? ";
                $params[] = "%$search_kw%";
                $types .= "s";
            } elseif ($search_field === 'kode_barang') {
                $sql .= " AND b.kode_barang LIKE ? ";
                $params[] = "%$search_kw%";
                $types .= "s";
            } elseif ($search_field === 'nama_barang') {
                $sql .= " AND b.uraian LIKE ? ";
                $params[] = "%$search_kw%";
                $types .= "s";
            } elseif ($search_field === 'no_aju') {
                $sql .= " AND h.nomor_aju LIKE ? ";
                $params[] = "%$search_kw%";
                $types .= "s";
            } else {
                $sql .= " AND (b.kode_barang LIKE ? OR b.uraian LIKE ? OR h.nomor_daftar LIKE ? OR h.nomor_aju LIKE ?) ";
                $kw = "%$search_kw%";
                $params[] = $kw; $params[] = $kw; $params[] = $kw; $params[] = $kw;
                $types .= "ssss";
            }
        }
        $sql .= " ORDER BY h.tanggal_daftar DESC, h.nomor_daftar DESC, b.seri_barang ASC LIMIT 1000";

        if (!empty($params)) {
            $stmt = $koneksi->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $dataRows[] = $row;
                    $stats['total_qty'] += (float)$row['JUMLAH'];
                    $stats['total_nilai'] += (float)$row['NILAI'];
                    $stats['total_cif'] += (float)$row['CIF'];
                }
                $stmt->close();
            }
        } else {
            $res = $koneksi->query($sql);
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $dataRows[] = $row;
                    $stats['total_qty'] += (float)$row['JUMLAH'];
                    $stats['total_nilai'] += (float)$row['NILAI'];
                    $stats['total_cif'] += (float)$row['CIF'];
                }
            }
        }
    }
} elseif ($activeTab === '1b') {
    // -------------------------------------------------------------
    // TAB 1B: PENGELUARAN BARANG (Outbound)
    // -------------------------------------------------------------
    $check1ab = $koneksi->query("SELECT COUNT(*) FROM bc_1ab2 WHERE ab = 'B'$cmpFilter")->fetch_row()[0];

    if ($check1ab > 0) {
        $sql = "SELECT ID_HEADER ID, SERI_BARANG ID_BRG, BC JENIS_DOC, NOMOR_DAFTAR NO_DOC, TANGGAL_DAFTAR TGL_DOC, 
                NO_BUKTI, TGL_BUKTI, NAMA_BS NAMA_PENERIMA_BARANG, NAMA_BS VENDOR, KODE_BARANG, URAIAN NAMA_BARANG, 
                QTY_AKTUAL JUMLAH, UNIT SATUAN, NILAI, POS_TARIF, NOMOR_AJU NO_AJU, SJ, INVOICE, FAKTUR, NOMOR_PO, 
                TUJUAN_PENGIRIMAN, TUJUAN_PEMASUKAN, ifnull(KURS, 'IDR') KODE_VALUTA, KLAS, 
                coalesce(TGL_PROSES, TANGGAL_DAFTAR) TANGGAL_PROSES, NETTO, CIF 
                FROM bc_1ab2 
                WHERE ab = 'B' AND coalesce(TGL_PROSES, TANGGAL_DAFTAR) BETWEEN ? AND ? $cmpFilter ";
        $params = [$tgl1, $tgl2];
        $types = "ss";

        if ($bc_filter !== '%' && !empty($bc_filter)) {
            $sql .= " AND BC = ? ";
            $params[] = $bc_filter;
            $types .= "s";
        } elseif (!($is_admin && (empty($cmp_id) || $cmp_id === 'ALL'))) {
            $inList = !empty($allowedOutbound) ? "'" . implode("','", array_map([$koneksi, 'real_escape_string'], $allowedOutbound)) . "'" : "'__NONE__'";
            $sql .= " AND BC IN ($inList) ";
        }

        if (!empty($search_kw)) {
            if ($search_field === 'no_daftar') {
                $sql .= " AND NOMOR_DAFTAR LIKE ? ";
                $params[] = "%$search_kw%";
                $types .= "s";
            } elseif ($search_field === 'kode_barang') {
                $sql .= " AND KODE_BARANG LIKE ? ";
                $params[] = "%$search_kw%";
                $types .= "s";
            } elseif ($search_field === 'nama_barang') {
                $sql .= " AND URAIAN LIKE ? ";
                $params[] = "%$search_kw%";
                $types .= "s";
            } elseif ($search_field === 'vendor') {
                $sql .= " AND NAMA_BS LIKE ? ";
                $params[] = "%$search_kw%";
                $types .= "s";
            } else {
                $sql .= " AND (KODE_BARANG LIKE ? OR URAIAN LIKE ? OR NOMOR_DAFTAR LIKE ? OR NAMA_BS LIKE ? OR NOMOR_AJU LIKE ?) ";
                $kw = "%$search_kw%";
                $params[] = $kw; $params[] = $kw; $params[] = $kw; $params[] = $kw; $params[] = $kw;
                $types .= "sssss";
            }
        }
        $sql .= " ORDER BY TGL_DOC DESC, NO_DOC DESC, ID_BRG ASC LIMIT 1000";

        $stmt = $koneksi->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $dataRows[] = $row;
                $stats['total_qty'] += (float)$row['JUMLAH'];
                $stats['total_nilai'] += (float)$row['NILAI'];
                $stats['total_cif'] += (float)$row['CIF'];
            }
            $stmt->close();
        }
    } else {
        // Fallback / Live CEISA Headers for Outbound
        // Outbound BC Codes: 30, 25, 41, 262, 33, 331, 613, 631
        $outListFallback = !empty($allowedOutbound) ? "'" . implode("','", array_map([$koneksi, 'real_escape_string'], $allowedOutbound)) . "'" : "'__NONE__'";
        if ($is_admin && (empty($cmp_id) || $cmp_id === 'ALL')) {
            $outListFallback = "'30', '25', '41', '262', '33', '331', '613', '631'";
        }
        $sql = "SELECT h.nomor_aju AS ID, b.seri_barang AS ID_BRG, CONCAT('BC ', h.kode_dokumen) AS JENIS_DOC,
                h.nomor_daftar AS NO_DOC, h.tanggal_daftar AS TGL_DOC,
                (SELECT d.nomor_dokumen FROM bc_ceisa_dokumen d WHERE d.nomor_aju = h.nomor_aju AND d.kode_dokumen IN ('380','217','999') LIMIT 1) AS NO_BUKTI,
                (SELECT d.tanggal_dokumen FROM bc_ceisa_dokumen d WHERE d.nomor_aju = h.nomor_aju AND d.kode_dokumen IN ('380','217','999') LIMIT 1) AS TGL_BUKTI,
                coalesce((SELECT e.nama_entitas FROM bc_ceisa_entitas e WHERE e.nomor_aju = h.nomor_aju AND e.kode_entitas IN ('PENERIMA','PEMBELI','IMPORTIR','3','8') LIMIT 1), 'PENERIMA') AS NAMA_PENERIMA_BARANG,
                coalesce((SELECT e.nama_entitas FROM bc_ceisa_entitas e WHERE e.nomor_aju = h.nomor_aju LIMIT 1), 'PERUSAHAAN') AS VENDOR,
                b.kode_barang AS KODE_BARANG, b.uraian AS NAMA_BARANG, b.jumlah_satuan AS JUMLAH, b.kode_satuan AS SATUAN,
                coalesce(b.harga_penyerahan, b.cif, b.harga_ekspor, 0) AS NILAI, b.hs AS POS_TARIF, h.nomor_aju AS NO_AJU,
                (SELECT d.nomor_dokumen FROM bc_ceisa_dokumen d WHERE d.nomor_aju = h.nomor_aju AND d.kode_dokumen = '217' LIMIT 1) AS SJ,
                (SELECT d.nomor_dokumen FROM bc_ceisa_dokumen d WHERE d.nomor_aju = h.nomor_aju AND d.kode_dokumen = '380' LIMIT 1) AS INVOICE,
                (SELECT d.nomor_dokumen FROM bc_ceisa_dokumen d WHERE d.nomor_aju = h.nomor_aju AND d.kode_dokumen = '408' LIMIT 1) AS FAKTUR,
                '' AS NOMOR_PO, '' AS TUJUAN_PENGIRIMAN, '' AS TUJUAN_PEMASUKAN,
                coalesce(h.kode_valuta, 'IDR') AS KODE_VALUTA, b.tipe AS KLAS,
                h.tanggal_daftar AS TANGGAL_PROSES, b.netto AS NETTO, b.cif AS CIF
                FROM bc_ceisa_header h
                JOIN bc_ceisa_barang b ON h.nomor_aju = b.nomor_aju
                WHERE h.kode_dokumen IN ($outListFallback)$cmpHdrFilter ";

        $params = [];
        $types = "";

        if ($bc_filter !== '%' && !empty($bc_filter)) {
            $cleanBc = str_replace('BC ', '', $bc_filter);
            $sql .= " AND h.kode_dokumen = ? ";
            $params[] = $cleanBc;
            $types .= "s";
        }

        if (!empty($search_kw)) {
            if ($search_field === 'no_daftar') {
                $sql .= " AND h.nomor_daftar LIKE ? ";
                $params[] = "%$search_kw%";
                $types .= "s";
            } elseif ($search_field === 'kode_barang') {
                $sql .= " AND b.kode_barang LIKE ? ";
                $params[] = "%$search_kw%";
                $types .= "s";
            } elseif ($search_field === 'nama_barang') {
                $sql .= " AND b.uraian LIKE ? ";
                $params[] = "%$search_kw%";
                $types .= "s";
            } elseif ($search_field === 'no_aju') {
                $sql .= " AND h.nomor_aju LIKE ? ";
                $params[] = "%$search_kw%";
                $types .= "s";
            } else {
                $sql .= " AND (b.kode_barang LIKE ? OR b.uraian LIKE ? OR h.nomor_daftar LIKE ? OR h.nomor_aju LIKE ?) ";
                $kw = "%$search_kw%";
                $params[] = $kw; $params[] = $kw; $params[] = $kw; $params[] = $kw;
                $types .= "ssss";
            }
        }
        $sql .= " ORDER BY h.tanggal_daftar DESC, h.nomor_daftar DESC, b.seri_barang ASC LIMIT 1000";

        if (!empty($params)) {
            $stmt = $koneksi->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $dataRows[] = $row;
                    $stats['total_qty'] += (float)$row['JUMLAH'];
                    $stats['total_nilai'] += (float)$row['NILAI'];
                    $stats['total_cif'] += (float)$row['CIF'];
                }
                $stmt->close();
            }
        } else {
            $res = $koneksi->query($sql);
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $dataRows[] = $row;
                    $stats['total_qty'] += (float)$row['JUMLAH'];
                    $stats['total_nilai'] += (float)$row['NILAI'];
                    $stats['total_cif'] += (float)$row['CIF'];
                }
            }
        }
    }
} elseif ($activeTab === '1c') {
    // -------------------------------------------------------------
    // TAB 1C: POSISI BARANG DALAM PROSES (WIP)
    // Sesuai d_rpt_1c.srd
    // -------------------------------------------------------------
    $sqlWip = "SELECT MASTER.KODE_BARANG, MASTER.URAIAN, MODEL, MASTER.KODE_SATUAN, coalesce(AWAL.STOK,0) JUMLAH 
               FROM (SELECT * FROM bc_material WHERE KLAS = 'Work In Progress'$cmpFilter) MASTER
               LEFT JOIN (
                   SELECT kode_barang, unit, sum(stok) stok
                   FROM (
                       SELECT kode_barang, unit, sum(CASE WHEN ab = 'A' THEN QTY_AKTUAL ELSE QTY_AKTUAL * -1 END) stok
                       FROM bc_1ab2
                       WHERE substr(tanggal_daftar,1,4) = substr(?,1,4) AND KLAS = 'Work In Progress' AND tanggal_daftar <= ?$cmpFilter
                       GROUP BY kode_barang, unit
                       UNION ALL
                       SELECT kode_barang, unit, sum(selisih)
                       FROM bc_so a
                       WHERE substr(tgl_so,1,4) = substr(?,1,4) AND tgl_so <= ?$cmpFilter
                       GROUP BY kode_barang, unit
                       UNION ALL
                       SELECT kode_barang, unit, stok_awal
                       FROM bc_so0 a
                       WHERE tahun = substr(?,1,4)$cmpFilter
                   ) sub
                   GROUP BY kode_barang, unit
               ) AWAL ON (MASTER.KODE_BARANG, MASTER.KODE_SATUAN) = (AWAL.KODE_BARANG, AWAL.unit) ";

    $params = [$tgl2, $tgl2, $tgl2, $tgl2, $tgl2];
    $types = "sssss";

    if (!empty($search_kw)) {
        $sqlWip .= " WHERE (MASTER.KODE_BARANG LIKE ? OR MASTER.URAIAN LIKE ? OR MASTER.MODEL LIKE ?) ";
        $kw = "%$search_kw%";
        $params[] = $kw; $params[] = $kw; $params[] = $kw;
        $types .= "sss";
    }

    $stmt = $koneksi->prepare($sqlWip);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $dataRows[] = $row;
            $stats['total_qty'] += (float)$row['JUMLAH'];
        }
        $stmt->close();
    }
} elseif ($activeTab === '1d') {
    // -------------------------------------------------------------
    // TAB 1D: MUTASI BARANG
    // Sesuai d_rpt_1d_2025.srd & w_rpt_1d.srw
    // -------------------------------------------------------------
    $klasVal = (empty($klas_filter) || $klas_filter === '%') ? 'Raw Materials' : $klas_filter;

    // Filter SQL untuk klasifikasi
    if (in_array($klasVal, ['Capital Goods', 'CG'])) {
        $klasSql = "KLAS IN ('Mold', 'Machine', 'Part Machine', 'Tools', 'Factory Equipment', 'Capital Goods')";
    } elseif ($klasVal === 'Raw Materials' || $klasVal === 'Raw Material') {
        $klasSql = "KLAS IN ('Raw Materials', 'Raw Material')";
    } elseif ($klasVal === 'Finished Goods' || $klasVal === 'Finished Good') {
        $klasSql = "KLAS IN ('Finished Goods', 'Finished Good')";
    } elseif ($klasVal === 'Work In Progress' || $klasVal === 'WIP') {
        $klasSql = "KLAS IN ('Work In Progress', 'WIP')";
    } elseif ($klasVal === 'Wastes' || $klasVal === 'Waste') {
        $klasSql = "KLAS IN ('Wastes', 'Waste')";
    } else {
        $escKlas = $koneksi->real_escape_string($klasVal);
        $klasSql = "KLAS = '$escKlas'";
    }

    $sql1d = "SELECT NI.*, (AWAL + MASUK - KELUAR) AKHIR, 
              coalesce((SELECT sum(CASE WHEN ab = 'A' THEN qty ELSE qty * -1 END) FROM bc_1ab2 X 
               WHERE tanggal_daftar BETWEEN ? AND ? AND bc = 'SPP' AND X.kode_barang = NI.KODE_BARANG AND X.UNIT = KODE_SATUAN$cmpFilter), 0) ADJ,
              coalesce((SELECT SUM(AKTUAL) FROM bc_so X 
               WHERE TGL_SO = ? AND TIPE = 'SO' AND X.kode_barang = NI.KODE_BARANG AND X.unit = KODE_SATUAN$cmpFilter), 0) SO,
              ((AWAL + MASUK - KELUAR) - 
               coalesce((SELECT SUM(AKTUAL) FROM bc_so X WHERE TGL_SO = ? AND TIPE = 'SO' AND X.kode_barang = NI.KODE_BARANG AND X.unit = KODE_SATUAN$cmpFilter), 0) -
               coalesce((SELECT sum(CASE WHEN ab = 'A' THEN qty ELSE qty * -1 END) FROM bc_1ab2 X WHERE tanggal_daftar BETWEEN ? AND ? AND bc = 'SPP' AND X.kode_barang = NI.KODE_BARANG AND X.UNIT = KODE_SATUAN$cmpFilter), 0)
              ) SELISIH
              FROM (
                  SELECT MASTER.KODE_BARANG, MASTER.URAIAN, MODEL, MASTER.KODE_SATUAN, 
                         coalesce(AWAL.STOK,0) AWAL, coalesce(MASUK.STOK,0) MASUK, coalesce(KELUAR.STOK,0) KELUAR 
                  FROM (SELECT * FROM bc_material WHERE $klasSql$cmpFilter) MASTER
                  LEFT JOIN (
                      SELECT kode_barang, unit, sum(stok) stok
                      FROM (
                          SELECT kode_barang, unit, sum(CASE WHEN ab = 'A' THEN QTY_AKTUAL ELSE QTY_AKTUAL * -1 END) stok
                          FROM bc_1ab2
                          WHERE substr(date_format(tgl_proses, '%Y-%m'), 1, 4) = substr(?, 1, 4) AND $klasSql AND tgl_proses < ?$cmpFilter
                          GROUP BY kode_barang, unit
                      ) subA GROUP BY kode_barang, unit
                  ) AWAL ON (MASTER.KODE_BARANG, MASTER.KODE_SATUAN) = (AWAL.KODE_BARANG, AWAL.unit)
                  LEFT JOIN (
                      SELECT kode_barang, unit, sum(QTY_AKTUAL) stok
                      FROM bc_1ab2
                      WHERE ab = 'A' AND $klasSql AND tgl_proses BETWEEN ? AND ?$cmpFilter
                      GROUP BY kode_barang, unit
                  ) MASUK ON (MASTER.KODE_BARANG, MASTER.KODE_SATUAN) = (MASUK.KODE_BARANG, MASUK.unit)
                  LEFT JOIN (
                      SELECT kode_barang, unit, sum(QTY_AKTUAL) stok
                      FROM bc_1ab2
                      WHERE ab = 'B' AND $klasSql AND tgl_proses BETWEEN ? AND ?$cmpFilter
                      GROUP BY kode_barang, unit
                  ) KELUAR ON (MASTER.KODE_BARANG, MASTER.KODE_SATUAN) = (KELUAR.KODE_BARANG, KELUAR.unit)
              ) NI ";

    $params = [$tgl1, $tgl2, $tgl2, $tgl2, $tgl1, $tgl2, $tgl1, $tgl1, $tgl1, $tgl2, $tgl1, $tgl2];
    $types = "ssssssssssss";

    if (!empty($search_kw)) {
        $sql1d .= " WHERE (NI.KODE_BARANG LIKE ? OR NI.URAIAN LIKE ? OR NI.MODEL LIKE ?) ";
        $kw = "%$search_kw%";
        $params[] = $kw; $params[] = $kw; $params[] = $kw;
        $types .= "sss";
    }

    $stmt = $koneksi->prepare($sql1d);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $dataRows[] = $row;
            $stats['total_awal'] += (float)$row['AWAL'];
            $stats['total_masuk'] += (float)$row['MASUK'];
            $stats['total_keluar'] += (float)$row['KELUAR'];
            $stats['total_adj'] += (float)$row['ADJ'];
            $stats['total_akhir'] += (float)$row['AKHIR'];
            $stats['total_so'] += (float)$row['SO'];
            $stats['total_selisih'] += (float)$row['SELISIH'];
        }
        $stmt->close();
    }
} elseif ($activeTab === 'log') {
    // -------------------------------------------------------------
    // TAB 5: LOG PERUBAHAN DATA & UNPOSTING
    // Sesuai w_bc_ceisa_unposting_rpt.srw
    // -------------------------------------------------------------
    $sqlLog = "SELECT r.ROWID, r.NOMOR_AJU, r.BC, r.NAMA_BS, r.TGL_REQ, r.USER_REQ, r.ALASAN, 
               r.STATUS, r.USER_APPV, r.TGL_APPV,
               (SELECT COUNT(*) FROM bc_1ab2_revisi rv WHERE rv.CEK = r.ROWID) AS JUMLAH_REVISI
               FROM bc_ceisa_req_unpost r
               WHERE 1=1 ";
    $params = [];
    $types = "";

    if (!empty($search_kw)) {
        $sqlLog .= " AND (r.NOMOR_AJU LIKE ? OR r.ALASAN LIKE ? OR r.NAMA_BS LIKE ? OR r.USER_REQ LIKE ?) ";
        $kw = "%$search_kw%";
        $params[] = $kw; $params[] = $kw; $params[] = $kw; $params[] = $kw;
        $types .= "ssss";
    }
    $sqlLog .= " ORDER BY r.ROWID DESC LIMIT 500";

    if (!empty($params)) {
        $stmt = $koneksi->prepare($sqlLog);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $dataRows[] = $row;
            }
            $stmt->close();
        }
    } else {
        $res = $koneksi->query($sqlLog);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $dataRows[] = $row;
            }
        }
    }
}

$stats['total_rows'] = count($dataRows);
?>
<!DOCTYPE html>
<html lang="id" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IT Inventory - CEISA 4.0 Host to Host</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        :root, html[data-theme="light"], body[data-theme="light"] {
            --bg-body: #f0f2f5;
            --bg-card: #ffffff;
            --border-card: #e8e8e8;
            --border-table: #e8e8e8;
            --table-head-bg: #fafafa;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --text-main: #262626;
            --text-heading: #1f1f1f;
            --text-muted: #8c8c8c;
            --bg-filter: #fafafa;
            --bg-input: #ffffff;
            --border-input: #d9d9d9;
            --row-hover: #f0f7ff;
            --row-selected: #e6f7ff;
            --row-selected-border: #1890ff;
            color-scheme: light;
        }

        html[data-theme="dark"], body[data-theme="dark"] {
            --bg-body: #141414;
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
            --row-hover: #262638;
            --row-selected: #111d2c;
            --row-selected-border: #177ddc;
            color-scheme: dark;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 10.5px;
            line-height: 1.45;
            background: var(--bg-body);
            color: var(--text-main);
            padding: 6px 10px;
            -webkit-font-smoothing: antialiased;
        }

        /* CARD STYLES */
        .ant-card {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 4px;
            margin-bottom: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            overflow: hidden;
        }
        .ant-card-head {
            min-height: 38px;
            padding: 4px 12px;
            border-bottom: 1px solid var(--border-card);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--bg-card);
            gap: 8px;
            flex-wrap: wrap;
        }
        .ant-card-head-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text-heading);
        }
        .ant-card-head-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .ant-card-body {
            padding: 8px 10px;
        }

        /* NAVIGATION TABS */
        .ant-tabs-nav {
            display: flex;
            align-items: center;
            gap: 4px;
            border-bottom: 1px solid var(--border-card);
            background: var(--bg-filter);
            padding: 4px 8px 0 8px;
            margin-bottom: 8px;
            border-radius: 4px 4px 0 0;
            overflow-x: auto;
        }
        .ant-tabs-tab {
            padding: 5px 12px;
            font-size: 10.5px;
            font-weight: 500;
            color: var(--text-muted);
            cursor: pointer;
            border: 1px solid transparent;
            border-bottom: none;
            border-radius: 4px 4px 0 0;
            background: transparent;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            white-space: nowrap;
        }
        .ant-tabs-tab:hover {
            color: var(--primary);
        }
        .ant-tabs-tab.active {
            background: var(--bg-card);
            color: var(--primary);
            font-weight: 600;
            border-color: var(--border-card);
            border-bottom-color: var(--bg-card);
            margin-bottom: -1px;
        }

        /* BUTTONS */
        .ant-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            height: 25px;
            padding: 0 10px;
            font-size: 10px;
            font-weight: 500;
            border-radius: 3px;
            border: 1px solid var(--border-input);
            background: var(--bg-card);
            color: var(--text-main);
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            white-space: nowrap;
            font-family: inherit;
        }
        .ant-btn:hover {
            color: var(--primary);
            border-color: var(--primary);
        }
        .ant-btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            color: #ffffff !important;
            font-weight: 600;
        }
        .ant-btn-primary:hover {
            background: var(--primary-hover);
            border-color: var(--primary-hover);
        }
        .ant-btn-success {
            background: #52c41a;
            border-color: #52c41a;
            color: #ffffff !important;
            font-weight: 600;
        }
        .ant-btn-success:hover {
            background: #73d13d;
            border-color: #73d13d;
        }

        /* FILTER PANEL */
        .filter-panel {
            display: flex;
            padding: 8px 10px;
            margin-bottom: 8px;
            gap: 8px;
            align-items: flex-end;
            background: var(--bg-filter);
            border: 1px solid var(--border-card);
            border-radius: 4px;
            flex-wrap: wrap;
        }
        .filter-col {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .filter-col label {
            font-size: 9px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .filter-control {
            padding: 2px 8px;
            height: 25px;
            font-size: 10.5px;
            border-radius: 3px;
            border: 1px solid var(--border-input);
            background-color: var(--bg-input);
            color: var(--text-main);
            outline: none;
            font-family: inherit;
        }
        .filter-control:focus {
            border-color: var(--primary);
        }

        /* KPI CHIPS */
        .kpi-container {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }
        .kpi-chip {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 3px;
            padding: 3px 8px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 10px;
        }
        .kpi-label {
            color: var(--text-muted);
            font-weight: 500;
            font-size: 9px;
            text-transform: uppercase;
        }
        .kpi-val {
            font-weight: 700;
            color: var(--text-heading);
        }

        /* TABLE WRAPPER */
        .ant-table-wrapper {
            width: 100%;
            border: 1px solid var(--border-table);
            border-radius: 4px;
            background: var(--bg-card);
            overflow: hidden;
        }
        .table-scroll {
            width: 100%;
            max-height: calc(100vh - 210px);
            min-height: 380px;
            overflow: auto;
        }
        .ant-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 10px;
        }
        .ant-table th {
            background: var(--table-head-bg);
            color: var(--text-heading);
            font-weight: 600;
            padding: 5px 8px;
            text-align: left;
            border-bottom: 1px solid var(--border-table);
            border-right: 1px solid var(--border-table);
            position: sticky;
            top: 0;
            z-index: 2;
            white-space: nowrap;
        }
        .ant-table th.sortable-th {
            cursor: pointer;
            user-select: none;
            transition: background 0.15s, color 0.15s;
        }
        .ant-table th.sortable-th:hover {
            background: var(--row-hover);
            color: var(--primary);
        }
        .ant-table th.sortable-th .sort-icon {
            display: inline-block;
            margin-left: 4px;
            font-size: 11px;
        }
        .ant-table td {
            padding: 4px 8px;
            border-bottom: 1px solid var(--border-table);
            border-right: 1px solid var(--border-table);
            white-space: nowrap;
            color: var(--text-main);
        }
        .ant-table tbody tr:hover td {
            background: var(--row-hover);
        }
        .ant-table tfoot th, .ant-table tfoot td {
            position: sticky;
            bottom: 0;
            background: var(--table-head-bg);
            font-weight: 700;
            border-top: 2px solid var(--border-table);
            padding: 5px 8px;
            z-index: 2;
        }

        /* BADGES */
        .ant-badge {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 9px;
            font-weight: 600;
            text-align: center;
        }
        .ant-badge-blue { background: #e6f7ff; color: #1890ff; border: 1px solid #91d5ff; }
        .ant-badge-green { background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f; }
        .ant-badge-gold { background: #fffbe6; color: #faad14; border: 1px solid #ffe58f; }
        .ant-badge-red { background: #fff1f0; color: #f5222d; border: 1px solid #ffa39e; }
        .ant-badge-purple { background: #f9f0ff; color: #722ed1; border: 1px solid #d3adf7; }

        /* MODAL */
        .ant-modal-mask {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .ant-modal {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 4px;
            width: 85%;
            max-width: 950px;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            overflow: hidden;
        }
        .ant-modal-header {
            padding: 8px 14px;
            border-bottom: 1px solid var(--border-card);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-weight: 600;
            font-size: 11.5px;
            background: var(--bg-card);
        }
        .ant-modal-body {
            padding: 12px;
            overflow-y: auto;
            flex: 1;
        }
        .ant-modal-footer {
            padding: 8px 14px;
            border-top: 1px solid var(--border-card);
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            background: var(--bg-filter);
        }

        /* =========================================================
           BEA CUKAI HOME DASHBOARD CONCEPT (PER-6/BC/2023) - COMPACT
           ========================================================= */
        .bc-home-container {
            padding: 12px 16px;
        }
        .bc-banner-card {
            background: var(--bg-filter);
            border: 1px solid var(--border-card);
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 12px;
        }
        .bc-banner-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .bc-banner-left {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .bc-banner-title-line {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .bc-banner-badge {
            background: #e6f7ff;
            color: #096dd9;
            border: 1px solid #91d5ff;
            padding: 1px 7px;
            border-radius: 3px;
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        body[data-theme="dark"] .bc-banner-badge {
            background: #111d2c;
            color: #177ddc;
            border-color: #153450;
        }
        .bc-banner-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-heading);
            letter-spacing: -0.2px;
        }
        .bc-banner-divider {
            color: var(--text-muted);
            font-size: 12px;
        }
        .bc-company-name {
            font-size: 13.5px;
            font-weight: 800;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .bc-compliance-desc {
            font-size: 11px;
            color: var(--text-muted);
            line-height: 1.4;
        }
        .bc-reg-btn {
            padding: 4px 10px;
            font-size: 10.5px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            color: var(--primary);
            border-radius: 4px;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .bc-reg-btn:hover {
            border-color: var(--primary);
            background: rgba(24, 144, 255, 0.08);
            color: var(--primary);
        }
        .bc-instruction-text {
            margin-top: 8px;
            padding-top: 6px;
            border-top: 1px dashed var(--border-card);
            font-size: 11px;
            font-weight: 600;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* TILES GRID */
        .bc-tiles-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 10px;
            margin-top: 8px;
            margin-bottom: 12px;
        }
        @media (max-width: 1199px) {
            .bc-tiles-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        @media (max-width: 680px) {
            .bc-tiles-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        .bc-tile-card {
            display: flex;
            flex-direction: column;
            text-decoration: none;
            border-radius: 6px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            transition: all 0.2s ease;
            border: 1px solid var(--border-card);
        }
        .bc-tile-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
            border-color: var(--primary);
        }
        .tile-icon-box {
            padding: 8px 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: #ffffff;
        }
        .tile-icon {
            font-size: 20px;
            line-height: 1;
        }
        .tile-code {
            font-size: 14px;
            font-weight: 800;
            letter-spacing: 0.3px;
        }
        .tile-footer {
            background: var(--bg-card);
            color: var(--text-heading);
            padding: 6px 6px;
            text-align: center;
            font-size: 11px;
            font-weight: 700;
            border-top: 1px solid var(--border-card);
            transition: background 0.2s;
            min-height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 1.25;
        }
        body[data-theme="dark"] .tile-footer {
            background: #1f1f1f;
            color: rgba(255,255,255,0.9);
            border-top: 1px solid #303030;
        }

        /* Tile Color Schemes Matching User Concept */
        .tile-blue .tile-icon-box { background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%); }
        .tile-green .tile-icon-box { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .tile-red .tile-icon-box { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
        .tile-lime .tile-icon-box { background: linear-gradient(135deg, #84cc16 0%, #65a30d 100%); }
        .tile-purple .tile-icon-box { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); }
        .tile-cyan .tile-icon-box { background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%); }
        .tile-gold .tile-icon-box { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }

        .bc-home-footer {
            margin-top: 14px;
            padding-top: 10px;
            border-top: 1px solid var(--border-card);
            font-size: 10.5px;
            color: var(--text-muted);
            text-align: center;
        }

        /* SORTABLE TABLE HEADERS */
        th.sortable-th {
            cursor: pointer;
            user-select: none;
            position: relative;
            transition: background 0.2s, color 0.2s;
        }
        th.sortable-th:hover {
            background: var(--row-hover) !important;
            color: var(--primary) !important;
        }
        th.sortable-th::after {
            content: " ↕";
            opacity: 0.35;
            font-size: 9px;
            margin-left: 4px;
        }
        th.sortable-th.sort-asc::after {
            content: " ▲";
            opacity: 1;
            color: var(--primary);
        }
        th.sortable-th.sort-desc::after {
            content: " ▼";
            opacity: 1;
            color: var(--primary);
        }
    </style>
</head>
<body data-theme="<?= htmlspecialchars($theme) ?>">

<?php if ($is_inv === 0): ?>
<div style="padding:40px 20px; text-align:center;">
    <div style="max-width:480px; margin:0 auto; background:var(--bg-card); border:1px solid var(--border-card); border-radius:6px; padding:28px 24px; box-shadow:0 2px 8px rgba(0,0,0,0.05);">
        <div style="font-size:32px; margin-bottom:10px;">📦</div>
        <div style="font-size:13px; font-weight:700; color:var(--text-heading); margin-bottom:6px;">Modul IT Inventory Non-Aktif</div>
        <div style="font-size:11px; color:var(--text-muted); line-height:1.5; margin-bottom:14px;">
            Fitur IT Inventory sedang dinonaktifkan untuk perusahaan ini. Untuk mengaktifkannya, silakan ubah status IT Inventory menjadi <strong>Aktif</strong> pada menu <em>Kelola Perusahaan</em>.
        </div>
    </div>
</div>
</body>
</html>
<?php exit; endif; ?>

<div class="ant-card">
    <!-- TOP TABS NAVIGATION -->
    <div class="ant-tabs-nav">
        <a href="?tab=home&cmp_id=<?= urlencode($cmp_id) ?>&theme=<?= $theme ?>" class="ant-tabs-tab <?= $activeTab === 'home' ? 'active' : '' ?>">
            <span>🏠</span> Dashboard Home
        </a>
        <a href="?tab=1a&cmp_id=<?= urlencode($cmp_id) ?>&theme=<?= $theme ?>" class="ant-tabs-tab <?= $activeTab === '1a' ? 'active' : '' ?>">
            <span>📦</span> 1A - Pemasukan Barang
        </a>
        <a href="?tab=1b&cmp_id=<?= urlencode($cmp_id) ?>&theme=<?= $theme ?>" class="ant-tabs-tab <?= $activeTab === '1b' ? 'active' : '' ?>">
            <span>📤</span> 1B - Pengeluaran Barang
        </a>
        <a href="?tab=1c&cmp_id=<?= urlencode($cmp_id) ?>&theme=<?= $theme ?>" class="ant-tabs-tab <?= $activeTab === '1c' ? 'active' : '' ?>">
            <span>⚙️</span> 1C - Posisi WIP
        </a>
        <a href="?tab=1d&cmp_id=<?= urlencode($cmp_id) ?>&theme=<?= $theme ?>" class="ant-tabs-tab <?= $activeTab === '1d' ? 'active' : '' ?>">
            <span>🔄</span> 1D - Mutasi Barang
        </a>
        <a href="?tab=log&cmp_id=<?= urlencode($cmp_id) ?>&theme=<?= $theme ?>" class="ant-tabs-tab <?= $activeTab === 'log' ? 'active' : '' ?>">
            <span>📜</span> Log Perubahan Data
        </a>
    </div>

    <?php if ($activeTab === 'home'): ?>
    <!-- ============================================================== -->
    <!-- DASHBOARD HOME CONCEPT KHUSUS PEJABAT BEA CUKAI & IT INVENTORY -->
    <!-- Sesuai Referensi Gambar PER-6/BC/2023 Tata Laksana Kawasan Berikat -->
    <!-- ============================================================== -->
    <div class="bc-home-container">
        <!-- COMPACT BANNER -->
        <div class="bc-banner-card">
            <div class="bc-banner-header">
                <div class="bc-banner-left">
                    <div class="bc-banner-title-line">
                        <span class="bc-banner-badge">IT INVENTORY</span>
                        <span class="bc-banner-title">Bea Cukai IT Inventory Report</span>
                        <span class="bc-banner-divider">&bull;</span>
                        <span class="bc-company-name"><?= htmlspecialchars($cmp_name) ?></span>
                    </div>
                    <div class="bc-compliance-desc">
                        Guna memenuhi ketentuan Peraturan Direktur Jenderal Bea Dan Cukai Nomor 
                        <strong style="color:var(--primary); font-weight:700;">PER-6/BC/2023</strong> / <strong style="color:var(--primary); font-weight:700;">PER-02/BC/2019</strong> 
                        Tentang Tata Laksana Kawasan Berikat dan Tempat Penimbunan Berikat.
                    </div>
                </div>
                <div class="bc-banner-right">
                    <a href="https://peraturan.beacukai.go.id" target="_blank" rel="noopener noreferrer" class="bc-reg-btn" title="Buka Regulasi PER-6/BC/2023">
                        📖 Pelajari Regulasi ↗
                    </a>
                </div>
            </div>
            <div class="bc-instruction-text">
                <span>📋</span> Silahkan pilih Laporan yang ingin ditampilkan:
            </div>
        </div>

        <!-- 7 COLORFUL TILES GRID -->
        <div class="bc-tiles-grid">
            <!-- 1. [ 1A ] PEMASUKAN BARANG -->
            <a href="?tab=1a&cmp_id=<?= urlencode($cmp_id) ?>&theme=<?= $theme ?>" class="bc-tile-card tile-blue">
                <div class="tile-icon-box">
                    <span class="tile-icon">📥</span>
                    <span class="tile-code">[ 1A ]</span>
                </div>
                <div class="tile-footer">Pemasukan Barang</div>
            </a>

            <!-- 2. [ 1B ] PENGELUARAN BARANG -->
            <a href="?tab=1b&cmp_id=<?= urlencode($cmp_id) ?>&theme=<?= $theme ?>" class="bc-tile-card tile-green">
                <div class="tile-icon-box">
                    <span class="tile-icon">📤</span>
                    <span class="tile-code">[ 1B ]</span>
                </div>
                <div class="tile-footer">Pengeluaran Barang</div>
            </a>

            <!-- 3. [ 1C ] POSISI WIP -->
            <a href="?tab=1c&cmp_id=<?= urlencode($cmp_id) ?>&theme=<?= $theme ?>" class="bc-tile-card tile-red">
                <div class="tile-icon-box">
                    <span class="tile-icon">🔄</span>
                    <span class="tile-code">[ 1C ]</span>
                </div>
                <div class="tile-footer">Posisi WIP</div>
            </a>

            <!-- 4. [ 1D ] MUTASI BAHAN BAKU -->
            <a href="?tab=1d&klas=Raw+Materials&cmp_id=<?= urlencode($cmp_id) ?>&theme=<?= $theme ?>" class="bc-tile-card tile-lime">
                <div class="tile-icon-box">
                    <span class="tile-icon">📦</span>
                    <span class="tile-code">.. 1D</span>
                </div>
                <div class="tile-footer">Mutasi Bahan Baku</div>
            </a>

            <!-- 5. [ 1D ] MUTASI BARANG JADI -->
            <a href="?tab=1d&klas=Finished+Goods&cmp_id=<?= urlencode($cmp_id) ?>&theme=<?= $theme ?>" class="bc-tile-card tile-purple">
                <div class="tile-icon-box">
                    <span class="tile-icon">🏷️</span>
                    <span class="tile-code">.. 1D</span>
                </div>
                <div class="tile-footer">Mutasi Barang Jadi</div>
            </a>

            <!-- 6. [ 1D ] MUTASI MESIN/PERALATAN -->
            <a href="?tab=1d&klas=Capital+Goods&cmp_id=<?= urlencode($cmp_id) ?>&theme=<?= $theme ?>" class="bc-tile-card tile-cyan">
                <div class="tile-icon-box">
                    <span class="tile-icon">⚙️</span>
                    <span class="tile-code">.. 1D</span>
                </div>
                <div class="tile-footer">Mutasi Mesin/Peralatan</div>
            </a>

            <!-- 7. [ 1D ] MUTASI SCRAP -->
            <a href="?tab=1d&klas=Wastes&cmp_id=<?= urlencode($cmp_id) ?>&theme=<?= $theme ?>" class="bc-tile-card tile-gold">
                <div class="tile-icon-box">
                    <span class="tile-icon">♻️</span>
                    <span class="tile-code">.. 1D</span>
                </div>
                <div class="tile-footer">Mutasi Scrap</div>
            </a>
        </div>

        <!-- FOOTER -->
        <div class="bc-home-footer">
            2026 &copy; <?= htmlspecialchars($cmp_name) ?> &bull; Portal CEISA 4.0 Host to Host IT Inventory
        </div>
    </div>
    <?php else: ?>
    <!-- FILTER BAR -->
    <form method="GET" action="it_inventory.php" class="filter-panel">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
        <input type="hidden" name="theme" value="<?= htmlspecialchars($theme) ?>">
        <input type="hidden" name="cmp_id" value="<?= htmlspecialchars($cmp_id) ?>">

        <?php if ($activeTab === '1a' || $activeTab === '1b'): ?>
            <div class="filter-col">
                <label>Dokumen Pabean (BC)</label>
                <select name="bc" class="filter-control" style="width: 140px;">
                    <option value="%" <?= $bc_filter === '%' ? 'selected' : '' ?>>Semua Dokumen (%)</option>
                    <?php if ($activeTab === '1a'): ?>
                        <?php if (isItDocPermitted('611')): ?><option value="611" <?= $bc_filter === '611' ? 'selected' : '' ?>>PPKEK 6.1.1 (KEK Masuk)</option><?php endif; ?>
                        <?php if (isItDocPermitted('20')): ?><option value="20" <?= $bc_filter === '20' ? 'selected' : '' ?>>BC 2.0 (Impor)</option><?php endif; ?>
                        <?php if (isItDocPermitted('23')): ?><option value="23" <?= $bc_filter === '23' ? 'selected' : '' ?>>BC 2.3 (TPB Masuk)</option><?php endif; ?>
                        <?php if (isItDocPermitted('40')): ?><option value="40" <?= $bc_filter === '40' ? 'selected' : '' ?>>BC 4.0 (TLDDP Masuk)</option><?php endif; ?>
                        <?php if (isItDocPermitted('16')): ?><option value="16" <?= $bc_filter === '16' ? 'selected' : '' ?>>BC 1.6 (Pemasukan PLB)</option><?php endif; ?>
                        <?php if (isItDocPermitted('261')): ?><option value="261" <?= $bc_filter === '261' ? 'selected' : '' ?>>BC 2.6.1 (Subkon Masuk)</option><?php endif; ?>
                        <?php if (isItDocPermitted('28')): ?><option value="28" <?= $bc_filter === '28' ? 'selected' : '' ?>>BC 2.8 (Impor PLB)</option><?php endif; ?>
                    <?php else: ?>
                        <?php if (isItDocPermitted('613')): ?><option value="613" <?= $bc_filter === '613' ? 'selected' : '' ?>>PPKEK 6.1.3 (KEK Keluar)</option><?php endif; ?>
                        <?php if (isItDocPermitted('631')): ?><option value="631" <?= $bc_filter === '631' ? 'selected' : '' ?>>PPKEK 6.3.1 (Pengeluaran ke LDP)</option><?php endif; ?>
                        <?php if (isItDocPermitted('25')): ?><option value="25" <?= $bc_filter === '25' ? 'selected' : '' ?>>BC 2.5 (TLDDP Keluar)</option><?php endif; ?>
                        <?php if (isItDocPermitted('30')): ?><option value="30" <?= $bc_filter === '30' ? 'selected' : '' ?>>BC 3.0 (Ekspor)</option><?php endif; ?>
                        <?php if (isItDocPermitted('41')): ?><option value="41" <?= $bc_filter === '41' ? 'selected' : '' ?>>BC 4.1 (TLDDP Keluar)</option><?php endif; ?>
                        <?php if (isItDocPermitted('262')): ?><option value="262" <?= $bc_filter === '262' ? 'selected' : '' ?>>BC 2.6.2 (Subkon Keluar)</option><?php endif; ?>
                        <?php if (isItDocPermitted('33')): ?><option value="33" <?= $bc_filter === '33' ? 'selected' : '' ?>>BC 3.3 (Ekspor PLB)</option><?php endif; ?>
                        <?php if (isItDocPermitted('331')): ?><option value="331" <?= $bc_filter === '331' ? 'selected' : '' ?>>BC 3.3.1 (P3BET PLB)</option><?php endif; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="filter-col">
                <label>Tanggal Mulai</label>
                <input type="date" name="tgl1" class="filter-control" value="<?= htmlspecialchars($tgl1) ?>">
            </div>
            <div class="filter-col">
                <label>Tanggal Akhir</label>
                <input type="date" name="tgl2" class="filter-control" value="<?= htmlspecialchars($tgl2) ?>">
            </div>
            <div class="filter-col">
                <label>Cari Berdasarkan</label>
                <select name="search_field" class="filter-control" style="width: 130px;">
                    <option value="all" <?= $search_field === 'all' ? 'selected' : '' ?>>Semua Kolom</option>
                    <option value="no_daftar" <?= $search_field === 'no_daftar' ? 'selected' : '' ?>>No Pendaftaran</option>
                    <option value="kode_barang" <?= $search_field === 'kode_barang' ? 'selected' : '' ?>>Kode Barang</option>
                    <option value="nama_barang" <?= $search_field === 'nama_barang' ? 'selected' : '' ?>>Nama / Uraian</option>
                    <option value="vendor" <?= $search_field === 'vendor' ? 'selected' : '' ?>><?= $activeTab === '1a' ? 'Vendor / Pemasok' : 'Buyer / Penerima' ?></option>
                    <option value="no_bukti" <?= $search_field === 'no_bukti' ? 'selected' : '' ?>>No Bukti / Invoice</option>
                    <option value="no_aju" <?= $search_field === 'no_aju' ? 'selected' : '' ?>>Nomor Aju</option>
                </select>
            </div>
        <?php elseif ($activeTab === '1c'): ?>
            <div class="filter-col">
                <label>Tanggal Cut-off Posisi WIP</label>
                <input type="date" name="tgl2" class="filter-control" value="<?= htmlspecialchars($tgl2) ?>">
            </div>
        <?php elseif ($activeTab === '1d'): ?>
            <div class="filter-col">
                <label>Periode Awal</label>
                <input type="date" name="tgl1" class="filter-control" value="<?= htmlspecialchars($tgl1) ?>">
            </div>
            <div class="filter-col">
                <label>Periode Akhir</label>
                <input type="date" name="tgl2" class="filter-control" value="<?= htmlspecialchars($tgl2) ?>">
            </div>
            <div class="filter-col">
                <label>Klasifikasi Barang</label>
                <select name="klas" class="filter-control" style="min-width: 210px;">
                    <option value="Raw Materials" <?= in_array($klas_filter, ['Raw Materials', 'Raw Material', 'RM']) ? 'selected' : '' ?>>RM - Raw Material</option>
                    <option value="Finished Goods" <?= in_array($klas_filter, ['Finished Goods', 'Finished Good', 'FG']) ? 'selected' : '' ?>>FG - Finished Goods</option>
                    <option value="Work In Progress" <?= in_array($klas_filter, ['Work In Progress', 'WIP', 'WI']) ? 'selected' : '' ?>>WI - Work In Progress</option>
                    <optgroup label="CG - Capital Goods">
                        <option value="Capital Goods" <?= in_array($klas_filter, ['Capital Goods', 'CG']) ? 'selected' : '' ?>>CG - Capital Goods (Semua)</option>
                        <?php foreach ($cgTypes as $cgt): ?>
                            <option value="<?= htmlspecialchars($cgt) ?>" <?= $klas_filter === $cgt ? 'selected' : '' ?>><?= htmlspecialchars($cgt) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <option value="Wastes" <?= in_array($klas_filter, ['Wastes', 'Waste', 'W']) ? 'selected' : '' ?>>W - Wastes</option>
                </select>
            </div>
        <?php elseif ($activeTab === 'log'): ?>
            <div class="filter-col">
                <label>Tanggal Request Awal</label>
                <input type="date" name="tgl1" class="filter-control" value="<?= htmlspecialchars($tgl1) ?>">
            </div>
            <div class="filter-col">
                <label>Tanggal Request Akhir</label>
                <input type="date" name="tgl2" class="filter-control" value="<?= htmlspecialchars($tgl2) ?>">
            </div>
        <?php endif; ?>

        <div class="filter-col">
            <label>Kata Kunci Cari</label>
            <input type="text" name="search_kw" class="filter-control" style="width: 160px;" placeholder="Ketik kata kunci..." value="<?= htmlspecialchars($search_kw) ?>">
        </div>

        <button type="submit" class="ant-btn ant-btn-primary">
            🔍 Muat Data
        </button>
        <a href="it_inventory.php?tab=<?= $activeTab ?>&theme=<?= $theme ?>" class="ant-btn">
            🔄 Reset
        </a>
        <button type="button" class="ant-btn ant-btn-success" onclick="exportToExcel('<?= $activeTab ?>')" title="Simpan sebagai file Excel (.xlsx)">
            📗 Save Excel (.xlsx)
        </button>
        <button type="button" class="ant-btn" onclick="exportTableToCSV('<?= $activeTab ?>')" title="Unduh CSV">
            📄 CSV
        </button>
        <button type="button" class="ant-btn" onclick="copyTableClipboard()" title="Salin data ke clipboard">
            📋 Copy Grid
        </button>
    </form>

    <!-- KPI CHIPS SUMMARY -->
    <div class="kpi-container">
        <div class="kpi-chip">
            <span class="kpi-label">Total Data:</span>
            <span class="kpi-val"><?= number_format($stats['total_rows']) ?> Baris</span>
        </div>
        <?php if ($activeTab === '1a' || $activeTab === '1b'): ?>
            <div class="kpi-chip">
                <span class="kpi-label">Total Qty:</span>
                <span class="kpi-val" style="color:#1890ff;"><?= fnum($stats['total_qty']) ?></span>
            </div>
            <div class="kpi-chip">
                <span class="kpi-label">Total Nilai:</span>
                <span class="kpi-val" style="color:#52c41a;"><?= fnum($stats['total_nilai']) ?></span>
            </div>
            <?php if ($stats['total_cif'] > 0): ?>
            <div class="kpi-chip">
                <span class="kpi-label">Total CIF:</span>
                <span class="kpi-val" style="color:#722ed1;"><?= fnum($stats['total_cif']) ?></span>
            </div>
            <?php endif; ?>
        <?php elseif ($activeTab === '1c'): ?>
            <div class="kpi-chip">
                <span class="kpi-label">Total Saldo WIP:</span>
                <span class="kpi-val" style="color:#1890ff;"><?= fnum($stats['total_qty']) ?></span>
            </div>
        <?php elseif ($activeTab === '1d'): ?>
            <div class="kpi-chip">
                <span class="kpi-label">Saldo Awal:</span>
                <span class="kpi-val"><?= fnum($stats['total_awal']) ?></span>
            </div>
            <div class="kpi-chip">
                <span class="kpi-label">Pemasukan:</span>
                <span class="kpi-val" style="color:#52c41a;">+<?= fnum($stats['total_masuk']) ?></span>
            </div>
            <div class="kpi-chip">
                <span class="kpi-label">Pengeluaran:</span>
                <span class="kpi-val" style="color:#f5222d;">-<?= fnum($stats['total_keluar']) ?></span>
            </div>
            <div class="kpi-chip">
                <span class="kpi-label">Saldo Buku:</span>
                <span class="kpi-val" style="color:#1890ff;"><?= fnum($stats['total_akhir']) ?></span>
            </div>
            <div class="kpi-chip">
                <span class="kpi-label">Stock Opname:</span>
                <span class="kpi-val"><?= fnum($stats['total_so']) ?></span>
            </div>
            <div class="kpi-chip">
                <span class="kpi-label">Selisih:</span>
                <span class="kpi-val" style="color:<?= $stats['total_selisih'] != 0 ? '#faad14' : '#52c41a' ?>;"><?= fnum($stats['total_selisih']) ?></span>
            </div>
        <?php endif; ?>
    </div>

    <!-- TABLE SEARCH & SORT TOOLBAR -->
    <div style="display:flex; justify-content:space-between; align-items:center; padding:7px 12px; background:var(--bg-filter); border:1px solid var(--border-card); border-bottom:none; border-radius:4px 4px 0 0; gap:10px; flex-wrap:wrap; margin-top:8px;">
        <div style="display:flex; align-items:center; gap:8px;">
            <span style="font-size:13px;">⚡</span>
            <input type="text" id="tableFilterInput" class="filter-control" placeholder="Cari instan di tabel (semua kolom)..." onkeyup="filterTableInstant()" style="width: 240px; padding:3px 8px;">
            <span id="rowCountBadge" class="ant-badge ant-badge-blue"><?= count($dataRows) ?> Baris Data</span>
            <button type="button" class="ant-btn" onclick="clearTableFilter()" style="height:22px; padding:0 6px; font-size:10px;">✕ Clear</button>
        </div>
        <div style="font-size:10.5px; color:var(--text-muted); display:flex; align-items:center; gap:12px;">
            <span>💡 Klik judul kolom untuk mengurutkan (Sort ▲▼)</span>
            <button type="button" class="ant-btn ant-btn-success" onclick="exportToExcel('<?= $activeTab ?>')" style="height:23px; font-weight:600; padding:0 8px;">
                📗 Save Excel (.xlsx)
            </button>
        </div>
    </div>

    <!-- MAIN DATA TABLE -->
    <div class="ant-table-wrapper" style="border-top-left-radius:0; border-top-right-radius:0;">
        <div class="table-scroll">
            <table class="ant-table" id="mainDataTable">
                <thead>
                <?php if ($activeTab === '1a' || $activeTab === '1b'): ?>
                    <tr>
                        <th style="width:30px; text-align:center;">No</th>
                        <th>Jenis BC</th>
                        <th>No Daftar</th>
                        <th>Tgl Daftar</th>
                        <th>No Bukti</th>
                        <th>Tgl Bukti</th>
                        <th><?= $activeTab === '1a' ? 'Vendor / Pemasok' : 'Buyer / Penerima' ?></th>
                        <th>Kode Barang</th>
                        <th>Uraian / Nama Barang</th>
                        <th style="text-align:right;">Jumlah</th>
                        <th>Satuan</th>
                        <th>Valuta</th>
                        <th style="text-align:right;">Nilai</th>
                        <th>Pos Tarif (HS)</th>
                        <th>Klasifikasi</th>
                        <th>Nomor Aju</th>
                        <th>Invoice</th>
                        <th>Faktur</th>
                        <th>Surat Jalan</th>
                        <th style="text-align:right;">Netto</th>
                        <th style="text-align:right;">CIF</th>
                    </tr>
                <?php elseif ($activeTab === '1c'): ?>
                    <tr>
                        <th style="width:30px; text-align:center;">No</th>
                        <th>Kode Barang</th>
                        <th>Uraian Barang</th>
                        <th>Model</th>
                        <th>Satuan</th>
                        <th style="text-align:right;">Jumlah Stok WIP</th>
                        <th>Status</th>
                    </tr>
                <?php elseif ($activeTab === '1d'): ?>
                    <tr>
                        <th style="width:30px; text-align:center;">No</th>
                        <th>Kode Barang</th>
                        <th>Uraian Barang</th>
                        <th>Model</th>
                        <th>Satuan</th>
                        <th style="text-align:right;">Saldo Awal</th>
                        <th style="text-align:right;">Pemasukan</th>
                        <th style="text-align:right;">Pengeluaran</th>
                        <th style="text-align:right;">Penyesuaian</th>
                        <th style="text-align:right;">Saldo Buku</th>
                        <th style="text-align:right;">Stock Opname</th>
                        <th style="text-align:right;">Selisih</th>
                    </tr>
                <?php elseif ($activeTab === 'log'): ?>
                    <tr>
                        <th style="width:30px; text-align:center;">No</th>
                        <th>Tgl Request</th>
                        <th>Dokumen BC</th>
                        <th>Nomor Aju</th>
                        <th>Nama Entitas / Perusahaan</th>
                        <th>User Request</th>
                        <th>Alasan Unposting</th>
                        <th style="text-align:center;">Status</th>
                        <th>User Appv</th>
                        <th>Tgl Appv</th>
                        <th style="text-align:center;">Arsip Revisi</th>
                    </tr>
                <?php endif; ?>
                </thead>
                <tbody>
                <?php if (empty($dataRows)): ?>
                    <tr>
                        <td colspan="21" style="text-align:center; padding: 24px; color: var(--text-muted);">
                            📭 Tidak ada data yang ditemukan untuk kriteria filter ini.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $no = 1; foreach ($dataRows as $r): ?>
                        <?php if ($activeTab === '1a' || $activeTab === '1b'): ?>
                            <tr>
                                <td style="text-align:center; color: var(--text-muted);"><?= $no++ ?></td>
                                <td><span class="ant-badge ant-badge-blue"><?= htmlspecialchars($r['JENIS_DOC'] ?? '-') ?></span></td>
                                <td style="font-weight:600;"><?= htmlspecialchars($r['NO_DOC'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['TGL_DOC'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['NO_BUKTI'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['TGL_BUKTI'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($activeTab === '1a' ? ($r['VENDOR'] ?? '-') : ($r['NAMA_PENERIMA_BARANG'] ?? '-')) ?></td>
                                <td style="font-family:monospace; font-weight:600; color:var(--primary);"><?= htmlspecialchars($r['KODE_BARANG'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['NAMA_BARANG'] ?? '-') ?></td>
                                <td style="text-align:right; font-weight:600;"><?= fnum($r['JUMLAH'] ?? 0) ?></td>
                                <td><?= htmlspecialchars($r['SATUAN'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['KODE_VALUTA'] ?? 'IDR') ?></td>
                                <td style="text-align:right; font-weight:600; color:#52c41a;"><?= fnum($r['NILAI'] ?? 0) ?></td>
                                <td><?= htmlspecialchars($r['POS_TARIF'] ?? '-') ?></td>
                                <td><span class="ant-badge ant-badge-gold"><?= htmlspecialchars($r['KLAS'] ?? '-') ?></span></td>
                                <td style="font-family:monospace;"><?= htmlspecialchars($r['NO_AJU'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['INVOICE'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['FAKTUR'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['SJ'] ?? '-') ?></td>
                                <td style="text-align:right;"><?= fnum($r['NETTO'] ?? 0) ?></td>
                                <td style="text-align:right;"><?= fnum($r['CIF'] ?? 0) ?></td>
                            </tr>
                        <?php elseif ($activeTab === '1c'): ?>
                            <tr>
                                <td style="text-align:center; color: var(--text-muted);"><?= $no++ ?></td>
                                <td style="font-family:monospace; font-weight:600; color:var(--primary);"><?= htmlspecialchars($r['KODE_BARANG'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['URAIAN'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['MODEL'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['KODE_SATUAN'] ?? '-') ?></td>
                                <td style="text-align:right; font-weight:700; color:#1890ff;"><?= fnum($r['JUMLAH'] ?? 0) ?></td>
                                <td><span class="ant-badge ant-badge-green">Aktif di WIP</span></td>
                            </tr>
                        <?php elseif ($activeTab === '1d'): ?>
                            <tr>
                                <td style="text-align:center; color: var(--text-muted);"><?= $no++ ?></td>
                                <td style="font-family:monospace; font-weight:600; color:var(--primary);"><?= htmlspecialchars($r['KODE_BARANG'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['URAIAN'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['MODEL'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['KODE_SATUAN'] ?? '-') ?></td>
                                <td style="text-align:right;"><?= fnum($r['AWAL'] ?? 0) ?></td>
                                <td style="text-align:right; font-weight:600; color:#52c41a;">+<?= fnum($r['MASUK'] ?? 0) ?></td>
                                <td style="text-align:right; font-weight:600; color:#f5222d;">-<?= fnum($r['KELUAR'] ?? 0) ?></td>
                                <td style="text-align:right;"><?= fnum($r['ADJ'] ?? 0) ?></td>
                                <td style="text-align:right; font-weight:700; color:#1890ff;"><?= fnum($r['AKHIR'] ?? 0) ?></td>
                                <td style="text-align:right;"><?= fnum($r['SO'] ?? 0) ?></td>
                                <td style="text-align:right; font-weight:700; color:<?= ((float)($r['SELISIH'] ?? 0)) != 0 ? '#faad14' : '#52c41a' ?>;">
                                    <?= fnum($r['SELISIH'] ?? 0) ?>
                                </td>
                            </tr>
                        <?php elseif ($activeTab === 'log'): ?>
                            <tr>
                                <td style="text-align:center; color: var(--text-muted);"><?= $no++ ?></td>
                                <td><?= htmlspecialchars($r['TGL_REQ'] ?? '-') ?></td>
                                <td><span class="ant-badge ant-badge-blue"><?= htmlspecialchars($r['BC'] ?? '-') ?></span></td>
                                <td style="font-family:monospace; font-weight:600;"><?= htmlspecialchars($r['NOMOR_AJU'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['NAMA_BS'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['USER_REQ'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['ALASAN'] ?? '-') ?></td>
                                <td style="text-align:center;">
                                    <?php if (($r['STATUS'] ?? '') === 'Y'): ?>
                                        <span class="ant-badge ant-badge-green">Disetujui</span>
                                    <?php elseif (($r['STATUS'] ?? '') === 'N'): ?>
                                        <span class="ant-badge ant-badge-red">Ditolak</span>
                                    <?php else: ?>
                                        <span class="ant-badge ant-badge-gold">Diajukan</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($r['USER_APPV'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['TGL_APPV'] ?? '-') ?></td>
                                <td style="text-align:center;">
                                    <button type="button" class="ant-btn ant-btn-sm" onclick="showRevisiDetail('<?= htmlspecialchars($r['NOMOR_AJU']) ?>', <?= (int)$r['ROWID'] ?>)">
                                        🔍 Detail (<?= (int)($r['JUMLAH_REVISI'] ?? 0) ?>)
                                    </button>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
                <?php if (!empty($dataRows)): ?>
                <tfoot>
                <?php if ($activeTab === '1a' || $activeTab === '1b'): ?>
                    <tr>
                        <th colspan="9" style="text-align:right;">TOTAL:</th>
                        <th style="text-align:right;"><?= fnum($stats['total_qty']) ?></th>
                        <th colspan="2"></th>
                        <th style="text-align:right; color:#52c41a;"><?= fnum($stats['total_nilai']) ?></th>
                        <th colspan="7"></th>
                        <th style="text-align:right;"><?= fnum($stats['total_cif']) ?></th>
                    </tr>
                <?php elseif ($activeTab === '1c'): ?>
                    <tr>
                        <th colspan="5" style="text-align:right;">TOTAL STOK WIP:</th>
                        <th style="text-align:right; color:#1890ff;"><?= fnum($stats['total_qty']) ?></th>
                        <th></th>
                    </tr>
                <?php elseif ($activeTab === '1d'): ?>
                    <tr>
                        <th colspan="5" style="text-align:right;">TOTAL KESELURUHAN:</th>
                        <th style="text-align:right;"><?= fnum($stats['total_awal']) ?></th>
                        <th style="text-align:right; color:#52c41a;">+<?= fnum($stats['total_masuk']) ?></th>
                        <th style="text-align:right; color:#f5222d;">-<?= fnum($stats['total_keluar']) ?></th>
                        <th style="text-align:right;"><?= fnum($stats['total_adj']) ?></th>
                        <th style="text-align:right; color:#1890ff;"><?= fnum($stats['total_akhir']) ?></th>
                        <th style="text-align:right;"><?= fnum($stats['total_so']) ?></th>
                        <th style="text-align:right;"><?= fnum($stats['total_selisih']) ?></th>
                    </tr>
                <?php endif; ?>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
    <?php endif; // Akhir dari if ($activeTab === 'home') else ?>
</div>

<!-- MODAL DETAIL REVISI (bc_1ab2_revisi) -->
<div class="ant-modal-mask" id="revisiModalMask">
    <div class="ant-modal">
        <div class="ant-modal-header">
            <span>Detail Arsip Revisi Data (bc_1ab2_revisi) - <span id="modalAjuTitle"></span></span>
            <button type="button" class="ant-btn" onclick="closeRevisiModal()">✕</button>
        </div>
        <div class="ant-modal-body" id="modalRevisiContent">
            Memuat data...
        </div>
        <div class="ant-modal-footer">
            <button type="button" class="ant-btn ant-btn-primary" onclick="closeRevisiModal()">Tutup</button>
        </div>
    </div>
</div>

<script>
    // Copy Grid to Clipboard
    function copyTableClipboard() {
        const table = document.getElementById("mainDataTable");
        let csv = [];
        for (let row of table.rows) {
            let cols = [];
            for (let cell of row.cells) {
                cols.push('"' + cell.innerText.replace(/"/g, '""').trim() + '"');
            }
            csv.push(cols.join("\t"));
        }
        navigator.clipboard.writeText(csv.join("\n")).then(() => {
            showSuccess("Tabel berhasil disalin ke clipboard!", "Tersalin");
        }).catch(() => {
            showInfo("Gagal menyalin otomatis. Silakan blok tabel dan salin secara manual.");
        });
    }

    // Export Table to Native Excel (.xlsx) using SheetJS
    function exportToExcel(tabName) {
        if (typeof XLSX === 'undefined') {
            alert('Library Excel (SheetJS) belum selesai termuat. Silakan gunakan tombol CSV.');
            return;
        }
        const table = document.getElementById("mainDataTable");
        if (!table) return;

        // Clone table so we can remove sort icons and hidden rows cleanly
        const clonedTable = table.cloneNode(true);
        clonedTable.querySelectorAll(".sort-icon").forEach(icon => icon.remove());
        clonedTable.querySelectorAll("tbody tr").forEach(row => {
            if (row.style.display === 'none') row.remove();
        });

        const sheetName = ("IT_Inv_" + (tabName || "Data")).substring(0, 31);
        const wb = XLSX.utils.table_to_book(clonedTable, { sheet: sheetName });
        const dateStr = new Date().toISOString().slice(0, 10);
        const fileName = "IT_Inventory_" + (tabName || "Data").toUpperCase() + "_" + dateStr + ".xlsx";
        XLSX.writeFile(wb, fileName);
    }

    // Live Instant Search across all columns
    function filterTableInstant() {
        const input = document.getElementById("tableFilterInput");
        if (!input) return;
        const filter = input.value.toLowerCase().trim();
        const table = document.getElementById("mainDataTable");
        if (!table) return;
        const tbody = table.querySelector("tbody");
        if (!tbody) return;
        const rows = tbody.querySelectorAll("tr");

        let visibleCount = 0;
        rows.forEach(row => {
            if (row.cells.length <= 1) return; // message row
            const text = row.innerText.toLowerCase();
            if (filter === '' || text.indexOf(filter) !== -1) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        const badge = document.getElementById("rowCountBadge");
        if (badge) {
            if (filter === '') {
                badge.innerText = visibleCount + " Baris Data";
                badge.className = "ant-badge ant-badge-blue";
            } else {
                badge.innerText = visibleCount + " Ditemukan";
                badge.className = visibleCount > 0 ? "ant-badge ant-badge-green" : "ant-badge ant-badge-red";
            }
        }
    }

    function clearTableFilter() {
        const input = document.getElementById("tableFilterInput");
        if (input) {
            input.value = "";
            filterTableInstant();
        }
    }

    // Multi-type Table Header Sorting (Number, Date, Text)
    let currentSortCol = -1;
    let currentSortDir = 'asc';

    function sortTable(colIndex) {
        const table = document.getElementById("mainDataTable");
        if (!table) return;
        const tbody = table.querySelector("tbody");
        if (!tbody) return;
        const rows = Array.from(tbody.querySelectorAll("tr"));
        if (rows.length === 0 || (rows.length === 1 && rows[0].cells.length <= 1)) return;

        if (currentSortCol === colIndex) {
            currentSortDir = (currentSortDir === 'asc') ? 'desc' : 'asc';
        } else {
            currentSortCol = colIndex;
            currentSortDir = 'asc';
        }

        // Update sort indicator icons
        const ths = table.querySelectorAll("thead th");
        ths.forEach((th, idx) => {
            const icon = th.querySelector(".sort-icon");
            if (icon) {
                if (idx === colIndex) {
                    icon.textContent = currentSortDir === 'asc' ? ' ▲' : ' ▼';
                    icon.style.opacity = '1';
                    icon.style.color = 'var(--primary)';
                } else {
                    icon.textContent = ' ↕';
                    icon.style.opacity = '0.35';
                    icon.style.color = 'inherit';
                }
            }
        });

        rows.sort((a, b) => {
            const cellA = (a.cells[colIndex] ? a.cells[colIndex].innerText : '').trim();
            const cellB = (b.cells[colIndex] ? b.cells[colIndex].innerText : '').trim();

            // Try clean numeric
            const cleanA = cellA.replace(/,/g, '').replace(/[^\d.-]/g, '');
            const cleanB = cellB.replace(/,/g, '').replace(/[^\d.-]/g, '');
            const numA = parseFloat(cleanA);
            const numB = parseFloat(cleanB);

            let comp = 0;
            if (!isNaN(numA) && !isNaN(numB) && cellA !== '' && cellB !== '' && !cellA.includes('-') && !cellA.match(/^[A-Za-z]/)) {
                comp = numA - numB;
            } else if (cellA.match(/^\d{4}-\d{2}-\d{2}/) && cellB.match(/^\d{4}-\d{2}-\d{2}/)) {
                comp = Date.parse(cellA) - Date.parse(cellB);
            } else {
                comp = cellA.localeCompare(cellB, undefined, { numeric: true, sensitivity: 'base' });
            }

            return currentSortDir === 'asc' ? comp : -comp;
        });

        // Reorder rows
        rows.forEach(r => tbody.appendChild(r));

        // Renumber No column if first column is No
        let noIdx = 1;
        rows.forEach(r => {
            if (r.cells.length > 1 && r.cells[0]) {
                r.cells[0].innerText = noIdx++;
            }
        });
    }

    function initSortableTable() {
        const table = document.getElementById("mainDataTable");
        if (!table) return;
        const ths = table.querySelectorAll("thead tr:last-child th");
        ths.forEach((th, idx) => {
            if (idx === 0) return; // Skip "No" column
            th.classList.add("sortable-th");
            th.title = "Klik untuk mengurutkan (Sort)";
            if (!th.querySelector(".sort-icon")) {
                const icon = document.createElement("span");
                icon.className = "sort-icon";
                icon.style.opacity = "0.35";
                icon.textContent = " ↕";
                th.appendChild(icon);
            }
            th.addEventListener("click", () => sortTable(idx));
        });
    }

    document.addEventListener("DOMContentLoaded", function() {
        initSortableTable();
    });

    // Export Table to CSV
    function exportTableToCSV(tabName) {
        const table = document.getElementById("mainDataTable");
        let csv = [];
        for (let row of table.rows) {
            let cols = [];
            for (let cell of row.cells) {
                cols.push('"' + cell.innerText.replace(/"/g, '""').trim() + '"');
            }
            csv.push(cols.join(","));
        }
        const blob = new Blob([csv.join("\n")], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement("a");
        link.href = URL.createObjectURL(blob);
        link.download = "IT_Inventory_" + tabName.toUpperCase() + "_" + new Date().toISOString().slice(0,10) + ".csv";
        link.click();
    }

    // Detail Revisi Modal
    function showRevisiDetail(nomorAju, rowId) {
        document.getElementById('modalAjuTitle').innerText = nomorAju;
        document.getElementById('revisiModalMask').style.display = 'flex';
        document.getElementById('modalRevisiContent').innerHTML = 'Memuat detail revisi...';

        fetch(`it_inventory.php?action=get_revisi&aju=${encodeURIComponent(nomorAju)}&cek=${rowId}`)
            .then(res => res.text())
            .then(html => {
                document.getElementById('modalRevisiContent').innerHTML = html;
            })
            .catch(err => {
                document.getElementById('modalRevisiContent').innerHTML = `<p style="color:red;">Gagal memuat detail revisi: ${err}</p>`;
            });
    }

    function closeRevisiModal() {
        document.getElementById('revisiModalMask').style.display = 'none';
    }

    // Theme synchronization
    window.addEventListener('message', (e) => {
        if (e.data && e.data.theme) {
            document.documentElement.setAttribute('data-theme', e.data.theme);
            document.body.setAttribute('data-theme', e.data.theme);
        }
    });
</script>
</body>
</html>

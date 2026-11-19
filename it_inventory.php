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

if (!empty($cmp_id) && $cmp_id !== 'ALL') {
    ceisa_switch_company_db($cmp_id);
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

$companyAllowedDocs = is_array(ceisa_resolve_allowed_docs($targetCompanyRoles, $targetCompanyAllowedDocs)) ? ceisa_resolve_allowed_docs($targetCompanyRoles, $targetCompanyAllowedDocs) : [];
$effectiveAllowedDocs = is_array(ceisa_get_effective_allowed_docs($companyAllowedDocs)) ? ceisa_get_effective_allowed_docs($companyAllowedDocs) : [];

if (!function_exists('isItDocPermitted')) {
    function isItDocPermitted($d) {
        global $is_admin, $cmp_id, $companyAllowedDocs, $effectiveAllowedDocs;
        if (!empty($is_admin) && (empty($cmp_id) || $cmp_id === 'ALL')) {
            return true;
        }
        if (!empty($is_admin)) {
            return !empty($companyAllowedDocs) ? in_array((string)$d, $companyAllowedDocs) : true;
        }
        return !empty($effectiveAllowedDocs) ? in_array((string)$d, $effectiveAllowedDocs) : false;
    }
}

$allowedInbound = array_values(array_filter(['27', '20', '23', '40', '16', '261', '28', '611'], 'isItDocPermitted'));
$allowedOutbound = array_values(array_filter(['30', '25', '41', '262', '33', '331', '613', '631'], 'isItDocPermitted'));

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

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

if (isset($_GET['action']) && $_GET['action'] === 'get_mutasi_detail') {
    header('Content-Type: application/json; charset=utf-8');
    $kode  = trim($_GET["kode"] ?? '');
    $awal  = trim($_GET["awal"] ?? date('Y-m-01'));
    $akhir = trim($_GET["akhir"] ?? date('Y-m-d'));
    $ab    = strtoupper(trim($_GET["ab"] ?? 'A'));
    $klas  = trim($_GET["klas"] ?? '');

    $escKode  = $koneksi->real_escape_string($kode);
    $escAwal  = $koneksi->real_escape_string($awal);
    $escAkhir = $koneksi->real_escape_string($akhir);
    $escAb    = $koneksi->real_escape_string($ab);

    $cmpDetailFilter = (!empty($escCmp)) ? " AND (a.cmp_id = '$escCmp' OR a.cmp_id = '')" : "";
    $cmpIssueFilter  = (!empty($escCmp)) ? " AND (na.cmp_id = '$escCmp' OR na.cmp_id = '')" : "";

    $sql = "
    SELECT 'Eksternal' AS TIPE, a.KODE_BARANG, a.UNIT AS SATUAN, a.QTY_AKTUAL AS JUMLAH, a.TGL_BUKTI, a.NO_BUKTI, a.NAMA_BS, 
    a.BC, a.NOMOR_DAFTAR AS NOMOR_DOK, a.TANGGAL_DAFTAR AS TGL_DOK, a.NOMOR_AJU, a.TGL_PROSES
    FROM bc_1ab2 a
    WHERE substr(date_format(a.tgl_proses, '%Y-%m'), 1, 4) = substr('$escAwal', 1, 4)
    AND a.tgl_proses BETWEEN '$escAwal' AND '$escAkhir' AND a.ab = '$escAb' 
    AND a.KODE_BARANG = '$escKode'
    $cmpDetailFilter

    UNION ALL

    SELECT 'Issue' AS TIPE, na.kode_barang, na.sat AS SATUAN, na.qty AS JUMLAH, ni.tgl AS TGL_BUKTI, ni.no_bukti AS NO_BUKTI, '*** Issue Produksi ***' AS NAMA_BS, NULL AS BC, NULL AS NOMOR_DOK, NULL AS TGL_DOK, null AS NOMOR_AJU, null AS TGL_PROSES
    FROM bc_issue_hdr ni, bc_issue_dtl na
    WHERE ni.id = na.id_hdr and (ni.iscancel IS NULL OR ni.iscancel <> 'Y')
    AND substr(ni.tgl, 1, 4) = substr('$escAwal', 1, 4)
    AND ni.tgl BETWEEN '$escAwal' AND '$escAkhir' AND ni.ab = '$escAb' 
    AND na.kode_barang = '$escKode' 
    $cmpIssueFilter

    ORDER BY TIPE, TGL_PROSES ASC, NO_BUKTI ASC
    ";

    $hasil = $koneksi->query($sql);
    $rows = [];
    $sumjml = 0;
    if ($hasil) {
        while ($r = $hasil->fetch_assoc()) {
            $sumjml += (float)$r['JUMLAH'];
            $rows[] = $r;
        }
    }

    $uraianBarang = '';
    $qMat = $koneksi->query("SELECT URAIAN FROM bc_material WHERE KODE_BARANG = '$escKode' LIMIT 1");
    if ($qMat && $rm = $qMat->fetch_assoc()) {
        $uraianBarang = $rm['URAIAN'];
    }

    echo json_encode([
        'status' => 'success',
        'kode' => $kode,
        'uraian' => $uraianBarang,
        'ab' => $ab,
        'label_ab' => ($ab === 'A') ? 'MASUK' : 'KELUAR',
        'label_bs' => ($ab === 'A') ? 'Pengirim' : 'Penerima',
        'awal' => $awal,
        'akhir' => $akhir,
        'total_qty' => $sumjml,
        'count' => count($rows),
        'data' => $rows
    ]);
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
$tgl_awal_default  = in_array($activeTab, ['1a', '1b', '1d']) ? date('Y-m-01') : date('Y-01-01');
$tgl_akhir_default = date('Y-m-d');

$tgl1 = $_GET['tgl1'] ?? $tgl_awal_default;
$tgl2 = $_GET['tgl2'] ?? $tgl_akhir_default;
$bc_filter = $_GET['bc'] ?? '%';
if ($bc_filter !== '%' && !empty($bc_filter) && !isItDocPermitted($bc_filter)) {
    $bc_filter = '%';
}
$search_field = $_GET['search_field'] ?? 'all';
$search_kw = trim($_GET['search_kw'] ?? '');

if (in_array($activeTab, ['1a', '1b'])) {
    $klas_filter = trim($_GET['klas'] ?? '%');
    if (empty($klas_filter)) $klas_filter = '%';
} else {
    $klas_filter = trim($_GET['klas'] ?? '');
    if (empty($klas_filter) || $klas_filter === '%') {
        $klas_filter = 'Raw Materials';
    }
}

// Opsi Tampilan Tab 1A & 1B (Group Mode & Kolom Tambahan No. AJU, Netto, Gross)
$group_mode = isset($_GET['group_mode']) ? (int)$_GET['group_mode'] : 0;
$show_ext   = isset($_GET['show_ext']) ? (int)$_GET['show_ext'] : 1;

// Opsi Tampilan Tab 1D Mutasi (Tampilkan Semua Stok vs Hanya yang Ada Mutasi)
$stok_param = $_GET['stok'] ?? 'N';
$show_all_stok = ($stok_param === 'Y' || $stok_param === '1');

// Helper Format Angka
if (!function_exists('fnum')) {
    function fnum($val, $dec = 2) {
        if ($val === null || $val === '') return '0.00';
        return number_format((float)$val, $dec, '.', ',');
    }
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
        if ($g === 'CG' && !empty($t) && !in_array($t, $cgTypes)) {
            $cgTypes[] = $t;
        }
    }
}
if (empty($cgTypes)) {
    $cgTypes = ['Mold', 'Machine', 'Part Machine', 'Tools', 'Factory Equipment'];
}

// Sub-filter khusus Capital Goods (Mold, Machine, Part Machine, Tools, Factory Equipment)
$sub_klas = trim($_GET['sub_klas'] ?? '%');
if (in_array($klas_filter, $cgTypes)) {
    $sub_klas = $klas_filter;
    $klas_filter = 'Capital Goods';
}
$isCapitalGoods = in_array($klas_filter, ['Capital Goods', 'CG']);

$stokCheckboxLabel = 'Tampilkan Semua Stok';
if (in_array($klas_filter, ['Raw Materials', 'Raw Material', 'RM'])) {
    $stokCheckboxLabel = 'Tampilkan Semua Stok Bahan Baku / Penolong';
} elseif (in_array($klas_filter, ['Finished Goods', 'Finished Good', 'FG'])) {
    $stokCheckboxLabel = 'Tampilkan Semua Stok Barang Jadi';
} elseif ($isCapitalGoods) {
    if (!empty($sub_klas) && $sub_klas !== '%' && $sub_klas !== '-- SEMUA KLAS --') {
        $stokCheckboxLabel = 'Tampilkan Semua Stok ' . $sub_klas;
    } else {
        $stokCheckboxLabel = 'Tampilkan Semua Stok Mesin dan Peralatan';
    }
} elseif (in_array($klas_filter, ['Wastes', 'Waste', 'W'])) {
    $stokCheckboxLabel = 'Tampilkan Semua Stok Scrap / Wastes';
} elseif (in_array($klas_filter, ['Work In Progress', 'WIP', 'WI'])) {
    $stokCheckboxLabel = 'Tampilkan Semua Stok Barang Dalam Proses (WIP)';
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
    'total_harga_penyerahan' => 0,
    'total_netto' => 0,
    'total_gross' => 0,
    'total_stok' => 0,
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
    // TAB 1A: PEMASUKAN BARANG (Inbound) - MUTLAK DARI bc_1ab2
    // Sesuai PowerBuilder: w_rpt_1a_tpb.srw & d_rpt_1a_tpb.srd
    // -------------------------------------------------------------
    $sql = "SELECT ID_HEADER ID, SERI_BARANG ID_BRG, BC JENIS_DOC, NOMOR_DAFTAR NO_DOC, TANGGAL_DAFTAR TGL_DOC, 
                NO_BUKTI, TGL_BUKTI, NAMA_BS NAMA_PENERIMA_BARANG, NAMA_BS VENDOR, KODE_BARANG, URAIAN NAMA_BARANG, 
                QTY_AKTUAL JUMLAH, UNIT SATUAN, NILAI, POS_TARIF, NOMOR_AJU NO_AJU, SJ, INVOICE, FAKTUR, NOMOR_PO, 
                TUJUAN_PENGIRIMAN, TUJUAN_PEMASUKAN, ifnull(KURS, 'IDR') KODE_VALUTA, KLAS, 
                coalesce(TGL_PROSES, TANGGAL_DAFTAR) TANGGAL_PROSES, NETTO, CIF,
                HARGA_PENYERAHAN, GROSS 
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

        if ($klas_filter !== '%' && !empty($klas_filter)) {
            $sql .= " AND KLAS = ? ";
            $params[] = $klas_filter;
            $types .= "s";
        }

        if (!empty($search_kw)) {
            $sql .= " AND (KODE_BARANG LIKE ? OR URAIAN LIKE ? OR NOMOR_DAFTAR LIKE ? OR NAMA_BS LIKE ? OR NOMOR_AJU LIKE ? OR NO_BUKTI LIKE ? OR POS_TARIF LIKE ?) ";
            $kw = "%$search_kw%";
            $params[] = $kw; $params[] = $kw; $params[] = $kw; $params[] = $kw; $params[] = $kw; $params[] = $kw; $params[] = $kw;
            $types .= "sssssss";
        }

        // Urutkan berdasarkan dokumen pabean & bukti penerimaan agar pengelompokan (Group Mode) konsisten
        $sql .= " ORDER BY coalesce(TGL_PROSES, TANGGAL_DAFTAR) ASC, BC ASC, NOMOR_DAFTAR ASC, NO_BUKTI ASC, SERI_BARANG ASC LIMIT 5000";

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
                $stats['total_harga_penyerahan'] += (float)($row['HARGA_PENYERAHAN'] ?? 0);
                $stats['total_netto'] += (float)($row['NETTO'] ?? 0);
                $stats['total_gross'] += (float)($row['GROSS'] ?? 0);
            }
            $stmt->close();
        }
        $stats['total_rows'] = count($dataRows);
} elseif ($activeTab === '1b') {
    // -------------------------------------------------------------
    // TAB 1B: PENGELUARAN BARANG (Outbound) - MUTLAK DARI bc_1ab2
    // Sesuai PowerBuilder: w_rpt_1b.srw & d_rpt_1b.srd
    // -------------------------------------------------------------
    $sql = "SELECT ID_HEADER ID, SERI_BARANG ID_BRG, BC JENIS_DOC, NOMOR_DAFTAR NO_DOC, TANGGAL_DAFTAR TGL_DOC, 
                NO_BUKTI, TGL_BUKTI, NAMA_BS NAMA_PENERIMA_BARANG, NAMA_BS VENDOR, KODE_BARANG, URAIAN NAMA_BARANG, 
                QTY_AKTUAL JUMLAH, UNIT SATUAN, NILAI, POS_TARIF, NOMOR_AJU NO_AJU, SJ, INVOICE, FAKTUR, NOMOR_PO, 
                TUJUAN_PENGIRIMAN, TUJUAN_PEMASUKAN, ifnull(KURS, 'IDR') KODE_VALUTA, KLAS, 
                coalesce(TGL_PROSES, TANGGAL_DAFTAR) TANGGAL_PROSES, NETTO, CIF,
                HARGA_PENYERAHAN, GROSS 
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

        if ($klas_filter !== '%' && !empty($klas_filter)) {
            $sql .= " AND KLAS = ? ";
            $params[] = $klas_filter;
            $types .= "s";
        }

        if (!empty($search_kw)) {
            $sql .= " AND (KODE_BARANG LIKE ? OR URAIAN LIKE ? OR NOMOR_DAFTAR LIKE ? OR NAMA_BS LIKE ? OR NOMOR_AJU LIKE ? OR NO_BUKTI LIKE ? OR POS_TARIF LIKE ?) ";
            $kw = "%$search_kw%";
            $params[] = $kw; $params[] = $kw; $params[] = $kw; $params[] = $kw; $params[] = $kw; $params[] = $kw; $params[] = $kw;
            $types .= "sssssss";
        }

        // Urutkan berdasarkan dokumen pabean & bukti pengeluaran agar pengelompokan (Group Mode) konsisten
        $sql .= " ORDER BY coalesce(TGL_PROSES, TANGGAL_DAFTAR) ASC, BC ASC, NOMOR_DAFTAR ASC, NO_BUKTI ASC, SERI_BARANG ASC LIMIT 5000";

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
                $stats['total_harga_penyerahan'] += (float)($row['HARGA_PENYERAHAN'] ?? 0);
                $stats['total_netto'] += (float)($row['NETTO'] ?? 0);
                $stats['total_gross'] += (float)($row['GROSS'] ?? 0);
            }
            $stmt->close();
        }
        $stats['total_rows'] = count($dataRows);
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
    // Sesuai D:\xampp\htdocs\bc3\1d_bb, 1d_bj, 1d_ma, 1d_s\sql.php
    // -------------------------------------------------------------
    $klasVal = (empty($klas_filter) || $klas_filter === '%') ? 'Raw Materials' : $klas_filter;

    // Filter SQL untuk klasifikasi (Mendukung Sub-filter khusus Capital Goods: Mold, Machine, Part Machine, dll)
    if ($isCapitalGoods) {
        if (!empty($sub_klas) && $sub_klas !== '%' && $sub_klas !== '-- SEMUA KLAS --') {
            $escSub = $koneksi->real_escape_string($sub_klas);
            $klasSql = "KLAS = '$escSub'";
        } else {
            $quotedCg = "'" . implode("','", array_map(function($v) use ($koneksi) { return $koneksi->real_escape_string($v); }, $cgTypes)) . "', 'Capital Goods', 'CG', 'Office Equipments'";
            $klasSql = "KLAS IN ($quotedCg)";
        }
    } elseif ($klasVal === 'Raw Materials' || $klasVal === 'Raw Material' || $klasVal === 'RM') {
        $klasSql = "KLAS IN ('Raw Materials', 'Raw Material')";
    } elseif ($klasVal === 'Finished Goods' || $klasVal === 'Finished Good' || $klasVal === 'FG') {
        $klasSql = "KLAS IN ('Finished Goods', 'Finished Good')";
    } elseif ($klasVal === 'Work In Progress' || $klasVal === 'WIP' || $klasVal === 'WI') {
        $klasSql = "KLAS IN ('Work In Progress', 'WIP')";
    } elseif ($klasVal === 'Wastes' || $klasVal === 'Waste' || $klasVal === 'W') {
        $klasSql = "KLAS IN ('Wastes', 'Waste', 'Scrap')";
    } else {
        $escKlas = $koneksi->real_escape_string($klasVal);
        $klasSql = "KLAS = '$escKlas'";
    }

    $as_now = date('Y-m-d');
    $escAwal  = $koneksi->real_escape_string($tgl1);
    $escAkhir = $koneksi->real_escape_string($tgl2);
    $escNow   = $koneksi->real_escape_string($as_now);

    $cmpDetailFilter = (!empty($escCmp)) ? " AND (cmp_id = '$escCmp' OR cmp_id = '')" : "";
    $cmpIssueFilter  = (!empty($escCmp)) ? " AND (na.cmp_id = '$escCmp' OR na.cmp_id = '')" : "";

    $sql1d = "
    SELECT NI.*, (AWAL + MASUK - KELUAR) AKHIR, 
    coalesce((SELECT sum(CASE WHEN ab = 'A' THEN qty ELSE qty * -1 END) FROM bc_1ab2 X 
              WHERE tgl_proses BETWEEN '$escAwal' AND '$escAkhir' AND bc = 'SPP' 
              AND X.kode_barang = NI.KODE_BARANG AND X.UNIT = KODE_SATUAN $cmpDetailFilter), 0) ADJ,
    coalesce((SELECT SUM(AKTUAL) FROM bc_so X 
              WHERE TGL_SO = '$escAkhir' AND TIPE = 'SO' 
              AND X.kode_barang = NI.KODE_BARANG AND X.unit = KODE_SATUAN $cmpDetailFilter), 0) SO,
    (coalesce((SELECT SUM(SELISIH) FROM bc_so X 
               WHERE substr(tgl_so,1,4) = substr('$escAwal',1,4) AND tgl_so >= '$escAwal' 
               AND X.kode_barang = NI.KODE_BARANG AND X.unit = KODE_SATUAN $cmpDetailFilter), 0)
     - 
     coalesce((SELECT sum(CASE WHEN ab = 'A' THEN qty ELSE qty * -1 END) FROM bc_1ab2 X 
               WHERE tgl_proses BETWEEN '$escAwal' AND '$escAkhir' AND bc = 'SPP' 
               AND X.kode_barang = NI.KODE_BARANG AND X.UNIT = KODE_SATUAN $cmpDetailFilter), 0)
    ) SELISIH

    FROM
    (
        SELECT MASTER.KODE_BARANG, MASTER.URAIAN, MASTER.MODEL, MASTER.KODE_SATUAN, 
               coalesce(JML.STOK, 0) STOK, coalesce(AWAL.STOK, 0) AWAL,
               coalesce(MASUK.STOK, 0) MASUK, coalesce(KELUAR.STOK, 0) KELUAR 
        FROM (SELECT * FROM bc_material WHERE $klasSql $cmpDetailFilter) MASTER
        
        LEFT JOIN
        (
            SELECT kode_barang, unit, sum(stok) stok
            FROM (
                SELECT kode_barang, unit, 
                sum(CASE WHEN ab = 'A' THEN QTY_AKTUAL ELSE QTY_AKTUAL * -1 END) stok
                FROM bc_1ab2
                WHERE substr(date_format(tgl_proses, '%Y-%m'), 1, 4) = substr('$escAwal', 1, 4) 
                AND tgl_proses < '$escAwal' $cmpDetailFilter
                GROUP BY kode_barang, unit 
                  
                UNION ALL
                
                SELECT kode_barang, unit, stok_awal
                FROM bc_so0 a
                WHERE tahun = substr('$escAwal', 1, 4) $cmpDetailFilter
                
                UNION ALL
                
                SELECT na.kode_barang, na.sat as unit, sum(CASE WHEN ni.ab = 'A' THEN na.qty ELSE na.qty * -1 END) stok
                FROM bc_issue_hdr ni, bc_issue_dtl na
                WHERE ni.id = na.id_hdr and (ni.iscancel IS NULL OR ni.iscancel <> 'Y')
                AND substr(ni.tgl, 1, 4) = substr('$escAwal', 1, 4)
                AND ni.tgl < '$escAwal' $cmpIssueFilter
                GROUP BY na.kode_barang, na.sat
            ) xx
            GROUP BY kode_barang, unit
        ) AWAL ON MASTER.KODE_BARANG = AWAL.KODE_BARANG AND MASTER.KODE_SATUAN = AWAL.UNIT
        
        LEFT JOIN
        (
            SELECT kode_barang, unit, sum(qty) stok
            FROM (
                SELECT kode_barang, unit, QTY_AKTUAL qty
                FROM bc_1ab2
                WHERE substr(date_format(tgl_proses, '%Y-%m'), 1, 4) = substr('$escAwal', 1, 4) 
                AND tgl_proses BETWEEN '$escAwal' AND '$escAkhir' AND ab = 'A' $cmpDetailFilter
               
                UNION ALL
                
                SELECT na.kode_barang, na.sat as unit, na.qty
                FROM bc_issue_hdr ni, bc_issue_dtl na
                WHERE ni.id = na.id_hdr and (ni.iscancel IS NULL OR ni.iscancel <> 'Y')
                AND substr(ni.tgl, 1, 4) = substr('$escAwal', 1, 4)
                AND ni.tgl BETWEEN '$escAwal' AND '$escAkhir' AND ni.ab = 'A' $cmpIssueFilter
            ) xx
            GROUP BY kode_barang, unit
        ) MASUK ON MASTER.KODE_BARANG = MASUK.KODE_BARANG AND MASTER.KODE_SATUAN = MASUK.UNIT
        
        LEFT JOIN
        (
            SELECT kode_barang, unit, sum(qty) stok
            FROM (
                SELECT kode_barang, unit, QTY_AKTUAL qty
                FROM bc_1ab2
                WHERE substr(date_format(tgl_proses, '%Y-%m'), 1, 4) = substr('$escAwal', 1, 4) 
                AND tgl_proses BETWEEN '$escAwal' AND '$escAkhir' AND ab = 'B' $cmpDetailFilter
               
                UNION ALL
                
                SELECT na.kode_barang, na.sat as unit, na.qty
                FROM bc_issue_hdr ni, bc_issue_dtl na
                WHERE ni.id = na.id_hdr and (ni.iscancel IS NULL OR ni.iscancel <> 'Y')
                AND substr(ni.tgl, 1, 4) = substr('$escAwal', 1, 4)
                AND ni.tgl BETWEEN '$escAwal' AND '$escAkhir' AND ni.ab = 'B' $cmpIssueFilter
            ) xx
            GROUP BY kode_barang, unit
        ) KELUAR ON MASTER.KODE_BARANG = KELUAR.KODE_BARANG AND MASTER.KODE_SATUAN = KELUAR.UNIT
        
        LEFT JOIN
        (
            SELECT kode_barang, unit, sum(stok) STOK
            FROM (
                SELECT kode_barang, unit, 
                sum(CASE WHEN ab = 'A' THEN QTY_AKTUAL ELSE QTY_AKTUAL * -1 END) stok
                FROM bc_1ab2
                WHERE substr(date_format(tgl_proses, '%Y-%m'), 1, 4) = substr('$escNow', 1, 4) 
                AND tgl_proses <= '$escNow' $cmpDetailFilter
                GROUP BY kode_barang, unit 
                  
                UNION ALL
                
                SELECT kode_barang, unit, stok_awal
                FROM bc_so0 a
                WHERE tahun = substr('$escNow', 1, 4) $cmpDetailFilter
                
                UNION ALL
                
                SELECT na.kode_barang, na.sat as unit, sum(CASE WHEN ni.ab = 'A' THEN na.qty ELSE na.qty * -1 END) stok
                FROM bc_issue_hdr ni, bc_issue_dtl na
                WHERE ni.id = na.id_hdr and (ni.iscancel IS NULL OR ni.iscancel <> 'Y')
                AND substr(ni.tgl, 1, 4) = substr('$escNow', 1, 4)
                AND ni.tgl <= '$escNow' $cmpIssueFilter
                GROUP BY na.kode_barang, na.sat		
            ) xx
            GROUP BY kode_barang, unit
        ) JML ON MASTER.KODE_BARANG = JML.KODE_BARANG AND MASTER.KODE_SATUAN = JML.UNIT
        
    ) NI
    WHERE 1=1 ";

    if (!empty($search_kw)) {
        $escKw = $koneksi->real_escape_string($search_kw);
        $sql1d .= " AND (NI.KODE_BARANG LIKE '%$escKw%' OR NI.URAIAN LIKE '%$escKw%' OR NI.MODEL LIKE '%$escKw%') ";
    }

    if (!$show_all_stok) {
        $sql1d .= " AND (NI.MASUK > 0 OR NI.KELUAR > 0) ";
    }

    $sql1d .= " ORDER BY NI.KODE_BARANG ASC ";

    $res = $koneksi->query($sql1d);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $dataRows[] = $row;
            $stats['total_stok'] += (float)$row['STOK'];
            $stats['total_awal'] += (float)$row['AWAL'];
            $stats['total_masuk'] += (float)$row['MASUK'];
            $stats['total_keluar'] += (float)$row['KELUAR'];
            $stats['total_adj'] += (float)$row['ADJ'];
            $stats['total_akhir'] += (float)$row['AKHIR'];
            $stats['total_so'] += (float)$row['SO'];
            $stats['total_selisih'] += (float)$row['SELISIH'];
        }
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
    if (!empty($escCmp)) {
        $sqlLog .= " AND r.cmp_id = '$escCmp' ";
    }
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

        /* LAPORAN 1A CUSTOM STYLES */
        .filter-panel-1a {
            background: var(--bg-filter);
            border: 1px solid var(--border-card);
            border-radius: 4px;
            padding: 3px 6px;
            margin-bottom: 4px;
        }
        .filter-panel-1a .filter-control {
            height: 21px;
            font-size: 9.5px;
            padding: 1px 4px;
            border: 1px solid var(--border-input);
            border-radius: 3px;
            background: var(--bg-input);
            color: var(--text-main);
            outline: none;
        }
        .filter-panel-1a .filter-control:focus {
            border-color: var(--primary);
        }
        .ant-table thead tr:first-child th {
            position: sticky;
            top: 0;
            z-index: 5;
            background: var(--table-head-bg);
        }
        .ant-table thead tr:nth-child(2) th {
            position: sticky;
            top: 24px;
            z-index: 4;
            background: var(--table-head-bg);
        }
        .ant-table tbody tr.row-selected td {
            background-color: #69c0ff !important;
            color: #000000 !important;
        }
        html[data-theme="dark"] .ant-table tbody tr.row-selected td,
        body[data-theme="dark"] .ant-table tbody tr.row-selected td {
            background-color: #11355c !important;
            color: #ffffff !important;
        }
        /* INNER REPORT HEADER BANNER: Sembunyikan dari layar (hanya untuk Print PDF & Export Excel) */
        .report-header-banner {
            display: none;
        }

        @page {
            size: A4 landscape;
            margin: 4mm;
        }

        @media print {
            @page {
                size: A4 landscape;
                margin: 4mm;
            }
            html, body {
                background: #ffffff !important;
                color: #000000 !important;
                font-family: 'Poppins', -apple-system, BlinkMacSystemFont, Arial, sans-serif !important;
                font-size: 6.2pt !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                overflow: visible !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .screen-only-header,
            .ant-tabs-nav,
            .filter-panel-1a,
            .filter-panel,
            .kpi-container,
            .ant-modal-mask,
            #tableFilterInput,
            #rowCountBadge,
            button,
            .ant-btn {
                display: none !important;
            }
            .report-header-banner {
                display: block !important;
                text-align: center !important;
                margin: 0 0 6px 0 !important;
                padding: 0 !important;
            }
            .report-header-banner .banner-title {
                font-size: 11pt !important;
                font-weight: 700 !important;
                color: #000000 !important;
                letter-spacing: 0.2px !important;
                margin-bottom: 2px !important;
            }
            .report-header-banner .banner-sub {
                font-size: 8pt !important;
                font-weight: 600 !important;
                color: #222222 !important;
            }
            .table-scroll,
            .ant-table-wrapper {
                max-height: none !important;
                overflow: visible !important;
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                border: none !important;
            }
            .ant-table {
                width: 100% !important;
                max-width: 100% !important;
                table-layout: auto !important;
                border-collapse: collapse !important;
                font-size: 6.2pt !important;
                page-break-inside: auto !important;
            }
            .ant-table tr {
                page-break-inside: avoid !important;
                page-break-after: auto !important;
            }
            .ant-table thead {
                display: table-header-group !important;
            }
            .ant-table tfoot {
                display: table-footer-group !important;
            }
            .ant-table th, .ant-table td {
                border: 0.5pt solid #333333 !important;
                padding: 2px 2.5px !important;
                font-size: 6.2pt !important;
                line-height: 1.15 !important;
                color: #000000 !important;
                word-break: break-word !important;
                white-space: normal !important;
                box-sizing: border-box !important;
            }
            .ant-table th {
                background: #f0f0f0 !important;
                font-weight: 700 !important;
                text-align: center !important;
            }
            .ant-table td.col-no,
            .ant-table td.col-jenis,
            .ant-table td.col-tgldoc,
            .ant-table td.col-tglbukti,
            .ant-table td.col-ext-netto,
            .ant-table td.col-ext-gross,
            .ant-table td:nth-child(8),
            .ant-table td:nth-child(11),
            .ant-table td:nth-child(12),
            .ant-table td:nth-child(13),
            .ant-table td:nth-child(14),
            .ant-table td:nth-child(15) {
                white-space: nowrap !important;
            }
            .ant-badge {
                border: none !important;
                background: transparent !important;
                color: #000000 !important;
                padding: 0 !important;
                font-size: 6.2pt !important;
                font-weight: normal !important;
            }
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
            max-height: calc(100vh - 120px);
            min-height: 420px;
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
        th.sortable-th::after,
        #mainDataTable thead th::after {
            content: none !important;
        }
        .sort-icon {
            display: none !important;
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
            <i class="fa-solid fa-house" style="margin-right:4px;"></i> Dashboard Home
        </a>
        <a href="?tab=1a&cmp_id=<?= urlencode($cmp_id) ?>&theme=<?= $theme ?>" class="ant-tabs-tab <?= $activeTab === '1a' ? 'active' : '' ?>">
            <i class="fa-solid fa-box-archive" style="margin-right:4px;"></i> 1A - Pemasukan Barang
        </a>
        <a href="?tab=1b&cmp_id=<?= urlencode($cmp_id) ?>&theme=<?= $theme ?>" class="ant-tabs-tab <?= $activeTab === '1b' ? 'active' : '' ?>">
            <i class="fa-solid fa-dolly" style="margin-right:4px;"></i> 1B - Pengeluaran Barang
        </a>
        <a href="?tab=1c&cmp_id=<?= urlencode($cmp_id) ?>&theme=<?= $theme ?>" class="ant-tabs-tab <?= $activeTab === '1c' ? 'active' : '' ?>">
            <i class="fa-solid fa-gears" style="margin-right:4px;"></i> 1C - Posisi WIP
        </a>
        <a href="?tab=1d&cmp_id=<?= urlencode($cmp_id) ?>&theme=<?= $theme ?>" class="ant-tabs-tab <?= $activeTab === '1d' ? 'active' : '' ?>">
            <i class="fa-solid fa-arrows-rotate" style="margin-right:4px;"></i> 1D - Mutasi Barang
        </a>
        <a href="?tab=log&cmp_id=<?= urlencode($cmp_id) ?>&theme=<?= $theme ?>" class="ant-tabs-tab <?= $activeTab === 'log' ? 'active' : '' ?>">
            <i class="fa-solid fa-clock-rotate-left" style="margin-right:4px;"></i> Log Perubahan Data
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
    <?php if (in_array($activeTab, ['1a', '1b'])): ?>
    <?php 
        $tabTitle = ($activeTab === '1a') ? 'Laporan 1A - Laporan Pemasukan Barang per Dokumen Pabean' : 'Laporan 1B - Laporan Pengeluaran Barang per Dokumen Pabean';
        $reportBannerTitle = ($activeTab === '1a') ? 'Laporan Pemasukan Barang per Dokumen Pabean' : 'Laporan Pengeluaran Barang per Dokumen Pabean';
        $tabIcon = ($activeTab === '1a') ? 'fa-file-import' : 'fa-file-export';
        $tabIconColor = ($activeTab === '1a') ? 'var(--primary)' : '#f5222d';
    ?>
    <!-- ============================================================== -->
    <!-- TAB 1A / 1B: LAPORAN DOKUMEN PABEAN (PEMASUKAN & PENGELUARAN)  -->
    <!-- Sesuai Desain PowerBuilder TPB-ERP & Standar CEISA 4.0        -->
    <!-- ============================================================== -->
    <!-- FILTER BAR TAB 1A & 1B (COMPACT 1-LINE TOOLBAR) -->
    <form method="GET" action="it_inventory.php" id="filterForm1AB" class="filter-panel-1a" style="display:flex; align-items:center; justify-content:space-between; gap:6px; flex-wrap:nowrap; padding:3px 8px; margin-bottom:4px; overflow-x:auto;">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
        <input type="hidden" name="theme" value="<?= htmlspecialchars($theme) ?>">
        <input type="hidden" name="cmp_id" value="<?= htmlspecialchars($cmp_id) ?>">
        <input type="hidden" id="inputGroupMode" name="group_mode" value="<?= $group_mode ? '1' : '0' ?>">
        <input type="hidden" id="inputShowExt" name="show_ext" value="<?= $show_ext ? '1' : '0' ?>">

        <!-- LEFT CONTROLS -->
        <div style="display:inline-flex; align-items:center; gap:8px; flex-shrink:0;">
            <!-- PERIODE -->
            <div style="display:inline-flex; align-items:center; gap:3px;">
                <label style="font-size:9.5px; font-weight:600; color:var(--text-muted); margin:0; white-space:nowrap;">Periode:</label>
                <input type="date" name="tgl1" class="filter-control" style="width:112px; height:21px; font-size:9.5px; padding:0 3px;" value="<?= htmlspecialchars($tgl1) ?>">
                <span style="font-size:9px; color:var(--text-muted);">-s/d-</span>
                <input type="date" name="tgl2" class="filter-control" style="width:112px; height:21px; font-size:9.5px; padding:0 3px;" value="<?= htmlspecialchars($tgl2) ?>">
            </div>

            <!-- JENIS DOKUMEN -->
            <div style="display:inline-flex; align-items:center; gap:3px;">
                <label style="font-size:9.5px; font-weight:600; color:var(--text-muted); margin:0; white-space:nowrap;">BC:</label>
                <select name="bc" class="filter-control" style="width:105px; height:21px; font-size:9.5px; padding:0 3px;">
                    <option value="%" <?= $bc_filter === '%' ? 'selected' : '' ?>>- SEMUA BC -</option>
                    <?php if ($activeTab === '1a'): ?>
                        <?php if (isItDocPermitted('27')): ?><option value="27" <?= $bc_filter === '27' ? 'selected' : '' ?>>27 - BC 2.7</option><?php endif; ?>
                        <?php if (isItDocPermitted('23')): ?><option value="23" <?= $bc_filter === '23' ? 'selected' : '' ?>>23 - BC 2.3</option><?php endif; ?>
                        <?php if (isItDocPermitted('40')): ?><option value="40" <?= $bc_filter === '40' ? 'selected' : '' ?>>40 - BC 4.0</option><?php endif; ?>
                        <?php if (isItDocPermitted('20')): ?><option value="20" <?= $bc_filter === '20' ? 'selected' : '' ?>>20 - BC 2.0</option><?php endif; ?>
                        <?php if (isItDocPermitted('611')): ?><option value="611" <?= $bc_filter === '611' ? 'selected' : '' ?>>611 - PPKEK 6.1.1</option><?php endif; ?>
                        <?php if (isItDocPermitted('16')): ?><option value="16" <?= $bc_filter === '16' ? 'selected' : '' ?>>16 - BC 1.6</option><?php endif; ?>
                        <?php if (isItDocPermitted('261')): ?><option value="261" <?= $bc_filter === '261' ? 'selected' : '' ?>>261 - BC 2.6.1</option><?php endif; ?>
                        <?php if (isItDocPermitted('28')): ?><option value="28" <?= $bc_filter === '28' ? 'selected' : '' ?>>28 - BC 2.8</option><?php endif; ?>
                    <?php else: ?>
                        <?php if (isItDocPermitted('30')): ?><option value="30" <?= $bc_filter === '30' ? 'selected' : '' ?>>30 - BC 3.0</option><?php endif; ?>
                        <?php if (isItDocPermitted('25')): ?><option value="25" <?= $bc_filter === '25' ? 'selected' : '' ?>>25 - BC 2.5</option><?php endif; ?>
                        <?php if (isItDocPermitted('41')): ?><option value="41" <?= $bc_filter === '41' ? 'selected' : '' ?>>41 - BC 4.1</option><?php endif; ?>
                        <?php if (isItDocPermitted('27')): ?><option value="27" <?= $bc_filter === '27' ? 'selected' : '' ?>>27 - BC 2.7</option><?php endif; ?>
                        <?php if (isItDocPermitted('613')): ?><option value="613" <?= $bc_filter === '613' ? 'selected' : '' ?>>613 - PPKEK 6.1.3</option><?php endif; ?>
                        <?php if (isItDocPermitted('631')): ?><option value="631" <?= $bc_filter === '631' ? 'selected' : '' ?>>631 - PPKEK 6.3.1</option><?php endif; ?>
                        <?php if (isItDocPermitted('262')): ?><option value="262" <?= $bc_filter === '262' ? 'selected' : '' ?>>262 - BC 2.6.2</option><?php endif; ?>
                        <?php if (isItDocPermitted('33')): ?><option value="33" <?= $bc_filter === '33' ? 'selected' : '' ?>>33 - BC 3.3</option><?php endif; ?>
                        <?php if (isItDocPermitted('331')): ?><option value="331" <?= $bc_filter === '331' ? 'selected' : '' ?>>331 - BC 3.3.1</option><?php endif; ?>
                    <?php endif; ?>
                </select>
            </div>

            <!-- KLASIFIKASI -->
            <div style="display:inline-flex; align-items:center; gap:3px;">
                <label style="font-size:9.5px; font-weight:600; color:var(--text-muted); margin:0; white-space:nowrap;">Klas:</label>
                <select name="klas" class="filter-control" style="width:110px; height:21px; font-size:9.5px; padding:0 3px;">
                    <option value="%" <?= ($klas_filter === '%' || empty($klas_filter)) ? 'selected' : '' ?>>-- SEMUA KLAS --</option>
                    <option value="Raw Materials" <?= $klas_filter === 'Raw Materials' ? 'selected' : '' ?>>Raw Materials</option>
                    <option value="Finished Goods" <?= $klas_filter === 'Finished Goods' ? 'selected' : '' ?>>Finished Goods</option>
                    <option value="Work In Progress" <?= $klas_filter === 'Work In Progress' ? 'selected' : '' ?>>Work In Progress</option>
                    <option value="Capital Goods" <?= $klas_filter === 'Capital Goods' ? 'selected' : '' ?>>Capital Goods</option>
                    <option value="Wastes" <?= $klas_filter === 'Wastes' ? 'selected' : '' ?>>Wastes</option>
                </select>
            </div>

            <!-- CARI / FILTER -->
            <div style="position:relative; display:inline-flex; align-items:center;">
                <input type="text" name="search_kw" id="filterSearchKw" class="filter-control" style="width:125px; height:21px; font-size:9.5px; padding:0 20px 0 5px;" placeholder="Filter Data / Teks.." value="<?= htmlspecialchars($search_kw) ?>" onkeyup="filterTableInstant(this.value)">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute; right:5px; top:5px; font-size:9.5px; color:var(--text-muted); pointer-events:none;"></i>
            </div>

            <!-- CHECKBOXES (INLINE) -->
            <div style="display:inline-flex; align-items:center; gap:8px; padding-left:4px; border-left:1px solid var(--border-light);">
                <label style="display:inline-flex; align-items:center; gap:3px; font-size:9.5px; font-weight:600; color:var(--text-main); cursor:pointer; user-select:none; margin:0; white-space:nowrap;">
                    <input type="checkbox" id="chkGroupMode" value="1" <?= $group_mode ? 'checked' : '' ?> onchange="toggleGroupMode(this.checked)" style="cursor:pointer; width:12px; height:12px; margin:0;">
                    Tampilan Grup
                </label>
                <label style="display:inline-flex; align-items:center; gap:3px; font-size:9.5px; font-weight:600; color:var(--text-main); cursor:pointer; user-select:none; margin:0; white-space:nowrap;">
                    <input type="checkbox" id="chkShowAjuNettoGross" value="1" <?= $show_ext ? 'checked' : '' ?> onchange="toggleAjuNettoGross(this.checked)" style="cursor:pointer; width:12px; height:12px; margin:0;">
                    Tampilkan No. AJU, Netto, Gross
                </label>
            </div>
        </div>

        <!-- RIGHT ACTION BUTTONS -->
        <div style="display:inline-flex; align-items:center; gap:4px; flex-shrink:0;">
            <button type="submit" class="ant-btn ant-btn-primary" style="height:21px; font-size:9.5px; padding:0 8px; font-weight:600; display:inline-flex; align-items:center; gap:4px;">
                <i class="fa-solid fa-desktop"></i> Tampilkan
            </button>
            <button type="button" class="ant-btn" onclick="printReport()" style="height:21px; font-size:9.5px; padding:0 8px; font-weight:600; background:#d4380d; border-color:#d4380d; color:#ffffff !important; display:inline-flex; align-items:center; gap:4px;">
                <i class="fa-solid fa-file-pdf"></i> Cetak ke PDF
            </button>
            <button type="button" class="ant-btn ant-btn-success" onclick="exportToExcelCustom('<?= $activeTab ?>')" style="height:21px; font-size:9.5px; padding:0 8px; font-weight:600; display:inline-flex; align-items:center; gap:4px;">
                <i class="fa-solid fa-file-excel"></i> Export ke XLS
            </button>
        </div>
    </form>

    <!-- INNER REPORT HEADER BANNER (Hanya tampil saat Print PDF & Save Excel) -->
    <div class="report-header-banner">
        <div class="banner-title"><?= $reportBannerTitle ?></div>
        <div class="banner-sub"><?= htmlspecialchars($cmp_name) ?> - Periode : <?= htmlspecialchars($tgl1) ?> s/d <?= htmlspecialchars($tgl2) ?></div>
    </div>
    <?php elseif ($activeTab === '1d'): ?>
    <?php 
        $klasLabelTitle = 'Barang';
        if (in_array($klas_filter, ['Raw Materials', 'Raw Material', 'RM'])) {
            $klasLabelTitle = 'Bahan Baku';
        } elseif (in_array($klas_filter, ['Finished Goods', 'Finished Good', 'FG'])) {
            $klasLabelTitle = 'Barang Jadi';
        } elseif ($isCapitalGoods) {
            if (!empty($sub_klas) && $sub_klas !== '%' && $sub_klas !== '-- SEMUA KLAS --') {
                $klasLabelTitle = 'Mesin dan Peralatan (' . $sub_klas . ')';
            } else {
                $klasLabelTitle = 'Mesin dan Peralatan';
            }
        } elseif (in_array($klas_filter, ['Wastes', 'Waste', 'W'])) {
            $klasLabelTitle = 'Barang Sisa / Scrap';
        } elseif (in_array($klas_filter, ['Work In Progress', 'WIP', 'WI'])) {
            $klasLabelTitle = 'Barang Dalam Proses (WIP)';
        }
        $reportBannerTitle = 'Laporan Pertanggungjawaban Mutasi ' . $klasLabelTitle;
    ?>
    <!-- ============================================================== -->
    <!-- TAB 1D: LAPORAN MUTASI BARANG (COMPACT 1-LINE TOOLBAR)         -->
    <!-- Sesuai D:\xampp\htdocs\bc3\1d_bb, 1d_bj, 1d_ma, 1d_s           -->
    <!-- ============================================================== -->
    <form method="GET" action="it_inventory.php" id="filterForm1D" class="filter-panel-1a" style="display:flex; align-items:center; justify-content:space-between; gap:6px; flex-wrap:nowrap; padding:3px 8px; margin-bottom:4px; overflow-x:auto;">
        <input type="hidden" name="tab" value="1d">
        <input type="hidden" name="theme" value="<?= htmlspecialchars($theme) ?>">
        <input type="hidden" name="cmp_id" value="<?= htmlspecialchars($cmp_id) ?>">

        <!-- LEFT CONTROLS -->
        <div style="display:inline-flex; align-items:center; gap:8px; flex-shrink:0;">
            <!-- PERIODE -->
            <div style="display:inline-flex; align-items:center; gap:3px;">
                <label style="font-size:9.5px; font-weight:600; color:var(--text-muted); margin:0; white-space:nowrap;">Periode:</label>
                <input type="date" name="tgl1" class="filter-control" style="width:112px; height:21px; font-size:9.5px; padding:0 3px;" value="<?= htmlspecialchars($tgl1) ?>">
                <span style="font-size:9px; color:var(--text-muted);">-s/d-</span>
                <input type="date" name="tgl2" class="filter-control" style="width:112px; height:21px; font-size:9.5px; padding:0 3px;" value="<?= htmlspecialchars($tgl2) ?>">
            </div>

            <!-- KLASIFIKASI -->
            <div style="display:inline-flex; align-items:center; gap:3px;">
                <label style="font-size:9.5px; font-weight:600; color:var(--text-muted); margin:0; white-space:nowrap;">Klas:</label>
                <select name="klas" id="selectKlas1D" class="filter-control" style="width:125px; height:21px; font-size:9.5px; padding:0 3px;" onchange="handleKlasChange(this.value)">
                    <option value="Raw Materials" <?= in_array($klas_filter, ['Raw Materials', 'Raw Material', 'RM']) ? 'selected' : '' ?>>Raw Materials</option>
                    <option value="Finished Goods" <?= in_array($klas_filter, ['Finished Goods', 'Finished Good', 'FG']) ? 'selected' : '' ?>>Finished Goods</option>
                    <option value="Capital Goods" <?= $isCapitalGoods ? 'selected' : '' ?>>Capital Goods</option>
                    <option value="Wastes" <?= in_array($klas_filter, ['Wastes', 'Waste', 'W']) ? 'selected' : '' ?>>Wastes</option>
                    <option value="Work In Progress" <?= in_array($klas_filter, ['Work In Progress', 'WIP', 'WI']) ? 'selected' : '' ?>>Work In Progress</option>
                    <option value="%" <?= ($klas_filter === '%' || empty($klas_filter)) ? 'selected' : '' ?>>-- SEMUA KLAS --</option>
                </select>
            </div>

            <!-- SUB-KLAS KHUSUS CAPITAL GOODS -->
            <div id="subKlasContainer" style="display:<?= $isCapitalGoods ? 'inline-flex' : 'none' ?>; align-items:center; gap:3px;">
                <label style="font-size:9.5px; font-weight:600; color:var(--primary); margin:0; white-space:nowrap;">Sub-Klas:</label>
                <select name="sub_klas" id="subKlasSelect" class="filter-control" style="width:125px; height:21px; font-size:9.5px; padding:0 3px; font-weight:600;" onchange="this.form.submit()">
                    <option value="%" <?= ($sub_klas === '%' || empty($sub_klas) || $sub_klas === '-- SEMUA KLAS --') ? 'selected' : '' ?>>-- SEMUA KLAS --</option>
                    <?php foreach ($cgTypes as $cgt): ?>
                        <option value="<?= htmlspecialchars($cgt) ?>" <?= $sub_klas === $cgt ? 'selected' : '' ?>><?= htmlspecialchars($cgt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- CARI / FILTER -->
            <div style="position:relative; display:inline-flex; align-items:center;">
                <input type="text" name="search_kw" id="filterSearchKw" class="filter-control" style="width:130px; height:21px; font-size:9.5px; padding:0 20px 0 5px;" placeholder="Filter Data / Cari Teks.." value="<?= htmlspecialchars($search_kw) ?>" onkeyup="filterTableInstant(this.value)">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute; right:5px; top:5px; font-size:9.5px; color:var(--text-muted); pointer-events:none;"></i>
            </div>

            <!-- CHECKBOX TAMPILKAN SEMUA STOK -->
            <div style="display:inline-flex; align-items:center; gap:4px; padding-left:4px; border-left:1px solid var(--border-light);">
                <label style="display:inline-flex; align-items:center; gap:3px; font-size:9.5px; font-weight:600; color:var(--text-main); cursor:pointer; user-select:none; margin:0; white-space:nowrap;">
                    <input type="checkbox" name="stok" id="chkStok" value="Y" <?= $show_all_stok ? 'checked' : '' ?> onchange="this.form.submit()" style="cursor:pointer; width:12px; height:12px; margin:0;">
                    <?= htmlspecialchars($stokCheckboxLabel) ?>
                </label>
            </div>
        </div>

        <!-- RIGHT ACTION BUTTONS -->
        <div style="display:inline-flex; align-items:center; gap:4px; flex-shrink:0;">
            <button type="submit" class="ant-btn ant-btn-primary" style="height:21px; font-size:9.5px; padding:0 8px; font-weight:600; display:inline-flex; align-items:center; gap:4px;">
                <i class="fa-solid fa-desktop"></i> Tampilkan
            </button>
            <button type="button" class="ant-btn" onclick="printReport()" style="height:21px; font-size:9.5px; padding:0 8px; font-weight:600; background:#d4380d; border-color:#d4380d; color:#ffffff !important; display:inline-flex; align-items:center; gap:4px;">
                <i class="fa-solid fa-file-pdf"></i> Cetak ke PDF
            </button>
            <button type="button" class="ant-btn ant-btn-success" onclick="exportToExcelCustom('1d')" style="height:21px; font-size:9.5px; padding:0 8px; font-weight:600; display:inline-flex; align-items:center; gap:4px;">
                <i class="fa-solid fa-file-excel"></i> Export ke XLS
            </button>
        </div>
    </form>

    <!-- INNER REPORT HEADER BANNER (Hanya tampil saat Print PDF & Save Excel) -->
    <div class="report-header-banner">
        <div class="banner-title"><?= $reportBannerTitle ?></div>
        <div class="banner-sub"><?= htmlspecialchars($cmp_name) ?> - Periode : <?= htmlspecialchars($tgl1) ?> s/d <?= htmlspecialchars($tgl2) ?></div>
    </div>
    <?php else: ?>
    <!-- FILTER BAR FOR OTHER TABS (1C, LOG) -->
    <form method="GET" action="it_inventory.php" class="filter-panel">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
        <input type="hidden" name="theme" value="<?= htmlspecialchars($theme) ?>">
        <input type="hidden" name="cmp_id" value="<?= htmlspecialchars($cmp_id) ?>">

        <?php if ($activeTab === '1c'): ?>
            <div class="filter-col">
                <label>Tanggal Cut-off Posisi WIP</label>
                <input type="date" name="tgl2" class="filter-control" value="<?= htmlspecialchars($tgl2) ?>">
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
            <i class="fa-solid fa-magnifying-glass"></i> Muat Data
        </button>
        <a href="it_inventory.php?tab=<?= $activeTab ?>&theme=<?= $theme ?>" class="ant-btn">
            <i class="fa-solid fa-rotate-left"></i> Reset
        </a>
        <button type="button" class="ant-btn ant-btn-success" onclick="exportToExcel('<?= $activeTab ?>')" title="Simpan sebagai file Excel (.xlsx)">
            <i class="fa-solid fa-file-excel"></i> Save Excel (.xlsx)
        </button>
        <button type="button" class="ant-btn" onclick="exportTableToCSV('<?= $activeTab ?>')" title="Unduh CSV">
            <i class="fa-solid fa-file-csv"></i> CSV
        </button>
        <button type="button" class="ant-btn" onclick="copyTableClipboard()" title="Salin data ke clipboard">
            <i class="fa-solid fa-clipboard"></i> Copy Grid
        </button>
    </form>

    <!-- KPI CHIPS SUMMARY -->
    <div class="kpi-container">
        <div class="kpi-chip">
            <span class="kpi-label">Total Data:</span>
            <span class="kpi-val"><?= number_format($stats['total_rows']) ?> Baris</span>
        </div>
        <?php if ($activeTab === '1c'): ?>
            <div class="kpi-chip">
                <span class="kpi-label">Total Saldo WIP:</span>
                <span class="kpi-val" style="color:#1890ff;"><?= fnum($stats['total_qty']) ?></span>
            </div>
        <?php endif; ?>
    </div>

    <!-- TABLE SEARCH & SORT TOOLBAR -->
    <div style="display:flex; justify-content:space-between; align-items:center; padding:7px 12px; background:var(--bg-filter); border:1px solid var(--border-card); border-bottom:none; border-radius:4px 4px 0 0; gap:10px; flex-wrap:wrap; margin-top:8px;">
        <div style="display:flex; align-items:center; gap:8px;">
            <i class="fa-solid fa-bolt" style="color:#faad14; font-size:12px;"></i>
            <input type="text" id="tableFilterInput" class="filter-control" placeholder="Cari instan di tabel (semua kolom)..." onkeyup="filterTableInstant()" style="width: 240px; padding:3px 8px;">
            <span id="rowCountBadge" class="ant-badge ant-badge-blue"><?= count($dataRows) ?> Baris Data</span>
            <button type="button" class="ant-btn" onclick="clearTableFilter()" style="height:22px; padding:0 6px; font-size:10px;">&times; Clear</button>
        </div>
        <div style="font-size:10.5px; color:var(--text-muted); display:flex; align-items:center; gap:12px;">
            <span><i class="fa-regular fa-lightbulb" style="color:#faad14;"></i> Klik judul kolom untuk mengurutkan (Sort &#9650;&#9660;)</span>
            <button type="button" class="ant-btn ant-btn-success" onclick="exportToExcel('<?= $activeTab ?>')" style="height:23px; font-weight:600; padding:0 8px;">
                <i class="fa-solid fa-file-excel"></i> Save Excel (.xlsx)
            </button>
        </div>
    </div>
    <?php endif; ?>
    <!-- MAIN DATA TABLE -->
    <div class="ant-table-wrapper" style="border-top-left-radius:0; border-top-right-radius:0;">
        <div class="table-scroll">
            <table class="ant-table" id="mainDataTable">
                <thead>
                <?php if (in_array($activeTab, ['1a', '1b'])): ?>
                    <?php 
                        $partnerHeader = ($activeTab === '1a') ? 'Pengirim Barang' : 'Penerima Barang';
                        $buktiHeader   = ($activeTab === '1a') ? 'Bukti Penerimaan' : 'Bukti Pengeluaran';
                    ?>
                    <tr>
                        <th rowspan="2" style="width:30px; text-align:center;">No.</th>
                        <th colspan="3" style="text-align:center;">Dokumen Pabean</th>
                        <th colspan="2" style="text-align:center;"><?= $buktiHeader ?></th>
                        <th rowspan="2"><?= $partnerHeader ?></th>
                        <th rowspan="2">Kode Barang</th>
                        <th rowspan="2">HS Kode</th>
                        <th rowspan="2">Nama Barang</th>
                        <th rowspan="2">Klasifikasi</th>
                        <th rowspan="2" style="text-align:center;">Sat</th>
                        <th rowspan="2" style="text-align:right;">Jumlah</th>
                        <th rowspan="2" style="text-align:center;">Kurs</th>
                        <th rowspan="2" style="text-align:right;">Nilai</th>
                        <th rowspan="2" style="text-align:right;">Harga Rupiah</th>
                        <th rowspan="2" class="col-ext col-ext-aju" style="text-align:center; <?= !$show_ext ? 'display:none;' : '' ?>">No. AJU</th>
                        <th rowspan="2" class="col-ext col-ext-netto" style="text-align:right; <?= !$show_ext ? 'display:none;' : '' ?>">Netto</th>
                        <th rowspan="2" class="col-ext col-ext-gross" style="text-align:right; <?= !$show_ext ? 'display:none;' : '' ?>">Gross</th>
                    </tr>
                    <tr>
                        <th style="text-align:center;">Jenis</th>
                        <th>Nomor</th>
                        <th style="text-align:center;">Tanggal</th>
                        <th>Nomor</th>
                        <th style="text-align:center;">Tanggal</th>
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
                        <th style="width:30px; text-align:center;">No.</th>
                        <th style="text-align:center;">Kode Barang</th>
                        <th>Nama Barang</th>
                        <th style="text-align:center;">Satuan</th>
                        <th style="text-align:right;">Jumlah<br>Barang</th>
                        <th style="text-align:right; width:90px;"><u>Saldo Awal</u><br><span style="font-weight:normal; font-size:8.5px;"><?= htmlspecialchars($tgl1) ?></span></th>
                        <th style="text-align:right;">Pemasukan</th>
                        <th style="text-align:right;">Pengeluaran</th>
                        <th style="text-align:right;">Penyesuaian</th>
                        <th style="text-align:right; width:90px;"><u>Saldo Akhir</u><br><span style="font-weight:normal; font-size:8.5px;"><?= htmlspecialchars($tgl2) ?></span></th>
                        <th style="text-align:right;">Stok Opname</th>
                        <th style="text-align:right;">Selisih</th>
                        <th style="text-align:center;">Keterangan</th>
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
                <?php if (in_array($activeTab, ['1a', '1b'])): ?>
                    <?php if (empty($dataRows)): ?>
                        <tr>
                            <td colspan="19" style="text-align:center; padding: 24px; color: var(--text-muted);">
                                Tidak ada data yang ditemukan untuk kriteria filter ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php
                        $rowNo = 1;
                        $groupNo = 0;
                        $lastGroupKey = null;
                        foreach ($dataRows as $idx => $r):
                            $groupKey = trim($r['JENIS_DOC'] ?? '') . '::' . trim($r['NO_DOC'] ?? '') . '::' . trim($r['TGL_DOC'] ?? '') . '::' . trim($r['NO_BUKTI'] ?? '') . '::' . trim($r['NO_AJU'] ?? '');
                            $isFirstInGroup = ($groupKey !== $lastGroupKey);
                            if ($isFirstInGroup) {
                                $groupNo++;
                                $lastGroupKey = $groupKey;
                            }

                            $jenisDoc = htmlspecialchars($r['JENIS_DOC'] ?? '-');
                            $noDoc    = htmlspecialchars($r['NO_DOC'] ?? '-');
                            $tglDoc   = htmlspecialchars($r['TGL_DOC'] ?? '-');
                            $noBukti  = htmlspecialchars($r['NO_BUKTI'] ?? '-');
                            $tglBukti = htmlspecialchars($r['TGL_BUKTI'] ?? '-');
                            $partner  = htmlspecialchars(($activeTab === '1a') ? ($r['VENDOR'] ?? ($r['NAMA_PENERIMA_BARANG'] ?? '-')) : ($r['NAMA_PENERIMA_BARANG'] ?? ($r['VENDOR'] ?? '-')));
                            $kodeBrg  = htmlspecialchars($r['KODE_BARANG'] ?? '-');
                            $hsKode   = htmlspecialchars($r['POS_TARIF'] ?? '-');
                            $namaBrg  = htmlspecialchars($r['NAMA_BARANG'] ?? '-');
                            $klas     = htmlspecialchars($r['KLAS'] ?? '-');
                            $satuan   = htmlspecialchars($r['SATUAN'] ?? '-');
                            $kurs     = htmlspecialchars($r['KODE_VALUTA'] ?? 'IDR');
                            $noAju    = htmlspecialchars($r['NO_AJU'] ?? '-');

                            $qtyVal    = (float)($r['JUMLAH'] ?? 0);
                            $qtyDisp   = (fmod($qtyVal, 1) == 0) ? number_format($qtyVal, 0) : number_format($qtyVal, 2);

                            $nilaiVal  = (float)($r['NILAI'] ?? 0);
                            $nilaiDisp = (fmod($nilaiVal, 1) == 0) ? number_format($nilaiVal, 0) : number_format($nilaiVal, 2);

                            $hargaPnyVal  = (float)($r['HARGA_PENYERAHAN'] ?? 0);
                            $hargaPnyDisp = (fmod($hargaPnyVal, 1) == 0) ? number_format($hargaPnyVal, 0) : number_format($hargaPnyVal, 2);

                            $nettoVal  = (float)($r['NETTO'] ?? 0);
                            $nettoDisp = number_format($nettoVal, 2);

                            $grossVal  = (float)($r['GROSS'] ?? 0);
                            $grossDisp = number_format($grossVal, 2);
                        ?>
                        <tr data-row-idx="<?= $idx ?>"
                            data-group-no="<?= $groupNo ?>"
                            data-row-no="<?= $rowNo ?>"
                            data-is-first="<?= $isFirstInGroup ? '1' : '0' ?>"
                            onclick="selectReportRow(this)">

                            <td class="col-no" data-orig-text="<?= $rowNo ?>" style="text-align:center; color:var(--text-muted);">
                                <?= $group_mode ? ($isFirstInGroup ? $groupNo : '') : $rowNo ?>
                            </td>

                            <td class="col-grp col-jenis" data-orig-text="<?= $jenisDoc ?>" style="text-align:center;">
                                <?= ($group_mode && !$isFirstInGroup) ? '' : $jenisDoc ?>
                            </td>

                            <td class="col-grp col-nodoc" data-orig-text="<?= $noDoc ?>" style="font-weight:600;">
                                <?= ($group_mode && !$isFirstInGroup) ? '' : $noDoc ?>
                            </td>

                            <td class="col-grp col-tgldoc" data-orig-text="<?= $tglDoc ?>" style="text-align:center;">
                                <?= ($group_mode && !$isFirstInGroup) ? '' : $tglDoc ?>
                            </td>

                            <td class="col-grp col-nobukti" data-orig-text="<?= $noBukti ?>">
                                <?= ($group_mode && !$isFirstInGroup) ? '' : $noBukti ?>
                            </td>

                            <td class="col-grp col-tglbukti" data-orig-text="<?= $tglBukti ?>" style="text-align:center;">
                                <?= ($group_mode && !$isFirstInGroup) ? '' : $tglBukti ?>
                            </td>

                            <td class="col-grp col-partner" data-orig-text="<?= $partner ?>">
                                <?= ($group_mode && !$isFirstInGroup) ? '' : $partner ?>
                            </td>

                            <td style="font-family:monospace; font-weight:600; color:var(--primary);"><?= $kodeBrg ?></td>
                            <td><?= $hsKode ?></td>
                            <td><?= $namaBrg ?></td>
                            <td><span class="ant-badge ant-badge-gold"><?= $klas ?></span></td>
                            <td style="text-align:center;"><?= $satuan ?></td>
                            <td style="text-align:right; font-weight:600;"><?= $qtyDisp ?></td>
                            <td style="text-align:center;"><?= $kurs ?></td>
                            <td style="text-align:right; font-weight:600; color:#52c41a;"><?= $nilaiDisp ?></td>
                            <td style="text-align:right; font-weight:600;"><?= $hargaPnyDisp ?></td>

                            <!-- No. AJU (Ikut Grouping seperti Nomor Daftar & Bisa di-hide) -->
                            <td class="col-ext col-ext-aju col-grp" data-orig-text="<?= $noAju ?>" style="text-align:center; font-family:monospace; <?= !$show_ext ? 'display:none;' : '' ?>">
                                <?= ($group_mode && !$isFirstInGroup) ? '' : $noAju ?>
                            </td>
                            <td class="col-ext col-ext-netto" style="text-align:right; <?= !$show_ext ? 'display:none;' : '' ?>">
                                <?= $nettoDisp ?>
                            </td>
                            <td class="col-ext col-ext-gross" style="text-align:right; <?= !$show_ext ? 'display:none;' : '' ?>">
                                <?= $grossDisp ?>
                            </td>
                        </tr>
                        <?php $rowNo++; endforeach; ?>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if (empty($dataRows)): ?>
                        <tr>
                            <td colspan="21" style="text-align:center; padding: 24px; color: var(--text-muted);">
                                Tidak ada data yang ditemukan untuk kriteria filter ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $no = 1; foreach ($dataRows as $r): ?>
                            <?php if ($activeTab === '1c'): ?>
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
                                <?php
                                    $kodeBrg = htmlspecialchars($r['KODE_BARANG'] ?? '-');
                                    $namaBrg = htmlspecialchars($r['URAIAN'] ?? '-');
                                    $satuan  = htmlspecialchars($r['KODE_SATUAN'] ?? '-');
                                    $stokJml = (float)($r['STOK'] ?? 0);
                                    $saldoAwal = (float)($r['AWAL'] ?? 0);
                                    $masuk     = (float)($r['MASUK'] ?? 0);
                                    $keluar    = (float)($r['KELUAR'] ?? 0);
                                    $adj       = (float)($r['ADJ'] ?? 0);
                                    $saldoAkhir = (float)($r['AKHIR'] ?? 0);
                                    $so        = (float)($r['SO'] ?? 0);
                                    $selisih   = (float)($r['SELISIH'] ?? 0);

                                    $ket = '';
                                    if ($selisih == 0) { $ket = 'Sesuai'; }
                                    elseif ($selisih > 0) { $ket = 'Selisih Lebih'; }
                                    elseif ($selisih < 0) { $ket = 'Selisih Kurang'; }
                                    if ($so == 0 && $selisih == 0 && $adj == 0) {
                                        $ket = '';
                                    }

                                    $soDisp = ($so == 0 && $selisih == 0 && $adj == 0) ? '' : fnum($so);
                                    $selisihDisp = ($so == 0 && $selisih == 0 && $adj == 0) ? '' : fnum($selisih);
                                    $adjDisp = ($adj == 0) ? '0' : fnum($adj);
                                ?>
                                <tr onclick="selectReportRow(this)">
                                    <td style="text-align:center; color: var(--text-muted);"><?= $no++ ?></td>
                                    <td style="font-family:monospace; font-weight:600; color:var(--primary);"><?= $kodeBrg ?></td>
                                    <td><?= $namaBrg ?></td>
                                    <td style="text-align:center;"><?= $satuan ?></td>
                                    <td style="text-align:right; font-weight:600;"><?= fnum($stokJml) ?></td>
                                    <td style="text-align:right;"><?= fnum($saldoAwal) ?></td>
                                    <td style="text-align:right; font-weight:600; color:#52c41a; cursor:pointer;"
                                        title="Dobel-klik untuk melihat Rincian Mutasi Masuk"
                                        ondblclick="openMutasiDetail('<?= addslashes($r['KODE_BARANG']) ?>', 'A', '<?= $tgl1 ?>', '<?= $tgl2 ?>', '<?= addslashes($klasVal) ?>')">
                                        <a href="javascript:void(0)" onclick="openMutasiDetail('<?= addslashes($r['KODE_BARANG']) ?>', 'A', '<?= $tgl1 ?>', '<?= $tgl2 ?>', '<?= addslashes($klasVal) ?>')" style="text-decoration:none; color:inherit;">
                                            <?= fnum($masuk) ?>
                                        </a>
                                    </td>
                                    <td style="text-align:right; font-weight:600; color:#f5222d; cursor:pointer;"
                                        title="Dobel-klik untuk melihat Rincian Mutasi Keluar"
                                        ondblclick="openMutasiDetail('<?= addslashes($r['KODE_BARANG']) ?>', 'B', '<?= $tgl1 ?>', '<?= $tgl2 ?>', '<?= addslashes($klasVal) ?>')">
                                        <a href="javascript:void(0)" onclick="openMutasiDetail('<?= addslashes($r['KODE_BARANG']) ?>', 'B', '<?= $tgl1 ?>', '<?= $tgl2 ?>', '<?= addslashes($klasVal) ?>')" style="text-decoration:none; color:inherit;">
                                            <?= fnum($keluar) ?>
                                        </a>
                                    </td>
                                    <td style="text-align:right;"><?= $adjDisp ?></td>
                                    <td style="text-align:right; font-weight:700; color:#1890ff;"><?= fnum($saldoAkhir) ?></td>
                                    <td style="text-align:right;"><?= $soDisp ?></td>
                                    <td style="text-align:right; font-weight:700; color:<?= $selisih != 0 ? '#faad14' : '#52c41a' ?>;"><?= $selisihDisp ?></td>
                                    <td style="text-align:center;">
                                        <?php if ($ket === 'Sesuai'): ?>
                                            <span class="ant-badge ant-badge-green">Sesuai</span>
                                        <?php elseif ($ket === 'Selisih Lebih'): ?>
                                            <span class="ant-badge ant-badge-blue">Selisih Lebih</span>
                                        <?php elseif ($ket === 'Selisih Kurang'): ?>
                                            <span class="ant-badge ant-badge-red">Selisih Kurang</span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
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
                                            <i class="fa-solid fa-magnifying-glass"></i> Detail (<?= (int)($r['JUMLAH_REVISI'] ?? 0) ?>)
                                        </button>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
                </tbody>
                <?php if (!empty($dataRows)): ?>
                <tfoot>
                <?php if (in_array($activeTab, ['1a', '1b'])): ?>
                    <tr>
                        <th colspan="12" style="text-align:right;">TOTAL:</th>
                        <th style="text-align:right; font-weight:700;"><?= fnum($stats['total_qty']) ?></th>
                        <th style="text-align:center;"></th>
                        <th style="text-align:right; font-weight:700; color:#52c41a;"><?= fnum($stats['total_nilai']) ?></th>
                        <th style="text-align:right; font-weight:700;"><?= fnum($stats['total_harga_penyerahan']) ?></th>
                        <th class="col-ext col-ext-aju" style="<?= !$show_ext ? 'display:none;' : '' ?>"></th>
                        <th class="col-ext col-ext-netto" style="text-align:right; font-weight:700; <?= !$show_ext ? 'display:none;' : '' ?>"><?= fnum($stats['total_netto']) ?></th>
                        <th class="col-ext col-ext-gross" style="text-align:right; font-weight:700; <?= !$show_ext ? 'display:none;' : '' ?>"><?= fnum($stats['total_gross']) ?></th>
                    </tr>
                <?php elseif ($activeTab === '1c'): ?>
                    <tr>
                        <th colspan="5" style="text-align:right;">TOTAL STOK WIP:</th>
                        <th style="text-align:right; color:#1890ff;"><?= fnum($stats['total_qty']) ?></th>
                        <th></th>
                    </tr>
                <?php elseif ($activeTab === '1d'): ?>
                    <tr>
                        <th colspan="4" style="text-align:right;">TOTAL:</th>
                        <th style="text-align:right; font-weight:700;"><?= fnum($stats['total_stok']) ?></th>
                        <th style="text-align:right; font-weight:700;"><?= fnum($stats['total_awal']) ?></th>
                        <th style="text-align:right; font-weight:700; color:#52c41a;"><?= fnum($stats['total_masuk']) ?></th>
                        <th style="text-align:right; font-weight:700; color:#f5222d;"><?= fnum($stats['total_keluar']) ?></th>
                        <th style="text-align:right; font-weight:700;"><?= fnum($stats['total_adj']) ?></th>
                        <th style="text-align:right; font-weight:700; color:#1890ff;"><?= fnum($stats['total_akhir']) ?></th>
                        <th style="text-align:right; font-weight:700;"><?= fnum($stats['total_so']) ?></th>
                        <th style="text-align:right; font-weight:700; color:<?= $stats['total_selisih'] != 0 ? '#faad14' : '#52c41a' ?>;"><?= fnum($stats['total_selisih']) ?></th>
                        <th></th>
                    </tr>
                <?php endif; ?>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
    <?php endif; // Akhir dari if ($activeTab === 'home') else ?>
</div>

<!-- MODAL DETAIL BREAKDOWN MUTASI (MASUK / KELUAR) -->
<div class="ant-modal-mask" id="mutasiModalMask" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.65); z-index:1050; align-items:center; justify-content:center; backdrop-filter:blur(2px);">
    <div class="ant-modal" style="width:94%; max-width:1150px; background:var(--bg-card); border-radius:6px; box-shadow:0 8px 32px rgba(0,0,0,0.25); display:flex; flex-direction:column; max-height:88vh; overflow:hidden; border:1px solid var(--border-card);">
        <div class="ant-modal-header" style="display:flex; justify-content:space-between; align-items:center; padding:8px 14px; background:var(--table-head-bg); border-bottom:1px solid var(--border-card);">
            <div>
                <span style="font-size:11px; font-weight:700; color:var(--text-main); display:inline-flex; align-items:center; gap:6px;">
                    <i id="mutasiModalIcon" class="fa-solid fa-arrow-down-long" style="color:#52c41a;"></i>
                    <span id="mutasiModalTitle">Detail Mutasi</span>
                </span>
                <div id="mutasiModalSub" style="font-size:9px; color:var(--text-muted); margin-top:2px;"></div>
            </div>
            <div style="display:inline-flex; align-items:center; gap:6px;">
                <button type="button" class="ant-btn ant-btn-sm" id="btnPopupExternal" onclick="openExternalMutasiWindow()" title="Buka di Jendela Baru (Pop-out)" style="height:22px; font-size:9.5px; padding:0 6px;">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Pop-out
                </button>
                <button type="button" class="ant-btn ant-btn-sm ant-btn-success" onclick="exportMutasiModalToExcel()" title="Export ke Excel" style="height:22px; font-size:9.5px; padding:0 6px;">
                    <i class="fa-solid fa-file-excel"></i> Excel
                </button>
                <button type="button" class="ant-btn ant-btn-sm" onclick="closeMutasiModal()" style="height:22px; font-size:11px; padding:0 6px; line-height:1;">✕</button>
            </div>
        </div>
        <div class="ant-modal-body" id="mutasiModalBody" style="padding:10px; overflow-y:auto; flex:1;">
            <div style="text-align:center; padding:30px; color:var(--text-muted);">
                <i class="fa-solid fa-spinner fa-spin" style="font-size:20px; color:var(--primary);"></i>
                <div style="margin-top:8px;">Memuat rincian breakdown mutasi...</div>
            </div>
        </div>
        <div class="ant-modal-footer" style="padding:6px 14px; background:var(--table-head-bg); border-top:1px solid var(--border-card); display:flex; justify-content:space-between; align-items:center;">
            <span id="mutasiModalCount" style="font-size:9.5px; color:var(--text-muted);"></span>
            <button type="button" class="ant-btn ant-btn-primary" onclick="closeMutasiModal()" style="height:22px; font-size:9.5px; padding:0 12px;">Tutup</button>
        </div>
    </div>
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
    // =========================================================================
    // FITUR INTERAKTIF TAB 1A & 1B: PEMASUKAN & PENGELUARAN (PowerBuilder TPB-ERP)
    // =========================================================================

    // Toggle Tampilan Grup (Client-side instant zero-reload toggle)
    function toggleGroupMode(isGrouped) {
        const input = document.getElementById('inputGroupMode');
        if (input) input.value = isGrouped ? '1' : '0';
        try { localStorage.setItem('it_group_mode', isGrouped ? '1' : '0'); } catch(e){}

        const rows = document.querySelectorAll('#mainDataTable tbody tr[data-row-idx]');
        rows.forEach(tr => {
            const isFirst = tr.getAttribute('data-is-first') === '1';
            const groupNo = tr.getAttribute('data-group-no');
            const rowNo   = tr.getAttribute('data-row-no');

            const noCell = tr.querySelector('.col-no');
            if (noCell) {
                noCell.innerText = isGrouped ? (isFirst ? groupNo : '') : rowNo;
            }

            const grpCells = tr.querySelectorAll('.col-grp');
            grpCells.forEach(td => {
                if (isGrouped && !isFirst) {
                    td.innerText = '';
                } else {
                    td.innerText = td.getAttribute('data-orig-text') || '';
                }
            });
        });
    }

    // Toggle Tampilkan No. AJU, Netto, Gross (Client-side instant toggle)
    function toggleAjuNettoGross(show) {
        const input = document.getElementById('inputShowExt');
        if (input) input.value = show ? '1' : '0';
        try { localStorage.setItem('it_show_ext', show ? '1' : '0'); } catch(e){}

        const extCols = document.querySelectorAll('.col-ext');
        extCols.forEach(el => {
            el.style.display = show ? '' : 'none';
        });
    }

    // Row selection highlight (Sky Blue)
    function selectReportRow(tr) {
        document.querySelectorAll('#mainDataTable tbody tr.row-selected').forEach(r => r.classList.remove('row-selected'));
        tr.classList.add('row-selected');
    }

    // Cetak ke PDF
    function printReport() {
        const prevTitle = document.title;
        const activeTab = '<?= $activeTab ?>';
        const sheetCode = (activeTab || "1A").toUpperCase();
        const tabWord = (sheetCode === '1B') ? 'Pengeluaran' : 'Pemasukan';
        const dateStr = '<?= htmlspecialchars($tgl1) ?>_sd_<?= htmlspecialchars($tgl2) ?>';
        document.title = "Laporan_" + sheetCode + "_" + tabWord + "_Barang_" + dateStr;
        window.print();
        setTimeout(() => { document.title = prevTitle; }, 1000);
    }

    // Export Table Tab 1A / 1B to Excel (.xlsx) using SheetJS
    function exportToExcelCustom(tabName) {
        if (typeof XLSX === 'undefined') {
            alert('Library Excel (SheetJS) belum selesai termuat. Silakan gunakan tombol CSV.');
            return;
        }
        const table = document.getElementById("mainDataTable");
        if (!table) return;

        // Clone table so original DOM is unaffected
        const clone = table.cloneNode(true);
        const showExt = document.getElementById('chkShowAjuNettoGross') ? document.getElementById('chkShowAjuNettoGross').checked : true;
        if (!showExt) {
            clone.querySelectorAll('.col-ext').forEach(el => el.remove());
        }

        // Hapus seluruh icon sort dan simbol panah dari header tabel
        clone.querySelectorAll('.sort-icon').forEach(el => el.remove());
        clone.querySelectorAll('th, td').forEach(cell => {
            if (cell.innerHTML) {
                cell.innerText = cell.innerText.replace(/[\u2195\u25B2\u25BC↕▲▼]/g, '').trim();
            }
        });

        // Sisipkan Judul Laporan & Periode Perusahaan di baris teratas Excel (Hanya tampil di Excel & PDF)
        const thead = clone.querySelector('thead');
        if (thead) {
            let colCount = 16;
            const firstRowCells = thead.querySelectorAll('tr:first-child > th');
            if (firstRowCells.length > 0) {
                let spanSum = 0;
                firstRowCells.forEach(th => {
                    if (th.style.display !== 'none') {
                        spanSum += parseInt(th.getAttribute('colspan') || '1', 10);
                    }
                });
                if (spanSum > 0) colCount = spanSum;
            }

            const trTitle = document.createElement('tr');
            const thTitle = document.createElement('th');
            thTitle.setAttribute('colspan', colCount);
            thTitle.style.textAlign = 'center';
            thTitle.style.fontWeight = 'bold';
            thTitle.style.fontSize = '12pt';
            thTitle.innerText = "<?= $reportBannerTitle ?>";
            trTitle.appendChild(thTitle);

            const trSub = document.createElement('tr');
            const thSub = document.createElement('th');
            thSub.setAttribute('colspan', colCount);
            thSub.style.textAlign = 'center';
            thSub.style.fontWeight = 'bold';
            thSub.style.fontSize = '10pt';
            thSub.innerText = "<?= htmlspecialchars($cmp_name, ENT_QUOTES) ?> - Periode : <?= htmlspecialchars($tgl1, ENT_QUOTES) ?> s/d <?= htmlspecialchars($tgl2, ENT_QUOTES) ?>";
            trSub.appendChild(thSub);

            const trBlank = document.createElement('tr');
            const thBlank = document.createElement('th');
            thBlank.setAttribute('colspan', colCount);
            thBlank.innerText = "";
            trBlank.appendChild(thBlank);

            thead.insertBefore(trBlank, thead.firstChild);
            thead.insertBefore(trSub, thead.firstChild);
            thead.insertBefore(trTitle, thead.firstChild);
        }

        const sheetCode = (tabName || "1A").toUpperCase();
        const ws = XLSX.utils.table_to_sheet(clone);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, "Laporan_" + sheetCode);

        const dateStr = new Date().toISOString().slice(0, 10);
        let tabWord = 'Pemasukan';
        if (sheetCode === '1B') tabWord = 'Pengeluaran';
        else if (sheetCode === '1D') tabWord = 'Pertanggungjawaban_Mutasi';
        const fileName = "Laporan_" + sheetCode + "_" + tabWord + "_Barang_" + dateStr + ".xlsx";
        XLSX.writeFile(wb, fileName);
    }
    function copyTableClipboard() {
        const table = document.getElementById("mainDataTable");
        let csv = [];
        for (let row of table.rows) {
            let cols = [];
            for (let cell of row.cells) {
                const text = cell.innerText.replace(/[\u2195\u25B2\u25BC↕▲▼]/g, '').replace(/"/g, '""').trim();
                cols.push('"' + text + '"');
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
        clonedTable.querySelectorAll('th, td').forEach(cell => {
            if (cell.innerHTML) {
                cell.innerText = cell.innerText.replace(/[\u2195\u25B2\u25BC↕▲▼]/g, '').trim();
            }
        });
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
        const activeTab = '<?= $activeTab ?>';
        // Tab 1A (Pemasukan) & 1B (Pengeluaran) menggunakan urutan terstruktur dari SQL (ORDER BY Dokumen & Tanggal)
        // Header 2-tingkat dibiarkan bersih tanpa panah sort (bebas simbol ↕).
        if (activeTab === '1a' || activeTab === '1b') {
            return;
        }
        const ths = table.querySelectorAll("thead tr:last-child th");
        ths.forEach((th, idx) => {
            if (idx === 0) return; // Skip "No" column
            th.classList.add("sortable-th");
            th.title = "Klik untuk mengurutkan (Sort)";
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
                const text = cell.innerText.replace(/[\u2195\u25B2\u25BC↕▲▼]/g, '').replace(/"/g, '""').trim();
                cols.push('"' + text + '"');
            }
            csv.push(cols.join(","));
        }
        const blob = new Blob([csv.join("\n")], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement("a");
        link.href = URL.createObjectURL(blob);
        link.download = "IT_Inventory_" + tabName.toUpperCase() + "_" + new Date().toISOString().slice(0,10) + ".csv";
        link.click();
    }

    // Detail Breakdown Mutasi (MASUK / KELUAR) Modal & Pop-out
    let currentMutasiParams = {};

    function openMutasiDetail(kode, ab, tgl1, tgl2, klas) {
        if (!kode) return;
        currentMutasiParams = { kode, ab, tgl1, tgl2, klas };
        
        const mask = document.getElementById('mutasiModalMask');
        const title = document.getElementById('mutasiModalTitle');
        const sub = document.getElementById('mutasiModalSub');
        const icon = document.getElementById('mutasiModalIcon');
        const body = document.getElementById('mutasiModalBody');
        const countBadge = document.getElementById('mutasiModalCount');

        const abLabel = (ab === 'A') ? 'MASUK' : 'KELUAR';
        const abColor = (ab === 'A') ? '#52c41a' : '#f5222d';
        const iconClass = (ab === 'A') ? 'fa-arrow-down-long' : 'fa-arrow-up-long';

        if (icon) {
            icon.className = `fa-solid ${iconClass}`;
            icon.style.color = abColor;
        }
        if (title) title.innerText = `Detail Mutasi ${abLabel} - ${kode}`;
        if (sub) sub.innerText = `Memuat rincian transaksi periode ${tgl1} s/d ${tgl2}...`;
        if (countBadge) countBadge.innerText = '';
        if (body) {
            body.innerHTML = `
                <div style="text-align:center; padding:30px; color:var(--text-muted);">
                    <i class="fa-solid fa-spinner fa-spin" style="font-size:20px; color:var(--primary);"></i>
                    <div style="margin-top:8px;">Memuat rincian breakdown transaksi mutasi...</div>
                </div>`;
        }
        if (mask) mask.style.display = 'flex';

        fetch(`it_inventory.php?action=get_mutasi_detail&kode=${encodeURIComponent(kode)}&ab=${encodeURIComponent(ab)}&awal=${encodeURIComponent(tgl1)}&akhir=${encodeURIComponent(tgl2)}&klas=${encodeURIComponent(klas)}&cmp_id=<?= urlencode($cmp_id) ?>`)
            .then(res => res.json())
            .then(res => {
                if (res.status !== 'success') {
                    body.innerHTML = `<p style="color:red; text-align:center;">Gagal memuat rincian: ${res.message || 'Error'}</p>`;
                    return;
                }
                if (title) title.innerText = `Detail Mutasi ${res.label_ab} - ${res.kode} ${res.uraian ? ' - ' + res.uraian : ''}`;
                if (sub) sub.innerText = `Periode: ${res.awal} s/d ${res.akhir} • Total Qty: ${Number(res.total_qty).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})} • ${res.count} Transaksi`;
                if (countBadge) countBadge.innerText = `Total Transaksi: ${res.count}`;

                let html = `
                <table class="ant-table" id="mutasiDetailTable" style="width:100%; border:1px solid var(--border-table); font-size:9.5px;">
                    <thead>
                        <tr>
                            <th style="width:30px; text-align:center;">No.</th>
                            <th style="text-align:center;">Kode Brg</th>
                            <th style="text-align:center;">Sat</th>
                            <th style="text-align:right;">Jumlah</th>
                            <th style="text-align:center;">Tgl Bukti</th>
                            <th>No. Bukti</th>
                            <th>${res.label_bs}</th>
                            <th style="text-align:center;">BC</th>
                            <th style="text-align:center;">Nomor Daftar</th>
                            <th style="text-align:center;">Tgl Daftar</th>
                            <th style="text-align:center;">Nomor AJU</th>
                            <th style="text-align:center;">Tgl Proses</th>
                        </tr>
                        <tr style="background:#ffe58f; color:#000; font-weight:700;">
                            <th colspan="3" style="text-align:center; background:#ffe58f !important; color:#000 !important;">${res.label_ab}</th>
                            <td style="text-align:right; font-weight:700; background:#ffe58f !important; color:#000 !important;">${Number(res.total_qty).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                            <th colspan="8" style="background:#ffe58f !important;"></th>
                        </tr>
                    </thead>
                    <tbody>`;

                if (res.data.length === 0) {
                    html += `<tr><td colspan="12" style="text-align:center; padding:16px; color:var(--text-muted);">Tidak ada transaksi rincian mutasi untuk barang ini.</td></tr>`;
                } else {
                    res.data.forEach((r, idx) => {
                        const no = idx + 1;
                        const jml = Number(r.JUMLAH || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
                        html += `
                        <tr>
                            <td style="text-align:center; color:var(--text-muted);">${no}</td>
                            <td style="font-family:monospace; font-weight:600; color:var(--primary);">${r.KODE_BARANG || '-'}</td>
                            <td style="text-align:center;">${r.SATUAN || '-'}</td>
                            <td style="text-align:right; font-weight:600;">${jml}</td>
                            <td style="text-align:center;">${r.TGL_BUKTI || '-'}</td>
                            <td>${r.NO_BUKTI || '-'}</td>
                            <td>${r.NAMA_BS || '-'}</td>
                            <td style="text-align:center;">${r.BC || '-'}</td>
                            <td style="text-align:center;">${r.NOMOR_DOK || '-'}</td>
                            <td style="text-align:center;">${r.TGL_DOK || '-'}</td>
                            <td style="text-align:center; font-family:monospace;">${r.NOMOR_AJU || '-'}</td>
                            <td style="text-align:center;">${r.TGL_PROSES || '-'}</td>
                        </tr>`;
                    });
                }

                html += `</tbody></table>`;
                body.innerHTML = html;
            })
            .catch(err => {
                body.innerHTML = `<p style="color:red; text-align:center;">Gagal menghubungi server: ${err}</p>`;
            });
    }

    function closeMutasiModal() {
        const mask = document.getElementById('mutasiModalMask');
        if (mask) mask.style.display = 'none';
    }

    function openExternalMutasiWindow() {
        if (!currentMutasiParams.kode) return;
        const { kode, ab, tgl1, tgl2, klas } = currentMutasiParams;
        const url = `mutasi.php?ab=${encodeURIComponent(ab)}&kode=${encodeURIComponent(kode)}&awal=${encodeURIComponent(tgl1)}&akhir=${encodeURIComponent(tgl2)}&klas=${encodeURIComponent(klas)}&cmp_id=<?= urlencode($cmp_id) ?>`;
        window.open(url, "popup2", "width=1100,height=500,scrollbars=yes,resizable=yes");
    }

    function exportMutasiModalToExcel() {
        const table = document.getElementById('mutasiDetailTable');
        if (!table) return;
        if (typeof XLSX === 'undefined') {
            alert('Library Excel belum siap.');
            return;
        }
        const ws = XLSX.utils.table_to_sheet(table);
        const wb = XLSX.utils.book_new();
        const sheetName = `Mutasi_${currentMutasiParams.ab === 'A' ? 'MASUK' : 'KELUAR'}`;
        XLSX.utils.book_append_sheet(wb, ws, sheetName);
        XLSX.writeFile(wb, `Detail_Mutasi_${currentMutasiParams.kode}_${currentMutasiParams.ab}.xlsx`);
    }

    // Toggle Sub-Klasifikasi Capital Goods
    function handleKlasChange(val) {
        const subContainer = document.getElementById('subKlasContainer');
        const subSelect = document.getElementById('subKlasSelect');
        if (val === 'Capital Goods') {
            if (subContainer) subContainer.style.display = 'inline-flex';
        } else {
            if (subContainer) subContainer.style.display = 'none';
            if (subSelect) subSelect.value = '%';
        }
        document.getElementById('filterForm1D').submit();
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
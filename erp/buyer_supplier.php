<?php
/**
 * ============================================================================
 * CEISA 4.0 & ERP Enterprise Hub - Master Buyer & Supplier (Mitra Bisnis)
 * ============================================================================
 * Acuan Asli PowerBuilder TPB-ERP: w_bs.srw, d_bs_grid.srd, d_bs_chg.srd
 * Manajemen Rekanan Bisnis (Supplier & Buyer), Validasi Coretax (NPWP 16 Digit & NITKU),
 * Fasilitas Kepabeanan TPB (Kawasan Berikat, Domestik/Lokal, Subkontrak), NIB Entitas,
 * Izin Fasilitas, serta Standardisasi Ant Design Compact Desktop 11px Identik Master Departemen.
 * ============================================================================
 */
session_start();
require_once __DIR__ . '/../datalogin.php';

if (!$koneksi) {
    die("Koneksi database terputus. Silakan muat ulang halaman.");
}

$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? ($cmp_id ?? ''));
if (!empty($_GET['cmp_id'])) {
    $sessionCmpId = trim($_GET['cmp_id']);
    $_SESSION['company_cmp_id'] = $sessionCmpId;
    $_SESSION['cmp_id'] = $sessionCmpId;
} elseif (!empty($_POST['cmp_id'])) {
    $sessionCmpId = trim($_POST['cmp_id']);
    $_SESSION['company_cmp_id'] = $sessionCmpId;
    $_SESSION['cmp_id'] = $sessionCmpId;
}
$escCmp = $koneksi->real_escape_string($sessionCmpId);
$isAdmin = !empty($_SESSION['is_admin']) || !empty($_SESSION['is_super_admin']);
$currentUser = $_SESSION['username'] ?? ($_SESSION['user_id'] ?? 'SYSTEM');

$activeCmpName = 'TPB ERP Enterprise';
if (!empty($sessionCmpId)) {
    $cmpQ = $koneksi->query("SELECT cmp_name FROM bc_ceisa_company WHERE cmp_id = '$escCmp' LIMIT 1");
    if ($cmpQ && $cRow = $cmpQ->fetch_assoc()) {
        $activeCmpName = $cRow['cmp_name'];
    }
}

// -------------------------------------------------------------------------
// AUTO-HEAL: Pastikan Struktur Tabel bc_bs Tersedia Lengkap Multi-Tenant
// -------------------------------------------------------------------------
function ensureBcBsTable($koneksi) {
    try {
        $check = $koneksi->query("SHOW TABLES LIKE 'bc_bs'");
        if (!$check || $check->num_rows === 0) {
            $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_bs` (
                `cmp_id` varchar(30) NOT NULL DEFAULT '',
                `KODE` varchar(30) NOT NULL DEFAULT '',
                `TIPE` varchar(5) NOT NULL DEFAULT 'B',
                `NAMA` varchar(255) NOT NULL DEFAULT '',
                `ALAMAT` text DEFAULT NULL,
                `NPWP` varchar(30) DEFAULT '',
                `KODE_KANTOR_TUJUAN` varchar(15) DEFAULT '',
                `NAMA_PENDEK` varchar(50) DEFAULT '',
                `KONTAK` varchar(100) DEFAULT '',
                `TELP` varchar(50) DEFAULT '',
                `FAX` varchar(50) DEFAULT '',
                `EMAIL` varchar(100) DEFAULT '',
                `DELI_TERM` varchar(20) DEFAULT 'FOB',
                `PAY_TERM` varchar(30) DEFAULT 'COD',
                `AKTIF` char(1) NOT NULL DEFAULT 'Y',
                `SET_BY` varchar(50) DEFAULT '',
                `kurs` varchar(5) DEFAULT 'IDR',
                `KB` char(1) DEFAULT 'Y',
                `LOKAL` char(1) DEFAULT 'Y',
                `SUBKON` char(1) DEFAULT 'N',
                `NEGARA` varchar(10) DEFAULT 'ID',
                `AKUN` varchar(30) DEFAULT '',
                `KODE_BANTU` varchar(30) DEFAULT '',
                `TIPE_SUBKON` char(1) DEFAULT '1',
                `PL_NOTE` varchar(255) DEFAULT '',
                `nib_entitas` varchar(50) DEFAULT '',
                `nomor_ijin_entitas` varchar(50) DEFAULT '',
                `tanggal_ijin_entitas` varchar(20) DEFAULT '',
                `nitku` varchar(35) DEFAULT '',
                `nonpkp` char(1) DEFAULT 'N',
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`cmp_id`, `KODE`),
                KEY `idx_tipe` (`cmp_id`, `TIPE`),
                KEY `idx_npwp` (`NPWP`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $cols = [];
            $res = $koneksi->query("SHOW COLUMNS FROM bc_bs");
            if ($res) {
                while ($r = $res->fetch_assoc()) $cols[strtolower($r['Field'])] = true;
            }
            if (!isset($cols['cmp_id'])) {
                @$koneksi->query("ALTER TABLE bc_bs ADD COLUMN `cmp_id` varchar(30) NOT NULL DEFAULT '' FIRST");
                @$koneksi->query("ALTER TABLE bc_bs ADD INDEX `idx_cmp_id` (`cmp_id`)");
            }
            if (!isset($cols['nitku'])) {
                @$koneksi->query("ALTER TABLE bc_bs ADD COLUMN `nitku` varchar(35) DEFAULT ''");
            }
            if (!isset($cols['nonpkp'])) {
                @$koneksi->query("ALTER TABLE bc_bs ADD COLUMN `nonpkp` char(1) DEFAULT 'N'");
            }
            if (!isset($cols['nib_entitas'])) {
                @$koneksi->query("ALTER TABLE bc_bs ADD COLUMN `nib_entitas` varchar(50) DEFAULT ''");
            }
            if (!isset($cols['nomor_ijin_entitas'])) {
                @$koneksi->query("ALTER TABLE bc_bs ADD COLUMN `nomor_ijin_entitas` varchar(50) DEFAULT ''");
            }
            if (!isset($cols['tanggal_ijin_entitas'])) {
                @$koneksi->query("ALTER TABLE bc_bs ADD COLUMN `tanggal_ijin_entitas` varchar(20) DEFAULT ''");
            }
            if (!isset($cols['aktif'])) {
                @$koneksi->query("ALTER TABLE bc_bs ADD COLUMN `AKTIF` char(1) NOT NULL DEFAULT 'Y'");
            }
            if (!isset($cols['nama_pendek'])) {
                @$koneksi->query("ALTER TABLE bc_bs ADD COLUMN `NAMA_PENDEK` varchar(50) DEFAULT ''");
            }
            if (!isset($cols['kurs'])) {
                @$koneksi->query("ALTER TABLE bc_bs ADD COLUMN `kurs` varchar(5) DEFAULT 'IDR'");
            }
            if (!isset($cols['kb'])) {
                @$koneksi->query("ALTER TABLE bc_bs ADD COLUMN `KB` char(1) DEFAULT 'Y'");
            }
            if (!isset($cols['lokal'])) {
                @$koneksi->query("ALTER TABLE bc_bs ADD COLUMN `LOKAL` char(1) DEFAULT 'Y'");
            }
            if (!isset($cols['subkon'])) {
                @$koneksi->query("ALTER TABLE bc_bs ADD COLUMN `SUBKON` char(1) DEFAULT 'N'");
            }
            if (!isset($cols['created_at'])) {
                @$koneksi->query("ALTER TABLE bc_bs ADD COLUMN `created_at` datetime DEFAULT CURRENT_TIMESTAMP");
            }
            if (!isset($cols['updated_at'])) {
                @$koneksi->query("ALTER TABLE bc_bs ADD COLUMN `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
            }
        }
    } catch (\Throwable $ex) {}
}
ensureBcBsTable($koneksi);

// Ambil referensi Termin & Kurs aktif untuk dropdown formulir
$allTerms = [];
try {
    $qT = $koneksi->query("SELECT kode, note, jatuhtempo FROM bc_ref_term WHERE (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL) AND (status = 1 OR status IS NULL) ORDER BY jatuhtempo ASC, kode ASC");
    if ($qT) while ($r = $qT->fetch_assoc()) $allTerms[] = $r;
} catch (\Throwable $e) {}

$allKurs = [];
try {
    $qK = $koneksi->query("SELECT nama, simbol FROM bc_ref_kurs WHERE (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL) AND (status = 1 OR status IS NULL) ORDER BY is_default DESC, nama ASC");
    if ($qK) while ($r = $qK->fetch_assoc()) $allKurs[] = $r;
} catch (\Throwable $e) {}

// =========================================================================
// AJAX BACKEND API HANDLERS
// =========================================================================
$action = $_REQUEST['action'] ?? '';

// 1. LIST DATA (PAGINATED, FILTERABLE, SEARCHABLE)
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $tipe      = trim($_GET['tipe'] ?? '');
        $search    = trim($_GET['search'] ?? '');
        $status    = trim($_GET['status'] ?? 'ALL');
        $fasilitas = trim($_GET['fasilitas'] ?? 'ALL');
        $page      = max(1, (int)($_GET['page'] ?? 1));
        $limit     = max(10, min(200, (int)($_GET['limit'] ?? 25)));
        $offset    = ($page - 1) * $limit;

        $whereClauses = [];
        if (!empty($sessionCmpId)) {
            $whereClauses[] = "(cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)";
        }

        if (!empty($tipe) && in_array($tipe, ['B', 'S'])) {
            $whereClauses[] = "TIPE = '$tipe'";
        }

        if ($status === 'Y') {
            $whereClauses[] = "(AKTIF = 'Y' OR AKTIF = '1' OR AKTIF IS NULL)";
        } elseif ($status === 'N') {
            $whereClauses[] = "(AKTIF = 'N' OR AKTIF = '0')";
        }

        if ($fasilitas === 'KB') {
            $whereClauses[] = "KB = 'Y'";
        } elseif ($fasilitas === 'LOKAL') {
            $whereClauses[] = "LOKAL = 'Y'";
        } elseif ($fasilitas === 'SUBKON') {
            $whereClauses[] = "SUBKON = 'Y'";
        } elseif ($fasilitas === 'NONPKP') {
            $whereClauses[] = "nonpkp = 'Y'";
        }

        if (!empty($search)) {
            $sEsc = $koneksi->real_escape_string($search);
            $whereClauses[] = "(KODE LIKE '%$sEsc%' OR NAMA LIKE '%$sEsc%' OR NAMA_PENDEK LIKE '%$sEsc%' OR NPWP LIKE '%$sEsc%' OR nitku LIKE '%$sEsc%' OR ALAMAT LIKE '%$sEsc%' OR KONTAK LIKE '%$sEsc%')";
        }

        $whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

        $countQ = $koneksi->query("SELECT COUNT(1) FROM bc_bs $whereSql");
        $total = (int)($countQ ? $countQ->fetch_row()[0] : 0);

        // Deteksi ketersediaan kolom updated_at secara defensif
        $hasColUpdated = false;
        $colQ = $koneksi->query("SHOW COLUMNS FROM bc_bs LIKE 'updated_at'");
        if ($colQ && $colQ->num_rows > 0) $hasColUpdated = true;
        $updatedSql = $hasColUpdated ? "updated_at" : "NOW() AS updated_at";

        $sql = "SELECT KODE, TIPE, NAMA, NAMA_PENDEK, ALAMAT, NPWP, nitku, nonpkp, 
                       KODE_KANTOR_TUJUAN, KONTAK, TELP, FAX, EMAIL, DELI_TERM, PAY_TERM, 
                       COALESCE(AKTIF, 'Y') as AKTIF, kurs, KB, LOKAL, SUBKON, NEGARA, 
                       nib_entitas, nomor_ijin_entitas, tanggal_ijin_entitas, $updatedSql
                FROM bc_bs 
                $whereSql 
                ORDER BY TIPE ASC, NAMA ASC 
                LIMIT $offset, $limit";
        $query = $koneksi->query($sql);
        $data = [];
        if ($query) {
            while ($r = $query->fetch_assoc()) {
                $updFormatted = '-';
                if (!empty($r['updated_at']) && $r['updated_at'] !== '-') {
                    $ts = strtotime($r['updated_at']);
                    if ($ts && $ts > 0) $updFormatted = date('d M Y H:i', $ts);
                }
                $data[] = [
                    'kode'          => $r['KODE'],
                    'tipe'          => $r['TIPE'],
                    'nama'          => $r['NAMA'],
                    'nama_pendek'   => $r['NAMA_PENDEK'] ?: '-',
                    'alamat'        => $r['ALAMAT'] ?: '-',
                    'npwp'          => $r['NPWP'] ?: '-',
                    'nitku'         => $r['nitku'] ?: '-',
                    'nonpkp'        => ($r['nonpkp'] === 'Y') ? 'Y' : 'N',
                    'kontak'        => $r['KONTAK'] ?: '-',
                    'telp'          => $r['TELP'] ?: '-',
                    'fax'           => $r['FAX'] ?: '-',
                    'email'         => $r['EMAIL'] ?: '-',
                    'deli_term'     => $r['DELI_TERM'] ?: 'FOB',
                    'pay_term'      => $r['PAY_TERM'] ?: 'COD',
                    'kurs'          => $r['kurs'] ?: 'IDR',
                    'kb'            => ($r['KB'] === 'Y') ? 'Y' : 'N',
                    'lokal'         => ($r['LOKAL'] === 'Y') ? 'Y' : 'N',
                    'subkon'        => ($r['SUBKON'] === 'Y') ? 'Y' : 'N',
                    'negara'        => $r['NEGARA'] ?: 'ID',
                    'kkt'           => $r['KODE_KANTOR_TUJUAN'] ?: '-',
                    'nib'           => $r['nib_entitas'] ?: '-',
                    'no_ijin'       => $r['nomor_ijin_entitas'] ?: '-',
                    'tgl_ijin'      => $r['tanggal_ijin_entitas'] ?: '-',
                    'aktif'         => ($r['AKTIF'] === 'N' || $r['AKTIF'] === '0') ? 'N' : 'Y',
                    'updated_at'    => $updFormatted
                ];
            }
        }

        // Mini KPI Summary
        $kpiWhere = !empty($sessionCmpId) ? "WHERE (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)" : "";
        $kpiQ = $koneksi->query("SELECT 
            COUNT(1) AS total,
            SUM(CASE WHEN TIPE = 'S' THEN 1 ELSE 0 END) AS total_supplier,
            SUM(CASE WHEN TIPE = 'B' THEN 1 ELSE 0 END) AS total_buyer,
            SUM(CASE WHEN KB = 'Y' THEN 1 ELSE 0 END) AS total_kb,
            SUM(CASE WHEN AKTIF = 'Y' OR AKTIF = '1' OR AKTIF IS NULL THEN 1 ELSE 0 END) AS total_aktif
            FROM bc_bs $kpiWhere");
        $kpi = [
            'total'          => 0,
            'total_supplier' => 0,
            'total_buyer'    => 0,
            'total_kb'       => 0,
            'total_aktif'    => 0
        ];
        if ($kpiQ && $kRow = $kpiQ->fetch_assoc()) {
            $kpi = [
                'total'          => (int)($kRow['total'] ?? 0),
                'total_supplier' => (int)($kRow['total_supplier'] ?? 0),
                'total_buyer'    => (int)($kRow['total_buyer'] ?? 0),
                'total_kb'       => (int)($kRow['total_kb'] ?? 0),
                'total_aktif'    => (int)($kRow['total_aktif'] ?? 0)
            ];
        }

        echo json_encode([
            'status'      => 'OK',
            'data'        => $data,
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => ceil($total / $limit),
            'kpi'         => $kpi
        ]);
    } catch (\Throwable $e) {
        echo json_encode([
            'status'  => 'ERROR',
            'message' => 'Gagal memuat data: ' . $e->getMessage()
        ]);
    }
    exit;
}

// 2. GET SINGLE RECORD (FOR EDIT)
if ($action === 'get') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $kode = trim($_GET['kode'] ?? '');
        if (empty($kode)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Kode mitra tidak valid']);
            exit;
        }
        $kEsc = $koneksi->real_escape_string($kode);
        $q = $koneksi->query("SELECT * FROM bc_bs WHERE KODE = '$kEsc' AND (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL) LIMIT 1");
        if ($q && $r = $q->fetch_assoc()) {
            echo json_encode(['status' => 'OK', 'data' => $r]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Data tidak ditemukan']);
        }
    } catch (\Throwable $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal mengambil data: ' . $e->getMessage()]);
    }
    exit;
}

// 3. SIMPAN DATA (INSERT / UPDATE)
if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $isEdit = !empty($_POST['is_edit']);
        $kode   = strtoupper(trim($_POST['kode'] ?? ''));
        $nama   = trim($_POST['nama'] ?? '');
        $tipe   = trim($_POST['tipe'] ?? 'B');

        if (empty($kode) || empty($nama)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Kode dan Nama Perusahaan wajib diisi!']);
            exit;
        }

        $namaPendek = trim($_POST['nama_pendek'] ?? '');
        $npwp       = preg_replace('/[^0-9]/', '', trim($_POST['npwp'] ?? ''));
        $nitku      = trim($_POST['nitku'] ?? '');
        $nonpkp     = ($_POST['nonpkp'] ?? 'N') === 'Y' ? 'Y' : 'N';
        $alamat     = trim($_POST['alamat'] ?? '');
        $negara     = strtoupper(trim($_POST['negara'] ?? 'ID'));
        $kkt        = trim($_POST['kkt'] ?? '');
        $kontak     = trim($_POST['kontak'] ?? '');
        $telp       = trim($_POST['telp'] ?? '');
        $fax        = trim($_POST['fax'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $deliTerm   = strtoupper(trim($_POST['deli_term'] ?? 'FOB'));
        $payTerm    = trim($_POST['pay_term'] ?? 'COD');
        $kurs       = strtoupper(trim($_POST['kurs'] ?? 'IDR'));
        $kb         = ($_POST['kb'] ?? 'N') === 'Y' ? 'Y' : 'N';
        $lokal      = ($_POST['lokal'] ?? 'N') === 'Y' ? 'Y' : 'N';
        $subkon     = ($_POST['subkon'] ?? 'N') === 'Y' ? 'Y' : 'N';
        $aktif      = ($_POST['aktif'] ?? 'Y') === 'N' ? 'N' : 'Y';
        $nib        = trim($_POST['nib_entitas'] ?? '');
        $noIjin     = trim($_POST['nomor_ijin_entitas'] ?? '');
        $tglIjin    = trim($_POST['tanggal_ijin_entitas'] ?? '');
        $setby      = $currentUser . ' - ' . date('Ymd H:i');

        $cmpIdVal = $sessionCmpId ?: '';

        if ($isEdit) {
            $stmt = $koneksi->prepare("UPDATE bc_bs SET 
                TIPE = ?, NAMA = ?, NAMA_PENDEK = ?, ALAMAT = ?, NPWP = ?, nitku = ?, nonpkp = ?,
                KODE_KANTOR_TUJUAN = ?, KONTAK = ?, TELP = ?, FAX = ?, EMAIL = ?, DELI_TERM = ?, 
                PAY_TERM = ?, kurs = ?, KB = ?, LOKAL = ?, SUBKON = ?, NEGARA = ?, 
                nib_entitas = ?, nomor_ijin_entitas = ?, tanggal_ijin_entitas = ?, AKTIF = ?, SET_BY = ?
                WHERE KODE = ? AND (cmp_id = ? OR cmp_id = '' OR cmp_id IS NULL)");
            $stmt->bind_param("ssssssssssssssssssssssssss",
                $tipe, $nama, $namaPendek, $alamat, $npwp, $nitku, $nonpkp,
                $kkt, $kontak, $telp, $fax, $email, $deliTerm,
                $payTerm, $kurs, $kb, $lokal, $subkon, $negara,
                $nib, $noIjin, $tglIjin, $aktif, $setby,
                $kode, $cmpIdVal
            );
            if ($stmt->execute()) {
                $stmt->close();
                echo json_encode(['status' => 'OK', 'message' => "Mitra bisnis $nama berhasil diperbarui."]);
            } else {
                $err = $stmt->error;
                $stmt->close();
                echo json_encode(['status' => 'ERROR', 'message' => 'Gagal memperbarui: ' . $err]);
            }
        } else {
            $chk = $koneksi->query("SELECT 1 FROM bc_bs WHERE KODE = '" . $koneksi->real_escape_string($kode) . "' AND cmp_id = '" . $koneksi->real_escape_string($cmpIdVal) . "' LIMIT 1");
            if ($chk && $chk->num_rows > 0) {
                echo json_encode(['status' => 'ERROR', 'message' => "Kode Mitra '$kode' sudah terdaftar pada perusahaan ini."]);
                exit;
            }

            $stmt = $koneksi->prepare("INSERT INTO bc_bs (
                cmp_id, KODE, TIPE, NAMA, NAMA_PENDEK, ALAMAT, NPWP, nitku, nonpkp,
                KODE_KANTOR_TUJUAN, KONTAK, TELP, FAX, EMAIL, DELI_TERM, PAY_TERM, 
                kurs, KB, LOKAL, SUBKON, NEGARA, nib_entitas, nomor_ijin_entitas, tanggal_ijin_entitas, AKTIF, SET_BY
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssssssssssssssssssssss",
                $cmpIdVal, $kode, $tipe, $nama, $namaPendek, $alamat, $npwp, $nitku, $nonpkp,
                $kkt, $kontak, $telp, $fax, $email, $deliTerm, $payTerm,
                $kurs, $kb, $lokal, $subkon, $negara, $nib, $noIjin, $tglIjin, $aktif, $setby
            );
            if ($stmt->execute()) {
                $stmt->close();
                echo json_encode(['status' => 'OK', 'message' => "Mitra bisnis $nama berhasil ditambahkan."]);
            } else {
                $err = $stmt->error;
                $stmt->close();
                echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyimpan: ' . $err]);
            }
        }
    } catch (\Throwable $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyimpan: ' . $e->getMessage()]);
    }
    exit;
}

// 4. TOGGLE STATUS AKTIF / NON-AKTIF
if ($action === 'toggle_status') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $kode = trim($_POST['kode'] ?? '');
        if (empty($kode)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Kode mitra tidak valid']);
            exit;
        }
        $kEsc = $koneksi->real_escape_string($kode);
        $upd = $koneksi->query("UPDATE bc_bs SET AKTIF = IF(AKTIF='Y', 'N', 'Y'), SET_BY = '$currentUser - " . date('Y-m-d H:i') . "' 
                                WHERE KODE = '$kEsc' AND (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)");
        if ($upd) {
            echo json_encode(['status' => 'OK', 'message' => 'Status aktif mitra bisnis berhasil diubah']);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal mengubah status: ' . $koneksi->error]);
        }
    } catch (\Throwable $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal mengubah status: ' . $e->getMessage()]);
    }
    exit;
}

// 5. HAPUS DATA
if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $kode = trim($_POST['kode'] ?? '');
        if (empty($kode)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Kode mitra tidak valid']);
            exit;
        }
        $kEsc = $koneksi->real_escape_string($kode);
        $cmpFilter = !empty($sessionCmpId) ? "AND (cmp_id = '$escCmp' OR cmp_id = '')" : "";
        
        // Periksa relasi transaksi PO / SO
        $checkPo = $koneksi->query("SELECT 1 FROM bc_po_hdr WHERE vendor = '$kEsc' $cmpFilter LIMIT 1");
        if ($checkPo && $checkPo->num_rows > 0) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Tidak dapat dihapus: Mitra ini sudah memiliki riwayat Purchase Order (PO).']);
            exit;
        }
        $checkSo = $koneksi->query("SELECT 1 FROM bc_so_hdr WHERE customer = '$kEsc' $cmpFilter LIMIT 1");
        if ($checkSo && $checkSo->num_rows > 0) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Tidak dapat dihapus: Mitra ini sudah memiliki riwayat Sales Order (SO).']);
            exit;
        }

        $del = $koneksi->query("DELETE FROM bc_bs WHERE KODE = '$kEsc' $cmpFilter LIMIT 1");
        if ($del) {
            echo json_encode(['status' => 'OK', 'message' => 'Data mitra berhasil dihapus.']);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menghapus: ' . $koneksi->error]);
        }
    } catch (\Throwable $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menghapus: ' . $e->getMessage()]);
    }
    exit;
}

// 6. EXPORT CSV
if ($action === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Master_Buyer_Supplier_' . date('Ymd_His') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['No', 'Kode', 'Tipe', 'Nama Perusahaan', 'Nama Pendek', 'NPWP', 'NITKU', 'Status PKP', 'Alamat', 'Negara', 'Kontak (PIC)', 'Telepon', 'Email', 'Fasilitas KB', 'Lokal', 'Subkontrak', 'Delivery Term', 'Payment Term', 'Valuta', 'NIB', 'No Izin Fasilitas', 'Status']);
    $w = !empty($sessionCmpId) ? "WHERE (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)" : "";
    $q = $koneksi->query("SELECT * FROM bc_bs $w ORDER BY TIPE ASC, NAMA ASC");
    $no = 1;
    if ($q) {
        while ($r = $q->fetch_assoc()) {
            fputcsv($output, [
                $no++,
                $r['KODE'],
                ($r['TIPE'] === 'S' ? 'Supplier' : 'Buyer'),
                $r['NAMA'],
                $r['NAMA_PENDEK'],
                $r['NPWP'],
                $r['nitku'],
                ($r['nonpkp'] === 'Y' ? 'Non-PKP' : 'PKP'),
                $r['ALAMAT'],
                $r['NEGARA'],
                $r['KONTAK'],
                $r['TELP'],
                $r['EMAIL'],
                $r['KB'],
                $r['LOKAL'],
                $r['SUBKON'],
                $r['DELI_TERM'],
                $r['PAY_TERM'],
                $r['kurs'],
                $r['nib_entitas'],
                $r['nomor_ijin_entitas'],
                ($r['AKTIF'] === 'Y' ? 'Aktif' : 'Non-Aktif')
            ]);
        }
    }
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Buyer &amp; Supplier - TPB ERP Enterprise</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/ant_grid.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.sheetjs.com/xlsx-latest/package/dist/xlsx.full.min.js"></script>
    <script src="/assets/ant_grid.js"></script>
    <style>
        :root {
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --primary-active: #096dd9;
            --success: #52c41a;
            --warning: #faad14;
            --danger: #ff4d4f;
            --bg: #f0f2f5;
            --card-bg: #ffffff;
            --border-color: #f0f0f0;
            --border-dark: #d9d9d9;
            --text-main: #262626;
            --text-muted: #8c8c8c;
            --row-hover: #fafafa;
        }
        body.dark-mode {
            --bg: #141414;
            --card-bg: #1f1f1f;
            --border-color: #303030;
            --border-dark: #434343;
            --text-main: #d9d9d9;
            --text-muted: #8c8c8c;
            --row-hover: #262626;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body {
            height: 100%;
            font-family: 'Poppins', 'Source Sans Pro', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 10.5px;
            background: var(--bg);
            color: var(--text-main);
            overflow: hidden;
            -webkit-font-smoothing: antialiased;
        }
        input, select, textarea, button {
            font-family: inherit;
        }
        .ant-container {
            display: flex;
            flex-direction: column;
            height: 100vh;
            padding: 8px 12px;
            gap: 8px;
        }
        .ant-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .ant-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 12px;
            border-bottom: 1px solid var(--border-color);
            background: var(--card-bg);
            min-height: 36px;
        }
        .ant-card-title {
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .ant-badge-company {
            background: #e6f7ff;
            color: #1890ff;
            border: 1px solid #91d5ff;
            padding: 1px 6px;
            border-radius: 2px;
            font-size: 10px;
            font-weight: 500;
        }
        body.dark-mode .ant-badge-company {
            background: #111d2c;
            color: #177ddc;
            border-color: #15395b;
        }

        /* KPI Strip */
        .kpi-row {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 8px;
            padding: 8px 12px;
            background: var(--card-bg);
            border-bottom: 1px solid var(--border-color);
        }
        .kpi-card {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 4px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            cursor: pointer;
            transition: all 0.2s;
        }
        .kpi-card:hover {
            border-color: var(--primary);
            background: var(--row-hover);
        }
        .kpi-icon {
            width: 28px;
            height: 28px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
        }
        .kpi-num {
            font-size: 14px;
            font-weight: 700;
            line-height: 1.1;
        }
        .kpi-label {
            font-size: 9.5px;
            color: var(--text-muted);
        }

        /* Tabs Navigasi (Identik Department & Tax Rate) */
        .ant-tabs-nav {
            display: flex;
            align-items: center;
            border-bottom: 1px solid var(--border-color);
            background: var(--card-bg);
            padding: 0 12px;
            gap: 4px;
        }
        .ant-tab-btn {
            height: 30px;
            padding: 0 12px;
            font-size: 11px;
            font-weight: 500;
            border: none;
            background: transparent;
            color: var(--text-muted);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
        }
        .ant-tab-btn:hover {
            color: var(--primary);
        }
        .ant-tab-btn.active {
            color: var(--primary);
            font-weight: 600;
            border-bottom-color: var(--primary);
        }

        /* Toolbar */
        .ant-toolbar {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-bottom: 1px solid var(--border-color);
            background: var(--card-bg);
            flex-wrap: wrap;
        }
        .ant-btn {
            height: 24px;
            padding: 0 8px;
            font-size: 11px;
            border-radius: 3px;
            border: 1px solid var(--border-dark);
            background: var(--card-bg);
            color: var(--text-main);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s;
            outline: none;
            white-space: nowrap;
        }
        .ant-btn:hover {
            color: var(--primary);
            border-color: var(--primary);
        }
        .ant-btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }
        .ant-btn-primary:hover {
            background: var(--primary-hover);
            border-color: var(--primary-hover);
            color: #fff;
        }
        .ant-btn-sm {
            height: 22px;
            padding: 0 6px;
            font-size: 10px;
        }
        .ant-btn-danger {
            color: var(--danger);
            border-color: #ffa39e;
            background: #fff1f0;
        }
        .ant-btn-danger:hover {
            background: var(--danger);
            color: #fff;
        }
        body.dark-mode .ant-btn-danger {
            background: #2a1215;
            border-color: #5c2223;
        }

        /* Grid DataWindow Style */
        .grid-wrap {
            flex: 1;
            overflow: auto;
            position: relative;
            background: var(--card-bg);
        }
        table.dw {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
            table-layout: fixed;
        }
        table.dw th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #fafafa;
            color: var(--text-main);
            font-weight: 600;
            font-size: 9.5px;
            padding: 4px 6px;
            border-bottom: 1px solid var(--border-dark);
            border-right: 1px solid var(--border-color);
            text-align: left;
            white-space: nowrap;
        }
        body.dark-mode table.dw th {
            background: #1d1d1d;
        }
        table.dw td {
            padding: 3px 6px;
            font-size: 10px;
            border-bottom: 1px solid var(--border-color);
            border-right: 1px solid var(--border-color);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            vertical-align: middle;
        }
        table.dw tr:hover td {
            background: var(--row-hover);
        }
        .th-highlight {
            background: #e6f7ff !important;
            color: #0050b3 !important;
        }
        body.dark-mode .th-highlight {
            background: #111d2c !important;
            color: #177ddc !important;
        }

        /* Badges & Tags */
        .badge-code {
            display: inline-block;
            font-family: "JetBrains Mono", Consolas, monospace;
            font-size: 10.5px;
            font-weight: 600;
            padding: 1px 6px;
            border-radius: 3px;
            background: #f5f5f5;
            color: #333;
            border: 1px solid #d9d9d9;
        }
        body.dark-mode .badge-code {
            background: #262626;
            color: #d9d9d9;
            border-color: #434343;
        }
        .tag-supplier {
            background: #f6ffed;
            color: #389e0d;
            border: 1px solid #b7eb8f;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 600;
        }
        .tag-buyer {
            background: #e6f7ff;
            color: #096dd9;
            border: 1px solid #91d5ff;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 600;
        }
        .tag-facility {
            display: inline-block;
            padding: 1px 4px;
            border-radius: 2px;
            font-size: 9.5px;
            font-weight: 500;
            margin-right: 2px;
        }
        .tag-kb { background: #fff7e6; color: #d46b08; border: 1px solid #ffd591; }
        .tag-lokal { background: #f0f5ff; color: #2f54eb; border: 1px solid #adc6ff; }
        .tag-subkon { background: #f9f0ff; color: #722ed1; border: 1px solid #d3adf7; }
        .tag-nonpkp { background: #fff1f0; color: #cf1322; border: 1px solid #ffa39e; }

        /* Switch Toggle */
        .ant-switch {
            position: relative;
            display: inline-block;
            width: 28px;
            height: 16px;
            vertical-align: middle;
            cursor: pointer;
        }
        .ant-switch input { opacity: 0; width: 0; height: 0; }
        .ant-slider {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #bfbfbf;
            transition: .2s;
            border-radius: 16px;
        }
        .ant-slider:before {
            position: absolute;
            content: "";
            height: 12px;
            width: 12px;
            left: 2px;
            bottom: 2px;
            background-color: white;
            transition: .2s;
            border-radius: 50%;
        }
        input:checked + .ant-slider { background-color: var(--primary); }
        input:checked + .ant-slider:before { transform: translateX(12px); }

        /* Card Footer */
        .ant-card-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 4px 12px;
            border-top: 1px solid var(--border-color);
            background: var(--card-bg);
            font-size: 10px;
            color: var(--text-muted);
            min-height: 30px;
        }
        .inline-box {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* Modal Dialog */
        .ant-modal-backdrop {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.45);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .ant-modal-backdrop.active {
            display: flex;
        }
        .ant-modal {
            background: var(--card-bg);
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            width: 94%;
            max-width: 680px;
            max-height: 92vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: modalFadeIn 0.2s ease-out;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.96); }
            to { opacity: 1; transform: scale(1); }
        }
        .ant-modal-header {
            padding: 8px 12px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 12px;
            font-weight: 600;
            background: var(--card-bg);
        }
        .ant-modal-body {
            padding: 12px;
            overflow-y: auto;
            flex: 1;
        }
        .ant-modal-footer {
            padding: 8px 12px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 6px;
            background: var(--card-bg);
        }
        .form-section-title {
            font-size: 11px;
            font-weight: 700;
            color: var(--primary);
            border-bottom: 1px dashed var(--border-color);
            padding-bottom: 3px;
            margin: 10px 0 8px 0;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .form-section-title:first-child {
            margin-top: 0;
        }
        .form-group {
            margin-bottom: 8px;
        }
        .form-group label {
            display: block;
            font-size: 10.5px;
            font-weight: 500;
            margin-bottom: 3px;
            color: var(--text-main);
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        .form-row-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8px;
        }
        .ant-input, .ant-select, .ant-textarea {
            width: 100%;
            height: 24px;
            padding: 0 6px;
            font-size: 11px;
            border: 1px solid var(--border-dark);
            border-radius: 3px;
            background: var(--card-bg);
            color: var(--text-main);
            outline: none;
        }
        .ant-textarea {
            height: 44px;
            padding: 4px 6px;
            resize: vertical;
        }
        .ant-input:focus, .ant-select:focus, .ant-textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(24,144,255,0.2);
        }
    </style>
</head>
<body>
<div class="ant-container">
    <div class="ant-card">
        <!-- Card Header -->
        <div class="ant-card-head">
            <div class="ant-card-title">
                <i class="fa-solid fa-handshake-simple" style="color:var(--primary);"></i>
                <span>Master Buyer &amp; Supplier (Mitra Bisnis)</span>
                <span class="ant-badge-company"><?= htmlspecialchars($activeCmpName) ?></span>
            </div>
            <div class="inline-box">
                <span style="font-size:10px; color:var(--text-muted);"><i class="fa-solid fa-circle-check" style="color:#52c41a;"></i> Acuan Asli PB: w_bs / d_bs_grid</span>
                <button type="button" class="ant-btn ant-btn-sm" onclick="openModuleGuide()" title="Panduan Modul &amp; SOP"><i class="fa-solid fa-circle-question" style="color:var(--primary); font-size:13px;"></i></button>
            </div>
        </div>

        <!-- Mini Dashboard KPI Strip -->
        <div class="kpi-row">
            <div class="kpi-card" onclick="filterByTab('')">
                <div class="kpi-icon" style="background:rgba(24,144,255,0.12); color:var(--primary);"><i class="fa-solid fa-users"></i></div>
                <div>
                    <div class="kpi-num" id="kpiTotal">0</div>
                    <div class="kpi-label">Total Mitra Terdaftar</div>
                </div>
            </div>
            <div class="kpi-card" onclick="filterByTab('S')">
                <div class="kpi-icon" style="background:rgba(82,196,26,0.12); color:var(--success);"><i class="fa-solid fa-truck-ramp-box"></i></div>
                <div>
                    <div class="kpi-num" id="kpiSupplier">0</div>
                    <div class="kpi-label">Supplier / Vendor</div>
                </div>
            </div>
            <div class="kpi-card" onclick="filterByTab('B')">
                <div class="kpi-icon" style="background:rgba(24,144,255,0.12); color:var(--primary);"><i class="fa-solid fa-user-tag"></i></div>
                <div>
                    <div class="kpi-num" id="kpiBuyer">0</div>
                    <div class="kpi-label">Buyer / Pelanggan</div>
                </div>
            </div>
            <div class="kpi-card" onclick="filterByFasilitas('KB')">
                <div class="kpi-icon" style="background:rgba(250,173,20,0.12); color:var(--warning);"><i class="fa-solid fa-shield-halved"></i></div>
                <div>
                    <div class="kpi-num" id="kpiKb">0</div>
                    <div class="kpi-label">Fasilitas TPB / KB</div>
                </div>
            </div>
            <div class="kpi-card" onclick="filterByStatus('Y')">
                <div class="kpi-icon" style="background:rgba(82,196,26,0.12); color:var(--success);"><i class="fa-solid fa-circle-check"></i></div>
                <div>
                    <div class="kpi-num" id="kpiAktif">0</div>
                    <div class="kpi-label">Mitra Aktif Operasional</div>
                </div>
            </div>
        </div>

        <!-- Tabs Navigasi Filter Cepat -->
        <div class="ant-tabs-nav">
            <button type="button" class="ant-tab-btn active" id="tabBtnAll" onclick="filterByTab('')"><i class="fa-solid fa-users"></i> 1. Semua Mitra Bisnis</button>
            <button type="button" class="ant-tab-btn" id="tabBtnSup" onclick="filterByTab('S')"><i class="fa-solid fa-truck-ramp-box"></i> 2. Supplier / Vendor (S)</button>
            <button type="button" class="ant-tab-btn" id="tabBtnBuy" onclick="filterByTab('B')"><i class="fa-solid fa-user-tag"></i> 3. Buyer / Pelanggan (B)</button>
            <button type="button" class="ant-tab-btn" id="tabBtnKb" onclick="filterByFasilitas('KB')"><i class="fa-solid fa-shield-halved"></i> 4. Kawasan Berikat (KB)</button>
            <button type="button" class="ant-tab-btn" id="tabBtnSubkon" onclick="filterByFasilitas('SUBKON')"><i class="fa-solid fa-arrows-split-up-and-left"></i> 5. Subkontrak</button>
        </div>

        <!-- Toolbar -->
        <div class="ant-toolbar">
            <button type="button" class="ant-btn ant-btn-primary" onclick="openAddModal()"><i class="fa-solid fa-plus"></i> Tambah Mitra Bisnis</button>
            <div style="height:14px; width:1px; background:var(--border-color); margin:0 4px;"></div>
            <label style="font-size:10.5px; color:var(--text-muted);">Tipe:</label>
            <select id="filterTipe" class="ant-select" style="width:115px; height:24px;" onchange="loadList(1)">
                <option value="">Semua Tipe</option>
                <option value="S">Supplier (S)</option>
                <option value="B">Buyer (B)</option>
            </select>
            <label style="font-size:10.5px; color:var(--text-muted); margin-left:4px;">Fasilitas:</label>
            <select id="filterFasilitas" class="ant-select" style="width:130px; height:24px;" onchange="loadList(1)">
                <option value="ALL">Semua Fasilitas</option>
                <option value="KB">Kawasan Berikat (KB)</option>
                <option value="LOKAL">Domestik / Lokal</option>
                <option value="SUBKON">Subkontrak</option>
                <option value="NONPKP">Non-PKP / UMKM</option>
            </select>
            <label style="font-size:10.5px; color:var(--text-muted); margin-left:4px;">Status:</label>
            <select id="filterStatus" class="ant-select" style="width:95px; height:24px;" onchange="loadList(1)">
                <option value="ALL">Semua</option>
                <option value="Y" selected>Hanya Aktif</option>
                <option value="N">Non-Aktif</option>
            </select>
            <input type="text" id="searchKeyword" class="ant-input" placeholder="Cari Kode, Nama, NPWP, NITKU, Alamat..." style="width:200px;" onkeydown="if(event.key==='Enter') loadList(1)">
            <button type="button" class="ant-btn" onclick="loadList(1)"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
            <div style="margin-left:auto; display:flex; gap:4px;">
                <button type="button" class="ant-btn" onclick="exportExcel()"><i class="fa-solid fa-file-excel" style="color:#52c41a;"></i> Export Excel</button>
                <button type="button" class="ant-btn" onclick="exportCsv()"><i class="fa-solid fa-file-csv"></i> CSV</button>
                <button type="button" class="ant-btn" onclick="loadList(currentPage)"><i class="fa-solid fa-rotate"></i> Refresh</button>
            </div>
        </div>

        <!-- Table Grid DataWindow Style -->
        <div class="grid-wrap">
            <table class="dw" id="tblBs">
                <thead>
                    <tr>
                        <th style="width:34px; text-align:center;">#</th>
                        <th class="th-highlight" style="width:95px;">Kode Mitra</th>
                        <th style="width:75px; text-align:center;">Tipe</th>
                        <th style="width:220px;">Nama Perusahaan / Alias</th>
                        <th style="width:145px;">NPWP &amp; NITKU (16 Dig)</th>
                        <th>Alamat Domisili &amp; Negara</th>
                        <th style="width:125px; text-align:center;">Fasilitas Pabean</th>
                        <th style="width:105px; text-align:center;">Termin &amp; Kurs</th>
                        <th style="width:65px; text-align:center;">Status</th>
                        <th style="width:105px; text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="tbodyBs">
                    <tr><td colspan="10" style="text-align:center; padding:16px; color:var(--text-muted);">Memuat data mitra bisnis...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Card Footer -->
        <div class="ant-card-footer">
            <span id="pageInfo">Menampilkan 0 data</span>
            <div class="inline-box" id="pageBtns"></div>
        </div>
    </div>
</div>

<!-- Modal Form Tambah / Edit Mitra Bisnis -->
<div class="ant-modal-backdrop" id="modalForm">
    <div class="ant-modal">
        <div class="ant-modal-header">
            <span id="modalFormTitle"><i class="fa-solid fa-pen-to-square"></i> Tambah Mitra Bisnis</span>
            <i class="fa-solid fa-xmark" style="cursor:pointer;" onclick="closeModal()"></i>
        </div>
        <div class="ant-modal-body">
            <input type="hidden" id="formIsEdit" value="0">
            
            <!-- Section 1: Identitas Dasar -->
            <div class="form-section-title"><i class="fa-solid fa-id-card"></i> 1. Identitas &amp; Klasifikasi Perusahaan</div>
            <div class="form-row-3">
                <div class="form-group">
                    <label>Kode Mitra: <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="formKode" class="ant-input" placeholder="Wajib unik..." maxlength="30" style="text-transform:uppercase;">
                </div>
                <div class="form-group">
                    <label>Tipe Mitra Bisnis: <span style="color:var(--danger)">*</span></label>
                    <select id="formTipe" class="ant-select">
                        <option value="S">Supplier / Vendor (S)</option>
                        <option value="B">Buyer / Pelanggan (B)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Nama Pendek / Alias:</label>
                    <input type="text" id="formNamaPendek" class="ant-input" placeholder="Alias singkat..." maxlength="50" style="text-transform:uppercase;">
                </div>
            </div>
            <div class="form-group">
                <label>Nama Resmi Perusahaan (Sesuai Akta / NIB): <span style="color:var(--danger)">*</span></label>
                <input type="text" id="formNama" class="ant-input" placeholder="Contoh: PT. INDO GLOBAL JAYA..." maxlength="255" style="text-transform:uppercase;">
            </div>

            <!-- Section 2: Legalitas & Perpajakan (Coretax Ready) -->
            <div class="form-section-title"><i class="fa-solid fa-file-invoice"></i> 2. Legalitas, Pajak &amp; Coretax DJP</div>
            <div class="form-row-3">
                <div class="form-group">
                    <label>NPWP (15 / 16 Digit):</label>
                    <input type="text" id="formNpwp" class="ant-input" placeholder="00.000.000.0-000.000" maxlength="30">
                </div>
                <div class="form-group">
                    <label>NITKU (Nomor Identitas Cabang):</label>
                    <input type="text" id="formNitku" class="ant-input" placeholder="22 Digit Coretax..." maxlength="35">
                </div>
                <div class="form-group">
                    <label>Status Pengusaha Kena Pajak:</label>
                    <select id="formNonpkp" class="ant-select">
                        <option value="N">PKP (Pengusaha Kena Pajak)</option>
                        <option value="Y">Non-PKP / UMKM Bebas PPN</option>
                    </select>
                </div>
            </div>
            <div class="form-row-3">
                <div class="form-group">
                    <label>Nomor Induk Berusaha (NIB):</label>
                    <input type="text" id="formNib" class="ant-input" placeholder="13 digit NIB..." maxlength="50">
                </div>
                <div class="form-group">
                    <label>Nomor Izin Fasilitas TPB/KB:</label>
                    <input type="text" id="formNoIjin" class="ant-input" placeholder="KMK / SKEP TPB..." maxlength="50">
                </div>
                <div class="form-group">
                    <label>Tanggal Izin Fasilitas:</label>
                    <input type="date" id="formTglIjin" class="ant-input">
                </div>
            </div>

            <!-- Section 3: Kontak & Alamat Domisili -->
            <div class="form-section-title"><i class="fa-solid fa-location-dot"></i> 3. Alamat Domisili &amp; Kontak Operasional</div>
            <div class="form-group">
                <label>Alamat Lengkap Perusahaan:</label>
                <textarea id="formAlamat" class="ant-textarea" placeholder="Alamat pabrik / kantor pusat..."></textarea>
            </div>
            <div class="form-row-3">
                <div class="form-group">
                    <label>Negara Domisili:</label>
                    <input type="text" id="formNegara" class="ant-input" value="ID" placeholder="Kode Negara (ID, US, SG...)" maxlength="10" style="text-transform:uppercase;">
                </div>
                <div class="form-group">
                    <label>Kantor Pabean Tujuan (KKT):</label>
                    <input type="text" id="formKkt" class="ant-input" placeholder="Contoh: 050100..." maxlength="15">
                </div>
                <div class="form-group">
                    <label>Kontak Person (PIC):</label>
                    <input type="text" id="formKontak" class="ant-input" placeholder="Nama PIC..." maxlength="100">
                </div>
            </div>
            <div class="form-row-3">
                <div class="form-group">
                    <label>Nomor Telepon:</label>
                    <input type="text" id="formTelp" class="ant-input" placeholder="021-..." maxlength="50">
                </div>
                <div class="form-group">
                    <label>Nomor Fax:</label>
                    <input type="text" id="formFax" class="ant-input" placeholder="Fax..." maxlength="50">
                </div>
                <div class="form-group">
                    <label>Alamat Email:</label>
                    <input type="email" id="formEmail" class="ant-input" placeholder="billing@perusahaan.com..." maxlength="100">
                </div>
            </div>

            <!-- Section 4: Syarat Transaksi ERP & Fasilitas Kepabeanan -->
            <div class="form-section-title"><i class="fa-solid fa-sliders"></i> 4. Ketentuan Transaksi ERP &amp; Fasilitas Kepabeanan</div>
            <div class="form-row-3">
                <div class="form-group">
                    <label>Delivery Term (Incoterms):</label>
                    <select id="formDeliTerm" class="ant-select">
                        <option value="FOB">FOB - Free On Board</option>
                        <option value="CIF">CIF - Cost, Insurance, Freight</option>
                        <option value="EXW">EXW - Ex Works</option>
                        <option value="CFR">CFR - Cost and Freight</option>
                        <option value="DDP">DDP - Delivered Duty Paid</option>
                        <option value="CIP">CIP - Carriage and Insurance Paid</option>
                        <option value="DAT">DAT - Delivered at Terminal</option>
                        <option value="DAP">DAP - Delivered at Place</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Termin Pembayaran:</label>
                    <select id="formPayTerm" class="ant-select">
                        <?php foreach ($allTerms as $t): ?>
                        <option value="<?= htmlspecialchars($t['kode']) ?>"><?= htmlspecialchars($t['kode']) ?> (<?= $t['jatuhtempo'] ?> Hari)</option>
                        <?php endforeach; ?>
                        <?php if (empty($allTerms)): ?>
                        <option value="COD">COD (Tunai)</option>
                        <option value="NET 30">NET 30</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Mata Uang Transaksi:</label>
                    <select id="formKurs" class="ant-select">
                        <?php foreach ($allKurs as $k): ?>
                        <option value="<?= htmlspecialchars($k['nama']) ?>"><?= htmlspecialchars($k['nama']) ?> (<?= htmlspecialchars($k['simbol']) ?>)</option>
                        <?php endforeach; ?>
                        <?php if (empty($allKurs)): ?>
                        <option value="IDR">IDR (Rp)</option>
                        <option value="USD">USD ($)</option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
            <div class="form-row-3" style="background:var(--row-hover); padding:6px; border-radius:4px; border:1px solid var(--border-color);">
                <div class="form-group" style="margin-bottom:0;">
                    <label>Fasilitas Kawasan Berikat:</label>
                    <select id="formKb" class="ant-select">
                        <option value="Y">Ya (Kawasan Berikat - TPB)</option>
                        <option value="N">Tidak</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>Domestik / TLDDP:</label>
                    <select id="formLokal" class="ant-select">
                        <option value="Y">Ya (Lokal / Domestik)</option>
                        <option value="N">Tidak (Import / Export)</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>Mitra Subkontrak:</label>
                    <select id="formSubkon" class="ant-select">
                        <option value="N">Tidak</option>
                        <option value="Y">Ya (Penerima Subkontrak)</option>
                    </select>
                </div>
            </div>
            <div class="form-group" style="margin-top:8px;">
                <label>Status Operasional Perusahaan:</label>
                <select id="formAktif" class="ant-select">
                    <option value="Y">Aktif (Tampil pada Dropdown PO / SO / Invoice / BC)</option>
                    <option value="N">Non-Aktif (Disembunyikan sementara)</option>
                </select>
            </div>
        </div>
        <div class="ant-modal-footer">
            <button type="button" class="ant-btn" onclick="closeModal()">Batal</button>
            <button type="button" class="ant-btn ant-btn-primary" onclick="submitForm()"><i class="fa-solid fa-floppy-disk"></i> Simpan Mitra Bisnis</button>
        </div>
    </div>
</div>

<!-- Modal Panduan SOP Modul -->
<div class="ant-modal-backdrop" id="modalModuleGuide">
    <div class="ant-modal" style="max-width:640px;">
        <div class="ant-modal-header">
            <span><i class="fa-solid fa-circle-question" style="color:var(--primary);"></i> Panduan Modul &amp; SOP: Master Buyer &amp; Supplier</span>
            <i class="fa-solid fa-xmark" style="cursor:pointer;" onclick="closeModuleGuide()"></i>
        </div>
        <div class="ant-modal-body" style="font-size:11px; line-height:1.6;">
            <h4 style="font-size:12px; margin-bottom:6px; color:var(--primary);"><i class="fa-solid fa-handshake"></i> Standar Operasional Prosedur (SOP) Registrasi Mitra Bisnis</h4>
            <p style="margin-bottom:8px;">Modul Master Buyer &amp; Supplier berfungsi mencatat data legalitas seluruh rekanan bisnis pengadaan (Supplier) dan pemasaran (Buyer) yang terintegrasi dengan modul Purchasing, Sales, serta Dokumen Pabean CEISA 4.0:</p>
            <ul style="padding-left:18px; margin-bottom:10px;">
                <li><b>Validasi Coretax DJP (NPWP &amp; NITKU):</b> Berdasarkan regulasi perpajakan Coretax terkini, pengusaha wajib mencantumkan NPWP 16 digit (atau NIK untuk orang pribadi) serta NITKU (Nomor Identitas Tempat Kegiatan Usaha) 22 digit bagi cabang.</li>
                <li><b>Fasilitas Kawasan Berikat (KB):</b> Rekanan yang berstatus Kawasan Berikat (TPB) berhak atas fasilitas Penangguhan Bea Masuk dan Tidak Dipungut PPN (Dokumen BC 2.7 / BC 4.0). Pastikan nomor dan tanggal izin fasilitas telah diinput dengan benar.</li>
                <li><b>Mitra Subkontrak:</b> Entitas penerima pekerjaan subkontrak (BC 2.6.1 / BC 2.6.2) wajib dicentang status <code>Subkontrak: Ya</code> guna pemantauan jaminan kepabeanan dan batas waktu pengembalian barang.</li>
            </ul>
        </div>
        <div class="ant-modal-footer">
            <button type="button" class="ant-btn ant-btn-primary" onclick="closeModuleGuide()">Tutup Panduan</button>
        </div>
    </div>
</div>

<script>
const CURRENT_CMP_ID = <?= json_encode($sessionCmpId) ?>;
let currentPage = 1;
let bsCache = [];

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

async function loadList(page = 1) {
    currentPage = page;
    const search    = document.getElementById('searchKeyword').value.trim();
    const tipe      = document.getElementById('filterTipe').value;
    const fasilitas = document.getElementById('filterFasilitas').value;
    const status    = document.getElementById('filterStatus').value;

    const tbody = document.getElementById('tbodyBs');
    tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:16px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data mitra bisnis...</td></tr>';

    try {
        const urlParams = new URLSearchParams(window.location.search);
        const cmpParam = urlParams.get('cmp_id') || CURRENT_CMP_ID || '';
        const url = `buyer_supplier.php?action=list&page=${page}&limit=25&search=${encodeURIComponent(search)}&tipe=${encodeURIComponent(tipe)}&fasilitas=${encodeURIComponent(fasilitas)}&status=${encodeURIComponent(status)}&cmp_id=${encodeURIComponent(cmpParam)}`;
        const res = await fetch(url);
        if (!res.ok) {
            const errText = await res.text();
            throw new Error(`Server status ${res.status}: ${errText.substring(0, 150)}`);
        }
        const json = await res.json();

        if (json.status === 'OK') {
            bsCache = json.data;
            renderTable(json.data);
            renderPagination(json.total, json.page, json.limit, json.total_pages);
            if (json.kpi) {
                document.getElementById('kpiTotal').innerText = json.kpi.total;
                document.getElementById('kpiSupplier').innerText = json.kpi.total_supplier;
                document.getElementById('kpiBuyer').innerText = json.kpi.total_buyer;
                document.getElementById('kpiKb').innerText = json.kpi.total_kb;
                document.getElementById('kpiAktif').innerText = json.kpi.total_aktif;
            }
        } else {
            tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; color:var(--danger); padding:12px;">${escapeHtml(json.message)}</td></tr>`;
        }
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; color:var(--danger); padding:12px;">Kesalahan: ${escapeHtml(e.message)}</td></tr>`;
    }
}

function renderTable(data) {
    const tbody = document.getElementById('tbodyBs');
    if (!data || data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:18px; color:var(--text-muted);">Tidak ada data mitra bisnis yang sesuai filter.</td></tr>';
        return;
    }

    let html = '';
    const startNo = (currentPage - 1) * 25 + 1;

    data.forEach((r, idx) => {
        const isSupplier = (r.tipe === 'S');
        const tipeTag = isSupplier 
            ? '<span class="tag-supplier"><i class="fa-solid fa-truck-ramp-box"></i> Supplier</span>' 
            : '<span class="tag-buyer"><i class="fa-solid fa-user-tag"></i> Buyer</span>';

        let facTags = '';
        if (r.kb === 'Y') facTags += '<span class="tag-facility tag-kb">KB</span>';
        if (r.lokal === 'Y') facTags += '<span class="tag-facility tag-lokal">Lokal</span>';
        if (r.subkon === 'Y') facTags += '<span class="tag-facility tag-subkon">Subkon</span>';
        if (r.nonpkp === 'Y') facTags += '<span class="tag-facility tag-nonpkp">Non-PKP</span>';
        if (!facTags) facTags = '<span style="color:var(--text-muted);">-</span>';

        const npwpDisplay = (r.npwp && r.npwp !== '-') ? r.npwp : '-';
        const nitkuDisplay = (r.nitku && r.nitku !== '-') ? `<br><span style="font-size:9.5px; color:var(--text-muted);">NITKU: ${escapeHtml(r.nitku)}</span>` : '';

        const switchChecked = (r.aktif === 'Y') ? 'checked' : '';

        html += `<tr>
            <td style="text-align:center; color:var(--text-muted);">${startNo + idx}</td>
            <td><span class="badge-code">${escapeHtml(r.kode)}</span></td>
            <td style="text-align:center;">${tipeTag}</td>
            <td>
                <b>${escapeHtml(r.nama)}</b>
                ${r.nama_pendek && r.nama_pendek !== '-' ? `<br><span style="font-size:10px; color:var(--text-muted);"><i class="fa-regular fa-bookmark"></i> ${escapeHtml(r.nama_pendek)}</span>` : ''}
            </td>
            <td style="font-family:'JetBrains Mono', Consolas, monospace; font-size:10.5px;">
                ${escapeHtml(npwpDisplay)}${nitkuDisplay}
            </td>
            <td>
                ${escapeHtml(r.alamat)}
                <span style="font-size:10px; color:var(--primary); font-weight:600;"> (${escapeHtml(r.negara)})</span>
            </td>
            <td style="text-align:center;">${facTags}</td>
            <td style="text-align:center; font-size:10.5px;">
                <span style="font-weight:600;">${escapeHtml(r.pay_term)}</span>
                <br><span style="font-size:9.5px; color:var(--text-muted);">${escapeHtml(r.kurs)} | ${escapeHtml(r.deli_term)}</span>
            </td>
            <td style="text-align:center;">
                <label class="ant-switch">
                    <input type="checkbox" ${switchChecked} onchange="toggleStatus('${escapeHtml(r.kode)}')">
                    <span class="ant-slider"></span>
                </label>
            </td>
            <td style="text-align:center;">
                <div class="inline-box">
                    <button type="button" class="ant-btn ant-btn-sm" onclick="editItem('${escapeHtml(r.kode)}')" title="Edit Data"><i class="fa-solid fa-pen" style="color:var(--primary);"></i></button>
                    <button type="button" class="ant-btn ant-btn-sm ant-btn-danger" onclick="deleteItem('${escapeHtml(r.kode)}', '${escapeHtml(r.nama)}')" title="Hapus"><i class="fa-solid fa-trash-can"></i></button>
                </div>
            </td>
        </tr>`;
    });

    tbody.innerHTML = html;

    if (window.bsGrid) {
        window.bsGrid.refresh();
    } else if (typeof AntEnterpriseGrid !== 'undefined') {
        window.bsGrid = new AntEnterpriseGrid('#tblBs', {
            gridId: 'erp_buyer_supplier',
            enableSort: true,
            enableResize: true,
            enableContextMenu: true,
            enablePagination: false,
            skipCols: [0, 9],
            exportTitle: 'Master_Buyer_Supplier',
            onEdit: (tr) => {
                const kode = tr.cells[1] ? tr.cells[1].innerText.trim() : '';
                if (kode) editItem(kode);
            }
        });
    }
}

function renderPagination(total, page, limit, totalPages) {
    document.getElementById('pageInfo').innerText = `Menampilkan ${total ? ((page - 1) * limit + 1) : 0} - ${Math.min(page * limit, total)} dari ${total} mitra bisnis`;
    const btns = document.getElementById('pageBtns');
    if (totalPages <= 1) {
        btns.innerHTML = '';
        return;
    }
    let html = '';
    html += `<button type="button" class="ant-btn ant-btn-sm" ${page <= 1 ? 'disabled style="opacity:0.5;"' : ''} onclick="loadList(${page - 1})"><i class="fa-solid fa-angle-left"></i> Prev</button>`;
    html += `<span style="padding:0 6px; font-weight:600;">${page} / ${totalPages}</span>`;
    html += `<button type="button" class="ant-btn ant-btn-sm" ${page >= totalPages ? 'disabled style="opacity:0.5;"' : ''} onclick="loadList(${page + 1})">Next <i class="fa-solid fa-angle-right"></i></button>`;
    btns.innerHTML = html;
}

function filterByTab(tipe) {
    document.querySelectorAll('.ant-tab-btn').forEach(b => b.classList.remove('active'));
    if (tipe === 'S') document.getElementById('tabBtnSup').classList.add('active');
    else if (tipe === 'B') document.getElementById('tabBtnBuy').classList.add('active');
    else document.getElementById('tabBtnAll').classList.add('active');

    document.getElementById('filterTipe').value = tipe;
    document.getElementById('filterFasilitas').value = 'ALL';
    loadList(1);
}

function filterByFasilitas(fas) {
    document.querySelectorAll('.ant-tab-btn').forEach(b => b.classList.remove('active'));
    if (fas === 'KB') document.getElementById('tabBtnKb').classList.add('active');
    else if (fas === 'SUBKON') document.getElementById('tabBtnSubkon').classList.add('active');

    document.getElementById('filterTipe').value = '';
    document.getElementById('filterFasilitas').value = fas;
    loadList(1);
}

function filterByStatus(st) {
    document.getElementById('filterStatus').value = st;
    loadList(1);
}

function openAddModal() {
    document.getElementById('modalFormTitle').innerHTML = '<i class="fa-solid fa-plus-circle"></i> Tambah Mitra Bisnis';
    document.getElementById('formIsEdit').value = '0';
    document.getElementById('formKode').value = '';
    document.getElementById('formKode').readOnly = false;
    document.getElementById('formTipe').value = 'S';
    document.getElementById('formNama').value = '';
    document.getElementById('formNamaPendek').value = '';
    document.getElementById('formNpwp').value = '';
    document.getElementById('formNitku').value = '';
    document.getElementById('formNonpkp').value = 'N';
    document.getElementById('formNib').value = '';
    document.getElementById('formNoIjin').value = '';
    document.getElementById('formTglIjin').value = '';
    document.getElementById('formAlamat').value = '';
    document.getElementById('formNegara').value = 'ID';
    document.getElementById('formKkt').value = '';
    document.getElementById('formKontak').value = '';
    document.getElementById('formTelp').value = '';
    document.getElementById('formFax').value = '';
    document.getElementById('formEmail').value = '';
    document.getElementById('formDeliTerm').value = 'FOB';
    document.getElementById('formPayTerm').value = 'COD';
    document.getElementById('formKurs').value = 'IDR';
    document.getElementById('formKb').value = 'Y';
    document.getElementById('formLokal').value = 'Y';
    document.getElementById('formSubkon').value = 'N';
    document.getElementById('formAktif').value = 'Y';
    document.getElementById('modalForm').classList.add('active');
}

async function editItem(kode) {
    try {
        const urlParams = new URLSearchParams(window.location.search);
        const cmpParam = urlParams.get('cmp_id') || CURRENT_CMP_ID || '';
        const res = await fetch(`buyer_supplier.php?action=get&kode=${encodeURIComponent(kode)}&cmp_id=${encodeURIComponent(cmpParam)}`);
        if (!res.ok) {
            const errText = await res.text();
            throw new Error(`Server error (${res.status}): ${errText.substring(0, 150)}`);
        }
        const json = await res.json();
        if (json.status !== 'OK') {
            Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
            return;
        }

        const d = json.data;
        document.getElementById('modalFormTitle').innerHTML = `<i class="fa-solid fa-pen-to-square"></i> Edit Mitra: ${escapeHtml(d.NAMA)}`;
        document.getElementById('formIsEdit').value = '1';
        document.getElementById('formKode').value = d.KODE;
        document.getElementById('formKode').readOnly = true;
        document.getElementById('formTipe').value = d.TIPE;
        document.getElementById('formNama').value = d.NAMA;
        document.getElementById('formNamaPendek').value = d.NAMA_PENDEK || '';
        document.getElementById('formNpwp').value = d.NPWP || '';
        document.getElementById('formNitku').value = d.nitku || '';
        document.getElementById('formNonpkp').value = (d.nonpkp === 'Y') ? 'Y' : 'N';
        document.getElementById('formNib').value = d.nib_entitas || '';
        document.getElementById('formNoIjin').value = d.nomor_ijin_entitas || '';
        document.getElementById('formTglIjin').value = d.tanggal_ijin_entitas || '';
        document.getElementById('formAlamat').value = d.ALAMAT || '';
        document.getElementById('formNegara').value = d.NEGARA || 'ID';
        document.getElementById('formKkt').value = d.KODE_KANTOR_TUJUAN || '';
        document.getElementById('formKontak').value = d.KONTAK || '';
        document.getElementById('formTelp').value = d.TELP || '';
        document.getElementById('formFax').value = d.FAX || '';
        document.getElementById('formEmail').value = d.EMAIL || '';
        document.getElementById('formDeliTerm').value = d.DELI_TERM || 'FOB';
        document.getElementById('formPayTerm').value = d.PAY_TERM || 'COD';
        document.getElementById('formKurs').value = d.kurs || 'IDR';
        document.getElementById('formKb').value = (d.KB === 'Y') ? 'Y' : 'N';
        document.getElementById('formLokal').value = (d.LOKAL === 'Y') ? 'Y' : 'N';
        document.getElementById('formSubkon').value = (d.SUBKON === 'Y') ? 'Y' : 'N';
        document.getElementById('formAktif').value = (d.AKTIF === 'N' || d.AKTIF === '0') ? 'N' : 'Y';

        document.getElementById('modalForm').classList.add('active');
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Kesalahan Sistem', text: e.message });
    }
}

function closeModal() {
    document.getElementById('modalForm').classList.remove('active');
}

async function submitForm() {
    const isEdit = document.getElementById('formIsEdit').value;
    const kode   = document.getElementById('formKode').value.trim();
    const nama   = document.getElementById('formNama').value.trim();

    if (!kode || !nama) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Kode dan Nama Perusahaan wajib diisi!' });
        return;
    }

    const urlParams = new URLSearchParams(window.location.search);
    const cmpParam = urlParams.get('cmp_id') || CURRENT_CMP_ID || '';

    const fd = new FormData();
    fd.append('cmp_id', cmpParam);
    fd.append('is_edit', isEdit);
    fd.append('kode', kode);
    fd.append('tipe', document.getElementById('formTipe').value);
    fd.append('nama', nama);
    fd.append('nama_pendek', document.getElementById('formNamaPendek').value.trim());
    fd.append('npwp', document.getElementById('formNpwp').value.trim());
    fd.append('nitku', document.getElementById('formNitku').value.trim());
    fd.append('nonpkp', document.getElementById('formNonpkp').value);
    fd.append('nib_entitas', document.getElementById('formNib').value.trim());
    fd.append('nomor_ijin_entitas', document.getElementById('formNoIjin').value.trim());
    fd.append('tanggal_ijin_entitas', document.getElementById('formTglIjin').value);
    fd.append('alamat', document.getElementById('formAlamat').value.trim());
    fd.append('negara', document.getElementById('formNegara').value.trim());
    fd.append('kkt', document.getElementById('formKkt').value.trim());
    fd.append('kontak', document.getElementById('formKontak').value.trim());
    fd.append('telp', document.getElementById('formTelp').value.trim());
    fd.append('fax', document.getElementById('formFax').value.trim());
    fd.append('email', document.getElementById('formEmail').value.trim());
    fd.append('deli_term', document.getElementById('formDeliTerm').value);
    fd.append('pay_term', document.getElementById('formPayTerm').value);
    fd.append('kurs', document.getElementById('formKurs').value);
    fd.append('kb', document.getElementById('formKb').value);
    fd.append('lokal', document.getElementById('formLokal').value);
    fd.append('subkon', document.getElementById('formSubkon').value);
    fd.append('aktif', document.getElementById('formAktif').value);

    try {
        const res = await fetch('buyer_supplier.php?action=save', { method: 'POST', body: fd });
        if (!res.ok) {
            const errText = await res.text();
            throw new Error(`Server error (${res.status}): ${errText.substring(0, 150)}`);
        }
        const json = await res.json();
        if (json.status === 'OK') {
            closeModal();
            Swal.fire({ icon: 'success', title: 'Berhasil', text: json.message, timer: 1200, showConfirmButton: false });
            loadList(currentPage);
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Kesalahan Sistem', text: e.message });
    }
}

async function toggleStatus(kode) {
    try {
        const urlParams = new URLSearchParams(window.location.search);
        const cmpParam = urlParams.get('cmp_id') || CURRENT_CMP_ID || '';
        const fd = new FormData();
        fd.append('cmp_id', cmpParam);
        fd.append('kode', kode);
        const res = await fetch('buyer_supplier.php?action=toggle_status', { method: 'POST', body: fd });
        if (!res.ok) {
            const errText = await res.text();
            throw new Error(`Server error (${res.status}): ${errText.substring(0, 150)}`);
        }
        const json = await res.json();
        if (json.status === 'OK') {
            loadList(currentPage);
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Kesalahan', text: e.message });
    }
}

async function deleteItem(kode, nama) {
    const ask = await Swal.fire({
        title: 'Konfirmasi Hapus',
        text: `Hapus mitra bisnis "${nama}" (${kode})?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Ya, Hapus',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#ff4d4f'
    });

    if (!ask.isConfirmed) return;

    try {
        const urlParams = new URLSearchParams(window.location.search);
        const cmpParam = urlParams.get('cmp_id') || CURRENT_CMP_ID || '';
        const fd = new FormData();
        fd.append('cmp_id', cmpParam);
        fd.append('kode', kode);
        const res = await fetch('buyer_supplier.php?action=delete', { method: 'POST', body: fd });
        if (!res.ok) {
            const errText = await res.text();
            throw new Error(`Server error (${res.status}): ${errText.substring(0, 150)}`);
        }
        const json = await res.json();
        if (json.status === 'OK') {
            Swal.fire({ icon: 'success', title: 'Terhapus', text: json.message, timer: 1200, showConfirmButton: false });
            loadList(currentPage);
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Kesalahan Sistem', text: e.message });
    }
}

function exportCsv() {
    const urlParams = new URLSearchParams(window.location.search);
    const cmpParam = urlParams.get('cmp_id') || CURRENT_CMP_ID || '';
    window.location.href = `buyer_supplier.php?action=export_csv&cmp_id=${encodeURIComponent(cmpParam)}`;
}

function exportExcel() {
    if (!bsCache || bsCache.length === 0) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Tidak ada data untuk diexport!' });
        return;
    }
    const rows = bsCache.map((r, i) => ({
        'No': i + 1,
        'Kode': r.kode,
        'Tipe': (r.tipe === 'S' ? 'Supplier' : 'Buyer'),
        'Nama Perusahaan': r.nama,
        'Nama Pendek': r.nama_pendek,
        'NPWP': r.npwp,
        'NITKU': r.nitku,
        'Status PKP': (r.nonpkp === 'Y' ? 'Non-PKP' : 'PKP'),
        'Alamat': r.alamat,
        'Negara': r.negara,
        'Kontak': r.kontak,
        'Telepon': r.telp,
        'Email': r.email,
        'Fasilitas KB': r.kb,
        'Lokal': r.lokal,
        'Subkontrak': r.subkon,
        'Termin Bayar': r.pay_term,
        'Mata Uang': r.kurs,
        'Status': (r.aktif === 'Y' ? 'Aktif' : 'Non-Aktif')
    }));

    const ws = XLSX.utils.json_to_sheet(rows);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Mitra Bisnis");
    XLSX.writeFile(wb, `Master_Buyer_Supplier_${new Date().toISOString().slice(0,10)}.xlsx`);
}

function openModuleGuide() {
    document.getElementById('modalModuleGuide').classList.add('active');
}
function closeModuleGuide() {
    document.getElementById('modalModuleGuide').classList.remove('active');
}

// Inisialisasi
window.addEventListener('DOMContentLoaded', () => {
    loadList(1);
});
</script>
</body>
</html>

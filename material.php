<?php
/**
 * CEISA 4.0 - Master Data Barang (material.php)
 * Mengadopsi w_material.srw & d_material.srd
 * Pengelolaan Master Items / Material / Barang Jadi / Bahan Baku / Scrap
 * Dilengkapi Import & Export Excel (.xlsx) dan Klasifikasi dinamis dari bc_tipe
 */
session_start();
require_once __DIR__ . '/koneksi.php';

$isAdmin = !empty($_SESSION['is_admin']) || (isset($_GET['is_admin']) && $_GET['is_admin'] === '1');
$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? ($_GET['cmp_id'] ?? ''));
$escSessionCmp = $koneksi->real_escape_string($sessionCmpId);

// Ambil Referensi Klasifikasi dari bc_tipe
$klasList = [];
$validKlasList = [];
$qTipe = $koneksi->query("SELECT * FROM bc_tipe WHERE NOT grp IN ('FA', 'Consumables') ORDER BY idx");
if ($qTipe) {
    while ($t = $qTipe->fetch_assoc()) {
        if ($t['tipe'] !== '-- SEMUA KLAS --') {
            $klasList[] = $t;
            $validKlasList[] = $t['tipe'];
        }
    }
}

// Cek setting perusahaan: Allow huruf kecil
$allowLowercase = false;
$activeCmpName  = 'Perusahaan';
if (!empty($sessionCmpId)) {
    $qCmp = $koneksi->query("SELECT cmp_name, allow_lowercase_code FROM bc_ceisa_company WHERE cmp_id = '$escSessionCmp' LIMIT 1");
    if ($qCmp && $rCmp = $qCmp->fetch_assoc()) {
        $allowLowercase = ((int)$rCmp['allow_lowercase_code'] === 1);
        $activeCmpName  = $rCmp['cmp_name'];
    }
} else if (isset($_SESSION['company_allow_lowercase'])) {
    $allowLowercase = ((int)$_SESSION['company_allow_lowercase'] === 1);
    $activeCmpName  = $_SESSION['company_name'] ?? 'Perusahaan';
}

// Cek data bc_1ab2 yang KLAS-nya bermasalah (kosong, '-', atau tidak ada di bc_tipe)
$cntInvalidKlas = 0;
$cmpFilter1ab2 = !empty($sessionCmpId) ? " AND (cmp_id = '$escSessionCmp' OR cmp_id = '')" : "";
$qInv = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE (KLAS IS NULL OR TRIM(KLAS) = '' OR TRIM(KLAS) = '-' OR KLAS NOT IN (SELECT tipe FROM bc_tipe))$cmpFilter1ab2");
if ($qInv) {
    $cntInvalidKlas = (int)($qInv->fetch_row()[0] ?? 0);
}

// Cek data transaksi bc_1ab2 yang satuannya berbeda dari bc_material
$cntDiffSatuan = 0;
$qDiffSat = $koneksi->query("SELECT COUNT(DISTINCT m.KODE_BARANG) 
                             FROM bc_material m
                             JOIN bc_1ab2 t ON m.KODE_BARANG = t.KODE_BARANG AND (m.cmp_id = t.cmp_id OR m.cmp_id = '' OR t.cmp_id = '')
                             WHERE (t.UNIT != m.KODE_SATUAN OR t.UNIT IS NULL OR TRIM(t.UNIT) = '' OR TRIM(t.UNIT) = '-')
                             $cmpFilter1ab2");
if ($qDiffSat && $rD = $qDiffSat->fetch_row()) {
    $cntDiffSatuan = (int)($rD[0] ?? 0);
}

$action = $_REQUEST['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '';

// 1. AJAX: LIST DATA MATERIAL
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $search = trim($_GET['q'] ?? '');
    $klas   = trim($_GET['klas'] ?? '');

    $where = [];
    if (!empty($klas)) {
        $where[] = "KLAS = '" . $koneksi->real_escape_string($klas) . "'";
    }
    if (!empty($search)) {
        $s = $koneksi->real_escape_string($search);
        $where[] = "(KODE_BARANG LIKE '%$s%' OR KODE_BARANG2 LIKE '%$s%' OR URAIAN LIKE '%$s%' OR POS_TARIF LIKE '%$s%' OR MODEL LIKE '%$s%')";
    }

    if (!empty($sessionCmpId)) {
        $where[] = "(m.cmp_id = '$escSessionCmp' OR m.cmp_id = '')";
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    $sql = "SELECT 
        cmp_id, KODE_BARANG, KODE_BARANG2, URAIAN, MODEL, POS_TARIF, KODE_SATUAN, KODE_SATUAN2, KONVERSI,
        UNIT_PRICE, UNIT_PRICE2, KODE_VALUTA, BERAT, KLAS, STOK_MINIMAL, LOKASI, NOTE, AKTIF,
        (SELECT COUNT(1) FROM bc_ceisa_bom x WHERE x.kode_barang = m.KODE_BARANG) AS jml_bom
    FROM bc_material m
    $whereSql
    ORDER BY KLAS ASC, KODE_BARANG ASC
    LIMIT 300";

    $res = $koneksi->query($sql);
    $data = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
        }
    }

    // Hitung KPI
    $kpiCmpWhere = !empty($sessionCmpId) ? "WHERE (cmp_id = '$escSessionCmp' OR cmp_id = '')" : "";
    $kpiCmpAnd   = !empty($sessionCmpId) ? "AND (cmp_id = '$escSessionCmp' OR cmp_id = '')" : "";
    $kpiTotal = $koneksi->query("SELECT COUNT(1) FROM bc_material $kpiCmpWhere")->fetch_row()[0] ?? 0;
    $kpiBb    = $koneksi->query("SELECT COUNT(1) FROM bc_material WHERE (KLAS LIKE '%Raw%' OR KLAS LIKE '%Bahan Baku%') $kpiCmpAnd")->fetch_row()[0] ?? 0;
    $kpiFg    = $koneksi->query("SELECT COUNT(1) FROM bc_material WHERE (KLAS LIKE '%Finished%' OR KLAS LIKE '%Barang Jadi%') $kpiCmpAnd")->fetch_row()[0] ?? 0;
    $kpiWip   = $koneksi->query("SELECT COUNT(1) FROM bc_material WHERE (KLAS LIKE '%Progress%' OR KLAS LIKE '%WIP%') $kpiCmpAnd")->fetch_row()[0] ?? 0;

    echo json_encode([
        'status' => 'OK',
        'data' => $data,
        'count' => count($data),
        'kpi' => [
            'total' => (int)$kpiTotal,
            'bahan_baku' => (int)$kpiBb,
            'finished_goods' => (int)$kpiFg,
            'wip' => (int)$kpiWip
        ]
    ]);
    exit;
}

// 2. AJAX: SIMPAN / UPDATE MATERIAL SATUAN
if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    $is_edit = (trim($_POST['is_edit'] ?? '0') === '1');
    $rawKode = trim($_POST['kode_barang'] ?? '');
    $kode    = $allowLowercase ? $rawKode : strtoupper($rawKode);
    $kode2   = trim($_POST['kode_barang2'] ?? '');
    $uraian  = trim($_POST['uraian'] ?? '');
    $model   = trim($_POST['model'] ?? '');
    $hs      = preg_replace('/[^0-9]/', '', $_POST['pos_tarif'] ?? '');
    $satuan  = strtoupper(trim($_POST['kode_satuan'] ?? 'PCE'));
    $satuan2 = strtoupper(trim($_POST['kode_satuan2'] ?? ''));
    $konversi = intval($_POST['konversi'] ?? 1);
    $harga   = floatval(str_replace(',', '', $_POST['unit_price'] ?? '0'));
    $valuta  = strtoupper(trim($_POST['kode_valuta'] ?? 'IDR'));
    $berat   = floatval($_POST['berat'] ?? '0');
    $klas    = trim($_POST['klas'] ?? 'Raw Materials');
    $lokasi  = trim($_POST['lokasi'] ?? '');
    $note    = trim($_POST['note'] ?? '');

    if (empty($kode) || $kode === '-') {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kode Barang wajib diisi dan DILARANG KERAS menggunakan tanda "-".']);
        exit;
    }
    if (empty($uraian)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Uraian Barang wajib diisi.']);
        exit;
    }
    if (empty($klas) || $klas === '-') {
        echo json_encode(['status' => 'ERROR', 'message' => 'Klasifikasi (KLAS) tidak boleh kosong atau bernilai "-".']);
        exit;
    }

    $escKode   = $koneksi->real_escape_string($kode);
    $escKode2  = $koneksi->real_escape_string($kode2);
    $escUraian = $koneksi->real_escape_string($uraian);
    $escModel  = $koneksi->real_escape_string($model);
    $escHs     = $koneksi->real_escape_string($hs);
    $escSat    = $koneksi->real_escape_string($satuan);
    $escSat2   = $koneksi->real_escape_string($satuan2);
    $escVal    = $koneksi->real_escape_string($valuta);
    $escKlas   = $koneksi->real_escape_string($klas);
    $escLokasi = $koneksi->real_escape_string($lokasi);
    $escNote   = $koneksi->real_escape_string($note);

    if ($is_edit) {
        $sql = "UPDATE bc_material SET
            KODE_BARANG2 = '$escKode2',
            URAIAN = '$escUraian',
            MODEL = '$escModel',
            POS_TARIF = '$escHs',
            KODE_SATUAN = '$escSat',
            KODE_SATUAN2 = '$escSat2',
            KONVERSI = $konversi,
            UNIT_PRICE = $harga,
            KODE_VALUTA = '$escVal',
            BERAT = $berat,
            KLAS = '$escKlas',
            LOKASI = '$escLokasi',
            NOTE = '$escNote',
            TGL_UPDATE = NOW()
            WHERE KODE_BARANG = '$escKode' AND (cmp_id = '$escSessionCmp' OR cmp_id = '')";
        $ok = $koneksi->query($sql);
        if ($ok) {
            // SINKRONISASI OTOMATIS KE bc_1ab2
            $cmpFilter1ab2 = !empty($sessionCmpId) ? " AND (cmp_id = '$escSessionCmp' OR cmp_id = '')" : "";
            $koneksi->query("UPDATE bc_1ab2 SET KLAS = '$escKlas' WHERE KODE_BARANG = '$escKode'$cmpFilter1ab2");
            $syncedRows = max(0, (int)$koneksi->affected_rows);
            $msg = "Item $kode berhasil diperbarui";
            if ($syncedRows > 0) {
                $msg .= " dan disinkronkan ke $syncedRows baris transaksi IT Inventory!";
            } else {
                $msg .= "!";
            }
            echo json_encode(['status' => 'OK', 'message' => $msg, 'synced_1ab2' => $syncedRows]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => $koneksi->error]);
        }
    } else {
        $chk = $koneksi->query("SELECT 1 FROM bc_material WHERE KODE_BARANG = '$escKode' AND (cmp_id = '$escSessionCmp' OR cmp_id = '') LIMIT 1");
        if ($chk && $chk->num_rows > 0) {
            echo json_encode(['status' => 'ERROR', 'message' => "Kode Barang $kode sudah ada di database."]);
            exit;
        }
        $sql = "INSERT INTO bc_material (
            cmp_id, KODE_BARANG, KODE_BARANG2, URAIAN, MODEL, POS_TARIF, KODE_SATUAN, KODE_SATUAN2,
            KONVERSI, UNIT_PRICE, KODE_VALUTA, BERAT, KLAS, LOKASI, NOTE, AKTIF, TGL_REGISTER
        ) VALUES (
            '$escSessionCmp', '$escKode', '$escKode2', '$escUraian', '$escModel', '$escHs', '$escSat', '$escSat2',
            $konversi, $harga, '$escVal', $berat, '$escKlas', '$escLokasi', '$escNote', 'Y', NOW()
        )";
        $ok = $koneksi->query($sql);
        if ($ok) {
            // Sinkronkan juga jika sudah ada transaksi di bc_1ab2 dengan kode ini yang KLAS-nya kosong/berbeda
            $cmpFilter1ab2 = !empty($sessionCmpId) ? " AND (cmp_id = '$escSessionCmp' OR cmp_id = '')" : "";
            $koneksi->query("UPDATE bc_1ab2 SET KLAS = '$escKlas' WHERE KODE_BARANG = '$escKode'$cmpFilter1ab2");
            $syncedRows = max(0, (int)$koneksi->affected_rows);
            $msg = "Item $kode berhasil ditambahkan";
            if ($syncedRows > 0) {
                $msg .= " dan disinkronkan ke $syncedRows baris transaksi IT Inventory!";
            } else {
                $msg .= "!";
            }
            echo json_encode(['status' => 'OK', 'message' => $msg, 'synced_1ab2' => $syncedRows]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => $koneksi->error]);
        }
    }
    exit;
}

// 3. AJAX: IMPORT DATA DARI EXCEL (BATCH UPSERT)
if ($action === 'import_excel') {
    header('Content-Type: application/json; charset=utf-8');
    $rowsJson = $_POST['rows'] ?? '[]';
    $rows = json_decode($rowsJson, true);
    if (!is_array($rows) || empty($rows)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Data impor kosong atau format salah.']);
        exit;
    }

    $inserted = 0;
    $updated  = 0;
    foreach ($rows as $r) {
        $rawKode = trim($r['kode_barang'] ?? ($r['KODE_BARANG'] ?? ($r['Kode Barang'] ?? '')));
        $kode    = $allowLowercase ? $rawKode : strtoupper($rawKode);
        $uraian  = trim($r['uraian'] ?? ($r['URAIAN'] ?? ($r['Uraian'] ?? ($r['Nama Barang'] ?? ''))));
        if (empty($kode) || $kode === '-' || empty($uraian)) continue;

        $kode2  = trim($r['kode_barang2'] ?? ($r['KODE_BARANG2'] ?? ($r['Part No'] ?? '')));
        $model  = trim($r['model'] ?? ($r['MODEL'] ?? ($r['Model'] ?? '')));
        $hs     = preg_replace('/[^0-9]/', '', $r['pos_tarif'] ?? ($r['POS_TARIF'] ?? ($r['HS Code'] ?? '')));
        $sat    = strtoupper(trim($r['kode_satuan'] ?? ($r['KODE_SATUAN'] ?? ($r['Satuan'] ?? 'PCE'))));
        $harga  = floatval($r['unit_price'] ?? ($r['UNIT_PRICE'] ?? ($r['Harga'] ?? 0)));
        $valuta = strtoupper(trim($r['kode_valuta'] ?? ($r['KODE_VALUTA'] ?? 'IDR')));
        $klas   = trim($r['klas'] ?? ($r['KLAS'] ?? ($r['Klasifikasi'] ?? 'Raw Materials')));
        if (empty($klas) || $klas === '-') $klas = 'Raw Materials';
        $lokasi = trim($r['lokasi'] ?? ($r['LOKASI'] ?? ($r['Lokasi'] ?? '')));
        $note   = trim($r['note'] ?? ($r['NOTE'] ?? ($r['Catatan'] ?? '')));

        $escKode   = $koneksi->real_escape_string($kode);
        $escKode2  = $koneksi->real_escape_string($kode2);
        $escUraian = $koneksi->real_escape_string($uraian);
        $escModel  = $koneksi->real_escape_string($model);
        $escHs     = $koneksi->real_escape_string($hs);
        $escSat    = $koneksi->real_escape_string($sat);
        $escVal    = $koneksi->real_escape_string($valuta);
        $escKlas   = $koneksi->real_escape_string($klas);
        $escLokasi = $koneksi->real_escape_string($lokasi);
        $escNote   = $koneksi->real_escape_string($note);

        $sql = "INSERT INTO bc_material (
            cmp_id, KODE_BARANG, KODE_BARANG2, URAIAN, MODEL, POS_TARIF, KODE_SATUAN,
            UNIT_PRICE, KODE_VALUTA, KLAS, LOKASI, NOTE, AKTIF, TGL_REGISTER
        ) VALUES (
            '$escSessionCmp', '$escKode', '$escKode2', '$escUraian', '$escModel', '$escHs', '$escSat',
            $harga, '$escVal', '$escKlas', '$escLokasi', '$escNote', 'Y', NOW()
        ) ON DUPLICATE KEY UPDATE
            cmp_id = VALUES(cmp_id),
            KODE_BARANG2 = VALUES(KODE_BARANG2),
            URAIAN = VALUES(URAIAN),
            MODEL = VALUES(MODEL),
            POS_TARIF = VALUES(POS_TARIF),
            KODE_SATUAN = VALUES(KODE_SATUAN),
            UNIT_PRICE = VALUES(UNIT_PRICE),
            KLAS = VALUES(KLAS),
            LOKASI = VALUES(LOKASI),
            NOTE = VALUES(NOTE),
            TGL_UPDATE = NOW()";

        if ($koneksi->query($sql)) {
            if ($koneksi->affected_rows === 1) $inserted++;
            else $updated++;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'message' => "Proses Impor Berhasil: $inserted item baru ditambahkan, $updated item diperbarui.",
        'inserted' => $inserted,
        'updated' => $updated
    ]);
    exit;
}

// 4. AJAX: HAPUS MATERIAL
if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $kode = trim($_POST['kode_barang'] ?? '');
    if (empty($kode)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kode barang kosong.']);
        exit;
    }
    $escKode = $koneksi->real_escape_string($kode);
    $del = $koneksi->query("DELETE FROM bc_material WHERE KODE_BARANG = '$escKode' AND (cmp_id = '$escSessionCmp' OR cmp_id = '')");
    if ($del) {
        echo json_encode(['status' => 'OK', 'message' => "Item $kode berhasil dihapus!"]);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => $koneksi->error]);
    }
    exit;
}

// 5. AJAX: GET IMPORT CANDIDATES (DARI bc_1ab2 ATAU bc_ceisa_barang)
// Deduplikasi menggunakan MAX(...) agar tidak ada barang dobel, pisahkan yang berkode '-'
if ($action === 'get_import_candidates') {
    header('Content-Type: application/json; charset=utf-8');
    $source = trim($_GET['source'] ?? '1ab2'); // '1ab2' atau 'staging'
    $q      = trim($_GET['q'] ?? '');

    // List material yang sudah ada di bc_material
    $existingItems = [];
    $cmpMatWhere = !empty($sessionCmpId) ? "WHERE (cmp_id = '$escSessionCmp' OR cmp_id = '')" : "";
    $qMat = $koneksi->query("SELECT KODE_BARANG, URAIAN, POS_TARIF, KODE_SATUAN, KLAS FROM bc_material $cmpMatWhere");
    if ($qMat) {
        while ($m = $qMat->fetch_assoc()) {
            $existingItems[strtoupper(trim($m['KODE_BARANG']))] = $m;
        }
    }

    $candidates = [];

    if ($source === '1ab2') {
        // Dari IT Inventory: bc_1ab2
        $where = [];
        if (!empty($sessionCmpId)) {
            $where[] = "(cmp_id = '$escSessionCmp' OR cmp_id = '')";
        }
        if (!empty($q)) {
            $escQ = $koneksi->real_escape_string($q);
            $where[] = "(KODE_BARANG LIKE '%$escQ%' OR URAIAN LIKE '%$escQ%' OR POS_TARIF LIKE '%$escQ%')";
        }
        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // DEDUP: GROUP BY IF(TRIM(KODE_BARANG) IN ('', '-'), CONCAT('__MINUS__', TRIM(URAIAN)), UPPER(TRIM(KODE_BARANG)))
        // Menggunakan MAX(URAIAN), MAX(POS_TARIF), MAX(UNIT), MAX(KLAS)
        $sql = "SELECT 
            MAX(TRIM(KODE_BARANG)) AS raw_kode,
            MAX(TRIM(URAIAN)) AS uraian,
            MAX(TRIM(POS_TARIF)) AS pos_tarif,
            MAX(TRIM(UNIT)) AS kode_satuan,
            MAX(TRIM(KLAS)) AS raw_klas,
            COUNT(1) AS jml_transaksi
        FROM bc_1ab2
        $whereSql
        GROUP BY IF(TRIM(KODE_BARANG) IN ('', '-'), CONCAT('__MINUS__', TRIM(URAIAN)), UPPER(TRIM(KODE_BARANG)))
        ORDER BY (MAX(TRIM(KODE_BARANG)) IN ('', '-')) DESC, raw_kode ASC
        LIMIT 2500";

        $res = $koneksi->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rawKode = trim($row['raw_kode'] ?? '');
                $isMinus = ($rawKode === '-' || $rawKode === '');
                $kode    = $isMinus ? '-' : ($allowLowercase ? $rawKode : strtoupper($rawKode));
                $klas    = trim($row['raw_klas'] ?? '');
                $isKlasValid = (!empty($klas) && $klas !== '-' && in_array($klas, $validKlasList));
                $exists  = (!$isMinus && isset($existingItems[strtoupper($kode)]));

                $candidates[] = [
                    'source'         => '1ab2',
                    'raw_kode'       => $rawKode,
                    'kode_barang'    => $kode,
                    'is_minus'       => $isMinus,
                    'uraian'         => $row['uraian'] ?? '',
                    'pos_tarif'      => preg_replace('/[^0-9]/', '', $row['pos_tarif'] ?? ''),
                    'kode_satuan'    => strtoupper(trim($row['kode_satuan'] ?: 'PCE')),
                    'unit_price'     => 0,
                    'klas'           => $isKlasValid ? $klas : '',
                    'is_klas_valid'  => $isKlasValid,
                    'jml_transaksi'  => (int)$row['jml_transaksi'],
                    'exists'         => $exists,
                    'selected'       => true
                ];
            }
        }
    } else {
        // Dari Staging Pabean: bc_ceisa_barang
        $where = [];
        if (!empty($sessionCmpId)) {
            $escCmp = $koneksi->real_escape_string($sessionCmpId);
            $where[] = "cmp_id = '$escCmp'";
        }
        if (!empty($q)) {
            $escQ = $koneksi->real_escape_string($q);
            $where[] = "(kode_barang LIKE '%$escQ%' OR uraian LIKE '%$escQ%' OR hs LIKE '%$escQ%')";
        }
        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // DEDUP: GROUP BY IF(TRIM(kode_barang) IN ('', '-'), CONCAT('__MINUS__', TRIM(uraian)), UPPER(TRIM(kode_barang)))
        // Menggunakan MAX(uraian), MAX(hs), MAX(kode_satuan), MAX(harga_satuan)
        $sql = "SELECT 
            MAX(TRIM(kode_barang)) AS raw_kode,
            MAX(TRIM(uraian)) AS uraian,
            MAX(TRIM(hs)) AS pos_tarif,
            MAX(TRIM(kode_satuan)) AS kode_satuan,
            MAX(harga_satuan) AS unit_price,
            COUNT(1) AS jml_transaksi
        FROM bc_ceisa_barang
        $whereSql
        GROUP BY IF(TRIM(kode_barang) IN ('', '-'), CONCAT('__MINUS__', TRIM(uraian)), UPPER(TRIM(kode_barang)))
        ORDER BY (MAX(TRIM(kode_barang)) IN ('', '-')) DESC, raw_kode ASC
        LIMIT 2500";

        $res = $koneksi->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rawKode = trim($row['raw_kode'] ?? '');
                $isMinus = ($rawKode === '-' || $rawKode === '');
                $kode    = $isMinus ? '-' : ($allowLowercase ? $rawKode : strtoupper($rawKode));
                $exists  = (!$isMinus && isset($existingItems[strtoupper($kode)]));

                $candidates[] = [
                    'source'         => 'staging',
                    'raw_kode'       => $rawKode,
                    'kode_barang'    => $kode,
                    'is_minus'       => $isMinus,
                    'uraian'         => $row['uraian'] ?? '',
                    'pos_tarif'      => preg_replace('/[^0-9]/', '', $row['pos_tarif'] ?? ''),
                    'kode_satuan'    => strtoupper(trim($row['kode_satuan'] ?: 'PCE')),
                    'unit_price'     => floatval($row['unit_price'] ?? 0),
                    'klas'           => 'Raw Materials', // default awal untuk staging (dapat diubah per baris atau massal)
                    'is_klas_valid'  => true,
                    'jml_transaksi'  => (int)$row['jml_transaksi'],
                    'exists'         => $exists,
                    'selected'       => true
                ];
            }
        }
    }

    echo json_encode([
        'status'          => 'OK',
        'source'          => $source,
        'allow_lowercase' => $allowLowercase,
        'active_cmp_name' => $activeCmpName,
        'total'           => count($candidates),
        'data'            => $candidates,
        'klas_options'    => $klasList
    ]);
    exit;
}

// 6. AJAX: SIMPAN HASIL IMPORT DARI DATABASE (bc_1ab2 ATAU bc_ceisa_barang)
if ($action === 'save_imported_materials') {
    header('Content-Type: application/json; charset=utf-8');
    $itemsJson = $_POST['items'] ?? '[]';
    $items = json_decode($itemsJson, true);

    if (!is_array($items) || empty($items)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Tidak ada item yang dipilih untuk diimport.']);
        exit;
    }

    // Map tipe valid
    $validKlasMap = [];
    $qK = $koneksi->query("SELECT tipe FROM bc_tipe");
    if ($qK) {
        while ($tk = $qK->fetch_assoc()) {
            $validKlasMap[$tk['tipe']] = true;
        }
    }

    $inserted = 0;
    $updated  = 0;
    $processedCodes = [];

    $koneksi->begin_transaction();

    try {
        foreach ($items as $idx => $it) {
            $rawKode = trim($it['kode_barang'] ?? '');
            $uraian  = trim($it['uraian'] ?? '');
            $hs      = preg_replace('/[^0-9]/', '', $it['pos_tarif'] ?? '');
            $satuan  = strtoupper(trim($it['kode_satuan'] ?? 'PCE'));
            $harga   = floatval($it['unit_price'] ?? 0);
            $klas    = trim($it['klas'] ?? '');

            // 1. VALIDASI MUTLAK: DILARANG KERAS KODE '-' ATAU KOSONG
            if ($rawKode === '-' || $rawKode === '') {
                throw new Exception("Baris #" . ($idx + 1) . " ('$uraian'): DILARANG KERAS Kode Barang bernilai '-' atau kosong! Wajib tentukan kode barang baru sebelum menyimpan.");
            }

            // 2. FORMAT KODE: Huruf Kapital (Default) vs Allow Huruf Kecil
            $kode = $allowLowercase ? $rawKode : strtoupper($rawKode);

            // Validasi keunikan dalam batch
            $normKey = strtoupper($kode);
            if (isset($processedCodes[$normKey])) {
                // Lewati duplikasi dalam batch pengiriman yang sama
                continue;
            }
            $processedCodes[$normKey] = true;

            // 3. VALIDASI URAIAN
            if (empty($uraian)) {
                throw new Exception("Baris #" . ($idx + 1) . " (Kode: $kode): Uraian barang wajib diisi.");
            }

            // 4. VALIDASI MUTLAK: KLAS TIDAK BOLEH KOSONG ATAU '-'
            if (empty($klas) || $klas === '-' || !isset($validKlasMap[$klas])) {
                throw new Exception("Baris #" . ($idx + 1) . " (Kode: $kode): Klasifikasi (KLAS) '$klas' tidak valid atau kosong! Pilih klasifikasi resmi dari daftar referensi.");
            }

            $escKode   = $koneksi->real_escape_string($kode);
            $escUraian = $koneksi->real_escape_string($uraian);
            $escHs     = $koneksi->real_escape_string($hs);
            $escSat    = $koneksi->real_escape_string($satuan);
            $escKlas   = $koneksi->real_escape_string($klas);

            // Cek apakah item sudah ada di bc_material
            $chk = $koneksi->query("SELECT 1 FROM bc_material WHERE KODE_BARANG = '$escKode' AND (cmp_id = '$escSessionCmp' OR cmp_id = '') LIMIT 1");
            $isExist = ($chk && $chk->num_rows > 0);

            if ($isExist) {
                // Update item yang sudah ada (hanya update kolom relevan)
                $sql = "UPDATE bc_material SET
                    URAIAN = '$escUraian',
                    POS_TARIF = IF('$escHs' != '', '$escHs', POS_TARIF),
                    KODE_SATUAN = IF('$escSat' != '', '$escSat', KODE_SATUAN),
                    UNIT_PRICE = IF($harga > 0, $harga, UNIT_PRICE),
                    KLAS = '$escKlas',
                    TGL_UPDATE = NOW()
                    WHERE KODE_BARANG = '$escKode' AND (cmp_id = '$escSessionCmp' OR cmp_id = '')";
                if (!$koneksi->query($sql)) {
                    throw new Exception("Gagal update data [$kode]: " . $koneksi->error);
                }
                $updated++;
            } else {
                // Insert item baru
                $sql = "INSERT INTO bc_material (
                    cmp_id, KODE_BARANG, URAIAN, POS_TARIF, KODE_SATUAN, UNIT_PRICE,
                    KODE_VALUTA, KLAS, AKTIF, TGL_REGISTER
                ) VALUES (
                    '$escSessionCmp', '$escKode', '$escUraian', '$escHs', '$escSat', $harga,
                    'IDR', '$escKlas', 'Y', NOW()
                )";
                if (!$koneksi->query($sql)) {
                    throw new Exception("Gagal insert item baru [$kode]: " . $koneksi->error);
                }
                $inserted++;
            }
        }

        $koneksi->commit();

        echo json_encode([
            'status'   => 'OK',
            'inserted' => $inserted,
            'updated'  => $updated,
            'total'    => ($inserted + $updated),
            'message'  => "Import berhasil disimpan! Berhasil menambahkan $inserted item baru dan memperbarui $updated item yang sudah ada."
        ]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode([
            'status'  => 'ERROR',
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// 7. AJAX: PINDAH KLASIFIKASI BARANG (SELECTED ATAU MASSAL) + SINKRONISASI KE bc_1ab2
if ($action === 'move_klas') {
    header('Content-Type: application/json; charset=utf-8');
    $mode       = trim($_POST['mode'] ?? 'selected'); // 'selected' atau 'bulk'
    $targetKlas = trim($_POST['target_klas'] ?? '');
    $sourceKlas = trim($_POST['source_klas'] ?? '');
    $sync1ab2   = (trim($_POST['sync_1ab2'] ?? '1') === '1');
    $itemsJson  = $_POST['items'] ?? '[]';
    $items      = json_decode($itemsJson, true);

    if (empty($targetKlas) || $targetKlas === '-') {
        echo json_encode(['status' => 'ERROR', 'message' => 'Klasifikasi tujuan wajib dipilih.']);
        exit;
    }

    $escTargetKlas = $koneksi->real_escape_string($targetKlas);
    $cmpFilterMat  = !empty($sessionCmpId) ? "AND (cmp_id = '$escSessionCmp' OR cmp_id = '')" : "";
    $cmpFilter1ab2 = !empty($sessionCmpId) ? "AND (cmp_id = '$escSessionCmp' OR cmp_id = '')" : "";

    $cntMat  = 0;
    $cnt1ab2 = 0;

    if ($mode === 'selected') {
        if (!is_array($items) || empty($items)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Silakan pilih setidaknya satu item barang yang akan dipindahkan klasifikasinya.']);
            exit;
        }

        $escItems = [];
        foreach ($items as $it) {
            $c = trim((string)$it);
            if (!empty($c) && $c !== '-') {
                $escItems[] = "'" . $koneksi->real_escape_string($c) . "'";
            }
        }

        if (empty($escItems)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Daftar kode barang tidak valid.']);
            exit;
        }

        $inList = implode(',', $escItems);

        // 1. Update bc_material
        $koneksi->query("UPDATE bc_material SET KLAS = '$escTargetKlas', TGL_UPDATE = NOW() WHERE KODE_BARANG IN ($inList) $cmpFilterMat");
        $cntMat = max(0, (int)$koneksi->affected_rows);

        // 2. Sinkronkan ke bc_1ab2 jika dipilih
        if ($sync1ab2) {
            $koneksi->query("UPDATE bc_1ab2 SET KLAS = '$escTargetKlas' WHERE KODE_BARANG IN ($inList) $cmpFilter1ab2");
            $cnt1ab2 = max(0, (int)$koneksi->affected_rows);
        }

        echo json_encode([
            'status' => 'OK',
            'count_material' => $cntMat,
            'count_1ab2' => $cnt1ab2,
            'message' => "Berhasil memindahkan $cntMat item Master Data Barang ke '$targetKlas'" . ($sync1ab2 ? " dan menyinkronkan $cnt1ab2 baris data di IT Inventory!" : "!")
        ]);
        exit;
    } elseif ($mode === 'bulk') {
        if (empty($sourceKlas) || $sourceKlas === '-') {
            echo json_encode(['status' => 'ERROR', 'message' => 'Klasifikasi asal wajib dipilih.']);
            exit;
        }
        if ($sourceKlas === $targetKlas) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Klasifikasi asal dan tujuan tidak boleh sama.']);
            exit;
        }

        $escSourceKlas = $koneksi->real_escape_string($sourceKlas);

        // 1. Update bc_material
        if ($sourceKlas === '(Kosong)') {
            $koneksi->query("UPDATE bc_material SET KLAS = '$escTargetKlas', TGL_UPDATE = NOW() WHERE (KLAS IS NULL OR TRIM(KLAS) = '' OR TRIM(KLAS) = '-') $cmpFilterMat");
        } else {
            $koneksi->query("UPDATE bc_material SET KLAS = '$escTargetKlas', TGL_UPDATE = NOW() WHERE KLAS = '$escSourceKlas' $cmpFilterMat");
        }
        $cntMat = max(0, (int)$koneksi->affected_rows);

        // 2. Sinkronkan ke bc_1ab2 jika dipilih
        if ($sync1ab2) {
            if ($sourceKlas === '(Kosong)') {
                $koneksi->query("UPDATE bc_1ab2 SET KLAS = '$escTargetKlas' WHERE (KLAS IS NULL OR TRIM(KLAS) = '' OR TRIM(KLAS) = '-') $cmpFilter1ab2");
            } else {
                $koneksi->query("UPDATE bc_1ab2 SET KLAS = '$escTargetKlas' WHERE KLAS = '$escSourceKlas' $cmpFilter1ab2");
            }
            $cnt1ab2 = max(0, (int)$koneksi->affected_rows);
        }

        echo json_encode([
            'status' => 'OK',
            'count_material' => $cntMat,
            'count_1ab2' => $cnt1ab2,
            'message' => "Berhasil memindahkan massal $cntMat item dari '$sourceKlas' ke '$targetKlas'" . ($sync1ab2 ? " dan menyinkronkan $cnt1ab2 baris data di IT Inventory!" : "!")
        ]);
        exit;
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Mode pindah tidak valid.']);
        exit;
    }
}

// 8. AJAX: SINKRONKAN SEMUA KLAS DARI bc_material KE bc_1ab2
if ($action === 'sync_all_klas_to_1ab2') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpFilterMat  = !empty($sessionCmpId) ? "AND (m.cmp_id = '$escSessionCmp' OR m.cmp_id = '')" : "";
    $cmpFilter1ab2 = !empty($sessionCmpId) ? "AND (t.cmp_id = '$escSessionCmp' OR t.cmp_id = '')" : "";

    $sqlSync = "UPDATE bc_1ab2 t
                JOIN bc_material m ON t.KODE_BARANG = m.KODE_BARANG AND (t.cmp_id = m.cmp_id OR t.cmp_id = '' OR m.cmp_id = '')
                SET t.KLAS = m.KLAS
                WHERE 1=1 $cmpFilterMat $cmpFilter1ab2
                  AND (t.KLAS != m.KLAS OR t.KLAS IS NULL OR TRIM(t.KLAS) = '' OR TRIM(t.KLAS) = '-')";
    $ok = $koneksi->query($sqlSync);
    if ($ok) {
        $updatedCount = max(0, (int)$koneksi->affected_rows);
        echo json_encode([
            'status' => 'OK',
            'updated_count' => $updatedCount,
            'message' => "Sinkronisasi selesai! Sebanyak $updatedCount baris data transaksi di IT Inventory telah diselaraskan dengan Klasifikasi Master Barang."
        ]);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal melakukan sinkronisasi: ' . $koneksi->error]);
    }
    exit;
}

// 9. AJAX: AMBIL RINGKASAN KLASIFIKASI DARI bc_material
if ($action === 'get_klas_summary') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpFilterMat = !empty($sessionCmpId) ? "WHERE (cmp_id = '$escSessionCmp' OR cmp_id = '')" : "";
    $q = $koneksi->query("SELECT IFNULL(NULLIF(TRIM(KLAS), ''), '(Kosong)') AS klas_name, COUNT(1) AS cnt 
                          FROM bc_material $cmpFilterMat 
                          GROUP BY KLAS ORDER BY cnt DESC, KLAS ASC");
    $summary = [];
    if ($q) {
        while ($r = $q->fetch_assoc()) {
            $summary[] = $r;
        }
    }
    echo json_encode(['status' => 'OK', 'data' => $summary]);
    exit;
}

// 10. AJAX: PREVIEW DAMPAK MERGE KODE BARANG
if ($action === 'preview_merge') {
    header('Content-Type: application/json; charset=utf-8');
    $targetCode   = trim($_REQUEST['target_code'] ?? '');
    $oldCodesJson = $_REQUEST['old_codes'] ?? '[]';
    $oldCodes     = json_decode($oldCodesJson, true);
    if (!is_array($oldCodes)) $oldCodes = [];

    $cleanOldCodes = [];
    foreach ($oldCodes as $c) {
        $trimmed = trim((string)$c);
        if (!empty($trimmed) && strcasecmp($trimmed, $targetCode) !== 0) {
            $cleanOldCodes[] = $trimmed;
        }
    }
    $cleanOldCodes = array_values(array_unique($cleanOldCodes));

    $cmpFilterMat   = !empty($sessionCmpId) ? "AND (cmp_id = '$escSessionCmp' OR cmp_id = '')" : "";
    $cmpFilter1ab2  = !empty($sessionCmpId) ? "AND (cmp_id = '$escSessionCmp' OR cmp_id = '')" : "";
    $cmpFilterTrace = !empty($sessionCmpId) ? "AND (cmp_id = '$escSessionCmp' OR cmp_id = '')" : "";
    $cmpFilterSo    = !empty($sessionCmpId) ? "AND (cmp_id = '$escSessionCmp' OR cmp_id = '')" : "";

    $escTarget    = $koneksi->real_escape_string($targetCode);
    $targetExists = false;
    $targetUraian = '';
    if (!empty($targetCode)) {
        $chkTarget = $koneksi->query("SELECT URAIAN FROM bc_material WHERE KODE_BARANG = '$escTarget' $cmpFilterMat LIMIT 1");
        if ($chkTarget && $rT = $chkTarget->fetch_assoc()) {
            $targetExists = true;
            $targetUraian = $rT['URAIAN'] ?? '';
        }
    }

    if (empty($cleanOldCodes)) {
        echo json_encode([
            'status'        => 'OK',
            'target_code'   => $targetCode,
            'target_exists' => $targetExists,
            'target_uraian' => $targetUraian,
            'old_codes'     => [],
            'counts'        => [
                '1ab2'        => 0,
                'tracebb'     => 0,
                'issue_dtl'   => 0,
                '1ab2_revisi' => 0,
                'so'          => 0,
                'material'    => 0
            ]
        ]);
        exit;
    }

    $escOldList = array_map(function($c) use ($koneksi) {
        return "'" . $koneksi->real_escape_string($c) . "'";
    }, $cleanOldCodes);
    $inOldList = implode(',', $escOldList);

    $cnt1ab2    = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE KODE_BARANG IN ($inOldList) $cmpFilter1ab2")->fetch_row()[0] ?? 0;
    $cntTrace   = $koneksi->query("SELECT COUNT(1) FROM bc_ceisa_tracebb WHERE kode_brg IN ($inOldList) $cmpFilterTrace")->fetch_row()[0] ?? 0;
    $cntIssue   = $koneksi->query("SELECT COUNT(1) FROM bc_issue_dtl WHERE kode_barang IN ($inOldList)")->fetch_row()[0] ?? 0;
    $cnt1ab2Rev = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2_revisi WHERE KODE_BARANG IN ($inOldList) $cmpFilter1ab2")->fetch_row()[0] ?? 0;
    $cntSo      = $koneksi->query("SELECT COUNT(1) FROM bc_so WHERE kode_barang IN ($inOldList) $cmpFilterSo")->fetch_row()[0] ?? 0;
    $cntSo0     = $koneksi->query("SELECT COUNT(1) FROM bc_so0 WHERE kode_barang IN ($inOldList) $cmpFilterSo")->fetch_row()[0] ?? 0;
    $cntMat     = $koneksi->query("SELECT COUNT(1) FROM bc_material WHERE KODE_BARANG IN ($inOldList) $cmpFilterMat")->fetch_row()[0] ?? 0;

    echo json_encode([
        'status'        => 'OK',
        'target_code'   => $targetCode,
        'target_exists' => $targetExists,
        'target_uraian' => $targetUraian,
        'old_codes'     => $cleanOldCodes,
        'counts'        => [
            '1ab2'        => (int)$cnt1ab2,
            'tracebb'     => (int)$cntTrace,
            'issue_dtl'   => (int)$cntIssue,
            '1ab2_revisi' => (int)$cnt1ab2Rev,
            'so'          => (int)($cntSo + $cntSo0),
            'material'    => (int)$cntMat
        ]
    ]);
    exit;
}

// 11. AJAX: EKSEKUSI FIX/MERGE KODE DOBEL BARANG
if ($action === 'merge_kode') {
    header('Content-Type: application/json; charset=utf-8');

    $targetCode   = trim($_POST['target_code'] ?? '');
    $oldCodesJson = $_POST['old_codes'] ?? '[]';
    $oldCodes     = json_decode($oldCodesJson, true);
    if (!is_array($oldCodes)) $oldCodes = [];

    // Filter kode lama agar tidak menyertakan kode target
    $cleanOldCodes = [];
    foreach ($oldCodes as $c) {
        $trimmed = trim((string)$c);
        if (!empty($trimmed) && strcasecmp($trimmed, $targetCode) !== 0) {
            $cleanOldCodes[] = $trimmed;
        }
    }
    $cleanOldCodes = array_values(array_unique($cleanOldCodes));

    if (empty($targetCode)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kode target (kode utama baru) wajib ditentukan.']);
        exit;
    }
    if (empty($cleanOldCodes)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Silakan tentukan minimal 1 kode lama yang akan digabungkan ke kode target.']);
        exit;
    }

    $escTarget = $koneksi->real_escape_string($targetCode);
    $escOldList = array_map(function($c) use ($koneksi) {
        return "'" . $koneksi->real_escape_string($c) . "'";
    }, $cleanOldCodes);
    $inOldList = implode(',', $escOldList);

    $cmpFilterMat   = !empty($sessionCmpId) ? "AND (cmp_id = '$escSessionCmp' OR cmp_id = '')" : "";
    $cmpFilter1ab2  = !empty($sessionCmpId) ? "AND (cmp_id = '$escSessionCmp' OR cmp_id = '')" : "";
    $cmpFilterTrace = !empty($sessionCmpId) ? "AND (cmp_id = '$escSessionCmp' OR cmp_id = '')" : "";
    $cmpFilterSo    = !empty($sessionCmpId) ? "AND (cmp_id = '$escSessionCmp' OR cmp_id = '')" : "";

    $sync1ab2    = (trim($_POST['sync_1ab2'] ?? '1') === '1');
    $syncTrace   = (trim($_POST['sync_trace'] ?? '1') === '1');
    $syncIssue   = (trim($_POST['sync_issue'] ?? '1') === '1');
    $syncSo      = (trim($_POST['sync_so'] ?? '1') === '1');
    $cleanMaster = (trim($_POST['clean_master'] ?? '1') === '1');

    $koneksi->begin_transaction();
    try {
        $cnt1ab2     = 0;
        $cntTrace    = 0;
        $cntIssue    = 0;
        $cnt1ab2Rev  = 0;
        $cntSo       = 0;
        $cntSo0      = 0;
        $cntMatClean = 0;

        // 1. UPDATE bc_1ab2
        if ($sync1ab2) {
            $koneksi->query("UPDATE bc_1ab2 SET KODE_BARANG = '$escTarget' WHERE KODE_BARANG IN ($inOldList) $cmpFilter1ab2");
            $cnt1ab2 = max(0, (int)$koneksi->affected_rows);
        }

        // 2. UPDATE bc_ceisa_tracebb
        if ($syncTrace) {
            $koneksi->query("UPDATE bc_ceisa_tracebb SET kode_brg = '$escTarget' WHERE kode_brg IN ($inOldList) $cmpFilterTrace");
            $cntTrace = max(0, (int)$koneksi->affected_rows);
        }

        // 3. UPDATE bc_issue_dtl
        if ($syncIssue) {
            $koneksi->query("UPDATE bc_issue_dtl SET kode_barang = '$escTarget' WHERE kode_barang IN ($inOldList)");
            $cntIssue = max(0, (int)$koneksi->affected_rows);
        }

        // 4. UPDATE bc_1ab2_revisi, bc_so, bc_so0
        if ($syncSo) {
            $koneksi->query("UPDATE bc_1ab2_revisi SET KODE_BARANG = '$escTarget' WHERE KODE_BARANG IN ($inOldList) $cmpFilter1ab2");
            $cnt1ab2Rev = max(0, (int)$koneksi->affected_rows);

            $koneksi->query("UPDATE bc_so SET kode_barang = '$escTarget' WHERE kode_barang IN ($inOldList) $cmpFilterSo");
            $cntSo = max(0, (int)$koneksi->affected_rows);

            $koneksi->query("UPDATE bc_so0 SET kode_barang = '$escTarget' WHERE kode_barang IN ($inOldList) $cmpFilterSo");
            $cntSo0 = max(0, (int)$koneksi->affected_rows);
        }

        // 5. UPDATE / BERSIHKAN MASTER DATA BARANG (bc_material)
        if ($cleanMaster) {
            $chkTarget = $koneksi->query("SELECT 1 FROM bc_material WHERE KODE_BARANG = '$escTarget' $cmpFilterMat LIMIT 1");
            $targetExists = ($chkTarget && $chkTarget->num_rows > 0);

            if ($targetExists) {
                // Target sudah ada, hapus kode lama dari master sehingga tidak dobel
                $koneksi->query("DELETE FROM bc_material WHERE KODE_BARANG IN ($inOldList) $cmpFilterMat");
                $cntMatClean = max(0, (int)$koneksi->affected_rows);
            } else {
                // Target belum ada di master: jadikan kode lama pertama sebagai target
                $escFirstOld = $koneksi->real_escape_string($cleanOldCodes[0]);
                $koneksi->query("UPDATE bc_material SET KODE_BARANG = '$escTarget', TGL_UPDATE = NOW() WHERE KODE_BARANG = '$escFirstOld' $cmpFilterMat LIMIT 1");
                $cntMatClean = 1;

                // Jika ada lebih dari satu kode lama, hapus sisanya
                if (count($cleanOldCodes) > 1) {
                    $otherOlds = array_slice($cleanOldCodes, 1);
                    $escOthers = array_map(function($c) use ($koneksi) { return "'" . $koneksi->real_escape_string($c) . "'"; }, $otherOlds);
                    $inOthers  = implode(',', $escOthers);
                    $koneksi->query("DELETE FROM bc_material WHERE KODE_BARANG IN ($inOthers) $cmpFilterMat");
                    $cntMatClean += max(0, (int)$koneksi->affected_rows);
                }
            }
        }

        $koneksi->commit();

        $msgParts = [];
        $msgParts[] = "Berhasil menggabungkan " . count($cleanOldCodes) . " kode barang lama ke '$targetCode':";
        if ($sync1ab2)    $msgParts[] = "• $cnt1ab2 baris data di IT Inventory diperbarui.";
        if ($syncTrace)   $msgParts[] = "• $cntTrace baris data di Traceability Bahan Baku diperbarui.";
        if ($syncIssue)   $msgParts[] = "• $cntIssue baris data di Pengeluaran (Issue) diperbarui.";
        if ($syncSo && ($cntSo + $cntSo0 + $cnt1ab2Rev) > 0) {
            $msgParts[] = "• " . ($cntSo + $cntSo0 + $cnt1ab2Rev) . " baris data di Revisi & Stock Opname diperbarui.";
        }
        if ($cleanMaster) $msgParts[] = "• Master Data Barang diselaraskan ($cntMatClean kode lama dihapus/digabung).";

        echo json_encode([
            'status'      => 'OK',
            'target_code' => $targetCode,
            'old_codes'   => $cleanOldCodes,
            'counts'      => [
                '1ab2'        => $cnt1ab2,
                'tracebb'     => $cntTrace,
                'issue_dtl'   => $cntIssue,
                '1ab2_revisi' => $cnt1ab2Rev,
                'so'          => $cntSo + $cntSo0,
                'mat_clean'   => $cntMatClean
            ],
            'message'     => implode("\n", $msgParts)
        ]);
        exit;
    } catch (\Throwable $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal melakukan merge kode: ' . $e->getMessage()]);
        exit;
    }
}

// 12. AJAX: REPORT DATA DENGAN SATUAN BERBEDA
if ($action === 'get_diff_satuan_report') {
    header('Content-Type: application/json; charset=utf-8');

    $cmpFilterMat   = !empty($sessionCmpId) ? "AND (m.cmp_id = '$escSessionCmp' OR m.cmp_id = '')" : "";
    $cmpFilter1ab2  = !empty($sessionCmpId) ? "AND (t.cmp_id = '$escSessionCmp' OR t.cmp_id = '')" : "";
    $cmpFilterSo    = !empty($sessionCmpId) ? "AND (s.cmp_id = '$escSessionCmp' OR s.cmp_id = '')" : "";

    $report = [];
    $totalTrans1ab2 = 0;
    $distinctItems  = [];

    // 1. Data beda satuan dari bc_1ab2 (IT Inventory)
    $sql1ab2 = "SELECT 
        m.KODE_BARANG, m.URAIAN, m.KLAS, m.KODE_SATUAN AS SAT_MASTER,
        IFNULL(NULLIF(TRIM(t.UNIT), ''), '(Kosong)') AS SAT_TRANSAKSI,
        'IT Inventory' AS MODUL,
        'bc_1ab2' AS TABEL,
        COUNT(1) AS JML_TRANSAKSI
    FROM bc_material m
    JOIN bc_1ab2 t ON m.KODE_BARANG = t.KODE_BARANG AND (m.cmp_id = t.cmp_id OR m.cmp_id = '' OR t.cmp_id = '')
    WHERE (t.UNIT != m.KODE_SATUAN OR t.UNIT IS NULL OR TRIM(t.UNIT) = '' OR TRIM(t.UNIT) = '-')
      AND m.KODE_SATUAN IS NOT NULL AND TRIM(m.KODE_SATUAN) != '' AND TRIM(m.KODE_SATUAN) != '-'
    $cmpFilterMat $cmpFilter1ab2
    GROUP BY m.KODE_BARANG, m.URAIAN, m.KLAS, m.KODE_SATUAN, t.UNIT
    ORDER BY JML_TRANSAKSI DESC, m.KODE_BARANG ASC";

    $q1 = $koneksi->query($sql1ab2);
    if ($q1) {
        while ($r = $q1->fetch_assoc()) {
            $report[] = $r;
            $totalTrans1ab2 += (int)$r['JML_TRANSAKSI'];
            $distinctItems[$r['KODE_BARANG']] = true;
        }
    }

    // 2. Data beda satuan dari bc_issue_dtl (Pengeluaran Barang)
    $sqlIssue = "SELECT 
        m.KODE_BARANG, m.URAIAN, m.KLAS, m.KODE_SATUAN AS SAT_MASTER,
        IFNULL(NULLIF(TRIM(i.sat), ''), '(Kosong)') AS SAT_TRANSAKSI,
        'Pengeluaran' AS MODUL,
        'bc_issue_dtl' AS TABEL,
        COUNT(1) AS JML_TRANSAKSI
    FROM bc_material m
    JOIN bc_issue_dtl i ON m.KODE_BARANG = i.kode_barang
    WHERE (i.sat != m.KODE_SATUAN OR i.sat IS NULL OR TRIM(i.sat) = '' OR TRIM(i.sat) = '-')
      AND m.KODE_SATUAN IS NOT NULL AND TRIM(m.KODE_SATUAN) != '' AND TRIM(m.KODE_SATUAN) != '-'
    $cmpFilterMat
    GROUP BY m.KODE_BARANG, m.URAIAN, m.KLAS, m.KODE_SATUAN, i.sat
    ORDER BY JML_TRANSAKSI DESC, m.KODE_BARANG ASC";

    $totalTransIssue = 0;
    $qIssue = $koneksi->query($sqlIssue);
    if ($qIssue) {
        while ($r = $qIssue->fetch_assoc()) {
            $report[] = $r;
            $totalTransIssue += (int)$r['JML_TRANSAKSI'];
            $distinctItems[$r['KODE_BARANG']] = true;
        }
    }

    // 3. Data beda satuan dari bc_so (Stock Opname)
    $sqlSo = "SELECT 
        m.KODE_BARANG, m.URAIAN, m.KLAS, m.KODE_SATUAN AS SAT_MASTER,
        IFNULL(NULLIF(TRIM(s.unit), ''), '(Kosong)') AS SAT_TRANSAKSI,
        'Stock Opname' AS MODUL,
        'bc_so' AS TABEL,
        COUNT(1) AS JML_TRANSAKSI
    FROM bc_material m
    JOIN bc_so s ON m.KODE_BARANG = s.kode_barang AND (m.cmp_id = s.cmp_id OR m.cmp_id = '' OR s.cmp_id = '')
    WHERE (s.unit != m.KODE_SATUAN OR s.unit IS NULL OR TRIM(s.unit) = '' OR TRIM(s.unit) = '-')
      AND m.KODE_SATUAN IS NOT NULL AND TRIM(m.KODE_SATUAN) != '' AND TRIM(m.KODE_SATUAN) != '-'
    $cmpFilterMat $cmpFilterSo
    GROUP BY m.KODE_BARANG, m.URAIAN, m.KLAS, m.KODE_SATUAN, s.unit
    ORDER BY JML_TRANSAKSI DESC, m.KODE_BARANG ASC";

    $totalTransSo = 0;
    $qSo = $koneksi->query($sqlSo);
    if ($qSo) {
        while ($r = $qSo->fetch_assoc()) {
            $report[] = $r;
            $totalTransSo += (int)$r['JML_TRANSAKSI'];
            $distinctItems[$r['KODE_BARANG']] = true;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'data'   => $report,
        'kpi'    => [
            'total_items'       => count($distinctItems),
            'total_trans_1ab2'  => $totalTrans1ab2,
            'total_trans_issue' => $totalTransIssue,
            'total_trans_so'    => $totalTransSo,
            'grand_total_trans' => $totalTrans1ab2 + $totalTransIssue + $totalTransSo
        ]
    ]);
    exit;
}

// 13. AJAX: EKSEKUSI FIX / SINKRONISASI SATUAN KE bc_material
if ($action === 'sync_satuan') {
    header('Content-Type: application/json; charset=utf-8');

    $mode      = trim($_POST['mode'] ?? 'all'); // 'all' atau 'selected'
    $itemsJson = $_POST['items'] ?? '[]';
    $items     = json_decode($itemsJson, true);
    if (!is_array($items)) $items = [];

    $itemWhere1ab2  = "";
    $itemWhereIssue = "";
    $itemWhereSo    = "";
    $itemWhereTrace = "";

    if ($mode === 'selected') {
        if (empty($items)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Pilih setidaknya 1 item barang untuk diselaraskan satuannya.']);
            exit;
        }
        $escItems = [];
        foreach ($items as $it) {
            $c = trim((string)$it);
            if (!empty($c)) {
                $escItems[] = "'" . $koneksi->real_escape_string($c) . "'";
            }
        }
        if (empty($escItems)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Daftar kode barang tidak valid.']);
            exit;
        }
        $inList = implode(',', $escItems);
        $itemWhere1ab2  = "AND a.KODE_BARANG IN ($inList)";
        $itemWhereIssue = "AND a.kode_barang IN ($inList)";
        $itemWhereSo    = "AND a.kode_barang IN ($inList)";
        $itemWhereTrace = "AND a.kode_brg IN ($inList)";
    }

    $cmpFilterMat   = !empty($sessionCmpId) ? "AND (b.cmp_id = '$escSessionCmp' OR b.cmp_id = '')" : "";
    $cmpFilter1ab2  = !empty($sessionCmpId) ? "AND (a.cmp_id = '$escSessionCmp' OR a.cmp_id = '')" : "";
    $cmpFilterSo    = !empty($sessionCmpId) ? "AND (a.cmp_id = '$escSessionCmp' OR a.cmp_id = '')" : "";
    $cmpFilterTrace = !empty($sessionCmpId) ? "AND (a.cmp_id = '$escSessionCmp' OR a.cmp_id = '')" : "";

    $koneksi->begin_transaction();
    try {
        $cnt1ab2    = 0;
        $cnt1ab2Rev = 0;
        $cntIssue   = 0;
        $cntSo0     = 0;
        $cntSo      = 0;
        $cntTrace   = 0;

        // 1. UPDATE bc_1ab2 (IT Inventory)
        $q1 = $koneksi->query("UPDATE bc_1ab2 a
                               JOIN bc_material b ON a.KODE_BARANG = b.KODE_BARANG AND (a.cmp_id = b.cmp_id OR a.cmp_id = '' OR b.cmp_id = '')
                               SET a.UNIT = b.KODE_SATUAN
                               WHERE (a.UNIT != b.KODE_SATUAN OR a.UNIT IS NULL OR TRIM(a.UNIT) = '' OR TRIM(a.UNIT) = '-')
                                 AND b.KODE_SATUAN IS NOT NULL AND TRIM(b.KODE_SATUAN) != '' AND TRIM(b.KODE_SATUAN) != '-'
                                 $itemWhere1ab2 $cmpFilterMat $cmpFilter1ab2");
        $cnt1ab2 = max(0, (int)$koneksi->affected_rows);

        // 2. UPDATE bc_1ab2_revisi (Revisi IT Inventory)
        $q1Rev = $koneksi->query("UPDATE bc_1ab2_revisi a
                                  JOIN bc_material b ON a.KODE_BARANG = b.KODE_BARANG AND (a.cmp_id = b.cmp_id OR a.cmp_id = '' OR b.cmp_id = '')
                                  SET a.UNIT = b.KODE_SATUAN
                                  WHERE (a.UNIT != b.KODE_SATUAN OR a.UNIT IS NULL OR TRIM(a.UNIT) = '' OR TRIM(a.UNIT) = '-')
                                    AND b.KODE_SATUAN IS NOT NULL AND TRIM(b.KODE_SATUAN) != '' AND TRIM(b.KODE_SATUAN) != '-'
                                    $itemWhere1ab2 $cmpFilterMat $cmpFilter1ab2");
        $cnt1ab2Rev = max(0, (int)$koneksi->affected_rows);

        // 3. UPDATE bc_issue_dtl (Pengeluaran Barang)
        $qIss = $koneksi->query("UPDATE bc_issue_dtl a
                                 JOIN bc_material b ON a.kode_barang = b.KODE_BARANG
                                 SET a.sat = b.KODE_SATUAN
                                 WHERE (a.sat != b.KODE_SATUAN OR a.sat IS NULL OR TRIM(a.sat) = '' OR TRIM(a.sat) = '-')
                                   AND b.KODE_SATUAN IS NOT NULL AND TRIM(b.KODE_SATUAN) != '' AND TRIM(b.KODE_SATUAN) != '-'
                                   $itemWhereIssue $cmpFilterMat");
        $cntIssue = max(0, (int)$koneksi->affected_rows);

        // 4. UPDATE bc_so0 (Saldo Awal Stock Opname)
        $qSo0 = $koneksi->query("UPDATE bc_so0 a
                                 JOIN bc_material b ON a.kode_barang = b.KODE_BARANG AND (a.cmp_id = b.cmp_id OR a.cmp_id = '' OR b.cmp_id = '')
                                 SET a.unit = b.KODE_SATUAN
                                 WHERE (a.unit != b.KODE_SATUAN OR a.unit IS NULL OR TRIM(a.unit) = '' OR TRIM(a.unit) = '-')
                                   AND b.KODE_SATUAN IS NOT NULL AND TRIM(b.KODE_SATUAN) != '' AND TRIM(b.KODE_SATUAN) != '-'
                                   $itemWhereSo $cmpFilterMat $cmpFilterSo");
        $cntSo0 = max(0, (int)$koneksi->affected_rows);

        // 5. UPDATE bc_so (Stock Opname)
        $qSo = $koneksi->query("UPDATE bc_so a
                                JOIN bc_material b ON a.kode_barang = b.KODE_BARANG AND (a.cmp_id = b.cmp_id OR a.cmp_id = '' OR b.cmp_id = '')
                                SET a.unit = b.KODE_SATUAN
                                WHERE (a.unit != b.KODE_SATUAN OR a.unit IS NULL OR TRIM(a.unit) = '' OR TRIM(a.unit) = '-')
                                  AND b.KODE_SATUAN IS NOT NULL AND TRIM(b.KODE_SATUAN) != '' AND TRIM(b.KODE_SATUAN) != '-'
                                  $itemWhereSo $cmpFilterMat $cmpFilterSo");
        $cntSo = max(0, (int)$koneksi->affected_rows);

        // 6. UPDATE bc_ceisa_tracebb (Traceability Bahan Baku)
        $qTr = $koneksi->query("UPDATE bc_ceisa_tracebb a
                                JOIN bc_material b ON a.kode_brg = b.KODE_BARANG AND (a.cmp_id = b.cmp_id OR a.cmp_id = '' OR b.cmp_id = '')
                                SET a.sat = b.KODE_SATUAN
                                WHERE (a.sat != b.KODE_SATUAN OR a.sat IS NULL OR TRIM(a.sat) = '' OR TRIM(a.sat) = '-')
                                  AND b.KODE_SATUAN IS NOT NULL AND TRIM(b.KODE_SATUAN) != '' AND TRIM(b.KODE_SATUAN) != '-'
                                  $itemWhereTrace $cmpFilterMat $cmpFilterTrace");
        $cntTrace = max(0, (int)$koneksi->affected_rows);

        // 7. Dynamic check for optional tables
        $optionalTables = [
            ['tbl' => 'bc_pi_dtl', 'code' => 'kode_barang', 'unit' => 'sat'],
            ['tbl' => 'bc_si_dtl', 'code' => 'kode_barang', 'unit' => 'sat'],
            ['tbl' => 'bc_po_dtl', 'code' => 'kode_barang', 'unit' => 'sat'],
            ['tbl' => 'bc_do_dtl', 'code' => 'kode_barang', 'unit' => 'sat'],
            ['tbl' => 'bc_issue2', 'code' => 'kode_barang', 'unit' => 'sat'],
            ['tbl' => 'bc_issue_aju', 'code' => 'kode_brg', 'unit' => 'sat'],
        ];
        $cntOpt = 0;
        foreach ($optionalTables as $opt) {
            $chk = $koneksi->query("SHOW TABLES LIKE '{$opt['tbl']}'");
            if ($chk && $chk->num_rows > 0) {
                $codeCol = $opt['code'];
                $unitCol = $opt['unit'];
                $tblName = $opt['tbl'];
                $itemWhereOpt = ($mode === 'selected') ? "AND a.`$codeCol` IN ($inList)" : "";
                $koneksi->query("UPDATE `$tblName` a 
                                 JOIN bc_material b ON a.`$codeCol` = b.KODE_BARANG 
                                 SET a.`$unitCol` = b.KODE_SATUAN 
                                 WHERE (a.`$unitCol` != b.KODE_SATUAN OR a.`$unitCol` IS NULL OR TRIM(a.`$unitCol`) = '' OR TRIM(a.`$unitCol`) = '-')
                                   AND b.KODE_SATUAN IS NOT NULL AND TRIM(b.KODE_SATUAN) != ''
                                   $itemWhereOpt $cmpFilterMat");
                $cntOpt += max(0, (int)$koneksi->affected_rows);
            }
        }

        $koneksi->commit();

        $totalUpdated = $cnt1ab2 + $cnt1ab2Rev + $cntIssue + $cntSo0 + $cntSo + $cntTrace + $cntOpt;

        $msgList = [];
        $msgList[] = "Sinkronisasi Satuan Berhasil! Total $totalUpdated baris data telah diselaraskan dengan Satuan Master Data Barang:";
        if ($cnt1ab2 > 0)    $msgList[] = "• $cnt1ab2 baris di IT Inventory";
        if ($cnt1ab2Rev > 0) $msgList[] = "• $cnt1ab2Rev baris di Revisi IT Inventory";
        if ($cntIssue > 0)   $msgList[] = "• $cntIssue baris di Pengeluaran (Issue)";
        if ($cntSo + $cntSo0 > 0) $msgList[] = "• " . ($cntSo + $cntSo0) . " baris di Stock Opname";
        if ($cntTrace > 0)   $msgList[] = "• $cntTrace baris di Traceability Bahan Baku";
        if ($cntOpt > 0)     $msgList[] = "• $cntOpt baris di modul lainnya";
        if ($totalUpdated === 0) {
            $msgList[] = "(Seluruh transaksi sudah sesuai dengan satuan master barang)";
        }

        echo json_encode([
            'status'        => 'OK',
            'total_updated' => $totalUpdated,
            'counts'        => [
                '1ab2'        => $cnt1ab2,
                '1ab2_revisi' => $cnt1ab2Rev,
                'issue'       => $cntIssue,
                'so'          => $cntSo + $cntSo0,
                'trace'       => $cntTrace,
                'other'       => $cntOpt
            ],
            'message'       => implode("\n", $msgList)
        ]);
        exit;
    } catch (\Throwable $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyelaraskan satuan: ' . $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Master Data Barang<?= $isAdmin ? ' (bc_material)' : '' ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Source+Sans+Pro:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        :root {
            --bg-base: #f0f2f5;
            --bg-card: #ffffff;
            --border-color: #e8e8e8;
            --text-main: #262626;
            --text-muted: #8c8c8c;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --success: #52c41a;
            --warning: #faad14;
            --danger: #ff4d4f;
            --table-hover: #f0f7ff;
        }
        [data-theme='dark'] {
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --border-color: #303030;
            --text-main: rgba(255, 255, 255, 0.85);
            --text-muted: rgba(255, 255, 255, 0.45);
            --primary: #177ddc;
            --primary-hover: #1890ff;
            --success: #49aa19;
            --warning: #d89614;
            --danger: #a61d24;
            --table-hover: #262638;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', 'Source Sans Pro', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: var(--bg-base);
            color: var(--text-main);
            font-size: 10.5px;
            line-height: 1.45;
            padding: 6px 10px;
            -webkit-font-smoothing: antialiased;
        }
        .ant-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            padding: 6px 10px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        }
        .ant-card-head {
            min-height: 36px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 6px;
            margin-bottom: 8px;
            flex-wrap: wrap;
            gap: 6px;
        }
        .ant-card-title { font-size: 12px; font-weight: 600; color: var(--text-main); display: flex; align-items: center; gap: 6px; }
        .ant-toolbar { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
        .ant-btn {
            font-family: inherit; font-size: 10px; height: 22px; padding: 0 7px; border-radius: 2px;
            border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main);
            cursor: pointer; display: inline-flex; align-items: center; gap: 4px; font-weight: 500; line-height: 1.4;
            transition: all .2s;
        }
        .ant-btn:hover { border-color: var(--primary); color: var(--primary); }
        .ant-btn-primary { background: var(--primary); color: #fff; border-color: var(--primary); }
        .ant-btn-primary:hover { background: var(--primary-hover); border-color: var(--primary-hover); color:#fff; }
        .ant-btn-success { background: var(--success); color: #fff; border-color: var(--success); }
        .ant-btn-success:hover { background: #49ad16; border-color: #49ad16; color:#fff; }
        .ant-btn-sm { height: 18px; padding: 0 5px; font-size: 9px; border-radius: 2px; }
        
        .filter-panel {
            background: var(--bg-base);
            border: 1px solid var(--border-color);
            border-radius: 2px;
            padding: 5px 8px;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            font-size: 10px;
        }
        .filter-panel label { font-size: 9px; font-weight: 600; text-transform: uppercase; color: var(--text-muted); }
        .form-control {
            font-family: inherit; font-size: 9.5px; height: 22px; padding: 1px 6px; border-radius: 2px;
            border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main); outline: none;
        }
        .form-control:focus { border-color: var(--primary); }

        .kpi-container {
            display: flex;
            gap: 6px;
            margin-bottom: 6px;
            flex-wrap: wrap;
        }
        .kpi-badge {
            background: var(--bg-base);
            border: 1px solid var(--border-color);
            border-radius: 2px;
            padding: 2px 8px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 9.5px;
        }
        .kpi-title { font-size: 9px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; }
        .kpi-val { font-size: 11px; font-weight: 700; color: var(--text-main); }

        .table-responsive { max-height: calc(100vh - 170px); overflow: auto; border: 1px solid var(--border-color); border-radius: 3px; }
        .ant-table { width: 100%; border-collapse: collapse; font-size: 10px; text-align: left; }
        .ant-table th {
            position: sticky; top: 0; background: var(--bg-base); border-bottom: 1px solid var(--border-color);
            padding: 4px 6px; font-size: 9.5px; font-weight: 600; white-space: nowrap; z-index: 2;
        }
        .ant-table td { padding: 3px 6px; border-bottom: 1px solid var(--border-color); white-space: nowrap; vertical-align: middle; }
        .ant-table tr:hover td { background-color: var(--table-hover); }
        .tag-badge { display: inline-block; padding: 1px 5px; border-radius: 2px; font-size: 9px; font-weight: 600; }
        .tag-blue { background: rgba(24, 144, 255, 0.12); color: var(--primary); }
        .input-error { border: 1.5px solid #ff4d4f !important; background: #fff2f0 !important; }
        .ant-badge { display: inline-flex; align-items: center; gap: 3px; font-size: 9px; font-weight: 600; padding: 1px 6px; border-radius: 2px; }

        /* Modal */
        .modal {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.45); z-index: 1000; align-items: center; justify-content: center;
        }
        .modal-content {
            background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 4px;
            width: 90%; max-width: 760px; max-height: 90vh; display: flex; flex-direction: column;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .modal-header { padding: 8px 12px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; font-size: 11.5px; font-weight: 600; }
        .modal-body { padding: 10px 14px; overflow-y: auto; flex: 1; }
        .modal-footer { padding: 6px 12px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 6px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .form-group { display: flex; flex-direction: column; gap: 2px; }
        .form-group label { font-size: 9.5px; font-weight: 600; color: var(--text-muted); }
        .dropzone {
            border: 2px dashed var(--border-color); border-radius: 4px; padding: 18px; text-align: center;
            cursor: pointer; background: var(--bg-base); transition: 0.2s; margin-bottom: 8px; font-size: 10.5px;
        }
        .dropzone:hover { border-color: var(--primary); }
        .merge-chip {
            display: inline-flex; align-items: center; gap: 4px;
            background: rgba(196, 29, 127, 0.1); border: 1px solid rgba(196, 29, 127, 0.3);
            color: #c41d7f; font-family: monospace; font-size: 11px; font-weight: 600;
            padding: 2px 7px; border-radius: 12px;
        }
        .merge-chip i { cursor: pointer; opacity: 0.7; }
        .merge-chip i:hover { opacity: 1; color: #ff4d4f; }
    </style>
</head>
<body>
    <div class="ant-card">
        <div class="ant-card-head">
            <div class="ant-card-title">
                <i class="fa-solid fa-boxes-stacked" style="color:var(--primary);"></i> Master Data Barang<?= $isAdmin ? ' <span style="font-size:11px; font-weight:normal; opacity:0.8;">(bc_material)</span>' : '' ?>
            </div>
            <div class="ant-toolbar">
                <button type="button" class="ant-btn ant-btn-primary" onclick="openAddModal()">
                    <i class="fa-solid fa-plus"></i> Tambah Item Baru
                </button>
                <button type="button" class="ant-btn ant-btn-success" onclick="openImportModal()">
                    <i class="fa-solid fa-file-excel"></i> Import Excel
                </button>
                <button type="button" class="ant-btn" style="background:#722ed1; color:#fff; border-color:#722ed1;" onclick="openDbImportModal('1ab2')">
                    <i class="fa-solid fa-warehouse"></i> Import IT Inventory<?= $isAdmin ? ' (bc_1ab2)' : '' ?>
                </button>
                <button type="button" class="ant-btn" style="background:#13c2c2; color:#fff; border-color:#13c2c2;" onclick="openDbImportModal('staging')">
                    <i class="fa-solid fa-file-invoice"></i> Import Staging Pabean<?= $isAdmin ? ' (bc_ceisa_barang)' : '' ?>
                </button>
                <button type="button" class="ant-btn" style="background:#fa8c16; color:#fff; border-color:#fa8c16;" onclick="openKoreksiKlas()">
                    <i class="fa-solid fa-triangle-exclamation"></i> Koreksi Klasifikasi IT Inv<?= $isAdmin ? ' (KLAS)' : '' ?> <?= $cntInvalidKlas > 0 ? "($cntInvalidKlas)" : '' ?>
                </button>
                <button type="button" class="ant-btn" style="background:#531dab; color:#fff; border-color:#531dab;" onclick="openPindahKlasModal()" title="Pindahkan klasifikasi barang yang salah ke kategori lain dan sinkronkan ke IT Inventory">
                    <i class="fa-solid fa-arrows-split-up-and-left"></i> Pindah Klasifikasi <span id="badgeSelectedMat" style="display:none; background:#ff4d4f; color:#fff; font-weight:700; border-radius:10px; padding:0 6px; font-size:10px; margin-left:4px;">0</span>
                </button>
                <button type="button" class="ant-btn" style="background:#096dd9; color:#fff; border-color:#096dd9;" onclick="syncAllKlasTo1ab2()" title="Sinkronkan seluruh klasifikasi master barang ke transaksi IT Inventory">
                    <i class="fa-solid fa-rotate"></i> Sync KLAS ke IT Inv<?= $isAdmin ? ' (bc_1ab2)' : '' ?>
                </button>
                <button type="button" class="ant-btn" style="background:#c41d7f; color:#fff; border-color:#c41d7f;" onclick="openMergeKodeModal()" title="Gabungkan / replace beberapa kode barang ganda menjadi 1 kode utama dan perbarui seluruh transaksi IT Inventory, Traceability, & Pengeluaran">
                    <i class="fa-solid fa-code-merge"></i> Fix/Merge Kode Dobel <span id="badgeMergeMat" style="display:none; background:#ff4d4f; color:#fff; font-weight:700; border-radius:10px; padding:0 6px; font-size:10px; margin-left:4px;">0</span>
                </button>
                <button type="button" class="ant-btn" style="background:#08979c; color:#fff; border-color:#08979c;" onclick="openFixSatuanModal()" title="Cek laporan perbedaan satuan antara Master Barang dan transaksi IT Inventory / Pengeluaran, lalu selaraskan secara otomatis">
                    <i class="fa-solid fa-scale-balanced"></i> Fix Satuan Beda <span id="badgeDiffSatuan" style="<?= $cntDiffSatuan > 0 ? '' : 'display:none;' ?> background:#ff4d4f; color:#fff; font-weight:700; border-radius:10px; padding:0 6px; font-size:10px; margin-left:4px;"><?= $cntDiffSatuan ?></span>
                </button>
                <button type="button" class="ant-btn" onclick="exportExcel()">
                    <i class="fa-solid fa-file-export"></i> Ekspor Excel
                </button>
                <button type="button" class="ant-btn" onclick="loadData()">
                    <i class="fa-solid fa-rotate"></i> Muat Ulang
                </button>
            </div>
        </div>

        <?php if ($cntInvalidKlas > 0): ?>
        <div style="background:#fffbe6; border:1px solid #ffe58f; border-radius:3px; padding:6px 12px; margin-bottom:8px; display:flex; align-items:center; justify-content:space-between; font-size:10.5px;">
            <div style="display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-triangle-exclamation" style="color:#faad14; font-size:14px;"></i>
                <div>
                    <strong>Perhatian:</strong> Ditemukan <b><?= $cntInvalidKlas ?></b> baris data transaksi di IT Inventory<?= $isAdmin ? ' (<code>bc_1ab2</code>)' : '' ?> dengan Klasifikasi<?= $isAdmin ? ' (KLAS)' : '' ?> kosong, bernilai <code>'-'</code>, atau tidak sesuai referensi.
                </div>
            </div>
            <button type="button" onclick="openKoreksiKlas()" class="ant-btn ant-btn-sm" style="background:#fa8c16; color:#fff; border-color:#fa8c16; font-weight:600;">
                <i class="fa-solid fa-pen-to-square"></i> Buka Halaman Koreksi Klasifikasi IT Inv &raquo;
            </button>
        </div>
        <?php endif; ?>

        <?php if ($cntDiffSatuan > 0): ?>
        <div style="background:#e6fffb; border:1px solid #87e8de; border-radius:3px; padding:6px 12px; margin-bottom:8px; display:flex; align-items:center; justify-content:space-between; font-size:10.5px;">
            <div style="display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-scale-balanced" style="color:#08979c; font-size:14px;"></i>
                <div>
                    <strong>Perhatian:</strong> Ditemukan <b><?= $cntDiffSatuan ?></b> item barang dengan Satuan Transaksi di IT Inventory<?= $isAdmin ? ' (<code>bc_1ab2</code>)' : '' ?> berbeda dari Satuan Master Barang<?= $isAdmin ? ' (<code>bc_material</code>)' : '' ?>.
                </div>
            </div>
            <button type="button" onclick="openFixSatuanModal()" class="ant-btn ant-btn-sm" style="background:#08979c; color:#fff; border-color:#08979c; font-weight:600;">
                <i class="fa-solid fa-scale-balanced"></i> Buka Report &amp; Selaraskan Satuan (<?= $cntDiffSatuan ?>) &raquo;
            </button>
        </div>
        <?php endif; ?>

        <!-- Filter Bar -->
        <div class="filter-panel">
            <div style="display:flex; align-items:center; gap:6px;">
                <label style="font-weight:600; font-size:11.5px;"><i class="fa-solid fa-magnifying-glass"></i> Cari:</label>
                <input type="text" id="filterSearch" class="form-control" style="width:200px;" placeholder="Kode Barang, Uraian, HS..." onkeyup="if(event.key==='Enter') loadData()">
            </div>
            <div style="display:flex; align-items:center; gap:6px;">
                <label style="font-weight:600; font-size:11.5px;"><i class="fa-solid fa-tags"></i> Klasifikasi:</label>
                <select id="filterKlas" class="form-control" onchange="loadData()">
                    <option value="">-- SEMUA KLAS --</option>
                    <?php foreach ($klasList as $k): ?>
                        <option value="<?= htmlspecialchars($k['tipe']) ?>"><?= htmlspecialchars($k['tipe']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" class="ant-btn ant-btn-primary" onclick="loadData()"><i class="fa-solid fa-filter"></i> Filter</button>
        </div>

        <!-- KPI Cards -->
        <div class="kpi-container">
            <div class="kpi-badge">
                <span class="kpi-title">Total Items</span>
                <span class="kpi-val" id="kpiTotal">0</span>
            </div>
            <div class="kpi-badge">
                <span class="kpi-title">Raw Materials</span>
                <span class="kpi-val" id="kpiBb" style="color:var(--primary);">0</span>
            </div>
            <div class="kpi-badge">
                <span class="kpi-title">Finished Goods</span>
                <span class="kpi-val" id="kpiFg" style="color:var(--success);">0</span>
            </div>
            <div class="kpi-badge">
                <span class="kpi-title">Work In Progress</span>
                <span class="kpi-val" id="kpiWip" style="color:var(--warning);">0</span>
            </div>
        </div>

        <!-- Table -->
        <div class="table-responsive">
            <table class="ant-table">
                <thead>
                    <tr>
                        <th style="width:30px; text-align:center;"><input type="checkbox" id="checkAllMat" onchange="toggleSelectAllMat(this)" title="Pilih Semua"></th>
                        <th style="width:35px; text-align:center;">#</th>
                        <th>Kode Barang</th>
                        <th>Part No / Kode 2</th>
                        <th>Uraian Barang</th>
                        <th>Model / Tipe</th>
                        <th>HS Code</th>
                        <th>Satuan</th>
                        <th style="text-align:right;">Harga Satuan</th>
                        <th>Valuta</th>
                        <th>Klasifikasi</th>
                        <th>Lokasi</th>
                        <th>Catatan</th>
                        <th style="text-align:center; width:110px;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <tr><td colspan="14" style="text-align:center; padding:15px;">Memuat data material...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal Form Tambah/Edit -->
    <div class="modal" id="modalForm">
        <div class="modal-content">
            <div class="modal-header">
                <h3 style="font-size:12.5px; font-weight:700;" id="modalTitle"><i class="fa-solid fa-box"></i> Tambah Item Barang</h3>
                <span style="cursor:pointer; font-size:16px;" onclick="closeModal('modalForm')">&times;</span>
            </div>
            <div class="modal-body">
                <input type="hidden" id="modalIsEdit" value="0">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Kode Barang (Primary Key)*</label>
                        <input type="text" id="modalKode" class="form-control" placeholder="Contoh: RM-FABRIC-001">
                    </div>
                    <div class="form-group">
                        <label>Kode Barang 2 / Part No</label>
                        <input type="text" id="modalKode2" class="form-control">
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Uraian Barang (Nama Lengkap)*</label>
                        <input type="text" id="modalUraian" class="form-control" placeholder="Deskripsi spesifikasi barang">
                    </div>
                    <div class="form-group">
                        <label>Model / Type / Style</label>
                        <input type="text" id="modalModel" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>HS Code (Pos Tarif Pabean)</label>
                        <input type="text" id="modalHs" class="form-control" placeholder="8 digit angka">
                    </div>
                    <div class="form-group">
                        <label>Satuan Utama (Kode Satuan CEISA)</label>
                        <input type="text" id="modalSatuan" class="form-control" value="PCE">
                    </div>
                    <div class="form-group">
                        <label>Satuan Alternatif / Kemasan</label>
                        <input type="text" id="modalSatuan2" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Nilai Konversi Satuan</label>
                        <input type="number" id="modalKonversi" class="form-control" value="1">
                    </div>
                    <div class="form-group">
                        <label>Harga Satuan (Unit Price)</label>
                        <input type="number" step="0.01" id="modalHarga" class="form-control" value="0.00">
                    </div>
                    <div class="form-group">
                        <label>Mata Uang (Valuta)</label>
                        <input type="text" id="modalValuta" class="form-control" value="IDR">
                    </div>
                    <div class="form-group">
                        <label>Klasifikasi Kategori<?= $isAdmin ? ' (bc_tipe)' : '' ?>*</label>
                        <select id="modalKlas" class="form-control">
                            <?php foreach ($klasList as $k): ?>
                                <option value="<?= htmlspecialchars($k['tipe']) ?>"><?= htmlspecialchars($k['tipe']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div style="font-size:9.5px; color:var(--text-muted); margin-top:2px;">
                            <i class="fa-solid fa-circle-info" style="color:var(--primary);"></i> Perubahan klasifikasi akan otomatis disinkronkan ke seluruh data transaksi IT Inventory<?= $isAdmin ? ' (bc_1ab2)' : '' ?>.
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Lokasi / Rak Gudang</label>
                        <input type="text" id="modalLokasi" class="form-control">
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Catatan Tambahan</label>
                        <input type="text" id="modalNote" class="form-control">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="ant-btn" onclick="closeModal('modalForm')">Batal</button>
                <button type="button" class="ant-btn ant-btn-primary" onclick="saveItem()"><i class="fa-solid fa-floppy-disk"></i> Simpan Item</button>
            </div>
        </div>
    </div>

    <!-- Modal Import Excel -->
    <div class="modal" id="modalImport">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header">
                <h3 style="font-size:12.5px; font-weight:700;"><i class="fa-solid fa-file-excel" style="color:var(--success);"></i> Import Master Barang dari Excel</h3>
                <span style="cursor:pointer; font-size:16px;" onclick="closeModal('modalImport')">&times;</span>
            </div>
            <div class="modal-body">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <div style="font-size:11px; color:var(--text-muted);">
                        Unggah file Excel (<strong>.xlsx, .xls, .csv</strong>) berisi data Master Barang.
                    </div>
                    <button type="button" class="ant-btn" onclick="downloadTemplate()"><i class="fa-solid fa-download"></i> Unduh Format Template</button>
                </div>
                
                <div class="dropzone" id="dropzone" onclick="document.getElementById('excelFileInput').click()">
                    <i class="fa-solid fa-cloud-arrow-up" style="font-size:32px; color:var(--primary); margin-bottom:6px;"></i>
                    <div style="font-weight:600; font-size:12px;">Klik atau Tarik File Excel ke Sini</div>
                    <div style="font-size:10.5px; color:var(--text-muted); margin-top:3px;">Format yang didukung: .xlsx, .xls, .csv</div>
                    <input type="file" id="excelFileInput" accept=".xlsx, .xls, .csv" style="display:none;" onchange="handleExcelUpload(event)">
                </div>

                <div id="importPreviewArea" style="display:none;">
                    <div style="font-weight:600; margin-bottom:6px; display:flex; justify-content:space-between;">
                        <span>Preview Data Excel: <strong id="importRowCount" style="color:var(--primary);">0</strong> baris terdeteksi</span>
                        <span id="importStatusNote" style="font-size:11px;"></span>
                    </div>
                    <div style="max-height: 250px; overflow: auto; border: 1px solid var(--border-color); border-radius: 4px;">
                        <table class="ant-table" style="font-size:10.5px;">
                            <thead>
                                <tr style="background:var(--bg-base);">
                                    <th>#</th>
                                    <th>Kode Barang</th>
                                    <th>Part No</th>
                                    <th>Uraian Barang</th>
                                    <th>HS Code</th>
                                    <th>Satuan</th>
                                    <th>Harga</th>
                                    <th>Klasifikasi</th>
                                </tr>
                            </thead>
                            <tbody id="importPreviewBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="ant-btn" onclick="closeModal('modalImport')">Batal</button>
                <button type="button" class="ant-btn ant-btn-success" id="btnExecuteImport" style="display:none;" onclick="executeImport()">
                    <i class="fa-solid fa-upload"></i> Proses Simpan ke Database
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Import dari IT Inventory (bc_1ab2) & Staging Pabean (bc_ceisa_barang) -->
    <div class="modal" id="modalDbImport">
        <div class="modal-content modal-xl" style="max-width:1150px; width:95%; height:90vh;">
            <div class="modal-header">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span id="dbImportModalIcon" style="font-size:16px;">📥</span>
                    <h3 style="font-size:12.5px; font-weight:700;" id="dbImportModalTitle">Import Master Barang</h3>
                    <span id="dbImportSourceBadge" class="ant-badge" style="background:#e6f7ff; color:#1890ff; border:1px solid #91d5ff;">IT Inventory</span>
                    <span class="ant-badge" style="<?= $allowLowercase ? 'background:#f6ffed; color:#52c41a; border:1px solid #b7eb8f;' : 'background:#f0f5ff; color:#2f54eb; border:1px solid #adc6ff;' ?>">
                        <?= $allowLowercase ? '🔤 Allow Huruf Kecil' : '🔠 Wajib HURUF BESAR' ?>
                    </span>
                </div>
                <span style="cursor:pointer; font-size:18px; line-height:1;" onclick="closeModal('modalDbImport')">&times;</span>
            </div>
            <div class="modal-body" style="padding:10px 14px; display:flex; flex-direction:column; gap:8px;">
                <!-- Toolbar Filter & Quick Actions -->
                <div style="background:var(--bg-base); border:1px solid var(--border-color); border-radius:3px; padding:6px 10px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; flex-shrink:0;">
                    <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                        <input type="text" id="dbImportSearch" class="form-control" style="width:180px;" placeholder="Cari kode/uraian..." oninput="filterCandidateRows()">
                        <select id="dbImportFilterStatus" class="form-control" style="width:145px;" onchange="filterCandidateRows()">
                            <option value="all">Semua Status</option>
                            <option value="new">Hanya Item Baru</option>
                            <option value="exist">Hanya Item Update</option>
                            <option value="minus">⚠️ Kode '-' Dilarang</option>
                            <option value="invalid_klas">⚠️ Klasifikasi Belum Terisi</option>
                        </select>
                    </div>

                    <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                        <label style="font-size:10px; font-weight:600; color:var(--text-muted);">Set Default Klasifikasi<?= $isAdmin ? ' (KLAS)' : '' ?>:</label>
                        <select id="dbImportBulkKlas" class="form-control" style="width:160px; font-weight:600;">
                            <?php foreach ($klasList as $k): ?>
                                <option value="<?= htmlspecialchars($k['tipe']) ?>"><?= htmlspecialchars($k['tipe']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="ant-btn ant-btn-sm" style="background:var(--primary); color:#fff; border-color:var(--primary);" onclick="applyBulkKlasToSelected()">
                            <i class="fa-solid fa-check-double"></i> Terapkan ke Terpilih
                        </button>
                        <button type="button" class="ant-btn ant-btn-sm" style="background:#fa8c16; color:#fff; border-color:#fa8c16;" onclick="autoGenerateCodesForMinus()">
                            <i class="fa-solid fa-wand-magic-sparkles"></i> Auto-Kode untuk '-'
                        </button>
                    </div>
                </div>

                <!-- KPI Summary Mini -->
                <div style="display:flex; gap:6px; flex-wrap:wrap; flex-shrink:0;">
                    <div class="kpi-badge">
                        <span class="kpi-title">Kandidat (Deduplikasi MAX):</span>
                        <span class="kpi-val" id="dbImportTotalCount">0</span>
                    </div>
                    <div class="kpi-badge">
                        <span class="kpi-title">Item Baru:</span>
                        <span class="kpi-val" id="dbImportNewCount" style="color:#1890ff;">0</span>
                    </div>
                    <div class="kpi-badge">
                        <span class="kpi-title">Item Update:</span>
                        <span class="kpi-val" id="dbImportExistCount" style="color:#52c41a;">0</span>
                    </div>
                    <div class="kpi-badge">
                        <span class="kpi-title">⚠️ Kode '-' Dilarang:</span>
                        <span class="kpi-val" id="dbImportMinusCount" style="color:#ff4d4f;">0</span>
                    </div>
                    <div class="kpi-badge">
                        <span class="kpi-title">⚠️ Klasifikasi Kosong:</span>
                        <span class="kpi-val" id="dbImportInvalidKlasCount" style="color:#faad14;">0</span>
                    </div>
                </div>

                <!-- Info Alert -->
                <div id="dbImportValidationAlert" style="display:none; background:#fff2f0; border:1px solid #ffccc7; border-radius:3px; padding:6px 10px; font-size:10px; color:#cf1322; flex-shrink:0;">
                    <i class="fa-solid fa-circle-exclamation"></i> <span id="dbImportValidationMsg"></span>
                </div>

                <!-- Table Preview -->
                <div style="flex:1; overflow:auto; border:1px solid var(--border-color); border-radius:3px; min-height:220px;">
                    <table class="ant-table" style="font-size:10px; width:100%;">
                        <thead>
                            <tr style="background:var(--bg-base); position:sticky; top:0; z-index:3;">
                                <th style="width:30px; text-align:center;">
                                    <input type="checkbox" id="dbImportCheckAll" onchange="toggleSelectAllCandidates(this.checked)" checked>
                                </th>
                                <th style="width:35px; text-align:center;">#</th>
                                <th style="width:80px; text-align:center;">Status</th>
                                <th style="width:200px;">Kode Barang <span style="color:red;">*</span></th>
                                <th>Uraian Barang (Nama Lengkap) <span style="color:red;">*</span></th>
                                <th style="width:90px;">HS Code</th>
                                <th style="width:75px;">Satuan</th>
                                <th style="width:180px;">Klasifikasi<?= $isAdmin ? ' (KLAS)' : '' ?> <span style="color:red;">*</span></th>
                                <th style="width:65px; text-align:center;">Jml Trx</th>
                            </tr>
                        </thead>
                        <tbody id="dbImportTableBody">
                            <tr><td colspan="9" style="text-align:center; padding:25px;">Memuat data kandidat...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer" style="display:flex; justify-content:space-between; align-items:center;">
                <div style="font-size:10.5px; color:var(--text-muted);">
                    <span id="dbImportSelectedCount" style="font-weight:700; color:var(--primary);">0</span> item dipilih untuk diimport.
                </div>
                <div style="display:flex; gap:6px;">
                    <button type="button" class="ant-btn" onclick="closeModal('modalDbImport')">Batal</button>
                    <button type="button" class="ant-btn ant-btn-primary" id="btnExecuteDbImport" onclick="executeDbImport()">
                        <i class="fa-solid fa-cloud-arrow-down"></i> Proses Import ke Master Data
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Pindah Klasifikasi -->
    <div class="modal" id="modalPindahKlas">
        <div class="modal-content" style="max-width: 620px;">
            <div class="modal-header" style="background:linear-gradient(135deg, #722ed1, #9254de); color:#fff;">
                <h3 style="font-size:12.5px; font-weight:700; color:#fff; display:flex; align-items:center; gap:8px;">
                    <i class="fa-solid fa-arrows-split-up-and-left"></i> Pindah &amp; Sinkronkan Klasifikasi Barang
                </h3>
                <span style="cursor:pointer; font-size:16px; color:#fff;" onclick="closeModal('modalPindahKlas')">&times;</span>
            </div>
            <div class="modal-body" style="padding:14px 16px; display:flex; flex-direction:column; gap:12px;">
                <!-- Tab Selector Mode -->
                <div style="display:flex; border-bottom:1px solid var(--border-color); gap:6px; padding-bottom:6px;">
                    <button type="button" id="tabBtnPindahSelected" class="ant-btn ant-btn-sm ant-btn-primary" style="font-weight:600;" onclick="switchPindahTab('selected')">
                        <i class="fa-solid fa-check-double"></i> Item Terpilih (<span id="pindahSelectedCountLabel">0</span>)
                    </button>
                    <button type="button" id="tabBtnPindahBulk" class="ant-btn ant-btn-sm" style="font-weight:600;" onclick="switchPindahTab('bulk')">
                        <i class="fa-solid fa-layer-group"></i> Pindah Massal Kategori
                    </button>
                    <button type="button" id="tabBtnSyncAll" class="ant-btn ant-btn-sm" style="font-weight:600;" onclick="switchPindahTab('sync')">
                        <i class="fa-solid fa-rotate"></i> Sinkronisasi IT Inv
                    </button>
                </div>

                <!-- Panel 1: Item Terpilih -->
                <div id="panelPindahSelected">
                    <div id="pindahSelectedAlert" style="background:rgba(114,46,209,0.08); border:1px solid rgba(114,46,209,0.25); border-radius:4px; padding:10px 12px; margin-bottom:12px; font-size:11px;">
                        <!-- Diisi via renderPindahSelectedAlert() -->
                    </div>
                    <div class="form-group" style="margin-bottom:12px;">
                        <label style="font-weight:600; font-size:11px; display:block; margin-bottom:4px;">Pindahkan ke Klasifikasi Tujuan Baru:*</label>
                        <select id="pindahSelectedTargetKlas" class="form-control" style="width:100%; font-size:11.5px; font-weight:600;">
                            <?php foreach ($klasList as $k): ?>
                                <option value="<?= htmlspecialchars($k['tipe']) ?>"><?= htmlspecialchars($k['tipe']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="background:var(--bg-base); border:1px solid var(--border-color); border-radius:4px; padding:8px 12px; font-size:11px; color:var(--text-muted);">
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer; color:var(--text-heading); font-weight:500;">
                            <input type="checkbox" id="pindahSelectedSync1ab2" checked>
                            <span>Sinkronkan juga klasifikasi ke seluruh transaksi IT Inventory<?= $isAdmin ? ' (bc_1ab2)' : '' ?></span>
                        </label>
                        <div style="margin-left:20px; font-size:10px; margin-top:2px;">
                            Seluruh data transaksi penerimaan, pengeluaran, dan mutasi barang ini di IT Inventory otomatis diperbarui ke klasifikasi baru.
                        </div>
                    </div>
                </div>

                <!-- Panel 2: Pindah Massal per Kategori -->
                <div id="panelPindahBulk" style="display:none;">
                    <div style="background:rgba(250,140,22,0.08); border:1px solid rgba(250,140,22,0.25); border-radius:4px; padding:10px 12px; margin-bottom:12px; font-size:11px;">
                        <i class="fa-solid fa-triangle-exclamation" style="color:#fa8c16;"></i> Fitur ini akan memindahkan <b>SEMUA barang</b> dari Klasifikasi Asal ke Klasifikasi Tujuan dalam satu langkah cepat.
                    </div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                        <div class="form-group">
                            <label style="font-weight:600; font-size:11px; display:block; margin-bottom:4px;">Klasifikasi Asal (Lama):*</label>
                            <select id="pindahBulkSourceKlas" class="form-control" style="width:100%; font-size:11.5px; font-weight:600;">
                                <!-- Diisi via loadKlasSummaryForBulk() -->
                            </select>
                        </div>
                        <div class="form-group">
                            <label style="font-weight:600; font-size:11px; display:block; margin-bottom:4px;">Klasifikasi Tujuan (Baru):*</label>
                            <select id="pindahBulkTargetKlas" class="form-control" style="width:100%; font-size:11.5px; font-weight:600;">
                                <?php foreach ($klasList as $k): ?>
                                    <option value="<?= htmlspecialchars($k['tipe']) ?>"><?= htmlspecialchars($k['tipe']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div style="background:var(--bg-base); border:1px solid var(--border-color); border-radius:4px; padding:8px 12px; font-size:11px; color:var(--text-muted);">
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer; color:var(--text-heading); font-weight:500;">
                            <input type="checkbox" id="pindahBulkSync1ab2" checked>
                            <span>Sinkronkan juga klasifikasi ke seluruh transaksi IT Inventory<?= $isAdmin ? ' (bc_1ab2)' : '' ?></span>
                        </label>
                    </div>
                </div>

                <!-- Panel 3: Sinkronisasi Menyeluruh (Sync All) -->
                <div id="panelSyncAll" style="display:none;">
                    <div style="background:rgba(24,144,255,0.08); border:1px solid rgba(24,144,255,0.25); border-radius:4px; padding:12px; font-size:11px; line-height:1.6;">
                        <div style="font-weight:700; color:#1890ff; margin-bottom:4px;">
                            <i class="fa-solid fa-circle-info"></i> Sinkronisasi Menyeluruh Master ke IT Inventory
                        </div>
                        Sistem akan mencocokkan setiap transaksi pada tabel IT Inventory<?= $isAdmin ? ' (<code>bc_1ab2</code>)' : '' ?> dengan Master Data Barang<?= $isAdmin ? ' (<code>bc_material</code>)' : '' ?> berdasarkan <b>Kode Barang</b> yang identik, dan memperbarui kolom <code>KLAS</code> transaksi agar selaras dengan master.<br><br>
                        <strong>Kapan fitur ini digunakan?</strong>
                        <ul style="margin:4px 0 0 16px; padding:0;">
                            <li>Ketika terdapat transaksi barang di IT Inventory yang memiliki klasifikasi berbeda atau kosong.</li>
                            <li>Setelah mengoreksi atau mengubah klasifikasi master barang agar semua laporan mutasi IT Inventory otomatis terupdate.</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="padding:10px 16px; display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="ant-btn" onclick="closeModal('modalPindahKlas')">Batal</button>
                <button type="button" id="btnExecutePindah" class="ant-btn" style="background:#722ed1; color:#fff; border-color:#722ed1; font-weight:600;" onclick="executePindahKlas()">
                    <i class="fa-solid fa-check"></i> Proses Pindah Klasifikasi
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Fix / Merge Kode Dobel -->
    <div class="modal" id="modalMergeKode">
        <div class="modal-content" style="max-width: 680px;">
            <div class="modal-header" style="background:linear-gradient(135deg, #c41d7f, #eb2f96); color:#fff;">
                <h3 style="font-size:12.5px; font-weight:700; color:#fff; display:flex; align-items:center; gap:8px;">
                    <i class="fa-solid fa-code-merge"></i> Fix / Merge Kode Barang Dobel
                </h3>
                <span style="cursor:pointer; font-size:16px; color:#fff;" onclick="closeModal('modalMergeKode')">&times;</span>
            </div>
            <div class="modal-body" style="padding:14px 16px; display:flex; flex-direction:column; gap:12px;">
                <!-- Info Penjelasan -->
                <div style="background:rgba(196,29,127,0.08); border:1px solid rgba(196,29,127,0.25); border-radius:4px; padding:10px 12px; font-size:11px; line-height:1.5;">
                    <i class="fa-solid fa-circle-info" style="color:#c41d7f;"></i> 
                    Fitur ini digunakan saat operator/exim memiliki kode barang ganda karena typo atau variasi penulisan 
                    (contoh: <code>LEMARI1</code>, <code>LEMRI1</code>, <code>LEMARI 1</code>, <code>LEMARI-1</code>) yang sebenarnya adalah barang yang sama, 
                    untuk digabungkan menjadi <b>1 Kode Utama</b> dan otomatis menyelaraskan seluruh transaksi mutasi.
                </div>

                <!-- Bagian 1: Daftar Kode Sumber (Yang akan di-replace/merge) -->
                <div class="form-group" style="margin-bottom:0;">
                    <label style="font-weight:600; font-size:11px; display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                        <span>1. Kode Barang Lama yang Akan Digabungkan (Sumber):*</span>
                        <span id="mergeCountBadge" style="font-size:10px; font-weight:normal; color:var(--text-muted);">0 kode</span>
                    </label>

                    <!-- Tags list -->
                    <div id="mergeTagsContainer" style="min-height:42px; max-height:110px; overflow-y:auto; border:1px solid var(--border-color); border-radius:4px; padding:6px 8px; background:var(--bg-base); display:flex; flex-wrap:wrap; gap:6px; align-items:center;">
                        <span id="mergeTagsEmpty" style="font-size:10.5px; color:var(--text-muted); font-style:italic;">Belum ada kode lama yang dipilih. Centang item di tabel atau tambahkan manual di bawah.</span>
                    </div>

                    <!-- Input untuk tambah kode manual / paste -->
                    <div style="display:flex; gap:6px; margin-top:6px;">
                        <input type="text" id="mergeInputAddCode" class="form-control" placeholder="Ketik kode barang atau tempel (pisah koma/spasi/baris)..." style="flex:1; font-size:11px;" onkeydown="if(event.key==='Enter'){event.preventDefault();addCodesToMergeList();}">
                        <button type="button" class="ant-btn" style="border-color:#eb2f96; color:#c41d7f; font-weight:600;" onclick="addCodesToMergeList()">
                            <i class="fa-solid fa-plus"></i> Tambah
                        </button>
                        <button type="button" class="ant-btn ant-btn-sm" style="color:var(--danger);" onclick="clearMergeList()" title="Bersihkan daftar">
                            <i class="fa-solid fa-trash-can"></i>
                        </button>
                    </div>
                </div>

                <!-- Bagian 2: Tentukan Kode Target Baru -->
                <div class="form-group" style="margin-bottom:0;">
                    <label style="font-weight:600; font-size:11px; display:block; margin-bottom:4px;">
                        2. Tentukan Kode Barang Utama (Target Hasil Merge):*
                    </label>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                        <div style="background:var(--bg-base); border:1px solid var(--border-color); border-radius:4px; padding:8px 10px;">
                            <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-weight:600; font-size:11px; margin-bottom:6px;">
                                <input type="radio" name="mergeTargetMode" value="select" id="radioTargetSelect" checked onchange="toggleMergeTargetMode()">
                                <span>Pilih dari Salah Satu Kode Sumber</span>
                            </label>
                            <select id="mergeTargetSelect" class="form-control" style="width:100%; font-size:11px; font-weight:600;" onchange="onMergeTargetChanged()">
                                <option value="">-- Pilih Kode Utama --</option>
                            </select>
                        </div>
                        <div style="background:var(--bg-base); border:1px solid var(--border-color); border-radius:4px; padding:8px 10px;">
                            <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-weight:600; font-size:11px; margin-bottom:6px;">
                                <input type="radio" name="mergeTargetMode" value="custom" id="radioTargetCustom" onchange="toggleMergeTargetMode()">
                                <span>Atau Ketik Kode Utama Baru</span>
                            </label>
                            <input type="text" id="mergeTargetCustom" class="form-control" placeholder="Contoh: LEMARI-01" style="width:100%; font-size:11px; font-weight:600;" disabled oninput="onMergeTargetChanged()">
                        </div>
                    </div>
                    <div id="targetKodePreviewBadge" style="margin-top:4px; font-size:11px; color:#c41d7f; font-weight:600;">
                        Target Kode Utama: <span id="lblTargetKode" style="font-family:monospace; background:rgba(196,29,127,0.1); padding:2px 6px; border-radius:3px;">-</span>
                    </div>
                </div>

                <!-- Bagian 3: Opsi Sinkronisasi Tabel -->
                <div style="background:var(--bg-base); border:1px solid var(--border-color); border-radius:4px; padding:10px 12px; font-size:11px;">
                    <div style="font-weight:600; margin-bottom:6px; color:var(--text-heading);">
                        3. Modul &amp; Transaksi yang Akan Diperbarui:
                    </div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:6px;">
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                            <input type="checkbox" id="mergeSync1ab2" checked onchange="triggerMergePreview()">
                            <span>IT Inventory<?= $isAdmin ? ' (<code>bc_1ab2</code>)' : '' ?></span>
                        </label>
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                            <input type="checkbox" id="mergeSyncTrace" checked onchange="triggerMergePreview()">
                            <span>Traceability Bahan Baku<?= $isAdmin ? ' (<code>bc_ceisa_tracebb</code>)' : '' ?></span>
                        </label>
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                            <input type="checkbox" id="mergeSyncIssue" checked onchange="triggerMergePreview()">
                            <span>Pengeluaran Barang<?= $isAdmin ? ' (<code>bc_issue_dtl</code>)' : '' ?></span>
                        </label>
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                            <input type="checkbox" id="mergeSyncSo" checked onchange="triggerMergePreview()">
                            <span>Revisi &amp; Stock Opname<?= $isAdmin ? ' (<code>bc_so</code>, <code>bc_1ab2_revisi</code>)' : '' ?></span>
                        </label>
                    </div>
                    <div style="border-top:1px dashed var(--border-color); margin-top:8px; padding-top:6px;">
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-weight:600; color:var(--text-heading);">
                            <input type="checkbox" id="mergeCleanMaster" checked onchange="triggerMergePreview()">
                            <span>Hapus/gabungkan kode lama dari Master Barang<?= $isAdmin ? ' (<code>bc_material</code>)' : '' ?></span>
                        </label>
                        <div style="margin-left:20px; font-size:10px; color:var(--text-muted);">
                            Kode-kode lama yang digabungkan akan dihapus dari Master Data sehingga hanya tersisa 1 kode utama.
                        </div>
                    </div>
                </div>

                <!-- Bagian 4: Pratinjau Dampak Update -->
                <div id="mergeImpactBox" style="background:#f6ffed; border:1px solid #b7eb8f; border-radius:4px; padding:8px 12px; font-size:11px; display:none;">
                    <div style="font-weight:700; color:#52c41a; display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                        <span><i class="fa-solid fa-magnifying-glass-chart"></i> Pratinjau Dampak Penggabungan Kode:</span>
                        <button type="button" class="ant-btn ant-btn-sm" style="font-size:10px; padding:1px 6px;" onclick="triggerMergePreview()"><i class="fa-solid fa-arrows-rotate"></i> Cek Ulang</button>
                    </div>
                    <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:8px; text-align:center;">
                        <div style="background:#fff; border:1px solid #d9d9d9; border-radius:4px; padding:6px;">
                            <div style="font-size:10px; color:var(--text-muted);">IT Inventory</div>
                            <div id="impactCnt1ab2" style="font-size:14px; font-weight:700; color:#1890ff;">0</div>
                        </div>
                        <div style="background:#fff; border:1px solid #d9d9d9; border-radius:4px; padding:6px;">
                            <div style="font-size:10px; color:var(--text-muted);">Traceability</div>
                            <div id="impactCntTrace" style="font-size:14px; font-weight:700; color:#722ed1;">0</div>
                        </div>
                        <div style="background:#fff; border:1px solid #d9d9d9; border-radius:4px; padding:6px;">
                            <div style="font-size:10px; color:var(--text-muted);">Pengeluaran</div>
                            <div id="impactCntIssue" style="font-size:14px; font-weight:700; color:#fa8c16;">0</div>
                        </div>
                        <div style="background:#fff; border:1px solid #d9d9d9; border-radius:4px; padding:6px;">
                            <div style="font-size:10px; color:var(--text-muted);">Master Barang</div>
                            <div id="impactCntMat" style="font-size:14px; font-weight:700; color:#eb2f96;">0</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="padding:10px 16px; display:flex; justify-content:space-between; align-items:center;">
                <button type="button" class="ant-btn" onclick="triggerMergePreview()" style="color:#c41d7f; border-color:#ffadd2;">
                    <i class="fa-solid fa-magnifying-glass"></i> Cek Dampak Update
                </button>
                <div style="display:flex; gap:8px;">
                    <button type="button" class="ant-btn" onclick="closeModal('modalMergeKode')">Batal</button>
                    <button type="button" id="btnExecuteMerge" class="ant-btn" style="background:#c41d7f; color:#fff; border-color:#c41d7f; font-weight:600;" onclick="executeMergeKode()">
                        <i class="fa-solid fa-code-merge"></i> Jalankan Merge Kode
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Laporan & Perbaikan Satuan Beda -->
    <div class="modal" id="modalFixSatuan">
        <div class="modal-content" style="max-width: 950px;">
            <div class="modal-header" style="background:linear-gradient(135deg, #08979c, #13c2c2); color:#fff;">
                <h3 style="font-size:12.5px; font-weight:700; color:#fff; display:flex; align-items:center; gap:8px;">
                    <i class="fa-solid fa-scale-balanced"></i> Laporan &amp; Perbaikan Satuan Berbeda (Fix Satuan)
                </h3>
                <span style="cursor:pointer; font-size:16px; color:#fff;" onclick="closeModal('modalFixSatuan')">&times;</span>
            </div>
            <div class="modal-body" style="padding:14px 16px; display:flex; flex-direction:column; gap:12px;">
                <!-- Info Penjelasan -->
                <div style="background:rgba(19,194,194,0.08); border:1px solid rgba(19,194,194,0.25); border-radius:4px; padding:10px 12px; font-size:11px; line-height:1.5;">
                    <i class="fa-solid fa-circle-info" style="color:#08979c;"></i> 
                    Laporan ini mendeteksi transaksi pabean &amp; mutasi di mana <b>Satuan Transaksi</b> (misal: <code>PCE</code>) berbeda dengan <b>Satuan Resmi di Master Data Barang</b> (misal: <code>KGM</code>) yang menyebabkan ketidakakuratan perhitungan stok / saldo IT Inventory.<br>
                    Sistem dapat menyelaraskan seluruh transaksi agar menggunakan satuan patokan resmi dari <b>Master Data Barang</b> (<code>bc_material</code>).
                </div>

                <!-- KPI Ringkasan Beda Satuan -->
                <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:8px; text-align:center;">
                    <div style="background:var(--bg-base); border:1px solid var(--border-color); border-radius:4px; padding:8px 6px;">
                        <div style="font-size:9.5px; color:var(--text-muted); font-weight:600; text-transform:uppercase;">Item Beda Satuan</div>
                        <div id="kpiDiffTotalItems" style="font-size:15px; font-weight:700; color:#08979c;">0</div>
                    </div>
                    <div style="background:var(--bg-base); border:1px solid var(--border-color); border-radius:4px; padding:8px 6px;">
                        <div style="font-size:9.5px; color:var(--text-muted); font-weight:600; text-transform:uppercase;">IT Inventory<?= $isAdmin ? ' (bc_1ab2)' : '' ?></div>
                        <div id="kpiDiff1ab2" style="font-size:15px; font-weight:700; color:#1890ff;">0 baris</div>
                    </div>
                    <div style="background:var(--bg-base); border:1px solid var(--border-color); border-radius:4px; padding:8px 6px;">
                        <div style="font-size:9.5px; color:var(--text-muted); font-weight:600; text-transform:uppercase;">Pengeluaran<?= $isAdmin ? ' (bc_issue_dtl)' : '' ?></div>
                        <div id="kpiDiffIssue" style="font-size:15px; font-weight:700; color:#fa8c16;">0 baris</div>
                    </div>
                    <div style="background:var(--bg-base); border:1px solid var(--border-color); border-radius:4px; padding:8px 6px;">
                        <div style="font-size:9.5px; color:var(--text-muted); font-weight:600; text-transform:uppercase;">Stock Opname<?= $isAdmin ? ' (bc_so)' : '' ?></div>
                        <div id="kpiDiffSo" style="font-size:15px; font-weight:700; color:#722ed1;">0 baris</div>
                    </div>
                </div>

                <!-- Filter & Pencarian Laporan -->
                <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap;">
                    <div style="display:flex; gap:8px; align-items:center; flex:1;">
                        <input type="text" id="diffSatuanSearch" class="form-control" placeholder="Cari Kode atau Uraian Barang..." style="max-width:260px; font-size:11px;" oninput="filterDiffSatuanTable()">
                        <select id="diffSatuanModulFilter" class="form-control" style="max-width:180px; font-size:11px;" onchange="filterDiffSatuanTable()">
                            <option value="">-- Semua Modul --</option>
                            <option value="IT Inventory">IT Inventory</option>
                            <option value="Pengeluaran">Pengeluaran</option>
                            <option value="Stock Opname">Stock Opname</option>
                        </select>
                    </div>
                    <div>
                        <button type="button" class="ant-btn ant-btn-sm" onclick="loadDiffSatuanReport()">
                            <i class="fa-solid fa-arrows-rotate"></i> Refresh Laporan
                        </button>
                    </div>
                </div>

                <!-- Tabel Laporan Beda Satuan -->
                <div style="max-height:300px; overflow-y:auto; border:1px solid var(--border-color); border-radius:4px;">
                    <table class="ant-table" style="font-size:10.5px;">
                        <thead>
                            <tr style="background:var(--bg-base);">
                                <th style="width:30px; text-align:center;">
                                    <input type="checkbox" id="checkAllDiffSatuan" onchange="toggleSelectAllDiffSatuan(this)">
                                </th>
                                <th style="width:30px; text-align:center;">#</th>
                                <th>Kode Barang</th>
                                <th>Uraian Barang</th>
                                <th>Klasifikasi</th>
                                <th style="text-align:center;">Satuan Master<br><small style="font-weight:normal; color:#52c41a;">(Patokan Resmi)</small></th>
                                <th style="text-align:center;">Satuan Transaksi<br><small style="font-weight:normal; color:#ff4d4f;">(Beda / Salah)</small></th>
                                <th>Modul Transaksi</th>
                                <th style="text-align:right;">Jml Transaksi</th>
                                <th style="text-align:center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="diffSatuanTableBody">
                            <tr>
                                <td colspan="10" style="text-align:center; padding:15px; color:var(--text-muted);">
                                    <i class="fa-solid fa-spinner fa-spin"></i> Memuat laporan perbedaan satuan...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer" style="padding:10px 16px; display:flex; justify-content:space-between; align-items:center;">
                <div style="font-size:11px; color:var(--text-muted);">
                    <span id="diffSatuanSelectedCount" style="font-weight:700; color:#08979c;">0</span> item dipilih.
                </div>
                <div style="display:flex; gap:8px;">
                    <button type="button" class="ant-btn" onclick="closeModal('modalFixSatuan')">Tutup</button>
                    <button type="button" id="btnSyncSelectedSatuan" class="ant-btn" style="border-color:#13c2c2; color:#08979c; font-weight:600;" onclick="executeSyncSatuan('selected')" disabled>
                        <i class="fa-solid fa-check"></i> Selaraskan Item Terpilih
                    </button>
                    <button type="button" id="btnSyncAllSatuan" class="ant-btn" style="background:#08979c; color:#fff; border-color:#08979c; font-weight:700;" onclick="executeSyncSatuan('all')">
                        <i class="fa-solid fa-rotate"></i> Selaraskan SEMUA Satuan ke Master Data
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
        const sessionCmpId = '<?= htmlspecialchars($sessionCmpId) ?>';

        function openKoreksiKlas() {
            if (window.parent && typeof window.parent.switchMainTab === 'function') {
                window.parent.switchMainTab('inv_koreksi_klas');
            } else {
                window.location.href = 'klas_koreksi.php?cmp_id=' + encodeURIComponent(sessionCmpId);
            }
        }

        let rawItemsData = [];
        let parsedExcelRows = [];

        async function loadData() {
            const q = document.getElementById('filterSearch').value.trim();
            const klas = document.getElementById('filterKlas').value;
            const tbody = document.getElementById('tableBody');
            tbody.innerHTML = '<tr><td colspan="14" style="text-align:center; padding:15px;"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data material...</td></tr>';

            try {
                const res = await fetch(`material.php?action=list&q=${encodeURIComponent(q)}&klas=${encodeURIComponent(klas)}`);
                const json = await res.json();
                if (json.status === 'OK') {
                    rawItemsData = json.data;
                    document.getElementById('kpiTotal').innerText = Number(json.kpi.total).toLocaleString('id-ID');
                    document.getElementById('kpiBb').innerText = Number(json.kpi.bahan_baku).toLocaleString('id-ID');
                    document.getElementById('kpiFg').innerText = Number(json.kpi.finished_goods).toLocaleString('id-ID');
                    document.getElementById('kpiWip').innerText = Number(json.kpi.wip).toLocaleString('id-ID');
                    renderTable(json.data);
                } else {
                    tbody.innerHTML = `<tr><td colspan="14" style="text-align:center; color:red;">${json.message}</td></tr>`;
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="14" style="text-align:center; color:red;">Gagal memuat: ${err.message}</td></tr>`;
            }
        }

        function renderTable(data) {
            const tbody = document.getElementById('tableBody');
            const checkAll = document.getElementById('checkAllMat');
            if (checkAll) checkAll.checked = false;

            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="14" style="text-align:center; padding:15px;">Tidak ada item barang ditemukan.</td></tr>';
                updateSelectedMatCount();
                return;
            }

            tbody.innerHTML = data.map((r, i) => `
                <tr>
                    <td style="text-align:center;"><input type="checkbox" class="mat-checkbox" value="${escapeHtml(r.KODE_BARANG)}" onchange="updateSelectedMatCount()"></td>
                    <td style="text-align:center; color:var(--text-muted);">${i + 1}</td>
                    <td style="font-weight:600; color:var(--primary); font-family:monospace;">${escapeHtml(r.KODE_BARANG)}</td>
                    <td>${escapeHtml(r.KODE_BARANG2 || '-')}</td>
                    <td style="font-weight:500;">${escapeHtml(r.URAIAN)}</td>
                    <td>${escapeHtml(r.MODEL || '-')}</td>
                    <td style="font-family:monospace;">${escapeHtml(r.POS_TARIF || '-')}</td>
                    <td>${escapeHtml(r.KODE_SATUAN || 'PCE')}</td>
                    <td style="text-align:right; font-weight:600;">${Number(r.UNIT_PRICE || 0).toLocaleString('id-ID', {minimumFractionDigits: 2})}</td>
                    <td>${escapeHtml(r.KODE_VALUTA || 'IDR')}</td>
                    <td><span class="tag-badge tag-blue" style="cursor:pointer;" onclick="quickMoveSingleKlas('${escapeJs(r.KODE_BARANG)}', '${escapeJs(r.KLAS || '')}')" title="Klik untuk memindahkan klasifikasi">${escapeHtml(r.KLAS || '-')}</span></td>
                    <td>${escapeHtml(r.LOKASI || '-')}</td>
                    <td>${escapeHtml(r.NOTE || '-')}</td>
                    <td style="text-align:center; white-space:nowrap;">
                        <button class="ant-btn" style="padding:2px 6px;" title="Edit Item" onclick="openEditModal(${i})"><i class="fa-solid fa-pen"></i></button>
                        <button class="ant-btn" style="padding:2px 6px; color:#722ed1; border-color:#d3adf7;" title="Pindah Klasifikasi" onclick="quickMoveSingleKlas('${escapeJs(r.KODE_BARANG)}', '${escapeJs(r.KLAS || '')}')"><i class="fa-solid fa-arrows-split-up-and-left"></i></button>
                        <button class="ant-btn" style="padding:2px 6px; color:#c41d7f; border-color:#ffadd2;" title="Fix/Merge Kode Dobel" onclick="quickMergeSingleKode('${escapeJs(r.KODE_BARANG)}')"><i class="fa-solid fa-code-merge"></i></button>
                        <button class="ant-btn" style="padding:2px 6px; color:var(--danger);" title="Hapus Item" onclick="deleteItem('${escapeHtml(r.KODE_BARANG)}')"><i class="fa-solid fa-trash"></i></button>
                    </td>
                </tr>
            `).join('');
            updateSelectedMatCount();
        }

        function toggleSelectAllMat(masterCb) {
            const cbs = document.querySelectorAll('.mat-checkbox');
            cbs.forEach(cb => { cb.checked = masterCb.checked; });
            updateSelectedMatCount();
        }

        function getSelectedMatCodes() {
            const cbs = document.querySelectorAll('.mat-checkbox:checked');
            return Array.from(cbs).map(cb => cb.value);
        }

        function updateSelectedMatCount() {
            const selected = getSelectedMatCodes();
            const badge = document.getElementById('badgeSelectedMat');
            const badgeMerge = document.getElementById('badgeMergeMat');
            const lbl = document.getElementById('pindahSelectedCountLabel');
            if (badge) {
                if (selected.length > 0) {
                    badge.style.display = 'inline-block';
                    badge.innerText = selected.length;
                } else {
                    badge.style.display = 'none';
                }
            }
            if (badgeMerge) {
                if (selected.length > 0) {
                    badgeMerge.style.display = 'inline-block';
                    badgeMerge.innerText = selected.length;
                } else {
                    badgeMerge.style.display = 'none';
                }
            }
            if (lbl) lbl.innerText = selected.length;

            const masterCb = document.getElementById('checkAllMat');
            const allCbs = document.querySelectorAll('.mat-checkbox');
            if (masterCb && allCbs.length > 0) {
                masterCb.checked = (selected.length === allCbs.length);
            }
        }

        function quickMoveSingleKlas(kodeBarang, currentKlas) {
            const allCbs = document.querySelectorAll('.mat-checkbox');
            allCbs.forEach(cb => {
                cb.checked = (cb.value === kodeBarang);
            });
            updateSelectedMatCount();
            openPindahKlasModal('selected');
        }

        let currentPindahTab = 'selected';

        async function openPindahKlasModal(defaultTab) {
            const selected = getSelectedMatCodes();
            if (defaultTab) {
                switchPindahTab(defaultTab);
            } else if (selected.length > 0) {
                switchPindahTab('selected');
            } else {
                switchPindahTab('bulk');
            }

            renderPindahSelectedAlert();
            await loadKlasSummaryForBulk();

            document.getElementById('modalPindahKlas').style.display = 'flex';
        }

        function closePindahKlasModal() {
            document.getElementById('modalPindahKlas').style.display = 'none';
        }

        function switchPindahTab(tab) {
            currentPindahTab = tab;
            const pSel = document.getElementById('panelPindahSelected');
            const pBulk = document.getElementById('panelPindahBulk');
            const pSync = document.getElementById('panelSyncAll');
            const btnSel = document.getElementById('tabBtnPindahSelected');
            const btnBulk = document.getElementById('tabBtnPindahBulk');
            const btnSync = document.getElementById('tabBtnSyncAll');
            const btnExec = document.getElementById('btnExecutePindah');

            pSel.style.display = (tab === 'selected') ? 'block' : 'none';
            pBulk.style.display = (tab === 'bulk') ? 'block' : 'none';
            pSync.style.display = (tab === 'sync') ? 'block' : 'none';

            btnSel.className = (tab === 'selected') ? 'ant-btn ant-btn-sm ant-btn-primary' : 'ant-btn ant-btn-sm';
            btnBulk.className = (tab === 'bulk') ? 'ant-btn ant-btn-sm ant-btn-primary' : 'ant-btn ant-btn-sm';
            btnSync.className = (tab === 'sync') ? 'ant-btn ant-btn-sm ant-btn-primary' : 'ant-btn ant-btn-sm';

            if (tab === 'sync') {
                btnExec.innerHTML = '<i class="fa-solid fa-sync"></i> Jalankan Sinkronisasi ke IT Inv';
                btnExec.style.background = '#096dd9';
                btnExec.style.borderColor = '#096dd9';
            } else {
                btnExec.innerHTML = '<i class="fa-solid fa-check"></i> Proses Pindah Klasifikasi';
                btnExec.style.background = '#722ed1';
                btnExec.style.borderColor = '#722ed1';
            }
        }

        function renderPindahSelectedAlert() {
            const selected = getSelectedMatCodes();
            const el = document.getElementById('pindahSelectedAlert');
            if (!el) return;
            if (selected.length > 0) {
                const preview = selected.slice(0, 8).map(c => `<code style="background:var(--bg-base); padding:1px 4px; border-radius:3px;">${escapeHtml(c)}</code>`).join(' ');
                el.innerHTML = `<div><strong><i class="fa-solid fa-circle-check" style="color:#52c41a;"></i> ${selected.length} item barang dipilih:</strong></div>` +
                               `<div style="margin-top:6px; font-size:11px; word-break:break-all;">${preview}${selected.length > 8 ? ` <em>...dan ${selected.length - 8} lainnya</em>` : ''}</div>`;
            } else {
                el.innerHTML = `<div style="color:#d46b08;"><strong><i class="fa-solid fa-triangle-exclamation"></i> Belum ada item yang dicentang di tabel.</strong></div>` +
                               `<div style="margin-top:2px; font-size:10.5px; color:var(--text-muted);">Silakan centang item pada tabel utama atau gunakan tab <b>Pindah Massal Kategori</b> di atas.</div>`;
            }
        }

        async function loadKlasSummaryForBulk() {
            const sel = document.getElementById('pindahBulkSourceKlas');
            if (!sel) return;
            sel.innerHTML = '<option value="">-- Memuat data... --</option>';
            try {
                const res = await fetch('material.php?action=get_klas_summary');
                const json = await res.json();
                if (json.status === 'OK' && Array.isArray(json.data) && json.data.length > 0) {
                    sel.innerHTML = json.data.map(k => `
                        <option value="${escapeHtml(k.klas_name)}">${escapeHtml(k.klas_name)} (${Number(k.cnt).toLocaleString('id-ID')} item)</option>
                    `).join('');
                } else {
                    sel.innerHTML = '<option value="">(Tidak ada data material)</option>';
                }
            } catch (e) {
                sel.innerHTML = '<option value="">(Gagal memuat kategori)</option>';
            }
        }

        async function executePindahKlas() {
            if (currentPindahTab === 'sync') {
                closePindahKlasModal();
                await syncAllKlasTo1ab2();
                return;
            }

            if (currentPindahTab === 'selected') {
                const selected = getSelectedMatCodes();
                if (selected.length === 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Tidak Ada Item Terpilih',
                        text: 'Silakan centang setidaknya satu item barang pada tabel utama terlebih dahulu.'
                    });
                    return;
                }
                const targetKlas = document.getElementById('pindahSelectedTargetKlas').value;
                const sync1ab2 = document.getElementById('pindahSelectedSync1ab2').checked ? '1' : '0';

                const confirm = await Swal.fire({
                    icon: 'question',
                    title: 'Konfirmasi Pindah Klasifikasi',
                    html: `Apakah Anda yakin ingin memindahkan <b>${selected.length} item</b> barang terpilih ke klasifikasi <b>${escapeHtml(targetKlas)}</b>?` +
                          (sync1ab2 === '1' ? `<br><br><small style="color:#52c41a;">✓ Transaksi di IT Inventory${isAdmin ? ' (bc_1ab2)' : ''} akan otomatis disinkronkan.</small>` : ''),
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Pindahkan',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#722ed1'
                });
                if (!confirm.isConfirmed) return;

                Swal.fire({
                    title: 'Memproses...',
                    text: 'Memindahkan klasifikasi dan menyinkronkan data...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });

                const fd = new FormData();
                fd.append('action', 'move_klas');
                fd.append('mode', 'selected');
                fd.append('target_klas', targetKlas);
                fd.append('sync_1ab2', sync1ab2);
                fd.append('items', JSON.stringify(selected));

                try {
                    const res = await fetch('material.php', { method: 'POST', body: fd });
                    const json = await res.json();
                    if (json.status === 'OK') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Pindah Klasifikasi Berhasil',
                            text: json.message
                        });
                        closePindahKlasModal();
                        loadData();
                    } else {
                        Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
                    }
                } catch (err) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Koneksi gagal: ' + err.message });
                }
            } else if (currentPindahTab === 'bulk') {
                const sourceKlas = document.getElementById('pindahBulkSourceKlas').value;
                const targetKlas = document.getElementById('pindahBulkTargetKlas').value;
                const sync1ab2 = document.getElementById('pindahBulkSync1ab2').checked ? '1' : '0';

                if (!sourceKlas) {
                    Swal.fire({ icon: 'warning', title: 'Pilih Klasifikasi Asal', text: 'Silakan pilih klasifikasi asal yang ingin dipindahkan.' });
                    return;
                }
                if (sourceKlas === targetKlas) {
                    Swal.fire({ icon: 'warning', title: 'Klasifikasi Sama', text: 'Klasifikasi asal dan tujuan tidak boleh sama.' });
                    return;
                }

                const confirm = await Swal.fire({
                    icon: 'warning',
                    title: 'Pindah Massal Seluruh Kategori?',
                    html: `Apakah Anda yakin ingin memindahkan <b>SEMUA barang</b> dari:<br><br>` +
                          `<span style="color:#d46b08; font-weight:700;">${escapeHtml(sourceKlas)}</span> &rarr; <span style="color:#52c41a; font-weight:700;">${escapeHtml(targetKlas)}</span>?` +
                          (sync1ab2 === '1' ? `<br><br><small style="color:#52c41a;">✓ Seluruh transaksi di IT Inventory${isAdmin ? ' (bc_1ab2)' : ''} akan otomatis disinkronkan.</small>` : ''),
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Jalankan Pindah Massal',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#722ed1'
                });
                if (!confirm.isConfirmed) return;

                Swal.fire({
                    title: 'Memproses Massal...',
                    text: 'Memindahkan seluruh kategori dan menyinkronkan data...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });

                const fd = new FormData();
                fd.append('action', 'move_klas');
                fd.append('mode', 'bulk');
                fd.append('source_klas', sourceKlas);
                fd.append('target_klas', targetKlas);
                fd.append('sync_1ab2', sync1ab2);

                try {
                    const res = await fetch('material.php', { method: 'POST', body: fd });
                    const json = await res.json();
                    if (json.status === 'OK') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Pindah Massal Berhasil',
                            text: json.message
                        });
                        closePindahKlasModal();
                        loadData();
                    } else {
                        Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
                    }
                } catch (err) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Koneksi gagal: ' + err.message });
                }
            }
        }

        async function syncAllKlasTo1ab2() {
            const confirm = await Swal.fire({
                icon: 'question',
                title: 'Sinkronkan Klasifikasi ke IT Inventory?',
                html: `Sistem akan menyamakan seluruh kolom <b>Klasifikasi (KLAS)</b> pada transaksi IT Inventory${isAdmin ? ' (bc_1ab2)' : ''} agar sesuai dengan <b>Master Data Barang</b>${isAdmin ? ' (bc_material)' : ''}.<br><br>Lanjutkan proses sinkronisasi?`,
                showCancelButton: true,
                confirmButtonText: 'Ya, Sinkronkan Sekarang',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#096dd9'
            });
            if (!confirm.isConfirmed) return;

            Swal.fire({
                title: 'Menyinkronkan...',
                text: 'Memperbarui data IT Inventory...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            const fd = new FormData();
            fd.append('action', 'sync_all_klas_to_1ab2');

            try {
                const res = await fetch('material.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Sinkronisasi Selesai',
                        text: json.message
                    });
                    loadData();
                } else {
                    Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
                }
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Koneksi gagal: ' + err.message });
            }
        }

        // ==================== FIX / MERGE KODE DOBEL ====================
        let mergeCodesList = [];
        let mergePreviewTimeout = null;

        function openMergeKodeModal(presetCodes = null) {
            if (Array.isArray(presetCodes) && presetCodes.length > 0) {
                mergeCodesList = [...new Set(presetCodes.map(c => String(c).trim()).filter(Boolean))];
            } else {
                const selected = getSelectedMatCodes();
                if (selected.length > 0) {
                    mergeCodesList = [...new Set(selected.map(c => String(c).trim()).filter(Boolean))];
                } else {
                    mergeCodesList = [];
                }
            }

            renderMergeTags();
            updateMergeTargetOptions();
            document.getElementById('radioTargetSelect').checked = true;
            toggleMergeTargetMode();
            document.getElementById('modalMergeKode').style.display = 'flex';

            if (mergeCodesList.length > 0) {
                triggerMergePreview();
            } else {
                document.getElementById('mergeImpactBox').style.display = 'none';
            }
        }

        function quickMergeSingleKode(kodeBarang) {
            mergeCodesList = [String(kodeBarang).trim()];
            renderMergeTags();
            updateMergeTargetOptions();
            document.getElementById('radioTargetSelect').checked = true;
            toggleMergeTargetMode();
            document.getElementById('modalMergeKode').style.display = 'flex';
            triggerMergePreview();
        }

        function renderMergeTags() {
            const container = document.getElementById('mergeTagsContainer');
            const badge = document.getElementById('mergeCountBadge');
            if (badge) badge.innerText = `${mergeCodesList.length} kode`;

            if (mergeCodesList.length === 0) {
                container.innerHTML = '<span id="mergeTagsEmpty" style="font-size:10.5px; color:var(--text-muted); font-style:italic;">Belum ada kode lama yang dipilih. Centang item di tabel atau tambahkan manual di bawah.</span>';
                return;
            }

            container.innerHTML = mergeCodesList.map(c => `
                <span class="merge-chip">
                    ${escapeHtml(c)}
                    <i class="fa-solid fa-xmark" title="Hapus dari daftar" onclick="removeCodeFromMergeList('${escapeJs(c)}')"></i>
                </span>
            `).join('');
        }

        function addCodesToMergeList() {
            const input = document.getElementById('mergeInputAddCode');
            const val = input.value.trim();
            if (!val) return;

            // Split by comma, semicolon, space, newline, tab
            const parts = val.split(/[\s,;\n\r\t]+/).map(s => s.trim()).filter(Boolean);
            let addedCount = 0;
            parts.forEach(p => {
                if (!mergeCodesList.includes(p)) {
                    mergeCodesList.push(p);
                    addedCount++;
                }
            });

            input.value = '';
            renderMergeTags();
            updateMergeTargetOptions();
            triggerMergePreview();
        }

        function removeCodeFromMergeList(code) {
            mergeCodesList = mergeCodesList.filter(c => c !== code);
            renderMergeTags();
            updateMergeTargetOptions();
            triggerMergePreview();
        }

        function clearMergeList() {
            mergeCodesList = [];
            renderMergeTags();
            updateMergeTargetOptions();
            document.getElementById('mergeImpactBox').style.display = 'none';
            onMergeTargetChanged();
        }

        function updateMergeTargetOptions() {
            const sel = document.getElementById('mergeTargetSelect');
            const prevVal = sel.value;
            sel.innerHTML = '<option value="">-- Pilih Kode Utama --</option>';

            mergeCodesList.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c;
                opt.innerText = c;
                if (c === prevVal || (mergeCodesList.length === 1 && c === mergeCodesList[0])) {
                    opt.selected = true;
                }
                sel.appendChild(opt);
            });

            if (!sel.value && mergeCodesList.length > 0) {
                sel.value = mergeCodesList[0];
            }
            onMergeTargetChanged();
        }

        function toggleMergeTargetMode() {
            const isCustom = document.getElementById('radioTargetCustom').checked;
            document.getElementById('mergeTargetSelect').disabled = isCustom;
            document.getElementById('mergeTargetCustom').disabled = !isCustom;
            if (isCustom) {
                document.getElementById('mergeTargetCustom').focus();
            }
            onMergeTargetChanged();
        }

        function getSelectedTargetCode() {
            const isCustom = document.getElementById('radioTargetCustom').checked;
            if (isCustom) {
                return document.getElementById('mergeTargetCustom').value.trim();
            } else {
                return document.getElementById('mergeTargetSelect').value.trim();
            }
        }

        function onMergeTargetChanged() {
            const target = getSelectedTargetCode();
            const lbl = document.getElementById('lblTargetKode');
            if (lbl) {
                lbl.innerText = target || '-';
            }
            triggerMergePreview();
        }

        function triggerMergePreview() {
            clearTimeout(mergePreviewTimeout);
            mergePreviewTimeout = setTimeout(fetchMergePreview, 350);
        }

        async function fetchMergePreview() {
            const targetCode = getSelectedTargetCode();
            const oldCodes = mergeCodesList.filter(c => c.toUpperCase() !== targetCode.toUpperCase());
            const impactBox = document.getElementById('mergeImpactBox');

            if (oldCodes.length === 0 && !targetCode) {
                if (impactBox) impactBox.style.display = 'none';
                return;
            }

            try {
                const res = await fetch(`material.php?action=preview_merge&target_code=${encodeURIComponent(targetCode)}&old_codes=${encodeURIComponent(JSON.stringify(oldCodes))}`);
                const json = await res.json();
                if (json.status === 'OK' && impactBox) {
                    impactBox.style.display = 'block';
                    document.getElementById('impactCnt1ab2').innerText = Number(json.counts['1ab2'] || 0).toLocaleString('id-ID');
                    document.getElementById('impactCntTrace').innerText = Number(json.counts['tracebb'] || 0).toLocaleString('id-ID');
                    document.getElementById('impactCntIssue').innerText = Number(json.counts['issue_dtl'] || 0).toLocaleString('id-ID');
                    document.getElementById('impactCntMat').innerText = Number(json.counts['material'] || 0).toLocaleString('id-ID');
                }
            } catch (e) {
                // Ignore silent preview errors
            }
        }

        async function executeMergeKode() {
            const targetCode = getSelectedTargetCode();
            if (!targetCode) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Tentukan Kode Utama',
                    text: 'Silakan tentukan Kode Barang Utama (Target) hasil merge, baik memilih dari daftar atau mengetik kode baru.'
                });
                return;
            }

            const oldCodes = mergeCodesList.filter(c => c.toUpperCase() !== targetCode.toUpperCase());
            if (oldCodes.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Tidak Ada Kode Sumber',
                    text: 'Minimal harus ada 1 kode barang lama yang berbeda dari kode target untuk digabungkan.'
                });
                return;
            }

            const sync1ab2 = document.getElementById('mergeSync1ab2').checked ? '1' : '0';
            const syncTrace = document.getElementById('mergeSyncTrace').checked ? '1' : '0';
            const syncIssue = document.getElementById('mergeSyncIssue').checked ? '1' : '0';
            const syncSo = document.getElementById('mergeSyncSo').checked ? '1' : '0';
            const cleanMaster = document.getElementById('mergeCleanMaster').checked ? '1' : '0';

            const confirm = await Swal.fire({
                icon: 'warning',
                title: 'Konfirmasi Merge Kode Dobel',
                html: `Apakah Anda yakin ingin menggabungkan <b>${oldCodes.length} kode barang lama</b> berikut:<br><br>` +
                      `<div style="max-height:80px; overflow-y:auto; background:#fff2f0; border:1px solid #ffccc7; border-radius:4px; padding:6px; font-family:monospace; font-size:11px; color:#cf1322;">` +
                      oldCodes.map(c => `• ${escapeHtml(c)}`).join('<br>') +
                      `</div><br>` +
                      `Menjadi 1 Kode Barang Utama:<br>` +
                      `<span style="font-family:monospace; font-size:14px; font-weight:700; color:#c41d7f; background:rgba(196,29,127,0.1); padding:3px 8px; border-radius:4px;">${escapeHtml(targetCode)}</span><br><br>` +
                      `<small style="color:var(--text-muted);">Seluruh data transaksi di IT Inventory${isAdmin ? ' (bc_1ab2)' : ''}, Traceability${isAdmin ? ' (bc_ceisa_tracebb)' : ''}, dan Pengeluaran${isAdmin ? ' (bc_issue_dtl)' : ''} yang menggunakan kode-kode lama akan langsung diganti ke kode utama ini.</small>`,
                showCancelButton: true,
                confirmButtonText: 'Ya, Jalankan Merge Kode',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#c41d7f'
            });
            if (!confirm.isConfirmed) return;

            Swal.fire({
                title: 'Memproses Merge Kode...',
                text: 'Mengupdate transaksi dan menyelaraskan kode barang...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            const fd = new FormData();
            fd.append('action', 'merge_kode');
            fd.append('target_code', targetCode);
            fd.append('old_codes', JSON.stringify(oldCodes));
            fd.append('sync_1ab2', sync1ab2);
            fd.append('sync_trace', syncTrace);
            fd.append('sync_issue', syncIssue);
            fd.append('sync_so', syncSo);
            fd.append('clean_master', cleanMaster);

            try {
                const res = await fetch('material.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    await Swal.fire({
                        icon: 'success',
                        title: 'Merge Kode Berhasil!',
                        html: `<pre style="text-align:left; font-size:11px; background:#f6ffed; border:1px solid #b7eb8f; border-radius:4px; padding:10px; white-space:pre-wrap;">${escapeHtml(json.message)}</pre>`,
                        confirmButtonColor: '#c41d7f'
                    });
                    closeModal('modalMergeKode');
                    clearMergeList();
                    loadData();
                } else {
                    Swal.fire({ icon: 'error', title: 'Gagal Merge Kode', text: json.message });
                }
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Koneksi gagal: ' + err.message });
            }
        }

        // ==================== FIX SATUAN BEDA ====================
        let diffSatuanRawData = [];

        function openFixSatuanModal() {
            document.getElementById('modalFixSatuan').style.display = 'flex';
            loadDiffSatuanReport();
        }

        async function loadDiffSatuanReport() {
            const tbody = document.getElementById('diffSatuanTableBody');
            tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:15px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat laporan perbedaan satuan...</td></tr>';
            try {
                const res = await fetch('material.php?action=get_diff_satuan_report');
                const json = await res.json();
                if (json.status === 'OK') {
                    diffSatuanRawData = json.data || [];

                    // Update KPI cards
                    document.getElementById('kpiDiffTotalItems').innerText = Number(json.kpi.total_items || 0).toLocaleString('id-ID');
                    document.getElementById('kpiDiff1ab2').innerText = Number(json.kpi.total_trans_1ab2 || 0).toLocaleString('id-ID') + ' baris';
                    document.getElementById('kpiDiffIssue').innerText = Number(json.kpi.total_trans_issue || 0).toLocaleString('id-ID') + ' baris';
                    document.getElementById('kpiDiffSo').innerText = Number(json.kpi.total_trans_so || 0).toLocaleString('id-ID') + ' baris';

                    // Update Toolbar Badge
                    const badge = document.getElementById('badgeDiffSatuan');
                    if (badge) {
                        const totalDiff = json.kpi.total_items || 0;
                        if (totalDiff > 0) {
                            badge.style.display = 'inline-block';
                            badge.innerText = totalDiff;
                        } else {
                            badge.style.display = 'none';
                        }
                    }

                    filterDiffSatuanTable();
                } else {
                    tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:15px; color:#ff4d4f;">Gagal memuat: ${escapeHtml(json.message)}</td></tr>`;
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:15px; color:#ff4d4f;">Koneksi error: ${escapeHtml(err.message)}</td></tr>`;
            }
        }

        function filterDiffSatuanTable() {
            const q = (document.getElementById('diffSatuanSearch').value || '').toLowerCase().trim();
            const modul = document.getElementById('diffSatuanModulFilter').value;

            let filtered = diffSatuanRawData;
            if (modul) {
                filtered = filtered.filter(it => it.MODUL === modul);
            }
            if (q) {
                filtered = filtered.filter(it => 
                    (it.KODE_BARANG || '').toLowerCase().includes(q) ||
                    (it.URAIAN || '').toLowerCase().includes(q) ||
                    (it.KLAS || '').toLowerCase().includes(q)
                );
            }
            renderDiffSatuanTable(filtered);
        }

        function renderDiffSatuanTable(data = null) {
            const list = data !== null ? data : diffSatuanRawData;
            const tbody = document.getElementById('diffSatuanTableBody');

            if (list.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:20px; color:#52c41a;"><i class="fa-solid fa-circle-check" style="font-size:16px;"></i><br><b>Bagus!</b> Tidak ditemukan perbedaan satuan. Seluruh transaksi sudah selaras dengan Satuan Master Barang.</td></tr>';
                updateDiffSatuanSelectedCount();
                return;
            }

            tbody.innerHTML = list.map((r, i) => `
                <tr>
                    <td style="text-align:center;">
                        <input type="checkbox" class="diff-sat-cb" value="${escapeHtml(r.KODE_BARANG)}" onchange="updateDiffSatuanSelectedCount()">
                    </td>
                    <td style="text-align:center; color:var(--text-muted);">${i + 1}</td>
                    <td style="font-weight:700; font-family:monospace; color:var(--primary);">${escapeHtml(r.KODE_BARANG)}</td>
                    <td>${escapeHtml(r.URAIAN || '-')}</td>
                    <td><span class="tag-badge tag-blue">${escapeHtml(r.KLAS || '-')}</span></td>
                    <td style="text-align:center;">
                        <span class="tag-badge" style="background:#f6ffed; color:#52c41a; border:1px solid #b7eb8f; font-weight:700; font-size:10.5px;">${escapeHtml(r.SAT_MASTER)}</span>
                    </td>
                    <td style="text-align:center;">
                        <span class="tag-badge" style="background:#fff2f0; color:#ff4d4f; border:1px solid #ffccc7; font-weight:700; font-size:10.5px;">${escapeHtml(r.SAT_TRANSAKSI)}</span>
                    </td>
                    <td>
                        <span class="tag-badge" style="background:${r.MODUL === 'IT Inventory' ? '#e6f7ff' : (r.MODUL === 'Pengeluaran' ? '#fff7e6' : '#f9f0ff')}; color:${r.MODUL === 'IT Inventory' ? '#1890ff' : (r.MODUL === 'Pengeluaran' ? '#d46b08' : '#722ed1')}; font-weight:600;">
                            ${escapeHtml(r.MODUL)}${isAdmin ? ' (' + escapeHtml(r.TABEL) + ')' : ''}
                        </span>
                    </td>
                    <td style="text-align:right; font-weight:700; color:#1890ff;">${Number(r.JML_TRANSAKSI).toLocaleString('id-ID')}</td>
                    <td style="text-align:center; white-space:nowrap;">
                        <button type="button" class="ant-btn ant-btn-sm" style="border-color:#13c2c2; color:#08979c; padding:1px 6px; font-size:10px;" onclick="syncSingleSatuan('${escapeJs(r.KODE_BARANG)}', '${escapeJs(r.SAT_MASTER)}')">
                            <i class="fa-solid fa-rotate"></i> Selaraskan
                        </button>
                    </td>
                </tr>
            `).join('');

            updateDiffSatuanSelectedCount();
        }

        function toggleSelectAllDiffSatuan(masterCb) {
            const cbs = document.querySelectorAll('.diff-sat-cb');
            cbs.forEach(cb => { cb.checked = masterCb.checked; });
            updateDiffSatuanSelectedCount();
        }

        function getSelectedDiffSatCodes() {
            const cbs = document.querySelectorAll('.diff-sat-cb:checked');
            return [...new Set(Array.from(cbs).map(cb => cb.value))];
        }

        function updateDiffSatuanSelectedCount() {
            const selected = getSelectedDiffSatCodes();
            const lbl = document.getElementById('diffSatuanSelectedCount');
            const btn = document.getElementById('btnSyncSelectedSatuan');
            if (lbl) lbl.innerText = selected.length;
            if (btn) btn.disabled = (selected.length === 0);

            const masterCb = document.getElementById('checkAllDiffSatuan');
            const allCbs = document.querySelectorAll('.diff-sat-cb');
            if (masterCb && allCbs.length > 0) {
                masterCb.checked = (selected.length === allCbs.length);
            }
        }

        async function syncSingleSatuan(kodeBarang, satMaster) {
            await executeSyncSatuan('selected', [kodeBarang], satMaster);
        }

        async function executeSyncSatuan(mode = 'all', itemArray = null, satTarget = null) {
            let itemsToSync = [];
            if (mode === 'selected') {
                itemsToSync = Array.isArray(itemArray) ? itemArray : getSelectedDiffSatCodes();
                if (itemsToSync.length === 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Tidak Ada Item Terpilih',
                        text: 'Silakan centang setidaknya 1 item barang yang ingin diselaraskan satuannya.'
                    });
                    return;
                }
            }

            const isAll = (mode === 'all');
            const confirm = await Swal.fire({
                icon: 'question',
                title: isAll ? 'Selaraskan SEMUA Satuan Transaksi?' : `Selaraskan ${itemsToSync.length} Item Terpilih?`,
                html: isAll 
                    ? `Sistem akan menyamakan seluruh kolom <b>Satuan Transaksi</b> (di IT Inventory, Pengeluaran, Stock Opname, dll.) agar berpatokan pada <b>Satuan Resmi di Master Data Barang</b> (<code>bc_material.KODE_SATUAN</code>).<br><br>Lanjutkan proses sinkronisasi massal?`
                    : `Sistem akan menyamakan Satuan Transaksi untuk <b>${itemsToSync.length} kode barang</b> terpilih agar mengikuti Master Data Barang.<br><br>Lanjutkan proses sinkronisasi?`,
                showCancelButton: true,
                confirmButtonText: 'Ya, Selaraskan Sekarang',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#08979c'
            });
            if (!confirm.isConfirmed) return;

            Swal.fire({
                title: 'Menyelaraskan Satuan...',
                text: 'Memperbarui satuan transaksi mengikuti master data barang...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            const fd = new FormData();
            fd.append('action', 'sync_satuan');
            fd.append('mode', mode);
            if (mode === 'selected') {
                fd.append('items', JSON.stringify(itemsToSync));
            }

            try {
                const res = await fetch('material.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    await Swal.fire({
                        icon: 'success',
                        title: 'Sinkronisasi Satuan Berhasil!',
                        html: `<pre style="text-align:left; font-size:11px; background:#f6ffed; border:1px solid #b7eb8f; border-radius:4px; padding:10px; white-space:pre-wrap;">${escapeHtml(json.message)}</pre>`,
                        confirmButtonColor: '#08979c'
                    });
                    loadDiffSatuanReport();
                    loadData();
                } else {
                    Swal.fire({ icon: 'error', title: 'Gagal Sinkronisasi', text: json.message });
                }
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Koneksi gagal: ' + err.message });
            }
        }

        function openAddModal() {
            document.getElementById('modalTitle').innerHTML = '<i class="fa-solid fa-plus"></i> Tambah Item Barang Baru';
            document.getElementById('modalIsEdit').value = '0';
            document.getElementById('modalKode').value = '';
            document.getElementById('modalKode').removeAttribute('readonly');
            document.getElementById('modalKode2').value = '';
            document.getElementById('modalUraian').value = '';
            document.getElementById('modalModel').value = '';
            document.getElementById('modalHs').value = '';
            document.getElementById('modalSatuan').value = 'PCE';
            document.getElementById('modalSatuan2').value = '';
            document.getElementById('modalKonversi').value = '1';
            document.getElementById('modalHarga').value = '0.00';
            document.getElementById('modalValuta').value = 'IDR';
            document.getElementById('modalLokasi').value = '';
            document.getElementById('modalNote').value = '';
            document.getElementById('modalForm').style.display = 'flex';
        }

        function openEditModal(idx) {
            const r = rawItemsData[idx];
            if (!r) return;
            document.getElementById('modalTitle').innerHTML = `<i class="fa-solid fa-pen"></i> Edit Item: ${escapeHtml(r.KODE_BARANG)}`;
            document.getElementById('modalIsEdit').value = '1';
            document.getElementById('modalKode').value = r.KODE_BARANG;
            document.getElementById('modalKode').setAttribute('readonly', 'readonly');
            document.getElementById('modalKode2').value = r.KODE_BARANG2 || '';
            document.getElementById('modalUraian').value = r.URAIAN || '';
            document.getElementById('modalModel').value = r.MODEL || '';
            document.getElementById('modalHs').value = r.POS_TARIF || '';
            document.getElementById('modalSatuan').value = r.KODE_SATUAN || 'PCE';
            document.getElementById('modalSatuan2').value = r.KODE_SATUAN2 || '';
            document.getElementById('modalKonversi').value = r.KONVERSI || '1';
            document.getElementById('modalHarga').value = r.UNIT_PRICE || '0.00';
            document.getElementById('modalValuta').value = r.KODE_VALUTA || 'IDR';
            document.getElementById('modalKlas').value = r.KLAS || '';
            document.getElementById('modalLokasi').value = r.LOKASI || '';
            document.getElementById('modalNote').value = r.NOTE || '';
            document.getElementById('modalForm').style.display = 'flex';
        }

        async function saveItem() {
            const isEdit = document.getElementById('modalIsEdit').value;
            const kode = document.getElementById('modalKode').value.trim();
            const kode2 = document.getElementById('modalKode2').value.trim();
            const uraian = document.getElementById('modalUraian').value.trim();
            const model = document.getElementById('modalModel').value.trim();
            const hs = document.getElementById('modalHs').value.trim();
            const sat = document.getElementById('modalSatuan').value.trim();
            const sat2 = document.getElementById('modalSatuan2').value.trim();
            const konversi = document.getElementById('modalKonversi').value;
            const harga = document.getElementById('modalHarga').value;
            const valuta = document.getElementById('modalValuta').value.trim();
            const klas = document.getElementById('modalKlas').value;
            const lokasi = document.getElementById('modalLokasi').value.trim();
            const note = document.getElementById('modalNote').value.trim();

            if (!kode || !uraian) {
                Swal.fire('Peringatan', 'Kode Barang dan Uraian wajib diisi!', 'warning');
                return;
            }

            const fd = new FormData();
            fd.append('action', 'save');
            fd.append('is_edit', isEdit);
            fd.append('kode_barang', kode);
            fd.append('kode_barang2', kode2);
            fd.append('uraian', uraian);
            fd.append('model', model);
            fd.append('pos_tarif', hs);
            fd.append('kode_satuan', sat);
            fd.append('kode_satuan2', sat2);
            fd.append('konversi', konversi);
            fd.append('unit_price', harga);
            fd.append('kode_valuta', valuta);
            fd.append('klas', klas);
            fd.append('lokasi', lokasi);
            fd.append('note', note);

            try {
                const res = await fetch('material.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire('Berhasil', json.message, 'success');
                    closeModal('modalForm');
                    loadData();
                } else {
                    Swal.fire('Error', json.message, 'error');
                }
            } catch (err) {
                Swal.fire('Error', err.message, 'error');
            }
        }

        async function deleteItem(kode) {
            const ok = await Swal.fire({
                title: 'Konfirmasi Hapus',
                text: `Hapus item barang: ${kode}?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ff4d4f',
                confirmButtonText: 'Ya, Hapus'
            });
            if (!ok.isConfirmed) return;

            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('kode_barang', kode);

            try {
                const res = await fetch('material.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire('Berhasil', json.message, 'success');
                    loadData();
                } else {
                    Swal.fire('Error', json.message, 'error');
                }
            } catch (e) { Swal.fire('Error', e.message, 'error'); }
        }

        // ================= EXCEL EXPORT & IMPORT ================= //
        function exportExcel() {
            if (rawItemsData.length === 0) {
                Swal.fire('Informasi', 'Tidak ada data untuk diekspor.', 'info');
                return;
            }
            const exportRows = rawItemsData.map((r, i) => ({
                'No': i + 1,
                'Kode Barang': r.KODE_BARANG,
                'Part No': r.KODE_BARANG2 || '',
                'Uraian Barang': r.URAIAN,
                'Model': r.MODEL || '',
                'HS Code': r.POS_TARIF || '',
                'Satuan': r.KODE_SATUAN || 'PCE',
                'Harga Satuan': Number(r.UNIT_PRICE || 0),
                'Valuta': r.KODE_VALUTA || 'IDR',
                'Klasifikasi': r.KLAS || '',
                'Lokasi': r.LOKASI || '',
                'Catatan': r.NOTE || ''
            }));

            const ws = XLSX.utils.json_to_sheet(exportRows);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Master Barang");
            XLSX.writeFile(wb, `Master_Barang_${new Date().toISOString().slice(0,10)}.xlsx`);
        }

        function openImportModal() {
            document.getElementById('excelFileInput').value = '';
            document.getElementById('importPreviewArea').style.display = 'none';
            document.getElementById('btnExecuteImport').style.display = 'none';
            parsedExcelRows = [];
            document.getElementById('modalImport').style.display = 'flex';
        }

        function downloadTemplate() {
            const templateData = [
                {
                    'Kode Barang': 'RM-FABRIC-001',
                    'Part No': 'FAB-POL-BLK',
                    'Uraian Barang': 'Kain Poliester Hitam Tebal',
                    'Model': '100% Polyester',
                    'HS Code': '54075200',
                    'Satuan': 'MTR',
                    'Harga': 25000,
                    'Valuta': 'IDR',
                    'Klasifikasi': 'Raw Materials',
                    'Lokasi': 'Gudang A-1',
                    'Catatan': 'Bahan Utama'
                },
                {
                    'Kode Barang': 'FG-SHIRT-M',
                    'Part No': 'TS-001-M',
                    'Uraian Barang': 'T-Shirt Lengan Pendek Ukuran M',
                    'Model': 'Casual Slim Fit',
                    'HS Code': '61091010',
                    'Satuan': 'PCE',
                    'Harga': 75000,
                    'Valuta': 'IDR',
                    'Klasifikasi': 'Finished Goods',
                    'Lokasi': 'Gudang FG',
                    'Catatan': 'Siap Jual'
                }
            ];
            const ws = XLSX.utils.json_to_sheet(templateData);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Template_Material");
            XLSX.writeFile(wb, "Template_Import_Master_Barang.xlsx");
        }

        function handleExcelUpload(event) {
            const file = event.target.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = function(e) {
                try {
                    const data = new Uint8Array(e.target.result);
                    const workbook = XLSX.read(data, { type: 'array' });
                    const firstSheet = workbook.SheetNames[0];
                    const rows = XLSX.utils.sheet_to_json(workbook.Sheets[firstSheet]);

                    if (rows.length === 0) {
                        Swal.fire('Error', 'File Excel kosong atau format tidak sesuai.', 'error');
                        return;
                    }

                    parsedExcelRows = rows;
                    document.getElementById('importRowCount').innerText = rows.length;
                    
                    const tbody = document.getElementById('importPreviewBody');
                    tbody.innerHTML = rows.slice(0, 50).map((r, i) => `
                        <tr>
                            <td>${i + 1}</td>
                            <td style="font-weight:600; font-family:monospace;">${escapeHtml(r['Kode Barang'] || r.kode_barang || r.KODE_BARANG || '-')}</td>
                            <td>${escapeHtml(r['Part No'] || r.kode_barang2 || '-')}</td>
                            <td>${escapeHtml(r['Uraian Barang'] || r.uraian || r.Nama || '-')}</td>
                            <td>${escapeHtml(r['HS Code'] || r.pos_tarif || '-')}</td>
                            <td>${escapeHtml(r['Satuan'] || r.kode_satuan || 'PCE')}</td>
                            <td>${Number(r['Harga'] || r.unit_price || 0).toLocaleString('id-ID')}</td>
                            <td>${escapeHtml(r['Klasifikasi'] || r.klas || 'Raw Materials')}</td>
                        </tr>
                    `).join('');

                    document.getElementById('importPreviewArea').style.display = 'block';
                    document.getElementById('btnExecuteImport').style.display = 'inline-flex';
                } catch (err) {
                    Swal.fire('Error Baca File', err.message, 'error');
                }
            };
            reader.readAsArrayBuffer(file);
        }

        async function executeImport() {
            if (!parsedExcelRows.length) return;
            const btn = document.getElementById('btnExecuteImport');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menyimpan ke Database...';

            const fd = new FormData();
            fd.append('action', 'import_excel');
            fd.append('rows', JSON.stringify(parsedExcelRows));

            try {
                const res = await fetch('material.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire('Sukses Impor', json.message, 'success');
                    closeModal('modalImport');
                    loadData();
                } else {
                    Swal.fire('Gagal Impor', json.message, 'error');
                }
            } catch (err) {
                Swal.fire('Error Server', err.message, 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-upload"></i> Proses Simpan ke Database';
            }
        }

        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }

        function escapeHtml(text) {
            if (!text) return '';
            return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // -------------------------------------------------------------
        // FITUR IMPORT DARI DATABASE: IT INVENTORY & STAGING PABEAN
        // -------------------------------------------------------------
        const ALLOW_LOWERCASE = <?= $allowLowercase ? 'true' : 'false' ?>;
        const KLAS_OPTIONS = <?= json_encode($klasList) ?>;
        let dbImportCandidates = [];
        let currentImportSource = '1ab2';

        function openDbImportModal(source) {
            currentImportSource = source;
            const modal = document.getElementById('modalDbImport');
            modal.style.display = 'flex';

            const is1ab2 = (source === '1ab2');
            document.getElementById('dbImportModalIcon').innerHTML = is1ab2 ? '📦' : '📑';
            document.getElementById('dbImportModalTitle').innerText = is1ab2 
                ? ('Import Master Barang dari IT Inventory' + (isAdmin ? ' (bc_1ab2)' : '')) 
                : ('Import Master Barang dari Staging Pabean' + (isAdmin ? ' (bc_ceisa_barang)' : ''));
            document.getElementById('dbImportSourceBadge').innerText = is1ab2 
                ? ('IT Inventory' + (isAdmin ? ' (bc_1ab2)' : '')) 
                : ('Staging Pabean' + (isAdmin ? ' (bc_ceisa_barang)' : ''));
            document.getElementById('dbImportSourceBadge').style.background = is1ab2 ? '#f9f0ff' : '#e6fffb';
            document.getElementById('dbImportSourceBadge').style.color = is1ab2 ? '#722ed1' : '#13c2c2';
            document.getElementById('dbImportSourceBadge').style.borderColor = is1ab2 ? '#d3adf7' : '#87e8de';

            // Reset filter
            document.getElementById('dbImportSearch').value = '';
            document.getElementById('dbImportFilterStatus').value = 'all';

            loadDbImportCandidates(source);
        }

        async function loadDbImportCandidates(source) {
            const tbody = document.getElementById('dbImportTableBody');
            tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:25px;"><i class="fa-solid fa-spinner fa-spin"></i> Menghubungi database & memproses deduplikasi (MAX)...</td></tr>';

            try {
                const res = await fetch(`material.php?action=get_import_candidates&source=${source}`);
                const json = await res.json();
                if (json.status === 'OK') {
                    dbImportCandidates = json.data;
                    renderDbImportTable();
                } else {
                    tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; color:red; padding:20px;">Gagal memuat kandidat: ${json.message}</td></tr>`;
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; color:red; padding:20px;">Error koneksi: ${err.message}</td></tr>`;
            }
        }

        function filterCandidateRows() {
            renderDbImportTable();
        }

        function renderDbImportTable() {
            const tbody = document.getElementById('dbImportTableBody');
            const search = (document.getElementById('dbImportSearch').value || '').trim().toLowerCase();
            const statusFilter = document.getElementById('dbImportFilterStatus').value;

            let totalCount = dbImportCandidates.length;
            let newCount = 0;
            let existCount = 0;
            let minusCount = 0;
            let invalidKlasCount = 0;
            let selectedCount = 0;

            dbImportCandidates.forEach(it => {
                const isBadCode = (it.is_minus || it.kode_barang.trim() === '-' || it.kode_barang.trim() === '');
                const isBadKlas = (!it.klas || it.klas.trim() === '' || it.klas.trim() === '-');
                if (isBadCode) minusCount++;
                if (isBadKlas) invalidKlasCount++;
                if (it.exists) existCount++;
                else newCount++;
                if (it.selected) selectedCount++;
            });

            document.getElementById('dbImportTotalCount').innerText = totalCount.toLocaleString('id-ID');
            document.getElementById('dbImportNewCount').innerText = newCount.toLocaleString('id-ID');
            document.getElementById('dbImportExistCount').innerText = existCount.toLocaleString('id-ID');
            document.getElementById('dbImportMinusCount').innerText = minusCount.toLocaleString('id-ID');
            document.getElementById('dbImportInvalidKlasCount').innerText = invalidKlasCount.toLocaleString('id-ID');
            document.getElementById('dbImportSelectedCount').innerText = selectedCount.toLocaleString('id-ID');

            // Filter items
            const filtered = dbImportCandidates.map((it, idx) => ({ ...it, originalIndex: idx })).filter(it => {
                const matchSearch = (!search || 
                    it.kode_barang.toLowerCase().includes(search) || 
                    it.uraian.toLowerCase().includes(search) || 
                    it.pos_tarif.toLowerCase().includes(search)
                );
                if (!matchSearch) return false;

                const isBadCode = (it.is_minus || it.kode_barang.trim() === '-' || it.kode_barang.trim() === '');
                const isBadKlas = (!it.klas || it.klas.trim() === '' || it.klas.trim() === '-');

                if (statusFilter === 'new') return !it.exists && !isBadCode;
                if (statusFilter === 'exist') return it.exists && !isBadCode;
                if (statusFilter === 'minus') return isBadCode;
                if (statusFilter === 'invalid_klas') return isBadKlas;
                return true;
            });

            if (filtered.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:20px; color:var(--text-muted);">Tidak ada data yang sesuai filter pencarian.</td></tr>';
                validateDbImportState();
                return;
            }

            let html = '';
            filtered.forEach((it, fIdx) => {
                const idx = it.originalIndex;
                const isBadCode = (it.is_minus || it.kode_barang.trim() === '-' || it.kode_barang.trim() === '');
                const isBadKlas = (!it.klas || it.klas.trim() === '' || it.klas.trim() === '-');

                let badgeHtml = '';
                if (isBadCode) {
                    badgeHtml = '<span class="tag-badge" style="background:#fff2f0; color:#ff4d4f; border:1px solid #ffccc7;"><i class="fa-solid fa-ban"></i> Kode "-"</span>';
                } else if (it.exists) {
                    badgeHtml = '<span class="tag-badge" style="background:#f6ffed; color:#52c41a; border:1px solid #b7eb8f;">Update</span>';
                } else {
                    badgeHtml = '<span class="tag-badge" style="background:#e6f7ff; color:#1890ff; border:1px solid #91d5ff;">Baru</span>';
                }

                let klasSelectHtml = `<select class="form-control ${isBadKlas ? 'input-error' : ''}" style="width:100%; font-size:10px;" onchange="updateCandidateKlas(${idx}, this.value)">`;
                klasSelectHtml += `<option value="">-- WAJIB PILIH KLAS --</option>`;
                KLAS_OPTIONS.forEach(k => {
                    const sel = (k.tipe === it.klas) ? 'selected' : '';
                    klasSelectHtml += `<option value="${escapeHtml(k.tipe)}" ${sel}>${escapeHtml(k.tipe)}</option>`;
                });
                klasSelectHtml += `</select>`;

                html += `
                    <tr style="${isBadCode ? 'background:#fff8f7;' : ''}">
                        <td style="text-align:center;">
                            <input type="checkbox" ${it.selected ? 'checked' : ''} onchange="toggleSelectCandidate(${idx}, this.checked)">
                        </td>
                        <td style="text-align:center; color:var(--text-muted);">${fIdx + 1}</td>
                        <td style="text-align:center;">${badgeHtml}</td>
                        <td>
                            <div style="display:flex; align-items:center; gap:3px;">
                                <input type="text" 
                                       class="form-control ${isBadCode ? 'input-error' : ''}" 
                                       value="${escapeHtml(it.kode_barang)}" 
                                       style="width:100%; font-family:monospace; font-weight:600; font-size:10px;"
                                       placeholder="Kode Barang" 
                                       oninput="updateCandidateCode(${idx}, this.value)">
                                ${isBadCode ? `
                                    <button type="button" class="ant-btn ant-btn-sm" style="background:#fa8c16; color:#fff; border-color:#fa8c16; flex-shrink:0;" title="Auto Generate Kode" onclick="autoGenerateSingleCode(${idx})">
                                        <i class="fa-solid fa-wand-magic-sparkles"></i>
                                    </button>
                                ` : ''}
                            </div>
                            ${isBadCode ? '<div style="font-size:8.5px; color:#ff4d4f; margin-top:2px; font-weight:600;">⛔ Wajib diganti (Dilarang "-")</div>' : ''}
                        </td>
                        <td>
                            <input type="text" class="form-control" value="${escapeHtml(it.uraian)}" style="width:100%; font-size:10px;" oninput="updateCandidateUraian(${idx}, this.value)">
                        </td>
                        <td>
                            <input type="text" class="form-control" value="${escapeHtml(it.pos_tarif)}" style="width:100%; font-size:10px; font-family:monospace;" oninput="updateCandidateHs(${idx}, this.value)">
                        </td>
                        <td>
                            <input type="text" class="form-control" value="${escapeHtml(it.kode_satuan)}" style="width:100%; font-size:10px; text-transform:uppercase;" oninput="updateCandidateSatuan(${idx}, this.value)">
                        </td>
                        <td>
                            ${klasSelectHtml}
                            ${isBadKlas ? '<div style="font-size:8.5px; color:#faad14; margin-top:2px; font-weight:600;">⚠️ KLAS Tidak Boleh Kosong</div>' : ''}
                        </td>
                        <td style="text-align:center; font-weight:600; color:var(--text-muted);">
                            ${it.jml_transaksi || 1}
                        </td>
                    </tr>
                `;
            });

            tbody.innerHTML = html;
            validateDbImportState();
        }

        function toggleSelectAllCandidates(checked) {
            dbImportCandidates.forEach(it => it.selected = checked);
            renderDbImportTable();
        }

        function toggleSelectCandidate(idx, checked) {
            dbImportCandidates[idx].selected = checked;
            const selCount = dbImportCandidates.filter(it => it.selected).length;
            document.getElementById('dbImportSelectedCount').innerText = selCount.toLocaleString('id-ID');
            validateDbImportState();
        }

        function updateCandidateCode(idx, val) {
            if (!ALLOW_LOWERCASE) {
                val = val.toUpperCase();
            }
            dbImportCandidates[idx].kode_barang = val;
            dbImportCandidates[idx].is_minus = (val.trim() === '-' || val.trim() === '');
            validateDbImportState();
        }

        function updateCandidateUraian(idx, val) {
            dbImportCandidates[idx].uraian = val;
        }

        function updateCandidateHs(idx, val) {
            dbImportCandidates[idx].pos_tarif = val.replace(/[^0-9]/g, '');
        }

        function updateCandidateSatuan(idx, val) {
            dbImportCandidates[idx].kode_satuan = val.toUpperCase().trim();
        }

        function updateCandidateKlas(idx, val) {
            dbImportCandidates[idx].klas = val;
            dbImportCandidates[idx].is_klas_valid = (val.trim() !== '' && val.trim() !== '-');
            validateDbImportState();
        }

        function applyBulkKlasToSelected() {
            const bulkKlas = document.getElementById('dbImportBulkKlas').value;
            if (!bulkKlas) return;

            let updated = 0;
            dbImportCandidates.forEach(it => {
                if (it.selected) {
                    it.klas = bulkKlas;
                    it.is_klas_valid = true;
                    updated++;
                }
            });

            renderDbImportTable();
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: `Klasifikasi '${bulkKlas}' berhasil diterapkan ke ${updated} baris terpilih!`,
                showConfirmButton: false,
                timer: 2000
            });
        }

        function autoGenerateCodesForMinus() {
            const existingCodes = new Set();
            dbImportCandidates.forEach(it => {
                const k = (it.kode_barang || '').trim().toUpperCase();
                if (k && k !== '-') existingCodes.add(k);
            });

            let count = 0;
            dbImportCandidates.forEach(it => {
                if (it.is_minus || it.kode_barang.trim() === '-' || it.kode_barang.trim() === '') {
                    it.kode_barang = generateUniqueItemCode(it.uraian, existingCodes);
                    it.is_minus = false;
                    count++;
                }
            });

            renderDbImportTable();
            Swal.fire({
                icon: 'success',
                title: 'Auto-Kode Berhasil',
                text: `${count} item dengan kode '-' telah dibuatkan kode unik otomatis.`
            });
        }

        function autoGenerateSingleCode(idx) {
            const existingCodes = new Set();
            dbImportCandidates.forEach(it => {
                const k = (it.kode_barang || '').trim().toUpperCase();
                if (k && k !== '-') existingCodes.add(k);
            });

            const it = dbImportCandidates[idx];
            it.kode_barang = generateUniqueItemCode(it.uraian, existingCodes);
            it.is_minus = false;
            renderDbImportTable();
        }

        function generateUniqueItemCode(uraian, existingCodes) {
            let clean = (uraian || 'BARANG')
                .toUpperCase()
                .replace(/[^A-Z0-9]/g, ' ')
                .trim()
                .split(/\s+/)
                .slice(0, 3)
                .join('-');
            if (!clean) clean = 'ITEM';
            let base = 'BRG-' + clean;
            if (base.length > 25) base = base.substring(0, 25);

            let code = base;
            let seq = 1;
            while (existingCodes.has(code.toUpperCase())) {
                code = `${base}-${seq}`;
                seq++;
            }
            existingCodes.add(code.toUpperCase());
            return code;
        }

        function validateDbImportState() {
            const alertBox = document.getElementById('dbImportValidationAlert');
            const alertMsg = document.getElementById('dbImportValidationMsg');
            const btn = document.getElementById('btnExecuteDbImport');

            const selected = dbImportCandidates.filter(it => it.selected);
            if (selected.length === 0) {
                alertBox.style.display = 'block';
                alertMsg.innerText = 'Pilih setidaknya 1 baris item untuk diimport.';
                btn.disabled = true;
                return false;
            }

            const badMinus = selected.filter(it => it.is_minus || it.kode_barang.trim() === '-' || it.kode_barang.trim() === '');
            if (badMinus.length > 0) {
                alertBox.style.display = 'block';
                alertMsg.innerHTML = `<strong>DILARANG KERAS:</strong> Terdapat <b>${badMinus.length} item</b> terpilih dengan Kode Barang bernilai <code>'-'</code> atau kosong! Klik tombol <b>'Auto-Kode untuk -'</b> atau isi kode barang baru sebelum melanjutkan.`;
                btn.disabled = true;
                return false;
            }

            const badKlas = selected.filter(it => !it.klas || it.klas.trim() === '' || it.klas.trim() === '-');
            if (badKlas.length > 0) {
                alertBox.style.display = 'block';
                alertMsg.innerHTML = `<strong>KLASIFIKASI WAJIB:</strong> Terdapat <b>${badKlas.length} item</b> terpilih yang belum memiliki Klasifikasi (KLAS)! Gunakan fitur <b>'Set Default KLAS'</b> atau pilih klasifikasi pada masing-masing baris.`;
                btn.disabled = true;
                return false;
            }

            alertBox.style.display = 'none';
            btn.disabled = false;
            return true;
        }

        async function executeDbImport() {
            if (!validateDbImportState()) return;

            const selectedItems = dbImportCandidates.filter(it => it.selected);
            const btn = document.getElementById('btnExecuteDbImport');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menyimpan ke Master Data...';

            const fd = new FormData();
            fd.append('action', 'save_imported_materials');
            fd.append('items', JSON.stringify(selectedItems));

            try {
                const res = await fetch('material.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Import Master Data Berhasil!',
                        html: json.message,
                        confirmButtonText: 'OK'
                    });
                    closeModal('modalDbImport');
                    loadData();
                } else {
                    Swal.fire('Gagal Import', json.message, 'error');
                }
            } catch (err) {
                Swal.fire('Error Server', err.message, 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-cloud-arrow-down"></i> Proses Import ke Master Data';
            }
        }

        window.onload = loadData;
    </script>
</body>
</html>

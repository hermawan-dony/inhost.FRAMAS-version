<?php
/**
 * CEISA 4.0 - Add-on Module: Load ERP Data (Odoo to CEISA 4.0)
 * File: load_erp_odoo.php
 * 
 * Mengadaptasi penuh window PowerBuilder: w_load_erp_odoo.srw
 * Mengintegrasikan backend dari: share/rmb/rmb/
 * - odoo_config.php & odoo_sync_out.php
 * - Tabel Staging: bc_ceisa_odoo_header, bc_ceisa_odoo_detail, bc_ceisa_odoo_bom
 * - Transfer ke Staging CEISA 4.0: bc_ceisa_header, bc_ceisa_barang, bc_ceisa_dokumen, bc_ceisa_kemasan, bc_ceisa_bahanbaku
 */

session_start();
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/erp/odoo/OdooSyncService.php';

$cmpId = trim($_GET['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ''));
session_write_close(); // Lepaskan lock sesi agar tidak memblokir request ajax lain

$escCmp = $koneksi->real_escape_string($cmpId);

// Ambil profil perusahaan
$company = [];
if (!empty($cmpId)) {
    $qCmp = $koneksi->query("SELECT * FROM bc_ceisa_company WHERE cmp_id = '$escCmp' LIMIT 1");
    if ($qCmp && $rCmp = $qCmp->fetch_assoc()) {
        $company = $rCmp;
    }
}

$action = trim($_REQUEST['action'] ?? '');

// -------------------------------------------------------------
// 1. AJAX API: SINKRONISASI REALTIME KE ODOO API (cb_30)
// -------------------------------------------------------------
if ($action === 'sync_odoo') {
    @set_time_limit(300);
    @ini_set('max_execution_time', '300');
    header('Content-Type: application/json; charset=utf-8');
    $dt1 = trim($_POST['date_from'] ?? date('Y-m-d'));
    $dt2 = trim($_POST['date_to'] ?? $dt1);
    $kodeBc = trim($_POST['kode_dokumen'] ?? '25');

    try {
        $service = new OdooSyncService($koneksi, $company);
        $res = $service->syncOutbound($dt1, $dt2, $kodeBc);
        echo json_encode(['status' => 'OK', 'data' => $res]);
    } catch (\Exception $e) {
        echo json_encode(['status' => 'ERROR', 'message' => $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------
// 2. AJAX API: LOAD DATA INVOICE ODOO (dw_1, cb_1)
// -------------------------------------------------------------
if ($action === 'load_invoices') {
    header('Content-Type: application/json; charset=utf-8');
    $dt1    = trim($_GET['date_from'] ?? date('Y-m-d', strtotime('-5 days')));
    $dt2    = trim($_GET['date_to'] ?? date('Y-m-d'));
    $search = trim($_GET['search'] ?? '');
    $bcType = trim($_GET['bc_type'] ?? '');

    $escDt1 = $koneksi->real_escape_string($dt1);
    $escDt2 = $koneksi->real_escape_string($dt2);

    $where = "WHERE (h.tanggal_invoice BETWEEN '$escDt1' AND '$escDt2')";
    if (!empty($cmpId) && $cmpId !== 'ALL') {
        $where .= " AND (h.cmp_id = '$escCmp' OR h.cmp_id = '')";
    }
    if (!empty($search)) {
        $escS = $koneksi->real_escape_string($search);
        $where .= " AND (h.nomor_invoice LIKE '%$escS%' OR h.nomor_surat_jalan LIKE '%$escS%' OR h.customer_nama LIKE '%$escS%' OR h.nomor_so LIKE '%$escS%')";
    }
    if (!empty($bcType) && $bcType !== 'ALL') {
        $cleanBc = preg_replace('/[^0-9]/', '', $bcType);
        if (!empty($cleanBc)) {
            $where .= " AND (h.kode_dokumen_bc = '$cleanBc' OR h.kode_dokumen_bc = '' OR h.kode_dokumen_bc IS NULL)";
        }
    }

    $sql = "SELECT h.*, 
            (SELECT COUNT(1) FROM bc_ceisa_odoo_detail d WHERE d.invoice_id = h.invoice_id) as total_items,
            (SELECT COUNT(1) FROM bc_ceisa_odoo_bom b WHERE b.invoice_id = h.invoice_id) as total_bom
            FROM bc_ceisa_odoo_header h $where 
            ORDER BY h.tanggal_invoice DESC, h.invoice_id DESC LIMIT 300";

    $res = $koneksi->query($sql);
    $list = [];
    $kpi = ['total_inv' => 0, 'total_fob' => 0, 'total_netto' => 0, 'total_bom' => 0];

    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $list[] = $r;
            $kpi['total_inv']++;
            $kpi['total_fob'] += (float)$r['total_fob'];
            $kpi['total_netto'] += (float)$r['berat_netto_total'];
            $kpi['total_bom'] += (int)$r['total_bom'];
        }
    }

    echo json_encode([
        'status' => 'OK',
        'kpi'    => $kpi,
        'rows'   => $list
    ]);
    exit;
}

// -------------------------------------------------------------
// 3. AJAX API: LOAD DETAIL BARANG & BOM INVOICE (dw_2 & dw_bb)
// -------------------------------------------------------------
if ($action === 'load_invoice_details') {
    header('Content-Type: application/json; charset=utf-8');
    $invId = (int)($_GET['invoice_id'] ?? 0);

    if ($invId <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Invoice ID tidak valid']);
        exit;
    }

    // Detail barang
    $qD = $koneksi->query("SELECT * FROM bc_ceisa_odoo_detail WHERE invoice_id = $invId ORDER BY seri_barang ASC");
    $details = [];
    if ($qD) {
        while ($rD = $qD->fetch_assoc()) {
            $details[] = $rD;
        }
    }

    // Bahan Baku BOM
    $qB = $koneksi->query("SELECT * FROM bc_ceisa_odoo_bom WHERE invoice_id = $invId ORDER BY bom_id ASC");
    $boms = [];
    if ($qB) {
        while ($rB = $qB->fetch_assoc()) {
            $boms[] = $rB;
        }
    }

    // Summary BOM
    $qSum = $koneksi->query("SELECT kode_bahan_baku, nama_bahan_baku, hs_code_bahan_baku, satuan_bahan_baku, 
                            SUM(total_kebutuhan) as total_qty 
                            FROM bc_ceisa_odoo_bom 
                            WHERE invoice_id = $invId 
                            GROUP BY kode_bahan_baku, nama_bahan_baku, hs_code_bahan_baku, satuan_bahan_baku");
    $bomSummary = [];
    if ($qSum) {
        while ($rS = $qSum->fetch_assoc()) {
            $bomSummary[] = $rS;
        }
    }

    echo json_encode([
        'status'      => 'OK',
        'details'     => $details,
        'boms'        => $boms,
        'bom_summary' => $bomSummary
    ]);
    exit;
}

// -------------------------------------------------------------
// 4. AJAX API: TRANSFER DATA KE STAGING CEISA 4.0 (cb_2)
// -------------------------------------------------------------
if ($action === 'transfer_data') {
    header('Content-Type: application/json; charset=utf-8');
    $invId    = (int)($_POST['invoice_id'] ?? 0);
    $nomorAju = trim($_POST['nomor_aju'] ?? '');
    $kodeBc   = trim($_POST['kode_dokumen'] ?? '25');
    $cleanBc  = preg_replace('/[^0-9]/', '', $kodeBc) ?: '25';

    if ($invId <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Pilih invoice terlebih dahulu sebelum transfer data.']);
        exit;
    }

    // Ambil data header invoice Odoo
    $qH = $koneksi->query("SELECT * FROM bc_ceisa_odoo_header WHERE invoice_id = $invId LIMIT 1");
    $hdr = $qH ? $qH->fetch_assoc() : null;
    if (!$hdr) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Data invoice Odoo tidak ditemukan.']);
        exit;
    }

    // Generate Nomor AJU jika belum ada
    if (empty($nomorAju)) {
        // Format standar 26 digit: 6 digit kode dok (misal 000025) + 6 digit kantor (040300) + 8 digit tgl (YYYYMMDD) + 6 digit urut
        $kodeDok6 = str_pad($cleanBc, 6, '0', STR_PAD_LEFT);
        $kantor6  = str_pad($hdr['kode_kantor_bc'] ?: '040300', 6, '0', STR_PAD_LEFT);
        $tgl8     = date('Ymd');

        // Cari nomor urut terakhir hari ini
        $prefixAju = $kodeDok6 . $kantor6 . $tgl8;
        $qLast = $koneksi->query("SELECT nomor_aju FROM bc_ceisa_header WHERE nomor_aju LIKE '{$prefixAju}%' ORDER BY nomor_aju DESC LIMIT 1");
        $lastSeq = 0;
        if ($qLast && $rLast = $qLast->fetch_assoc()) {
            $lastSeq = (int)substr($rLast['nomor_aju'], -6);
        }
        $nextSeq = str_pad($lastSeq + 1, 6, '0', STR_PAD_LEFT);
        $nomorAju = $prefixAju . $nextSeq;
    }

    $escAju = $koneksi->real_escape_string($nomorAju);

    $koneksi->begin_transaction();
    try {
        $valuta  = $koneksi->real_escape_string($hdr['mata_uang'] ?: 'IDR');
        $ndpbm   = 1.0;
        $fob     = (float)$hdr['total_fob'];
        $fobIdr  = ($valuta === 'IDR') ? $fob : ($fob * $ndpbm);
        $netto   = (float)$hdr['berat_netto_total'];
        $bruto   = (float)$hdr['berat_bruto_total'];
        $volume  = (float)$hdr['volume_total'];
        $inco    = $koneksi->real_escape_string($hdr['incoterm'] ?: 'FOB');
        $tglAju  = date('Y-m-d');
        $userCmp = !empty($cmpId) ? $escCmp : '001';

        // 1. UPSERT bc_ceisa_header
        $chkHead = $koneksi->query("SELECT nomor_aju FROM bc_ceisa_header WHERE nomor_aju = '$escAju' LIMIT 1");
        if ($chkHead && $chkHead->num_rows > 0) {
            $koneksi->query("UPDATE bc_ceisa_header SET 
                kode_dokumen = '$cleanBc',
                kode_valuta = '$valuta',
                fob = $fob,
                cif = $fob,
                cif_rupiah = $fobIdr,
                harga_penyerahan = $fobIdr,
                netto = $netto,
                bruto = $bruto,
                volume = $volume,
                kode_incoterm = '$inco',
                tanggal_aju = '$tglAju'
                WHERE nomor_aju = '$escAju'");
        } else {
            $koneksi->query("INSERT INTO bc_ceisa_header (
                nomor_aju, cmp_id, kode_dokumen, kode_kantor, kode_valuta,
                fob, cif, cif_rupiah, harga_penyerahan, netto, bruto, volume,
                kode_incoterm, tanggal_aju, created_at
            ) VALUES (
                '$escAju', '$userCmp', '$cleanBc', '{$hdr['kode_kantor_bc']}', '$valuta',
                $fob, $fob, $fobIdr, $fobIdr, $netto, $bruto, $volume,
                '$inco', '$tglAju', NOW()
            )");
        }

        // 2. INSERT BARANG
        $koneksi->query("DELETE FROM bc_ceisa_barang WHERE nomor_aju = '$escAju'");
        $qD = $koneksi->query("SELECT * FROM bc_ceisa_odoo_detail WHERE invoice_id = $invId ORDER BY seri_barang ASC");
        $itemCount = 0;
        if ($qD) {
            while ($d = $qD->fetch_assoc()) {
                $itemCount++;
                $seriBarang = (int)$d['seri_barang'];
                $kodeBrg    = $koneksi->real_escape_string($d['kode_barang']);
                $uraian     = $koneksi->real_escape_string($d['nama_barang']);
                $hsCode     = preg_replace('/[^0-9]/', '', $d['hs_code']);
                $satuan     = $koneksi->real_escape_string($d['satuan'] ?: 'PCE');
                $qty        = (float)$d['jumlah'];
                $hrgSat     = (float)$d['harga_satuan'];
                $fobItem    = (float)$d['subtotal_fob'];
                $fobItemIdr = ($valuta === 'IDR') ? $fobItem : ($fobItem * $ndpbm);
                $nettoItem  = (float)$d['berat_netto'];
                $brutoItem  = $nettoItem * 1.05;
                $volItem    = (float)$d['volume'];
                $jmlKms     = (int)($d['jumlah_kemasan'] ?: 1);

                $koneksi->query("INSERT INTO bc_ceisa_barang (
                    nomor_aju, seri_barang, kode_barang, uraian, hs,
                    kode_satuan, jumlah_satuan, kode_kemasan, jumlah_kemasan,
                    netto, bruto, volume, ndpbm, harga_satuan, fob, cif, cif_rupiah,
                    harga_penyerahan, harga_perolehan, kode_asal_barang
                ) VALUES (
                    '$escAju', $seriBarang, '$kodeBrg', '$uraian', '$hsCode',
                    '$satuan', $qty, 'PK', $jmlKms,
                    $nettoItem, $brutoItem, $volItem, $ndpbm, $hrgSat, $fobItem, $fobItem, $fobItemIdr,
                    $fobItemIdr, 0, '1'
                )");
            }
        }
        $koneksi->query("UPDATE bc_ceisa_header SET jumlah_barang = $itemCount WHERE nomor_aju = '$escAju'");

        // 3. INSERT DOKUMEN PELENGKAP (380 Invoice, 217 Surat Jalan, BL)
        $koneksi->query("DELETE FROM bc_ceisa_dokumen WHERE nomor_aju = '$escAju' AND kode_dokumen IN ('380', '217', '705')");
        $seriDoc = 1;
        $escInv = $koneksi->real_escape_string($hdr['nomor_invoice']);
        $tglInv = $hdr['tanggal_invoice'] ?: date('Y-m-d');
        $koneksi->query("INSERT INTO bc_ceisa_dokumen (nomor_aju, seri, kode_dokumen, nomor_dokumen, tanggal_dokumen) 
                         VALUES ('$escAju', $seriDoc++, '380', '$escInv', '$tglInv')");

        if (!empty($hdr['nomor_surat_jalan'])) {
            $escSj = $koneksi->real_escape_string($hdr['nomor_surat_jalan']);
            $tglSj = substr($hdr['tanggal_kirim'] ?: $tglInv, 0, 10);
            $koneksi->query("INSERT INTO bc_ceisa_dokumen (nomor_aju, seri, kode_dokumen, nomor_dokumen, tanggal_dokumen) 
                             VALUES ('$escAju', $seriDoc++, '217', '$escSj', '$tglSj')");
        }

        // 4. INSERT KEMASAN
        $koneksi->query("DELETE FROM bc_ceisa_kemasan WHERE nomor_aju = '$escAju'");
        $escMerk = $koneksi->real_escape_string($hdr['customer_nama'] ?: 'RMB STANDARD');
        $koneksi->query("INSERT INTO bc_ceisa_kemasan (nomor_aju, seri, kode_kemasan, jumlah_kemasan, merek) 
                         VALUES ('$escAju', 1, 'PK', $itemCount, '$escMerk')");

        // 5. INSERT BAHAN BAKU / BOM (Jika ada)
        $koneksi->query("DELETE FROM bc_ceisa_bahanbaku WHERE nomor_aju = '$escAju'");
        $qB = $koneksi->query("SELECT * FROM bc_ceisa_odoo_bom WHERE invoice_id = $invId ORDER BY bom_id ASC");
        $bomCount = 0;
        if ($qB) {
            $seriBb = 1;
            while ($b = $qB->fetch_assoc()) {
                $bomCount++;
                $kdBb   = $koneksi->real_escape_string($b['kode_bahan_baku']);
                $nmBb   = $koneksi->real_escape_string($b['nama_bahan_baku']);
                $hsBb   = preg_replace('/[^0-9]/', '', $b['hs_code_bahan_baku']);
                $satBb  = $koneksi->real_escape_string($b['satuan_bahan_baku'] ?: 'PCE');
                $qtyBb  = (float)$b['total_kebutuhan'];
                $dokAsal = $koneksi->real_escape_string($b['eks_jenis_bc'] ?: '27');
                $nopenAsal = $koneksi->real_escape_string($b['eks_nomor_nopen'] ?: '');
                $tglAsal = $koneksi->real_escape_string($b['eks_tanggal_nopen'] ?: '');

                $koneksi->query("INSERT INTO bc_ceisa_bahanbaku (
                    cmp_id, nomor_aju, seri_barang, seri_bahan_baku, kode_asal_bahan_baku,
                    hs, kode_barang, uraian, kode_satuan, jumlah_satuan,
                    kode_dokumen_asal, nomor_daftar_asal, tanggal_daftar_asal
                ) VALUES (
                    '$userCmp', '$escAju', 1, $seriBb, '1',
                    '$hsBb', '$kdBb', '$nmBb', '$satBb', $qtyBb,
                    '$dokAsal', '$nopenAsal', '$tglAsal'
                )");
                $seriBb++;
            }
        }

        // 6. UPDATE ENTITAS (Customer / Penerima)
        $koneksi->query("DELETE FROM bc_ceisa_entitas WHERE nomor_aju = '$escAju' AND kode_entitas IN ('6', '7', '8')");
        if (!empty($hdr['customer_nama'])) {
            $custNm  = $koneksi->real_escape_string($hdr['customer_nama']);
            $custNp  = $koneksi->real_escape_string(preg_replace('/[^0-9]/', '', $hdr['customer_npwp']));
            $custAlm = $koneksi->real_escape_string(trim($hdr['customer_alamat'] . ' ' . $hdr['customer_kota']));
            $custCty = $koneksi->real_escape_string($hdr['customer_negara'] ?: 'ID');

            // Penerima (Kode 8)
            $koneksi->query("INSERT INTO bc_ceisa_entitas (nomor_aju, seri, kode_entitas, kode_jenis_identitas, nomor_identitas, nama_entitas, alamat_entitas, kode_negara) 
                             VALUES ('$escAju', 1, '8', '6', '$custNp', '$custNm', '$custAlm', '$custCty')");
            // Pembeli (Kode 6)
            $koneksi->query("INSERT INTO bc_ceisa_entitas (nomor_aju, seri, kode_entitas, kode_jenis_identitas, nomor_identitas, nama_entitas, alamat_entitas, kode_negara) 
                             VALUES ('$escAju', 2, '6', '6', '$custNp', '$custNm', '$custAlm', '$custCty')");
        }

        // 7. UPDATE STATUS DI bc_ceisa_odoo_header
        $koneksi->query("UPDATE bc_ceisa_odoo_header SET status_ceisa = 'TRANSFERRED', nomor_aju_ceisa = '$escAju', tanggal_aju_ceisa = NOW() WHERE invoice_id = $invId");

        $koneksi->commit();

        echo json_encode([
            'status'     => 'OK',
            'nomor_aju'  => $nomorAju,
            'message'    => "Data Odoo Invoice {$hdr['nomor_invoice']} berhasil ditransfer ke Dokumen CEISA 4.0 AJU $nomorAju!",
            'row_counts' => [
                'barang'    => $itemCount,
                'dokumen'   => $seriDoc - 1,
                'kemasan'   => 1,
                'bahanbaku' => $bomCount
            ]
        ]);
    } catch (\Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal transfer data: ' . $e->getMessage()]);
    }
    exit;
}

$themeParam = $_GET['theme'] ?? 'light';
if ($themeParam !== 'dark' && $themeParam !== 'light') $themeParam = 'light';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Load ERP Data - Odoo to CEISA 4.0 (w_load_erp_odoo)</title>
    <script>
        (function() {
            var urlParams = new URLSearchParams(window.location.search);
            var theme = urlParams.get('theme') || localStorage.getItem('ceisa_theme') || '<?= $themeParam ?>' || 'light';
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.style.colorScheme = theme;
        })();
    </script>
    <link rel="icon" type="image/png" href="/assets/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Source+Sans+Pro:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/sweetalert2.min.css">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js"></script>

    <style>
        :root, html[data-theme="light"] body {
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
            --kpi-bg: #ffffff;
            --kpi-border: #e8e8e8;
            color-scheme: light;
        }

        html[data-theme="dark"] body {
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
            --kpi-bg: #1f1f1f;
            --kpi-border: #303030;
            color-scheme: dark;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', 'Source Sans Pro', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 10.5px;
            line-height: 1.45;
            background: var(--bg-body);
            color: var(--text-main);
            padding: 6px 10px;
            -webkit-font-smoothing: antialiased;
        }

        .ant-card {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 3px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            margin-bottom: 6px;
            overflow: hidden;
        }
        .ant-card-head {
            min-height: 36px;
            padding: 0 10px;
            border-bottom: 1px solid var(--border-card);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
            flex-wrap: wrap;
        }
        .ant-card-head-title {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-heading);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .filter-panel {
            padding: 5px 8px;
            background: var(--bg-filter);
            border-bottom: 1px solid var(--border-card);
            display: flex;
            align-items: flex-end;
            gap: 6px;
            flex-wrap: wrap;
        }
        .filter-col {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .filter-col label {
            font-size: 9px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
        }
        .filter-control {
            height: 22px;
            padding: 1px 6px;
            font-size: 9.5px;
            font-family: inherit;
            border: 1px solid var(--border-input);
            border-radius: 2px;
            background: var(--bg-input);
            color: var(--text-main);
            outline: none;
            transition: border-color 0.2s;
        }
        .filter-control:focus {
            border-color: var(--primary);
        }

        .kpi-container {
            padding: 5px 8px;
            display: flex;
            gap: 6px;
            border-bottom: 1px solid var(--border-card);
            flex-wrap: wrap;
            background: var(--bg-card);
        }
        .kpi-chip {
            padding: 2px 8px;
            border-radius: 2px;
            background: var(--bg-filter);
            border: 1px solid var(--border-card);
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 9.5px;
        }
        .kpi-title {
            font-size: 9px;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
        }
        .kpi-val {
            font-weight: 700;
            font-size: 11px;
        }

        .ant-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            height: 22px;
            padding: 0 7px;
            font-size: 10px;
            font-weight: 500;
            border-radius: 2px;
            border: 1px solid var(--border-input);
            background: var(--bg-card);
            color: var(--text-main);
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            white-space: nowrap;
        }
        .ant-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        .ant-btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            color: #ffffff !important;
        }
        .ant-btn-success {
            background: #52c41a;
            border-color: #52c41a;
            color: #ffffff !important;
        }
        .ant-btn-warning {
            background: #faad14;
            border-color: #faad14;
            color: #ffffff !important;
        }
        .ant-btn-danger {
            background: #ff4d4f;
            border-color: #ff4d4f;
            color: #ffffff !important;
        }
        .ant-btn-sm {
            height: 18px;
            padding: 0 5px;
            font-size: 9px;
            border-radius: 2px;
        }

        /* Prominent Sync & Transfer Buttons (PowerBuilder Match) */
        .btn-pb-sync {
            background: linear-gradient(135deg, #722ed1, #531dab);
            color: #fff !important;
            border-color: #531dab;
            font-weight: 600;
        }
        .btn-pb-sync:hover {
            background: linear-gradient(135deg, #9254de, #722ed1);
        }
        .btn-pb-transfer {
            background: linear-gradient(135deg, #1890ff, #096dd9);
            color: #fff !important;
            border-color: #096dd9;
            font-weight: 700;
            font-size: 10.5px;
            padding: 0 10px;
        }
        .btn-pb-transfer:hover {
            background: linear-gradient(135deg, #40a9ff, #1890ff);
        }

        .table-scroll {
            width: 100%;
            max-height: 250px;
            overflow: auto;
            border-bottom: 1px solid var(--border-card);
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
            padding: 4px 6px;
            font-size: 9.5px;
            text-align: left;
            border-bottom: 1px solid var(--border-table);
            border-right: 1px solid var(--border-table);
            position: sticky;
            top: 0;
            z-index: 2;
            white-space: nowrap;
            user-select: none;
        }
        .ant-table td {
            padding: 3px 6px;
            font-size: 10px;
            border-bottom: 1px solid var(--border-table);
            border-right: 1px solid var(--border-table);
            white-space: nowrap;
            color: var(--text-main);
        }
        .ant-table tbody tr {
            cursor: pointer;
        }
        .ant-table tbody tr:hover td {
            background: var(--row-hover);
        }
        .ant-table tbody tr.is-selected td {
            background: rgba(24, 144, 255, 0.12) !important;
            font-weight: 600;
        }

        .ant-badge {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 2px;
            font-size: 9px;
            font-weight: 600;
            text-align: center;
        }
        .ant-badge-blue { background: #e6f7ff; color: #1890ff; border: 1px solid #91d5ff; }
        .ant-badge-green { background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f; }
        .ant-badge-gold { background: #fffbe6; color: #faad14; border: 1px solid #ffe58f; }
        .ant-badge-purple { background: #f9f0ff; color: #722ed1; border: 1px solid #d3adf7; }

        /* Detail Tabs */
        .detail-tabs-nav {
            display: flex;
            align-items: center;
            gap: 4px;
            background: var(--bg-filter);
            padding: 4px 8px 0;
            border-bottom: 1px solid var(--border-card);
        }
        .detail-tab-btn {
            height: 24px;
            padding: 0 10px;
            font-size: 9.5px;
            font-weight: 600;
            border: 1px solid transparent;
            border-bottom: none;
            border-radius: 3px 3px 0 0;
            background: transparent;
            color: var(--text-muted);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .detail-tab-btn.active {
            background: var(--bg-card);
            color: var(--primary);
            border-color: var(--border-card);
            border-bottom: 1px solid var(--bg-card);
            margin-bottom: -1px;
        }
    </style>
</head>
<body>

<div class="ant-card">
    <!-- Header Card -->
    <div class="ant-card-head">
        <div class="ant-card-head-title">
            <span style="font-size:14px; color:#722ed1;">🔌</span>
            <span>Load ERP Data - Odoo to CEISA 4.0</span>
            <span class="ant-badge ant-badge-purple" style="margin-left:4px;">Add-on Odoo (w_load_erp_odoo)</span>
            <span id="selectedInvoiceBadge" class="ant-badge ant-badge-blue" style="display:none;"></span>
        </div>
        <div style="display:flex; align-items:center; gap:4px; flex-wrap:wrap;">
            <button type="button" class="ant-btn btn-pb-sync" id="btnSyncOdoo" onclick="triggerSyncOdoo()" title="Tarik transaksi terbaru dari Server Odoo API (cb_30 PowerBuilder)">
                <i class="fa-solid fa-arrows-rotate"></i> Sync Odoo All
            </button>
            <button type="button" class="ant-btn" id="btnToggleBom" onclick="toggleBomView()" title="Tampilkan rincian Bahan Baku BOM (cb_4 PowerBuilder)">
                <i class="fa-solid fa-layer-group"></i> Lihat Bahan Baku
            </button>
            <button type="button" class="ant-btn btn-pb-transfer" id="btnTransferData" onclick="executeTransferData()" title="Transfer data ke staging Dokumen Pabean CEISA 4.0 (cb_2 PowerBuilder)">
                <i class="fa-solid fa-bolt"></i> TRANSFER DATA
            </button>
        </div>
    </div>

    <!-- Filter Bar (w_load_erp_odoo controls) -->
    <div class="filter-panel">
        <div class="filter-col">
            <label>Dokumen BC (sle_bc)</label>
            <select id="filterBcType" class="filter-control" style="width:110px;" onchange="loadInvoices()">
                <option value="25" selected>BC 2.5</option>
                <option value="27">BC 2.7</option>
                <option value="30">BC 3.0</option>
                <option value="41">BC 4.1</option>
                <option value="PPKEK">PPKEK</option>
                <option value="ALL">Semua BC</option>
            </select>
        </div>
        <div class="filter-col">
            <label>Tgl Dari</label>
            <input type="date" id="filterDateFrom" class="filter-control" value="<?= date('Y-m-d', strtotime('-5 days')) ?>" onchange="loadInvoices()">
        </div>
        <div class="filter-col">
            <label>Tgl Sampai (em_dt1)</label>
            <div style="display:flex; gap:2px; align-items:center;">
                <button type="button" class="ant-btn ant-btn-sm" onclick="adjustDate(-1)" title="Mundur 1 Hari (cb_7)">◀</button>
                <input type="date" id="filterDateTo" class="filter-control" value="<?= date('Y-m-d') ?>" onchange="loadInvoices()">
                <button type="button" class="ant-btn ant-btn-sm" onclick="adjustDate(1)" title="Maju 1 Hari (cb_8)">▶</button>
                <button type="button" class="ant-btn ant-btn-sm" onclick="setQuickRange(5)" title="Pilih Rentang 5 Hari Terakhir" style="font-size:9.5px; padding:0 6px;">5 Hari</button>
                <button type="button" class="ant-btn ant-btn-sm" onclick="setQuickRange(1)" title="Pilih Hari Ini Saja" style="font-size:9.5px; padding:0 6px;">Hari Ini</button>
            </div>
        </div>
        <div class="filter-col" style="flex:1; min-width:180px;">
            <label>Cari Invoice / DO / Customer (sle_1)</label>
            <input type="text" id="filterSearch" class="filter-control" placeholder="Ketik nomor invoice, surat jalan, atau customer..." onkeyup="if(event.key==='Enter') loadInvoices()">
        </div>
        <div class="filter-col">
            <label>&nbsp;</label>
            <button type="button" class="ant-btn ant-btn-primary" onclick="loadInvoices()" title="Tampilkan Data (cb_1)">
                <i class="fa-solid fa-magnifying-glass"></i> Tampilkan Data
            </button>
        </div>
    </div>

    <!-- KPI Metrics -->
    <div class="kpi-container">
        <div class="kpi-chip">
            <span class="kpi-title">Total Invoice:</span>
            <span class="kpi-val" id="kpiTotalInv" style="color:var(--primary);">0</span>
        </div>
        <div class="kpi-chip">
            <span class="kpi-title">Total Nilai FOB:</span>
            <span class="kpi-val" id="kpiTotalFob" style="color:#52c41a;">0</span>
        </div>
        <div class="kpi-chip">
            <span class="kpi-title">Total Netto:</span>
            <span class="kpi-val" id="kpiTotalNetto">0 kg</span>
        </div>
        <div class="kpi-chip">
            <span class="kpi-title">Total Baris BOM:</span>
            <span class="kpi-val" id="kpiTotalBom" style="color:#faad14;">0</span>
        </div>
        <div class="kpi-chip" style="margin-left:auto;">
            <span class="kpi-title">Server Odoo:</span>
            <span class="kpi-val" style="font-size:10px; color:var(--text-muted);">https://rmb.uduu.io (db: rmb)</span>
        </div>
    </div>

    <!-- Master Grid: Invoices Odoo (dw_1) -->
    <div class="table-scroll">
        <table class="ant-table" id="tableInvoices">
            <thead>
                <tr>
                    <th style="width:30px; text-align:center;">#</th>
                    <th style="width:50px; text-align:center;">BC</th>
                    <th style="width:130px;">No Invoice / Contract</th>
                    <th style="width:75px;">Tgl Invoice</th>
                    <th style="width:120px;">No Surat Jalan</th>
                    <th>Customer / Pembeli</th>
                    <th style="width:120px;">NPWP</th>
                    <th style="width:50px; text-align:center;">Valuta</th>
                    <th style="width:110px; text-align:right;">Total FOB</th>
                    <th style="width:85px; text-align:right;">Netto (kg)</th>
                    <th style="width:85px; text-align:right;">Bruto (kg)</th>
                    <th style="width:80px; text-align:center;">Status CEISA</th>
                    <th style="width:150px;">Nomor AJU Terhubung</th>
                </tr>
            </thead>
            <tbody id="tbodyInvoices">
                <tr>
                    <td colspan="13" style="text-align:center; padding:20px; color:var(--text-muted);">
                        Memuat data invoice Odoo...
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Detail Panels & Tabs (dw_2, dw_bb, dw_sum) -->
    <div>
        <div class="detail-tabs-nav">
            <button type="button" class="detail-tab-btn active" id="tabBtnGoods" onclick="switchDetailTab('goods')">
                <i class="fa-solid fa-box-open"></i> Detail Barang Jadi (dw_2) <span id="badgeGoodsCount" class="ant-badge ant-badge-blue" style="margin-left:2px;">0</span>
            </button>
            <button type="button" class="detail-tab-btn" id="tabBtnBom" onclick="switchDetailTab('bom')">
                <i class="fa-solid fa-layer-group"></i> Rincian Bahan Baku / BOM (dw_bb) <span id="badgeBomCount" class="ant-badge ant-badge-gold" style="margin-left:2px;">0</span>
            </button>
            <button type="button" class="detail-tab-btn" id="tabBtnBomSum" onclick="switchDetailTab('bom_sum')">
                <i class="fa-solid fa-calculator"></i> Summary Bahan Baku (dw_sum)
            </button>
        </div>

        <!-- Detail Tab 1: Barang Jadi (dw_2) -->
        <div id="panelDetailGoods" style="max-height:220px; overflow:auto;">
            <table class="ant-table">
                <thead>
                    <tr>
                        <th style="width:30px; text-align:center;">Seri</th>
                        <th style="width:110px;">Kode Barang</th>
                        <th>Uraian Barang</th>
                        <th style="width:75px; text-align:right;">Qty</th>
                        <th style="width:55px;">Satuan</th>
                        <th style="width:55px; text-align:center;">Kemasan</th>
                        <th style="width:95px; text-align:right;">Harga Satuan</th>
                        <th style="width:110px; text-align:right;">Subtotal FOB</th>
                        <th style="width:80px;">HS Code</th>
                        <th style="width:75px; text-align:right;">Netto (kg)</th>
                        <th style="width:65px; text-align:right;">BM (%)</th>
                        <th style="width:65px; text-align:right;">PPN (%)</th>
                    </tr>
                </thead>
                <tbody id="tbodyDetailGoods">
                    <tr><td colspan="12" style="text-align:center; padding:15px; color:var(--text-muted);">Pilih salah satu invoice di tabel atas untuk melihat detail barang.</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Detail Tab 2: Rincian Bahan Baku BOM (dw_bb) -->
        <div id="panelDetailBom" style="display:none; max-height:220px; overflow:auto;">
            <table class="ant-table">
                <thead>
                    <tr>
                        <th style="width:30px; text-align:center;">#</th>
                        <th style="width:110px;">Barang Jadi</th>
                        <th style="width:110px;">Kode Bahan Baku</th>
                        <th>Nama Bahan Baku</th>
                        <th style="width:80px;">HS Bahan</th>
                        <th style="width:75px; text-align:right;">Koef/Unit</th>
                        <th style="width:85px; text-align:right;">Total Kebutuhan</th>
                        <th style="width:55px;">Satuan</th>
                        <th style="width:65px; text-align:center;">Dok Asal</th>
                        <th style="width:90px;">No Pendaftaran Asal</th>
                        <th style="width:75px;">Tgl Daftar</th>
                    </tr>
                </thead>
                <tbody id="tbodyDetailBom">
                    <tr><td colspan="11" style="text-align:center; padding:15px; color:var(--text-muted);">Tidak ada rincian bahan baku atau belum dipilih.</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Detail Tab 3: Summary Bahan Baku (dw_sum) -->
        <div id="panelDetailBomSum" style="display:none; max-height:220px; overflow:auto;">
            <table class="ant-table">
                <thead>
                    <tr>
                        <th style="width:30px; text-align:center;">#</th>
                        <th style="width:130px;">Kode Bahan Baku</th>
                        <th>Nama Bahan Baku</th>
                        <th style="width:90px;">HS Code</th>
                        <th style="width:100px; text-align:right;">Total Kebutuhan</th>
                        <th style="width:65px;">Satuan</th>
                    </tr>
                </thead>
                <tbody id="tbodyDetailBomSum">
                    <tr><td colspan="6" style="text-align:center; padding:15px; color:var(--text-muted);">Pilih salah satu invoice untuk melihat rekap kebutuhan bahan baku.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    let activeInvoices = [];
    let selectedInvoice = null;

    document.addEventListener('DOMContentLoaded', function() {
        loadInvoices();
    });

    function adjustDate(days) {
        const inputTo = document.getElementById('filterDateTo');
        const inputFrom = document.getElementById('filterDateFrom');
        if (!inputTo.value) return;
        const curTo = new Date(inputTo.value);
        curTo.setDate(curTo.getDate() + days);
        inputTo.value = curTo.toISOString().split('T')[0];

        if (inputFrom && inputFrom.value) {
            const curFrom = new Date(inputFrom.value);
            curFrom.setDate(curFrom.getDate() + days);
            inputFrom.value = curFrom.toISOString().split('T')[0];
        }
        loadInvoices();
    }

    function setQuickRange(days) {
        const inputTo = document.getElementById('filterDateTo');
        const inputFrom = document.getElementById('filterDateFrom');
        const now = new Date();
        inputTo.value = now.toISOString().split('T')[0];
        if (days === 1) {
            inputFrom.value = now.toISOString().split('T')[0];
        } else {
            const past = new Date();
            past.setDate(past.getDate() - (days - 1));
            inputFrom.value = past.toISOString().split('T')[0];
        }
        loadInvoices();
    }

    async function loadInvoices() {
        const dt1 = document.getElementById('filterDateFrom').value;
        const dt2 = document.getElementById('filterDateTo').value;
        const search = document.getElementById('filterSearch').value;
        const bc = document.getElementById('filterBcType').value;
        const cmpId = new URLSearchParams(window.location.search).get('cmp_id') || '<?= htmlspecialchars($cmpId) ?>';

        const tbody = document.getElementById('tbodyInvoices');
        tbody.innerHTML = '<tr><td colspan="13" style="text-align:center; padding:20px;"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data invoice Odoo...</td></tr>';

        try {
            const url = `load_erp_odoo.php?action=load_invoices&cmp_id=${encodeURIComponent(cmpId)}&date_from=${encodeURIComponent(dt1)}&date_to=${encodeURIComponent(dt2)}&search=${encodeURIComponent(search)}&bc_type=${encodeURIComponent(bc)}`;
            const res = await fetch(url);
            const text = await res.text();
            let json;
            try {
                json = JSON.parse(text);
            } catch(pe) {
                tbody.innerHTML = `<tr><td colspan="13" style="text-align:center; color:#ff4d4f; padding:15px;">Respon server tidak valid (${res.status}). Silakan coba lagi.</td></tr>`;
                return;
            }

            if (json.status === 'OK') {
                activeInvoices = json.rows || [];
                renderInvoicesTable(activeInvoices);
                updateKpi(json.kpi || {});
            } else {
                tbody.innerHTML = `<tr><td colspan="13" style="text-align:center; color:#ff4d4f; padding:15px;">Gagal: ${json.message || 'Error'}</td></tr>`;
            }
        } catch(e) {
            tbody.innerHTML = `<tr><td colspan="13" style="text-align:center; color:#ff4d4f; padding:15px;">Error: ${e.message}</td></tr>`;
        }
    }

    function updateKpi(kpi) {
        document.getElementById('kpiTotalInv').innerText = (kpi.total_inv || 0).toLocaleString('id-ID');
        document.getElementById('kpiTotalFob').innerText = (kpi.total_fob || 0).toLocaleString('id-ID', { minimumFractionDigits: 2 });
        document.getElementById('kpiTotalNetto').innerText = (kpi.total_netto || 0).toLocaleString('id-ID', { minimumFractionDigits: 1 }) + ' kg';
        document.getElementById('kpiTotalBom').innerText = (kpi.total_bom || 0).toLocaleString('id-ID');
    }

    function renderInvoicesTable(rows) {
        const tbody = document.getElementById('tbodyInvoices');
        if (!rows || rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="13" style="text-align:center; padding:25px; color:var(--text-muted);">Tidak ada transaksi invoice Odoo pada periode ini.<br><button class="ant-btn ant-btn-sm btn-pb-sync" onclick="triggerSyncOdoo()" style="margin-top:6px;">🔄 Sync Odoo Sekarang</button></td></tr>';
            clearDetails();
            return;
        }

        let html = '';
        rows.forEach((r, idx) => {
            const statusBadge = (r.status_ceisa === 'TRANSFERRED') 
                ? '<span class="ant-badge ant-badge-green">TRANSFERRED</span>' 
                : '<span class="ant-badge ant-badge-blue">READY</span>';
            const ajuDisplay = r.nomor_aju_ceisa ? `<code style="color:var(--primary); font-weight:600;">${r.nomor_aju_ceisa}</code>` : '-';

            html += `
                <tr id="row-inv-${r.invoice_id}" onclick="selectInvoiceRow(${r.invoice_id})">
                    <td style="text-align:center;">${idx + 1}</td>
                    <td style="text-align:center;"><span class="ant-badge ant-badge-purple">BC ${r.kode_dokumen_bc || '25'}</span></td>
                    <td style="font-weight:600; color:var(--primary); font-family:monospace;">${escapeHtml(r.nomor_invoice)}</td>
                    <td>${escapeHtml(r.tanggal_invoice)}</td>
                    <td style="font-family:monospace;">${escapeHtml(r.nomor_surat_jalan || '-')}</td>
                    <td style="font-weight:500;">${escapeHtml(r.customer_nama || '-')}</td>
                    <td style="font-family:monospace;">${escapeHtml(r.customer_npwp || '-')}</td>
                    <td style="text-align:center;">${escapeHtml(r.mata_uang || 'IDR')}</td>
                    <td style="text-align:right; font-weight:700; color:#52c41a;">${parseFloat(r.total_fob).toLocaleString('id-ID', { minimumFractionDigits: 2 })}</td>
                    <td style="text-align:right;">${parseFloat(r.berat_netto_total).toLocaleString('id-ID', { minimumFractionDigits: 1 })}</td>
                    <td style="text-align:right;">${parseFloat(r.berat_bruto_total).toLocaleString('id-ID', { minimumFractionDigits: 1 })}</td>
                    <td style="text-align:center;">${statusBadge}</td>
                    <td>${ajuDisplay}</td>
                </tr>
            `;
        });

        tbody.innerHTML = html;

        // Auto pilih row pertama
        if (rows.length > 0) {
            selectInvoiceRow(rows[0].invoice_id);
        }
    }

    function selectInvoiceRow(invId) {
        selectedInvoice = activeInvoices.find(i => parseInt(i.invoice_id) === parseInt(invId));
        document.querySelectorAll('#tbodyInvoices tr').forEach(tr => tr.classList.remove('is-selected'));
        const selTr = document.getElementById(`row-inv-${invId}`);
        if (selTr) selTr.classList.add('is-selected');

        const badge = document.getElementById('selectedInvoiceBadge');
        if (selectedInvoice && badge) {
            badge.style.display = 'inline-block';
            badge.innerText = `Terpilih: ${selectedInvoice.nomor_invoice}`;
        }

        loadInvoiceDetails(invId);
    }

    async function loadInvoiceDetails(invId) {
        const tbodyGoods = document.getElementById('tbodyDetailGoods');
        const tbodyBom = document.getElementById('tbodyDetailBom');
        const tbodyBomSum = document.getElementById('tbodyDetailBomSum');

        tbodyGoods.innerHTML = '<tr><td colspan="12" style="text-align:center; padding:15px;"><i class="fa-solid fa-spinner fa-spin"></i> Memuat detail barang...</td></tr>';

        try {
            const res = await fetch(`load_erp_odoo.php?action=load_invoice_details&invoice_id=${invId}`);
            const text = await res.text();
            let json;
            try {
                json = JSON.parse(text);
            } catch(pe) {
                tbodyGoods.innerHTML = `<tr><td colspan="12" style="text-align:center; color:#ff4d4f; padding:15px;">Respon server tidak valid (${res.status}).</td></tr>`;
                return;
            }

            if (json.status === 'OK') {
                renderDetailsTable(json.details || []);
                renderBomTable(json.boms || []);
                renderBomSumTable(json.bom_summary || []);
                document.getElementById('badgeGoodsCount').innerText = (json.details || []).length;
                document.getElementById('badgeBomCount').innerText = (json.boms || []).length;
            }
        } catch(e) {
            tbodyGoods.innerHTML = `<tr><td colspan="12" style="text-align:center; color:#ff4d4f;">Error: ${e.message}</td></tr>`;
        }
    }

    function renderDetailsTable(details) {
        const tbody = document.getElementById('tbodyDetailGoods');
        if (!details || details.length === 0) {
            tbody.innerHTML = '<tr><td colspan="12" style="text-align:center; padding:15px; color:var(--text-muted);">Tidak ada detail barang untuk invoice ini.</td></tr>';
            return;
        }

        let html = '';
        details.forEach(d => {
            html += `
                <tr>
                    <td style="text-align:center;">${d.seri_barang}</td>
                    <td style="font-family:monospace; font-weight:600;">${escapeHtml(d.kode_barang)}</td>
                    <td>${escapeHtml(d.nama_barang)}</td>
                    <td style="text-align:right; font-weight:600;">${parseFloat(d.jumlah).toLocaleString('id-ID', { minimumFractionDigits: 2 })}</td>
                    <td>${escapeHtml(d.satuan)}</td>
                    <td style="text-align:center;">${d.jumlah_kemasan || 1} PK</td>
                    <td style="text-align:right;">${parseFloat(d.harga_satuan).toLocaleString('id-ID', { minimumFractionDigits: 2 })}</td>
                    <td style="text-align:right; font-weight:700; color:#52c41a;">${parseFloat(d.subtotal_fob).toLocaleString('id-ID', { minimumFractionDigits: 2 })}</td>
                    <td style="font-family:monospace;">${escapeHtml(d.hs_code || '-')}</td>
                    <td style="text-align:right;">${parseFloat(d.berat_netto).toFixed(1)}</td>
                    <td style="text-align:right;">${parseFloat(d.tarif_bm_persen || 0).toFixed(0)}%</td>
                    <td style="text-align:right;">${parseFloat(d.tarif_ppn_persen || 11).toFixed(0)}%</td>
                </tr>
            `;
        });
        tbody.innerHTML = html;
    }

    function renderBomTable(boms) {
        const tbody = document.getElementById('tbodyDetailBom');
        if (!boms || boms.length === 0) {
            tbody.innerHTML = '<tr><td colspan="11" style="text-align:center; padding:15px; color:var(--text-muted);">Tidak ada rincian bahan baku (BOM) untuk invoice ini.</td></tr>';
            return;
        }

        let html = '';
        boms.forEach((b, idx) => {
            html += `
                <tr>
                    <td style="text-align:center;">${idx + 1}</td>
                    <td style="font-family:monospace; font-weight:600;">${escapeHtml(b.kode_barang_jadi)}</td>
                    <td style="font-family:monospace; color:var(--primary);">${escapeHtml(b.kode_bahan_baku)}</td>
                    <td>${escapeHtml(b.nama_bahan_baku)}</td>
                    <td style="font-family:monospace;">${escapeHtml(b.hs_code_bahan_baku || '-')}</td>
                    <td style="text-align:right;">${parseFloat(b.jumlah_per_unit).toFixed(4)}</td>
                    <td style="text-align:right; font-weight:700; color:#faad14;">${parseFloat(b.total_kebutuhan).toLocaleString('id-ID', { minimumFractionDigits: 2 })}</td>
                    <td>${escapeHtml(b.satuan_bahan_baku)}</td>
                    <td style="text-align:center;"><span class="ant-badge ant-badge-blue">BC ${escapeHtml(b.eks_jenis_bc || '27')}</span></td>
                    <td style="font-family:monospace;">${escapeHtml(b.eks_nomor_nopen || '-')}</td>
                    <td>${escapeHtml(b.eks_tanggal_nopen || '-')}</td>
                </tr>
            `;
        });
        tbody.innerHTML = html;
    }

    function renderBomSumTable(sums) {
        const tbody = document.getElementById('tbodyDetailBomSum');
        if (!sums || sums.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:15px; color:var(--text-muted);">Tidak ada rekap bahan baku.</td></tr>';
            return;
        }

        let html = '';
        sums.forEach((s, idx) => {
            html += `
                <tr>
                    <td style="text-align:center;">${idx + 1}</td>
                    <td style="font-family:monospace; font-weight:600;">${escapeHtml(s.kode_bahan_baku)}</td>
                    <td>${escapeHtml(s.nama_bahan_baku)}</td>
                    <td style="font-family:monospace;">${escapeHtml(s.hs_code_bahan_baku || '-')}</td>
                    <td style="text-align:right; font-weight:700; color:#722ed1;">${parseFloat(s.total_qty).toLocaleString('id-ID', { minimumFractionDigits: 2 })}</td>
                    <td>${escapeHtml(s.satuan_bahan_baku)}</td>
                </tr>
            `;
        });
        tbody.innerHTML = html;
    }

    function clearDetails() {
        document.getElementById('tbodyDetailGoods').innerHTML = '<tr><td colspan="12" style="text-align:center; padding:15px; color:var(--text-muted);">Pilih salah satu invoice di tabel atas.</td></tr>';
        document.getElementById('tbodyDetailBom').innerHTML = '<tr><td colspan="11" style="text-align:center; padding:15px; color:var(--text-muted);">Tidak ada rincian bahan baku.</td></tr>';
        document.getElementById('tbodyDetailBomSum').innerHTML = '<tr><td colspan="6" style="text-align:center; padding:15px; color:var(--text-muted);">Tidak ada rekap bahan baku.</td></tr>';
        document.getElementById('badgeGoodsCount').innerText = '0';
        document.getElementById('badgeBomCount').innerText = '0';
    }

    function switchDetailTab(tabName) {
        document.querySelectorAll('.detail-tab-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('panelDetailGoods').style.display = 'none';
        document.getElementById('panelDetailBom').style.display = 'none';
        document.getElementById('panelDetailBomSum').style.display = 'none';

        if (tabName === 'goods') {
            document.getElementById('tabBtnGoods').classList.add('active');
            document.getElementById('panelDetailGoods').style.display = 'block';
        } else if (tabName === 'bom') {
            document.getElementById('tabBtnBom').classList.add('active');
            document.getElementById('panelDetailBom').style.display = 'block';
        } else if (tabName === 'bom_sum') {
            document.getElementById('tabBtnBomSum').classList.add('active');
            document.getElementById('panelDetailBomSum').style.display = 'block';
        }
    }

    function toggleBomView() {
        const isBom = document.getElementById('panelDetailBom').style.display === 'block';
        if (isBom) {
            switchDetailTab('goods');
        } else {
            switchDetailTab('bom');
        }
    }

    async function triggerSyncOdoo() {
        const dt1 = document.getElementById('filterDateFrom').value;
        const dt2 = document.getElementById('filterDateTo').value;
        const bc = document.getElementById('filterBcType').value;

        if (!dt1 || !dt2) {
            Swal.fire('Peringatan', 'Silakan pilih rentang tanggal terlebih dahulu.', 'warning');
            return;
        }

        let start = dt1 <= dt2 ? dt1 : dt2;
        let end   = dt1 <= dt2 ? dt2 : dt1;

        const dateList = [];
        let curr = new Date(start + 'T00:00:00');
        const last = new Date(end + 'T00:00:00');
        while (curr <= last) {
            dateList.push(curr.toISOString().split('T')[0]);
            curr.setDate(curr.getDate() + 1);
        }

        const totalDays = dateList.length;

        Swal.fire({
            title: 'Sinkronisasi Odoo ERP',
            html: `
                <div style="text-align:left; font-size:11.5px; padding:4px;">
                    <div id="syncProgressText" style="font-weight:600; margin-bottom:6px; color:var(--text-main);">Menyiapkan sinkronisasi (${totalDays} hari)...</div>
                    <div style="width:100%; height:14px; background:#e8e8e8; border-radius:7px; overflow:hidden; margin-bottom:8px;">
                        <div id="syncProgressBar" style="width:0%; height:100%; background:#1890ff; transition:width 0.25s ease;"></div>
                    </div>
                    <div id="syncProgressDetail" style="font-size:10.5px; color:#8c8c8c;">Menghubungkan ke https://rmb.uduu.io via JSON-RPC...</div>
                </div>
            `,
            allowOutsideClick: false,
            showConfirmButton: false,
            showCancelButton: true,
            cancelButtonText: 'Hentikan',
            didOpen: () => {}
        });

        let totalInvoices = 0;
        let totalDetails = 0;
        let totalBoms = 0;
        let syncCancelled = false;

        const cancelBtn = Swal.getCancelButton();
        if (cancelBtn) {
            cancelBtn.onclick = () => { syncCancelled = true; };
        }

        for (let i = 0; i < totalDays; i++) {
            if (syncCancelled) break;

            const targetDay = dateList[i];
            const pct = Math.round((i / totalDays) * 100);

            const pBar = document.getElementById('syncProgressBar');
            const pText = document.getElementById('syncProgressText');
            const pDetail = document.getElementById('syncProgressDetail');

            if (pBar) pBar.style.width = `${pct}%`;
            if (pText) pText.innerText = `Memproses Hari ke-${i + 1} dari ${totalDays} (${targetDay})... [${pct}%]`;
            if (pDetail) pDetail.innerText = `Total tersimpan: ${totalInvoices.toLocaleString('id-ID')} Invoice, ${totalBoms.toLocaleString('id-ID')} Baris BOM`;

            try {
                const fd = new FormData();
                fd.append('date_from', targetDay);
                fd.append('date_to', targetDay);
                fd.append('kode_dokumen', bc);

                const res = await fetch('load_erp_odoo.php?action=sync_odoo', {
                    method: 'POST',
                    body: fd
                });
                const text = await res.text();
                let json;
                try {
                    json = JSON.parse(text);
                } catch(pe) {
                    throw new Error(`HTTP ${res.status} pada tanggal ${targetDay}`);
                }

                if (json.status === 'OK') {
                    const d = json.data || {};
                    totalInvoices += (d.synced_invoices || 0);
                    totalDetails  += (d.synced_details || 0);
                    totalBoms     += (d.synced_bom_items || 0);
                }
            } catch(e) {
                console.warn(`Error pada tanggal ${targetDay}:`, e);
            }
        }

        const pBar = document.getElementById('syncProgressBar');
        if (pBar) pBar.style.width = '100%';

        Swal.fire({
            icon: 'success',
            title: syncCancelled ? 'Sinkronisasi Dihentikan' : 'Sync Odoo Berhasil! 🎉',
            html: `
                <div style="text-align:left; font-size:11.5px; padding:10px; background:rgba(82, 196, 26, 0.08); border-radius:6px;">
                    <div>📄 <strong>${totalInvoices.toLocaleString('id-ID')}</strong> Invoice Berhasil Diupdate</div>
                    <div>📦 <strong>${totalDetails.toLocaleString('id-ID')}</strong> Baris Barang Jadi</div>
                    <div>🧩 <strong>${totalBoms.toLocaleString('id-ID')}</strong> Baris Bahan Baku (BOM)</div>
                    <div style="margin-top:6px; color:#8c8c8c; font-size:10px;">Periode: ${start} s/d ${end} (${totalDays} hari)</div>
                </div>
            `,
            confirmButtonText: 'Tampilkan Data'
        });

        loadInvoices();
    }

    async function executeTransferData() {
        if (!selectedInvoice) {
            Swal.fire({
                icon: 'warning',
                title: 'Pilih Dokumen',
                text: 'Silakan pilih salah satu invoice terlebih dahulu pada tabel.'
            });
            return;
        }

        const invNo = selectedInvoice.nomor_invoice;
        const curAju = selectedInvoice.nomor_aju_ceisa || '';
        const bc = document.getElementById('filterBcType').value || selectedInvoice.kode_dokumen_bc || '25';

        const { value: formValues } = await Swal.fire({
            title: 'TRANSFER DATA KE CEISA 4.0',
            html: `
                <div style="text-align:left; font-size:11px;">
                    <div style="margin-bottom:8px; background:rgba(24, 144, 255, 0.08); padding:8px; border-radius:4px;">
                        <div>Invoice: <strong>${escapeHtml(invNo)}</strong></div>
                        <div>Customer: <strong>${escapeHtml(selectedInvoice.customer_nama)}</strong></div>
                        <div>Total FOB: <strong>${escapeHtml(selectedInvoice.mata_uang)} ${parseFloat(selectedInvoice.total_fob).toLocaleString('id-ID')}</strong></div>
                    </div>
                    <label style="font-weight:600; display:block; margin-bottom:3px;">Dokumen Pabean (BC):</label>
                    <select id="swalBcType" class="filter-control" style="width:100%; height:26px; margin-bottom:8px;">
                        <option value="25" ${bc === '25' ? 'selected' : ''}>BC 2.5 - Pengeluaran TPB ke TLDDP</option>
                        <option value="27" ${bc === '27' ? 'selected' : ''}>BC 2.7 - Transfer Antar TPB</option>
                        <option value="30" ${bc === '30' ? 'selected' : ''}>BC 3.0 - Ekspor Barang Jadi</option>
                        <option value="41" ${bc === '41' ? 'selected' : ''}>BC 4.1 - Subkontrak</option>
                        <option value="PPKEK" ${bc === 'PPKEK' ? 'selected' : ''}>PPKEK - Pengeluaran KEK</option>
                    </select>
                    <label style="font-weight:600; display:block; margin-bottom:3px;">Nomor Pengajuan AJU 26 Digit (Kosongkan untuk auto-generate):</label>
                    <input type="text" id="swalNomorAju" class="filter-control" style="width:100%; height:26px;" placeholder="Auto-generate nomor AJU 26 digit standar..." value="${curAju}">
                </div>
            `,
            focusConfirm: false,
            showCancelButton: true,
            confirmButtonText: '⚡ Eksekusi Transfer',
            cancelButtonText: 'Batal',
            preConfirm: () => {
                return {
                    kode_dokumen: document.getElementById('swalBcType').value,
                    nomor_aju: document.getElementById('swalNomorAju').value.trim()
                };
            }
        });

        if (!formValues) return;

        Swal.fire({
            title: 'Mentransfer Data ke Staging...',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        try {
            const fd = new FormData();
            fd.append('invoice_id', selectedInvoice.invoice_id);
            fd.append('kode_dokumen', formValues.kode_dokumen);
            fd.append('nomor_aju', formValues.nomor_aju);

            const res = await fetch('load_erp_odoo.php?action=transfer_data', {
                method: 'POST',
                body: fd
            });
            const text = await res.text();
            let json;
            try {
                json = JSON.parse(text);
            } catch(pe) {
                Swal.fire({
                    icon: 'error',
                    title: 'Transfer Timeout / Error',
                    text: `Terjadi kendala respon server (HTTP ${res.status}).`
                });
                return;
            }

            if (json.status === 'OK') {
                const targetAju = json.nomor_aju;
                Swal.fire({
                    icon: 'success',
                    title: 'Transfer Berhasil! 🚀',
                    html: `
                        <div style="text-align:left; font-size:11.5px; padding:10px; background:rgba(82, 196, 26, 0.08); border-radius:6px;">
                            <div>${escapeHtml(json.message)}</div>
                            <div style="margin-top:8px;">Nomor AJU: <code style="font-size:12px; font-weight:700; color:var(--primary);">${targetAju}</code></div>
                            <div style="margin-top:6px; font-size:10.5px; color:var(--text-muted);">
                                • ${json.row_counts?.barang || 0} Barang Jadi<br>
                                • ${json.row_counts?.dokumen || 0} Dokumen Pelengkap<br>
                                • ${json.row_counts?.bahanbaku || 0} Baris Bahan Baku (BOM)
                            </div>
                        </div>
                    `,
                    showCancelButton: true,
                    confirmButtonText: '✏️ Buka di Form Editor Dokumen',
                    cancelButtonText: 'Tetap di Halaman Ini'
                }).then(result => {
                    if (result.isConfirmed) {
                        window.parent.postMessage({ action: 'open_editor', nomor_aju: targetAju }, '*');
                        if (window.top && window.top !== window) {
                            window.top.location.href = `index.php?tab=editor&nomor_aju=${encodeURIComponent(targetAju)}`;
                        } else {
                            window.location.href = `editor.php?nomor_aju=${encodeURIComponent(targetAju)}`;
                        }
                    } else {
                        loadInvoices();
                    }
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal Transfer Data',
                    text: json.message || 'Terjadi kesalahan sistem'
                });
            }
        } catch(e) {
            Swal.fire({
                icon: 'error',
                title: 'Error Jaringan',
                text: e.message
            });
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
</script>
</body>
</html>

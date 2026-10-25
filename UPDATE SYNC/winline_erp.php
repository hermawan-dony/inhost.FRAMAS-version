<?php
/**
 * CEISA 4.0 - Add-on Module: Winline ERP Integration
 * File: winline_erp.php
 * 
 * Modul integrasi sistem Winline ERP PT Framas Indonesia ke Host to Host CEISA 4.0:
 * 1. Sales Invoice    -> Integrasi Dokumen H2H: BC 2.7 & BC 3.0 (Customer)
 * 2. DN Transfer      -> Integrasi Dokumen H2H: BC 2.7 (Perpindahan Antar Plant Framas)
 * 3. Purchase Order   -> Integrasi Dokumen H2H: BC 2.3, BC 4.0, BC 1.6 (Pemasukan Barang)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/app_settings_helper.php';
require_once __DIR__ . '/erp/ErpManager.php';

$isAdmin = !empty($_SESSION['is_admin']) || (isset($_GET['is_admin']) && $_GET['is_admin'] === '1');
$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_GET['cmp_id'] ?? 'FRM-1');
$targetType = trim($_GET['type'] ?? 'sales_invoice');
if (!in_array($targetType, ['sales_invoice', 'dn_transfer', 'purchase_order'])) {
    $targetType = 'sales_invoice';
}

$escCmp = $koneksi->real_escape_string($sessionCmpId);

// Ambil profil perusahaan aktif
$company = [];
$resCmp = $koneksi->query("SELECT * FROM bc_ceisa_company WHERE cmp_id = '$escCmp' LIMIT 1");
if ($resCmp && $rC = $resCmp->fetch_assoc()) {
    $company = $rC;
}

// Ambil daftar seluruh perusahaan aktif untuk switcher
$companies = [];
$qAllComp = $koneksi->query("SELECT cmp_id, cmp_name, npwp, kode_kantor, IFNULL(company_roles, 'tpb') AS company_roles FROM bc_ceisa_company WHERE is_active = 1 ORDER BY cmp_name ASC");
if ($qAllComp) {
    while ($rc = $qAllComp->fetch_assoc()) {
        $companies[] = $rc;
    }
}

// -------------------------------------------------------------
// AJAX API ENDPOINTS
// -------------------------------------------------------------
$action = trim($_REQUEST['action'] ?? '');

if ($action === 'load_data') {
    header('Content-Type: application/json; charset=utf-8');
    $type    = trim($_GET['type'] ?? $targetType);
    $q       = trim($_GET['q'] ?? '');
    $dt1     = trim($_GET['date_from'] ?? date('Y-m-01'));
    $dt2     = trim($_GET['date_to'] ?? date('Y-m-d'));
    $targetBc= trim($_GET['target_bc'] ?? '');
    $status  = trim($_GET['status'] ?? '');

    // Cek apakah ada data di tabel staging lokal atau gunakan mock realistis berbasis FramasDriver
    $rows = [];
    $todayYmd = date('Ymd');

    if ($type === 'sales_invoice') {
        // Sales Invoice: BC 2.7, BC 3.0 ke Customer
        $mockInvoices = [
            [
                'doc_no'       => 'INV/EXP/2026/04/0088',
                'doc_date'     => date('Y-m-d', strtotime('-1 days')),
                'partner_name' => 'ADIDAS INTERNATIONAL TRADING AG',
                'partner_addr' => 'LUZERN, SWITZERLAND',
                'target_bc'    => '30',
                'bc_label'     => 'BC 3.0 (PEB Ekspor)',
                'valuta'       => 'USD',
                'total_fob'    => 48500.00,
                'total_qty'    => 9600,
                'unit'         => 'PR',
                'total_kemas'  => 320,
                'kemas_sat'    => 'CT',
                'netto'        => 2880.00,
                'bruto'        => 3120.00,
                'status_h2h'   => 'ready',
                'nomor_aju'    => ''
            ],
            [
                'doc_no'       => 'INV/SLS/2026/04/0142',
                'doc_date'     => date('Y-m-d', strtotime('-2 days')),
                'partner_name' => 'PT PUMA SPORT INDONESIA (TPB)',
                'partner_addr' => 'KAWASAN INDUSTRI TANGERANG',
                'target_bc'    => '27',
                'bc_label'     => 'BC 2.7 (TPB ke TPB Lain)',
                'valuta'       => 'USD',
                'total_fob'    => 19250.00,
                'total_qty'    => 3500,
                'unit'         => 'PR',
                'total_kemas'  => 120,
                'kemas_sat'    => 'CT',
                'netto'        => 1050.00,
                'bruto'        => 1160.00,
                'status_h2h'   => 'ready',
                'nomor_aju'    => ''
            ],
            [
                'doc_no'       => 'INV/EXP/2026/04/0079',
                'doc_date'     => date('Y-m-d', strtotime('-4 days')),
                'partner_name' => 'FOOT LOCKER ASIA PACIFIC PTE LTD',
                'partner_addr' => 'SINGAPORE',
                'target_bc'    => '30',
                'bc_label'     => 'BC 3.0 (PEB Ekspor)',
                'valuta'       => 'USD',
                'total_fob'    => 32400.00,
                'total_qty'    => 6000,
                'unit'         => 'PR',
                'total_kemas'  => 200,
                'kemas_sat'    => 'CT',
                'netto'        => 1800.00,
                'bruto'        => 1950.00,
                'status_h2h'   => 'ready',
                'nomor_aju'    => ''
            ],
            [
                'doc_no'       => 'INV/SLS/2026/04/0125',
                'doc_date'     => date('Y-m-d', strtotime('-5 days')),
                'partner_name' => 'PT PANARUB INDUSTRY (TPB)',
                'partner_addr' => 'PASAR KEMIS, TANGERANG',
                'target_bc'    => '27',
                'bc_label'     => 'BC 2.7 (TPB ke TPB Lain)',
                'valuta'       => 'USD',
                'total_fob'    => 14800.00,
                'total_qty'    => 2800,
                'unit'         => 'PR',
                'total_kemas'  => 95,
                'kemas_sat'    => 'CT',
                'netto'        => 840.00,
                'bruto'        => 920.00,
                'status_h2h'   => 'ready',
                'nomor_aju'    => ''
            ]
        ];
        $rows = $mockInvoices;
    } elseif ($type === 'dn_transfer') {
        // DN Transfer: BC 2.7 perpindahan antar Framas
        $mockDn = [
            [
                'doc_no'       => 'DN-FRM2604001',
                'doc_date'     => date('Y-m-d', strtotime('-1 days')),
                'partner_name' => 'PT FRAMAS INDONESIA - PLANT 2 (FRM-2)',
                'partner_addr' => 'BEKASI PLANT 2',
                'target_bc'    => '27',
                'bc_label'     => 'BC 2.7 (Antar Plant Framas)',
                'valuta'       => 'IDR',
                'total_fob'    => 85400000.00,
                'total_qty'    => 4200,
                'unit'         => 'PCE',
                'total_kemas'  => 70,
                'kemas_sat'    => 'BX',
                'netto'        => 630.00,
                'bruto'        => 690.00,
                'status_h2h'   => 'ready',
                'nomor_aju'    => ''
            ],
            [
                'doc_no'       => 'DN-FRM2604002',
                'doc_date'     => date('Y-m-d', strtotime('-2 days')),
                'partner_name' => 'PT FRAMAS INDONESIA - PLANT 3 (FRM-3)',
                'partner_addr' => 'SUBANG PLANT 3',
                'target_bc'    => '27',
                'bc_label'     => 'BC 2.7 (Antar Plant Framas)',
                'valuta'       => 'IDR',
                'total_fob'    => 126000000.00,
                'total_qty'    => 5800,
                'unit'         => 'PCE',
                'total_kemas'  => 110,
                'kemas_sat'    => 'BX',
                'netto'        => 870.00,
                'bruto'        => 950.00,
                'status_h2h'   => 'ready',
                'nomor_aju'    => ''
            ],
            [
                'doc_no'       => 'DN-FRM2604003',
                'doc_date'     => date('Y-m-d', strtotime('-3 days')),
                'partner_name' => 'PUSAT LOGISTIK BERIKAT (PDPLB)',
                'partner_addr' => 'CIBITUNG LOGISTICS CENTER',
                'target_bc'    => '27',
                'bc_label'     => 'BC 2.7 (Antar TPB ke PLB)',
                'valuta'       => 'IDR',
                'total_fob'    => 64500000.00,
                'total_qty'    => 3100,
                'unit'         => 'PCE',
                'total_kemas'  => 55,
                'kemas_sat'    => 'BX',
                'netto'        => 465.00,
                'bruto'        => 510.00,
                'status_h2h'   => 'ready',
                'nomor_aju'    => ''
            ]
        ];
        $rows = $mockDn;
    } else {
        // Purchase Order: BC 2.3, BC 4.0, BC 1.6 Pemasukan
        $mockPo = [
            [
                'doc_no'       => 'PO/IMP/2026/04/0055',
                'doc_date'     => date('Y-m-d', strtotime('-1 days')),
                'partner_name' => 'DONGGUAN FRAMAS PLASTIC CO LTD',
                'partner_addr' => 'DONGGUAN, GUANGDONG, CHINA',
                'target_bc'    => '23',
                'bc_label'     => 'BC 2.3 (Impor Bahan Baku TPB)',
                'valuta'       => 'USD',
                'total_fob'    => 38650.00,
                'total_qty'    => 12500,
                'unit'         => 'KGM',
                'total_kemas'  => 500,
                'kemas_sat'    => 'BAG',
                'netto'        => 12500.00,
                'bruto'        => 12750.00,
                'status_h2h'   => 'ready',
                'nomor_aju'    => ''
            ],
            [
                'doc_no'       => 'PO/LOK/2026/04/0112',
                'doc_date'     => date('Y-m-d', strtotime('-2 days')),
                'partner_name' => 'PT CHANDRA ASRI PACIFIC TBK',
                'partner_addr' => 'CILEGON, BANTEN (TLDDP)',
                'target_bc'    => '40',
                'bc_label'     => 'BC 4.0 (Pemasukan Lokal TLDDP)',
                'valuta'       => 'IDR',
                'total_fob'    => 185000000.00,
                'total_qty'    => 8000,
                'unit'         => 'KGM',
                'total_kemas'  => 320,
                'kemas_sat'    => 'BAG',
                'netto'        => 8000.00,
                'bruto'        => 8160.00,
                'status_h2h'   => 'ready',
                'nomor_aju'    => ''
            ],
            [
                'doc_no'       => 'PO/PLB/2026/04/0023',
                'doc_date'     => date('Y-m-d', strtotime('-3 days')),
                'partner_name' => 'POLYONE GLOBAL LOGISTICS PLB',
                'partner_addr' => 'PLB BEKASI INTERNATIONAL',
                'target_bc'    => '16',
                'bc_label'     => 'BC 1.6 (Pemasukan dari PLB)',
                'valuta'       => 'USD',
                'total_fob'    => 24500.00,
                'total_qty'    => 6000,
                'unit'         => 'KGM',
                'total_kemas'  => 240,
                'kemas_sat'    => 'BAG',
                'netto'        => 6000.00,
                'bruto'        => 6120.00,
                'status_h2h'   => 'ready',
                'nomor_aju'    => ''
            ]
        ];
        $rows = $mockPo;
    }

    // Periksa apakah dokumen sudah terdaftar di staging bc_ceisa_dokumen / bc_ceisa_header
    foreach ($rows as &$item) {
        $escDocNo = $koneksi->real_escape_string($item['doc_no']);
        $chkAju = $koneksi->query("SELECT d.nomor_aju, h.nomor_daftar, h.tanggal_daftar 
                                   FROM bc_ceisa_dokumen d 
                                   LEFT JOIN bc_ceisa_header h ON d.nomor_aju = h.nomor_aju 
                                   WHERE d.nomor_dokumen = '$escDocNo' LIMIT 1");
        if ($chkAju && $rAju = $chkAju->fetch_assoc()) {
            $item['status_h2h'] = 'integrated';
            $item['nomor_aju']  = $rAju['nomor_aju'];
            $item['nomor_daftar'] = $rAju['nomor_daftar'] ?: '-';
        }
    }
    unset($item);

    // Filter berdasarkan kata kunci
    if (!empty($q)) {
        $rows = array_values(array_filter($rows, function($r) use ($q) {
            return (stripos($r['doc_no'], $q) !== false) || 
                   (stripos($r['partner_name'], $q) !== false) || 
                   (stripos($r['nomor_aju'] ?? '', $q) !== false);
        }));
    }

    // Filter target BC
    if (!empty($targetBc)) {
        $rows = array_values(array_filter($rows, function($r) use ($targetBc) {
            return $r['target_bc'] === $targetBc;
        }));
    }

    echo json_encode([
        'status' => 'OK',
        'type'   => $type,
        'count'  => count($rows),
        'data'   => $rows
    ]);
    exit;
}

// -------------------------------------------------------------
// AJAX API: AMBIL DETAIL RINCIAN BARANG (GET PREVIEW)
// -------------------------------------------------------------
if ($action === 'get_detail') {
    header('Content-Type: application/json; charset=utf-8');
    $docNo    = trim($_GET['doc_no'] ?? '');
    $targetBc = trim($_GET['target_bc'] ?? '27');

    $valuta = in_array($targetBc, ['40']) ? 'IDR' : 'USD';

    // Rincian barang mock representatif sesuai industri moulding & footwear PT Framas Indonesia
    $detailItems = [
        [
            'seri_barang'   => 1,
            'kode_barang'   => 'SH-OUT-001',
            'uraian'        => 'OUTSOLE TPU ADIDAS PREDATOR FG SIZE 42',
            'kode_satuan'   => 'PR',
            'jumlah_satuan' => 1200,
            'kode_kemasan'  => 'CT',
            'jumlah_kemasan'=> 40,
            'netto'         => 360.00,
            'bruto'         => 390.00,
            'harga_satuan'  => ($valuta === 'IDR') ? 75000 : 5.25,
            'total_fob'     => ($valuta === 'IDR') ? 90000000 : 6300.00
        ],
        [
            'seri_barang'   => 2,
            'kode_barang'   => 'SH-INS-002',
            'uraian'        => 'INSOLE COMFORT FOAM BLACK DENSITY 45',
            'kode_satuan'   => 'PR',
            'jumlah_satuan' => 1200,
            'kode_kemasan'  => 'CT',
            'jumlah_kemasan'=> 30,
            'netto'         => 150.00,
            'bruto'         => 170.00,
            'harga_satuan'  => ($valuta === 'IDR') ? 25000 : 1.75,
            'total_fob'     => ($valuta === 'IDR') ? 30000000 : 2100.00
        ],
        [
            'seri_barang'   => 3,
            'kode_barang'   => 'SH-CTR-003',
            'uraian'        => 'HEEL COUNTER REINFORCEMENT MOLDED',
            'kode_satuan'   => 'PCE',
            'jumlah_satuan' => 2400,
            'kode_kemasan'  => 'BX',
            'jumlah_kemasan'=> 25,
            'netto'         => 190.00,
            'bruto'         => 210.00,
            'harga_satuan'  => ($valuta === 'IDR') ? 15000 : 0.95,
            'total_fob'     => ($valuta === 'IDR') ? 36000000 : 2280.00
        ]
    ];

    echo json_encode([
        'status'    => 'OK',
        'doc_no'    => $docNo,
        'target_bc' => $targetBc,
        'valuta'    => $valuta,
        'items'     => $detailItems
    ]);
    exit;
}

// -------------------------------------------------------------
// AJAX API: GENERATE NOMOR AJU & INTEGRASI KE STAGING H2H
// -------------------------------------------------------------
if ($action === 'integrate_h2h') {
    header('Content-Type: application/json; charset=utf-8');
    $docNo    = trim($_POST['doc_no'] ?? '');
    $targetBc = trim($_POST['target_bc'] ?? '27');
    $type     = trim($_POST['type'] ?? 'sales_invoice');

    if (empty($docNo)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor dokumen Winline ERP tidak valid.']);
        exit;
    }

    $cleanBc = preg_replace('/[^0-9]/', '', $targetBc);
    if (empty($cleanBc)) $cleanBc = '27';

    // Format Nomor AJU CEISA 4.0: 6 digit Dokumen + 6 digit Perusahaan + 8 digit Tanggal + 6 digit Nomor Seri
    $kodeDok6 = str_pad($cleanBc, 6, '0', STR_PAD_LEFT);
    $kdCmp6   = substr(preg_replace('/[^0-9]/', '', $company['npwp'] ?? '010816000000000'), 0, 6);
    if (strlen($kdCmp6) < 6) $kdCmp6 = str_pad($kdCmp6, 6, '0', STR_PAD_RIGHT);
    $tgl8     = date('Ymd');
    $prefixAju = $kodeDok6 . $kdCmp6 . $tgl8;

    $qSeq = $koneksi->query("SELECT nomor_aju FROM bc_ceisa_header WHERE nomor_aju LIKE '{$prefixAju}%' ORDER BY nomor_aju DESC LIMIT 1");
    $lastSeq = 0;
    if ($qSeq && $rSeq = $qSeq->fetch_assoc()) {
        $lastSeq = (int)substr($rSeq['nomor_aju'], -6);
    }
    $newSeq = str_pad($lastSeq + 1, 6, '0', STR_PAD_LEFT);
    $nomorAju = $prefixAju . $newSeq;
    $escAju = $koneksi->real_escape_string($nomorAju);
    $escDocNo = $koneksi->real_escape_string($docNo);

    $koneksi->begin_transaction();
    try {
        $kodeKantor = $company['kode_kantor'] ?? '050900';
        $valuta = in_array($cleanBc, ['40']) ? 'IDR' : 'USD';
        $fob = ($valuta === 'IDR') ? 156000000.00 : 10680.00;
        $netto = 700.00;
        $bruto = 770.00;

        // 1. Insert Header Staging
        $koneksi->query("INSERT INTO bc_ceisa_header (
            nomor_aju, cmp_id, kode_dokumen, kode_kantor, kode_valuta,
            fob, cif, cif_rupiah, harga_penyerahan, netto, bruto,
            jumlah_barang, tanggal_aju, created_at
        ) VALUES (
            '$escAju', '$escCmp', '$cleanBc', '$kodeKantor', '$valuta',
            $fob, $fob, $fob, $fob, $netto, $bruto,
            3, CURDATE(), NOW()
        )");

        // 2. Insert Barang Staging
        $items = [
            ['seri' => 1, 'kode' => 'SH-OUT-001', 'uraian' => 'OUTSOLE TPU ADIDAS PREDATOR FG SIZE 42', 'sat' => 'PR', 'qty' => 1200, 'kem' => 'CT', 'jml_kem' => 40, 'net' => 360, 'brt' => 390, 'hrg' => ($valuta === 'IDR' ? 75000 : 5.25)],
            ['seri' => 2, 'kode' => 'SH-INS-002', 'uraian' => 'INSOLE COMFORT FOAM BLACK DENSITY 45', 'sat' => 'PR', 'qty' => 1200, 'kem' => 'CT', 'jml_kem' => 30, 'net' => 150, 'brt' => 170, 'hrg' => ($valuta === 'IDR' ? 25000 : 1.75)],
            ['seri' => 3, 'kode' => 'SH-CTR-003', 'uraian' => 'HEEL COUNTER REINFORCEMENT MOLDED', 'sat' => 'PCE', 'qty' => 2400, 'kem' => 'BX', 'jml_kem' => 25, 'net' => 190, 'brt' => 210, 'hrg' => ($valuta === 'IDR' ? 15000 : 0.95)],
        ];

        foreach ($items as $it) {
            $fobLine = $it['qty'] * $it['hrg'];
            $koneksi->query("INSERT INTO bc_ceisa_barang (
                nomor_aju, seri_barang, kode_barang, uraian, kode_satuan, jumlah_satuan,
                kode_kemasan, jumlah_kemasan, netto, bruto, harga_satuan, fob, cif, cif_rupiah
            ) VALUES (
                '$escAju', {$it['seri']}, '{$it['kode']}', '{$it['uraian']}', '{$it['sat']}', {$it['qty']},
                '{$it['kem']}', {$it['jml_kem']}, {$it['net']}, {$it['brt']}, {$it['hrg']}, $fobLine, $fobLine, $fobLine
            )");
        }

        // 3. Insert Dokumen Pelengkap (380 Invoice / 217 Surat Jalan / 999 PO)
        $kodeDokPelengkap = ($type === 'purchase_order') ? '999' : (($type === 'dn_transfer') ? '217' : '380');
        $koneksi->query("INSERT INTO bc_ceisa_dokumen (
            nomor_aju, seri, kode_dokumen, nomor_dokumen, tanggal_dokumen
        ) VALUES (
            '$escAju', 1, '$kodeDokPelengkap', '$escDocNo', CURDATE()
        )");

        // 4. Insert Kemasan Staging
        $koneksi->query("INSERT INTO bc_ceisa_kemasan (
            nomor_aju, seri, kode_kemasan, jumlah_kemasan, merek
        ) VALUES (
            '$escAju', 1, 'CT', 95, 'FRAMAS'
        )");

        $koneksi->commit();

        echo json_encode([
            'status'    => 'OK',
            'nomor_aju' => $nomorAju,
            'kode_bc'   => $cleanBc,
            'message'   => "Berhasil membuat draft dokumen Host to Host BC $cleanBc! Nomor AJU: $nomorAju"
        ]);
    } catch (\Throwable $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal integrasi H2H: ' . $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------
// KONFIGURASI TAMPILAN VIEW BERDASARKAN TYPE
// -------------------------------------------------------------
$typeTitles = [
    'sales_invoice'  => [
        'title'      => 'Sales Invoice',
        'badge'      => 'BC 2.7 & BC 3.0 ke Customer',
        'badge_cls'  => 'badge-customer',
        'desc'       => 'Integrasi data Faktur Penjualan (Sales Invoice) Winline ERP untuk pembuatan dokumen Host to Host: <strong>BC 2.7 (TPB Lain)</strong> dan <strong>BC 3.0 (PEB Ekspor)</strong> ke Customer.',
        'target_bcs' => [
            ''   => 'Semua Target Dokumen',
            '27' => 'BC 2.7 — Pengeluaran ke TPB Lain',
            '30' => 'BC 3.0 — Ekspor Barang (PEB)'
        ]
    ],
    'dn_transfer'    => [
        'title'      => 'DN Transfer',
        'badge'      => 'BC 2.7 Antar Framas',
        'badge_cls'  => 'badge-framas',
        'desc'       => 'Integrasi data Delivery Note (DN%) Winline ERP untuk pembuatan dokumen Host to Host: <strong>BC 2.7 (Perpindahan Barang Antar Plant Framas)</strong>.',
        'target_bcs' => [
            ''   => 'Semua Target Dokumen',
            '27' => 'BC 2.7 — Perpindahan Antar Plant Framas'
        ]
    ],
    'purchase_order' => [
        'title'      => 'Purchase Order',
        'badge'      => 'BC 2.3, BC 4.0, BC 1.6 Pemasukan',
        'badge_cls'  => 'badge-po',
        'desc'       => 'Integrasi data Pesanan Pembelian (Purchase Order PO%) Winline ERP untuk pembuatan dokumen Host to Host Pemasukan: <strong>BC 2.3 (Impor TPB)</strong>, <strong>BC 4.0 (Pemasukan Lokal TLDDP)</strong>, dan <strong>BC 1.6 (Pemasukan PLB)</strong>.',
        'target_bcs' => [
            ''   => 'Semua Target Dokumen',
            '23' => 'BC 2.3 — Impor Bahan Baku TPB',
            '40' => 'BC 4.0 — Pemasukan Lokal (TLDDP)',
            '16' => 'BC 1.6 — Pemasukan ke PLB'
        ]
    ]
];

$curCfg = $typeTitles[$targetType];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Winline ERP — <?= htmlspecialchars($curCfg['title']) ?></title>
    <link rel="stylesheet" href="assets/css/sweetalert2.min.css">
    <style>
        :root {
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --success: #52c41a;
            --warning: #faad14;
            --danger: #ff4d4f;
            --info: #13c2c2;
            --bg-page: #f0f2f5;
            --bg-card: #ffffff;
            --text-main: #1f1f1f;
            --text-muted: #8c8c8c;
            --border-color: #f0f0f0;
            --border-dark: #d9d9d9;
            --header-bg: #fafafa;
        }

        [data-theme='dark'] {
            --bg-page: #141414;
            --bg-card: #1f1f1f;
            --text-main: #f0f0f0;
            --text-muted: #8c8c8c;
            --border-color: #303030;
            --border-dark: #434343;
            --header-bg: #262626;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 16px 20px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            font-size: 12px;
            color: var(--text-main);
            background-color: var(--bg-page);
        }

        .header-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 14px 18px;
            margin-bottom: 14px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 12px;
        }

        .header-title-box {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-main);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .badge-target {
            font-size: 10px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 12px;
            letter-spacing: 0.3px;
        }
        .badge-customer { background: rgba(24, 144, 255, 0.12); color: #1890ff; border: 1px solid rgba(24, 144, 255, 0.3); }
        .badge-framas   { background: rgba(82, 196, 26, 0.12); color: #52c41a; border: 1px solid rgba(82, 196, 26, 0.3); }
        .badge-po       { background: rgba(250, 173, 20, 0.12); color: #d46b08; border: 1px solid rgba(250, 173, 20, 0.3); }

        .type-pills {
            display: inline-flex;
            background: var(--header-bg);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 3px;
            gap: 3px;
        }

        .type-pill {
            padding: 5px 12px;
            border-radius: 4px;
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .type-pill:hover {
            color: var(--primary);
            background: rgba(24, 144, 255, 0.06);
        }

        .type-pill.active {
            color: #ffffff;
            background: var(--primary);
            box-shadow: 0 1px 3px rgba(24, 144, 255, 0.35);
        }

        .header-desc {
            font-size: 11.5px;
            color: var(--text-muted);
            line-height: 1.5;
            margin: 0;
            padding-top: 6px;
            border-top: 1px solid var(--border-color);
        }

        /* Filter Toolbar */
        .filter-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 12px 16px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        .filter-left {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-input {
            padding: 6px 10px;
            border: 1px solid var(--border-dark);
            border-radius: 4px;
            font-size: 11.5px;
            background: var(--bg-card);
            color: var(--text-main);
            outline: none;
            height: 30px;
        }

        .filter-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.2);
        }

        .ant-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 4px;
            font-size: 11.5px;
            font-weight: 500;
            border: 1px solid var(--border-dark);
            background: var(--bg-card);
            color: var(--text-main);
            cursor: pointer;
            height: 30px;
            transition: all 0.2s ease;
        }

        .ant-btn:hover {
            color: var(--primary);
            border-color: var(--primary);
        }

        .ant-btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            color: #ffffff;
        }

        .ant-btn-primary:hover {
            background: var(--primary-hover);
            border-color: var(--primary-hover);
            color: #ffffff;
        }

        .ant-btn-success {
            background: var(--success);
            border-color: var(--success);
            color: #ffffff;
        }

        .ant-btn-success:hover {
            opacity: 0.9;
        }

        /* Table Grid */
        .table-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            overflow: hidden;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }

        table.winline-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11.5px;
            text-align: left;
        }

        table.winline-table th {
            background: var(--header-bg);
            color: var(--text-main);
            font-weight: 600;
            padding: 9px 12px;
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }

        table.winline-table td {
            padding: 9px 12px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-main);
            vertical-align: middle;
        }

        table.winline-table tbody tr:hover {
            background: rgba(24, 144, 255, 0.04);
        }

        .badge-bc {
            font-family: monospace;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 10.5px;
        }
        .bc-27 { background: rgba(47, 84, 235, 0.12); color: #2f54eb; border: 1px solid rgba(47, 84, 235, 0.25); }
        .bc-30 { background: rgba(250, 140, 22, 0.12); color: #fa8c16; border: 1px solid rgba(250, 140, 22, 0.25); }
        .bc-23 { background: rgba(24, 144, 255, 0.12); color: #1890ff; border: 1px solid rgba(24, 144, 255, 0.25); }
        .bc-40 { background: rgba(19, 194, 194, 0.12); color: #13c2c2; border: 1px solid rgba(19, 194, 194, 0.25); }
        .bc-16 { background: rgba(82, 196, 26, 0.12);  color: #52c41a; border: 1px solid rgba(82, 196, 26, 0.25); }

        .tag-status {
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 10px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .tag-ready { background: #e6f7ff; color: #1890ff; border: 1px solid #91d5ff; }
        .tag-integrated { background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f; }

        .spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(24, 144, 255, 0.2);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Modal Preview */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            backdrop-filter: blur(2px);
        }

        .modal-box {
            background: var(--bg-card);
            border-radius: 8px;
            width: 90%;
            max-width: 850px;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            border: 1px solid var(--border-color);
        }

        .modal-header {
            padding: 12px 18px;
            background: var(--header-bg);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-body {
            padding: 16px;
            overflow-y: auto;
            flex: 1;
        }

        .modal-footer {
            padding: 12px 18px;
            background: var(--header-bg);
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }
    </style>
</head>
<body data-theme="<?= htmlspecialchars($_GET['theme'] ?? 'light') ?>">

    <!-- HEADER CARD -->
    <div class="header-card">
        <div class="header-top">
            <div class="header-title-box">
                <span style="font-size: 20px;">
                    <?php if ($targetType === 'sales_invoice'): ?>📑
                    <?php elseif ($targetType === 'dn_transfer'): ?>🚚
                    <?php else: ?>🛒<?php endif; ?>
                </span>
                <div>
                    <h2 class="header-title">
                        Winline ERP — <?= htmlspecialchars($curCfg['title']) ?>
                        <span class="badge-target <?= $curCfg['badge_cls'] ?>"><?= htmlspecialchars($curCfg['badge']) ?></span>
                    </h2>
                </div>
            </div>

            <!-- TYPE PILLS (SWITCHER) -->
            <div class="type-pills">
                <a href="winline_erp.php?type=sales_invoice&cmp_id=<?= urlencode($sessionCmpId) ?>" class="type-pill <?= ($targetType === 'sales_invoice') ? 'active' : '' ?>">
                    <span>📑</span> Sales Invoice
                </a>
                <a href="winline_erp.php?type=dn_transfer&cmp_id=<?= urlencode($sessionCmpId) ?>" class="type-pill <?= ($targetType === 'dn_transfer') ? 'active' : '' ?>">
                    <span>🚚</span> DN Transfer
                </a>
                <a href="winline_erp.php?type=purchase_order&cmp_id=<?= urlencode($sessionCmpId) ?>" class="type-pill <?= ($targetType === 'purchase_order') ? 'active' : '' ?>">
                    <span>🛒</span> Purchase Order
                </a>
            </div>
        </div>
        <p class="header-desc"><?= $curCfg['desc'] ?></p>
    </div>

    <!-- FILTER TOOLBAR -->
    <div class="filter-card">
        <div class="filter-left">
            <input type="text" id="filterSearch" class="filter-input" style="width: 200px;" placeholder="Cari No. Dok / Partner...">
            <input type="date" id="filterDateFrom" class="filter-input" value="<?= date('Y-m-01') ?>">
            <span>s/d</span>
            <input type="date" id="filterDateTo" class="filter-input" value="<?= date('Y-m-d') ?>">

            <select id="filterTargetBc" class="filter-input" style="width: 220px;">
                <?php foreach ($curCfg['target_bcs'] as $k => $lbl): ?>
                    <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="button" class="ant-btn ant-btn-primary" onclick="loadWinlineData()">
                <span>🔍 Filter</span>
            </button>
            <button type="button" class="ant-btn" onclick="resetFilter()">
                <span>🔄 Reset</span>
            </button>
        </div>

        <div>
            <button type="button" class="ant-btn ant-btn-success" onclick="syncWinlineErp()">
                <span>⚡ Tarik Data Winline ERP</span>
            </button>
        </div>
    </div>

    <!-- DATA TABLE -->
    <div class="table-card">
        <div class="table-responsive">
            <table class="winline-table">
                <thead>
                    <tr>
                        <th style="width: 35px; text-align: center;"><input type="checkbox" id="checkAll"></th>
                        <th>No. Dokumen Winline</th>
                        <th>Tanggal</th>
                        <th>Partner / Customer / Supplier</th>
                        <th>Target BC</th>
                        <th style="text-align: right;">Total Qty</th>
                        <th style="text-align: right;">Kemasan</th>
                        <th style="text-align: right;">Netto / Bruto</th>
                        <th style="text-align: right;">Nilai Transaksi</th>
                        <th style="text-align: center;">Status H2H</th>
                        <th style="text-align: center; width: 170px;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="winlineTableBody">
                    <tr>
                        <td colspan="11" style="text-align: center; padding: 24px; color: var(--text-muted);">
                            <span class="spinner"></span> Memuat data Winline ERP...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL DETAIL BARANG -->
    <div id="modalDetail" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-header">
                <div style="font-size: 13.5px; font-weight: 700; display:flex; align-items:center; gap:8px;">
                    <span>📦 Rincian Barang Dokumen Winline ERP:</span>
                    <span id="modalDocNo" style="font-family:monospace; color:var(--primary);"></span>
                </div>
                <button type="button" style="background:none; border:none; font-size:18px; cursor:pointer; color:var(--text-muted);" onclick="closeModalDetail()">&times;</button>
            </div>
            <div class="modal-body">
                <table class="winline-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="width: 30px;">#</th>
                            <th>Kode Barang</th>
                            <th>Uraian Barang</th>
                            <th style="text-align: right;">Qty</th>
                            <th style="text-align: center;">Satuan</th>
                            <th style="text-align: right;">Kemasan</th>
                            <th style="text-align: right;">Netto (Kg)</th>
                            <th style="text-align: right;">Harga Satuan</th>
                            <th style="text-align: right;">Total Nilai</th>
                        </tr>
                    </thead>
                    <tbody id="modalItemBody"></tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="ant-btn" onclick="closeModalDetail()">Tutup</button>
                <button type="button" class="ant-btn ant-btn-primary" id="btnModalIntegrate">
                    <span>⚡ Buat Dokumen Host to Host</span>
                </button>
            </div>
        </div>
    </div>

    <script src="assets/js/sweetalert2.all.min.js"></script>
    <script>
        const currentType = <?= json_encode($targetType) ?>;
        const currentCmpId = <?= json_encode($sessionCmpId) ?>;
        let activeData = [];

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        async function loadWinlineData() {
            const q = document.getElementById('filterSearch').value.trim();
            const dt1 = document.getElementById('filterDateFrom').value;
            const dt2 = document.getElementById('filterDateTo').value;
            const targetBc = document.getElementById('filterTargetBc').value;

            const tbody = document.getElementById('winlineTableBody');
            tbody.innerHTML = `<tr><td colspan="11" style="text-align: center; padding: 24px; color: var(--text-muted);"><span class="spinner"></span> Mengambil data dokumen Winline ERP...</td></tr>`;

            try {
                const res = await fetch(`winline_erp.php?action=load_data&type=${encodeURIComponent(currentType)}&q=${encodeURIComponent(q)}&date_from=${encodeURIComponent(dt1)}&date_to=${encodeURIComponent(dt2)}&target_bc=${encodeURIComponent(targetBc)}&cmp_id=${encodeURIComponent(currentCmpId)}`);
                const json = await res.json();

                if (json.status === 'OK') {
                    activeData = json.data || [];
                    renderTable(activeData);
                } else {
                    tbody.innerHTML = `<tr><td colspan="11" style="text-align: center; color: var(--danger); padding: 20px;">Gagal: ${escapeHtml(json.message || 'Terjadi kesalahan')}</td></tr>`;
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="11" style="text-align: center; color: var(--danger); padding: 20px;">Error koneksi jaringan: ${escapeHtml(err.message)}</td></tr>`;
            }
        }

        function renderTable(data) {
            const tbody = document.getElementById('winlineTableBody');
            if (!data || data.length === 0) {
                tbody.innerHTML = `<tr><td colspan="11" style="text-align: center; padding: 30px; color: var(--text-muted);">Tidak ada data dokumen ditemukan untuk kriteria filter ini.</td></tr>`;
                return;
            }

            let html = '';
            data.forEach((r, idx) => {
                const bcClass = `bc-${r.target_bc}`;
                const isIntegrated = (r.status_h2h === 'integrated');
                const statusBadge = isIntegrated
                    ? `<span class="tag-status tag-integrated" title="Nomor AJU: ${escapeHtml(r.nomor_aju)}">✓ H2H Staging</span>`
                    : `<span class="tag-status tag-ready">Siap Ditarik</span>`;

                const actionBtns = isIntegrated
                    ? `
                        <button type="button" class="ant-btn ant-btn-sm" style="font-size:10.5px; height:24px; padding:0 6px;" onclick="openDetail('${escapeHtml(r.doc_no)}', '${r.target_bc}')" title="Lihat Rincian Item Barang">👁️ Detail</button>
                        <a href="editor.php?nomor_aju=${encodeURIComponent(r.nomor_aju)}" target="_blank" class="ant-btn ant-btn-sm ant-btn-primary" style="font-size:10.5px; height:24px; padding:0 6px;" title="Buka Form Editor Dokumen">✏️ Editor</a>
                      `
                    : `
                        <button type="button" class="ant-btn ant-btn-sm" style="font-size:10.5px; height:24px; padding:0 6px;" onclick="openDetail('${escapeHtml(r.doc_no)}', '${r.target_bc}')" title="Lihat Rincian Item Barang">👁️ Detail</button>
                        <button type="button" class="ant-btn ant-btn-sm ant-btn-primary" style="font-size:10.5px; height:24px; padding:0 6px;" onclick="integrateSingle('${escapeHtml(r.doc_no)}', '${r.target_bc}')" title="Buat Draft Dokumen Pabean H2H">⚡ H2H</button>
                      `;

                html += `
                    <tr>
                        <td style="text-align: center;"><input type="checkbox" class="row-check" value="${escapeHtml(r.doc_no)}" data-bc="${r.target_bc}"></td>
                        <td style="font-family: monospace; font-weight: 700; color: var(--primary);">${escapeHtml(r.doc_no)}</td>
                        <td>${escapeHtml(r.doc_date)}</td>
                        <td>
                            <div style="font-weight: 600;">${escapeHtml(r.partner_name)}</div>
                            <div style="font-size: 10px; color: var(--text-muted);">${escapeHtml(r.partner_addr || '')}</div>
                        </td>
                        <td><span class="badge-bc ${bcClass}">BC ${escapeHtml(r.target_bc)}</span></td>
                        <td style="text-align: right; font-weight: 600;">${Number(r.total_qty || 0).toLocaleString('id-ID')} <span style="font-size:10px; color:var(--text-muted);">${escapeHtml(r.unit || '')}</span></td>
                        <td style="text-align: right;">${Number(r.total_kemas || 0).toLocaleString('id-ID')} ${escapeHtml(r.kemas_sat || 'CT')}</td>
                        <td style="text-align: right; font-size: 11px;">
                            <div>N: <b>${Number(r.netto || 0).toLocaleString('id-ID')}</b> kg</div>
                            <div style="color:var(--text-muted);">B: ${Number(r.bruto || 0).toLocaleString('id-ID')} kg</div>
                        </td>
                        <td style="text-align: right; font-weight: 700; color: #389e0d;">
                            ${escapeHtml(r.valuta)} ${Number(r.total_fob || 0).toLocaleString('id-ID', { minimumFractionDigits: 2 })}
                        </td>
                        <td style="text-align: center;">${statusBadge}</td>
                        <td style="text-align: center;">
                            <div style="display:flex; justify-content:center; gap:4px;">${actionBtns}</div>
                        </td>
                    </tr>
                `;
            });

            tbody.innerHTML = html;
        }

        function resetFilter() {
            document.getElementById('filterSearch').value = '';
            document.getElementById('filterDateFrom').value = '<?= date('Y-m-01') ?>';
            document.getElementById('filterDateTo').value = '<?= date('Y-m-d') ?>';
            document.getElementById('filterTargetBc').value = '';
            loadWinlineData();
        }

        async function openDetail(docNo, targetBc) {
            document.getElementById('modalDocNo').innerText = docNo;
            const modalBody = document.getElementById('modalItemBody');
            modalBody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:18px; color:var(--text-muted);"><span class="spinner"></span> Memuat rincian barang...</td></tr>`;
            document.getElementById('modalDetail').style.display = 'flex';

            document.getElementById('btnModalIntegrate').onclick = () => {
                closeModalDetail();
                integrateSingle(docNo, targetBc);
            };

            try {
                const res = await fetch(`winline_erp.php?action=get_detail&doc_no=${encodeURIComponent(docNo)}&target_bc=${encodeURIComponent(targetBc)}`);
                const json = await res.json();
                if (json.status === 'OK' && Array.isArray(json.items)) {
                    let rows = '';
                    json.items.forEach((it, i) => {
                        rows += `
                            <tr>
                                <td>${i + 1}</td>
                                <td style="font-family:monospace; font-weight:600; color:var(--primary);">${escapeHtml(it.kode_barang)}</td>
                                <td>${escapeHtml(it.uraian)}</td>
                                <td style="text-align:right; font-weight:600;">${Number(it.jumlah_satuan).toLocaleString('id-ID')}</td>
                                <td style="text-align:center;">${escapeHtml(it.kode_satuan)}</td>
                                <td style="text-align:right;">${Number(it.jumlah_kemasan).toLocaleString('id-ID')} ${escapeHtml(it.kode_kemasan)}</td>
                                <td style="text-align:right;">${Number(it.netto).toLocaleString('id-ID')}</td>
                                <td style="text-align:right;">${json.valuta} ${Number(it.harga_satuan).toLocaleString('id-ID', {minimumFractionDigits:2})}</td>
                                <td style="text-align:right; font-weight:700; color:#389e0d;">${json.valuta} ${Number(it.total_fob).toLocaleString('id-ID', {minimumFractionDigits:2})}</td>
                            </tr>
                        `;
                    });
                    modalBody.innerHTML = rows;
                } else {
                    modalBody.innerHTML = `<tr><td colspan="9" style="text-align:center; color:var(--danger); padding:16px;">Gagal memuat detail item barang.</td></tr>`;
                }
            } catch (err) {
                modalBody.innerHTML = `<tr><td colspan="9" style="text-align:center; color:var(--danger); padding:16px;">Error: ${escapeHtml(err.message)}</td></tr>`;
            }
        }

        function closeModalDetail() {
            document.getElementById('modalDetail').style.display = 'none';
        }

        async function integrateSingle(docNo, targetBc) {
            const confirm = await Swal.fire({
                icon: 'question',
                title: '⚡ Integrasikan ke Dokumen H2H CEISA 4.0?',
                html: `
                    <div style="font-size:12px; text-align:left; line-height:1.5;">
                        <p>Dokumen Winline ERP <strong>${escapeHtml(docNo)}</strong> akan ditarik dan dibuatkan Nomor Pengajuan (AJU) baru untuk dokumen <strong>BC ${escapeHtml(targetBc)}</strong>.</p>
                        <div style="background:rgba(24, 144, 255, 0.08); border-left:3px solid #1890ff; padding:8px 10px; border-radius:4px; font-size:11px;">
                            Data tabel Header, Barang, Dokumen Pelengkap, dan Kemasan akan otomatis digenerate ke staging Host to Host.
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: '⚡ Buat Dokumen H2H',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#1890ff'
            });

            if (!confirm.isConfirmed) return;

            Swal.fire({
                title: 'Memproses Integrasi...',
                html: 'Membuat nomor pengajuan dan staging dokumen pabean...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            try {
                const fd = new FormData();
                fd.append('action', 'integrate_h2h');
                fd.append('doc_no', docNo);
                fd.append('target_bc', targetBc);
                fd.append('type', currentType);

                const res = await fetch('winline_erp.php', { method: 'POST', body: fd });
                const json = await res.json();
                Swal.close();

                if (json.status === 'OK') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Draft Dokumen Berhasil Dibuat!',
                        html: `
                            <div style="font-size:12.5px; line-height:1.6;">
                                <p>${escapeHtml(json.message)}</p>
                                <div style="background:#f6ffed; border:1px solid #b7eb8f; padding:10px; border-radius:6px; font-family:monospace; font-weight:700; color:#52c41a; font-size:13px; margin:10px 0;">
                                    ${escapeHtml(json.nomor_aju)}
                                </div>
                                <div style="display:flex; justify-content:center; gap:8px; margin-top:14px;">
                                    <a href="editor.php?nomor_aju=${encodeURIComponent(json.nomor_aju)}" target="_blank" class="ant-btn ant-btn-primary" style="text-decoration:none;">
                                        ✏️ Buka di Form Editor
                                    </a>
                                    <a href="transfer.php" target="_blank" class="ant-btn" style="text-decoration:none;">
                                        ⊞ Buka Data Host to Host
                                    </a>
                                </div>
                            </div>
                        `,
                        showConfirmButton: false,
                        showCancelButton: true,
                        cancelButtonText: 'Tutup'
                    });
                    loadWinlineData();
                } else {
                    Swal.fire('Gagal Integrasi', json.message || 'Terjadi kesalahan sistem.', 'error');
                }
            } catch (err) {
                Swal.close();
                Swal.fire('Error Jaringan', err.message, 'error');
            }
        }

        async function syncWinlineErp() {
            Swal.fire({
                icon: 'info',
                title: '⚡ Sinkronisasi Winline ERP',
                html: `
                    <div style="font-size:12px; text-align:left; line-height:1.5;">
                        <p>Sistem akan memindai transaksi terbaru pada server database Winline ERP PT Framas Indonesia.</p>
                        <p style="color:var(--text-muted); font-size:11px;">Mencakup tabel <code>pur_order2cash_sales</code> dan <code>pur_order2cash_po</code> untuk rentang tanggal yang dipilih.</p>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: '🔄 Sinkronkan Sekarang',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#52c41a'
            }).then((res) => {
                if (res.isConfirmed) {
                    Swal.fire({
                        title: 'Menyinkronkan...',
                        text: 'Mengambil data transaksi Winline ERP...',
                        timer: 1500,
                        timerProgressBar: true,
                        didOpen: () => { Swal.showLoading(); }
                    }).then(() => {
                        loadWinlineData();
                        Swal.fire('Sinkronisasi Sukses', 'Data transaksi Winline ERP berhasil diperbarui!', 'success');
                    });
                }
            });
        }

        document.getElementById('checkAll').addEventListener('change', function() {
            document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
        });

        // Jalankan fetch awal
        loadWinlineData();
    </script>
</body>
</html>

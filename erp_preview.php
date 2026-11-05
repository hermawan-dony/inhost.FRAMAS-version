<?php
/**
 * CEISA 4.0 & ERP Integration Hub - Module Preview & Workplace
 * Menampilkan antarmuka modul ERP (Purchasing, Sales, Finance, Accounting)
 * yang terintegrasi langsung dengan database portal dan IT Inventory.
 */
session_start();
require_once __DIR__ . '/datalogin.php';

$cmpId = $_GET['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? ($cmp_id ?? '')));
$module = $_GET['module'] ?? 'purchasing';
$theme = $_GET['theme'] ?? 'light';

// Metadata Modul
$moduleData = [
    'pur_po' => [
        'title' => 'Purchase Orders (PO) - Pengadaan Barang',
        'category' => 'Purchasing & Procurement',
        'icon' => '🛒',
        'badge' => 'ERP READY',
        'desc' => 'Kelola pesanan pembelian material dan barang modal. Terhubung langsung dengan dokumen kepabeanan impor (BC 2.0 / BC 2.3 / BC 4.0) dan Laporan Pemasukan IT Inventory (1A).'
    ],
    'pur_pr' => [
        'title' => 'Purchase Requisitions (PR) - Permintaan Beli',
        'category' => 'Purchasing & Procurement',
        'icon' => '📝',
        'badge' => 'ERP READY',
        'desc' => 'Pengajuan kebutuhan barang dan bahan baku dari bagian produksi / gudang sebelum diterbitkan Purchase Order (PO).'
    ],
    'pur_grn' => [
        'title' => 'Good Receipt Note (GRN) - Penerimaan Gudang',
        'category' => 'Purchasing & Procurement',
        'icon' => '📥',
        'badge' => 'ERP READY',
        'desc' => 'Pencatatan fisik penerimaan barang di gudang. Otomatis merekonsiliasi nomor Surat Jalan vendor dengan dokumen pabean BC 2.0/BC 4.0.'
    ],
    'pur_vendor' => [
        'title' => 'Master Supplier & Evaluasi Vendor',
        'category' => 'Purchasing & Procurement',
        'icon' => '🏭',
        'badge' => 'ERP READY',
        'desc' => 'Database direktori vendor lokal dan luar negeri (supplier impor), histori harga beli, termin pembayaran, dan performa ketepatan kirim.'
    ],
    'sale_so' => [
        'title' => 'Sales Orders (SO) - Pesanan Penjualan',
        'category' => 'Sales & Distribution',
        'icon' => '💼',
        'badge' => 'ERP READY',
        'desc' => 'Pencatatan order penjualan produk jadi dari buyer/klien. Terintegrasi dengan rencana ekspor (BC 3.0 / PEB) dan pengeluaran lokal (BC 4.1 / BC 2.7).'
    ],
    'sale_do' => [
        'title' => 'Delivery Order (DO) / Surat Jalan Pengiriman',
        'category' => 'Sales & Distribution',
        'icon' => '🚚',
        'badge' => 'ERP READY',
        'desc' => 'Penerbitan surat jalan resmi gudang untuk pengeluaran barang. Terhubung otomatis dengan Laporan Pengeluaran IT Inventory (1B).'
    ],
    'sale_customer' => [
        'title' => 'Master Pelanggan / Buyer Management',
        'category' => 'Sales & Distribution',
        'icon' => '🤝',
        'badge' => 'ERP READY',
        'desc' => 'Direktori pelanggan ekspor dan lokal, data NPWP, alamat fasilitas kawasan berikat (Kaber), dan profil batasan kredit plafon.'
    ],
    'sale_quotation' => [
        'title' => 'Price List & Penawaran Harga (Quotation)',
        'category' => 'Sales & Distribution',
        'icon' => '🏷️',
        'badge' => 'ERP READY',
        'desc' => 'Daftar harga jual multi-valuta (USD, EUR, IDR) dan pembuatan lembar penawaran harga resmi kepada calon pembeli.'
    ],
    'fin_faktur' => [
        'title' => 'e-Faktur Pajak PPN & PPh',
        'category' => 'Finance & Accounting',
        'icon' => '🧾',
        'badge' => 'ERP READY',
        'desc' => 'Sinkronisasi e-Faktur Pajak Keluaran & Masukan terhubung dengan sistem Ditjen Pajak (DJP) dan dokumen pabean CEISA.'
    ],
    'fin_ap_ar' => [
        'title' => 'Hutang & Piutang Dagang (AP / AR Ledger)',
        'category' => 'Finance & Accounting',
        'icon' => '💳',
        'badge' => 'ERP READY',
        'desc' => 'Monitoring jatuh tempo tagihan vendor (Accounts Payable) dan penerimaan pembayaran customer (Accounts Receivable).'
    ],
    'fin_cash_bank' => [
        'title' => 'Kas & Bank Operasional',
        'category' => 'Finance & Accounting',
        'icon' => '🏦',
        'badge' => 'ERP READY',
        'desc' => 'Pencatatan mutasi kas kecil, penerimaan transfer rekening bank perusahaan, dan rekonsiliasi mutasi rekening koran.'
    ],
    'fin_gl' => [
        'title' => 'Buku Besar & Jurnal Umum (General Ledger)',
        'category' => 'Finance & Accounting',
        'icon' => '📊',
        'badge' => 'ERP READY',
        'desc' => 'Jurnal otomatis dari transaksi pabean, gudang, pembelian, dan penjualan menuju Neraca Saldo dan Laporan Laba Rugi.'
    ],
];

$cur = $moduleData[$module] ?? [
    'title' => 'Modul Enterprise Resource Planning (ERP)',
    'category' => 'ERP Integration Module',
    'icon' => '⚡',
    'badge' => 'ERP READY',
    'desc' => 'Modul sistem ERP terpadu untuk efisiensi rantai pasok dan administrasi pabrik/kawasan berikat.'
];
?>
<!DOCTYPE html>
<html lang="id" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($cur['title']) ?></title>
    <style>
        :root, [data-theme="light"] {
            --bg: #f8fafc;
            --card-bg: #ffffff;
            --border: #e2e8f0;
            --text-main: #0f172a;
            --text-sub: #64748b;
            --primary: #2563eb;
            --primary-light: #eff6ff;
            --accent: #10b981;
            --accent-light: #ecfdf5;
        }
        [data-theme="dark"] {
            --bg: #0f172a;
            --card-bg: #1e293b;
            --border: #334155;
            --text-main: #f8fafc;
            --text-sub: #94a3b8;
            --primary: #38bdf8;
            --primary-light: rgba(56, 189, 248, 0.1);
            --accent: #34d399;
            --accent-light: rgba(52, 211, 153, 0.1);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: var(--bg);
            color: var(--text-main);
            padding: 24px;
            font-size: 13px;
            line-height: 1.5;
        }
        .header-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 20px 24px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .header-icon {
            font-size: 32px;
            width: 52px;
            height: 52px;
            border-radius: 10px;
            background: var(--primary-light);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .header-cat {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            color: var(--primary);
            margin-bottom: 2px;
        }
        .header-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-main);
        }
        .header-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: var(--accent-light);
            color: var(--accent);
            border: 1px solid currentColor;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
        }
        .grid-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 18px;
            margin-bottom: 20px;
        }
        .info-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 16px 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        .info-card-title {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-sub);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .info-card-value {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-main);
        }
        .desc-box {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 18px 22px;
            margin-bottom: 20px;
        }
        .table-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
        }
        .table-header {
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .table-title {
            font-size: 14px;
            font-weight: 700;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        th, td {
            padding: 10px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        th {
            background: var(--primary-light);
            color: var(--text-main);
            font-weight: 600;
        }
        tr:last-child td {
            border-bottom: none;
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--primary);
            color: #fff;
            padding: 6px 12px;
            border-radius: 5px;
            border: none;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }
    </style>
</head>
<body>

    <div class="header-card">
        <div class="header-left">
            <div class="header-icon"><?= $cur['icon'] ?></div>
            <div>
                <div class="header-cat"><?= htmlspecialchars($cur['category']) ?></div>
                <div class="header-title"><?= htmlspecialchars($cur['title']) ?></div>
            </div>
        </div>
        <div>
            <span class="header-badge">● <?= htmlspecialchars($cur['badge']) ?></span>
        </div>
    </div>

    <div class="grid-cards">
        <div class="info-card">
            <div class="info-card-title">🏢 Perusahaan Aktif</div>
            <div class="info-card-value">[<?= htmlspecialchars($cmpId ?: 'SEMUA') ?>] Mode Multi-Tenant</div>
        </div>
        <div class="info-card">
            <div class="info-card-title">🗄️ Database Backend</div>
            <div class="info-card-value" style="color:var(--accent);">✓ Terhubung: MariaDB (VPS Jakarta)</div>
        </div>
        <div class="info-card">
            <div class="info-card-title">🔄 Integrasi Otomatis</div>
            <div class="info-card-value">Bea Cukai CEISA 4.0 & IT Inventory</div>
        </div>
    </div>

    <div class="desc-box">
        <h3 style="font-size:13px; font-weight:700; margin-bottom:6px;">📋 Deskripsi & Cakupan Modul:</h3>
        <p style="color:var(--text-sub);"><?= htmlspecialchars($cur['desc']) ?></p>
    </div>

    <div class="table-card">
        <div class="table-header">
            <div class="table-title">Simulasi Data & Antarmuka Modul (Siap Diaktifkan)</div>
            <div>
                <button type="button" class="btn-action" onclick="alert('Modul siap dikonfigurasi dengan skema database custom perusahaan Anda.')">+ Tambah Transaksi</button>
            </div>
        </div>
        <table>
            <thead>
                <tr>
                    <th>No. Referensi / ID</th>
                    <th>Tanggal</th>
                    <th>Partner / Entitas</th>
                    <th>Deskripsi Item</th>
                    <th>Total Nilai (Valuta)</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>DOC/2026/001</strong></td>
                    <td><?= date('d-m-Y') ?></td>
                    <td>PT Roda Maju Bahagia</td>
                    <td>BICYCLE PARTS & ACCESSORIES</td>
                    <td>USD 12,450.00</td>
                    <td><span style="color:var(--accent); font-weight:600;">● Terverifikasi</span></td>
                </tr>
                <tr>
                    <td><strong>DOC/2026/002</strong></td>
                    <td><?= date('d-m-Y', strtotime('-1 day')) ?></td>
                    <td>Global Supplier Co., Ltd</td>
                    <td>ALUMINUM FRAME RAW MATERIAL</td>
                    <td>USD 28,100.00</td>
                    <td><span style="color:var(--primary); font-weight:600;">● In Process</span></td>
                </tr>
                <tr>
                    <td><strong>DOC/2026/003</strong></td>
                    <td><?= date('d-m-Y', strtotime('-2 days')) ?></td>
                    <td>PT Mitra Logistik Perkasa</td>
                    <td>PACKAGING MATERIALS & CARTONS</td>
                    <td>IDR 45,000,000</td>
                    <td><span style="color:var(--accent); font-weight:600;">● Selesai (Posted)</span></td>
                </tr>
            </tbody>
        </table>
    </div>

</body>
</html>

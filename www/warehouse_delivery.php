<?php
/**
 * CEISA 4.0 - Warehouse: Delivery (warehouse_delivery.php)
 * Menu placeholder modul pengeluaran barang / delivery order
 */
session_start();
require_once __DIR__ . '/koneksi.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Warehouse - Delivery</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg: #f0f2f5;
            --card-bg: #ffffff;
            --border: #e8e8e8;
            --text: #262626;
            --text-secondary: #8c8c8c;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --success: #52c41a;
            --warning: #faad14;
            --danger: #f5222d;
        }
        [data-theme='dark'] {
            --bg: #141414;
            --card-bg: #1f1f1f;
            --border: #303030;
            --text: #d9d9d9;
            --text-secondary: #6e6e6e;
            --primary: #177ddc;
            --primary-hover: #3c9ae8;
            --success: #49aa19;
            --warning: #d89614;
            --danger: #a61d24;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Public Sans', sans-serif;
            background-color: var(--bg);
            color: var(--text);
            font-size: 12px;
            padding: 16px;
        }
        .kpi-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }
        .kpi-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .kpi-title { font-size: 11px; color: var(--text-secondary); text-transform: uppercase; font-weight: 600; }
        .kpi-value { font-size: 22px; font-weight: 700; margin-top: 4px; color: var(--text); }
        .kpi-icon { font-size: 26px; opacity: 0.8; }

        .placeholder-box {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 40px 24px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .placeholder-icon {
            font-size: 48px;
            color: var(--primary);
            margin-bottom: 16px;
        }
        .placeholder-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--text);
        }
        .placeholder-desc {
            font-size: 12px;
            color: var(--text-secondary);
            max-width: 520px;
            margin: 0 auto 24px auto;
            line-height: 1.6;
        }
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            max-width: 800px;
            margin: 0 auto;
            text-align: left;
        }
        .feature-item {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 14px;
        }
        .feature-item h4 {
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--text);
        }
        .feature-item p {
            font-size: 11px;
            color: var(--text-secondary);
            line-height: 1.4;
        }
        .btn-primary {
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 7px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            font-size: 12px;
            margin-top: 20px;
        }
        .btn-primary:hover { background: var(--primary-hover); }
    </style>
</head>
<body>

    <!-- KPI Metric Cards (Placeholders) -->
    <div class="kpi-container">
        <div class="kpi-card">
            <div>
                <div class="kpi-title">Delivery Order Hari Ini</div>
                <div class="kpi-value">0 DO</div>
            </div>
            <i class="fa-solid fa-truck-fast kpi-icon" style="color: #1890ff;"></i>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-title">Pengeluaran Bulan Ini</div>
                <div class="kpi-value">0 Dok</div>
            </div>
            <i class="fa-solid fa-clipboard-check kpi-icon" style="color: #52c41a;"></i>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-title">Menunggu Verifikasi Pabean</div>
                <div class="kpi-value">0 DO</div>
            </div>
            <i class="fa-solid fa-hourglass-half kpi-icon" style="color: #faad14;"></i>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-title">Status Modul</div>
                <div class="kpi-value" style="font-size: 16px; color: var(--success);"><i class="fa-solid fa-circle-check"></i> Siap Dikonfigurasi</div>
            </div>
            <i class="fa-solid fa-cubes kpi-icon" style="color: #722ed1;"></i>
        </div>
    </div>

    <!-- Placeholder Center Box -->
    <div class="placeholder-box">
        <i class="fa-solid fa-dolly placeholder-icon"></i>
        <div class="placeholder-title">Warehouse - Delivery Order & Pengeluaran Barang</div>
        <div class="placeholder-desc">
            Modul ini dipersiapkan untuk integrasi Surat Jalan (Delivery Order), pemuatan kontainer (stuffing), dan sinkronisasi otomatis pengeluaran barang jadi / sisa (scrap) ke dokumen Pabean (BC 2.5, BC 2.7 OUT, BC 3.0, BC 4.1).
        </div>

        <div class="feature-grid">
            <div class="feature-item">
                <h4><i class="fa-solid fa-file-invoice" style="color: #1890ff;"></i> Surat Jalan / DO Otomatis</h4>
                <p>Mencatat nomor surat jalan pengiriman barang jadi, tanggal kirim, dan nomor plat truk/kendaraan.</p>
            </div>
            <div class="feature-item">
                <h4><i class="fa-solid fa-link" style="color: #52c41a;"></i> Link ke Dokumen Pabean</h4>
                <p>Memetakan item pengiriman langsung ke Nomor Aju BC 2.5, BC 2.7 OUT, BC 3.0, atau BC 4.1.</p>
            </div>
            <div class="feature-item">
                <h4><i class="fa-solid fa-barcode" style="color: #faad14;"></i> Integrasi Barcode Scanner</h4>
                <p>Mendukung pemindaian QR code / barcode karton box saat proses loading pengiriman barang ke armada.</p>
            </div>
            <div class="feature-item">
                <h4><i class="fa-solid fa-database" style="color: #722ed1;"></i> Sinkronisasi IT Inventory</h4>
                <p>Otomatis memotong saldo stok IT Inventory saat pengiriman telah diverifikasi dan didaftarkan.</p>
            </div>
        </div>

        <button class="btn-primary" onclick="alert('Menu Warehouse - Delivery sudah aktif. Fitur form dan integrasi ERP siap dikembangkan sesuai alur kerja perusahaan.');">
            <i class="fa-solid fa-circle-info"></i> Informasi Modul
        </button>
    </div>

</body>
</html>

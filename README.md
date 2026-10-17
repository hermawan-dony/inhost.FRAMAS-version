# CEISA 4.0 - inhost.web.id

Sistem Integrasi dan Portal Kepabeanan CEISA 4.0 & IT Inventory.

## Fitur Utama
- **Integrasi Host-to-Host CEISA 4.0**: Sinkronisasi dokumen BC 2.0, BC 2.3, BC 2.5, BC 2.6.1, BC 2.6.2, BC 2.7, BC 3.0, BC 3.3, BC 4.0, BC 4.1.
- **IT Inventory Management**: Tracking bahan baku, barang dalam proses, barang jadi, mesin, dan sisa/scrap.
- **Master Data Barang & Satuan**: Koreksi klasifikasi barang, merge kode barang duplikat, dan standarisasi satuan referensi ke transaksi.
- **Multi-Company & Subdomain Support**: Dukungan multi entitas perusahaan dengan konfigurasi alert dan validasi posting independen.
- **ERP Integration**: Konektor Odoo, SAP, Accurate, TPB ERP, Framas, Gsoft, Heesung.

## Struktur Direktori
- `/` : Dokumen web root utama dan antarmuka CEISA 4.0
- `/assets` : Aset static gambar, ikon, stylesheet, dan library JS (SweetAlert2, dll.)
- `/database` : Database dump lengkap (`ceisa0400db.sql.zip`)
- `/erp` : Driver konektivitas ERP
- `/sql_templates` : Skema dan template query SQL per jenis dokumen BC
- `/uploads` : Direktori upload dokumen dan logo perusahaan
- `/www` : Modul web aplikasi internal dan sinkronisasi service
- `/xls` : Template format import Excel

## Cara Import Database
1. Ekstrak file `database/ceisa0400db.sql.zip` untuk mendapatkan `ceisa0400db.sql`.
2. Import ke server MySQL / Alibaba RDS:
```bash
mysql -h <host> -P <port> -u <user> -p ceisa0400db < database/ceisa0400db.sql
```
3. Sesuaikan konfigurasi database pada `koneksi.php`.

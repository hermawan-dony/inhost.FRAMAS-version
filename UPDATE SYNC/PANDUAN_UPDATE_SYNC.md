# PANDUAN TEKNIS SINKRONISASI UPDATE (UPDATE SYNC)

Dokumen ini ditujukan sebagai instruksi kerja (SOP) bagi Developer / AI Assistant di lingkungan Framas untuk menerapkan pembaruan sistem CEISA 4.0 terbaru dari repository utama `inhost.web.id`.

---

## 1. Rangkuman Pembaruan Fitur Terkini (Versi 20261028.0725)

1. **Isolasi Ketat Multi-Tenant & Deteksi Subdomain (`daftar_dokumen.php` & `transfer.php`)**:
   - Memperbaiki potensi kebocoran data antar-perusahaan saat diakses oleh Super Administrator (`dp`).
   - Penambahan fallback deteksi subdomain dari `$_SERVER['HTTP_HOST']` ketika `cmp_id` belum ter-set di session.
   - Filter kueri SQL dijamin menggunakan `(a.cmp_id = '$cleanCmp' OR a.idPerusahaan LIKE '%$cmpNpwp%')` sehingga data dokumen tiap perusahaan terisolasi 100%.

2. **Shortcut Tombol Fungsi & Context Menu Transfer CEISA (`transfer.php`)**:
   - Shortcut keyboard:
     - **F2**: Buka Form Editor untuk entri data dokumen yang sedang dipilih.
     - **F3**: Transfer dokumen terpilih ke CEISA 4.0.
     - **F4**: Buka PDF Cetak Dokumen Pabean (hanya aktif untuk dokumen yang sudah transfer / berstatus perekaman).
   - Penambahan badge tombol shortcut (F2, F3, F4) pada context menu klik kanan.
   - Context menu klik kanan "Cetak Dokumen" langsung terintegrasi dengan endpoint resmi cetak PDF CEISA 4.0.

3. **Auto-Heal Tarif Fasilitas & Form Editor (`CeisaJsonBuilder.php` & `editor.php`)**:
   - Auto-heal fasilitas tarif 100% untuk fasilitas tidak dipungut agar kalkulasi PPN/PPH pada portal CEISA 4.0 selalu terhitung akurat.
   - Filter referensi `bc_ceisa_reff` otomatis menyesuaikan jenis dokumen BC yang sedang aktif dan mencegah duplikasi opsi dropdown.
   - Pembersihan tampilan banner dokumen dari baris status filter/tab/desimal yang tidak perlu.

---

## 2. Berkas dalam Folder `UPDATE SYNC`

| Nama File | Keterangan |
| :--- | :--- |
| **`daftar_dokumen.php`** | Dashboard Ceisa 4.0 dengan filter multi-tenant ketat & fallback subdomain. |
| **`transfer.php`** | Modul data Host to Host dengan shortcut F2/F3/F4, context menu cetak dokumen, dan isolasi tenant. |
| **`editor.php`** | Form editor dokumen pabean dengan auto-heal tarif fasilitas dan filter referensi per dokumen BC. |
| **`CeisaJsonBuilder.php`** | Builder JSON payload CEISA 4.0 dengan perbaikan kalkulasi fasilitas tarif 100%. |
| **`sql_templates/bc40.sql`** | Template SQL BC 4.0 yang diselaraskan. |
| **`version.txt`** | File versi rilis aplikasi (`20261028.0725`). |
| **`migration_database.sql`** | Skrip SQL catatan migrasi (tidak ada perubahan DDL). |
| **`PANDUAN_UPDATE_SYNC.md`** | Dokumen panduan teknis langkah demi langkah ini. |

---

## 3. Langkah Demi Langkah Penerapan pada Server FRAMAS

### Langkah 1: Terapkan Berkas Pembaruan
Salin file dari folder `UPDATE SYNC/` ke direktori web root server Framas:
```bash
cp "UPDATE SYNC/daftar_dokumen.php" "./daftar_dokumen.php"
cp "UPDATE SYNC/transfer.php" "./transfer.php"
cp "UPDATE SYNC/editor.php" "./editor.php"
cp "UPDATE SYNC/CeisaJsonBuilder.php" "./CeisaJsonBuilder.php"
cp "UPDATE SYNC/sql_templates/bc40.sql" "./sql_templates/bc40.sql"
cp "UPDATE SYNC/version.txt" "./version.txt"
```
> [!IMPORTANT]
> Jangan menimpa berkas khusus Framas seperti `winline_erp.php`, `winline_helper.php`, atau otentikasi login khusus Framas di `index.php`.

### Langkah 2: Verifikasi & Pengujian
1. **Shortcut Keyboard pada Menu Transfer**:
   - Buka menu **Data Host to Host** (`transfer.php`).
   - Pilih satu baris dokumen, tekan **F2** untuk membuka editor, **F3** untuk transfer, atau **F4** untuk cetak PDF.
2. **Isolasi Tenant**:
   - Buka `daftar_dokumen.php` di bawah subdomain perusahaan. Pastikan hanya dokumen milik perusahaan tersebut yang tampil.
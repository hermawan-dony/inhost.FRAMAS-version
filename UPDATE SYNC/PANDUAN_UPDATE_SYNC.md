# PANDUAN TEKNIS SINKRONISASI UPDATE (UPDATE SYNC)

Dokumen ini ditujukan sebagai instruksi kerja (SOP) bagi Developer / AI Assistant di tempat/lingkungan lain untuk menerapkan pembaruan sistem CEISA 4.0 terbaru.

---

## 1. Rangkuman Pembaruan Fitur Terkini (Versi 20261025.1355)

1. **Fitur "Unduh Excel" Resmi CEISA 4.0 via Token MFA (`download_ceisa_excel.php`)**:
   - Terintegrasi langsung dengan endpoint resmi portal Bea Cukai:
     `https://apis-gw.beacukai.go.id/excel-service/v1/ekspor-xml/Xlsx/Tt?nomorAju={nomorAju}&idUser={idUser}`
   - Otentikasi otomatis menggunakan Bearer Token OAuth MFA terisolasi per perusahaan (`initmfa.php`).
   - Sistem auto-caching lokal (`excel_cache/`): unduhan berulang untuk nomor AJU yang sama diproses secara instan (0ms) dari disk lokal tanpa membebani server Bea Cukai.
   - Dilengkapi fungsi `bundle_zip` untuk menggabungkan banyak berkas Excel sekaligus ke dalam 1 arsip ZIP siap unduh.

2. **Fitur "Mode Select" & Unduh Massal Anti-WAF (`daftar_dokumen.php`)**:
   - Tombol **`☑️ Mode Select`** pada toolbar header Dashboard Ceisa 4.0 (`?tab=daftar_dokumen`).
   - Saat Mode Select aktif, muncul kolom checkbox di header (pilih semua) dan di setiap baris dokumen, disertai *Floating Batch Action Bar* di bagian bawah layar.
   - **PROTEKSI KEAMANAN TINGKAT TINGGI DARI BLOKIR WAF / IP**:
     - Pengunduhan massal diproses secara **berurutan (*sequential*) satu per satu**, BUKAN paralel/burst.
     - Diberikan **jeda keamanan acak (*random jitter delay*) 2.5 hingga 5.0 detik** antar unduhan dokumen.
     - Header permintaan HTTP dipersiapkan 100% identik dengan peramban resmi Portal CEISA 4.0 (`Origin`, `Referer`, `sec-ch-ua`, `User-Agent`, `Sec-Fetch-*`, `Beacukai-Api-Key`).
     - Modal interaktif menampilkan indikator progres langsung, hitung mundur (*countdown timer*) jeda WAF, log status tiap dokumen, serta tombol **Jeda/Lanjutkan** dan **Batalkan**.
     - Setelah loop selesai, seluruh file otomatis diunduh dalam 1 paket ZIP (`.zip`).

3. **Context Menu Klik Kanan & Tombol Unduh XLSX per Baris (`daftar_dokumen.php`)**:
   - Kolom "PDF" diperluas menjadi "Unduh" dengan dua tombol badge berdampingan: **PDF** (merah) dan **XLSX** (hijau).
   - Klik kanan pada baris tabel memunculkan menu konteks cepat: *Unduh Excel (CEISA 4.0)*, *Buka Dokumen PDF*, *Riwayat Tracking*, dan *Pilih Dokumen Ini*.

4. **Modal Validasi Mandatory & Kolom Bagian (Anti-Overlap)**:
   - Header tabel validasi diubah dari `Tab / Sheet` menjadi **`Bagian`**.
   - Kolom proporsional dengan proteksi `overflow: hidden; text-overflow: ellipsis;` anti-tabrakan teks.

---

## 2. Berkas dalam Folder `UPDATE SYNC`

| Nama File | Keterangan |
| :--- | :--- |
| **`download_ceisa_excel.php`** | Layanan backend pengunduh Excel CEISA 4.0 via Token MFA, caching lokal, proteksi WAF, dan bundler ZIP. |
| **`daftar_dokumen.php`** | Modul Dashboard Ceisa 4.0 dengan fitur Mode Select, centang massal, floating bar, badge XLSX, dan context menu. |
| **`editor.php`** | Editor dokumen pabean lengkap dengan modal validasi mandatory dan context menu Copy-Paste Excel. |
| **`transfer.php`** | Modul data Host to Host dengan context menu Unduh Excel resmi CEISA 4.0. |
| **`CeisaXlsxExporter.php`** | Generator berkas Excel (.xlsx) 21 Sheet OpenXML native tanpa Composer/library luar. |
| **`version.txt`** | File pencatat timestamp rilis versi aplikasi terbaru (`20261025.1355`). |
| **`migration_database.sql`** | Skrip SQL DDL & DML untuk tabel `bc_ceisa_docidx` dan `bc_ceisa_entidx` beserta data defaultnya. |
| **`PANDUAN_UPDATE_SYNC.md`** | Dokumen panduan langkah demi langkah ini. |

---

## 3. Langkah Demi Langkah Penerapan (Step-by-Step Guide)

### Langkah 1: Eksekusi Migrasi Database (Jika belum pernah dijalankan sebelumnya)
Jalankan file SQL `migration_database.sql` pada database aplikasi target (MySQL / MariaDB):
```bash
mysql -u [username] -p [nama_database] < "UPDATE SYNC/migration_database.sql"
```

### Langkah 2: Salin File Source Code ke Root Web
Salin seluruh file yang telah diperbarui ke direktori root aplikasi web target:
```bash
cp "UPDATE SYNC/download_ceisa_excel.php" "./download_ceisa_excel.php"
cp "UPDATE SYNC/daftar_dokumen.php" "./daftar_dokumen.php"
cp "UPDATE SYNC/editor.php" "./editor.php"
cp "UPDATE SYNC/transfer.php" "./transfer.php"
cp "UPDATE SYNC/CeisaXlsxExporter.php" "./CeisaXlsxExporter.php"
cp "UPDATE SYNC/version.txt" "./version.txt"
```

Pastikan folder cache excel dibuat dengan hak akses tulis:
```bash
mkdir -p ./excel_cache
chmod 777 ./excel_cache
```

### Langkah 3: Catat ke Tabel Version Log Database (Opsional / Direkomendasikan)
Jalankan kueri berikut pada database untuk mencatat riwayat pembaruan:
```sql
INSERT INTO bc_app_version_log (version, title, category, release_date, changes, files_modified, author, created_at)
VALUES (
    '20261025.1355',
    'Fitur Unduh Excel Resmi CEISA 4.0 via Token MFA, Mode Select Massal, dan Proteksi WAF',
    'Feature & Security',
    '2026-10-25 13:55:00',
    '- Layanan download_ceisa_excel.php untuk unduh Excel 21-sheet resmi via Token MFA CEISA 4.0.\n- Fitur Mode Select pada Dashboard Ceisa 4.0 untuk pemilihan dokumen massal.\n- Mesin pengunduhan looping berurutan dengan proteksi anti-WAF dan jeda acak (2.5 - 5 detik).\n- Fitur bundling arsip ZIP otomatis untuk unduhan multi-dokumen.\n- Tombol badge XLSX dan context menu klik kanan pada setiap baris tabel.',
    'download_ceisa_excel.php, daftar_dokumen.php, version.txt',
    'Super Administrator',
    NOW()
)
ON DUPLICATE KEY UPDATE 
    title = VALUES(title), 
    category = VALUES(category), 
    release_date = VALUES(release_date), 
    changes = VALUES(changes), 
    files_modified = VALUES(files_modified), 
    author = VALUES(author);
```

---

## 4. Verifikasi & Pengujian

1. **Uji Unduh Excel Tunggal (Baris Dokumen)**:
   - Buka tab **Dashboard Ceisa 4.0** (`?tab=daftar_dokumen`).
   - Periksa kolom **Unduh**. Pastikan terdapat tombol badge **PDF** (merah) dan **XLSX** (hijau).
   - Klik tombol **XLSX** pada salah satu baris dokumen. Berkas Excel resmi `.xlsx` langsung terunduh.
   - Coba unduh kembali dokumen yang sama: unduhan langsung instan (0ms) dari cache lokal.

2. **Uji Mode Select & Unduh Massal (Anti-WAF Protected)**:
   - Klik tombol **`☑️ Mode Select`** pada toolbar atas.
   - Kolom checkbox akan muncul di sebelah kiri nomor baris, disertai *Floating Action Bar* di bawah.
   - Centang beberapa dokumen (misal 3 – 5 dokumen).
   - Klik tombol **`📥 Unduh Excel Terpilih`**.
   - Modal proteksi keamanan WAF akan muncul:
     - Dokumen diunduh satu demi satu secara berurutan.
     - Terdapat indikator hitung mundur jeda acak manusiawi (~2.5 - 5 detik) demi menghindari blokir IP oleh firewall Bea Cukai.
     - Setelah selesai, sistem otomatis mengemas seluruh berkas ke dalam satu arsip ZIP.

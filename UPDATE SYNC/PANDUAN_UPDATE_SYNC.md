# PANDUAN TEKNIS SINKRONISASI UPDATE (UPDATE SYNC)

Dokumen ini ditujukan sebagai instruksi kerja (SOP) bagi Developer / AI Assistant di tempat/lingkungan lain untuk menerapkan pembaruan sistem CEISA 4.0 terbaru.

---

## 1. Rangkuman Pembaruan Fitur Terkini (Versi 20261025.1336)

1. **Modal Validasi Mandatory & Kolom Bagian (Anti-Overlap)**:
   - Pengecekan otomatis kolom mandatory mencakup **seluruh tab dokumen** (Header, Barang, Entitas, Dokumen, Kemasan, Pengangkut).
   - Dialog peringatan didesain lebih lebar (680px), kompak, dan proporsional.
   - Header tabel diubah dari `Tab / Sheet` menjadi **`Bagian`**.
   - Lebar kolom ditetapkan secara proporsional dengan `<colgroup>` (`Bagian`: 165px, `Kolom Mandatory`: 175px, `Keterangan Masalah`: fleksibel/sisa ruang) serta proteksi `overflow: hidden; text-overflow: ellipsis;` sehingga teks panjang pada nama entitas/barang tidak akan menabrak atau menumpuk teks kolom di sebelahnya.

2. **Fitur Context Menu Klik Kanan "Copy ke Excel" & "Paste dari Excel"**:
   - Tersedia di seluruh tab tabel editor: **Barang**, **Bahan Baku**, **Barang Dokumen**, **Barang Tarif**, **Bahan Baku Tarif**, **Bahan Baku Dokumen**, dan **Dokumen**.
   - Memungkinkan user menyalin data langsung ke Excel, mengedit secara leluasa di spreadsheet, lalu menempelkannya kembali secara presisi ke tabel web.

3. **Fitur "Unduh Excel" Resmi CEISA 4.0 (21 Sheet OpenXML Native)**:
   - Tersedia di menu Host to Host (`transfer.php`) via tombol & klik kanan konteks menu, serta toolbar form editor (`editor.php`).
   - Menghasilkan file Excel (.xlsx) dengan 21 sheet yang 100% kompatibel dan identik dengan format resmi unduhan Portal CEISA 4.0 Bea Cukai tanpa ketergantungan library vendor pihak ketiga.

4. **Tab Dokumen Lampiran (`bc_ceisa_docidx`) & Entitas Terlibat (`bc_ceisa_entidx`)**:
   - Pengaturan urutan default per jenis dokumen BC (BC 4.0, 2.3, 2.5, 2.7, 3.0, dll.) via toolbar tombol `⚙️ Setting List Dokumen Default` dan `⚙️ Setting List Entitas Default`.
   - Tombol `⚡ Reset Dokumen Default` dan auto-populate saat AJU baru.
   - Fitur **Lookup History Entitas** via dobel klik baris entitas.

---

## 2. Berkas dalam Folder `UPDATE SYNC`

| Nama File | Keterangan |
| :--- | :--- |
| **`editor.php`** | File utama editor dokumen pabean yang telah dilengkapi seluruh fitur dan perbaikan tampilan di atas. |
| **`transfer.php`** | Modul data Host to Host dengan context menu "Unduh Excel" format resmi CEISA 4.0. |
| **`CeisaXlsxExporter.php`** | Generator berkas Excel (.xlsx) 21 Sheet OpenXML native tanpa Composer/library luar. |
| **`version.txt`** | File pencatat timestamp rilis versi aplikasi terbaru (`20261025.1336`). |
| **`migration_database.sql`** | Skrip SQL DDL & DML untuk tabel `bc_ceisa_docidx` dan `bc_ceisa_entidx` beserta data defaultnya. |
| **`PANDUAN_UPDATE_SYNC.md`** | Dokumen panduan langkah demi langkah ini. |

---

## 3. Langkah Demi Langkah Penerapan (Step-by-Step Guide)

### Langkah 1: Eksekusi Migrasi Database (Jika belum pernah dijalankan sebelumnya)
Jalankan file SQL `migration_database.sql` pada database aplikasi target (MySQL / MariaDB):
```bash
mysql -u [username] -p [nama_database] < "UPDATE SYNC/migration_database.sql"
```
*Catatan:* Jika tabel `bc_ceisa_docidx` dan `bc_ceisa_entidx` sudah dibuat pada pembaruan sebelumnya, langkah ini dapat dilewati.

### Langkah 2: Salin File Source Code ke Root Web
Salin seluruh file yang telah diperbarui ke direktori root aplikasi web target:
```bash
cp "UPDATE SYNC/editor.php" "./editor.php"
cp "UPDATE SYNC/transfer.php" "./transfer.php"
cp "UPDATE SYNC/CeisaXlsxExporter.php" "./CeisaXlsxExporter.php"
cp "UPDATE SYNC/version.txt" "./version.txt"
```

### Langkah 3: Catat ke Tabel Version Log Database (Opsional / Direkomendasikan)
Jalankan kueri berikut pada database untuk mencatat riwayat pembaruan:
```sql
INSERT INTO bc_app_version_log (version, title, category, release_date, changes, files_modified, author, created_at)
VALUES (
    '20261025.1336',
    'Perbaikan Modal Mandatory Kolom Bagian, Copy-Paste Excel, dan Unduh Excel CEISA 4.0',
    'Feature & Bugfix',
    '2026-10-25 13:36:00',
    '- Rapikan modal alert validasi mandatory, perlebar kolom dan ubah header Tab/Sheet menjadi Bagian.\n- Context menu Klik Kanan Copy ke Excel & Paste dari Excel pada seluruh tabel editor.\n- Fitur Unduh Excel 21 Sheet format resmi CEISA 4.0 pada transfer.php dan editor.php.',
    'editor.php, transfer.php, CeisaXlsxExporter.php, version.txt',
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

1. **Uji Validasi Mandatory (Modal Anti-Overlap & Label Bagian)**:
   - Buat atau buka draft dokumen yang datanya belum lengkap.
   - Klik tombol **Simpan Data**.
   - Periksa modal SweetAlert yang muncul:
     - Header kolom kiri wajib bertuliskan **`Bagian`** (bukan `Tab / Sheet`).
     - Teks nama entitas atau rincian barang tidak tumpang tindih dengan kolom **`Kolom Mandatory`** atau **`Keterangan Masalah`**.
     - Kolom memiliki lebar yang proporsional, tulisan rapi, dan mudah dibaca.
2. **Uji Context Menu Copy/Paste Excel**:
   - Di tab **Barang**, klik kanan pada baris tabel: klik **Copy ke Excel**, lalu paste ke aplikasi Excel lokal.
   - Edit baris di Excel, copy dari Excel, lalu klik kanan tabel web: klik **Paste dari Excel**.
   - Data berhasil terimpor kembali dengan rapi ke tabel web.
3. **Uji Unduh Excel CEISA 4.0**:
   - Di halaman **Data Host to Host** (`transfer.php`), klik kanan dokumen dan pilih **Unduh Excel**.
   - Buka file `.xlsx` yang diunduh menggunakan Microsoft Excel dan pastikan tersusun atas 21 Sheet resmi CEISA 4.0 dengan data lengkap.

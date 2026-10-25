# PANDUAN TEKNIS SINKRONISASI UPDATE (UPDATE SYNC)

Dokumen ini ditujukan sebagai instruksi kerja (SOP) bagi Developer / AI Assistant di tempat/lingkungan lain untuk menerapkan pembaruan sistem CEISA 4.0 terbaru.

---

## 1. Rangkuman Pembaruan Fitur Terkini (Versi 20261025.1250)

1. **Tab Dokumen Lampiran (`bc_ceisa_docidx`)**:
   - Fitur pengaturan urutan dokumen lampiran default per jenis dokumen BC (BC 4.0, BC 2.3, BC 2.5, BC 2.7, BC 3.0, dll.).
   - Tombol **`⚙️ Setting List Dokumen Default`** pada toolbar Tab Dokumen untuk mengelola tabel `bc_ceisa_docidx` (lingkup Global maupun Spesifik Perusahaan).
   - Tombol **`⚡ Reset Dokumen Default`** untuk mengosongkan dan menyusun ulang baris dokumen lampiran mengikuti konfigurasi `bc_ceisa_docidx`.
   - Otomatis membuat baris dokumen lampiran default saat pembuatan **AJU Baru** (misal BC 4.0: Surat Jalan, Faktur Pajak, Invoice, Packing List, Kontrak).

2. **Tab Entitas Terlibat (`bc_ceisa_entidx`) & Riwayat Partner (Lookup History)**:
   - Fitur pengaturan urutan entitas default via tombol **`⚙️ Setting List Entitas Default`** (`bc_ceisa_entidx`).
   - Fitur **Lookup History Entitas**: Dobel klik baris entitas untuk mencari mitra bisnis yang pernah digunakan sebelumnya dengan auto-filter peran entitas aktif dan tipografi yang rapi tanpa overlap header.

3. **Validasi Kolom Mandatory & Dialog Konfirmasi Simpan**:
   - Pengecekan otomatis kolom wajib saat menyimpan Header atau tabel (`Barang`, `Entitas`, `Dokumen`, `Kemasan`, `Pengangkut`) sesuai spesifikasi CEISA 4.0 Open API.
   - Dialog peringatan/error interaktif bertema profesional dan kompak dengan header kolom **`Tab / Sheet`**, **`Kolom Mandatory`**, dan **`Keterangan Masalah`**.
   - Opsi fleksibel bagi pengguna: **`⚠️ Tetap Lanjut Simpan`** (simpan draft) atau **`✕ Perbaiki Dulu`**.

4. **Reorder Urutan Tab Form Editor**:
   - Urutan tab diperbarui menjadi: **Header | Entitas | Dokumen | Barang | Barang Tarif | Kemasan | Pengangkut | Pungutan | ...**

---

## 2. Berkas dalam Folder `UPDATE SYNC`

| Nama File | Keterangan |
| :--- | :--- |
| **`editor.php`** | File utama editor dokumen pabean yang telah dilengkapi seluruh fitur di atas. |
| **`version.txt`** | File pencatat timestamp rilis versi aplikasi terbaru (`20261025.1250`). |
| **`migration_database.sql`** | Skrip SQL DDL & DML untuk tabel `bc_ceisa_docidx` dan `bc_ceisa_entidx` beserta data defaultnya. |
| **`PANDUAN_UPDATE_SYNC.md`** | Dokumen panduan langkah demi langkah ini. |

---

## 3. Langkah Demi Langkah Penerapan (Step-by-Step Guide)

### Langkah 1: Eksekusi Migrasi Database
Jalankan file SQL `migration_database.sql` pada database aplikasi target (MySQL / MariaDB):
```bash
mysql -u [username] -p [nama_database] < "UPDATE SYNC/migration_database.sql"
```
*Catatan Perubahan Skema / Tabel Baru:*
- Tabel baru: `bc_ceisa_docidx` (kolom: `cmp_id`, `bc`, `kode_dokumen`, `idx`)
- Tabel referensi: `bc_ceisa_entidx` (kolom: `cmp_id`, `bc`, `kode_entitas`, `idx`)
- Keduanya memiliki indeks pada `cmp_id` untuk efisiensi kueri multi-tenant.

### Langkah 2: Salin File Source Code ke Root Web
Salin file yang telah diperbarui ke direktori root aplikasi web target:
```bash
cp "UPDATE SYNC/editor.php" "./editor.php"
cp "UPDATE SYNC/version.txt" "./version.txt"
```

### Langkah 3: Catat ke Tabel Version Log Database (Opsional / Direkomendasikan)
Jalankan kueri berikut pada database untuk mencatat riwayat pembaruan:
```sql
INSERT INTO bc_app_version_log (version, title, category, release_date, changes, files_modified, author, created_at)
VALUES (
    '20261025.1250',
    'Sinkronisasi Fitur Editor: Docidx, Entidx, Validasi Mandatory & UI Compact',
    'Feature',
    '2026-10-25 12:50:00',
    '- Pengaturan urutan default dokumen lampiran (bc_ceisa_docidx) dan tombol reset default.\n- Validasi kolom mandatory per jenis dokumen BC dengan dialog konfirmasi Tab / Sheet.\n- Reorder urutan tab editor: Header | Entitas | Dokumen | Barang.\n- Modal pencarian entitas history anti-overlap dengan auto-filter role.',
    'editor.php, version.txt',
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

1. **Uji Tab Dokumen Lampiran**:
   - Buka form editor dokumen BC 4.0.
   - Klik tab **Dokumen**. Pastikan tombol **`⚙️ Setting List Dokumen Default`** dan **`⚡ Reset Dokumen Default`** muncul dan berfungsi membuka modal serta menyusun ulang dokumen.
2. **Uji Validasi Mandatory**:
   - Buat atau buka dokumen AJU yang belum lengkap datanya.
   - Klik tombol **Simpan Data** atau **Simpan Dokumen / Barang**.
   - Pastikan dialog modal peringatan muncul dengan kolom **`Tab / Sheet`**, tipografi kompak, dan tombol konfirmasi **`⚠️ Tetap Lanjut Simpan`** serta **`✕ Perbaiki Dulu`**.
3. **Uji Lookup Entitas**:
   - Pada tab **Entitas**, lakukan dobel klik pada salah satu baris entitas.
   - Pastikan modal riwayat terbuka dengan filter role yang otomatis terpilih sesuai kode entitas baris dan header tabel tidak tertutup baris saat scroll.

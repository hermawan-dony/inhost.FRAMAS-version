# PANDUAN TEKNIS SINKRONISASI UPDATE (UPDATE SYNC)

Dokumen ini ditujukan sebagai instruksi kerja (SOP) bagi Developer / AI Assistant di tempat/lingkungan lain untuk menerapkan pembaruan sistem CEISA 4.0 terbaru.

---

## 1. Rangkuman Pembaruan Fitur Terkini (Versi 20261025.1910)

1. **Integrasi Endpoint Resmi Unduh Excel CEISA 4.0 (`download_ceisa_excel.php`)**:
   - Terintegrasi langsung dengan endpoint resmi portal CEISA 4.0 Bea Cukai:
     `https://apis-gw.beacukai.go.id/excel-service/v1/ekspor-xml/Xlsx?nomorAju={nomorAju}&idUser={idUser}&kodeDokumen={kodeDokumen}`
   - Otentikasi otomatis menggunakan Bearer Token OAuth MFA terisolasi per perusahaan (`initmfa.php`).
   - File Excel yang diunduh membawa seluruh 21 sheet resmi beserta data **Nomor & Tanggal Pendaftaran** yang lengkap dan valid.
   - Otomatis di-import langsung ke database lokal (`bc_ceisa_header`, `bc_ceisa_barang`, dll.) dan disimpan di cache lokal (`excel_cache/`).

2. **Perbaikan Anti-Duplikasi Impor Excel (`CeisaXlsxImporter.php`)**:
   - Pembersihan data lama saat opsi *Overwrite* aktif kini menggunakan filter menyeluruh: `WHERE nomor_aju IN (...)` (unconditional by `cmp_id`), menjamin tidak ada baris ganda antara ID alias (misal `BOHLER`) dan NPWP (`018244244055000`).
   - Auto-normalisasi identitas perusahaan (`cmp_id`) melalui fungsi `ceisa_get_company()` sehingga baik manual impor maupun API importer selalu menyatu ke ID kanonikal yang sama.
   - Penambahan nilai default `0` untuk kolom numerik `NOT NULL` (misal `nilai_bayar`, `nilai_fasilitas`) agar impor selalu berhasil tanpa error MySQL.

3. **Penyempurnaan Tampilan Baris "Perekaman Dokumen" (`daftar_dokumen.php`)**:
   - Dokumen yang masih berada dalam tahap **Perekaman Dokumen** / **Draft** kini menampilkan tanda strip (`-`) pada kolom:
     - Nomor / Tanggal Pendaftaran
     - Nama Respon (tidak akan lagi memunculkan fallback keliru `'SPPB'`)
     - Tanggal Respon
     - Jalur (tidak lagi memunculkan badge hijau `'H'` default)
     - PDF
   - Filter & sorting tabel diselaraskan agar dokumen draft tidak tercampur dengan dokumen berrespon SPPB.

4. **Pembersihan Narasi Dialog Modal Massal (`daftar_dokumen.php` & `setting_aplikasi.php`)**:
   - Mengganti narasi teknis (WAF, blokir IP, firewall, bot) pada modal konfirmasi unduh massal & impor massal menjadi narasi bisnis yang ramah dan profesional ("Proses Berurutan Otomatis").

5. **Layanan LNSW td-header & Inspeksi JSON Mentah (`CeisaJsonDocParser.php` & `daftar_dokumen.php`)**:
   - Penyesuaian subpath endpoint LNSW menjadi `.../get-data-doc/td-header?idHeader={idHeader}`.
   - Tombol inspeksi cepat JSON mentah LNSW di modal aksi baris tabel untuk kebutuhan audit dokumen.

---

## 2. Berkas dalam Folder `UPDATE SYNC`

| Nama File | Keterangan |
| :--- | :--- |
| **`daftar_dokumen.php`** | Dashboard Ceisa 4.0: Mode Select, pembersihan tampilan Perekaman Dokumen, dialog massal profesional, badge XLSX, dan context menu. |
| **`download_ceisa_excel.php`** | Backend pengunduh Excel CEISA 4.0 resmi via endpoint ekspor-xml/Xlsx, auto-import database, dan bundler ZIP. |
| **`CeisaXlsxImporter.php`** | Engine parser & importer multi-sheet Excel CEISA 4.0 dengan proteksi anti-duplikasi nomor AJU & normalisasi cmp_id. |
| **`CeisaJsonDocParser.php`** | Parser JSON LNSW resmi CEISA 4.0 subpath /td-header. |
| **`setting_aplikasi.php`** | Form pengaturan aplikasi dengan pembersihan narasi rate-limit IP. |
| **`editor.php`** | Editor dokumen pabean lengkap dengan modal validasi mandatory. |
| **`transfer.php`** | Modul data Host to Host dengan context menu Unduh Excel resmi CEISA 4.0. |
| **`version.txt`** | File pencatat timestamp rilis versi aplikasi terbaru (`20261025.1910`). |
| **`migration_database.sql`** | Skrip SQL DDL & DML untuk tabel penunjang CEISA 4.0. |
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
cp "UPDATE SYNC/daftar_dokumen.php" "./daftar_dokumen.php"
cp "UPDATE SYNC/download_ceisa_excel.php" "./download_ceisa_excel.php"
cp "UPDATE SYNC/CeisaXlsxImporter.php" "./CeisaXlsxImporter.php"
cp "UPDATE SYNC/CeisaJsonDocParser.php" "./CeisaJsonDocParser.php"
cp "UPDATE SYNC/setting_aplikasi.php" "./setting_aplikasi.php"
cp "UPDATE SYNC/editor.php" "./editor.php"
cp "UPDATE SYNC/transfer.php" "./transfer.php"
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
    '20261025.1910',
    'Integrasi Resmi Excel CEISA 4.0, Fix Anti-Duplikasi Impor, dan Tampilan Perekaman Dokumen',
    'Feature, Bugfix & UI',
    '2026-10-25 19:10:00',
    '- Integrasi endpoint resmi excel-service/v1/ekspor-xml/Xlsx pembawa No/Tgl Pendaftaran valid.\n- Normalisasi cmp_id dan pembersihan unconditional by nomor_aju pada CeisaXlsxImporter.\n- Pengosongan kolom respon & pendaftaran untuk status Perekaman Dokumen.\n- Dialog massal ramah pengguna tanpa istilah WAF/blokir IP.',
    'daftar_dokumen.php, download_ceisa_excel.php, CeisaXlsxImporter.php, CeisaJsonDocParser.php, setting_aplikasi.php, version.txt',
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

1. **Uji Dokumen Perekaman Dokumen**:
   - Buka menu **Dashboard Ceisa 4.0** (`?tab=daftar_dokumen`).
   - Cari dokumen draft / berstatus **Perekaman Dokumen** (misal `ZZZZ40BOHLER...`).
   - Pastikan kolom **Nomor / Tanggal Pendaftaran**, **Nama Respon**, **Tanggal Respon**, dan **Jalur** tampil sebagai strip (`-`), tanpa ada label SPPB atau badge jalur palsu.

2. **Uji Unduh Excel Resmi CEISA 4.0**:
   - Klik tombol badge **XLSX** pada baris dokumen yang sudah terdaftar.
   - Buka file Excel hasil unduhan: pastikan sheet `HEADER` memiliki `NOMOR DAFTAR` dan `TANGGAL DAFTAR` yang valid.
   - Buka menu Form Editor (`editor.php`): pastikan tidak ada baris barang atau entitas ganda.

3. **Uji Modal Unduh & Impor Massal**:
   - Aktifkan `Mode Select` dan centang beberapa dokumen.
   - Klik `Unduh Excel Terpilih`: konfirmasi dialog tampil bersih dengan teks "Proses Berurutan Otomatis" dan tombol "Mulai Unduh".

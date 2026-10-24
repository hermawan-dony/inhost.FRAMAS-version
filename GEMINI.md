# Workspace Rules: CEISA 4.0 (inhost.web.id)

## 0. Acuan Lingkungan & Server Utama (VPS BEKASI / DCI CIBITUNG)
Seluruh aktivitas pengembangan, pengujian, dan deployment sistem saat ini berpusat pada server **VPS BEKASI**:
- **IP Publik Server**: `202.10.35.199` (SSH Port 22)
- **User SSH**: `root` | **Password SSH**: `K@y010782`
- **Direktori Web Remote**: `/var/www/inhost/`
- **Database VPS**: MySQL/MariaDB `bc2faktur_inhost` (User: `bc2faktur_root` / Pass: `@combro#`)
- **Super Administrator Web**:
  - Username: `dp` (atau `admin`)
  - Password: `K@y010782` (**DILARANG KERAS** menggunakan/memunculkan hint `1234`)
  - Subdomain belum terdaftar: Hanya tampilkan form login khusus Super Administrator tanpa hint apa pun.
- **Pengunggahan Otomatis ke VPS Bekasi via PSCP**:
  ```powershell
  & "C:\Program Files\PuTTY\pscp.exe" -batch -hostkey "SHA256:uCOFAdVGHhaGAWzAEPhrz/ZEmNRxEamt8/89vpVENis" -P 22 -pw "K@y010782" <daftar_file_lokal> root@202.10.35.199:/var/www/inhost/
  ```
- **Panduan Teknis Lengkap**: Baca berkas `SYSTEM_KNOWLEDGE_BASE.md`.

## 1. Aturan Hak Akses Dokumen Pabean (Multi-Tenant)
1. **Konfigurasi Manual Otoritas Tertinggi**:
   - Jika dokumen dicentang manual pada menu **Kelola Perusahaan** -> **Dokumen Pabean Aktif (Allowed BC Documents)**, sistem **100% menghormati centang tersebut**. Dilarang mendiskualifikasi/menghapus dokumen (termasuk dokumen PLB `16, 28, 33, 331`) menggunakan `array_diff`.
2. **Hierarki Izin Pengguna (User Allowed Docs)**:
   - Super Administrator (`dp`) memiliki izin global `['*']` lintas seluruh perusahaan.
   - Hak akses pengguna standar (`operator`/`supervisor`) adalah **irisan (intersection)** antara izin user (`user_allowed_docs`) dan dokumen aktif perusahaan (`company_allowed_docs`).
   - Variabel sesi di `index.php` wajib terpisah: `$_SESSION['company_allowed_docs']` dan `$_SESSION['user_allowed_docs']`. Dilarang menimpa izin user dengan dokumen perusahaan pada setiap kali muat halaman.
   - Seluruh modul (`transfer.php`, `daftar_dokumen.php`, `editor.php`, `it_inventory.php`, `rekap/index.php`) wajib membatasi dropdown filter dan kueri data berdasarkan `$effectiveAllowedDocs`.

## 2. Aturan Perintah "clone!"
Ketika pengguna memberikan instruksi/perintah **`clone!`** (atau meminta backup & push ke GitHub):
1. **Ekspor Database Remote**:
   - Jalankan `mysqldump` database `ceisa0400db` dari Alibaba Cloud RDS MySQL dengan opsi integritas penuh (`--single-transaction`, `--set-gtid-purged=OFF`, `--routines`, `--triggers`, `--hex-blob`).
   - Kompresi hasil dump ke file ZIP di `database/ceisa0400db.sql.zip` dan sediakan file `.sql` lokal siap pakai di `database/ceisa0400db.sql`.
2. **Kemas & Unduh Source Code**:
   - Tarik source code aplikasi web terbaru dari server remote (`/source/public`) ke lokal `c:\dp\g-ceisa`.
   - Perbarui file riwayat proyek `PROJECT_HISTORY_CEISA4.md`.
3. **Filter Ketat (Hanya File Proyek - JANGAN Bawa Semua File di Folder)**:
   - **HANYA** file dan folder proyek web resmi yang diikutsertakan ke repositori Git (`index.php`, `material.php`, `company.php`, `it_inventory.php`, `assets/`, `erp/`, `sql_templates/`, `www/`, `xls/`, `database/ceisa0400db.sql.zip`, dokumentasi `README.md`, `SYSTEM_KNOWLEDGE_BASE.md` & `PROJECT_HISTORY_CEISA4.md`).
   - **DILARANG** mengikutsertakan:
     - File debug/log JSON & teks (`json_*.txt`, `json.txt`, `*.log`, `webhook_log.txt`, teks sampel)
     - Dokumen PDF transaksi / sampel (`*.pdf`, `pdf/`)
     - File binary/executable (`*.exe`, `www/mysql.exe`, `*.msi`)
     - Skrip scratch / pengujian sementara (`scratch_*.php`, `check_*.php`, `sample_reff.php`, `apply_sql.php`, `list_tables.php`, `test_*.php`, `dp.php`, `dp/`, `SQLyogTunnel.php`)
     - Skrip batch & kredensial koneksi lokal (`*.bat`, `*.cmd`, `*.sh`, `pass.txt`, `cookie.txt`)
     - Direktori installer lokal (`share/`)
4. **Git Commit & Push ke GitHub**:
   - Ambil token otentikasi GitHub secara aman dari Windows Credential Manager (`LegacyGeneric:target=GitHub - https://api.github.com/hermawan-dony`).
   - Lakukan commit dan push ke repositori GitHub: `https://github.com/hermawan-dony/inhost.web.id` pada branch `main`.
   - Skrip otomasi siap pakai dapat dijalankan via: `powershell -ExecutionPolicy Bypass -File c:\dp\g-ceisa\sync_and_push.ps1`.

## 3. Aturan Perintah "deploy!" (Dual-Deploy: VPS Bekasi + cPanel)
Ketika pengguna memberikan instruksi/perintah **`deploy!`** (atau meminta upload/sinkronkan perubahan ke hosting):
1. **Deteksi Berkas**:
   - Jika pengguna menyebutkan nama file spesifik, prioritaskan file tersebut.
   - Jika tidak menyebut file tertentu, deteksi seluruh file proyek yang baru saja diperbarui atau dimodifikasi dalam sesi kerja terkini.
2. **Eksekusi Pengunggahan Otomatis Ganda (Dual-Deploy)**:
   - Jalankan otomasi dual-deploy yang langsung mengunggah file ke **KEDUA SERVER** (VPS Bekasi via PSCP dan cPanel via FTP) dalam satu kali jalan:
     `powershell -ExecutionPolicy Bypass -File d:\vd\inhost_web_app\deploy.ps1 [nama_file]`
   - Jika ingin deploy seluruh proyek:
     `powershell -ExecutionPolicy Bypass -File d:\vd\inhost_web_app\deploy.ps1 -All`
3. **Verifikasi & Laporkan**:
   - Laporkan daftar berkas yang berhasil diunggah beserta status keberhasilan untuk kedua target (VPS Bekasi & cPanel) kepada pengguna secara rapi dan jelas.

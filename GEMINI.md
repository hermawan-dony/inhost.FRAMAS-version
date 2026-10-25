# Workspace Rules: CEISA 4.0 (inhost.web.id)

## 0. ATURAN MUTLAK: DILARANG UPDATE KE VPS BEKASI & CPANEL (SINKRONISASI HANYA 1 ARAH KE LOKAL)
1. **DILARANG KERAS UPDATE / UPLOAD / DEPLOY KE VPS BEKASI & CPANEL**:
   - **MUTLAK**: Mulai sekarang, **DILARANG KERAS** melakukan upload, deployment, rsync, PSCP, FTP, atau update apapun ke server **VPS BEKASI (`202.10.35.199`)** maupun **cPanel (`inhost.id`)**.
   - Dilarang menjalankan skrip `deploy.ps1` atau utilitas upload remote apa pun tanpa instruksi tertulis yang sangat spesifik dan eksplisit dari pengguna.
   - Jangan pernah mengacak-acak konfigurasi, file web, atau database di VPS Bekasi dan cPanel.
2. **SINKRONISASI BERSIFAT 1 ARAH KE LOKAL SAJA**:
   - Segala bentuk sinkronisasi data/source code sifatnya **HANYA 1 ARAH KE LOKAL** (menarik/pull/import dari remote/UPDATE SYNC ke lokal).
   - **TIDAK ADA** pengunggahan atau pembalikan file dari lokal ke server VPS atau cPanel.
3. **Pengembangan & Pengujian 100% Lokal**:
   - Seluruh aktivitas penambahan fitur, perbaikan bug, dan pengujian query dilakukan di environment lokal (`d:\xampp\htdocs\inhost-web-id`, PHP lokal, dan database lokal `framasbc` di port 3307).
4. **Super Administrator Web**:
   - Username: `dp` (atau `admin`)
   - Password: `K@y010782` (**DILARANG KERAS** menggunakan/memunculkan hint `1234`)
   - Subdomain belum terdaftar: Hanya tampilkan form login khusus Super Administrator tanpa hint apa pun.

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
   - Lakukan commit dan push ke repositori GitHub: `https://github.com/hermawan-dony/inhost.FRAMAS-version` pada branch `main`.
   - Skrip otomasi siap pakai dapat dijalankan via: `powershell -ExecutionPolicy Bypass -File push_github.ps1`.

## 3. Aturan Sinkronisasi (Hanya 1 Arah ke Lokal)
1. **Sifat Sinkronisasi**:
   - Segala bentuk sinkronisasi (UPDATE SYNC / remote) sifatnya **HANYA 1 ARAH MENARIK KE LOKAL**.
   - Dilarang keras melakukan deploy/upload balik ke VPS Bekasi maupun cPanel.
2. **Deployment Dilarang**:
   - Operasi `deploy.ps1` dinonaktifkan / tidak boleh dijalankan.
   - Pengembangan difokuskan murni pada environment lokal dan repositori GitHub jika diminta pengguna.

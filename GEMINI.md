# Workspace Rules: CEISA 4.0 (inhost.web.id)

## 1. Aturan Perintah "clone!"
Ketika pengguna memberikan instruksi/perintah **`clone!`** (atau meminta backup & push ke GitHub):
1. **Ekspor Database Remote**:
   - Jalankan `mysqldump` database `ceisa0400db` dari Alibaba Cloud RDS MySQL dengan opsi integritas penuh (`--single-transaction`, `--set-gtid-purged=OFF`, `--routines`, `--triggers`, `--hex-blob`).
   - Kompresi hasil dump ke file ZIP di `database/ceisa0400db.sql.zip` dan sediakan file `.sql` lokal siap pakai di `database/ceisa0400db.sql`.
2. **Kemas & Unduh Source Code**:
   - Tarik source code aplikasi web terbaru dari server remote (`/source/public`) ke lokal `c:\dp\g-ceisa`.
   - Perbarui file riwayat proyek `PROJECT_HISTORY_CEISA4.md`.
3. **Filter Ketat (Hanya File Proyek - JANGAN Bawa Semua File di Folder)**:
   - **HANYA** file dan folder proyek web resmi yang diikutsertakan ke repositori Git (`index.php`, `material.php`, `company.php`, `it_inventory.php`, `assets/`, `erp/`, `sql_templates/`, `www/`, `xls/`, `database/ceisa0400db.sql.zip`, dokumentasi `README.md` & `PROJECT_HISTORY_CEISA4.md`).
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

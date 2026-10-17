---
description: Aturan otomasi saat user mengetik perintah "clone!"
trigger: always_on
---

# Aturan Perintah "clone!"

Ketika pengguna mengetik **`clone!`**:
1. Ekspor basis data Alibaba Cloud RDS `ceisa0400db` dan kompresi ke `database/ceisa0400db.sql.zip`.
2. Salin seluruh source code proyek web terbaru dari remote server (`/source/public`) ke `c:\dp\g-ceisa`.
3. **Filter Ketat (Hanya File Proyek - JANGAN Bawa Semua File di Folder c:\dp\g-ceisa)**:
   - File proyek yang sah: modul web utama (`*.php`), `assets/`, `erp/`, `sql_templates/`, `www/`, `xls/`, `database/ceisa0400db.sql.zip`, `PROJECT_HISTORY_CEISA4.md`, `README.md`.
   - File yang **DIKECUALIKAN**: `json_*.txt`, `json.txt`, `*.pdf`, `pdf/`, `*.exe`, `www/mysql.exe`, `scratch_*.php`, `check_*.php`, `sample_reff.php`, `apply_sql.php`, `list_tables.php`, `test_*.php`, `dp.php`, `dp/`, `SQLyogTunnel.php`, `*.bat`, `pass.txt`, `cookie.txt`, `share/`.
4. Push ke GitHub repository: `https://github.com/hermawan-dony/inhost.web.id` (branch `main`).
5. Jalankan melalui `c:\dp\g-ceisa\sync_and_push.ps1` atau eksekusi langkah setara.

# SYSTEM KNOWLEDGE BASE: CEISA 4.0 (inhost.web.id)
Dokumen ini merupakan panduan lengkap arsitektur, kredensial server, konfigurasi database, hierarki hak akses, dan tata kelola proyek untuk AI Assistant dan pengembang sistem.

---

## 1. INFRASTRUKTUR & SERVER ACUAN (VPS BEKASI / DCI CIBITUNG)

Pengembangan sistem dan acuan operasional saat ini berpusat pada server **VPS BEKASI**:

| Komponen | Nilai / Konfigurasi |
|---|---|
| **IP Publik Server** | `202.10.35.199` |
| **Port SSH** | `22` |
| **User SSH** | `root` |
| **Password SSH** | `K@y010782` |
| **Direktori Web Root** | `/var/www/inhost/` |
| **OS Server** | Ubuntu 22.04 LTS / Nginx & PHP-FPM 8.2 |
| **Database Server** | MariaDB 10.6.23 (`localhost:3306` & `202.10.35.199:3306`) |
| **Nama Database** | `bc2faktur_inhost` |
| **User Database** | `bc2faktur_root` |
| **Password Database** | `@combro#` |
| **Collation Database** | **`utf8mb4_unicode_ci`** (100% seragam untuk seluruh 95 tabel) |
| **Tuning MariaDB** | Buffer Pool: `1024M` (1 GB) \| Query Cache: `64M` \| Temp Table: `64M` \| Max Conn: `200` |
| **Whitelist Client IP** | cPanel inhost.id (`101.50.1.111`) diberi izin akses langsung ke DB VPS |

### Database Alternatif (Alibaba Cloud RDS - Secondary)
- Host: `rm-d9j4584f890612j7vro.mysql.ap-southeast-5.rds.aliyuncs.com:63306`
- User: `ceisarwuser`
- Password: `CiT8T2#h4Jd#3wer$9d36hd`
- Database: `ceisa0400db`

### Otomasi Dual-Deploy (VPS Bekasi + cPanel inhost.id)
Sistem menggunakan skrip `deploy.ps1` untuk menyinkronkan perubahan ke **KEDUA SERVER SEKALIGUS**:
```powershell
powershell -ExecutionPolicy Bypass -File d:\vd\inhost_web_app\deploy.ps1 [nama_file]
```
- **Target 1**: VPS Bekasi (`202.10.35.199:/var/www/inhost/`) via PSCP
- **Target 2**: cPanel (`inhost.id`) via FTP

Eksekusi perintah remote via SSH:
```powershell
& "C:\Program Files\PuTTY\plink.exe" -batch -hostkey "SHA256:uCOFAdVGHhaGAWzAEPhrz/ZEmNRxEamt8/89vpVENis" -P 22 -pw "K@y010782" root@202.10.35.199 "<command_bash>"
```

---

## 2. OTENTIKASI & AKUN SUPER ADMINISTRATOR

### Super Administrator Web Portal
- **Username**: `dp` (atau `admin`)
- **Password**: `K@y010782`
- **Catatan Sangat Penting**:
  - **DILARANG KERAS** menggunakan atau memunculkan hint `1234`.
  - Password hardcode `1234` telah dihapus sepenuhnya dari `index.php`.
  - Otentikasi Administrator **100% membaca database** tabel `bc_ceisa_login WHERE (role = 'superadmin' OR cmp_id = 'SYSTEM')`.
  - Super Administrator memiliki hak akses global: dokumen penuh (`['*']`) dan menu penuh (`['*']`) lintas seluruh perusahaan.

### Subdomain Belum Terdaftar
- Jika URL diakses dengan subdomain yang belum terdaftar di `bc_ceisa_company`:
  - Munculkan form login khusus Super Administrator saja.
  - Jangan tampilkan hint/bocoran password apa pun.

---

## 3. ARSITEKTUR MULTI-TENANT & HAK AKSES DOKUMEN PABEAN

Sistem melayani banyak perusahaan (*multi-tenant*) dalam satu basis kode dan basis data dengan aturan hak akses berjenjang.

### A. Role Kepabeanan Perusahaan (`company_roles`)
Setiap perusahaan memiliki satu atau lebih peran kepabeanan yang dipisahkan tanda koma:
1. **`tpb` (Tempat Penimbunan Berikat)**:
   - Preset Dokumen: `23` (Impor TPB), `25` (Pengeluaran TPB), `261` (Subkon Luar), `262` (Subkon Masuk), `27` (TPB ke TPB), `40` (Pemasukan TLDDP), `41` (Pengeluaran TLDDP).
2. **`plb` (Pusat Logistik Berikat)**:
   - Preset Dokumen: `16` (BC 1.6 Pemasukan PLB), `28` (BC 2.8 Impor PLB), `33` (BC 3.3 Ekspor PLB), `331` (BC 3.3.1 P3BET PLB), `27` (BC 2.7 Antar PLB/TPB).
3. **`ppkek` / `kek` (Kawasan Ekonomi Khusus)**:
   - Preset Dokumen: `611` (PPKEK 6.1.1), `613` (PPKEK 6.1.3), `631` (PPKEK 6.3.1), `25` (BC 2.5), `41` (BC 4.1).
4. **`pib`**: `20` (BC 2.0).
5. **`peb`**: `30` (BC 3.0).
6. **`manifest`**: Manifes kedatangan/keberangkatan sarana pengangkut.

### B. Konfigurasi Manual Dokumen Perusahaan (`allowed_docs`)
- Diatur pada menu **Kelola Perusahaan** -> **Dokumen Pabean Aktif (Allowed BC Documents)**.
- Disimpan sebagai string kode dokumen dipisah koma (contoh: `25,23,40,27,41,16,28,33,331`).
- **ATURAN MUTLAK**: Jika administrator mencentang dokumen secara eksplisit, sistem **100% menghormati dokumen tersebut** dan **DILARANG MENGHAPUS/MEMBUANG** dokumen (misalnya dokumen PLB `16, 28, 33, 331`) menggunakan `array_diff`.

### C. Hierarki Izin Dokumen Pengguna (User-Level Document Permissions)
1. Setiap pengguna (`operator`, `supervisor`, `admin` lokal) memiliki batasan dokumen spesifik pada tabel `bc_ceisa_login.allowed_docs` (contoh: user `didi` diset `["40","41"]`).
2. **Formula Izin Efektif**:
   - Jika Super Administrator: Izin adalah `['*']` (akses tak terbatas).
   - Jika pengguna biasa: Izin efektif adalah **irisan (intersection)** antara izin pengguna dan dokumen aktif perusahaan:
     `$effectiveAllowedDocs = array_intersect($userAllowedDocs, $companyAllowedDocs);`
3. **Pemisahan Variabel Sesi di [`index.php`](file:///d:/vd/inhost_web_app/index.php)**:
   - `$_SESSION['company_allowed_docs']`: Daftar dokumen aktif perusahaan.
   - `$_SESSION['user_allowed_docs']`: Daftar dokumen yang diizinkan untuk akun pengguna.
   - `$_SESSION['allowed_docs']`: Dokumen efektif hasil irisan.
   - Dilarang menimpa `$_SESSION['user_allowed_docs']` dengan data perusahaan pada setiap kali muat halaman.

### D. Fungsi Helper Global di [`koneksi.php`](file:///d:/vd/inhost_web_app/koneksi.php)
- `ceisa_parse_docs($rawDocs)`: Mem-parse format `*`, `ALL`, JSON array, atau teks koma menjadi array dokumen standar.
- `ceisa_resolve_allowed_docs($rolesStr, $allowedDocsStr)`: Menghasilkan daftar dokumen aktif perusahaan (mengutamakan setting manual `allowed_docs` atau fallback ke preset role).
- `ceisa_get_effective_allowed_docs($companyAllowedDocs)`: Menghitung dokumen efektif yang berhak diakses pengguna saat ini.

---

## 4. PETA MODUL & STRUKTUR APLIKASI

1. **[`index.php`](file:///d:/vd/inhost_web_app/index.php)**:
   - Gerbang utama aplikasi, otentikasi sesi multi-tenant, company switcher, dan router iframe menu.
2. **[`transfer.php`](file:///d:/vd/inhost_web_app/transfer.php)**:
   - Modul Data Host to Host CEISA 4.0 (menampilkan data staging `bc_ceisa_header`, `bc_ceisa_barang`, `bc_ceisa_dokumen`).
   - Filter dropdown Dokumen BC dan kueri `action=list` dibatasi ketat oleh `$effectiveAllowedDocs`.
3. **[`daftar_dokumen.php`](file:///d:/vd/inhost_web_app/daftar_dokumen.php)**:
   - Dashboard Ceisa 4.0 / Monitoring respon pabean CEISA.
   - Filter dropdown Dokumen BC dibatasi ketat oleh `$effectiveAllowedDocs`.
4. **[`editor.php`](file:///d:/vd/inhost_web_app/editor.php)**:
   - Form Editor Dokumen Pabean 100% Web Native untuk pembuatan dan revisi dokumen BC (Header, Barang, Entitas, Dokap, dsb.).
   - Tombol pilihan jenis dokumen dan dropdown selector nomor AJU difilter sesuai `$effectiveAllowedDocs`.
5. **[`company.php`](file:///d:/vd/inhost_web_app/company.php)**:
   - Modul Kelola Perusahaan: profil entitas, SKEP, kredensial CEISA 4.0 & INSW, role kepabeanan, dan centang dokumen aktif (*Allowed BC Documents*).
   - Otomatis mensinkronkan role saat dokumen tertentu (PLB / PPKEK) dicentang.
6. **[`user_management.php`](file:///d:/vd/inhost_web_app/user_management.php)**:
   - Kelola Pengguna Perusahaan: pendaftaran username, password, role (`admin`, `supervisor`, `operator`, `bc`), pembatasan menu (`allowed_menus`), dan pembatasan dokumen (`allowed_docs`).
7. **[`it_inventory.php`](file:///d:/vd/inhost_web_app/it_inventory.php)**:
   - Modul Laporan IT Inventory Bea Cukai (Laporan Pemasukan, Pengeluaran, Mutasi Bahan Baku, Barang Jadi, Mesin, Scrap).
8. **[`rekap/index.php`](file:///d:/vd/inhost_web_app/rekap/index.php)**:
   - Dashboard Rekapitulasi Dokumen Pemasukan & Pengeluaran CEISA.
9. **[`visitor.php`](file:///d:/vd/inhost_web_app/visitor.php) & [`visitor_logger.php`](file:///d:/vd/inhost_web_app/visitor_logger.php)**:
   - Dashboard & Engine Analytics Pengunjung, Pelacakan IP Unik, Subdomain, Geolokasi (Kota/Negara/ISP), dan Riwayat Akses Menu Real-Time (WIB / Asia/Jakarta).
   - Skema Database: `bc_visitor_geo_cache`, `bc_visitor_log`, `bc_visitor_hits`, `bc_visitor_menu_log` (Skrip DDL: `database/visitor_tables.sql`).
   - Hanya mencatat aktivitas pengguna yang sudah terotentikasi/login (non-login disaring).
10. **[`PROJECT_HISTORY_CEISA4.md`](file:///d:/vd/inhost_web_app/PROJECT_HISTORY_CEISA4.md)**:
   - Riwayat lengkap seluruh perbaikan bug, integrasi fitur, dan catatan teknis proyek. **Wajib diperbarui setiap ada perubahan arsitektural/fungsional baru.**

---

## 5. INSTRUKSI KERJA KETIKA ANTIGRAVITY PINDAH LINGKUNGAN / FRESH START

Jika sesi Antigravity dimulai kembali di mesin atau profil baru:
1. Baca berkas ini (`SYSTEM_KNOWLEDGE_BASE.md`) dan `GEMINI.md`.
2. Selalu gunakan **VPS Bekasi (`202.10.35.199`)** sebagai server target utama.
3. Kredensial Super Administrator selalu **`dp`** dengan password **`K@y010782`**.
4. Selalu periksa sintaks PHP (`php -l`) sebelum dan sesudah menyalin berkas ke server remote.
5. Gunakan `pscp.exe` untuk sinkronisasi berkas lokal ke `/var/www/inhost/`.
6. Selalu pertahankan integritas hak akses berjenjang (dokumen efektif = irisan user dan perusahaan; Super Admin = `*`).

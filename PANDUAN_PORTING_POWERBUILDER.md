# Panduan Porting & Crosscheck PowerBuilder (TPB-ERP) untuk AGY CLI

Dokumen ini adalah acuan resmi bagi **Antigravity CLI (AGY)** dan pengembang sistem **CEISA 4.0 (inhost.web.id)** saat melakukan porting modul, crosscheck query SQL, atau verifikasi rumus perhitungan bisnis dari aplikasi desktop PowerBuilder (TPB-ERP v2.0) ke aplikasi web native.

---

## 1. Lokasi Berkas PowerBuilder di Server VPS Jakarta
* **Direktori Master Utama**: `/var/www/inhost/tpberp/` (symlink ke `/var/www/inhost/TPBERP-SOURCE/`)
* **Clean UTF-8 Mirror**: `/var/www/inhost/tpberp_utf8/`
* **Arsip Cadangan**: `/root/tpberp/` dan `/root/TPBERP-SOURCE.zip`
* **Total Objek**: **1.053 berkas** source code asli PowerBuilder.

---

## 2. Struktur Ekstensi Berkas PowerBuilder
1. **`.srw` (Window / Form Interface)**:
   - Memuat tata letak visual, tombol perintah (`CommandButton`), tab navigasi, dropdown, dan *event triggers* (`open`, `close`, `clicked`, `itemchanged`, `ue_*`).
   - Gunakan berkas ini untuk memahami alur validasi *input*, proteksi hak akses, dan interaksi pengguna sebelum data disimpan.
2. **`.srd` (DataWindow / Query & Tata Letak Laporan)**:
   - Memuat **query SQL asli** pada atribut `retrieve="SELECT ... FROM ... WHERE ..."`.
   - Memuat daftar argumen/parameter (`arguments=(...)`), pengurutan data (`sort=`), serta kolom kalkulasi (*computed fields*).
   - Gunakan berkas ini sebagai acuan mutlak saat melakukan **crosscheck SQL** untuk memastikan tabel, nama kolom, klausa `JOIN`, dan filter `WHERE` identik dengan versi desktop.
3. **`.srf` (User Function / Rumus Logika Bisnis)**:
   - Memuat fungsi kalkulasi murni (perhitungan pajak, pembulatan, konversi kurs, penomoran urut otomatis, enkripsi sandi).
   - Gunakan berkas ini untuk mereplikasi rumus bisnis secara 1:1 ke bahasa PHP/Python.
4. **`.sra` & `.srj` (Application & Project Settings)**:
   - Inisialisasi koneksi basis data, variabel global transaksi, dan profil aplikasi.

---

## 3. Tool Bantu Ekstraksi Otomatis (`pb_helper.py`)
Karena berkas asli PowerBuilder diekspor dalam format **UTF-16LE**, sistem telah dilengkapi skrip pembantu otomatis di `/var/www/inhost/ai_agent/pb_helper.py`.

AGY CLI dapat langsung menjalankan perintah berikut di terminal:

```bash
# 1. Ekstrak Query SQL langsung dari DataWindow (.srd)
python3 /var/www/inhost/ai_agent/pb_helper.py --action sql --file nama_datawindow.srd

# 2. Ekstrak Argumen / Parameter Filter DataWindow
python3 /var/www/inhost/ai_agent/pb_helper.py --action args --file nama_datawindow.srd

# 3. Cari Objek PB berdasarkan kata kunci (nama tabel, nama kolom, nama form)
python3 /var/www/inhost/ai_agent/pb_helper.py --action search --query "nama_tabel_atau_fitur"

# 4. Baca isi Window (.srw) atau Function (.srf) dalam teks UTF-8 bersih
python3 /var/www/inhost/ai_agent/pb_helper.py --action view --file nama_window.srw
```

---

## 4. Alur Kerja Porting saat Menerima Perintah Mas Wawan

Bila Mas Wawan meminta: *"Porting modul X dari PB ke Web"* atau *"Crosscheck SQL untuk fitur Y di PB"*, ikuti langkah baku berikut:

1. **Temukan Objek yang Relevan**:
   - Jalankan pencarian via `pb_helper.py --action search --query "<keyword>"` atau cari berkas di `/var/www/inhost/tpberp/`.
2. **Ekstrak Query & Logika**:
   - Jika terkait data/laporan: Ambil query `SELECT` dari `.srd` menggunakan `pb_helper.py --action sql`.
   - Jika terkait antarmuka/alur: Baca event `open` dan event tombol `Save`/`Proses` pada berkas `.srw`.
3. **Croscek Struktur Database**:
   - Periksa apakah tabel dan kolom yang dipanggil di DataWindow sudah tersedia di database MariaDB VPS (`bc2faktur_inhost`).
   - Sesuaikan tipe data, default value, dan alias agar selaras dengan skema MySQL/MariaDB.
4. **Implementasikan ke Web Native**:
   - Tulis kode modular di direktori yang sesuai (modul pabean di root, modul ERP di `/var/www/inhost/erp/`).
   - Pastikan mengikuti aturan desain inhost: Bootstrap 4 + Tabler/Metronic, responsif, aman dari SQL Injection (gunakan PDO / MySQLi Prepared Statements), dan sertakan panduan SOP interaktif `openModuleGuide()`.
5. **Verifikasi & Laporkan**:
   - Uji eksekusi skrip atau query di server VPS.
   - Laporkan hasil porting secara ringkas dan runtut kepada Mas Wawan.

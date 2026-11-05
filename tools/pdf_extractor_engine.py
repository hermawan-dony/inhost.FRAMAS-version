#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
INHOST TOOLS - AI PDF Invoice Extractor to CEISA 4.0 Excel Engine
Ekstraksi data invoice / packing list dari berkas PDF via VPS Murah AI API,
kemudian mengonversinya langsung menjadi format resmi Excel CEISA 4.0 (21 Sheet .xlsx).
100% In-Memory / Temporary Processing, Zero Database Storage.
"""

import sys
import os
import re
import json
import subprocess
import urllib.request
import urllib.error

try:
    import openpyxl
    from openpyxl.styles import Font
    from openpyxl.utils import get_column_letter
except ImportError:
    openpyxl = None

API_KEY = os.getenv("VPS_AI_KEY", "sk-vps-wt928cidmnuj330ceo2kicb1x5jem4kc3ryo5oxw")
API_ENDPOINT = "https://my.vpsmurah.co.id/api/v1/chat/completions"
DEFAULT_MODEL = "gpt-4o-mini"

# Daftar 21 sheet resmi CEISA 4.0 berurutan
SHEET_ORDER = [
    'HEADER', 'ENTITAS', 'DOKUMEN', 'PENGANGKUT', 'KEMASAN', 'KONTAINER', 'KOMPONENBIAYA',
    'BARANG', 'BARANGTARIF', 'BARANGDOKUMEN', 'BARANGENTITAS', 'BARANGSPEKKHUSUS', 'BARANGVD',
    'BAHANBAKU', 'BAHANBAKUTARIF', 'BAHANBAKUDOKUMEN', 'PUNGUTAN', 'JAMINAN', 'BANKDEVISA',
    'VERSI', 'RESPON'
]

STRING_COLS = {
    'NOMOR AJU', 'KODE DOKUMEN', 'KODE KANTOR', 'KODE KANTOR BONGKAR', 'KODE KANTOR TUJUAN',
    'KODE JENIS TPB', 'NOMOR BC11', 'NOMOR POS', 'NOMOR SUB POS', 'NOMOR DAFTAR', 'NOMOR DAFTAR ASAL',
    'NOMOR AJU ASAL', 'KODE DOKUMEN ASAL', 'NOMOR IDENTITAS', 'NIB ENTITAS', 'NOMOR IJIN ENTITAS',
    'KODE ENTITAS', 'KODE JENIS IDENTITAS', 'NOMOR DOKUMEN', 'KODE KEMASAN', 'NOMOR KONTINER',
    'HS', 'KODE BARANG', 'KODE SATUAN', 'NPWP BILLING', 'KODE PUNGUTAN', 'KODE FASILITAS',
    'KODE TARIF', 'KODE FASILITAS TARIF', 'KODE JENIS PUNGUTAN', 'VERSI', 'KODE RESPON', 'NOMOR RESPON'
}


def normalize_number(v):
    if v is None:
        return 0.0
    if isinstance(v, (int, float)):
        return float(v)
    s = str(v).replace('Rp', '').replace('rp', '').replace('$', '').replace('EUR', '').strip()
    s = re.sub(r'[^\d,.\-+]', '', s)
    if not s:
        return 0.0
    dot_count = s.count('.')
    comma_count = s.count(',')
    if dot_count > 0 and comma_count > 0:
        s = s.replace('.', '').replace(',', '.')
    elif comma_count > 0:
        parts = s.split(',')
        if len(parts) > 2:
            s = ''.join(parts)
        else:
            if len(parts[1]) == 3:
                s = parts[0] + parts[1]
            else:
                s = parts[0] + '.' + parts[1]
    elif dot_count > 0:
        parts = s.split('.')
        if len(parts) > 2:
            s = ''.join(parts)
    try:
        return float(s)
    except ValueError:
        return 0.0


def extract_text_from_pdf(pdf_path):
    """
    Mengekstrak teks mentah dari berkas PDF:
    1. Coba pdftotext (Poppler) untuk PDF teks digital native.
    2. Coba library pypdf / PyPDF2 jika pdftotext tidak menghasilkan teks.
    3. Jika teks kosong / < 25 karakter, otomatis eksekusi OCR cerdas (pdftoppm + tesseract).
    Mengembalikan tuple: (extracted_text, is_ocr_used)
    """
    # 1. Coba gunakan command pdftotext (Poppler)
    try:
        res = subprocess.run(
            ['pdftotext', '-layout', pdf_path, '-'],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=25
        )
        if res.returncode == 0:
            txt = res.stdout.decode('utf-8', errors='ignore').strip()
            # Hanya terima jika teks memuat konten bermakna (> 25 karakter)
            if len(txt) > 25:
                return txt, False
    except Exception:
        pass

    # 2. Coba gunakan library pypdf jika terpasang
    try:
        from pypdf import PdfReader
        reader = PdfReader(pdf_path)
        pages_txt = []
        for p in reader.pages:
            t = p.extract_text()
            if t:
                pages_txt.append(t)
        joined = "\n".join(pages_txt).strip()
        if len(joined) > 25:
            return joined, False
    except Exception:
        pass

    # 3. Fallback jika PyPDF2 terpasang
    try:
        import PyPDF2
        reader = PyPDF2.PdfReader(pdf_path)
        pages_txt = []
        for p in reader.pages:
            t = p.extract_text()
            if t:
                pages_txt.append(t)
        joined = "\n".join(pages_txt).strip()
        if len(joined) > 25:
            return joined, False
    except Exception:
        pass

    # 4. FITUR OCR OTOMATIS UNTUK BERKAS PINDAIAN / SCAN (IMAGE-ONLY PDF)
    try:
        import tempfile
        import glob

        # Gunakan temporary directory terisolasi untuk hasil render halaman gambar
        with tempfile.TemporaryDirectory(prefix="inhost_ocr_") as tmp_ocr_dir:
            out_prefix = os.path.join(tmp_ocr_dir, "page")
            # Konversi halaman PDF ke format PNG (maksimal 5 halaman pertama, 200 DPI optimal untuk teks kecil & invoice)
            sub_ppm = subprocess.run(
                ['pdftoppm', '-png', '-r', '200', '-f', '1', '-l', '5', pdf_path, out_prefix],
                stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=40
            )

            # Temukan seluruh berkas PNG yang berhasil diekspor
            png_files = sorted(glob.glob(os.path.join(tmp_ocr_dir, "*.png")))
            if png_files:
                ocr_pages = []
                for fpath in png_files:
                    # Jalankan tesseract OCR (bilingual Indonesia + English)
                    res_ocr = subprocess.run(
                        ['tesseract', fpath, 'stdout', '-l', 'eng+ind'],
                        stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=35
                    )
                    # Fallback ke english jika bilingual gagal
                    if res_ocr.returncode != 0:
                        res_ocr = subprocess.run(
                            ['tesseract', fpath, 'stdout', '-l', 'eng'],
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=35
                        )
                    
                    if res_ocr.returncode == 0:
                        page_txt = res_ocr.stdout.decode('utf-8', errors='ignore').strip()
                        if page_txt:
                            ocr_pages.append(page_txt)

                if ocr_pages:
                    joined_ocr = "\n\n--- HALAMAN BERIKUTNYA ---\n\n".join(ocr_pages).strip()
                    if len(joined_ocr) > 10:
                        return joined_ocr, True
    except Exception as exc:
        sys.stderr.write(f"OCR Pipeline Error: {str(exc)}\n")

    return "", False


def call_ai_extract(text_content, model=None):
    """Mengirim teks invoice ke VPS Murah AI API untuk mengekstrak tabel invoice ke format JSON array."""
    if not text_content:
        return [], {}

    chosen_model = model if model else DEFAULT_MODEL

    # Potong teks maksimal 16.000 karakter agar cepat & efisien
    trimmed_text = text_content[:16000]

    system_instruction = (
        "Kamu adalah AI Ekstraktor Dokumen Kepabeanan dan Invoice/Packing List yang sangat akurat. "
        "Tugasmu mengekstrak seluruh rincian barang/item dari teks dokumen ke dalam format JSON array valid. "
        "HANYA berikan output JSON array murni tanpa pembuka/penutup markdown seperti ```json atau ```."
    )

    user_prompt = f"""
Ekstrak daftar barang/item transaksi dari teks dokumen invoice berikut.
Setiap elemen di dalam array JSON wajib memiliki field persis seperti ini:
- "kode_barang": string (kode part, kode barang, item number, atau generate jika tidak ada)
- "nama_barang": string (nama / uraian lengkap barang)
- "hs_code": string (pos tarif / HS Code 8-10 digit bila ditemukan, atau kosongkan "")
- "qty": number (jumlah barang numerik, contoh: 120 atau 5150.5)
- "satuan": string (kode satuan internasional standar CEISA seperti KGM, PCS, SET, MTR, BOX, ROLL, CAN, TUBE, dll)
- "harga": number (harga satuan / unit price numerik)
- "total": number (total harga item = qty * harga)

Teks Dokumen:
{trimmed_text}
"""

    payload = {
        "model": chosen_model,
        "messages": [
            {"role": "system", "content": system_instruction},
            {"role": "user", "content": user_prompt}
        ],
        "temperature": 0.1
    }

    req_data = json.dumps(payload).encode('utf-8')
    req = urllib.request.Request(
        API_ENDPOINT,
        data=req_data,
        headers={
            "Authorization": f"Bearer {API_KEY}",
            "Content-Type": "application/json",
            "User-Agent": "INHOST-Invoice-Extractor/1.0"
        }
    )

    try:
        with urllib.request.urlopen(req, timeout=40) as response:
            res_body = response.read().decode('utf-8')
            resp_json = json.loads(res_body)
            usage_data = resp_json.get('usage', {})
            raw_ai = resp_json.get('choices', [{}])[0].get('message', {}).get('content', '').strip()
            
            # Bersihkan bila ada markdown code blocks
            raw_ai = re.sub(r'^```(?:json)?\s*', '', raw_ai, flags=re.MULTILINE)
            raw_ai = re.sub(r'```\s*$', '', raw_ai, flags=re.MULTILINE).strip()
            
            try:
                parsed = json.loads(raw_ai)
            except Exception:
                parsed = []

            if isinstance(parsed, dict):
                parsed = parsed.get('items', []) or parsed.get('data', []) or parsed.get('barang', [])
            
            if isinstance(parsed, list) and len(parsed) > 0:
                clean_items = []
                for it in parsed:
                    q = normalize_number(it.get('qty', 0))
                    h = normalize_number(it.get('harga', 0))
                    t = normalize_number(it.get('total', 0))
                    if t == 0 and q > 0 and h > 0:
                        t = round(q * h, 4)
                    clean_items.append({
                        "kode_barang": str(it.get('kode_barang', '') or '').strip(),
                        "nama_barang": str(it.get('nama_barang', '') or '').strip(),
                        "hs_code": str(it.get('hs_code', '') or '').replace('.', '').replace(' ', '').strip(),
                        "qty": q,
                        "satuan": str(it.get('satuan', 'PCS') or 'PCS').upper().strip()[:10],
                        "harga": h,
                        "total": t
                    })
                return clean_items, usage_data
            
            return [], usage_data
    except urllib.error.HTTPError as he:
        try:
            err_body = he.read().decode('utf-8')
            err_json = json.loads(err_body)
            return [], err_json.get('usage', {})
        except Exception:
            return [], {}
    except Exception as e:
        sys.stderr.write(f"AI API error: {str(e)}\n")
        return [], {}


def generate_ceisa4_workbook(items, output_path, kode_dokumen='23', nomor_aju='', valuta='USD'):
    """
    Membuat berkas Excel khusus Sheet BARANG standar resmi CEISA 4.0
    dari daftar barang hasil ekstraksi PDF.
    """
    if not openpyxl:
        raise RuntimeError("Library openpyxl belum terinstal.")

    # Muat struktur resmi CEISA 4.0 untuk mengambil urutan kolom Sheet BARANG
    structure_json = os.path.join(os.path.dirname(__file__), '..', 'xls_structure_ceisa40.json')
    if not os.path.exists(structure_json):
        structure_json = os.path.join(os.path.dirname(__file__), 'xls_structure_ceisa40.json')

    ceisa_structure = {}
    if os.path.exists(structure_json):
        with open(structure_json, 'r', encoding='utf-8') as f:
            ceisa_structure = json.load(f)

    # Kolom resmi Sheet BARANG
    barang_cols = ceisa_structure.get('BARANG', [
        "NOMOR AJU", "SERI BARANG", "HS", "KODE BARANG", "URAIAN", "MEREK", "TIPE", "UKURAN",
        "SPESIFIKASI LAIN", "KODE SATUAN", "JUMLAH SATUAN", "KODE KEMASAN", "JUMLAH KEMASAN",
        "KODE DOKUMEN ASAL", "KODE KANTOR ASAL", "NOMOR DAFTAR ASAL", "TANGGAL DAFTAR ASAL",
        "NOMOR AJU ASAL", "SERI BARANG ASAL", "NETTO", "BRUTO", "VOLUME", "SALDO AWAL",
        "SALDO AKHIR", "JUMLAH REALISASI", "CIF", "CIF RUPIAH", "NDPBM", "FOB", "ASURANSI",
        "FREIGHT", "NILAI TAMBAH", "DISKON", "HARGA PENYERAHAN", "HARGA PEROLEHAN",
        "HARGA SATUAN", "HARGA EKSPOR", "HARGA PATOKAN", "NILAI BARANG", "NILAI JASA",
        "NILAI DANA SAWIT", "NILAI DEVISA", "PERSENTASE IMPOR", "KODE ASAL BARANG",
        "KODE DAERAH ASAL", "KODE GUNA BARANG", "KODE JENIS NILAI", "JATUH TEMPO ROYALTI",
        "KODE KATEGORI BARANG", "KODE KONDISI BARANG", "KODE NEGARA ASAL", "KODE PERHITUNGAN",
        "PERNYATAAN LARTAS", "FLAG 4 TAHUN", "SERI IZIN", "TAHUN PEMBUATAN", "KAPASITAS SILINDER",
        "KODE BKC", "KODE KOMODITI BKC", "KODE SUB KOMODITI BKC", "FLAG TIS", "ISI PER KEMASAN"
    ])

    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = 'BARANG'

    header_font = Font(name='Calibri', size=11, bold=True, color='000000')
    data_font = Font(name='Calibri', size=11, bold=False, color='000000')

    # Baris 1: Header kolom resmi BARANG
    for c_idx, c_name in enumerate(barang_cols, start=1):
        cell = ws.cell(row=1, column=c_idx, value=c_name)
        cell.font = header_font

    # Siapkan baris data barang
    for idx, it in enumerate(items, start=1):
        hs = str(it.get('hs_code', '') or '').replace('.', '').replace(' ', '').strip()
        qty = it.get('qty', 0)
        uom = it.get('satuan', 'PCS')
        price = it.get('harga', 0)
        tot = it.get('total', 0)
        kode_b = it.get('kode_barang', '') or f"ITM-{idx:03d}"
        nama_b = it.get('nama_barang', '') or f"Item Barang #{idx}"

        row_dict = {
            'NOMOR AJU': nomor_aju,
            'SERI BARANG': idx,
            'HS': hs,
            'KODE BARANG': kode_b,
            'URAIAN': nama_b,
            'KODE SATUAN': uom,
            'JUMLAH SATUAN': qty,
            'HARGA SATUAN': price,
            'FOB': tot,
            'CIF': tot,
            'NILAI BARANG': tot,
            'HARGA PENYERAHAN': tot
        }

        row_num = idx + 1
        for c_idx, c_name in enumerate(barang_cols, start=1):
            val = row_dict.get(c_name, '')
            cell = ws.cell(row=row_num, column=c_idx)
            cell.font = data_font

            if c_name in STRING_COLS or (isinstance(val, str) and (val.startswith('0') or '-' in val or '/' in val)):
                cell.number_format = '@'
                cell.value = str(val) if val is not None else ''
            else:
                if isinstance(val, (int, float)):
                    cell.value = val
                else:
                    s_val = str(val).strip() if val is not None else ''
                    if s_val == '':
                        cell.value = None
                    else:
                        try:
                            if '.' in s_val:
                                cell.value = float(s_val)
                            else:
                                cell.value = int(s_val)
                        except ValueError:
                            cell.value = s_val

    # Auto-fit lebar kolom
    for c_idx, c_name in enumerate(barang_cols, start=1):
        col_letter = get_column_letter(c_idx)
        max_len = len(str(c_name))
        for r in range(2, min(len(items) + 2, 25)):
            cv = ws.cell(row=r, column=c_idx).value
            if cv is not None:
                max_len = max(max_len, len(str(cv)[:35]))
        ws.column_dimensions[col_letter].width = max(12, min(max_len + 3, 40))

    os.makedirs(os.path.dirname(os.path.abspath(output_path)), exist_ok=True)
    wb.save(output_path)
    return True


def main():
    """
    Mode Eksekusi CLI:
    1) Ekstrak PDF & Generate Excel:
       python pdf_extractor_engine.py extract <input_pdf> <output_xlsx> [kode_dokumen] [nomor_aju]
    2) Generate Excel dari JSON item (setelah diedit pengguna):
       python pdf_extractor_engine.py generate <input_items_json> <output_xlsx> [kode_dokumen] [nomor_aju]
    """
    if len(sys.argv) < 4:
        print(json.dumps({
            "status": "ERROR",
            "message": "Penggunaan: python pdf_extractor_engine.py [extract|generate] <input> <output_xlsx> [kode_dok] [no_aju]"
        }))
        sys.exit(1)

    cmd = sys.argv[1].lower()
    input_file = sys.argv[2]
    output_xlsx = sys.argv[3]
    kode_dok = sys.argv[4] if len(sys.argv) > 4 else '23'
    no_aju = sys.argv[5] if len(sys.argv) > 5 else ''
    model_ai = sys.argv[6] if len(sys.argv) > 6 and sys.argv[6] else DEFAULT_MODEL

    if not os.path.exists(input_file):
        print(json.dumps({"status": "ERROR", "message": f"Berkas masukan {input_file} tidak ditemukan."}))
        sys.exit(1)

    try:
        if cmd == 'extract':
            # 1. Ekstrak teks dari PDF (otomatis OCR jika hasil scan)
            extracted_text, is_ocr = extract_text_from_pdf(input_file)
            if not extracted_text:
                print(json.dumps({
                    "status": "ERROR",
                    "message": "Gagal membaca teks dari PDF. Berkas mungkin terproteksi sandi atau hasil scan terlalu buram untuk di-OCR."
                }))
                sys.exit(0)

            # 2. Panggil AI untuk parsing item invoice
            items, usage_stats = call_ai_extract(extracted_text, model=model_ai)
            if not items:
                print(json.dumps({
                    "status": "ERROR",
                    "message": "AI tidak berhasil mendeteksi item barang dalam dokumen ini.",
                    "model_used": model_ai,
                    "is_ocr": is_ocr,
                    "usage": usage_stats,
                    "raw_text": extracted_text[:500]
                }))
                sys.exit(0)

            # 3. Buat Excel CEISA 4.0
            generate_ceisa4_workbook(items, output_xlsx, kode_dokumen=kode_dok, nomor_aju=no_aju)

            print(json.dumps({
                "status": "SUCCESS",
                "total_items": len(items),
                "items": items,
                "model_used": model_ai,
                "is_ocr": is_ocr,
                "usage": usage_stats,
                "output_file": output_xlsx,
                "file_size": os.path.getsize(output_xlsx)
            }))

        elif cmd == 'generate':
            # Baca data items dari json file
            with open(input_file, 'r', encoding='utf-8') as f:
                items = json.load(f)
            
            generate_ceisa4_workbook(items, output_xlsx, kode_dokumen=kode_dok, nomor_aju=no_aju)
            print(json.dumps({
                "status": "SUCCESS",
                "total_items": len(items),
                "output_file": output_xlsx,
                "file_size": os.path.getsize(output_xlsx)
            }))

    except Exception as exc:
        print(json.dumps({
            "status": "ERROR",
            "message": f"Terjadi kesalahan saat memproses ekstraksi: {str(exc)}"
        }))
        sys.exit(1)


if __name__ == '__main__':
    main()

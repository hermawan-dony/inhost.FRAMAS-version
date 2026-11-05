#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
INHOST TOOLS - CEISA 4.0 Excel Converter Engine (Hybrid Ultra-Resilient Edition)
Konversi Dokumen BC Format Reprint/Legacy (XLS/XLSX) maupun Format CEISA 4.0 Existing menjadi
Format Standar Resmi Excel CEISA 4.0 (21 Sheet XLSX).
Format persis 100% dengan unduhan resmi Portal CEISA 4.0 (tanpa warna background header, font Calibri standar).
100% In-Memory Processing, Zero Database Storage, Zero Trace Guarantee.
"""

import sys
import os
import re
import math
import json
from datetime import datetime

try:
    import xlrd
except ImportError:
    xlrd = None

try:
    import openpyxl
    from openpyxl.styles import Font
    from openpyxl.utils import get_column_letter
except ImportError:
    openpyxl = None


def normalize_date(val, wb_datemode=0):
    if val is None or val == '':
        return ''
    if isinstance(val, (int, float)):
        try:
            if xlrd:
                date_tuple = xlrd.xldate_as_tuple(val, wb_datemode)
                return f"{date_tuple[0]:04d}-{date_tuple[1]:02d}-{date_tuple[2]:02d}"
        except Exception:
            pass
    if isinstance(val, datetime):
        return val.strftime('%Y-%m-%d')
    s = str(val).strip()
    if not s:
        return ''
    # Match "26 Oct 2026" or "26-Oct-2026"
    m = re.match(r'^(\d{1,2})[\s\-_]+([A-Za-z]{3,4})[\s\-_]+(\d{4})', s)
    if m:
        day, mon_str, year = m.groups()
        months = {
            'jan': 1, 'feb': 2, 'mar': 3, 'apr': 4, 'may': 5, 'jun': 6,
            'jul': 7, 'aug': 8, 'sep': 9, 'oct': 10, 'nov': 11, 'dec': 12,
            'agt': 8, 'okt': 10, 'des': 12, 'mei': 5
        }
        mon = months.get(mon_str.lower()[:3], 1)
        return f"{int(year):04d}-{mon:02d}-{int(day):02d}"
    # Match yyyy-mm-dd
    m2 = re.match(r'^(\d{4})[-/](\d{1,2})[-/](\d{1,2})', s)
    if m2:
        y, m, d = m2.groups()
        return f"{int(y):04d}-{int(m):02d}-{int(d):02d}"
    # Match dd-mm-yyyy or dd/mm/yyyy
    m3 = re.match(r'^(\d{1,2})[-/](\d{1,2})[-/](\d{4})', s)
    if m3:
        d, m, y = m3.groups()
        return f"{int(y):04d}-{int(m):02d}-{int(d):02d}"
    return s


def clean_val(val):
    if val is None:
        return ''
    if isinstance(val, float):
        if val.is_integer():
            return int(val)
        return val
    s = str(val).strip()
    return s


def to_float(val, default=0.0):
    if val is None or val == '':
        return default
    try:
        return float(val)
    except (ValueError, TypeError):
        return default


def format_npwp_ceisa40(npwp_str):
    """
    Format NPWP untuk CEISA 4.0:
    NPWP 15 digit -> tambahkan '0' di depan (16 digit) + '000000' (NITKU cabang) = 22 digit
    NPWP 16 digit -> tambahkan '000000' = 22 digit
    Jika sudah >= 22 digit, kembalikan apa adanya.
    """
    digits = re.sub(r'\D', '', str(npwp_str or ''))
    if len(digits) == 15:
        return '0' + digits + '000000'
    elif len(digits) == 16:
        return digits + '000000'
    return digits if digits else clean_val(npwp_str)


def find_field(d, aliases, default=''):
    """
    Mencari nilai dalam dictionary baris dengan multi-alias toleran (case & underscore-insensitive).
    """
    if not isinstance(d, dict):
        return default
    # 1. Cek case-exact
    for a in aliases:
        if a in d and d[a] is not None and str(d[a]).strip() != '':
            return d[a]
    # 2. Cek case-insensitive & underscore-insensitive
    lower_dict = {str(k).lower().strip().replace('_', ' '): v for k, v in d.items()}
    for a in aliases:
        norm_a = a.lower().strip().replace('_', ' ')
        if norm_a in lower_dict and lower_dict[norm_a] is not None and str(lower_dict[norm_a]).strip() != '':
            return lower_dict[norm_a]
    return default


def load_input_file(filepath):
    """Membaca file input .xls atau .xlsx dan mengembalikan dict of sheet -> list of row dicts"""
    sheets_data = {}
    ext = os.path.splitext(filepath)[1].lower()

    if ext == '.xls':
        if not xlrd:
            raise RuntimeError("Library xlrd belum terinstal untuk membaca berkas .xls")
        wb = xlrd.open_workbook(filepath)
        for sname in wb.sheet_names():
            sh = wb.sheet_by_name(sname)
            if sh.nrows == 0:
                sheets_data[sname] = []
                continue
            headers = [str(sh.cell_value(0, c)).strip() for c in range(sh.ncols)]
            rows = []
            for r in range(1, sh.nrows):
                row_dict = {}
                has_data = False
                for c in range(sh.ncols):
                    h = headers[c]
                    if not h:
                        continue
                    v = sh.cell_value(r, c)
                    if v != '' and v is not None:
                        has_data = True
                    if sh.cell_type(r, c) == xlrd.XL_CELL_DATE:
                        v = normalize_date(v, wb.datemode)
                    elif isinstance(v, float) and v.is_integer():
                        v = int(v)
                    row_dict[h] = v
                if has_data:
                    rows.append(row_dict)
            sheets_data[sname] = rows
    else:
        if not openpyxl:
            raise RuntimeError("Library openpyxl belum terinstal untuk membaca berkas .xlsx")
        wb = openpyxl.load_workbook(filepath, data_only=True)
        for sname in wb.sheetnames:
            sh = wb[sname]
            if sh.max_row is None or sh.max_row < 1:
                sheets_data[sname] = []
                continue
            headers = [str(cell.value).strip() if cell.value is not None else '' for cell in sh[1]]
            rows = []
            for row in sh.iter_rows(min_row=2, values_only=True):
                row_dict = {}
                has_data = False
                for c, v in enumerate(row):
                    if c < len(headers):
                        h = headers[c]
                        if not h:
                            continue
                        if v != '' and v is not None:
                            has_data = True
                        if isinstance(v, datetime):
                            v = v.strftime('%Y-%m-%d')
                        elif isinstance(v, float) and v.is_integer():
                            v = int(v)
                        row_dict[h] = v
                if has_data:
                    rows.append(row_dict)
            sheets_data[sname] = rows

    return sheets_data


def get_case_insensitive_sheet(sheets_dict, name):
    """Mencari sheet dengan nama fleksibel (mengabaikan spasi, garis bawah, huruf kapital)."""
    norm_target = re.sub(r'[^a-zA-Z0-9]', '', name).lower()
    for k in sheets_dict:
        norm_k = re.sub(r'[^a-zA-Z0-9]', '', k).lower()
        if norm_k == norm_target:
            return sheets_dict[k]
    return []


def convert_reprint_to_ceisa4(input_path, output_path, structure_json_path=None):
    if not openpyxl:
        raise RuntimeError("Library openpyxl belum terinstal untuk menghasilkan berkas .xlsx")

    # Muat struktur resmi CEISA 4.0
    ceisa_structure = {}
    if structure_json_path and os.path.exists(structure_json_path):
        with open(structure_json_path, 'r', encoding='utf-8') as f:
            ceisa_structure = json.load(f)
    else:
        default_json = os.path.join(os.path.dirname(__file__), '..', 'xls_structure_ceisa40.json')
        if os.path.exists(default_json):
            with open(default_json, 'r', encoding='utf-8') as f:
                ceisa_structure = json.load(f)

    raw_sheets = load_input_file(input_path)

    # Ambil sheet sumber dengan pencocokan nama fleksibel
    src_header = get_case_insensitive_sheet(raw_sheets, 'Header')
    src_entitas = get_case_insensitive_sheet(raw_sheets, 'Entitas')
    src_barang = get_case_insensitive_sheet(raw_sheets, 'Barang')
    src_btarif = get_case_insensitive_sheet(raw_sheets, 'BarangTarif')
    src_bdok = get_case_insensitive_sheet(raw_sheets, 'BarangDokumen')
    src_bb = get_case_insensitive_sheet(raw_sheets, 'BahanBaku')
    src_bbtarif = get_case_insensitive_sheet(raw_sheets, 'BahanBakuTarif')
    src_bbdok = get_case_insensitive_sheet(raw_sheets, 'BahanBakuDokumen')
    src_dokumen = get_case_insensitive_sheet(raw_sheets, 'Dokumen')
    src_kemasan = get_case_insensitive_sheet(raw_sheets, 'Kemasan')
    src_kontainer = get_case_insensitive_sheet(raw_sheets, 'Kontainer')
    src_pungutan = get_case_insensitive_sheet(raw_sheets, 'Pungutan')
    src_billing = get_case_insensitive_sheet(raw_sheets, 'Billing')
    src_respon = get_case_insensitive_sheet(raw_sheets, 'Respon')

    hdr = src_header[0] if len(src_header) > 0 else {}

    nomor_aju = str(find_field(hdr, ['NOMOR AJU', 'NOMOR_AJU', 'NO AJU', 'NOMOR PENGAJUAN'])).strip()
    kode_dokumen = str(find_field(hdr, ['KODE DOKUMEN PABEAN', 'KODE DOKUMEN', 'KODE_DOKUMEN', 'JENIS DOKUMEN'])).strip() or '25'
    perusahaan = str(find_field(hdr, ['PERUSAHAAN', 'NAMA PENGUSAHA', 'NAMA_PENGUSAHA'])).strip()

    is_bc25 = (kode_dokumen == '25')

    # Total Harga Penyerahan & Nilai Pabean
    hdr_harga_penyerahan = to_float(find_field(hdr, ['HARGA PENYERAHAN', 'HARGA_PENYERAHAN', 'NILAI PENYERAHAN']))
    # Jika di header kosong/0, hitung otomatis dari sheet Barang
    if hdr_harga_penyerahan == 0 and src_barang:
        hdr_harga_penyerahan = sum(to_float(find_field(b, ['HARGA PENYERAHAN', 'HARGA_PENYERAHAN'])) for b in src_barang)

    hdr_cif = to_float(find_field(hdr, ['CIF', 'NILAI CIF', 'CIF RUPIAH']))
    if hdr_cif == 0:
        if src_barang:
            hdr_cif = sum(to_float(find_field(b, ['CIF', 'NILAI CIF'])) for b in src_barang)
        if hdr_cif == 0 and src_bb:
            hdr_cif = sum(to_float(find_field(bb, ['CIF', 'NILAI CIF'])) for bb in src_bb)

    hdr_ndpbm = to_float(find_field(hdr, ['NDPBM', 'KURS NDPBM', 'KURS']))
    if hdr_ndpbm == 0:
        if src_barang:
            for b in src_barang:
                v = to_float(find_field(b, ['NDPBM', 'KURS']))
                if v > 0:
                    hdr_ndpbm = v
                    break
        if hdr_ndpbm == 0 and src_bb:
            for bb in src_bb:
                v = to_float(find_field(bb, ['NDPBM', 'KURS']))
                if v > 0:
                    hdr_ndpbm = v
                    break

    hdr_bruto = to_float(find_field(hdr, ['BRUTO', 'BERAT KOTOR']))
    if hdr_bruto == 0 and src_barang:
        hdr_bruto = sum(to_float(find_field(b, ['BRUTO', 'BERAT KOTOR'])) for b in src_barang)

    hdr_netto = to_float(find_field(hdr, ['NETTO', 'BERAT BERSIH']))
    if hdr_netto == 0 and src_barang:
        hdr_netto = sum(to_float(find_field(b, ['NETTO', 'BERAT BERSIH'])) for b in src_barang)

    # 1. GENERATE SHEET: HEADER
    dpp = hdr_harga_penyerahan if is_bc25 else 0.0
    tarif_ppn_hdr = 11.0 if (is_bc25 and dpp > 0) else 0.0
    ppn_hdr = round(dpp * (tarif_ppn_hdr / 100.0), 2) if is_bc25 else 0.0

    row_header = {
        'NOMOR AJU': nomor_aju,
        'KODE DOKUMEN': kode_dokumen,
        'KODE KANTOR': clean_val(find_field(hdr, ['KPPBC', 'KODE KANTOR', 'KODE_KANTOR'])),
        'KODE KANTOR BONGKAR': clean_val(find_field(hdr, ['KODE KANTOR BONGKAR', 'KANTOR BONGKAR'])),
        'KODE KANTOR TUJUAN': clean_val(find_field(hdr, ['KODE KANTOR TUJUAN', 'KANTOR TUJUAN'])) or '-',
        'KODE JENIS TPB': clean_val(find_field(hdr, ['KODE JENIS TPB', 'JENIS TPB'])),
        'KODE CARA DAGANG': clean_val(find_field(hdr, ['KODE CARA DAGANG', 'CARA DAGANG'])),
        'KODE CARA BAYAR': clean_val(find_field(hdr, ['KODE CARA BAYAR', 'CARA BAYAR'])) or ('10' if is_bc25 else ''),
        'KODE LOKASI BAYAR': clean_val(find_field(hdr, ['KODE LOKASI BAYAR', 'LOKASI BAYAR'])),
        'KODE DAERAH ASAL': clean_val(find_field(hdr, ['KODE DAERAH ASAL', 'DAERAH ASAL'])),
        'KODE GUDANG ASAL': clean_val(find_field(hdr, ['KODE GUDANG ASAL', 'GUDANG ASAL'])),
        'KODE GUDANG TUJUAN': clean_val(find_field(hdr, ['KODE GUDANG TUJUAN', 'GUDANG TUJUAN'])),
        'KODE NEGARA TUJUAN': clean_val(find_field(hdr, ['KODE NEGARA TUJUAN', 'NEGARA TUJUAN'])),
        'KODE TUTUP PU': clean_val(find_field(hdr, ['KODE TUTUP PU', 'TUTUP PU'])),
        'NOMOR BC11': clean_val(find_field(hdr, ['NOMOR BC11', 'BC11'])),
        'TANGGAL BC11': normalize_date(find_field(hdr, ['TANGGAL BC11', 'TGL BC11'])),
        'NOMOR POS': clean_val(find_field(hdr, ['POS BC11', 'NOMOR POS', 'POS'])),
        'NOMOR SUB POS': clean_val(find_field(hdr, ['SUBPOS BC11', 'NOMOR SUB POS', 'SUB POS'])),
        'KODE PELABUHAN BONGKAR': clean_val(find_field(hdr, ['KODE PEL BONGKAR', 'KODE PELABUHAN BONGKAR', 'PEL BONGKAR'])),
        'KODE PELABUHAN MUAT': clean_val(find_field(hdr, ['KODE PEL MUAT', 'KODE PELABUHAN MUAT', 'PEL MUAT'])),
        'KODE PELABUHAN TRANSIT': clean_val(find_field(hdr, ['KODE PEL TRANSIT', 'KODE PELABUHAN TRANSIT'])),
        'KODE PELABUHAN TUJUAN': clean_val(find_field(hdr, ['KODE PEL TUJUAN', 'KODE PELABUHAN TUJUAN'])),
        'KODE TPS': clean_val(find_field(hdr, ['KODE TPS', 'TPS'])),
        'TANGGAL BERANGKAT': normalize_date(find_field(hdr, ['TANGGAL BERANGKAT', 'TGL BERANGKAT'])),
        'TANGGAL TIBA': normalize_date(find_field(hdr, ['TANGGAL TIBA', 'TGL TIBA'])),
        'ASURANSI': to_float(find_field(hdr, ['ASURANSI', 'NILAI ASURANSI'])),
        'FREIGHT': to_float(find_field(hdr, ['FREIGHT', 'NILAI FREIGHT'])),
        'FOB': to_float(find_field(hdr, ['FOB', 'NILAI FOB'])),
        'BIAYA TAMBAHAN': to_float(find_field(hdr, ['BIAYA TAMBAHAN', 'BIAYA_TAMBAHAN'])),
        'BIAYA PENGURANG': to_float(find_field(hdr, ['BIAYA PENGURANG', 'BIAYA_PENGURANG'])),
        'VD': to_float(find_field(hdr, ['VD', 'VOLUNTARY DECLARATION'])),
        'NILAI BARANG': to_float(find_field(hdr, ['NILAI BARANG', 'NILAI_BARANG'])),
        'NILAI JASA': to_float(find_field(hdr, ['NILAI JASA', 'NILAI_JASA'])),
        'UANG MUKA': to_float(find_field(hdr, ['UANG MUKA', 'UANG_MUKA'])),
        'CIF': hdr_cif,
        'HARGA_PENYERAHAN': hdr_harga_penyerahan,
        'DASAR PENGENAAN PAJAK': dpp,
        'TARIF PPN PAJAK': tarif_ppn_hdr,
        'PPN PAJAK': ppn_hdr,
        'PPNBM PAJAK': 0.0,
        'NDPBM': hdr_ndpbm,
        'BRUTO': hdr_bruto,
        'NETTO': hdr_netto,
        'VOLUME': to_float(find_field(hdr, ['VOLUME', 'NILAI VOLUME'])),
        'KOTA PERNYATAAN': clean_val(find_field(hdr, ['KOTA TTD', 'KOTA PERNYATAAN', 'KOTA'])),
        'TANGGAL PERNYATAAN': normalize_date(find_field(hdr, ['TANGGAL TTD', 'TANGGAL PERNYATAAN', 'TGL PERNYATAAN'])) or datetime.now().strftime('%Y-%m-%d'),
        'NAMA PERNYATAAN': clean_val(find_field(hdr, ['NAMA TTD', 'NAMA PERNYATAAN', 'NAMA PEJABAT'])),
        'JABATAN PERNYATAAN': clean_val(find_field(hdr, ['JABATAN TTD', 'JABATAN PERNYATAAN', 'JABATAN'])),
        'KODE VALUTA': clean_val(find_field(hdr, ['KODE VALUTA', 'VALUTA', 'MATA UANG'])) or ('IDR' if is_bc25 and not find_field(hdr, ['KODE VALUTA', 'VALUTA']) else 'USD'),
        'KODE INCOTERM': clean_val(find_field(hdr, ['KODE INCOTERM', 'INCOTERM'])),
        'NOMOR DAFTAR': clean_val(find_field(hdr, ['NOMOR DAFTAR', 'NO DAFTAR'])),
        'TANGGAL DAFTAR': normalize_date(find_field(hdr, ['TANGGAL DAFTAR', 'TGL DAFTAR'])),
        'KODE TUJUAN PEMASUKAN': clean_val(find_field(hdr, ['KODE TUJUAN PEMASUKAN', 'TUJUAN PEMASUKAN'])),
        'KODE TUJUAN PENGIRIMAN': clean_val(find_field(hdr, ['KODE TUJUAN PENGIRIMAN', 'TUJUAN PENGIRIMAN'])) or ('1' if is_bc25 else ''),
        'KODE TUJUAN TPB': clean_val(find_field(hdr, ['KODE TUJUAN TPB', 'TUJUAN TPB']))
    }

    # Format identitas jenis 6 (NPWP 16/22 digit)
    def make_entitas_id(raw_id, raw_jenis_id):
        raw_str = clean_val(raw_id)
        formatted_id = format_npwp_ceisa40(raw_str)
        if len(formatted_id) == 22 or len(re.sub(r'\D', '', raw_str)) in (15, 16):
            return formatted_id, '6'
        return raw_str, clean_val(raw_jenis_id) or '1'

    def format_api_code(raw_api):
        s = clean_val(raw_api)
        if not s:
            return '02'
        if s in ('1', '2', 1, 2):
            return '02'
        if s.isdigit():
            return f"{int(s):02d}"
        return s

    # 2. GENERATE SHEET: ENTITAS (Hybrid: Mendukung Sheet ENTITAS Input & Header Flattened)
    rows_entitas = []

    # Prioritas 1: Jika file input SUDAH memiliki Sheet ENTITAS dan ada datanya
    if src_entitas and len(src_entitas) > 0:
        for idx, e in enumerate(src_entitas, start=1):
            kd_ent = clean_val(find_field(e, ['KODE ENTITAS', 'KODE_ENTITAS', 'ENTITAS']))
            raw_id = clean_val(find_field(e, ['NOMOR IDENTITAS', 'ID ENTITAS', 'NPWP', 'NOMOR_IDENTITAS']))
            raw_jid = clean_val(find_field(e, ['KODE JENIS IDENTITAS', 'JENIS IDENTITAS', 'KODE_JENIS_IDENTITAS']))
            ent_id, ent_jid = make_entitas_id(raw_id, raw_jid)

            nama_ent = clean_val(find_field(e, ['NAMA ENTITAS', 'NAMA_ENTITAS', 'NAMA', 'PERUSAHAAN']))
            if not kd_ent and not nama_ent and not ent_id:
                continue

            seri_val = find_field(e, ['SERI', 'SERI ENTITAS', 'SERI_ENTITAS'])
            seri_num = int(seri_val) if str(seri_val).isdigit() else idx

            rows_entitas.append({
                'NOMOR AJU': clean_val(find_field(e, ['NOMOR AJU', 'NOMOR_AJU'])) or nomor_aju,
                'SERI': seri_num,
                'KODE ENTITAS': kd_ent,
                'KODE JENIS IDENTITAS': ent_jid,
                'NOMOR IDENTITAS': ent_id,
                'NAMA ENTITAS': nama_ent,
                'ALAMAT ENTITAS': clean_val(find_field(e, ['ALAMAT ENTITAS', 'ALAMAT_ENTITAS', 'ALAMAT'])),
                'NIB ENTITAS': clean_val(find_field(e, ['NIB ENTITAS', 'NIB_ENTITAS', 'NIB'])),
                'KODE JENIS API': format_api_code(find_field(e, ['KODE JENIS API', 'KODE_JENIS_API', 'JENIS API', 'API'])),
                'KODE STATUS': clean_val(find_field(e, ['KODE STATUS', 'KODE_STATUS', 'STATUS'])) or 'LAINNYA',
                'NOMOR IJIN ENTITAS': clean_val(find_field(e, ['NOMOR IJIN ENTITAS', 'NOMOR IJIN', 'NOMOR_IJIN_ENTITAS'])),
                'TANGGAL IJIN ENTITAS': normalize_date(find_field(e, ['TANGGAL IJIN ENTITAS', 'TANGGAL IJIN', 'TANGGAL_IJIN_ENTITAS'])),
                'KODE NEGARA': clean_val(find_field(e, ['KODE NEGARA', 'KODE_NEGARA', 'NEGARA'])) or 'ID'
            })

    # Prioritas 2: Jika Sheet ENTITAS tidak ada atau kosong, ekstrak dari Header flattened (format TPB Reprint)
    if not rows_entitas:
        seri_entitas = 1

        # 2.1 Pengusaha TPB (Kode Entitas: 3)
        id_pengusaha = clean_val(find_field(hdr, ['ID PENGUSAHA', 'NPWP PENGUSAHA', 'ID_PENGUSAHA', 'NPWP']))
        nama_pengusaha = clean_val(find_field(hdr, ['PERUSAHAAN', 'NAMA PENGUSAHA', 'NAMA_PENGUSAHA']))
        if id_pengusaha or nama_pengusaha:
            ent_id, ent_jid = make_entitas_id(id_pengusaha, find_field(hdr, ['KODE ID PENGUSAHA', 'KODE_ID_PENGUSAHA']))
            api_code = format_api_code(find_field(hdr, ['KODE JENIS API PENGUSAHA', 'KODE JENIS API', 'JENIS API']))
            rows_entitas.append({
                'NOMOR AJU': nomor_aju,
                'SERI': seri_entitas,
                'KODE ENTITAS': 3,
                'KODE JENIS IDENTITAS': ent_jid,
                'NOMOR IDENTITAS': ent_id,
                'NAMA ENTITAS': nama_pengusaha,
                'ALAMAT ENTITAS': clean_val(find_field(hdr, ['ALAMAT PENGUSAHA', 'ALAMAT'])),
                'NIB ENTITAS': clean_val(find_field(hdr, ['NIB PENGUSAHA', 'NIB', 'NOMOR API', 'NO API'])),
                'KODE JENIS API': api_code,
                'KODE STATUS': clean_val(find_field(hdr, ['KODE STATUS PENGUSAHA', 'STATUS PENGUSAHA'])) or 'LAINNYA',
                'NOMOR IJIN ENTITAS': clean_val(find_field(hdr, ['NOMOR IJIN TPB', 'NOMOR IJIN ENTITAS', 'NO IJIN TPB'])),
                'TANGGAL IJIN ENTITAS': normalize_date(find_field(hdr, ['TANGGAL IJIN TPB', 'TANGGAL IJIN ENTITAS', 'TGL IJIN TPB'])),
                'KODE NEGARA': 'ID'
            })
            seri_entitas += 1

        # 2.2 Pemilik Barang (Kode Entitas: 7)
        id_pemilik = clean_val(find_field(hdr, ['ID PEMILIK', 'NPWP PEMILIK', 'ID_PEMILIK']))
        nama_pemilik = clean_val(find_field(hdr, ['NAMA PEMILIK', 'PEMILIK', 'NAMA_PEMILIK']))
        # Jika pemilik barang tidak diisi khusus, pada TPB pemilik barang adalah pengusaha TPB
        if not id_pemilik and not nama_pemilik and (id_pengusaha or nama_pengusaha):
            id_pemilik = id_pengusaha
            nama_pemilik = nama_pengusaha

        if id_pemilik or nama_pemilik:
            ent_id, ent_jid = make_entitas_id(id_pemilik, find_field(hdr, ['KODE ID PEMILIK', 'KODE_ID_PEMILIK']))
            api_code = format_api_code(find_field(hdr, ['KODE JENIS API PEMILIK', 'KODE JENIS API']))
            rows_entitas.append({
                'NOMOR AJU': nomor_aju,
                'SERI': seri_entitas,
                'KODE ENTITAS': 7,
                'KODE JENIS IDENTITAS': ent_jid,
                'NOMOR IDENTITAS': ent_id,
                'NAMA ENTITAS': nama_pemilik,
                'ALAMAT ENTITAS': clean_val(find_field(hdr, ['ALAMAT PEMILIK', 'ALAMAT PENGUSAHA', 'ALAMAT'])),
                'NIB ENTITAS': clean_val(find_field(hdr, ['NIB PEMILIK', 'NIB PENGUSAHA', 'NIB'])),
                'KODE JENIS API': api_code,
                'KODE STATUS': 'LAINNYA',
                'NOMOR IJIN ENTITAS': '',
                'TANGGAL IJIN ENTITAS': '',
                'KODE NEGARA': clean_val(find_field(hdr, ['KODE NEGARA PEMILIK', 'NEGARA PEMILIK'])) or 'ID'
            })
            seri_entitas += 1

        # 2.3 Penerima Barang (Kode Entitas: 8)
        id_penerima = clean_val(find_field(hdr, ['ID PENERIMA BARANG', 'ID PENERIMA', 'NPWP PENERIMA', 'ID_PENERIMA']))
        nama_penerima = clean_val(find_field(hdr, ['NAMA PENERIMA BARANG', 'NAMA PENERIMA', 'PENERIMA', 'NAMA_PENERIMA']))
        if id_penerima or nama_penerima:
            ent_id, ent_jid = make_entitas_id(id_penerima, find_field(hdr, ['KODE ID PENERIMA BARANG', 'KODE ID PENERIMA']))
            api_code = format_api_code(find_field(hdr, ['KODE JENIS API PENERIMA', 'JENIS API PENERIMA']))
            rows_entitas.append({
                'NOMOR AJU': nomor_aju,
                'SERI': seri_entitas,
                'KODE ENTITAS': 8,
                'KODE JENIS IDENTITAS': ent_jid,
                'NOMOR IDENTITAS': ent_id,
                'NAMA ENTITAS': nama_penerima,
                'ALAMAT ENTITAS': clean_val(find_field(hdr, ['ALAMAT PENERIMA BARANG', 'ALAMAT PENERIMA'])),
                'NIB ENTITAS': clean_val(find_field(hdr, ['NIB PENERIMA', 'NIB PENERIMA BARANG'])),
                'KODE JENIS API': api_code,
                'KODE STATUS': 'LAINNYA',
                'NOMOR IJIN ENTITAS': clean_val(find_field(hdr, ['NOMOR IJIN TPB PENERIMA', 'NO IJIN TPB PENERIMA'])),
                'TANGGAL IJIN ENTITAS': '',
                'KODE NEGARA': clean_val(find_field(hdr, ['KODE NEGARA PENERIMA', 'NEGARA PENERIMA'])) or 'ID'
            })
            seri_entitas += 1

        # 2.4 Pemasok / Penjual (Kode Entitas: 5)
        id_pemasok = clean_val(find_field(hdr, ['ID PEMASOK', 'NPWP PEMASOK', 'ID_PEMASOK']))
        nama_pemasok = clean_val(find_field(hdr, ['PEMASOK', 'NAMA PEMASOK', 'NAMA_PEMASOK']))
        if id_pemasok or nama_pemasok:
            ent_id, ent_jid = make_entitas_id(id_pemasok, find_field(hdr, ['KODE ID PEMASOK', 'KODE_ID_PEMASOK']))
            rows_entitas.append({
                'NOMOR AJU': nomor_aju,
                'SERI': seri_entitas,
                'KODE ENTITAS': 5,
                'KODE JENIS IDENTITAS': ent_jid,
                'NOMOR IDENTITAS': ent_id,
                'NAMA ENTITAS': nama_pemasok,
                'ALAMAT ENTITAS': clean_val(find_field(hdr, ['ALAMAT PEMASOK'])),
                'NIB ENTITAS': '',
                'KODE JENIS API': '',
                'KODE STATUS': 'LAINNYA',
                'NOMOR IJIN ENTITAS': clean_val(find_field(hdr, ['NOMOR IJIN BPK PEMASOK', 'NO IJIN PEMASOK'])),
                'TANGGAL IJIN ENTITAS': normalize_date(find_field(hdr, ['TANGGAL IJIN BPK PEMASOK', 'TGL IJIN PEMASOK'])),
                'KODE NEGARA': clean_val(find_field(hdr, ['KODE NEGARA PEMASOK', 'NEGARA PEMASOK'])) or 'ID'
            })
            seri_entitas += 1

    # 3. GENERATE SHEET: DOKUMEN (Dengan Multi-Alias & Fallback Kode Dokumen Cerdas)
    rows_dokumen = []
    for idx, d in enumerate(src_dokumen, start=1):
        no_dok = clean_val(find_field(d, ['NOMOR DOKUMEN', 'NOMOR_DOKUMEN', 'NO DOKUMEN', 'NO_DOKUMEN']))
        kd_dok = clean_val(find_field(d, ['KODE DOKUMEN', 'KODE_DOKUMEN', 'KODE JENIS DOKUMEN', 'JENIS DOKUMEN', 'KODE_JENIS_DOKUMEN']))

        # Fallback pintar jika kode dokumen kosong
        if not kd_dok and no_dok:
            u_nodok = no_dok.upper()
            if u_nodok.startswith(('INV', 'IV', 'FAK', 'FKT')):
                kd_dok = '380'  # Invoice
            elif u_nodok.startswith(('DO', 'D0', 'SJ', 'PL')):
                kd_dok = '217'  # Packing List / Surat Jalan
            elif idx == 1:
                kd_dok = '380'
            else:
                kd_dok = '217'

        if not no_dok and not kd_dok:
            continue

        seri_d = find_field(d, ['SERI DOKUMEN', 'SERI_DOKUMEN', 'SERI'])
        seri_d_num = int(seri_d) if str(seri_d).isdigit() else (len(rows_dokumen) + 1)

        rows_dokumen.append({
            'NOMOR AJU': clean_val(find_field(d, ['NOMOR AJU', 'NOMOR_AJU'])) or nomor_aju,
            'SERI': seri_d_num,
            'KODE DOKUMEN': kd_dok,
            'NOMOR DOKUMEN': no_dok,
            'TANGGAL DOKUMEN': normalize_date(find_field(d, ['TANGGAL DOKUMEN', 'TANGGAL_DOKUMEN', 'TGL DOKUMEN'])),
            'KODE FASILITAS': clean_val(find_field(d, ['KODE FASILITAS', 'KODE_FASILITAS'])),
            'KODE IJIN': clean_val(find_field(d, ['KODE IJIN', 'KODE_IJIN']))
        })

    # 4. GENERATE SHEET: PENGANGKUT
    cara_angkut = clean_val(find_field(hdr, ['KODE CARA ANGKUT', 'CARA ANGKUT'])) or '3'
    rows_pengangkut = [{
        'NOMOR AJU': nomor_aju,
        'SERI': 1,
        'KODE CARA ANGKUT': cara_angkut,
        'NAMA PENGANGKUT': clean_val(find_field(hdr, ['NAMA PENGANGKUT', 'PENGANGKUT'])) or '-',
        'NOMOR PENGANGKUT': clean_val(find_field(hdr, ['NOMOR POLISI', 'NOMOR_POLISI', 'NOMOR VOYV FLIGHT', 'NOMOR PENGANGKUT'])) or '1',
        'KODE BENDERA': clean_val(find_field(hdr, ['KODE BENDERA', 'BENDERA'])) or 'ID',
        'CALL SIGN': clean_val(find_field(hdr, ['CALL SIGN', 'CALL_SIGN']))
    }]

    # 5. GENERATE SHEET: KEMASAN
    rows_kemasan = []
    for idx, k in enumerate(src_kemasan, start=1):
        kd_k = clean_val(find_field(k, ['KODE KEMASAN', 'KODE JENIS KEMASAN', 'JENIS KEMASAN', 'KODE_KEMASAN']))
        jml_k = find_field(k, ['JUMLAH KEMASAN', 'JUMLAH', 'JUMLAH_KEMASAN'], None)
        if not kd_k and jml_k is None:
            continue
        seri_k = find_field(k, ['SERI KEMASAN', 'SERI', 'SERI_KEMASAN'])
        seri_k_num = int(seri_k) if str(seri_k).isdigit() else idx
        rows_kemasan.append({
            'NOMOR AJU': clean_val(find_field(k, ['NOMOR AJU', 'NOMOR_AJU'])) or nomor_aju,
            'SERI': seri_k_num,
            'KODE KEMASAN': kd_k,
            'JUMLAH KEMASAN': to_float(jml_k) if jml_k is not None else 0.0,
            'MEREK': clean_val(find_field(k, ['MEREK KEMASAN', 'MEREK', 'MERK'])),
            'NOMOR SEGEL': clean_val(find_field(k, ['NOMOR SEGEL', 'NO SEGEL']))
        })

    # 6. GENERATE SHEET: KONTAINER
    rows_kontainer = []
    for idx, c in enumerate(src_kontainer, start=1):
        no_k = clean_val(find_field(c, ['NOMOR KONTAINER', 'NOMOR KONTINER', 'NO KONTAINER', 'NOMOR_KONTAINER']))
        if not no_k and not find_field(c, ['KODE UKURAN KONTAINER', 'UKURAN']):
            continue
        seri_c = find_field(c, ['SERI KONTAINER', 'SERI', 'SERI_KONTAINER'])
        seri_c_num = int(seri_c) if str(seri_c).isdigit() else idx
        rows_kontainer.append({
            'NOMOR AJU': clean_val(find_field(c, ['NOMOR AJU', 'NOMOR_AJU'])) or nomor_aju,
            'SERI': seri_c_num,
            'NOMOR KONTINER': no_k,
            'KODE UKURAN KONTAINER': clean_val(find_field(c, ['KODE UKURAN KONTAINER', 'UKURAN'])),
            'KODE JENIS KONTAINER': clean_val(find_field(c, ['KODE JENIS KONTAINER', 'JENIS'])),
            'KODE TIPE KONTAINER': clean_val(find_field(c, ['KODE TIPE KONTAINER', 'TIPE'])),
            'NOMOR SEGEL': clean_val(find_field(c, ['NOMOR SEGEL', 'NO SEGEL']))
        })

    # 7. GENERATE SHEET: BARANG (Mendukung HS / POS TARIF)
    rows_barang = []
    barang_lookup = {}
    for idx, b in enumerate(src_barang, start=1):
        seri_b = find_field(b, ['SERI BARANG', 'SERI_BARANG', 'SERI'])
        hs_val = clean_val(find_field(b, ['HS', 'POS TARIF', 'POS_TARIF', 'KODE HS', 'NO HS']))
        uraian_val = clean_val(find_field(b, ['URAIAN', 'URAIAN BARANG', 'NAMA BARANG']))

        if not seri_b and not hs_val and not uraian_val:
            continue
        seri_b_num = int(seri_b) if str(seri_b).isdigit() else idx

        brg_hp = to_float(find_field(b, ['HARGA PENYERAHAN', 'HARGA_PENYERAHAN']))
        if brg_hp == 0 and len(src_barang) == 1 and hdr_harga_penyerahan > 0:
            brg_hp = hdr_harga_penyerahan

        brg_bruto = to_float(find_field(b, ['BRUTO', 'BERAT KOTOR'])) or hdr_bruto
        brg_netto = to_float(find_field(b, ['NETTO', 'BERAT BERSIH'])) or hdr_netto
        brg_cif = to_float(find_field(b, ['CIF', 'NILAI CIF'])) or hdr_cif
        brg_ndpbm = to_float(find_field(b, ['NDPBM', 'KURS'])) or hdr_ndpbm
        brg_cif_rupiah = to_float(find_field(b, ['CIF RUPIAH', 'CIF_RUPIAH'])) or round(brg_cif * brg_ndpbm, 2)

        kd_satuan = clean_val(find_field(b, ['KODE SATUAN', 'JENIS SATUAN', 'SATUAN']))
        jml_satuan = to_float(find_field(b, ['JUMLAH SATUAN', 'JUMLAH']))

        barang_lookup[seri_b_num] = {
            'kode_satuan': kd_satuan,
            'jumlah_satuan': jml_satuan,
            'harga_penyerahan': brg_hp,
            'cif': brg_cif,
            'cif_rupiah': brg_cif_rupiah,
            'ndpbm': brg_ndpbm
        }

        rows_barang.append({
            'NOMOR AJU': clean_val(find_field(b, ['NOMOR AJU', 'NOMOR_AJU'])) or nomor_aju,
            'SERI BARANG': seri_b_num,
            'HS': hs_val,
            'KODE BARANG': clean_val(find_field(b, ['KODE BARANG', 'KODE_BARANG', 'PART NUMBER'])),
            'URAIAN': uraian_val,
            'MEREK': clean_val(find_field(b, ['MEREK', 'MERK'])) or '-',
            'TIPE': clean_val(find_field(b, ['TIPE'])) or '-',
            'UKURAN': clean_val(find_field(b, ['UKURAN'])) or '-',
            'SPESIFIKASI LAIN': clean_val(find_field(b, ['SPESIFIKASI LAIN', 'SPESIFIKASI'])) or '-',
            'KODE SATUAN': kd_satuan,
            'JUMLAH SATUAN': jml_satuan,
            'KODE KEMASAN': clean_val(find_field(b, ['KODE KEMASAN', 'KODE JENIS KEMASAN'])),
            'JUMLAH KEMASAN': to_float(find_field(b, ['JUMLAH KEMASAN']), 1.0),
            'KODE DOKUMEN ASAL': clean_val(find_field(b, ['KODE DOKUMEN ASAL', 'DOKUMEN ASAL'])) or '-',
            'NETTO': brg_netto,
            'BRUTO': brg_bruto,
            'VOLUME': to_float(find_field(b, ['VOLUME'])),
            'JUMLAH REALISASI': 0.0,
            'CIF': brg_cif,
            'CIF RUPIAH': brg_cif_rupiah,
            'NDPBM': brg_ndpbm,
            'FOB': 0.0,
            'ASURANSI': 0.0,
            'FREIGHT': 0.0,
            'NILAI TAMBAH': 0.0,
            'DISKON': 0.0,
            'HARGA PENYERAHAN': brg_hp,
            'HARGA PEROLEHAN': 0.0,
            'HARGA SATUAN': 0.0,
            'HARGA EKSPOR': 0.0,
            'NILAI BARANG': 0.0,
            'NILAI JASA': 0.0,
            'KODE GUNA BARANG': clean_val(find_field(b, ['KODE GUNA BARANG', 'KODE GUNA'])) or ('4' if is_bc25 else ''),
            'KODE KATEGORI BARANG': clean_val(find_field(b, ['KODE KATEGORI BARANG', 'KATEGORI BARANG'])) or ('5' if is_bc25 else ''),
            'KODE KONDISI BARANG': clean_val(find_field(b, ['KODE KONDISI BARANG', 'KONDISI BARANG'])) or ('2' if is_bc25 else ''),
            'KODE PERHITUNGAN': '1' if is_bc25 else (clean_val(find_field(b, ['KODE PERHITUNGAN'])) or '0'),
            'ISI PER KEMASAN': 0.0
        })

    # 8. GENERATE SHEET: BARANGTARIF (Mendukung KODE PUNGUTAN / JENIS TARIF)
    rows_btarif = []
    total_bayar_map = {'PPN': 0.0, 'PPH': 0.0, 'BM': 0.0}

    existing_tarifs = {}
    for t in src_btarif:
        seri_b = find_field(t, ['SERI BARANG', 'SERI_BARANG', 'SERI'])
        kd_p = clean_val(find_field(t, ['KODE PUNGUTAN', 'JENIS TARIF', 'KODE JENIS PUNGUTAN', 'PUNGUTAN'])).upper()
        if seri_b and kd_p:
            existing_tarifs[(int(seri_b) if str(seri_b).isdigit() else 1, kd_p)] = t

    # Iterasi per barang untuk susun BarangTarif resmi
    for seri_b_num, b_info in barang_lookup.items():
        hp = b_info['harga_penyerahan']
        kd_sat = b_info['kode_satuan']
        jml_sat = b_info['jumlah_satuan']

        # Jenis pungutan standar BC 2.5: BM, PPH, PPN (urutan resmi CEISA 4.0: BM, PPH, PPN)
        standard_pungutans = ['BM', 'PPH', 'PPN'] if is_bc25 else ['BM', 'PPN', 'PPH']

        for kd_p in standard_pungutans:
            raw_t = existing_tarifs.get((seri_b_num, kd_p), {})

            if kd_p == 'PPN':
                tarif = to_float(find_field(raw_t, ['TARIF']), 11.0)
                nilai_bayar = round(hp * (tarif / 100.0), 2) if is_bc25 else to_float(find_field(raw_t, ['NILAI BAYAR', 'TARIF NILAI BAYAR']))
                kd_fasilitas = '1' if is_bc25 else clean_val(find_field(raw_t, ['KODE FASILITAS'], '0'))
            elif kd_p == 'PPH':
                tarif = to_float(find_field(raw_t, ['TARIF']), 2.5)
                nilai_bayar = round(hp * (tarif / 100.0), 2) if is_bc25 else to_float(find_field(raw_t, ['NILAI BAYAR', 'TARIF NILAI BAYAR']))
                kd_fasilitas = '1' if is_bc25 else clean_val(find_field(raw_t, ['KODE FASILITAS'], '0'))
            else:  # BM
                tarif = to_float(find_field(raw_t, ['TARIF']), 0.0)
                nilai_bayar = 0.0 if (is_bc25 and tarif == 0) else to_float(find_field(raw_t, ['NILAI BAYAR', 'TARIF NILAI BAYAR']))
                kd_fasilitas = '1' if is_bc25 else clean_val(find_field(raw_t, ['KODE FASILITAS'], '0'))

            if kd_fasilitas == '1':
                total_bayar_map[kd_p] = total_bayar_map.get(kd_p, 0.0) + nilai_bayar

            rows_btarif.append({
                'NOMOR AJU': nomor_aju,
                'SERI BARANG': seri_b_num,
                'KODE PUNGUTAN': kd_p,
                'KODE TARIF': '1',
                'TARIF': tarif,
                'KODE FASILITAS': kd_fasilitas,
                'TARIF FASILITAS': 100.0,
                'NILAI BAYAR': nilai_bayar,
                'NILAI FASILITAS': 0.0,
                'NILAI SUDAH DILUNASI': 0.0,
                'KODE SATUAN': kd_sat,
                'JUMLAH SATUAN': jml_sat
            })

    # 9. GENERATE SHEET: BARANGDOKUMEN
    rows_bdok = []
    for bd in src_bdok:
        seri_b = find_field(bd, ['SERI BARANG', 'SERI_BARANG', 'SERI'])
        seri_d = find_field(bd, ['SERI DOKUMEN', 'SERI_DOKUMEN'])
        if not seri_b and not seri_d:
            continue
        rows_bdok.append({
            'NOMOR AJU': clean_val(find_field(bd, ['NOMOR AJU', 'NOMOR_AJU'])) or nomor_aju,
            'SERI BARANG': int(seri_b) if str(seri_b).isdigit() else seri_b,
            'SERI DOKUMEN': int(seri_d) if str(seri_d).isdigit() else seri_d
        })

    # 10. GENERATE SHEET: BAHANBAKU
    rows_bb = []
    for bb in src_bb:
        seri_b = find_field(bb, ['SERI BARANG', 'SERI_BARANG', 'SERI'])
        seri_bb = find_field(bb, ['SERI BAHAN BAKU', 'SERI_BAHAN_BAKU', 'SERI BB'])
        if not seri_b or not seri_bb:
            continue
        rows_bb.append({
            'NOMOR AJU': clean_val(find_field(bb, ['NOMOR AJU', 'NOMOR_AJU'])) or nomor_aju,
            'SERI BARANG': int(seri_b) if str(seri_b).isdigit() else 1,
            'SERI BAHAN BAKU': int(seri_bb) if str(seri_bb).isdigit() else seri_bb,
            'KODE ASAL BAHAN BAKU': clean_val(find_field(bb, ['KODE ASAL BAHAN BAKU', 'KODE_ASAL_BAHAN_BAKU'], '0')),
            'HS': clean_val(find_field(bb, ['HS', 'POS TARIF', 'POS_TARIF', 'KODE HS'])),
            'KODE BARANG': clean_val(find_field(bb, ['KODE BARANG', 'KODE_BARANG'])),
            'URAIAN': clean_val(find_field(bb, ['URAIAN', 'URAIAN BARANG'])),
            'MEREK': clean_val(find_field(bb, ['MEREK', 'MERK'])) or ' ',
            'TIPE': clean_val(find_field(bb, ['TIPE'])) or ' ',
            'UKURAN': clean_val(find_field(bb, ['UKURAN'])) or ' ',
            'SPESIFIKASI LAIN': clean_val(find_field(bb, ['SPESIFIKASI LAIN', 'SPESIFIKASI'])) or ' ',
            'KODE SATUAN': clean_val(find_field(bb, ['KODE SATUAN', 'JENIS SATUAN', 'SATUAN'])),
            'JUMLAH SATUAN': to_float(find_field(bb, ['JUMLAH SATUAN', 'JUMLAH'])),
            'KODE DOKUMEN ASAL': clean_val(find_field(bb, ['KODE DOKUMEN ASAL', 'KODE JENIS DOK ASAL', 'KODE_JENIS_DOK_ASAL'])),
            'KODE KANTOR ASAL': clean_val(find_field(bb, ['KODE KANTOR ASAL', 'KODE KANTOR', 'KANTOR ASAL'])),
            'NOMOR DAFTAR ASAL': clean_val(find_field(bb, ['NOMOR DAFTAR ASAL', 'NOMOR DAFTAR DOKUMEN ASAL'])),
            'TANGGAL DAFTAR ASAL': normalize_date(find_field(bb, ['TANGGAL DAFTAR ASAL', 'TANGGAL DAFTAR DOKUMEN ASAL'])),
            'NOMOR AJU ASAL': clean_val(find_field(bb, ['NOMOR AJU ASAL', 'NOMOR AJU DOKUMEN ASAL'])),
            'SERI BARANG ASAL': int(find_field(bb, ['SERI BARANG ASAL', 'SERI BARANG DOKUMEN ASAL'], 1)) if str(find_field(bb, ['SERI BARANG ASAL', 'SERI BARANG DOKUMEN ASAL'], '')).isdigit() else 1,
            'NETTO': 0.0,
            'CIF': to_float(find_field(bb, ['CIF', 'NILAI CIF'])),
            'CIF RUPIAH': 0.0,
            'NDPBM': to_float(find_field(bb, ['NDPBM', 'KURS'], hdr_ndpbm)),
            'HARGA PENYERAHAN': 0.0,
            'HARGA PEROLEHAN': 0.0,
            'SERI IZIN': 0
        })

    # 11. GENERATE SHEET: BAHANBAKUTARIF (Mendukung KODE PUNGUTAN / JENIS TARIF)
    rows_bbtarif = []
    for bbt in src_bbtarif:
        seri_b = find_field(bbt, ['SERI BARANG', 'SERI_BARANG', 'SERI'])
        seri_bb = find_field(bbt, ['SERI BAHAN BAKU', 'SERI_BAHAN_BAKU', 'SERI BB'])
        kd_p = clean_val(find_field(bbt, ['KODE PUNGUTAN', 'JENIS TARIF', 'KODE JENIS PUNGUTAN', 'PUNGUTAN'])).upper()
        if not seri_b or not seri_bb or not kd_p:
            continue

        # Pada BC 2.5, pungutan bahan baku mendapat fasilitas / tidak bayar (0.0) karena sudah dibayar di barang
        nilai_bayar_bb = 0.0 if is_bc25 else to_float(find_field(bbt, ['NILAI BAYAR', 'TARIF NILAI BAYAR']))
        kd_fas_bb = '0' if is_bc25 else clean_val(find_field(bbt, ['KODE FASILITAS'], '0'))

        rows_bbtarif.append({
            'NOMOR AJU': clean_val(find_field(bbt, ['NOMOR AJU', 'NOMOR_AJU'])) or nomor_aju,
            'SERI BARANG': int(seri_b) if str(seri_b).isdigit() else 1,
            'SERI BAHAN BAKU': int(seri_bb) if str(seri_bb).isdigit() else 1,
            'KODE ASAL BAHAN BAKU': clean_val(find_field(bbt, ['KODE ASAL BAHAN BAKU', 'KODE_ASAL_BAHAN_BAKU'], '0')),
            'KODE PUNGUTAN': kd_p,
            'KODE TARIF': '1',
            'TARIF': to_float(find_field(bbt, ['TARIF'])),
            'KODE FASILITAS': kd_fas_bb,
            'TARIF FASILITAS': 100.0,
            'NILAI BAYAR': nilai_bayar_bb,
            'NILAI FASILITAS': 0.0,
            'NILAI SUDAH DILUNASI': 0.0,
            'KODE SATUAN': clean_val(find_field(bbt, ['KODE SATUAN', 'SATUAN'])),
            'JUMLAH SATUAN': 0.0,
            'KODE SUB KOMODITI CUKAI': None
        })

    # 12. GENERATE SHEET: BAHANBAKUDOKUMEN
    rows_bbdok = []
    for bbd in src_bbdok:
        seri_b = find_field(bbd, ['SERI BARANG', 'SERI_BARANG', 'SERI'])
        seri_bb = find_field(bbd, ['SERI BAHAN BAKU', 'SERI_BAHAN_BAKU', 'SERI BB'])
        if not seri_b or not seri_bb:
            continue
        rows_bbdok.append({
            'NOMOR AJU': clean_val(find_field(bbd, ['NOMOR AJU', 'NOMOR_AJU'])) or nomor_aju,
            'SERI BARANG': int(seri_b) if str(seri_b).isdigit() else 1,
            'SERI BAHAN BAKU': int(seri_bb) if str(seri_bb).isdigit() else 1,
            'KODE_ASAL_BAHAN_BAKU': clean_val(find_field(bbd, ['KODE_ASAL_BAHAN_BAKU', 'KODE ASAL BAHAN BAKU'], '0')),
            'SERI DOKUMEN': clean_val(find_field(bbd, ['SERI DOKUMEN', 'SERI_DOKUMEN', 'SERI']))
        })

    # 13. GENERATE SHEET: PUNGUTAN (Kalkulasi Akurat & Pembulatan Ribuan CEISA 4.0)
    rows_pungutan = []
    if is_bc25:
        # Pungutan Bayar BC 2.5: PPN dan PPH (BM jika > 0)
        # Urutan resmi di sheet PUNGUTAN: PPN, PPH (hanya yang nilai bayar > 0)
        for kd_p in ['PPN', 'PPH', 'BM']:
            tot = total_bayar_map.get(kd_p, 0.0)
            if tot > 0:
                # Pembulatan ke ribuan terdekat / round to thousand (aturan CEISA DJBC)
                nilai_pungutan_billing = float(math.ceil(tot / 1000.0) * 1000)
                rows_pungutan.append({
                    'NOMOR AJU': nomor_aju,
                    'KODE FASILITAS TARIF': '1',
                    'KODE JENIS PUNGUTAN': kd_p,
                    'NILAI PUNGUTAN': nilai_pungutan_billing,
                    'NPWP BILLING': None
                })
    else:
        # Dokumen selain BC 2.5: ambil dari sheet pungutan sumber
        for p in src_pungutan:
            j_tarif = clean_val(find_field(p, ['KODE JENIS PUNGUTAN', 'JENIS TARIF', 'KODE PUNGUTAN'])).upper()
            if not j_tarif:
                continue
            npwp_b = clean_val(find_field(p, ['NPWP BILLING', 'NPWP_BILLING'])) or None
            val_p = to_float(find_field(p, ['NILAI PUNGUTAN', 'NILAI_PUNGUTAN', 'NILAI']))
            rows_pungutan.append({
                'NOMOR AJU': clean_val(find_field(p, ['NOMOR AJU', 'NOMOR_AJU'])) or nomor_aju,
                'KODE FASILITAS TARIF': clean_val(find_field(p, ['KODE FASILITAS TARIF', 'KODE FASILITAS'], '0')),
                'KODE JENIS PUNGUTAN': j_tarif,
                'NILAI PUNGUTAN': val_p,
                'NPWP BILLING': npwp_b
            })

    # 14. GENERATE SHEET: VERSI (Resmi CEISA 4.0: 1.3)
    rows_versi = [{'VERSI': '1.3'}]

    # 15. GENERATE SHEET: RESPON
    rows_respon = []
    for r in src_respon:
        kd_r = clean_val(find_field(r, ['KODE RESPON', 'KODE_RESPON']))
        no_r = clean_val(find_field(r, ['NOMOR RESPON', 'NOMOR_RESPON']))
        if not kd_r and not no_r:
            continue
        rows_respon.append({
            'NOMOR AJU': clean_val(find_field(r, ['NOMOR AJU', 'NOMOR_AJU'])) or nomor_aju,
            'KODE RESPON': kd_r,
            'NOMOR RESPON': no_r,
            'TANGGAL RESPON': normalize_date(find_field(r, ['TANGGAL RESPON', 'TGL RESPON']))
        })

    # Kompilasi seluruh dataset per sheet resmi CEISA 4.0
    final_sheets_data = {
        'HEADER': [row_header] if row_header.get('NOMOR AJU') else [],
        'ENTITAS': rows_entitas,
        'DOKUMEN': rows_dokumen,
        'PENGANGKUT': rows_pengangkut,
        'KEMASAN': rows_kemasan,
        'KONTAINER': rows_kontainer,
        'KOMPONENBIAYA': [],
        'BARANG': rows_barang,
        'BARANGTARIF': rows_btarif,
        'BARANGDOKUMEN': rows_bdok,
        'BARANGENTITAS': [],
        'BARANGSPEKKHUSUS': [],
        'BARANGVD': [],
        'BAHANBAKU': rows_bb,
        'BAHANBAKUTARIF': rows_bbtarif,
        'BAHANBAKUDOKUMEN': rows_bbdok,
        'PUNGUTAN': rows_pungutan,
        'JAMINAN': [],
        'BANKDEVISA': [],
        'VERSI': rows_versi,
        'RESPON': rows_respon
    }

    # 21 Sheet Resmi Portal CEISA 4.0 berurutan
    sheet_order = [
        'HEADER', 'ENTITAS', 'DOKUMEN', 'PENGANGKUT', 'KEMASAN', 'KONTAINER', 'KOMPONENBIAYA',
        'BARANG', 'BARANGTARIF', 'BARANGDOKUMEN', 'BARANGENTITAS', 'BARANGSPEKKHUSUS', 'BARANGVD',
        'BAHANBAKU', 'BAHANBAKUTARIF', 'BAHANBAKUDOKUMEN', 'PUNGUTAN', 'JAMINAN', 'BANKDEVISA',
        'VERSI', 'RESPON'
    ]

    string_cols = {
        'NOMOR AJU', 'KODE DOKUMEN', 'KODE KANTOR', 'KODE KANTOR BONGKAR', 'KODE KANTOR TUJUAN',
        'KODE JENIS TPB', 'NOMOR BC11', 'NOMOR POS', 'NOMOR SUB POS', 'NOMOR DAFTAR', 'NOMOR DAFTAR ASAL',
        'NOMOR AJU ASAL', 'KODE DOKUMEN ASAL', 'NOMOR IDENTITAS', 'NIB ENTITAS', 'NOMOR IJIN ENTITAS',
        'KODE ENTITAS', 'KODE JENIS IDENTITAS', 'NOMOR DOKUMEN', 'KODE KEMASAN', 'NOMOR KONTINER',
        'HS', 'KODE BARANG', 'KODE SATUAN', 'NPWP BILLING', 'KODE PUNGUTAN', 'KODE FASILITAS',
        'KODE TARIF', 'KODE FASILITAS TARIF', 'KODE JENIS PUNGUTAN', 'VERSI', 'KODE RESPON', 'NOMOR RESPON',
        'KODE JENIS API', 'KODE STATUS'
    }

    header_font = Font(name='Calibri', size=11, bold=True, color='000000')
    data_font = Font(name='Calibri', size=11, bold=False, color='000000')

    wb_out = openpyxl.Workbook()
    wb_out.remove(wb_out.active)

    for sname in sheet_order:
        ws = wb_out.create_sheet(title=sname)
        columns = ceisa_structure.get(sname, [])
        if not columns and sname in final_sheets_data and len(final_sheets_data[sname]) > 0:
            columns = list(final_sheets_data[sname][0].keys())

        # Tulis Baris 1: Header Kolom
        for col_idx, col_name in enumerate(columns, start=1):
            cell = ws.cell(row=1, column=col_idx, value=col_name)
            cell.font = header_font

        # Tulis Baris Data Transaksi
        rows_list = final_sheets_data.get(sname, [])
        for row_idx, rdata in enumerate(rows_list, start=2):
            for col_idx, col_name in enumerate(columns, start=1):
                val = rdata.get(col_name, None)
                cell = ws.cell(row=row_idx, column=col_idx)
                cell.font = data_font

                if val is None or val == '':
                    cell.value = None
                elif col_name in string_cols or (isinstance(val, str) and (val.startswith('0') or '-' in val or '/' in val)):
                    cell.number_format = '@'
                    cell.value = str(val)
                elif isinstance(val, (int, float)):
                    cell.value = val
                else:
                    s_val = str(val).strip()
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
        for col_idx, col_name in enumerate(columns, start=1):
            col_letter = get_column_letter(col_idx)
            max_len = len(str(col_name))
            for r in range(2, min(len(rows_list) + 2, 25)):
                cv = ws.cell(row=r, column=col_idx).value
                if cv is not None:
                    max_len = max(max_len, len(str(cv)[:35]))
            ws.column_dimensions[col_letter].width = max(12, min(max_len + 3, 40))

    os.makedirs(os.path.dirname(os.path.abspath(output_path)), exist_ok=True)
    wb_out.save(output_path)

    stats = {
        'nomor_aju': nomor_aju,
        'kode_dokumen': kode_dokumen,
        'perusahaan': perusahaan,
        'barang_count': len(rows_barang),
        'bahan_baku_count': len(rows_bb),
        'dokumen_count': len(rows_dokumen),
        'kemasan_count': len(rows_kemasan),
        'entitas_count': len(rows_entitas),
        'pungutan_count': len(rows_pungutan),
        'output_file': output_path,
        'output_size': os.path.getsize(output_path)
    }

    return stats


if __name__ == '__main__':
    if len(sys.argv) < 3:
        print("Penggunaan: python convert_engine.py <input_reprint.xls> <output_ceisa40.xlsx> [structure.json]")
        sys.exit(1)

    in_f = sys.argv[1]
    out_f = sys.argv[2]
    struct_f = sys.argv[3] if len(sys.argv) > 3 else None

    try:
        res = convert_reprint_to_ceisa4(in_f, out_f, struct_f)
        print(json.dumps({'status': 'SUCCESS', 'data': res}))
    except Exception as e:
        import traceback
        print(json.dumps({'status': 'ERROR', 'message': str(e), 'trace': traceback.format_exc()}))
        sys.exit(1)

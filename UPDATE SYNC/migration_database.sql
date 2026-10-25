-- =========================================================================
-- CEISA 4.0 - DATABASE MIGRATION SCRIPT
-- =========================================================================
-- Fitur: Inisialisasi Otomatis & Default Header Dokumen per Perusahaan (bc_ceisa_setaju)
-- Waktu: 2026-10-25
-- =========================================================================

-- 1. Penambahan Kolom Default Header pada Tabel bc_ceisa_setaju
ALTER TABLE bc_ceisa_setaju 
    MODIFY COLUMN bc VARCHAR(10),
    ADD COLUMN IF NOT EXISTS kode_kantor VARCHAR(10) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS kode_jenis_tpb VARCHAR(10) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS kode_tujuan_pengiriman VARCHAR(10) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS kode_tujuan_pemasukan VARCHAR(10) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS kode_tujuan_pengeluaran VARCHAR(10) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS kode_valuta VARCHAR(10) DEFAULT 'USD',
    ADD COLUMN IF NOT EXISTS header_defaults TEXT DEFAULT NULL;

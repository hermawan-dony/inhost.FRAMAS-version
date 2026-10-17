<?php
/**
 * CEISA 4.0 - ERP Integration Modular Framework
 * Base Abstract Driver (ErpBaseDriver.php)
 * 
 * Fondasi modular untuk mengintegrasikan berbagai sistem ERP (SAP, Odoo, HEESUNG, FRAMAS, dll.)
 * ke dalam tabel staging CEISA 4.0 (bc_ceisa_header, bc_ceisa_barang, bc_ceisa_dokumen, bc_ceisa_kemasan).
 */

abstract class ErpBaseDriver
{
    /** @var array Data profil perusahaan dari bc_ceisa_company */
    protected $company;

    /** @var mysqli Koneksi database staging CEISA 4.0 */
    protected $db;

    /**
     * @param array $company Data profil perusahaan aktif
     * @param mysqli $db Koneksi MySQL CEISA 4.0
     */
    public function __construct(array $company, mysqli $db)
    {
        $this->company = $company;
        $this->db = $db;
    }

    /**
     * Nama kode unik driver (contoh: 'FRAMAS', 'SAP', 'HEESUNG', 'ODOO', 'CUSTOM')
     */
    abstract public function getName(): string;

    /**
     * Judul human-readable driver
     */
    abstract public function getTitle(): string;

    /**
     * Deskripsi singkat konektor ERP
     */
    abstract public function getDescription(): string;

    /**
     * Daftar jenis transaksi yang didukung (misal: 'outbound' / sales, 'inbound' / purchase)
     */
    public function getTransactionTypes(string $kodeDokumen = ''): array
    {
        $cleanBc = preg_replace('/[^0-9]/', '', $kodeDokumen);
        // Jika dokumen ekspor atau pengeluaran TPB (BC 3.0, 2.5, 2.7, 4.1, 2.6.1)
        if (in_array($cleanBc, ['30', '25', '27', '41', '261', '33'])) {
            return [
                'outbound' => 'Sales Invoice / Delivery Order (Pengeluaran Barang)',
                'inbound'  => 'Purchase Return / Lainnya'
            ];
        }
        // Jika dokumen impor atau pemasukan TPB (BC 2.0, 2.3, 4.0, 1.6, 2.6.2)
        return [
            'inbound'  => 'Purchase Order / Goods Receipt (Pemasukan Barang)',
            'outbound' => 'Sales / Outbound Transfer'
        ];
    }

    /**
     * Cari daftar dokumen/transaksi di database ERP
     * @param array $filters [doc_type, search_q, date_from, date_to, kode_dokumen]
     * @return array List of documents found
     */
    abstract public function search(array $filters): array;

    /**
     * Ambil preview detail suatu dokumen sebelum diintegrasikan
     * @param string $docId ID dokumen ERP (No Invoice / No DO / No PO)
     * @param string $kodeDokumen Jenis Dokumen BC
     * @return array [header, items, documents, packages]
     */
    abstract public function getPreview(string $docId, string $kodeDokumen): array;

    /**
     * Eksekusi transfer data dari ERP ke tabel staging CEISA 4.0
     * @param string $nomorAju Nomor AJU 26 Digit
     * @param string $docId ID dokumen ERP
     * @param string $kodeDokumen Jenis Dokumen BC
     * @param array $options Opsi tambahan
     * @return array [status, message, row_counts]
     */
    abstract public function integrate(string $nomorAju, string $docId, string $kodeDokumen, array $options = []): array;

    /**
     * Mapping satuan UoM dari ERP ke Standar Kode Satuan Bea Cukai
     */
    public function mapUom(string $uom): string
    {
        $uom = strtoupper(trim($uom));
        $map = [
            'PCS'    => 'PCE',
            'PIECE'  => 'PCE',
            'PIECES' => 'PCE',
            'PC'     => 'PCE',
            'EA'     => 'PCE',
            'EACH'   => 'PCE',
            'SET'    => 'SET',
            'ST'     => 'SET',
            'SHEET'  => 'ST',
            'KG'     => 'KGM',
            'KGS'    => 'KGM',
            'KILOGRAM' => 'KGM',
            'GR'     => 'GRM',
            'GRAM'   => 'GRM',
            'M'      => 'MTR',
            'METER'  => 'MTR',
            'ROLL'   => 'NRL',
            'RL'     => 'NRL',
            'BOX'    => 'BX',
            'CARTON' => 'CT',
            'CTN'    => 'CT',
            'PACK'   => 'PK',
            'BAG'    => 'BG',
            'CAN'    => 'CA',
            'DRUM'   => 'DR',
            'BOTTLE' => 'BO',
            'LITER'  => 'LTR',
            'LTR'    => 'LTR',
            'M3'     => 'MTQ',
            'M2'     => 'MTK',
            'PAIR'   => 'PR',
            'PRS'    => 'PR'
        ];
        return $map[$uom] ?? ($uom ?: 'PCE');
    }

    /**
     * Mapping kode kemasan dari ERP ke Standar Kode Kemasan Bea Cukai
     */
    public function mapPackage(string $pkg): string
    {
        $pkg = strtoupper(trim($pkg));
        $map = [
            'BOX'    => 'BX',
            'CARTON' => 'CT',
            'CTN'    => 'CT',
            'PALLET' => 'PX',
            'PACKAGE'=> 'PK',
            'BAG'    => 'BG',
            'DRUM'   => 'DR',
            'CASE'   => 'CS',
            'CRATE'  => 'CR',
            'CAN'    => 'CA'
        ];
        return $map[$pkg] ?? ($pkg ?: 'PX');
    }

    /**
     * Helper untuk insert atau update record staging
     */
    protected function insertStagingRow(string $table, array $data): bool
    {
        $cols = [];
        $vals = [];
        foreach ($data as $k => $v) {
            $cols[] = "`" . $this->db->real_escape_string($k) . "`";
            if ($v === null || $v === '') {
                $vals[] = "NULL";
            } elseif (is_numeric($v)) {
                $vals[] = "'" . $this->db->real_escape_string((string)$v) . "'";
            } else {
                $vals[] = "'" . $this->db->real_escape_string((string)$v) . "'";
            }
        }
        $sql = "INSERT INTO `$table` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
        return (bool)$this->db->query($sql);
    }
}

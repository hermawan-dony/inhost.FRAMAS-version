<?php
/**
 * CEISA 4.0 Database Connection
 * Terhubung ke Alibaba Cloud RDS MySQL (ceisa0400db)
 */
$host     = 'rm-d9j4584f890612j7vro.mysql.ap-southeast-5.rds.aliyuncs.com';
$port     = 63306;
$user     = 'ceisarwuser';
$password = 'CiT8T2#h4Jd#3wer$9d36hd';
$database = 'ceisa0400db';

// Hubungkan ke database RDS Alibaba Cloud
$koneksi = @mysqli_connect($host, $user, $password, $database, $port);

// Fallback ke port database lokal jika koneksi langsung tidak tersedia
if (!$koneksi) {
    $koneksi = @mysqli_connect('127.0.0.1', $user, $password, $database, 3306);
}

if (!$koneksi) {
    die('Connection Error: ' . mysqli_connect_error());
}

mysqli_set_charset($koneksi, "utf8mb4");

// Auto-migration: pastikan kolom allowed_docs tersedia di bc_ceisa_company
$chkColAllowedDocs = @$koneksi->query("SHOW COLUMNS FROM bc_ceisa_company LIKE 'allowed_docs'");
if ($chkColAllowedDocs && $chkColAllowedDocs->num_rows === 0) {
    @$koneksi->query("ALTER TABLE bc_ceisa_company ADD COLUMN allowed_docs VARCHAR(255) NULL AFTER company_roles");
}

/**
 * Master Katalog Dokumen Kepabeanan CEISA 4.0 / INSW
 */
if (!function_exists('ceisa_doc_catalog')) {
    function ceisa_doc_catalog() {
        return [
            // PPKEK (Kawasan Ekonomi Khusus)
            '611' => ['label' => 'PPKEK 6.1.1 (Pemasukan LDP)',       'group' => 'ppkek', 'short' => 'PPKEK 6.1.1', 'color' => '#722ed1'],
            '613' => ['label' => 'PPKEK 6.1.3 (Pemasukan TLDDP)',     'group' => 'ppkek', 'short' => 'PPKEK 6.1.3', 'color' => '#722ed1'],
            '631' => ['label' => 'PPKEK 6.3.1 (Pengeluaran ke LDP)', 'group' => 'ppkek', 'short' => 'PPKEK 6.3.1', 'color' => '#722ed1'],
            
            // TPB (Tempat Penimbunan Berikat)
            '25'  => ['label' => 'BC 2.5 (Pengeluaran TPB)',      'group' => 'tpb',   'short' => 'BC 2.5',       'color' => '#fa8c16'],
            '23'  => ['label' => 'BC 2.3 (Impor TPB)',            'group' => 'tpb',   'short' => 'BC 2.3',       'color' => '#1890ff'],
            '40'  => ['label' => 'BC 4.0 (Pemasukan TLDDP)',      'group' => 'tpb',   'short' => 'BC 4.0',       'color' => '#13c2c2'],
            '27'  => ['label' => 'BC 2.7 (TPB ke TPB)',           'group' => 'tpb',   'short' => 'BC 2.7',       'color' => '#2f54eb'],
            '41'  => ['label' => 'BC 4.1 (Pengeluaran TLDDP)',    'group' => 'tpb',   'short' => 'BC 4.1',       'color' => '#faad14'],
            '261' => ['label' => 'BC 2.6.1 (Subkon Luar)',        'group' => 'tpb',   'short' => 'BC 2.6.1',     'color' => '#eb2f96'],
            '262' => ['label' => 'BC 2.6.2 (Subkon Masuk)',       'group' => 'tpb',   'short' => 'BC 2.6.2',     'color' => '#a0d911'],
            
            // PLB / PDPLB (Pusat Logistik Berikat - Khusus, Bukan TPB Standar)
            '16'  => ['label' => 'BC 1.6 (Pemasukan PLB)',        'group' => 'plb',   'short' => 'BC 1.6',       'color' => '#52c41a'],
            '28'  => ['label' => 'BC 2.8 (Impor PLB)',            'group' => 'plb',   'short' => 'BC 2.8',       'color' => '#13c2c2'],
            '33'  => ['label' => 'BC 3.3 (Ekspor PLB)',           'group' => 'plb',   'short' => 'BC 3.3',       'color' => '#fa541c'],
            '331' => ['label' => 'BC 3.3.1 (P3BET PLB)',          'group' => 'plb',   'short' => 'BC 3.3.1',     'color' => '#fa8c16'],

            // PIB & PEB (Impor / Ekspor Umum)
            '20'  => ['label' => 'BC 2.0 (Impor PIB)',            'group' => 'pib',   'short' => 'BC 2.0',       'color' => '#2f54eb'],
            '30'  => ['label' => 'BC 3.0 (Ekspor PEB)',           'group' => 'peb',   'short' => 'BC 3.0',       'color' => '#fa8c16'],
        ];
    }
}

/**
 * Resolusi Hak Akses Dokumen BC Perusahaan
 * Mengutamakan allowed_docs kustom (bila sudah diset di kelola perusahaan),
 * atau menggunakan Preset Otomatis sesuai Role perusahaan:
 * - PPKEK/KEK: PPKEK (611, 613, 631) serta BC 2.5 dan BC 4.1
 * - TPB: Seluruh dokumen khusus TPB murni (23, 25, 261, 262, 27, 40, 41)
 *   Catatan: BC 1.6 & 2.8 (Masuk PLB) serta BC 3.3 & 3.3.1 (Keluar PLB) dikecualikan dari TPB standar.
 * - PLB: Dokumen khusus PLB / PDPLB (16, 28, 33, 331)
 * - PIB: Hanya BC 2.0
 * - PEB: Hanya BC 3.0
 * - Manifest: Kosong (tidak ada dokumen pabean default)
 */
if (!function_exists('ceisa_resolve_allowed_docs')) {
    function ceisa_resolve_allowed_docs($rolesStr, $allowedDocsStr = null) {
        $roles = array_filter(array_map('trim', explode(',', strtolower((string)$rolesStr))));
        if (empty($roles)) {
            $roles = ['tpb'];
        }

        // Jika sudah diatur secara manual / eksplisit di kelola perusahaan
        if ($allowedDocsStr !== null && trim((string)$allowedDocsStr) !== '' && trim((string)$allowedDocsStr) !== '*') {
            $val = trim((string)$allowedDocsStr);
            if (strtoupper($val) === 'NONE' || $val === '-') {
                return [];
            }
            $parsed = array_values(array_filter(array_map('trim', explode(',', $val))));
            // Jika perusahaan TPB murni (tanpa role PLB), kecualikan dokumen PLB/PDPLB: 16, 28, 33, 331
            if (!in_array('plb', $roles)) {
                $parsed = array_values(array_diff($parsed, ['16', '28', '33', '331']));
            }
            return $parsed;
        }

        $docs = [];
        // 1. PPKEK / KEK: Dokumen PPKEK (611, 613, 631) serta BC 2.5 dan BC 4.1
        if (in_array('ppkek', $roles) || in_array('kek', $roles)) {
            $docs = array_merge($docs, ['611', '613', '631', '25', '41']);
        }
        // 2. TPB: Dokumen TPB standar (16, 28, 33, 331 dikecualikan karena dokumen PLB / PDPLB)
        if (in_array('tpb', $roles)) {
            $docs = array_merge($docs, ['23', '25', '261', '262', '27', '40', '41']);
        }
        // 3. PLB / PDPLB: Dokumen khusus PLB
        if (in_array('plb', $roles)) {
            $docs = array_merge($docs, ['16', '28', '33', '331']);
        }
        // 4. PIB: hanya BC 2.0
        if (in_array('pib', $roles)) {
            $docs = array_merge($docs, ['20']);
        }
        // 5. PEB: hanya BC 3.0
        if (in_array('peb', $roles)) {
            $docs = array_merge($docs, ['30']);
        }
        // 6. Manifest murni: tidak ada dokumen pabean yang tampil
        if (in_array('manifest', $roles) && count($roles) === 1) {
            return [];
        }

        return array_values(array_unique($docs));
    }
}
?>

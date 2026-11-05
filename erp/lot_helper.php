<?php
/**
 * ============================================================================
 * ERP Enterprise Hub - Warehouse Lot Tracking & QR Generator Helper
 * ============================================================================
 * Mengelola pembuatan nomor lot unik, sinkronisasi penerimaan RI / PI ke bc_wh_lot,
 * parsing string payload QR Code kepabeanan berbasis Nomor AJU 26 Digit DJBC.
 * ============================================================================
 */

if (!function_exists('ensureWhLotTable')) {
    /**
     * Memastikan tabel bc_wh_lot tersedia pada database aktif (Auto-Heal Multi-Tenant)
     */
    function ensureWhLotTable($koneksi) {
        if (!$koneksi) return;
        $chk = $koneksi->query("SHOW TABLES LIKE 'bc_wh_lot'");
        if (!$chk || $chk->num_rows === 0) {
            $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_wh_lot` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `cmp_id` varchar(30) NOT NULL DEFAULT '',
                `lot_no` varchar(60) NOT NULL DEFAULT '',
                `ref_type` varchar(10) NOT NULL DEFAULT 'RI',
                `ref_no` varchar(60) NOT NULL DEFAULT '',
                `ref_date` date DEFAULT NULL,
                `nomor_aju` varchar(30) NOT NULL DEFAULT '',
                `bc` varchar(10) DEFAULT '',
                `no_bc` varchar(50) DEFAULT '',
                `tgl_bc` date DEFAULT NULL,
                `vendor` varchar(100) DEFAULT '',
                `kode_barang` varchar(50) NOT NULL DEFAULT '',
                `nama_barang` varchar(255) NOT NULL DEFAULT '',
                `sat` varchar(20) DEFAULT '',
                `qty_initial` decimal(18,4) NOT NULL DEFAULT 0.0000,
                `qty_remaining` decimal(18,4) NOT NULL DEFAULT 0.0000,
                `lokasi_rak` varchar(50) DEFAULT '',
                `qr_payload` text DEFAULT NULL,
                `status` varchar(20) DEFAULT 'ACTIVE',
                `created_by` varchar(50) DEFAULT '',
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_cmp_lot` (`cmp_id`,`lot_no`),
                KEY `idx_cmp_ref` (`cmp_id`,`ref_type`,`ref_no`),
                KEY `idx_cmp_aju` (`cmp_id`,`nomor_aju`),
                KEY `idx_cmp_item` (`cmp_id`,`kode_barang`),
                KEY `idx_cmp_status` (`cmp_id`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }
}
if (isset($koneksi) && $koneksi instanceof mysqli) {
    ensureWhLotTable($koneksi);
}

if (!function_exists('generateWhLotNumber')) {
    /**
     * Menghasilkan nomor referensi penerimaan unik: LOT-YYYYMM-XXXXX
     */
    function generateWhLotNumber($koneksi, $cmp_id) {
        $prefix = 'LOT-' . date('Ym') . '-';
        $escPrefix = $koneksi->real_escape_string($prefix);
        $escCmp = $koneksi->real_escape_string($cmp_id);
        
        $q = $koneksi->query("SELECT lot_no FROM bc_wh_lot WHERE cmp_id = '{$escCmp}' AND lot_no LIKE '{$escPrefix}%' ORDER BY lot_no DESC LIMIT 1");
        $nextNum = 1;
        if ($q && $row = $q->fetch_assoc()) {
            $lastPart = substr($row['lot_no'], strlen($prefix));
            $nextNum = intval($lastPart) + 1;
        }
        return $prefix . str_pad($nextNum, 5, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('buildQrPayload')) {
    /**
     * Membangun payload string QR Kepabeanan CEISA 4.0:
     * AJU|<NOMOR_AJU>|<NO_BC>|<KODE_BARANG>|<NO_REF>|<LOT_ID>
     */
    function buildQrPayload($kode_barang, $ref_no, $nomor_aju, $lot_id, $no_bc = '') {
        $kd  = trim(str_replace('|', '', $kode_barang));
        $ref = trim(str_replace('|', '', $ref_no));
        $aju = trim(str_replace('|', '', $nomor_aju));
        $bc  = trim(str_replace('|', '', $no_bc));
        return "AJU|{$aju}|{$bc}|{$kd}|{$ref}|{$lot_id}";
    }
}

if (!function_exists('syncRiToLots')) {
    /**
     * Sinkronisasi dokumen Receive Item (RI) ke tabel bc_wh_lot
     */
    function syncRiToLots($koneksi, $ri_id) {
        $escId = $koneksi->real_escape_string($ri_id);
        $qHdr = $koneksi->query("SELECT * FROM bc_ri_hdr WHERE id = '{$escId}' LIMIT 1");
        if (!$qHdr || $qHdr->num_rows === 0) return false;
        $hdr = $qHdr->fetch_assoc();

        $cmp_id = $hdr['cmp_id'];
        $ref_no = $hdr['ri_no'];
        $ref_date = $hdr['ri_dt'];
        $nomor_aju = $hdr['no_aju'];
        $bc = $hdr['bc'];
        $no_bc = $hdr['no_bc'];
        $tgl_bc = !empty($hdr['tgl_bc']) ? $hdr['tgl_bc'] : null;
        $vendor = $hdr['vendor'];
        $gudang = $hdr['gudang'] ?: 'UTAMA';
        $createdBy = $hdr['set_by'] ?: 'SYSTEM';

        $qDtl = $koneksi->query("SELECT * FROM bc_ri_dtl WHERE id_hdr = '{$escId}' ORDER BY seq ASC");
        if (!$qDtl) return false;

        $createdLots = [];
        while ($dtl = $qDtl->fetch_assoc()) {
            $kode_barang = $dtl['kode_barang'];
            $nama_barang = $dtl['uraian'];
            $sat = $dtl['sat'];
            $qty = floatval($dtl['qty_aktual'] > 0 ? $dtl['qty_aktual'] : $dtl['qty']);
            $rak = $dtl['rack'] ?: $gudang;
            if ($qty <= 0) continue;

            $escKd = $koneksi->real_escape_string($kode_barang);
            $escRef = $koneksi->real_escape_string($ref_no);
            $escCmp = $koneksi->real_escape_string($cmp_id);

            // Cek apakah lot untuk item RI ini sudah pernah dibuat
            $qCheck = $koneksi->query("SELECT id, lot_no FROM bc_wh_lot WHERE cmp_id = '{$escCmp}' AND ref_type = 'RI' AND ref_no = '{$escRef}' AND kode_barang = '{$escKd}' LIMIT 1");
            if ($qCheck && $row = $qCheck->fetch_assoc()) {
                // Update sisa jika perlu
                $createdLots[] = $row['lot_no'];
                continue;
            }

            $lot_no = generateWhLotNumber($koneksi, $cmp_id);
            $escLot = $koneksi->real_escape_string($lot_no);
            $escNama = $koneksi->real_escape_string($nama_barang);
            $escSat = $koneksi->real_escape_string($sat);
            $escAju = $koneksi->real_escape_string($nomor_aju);
            $escBc = $koneksi->real_escape_string($bc);
            $escNoBc = $koneksi->real_escape_string($no_bc);
            $escVendor = $koneksi->real_escape_string($vendor);
            $escRak = $koneksi->real_escape_string($rak);
            $escUser = $koneksi->real_escape_string($createdBy);
            $tglBcSql = $tgl_bc ? "'{$tgl_bc}'" : "NULL";
            $refDtSql = $ref_date ? "'{$ref_date}'" : "CURRENT_DATE";

            $insSql = "INSERT INTO bc_wh_lot 
                (cmp_id, lot_no, ref_type, ref_no, ref_date, nomor_aju, bc, no_bc, tgl_bc, vendor, kode_barang, nama_barang, sat, qty_initial, qty_remaining, lokasi_rak, qr_payload, status, created_by)
                VALUES 
                ('{$escCmp}', '{$escLot}', 'RI', '{$escRef}', {$refDtSql}, '{$escAju}', '{$escBc}', '{$escNoBc}', {$tglBcSql}, '{$escVendor}', '{$escKd}', '{$escNama}', '{$escSat}', {$qty}, {$qty}, '{$escRak}', '', 'ACTIVE', '{$escUser}')";
            
            if ($koneksi->query($insSql)) {
                $newLotId = $koneksi->insert_id;
                $payload = buildQrPayload($kode_barang, $ref_no, $nomor_aju, $newLotId, $no_bc);
                $escPayload = $koneksi->real_escape_string($payload);
                $koneksi->query("UPDATE bc_wh_lot SET qr_payload = '{$escPayload}' WHERE id = {$newLotId}");
                $createdLots[] = $lot_no;
            }
        }
        return $createdLots;
    }
}

if (!function_exists('syncPiToLots')) {
    /**
     * Sinkronisasi dokumen Purchase Invoice (PI) ke tabel bc_wh_lot
     * (Untuk perusahaan yang langsung mencatat faktur tanpa form RI terpisah)
     */
    function syncPiToLots($koneksi, $pi_id) {
        $escId = $koneksi->real_escape_string($pi_id);
        $qHdr = $koneksi->query("SELECT * FROM bc_pi_hdr WHERE id = '{$escId}' LIMIT 1");
        if (!$qHdr || $qHdr->num_rows === 0) return false;
        $hdr = $qHdr->fetch_assoc();

        $cmp_id = $hdr['cmp_id'];
        $ref_no = $hdr['pi_no'];
        $ref_date = $hdr['pi_dt'];
        $nomor_aju = $hdr['no_aju'];
        $bc = $hdr['bc'];
        $no_bc = $hdr['no_bc'];
        $tgl_bc = !empty($hdr['tgl_bc']) ? $hdr['tgl_bc'] : null;
        $vendor = $hdr['vendor'];
        $gudang = $hdr['gudang'] ?: 'UTAMA';
        $createdBy = $hdr['set_by'] ?: 'SYSTEM';
        $linkedRi = trim($hdr['ri_no'] ?? '');

        // Jika PI ini ditarik dari RI yang sudah ada, jangan buat lot ganda
        if (!empty($linkedRi)) {
            $escRi = $koneksi->real_escape_string($linkedRi);
            $checkExistingRiLot = $koneksi->query("SELECT id FROM bc_wh_lot WHERE cmp_id = '{$cmp_id}' AND ref_type = 'RI' AND ref_no = '{$escRi}' LIMIT 1");
            if ($checkExistingRiLot && $checkExistingRiLot->num_rows > 0) {
                return false; // Sudah ada lot dari RI, tidak perlu digandakan
            }
        }

        $qDtl = $koneksi->query("SELECT * FROM bc_pi_dtl WHERE id_hdr = '{$escId}' ORDER BY seq ASC");
        if (!$qDtl) return false;

        $createdLots = [];
        while ($dtl = $qDtl->fetch_assoc()) {
            $kode_barang = $dtl['kode_barang'];
            $nama_barang = $dtl['uraian'];
            $sat = $dtl['sat'];
            $qty = floatval($dtl['qty']);
            $rak = $gudang;
            if ($qty <= 0) continue;

            $escKd = $koneksi->real_escape_string($kode_barang);
            $escRef = $koneksi->real_escape_string($ref_no);
            $escCmp = $koneksi->real_escape_string($cmp_id);

            // Cek apakah lot untuk item PI ini sudah pernah dibuat
            $qCheck = $koneksi->query("SELECT id, lot_no FROM bc_wh_lot WHERE cmp_id = '{$escCmp}' AND ref_type = 'PI' AND ref_no = '{$escRef}' AND kode_barang = '{$escKd}' LIMIT 1");
            if ($qCheck && $row = $qCheck->fetch_assoc()) {
                $createdLots[] = $row['lot_no'];
                continue;
            }

            $lot_no = generateWhLotNumber($koneksi, $cmp_id);
            $escLot = $koneksi->real_escape_string($lot_no);
            $escNama = $koneksi->real_escape_string($nama_barang);
            $escSat = $koneksi->real_escape_string($sat);
            $escAju = $koneksi->real_escape_string($nomor_aju);
            $escBc = $koneksi->real_escape_string($bc);
            $escNoBc = $koneksi->real_escape_string($no_bc);
            $escVendor = $koneksi->real_escape_string($vendor);
            $escRak = $koneksi->real_escape_string($rak);
            $escUser = $koneksi->real_escape_string($createdBy);
            $tglBcSql = $tgl_bc ? "'{$tgl_bc}'" : "NULL";
            $refDtSql = $ref_date ? "'{$ref_date}'" : "CURRENT_DATE";

            $insSql = "INSERT INTO bc_wh_lot 
                (cmp_id, lot_no, ref_type, ref_no, ref_date, nomor_aju, bc, no_bc, tgl_bc, vendor, kode_barang, nama_barang, sat, qty_initial, qty_remaining, lokasi_rak, qr_payload, status, created_by)
                VALUES 
                ('{$escCmp}', '{$escLot}', 'PI', '{$escRef}', {$refDtSql}, '{$escAju}', '{$escBc}', '{$escNoBc}', {$tglBcSql}, '{$escVendor}', '{$escKd}', '{$escNama}', '{$escSat}', {$qty}, {$qty}, '{$escRak}', '', 'ACTIVE', '{$escUser}')";
            
            if ($koneksi->query($insSql)) {
                $newLotId = $koneksi->insert_id;
                $payload = buildQrPayload($kode_barang, $ref_no, $nomor_aju, $newLotId, $no_bc);
                $escPayload = $koneksi->real_escape_string($payload);
                $koneksi->query("UPDATE bc_wh_lot SET qr_payload = '{$escPayload}' WHERE id = {$newLotId}");
                $createdLots[] = $lot_no;
            }
        }
        return $createdLots;
    }
}

if (!function_exists('parseAndResolveLot')) {
    /**
     * Mengurai barcode/QR scan text dan mencari lot yang cocok di bc_wh_lot
     */
    function parseAndResolveLot($koneksi, $cmp_id, $scanText) {
        $scanText = trim($scanText);
        if (empty($scanText)) return null;

        $escCmp = $koneksi->real_escape_string($cmp_id);
        $escText = $koneksi->real_escape_string($scanText);

        // 1. Coba cari exact match lot_no
        $q = $koneksi->query("SELECT * FROM bc_wh_lot WHERE cmp_id = '{$escCmp}' AND lot_no = '{$escText}' LIMIT 1");
        if ($q && $r = $q->fetch_assoc()) return $r;

        // 2. Coba cari exact match qr_payload
        $q = $koneksi->query("SELECT * FROM bc_wh_lot WHERE cmp_id = '{$escCmp}' AND qr_payload = '{$escText}' LIMIT 1");
        if ($q && $r = $q->fetch_assoc()) return $r;

        // 3. Coba parse jika format AJU|<NOMOR_AJU>|<NO_BC>|<KODE_BARANG>|<REF_NO>|<ID>
        if (strpos($scanText, 'AJU|') === 0) {
            $parts = explode('|', $scanText);
            if (count($parts) >= 6) {
                $lotId = intval($parts[5]);
                if ($lotId > 0) {
                    $q = $koneksi->query("SELECT * FROM bc_wh_lot WHERE cmp_id = '{$escCmp}' AND id = {$lotId} LIMIT 1");
                    if ($q && $r = $q->fetch_assoc()) return $r;
                }
            }
        }

        // 3b. Coba parse jika format lama: LOT|<KODE>|<REF>|<AJU>|<ID>
        if (strpos($scanText, 'LOT|') === 0) {
            $parts = explode('|', $scanText);
            if (count($parts) >= 5) {
                $lotId = intval($parts[4]);
                if ($lotId > 0) {
                    $q = $koneksi->query("SELECT * FROM bc_wh_lot WHERE cmp_id = '{$escCmp}' AND id = {$lotId} LIMIT 1");
                    if ($q && $r = $q->fetch_assoc()) return $r;
                }
            }
        }

        // 4. Fallback cari berdasarkan kode barang dengan sisa lot terbanyak/FIFO
        $q = $koneksi->query("SELECT * FROM bc_wh_lot WHERE cmp_id = '{$escCmp}' AND kode_barang = '{$escText}' AND qty_remaining > 0 AND status = 'ACTIVE' ORDER BY ref_date ASC, id ASC LIMIT 1");
        if ($q && $r = $q->fetch_assoc()) return $r;

        return null;
    }
}

if (!function_exists('deleteWhLotsByRef')) {
    /**
     * Menghapus lot penerimaan jika dokumen acuan (RI/PI) dihapus.
     * Hanya menghapus lot yang belum terpakai (qty_remaining == qty_initial).
     */
    function deleteWhLotsByRef($koneksi, $cmp_id, $ref_type, $ref_no) {
        $escCmp = $koneksi->real_escape_string($cmp_id);
        $escType = $koneksi->real_escape_string($ref_type);
        $escRef = $koneksi->real_escape_string($ref_no);

        // Cek apakah ada yang sudah terpakai
        $check = $koneksi->query("SELECT id FROM bc_wh_lot WHERE cmp_id = '{$escCmp}' AND ref_type = '{$escType}' AND ref_no = '{$escRef}' AND qty_remaining < qty_initial LIMIT 1");
        if ($check && $check->num_rows > 0) {
            // Sudah ada yang terpakai di issue bahan baku, tandai saja
            $koneksi->query("UPDATE bc_wh_lot SET status = 'DEPLETED' WHERE cmp_id = '{$escCmp}' AND ref_type = '{$escType}' AND ref_no = '{$escRef}'");
            return false;
        }

        $koneksi->query("DELETE FROM bc_wh_lot WHERE cmp_id = '{$escCmp}' AND ref_type = '{$escType}' AND ref_no = '{$escRef}'");
        return true;
    }
}

if (!function_exists('getActiveLots')) {
    /**
     * Mengambil daftar lot yang masih aktif dan memiliki sisa stok
     */
    function getActiveLots($koneksi, $cmp_id, $search = '', $limit = 50) {
        $escCmp = $koneksi->real_escape_string($cmp_id);
        $limit = intval($limit);
        if ($limit <= 0) $limit = 50;

        $where = "WHERE cmp_id = '{$escCmp}' AND qty_remaining > 0 AND status = 'ACTIVE'";
        if (!empty($search)) {
            $escS = $koneksi->real_escape_string($search);
            $where .= " AND (lot_no LIKE '%{$escS}%' OR kode_barang LIKE '%{$escS}%' OR nama_barang LIKE '%{$escS}%' OR ref_no LIKE '%{$escS}%' OR nomor_aju LIKE '%{$escS}%')";
        }

        $q = $koneksi->query("SELECT * FROM bc_wh_lot {$where} ORDER BY ref_date DESC, id DESC LIMIT {$limit}");
        $res = [];
        if ($q) {
            while ($r = $q->fetch_assoc()) {
                $res[] = $r;
            }
        }
        return $res;
    }
}


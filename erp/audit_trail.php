<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Universal Audit Trail & Change Logger
 * Menyimpan riwayat perubahan & penghapusan data secara terperinci:
 * 1. APA YANG DIGANTI (Field Header: Nilai Lama -> Nilai Baru, Item: Tambah / Hapus / Ubah Rincian)
 * 2. APA YANG DIHAPUS (Snapshot lengkap data transaksi & rincian item sebelum dihapus)
 * 3. OLEH SIAPA (User ID, User Name / Akun Pengguna, dan IP Address)
 * 4. KAPAN (Waktu Indonesia Barat / WIB Asia/Jakarta)
 */

if (!function_exists('getAuditClientIp')) {
    function getAuditClientIp() {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($headers as $h) {
            if (!empty($_SERVER[$h])) {
                $ips = explode(',', $_SERVER[$h]);
                foreach ($ips as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return $ip;
                    }
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}

if (!function_exists('getAuditCurrentTimeWib')) {
    function getAuditCurrentTimeWib() {
        try {
            $dt = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
            return $dt->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return date('Y-m-d H:i:s');
        }
    }
}

if (!function_exists('ensureAuditLogTable')) {
    function ensureAuditLogTable($koneksi) {
        if (!$koneksi) return;
        try {
            $check = $koneksi->query("SHOW TABLES LIKE 'bc_erp_audit_log'");
            if (!$check || $check->num_rows === 0) {
                $sql = "CREATE TABLE IF NOT EXISTS `bc_erp_audit_log` (
                    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    `cmp_id` varchar(30) NOT NULL DEFAULT '',
                    `module` varchar(50) NOT NULL DEFAULT '',
                    `doc_no` varchar(100) NOT NULL DEFAULT '',
                    `doc_id` varchar(50) NOT NULL DEFAULT '',
                    `action_type` varchar(20) NOT NULL DEFAULT '',
                    `summary` text DEFAULT NULL,
                    `changes_json` longtext DEFAULT NULL,
                    `old_snapshot` longtext DEFAULT NULL,
                    `new_snapshot` longtext DEFAULT NULL,
                    `user_id` varchar(50) NOT NULL DEFAULT '',
                    `user_name` varchar(100) NOT NULL DEFAULT '',
                    `ip_address` varchar(45) NOT NULL DEFAULT '',
                    `created_at` datetime NOT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_cmp_mod_doc` (`cmp_id`, `module`, `doc_no`),
                    KEY `idx_doc_id` (`doc_id`),
                    KEY `idx_created_at` (`created_at`),
                    KEY `idx_user` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                $koneksi->query($sql);
            }
        } catch (Throwable $e) {}
    }
}

if (isset($koneksi) && $koneksi instanceof mysqli) {
    ensureAuditLogTable($koneksi);
}

if (!function_exists('recordAuditLog')) {
    /**
     * Catat entri riwayat perubahan / penghapusan data ke tabel audit
     */
    function recordAuditLog($koneksi, array $data) {
        if (!$koneksi) return false;
        ensureAuditLogTable($koneksi);

        $cmpId      = trim((string)($data['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? ''))));
        $module     = strtoupper(trim((string)($data['module'] ?? 'ERP')));
        $docNo      = trim((string)($data['doc_no'] ?? ''));
        $docId      = trim((string)($data['doc_id'] ?? ''));
        $actionType = strtoupper(trim((string)($data['action_type'] ?? 'UPDATE')));
        $summary    = trim((string)($data['summary'] ?? ''));
        
        $changesJson = is_array($data['changes_json'] ?? null) 
            ? json_encode($data['changes_json'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) 
            : (string)($data['changes_json'] ?? '');

        $oldSnapshot = is_array($data['old_snapshot'] ?? null) 
            ? json_encode($data['old_snapshot'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) 
            : (string)($data['old_snapshot'] ?? '');

        $newSnapshot = is_array($data['new_snapshot'] ?? null) 
            ? json_encode($data['new_snapshot'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) 
            : (string)($data['new_snapshot'] ?? '');

        $userId = trim((string)($data['user_id'] ?? ($_SESSION['user_id'] ?? ($_SESSION['username'] ?? 'SYSTEM'))));
        $userName = trim((string)($data['user_name'] ?? ($_SESSION['user_name'] ?? ($_SESSION['nama_lengkap'] ?? $userId))));
        $ip = trim((string)($data['ip_address'] ?? getAuditClientIp()));
        $createdAt = getAuditCurrentTimeWib();

        $stmt = $koneksi->prepare("INSERT INTO bc_erp_audit_log (
            cmp_id, module, doc_no, doc_id, action_type, summary, changes_json, old_snapshot, new_snapshot, user_id, user_name, ip_address, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        if ($stmt) {
            $stmt->bind_param("sssssssssssss",
                $cmpId, $module, $docNo, $docId, $actionType, $summary, $changesJson, $oldSnapshot, $newSnapshot, $userId, $userName, $ip, $createdAt
            );
            $res = $stmt->execute();
            $stmt->close();
            return $res;
        } else {
            // Fallback escape query jika prepare gagal
            $escCmp   = $koneksi->real_escape_string($cmpId);
            $escMod   = $koneksi->real_escape_string($module);
            $escDocNo = $koneksi->real_escape_string($docNo);
            $escDocId = $koneksi->real_escape_string($docId);
            $escAct   = $koneksi->real_escape_string($actionType);
            $escSum   = $koneksi->real_escape_string($summary);
            $escChg   = $koneksi->real_escape_string($changesJson);
            $escOld   = $koneksi->real_escape_string($oldSnapshot);
            $escNew   = $koneksi->real_escape_string($newSnapshot);
            $escUid   = $koneksi->real_escape_string($userId);
            $escUnm   = $koneksi->real_escape_string($userName);
            $escIp    = $koneksi->real_escape_string($ip);

            $sql = "INSERT INTO bc_erp_audit_log (
                cmp_id, module, doc_no, doc_id, action_type, summary, changes_json, old_snapshot, new_snapshot, user_id, user_name, ip_address, created_at
            ) VALUES (
                '$escCmp', '$escMod', '$escDocNo', '$escDocId', '$escAct', '$escSum', '$escChg', '$escOld', '$escNew', '$escUid', '$escUnm', '$escIp', '$createdAt'
            )";
            return (bool)@$koneksi->query($sql);
        }
    }
}

if (!function_exists('diffAuditDocument')) {
    /**
     * Membandingkan data lama vs data baru dan menghasilkan rincian perbedaan terstruktur
     * 
     * @param array $oldHdr
     * @param array $newHdr
     * @param array $oldItems
     * @param array $newItems
     * @param array $headerLabels [field_name => Label Deskripsi]
     * @param string $itemKeyField 'kode_barang'
     * @return array
     */
    function diffAuditDocument(array $oldHdr, array $newHdr, array $oldItems, array $newItems, array $headerLabels = [], $itemKeyField = 'kode_barang') {
        $changes = [
            'header'         => [],
            'items_added'    => [],
            'items_deleted'  => [],
            'items_modified' => []
        ];
        $summaryParts = [];

        // 1. Cek Header Fields
        foreach ($headerLabels as $field => $label) {
            $oldVal = isset($oldHdr[$field]) ? trim((string)$oldHdr[$field]) : '';
            $newVal = isset($newHdr[$field]) ? trim((string)$newHdr[$field]) : '';

            // Normalkan float/decimal agar '100.00' == '100'
            if (is_numeric($oldVal) && is_numeric($newVal)) {
                if (abs((float)$oldVal - (float)$newVal) < 0.0001) {
                    continue;
                }
                $oldValStr = number_format((float)$oldVal, 2, '.', ',');
                $newValStr = number_format((float)$newVal, 2, '.', ',');
            } else {
                if ($oldVal === $newVal) {
                    continue;
                }
                $oldValStr = $oldVal !== '' ? $oldVal : '(kosong)';
                $newValStr = $newVal !== '' ? $newVal : '(kosong)';
            }

            $changes['header'][$field] = [
                'label' => $label,
                'old'   => $oldVal,
                'new'   => $newVal
            ];
            $summaryParts[] = "$label: {$oldValStr} ➔ {$newValStr}";
        }

        // 2. Cek Detail Items
        // Bangun index item lama berdasarkan kode_barang (atau combo kode_barang + seq)
        $oldMap = [];
        foreach ($oldItems as $idx => $it) {
            $key = trim((string)($it[$itemKeyField] ?? ''));
            if ($key === '') $key = "row_" . ($idx + 1);
            // Simpan beberapa item dengan kode yang sama
            $oldMap[$key][] = $it;
        }

        $newMap = [];
        foreach ($newItems as $idx => $it) {
            $key = trim((string)($it[$itemKeyField] ?? ''));
            if ($key === '') $key = "row_" . ($idx + 1);
            $newMap[$key][] = $it;
        }

        // Cek item yang ditambahkan atau dimodifikasi
        foreach ($newMap as $key => $newList) {
            if (!isset($oldMap[$key])) {
                // Seluruh item dengan kode ini baru ditambahkan
                foreach ($newList as $it) {
                    $nm = $it['nama_barang'] ?? ($it['uraian'] ?? $key);
                    $qty = (float)($it['qty'] ?? 0);
                    $sat = $it['satuan'] ?? ($it['sat'] ?? '');
                    $hrg = (float)($it['harga'] ?? ($it['price'] ?? 0));
                    $changes['items_added'][] = [
                        'key'         => $key,
                        'nama_barang' => $nm,
                        'qty'         => $qty,
                        'satuan'      => $sat,
                        'harga'       => $hrg,
                        'total'       => (float)($it['total'] ?? ($it['amount'] ?? ($qty * $hrg)))
                    ];
                    $summaryParts[] = "Tambah Barang: [{$key}] {$nm} (Qty: {$qty} {$sat})";
                }
            } else {
                // Item ada di kedua snapshot, bandingkan per indeks
                $oldList = $oldMap[$key];
                $maxLen = max(count($oldList), count($newList));
                for ($i = 0; $i < $maxLen; $i++) {
                    if (!isset($oldList[$i])) {
                        // Tambahan
                        $it = $newList[$i];
                        $nm = $it['nama_barang'] ?? ($it['uraian'] ?? $key);
                        $qty = (float)($it['qty'] ?? 0);
                        $sat = $it['satuan'] ?? ($it['sat'] ?? '');
                        $changes['items_added'][] = [
                            'key'         => $key,
                            'nama_barang' => $nm,
                            'qty'         => $qty,
                            'satuan'      => $sat,
                            'harga'       => (float)($it['harga'] ?? ($it['price'] ?? 0))
                        ];
                        $summaryParts[] = "Tambah Barang: [{$key}] {$nm} (Qty: {$qty} {$sat})";
                    } elseif (!isset($newList[$i])) {
                        // Dihapus
                        $it = $oldList[$i];
                        $nm = $it['nama_barang'] ?? ($it['uraian'] ?? $key);
                        $qty = (float)($it['qty'] ?? 0);
                        $sat = $it['satuan'] ?? ($it['sat'] ?? '');
                        $changes['items_deleted'][] = [
                            'key'         => $key,
                            'nama_barang' => $nm,
                            'qty'         => $qty,
                            'satuan'      => $sat
                        ];
                        $summaryParts[] = "Hapus Barang: [{$key}] {$nm} (Qty: {$qty} {$sat})";
                    } else {
                        // Bandingkan field-field penting
                        $o = $oldList[$i];
                        $n = $newList[$i];
                        $itemDiff = [];

                        $compareFields = [
                            'qty'        => 'Qty',
                            'harga'      => 'Harga Satuan',
                            'price'      => 'Harga Satuan',
                            'diskon'     => 'Diskon',
                            'disc'       => 'Diskon',
                            'total'      => 'Total',
                            'amount'     => 'Total',
                            'satuan'     => 'Satuan',
                            'sat'        => 'Satuan',
                            'color_cd'   => 'Warna',
                            'size_cd'    => 'Ukuran',
                            'note'       => 'Catatan'
                        ];

                        foreach ($compareFields as $f => $lbl) {
                            if (!isset($o[$f]) && !isset($n[$f])) continue;
                            $ov = trim((string)($o[$f] ?? ''));
                            $nv = trim((string)($n[$f] ?? ''));

                            if (is_numeric($ov) && is_numeric($nv)) {
                                if (abs((float)$ov - (float)$nv) < 0.0001) continue;
                                $itemDiff[$lbl] = ['old' => (float)$ov, 'new' => (float)$nv];
                            } else {
                                if ($ov === $nv) continue;
                                $itemDiff[$lbl] = ['old' => $ov, 'new' => $nv];
                            }
                        }

                        if (!empty($itemDiff)) {
                            $nm = $n['nama_barang'] ?? ($n['uraian'] ?? $key);
                            $changes['items_modified'][] = [
                                'key'         => $key,
                                'nama_barang' => $nm,
                                'diff'        => $itemDiff
                            ];
                            $diffStrs = [];
                            foreach ($itemDiff as $dlbl => $dv) {
                                $diffStrs[] = "{$dlbl}: {$dv['old']} ➔ {$dv['new']}";
                            }
                            $summaryParts[] = "Ubah [{$key}]: " . implode(', ', $diffStrs);
                        }
                    }
                }
            }
        }

        // Cek item yang seluruhnya dihapus dari daftar baru
        foreach ($oldMap as $key => $oldList) {
            if (!isset($newMap[$key])) {
                foreach ($oldList as $it) {
                    $nm = $it['nama_barang'] ?? ($it['uraian'] ?? $key);
                    $qty = (float)($it['qty'] ?? 0);
                    $sat = $it['satuan'] ?? ($it['sat'] ?? '');
                    $changes['items_deleted'][] = [
                        'key'         => $key,
                        'nama_barang' => $nm,
                        'qty'         => $qty,
                        'satuan'      => $sat
                    ];
                    $summaryParts[] = "Hapus Barang: [{$key}] {$nm} (Qty: {$qty} {$sat})";
                }
            }
        }

        $hasChanges = !empty($changes['header']) 
                   || !empty($changes['items_added']) 
                   || !empty($changes['items_deleted']) 
                   || !empty($changes['items_modified']);

        $summary = !empty($summaryParts) ? implode('; ', $summaryParts) : 'Tidak ada rincian data yang diubah.';

        return [
            'has_changes' => $hasChanges,
            'summary'     => $summary,
            'changes'     => $changes
        ];
    }
}

if (!function_exists('handleAuditLogApi')) {
    /**
     * Router standar backend AJAX untuk membaca data audit log
     */
    function handleAuditLogApi($koneksi, $sessionCmpId) {
        ensureAuditLogTable($koneksi);
        $action = $_REQUEST['action'] ?? '';
        $escCmp = $koneksi->real_escape_string($sessionCmpId);

        if ($action === 'audit_list') {
            header('Content-Type: application/json; charset=utf-8');
            $docNo  = trim($_GET['doc_no'] ?? '');
            $module = strtoupper(trim($_GET['module'] ?? ''));
            $page   = max(1, (int)($_GET['page'] ?? 1));
            $limit  = max(10, min(100, (int)($_GET['limit'] ?? 30)));
            $offset = ($page - 1) * $limit;

            $where = [];
            if (!empty($sessionCmpId)) {
                $where[] = "(cmp_id = '$escCmp' OR cmp_id = '')";
            }
            if (!empty($module)) {
                $escMod = $koneksi->real_escape_string($module);
                $where[] = "module = '$escMod'";
            }
            if (!empty($docNo)) {
                $escDoc = $koneksi->real_escape_string($docNo);
                $where[] = "doc_no = '$escDoc'";
            }

            $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

            $countSql = "SELECT COUNT(1) as total FROM bc_erp_audit_log $whereSql";
            $total = 0;
            $cQ = $koneksi->query($countSql);
            if ($cQ && $cRow = $cQ->fetch_assoc()) $total = (int)$cRow['total'];

            $sql = "SELECT id, cmp_id, module, doc_no, doc_id, action_type, summary, user_id, user_name, ip_address, created_at,
                           IF(changes_json IS NOT NULL AND changes_json != '', 1, 0) as has_changes_json,
                           IF(old_snapshot IS NOT NULL AND old_snapshot != '', 1, 0) as has_old_snapshot,
                           IF(new_snapshot IS NOT NULL AND new_snapshot != '', 1, 0) as has_new_snapshot
                    FROM bc_erp_audit_log
                    $whereSql
                    ORDER BY created_at DESC, id DESC
                    LIMIT $offset, $limit";

            $data = [];
            $res = $koneksi->query($sql);
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $data[] = $r;
                }
            }

            echo json_encode([
                'status' => 'OK',
                'data'   => $data,
                'total'  => $total,
                'page'   => $page,
                'limit'  => $limit
            ]);
            exit;
        }

        if ($action === 'audit_detail') {
            header('Content-Type: application/json; charset=utf-8');
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['status' => 'ERROR', 'message' => 'ID Audit Log tidak valid']);
                exit;
            }

            $q = $koneksi->query("SELECT * FROM bc_erp_audit_log WHERE id = $id LIMIT 1");
            if (!$q || $q->num_rows === 0) {
                echo json_encode(['status' => 'ERROR', 'message' => 'Catatan log tidak ditemukan']);
                exit;
            }

            $row = $q->fetch_assoc();
            $row['changes_decoded'] = !empty($row['changes_json']) ? json_decode($row['changes_json'], true) : null;
            $row['old_decoded']     = !empty($row['old_snapshot']) ? json_decode($row['old_snapshot'], true) : null;
            $row['new_decoded']     = !empty($row['new_snapshot']) ? json_decode($row['new_snapshot'], true) : null;

            echo json_encode(['status' => 'OK', 'data' => $row]);
            exit;
        }
    }
}

if (!function_exists('renderAuditLogModalHtml')) {
    /**
     * Render komponen UI Modal Audit Log siap pakai
     */
    function renderAuditLogModalHtml() {
        return <<<'HTML'
<!-- Modal Audit Log (Riwayat Perubahan & Penghapusan Data) -->
<div id="modalAuditLog" class="ant-modal-mask" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); backdrop-filter:blur(2px); align-items:center; justify-content:center;">
    <div class="ant-modal-content" style="background:var(--bg-card, #fff); color:var(--text-color, #262626); border-radius:8px; width:920px; max-width:96vw; max-height:92vh; display:flex; flex-direction:column; box-shadow:0 12px 32px rgba(0,0,0,0.3); border:1px solid var(--border-color, #d9d9d9); overflow:hidden;">
        
        <!-- Modal Header -->
        <div style="padding:14px 20px; border-bottom:1px solid var(--border-color, #f0f0f0); display:flex; align-items:center; justify-content:space-between; background:var(--bg-table-th, #fafafa);">
            <div style="display:flex; align-items:center; gap:10px;">
                <div style="width:32px; height:32px; border-radius:6px; background:#e6f7ff; color:#1890ff; display:flex; align-items:center; justify-content:center; font-size:16px;">
                    <i class="fa fa-history"></i>
                </div>
                <div>
                    <h3 style="margin:0; font-size:15px; font-weight:600; color:var(--text-color, #262626);" id="auditModalTitle">Audit Trail: Riwayat Perubahan & Penghapusan Data</h3>
                    <div style="font-size:11px; color:var(--text-muted, #8c8c8c);" id="auditModalSubtitle">Mencatat apa yang diganti, apa yang dihapus, oleh siapa dan kapan</div>
                </div>
            </div>
            <div style="display:flex; align-items:center; gap:8px;">
                <button type="button" class="ant-btn ant-btn-default ant-btn-sm" onclick="refreshCurrentAuditLog()" title="Muat Ulang Log"><i class="fa fa-refresh"></i> Refresh</button>
                <button type="button" class="ant-btn ant-btn-default ant-btn-sm" onclick="closeAuditLogModal()" style="font-size:16px; line-height:1; border:none; background:transparent; cursor:pointer;">&times;</button>
            </div>
        </div>

        <!-- Filter Bar -->
        <div style="padding:10px 20px; border-bottom:1px solid var(--border-color, #f0f0f0); display:flex; align-items:center; gap:12px; background:var(--bg-card, #fff); font-size:12px;">
            <div style="display:flex; align-items:center; gap:6px;">
                <label style="color:var(--text-muted, #8c8c8c); font-weight:500;">Dokumen:</label>
                <input type="text" id="auditFilterDocNo" class="ant-input ant-input-sm" style="width:160px; font-family:monospace; font-weight:600;" placeholder="Semua Dokumen">
            </div>
            <div style="display:flex; align-items:center; gap:6px;">
                <label style="color:var(--text-muted, #8c8c8c); font-weight:500;">Aksi:</label>
                <select id="auditFilterAction" class="ant-input ant-input-sm" style="width:120px;" onchange="loadAuditLogData()">
                    <option value="">Semua Aksi</option>
                    <option value="UPDATE">Ubah Data</option>
                    <option value="DELETE">Hapus Data</option>
                    <option value="INSERT">Data Baru</option>
                </select>
            </div>
            <button type="button" class="ant-btn ant-btn-primary ant-btn-sm" onclick="loadAuditLogData()"><i class="fa fa-filter"></i> Terapkan</button>
            <div style="margin-left:auto; font-size:11px; color:var(--text-muted, #8c8c8c);" id="auditCountInfo">Memuat log...</div>
        </div>

        <!-- Modal Body (Log Table & Detail) -->
        <div style="padding:0; flex:1; overflow-y:auto; position:relative; min-height:360px;" id="auditLogContentWrap">
            <div id="auditLogLoading" style="position:absolute; inset:0; background:rgba(255,255,255,0.7); display:flex; align-items:center; justify-content:center; z-index:10; font-size:13px; color:#1890ff;">
                <i class="fa fa-spinner fa-spin fa-2x"></i>
            </div>
            <table class="dw" style="width:100%; border-collapse:collapse; font-size:12px;" id="auditLogTable">
                <thead>
                    <tr style="background:var(--bg-table-th, #fafafa); text-align:left;">
                        <th style="width:145px; padding:8px 10px; border:1px solid var(--border-color, #d9d9d9);"><i class="fa fa-clock-o"></i> Waktu (WIB)</th>
                        <th style="width:130px; padding:8px 10px; border:1px solid var(--border-color, #d9d9d9);"><i class="fa fa-user"></i> Oleh Siapa</th>
                        <th style="width:90px; padding:8px 10px; border:1px solid var(--border-color, #d9d9d9); text-align:center;">Aksi</th>
                        <th style="width:140px; padding:8px 10px; border:1px solid var(--border-color, #d9d9d9);"><i class="fa fa-file-text-o"></i> No. Dokumen</th>
                        <th style="padding:8px 10px; border:1px solid var(--border-color, #d9d9d9);"><i class="fa fa-list-alt"></i> Rincian Apa yang Diganti / Dihapus</th>
                        <th style="width:75px; padding:8px 10px; border:1px solid var(--border-color, #d9d9d9); text-align:center;">Detail</th>
                    </tr>
                </thead>
                <tbody id="auditLogTbody">
                    <tr><td colspan="6" style="text-align:center; padding:30px; color:#999;">Belum ada catatan aktivitas.</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Modal Footer -->
        <div style="padding:10px 20px; border-top:1px solid var(--border-color, #f0f0f0); display:flex; align-items:center; justify-content:space-between; background:var(--bg-table-th, #fafafa);">
            <div style="font-size:11px; color:var(--text-muted, #8c8c8c);">
                <i class="fa fa-shield"></i> Seluruh riwayat perubahan tersimpan permanen dan terlindungi integritasnya.
            </div>
            <button type="button" class="ant-btn ant-btn-default ant-btn-sm" onclick="closeAuditLogModal()">Tutup</button>
        </div>
    </div>
</div>

<!-- Modal Sub-Detail: Diff JSON Perubahan -->
<div id="modalAuditDetail" class="ant-modal-mask" style="display:none; position:fixed; z-index:100000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.6); align-items:center; justify-content:center;">
    <div class="ant-modal-content" style="background:var(--bg-card, #fff); color:var(--text-color, #262626); border-radius:8px; width:760px; max-width:92vw; max-height:85vh; display:flex; flex-direction:column; box-shadow:0 14px 36px rgba(0,0,0,0.4); border:1px solid var(--border-color, #d9d9d9); overflow:hidden;">
        <div style="padding:12px 18px; border-bottom:1px solid var(--border-color, #f0f0f0); display:flex; align-items:center; justify-content:space-between; background:var(--bg-table-th, #fafafa);">
            <h4 style="margin:0; font-size:14px; font-weight:600;" id="auditDetailTitle">Rincian Perubahan Data Terstruktur</h4>
            <button type="button" class="ant-btn ant-btn-default ant-btn-sm" onclick="closeAuditDetailModal()" style="font-size:16px; border:none; background:transparent; cursor:pointer;">&times;</button>
        </div>
        <div style="padding:16px 20px; flex:1; overflow-y:auto; font-size:12px;" id="auditDetailBody">
            <!-- Isi Diff Dinamis -->
        </div>
        <div style="padding:10px 18px; border-top:1px solid var(--border-color, #f0f0f0); text-align:right; background:var(--bg-table-th, #fafafa);">
            <button type="button" class="ant-btn ant-btn-default ant-btn-sm" onclick="closeAuditDetailModal()">Kembali</button>
        </div>
    </div>
</div>
HTML;
    }
}

if (!function_exists('renderAuditLogJs')) {
    /**
     * Render skrip JavaScript pendukung audit log
     */
    function renderAuditLogJs($module = 'ERP') {
        $modJs = json_encode($module);
        $jsBody = <<<'JS'
let currentAuditDocNo = '';

function showDocAuditLog(docNo, module) {
    currentAuditDocNo = docNo || '';
    if (module) currentAuditModule = module;

    const inpDoc = document.getElementById('auditFilterDocNo');
    if (inpDoc) inpDoc.value = currentAuditDocNo;

    const titleEl = document.getElementById('auditModalTitle');
    if (titleEl) {
        titleEl.textContent = currentAuditDocNo 
            ? `Audit Trail: Riwayat Perubahan & Penghapusan [${currentAuditDocNo}]` 
            : `Audit Trail: Riwayat Perubahan & Penghapusan Data (${currentAuditModule})`;
    }

    const modal = document.getElementById('modalAuditLog');
    if (modal) {
        modal.style.display = 'flex';
        loadAuditLogData();
    }
}

function closeAuditLogModal() {
    const modal = document.getElementById('modalAuditLog');
    if (modal) modal.style.display = 'none';
}

function refreshCurrentAuditLog() {
    loadAuditLogData();
}

function loadAuditLogData() {
    const loader = document.getElementById('auditLogLoading');
    if (loader) loader.style.display = 'flex';

    const docNo = document.getElementById('auditFilterDocNo') ? document.getElementById('auditFilterDocNo').value.trim() : '';
    const actFilter = document.getElementById('auditFilterAction') ? document.getElementById('auditFilterAction').value.trim() : '';

    let url = `?action=audit_list&module=${encodeURIComponent(currentAuditModule)}&doc_no=${encodeURIComponent(docNo)}`;
    if (typeof CURRENT_CMP_ID !== 'undefined' && CURRENT_CMP_ID) {
        url += '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID);
    }
    
    fetch(url)
        .then(r => r.json())
        .then(res => {
            if (loader) loader.style.display = 'none';
            if (res.status === 'OK') {
                renderAuditLogTable(res.data, actFilter);
                const cnt = document.getElementById('auditCountInfo');
                if (cnt) cnt.textContent = `Menampilkan ${res.data.length} dari ${res.total} catatan`;
            } else {
                alert('Gagal membaca log: ' + (res.message || 'Unknown error'));
            }
        })
        .catch(err => {
            if (loader) loader.style.display = 'none';
            console.error('Audit fetch error:', err);
        });
}

function renderAuditLogTable(rows, actFilter) {
    const tbody = document.getElementById('auditLogTbody');
    if (!tbody) return;

    if (actFilter) {
        rows = rows.filter(r => (r.action_type || '').toUpperCase() === actFilter.toUpperCase());
    }

    if (!rows || rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:30px; color:#999;"><i class="fa fa-info-circle"></i> Tidak ada catatan riwayat aktivitas yang sesuai filter.</td></tr>';
        return;
    }

    let html = '';
    rows.forEach(r => {
        let badge = '<span style="background:#e6f7ff; color:#1890ff; padding:2px 8px; border-radius:4px; font-weight:600; font-size:10px;">UBAH</span>';
        if (r.action_type === 'DELETE') {
            badge = '<span style="background:#fff1f0; color:#f5222d; padding:2px 8px; border-radius:4px; font-weight:600; font-size:10px;"><i class="fa fa-trash"></i> HAPUS</span>';
        } else if (r.action_type === 'INSERT') {
            badge = '<span style="background:#f6ffed; color:#52c41a; padding:2px 8px; border-radius:4px; font-weight:600; font-size:10px;"><i class="fa fa-plus"></i> BARU</span>';
        }

        const userDisplay = `${escapeHtml(r.user_name || r.user_id || 'System')}<br><small style="color:#8c8c8c; font-family:monospace;">${escapeHtml(r.ip_address || '-')}</small>`;
        const docDisplay = `<span style="font-family:monospace; font-weight:600; color:#1890ff;">${escapeHtml(r.doc_no || '-')}</span>`;
        const summaryText = escapeHtml(r.summary || '-');

        const btnDetail = (r.has_changes_json || r.has_old_snapshot)
            ? `<button type="button" class="ant-btn ant-btn-default ant-btn-sm" onclick="viewAuditLogDetail(${r.id})" style="padding:2px 6px; font-size:11px;" title="Lihat Snapshot / Diff Lengkap"><i class="fa fa-search"></i> Lihat</button>`
            : '<span style="color:#bfbfbf;">-</span>';

        html += `
            <tr style="border-bottom:1px solid var(--border-light, #f0f0f0);">
                <td style="padding:8px 10px; font-family:monospace; font-size:11px; white-space:nowrap; border:1px solid var(--border-color, #d9d9d9);">${escapeHtml(r.created_at)}</td>
                <td style="padding:8px 10px; border:1px solid var(--border-color, #d9d9d9);">${userDisplay}</td>
                <td style="padding:8px 10px; text-align:center; border:1px solid var(--border-color, #d9d9d9);">${badge}</td>
                <td style="padding:8px 10px; border:1px solid var(--border-color, #d9d9d9);">${docDisplay}</td>
                <td style="padding:8px 10px; font-size:11.5px; border:1px solid var(--border-color, #d9d9d9); line-height:1.4;">${summaryText}</td>
                <td style="padding:8px 10px; text-align:center; border:1px solid var(--border-color, #d9d9d9);">${btnDetail}</td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}

function viewAuditLogDetail(id) {
    let url = `?action=audit_detail&id=${id}`;
    if (typeof CURRENT_CMP_ID !== 'undefined' && CURRENT_CMP_ID) {
        url += '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID);
    }
    fetch(url)
        .then(r => r.json())
        .then(res => {
            if (res.status === 'OK') {
                const d = res.data;
                const modal = document.getElementById('modalAuditDetail');
                const title = document.getElementById('auditDetailTitle');
                const body = document.getElementById('auditDetailBody');
                if (!modal || !body) return;

                title.textContent = `Rincian Audit Log: [${d.action_type}] Dokumen ${d.doc_no} (${d.created_at})`;
                
                let html = `
                    <div style="background:var(--bg-table-th, #fafafa); padding:10px 14px; border-radius:6px; margin-bottom:14px; border:1px solid var(--border-color, #e8e8e8); font-size:12px;">
                        <div style="margin-bottom:4px;"><strong>Pelaku / User:</strong> ${escapeHtml(d.user_name || d.user_id)} (${escapeHtml(d.user_id)})</div>
                        <div style="margin-bottom:4px;"><strong>IP Address:</strong> ${escapeHtml(d.ip_address)} | <strong>Waktu (WIB):</strong> ${escapeHtml(d.created_at)}</div>
                        <div><strong>Ringkasan:</strong> ${escapeHtml(d.summary || '-')}</div>
                    </div>
                `;

                if (d.changes_decoded) {
                    const c = d.changes_decoded;
                    html += '<h5 style="margin:12px 0 6px; font-size:13px; font-weight:600; color:#1890ff;"><i class="fa fa-exchange"></i> Rincian Perubahan Field Header & Item:</h5>';

                    if (c.header && Object.keys(c.header).length > 0) {
                        html += '<div style="margin-bottom:10px;"><strong style="font-size:11px; text-transform:uppercase; color:#8c8c8c;">Kolom Header yang Diubah:</strong><table class="dw" style="width:100%; margin-top:4px; font-size:11.5px;"><thead><tr style="background:#fafafa;"><th style="padding:4px 8px;">Kolom</th><th style="padding:4px 8px; color:#cf1322;">Nilai Lama (Sebelum)</th><th style="padding:4px 8px; color:#389e0d;">Nilai Baru (Sesudah)</th></tr></thead><tbody>';
                        for (let k in c.header) {
                            const it = c.header[k];
                            html += `<tr><td style="padding:4px 8px; font-weight:600;">${escapeHtml(it.label || k)}</td><td style="padding:4px 8px; background:#fff1f0; font-family:monospace;">${escapeHtml(it.old)}</td><td style="padding:4px 8px; background:#f6ffed; font-family:monospace; font-weight:600;">${escapeHtml(it.new)}</td></tr>`;
                        }
                        html += '</tbody></table></div>';
                    }

                    if (c.items_added && c.items_added.length > 0) {
                        html += '<div style="margin-bottom:10px;"><strong style="font-size:11px; text-transform:uppercase; color:#389e0d;"><i class="fa fa-plus-circle"></i> Barang yang Ditambahkan:</strong><ul style="margin:4px 0 0 18px; padding:0;">';
                        c.items_added.forEach(it => {
                            html += `<li><strong>[${escapeHtml(it.key)}]</strong> ${escapeHtml(it.nama_barang || '')} &mdash; Qty: ${it.qty} ${escapeHtml(it.satuan || '')} @ ${it.harga}</li>`;
                        });
                        html += '</ul></div>';
                    }

                    if (c.items_deleted && c.items_deleted.length > 0) {
                        html += '<div style="margin-bottom:10px;"><strong style="font-size:11px; text-transform:uppercase; color:#cf1322;"><i class="fa fa-minus-circle"></i> Barang yang Dihapus:</strong><ul style="margin:4px 0 0 18px; padding:0;">';
                        c.items_deleted.forEach(it => {
                            html += `<li><strong>[${escapeHtml(it.key)}]</strong> ${escapeHtml(it.nama_barang || '')} &mdash; Qty: ${it.qty} ${escapeHtml(it.satuan || '')}</li>`;
                        });
                        html += '</ul></div>';
                    }

                    if (c.items_modified && c.items_modified.length > 0) {
                        html += '<div style="margin-bottom:10px;"><strong style="font-size:11px; text-transform:uppercase; color:#d46b08;"><i class="fa fa-pencil"></i> Rincian Barang yang Dimodifikasi:</strong><ul style="margin:4px 0 0 18px; padding:0;">';
                        c.items_modified.forEach(it => {
                            let diffList = [];
                            for (let fk in it.diff) {
                                diffList.push(`${escapeHtml(fk)}: <span style="color:#cf1322;">${escapeHtml(it.diff[fk].old)}</span> ➔ <span style="color:#389e0d; font-weight:600;">${escapeHtml(it.diff[fk].new)}</span>`);
                            }
                            html += `<li><strong>[${escapeHtml(it.key)}]</strong> ${escapeHtml(it.nama_barang || '')} &mdash; (${diffList.join(', ')})</li>`;
                        });
                        html += '</ul></div>';
                    }
                } else if (d.action_type === 'DELETE' && d.old_decoded) {
                    html += '<h5 style="margin:12px 0 6px; font-size:13px; font-weight:600; color:#cf1322;"><i class="fa fa-trash"></i> Snapshot Dokumen yang Dihapus (Backup Integritas):</h5>';
                    html += `<pre style="background:#282c34; color:#abb2bf; padding:12px; border-radius:6px; max-height:260px; overflow:auto; font-size:11px; font-family:monospace;">${escapeHtml(JSON.stringify(d.old_decoded, null, 2))}</pre>`;
                }

                body.innerHTML = html;
                modal.style.display = 'flex';
            }
        });
}

function closeAuditDetailModal() {
    const modal = document.getElementById('modalAuditDetail');
    if (modal) modal.style.display = 'none';
}
JS;
        return "let currentAuditModule = {$modJs};\n" . $jsBody;
    }
}

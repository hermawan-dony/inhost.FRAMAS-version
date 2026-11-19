<?php
/**
 * CEISA 4.0 - Service Integrasi IT Inventory Kawasan Ekonomi Khusus (KEK)
 * Lembaga National Single Window (LNSW Gen2)
 *
 * Mengimplementasikan seluruh endpoint resmi sesuai dokumen:
 * "Pedoman Integrasi Aplikasi IT Inventory KEK - LNSW Gen2"
 */

class KekSyncService {
    private $koneksi;
    private $cmpId;
    private $setting;
    private $company;

    public function __construct($koneksi, $cmpId) {
        $this->koneksi = $koneksi;
        $this->cmpId   = $cmpId;
        $this->loadCompany();
        $this->loadSetting();
    }

    private function loadCompany() {
        $cmpEsc = $this->koneksi->real_escape_string($this->cmpId);
        $res = $this->koneksi->query("SELECT cmp_id, cmp_name, npwp, IFNULL(business_type, 'manufaktur') as business_type FROM bc_ceisa_company WHERE cmp_id = '$cmpEsc' OR npwp = '$cmpEsc' LIMIT 1");
        if ($res && $r = $res->fetch_assoc()) {
            $this->company = $r;
        } else {
            $this->company = [
                'cmp_id'        => $this->cmpId,
                'cmp_name'      => 'Unknown Company',
                'npwp'          => '',
                'business_type' => 'manufaktur'
            ];
        }
    }

    private function loadSetting() {
        $cmpEsc = $this->koneksi->real_escape_string($this->cmpId);
        $res = $this->koneksi->query("SELECT * FROM bc_kek_setting WHERE cmp_id = '$cmpEsc' LIMIT 1");
        if ($res && $r = $res->fetch_assoc()) {
            $this->setting = $r;
        } else {
            // Default setting jika belum disimpan
            $bizType = $this->company['business_type'] ?? 'manufaktur';
            $ins = $this->koneksi->prepare("INSERT INTO bc_kek_setting (cmp_id, is_kek_active, kek_mode, insw_key, unique_key, business_type) VALUES (?, 0, 'dummy', 'pZ66hobzPpXBn2bMHVPTz0wG1pxuWQdo', '', ?)");
            if ($ins) {
                $ins->bind_param("ss", $this->cmpId, $bizType);
                $ins->execute();
                $ins->close();
            }
            $this->setting = [
                'cmp_id'             => $this->cmpId,
                'is_kek_active'      => 0,
                'kek_mode'           => 'dummy',
                'insw_key'           => 'pZ66hobzPpXBn2bMHVPTz0wG1pxuWQdo',
                'unique_key'         => '',
                'unique_key_real'    => '',
                'unique_key_dummy'   => 'e655669b83fe553f0bd756966311ecb694df907991293e5f0961d7e8157641e7',
                'business_type'      => $bizType,
                'auto_push_active'   => 0,
                'auto_push_interval' => 60,
                'saldo_awal_final'   => 0,
                'saldo_awal_final_at'=> null,
                'last_push_at'       => null
            ];
        }
    }

    public function getSetting() {
        return $this->setting;
    }

    public function getCompany() {
        return $this->company;
    }

    public function getBaseUrl() {
        $mode = $this->setting['kek_mode'] ?? 'dummy';
        if ($mode === 'real') {
            return 'https://api.insw.go.id/api-prod/inventory';
        }
        return 'https://api.insw.go.id/api-prod/inventory/temp';
    }

    /**
     * Mengambil x-unique-key aktif berdasarkan mode lingkungan:
     * - Mode 'real' (Produksi): mengambil unique_key_real
     * - Mode 'dummy' (Percobaan): mengambil unique_key_dummy (default e655669b...)
     */
    public function getActiveUniqueKey() {
        $mode = $this->setting['kek_mode'] ?? 'dummy';
        if ($mode === 'real') {
            $k = trim($this->setting['unique_key_real'] ?? '');
            return $k !== '' ? $k : trim($this->setting['unique_key'] ?? '');
        }
        $k = trim($this->setting['unique_key_dummy'] ?? '');
        if ($k !== '') {
            return $k;
        }
        $legacy = trim($this->setting['unique_key'] ?? '');
        return $legacy !== '' ? $legacy : 'e655669b83fe553f0bd756966311ecb694df907991293e5f0961d7e8157641e7';
    }

    /**
     * Eksekusi HTTP Request ke API Gateway INSW
     */
    public function sendRequest($subpath, $method = 'POST', $payload = null) {
        $baseUrl = $this->getBaseUrl();
        $url = rtrim($baseUrl, '/') . '/' . ltrim($subpath, '/');

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'x-insw-key: ' . trim($this->setting['insw_key'] ?? ''),
            'x-unique-key: ' . $this->getActiveUniqueKey()
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $methodUpper = strtoupper($method);
        if ($methodUpper === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($payload !== null) {
                $json = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_SLASHES);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            }
        } elseif ($methodUpper === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($payload !== null) {
                $json = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_SLASHES);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            }
        } elseif ($methodUpper === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            if ($payload !== null) {
                $json = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_SLASHES);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            }
        } elseif ($methodUpper === 'GET') {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $jsonDecoded = null;
        if ($response !== false) {
            $jsonDecoded = json_decode($response, true);
        }

        $respMsg = $jsonDecoded['message'] ?? ($curlError ?: "HTTP $httpCode");
        if ($respMsg === 'invalid x-unique-key') {
            $respMsg .= ' (Gateway INSW menolak x-unique-key. Pastikan mode Live/Percobaan sesuai dan Onboarding Saldo Awal di itinventory.insw.go.id telah difinalisasi di Step 3 agar kunci diaktifkan)';
        }

        return [
            'success'      => ($httpCode >= 200 && $httpCode < 300),
            'http_code'    => $httpCode,
            'url'          => $url,
            'method'       => $methodUpper,
            'curl_error'   => $curlError,
            'raw_response' => $response,
            'json'         => $jsonDecoded,
            'code'         => $jsonDecoded['code'] ?? null,
            'status'       => $jsonDecoded['status'] ?? false,
            'message'      => $respMsg
        ];
    }

    /**
     * 1. Saldo Awal (POST /saldoAwal atau /temp/saldoAwal)
     */
    public function pushSaldoAwal($noKegiatan, $tglKegiatan, $barangSaldo) {
        $payload = [
            'data' => [
                'no_kegiatan'   => (string)$noKegiatan,
                'tgl_kegiatan'  => (string)$tglKegiatan,
                'barangSaldo'   => array_map(function($it) use ($tglKegiatan) {
                    return [
                        'kd_kategori_barang' => (string)($it['kd_kategori_barang'] ?? $it['kdKategoriBarang'] ?? '1'),
                        'kd_barang'          => (string)($it['kd_barang'] ?? $it['kdBarang'] ?? '-'),
                        'uraian_barang'      => (string)($it['uraian_barang'] ?? $it['uraianBarang'] ?? ''),
                        'jumlah'             => (float)($it['jumlah'] ?? $it['qty'] ?? 0),
                        'satuan'             => strtoupper(trim($it['satuan'] ?? $it['kdSatuan'] ?? $it['unit'] ?? 'PCE')),
                        'nilai'              => (float)($it['nilai'] ?? 0),
                        'tanggal_declare'    => !empty($it['tanggal_declare']) ? $it['tanggal_declare'] : (!empty($tglKegiatan) ? date('Y-m-d', strtotime($tglKegiatan)) : date('Y-m-d'))
                    ];
                }, $barangSaldo)
            ]
        ];

        $res = $this->sendRequest('saldoAwal', 'POST', $payload);

        // Jika respon sukses mengembalikan uniqueKey baru, auto-simpan ke database setting
        if (!empty($res['json']['data']['postHeader']['uniqueKey'])) {
            $newKey = trim($res['json']['data']['postHeader']['uniqueKey']);
            $mode = $this->setting['kek_mode'] ?? 'dummy';
            $cmpEsc = $this->koneksi->real_escape_string($this->cmpId);
            if ($mode === 'real') {
                $this->koneksi->query("UPDATE bc_kek_setting SET unique_key_real = '$newKey', unique_key = '$newKey' WHERE cmp_id = '$cmpEsc'");
                $this->setting['unique_key_real'] = $newKey;
            } else {
                $this->koneksi->query("UPDATE bc_kek_setting SET unique_key_dummy = '$newKey', unique_key = '$newKey' WHERE cmp_id = '$cmpEsc'");
                $this->setting['unique_key_dummy'] = $newKey;
            }
            $this->setting['unique_key'] = $newKey;
        }

        // Catat ke log
        $this->recordLog([
            'kd_kegiatan'         => '00',
            'nomor_dok_kegiatan'  => $noKegiatan,
            'tanggal_kegiatan'    => date('Y-m-d', strtotime($tglKegiatan)),
            'nama_entitas'        => 'SALDO AWAL KEK',
            'ref_source'          => 'saldo_awal',
            'request_payload'     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'response'            => $res,
            'items'               => $barangSaldo
        ]);

        return $res;
    }

    /**
     * 2. Kunci Saldo Awal Final (PUT /registrasi atau /temp/registrasi)
     */
    public function finalizeSaldoAwal() {
        $res = $this->sendRequest('registrasi', 'PUT', []);
        if ($res['success'] && ($res['status'] === true || ($res['json']['code'] ?? '') === '01')) {
            $cmpEsc = $this->koneksi->real_escape_string($this->cmpId);
            $this->koneksi->query("UPDATE bc_kek_setting SET saldo_awal_final = 1, saldo_awal_final_at = NOW() WHERE cmp_id = '$cmpEsc'");
            $this->setting['saldo_awal_final'] = 1;
        }
        return $res;
    }

    /**
     * 3, 4, 5, 6. Kirim Transaksi KEK (POST /transaksi atau /temp/transaksi)
     * $kdKegiatan: 30 (Pemasukan), 31 (Pengeluaran), 32 (Stock Opname), 33 (Adjustment)
     */
    public function pushTransaksi($kdKegiatan, $dokumenKegiatanArray, $refSource = 'bc_1ab2') {
        $payload = [
            'data' => [
                [
                    'kdKegiatan'       => (string)$kdKegiatan,
                    'dokumenKegiatan'  => $dokumenKegiatanArray
                ]
            ]
        ];

        $res = $this->sendRequest('transaksi', 'POST', $payload);

        // Catat log untuk setiap dokumen kegiatan
        foreach ($dokumenKegiatanArray as $dok) {
            $tglKgt = $dok['tanggalKegiatan'] ?? date('Y-m-d');
            if (strlen($tglKgt) > 10) {
                $tglKgt = substr($tglKgt, 0, 10);
            }
            if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $tglKgt, $m)) {
                $tglKgt = "{$m[3]}-{$m[2]}-{$m[1]}";
            }

            $items = $dok['barangTransaksi'] ?? [];
            $this->recordLog([
                'kd_kegiatan'         => (string)$kdKegiatan,
                'nomor_dok_kegiatan'  => $dok['nomorDokKegiatan'] ?? '-',
                'tanggal_kegiatan'    => $tglKgt,
                'nama_entitas'        => $dok['namaEntitas'] ?? '',
                'ref_source'          => $refSource,
                'request_payload'     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'response'            => $res,
                'items'               => $items
            ]);
        }

        // Update timestamp last push
        $cmpEsc = $this->koneksi->real_escape_string($this->cmpId);
        $this->koneksi->query("UPDATE bc_kek_setting SET last_push_at = NOW() WHERE cmp_id = '$cmpEsc'");

        return $res;
    }

    /**
     * 7. Cleansing Data Percobaan (DELETE /temp/transaksi?npwp={npwp})
     */
    public function cleansingDataPercobaan($npwp = null) {
        $targetNpwp = $npwp ?: ($this->company['npwp'] ?? '');
        $targetNpwpDigits = preg_replace('/\D/', '', $targetNpwp);
        $url = 'transaksi?npwp=' . urlencode($targetNpwpDigits);
        $res = $this->sendRequest($url, 'DELETE');

        // Bersihkan seluruh data log transaksi pengujian lokal agar status kembali CLEAR / PENDING
        $cmpEsc = $this->koneksi->real_escape_string($this->cmpId);
        @$this->koneksi->query("DELETE d FROM bc_kek_transaksi_detail d JOIN bc_kek_transaksi_log l ON d.id_log = l.id WHERE l.cmp_id = '$cmpEsc'");
        @$this->koneksi->query("DELETE FROM bc_kek_transaksi_detail WHERE cmp_id = '$cmpEsc'");
        @$this->koneksi->query("DELETE FROM bc_kek_transaksi_log WHERE cmp_id = '$cmpEsc'");

        return $res;
    }

    /**
     * 8. Update Dokumen Barang (PUT /transaksi/dokumen atau /temp/transaksi/dokumen)
     */
    public function updateDokumenBarang($idTransaksi, $idBarangTransaksi, $kodeDokumen, $nomorDokumen, $tanggalDokumen) {
        $payload = [
            'idTransaksi'        => (string)$idTransaksi,
            'idBarangTransaksi'  => (string)$idBarangTransaksi,
            'kodeDokumen'        => (string)$kodeDokumen,
            'nomorDokumen'       => (string)$nomorDokumen,
            'tanggalDokumen'     => (string)$tanggalDokumen
        ];
        return $this->sendRequest('transaksi/dokumen', 'PUT', $payload);
    }

    /**
     * 9, 10, 11, 12. Inquiry / Get Data Transaksi dari LNSW
     */
    public function inquiryTransaksi($kdKegiatan, $tglAwal, $tglAkhir) {
        $subpath = "transaksi/{$kdKegiatan}/tglAwal=" . urlencode($tglAwal) . "&tglAkhir=" . urlencode($tglAkhir);
        return $this->sendRequest($subpath, 'POST', []);
    }

    /**
     * Rekam Log Transaksi ke Database
     */
    private function recordLog($data) {
        $res = $data['response'] ?? [];
        $isSuccess = (!empty($res['success']) && (!empty($res['status']) || ($res['code'] ?? '') === '01'));
        $statusKirim = $isSuccess ? 'SUCCESS' : 'FAILED';
        $httpCode = (int)($res['http_code'] ?? 0);
        $respCode = (string)($res['code'] ?? '');
        $respMsg  = (string)($res['message'] ?? '');
        $rawResp  = (string)($res['raw_response'] ?? '');

        // Cari ID Transaksi dari respon jika sukses
        $inswIdTransaksi = null;
        if (!empty($res['json']['data']['resultDataTransaksi'][0]['idTransaksi'])) {
            $inswIdTransaksi = $res['json']['data']['resultDataTransaksi'][0]['idTransaksi'];
        } elseif (!empty($res['json']['data']['postHeader']['idHeader'])) {
            $inswIdTransaksi = $res['json']['data']['postHeader']['idHeader'];
        }

        $items = $data['items'] ?? [];
        $totalItem = count($items);
        $totalNilai = 0;
        foreach ($items as $it) {
            $totalNilai += (float)($it['nilai'] ?? 0);
        }

        $stmt = $this->koneksi->prepare("INSERT INTO bc_kek_transaksi_log 
            (cmp_id, kd_kegiatan, nomor_dok_kegiatan, tanggal_kegiatan, nama_entitas, ref_source, insw_id_transaksi, status_kirim, http_code, response_code, response_message, request_payload, response_payload, total_item, total_nilai, pushed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param(
                "ssssssssissssid",
                $this->cmpId,
                $data['kd_kegiatan'],
                $data['nomor_dok_kegiatan'],
                $data['tanggal_kegiatan'],
                $data['nama_entitas'],
                $data['ref_source'],
                $inswIdTransaksi,
                $statusKirim,
                $httpCode,
                $respCode,
                $respMsg,
                $data['request_payload'],
                $rawResp,
                $totalItem,
                $totalNilai
            );
            $stmt->execute();
            $logId = $stmt->insert_id;
            $stmt->close();

            // Simpan detail barang
            if ($logId && !empty($items)) {
                $detailStmt = $this->koneksi->prepare("INSERT INTO bc_kek_transaksi_detail 
                    (id_log, cmp_id, nomor_aju, kd_kegiatan, kd_kategori_barang, kd_barang, seri_barang, uraian_barang, jumlah, kd_satuan, nilai, insw_id_barang_transaksi, kode_dokumen, nomor_dokumen, tanggal_dokumen)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if ($detailStmt) {
                    foreach ($items as $idx => $it) {
                        $kdKategori = (string)($it['kdKategoriBarang'] ?? $it['kd_kategori_barang'] ?? '1');
                        $kdBarang   = (string)($it['kdBarang'] ?? $it['kd_barang'] ?? '-');
                        $seriBarang = isset($it["seri_barang"]) ? (int)$it["seri_barang"] : null;
                        $nomorAju   = !empty($it["nomor_aju"]) ? (string)$it["nomor_aju"] : null;
                        $uraian     = (string)($it['uraianBarang'] ?? $it['uraian_barang'] ?? '');
                        $jumlah     = (float)($it['jumlah'] ?? 0);
                        $satuan     = (string)($it['kdSatuan'] ?? $it['satuan'] ?? 'PCE');
                        $nilai      = (float)($it['nilai'] ?? 0);

                        $inswBrgId = null;
                        if (!empty($res['json']['data']['resultBarangTransaksi'][$idx]['idBarangTransaksi'])) {
                            $inswBrgId = $res['json']['data']['resultBarangTransaksi'][$idx]['idBarangTransaksi'];
                        } elseif (!empty($res['json']['data']['postBarang'][$idx]['idBarang'])) {
                            $inswBrgId = $res['json']['data']['postBarang'][$idx]['idBarang'];
                        }

                        $dokList = $it['dokumen'] ?? [];
                        $kdDok   = !empty($dokList[0]['kodeDokumen']) ? $dokList[0]['kodeDokumen'] : null;
                        $noDok   = !empty($dokList[0]['nomorDokumen']) ? $dokList[0]['nomorDokumen'] : null;
                        $tglDok  = !empty($dokList[0]['tanggalDokumen']) ? date('Y-m-d', strtotime($dokList[0]['tanggalDokumen'])) : null;

                        $detailStmt->bind_param(
                            "isssssisdsdssss",
                            $logId,
                            $this->cmpId,
                            $nomorAju,
                            $data['kd_kegiatan'],
                            $kdKategori,
                            $kdBarang,
                            $seriBarang,
                            $uraian,
                            $jumlah,
                            $satuan,
                            $nilai,
                            $inswBrgId,
                            $kdDok,
                            $noDok,
                            $tglDok
                        );
                        $detailStmt->execute();
                    }
                    $detailStmt->close();
                }
            }
        }
    }

    /**
     * Konversi Kode BC Internal (20, 23, 27, 40, dll.) ke Kode 7 Digit LNSW
     */
    public function mapKodeDokumen($bcCode) {
        $clean = strtoupper(trim(str_replace(['BC', 'bc', '.', ' '], '', $bcCode)));
        $stmt = $this->koneksi->prepare("SELECT kode_lnsw FROM bc_kek_ref_dokumen WHERE kode_bc = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $clean);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $r = $res->fetch_assoc()) {
                $stmt->close();
                return $r['kode_lnsw'];
            }
            $stmt->close();
        }

        // Fallback default format 7 digit
        $defaults = [
            '20'  => '0407020',
            '23'  => '0407023',
            '25'  => '0407025',
            '261' => '0407261',
            '262' => '0407262',
            '27'  => '0407027',
            '28'  => '0407028',
            '30'  => '0407030',
            '40'  => '0407040',
            '41'  => '0407041',
            '611' => '0407611',
            '613' => '0407613',
            '621' => '0407621',
            '631' => '0407631',
            '632' => '0407632'
        ];
        return $defaults[$clean] ?? '0407008';
    }

    /**
     * Pemetaan Klasifikasi Barang Internal ke Kategori KEK (1-8)
     */
    public function mapKategoriBarang($klasifikasi, $businessType = 'manufaktur') {
        $klas = strtolower(trim($klasifikasi));

        // Jika KEK Logistik: HANYA Barang Dagangan (4) atau Mesin/Alat (5)
        if ($businessType === 'logistik') {
            if (str_contains($klas, 'mesin') || str_contains($klas, 'capital') || str_contains($klas, 'alat')) {
                return '5';
            }
            return '4'; // Barang Dagangan
        }

        // Jika KEK Manufaktur (1, 2, 5, 6, 7, 8)
        if (str_contains($klas, 'raw') || str_contains($klas, 'baku')) {
            return '1'; // Bahan Baku
        } elseif (str_contains($klas, 'penolong') || str_contains($klas, 'sub material')) {
            return '2'; // Bahan Penolong
        } elseif (str_contains($klas, 'mesin') || str_contains($klas, 'capital') || str_contains($klas, 'equipment')) {
            return '5'; // Mesin & Peralatan
        } elseif (str_contains($klas, 'wip') || str_contains($klas, 'proses')) {
            return '6'; // Barang Dalam Proses
        } elseif (str_contains($klas, 'finish') || str_contains($klas, 'jadi')) {
            return '7'; // Barang Jadi
        } elseif (str_contains($klas, 'scrap') || str_contains($klas, 'reject') || str_contains($klas, 'sisa') || str_contains($klas, 'waste')) {
            return '8'; // Reject & Scrap
        }

        return '1'; // Default Bahan Baku untuk manufaktur
    }

    /**
     * Konversi Data Mutasi bc_1ab2 ke Payload KEK LNSW dan Kirimkan
     * $ids: array ID baris bc_1ab2 yang akan dikirim
     */
    public function pushRecordsFrom1ab2($ids, $kdKegiatan = '30') {
        if (empty($ids) || !is_array($ids)) {
            return ['status' => 'ERROR', 'message' => 'Tidak ada data transaksi yang dipilih.'];
        }

        $cmpEsc = $this->koneksi->real_escape_string($this->cmpId);
        $whereClauses = [];

        foreach ($ids as $idItem) {
            $idItem = trim((string)$idItem);
            if (empty($idItem)) continue;
            if (str_contains($idItem, ':::|:::')) {
                $parts = explode(':::|:::', $idItem);
                $nb  = $this->koneksi->real_escape_string($parts[0] ?? '');
                $kb  = $this->koneksi->real_escape_string($parts[1] ?? '');
                $sb  = $this->koneksi->real_escape_string($parts[2] ?? '');
                $bc  = $this->koneksi->real_escape_string($parts[3] ?? '');
                $nd  = $this->koneksi->real_escape_string($parts[4] ?? '');
                $ab  = $this->koneksi->real_escape_string($parts[5] ?? '');
                $whereClauses[] = "(a.NO_BUKTI = '$nb' AND a.KODE_BARANG = '$kb' AND a.SERI_BARANG = '$sb' AND a.BC = '$bc' AND a.NOMOR_DAFTAR = '$nd' AND a.AB = '$ab')";
            } elseif (is_numeric($idItem)) {
                // Fallback if integer id
                $cleanId = (int)$idItem;
                $whereClauses[] = "(a.SERI_BARANG = '$cleanId')";
            }
        }

        if (empty($whereClauses)) {
            return ['status' => 'ERROR', 'message' => 'Format kunci data transaksi tidak valid.'];
        }

        $cmpCandidates = [$cmpEsc];
        $resCmp = $this->koneksi->query("SELECT cmp_id, npwp, subdomain FROM bc_ceisa_company WHERE cmp_id = '$cmpEsc' OR npwp = '$cmpEsc' OR subdomain = '$cmpEsc' LIMIT 1");
        if ($resCmp && $cRow = $resCmp->fetch_assoc()) {
            if (!empty($cRow['cmp_id'])) $cmpCandidates[] = $cRow['cmp_id'];
            if (!empty($cRow['npwp'])) {
                $cmpCandidates[] = $cRow['npwp'];
                $cmpCandidates[] = preg_replace('/\D/', '', $cRow['npwp']);
            }
            if (!empty($cRow['subdomain'])) $cmpCandidates[] = $cRow['subdomain'];
        }
        $cmpCandidates = array_unique(array_filter($cmpCandidates));
        $escapedCmpList = "'" . implode("','", array_map([$this->koneksi, 'real_escape_string'], $cmpCandidates)) . "'";

        $whereSql = implode(' OR ', $whereClauses);
        $sql = "SELECT 
                    a.NOMOR_AJU, a.BC, a.NOMOR_DAFTAR, a.TANGGAL_DAFTAR, a.NO_BUKTI, a.TGL_BUKTI, 
                    a.KODE_BARANG, a.URAIAN, COALESCE(a.QTY_AKTUAL, a.QTY, 0) AS QTY, a.UNIT, 
                    COALESCE(a.NILAI, a.CIF, a.HARGA_PENYERAHAN, 0) AS NILAI, 
                    COALESCE(a.CIF, a.NILAI, 0) AS NILAI_PABEAN,
                    a.NAMA_BS AS PENGIRIM_PENERIMA, 
                    a.KLAS AS KLASIFIKASI, a.AB, a.SERI_BARANG
                FROM bc_1ab2 a
                WHERE ($whereSql) 
                  AND a.cmp_id IN ($escapedCmpList)
                ORDER BY a.NO_BUKTI ASC, a.SERI_BARANG ASC";

        $res = $this->koneksi->query($sql);
        if (!$res || $res->num_rows === 0) {
            return ['status' => 'ERROR', 'message' => 'Data transaksi tidak ditemukan di tabel persediaan.'];
        }

        $businessType = $this->setting['business_type'] ?? 'manufaktur';

        // Kelompokkan per nomor bukti transaksi (nomorDokKegiatan)
        $grouped = [];
        while ($r = $res->fetch_assoc()) {
            $noBukti = trim($r['NO_BUKTI']) ?: ('TRX-' . ($r['NOMOR_DAFTAR'] ?: uniqid()));
            if (!isset($grouped[$noBukti])) {
                $tglBukti = !empty($r['TGL_BUKTI']) ? date('d-m-Y', strtotime($r['TGL_BUKTI'])) : date('d-m-Y');
                $namaEntitas = trim($r['PENGIRIM_PENERIMA'] ?? '') ?: ($kdKegiatan === '30' ? 'SUPPLIER KEK' : 'CUSTOMER KEK');

                $grouped[$noBukti] = [
                    'nomorDokKegiatan' => $noBukti,
                    'tanggalKegiatan'  => $tglBukti,
                    'namaEntitas'      => $namaEntitas,
                    'barangTransaksi'  => []
                ];
            }

            // Siapkan relasi dokumen pabean jika sudah terdaftar
            $dokumenArray = [];
            if (!empty($r['NOMOR_DAFTAR']) && trim($r['NOMOR_DAFTAR']) !== '-' && trim($r['NOMOR_DAFTAR']) !== '') {
                $dokumenArray[] = [
                    'kodeDokumen'   => $this->mapKodeDokumen($r['BC']),
                    'nomorDokumen'  => trim($r['NOMOR_DAFTAR']),
                    'tanggalDokumen'=> !empty($r['TANGGAL_DAFTAR']) ? date('d-m-Y', strtotime($r['TANGGAL_DAFTAR'])) : date('d-m-Y')
                ];
            }

            // Hitung nilai total rupiah
            $nilaiTotal = (float)($r['NILAI'] ?? 0);
            if ($nilaiTotal <= 0 && !empty($r['NILAI_PABEAN'])) {
                $nilaiTotal = (float)$r['NILAI_PABEAN'];
            }

            $grouped[$noBukti]['barangTransaksi'][] = [
                'kdKategoriBarang' => $this->mapKategoriBarang($r['KLASIFIKASI'] ?? '', $businessType),
                'kdBarang'         => trim($r['KODE_BARANG']),
                'seri_barang'      => (int)$r['SERI_BARANG'],
                'nomor_aju'        => trim($r['NOMOR_AJU'] ?? ''),
                'uraianBarang'     => trim($r['URAIAN']),
                'jumlah'           => (float)$r['QTY'],
                'kdSatuan'         => strtoupper(trim($r['UNIT'] ?: 'PCE')),
                'nilai'            => round($nilaiTotal, 4),
                'dokumen'          => $dokumenArray
            ];
        }

        $dokumenKegiatanArray = array_values($grouped);
        return $this->pushTransaksi($kdKegiatan, $dokumenKegiatanArray, 'bc_1ab2');
    }
}

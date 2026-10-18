<?php 
/**
 * CEISA 4.0 Tracking Respon Pabean (statusaju.php)
 * Dashboard interaktif pemantauan status dan respon dokumen pabean CEISA 4.0.
 * Mendukung Single-AJU live sync, Multi-Company NPWP sync, Visual Timeline Stepper,
 * serta backward compatibility penuh untuk endpoint REST API legacy (transfer.php & curl).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/datalogin.php';

// Ambil input parameter
$action   = trim($_GET['action'] ?? ($_POST['action'] ?? ''));
$aju      = trim($_GET['aju'] ?? ($_GET['nomor_aju'] ?? ($_POST['aju'] ?? ($_POST['nomor_aju'] ?? ''))));
$cmp_id   = trim($_GET['cmp_id'] ?? ($_POST['cmp_id'] ?? ($sessionCmpId ?? ($cmp_id ?? ''))));
$theme    = trim($_GET['theme'] ?? ($_SESSION['theme'] ?? 'light'));
$embedded = isset($_GET['embedded']) || isset($_POST['embedded']);

// Otomatis deteksi company jika aju diberikan tapi cmp_id belum terisi
if (empty($cmp_id) && !empty($aju) && function_exists('ceisa_get_company')) {
    $comp = ceisa_get_company($aju);
    if ($comp) {
        $cmp_id = $comp['cmp_id'];
        $_SESSION['cmp_id'] = $cmp_id;
    }
}

/**
 * Helper: Sinkronisasi Respon SPPB / Persetujuan Pengeluaran ke bc_ceisa_sppb (History SPPB)
 */
function syncSppbRecord($koneksi, $cmpId, $nomorAju, $nomorDaftar, $tanggalDaftar, $kodeDokumen, $nomorRespon, $tanggalRespon, $keterangan, $pdf) {
    if (empty($nomorAju) || !$koneksi) return;

    $isSppb = (
        stripos($keterangan, 'SPPB') !== false ||
        stripos($keterangan, 'PERSETUJUAN PENGELUARAN') !== false ||
        in_array((string)$kodeDokumen . '03', ['2703', '2303', '2003', '2613', '2623', '2803', '3003', '1603']) ||
        in_array((string)$keterangan, ['SPPB', '860'])
    );
    if (!$isSppb) return;

    $escAju = $koneksi->real_escape_string($nomorAju);
    $d = null;
    $resD = $koneksi->query("SELECT * FROM bc_ceisa_data WHERE nomorAju = '$escAju' LIMIT 1");
    if ($resD && $rowD = $resD->fetch_assoc()) {
        $d = $rowD;
    }

    $finalCmpId       = !empty($cmpId) ? $cmpId : ($d['cmp_id'] ?? '010816064431000');
    $finalNoDaftar    = !empty($nomorDaftar) ? $nomorDaftar : ($d['nomorDaftar'] ?? '');
    $finalTglDaftar   = !empty($tanggalDaftar) ? $tanggalDaftar : (!empty($d['tanggalDaftar']) ? $d['tanggalDaftar'] : null);
    $finalKdDok       = !empty($kodeDokumen) ? $kodeDokumen : ($d['kodeDokumen'] ?? '');
    $finalNmDok       = !empty($d['namaDokumen']) ? $d['namaDokumen'] : ('BC ' . $finalKdDok);
    $finalKdKantor    = $d['kodeKantor'] ?? '';
    $finalNmKantor    = $d['namaKantorPendek'] ?? '';
    $finalNmEntitas   = $d['namaEntitas'] ?? '';
    $finalKdProses    = $d['kodeProses'] ?? '800';
    $finalKdEntitas   = $d['kodeEntitas'] ?? '';
    $finalNmProses    = $d['namaProses'] ?? 'Selesai Proses';
    $finalIdPrsh      = $d['idPerusahaan'] ?? '';
    $finalNmPrsh      = $d['namaPerusahaan'] ?? '';
    $finalIdPpjk      = $d['idPpjk'] ?? '';
    $finalNipPfpd     = $d['nipPfpd'] ?? '';
    $finalNipPfpb     = $d['nipPfpb'] ?? '';
    $finalNipAp       = $d['nipAp'] ?? '';
    $finalKdJalur     = $d['kodeJalur'] ?? 'H';
    $finalIdRespon    = $d['idRespon'] ?? '';
    $finalNmRespon    = !empty($keterangan) ? $keterangan : 'SPPB';
    $finalNoRespon    = $nomorRespon;
    $finalTglRespon   = !empty($tanggalRespon) ? $tanggalRespon : (!empty($d['tanggalRespon']) ? $d['tanggalRespon'] : null);
    $finalPdf         = !empty($pdf) ? $pdf : ($d['pdf'] ?? '');

    $sql = "INSERT INTO bc_ceisa_sppb (
        cmp_id, nomorAju, nomorDaftar, tanggalDaftar, kodeDokumen, namaDokumen,
        kodeKantor, namaKantorPendek, namaEntitas, kodeProses, kodeEntitas,
        namaProses, idPerusahaan, namaPerusahaan, idPpjk, nipPfpd, nipPfpb,
        nipAp, kodeJalur, idRespon, namaRespon, nomorRespon, tanggalRespon, pdf,
        created_at, upd_time
    ) VALUES (
        ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?, ?, ?,
        NOW(), NOW()
    )
    ON DUPLICATE KEY UPDATE 
        nomorDaftar = COALESCE(NULLIF(VALUES(nomorDaftar), ''), bc_ceisa_sppb.nomorDaftar),
        tanggalDaftar = COALESCE(VALUES(tanggalDaftar), bc_ceisa_sppb.tanggalDaftar),
        nomorRespon = COALESCE(NULLIF(VALUES(nomorRespon), ''), bc_ceisa_sppb.nomorRespon),
        tanggalRespon = COALESCE(VALUES(tanggalRespon), bc_ceisa_sppb.tanggalRespon),
        pdf = COALESCE(NULLIF(VALUES(pdf), ''), bc_ceisa_sppb.pdf),
        namaRespon = VALUES(namaRespon),
        upd_time = NOW()";

    $stmt = $koneksi->prepare($sql);
    if ($stmt) {
        $stmt->bind_param(
            "ssssssssssssssssssssssss",
            $finalCmpId, $nomorAju, $finalNoDaftar, $finalTglDaftar, $finalKdDok, $finalNmDok,
            $finalKdKantor, $finalNmKantor, $finalNmEntitas, $finalKdProses, $finalKdEntitas,
            $finalNmProses, $finalIdPrsh, $finalNmPrsh, $finalIdPpjk, $finalNipPfpd, $finalNipPfpb,
            $finalNipAp, $finalKdJalur, $finalIdRespon, $finalNmRespon, $finalNoRespon, $finalTglRespon, $finalPdf
        );
        $stmt->execute();
        $stmt->close();
    }

    if (!empty($finalPdf)) {
        $escPdf = $koneksi->real_escape_string($finalPdf);
        $koneksi->query("UPDATE bc_ceisa_data SET pdf = '$escPdf' WHERE nomorAju = '$escAju' AND (pdf IS NULL OR pdf = '')");
    }
}

/**
 * Helper: Sinkronisasi Status & Respon 1 Nomor AJU dari API CEISA 4.0
 */
function ceisa_sync_single_aju($targetAju, $targetCmpId = '') {
    global $koneksi;

    if (empty($targetAju)) {
        return ['status' => 'ERROR', 'message' => 'Nomor AJU tidak boleh kosong!'];
    }

    if (empty($targetCmpId) && function_exists('ceisa_get_company')) {
        $comp = ceisa_get_company($targetAju);
        if ($comp) {
            $targetCmpId = $comp['cmp_id'];
        }
    }

    $cmp_id = $targetCmpId;
    require_once __DIR__ . '/init.php';

    $hostprd = "https://apis-gw.beacukai.go.id/openapi/status/" . urlencode($targetAju);
    $bearer  = $_SESSION["token_" . $targetCmpId] ?? ($_SESSION["token"] ?? '');

    $curl = curl_init(); 
    curl_setopt_array($curl, [
        CURLOPT_URL => $hostprd,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "GET",
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $bearer",
            "Content-Type: application/json",
            "Accept: application/json, text/plain, */*",
            "Accept-Language: id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7",
            "Origin: https://portal.beacukai.go.id",
            "Referer: https://portal.beacukai.go.id/",
            "Sec-Fetch-Dest: empty",
            "Sec-Fetch-Mode: cors",
            "Sec-Fetch-Site: same-site",
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36"
        ],
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        return ['status' => 'ERROR', 'message' => 'cURL Error: ' . $err, 'raw_response' => 'cURL Error: ' . $err];
    }

    // Simpan respon mentah ke tabel log
    if (isset($koneksi) && $koneksi) {
        $stmtLog = $koneksi->prepare("INSERT INTO bc_ceisa_respon_logs (respon) VALUES (?)");
        if ($stmtLog) {
            $stmtLog->bind_param("s", $response);
            $stmtLog->execute();
            $stmtLog->close();
        }
    }

    $data = json_decode($response, true);
    $status = $data['status'] ?? '';

    if ($status === 'Success' && isset($koneksi) && $koneksi) {
        // Hapus data status & respon lama untuk aju ini agar tidak terjadi redundansi
        $delStatus = $koneksi->prepare("DELETE FROM bc_ceisa_status WHERE (cmp_id = ? OR cmp_id = '') AND nomor_aju = ?");
        if ($delStatus) {
            $delStatus->bind_param("ss", $targetCmpId, $targetAju);
            $delStatus->execute();
            $delStatus->close();
        }

        $delResp = $koneksi->prepare("DELETE FROM bc_ceisa_respons WHERE (cmp_id = ? OR cmp_id = '') AND nomor_aju = ?");
        if ($delResp) {
            $delResp->bind_param("ss", $targetCmpId, $targetAju);
            $delResp->execute();
            $delResp->close();
        }

        $cntStatus = 0;
        $cntRespon = 0;

        // Simpan dataStatus
        if (!empty($data['dataStatus']) && is_array($data['dataStatus'])) {
            $stmtStatus = $koneksi->prepare("
                INSERT INTO bc_ceisa_status 
                (cmp_id, nomor_aju, nomor_daftar, tanggal_daftar, kode_proses, waktu_status, keterangan, kode_dokumen) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $updHdr = $koneksi->prepare("
                UPDATE bc_ceisa_header 
                SET nomor_daftar = ?, tanggal_daftar = STR_TO_DATE(?, '%d-%m-%Y') 
                WHERE (cmp_id = ? OR cmp_id = '') AND nomor_aju = ?
            ");

            $updPosted = $koneksi->prepare("
                UPDATE bc_ceisa_aju_posted 
                SET bc = ?, nomor_daftar = ?, tanggal_daftar = STR_TO_DATE(?, '%d-%m-%Y') 
                WHERE (cmp_id = ? OR cmp_id = '') AND nomor_aju = ?
            ");

            foreach ($data['dataStatus'] as $row) {
                $nomorAju      = $row['nomorAju'] ?? $targetAju;
                $nomorDaftar   = $row['nomorDaftar'] ?? '';
                $tanggalDaftar = $row['tanggalDaftar'] ?? '';
                $kodeProses    = $row['kodeProses'] ?? '';
                $waktuStatus   = $row['waktuStatus'] ?? '';
                $keterangan    = $row['keterangan'] ?? '';
                $kodeDokumen   = $row['kodeDokumen'] ?? '';

                if ($stmtStatus) {
                    $stmtStatus->bind_param("ssssssss", $targetCmpId, $nomorAju, $nomorDaftar, $tanggalDaftar, $kodeProses, $waktuStatus, $keterangan, $kodeDokumen);
                    $stmtStatus->execute();
                    $cntStatus++;
                }

                if (!empty($nomorDaftar)) {
                    if ($updHdr) {
                        $updHdr->bind_param("ssss", $nomorDaftar, $tanggalDaftar, $targetCmpId, $nomorAju);
                        $updHdr->execute();
                    }
                    if ($updPosted) {
                        $updPosted->bind_param("sssss", $kodeDokumen, $nomorDaftar, $tanggalDaftar, $targetCmpId, $nomorAju);
                        $updPosted->execute();
                    }
                }
            }

            if ($stmtStatus) $stmtStatus->close();
            if ($updHdr) $updHdr->close();
            if ($updPosted) $updPosted->close();
        }

        // Simpan dataRespon
        if (!empty($data['dataRespon']) && is_array($data['dataRespon'])) {
            $stmtRespon = $koneksi->prepare("
                INSERT INTO bc_ceisa_respons 
                (cmp_id, nomor_aju, kode_respon, nomor_daftar, tanggal_daftar, nomor_respon, tanggal_respon, keterangan, pdf, kode_dokumen) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($data['dataRespon'] as $row) {
                $nomorAju      = $row['nomorAju'] ?? $targetAju;
                $kodeRespon    = $row['kodeRespon'] ?? '';
                $nomorDaftar   = $row['nomorDaftar'] ?? '';
                $tanggalDaftar = $row['tanggalDaftar'] ?? '';
                $nomorRespon   = $row['nomorRespon'] ?? '';
                $tanggalRespon = $row['tanggalRespon'] ?? '';
                $keterangan    = $row['keterangan'] ?? '';
                $pdf           = $row['pdf'] ?? '';
                $kodeDokumen   = $row['kodeDokumen'] ?? '';

                if ($stmtRespon) {
                    $stmtRespon->bind_param("ssssssssss", $targetCmpId, $nomorAju, $kodeRespon, $nomorDaftar, $tanggalDaftar, $nomorRespon, $tanggalRespon, $keterangan, $pdf, $kodeDokumen);
                    $stmtRespon->execute();
                    $cntRespon++;

                    // Sinkronisasi otomatis ke tabel history SPPB (bc_ceisa_sppb) jika respon adalah SPPB/Persetujuan Pengeluaran
                    syncSppbRecord($koneksi, $targetCmpId, $nomorAju, $nomorDaftar, $tanggalDaftar, $kodeDokumen, $nomorRespon, $tanggalRespon, $keterangan, $pdf);
                }
            }

            if ($stmtRespon) $stmtRespon->close();
        }

        return [
            'status'       => 'OK',
            'raw_response' => 'OK',
            'data'         => $data,
            'cnt_status'   => $cntStatus,
            'cnt_respon'   => $cntRespon,
            'message'      => "Sinkronisasi berhasil! $cntStatus status & $cntRespon respon tersimpan."
        ];
    } else {
        $rawMsg = $data['message'] ?? '';
        $isNotFound = (
            stripos($response, 'Data Tidak Ditemukan') !== false ||
            stripos($rawMsg, 'tidak ditemukan') !== false ||
            (isset($data['status']) && strtolower((string)$data['status']) === 'failed' && stripos($rawMsg, 'tidak ditemukan') !== false)
        );

        $deletedPosted = false;
        if ($isNotFound && isset($koneksi) && $koneksi) {
            $escAju = $koneksi->real_escape_string($targetAju);
            // Hapus dari bc_ceisa_aju_posted agar baris dokumen di transfer.php kembali putih
            $koneksi->query("DELETE FROM bc_ceisa_aju_posted WHERE nomor_aju = '$escAju'");
            // Hapus juga record staging status, respon, dan data pabean yang belum terdaftar
            $koneksi->query("DELETE FROM bc_ceisa_data WHERE nomorAju = '$escAju'");
            $koneksi->query("DELETE FROM bc_ceisa_status WHERE nomor_aju = '$escAju'");
            $koneksi->query("DELETE FROM bc_ceisa_respons WHERE nomor_aju = '$escAju'");
            $deletedPosted = true;
        }

        return [
            'status'         => $isNotFound ? 'NOT_FOUND' : 'ERROR',
            'is_not_found'   => $isNotFound,
            'deleted_posted' => $deletedPosted,
            'raw_response'   => $response,
            'data'           => $data,
            'message'        => !empty($rawMsg) ? $rawMsg : 'Data tidak ditemukan atau respon gagal dari CEISA 4.0.'
        ];
    }
}

/**
 * Helper: Sinkronisasi Seluruh Dokumen Perusahaan dari API CEISA 4.0 via NPWP
 */
function ceisa_sync_company_all($targetCmpId, $targetNpwp) {
    global $koneksi;

    if (empty($targetNpwp)) {
        return ['status' => 'ERROR', 'message' => 'Nomor NPWP perusahaan tidak ditemukan!'];
    }

    $cmp_id = $targetCmpId;
    require_once __DIR__ . '/init.php';

    $hostprd = "https://apis-gw.beacukai.go.id/openapi/status?idPerusahaan=" . urlencode($targetNpwp);
    $bearer  = $_SESSION["token_" . $targetCmpId] ?? ($_SESSION["token"] ?? '');

    $curl = curl_init(); 
    curl_setopt_array($curl, [
        CURLOPT_URL => $hostprd,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "GET",
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $bearer",
            "Content-Type: application/json"
        ],
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        return ['status' => 'ERROR', 'message' => 'cURL Error: ' . $err];
    }

    if (isset($koneksi) && $koneksi) {
        $stmtLog = $koneksi->prepare("INSERT INTO bc_ceisa_respon_logs (respon) VALUES (?)");
        if ($stmtLog) {
            $stmtLog->bind_param("s", $response);
            $stmtLog->execute();
            $stmtLog->close();
        }
    }

    $data = json_decode($response, true);
    $status = $data['status'] ?? '';

    if ($status === 'Success' && isset($koneksi) && $koneksi) {
        $cntStatus = 0;
        $cntRespon = 0;

        if (!empty($data['dataStatus']) && is_array($data['dataStatus'])) {
            $stmtStatus = $koneksi->prepare("
                INSERT INTO bc_ceisa_status 
                (cmp_id, nomor_aju, nomor_daftar, tanggal_daftar, kode_proses, waktu_status, keterangan, kode_dokumen) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($data['dataStatus'] as $r) {
                $nomorAju      = $r['nomorAju'] ?? '';
                $nomorDaftar   = $r['nomorDaftar'] ?? '';
                $tanggalDaftar = $r['tanggalDaftar'] ?? '';
                $kodeProses    = $r['kodeProses'] ?? '';
                $waktuStatus   = $r['waktuStatus'] ?? '';
                $keterangan    = $r['keterangan'] ?? '';
                $kodeDokumen   = $r['kodeDokumen'] ?? '';

                if (!empty($nomorAju) && $stmtStatus) {
                    $stmtStatus->bind_param("ssssssss", $targetCmpId, $nomorAju, $nomorDaftar, $tanggalDaftar, $kodeProses, $waktuStatus, $keterangan, $kodeDokumen);
                    $stmtStatus->execute();
                    $cntStatus++;
                }
            }
            if ($stmtStatus) $stmtStatus->close();
        }

        if (!empty($data['dataRespon']) && is_array($data['dataRespon'])) {
            $stmtRespon = $koneksi->prepare("
                INSERT INTO bc_ceisa_respons 
                (cmp_id, nomor_aju, kode_respon, nomor_daftar, tanggal_daftar, nomor_respon, tanggal_respon, keterangan, pdf, kode_dokumen) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($data['dataRespon'] as $r) {
                $nomorAju      = $r['nomorAju'] ?? '';
                $kodeRespon    = $r['kodeRespon'] ?? '';
                $nomorDaftar   = $r['nomorDaftar'] ?? '';
                $tanggalDaftar = $r['tanggalDaftar'] ?? '';
                $nomorRespon   = $r['nomorRespon'] ?? '';
                $tanggalRespon = $r['tanggalRespon'] ?? '';
                $keterangan    = $r['keterangan'] ?? '';
                $pdf           = $r['pdf'] ?? '';
                $kodeDokumen   = $r['kodeDokumen'] ?? '';

                if (!empty($nomorAju) && $stmtRespon) {
                    $stmtRespon->bind_param("ssssssssss", $targetCmpId, $nomorAju, $kodeRespon, $nomorDaftar, $tanggalDaftar, $nomorRespon, $tanggalRespon, $keterangan, $pdf, $kodeDokumen);
                    $stmtRespon->execute();
                    $cntRespon++;

                    // Sinkronisasi otomatis ke tabel history SPPB (bc_ceisa_sppb) jika respon adalah SPPB/Persetujuan Pengeluaran
                    syncSppbRecord($koneksi, $targetCmpId, $nomorAju, $nomorDaftar, $tanggalDaftar, $kodeDokumen, $nomorRespon, $tanggalRespon, $keterangan, $pdf);
                }
            }
            if ($stmtRespon) $stmtRespon->close();
        }

        return [
            'status'     => 'OK',
            'cnt_status' => $cntStatus,
            'cnt_respon' => $cntRespon,
            'message'    => "Sinkronisasi seluruh dokumen selesai! Sebanyak $cntStatus record status dan $cntRespon record respon berhasil diselaraskan."
        ];
    } else {
        return [
            'status'  => 'ERROR',
            'message' => $data['message'] ?? 'Gagal mengambil status perusahaan dari CEISA 4.0.'
        ];
    }
}

// =========================================================================
// ROUTING 1: LEGACY REST API (KOMPATIBEL DENGAN transfer.php, docs.php, curl)
// =========================================================================
if (!empty($aju) && empty($action) && empty($_GET['ui']) && !isset($_GET['embedded'])) {
    $sync = ceisa_sync_single_aju($aju, $cmp_id);
    if ($sync['status'] === 'OK') {
        echo 'OK';
    } else {
        echo $sync['raw_response'] ?? $sync['message'];
    }
    exit;
}

// =========================================================================
// ROUTING 2: AJAX ENDPOINTS UNTUK DASHBOARD UI
// =========================================================================

// 2.1 Ambil Data Tabel & KPI Tracking
if ($action === 'get_data') {
    header('Content-Type: application/json; charset=utf-8');
    $escCmp = $koneksi->real_escape_string($cmp_id);
    $q      = trim($_GET['q'] ?? '');
    $dokBc  = trim($_GET['dok_bc'] ?? '');
    $statusCat = trim($_GET['status_cat'] ?? '');

    $sql = "
    SELECT 
        a.nomor_aju,
        COALESCE(NULLIF(a.h_kode_dokumen, ''), NULLIF(a.p_bc, ''), NULLIF(s_max.kode_dokumen, ''), NULLIF(r_max.kode_dokumen, ''), '') AS kode_dokumen,
        COALESCE(NULLIF(a.h_nomor_daftar, ''), NULLIF(a.p_nomor_daftar, ''), NULLIF(s_max.nomor_daftar, ''), NULLIF(r_max.nomor_daftar, ''), '-') AS nomor_daftar,
        COALESCE(NULLIF(a.h_tanggal_daftar, ''), NULLIF(a.p_tanggal_daftar, ''), NULLIF(s_max.tanggal_daftar, ''), NULLIF(r_max.tanggal_daftar, ''), '-') AS tanggal_daftar,
        s_max.kode_proses,
        s_max.keterangan AS status_keterangan,
        s_max.waktu_status,
        r_max.kode_respon,
        r_max.nomor_respon,
        r_max.tanggal_respon,
        r_max.keterangan AS respon_keterangan,
        r_max.pdf
    FROM (
        SELECT 
            u.nomor_aju,
            MAX(h.kode_dokumen) AS h_kode_dokumen,
            MAX(h.nomor_daftar) AS h_nomor_daftar,
            MAX(h.tanggal_daftar) AS h_tanggal_daftar,
            MAX(p.bc) AS p_bc,
            MAX(p.nomor_daftar) AS p_nomor_daftar,
            MAX(p.tanggal_daftar) AS p_tanggal_daftar
        FROM (
            SELECT nomor_aju FROM bc_ceisa_status WHERE (cmp_id = '$escCmp' OR cmp_id = '')
            UNION
            SELECT nomor_aju FROM bc_ceisa_respons WHERE (cmp_id = '$escCmp' OR cmp_id = '')
            UNION
            SELECT nomor_aju FROM bc_ceisa_header WHERE (cmp_id = '$escCmp' OR cmp_id = '')
            UNION
            SELECT nomor_aju FROM bc_ceisa_aju_posted WHERE (cmp_id = '$escCmp' OR cmp_id = '')
        ) u
        LEFT JOIN bc_ceisa_header h ON h.nomor_aju = u.nomor_aju AND (h.cmp_id = '$escCmp' OR h.cmp_id = '')
        LEFT JOIN bc_ceisa_aju_posted p ON p.nomor_aju = u.nomor_aju AND (p.cmp_id = '$escCmp' OR p.cmp_id = '')
        GROUP BY u.nomor_aju
    ) a
    LEFT JOIN (
        SELECT s1.nomor_aju, s1.kode_dokumen, s1.nomor_daftar, s1.tanggal_daftar, s1.kode_proses, s1.keterangan, s1.waktu_status
        FROM bc_ceisa_status s1
        INNER JOIN (
            SELECT nomor_aju, MAX(id) as max_id FROM bc_ceisa_status 
            WHERE (cmp_id = '$escCmp' OR cmp_id = '') GROUP BY nomor_aju
        ) s2 ON s1.id = s2.max_id
    ) s_max ON s_max.nomor_aju = a.nomor_aju
    LEFT JOIN (
        SELECT r1.nomor_aju, r1.kode_dokumen, r1.nomor_daftar, r1.tanggal_daftar, r1.kode_respon, r1.nomor_respon, r1.tanggal_respon, r1.keterangan, r1.pdf
        FROM bc_ceisa_respons r1
        INNER JOIN (
            SELECT nomor_aju, MAX(id) as max_id FROM bc_ceisa_respons 
            WHERE (cmp_id = '$escCmp' OR cmp_id = '') GROUP BY nomor_aju
        ) r2 ON r1.id = r2.max_id
    ) r_max ON r_max.nomor_aju = a.nomor_aju
    ORDER BY COALESCE(s_max.waktu_status, r_max.tanggal_respon, '1970-01-01') DESC
    ";

    $res = $koneksi->query($sql);
    $rows = [];
    $kpiTotal = 0;
    $kpiSelesai = 0;
    $kpiProses = 0;
    $kpiReject = 0;

    $fnFormatRespon = function($kdRespon, $keterangan) {
        $ket = trim((string)$keterangan);
        if (!empty($ket)) return $ket;
        $kr = trim((string)$kdRespon);
        if (empty($kr)) return '-';
        if (str_ends_with($kr, '03')) {
            return str_starts_with($kr, '30') ? 'NPE (Nota Pelayanan Ekspor)' : 'SPPB (Persetujuan Pengeluaran)';
        }
        if (str_ends_with($kr, '05')) return 'SPPD (Pengeluaran Sebagian)';
        if (str_ends_with($kr, '08')) return 'Billing / Penetapan Pembayaran';
        if (str_ends_with($kr, '07')) return 'Penetapan Jalur';
        if (str_ends_with($kr, '02')) return 'Penerimaan Dokumen';
        if (str_ends_with($kr, '01')) return 'Penolakan / Reject';
        if ($kr === '3015') return 'Pemeriksaan Fisik Ekspor';
        if ($kr === '1603') return 'Persetujuan BC 1.6';
        if ($kr === '1604') return 'Pemberitahuan BC 1.6';
        return "Respon Kode $kr";
    };

    $fnFormatProses = function($kdProses, $keterangan) {
        $ket = trim((string)$keterangan);
        if (!empty($ket)) return $ket;
        $kp = trim((string)$kdProses);
        if (empty($kp)) return '-';
        if ($kp === '100') return 'Perekaman Dokumen';
        if ($kp === '104') return 'Kirim Dokumen ke INSW';
        if ($kp === '105') return 'LNSW - Penerimaan Dokumen';
        if ($kp === '106') return 'LNSW - Cek Mandatory Content';
        if ($kp === '110') return 'Validasi Dokumen';
        if ($kp === '230') return 'Siap Jalur';
        if ($kp === '240') return 'Penjaluran';
        if ($kp === '501') return 'Gate Out TPS';
        if ($kp === '510') return 'Gate In TPB/KEK';
        if ($kp === '511') return 'Pembongkaran';
        if ($kp === '800') return 'Selesai Proses';
        return "Proses Kode $kp";
    };

    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $nomorAju     = $r['nomor_aju'] ?? '';
            $kdDok        = trim($r['kode_dokumen'] ?? '');
            $noDaftar     = trim($r['nomor_daftar'] ?? '');
            $statusKet    = trim($r['status_keterangan'] ?? '');
            $kdProses     = trim($r['kode_proses'] ?? '');
            $respKet      = trim($r['respon_keterangan'] ?? '');
            $kdRespon     = trim($r['kode_respon'] ?? '');

            $formattedStatusKet = $fnFormatProses($kdProses, $statusKet);
            $formattedRespKet   = $fnFormatRespon($kdRespon, $respKet);
            $r['status_keterangan'] = $formattedStatusKet;
            $r['respon_keterangan'] = $formattedRespKet;

            // Klasifikasi Kategori Dokumen
            $category = 'proses';
            $isSelesai = (
                in_array($kdRespon, ['2003', '2303', '2503', '2703', '3003', '4003', '4103', '61103', '61303']) ||
                stripos($formattedRespKet, 'SPPB') !== false ||
                stripos($formattedRespKet, 'NPE') !== false ||
                $kdProses === '800' ||
                stripos($formattedStatusKet, 'Selesai') !== false
            );
            $isReject = (
                stripos($formattedRespKet, 'Reject') !== false ||
                stripos($formattedRespKet, 'Tolak') !== false ||
                stripos($formattedRespKet, 'Batal') !== false ||
                stripos($formattedStatusKet, 'Reject') !== false ||
                in_array($kdProses, ['900', '999'])
            );
            $isBilling = (
                stripos($formattedRespKet, 'Billing') !== false ||
                stripos($formattedRespKet, 'Bayar') !== false ||
                substr($kdRespon, -2) === '08'
            );

            if ($isReject) {
                $category = 'reject';
                $kpiReject++;
            } elseif ($isSelesai) {
                $category = 'selesai';
                $kpiSelesai++;
            } elseif ($isBilling) {
                $category = 'billing';
                $kpiProses++;
            } else {
                $category = 'proses';
                $kpiProses++;
            }
            $kpiTotal++;

            $r['category'] = $category;

            // Filter Pencarian Text
            if (!empty($q)) {
                $searchStr = strtolower($nomorAju . ' ' . $kdDok . ' ' . $noDaftar . ' ' . $statusKet . ' ' . $respKet);
                if (strpos($searchStr, strtolower($q)) === false) {
                    continue;
                }
            }

            // Filter Jenis Dokumen BC
            if (!empty($dokBc) && $dokBc !== 'all') {
                $cleanDok = str_replace(['bc', 'BC', ' ', '.'], '', $dokBc);
                $curCleanDok = str_replace(['bc', 'BC', ' ', '.'], '', $kdDok);
                if ($cleanDok !== $curCleanDok) {
                    continue;
                }
            }

            // Filter Kategori Status
            if (!empty($statusCat) && $statusCat !== 'all') {
                if ($statusCat !== $category) {
                    continue;
                }
            }

            $rows[] = $r;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'data'   => $rows,
        'count'  => count($rows),
        'kpi'    => [
            'total'   => $kpiTotal,
            'selesai' => $kpiSelesai,
            'proses'  => $kpiProses,
            'reject'  => $kpiReject
        ]
    ]);
    exit;
}

// 2.2 Ambil Detail Timeline Riwayat Status & Respon Dokumen
if ($action === 'get_timeline') {
    header('Content-Type: application/json; charset=utf-8');
    $escAju = $koneksi->real_escape_string($aju);
    $escCmp = $koneksi->real_escape_string($cmp_id);

    if (empty($escAju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak ditentukan!']);
        exit;
    }

    // Riwayat Status Proses
    $sqlStatus = "SELECT kode_dokumen, nomor_aju, nomor_daftar, tanggal_daftar, kode_proses, waktu_status, keterangan 
                  FROM bc_ceisa_status 
                  WHERE nomor_aju = '$escAju' 
                  ORDER BY waktu_status ASC, id ASC";
    $resStatus = $koneksi->query($sqlStatus);
    $listStatus = [];
    if ($resStatus) {
        while ($s = $resStatus->fetch_assoc()) {
            $listStatus[] = $s;
        }
    }

    // Riwayat Respon Resmi
    $sqlResp = "SELECT kode_dokumen, nomor_aju, kode_respon, nomor_daftar, tanggal_daftar, nomor_respon, tanggal_respon, keterangan, pdf 
                FROM bc_ceisa_respons 
                WHERE nomor_aju = '$escAju' 
                ORDER BY id ASC";
    $resResp = $koneksi->query($sqlResp);
    $listResp = [];
    if ($resResp) {
        while ($rp = $resResp->fetch_assoc()) {
            $listResp[] = $rp;
        }
    }

    // Header info
    $sqlHdr = "SELECT kode_dokumen, nomor_daftar, tanggal_daftar, nomor_aju, nama_pernyataan, tanggal_pernyataan 
               FROM bc_ceisa_header 
               WHERE nomor_aju = '$escAju' LIMIT 1";
    $resHdr = $koneksi->query($sqlHdr);
    $header = $resHdr ? $resHdr->fetch_assoc() : null;

    // Respon mentah log
    $sqlLog = "SELECT respon FROM bc_ceisa_respon_logs 
               WHERE respon LIKE '%$escAju%' 
               ORDER BY id DESC LIMIT 1";
    $resLog = $koneksi->query($sqlLog);
    $rawLog = $resLog ? $resLog->fetch_assoc() : null;

    // Hitung status stepper visual (Tahapan 1-5)
    $stages = [
        1 => ['title' => 'Perekaman Dokumen', 'desc' => 'Perekaman & draft awal', 'done' => true, 'time' => $header['tanggal_pernyataan'] ?? ''],
        2 => ['title' => 'Pengiriman LNSW', 'desc' => 'Dokumen terkirim ke INSW', 'done' => false, 'time' => ''],
        3 => ['title' => 'Validasi Pabean', 'desc' => 'Validasi konten & No. Daftar terbit', 'done' => false, 'time' => ''],
        4 => ['title' => 'Penjaluran / Billing', 'desc' => 'Penetapan jalur & kode billing', 'done' => false, 'time' => ''],
        5 => ['title' => 'Keputusan Akhir (SPPB/NPE)', 'desc' => 'Dokumen selesai diproses', 'done' => false, 'time' => '']
    ];

    foreach ($listStatus as $st) {
        $kp = (string)($st['kode_proses'] ?? '');
        $kt = (string)($st['keterangan'] ?? '');
        $tm = (string)($st['waktu_status'] ?? '');

        if ($kp === '104' || $kp === '105' || $kp === '106' || stripos($kt, 'LNSW') !== false || stripos($kt, 'Kirim') !== false) {
            $stages[2]['done'] = true;
            if (empty($stages[2]['time'])) $stages[2]['time'] = $tm;
        }
        if ($kp === '110' || $kp === '200' || !empty($st['nomor_daftar'])) {
            $stages[3]['done'] = true;
            if (empty($stages[3]['time'])) $stages[3]['time'] = $tm;
        }
        if ($kp === '230' || $kp === '240' || $kp === '501' || $kp === '510' || stripos($kt, 'Jalur') !== false) {
            $stages[4]['done'] = true;
            if (empty($stages[4]['time'])) $stages[4]['time'] = $tm;
        }
        if ($kp === '511' || $kp === '800' || stripos($kt, 'Selesai') !== false) {
            $stages[5]['done'] = true;
            if (empty($stages[5]['time'])) $stages[5]['time'] = $tm;
        }
    }

    foreach ($listResp as $rp) {
        $kr = (string)($rp['kode_respon'] ?? '');
        $kt = (string)($rp['keterangan'] ?? '');
        $tm = (string)($rp['tanggal_respon'] ?? '');

        if (in_array($kr, ['2003', '2303', '2503', '2703', '3003', '4003', '4103', '61103', '61303']) || stripos($kt, 'SPPB') !== false || stripos($kt, 'NPE') !== false) {
            $stages[5]['done'] = true;
            if (empty($stages[5]['time'])) $stages[5]['time'] = $tm;
        }
        if (substr($kr, -2) === '08' || stripos($kt, 'Billing') !== false) {
            $stages[4]['done'] = true;
            if (empty($stages[4]['time'])) $stages[4]['time'] = $tm;
        }
    }

    echo json_encode([
        'status'      => 'OK',
        'nomor_aju'   => $aju,
        'header'      => $header,
        'stages'      => $stages,
        'data_status' => $listStatus,
        'data_respon' => $listResp,
        'raw_log'     => $rawLog
    ]);
    exit;
}

// 2.3 AJAX: Sinkronisasi Single AJU Live ke CEISA 4.0
if ($action === 'sync_single') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak boleh kosong!']);
        exit;
    }
    $res = ceisa_sync_single_aju($aju, $cmp_id);
    echo json_encode($res);
    exit;
}

// 2.4 AJAX: Sinkronisasi Seluruh Dokumen Perusahaan via NPWP
if ($action === 'sync_company') {
    header('Content-Type: application/json; charset=utf-8');
    $targetNpwp = !empty($_REQUEST['npwp']) ? trim($_REQUEST['npwp']) : $npwp;
    if (empty($targetNpwp)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor NPWP perusahaan tidak ditemukan!']);
        exit;
    }
    $res = ceisa_sync_company_all($cmp_id, $targetNpwp);
    echo json_encode($res);
    exit;
}

// =========================================================================
// ROUTING 3: TAMPILAN DASHBOARD TRACKING RESPON PABEAN (HTML5)
// =========================================================================
?>
<!DOCTYPE html>
<html lang="id" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="UTF-8">
    <title>Tracking Respon Pabean (CEISA 4.0) - <?= htmlspecialchars($namaperusahaan ?: 'CEISA 4.0') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (function() {
            var urlParams = new URLSearchParams(window.location.search);
            var theme = urlParams.get('theme') || localStorage.getItem('ceisa_theme') || 'light';
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.style.colorScheme = theme;
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Source+Sans+Pro:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js"></script>
    <style>
        :root, html[data-theme="light"], body[data-theme="light"] {
            --bg-base: #f0f2f5;
            --bg-card: #ffffff;
            --border-color: #e8e8e8;
            --text-main: #262626;
            --text-muted: #8c8c8c;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --success: #52c41a;
            --warning: #faad14;
            --danger: #ff4d4f;
            --purple: #722ed1;
            --table-hover: #f0f7ff;
            --table-header: #fafafa;
        }
        [data-theme='dark'], html[data-theme="dark"], body[data-theme="dark"] {
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --border-color: #303030;
            --text-main: rgba(255, 255, 255, 0.85);
            --text-muted: rgba(255, 255, 255, 0.45);
            --primary: #177ddc;
            --primary-hover: #1890ff;
            --success: #49aa19;
            --warning: #d89614;
            --danger: #a61d24;
            --purple: #531dab;
            --table-hover: #262638;
            --table-header: #191919;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', 'Source Sans Pro', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg-base);
            color: var(--text-main);
            font-size: 10.5px;
            line-height: 1.45;
            padding: 6px 10px;
            -webkit-font-smoothing: antialiased;
        }

        /* Top Header Info */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            padding: 6px 10px;
            margin-bottom: 6px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
            flex-wrap: wrap;
            gap: 6px;
        }
        .page-title {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text-main);
        }
        .page-title i {
            color: var(--primary);
            font-size: 14px;
        }
        .page-subtitle {
            font-size: 9.5px;
            color: var(--text-muted);
            margin-top: 1px;
        }
        .header-badges {
            display: flex;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
        }

        /* KPI Cards Grid */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 6px;
            margin-bottom: 6px;
        }
        .kpi-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            padding: 5px 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        }
        .kpi-card:hover {
            border-color: var(--primary);
            transform: translateY(-1px);
        }
        .kpi-card.active {
            border-color: var(--primary);
            background: rgba(24, 144, 255, 0.05);
        }
        .kpi-content {
            display: flex;
            flex-direction: column;
        }
        .kpi-label {
            font-size: 9px;
            color: var(--text-muted);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .kpi-value {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-main);
            margin-top: 1px;
        }
        .kpi-icon {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
        }
        .kpi-icon.blue { background: rgba(24, 144, 255, 0.12); color: var(--primary); }
        .kpi-icon.green { background: rgba(82, 196, 26, 0.12); color: var(--success); }
        .kpi-icon.orange { background: rgba(250, 173, 20, 0.12); color: var(--warning); }
        .kpi-icon.red { background: rgba(255, 77, 79, 0.12); color: var(--danger); }

        /* Toolbar & Filter Bar */
        .toolbar-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            padding: 5px 8px;
            margin-bottom: 6px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
        }
        .toolbar-left, .toolbar-right {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .search-input {
            height: 24px;
            padding: 0 8px 0 24px;
            border: 1px solid var(--border-color);
            border-radius: 3px;
            background: var(--bg-card);
            color: var(--text-main);
            font-size: 10px;
            outline: none;
            width: 180px;
            transition: border-color 0.2s;
        }
        .search-input:focus { border-color: var(--primary); }
        .search-box {
            position: relative;
            display: flex;
            align-items: center;
        }
        .search-box i {
            position: absolute;
            left: 7px;
            color: var(--text-muted);
            pointer-events: none;
            font-size: 10px;
        }
        .select-filter {
            height: 24px;
            padding: 0 6px;
            border: 1px solid var(--border-color);
            border-radius: 3px;
            background: var(--bg-card);
            color: var(--text-main);
            font-size: 10px;
            outline: none;
            cursor: pointer;
        }
        .select-filter:focus { border-color: var(--primary); }

        [data-theme='dark'] select option {
            background: #1f1f1f;
            color: #fff;
        }

        /* Buttons */
        .ant-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            height: 24px;
            padding: 0 8px;
            font-size: 10px;
            font-weight: 500;
            border-radius: 2px;
            cursor: pointer;
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-main);
            transition: all 0.2s ease;
            text-decoration: none;
            white-space: nowrap;
        }
        .ant-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        .ant-btn-primary {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
        }
        .ant-btn-primary:hover {
            background: var(--primary-hover);
            border-color: var(--primary-hover);
            color: #ffffff;
        }
        .ant-btn-success {
            background: var(--success);
            color: #ffffff;
            border-color: var(--success);
        }
        .ant-btn-success:hover {
            opacity: 0.9;
            color: #ffffff;
        }
        .ant-btn-sm {
            height: 20px;
            padding: 0 5px;
            font-size: 9px;
        }

        /* Table Card */
        .table-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            overflow: hidden;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        }
        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }
        table.ant-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
            text-align: left;
        }
        table.ant-table th {
            background: var(--table-header);
            color: var(--text-muted);
            font-weight: 600;
            padding: 5px 8px;
            font-size: 9.5px;
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }
        table.ant-table td {
            padding: 4px 8px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-main);
            vertical-align: middle;
        }
        table.ant-table tbody tr:hover td {
            background: var(--table-hover);
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 1px 5px;
            border-radius: 2px;
            font-size: 9px;
            font-weight: 600;
            line-height: 1.2;
            white-space: nowrap;
        }
        .badge-primary { background: rgba(24, 144, 255, 0.12); color: var(--primary); border: 1px solid rgba(24, 144, 255, 0.25); }
        .badge-success { background: rgba(82, 196, 26, 0.12); color: var(--success); border: 1px solid rgba(82, 196, 26, 0.25); }
        .badge-warning { background: rgba(250, 173, 20, 0.12); color: var(--warning); border: 1px solid rgba(250, 173, 20, 0.25); }
        .badge-danger  { background: rgba(255, 77, 79, 0.12); color: var(--danger); border: 1px solid rgba(255, 77, 79, 0.25); }
        .badge-purple  { background: rgba(114, 46, 209, 0.12); color: var(--purple); border: 1px solid rgba(114, 46, 209, 0.25); }
        .badge-muted   { background: rgba(140, 140, 140, 0.12); color: var(--text-muted); border: 1px solid var(--border-color); }

        .btn-copy {
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 2px 4px;
            border-radius: 3px;
            transition: color 0.2s;
        }
        .btn-copy:hover { color: var(--primary); }

        /* Modal Timeline Dialog */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.55);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1050;
            backdrop-filter: blur(2px);
            padding: 16px;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal-dialog {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            width: 100%;
            max-width: 860px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 8px 24px rgba(0,0,0,0.25);
            animation: modalFadeIn 0.2s ease-out;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-12px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .modal-header {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .modal-header h3 {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 16px;
            color: var(--text-muted);
            cursor: pointer;
            padding: 4px;
        }
        .modal-close:hover { color: var(--danger); }
        .modal-body {
            padding: 14px 16px;
            overflow-y: auto;
            flex: 1;
        }
        .modal-footer {
            padding: 10px 16px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            background: var(--table-header);
        }

        /* Visual Stepper / Progress Bar */
        .stepper-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: relative;
            margin: 12px 0 24px;
            padding: 0 10px;
        }
        .stepper-progress-bar {
            position: absolute;
            top: 16px;
            left: 40px;
            right: 40px;
            height: 2px;
            background: var(--border-color);
            z-index: 1;
        }
        .stepper-progress-active {
            position: absolute;
            top: 16px;
            left: 40px;
            height: 2px;
            background: var(--success);
            z-index: 2;
            transition: width 0.3s ease;
        }
        .step-item {
            position: relative;
            z-index: 3;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            width: 100px;
        }
        .step-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--bg-card);
            border: 2px solid var(--border-color);
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .step-item.done .step-icon {
            border-color: var(--success);
            background: var(--success);
            color: #ffffff;
        }
        .step-item.active .step-icon {
            border-color: var(--primary);
            background: var(--primary);
            color: #ffffff;
            box-shadow: 0 0 0 3px rgba(24, 144, 255, 0.25);
        }
        .step-title {
            font-size: 10.5px;
            font-weight: 600;
            color: var(--text-main);
            margin-top: 6px;
        }
        .step-time {
            font-size: 9.5px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        /* Tabs inside Modal */
        .modal-tabs {
            display: flex;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 12px;
            gap: 16px;
        }
        .modal-tab-btn {
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            padding: 6px 4px;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s;
        }
        .modal-tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; }

        /* JSON Code Viewer */
        .code-box {
            background: #0d1117;
            color: #c9d1d9;
            padding: 12px;
            border-radius: 4px;
            font-family: Consolas, "Courier New", monospace;
            font-size: 10px;
            overflow-x: auto;
            max-height: 260px;
        }
    </style>
</head>
<body>

    <!-- Header Section -->
    <div class="page-header">
        <div>
            <div class="page-title">
                <i class="fa-solid fa-satellite-dish"></i>
                <span>Tracking Respon Dokumen Pabean (CEISA 4.0)</span>
            </div>
            <div class="page-subtitle">
                Perusahaan: <strong><?= htmlspecialchars($namaperusahaan ?: 'Umum') ?></strong> 
                &bull; NPWP: <code><?= htmlspecialchars($npwp ?: '-') ?></code>
                &bull; Company ID: <code><?= htmlspecialchars($cmp_id ?: '-') ?></code>
            </div>
        </div>
        <div class="header-badges">
            <span class="badge badge-success"><i class="fa-solid fa-circle-check"></i> Host to Host CEISA 4.0</span>
            <span class="badge badge-primary"><i class="fa-solid fa-cloud"></i> Portal Bea Cukai</span>
        </div>
    </div>

    <!-- KPI Summary Cards -->
    <div class="kpi-grid">
        <div class="kpi-card" onclick="filterByCard('all')">
            <div class="kpi-content">
                <div class="kpi-label">Total Dokumen Terlacak</div>
                <div class="kpi-value" id="kpi-total">0</div>
            </div>
            <div class="kpi-icon blue"><i class="fa-solid fa-folder-tree"></i></div>
        </div>
        <div class="kpi-card" onclick="filterByCard('selesai')">
            <div class="kpi-content">
                <div class="kpi-label">Selesai (SPPB / NPE)</div>
                <div class="kpi-value" id="kpi-selesai" style="color:var(--success);">0</div>
            </div>
            <div class="kpi-icon green"><i class="fa-solid fa-circle-check"></i></div>
        </div>
        <div class="kpi-card" onclick="filterByCard('proses')">
            <div class="kpi-content">
                <div class="kpi-label">Dalam Proses / Billing</div>
                <div class="kpi-value" id="kpi-proses" style="color:var(--warning);">0</div>
            </div>
            <div class="kpi-icon orange"><i class="fa-solid fa-clock-rotate-left"></i></div>
        </div>
        <div class="kpi-card" onclick="filterByCard('reject')">
            <div class="kpi-content">
                <div class="kpi-label">Perlu Tindakan / Reject</div>
                <div class="kpi-value" id="kpi-reject" style="color:var(--danger);">0</div>
            </div>
            <div class="kpi-icon red"><i class="fa-solid fa-triangle-exclamation"></i></div>
        </div>
    </div>

    <!-- Action & Filter Bar -->
    <div class="toolbar-card">
        <div class="toolbar-left">
            <div class="search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="filter-search" class="search-input" placeholder="Cari Nomor AJU / No. Daftar / Respon..." oninput="handleSearch()">
            </div>
            <select id="filter-dok" class="select-filter" onchange="loadTrackingData()">
                <option value="all">Semua Dokumen BC</option>
                <option value="20">BC 2.0 (Impor Umum)</option>
                <option value="23">BC 2.3 (Impor Kawasan Berikat)</option>
                <option value="25">BC 2.5 (Pengeluaran TPB ke TLDDP)</option>
                <option value="261">BC 2.6.1 (Barang Sementara Out)</option>
                <option value="262">BC 2.6.2 (Barang Sementara In)</option>
                <option value="27">BC 2.7 (TPB ke TPB)</option>
                <option value="30">BC 3.0 (Ekspor)</option>
                <option value="40">BC 4.0 (TLDDP ke TPB)</option>
                <option value="41">BC 4.1 (Bahan Baku ke TLDDP)</option>
            </select>
            <select id="filter-status" class="select-filter" onchange="loadTrackingData()">
                <option value="all">Semua Status</option>
                <option value="selesai">Selesai (SPPB / NPE / 800)</option>
                <option value="proses">Dalam Proses (Validasi / Jalur)</option>
                <option value="billing">Billing / Pungutan</option>
                <option value="reject">Reject / Batal / Perbaikan</option>
            </select>
        </div>
        <div class="toolbar-right">
            <!-- Form Lacak Nomor AJU Cepat -->
            <div style="display:flex; align-items:center; gap:4px;">
                <input type="text" id="quick-aju-input" class="search-input" style="width:180px;" placeholder="Input Nomor AJU (26 digit)..." maxlength="35">
                <button class="ant-btn ant-btn-primary" onclick="syncSingleAjuQuick()" title="Lacak & Tarik Status Live dari CEISA 4.0">
                    <i class="fa-solid fa-bolt"></i> Lacak AJU
                </button>
            </div>

            <!-- Tombol Sync Seluruh Dokumen Perusahaan -->
            <button class="ant-btn ant-btn-success" onclick="syncCompanyDocs()" title="Tarik seluruh update dokumen aktif perusahaan via NPWP">
                <i class="fa-solid fa-cloud-arrow-down"></i> Sync Perusahaan
            </button>

            <!-- Refresh Button -->
            <button class="ant-btn" onclick="loadTrackingData()" title="Segarkan Tampilan">
                <i class="fa-solid fa-arrows-rotate"></i> Refresh
            </button>
        </div>
    </div>

    <!-- Data Table Container -->
    <div class="table-card">
        <div class="table-responsive">
            <table class="ant-table" id="tracking-table">
                <thead>
                    <tr>
                        <th style="width:35px; text-align:center;">#</th>
                        <th style="width:75px;">Dokumen</th>
                        <th style="width:230px;">Nomor Pengajuan (AJU)</th>
                        <th style="width:130px;">No & Tgl Daftar</th>
                        <th>Status Proses Terakhir</th>
                        <th>Respon Terakhir Pabean</th>
                        <th style="width:130px;">Dokumen Respon (PDF)</th>
                        <th style="width:130px;">Waktu Update</th>
                        <th style="width:130px; text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="tracking-tbody">
                    <tr>
                        <td colspan="9" style="text-align:center; padding:30px; color:var(--text-muted);">
                            <i class="fa-solid fa-spinner fa-spin" style="font-size:16px;"></i><br><br>Memuat data respon kepabeanan...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal Dialog Timeline Alur Dokumen -->
    <div class="modal-overlay" id="timeline-modal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3>
                    <i class="fa-solid fa-timeline" style="color:var(--primary);"></i>
                    <span>Detail Alur Dokumen Pabean</span>
                </h3>
                <button class="modal-close" onclick="closeTimelineModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <!-- Info Header AJU -->
                <div style="background:var(--table-header); border:1px solid var(--border-color); border-radius:4px; padding:8px 12px; margin-bottom:12px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                    <div>
                        <div style="font-size:10px; color:var(--text-muted); text-transform:uppercase;">Nomor Pengajuan (AJU)</div>
                        <div style="font-size:12px; font-weight:700; font-family:monospace;" id="modal-aju-text">-</div>
                    </div>
                    <div>
                        <div style="font-size:10px; color:var(--text-muted); text-transform:uppercase;">Jenis Dokumen</div>
                        <div id="modal-dok-badge"><span class="badge badge-primary">-</span></div>
                    </div>
                    <div>
                        <div style="font-size:10px; color:var(--text-muted); text-transform:uppercase;">Nomor & Tgl Pendaftaran</div>
                        <div style="font-size:11px; font-weight:600;" id="modal-daftar-text">-</div>
                    </div>
                    <div>
                        <button class="ant-btn ant-btn-sm ant-btn-primary" id="modal-sync-btn" onclick="syncModalAju()">
                            <i class="fa-solid fa-rotate"></i> Sync Ulang Live
                        </button>
                    </div>
                </div>

                <!-- Visual Stepper Progress Bar -->
                <div class="stepper-container" id="visual-stepper">
                    <div class="stepper-progress-bar"></div>
                    <div class="stepper-progress-active" id="stepper-active-bar" style="width:0%;"></div>
                    <!-- Steps injected dynamically -->
                </div>

                <!-- Tabs -->
                <div class="modal-tabs">
                    <button class="modal-tab-btn active" onclick="switchModalTab('status')">
                        <i class="fa-solid fa-list-check"></i> Riwayat Status Proses (<span id="count-tab-status">0</span>)
                    </button>
                    <button class="modal-tab-btn" onclick="switchModalTab('respon')">
                        <i class="fa-solid fa-file-invoice"></i> Riwayat Respon Resmi (<span id="count-tab-respon">0</span>)
                    </button>
                    <button class="modal-tab-btn" onclick="switchModalTab('raw')">
                        <i class="fa-solid fa-code"></i> Log Respon Mentah (JSON)
                    </button>
                </div>

                <!-- Tab 1: Status Proses -->
                <div class="tab-pane active" id="pane-status">
                    <table class="ant-table" style="font-size:10.5px;">
                        <thead>
                            <tr>
                                <th style="width:30px;">#</th>
                                <th style="width:130px;">Waktu Status</th>
                                <th style="width:80px;">Kode Proses</th>
                                <th>Keterangan Proses</th>
                                <th style="width:90px;">No. Daftar</th>
                            </tr>
                        </thead>
                        <tbody id="modal-status-tbody">
                            <!-- Injected by JS -->
                        </tbody>
                    </table>
                </div>

                <!-- Tab 2: Respon Resmi -->
                <div class="tab-pane" id="pane-respon">
                    <table class="ant-table" style="font-size:10.5px;">
                        <thead>
                            <tr>
                                <th style="width:30px;">#</th>
                                <th style="width:90px;">Kode Respon</th>
                                <th style="width:140px;">No. Respon</th>
                                <th style="width:120px;">Tgl Respon</th>
                                <th>Keterangan Respon</th>
                                <th style="width:90px; text-align:center;">Dokumen PDF</th>
                            </tr>
                        </thead>
                        <tbody id="modal-respon-tbody">
                            <!-- Injected by JS -->
                        </tbody>
                    </table>
                </div>

                <!-- Tab 3: Raw Log -->
                <div class="tab-pane" id="pane-raw">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                        <span style="color:var(--text-muted); font-size:10px;">Payload JSON mentah dari CEISA 4.0:</span>
                        <button class="ant-btn ant-btn-sm" onclick="copyRawJson()"><i class="fa-solid fa-copy"></i> Salin JSON</button>
                    </div>
                    <pre class="code-box" id="raw-json-box">// Menunggu data...</pre>
                </div>
            </div>
            <div class="modal-footer">
                <button class="ant-btn" onclick="closeTimelineModal()">Tutup</button>
            </div>
        </div>
    </div>

    <!-- Script Application Logic -->
    <script>
        const cmpId = '<?= addslashes($cmp_id) ?>';
        const userNpwp = '<?= addslashes($npwp) ?>';
        let rawTrackingData = [];
        let activeModalAju = '';

        // Helper Functions
        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            return String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function escapeJs(str) {
            if (str === null || str === undefined) return '';
            return String(str).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
        }

        function copyToClipboard(text, msg = 'Nomor AJU berhasil disalin ke clipboard!') {
            navigator.clipboard.writeText(text).then(() => {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: msg,
                    showConfirmButton: false,
                    timer: 1500
                });
            }).catch(err => {
                console.error(err);
            });
        }

        // Theme Sync from parent frame (index.php)
        window.addEventListener('message', (e) => {
            if (e.data && e.data.theme) {
                document.documentElement.setAttribute('data-theme', e.data.theme);
            }
        });

        // 1. Load Tracking Data
        async function loadTrackingData() {
            const q = document.getElementById('filter-search').value.trim();
            const dok = document.getElementById('filter-dok').value;
            const statusCat = document.getElementById('filter-status').value;

            const tbody = document.getElementById('tracking-tbody');
            tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:30px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin" style="font-size:16px;"></i><br><br>Memuat data respon kepabeanan...</td></tr>`;

            try {
                const res = await fetch(`statusaju.php?action=get_data&cmp_id=${encodeURIComponent(cmpId)}&q=${encodeURIComponent(q)}&dok_bc=${encodeURIComponent(dok)}&status_cat=${encodeURIComponent(statusCat)}`);
                const result = await res.json();

                if (result.status === 'OK') {
                    rawTrackingData = result.data || [];
                    renderKPI(result.kpi);
                    renderTable(rawTrackingData);
                } else {
                    tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:20px; color:var(--danger);"><i class="fa-solid fa-triangle-exclamation"></i> Gagal memuat data: ${escapeHtml(result.message || 'Error')}</td></tr>`;
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:20px; color:var(--danger);"><i class="fa-solid fa-triangle-exclamation"></i> Terjadi kesalahan jaringan: ${escapeHtml(err.message)}</td></tr>`;
            }
        }

        // 2. Render KPI Cards
        function renderKPI(kpi) {
            if (!kpi) return;
            document.getElementById('kpi-total').textContent = (kpi.total || 0).toLocaleString();
            document.getElementById('kpi-selesai').textContent = (kpi.selesai || 0).toLocaleString();
            document.getElementById('kpi-proses').textContent = (kpi.proses || 0).toLocaleString();
            document.getElementById('kpi-reject').textContent = (kpi.reject || 0).toLocaleString();
        }

        function filterByCard(cat) {
            document.querySelectorAll('.kpi-card').forEach(c => c.classList.remove('active'));
            document.getElementById('filter-status').value = cat;
            loadTrackingData();
        }

        function handleSearch() {
            clearTimeout(window._searchTimer);
            window._searchTimer = setTimeout(() => {
                loadTrackingData();
            }, 300);
        }

        // 3. Render Table
        function renderTable(data) {
            const tbody = document.getElementById('tracking-tbody');
            if (!data || data.length === 0) {
                tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:40px; color:var(--text-muted);"><i class="fa-solid fa-inbox" style="font-size:24px; margin-bottom:8px;"></i><br>Tidak ada riwayat dokumen pabean yang cocok dengan filter.</td></tr>`;
                return;
            }

            let html = '';
            data.forEach((r, idx) => {
                const aju = r.nomor_aju || '-';
                const dok = r.kode_dokumen ? `BC ${r.kode_dokumen}` : '-';
                const noDaftar = r.nomor_daftar && r.nomor_daftar !== '-' ? r.nomor_daftar : '-';
                const tglDaftar = r.tanggal_daftar && r.tanggal_daftar !== '-' ? r.tanggal_daftar : '-';
                const statusKet = r.status_keterangan || '-';
                const kdProses = r.kode_proses ? `<span class="badge badge-muted">${escapeHtml(r.kode_proses)}</span> ` : '';
                const waktuStatus = r.waktu_status || '-';
                const respKet = r.respon_keterangan || '-';
                const kdRespon = r.kode_respon ? `<span class="badge badge-primary">${escapeHtml(r.kode_respon)}</span> ` : '';
                const noRespon = r.nomor_respon ? `<div style="font-size:9.5px; color:var(--text-muted);">${escapeHtml(r.nomor_respon)}</div>` : '';
                const pdf = r.pdf || '';
                const cat = r.category || 'proses';

                // Status Badge Color
                let statusBadge = 'badge-muted';
                if (cat === 'selesai') statusBadge = 'badge-success';
                else if (cat === 'reject') statusBadge = 'badge-danger';
                else if (cat === 'billing') statusBadge = 'badge-warning';
                else if (cat === 'proses') statusBadge = 'badge-primary';

                // Dokumen BC Badge
                let dokBadge = 'badge-primary';
                if (dok.includes('2.3') || dok.includes('23')) dokBadge = 'badge-primary';
                else if (dok.includes('3.0') || dok.includes('30')) dokBadge = 'badge-purple';
                else if (dok.includes('4.0') || dok.includes('40')) dokBadge = 'badge-success';
                else if (dok.includes('2.7') || dok.includes('27')) dokBadge = 'badge-warning';

                // PDF Action
                let pdfButton = '<span class="badge badge-muted" style="color:var(--text-muted); font-size:9.5px;">Belum Terbit</span>';
                if (pdf || cat === 'selesai') {
                    const pdfUrl = `pdf.php?aju=${encodeURIComponent(aju)}${pdf ? `&pdf=${encodeURIComponent(pdf)}` : ''}`;
                    pdfButton = `
                        <a href="${pdfUrl}" target="_blank" class="ant-btn ant-btn-sm" style="color:var(--primary); border-color:var(--primary);">
                            <i class="fa-solid fa-file-pdf" style="color:var(--danger);"></i> Unduh PDF
                        </a>
                    `;
                }

                html += `
                    <tr>
                        <td style="text-align:center; color:var(--text-muted);">${idx + 1}</td>
                        <td><span class="badge ${dokBadge}">${escapeHtml(dok)}</span></td>
                        <td>
                            <strong style="font-family:monospace; letter-spacing:0.3px;">${escapeHtml(aju)}</strong>
                            <button class="btn-copy" onclick="copyToClipboard('${escapeJs(aju)}')" title="Salin Nomor AJU">
                                <i class="fa-regular fa-copy"></i>
                            </button>
                        </td>
                        <td>
                            <strong>${escapeHtml(noDaftar)}</strong>
                            <div style="font-size:9.5px; color:var(--text-muted);">${escapeHtml(tglDaftar)}</div>
                        </td>
                        <td>
                            <div>${kdProses}<span class="badge ${statusBadge}">${escapeHtml(statusKet)}</span></div>
                            <div style="font-size:9.5px; color:var(--text-muted); margin-top:2px;">${escapeHtml(waktuStatus)}</div>
                        </td>
                        <td>
                            <div>${kdRespon}<strong>${escapeHtml(respKet)}</strong></div>
                            ${noRespon}
                        </td>
                        <td>${pdfButton}</td>
                        <td style="font-size:10px; color:var(--text-muted);">${escapeHtml(waktuStatus !== '-' ? waktuStatus : (r.tanggal_respon || '-'))}</td>
                        <td style="text-align:center; white-space:nowrap;">
                            <button class="ant-btn ant-btn-sm" onclick="openTimelineModal('${escapeJs(aju)}')" title="Lihat Riwayat Alur Lengkap">
                                <i class="fa-solid fa-timeline" style="color:var(--primary);"></i> Timeline
                            </button>
                            <button class="ant-btn ant-btn-sm ant-btn-primary" onclick="syncSingleAjuDirect('${escapeJs(aju)}')" title="Sync Live dari CEISA 4.0">
                                <i class="fa-solid fa-rotate"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });

            tbody.innerHTML = html;
        }

        // 4. Modal Timeline Handling
        async function openTimelineModal(aju) {
            activeModalAju = aju;
            document.getElementById('modal-aju-text').textContent = aju;
            document.getElementById('modal-dok-badge').innerHTML = `<span class="badge badge-muted">Memuat...</span>`;
            document.getElementById('modal-daftar-text').textContent = '-';
            document.getElementById('modal-status-tbody').innerHTML = `<tr><td colspan="5" style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat riwayat status...</td></tr>`;
            document.getElementById('modal-respon-tbody').innerHTML = `<tr><td colspan="6" style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat riwayat respon...</td></tr>`;
            document.getElementById('raw-json-box').textContent = '// Menghubungi database...';

            document.getElementById('timeline-modal').classList.add('active');

            try {
                const res = await fetch(`statusaju.php?action=get_timeline&aju=${encodeURIComponent(aju)}&cmp_id=${encodeURIComponent(cmpId)}`);
                const result = await res.json();

                if (result.status === 'OK') {
                    renderModalDetails(result);
                } else {
                    document.getElementById('modal-status-tbody').innerHTML = `<tr><td colspan="5" style="color:var(--danger); text-align:center;">${escapeHtml(result.message)}</td></tr>`;
                }
            } catch (err) {
                document.getElementById('modal-status-tbody').innerHTML = `<tr><td colspan="5" style="color:var(--danger); text-align:center;">Gagal memuat timeline: ${escapeHtml(err.message)}</td></tr>`;
            }
        }

        function closeTimelineModal() {
            document.getElementById('timeline-modal').classList.remove('active');
        }

        function renderModalDetails(data) {
            const hdr = data.header || {};
            const dok = hdr.kode_dokumen ? `BC ${hdr.kode_dokumen}` : (data.data_status[0]?.kode_dokumen ? `BC ${data.data_status[0].kode_dokumen}` : '-');
            const noDaftar = hdr.nomor_daftar || data.data_status[0]?.nomor_daftar || '-';
            const tglDaftar = hdr.tanggal_daftar || data.data_status[0]?.tanggal_daftar || '-';

            document.getElementById('modal-dok-badge').innerHTML = `<span class="badge badge-primary">${escapeHtml(dok)}</span>`;
            document.getElementById('modal-daftar-text').textContent = noDaftar !== '-' ? `${noDaftar} (Tgl: ${tglDaftar})` : 'Belum Terdaftar';

            // Visual Stepper
            const stages = data.stages || {};
            let stepperHtml = `
                <div class="stepper-progress-bar"></div>
                <div class="stepper-progress-active" id="stepper-active-bar"></div>
            `;
            let doneCount = 0;
            const stageKeys = [1, 2, 3, 4, 5];
            stageKeys.forEach(k => {
                const st = stages[k] || { title: '', desc: '', done: false, time: '' };
                if (st.done) doneCount++;
                const isCurrentActive = st.done && (k === 5 || !stages[k + 1]?.done);
                const stepClass = st.done ? (isCurrentActive ? 'done active' : 'done') : '';
                const icon = st.done ? '<i class="fa-solid fa-check"></i>' : k;

                stepperHtml += `
                    <div class="step-item ${stepClass}">
                        <div class="step-icon">${icon}</div>
                        <div class="step-title">${escapeHtml(st.title)}</div>
                        <div class="step-time">${escapeHtml(st.time ? st.time.substring(0, 16) : '')}</div>
                    </div>
                `;
            });
            document.getElementById('visual-stepper').innerHTML = stepperHtml;
            const activePercent = Math.max(0, Math.min(100, (doneCount - 1) * 25));
            setTimeout(() => {
                const bar = document.getElementById('stepper-active-bar');
                if (bar) bar.style.width = activePercent + '%';
            }, 50);

            // Tab 1: Status
            const statusList = data.data_status || [];
            document.getElementById('count-tab-status').textContent = statusList.length;
            if (statusList.length === 0) {
                document.getElementById('modal-status-tbody').innerHTML = `<tr><td colspan="5" style="text-align:center; padding:20px; color:var(--text-muted);">Belum ada riwayat proses pabean untuk dokumen ini.</td></tr>`;
            } else {
                let sHtml = '';
                statusList.forEach((s, idx) => {
                    sHtml += `
                        <tr>
                            <td>${idx + 1}</td>
                            <td><code>${escapeHtml(s.waktu_status || '-')}</code></td>
                            <td><span class="badge badge-muted">${escapeHtml(s.kode_proses || '-')}</span></td>
                            <td><strong>${escapeHtml(s.keterangan || '-')}</strong></td>
                            <td>${escapeHtml(s.nomor_daftar || '-')}</td>
                        </tr>
                    `;
                });
                document.getElementById('modal-status-tbody').innerHTML = sHtml;
            }

            // Tab 2: Respon
            const respList = data.data_respon || [];
            document.getElementById('count-tab-respon').textContent = respList.length;
            if (respList.length === 0) {
                document.getElementById('modal-respon-tbody').innerHTML = `<tr><td colspan="6" style="text-align:center; padding:20px; color:var(--text-muted);">Belum ada respon resmi yang diterbitkan oleh Bea Cukai.</td></tr>`;
            } else {
                let rHtml = '';
                respList.forEach((rp, idx) => {
                    let pdfBtn = '<span style="color:var(--text-muted);">-</span>';
                    if (rp.pdf) {
                        pdfBtn = `<a href="pdf.php?aju=${encodeURIComponent(data.nomor_aju)}&pdf=${encodeURIComponent(rp.pdf)}" target="_blank" class="ant-btn ant-btn-sm" style="color:var(--primary);"><i class="fa-solid fa-file-pdf"></i> Unduh</a>`;
                    }
                    rHtml += `
                        <tr>
                            <td>${idx + 1}</td>
                            <td><span class="badge badge-primary">${escapeHtml(rp.kode_respon || '-')}</span></td>
                            <td><strong>${escapeHtml(rp.nomor_respon || '-')}</strong></td>
                            <td>${escapeHtml(rp.tanggal_respon || '-')}</td>
                            <td>${escapeHtml(rp.keterangan || '-')}</td>
                            <td style="text-align:center;">${pdfBtn}</td>
                        </tr>
                    `;
                });
                document.getElementById('modal-respon-tbody').innerHTML = rHtml;
            }

            // Tab 3: Raw Log
            if (data.raw_log && data.raw_log.respon) {
                try {
                    const parsed = JSON.parse(data.raw_log.respon);
                    document.getElementById('raw-json-box').textContent = JSON.stringify(parsed, null, 2);
                } catch (e) {
                    document.getElementById('raw-json-box').textContent = data.raw_log.respon;
                }
            } else {
                document.getElementById('raw-json-box').textContent = '// Belum ada log respon mentah yang tersimpan di bc_ceisa_respon_logs untuk nomor AJU ini.';
            }
        }

        function switchModalTab(tab) {
            document.querySelectorAll('.modal-tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));

            if (tab === 'status') {
                event.currentTarget.classList.add('active');
                document.getElementById('pane-status').classList.add('active');
            } else if (tab === 'respon') {
                event.currentTarget.classList.add('active');
                document.getElementById('pane-respon').classList.add('active');
            } else if (tab === 'raw') {
                event.currentTarget.classList.add('active');
                document.getElementById('pane-raw').classList.add('active');
            }
        }

        function copyRawJson() {
            const text = document.getElementById('raw-json-box').textContent;
            copyToClipboard(text, 'JSON payload berhasil disalin!');
        }

        // 5. Sync Live Single AJU
        async function syncSingleAjuDirect(aju) {
            Swal.fire({
                title: 'Tarik / Sync Respon CEISA',
                html: `Menghubungi portal Bea Cukai untuk memeriksa respon dokumen AJU:<br><b>${escapeHtml(aju)}</b>...`,
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            try {
                const res = await fetch(`statusaju.php?action=sync_single&aju=${encodeURIComponent(aju)}&cmp_id=${encodeURIComponent(cmpId)}`);
                const result = await res.json();

                if (result.status === 'OK') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Sinkronisasi Berhasil!',
                        text: result.message || 'Status dan respon dokumen berhasil diperbarui.',
                        timer: 2000,
                        showConfirmButton: false
                    });
                    loadTrackingData();
                } else {
                    const isNotFound = result.is_not_found || (result.message && result.message.toLowerCase().includes('tidak ditemukan'));
                    if (isNotFound) {
                        Swal.fire({
                            icon: 'warning',
                            title: '<span style="font-size:18px; font-weight:700; color:#d97706;">Data Tidak Ditemukan di CEISA 4.0</span>',
                            html: `
                                <div style="text-align:center; margin-bottom:14px;">
                                    <div style="display:inline-block; padding:5px 14px; background:#f1f5f9; border:1px solid #cbd5e1; border-radius:6px; font-family:monospace; font-size:13px; font-weight:700; color:#1e293b; letter-spacing:0.5px;">
                                        ${escapeHtml(aju)}
                                    </div>
                                </div>
                                <div style="font-size:13px; color:#475569; line-height:1.6; margin-bottom:14px; text-align:left;">
                                    Portal CEISA 4.0 menyatakan dokumen pengajuan ini <b>tidak ditemukan</b> dalam database Bea Cukai (kemungkinan belum terkirim atau pengiriman sebelumnya ditolak/dibatalkan).
                                </div>
                                <div style="background:#eff6ff; border:1px solid #bfdbfe; border-left:4px solid #3b82f6; border-radius:6px; padding:12px 14px; text-align:left; font-size:12px; color:#1e40af; margin-bottom:14px; line-height:1.5;">
                                    <div style="font-weight:700; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                        <i class="fas fa-undo-alt" style="color:#2563eb;"></i> Status Pengiriman Telah Direset
                                    </div>
                                    Tanda status pengiriman (<code>bc_ceisa_aju_posted</code>) telah dibersihkan otomatis sehingga baris dokumen pada tabel kembali <b>putih</b> (Belum Kirim).
                                </div>
                                <details style="text-align:left; font-size:11px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:8px 12px; cursor:pointer;">
                                    <summary style="font-weight:600; color:#64748b; outline:none; user-select:none;">Detail Respon Mentah CEISA</summary>
                                    <pre style="margin:8px 0 0 0; padding:8px; background:#ffffff; border:1px solid #e2e8f0; border-radius:4px; font-family:monospace; font-size:11px; color:#334155; white-space:pre-wrap; word-break:break-all; max-height:140px; overflow-y:auto;">${escapeHtml(result.raw_response || result.message || JSON.stringify(result))}</pre>
                                </details>
                            `,
                            confirmButtonText: '<i class="fas fa-check" style="margin-right:6px;"></i> Mengerti',
                            confirmButtonColor: '#3b82f6',
                            width: '540px'
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: '<span style="font-size:18px; font-weight:700; color:#dc2626;">Respon Gagal dari CEISA 4.0</span>',
                            html: `
                                <div style="text-align:center; margin-bottom:14px;">
                                    <div style="display:inline-block; padding:5px 14px; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; font-family:monospace; font-size:13px; font-weight:700; color:#991b1b; letter-spacing:0.5px;">
                                        ${escapeHtml(aju)}
                                    </div>
                                </div>
                                <div style="font-size:13px; color:#475569; line-height:1.6; margin-bottom:14px; text-align:left;">
                                    ${escapeHtml(result.message || 'Gagal menyelaraskan status dokumen dari CEISA 4.0.')}
                                </div>
                                <details style="text-align:left; font-size:11px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:8px 12px; cursor:pointer;">
                                    <summary style="font-weight:600; color:#64748b; outline:none; user-select:none;">Detail Respon Teknis</summary>
                                    <pre style="margin:8px 0 0 0; padding:8px; background:#ffffff; border:1px solid #e2e8f0; border-radius:4px; font-family:monospace; font-size:11px; color:#334155; white-space:pre-wrap; word-break:break-all; max-height:140px; overflow-y:auto;">${escapeHtml(result.raw_response || result.message || JSON.stringify(result))}</pre>
                                </details>
                            `,
                            confirmButtonText: 'Tutup',
                            confirmButtonColor: '#4b5563',
                            width: '540px'
                        });
                    }
                    loadTrackingData();
                }
            } catch (err) {
                Swal.fire('Error', 'Terjadi kesalahan komunikasi jaringan: ' + err.message, 'error');
            }
        }

        function syncModalAju() {
            if (activeModalAju) {
                syncSingleAjuDirect(activeModalAju).then(() => {
                    openTimelineModal(activeModalAju);
                });
            }
        }

        function syncSingleAjuQuick() {
            const input = document.getElementById('quick-aju-input');
            const aju = input.value.trim();
            if (!aju) {
                Swal.fire('Peringatan', 'Silakan ketik atau tempelkan Nomor Pengajuan (AJU) 26 digit terlebih dahulu!', 'warning');
                return;
            }
            syncSingleAjuDirect(aju).then(() => {
                input.value = '';
            });
        }

        // 6. Sync Seluruh Dokumen Perusahaan via NPWP
        async function syncCompanyDocs() {
            const confirm = await Swal.fire({
                title: 'Sinkronisasi Seluruh Dokumen?',
                html: `Sistem akan mengambil seluruh status & respon pabean aktif dari CEISA 4.0 untuk NPWP:<br><b>${escapeHtml(userNpwp || cmpId)}</b>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#1890ff',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: 'Ya, Mulai Sinkronisasi',
                cancelButtonText: 'Batal'
            });

            if (!confirm.isConfirmed) return;

            Swal.fire({
                title: 'Sinkronisasi Massal CEISA 4.0',
                html: 'Menghubungi endpoint OpenAPI Status Bea Cukai & memproses seluruh respon...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            try {
                const res = await fetch(`statusaju.php?action=sync_company&cmp_id=${encodeURIComponent(cmpId)}&npwp=${encodeURIComponent(userNpwp)}`);
                const result = await res.json();

                if (result.status === 'OK') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Sinkronisasi Selesai!',
                        text: result.message || 'Seluruh status dokumen berhasil diselaraskan!',
                        timer: 2500,
                        showConfirmButton: false
                    });
                    loadTrackingData();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal Sinkronisasi',
                        text: result.message || 'Gagal mengambil status perusahaan dari CEISA 4.0.'
                    });
                }
            } catch (err) {
                Swal.fire('Error', 'Terjadi kesalahan jaringan: ' + err.message, 'error');
            }
        }

        function applyCurrentTheme(th) {
            if (!th) {
                var urlParams = new URLSearchParams(window.location.search);
                th = urlParams.get('theme') || localStorage.getItem('ceisa_theme') || 'light';
            }
            if (th !== 'light' && th !== 'dark') th = 'light';
            document.documentElement.setAttribute('data-theme', th);
            document.documentElement.style.colorScheme = th;
            if (document.body) document.body.setAttribute('data-theme', th);
        }
        applyCurrentTheme();

        window.addEventListener('message', function(e) {
            if (e.data && e.data.type === 'SET_THEME') {
                applyCurrentTheme(e.data.theme);
            }
        });

        // Inisialisasi awal saat halaman dimuat
        document.addEventListener('DOMContentLoaded', () => {
            loadTrackingData();
        });
    </script>
</body>
</html>
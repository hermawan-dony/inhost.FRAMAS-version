<?php
/**
 * ============================================================================
 * CEISA 4.0 & TPB ERP Enterprise - Scraper Kurs Transaksi Bank Indonesia (BI)
 * ============================================================================
 * Target Resmi: https://www.bi.go.id/id/statistik/informasi-kurs/transaksi-bi/Default.aspx
 * Mendukung penarikan kurs harian Bank Indonesia:
 * - Kurs Jual, Kurs Beli, Kurs Tengah (26 Valuta Asing)
 * - Dukungan format output: JSON, TSV (PowerBuilder d_act_birate_tmp), dan HTML
 * - Auto-persist ke tabel database bc_act_birate
 * ============================================================================
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/datalogin.php';

// Kamus Nama Lengkap Valuta Resmi Bank Indonesia
function get_bi_currency_names() {
    return [
        'AED' => 'United Arab Emirates Dirham',
        'AUD' => 'Australian Dollar',
        'BND' => 'Brunei Dollar',
        'CAD' => 'Canadian Dollar',
        'CHF' => 'Swiss Franc',
        'CNH' => 'China Yuan (Offshore)',
        'CNY' => 'China Yuan',
        'DKK' => 'Danish Krone',
        'EUR' => 'Euro',
        'GBP' => 'British Pound',
        'HKD' => 'Hong Kong Dollar',
        'JPY' => 'Japanese Yen',
        'KRW' => 'South Korean Won',
        'KWD' => 'Kuwaiti Dinar',
        'LAK' => 'Laotian Kip',
        'MYR' => 'Malaysian Ringgit',
        'NOK' => 'Norwegian Krone',
        'NZD' => 'New Zealand Dollar',
        'PGK' => 'Papua New Guinea Kina',
        'PHP' => 'Philippine Peso',
        'SAR' => 'Saudi Arabian Riyal',
        'SEK' => 'Swedish Krona',
        'SGD' => 'Singapore Dollar',
        'THB' => 'Thai Baht',
        'USD' => 'United States Dollar',
        'VND' => 'Vietnamese Dong'
    ];
}

/**
 * Scrape data kurs dari portal Bank Indonesia
 * @param string|null $targetDate Format 'YYYY-MM-DD' atau 'DD/MM/YYYY'
 * @return array
 */
function tpberp_scrape_bi_rate($targetDate = null) {
    $url = 'https://www.bi.go.id/id/statistik/informasi-kurs/transaksi-bi/Default.aspx';
    $cookieFile = tempnam(sys_get_temp_dir(), 'bi_cookie_');

    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: id,en-US;q=0.9,en;q=0.8',
        'Origin: https://www.bi.go.id',
        'Referer: https://www.bi.go.id/id/statistik/informasi-kurs/transaksi-bi/Default.aspx'
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_TIMEOUT => 25
    ]);

    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (!$html || $httpCode !== 200) {
        $err = curl_error($ch) ?: "HTTP $httpCode";
        curl_close($ch);
        @unlink($cookieFile);
        return [
            'success' => false,
            'error'   => "Gagal menghubungi portal Bank Indonesia: $err",
            'data'    => []
        ];
    }

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    // Standardisasi tanggal
    $needsPost = false;
    $dateFormatted = '';
    $stdTargetDate = '';

    if (!empty($targetDate)) {
        if (strpos($targetDate, '/') !== false) {
            $parts = explode('/', $targetDate);
            if (count($parts) === 3) {
                // dd/mm/yyyy -> YYYY-MM-DD
                $stdTargetDate = sprintf('%04d-%02d-%02d', $parts[2], $parts[1], $parts[0]);
                $dateFormatted = sprintf('%02d/%02d/%04d', $parts[0], $parts[1], $parts[2]);
            }
        } else {
            $ts = strtotime($targetDate);
            if ($ts) {
                $stdTargetDate = date('Y-m-d', $ts);
                $dateFormatted = date('d/m/Y', $ts);
            }
        }

        // Jika tanggal yang diminta bukan hari ini, lakukan POST ke ASP.NET
        if ($dateFormatted !== '') {
            $needsPost = true;
        }
    }

    // Ekstrak tanggal publikasi dari halaman
    $pagePublishedDate = '';
    $spans = $xpath->query('//span');
    foreach ($spans as $sp) {
        if (strpos($sp->textContent, 'Update Terakhir') !== false) {
            $clean = preg_replace('/\s+/', ' ', trim($sp->textContent));
            $clean = str_replace('Update Terakhir', '', $clean);
            $pagePublishedDate = trim($clean);
            break;
        }
    }

    if ($needsPost) {
        $postData = [];
        $inputs = $xpath->query('//form[@id="aspnetForm"]//input');
        foreach ($inputs as $input) {
            $name = $input->getAttribute('name');
            $type = strtolower($input->getAttribute('type') ?: 'text');
            $val  = $input->getAttribute('value');

            if (!$name) continue;
            // Abaikan seluruh tombol submit/button kecuali yang ditargetkan
            if (in_array($type, ['submit', 'button', 'image'])) continue;
            if (in_array($type, ['checkbox', 'radio']) && !$input->hasAttribute('checked')) continue;

            $postData[$name] = $val;
        }

        $selects = $xpath->query('//form[@id="aspnetForm"]//select');
        foreach ($selects as $select) {
            $name = $select->getAttribute('name');
            if (!$name) continue;
            $selectedOpt = $xpath->query('.//option[@selected]', $select);
            if ($selectedOpt->length > 0) {
                $postData[$name] = $selectedOpt->item(0)->getAttribute('value');
            } else {
                $firstOpt = $xpath->query('.//option', $select);
                if ($firstOpt->length > 0) {
                    $postData[$name] = $firstOpt->item(0)->getAttribute('value');
                }
            }
        }

        $prefix = 'ctl00$ctl51$g_6c89d4ad_107f_437d_bd54_8fda17b556bf$ctl00$';
        $postData[$prefix . 'txtTanggal'] = $dateFormatted;
        $postData[$prefix . 'hidSourceID'] = 'ctl00_ctl51_g_6c89d4ad_107f_437d_bd54_8fda17b556bf_ctl00_btnSearch2';
        $postData[$prefix . 'btnSearch2'] = 'Cari';

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_TIMEOUT => 30
        ]);

        $htmlPost = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($htmlPost && $httpCode === 200) {
            $html = $htmlPost;
            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);
        }
    }

    curl_close($ch);
    @unlink($cookieFile);

    // Cari tabel hasil (baik gvSearchResult1 jika postback atau cek_operasi jika default hari ini)
    $table = $xpath->query('//table[contains(@id, "gvSearchResult1")] | //table[@id="cek_operasi"]');
    if ($table->length === 0) {
        return [
            'success' => false,
            'error'   => 'Tabel kurs tidak ditemukan pada respon portal Bank Indonesia (mungkin hari libur atau server BI sibuk).',
            'data'    => []
        ];
    }

    $targetTable = $table->item(0);
    $rows = $xpath->query('.//tr', $targetTable);

    $currencyNames = get_bi_currency_names();
    $effectiveDate = $stdTargetDate ?: date('Y-m-d');
    $results = [];

    foreach ($rows as $row) {
        $cols = $xpath->query('.//td', $row);
        if ($cols->length < 4) continue;

        $kurs      = strtoupper(trim($cols->item(0)->textContent));
        $satuanStr = trim($cols->item(1)->textContent);
        $jualStr   = trim($cols->item(2)->textContent);
        $beliStr   = trim($cols->item(3)->textContent);

        if (empty($kurs) || strlen($kurs) > 5) continue;

        // Normalisasi angka desimal Indonesia (17.973,42 -> 17973.42)
        $satuan = floatval(str_replace(['.', ','], ['', '.'], $satuanStr)) ?: 1.0;
        $jual   = floatval(str_replace(['.', ','], ['', '.'], $jualStr));
        $beli   = floatval(str_replace(['.', ','], ['', '.'], $beliStr));
        $tengah = round(($jual + $beli) / 2, 2);

        $namaMataUang = $currencyNames[$kurs] ?? $kurs;

        $results[] = [
            'tanggal'   => $effectiveDate,
            'kurs'      => $kurs,
            'matauang'  => $namaMataUang,
            'satuan'    => (int)$satuan,
            'jual'      => $jual,
            'beli'      => $beli,
            'tengah'    => $tengah,
            'set_by'    => 'AUTO SCRAPER BI'
        ];
    }

    return [
        'success'        => true,
        'tanggal'        => $effectiveDate,
        'tanggal_bi_fmt' => $dateFormatted ?: date('d/m/Y'),
        'published_note' => $pagePublishedDate,
        'count'          => count($results),
        'data'           => $results
    ];
}

// ----------------------------------------------------------------------------
// HANDLER REQUEST / ENTRY POINT (Hanya jika dipanggil langsung via HTTP)
// ----------------------------------------------------------------------------
$calledScript = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
$isDirectCall = ($calledScript === 'birate.php' || $calledScript === 'birate2.php' || $calledScript === 'ratebi.php');

if ($isDirectCall) {
    $targetDate = $_GET['tgl'] ?? ($_POST['tgl'] ?? ($_GET['date'] ?? ''));
    $format     = strtolower($_GET['format'] ?? ($_POST['format'] ?? 'tsv'));
    $autoSave   = !empty($_REQUEST['auto_save']);
    $cmpIdReq   = $_REQUEST['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? ''));

    $scrapeResult = tpberp_scrape_bi_rate($targetDate);

    // Simpan otomatis ke database jika diminta dan berhasil
    if ($autoSave && !empty($scrapeResult['success']) && !empty($koneksi) && !empty($scrapeResult['data'])) {
        $escCmp = $koneksi->real_escape_string($cmpIdReq);
        foreach ($scrapeResult['data'] as $item) {
            $tglEsc  = $koneksi->real_escape_string($item['tanggal']);
            $kursEsc = $koneksi->real_escape_string($item['kurs']);
            $namaEsc = $koneksi->real_escape_string($item['matauang']);
            $satuan  = (int)$item['satuan'];
            $jual    = (float)$item['jual'];
            $beli    = (float)$item['beli'];
            $tengah  = (float)$item['tengah'];
            $setBy   = $koneksi->real_escape_string($_SESSION['username'] ?? 'AUTO SCRAPER BI');

            $koneksi->query("INSERT INTO bc_act_birate (cmp_id, tanggal, kurs, matauang, satuan, jual, beli, tengah, set_by)
                             VALUES ('$escCmp', '$tglEsc', '$kursEsc', '$namaEsc', $satuan, $jual, $beli, $tengah, '$setBy')
                             ON DUPLICATE KEY UPDATE 
                                matauang = VALUES(matauang),
                                satuan   = VALUES(satuan),
                                jual     = VALUES(jual),
                                beli     = VALUES(beli),
                                tengah   = VALUES(tengah),
                                set_by   = VALUES(set_by)");
        }
    }

    // Format 1: JSON (Untuk AJAX Web Frontend)
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($scrapeResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Format 2: TSV (Tab-Separated Values) - Identik Asli PowerBuilder Desktop (TPB-ERP)
    // Kolom: tanggal \t matauang \t kurs \t satuan \t jual \t beli \t tengah \n
    header('Content-Type: text/plain; charset=utf-8');
    if (!empty($scrapeResult['success']) && !empty($scrapeResult['data'])) {
        foreach ($scrapeResult['data'] as $r) {
            echo $r['tanggal'] . "\t" . 
                 $r['matauang'] . "\t" . 
                 $r['kurs'] . "\t" . 
                 $r['satuan'] . "\t" . 
                 number_format($r['jual'], 2, '.', '') . "\t" . 
                 number_format($r['beli'], 2, '.', '') . "\t" . 
                 number_format($r['tengah'], 2, '.', '') . "\r\n";
        }
    } else {
        echo "ERROR: " . ($scrapeResult['error'] ?? 'Gagal mengambil data dari Bank Indonesia');
    }
    exit;
}

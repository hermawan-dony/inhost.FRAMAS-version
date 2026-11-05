<?php
/**
 * Local Scraper Kurs Pajak KMK Kemenkeu
 * Porting asli dari http://tpberp.com/taxrate.php
 * Mendukung pemanggilan via CLI atau HTTP:
 * ?tgl=YYYY-MM-DD
 * ?kurs=1 (1=USD, 2=AUD, 10=SGD, 13=JPY, 15=EUR, 16=CNY, dst. atau nama valuta 'USD', 'SGD', 'JPY', 'ALL')
 *
 * Mengembalikan: KMK\tTgl1\tTgl2\tRate\tKeterangan
 * Atau jika ?kurs=ALL mengembalikan format JSON untuk bulk import!
 */

if (!function_exists('tpberp_scrape_taxrate')) {
    function tpberp_scrape_taxrate($tgl = null) {
        if (empty($tgl)) { $tgl = date('Y-m-d'); }

        $arrContextOptions = [
            "ssl" => [
                "verify_peer" => false,
                "verify_peer_name" => false,
            ],
            "http" => [
                "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n"
            ]
        ];

        $targetUrl = "https://fiskal.kemenkeu.go.id/informasi-publik/kurs-pajak?date=$tgl";
        @$html = file_get_contents($targetUrl, false, stream_context_create($arrContextOptions));

        if (!$html) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $targetUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]);
            $html = curl_exec($ch);
            curl_close($ch);
        }

        if (!$html) return null;

        // Ekstrak KMK
        $kmk = '';
        if (preg_match('/<p><strong>(KMK[^<]+)<\/strong><\/p>/i', $html, $mKmk)) {
            $kmk = trim($mKmk[1]);
        } else {
            $pecah = explode('<p><strong>', $html);
            if (isset($pecah[1])) {
                $pecahlagi = explode('</strong></p>', $pecah[1]);
                $kmk = trim($pecahlagi[0]);
            }
        }

        // Ekstrak Tanggal Berlaku
        $tgl1 = '';
        $tgl2 = '';
        $keterangan = '';
        if (preg_match('/Tanggal berlaku:\s*([0-9]{1,2}\s+[a-zA-Z]+\s+[0-9]{4})\s*-\s*([0-9]{1,2}\s+[a-zA-Z]+\s+[0-9]{4})/i', $html, $mTgl)) {
            $keterangan = 'Tanggal berlaku: ' . trim($mTgl[1]) . ' - ' . trim($mTgl[2]);
            $tglStr = trim($mTgl[1]) . ' - ' . trim($mTgl[2]);
        } else {
            $pecah = explode('Tanggal berlaku:', $html);
            if (isset($pecah[1])) {
                $pecahlagi = explode('</em>', $pecah[1]);
                $keterangan = 'Tanggal berlaku:' . $pecahlagi[0];
                $tglStr = $pecahlagi[0];
            } else {
                $tglStr = '';
            }
        }

        $tglStr = str_replace(' Januari ', ' Jan ', $tglStr);
        $tglStr = str_replace(' Februari ', ' Feb ', $tglStr);
        $tglStr = str_replace(' Pebruari ', ' Feb ', $tglStr);
        $tglStr = str_replace(' Maret ', ' Mar ', $tglStr);
        $tglStr = str_replace(' April ', ' Apr ', $tglStr);
        $tglStr = str_replace(' Mei ', ' May ', $tglStr);
        $tglStr = str_replace(' Juni ', ' Jun ', $tglStr);
        $tglStr = str_replace(' Juli ', ' Jul ', $tglStr);
        $tglStr = str_replace(' Agustus ', ' Aug ', $tglStr);
        $tglStr = str_replace(' September ', ' Sep ', $tglStr);
        $tglStr = str_replace(' Oktober ', ' Oct ', $tglStr);
        $tglStr = str_replace(' November ', ' Nov ', $tglStr);
        $tglStr = str_replace(' Nopember ', ' Nov ', $tglStr);
        $tglStr = str_replace(' Desember ', ' Dec ', $tglStr);

        $pecah = explode(' - ', $tglStr);
        if (count($pecah) >= 2) {
            $tgl1 = trim(date("Y-m-d", strtotime($pecah[0])));
            $tgl2 = trim(date("Y-m-d", strtotime($pecah[1])));
        }

        // Ekstrak Seluruh Mata Uang dari Tabel Kemenkeu
        $allCurrencies = [];
        $indexedRates = [];

        if (preg_match_all('/<tr[^>]*>.*?<span class=[\'"]visible-xs-inline[\'"]>([A-Z]{3})<\/span>.*?<div class=[\'"]m-l-5[\'"]>([0-9\.,]+)<\/div>/is', $html, $matches, PREG_SET_ORDER)) {
            $idx = 1;
            foreach ($matches as $row) {
                $curCode = strtoupper(trim($row[1]));
                $valStr = trim($row[2]);
                $valStr = str_replace('.', '', $valStr);
                $valStr = str_replace(',', '.', $valStr);
                $rateVal = (float)$valStr;

                // JPY di Kemenkeu adalah per 100 JPY
                if ($curCode === 'JPY' || $idx === 13) {
                    $rateVal = $rateVal / 100;
                }

                $allCurrencies[$curCode] = $rateVal;
                $indexedRates[$idx] = [
                    'code' => $curCode,
                    'rate' => $rateVal
                ];
                $idx++;
            }
        }

        return [
            'status' => 'OK',
            'kmk' => $kmk,
            'tgl1' => $tgl1,
            'tgl2' => $tgl2,
            'keterangan' => $keterangan,
            'total' => count($allCurrencies),
            'rates' => $allCurrencies,
            'indexed' => $indexedRates
        ];
    }
}

// Jika file dipanggil langsung sebagai script utama (bukan di-include)
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $tgl = @$_GET['tgl'];
    $kursParam = @$_GET['kurs'] ?? '1';

    $data = tpberp_scrape_taxrate($tgl);
    if (!$data) {
        echo "ERROR\tGagal mengambil data dari fiskal.kemenkeu.go.id";
        exit;
    }

    if (strtoupper($kursParam) === 'ALL' || (@$_GET['format'] ?? '') === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }

    header('Content-Type: text/plain; charset=utf-8');
    $allCurrencies = $data['rates'];
    $indexedRates = $data['indexed'];

    $requestedRate = 0;
    $targetCode = strtoupper(trim($kursParam));

    if (isset($allCurrencies[$targetCode])) {
        $requestedRate = $allCurrencies[$targetCode];
    } elseif (is_numeric($kursParam) && isset($indexedRates[(int)$kursParam])) {
        $requestedRate = $indexedRates[(int)$kursParam]['rate'];
    } else {
        $requestedRate = $allCurrencies['USD'] ?? 0;
    }

    // Format output persis PB Desktop w_taxrate
    echo "{$data['kmk']}\t{$data['tgl1']}\t{$data['tgl2']}\t$requestedRate\t{$data['keterangan']}";
    exit;
}

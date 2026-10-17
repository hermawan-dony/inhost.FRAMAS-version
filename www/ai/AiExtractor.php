<?php
/**
 * CEISA 4.0 - AI Document Reader Engine (AiExtractor.php)
 * Ekstraktor data dokumen kepabeanan (Commercial Invoice, B/L, Packing List, dll.)
 * berbasis Multi-Provider AI (Google Gemini, OpenAI ChatGPT, Anthropic Claude, & OpenAI-Compatible).
 */

class AiExtractor
{
    /**
     * Ekstrak teks dari berkas dokumen (PDF, DOCX, XLSX, CSV, TXT, Gambar)
     */
    public static function extractTextFromFile(string $filePath, string $mimeType, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $text = '';
        $base64Image = null;

        switch ($ext) {
            case 'pdf':
                // Gunakan pdftotext jika tersedia
                $escapedPath = escapeshellarg($filePath);
                $pdfOutput = @shell_exec("pdftotext -layout {$escapedPath} - 2>/dev/null");
                if (!empty($pdfOutput)) {
                    $text = trim($pdfOutput);
                } else {
                    // Fallback pembacaan stream teks PDF dasar
                    $content = @file_get_contents($filePath);
                    if ($content) {
                        preg_match_all('/BT[\s\S]*?ET/m', $content, $matches);
                        $extracted = [];
                        foreach ($matches[0] as $block) {
                            preg_match_all('/\((.*?)\)[\s]*T[jJ]/', $block, $tMatches);
                            if (!empty($tMatches[1])) {
                                $extracted[] = implode(' ', $tMatches[1]);
                            }
                        }
                        $text = implode("\n", $extracted);
                    }
                }
                break;

            case 'docx':
                if (class_exists('ZipArchive')) {
                    $zip = new ZipArchive();
                    if ($zip->open($filePath) === true) {
                        $xmlContent = $zip->getFromName('word/document.xml');
                        $zip->close();
                        if ($xmlContent) {
                            $xml = @simplexml_load_string($xmlContent);
                            if ($xml) {
                                $paragraphs = [];
                                foreach ($xml->xpath('//w:p') as $p) {
                                    $pText = '';
                                    foreach ($p->xpath('.//w:t') as $t) {
                                        $pText .= (string)$t;
                                    }
                                    if (trim($pText) !== '') {
                                        $paragraphs[] = trim($pText);
                                    }
                                }
                                $text = implode("\n", $paragraphs);
                            } else {
                                $text = strip_tags(str_replace('</w:p>', "\n", $xmlContent));
                            }
                        }
                    }
                }
                break;

            case 'xlsx':
                if (class_exists('ZipArchive')) {
                    $zip = new ZipArchive();
                    if ($zip->open($filePath) === true) {
                        $sharedStrings = [];
                        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
                        if ($ssXml) {
                            $sXml = @simplexml_load_string($ssXml);
                            if ($sXml && isset($sXml->si)) {
                                foreach ($sXml->si as $si) {
                                    $sharedStrings[] = isset($si->t) ? (string)$si->t : '';
                                }
                            }
                        }

                        $sheetLines = [];
                        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
                        if ($sheetXml) {
                            $shXml = @simplexml_load_string($sheetXml);
                            if ($shXml && isset($shXml->sheetData->row)) {
                                foreach ($shXml->sheetData->row as $row) {
                                    $rowCells = [];
                                    foreach ($row->c as $cell) {
                                        $val = isset($cell->v) ? (string)$cell->v : '';
                                        if (isset($cell['t']) && (string)$cell['t'] === 's') {
                                            $val = $sharedStrings[(int)$val] ?? $val;
                                        }
                                        $rowCells[] = trim($val);
                                    }
                                    if (!empty(array_filter($rowCells))) {
                                        $sheetLines[] = implode("\t|\t", $rowCells);
                                    }
                                }
                            }
                        }
                        $zip->close();
                        $text = implode("\n", $sheetLines);
                    }
                }
                break;

            case 'csv':
            case 'txt':
                $text = @file_get_contents($filePath) ?: '';
                break;

            case 'png':
            case 'jpg':
            case 'jpeg':
            case 'webp':
                $imgData = @file_get_contents($filePath);
                if ($imgData) {
                    $base64Image = [
                        'mime_type' => $mimeType ?: 'image/' . $ext,
                        'base64'    => base64_encode($imgData)
                    ];
                }
                break;
        }

        return [
            'file_name' => $originalName,
            'extension' => $ext,
            'text'      => trim($text),
            'image'     => $base64Image
        ];
    }

    /**
     * Bangun prompt spesialis kepabeanan CEISA 4.0
     */
    public static function buildPrompt(string $combinedText, string $kodeBc, ?array $company = []): string
    {
        $cmpName = $company['cmp_name'] ?? 'Importir Terdaftar';
        $cmpNpwp = $company['npwp'] ?? '-';
        $cleanBc = preg_replace('/[^0-9]/', '', $kodeBc ?: '20');

        return <<<PROMPT
Anda adalah Asisten Pakar Kepabeanan (Customs Specialist) Direktorat Jenderal Bea dan Cukai (DJBC) untuk sistem CEISA 4.0 Indonesia.
Tugas Anda adalah membaca dan mengekstrak seluruh informasi dari berkas dokumen perdagangan internasional (Commercial Invoice, Packing List, Bill of Lading / AWB, Certificate of Origin, dll.) yang diberikan di bawah ini.

Dokumen target pabean yang sedang dipersiapkan: BC {$cleanBc} (misal: BC 2.0 Impor Umum, BC 2.3 Impor Kawasan Berikat/TPB, atau BC 1.6 Pusat Logistik Berikat).
Perusahaan Importir Aktif di sistem: "{$cmpName}" (NPWP: {$cmpNpwp}).

EKSTRAKSIKAN DATA SECARA AKURAT KE DALAM FORMAT JSON RESMI CEISA 4.0 DENGAN STRUKTUR PERSIS BERIKUT:
{
  "header": {
    "nomor_invoice": "Nomor Invoice / Faktur Komersial",
    "tanggal_invoice": "YYYY-MM-DD",
    "kode_valuta": "Kode Valuta 3 huruf (e.g. USD, EUR, JPY, CNY, SGD, IDR)",
    "fob": 0.00,
    "asuransi": 0.00,
    "freight": 0.00,
    "cif": 0.00,
    "kode_cara_angkut": "1", // 1 = Laut, 4 = Udara, 3 = Darat, 2 = Kereta
    "nama_pengangkut": "Nama Kapal / Pesawat / Sarana Pengangkut (e.g. EVER GIVEN, GARUDA)",
    "nomor_pengangkut": "Nomor Voyage / Flight No / No Polisi",
    "pelabuhan_muat": "Nama / Kode Pelabuhan Muat Asal (e.g. SHANGHAI, SINGAPORE)",
    "pelabuhan_bongkar": "Nama / Kode Pelabuhan Bongkar Tujuan (e.g. TANJUNG PRIOK, TANJUNG PERAK)"
  },
  "entitas": [
    {
      "kode_entitas": "1", // 1 = Pengirim / Supplier / Eksportir Luar Negeri
      "nama_entitas": "Nama Perusahaan Supplier / Shipper",
      "alamat_entitas": "Alamat Lengkap Supplier",
      "kode_negara": "Kode 2 huruf ISO Negara Asal Supplier (e.g. CN, US, JP, DE, SG)"
    },
    {
      "kode_entitas": "2", // 2 = Importir / Penerima
      "nama_entitas": "{$cmpName}",
      "nomor_identitas": "{$cmpNpwp}",
      "alamat_entitas": "Alamat Importir jika tertera di dokumen",
      "kode_negara": "ID"
    }
  ],
  "dokumen": [
    {
      "kode_dokumen": "380", // 380 = Invoice
      "nomor_dokumen": "Nomor Invoice",
      "tanggal_dokumen": "YYYY-MM-DD"
    },
    {
      "kode_dokumen": "705", // 705 = B/L (Bill of Lading) atau 740 = AWB (Air Waybill)
      "nomor_dokumen": "Nomor B/L atau AWB",
      "tanggal_dokumen": "YYYY-MM-DD"
    },
    {
      "kode_dokumen": "217", // 217 = Packing List
      "nomor_dokumen": "Nomor Packing List (atau nomor invoice jika sama)",
      "tanggal_dokumen": "YYYY-MM-DD"
    }
  ],
  "kemasan": [
    {
      "seri": 1,
      "jumlah_kemasan": 0,
      "kode_kemasan": "Kode 2 huruf kemasan (e.g. CT = Cartons, PK = Packages, PX = Pallets, CS = Cases, BG = Bags, DR = Drums)",
      "merek": "Merek / Marks & Numbers pada kemasan"
    }
  ],
  "barang": [
    {
      "seri_barang": 1,
      "kode_barang": "Kode Part Number / SKU / Item Code",
      "hs": "HS Code 8-10 digit (jika tertera di invoice/dokumen, jika tidak kosongkan string \"\")",
      "uraian": "Deskripsi barang lengkap, jelas, komersial",
      "jumlah_satuan": 0.0000,
      "kode_satuan": "Kode satuan (e.g. PCE = Pieces, KGM = Kilogram, SET = Sets, ROL = Rolls, TNE = Ton, MTR = Meters, DZN = Dozen)",
      "harga_satuan": 0.0000,
      "fob": 0.00,
      "cif": 0.00,
      "kode_negara_asal": "Kode 2 huruf negara pembuat barang (e.g. CN, JP, US, DE)",
      "netto": 0.0000,
      "bruto": 0.0000
    }
  ]
}

ATURAN PENTING:
1. Pastikan seluruh harga, nilai FOB, CIF, dan kuantitas berupa tipe angka numerik (float/int), BUKAN string.
2. Format tanggal WAJIB berformat standar "YYYY-MM-DD".
3. Jika terdapat rincian item barang multi-baris pada Invoice / Packing List, ekstrak SELURUH baris barang tersebut secara berurutan mulai dari seri_barang 1 sampai selesai.
4. Total FOB pada "header" harus merupakan jumlah total dari nilai barang, atau sesuai nilai grand total invoice.
5. HANYA kembalikan teks valid JSON murni tanpa ada teks pengantar atau penutup apapun di luar JSON.

BERIKUT TEKS ISI DOKUMEN YANG HARUS ANDA BACA:
---
{$combinedText}
---
PROMPT;
    }

    /**
     * Kirim permintaan ekstraksi ke penyedia AI yang dikonfigurasi
     */
    public static function callAi(string $prompt, array $images, array $config): array
    {
        $provider = strtolower($config['provider'] ?? 'gemini');
        $apiKey   = trim($config['api_key'] ?? ($config['apiKey'] ?? ''));
        $model    = trim($config['model'] ?? '');

        if (empty($apiKey) && $provider !== 'openai_compatible') {
            return ['status' => 'ERROR', 'message' => "API Key untuk provider '{$provider}' belum diisi. Silakan atur di Konfigurasi AI."];
        }

        switch ($provider) {
            case 'gemini':
                return self::callGemini($prompt, $images, $apiKey, $model ?: 'gemini-2.0-flash');

            case 'openai':
                return self::callOpenAi($prompt, $images, $apiKey, $model ?: 'gpt-4o-mini', 'https://api.openai.com/v1/chat/completions');

            case 'claude':
                return self::callClaude($prompt, $images, $apiKey, $model ?: 'claude-3-5-sonnet-20241022');

            case 'openai_compatible':
            case 'custom':
            case 'ollama':
            case 'deepseek':
            case 'groq':
            case 'mistral':
                $endpoint = trim($config['endpoint'] ?? ($config['url'] ?? 'https://api.deepseek.com/v1/chat/completions'));
                return self::callOpenAi($prompt, $images, $apiKey, $model ?: 'deepseek-chat', $endpoint, $config['customHeaders'] ?? []);

            default:
                return ['status' => 'ERROR', 'message' => "Provider AI '{$provider}' tidak didukung."];
        }
    }

    /**
     * Uji koneksi API ke penyedia AI
     */
    public static function testConnection(array $config): array
    {
        $prompt = 'Respond with pure valid JSON: {"status":"OK","message":"Connection test successful"}';
        $res = self::callAi($prompt, [], $config);
        if (($res['status'] ?? '') === 'OK') {
            return ['status' => 'OK', 'message' => 'Koneksi ke AI Provider berhasil terhubung!'];
        }
        return $res;
    }

    /**
     * Google Gemini API Dispatcher
     */
    private static function callGemini(string $prompt, array $images, string $apiKey, string $model): array
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/" . urlencode($model) . ":generateContent?key=" . urlencode($apiKey);

        $parts = [];
        $parts[] = ['text' => $prompt];

        foreach ($images as $img) {
            if (!empty($img['base64'])) {
                $parts[] = [
                    'inline_data' => [
                        'mime_type' => $img['mime_type'],
                        'data'      => $img['base64']
                    ]
                ];
            }
        }

        $payload = [
            'contents' => [
                ['parts' => $parts]
            ],
            'generationConfig' => [
                'temperature'     => 0.1,
                'responseMimeType'=> 'application/json'
            ]
        ];

        $res = self::httpPost($url, json_encode($payload), ['Content-Type: application/json']);
        if (!$res['success']) {
            return ['status' => 'ERROR', 'message' => 'Gagal menghubungi Gemini API: ' . $res['error']];
        }

        $json = json_decode($res['body'], true);
        if (isset($json['error'])) {
            return ['status' => 'ERROR', 'message' => 'Gemini API Error: ' . ($json['error']['message'] ?? json_encode($json['error']))];
        }

        $rawText = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
        return self::parseAiJsonResponse($rawText, 'Gemini');
    }

    /**
     * OpenAI / OpenAI-Compatible API Dispatcher
     */
    private static function callOpenAi(string $prompt, array $images, string $apiKey, string $model, string $endpoint, array $customHeaders = []): array
    {
        $headers = [
            'Content-Type: application/json'
        ];
        if (!empty($apiKey)) {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }
        foreach ($customHeaders as $hk => $hv) {
            $headers[] = "{$hk}: {$hv}";
        }

        $userContents = [];
        $userContents[] = ['type' => 'text', 'text' => $prompt];

        foreach ($images as $img) {
            if (!empty($img['base64'])) {
                $userContents[] = [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => "data:{$img['mime_type']};base64,{$img['base64']}"
                    ]
                ];
            }
        }

        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role'    => 'system',
                    'content' => 'You are an expert Indonesian Customs (DJBC CEISA 4.0) specialist. Output ONLY pure valid JSON matching the requested schema.'
                ],
                [
                    'role'    => 'user',
                    'content' => (count($userContents) === 1) ? $prompt : $userContents
                ]
            ],
            'temperature' => 0.1
        ];

        // Beberapa model support response_format json_object
        if (strpos($endpoint, 'api.openai.com') !== false || strpos($model, 'gpt-4o') !== false) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $res = self::httpPost($endpoint, json_encode($payload), $headers);
        if (!$res['success']) {
            return ['status' => 'ERROR', 'message' => 'Gagal menghubungi AI API: ' . $res['error']];
        }

        $json = json_decode($res['body'], true);
        if (isset($json['error'])) {
            return ['status' => 'ERROR', 'message' => 'AI API Error: ' . ($json['error']['message'] ?? json_encode($json['error']))];
        }

        $rawText = $json['choices'][0]['message']['content'] ?? '';
        return self::parseAiJsonResponse($rawText, 'OpenAI');
    }

    /**
     * Anthropic Claude API Dispatcher
     */
    private static function callClaude(string $prompt, array $images, string $apiKey, string $model): array
    {
        $url = "https://api.anthropic.com/v1/messages";

        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01'
        ];

        $content = [];
        foreach ($images as $img) {
            if (!empty($img['base64'])) {
                $content[] = [
                    'type' => 'image',
                    'source' => [
                        'type'       => 'base64',
                        'media_type' => $img['mime_type'],
                        'data'       => $img['base64']
                    ]
                ];
            }
        }
        $content[] = ['type' => 'text', 'text' => $prompt];

        $payload = [
            'model'      => $model,
            'max_tokens' => 4096,
            'messages'   => [
                ['role' => 'user', 'content' => $content]
            ],
            'temperature'=> 0.1
        ];

        $res = self::httpPost($url, json_encode($payload), $headers);
        if (!$res['success']) {
            return ['status' => 'ERROR', 'message' => 'Gagal menghubungi Claude API: ' . $res['error']];
        }

        $json = json_decode($res['body'], true);
        if (isset($json['error'])) {
            return ['status' => 'ERROR', 'message' => 'Claude API Error: ' . ($json['error']['message'] ?? json_encode($json['error']))];
        }

        $rawText = $json['content'][0]['text'] ?? '';
        return self::parseAiJsonResponse($rawText, 'Claude');
    }

    /**
     * Bersihkan Markdown code blocks dan parse JSON secara aman
     */
    private static function parseAiJsonResponse(string $rawText, string $providerName): array
    {
        $cleaned = trim($rawText);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $cleaned, $matches)) {
            $cleaned = trim($matches[1]);
        }

        $data = json_decode($cleaned, true);
        if ($data === null) {
            // Coba cari substring kurung kurawal pertama dan terakhir
            $firstBrace = strpos($cleaned, '{');
            $lastBrace  = strrpos($cleaned, '}');
            if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
                $sub = substr($cleaned, $firstBrace, $lastBrace - $firstBrace + 1);
                $data = json_decode($sub, true);
            }
        }

        if ($data === null) {
            return [
                'status'   => 'ERROR',
                'message'  => "Gagal mengurai respon {$providerName} sebagai JSON valid.",
                'raw_text' => $rawText
            ];
        }

        return [
            'status' => 'OK',
            'data'   => $data
        ];
    }

    /**
     * Helper cURL POST
     */
    private static function httpPost(string $url, string $payload, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_HTTPHEADER     => $headers
        ]);

        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'error' => $err, 'code' => $code];
        }

        return ['success' => true, 'body' => $body, 'code' => $code];
    }

    /**
     * Simpan data hasil ekstraksi AI langsung ke tabel Staging CEISA 4.0
     */
    public static function injectToStaging(mysqli $koneksi, string $nomorAju, string $cmpId, array $extracted): array
    {
        if (empty($nomorAju) || empty($cmpId)) {
            return ['status' => 'ERROR', 'message' => 'Parameter nomor_aju dan cmp_id tidak boleh kosong.'];
        }

        $escAju = $koneksi->real_escape_string($nomorAju);
        $escCmp = $koneksi->real_escape_string($cmpId);

        // Ambil header saat ini
        $qH = $koneksi->query("SELECT * FROM bc_ceisa_header WHERE nomor_aju = '$escAju' LIMIT 1");
        if (!$qH || $qH->num_rows === 0) {
            return ['status' => 'ERROR', 'message' => "Dokumen AJU '$nomorAju' tidak ditemukan di bc_ceisa_header."];
        }
        $currentHdr = $qH->fetch_assoc();
        $kodeDokumen = $currentHdr['kode_dokumen'] ?: '20';

        $koneksi->begin_transaction();
        try {
            $hdr = $extracted['header'] ?? [];

            // 1. UPDATE HEADER
            $valuta   = $koneksi->real_escape_string($hdr['kode_valuta'] ?? ($currentHdr['kode_valuta'] ?: 'USD'));
            $fob      = floatval($hdr['fob'] ?? ($currentHdr['fob'] ?? 0));
            $asuransi = floatval($hdr['asuransi'] ?? ($currentHdr['asuransi'] ?? 0));
            $freight  = floatval($hdr['freight'] ?? ($currentHdr['freight'] ?? 0));
            $cif      = floatval($hdr['cif'] ?? ($currentHdr['cif'] ?? ($fob + $asuransi + $freight)));
            if ($cif <= 0) $cif = $fob + $asuransi + $freight;

            $ndpbm = floatval($currentHdr['ndpbm'] ?? 16250.00);
            $cifRupiah = round($cif * $ndpbm, 2);

            $sqlHdr = "UPDATE bc_ceisa_header SET 
                kode_valuta = '$valuta',
                fob = $fob,
                asuransi = $asuransi,
                freight = $freight,
                cif = $cif,
                cif_rupiah = $cifRupiah
                WHERE nomor_aju = '$escAju'";
            $koneksi->query($sqlHdr);

            // 2. INSERT / UPDATE PENGANGKUT
            if (!empty($hdr['nama_pengangkut']) || !empty($hdr['nomor_pengangkut'])) {
                $caraAngkut = $koneksi->real_escape_string($hdr['kode_cara_angkut'] ?? '1');
                $namaAngkut = $koneksi->real_escape_string($hdr['nama_pengangkut'] ?? '');
                $noAngkut   = $koneksi->real_escape_string($hdr['nomor_pengangkut'] ?? '');
                $koneksi->query("DELETE FROM bc_ceisa_pengangkut WHERE nomor_aju = '$escAju'");
                $koneksi->query("INSERT INTO bc_ceisa_pengangkut (cmp_id, nomor_aju, seri, kode_cara_angkut, nama_pengangkut, nomor_pengangkut)
                    VALUES ('$escCmp', '$escAju', 1, '$caraAngkut', '$namaAngkut', '$noAngkut')");
            }

            // 3. INSERT ENTITAS
            $entitasList = $extracted['entitas'] ?? [];
            if (!empty($entitasList)) {
                $koneksi->query("DELETE FROM bc_ceisa_entitas WHERE nomor_aju = '$escAju'");
                $seriEnt = 1;
                foreach ($entitasList as $ent) {
                    $kodeEnt   = $koneksi->real_escape_string($ent['kode_entitas'] ?? '1');
                    $namaEnt   = $koneksi->real_escape_string($ent['nama_entitas'] ?? '');
                    $alamatEnt = $koneksi->real_escape_string($ent['alamat_entitas'] ?? '');
                    $negaraEnt = $koneksi->real_escape_string($ent['kode_negara'] ?? '');
                    $idEnt     = $koneksi->real_escape_string($ent['nomor_identitas'] ?? '');
                    $jenisId   = (!empty($idEnt) && strlen(preg_replace('/[^0-9]/', '', $idEnt)) >= 15) ? '5' : '0';

                    $koneksi->query("INSERT INTO bc_ceisa_entitas (cmp_id, nomor_aju, seri, kode_entitas, kode_jenis_identitas, nomor_identitas, nama_entitas, alamat_entitas, kode_negara)
                        VALUES ('$escCmp', '$escAju', $seriEnt, '$kodeEnt', '$jenisId', '$idEnt', '$namaEnt', '$alamatEnt', '$negaraEnt')");
                    $seriEnt++;
                }
            }

            // 4. INSERT DOKUMEN PELENGKAP
            $dokList = $extracted['dokumen'] ?? [];
            if (!empty($dokList)) {
                $koneksi->query("DELETE FROM bc_ceisa_dokumen WHERE nomor_aju = '$escAju'");
                $seriDok = 1;
                foreach ($dokList as $dok) {
                    $kdDok  = $koneksi->real_escape_string($dok['kode_dokumen'] ?? '380');
                    $noDok  = $koneksi->real_escape_string($dok['nomor_dokumen'] ?? '');
                    $tglDok = !empty($dok['tanggal_dokumen']) ? date('Y-m-d', strtotime($dok['tanggal_dokumen'])) : date('Y-m-d');
                    if (empty($noDok)) continue;

                    $koneksi->query("INSERT INTO bc_ceisa_dokumen (cmp_id, nomor_aju, seri, kode_dokumen, nomor_dokumen, tanggal_dokumen)
                        VALUES ('$escCmp', '$escAju', $seriDok, '$kdDok', '$noDok', '$tglDok')");
                    $seriDok++;
                }
            }

            // 5. INSERT KEMASAN
            $kemasanList = $extracted['kemasan'] ?? [];
            if (!empty($kemasanList)) {
                $koneksi->query("DELETE FROM bc_ceisa_kemasan WHERE nomor_aju = '$escAju'");
                $seriKms = 1;
                foreach ($kemasanList as $kms) {
                    $jmlKms  = floatval($kms['jumlah_kemasan'] ?? 1);
                    $kdKms   = $koneksi->real_escape_string(strtoupper($kms['kode_kemasan'] ?? 'CT'));
                    $merek   = $koneksi->real_escape_string($kms['merek'] ?? '');

                    $koneksi->query("INSERT INTO bc_ceisa_kemasan (cmp_id, nomor_aju, seri, jumlah_kemasan, kode_kemasan, merek)
                        VALUES ('$escCmp', '$escAju', $seriKms, $jmlKms, '$kdKms', '$merek')");
                    $seriKms++;
                }
            }

            // 6. INSERT BARANG
            $barangList = $extracted['barang'] ?? [];
            $totalNetto = 0;
            $totalBruto = 0;

            if (!empty($barangList)) {
                $koneksi->query("DELETE FROM bc_ceisa_barang WHERE nomor_aju = '$escAju'");
                $koneksi->query("DELETE FROM bc_ceisa_barangtarif WHERE nomor_aju = '$escAju'");

                $seriBrg = 1;
                foreach ($barangList as $item) {
                    $kodeBarang = $koneksi->real_escape_string($item['kode_barang'] ?? "ITEM-{$seriBrg}");
                    $hs         = $koneksi->real_escape_string(preg_replace('/[^0-9]/', '', $item['hs'] ?? ''));
                    $uraian     = $koneksi->real_escape_string($item['uraian'] ?? 'Barang Impor');
                    $qty        = floatval($item['jumlah_satuan'] ?? 1);
                    $satuan     = $koneksi->real_escape_string(strtoupper($item['kode_satuan'] ?? 'PCE'));
                    $hargaSat   = floatval($item['harga_satuan'] ?? 0);
                    $itemFob    = floatval($item['fob'] ?? ($qty * $hargaSat));
                    $itemCif    = floatval($item['cif'] ?? $itemFob);
                    $itemCifRp  = round($itemCif * $ndpbm, 2);
                    $negaraAsal = $koneksi->real_escape_string(strtoupper($item['kode_negara_asal'] ?? ''));
                    $netto      = floatval($item['netto'] ?? 0);
                    $bruto      = floatval($item['bruto'] ?? $netto);

                    $totalNetto += $netto;
                    $totalBruto += $bruto;

                    $sqlItem = "INSERT INTO bc_ceisa_barang (
                        cmp_id, nomor_aju, seri_barang, kode_barang, hs, uraian, jumlah_satuan, kode_satuan,
                        harga_satuan, fob, cif, cif_rupiah, kode_negara_asal, netto, bruto
                    ) VALUES (
                        '$escCmp', '$escAju', $seriBrg, '$kodeBarang', '$hs', '$uraian', $qty, '$satuan',
                        $hargaSat, $itemFob, $itemCif, $itemCifRp, '$negaraAsal', $netto, $bruto
                    )";
                    $koneksi->query($sqlItem);

                    // Insert default tarif untuk item (BM, PPN, PPH)
                    $koneksi->query("INSERT INTO bc_ceisa_barangtarif (cmp_id, nomor_aju, seri_barang, kode_pungutan, kode_tarif, tarif, nilai_bayar, nilai_fasilitas, kode_fasilitas)
                        VALUES ('$escCmp', '$escAju', $seriBrg, 'BM', '1', 5.00, 0, 0, '1')");
                    $koneksi->query("INSERT INTO bc_ceisa_barangtarif (cmp_id, nomor_aju, seri_barang, kode_pungutan, kode_tarif, tarif, nilai_bayar, nilai_fasilitas, kode_fasilitas)
                        VALUES ('$escCmp', '$escAju', $seriBrg, 'PPN', '1', 11.00, 0, 0, '1')");

                    $seriBrg++;
                }

                // Update total netto & bruto di header jika ada
                if ($totalNetto > 0 || $totalBruto > 0) {
                    $koneksi->query("UPDATE bc_ceisa_header SET netto = $totalNetto, bruto = $totalBruto WHERE nomor_aju = '$escAju'");
                }
            }

            $koneksi->commit();
            return [
                'status'    => 'OK',
                'nomor_aju' => $nomorAju,
                'message'   => "Ekstraksi AI berhasil dimasukkan ke Dokumen AJU: {$nomorAju} (" . count($barangList) . " seri barang)."
            ];

        } catch (\Exception $e) {
            $koneksi->rollback();
            return ['status' => 'ERROR', 'message' => 'Gagal memasukkan data ke staging: ' . $e->getMessage()];
        }
    }
}

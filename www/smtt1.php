<?php

// URL endpoint target
$url = 'https://adidas.api.trustrace.com/api/material-compliance/ps/filter-list';

// Data Body JSON (Ay rapikan jadi Array biar enak dilihat, nanti di-json_encode)
$data = [
    "module" => "TT_TRANSACTION",
    "filter" => [
        "Brand" => [
            "60b20f144294127f8af64c6e"
        ],
        "Transaction Status" => [
            "SUBMITTED"
        ],
        "Transaction Type" => [
            "AUTOMATED INBOUND",
            "INBOUND"
        ]
    ],
    "sort" => [
        "sortBy" => "create_ts",
        "sortOrder" => "desc",
        "sortValue" => "Newest First"
    ],
    "pagination" => [
        "from" => 0,
        "size" => 50
    ],
    "searchContext" => [
        "cocType" => "PRODUCT_SEGREGATION",
        "tabTTName" => "SUBMITTED_TRANSACTION"
    ]
];

// Konversi data array ke JSON string
$payload = json_encode($data);

// Token dan Cookie yang Mas kasih (Hati-hati, ini bisa expired ya Mas)
$cookie = 'TT-XSRF-TOKEN=6fdc72a9-a4dc-4ef7-9d0e-c3689e280576; refreshtoken=696870c3195c-33c094b15101_99779200dcaf_a79a-4ada-696870c3195c99779200dcb0; tt_token=eyJhbGciOiJIUzUxMiJ9.eyJzdWIiOiJwYW5kaHUudXRvbW9AZnJhbWFzLmNvbSIsImNvbXBhbnktY29udGV4dCI6eyJpZCI6IjYwYjIwZjE0NDI5NDEyN2Y4YWY2NGM2ZSIsIm5hbWUiOiJhZGlkYXMgYnJhbmQifSwidGllci1jb250ZXh0Ijp7ImtleSI6InRyX3RpZXIyIiwidmFsdWUiOiJUaWVyIDIifSwiZXhwIjoxNzY4NDU0MDkxLCJpYXQiOjE3Njg0NTIyOTF9.lrQ34XRtGwEtJglghD2gcAzBX_Gf_BIdasYArp7V2Y-rovqCYRvCZpKIU2LyBvhSuxfqmznbf5Lm_qVAzryLLQ; expiresAt=1768454091000';
$xsrfToken = '6fdc72a9-a4dc-4ef7-9d0e-c3689e280576';

// Inisialisasi cURL
$ch = curl_init($url);

// Set opsi cURL
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '', // Menangani gzip/deflate/br otomatis
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json, text/plain, */*',
        'Accept-Language: en-US,en;q=0.9',
        'Connection: keep-alive',
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload),
        'Cookie: ' . $cookie,
        'Host: adidas.api.trustrace.com',
        'Origin: https://adidas.trustrace.com',
        'Referer: https://adidas.trustrace.com/',
        'sec-ch-ua: "Google Chrome";v="143", "Chromium";v="143", "Not A(Brand";v="24"',
        'sec-ch-ua-mobile: ?0',
        'sec-ch-ua-platform: "Windows"',
        'Sec-Fetch-Dest: empty',
        'Sec-Fetch-Mode: cors',
        'Sec-Fetch-Site: same-site',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36',
        'X-TT-XSRF-TOKEN: ' . $xsrfToken
    ],
]);

// Eksekusi request
$response = curl_exec($ch);
$err = curl_error($ch);

// Tutup koneksi
curl_close($ch);

// Cek hasil
if ($err) {
    echo "Duh, ada error Mas: " . $err;
} else {
    // Tampilkan response (biasanya JSON) 
    echo $response;
}

?>
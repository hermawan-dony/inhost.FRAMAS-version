<?php
/*
 * CONTOH WEBHOOK UNTUK WAGW PRO
 * Menerima payload JSON dari wabot.js setiap ada pesan masuk.
 * Dan mengirim balasan otomatis kembali.
 */

// Terima JSON dari raw body
$jsonStr = file_get_contents('php://input');
$data = json_decode($jsonStr, true);

if ($data) {
    $pengirim = $data['wa_no'];
    $pesan = $data['text'];
    $isMedia = $data['isMedia'];

    // 1. Simpan ke log
    $logEntry = date('Y-m-d H:i:s') . " - NEW MSG - From: $pengirim | Msg: $pesan | isMedia: $isMedia \n";
    file_put_contents('webhook_log.txt', $logEntry, FILE_APPEND);

    // 2. Balas otomatis dengan mengirimkan (POST) kembali pesan ke API WAGW PRO
    $replyPayload = json_encode([
        "no" => $pengirim,
        "text" => "Balasan Otomatis BOT: Anda mengirim => " . $pesan
    ]);

    $ch = curl_init('http://localhost:3000/api/send');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLINFO_HEADER_OUT, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $replyPayload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($replyPayload)
    ]);
    $result = curl_exec($ch);
    curl_close($ch);

    // Kirim balasan status HTTP 200 ke Nodejs bot
    http_response_code(200);
    echo json_encode(["status" => "success", "message" => "Webhook received and replied"]);
} else {
    http_response_code(400);
    echo json_encode(["status" => "failed", "message" => "Invalid payload"]);
}

?>

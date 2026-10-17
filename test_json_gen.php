<?php
require_once __DIR__ . '/koneksi.php';

$aju = '00002303006520261014297970';
$koneksi->query("SET SESSION group_concat_max_len = 100000000");

$sql = file_get_contents(__DIR__ . '/wf_23_clean.sql');
$sql = preg_replace('/^\xEF\xBB\xBF/', '', trim($sql));
$escaped = "'" . $koneksi->real_escape_string($aju) . "'";
$sql = str_replace(':ls_aju', $escaped, $sql);

$res = $koneksi->query($sql);
if (!$res) {
    echo "SQL ERROR: " . $koneksi->error . "\n";
    exit;
}

$row = $res->fetch_row();
$json = $row[0] ?? "";
echo "Generated JSON length: " . strlen($json) . " bytes\n";

$decoded = json_decode($json, true);
if ($decoded === null) {
    echo "JSON DECODE ERROR: " . json_last_error_msg() . "\n";
    echo "First 400 chars: " . substr($json, 0, 400) . "\n";
    echo "Last 400 chars: " . substr($json, -400) . "\n";
} else {
    echo "SUCCESS VALID JSON!\n";
    echo "nomorAju: " . ($decoded["nomorAju"] ?? "NOT_FOUND") . "\n";
    echo "kodeDokumen: " . ($decoded["kodeDokumen"] ?? "NOT_FOUND") . "\n";
    echo "entitas count: " . count($decoded["entitas"] ?? []) . "\n";
    echo "dokumen count: " . count($decoded["dokumen"] ?? []) . "\n";
    echo "barang count: " . count($decoded["barang"] ?? []) . "\n";
}

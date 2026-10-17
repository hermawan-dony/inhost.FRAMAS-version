<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$cmp_id    = trim((string)($_REQUEST['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? ''))));
$nomor_aju = trim((string)($_REQUEST['nomor_aju'] ?? $_REQUEST['aju'] ?? ''));
$cleanCmp  = preg_replace('/[^0-9A-Za-z_-]/', '', $cmp_id);
$cleanAju  = preg_replace('/[^0-9A-Za-z_-]/', '', $nomor_aju);

$f = 'json.txt';
if (!empty($cleanAju) && file_exists(__DIR__ . "/json_{$cleanAju}.txt")) {
    $f = "json_{$cleanAju}.txt";
} elseif (!empty($cleanCmp) && file_exists(__DIR__ . "/json_{$cleanCmp}.txt")) {
    $f = "json_{$cleanCmp}.txt";
}

$d = file_exists(__DIR__ . '/' . $f) ? file_get_contents(__DIR__ . '/' . $f) : '';
$j = json_decode($d);
$err = json_last_error() !== JSON_ERROR_NONE;
$out = $d ? ($err ? htmlspecialchars($d) : json_encode($j, JSON_PRETTY_PRINT)) : "📛 File $f mboten kepanggih.";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JSON Viewer Pro ✨</title>
    <style>
        body { background: #0d1117; color: #c9d1d9; font-family: system-ui, sans-serif; display: flex; justify-content: center; padding: 2rem; }
        .card { background: #161b22; border: 1px solid #30363d; border-radius: 8px; width: 100%; max-width: 800px; box-shadow: 0 4px 15px rgba(0,0,0,0.5); overflow: hidden; }
        .hdr { background: #21262d; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #30363d; font-size: 14px; font-weight: 600; }
        .status { color: <?= $d ? ($err ? '#ff7b72' : '#3fb950') : '#ff7b72' ?>; font-weight: normal; margin-left: 8px; }
        .btn { background: #238636; color: #fff; border: none; padding: 6px 14px; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; transition: 0.2s; }
        .btn:hover { background: #2ea043; }
        pre { margin: 0; padding: 16px; overflow-x: auto; font-family: 'Courier New', Courier, monospace; font-size: 13px; color: #e6edf3; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="card">
        <div class="hdr">
            <span>📄 json.txt <span class="status">(<?= $d ? ($err ? 'Invalid JSON Format' : 'Valid JSON') : 'File Not Found' ?>)</span></span>
            <button class="btn" onclick="copyJSON(this)">Copy JSON</button>
        </div>
        <pre id="code"><?= $out ?></pre>
    </div>

    <script>
        const copyJSON = btn => {
            navigator.clipboard.writeText(document.getElementById('code').innerText).then(() => {
                btn.innerText = 'Copied! ✔'; 
                btn.style.background = '#1f6feb';
                setTimeout(() => { btn.innerText = 'Copy JSON'; btn.style.background = '#238636'; }, 2000);
            });
        };
    </script>
</body>
</html>
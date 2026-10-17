<?php
require_once __DIR__ . '/koneksi.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$cmp_id    = trim((string)($_REQUEST['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? ''))));
$nomor_aju = trim((string)($_REQUEST['nomor_aju'] ?? $_REQUEST['aju'] ?? ''));

$cleanCmp = preg_replace('/[^0-9A-Za-z_-]/', '', $cmp_id);
$cleanAju = preg_replace('/[^0-9A-Za-z_-]/', '', $nomor_aju);

// Tentukan target file berdasarkan prioritas AJU -> Perusahaan -> Global
$file = __DIR__ . '/json.txt';
if (!empty($cleanAju) && file_exists(__DIR__ . "/json_{$cleanAju}.txt")) {
    $file = __DIR__ . "/json_{$cleanAju}.txt";
} elseif (!empty($cleanCmp) && file_exists(__DIR__ . "/json_{$cleanCmp}.txt")) {
    $file = __DIR__ . "/json_{$cleanCmp}.txt";
}

$prettyJson = '';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newJson = $_POST['json'] ?? '';

    json_decode($newJson);
    if (json_last_error() === JSON_ERROR_NONE) {
        file_put_contents($file, $newJson);
        if (!empty($cleanCmp)) {
            @file_put_contents(__DIR__ . "/json_{$cleanCmp}.txt", $newJson);
        }
        @file_put_contents(__DIR__ . '/json.txt', $newJson);

        if (isset($koneksi) && $koneksi) {
            if (!empty($cmp_id)) {
                $stmt = $koneksi->prepare("UPDATE bc_ceisa_json SET json = ? WHERE cmp_id = ?");
                if ($stmt) {
                    $stmt->bind_param("ss", $newJson, $cmp_id);
                    $stmt->execute();
                    $stmt->close();
                }
            } else {
                $stmt = $koneksi->prepare("UPDATE bc_ceisa_json SET json = ? ORDER BY id DESC LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param("s", $newJson);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
        $success = 'JSON berhasil disimpan!';
    } else {
        $error = 'Format JSON tidak valid: ' . json_last_error_msg();
    }
}

if (!file_exists($file) && isset($koneksi) && $koneksi) {
    if (!empty($cmp_id)) {
        $stmt = $koneksi->prepare("SELECT json FROM bc_ceisa_json WHERE cmp_id = ? ORDER BY id DESC LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $cmp_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($r = $res->fetch_assoc()) {
                if (!empty($r['json'])) {
                    file_put_contents($file, $r['json']);
                }
            }
            $stmt->close();
        }
    }
    if (!file_exists($file)) {
        $res = $koneksi->query("SELECT json FROM bc_ceisa_json ORDER BY id DESC LIMIT 1");
        if ($res && $r = $res->fetch_assoc()) {
            if (!empty($r['json'])) {
                file_put_contents($file, $r['json']);
            }
        }
    }
}

if (file_exists($file)) {
    $json = file_get_contents($file);
    $data = json_decode($json, true);

    if (json_last_error() === JSON_ERROR_NONE) {
        $prettyJson = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        $error = 'Format JSON dalam file tidak valid.';
    }
} else {
    $error = 'File JSON belum dibuat. Silakan lakukan proses generate JSON dari aplikasi terlebih dahulu.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>JSON Viewer</title>

<link href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>

<style>
body {
    margin: 0;
    background: #0b1220;
    color: #e5e7eb;
    font-family: Consolas, monospace;
    font-size: 12px;
}

.wrap {
    max-width: 1200px;
    margin: auto;
    padding: 10px 12px;
}

.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
}

.title {
    font-size: 14px;
    font-weight: 500;
}

.actions {
    display: flex;
    gap: 6px;
}

button {
    background: #2563eb;
    border: none;
    color: white;
    padding: 4px 9px;
    font-size: 11px;
    border-radius: 4px;
    cursor: pointer;
}

button:hover {
    background: #1d4ed8;
}

textarea {
    width: 100%;
    height: 80vh;
    background: #0f172a;
    color: #e5e7eb;
    border: none;
    padding: 10px;
    font-family: Consolas, monospace;
    font-size: 12px;
    border-radius: 6px;
}

pre {
    margin: 0;
    padding: 10px;
    border-radius: 6px;
    overflow: auto;
}

.error {
    background: #7f1d1d;
    padding: 8px;
    border-radius: 5px;
    color: #fecaca;
}

.success {
    background: #064e3b;
    padding: 8px;
    border-radius: 5px;
    color: #6ee7b7;
}
</style>
</head>

<body>
<div class="wrap">

    <div class="topbar">
        <div class="title">JSON Viewer</div>
        <div class="actions">
            <button onclick="toggleEdit()" id="editBtn">Edit</button>
            <button onclick="copyJSON()">Copy</button>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="POST" id="form">
        <div id="viewer">
            <pre><code id="json"><?= htmlspecialchars($prettyJson) ?></code></pre>
        </div>

        <div id="editor" style="display:none;">
            <textarea name="json" id="jsonEdit"><?= htmlspecialchars($prettyJson) ?></textarea>
            <br><br>
            <button type="submit">Save</button>
            <button type="button" onclick="toggleEdit()">Cancel</button>
        </div>
    </form>

</div>

<script>
hljs.highlightAll();

function copyJSON() {
    const text = document.getElementById('json').innerText;
    navigator.clipboard.writeText(text);
}

function toggleEdit() {
    const viewer = document.getElementById('viewer');
    const editor = document.getElementById('editor');
    const btn = document.getElementById('editBtn');

    if (editor.style.display === 'none') {
        viewer.style.display = 'none';
        editor.style.display = 'block';
        btn.innerText = 'View';
    } else {
        viewer.style.display = 'block';
        editor.style.display = 'none';
        btn.innerText = 'Edit';
    }
}
</script>

</body>
</html>
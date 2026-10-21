<?php
/**
 * CEISA 4.0 Excel Importer UI (import_excel.php)
 * Halaman antarmuka interaktif modern untuk preview & impor multi-file Excel CEISA 4.0 langsung ke tabel database.
 */

session_start();
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/datalogin.php';
require_once __DIR__ . '/CeisaXlsxImporter.php';

// Ambil daftar perusahaan aktif
$companies = [];
if (isset($koneksi) && $koneksi) {
    $res = $koneksi->query("SELECT cmp_id, cmp_name, npwp FROM bc_ceisa_company WHERE is_active = 1 ORDER BY cmp_name ASC");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $companies[] = $r;
        }
    }
}

// Tentukan perusahaan aktif berdasarkan parameter atau sesi
$currentCmpId = trim($_GET['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? '')));
if (empty($currentCmpId) && !empty($companies)) {
    $currentCmpId = $companies[0]['cmp_id'];
}
$currentCmpName = $currentCmpId;
$currentCmpNpwp = '-';
foreach ($companies as $c) {
    if ($c['cmp_id'] === $currentCmpId) {
        $currentCmpName = $c['cmp_name'];
        $currentCmpNpwp = $c['npwp'] ?: '-';
        break;
    }
}

$existingFiles = [];
$isEmbedded = (!empty($_GET['embedded']) && $_GET['embedded'] == '1') || (isset($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] === 'iframe');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CEISA 4.0 Multi-File Excel Importer</title>
    <script>
        (function() {
            var theme = localStorage.getItem('ceisa_theme') || 'light';
            document.documentElement.setAttribute('data-theme', theme);
        })();
        const isEmbeddedMode = (window.self !== window.top) || (new URLSearchParams(window.location.search).get('embedded') === '1');
        if (isEmbeddedMode) {
            document.documentElement.classList.add('is-embedded');
        }
    </script>
    <link rel="icon" type="image/png" href="/assets/logo.png">
    <link rel="shortcut icon" type="image/png" href="/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js"></script>
    <style>
        :root, body[data-theme="light"], html[data-theme="light"] body {
            --bg-body: #f4f6f9;
            --card-bg: #ffffff;
            --card-border: #e2e8f0;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --primary-light: rgba(37, 99, 235, 0.08);
            --primary-border: rgba(37, 99, 235, 0.25);
            --success: #10b981;
            --success-light: rgba(16, 185, 129, 0.1);
            --success-border: rgba(16, 185, 129, 0.3);
            --danger: #ef4444;
            --danger-light: rgba(239, 68, 68, 0.1);
            --danger-border: rgba(239, 68, 68, 0.3);
            --warning: #f59e0b;
            --dropzone-bg: #f8fafc;
            --dropzone-border: #cbd5e1;
            --dropzone-hover-bg: #eff6ff;
            --dropzone-hover-border: #3b82f6;
            --table-header-bg: #f1f5f9;
            --table-row-hover: #f8fafc;
            color-scheme: light;
        }

        body[data-theme="dark"], html[data-theme="dark"] body {
            --bg-body: #0f172a;
            --card-bg: #1e293b;
            --card-border: #334155;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --primary: #3b82f6;
            --primary-hover: #60a5fa;
            --primary-light: rgba(59, 130, 246, 0.15);
            --primary-border: rgba(59, 130, 246, 0.35);
            --success: #10b981;
            --success-light: rgba(16, 185, 129, 0.15);
            --success-border: rgba(16, 185, 129, 0.35);
            --danger: #f87171;
            --danger-light: rgba(248, 113, 113, 0.15);
            --danger-border: rgba(248, 113, 113, 0.35);
            --warning: #fbbf24;
            --dropzone-bg: #131d31;
            --dropzone-border: #475569;
            --dropzone-hover-bg: #1e2e4a;
            --dropzone-hover-border: #60a5fa;
            --table-header-bg: #162032;
            --table-row-hover: #1e293b;
            color-scheme: dark;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
            font-size: 11px;
            line-height: 1.5;
            min-height: 100vh;
            padding: 12px 16px;
            -webkit-font-smoothing: antialiased;
        }

        html.is-embedded body,
        body.is-embedded {
            padding: 10px 12px !important;
        }

        .container {
            max-width: 1100px;
            margin: 0 auto;
        }

        /* TOP CONTEXT BAR */
        .top-bar {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            padding: 10px 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }

        .top-bar-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .app-title-wrap h1 {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 7px;
            letter-spacing: -0.2px;
        }

        .app-title-wrap p {
            font-size: 10.5px;
            color: var(--text-muted);
            margin-top: 1px;
        }

        .company-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--primary-light);
            border: 1px solid var(--primary-border);
            border-radius: 6px;
            padding: 5px 10px;
        }

        .company-pill-icon {
            font-size: 14px;
        }

        .company-pill-name {
            font-size: 11.5px;
            font-weight: 700;
            color: var(--primary);
        }

        .company-pill-npwp {
            font-size: 9.5px;
            color: var(--text-muted);
            font-family: monospace;
        }

        /* HERO DROPZONE */
        .dropzone-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }

        .dropzone {
            border: 2px dashed var(--dropzone-border);
            background: var(--dropzone-bg);
            border-radius: 8px;
            padding: 24px 16px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }

        .dropzone:hover, .dropzone.dragover {
            border-color: var(--dropzone-hover-border);
            background: var(--dropzone-hover-bg);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.08);
        }

        .dropzone-icon {
            width: 44px;
            height: 44px;
            margin: 0 auto 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--primary-light);
            border: 1px solid var(--primary-border);
            border-radius: 50%;
            color: var(--primary);
            font-size: 22px;
            transition: transform 0.2s ease;
        }

        .dropzone:hover .dropzone-icon, .dropzone.dragover .dropzone-icon {
            transform: scale(1.1) rotate(-4deg);
        }

        .dropzone-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 3px;
        }

        .dropzone-subtitle {
            font-size: 11px;
            color: var(--text-muted);
            margin-bottom: 12px;
        }

        .btn-browse {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--primary);
            color: #ffffff;
            font-size: 11px;
            font-weight: 600;
            padding: 6px 16px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(37, 99, 235, 0.25);
            transition: all 0.15s ease;
        }

        .btn-browse:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
        }

        /* SERVER SAMPLES ROW */
        .server-samples-bar {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px dashed var(--card-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }

        .server-samples-label {
            font-size: 10px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .server-sample-pills {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .sample-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 10px;
            color: var(--text-main);
            cursor: pointer;
            transition: all 0.15s;
        }

        .sample-pill:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-light);
        }

        /* FILE QUEUE CARD */
        .queue-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            display: none;
        }

        .queue-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--card-border);
        }

        .queue-header-title {
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .queue-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-clear {
            background: none;
            border: none;
            color: var(--danger);
            font-size: 10.5px;
            font-weight: 600;
            cursor: pointer;
            padding: 2px 6px;
            border-radius: 4px;
            transition: background 0.15s;
        }

        .btn-clear:hover {
            background: var(--danger-light);
        }

        /* QUEUE TABLE */
        .queue-table-wrap {
            overflow-x: auto;
            border: 1px solid var(--card-border);
            border-radius: 6px;
        }

        table.queue-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10.5px;
        }

        table.queue-table th {
            background: var(--table-header-bg);
            padding: 6px 10px;
            text-align: left;
            font-weight: 600;
            font-size: 9.5px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: var(--text-muted);
            border-bottom: 1px solid var(--card-border);
        }

        table.queue-table td {
            padding: 8px 10px;
            border-bottom: 1px solid var(--card-border);
            vertical-align: middle;
        }

        table.queue-table tr:last-child td {
            border-bottom: none;
        }

        table.queue-table tr.item-success td {
            background: var(--success-light);
        }

        table.queue-table tr.item-error td {
            background: var(--danger-light);
        }

        .file-name-cell {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .file-icon-box {
            width: 24px;
            height: 24px;
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.25);
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            flex-shrink: 0;
        }

        /* STATUS BADGES */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.2px;
            white-space: nowrap;
        }

        .badge-pending {
            background: #e2e8f0;
            color: #475569;
            border: 1px solid #cbd5e1;
        }
        body[data-theme="dark"] .badge-pending {
            background: #334155;
            color: #94a3b8;
            border-color: #475569;
        }

        .badge-processing {
            background: var(--primary-light);
            color: var(--primary);
            border: 1px solid var(--primary-border);
        }

        .badge-success {
            background: var(--success-light);
            color: #10b981;
            border: 1px solid var(--success-border);
        }

        .badge-error {
            background: var(--danger-light);
            color: #ef4444;
            border: 1px solid var(--danger-border);
        }

        /* ERROR DETAILS CALLOUT */
        .error-reason-box {
            margin-top: 5px;
            background: rgba(239, 68, 68, 0.12);
            border-left: 3px solid #ef4444;
            padding: 4px 8px;
            border-radius: 0 4px 4px 0;
            font-size: 10px;
            color: #dc2626;
            line-height: 1.4;
        }
        body[data-theme="dark"] .error-reason-box {
            color: #f87171;
            background: rgba(239, 68, 68, 0.2);
        }

        .success-detail-box {
            margin-top: 4px;
            font-size: 9.5px;
            color: #059669;
        }
        body[data-theme="dark"] .success-detail-box {
            color: #34d399;
        }

        /* PROGRESS BAR */
        .progress-container {
            margin-top: 10px;
            display: none;
        }

        .progress-track {
            height: 6px;
            background: var(--card-border);
            border-radius: 3px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #3b82f6, #10b981);
            transition: width 0.2s ease;
        }

        .progress-status {
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        /* BOTTOM CONTROLS & OPTIONS */
        .bottom-bar {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            padding: 10px 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }

        .option-toggle {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 11px;
            font-weight: 500;
            cursor: pointer;
            user-select: none;
        }

        .option-toggle input[type="checkbox"] {
            width: 14px;
            height: 14px;
            accent-color: var(--primary);
            cursor: pointer;
        }

        .btn-group-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-action-primary {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #10b981;
            color: #ffffff;
            font-size: 11px;
            font-weight: 700;
            padding: 7px 16px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(16, 185, 129, 0.3);
            transition: all 0.15s ease;
        }

        .btn-action-primary:hover:not(:disabled) {
            background: #059669;
            transform: translateY(-1px);
        }

        .btn-action-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-action-secondary {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            color: var(--text-main);
            font-size: 11px;
            font-weight: 600;
            padding: 6px 14px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .btn-action-secondary:hover:not(:disabled) {
            border-color: var(--primary);
            color: var(--primary);
        }

        /* SPINNER */
        .spinner-sm {
            width: 10px;
            height: 10px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top: 2px solid currentColor;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            display: inline-block;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        /* PREVIEW MODAL */
        .preview-modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 16px;
            backdrop-filter: blur(2px);
        }

        .preview-modal {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            width: 100%;
            max-width: 800px;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 8px 30px rgba(0,0,0,0.25);
            overflow: hidden;
        }

        .preview-modal-header {
            padding: 10px 14px;
            border-bottom: 1px solid var(--card-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--table-header-bg);
        }

        .preview-modal-body {
            padding: 12px 14px;
            overflow-y: auto;
            flex: 1;
        }

        .preview-modal-footer {
            padding: 8px 14px;
            border-top: 1px solid var(--card-border);
            display: flex;
            justify-content: flex-end;
            background: var(--table-header-bg);
        }
    </style>
</head>
<body data-theme="light" class="<?= $isEmbedded ? 'is-embedded' : '' ?>">

<div class="container">
    <!-- TOP CONTEXT BAR (NO DROPDOWN - CLEAN ACTIVE COMPANY PILL) -->
    <div class="top-bar">
        <div class="top-bar-left">
            <div class="app-title-wrap">
                <h1>
                    <span>📥</span>
                    CEISA 4.0 Multi-File Excel Importer
                </h1>
                <p>Impor dokumen pabean (.xlsx) multi-sheet langsung ke tabel database tanpa tabel temporary.</p>
            </div>
        </div>

        <div class="company-pill" title="Perusahaan aktif yang digunakan untuk proses impor data">
            <span class="company-pill-icon">🏢</span>
            <div>
                <div class="company-pill-name"><?= htmlspecialchars($currentCmpName) ?></div>
                <div class="company-pill-npwp">NPWP: <?= htmlspecialchars($currentCmpNpwp) ?> • ID: <?= htmlspecialchars($currentCmpId) ?></div>
            </div>
        </div>
    </div>

    <!-- HERO MULTI-FILE DROPZONE -->
    <div class="dropzone-card">
        <div class="dropzone" id="dropArea">
            <div class="dropzone-icon">📊</div>
            <div class="dropzone-title">Tarik & Lepaskan File Excel (.xlsx) di Sini</div>
            <div class="dropzone-subtitle">Mendukung multi-file sekaligus (bisa drag beberapa file .xlsx bersamaan)</div>
            <button type="button" class="btn-browse" onclick="document.getElementById('fileInput').click()">
                📁 Pilih File dari Komputer
            </button>
            <input type="file" id="fileInput" name="files[]" accept=".xlsx" multiple style="display:none;">
        </div>
    </div>

    <!-- FILE QUEUE CARD -->
    <div class="queue-card" id="queueCard">
        <div class="queue-header">
            <div class="queue-header-title">
                <span>📋</span>
                Daftar Antrean File
                <span id="queueCounterBadge" style="font-size:10px; background:var(--primary-light); color:var(--primary); padding:2px 7px; border-radius:10px; font-weight:700; margin-left:4px;">0 file</span>
            </div>
            <div class="queue-actions">
                <button type="button" class="btn-clear" onclick="clearFileQueue()">
                    🗑️ Kosongkan Antrean
                </button>
            </div>
        </div>

        <div class="queue-table-wrap">
            <table class="queue-table" id="queueTable">
                <thead>
                    <tr>
                        <th style="width: 32px; text-align: center;">#</th>
                        <th>Nama File & Ukuran</th>
                        <th style="width: 140px;">Sumber</th>
                        <th style="width: 280px;">Status & Hasil</th>
                        <th style="width: 60px; text-align: center;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="queueTableBody">
                    <!-- Dynamic Rows -->
                </tbody>
            </table>
        </div>

        <!-- PROGRESS BAR -->
        <div class="progress-container" id="progressContainer">
            <div class="progress-track">
                <div class="progress-fill" id="progressFill"></div>
            </div>
            <div class="progress-status">
                <span id="progressText">Memproses file...</span>
                <span id="progressPercent">0%</span>
            </div>
        </div>
    </div>

    <!-- BOTTOM CONTROLS & OPTIONS -->
    <div class="bottom-bar">
        <label class="option-toggle" title="Jika dicentang, data lama dengan Nomor AJU yang sama akan ditimpa bersih">
            <input type="checkbox" id="chkOverwrite" value="1" checked>
            <span>Timpa (Overwrite) data lama jika Nomor AJU sudah ada di database</span>
        </label>

        <div class="btn-group-actions">
            <button type="button" class="btn-action-secondary" id="btnBatchPreview" onclick="handleBatchPreview()" disabled>
                <span>🔍 Preview Sheet</span>
            </button>
            <button type="button" class="btn-action-primary" id="btnStartImport" onclick="startBatchImport()" disabled>
                <span id="btnImportSpinner" class="spinner-sm" style="display:none;"></span>
                <span id="btnImportText">🚀 Import Semua File ke Database</span>
            </button>
        </div>
    </div>
</div>

<!-- HIDDEN INPUT COMPANY -->
<input type="hidden" id="activeCmpId" value="<?= htmlspecialchars($currentCmpId) ?>">

<!-- PREVIEW MODAL -->
<div class="preview-modal-overlay" id="previewModal">
    <div class="preview-modal">
        <div class="preview-modal-header">
            <div>
                <strong id="previewModalTitle" style="font-size:12px; color:var(--text-main);">Preview Sheet File Excel</strong>
                <div id="previewModalMeta" style="font-size:10px; color:var(--text-muted); margin-top:2px;"></div>
            </div>
            <button type="button" class="btn-clear" onclick="closePreviewModal()" style="font-size:14px;">✕</button>
        </div>
        <div class="preview-modal-body" id="previewModalBody">
            <!-- Dynamic Sheet Table -->
        </div>
        <div class="preview-modal-footer">
            <button type="button" class="btn-action-secondary" onclick="closePreviewModal()">Tutup</button>
        </div>
    </div>
</div>

<script>
    // State Antrean Multi-File
    let queue = [];
    let isProcessing = false;

    const fileInput = document.getElementById('fileInput');
    const dropArea = document.getElementById('dropArea');
    const queueCard = document.getElementById('queueCard');
    const queueTableBody = document.getElementById('queueTableBody');
    const queueCounterBadge = document.getElementById('queueCounterBadge');
    const btnStartImport = document.getElementById('btnStartImport');
    const btnBatchPreview = document.getElementById('btnBatchPreview');
    const progressContainer = document.getElementById('progressContainer');
    const progressFill = document.getElementById('progressFill');
    const progressText = document.getElementById('progressText');
    const progressPercent = document.getElementById('progressPercent');

    // 1. Drag & Drop Event Handling
    ['dragenter', 'dragover'].forEach(eventName => {
        dropArea.addEventListener(eventName, (e) => {
            e.preventDefault();
            dropArea.classList.add('dragover');
        }, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropArea.addEventListener(eventName, (e) => {
            e.preventDefault();
            dropArea.classList.remove('dragover');
        }, false);
    });

    dropArea.addEventListener('drop', (e) => {
        if (e.dataTransfer && e.dataTransfer.files) {
            handleSelectedFiles(e.dataTransfer.files);
        }
    });

    fileInput.addEventListener('change', function() {
        if (this.files) {
            handleSelectedFiles(this.files);
            this.value = ''; // Reset input agar file sama bisa dipilih ulang jika dihapus
        }
    });

    // 2. Tambah File ke Antrean
    function handleSelectedFiles(files) {
        let addedCount = 0;
        Array.from(files).forEach(f => {
            const ext = f.name.split('.').pop().toLowerCase();
            if (ext !== 'xlsx') {
                showWarning(`File "${f.name}" dilewati karena bukan berekstensi .xlsx`, 'Format Tidak Didukung');
                return;
            }

            // Cek duplikasi nama file di queue
            const exists = queue.some(item => item.name === f.name);
            if (!exists) {
                queue.push({
                    id: 'f_' + Date.now() + '_' + Math.random().toString(36).substr(2, 6),
                    type: 'upload',
                    file: f,
                    serverFileName: null,
                    name: f.name,
                    sizeStr: formatBytes(f.size),
                    status: 'pending', // pending, processing, success, error
                    result: null,
                    errorMessage: null
                });
                addedCount++;
            }
        });

        if (addedCount > 0) {
            renderQueue();
        }
    }

    function addServerFile(fileName, sizeStr) {
        const exists = queue.some(item => item.name === fileName);
        if (exists) {
            showToast(`File "${fileName}" sudah ada di antrean.`);
            return;
        }

        queue.push({
            id: 'f_' + Date.now() + '_' + Math.random().toString(36).substr(2, 6),
            type: 'server',
            file: null,
            serverFileName: fileName,
            name: fileName,
            sizeStr: sizeStr,
            status: 'pending',
            result: null,
            errorMessage: null
        });

        renderQueue();
        showToast(`File server "${fileName}" ditambahkan ke antrean.`);
    }

    function removeQueueItem(id) {
        if (isProcessing) return;
        queue = queue.filter(item => item.id !== id);
        renderQueue();
    }

    function clearFileQueue() {
        if (isProcessing) return;
        queue = [];
        renderQueue();
    }

    // 3. Render Antrean Tabel
    function renderQueue() {
        if (queue.length === 0) {
            queueCard.style.display = 'none';
            btnStartImport.disabled = true;
            btnBatchPreview.disabled = true;
            return;
        }

        queueCard.style.display = 'block';
        queueCounterBadge.innerText = `${queue.length} file`;
        btnStartImport.disabled = isProcessing;
        btnBatchPreview.disabled = isProcessing;

        let html = '';
        queue.forEach((item, index) => {
            let rowClass = '';
            if (item.status === 'success') rowClass = 'item-success';
            if (item.status === 'error') rowClass = 'item-error';

            let statusBadgeHtml = '';
            if (item.status === 'pending') {
                statusBadgeHtml = `<span class="status-badge badge-pending">⏳ Menunggu</span>`;
            } else if (item.status === 'processing') {
                statusBadgeHtml = `<span class="status-badge badge-processing"><span class="spinner-sm"></span> Memproses...</span>`;
            } else if (item.status === 'success') {
                const warnKeys = item.result?.warnings ? Object.keys(item.result.warnings) : [];
                const warnHtml = warnKeys.length > 0 
                    ? `<div style="font-size:9.5px; color:#d97706; margin-top:2px;">⚠️ Catatan: Sheet <strong>${escapeHtml(warnKeys.join(', '))}</strong> memiliki peringatan tapi data Header & Barang aman tersimpan.</div>` 
                    : '';
                statusBadgeHtml = `
                    <span class="status-badge badge-success">✓ Berhasil</span>
                    <div class="success-detail-box">
                        AJU: <strong>${escapeHtml(item.result?.nomor_aju || '-')}</strong> (BC ${item.result?.kode_dokumen || '-'}) • <strong>${item.result?.total_rows_imported || 0} baris</strong> tersimpan
                        ${warnHtml}
                    </div>
                `;
            } else if (item.status === 'error') {
                statusBadgeHtml = `
                    <span class="status-badge badge-error">✕ GAGAL</span>
                    <div class="error-reason-box">
                        <strong>Penyebab:</strong> ${escapeHtml(item.errorMessage || 'Terjadi kesalahan sistem')}
                    </div>
                `;
            }

            const sourceBadge = (item.type === 'server')
                ? `<span style="font-size:9.5px; background:rgba(99,102,241,0.1); color:#6366f1; padding:2px 6px; border-radius:3px; font-weight:600;">Server share/xls</span>`
                : `<span style="font-size:9.5px; background:rgba(16,185,129,0.1); color:#10b981; padding:2px 6px; border-radius:3px; font-weight:600;">Upload PC</span>`;

            html += `
                <tr class="${rowClass}">
                    <td style="text-align:center; color:var(--text-muted); font-size:10px;">${index + 1}</td>
                    <td>
                        <div class="file-name-cell">
                            <span class="file-icon-box">📊</span>
                            <div>
                                <strong style="font-size:11px; color:var(--text-main);">${escapeHtml(item.name)}</strong>
                                <span style="font-size:9.5px; color:var(--text-muted); margin-left:4px;">(${escapeHtml(item.sizeStr)})</span>
                            </div>
                        </div>
                    </td>
                    <td>${sourceBadge}</td>
                    <td>${statusBadgeHtml}</td>
                    <td style="text-align:center;">
                        <button type="button" class="btn-clear" onclick="removeQueueItem('${item.id}')" ${isProcessing ? 'disabled' : ''} title="Hapus file dari antrean">✕</button>
                    </td>
                </tr>
            `;
        });

        queueTableBody.innerHTML = html;
    }

    // 4. Eksekusi Impor Multi-File
    async function startBatchImport() {
        if (isProcessing || queue.length === 0) return;

        const cmpId = document.getElementById('activeCmpId').value;
        const overwrite = document.getElementById('chkOverwrite').checked ? 1 : 0;

        isProcessing = true;
        btnStartImport.disabled = true;
        btnBatchPreview.disabled = true;
        document.getElementById('btnImportSpinner').style.display = 'inline-block';
        document.getElementById('btnImportText').innerText = 'Sedang Mengimpor...';

        progressContainer.style.display = 'block';
        updateProgressBar(0, queue.length);

        let successCount = 0;
        let failCount = 0;

        for (let i = 0; i < queue.length; i++) {
            const item = queue[i];
            item.status = 'processing';
            item.errorMessage = null;
            renderQueue();

            updateProgressBar(i, queue.length, `Memproses file (${i + 1}/${queue.length}): ${item.name}`);

            const fd = new FormData();
            fd.append('action', 'import');
            fd.append('cmp_id', cmpId);
            fd.append('overwrite', overwrite);

            if (item.type === 'upload') {
                fd.append('file', item.file);
            } else {
                fd.append('file_name', item.serverFileName);
            }

            try {
                const res = await fetch('api_import_excel.php', {
                    method: 'POST',
                    body: fd
                });

                if (!res.ok) {
                    const text = await res.text();
                    let errMsg = `HTTP ${res.status}: ${res.statusText}`;
                    try {
                        const parsed = JSON.parse(text);
                        if (parsed.message) errMsg = parsed.message;
                    } catch (e) {
                        if (res.status === 413) {
                            errMsg = `Ukuran file melebihi batas upload server (HTTP 413 Payload Too Large).`;
                        } else if (res.status === 504) {
                            errMsg = `Proses impor timeout (HTTP 504 Gateway Timeout).`;
                        }
                    }
                    throw new Error(errMsg);
                }

                const json = await res.json();

                if (json.status === 'OK') {
                    item.status = 'success';
                    item.result = json;
                    successCount++;
                } else {
                    item.status = 'error';
                    item.errorMessage = json.message || 'Gagal menyimpan ke database.';
                    failCount++;
                }
            } catch (err) {
                item.status = 'error';
                item.errorMessage = err.message;
                failCount++;
            }

            renderQueue();
        }

        updateProgressBar(queue.length, queue.length, 'Selesai.');

        isProcessing = false;
        btnStartImport.disabled = false;
        btnBatchPreview.disabled = false;
        document.getElementById('btnImportSpinner').style.display = 'none';
        document.getElementById('btnImportText').innerText = '🚀 Import Semua File ke Database';

        // Tampilkan Notifikasi Rangkuman
        if (failCount === 0) {
            showSuccess(`Selamat! Seluruh ${successCount} file Excel berhasil diimpor ke database tanpa kesalahan.`);
        } else if (successCount === 0) {
            showError(`Seluruh ${failCount} file GAGAL diimpor. Silakan periksa keterangan penyebab pada masing-masing file.`);
        } else {
            showWarning(`Proses impor selesai dengan catatan: ${successCount} file berhasil, ${failCount} file GAGAL. Silakan periksa detail galat di tabel antrean.`, 'Hasil Sebagian Gagal');
        }
    }

    // 5. Preview Sheet File Excel
    async function handleBatchPreview() {
        if (isProcessing || queue.length === 0) return;

        const cmpId = document.getElementById('activeCmpId').value;
        const targetItem = queue[0]; // Preview file pertama dalam antrean

        const modal = document.getElementById('previewModal');
        const titleEl = document.getElementById('previewModalTitle');
        const metaEl = document.getElementById('previewModalMeta');
        const bodyEl = document.getElementById('previewModalBody');

        titleEl.innerText = `Preview Sheet: ${targetItem.name}`;
        metaEl.innerText = `Memeriksa struktur sheet dan pemetaan tabel database...`;
        bodyEl.innerHTML = `<div style="text-align:center; padding:30px;"><span class="spinner-sm" style="width:24px;height:24px;color:var(--primary);"></span><p style="margin-top:8px; color:var(--text-muted);">Membaca file Excel...</p></div>`;
        modal.style.display = 'flex';

        const fd = new FormData();
        fd.append('action', 'preview');
        fd.append('cmp_id', cmpId);
        if (targetItem.type === 'upload') {
            fd.append('file', targetItem.file);
        } else {
            fd.append('file_name', targetItem.serverFileName);
        }

        try {
            const res = await fetch('api_import_excel.php', { method: 'POST', body: fd });
            const json = await res.json();

            if (json.status === 'OK') {
                metaEl.innerText = `Nomor AJU: ${json.nomor_aju || '-'} | Dokumen: BC ${json.kode_dokumen || '-'} | Perusahaan: ${json.cmp_id}`;
                let html = `
                    <table class="queue-table" style="border:1px solid var(--card-border); border-radius:6px; overflow:hidden;">
                        <thead>
                            <tr>
                                <th>Nama Sheet Excel</th>
                                <th>Tabel Tujuan MySQL</th>
                                <th>Jumlah Baris</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                `;

                for (const [sheet, info] of Object.entries(json.sheets || {})) {
                    html += `
                        <tr>
                            <td><strong>${escapeHtml(sheet)}</strong></td>
                            <td><code>${escapeHtml(info.table || 'Tidak dipetakan (Skip)')}</code></td>
                            <td>${info.row_count} baris</td>
                            <td>${info.table ? '<span class="status-badge badge-success">Tersedia</span>' : '<span style="color:var(--text-muted);">Dilewati</span>'}</td>
                        </tr>
                    `;
                }

                html += '</tbody></table>';
                bodyEl.innerHTML = html;
            } else {
                bodyEl.innerHTML = `
                    <div class="error-reason-box" style="padding:12px;">
                        <strong>Gagal Preview:</strong> ${escapeHtml(json.message || 'File tidak dapat dibaca.')}
                    </div>
                `;
            }
        } catch (err) {
            bodyEl.innerHTML = `<div class="error-reason-box" style="padding:12px;"><strong>Kesalahan Jaringan:</strong> ${escapeHtml(err.message)}</div>`;
        }
    }

    function closePreviewModal() {
        document.getElementById('previewModal').style.display = 'none';
    }

    function updateProgressBar(current, total, statusText = '') {
        const pct = total > 0 ? Math.round((current / total) * 100) : 0;
        progressFill.style.width = pct + '%';
        progressPercent.innerText = pct + '%';
        if (statusText) progressText.innerText = statusText;
    }

    function formatBytes(bytes) {
        if (!bytes || bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function showToast(msg) {
        if (typeof Swal !== 'undefined' && Swal.mixin) {
            const Toast = Swal.mixin({
                toast: true,
                position: 'bottom-end',
                showConfirmButton: false,
                timer: 2500,
                timerProgressBar: true
            });
            Toast.fire({ icon: 'info', title: msg });
        }
    }

    // Tema Sinkronisasi
    function setTheme(theme) {
        if (theme !== 'light' && theme !== 'dark') theme = 'light';
        document.documentElement.setAttribute('data-theme', theme);
        document.body.setAttribute('data-theme', theme);
        localStorage.setItem('ceisa_theme', theme);
    }
    let savedTheme = localStorage.getItem('ceisa_theme') || 'light';
    setTheme(savedTheme);

    window.addEventListener('message', function(e) {
        if (e.data && e.data.type === 'SET_THEME') {
            setTheme(e.data.theme);
        }
    });
</script>

</body>
</html>

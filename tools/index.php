<?php
/**
 * INHOST TOOLS - Platform Utilitas Kepabeanan & Konversi Excel CEISA 4.0
 * Public / No Login Required.
 * 100% In-Memory Processing, Zero Database Storage, Pemusnahan Berkas Otomatis.
 */
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>INHOST TOOLS - Utilitas &amp; Konversi Excel CEISA 4.0</title>
    <link rel="icon" type="image/png" href="/assets/logo.png">
    <link rel="shortcut icon" type="image/png" href="/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <style>
        :root {
            --bg-body: #f8fafc;
            --sidebar-bg: #0b1329;
            --sidebar-text: #94a3b8;
            --sidebar-active-bg: rgba(24, 144, 255, 0.16);
            --sidebar-active-text: #ffffff;
            --card-bg: #ffffff;
            --card-border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --primary: #1890ff;
            --primary-hover: #096dd9;
            --primary-dark: #002060;
            --success: #10b981;
            --radius-md: 10px;
            --radius-lg: 14px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 14px -2px rgba(15, 23, 42, 0.08);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            -webkit-font-smoothing: antialiased;
        }

        /* Top Bar */
        .top-navbar {
            background: #ffffff;
            border-bottom: 1px solid var(--card-border);
            height: 56px;
            padding: 0 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .tools-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: inherit;
        }

        .brand-badge {
            background: linear-gradient(135deg, #001529 0%, #002060 60%, #1890ff 100%);
            color: #ffffff;
            font-weight: 800;
            font-size: 13px;
            padding: 4px 10px;
            border-radius: 6px;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .brand-text h1 {
            font-size: 14px;
            font-weight: 800;
            color: var(--primary-dark);
            line-height: 1.2;
        }
        .brand-text p {
            font-size: 11px;
            color: var(--text-muted);
        }

        .top-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .zero-trace-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
        }

        .pulse-dot {
            width: 7px;
            height: 7px;
            background-color: #10b981;
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
            animation: pulse-green 2s infinite;
        }
        @keyframes pulse-green {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 5px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        .btn-portal {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            color: #1e293b;
            padding: 5px 12px;
            font-size: 11.5px;
            font-weight: 600;
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .btn-portal:hover {
            background: #f1f5f9;
            color: var(--primary);
        }

        /* Layout Container: Sidebar + Content */
        .app-layout {
            display: flex;
            flex: 1;
            min-height: calc(100vh - 56px);
        }

        /* Sidebar */
        .sidebar {
            width: 260px;
            background: var(--sidebar-bg);
            color: var(--sidebar-text);
            display: flex;
            flex-direction: column;
            border-right: 1px solid rgba(255, 255, 255, 0.08);
            flex-shrink: 0;
            transition: all 0.3s;
        }

        .sidebar-section-title {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #64748b;
            padding: 18px 18px 8px;
        }

        .sidebar-menu {
            list-style: none;
            padding: 0 10px;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .menu-item-btn {
            width: 100%;
            text-align: left;
            background: transparent;
            border: 1px solid transparent;
            color: var(--sidebar-text);
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 12.5px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .menu-item-btn:hover {
            background: rgba(255, 255, 255, 0.06);
            color: #ffffff;
        }

        .menu-item-btn.active {
            background: var(--sidebar-active-bg);
            border-color: rgba(24, 144, 255, 0.4);
            color: var(--sidebar-active-text);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }

        .menu-icon {
            font-size: 16px;
            width: 22px;
            height: 22px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 5px;
        }

        .menu-item-btn.active .menu-icon {
            color: #38bdf8;
        }

        .badge-new {
            background: #ef4444;
            color: #ffffff;
            font-size: 9.5px;
            font-weight: 800;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: auto;
            letter-spacing: 0.3px;
        }

        .badge-soon {
            background: rgba(245, 158, 11, 0.2);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.4);
            font-size: 9px;
            font-weight: 700;
            padding: 1.5px 6px;
            border-radius: 10px;
            margin-left: auto;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }

        .sidebar-footer {
            margin-top: auto;
            padding: 16px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            font-size: 11px;
            color: #64748b;
            line-height: 1.4;
        }

        /* Content Area */
        .content-area {
            flex: 1;
            padding: 24px 28px 40px;
            background: 
                radial-gradient(circle at 50% 0%, rgba(24, 144, 255, 0.05) 0%, transparent 45%),
                radial-gradient(rgba(148, 163, 184, 0.12) 1px, transparent 1px);
            background-size: 100% 100%, 28px 28px;
            background-position: 0 0, 14px 14px;
            max-width: 1080px;
        }

        .view-panel {
            display: none;
            animation: fadeIn 0.2s ease-in-out;
        }
        .view-panel.active {
            display: block;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* View Intro */
        .view-intro {
            margin-bottom: 20px;
        }
        .view-intro h2 {
            font-size: 20px;
            font-weight: 800;
            color: var(--primary-dark);
            letter-spacing: -0.3px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .view-intro p {
            font-size: 12.5px;
            color: var(--text-muted);
            margin-top: 3px;
        }

        /* Tool Card */
        .tool-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            margin-bottom: 24px;
        }

        .tool-card-header {
            background: #ffffff;
            border-bottom: 1px solid var(--card-border);
            padding: 14px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        .tool-card-title h3 {
            font-size: 15px;
            font-weight: 800;
            color: var(--text-main);
        }
        .tool-card-title p {
            font-size: 11.5px;
            color: var(--text-muted);
            margin-top: 1px;
        }

        .tool-card-body {
            padding: 20px;
        }

        /* Doc chips */
        .doc-chips-bar {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 16px;
            padding: 8px 12px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .doc-chips-title {
            font-size: 10.5px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-right: 4px;
        }
        .doc-chip {
            font-size: 11px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 4px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            color: #334155;
        }
        .doc-chip.popular {
            background: #eff6ff;
            border-color: #93c5fd;
            color: #1d4ed8;
            font-weight: 700;
        }

        /* Dropzone */
        .dropzone-container {
            border: 2px dashed #93c5fd;
            background: #f8fafc;
            border-radius: var(--radius-md);
            padding: 30px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease-in-out;
            position: relative;
        }
        .dropzone-container:hover, .dropzone-container.is-dragover {
            border-color: var(--primary);
            background: #f0f7ff;
        }
        .dropzone-file-input {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            opacity: 0; cursor: pointer; z-index: 5;
        }
        .dropzone-icon {
            color: var(--primary);
            margin-bottom: 8px;
        }
        .dropzone-title {
            font-size: 14.5px;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 4px;
        }
        .dropzone-desc {
            font-size: 12px;
            color: var(--text-muted);
            max-width: 580px;
            margin: 0 auto 14px;
            line-height: 1.5;
        }
        .btn-choose-file {
            background: linear-gradient(135deg, #1890ff 0%, #096dd9 100%);
            color: #ffffff;
            font-size: 12.5px;
            font-weight: 700;
            padding: 8px 18px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(24, 144, 255, 0.3);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            position: relative;
            z-index: 10;
        }

        /* Option row */
        .option-form-row {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 16px;
            flex-wrap: wrap;
            background: #f8fafc;
            padding: 10px 14px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .form-group-inline {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-group-inline label {
            font-size: 12px;
            font-weight: 700;
            color: #334155;
        }
        .form-select-sm, .form-input-sm {
            padding: 6px 10px;
            font-size: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #ffffff;
            font-family: inherit;
        }

        /* Queue / Results Table */
        .queue-section {
            margin-top: 20px;
            display: none;
            animation: fadeIn 0.25s ease-in-out;
        }
        .queue-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .queue-title {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .queue-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-action-sm {
            padding: 6px 14px;
            font-size: 12px;
            font-weight: 700;
            border-radius: 6px;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-convert-all {
            background: linear-gradient(135deg, #1890ff 0%, #096dd9 100%);
            color: #ffffff;
            box-shadow: 0 2px 6px rgba(24, 144, 255, 0.25);
        }
        .btn-convert-all:hover { transform: translateY(-1px); }
        .btn-convert-all:disabled { background: #cbd5e1; cursor: not-allowed; }

        .btn-download-all {
            background: #10b981;
            color: #ffffff;
            box-shadow: 0 2px 6px rgba(16, 185, 129, 0.25);
        }
        .btn-download-all:hover { background: #059669; }

        .btn-copy-table {
            background: #475569;
            color: #ffffff;
        }
        .btn-copy-table:hover { background: #334155; }

        .btn-clear-queue {
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #cbd5e1;
        }
        .btn-clear-queue:hover { background: #e2e8f0; color: #334155; }

        .file-table-box {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: 8px;
            overflow-x: auto;
            box-shadow: var(--shadow-sm);
        }
        .file-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            text-align: left;
        }
        .file-table th {
            background: #f8fafc;
            padding: 10px 12px;
            font-weight: 700;
            color: #475569;
            border-bottom: 1px solid #e2e8f0;
            text-transform: uppercase;
            font-size: 10.5px;
            letter-spacing: 0.4px;
        }
        .file-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        .file-table tr:last-child td { border-bottom: none; }

        .badge-status {
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 10.5px;
            font-weight: 600;
        }
        .badge-status.waiting { background: #f1f5f9; color: #64748b; }
        .badge-status.processing { background: #e0f2fe; color: #0369a1; }
        .badge-status.success { background: #dcfce7; color: #15803d; }
        .badge-status.error { background: #fee2e2; color: #b91c1c; }

        .btn-download-item {
            background: #10b981;
            color: #ffffff;
            border: none;
            padding: 4px 10px;
            border-radius: 5px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-download-item:hover { background: #059669; }

        /* AI Model Selection Box & Tier Tabs */
        .ai-model-box {
            margin-bottom: 16px;
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            padding: 14px 18px;
            box-shadow: var(--shadow-sm);
        }
        .tier-tabs {
            display: inline-flex;
            gap: 4px;
            background: #f1f5f9;
            padding: 3px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .tier-btn {
            border: none;
            background: transparent;
            padding: 6px 14px;
            font-size: 11.5px;
            font-weight: 700;
            color: #64748b;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .tier-btn:hover { color: #1e293b; background: rgba(255,255,255,0.6); }
        .tier-btn.active[data-tier="Hemat"] {
            background: #ffffff;
            color: #15803d;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            border: 1px solid #bbf7d0;
        }
        .tier-btn.active[data-tier="Seimbang"] {
            background: #ffffff;
            color: #0369a1;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            border: 1px solid #bae6fd;
        }
        .tier-btn.active[data-tier="Flagship"] {
            background: #ffffff;
            color: #7e22ce;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            border: 1px solid #e9d5ff;
        }

        /* Credit Balance Widget */
        .ai-credit-banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #ffffff;
            border-radius: 8px;
            padding: 10px 14px;
            margin-bottom: 14px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.15);
        }
        .ai-credit-stat {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
        }
        .ai-credit-pill {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.4);
            color: #34d399;
            font-family: 'JetBrains Mono', monospace;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 12px;
        }
        .btn-reset-credit {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #cbd5e1;
            font-size: 11px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-reset-credit:hover {
            background: rgba(255, 255, 255, 0.18);
            color: #ffffff;
        }

        /* Editable cell in PDF Extractor preview */
        .editable-cell {
            outline: none;
            padding: 2px 4px;
            border-radius: 3px;
        }
        .editable-cell:focus {
            background: #eff6ff;
            box-shadow: 0 0 0 2px #38bdf8;
        }

        .spin {
            display: inline-block;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Security footnote */
        .security-footnote {
            margin-top: 24px;
            padding: 12px 16px;
            background: #ffffff;
            border: 1px solid #bbf7d0;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            font-size: 11.5px;
            color: #14532d;
        }
        .sec-note-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        /* Mobile toggle */
        @media (max-width: 820px) {
            .app-layout { flex-direction: column; }
            .sidebar { width: 100%; border-right: none; border-bottom: 1px solid rgba(255,255,255,0.08); }
            .content-area { padding: 16px; }
            .security-footnote { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>

    <!-- TOP NAVBAR -->
    <header class="top-navbar">
        <a href="/tools" class="tools-brand">
            <div class="brand-badge">
                <span>INHOST</span>
                <span style="font-size:10px; font-weight:500; opacity:0.85; border-left:1px solid rgba(255,255,255,0.4); padding-left:6px;">TOOLS</span>
            </div>
            <div class="brand-text">
                <h1>Platform Utilitas Kepabeanan</h1>
                <p>Otomasi &amp; Konversi Excel CEISA 4.0</p>
            </div>
        </a>
        <div class="top-actions">
            <div class="zero-trace-pill">
                <span class="pulse-dot"></span>
                <span>Zero-Trace Active</span>
            </div>
            <a href="/" class="btn-portal" title="Masuk ke Portal Utama INHOST">
                <svg viewBox="64 64 896 896" width="13" height="13" fill="currentColor">
                    <path d="M868 732h-70.3c-4.8 0-9.3 2.1-12.3 5.8-7 8.5-14.5 16.7-22.4 24.5a353.84 353.84 0 0 1-112.7 75.9A352.8 352.8 0 0 1 512.4 866c-47.9 0-94.3-9.4-137.9-27.8a353.84 353.84 0 0 1-112.7-75.9 353.28 353.28 0 0 1-76-112.5C167.3 606.2 158 559.9 158 512s9.4-94.2 27.8-137.8c17.8-42.1 43.4-80 76-112.5s70.5-58.2 112.7-75.9C418.1 167.4 464.5 158 512.4 158c47.9 0 94.3 9.4 137.9 27.8 42.2 17.7 80.4 43.3 112.7 75.9 7.9 7.9 15.3 16.1 22.4 24.5 3 3.7 7.6 5.8 12.3 5.8H868c6.3 0 10.2-7 6.7-12.2C797.6 155.1 665 78 512 78 272.3 78 78 272.3 78 512s194.3 434 434 434c153.2 0 285.8-77.2 362.8-201.8 3.5-5.2-.4-12.2-6.8-12.2z"></path>
                    <path d="M856 480H432c-4.4 0-8 3.6-8 8v48c0 4.4 3.6 8 8 8h424c4.4 0 8-3.6 8-8v-48c0-4.4-3.6-8-8-8z"></path>
                </svg>
                <span>Portal Utama</span>
            </a>
        </div>
    </header>

    <!-- APP LAYOUT -->
    <div class="app-layout">

        <!-- SIDEBAR -->
        <aside class="sidebar">
            <div class="sidebar-section-title">Menu Utilitas</div>
            <ul class="sidebar-menu">
                <li>
                    <button type="button" class="menu-item-btn active" id="nav-btn-pdf" onclick="switchTab('pdf')">
                        <span class="menu-icon">🤖</span>
                        <span>PDF Extractor &rarr; Excel CEISA</span>
                        <span class="badge-new">AI</span>
                    </button>
                </li>
                <li>
                    <button type="button" class="menu-item-btn" id="nav-btn-backup" onclick="switchTab('backup')">
                        <span class="menu-icon">📊</span>
                        <span>Konversi Excel Backup Modul TPB</span>
                    </button>
                </li>
                <li>
                    <button type="button" class="menu-item-btn" id="nav-btn-btki" onclick="switchTab('btki')">
                        <span class="menu-icon">📖</span>
                        <span>Buku Tarif Kepabeanan Indonesia (BTKI) 2022</span>
                        <span class="badge-soon">Coming Soon</span>
                    </button>
                </li>
            </ul>

            <div class="sidebar-footer">
                <div style="color:#e2e8f0; font-weight:700; margin-bottom:4px;">100% Bebas Akses</div>
                Pemrosesan berjalan instan di RAM server tanpa menyimpan berkas transaksi Anda ke database.
            </div>
        </aside>

        <!-- CONTENT AREA -->
        <main class="content-area">

            <!-- ============================================================== -->
            <!-- TAB 1: PDF EXTRACTOR KE EXCEL CEISA 4.0 (SHEET BARANG) -->
            <!-- ============================================================== -->
            <div class="view-panel active" id="view-pdf">
                <div class="view-intro">
                    <h2>PDF Extractor &rarr; Excel CEISA 4.0 (Sheet BARANG)</h2>
                    <p>Ekstraksi cerdas dokumen Invoice &amp; Packing List PDF berbasis AI LLM, otomatis dikemas ke Sheet BARANG standar resmi CEISA 4.0.</p>
                </div>

                <div class="tool-card">
                    <div class="tool-card-header">
                        <div class="tool-card-title">
                            <h3>Upload Dokumen PDF (Commercial Invoice / Packing List)</h3>
                            <p>Ekstraksi otomatis Kode Barang, Deskripsi, HS Code, Qty, Satuan, Harga, dan Total Nilai ke format Sheet BARANG</p>
                        </div>
                        <span class="badge-status success" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd;">
                            🤖 Powered by AI Vision &amp; LLM
                        </span>
                    </div>
                    <div class="tool-card-body">

                        <!-- Demo Credit Balance Banner -->
                        <div class="ai-credit-banner">
                            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                                <div class="ai-credit-stat">
                                    <span style="font-size:16px;">💳</span>
                                    <span style="font-weight:600; color:#cbd5e1;">Saldo AI:</span>
                                    <span class="ai-credit-pill" id="aiBalanceRpVal">Rp 0</span>
                                </div>
                                <div style="display:inline-flex; align-items:center; gap:8px; font-size:11px; color:#94a3b8;">
                                    <span>•</span>
                                    <span>Total Request: <b id="aiTotalRequestsVal" style="color:#f1f5f9; font-family:'JetBrains Mono', monospace;">0</b> req</span>
                                    <span>•</span>
                                    <span>Terpakai: <b id="aiCreditsUsedVal" style="color:#f87171; font-family:'JetBrains Mono', monospace;">Rp 0</b></span>
                                </div>
                            </div>
                            <div style="display:flex; align-items:center; gap:10px;">
                                <button type="button" class="btn-reset-credit" onclick="fetchAiBalance(true)" title="Sinkronkan saldo aktual sekarang">
                                    <span>↺</span> <span>Refresh Saldo</span>
                                </button>
                            </div>
                        </div>

                        <!-- AI Model & Tier Selection Box -->
                        <div class="ai-model-box">
                            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:12px;">
                                <div>
                                    <div style="font-size:13px; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:6px;">
                                        <span>🧠</span> <span>Pilih Tingkat Kecerdasan &amp; Model AI</span>
                                    </div>
                                    <div style="font-size:11.5px; color:#64748b; margin-top:2px;">
                                        Sesuaikan tingkatan tier kecerdasan dan model AI dengan kompleksitas dokumen PDF invoice Anda.
                                    </div>
                                </div>
                                <!-- Tier Tabs -->
                                <div class="tier-tabs">
                                    <button type="button" class="tier-btn active" data-tier="Hemat" onclick="selectTier('Hemat')">
                                        <span>🟢</span> <span>Hemat</span>
                                    </button>
                                    <button type="button" class="tier-btn" data-tier="Seimbang" onclick="selectTier('Seimbang')">
                                        <span>🔵</span> <span>Seimbang</span>
                                    </button>
                                    <button type="button" class="tier-btn" data-tier="Flagship" onclick="selectTier('Flagship')">
                                        <span>🟣</span> <span>Flagship</span>
                                    </button>
                                </div>
                            </div>

                            <!-- Model Selector Dropdown & Live Info -->
                            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                                <div style="flex:1; min-width:280px;">
                                    <label style="display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:4px;">
                                        Pilihan Model AI (<span id="modelTierLabel" style="color:#15803d; font-weight:800;">Tier Hemat</span>):
                                    </label>
                                    <select id="aiModelSelect" style="width:100%; font-size:12.5px; font-weight:600; padding:8px 12px; border-radius:6px; border:1px solid #cbd5e1; background:#ffffff; color:#1e293b; cursor:pointer;" onchange="onModelChange()">
                                        <!-- Opsi model diisi via JavaScript -->
                                    </select>
                                </div>
                                <div id="modelDetailBadge" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:8px 14px; font-size:11.5px; color:#475569; display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-top:16px;">
                                    <!-- Dynamic details -->
                                </div>
                            </div>
                        </div>

                        <!-- Dropzone PDF -->
                        <div class="dropzone-container" id="dropzonePdf">
                            <input type="file" id="fileInputPdf" class="dropzone-file-input" accept=".pdf">
                            <div class="dropzone-icon">
                                <svg viewBox="64 64 896 896" width="32" height="32" fill="#ef4444">
                                    <path d="M532 546c10.7-5.9 22.8-10.7 36.3-14.3.4-1.2.9-2.5 1.3-3.7 18.2-50.5 35.8-132.8 24.3-178.6-3.8-15-13.6-26.4-27.6-32.1-13.8-5.6-29.3-5-42.6 1.7-18.7 9.4-28.5 29.5-27.6 56.6 1 31.8 14.5 73.1 38.3 120.7 1.4 2.8 2.8 5.6 4.3 8.4-15.1 40.2-35.1 85.9-57.8 132.5-40 21.8-74.5 45.4-98 67.5-23.7 22.2-34.9 44.5-32.3 64.4 2.5 19 15.6 33.7 34 38.4 4.5 1.1 9.2 1.7 14 1.7 18.7 0 38.7-8.9 59.5-26.5 28.5-24.1 57.6-61.9 84.4-110.1 48-15.7 96.6-26.6 141.4-31.9 19.3 17.5 38.8 31.6 57.6 41.7 20 10.7 38.2 16.3 54.2 16.6 2.3 0 4.6-.2 6.8-.5 18.2-2.5 31.9-14.4 36.6-31.8 4.7-17.6-.4-36.4-13.9-51.5-17.8-19.9-46.7-34.5-83.6-42.2-1.2-.3-2.5-.5-3.8-.7-10.2-2.1-20.9-3.7-32-4.9zm-4.7-164.2c.4-1.7 1-3.3 1.8-4.9 1.8-3.7 4.1-5.7 6.6-5.8.4 0 .9 0 1.3.1 3.5 1.5 6.4 5.3 7.8 10.8 4.7 18.6-2.5 60.1-13.8 98.7-9.5-29.1-12.8-54.6-11.7-77.9.6-12.2 4.1-18.9 8-21zM342.3 762.6c-4.4-1.1-7.5-4.5-8-8.8-.6-4.6 1.7-11.4 7.9-19.5 9.1-11.9 25.1-25.9 46.5-40.4-23.9 39.5-41.6 62.7-46.4 68.7zm382.4-74.9c5.1 5.7 6.5 11.8 4.5 16.9-1.9 4.9-6.9 8.2-13.1 8.7-.9.1-1.8.1-2.7.1-7.2 0-16.5-3.5-27-10.3-9.5-6.2-19.7-14.7-29.8-24.8 19.9 2.5 36.9 6.2 49.3 11.4 11 4.7 16 8.3 18.8 18z"></path>
                                    <path d="M854.6 288.6L639.4 73.4c-6-6-14.1-9.4-22.6-9.4H192c-17.7 0-32 14.3-32 32v832c0 17.7 14.3 32 32 32h640c17.7 0 32-14.3 32-32V311.3c0-8.6-3.4-16.7-9.4-22.7zM790.2 320H608V137.8L790.2 320zm-26.2 608H224V128h320v224c0 17.7 14.3 32 32 32h224v544z"></path>
                                </svg>
                            </div>
                            <div class="dropzone-title">Tarik &amp; Lepaskan Berkas Invoice / Packing List (.pdf) ke Sini</div>
                            <div class="dropzone-desc">Sistem AI akan membaca teks dokumen secara cerdas (termasuk otomatis OCR jika PDF hasil scan / pindaian) dan menyusun tabel ke format Excel resmi Sheet BARANG CEISA 4.0.</div>
                            <button type="button" class="btn-choose-file" onclick="document.getElementById('fileInputPdf').click()" style="background:linear-gradient(135deg, #ef4444 0%, #b91c1c 100%);">
                                <span>Pilih Berkas PDF Invoice</span>
                            </button>
                        </div>

                        <!-- Hasil Ekstraksi PDF Section -->
                        <div class="queue-section" id="queueSectionPdf">
                            <div class="queue-header">
                                <div class="queue-title">
                                    <span style="font-size:16px;">📑</span>
                                    <span>Hasil Ekstraksi Dokumen:</span>
                                    <span id="pdfFileNameBadge" style="font-weight:800; color:#0284c7;">-</span>
                                    <span id="pdfItemsCountBadge" style="background:#e0f2fe; color:#0369a1; font-size:11px; padding:2px 8px; border-radius:10px; font-weight:700;">0 item</span>
                                    <span id="pdfOcrBadge" style="display:none; background:#fef3c7; border:1px solid #fde68a; color:#b45309; font-size:11px; padding:2px 8px; border-radius:10px; font-weight:700;">🔍 Auto-OCR Scan</span>
                                </div>
                                <div class="queue-actions">
                                    <a href="#" class="btn-action-sm btn-download-all" id="btnDownloadPdfExcel" target="_blank">
                                        <span>📥 Unduh Excel (Sheet BARANG)</span>
                                    </a>
                                    <button type="button" class="btn-action-sm btn-copy-table" onclick="copyExtractedTable()">
                                        <span>📋 Salin Tabel</span>
                                    </button>
                                    <button type="button" class="btn-action-sm btn-clear-queue" onclick="resetPdfExtractor()">
                                        <span>✕ Reset</span>
                                    </button>
                                </div>
                            </div>

                            <div style="font-size:11.5px; color:#64748b; margin-bottom:8px;">
                                💡 <i>Anda dapat langsung mengeklik sel pada tabel untuk mengoreksi nilai Kode Barang, Uraian, Qty, Satuan, atau Harga sebelum mengunduh Excel.</i>
                            </div>

                            <div class="file-table-box">
                                <table class="file-table" id="pdfResultTable">
                                    <thead>
                                        <tr>
                                            <th style="width:30px;">#</th>
                                            <th style="width:130px;">Kode Barang</th>
                                            <th>Uraian Barang</th>
                                            <th style="width:95px;">HS Code</th>
                                            <th style="width:75px; text-align:right;">Qty</th>
                                            <th style="width:70px;">Satuan</th>
                                            <th style="width:95px; text-align:right;">Harga Satuan</th>
                                            <th style="width:105px; text-align:right;">Total Nilai</th>
                                        </tr>
                                    </thead>
                                    <tbody id="pdfResultTableBody"></tbody>
                                </table>
                            </div>

                            <!-- Token Usage & Cost Info Strip -->
                            <div id="aiUsageStrip" style="margin-top:12px; display:none; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; font-size:11.5px; color:#475569; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                    <span style="font-size:14px;">⚡</span>
                                    <span><b>Model AI:</b> <span id="usageModelVal" style="font-family:'JetBrains Mono', monospace; font-weight:700; color:#1e293b;">-</span></span>
                                    <span style="color:#cbd5e1;">&bull;</span>
                                    <span><b>Token:</b> <span id="usageTokensVal" style="font-family:'JetBrains Mono', monospace; font-weight:700; color:#0284c7;">-</span></span>
                                    <span style="color:#cbd5e1;">&bull;</span>
                                    <span style="color:#64748b;">(Prompt: <span id="usagePromptTokensVal">0</span> &bull; Completion: <span id="usageCompTokensVal">0</span>)</span>
                                    <span id="ocrNotice" style="display:none; color:#b45309; font-weight:700;"><span style="color:#cbd5e1;">&bull;</span> 🔍 Dokumen Hasil Scan (Auto-OCR)</span>
                                </div>
                                <div style="display:flex; align-items:center; gap:6px;">
                                    <span>💰 <b>Estimasi Biaya:</b></span>
                                    <span id="usageCostBadge" style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; font-family:'JetBrains Mono', monospace; font-size:11px; font-weight:700; padding:2px 8px; border-radius:5px;">
                                        Rp 0
                                    </span>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- ============================================================== -->
            <!-- TAB 2: EXCEL BACKUP MODUL TPB -> EXCEL CEISA 4.0 -->
            <!-- ============================================================== -->
            <div class="view-panel" id="view-backup">
                <div class="view-intro">
                    <h2>Excel Backup Modul TPB &rarr; Excel CEISA 4.0</h2>
                    <p>Mengonversi berkas Excel hasil backup aplikasi Modul TPB Bea Cukai menjadi format standar resmi 21 Sheet (.xlsx) siap upload di CEISA 4.0.</p>
                </div>

                <div class="tool-card">
                    <div class="tool-card-header">
                        <div class="tool-card-title">
                            <h3>Excel Backup Modul TPB &rarr; Excel CEISA 4.0</h3>
                            <p>Dukungan dokumen BC 2.5, BC 2.3, BC 4.0, BC 2.7, BC 2.0, dan BC 3.0</p>
                        </div>
                        <span class="badge-status success">✓ Multi-File Batch Aktif</span>
                    </div>
                    <div class="tool-card-body">

                        <div class="doc-chips-bar">
                            <span class="doc-chips-title">Format Modul TPB:</span>
                            <span class="doc-chip popular">Backup BC 2.5 (Pengeluaran TPB ke TLDDP)</span>
                            <span class="doc-chip popular">Backup BC 2.3 (Impor / Pemasukan TPB)</span>
                            <span class="doc-chip">Backup BC 4.0 (Pemasukan TLDDP)</span>
                            <span class="doc-chip">Backup BC 2.7 (Transfer Antar TPB)</span>
                            <span class="doc-chip">BC 2.0 (Impor Umum)</span>
                            <span class="doc-chip">BC 3.0 (Ekspor)</span>
                        </div>

                        <!-- Dropzone Modul TPB -->
                        <div class="dropzone-container" id="dropzoneBackup">
                            <input type="file" id="fileInputBackup" class="dropzone-file-input" multiple accept=".xls,.xlsx">
                            <div class="dropzone-icon">
                                <svg viewBox="64 64 896 896" width="30" height="30" fill="currentColor">
                                    <path d="M518.3 459a8 8 0 0 0-12.6 0l-112 141.7a8 8 0 0 0 6.3 13h52v184c0 4.4 3.6 8 8 8h64c4.4 0 8-3.6 8-8V613.7h52c6.9 0 10.6-8.2 6.3-13L518.3 459z"></path>
                                    <path d="M811.4 366.7C765.6 245.9 648.9 160 512 160S258.4 245.9 212.6 366.7A273.31 273.31 0 0 0 112 585.8C112 734.9 233.1 856 382.2 856H400c4.4 0 8-3.6 8-8v-56c0-4.4-3.6-8-8-8h-17.8c-109.4 0-198.2-88.8-198.2-198.2 0-96.1 68.6-176.6 161.7-194.2l26.4-5 7.6-25.8C405 277.4 496.1 216 600 216c123.7 0 224 100.3 224 224 0 14.4-1.4 28.7-4.1 42.6l-5.3 26.6 26.3 6.4c84.4 20.6 143.1 96.6 143.1 184.4 0 105-85 190-190 190H784c-4.4 0-8 3.6-8 8v56c0 4.4 3.6 8 8 8h10c144.7 0 262-117.3 262-262 0-112.5-70.9-209.1-171.6-245.3z"></path>
                                </svg>
                            </div>
                            <div class="dropzone-title">Tarik &amp; Lepaskan Berkas Backup Modul TPB (.xls / .xlsx) ke Sini</div>
                            <div class="dropzone-desc">Dukungan penuh berkas hasil cadangan Modul TPB (BC 2.5, BC 2.3, BC 4.0, BC 2.7). Anda dapat memilih banyak berkas sekaligus.</div>
                            <button type="button" class="btn-choose-file" onclick="document.getElementById('fileInputBackup').click()">
                                <span>Pilih Berkas Backup Modul TPB</span>
                            </button>
                        </div>

                        <!-- Queue Modul TPB -->
                        <div class="queue-section" id="queueSectionBackup">
                            <div class="queue-header">
                                <div class="queue-title">
                                    <span>Daftar Berkas Terpilih</span>
                                    <span id="queueCountBadgeBackup" style="background:#e2e8f0; color:#334155; font-size:11px; padding:2px 8px; border-radius:10px; font-weight:700;">0 file</span>
                                </div>
                                <div class="queue-actions">
                                    <button type="button" class="btn-action-sm btn-convert-all" id="btnConvertAllBackup" onclick="startBackupConversion()">
                                        <span>⚡ Konversi Semua Sekarang</span>
                                    </button>
                                    <a href="#" class="btn-action-sm btn-download-all" id="btnDownloadZipBackup" style="display:none;" target="_blank">
                                        <span>📦 Unduh Semua (.ZIP)</span>
                                    </a>
                                    <button type="button" class="btn-action-sm btn-clear-queue" onclick="resetBackupQueue()">
                                        <span>✕ Bersihkan</span>
                                    </button>
                                </div>
                            </div>

                            <div class="file-table-box">
                                <table class="file-table">
                                    <thead>
                                        <tr>
                                            <th style="width:30px;">#</th>
                                            <th>Nama Berkas Asli</th>
                                            <th style="width:85px;">Ukuran</th>
                                            <th style="width:130px;">Status</th>
                                            <th>Identitas Dokumen Pabean</th>
                                            <th style="width:120px; text-align:center;">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody id="fileTableBodyBackup"></tbody>
                                </table>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- ============================================================== -->
            <!-- TAB 3: BUKU TARIF KEPABEANAN INDONESIA (BTKI) 2022 -->
            <!-- ============================================================== -->
            <div class="view-panel" id="view-btki">
                <div class="view-intro">
                    <h2>Buku Tarif Kepabeanan Indonesia (BTKI) 2022</h2>
                    <p>Basis data referensi resmi pos tarif HS Code, Bea Masuk (MFN), PPN, PPh 22, dan regulasi Lartas kepabeanan.</p>
                </div>

                <div class="tool-card">
                    <div class="tool-card-body" style="padding: 48px 24px; text-align: center;">
                        <div style="font-size: 54px; margin-bottom: 16px;">📖✨</div>
                        <div style="display: inline-block; background: #fef3c7; border: 1px solid #fde68a; color: #b45309; font-size: 11.5px; font-weight: 700; padding: 4px 12px; border-radius: 20px; margin-bottom: 14px;">
                            ⏳ Sedang Dalam Tahap Penyusunan (Coming Soon)
                        </div>
                        <h3 style="font-size: 18px; font-weight: 800; color: var(--primary-dark); margin-bottom: 8px;">
                            Buku Tarif Kepabeanan Indonesia (BTKI) 2022
                        </h3>
                        <p style="font-size: 13px; color: var(--text-muted); max-width: 580px; margin: 0 auto 24px; line-height: 1.6;">
                            Fitur pencarian interaktif HS Code 8 digit, penelusuran tarif Bea Masuk, PPN Impor, PPh Pasal 22, serta verifikasi persyaratan izin Lartas kementerian/lembaga terkait sedang dipersiapkan dan segera hadir di pembaruan berikutnya.
                        </p>
                        <div style="display: flex; justify-content: center; gap: 12px; flex-wrap: wrap;">
                            <button type="button" class="btn-action-sm btn-convert-all" onclick="switchTab('backup')">
                                <span>&larr; Buka Konversi Excel Backup Modul TPB</span>
                            </button>
                            <button type="button" class="btn-action-sm btn-download-all" onclick="switchTab('pdf')">
                                <span>🤖 Coba PDF Extractor AI &rarr;</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FOOTNOTE PRIVASI -->
            <div class="security-footnote">
                <div class="sec-note-item">
                    <span>🔒</span> <b>Zero Database Storage:</b> Berkas dan data Anda tidak pernah disimpan ke database.
                </div>
                <div class="sec-note-item">
                    <span>⚡</span> <b>100% In-Memory Processing:</b> Pemrosesan sementara di memori server berkecepatan tinggi.
                </div>
                <div class="sec-note-item">
                    <span>🗑️</span> <b>Pemusnahan Instan:</b> Berkas sumber &amp; hasil langsung dihapus permanen.
                </div>
            </div>

        </main>
    </div>

    <!-- JAVASCRIPT LOGIC -->
    <script>
        // -------------------------------------------------------------
        // TAB SWITCHER
        // -------------------------------------------------------------
        function switchTab(tab) {
            document.querySelectorAll('.menu-item-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.view-panel').forEach(panel => panel.classList.remove('active'));

            if (tab === 'backup') {
                document.getElementById('nav-btn-backup').classList.add('active');
                document.getElementById('view-backup').classList.add('active');
            } else if (tab === 'btki') {
                document.getElementById('nav-btn-btki').classList.add('active');
                document.getElementById('view-btki').classList.add('active');
            } else {
                document.getElementById('nav-btn-pdf').classList.add('active');
                document.getElementById('view-pdf').classList.add('active');
            }
        }

        // -------------------------------------------------------------
        // UTILS
        // -------------------------------------------------------------
        function formatBytes(bytes) {
            if (!bytes || bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // =============================================================
        // MODULE 1: BACKUP MODUL TPB CONVERTER
        // =============================================================
        let queuedBackupFiles = [];
        let activeBackupBatchId = null;

        const dropzoneBackup = document.getElementById('dropzoneBackup');
        const fileInputBackup = document.getElementById('fileInputBackup');
        const queueSectionBackup = document.getElementById('queueSectionBackup');
        const fileTableBodyBackup = document.getElementById('fileTableBodyBackup');
        const queueCountBadgeBackup = document.getElementById('queueCountBadgeBackup');
        const btnConvertAllBackup = document.getElementById('btnConvertAllBackup');
        const btnDownloadZipBackup = document.getElementById('btnDownloadZipBackup');

        ['dragenter', 'dragover'].forEach(eventName => {
            dropzoneBackup.addEventListener(eventName, (e) => {
                e.preventDefault(); e.stopPropagation();
                dropzoneBackup.classList.add('is-dragover');
            });
        });
        ['dragleave', 'drop'].forEach(eventName => {
            dropzoneBackup.addEventListener(eventName, (e) => {
                e.preventDefault(); e.stopPropagation();
                dropzoneBackup.classList.remove('is-dragover');
            });
        });
        dropzoneBackup.addEventListener('drop', (e) => {
            handleBackupFiles(e.dataTransfer.files);
        });
        fileInputBackup.addEventListener('change', (e) => {
            handleBackupFiles(e.target.files);
        });

        function handleBackupFiles(files) {
            if (!files || files.length === 0) return;
            for (let i = 0; i < files.length; i++) {
                const f = files[i];
                const ext = f.name.split('.').pop().toLowerCase();
                if (ext !== 'xls' && ext !== 'xlsx') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Format Ditolak',
                        text: `Berkas "${f.name}" bukan format Excel (.xls/.xlsx).`
                    });
                    continue;
                }
                queuedBackupFiles.push({
                    id: 'f_' + Date.now() + '_' + Math.random().toString(36).substring(2, 7),
                    file: f,
                    name: f.name,
                    size: f.size,
                    status: 'waiting',
                    resData: null
                });
            }
            renderBackupQueue();
        }

        function renderBackupQueue() {
            if (queuedBackupFiles.length === 0) {
                queueSectionBackup.style.display = 'none';
                return;
            }
            queueSectionBackup.style.display = 'block';
            queueCountBadgeBackup.textContent = queuedBackupFiles.length + ' file';
            fileTableBodyBackup.innerHTML = '';

            queuedBackupFiles.forEach((item, idx) => {
                const tr = document.createElement('tr');
                let statusBadge = '<span class="badge-status waiting">⏳ Menunggu</span>';
                if (item.status === 'uploading') {
                    statusBadge = `<span class="badge-status processing"><span class="spin">⬆️</span> ${item.progress || 0}%</span>`;
                } else if (item.status === 'processing') {
                    statusBadge = '<span class="badge-status processing"><span class="spin">⚙️</span> Memproses...</span>';
                } else if (item.status === 'success') {
                    statusBadge = '<span class="badge-status success">✓ Berhasil</span>';
                } else if (item.status === 'error') {
                    statusBadge = '<span class="badge-status error">✕ Gagal</span>';
                }

                let detailsHtml = '<span style="color:#94a3b8; font-size:11.5px;">Belum diproses</span>';
                let actionHtml = '<span style="color:#cbd5e1;">-</span>';

                if (item.resData) {
                    if (item.resData.status === 'SUCCESS') {
                        const noAju = item.resData.nomor_aju || '-';
                        const cmp = item.resData.perusahaan || '-';
                        const kd = item.resData.kode_dokumen || '25';
                        const stats = item.resData.stats || {};
                        detailsHtml = `
                            <div>
                                <span style="font-family:'JetBrains Mono', monospace; font-weight:700; color:#0284c7;">BC ${kd}: ${escapeHtml(noAju)}</span>
                                <div style="font-size:11px; color:#475569; font-weight:600;">${escapeHtml(cmp)}</div>
                                <div style="font-size:10px; color:#64748b; margin-top:2px;">
                                    ${stats.barang || 0} Barang &bull; ${stats.bahan_baku || 0} BB &bull; ${stats.dokumen || 0} Dokumen
                                </div>
                            </div>
                        `;
                        actionHtml = `
                            <a href="${item.resData.download_url}" class="btn-download-item">
                                <span>📥 Unduh .xlsx</span>
                            </a>
                        `;
                    } else {
                        detailsHtml = `<span style="color:#dc2626; font-size:11.5px;">${escapeHtml(item.resData.message || 'Gagal')}</span>`;
                    }
                }

                tr.innerHTML = `
                    <td style="color:#94a3b8; font-family:'JetBrains Mono', monospace;">${idx + 1}</td>
                    <td style="font-weight:600;">📄 ${escapeHtml(item.name)}</td>
                    <td style="color:#64748b; font-size:11.5px;">${formatBytes(item.size)}</td>
                    <td>${statusBadge}</td>
                    <td>${detailsHtml}</td>
                    <td style="text-align:center;">${actionHtml}</td>
                `;
                fileTableBodyBackup.appendChild(tr);
            });
        }

        function startBackupConversion() {
            if (queuedBackupFiles.length === 0) return;
            btnConvertAllBackup.disabled = true;
            btnConvertAllBackup.innerHTML = '<span class="spin">⬆️</span> Mengunggah berkas...';

            const formData = new FormData();
            queuedBackupFiles.forEach(item => {
                formData.append('files[]', item.file);
                item.status = 'uploading';
                item.progress = 0;
            });
            renderBackupQueue();

            const xhr = new XMLHttpRequest();
            xhr.open('POST', '/tools/api.php?action=convert', true);
            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) {
                    const percent = Math.min(99, Math.round((e.loaded / e.total) * 100));
                    btnConvertAllBackup.innerHTML = `<span class="spin">⬆️</span> Mengunggah ${percent}%...`;
                    queuedBackupFiles.forEach(item => { if (item.status === 'uploading') item.progress = percent; });
                    renderBackupQueue();
                }
            };
            xhr.upload.onload = function() {
                btnConvertAllBackup.innerHTML = '<span class="spin">⚙️</span> Memproses Konversi CEISA 4.0...';
                queuedBackupFiles.forEach(item => { if (item.status === 'uploading') item.status = 'processing'; });
                renderBackupQueue();
            };
            xhr.onload = function() {
                btnConvertAllBackup.disabled = false;
                btnConvertAllBackup.innerHTML = '<span>⚡ Konversi Semua Sekarang</span>';
                try {
                    const res = JSON.parse(xhr.responseText);
                    if (res && res.status === 'SUCCESS') {
                        activeBackupBatchId = res.batch_id;
                        (res.items || []).forEach((r, idx) => {
                            if (queuedBackupFiles[idx]) {
                                queuedBackupFiles[idx].status = r.status.toLowerCase();
                                queuedBackupFiles[idx].resData = r;
                            }
                        });
                        renderBackupQueue();
                        if (res.zip_url) {
                            btnDownloadZipBackup.href = res.zip_url;
                            btnDownloadZipBackup.style.display = 'inline-flex';
                        }
                        Swal.fire({
                            icon: 'success',
                            title: 'Konversi Selesai!',
                            html: `<b>${res.success_count} dari ${res.total}</b> berkas berhasil dikonversi ke format resmi Excel CEISA 4.0.`
                        });
                    } else {
                        queuedBackupFiles.forEach(item => { item.status = 'error'; });
                        renderBackupQueue();
                        Swal.fire({ icon: 'error', title: 'Konversi Gagal', text: res.message || 'Terjadi kesalahan.' });
                    }
                } catch(e) {
                    queuedBackupFiles.forEach(item => { item.status = 'error'; });
                    renderBackupQueue();
                    Swal.fire({ icon: 'error', title: 'Respon Server Gagal', text: e.message });
                }
            };
            xhr.onerror = function() {
                btnConvertAllBackup.disabled = false;
                btnConvertAllBackup.innerHTML = '<span>⚡ Konversi Semua Sekarang</span>';
                Swal.fire({ icon: 'error', title: 'Koneksi Terputus' });
            };
            xhr.send(formData);
        }

        function resetBackupQueue() {
            if (activeBackupBatchId) {
                const fd = new FormData();
                fd.append('batch', activeBackupBatchId);
                fetch('/tools/api.php?action=cleanup', { method: 'POST', body: fd }).catch(() => {});
            }
            queuedBackupFiles = [];
            activeBackupBatchId = null;
            btnDownloadZipBackup.style.display = 'none';
            fileInputBackup.value = '';
            renderBackupQueue();
        }

        // =============================================================
        // MODULE 2: PDF EXTRACTOR TO EXCEL CEISA 4.0
        // =============================================================
        const AI_MODELS = [
            // TIER: HEMAT (10 model)
            { id: 'gpt-4o-mini', name: 'GPT-4o Mini', tier: 'Hemat', context: '128K', maxRp: 30, desc: 'Paling populer, cepat & stabil' },
            { id: 'gpt-5-mini', name: 'GPT-5 Mini', tier: 'Hemat', context: '400K', maxRp: 30, desc: 'Konteks 400K & efisien' },
            { id: 'gpt-54-mini', name: 'GPT-5.4 Mini', tier: 'Hemat', context: '400K', maxRp: 80, desc: 'Generasi baru GPT-5' },
            { id: 'gemini-35', name: 'Gemini 3.5 Flash', tier: 'Hemat', context: '1.000K', maxRp: 200, desc: '1 Juta Konteks Super Cepat' },
            { id: 'gemini-36', name: 'Gemini 3.6 Flash', tier: 'Hemat', context: '1.000K', maxRp: 70, desc: 'Flash generasi 3.6' },
            { id: 'gemini-37', name: 'Gemini 3.7 Flash', tier: 'Hemat', context: '1.000K', maxRp: 70, desc: 'Flash generasi 3.7' },
            { id: 'gemini-38', name: 'Gemini 3.8 Flash', tier: 'Hemat', context: '1.000K', maxRp: 70, desc: 'Flash generasi terbaru' },
            { id: 'mai-code', name: 'MAI Code 1.1 Flash', tier: 'Hemat', context: '256K', maxRp: 20, desc: 'Termurah Rp 20 / req' },
            { id: 'qwen3-flash', name: 'Qwen3 Flash', tier: 'Hemat', context: '1.000K', maxRp: 70, desc: '1 Juta Konteks Alibaba' },
            { id: 'mistral-small', name: 'Mistral Small 3.2', tier: 'Hemat', context: '128K', maxRp: 30, desc: 'Ringan & terjangkau' },

            // TIER: SEIMBANG (17 model)
            { id: 'sonnet-55', name: 'Claude Sonnet 5.5', tier: 'Seimbang', context: '1.000K', maxRp: 200, desc: 'Rekomendasi akurasi invoice rumit' },
            { id: 'sonnet-5', name: 'Claude Sonnet 5', tier: 'Seimbang', context: '1.000K', maxRp: 200, desc: 'Akurasi tinggi Anthropic' },
            { id: 'haiku-45', name: 'Claude Haiku 4.5', tier: 'Seimbang', context: '200K', maxRp: 90, desc: 'Responsif & presisi' },
            { id: 'gpt-6-luna', name: 'GPT-6 Luna', tier: 'Seimbang', context: '1.000K', maxRp: 40, desc: 'GPT-6 hemat Rp 40' },
            { id: 'gpt-55', name: 'GPT-5.5', tier: 'Seimbang', context: '400K', maxRp: 480, desc: 'Penalaran tinggi GPT-5' },
            { id: 'gpt-54', name: 'GPT-5.4', tier: 'Seimbang', context: '400K', maxRp: 240, desc: 'Handal & presisi' },
            { id: 'gpt-53-codex', name: 'GPT-5.3 Codex', tier: 'Seimbang', context: '400K', maxRp: 190, desc: 'Spesialis data struktural' },
            { id: 'grok-45', name: 'Grok 4.5', tier: 'Seimbang', context: '256K', maxRp: 360, desc: 'Model xAI' },
            { id: 'grok-46', name: 'Grok 4.6', tier: 'Seimbang', context: '256K', maxRp: 360, desc: 'Model xAI lanjutan' },
            { id: 'grok-47', name: 'Grok 4.7', tier: 'Seimbang', context: '256K', maxRp: 360, desc: 'Model xAI terkini' },
            { id: 'kimi-k27', name: 'Kimi K2.7 Code', tier: 'Seimbang', context: '256K', maxRp: 100, desc: 'Kimi pemecah tabel' },
            { id: 'kimi-k3', name: 'Kimi K3', tier: 'Seimbang', context: '256K', maxRp: 270, desc: 'Generasi Kimi K3' },
            { id: 'deepseek-v3', name: 'DeepSeek V3', tier: 'Seimbang', context: '400K', maxRp: 480, desc: 'Penalaran DeepSeek kuat' },
            { id: 'deepseek-coder', name: 'DeepSeek Coder V2', tier: 'Seimbang', context: '400K', maxRp: 190, desc: 'Parsing kode & data' },
            { id: 'qwen3-coder', name: 'Qwen3 Coder', tier: 'Seimbang', context: '400K', maxRp: 190, desc: 'Alibaba Qwen Coder' },
            { id: 'qwen3-plus', name: 'Qwen3 Plus', tier: 'Seimbang', context: '400K', maxRp: 480, desc: 'Qwen Plus penalaran tinggi' },
            { id: 'llama-4-scout', name: 'Llama 4 Scout', tier: 'Seimbang', context: '200K', maxRp: 90, desc: 'Meta Llama 4 ringan' },
            { id: 'codestral', name: 'Codestral 25', tier: 'Seimbang', context: '256K', maxRp: 190, desc: 'Mistral data & code' },

            // TIER: FLAGSHIP (17 model)
            { id: 'opus-5', name: 'Claude Opus 5', tier: 'Flagship', context: '1.000K', maxRp: 480, desc: 'Kemampuan analisis tertinggi Anthropic' },
            { id: 'opus-48', name: 'Claude Opus 4.8', tier: 'Flagship', context: '1.000K', maxRp: 480, desc: 'Flagship Opus 4.8' },
            { id: 'opus-47', name: 'Claude Opus 4.7', tier: 'Flagship', context: '1.000K', maxRp: 480, desc: 'Flagship Opus 4.7' },
            { id: 'fable-5', name: 'Claude Fable 5', tier: 'Flagship', context: '1.000K', maxRp: 890, desc: 'Model penalaran ekstrem' },
            { id: 'fable-51', name: 'Claude Fable 5.1', tier: 'Flagship', context: '1.000K', maxRp: 800, desc: 'Claude Fable 5.1' },
            { id: 'astra', name: 'GPT-6 Astra', tier: 'Flagship', context: '1.000K', maxRp: 890, desc: 'Flagship tertinggi OpenAI' },
            { id: 'gpt-61-sol', name: 'GPT-6.1 Sol', tier: 'Flagship', context: '1.000K', maxRp: 200, desc: 'GPT-6.1 Sol 1M Konteks' },
            { id: 'gpt-6-sol', name: 'GPT-6 Sol', tier: 'Flagship', context: '1.000K', maxRp: 200, desc: 'GPT-6 Sol' },
            { id: 'terra', name: 'GPT-5.6 Terra', tier: 'Flagship', context: '1.000K', maxRp: 190, desc: 'GPT-5.6 Terra' },
            { id: 'sol', name: 'GPT-5.6 Sol', tier: 'Flagship', context: '1.000K', maxRp: 360, desc: 'GPT-5.6 Sol' },
            { id: 'luna', name: 'GPT-5.6 Luna', tier: 'Flagship', context: '1.000K', maxRp: 40, desc: 'GPT-5.6 Luna ekonomis' },
            { id: 'deepseek-v32', name: 'DeepSeek V3.2', tier: 'Flagship', context: '1.000K', maxRp: 200, desc: 'DeepSeek Flagship V3.2' },
            { id: 'deepseek-r1', name: 'DeepSeek R1', tier: 'Flagship', context: '200K', maxRp: 200, desc: 'DeepSeek Reasoning R1' },
            { id: 'qwen3-max', name: 'Qwen3 Max', tier: 'Flagship', context: '1.000K', maxRp: 200, desc: 'Flagship Alibaba Qwen3 Max' },
            { id: 'llama-4-maverick', name: 'Llama 4 Maverick', tier: 'Flagship', context: '1.000K', maxRp: 200, desc: 'Flagship Meta Llama 4' },
            { id: 'mistral-large', name: 'Mistral Large 3', tier: 'Flagship', context: '256K', maxRp: 200, desc: 'Flagship Mistral Large' }
        ];

        let activeTier = 'Hemat';
        let currentSelectedModel = 'gpt-4o-mini';
        let currentExtractedItems = [];
        let activePdfBatchId = null;

        function initAiModelSelector() {
            const savedTier = localStorage.getItem('inhost_ai_tier');
            const savedModel = localStorage.getItem('inhost_ai_model');
            if (savedTier && ['Hemat', 'Seimbang', 'Flagship'].includes(savedTier)) {
                activeTier = savedTier;
            }
            if (savedModel && AI_MODELS.some(m => m.id === savedModel)) {
                currentSelectedModel = savedModel;
            } else {
                currentSelectedModel = 'gpt-4o-mini';
            }
            renderTierButtons();
            populateModelDropdown();
        }

        function selectTier(tier) {
            activeTier = tier;
            localStorage.setItem('inhost_ai_tier', tier);
            renderTierButtons();

            const modelsInTier = AI_MODELS.filter(m => m.tier === tier);
            const found = modelsInTier.find(m => m.id === currentSelectedModel);
            if (!found && modelsInTier.length > 0) {
                if (tier === 'Hemat') currentSelectedModel = 'gpt-4o-mini';
                else if (tier === 'Seimbang') currentSelectedModel = 'sonnet-55';
                else if (tier === 'Flagship') currentSelectedModel = 'opus-5';
                else currentSelectedModel = modelsInTier[0].id;
                localStorage.setItem('inhost_ai_model', currentSelectedModel);
            }
            populateModelDropdown();
        }

        function renderTierButtons() {
            document.querySelectorAll('.tier-btn').forEach(btn => {
                if (btn.dataset.tier === activeTier) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
            const tierLabel = document.getElementById('modelTierLabel');
            if (tierLabel) {
                tierLabel.textContent = 'Tier ' + activeTier;
                tierLabel.style.color = activeTier === 'Hemat' ? '#15803d' : (activeTier === 'Seimbang' ? '#0369a1' : '#7e22ce');
            }
        }

        function populateModelDropdown() {
            const select = document.getElementById('aiModelSelect');
            if (!select) return;
            select.innerHTML = '';

            const models = AI_MODELS.filter(m => m.tier === activeTier);
            models.forEach(m => {
                const opt = document.createElement('option');
                opt.value = m.id;
                opt.textContent = `${m.name} (${m.id}) — Maks Rp ${m.maxRp.toLocaleString('id-ID')}`;
                if (m.id === currentSelectedModel) opt.selected = true;
                select.appendChild(opt);
            });
            updateModelDetailBadge();
        }

        function onModelChange() {
            const select = document.getElementById('aiModelSelect');
            if (select) {
                currentSelectedModel = select.value;
                localStorage.setItem('inhost_ai_model', currentSelectedModel);
                updateModelDetailBadge();
            }
        }

        function updateModelDetailBadge() {
            const badge = document.getElementById('modelDetailBadge');
            if (!badge) return;
            const m = AI_MODELS.find(x => x.id === currentSelectedModel);
            if (!m) {
                badge.innerHTML = '<span style="color:#94a3b8;">-</span>';
                return;
            }
            badge.innerHTML = `
                <div>⚡ <b>Konteks:</b> <span style="font-family:'JetBrains Mono', monospace; font-weight:700; color:#0284c7;">${escapeHtml(m.context)}</span></div>
                <div style="color:#cbd5e1;">&bull;</div>
                <div>💰 <b>Maks Biaya:</b> <span style="font-family:'JetBrains Mono', monospace; font-weight:700; color:#059669;">Rp ${m.maxRp.toLocaleString('id-ID')}</span> <span style="font-size:10.5px; color:#64748b;">(min Rp 2)</span></div>
                <div style="color:#cbd5e1;">&bull;</div>
                <div style="font-size:11px; color:#64748b;">${escapeHtml(m.desc)}</div>
            `;
        }

        const dropzonePdf = document.getElementById('dropzonePdf');
        const fileInputPdf = document.getElementById('fileInputPdf');
        const queueSectionPdf = document.getElementById('queueSectionPdf');
        const pdfResultTableBody = document.getElementById('pdfResultTableBody');
        const pdfFileNameBadge = document.getElementById('pdfFileNameBadge');
        const pdfItemsCountBadge = document.getElementById('pdfItemsCountBadge');
        const btnDownloadPdfExcel = document.getElementById('btnDownloadPdfExcel');

        ['dragenter', 'dragover'].forEach(eventName => {
            dropzonePdf.addEventListener(eventName, (e) => {
                e.preventDefault(); e.stopPropagation();
                dropzonePdf.classList.add('is-dragover');
            });
        });
        ['dragleave', 'drop'].forEach(eventName => {
            dropzonePdf.addEventListener(eventName, (e) => {
                e.preventDefault(); e.stopPropagation();
                dropzonePdf.classList.remove('is-dragover');
            });
        });
        dropzonePdf.addEventListener('drop', (e) => {
            const files = e.dataTransfer.files;
            if (files && files.length > 0) processPdfExtraction(files[0]);
        });
        fileInputPdf.addEventListener('change', (e) => {
            if (e.target.files && e.target.files.length > 0) processPdfExtraction(e.target.files[0]);
        });

        function processPdfExtraction(file) {
            const ext = file.name.split('.').pop().toLowerCase();
            if (ext !== 'pdf') {
                Swal.fire({ icon: 'warning', title: 'Berkas Bukan PDF', text: 'Mohon unggah berkas berekstensi .pdf' });
                return;
            }

            const activeModelObj = AI_MODELS.find(m => m.id === currentSelectedModel);
            const modelNameText = activeModelObj ? activeModelObj.name : currentSelectedModel;

            Swal.fire({
                title: 'Mengekstrak Dokumen via AI...',
                html: `
                    <div style="font-size:12.5px; color:#475569; margin-top:10px;">
                        Sedang memproses teks &amp; membedah tabel invoice dengan model <b>${escapeHtml(modelNameText)}</b> (${escapeHtml(activeTier)})...<br>
                        <span style="font-size:11px; color:#64748b;">(Estimasi proses 5 - 15 detik)</span>
                    </div>
                `,
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            const formData = new FormData();
            formData.append('file', file);
            formData.append('model', currentSelectedModel || 'gpt-4o-mini');

            fetch('/tools/api.php?action=extract_pdf', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                Swal.close();
                if (data && data.balance) {
                    updateAiBalanceDisplay(data.balance);
                }

                if (data && data.status === 'SUCCESS') {
                    activePdfBatchId = data.batch_id;
                    currentExtractedItems = data.items || [];
                    pdfFileNameBadge.textContent = data.original_name;
                    pdfItemsCountBadge.textContent = currentExtractedItems.length + ' item';
                    btnDownloadPdfExcel.href = data.download_url;

                    // Tampilkan model & info token & estimasi biaya
                    const usage = data.usage;
                    const usageStrip = document.getElementById('aiUsageStrip');

                    // Deteksi status OCR (dokumen scan)
                    const ocrBadge = document.getElementById('pdfOcrBadge');
                    const ocrNotice = document.getElementById('ocrNotice');
                    if (data.is_ocr) {
                        if (ocrBadge) ocrBadge.style.display = 'inline-block';
                        if (ocrNotice) ocrNotice.style.display = 'inline';
                    } else {
                        if (ocrBadge) ocrBadge.style.display = 'none';
                        if (ocrNotice) ocrNotice.style.display = 'none';
                    }

                    if (data.model_used && document.getElementById('usageModelVal')) {
                        const usedObj = AI_MODELS.find(m => m.id === data.model_used);
                        document.getElementById('usageModelVal').textContent = usedObj ? `${usedObj.name} (${usedObj.id})` : data.model_used;
                    }
                    if (usage && usageStrip) {
                        const totalTok = usage.total_tokens || 0;
                        const promptTok = usage.prompt_tokens || 0;
                        const compTok = usage.completion_tokens || 0;
                        document.getElementById('usageTokensVal').textContent = totalTok.toLocaleString('id-ID');
                        document.getElementById('usagePromptTokensVal').textContent = promptTok.toLocaleString('id-ID');
                        document.getElementById('usageCompTokensVal').textContent = compTok.toLocaleString('id-ID');

                        const costUsd = parseFloat(usage.cost) || 0;
                        let kreditUsed = costUsd > 0 ? (costUsd * 1023.47) : 0.2;
                        if (kreditUsed < 0.2) kreditUsed = 0.2;
                        const costIdr = kreditUsed * 10;
                        let costText = `Rp ${Math.round(costIdr).toLocaleString('id-ID')}`;
                        document.getElementById('usageCostBadge').textContent = costText;
                        usageStrip.style.display = 'flex';
                    } else if (usageStrip) {
                        usageStrip.style.display = 'none';
                    }

                    renderPdfItemsTable();
                    queueSectionPdf.style.display = 'block';

                    Swal.fire({
                        icon: 'success',
                        title: 'Ekstraksi AI Berhasil!',
                        text: `Ditemukan ${currentExtractedItems.length} baris barang dari berkas ${data.original_name}. Anda dapat mengoreksi data atau langsung mengunduh Excel CEISA 4.0.`
                    });
                } else {
                    let failHtml = `<div style="font-size:13px; color:#475569; margin-bottom:12px; line-height:1.5;">${escapeHtml(data.message || 'AI tidak berhasil mengekstrak data dari berkas PDF ini.')}</div>`;
                    
                    const usage = data ? data.usage : null;
                    if (usage && (usage.total_tokens || usage.cost)) {
                        const totalTok = (usage.total_tokens || 0).toLocaleString('id-ID');
                        const promptTok = (usage.prompt_tokens || 0).toLocaleString('id-ID');
                        const compTok = (usage.completion_tokens || 0).toLocaleString('id-ID');
                        const costUsd = parseFloat(usage.cost) || 0;
                        let kreditUsed = costUsd > 0 ? (costUsd * 1023.47) : 0.2;
                        if (kreditUsed < 0.2) kreditUsed = 0.2;
                        const costIdr = kreditUsed * 10;
                        let costStr = `Rp ${Math.round(costIdr).toLocaleString('id-ID')}`;
                        const usedModel = data.model_used || currentSelectedModel;
                        const usedObj = AI_MODELS.find(m => m.id === usedModel);
                        const modelTitle = usedObj ? `${usedObj.name} (${usedObj.id})` : usedModel;

                        failHtml += `
                            <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:12px 14px; text-align:left; font-size:11.5px; color:#991b1b; margin-top:10px;">
                                <div style="font-weight:700; margin-bottom:6px; display:flex; align-items:center; gap:6px; color:#b91c1c;">
                                    <span>⚠️</span> <span>Penggunaan Biaya Model:</span>
                                </div>
                                <div style="color:#7f1d1d; margin-bottom:3px;">• <b>Model:</b> <span style="font-family:'JetBrains Mono', monospace; font-weight:700;">${escapeHtml(modelTitle)}</span></div>
                                <div style="color:#7f1d1d; margin-bottom:3px;">• <b>Token:</b> <span style="font-family:'JetBrains Mono', monospace; font-weight:700;">${totalTok}</span> (Prompt: ${promptTok} &bull; Completion: ${compTok})</div>
                                <div style="color:#7f1d1d;">• <b>Estimasi Biaya:</b> <span style="background:#fee2e2; border:1px solid #fca5a5; padding:1px 6px; border-radius:4px; font-family:'JetBrains Mono', monospace; font-weight:700; color:#991b1b;">${costStr}</span></div>
                            </div>
                        `;
                    }

                    Swal.fire({
                        icon: 'error',
                        title: 'Ekstraksi Gagal',
                        html: failHtml
                    });
                }
            })
            .catch(err => {
                Swal.close();
                Swal.fire({ icon: 'error', title: 'Koneksi Gagal', text: err.message });
            });
        }

        function renderPdfItemsTable() {
            pdfResultTableBody.innerHTML = '';
            if (!currentExtractedItems.length) return;

            currentExtractedItems.forEach((it, idx) => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td style="color:#94a3b8; font-family:'JetBrains Mono', monospace;">${idx + 1}</td>
                    <td>
                        <input type="text" class="form-input-sm editable-cell" style="width:100%; font-weight:700;" 
                               value="${escapeHtml(it.kode_barang || '')}" onchange="updateItemField(${idx}, 'kode_barang', this.value)">
                    </td>
                    <td>
                        <input type="text" class="form-input-sm editable-cell" style="width:100%;" 
                               value="${escapeHtml(it.nama_barang || '')}" onchange="updateItemField(${idx}, 'nama_barang', this.value)">
                    </td>
                    <td>
                        <input type="text" class="form-input-sm editable-cell" style="width:100%; font-family:'JetBrains Mono', monospace;" 
                               value="${escapeHtml(it.hs_code || '')}" onchange="updateItemField(${idx}, 'hs_code', this.value)">
                    </td>
                    <td>
                        <input type="number" step="any" class="form-input-sm editable-cell" style="width:100%; text-align:right;" 
                               value="${it.qty || 0}" onchange="updateItemField(${idx}, 'qty', this.value)">
                    </td>
                    <td>
                        <input type="text" class="form-input-sm editable-cell" style="width:100%; text-transform:uppercase;" 
                               value="${escapeHtml(it.satuan || 'PCS')}" onchange="updateItemField(${idx}, 'satuan', this.value)">
                    </td>
                    <td>
                        <input type="number" step="any" class="form-input-sm editable-cell" style="width:100%; text-align:right;" 
                               value="${it.harga || 0}" onchange="updateItemField(${idx}, 'harga', this.value)">
                    </td>
                    <td>
                        <input type="number" step="any" class="form-input-sm editable-cell" style="width:100%; text-align:right; font-weight:700;" 
                               value="${it.total || 0}" onchange="updateItemField(${idx}, 'total', this.value)">
                    </td>
                `;
                pdfResultTableBody.appendChild(tr);
            });
        }

        function updateItemField(idx, field, val) {
            if (!currentExtractedItems[idx]) return;
            if (field === 'qty' || field === 'harga' || field === 'total') {
                currentExtractedItems[idx][field] = parseFloat(val) || 0;
                if (field === 'qty' || field === 'harga') {
                    currentExtractedItems[idx]['total'] = Math.round((currentExtractedItems[idx]['qty'] * currentExtractedItems[idx]['harga']) * 10000) / 10000;
                    renderPdfItemsTable();
                }
            } else {
                currentExtractedItems[idx][field] = val;
            }
            syncCustomPdfExcel();
        }

        function syncCustomPdfExcel() {
            // Re-generate Excel via API saat data di tabel diedit
            const fd = new FormData();
            fd.append('batch_id', activePdfBatchId || '');
            fd.append('items', JSON.stringify(currentExtractedItems));

            fetch('/tools/api.php?action=generate_pdf_excel', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d && d.status === 'SUCCESS') {
                        btnDownloadPdfExcel.href = d.download_url;
                    }
                }).catch(() => {});
        }

        function copyExtractedTable() {
            if (!currentExtractedItems.length) {
                Swal.fire({ icon: 'info', title: 'Belum Ada Data' });
                return;
            }
            const headers = ['Kode Barang', 'Uraian Barang', 'HS Code', 'Qty', 'Satuan', 'Harga Satuan', 'Total Nilai'];
            const lines = [headers.join('\t')];
            currentExtractedItems.forEach(it => {
                lines.push([
                    it.kode_barang || '',
                    it.nama_barang || '',
                    it.hs_code || '',
                    it.qty || 0,
                    it.satuan || '',
                    it.harga || 0,
                    it.total || 0
                ].join('\t'));
            });

            navigator.clipboard.writeText(lines.join('\n')).then(() => {
                Swal.fire({
                    icon: 'success',
                    title: 'Tabel Disalin!',
                    text: 'Data tabel berhasil disalin ke clipboard dalam format tabulasi. Anda bisa langsung paste di Excel atau sheet lain.'
                });
            });
        }

        function resetPdfExtractor() {
            if (activePdfBatchId) {
                const fd = new FormData();
                fd.append('batch', activePdfBatchId);
                fetch('/tools/api.php?action=cleanup', { method: 'POST', body: fd }).catch(() => {});
            }
            currentExtractedItems = [];
            activePdfBatchId = null;
            queueSectionPdf.style.display = 'none';
            fileInputPdf.value = '';
            pdfFileNameBadge.textContent = '-';
            pdfItemsCountBadge.textContent = '0 item';
            const usageStrip = document.getElementById('aiUsageStrip');
            if (usageStrip) usageStrip.style.display = 'none';
            const ocrBadge = document.getElementById('pdfOcrBadge');
            const ocrNotice = document.getElementById('ocrNotice');
            if (ocrBadge) ocrBadge.style.display = 'none';
            if (ocrNotice) ocrNotice.style.display = 'none';
        }

        function fetchAiBalance(showToast = false) {
            fetch('/tools/api.php?action=get_balance')
                .then(r => r.json())
                .then(d => {
                    if (d && d.status === 'SUCCESS' && d.data) {
                        updateAiBalanceDisplay(d.data);
                        if (showToast) {
                            const credits = parseFloat(d.data.balance_credits) || 0;
                            const rp = parseFloat(d.data.balance_rp) || (credits * 10);
                            Swal.fire({
                                icon: 'success',
                                title: 'Saldo Terkini Berhasil Disinkronkan!',
                                text: `Saldo AI: Rp ${Math.round(rp).toLocaleString('id-ID')}`,
                                timer: 2000,
                                showConfirmButton: false
                            });
                        }
                    }
                }).catch(() => {});
        }

        function updateAiBalanceDisplay(bal) {
            if (!bal) return;
            const elCredits = document.getElementById('aiBalanceCreditsVal');
            const elRp = document.getElementById('aiBalanceRpVal');
            const elReq = document.getElementById('aiTotalRequestsVal');
            const elUsed = document.getElementById('aiCreditsUsedVal');
            const elBadge = document.getElementById('aiLiveSyncBadge');

            const credits = parseFloat(bal.balance_credits) || 0;
            const rp = parseFloat(bal.balance_rp) || (credits * 10);
            const totalReq = parseInt(bal.total_requests) || 0;
            const used = parseFloat(bal.credits_used_total) || 0;
            const usedRp = Math.round(used * 10);

            if (elCredits) elCredits.textContent = credits.toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            if (elRp) elRp.textContent = `Rp ${Math.round(rp).toLocaleString('id-ID')}`;
            if (elReq) elReq.textContent = totalReq.toLocaleString('id-ID');
            if (elUsed) elUsed.textContent = `Rp ${usedRp.toLocaleString('id-ID')}`;

            if (elBadge) {
                if (bal.source === 'LIVE_UPSTREAM') {
                    elBadge.innerHTML = '🟢 Live Sync';
                    elBadge.style.color = '#38bdf8';
                    elBadge.style.borderColor = 'rgba(56,189,248,0.4)';
                } else {
                    elBadge.innerHTML = '⚡ Local Tracker';
                    elBadge.style.color = '#fbbf24';
                    elBadge.style.borderColor = 'rgba(251,191,36,0.4)';
                }
            }
        }

        function resetDemoBalance() {
            Swal.fire({
                title: 'Reset Saldo Demo?',
                text: 'Saldo kredit demo akan dikembalikan ke 988,62 kredit (≈ Rp 9.886,17). Lanjutkan?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#0284c7',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Ya, Reset',
                cancelButtonText: 'Batal'
            }).then((res) => {
                if (res.isConfirmed) {
                    fetch('/tools/api.php?action=reset_balance')
                        .then(r => r.json())
                        .then(d => {
                            if (d && d.status === 'SUCCESS' && d.data) {
                                updateAiBalanceDisplay(d.data);
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Saldo Direset',
                                    text: 'Saldo kredit demo berhasil dikembalikan ke 988,62 kredit.',
                                    timer: 2000,
                                    showConfirmButton: false
                                });
                            }
                        }).catch(e => {
                            Swal.fire({ icon: 'error', title: 'Gagal', text: e.message });
                        });
                }
            });
        }

        // Inisialisasi awal pemilih tier & model AI serta saldo kredit demo
        document.addEventListener('DOMContentLoaded', () => {
            initAiModelSelector();
            fetchAiBalance();
        });
    </script>
</body>
</html>

<?php
/**
 * DOKUMENTASI LENGKAP REST API CEISA 4.0
 * Halaman panduan teknis integrasi untuk Tim Developer, Pengguna & Publik.
 * Dapat diakses secara terbuka / publik langsung melalui URL root/subdomain.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dokumentasi REST API CEISA 4.0 - Panduan Integrasi Developer</title>
    <script>
        (function() {
            var theme = localStorage.getItem('ceisa_theme') || 'light';
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>
    <link rel="icon" type="image/png" href="/assets/logo.png">
    <link rel="shortcut icon" type="image/png" href="/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root, body[data-theme="light"], html[data-theme="light"] body {
            --bg-body: #f8fafc;
            --bg-sidebar: #ffffff;
            --bg-card: #ffffff;
            --border-color: #e2e8f0;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --method-get: #10b981;
            --method-post: #2563eb;
            --code-bg: #f1f5f9;
            --code-text: #0f172a;
            color-scheme: light;
        }
        body[data-theme="dark"], html[data-theme="dark"] body {
            --bg-body: #0b1120;
            --bg-sidebar: #0f172a;
            --bg-card: #0f172a;
            --border-color: #1e293b;
            --primary: #38bdf8;
            --primary-hover: #60a5fa;
            --text-main: #f1f5f9;
            --text-muted: #94a3b8;
            --method-get: #34d399;
            --method-post: #60a5fa;
            --code-bg: #030712;
            --code-text: #f8fafc;
            color-scheme: dark;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            background: var(--bg-body);
            color: var(--text-main);
            display: flex;
            height: 100vh;
            overflow: hidden;
            font-size: 10.5px;
            line-height: 1.5;
        }

        /* Universal Professional Typography Control */
        .content p, 
        .api-card p, 
        .doc-section p {
            font-size: 10px !important;
            line-height: 1.55 !important;
            color: var(--text-muted) !important;
        }
        .content h1, .doc-title {
            font-size: 14.5px !important;
            font-weight: 700 !important;
            letter-spacing: -0.2px !important;
            color: var(--text-main) !important;
        }
        .content h2 {
            font-size: 12.5px !important;
            font-weight: 600 !important;
            color: var(--text-main) !important;
            letter-spacing: -0.1px !important;
        }
        .content h3 {
            font-size: 11.5px !important;
            font-weight: 600 !important;
            color: var(--primary) !important;
            letter-spacing: -0.1px !important;
        }
        .content h4 {
            font-size: 10.5px !important;
            font-weight: 600 !important;
            color: var(--text-main) !important;
            margin: 12px 0 6px !important;
        }
        .content ul, .content ol {
            font-size: 10px !important;
            line-height: 1.55 !important;
            color: var(--text-muted) !important;
        }
        .content code {
            font-family: 'JetBrains Mono', 'SF Mono', 'Consolas', monospace !important;
            font-size: 9px !important;
            padding: 1px 4px !important;
            border-radius: 3px !important;
            background: rgba(0, 0, 0, 0.04) !important;
            border: 1px solid var(--border-color) !important;
            color: var(--primary) !important;
            font-weight: 500 !important;
        }
        body[data-theme="dark"] .content code {
            background: rgba(255, 255, 255, 0.08) !important;
        }

        /* Sidebar Navigation */
        .sidebar {
            width: 240px;
            background: var(--bg-sidebar);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        .sidebar-header {
            padding: 8px 12px;
            border-bottom: 1px solid var(--border-color);
        }
        .sidebar-header h2 {
            font-size: 11.5px;
            color: var(--primary);
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
            letter-spacing: -0.1px;
        }
        .sidebar-header p {
            font-size: 9px;
            color: var(--text-muted);
            margin-top: 2px;
        }
        .search-box {
            padding: 6px 10px;
            border-bottom: 1px solid var(--border-color);
        }
        .search-box input {
            width: 100%;
            background: var(--code-bg);
            border: 1px solid var(--border-color);
            padding: 3px 8px;
            border-radius: 4px;
            color: var(--text-main);
            font-size: 9.5px;
            height: 24px;
            outline: none;
            font-family: inherit;
            transition: border-color 0.2s;
        }
        .search-box input:focus { border-color: var(--primary); }
        .sidebar-menu {
            flex: 1;
            overflow-y: auto;
            padding: 6px 6px;
        }
        .menu-category {
            font-size: 8.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: var(--text-muted);
            padding: 6px 6px 2px;
        }
        .menu-item {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 3px 6px;
            border-radius: 4px;
            color: var(--text-main);
            text-decoration: none;
            font-size: 9.5px;
            margin-bottom: 1px;
            transition: all 0.15s;
        }
        .menu-item:hover, .menu-item.active {
            background: rgba(24, 144, 255, 0.08);
            color: var(--primary);
        }
        .method-badge {
            font-size: 8px;
            font-weight: 700;
            padding: 1px 4px;
            border-radius: 3px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .badge-get { background: rgba(16, 185, 129, 0.15); color: var(--method-get); }
        .badge-post { background: rgba(37, 99, 235, 0.15); color: var(--method-post); }

        /* Main Content */
        .content {
            flex: 1;
            overflow-y: auto;
            padding: 16px 24px;
        }
        .doc-section {
            max-width: 920px;
            margin: 0 auto 24px;
            scroll-margin-top: 16px;
        }
        .doc-title {
            font-size: 14.5px;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 4px;
        }
        .doc-subtitle {
            font-size: 10px;
            color: var(--text-muted);
            margin-bottom: 12px;
            line-height: 1.5;
        }
        .api-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 12px 16px;
            margin-bottom: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
        }
        .api-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 6px;
        }
        .endpoint-url {
            font-family: 'JetBrains Mono', 'SF Mono', 'Consolas', monospace;
            font-size: 10.5px;
            font-weight: 600;
            color: var(--primary);
        }
        .param-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5px !important;
            margin: 8px 0;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            overflow: hidden;
        }
        .param-table th, .param-table td {
            padding: 4px 8px !important;
            border-bottom: 1px solid var(--border-color);
            text-align: left;
        }
        .param-table th {
            background: var(--code-bg);
            color: var(--text-muted);
            font-size: 8.5px !important;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .code-block {
            background: var(--code-bg);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 8px 10px;
            margin: 8px 0;
            font-family: 'JetBrains Mono', 'SF Mono', 'Consolas', monospace;
            font-size: 9.5px !important;
            color: var(--code-text);
            overflow-x: auto;
            position: relative;
            line-height: 1.45;
        }
        .code-block pre {
            font-family: inherit !important;
            font-size: inherit !important;
            line-height: inherit !important;
            margin: 0;
            color: inherit;
        }
        .copy-btn {
            position: absolute;
            top: 5px;
            right: 5px;
            background: var(--bg-card);
            color: var(--text-muted);
            border: 1px solid var(--border-color);
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 8.5px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
        }
        .copy-btn:hover {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
        }
        .note-box {
            background: rgba(24, 144, 255, 0.04);
            border: 1px solid rgba(24, 144, 255, 0.15);
            border-left: 3px solid var(--primary);
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 10px !important;
            margin: 8px 0;
            line-height: 1.5;
        }

        /* Snippet Tabs & Switcher */
        .mode-toggle-bar {
            display: flex;
            align-items: center;
            gap: 6px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 5px 10px;
            margin: 10px 0 12px;
            flex-wrap: wrap;
        }
        .mode-btn {
            background: var(--bg-card);
            color: var(--text-muted);
            border: 1px solid var(--border-color);
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 9.5px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            height: 22px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-family: inherit;
        }
        .mode-btn:hover {
            background: rgba(24, 144, 255, 0.06);
            color: var(--text-main);
        }
        .mode-btn.active {
            background: #8b5cf6 !important;
            color: #ffffff !important;
            border-color: #8b5cf6 !important;
            box-shadow: 0 1px 4px rgba(139, 92, 246, 0.35);
        }
        .mode-btn.active.active-curl {
            background: #1890ff !important;
            color: #ffffff !important;
            border-color: #1890ff !important;
            box-shadow: 0 1px 4px rgba(24, 144, 255, 0.35);
        }
        .snippet-tabs {
            display: flex;
            gap: 2px;
            margin-top: 8px;
            margin-bottom: 0;
        }
        .snippet-tab-btn {
            background: var(--bg-card);
            color: var(--text-muted);
            border: 1px solid var(--border-color);
            border-bottom: none;
            padding: 2px 8px;
            border-radius: 4px 4px 0 0;
            font-size: 9px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
            height: 22px;
        }
        .snippet-tab-btn:hover {
            color: var(--text-main);
            background: rgba(24, 144, 255, 0.05);
        }
        .snippet-tab-btn.active {
            background: var(--code-bg);
            color: var(--primary);
            border-color: var(--border-color);
            border-bottom: 1px solid var(--code-bg);
            font-weight: 600;
        }
        .snippet-tab-btn.active.pb-tab {
            color: #8b5cf6;
        }
        .code-block.with-tabs {
            margin-top: 0;
            border-top-left-radius: 0;
        }
    </style>
</head>
<body data-theme="light">

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sidebar-header">
        <h2>⚡ API CEISA 4.0</h2>
        <p>Panduan Integrasi Developer</p>
    </div>
    <div class="search-box">
        <input type="text" id="searchInput" placeholder="Cari endpoint..." onkeyup="filterMenu()">
    </div>
    <div class="sidebar-menu" id="menuList">
        <div class="menu-category">Pengantar</div>
        <a href="#intro" class="menu-item active">Konvensi &amp; Multi-Company</a>
        <a href="#pb-guide" class="menu-item"><span class="method-badge badge-get" style="background:#8b5cf6; color:#fff;">PB</span> Panduan PowerBuilder</a>
        <a href="#ep-portal-catalog" class="menu-item"><span class="method-badge badge-get" style="background:#06b6d4; color:#fff;">CATALOG</span> 5 Layanan API Portal</a>

        <div class="menu-category">Portal & Editor Web</div>
        <a href="#ep-portal" class="menu-item"><span class="method-badge badge-get" style="background:#f59e0b; color:#000;">PORTAL</span> index.php</a>
        <a href="#ep-transfer" class="menu-item"><span class="method-badge badge-get" style="background:#8b5cf6;">UI</span> transfer.php</a>
        <a href="#ep-editor" class="menu-item"><span class="method-badge badge-get" style="background:#0ea5e9;">UI</span> editor.php</a>
        <a href="#ep-reff" class="menu-item"><span class="method-badge badge-get" style="background:#10b981; color:#fff;">MASTER</span> reff.php</a>
        <div class="menu-category">Modul Manifes (BC 1.1)</div>
        <a href="#ep-manifest" class="menu-item"><span class="method-badge badge-get" style="background:#06b6d4; color:#fff;">UI</span> manifest.php &middot; UI Manifes</a>
        <a href="#ep-manifest-api" class="menu-item"><span class="method-badge badge-post">API</span> manifest_api.php (Daftar Endpoint)</a>
        <a href="#ep-manifest-restore" class="menu-item"><span class="method-badge badge-post" style="background:#10b981; color:#fff;">⭐ RESTORE</span> upload_portal_restore (Auto Excel)</a>
        <a href="#ep-manifest-export" class="menu-item"><span class="method-badge badge-get" style="background:#10b981; color:#fff;">XLSX</span> export_excel (7 Sheet)</a>
        <a href="#ep-manifest-sync" class="menu-item"><span class="method-badge badge-post" style="background:#0ea5e9; color:#fff;">SYNC</span> sync_ceisa_portal</a>
        <a href="#ep-manifest-pdf" class="menu-item"><span class="method-badge badge-get" style="background:#ef4444; color:#fff;">PDF</span> pdf_manifest.php (Cetak PDF)</a>
        <a href="#ep-manifest-h2h" class="menu-item"><span class="method-badge badge-post" style="background:#8b5cf6; color:#fff;">H2H</span> api_draft &amp; api_kirim</a>
        <a href="#ep-getbl" class="menu-item"><span class="method-badge badge-get" style="background:#7c3aed; color:#fff;">PB</span> getbl.php (Inkuiri BC 1.1)</a>

        <div class="menu-category">Posting & Respon Dokumen</div>
        <a href="#ep-genjson" class="menu-item"><span class="method-badge badge-get">GET</span> genjson.php</a>
        <a href="#ep-posting" class="menu-item"><span class="method-badge badge-get">GET</span> posting.php</a>
        <a href="#ep-perbaikan" class="menu-item"><span class="method-badge badge-get">GET</span> perbaikan.php</a>
        <a href="#ep-statusaju" class="menu-item"><span class="method-badge badge-get">GET</span> statusaju.php</a>
        <a href="#ep-status" class="menu-item"><span class="method-badge badge-get">GET</span> status.php</a>
        <a href="#ep-pdf" class="menu-item"><span class="method-badge badge-get">GET</span> pdf.php</a>
        <a href="#ep-upload-dokap" class="menu-item"><span class="method-badge badge-post" style="background:#ec4899; color:#fff;">FILE</span> upload_dokap.php</a>
        <a href="#ep-schema-bc20" class="menu-item"><span class="method-badge badge-get" style="background:#3b82f6; color:#fff;">SCHEMA</span> Skema BC 2.0 (PIB)</a>
        <a href="#ep-rest-codes" class="menu-item"><span class="method-badge badge-get" style="background:#10b981; color:#fff;">CODES</span> Response &amp; Error Codes</a>

        <div class="menu-category">Import Excel CEISA</div>
        <a href="#ep-import-excel" class="menu-item"><span class="method-badge badge-post">POST</span> api_import_excel.php</a>

        <div class="menu-category">Master Data & Referensi</div>
        <a href="#ep-kurs" class="menu-item"><span class="method-badge badge-get">GET</span> kurs.php</a>
        <a href="#ep-tarif" class="menu-item"><span class="method-badge badge-get">GET</span> tarif.php</a>
        <a href="#ep-nib" class="menu-item"><span class="method-badge badge-get">GET</span> nib.php</a>
        <a href="#ep-skep" class="menu-item"><span class="method-badge badge-get">GET</span> skep.php</a>
        <a href="#ep-getbc11" class="menu-item"><span class="method-badge badge-get">GET</span> getbc11.php</a>
        <a href="#ep-getbl" class="menu-item"><span class="method-badge badge-get" style="background:#7c3aed; color:#fff;">PB</span> getbl.php (Inkuiri BC 1.1)</a>
        <a href="#ep-gettps" class="menu-item"><span class="method-badge badge-get">GET</span> gettps.php</a>
        <a href="#ep-cekaju" class="menu-item"><span class="method-badge badge-get">GET</span> cekaju.php</a>
    </div>
</div>

<!-- CONTENT -->
<div class="content">

    <!-- INTRO -->
    <div class="doc-section" id="intro">
        <h1 class="doc-title">Panduan Integrasi REST API CEISA 4.0</h1>
        <p class="doc-subtitle">
            Dokumentasi teknis untuk Tim Developer yang mengintegrasikan aplikasi internal, ERP, maupun sistem backend dengan layanan pertukaran data CEISA 4.0.
        </p>

        <div class="note-box">
            <strong>Standar Pemanggilan API:</strong><br>
            Semua endpoint API dapat diakses melalui protokol <code>HTTP/HTTPS</code> menggunakan metode standar (<code>GET</code> &amp; <code>POST</code>). Respon disediakan dalam format <code>JSON</code> atau teks terstruktur yang dapat diparsing oleh bahasa pemrograman apa pun (PowerBuilder, C#, Java, Python, PHP, Node.js, Go, dsb).
        </div>

        <!-- MODE TOGGLE BAR -->
        <div class="mode-toggle-bar">
            <span style="font-size:10px; color:var(--text-muted); font-weight:700;">BAHASA CONTOH KODE:</span>
            <button id="btnCurlMode" class="mode-btn" onclick="setGlobalSnippetMode('curl')">🌐 cURL / Universal HTTP</button>
            <button id="btnPbMode" class="mode-btn active" onclick="setGlobalSnippetMode('pb')">⚡ PowerBuilder (PowerScript)</button>
            <span style="font-size:10px; color:var(--text-muted); margin-left:auto;">Pilihan otomatis tersimpan di browser &amp; beralih instan di semua endpoint</span>
        </div>

        <h3 style="font-size:13px; font-weight:600; margin: 14px 0 6px; color: var(--primary);">Aturan Multi-Company (Banyak Perusahaan dalam 1 Sistem)</h3>
        <p style="font-size:10.5px; color: var(--text-muted); line-height:1.5;">
            Sistem API ini mendukung multi-company secara otomatis. Kredensial akun CEISA 4.0 (username, password, SKEP, NIB) disimpan dalam tabel database <code>bc_ceisa_company</code>.<br>
            Untuk menentukan perusahaan yang sedang diproses, sertakan parameter <code>cmp_id</code> pada URL request:
        </p>
        <div class="code-block">
            <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// Contoh pemanggilan endpoint dengan cmp_id (NPWP / Kode Perusahaan):
GET /posting.php?nomor_aju=00002303006520261014297970&amp;cmp_id=010000000000000</pre>
        </div>
        <p style="font-size:10px; color: var(--text-muted); margin-top:6px;">
            <em>Tips: Jika parameter <code>cmp_id</code> tidak dikirimkan, API akan otomatis mendeteksi perusahaan dari Nomor AJU dokumen yang bersangkutan, atau menggunakan profil perusahaan aktif default.</em>
        </p>
    </div>

    <!-- PANDUAN INTEGRASI POWERBUILDER -->
    <div class="doc-section" id="pb-guide">
        <div class="api-card" style="border-left: 4px solid #8b5cf6;">
            <div class="api-header">
                <span class="method-badge badge-get" style="background:#8b5cf6; color:#fff;">POWERBUILDER GUIDE</span>
                <span class="endpoint-url" style="color:#a78bfa;">Panduan Integrasi PowerBuilder (PowerScript)</span>
            </div>
            <p style="font-size:10.5px; color: var(--text-muted); margin-bottom:10px; line-height:1.5;">
                Sistem REST API CEISA 4.0 dirancang <strong>100% kompatibel dan siap diintegrasikan langsung dengan aplikasi Desktop PowerBuilder</strong>, mulai dari versi <em>PowerBuilder Classic (PB 7, 8, 9, 10, 11, 12, 12.5, 12.6)</em> hingga <em>Appeon PowerBuilder Modern (PB 2017, 2019, 2021, 2022, 2025+)</em>. Developer dapat langsung menyalin kode PowerScript di bawah ini ke event Window, CommandButton, maupun DataWindow.
            </p>

            <div class="note-box" style="border-left-color: #8b5cf6; background: rgba(139, 92, 246, 0.08);">
                <strong>Standar Pengaturan Variabel Global Aplikasi PB:</strong><br>
                Definisikan variabel global di <em>Application Object</em> atau modul global PB:<br>
                <code>String gs_url = "http://192.168.1.100" // Base URL server CEISA API</code><br>
                <code>String gs_cmp_id = "010000000000000"  // NPWP 15/16 digit perusahaan aktif</code>
            </div>

            <h4 style="font-size:12px; font-weight:600; margin: 14px 0 6px; color:var(--text-main);">Metode 1: OLEObject MSXML2.ServerXMLHTTP (Universal &amp; Sangat Direkomendasikan)</h4>
            <p style="font-size:10.5px; color: var(--text-muted); margin-bottom:6px;">
                Metode ini bekerja di <strong>semua versi PowerBuilder</strong> (tanpa perlu library tambahan) dengan memanfaatkan runtime Windows MSXML Core Services. Sangat stabil, mendukung timeout kustom, dan aman dari freeze/hang pada koneksi jaringan lambat:
            </p>
            <div class="code-block">
                <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// ============================================================================
// CONTOH DASAR PEMANGGILAN API MENGGUNAKAN OLEOBJECT (POWERSCRIPT)
// ============================================================================
OLEObject lole_http
String ls_url, ls_response, ls_aju
Long ll_status
Integer li_ret

ls_aju = "00002303006520261014297970"
ls_url = gs_url + "/posting.php?nomor_aju=" + ls_aju + "&cmp_id=" + gs_cmp_id

TRY
    lole_http = CREATE OLEObject
    // Prioritas 1: ServerXMLHTTP 6.0 (Rekomendasi Utama)
    li_ret = lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP.6.0")
    IF li_ret <> 0 THEN
        // Prioritas 2: Fallback ke versi ServerXMLHTTP umum
        li_ret = lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP")
        IF li_ret <> 0 THEN
            // Prioritas 3: Fallback ke XMLHTTP Client
            lole_http.ConnectToNewObject("MSXML2.XMLHTTP")
        END IF
    END IF

    // Set Timeout: resolve(5s), connect(10s), send(30s), receive(60s)
    TRY
        lole_http.setTimeouts(5000, 10000, 30000, 60000)
    CATCH (runtimeerror er_to)
    END TRY

    // Buka koneksi HTTP (GET / POST)
    lole_http.Open("GET", ls_url, False)
    lole_http.setRequestHeader("Content-Type", "application/json; charset=utf-8")
    lole_http.setRequestHeader("Accept", "application/json")
    lole_http.Send()

    // Tangkap kode status HTTP dan teks respon
    ll_status = Long(lole_http.Status)
    ls_response = String(lole_http.ResponseText)

    IF ll_status = 200 AND Mid(Trim(ls_response), 1, 14) = '{"status":"OK"' THEN
        MessageBox("Sukses Transfer", "Dokumen " + ls_aju + " berhasil diproses!~r~nRespon:~r~n" + ls_response)
    ELSE
        MessageBox("Gagal Transfer", "Gagal memproses (HTTP " + String(ll_status) + "):~r~n" + ls_response)
    END IF

CATCH (runtimeerror er)
    MessageBox("Exception Jaringan", "Tidak dapat terhubung ke server:~r~n" + er.text)
FINALLY
    IF IsValid(lole_http) THEN
        lole_http.DisconnectObject()
        DESTROY lole_http
    END IF
END TRY</pre>
            </div>

            <h4 style="font-size:12px; font-weight:600; margin: 14px 0 6px; color:var(--text-main);">Metode 2: Native HTTPClient (Appeon PowerBuilder 2017 R2 / 2019 / 2022+)</h4>
            <p style="font-size:10.5px; color: var(--text-muted); margin-bottom:6px;">
                Bagi tim developer yang menggunakan versi modern Appeon PowerBuilder, tersedia class bawaan <code>HTTPClient</code> dan <code>JsonParser</code>:
            </p>
            <div class="code-block">
                <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// ============================================================================
// CONTOH NATIVE HTTPCLIENT (POWERBUILDER 2017 R2+)
// ============================================================================
HTTPClient lnv_http
Long ll_status
String ls_url, ls_response

ls_url = gs_url + "/statusaju.php?aju=00002303006520261014297970&cmp_id=" + gs_cmp_id

lnv_http = CREATE HTTPClient
lnv_http.SetRequestHeader("Content-Type", "application/json; charset=utf-8")
lnv_http.SetRequestHeader("Accept", "application/json")
lnv_http.Timeout = 60

ll_status = lnv_http.SendRequest("GET", ls_url)
IF ll_status = 1 AND lnv_http.GetResponseStatusCode() = 200 THEN
    lnv_http.GetResponseBody(ls_response)
    MessageBox("Status AJU", "Respon Server:~r~n" + ls_response)
ELSE
    MessageBox("Error HTTP", "Gagal komunikasi HTTP: " + String(lnv_http.GetResponseStatusCode()))
END IF

DESTROY lnv_http</pre>
            </div>

            <h4 style="font-size:12px; font-weight:600; margin: 14px 0 6px; color:var(--text-main);">Reusable Global Function: <code>gf_call_ceisa_api()</code></h4>
            <p style="font-size:10.5px; color: var(--text-muted); margin-bottom:6px;">
                Rekomendasi arsitektur terbaik adalah membungkus pemanggilan API ke dalam satu <strong>Global Function PowerBuilder</strong> agar dapat dipanggil dari mana pun dengan 1 baris kode:
            </p>
            <div class="code-block">
                <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// ============================================================================
// GLOBAL FUNCTION: gf_call_ceisa_api
// Arguments:
//   as_endpoint  (String) - contoh: "/posting.php" atau "/statusaju.php"
//   as_method    (String) - "GET" atau "POST"
//   as_params    (String) - Query string (contoh: "nomor_aju=...&cmp_id=...")
//   ref as_response (String) - Menerima output JSON / teks dari server
//   ref al_status   (Long)   - Menerima HTTP Status Code (200, 400, 500, dll)
// Returns: Integer (1 = Berhasil, -1 = Exception / Koneksi Gagal)
// ============================================================================
OLEObject lole_http
String ls_url
Integer li_ret = 1

TRY
    lole_http = CREATE OLEObject
    IF lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP.6.0") <> 0 THEN
        IF lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP") <> 0 THEN
            IF lole_http.ConnectToNewObject("MSXML2.XMLHTTP") <> 0 THEN
                as_response = "Gagal inisialisasi OLEObject MSXML2 di Windows"
                al_status = -1
                Return -1
            END IF
        END IF
    END IF

    // Susun URL Lengkap
    IF Upper(as_method) = "GET" AND Len(Trim(as_params)) > 0 THEN
        IF Pos(as_endpoint, "?") > 0 THEN
            ls_url = gs_url + as_endpoint + "&" + as_params
        ELSE
            ls_url = gs_url + as_endpoint + "?" + as_params
        END IF
    ELSE
        ls_url = gs_url + as_endpoint
    END IF

    // Timeout: 5s resolve, 10s connect, 30s send, 60s receive
    TRY
        lole_http.setTimeouts(5000, 10000, 30000, 60000)
    CATCH (runtimeerror er_to)
    END TRY

    lole_http.Open(Upper(as_method), ls_url, False)
    lole_http.setRequestHeader("Content-Type", "application/json; charset=utf-8")
    lole_http.setRequestHeader("Accept", "application/json")

    IF Upper(as_method) = "POST" THEN
        lole_http.Send(as_params)
    ELSE
        lole_http.Send()
    END IF

    al_status = Long(lole_http.Status)
    as_response = String(lole_http.ResponseText)

CATCH (runtimeerror er)
    al_status = -1
    as_response = "Exception: " + er.text
    li_ret = -1
FINALLY
    IF IsValid(lole_http) THEN
        lole_http.DisconnectObject()
        DESTROY lole_http
    END IF
END TRY

Return li_ret</pre>
            </div>
            <p style="font-size:12px; color: var(--text-muted); margin-top:6px;">
                <em>Contoh Pemanggilan di Event Tombol Window PowerBuilder:</em><br>
                <code>Long ll_st; String ls_res;</code><br>
                <code>IF gf_call_ceisa_api("/posting.php", "GET", "nomor_aju=" + ls_aju + "&amp;cmp_id=" + gs_cmp_id, ls_res, ll_st) = 1 AND ll_st = 200 THEN ...</code>
            </p>
        </div>
    </div>

    <!-- 5 LAYANAN UTAMA API PORTAL BEA CUKAI -->
    <div class="doc-section" id="ep-portal-catalog">
        <div class="api-card">
            <div class="api-header">
                <span class="method-badge badge-get" style="background:#06b6d4; color:#fff;">OFFICIAL APIS</span>
                <span class="endpoint-url">Katalog 5 Layanan API Resmi Portal Bea Cukai</span>
            </div>
            <p style="font-size:13px; color: var(--text-muted); margin-bottom:14px;">
                Berdasarkan katalog resmi <a href="https://openapi.beacukai.go.id/portal/apis" target="_blank" style="color:var(--primary); text-decoration:none;">Portal OpenAPI Bea Cukai</a>, terdapat 5 paket API Host-to-Host (H2H) dengan total <strong>150 endpoint route</strong> untuk seluruh operasional kepabeanan, cukai, manifes, dan logistik pelabuhan:
            </p>

            <table class="param-table">
                <thead>
                    <tr>
                        <th style="width:140px;">Nama Layanan</th>
                        <th style="width:60px;">Versi</th>
                        <th>Base Gateway URL</th>
                        <th style="width:80px; text-align:center;">Routes</th>
                        <th>Deskripsi &amp; Lingkup Layanan</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong><code>openapi-auth</code></strong></td>
                        <td>v1.0</td>
                        <td><code>https://apis-gw.beacukai.go.id/v1/openapi-auth</code></td>
                        <td style="text-align:center;"><span class="method-badge badge-get">2</span></td>
                        <td>Autentikasi Host to Host CEISA 4.0 (Login pengguna, penerbitan token JWT Bearer, dan perpanjangan token).</td>
                    </tr>
                    <tr>
                        <td><strong><code>openapi</code></strong></td>
                        <td>v2.0</td>
                        <td><code>https://apis-gw.beacukai.go.id/v2/openapi</code></td>
                        <td style="text-align:center;"><span class="method-badge badge-get">53</span></td>
                        <td>Layanan Dokumen Pabean (Kirim Dokumen BC, Cek Skema JSON, Status Dokumen, Unduh Respon PDF, Billing, Gate In/Out Mandiri, Barang Kiriman CN/PIBK, Upload Dokumen Pelengkap &amp; Gambar Barang).</td>
                    </tr>
                    <tr>
                        <td><strong><code>openapi-manifes</code></strong></td>
                        <td>v1.0</td>
                        <td><code>https://apis-gw.beacukai.go.id/v1/openapi-manifes</code></td>
                        <td style="text-align:center;"><span class="method-badge badge-get">17</span></td>
                        <td>Layanan Manifes Inward, Manifes Outward, dan NVOCC (Kirim Pos BC 1.1, Cek Kelengkapan, Draft NVOCC, Rekon Manifes, dan Unduh Respon PDF Manifes).</td>
                    </tr>
                    <tr>
                        <td><strong><code>openapi-tps</code></strong></td>
                        <td>v1.0</td>
                        <td><code>https://apis-gw.beacukai.go.id/v1/openapi-tpsonline</code></td>
                        <td style="text-align:center;"><span class="method-badge badge-get">62</span></td>
                        <td>Layanan TPS Online (Coarri/Codeco Kontainer/Kemasan, Gate In/Out Pelabuhan, SPPB/NPE Permit, Permohonan &amp; Respon PLP, SP3B, SPJM, Laporan YOR, Tracking TPS).</td>
                    </tr>
                    <tr>
                        <td><strong><code>openapi-cukai</code></strong></td>
                        <td>v1.0</td>
                        <td><code>https://apis-gw.beacukai.go.id/v1/openapi-cukai</code></td>
                        <td style="text-align:center;"><span class="method-badge badge-get">16</span></td>
                        <td>Layanan Cukai H2H (Registrasi Mesin Produksi Batang/Kemasan rokok/cukai, Data GPS Logistik, Batch Produksi, dan Referensi Status Kepemilikan Mesin).</td>
                    </tr>
                </tbody>
            </table>

            <div class="note-box" style="margin-top:14px;">
                <strong>Standar Autentikasi Gateway Bea Cukai:</strong><br>
                Seluruh pemanggilan endpoint di atas wajib menyertakan dua header resmi:<br>
                1. <code>Authorization: Bearer [TOKEN_JWT]</code> (Diperoleh dari <code>openapi-auth/user/login</code>).<br>
                2. <code>beacukai-api-key: [CLIENT_API_KEY]</code> (API Key resmi dari akun Portal Pengguna Jasa Bea Cukai).
            </div>
        </div>
    </div>
    <div class="doc-section" id="ep-genjson">
        <div class="api-card">
            <div class="api-header">
                <span class="method-badge badge-get">GET</span>
                <span class="endpoint-url">/genjson.php</span>
            </div>
            <p style="font-size:13px; color: var(--text-muted); margin-bottom:14px;">
                Membangun dokumen pabean menjadi payload JSON resmi CEISA 4.0 langsung dari tabel database MySQL (mendukung BC 1.6, BC 2.0, BC 2.3, BC 2.5, BC 2.6.1, BC 2.6.2, BC 2.7, BC 2.8, BC 3.0, BC 3.3, BC 3.3.1/P3BET, BC 4.0, BC 4.1, PPKEK 6.1.1, PPKEK 6.1.3). Hasilnya otomatis disimpan ke tabel <code>bc_ceisa_json</code> dan berkas <code>json.txt</code>.
            </p>

            <table class="param-table">
                <thead>
                    <tr><th>Parameter</th><th>Tipe</th><th>Wajib?</th><th>Keterangan</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>nomor_aju</code></td><td>string</td><td>Opsional</td><td>Nomor AJU dokumen (26 digit). Jika diisi, menyusun JSON langsung dari database riil.</td></tr>
                    <tr><td><code>cmp_id</code></td><td>string</td><td>Opsional</td><td>ID Perusahaan / NPWP (otomatis terdeteksi dari header jika tidak diisi).</td></tr>
                    <tr><td><code>json</code></td><td>integer</td><td>Opsional</td><td>Isi <code>1</code> untuk mengembalikan respon berformat JSON terstruktur.</td></tr>
                    <tr><td><code>s</code></td><td>string</td><td>Opsional</td><td>Random string pencegah cache HTTP.</td></tr>
                </tbody>
            </table>

            <h4 style="font-size:13px; margin: 16px 0 6px; color:var(--text-main);">Contoh Pemanggilan API:</h4>
            <div class="snippet-wrapper">
                <div class="snippet-tabs">
                    <button class="snippet-tab-btn active" data-mode="curl" onclick="setSnippetTab(this, 'curl')">🌐 cURL / HTTP</button>
                    <button class="snippet-tab-btn pb-tab" data-mode="pb" onclick="setSnippetTab(this, 'pb')">⚡ PowerBuilder (PowerScript)</button>
                </div>
                <div class="snippet-pane pane-curl active">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>curl -X GET "http://[server-ip]/genjson.php?nomor_aju=00002303006520261014297970&amp;cmp_id=010000000000000&amp;json=1"</pre>
                    </div>
                </div>
                <div class="snippet-pane pane-pb" style="display:none;">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// PowerBuilder (PowerScript) - Generate JSON AJU
OLEObject lole_http
String ls_url, ls_response
Long ll_status

ls_url = gs_url + "/genjson.php?nomor_aju=00002303006520261014297970&cmp_id=" + gs_cmp_id + "&json=1"

lole_http = CREATE OLEObject
IF lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP.6.0") <> 0 THEN
    lole_http.ConnectToNewObject("MSXML2.XMLHTTP")
END IF

lole_http.Open("GET", ls_url, False)
lole_http.setRequestHeader("Content-Type", "application/json; charset=utf-8")
lole_http.Send()

ll_status = Long(lole_http.Status)
ls_response = String(lole_http.ResponseText)

lole_http.DisconnectObject()
DESTROY lole_http

IF ll_status = 200 THEN
    MessageBox("Sukses", "JSON Dokumen Pabean Berhasil Dibuat!")
ELSE
    MessageBox("Error", "Gagal GenJSON (HTTP " + String(ll_status) + "):~r~n" + ls_response)
END IF</pre>
                    </div>
                </div>
            </div>
            <h4 style="font-size:13px; margin: 12px 0 6px; color:var(--text-main);">Contoh Respon (JSON):</h4>
            <div class="code-block">
                <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>{
  "status": "OK",
  "message": "File JSON berhasil disusun",
  "nomor_aju": "00002303006520261014297970",
  "kode_dokumen": "23",
  "file": "json.txt"
}</pre>
            </div>
        </div>
    </div>

    <!-- POSTING.PHP -->
    <div class="doc-section" id="ep-posting">
        <div class="api-card">
            <div class="api-header">
                <span class="method-badge badge-get">GET</span>
                <span class="endpoint-url">/posting.php</span>
            </div>
            <p style="font-size:13px; color: var(--text-muted); margin-bottom:14px;">
                Mengirimkan berkas dokumen pabean ke Host API CEISA 4.0. Jika parameter <code>nomor_aju</code> disertakan, backend otomatis menyusun JSON segar langsung dari database riil dan langsung mempostingnya.
            </p>

            <table class="param-table">
                <thead>
                    <tr><th>Parameter</th><th>Tipe</th><th>Wajib?</th><th>Keterangan</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>nomor_aju</code></td><td>string</td><td>Opsional</td><td>Nomor AJU dokumen (26 digit). Menyusun dan memposting langsung dokumen terkait.</td></tr>
                    <tr><td><code>cmp_id</code></td><td>string</td><td>Opsional</td><td>ID Perusahaan / NPWP pemilik dokumen.</td></tr>
                    <tr><td><code>s</code></td><td>string</td><td>Opsional</td><td>Random string pencegah cache HTTP (contoh: nomor acak).</td></tr>
                </tbody>
            </table>

            <h4 style="font-size:13px; margin: 16px 0 6px; color:var(--text-main);">Contoh Pemanggilan API:</h4>
            <div class="snippet-wrapper">
                <div class="snippet-tabs">
                    <button class="snippet-tab-btn active" data-mode="curl" onclick="setSnippetTab(this, 'curl')">🌐 cURL / HTTP</button>
                    <button class="snippet-tab-btn pb-tab" data-mode="pb" onclick="setSnippetTab(this, 'pb')">⚡ PowerBuilder (PowerScript)</button>
                </div>
                <div class="snippet-pane pane-curl active">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>curl -X GET "http://[server-ip]/posting.php?nomor_aju=00002303006520261014297970&amp;cmp_id=010000000000000"</pre>
                    </div>
                </div>
                <div class="snippet-pane pane-pb" style="display:none;">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// PowerBuilder (PowerScript) - Kirim Dokumen Pabean ke CEISA 4.0
OLEObject lole_http
String ls_url, ls_response, ls_aju
Long ll_status

ls_aju = "00002303006520261014297970"
ls_url = gs_url + "/posting.php?nomor_aju=" + ls_aju + "&cmp_id=" + gs_cmp_id

lole_http = CREATE OLEObject
IF lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP.6.0") <> 0 THEN
    lole_http.ConnectToNewObject("MSXML2.XMLHTTP")
END IF

lole_http.Open("GET", ls_url, False)
lole_http.setRequestHeader("Content-Type", "application/json; charset=utf-8")
lole_http.Send()

ll_status = Long(lole_http.Status)
ls_response = String(lole_http.ResponseText)

lole_http.DisconnectObject()
DESTROY lole_http

IF ll_status = 200 AND Mid(Trim(ls_response), 1, 14) = '{"status":"OK"' THEN
    MessageBox("Sukses", "Nomor AJU " + ls_aju + " berhasil dikirim ke CEISA 4.0!")
    // Update status di DataWindow
    dw_1.SetItem(dw_1.GetRow(), "posted", 1)
ELSE
    MessageBox("Gagal", "HTTP " + String(ll_status) + ":~r~n" + ls_response)
END IF</pre>
                    </div>
                </div>
            </div>
            <h4 style="font-size:13px; margin: 12px 0 6px; color:var(--text-main);">Contoh Respon (JSON):</h4>
            <div class="code-block">
                <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>{
  "status": "OK",
  "message": "Dokumen AJU berhasil dikirim ke CEISA 4.0",
  "nomor_aju": "00002303006520261014297970",
  "waktu_kirim": "2026-10-15 10:00:00"
}</pre>
            </div>
        </div>
    </div>

    <!-- TRANSFER.PHP -->
    <div class="doc-section" id="ep-transfer">
        <div class="api-card">
            <div class="api-header">
                <span class="method-badge badge-get" style="background:#8b5cf6; color:#fff;">UI / API</span>
                <span class="endpoint-url">/transfer.php</span>
            </div>
            <p style="font-size:10.5px; color: var(--text-muted); margin-bottom:10px; line-height:1.5;">
                Dashboard monitoring dan transfer interaktif dokumen pabean ke Host API CEISA 4.0. Dilengkapi kartu metrik KPI, filter multi-company, tabel data AJU interaktif, modal preview JSON segar langsung dari database riil, dan tombol eksekusi <strong>Transfer ke Ceisa 4.0</strong>.
            </p>

            <table class="param-table">
                <thead>
                    <tr><th>Parameter Query</th><th>Tipe</th><th>Wajib?</th><th>Keterangan</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>action</code></td><td>string</td><td>Opsional</td><td><code>list</code> (ambil daftar AJU JSON), <code>preview_json</code> (compile JSON), <code>transfer</code> (eksekusi kirim).</td></tr>
                    <tr><td><code>nomor_aju</code></td><td>string</td><td>Opsional</td><td>Nomor AJU (26 digit) saat aksi <code>preview_json</code> atau <code>transfer</code>.</td></tr>
                    <tr><td><code>cmp_id</code></td><td>string</td><td>Opsional</td><td>Filter berdasarkan kode perusahaan / NPWP.</td></tr>
                    <tr><td><code>search</code></td><td>string</td><td>Opsional</td><td>Kata kunci pencarian nomor AJU, nomor daftar, atau importir.</td></tr>
                </tbody>
            </table>

            <h4 style="font-size:12px; font-weight:600; margin: 12px 0 6px; color:var(--text-main);">Aksi AJAX API yang Didukung:</h4>
            <ul style="font-size:10.5px; color:var(--text-muted); margin-left:20px; line-height:1.5;">
                <li><code>GET /transfer.php?action=list&cmp_id={cmp_id}</code> - Mengembalikan ringkasan KPI dan array daftar AJU dokumen siap kirim dalam format JSON.</li>
                <li><code>GET /transfer.php?action=preview_json&nomor_aju={nomor_aju}</code> - Mengompilasi dan mengembalikan JSON payload CEISA 4.0 secara real-time dari database.</li>
                <li><code>POST /transfer.php?action=transfer</code> - Menjalankan pengiriman dokumen AJU ke gateway Bea Cukai CEISA 4.0 dan mencatat status ke riwayat.</li>
            </ul>

            <h4 style="font-size:13px; margin: 16px 0 6px; color:var(--text-main);">Contoh Pemanggilan API Transfer:</h4>
            <div class="snippet-wrapper">
                <div class="snippet-tabs">
                    <button class="snippet-tab-btn active" data-mode="curl" onclick="setSnippetTab(this, 'curl')">🌐 cURL / HTTP</button>
                    <button class="snippet-tab-btn pb-tab" data-mode="pb" onclick="setSnippetTab(this, 'pb')">⚡ PowerBuilder (PowerScript)</button>
                </div>
                <div class="snippet-pane pane-curl active">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>curl -X GET "http://[server-ip]/transfer.php?action=list&amp;cmp_id=010000000000000"</pre>
                    </div>
                </div>
                <div class="snippet-pane pane-pb" style="display:none;">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// PowerBuilder (PowerScript) - Ambil Daftar AJU Siap Kirim
OLEObject lole_http
String ls_url, ls_response
Long ll_status

ls_url = gs_url + "/transfer.php?action=list&cmp_id=" + gs_cmp_id

lole_http = CREATE OLEObject
IF lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP.6.0") <> 0 THEN
    lole_http.ConnectToNewObject("MSXML2.XMLHTTP")
END IF

lole_http.Open("GET", ls_url, False)
lole_http.Send()

ll_status = Long(lole_http.Status)
ls_response = String(lole_http.ResponseText)

lole_http.DisconnectObject()
DESTROY lole_http

MessageBox("Daftar AJU", "HTTP " + String(ll_status) + ":~r~n" + ls_response)</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- PORTAL INDEX.PHP -->
    <div class="doc-section" id="ep-portal">
        <div class="api-card">
            <div class="api-header">
                <span class="method-badge badge-get" style="background:#f59e0b; color:#000;">PORTAL DEDIKASI</span>
                <span class="endpoint-url">/index.php</span>
            </div>
            <p style="font-size:13px; color: var(--text-muted); margin-bottom:14px;">
                Dashboard & Portal Mandiri (Dedicated) CEISA 4.0 yang terpisah dari dashboard umum. Mendukung autentikasi berbasis peran:
            </p>
            <div class="note-box" style="margin-bottom:14px;">
                <strong>Perbedaan Hak Akses Role:</strong><br>
                1. <strong>User Perusahaan:</strong> Login menggunakan <code>cmp_id</code> / NPWP dan password perusahaan. Saat masuk, sesi terkunci permanen pada perusahaan tersebut tanpa opsi berpindah.<br>
                2. <strong>Administrator (<code>admin</code>):</strong> Login dengan akun <code>admin</code> (password default: <code>1234</code>). Administrator memiliki tombol khusus <strong>👑 ADMIN | Ganti Perusahaan</strong> di topbar yang membuka popup modal interaktif dengan fitur live search untuk langsung beralih ke perusahaan mana saja secara seketika <em>tanpa perlu login ulang</em>.
            </div>
            <table class="param-table">
                <thead>
                    <tr><th>Aksi (<code>action</code>)</th><th>Metode</th><th>Parameter</th><th>Keterangan</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>login</code></td><td>POST</td><td><code>cmp_id</code>, <code>password</code></td><td>Autentikasi akun (pilih <code>admin</code> untuk Super Admin atau <code>cmp_id</code> untuk User Perusahaan).</td></tr>
                    <tr><td><code>switch_company</code></td><td>POST</td><td><code>cmp_id</code></td><td>(Khusus Admin) Mengalihkan perusahaan aktif secara real-time tanpa re-login.</td></tr>
                    <tr><td><code>get_companies</code></td><td>GET</td><td>-</td><td>(Khusus Admin) Mengambil daftar seluruh perusahaan terdaftar untuk modal pencarian.</td></tr>
                    <tr><td><code>change_password</code></td><td>POST</td><td><code>old_password</code>, <code>new_password</code></td><td>Mengubah password akun aktif (tabel <code>bc_ceisa_login</code> untuk Admin, atau <code>bc_ceisa_company</code> untuk Perusahaan).</td></tr>
                    <tr><td><code>logout</code></td><td>GET</td><td>-</td><td>Menghancurkan sesi dan kembali ke layar login.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MANIFEST.PHP & MANIFEST_API.PHP OVERVIEW -->
    <div class="doc-section" id="ep-manifest">
        <div class="api-card">
            <div class="api-header">
                <span class="method-badge badge-get" style="background:#06b6d4; color:#fff;">UI / OPENAPI</span>
                <span class="endpoint-url">/manifest.php &middot; /manifest_api.php</span>
            </div>
            <p style="font-size:13px; color: var(--text-muted); margin-bottom:14px;">
                Modul Manifes Kedatangan Sarana Pengangkut (BC 1.1) / NVOCC terintegrasi langsung dengan Host-to-Host OpenAPI Portal Bea Cukai (Versi 1.0). Melayani siklus lengkap: <strong>Drafting NVOCC</strong>, <strong>Cek Kelengkapan</strong>, <strong>Kirim / Pindah Data</strong>, <strong>Rekonsiliasi (Pecah Pos)</strong>, <strong>Inquiry No/Tgl BC 1.1</strong>, hingga <strong>Otomasi Pembuatan Excel 7-Sheet &amp; Restore ke Portal CEISA 4.0</strong>.
            </p>

            <div class="note-box" style="margin-bottom:14px;">
                <strong>Arsitektur OpenAPI Manifes Bea Cukai:</strong><br>
                1. <strong>Payload Standar <code>JsonNvocc</code> &amp; Excel Generator:</strong> Disusun oleh builder engine <code>CeisaManifestBuilder.php</code> dan <code>CeisaManifestXlsxExporter.php</code> mencakup relasi Header &rarr; Kelompok Pos &rarr; Master BL &rarr; House BL (HBL) &rarr; BL HS, Kontainer Terangkut/Tertinggal, &amp; Dokumen Pendukung.<br>
                2. <strong>Multi-Company Isolated (<code>cmp_id</code>):</strong> Data tersimpan aman di 9 tabel database terdedikasi (<code>bc_ceisa_manifes_*</code>).<br>
                3. <strong>Otomasi One-Click Restore:</strong> Mengeliminasi input manual dengan membuat Excel dan mengirimkannya secara otomatis via cURL multipart form-data dengan autentikasi MFA Token.
            </div>

            <h4 style="font-size:13px; margin: 12px 0 6px; color:var(--text-main);" id="ep-manifest-api">Daftar Endpoint API Manifes yang Tersedia:</h4>
            <table class="param-table">
                <thead>
                    <tr><th>Aksi Internal (<code>action</code>)</th><th>Metode HTTP</th><th>Endpoint OpenAPI Bea Cukai</th><th>Keterangan</th></tr>
                </thead>
                <tbody>
                    <tr><td><a href="#ep-manifest-restore" style="color:#10b981; font-weight:700;"><code>upload_portal_restore</code></a></td><td><code>POST</code></td><td><code>/manifes-restore-dokumen/v1/tempNVOCC/UploadRestoreExcel</code></td><td>⭐ <strong>Otomasi 2-Step:</strong> Merakit file Excel format baru 7 sheet di memori dan langsung me-restore dokumen ke Portal CEISA 4.0 via cURL multipart.</td></tr>
                    <tr><td><a href="#ep-manifest-export" style="color:#10b981; font-weight:600;"><code>export_excel</code></a></td><td><code>GET</code></td><td>Internal Generator (<code>CeisaManifestXlsxExporter</code>)</td><td>Mengekspor data manifes lokal menjadi file Excel resmi (.xlsx 7 Sheet) berstandar CEISA 4.0 siap pakai.</td></tr>
                    <tr><td><a href="#ep-manifest-sync" style="color:#0ea5e9; font-weight:600;"><code>sync_ceisa_portal</code></a></td><td><code>POST</code></td><td><code>/v3/ManifesSchema/v1/DataProses/prosesNPWP-NVOCC/{npwp}</code></td><td>Menarik dan menyinkronkan seluruh dokumen NVOCC aktif di Portal CEISA 4.0 Bea Cukai secara live dengan autentikasi MFA Token.</td></tr>
                    <tr><td><code>get_detail_nvocc</code></td><td><code>GET</code></td><td><code>/v3/ManifesSchema/v1/TdNvoccHeader/GetOneNVOCCHeader/{idNvocc}</code></td><td>Mengambil rincian dokumen header, status pos, dan daftar Master B/L resmi dari server CEISA 4.0.</td></tr>
                    <tr><td><code>cek_rekon</code></td><td><code>GET / POST</code></td><td><code>/manifest_api.php?action=cek_rekon</code></td><td>Memeriksa status respon rekonsiliasi (rekon) NVOCC langsung dari Gateway Bea Cukai dan memperbarui database lokal.</td></tr>
                    <tr><td><a href="#ep-manifest-pdf" style="color:#ef4444; font-weight:600;"><code>pdf_manifest.php</code></a></td><td><code>GET</code></td><td><code>/report-manifes-v2/v1/jasper/get-file-nvocc/{idNvocc}</code></td><td>Mengunduh dan menampilkan dokumen PDF resmi dari Jasper Report Service Bea Cukai dengan fitur smart local caching.</td></tr>
                    <tr><td><code>get_list</code></td><td><code>GET</code></td><td><code>/manifest_api.php?action=get_list&amp;source={h2h|portal}</code></td><td>Mengambil daftar dokumen manifes lokal Host to Host (<code>source=h2h</code>) atau hasil sinkronisasi Portal (<code>source=portal</code>).</td></tr>
                    <tr><td><a href="#ep-manifest-h2h" style="color:#8b5cf6; font-weight:600;"><code>api_draft</code></a></td><td><code>POST</code></td><td><code>/manifes/nvocc/draft</code></td><td>Mengirim payload <code>JsonNvocc</code> draft baru ke CEISA 4.0 dan mendapatkan <code>idNvoccHeader</code>.</td></tr>
                    <tr><td><code>api_cek_lengkap</code></td><td><code>POST</code></td><td><code>/manifes/nvocc/cek-lengkap?nomorAju={nomorAju}</code></td><td>Mengecek kelengkapan dokumen manifes. Status otomatis berubah menjadi <strong>Ready</strong> jika valid.</td></tr>
                    <tr><td><code>api_kirim</code></td><td><code>POST</code></td><td><code>/manifes/nvocc/kirim</code></td><td>Mengirim data manifes yang sudah berstatus <em>Ready</em> ke Bea Cukai. Status berubah menjadi <strong>Terkirim</strong>.</td></tr>
                    <tr><td><code>api_rekon</code></td><td><code>POST</code></td><td><code>/manifes/nvocc/rekon</code></td><td>Melakukan rekonsiliasi / pecah pos antara manifes operator kapal dan NVOCC. Status berubah menjadi <strong>Rekon</strong>.</td></tr>
                    <tr><td><code>api_status</code></td><td><code>GET</code></td><td><code>/manifes/nvocc/status/{nomorAju}</code></td><td>Menarik riwayat dan status respon terakhir dari Bea Cukai.</td></tr>
                    <tr><td><code>api_bc11</code></td><td><code>GET</code></td><td><code>/manifes/nvocc/bc11?nomorAju={nomorAju}</code></td><td>Mendapatkan Nomor BC 1.1, Tanggal BC 1.1, dan Pos Manifes setelah proses rekon berhasil.</td></tr>
                    <tr><td><code>backup_portal_excel</code></td><td><code>GET</code></td><td><code>/v3/ManifesSchema/v1/tempNVOCC/ExcelNvocc</code></td><td>Mengunduh file backup Excel resmi langsung dari server CEISA 4.0.</td></tr>
                    <tr><td><code>get_portal_history</code></td><td><code>GET</code></td><td><code>/v3/ManifesSchema/v1/DataProses/HistoryManifes</code></td><td>Menarik rekam jejak riwayat resmi dokumen (Penerimaan, Perbaikan, Rekon, Respon Outward/Inward).</td></tr>
                    <tr><td><code>delete_manifes</code></td><td><code>DELETE</code></td><td><code>/manifes/nvocc/draft/{nomorAju}</code></td><td>Menghapus draft manifes dari gateway Bea Cukai dan database lokal.</td></tr>
                    <tr><td><code>api_inward</code></td><td><code>GET</code></td><td><code>/manifes/inward</code></td><td>Pencarian pos manifes kedatangan sarana pengangkut berdasarkan No BL, Tgl BL, dan Kode Kantor.</td></tr>
                    <tr><td><code>api_outward</code></td><td><code>GET</code></td><td><code>/manifes/outward</code></td><td>Pencarian pos manifes keberangkatan ekspor berdasarkan No BL, Tgl BL, dan Kode Kantor.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 1. FEATURE SPECIAL: UPLOAD PORTAL RESTORE (AUTO EXCEL + RESTORE) -->
    <div class="doc-section" id="ep-manifest-restore">
        <div class="api-card" style="border-left: 4px solid #10b981;">
            <div class="api-header">
                <span class="method-badge badge-post" style="background:#10b981; color:#fff;">POST</span>
                <span class="endpoint-url" style="color:#059669;">/manifest_api.php?action=upload_portal_restore</span>
            </div>
            <p style="font-size:13px; color: var(--text-main); font-weight:600; margin-bottom:4px;">
                ⭐ Otomasi Terpadu: Bentuk Excel 7-Sheet &amp; Restore ke Portal CEISA 4.0
            </p>
            <p style="font-size:11.5px; color: var(--text-muted); margin-bottom:12px; line-height:1.5;">
                API ini merupakan solusi <strong>All-in-One One-Click</strong> yang mengeksekusi dua tahapan secara atomik di sisi server tanpa intervensi manual:
            </p>

            <div style="background:rgba(16, 185, 129, 0.06); border:1px solid rgba(16, 185, 129, 0.25); border-radius:6px; padding:12px 14px; margin-bottom:14px; font-size:11px; line-height:1.6;">
                <strong>Mekanisme Otomatis 2-Step yang Dijalankan Server:</strong><br>
                1. <strong>Step 1 (Generate Excel 7-Sheet):</strong> Server membaca data manifes dari tabel database (<code>bc_ceisa_manifes_*</code>), lalu merakit berkas spreadsheet format baru resmi CEISA 4.0 (<code>NVOCC</code>, <code>MASTER</code>, <code>BL</code>, <code>BL HS</code>, <code>BL PETIKEMAS TERANGKUT</code>, <code>BL PETIKEMAS TERTINGGAL</code>, <code>BL DOKUMEN</code>) di memori/temporary server.<br>
                2. <strong>Step 2 (Auto-Restore ke Bea Cukai):</strong> Server mengambil token <em>Portal MFA CEISA 4.0</em> aktif milik perusahaan, lalu mengirimkan (cURL multipart form-data) berkas Excel tersebut ke microservice resmi Bea Cukai <code>/v3/ManifesSchema/v1/tempNVOCC/UploadRestoreExcel</code>. Setelah sukses, server langsung mengambil <code>idNvoccHeader</code> resmi yang terbit dan menyimpannya ke database lokal.
            </div>

            <h5 style="font-size:12px; font-weight:700; margin:10px 0 6px; color:var(--text-main);">Parameter Request (POST / x-www-form-urlencoded):</h5>
            <table class="param-table">
                <thead>
                    <tr><th>Parameter</th><th>Tipe Data</th><th>Wajib?</th><th>Keterangan</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>action</code></td><td>String</td><td>Ya</td><td>Harus bernilai <code>upload_portal_restore</code></td></tr>
                    <tr><td><code>nomor_aju</code></td><td>String (26 digit)</td><td>Ya</td><td>Nomor Aju Manifes yang akan di-restore (contoh: <code>07010047031520261019872416</code>)</td></tr>
                    <tr><td><code>cmp_id</code></td><td>String</td><td>Opsional</td><td>Kode/NPWP perusahaan (contoh: <code>TVL</code> atau <code>080167772504400</code>). Jika diabaikan, sistem otomatis mendeteksi dari Nomor AJU atau sesi aktif.</td></tr>
                </tbody>
            </table>

            <h5 style="font-size:12px; font-weight:700; margin:14px 0 6px; color:var(--text-main);">Struktur Respon JSON:</h5>
            <div class="code-block">
                <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// 1. Contoh Respon Sukses (HTTP 200):
{
  "status": "OK",
  "message": "Dokumen berhasil di-restore ke Portal CEISA 4.0!",
  "nomor_aju": "07010047031520261019872416",
  "id_nvocc_header": "219582",
  "cmp_id": "TVL",
  "http_code": 200
}

// 2. Contoh Respon Jika Dokumen Sudah Pernah Ada di Portal (Duplikat):
{
  "status": "OK",
  "message": "Dokumen sudah terdaftar di portal. idNvoccHeader berhasil disinkronkan.",
  "nomor_aju": "07010047031520261019872416",
  "id_nvocc_header": "219582",
  "is_duplicate": true
}

// 3. Contoh Respon Gagal (HTTP 200 / 400 / 500):
{
  "status": "ERROR",
  "message": "Gagal menghasilkan token Portal MFA CEISA 4.0 untuk perusahaan TVL. Pastikan kredensial login CEISA & Secret MFA tersimpan pada profil perusahaan."
}</pre>
            </div>

            <h4 style="font-size:13px; margin: 16px 0 6px; color:var(--text-main);">Contoh Pemanggilan upload_portal_restore:</h4>
            <div class="snippet-wrapper">
                <div class="snippet-tabs">
                    <button class="snippet-tab-btn active pb-tab" data-mode="pb" onclick="setSnippetTab(this, 'pb')">⚡ PowerBuilder (PowerScript)</button>
                    <button class="snippet-tab-btn" data-mode="curl" onclick="setSnippetTab(this, 'curl')">🌐 cURL / Universal HTTP</button>
                </div>
                <div class="snippet-pane pane-pb active">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// =========================================================================
// PowerScript (PowerBuilder): Otomasi Pembuatan Excel &amp; Restore ke Portal CEISA 4.0
// Nama Fungsi : gf_manifest_upload_portal_restore(as_nomor_aju, as_cmp_id)
// Parameter   : String as_nomor_aju (Nomor Aju 26 Digit, misal: "07010047031520261019872416")
//               String as_cmp_id    (Kode Perusahaan, misal: "TVL" atau "080167772504400")
// Return      : Boolean (TRUE = Berhasil Restore, FALSE = Gagal)
// =========================================================================
OLEObject lole_http
String ls_url, ls_post_data, ls_response
Long ll_status
Boolean lb_success = False

// 1. Validasi Parameter Awal
IF IsNull(as_nomor_aju) OR Trim(as_nomor_aju) = "" THEN
    MessageBox("Peringatan", "Nomor Aju Manifes tidak boleh kosong!", Exclamation!)
    RETURN False
END IF

IF IsNull(as_cmp_id) OR Trim(as_cmp_id) = "" THEN
    as_cmp_id = "TVL" // Default perusahaan jika kosong
END IF

// 2. Susun URL Endpoint &amp; Payload POST
ls_url = gs_url + "/manifest_api.php"
ls_post_data = "action=upload_portal_restore&amp;nomor_aju=" + Trim(as_nomor_aju) + "&amp;cmp_id=" + Trim(as_cmp_id)

// 3. Buat Object HTTP Client Windows (MSXML2 ServerXMLHTTP)
lole_http = CREATE OLEObject
IF lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP.6.0") &lt;&gt; 0 THEN
    IF lole_http.ConnectToNewObject("MSXML2.XMLHTTP") &lt;&gt; 0 THEN
        MessageBox("Error Sistem", "Komponen MSXML2.ServerXMLHTTP tidak ditemukan pada Windows ini.", StopSign!)
        DESTROY lole_http
        RETURN False
    END IF
END IF

// 4. Buka Koneksi POST dan Kirim Request
lole_http.Open("POST", ls_url, False)
lole_http.setRequestHeader("Content-Type", "application/x-www-form-urlencoded")
lole_http.setRequestHeader("Accept", "application/json")
lole_http.Send(ls_post_data)

// 5. Baca Status HTTP dan Respon Body
ll_status = Long(lole_http.Status)
ls_response = String(lole_http.ResponseText)

// 6. Bebaskan OLE Object dari Memori
lole_http.DisconnectObject()
DESTROY lole_http

// 7. Evaluasi Hasil Pengiriman
IF ll_status = 200 THEN
    IF Pos(ls_response, '"status":"OK"') &gt; 0 OR Pos(ls_response, '"status":true') &gt; 0 THEN
        MessageBox("Sukses Restore Portal", &amp;
            "Dokumen Manifes berhasil dibentuk menjadi Excel dan di-restore ke Portal CEISA 4.0!~r~n~r~n" + &amp;
            "Nomor AJU: " + as_nomor_aju + "~r~n" + &amp;
            "Respon Server:~r~n" + ls_response, Information!)
        lb_success = True
    ELSEIF Pos(ls_response, "sudah terdaftar") &gt; 0 OR Pos(ls_response, "duplikat") &gt; 0 THEN
        MessageBox("Informasi Duplikat", &amp;
            "Dokumen Manifes ini sudah pernah terdaftar di Portal CEISA 4.0.~r~n~r~n" + &amp;
            "Respon Server:~r~n" + ls_response, Exclamation!)
        lb_success = True
    ELSE
        MessageBox("Gagal Restore", &amp;
            "Server mengembalikan pesan kegagalan:~r~n~r~n" + ls_response, StopSign!)
        lb_success = False
    END IF
ELSE
    MessageBox("HTTP Error", &amp;
        "Koneksi gagal dengan HTTP Status: " + String(ll_status) + "~r~n~r~n" + ls_response, StopSign!)
    lb_success = False
END IF

RETURN lb_success</pre>
                    </div>
                </div>
                <div class="snippet-pane pane-curl" style="display:none;">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre># Eksekusi Otomasi Excel &amp; Restore Portal CEISA 4.0:
curl -X POST "http://[server-ip]/manifest_api.php" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "action=upload_portal_restore&amp;nomor_aju=07010047031520261019872416&amp;cmp_id=TVL"</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. EXPORT EXCEL 7-SHEET -->
    <div class="doc-section" id="ep-manifest-export">
        <div class="api-card" style="border-left: 4px solid #10b981;">
            <div class="api-header">
                <span class="method-badge badge-get">GET</span>
                <span class="endpoint-url">/manifest_api.php?action=export_excel&amp;nomor_aju={aju}&amp;cmp_id={cmp}</span>
            </div>
            <p style="font-size:12px; color: var(--text-main); font-weight:600; margin-bottom:4px;">
                Mengekspor &amp; Mengunduh Berkas Excel Resmi (.xlsx 7-Sheet)
            </p>
            <p style="font-size:11px; color: var(--text-muted); margin-bottom:12px;">
                Mengenerate dan mengalirkan langsung file Excel format baru resmi CEISA 4.0 yang terdiri dari 7 Sheet (<code>NVOCC</code>, <code>MASTER</code>, <code>BL</code>, <code>BL HS</code>, <code>BL PETIKEMAS TERANGKUT</code>, <code>BL PETIKEMAS TERTINGGAL</code>, <code>BL DOKUMEN</code>). Berkas ini identik dengan template yang diterima oleh portal CEISA 4.0.
            </p>

            <h4 style="font-size:12px; margin: 12px 0 6px; color:var(--text-main);">Contoh PowerBuilder (Download &amp; Simpan Berkas Biner .xlsx):</h4>
            <div class="snippet-wrapper">
                <div class="snippet-tabs">
                    <button class="snippet-tab-btn active pb-tab" data-mode="pb" onclick="setSnippetTab(this, 'pb')">⚡ PowerBuilder (PowerScript)</button>
                    <button class="snippet-tab-btn" data-mode="curl" onclick="setSnippetTab(this, 'curl')">🌐 cURL / HTTP</button>
                </div>
                <div class="snippet-pane pane-pb active">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// =========================================================================
// PowerScript: Download Berkas Excel Manifes CEISA 4.0 (.xlsx) ke Disk Lokal
// Function: gf_manifest_download_excel(as_nomor_aju, as_cmp_id, as_target_path)
// =========================================================================
OLEObject lole_http, lole_stream
String ls_url
Long ll_status

ls_url = gs_url + "/manifest_api.php?action=export_excel&amp;nomor_aju=" + Trim(as_nomor_aju) + "&amp;cmp_id=" + Trim(as_cmp_id)

lole_http = CREATE OLEObject
lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP.6.0")
lole_http.Open("GET", ls_url, False)
lole_http.Send()

ll_status = Long(lole_http.Status)
IF ll_status = 200 THEN
    // Tulis data biner ke file menggunakan ADODB.Stream
    lole_stream = CREATE OLEObject
    lole_stream.ConnectToNewObject("ADODB.Stream")
    lole_stream.Type = 1 // 1 = adTypeBinary
    lole_stream.Open()
    lole_stream.Write(lole_http.ResponseBody)
    lole_stream.SaveToFile(as_target_path, 2) // 2 = adSaveCreateOverWrite
    lole_stream.Close()
    DESTROY lole_stream

    MessageBox("Sukses", "Berkas Excel CEISA 4.0 berhasil diunduh ke:~r~n" + as_target_path, Information!)
ELSE
    MessageBox("Error", "Gagal mengunduh Excel. HTTP Status: " + String(ll_status), StopSign!)
END IF

lole_http.DisconnectObject()
DESTROY lole_http</pre>
                    </div>
                </div>
                <div class="snippet-pane pane-curl" style="display:none;">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre># Unduh file Excel resmi CEISA 4.0:
curl -X GET "http://[server-ip]/manifest_api.php?action=export_excel&amp;nomor_aju=07010047031520261019872416&amp;cmp_id=TVL" \
  -o "Manifest_CEISA4_NVOCC.xlsx"</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. SINKRONISASI PORTAL CEISA 4.0 -->
    <div class="doc-section" id="ep-manifest-sync">
        <div class="api-card" style="border-left: 4px solid #0ea5e9;">
            <div class="api-header">
                <span class="method-badge badge-post" style="background:#0ea5e9; color:#fff;">POST</span>
                <span class="endpoint-url">/manifest_api.php?action=sync_ceisa_portal</span>
            </div>
            <p style="font-size:12px; color: var(--text-main); font-weight:600; margin-bottom:4px;">
                Sinkronisasi Seluruh Dokumen NVOCC dari Portal CEISA 4.0 Bea Cukai
            </p>
            <p style="font-size:11px; color: var(--text-muted); margin-bottom:12px;">
                Menarik seluruh daftar dokumen NVOCC yang terdaftar di Portal CEISA 4.0 milik NPWP perusahaan, mencakup nomor aju, status proses, kelompok pos, no/tgl master BL, serta status rekon secara live menggunakan token MFA.
            </p>

            <h4 style="font-size:12px; margin: 12px 0 6px; color:var(--text-main);">Contoh PowerBuilder (Sinkronisasi Data):</h4>
            <div class="snippet-wrapper">
                <div class="snippet-tabs">
                    <button class="snippet-tab-btn active pb-tab" data-mode="pb" onclick="setSnippetTab(this, 'pb')">⚡ PowerBuilder (PowerScript)</button>
                    <button class="snippet-tab-btn" data-mode="curl" onclick="setSnippetTab(this, 'curl')">🌐 cURL / HTTP</button>
                </div>
                <div class="snippet-pane pane-pb active">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// =========================================================================
// PowerScript: Sinkronisasi Data Dokumen Portal CEISA 4.0
// Function: gf_manifest_sync_portal(as_cmp_id)
// =========================================================================
OLEObject lole_http
String ls_url, ls_payload, ls_response
Long ll_status

ls_url = gs_url + "/manifest_api.php"
ls_payload = "action=sync_ceisa_portal&amp;cmp_id=" + Trim(as_cmp_id)

lole_http = CREATE OLEObject
lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP.6.0")
lole_http.Open("POST", ls_url, False)
lole_http.setRequestHeader("Content-Type", "application/x-www-form-urlencoded")
lole_http.Send(ls_payload)

ll_status = Long(lole_http.Status)
ls_response = String(lole_http.ResponseText)

lole_http.DisconnectObject()
DESTROY lole_http

MessageBox("Sinkronisasi Portal CEISA 4.0", "HTTP " + String(ll_status) + ":~r~n" + ls_response)</pre>
                    </div>
                </div>
                <div class="snippet-pane pane-curl" style="display:none;">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre># Sinkronisasi Dokumen Portal:
curl -X POST "http://[server-ip]/manifest_api.php" \
  -d "action=sync_ceisa_portal&amp;cmp_id=TVL"</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 4. CETAK PDF RESMI JASPER REPORT -->
    <div class="doc-section" id="ep-manifest-pdf">
        <div class="api-card" style="border-left: 4px solid #ef4444;">
            <div class="api-header">
                <span class="method-badge badge-get" style="background:#ef4444; color:#fff;">GET</span>
                <span class="endpoint-url">/pdf_manifest.php?nomor_aju={aju}&amp;cmp_id={cmp}</span>
            </div>
            <p style="font-size:12px; color: var(--text-main); font-weight:600; margin-bottom:4px;">
                Mengunduh &amp; Menampilkan Dokumen PDF Resmi dari Jasper Report Bea Cukai
            </p>
            <p style="font-size:11px; color: var(--text-muted); margin-bottom:12px;">
                Mengunduh dokumen PDF resmi BC 1.1 yang diterbitkan oleh Jasper Report Service Bea Cukai (<code>/report-manifes-v2/v1/jasper/get-file-nvocc/{idNvocc}</code>) dengan fitur <em>smart local caching</em> (respon instan jika sudah pernah diunduh).
            </p>

            <h4 style="font-size:12px; margin: 12px 0 6px; color:var(--text-main);">Contoh PowerBuilder (Download &amp; Buka PDF Otomatis):</h4>
            <div class="snippet-wrapper">
                <div class="snippet-tabs">
                    <button class="snippet-tab-btn active pb-tab" data-mode="pb" onclick="setSnippetTab(this, 'pb')">⚡ PowerBuilder (PowerScript)</button>
                    <button class="snippet-tab-btn" data-mode="curl" onclick="setSnippetTab(this, 'curl')">🌐 cURL / HTTP</button>
                </div>
                <div class="snippet-pane pane-pb active">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// =========================================================================
// PowerScript: Download &amp; Buka File PDF Resmi Manifes
// Function: gf_manifest_view_pdf(as_nomor_aju, as_cmp_id, as_target_pdf)
// =========================================================================
OLEObject lole_http, lole_stream
String ls_url
Long ll_status

ls_url = gs_url + "/pdf_manifest.php?nomor_aju=" + Trim(as_nomor_aju) + "&amp;cmp_id=" + Trim(as_cmp_id)

lole_http = CREATE OLEObject
lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP.6.0")
lole_http.Open("GET", ls_url, False)
lole_http.Send()

ll_status = Long(lole_http.Status)
IF ll_status = 200 THEN
    lole_stream = CREATE OLEObject
    lole_stream.ConnectToNewObject("ADODB.Stream")
    lole_stream.Type = 1 // Binary
    lole_stream.Open()
    lole_stream.Write(lole_http.ResponseBody)
    lole_stream.SaveToFile(as_target_pdf, 2)
    lole_stream.Close()
    DESTROY lole_stream

    // Buka otomatis dengan viewer PDF default Windows
    Run("cmd /c start dummy " + as_target_pdf)
ELSE
    MessageBox("Error", "Gagal mengunduh dokumen PDF. HTTP " + String(ll_status), StopSign!)
END IF

lole_http.DisconnectObject()
DESTROY lole_http</pre>
                    </div>
                </div>
                <div class="snippet-pane pane-curl" style="display:none;">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre># Unduh PDF resmi:
curl -X GET "http://[server-ip]/pdf_manifest.php?nomor_aju=07010047031520261019872416&amp;cmp_id=TVL" \
  -o "manifes_bc11.pdf"</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 5. H2H LIFECYCLE: DRAFT, KIRIM, REKON -->
    <div class="doc-section" id="ep-manifest-h2h">
        <div class="api-card" style="border-left: 4px solid #8b5cf6;">
            <div class="api-header">
                <span class="method-badge badge-post" style="background:#8b5cf6; color:#fff;">POST</span>
                <span class="endpoint-url">/manifest_api.php?action=api_draft | api_kirim | api_rekon</span>
            </div>
            <p style="font-size:12px; color: var(--text-main); font-weight:600; margin-bottom:4px;">
                Transmisi Host to Host (H2H) Manifes via OpenAPI Gateway Bea Cukai
            </p>
            <p style="font-size:11px; color: var(--text-muted); margin-bottom:12px;">
                Digunakan untuk alur integrasi JSON standar: membuat draft (<code>api_draft</code>), validasi kelengkapan (<code>api_cek_lengkap</code>), kirim ke pabean (<code>api_kirim</code>), melakukan rekonsiliasi pecah pos (<code>api_rekon</code>), serta penarikan nomor &amp; tanggal BC 1.1 (<code>api_bc11</code>).
            </p>

            <h4 style="font-size:12px; margin: 12px 0 6px; color:var(--text-main);">Contoh PowerBuilder (Kirim / Eksekusi Transaksi H2H):</h4>
            <div class="snippet-wrapper">
                <div class="snippet-tabs">
                    <button class="snippet-tab-btn active pb-tab" data-mode="pb" onclick="setSnippetTab(this, 'pb')">⚡ PowerBuilder (PowerScript)</button>
                    <button class="snippet-tab-btn" data-mode="curl" onclick="setSnippetTab(this, 'curl')">🌐 cURL / HTTP</button>
                </div>
                <div class="snippet-pane pane-pb active">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// =========================================================================
// PowerScript: Eksekusi Transaksi Manifes H2H (Draft / Kirim / Rekon / BC11)
// Function: gf_manifest_execute_h2h(as_action, as_nomor_aju, as_cmp_id)
// Parameter as_action: "api_draft", "api_cek_lengkap", "api_kirim", "api_rekon", "api_bc11"
// =========================================================================
OLEObject lole_http
String ls_url, ls_payload, ls_response
Long ll_status

ls_url = gs_url + "/manifest_api.php"
ls_payload = "action=" + Trim(as_action) + "&amp;nomor_aju=" + Trim(as_nomor_aju) + "&amp;cmp_id=" + Trim(as_cmp_id)

lole_http = CREATE OLEObject
lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP.6.0")
lole_http.Open("POST", ls_url, False)
lole_http.setRequestHeader("Content-Type", "application/x-www-form-urlencoded")
lole_http.Send(ls_payload)

ll_status = Long(lole_http.Status)
ls_response = String(lole_http.ResponseText)

lole_http.DisconnectObject()
DESTROY lole_http

MessageBox("Respon Manifes H2H (" + as_action + ")", &amp;
    "HTTP " + String(ll_status) + ":~r~n" + ls_response)</pre>
                    </div>
                </div>
                <div class="snippet-pane pane-curl" style="display:none;">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre># 1. Kirim Draft Manifes:
curl -X POST "http://[server-ip]/manifest_api.php" \
  -d "action=api_draft&amp;nomor_aju=07010047031520261019872416&amp;cmp_id=TVL"

# 2. Kirim Final ke Bea Cukai:
curl -X POST "http://[server-ip]/manifest_api.php" \
  -d "action=api_kirim&amp;nomor_aju=07010047031520261019872416&amp;cmp_id=TVL"

# 3. Rekonsiliasi (Pecah Pos):
curl -X POST "http://[server-ip]/manifest_api.php" \
  -d "action=api_rekon&amp;nomor_aju=07010047031520261019872416&amp;cmp_id=TVL"</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- EDITOR.PHP -->
    <div class="doc-section" id="ep-editor">
        <div class="api-card">
            <div class="api-header">
                <span class="method-badge badge-get" style="background:#0ea5e9; color:#fff;">UI / API</span>
                <span class="endpoint-url">/editor.php</span>
            </div>
            <p style="font-size:13px; color: var(--text-muted); margin-bottom:14px;">
                Form Web Editor Dokumen Pabean 100% fungsional untuk meninjau dan mengedit data transaksi pabean secara komprehensif. Mendukung navigasi 14 tab entitas dokumen pabean secara lengkap, penyimpanan langsung ke tabel database riil tanpa tabel staging sementara, validasi format tanggal otomatis ISO, dan integrasi langsung ke proses transfer CEISA 4.0.
            </p>

            <div class="note-box" style="margin-bottom:14px;">
                <strong>Fitur Mutakhir Editor 2.0:</strong><br>
                1. <strong>Saring Kolom Dinamis (<code>bc_ceisa_varheader</code>):</strong> Kolom di Header &amp; Barang yang tidak relevan untuk jenis dokumen BC aktif (misal BC 23 vs 30 vs 33 vs 40 vs 25 vs 2.6.1 vs 2.6.2) disembunyikan otomatis untuk menjaga kerapian tampilan. Tersedia tombol toggle <code>👁️ Saring Kolom</code>.<br>
                2. <strong>Saring Tab Dokumen:</strong> Sheet tab yang tidak digunakan oleh jenis dokumen BC terkait otomatis disembunyikan (misal Kesiapan Barang hanya tampil di BC 30, Bahan Baku di BC 25/27/261/262, dsb) untuk mempermudah fokus kerja. Tersedia tombol toggle <code>📑 Saring Tab BC</code>.<br>
                3. <strong>Dropdown Referensi Terintegrasi (<code>bc_ceisa_reff</code>):</strong> Kolom seperti Cara Bayar, Kantor, Pelabuhan, TPS, Valuta, Incoterm, Satuan, dan Kemasan terhubung langsung ke dropdown &amp; autocomplete.<br>
                4. <strong>Dua Tema Resmi:</strong> Mendukung tema Dark dan Light yang sinkron dengan preferensi portal utama.
            </div>

            <h4 style="font-size:13px; margin: 12px 0 6px; color:var(--text-main);">14 Tab Tabel Pabean yang Didukung:</h4>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:8px; margin-bottom:14px; font-size:12px;">
                <div style="background:var(--code-bg); padding:8px; border-radius:4px; border:1px solid var(--border-color);">1. 📋 <strong>Header</strong> (<code>bc_ceisa_header</code>)</div>
                <div style="background:var(--code-bg); padding:8px; border-radius:4px; border:1px solid var(--border-color);">2. 📦 <strong>Barang</strong> (<code>bc_ceisa_barang</code>)</div>
                <div style="background:var(--code-bg); padding:8px; border-radius:4px; border:1px solid var(--border-color);">3. 🏢 <strong>Entitas</strong> (<code>bc_ceisa_entitas</code>)</div>
                <div style="background:var(--code-bg); padding:8px; border-radius:4px; border:1px solid var(--border-color);">4. 📑 <strong>Dokumen</strong> (<code>bc_ceisa_dokumen</code>)</div>
                <div style="background:var(--code-bg); padding:8px; border-radius:4px; border:1px solid var(--border-color);">5. 🔬 <strong>Bahan Baku</strong> (<code>bc_ceisa_bahanbaku</code>)</div>
                <div style="background:var(--code-bg); padding:8px; border-radius:4px; border:1px solid var(--border-color);">6. 🏷️ <strong>Barang Tarif</strong> (<code>bc_ceisa_barangtarif</code>)</div>
                <div style="background:var(--code-bg); padding:8px; border-radius:4px; border:1px solid var(--border-color);">7. 🧮 <strong>Bahan Baku Tarif</strong> (<code>bc_ceisa_bahanbakutarif</code>)</div>
                <div style="background:var(--code-bg); padding:8px; border-radius:4px; border:1px solid var(--border-color);">8. 🧳 <strong>Kemasan</strong> (<code>bc_ceisa_kemasan</code>)</div>
                <div style="background:var(--code-bg); padding:8px; border-radius:4px; border:1px solid var(--border-color);">9. 🚢 <strong>Pengangkut</strong> (<code>bc_ceisa_pengangkut</code>)</div>
                <div style="background:var(--code-bg); padding:8px; border-radius:4px; border:1px solid var(--border-color);">10. 🚛 <strong>Kontainer</strong> (<code>bc_ceisa_kontainer</code>)</div>
                <div style="background:var(--code-bg); padding:8px; border-radius:4px; border:1px solid var(--border-color);">11. 💰 <strong>Pungutan</strong> (<code>bc_ceisa_pungutan</code>)</div>
                <div style="background:var(--code-bg); padding:8px; border-radius:4px; border:1px solid var(--border-color);">12. 🏦 <strong>Bank Devisa</strong> (<code>bc_ceisa_bankdevisa</code>)</div>
                <div style="background:var(--code-bg); padding:8px; border-radius:4px; border:1px solid var(--border-color);">13. 🛡️ <strong>Jaminan</strong> (<code>bc_ceisa_jaminan</code>)</div>
                <div style="background:var(--code-bg); padding:8px; border-radius:4px; border:1px solid var(--border-color);">14. ⏱️ <strong>Kesiapan Barang</strong> (<code>bc_ceisa_kesiapanbarang</code>)</div>
            </div>

            <h4 style="font-size:12px; font-weight:600; margin: 14px 0 6px; color:var(--text-main);">Aksi AJAX API yang Didukung:</h4>
            <table class="param-table">
                <thead>
                    <tr><th>Aksi (<code>action</code>)</th><th>Metode</th><th>Parameter</th><th>Keterangan</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>load_all</code></td><td>GET</td><td><code>nomor_aju</code></td><td>Memuat seluruh data 14 tabel, visibilitas kolom varheader, dan opsi referensi dropdown.</td></tr>
                    <tr><td><code>search_reff</code></td><td>GET</td><td><code>reff</code>, <code>q</code></td><td>Pencarian cepat autocomplete referensi dropdown (misal Pelabuhan, Kantor, dll).</td></tr>
                    <tr><td><code>save_header</code></td><td>POST</td><td><code>nomor_aju</code>, <code>data[]</code></td><td>Menyimpan perubahan field header dokumen.</td></tr>
                    <tr><td><code>save_table</code></td><td>POST</td><td><code>nomor_aju</code>, <code>table</code>, <code>rows (JSON)</code></td><td>Menyimpan seluruh baris grid tabel secara batch dengan transaksi database.</td></tr>
                    <tr><td><code>save_kesiapanbarang</code></td><td>POST</td><td><code>nomor_aju</code>, <code>data[]</code></td><td>Menyimpan data kesiapan barang ekspor (BC 3.0).</td></tr>
                </tbody>
            </table>

            <h4 style="font-size:12px; font-weight:600; margin: 14px 0 6px; color:var(--text-main);">Contoh Pemanggilan API Editor:</h4>
            <div class="snippet-wrapper">
                <div class="snippet-tabs">
                    <button class="snippet-tab-btn active" data-mode="curl" onclick="setSnippetTab(this, 'curl')">🌐 cURL / HTTP</button>
                    <button class="snippet-tab-btn pb-tab" data-mode="pb" onclick="setSnippetTab(this, 'pb')">⚡ PowerBuilder (PowerScript)</button>
                </div>
                <div class="snippet-pane pane-curl active">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>curl -X GET "http://[server-ip]/editor.php?action=load_all&amp;nomor_aju=00002303006520261014297970"</pre>
                    </div>
                </div>
                <div class="snippet-pane pane-pb" style="display:none;">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// PowerBuilder (PowerScript) - Muat Seluruh Data Dokumen AJU
OLEObject lole_http
String ls_url, ls_response
Long ll_status

ls_url = gs_url + "/editor.php?action=load_all&nomor_aju=00002303006520261014297970"

lole_http = CREATE OLEObject
IF lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP.6.0") <> 0 THEN
    lole_http.ConnectToNewObject("MSXML2.XMLHTTP")
END IF

lole_http.Open("GET", ls_url, False)
lole_http.Send()

ll_status = Long(lole_http.Status)
ls_response = String(lole_http.ResponseText)

lole_http.DisconnectObject()
DESTROY lole_http

IF ll_status = 200 THEN
    MessageBox("Data AJU", "Data 14 Tabel Berhasil Dimuat (Panjang: " + String(Len(ls_response)) + " byte)")
END IF</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- REFF.PHP -->
    <div class="doc-section" id="ep-reff">
        <div class="api-card">
            <div class="api-header">
                <span class="method-badge badge-get" style="background:#10b981; color:#fff;">MASTER / API</span>
                <span class="endpoint-url">/reff.php</span>
            </div>
            <p style="font-size:10.5px; color: var(--text-muted); margin-bottom:10px; line-height:1.5;">
                Modul Manajemen Data Referensi Kepabeanan (<code>bc_ceisa_reff</code>). Berfungsi sebagai pusat master data dropdown yang digunakan oleh seluruh modul editor dan formulir CEISA 4.0. Dilengkapi fitur Tambah, Edit, Hapus, Pencarian, Paginasi, Export CSV/Excel beserta data isi, Template CSV/Excel kosong, dan Import langsung dari file Excel (<code>.xlsx</code>) atau CSV.
            </p>

            <h4 style="font-size:12px; font-weight:600; margin: 14px 0 6px; color:var(--text-main);">Aksi AJAX API yang Didukung:</h4>
            <table class="param-table">
                <thead>
                    <tr><th>Aksi (<code>action</code>)</th><th>Metode</th><th>Parameter</th><th>Keterangan</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>get_categories</code></td><td>GET</td><td>-</td><td>Mengembalikan daftar seluruh kategori referensi dan jumlah baris per kategori.</td></tr>
                    <tr><td><code>list</code></td><td>GET</td><td><code>reff</code>, <code>search</code>, <code>page</code>, <code>limit</code></td><td>Mengambil data referensi berhalaman dengan filter kategori dan pencarian kata kunci.</td></tr>
                    <tr><td><code>save</code></td><td>POST</td><td><code>is_edit</code>, <code>reff</code>, <code>grup</code>, <code>kode</code>, <code>teks</code>, <code>status</code></td><td>Menambah atau memperbarui baris data referensi.</td></tr>
                    <tr><td><code>delete</code></td><td>POST</td><td><code>reff</code>, <code>kode</code></td><td>Menghapus data referensi tertentu.</td></tr>
                    <tr><td><code>export</code></td><td>GET</td><td><code>reff</code> (opsional)</td><td>Export data referensi ke format file CSV dengan encoding UTF-8 BOM.</td></tr>
                    <tr><td><code>template</code></td><td>GET</td><td>-</td><td>Mengunduh template CSV siap isi untuk import data referensi massal.</td></tr>
                    <tr><td><code>import</code></td><td>POST</td><td><code>file_reff</code> (multipart)</td><td>Mengimpor data referensi dari file Excel (<code>.xlsx</code>) atau CSV langsung ke tabel <code>bc_ceisa_reff</code>.</td></tr>
                </tbody>
            </table>

            <h4 style="font-size:12px; font-weight:600; margin: 14px 0 6px; color:var(--text-main);">Contoh Pemanggilan API:</h4>
            <div class="snippet-wrapper">
                <div class="snippet-tabs">
                    <button class="snippet-tab-btn active" data-mode="curl" onclick="setSnippetTab(this, 'curl')">🌐 cURL / HTTP</button>
                    <button class="snippet-tab-btn pb-tab" data-mode="pb" onclick="setSnippetTab(this, 'pb')">⚡ PowerBuilder (PowerScript)</button>
                </div>
                <div class="snippet-pane pane-curl active">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre># 1. Ambil Opsi Dropdown (misal KANTOR, PELABUHAN, DOKUMEN, KEMASAN):
curl -X GET "http://[server-ip]/reff.php?action=get_options&amp;reff=KANTOR"

# 2. Ambil Daftar Kategori &amp; Counter:
curl -X GET "http://[server-ip]/reff.php?action=get_categories"</pre>
                    </div>
                </div>
                <div class="snippet-pane pane-pb" style="display:none;">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// PowerBuilder (PowerScript) - Ambil Opsi Referensi Dropdown
OLEObject lole_http
String ls_url, ls_response
Long ll_status

// Mengambil opsi referensi KANTOR (atau PELABUHAN, DOKUMEN, VALUTA, dll)
ls_url = gs_url + "/reff.php?action=get_options&reff=KANTOR"

lole_http = CREATE OLEObject
IF lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP.6.0") <> 0 THEN
    lole_http.ConnectToNewObject("MSXML2.XMLHTTP")
END IF

lole_http.Open("GET", ls_url, False)
lole_http.Send()

ll_status = Long(lole_http.Status)
ls_response = String(lole_http.ResponseText)

lole_http.DisconnectObject()
DESTROY lole_http

IF ll_status = 200 THEN
    MessageBox("Referensi", ls_response)
END IF</pre>
                    </div>
                </div>
            </div>

            <h4 style="font-size:12px; font-weight:600; margin: 16px 0 6px; color:var(--text-main);">Daftar 63 Kategori Master Referensi Resmi Portal Bea Cukai (Total: 96.930 Baris):</h4>
            <div style="max-height: 380px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 6px; margin-bottom: 15px;">
                <table class="param-table" style="margin-bottom:0;">
                    <thead style="position: sticky; top: 0; background: var(--bg-card); z-index: 2;">
                        <tr><th>No</th><th>Nama Kategori (<code>reff</code>)</th><th>Jumlah Data</th><th>Ruang Lingkup / Penggunaan</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>1</td><td><code>API</code></td><td>3</td><td>Tipe Angka Pengenal Importir (01: API-U, 02: API-P, 03: API-T)</td></tr>
                        <tr><td>2</td><td><code>ASAL BARANG</code></td><td>4</td><td>Referensi asal barang impor/ekspor</td></tr>
                        <tr><td>3</td><td><code>ASAL BARANG FTZ</code></td><td>5</td><td>Referensi asal perolehan barang di Kawasan Bebas (FTZ)</td></tr>
                        <tr><td>4</td><td><code>BANK</code></td><td>149</td><td>Daftar kode & nama bank devisa persepsi & bank operasional</td></tr>
                        <tr><td>5</td><td><code>BARANG KIRIMAN BADAN USAHA</code></td><td>2</td><td>Badan usaha penerima/pengirim barang kiriman (PJT, Pos)</td></tr>
                        <tr><td>6</td><td><code>BARANG KIRIMAN JENIS AJU</code></td><td>7</td><td>Jenis pengajuan barang kiriman (CN, PIBK, dll)</td></tr>
                        <tr><td>7</td><td><code>BARANG KIRIMAN JENIS AJU CN FTZ</code></td><td>3</td><td>Jenis aju Consignment Note keluar/masuk FTZ</td></tr>
                        <tr><td>8</td><td><code>BARANG KIRIMAN JENIS PIBK</code></td><td>6</td><td>Jenis dokumen Pemberitahuan Impor Barang Kiriman</td></tr>
                        <tr><td>9</td><td><code>BARANG KIRIMAN JENIS PIBK CN FTZ</code></td><td>6</td><td>Jenis dokumen PIBK Consignment Note FTZ</td></tr>
                        <tr><td>10</td><td><code>BARANG KIRIMAN KATEGORI</code></td><td>2</td><td>Kategori barang kiriman reguler / e-commerce</td></tr>
                        <tr><td>11</td><td><code>BARANG KIRIMAN KODE STATUS</code></td><td>51</td><td>Kode status tracking pergerakan & verifikasi barang kiriman</td></tr>
                        <tr><td>12</td><td><code>BARANG KIRIMAN KONDISI</code></td><td>2</td><td>Kondisi fisik barang kiriman (Baru / Bukan Baru)</td></tr>
                        <tr><td>13</td><td><code>CARA ANGKUT</code></td><td>12</td><td>Moda pengangkutan (Laut, Kereta Api, Jalan Raya, Udara, Multimoda, dll)</td></tr>
                        <tr><td>14</td><td><code>CARA BAYAR</code></td><td>17</td><td>Metode pembayaran kewajiban pabean dan devisa</td></tr>
                        <tr><td>15</td><td><code>CARA DAGANG</code></td><td>15</td><td>Pola transaksi perdagangan internasional</td></tr>
                        <tr><td>16</td><td><code>DAERAH ASAL</code></td><td>514</td><td>Kode kabupaten/kota daerah asal komoditas di seluruh Indonesia</td></tr>
                        <tr><td>17</td><td><code>DOKUMEN</code></td><td>316</td><td>Kode dokumen pelengkap pabean (Invoice, BL, COO, Form D, NIB, SNI, dll)</td></tr>
                        <tr><td>18</td><td><code>ENTITAS</code></td><td>16</td><td>Peran pemangku kepentingan (Importir, Eksportir, Pengangkut, PPJK, dll)</td></tr>
                        <tr><td>19</td><td><code>FASILITAS</code></td><td>76</td><td>Jenis fasilitas kepabeanan & perpajakan (KITE, TPB, KB, Pembebasan, dll)</td></tr>
                        <tr><td>20</td><td><code>FASILITAS TARIF</code></td><td>9</td><td>Skema preferensi tarif perdagangan bebas (ATIGA, ACFTA, IJPA, dll)</td></tr>
                        <tr><td>21</td><td><code>IJIN</code></td><td>204</td><td>Kode instansi penerbit perijinan tata niaga/lartas LNSW</td></tr>
                        <tr><td>22</td><td><code>INCOTERM</code></td><td>16</td><td>International Commercial Terms (FOB, CIF, CFR, EXW, FCA, DAP, DDP, dll)</td></tr>
                        <tr><td>23</td><td><code>JENIS EKSPOR</code></td><td>7</td><td>Klasifikasi jenis kegiatan ekspor</td></tr>
                        <tr><td>24</td><td><code>JENIS IDENTITAS</code></td><td>5</td><td>Jenis identitas entitas (NPWP 15/16 digit, NIK/KTP, Paspor, Lainnya)</td></tr>
                        <tr><td>25</td><td><code>JENIS JAMINAN</code></td><td>8</td><td>Bentuk jaminan kepabeanan (Tunai, Bank Garansi, Customs Bond, dll)</td></tr>
                        <tr><td>26</td><td><code>JENIS KEMASAN</code></td><td>382</td><td>Standar kode jenis kemasan internasional UN/ECE (CT, BX, PK, DR, dll)</td></tr>
                        <tr><td>27</td><td><code>JENIS KONTAINER</code></td><td>3</td><td>FCL (Full Container), LCL (Less Container), Empty Container</td></tr>
                        <tr><td>28</td><td><code>JENIS PENGANGKUTAN</code></td><td>6</td><td>Jenis sarana pengangkut laut, udara, darat, atau multimoda</td></tr>
                        <tr><td>29</td><td><code>JENIS PIB</code></td><td>2</td><td>Prosedur PIB Biasa (1) dan PIB Berkala (2)</td></tr>
                        <tr><td>30</td><td><code>JENIS PUNGUTAN</code></td><td>18</td><td>BM, BMT, PPN, PPh Pasal 22, Cukai, Denda, BMMTP, BMAD, dll</td></tr>
                        <tr><td>31</td><td><code>JENIS TANDA PENGAMAN</code></td><td>4</td><td>Segel kertas, kancing pabean, kawat timah, electronic seal (GPS)</td></tr>
                        <tr><td>32</td><td><code>JENIS TARIF</code></td><td>2</td><td>Advalorum (% persentase) dan Spesifik (nilai rupiah per satuan unit)</td></tr>
                        <tr><td>33</td><td><code>JENIS TPB</code></td><td>10</td><td>Kawasan Berikat, Gudang Berikat, Tempat Penimbunan Berikat Lainnya</td></tr>
                        <tr><td>34</td><td><code>JENIS TRANSAKSI DAGANG</code></td><td>10</td><td>Imbal dagang, konsinyasi, hibah, sewa guna usaha, perdagangan umum</td></tr>
                        <tr><td>35</td><td><code>JENIS VD</code></td><td>12</td><td>Voluntary Declaration (Royalti, Proceeds, Assist, Freight, Asuransi, dll)</td></tr>
                        <tr><td>36</td><td><code>KANTOR</code></td><td>171</td><td>Daftar seluruh Kantor Pelayanan Utama & Pengawasan Bea Cukai Indonesia</td></tr>
                        <tr><td>37</td><td><code>KATEGORI BARANG</code></td><td>33</td><td>Klasifikasi kategori komoditas barang kepabeanan</td></tr>
                        <tr><td>38</td><td><code>KATEGORI EKSPOR</code></td><td>35</td><td>Kategori operasional pengeluaran barang ekspor</td></tr>
                        <tr><td>39</td><td><code>KATEGORI KELUAR FTZ</code></td><td>13</td><td>Alasan dan dasar kategori pengeluaran barang dari Kawasan Bebas</td></tr>
                        <tr><td>40</td><td><code>KATEGORI KONSOLIDATOR</code></td><td>3</td><td>Jenis badan usaha penyelenggara konsolidasi kargo muatan</td></tr>
                        <tr><td>41</td><td><code>KATEGORI MASUK FTZ</code></td><td>6</td><td>Alasan dan kategori pemasukan barang ke Kawasan Bebas</td></tr>
                        <tr><td>42</td><td><code>KODE JENIS IMPOR</code></td><td>7</td><td>Untuk Dipakai, Sementara, Re-impor, Pelayanan Segera, Proyek Pemerintah</td></tr>
                        <tr><td>43</td><td><code>KODE LAPORAN INVENTORI</code></td><td>7</td><td>Kategori pelaporan sistem IT Inventory TPB / Kawasan Berikat</td></tr>
                        <tr><td>44</td><td><code>KOMODITI CUKAI</code></td><td>4</td><td>Kategori objek cukai (Hasil Tembakau, MMEA, EA, Minuman Berpemanis)</td></tr>
                        <tr><td>45</td><td><code>KONDISI BARANG</code></td><td>8</td><td>Kondisi fisik barang (Baru, Bekas, Reject, Rusak, dll)</td></tr>
                        <tr><td>46</td><td><code>LOKASI BAYAR</code></td><td>4</td><td>Kanal tempat pembayaran (Bank Devisa Persepsi, Pos, Kasir Kantor Pabean)</td></tr>
                        <tr><td>47</td><td><code>NEGARA</code></td><td>254</td><td>Kode ISO 3166-1 alpha-2 seluruh negara di dunia</td></tr>
                        <tr><td>48</td><td><code>PELABUHAN</code></td><td>91.821</td><td>Master data UN/LOCODE pelabuhan dan bandar udara seluruh dunia</td></tr>
                        <tr><td>49</td><td><code>RESPON</code></td><td>99</td><td>Daftar kode respon CEISA (Penjaluran merah/hijau/kuning, billing, SPPB, NPE)</td></tr>
                        <tr><td>50</td><td><code>SATUAN BARANG</code></td><td>1.544</td><td>Kode satuan ukuran kuantitas komoditas internasional standar UN/ECE</td></tr>
                        <tr><td>51</td><td><code>SPESIFIKASI KHUSUS</code></td><td>46</td><td>Kategori kelompok spesifikasi teknis barang lartas & komoditas tertentu</td></tr>
                        <tr><td>52</td><td><code>SPESIFIKASI KHUSUS DETAIL</code></td><td>542</td><td>Rincian spesifikasi teknis komoditas barang terhubung ke pos tarif HS</td></tr>
                        <tr><td>53</td><td><code>STATUS</code></td><td>45</td><td>Kode status alur pemrosesan dokumen pabean</td></tr>
                        <tr><td>54</td><td><code>STATUS PENGUSAHA</code></td><td>10</td><td>Status sertifikasi legal entitas (AEO, Mitra Utama, Non-AEO, dll)</td></tr>
                        <tr><td>55</td><td><code>TIPE KONTAINER</code></td><td>9</td><td>Tipe peti kemas (Dry, Reefer, Open Top, Flat Rack, Tank Container, dll)</td></tr>
                        <tr><td>56</td><td><code>TPS KODE DOKUMEN COARRI CODECO</code></td><td>9</td><td>Kode dokumen pergerakan kontainer pada integrasi TPS Online Coarri Codeco</td></tr>
                        <tr><td>57</td><td><code>TPS KODE DOKUMEN IN OUT</code></td><td>77</td><td>Kode dokumen pengeluaran dan pemasukan peti kemas/kargo di TPS Online</td></tr>
                        <tr><td>58</td><td><code>TUJUAN PEMASUKAN</code></td><td>6</td><td>Alasan & tujuan pemasukan ke kawasan berfasilitas kepabeanan</td></tr>
                        <tr><td>59</td><td><code>TUJUAN PENGELUARAN</code></td><td>31</td><td>Alasan & tujuan pengeluaran dari kawasan berfasilitas kepabeanan</td></tr>
                        <tr><td>60</td><td><code>TUJUAN PENGIRIMAN</code></td><td>31</td><td>Alokasi perpindahan barang antar fasilitas kepabeanan</td></tr>
                        <tr><td>61</td><td><code>TUTUP PU</code></td><td>3</td><td>Kode status penutupan pos pemberitahuan pabean (11, 12, 14)</td></tr>
                        <tr><td>62</td><td><code>UKURAN KONTAINER</code></td><td>4</td><td>Ukuran standar panjang peti kemas (20 ft, 40 ft, 45 ft, Lainnya)</td></tr>
                        <tr><td>63</td><td><code>VALUTA</code></td><td>204</td><td>Kode mata uang internasional ISO 4217 (USD, EUR, JPY, SGD, CNY, IDR, dll)</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- PERBAIKAN.PHP -->
    <div class="doc-section" id="ep-perbaikan">
        <div class="api-card">
            <div class="api-header">
                <span class="method-badge badge-get">GET</span>
                <span class="endpoint-url">/perbaikan.php</span>
            </div>
            <p style="font-size:13px; color: var(--text-muted); margin-bottom:14px;">
                Mengirimkan dokumen perbaikan / revisi ke CEISA 4.0 (menambahkan flag <code>isRevision=true</code> ke gateway Bea Cukai).
            </p>

            <h4 style="font-size:13px; margin: 16px 0 6px; color:var(--text-main);">Contoh Pemanggilan API:</h4>
            <div class="snippet-wrapper">
                <div class="snippet-tabs">
                    <button class="snippet-tab-btn active" data-mode="curl" onclick="setSnippetTab(this, 'curl')">🌐 cURL / HTTP</button>
                    <button class="snippet-tab-btn pb-tab" data-mode="pb" onclick="setSnippetTab(this, 'pb')">⚡ PowerBuilder (PowerScript)</button>
                </div>
                <div class="snippet-pane pane-curl active">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>curl -X GET "http://[server-ip]/perbaikan.php?cmp_id=010000000000000"</pre>
                    </div>
                </div>
                <div class="snippet-pane pane-pb" style="display:none;">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// PowerBuilder (PowerScript) - Kirim Dokumen Revisi / Perbaikan
OLEObject lole_http
String ls_url, ls_response
Long ll_status

ls_url = gs_url + "/perbaikan.php?cmp_id=" + gs_cmp_id

lole_http = CREATE OLEObject
IF lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP.6.0") <> 0 THEN
    lole_http.ConnectToNewObject("MSXML2.XMLHTTP")
END IF

lole_http.Open("GET", ls_url, False)
lole_http.setRequestHeader("Content-Type", "application/json; charset=utf-8")
lole_http.Send()

ll_status = Long(lole_http.Status)
ls_response = String(lole_http.ResponseText)

lole_http.DisconnectObject()
DESTROY lole_http

MessageBox("Hasil Perbaikan", "HTTP " + String(ll_status) + ":~r~n" + ls_response)</pre>
                    </div>
                </div>
            </div>
            <h4 style="font-size:13px; margin: 12px 0 6px; color:var(--text-main);">Contoh Respon (JSON):</h4>
            <div class="code-block">
                <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>{
  "status": "OK",
  "message": "Dokumen perbaikan berhasil dikirim ke CEISA 4.0"
}</pre>
            </div>
        </div>
    </div>

    <!-- STATUSAJU.PHP -->
    <div class="doc-section" id="ep-statusaju">
        <div class="api-card">
            <div class="api-header">
                <span class="method-badge badge-get">GET</span>
                <span class="endpoint-url">/statusaju.php</span>
            </div>
            <p style="font-size:13px; color: var(--text-muted); margin-bottom:14px;">
                Melakukan pengecekan status dan respon kepabeanan secara langsung ke CEISA 4.0 berdasarkan Nomor AJU. Status yang diterima otomatis disimpan ke database (tabel <code>bc_ceisa_status</code>, <code>bc_ceisa_respons</code>, dan update nomor daftar di <code>bc_ceisa_header</code>).
            </p>

            <table class="param-table">
                <thead>
                    <tr><th>Parameter</th><th>Tipe</th><th>Wajib?</th><th>Keterangan</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>aju</code></td><td>string</td><td>Wajib</td><td>Nomor AJU dokumen (26 digit). Contoh: <code>00002303006520261014297970</code></td></tr>
                    <tr><td><code>cmp_id</code></td><td>string</td><td>Opsional</td><td>ID Perusahaan pemilik dokumen.</td></tr>
                </tbody>
            </table>

            <h4 style="font-size:13px; margin: 16px 0 6px; color:var(--text-main);">Contoh Pemanggilan API:</h4>
            <div class="snippet-wrapper">
                <div class="snippet-tabs">
                    <button class="snippet-tab-btn active" data-mode="curl" onclick="setSnippetTab(this, 'curl')">🌐 cURL / HTTP</button>
                    <button class="snippet-tab-btn pb-tab" data-mode="pb" onclick="setSnippetTab(this, 'pb')">⚡ PowerBuilder (PowerScript)</button>
                </div>
                <div class="snippet-pane pane-curl active">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>curl -X GET "http://[server-ip]/statusaju.php?aju=00002303006520261014297970&amp;cmp_id=010000000000000"</pre>
                    </div>
                </div>
                <div class="snippet-pane pane-pb" style="display:none;">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// PowerBuilder (PowerScript) - Cek Status & Respon AJU
OLEObject lole_http
String ls_url, ls_response, ls_aju
Long ll_status

ls_aju = "00002303006520261014297970"
ls_url = gs_url + "/statusaju.php?aju=" + ls_aju + "&cmp_id=" + gs_cmp_id

lole_http = CREATE OLEObject
IF lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP.6.0") <> 0 THEN
    lole_http.ConnectToNewObject("MSXML2.XMLHTTP")
END IF

lole_http.Open("GET", ls_url, False)
lole_http.setRequestHeader("Content-Type", "application/json; charset=utf-8")
lole_http.Send()

ll_status = Long(lole_http.Status)
ls_response = String(lole_http.ResponseText)

lole_http.DisconnectObject()
DESTROY lole_http

IF ll_status = 200 THEN
    MessageBox("Status AJU", "Respon Bea Cukai:~r~n" + ls_response)
ELSE
    MessageBox("Error", "Gagal cek status (HTTP " + String(ll_status) + ")")
END IF</pre>
                    </div>
                </div>
            </div>
            <h4 style="font-size:13px; margin: 12px 0 6px; color:var(--text-main);">Contoh Respon:</h4>
            <div class="code-block">
                <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>OK</pre>
            </div>
        </div>
    </div>

    <!-- STATUS.PHP -->
    <div class="doc-section" id="ep-status">
        <div class="api-card">
            <div class="api-header">
                <span class="method-badge badge-get">GET</span>
                <span class="endpoint-url">/status.php</span>
            </div>
            <p style="font-size:13px; color: var(--text-muted); margin-bottom:14px;">
                Menarik seluruh riwayat status dan respon terbaru milik perusahaan dari CEISA 4.0 dan menampilkannya dalam format visual tabel web responsif.
            </p>

            <table class="param-table">
                <thead>
                    <tr><th>Parameter</th><th>Tipe</th><th>Wajib?</th><th>Keterangan</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>npwp</code></td><td>string</td><td>Opsional</td><td>NPWP Perusahaan. Jika tidak diisi, mengambil dari konfigurasi perusahaan aktif.</td></tr>
                    <tr><td><code>cmp_id</code></td><td>string</td><td>Opsional</td><td>ID Perusahaan.</td></tr>
                </tbody>
            </table>

            <h4 style="font-size:13px; margin: 16px 0 6px; color:var(--text-main);">Contoh Pemanggilan API:</h4>
            <div class="snippet-wrapper">
                <div class="snippet-tabs">
                    <button class="snippet-tab-btn active" data-mode="curl" onclick="setSnippetTab(this, 'curl')">🌐 cURL / HTTP</button>
                    <button class="snippet-tab-btn pb-tab" data-mode="pb" onclick="setSnippetTab(this, 'pb')">⚡ PowerBuilder (PowerScript)</button>
                </div>
                <div class="snippet-pane pane-curl active">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>curl -X GET "http://[server-ip]/status.php?cmp_id=010000000000000"</pre>
                    </div>
                </div>
                <div class="snippet-pane pane-pb" style="display:none;">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// PowerBuilder (PowerScript) - Tarik Seluruh Riwayat Status Perusahaan
OLEObject lole_http
String ls_url, ls_response
Long ll_status

ls_url = gs_url + "/status.php?cmp_id=" + gs_cmp_id

lole_http = CREATE OLEObject
IF lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP.6.0") <> 0 THEN
    lole_http.ConnectToNewObject("MSXML2.XMLHTTP")
END IF

lole_http.Open("GET", ls_url, False)
lole_http.Send()

ll_status = Long(lole_http.Status)
ls_response = String(lole_http.ResponseText)

lole_http.DisconnectObject()
DESTROY lole_http</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- PDF.PHP -->
    <div class="doc-section" id="ep-pdf">
        <div class="api-card">
            <div class="api-header">
                <span class="method-badge badge-get">GET</span>
                <span class="endpoint-url">/pdf.php</span>
            </div>
            <p style="font-size:13px; color: var(--text-muted); margin-bottom:14px;">
                Mengunduh file PDF respon resmi (SPPB, NPE, Billing Pajak, dll) dari CEISA 4.0 dan menyimpannya secara otomatis ke folder lokal <code>C:\iceisa40\pdf\[nomor_aju].pdf</code>.
            </p>

            <table class="param-table">
                <thead>
                    <tr><th>Parameter</th><th>Tipe</th><th>Wajib?</th><th>Keterangan</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>aju</code></td><td>string</td><td>Wajib</td><td>Nomor AJU dokumen.</td></tr>
                    <tr><td><code>pdf</code></td><td>string</td><td>Wajib</td><td>Path respon PDF yang diperoleh dari CEISA (contoh: <code>respon/2026/10/14/...pdf</code>).</td></tr>
                    <tr><td><code>view</code></td><td>int</td><td>Opsional</td><td>Isi <code>1</code> jika ingin menampilkan langsung PDF di jendela browser. Default: download file.</td></tr>
                </tbody>
            </table>

            <h4 style="font-size:13px; margin: 16px 0 6px; color:var(--text-main);">Contoh Pemanggilan API:</h4>
            <div class="snippet-wrapper">
                <div class="snippet-tabs">
                    <button class="snippet-tab-btn active" data-mode="curl" onclick="setSnippetTab(this, 'curl')">🌐 cURL / HTTP</button>
                    <button class="snippet-tab-btn pb-tab" data-mode="pb" onclick="setSnippetTab(this, 'pb')">⚡ PowerBuilder (PowerScript)</button>
                </div>
                <div class="snippet-pane pane-curl active">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>curl -X GET "http://[server-ip]/pdf.php?aju=00002303006520261014297970&amp;pdf=respon%2F2026%2F10%2F14%2Fdoc.pdf" -o "00002303006520261014297970.pdf"</pre>
                    </div>
                </div>
                <div class="snippet-pane pane-pb" style="display:none;">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// PowerBuilder (PowerScript) - Unduh & Simpan PDF Respon / Billing Bea Cukai
OLEObject lole_http
String ls_url, ls_filepath, ls_aju
Blob lblb_pdf
Long ll_status
Integer li_file

ls_aju = "00002303006520261014297970"
ls_url = gs_url + "/pdf.php?aju=" + ls_aju + "&pdf=respon%2Fdoc.pdf&cmp_id=" + gs_cmp_id
ls_filepath = "C:\CEISA4\PDF\RESPON_" + ls_aju + ".pdf"

lole_http = CREATE OLEObject
IF lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP.6.0") <> 0 THEN
    lole_http.ConnectToNewObject("MSXML2.XMLHTTP")
END IF

lole_http.Open("GET", ls_url, False)
lole_http.Send()

ll_status = Long(lole_http.Status)
IF ll_status = 200 THEN
    // Ambil body stream biner ke dalam Blob
    lblb_pdf = lole_http.ResponseBody
    li_file = FileOpen(ls_filepath, StreamMode!, Write!, Shared!, Replace!)
    IF li_file > 0 THEN
        FileWriteLarge(li_file, lblb_pdf)
        FileClose(li_file)
        MessageBox("Sukses", "PDF Berhasil Diunduh:~r~n" + ls_filepath)
        // Buka otomatis dengan PDF viewer default Windows
        Run("rundll32 url.dll,FileProtocolHandler " + ls_filepath)
    END IF
ELSE
    MessageBox("Error", "Gagal unduh PDF (HTTP " + String(ll_status) + ")")
END IF

lole_http.DisconnectObject()
DESTROY lole_http</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- UPLOAD_DOKAP.PHP -->
    <div class="doc-section" id="ep-upload-dokap">
        <div class="api-card">
            <div class="api-header">
                <span class="method-badge badge-post" style="background:#ec4899; color:#fff;">FILE / OPENAPI</span>
                <span class="endpoint-url">/upload_dokap.php</span>
            </div>
            <p style="font-size:13px; color: var(--text-muted); margin-bottom:14px;">
                Modul H2H Upload Dokumen Pelengkap (PDF) dan Gambar Fisik Barang ke API Gateway Bea Cukai sesuai spesifikasi resmi OpenAPI (<em>H2H Upload Dokap dan Gambar PROD OpenAPI</em>).
            </p>

            <table class="param-table">
                <thead>
                    <tr><th>Aksi (<code>action</code>)</th><th>Endpoint Gateway Bea Cukai</th><th>Tipe Berkas</th><th>Payload Param JSON</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>upload_dokumen</code></td>
                        <td><code>POST https://apis-gw.beacukai.go.id/v2/openapi/file/dokumen</code></td>
                        <td>PDF (Invoice, B/L, COO, SK, dll)</td>
                        <td><code>{"nomorAju": "...", "seriDokumen": 1, "npwp": "..."}</code></td>
                    </tr>
                    <tr>
                        <td><code>upload_barang</code></td>
                        <td><code>POST https://apis-gw.beacukai.go.id/v2/openapi/file/barang</code></td>
                        <td>JPG / PNG (Foto fisik komoditas)</td>
                        <td><code>{"keterangan": "...", "nomorAju": "...", "seriBarang": 1, "npwp": "..."}</code></td>
                    </tr>
                    <tr>
                        <td><code>upload_npd</code></td>
                        <td><code>POST https://apis-gw.beacukai.go.id/v2/openapi/file/upload-dokap-npd</code></td>
                        <td>PDF (Tindak lanjut Nota Pemberitahuan Dokumen)</td>
                        <td><code>{"nomorAju": "...", "seriDokumen": 1, "npwp": "..."}</code></td>
                    </tr>
                </tbody>
            </table>

            <h4 style="font-size:13px; margin: 16px 0 6px; color:var(--text-main);">Contoh Pemanggilan API:</h4>
            <div class="snippet-wrapper">
                <div class="snippet-tabs">
                    <button class="snippet-tab-btn active" data-mode="curl" onclick="setSnippetTab(this, 'curl')">🌐 cURL / HTTP</button>
                    <button class="snippet-tab-btn pb-tab" data-mode="pb" onclick="setSnippetTab(this, 'pb')">⚡ PowerBuilder (PowerScript)</button>
                </div>
                <div class="snippet-pane pane-curl active">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>curl -X POST "https://apis-gw.beacukai.go.id/v2/openapi/file/dokumen" \
  -H "Authorization: Bearer [TOKEN]" \
  -H "Beacukai-Api-Key: [API-KEY]" \
  -F "file=@/path/to/invoice.pdf" \
  -F 'param={"nomorAju":"000020SUJ64320260130000001","seriDokumen":1,"npwp":"010816064431000"}'</pre>
                    </div>
                </div>
                <div class="snippet-pane pane-pb" style="display:none;">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// PowerBuilder (PowerScript) - Upload Dokumen Pelengkap PDF (Multipart/Form-Data)
OLEObject lole_http
String ls_url, ls_filepath, ls_filename, ls_boundary, ls_param_json
Blob lblb_file, lblb_head, lblb_tail, lblb_payload
Integer li_file
Long ll_status

ls_url = "https://apis-gw.beacukai.go.id/v2/openapi/file/dokumen"
ls_filepath = "C:\CEISA4\DOKAP\invoice.pdf"
ls_filename = "invoice.pdf"
ls_boundary = "----PowerBuilderBoundary7d33a8"

// Baca file PDF ke Blob
li_file = FileOpen(ls_filepath, StreamMode!, Read!, Shared!)
IF li_file <= 0 THEN
    MessageBox("Error", "File PDF tidak ditemukan: " + ls_filepath)
    Return
END IF
FileReadLarge(li_file, lblb_file)
FileClose(li_file)

ls_param_json = '{"nomorAju":"000020SUJ64320260130000001","seriDokumen":1,"npwp":"' + gs_cmp_id + '"}'

lblb_head = Blob( &
    "--" + ls_boundary + "~r~n" + &
    'Content-Disposition: form-data; name="file"; filename="' + ls_filename + '"~r~n' + &
    "Content-Type: application/pdf~r~n~r~n", EncodingUTF8! )

lblb_tail = Blob( &
    "~r~n--" + ls_boundary + "~r~n" + &
    'Content-Disposition: form-data; name="param"~r~n~r~n' + &
    ls_param_json + "~r~n" + &
    "--" + ls_boundary + "--~r~n", EncodingUTF8! )

lblb_payload = lblb_head + lblb_file + lblb_tail

lole_http = CREATE OLEObject
lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP.6.0")

lole_http.Open("POST", ls_url, False)
lole_http.setRequestHeader("Authorization", "Bearer " + gs_jwt_token)
lole_http.setRequestHeader("beacukai-api-key", gs_api_key)
lole_http.setRequestHeader("Content-Type", "multipart/form-data; boundary=" + ls_boundary)
lole_http.Send(lblb_payload)

ll_status = Long(lole_http.Status)
MessageBox("Upload Dokap", "HTTP " + String(ll_status) + ":~r~n" + String(lole_http.ResponseText))

lole_http.DisconnectObject()
DESTROY lole_http</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- SKEMA BC 2.0 (PIB) -->
    <div class="doc-section" id="ep-schema-bc20">
        <div class="api-card">
            <div class="api-header">
                <span class="method-badge badge-get" style="background:#3b82f6; color:#fff;">SCHEMA JSON</span>
                <span class="endpoint-url">Skema Kirim Dokumen BC 2.0 (v.0.5.7.23)</span>
            </div>
            <p style="font-size:13px; color: var(--text-muted); margin-bottom:14px;">
                Standar resmi skema JSON Kirim Dokumen Pabean BC 2.0 (Pemberitahuan Impor Barang / PIB) dari Portal OpenAPI Bea Cukai. Terdiri atas data Header (umum) dan rincian data Barang (spesifik).
            </p>

            <div class="note-box" style="margin-bottom:14px;">
                <strong>Karakteristik &amp; Aturan Wajib BC 2.0:</strong><br>
                1. <strong>Field Wajib Header:</strong> <code>kodeJenisImpor</code>, <code>kodeCaraBayar</code>, <code>kodePelMuat</code>, <code>kodePelTujuan</code>, <code>kodeTps</code>, <code>kodeTutupPu</code> (11/12/14), <code>kodeValuta</code>, <code>ndpbm</code>, <code>cif</code>, <code>fob</code>, <code>asuransi</code>, <code>freight</code>, <code>bruto</code>, <code>netto</code>, <code>biayaTambahan</code>, <code>biayaPengurang</code>, <code>disclaimer: "1"</code>.<br>
                2. <strong>Urutan Tuple Entitas Wajib:</strong> Seri 1: Importir (<code>kodeEntitas: 1</code>), Seri 2: Pemilik Barang (<code>kodeEntitas: 7</code>, wajib <code>kodeAfiliasi: TAH/AFL/CTR/FAM</code>), Seri 3: Pengirim (<code>kodeEntitas: 9</code>), Seri 4: Penjual (<code>kodeEntitas: 10</code>), Seri 5: Pemusatan (<code>kodeEntitas: 11</code>), Seri 6: PPJK (<code>kodeEntitas: 4</code>).<br>
                3. <strong>Field Wajib Barang:</strong> <code>posTarif</code>, <code>uraian</code>, <code>merk</code> (min 2 karakter), <code>tipe</code> (min 2 karakter), <code>jumlahSatuan</code>, <code>kodeSatuanBarang</code>, <code>jumlahKemasan</code>, <code>kodeJenisKemasan</code>, <code>cif</code>, <code>fob</code>, <code>asuransi</code>, <code>freight</code>, <code>hargaSatuan</code>, <code>saldoAwal</code>, <code>saldoAkhir</code>, <code>metodePenentuanNilai</code> (Metode 1..6), <code>statementPerbedaanHarga</code> (Y/T), <code>barangTarif</code>, <code>barangVd</code>.<br>
                4. <strong>Dokumen Minimal:</strong> Dokumen Seri 1 Invoice (<code>kodeDokumen: 380</code>) dan Dokumen Seri 2 B/L atau AWB (<code>kodeDokumen: 705/740</code>).
            </div>
        </div>
    </div>

    <!-- REST RESPONSE CODES (RFC 2616) -->
    <div class="doc-section" id="ep-rest-codes">
        <div class="api-card">
            <div class="api-header">
                <span class="method-badge badge-get" style="background:#10b981; color:#fff;">HTTP / RFC 2616</span>
                <span class="endpoint-url">REST Response Codes</span>
            </div>
            <p style="font-size:13px; color: var(--text-muted); margin-bottom:14px;">
                Respon REST API Bea Cukai dalam setiap transaksi Host-to-Host (H2H) mengacu pada kode status HTTP standar (<a href="https://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html#sec10.4.1" target="_blank" style="color:var(--primary); text-decoration:none;">RFC 2616</a>). Berikut adalah daftar resmi kode respon beserta informasi yang didapat dari setiap respon:
            </p>

            <table class="param-table">
                <thead>
                    <tr>
                        <th style="width:200px;">Kode Respon</th>
                        <th>Deskripsi (Indonesia)</th>
                        <th>Description (English)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><span class="method-badge badge-get" style="font-weight:700;">200 OK</span></td>
                        <td>Request diterima</td>
                        <td>The request has succeeded</td>
                    </tr>
                    <tr>
                        <td><span class="method-badge badge-get" style="font-weight:700;">201 CREATED</span></td>
                        <td>Request telah dipenuhi dan telah dibuat resource yang baru</td>
                        <td>The request has been fulfilled and resulted in a new resource being created</td>
                    </tr>
                    <tr>
                        <td><span class="method-badge badge-get" style="font-weight:700;">204 NO CONTENT</span></td>
                        <td>Server menerima request namun tidak ada yang bisa dikembalikan</td>
                        <td>The server has fulfilled the request but that there was nothing to return</td>
                    </tr>
                    <tr>
                        <td><span class="method-badge badge-post" style="background:rgba(239, 68, 68, 0.2); color:#ef4444; font-weight:700;">400 BAD REQUEST</span></td>
                        <td>Parameter input tidak valid</td>
                        <td>Invalid input parameter</td>
                    </tr>
                    <tr>
                        <td><span class="method-badge badge-post" style="background:rgba(239, 68, 68, 0.2); color:#ef4444; font-weight:700;">401 UNAUTHORIZED</span></td>
                        <td>Request membutuhkan otentikasi pengguna</td>
                        <td>The request requires user authentication</td>
                    </tr>
                    <tr>
                        <td><span class="method-badge badge-post" style="background:rgba(245, 158, 11, 0.2); color:#f59e0b; font-weight:700;">403 FORBIDDEN</span></td>
                        <td>Server memahami request namun menolak untuk menindaklanjuti</td>
                        <td>The server understood the request but is refusing to fulfill it</td>
                    </tr>
                    <tr>
                        <td><span class="method-badge badge-post" style="background:rgba(239, 68, 68, 0.2); color:#ef4444; font-weight:700;">404 NOT FOUND</span></td>
                        <td>Server tidak menemukan apapun sesuai Request-URI yang mengindikasikan resource telah dihapus</td>
                        <td>The server has not found anything according to the Request-URI indicating the resource was deleted</td>
                    </tr>
                    <tr>
                        <td><span class="method-badge badge-post" style="background:rgba(245, 158, 11, 0.2); color:#f59e0b; font-weight:700;">405 METHOD NOT ALLOWED</span></td>
                        <td>Resource yang ditargetkan tidak mendukung request pada method HTTP</td>
                        <td>The targeted resource does not support requests for HTTP methods</td>
                    </tr>
                    <tr>
                        <td><span class="method-badge badge-post" style="background:rgba(245, 158, 11, 0.2); color:#f59e0b; font-weight:700;">406 NOT ACCEPTABLE</span></td>
                        <td>Karakteristik konten dari client tidak dapat diterima sesuai request yang dikirim pada penerimaan Header</td>
                        <td>The content characteristics of the client are not acceptable according to the accept header sent in the request</td>
                    </tr>
                    <tr>
                        <td><span class="method-badge badge-post" style="background:rgba(245, 158, 11, 0.2); color:#f59e0b; font-weight:700;">409 CONFLICT</span></td>
                        <td>Adanya konflik perubahan yang terdeteksi ketika memodifikasi resource</td>
                        <td>Indicates that conflicting changes have been detected when modifying resources</td>
                    </tr>
                    <tr>
                        <td><span class="method-badge badge-post" style="background:rgba(245, 158, 11, 0.2); color:#f59e0b; font-weight:700;">415 UNSUPPORTED MEDIA TYPE</span></td>
                        <td>Server menolak request karena format tidak didukung oleh resource yang ditargetkan</td>
                        <td>The server is refusing the service because the format unsupported by the targeted resource</td>
                    </tr>
                    <tr>
                        <td><span class="method-badge badge-post" style="background:rgba(220, 38, 38, 0.25); color:#f87171; font-weight:700;">500 INTERNAL SERVER ERROR</span></td>
                        <td>Error internal muncul pada server</td>
                        <td>An internal error occurred in the server</td>
                    </tr>
                </tbody>
            </table>

            <!-- ERROR RESPONSE CODES (JSON SCHEMA VALIDATION) -->
            <div style="margin-top: 28px; border-top: 1px solid var(--border-color); padding-top: 20px;">
                <div class="api-header" style="border:none; padding:0; margin-bottom:12px;">
                    <span class="method-badge badge-post" style="background:#e74c3c; color:#fff;">ERROR SCHEMA</span>
                    <span class="endpoint-url" style="color:#ef4444;">Error Response Code (Validasi JSON Schema)</span>
                </div>
                <p style="font-size:13px; color: var(--text-muted); margin-bottom:14px;">
                    Daftar kode respons error yang dikembalikan dalam body response API Gateway Bea Cukai ketika payload dokumen pabean mengalami kegagalan validasi skema JSON:
                </p>

                <table class="param-table">
                    <thead>
                        <tr>
                            <th style="width:160px;">Kode Respon</th>
                            <th>Uraian (Indonesia)</th>
                            <th>Aturan JSON Schema (Draft-07) &amp; Solusi Penanganan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><span class="method-badge badge-post" style="background:rgba(239, 68, 68, 0.2); color:#ef4444; font-weight:700;">1008</span></td>
                            <td>Nilai isian harus berasal dari salah satu pilihan, lihat kembali pilihan enumeration di JSON Schema</td>
                            <td>Pelanggaran aturan <code>enum</code>. Contoh: <code>kodeTutupPu</code> harus salah satu dari <code>["11", "12", "14"]</code>, atau <code>disclaimer</code> harus <code>"1"</code> atau <code>"0"</code>. Periksa pilihan nilai di referensi.</td>
                        </tr>
                        <tr>
                            <td><span class="method-badge badge-post" style="background:rgba(239, 68, 68, 0.2); color:#ef4444; font-weight:700;">1023</span></td>
                            <td>Nilai isian tidak sesuai dengan pattern yang ditentukan, perhatikan karakter yang diperbolehkan dan jumlah karakter</td>
                            <td>Pelanggaran aturan <code>pattern</code> (Regex) atau batas panjang. Contoh: <code>nomorAju</code> harus tepat 26 digit alphanumeric (<code>^[A-Za-z0-9]{26}$</code>), atau kode negara harus tepat 2 huruf kapital ISO-3166.</td>
                        </tr>
                        <tr>
                            <td><span class="method-badge badge-post" style="background:rgba(239, 68, 68, 0.2); color:#ef4444; font-weight:700;">1028</span></td>
                            <td>Elemen mandatory, wajib terdapat pada data json yang dikirimkan</td>
                            <td>Pelanggaran aturan <code>required</code>. Field wajib belum terisi atau terhapus dari struktur JSON. Contoh: properti <code>kodeBendera</code> pada sarana pengangkut atau <code>kodeDokumen</code> di root dokumen.</td>
                        </tr>
                        <tr>
                            <td><span class="method-badge badge-post" style="background:rgba(239, 68, 68, 0.2); color:#ef4444; font-weight:700;">1042</span></td>
                            <td>Nilai isian harus sama dengan CONSTANT yang ditetapkan sesuai urutannya</td>
                            <td>Pelanggaran aturan <code>const</code> atau urutan Tuple array. Contoh: pada dokumen pelengkap P3BET indeks 0 wajib bernilai <code>kodeDokumen: "33"</code>, atau urutan entitas (<code>kodeEntitas</code>) tidak sesuai tuple schema.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- API_IMPORT_EXCEL.PHP -->
    <div class="doc-section" id="ep-import-excel">
        <div class="api-card">
            <div class="api-header">
                <span class="method-badge badge-post">POST</span>
                <span class="endpoint-url">/api_import_excel.php</span>
            </div>
            <p style="font-size:13px; color: var(--text-muted); margin-bottom:14px;">
                Mengimpor file Excel hasil ekspor resmi Portal CEISA 4.0 (format <code>.xlsx</code>) langsung ke tabel-tabel transaksi database MySQL secara idempoten, cepat, dan aman. Mendukung Single Dokumen maupun <strong>Batch Multi-AJU</strong> (ribuan baris data), mencakup dokumen <strong>BC 1.6, BC 2.0, BC 2.3, BC 2.5, BC 2.5 KEK, BC 2.6.1, BC 2.6.2, BC 2.7, BC 3.0, BC 6.1.1, BC 6.1.3</strong>.
            </p>

            <h4 style="font-size:12px; margin: 10px 0 6px; color:#60a5fa;">Daftar 22 Sheet Standar CEISA 4.0 & Tabel Tujuan MySQL:</h4>
            <div style="font-size:11px; line-height: 1.6; color: var(--text-muted); background:rgba(0,0,0,0.25); padding:10px 14px; border-radius:6px; margin-bottom:14px; border:1px solid var(--border);">
                <code>HEADER</code> &rarr; <code>bc_ceisa_header</code> | 
                <code>ENTITAS</code> &rarr; <code>bc_ceisa_entitas</code> | 
                <code>DOKUMEN</code> &rarr; <code>bc_ceisa_dokumen</code> | 
                <code>PENGANGKUT</code> &rarr; <code>bc_ceisa_pengangkut</code> | 
                <code>KEMASAN</code> &rarr; <code>bc_ceisa_kemasan</code> | 
                <code>KONTAINER</code> &rarr; <code>bc_ceisa_kontainer</code> | 
                <code>KOMPONENBIAYA</code> &rarr; <code>bc_ceisa_komponenbiaya</code> | 
                <code>BARANG</code> &rarr; <code>bc_ceisa_barang</code> | 
                <code>BARANGTARIF</code> &rarr; <code>bc_ceisa_barangtarif</code> | 
                <code>BARANGDOKUMEN</code> &rarr; <code>bc_ceisa_barangdokumen</code> | 
                <code>BARANGENTITAS</code> &rarr; <code>bc_ceisa_barangentitas</code> | 
                <code>BARANGSPEKKHUSUS</code> &rarr; <code>bc_ceisa_barangspekkhusus</code> | 
                <code>BARANGVD</code> &rarr; <code>bc_ceisa_barangvd</code> | 
                <code>BAHANBAKU</code> &rarr; <code>bc_ceisa_bahanbaku</code> | 
                <code>BAHANBAKUTARIF</code> &rarr; <code>bc_ceisa_bahanbakutarif</code> | 
                <code>BAHANBAKUDOKUMEN</code> &rarr; <code>bc_ceisa_bahanbakudokumen</code> | 
                <code>PUNGUTAN</code> &rarr; <code>bc_ceisa_pungutan</code> | 
                <code>JAMINAN</code> &rarr; <code>bc_ceisa_jaminan</code> | 
                <code>BANKDEVISA</code> &rarr; <code>bc_ceisa_bankdevisa</code> | 
                <code>KESIAPANBARANG</code> &rarr; <code>bc_ceisa_kesiapanbarang</code> | 
                <code>VERSI</code> &rarr; <code>bc_ceisa_versi</code> | 
                <code>RESPON</code> &rarr; <code>bc_ceisa_respons</code>
            </div>

            <table class="param-table">
                <thead>
                    <tr><th>Parameter</th><th>Tipe</th><th>Wajib?</th><th>Keterangan</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>file_name</code></td><td>string</td><td>Kondisional</td><td>Nama file Excel yang ada di folder <code>share/xls/</code> (contoh: <code>bc-20.xlsx</code>, <code>bc-25 (kek).xlsx</code>, <code>bc-611.xlsx</code>, <code>bc-23.xlsx</code>).</td></tr>
                    <tr><td><code>file</code></td><td>file upload</td><td>Kondisional</td><td>Berkas file <code>.xlsx</code> jika diupload langsung (multipart/form-data).</td></tr>
                    <tr><td><code>cmp_id</code></td><td>string</td><td>Wajib</td><td>Company ID / NPWP pemilik dokumen pabean.</td></tr>
                    <tr><td><code>action</code></td><td>string</td><td>Opsional</td><td><code>import</code> (default: simpan ke database dengan chunked bulk-insert) atau <code>preview</code> (hanya membaca jumlah baris & sheet).</td></tr>
                    <tr><td><code>overwrite</code></td><td>int</td><td>Opsional</td><td><code>1</code> (default: hapus data lama jika AJU sudah ada) atau <code>0</code>.</td></tr>
                </tbody>
            </table>

            <h4 style="font-size:13px; margin: 16px 0 6px; color:var(--text-main);">Contoh Pemanggilan API:</h4>
            <div class="snippet-wrapper">
                <div class="snippet-tabs">
                    <button class="snippet-tab-btn active" data-mode="curl" onclick="setSnippetTab(this, 'curl')">🌐 cURL / HTTP</button>
                    <button class="snippet-tab-btn pb-tab" data-mode="pb" onclick="setSnippetTab(this, 'pb')">⚡ PowerBuilder (PowerScript)</button>
                </div>
                <div class="snippet-pane pane-curl active">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre># Opsi 1: Import file dari folder server share/xls/
curl -X POST "http://[server-ip]/api_import_excel.php" \
  -d "file_name=bc-20.xlsx&amp;cmp_id=010000000000000&amp;action=import&amp;overwrite=1"

# Opsi 2: Upload langsung file Excel dari klien (multipart/form-data)
curl -X POST "http://[server-ip]/api_import_excel.php" \
  -F "file=@bc-20.xlsx" \
  -F "cmp_id=010000000000000" \
  -F "action=import" \
  -F "overwrite=1"</pre>
                    </div>
                </div>
                <div class="snippet-pane pane-pb" style="display:none;">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// PowerBuilder (PowerScript) - Import File Excel ke Database
OLEObject lole_http
String ls_url, ls_payload, ls_response
Long ll_status

// Panggil import file excel yang ada di folder share/xls/
ls_url = gs_url + "/api_import_excel.php"
ls_payload = "file_name=bc-20.xlsx&cmp_id=" + gs_cmp_id + "&action=import&overwrite=1"

lole_http = CREATE OLEObject
IF lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP.6.0") <> 0 THEN
    lole_http.ConnectToNewObject("MSXML2.XMLHTTP")
END IF

lole_http.Open("POST", ls_url, False)
lole_http.setRequestHeader("Content-Type", "application/x-www-form-urlencoded")
lole_http.Send(ls_payload)

ll_status = Long(lole_http.Status)
ls_response = String(lole_http.ResponseText)

lole_http.DisconnectObject()
DESTROY lole_http

IF ll_status = 200 THEN
    MessageBox("Sukses Import", "Hasil Import:~r~n" + ls_response)
ELSE
    MessageBox("Gagal", "HTTP " + String(ll_status) + ":~r~n" + ls_response)
END IF</pre>
                    </div>
                </div>
            </div>
            <h4 style="font-size:13px; margin: 12px 0 6px; color:var(--text-main);">Contoh Respon (JSON):</h4>
            <div class="code-block">
                <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>{
  "status": "OK",
  "nomor_aju": "00002003006520261014297970",
  "kode_dokumen": "20",
  "total_rows_imported": 35,
  "execution_time_seconds": 0.12
}</pre>
            </div>
        </div>
    </div>

    <!-- KURS.PHP -->
    <div class="doc-section" id="ep-kurs">
        <div class="api-card">
            <div class="api-header">
                <span class="method-badge badge-get">GET</span>
                <span class="endpoint-url">/kurs.php</span>
            </div>
            <p style="font-size:13px; color: var(--text-muted); margin-bottom:14px;">
                Mengambil nilai kurs valuta asing pajak mingguan resmi dari Keputusan Menteri Keuangan via CEISA 4.0.
            </p>

            <table class="param-table">
                <thead>
                    <tr><th>Parameter</th><th>Tipe</th><th>Wajib?</th><th>Keterangan</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>kode</code></td><td>string</td><td>Opsional</td><td>Kode valuta asing (contoh: <code>usd</code>, <code>jpy</code>, <code>eur</code>, <code>sgd</code>, <code>cny</code>). Default: <code>usd</code></td></tr>
                    <tr><td><code>json</code></td><td>int</td><td>Opsional</td><td>Isi <code>1</code> jika ingin output format JSON lengkap. Default: plain text angka kurs.</td></tr>
                </tbody>
            </table>

            <h4 style="font-size:13px; margin: 16px 0 6px; color:var(--text-main);">Contoh Pemanggilan API:</h4>
            <div class="snippet-wrapper">
                <div class="snippet-tabs">
                    <button class="snippet-tab-btn active" data-mode="curl" onclick="setSnippetTab(this, 'curl')">🌐 cURL / HTTP</button>
                    <button class="snippet-tab-btn pb-tab" data-mode="pb" onclick="setSnippetTab(this, 'pb')">⚡ PowerBuilder (PowerScript)</button>
                </div>
                <div class="snippet-pane pane-curl active">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre># Output JSON:
curl -X GET "http://[server-ip]/kurs.php?kode=usd&amp;json=1"

# Output plain text:
curl -X GET "http://[server-ip]/kurs.php?kode=usd"</pre>
                    </div>
                </div>
                <div class="snippet-pane pane-pb" style="display:none;">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// PowerBuilder (PowerScript) - Cek Kurs Valuta Asing Terkini
OLEObject lole_http
String ls_url, ls_response
Long ll_status
Dec ldc_kurs

ls_url = gs_url + "/kurs.php?kode=usd&cmp_id=" + gs_cmp_id

lole_http = CREATE OLEObject
IF lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP.6.0") <> 0 THEN
    lole_http.ConnectToNewObject("MSXML2.XMLHTTP")
END IF

lole_http.Open("GET", ls_url, False)
lole_http.Send()

ll_status = Long(lole_http.Status)
ls_response = String(lole_http.ResponseText)

lole_http.DisconnectObject()
DESTROY lole_http

IF ll_status = 200 THEN
    ldc_kurs = Dec(Trim(ls_response))
    MessageBox("Info Kurs", "Nilai Kurs USD: Rp " + String(ldc_kurs, "#,##0.00"))
END IF</pre>
                    </div>
                </div>
            </div>
            <h4 style="font-size:13px; margin: 12px 0 6px; color:var(--text-main);">Contoh Respon (JSON):</h4>
            <div class="code-block">
                <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>{
  "kode": "USD",
  "kurs": 15850.00
}</pre>
            </div>
        </div>
    </div>

    <!-- TARIF.PHP -->
    <div class="doc-section" id="ep-tarif">
        <div class="api-card">
            <div class="api-header">
                <span class="method-badge badge-get">GET</span>
                <span class="endpoint-url">/tarif.php</span>
            </div>
            <p style="font-size:13px; color: var(--text-muted); margin-bottom:14px;">
                Mengecek tarif Bea Masuk (BM) resmi dari Buku Tarif Kepabeanan Indonesia (BTKI) berdasarkan Pos Tarif HS Code 8 digit.
            </p>

            <table class="param-table">
                <thead>
                    <tr><th>Parameter</th><th>Tipe</th><th>Wajib?</th><th>Keterangan</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>a</code></td><td>string</td><td>Wajib</td><td>Kode HS 8 digit (contoh: <code>85168090</code>).</td></tr>
                    <tr><td><code>b</code></td><td>string</td><td>Opsional</td><td>Tanggal berlaku tarif (YYYY-MM-DD). Default: hari ini.</td></tr>
                </tbody>
            </table>

            <h4 style="font-size:13px; margin: 16px 0 6px; color:var(--text-main);">Contoh Pemanggilan API:</h4>
            <div class="snippet-wrapper">
                <div class="snippet-tabs">
                    <button class="snippet-tab-btn active" data-mode="curl" onclick="setSnippetTab(this, 'curl')">🌐 cURL / HTTP</button>
                    <button class="snippet-tab-btn pb-tab" data-mode="pb" onclick="setSnippetTab(this, 'pb')">⚡ PowerBuilder (PowerScript)</button>
                </div>
                <div class="snippet-pane pane-curl active">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>curl -X GET "http://[server-ip]/tarif.php?a=85168090"</pre>
                    </div>
                </div>
                <div class="snippet-pane pane-pb" style="display:none;">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// PowerBuilder (PowerScript) - Cek Tarif Bea Masuk HS Code
OLEObject lole_http
String ls_url, ls_response
Long ll_status
Dec ldc_bm

ls_url = gs_url + "/tarif.php?a=85168090&cmp_id=" + gs_cmp_id

lole_http = CREATE OLEObject
IF lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP.6.0") <> 0 THEN
    lole_http.ConnectToNewObject("MSXML2.XMLHTTP")
END IF

lole_http.Open("GET", ls_url, False)
lole_http.Send()

ll_status = Long(lole_http.Status)
ls_response = String(lole_http.ResponseText)

lole_http.DisconnectObject()
DESTROY lole_http

IF ll_status = 200 THEN
    ldc_bm = Dec(Trim(ls_response))
    MessageBox("Tarif HS 85168090", "Tarif Bea Masuk (BM): " + String(ldc_bm, "0.00") + "%")
END IF</pre>
                    </div>
                </div>
            </div>
            <h4 style="font-size:13px; margin: 12px 0 6px; color:var(--text-main);">Contoh Respon:</h4>
            <div class="code-block">
                <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>10.00</pre>
            </div>
        </div>
    </div>

    <!-- NIB.PHP -->
    <div class="doc-section" id="ep-nib">
        <div class="api-card">
            <div class="api-header">
                <span class="method-badge badge-get">GET</span>
                <span class="endpoint-url">/nib.php</span>
            </div>
            <p style="font-size:13px; color: var(--text-muted); margin-bottom:14px;">
                Mengambil NIB dan Nama Perusahaan dari CEISA 4.0 berdasarkan NPWP.<br>
                Output format: <code>NIB|NamaPerusahaan</code>
            </p>
            <table class="param-table">
                <thead>
                    <tr><th>Parameter</th><th>Tipe</th><th>Wajib?</th><th>Keterangan</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>npwp</code></td><td>string</td><td>Wajib</td><td>NPWP 15/16 digit perusahaan.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- SKEP.PHP -->
    <div class="doc-section" id="ep-skep">
        <div class="api-card">
            <div class="api-header">
                <span class="method-badge badge-get">GET</span>
                <span class="endpoint-url">/skep.php</span>
            </div>
            <p style="font-size:13px; color: var(--text-muted); margin-bottom:14px;">
                Mengambil Nomor dan Tanggal SKEP Ijin Fasilitas TPB dari CEISA 4.0.<br>
                Output format: <code>NomorSkep|TanggalSkep</code>
            </p>
            <table class="param-table">
                <thead>
                    <tr><th>Parameter</th><th>Tipe</th><th>Wajib?</th><th>Keterangan</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>npwp</code></td><td>string</td><td>Wajib</td><td>NPWP 15/16 digit perusahaan.</td></tr>
                </tbody>
            </table>

            <h4 style="font-size:13px; margin: 16px 0 6px; color:var(--text-main);">Contoh Pemanggilan API:</h4>
            <div class="snippet-wrapper">
                <div class="snippet-tabs">
                    <button class="snippet-tab-btn active" data-mode="curl" onclick="setSnippetTab(this, 'curl')">🌐 cURL / HTTP</button>
                    <button class="snippet-tab-btn pb-tab" data-mode="pb" onclick="setSnippetTab(this, 'pb')">⚡ PowerBuilder (PowerScript)</button>
                </div>
                <div class="snippet-pane pane-curl active">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>curl -X GET "http://[server-ip]/nib.php?npwp=010000000000000"</pre>
                    </div>
                </div>
                <div class="snippet-pane pane-pb" style="display:none;">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// PowerBuilder (PowerScript) - Cek NIB Perusahaan
OLEObject lole_http
String ls_url, ls_response
Long ll_status

ls_url = gs_url + "/nib.php?npwp=" + gs_cmp_id

lole_http = CREATE OLEObject
IF lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP.6.0") <> 0 THEN
    lole_http.ConnectToNewObject("MSXML2.XMLHTTP")
END IF

lole_http.Open("GET", ls_url, False)
lole_http.Send()

ll_status = Long(lole_http.Status)
ls_response = String(lole_http.ResponseText)

lole_http.DisconnectObject()
DESTROY lole_http

// Format output: NIB|NamaPerusahaan
MessageBox("Data NIB", ls_response)</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- SKEP.PHP -->
    <div class="doc-section" id="ep-skep">
        <div class="api-card">
            <div class="api-header">
                <span class="method-badge badge-get">GET</span>
                <span class="endpoint-url">/skep.php</span>
            </div>
            <p style="font-size:13px; color: var(--text-muted); margin-bottom:14px;">
                Mengambil Nomor dan Tanggal SKEP Ijin Fasilitas TPB dari CEISA 4.0.<br>
                Output format: <code>NomorSkep|TanggalSkep</code>
            </p>
            <table class="param-table">
                <thead>
                    <tr><th>Parameter</th><th>Tipe</th><th>Wajib?</th><th>Keterangan</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>npwp</code></td><td>string</td><td>Wajib</td><td>NPWP Perusahaan.</td></tr>
                </tbody>
            </table>

            <h4 style="font-size:13px; margin: 16px 0 6px; color:var(--text-main);">Contoh Pemanggilan API:</h4>
            <div class="snippet-wrapper">
                <div class="snippet-tabs">
                    <button class="snippet-tab-btn active" data-mode="curl" onclick="setSnippetTab(this, 'curl')">🌐 cURL / HTTP</button>
                    <button class="snippet-tab-btn pb-tab" data-mode="pb" onclick="setSnippetTab(this, 'pb')">⚡ PowerBuilder (PowerScript)</button>
                </div>
                <div class="snippet-pane pane-curl active">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>curl -X GET "http://[server-ip]/skep.php?npwp=010000000000000"</pre>
                    </div>
                </div>
                <div class="snippet-pane pane-pb" style="display:none;">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// PowerBuilder (PowerScript) - Cek SKEP Fasilitas
OLEObject lole_http
String ls_url, ls_response
Long ll_status

ls_url = gs_url + "/skep.php?npwp=" + gs_cmp_id

lole_http = CREATE OLEObject
IF lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP.6.0") <> 0 THEN
    lole_http.ConnectToNewObject("MSXML2.XMLHTTP")
END IF

lole_http.Open("GET", ls_url, False)
lole_http.Send()

ll_status = Long(lole_http.Status)
ls_response = String(lole_http.ResponseText)

lole_http.DisconnectObject()
DESTROY lole_http

// Format output: NomorSkep|TanggalSkep
MessageBox("Data SKEP", ls_response)</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- GETBC11.PHP -->
    <div class="doc-section" id="ep-getbc11">
        <div class="api-card">
            <div class="api-header">
                <span class="method-badge badge-get">GET</span>
                <span class="endpoint-url">/getbc11.php</span>
            </div>
            <p style="font-size:13px; color: var(--text-muted); margin-bottom:14px;">
                Mencari Pos dan Subpos manifes kedatangan sarana pengangkut (BC 1.1) berdasarkan Nomor &amp; Tanggal B/L.
            </p>
            <table class="param-table">
                <thead>
                    <tr><th>Parameter</th><th>Tipe</th><th>Wajib?</th><th>Keterangan</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>a</code></td><td>string</td><td>Wajib</td><td>Nomor Host B/L.</td></tr>
                    <tr><td><code>b</code></td><td>string</td><td>Wajib</td><td>Tanggal Host B/L (YYYY-MM-DD).</td></tr>
                    <tr><td><code>c</code></td><td>string</td><td>Opsional</td><td>Kode Kantor Pabean Bongkar (default: kode kantor perusahaan).</td></tr>
                    <tr><td><code>d</code></td><td>string</td><td>Opsional</td><td>Nama Perusahaan Importir.</td></tr>
                </tbody>
            </table>

            <h4 style="font-size:13px; margin: 16px 0 6px; color:var(--text-main);">Contoh Pemanggilan API:</h4>
            <div class="snippet-wrapper">
                <div class="snippet-tabs">
                    <button class="snippet-tab-btn active" data-mode="curl" onclick="setSnippetTab(this, 'curl')">🌐 cURL / HTTP</button>
                    <button class="snippet-tab-btn pb-tab" data-mode="pb" onclick="setSnippetTab(this, 'pb')">⚡ PowerBuilder (PowerScript)</button>
                </div>
                <div class="snippet-pane pane-curl active">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>curl -X GET "http://[server-ip]/getbc11.php?a=MSK123456&amp;b=2026-10-15&amp;c=040300&amp;cmp_id=010000000000000"</pre>
                    </div>
                </div>
                <div class="snippet-pane pane-pb" style="display:none;">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// PowerBuilder (PowerScript) - Cek Pos Manifes BC 1.1
OLEObject lole_http
String ls_url, ls_response
Long ll_status

ls_url = gs_url + "/getbc11.php?a=MSK123456&b=2026-10-15&c=040300&cmp_id=" + gs_cmp_id

lole_http = CREATE OLEObject
IF lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP.6.0") <> 0 THEN
    lole_http.ConnectToNewObject("MSXML2.XMLHTTP")
END IF

lole_http.Open("GET", ls_url, False)
lole_http.Send()

ll_status = Long(lole_http.Status)
ls_response = String(lole_http.ResponseText)

lole_http.DisconnectObject()
DESTROY lole_http

MessageBox("BC 1.1", ls_response)</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- GETBL.PHP (INKUIRI BC 1.1, JADWAL KAPAL & KONTAINER - POWERBUILDER & REST SERVICE) -->
    <div class="doc-section" id="ep-getbl">
        <div class="api-card">
            <div class="api-header">
                <span class="method-badge badge-get" style="background:#7c3aed; color:#fff;">PB / REST</span>
                <span class="endpoint-url">/getbl.php</span>
            </div>
            <p style="font-size:13px; color: var(--text-muted); margin-bottom:14px;">
                Layanan inkuiri terpadu Manifes BC 1.1, Jadwal Kedatangan Sarana Pengangkut (MapController), Peti Kemas (Container), dan Bill of Lading.<br>
                Mendukung output format <strong>TSV (Tab-Separated Values)</strong> yang siap diimpor langsung ke DataWindow PowerBuilder via <code>dw.ImportString(ls_response, 2, 999999, 1, 0, 0)</code>, maupun format <strong>JSON</strong> untuk REST Client.
            </p>
            <table class="param-table">
                <thead>
                    <tr><th>Parameter</th><th>Tipe</th><th>Wajib?</th><th>Keterangan</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>a</code></td><td>string</td><td>Kondisional</td><td>Nomor Host B/L atau Master B/L (contoh: <code>SUB600398100</code>). Digunakan untuk inkuiri B/L &amp; Pos BC 1.1.</td></tr>
                    <tr><td><code>action</code></td><td>string</td><td>Kondisional</td><td>Pilihan aksi: <code>search_vessel</code> (Jadwal kapal), <code>search_container</code> (Peti kemas), <code>manifest_detail</code> (Rincian header), atau <code>sync_to_manifest</code>.</td></tr>
                    <tr><td><code>vessel</code></td><td>string</td><td>Kondisional</td><td>Nama Kapal / Sarana Pengangkut (contoh: <code>SINAR SUNDA</code>, <code>MTT SANDAKAN</code>). Wajib jika <code>action=search_vessel</code>.</td></tr>
                    <tr><td><code>nomor_container</code></td><td>string</td><td>Kondisional</td><td>Nomor Peti Kemas (contoh: <code>NYKU3933307</code>). Wajib jika <code>action=search_container</code>.</td></tr>
                    <tr><td><code>id_header</code></td><td>string</td><td>Kondisional</td><td>UUID ID Header Manifes dari Bea Cukai. Wajib jika <code>action=manifest_detail</code>.</td></tr>
                    <tr><td><code>format</code></td><td>string</td><td>Opsional</td><td><code>tsv</code> untuk Tab-Delimited DataWindow ready, atau <code>json</code> (default).</td></tr>
                    <tr><td><code>cmp_id</code></td><td>string</td><td>Opsional</td><td>ID Perusahaan konteks multi-tenant (contoh: <code>TVL</code>).</td></tr>
                </tbody>
            </table>

            <h4 style="font-size:13px; margin: 16px 0 6px; color:var(--text-main);">Contoh Pemanggilan API:</h4>
            <div class="snippet-wrapper">
                <div class="snippet-tabs">
                    <button class="snippet-tab-btn active" data-mode="curl" onclick="setSnippetTab(this, 'curl')">🌐 cURL / HTTP</button>
                    <button class="snippet-tab-btn pb-tab" data-mode="pb" onclick="setSnippetTab(this, 'pb')">⚡ PowerBuilder (PowerScript)</button>
                </div>
                <div class="snippet-pane pane-curl active">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre># 1. Inkuiri Jadwal Kapal (Format TSV untuk DataWindow)
curl -X GET "http://[server-ip]/getbl.php?action=search_vessel&amp;vessel=SINAR+SUNDA&amp;format=tsv&amp;cmp_id=TVL"

# 2. Inkuiri Bill of Lading &amp; Pos BC 1.1 (Format JSON)
curl -X GET "http://[server-ip]/getbl.php?a=SUB600398100&amp;format=json&amp;cmp_id=TVL"

# 3. Inkuiri Peti Kemas (Format TSV)
curl -X GET "http://[server-ip]/getbl.php?action=search_container&amp;nomor_container=NYKU3933307&amp;format=tsv&amp;cmp_id=TVL"</pre>
                    </div>
                </div>
                <div class="snippet-pane pane-pb" style="display:none;">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// PowerBuilder (PowerScript) - Tarik Jadwal Kapal Langsung ke DataWindow
OLEObject lole_http
String ls_url, ls_response
Long ll_status, ll_rows

ls_url = "http://ceisaapi01.123456.id/getbl.php?action=search_vessel&vessel=SINAR+SUNDA&format=tsv&cmp_id=" + gs_cmp_id

lole_http = CREATE OLEObject
IF lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP.6.0") &lt;&gt; 0 THEN
    lole_http.ConnectToNewObject("MSXML2.XMLHTTP")
END IF

lole_http.Open("GET", ls_url, False)
lole_http.Send()

ll_status = Long(lole_http.Status)
IF ll_status = 200 THEN
    ls_response = String(lole_http.ResponseText)
    // Import langsung string TSV ke DataWindow (parameter 2 = mulai baris ke-2 / skip header)
    dw_vessel.Reset()
    ll_rows = dw_vessel.ImportString(ls_response, 2, 999999, 1, 0, 0)
    MessageBox("Sukses", "Berhasil menarik " + String(ll_rows) + " baris jadwal kapal.")
ELSE
    MessageBox("Error", "Gagal memanggil API: HTTP " + String(ll_status))
END IF

lole_http.DisconnectObject()
DESTROY lole_http</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- GETTPS.PHP -->
    <div class="doc-section" id="ep-gettps">
        <div class="api-card">
            <div class="api-header">
                <span class="method-badge badge-get">GET</span>
                <span class="endpoint-url">/gettps.php</span>
            </div>
            <p style="font-size:13px; color: var(--text-muted); margin-bottom:14px;">
                Mengambil daftar referensi Kode dan Nama TPS di wilayah kantor pabean tertentu.<br>
                Output: Tab-delimited (<code>KodeGudang \t NamaGudang \n</code>) siap diparsing oleh aplikasi klien.
            </p>
            <table class="param-table">
                <thead>
                    <tr><th>Parameter</th><th>Tipe</th><th>Wajib?</th><th>Keterangan</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>a</code></td><td>string</td><td>Wajib</td><td>Kode Kantor Pabean (contoh: <code>050100</code> Tanjung Priok, <code>050900</code> Cikarang).</td></tr>
                </tbody>
            </table>

            <h4 style="font-size:13px; margin: 16px 0 6px; color:var(--text-main);">Contoh Pemanggilan API:</h4>
            <div class="snippet-wrapper">
                <div class="snippet-tabs">
                    <button class="snippet-tab-btn active" data-mode="curl" onclick="setSnippetTab(this, 'curl')">🌐 cURL / HTTP</button>
                    <button class="snippet-tab-btn pb-tab" data-mode="pb" onclick="setSnippetTab(this, 'pb')">⚡ PowerBuilder (PowerScript)</button>
                </div>
                <div class="snippet-pane pane-curl active">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>curl -X GET "http://[server-ip]/gettps.php?a=050100&amp;cmp_id=010000000000000"</pre>
                    </div>
                </div>
                <div class="snippet-pane pane-pb" style="display:none;">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// PowerBuilder (PowerScript) - Ambil Daftar TPS ke DataWindow
OLEObject lole_http
String ls_url, ls_response
Long ll_status

ls_url = gs_url + "/gettps.php?a=050100&cmp_id=" + gs_cmp_id

lole_http = CREATE OLEObject
IF lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP.6.0") <> 0 THEN
    lole_http.ConnectToNewObject("MSXML2.XMLHTTP")
END IF

lole_http.Open("GET", ls_url, False)
lole_http.Send()

ll_status = Long(lole_http.Status)
ls_response = String(lole_http.ResponseText)

lole_http.DisconnectObject()
DESTROY lole_http

IF ll_status = 200 THEN
    // Tab-delimited string dapat diimport langsung ke DataWindow
    dw_tps.Reset()
    dw_tps.ImportString(ls_response)
END IF</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- CEKAJU.PHP -->
    <div class="doc-section" id="ep-cekaju">
        <div class="api-card">
            <div class="api-header">
                <span class="method-badge badge-get">GET</span>
                <span class="endpoint-url">/cekaju.php</span>
            </div>
            <p style="font-size:13px; color: var(--text-muted); margin-bottom:14px;">
                Melakukan penarikan browse seluruh dokumen pabean dari Portal CEISA 4.0 dan menyimpannya ke tabel <code>bc_ceisa_data</code>.
            </p>
            <table class="param-table">
                <thead>
                    <tr><th>Parameter</th><th>Tipe</th><th>Wajib?</th><th>Keterangan</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>bc</code></td><td>string</td><td>Opsional</td><td>Kode Dokumen Pabean (contoh: <code>23</code>, <code>25</code>, <code>27</code>, <code>30</code>). Kosongkan untuk semua.</td></tr>
                    <tr><td><code>page</code></td><td>int</td><td>Opsional</td><td>Halaman penarikan data (default: 1).</td></tr>
                    <tr><td><code>cmp_id</code></td><td>string</td><td>Opsional</td><td>Company ID perusahaan.</td></tr>
                </tbody>
            </table>

            <h4 style="font-size:13px; margin: 16px 0 6px; color:var(--text-main);">Contoh Pemanggilan API:</h4>
            <div class="snippet-wrapper">
                <div class="snippet-tabs">
                    <button class="snippet-tab-btn active" data-mode="curl" onclick="setSnippetTab(this, 'curl')">🌐 cURL / HTTP</button>
                    <button class="snippet-tab-btn pb-tab" data-mode="pb" onclick="setSnippetTab(this, 'pb')">⚡ PowerBuilder (PowerScript)</button>
                </div>
                <div class="snippet-pane pane-curl active">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>curl -X GET "http://[server-ip]/cekaju.php?bc=23&amp;page=1&amp;cmp_id=010000000000000"</pre>
                    </div>
                </div>
                <div class="snippet-pane pane-pb" style="display:none;">
                    <div class="code-block with-tabs">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<pre>// PowerBuilder (PowerScript) - Browse Sinkronisasi Dokumen Pabean
OLEObject lole_http
String ls_url, ls_response
Long ll_status

ls_url = gs_url + "/cekaju.php?bc=23&page=1&cmp_id=" + gs_cmp_id

lole_http = CREATE OLEObject
IF lole_http.ConnectToNewObject("MSXML2.ServerXMLHTTP.6.0") <> 0 THEN
    lole_http.ConnectToNewObject("MSXML2.XMLHTTP")
END IF

lole_http.Open("GET", ls_url, False)
lole_http.Send()

ll_status = Long(lole_http.Status)
ls_response = String(lole_http.ResponseText)

lole_http.DisconnectObject()
DESTROY lole_http

MessageBox("Cek AJU", "HTTP " + String(ll_status) + ":~r~n" + ls_response)</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
    function filterMenu() {
        var input = document.getElementById('searchInput').value.toLowerCase();
        var items = document.querySelectorAll('#menuList .menu-item');
        items.forEach(function(item) {
            var text = item.textContent.toLowerCase();
            item.style.display = text.indexOf(input) > -1 ? 'flex' : 'none';
        });
    }

    function copyCode(btn) {
        var pre = btn.nextElementSibling;
        var text = pre.innerText;
        navigator.clipboard.writeText(text).then(function() {
            var orig = btn.innerText;
            btn.innerText = 'Copied!';
            btn.style.background = '#10b981';
            btn.style.color = '#fff';
            setTimeout(function() {
                btn.innerText = orig;
                btn.style.background = '';
                btn.style.color = '';
            }, 1500);
        });
    }

    // Active link highlighting on scroll
    window.addEventListener('scroll', function() {
        var sections = document.querySelectorAll('.doc-section');
        var scrollPos = window.scrollY || document.documentElement.scrollTop;
        sections.forEach(function(sec) {
            if (sec.offsetTop - 100 <= scrollPos && (sec.offsetTop + sec.offsetHeight - 100) > scrollPos) {
                var id = sec.getAttribute('id');
                document.querySelectorAll('.sidebar-menu .menu-item').forEach(function(a) {
                    a.classList.remove('active');
                    if (a.getAttribute('href') === '#' + id) {
                        a.classList.add('active');
                    }
                });
            }
        });
    });

    function setSnippetTab(btn, mode) {
        var wrapper = btn.closest('.snippet-wrapper');
        wrapper.querySelectorAll('.snippet-tab-btn').forEach(function(b) {
            b.classList.remove('active');
        });
        btn.classList.add('active');

        wrapper.querySelectorAll('.snippet-pane').forEach(function(p) {
            p.style.display = p.classList.contains('pane-' + mode) ? 'block' : 'none';
        });
    }

    function setGlobalSnippetMode(mode) {
        localStorage.setItem('ceisa_snippet_mode', mode);
        document.querySelectorAll('.snippet-wrapper').forEach(function(wrapper) {
            wrapper.querySelectorAll('.snippet-tab-btn').forEach(function(b) {
                var isMatch = b.getAttribute('data-mode') === mode;
                b.classList.toggle('active', isMatch);
                if (isMatch && mode === 'pb') {
                    b.classList.add('pb-tab');
                } else {
                    b.classList.remove('pb-tab');
                }
            });
            wrapper.querySelectorAll('.snippet-pane').forEach(function(p) {
                p.style.display = p.classList.contains('pane-' + mode) ? 'block' : 'none';
            });
        });

        var bCurl = document.getElementById('btnCurlMode');
        var bPb = document.getElementById('btnPbMode');
        if (bCurl && bPb) {
            bCurl.className = 'mode-btn' + (mode === 'curl' ? ' active active-curl' : '');
            bPb.className = 'mode-btn' + (mode === 'pb' ? ' active' : '');
        }
    }

    // Auto load preferred snippet mode
    setTimeout(function() {
        var savedMode = localStorage.getItem('ceisa_snippet_mode') || 'pb';
        setGlobalSnippetMode(savedMode);
    }, 50);

    function setTheme(theme) {
        if (theme !== 'light' && theme !== 'dark') theme = 'light';
        document.body.setAttribute('data-theme', theme);
        localStorage.setItem('ceisa_theme', theme);
    }
    const savedTheme = localStorage.getItem('ceisa_theme') || 'light';
    setTheme(savedTheme);

    window.addEventListener('message', function(e) {
        if (e.data && e.data.type === 'SET_THEME') {
            setTheme(e.data.theme);
        }
    });
</script>

</body>
</html>

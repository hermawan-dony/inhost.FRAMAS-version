<?php
/**
 * CEISA 4.0 - Manifes BC 1.1 / NVOCC Portal & Management System (manifest.php)
 * Mengelola Pengajuan Manifes Host-to-Host OpenAPI Bea Cukai (Versi 1.0)
 * Mendukung Drafting, Cek Lengkap, Kirim, Rekon, Inquiry BC 1.1, dan Pencarian Pos Inward.
 */
session_start();
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/datalogin.php';

$isAdmin = !empty($_SESSION['is_admin']) || (!empty($_GET['is_admin']) && $_GET['is_admin'] == '1');
$sessionCmpId = $cmp_id ?: ($_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? ''));
$sessionCmpName = $namaperusahaan ?: ($_SESSION['company_name'] ?? 'Perusahaan');
$sessionNpwp = $npwp ?: ($_SESSION['company_npwp'] ?? '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manifes BC 1.1 / NVOCC - CEISA 4.0</title>
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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js"></script>
    <style>
        :root, body[data-theme="light"], html[data-theme="light"] body {
            /* Official CEISA 4.0 Light Theme (DEFAULT) */
            --bg-base: #f0f2f5;
            --bg-card: #ffffff;
            --bg-input: #ffffff;
            --border: #e8e8e8;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --accent: #52c41a;
            --warning: #faad14;
            --danger: #ff4d4f;
            --text-main: #262626;
            --text-muted: #8c8c8c;
            --code-bg: #f8fafc;
            color-scheme: light;
        }

        body[data-theme="dark"], html[data-theme="dark"] body {
            /* CEISA Dark Theme Option */
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --bg-input: #141414;
            --border: #303030;
            --primary: #177ddc;
            --primary-hover: #1890ff;
            --accent: #49aa19;
            --warning: #d89614;
            --danger: #a61d24;
            --text-main: rgba(255, 255, 255, 0.85);
            --text-muted: rgba(255, 255, 255, 0.45);
            --code-bg: #141414;
            color-scheme: dark;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg-base);
            color: var(--text-main);
            min-height: 100vh;
            padding: 8px 12px;
            font-size: 10.5px;
            line-height: 1.45;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* Top Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 8px;
            padding-bottom: 6px;
            border-bottom: 1px solid var(--border);
        }
        .page-title {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .page-title h1 {
            font-size: 12px;
            font-weight: 700;
            color: var(--primary);
            margin: 0;
        }
        .page-title p {
            font-size: 9.5px;
            color: var(--text-muted);
            margin: 0;
        }

        /* Nav Pills / Subtabs */
        .subtabs {
            display: flex;
            gap: 4px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 8px;
            overflow-x: auto;
        }
        .subtab-btn {
            background: transparent;
            border: none;
            border-bottom: 2px solid transparent;
            color: var(--text-muted);
            padding: 5px 10px;
            font-size: 10px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .subtab-btn:hover {
            color: var(--primary);
        }
        .subtab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        /* Cards & Containers */
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 3px;
            padding: 8px 10px;
            margin-bottom: 8px;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
            padding-bottom: 4px;
            border-bottom: 1px solid var(--border);
        }
        .card-title {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-heading, var(--text-main));
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 6px;
            margin-bottom: 8px;
        }
        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 3px;
            padding: 6px 10px;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .stat-card .label { font-size: 8.5px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; }
        .stat-card .value { font-size: 14px; font-weight: 700; color: var(--text-main); }
        .stat-card.primary .value { color: var(--primary); }
        .stat-card.accent .value { color: var(--accent); }
        .stat-card.warning .value { color: var(--warning); }

        /* Forms & Inputs */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 6px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .form-group label {
            font-size: 9px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
        }
        .form-control {
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 2px;
            padding: 1px 6px;
            color: var(--text-main);
            font-size: 10px;
            height: 22px;
            outline: none;
            transition: border-color 0.2s;
            font-family: inherit;
        }
        .form-control:focus {
            border-color: var(--primary);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 0 7px;
            height: 22px;
            border-radius: 2px;
            font-size: 10px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
            text-decoration: none;
            font-family: inherit;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-accent { background: var(--accent); color: #fff; }
        .btn-accent:hover { filter: brightness(0.9); }
        .btn-warning { background: var(--warning); color: #000; }
        .btn-warning:hover { filter: brightness(0.9); }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-danger:hover { filter: brightness(0.9); }
        .btn-secondary { background: var(--bg-input); border: 1px solid var(--border); color: var(--text-main); }
        .btn-secondary:hover { border-color: var(--primary); }
        .btn-sm { padding: 0 5px; height: 20px; font-size: 9px; }

        /* Tables */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }
        table.custom-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
        }
        table.custom-table th {
            background: var(--bg-input);
            color: var(--text-muted);
            text-transform: uppercase;
            font-size: 9px;
            font-weight: 700;
            padding: 4px 6px;
            border-bottom: 1px solid var(--border);
            text-align: left;
        }
        table.custom-table td {
            padding: 3px 6px;
            border-bottom: 1px solid var(--border);
            color: var(--text-main);
            vertical-align: middle;
        }
        table.custom-table tr:hover td {
            background: rgba(54, 153, 255, 0.04);
        }

        /* Status Badges */
        .badge {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 2px;
            font-size: 8.5px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .badge-draft { background: rgba(146, 146, 159, 0.2); color: var(--text-muted); }
        .badge-ready { background: rgba(54, 153, 255, 0.2); color: var(--primary); }
        .badge-terkirim { background: rgba(255, 168, 0, 0.2); color: var(--warning); }
        .badge-rekon { background: rgba(27, 197, 189, 0.2); color: var(--accent); }
        .badge-danger { background: rgba(246, 78, 96, 0.2); color: var(--danger); }

        /* Code Block */
        pre.code-view {
            background: var(--code-bg);
            border: 1px solid var(--border);
            border-radius: 3px;
            padding: 8px 10px;
            font-family: monospace;
            font-size: 10px;
            color: var(--text-main);
            overflow: auto;
            max-height: 400px;
            white-space: pre-wrap;
            word-break: break-all;
        }

        /* Modal */
        .modal {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.6);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            padding: 12px;
        }
        .modal-content {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 3px;
            width: 100%;
            max-width: 780px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 8px 20px rgba(0,0,0,0.3);
        }
        .modal-header {
            padding: 8px 12px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--bg-input);
        }
        .modal-body {
            padding: 10px 12px;
            overflow-y: auto;
            flex: 1;
        }
        .modal-footer {
            padding: 8px 12px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 6px;
            background: var(--bg-input);
        }
    </style>
</head>
<body data-theme="light">

    <!-- PAGE HEADER -->
    <div class="page-header">
        <div class="page-title">
            <span style="font-size: 24px;">🚢</span>
            <div>
                <h1>Manifes BC 1.1 / NVOCC (Host-to-Host OpenAPI)</h1>
                <p>Pengelolaan dan Rekonsiliasi Manifes Kedatangan Sarana Pengangkut CEISA 4.0 &middot; Perusahaan: <strong><?= htmlspecialchars($sessionCmpName) ?></strong> (<?= htmlspecialchars($sessionCmpId) ?>)</p>
            </div>
        </div>
        <div style="display:flex; gap:8px;">
            <button class="btn btn-secondary btn-sm" onclick="toggleTheme()">🌓 Ganti Tema</button>
            <button class="btn btn-primary" onclick="showTab('editor'); resetForm();">➕ Buat Manifes Baru</button>
        </div>
    </div>

    <!-- SUB TABS NAVIGATION -->
    <div class="subtabs">
        <button class="subtab-btn active" id="tabbtn-list" onclick="showTab('list')">📋 Daftar Manifes</button>
        <button class="subtab-btn" id="tabbtn-editor" onclick="showTab('editor')">✏️ Form Editor Manifes</button>
        <button class="subtab-btn" id="tabbtn-inquiry" onclick="showTab('inquiry')">🔍 Pencarian Pos BC 1.1 (Inward/Outward)</button>
        <button class="subtab-btn" id="tabbtn-json" onclick="showTab('json')">📄 JSON Payload Viewer</button>
    </div>

    <!-- ========================================================================= -->
    <!-- TAB 1: DAFTAR MANIFES                                                     -->
    <!-- ========================================================================= -->
    <div id="tab-list">
        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card primary">
                <span class="label">Total Manifes</span>
                <span class="value" id="stat-total">0</span>
            </div>
            <div class="stat-card">
                <span class="label">Draft</span>
                <span class="value" id="stat-draft">0</span>
            </div>
            <div class="stat-card warning">
                <span class="label">Ready / Siap Kirim</span>
                <span class="value" id="stat-ready">0</span>
            </div>
            <div class="stat-card">
                <span class="label">Terkirim</span>
                <span class="value" id="stat-terkirim">0</span>
            </div>
            <div class="stat-card accent">
                <span class="label">Selesai Rekon</span>
                <span class="value" id="stat-rekon">0</span>
            </div>
        </div>

        <!-- Filter & Search Toolbar -->
        <div class="card" style="padding:12px 16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                <div style="display:flex; gap:8px; align-items:center; flex:1; max-width:500px;">
                    <input type="text" id="searchKeyword" class="form-control" placeholder="Cari No Aju, Voyage, Sarana Pengangkut, ID NVOCC..." onkeyup="if(event.key==='Enter') loadManifestList()">
                    <button class="btn btn-secondary" onclick="loadManifestList()">🔍 Cari</button>
                </div>
                <div style="display:flex; gap:8px; align-items:center;">
                    <select id="filterStatus" class="form-control" onchange="loadManifestList()">
                        <option value="">Semua Status</option>
                        <option value="Draft">Draft</option>
                        <option value="Ready">Ready</option>
                        <option value="Terkirim">Terkirim</option>
                        <option value="Rekon">Rekon</option>
                    </select>
                    <button class="btn btn-secondary" onclick="loadManifestList()">🔄 Refresh</button>
                </div>
            </div>
        </div>

        <!-- Table List -->
        <div class="card">
            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th style="width:40px;">No</th>
                            <th>Nomor Aju</th>
                            <th>Sarana Pengangkut</th>
                            <th>No Voyage</th>
                            <th>Tgl Tiba</th>
                            <th>Jml HBL</th>
                            <th>Bruto (KG)</th>
                            <th>Status</th>
                            <th>No BC 1.1</th>
                            <th style="text-align:right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tableBodyManifest">
                        <tr><td colspan="10" style="text-align:center; padding:20px; color:var(--text-muted);">Memuat data manifes...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- TAB 2: FORM EDITOR MANIFES                                                -->
    <!-- ========================================================================= -->
    <div id="tab-editor" style="display:none;">
        <form id="formManifest" onsubmit="saveHeader(event)">
            <div class="card">
                <div class="card-header">
                    <span class="card-title">📦 Data Header Manifes NVOCC</span>
                    <div style="display:flex; gap:8px;">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="generateAutoAju()">🎲 Generate No Aju</button>
                        <button type="submit" class="btn btn-primary btn-sm">💾 Simpan Header</button>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Nomor Aju (26 Digit)</label>
                        <input type="text" id="m_nomor_aju" class="form-control" required placeholder="00900009192025012345678901">
                    </div>
                    <div class="form-group">
                        <label>Kode Kantor Pabean</label>
                        <input type="text" id="m_kode_kantor" class="form-control" value="009000" required>
                    </div>
                    <div class="form-group">
                        <label>ID Perusahaan (NPWP 16)</label>
                        <input type="text" id="m_id_perusahaan" class="form-control" value="<?= htmlspecialchars($sessionNpwp) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Nama Perusahaan NVOCC</label>
                        <input type="text" id="m_nama_perusahaan" class="form-control" value="<?= htmlspecialchars($sessionCmpName) ?>" required>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Alamat Perusahaan</label>
                        <input type="text" id="m_alamat_perusahaan" class="form-control" value="<?= htmlspecialchars($alamat) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Nomor Voyage / Penerbangan</label>
                        <input type="text" id="m_nomor_voyage" class="form-control" required placeholder="V.001">
                    </div>
                    <div class="form-group">
                        <label>Mode Pengangkut</label>
                        <select id="m_mode_pengangkut" class="form-control">
                            <option value="Laut">Laut (Kapal)</option>
                            <option value="Udara">Udara (Pesawat)</option>
                            <option value="Darat">Darat (Truk/Kereta)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Nama Sarana Pengangkut</label>
                        <input type="text" id="m_sarana_pengangkut" class="form-control" required placeholder="MV KAPAL LAUT">
                    </div>
                    <div class="form-group">
                        <label>IMO Number / Flight No</label>
                        <input type="text" id="m_imo_number" class="form-control" placeholder="IMO123456">
                    </div>
                    <div class="form-group">
                        <label>Call Sign</label>
                        <input type="text" id="m_call_sign" class="form-control" placeholder="CALLSIGN">
                    </div>
                    <div class="form-group">
                        <label>Bendera / Kode Negara</label>
                        <input type="text" id="m_kode_negara" class="form-control" value="PA" required>
                    </div>
                    <div class="form-group">
                        <label>Tanggal Berangkat</label>
                        <input type="datetime-local" id="m_tgl_berangkat" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Tanggal Tiba (Estimasi / Aktual)</label>
                        <input type="datetime-local" id="m_tgl_tiba" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Waktu Tempuh (Jam)</label>
                        <input type="text" id="m_flwaktu_tempuh" class="form-control" value="24">
                    </div>
                </div>
            </div>
        </form>

        <!-- Daftar House BL (HBL) -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">📜 Daftar House B/L (HBL) Terdaftar</span>
                <button type="button" class="btn btn-accent btn-sm" onclick="openModalBL()">➕ Tambah House B/L</button>
            </div>
            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>No Host BL</th>
                            <th>Tgl Host BL</th>
                            <th>No Master BL</th>
                            <th>Shipper (Pengirim)</th>
                            <th>Consignee (Penerima)</th>
                            <th>Bruto (KG)</th>
                            <th>Kemasan</th>
                            <th>Pel. Asal - Bongkar</th>
                            <th style="text-align:right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tableBodyHouseBl">
                        <tr><td colspan="10" style="text-align:center; padding:15px; color:var(--text-muted);">Belum ada data House BL. Silakan simpan Header terlebih dahulu kemudian klik tombol 'Tambah House B/L'.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Action Floating / Bottom Bar -->
        <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:12px;">
            <button type="button" class="btn btn-secondary" onclick="previewCurrentJson()">📄 Preview JSON CEISA</button>
            <button type="button" class="btn btn-warning" onclick="executeCekLengkap()">🔍 Cek Kelengkapan</button>
            <button type="button" class="btn btn-primary" onclick="submitDraftToCeisa()">🚀 Kirim Draft ke CEISA 4.0</button>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- TAB 3: PENCARIAN POS BC 1.1 (INWARD / OUTWARD)                             -->
    <!-- ========================================================================= -->
    <div id="tab-inquiry" style="display:none;">
        <div class="card">
            <div class="card-header">
                <span class="card-title">🔎 Pencarian Pos Manifes BC 1.1 Live (Inward / Outward)</span>
            </div>
            <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                <div class="form-group">
                    <label>Nomor B/L (Host BL atau Master BL)</label>
                    <input type="text" id="inq_no_bl" class="form-control" placeholder="Contoh: CAAE25031006 / SSLNSJKTCAA5124">
                </div>
                <div class="form-group">
                    <label>Tanggal B/L</label>
                    <input type="date" id="inq_tgl_bl" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label>Kode Kantor Bea Cukai</label>
                    <input type="text" id="inq_kantor" class="form-control" value="050100">
                </div>
                <div class="form-group" style="justify-content: flex-end;">
                    <label>&nbsp;</label>
                    <button class="btn btn-primary" onclick="cariPosBc11()">🚀 Cari Pos Manifes</button>
                </div>
            </div>
        </div>

        <div class="card" id="cardInquiryResult" style="display:none;">
            <div class="card-header">
                <span class="card-title">📋 Hasil Data Pos BC 1.1</span>
                <button class="btn btn-secondary btn-sm" onclick="salinDataKeClipboard()">📋 Salin JSON</button>
            </div>
            <div id="inquiryParsedResult" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap:12px; margin-bottom:16px;"></div>
            <pre class="code-view" id="inquiryRawResult"></pre>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- TAB 4: JSON PAYLOAD VIEWER                                                -->
    <!-- ========================================================================= -->
    <div id="tab-json" style="display:none;">
        <div class="card">
            <div class="card-header">
                <span class="card-title">📄 CEISA 4.0 Host-to-Host Manifes JSON Payload</span>
                <div style="display:flex; gap:8px;">
                    <button class="btn btn-secondary btn-sm" onclick="salinJsonViewer()">📋 Salin JSON</button>
                    <button class="btn btn-primary btn-sm" onclick="downloadJsonViewer()">💾 Download .JSON</button>
                </div>
            </div>
            <pre class="code-view" id="jsonViewerContent">// Pilih dokumen manifes atau klik tombol Preview JSON untuk melihat struktur payload OpenAPI.</pre>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL ADD / EDIT HOUSE B/L                                                -->
    <!-- ========================================================================= -->
    <div class="modal" id="modalHouseBl">
        <div class="modal-content">
            <div class="modal-header">
                <h3 style="font-size:14px; font-weight:700;">Tambah / Edit House B/L (HBL)</h3>
                <button type="button" onclick="closeModalBL()" style="background:transparent; border:none; color:var(--text-muted); font-size:16px; cursor:pointer;">✕</button>
            </div>
            <div class="modal-body">
                <form id="formHouseBl" onsubmit="saveHouseBl(event)">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>No Master B/L (MBL)</label>
                            <input type="text" id="bl_nomor_bl" class="form-control" required placeholder="MBL001">
                        </div>
                        <div class="form-group">
                            <label>Tanggal Master B/L</label>
                            <input type="date" id="bl_tgl_bl" class="form-control" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="form-group">
                            <label>No House B/L (HBL)</label>
                            <input type="text" id="bl_nomor_host_bl" class="form-control" required placeholder="HBL001">
                        </div>
                        <div class="form-group">
                            <label>Tanggal House B/L</label>
                            <input type="date" id="bl_tgl_host_bl" class="form-control" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Nama Shipper (Pengirim Luar Negeri)</label>
                            <input type="text" id="bl_nama_pengirim" class="form-control" required placeholder="NAMA SHIPPER">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Alamat Shipper</label>
                            <input type="text" id="bl_alamat_pengirim" class="form-control" required placeholder="ALAMAT SHIPPER">
                        </div>
                        <div class="form-group">
                            <label>NPWP Consignee (Penerima 16 Digit)</label>
                            <input type="text" id="bl_npwp_penerima" class="form-control" required value="<?= htmlspecialchars($sessionNpwp) ?>">
                        </div>
                        <div class="form-group">
                            <label>Nama Consignee</label>
                            <input type="text" id="bl_nama_penerima" class="form-control" required value="<?= htmlspecialchars($sessionCmpName) ?>">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Alamat Consignee</label>
                            <input type="text" id="bl_alamat_penerima" class="form-control" required value="<?= htmlspecialchars($alamat) ?>">
                        </div>
                        <div class="form-group">
                            <label>Berat Bruto (KG)</label>
                            <input type="number" step="0.01" id="bl_berat" class="form-control" required value="100.00">
                        </div>
                        <div class="form-group">
                            <label>Volume Dimensi (M3)</label>
                            <input type="number" step="0.01" id="bl_dimensi" class="form-control" value="0.00">
                        </div>
                        <div class="form-group">
                            <label>Jenis Kemasan</label>
                            <input type="text" id="bl_jenis_kemasan" class="form-control" value="CT" required>
                        </div>
                        <div class="form-group">
                            <label>Jumlah Kemasan</label>
                            <input type="number" id="bl_jumlah_kemasan" class="form-control" value="1" required>
                        </div>
                        <div class="form-group">
                            <label>Mother Vessel</label>
                            <input type="text" id="bl_mother_vessel" class="form-control" required placeholder="MV KAPAL PENGANGKUT">
                        </div>
                        <div class="form-group">
                            <label>Pelabuhan Asal</label>
                            <input type="text" id="bl_pelabuhan_asal" class="form-control" required placeholder="CNSHZ">
                        </div>
                        <div class="form-group">
                            <label>Pelabuhan Bongkar</label>
                            <input type="text" id="bl_pelabuhan_bongkar" class="form-control" required placeholder="IDTPP">
                        </div>
                        <div class="form-group">
                            <label>Pelabuhan Akhir</label>
                            <input type="text" id="bl_pelabuhan_akhir" class="form-control" required placeholder="IDTPP">
                        </div>
                    </div>

                    <!-- HS & Petikemas Quick Fields -->
                    <div style="margin-top:16px; padding-top:12px; border-top:1px solid var(--border);">
                        <h4 style="font-size:12px; font-weight:700; margin-bottom:8px; color:var(--primary);">📦 Uraian Barang (HS Code)</h4>
                        <div class="form-grid" style="grid-template-columns: 140px 1fr;">
                            <div class="form-group">
                                <label>Pos Tarif (HS)</label>
                                <input type="text" id="bl_kode_hs" class="form-control" value="0101" placeholder="4 Digit HS">
                            </div>
                            <div class="form-group">
                                <label>Uraian Barang</label>
                                <input type="text" id="bl_uraian_barang" class="form-control" value="ELECTRONIC GOODS" placeholder="Uraian barang spesifik...">
                            </div>
                        </div>
                    </div>

                    <div style="margin-top:16px; padding-top:12px; border-top:1px solid var(--border);">
                        <h4 style="font-size:12px; font-weight:700; margin-bottom:8px; color:var(--accent);">🚢 Nomor Petikemas (Container)</h4>
                        <div class="form-grid" style="grid-template-columns: 1fr 100px 100px 120px;">
                            <div class="form-group">
                                <label>No Container</label>
                                <input type="text" id="bl_no_container" class="form-control" placeholder="MSKU0000001">
                            </div>
                            <div class="form-group">
                                <label>Ukuran</label>
                                <select id="bl_ukuran_container" class="form-control">
                                    <option value="20">20 Feet</option>
                                    <option value="40">40 Feet</option>
                                    <option value="45">45 Feet</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Tipe</label>
                                <select id="bl_type_container" class="form-control">
                                    <option value="1">General</option>
                                    <option value="2">Reefer</option>
                                    <option value="3">Open Top</option>
                                    <option value="4">Tank</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Nomor Segel</label>
                                <input type="text" id="bl_nomor_segel" class="form-control" placeholder="SEAL01">
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer" style="margin-top:20px; padding:0; background:transparent;">
                        <button type="button" class="btn btn-secondary" onclick="closeModalBL()">Batal</button>
                        <button type="submit" class="btn btn-primary">💾 Simpan House B/L</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- JAVASCRIPT APPLICATION LOGIC -->
    <script>
        const activeCmpId = '<?= htmlspecialchars($sessionCmpId) ?>';
        let currentNomorAju = '';
        let lastInquiryData = null;

        // Auto Sync Theme from Parent
        function applyCurrentTheme(th) {
            if (!th) th = localStorage.getItem('ceisa_theme') || 'light';
            document.documentElement.setAttribute('data-theme', th);
            document.body.setAttribute('data-theme', th);
        }
        applyCurrentTheme();

        window.addEventListener('message', (e) => {
            if (e.data && e.data.type === 'SET_THEME') {
                applyCurrentTheme(e.data.theme);
            }
        });

        function toggleTheme() {
            const cur = document.body.getAttribute('data-theme') || 'light';
            const next = cur === 'dark' ? 'light' : 'dark';
            localStorage.setItem('ceisa_theme', next);
            applyCurrentTheme(next);
        }

        function showTab(tabId) {
            document.querySelectorAll('.subtab-btn').forEach(b => b.classList.remove('active'));
            const btn = document.getElementById('tabbtn-' + tabId);
            if (btn) btn.classList.add('active');

            ['list', 'editor', 'inquiry', 'json'].forEach(t => {
                const el = document.getElementById('tab-' + t);
                if (el) el.style.display = (t === tabId) ? 'block' : 'none';
            });

            if (tabId === 'list') loadManifestList();
        }

        // =========================================================================
        // 1. LOAD MANIFEST LIST
        // =========================================================================
        async function loadManifestList() {
            const kw = document.getElementById('searchKeyword').value;
            const st = document.getElementById('filterStatus').value;
            const tbody = document.getElementById('tableBodyManifest');
            tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:20px; color:var(--text-muted);">Memuat data...</td></tr>';

            try {
                const res = await fetch(`manifest_api.php?action=get_list&search=${encodeURIComponent(kw)}&status=${encodeURIComponent(st)}&cmp_id=${encodeURIComponent(activeCmpId)}`);
                const json = await res.json();

                if (json.status !== 'OK' || !json.data || json.data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:20px; color:var(--text-muted);">Belum ada data manifes. Klik "Buat Manifes Baru" untuk memulai.</td></tr>';
                    updateStats([]);
                    return;
                }

                updateStats(json.data);

                let html = '';
                json.data.forEach((r, idx) => {
                    const stt = r.status || 'Draft';
                    let badgeClass = 'badge-draft';
                    if (stt === 'Ready') badgeClass = 'badge-ready';
                    if (stt === 'Terkirim') badgeClass = 'badge-terkirim';
                    if (stt === 'Rekon') badgeClass = 'badge-rekon';

                    html += `<tr>
                        <td>${idx + 1}</td>
                        <td>
                            <strong>${r.nomor_aju}</strong>
                            <div style="font-size:11px; color:var(--text-muted);">${r.nama_perusahaan || ''}</div>
                        </td>
                        <td>${r.nama_sarana_pengangkut || '-'}</td>
                        <td>${r.nomor_voyage || '-'}</td>
                        <td>${r.tanggal_tiba ? r.tanggal_tiba.substring(0, 16) : '-'}</td>
                        <td><strong>${r.total_hbl || 0}</strong></td>
                        <td>${parseFloat(r.total_bruto || 0).toLocaleString('id-ID')}</td>
                        <td><span class="badge ${badgeClass}">${stt}</span></td>
                        <td>${r.nomor_bc11 ? `<strong>${r.nomor_bc11}</strong> (${r.tanggal_bc11 || ''})` : '<span style="color:var(--text-muted);">-</span>'}</td>
                        <td style="text-align:right; white-space:nowrap;">
                            <button class="btn btn-secondary btn-sm" onclick="editManifest('${r.nomor_aju}')" title="Edit Manifes">✏️</button>
                            <button class="btn btn-secondary btn-sm" onclick="viewJsonAju('${r.nomor_aju}')" title="Lihat JSON Payload">📄</button>
                            ${stt === 'Draft' ? `<button class="btn btn-warning btn-sm" onclick="actionCekLengkap('${r.nomor_aju}')" title="Cek Kelengkapan">🔍</button>` : ''}
                            ${stt === 'Ready' ? `<button class="btn btn-primary btn-sm" onclick="actionKirim('${r.nomor_aju}')" title="Kirim ke Bea Cukai">🚀 Kirim</button>` : ''}
                            ${stt === 'Terkirim' ? `<button class="btn btn-accent btn-sm" onclick="actionRekon('${r.nomor_aju}')" title="Rekonsiliasi (Pecah Pos)">🔄 Rekon</button>` : ''}
                            <button class="btn btn-secondary btn-sm" onclick="actionStatus('${r.nomor_aju}')" title="Cek Status Respon">⏱️</button>
                            <button class="btn btn-danger btn-sm" onclick="actionDelete('${r.nomor_aju}')" title="Hapus Manifes">🗑️</button>
                        </td>
                    </tr>`;
                });
                tbody.innerHTML = html;
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:20px; color:var(--danger);">Error memuat data: ${err.message}</td></tr>`;
            }
        }

        function updateStats(rows) {
            document.getElementById('stat-total').innerText = rows.length;
            let d = 0, rd = 0, tk = 0, rk = 0;
            rows.forEach(r => {
                const s = (r.status || '').toLowerCase();
                if (s === 'draft') d++;
                else if (s === 'ready') rd++;
                else if (s === 'terkirim') tk++;
                else if (s === 'rekon') rk++;
            });
            document.getElementById('stat-draft').innerText = d;
            document.getElementById('stat-ready').innerText = rd;
            document.getElementById('stat-terkirim').innerText = tk;
            document.getElementById('stat-rekon').innerText = rk;
        }

        // =========================================================================
        // 2. AUTO GENERATE NOMOR AJU
        // =========================================================================
        function generateAutoAju() {
            const ktr = document.getElementById('m_kode_kantor').value || '009000';
            const npwpClean = (document.getElementById('m_id_perusahaan').value || '0123456789012345').replace(/\D/g, '').substring(0, 10);
            const dateStr = new Date().toISOString().slice(0, 10).replace(/-/g, '');
            const rnd = Math.floor(100000 + Math.random() * 900000);
            const autoAju = `${ktr}${npwpClean}${dateStr}${rnd}`;
            document.getElementById('m_nomor_aju').value = autoAju;
            currentNomorAju = autoAju;
        }

        function resetForm() {
            document.getElementById('formManifest').reset();
            document.getElementById('tableBodyHouseBl').innerHTML = '<tr><td colspan="10" style="text-align:center; padding:15px; color:var(--text-muted);">Belum ada data House BL. Simpan Header terlebih dahulu.</td></tr>';
            generateAutoAju();
            const now = new Date();
            const tglBerangkat = new Date(now.getTime() - 24*3600*1000).toISOString().slice(0, 16);
            const tglTiba = now.toISOString().slice(0, 16);
            document.getElementById('m_tgl_berangkat').value = tglBerangkat;
            document.getElementById('m_tgl_tiba').value = tglTiba;
        }

        // =========================================================================
        // 3. EDIT MANIFEST DETAIL
        // =========================================================================
        async function editManifest(nomorAju) {
            currentNomorAju = nomorAju;
            showTab('editor');

            try {
                const res = await fetch(`manifest_api.php?action=get_detail&nomor_aju=${encodeURIComponent(nomorAju)}&cmp_id=${encodeURIComponent(activeCmpId)}`);
                const json = await res.json();
                if (json.status !== 'OK') {
                    showError('Gagal memuat detail manifes: ' + json.message);
                    return;
                }

                const h = json.header;
                document.getElementById('m_nomor_aju').value = h.nomor_aju || '';
                document.getElementById('m_kode_kantor').value = h.kode_kantor || '009000';
                document.getElementById('m_id_perusahaan').value = h.id_perusahaan || '';
                document.getElementById('m_nama_perusahaan').value = h.nama_perusahaan || '';
                document.getElementById('m_alamat_perusahaan').value = h.alamat_perusahaan || '';
                document.getElementById('m_nomor_voyage').value = h.nomor_voyage || '';
                document.getElementById('m_mode_pengangkut').value = h.mode_pengangkut || 'Laut';
                document.getElementById('m_sarana_pengangkut').value = h.nama_sarana_pengangkut || '';
                document.getElementById('m_imo_number').value = h.imo_number || '';
                document.getElementById('m_call_sign').value = h.call_sign || '';
                document.getElementById('m_kode_negara').value = h.kode_negara || 'PA';
                document.getElementById('m_flwaktu_tempuh').value = h.flwaktu_tempuh || '24';

                if (h.tanggal_berangkat) document.getElementById('m_tgl_berangkat').value = h.tanggal_berangkat.replace(' ', 'T').substring(0, 16);
                if (h.tanggal_tiba) document.getElementById('m_tgl_tiba').value = h.tanggal_tiba.replace(' ', 'T').substring(0, 16);

                // Render House BLs
                renderHouseBlTable(json.house_bls || []);
            } catch (err) {
                showError('Error: ' + err.message);
            }
        }

        function renderHouseBlTable(bls) {
            const tbody = document.getElementById('tableBodyHouseBl');
            if (!bls || bls.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:15px; color:var(--text-muted);">Belum ada data House BL. Klik tombol "Tambah House B/L" di atas.</td></tr>';
                return;
            }

            let html = '';
            bls.forEach((b, idx) => {
                html += `<tr>
                    <td>${idx + 1}</td>
                    <td><strong>${b.nomor_host_bl}</strong></td>
                    <td>${b.tanggal_host_bl || '-'}</td>
                    <td>${b.nomor_bl || '-'}</td>
                    <td>${b.nama_pengirim || '-'}</td>
                    <td>${b.nama_penerima || '-'}</td>
                    <td>${parseFloat(b.berat || 0).toLocaleString('id-ID')}</td>
                    <td>${b.jumlah_kemasan || 1} ${b.jenisKemasan || 'CT'}</td>
                    <td>${b.kode_pelabuhan_asal || ''} → ${b.kode_pelabuhan_bongkar || ''}</td>
                    <td style="text-align:right;">
                        <button class="btn btn-secondary btn-sm" onclick="editHouseBlModal('${b.nomor_host_bl}')">✏️ Edit</button>
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html;
        }

        // =========================================================================
        // 4. SAVE HEADER
        // =========================================================================
        async function saveHeader(e) {
            e.preventDefault();
            const fData = new FormData();
            fData.append('action', 'save_header');
            fData.append('cmp_id', activeCmpId);
            fData.append('nomor_aju', document.getElementById('m_nomor_aju').value);
            fData.append('kode_kantor', document.getElementById('m_kode_kantor').value);
            fData.append('id_perusahaan', document.getElementById('m_id_perusahaan').value);
            fData.append('nama_perusahaan', document.getElementById('m_nama_perusahaan').value);
            fData.append('alamat_perusahaan', document.getElementById('m_alamat_perusahaan').value);
            fData.append('nomor_voyage', document.getElementById('m_nomor_voyage').value);
            fData.append('mode_pengangkut', document.getElementById('m_mode_pengangkut').value);
            fData.append('nama_sarana_pengangkut', document.getElementById('m_sarana_pengangkut').value);
            fData.append('imo_number', document.getElementById('m_imo_number').value);
            fData.append('call_sign', document.getElementById('m_call_sign').value);
            fData.append('kode_negara', document.getElementById('m_kode_negara').value);
            fData.append('tanggal_berangkat', document.getElementById('m_tgl_berangkat').value);
            fData.append('tanggal_tiba', document.getElementById('m_tgl_tiba').value);
            fData.append('flwaktu_tempuh', document.getElementById('m_flwaktu_tempuh').value);

            try {
                const res = await fetch('manifest_api.php', { method: 'POST', body: fData });
                const json = await res.json();
                if (json.status === 'OK') {
                    currentNomorAju = json.nomor_aju;
                    showSuccess(json.message);
                } else {
                    showError(json.message);
                }
            } catch (err) {
                showError('Error: ' + err.message);
            }
        }

        // =========================================================================
        // 5. MODAL HOUSE BL
        // =========================================================================
        function openModalBL() {
            if (!document.getElementById('m_nomor_aju').value) {
                showWarning('Silakan isi dan simpan Header Manifes terlebih dahulu!');
                return;
            }
            document.getElementById('modalHouseBl').style.display = 'flex';
        }

        function closeModalBL() {
            document.getElementById('modalHouseBl').style.display = 'none';
        }

        async function saveHouseBl(e) {
            e.preventDefault();
            const aju = document.getElementById('m_nomor_aju').value;
            const fData = new FormData();
            fData.append('action', 'save_bl');
            fData.append('cmp_id', activeCmpId);
            fData.append('nomor_aju', aju);
            fData.append('nomor_bl', document.getElementById('bl_nomor_bl').value);
            fData.append('tanggal_bl', document.getElementById('bl_tgl_bl').value);
            fData.append('nomor_host_bl', document.getElementById('bl_nomor_host_bl').value);
            fData.append('tanggal_host_bl', document.getElementById('bl_tgl_host_bl').value);
            fData.append('nama_pengirim', document.getElementById('bl_nama_pengirim').value);
            fData.append('alamat_pengirim', document.getElementById('bl_alamat_pengirim').value);
            fData.append('npwp_penerima', document.getElementById('bl_npwp_penerima').value);
            fData.append('nama_penerima', document.getElementById('bl_nama_penerima').value);
            fData.append('alamat_penerima', document.getElementById('bl_alamat_penerima').value);
            fData.append('berat', document.getElementById('bl_berat').value);
            fData.append('dimensi', document.getElementById('bl_dimensi').value);
            fData.append('jenis_kemasan', document.getElementById('bl_jenis_kemasan').value);
            fData.append('jumlah_kemasan', document.getElementById('bl_jumlah_kemasan').value);
            fData.append('mother_vessel', document.getElementById('bl_mother_vessel').value);
            fData.append('kode_pelabuhan_asal', document.getElementById('bl_pelabuhan_asal').value);
            fData.append('kode_pelabuhan_bongkar', document.getElementById('bl_pelabuhan_bongkar').value);
            fData.append('kode_pelabuhan_akhir', document.getElementById('bl_pelabuhan_akhir').value);

            // Item HS
            const hsJson = [{
                kode_hs: document.getElementById('bl_kode_hs').value || '0101',
                uraian_barang: document.getElementById('bl_uraian_barang').value || 'BARANG IMPOR'
            }];
            fData.append('hs_items_json', JSON.stringify(hsJson));

            // Petikemas
            const noCont = document.getElementById('bl_no_container').value;
            if (noCont) {
                const contJson = [{
                    nomor_container: noCont,
                    ukuran_container: document.getElementById('bl_ukuran_container').value,
                    type_container: document.getElementById('bl_type_container').value,
                    nomor_segel: document.getElementById('bl_nomor_segel').value || 'SEAL01',
                    jenis_container: '7'
                }];
                fData.append('petikemas_json', JSON.stringify(contJson));
            }

            try {
                const res = await fetch('manifest_api.php', { method: 'POST', body: fData });
                const json = await res.json();
                if (json.status === 'OK') {
                    showSuccess(json.message);
                    closeModalBL();
                    editManifest(aju);
                } else {
                    showError(json.message);
                }
            } catch (err) {
                showError('Error: ' + err.message);
            }
        }

        // =========================================================================
        // 6. PREVIEW JSON VIEWER
        // =========================================================================
        async function previewCurrentJson() {
            const aju = document.getElementById('m_nomor_aju').value;
            if (!aju) {
                showWarning('Silakan pilih atau isi Nomor Aju terlebih dahulu!');
                return;
            }
            viewJsonAju(aju);
        }

        async function viewJsonAju(nomorAju) {
            showTab('json');
            const pre = document.getElementById('jsonViewerContent');
            pre.innerText = 'Membangun JSON CEISA 4.0...';

            try {
                const res = await fetch(`manifest_api.php?action=generate_json&nomor_aju=${encodeURIComponent(nomorAju)}&cmp_id=${encodeURIComponent(activeCmpId)}`);
                const json = await res.json();
                if (json.status === 'OK' || json.status === 'WARNING') {
                    pre.innerText = json.json;
                    if (json.errors && json.errors.length > 0) {
                        showWarning('Terdapat catatan validasi:<br>• ' + json.errors.join('<br>• '), 'Catatan Validasi');
                    }
                } else {
                    pre.innerText = 'Error: ' + json.message;
                }
            } catch (err) {
                pre.innerText = 'Error: ' + err.message;
            }
        }

        function salinJsonViewer() {
            const txt = document.getElementById('jsonViewerContent').innerText;
            navigator.clipboard.writeText(txt).then(() => showSuccess('JSON berhasil disalin ke clipboard!'));
        }

        function downloadJsonViewer() {
            const txt = document.getElementById('jsonViewerContent').innerText;
            const blob = new Blob([txt], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `Manifes_${currentNomorAju || 'draft'}.json`;
            a.click();
            URL.revokeObjectURL(url);
        }

        // =========================================================================
        // 7. API ACTIONS (DRAFT, CEK LENGKAP, KIRIM, REKON, STATUS, DELETE)
        // =========================================================================
        async function submitDraftToCeisa() {
            const aju = document.getElementById('m_nomor_aju').value;
            if (!aju) { showWarning('Nomor Aju tidak boleh kosong!'); return; }
            const ok = await showConfirm(`Kirim Draft Manifes untuk No Aju '${escapeHtml(aju)}' ke CEISA 4.0?`, 'Konfirmasi Kirim Draft');
            if (!ok) return;

            const fData = new FormData();
            fData.append('action', 'api_draft');
            fData.append('cmp_id', activeCmpId);
            fData.append('nomor_aju', aju);

            try {
                const res = await fetch('manifest_api.php', { method: 'POST', body: fData });
                const json = await res.json();
                if (json.status === 'OK') {
                    showSuccess(json.message);
                    loadManifestList();
                } else {
                    showError(json.message);
                }
            } catch (err) { showError('Error: ' + err.message); }
        }

        async function actionCekLengkap(nomorAju) {
            const fData = new FormData();
            fData.append('action', 'api_cek_lengkap');
            fData.append('cmp_id', activeCmpId);
            fData.append('nomor_aju', nomorAju);

            try {
                const res = await fetch('manifest_api.php', { method: 'POST', body: fData });
                const json = await res.json();
                if (json.status === 'OK') {
                    showSuccess(json.message);
                } else {
                    showError(json.message);
                }
                loadManifestList();
            } catch (err) { showError('Error: ' + err.message); }
        }

        async function actionKirim(nomorAju) {
            const ok = await showConfirm(
                `KIRIM MANIFES: Pastikan data sudah benar dan lengkap.<br><br>Kirim Manifes <strong>${escapeHtml(nomorAju)}</strong> ke Bea Cukai?`,
                'Konfirmasi Kirim Manifes',
                'Ya, Kirim Manifes',
                'Batal',
                'warning'
            );
            if (!ok) return;

            const fData = new FormData();
            fData.append('action', 'api_kirim');
            fData.append('cmp_id', activeCmpId);
            fData.append('nomor_aju', nomorAju);

            try {
                const res = await fetch('manifest_api.php', { method: 'POST', body: fData });
                const json = await res.json();
                if (json.status === 'OK') {
                    showSuccess(json.message);
                } else {
                    showError(json.message);
                }
                loadManifestList();
            } catch (err) { showError('Error: ' + err.message); }
        }

        async function actionRekon(nomorAju) {
            const ok = await showConfirm(
                `REKONSILIASI / PECAH POS:<br><br>Lakukan rekonsiliasi pos untuk No Aju <strong>${escapeHtml(nomorAju)}</strong>?`,
                'Konfirmasi Rekonsiliasi',
                'Ya, Lakukan Rekon',
                'Batal',
                'question'
            );
            if (!ok) return;

            const fData = new FormData();
            fData.append('action', 'api_rekon');
            fData.append('cmp_id', activeCmpId);
            fData.append('nomor_aju', nomorAju);

            try {
                const res = await fetch('manifest_api.php', { method: 'POST', body: fData });
                const json = await res.json();
                if (json.status === 'OK') {
                    showSuccess(json.message);
                } else {
                    showError(json.message);
                }
                loadManifestList();
            } catch (err) { showError('Error: ' + err.message); }
        }

        async function actionStatus(nomorAju) {
            try {
                const res = await fetch(`manifest_api.php?action=api_status&nomor_aju=${encodeURIComponent(nomorAju)}&cmp_id=${encodeURIComponent(activeCmpId)}`);
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire({
                        ...getSwalThemeOptions(),
                        title: 'Status Respon CEISA',
                        html: `<pre style="text-align:left; max-height:300px; overflow:auto; font-size:10px; background:#0b1120; color:#38bdf8; padding:10px; border-radius:4px;">${escapeHtml(JSON.stringify(json.data, null, 2))}</pre>`,
                        confirmButtonText: 'Tutup'
                    });
                    loadManifestList();
                } else {
                    showError(json.message);
                }
            } catch (err) { showError('Error: ' + err.message); }
        }

        async function actionDelete(nomorAju) {
            const ok = await showConfirm(
                `HAPUS MANIFES: Anda yakin ingin menghapus data Manifes <strong>${escapeHtml(nomorAju)}</strong>?`,
                'Konfirmasi Hapus Manifes',
                'Ya, Hapus Data',
                'Batal',
                'warning'
            );
            if (!ok) return;

            const fData = new FormData();
            fData.append('action', 'delete_manifes');
            fData.append('cmp_id', activeCmpId);
            fData.append('nomor_aju', nomorAju);

            try {
                const res = await fetch('manifest_api.php', { method: 'POST', body: fData });
                const json = await res.json();
                if (json.status === 'OK') {
                    showSuccess(json.message);
                } else {
                    showError(json.message);
                }
                loadManifestList();
            } catch (err) { showError('Error: ' + err.message); }
        }

        // =========================================================================
        // 8. PENCARIAN POS BC 1.1 INWARD LIVE
        // =========================================================================
        async function cariPosBc11() {
            const noBl = document.getElementById('inq_no_bl').value.trim();
            const tglBl = document.getElementById('inq_tgl_bl').value;
            const ktr = document.getElementById('inq_kantor').value.trim();

            if (!noBl) { showWarning('Nomor B/L tidak boleh kosong!'); return; }

            const card = document.getElementById('cardInquiryResult');
            const parsedDiv = document.getElementById('inquiryParsedResult');
            const rawPre = document.getElementById('inquiryRawResult');
            card.style.display = 'block';
            parsedDiv.innerHTML = 'Memuat pencarian pos...';
            rawPre.innerText = 'Menghubungi API Gateway Bea Cukai...';

            try {
                const res = await fetch(`manifest_api.php?action=api_inward&nomor_bl=${encodeURIComponent(noBl)}&tanggal_bl=${encodeURIComponent(tglBl)}&kode_kantor=${encodeURIComponent(ktr)}&cmp_id=${encodeURIComponent(activeCmpId)}`);
                const json = await res.json();
                lastInquiryData = json;
                rawPre.innerText = JSON.stringify(json, null, 2);

                if (json.status === 'OK' && json.data) {
                    let d = json.data;
                    if (Array.isArray(d)) d = d[0] || {};
                    if (d.data && Array.isArray(d.data)) d = d.data[0] || {};

                    parsedDiv.innerHTML = `
                        <div style="background:var(--bg-input); padding:10px 14px; border-radius:6px; border:1px solid var(--border);">
                            <div style="font-size:11px; color:var(--text-muted);">NOMOR DAFTAR BC 1.1</div>
                            <div style="font-size:16px; font-weight:700; color:var(--primary);">${d.nomorDaftar || '-'}</div>
                            <div style="font-size:12px; color:var(--text-muted);">${d.tanggalDaftar || ''}</div>
                        </div>
                        <div style="background:var(--bg-input); padding:10px 14px; border-radius:6px; border:1px solid var(--border);">
                            <div style="font-size:11px; color:var(--text-muted);">NOMOR POS / SUBPOS</div>
                            <div style="font-size:16px; font-weight:700; color:var(--accent);">${d.nomorPos || '-'}</div>
                            <div style="font-size:12px; color:var(--text-muted);">Kelompok: ${d.kodeKelompokPos || '01'}</div>
                        </div>
                        <div style="background:var(--bg-input); padding:10px 14px; border-radius:6px; border:1px solid var(--border);">
                            <div style="font-size:11px; color:var(--text-muted);">SARANA PENGANGKUT</div>
                            <div style="font-size:14px; font-weight:700;">${d.namaSaranaPengangkut || d.motherVessel || '-'}</div>
                            <div style="font-size:12px; color:var(--text-muted);">Voyage: ${d.nomorVoyage || '-'}</div>
                        </div>
                        <div style="background:var(--bg-input); padding:10px 14px; border-radius:6px; border:1px solid var(--border);">
                            <div style="font-size:11px; color:var(--text-muted);">PENERIMA (CONSIGNEE)</div>
                            <div style="font-size:13px; font-weight:700;">${d.namaPenerima || '-'}</div>
                            <div style="font-size:11px; color:var(--text-muted);">${d.npwpPenerima || ''}</div>
                        </div>
                        <div style="background:var(--bg-input); padding:10px 14px; border-radius:6px; border:1px solid var(--border);">
                            <div style="font-size:11px; color:var(--text-muted);">BRUTO & KEMASAN</div>
                            <div style="font-size:14px; font-weight:700;">${parseFloat(d.berat || 0).toLocaleString('id-ID')} KG</div>
                            <div style="font-size:12px; color:var(--text-muted);">${d.jumlahKemasan || 0} ${d.jenisKemasan || ''}</div>
                        </div>
                        <div style="background:var(--bg-input); padding:10px 14px; border-radius:6px; border:1px solid var(--border);">
                            <div style="font-size:11px; color:var(--text-muted);">STATUS TUTUP / PIB</div>
                            <div style="font-size:14px; font-weight:700; color:${d.flagTutup ? 'var(--warning)' : 'var(--text-muted)'};">${d.flagTutup ? `TUTUP (${d.flagTutup})` : 'BELUM TUTUP'}</div>
                            <div style="font-size:11px; color:var(--text-muted);">${d.nomorDokumenTutup || ''}</div>
                        </div>
                    `;
                } else {
                    parsedDiv.innerHTML = `<div style="grid-column: span 3; color:var(--danger); padding:10px;">${json.message || 'Data pos tidak ditemukan'}</div>`;
                }
            } catch (err) {
                rawPre.innerText = 'Error: ' + err.message;
            }
        }

        function salinDataKeClipboard() {
            if (!lastInquiryData) return;
            navigator.clipboard.writeText(JSON.stringify(lastInquiryData, null, 2)).then(() => showSuccess('Hasil pencarian disalin ke clipboard!'));
        }

        // Initialize on load
        window.addEventListener('DOMContentLoaded', () => {
            loadManifestList();
        });
    </script>
</body>
</html>

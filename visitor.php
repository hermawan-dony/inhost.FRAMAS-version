<?php
/**
 * CEISA 4.0 - Visitor & IP Activity Analytics Dashboard (visitor.php)
 * Menampilkan statistik pengunjung: IP Unik, Subdomain, Lokasi Geografis (Kota/Negara),
 * Frekuensi Kunjungan, dan Akun yang Berhasil Login.
 */

session_start();
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/visitor_logger.php';

// Pastikan pengunjung yang membuka /visitor tetap tercatat
recordVisitorAccess($koneksi, ['event' => 'visit']);

// Handle AJAX Request untuk Data Real-time & Export
$action = $_GET['action'] ?? '';

if ($action === 'data') {
    header('Content-Type: application/json; charset=utf-8');
    
    // 1. KPI Aggregates
    $kpiHits    = $koneksi->query("SELECT IFNULL(SUM(hit_count), 0) FROM bc_visitor_log")->fetch_row()[0] ?? 0;
    $kpiIps     = $koneksi->query("SELECT COUNT(DISTINCT ip_address) FROM bc_visitor_log")->fetch_row()[0] ?? 0;
    $kpiSubs    = $koneksi->query("SELECT COUNT(DISTINCT subdomain) FROM bc_visitor_log")->fetch_row()[0] ?? 0;
    $kpiUsers   = $koneksi->query("SELECT COUNT(DISTINCT user_id) FROM bc_visitor_log WHERE user_id IS NOT NULL AND user_id != ''")->fetch_row()[0] ?? 0;

    // 2. Breakdown per Subdomain
    $subRes = $koneksi->query("SELECT subdomain, COUNT(DISTINCT ip_address) as unique_ips, SUM(hit_count) as total_hits 
                               FROM bc_visitor_log 
                               GROUP BY subdomain 
                               ORDER BY total_hits DESC LIMIT 10");
    $subBreakdown = [];
    while ($r = $subRes->fetch_assoc()) {
        $subBreakdown[] = $r;
    }

    // 3. Breakdown per Kota / Lokasi
    $cityRes = $koneksi->query("SELECT city, country_name, country_code, COUNT(DISTINCT ip_address) as unique_ips, SUM(hit_count) as total_hits 
                                FROM bc_visitor_log 
                                WHERE city IS NOT NULL AND city != '' 
                                GROUP BY city, country_name, country_code 
                                ORDER BY total_hits DESC LIMIT 10");
    $cityBreakdown = [];
    while ($r = $cityRes->fetch_assoc()) {
        $cityBreakdown[] = $r;
    }

    // 4. Breakdown per Device & OS
    $devRes = $koneksi->query("SELECT device_type, COUNT(*) as cnt FROM bc_visitor_log GROUP BY device_type");
    $devBreakdown = [];
    while ($r = $devRes->fetch_assoc()) {
        $devBreakdown[] = $r;
    }

    // 5. Data List Lengkap
    $filterSub = trim($_GET['subdomain'] ?? '');
    $searchKw  = trim($_GET['q'] ?? '');
    
    $where = ["1=1"];
    if (!empty($filterSub) && $filterSub !== 'ALL') {
        $where[] = "subdomain = '" . $koneksi->real_escape_string($filterSub) . "'";
    }
    if (!empty($searchKw)) {
        $escKw = $koneksi->real_escape_string($searchKw);
        $where[] = "(ip_address LIKE '%$escKw%' OR subdomain LIKE '%$escKw%' OR city LIKE '%$escKw%' OR country_name LIKE '%$escKw%' OR isp LIKE '%$escKw%' OR user_id LIKE '%$escKw%' OR user_name LIKE '%$escKw%')";
    }

    $whereSql = implode(' AND ', $where);
    $listRes = $koneksi->query("SELECT * FROM bc_visitor_log WHERE $whereSql ORDER BY last_visit DESC LIMIT 300");
    $visitors = [];
    while ($r = $listRes->fetch_assoc()) {
        $visitors[] = $r;
    }

    // Ambil list unik subdomain untuk filter dropdown
    $subFilterList = [];
    $sRes = $koneksi->query("SELECT DISTINCT subdomain FROM bc_visitor_log ORDER BY subdomain ASC");
    while ($sr = $sRes->fetch_assoc()) {
        $subFilterList[] = $sr['subdomain'];
    }

    echo json_encode([
        'status' => 'OK',
        'kpi' => [
            'total_hits' => (int)$kpiHits,
            'unique_ips' => (int)$kpiIps,
            'subdomains' => (int)$kpiSubs,
            'users'      => (int)$kpiUsers
        ],
        'subdomains' => $subBreakdown,
        'cities'     => $cityBreakdown,
        'devices'    => $devBreakdown,
        'subFilterList' => $subFilterList,
        'visitors'   => $visitors
    ]);
    exit;
}

$themeParam = $_GET['theme'] ?? 'light';
if ($themeParam !== 'dark' && $themeParam !== 'light') $themeParam = 'light';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visitor & IP Analytics Log - CEISA 4.0</title>
    <link rel="icon" type="image/png" href="assets/media/logos/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --bg-body: #f4f6fa;
            --bg-card: #ffffff;
            --border-color: #e2e8f0;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
        }

        body.dark-mode {
            --bg-body: #0f172a;
            --bg-card: #1e293b;
            --border-color: #334155;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.3);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background-color: var(--bg-body);
            color: var(--text-main);
            min-height: 100vh;
            padding: 24px;
        }

        .container {
            max-width: 1440px;
            margin: 0 auto;
        }

        /* Top Header */
        .header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .header-title {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .header-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 22px;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .header-text h1 {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .header-text p {
            font-size: 12px;
            color: var(--text-muted);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 12.5px;
            font-weight: 500;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }
        .btn-primary:hover {
            background-color: var(--primary-hover);
        }

        .btn-outline {
            background-color: var(--bg-card);
            border-color: var(--border-color);
            color: var(--text-main);
        }
        .btn-outline:hover {
            background-color: rgba(59, 130, 246, 0.08);
            border-color: var(--primary);
        }

        /* KPI Cards Grid */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }

        .kpi-card {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--card-shadow);
            display: flex;
            align-items: center;
            gap: 16px;
            position: relative;
            overflow: hidden;
        }

        .kpi-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
        }

        .kpi-card.blue::after { background-color: #3b82f6; }
        .kpi-card.green::after { background-color: #10b981; }
        .kpi-card.purple::after { background-color: #8b5cf6; }
        .kpi-card.orange::after { background-color: #f59e0b; }

        .kpi-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .kpi-card.blue .kpi-icon { background: rgba(59, 130, 246, 0.12); color: #3b82f6; }
        .kpi-card.green .kpi-icon { background: rgba(16, 185, 129, 0.12); color: #10b981; }
        .kpi-card.purple .kpi-icon { background: rgba(139, 92, 246, 0.12); color: #8b5cf6; }
        .kpi-card.orange .kpi-icon { background: rgba(245, 158, 11, 0.12); color: #f59e0b; }

        .kpi-info {
            flex-grow: 1;
        }

        .kpi-label {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 500;
        }

        .kpi-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-main);
            margin-top: 4px;
        }

        /* Analytics Panels Grid */
        .panels-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .panel-card {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--card-shadow);
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-color);
        }

        .panel-title {
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .breakdown-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px dashed var(--border-color);
            font-size: 12.5px;
        }

        .breakdown-item:last-child {
            border-bottom: none;
        }

        .breakdown-name {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
        }

        .breakdown-stats {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 12px;
            color: var(--text-muted);
        }

        .badge-hits {
            background: rgba(59, 130, 246, 0.1);
            color: #2563eb;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11px;
        }

        /* Main Data Table Section */
        .table-section {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
        }

        .table-controls {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
        }

        .search-box {
            position: relative;
            flex: 1;
            max-width: 380px;
        }

        .search-box input {
            width: 100%;
            padding: 9px 12px 9px 36px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--bg-body);
            color: var(--text-main);
            font-size: 12.5px;
            outline: none;
            transition: all 0.2s;
        }

        .search-box input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 14px;
        }

        .filter-select {
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--bg-body);
            color: var(--text-main);
            font-size: 12.5px;
            outline: none;
            cursor: pointer;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
            text-align: left;
        }

        th {
            background-color: var(--bg-body);
            color: var(--text-muted);
            font-weight: 600;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
            letter-spacing: 0.3px;
        }

        td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }

        tr:hover td {
            background-color: rgba(59, 130, 246, 0.03);
        }

        .subdomain-tag {
            display: inline-block;
            background: #e0f2fe;
            color: #0369a1;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11.5px;
            border: 1px solid #bae6fd;
        }

        body.dark-mode .subdomain-tag {
            background: #082f49;
            color: #38bdf8;
            border-color: #0369a1;
        }

        .ip-badge {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .isp-text {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .user-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
            color: #059669;
            background: #d1fae5;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11.5px;
        }

        body.dark-mode .user-tag {
            background: #064e3b;
            color: #34d399;
        }

        .guest-tag {
            font-size: 11px;
            color: var(--text-muted);
            font-style: italic;
        }

        .table-footer {
            padding: 14px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: var(--text-muted);
            border-top: 1px solid var(--border-color);
        }

        /* Pulse Live Indicator */
        .live-dot {
            width: 8px;
            height: 8px;
            background-color: #10b981;
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
            animation: pulse-green 1.8s infinite;
        }

        @keyframes pulse-green {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }
    </style>
</head>
<body class="<?= ($themeParam === 'dark') ? 'dark-mode' : '' ?>">
    <div class="container">
        <!-- Top Bar Header -->
        <header class="header-bar">
            <div class="header-title">
                <div class="header-icon">
                    <i class="fa-solid fa-chart-line"></i>
                </div>
                <div class="header-text">
                    <h1>
                        Visitor & IP Analytics Log 
                        <span class="live-dot" title="Live Logging Active"></span>
                    </h1>
                    <p>Pemantauan Kunjungan Multi-Tenant, IP Unik, Subdomain Terbuka, & Lokasi Akses Geografis</p>
                </div>
            </div>

            <div class="header-actions">
                <button class="btn btn-outline" id="btnToggleTheme" onclick="toggleTheme()" title="Ubah Tema">
                    <i class="fa-solid fa-moon"></i> Tema
                </button>
                <button class="btn btn-outline" onclick="loadVisitorData()" title="Segarkan Data">
                    <i class="fa-solid fa-arrows-rotate" id="refreshIcon"></i> Refresh
                </button>
                <button class="btn btn-primary" onclick="exportToCsv()" title="Unduh CSV">
                    <i class="fa-solid fa-file-csv"></i> Export CSV
                </button>
                <a href="/" class="btn btn-outline" title="Kembali ke Portal">
                    <i class="fa-solid fa-house"></i> Portal
                </a>
            </div>
        </header>

        <!-- KPI Cards Grid -->
        <section class="kpi-grid">
            <div class="kpi-card blue">
                <div class="kpi-icon"><i class="fa-solid fa-eye"></i></div>
                <div class="kpi-info">
                    <div class="kpi-label">TOTAL KUNJUNGAN (HITS)</div>
                    <div class="kpi-value" id="kpiTotalHits">-</div>
                </div>
            </div>

            <div class="kpi-card green">
                <div class="kpi-icon"><i class="fa-solid fa-network-wired"></i></div>
                <div class="kpi-info">
                    <div class="kpi-label">TOTAL IP UNIK</div>
                    <div class="kpi-value" id="kpiUniqueIps">-</div>
                </div>
            </div>

            <div class="kpi-card purple">
                <div class="kpi-icon"><i class="fa-solid fa-globe"></i></div>
                <div class="kpi-info">
                    <div class="kpi-label">SUBDOMAIN TERAKSES</div>
                    <div class="kpi-value" id="kpiSubdomains">-</div>
                </div>
            </div>

            <div class="kpi-card orange">
                <div class="kpi-icon"><i class="fa-solid fa-user-check"></i></div>
                <div class="kpi-info">
                    <div class="kpi-label">AKUN TERIDENTIFIKASI</div>
                    <div class="kpi-value" id="kpiUsers">-</div>
                </div>
            </div>
        </section>

        <!-- Analytics Visual Panels Grid -->
        <section class="panels-grid">
            <!-- Panel Subdomain -->
            <div class="panel-card">
                <div class="panel-header">
                    <div class="panel-title"><i class="fa-solid fa-sitemap" style="color:#3b82f6;"></i> Kunjungan per Subdomain</div>
                    <span style="font-size:11.5px; color:var(--text-muted);">Top 10 Host</span>
                </div>
                <div id="subdomainListContainer">
                    <div style="padding:20px; text-align:center; color:var(--text-muted);">Memuat data...</div>
                </div>
            </div>

            <!-- Panel Lokasi / Kota -->
            <div class="panel-card">
                <div class="panel-header">
                    <div class="panel-title"><i class="fa-solid fa-map-location-dot" style="color:#10b981;"></i> Lokasi Pengunjung (Kota / Negara)</div>
                    <span style="font-size:11.5px; color:var(--text-muted);">GeoIP Analytics</span>
                </div>
                <div id="cityListContainer">
                    <div style="padding:20px; text-align:center; color:var(--text-muted);">Memuat data...</div>
                </div>
            </div>
        </section>

        <!-- Main Data Table -->
        <section class="table-section">
            <div class="table-controls">
                <div class="search-box">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="searchInput" placeholder="Cari IP, Subdomain, Kota, ISP, User..." oninput="filterTable()">
                </div>

                <div style="display:flex; gap:10px; align-items:center;">
                    <label for="subFilter" style="font-size:12px; color:var(--text-muted);">Filter Subdomain:</label>
                    <select id="subFilter" class="filter-select" onchange="filterTable()">
                        <option value="ALL">Semua Subdomain</option>
                    </select>
                </div>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th style="width:50px;">#</th>
                            <th>Subdomain</th>
                            <th>IP Address & Provider</th>
                            <th>Lokasi (Kota / Negara)</th>
                            <th>Akun Pengguna</th>
                            <th>Kunjungan</th>
                            <th>Perangkat & OS</th>
                            <th>Waktu Terakhir</th>
                            <th>Pertama Akses</th>
                        </tr>
                    </thead>
                    <tbody id="visitorTableBody">
                        <tr>
                            <td colspan="9" style="text-align:center; padding:30px; color:var(--text-muted);">Memuat daftar pengunjung...</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="table-footer">
                <div>Menampilkan <b id="filteredCount">0</b> dari <b id="totalCount">0</b> data entri log.</div>
                <div>Sinkronisasi Otomatis Setiap 30 Detik</div>
            </div>
        </section>
    </div>

    <script>
        let rawVisitors = [];

        async function loadVisitorData() {
            const icon = document.getElementById('refreshIcon');
            if (icon) icon.classList.add('fa-spin');

            try {
                const res = await fetch('visitor.php?action=data&_t=' + Date.now());
                const json = await res.json();
                if (json.status === 'OK') {
                    // Update KPI
                    document.getElementById('kpiTotalHits').textContent = Number(json.kpi.total_hits).toLocaleString('id-ID');
                    document.getElementById('kpiUniqueIps').textContent = Number(json.kpi.unique_ips).toLocaleString('id-ID');
                    document.getElementById('kpiSubdomains').textContent = Number(json.kpi.subdomains).toLocaleString('id-ID');
                    document.getElementById('kpiUsers').textContent = Number(json.kpi.users).toLocaleString('id-ID');

                    // Update Subdomain Breakdown
                    renderSubdomains(json.subdomains);

                    // Update City Breakdown
                    renderCities(json.cities);

                    // Update Subdomain Filter Dropdown
                    const subSelect = document.getElementById('subFilter');
                    const curVal = subSelect.value;
                    subSelect.innerHTML = '<option value="ALL">Semua Subdomain</option>';
                    (json.subFilterList || []).forEach(sub => {
                        const opt = document.createElement('option');
                        opt.value = sub;
                        opt.textContent = sub + (sub === 'root' ? ' (Portal Utama)' : '');
                        if (sub === curVal) opt.selected = true;
                        subSelect.appendChild(opt);
                    });

                    // Update Table Data
                    rawVisitors = json.visitors || [];
                    filterTable();
                }
            } catch (err) {
                console.error("Gagal memuat data pengunjung:", err);
            } finally {
                if (icon) icon.classList.remove('fa-spin');
            }
        }

        function renderSubdomains(list) {
            const wrap = document.getElementById('subdomainListContainer');
            if (!list || list.length === 0) {
                wrap.innerHTML = '<div style="padding:15px; color:var(--text-muted); text-align:center;">Belum ada kunjungan subdomain.</div>';
                return;
            }
            let html = '';
            list.forEach(item => {
                const label = item.subdomain === 'root' ? 'root (Portal Utama)' : item.subdomain;
                html += `
                    <div class="breakdown-item">
                        <div class="breakdown-name">
                            <span class="subdomain-tag">${escapeHtml(label)}</span>
                        </div>
                        <div class="breakdown-stats">
                            <span><i class="fa-solid fa-users" style="font-size:11px;"></i> ${item.unique_ips} IP Unik</span>
                            <span class="badge-hits">${Number(item.total_hits).toLocaleString('id-ID')} Hits</span>
                        </div>
                    </div>
                `;
            });
            wrap.innerHTML = html;
        }

        function renderCities(list) {
            const wrap = document.getElementById('cityListContainer');
            if (!list || list.length === 0) {
                wrap.innerHTML = '<div style="padding:15px; color:var(--text-muted); text-align:center;">Belum ada data geolokasi.</div>';
                return;
            }
            let html = '';
            list.forEach(item => {
                const cityStr = item.city || 'Tidak Diketahui';
                const countryStr = item.country_name || 'Indonesia';
                html += `
                    <div class="breakdown-item">
                        <div class="breakdown-name">
                            <i class="fa-solid fa-location-dot" style="color:#ef4444; font-size:13px;"></i>
                            <span>${escapeHtml(cityStr)}, <small style="color:var(--text-muted);">${escapeHtml(countryStr)}</small></span>
                        </div>
                        <div class="breakdown-stats">
                            <span><i class="fa-solid fa-network-wired" style="font-size:11px;"></i> ${item.unique_ips} IP</span>
                            <span class="badge-hits">${Number(item.total_hits).toLocaleString('id-ID')} Hits</span>
                        </div>
                    </div>
                `;
            });
            wrap.innerHTML = html;
        }

        function filterTable() {
            const search = (document.getElementById('searchInput').value || '').toLowerCase();
            const subFilter = document.getElementById('subFilter').value;

            const filtered = rawVisitors.filter(r => {
                const matchSub = (subFilter === 'ALL' || r.subdomain === subFilter);
                if (!matchSub) return false;

                if (!search) return true;
                const matchKw = (r.ip_address || '').toLowerCase().includes(search) ||
                                (r.subdomain || '').toLowerCase().includes(search) ||
                                (r.city || '').toLowerCase().includes(search) ||
                                (r.country_name || '').toLowerCase().includes(search) ||
                                (r.isp || '').toLowerCase().includes(search) ||
                                (r.user_id || '').toLowerCase().includes(search) ||
                                (r.user_name || '').toLowerCase().includes(search) ||
                                (r.browser || '').toLowerCase().includes(search) ||
                                (r.os || '').toLowerCase().includes(search);
                return matchKw;
            });

            renderTable(filtered);
            document.getElementById('filteredCount').textContent = filtered.length;
            document.getElementById('totalCount').textContent = rawVisitors.length;
        }

        function renderTable(list) {
            const tbody = document.getElementById('visitorTableBody');
            if (!list || list.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:30px; color:var(--text-muted);">Tidak ditemukan data pengunjung yang sesuai filter.</td></tr>';
                return;
            }

            let html = '';
            list.forEach((r, idx) => {
                const subLabel = r.subdomain === 'root' ? 'root (Portal)' : r.subdomain;
                const locStr = (r.city ? r.city + ', ' : '') + (r.country_name || 'Indonesia');
                
                let userHtml = '<span class="guest-tag">Pengunjung / Pre-login</span>';
                if (r.user_id) {
                    userHtml = `<span class="user-tag"><i class="fa-solid fa-user-shield"></i> ${escapeHtml(r.user_id)}</span>`;
                    if (r.user_name && r.user_name !== r.user_id) {
                        userHtml += `<div style="font-size:11px; color:var(--text-muted); margin-top:2px;">${escapeHtml(r.user_name)}</div>`;
                    }
                }

                html += `
                    <tr>
                        <td style="color:var(--text-muted);">${idx + 1}</td>
                        <td><span class="subdomain-tag">${escapeHtml(subLabel)}</span></td>
                        <td>
                            <div class="ip-badge"><i class="fa-solid fa-terminal" style="font-size:10px; color:var(--primary);"></i> ${escapeHtml(r.ip_address)}</div>
                            <div class="isp-text" title="${escapeHtml(r.isp || '')}">${escapeHtml(r.isp || 'Provider Publik')}</div>
                        </td>
                        <td>
                            <div style="font-weight:500;">${escapeHtml(locStr)}</div>
                            <div style="font-size:11px; color:var(--text-muted);">${escapeHtml(r.region_name || '')}</div>
                        </td>
                        <td>${userHtml}</td>
                        <td><span class="badge-hits">${Number(r.hit_count).toLocaleString('id-ID')} Kali</span></td>
                        <td>
                            <div style="font-weight:500;"><i class="fa-solid fa-laptop" style="font-size:11px; margin-right:4px;"></i> ${escapeHtml(r.os || 'OS Unknown')}</div>
                            <div style="font-size:11px; color:var(--text-muted);">${escapeHtml(r.browser || 'Browser')} (${escapeHtml(r.device_type || 'Desktop')})</div>
                        </td>
                        <td style="white-space:nowrap;">
                            <div style="font-weight:500;">${r.last_visit || '-'}</div>
                        </td>
                        <td style="white-space:nowrap; color:var(--text-muted);">
                            ${r.first_visit || '-'}
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function toggleTheme() {
            document.body.classList.toggle('dark-mode');
        }

        function exportToCsv() {
            if (!rawVisitors || rawVisitors.length === 0) {
                alert("Tidak ada data pengunjung untuk diekspor!");
                return;
            }
            let csv = "No,Subdomain,IP Address,ISP,Kota,Negara,User ID,User Name,Total Hits,OS,Browser,Device,Last Visit,First Visit\n";
            rawVisitors.forEach((r, idx) => {
                const row = [
                    idx + 1,
                    `"${r.subdomain || ''}"`,
                    `"${r.ip_address || ''}"`,
                    `"${(r.isp || '').replace(/"/g, '""')}"`,
                    `"${(r.city || '').replace(/"/g, '""')}"`,
                    `"${(r.country_name || '').replace(/"/g, '""')}"`,
                    `"${r.user_id || ''}"`,
                    `"${(r.user_name || '').replace(/"/g, '""')}"`,
                    r.hit_count || 1,
                    `"${r.os || ''}"`,
                    `"${r.browser || ''}"`,
                    `"${r.device_type || ''}"`,
                    `"${r.last_visit || ''}"`,
                    `"${r.first_visit || ''}"`
                ];
                csv += row.join(",") + "\n";
            });

            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement("a");
            const url = URL.createObjectURL(blob);
            link.setAttribute("href", url);
            link.setAttribute("download", "CEISA4_Visitor_Log_" + new Date().toISOString().slice(0,10) + ".csv");
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Auto load & Auto refresh every 30 seconds
        loadVisitorData();
        setInterval(loadVisitorData, 30000);
    </script>
</body>
</html>

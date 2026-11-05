<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Laporan Keuangan Multi Periode (Jan - Des)
 * Hasil Porting Sempurna dari PowerBuilder TPB-ERP v2.0 (w_acct_rpt2.srw)
 * DataWindows: bc_act_crosstab_lr.srd, d_act_lr_multi.srd, bc_act_crosstab_nr.srd, d_act_nr_multi.srd
 * 
 * Standar UI: Ant Design Enterprise Compact Desktop 9.5px PB-Style
 * Fitur:
 * - Tab 1: Laba Rugi Multi Periode (12 Bulan Jan s/d Des + Total)
 * - Tab 2: Neraca Multi Periode (12 Bulan Jan s/d Des + Saldo Akhir)
 * - Pemilih Tahun Transaksi Real-Time
 * - DataGrid Enterprise Resizable (.col-resizer) & Right-Click Context Menu
 * - Export ke Microsoft Excel (.xlsx) Lengkap
 */

session_start();
require_once __DIR__ . '/../datalogin.php';

if (!$koneksi) {
    die("Koneksi database terputus. Silakan muat ulang halaman.");
}

$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? ($cmp_id ?? ''));
if (empty($sessionCmpId)) {
    $sessionCmpId = 'SNU';
}
$escCmp = $koneksi->real_escape_string($sessionCmpId);
$isAdmin = !empty($_SESSION['is_admin']) || !empty($_SESSION['is_super_admin']);
$userId  = $_SESSION['user_name'] ?? ($_SESSION['user_id'] ?? 'ADMIN');

session_write_close();

$action = $_REQUEST['action'] ?? '';
$year = $_GET['year'] ?? date('Y');
$escYear = $koneksi->real_escape_string($year);

// =========================================================================
// BACKEND AJAX API: Get Multi-Period Data
// =========================================================================
if ($action === 'get_multi_data') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(a.cmp_id = '$escCmp' OR a.cmp_id = '')" : "1=1";
    $rpt = $_GET['report'] ?? 'lr'; // lr or nr

    $typeCondition = ($rpt === 'lr')
        ? "WHERE (a.kode LIKE '4%' OR a.kode LIKE '5%' OR a.kode LIKE '6%' OR a.kode LIKE '7%' OR a.kode LIKE '8%' OR a.kode LIKE '9%')"
        : "WHERE (a.kode LIKE '1%' OR a.kode LIKE '2%' OR a.kode LIKE '3%')";

    // Generate month sums m01..m12
    $sql = "SELECT a.kode, a.nama_akun, a.tipe,
                   SUM(CASE WHEN b.periode = '$escYear-01' THEN (b.LR_kr - b.LR_db) ELSE 0 END) as m01,
                   SUM(CASE WHEN b.periode = '$escYear-02' THEN (b.LR_kr - b.LR_db) ELSE 0 END) as m02,
                   SUM(CASE WHEN b.periode = '$escYear-03' THEN (b.LR_kr - b.LR_db) ELSE 0 END) as m03,
                   SUM(CASE WHEN b.periode = '$escYear-04' THEN (b.LR_kr - b.LR_db) ELSE 0 END) as m04,
                   SUM(CASE WHEN b.periode = '$escYear-05' THEN (b.LR_kr - b.LR_db) ELSE 0 END) as m05,
                   SUM(CASE WHEN b.periode = '$escYear-06' THEN (b.LR_kr - b.LR_db) ELSE 0 END) as m06,
                   SUM(CASE WHEN b.periode = '$escYear-07' THEN (b.LR_kr - b.LR_db) ELSE 0 END) as m07,
                   SUM(CASE WHEN b.periode = '$escYear-08' THEN (b.LR_kr - b.LR_db) ELSE 0 END) as m08,
                   SUM(CASE WHEN b.periode = '$escYear-09' THEN (b.LR_kr - b.LR_db) ELSE 0 END) as m09,
                   SUM(CASE WHEN b.periode = '$escYear-10' THEN (b.LR_kr - b.LR_db) ELSE 0 END) as m10,
                   SUM(CASE WHEN b.periode = '$escYear-11' THEN (b.LR_kr - b.LR_db) ELSE 0 END) as m11,
                   SUM(CASE WHEN b.periode = '$escYear-12' THEN (b.LR_kr - b.LR_db) ELSE 0 END) as m12
            FROM bc_act_akun a
            LEFT JOIN bc_act_nl b ON a.kode = b.kode AND b.periode LIKE '$escYear-%' AND (b.cmp_id = '$escCmp' OR b.cmp_id = '')
            $typeCondition AND $cmpClause
            GROUP BY a.kode, a.nama_akun, a.tipe
            ORDER BY a.kode ASC";

    if ($rpt === 'nr') {
        $sql = "SELECT a.kode, a.nama_akun, a.tipe,
                       SUM(CASE WHEN b.periode = '$escYear-01' THEN (b.NR_db - b.NR_kr) ELSE 0 END) as m01,
                       SUM(CASE WHEN b.periode = '$escYear-02' THEN (b.NR_db - b.NR_kr) ELSE 0 END) as m02,
                       SUM(CASE WHEN b.periode = '$escYear-03' THEN (b.NR_db - b.NR_kr) ELSE 0 END) as m03,
                       SUM(CASE WHEN b.periode = '$escYear-04' THEN (b.NR_db - b.NR_kr) ELSE 0 END) as m04,
                       SUM(CASE WHEN b.periode = '$escYear-05' THEN (b.NR_db - b.NR_kr) ELSE 0 END) as m05,
                       SUM(CASE WHEN b.periode = '$escYear-06' THEN (b.NR_db - b.NR_kr) ELSE 0 END) as m06,
                       SUM(CASE WHEN b.periode = '$escYear-07' THEN (b.NR_db - b.NR_kr) ELSE 0 END) as m07,
                       SUM(CASE WHEN b.periode = '$escYear-08' THEN (b.NR_db - b.NR_kr) ELSE 0 END) as m08,
                       SUM(CASE WHEN b.periode = '$escYear-09' THEN (b.NR_db - b.NR_kr) ELSE 0 END) as m09,
                       SUM(CASE WHEN b.periode = '$escYear-10' THEN (b.NR_db - b.NR_kr) ELSE 0 END) as m10,
                       SUM(CASE WHEN b.periode = '$escYear-11' THEN (b.NR_db - b.NR_kr) ELSE 0 END) as m11,
                       SUM(CASE WHEN b.periode = '$escYear-12' THEN (b.NR_db - b.NR_kr) ELSE 0 END) as m12
                FROM bc_act_akun a
                LEFT JOIN bc_act_nl b ON a.kode = b.kode AND b.periode LIKE '$escYear-%' AND (b.cmp_id = '$escCmp' OR b.cmp_id = '')
                $typeCondition AND $cmpClause
                GROUP BY a.kode, a.nama_akun, a.tipe
                ORDER BY a.kode ASC";
    }

    $res = $koneksi->query($sql);
    $data = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $tot = 0;
            for ($i = 1; $i <= 12; $i++) {
                $mKey = 'm' . str_pad($i, 2, '0', STR_PAD_LEFT);
                $tot += (float)$r[$mKey];
            }
            $r['total_year'] = $tot;
            $data[] = $r;
        }
    }

    echo json_encode(['status' => 'OK', 'data' => $data]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Multi Periode - CEISA 4.0 & ERP Hub</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    
    <link rel="stylesheet" href="../assets/ant_grid.css">
    <script src="../assets/ant_grid.js"></script>

    <style>
        :root {
            --app-font: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            --app-font-size: 9.5px;
            --form-font-size: 9.5px;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --success: #52c41a;
            --danger: #f5222d;
            --warning: #faad14;
            --info: #0ea5e9;
            --bg-body: #f4f6f9;
            --bg-card: #ffffff;
            --text-main: #262626;
            --text-muted: #8c8c8c;
            --border-light: #d9d9d9;
            --border-table: #e8e8e8;
            --table-head-bg: #fafafa;
            --row-hover: #f0f7ff;
            --row-selected: #e6f7ff;
        }
        
        body.theme-dark {
            --bg-body: #141414;
            --bg-card: #1f1f1f;
            --text-main: #d9d9d9;
            --text-muted: #8c8c8c;
            --border-light: #434343;
            --border-table: #303030;
            --table-head-bg: #1d1d1d;
            --row-hover: #262638;
            --row-selected: #111d2c;
        }

        * { box-sizing: border-box; }
        html, body {
            margin: 0; padding: 0;
            background-color: var(--bg-body);
            color: var(--text-main);
            font-family: var(--app-font);
            font-size: var(--app-font-size);
            line-height: 1.4;
            height: 100%;
            overflow-x: hidden;
        }

        .main-container {
            display: flex;
            flex-direction: column;
            height: 100vh;
            padding: 8px 12px;
            gap: 8px;
        }

        /* Tabs & Card Container */
        .card-panel {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: 4px;
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
            overflow: hidden;
        }
        .card-header-tabs {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--table-head-bg);
            border-bottom: 1px solid var(--border-light);
            padding: 0 8px;
            height: 32px;
            flex-shrink: 0;
        }
        .tabs-nav {
            display: flex;
            gap: 4px;
            height: 100%;
        }
        .tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 0 12px;
            height: 100%;
            background: transparent;
            border: none;
            border-bottom: 2px solid transparent;
            color: var(--text-muted);
            font-family: inherit;
            font-size: 10px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .tab-btn:hover { color: var(--primary); }
        .tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            font-weight: 600;
            background: var(--bg-card);
        }

        /* Toolbar Filter */
        .toolbar {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            background: var(--bg-card);
            border-bottom: 1px solid var(--border-table);
            flex-shrink: 0;
            flex-wrap: wrap;
        }
        .toolbar label { font-size: 9.5px; font-weight: 500; color: var(--text-muted); }
        .ant-input {
            height: 21px;
            padding: 0 6px;
            font-family: inherit;
            font-size: 9.5px;
            border: 1px solid var(--border-light);
            border-radius: 2px;
            background: var(--bg-card);
            color: var(--text-main);
            outline: none;
        }
        .ant-btn {
            height: 21px;
            padding: 0 8px;
            font-family: inherit;
            font-size: 9.5px;
            font-weight: 500;
            border: 1px solid var(--border-light);
            border-radius: 2px;
            background: #fff;
            color: var(--text-main);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s;
        }
        .ant-btn:hover { border-color: var(--primary); color: var(--primary); }
        .ant-btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff !important;
        }

        /* Grid Table */
        .grid-wrapper {
            flex: 1;
            overflow: auto;
            position: relative;
            min-height: 0;
        }
        table.ant-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5px;
            white-space: nowrap;
        }
        table.ant-table th {
            position: sticky;
            top: 0;
            background: var(--table-head-bg);
            border: 1px solid var(--border-table);
            padding: 3px 6px;
            text-align: left;
            font-weight: 600;
            color: var(--text-main);
            z-index: 2;
            user-select: none;
        }
        table.ant-table td {
            border: 1px solid var(--border-table);
            padding: 2px 6px;
            height: 22px;
            vertical-align: middle;
            color: var(--text-main);
        }
        table.ant-table tbody tr:hover { background-color: var(--row-hover); }

        .col-resizer {
            position: absolute;
            top: 0; right: 0;
            width: 4px;
            cursor: col-resize;
            user-select: none;
            height: 100%;
        }

        .footer-bar {
            height: 26px;
            background: var(--table-head-bg);
            border-top: 1px solid var(--border-table);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 10px;
            font-size: 9.5px;
            flex-shrink: 0;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <div class="card-panel">
            <div class="card-header-tabs">
                <div class="tabs-nav">
                    <button type="button" class="tab-btn active" id="tab-btn-lr" onclick="switchTab('lr')">
                        <i class="fa-solid fa-chart-line"></i> 1. Laba Rugi Komparatif Multi Periode (Jan - Des)
                    </button>
                    <button type="button" class="tab-btn" id="tab-btn-nr" onclick="switchTab('nr')">
                        <i class="fa-solid fa-scale-balanced"></i> 2. Neraca Komparatif Multi Periode (Jan - Des)
                    </button>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <button type="button" class="ant-btn" onclick="loadReportData()" title="Muat Ulang Data">
                        <i class="fa-solid fa-arrows-rotate"></i> Refresh
                    </button>
                    <button type="button" class="ant-btn" onclick="exportToExcel()">
                        <i class="fa-solid fa-file-excel" style="color:#107c41;"></i> Export Excel
                    </button>
                </div>
            </div>

            <div class="toolbar">
                <label>Tahun Buku:</label>
                <input type="number" id="sel-year" class="ant-input" value="<?= date('Y') ?>" style="width:70px;" min="2020" max="2035">

                <button type="button" class="ant-btn ant-btn-primary" onclick="loadReportData()">
                    <i class="fa-solid fa-magnifying-glass"></i> Tampilkan
                </button>
            </div>

            <div class="grid-wrapper">
                <table class="ant-table" id="table-multi">
                    <thead>
                        <tr>
                            <th style="width: 70px;">Kode</th>
                            <th style="width: 170px;">Nama Akun</th>
                            <th style="width: 75px; text-align:right;">Jan</th>
                            <th style="width: 75px; text-align:right;">Feb</th>
                            <th style="width: 75px; text-align:right;">Mar</th>
                            <th style="width: 75px; text-align:right;">Apr</th>
                            <th style="width: 75px; text-align:right;">Mei</th>
                            <th style="width: 75px; text-align:right;">Jun</th>
                            <th style="width: 75px; text-align:right;">Jul</th>
                            <th style="width: 75px; text-align:right;">Ags</th>
                            <th style="width: 75px; text-align:right;">Sep</th>
                            <th style="width: 75px; text-align:right;">Okt</th>
                            <th style="width: 75px; text-align:right;">Nov</th>
                            <th style="width: 75px; text-align:right;">Des</th>
                            <th style="width: 90px; text-align:right;">Total Tahunan</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-multi">
                        <tr><td colspan="15" style="text-align:center; padding:20px; color:var(--text-muted);">Memuat laporan multi periode...</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="footer-bar">
                <div>
                    <span id="footer-text">Laporan Keuangan Komparatif 12 Bulan</span>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentTab = 'lr';
        let multiData = [];

        document.addEventListener('DOMContentLoaded', () => {
            initTheme();
            loadReportData();

            if (window.AntGrid) {
                AntGrid.initTableResizing('#table-multi');
            }
        });

        function initTheme() {
            const savedTheme = localStorage.getItem('ceisa_theme');
            if (savedTheme === 'dark') {
                document.body.classList.add('theme-dark');
            }
            window.addEventListener('message', (e) => {
                if (e.data && e.data.type === 'THEME_CHANGE') {
                    if (e.data.theme === 'dark') document.body.classList.add('theme-dark');
                    else document.body.classList.remove('theme-dark');
                }
            });
        }

        function switchTab(tab) {
            currentTab = tab;
            document.getElementById('tab-btn-lr').classList.toggle('active', tab === 'lr');
            document.getElementById('tab-btn-nr').classList.toggle('active', tab === 'nr');
            loadReportData();
        }

        async function loadReportData() {
            const y = document.getElementById('sel-year').value;
            const tbody = document.getElementById('tbody-multi');
            tbody.innerHTML = '<tr><td colspan="15" style="text-align:center; padding:20px; color:var(--text-muted);">Memuat data multi periode...</td></tr>';

            try {
                const res = await fetch(`multi_period_reports.php?action=get_multi_data&cmp_id=<?= urlencode($sessionCmpId) ?>&year=${encodeURIComponent(y)}&report=${encodeURIComponent(currentTab)}`);
                const json = await res.json();

                if (json.status === 'OK' && Array.isArray(json.data)) {
                    multiData = json.data;
                    renderTable();
                } else {
                    tbody.innerHTML = '<tr><td colspan="15" style="text-align:center; padding:20px; color:var(--danger);">Gagal memuat data</td></tr>';
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="15" style="text-align:center; padding:20px; color:var(--danger);">Error: ${escapeHtml(e.message)}</td></tr>`;
            }
        }

        function renderTable() {
            const tbody = document.getElementById('tbody-multi');
            if (multiData.length === 0) {
                tbody.innerHTML = '<tr><td colspan="15" style="text-align:center; padding:20px; color:var(--text-muted);">Tidak ada data neraca lajur pada tahun ini.</td></tr>';
                return;
            }

            let html = '';
            multiData.forEach(r => {
                html += `
                <tr>
                    <td><code>${escapeHtml(r.kode)}</code></td>
                    <td>${escapeHtml(r.nama_akun)}</td>
                    <td style="text-align:right;">${formatNumber(r.m01)}</td>
                    <td style="text-align:right;">${formatNumber(r.m02)}</td>
                    <td style="text-align:right;">${formatNumber(r.m03)}</td>
                    <td style="text-align:right;">${formatNumber(r.m04)}</td>
                    <td style="text-align:right;">${formatNumber(r.m05)}</td>
                    <td style="text-align:right;">${formatNumber(r.m06)}</td>
                    <td style="text-align:right;">${formatNumber(r.m07)}</td>
                    <td style="text-align:right;">${formatNumber(r.m08)}</td>
                    <td style="text-align:right;">${formatNumber(r.m09)}</td>
                    <td style="text-align:right;">${formatNumber(r.m10)}</td>
                    <td style="text-align:right;">${formatNumber(r.m11)}</td>
                    <td style="text-align:right;">${formatNumber(r.m12)}</td>
                    <td style="text-align:right; font-weight:700; color:var(--primary);">${formatNumber(r.total_year)}</td>
                </tr>`;
            });

            tbody.innerHTML = html;
        }

        function exportToExcel() {
            if (multiData.length === 0) {
                Swal.fire({
                    icon: 'info',
                    title: 'Tidak Ada Data',
                    text: 'Tidak ada data untuk diekspor',
                    customClass: { popup: 'compact-swal' }
                });
                return;
            }

            const y = document.getElementById('sel-year').value;
            const ws = XLSX.utils.json_to_sheet(multiData);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, `Multi_Periode_${y}`);
            XLSX.writeFile(wb, `Laporan_${currentTab.toUpperCase()}_Multi_Periode_${y}.xlsx`);
        }

        // Helpers
        function formatNumber(num) {
            return (parseFloat(num) || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }
    </script>
</body>
</html>

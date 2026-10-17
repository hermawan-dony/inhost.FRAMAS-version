<?php
/**
 * CEISA 4.0 - Master BOM / Bill of Materials (bom.php)
 * Mengadopsi w_bom_xls.srw & d_ceisa_bom.srd
 * Menghubungkan Barang Jadi (Parent) dengan Komponen / Bahan Baku (Child)
 * Dilengkapi fitur Import & Export Excel (.xlsx)
 */
session_start();
require_once __DIR__ . '/koneksi.php';

$isAdmin = !empty($_SESSION['is_admin']) || (isset($_GET['is_admin']) && $_GET['is_admin'] === '1');
$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_GET['cmp_id'] ?? '');

$action = $_REQUEST['action'] ?? '';

// 1. AJAX: LIST DATA BOM
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $search = trim($_GET['q'] ?? '');
    $parent = trim($_GET['parent'] ?? '');

    $where = [];
    if (!empty($parent)) {
        $where[] = "b.kode_barang = '" . $koneksi->real_escape_string($parent) . "'";
    }
    if (!empty($search)) {
        $s = $koneksi->real_escape_string($search);
        $where[] = "(b.kode_barang LIKE '%$s%' OR b.komponen LIKE '%$s%' OR m_fg.URAIAN LIKE '%$s%' OR m_comp.URAIAN LIKE '%$s%')";
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    $sql = "SELECT 
        b.cmp_id, b.kode_barang, b.style, b.komponen, b.req_qty, b.upd_time,
        IFNULL(m_fg.URAIAN, b.kode_barang) AS nama_parent,
        IFNULL(m_fg.KODE_SATUAN, 'PCE') AS sat_parent,
        IFNULL(m_comp.URAIAN, b.komponen) AS nama_komponen,
        IFNULL(m_comp.KODE_SATUAN, 'PCE') AS sat_komponen,
        IFNULL(m_comp.KLAS, 'Raw Materials') AS klas_komponen
    FROM bc_ceisa_bom b
    LEFT JOIN bc_material m_fg ON b.kode_barang = m_fg.KODE_BARANG
    LEFT JOIN bc_material m_comp ON b.komponen = m_comp.KODE_BARANG
    $whereSql
    ORDER BY b.kode_barang ASC, b.komponen ASC
    LIMIT 300";

    $res = $koneksi->query($sql);
    $data = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
        }
    }

    // Ambil daftar unik Finished Goods yang punya BOM
    $qFg = $koneksi->query("SELECT DISTINCT b.kode_barang, IFNULL(m.URAIAN, b.kode_barang) as uraian FROM bc_ceisa_bom b LEFT JOIN bc_material m ON b.kode_barang = m.KODE_BARANG ORDER BY b.kode_barang ASC");
    $fgList = [];
    if ($qFg) {
        while ($r = $qFg->fetch_assoc()) {
            $fgList[] = $r;
        }
    }

    echo json_encode(['status' => 'OK', 'data' => $data, 'count' => count($data), 'parents' => $fgList]);
    exit;
}

// 2. AJAX: SIMPAN / TAMBAH BOM SATUAN
if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    $cmp_id   = trim($_POST['cmp_id'] ?? $sessionCmpId);
    $parent   = strtoupper(trim($_POST['kode_barang'] ?? ''));
    $style    = trim($_POST['style'] ?? '-');
    $komponen = strtoupper(trim($_POST['komponen'] ?? ''));
    $qty      = floatval($_POST['req_qty'] ?? '1');

    if (empty($parent) || empty($komponen)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Barang Jadi (Parent) dan Komponen wajib diisi.']);
        exit;
    }

    $escCmp   = $koneksi->real_escape_string($cmp_id);
    $escPar   = $koneksi->real_escape_string($parent);
    $escSty   = $koneksi->real_escape_string($style);
    $escKomp  = $koneksi->real_escape_string($komponen);

    $sql = "INSERT INTO bc_ceisa_bom (cmp_id, kode_barang, style, komponen, req_qty, upd_time)
            VALUES ('$escCmp', '$escPar', '$escSty', '$escKomp', $qty, NOW())
            ON DUPLICATE KEY UPDATE req_qty = $qty, upd_time = NOW()";

    if ($koneksi->query($sql)) {
        echo json_encode(['status' => 'OK', 'message' => 'Komponen BOM berhasil disimpan!']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => $koneksi->error]);
    }
    exit;
}

// 3. AJAX: IMPORT DATA DARI EXCEL (BATCH UPSERT BOM)
if ($action === 'import_excel') {
    header('Content-Type: application/json; charset=utf-8');
    $rowsJson = $_POST['rows'] ?? '[]';
    $rows = json_decode($rowsJson, true);
    if (!is_array($rows) || empty($rows)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Data impor kosong atau format salah.']);
        exit;
    }

    $inserted = 0;
    $updated  = 0;
    foreach ($rows as $r) {
        $parent   = strtoupper(trim($r['kode_barang'] ?? ($r['KODE_BARANG'] ?? ($r['Kode Barang Jadi'] ?? ($r['Kode Parent'] ?? '')))));
        $komponen = strtoupper(trim($r['komponen'] ?? ($r['KOMPONEN'] ?? ($r['Kode Komponen'] ?? ($r['Kode Bahan'] ?? '')))));
        $qty      = floatval($r['req_qty'] ?? ($r['REQ_QTY'] ?? ($r['Kebutuhan'] ?? ($r['Qty'] ?? 1))));
        $style    = trim($r['style'] ?? ($r['STYLE'] ?? ($r['Style'] ?? '-')));

        if (empty($parent) || empty($komponen) || $qty <= 0) continue;

        $escCmp   = $koneksi->real_escape_string($sessionCmpId);
        $escPar   = $koneksi->real_escape_string($parent);
        $escSty   = $koneksi->real_escape_string($style);
        $escKomp  = $koneksi->real_escape_string($komponen);

        $sql = "INSERT INTO bc_ceisa_bom (cmp_id, kode_barang, style, komponen, req_qty, upd_time)
                VALUES ('$escCmp', '$escPar', '$escSty', '$escKomp', $qty, NOW())
                ON DUPLICATE KEY UPDATE req_qty = VALUES(req_qty), upd_time = NOW()";

        if ($koneksi->query($sql)) {
            if ($koneksi->affected_rows === 1) $inserted++;
            else $updated++;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'message' => "Proses Impor BOM Berhasil: $inserted item baru, $updated item diperbarui.",
        'inserted' => $inserted,
        'updated' => $updated
    ]);
    exit;
}

// 4. AJAX: HAPUS BOM
if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $parent   = strtoupper(trim($_POST['kode_barang'] ?? ''));
    $komponen = strtoupper(trim($_POST['komponen'] ?? ''));

    $escPar   = $koneksi->real_escape_string($parent);
    $escKomp  = $koneksi->real_escape_string($komponen);

    $koneksi->query("DELETE FROM bc_ceisa_bom WHERE kode_barang = '$escPar' AND komponen = '$escKomp'");
    echo json_encode(['status' => 'OK', 'message' => 'Komponen BOM berhasil dihapus.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master BOM - INHOST CEISA 4.0</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root, html[data-theme="light"] {
            --bg-base: #f0f2f5;
            --bg-card: #ffffff;
            --text-main: #262626;
            --text-muted: #8c8c8c;
            --border-color: #d9d9d9;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --danger: #ff4d4f;
            --success: #52c41a;
            --warning: #faad14;
            --row-hover: #fafafa;
            --table-header: #f0f2f5;
        }
        html[data-theme="dark"] {
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --text-main: #d9d9d9;
            --text-muted: #737373;
            --border-color: #303030;
            --primary: #177ddc;
            --primary-hover: #3c9ae8;
            --danger: #a61d24;
            --success: #49aa19;
            --warning: #d89614;
            --row-hover: #262626;
            --table-header: #141414;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: var(--bg-base); color: var(--text-main); font-family: 'Public Sans', sans-serif; font-size: 11.5px; padding: 12px; }
        .ant-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); overflow: hidden; padding: 12px; }
        .ant-card-head { padding-bottom: 10px; margin-bottom: 12px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
        .ant-card-title { font-size: 13.5px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
        .ant-toolbar { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .ant-btn { padding: 5px 12px; font-size: 11.5px; border-radius: 4px; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main); cursor: pointer; display: inline-flex; align-items: center; gap: 6px; font-weight: 500; }
        .ant-btn:hover { border-color: var(--primary); color: var(--primary); }
        .ant-btn-primary { background: var(--primary); color: #fff; border-color: var(--primary); }
        .ant-btn-primary:hover { background: var(--primary-hover); color:#fff; }
        .ant-btn-success { background: var(--success); color: #fff; border-color: var(--success); }
        .ant-btn-success:hover { background: #73d13d; color:#fff; }
        .filter-panel { padding: 8px 10px; background: var(--bg-base); border-radius: 4px; border: 1px solid var(--border-color); margin-bottom: 12px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .form-control { padding: 5px 8px; font-size: 11.5px; border: 1px solid var(--border-color); border-radius: 4px; background: var(--bg-card); color: var(--text-main); outline: none; }
        .table-responsive { max-height: calc(100vh - 200px); overflow: auto; border: 1px solid var(--border-color); border-radius: 4px; }
        table.ant-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 11px; }
        table.ant-table th { position: sticky; top: 0; background: var(--table-header); color: var(--text-main); font-weight: 600; padding: 7px 10px; border-bottom: 1px solid var(--border-color); white-space: nowrap; z-index: 2; }
        table.ant-table td { padding: 6px 10px; border-bottom: 1px solid var(--border-color); white-space: nowrap; }
        table.ant-table tbody tr:hover { background: var(--row-hover); }
        
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal-content { background: var(--bg-card); width: 90%; max-width: 600px; border-radius: 6px; border: 1px solid var(--border-color); box-shadow: 0 4px 12px rgba(0,0,0,0.15); overflow: hidden; display: flex; flex-direction: column; }
        .modal-header { padding: 10px 14px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; font-size: 13px; font-weight: 700; }
        .modal-body { padding: 14px; overflow-y: auto; flex: 1; }
        .modal-footer { padding: 8px 14px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 8px; }
        .form-group { display: flex; flex-direction: column; gap: 4px; margin-bottom: 10px; }
        .form-group label { font-size: 10.5px; font-weight: 600; color: var(--text-muted); }
        .dropzone { border: 2px dashed var(--border-color); border-radius: 6px; padding: 25px; text-align: center; cursor: pointer; background: var(--bg-base); transition: 0.2s; margin-bottom: 12px; }
        .dropzone:hover { border-color: var(--primary); }
    </style>
</head>
<body>
    <div class="ant-card">
        <div class="ant-card-head">
            <div class="ant-card-title">
                <i class="fa-solid fa-puzzle-piece" style="color:var(--primary);"></i> Master Bill of Materials / BOM (bc_ceisa_bom)
            </div>
            <div class="ant-toolbar">
                <button type="button" class="ant-btn ant-btn-primary" onclick="openAddModal()">
                    <i class="fa-solid fa-plus"></i> Tambah Komponen BOM
                </button>
                <button type="button" class="ant-btn ant-btn-success" onclick="openImportModal()">
                    <i class="fa-solid fa-file-excel"></i> Import Excel
                </button>
                <button type="button" class="ant-btn" onclick="exportExcel()">
                    <i class="fa-solid fa-file-export"></i> Ekspor Excel
                </button>
                <button type="button" class="ant-btn" onclick="loadData()">
                    <i class="fa-solid fa-rotate"></i> Muat Ulang
                </button>
            </div>
        </div>

        <!-- Filter Panel -->
        <div class="filter-panel">
            <div style="display:flex; align-items:center; gap:6px;">
                <label style="font-weight:600;"><i class="fa-solid fa-filter"></i> Pilih Barang Jadi:</label>
                <select id="filterParent" class="form-control" style="min-width: 200px;" onchange="loadData()">
                    <option value="">-- Semua Barang Jadi --</option>
                </select>
            </div>
            <div style="display:flex; align-items:center; gap:6px;">
                <input type="text" id="filterSearch" class="form-control" style="width: 200px;" placeholder="Cari kode/nama komponen..." onkeyup="if(event.key==='Enter') loadData()">
            </div>
            <button type="button" class="ant-btn ant-btn-primary" onclick="loadData()"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
        </div>

        <!-- Table -->
        <div class="table-responsive">
            <table class="ant-table">
                <thead>
                    <tr>
                        <th style="width: 35px; text-align: center;">#</th>
                        <th>Kode Barang Jadi</th>
                        <th>Uraian Barang Jadi</th>
                        <th>Kode Komponen</th>
                        <th>Uraian Komponen / Bahan</th>
                        <th>Klasifikasi</th>
                        <th style="text-align: right;">Kebutuhan (Qty)</th>
                        <th>Satuan</th>
                        <th>Style / Model</th>
                        <th>Waktu Update</th>
                        <th style="text-align: center; width: 60px;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <tr><td colspan="11" style="text-align:center; padding:15px;"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data BOM...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal Form Tambah Satuan -->
    <div class="modal" id="modalForm">
        <div class="modal-content">
            <div class="modal-header">
                <span id="modalTitle"><i class="fa-solid fa-plus"></i> Tambah Komponen BOM</span>
                <span style="cursor:pointer; font-size:16px;" onclick="closeModal('modalForm')">&times;</span>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Kode Barang Jadi (Parent / Finished Goods)*</label>
                    <input type="text" id="modalParent" class="form-control" placeholder="Contoh: FG-SHIRT-001">
                </div>
                <div class="form-group">
                    <label>Kode Komponen (Child / Bahan Baku / Kemasan)*</label>
                    <input type="text" id="modalKomponen" class="form-control" placeholder="Contoh: RM-FABRIC-001">
                </div>
                <div class="form-group">
                    <label>Jumlah Kebutuhan per Satuan Jadi (Req Qty)*</label>
                    <input type="number" step="0.0001" id="modalQty" class="form-control" value="1.0000">
                </div>
                <div class="form-group">
                    <label>Style / Model (Opsional)</label>
                    <input type="text" id="modalStyle" class="form-control" value="-">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="ant-btn" onclick="closeModal('modalForm')">Batal</button>
                <button type="button" class="ant-btn ant-btn-primary" onclick="saveBom()"><i class="fa-solid fa-floppy-disk"></i> Simpan BOM</button>
            </div>
        </div>
    </div>

    <!-- Modal Import Excel BOM -->
    <div class="modal" id="modalImport">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <span><i class="fa-solid fa-file-excel" style="color:var(--success);"></i> Import Master BOM dari Excel</span>
                <span style="cursor:pointer; font-size:16px;" onclick="closeModal('modalImport')">&times;</span>
            </div>
            <div class="modal-body">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <div style="font-size:11px; color:var(--text-muted);">
                        Unggah file Excel (<strong>.xlsx, .xls, .csv</strong>) berisi data pemetaan BOM.
                    </div>
                    <button type="button" class="ant-btn" onclick="downloadTemplate()"><i class="fa-solid fa-download"></i> Unduh Format Template</button>
                </div>
                
                <div class="dropzone" onclick="document.getElementById('excelFileInput').click()">
                    <i class="fa-solid fa-cloud-arrow-up" style="font-size:32px; color:var(--primary); margin-bottom:6px;"></i>
                    <div style="font-weight:600; font-size:12px;">Klik atau Tarik File Excel ke Sini</div>
                    <div style="font-size:10.5px; color:var(--text-muted); margin-top:3px;">Format yang didukung: .xlsx, .xls, .csv</div>
                    <input type="file" id="excelFileInput" accept=".xlsx, .xls, .csv" style="display:none;" onchange="handleExcelUpload(event)">
                </div>

                <div id="importPreviewArea" style="display:none;">
                    <div style="font-weight:600; margin-bottom:6px; display:flex; justify-content:space-between;">
                        <span>Preview Data BOM: <strong id="importRowCount" style="color:var(--primary);">0</strong> baris terdeteksi</span>
                    </div>
                    <div style="max-height: 250px; overflow: auto; border: 1px solid var(--border-color); border-radius: 4px;">
                        <table class="ant-table" style="font-size:10.5px;">
                            <thead>
                                <tr style="background:var(--table-header);">
                                    <th>#</th>
                                    <th>Kode Barang Jadi (FG)</th>
                                    <th>Kode Komponen (Bahan)</th>
                                    <th style="text-align:right;">Kebutuhan (Qty)</th>
                                    <th>Style</th>
                                </tr>
                            </thead>
                            <tbody id="importPreviewBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="ant-btn" onclick="closeModal('modalImport')">Batal</button>
                <button type="button" class="ant-btn ant-btn-success" id="btnExecuteImport" style="display:none;" onclick="executeImport()">
                    <i class="fa-solid fa-upload"></i> Proses Simpan ke Database
                </button>
            </div>
        </div>
    </div>

    <script>
        let rawBomData = [];
        let parsedExcelRows = [];

        async function loadData() {
            const parent = document.getElementById('filterParent').value;
            const q = document.getElementById('filterSearch').value.trim();
            const tbody = document.getElementById('tableBody');
            tbody.innerHTML = '<tr><td colspan="11" style="text-align:center; padding:15px;"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data BOM...</td></tr>';

            try {
                const res = await fetch(`bom.php?action=list&parent=${encodeURIComponent(parent)}&q=${encodeURIComponent(q)}`);
                const json = await res.json();
                if (json.status === 'OK') {
                    rawBomData = json.data;
                    renderTable(json.data);

                    // Update parent dropdown if empty
                    const sel = document.getElementById('filterParent');
                    if (sel.options.length <= 1 && json.parents && json.parents.length > 0) {
                        json.parents.forEach(p => {
                            const opt = document.createElement('option');
                            opt.value = p.kode_barang;
                            opt.innerText = `${p.kode_barang} - ${p.uraian}`;
                            sel.appendChild(opt);
                        });
                    }
                } else {
                    tbody.innerHTML = `<tr><td colspan="11" style="text-align:center; color:red;">${json.message}</td></tr>`;
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="11" style="text-align:center; color:red;">Error: ${err.message}</td></tr>`;
            }
        }

        function renderTable(data) {
            const tbody = document.getElementById('tableBody');
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="11" style="text-align:center; padding:15px;">Tidak ada data BOM ditemukan.</td></tr>';
                return;
            }

            tbody.innerHTML = data.map((r, i) => `
                <tr>
                    <td style="text-align:center; color:var(--text-muted);">${i + 1}</td>
                    <td style="font-weight:600; color:var(--primary); font-family:monospace;">${escapeHtml(r.kode_barang)}</td>
                    <td>${escapeHtml(r.nama_parent || '-')}</td>
                    <td style="font-weight:600; font-family:monospace;">${escapeHtml(r.komponen)}</td>
                    <td>${escapeHtml(r.nama_komponen || '-')}</td>
                    <td><span style="font-weight:500;">${escapeHtml(r.klas_komponen || '-')}</span></td>
                    <td style="text-align:right; font-weight:700;">${Number(r.req_qty).toLocaleString('id-ID', {minimumFractionDigits: 4})}</td>
                    <td>${escapeHtml(r.sat_komponen || 'PCE')}</td>
                    <td>${escapeHtml(r.style || '-')}</td>
                    <td style="font-size:10.5px; color:var(--text-muted);">${escapeHtml(r.upd_time || '-')}</td>
                    <td style="text-align:center;">
                        <button class="ant-btn" style="padding:2px 6px; color:var(--danger);" title="Hapus Komponen" onclick="deleteBom('${escapeHtml(r.kode_barang)}', '${escapeHtml(r.komponen)}')"><i class="fa-solid fa-trash"></i></button>
                    </td>
                </tr>
            `).join('');
        }

        function openAddModal() {
            document.getElementById('modalParent').value = '';
            document.getElementById('modalKomponen').value = '';
            document.getElementById('modalQty').value = '1.0000';
            document.getElementById('modalStyle').value = '-';
            document.getElementById('modalForm').style.display = 'flex';
        }

        async function saveBom() {
            const parent = document.getElementById('modalParent').value.trim();
            const komp   = document.getElementById('modalKomponen').value.trim();
            const qty    = document.getElementById('modalQty').value;
            const style  = document.getElementById('modalStyle').value.trim();

            if (!parent || !komp) {
                Swal.fire('Peringatan', 'Kode Barang Jadi dan Kode Komponen wajib diisi!', 'warning');
                return;
            }

            const fd = new FormData();
            fd.append('action', 'save');
            fd.append('kode_barang', parent);
            fd.append('komponen', komp);
            fd.append('req_qty', qty);
            fd.append('style', style);

            try {
                const res = await fetch('bom.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire('Berhasil', json.message, 'success');
                    closeModal('modalForm');
                    loadData();
                } else {
                    Swal.fire('Error', json.message, 'error');
                }
            } catch (err) {
                Swal.fire('Error', err.message, 'error');
            }
        }

        async function deleteBom(parent, komp) {
            const ok = await Swal.fire({
                title: 'Hapus Komponen BOM',
                text: `Hapus komponen ${komp} dari barang jadi ${parent}?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ff4d4f',
                confirmButtonText: 'Ya, Hapus'
            });
            if (!ok.isConfirmed) return;

            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('kode_barang', parent);
            fd.append('komponen', komp);

            try {
                const res = await fetch('bom.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire('Berhasil', json.message, 'success');
                    loadData();
                } else {
                    Swal.fire('Error', json.message, 'error');
                }
            } catch (e) { Swal.fire('Error', e.message, 'error'); }
        }

        // ================= EXCEL EXPORT & IMPORT ================= //
        function exportExcel() {
            if (rawBomData.length === 0) {
                Swal.fire('Informasi', 'Tidak ada data BOM untuk diekspor.', 'info');
                return;
            }
            const exportRows = rawBomData.map((r, i) => ({
                'No': i + 1,
                'Kode Barang Jadi': r.kode_barang,
                'Uraian Barang Jadi': r.nama_parent || '',
                'Kode Komponen': r.komponen,
                'Uraian Komponen': r.nama_komponen || '',
                'Klasifikasi': r.klas_komponen || '',
                'Kebutuhan (Qty)': Number(r.req_qty || 0),
                'Satuan': r.sat_komponen || 'PCE',
                'Style': r.style || '-'
            }));

            const ws = XLSX.utils.json_to_sheet(exportRows);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Master BOM");
            XLSX.writeFile(wb, `Master_BOM_${new Date().toISOString().slice(0,10)}.xlsx`);
        }

        function openImportModal() {
            document.getElementById('excelFileInput').value = '';
            document.getElementById('importPreviewArea').style.display = 'none';
            document.getElementById('btnExecuteImport').style.display = 'none';
            parsedExcelRows = [];
            document.getElementById('modalImport').style.display = 'flex';
        }

        function downloadTemplate() {
            const templateData = [
                {
                    'Kode Barang Jadi': 'FG-SHIRT-M',
                    'Kode Komponen': 'RM-FABRIC-001',
                    'Kebutuhan': 1.25,
                    'Style': 'Casual Style'
                },
                {
                    'Kode Barang Jadi': 'FG-SHIRT-M',
                    'Kode Komponen': 'RM-BUTTON-01',
                    'Kebutuhan': 6.00,
                    'Style': 'Casual Style'
                },
                {
                    'Kode Barang Jadi': 'FG-SHIRT-M',
                    'Kode Komponen': 'RM-THREAD-BLK',
                    'Kebutuhan': 0.05,
                    'Style': 'Casual Style'
                }
            ];
            const ws = XLSX.utils.json_to_sheet(templateData);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Template_BOM");
            XLSX.writeFile(wb, "Template_Import_Master_BOM.xlsx");
        }

        function handleExcelUpload(event) {
            const file = event.target.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = function(e) {
                try {
                    const data = new Uint8Array(e.target.result);
                    const workbook = XLSX.read(data, { type: 'array' });
                    const firstSheet = workbook.SheetNames[0];
                    const rows = XLSX.utils.sheet_to_json(workbook.Sheets[firstSheet]);

                    if (rows.length === 0) {
                        Swal.fire('Error', 'File Excel kosong atau format tidak sesuai.', 'error');
                        return;
                    }

                    parsedExcelRows = rows;
                    document.getElementById('importRowCount').innerText = rows.length;

                    const tbody = document.getElementById('importPreviewBody');
                    tbody.innerHTML = rows.slice(0, 50).map((r, i) => `
                        <tr>
                            <td>${i + 1}</td>
                            <td style="font-weight:600; font-family:monospace;">${escapeHtml(r['Kode Barang Jadi'] || r.kode_barang || r.KODE_BARANG || '-')}</td>
                            <td style="font-weight:600; font-family:monospace;">${escapeHtml(r['Kode Komponen'] || r.komponen || r.KOMPONEN || '-')}</td>
                            <td style="text-align:right; font-weight:700;">${Number(r['Kebutuhan'] || r.req_qty || r.Qty || 1).toLocaleString('id-ID', {minimumFractionDigits: 4})}</td>
                            <td>${escapeHtml(r['Style'] || r.style || '-')}</td>
                        </tr>
                    `).join('');

                    document.getElementById('importPreviewArea').style.display = 'block';
                    document.getElementById('btnExecuteImport').style.display = 'inline-flex';
                } catch (err) {
                    Swal.fire('Error Baca File', err.message, 'error');
                }
            };
            reader.readAsArrayBuffer(file);
        }

        async function executeImport() {
            if (!parsedExcelRows.length) return;
            const btn = document.getElementById('btnExecuteImport');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menyimpan ke Database...';

            const fd = new FormData();
            fd.append('action', 'import_excel');
            fd.append('rows', JSON.stringify(parsedExcelRows));

            try {
                const res = await fetch('bom.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire('Sukses Impor BOM', json.message, 'success');
                    closeModal('modalImport');
                    loadData();
                } else {
                    Swal.fire('Gagal Impor', json.message, 'error');
                }
            } catch (err) {
                Swal.fire('Error Server', err.message, 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-upload"></i> Proses Simpan ke Database';
            }
        }

        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }

        function escapeHtml(text) {
            if (!text) return '';
            return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        window.onload = loadData;
    </script>
</body>
</html>

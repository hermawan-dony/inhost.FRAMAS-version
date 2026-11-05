/**
 * ============================================================================
 * Ant Design Enterprise DataGrid Engine (ant_grid.js)
 * ============================================================================
 * Enterprise Grid Standard for CEISA 4.0 & ERP Enterprise Hub:
 * - Sortable Headers with Numeric, Date, Input, and Text detection
 * - Column Resizing with persistent localStorage storage
 * - Right-Click Context Menu (Clipboard, Excel, Custom Filter, Column Settings, Edit)
 * - Show/Hide Column Manager with localStorage persistence
 * - Advanced Custom Parameter Filter Dialog
 * - Double-Click Row Edit integration
 * - Client-Side Smart Pagination
 * ============================================================================
 */

(function (window, document) {
    'use strict';

    // Auto-Sync Unified Application Font & Font Size Engine
    function syncAppFont(fontName) {
        if (!fontName) {
            try { fontName = localStorage.getItem('ceisa_app_font'); } catch(e) {}
        }
        if (!fontName) fontName = 'Poppins';
        var fontStack = "'" + fontName + "', 'Source Sans Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";
        document.documentElement.style.setProperty('--app-font', fontStack);
        if (document.body) {
            document.body.style.setProperty('--app-font', fontStack);
        }
    }
    function syncAppFontSize(fontSize) {
        if (!fontSize) {
            try { fontSize = localStorage.getItem('ceisa_app_font_size'); } catch(e) {}
        }
        if (!fontSize) fontSize = '9.5px';
        document.documentElement.style.setProperty('--app-font-size', fontSize);
        document.documentElement.style.setProperty('--form-font-size', fontSize);
        if (document.body) {
            document.body.style.setProperty('--app-font-size', fontSize);
            document.body.style.setProperty('--form-font-size', fontSize);
        }
    }
    syncAppFont();
    syncAppFontSize();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { 
            syncAppFont(); 
            syncAppFontSize(); 
        });
    }
    window.addEventListener('message', function(e) {
        if (!e.data) return;
        if (e.data.type === 'SET_FONT' || e.data.type === 'CHANGE_FONT') {
            syncAppFont(e.data.font);
        }
        if (e.data.type === 'SET_FONT_SIZE' || e.data.type === 'CHANGE_FONT_SIZE' || e.data.type === 'LIVE_FONT_SIZE_CHANGE') {
            syncAppFontSize(e.data.fontSize || e.data.size);
        }
    });

    class AntEnterpriseGrid {
        constructor(target, options = {}) {
            this.table = typeof target === 'string' ? document.querySelector(target) : target;
            if (!this.table) return;

            this.options = Object.assign({
                gridId: this.table.id || window.location.pathname.replace(/[^a-zA-Z0-9_]/g, '_'),
                enableSort: true,
                enableResize: true,
                enableContextMenu: true,
                enablePagination: false,
                pageSize: 25,
                pageSizes: [15, 25, 50, 100, 250, 'Semua'],
                skipCols: [], // e.g. [0, 7] for # and Actions
                onEdit: null,
                exportTitle: document.title || 'Data_Export'
            }, options);

            this.gridId = this.options.gridId;
            this.currentSortCol = -1;
            this.currentSortDir = 'asc';
            this.currentPage = 1;
            this.pageSize = this.options.pageSize;
            this.customFilter = null; // { colIndex, op, val }

            this.init();
        }

        init() {
            this.table.classList.add('ant-grid-table');

            this.initHeaders();
            if (this.options.enableResize) this.initResizing();
            if (this.options.enableSort) this.initSorting();
            if (this.options.enableContextMenu) this.initContextMenu();
            this.initRowInteractions();
            this.restoreColumnSettings();

            if (this.options.enablePagination) {
                this.initPagination();
            }
        }

        getHeaders() {
            return Array.from(this.table.querySelectorAll('thead tr:last-child th'));
        }

        getBodyRows() {
            const tbody = this.table.querySelector('tbody');
            if (!tbody) return [];
            return Array.from(tbody.querySelectorAll('tr')).filter(r => !r.classList.contains('ant-grid-empty-row'));
        }

        initHeaders() {
            const ths = this.getHeaders();
            ths.forEach((th, idx) => {
                th.dataset.colIndex = idx;
                if (!th.dataset.colName) {
                    th.dataset.colName = th.innerText.trim() || `Kolom ${idx + 1}`;
                }
            });
        }

        // ---------------------------------------------------------------------
        // 1. COLUMN RESIZING
        // ---------------------------------------------------------------------
        initResizing() {
            const ths = this.getHeaders();
            ths.forEach((th, idx) => {
                if (this.options.skipCols.includes(idx)) return;
                if (th.querySelector('.ant-grid-resizer')) return;

                const resizer = document.createElement('div');
                resizer.className = 'ant-grid-resizer';
                th.appendChild(resizer);

                let startX, startW;

                const onMouseMove = (e) => {
                    const newW = Math.max(30, startW + (e.pageX - startX));
                    th.style.width = newW + 'px';
                    th.style.minWidth = newW + 'px';
                    resizer.classList.add('ant-grid-resizing');
                };

                const onMouseUp = () => {
                    resizer.classList.remove('ant-grid-resizing');
                    document.removeEventListener('mousemove', onMouseMove);
                    document.removeEventListener('mouseup', onMouseUp);
                    this.saveColumnWidths();
                };

                resizer.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    startX = e.pageX;
                    startW = th.offsetWidth;
                    document.addEventListener('mousemove', onMouseMove);
                    document.addEventListener('mouseup', onMouseUp);
                });
            });

            this.restoreColumnWidths();
        }

        saveColumnWidths() {
            const ths = this.getHeaders();
            const widths = {};
            ths.forEach((th, idx) => {
                if (th.style.width) widths[idx] = th.style.width;
            });
            try {
                localStorage.setItem(`ant_grid_w_${this.gridId}`, JSON.stringify(widths));
            } catch (e) {}
        }

        restoreColumnWidths() {
            try {
                const raw = localStorage.getItem(`ant_grid_w_${this.gridId}`);
                if (!raw) return;
                const widths = JSON.parse(raw);
                const ths = this.getHeaders();
                ths.forEach((th, idx) => {
                    if (widths[idx]) {
                        th.style.width = widths[idx];
                        th.style.minWidth = widths[idx];
                    }
                });
            } catch (e) {}
        }

        // ---------------------------------------------------------------------
        // 2. COLUMN SORTING
        // ---------------------------------------------------------------------
        initSorting() {
            const ths = this.getHeaders();
            ths.forEach((th, idx) => {
                if (this.options.skipCols.includes(idx) || th.classList.contains('no-sort')) return;
                th.classList.add('sortable');
                th.title = 'Klik untuk mengurutkan (Sort)';

                let icon = th.querySelector('.ant-grid-sort-icon');
                if (!icon) {
                    icon = document.createElement('span');
                    icon.className = 'ant-grid-sort-icon';
                    icon.textContent = ' ↕';
                    th.appendChild(icon);
                }

                th.addEventListener('click', (e) => {
                    if (e.target.classList.contains('ant-grid-resizer')) return;
                    this.sortColumn(idx);
                });
            });
        }

        sortColumn(colIndex) {
            const ths = this.getHeaders();
            const targetTh = ths[colIndex];
            if (!targetTh) return;

            if (this.currentSortCol === colIndex) {
                this.currentSortDir = (this.currentSortDir === 'asc') ? 'desc' : 'asc';
            } else {
                this.currentSortCol = colIndex;
                this.currentSortDir = 'asc';
            }

            ths.forEach((th, idx) => {
                const icon = th.querySelector('.ant-grid-sort-icon');
                th.classList.remove('sort-asc', 'sort-desc');
                if (icon) {
                    if (idx === colIndex) {
                        th.classList.add(this.currentSortDir === 'asc' ? 'sort-asc' : 'sort-desc');
                        icon.textContent = this.currentSortDir === 'asc' ? ' ▲' : ' ▼';
                    } else {
                        icon.textContent = ' ↕';
                    }
                }
            });

            const tbody = this.table.querySelector('tbody');
            if (!tbody) return;
            const rows = this.getBodyRows();
            if (rows.length <= 1) return;

            rows.sort((a, b) => {
                let cellA = (a.cells[colIndex] ? a.cells[colIndex].innerText : '').trim();
                let cellB = (b.cells[colIndex] ? b.cells[colIndex].innerText : '').trim();

                const inpA = a.cells[colIndex] ? a.cells[colIndex].querySelector('input, select') : null;
                const inpB = b.cells[colIndex] ? b.cells[colIndex].querySelector('input, select') : null;
                if (inpA) cellA = inpA.value.trim();
                if (inpB) cellB = inpB.value.trim();

                const cleanA = cellA.replace(/,/g, '').replace(/[^\d.-]/g, '');
                const cleanB = cellB.replace(/,/g, '').replace(/[^\d.-]/g, '');
                const numA = parseFloat(cleanA);
                const numB = parseFloat(cleanB);

                let comp = 0;
                if (!isNaN(numA) && !isNaN(numB) && cellA !== '' && cellB !== '' && !cellA.includes('-') && !cellA.match(/^[A-Za-z]/)) {
                    comp = numA - numB;
                } else {
                    comp = cellA.localeCompare(cellB, undefined, { numeric: true, sensitivity: 'base' });
                }

                return this.currentSortDir === 'asc' ? comp : -comp;
            });

            rows.forEach(r => tbody.appendChild(r));
            this.reNumberRows();

            if (this.options.enablePagination) {
                this.updatePagination();
            }
        }

        reNumberRows() {
            const rows = this.getBodyRows();
            let idx = 1;
            rows.forEach(r => {
                if (r.cells.length > 1 && r.cells[0] && !isNaN(parseInt(r.cells[0].innerText.trim()))) {
                    r.cells[0].innerText = idx++;
                }
            });
        }

        // ---------------------------------------------------------------------
        // 3. ROW INTERACTIONS (Click, Double Click)
        // ---------------------------------------------------------------------
        initRowInteractions() {
            const tbody = this.table.querySelector('tbody');
            if (!tbody) return;

            tbody.addEventListener('click', (e) => {
                const tr = e.target.closest('tr');
                if (!tr || tr.parentElement !== tbody) return;
                tbody.querySelectorAll('tr.ant-grid-row-selected').forEach(r => r.classList.remove('ant-grid-row-selected'));
                tr.classList.add('ant-grid-row-selected');
            });

            tbody.addEventListener('dblclick', (e) => {
                const tr = e.target.closest('tr');
                if (!tr || tr.parentElement !== tbody) return;
                this.triggerEditRow(tr);
            });
        }

        triggerEditRow(tr) {
            if (!tr) {
                tr = this.table.querySelector('tbody tr.ant-grid-row-selected') || this.getBodyRows()[0];
            }
            if (!tr) return;

            if (typeof this.options.onEdit === 'function') {
                const rowData = {};
                Array.from(tr.cells).forEach((td, idx) => {
                    rowData[idx] = td.innerText.trim();
                });
                this.options.onEdit(tr, rowData);
                return;
            }

            // Fallback: cari tombol edit di baris
            const editBtn = tr.querySelector('.btn-edit, button[onclick*="load"], button[onclick*="edit"], a[onclick*="edit"], .fa-pen');
            if (editBtn) {
                const target = editBtn.tagName === 'BUTTON' || editBtn.tagName === 'A' ? editBtn : editBtn.closest('button, a');
                if (target) target.click();
            }
        }

        // ---------------------------------------------------------------------
        // 4. RIGHT-CLICK CONTEXT MENU
        // ---------------------------------------------------------------------
        initContextMenu() {
            let menu = document.getElementById('antGridSharedContextMenu');
            if (!menu) {
                menu = document.createElement('div');
                menu.id = 'antGridSharedContextMenu';
                menu.className = 'ant-grid-ctx-menu';
                menu.innerHTML = `
                    <div class="ant-grid-ctx-item" data-action="copy_grid"><i class="fa-solid fa-copy" style="color:#0ea5e9;"></i> <span>Salin Semua Data (Grid)</span></div>
                    <div class="ant-grid-ctx-item" data-action="copy_row"><i class="fa-regular fa-clone" style="color:#6366f1;"></i> <span>Salin Baris Terpilih</span></div>
                    <div class="ant-grid-ctx-divider"></div>
                    <div class="ant-grid-ctx-item" data-action="export_excel"><i class="fa-solid fa-file-excel" style="color:#10b981;"></i> <span>Simpan ke Excel (.xlsx)</span></div>
                    <div class="ant-grid-ctx-item" data-action="export_csv"><i class="fa-solid fa-file-csv" style="color:#f59e0b;"></i> <span>Unduh CSV</span></div>
                    <div class="ant-grid-ctx-divider"></div>
                    <div class="ant-grid-ctx-item" data-action="filter_custom"><i class="fa-solid fa-filter" style="color:#8b5cf6;"></i> <span>Filter Data Kustom...</span></div>
                    <div class="ant-grid-ctx-item" data-action="column_settings"><i class="fa-solid fa-table-columns" style="color:#1890ff;"></i> <span>Atur Kolom (Show / Hide)...</span></div>
                    <div class="ant-grid-ctx-divider"></div>
                    <div class="ant-grid-ctx-item" data-action="edit_row"><i class="fa-solid fa-pen-to-square" style="color:#f97316;"></i> <span>Edit Baris (Dobel Klik)</span></div>
                    <div class="ant-grid-ctx-item" data-action="reset_grid"><i class="fa-solid fa-rotate-left" style="color:#ef4444;"></i> <span>Reset Ukuran &amp; Tampilan Kolom</span></div>
                `;
                document.body.appendChild(menu);

                document.addEventListener('click', (e) => {
                    if (!e.target.closest('#antGridSharedContextMenu')) {
                        menu.style.display = 'none';
                    }
                });
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') menu.style.display = 'none';
                });
            }

            this.table.addEventListener('contextmenu', (e) => {
                e.preventDefault();
                const tr = e.target.closest('tr');
                if (tr && tr.parentElement.tagName === 'TBODY') {
                    this.table.querySelectorAll('tbody tr.ant-grid-row-selected').forEach(r => r.classList.remove('ant-grid-row-selected'));
                    tr.classList.add('ant-grid-row-selected');
                    this.activeContextTr = tr;
                } else {
                    this.activeContextTr = null;
                }

                window.activeAntGrid = this;

                menu.style.display = 'block';
                const menuW = menu.offsetWidth || 200;
                const menuH = menu.offsetHeight || 220;

                let posX = e.clientX;
                let posY = e.clientY;
                if (posX + menuW > window.innerWidth) posX = window.innerWidth - menuW - 10;
                if (posY + menuH > window.innerHeight) posY = window.innerHeight - menuH - 10;

                menu.style.left = `${posX}px`;
                menu.style.top = `${posY}px`;
            });

            menu.onclick = (e) => {
                const item = e.target.closest('.ant-grid-ctx-item');
                if (!item || !window.activeAntGrid) return;
                const action = item.dataset.action;
                menu.style.display = 'none';
                window.activeAntGrid.executeContextAction(action);
            };
        }

        executeContextAction(action) {
            switch (action) {
                case 'copy_grid':
                    this.copyClipboard(false);
                    break;
                case 'copy_row':
                    this.copyClipboard(true);
                    break;
                case 'export_excel':
                    this.exportExcel();
                    break;
                case 'export_csv':
                    this.exportCSV();
                    break;
                case 'filter_custom':
                    this.openFilterModal();
                    break;
                case 'column_settings':
                    this.openColumnSettingsModal();
                    break;
                case 'edit_row':
                    this.triggerEditRow(this.activeContextTr);
                    break;
                case 'reset_grid':
                    this.resetGridSettings();
                    break;
            }
        }

        // ---------------------------------------------------------------------
        // 5. CLIPBOARD & EXPORT EXCEL/CSV
        // ---------------------------------------------------------------------
        copyClipboard(onlySelected = false) {
            const rows = onlySelected && this.activeContextTr ? [this.activeContextTr] : this.getBodyRows().filter(r => r.style.display !== 'none');
            if (rows.length === 0) return;

            const ths = this.getHeaders().filter((th, idx) => th.style.display !== 'none' && !this.options.skipCols.includes(idx));
            let out = [];
            
            // Header baris
            out.push(ths.map(th => th.dataset.colName || th.innerText.trim()).join('\t'));

            // Data baris
            rows.forEach(r => {
                const rowVals = [];
                ths.forEach(th => {
                    const idx = parseInt(th.dataset.colIndex);
                    const td = r.cells[idx];
                    let val = td ? td.innerText.trim() : '';
                    const inp = td ? td.querySelector('input, select') : null;
                    if (inp) val = inp.value.trim();
                    rowVals.push(val.replace(/\r?\n|\r/g, ' '));
                });
                out.push(rowVals.join('\t'));
            });

            navigator.clipboard.writeText(out.join('\n')).then(() => {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'success', title: 'Tersalin!', text: `${rows.length} baris data berhasil disalin ke clipboard (format Excel TSV).`, timer: 1500, showConfirmButton: false });
                } else {
                    alert('Data berhasil disalin ke clipboard!');
                }
            }).catch(() => {
                alert('Gagal menyalin otomatis ke clipboard.');
            });
        }

        exportExcel() {
            if (typeof XLSX === 'undefined') {
                this.exportCSV();
                return;
            }

            const ths = this.getHeaders().filter((th, idx) => th.style.display !== 'none' && !this.options.skipCols.includes(idx));
            const rows = this.getBodyRows().filter(r => r.style.display !== 'none');

            const data = [];
            // Header
            data.push(ths.map(th => th.dataset.colName || th.innerText.trim()));

            // Data
            rows.forEach(r => {
                const rowVals = [];
                ths.forEach(th => {
                    const idx = parseInt(th.dataset.colIndex);
                    const td = r.cells[idx];
                    let val = td ? td.innerText.trim() : '';
                    const inp = td ? td.querySelector('input, select') : null;
                    if (inp) val = inp.value.trim();
                    rowVals.push(val);
                });
                data.push(rowVals);
            });

            const ws = XLSX.utils.aoa_to_sheet(data);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Data");
            const filename = `${this.options.exportTitle.replace(/[^a-zA-Z0-9_-]/g, '_')}_${new Date().toISOString().slice(0, 10)}.xlsx`;
            XLSX.writeFile(wb, filename);
        }

        exportCSV() {
            const ths = this.getHeaders().filter((th, idx) => th.style.display !== 'none' && !this.options.skipCols.includes(idx));
            const rows = this.getBodyRows().filter(r => r.style.display !== 'none');

            let csv = [];
            csv.push(ths.map(th => '"' + (th.dataset.colName || th.innerText.trim()).replace(/"/g, '""') + '"').join(','));

            rows.forEach(r => {
                const rowVals = [];
                ths.forEach(th => {
                    const idx = parseInt(th.dataset.colIndex);
                    const td = r.cells[idx];
                    let val = td ? td.innerText.trim() : '';
                    const inp = td ? td.querySelector('input, select') : null;
                    if (inp) val = inp.value.trim();
                    rowVals.push('"' + val.replace(/"/g, '""') + '"');
                });
                csv.push(rowVals.join(','));
            });

            const blob = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = `${this.options.exportTitle.replace(/[^a-zA-Z0-9_-]/g, '_')}_${new Date().toISOString().slice(0, 10)}.csv`;
            link.click();
        }

        // ---------------------------------------------------------------------
        // 6. COLUMN VISIBILITY MANAGER (SHOW / HIDE)
        // ---------------------------------------------------------------------
        openColumnSettingsModal() {
            let modal = document.getElementById('antGridColModal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'antGridColModal';
                modal.className = 'ant-grid-modal-backdrop';
                modal.innerHTML = `
                    <div class="ant-grid-modal">
                        <div class="ant-grid-modal-header">
                            <span><i class="fa-solid fa-table-columns" style="color:var(--primary);"></i> Atur Kolom Tampilan</span>
                            <span class="close-btn" onclick="document.getElementById('antGridColModal').classList.remove('active')">&times;</span>
                        </div>
                        <div class="ant-grid-modal-body" id="antGridColModalList"></div>
                        <div class="ant-grid-modal-footer">
                            <button type="button" class="ant-btn ant-btn-sm" id="btnAntGridSelectAllCols">Pilih Semua</button>
                            <button type="button" class="ant-btn ant-btn-sm" onclick="document.getElementById('antGridColModal').classList.remove('active')">Batal</button>
                            <button type="button" class="ant-btn ant-btn-sm ant-btn-primary" id="btnAntGridApplyCols">Terapkan</button>
                        </div>
                    </div>
                `;
                document.body.appendChild(modal);
            }

            const list = document.getElementById('antGridColModalList');
            const ths = this.getHeaders();
            let html = '';
            ths.forEach((th, idx) => {
                if (this.options.skipCols.includes(idx)) return;
                const name = th.dataset.colName || th.innerText.trim();
                const isVisible = th.style.display !== 'none';
                html += `
                    <label style="display:flex; align-items:center; gap:8px; padding:3px 0; cursor:pointer; font-size:11px;">
                        <input type="checkbox" data-idx="${idx}" ${isVisible ? 'checked' : ''} style="cursor:pointer;">
                        <span>${escapeHtml(name)}</span>
                    </label>
                `;
            });
            list.innerHTML = html;

            document.getElementById('btnAntGridSelectAllCols').onclick = () => {
                list.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = true);
            };

            document.getElementById('btnAntGridApplyCols').onclick = () => {
                const hiddenCols = [];
                list.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                    const idx = parseInt(cb.dataset.idx);
                    if (!cb.checked) hiddenCols.push(idx);
                });
                this.setHiddenColumns(hiddenCols);
                modal.classList.remove('active');
            };

            modal.classList.add('active');
        }

        setHiddenColumns(hiddenCols) {
            const ths = this.getHeaders();
            const rows = this.getBodyRows();

            ths.forEach((th, idx) => {
                if (this.options.skipCols.includes(idx)) return;
                const hide = hiddenCols.includes(idx);
                th.style.display = hide ? 'none' : '';
            });

            rows.forEach(r => {
                ths.forEach((th, idx) => {
                    if (this.options.skipCols.includes(idx)) return;
                    if (r.cells[idx]) {
                        r.cells[idx].style.display = hiddenCols.includes(idx) ? 'none' : '';
                    }
                });
            });

            try {
                localStorage.setItem(`ant_grid_hidden_${this.gridId}`, JSON.stringify(hiddenCols));
            } catch (e) {}
        }

        restoreColumnSettings() {
            try {
                const raw = localStorage.getItem(`ant_grid_hidden_${this.gridId}`);
                if (!raw) return;
                const hiddenCols = JSON.parse(raw);
                if (Array.isArray(hiddenCols)) {
                    this.setHiddenColumns(hiddenCols);
                }
            } catch (e) {}
        }

        resetGridSettings() {
            try {
                localStorage.removeItem(`ant_grid_w_${this.gridId}`);
                localStorage.removeItem(`ant_grid_hidden_${this.gridId}`);
            } catch (e) {}

            const ths = this.getHeaders();
            ths.forEach(th => {
                th.style.width = '';
                th.style.minWidth = '';
                th.style.display = '';
            });
            this.getBodyRows().forEach(r => {
                Array.from(r.cells).forEach(td => td.style.display = '');
            });

            this.customFilter = null;
            this.applyCustomFilter();

            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'success', title: 'Reset Berhasil', text: 'Ukuran kolom dan pengaturan tampilan telah dikembalikan ke setelan awal.', timer: 1500, showConfirmButton: false });
            }
        }

        // ---------------------------------------------------------------------
        // 7. CUSTOM PARAMETER FILTER MODAL
        // ---------------------------------------------------------------------
        openFilterModal() {
            let modal = document.getElementById('antGridFilterModal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'antGridFilterModal';
                modal.className = 'ant-grid-modal-backdrop';
                modal.innerHTML = `
                    <div class="ant-grid-modal">
                        <div class="ant-grid-modal-header">
                            <span><i class="fa-solid fa-filter" style="color:var(--primary);"></i> Filter Data Kustom</span>
                            <span class="close-btn" onclick="document.getElementById('antGridFilterModal').classList.remove('active')">&times;</span>
                        </div>
                        <div class="ant-grid-modal-body">
                            <div style="display:flex; flex-direction:column; gap:3px;">
                                <label style="font-size:10px; font-weight:600; color:var(--text-muted);">Pilih Kolom:</label>
                                <select id="antFilterColSelect" class="form-control" style="height:24px; font-size:11px;"></select>
                            </div>
                            <div style="display:flex; flex-direction:column; gap:3px;">
                                <label style="font-size:10px; font-weight:600; color:var(--text-muted);">Operator Pembanding:</label>
                                <select id="antFilterOpSelect" class="form-control" style="height:24px; font-size:11px;">
                                    <option value="contains">Berisi Kata (Contains)</option>
                                    <option value="equals">Sama Dengan (=)</option>
                                    <option value="not_equals">Tidak Sama Dengan (!=)</option>
                                    <option value="starts_with">Diawali Dengan (Starts with)</option>
                                    <option value="ends_with">Diakhiri Dengan (Ends with)</option>
                                    <option value="gt">Lebih Besar (&gt;)</option>
                                    <option value="lt">Lebih Kecil (&lt;)</option>
                                    <option value="empty">Kosong (Empty)</option>
                                    <option value="not_empty">Tidak Kosong (Not Empty)</option>
                                </select>
                            </div>
                            <div style="display:flex; flex-direction:column; gap:3px;">
                                <label style="font-size:10px; font-weight:600; color:var(--text-muted);">Nilai Kriteria:</label>
                                <input type="text" id="antFilterValInput" class="form-control" placeholder="Ketik kata / angka kriteria..." style="height:24px; font-size:11px;">
                            </div>
                        </div>
                        <div class="ant-grid-modal-footer">
                            <button type="button" class="ant-btn ant-btn-sm" id="btnAntGridClearFilter">Hapus Filter</button>
                            <button type="button" class="ant-btn ant-btn-sm" onclick="document.getElementById('antGridFilterModal').classList.remove('active')">Batal</button>
                            <button type="button" class="ant-btn ant-btn-sm ant-btn-primary" id="btnAntGridApplyFilter">Terapkan Filter</button>
                        </div>
                    </div>
                `;
                document.body.appendChild(modal);
            }

            const colSelect = document.getElementById('antFilterColSelect');
            const ths = this.getHeaders();
            let optionsHtml = '';
            ths.forEach((th, idx) => {
                if (this.options.skipCols.includes(idx)) return;
                const name = th.dataset.colName || th.innerText.trim();
                optionsHtml += `<option value="${idx}">${escapeHtml(name)}</option>`;
            });
            colSelect.innerHTML = optionsHtml;

            if (this.customFilter) {
                colSelect.value = this.customFilter.colIndex;
                document.getElementById('antFilterOpSelect').value = this.customFilter.op;
                document.getElementById('antFilterValInput').value = this.customFilter.val;
            }

            document.getElementById('btnAntGridApplyFilter').onclick = () => {
                const colIdx = parseInt(colSelect.value);
                const op = document.getElementById('antFilterOpSelect').value;
                const val = document.getElementById('antFilterValInput').value.trim();
                this.customFilter = { colIndex: colIdx, op: op, val: val };
                this.applyCustomFilter();
                modal.classList.remove('active');
            };

            document.getElementById('btnAntGridClearFilter').onclick = () => {
                this.customFilter = null;
                this.applyCustomFilter();
                modal.classList.remove('active');
            };

            modal.classList.add('active');
            document.getElementById('antFilterValInput').focus();
        }

        applyCustomFilter() {
            const rows = this.getBodyRows();
            if (!this.customFilter) {
                rows.forEach(r => {
                    delete r.dataset.antFiltered;
                    r.style.display = '';
                });
                if (this.options.enablePagination) this.updatePagination();
                return;
            }

            const { colIndex, op, val } = this.customFilter;
            const searchVal = val.toLowerCase();
            const numSearch = parseFloat(val.replace(/,/g, ''));

            rows.forEach(r => {
                const td = r.cells[colIndex];
                let cellText = td ? td.innerText.trim() : '';
                const inp = td ? td.querySelector('input, select') : null;
                if (inp) cellText = inp.value.trim();

                const lowerText = cellText.toLowerCase();
                const cellNum = parseFloat(cellText.replace(/,/g, '').replace(/[^\d.-]/g, ''));

                let match = false;
                switch (op) {
                    case 'contains':
                        match = lowerText.includes(searchVal);
                        break;
                    case 'equals':
                        match = lowerText === searchVal;
                        break;
                    case 'not_equals':
                        match = lowerText !== searchVal;
                        break;
                    case 'starts_with':
                        match = lowerText.startsWith(searchVal);
                        break;
                    case 'ends_with':
                        match = lowerText.endsWith(searchVal);
                        break;
                    case 'gt':
                        match = !isNaN(cellNum) && !isNaN(numSearch) && cellNum > numSearch;
                        break;
                    case 'lt':
                        match = !isNaN(cellNum) && !isNaN(numSearch) && cellNum < numSearch;
                        break;
                    case 'empty':
                        match = cellText === '' || cellText === '-';
                        break;
                    case 'not_empty':
                        match = cellText !== '' && cellText !== '-';
                        break;
                }

                if (match) {
                    delete r.dataset.antFiltered;
                    r.style.display = '';
                } else {
                    r.dataset.antFiltered = '1';
                    r.style.display = 'none';
                }
            });

            if (this.options.enablePagination) {
                this.currentPage = 1;
                this.updatePagination();
            }
        }

        // ---------------------------------------------------------------------
        // 8. CLIENT-SIDE PAGINATION
        // ---------------------------------------------------------------------
        initPagination() {
            if (this.pagerEl) this.pagerEl.remove();

            const pager = document.createElement('div');
            pager.className = 'ant-grid-pager';
            pager.innerHTML = `
                <div class="ant-grid-pager-left">
                    <span class="ant-grid-pager-info">Menampilkan 0 - 0 dari 0 data</span>
                    <span style="margin: 0 4px; color:var(--border-color);">|</span>
                    <span>Tampilkan:</span>
                    <select class="ant-grid-pager-select"></select>
                </div>
                <div class="ant-grid-pager-right">
                    <button type="button" class="ant-grid-pager-btn btn-first" title="Halaman Pertama">&laquo;</button>
                    <button type="button" class="ant-grid-pager-btn btn-prev" title="Halaman Sebelumnya">&lsaquo;</button>
                    <div class="ant-grid-pager-numbers" style="display:inline-flex; gap:2px;"></div>
                    <button type="button" class="ant-grid-pager-btn btn-next" title="Halaman Berikutnya">&rsaquo;</button>
                    <button type="button" class="ant-grid-pager-btn btn-last" title="Halaman Terakhir">&raquo;</button>
                </div>
            `;

            const select = pager.querySelector('.ant-grid-pager-select');
            this.options.pageSizes.forEach(ps => {
                const opt = document.createElement('option');
                opt.value = ps === 'Semua' ? 'ALL' : ps;
                opt.textContent = ps;
                if (String(ps) === String(this.pageSize)) opt.selected = true;
                select.appendChild(opt);
            });

            select.addEventListener('change', () => {
                this.pageSize = select.value === 'ALL' ? 999999 : parseInt(select.value);
                this.currentPage = 1;
                this.updatePagination();
            });

            pager.querySelector('.btn-first').onclick = () => { this.currentPage = 1; this.updatePagination(); };
            pager.querySelector('.btn-prev').onclick = () => { if (this.currentPage > 1) { this.currentPage--; this.updatePagination(); } };
            pager.querySelector('.btn-next').onclick = () => { if (this.currentPage < this.totalPages) { this.currentPage++; this.updatePagination(); } };
            pager.querySelector('.btn-last').onclick = () => { this.currentPage = this.totalPages; this.updatePagination(); };

            if (this.table.parentElement) {
                this.table.parentElement.appendChild(pager);
            } else {
                this.table.insertAdjacentElement('afterend', pager);
            }

            this.pagerEl = pager;
            this.updatePagination();
        }

        updatePagination() {
            if (!this.options.enablePagination || !this.pagerEl) return;

            const rows = this.getBodyRows().filter(r => r.dataset.antFiltered !== '1');
            const total = rows.length;
            const limit = this.pageSize || 25;
            this.totalPages = Math.max(1, Math.ceil(total / limit));
            if (this.currentPage > this.totalPages) this.currentPage = this.totalPages;

            const startIdx = (this.currentPage - 1) * limit;
            const endIdx = Math.min(startIdx + limit, total);

            rows.forEach((r, idx) => {
                if (idx >= startIdx && idx < endIdx) {
                    r.style.display = '';
                } else {
                    r.style.display = 'none';
                }
            });

            const infoEl = this.pagerEl.querySelector('.ant-grid-pager-info');
            infoEl.textContent = total > 0 
                ? `Menampilkan ${startIdx + 1} - ${endIdx} dari ${total} data`
                : 'Tidak ada data yang ditampilkan';

            this.pagerEl.querySelector('.btn-first').disabled = this.currentPage <= 1;
            this.pagerEl.querySelector('.btn-prev').disabled = this.currentPage <= 1;
            this.pagerEl.querySelector('.btn-next').disabled = this.currentPage >= this.totalPages;
            this.pagerEl.querySelector('.btn-last').disabled = this.currentPage >= this.totalPages;

            const numbersContainer = this.pagerEl.querySelector('.ant-grid-pager-numbers');
            numbersContainer.innerHTML = '';
            
            // Tampilkan beberapa tombol nomor halaman
            let pStart = Math.max(1, this.currentPage - 2);
            let pEnd = Math.min(this.totalPages, pStart + 4);
            if (pEnd - pStart < 4) pStart = Math.max(1, pEnd - 4);

            for (let p = pStart; p <= pEnd; p++) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = `ant-grid-pager-btn ${p === this.currentPage ? 'active' : ''}`;
                btn.textContent = p;
                btn.onclick = () => { this.currentPage = p; this.updatePagination(); };
                numbersContainer.appendChild(btn);
            }
        }

        refresh() {
            this.initHeaders();
            if (this.options.enableResize) this.initResizing();
            if (this.options.enableSort) this.initSorting();
            this.restoreColumnSettings();
            if (this.options.enablePagination) this.updatePagination();
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // Expose to global window
    window.AntEnterpriseGrid = AntEnterpriseGrid;

})(window, document);

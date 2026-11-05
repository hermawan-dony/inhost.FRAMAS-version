/**
 * Ant Design Compact Desktop 11px - Searchable Select Combobox Engine
 * Created for CEISA 4.0 & ERP Enterprise Hub
 * (c) 2026 PT. Inhost Teknologi Indonesia
 */
(function(window) {
    'use strict';

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function highlightMatch(text, query) {
        if (!query) return escapeHtml(text);
        const safeText = String(text);
        const qEsc = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const reg = new RegExp(`(${qEsc})`, 'gi');
        return safeText.replace(reg, '<span class="ant-searchable-match">$1</span>');
    }

    function initAntSearchableSelect(selectOrId, options = {}) {
        const selectEl = typeof selectOrId === 'string' ? document.getElementById(selectOrId) : selectOrId;
        if (!selectEl) return null;

        // If already initialized, just call sync
        if (selectEl._antSearchableInstance) {
            selectEl._antSearchableInstance.sync();
            return selectEl._antSearchableInstance;
        }

        const placeholder = options.placeholder || selectEl.getAttribute('placeholder') || 'Ketik nama / kode untuk mencari...';
        const wrapper = document.createElement('div');
        wrapper.className = 'ant-searchable-wrapper';
        if (options.className) wrapper.classList.add(options.className);
        if (options.style) wrapper.style.cssText = options.style;
        if (selectEl.style) {
            if (selectEl.style.flex) wrapper.style.flex = selectEl.style.flex;
            if (selectEl.style.flexGrow) wrapper.style.flexGrow = selectEl.style.flexGrow;
        }
        if (options.width) {
            wrapper.style.width = options.width;
        } else if (selectEl.style && selectEl.style.width) {
            wrapper.style.width = selectEl.style.width;
        }

        const box = document.createElement('div');
        box.className = 'ant-searchable-box';

        const iconSearch = document.createElement('i');
        iconSearch.className = 'fa-solid fa-magnifying-glass ant-searchable-icon-search';

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'ant-searchable-input';
        input.autocomplete = 'off';
        input.spellcheck = false;
        input.placeholder = placeholder;

        const actions = document.createElement('div');
        actions.className = 'ant-searchable-actions';

        const clearBtn = document.createElement('span');
        clearBtn.className = 'ant-searchable-clear';
        clearBtn.innerHTML = '&times;';
        clearBtn.title = 'Hapus pilihan';

        const arrowIcon = document.createElement('i');
        arrowIcon.className = 'fa-solid fa-chevron-down ant-searchable-arrow';

        actions.appendChild(clearBtn);
        actions.appendChild(arrowIcon);

        box.appendChild(iconSearch);
        box.appendChild(input);
        box.appendChild(actions);

        const dropdown = document.createElement('div');
        dropdown.className = 'ant-searchable-dropdown';

        wrapper.appendChild(box);
        wrapper.appendChild(dropdown);

        // Hide select and insert wrapper right after
        selectEl.style.display = 'none';
        selectEl.parentNode.insertBefore(wrapper, selectEl.nextSibling);

        let itemsData = [];
        let highlightedIdx = -1;
        let isOpen = false;

        function refreshItemsFromSelect() {
            itemsData = [];
            const opts = selectEl.options;
            for (let i = 0; i < opts.length; i++) {
                const opt = opts[i];
                const val = opt.value;
                const txt = opt.text;
                // Parse kode & nama if formatted like "KODE - NAMA"
                let kode = val;
                let nama = txt;
                const dashIdx = txt.indexOf(' - ');
                if (dashIdx > 0 && val && txt.startsWith(val)) {
                    kode = val;
                    nama = txt.substring(dashIdx + 3).trim();
                }
                itemsData.push({
                    value: val,
                    text: txt,
                    kode: kode,
                    nama: nama,
                    disabled: opt.disabled,
                    optElement: opt
                });
            }
        }

        function updateDisplayFromSelect() {
            const curVal = selectEl.value;
            const selOpt = selectEl.options[selectEl.selectedIndex];
            if (selOpt && selOpt.value) {
                input.value = selOpt.text;
                clearBtn.style.display = 'inline-flex';
            } else {
                input.value = '';
                clearBtn.style.display = 'none';
            }
        }

        function renderDropdown(filterQuery = '') {
            dropdown.innerHTML = '';
            const q = filterQuery.trim().toLowerCase();
            const filtered = itemsData.filter(it => {
                if (!it.value && !q) return false; // skip placeholder in list unless searched
                if (!q) return true;
                return it.kode.toLowerCase().includes(q) || it.nama.toLowerCase().includes(q) || it.text.toLowerCase().includes(q);
            });

            if (filtered.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'ant-searchable-empty';
                empty.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> Tidak ada data yang cocok';
                dropdown.appendChild(empty);
                highlightedIdx = -1;
                return;
            }

            const currentVal = selectEl.value;
            filtered.forEach((it, idx) => {
                const optDiv = document.createElement('div');
                optDiv.className = 'ant-searchable-option';
                if (it.value === currentVal) optDiv.classList.add('selected');
                optDiv.dataset.value = it.value;
                optDiv.dataset.idx = idx;

                const codeHtml = highlightMatch(it.kode, filterQuery);
                const nameHtml = highlightMatch(it.nama, filterQuery);

                let metaParts = [];
                if (it.optElement && it.optElement.dataset) {
                    const ds = it.optElement.dataset;
                    if (ds.alamat) metaParts.push(ds.alamat);
                    if (ds.npwp) metaParts.push('NPWP: ' + ds.npwp);
                }
                const metaText = metaParts.join(' • ');
                const metaHtml = metaText ? `<span class="ant-searchable-meta" style="font-size:8.5px; color:var(--text-muted); max-width:240px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; text-align:right; margin-left:auto; flex-shrink:0;">${escapeHtml(metaText)}</span>` : '';

                optDiv.innerHTML = `
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; width:100%; min-width:0;">
                        <div style="display:flex; align-items:center; gap:8px; min-width:0; flex:1;">
                            <span class="ant-searchable-code" style="min-width:48px;">${codeHtml}</span>
                            <span class="ant-searchable-name" style="font-weight:500;">${nameHtml}</span>
                        </div>
                        ${metaHtml}
                    </div>
                `;

                optDiv.addEventListener('mousedown', function(e) {
                    e.preventDefault(); // prevent blur before click
                    selectItem(it.value);
                });

                optDiv.addEventListener('mouseenter', function() {
                    setHighlighted(idx);
                });

                dropdown.appendChild(optDiv);
            });

            if (filtered.length > 0 && filterQuery.trim() !== '') {
                setHighlighted(0);
            } else {
                highlightedIdx = -1;
            }
        }

        function setHighlighted(idx) {
            const allOpts = dropdown.querySelectorAll('.ant-searchable-option');
            allOpts.forEach(el => el.classList.remove('highlighted'));
            highlightedIdx = idx;
            if (idx >= 0 && idx < allOpts.length) {
                const target = allOpts[idx];
                target.classList.add('highlighted');
                // Scroll into view if needed
                const dTop = dropdown.scrollTop;
                const dBottom = dTop + dropdown.clientHeight;
                const elTop = target.offsetTop;
                const elBottom = elTop + target.clientHeight;
                if (elTop < dTop) {
                    dropdown.scrollTop = elTop;
                } else if (elBottom > dBottom) {
                    dropdown.scrollTop = elBottom - dropdown.clientHeight;
                }
            }
        }

        function updatePosition() {
            if (!isOpen) return;
            const rect = box.getBoundingClientRect();
            const spaceBelow = window.innerHeight - rect.bottom;
            const isFilter = Boolean(wrapper.closest('.browse-filter, .toolbar-filter, .ant-toolbar'));
            const minDropWidth = isFilter ? 280 : 540;
            const dropWidth = options.dropdownWidth || Math.max(rect.width, minDropWidth);

            dropdown.style.position = 'fixed';
            dropdown.style.width = dropWidth + 'px';
            dropdown.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - dropWidth - 10)) + 'px';
            dropdown.style.zIndex = '999999';

            if (spaceBelow < 260 && rect.top > 260) {
                dropdown.style.top = 'auto';
                dropdown.style.bottom = (window.innerHeight - rect.top + 2) + 'px';
            } else {
                dropdown.style.top = (rect.bottom + 2) + 'px';
                dropdown.style.bottom = 'auto';
            }
        }

        function onScrollOrResize(e) {
            if (!isOpen) return;
            if (e && e.target === dropdown) return;
            updatePosition();
        }

        function openDropdown() {
            if (isOpen) return;
            isOpen = true;
            wrapper.classList.add('active');
            dropdown.classList.add('show');
            renderDropdown(input.value || '');
            updatePosition();
            window.addEventListener('scroll', onScrollOrResize, true);
            window.addEventListener('resize', onScrollOrResize);
        }

        function closeDropdown() {
            if (!isOpen) return;
            isOpen = false;
            wrapper.classList.remove('active');
            dropdown.classList.remove('show');
            window.removeEventListener('scroll', onScrollOrResize, true);
            window.removeEventListener('resize', onScrollOrResize);

            // Auto-resolve typed text to best matching item if user didn't explicitly click
            const typed = (input.value || '').trim().toLowerCase();
            if (typed) {
                const curSelOpt = selectEl.options[selectEl.selectedIndex];
                const curVal = selectEl.value;
                if (!curVal || (curSelOpt && !curSelOpt.text.toLowerCase().includes(typed))) {
                    const match = itemsData.find(it => it.value && (it.kode.toLowerCase().includes(typed) || it.nama.toLowerCase().includes(typed) || it.text.toLowerCase().includes(typed)));
                    if (match) {
                        selectItem(match.value);
                        return;
                    }
                }
            }

            updateDisplayFromSelect();
        }

        function selectItem(val) {
            selectEl.value = val;
            updateDisplayFromSelect();
            closeDropdown();
            // Trigger native change event so all handlers run!
            selectEl.dispatchEvent(new Event('change', { bubbles: true }));
        }

        // Event Listeners
        input.addEventListener('focus', function() {
            input.select();
            openDropdown();
        });

        input.addEventListener('input', function() {
            if (!isOpen) openDropdown();
            renderDropdown(input.value);
            updatePosition();
        });

        input.addEventListener('keydown', function(e) {
            const allOpts = dropdown.querySelectorAll('.ant-searchable-option');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!isOpen) {
                    openDropdown();
                } else if (allOpts.length > 0) {
                    let next = highlightedIdx + 1;
                    if (next >= allOpts.length) next = 0;
                    setHighlighted(next);
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (isOpen && allOpts.length > 0) {
                    let prev = highlightedIdx - 1;
                    if (prev < 0) prev = allOpts.length - 1;
                    setHighlighted(prev);
                }
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (isOpen) {
                    if (highlightedIdx >= 0 && allOpts[highlightedIdx]) {
                        const val = allOpts[highlightedIdx].dataset.value;
                        selectItem(val);
                    } else if (allOpts.length > 0) {
                        const val = allOpts[0].dataset.value;
                        selectItem(val);
                    } else {
                        closeDropdown();
                    }
                }

                // Auto-trigger search if inside a toolbar / filter form
                const toolbar = wrapper.closest('.browse-filter, .toolbar-filter, .ant-toolbar');
                if (toolbar) {
                    const btn = toolbar.querySelector('.ant-btn-primary, button[type="submit"]');
                    if (btn) btn.click();
                }
            } else if (e.key === 'Escape') {
                e.preventDefault();
                closeDropdown();
            }
        });

        clearBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            selectItem('');
            input.focus();
        });

        arrowIcon.addEventListener('click', function(e) {
            e.stopPropagation();
            if (isOpen) {
                closeDropdown();
            } else {
                input.focus();
            }
        });

        document.addEventListener('click', function(e) {
            if (!wrapper.contains(e.target)) {
                closeDropdown();
            }
        });

        // Listen for programmatic change on original select
        selectEl.addEventListener('change', function() {
            updateDisplayFromSelect();
        });

        // Initialize state
        refreshItemsFromSelect();
        updateDisplayFromSelect();

        // Intercept select.value setter so direct assignments update the input display automatically
        try {
            const originalValDesc = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
            if (originalValDesc) {
                Object.defineProperty(selectEl, 'value', {
                    get: function() {
                        return originalValDesc.get.call(this);
                    },
                    set: function(val) {
                        originalValDesc.set.call(this, val);
                        updateDisplayFromSelect();
                    },
                    configurable: true
                });
            }
        } catch(e) {}

        const instance = {
            wrapper: wrapper,
            select: selectEl,
            input: input,
            findMatch: function(query) {
                if (!query) return null;
                const q = query.trim().toLowerCase();
                return itemsData.find(it => it.value && (it.kode.toLowerCase().includes(q) || it.nama.toLowerCase().includes(q) || it.text.toLowerCase().includes(q)));
            },
            sync: function() {
                refreshItemsFromSelect();
                updateDisplayFromSelect();
            },
            setValue: function(val) {
                selectItem(val);
            }
        };

        selectEl._antSearchableInstance = instance;
        selectEl.syncAntSelect = function() { instance.sync(); };

        return instance;
    }

    window.initAntSearchableSelect = initAntSearchableSelect;

})(window);

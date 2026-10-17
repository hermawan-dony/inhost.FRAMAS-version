/**
 * CEISA 4.0 - SweetAlert2 Compact Theme & Helper (sweetalert-theme.js)
 * Ant Design / Metronic compact styling:
 * - Dialog width: ~380px (proporsional & ringkas)
 * - Icon: scaled to compact 48px
 * - Title font: 15.5px (sebelumnya default 30px)
 * - Text font: 12.5px (sebelumnya default 18px)
 * - Buttons: 32px height, 12px font, 5px radius
 * - Otomatis menyesuaikan Light & Dark mode
 */

// Inject Compact CSS Stylesheet for SweetAlert2
(function initSwalCompactStyle() {
    function injectStyle() {
        if (document.getElementById('swal2-compact-theme-style')) return;
        const style = document.createElement('style');
        style.id = 'swal2-compact-theme-style';
        style.textContent = `
            /* Container & Modal */
            .swal2-popup.swal2-modal {
                width: 380px !important;
                max-width: 90vw !important;
                padding: 16px 20px 14px 20px !important;
                border-radius: 8px !important;
                box-shadow: 0 10px 32px rgba(0, 0, 0, 0.18) !important;
                font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
            }

            /* Scaled Compact Icon */
            .swal2-icon {
                transform: scale(0.60) !important;
                transform-origin: center center !important;
                margin: -8px auto -6px auto !important;
            }

            /* Compact Title */
            .swal2-title {
                font-size: 15.5px !important;
                font-weight: 600 !important;
                line-height: 1.35 !important;
                padding: 0 0 6px 0 !important;
                margin: 0 !important;
                color: inherit !important;
            }

            /* Compact Text Body */
            .swal2-html-container {
                font-size: 12.5px !important;
                line-height: 1.55 !important;
                margin: 4px 0 10px 0 !important;
                padding: 0 4px !important;
                color: inherit !important;
            }

            .swal2-html-container b,
            .swal2-html-container strong {
                font-weight: 600 !important;
                color: #1890ff;
            }

            /* Action Buttons Container */
            .swal2-actions {
                margin: 10px 0 0 0 !important;
                gap: 8px !important;
                padding: 0 !important;
            }

            /* Styled Buttons */
            .swal2-styled {
                font-size: 12px !important;
                font-weight: 500 !important;
                padding: 6px 16px !important;
                min-height: 32px !important;
                line-height: 18px !important;
                border-radius: 5px !important;
                margin: 0 !important;
                box-shadow: none !important;
                cursor: pointer !important;
                transition: all 0.2s ease !important;
            }

            .swal2-styled:hover {
                opacity: 0.9 !important;
            }

            .swal2-styled.swal2-confirm {
                background-color: #1890ff !important;
                border: 1px solid #1890ff !important;
                color: #ffffff !important;
            }

            .swal2-styled.swal2-cancel {
                background-color: #8c8c8c !important;
                border: 1px solid #8c8c8c !important;
                color: #ffffff !important;
            }

            /* Dark Mode overrides */
            [data-theme="dark"] .swal2-popup.swal2-modal,
            body[data-theme="dark"] .swal2-popup.swal2-modal {
                background: #1f1f1f !important;
                color: rgba(255, 255, 255, 0.85) !important;
                border: 1px solid #303030 !important;
            }

            [data-theme="dark"] .swal2-styled.swal2-cancel,
            body[data-theme="dark"] .swal2-styled.swal2-cancel {
                background-color: #303030 !important;
                border: 1px solid #434343 !important;
                color: #d9d9d9 !important;
            }
        `;
        (document.head || document.documentElement).appendChild(style);
    }

    if (document.head || document.documentElement) {
        injectStyle();
    } else {
        document.addEventListener('DOMContentLoaded', injectStyle);
    }
})();

function getSwalThemeOptions() {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark' || 
                   (document.body && document.body.getAttribute('data-theme') === 'dark');
    return {
        width: '380px',
        padding: '16px 20px',
        background: isDark ? '#1f1f1f' : '#ffffff',
        color: isDark ? 'rgba(255, 255, 255, 0.85)' : '#262626',
        confirmButtonColor: '#1890ff',
        cancelButtonColor: isDark ? '#303030' : '#8c8c8c'
    };
}

function showSuccess(msg, title = 'Berhasil') {
    if (typeof Swal === 'undefined') { alert(msg); return Promise.resolve(); }
    return Swal.fire({
        ...getSwalThemeOptions(),
        icon: 'success',
        title: title,
        html: String(msg || '').replace(/\n/g, '<br>'),
        confirmButtonText: 'OK'
    });
}

function showError(msg, title = 'Terjadi Kesalahan') {
    if (typeof Swal === 'undefined') { alert(msg); return Promise.resolve(); }
    return Swal.fire({
        ...getSwalThemeOptions(),
        icon: 'error',
        title: title,
        html: String(msg || '').replace(/\n/g, '<br>'),
        confirmButtonText: 'Tutup'
    });
}

function showWarning(msg, title = 'Perhatian') {
    if (typeof Swal === 'undefined') { alert(msg); return Promise.resolve(); }
    return Swal.fire({
        ...getSwalThemeOptions(),
        icon: 'warning',
        title: title,
        html: String(msg || '').replace(/\n/g, '<br>'),
        confirmButtonText: 'Mengerti'
    });
}

function showInfo(msg, title = 'Informasi') {
    if (typeof Swal === 'undefined') { alert(msg); return Promise.resolve(); }
    return Swal.fire({
        ...getSwalThemeOptions(),
        icon: 'info',
        title: title,
        html: String(msg || '').replace(/\n/g, '<br>'),
        confirmButtonText: 'OK'
    });
}

async function showConfirm(msg, title = 'Konfirmasi', confirmText = 'Ya, Lanjutkan', cancelText = 'Batal', icon = 'question') {
    if (typeof Swal === 'undefined') {
        return window.confirm(msg);
    }
    const res = await Swal.fire({
        ...getSwalThemeOptions(),
        title: title,
        html: String(msg || '').replace(/\n/g, '<br>'),
        icon: icon,
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: cancelText,
        reverseButtons: true
    });
    return res.isConfirmed;
}

// Global safety override untuk window.alert standar browser
window.alert = function(msg) {
    if (typeof Swal !== 'undefined') {
        const textStr = String(msg || '');
        let icon = 'info';
        let title = 'Informasi';
        if (textStr.toLowerCase().includes('gagal') || textStr.toLowerCase().includes('error') || textStr.toLowerCase().includes('kesalahan') || textStr.toLowerCase().includes('tidak valid')) {
            icon = 'error';
            title = 'Perhatian';
        } else if (textStr.toLowerCase().includes('berhasil') || textStr.toLowerCase().includes('sukses')) {
            icon = 'success';
            title = 'Berhasil';
        } else if (textStr.toLowerCase().includes('perhatian') || textStr.toLowerCase().includes('terkunci') || textStr.toLowerCase().includes('wajib') || textStr.toLowerCase().includes('silakan pilih')) {
            icon = 'warning';
            title = 'Peringatan';
        }
        Swal.fire({
            ...getSwalThemeOptions(),
            icon: icon,
            title: title,
            html: textStr.replace(/\n/g, '<br>'),
            confirmButtonText: 'OK'
        });
    } else {
        console.warn('Alert:', msg);
    }
};

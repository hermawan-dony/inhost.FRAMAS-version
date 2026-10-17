<?php
/**
 * CEISA 4.0 - Standalone MFA Token Window (mfa.php)
 * Menampilkan Google Authenticator TOTP token yang dapat dicopy,
 * dan otomatis berganti setiap 30 detik saat jendela terbuka.
 */
session_start();
require_once __DIR__ . '/koneksi.php';

$isAdmin = !empty($_SESSION['is_admin']) || (isset($_GET['is_admin']) && $_GET['is_admin'] === '1');
$defaultCmpId = $_REQUEST['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? '');

// Jika bukan admin dan ada session company, paksa defaultCmpId ke perusahaan user
if (!$isAdmin && !empty($_SESSION['company_cmp_id'])) {
    $defaultCmpId = $_SESSION['company_cmp_id'];
}

// Ambil daftar perusahaan: jika Admin tampilkan semua, jika bukan Admin hanya perusahaan sendiri
$companies = [];
if ($isAdmin) {
    $res = $koneksi->query("SELECT cmp_id, cmp_name, username_ceisa, IF(secret_mfa IS NOT NULL AND secret_mfa != '', 1, 0) as has_secret FROM bc_ceisa_company WHERE is_active = 1 ORDER BY cmp_name ASC");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $companies[] = $r;
            if (empty($defaultCmpId) && $r['has_secret']) {
                $defaultCmpId = $r['cmp_id'];
            }
        }
    }
} else {
    $stmt = $koneksi->prepare("SELECT cmp_id, cmp_name, username_ceisa, IF(secret_mfa IS NOT NULL AND secret_mfa != '', 1, 0) as has_secret FROM bc_ceisa_company WHERE cmp_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $defaultCmpId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $r = $res->fetch_assoc()) {
            $companies[] = $r;
        }
        $stmt->close();
    }
}

$currentCmpName = '';
foreach ($companies as $c) {
    if ($c['cmp_id'] === $defaultCmpId) {
        $currentCmpName = $c['cmp_name'];
        break;
    }
}
if (empty($currentCmpName) && !empty($companies)) {
    $currentCmpName = $companies[0]['cmp_name'];
    $defaultCmpId = $companies[0]['cmp_id'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MFA Token Ceisa 4.0</title>
    <script>
        (function() {
            var theme = localStorage.getItem('ceisa_theme') || 'light';
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>
    <link rel="icon" type="image/png" href="/assets/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Roboto+Mono:wght@500;700&display=swap" rel="stylesheet">
    <style>
        :root, [data-theme="light"] {
            --bg-base: #f0f2f5;
            --bg-card: #ffffff;
            --text-main: rgba(0, 0, 0, 0.85);
            --text-muted: #8c8c8c;
            --border-color: #e8e8e8;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --primary-light: #e6f7ff;
            --success: #52c41a;
            color-scheme: light;
        }

        [data-theme="dark"] {
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --text-main: rgba(255, 255, 255, 0.85);
            --text-muted: #8c8c8c;
            --border-color: #303030;
            --primary: #177ddc;
            --primary-hover: #3c9ae8;
            --primary-light: #112a45;
            --success: #49aa19;
            color-scheme: dark;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        body {
            background-color: var(--bg-base);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 10px;
            font-size: 10.5px;
            line-height: 1.45;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .mfa-container {
            width: 100%;
            max-width: 320px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        /* CARD SESUAI SCREENSHOT PENGGUNA */
        .totp-card {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 12px 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            cursor: pointer;
            position: relative;
            transition: all 0.2s ease;
            user-select: none;
        }

        .totp-card:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 12px rgba(24, 144, 255, 0.14);
            transform: translateY(-1px);
        }

        .totp-card:active {
            transform: translateY(0);
        }

        /* Baris 1: Issuer (portal-beacukai) */
        .totp-issuer {
            font-size: 11px;
            font-weight: 500;
            color: var(--text-main);
            line-height: 1.3;
            letter-spacing: -0.1px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .totp-badge-auto {
            font-size: 8.5px;
            font-weight: 600;
            background: #e6f7ff;
            color: #1890ff;
            padding: 1px 4px;
            border-radius: 2px;
            border: 1px solid #91d5ff;
        }

        /* Baris 2: OTP 6 Digit Besar (882437) */
        .totp-code-wrap {
            margin: 6px 0;
            display: flex;
            align-items: baseline;
            justify-content: space-between;
        }

        .totp-code {
            font-family: 'Roboto Mono', 'Poppins', monospace;
            font-size: 32px;
            font-weight: 700;
            color: #0b82df;
            letter-spacing: 1.5px;
            line-height: 1.1;
            transition: color 0.2s ease;
        }

        [data-theme="dark"] .totp-code {
            color: #177ddc;
        }

        .totp-code.is-refreshing {
            opacity: 0.4;
        }

        .totp-copy-icon {
            font-size: 11px;
            color: var(--text-muted);
            opacity: 0.6;
            transition: all 0.2s;
        }

        .totp-card:hover .totp-copy-icon {
            color: var(--primary);
            opacity: 1;
            transform: scale(1.1);
        }

        /* Baris 3: Username (maikelwawoh) */
        .totp-username {
            font-size: 11px;
            font-weight: 500;
            color: var(--text-main);
            line-height: 1.3;
        }

        /* Countdown & Timer Bar */
        .timer-strip {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px dashed var(--border-color);
            font-size: 9.5px;
            color: var(--text-muted);
        }

        .progress-bar-bg {
            flex: 1;
            height: 3px;
            background: var(--border-color);
            border-radius: 2px;
            margin: 0 8px;
            overflow: hidden;
        }

        .progress-bar-fill {
            height: 100%;
            background: var(--primary);
            width: 100%;
            transition: width 1s linear, background-color 0.3s;
        }

        .progress-bar-fill.warning {
            background: #faad14;
        }

        .progress-bar-fill.danger {
            background: #f5222d;
        }

        /* Selector Perusahaan */
        .cmp-select-wrap {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .cmp-select-wrap label {
            font-size: 9px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .cmp-select {
            width: 100%;
            height: 24px;
            border-radius: 2px;
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-main);
            padding: 0 6px;
            font-size: 10px;
            outline: none;
            cursor: pointer;
            font-family: inherit;
        }

        .cmp-select:focus {
            border-color: var(--primary);
        }

        /* Action Buttons */
        .actions-wrap {
            display: flex;
            gap: 6px;
        }

        .btn {
            flex: 1;
            height: 24px;
            border-radius: 2px;
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-main);
            font-size: 10px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            font-family: inherit;
        }

        .btn:hover {
            color: var(--primary);
            border-color: var(--primary);
            background: var(--primary-light);
        }

        .btn-primary {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        .btn-primary:hover {
            background: var(--primary-hover);
            color: #fff;
        }

        /* Toast Feedback */
        #toastMfa {
            position: fixed;
            top: 10px;
            background: rgba(0, 0, 0, 0.85);
            color: #fff;
            padding: 4px 12px;
            border-radius: 14px;
            font-size: 10px;
            font-weight: 500;
            box-shadow: 0 2px 8px rgba(0,0,0,0.25);
            display: none;
            z-index: 9999;
            animation: fadeIn 0.2s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body data-theme="light">

    <div id="toastMfa">✓ Kode OTP berhasil disalin!</div>

    <div class="mfa-container">

        <!-- Selector Perusahaan (HANYA MUNCUL JIKA LOGIN SEBAGAI ADMIN) -->
        <?php if ($isAdmin): ?>
        <div class="cmp-select-wrap">
            <label>Profil Akun Perusahaan</label>
            <select id="selectCompany" class="cmp-select" onchange="changeCompany(this.value)">
                <?php foreach ($companies as $c): ?>
                    <option value="<?= htmlspecialchars($c['cmp_id']) ?>" <?= ($c['cmp_id'] === $defaultCmpId) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['cmp_name']) ?> (<?= htmlspecialchars($c['username_ceisa'] ?: 'user') ?>) <?= $c['has_secret'] ? '🔑' : '⚠️ (No Secret)' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php else: ?>
        <div style="margin-bottom: 2px; padding: 5px 8px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 4px; display: flex; align-items: center; justify-content: space-between;">
            <div style="font-size: 10px; font-weight: 600; color: var(--text-main); display: flex; align-items: center; gap: 5px;">
                <span>🏢</span>
                <span><?= htmlspecialchars($currentCmpName) ?></span>
            </div>
            <span style="font-size: 9px; color: var(--text-muted); font-family: monospace;">[<?= htmlspecialchars($defaultCmpId) ?>]</span>
        </div>
        <?php endif; ?>

        <!-- CARD IDENTIK DENGAN SCREENSHOT USER -->
        <div class="totp-card" id="cardTotp" onclick="copyOtp()" title="Klik untuk menyalin kode OTP">
            <!-- Baris 1: Issuer -->
            <div class="totp-issuer">
                <span id="labelIssuer">portal-beacukai</span>
                <span class="totp-badge-auto">Live TOTP</span>
            </div>

            <!-- Baris 2: Code 6 Digit Besar -->
            <div class="totp-code-wrap">
                <span class="totp-code" id="labelCode">------</span>
                <span class="totp-copy-icon" title="Salin">📋 Salin</span>
            </div>

            <!-- Baris 3: Username -->
            <div class="totp-username" id="labelUsername">Memuat...</div>

            <!-- Baris 4: Countdown & Progress -->
            <div class="timer-strip">
                <span>Berubah dalam</span>
                <div class="progress-bar-bg">
                    <div class="progress-bar-fill" id="timerProgress"></div>
                </div>
                <span style="font-family:monospace; font-weight:700; width:22px; text-align:right;" id="labelSeconds">30s</span>
            </div>
        </div>

        <!-- Tombol Aksi Cepat -->
        <div class="actions-wrap">
            <button type="button" class="btn btn-primary" onclick="copyOtp()">
                <svg viewBox="64 64 896 896" width="13" height="13" fill="currentColor">
                    <path d="M832 64H296c-4.4 0-8 3.6-8 8v56c0 4.4 3.6 8 8 8h496v496c0 4.4 3.6 8 8 8h56c4.4 0 8-3.6 8-8V96c0-17.7-14.3-32-32-32zM704 192H192c-17.7 0-32 14.3-32 32v672c0 17.7 14.3 32 32 32h512c17.7 0 32-14.3 32-32V224c0-17.7-14.3-32-32-32zm-40 664H232V264h432v592z"></path>
                </svg>
                <span>Salin Kode OTP</span>
            </button>
            <button type="button" class="btn" onclick="fetchOtp(true)" title="Sinkronkan Ulang Sekarang">
                <svg viewBox="64 64 896 896" width="13" height="13" fill="currentColor">
                    <path d="M909.1 209.3l-56.4 44.1C775.8 155.1 656.2 92 521.9 92 290 92 102.3 279.5 102 511.5 101.7 743.7 289.8 932 521.9 932c181.3 0 335.8-115 394.6-276.1 1.5-4.2-.7-8.9-4.9-10.3l-56.7-19.5a8 8 0 0 0-10.1 4.8c-1.8 5-3.8 10-5.9 14.9-17.3 41-42.1 77.8-73.7 109.4A344.77 344.77 0 0 1 655.9 829c-42.3 17.9-87.4 27-133.8 27-46.5 0-91.5-9.1-133.8-27A341.5 341.5 0 0 1 279 755.2a342.16 342.16 0 0 1-73.7-109.4c-17.9-42.4-27-87.4-27-133.9s9.1-91.5 27-133.9c17.3-41 42.1-77.8 73.7-109.4 31.6-31.6 68.4-56.4 109.3-73.8 42.3-17.9 87.4-27 133.8-27 46.5 0 91.5 9.1 133.8 27a341.5 341.5 0 0 1 109.3 73.8c9.9 9.9 19.2 20.4 27.8 31.4l-60.2 47a8 8 0 0 0 3 14.1l175.6 43c5 1.2 9.9-2.6 9.9-7.7l.8-180.9c-.1-6.6-7.8-10.3-13-6.2z"></path>
                </svg>
                <span>Refresh</span>
            </button>
        </div>

    </div>

    <script>
        const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
        let currentCmpId = '<?= htmlspecialchars($defaultCmpId) ?>';
        let remainingSeconds = 30;
        let countdownTimer = null;
        let isFetching = false;

        async function fetchOtp(manual = false) {
            if (isFetching) return;
            isFetching = true;

            const codeEl = document.getElementById('labelCode');
            if (manual) {
                codeEl.classList.add('is-refreshing');
            }

            try {
                const adminParam = isAdmin ? '&is_admin=1' : '&is_admin=0';
                const res = await fetch(`api_mfa.php?cmp_id=${encodeURIComponent(currentCmpId)}${adminParam}&t=${Date.now()}`);
                const data = await res.json();

                codeEl.classList.remove('is-refreshing');

                if (data.status === 'OK') {
                    codeEl.innerText = data.otp;
                    document.getElementById('labelIssuer').innerText = data.issuer || 'portal-beacukai';
                    document.getElementById('labelUsername').innerText = data.username || 'maikelwawoh';
                    remainingSeconds = parseInt(data.remaining_seconds, 10) || 30;
                    updateTimerDisplay();
                } else if (data.status === 'EMPTY_SECRET') {
                    codeEl.innerText = 'NO SECRET';
                    document.getElementById('labelUsername').innerText = data.username || 'user';
                    codeEl.style.fontSize = '24px';
                    codeEl.style.color = '#faad14';
                } else {
                    codeEl.innerText = 'ERROR';
                    codeEl.style.fontSize = '24px';
                    codeEl.style.color = '#f5222d';
                }
            } catch (err) {
                codeEl.classList.remove('is-refreshing');
                console.error('MFA fetch error:', err);
            } finally {
                isFetching = false;
            }
        }

        function updateTimerDisplay() {
            const label = document.getElementById('labelSeconds');
            const progress = document.getElementById('timerProgress');
            
            label.innerText = `${remainingSeconds}s`;
            
            const pct = (remainingSeconds / 30) * 100;
            progress.style.width = `${pct}%`;

            progress.classList.remove('warning', 'danger');
            if (remainingSeconds <= 5) {
                progress.classList.add('danger');
            } else if (remainingSeconds <= 10) {
                progress.classList.add('warning');
            }
        }

        function startTimer() {
            if (countdownTimer) clearInterval(countdownTimer);

            countdownTimer = setInterval(() => {
                remainingSeconds--;
                if (remainingSeconds <= 0) {
                    fetchOtp();
                } else {
                    updateTimerDisplay();
                }
            }, 1000);
        }

        function changeCompany(newCmpId) {
            currentCmpId = newCmpId;
            fetchOtp(true);
        }

        function copyOtp() {
            const codeEl = document.getElementById('labelCode');
            const otp = codeEl.innerText.trim();
            if (!otp || otp === '------' || otp === 'NO SECRET' || otp === 'ERROR') return;

            navigator.clipboard.writeText(otp).then(() => {
                showToast(`✓ Kode ${otp} berhasil disalin!`);
            }).catch(() => {
                // Fallback execCommand
                const ta = document.createElement('textarea');
                ta.value = otp;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                showToast(`✓ Kode ${otp} berhasil disalin!`);
            });
        }

        function showToast(msg) {
            const t = document.getElementById('toastMfa');
            t.innerText = msg;
            t.style.display = 'block';
            setTimeout(() => { t.style.display = 'none'; }, 2400);
        }

        // Jalankan saat jendela terbuka
        window.onload = function() {
            // Ambil tema dari localStorage jika ada
            const theme = localStorage.getItem('ceisa_theme') || 'light';
            document.body.setAttribute('data-theme', theme);

            fetchOtp();
            startTimer();
        };

        // Sinkronkan ulang bila jendela aktif kembali
        window.onfocus = function() {
            fetchOtp();
        };
    </script>
</body>
</html>

<?php
declare(strict_types=1);

require_once __DIR__ . '/otp-common.php';

if (empty($_SESSION['password_reset_email']) || empty($_SESSION['password_reset_user_id'])) {
    header('Location: ./forgot-password.php');
    exit;
}

$email = (string) $_SESSION['password_reset_email'];
$maskedEmail = otp_mask_email($email);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Reset Code | SNDRA Park</title>
    <link rel="icon" type="image/png" href="./assets/images/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="./frontend/css/design-system.css?v=20260828-reset1">
    <link rel="stylesheet" href="./frontend/css/auth.css?v=20260828-reset1">
    <link rel="stylesheet" href="./frontend/css/reset-auth.css?v=20260828-reset1">
</head>
<body class="auth-page reset-page">
    <main class="auth-shell">
        <aside class="auth-aside">
            <div class="auth-aside-inner">
                <div class="auth-brand">
                    <img src="./assets/images/brand-mark.png" alt="">
                    <span class="auth-brand-name">SNDRA Park</span>
                </div>

                <div class="auth-aside-copy">
                    <p class="auth-aside-kicker">Account recovery</p>
                    <h2>Check your inbox.</h2>
                    <p>The code is good for five minutes. Requesting another one cancels it.</p>
                </div>

                <p class="auth-aside-foot">&copy; 2026 &middot; All rights reserved.</p>
            </div>
        </aside>

        <section class="auth-main">
            <div class="auth-main-top">
                <a class="auth-back" href="./forgot-password.php">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M19 12H5"></path>
                        <path d="m12 19-7-7 7-7"></path>
                    </svg>
                    <span>Change email</span>
                </a>

                <p class="reset-steps" role="img" aria-label="Step 2 of 3">
                    <span class="reset-step is-done"></span>
                    <span class="reset-step is-current"></span>
                    <span class="reset-step"></span>
                </p>
            </div>

            <div class="auth-form-wrap">
                <div class="auth-copy">
                    <h1>Enter your code</h1>
                    <p class="auth-subtitle">
                        Sent to <strong><?= htmlspecialchars($maskedEmail, ENT_QUOTES, 'UTF-8'); ?></strong>.
                        If you asked more than once, use the newest email.
                    </p>
                </div>

                <form id="verify-otp-form" class="auth-form" novalidate>
                    <div class="field-group">
                        <label class="field-label" for="otp">6-digit code</label>
                        <div class="input-shell">
                            <input class="auth-input otp-input" id="otp" name="otp" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" required autofocus>
                        </div>
                    </div>

                    <button class="action-btn primary-btn" id="verify-button" type="submit">Verify code</button>

                    <p class="form-status" id="form-status" aria-live="polite"></p>
                </form>

                <p class="reset-foot">
                    <a class="text-link" href="./forgot-password.php">Send a new code</a>
                    <a class="text-link" href="./frontend/pages/login.html">Back to login</a>
                </p>
            </div>
        </section>
    </main>

    <script src="./assets/js/reset-flow.js?v=20260828-reset1"></script>
    <script>
        const otpInput = document.getElementById('otp');
        const form = document.getElementById('verify-otp-form');
        const button = document.getElementById('verify-button');
        const statusBox = document.getElementById('form-status');

        otpInput.addEventListener('input', () => {
            otpInput.value = otpInput.value.replace(/\D/g, '').slice(0, 6);
        });

        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const otp = otpInput.value.trim();

            if (!/^\d{6}$/.test(otp)) {
                ResetFlow.setStatus(statusBox, 'Please enter the full 6-digit code.', 'error');
                ResetFlow.reject(otpInput);
                return;
            }

            ResetFlow.setBusy(button, true);
            ResetFlow.setStatus(statusBox, 'Checking code...');

            try {
                const response = await fetch('./check-reset-otp.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ otp })
                });

                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Code verification failed.');
                }

                ResetFlow.setStatus(statusBox, result.message || 'Code verified.', 'success');
                window.setTimeout(() => {
                    window.location.href = result.redirect || './reset-password.php';
                }, 700);
            } catch (error) {
                ResetFlow.setStatus(statusBox, error.message || 'Code verification failed.', 'error');
                ResetFlow.reject(otpInput);
                ResetFlow.setBusy(button, false);
            }
        });
    </script>
</body>
</html>

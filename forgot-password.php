<?php
declare(strict_types=1);

require_once __DIR__ . '/otp-common.php';

if (otp_has_verified_session()) {
    header('Location: ./reset-password.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | SNDRA Park</title>
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
                    <h2>Forgot your password?</h2>
                    <p>Tell us the email on your account and we will send a code to get you back in.</p>
                </div>

                <p class="auth-aside-foot">&copy; 2026 &middot; All rights reserved.</p>
            </div>
        </aside>

        <section class="auth-main">
            <div class="auth-main-top">
                <a class="auth-back" href="./frontend/pages/login.html">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M19 12H5"></path>
                        <path d="m12 19-7-7 7-7"></path>
                    </svg>
                    <span>Back to login</span>
                </a>

                <p class="reset-steps" role="img" aria-label="Step 1 of 3">
                    <span class="reset-step is-current"></span>
                    <span class="reset-step"></span>
                    <span class="reset-step"></span>
                </p>
            </div>

            <div class="auth-form-wrap">
                <div class="auth-copy">
                    <h1>Reset your password</h1>
                    <p class="auth-subtitle">We will email you a 6-digit code. It expires after 5 minutes.</p>
                </div>

                <form id="forgotPasswordForm" class="auth-form" novalidate>
                    <div class="field-group">
                        <label class="field-label" for="email">Email</label>
                        <div class="input-shell">
                            <input class="auth-input" id="email" name="email" type="email" placeholder="you@example.com" autocomplete="email" required>
                        </div>
                    </div>

                    <button class="action-btn primary-btn" id="sendOtpButton" type="submit">Send code</button>

                    <p class="form-status" id="alertBox" aria-live="polite"></p>
                </form>

                <p class="reset-foot">
                    <a class="text-link" href="./frontend/pages/login.html">Back to login</a>
                    <a class="text-link" href="./frontend/pages/index.html">Back home</a>
                </p>
            </div>
        </section>
    </main>

    <script src="./assets/js/reset-flow.js?v=20260828-reset1"></script>
    <script>
        const form = document.getElementById('forgotPasswordForm');
        const sendOtpButton = document.getElementById('sendOtpButton');
        const alertBox = document.getElementById('alertBox');
        const emailInput = document.getElementById('email');

        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const email = emailInput.value.trim();

            if (!email) {
                ResetFlow.setStatus(alertBox, 'Please enter your email address.', 'error');
                ResetFlow.reject(emailInput);
                return;
            }

            ResetFlow.setBusy(sendOtpButton, true);
            ResetFlow.setStatus(alertBox, 'Sending code...');

            try {
                const response = await fetch('./backend/auth/send-reset-otp.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ email })
                });

                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Unable to send the code.');
                }

                ResetFlow.setStatus(alertBox, result.message, 'success');
                window.setTimeout(() => {
                    window.location.href = result.redirect || './verify-reset-otp.php';
                }, 900);
            } catch (error) {
                ResetFlow.setStatus(alertBox, error.message || 'Unable to send the code.', 'error');
                ResetFlow.reject(emailInput);
                ResetFlow.setBusy(sendOtpButton, false);
            }
        });
    </script>
</body>
</html>

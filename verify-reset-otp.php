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
    <title>Verify Reset OTP | SNDRA Park</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="./style.css">
</head>
<body>
    <main class="reset-shell">
    <section class="card">
        <p class="eyebrow">OTP Verification</p>
        <h1>Enter the 6-digit code.</h1>
        <p>We sent an OTP to <strong><?= htmlspecialchars($maskedEmail, ENT_QUOTES, 'UTF-8'); ?></strong>. The code expires in 5 minutes.</p>

        <form id="verify-otp-form" novalidate>
            <div class="field-group" id="otp-field-group">
                <input id="otp" name="otp" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder=" " required>
                <label for="otp">One-Time Password</label>
            </div>

            <button id="verify-button" type="submit">Verify OTP</button>
            <div id="form-status" class="alert" aria-live="polite"></div>
        </form>

        <div class="meta-links">
            <a href="./forgot-password.php">Change Email / Resend OTP</a>
            <a href="./frontend/pages/login.html">Back to Login</a>
        </div>

        <p>If the OTP expires, go back to the previous page and send a new one.</p>
    </section>
    </main>

    <script>
        const otpInput = document.getElementById('otp');
        const form = document.getElementById('verify-otp-form');
        const button = document.getElementById('verify-button');
        const statusBox = document.getElementById('form-status');
        const otpFieldGroup = document.getElementById('otp-field-group');

        function setStatus(message, type) {
            statusBox.textContent = message;
            statusBox.className = 'alert show' + (type ? ' ' + type : '');
        }

        function syncFloatingState() {
            otpFieldGroup.classList.toggle('is-active', document.activeElement === otpInput || otpInput.value.trim() !== '');
        }

        otpInput.addEventListener('input', () => {
            otpInput.value = otpInput.value.replace(/\D/g, '').slice(0, 6);
            syncFloatingState();
        });
        otpInput.addEventListener('focus', syncFloatingState);
        otpInput.addEventListener('blur', syncFloatingState);
        syncFloatingState();

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const otp = otpInput.value.trim();

            if (!/^\d{6}$/.test(otp)) {
                setStatus('Please enter the full 6-digit OTP.', 'error');
                otpInput.focus();
                return;
            }

            button.disabled = true;
            setStatus('Checking OTP...', '');

            try {
                const response = await fetch('./check-reset-otp.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ otp })
                });

                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'OTP verification failed.');
                }

                setStatus(result.message || 'OTP verified.', 'success');
                window.setTimeout(() => {
                    window.location.href = result.redirect || './reset-password.php';
                }, 700);
            } catch (error) {
                setStatus(error.message || 'OTP verification failed.', 'error');
            } finally {
                button.disabled = false;
            }
        });
    </script>
</body>
</html>

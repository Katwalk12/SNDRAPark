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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="./style.css">
</head>
<body>
    <main class="reset-shell">
    <div class="card">
        <p class="eyebrow">Password Reset</p>
        <h1>Send a reset code.</h1>
        <p>Enter your registered email address to receive a 6-digit OTP. The code expires after 5 minutes.</p>

        <form id="forgotPasswordForm">
            <div class="field-group" id="email-field-group">
                <input type="email" id="email" name="email" placeholder=" " required>
                <label for="email">Email Address</label>
            </div>

            <button type="submit" id="sendOtpButton">Send OTP</button>
            <div id="alertBox" class="alert"></div>
        </form>
        <div class="meta-links">
            <a href="./frontend/pages/login.html">Back to Login</a>
            <a href="./frontend/pages/index.html">Back Home</a>
        </div>
    </div>
    </main>

    <script>
        const form = document.getElementById('forgotPasswordForm');
        const sendOtpButton = document.getElementById('sendOtpButton');
        const alertBox = document.getElementById('alertBox');
        const emailInput = document.getElementById('email');
        const emailFieldGroup = document.getElementById('email-field-group');

        function showAlert(message, type) {
            alertBox.textContent = message;
            alertBox.className = 'alert show ' + type;
        }

        function syncFloatingState() {
            emailFieldGroup.classList.toggle('is-active', document.activeElement === emailInput || emailInput.value.trim() !== '');
        }

        emailInput.addEventListener('focus', syncFloatingState);
        emailInput.addEventListener('blur', syncFloatingState);
        emailInput.addEventListener('input', syncFloatingState);
        syncFloatingState();

        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const email = document.getElementById('email').value.trim();

            if (!email) {
                showAlert('Please enter your email address.', 'error');
                return;
            }

            sendOtpButton.disabled = true;
            showAlert('Sending OTP...', 'success');

            try {
                const response = await fetch('./backend/auth/send-reset-otp.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ email })
                });

                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Unable to send OTP.');
                }

                showAlert(result.message, 'success');

                setTimeout(() => {
                    window.location.href = result.redirect || './verify-reset-otp.php';
                }, 900);
            } catch (error) {
                showAlert(error.message || 'Unable to send OTP.', 'error');
            } finally {
                sendOtpButton.disabled = false;
            }
        });
    </script>
</body>
</html>

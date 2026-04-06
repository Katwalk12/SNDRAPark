<?php
declare(strict_types=1);

require_once __DIR__ . '/otp-common.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!otp_has_verified_session()) {
        otp_clear_reset_session();
        otp_json_response(false, 'Your reset session has expired. Please verify a new OTP.', 401);
    }

    try {
        $data = otp_request_data();
        $password = (string) ($data['password'] ?? '');
        $confirmPassword = (string) ($data['confirm_password'] ?? '');

        if ($password === '' || $confirmPassword === '') {
            otp_json_response(false, 'Please fill in both password fields.', 422);
        }

        if ($password !== $confirmPassword) {
            otp_json_response(false, 'Passwords do not match.', 422);
        }

        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{8,}$/', $password)) {
            otp_json_response(false, 'Password must contain upper, lower, number, symbol, and at least 8 characters.', 422);
        }

        $connection = otp_db();
        $userId = (int) $_SESSION['password_reset_user_id'];
        $email = (string) $_SESSION['password_reset_email'];
        $passwordColumn = otp_get_password_column($connection);
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $statement = $connection->prepare("
            UPDATE users
            SET {$passwordColumn} = ?, reset_otp_hash = NULL, reset_otp_expires_at = NULL, reset_otp_verified_at = NULL
            WHERE id = ? AND email = ?
        ");
        $statement->bind_param('sis', $passwordHash, $userId, $email);
        $statement->execute();

        if ($statement->affected_rows === 0) {
            otp_json_response(false, 'Password update failed. Please restart the reset process.', 404);
        }

        otp_clear_reset_session();
        session_regenerate_id(true);

        otp_json_response(true, 'Your password has been reset successfully.', 200, [
            'redirect' => './frontend/pages/login.html',
        ]);
    } catch (Throwable $exception) {
        otp_json_response(false, $exception->getMessage(), 500);
    }
}

if (!otp_has_verified_session()) {
    otp_clear_reset_session();
    header('Location: ./forgot-password.php');
    exit;
}

$email = (string) $_SESSION['password_reset_email'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | SNDRA Park</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="./style.css">
</head>
<body>
    <main class="reset-shell">
    <section class="card">
        <p class="eyebrow">Password Reset</p>
        <h1>Create your new password.</h1>
        <p>Set a new password for <strong><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></strong>.</p>

        <form id="reset-password-form" novalidate>
            <div class="field-group" id="password-field-group">
                <input id="password" name="password" type="password" autocomplete="new-password" placeholder=" " required>
                <label for="password">New Password</label>
            </div>

            <div class="field-group" id="confirm-password-field-group">
                <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" placeholder=" " required>
                <label for="confirm_password">Confirm Password</label>
            </div>

            <button id="reset-button" type="submit">Update Password</button>
            <div id="form-status" class="alert" aria-live="polite"></div>
        </form>

        <div class="meta-links">
            <a href="./forgot-password.php">Start again</a>
            <a href="./frontend/pages/login.html">Back to Login</a>
        </div>
    </section>
    </main>

    <script>
        const form = document.getElementById('reset-password-form');
        const button = document.getElementById('reset-button');
        const statusBox = document.getElementById('form-status');
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const passwordFieldGroup = document.getElementById('password-field-group');
        const confirmPasswordFieldGroup = document.getElementById('confirm-password-field-group');

        function setStatus(message, type) {
            statusBox.textContent = message;
            statusBox.className = 'alert show' + (type ? ' ' + type : '');
        }

        function syncFloatingState() {
            passwordFieldGroup.classList.toggle('is-active', document.activeElement === passwordInput || passwordInput.value.trim() !== '');
            confirmPasswordFieldGroup.classList.toggle('is-active', document.activeElement === confirmPasswordInput || confirmPasswordInput.value.trim() !== '');
        }

        passwordInput.addEventListener('focus', syncFloatingState);
        passwordInput.addEventListener('blur', syncFloatingState);
        passwordInput.addEventListener('input', syncFloatingState);
        confirmPasswordInput.addEventListener('focus', syncFloatingState);
        confirmPasswordInput.addEventListener('blur', syncFloatingState);
        confirmPasswordInput.addEventListener('input', syncFloatingState);
        syncFloatingState();

        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;

            if (!password || !confirmPassword) {
                setStatus('Please fill in both password fields.', 'error');
                return;
            }

            button.disabled = true;
            setStatus('Updating password...', '');

            try {
                const response = await fetch('./reset-password.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        password,
                        confirm_password: confirmPassword
                    })
                });

                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Unable to update password.');
                }

                setStatus(result.message || 'Password updated successfully.', 'success');
                window.setTimeout(() => {
                    window.location.href = result.redirect || './frontend/pages/login.html';
                }, 900);
            } catch (error) {
                setStatus(error.message || 'Unable to update password.', 'error');
            } finally {
                button.disabled = false;
            }
        });
    </script>
</body>
</html>

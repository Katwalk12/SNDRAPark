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

        $connection = otp_db();
        $userId = (int) $_SESSION['password_reset_user_id'];
        $email = (string) $_SESSION['password_reset_email'];

        // Same policy as sign up: the new password may not reuse the account's own details.
        $account = otp_find_user_for_reset($connection, $userId, $email);
        $policyErrors = PasswordPolicy::check($password, PasswordPolicy::contextFromUser($account));

        if (!empty($policyErrors)) {
            otp_json_response(false, $policyErrors[0], 422, ['errors' => $policyErrors]);
        }

        $passwordColumn = otp_get_password_column($connection);
        $currentHash = otp_get_current_password_hash($connection, $userId, $passwordColumn);

        if ($currentHash !== '' && password_verify($password, $currentHash)) {
            otp_json_response(false, 'New password must be different from your current password.', 422);
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $extraColumns = otp_password_reset_extra_columns($connection);

        $statement = $connection->prepare("
            UPDATE users
            SET {$passwordColumn} = ?, {$extraColumns}
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
            'redirect' => './frontend/pages/login.html?reason=password-reset',
        ]);
    } catch (Throwable $exception) {
        otp_fail($exception, 'reset-password');
    }
}

if (!otp_has_verified_session()) {
    otp_clear_reset_session();
    header('Location: ./forgot-password.php');
    exit;
}

$email = (string) $_SESSION['password_reset_email'];
$passwordRules = PasswordPolicy::describe();

// The account has already been verified through the OTP, so its own details can be
// used client-side to warn about easily guessed passwords before submitting.
$passwordContext = ['email' => $email];

try {
    $resetAccount = otp_find_user_for_reset(otp_db(), (int) $_SESSION['password_reset_user_id'], $email);

    if ($resetAccount) {
        $passwordContext = [
            'email' => $email,
            'firstName' => (string) ($resetAccount['first_name'] ?? ''),
            'lastName' => (string) ($resetAccount['last_name'] ?? ''),
            'fullName' => (string) ($resetAccount['full_name'] ?? ''),
            'birthDate' => (string) ($resetAccount['birth_date'] ?? ''),
        ];
    }
} catch (Throwable $exception) {
    // Fall back to the email only; the server still enforces the full policy.
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Password | SNDRA Park</title>
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
                    <h2>Almost done.</h2>
                    <p>Pick something you have not used on this account before.</p>
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
                    <span>Start again</span>
                </a>

                <p class="reset-steps" role="img" aria-label="Step 3 of 3">
                    <span class="reset-step is-done"></span>
                    <span class="reset-step is-done"></span>
                    <span class="reset-step is-current"></span>
                </p>
            </div>

            <div class="auth-form-wrap">
                <div class="auth-copy">
                    <h1>Set a new password</h1>
                    <p class="auth-subtitle">For <strong><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></strong>.</p>
                </div>

                <form id="reset-password-form" class="auth-form" novalidate>
                    <div class="field-group">
                        <label class="field-label" for="password">New password</label>
                        <div class="input-shell has-action">
                            <input class="auth-input" id="password" name="password" type="password" autocomplete="new-password" required>
                            <button class="password-toggle" type="button" aria-label="Show password" aria-pressed="false" data-password-toggle data-target="password">
                                <svg class="eye-icon eye-open" viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M1.5 12S5.5 5 12 5s10.5 7 10.5 7-4 7-10.5 7S1.5 12 1.5 12Z"></path>
                                    <circle cx="12" cy="12" r="3.2"></circle>
                                </svg>
                                <svg class="eye-icon eye-closed" viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M3 3l18 18"></path>
                                    <path d="M10.7 5.2A9.5 9.5 0 0 1 12 5c6.5 0 10.5 7 10.5 7a18 18 0 0 1-3.2 3.8"></path>
                                    <path d="M6.3 6.3A18 18 0 0 0 1.5 12S5.5 19 12 19c1.5 0 2.8-.3 4-.8"></path>
                                    <path d="M9.9 9.9A3.2 3.2 0 0 0 14.1 14.1"></path>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <ul class="password-rules" data-password-rules data-target="password" role="list" aria-label="Password requirements">
                        <?php foreach ($passwordRules as $rule): ?>
                            <li><?= htmlspecialchars($rule, ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>

                    <div class="field-group">
                        <label class="field-label" for="confirm_password">Confirm password</label>
                        <div class="input-shell has-action">
                            <input class="auth-input" id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required>
                            <button class="password-toggle" type="button" aria-label="Show password" aria-pressed="false" data-password-toggle data-target="confirm_password">
                                <svg class="eye-icon eye-open" viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M1.5 12S5.5 5 12 5s10.5 7 10.5 7-4 7-10.5 7S1.5 12 1.5 12Z"></path>
                                    <circle cx="12" cy="12" r="3.2"></circle>
                                </svg>
                                <svg class="eye-icon eye-closed" viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M3 3l18 18"></path>
                                    <path d="M10.7 5.2A9.5 9.5 0 0 1 12 5c6.5 0 10.5 7 10.5 7a18 18 0 0 1-3.2 3.8"></path>
                                    <path d="M6.3 6.3A18 18 0 0 0 1.5 12S5.5 19 12 19c1.5 0 2.8-.3 4-.8"></path>
                                    <path d="M9.9 9.9A3.2 3.2 0 0 0 14.1 14.1"></path>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <button class="action-btn primary-btn" id="reset-button" type="submit">Update password</button>

                    <p class="form-status" id="form-status" aria-live="polite"></p>
                </form>

                <p class="reset-foot">
                    <a class="text-link" href="./forgot-password.php">Start again</a>
                    <a class="text-link" href="./frontend/pages/login.html">Back to login</a>
                </p>
            </div>
        </section>
    </main>

    <script src="./assets/js/password-policy.js"></script>
    <script src="./assets/js/reset-flow.js?v=20260828-reset1"></script>
    <script>
        const form = document.getElementById('reset-password-form');
        const button = document.getElementById('reset-button');
        const statusBox = document.getElementById('form-status');
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const passwordRulesList = document.querySelector('[data-password-rules]');
        const passwordPolicy = window.SndraPasswordPolicy || null;
        const passwordContext = <?= json_encode($passwordContext, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        // Turn the server-rendered rule list into a live checklist.
        function refreshPasswordRules() {
            if (!passwordPolicy || !passwordRulesList) {
                return null;
            }

            if (!passwordRulesList.dataset.rendered) {
                passwordRulesList.innerHTML = passwordPolicy.RULES
                    .map((rule) => '<li class="password-rule" data-rule="' + rule.id + '"><span class="rule-mark" aria-hidden="true"></span>' + rule.label + '</li>')
                    .join('');
                passwordRulesList.dataset.rendered = 'true';
            }

            const result = passwordPolicy.evaluate(passwordInput.value, passwordContext);

            passwordRulesList.querySelectorAll('[data-rule]').forEach((item) => {
                item.classList.toggle('is-met', Boolean(result.checks[item.dataset.rule]));
            });

            return result;
        }

        passwordInput.addEventListener('input', refreshPasswordRules);
        refreshPasswordRules();

        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const password = passwordInput.value;
            const confirmPassword = confirmPasswordInput.value;

            if (!password || !confirmPassword) {
                ResetFlow.setStatus(statusBox, 'Please fill in both password fields.', 'error');
                ResetFlow.reject(password ? confirmPasswordInput : passwordInput);
                return;
            }

            if (password !== confirmPassword) {
                ResetFlow.setStatus(statusBox, 'Passwords do not match.', 'error');
                ResetFlow.reject(confirmPasswordInput);
                return;
            }

            const policyResult = refreshPasswordRules();

            if (policyResult && !policyResult.valid) {
                ResetFlow.setStatus(statusBox, policyResult.errors[0], 'error');
                ResetFlow.reject(passwordInput);
                return;
            }

            ResetFlow.setBusy(button, true);
            ResetFlow.setStatus(statusBox, 'Updating password...');

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

                ResetFlow.setStatus(statusBox, result.message || 'Password updated.', 'success');
                window.setTimeout(() => {
                    window.location.href = result.redirect || './frontend/pages/login.html';
                }, 900);
            } catch (error) {
                ResetFlow.setStatus(statusBox, error.message || 'Unable to update password.', 'error');
                ResetFlow.reject(passwordInput);
                ResetFlow.setBusy(button, false);
            }
        });
    </script>
</body>
</html>

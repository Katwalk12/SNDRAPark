<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../utils/PasswordPolicy.php';

/**
 * Self-service for the signed-in staff account: change your own email address
 * and your own password.
 *
 * Before this there was no way to do either. staff_accounts was written only
 * by the login path (last_login_at), so the seeded 'Admin123!' was whatever
 * every deployment still had, and rotating it meant hand-editing the database
 * with a bcrypt hash.
 *
 * Deliberately '*' rather than 'admin': this changes nothing but the caller's
 * own credentials, so a supervisor -- read-only over everyone else's data --
 * is still entitled to it.
 */
$admin = admin_require_auth('*');

try {
    $connection = admin_db();

    if (admin_method() === 'GET') {
        admin_success('Account loaded successfully.', [
            'account' => [
                'id' => $admin['id'],
                'fullName' => $admin['fullName'],
                'email' => $admin['email'],
                'role' => $admin['role']
            ],
            'passwordPolicy' => PasswordPolicy::describe()
        ]);
    }

    admin_require_method('POST');
    admin_require_csrf();

    $statement = $connection->prepare("
        SELECT id, full_name, username, email, password_hash, role, is_active
        FROM staff_accounts
        WHERE id = ?
        LIMIT 1
    ");
    $statement->bind_param('i', $admin['id']);
    $statement->execute();
    $staff = $statement->get_result()->fetch_assoc();

    if (!$staff || (int) $staff['is_active'] !== 1) {
        admin_error('That account can no longer be changed.', 403);
    }

    // The current password is the whole security of this endpoint: it is what
    // stops a borrowed session from locking the real owner out by changing the
    // password and address out from under them.
    $currentPassword = (string) admin_input('current_password', '');

    if ($currentPassword === '') {
        admin_error('Enter your current password to save changes.', 422);
    }

    if (!admin_staff_password_verify($currentPassword, (string) $staff['password_hash'])) {
        admin_audit_log($connection, $admin, 'ADMIN_ACCOUNT_UPDATE_FAILED', 'An account change was refused because the current password was wrong.', [
            'target_type' => 'staff_account',
            'target_id' => (string) $staff['id'],
            'status' => 'failure'
        ]);

        // 422, not 401: the dashboard's postJson() treats 401/403/419 as an
        // expired session and bounces to the login page, so a simple typo in
        // this field would log the administrator out instead of telling them
        // the password was wrong.
        admin_error('Your current password is not correct.', 422);
    }

    $currentEmail = (string) $staff['email'];
    $newEmail = strtolower(admin_clean_text(admin_input('email', $currentEmail)));
    $newPassword = (string) admin_input('new_password', '');
    $confirmPassword = (string) admin_input('confirm_password', '');

    if ($newEmail === '') {
        admin_error('An email address is required.', 422);
    }

    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        admin_error('Enter a valid email address.', 422);
    }

    if (strlen($newEmail) > 150) {
        admin_error('That email address is too long.', 422);
    }

    $emailChanged = strtolower($currentEmail) !== $newEmail;
    $passwordChanged = $newPassword !== '';

    if (!$emailChanged && !$passwordChanged) {
        admin_error('Nothing to change.', 422);
    }

    if ($emailChanged) {
        // The column is UNIQUE, so this is only for the readable error --
        // without it a clash surfaces as a 500 from the driver.
        $duplicate = $connection->prepare("
            SELECT id FROM staff_accounts WHERE email = ? AND id <> ? LIMIT 1
        ");
        $duplicate->bind_param('si', $newEmail, $staff['id']);
        $duplicate->execute();

        if ($duplicate->get_result()->fetch_assoc()) {
            admin_error('Another staff account already uses that email address.', 409);
        }
    }

    if ($passwordChanged) {
        if ($newPassword !== $confirmPassword) {
            admin_error('The new password and its confirmation do not match.', 422);
        }

        if (admin_staff_password_verify($newPassword, (string) $staff['password_hash'])) {
            admin_error('Choose a password you have not used here before.', 422);
        }

        try {
            // Checked against the name and address so 'sndrapark2026' and the
            // like are refused, same as on the member side.
            PasswordPolicy::validate($newPassword, PasswordPolicy::contextFromUser([
                'full_name' => $staff['full_name'],
                'email' => $newEmail
            ]));
        } catch (InvalidArgumentException $policyException) {
            admin_error($policyException->getMessage(), 422);
        }
    }

    if ($passwordChanged) {
        $passwordHash = admin_staff_password_hash($newPassword);
        $update = $connection->prepare("
            UPDATE staff_accounts SET email = ?, password_hash = ? WHERE id = ?
        ");
        $update->bind_param('ssi', $newEmail, $passwordHash, $staff['id']);
    } else {
        $update = $connection->prepare("UPDATE staff_accounts SET email = ? WHERE id = ?");
        $update->bind_param('si', $newEmail, $staff['id']);
    }

    $update->execute();

    $changes = [];

    if ($emailChanged) {
        $changes[] = 'email address';
    }

    if ($passwordChanged) {
        $changes[] = 'password';
    }

    // Logged against the OLD address on purpose: admin_dispatch_change_alert()
    // mails whoever the audit row names, and the person who needs to hear that
    // an account was taken over is whoever owned it a moment ago.
    admin_audit_log($connection, $admin, 'ADMIN_ACCOUNT_UPDATED', 'Admin changed their own ' . implode(' and ', $changes) . '.', [
        'target_type' => 'staff_account',
        'target_id' => (string) $staff['id'],
        'status' => 'success',
        'admin_email' => $currentEmail,
        'alert_details' => array_filter([
            'Changed' => implode(' and ', $changes),
            'New email address' => $emailChanged ? $newEmail : ''
        ]),
        'metadata' => [
            'email_changed' => $emailChanged,
            'password_changed' => $passwordChanged
        ]
    ]);

    // A credential change retires the old session id, so a copy of the cookie
    // taken before the change stops working.
    session_regenerate_id(true);

    $_SESSION['sndra_admin']['email'] = $newEmail;
    $_SESSION['_admin_last_activity'] = time();
    $csrfToken = CsrfMiddleware::refresh();

    admin_success('Account updated successfully.', [
        'account' => [
            'id' => (int) $staff['id'],
            'fullName' => (string) $staff['full_name'],
            'email' => $newEmail,
            'role' => (string) $staff['role']
        ],
        'emailChanged' => $emailChanged,
        'passwordChanged' => $passwordChanged,
        'csrfToken' => $csrfToken
    ]);
} catch (Throwable $exception) {
    admin_log('update-account-failed', [
        'method' => admin_method(),
        'error' => $exception->getMessage()
    ]);
    admin_error('Failed to update the account.', 500, admin_debug_details($exception));
}

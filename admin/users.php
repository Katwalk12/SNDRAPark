<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/admin/common.php';
require_once __DIR__ . '/../backend/common/reservation-security.php';

if (empty($_SESSION['sndra_admin']) || (string) ($_SESSION['sndra_admin']['role'] ?? '') !== 'admin') {
    header('Location: ../frontend/pages/admin-login.html');
    exit;
}

$connection = admin_db();
reservation_security_expire_due_reservations($connection);
$flashMessage = '';
$flashType = 'success';

if (admin_method() === 'POST') {
    $action = admin_clean_text(admin_input('action'));
    $userId = (int) admin_input('user_id');

    try {
        if ($userId <= 0) {
            throw new RuntimeException('Invalid user selected.');
        }

        if ($action === 'disable') {
            $statement = $connection->prepare("UPDATE users SET status = 'Disabled' WHERE id = ?");
            $statement->bind_param('i', $userId);
            $statement->execute();
            $flashMessage = 'User account disabled successfully.';
        } elseif ($action === 'activate') {
            $statement = $connection->prepare("UPDATE users SET status = 'Active' WHERE id = ?");
            $statement->bind_param('i', $userId);
            $statement->execute();
            $flashMessage = 'User account activated successfully.';
        } elseif ($action === 'delete') {
            $countStatement = $connection->prepare("SELECT COUNT(*) AS total FROM reservations WHERE user_id = ?");
            $countStatement->bind_param('i', $userId);
            $countStatement->execute();
            $reservationCount = (int) (($countStatement->get_result()->fetch_assoc()['total'] ?? 0));

            if ($reservationCount > 0) {
                throw new RuntimeException('This user already has reservation records and cannot be deleted. Disable the account instead.');
            }

            $deleteStatement = $connection->prepare("DELETE FROM users WHERE id = ?");
            $deleteStatement->bind_param('i', $userId);
            $deleteStatement->execute();
            $flashMessage = 'User account deleted successfully.';
        } elseif ($action === 'unlock') {
            reservation_security_unlock_user_account($connection, $userId, true);
            $flashMessage = 'User account unlocked successfully.';
        }
    } catch (Throwable $exception) {
        $flashMessage = $exception->getMessage();
        $flashType = 'error';
    }
}

$users = [];
$result = $connection->query("
    SELECT
        u.id,
        u.full_name,
        u.email,
        u.birth_date,
        COALESCE(u.status, 'Active') AS status,
        COALESCE(u.warning_count, 0) AS warning_count,
        u.first_warning_at,
        COALESCE(u.account_status, 'active') AS account_status,
        u.account_locked_until,
        u.created_at,
        COUNT(r.id) AS reservation_count
    FROM users u
    LEFT JOIN reservations r ON r.user_id = u.id
    GROUP BY u.id, u.full_name, u.email, u.birth_date, u.status, u.warning_count, u.first_warning_at, u.account_status, u.account_locked_until, u.created_at
    ORDER BY u.created_at DESC, u.id DESC
");

while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}

function admin_users_escape(?string $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function admin_users_format_date(?string $value): string
{
    if (!$value) {
        return '--';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('M d, Y', $timestamp) : (string) $value;
}

function admin_users_format_datetime(?string $value): string
{
    if (!$value) {
        return '--';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('M d, Y h:i A', $timestamp) : (string) $value;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SNDRA Park | Admin Users</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg: #050505;
      --panel: rgba(17, 17, 17, 0.92);
      --panel-soft: rgba(255, 255, 255, 0.03);
      --border: rgba(255, 255, 255, 0.12);
      --text: #f8f8f8;
      --muted: rgba(255, 255, 255, 0.68);
      --accent: #f1c451;
      --danger: #ff8f8f;
      --success: #bff0cf;
    }

    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      font-family: "Montserrat", "Segoe UI", sans-serif;
      color: var(--text);
      background:
        radial-gradient(circle at top left, rgba(255, 255, 255, 0.08), transparent 26%),
        radial-gradient(circle at bottom right, rgba(255, 255, 255, 0.03), transparent 22%),
        linear-gradient(135deg, #050505 0%, #020202 100%);
    }

    .page-shell {
      width: min(1320px, calc(100% - 2rem));
      margin: 0 auto;
      padding: 1.4rem 0 2rem;
    }

    .hero-card,
    .panel-card {
      border: 1px solid var(--border);
      border-radius: 1.7rem;
      background:
        radial-gradient(circle at top right, rgba(255, 213, 79, 0.06), transparent 24%),
        linear-gradient(180deg, rgba(18, 18, 18, 0.88), rgba(7, 7, 7, 0.98));
      box-shadow: 0 20px 42px rgba(0, 0, 0, 0.28);
    }

    .hero-card {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 1rem;
      padding: 1.5rem;
      margin-bottom: 1rem;
    }

    .section-kicker {
      margin: 0 0 0.5rem;
      color: var(--muted);
      font-size: 0.76rem;
      letter-spacing: 0.18em;
      text-transform: uppercase;
    }

    h1 {
      margin: 0;
      font-size: clamp(2rem, 4vw, 3rem);
      line-height: 0.98;
    }

    .hero-copy p,
    .meta-chip,
    td,
    .table-empty {
      color: var(--muted);
    }

    .hero-copy p {
      margin: 0.8rem 0 0;
      max-width: 58ch;
      line-height: 1.75;
    }

    .hero-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
    }

    .hero-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 0.85rem 1.1rem;
      border: 1px solid var(--border);
      border-radius: 999px;
      background: var(--panel-soft);
      color: var(--text);
      text-decoration: none;
    }

    .panel-card { padding: 1.2rem; }

    .status-banner {
      margin-bottom: 1rem;
      padding: 0.9rem 1rem;
      border: 1px solid var(--border);
      border-radius: 1rem;
      background: var(--panel-soft);
    }

    .status-banner.is-error {
      color: var(--danger);
      border-color: rgba(255, 143, 143, 0.24);
      background: rgba(255, 143, 143, 0.08);
    }

    .status-banner.is-success {
      color: var(--success);
      border-color: rgba(191, 240, 207, 0.24);
      background: rgba(191, 240, 207, 0.08);
    }

    .table-shell {
      overflow-x: auto;
      overflow-y: hidden;
      padding-bottom: 0.35rem;
    }

    table {
      width: 100%;
      min-width: 1440px;
      border-collapse: collapse;
      table-layout: auto;
    }

    th, td {
      padding: 1rem 1rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
      text-align: left;
      vertical-align: middle;
      line-height: 1.6;
    }

    th {
      color: rgba(255, 255, 255, 0.56);
      font-size: 0.68rem;
      letter-spacing: 0.16em;
      text-transform: uppercase;
      white-space: nowrap;
    }

    td {
      overflow-wrap: anywhere;
      word-break: break-word;
    }

    td strong {
      color: var(--text);
    }

    .status-pill {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 84px;
      padding: 0.4rem 0.7rem;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.05);
      font-size: 0.74rem;
      font-weight: 600;
      text-transform: uppercase;
    }

    .status-pill.is-active {
      color: var(--success);
      background: rgba(191, 240, 207, 0.1);
    }

    .status-pill.is-disabled {
      color: var(--danger);
      background: rgba(255, 143, 143, 0.1);
    }

    .action-stack {
      display: flex;
      flex-wrap: wrap;
      gap: 0.45rem;
      min-width: 220px;
    }

    .action-stack form { margin: 0; }

    .action-btn {
      padding: 0.56rem 0.85rem;
      border: 1px solid var(--border);
      border-radius: 0.8rem;
      background: rgba(255, 255, 255, 0.03);
      color: var(--text);
      cursor: pointer;
      white-space: nowrap;
    }

    .action-btn-danger {
      border-color: rgba(255, 143, 143, 0.24);
      color: var(--danger);
      background: rgba(255, 143, 143, 0.06);
    }

    @media (max-width: 720px) {
      .page-shell { width: min(100% - 1rem, 100%); }
      .hero-card { flex-direction: column; }
      .hero-actions { width: 100%; }
      .hero-link { width: 100%; }
    }
  </style>
</head>
<body>
  <main class="page-shell">
    <section class="hero-card">
      <div class="hero-copy">
        <p class="section-kicker">Admin Users</p>
        <h1>Registered user accounts</h1>
        <p>All newly registered users appear here from the shared MySQL users table. This page gives the admin a direct server-rendered view of persistent user records, account status, and reservation count.</p>
      </div>

      <div class="hero-actions">
        <a class="hero-link" href="../frontend/pages/admin-dashboard.html">Open Admin Dashboard</a>
        <a class="hero-link" href="../frontend/pages/index.html">Back to Website</a>
      </div>
    </section>

    <?php if ($flashMessage !== ''): ?>
      <div class="status-banner <?php echo $flashType === 'error' ? 'is-error' : 'is-success'; ?>">
        <?php echo admin_users_escape($flashMessage); ?>
      </div>
    <?php endif; ?>

    <section class="panel-card">
      <div class="table-shell">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Full Name</th>
              <th>Email</th>
              <th>Birth Date</th>
              <th>Date Created</th>
              <th>Status</th>
              <th>Warning Count</th>
              <th>First Warning</th>
              <th>Account Status</th>
              <th>Locked Until</th>
              <th>Reservations</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($users)): ?>
              <tr>
                <td class="table-empty" colspan="12">No registered users yet.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($users as $user): ?>
                <?php $isDisabled = (($user['status'] ?? 'Active') === 'Disabled'); ?>
                <?php $isLocked = (($user['account_status'] ?? 'active') === 'locked'); ?>
                <tr>
                  <td><?php echo admin_users_escape((string) ($user['id'] ?? '--')); ?></td>
                  <td><strong><?php echo admin_users_escape($user['full_name'] ?? '--'); ?></strong></td>
                  <td><?php echo admin_users_escape($user['email'] ?? '--'); ?></td>
                  <td><?php echo admin_users_escape(admin_users_format_date($user['birth_date'] ?? null)); ?></td>
                  <td><?php echo admin_users_escape(admin_users_format_datetime($user['created_at'] ?? null)); ?></td>
                  <td>
                    <span class="status-pill <?php echo $isDisabled ? 'is-disabled' : 'is-active'; ?>">
                      <?php echo admin_users_escape($user['status'] ?? 'Active'); ?>
                    </span>
                  </td>
                  <td><?php echo admin_users_escape((string) ($user['warning_count'] ?? '0')); ?></td>
                  <td><?php echo admin_users_escape(admin_users_format_datetime($user['first_warning_at'] ?? null)); ?></td>
                  <td>
                    <span class="status-pill <?php echo $isLocked ? 'is-disabled' : 'is-active'; ?>">
                      <?php echo admin_users_escape($user['account_status'] ?? 'active'); ?>
                    </span>
                  </td>
                  <td><?php echo admin_users_escape(admin_users_format_datetime($user['account_locked_until'] ?? null)); ?></td>
                  <td><?php echo admin_users_escape((string) ($user['reservation_count'] ?? '0')); ?></td>
                  <td>
                    <div class="action-stack">
                      <?php if ($isLocked): ?>
                        <form method="post">
                          <input type="hidden" name="user_id" value="<?php echo admin_users_escape((string) ($user['id'] ?? '0')); ?>">
                          <input type="hidden" name="action" value="unlock">
                          <button class="action-btn" type="submit">Unlock</button>
                        </form>
                      <?php endif; ?>
                      <form method="post">
                        <input type="hidden" name="user_id" value="<?php echo admin_users_escape((string) ($user['id'] ?? '0')); ?>">
                        <input type="hidden" name="action" value="<?php echo $isDisabled ? 'activate' : 'disable'; ?>">
                        <button class="action-btn" type="submit"><?php echo $isDisabled ? 'Activate' : 'Disable'; ?></button>
                      </form>
                      <form method="post" onsubmit="return confirm('Delete this user account?');">
                        <input type="hidden" name="user_id" value="<?php echo admin_users_escape((string) ($user['id'] ?? '0')); ?>">
                        <input type="hidden" name="action" value="delete">
                        <button class="action-btn action-btn-danger" type="submit">Delete</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</body>
</html>

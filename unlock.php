<?php
/**
 * unlock.php — one-shot utility to clear account lockouts.
 *
 * Visit http://localhost/assetdesk/unlock.php
 * Only works on localhost (blocked on any other host).
 * Delete this file before deploying anywhere.
 */

require 'config.php';

/* ---- Guard: only allow access from localhost ---- */
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$allowed = in_array($ip, ['127.0.0.1', '::1'], true);

if (!$allowed) {
    http_response_code(403);
    exit('Access denied. This utility only runs on localhost.');
}

$pdo = db();
$didUnlock = false;
$unlocked  = 0;

/* ---- Handle the "Unlock" button ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock'])) {
    $stmt = $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE failed_attempts > 0 OR locked_until IS NOT NULL');
    $stmt->execute();
    $unlocked  = $stmt->rowCount();
    $didUnlock = true;
}

/* ---- Load current state of all users ---- */
$users = $pdo->query('
    SELECT id, username, full_name, role, status,
           failed_attempts, locked_until, last_login
    FROM users
    ORDER BY id
')->fetchAll();

/* Any currently locked? */
$lockedNow = 0;
$now = time();
foreach ($users as $u) {
    if ($u['locked_until'] && strtotime($u['locked_until']) > $now) $lockedNow++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>AssetDesk · Unlock Accounts</title>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
<link rel="stylesheet" href="assets/style.css" />
</head>
<body style="padding:40px;">

<div style="max-width:1000px;margin:0 auto;">

    <div class="flex-between mb-20">
        <div class="brand" style="margin-bottom:0;">
            <div class="mark">AD</div>
            AssetDesk · Unlock Accounts
        </div>
        <a href="index.php" class="btn btn-outline">
            <i class="fas fa-home"></i> Home
        </a>
    </div>

    <div class="banner warn">
        <i class="fas fa-exclamation-triangle"></i>
        <div>
            <strong>Localhost utility.</strong>
            This page is not part of the app. It's here so you can reset lockouts
            during development. <strong>Delete this file before showing your project
            to anyone.</strong>
        </div>
    </div>

    <?php if ($didUnlock): ?>
        <div class="banner success" data-autohide>
            <i class="fas fa-check-circle"></i>
            <div>
                <strong>Done.</strong>
                <?= (int)$unlocked ?> account<?= $unlocked === 1 ? '' : 's' ?> unlocked
                (failed_attempts = 0, locked_until = NULL).
            </div>
        </div>
    <?php endif; ?>

    <div class="card mb-20">
        <div class="flex-between">
            <div>
                <h3 style="margin-bottom:4px;">Status</h3>
                <p class="text-muted" style="margin:0;font-size:13px;">
                    Currently locked accounts:
                    <strong class="<?= $lockedNow ? 'text-red' : 'text-green' ?>">
                        <?= (int)$lockedNow ?>
                    </strong>
                </p>
            </div>
            <form method="POST" style="margin:0;">
                <button type="submit" name="unlock" value="1"
                        class="btn btn-primary"
                        onclick="return confirm('Unlock all accounts?');">
                    <i class="fas fa-unlock"></i> Unlock All Accounts
                </button>
            </form>
        </div>
    </div>

    <div class="table-card">
        <div class="table-header">
            <h3>All Accounts</h3>
            <span class="text-muted" style="font-size:12px;">
                <?= count($users) ?> total
            </span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Full Name</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Failed</th>
                    <th>Locked Until</th>
                    <th>Last Login</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u):
                    $isLocked = $u['locked_until'] && strtotime($u['locked_until']) > time();
                ?>
                    <tr>
                        <td class="text-muted">#<?= (int)$u['id'] ?></td>
                        <td class="text-bright fw-600"><?= htmlspecialchars($u['username']) ?></td>
                        <td><?= htmlspecialchars($u['full_name']) ?></td>
                        <td>
                            <span class="badge <?=
                                $u['role'] === 'superadmin' ? 'purple' :
                                ($u['role'] === 'admin' ? 'info' : 'muted')
                            ?>">
                                <?= ucfirst(htmlspecialchars($u['role'])) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= $u['status'] === 'active' ? 'success' : 'danger' ?>">
                                <?= ucfirst(htmlspecialchars($u['status'])) ?>
                            </span>
                        </td>
                        <td class="<?= (int)$u['failed_attempts'] > 0 ? 'text-orange fw-600' : '' ?>">
                            <?= (int)$u['failed_attempts'] ?>
                        </td>
                        <td>
                            <?php if ($isLocked): ?>
                                <span class="badge danger"><?= htmlspecialchars($u['locked_until']) ?></span>
                            <?php elseif ($u['locked_until']): ?>
                                <span class="text-muted" style="font-size:12px;">expired</span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted" style="font-size:12px;">
                            <?= $u['last_login'] ? htmlspecialchars($u['last_login']) : '—' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h3>What this does</h3>
        <ul class="visual-list" style="margin-top:0;">
            <li><i class="fas fa-check"></i> Sets <code>failed_attempts = 0</code> for all users</li>
            <li><i class="fas fa-check"></i> Sets <code>locked_until = NULL</code> for all users</li>
            <li><i class="fas fa-check"></i> Only runs on <code>127.0.0.1</code> / <code>::1</code></li>
            <li><i class="fas fa-times text-red"></i> Does <strong>not</strong> change passwords</li>
            <li><i class="fas fa-times text-red"></i> Does <strong>not</strong> create or delete users</li>
        </ul>
        <p class="text-muted" style="font-size:12px;margin-top:12px;">
            Note: failed login attempts are still logged in <code>audit_logs</code> —
            this utility only resets the counters and lock timers on the <code>users</code> table.
        </p>
    </div>

</div>

<script src="assets/app.js"></script>
</body>
</html>
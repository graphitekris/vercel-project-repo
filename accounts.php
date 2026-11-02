<?php
require 'config.php';
require 'auth.php';

$user = require_role('admin', 'superadmin');
$isSuperadmin = ($user['role'] === 'superadmin');

$pageTitle = 'Accounts';
$activeNav = 'accounts';

$flash = '';
$flashType = 'success';
$errors = [];

$action = $_GET['action'] ?? 'list';
$editId = (int)($_GET['id'] ?? 0);

/* ============================================================
   GUARD — only Superadmins can reach forms/actions
   ============================================================ */
if (!$isSuperadmin && in_array($action, ['new', 'edit'], true)) {
    header('Location: accounts.php');
    exit;
}

/* ============================================================
   RESET LOCKOUT
   ============================================================ */
if ($action === 'unlock' && $editId > 0 && $isSuperadmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $stmt = db()->prepare('SELECT username, role FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$editId]);
        $target = $stmt->fetch();

        if ($target) {
            db()->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')
                ->execute([$editId]);

            log_action($user['id'], $user['username'], $user['role'],
                "Reset lockout for {$target['username']}", 'accounts', 'success');

            $flash = "Lockout reset for {$target['username']}.";
        }
    } catch (Throwable $e) {
        error_log('Unlock failed: ' . $e->getMessage());
        $flash = 'Could not reset lockout.'; $flashType = 'danger';
    }
    $action = 'list';
}

/* ============================================================
   DISABLE / ENABLE
   ============================================================ */
if (in_array($action, ['disable', 'enable'], true) && $editId > 0 && $isSuperadmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $stmt = db()->prepare('SELECT username, role, status FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$editId]);
        $target = $stmt->fetch();

        if (!$target) {
            $flash = 'User not found.'; $flashType = 'danger';
        } elseif ($action === 'disable') {
            if ((int)$target['id'] === (int)$user['id']) {
                $flash = 'You cannot disable your own account.'; $flashType = 'danger';
            } elseif ($target['role'] === 'superadmin') {
                $flash = 'You cannot disable a Superadmin.'; $flashType = 'danger';
            } else {
                db()->prepare("UPDATE users SET status = 'disabled' WHERE id = ?")->execute([$editId]);
                log_action($user['id'], $user['username'], $user['role'],
                    "Disabled account {$target['username']}", 'accounts', 'warning');
                $flash = "{$target['username']} disabled.";
            }
        } elseif ($action === 'enable') {
            db()->prepare("UPDATE users SET status = 'active', failed_attempts = 0, locked_until = NULL WHERE id = ?")
                ->execute([$editId]);
            log_action($user['id'], $user['username'], $user['role'],
                "Enabled account {$target['username']}", 'accounts', 'success');
            $flash = "{$target['username']} enabled.";
        }
    } catch (Throwable $e) {
        error_log('Enable/disable failed: ' . $e->getMessage());
        $flash = 'Action failed.'; $flashType = 'danger';
    }
    $action = 'list';
}

/* ============================================================
   CREATE / UPDATE
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['new', 'edit'], true)) {

    $fullName    = trim($_POST['full_name'] ?? '');
    $username    = trim($_POST['username'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $role        = $_POST['role'] ?? 'user';
    $password    = $_POST['password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $newIp       = trim($_POST['last_ip'] ?? '');

    if ($fullName === '') $errors[] = 'Full name is required.';
    if (!in_array($role, ['user', 'admin'], true) && $role !== 'superadmin') {
        $errors[] = 'Invalid role.';
    }
    if ($role === 'superadmin' && $action === 'new') {
        $errors[] = 'Superadmin accounts cannot be created through the app.';
    }

    if ($action === 'new') {
        if ($username === '') $errors[] = 'Username is required.';
        elseif (strlen($username) < 3) $errors[] = 'Username must be at least 3 characters.';
        elseif (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) $errors[] = 'Username may only contain letters, numbers, dots, underscores, and dashes.';
        if ($password === '') $errors[] = 'Initial password is required.';
        elseif (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    }

    // IP validation (very loose — accepts IPv4, IPv6, or hostname)
    if ($newIp !== '' && strlen($newIp) > 45) {
        $errors[] = 'IP address is too long.';
    }

    // Duplicate username
    if ($action === 'new' && !$errors) {
        $stmt = db()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        if ($stmt->fetch()) $errors[] = 'That username is already taken.';
    }

    if (!$errors) {
        try {
            if ($action === 'new') {
                $hash   = password_hash($password, PASSWORD_DEFAULT);
                $avatar = strtoupper(substr($fullName, 0, 2));

                db()->prepare('
                    INSERT INTO users (full_name, username, email, password_hash, role, avatar, avatar_color, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, "active")
                ')->execute([$fullName, $username, $email ?: null, $hash, $role, $avatar, '#007acc']);

                log_action($user['id'], $user['username'], $user['role'],
                    "Created account {$username} ({$role})", 'accounts', 'success');

                header('Location: accounts.php?flash=created');
                exit;

            } else {
                // EDIT
                $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
                $stmt->execute([$editId]);
                $cur = $stmt->fetch();

                if (!$cur) {
                    $errors[] = 'User not found.';
                }

                // Superadmin role lock
                if (!$errors && $cur['role'] === 'superadmin' && $role !== 'superadmin') {
                    $errors[] = 'The Superadmin role is locked. It cannot be changed through the app.';
                }
                if (!$errors && $cur['role'] !== 'superadmin' && $role === 'superadmin') {
                    $errors[] = 'Superadmin cannot be assigned through the app.';
                }

                if (!$errors) {
                    $fields = ['full_name = ?', 'email = ?', 'role = ?'];
                    $params = [$fullName, $email ?: null, $role];

                    if ($newPassword !== '') {
                        if (strlen($newPassword) < 6) {
                            $errors[] = 'New password must be at least 6 characters.';
                        } else {
                            $fields[] = 'password_hash = ?';
                            $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
                        }
                    }

                    // IP change → update all of this user's audit_logs rows
                    $oldIp = null;
                    if ($newIp !== '') {
                        $ipStmt = db()->prepare('
                            SELECT ip_address FROM audit_logs
                            WHERE user_id = ?
                            ORDER BY id DESC LIMIT 1
                        ');
                        $ipStmt->execute([$editId]);
                        $oldIp = $ipStmt->fetchColumn() ?: '—';
                    }

                    if (!$errors) {
                        $params[] = $editId;
                        db()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')
                            ->execute($params);

                        $logMsg = "Updated account {$cur['username']}";
                        if ($newPassword !== '') $logMsg .= ' (password reset)';
                        log_action($user['id'], $user['username'], $user['role'], $logMsg, 'accounts', 'success');

                        // If IP changed, update all of the target user's log rows
                        if ($newIp !== '' && $newIp !== $oldIp) {
                            db()->prepare('UPDATE audit_logs SET ip_address = ? WHERE user_id = ?')
                                ->execute([$newIp, $editId]);

                            // Log the fact that the IP was edited (audit of the audit)
                            log_action(
                                $user['id'],
                                $user['username'],
                                $user['role'],
                                "Updated IP for {$cur['username']}: {$oldIp} → {$newIp}",
                                'accounts',
                                'warning'
                            );
                        }

                        header('Location: accounts.php?flash=updated');
                        exit;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('Account save failed: ' . $e->getMessage());
            $errors[] = 'Database error. Please try again.';
        }
    }
}

/* ---------- Flash ---------- */
if (isset($_GET['flash'])) {
    $flash = match($_GET['flash']) {
        'created' => 'Account created successfully.',
        'updated' => 'Account updated.',
        default   => '',
    };
}

/* ============================================================
   LOAD DATA
   ============================================================ */
$editUser = null;
if ($action === 'edit' && $editId > 0 && $isSuperadmin) {
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$editId]);
    $editUser = $stmt->fetch();
    if (!$editUser) { header('Location: accounts.php'); exit; }

    // Fetch current IP
    $stmt = db()->prepare('SELECT ip_address FROM audit_logs WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$editId]);
    $editUser['last_ip'] = $stmt->fetchColumn() ?: '';
}

$users = db()->query("
    SELECT u.id, u.full_name, u.username, u.email, u.role, u.status, u.avatar, u.avatar_color,
           u.failed_attempts, u.locked_until, u.last_login, u.created_at,
           (SELECT ip_address FROM audit_logs WHERE user_id = u.id ORDER BY id DESC LIMIT 1) AS last_ip
    FROM users u
    ORDER BY FIELD(u.role, 'superadmin','admin','user'), u.full_name
")->fetchAll();

include 'header.php';
?>

<?php if ($flash): ?>
    <div class="banner <?= $flashType ?>" data-autohide>
        <i class="fas fa-<?= $flashType === 'danger' ? 'exclamation-circle' : 'check-circle' ?>"></i>
        <span><?= htmlspecialchars($flash) ?></span>
    </div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="banner danger">
        <i class="fas fa-exclamation-circle"></i>
        <div>
            <strong>Please fix the following:</strong>
            <ul style="margin:6px 0 0 18px;">
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<?php /* =========================================================
   LIST VIEW
   ========================================================= */ ?>
<?php if ($action === 'list'): ?>

<?php if (!$isSuperadmin): ?>
    <div class="banner info">
        <i class="fas fa-eye"></i>
        <span>
            <strong>Read-only view.</strong> Admins can see the account list but
            cannot create, edit, disable, or reset passwords. Only Superadmins
            can manage accounts.
        </span>
    </div>
<?php else: ?>
    <div class="banner info">
        <i class="fas fa-shield-alt"></i>
        <span>
            <strong>User management.</strong> Create accounts, assign roles,
            reset passwords, and reset lockouts. The
            <strong>Superadmin role is locked</strong>. The
            <strong>Last IP</strong> column can be edited per user and any
            change is logged as a warning.
        </span>
    </div>
<?php endif; ?>

<div class="flex-between mb-16">
    <div class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" placeholder="Search by name or username..." data-filter-target="#accountsTable" />
    </div>
    <?php if ($isSuperadmin): ?>
        <a href="accounts.php?action=new" class="btn btn-primary">
            <i class="fas fa-user-plus"></i> Create Account
        </a>
    <?php endif; ?>
</div>

<div class="table-card">
    <table id="accountsTable">
        <thead>
            <tr>
                <th>Name</th>
                <th>Username</th>
                <th>Role</th>
                <th>Status</th>
                <th>Lockout</th>
                <th>Last Login</th>
                <th>Last IP</th>
                <?php if ($isSuperadmin): ?><th style="text-align:right;">Actions</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u):
            $roleClass = match($u['role']) {
                'superadmin' => 'purple',
                'admin'      => 'info',
                'user'       => 'muted',
                default      => 'muted',
            };
            $isLocked   = $u['locked_until'] && strtotime($u['locked_until']) > time();
            $isSelf     = ((int)$u['id'] === (int)$user['id']);
            $isDisabled = ($u['status'] === 'disabled');
            $isSuper    = ($u['role'] === 'superadmin');
        ?>
            <tr>
                <td>
                    <div class="flex" style="gap:10px;">
                        <div class="avatar" style="width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff;background:<?= htmlspecialchars($u['avatar_color'] ?: '#888') ?>;">
                            <?= htmlspecialchars($u['avatar'] ?: strtoupper(substr($u['full_name'], 0, 2))) ?>
                        </div>
                        <div>
                            <div class="text-bright fw-600"><?= htmlspecialchars($u['full_name']) ?></div>
                            <div class="text-muted" style="font-size:11px;"><?= htmlspecialchars($u['email'] ?? '—') ?></div>
                        </div>
                    </div>
                </td>
                <td class="text-bright"><?= htmlspecialchars($u['username']) ?></td>
                <td>
                    <span class="badge <?= $roleClass ?>">
                        <?= ucfirst(htmlspecialchars($u['role'])) ?>
                        <?php if ($isSuper): ?><i class="fas fa-lock" style="margin-left:4px;font-size:9px;"></i><?php endif; ?>
                    </span>
                </td>
                <td>
                    <?php if ($isDisabled): ?>
                        <span class="badge danger">Disabled</span>
                    <?php else: ?>
                        <span class="badge success">Active</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($isLocked): ?>
                        <span class="badge danger">
                            <i class="fas fa-lock"></i>
                            until <?= date('H:i', strtotime($u['locked_until'])) ?>
                        </span>
                    <?php elseif ((int)$u['failed_attempts'] > 0): ?>
                        <span class="badge warning"><?= (int)$u['failed_attempts'] ?> failed</span>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="text-muted" style="font-size:12px;">
                    <?= $u['last_login'] ? htmlspecialchars($u['last_login']) : 'never' ?>
                </td>
                <td class="text-muted" style="font-size:12px;font-family:Consolas,monospace;">
                    <?= htmlspecialchars($u['last_ip'] ?? '—') ?>
                </td>
                <?php if ($isSuperadmin): ?>
                    <td style="text-align:right;white-space:nowrap;">
                        <?php if ($isLocked): ?>
                            <form method="POST" action="accounts.php?action=unlock&id=<?= (int)$u['id'] ?>" style="display:inline-block;margin:0;">
                                <button type="submit" class="btn btn-outline"
                                        style="padding:4px 10px;font-size:12px;color:var(--green);border-color:rgba(78,201,176,0.3);">
                                    <i class="fas fa-unlock"></i> Unlock
                                </button>
                            </form>
                        <?php endif; ?>
                        <a href="accounts.php?action=edit&id=<?= (int)$u['id'] ?>"
                           class="btn btn-outline" style="padding:4px 10px;font-size:12px;">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                        <?php if (!$isSelf && !$isSuper): ?>
                            <?php if ($isDisabled): ?>
                                <form method="POST" action="accounts.php?action=enable&id=<?= (int)$u['id'] ?>" style="display:inline-block;margin:0;">
                                    <button type="submit" class="btn btn-outline"
                                            style="padding:4px 10px;font-size:12px;color:var(--green);border-color:rgba(78,201,176,0.3);">
                                        <i class="fas fa-check"></i> Enable
                                    </button>
                                </form>
                            <?php else: ?>
                                <form method="POST" action="accounts.php?action=disable&id=<?= (int)$u['id'] ?>" style="display:inline-block;margin:0;">
                                    <button type="submit" class="btn btn-outline"
                                            style="padding:4px 10px;font-size:12px;color:var(--red);border-color:rgba(244,71,71,0.3);"
                                            data-confirm="Disable <?= htmlspecialchars($u['username']) ?>? Their history stays intact.">
                                        <i class="fas fa-ban"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php /* =========================================================
   NEW FORM
   ========================================================= */ ?>
<?php elseif ($action === 'new'): ?>

<div class="card" style="max-width:640px;margin:0 auto;">
    <div class="flex-between mb-20">
        <h3 style="margin:0;">Create Account</h3>
        <a href="accounts.php" class="btn btn-outline">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>

    <form method="POST" action="accounts.php?action=new">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

            <div class="form-group">
                <label>Full Name <span class="text-red">*</span></label>
                <div class="input-wrap">
                    <i class="fas fa-user icon"></i>
                    <input type="text" name="full_name" required
                           value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Username <span class="text-red">*</span></label>
                <div class="input-wrap">
                    <i class="fas fa-at icon"></i>
                    <input type="text" name="username" required
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Email</label>
                <div class="input-wrap">
                    <i class="fas fa-envelope icon"></i>
                    <input type="email" name="email"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Role <span class="text-red">*</span></label>
                <div class="input-wrap">
                    <i class="fas fa-user-tag icon"></i>
                    <select name="role" required style="padding-left:40px;">
                        <option value="user"  <?= ($_POST['role'] ?? '') === 'user'  ? 'selected' : '' ?>>User</option>
                        <option value="admin" <?= ($_POST['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
                    </select>
                </div>
                <p class="text-muted" style="font-size:11px;margin-top:4px;">
                    <i class="fas fa-lock"></i> Superadmin is a locked role. To create one, use database access.
                </p>
            </div>

        </div>

        <div class="form-group">
            <label>Initial Password <span class="text-red">*</span></label>
            <div class="input-wrap">
                <i class="fas fa-lock icon"></i>
                <input type="text" name="password" required minlength="6"
                       placeholder="At least 6 characters"
                       value="<?= htmlspecialchars($_POST['password'] ?? '') ?>">
            </div>
        </div>

        <div class="flex mt-12" style="gap:10px;">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-user-plus"></i> Create Account
            </button>
            <a href="accounts.php" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>

<?php /* =========================================================
   EDIT FORM
   ========================================================= */ ?>
<?php elseif ($action === 'edit' && $editUser): ?>

<?php $editingSuperadmin = ($editUser['role'] === 'superadmin'); ?>

<div class="card" style="max-width:640px;margin:0 auto;">
    <div class="flex-between mb-20">
        <h3 style="margin:0;">Edit Account</h3>
        <a href="accounts.php" class="btn btn-outline">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>

    <?php if ($editingSuperadmin): ?>
        <div class="banner warn">
            <i class="fas fa-lock"></i>
            <span>
                <strong>Superadmin account.</strong> The role is locked and
                cannot be changed through the app. You may still edit name,
                email, password, and IP.
            </span>
        </div>
    <?php else: ?>
        <div class="banner info">
            <i class="fas fa-info-circle"></i>
            <span>
                Editing <strong><?= htmlspecialchars($editUser['username']) ?></strong>.
                Username cannot be changed.
            </span>
        </div>
    <?php endif; ?>

    <form method="POST" action="accounts.php?action=edit&id=<?= (int)$editUser['id'] ?>">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

            <div class="form-group">
                <label>Full Name <span class="text-red">*</span></label>
                <div class="input-wrap">
                    <i class="fas fa-user icon"></i>
                    <input type="text" name="full_name" required
                           value="<?= htmlspecialchars($_POST['full_name'] ?? $editUser['full_name']) ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Username</label>
                <div class="input-wrap">
                    <i class="fas fa-at icon"></i>
                    <input type="text" value="<?= htmlspecialchars($editUser['username']) ?>" disabled
                           style="opacity:0.6;cursor:not-allowed;">
                </div>
            </div>

            <div class="form-group">
                <label>Email</label>
                <div class="input-wrap">
                    <i class="fas fa-envelope icon"></i>
                    <input type="email" name="email"
                           value="<?= htmlspecialchars($_POST['email'] ?? $editUser['email'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group">
                <label>
                    Role
                    <?php if ($editingSuperadmin): ?>
                        <span class="text-muted" style="font-weight:400;">(locked)</span>
                    <?php else: ?>
                        <span class="text-red">*</span>
                    <?php endif; ?>
                </label>
                <div class="input-wrap">
                    <i class="fas fa-user-tag icon"></i>
                    <?php if ($editingSuperadmin): ?>
                        <input type="text" value="Superadmin" disabled
                               style="opacity:0.6;cursor:not-allowed;">
                        <input type="hidden" name="role" value="superadmin">
                    <?php else: ?>
                        <select name="role" required style="padding-left:40px;">
                            <?php $r = $_POST['role'] ?? $editUser['role']; ?>
                            <option value="user"  <?= $r === 'user'  ? 'selected' : '' ?>>User</option>
                            <option value="admin" <?= $r === 'admin' ? 'selected' : '' ?>>Admin</option>
                        </select>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <div class="form-group">
            <label>New Password <span class="text-muted" style="font-weight:400;">(leave blank to keep current)</span></label>
            <div class="input-wrap">
                <i class="fas fa-key icon"></i>
                <input type="text" name="new_password" minlength="6"
                       placeholder="Set a new password">
            </div>
        </div>

        <div class="form-group">
            <label>Last Known IP</label>
            <div class="input-wrap">
                <i class="fas fa-network-wired icon"></i>
                <input type="text" name="last_ip" maxlength="45"
                       placeholder="e.g. 127.0.0.1"
                       value="<?= htmlspecialchars($_POST['last_ip'] ?? $editUser['last_ip'] ?? '') ?>">
            </div>
            <p class="text-muted" style="font-size:11px;margin-top:4px;">
                <i class="fas fa-exclamation-triangle" style="color:var(--orange);"></i>
                This updates the IP shown in the audit log for all of this user's existing log entries.
                The change is itself logged as a <strong>warning</strong>.
            </p>
        </div>

        <div class="flex mt-12" style="gap:10px;">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Changes
            </button>
            <a href="accounts.php" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>

<?php endif; ?>

<?php include 'footer.php'; ?>
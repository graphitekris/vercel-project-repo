<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const MAX_ATTEMPTS    = 3;
const LOCKOUT_MINUTES = 15;

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function require_login(): array {
    $u = current_user();
    if (!$u) { header('Location: signin.php'); exit; }
    return $u;
}

function require_role(string ...$roles): array {
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        exit('Access denied.');
    }
    return $u;
}

function log_action(
    ?int $userId, ?string $username, ?string $role,
    string $action, string $module, string $status = 'success'
): void {
    $pdo = db();
    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs (user_id, username, role, action, module, status, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId, $username, $role, $action, $module, $status,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

function attempt_login(string $username, string $password): array {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        log_action(null, $username, null, 'Failed login (unknown user)', 'auth', 'failed');
        return ['ok' => false, 'msg' => 'Invalid username or password.'];
    }

    if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
        $mins = (int)ceil((strtotime($user['locked_until']) - time()) / 60);
        return ['ok' => false, 'msg' => "Account locked. Try again in {$mins} minute(s)."];
    }

    if ($user['status'] === 'disabled') {
        return ['ok' => false, 'msg' => 'Account disabled. Contact your Superadmin.'];
    }

    if (!password_verify($password, $user['password_hash'])) {
        if ($user['role'] === 'superadmin') {
            log_action($user['id'], $user['username'], $user['role'],
                'Failed login attempt (Superadmin — exempt from lockout)', 'auth', 'warning');
            return ['ok' => false, 'msg' => 'Invalid credentials.'];
        }

        $attempts = (int)$user['failed_attempts'] + 1;
        if ($attempts >= MAX_ATTEMPTS) {
            $until = date('Y-m-d H:i:s', time() + LOCKOUT_MINUTES * 60);
            $pdo->prepare('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?')
                ->execute([$attempts, $until, $user['id']]);
            log_action($user['id'], $user['username'], $user['role'],
                'Failed login (' . MAX_ATTEMPTS . ' attempts) — account locked', 'auth', 'failed');
            return ['ok' => false, 'msg' => 'Too many failed attempts. Account locked for ' . LOCKOUT_MINUTES . ' minutes.'];
        }

        $pdo->prepare('UPDATE users SET failed_attempts = ? WHERE id = ?')
            ->execute([$attempts, $user['id']]);
        $remaining = MAX_ATTEMPTS - $attempts;
        log_action($user['id'], $user['username'], $user['role'],
            'Failed login attempt', 'auth', 'warning');
        return ['ok' => false, 'msg' => "Invalid credentials. {$remaining} attempt(s) remaining."];
    }

    // SUCCESS
    $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?')
        ->execute([$user['id']]);

    $_SESSION['user'] = [
        'id'                     => (int)$user['id'],
        'username'               => $user['username'],
        'full_name'              => $user['full_name'],
        'role'                   => $user['role'],
        'avatar'                 => $user['avatar'] ?: strtoupper(substr($user['full_name'], 0, 2)),
        'color'                  => $user['avatar_color'] ?: '#888888',
        'dashboard_widgets'      => $user['dashboard_widgets'] ?? null,
        'dashboard_widget_order' => $user['dashboard_widget_order'] ?? null,
    ];

    log_action($user['id'], $user['username'], $user['role'], 'Logged in', 'auth', 'success');
    return ['ok' => true, 'msg' => ''];
}

function logout(): void {
    $u = current_user();
    if ($u) {
        log_action($u['id'], $u['username'], $u['role'], 'Logged out', 'auth', 'success');
    }
    $_SESSION = [];
    session_destroy();
}
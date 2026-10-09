<?php
require 'config.php';
require 'auth.php';

if (current_user()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = false;
$full_name = '';
$username  = '';
$email     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';
    $agree     = isset($_POST['agree']);

    if ($full_name === '') {
        $error = 'Please enter your full name.';
    } elseif ($username === '') {
        $error = 'Please choose a username.';
    } elseif (strlen($username) < 3) {
        $error = 'Username must be at least 3 characters.';
    } elseif (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
        $error = 'Username can only contain letters, numbers, dots, underscores, and dashes.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (!$agree) {
        $error = 'You must agree to the Terms of Service.';
    } else {
        $stmt = db()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);

        if ($stmt->fetch()) {
            $error = 'That username is already taken. Try another.';
        } else {
            $hash   = password_hash($password, PASSWORD_DEFAULT);
            $avatar = strtoupper(substr($full_name, 0, 2));

            db()->prepare('
                INSERT INTO users (full_name, username, email, password_hash, role, avatar, avatar_color)
                VALUES (?, ?, ?, ?, "user", ?, "#888888")
            ')->execute([$full_name, $username, $email ?: null, $hash, $avatar]);

            log_action((int)db()->lastInsertId(), $username, 'user',
                'Account created (self-registration)', 'accounts', 'success');

            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>AssetDesk · Create Account</title>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
<link rel="stylesheet" href="assets/style.css" />
</head>
<body>

<div class="auth-layout">
    <div class="auth-panel">
        <div class="auth-panel-inner">
            <a href="index.php" class="brand">
                <div class="mark">AD</div>
                AssetDesk
            </a>

            <h1>Create your account</h1>
            <p class="sub">
                Already have an account?
                <a href="signin.php">Sign in</a>
            </p>

            <?php if ($error): ?>
                <div class="form-message error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="form-message success">
                    <i class="fas fa-check-circle"></i>
                    <span>Account created! Redirecting to sign in…</span>
                </div>
                <meta http-equiv="refresh" content="2;url=signin.php">
            <?php else: ?>

            <form method="POST" autocomplete="on">
                <div class="form-group">
                    <label for="full_name">Full name</label>
                    <div class="input-wrap">
                        <i class="fas fa-user icon"></i>
                        <input type="text" id="full_name" name="full_name"
                               placeholder="e.g. Juan dela Cruz"
                               value="<?= htmlspecialchars($full_name) ?>" required autofocus />
                    </div>
                </div>

                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-wrap">
                        <i class="fas fa-at icon"></i>
                        <input type="text" id="username" name="username"
                               placeholder="e.g. juan.delacruz"
                               value="<?= htmlspecialchars($username) ?>" required />
                    </div>
                </div>

                <div class="form-group">
                    <label for="email">Email (optional)</label>
                    <div class="input-wrap">
                        <i class="fas fa-envelope icon"></i>
                        <input type="email" id="email" name="email"
                               placeholder="you@example.com"
                               value="<?= htmlspecialchars($email) ?>" />
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock icon"></i>
                        <input type="password" id="password" name="password"
                               placeholder="At least 6 characters" required />
                        <button type="button" class="toggle-pw" data-target="password" aria-label="Show password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm password</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock icon"></i>
                        <input type="password" id="confirm_password" name="confirm_password"
                               placeholder="Re-enter password" required />
                        <button type="button" class="toggle-pw" data-target="confirm_password" aria-label="Show password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <label style="display:flex;align-items:flex-start;gap:10px;font-size:13px;color:var(--text-muted);margin-bottom:20px;cursor:pointer;line-height:1.5;">
                    <input type="checkbox" name="agree" style="margin-top:3px;accent-color:var(--accent);" required />
                    <span>I agree to the Terms of Service and Privacy Policy.</span>
                </label>

                <button type="submit" class="btn-submit">
                    <i class="fas fa-user-plus"></i> Create account
                </button>
            </form>

            <?php endif; ?>
        </div>
    </div>

    <div class="auth-visual">
        <div class="visual-content">
            <h2>Track every asset.<br />Know every movement.</h2>
            <p>
                AssetDesk gives your IT team a single place to manage inventory,
                log transactions, and generate reports — with role-based access
                for Users, Admins, and Superadmins.
            </p>
            <ul class="visual-list">
                <li><i class="fas fa-check-circle"></i> Inventory tracking with reorder thresholds</li>
                <li><i class="fas fa-check-circle"></i> Full transaction history — inbound to disposal</li>
                <li><i class="fas fa-check-circle"></i> Role-based access with 3-tier permissions</li>
                <li><i class="fas fa-check-circle"></i> Complete audit trail of every action</li>
            </ul>
        </div>
    </div>
</div>

<script src="assets/app.js"></script>
</body>
</html>
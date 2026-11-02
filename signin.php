<?php
require 'config.php';
require 'auth.php';

if (current_user()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$resetRequested = false;
$username_value = '';

/* ============================================================
   PASSWORD RESET REQUEST — logs to audit_logs
   ============================================================ */
if (($_GET['action'] ?? '') === 'request_reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $reqUsername = trim($_POST['reset_username'] ?? '');

    if ($reqUsername !== '') {
        // Look up the user (may or may not exist)
        $stmt = db()->prepare('SELECT id, username, role FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$reqUsername]);
        $target = $stmt->fetch();

        if ($target) {
            log_action(
                (int)$target['id'],
                $target['username'],
                $target['role'],
                'Requested password reset from sign-in page',
                'auth',
                'warning'
            );
        } else {
            // Unknown username — log the attempt anyway so admins see it
            log_action(
                null,
                $reqUsername,
                null,
                'Password reset requested for unknown username',
                'auth',
                'warning'
            );
        }
    }

    // Always show the same message, whether the username exists or not.
    // This prevents user enumeration.
    $resetRequested = true;
    $username_value = $reqUsername;
}

/* ============================================================
   LOGIN
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$resetRequested && !isset($_POST['reset_username'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $username_value = $username;

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $result = attempt_login($username, $password);
        if ($result['ok']) {
            header('Location: dashboard.php');
            exit;
        }
        $error = $result['msg'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>AssetDesk · Sign In</title>
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

            <h1>Welcome back</h1>
            <p class="sub">
                Don't have an account?
                <a href="signup.php">Sign up</a>
            </p>

            <?php if ($resetRequested): ?>
                <div class="form-message success">
                    <i class="fas fa-check-circle"></i>
                    <span>
                        Your request has been logged. A Superadmin will contact you
                        to reset your password.
                    </span>
                </div>

                <a href="signin.php" class="btn btn-outline" style="width:100%;justify-content:center;margin-top:8px;">
                    <i class="fas fa-arrow-left"></i> Back to sign in
                </a>

            <?php else: ?>

                <?php if ($error): ?>
                    <div class="form-message error">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" autocomplete="on">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <div class="input-wrap">
                            <i class="fas fa-user icon"></i>
                            <input type="text" id="username" name="username"
                                   placeholder="e.g. juan.delacruz"
                                   autocomplete="username"
                                   value="<?= htmlspecialchars($username_value) ?>"
                                   required autofocus />
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="input-wrap">
                            <i class="fas fa-lock icon"></i>
                            <input type="password" id="password" name="password"
                                   placeholder="Enter your password"
                                   autocomplete="current-password" required />
                            <button type="button" class="toggle-pw" data-target="password" aria-label="Show password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="flex-between mb-16">
                        <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-muted);cursor:pointer;">
                            <input type="checkbox" name="remember" style="accent-color:var(--accent);">
                            Remember me
                        </label>
                        <a href="#" style="font-size:13px;color:var(--accent-hover);font-weight:500;"
                           onclick="showResetForm(event); return false;">
                            Forgot password?
                        </a>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-sign-in-alt"></i> Sign in
                    </button>
                </form>

                <!-- Hidden reset form -->
                <div id="resetForm" style="display:none;margin-top:8px;">
                    <div class="form-message" style="background:rgba(0,122,204,0.08);border:1px solid var(--accent);color:var(--accent-hover);display:flex;">
                        <i class="fas fa-key"></i>
                        <span>
                            Enter your username below. Your request will be logged
                            and a Superadmin will reset your password.
                        </span>
                    </div>

                    <form method="POST" action="signin.php?action=request_reset">
                        <div class="form-group">
                            <label for="reset_username">Username</label>
                            <div class="input-wrap">
                                <i class="fas fa-user icon"></i>
                                <input type="text" id="reset_username" name="reset_username"
                                       placeholder="Your username"
                                       value="<?= htmlspecialchars($username_value) ?>" required />
                            </div>
                        </div>

                        <div class="flex" style="gap:8px;margin-top:8px;">
                            <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;">
                                <i class="fas fa-paper-plane"></i> Send Request
                            </button>
                            <button type="button" class="btn btn-outline" onclick="hideResetForm()">
                                Cancel
                            </button>
                        </div>
                    </form>
                </div>

            <?php endif; ?>

        </div>
    </div>

    <div class="auth-visual">
        <div class="visual-content">
            <h2>Sign in to manage your inventory.</h2>
            <p>
                AssetDesk keeps your IT asset records accurate and your team aligned.
                Sign in to access your dashboard, inventory, and reports.
            </p>
            <ul class="visual-list">
                <li><i class="fas fa-check-circle"></i> Role-based access — User, Admin, Superadmin</li>
                <li><i class="fas fa-check-circle"></i> Every login attempt is logged</li>
                <li><i class="fas fa-check-circle"></i> Superadmins are exempt from lockout</li>
            </ul>
        </div>
    </div>

</div>

<script>
function showResetForm(e) {
    e.preventDefault();
    document.getElementById('resetForm').style.display = 'block';
}
function hideResetForm() {
    document.getElementById('resetForm').style.display = 'none';
}
</script>

<script src="assets/app.js"></script>
</body>
</html>
<?php
require 'config.php';
require 'auth.php';

$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>AssetDesk · Inventory Management System</title>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
<link rel="stylesheet" href="assets/style.css" />
</head>
<body>

<nav class="nav">
    <div class="nav-inner">
        <a href="index.php" class="brand">
            <div class="mark">AD</div>
            AssetDesk
        </a>
        <div class="nav-links">
            <a href="#features">Features</a>
            <a href="#roles">Roles</a>
            <a href="#how">How it works</a>
        </div>
        <div class="nav-actions">
            <?php if ($user): ?>
                <a href="dashboard.php" class="btn btn-primary">
                    <i class="fas fa-th-large"></i> Dashboard
                </a>
                <a href="logout.php" class="btn btn-ghost">Sign out</a>
            <?php else: ?>
                <a href="signin.php" class="btn btn-ghost">Log in</a>
                <a href="signup.php" class="btn btn-primary">
                    <i class="fas fa-user-plus"></i> Sign up
                </a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<section class="hero">
    <div class="hero-inner">
        <div class="hero-badge">
            <span class="dot"></span>
            Inventory · Lifecycle · Reports
        </div>
        <h1>
            Track every asset.<br />
            <span class="gradient">Know every movement.</span>
        </h1>
        <p class="lead">
            AssetDesk keeps your IT inventory accurate, your transactions logged,
            and your team aligned — with role-based access for Users, Admins, and Superadmins.
        </p>
        <div class="hero-cta">
            <?php if ($user): ?>
                <a href="dashboard.php" class="btn btn-primary btn-large">
                    <i class="fas fa-th-large"></i> Go to Dashboard
                </a>
            <?php else: ?>
                <a href="signup.php" class="btn btn-primary btn-large">
                    <i class="fas fa-user-plus"></i> Create an account
                </a>
                <a href="signin.php" class="btn btn-outline btn-large">
                    <i class="fas fa-sign-in-alt"></i> Sign in
                </a>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="section" id="features">
    <div class="section-head">
        <span class="tag">Features</span>
        <h2>Everything you need to track IT assets</h2>
        <p>Purpose-built for small IT teams managing desktops, laptops, peripherals, and network gear.</p>
    </div>

    <div class="features-grid">
        <div class="feature">
            <div class="icon blue"><i class="fas fa-cube"></i></div>
            <h3>Inventory Tracking</h3>
            <p>Track every SKU, stock level, reorder threshold, and status. Types: hardware, software, consumable.</p>
        </div>
        <div class="feature">
            <div class="icon green"><i class="fas fa-exchange-alt"></i></div>
            <h3>Transaction Log</h3>
            <p>Every stock movement is recorded — inbound, outbound, damaged, for repair, disposed, returned.</p>
        </div>
        <div class="feature">
            <div class="icon purple"><i class="fas fa-file-alt"></i></div>
            <h3>Generated Reports</h3>
            <p>Inventory, transaction, depreciation, warranty, license, client, and user reports — all as CSV.</p>
        </div>
        <div class="feature">
            <div class="icon blue"><i class="fas fa-chart-line"></i></div>
            <h3>Depreciation & Book Value</h3>
            <p>Straight-line depreciation per product with a book value chart and current-value KPI.</p>
        </div>
        <div class="feature">
            <div class="icon green"><i class="fas fa-history"></i></div>
            <h3>Audit Trail</h3>
            <p>Every action is timestamped — who did what, when, and to which record. Full history on demand.</p>
        </div>
        <div class="feature">
            <div class="icon purple"><i class="fas fa-user-shield"></i></div>
            <h3>Role-Based Access</h3>
            <p>Three roles — User, Admin, Superadmin — each with defined permissions. No accidental data changes.</p>
        </div>
    </div>
</section>

<section class="section" id="roles">
    <div class="section-head">
        <span class="tag">Roles</span>
        <h2>Three roles. Clear responsibilities.</h2>
        <p>Each role inherits the permissions of the roles below it, plus its own.</p>
    </div>

    <div class="features-grid">
        <div class="feature" style="border-top:3px solid var(--green);">
            <span class="badge success" style="margin-bottom:12px;">Base role</span>
            <h3>User</h3>
            <p>Every employee with an account. Read-only access to their own data, plus submitting reports.</p>
        </div>
        <div class="feature" style="border-top:3px solid var(--accent);">
            <span class="badge info" style="margin-bottom:12px;">Management</span>
            <h3>Admin</h3>
            <p>IT staff and managers. Full operational control of inventory, transactions, reports, clients, and categories.</p>
        </div>
        <div class="feature" style="border-top:3px solid var(--purple);">
            <span class="badge purple" style="margin-bottom:12px;">System</span>
            <h3>Superadmin</h3>
            <p>System owner. Manages accounts, permissions, security policy, and full audit access.</p>
        </div>
    </div>
</section>

<section class="section" id="how">
    <div class="section-head">
        <span class="tag">How it works</span>
        <h2>From login to logout in four steps</h2>
    </div>

    <div class="features-grid" style="grid-template-columns:repeat(4,1fr);">
        <div class="feature" style="text-align:center;">
            <div class="mark" style="width:52px;height:52px;margin:0 auto 16px;background:var(--accent-soft);color:var(--accent-hover);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:700;">1</div>
            <h3>Sign in</h3>
            <p>Log in with your username and password. Three failed attempts locks the account for 15 minutes.</p>
        </div>
        <div class="feature" style="text-align:center;">
            <div class="mark" style="width:52px;height:52px;margin:0 auto 16px;background:var(--accent-soft);color:var(--accent-hover);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:700;">2</div>
            <h3>Dashboard</h3>
            <p>Date range + category filters. Charts, KPIs, and stock alerts update to the current view.</p>
        </div>
        <div class="feature" style="text-align:center;">
            <div class="mark" style="width:52px;height:52px;margin:0 auto 16px;background:var(--accent-soft);color:var(--accent-hover);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:700;">3</div>
            <h3>Manage or report</h3>
            <p>Admins manage inventory, transactions, and clients. Users submit reports and requests.</p>
        </div>
        <div class="feature" style="text-align:center;">
            <div class="mark" style="width:52px;height:52px;margin:0 auto 16px;background:var(--accent-soft);color:var(--accent-hover);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:700;">4</div>
            <h3>Generate reports</h3>
            <p>Export inventory, transaction, depreciation, warranty, license, client, and user reports as CSV.</p>
        </div>
    </div>
</section>

<section class="section" style="text-align:center;padding-top:40px;">
    <div class="section-head" style="margin-bottom:32px;">
        <h2>Ready to get started?</h2>
        <p>Create an account or sign in with one of the test accounts.</p>
    </div>
    <div class="hero-cta" style="margin-bottom:0;">
        <?php if ($user): ?>
            <a href="dashboard.php" class="btn btn-primary btn-large">
                <i class="fas fa-th-large"></i> Go to Dashboard
            </a>
        <?php else: ?>
            <a href="signup.php" class="btn btn-primary btn-large">
                <i class="fas fa-user-plus"></i> Create an account
            </a>
            <a href="signin.php" class="btn btn-outline btn-large">
                <i class="fas fa-sign-in-alt"></i> Sign in
            </a>
        <?php endif; ?>
    </div>
</section>

<footer class="footer">
    <div class="brand" style="justify-content:center;margin-bottom:12px;">
        <div class="mark">AD</div>
        AssetDesk
    </div>
    <p>© <?= date('Y') ?> AssetDesk · Inventory Management System · Local build</p>
</footer>

<script src="assets/app.js"></script>
</body>
</html>
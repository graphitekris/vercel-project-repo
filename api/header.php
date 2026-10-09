<?php
if (!isset($user) || !$user) { $user = current_user(); }
if (!$user) { header('Location: signin.php'); exit; }

$pageTitle = $pageTitle ?? 'Dashboard';
$activeNav = $activeNav ?? 'dashboard';

$navItems = [
    ['key'=>'dashboard',    'icon'=>'fa-th-large',     'label'=>'Dashboard',                                              'roles'=>['user','admin','superadmin']],
    ['key'=>'assets',       'icon'=>'fa-laptop',       'label'=>'My Assets',                                              'roles'=>['user']],
    ['key'=>'inventory',    'icon'=>'fa-cube',         'label'=>'Inventory',                                              'roles'=>['admin','superadmin']],
    ['key'=>'transactions', 'icon'=>'fa-exchange-alt', 'label'=>$user['role']==='user'?'My Transactions':'Transactions',  'roles'=>['user','admin','superadmin']],
    ['key'=>'reports',      'icon'=>'fa-file-alt',     'label'=>'Reports',                                                'roles'=>['admin','superadmin']],
    ['key'=>'accounts',     'icon'=>'fa-users-cog',    'label'=>'Accounts',                                               'roles'=>['admin','superadmin']],
    ['key'=>'submit-report','icon'=>'fa-flag',         'label'=>'Submit Report',                                          'roles'=>['user','admin','superadmin']],
    ['key'=>'history',      'icon'=>'fa-history',      'label'=>$user['role']==='user'?'My History':'History & Audit Log','roles'=>['user','admin','superadmin']],
    ['key'=>'system',       'icon'=>'fa-shield-alt',   'label'=>'System Settings',                                        'roles'=>['superadmin']],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>AssetDesk · <?= htmlspecialchars($pageTitle) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
<link rel="stylesheet" href="assets/style.css" />
</head>
<body>
<div class="app">

    <aside class="sidebar">
        <a href="dashboard.php" class="sidebar-brand">
            <div class="mark">AD</div>
            AssetDesk
        </a>
        <nav class="sidebar-nav">
            <?php foreach ($navItems as $item): ?>
                <?php if (!in_array($user['role'], $item['roles'], true)) continue; ?>
                <a href="<?= $item['key'] ?>.php"
                   class="<?= $activeNav === $item['key'] ? 'active' : '' ?>">
                    <i class="fas <?= $item['icon'] ?>"></i>
                    <?= htmlspecialchars($item['label']) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <a href="logout.php" class="btn btn-ghost"
               style="width:100%;justify-content:flex-start;padding:10px 12px;">
                <i class="fas fa-sign-out-alt"></i> Sign Out
            </a>
        </div>
    </aside>

    <main class="main">
        <div class="topbar">
            <h1><?= htmlspecialchars($pageTitle) ?></h1>
            <div class="user">
                <div class="avatar" style="background:<?= htmlspecialchars($user['color'] ?? '#888') ?>">
                    <?= htmlspecialchars($user['avatar'] ?? '??') ?>
                </div>
                <div class="info">
                    <div class="name"><?= htmlspecialchars($user['full_name']) ?></div>
                    <div class="role"><?= ucfirst(htmlspecialchars($user['role'])) ?></div>
                </div>
            </div>
        </div>
        <div class="page">
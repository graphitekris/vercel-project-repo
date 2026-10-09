<?php
require 'config.php';
require 'auth.php';

$user = require_role('superadmin');

$pageTitle = 'System Settings';
$activeNav = 'system';

$flash = '';
$flashType = 'success';

/* ============================================================
   BACKUP — stream a SQL dump of the database
   ============================================================ */
if (isset($_GET['action']) && $_GET['action'] === 'backup') {

    $tables = ['users','categories','clients','products','transactions','reports','audit_logs','assets'];
    $filename = 'assetdesk_backup_' . date('Ymd_His') . '.sql';

    log_action($user['id'], $user['username'], $user['role'],
        'Downloaded database backup', 'system', 'success');

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');

    fwrite($out, "-- AssetDesk Database Backup\n");
    fwrite($out, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
    fwrite($out, "-- By: {$user['full_name']} ({$user['username']})\n");
    fwrite($out, "-- ----------------------------------------\n\n");

    fwrite($out, "SET FOREIGN_KEY_CHECKS=0;\n\n");

    foreach ($tables as $table) {
        fwrite($out, "--\n-- Table structure for `{$table}`\n--\n");
        fwrite($out, "DROP TABLE IF EXISTS `{$table}`;\n");

        try {
            $create = db()->query("SHOW CREATE TABLE `{$table}`")->fetch();
            fwrite($out, $create['Create Table'] . ";\n\n");
        } catch (Throwable $e) {
            fwrite($out, "-- Error: " . $e->getMessage() . "\n\n");
            continue;
        }

        fwrite($out, "--\n-- Data for `{$table}`\n--\n");

        try {
            $rows = db()->query("SELECT * FROM `{$table}`")->fetchAll();
            if ($rows) {
                foreach ($rows as $row) {
                    $cols   = array_keys($row);
                    $values = array_map(function ($v) {
                        if ($v === null) return 'NULL';
                        return "'" . str_replace(
                            ["\\", "'", "\n", "\r", "\x1a"],
                            ["\\\\", "\\'", "\\n", "\\r", "\\Z"],
                            (string)$v
                        ) . "'";
                    }, array_values($row));

                    fwrite($out,
                        "INSERT INTO `{$table}` (`" . implode('`, `', $cols) . "`) VALUES (" .
                        implode(', ', $values) . ");\n"
                    );
                }
            }
            fwrite($out, "\n");
        } catch (Throwable $e) {
            fwrite($out, "-- Error: " . $e->getMessage() . "\n\n");
        }
    }

    fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($out);
    exit;
}

/* ============================================================
   TABLE INFO FOR BACKUP CARD
   ============================================================ */
$tableSizes = [];
try {
    $rows = db()->query("
        SELECT table_name AS name,
               table_rows AS rows_count,
               ROUND(((data_length + index_length) / 1024), 2) AS size_kb
        FROM information_schema.TABLES
        WHERE table_schema = DATABASE()
        ORDER BY (data_length + index_length) DESC
    ")->fetchAll();
    foreach ($rows as $r) {
        $tableSizes[] = [
            'name' => $r['name'],
            'rows' => (int)$r['rows_count'],
            'size' => (float)$r['size_kb'],
        ];
    }
} catch (Throwable $e) {
    $tableSizes = [];
}

$totalSize = array_sum(array_column($tableSizes, 'size'));
$totalRows = array_sum(array_column($tableSizes, 'rows'));

/* ============================================================
   COUNTS
   ============================================================ */
$activeUsers = (int) db()->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
$lockedUsers = (int) db()->query("SELECT COUNT(*) FROM users WHERE locked_until IS NOT NULL AND locked_until > NOW()")->fetchColumn();
$auditCount  = (int) db()->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();

/* ============================================================
   RECENT ERRORS
   ============================================================ */
$recentErrors = [];
$logFile = __DIR__ . '/logs/error.log';
if (is_readable($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines) {
        $recentErrors = array_slice($lines, -5);
        $recentErrors = array_reverse($recentErrors);
    }
}

include 'header.php';
?>

<?php if ($flash): ?>
    <div class="banner <?= $flashType ?>" data-autohide>
        <i class="fas fa-check-circle"></i>
        <span><?= htmlspecialchars($flash) ?></span>
    </div>
<?php endif; ?>

<div class="banner info">
    <i class="fas fa-shield-alt"></i>
    <span>
        <strong>Superadmin-only page.</strong> Documentation, operational metrics,
        and database backup. Only Superadmins can access this page.
    </span>
</div>

<!-- ============================================================
     DATABASE BACKUP — the main working feature
     ============================================================ -->
<div class="card">
    <div class="flex-between">
        <div>
            <h3 style="margin-bottom:6px;">Database Backup</h3>
            <p class="text-muted" style="font-size:13px;margin:0;">
                Download the entire database as a single <code>.sql</code> file —
                tables, structure, and all data. Restore by importing it back
                via phpMyAdmin.
            </p>
        </div>
        <a href="system.php?action=backup" class="btn btn-primary" style="flex-shrink:0;"
           onclick="this.innerHTML='<i class=&quot;fas fa-spinner fa-spin&quot;></i> Building…';">
            <i class="fas fa-download"></i> Download Backup
        </a>
    </div>

    <table style="margin-top:20px;">
        <thead>
            <tr><th>Table</th><th style="text-align:right;">Rows</th><th style="text-align:right;">Size</th></tr>
        </thead>
        <tbody>
            <?php foreach ($tableSizes as $t): ?>
                <tr>
                    <td class="text-bright"><?= htmlspecialchars($t['name']) ?></td>
                    <td style="text-align:right;" class="text-muted"><?= number_format($t['rows']) ?></td>
                    <td style="text-align:right;" class="text-muted">
                        <?= $t['size'] >= 1024
                            ? number_format($t['size'] / 1024, 2) . ' MB'
                            : number_format($t['size'], 2) . ' KB' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <tr style="border-top:2px solid var(--border);">
                <td class="text-bright fw-600">Total</td>
                <td style="text-align:right;" class="text-bright fw-600"><?= number_format($totalRows) ?></td>
                <td style="text-align:right;" class="text-bright fw-600">
                    <?= $totalSize >= 1024
                        ? number_format($totalSize / 1024, 2) . ' MB'
                        : number_format($totalSize, 2) . ' KB' ?>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<!-- ============================================================
     SECURITY POLICY
     ============================================================ -->
<div class="card">
    <h3>Security Policy</h3>
    <p class="text-muted mb-16" style="font-size:13px;">
        Current settings governing authentication and lockout. Defined in
        <code>auth.php</code> as constants.
    </p>

    <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:16px;">
        <div class="kpi-card">
            <span class="label">Max Failed Attempts</span>
            <span class="value"><?= MAX_ATTEMPTS ?></span>
            <span class="trend">before lockout</span>
        </div>
        <div class="kpi-card">
            <span class="label">Lockout Duration</span>
            <span class="value"><?= LOCKOUT_MINUTES ?> min</span>
            <span class="trend">auto-expires</span>
        </div>
        <div class="kpi-card">
            <span class="label">Active Users</span>
            <span class="value text-green"><?= number_format($activeUsers) ?></span>
            <span class="trend">enabled accounts</span>
        </div>
        <div class="kpi-card">
            <span class="label">Locked Right Now</span>
            <span class="value <?= $lockedUsers > 0 ? 'text-red' : 'text-muted' ?>">
                <?= number_format($lockedUsers) ?>
            </span>
            <span class="trend">accounts locked</span>
        </div>
    </div>

    <table style="margin-top:20px;">
        <thead>
            <tr><th>Policy</th><th>Setting</th></tr>
        </thead>
        <tbody>
            <tr>
                <td>Password storage</td>
                <td><code>password_hash()</code> with <code>PASSWORD_DEFAULT</code> (bcrypt)</td>
            </tr>
            <tr>
                <td>Password minimum length</td>
                <td>6 characters</td>
            </tr>
            <tr>
                <td>Session storage</td>
                <td>PHP native sessions (<code>$_SESSION</code>)</td>
            </tr>
            <tr>
                <td>Login attempts tracked by</td>
                <td>Username (server-side, DB-backed)</td>
            </tr>
            <tr>
                <td>Audit coverage</td>
                <td>All auth, inventory, transaction, report, and account actions</td>
            </tr>
            <tr>
                <td>Audit rows so far</td>
                <td><?= number_format($auditCount) ?></td>
            </tr>
        </tbody>
    </table>
</div>

<!-- ============================================================
     ROLE & PERMISSION MATRIX
     ============================================================ -->
<div class="card">
    <h3>Role &amp; Permission Matrix</h3>
    <p class="text-muted mb-16" style="font-size:13px;">
        What each role can do. Enforced server-side — the UI can't be tricked
        into giving a lower role more access.
    </p>

    <table>
        <thead>
            <tr>
                <th>Capability</th>
                <th style="text-align:center;">User</th>
                <th style="text-align:center;">Admin</th>
                <th style="text-align:center;">Superadmin</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $matrix = [
                ['View own assets &amp; reports',        true,  true,  true],
                ['Submit reports / requests',           true,  true,  true],
                ['View own audit history',              true,  true,  true],
                ['View full inventory',                 false, true,  true],
                ['Create / edit products',              false, true,  true],
                ['Adjust pricing &amp; lifecycle',       false, true,  true],
                ['Create transactions',                 false, true,  true],
                ['Generate reports &amp; CSV',           false, true,  true],
                ['Resolve user reports',                false, true,  true],
                ['Manage clients &amp; categories',       false, true,  true],
                ['View all accounts (read-only)',       false, true,  true],
                ['View system-wide audit log',          false, true,  true],
                ['Create user / admin accounts',        false, false, true],
                ['Reset passwords &amp; lockouts',        false, false, true],
                ['Disable user accounts',               false, false, true],
                ['Assign Admin role',                   false, false, true],
                ['Access system settings',              false, false, true],
                ['Create / edit Superadmin',            false, false, false],
                ['Disable a Superadmin',                false, false, false],
                ['Delete any record',                   false, false, false],
            ];
            foreach ($matrix as $row):
            ?>
                <tr>
                    <td><?= $row[0] ?></td>
                    <td style="text-align:center;">
                        <?php if ($row[1]): ?>
                            <i class="fas fa-check text-green"></i>
                        <?php else: ?>
                            <i class="fas fa-times text-muted" style="opacity:0.4;"></i>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <?php if ($row[2]): ?>
                            <i class="fas fa-check text-green"></i>
                        <?php else: ?>
                            <i class="fas fa-times text-muted" style="opacity:0.4;"></i>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <?php if ($row[3]): ?>
                            <i class="fas fa-check text-green"></i>
                        <?php else: ?>
                            <i class="fas fa-times text-muted" style="opacity:0.4;"></i>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <p class="text-muted" style="font-size:12px;margin-top:12px;">
        <i class="fas fa-lock"></i>
        <strong>Superadmin is a locked role.</strong> It cannot be created, promoted to,
        demoted from, or disabled through the app.
    </p>
</div>

<!-- ============================================================
     RECENT ERRORS
     ============================================================ -->
<div class="card">
    <h3>Recent Errors</h3>
    <p class="text-muted mb-16" style="font-size:13px;">
        Last 5 lines from <code>logs/error.log</code>. A clean system shows nothing here.
    </p>

    <?php if (!$recentErrors): ?>
        <div class="banner success" style="margin:0;">
            <i class="fas fa-check-circle"></i>
            <span>No recent errors.</span>
        </div>
    <?php else: ?>
        <div style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:12px 16px;font-family:Consolas,monospace;font-size:12px;color:var(--text-muted);overflow-x:auto;">
            <?php foreach ($recentErrors as $line): ?>
                <div style="white-space:pre-wrap;word-break:break-all;padding:3px 0;"><?= htmlspecialchars($line) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
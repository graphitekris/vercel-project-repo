<?php
require 'config.php';
require 'auth.php';

$user = require_login();   // all roles can view, but scoped
$isPrivileged = in_array($user['role'], ['admin', 'superadmin'], true);

$pageTitle = 'History & Audit Log';
$activeNav = 'history';

/* ============================================================
   FILTERS
   ============================================================ */
$filterModule = $_GET['module'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterUser   = (int)($_GET['user'] ?? 0);
$filterDate   = $_GET['date'] ?? '';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 50;
$offset       = ($page - 1) * $perPage;

$validModules  = ['auth','inventory','transactions','reports','accounts','system'];
$validStatuses = ['success','warning','failed'];

$where  = [];
$params = [];

// Users can only see their own rows
if (!$isPrivileged) {
    $where[]  = 'user_id = ?';
    $params[] = (int)$user['id'];
} elseif ($filterUser > 0) {
    $where[]  = 'user_id = ?';
    $params[] = $filterUser;
}

if ($filterModule !== '' && in_array($filterModule, $validModules, true)) {
    $where[]  = 'module = ?';
    $params[] = $filterModule;
}
if ($filterStatus !== '' && in_array($filterStatus, $validStatuses, true)) {
    $where[]  = 'status = ?';
    $params[] = $filterStatus;
}
if ($filterDate !== '') {
    $where[]  = 'DATE(created_at) = ?';
    $params[] = $filterDate;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* ---------- Total rows for pagination ---------- */
$countStmt = db()->prepare("SELECT COUNT(*) FROM audit_logs {$whereSql}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

/* ---------- Fetch the page ---------- */
$stmt = db()->prepare("
    SELECT id, user_id, username, role, action, module, status, ip_address, created_at
    FROM audit_logs
    {$whereSql}
    ORDER BY created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

/* ---------- KPI counts (respecting non-user filter but ignoring pagination) ---------- */
$kpiWhere  = [];
$kpiParams = [];
if (!$isPrivileged) {
    $kpiWhere[]  = 'user_id = ?';
    $kpiParams[] = (int)$user['id'];
}
if ($filterDate !== '') {
    $kpiWhere[]  = 'DATE(created_at) = ?';
    $kpiParams[] = $filterDate;
}
$kpiSql = $kpiWhere ? 'WHERE ' . implode(' AND ', $kpiWhere) : '';

$kpiStmt = db()->prepare("
    SELECT status, COUNT(*) AS n
    FROM audit_logs
    {$kpiSql}
    GROUP BY status
");
$kpiStmt->execute($kpiParams);
$counts = ['success' => 0, 'warning' => 0, 'failed' => 0];
foreach ($kpiStmt->fetchAll() as $row) {
    $counts[$row['status']] = (int)$row['n'];
}

/* ---------- Users for filter dropdown ---------- */
$usersForFilter = [];
if ($isPrivileged) {
    $usersForFilter = db()->query('
        SELECT DISTINCT u.id, u.full_name
        FROM users u
        INNER JOIN audit_logs al ON al.user_id = u.id
        ORDER BY u.full_name
    ')->fetchAll();
}

include 'header.php';
?>

<div class="banner info">
    <i class="fas fa-history"></i>
    <span>
        <?php if ($isPrivileged): ?>
            <strong>Full audit trail.</strong> Every action in the system is logged here —
            logins, inventory changes, transactions, account operations. Newest first.
        <?php else: ?>
            <strong>Your activity.</strong> Every action you've taken in the system is
            logged here. If you need to see system-wide history, contact an Admin.
        <?php endif; ?>
    </span>
</div>

<!-- KPI cards -->
<div class="kpi-grid" style="grid-template-columns: repeat(3, 1fr);">
    <div class="kpi-card">
        <span class="label">Successful</span>
        <span class="value text-green"><?= number_format($counts['success']) ?></span>
        <span class="trend">Total</span>
    </div>
    <div class="kpi-card">
        <span class="label">Warnings</span>
        <span class="value text-yellow"><?= number_format($counts['warning']) ?></span>
        <span class="trend warn">Actions worth a look</span>
    </div>
    <div class="kpi-card">
        <span class="label">Failed</span>
        <span class="value text-red"><?= number_format($counts['failed']) ?></span>
        <span class="trend down">Rejected or errored</span>
    </div>
</div>

<!-- Filters -->
<div class="card">
    <form method="GET" style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;align-items:end;">
        <div class="form-group" style="margin:0;">
            <label for="module">Module</label>
            <div class="input-wrap">
                <i class="fas fa-layer-group icon"></i>
                <select id="module" name="module" style="padding-left:40px;">
                    <option value="">All Modules</option>
                    <?php foreach ($validModules as $m): ?>
                        <option value="<?= $m ?>" <?= $filterModule === $m ? 'selected' : '' ?>>
                            <?= ucfirst($m) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-group" style="margin:0;">
            <label for="status">Status</label>
            <div class="input-wrap">
                <i class="fas fa-flag icon"></i>
                <select id="status" name="status" style="padding-left:40px;">
                    <option value="">All Statuses</option>
                    <?php foreach ($validStatuses as $s): ?>
                        <option value="<?= $s ?>" <?= $filterStatus === $s ? 'selected' : '' ?>>
                            <?= ucfirst($s) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php if ($isPrivileged): ?>
        <div class="form-group" style="margin:0;">
            <label for="user">User</label>
            <div class="input-wrap">
                <i class="fas fa-user icon"></i>
                <select id="user" name="user" style="padding-left:40px;">
                    <option value="">All Users</option>
                    <?php foreach ($usersForFilter as $uf): ?>
                        <option value="<?= (int)$uf['id'] ?>" <?= $filterUser === (int)$uf['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($uf['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php else: ?>
        <div></div>
        <?php endif; ?>

        <div class="form-group" style="margin:0;">
            <label for="date">Date</label>
            <div class="input-wrap">
                <i class="fas fa-calendar icon"></i>
                <input type="date" id="date" name="date" value="<?= htmlspecialchars($filterDate) ?>">
            </div>
        </div>

        <div style="grid-column:1 / -1;display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-filter"></i> Apply Filters
            </button>
            <a href="history.php" class="btn btn-outline">Clear</a>
            <button type="button" class="btn btn-outline"
                    onclick="exportTableCSV('#historyTable', 'audit_log.csv')">
                <i class="fas fa-download"></i> Export CSV
            </button>
        </div>
    </form>
</div>

<!-- Search (client-side on the visible page) -->
<div class="flex-between mb-16">
    <div class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" placeholder="Filter visible rows..." data-filter-target="#historyTable" />
    </div>
    <span class="text-muted" style="font-size:12px;">
        Showing
        <?= count($logs) ?>
        of
        <?= number_format($totalRows) ?>
        entries
    </span>
</div>

<!-- Table -->
<div class="table-card">
    <table id="historyTable">
        <thead>
            <tr>
                <th>Time</th>
                <th>User</th>
                <th>Role</th>
                <th>Action</th>
                <th>Module</th>
                <th>Status</th>
                <th>IP</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$logs): ?>
            <tr><td colspan="7" class="text-muted">No log entries match the current filters.</td></tr>
        <?php else: foreach ($logs as $l):
            $statusClass = match($l['status']) {
                'success' => 'success',
                'warning' => 'warning',
                'failed'  => 'danger',
                default   => 'muted',
            };
            $roleClass = match($l['role']) {
                'superadmin' => 'purple',
                'admin'      => 'info',
                'user'       => 'muted',
                default      => 'muted',
            };
        ?>
            <tr>
                <td class="text-muted" style="font-size:12px;white-space:nowrap;"><?= htmlspecialchars($l['created_at']) ?></td>
                <td class="text-bright"><?= htmlspecialchars($l['username'] ?? 'unknown') ?></td>
                <td>
                    <?php if ($l['role']): ?>
                        <span class="badge <?= $roleClass ?>"><?= ucfirst(htmlspecialchars($l['role'])) ?></span>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($l['action']) ?></td>
                <td class="text-muted"><?= ucfirst(htmlspecialchars($l['module'])) ?></td>
                <td><span class="badge <?= $statusClass ?>"><?= ucfirst(htmlspecialchars($l['status'])) ?></span></td>
                <td class="text-muted" style="font-size:11px;"><?= htmlspecialchars($l['ip_address'] ?? '—') ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
    <?php
    $queryBase = $_GET;
    unset($queryBase['page']);
    $qs = http_build_query($queryBase);
    $qsPrefix = $qs ? $qs . '&' : '';
    ?>
    <div class="flex" style="justify-content:center;gap:8px;margin-top:16px;flex-wrap:wrap;">
        <?php if ($page > 1): ?>
            <a href="history.php?<?= $qsPrefix ?>page=<?= $page - 1 ?>" class="btn btn-outline">
                <i class="fas fa-chevron-left"></i> Prev
            </a>
        <?php endif; ?>

        <?php
        $start = max(1, $page - 2);
        $end   = min($totalPages, $page + 2);
        if ($start > 1) {
            echo '<a href="history.php?' . $qsPrefix . 'page=1" class="btn btn-outline">1</a>';
            if ($start > 2) echo '<span class="text-muted" style="padding:0 6px;">…</span>';
        }
        for ($i = $start; $i <= $end; $i++):
        ?>
            <a href="history.php?<?= $qsPrefix ?>page=<?= $i ?>"
               class="btn <?= $i === $page ? 'btn-primary' : 'btn-outline' ?>">
                <?= $i ?>
            </a>
        <?php endfor; ?>
        <?php
        if ($end < $totalPages) {
            if ($end < $totalPages - 1) echo '<span class="text-muted" style="padding:0 6px;">…</span>';
            echo '<a href="history.php?' . $qsPrefix . 'page=' . $totalPages . '" class="btn btn-outline">' . $totalPages . '</a>';
        }
        ?>

        <?php if ($page < $totalPages): ?>
            <a href="history.php?<?= $qsPrefix ?>page=<?= $page + 1 ?>" class="btn btn-outline">
                Next <i class="fas fa-chevron-right"></i>
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php include 'footer.php'; ?>
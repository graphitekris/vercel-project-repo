<?php
require 'config.php';
require 'auth.php';
require 'queries.php';
require 'charts.php';

$user = require_login();
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';

/* ============================================================
   WIDGET PREFERENCES
   ============================================================ */
$allWidgetsAdmin = [
    'kpi_inventory_value'    => 'Inventory Value',
    'kpi_total_transactions' => 'Total Transactions',
    'kpi_stock_alerts'       => 'Stock Alerts',
    'kpi_open_reports'       => 'Open Reports',
    'chart_volume'           => 'Weekly Volume Chart',
    'chart_category'         => 'Stock by Category Chart',
    'card_superadmin'        => 'Superadmin Quick Actions',
    'table_low_stock'        => 'Stock Alerts Table',
    'table_recent_activity'  => 'Recent Activity Table',
];

$allWidgetsUser = [
    'user_kpi_assets'    => 'My Assigned Assets',
    'user_kpi_reports'   => 'My Open Reports',
    'user_kpi_txns'      => 'My Recent Transactions',
    'user_table_assets'  => 'My Assets Table',
    'user_table_reports' => 'My Reports Table',
];

$allWidgets = ($user['role'] === 'user') ? $allWidgetsUser : $allWidgetsAdmin;

$enabledWidgets = null;
if (!empty($user['dashboard_widgets'])) {
    $parsed = json_decode($user['dashboard_widgets'], true);
    if (is_array($parsed)) {
        $enabledWidgets = array_values(array_intersect($parsed, array_keys($allWidgets)));
    }
}

$widgetOrder = null;
if (!empty($user['dashboard_widget_order'])) {
    $parsed = json_decode($user['dashboard_widget_order'], true);
    if (is_array($parsed)) {
        $widgetOrder = array_values(array_intersect($parsed, array_keys($allWidgets)));
    }
}

$showWidget = function (string $key) use ($enabledWidgets, $allWidgets): bool {
    if ($key === 'card_superadmin' && !array_key_exists($key, $allWidgets)) return false;
    if ($enabledWidgets === null) return true;
    return in_array($key, $enabledWidgets, true);
};

/* ============================================================
   SAVE WIDGETS (AJAX)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_widgets'])) {
    header('Content-Type: application/json');

    $raw = $_POST['widgets'] ?? '[]';
    $widgets = json_decode($raw, true);
    if (!is_array($widgets)) $widgets = [];

    $widgets = array_values(array_intersect($widgets, array_keys($allWidgets)));
    $stored  = empty($widgets) ? null : json_encode($widgets);

    db()->prepare('UPDATE users SET dashboard_widgets = ? WHERE id = ?')
        ->execute([$stored, $user['id']]);

    $_SESSION['user']['dashboard_widgets'] = $stored;

    log_action($user['id'], $user['username'], $user['role'],
        'Customized dashboard (' . count($widgets) . ' widgets enabled)',
        'system', 'success');

    echo json_encode(['ok' => true, 'count' => count($widgets)]);
    exit;
}

/* ============================================================
   SAVE WIDGET ORDER (AJAX)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_widget_order'])) {
    header('Content-Type: application/json');

    $order = json_decode($_POST['order'] ?? '[]', true);
    if (!is_array($order)) $order = [];

    $order = array_values(array_intersect($order, array_keys($allWidgets)));
    $stored = json_encode($order);

    db()->prepare('UPDATE users SET dashboard_widget_order = ? WHERE id = ?')
        ->execute([$stored, $user['id']]);

    $_SESSION['user']['dashboard_widget_order'] = $stored;

    log_action($user['id'], $user['username'], $user['role'],
        'Reordered dashboard widgets', 'system', 'success');

    echo json_encode(['ok' => true]);
    exit;
}

/* ============================================================
   DATE RANGE FILTER
   ============================================================ */
$range = $_GET['range'] ?? '30d';
$from  = $_GET['from'] ?? '';
$to    = $_GET['to']   ?? '';

$today = new DateTime('today');

switch ($range) {
    case '7d':     $startDate = (clone $today)->modify('-6 days'); $endDate = clone $today; break;
    case '30d':    $startDate = (clone $today)->modify('-29 days'); $endDate = clone $today; break;
    case 'month':  $startDate = new DateTime(date('Y-m-01'));       $endDate = clone $today; break;
    case 'quarter':
        $q = (int)ceil((int)date('n') / 3);
        $startDate = new DateTime(date('Y') . '-' . str_pad((string)(($q - 1) * 3 + 1), 2, '0', STR_PAD_LEFT) . '-01');
        $endDate = clone $today;
        break;
    case 'custom':
        $startDate = $from ? new DateTime($from) : (clone $today)->modify('-29 days');
        $endDate   = $to   ? new DateTime($to)   : clone $today;
        if ($startDate > $endDate) { [$startDate, $endDate] = [$endDate, $startDate]; }
        break;
    default:
        $range = '30d';
        $startDate = (clone $today)->modify('-29 days');
        $endDate   = clone $today;
}
$startSql = $startDate->format('Y-m-d') . ' 00:00:00';
$endSql   = $endDate->format('Y-m-d')   . ' 23:59:59';
$daysInRange = (int)$startDate->diff($endDate)->days + 1;

/* ============================================================
   ACTION FILTER
   ============================================================ */
$allActions = [
    'inbound'         => ['Inbound',         '#4ec9b0'],
    'outbound'        => ['Outbound',        '#007acc'],
    'returned'        => ['Returned',        '#4ec9b0'],
    'damaged'         => ['Damaged',         '#f44747'],
    'for_repair'      => ['For Repair',      '#dcdcaa'],
    'for_replacement' => ['For Replacement', '#c586c0'],
    'disposed'        => ['Disposed',        '#8b949e'],
];

$selectedActions = [];
if (!empty($_GET['actions'])) {
    foreach (explode(',', $_GET['actions']) as $a) {
        $a = trim($a);
        if (array_key_exists($a, $allActions)) $selectedActions[] = $a;
    }
    $selectedActions = array_values(array_unique($selectedActions));
}
$hasActionFilter = !empty($selectedActions);

$actionWhere  = '';
$actionParams = [];
if ($hasActionFilter) {
    $placeholders = implode(',', array_fill(0, count($selectedActions), '?'));
    $actionWhere  = " AND t.type IN ($placeholders) ";
    $actionParams = $selectedActions;
}

/* ============================================================
   CATEGORY FILTER
   ============================================================ */
$allCategories = db()->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();

$selectedCatIds = [];
if (!empty($_GET['cats'])) {
    foreach (explode(',', $_GET['cats']) as $id) {
        $id = (int)trim($id);
        if ($id > 0) $selectedCatIds[] = $id;
    }
    $selectedCatIds = array_values(array_unique($selectedCatIds));
}
$hasCatFilter = !empty($selectedCatIds);

$catWhere  = '';
$catParams = [];
if ($hasCatFilter) {
    $placeholders = implode(',', array_fill(0, count($selectedCatIds), '?'));
    $catWhere     = " AND p.category_id IN ($placeholders) ";
    $catParams    = $selectedCatIds;
}

/* URL builders */
function qsRange(string $r): string {
    $params = ['range' => $r];
    if (!empty($_GET['cats']))    $params['cats']    = $_GET['cats'];
    if (!empty($_GET['actions'])) $params['actions'] = $_GET['actions'];
    return http_build_query($params);
}

$reportLink = 'reports.php?' . http_build_query([
    'range'   => $range,
    'from'    => $from,
    'to'      => $to,
    'cats'    => implode(',', $selectedCatIds),
    'actions' => implode(',', $selectedActions),
]);

/* ============================================================
   ROLE-SCOPED DATA
   ============================================================ */
if ($user['role'] === 'user') {
    $stmt = db()->prepare('
        SELECT p.id AS product_id, p.name AS product_name, p.sku,
               p.warranty_until, p.lifecycle_stage,
               SUM(a.quantity) AS total_qty,
               MIN(a.assigned_at) AS first_assigned
        FROM assets a
        JOIN products p ON p.id = a.product_id
        WHERE a.assigned_to = ? AND a.status = "active"
        GROUP BY p.id
        ORDER BY first_assigned DESC
    ');
    $stmt->execute([$user['id']]);
    $myAssets = $stmt->fetchAll();

    $stmt = db()->prepare('
        SELECT r.report_code, r.type, r.title, r.status, r.created_at,
               r.remarks, r.resolved_at, u.full_name AS handled_by_name
        FROM reports r
        LEFT JOIN users u ON u.id = r.handled_by
        WHERE r.created_by = ? AND r.source = "user"
        ORDER BY r.created_at DESC
        LIMIT 20
    ');
    $stmt->execute([$user['id']]);
    $myReports = $stmt->fetchAll();

    $myOpenReports = 0;
    foreach ($myReports as $mr) if ($mr['status'] === 'pending') $myOpenReports++;

    $myTxns = q_my_transactions((int)$user['id']);
} else {

    $sql = "SELECT COALESCE(SUM(p.stock * p.unit_cost), 0)
            FROM products p
            WHERE p.status != 'disposed' " . $catWhere;
    $stmt = db()->prepare($sql);
    $stmt->execute($catParams);
    $inventoryValue = (float)$stmt->fetchColumn();

    $sql = "SELECT p.sku, p.name, p.stock, p.reorder_level, p.status
            FROM products p
            WHERE p.status IN ('low_stock','out_of_stock') " . $catWhere . "
            ORDER BY FIELD(p.status, 'out_of_stock','low_stock'), (p.reorder_level - p.stock) DESC";
    $stmt = db()->prepare($sql);
    $stmt->execute($catParams);
    $lowStock = $stmt->fetchAll();

    $openReports = q_open_reports();

    $sql = "SELECT COUNT(*)
            FROM transactions t
            JOIN products p ON p.id = t.product_id
            WHERE t.created_at BETWEEN ? AND ? " . $catWhere . $actionWhere;
    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge([$startSql, $endSql], $catParams, $actionParams));
    $totalTxns = (int)$stmt->fetchColumn();

    $allSeries = [];
    $labels    = [];

    if ($hasActionFilter) {
        $seriesData = [];
        foreach ($selectedActions as $a) $seriesData[$a] = [];

        $placeholders = implode(',', array_fill(0, count($selectedActions), '?'));
        $sql = "SELECT t.type, DATE(t.created_at) AS day, SUM(t.quantity * t.unit_cost) AS value
                FROM transactions t
                JOIN products p ON p.id = t.product_id
                WHERE t.created_at BETWEEN ? AND ?
                  AND t.type IN ($placeholders) " . $catWhere . "
                GROUP BY t.type, DATE(t.created_at)
                ORDER BY day";
        $stmt = db()->prepare($sql);
        $stmt->execute(array_merge([$startSql, $endSql], $selectedActions, $catParams));
        foreach ($stmt->fetchAll() as $r) {
            $seriesData[$r['type']][$r['day']] = (float)$r['value'];
        }

        $dayList = [];
        if ($daysInRange > 60) {
            $cur = clone $startDate;
            while ($cur <= $endDate) {
                $weekStart = clone $cur;
                $weekEnd   = (clone $cur)->modify('+6 days');
                if ($weekEnd > $endDate) $weekEnd = clone $endDate;
                $dayList[] = ['from' => $weekStart, 'to' => $weekEnd, 'label' => $weekStart->format('M j')];
                $cur->modify('+7 days');
            }
        } else {
            for ($i = 0; $i < $daysInRange; $i++) {
                $d = (clone $startDate)->modify("+{$i} days");
                $dayList[] = ['from' => $d, 'to' => $d, 'label' => $d->format('M j')];
            }
        }

        foreach ($selectedActions as $a) {
            $values = [];
            foreach ($dayList as $bucket) {
                $sum = 0;
                $d = clone $bucket['from'];
                while ($d <= $bucket['to']) {
                    $key = $d->format('Y-m-d');
                    if (isset($seriesData[$a][$key])) $sum += $seriesData[$a][$key];
                    $d->modify('+1 day');
                }
                $values[] = $sum;
            }
            $allSeries[] = ['name' => $allActions[$a][0], 'color' => $allActions[$a][1], 'values' => $values];
        }
        $labels = array_column($dayList, 'label');
    } else {
        $sql = "SELECT DATE(t.created_at) AS day,
                       SUM(CASE WHEN t.type IN ('inbound','returned')
                                THEN t.quantity * t.unit_cost ELSE 0 END) AS inbound,
                       SUM(CASE WHEN t.type IN ('outbound','damaged','for_repair','for_replacement','disposed')
                                THEN t.quantity * t.unit_cost ELSE 0 END) AS outbound
                FROM transactions t
                JOIN products p ON p.id = t.product_id
                WHERE t.created_at BETWEEN ? AND ? " . $catWhere . "
                GROUP BY DATE(t.created_at)
                ORDER BY day";
        $stmt = db()->prepare($sql);
        $stmt->execute(array_merge([$startSql, $endSql], $catParams));
        $rows = $stmt->fetchAll();

        $byDay = [];
        foreach ($rows as $r) $byDay[$r['day']] = $r;

        $inboundSeries  = [];
        $outboundSeries = [];

        if ($daysInRange > 60) {
            $cur = clone $startDate;
            while ($cur <= $endDate) {
                $weekStart = clone $cur;
                $weekEnd   = (clone $cur)->modify('+6 days');
                if ($weekEnd > $endDate) $weekEnd = clone $endDate;
                $in = 0; $out = 0;
                $d = clone $weekStart;
                while ($d <= $weekEnd) {
                    $key = $d->format('Y-m-d');
                    if (isset($byDay[$key])) {
                        $in  += (float)$byDay[$key]['inbound'];
                        $out += (float)$byDay[$key]['outbound'];
                    }
                    $d->modify('+1 day');
                }
                $labels[]         = $weekStart->format('M j');
                $inboundSeries[]  = $in;
                $outboundSeries[] = $out;
                $cur->modify('+7 days');
            }
        } else {
            for ($i = 0; $i < $daysInRange; $i++) {
                $d = (clone $startDate)->modify("+{$i} days");
                $key = $d->format('Y-m-d');
                $labels[]         = $d->format('M j');
                $inboundSeries[]  = (float)($byDay[$key]['inbound']  ?? 0);
                $outboundSeries[] = (float)($byDay[$key]['outbound'] ?? 0);
            }
        }

        $allSeries = [
            ['name' => 'Inbound',  'color' => '#4ec9b0', 'values' => $inboundSeries],
            ['name' => 'Outbound', 'color' => '#007acc', 'values' => $outboundSeries],
        ];
    }

    $sql = "SELECT c.name AS category, COALESCE(SUM(t.quantity * t.unit_cost), 0) AS moved_value
            FROM categories c
            LEFT JOIN products p     ON p.category_id = c.id
            LEFT JOIN transactions t ON t.product_id  = p.id
                                    AND t.created_at BETWEEN ? AND ?" . $actionWhere;
    $params = array_merge([$startSql, $endSql], $actionParams);
    if ($hasCatFilter) {
        $placeholders = implode(',', array_fill(0, count($selectedCatIds), '?'));
        $sql .= " WHERE c.id IN ($placeholders) ";
        $params = array_merge($params, $selectedCatIds);
    }
    $sql .= " GROUP BY c.id HAVING moved_value > 0 ORDER BY moved_value DESC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $catRows = $stmt->fetchAll();

$palette  = ['#007acc','#4ec9b0','#c586c0','#ce9178','#dcdcaa','#f44747'];
$catTotal = 0;
foreach ($catRows as $r) $catTotal += (float)$r['moved_value'];
if ($catTotal <= 0) $catTotal = 1;

$catSlices = [];
foreach ($catRows as $i => $r) {
    $pct = ($r['moved_value'] / $catTotal) * 100;

    if ($pct > 0 && $pct < 0.1) {
        $label = '<0.1%';
    } elseif ($pct >= 99.95) {
        $label = '>99.9%';
    } else {
        $label = round($pct, 1) . '%';
    }

    $catSlices[] = [
        'label' => $r['category'],
        'value' => (float)$r['moved_value'],
        'color' => $palette[$i % count($palette)],
        'pct'   => $label,
    ];
}

    $stmt = db()->prepare('
        SELECT username, role, action, module, status, created_at
        FROM audit_logs
        WHERE created_at BETWEEN ? AND ?
        ORDER BY id DESC
        LIMIT 10
    ');
    $stmt->execute([$startSql, $endSql]);
    $recentActivity = $stmt->fetchAll();
}

include 'header.php';
?>

<?php if ($user['role'] === 'user'): ?>
<!-- ============================================================
     USER DASHBOARD
     ============================================================ -->

<div class="banner info">
    <i class="fas fa-user"></i>
    <span>
        <strong>User access</strong> — Your assigned assets and submitted reports.
        Use <strong>Submit Report</strong> to report a problem or request something new.
    </span>
</div>

<div class="flex-between mb-16">
    <h2 style="margin:0;font-size:18px;">My Overview</h2>
    <div class="flex" style="gap:8px;">
        <button type="button" class="btn btn-outline" onclick="openWidgetPanel()">
            <i class="fas fa-sliders-h"></i> Customize
        </button>
        <a href="submit-report.php" class="btn btn-primary">
            <i class="fas fa-flag"></i> Submit Report
        </a>
    </div>
</div>

<?php
$totalHeldUnits = 0;
foreach ($myAssets as $a) $totalHeldUnits += (int)$a['total_qty'];
$userKpiVisible = $showWidget('user_kpi_assets') || $showWidget('user_kpi_reports') || $showWidget('user_kpi_txns');
?>

<?php if ($userKpiVisible): ?>
<div class="kpi-grid" style="grid-template-columns:repeat(3,1fr);">
    <?php if ($showWidget('user_kpi_assets')): ?>
    <div class="kpi-card" data-widget="user_kpi_assets">
        <div class="drag-handle" title="Drag to reorder">⋮⋮</div>
        <span class="label">Assets Assigned</span>
        <span class="value"><?= count($myAssets) ?></span>
        <span class="trend"><?= $totalHeldUnits ?> total unit<?= $totalHeldUnits === 1 ? '' : 's' ?></span>
        <button type="button" class="widget-remove" onclick="removeWidget('user_kpi_assets')" title="Hide">×</button>
    </div>
    <?php endif; ?>
    <?php if ($showWidget('user_kpi_reports')): ?>
    <div class="kpi-card" data-widget="user_kpi_reports">
        <div class="drag-handle" title="Drag to reorder">⋮⋮</div>
        <span class="label">My Open Reports</span>
        <span class="value <?= $myOpenReports > 0 ? 'text-yellow' : '' ?>"><?= (int)$myOpenReports ?></span>
        <span class="trend warn"><i class="fas fa-clock"></i> Pending review</span>
        <button type="button" class="widget-remove" onclick="removeWidget('user_kpi_reports')" title="Hide">×</button>
    </div>
    <?php endif; ?>
    <?php if ($showWidget('user_kpi_txns')): ?>
    <div class="kpi-card" data-widget="user_kpi_txns">
        <div class="drag-handle" title="Drag to reorder">⋮⋮</div>
        <span class="label">My Recent Transactions</span>
        <span class="value"><?= count($myTxns) ?></span>
        <span class="trend">Last 30 days</span>
        <button type="button" class="widget-remove" onclick="removeWidget('user_kpi_txns')" title="Hide">×</button>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($showWidget('user_table_assets')): ?>
<div class="table-card" data-widget="user_table_assets">
    <div class="table-header">
        <h3>My Assigned Assets</h3>
        <div class="flex" style="gap:8px;">
            <a href="assets.php" class="btn btn-outline" style="padding:4px 10px;font-size:12px;">
                View All <i class="fas fa-arrow-right"></i>
            </a>
            <button type="button" class="widget-remove" onclick="removeWidget('user_table_assets')" title="Hide">×</button>
        </div>
    </div>
    <table>
        <thead>
            <tr><th>Asset</th><th>SKU</th><th>Qty</th><th>Assigned Since</th><th>Warranty</th><th>Stage</th></tr>
        </thead>
        <tbody>
            <?php if (!$myAssets): ?>
                <tr><td colspan="6" class="text-muted">No assets assigned to you yet.</td></tr>
            <?php else: foreach ($myAssets as $a):
                if ($a['warranty_until']) {
                    $days = (int)((strtotime($a['warranty_until']) - time()) / 86400);
                    if ($days < 0)      $warrantyBadge = '<span class="badge danger">expired</span>';
                    elseif ($days < 30) $warrantyBadge = '<span class="badge warning">' . $days . ' d</span>';
                    else                $warrantyBadge = '<span class="badge success">' . round($days / 30) . ' mo</span>';
                } else {
                    $warrantyBadge = '<span class="text-muted">—</span>';
                }
                $stageClass = match($a['lifecycle_stage']) {
                    'acquired'  => 'info',
                    'in_use'    => 'success',
                    'in_repair' => 'warning',
                    'retired'   => 'muted',
                    default     => 'muted',
                };
            ?>
                <tr>
                    <td class="text-bright fw-600"><?= htmlspecialchars($a['product_name']) ?></td>
                    <td class="text-muted"><?= htmlspecialchars($a['sku']) ?></td>
                    <td class="text-bright fw-600"><?= (int)$a['total_qty'] ?></td>
                    <td><?= htmlspecialchars($a['first_assigned']) ?></td>
                    <td><?= $warrantyBadge ?></td>
                    <td><span class="badge <?= $stageClass ?>"><?= ucwords(str_replace('_',' ',$a['lifecycle_stage'])) ?></span></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ($showWidget('user_table_reports')): ?>
<div class="table-card" data-widget="user_table_reports">
    <div class="table-header">
        <h3>My Reports</h3>
        <div class="flex" style="gap:8px;">
            <a href="submit-report.php" class="btn btn-outline"><i class="fas fa-plus"></i> New Report</a>
            <button type="button" class="widget-remove" onclick="removeWidget('user_table_reports')" title="Hide">×</button>
        </div>
    </div>
    <table>
        <thead>
            <tr><th>Code</th><th>Date</th><th>Type</th><th>Title</th><th>Status</th><th>Handled By</th></tr>
        </thead>
        <tbody>
            <?php if (!$myReports): ?>
                <tr><td colspan="6" class="text-muted">You haven't submitted any reports yet.</td></tr>
            <?php else: foreach ($myReports as $r):
                $sc = match($r['status']) {
                    'pending'  => 'warning',
                    'resolved' => 'success',
                    default    => 'muted',
                };
            ?>
                <tr>
                    <td class="text-bright fw-600" style="font-size:12px;"><?= htmlspecialchars($r['report_code']) ?></td>
                    <td class="text-muted" style="font-size:12px;"><?= htmlspecialchars(substr($r['created_at'], 0, 10)) ?></td>
                    <td><span class="badge <?= $r['type'] === 'issue' ? 'danger' : 'info' ?>"><?= ucfirst($r['type']) ?></span></td>
                    <td><?= htmlspecialchars($r['title']) ?></td>
                    <td><span class="badge <?= $sc ?>"><?= ucfirst($r['status']) ?></span></td>
                    <td style="font-size:12px;"><?= htmlspecialchars($r['handled_by_name'] ?? '—') ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ============================================================
     ADMIN + SUPERADMIN DASHBOARD
     ============================================================ -->

<?php if ($user['role'] === 'superadmin'): ?>
    <div class="banner purple" style="background:rgba(197,134,192,0.08);border:1px solid var(--purple);color:var(--purple);">
        <i class="fas fa-shield-alt"></i>
        <span><strong>Superadmin</strong> — Full system access.</span>
    </div>
<?php else: ?>
    <div class="banner info">
        <i class="fas fa-users-cog"></i>
        <span><strong>Admin access</strong> — Manage inventory, transactions, reports, and view accounts.</span>
    </div>
<?php endif; ?>

<div class="flex-between mb-16">
    <h2 style="margin:0;font-size:18px;">Management Overview</h2>
    <div class="flex" style="gap:8px;">
        <button type="button" class="btn btn-outline" onclick="openWidgetPanel()">
            <i class="fas fa-sliders-h"></i> Customize
        </button>
        <a href="<?= htmlspecialchars($reportLink) ?>" class="btn btn-primary">
            <i class="fas fa-file-export"></i> View Full Report
        </a>
    </div>
</div>

<!-- Filter bar -->
<div class="card" style="padding:16px 20px;position:relative;overflow:visible;">
    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px;justify-content:space-between;">
        <div class="flex" style="gap:8px;flex-wrap:wrap;">
            <span class="text-muted" style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.4px;">Range:</span>

            <a href="?<?= qsRange('7d') ?>"      class="btn <?= $range === '7d'      ? 'btn-primary' : 'btn-outline' ?>" style="padding:6px 14px;font-size:12px;">Last 7 days</a>
            <a href="?<?= qsRange('30d') ?>"     class="btn <?= $range === '30d'     ? 'btn-primary' : 'btn-outline' ?>" style="padding:6px 14px;font-size:12px;">Last 30 days</a>
            <a href="?<?= qsRange('month') ?>"   class="btn <?= $range === 'month'   ? 'btn-primary' : 'btn-outline' ?>" style="padding:6px 14px;font-size:12px;">This month</a>
            <a href="?<?= qsRange('quarter') ?>" class="btn <?= $range === 'quarter' ? 'btn-primary' : 'btn-outline' ?>" style="padding:6px 14px;font-size:12px;">This quarter</a>

            <?php $customActive = ($range === 'custom'); ?>
            <a href="#" onclick="toggleCustomRange(event); return false;"
               class="btn <?= $customActive ? 'btn-primary' : 'btn-outline' ?>"
               style="padding:6px 14px;font-size:12px;">
                <i class="fas fa-calendar-alt"></i> Custom
            </a>

            <span style="width:12px;"></span>

            <div style="position:relative;" id="filterWrap">
                <button type="button"
                        class="btn <?= $hasCatFilter ? 'btn-primary' : 'btn-outline' ?>"
                        style="padding:6px 14px;font-size:12px;"
                        onclick="toggleFilter(event)">
                    <i class="fas fa-filter"></i>
                    Category<?= $hasCatFilter ? ' (' . count($selectedCatIds) . ')' : '' ?>
                    <i class="fas fa-chevron-down" style="font-size:9px;"></i>
                </button>

                <div id="filterPanel" style="display:none;position:absolute;top:100%;left:0;margin-top:6px;width:280px;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:0 8px 24px rgba(0,0,0,0.5);z-index:50;padding:12px 14px;">
                    <div class="flex-between mb-12" style="padding-bottom:8px;border-bottom:1px solid var(--border);">
                        <strong class="text-bright" style="font-size:12px;">Categories</strong>
                        <div class="flex" style="gap:10px;">
                            <button type="button" onclick="selectAllCats()" style="background:transparent;border:none;color:var(--accent-hover);font-size:11px;cursor:pointer;padding:0;">All</button>
                            <button type="button" onclick="clearCats()" style="background:transparent;border:none;color:var(--text-muted);font-size:11px;cursor:pointer;padding:0;">Clear</button>
                        </div>
                    </div>
                    <div style="max-height:260px;overflow-y:auto;">
                        <?php foreach ($allCategories as $c):
                            $checked = in_array((int)$c['id'], $selectedCatIds, true);
                        ?>
                            <label style="display:flex;align-items:center;gap:10px;padding:7px 0;font-size:13px;cursor:pointer;border-bottom:1px solid rgba(48,54,61,0.3);">
                                <input type="checkbox" class="cat-check" value="<?= (int)$c['id'] ?>" <?= $checked ? 'checked' : '' ?> style="width:15px;height:15px;accent-color:var(--accent);cursor:pointer;">
                                <span style="flex:1;color:var(--text);"><?= htmlspecialchars($c['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="flex" style="gap:8px;margin-top:14px;">
                        <button type="button" class="btn btn-primary" style="flex:1;padding:7px 12px;font-size:12px;" onclick="applyCats()">Apply</button>
                        <button type="button" class="btn btn-outline" style="padding:7px 12px;font-size:12px;" onclick="toggleFilter(event)">Cancel</button>
                    </div>
                </div>
            </div>

            <div style="position:relative;" id="actionWrap">
                <button type="button"
                        class="btn <?= $hasActionFilter ? 'btn-primary' : 'btn-outline' ?>"
                        style="padding:6px 14px;font-size:12px;"
                        onclick="toggleActionFilter(event)">
                    <i class="fas fa-exchange-alt"></i>
                    Action<?= $hasActionFilter ? ' (' . count($selectedActions) . ')' : '' ?>
                    <i class="fas fa-chevron-down" style="font-size:9px;"></i>
                </button>

                <div id="actionPanel" style="display:none;position:absolute;top:100%;left:0;margin-top:6px;width:260px;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:0 8px 24px rgba(0,0,0,0.5);z-index:50;padding:12px 14px;">
                    <div class="flex-between mb-12" style="padding-bottom:8px;border-bottom:1px solid var(--border);">
                        <strong class="text-bright" style="font-size:12px;">Transaction Actions</strong>
                        <div class="flex" style="gap:10px;">
                            <button type="button" onclick="selectAllActions()" style="background:transparent;border:none;color:var(--accent-hover);font-size:11px;cursor:pointer;padding:0;">All</button>
                            <button type="button" onclick="clearActions()" style="background:transparent;border:none;color:var(--text-muted);font-size:11px;cursor:pointer;padding:0;">Clear</button>
                        </div>
                    </div>
                    <p class="text-muted" style="font-size:11px;margin:0 0 10px;">
                        Applies to <strong>Transaction Summary</strong> and <strong>Overall Report</strong>.
                    </p>
                    <div style="max-height:260px;overflow-y:auto;">
                        <?php foreach ($allActions as $key => [$label, $color]):
                            $checked = in_array($key, $selectedActions, true);
                        ?>
                            <label style="display:flex;align-items:center;gap:10px;padding:7px 0;font-size:13px;cursor:pointer;border-bottom:1px solid rgba(48,54,61,0.3);">
                                <input type="checkbox" class="action-check" value="<?= $key ?>" <?= $checked ? 'checked' : '' ?> style="width:15px;height:15px;accent-color:var(--accent);cursor:pointer;">
                                <span style="width:10px;height:10px;border-radius:50%;background:<?= $color ?>;flex-shrink:0;"></span>
                                <span style="flex:1;color:var(--text);"><?= htmlspecialchars($label) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="flex" style="gap:8px;margin-top:14px;">
                        <button type="button" class="btn btn-primary" style="flex:1;padding:7px 12px;font-size:12px;" onclick="applyActions()">Apply</button>
                        <button type="button" class="btn btn-outline" style="padding:7px 12px;font-size:12px;" onclick="toggleActionFilter(event)">Cancel</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-muted" style="font-size:12px;">
            Showing <strong class="text-bright"><?= htmlspecialchars($startDate->format('M j, Y')) ?></strong>
            → <strong class="text-bright"><?= htmlspecialchars($endDate->format('M j, Y')) ?></strong>
            <span>(<?= $daysInRange ?>d)</span>
        </div>
    </div>

    <form method="GET" id="customRangeForm" style="display:<?= $customActive ? 'grid' : 'none' ?>;grid-template-columns:1fr 1fr auto;gap:12px;align-items:end;margin-top:14px;padding-top:14px;border-top:1px solid var(--border);">
        <input type="hidden" name="range" value="custom">
        <?php if ($hasCatFilter): ?>
            <input type="hidden" name="cats" value="<?= htmlspecialchars(implode(',', $selectedCatIds)) ?>">
        <?php endif; ?>
        <?php if ($hasActionFilter): ?>
            <input type="hidden" name="actions" value="<?= htmlspecialchars(implode(',', $selectedActions)) ?>">
        <?php endif; ?>
        <div class="form-group" style="margin:0;">
            <label for="from">From</label>
            <div class="input-wrap">
                <i class="fas fa-calendar icon"></i>
                <input type="date" id="from" name="from" value="<?= htmlspecialchars($from ?: $startDate->format('Y-m-d')) ?>" required>
            </div>
        </div>
        <div class="form-group" style="margin:0;">
            <label for="to">To</label>
            <div class="input-wrap">
                <i class="fas fa-calendar icon"></i>
                <input type="date" id="to" name="to" value="<?= htmlspecialchars($to ?: $endDate->format('Y-m-d')) ?>" required>
            </div>
        </div>
        <div>
            <button type="submit" class="btn btn-primary" style="width:100%;">
                <i class="fas fa-check"></i> Apply
            </button>
        </div>
    </form>
</div>

<script>
function toggleCustomRange(e) { e.preventDefault(); var f = document.getElementById('customRangeForm'); f.style.display = (f.style.display === 'none') ? 'grid' : 'none'; }
function toggleFilter(e) { e.stopPropagation(); var p = document.getElementById('filterPanel'); p.style.display = (p.style.display === 'none') ? 'block' : 'none'; }
function toggleActionFilter(e) { e.stopPropagation(); var p = document.getElementById('actionPanel'); p.style.display = (p.style.display === 'none') ? 'block' : 'none'; }
function selectAllCats() { document.querySelectorAll('.cat-check').forEach(function (cb) { cb.checked = true; }); }
function clearCats() { document.querySelectorAll('.cat-check').forEach(function (cb) { cb.checked = false; }); }
function selectAllActions() { document.querySelectorAll('.action-check').forEach(function (cb) { cb.checked = true; }); }
function clearActions() { document.querySelectorAll('.action-check').forEach(function (cb) { cb.checked = false; }); }
function applyCats() {
    var ids = [];
    document.querySelectorAll('.cat-check').forEach(function (cb) { if (cb.checked) ids.push(cb.value); });
    var params = new URLSearchParams(window.location.search);
    params.set('range', '<?= htmlspecialchars($range) ?>');
    if (ids.length > 0) params.set('cats', ids.join(','));
    else                params.delete('cats');
    window.location.href = 'dashboard.php?' + params.toString();
}
function applyActions() {
    var ids = [];
    document.querySelectorAll('.action-check').forEach(function (cb) { if (cb.checked) ids.push(cb.value); });
    var params = new URLSearchParams(window.location.search);
    params.set('range', '<?= htmlspecialchars($range) ?>');
    if (ids.length === 7) ids = [];
    if (ids.length > 0) params.set('actions', ids.join(','));
    else                params.delete('actions');
    window.location.href = 'dashboard.php?' + params.toString();
}
document.addEventListener('click', function (e) {
    var cw = document.getElementById('filterWrap'), cp = document.getElementById('filterPanel');
    if (cw && cp && !cw.contains(e.target)) cp.style.display = 'none';
    var aw = document.getElementById('actionWrap'), ap = document.getElementById('actionPanel');
    if (aw && ap && !aw.contains(e.target)) ap.style.display = 'none';
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        document.getElementById('filterPanel').style.display = 'none';
        document.getElementById('actionPanel').style.display = 'none';
    }
});
</script>

<?php
$adminKpiVisible = $showWidget('kpi_inventory_value') || $showWidget('kpi_total_transactions')
                || $showWidget('kpi_stock_alerts') || $showWidget('kpi_open_reports');
?>
<?php if ($adminKpiVisible): ?>
<div class="kpi-grid">
    <?php if ($showWidget('kpi_inventory_value')): ?>
    <div class="kpi-card" data-widget="kpi_inventory_value">
        <div class="drag-handle" title="Drag to reorder">⋮⋮</div>
        <span class="label">Inventory Value</span>
        <span class="value">₱<?= number_format($inventoryValue) ?></span>
        <span class="trend up"><?= $hasCatFilter ? 'In selected categories' : 'All products' ?></span>
        <button type="button" class="widget-remove" onclick="removeWidget('kpi_inventory_value')" title="Hide">×</button>
    </div>
    <?php endif; ?>
    <?php if ($showWidget('kpi_total_transactions')): ?>
    <div class="kpi-card" data-widget="kpi_total_transactions">
        <div class="drag-handle" title="Drag to reorder">⋮⋮</div>
        <span class="label"><?= $hasActionFilter ? 'Filtered Transactions' : 'Transactions in Range' ?></span>
        <span class="value"><?= number_format($totalTxns) ?></span>
        <span class="trend up"><?= htmlspecialchars($startDate->format('M j')) ?> → <?= htmlspecialchars($endDate->format('M j')) ?></span>
        <button type="button" class="widget-remove" onclick="removeWidget('kpi_total_transactions')" title="Hide">×</button>
    </div>
    <?php endif; ?>
    <?php if ($showWidget('kpi_stock_alerts')): ?>
    <div class="kpi-card" data-widget="kpi_stock_alerts">
        <div class="drag-handle" title="Drag to reorder">⋮⋮</div>
        <span class="label">Stock Alerts</span>
        <span class="value text-orange"><?= count($lowStock) ?></span>
        <span class="trend warn"><i class="fas fa-exclamation-triangle"></i> Current — <?= $hasCatFilter ? 'in selected categories' : 'all products' ?></span>
        <button type="button" class="widget-remove" onclick="removeWidget('kpi_stock_alerts')" title="Hide">×</button>
    </div>
    <?php endif; ?>
    <?php if ($showWidget('kpi_open_reports')): ?>
    <div class="kpi-card" data-widget="kpi_open_reports">
        <div class="drag-handle" title="Drag to reorder">⋮⋮</div>
        <span class="label">Open Reports</span>
        <span class="value text-yellow"><?= (int)$openReports ?></span>
        <span class="trend warn">Current — pending resolution</span>
        <button type="button" class="widget-remove" onclick="removeWidget('kpi_open_reports')" title="Hide">×</button>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($showWidget('chart_volume') || $showWidget('chart_category')): ?>
<div class="chart-grid" <?= !$showWidget('chart_volume') || !$showWidget('chart_category') ? 'style="grid-template-columns:1fr;"' : '' ?>>
    <?php if ($showWidget('chart_volume')): ?>
    <div class="chart-card" data-widget="chart_volume">
        <div class="chart-header">
            <h3>Transaction Volume (₱)</h3>
            <div class="flex" style="gap:16px;font-size:11px;">
                <?php foreach ($allSeries as $s): ?>
                    <span>
                        <span style="display:inline-block;width:10px;height:10px;background:<?= $s['color'] ?>;border-radius:50%;margin-right:6px;"></span>
                        <?= htmlspecialchars($s['name']) ?>
                    </span>
                <?php endforeach; ?>
                <button type="button" class="widget-remove" onclick="removeWidget('chart_volume')" title="Hide" style="margin-left:6px;">×</button>
            </div>
        </div>
        <div class="chart-placeholder">
            <?php
            $totalInSeries = 0;
            foreach ($allSeries as $s) $totalInSeries += array_sum($s['values']);
            ?>
            <?php if ($totalInSeries > 0): ?>
                <?= render_line_chart($allSeries, $labels, ['width' => 600, 'height' => 200]) ?>
            <?php else: ?>
                <p class="text-muted">No matching transactions in this range.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($showWidget('chart_category')): ?>
    <div class="chart-card" data-widget="chart_category">
        <div class="chart-header">
            <h3>Value Moved by Category</h3>
            <button type="button" class="widget-remove" onclick="removeWidget('chart_category')" title="Hide">×</button>
        </div>
        <div class="donut-wrapper">
            <?php if ($catSlices): ?>
                <?= render_donut_chart($catSlices, ['size' => 140]) ?>
                <div class="donut-legend">
                    <?php foreach ($catSlices as $s): ?>
                        <div class="legend-item">
                            <span class="dot" style="background:<?= $s['color'] ?>;"></span>
                            <?= htmlspecialchars($s['label']) ?>
<span class="pct"><?= $s['pct'] ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-muted" style="padding:20px;">No value moved in this range.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($user['role'] === 'superadmin' && $showWidget('card_superadmin')): ?>
<div class="card" data-widget="card_superadmin">
    <div class="flex-between mb-16">
        <h3 style="margin:0;">Superadmin Quick Actions</h3>
        <button type="button" class="widget-remove" onclick="removeWidget('card_superadmin')" title="Hide">×</button>
    </div>
    <div class="flex" style="flex-wrap: wrap;">
        <a href="accounts.php" class="btn btn-outline"><i class="fas fa-user-plus"></i> Create Account</a>
        <a href="accounts.php" class="btn btn-outline"><i class="fas fa-user-tag"></i> Assign Roles</a>
        <a href="system.php" class="btn btn-outline"><i class="fas fa-cogs"></i> System Settings</a>
        <a href="history.php" class="btn btn-outline"><i class="fas fa-history"></i> Full Audit Log</a>
    </div>
</div>
<?php endif; ?>

<?php if ($showWidget('table_low_stock')): ?>
<div class="table-card" data-widget="table_low_stock">
    <div class="table-header">
        <h3>Stock Alerts <span class="text-muted" style="font-weight:400;font-size:12px;">— <?= $hasCatFilter ? 'filtered' : 'current snapshot' ?></span></h3>
        <div class="flex" style="gap:8px;">
            <a href="inventory.php" class="btn btn-outline"><i class="fas fa-cube"></i> View Inventory</a>
            <button type="button" class="widget-remove" onclick="removeWidget('table_low_stock')" title="Hide">×</button>
        </div>
    </div>
    <table>
        <thead><tr><th>SKU</th><th>Product</th><th>Stock</th><th>Reorder Level</th><th>Status</th></tr></thead>
        <tbody>
            <?php if (!$lowStock): ?>
                <tr><td colspan="5" class="text-muted">All items are above their reorder level<?= $hasCatFilter ? ' in the selected categories' : '' ?>.</td></tr>
            <?php else: foreach ($lowStock as $p):
                $isOut      = $p['status'] === 'out_of_stock';
                $badgeClass = $isOut ? 'danger' : 'warning';
                $badgeLabel = $isOut ? 'Out of Stock' : 'Low Stock';
            ?>
                <tr>
                    <td class="text-bright fw-600"><?= htmlspecialchars($p['sku']) ?></td>
                    <td><?= htmlspecialchars($p['name']) ?></td>
                    <td class="<?= $isOut ? 'text-red fw-600' : 'text-orange fw-600' ?>"><?= (int)$p['stock'] ?></td>
                    <td><?= (int)$p['reorder_level'] ?></td>
                    <td><span class="badge <?= $badgeClass ?>"><?= $badgeLabel ?></span></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ($showWidget('table_recent_activity')): ?>
<div class="table-card" data-widget="table_recent_activity">
    <div class="table-header">
        <h3>Recent Activity <span class="text-muted" style="font-weight:400;font-size:12px;">— in range</span></h3>
        <div class="flex" style="gap:8px;">
            <a href="history.php" class="btn btn-outline"><i class="fas fa-history"></i> Full Log</a>
            <button type="button" class="widget-remove" onclick="removeWidget('table_recent_activity')" title="Hide">×</button>
        </div>
    </div>
    <div style="overflow-x:auto;">
        <table>
            <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Module</th><th>Status</th></tr></thead>
            <tbody>
                <?php if (!$recentActivity): ?>
                    <tr><td colspan="5" class="text-muted">No activity in this range.</td></tr>
                <?php else: foreach ($recentActivity as $r):
                    $sc = match($r['status']) {
                        'success' => 'success',
                        'warning' => 'warning',
                        'failed'  => 'danger',
                        default   => 'muted',
                    };
                ?>
                    <tr>
                        <td class="text-muted" style="font-size:12px;white-space:nowrap;"><?= htmlspecialchars(substr($r['created_at'], 5, 11)) ?></td>
                        <td style="white-space:nowrap;"><?= htmlspecialchars($r['username'] ?? 'unknown') ?></td>
                        <td><?= htmlspecialchars($r['action']) ?></td>
                        <td class="text-muted" style="white-space:nowrap;"><?= ucfirst(htmlspecialchars($r['module'])) ?></td>
                        <td><span class="badge <?= $sc ?>"><?= ucfirst($r['status']) ?></span></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<!-- ============================================================
     WIDGET CUSTOMIZE PANEL
     ============================================================ -->
<div id="widgetPanel" class="customize-panel">
    <div class="customize-header">
        <h3 style="margin:0;font-size:15px;">Customize Dashboard</h3>
        <button type="button" class="btn btn-ghost" onclick="closeWidgetPanel()" style="padding:4px 8px;">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <p class="text-muted" style="font-size:12px;margin:8px 0 16px;">
        Choose which widgets appear on your dashboard. Saved to your account.
    </p>

    <?php foreach ($allWidgets as $key => $label): ?>
        <label class="customize-toggle">
            <input type="checkbox"
                   data-widget="<?= htmlspecialchars($key) ?>"
                   <?= $showWidget($key) ? 'checked' : '' ?>
                   onchange="updateWidgets()">
            <span><?= htmlspecialchars($label) ?></span>
        </label>
    <?php endforeach; ?>

    <div class="flex" style="gap:8px;margin-top:20px;">
        <button type="button" class="btn btn-outline" style="flex:1;" onclick="resetWidgets()">
            <i class="fas fa-undo"></i> Reset All
        </button>
    </div>
</div>

<div id="widgetOverlay" class="customize-overlay" onclick="closeWidgetPanel()"></div>

<!-- ============================================================
     SORTABLE + WIDGET SAVE / REMOVE
     ============================================================ -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Sortable === 'undefined') {
        console.warn('SortableJS not loaded — drag disabled');
        return;
    }

    function initSortable(containerSelector, itemSelector, orderKey) {
        var container = document.querySelector(containerSelector);
        if (!container) return;

        Sortable.create(container, {
            animation: 200,
            handle: '.drag-handle',
            draggable: itemSelector,
            ghostClass: 'widget-ghost',
            chosenClass: 'widget-chosen',
            onEnd: function () {
                var ids = [];
                container.querySelectorAll(itemSelector).forEach(function (el) {
                    var id = el.getAttribute('data-widget');
                    if (id) ids.push(id);
                });

                var form = new URLSearchParams();
                form.append('save_widget_order', '1');
                form.append('order_key', orderKey);
                form.append('order', JSON.stringify(ids));

                fetch(window.location.pathname + window.location.search, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: form.toString(),
                }).then(function (r) { return r.json(); })
                  .then(function () { location.reload(); });
            }
        });
    }

    initSortable('.kpi-grid', '.kpi-card[data-widget]', 'kpi');
});

function openWidgetPanel() {
    document.getElementById('widgetPanel').classList.add('open');
    document.getElementById('widgetOverlay').classList.add('open');
}
function closeWidgetPanel() {
    document.getElementById('widgetPanel').classList.remove('open');
    document.getElementById('widgetOverlay').classList.remove('open');
}
function saveWidgets(enabled) {
    var form = new URLSearchParams();
    form.append('save_widgets', '1');
    form.append('widgets', JSON.stringify(enabled));
    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: form.toString(),
    }).then(function () { location.reload(); });
}
function updateWidgets() {
    var enabled = [];
    document.querySelectorAll('#widgetPanel input[data-widget]').forEach(function (cb) {
        if (cb.checked) enabled.push(cb.dataset.widget);
    });
    closeWidgetPanel();
    saveWidgets(enabled);
}
function resetWidgets() {
    document.querySelectorAll('#widgetPanel input[data-widget]').forEach(function (cb) {
        cb.checked = true;
    });
    saveWidgets([]);
}
function removeWidget(key) {
    var cb = document.querySelector('#widgetPanel input[data-widget="' + key + '"]');
    if (cb) cb.checked = false;
    updateWidgets();
}
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeWidgetPanel();
});
</script>

<?php include 'footer.php'; ?>
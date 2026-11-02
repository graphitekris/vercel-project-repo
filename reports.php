<?php
require 'config.php';
require 'auth.php';
require 'charts.php';

$user = require_role('admin', 'superadmin');

$pageTitle = 'Reports';
$activeNav = 'reports';

$flash = '';
$flashType = 'success';

$action = $_GET['action'] ?? 'list';

/* ============================================================
   DATE RANGE FILTER
   ============================================================ */
$range = $_GET['range'] ?? '30d';
$from  = $_GET['from'] ?? '';
$to    = $_GET['to']   ?? '';

$today = new DateTime('today');

switch ($range) {
    case '7d':
        $startDate = (clone $today)->modify('-6 days');
        $endDate   = clone $today;
        break;
    case '30d':
        $startDate = (clone $today)->modify('-29 days');
        $endDate   = clone $today;
        break;
    case 'month':
        $startDate = new DateTime(date('Y-m-01'));
        $endDate   = clone $today;
        break;
    case 'quarter':
        $q = (int)ceil((int)date('n') / 3);
        $startDate = new DateTime(date('Y') . '-' . str_pad((string)(($q - 1) * 3 + 1), 2, '0', STR_PAD_LEFT) . '-01');
        $endDate   = clone $today;
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

$snapshotTypes = ['warranty_expiry','license_expiry','client_holdings','user_assignments','issue_summary','overall_report'];

/* ============================================================
   DEPRECIATION HELPERS
   ============================================================ */
function depreciation_pct(float $cost, float $bookValue, float $salvage): float {
    $base = $cost - $salvage;
    if ($base <= 0) return 0.0;
    $pct = (($cost - $bookValue) / $base) * 100;
    return min(100.0, max(0.0, $pct));
}

function months_elapsed_label(int $elapsed, int $life): string {
    if ($life <= 0) return (string)$elapsed;
    if ($elapsed > $life) return $elapsed . ' (past life)';
    return (string)$elapsed;
}

/* ============================================================
   REPORT DATA PROVIDER
   ============================================================ */
function getReportData(string $type): array
{
    global $startSql, $endSql, $catWhere, $catParams, $actionWhere, $actionParams;

    $headers = [];
    $rows    = [];
    $pdo     = db();

    switch ($type) {

        case 'overall_report':
            $headers = ['Section', 'Metric / Item', 'Value'];

            $sql = <<<SQL
                SELECT COALESCE(SUM(p.stock * p.unit_cost), 0)
                FROM products p
                WHERE p.status != 'disposed' {$catWhere}
            SQL;
            $stmt = $pdo->prepare($sql); $stmt->execute($catParams);
            $inventoryValue = (float)$stmt->fetchColumn();

            $sql = <<<SQL
                SELECT COUNT(*) FROM products p
                WHERE p.status != 'disposed' {$catWhere}
            SQL;
            $stmt = $pdo->prepare($sql); $stmt->execute($catParams);
            $productCount = (int)$stmt->fetchColumn();

            $sql = <<<SQL
                SELECT COUNT(*) FROM products p
                WHERE p.status IN ('low_stock','out_of_stock') {$catWhere}
            SQL;
            $stmt = $pdo->prepare($sql); $stmt->execute($catParams);
            $alertCount = (int)$stmt->fetchColumn();

            $openReports = (int)$pdo->query("SELECT COUNT(*) FROM reports WHERE status = 'pending'")->fetchColumn();

            $sql = <<<SQL
                SELECT COUNT(*)
                FROM transactions t
                JOIN products p ON p.id = t.product_id
                WHERE t.created_at BETWEEN ? AND ? {$catWhere} {$actionWhere}
            SQL;
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$startSql, $endSql], $catParams, $actionParams));
            $txnCount = (int)$stmt->fetchColumn();

            $rows[] = ['Snapshot', 'Inventory Value', '₱' . number_format($inventoryValue, 2)];
            $rows[] = ['Snapshot', 'Active Products', $productCount];
            $rows[] = ['Snapshot', 'Stock Alerts', $alertCount];
            $rows[] = ['Snapshot', 'Open Reports', $openReports];
            $rows[] = ['Snapshot', 'Transactions in Range', $txnCount];

            $sql = <<<SQL
                SELECT c.name AS category,
                       COUNT(p.id) AS products,
                       COALESCE(SUM(p.stock), 0) AS stock,
                       COALESCE(SUM(p.stock * p.unit_cost), 0) AS value
                FROM categories c
                LEFT JOIN products p ON p.category_id = c.id AND p.status != 'disposed' {$catWhere}
                GROUP BY c.id
                HAVING products > 0
                ORDER BY value DESC
            SQL;
            $stmt = $pdo->prepare($sql); $stmt->execute($catParams);
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = ['Category', $r['category'],
                           $r['products'] . ' products · ' . $r['stock'] . ' units · ₱' . number_format((float)$r['value'], 2)];
            }

            $sql = <<<SQL
                SELECT p.sku, p.name, p.stock, p.unit_cost, (p.stock * p.unit_cost) AS value
                FROM products p
                WHERE p.status != 'disposed' {$catWhere}
                ORDER BY value DESC
                LIMIT 10
            SQL;
            $stmt = $pdo->prepare($sql); $stmt->execute($catParams);
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = ['Top Product', $r['sku'] . ' — ' . $r['name'],
                           $r['stock'] . ' units · ₱' . number_format((float)$r['value'], 2)];
            }

            // ---- Depreciation snapshot for the overall report ----
            $sql = <<<SQL
                SELECT p.sku, p.name, p.purchase_date, p.unit_cost, p.salvage_value, p.useful_life_months
                FROM products p
                WHERE p.purchase_date IS NOT NULL
                  AND p.useful_life_months IS NOT NULL
                  AND p.useful_life_months > 0
                  AND p.product_type != 'software'
                  AND p.ownership = 'company'
                  AND p.status != 'disposed' {$catWhere}
                ORDER BY p.purchase_date
                LIMIT 15
            SQL;
            $stmt = $pdo->prepare($sql); $stmt->execute($catParams);
            foreach ($stmt->fetchAll() as $r) {
                $cost    = (float)$r['unit_cost'];
                $salvage = (float)($r['salvage_value'] ?? 0);
                $life    = (int)$r['useful_life_months'];
                $start   = new DateTime($r['purchase_date']);
                $now     = new DateTime();
                $months  = ($now->format('Y') - $start->format('Y')) * 12
                         + ($now->format('n') - $start->format('n'));
                $months  = max(0, $months);
                $bv      = book_value_at($cost, $salvage, $life, $months);
                $pct     = depreciation_pct($cost, $bv, $salvage);

                $rows[] = ['Depreciation', $r['sku'] . ' — ' . $r['name'],
                           'Book ₱' . number_format($bv, 2) . ' · ' .
                           number_format($pct, 1) . '% depreciated · ' .
                           months_elapsed_label($months, $life) . ' of ' . $life . ' mo'];
            }

            $sql = <<<SQL
                SELECT p.sku, p.name, p.stock, p.reorder_level, p.status
                FROM products p
                WHERE p.status IN ('low_stock','out_of_stock') {$catWhere}
                ORDER BY FIELD(p.status, 'out_of_stock','low_stock'),
                         (p.reorder_level - p.stock) DESC
            SQL;
            $stmt = $pdo->prepare($sql); $stmt->execute($catParams);
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = ['Alert', $r['sku'] . ' — ' . $r['name'],
                           'Stock ' . $r['stock'] . ' / Reorder ' . $r['reorder_level'] . ' · ' . ucwords(str_replace('_',' ',$r['status']))];
            }

            $sql = <<<SQL
                SELECT p.sku, p.name, p.warranty_until,
                       DATEDIFF(p.warranty_until, CURDATE()) AS days_left
                FROM products p
                WHERE p.warranty_until IS NOT NULL
                  AND p.status != 'disposed'
                  AND DATEDIFF(p.warranty_until, CURDATE()) BETWEEN 0 AND 90 {$catWhere}
                ORDER BY p.warranty_until
                LIMIT 10
            SQL;
            $stmt = $pdo->prepare($sql); $stmt->execute($catParams);
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = ['Expiring Warranty', $r['sku'] . ' — ' . $r['name'],
                           'Expires ' . $r['warranty_until'] . ' (' . $r['days_left'] . ' days)'];
            }

            $sql = <<<SQL
                SELECT p.sku, p.name, p.license_vendor, p.license_expires_at,
                       DATEDIFF(p.license_expires_at, CURDATE()) AS days_left
                FROM products p
                WHERE p.product_type = 'software'
                  AND p.license_expires_at IS NOT NULL
                  AND p.status != 'disposed'
                  AND DATEDIFF(p.license_expires_at, CURDATE()) BETWEEN 0 AND 90 {$catWhere}
                ORDER BY p.license_expires_at
                LIMIT 10
            SQL;
            $stmt = $pdo->prepare($sql); $stmt->execute($catParams);
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = ['Expiring License', $r['sku'] . ' — ' . $r['name'],
                           ($r['license_vendor'] ? $r['license_vendor'] . ' · ' : '') .
                           'Expires ' . $r['license_expires_at'] . ' (' . $r['days_left'] . ' days)'];
            }

            $sql = <<<SQL
                SELECT u.full_name, u.role,
                       COUNT(DISTINCT a.product_id) AS products,
                       COALESCE(SUM(a.quantity), 0) AS units
                FROM users u
                LEFT JOIN assets a ON a.assigned_to = u.id AND a.status = 'active'
                WHERE u.status = 'active'
                GROUP BY u.id
                HAVING units > 0
                ORDER BY units DESC
            SQL;
            foreach ($pdo->query($sql)->fetchAll() as $r) {
                $rows[] = ['User Holdings', $r['full_name'] . ' (' . $r['role'] . ')',
                           $r['products'] . ' products · ' . $r['units'] . ' units'];
            }

            $sql = <<<SQL
                SELECT cl.name AS client,
                       COUNT(p.id) AS items,
                       SUM(p.ownership = 'company') AS company_owned,
                       SUM(p.ownership = 'client')  AS client_supplied,
                       COALESCE(SUM(p.stock * p.unit_cost), 0) AS value
                FROM clients cl
                LEFT JOIN products p ON p.client_id = cl.id AND p.status != 'disposed' {$catWhere}
                GROUP BY cl.id
                HAVING items > 0
                ORDER BY value DESC
            SQL;
            $stmt = $pdo->prepare($sql); $stmt->execute($catParams);
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = ['Client Holdings', $r['client'],
                           $r['items'] . ' items · ' . $r['company_owned'] . ' ours · ' .
                           $r['client_supplied'] . ' theirs · ₱' . number_format((float)$r['value'], 2)];
            }
            break;

        case 'inventory_snapshot':
            $headers = ['SKU','Name','Type','Ownership','Client','Category',
                        'Stock','Reorder','Unit Cost','Unit Price','Total Value','Status'];
            $sql = <<<SQL
                SELECT p.sku, p.name, p.product_type, p.ownership,
                       cl.name AS client, c.name AS category,
                       p.stock, p.reorder_level, p.unit_cost, p.unit_price,
                       (p.stock * p.unit_cost) AS total_value, p.status
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN clients cl   ON cl.id = p.client_id
                WHERE p.status != 'disposed' {$catWhere}
                ORDER BY c.name, p.name
            SQL;
            $stmt = $pdo->prepare($sql); $stmt->execute($catParams);
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [
                    $r['sku'], $r['name'], $r['product_type'], $r['ownership'],
                    $r['client'] ?? '', $r['category'] ?? '',
                    $r['stock'], $r['reorder_level'], $r['unit_cost'], $r['unit_price'],
                    number_format((float)$r['total_value'], 2, '.', ''), $r['status'],
                ];
            }
            break;

        case 'transaction_summary':
            $headers = ['Code','Date','Type','SKU','Product','Category','Qty',
                        'Unit Cost','Unit Price','Reference','User','Remarks'];
            $sql = <<<SQL
                SELECT t.txn_code, t.created_at, t.type, p.sku, p.name AS product,
                       c.name AS category,
                       t.quantity, t.unit_cost, t.unit_price, t.reference_no,
                       u.full_name AS user_name, t.remarks
                FROM transactions t
                LEFT JOIN products p   ON p.id = t.product_id
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN users u      ON u.id = t.created_by
                WHERE t.created_at BETWEEN ? AND ? {$catWhere} {$actionWhere}
                ORDER BY t.created_at DESC
            SQL;
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$startSql, $endSql], $catParams, $actionParams));
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [
                    $r['txn_code'], $r['created_at'], $r['type'],
                    $r['sku'] ?? '', $r['product'] ?? '', $r['category'] ?? '',
                    $r['quantity'], $r['unit_cost'], $r['unit_price'],
                    $r['reference_no'] ?? '', $r['user_name'] ?? '', $r['remarks'] ?? '',
                ];
            }
            break;

        case 'issue_summary':
            $headers = ['Report Code','Date','Type','Title','Created By',
                        'Status','Handled By','Remarks','Resolved At'];
            $sql = <<<SQL
                SELECT r.report_code, r.created_at, r.type, r.title,
                       cu.full_name AS created_by_name, r.status,
                       hu.full_name AS handled_by_name, r.remarks, r.resolved_at
                FROM reports r
                LEFT JOIN users cu ON cu.id = r.created_by
                LEFT JOIN users hu ON hu.id = r.handled_by
                WHERE r.source = 'user'
                ORDER BY r.created_at DESC
            SQL;
            foreach ($pdo->query($sql)->fetchAll() as $r) {
                $rows[] = [
                    $r['report_code'], $r['created_at'], $r['type'],
                    $r['title'], $r['created_by_name'] ?? '',
                    $r['status'], $r['handled_by_name'] ?? '',
                    $r['remarks'] ?? '', $r['resolved_at'] ?? '',
                ];
            }
            break;

        case 'depreciation':
            $headers = ['SKU','Name','Ownership','Client','Category','Purchase Date',
                        'Original Cost','Salvage Value','Useful Life (mo)',
                        'Months Elapsed','Current Book Value','% Depreciated'];
            $sql = <<<SQL
                SELECT p.sku, p.name, p.ownership, cl.name AS client, c.name AS category,
                       p.purchase_date, p.unit_cost, p.salvage_value, p.useful_life_months
                FROM products p
                LEFT JOIN clients cl    ON cl.id = p.client_id
                LEFT JOIN categories c  ON c.id = p.category_id
                WHERE p.purchase_date IS NOT NULL
                  AND p.useful_life_months IS NOT NULL
                  AND p.useful_life_months > 0
                  AND p.product_type != 'software'
                  AND p.ownership = 'company'
                  AND p.status != 'disposed' {$catWhere}
                ORDER BY p.purchase_date
            SQL;
            $stmt = $pdo->prepare($sql); $stmt->execute($catParams);
            foreach ($stmt->fetchAll() as $r) {
                $cost    = (float)$r['unit_cost'];
                $salvage = (float)($r['salvage_value'] ?? 0);
                $life    = (int)$r['useful_life_months'];
                $start   = new DateTime($r['purchase_date']);
                $now     = new DateTime();
                $months  = ($now->format('Y') - $start->format('Y')) * 12
                         + ($now->format('n') - $start->format('n'));
                $months  = max(0, $months);
                $bv      = book_value_at($cost, $salvage, $life, $months);
                $pct     = depreciation_pct($cost, $bv, $salvage);

                $rows[] = [
                    $r['sku'], $r['name'], $r['ownership'], $r['client'] ?? '', $r['category'] ?? '',
                    $r['purchase_date'],
                    number_format($cost, 2, '.', ''),
                    number_format($salvage, 2, '.', ''),
                    $life,
                    months_elapsed_label($months, $life),
                    number_format($bv, 2, '.', ''),
                    number_format($pct, 1, '.', '') . '%',
                ];
            }
            break;

        case 'warranty_expiry':
            $headers = ['SKU','Name','Ownership','Client','Category','Location',
                        'Warranty Until','Days Remaining','Status'];
            $sql = <<<SQL
                SELECT p.sku, p.name, p.ownership, cl.name AS client, c.name AS category,
                       p.location, p.warranty_until, p.status,
                       DATEDIFF(p.warranty_until, CURDATE()) AS days_left
                FROM products p
                LEFT JOIN clients cl    ON cl.id = p.client_id
                LEFT JOIN categories c  ON c.id = p.category_id
                WHERE p.warranty_until IS NOT NULL
                  AND p.status != 'disposed'
                  AND DATEDIFF(p.warranty_until, CURDATE()) <= 90 {$catWhere}
                ORDER BY p.warranty_until
            SQL;
            $stmt = $pdo->prepare($sql); $stmt->execute($catParams);
            foreach ($stmt->fetchAll() as $r) {
                $days  = (int)$r['days_left'];
                $state = $days < 0 ? 'EXPIRED' : ($days <= 30 ? 'EXPIRING SOON' : 'EXPIRING');
                $rows[] = [
                    $r['sku'], $r['name'], $r['ownership'], $r['client'] ?? '', $r['category'] ?? '',
                    $r['location'] ?? '', $r['warranty_until'], $days, $state,
                ];
            }
            break;

        case 'license_expiry':
            $headers = ['SKU','Name','Vendor','License Key','Seats',
                        'Expires','Days Remaining','Status'];
            $sql = <<<SQL
                SELECT p.sku, p.name, p.license_vendor, p.license_key, p.license_seats,
                       p.license_expires_at,
                       DATEDIFF(p.license_expires_at, CURDATE()) AS days_left
                FROM products p
                WHERE p.product_type = 'software'
                  AND p.license_expires_at IS NOT NULL
                  AND p.status != 'disposed'
                  AND DATEDIFF(p.license_expires_at, CURDATE()) <= 90 {$catWhere}
                ORDER BY p.license_expires_at
            SQL;
            $stmt = $pdo->prepare($sql); $stmt->execute($catParams);
            foreach ($stmt->fetchAll() as $r) {
                $days  = (int)$r['days_left'];
                $state = $days < 0 ? 'EXPIRED' : ($days <= 30 ? 'EXPIRING SOON' : 'EXPIRING');
                $rows[] = [
                    $r['sku'], $r['name'], $r['license_vendor'] ?? '',
                    $r['license_key'] ?? '', $r['license_seats'] ?? '',
                    $r['license_expires_at'], $days, $state,
                ];
            }
            break;

        case 'client_holdings':
            $headers = ['Client','Total Items','Company-owned','Client-supplied','Total Stock Value (₱)'];
            $sql = <<<SQL
                SELECT cl.name AS client,
                       COUNT(p.id) AS items,
                       SUM(p.ownership = 'company') AS company_owned,
                       SUM(p.ownership = 'client')  AS client_supplied,
                       SUM(p.stock * p.unit_cost)   AS total_value
                FROM clients cl
                LEFT JOIN products p ON p.client_id = cl.id AND p.status != 'disposed' {$catWhere}
                GROUP BY cl.id
                ORDER BY total_value DESC
            SQL;
            $stmt = $pdo->prepare($sql); $stmt->execute($catParams);
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [
                    $r['client'], $r['items'],
                    $r['company_owned'], $r['client_supplied'],
                    number_format((float)$r['total_value'], 2, '.', ''),
                ];
            }
            break;

        case 'user_assignments':
            $headers = ['User','Role','Assets Assigned','Total Stock Value (₱)'];
            $sql = <<<SQL
                SELECT u.full_name, u.role,
                       COUNT(p.id) AS assets,
                       COALESCE(SUM(p.stock * p.unit_cost), 0) AS total_value
                FROM users u
                LEFT JOIN products p ON p.assigned_to_user_id = u.id
                                    AND p.status != 'disposed' {$catWhere}
                WHERE u.status = 'active'
                GROUP BY u.id
                ORDER BY assets DESC, u.full_name
            SQL;
            $stmt = $pdo->prepare($sql); $stmt->execute($catParams);
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [
                    $r['full_name'], $r['role'], $r['assets'],
                    number_format((float)$r['total_value'], 2, '.', ''),
                ];
            }
            break;
    }

    return ['headers' => $headers, 'rows' => $rows];
}

/* ============================================================
   DOWNLOAD
   ============================================================ */
if ($action === 'download') {
    $type = $_GET['type'] ?? '';
    $validTypes = ['overall_report','inventory_snapshot','transaction_summary','issue_summary',
                   'depreciation','warranty_expiry','license_expiry',
                   'client_holdings','user_assignments'];
    if (!in_array($type, $validTypes, true)) {
        header('Location: reports.php?' . http_build_query($_GET));
        exit;
    }

    try {
        $next = (int) db()->query("SELECT COALESCE(MAX(id), 0) + 1 FROM reports")->fetchColumn();
        $code = 'RPT-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
        $title = ucwords(str_replace('_',' ',$type)) . ' — ' . $startDate->format('M j') . ' to ' . $endDate->format('M j, Y');
        if ($hasCatFilter)    $title .= ' (' . count($selectedCatIds) . ' categories)';
        if ($hasActionFilter) $title .= ' (' . implode(', ', $selectedActions) . ')';

        db()->prepare("
            INSERT INTO reports (report_code, source, type, title, status, created_by, handled_by, resolved_at)
            VALUES (?, 'generated', ?, ?, 'generated', ?, ?, NOW())
        ")->execute([$code, $type, $title, $user['id'], $user['id']]);

        log_action($user['id'], $user['username'], $user['role'],
            "Generated report {$code} ({$type})", 'reports', 'success');
    } catch (Throwable $e) {
        error_log('Report log failed: ' . $e->getMessage());
    }

    $data        = getReportData($type);
    $isSnapshot  = in_array($type, $snapshotTypes, true);

    $filename = 'assetdesk_' . $type . '_' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, ['AssetDesk Report', strtoupper($type)]);
    fputcsv($out, ['Generated by', $user['full_name']]);
    fputcsv($out, ['Generated at', date('Y-m-d H:i:s')]);

    if (!$isSnapshot) {
        fputcsv($out, ['Date range', $startDate->format('Y-m-d') . ' → ' . $endDate->format('Y-m-d')]);
    }
    if ($hasCatFilter) {
        $catNames = [];
        foreach ($allCategories as $c) {
            if (in_array((int)$c['id'], $selectedCatIds, true)) $catNames[] = $c['name'];
        }
        fputcsv($out, ['Category filter', implode(', ', $catNames)]);
    }
    if ($hasActionFilter && ($type === 'transaction_summary' || $type === 'overall_report')) {
        fputcsv($out, ['Action filter', implode(', ', $selectedActions)]);
    }

    fputcsv($out, []);

    if ($type === 'overall_report') {
        $currentSection = '';
        foreach ($data['rows'] as $row) {
            [$section, $label, $value] = $row;
            if ($section !== $currentSection) {
                if ($currentSection !== '') fputcsv($out, []);
                fputcsv($out, ['--- ' . strtoupper($section) . ' ---']);
                $currentSection = $section;
            }
            fputcsv($out, [$label, $value]);
        }
    } else {
        fputcsv($out, $data['headers']);
        foreach ($data['rows'] as $row) {
            fputcsv($out, $row);
        }
    }

    fclose($out);
    exit;
}

/* ============================================================
   PREVIEW
   ============================================================ */
if ($action === 'preview') {
    $type = $_GET['type'] ?? '';
    $validTypes = ['overall_report','inventory_snapshot','transaction_summary','issue_summary',
                   'depreciation','warranty_expiry','license_expiry',
                   'client_holdings','user_assignments'];
    if (!in_array($type, $validTypes, true)) {
        http_response_code(400);
        exit('Invalid type');
    }

    $data = getReportData($type);
    header('Content-Type: application/json');
    echo json_encode([
        'type'       => $type,
        'headers'    => $data['headers'],
        'rows'       => array_slice($data['rows'], 0, 20),
        'total'      => count($data['rows']),
        'isSnapshot' => in_array($type, $snapshotTypes, true),
        'rangeFrom'  => $startDate->format('M j, Y'),
        'rangeTo'    => $endDate->format('M j, Y'),
        'catCount'   => count($selectedCatIds),
        'actCount'   => count($selectedActions),
    ]);
    exit;
}

/* ============================================================
   RESOLVE
   ============================================================ */
if ($action === 'resolve' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $reportId = (int)($_POST['report_id'] ?? 0);
    $notes    = trim($_POST['resolution_notes'] ?? '');

    try {
        $stmt = db()->prepare("
            SELECT id, report_code, title, status FROM reports
            WHERE id = ? AND source = 'user' LIMIT 1
        ");
        $stmt->execute([$reportId]);
        $rep = $stmt->fetch();

        if (!$rep) {
            $flash = 'Report not found.'; $flashType = 'danger';
        } elseif ($rep['status'] === 'resolved') {
            $flash = 'Already resolved.'; $flashType = 'warning';
        } else {
            db()->prepare("
                UPDATE reports
                SET status = 'resolved', handled_by = ?, resolved_at = NOW(),
                    remarks = CONCAT(COALESCE(remarks,''), ?)
                WHERE id = ?
            ")->execute([
                $user['id'],
                $notes ? "\n\n[Resolved] " . $notes : '',
                $reportId,
            ]);

            log_action($user['id'], $user['username'], $user['role'],
                "Resolved report {$rep['report_code']} — {$rep['title']}",
                'reports', 'success');

            $flash = "Report {$rep['report_code']} resolved.";
        }
    } catch (Throwable $e) {
        error_log('Resolve failed: ' . $e->getMessage());
        $flash = 'Could not resolve.'; $flashType = 'danger';
    }
    header('Location: reports.php?' . http_build_query($_GET));
    exit;
}

/* ============================================================
   LOAD LOG
   ============================================================ */
$filterStatus = $_GET['status'] ?? '';
$filterType   = $_GET['type']   ?? '';

$where = []; $params = [];
if ($filterStatus !== '' && in_array($filterStatus, ['pending','resolved','generated'], true)) {
    $where[] = 'r.status = ?'; $params[] = $filterStatus;
}
if ($filterType !== '') {
    $where[] = 'r.type = ?'; $params[] = $filterType;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = db()->prepare("
    SELECT r.*, cu.full_name AS created_by_name, hu.full_name AS handled_by_name
    FROM reports r
    LEFT JOIN users cu ON cu.id = r.created_by
    LEFT JOIN users hu ON hu.id = r.handled_by
    {$whereSql}
    ORDER BY CASE WHEN r.status = 'pending' THEN 0 ELSE 1 END, r.created_at DESC
    LIMIT 200
");
$stmt->execute($params);
$reports = $stmt->fetchAll();

$kpi = db()->query("
    SELECT SUM(status='pending') AS pending, SUM(status='resolved') AS resolved, SUM(status='generated') AS generated
    FROM reports
")->fetch();

function qsRange(string $r): string {
    $params = ['range' => $r];
    if (!empty($_GET['cats']))    $params['cats']    = $_GET['cats'];
    if (!empty($_GET['actions'])) $params['actions'] = $_GET['actions'];
    return http_build_query($params);
}

include 'header.php';
?>

<?php if ($flash): ?>
    <div class="banner <?= $flashType ?>" data-autohide>
        <i class="fas fa-<?= $flashType === 'danger' ? 'exclamation-circle' : 'check-circle' ?>"></i>
        <span><?= htmlspecialchars($flash) ?></span>
    </div>
<?php endif; ?>

<div class="banner info">
    <i class="fas fa-file-alt"></i>
    <span>
        <strong>Reports hub.</strong> Set the filters, then click a generate button.
        A preview opens first. The <strong>Overall Report</strong> combines highlights into one file.
    </span>
</div>

<div class="kpi-grid" style="grid-template-columns:repeat(3,1fr);">
    <div class="kpi-card">
        <span class="label">Pending User Reports</span>
        <span class="value text-yellow"><?= (int)($kpi['pending'] ?? 0) ?></span>
        <span class="trend warn">Awaiting resolution</span>
    </div>
    <div class="kpi-card">
        <span class="label">Resolved</span>
        <span class="value text-green"><?= (int)($kpi['resolved'] ?? 0) ?></span>
        <span class="trend up">Closed out</span>
    </div>
    <div class="kpi-card">
        <span class="label">Generated Reports</span>
        <span class="value text-bright"><?= (int)($kpi['generated'] ?? 0) ?></span>
        <span class="trend">CSV snapshots</span>
    </div>
</div>

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
    window.location.href = 'reports.php?' + params.toString();
}
function applyActions() {
    var ids = [];
    document.querySelectorAll('.action-check').forEach(function (cb) { if (cb.checked) ids.push(cb.value); });
    var params = new URLSearchParams(window.location.search);
    params.set('range', '<?= htmlspecialchars($range) ?>');
    if (ids.length === 7) ids = [];
    if (ids.length > 0) params.set('actions', ids.join(','));
    else                params.delete('actions');
    window.location.href = 'reports.php?' + params.toString();
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

<div class="card">
    <h3>Generate Report</h3>
    <p class="text-muted mb-16" style="font-size:13px;">
        Click any report to preview it. The CSV includes exactly what the preview shows.
    </p>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;">
        <?php
        $buttons = [
            ['overall_report',      'Overall Report',           'fa-file-invoice'],
            ['inventory_snapshot',  'Inventory Snapshot',       'fa-cube'],
            ['transaction_summary', 'Transaction Summary',      'fa-exchange-alt'],
            ['issue_summary',       'Issue Summary',            'fa-exclamation-triangle'],
            ['depreciation',        'Depreciation / Book Value','fa-chart-line'],
            ['warranty_expiry',     'Warranty Expiry',          'fa-shield-alt'],
            ['license_expiry',      'License Expiry',           'fa-key'],
            ['client_holdings',     'Client Holdings',          'fa-building'],
            ['user_assignments',    'User Assignments',         'fa-user-tag'],
        ];
        foreach ($buttons as [$type, $label, $icon]):
        ?>
            <button type="button" class="btn btn-primary" onclick="openPreview('<?= $type ?>')">
                <i class="fas <?= $icon ?>"></i> <?= $label ?>
            </button>
        <?php endforeach; ?>
    </div>
</div>

<div class="flex-between mb-16">
    <div class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" placeholder="Filter visible rows..." data-filter-target="#reportsTable" />
    </div>
</div>

<div class="table-card">
    <table id="reportsTable">
        <thead>
            <tr>
                <th>Code</th>
                <th>Date</th>
                <th>Source</th>
                <th>Type</th>
                <th>Title</th>
                <th>Created By</th>
                <th>Status</th>
                <th>Handled By</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$reports): ?>
            <tr><td colspan="9" class="text-muted">No reports match the current filters.</td></tr>
        <?php else: foreach ($reports as $r):
            $statusClass = match($r['status']) {
                'pending'   => 'warning',
                'resolved'  => 'success',
                'generated' => 'info',
                default     => 'muted',
            };
            $sourceClass = match($r['source']) {
                'user'      => 'purple',
                'generated' => 'info',
                default     => 'muted',
            };
        ?>
            <tr>
                <td class="text-bright fw-600" style="font-size:12px;"><?= htmlspecialchars($r['report_code']) ?></td>
                <td class="text-muted" style="font-size:12px;white-space:nowrap;"><?= htmlspecialchars($r['created_at']) ?></td>
                <td><span class="badge <?= $sourceClass ?>"><?= ucfirst($r['source']) ?></span></td>
                <td class="text-muted" style="font-size:12px;"><?= ucwords(str_replace('_',' ',$r['type'])) ?></td>
                <td class="text-bright"><?= htmlspecialchars($r['title']) ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($r['created_by_name'] ?? '—') ?></td>
                <td><span class="badge <?= $statusClass ?>"><?= ucfirst($r['status']) ?></span></td>
                <td style="font-size:12px;"><?= htmlspecialchars($r['handled_by_name'] ?? '—') ?></td>
                <td style="text-align:right;white-space:nowrap;">
                    <?php if ($r['source'] === 'user' && $r['status'] === 'pending'): ?>
                        <button type="button" class="btn btn-outline"
                                style="padding:4px 10px;font-size:12px;color:var(--green);border-color:rgba(78,201,176,0.3);"
                                onclick="openResolve(<?= (int)$r['id'] ?>, '<?= htmlspecialchars($r['report_code'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['title'], ENT_QUOTES) ?>')">
                            <i class="fas fa-check"></i> Resolve
                        </button>
                    <?php else: ?>
                        <span class="text-muted" style="font-size:11px;">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div id="previewModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:1000;align-items:center;justify-content:center;padding:20px;">
    <div class="card" style="max-width:1100px;width:100%;max-height:90vh;margin:0;display:flex;flex-direction:column;">
        <div class="flex-between mb-16" style="padding-bottom:12px;border-bottom:1px solid var(--border);">
            <div>
                <h3 style="margin:0 0 4px;" id="previewTitle">Report Preview</h3>
                <p class="text-muted" style="margin:0;font-size:12px;" id="previewMeta"></p>
            </div>
            <button type="button" class="btn btn-ghost" onclick="closePreview()" style="padding:6px 10px;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div id="previewBody" style="flex:1;overflow:auto;min-height:0;"></div>
        <div class="flex" style="gap:10px;justify-content:flex-end;margin-top:16px;padding-top:12px;border-top:1px solid var(--border);">
            <button type="button" class="btn btn-outline" onclick="closePreview()">Close</button>
            <a href="#" id="previewDownloadBtn" class="btn btn-primary">
                <i class="fas fa-download"></i> Download Full CSV
            </a>
        </div>
    </div>
</div>

<div id="resolveModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:1000;align-items:center;justify-content:center;">
    <div class="card" style="max-width:520px;width:90%;margin:0;">
        <h3 style="margin-bottom:8px;">Resolve Report</h3>
        <p class="text-muted mb-16" style="font-size:13px;">
            <strong id="resolveCode"></strong> — <span id="resolveTitle"></span>
        </p>
        <form method="POST" action="reports.php?action=resolve">
            <input type="hidden" name="report_id" id="resolveId">
            <div class="form-group">
                <label for="resolution_notes">Resolution Notes <span class="text-muted" style="font-weight:400;">(optional)</span></label>
                <div class="input-wrap">
                    <i class="fas fa-comment icon"></i>
                    <input type="text" id="resolution_notes" name="resolution_notes"
                           placeholder="e.g. Replaced with spare unit">
                </div>
            </div>
            <div class="flex" style="gap:10px;">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check"></i> Mark Resolved
                </button>
                <button type="button" class="btn btn-outline" onclick="closeResolve()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openPreview(type) {
    var modal = document.getElementById('previewModal');
    modal.style.display = 'flex';

    document.getElementById('previewTitle').textContent = 'Loading…';
    document.getElementById('previewMeta').textContent  = '';
    document.getElementById('previewBody').innerHTML    = '<p class="text-muted" style="padding:20px;">Loading preview…</p>';

    var params = new URLSearchParams({
        action: 'preview',
        type:   type,
        range:  '<?= htmlspecialchars($range) ?>',
        from:   '<?= htmlspecialchars($from) ?>',
        to:     '<?= htmlspecialchars($to) ?>',
        cats:   '<?= htmlspecialchars(implode(",", $selectedCatIds)) ?>',
        actions:'<?= htmlspecialchars(implode(",", $selectedActions)) ?>',
    });

    fetch('reports.php?' + params.toString())
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var titleMap = {
                'overall_report':      'Overall Report',
                'inventory_snapshot':  'Inventory Snapshot',
                'transaction_summary': 'Transaction Summary',
                'issue_summary':       'Issue / Defect Summary',
                'depreciation':        'Depreciation / Book Value',
                'warranty_expiry':     'Warranty Expiry',
                'license_expiry':      'License Expiry',
                'client_holdings':     'Client Holdings',
                'user_assignments':    'User Assignments',
            };
            document.getElementById('previewTitle').textContent = titleMap[type] || type;

            var meta = 'Showing ' + Math.min(data.rows.length, 20) + ' of ' + data.total + ' rows';
            if (!data.isSnapshot) meta += ' · ' + data.rangeFrom + ' → ' + data.rangeTo;
            else                  meta += ' · current snapshot';
            if (data.catCount > 0) meta += ' · ' + data.catCount + ' categories';
            if (data.actCount > 0) meta += ' · ' + data.actCount + ' actions';
            document.getElementById('previewMeta').textContent = meta;

            document.getElementById('previewDownloadBtn').href =
                'reports.php?action=download&type=' + encodeURIComponent(type)
                + '&range=' + encodeURIComponent('<?= htmlspecialchars($range) ?>')
                + '&from='  + encodeURIComponent('<?= htmlspecialchars($from) ?>')
                + '&to='    + encodeURIComponent('<?= htmlspecialchars($to) ?>')
                + '&cats='  + encodeURIComponent('<?= htmlspecialchars(implode(",", $selectedCatIds)) ?>')
                + '&actions='+ encodeURIComponent('<?= htmlspecialchars(implode(",", $selectedActions)) ?>');

            if (!data.rows.length) {
                document.getElementById('previewBody').innerHTML =
                    '<p class="text-muted" style="padding:20px;">No rows match the current filters.</p>';
                return;
            }

            var html = '<table><thead><tr>';
            data.headers.forEach(function (h) { html += '<th>' + escapeHtml(h) + '</th>'; });
            html += '</tr></thead><tbody>';

            var lastSection = '';
            data.rows.forEach(function (row) {
                if (type === 'overall_report' && row[0] !== lastSection) {
                    html += '<tr style="background:rgba(0,122,204,0.08);"><td colspan="' + data.headers.length +
                            '" style="font-weight:600;color:var(--accent-hover);text-transform:uppercase;font-size:11px;letter-spacing:0.5px;">' +
                            escapeHtml(row[0]) + '</td></tr>';
                    lastSection = row[0];
                }
                html += '<tr>';
                row.forEach(function (cell) { html += '<td>' + escapeHtml(String(cell)) + '</td>'; });
                html += '</tr>';
            });
            html += '</tbody></table>';

            if (data.total > 20) {
                html += '<p class="text-muted" style="padding:12px 20px;font-size:12px;">… and ' +
                        (data.total - 20) + ' more rows. Download the CSV to see all.</p>';
            }

            document.getElementById('previewBody').innerHTML = html;
        })
        .catch(function () {
            document.getElementById('previewBody').innerHTML =
                '<p class="text-muted" style="padding:20px;">Failed to load preview.</p>';
        });
}
function closePreview() { document.getElementById('previewModal').style.display = 'none'; }
function escapeHtml(str) {
    return str.replace(/[&<>"']/g, function (m) {
        return ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' })[m];
    });
}
document.getElementById('previewModal').addEventListener('click', function (e) {
    if (e.target === this) closePreview();
});

function openResolve(id, code, title) {
    document.getElementById('resolveId').value = id;
    document.getElementById('resolveCode').textContent  = code;
    document.getElementById('resolveTitle').textContent = title;
    document.getElementById('resolveModal').style.display = 'flex';
    document.getElementById('resolution_notes').focus();
}
function closeResolve() { document.getElementById('resolveModal').style.display = 'none'; }

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closePreview();
        closeResolve();
        var p1 = document.getElementById('filterPanel'); if (p1) p1.style.display = 'none';
        var p2 = document.getElementById('actionPanel'); if (p2) p2.style.display = 'none';
    }
});
</script>

<?php include 'footer.php'; ?>
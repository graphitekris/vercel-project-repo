<?php
require 'config.php';
require 'auth.php';
require 'charts.php';

$user = require_role('admin', 'superadmin');
$isSuperadmin = ($user['role'] === 'superadmin');

$pageTitle = 'Product Detail';
$activeNav = 'inventory';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: inventory.php'); exit; }

/* ============================================================
   ADJUST
   ============================================================ */
$flash = '';
$flashType = 'success';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust'])) {
    $reorder     = (int)($_POST['reorder_level'] ?? 0);
    $unitCost    = (float)($_POST['unit_cost'] ?? 0);
    $unitPrice   = (float)($_POST['unit_price'] ?? 0);
    $purDate     = $_POST['purchase_date'] ?? '';
    $life        = (int)($_POST['useful_life_months'] ?? 0) ?: null;
    $salvage     = (float)($_POST['salvage_value'] ?? 0) ?: null;
    $warranty    = $_POST['warranty_until'] ?? '';
    $stage       = $_POST['lifecycle_stage'] ?? 'acquired';

    $clientId    = (int)($_POST['client_id'] ?? 0) ?: null;
    $location    = trim($_POST['location'] ?? '') ?: null;
    $tools       = trim($_POST['tools_included'] ?? '') ?: null;

    $licVendor   = trim($_POST['license_vendor'] ?? '') ?: null;
    $licKey      = trim($_POST['license_key'] ?? '') ?: null;
    $licSeats    = (int)($_POST['license_seats'] ?? 0) ?: null;
    $licExpires  = $_POST['license_expires_at'] ?? '';

    $validStages = ['acquired','in_use','in_repair','retired'];

    if ($reorder < 0)   $errors[] = 'Reorder level cannot be negative.';
    if ($unitCost < 0)  $errors[] = 'Unit cost cannot be negative.';
    if ($unitPrice < 0) $errors[] = 'Unit price cannot be negative.';
    if (!in_array($stage, $validStages, true)) $errors[] = 'Invalid lifecycle stage.';

    if (!$errors) {
        $stmt = db()->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $cur = $stmt->fetch();

        if (!$cur) {
            $flash = 'Product not found.'; $flashType = 'danger';
        } elseif ($cur['status'] === 'disposed') {
            $flash = 'Cannot adjust a disposed product.'; $flashType = 'danger';
        } else {
            $terminalStates = ['damaged','for_repair','for_replacement','disposed'];
            $newStatus = $cur['status'];
            if (!in_array($cur['status'], $terminalStates, true)) {
                $stock = (int)$cur['stock'];
                $newStatus = $stock <= 0 ? 'out_of_stock'
                    : ($stock <= $reorder ? 'low_stock' : 'in_stock');
            }

            db()->prepare('
                UPDATE products
                SET reorder_level=?, unit_cost=?, unit_price=?,
                    purchase_date=?, useful_life_months=?, salvage_value=?,
                    warranty_until=?, lifecycle_stage=?,
                    client_id=?, location=?, tools_included=?,
                    license_vendor=?, license_key=?, license_seats=?, license_expires_at=?,
                    status=?
                WHERE id=?
            ')->execute([
                $reorder, $unitCost, $unitPrice,
                $purDate ?: null, $life, $salvage,
                $warranty ?: null, $stage,
                $clientId, $location, $tools,
                $licVendor, $licKey, $licSeats, $licExpires ?: null,
                $newStatus, $id
            ]);

            log_action($user['id'], $user['username'], $user['role'],
                "Adjusted {$cur['sku']} — stage: {$stage}", 'inventory', 'success');

            $flash = 'Product updated.';
        }
    }
}

/* ============================================================
   LOAD
   ============================================================ */
$stmt = db()->prepare('
    SELECT p.*, c.name AS category_name,
           cl.name AS client_name, cl.contact_name AS client_contact, cl.contact_email AS client_email
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN clients cl   ON cl.id = p.client_id
    WHERE p.id = ?
    LIMIT 1
');
$stmt->execute([$id]);
$product = $stmt->fetch();
if (!$product) { header('Location: inventory.php'); exit; }

$allClients = db()->query('SELECT id, name FROM clients ORDER BY name')->fetchAll();

$stmt = db()->prepare('
    SELECT u.id AS user_id, u.full_name, u.username, u.role, u.avatar, u.avatar_color,
           SUM(a.quantity) AS total_qty,
           MIN(a.assigned_at) AS first_assigned,
           GROUP_CONCAT(DISTINCT a.remarks SEPARATOR "; ") AS remarks
    FROM assets a
    JOIN users u ON u.id = a.assigned_to
    WHERE a.product_id = ? AND a.status = "active"
    GROUP BY u.id
    ORDER BY first_assigned
');
$stmt->execute([$id]);
$currentHolders = $stmt->fetchAll();

$stmt = db()->prepare('
    SELECT a.id AS asset_id, a.quantity, a.assigned_at, a.remarks,
           u.full_name, u.username, u.avatar, u.avatar_color,
           t.txn_code, t.created_at AS damaged_at
    FROM assets a
    LEFT JOIN users u ON u.id = a.assigned_to
    LEFT JOIN transactions t ON t.id = a.transaction_id
    WHERE a.product_id = ? AND a.status = "damaged"
    ORDER BY a.assigned_at DESC, a.id DESC
');
$stmt->execute([$id]);
$damagedUnits = $stmt->fetchAll();

$totalDamaged = 0;
foreach ($damagedUnits as $d) $totalDamaged += (int)$d['quantity'];

$stmt = db()->prepare('
    SELECT a.id AS asset_id, a.quantity, a.assigned_at, a.remarks,
           u.full_name, u.username, u.avatar, u.avatar_color,
           t.txn_code, t.created_at AS disposed_at
    FROM assets a
    LEFT JOIN users u ON u.id = a.assigned_to
    LEFT JOIN transactions t ON t.id = a.transaction_id
    WHERE a.product_id = ? AND a.status = "disposed"
    ORDER BY a.assigned_at DESC, a.id DESC
');
$stmt->execute([$id]);
$disposedUnits = $stmt->fetchAll();

$totalDisposed = 0;
foreach ($disposedUnits as $d) $totalDisposed += (int)$d['quantity'];

$totalHeld = 0;
foreach ($currentHolders as $h) $totalHeld += (int)$h['total_qty'];

$stmt = db()->prepare('
    SELECT a.*, u.full_name AS user_name, u.username
    FROM assets a
    LEFT JOIN users u ON u.id = a.assigned_to
    WHERE a.product_id = ?
    ORDER BY a.assigned_at DESC, a.id DESC
    LIMIT 30
');
$stmt->execute([$id]);
$assignmentHistory = $stmt->fetchAll();

$stmt = db()->prepare('
    SELECT t.*,
           u.full_name AS user_name,
           fu.full_name AS from_user_name
    FROM transactions t
    LEFT JOIN users u  ON u.id = t.created_by
    LEFT JOIN users fu ON fu.id = t.from_user_id
    WHERE t.product_id = ?
    ORDER BY t.created_at DESC
    LIMIT 100
');
$stmt->execute([$id]);
$transactions = $stmt->fetchAll();

$stmt = db()->prepare('SELECT COUNT(*) FROM transactions WHERE product_id = ?');
$stmt->execute([$id]);
$txnRefCount = (int)$stmt->fetchColumn();

$stmt = db()->prepare('SELECT COUNT(*) FROM assets WHERE product_id = ?');
$stmt->execute([$id]);
$assetRefCount = (int)$stmt->fetchColumn();

$canDelete = ($txnRefCount === 0 && $assetRefCount === 0 && $product['status'] !== 'disposed');

/* Compute disposal eligibility */
$stmt = db()->prepare('
    SELECT COALESCE(SUM(quantity), 0)
    FROM assets
    WHERE product_id = ? AND status = "active"
');
$stmt->execute([$id]);
$heldByUsers = (int)$stmt->fetchColumn();
$canDispose  = ($product['status'] !== 'disposed' && (int)$product['stock'] > 0 && $heldByUsers === 0);

/* ============================================================
   BOOK VALUE
   ============================================================ */
$bookValueSeries = [];
$bookValueLabels = [];
$bookValueNow    = null;

if ($product['purchase_date'] && $product['useful_life_months']
    && $product['product_type'] !== 'software') {
    $cost    = (float)$product['unit_cost'];
    $salvage = (float)($product['salvage_value'] ?? 0);
    $life    = (int)$product['useful_life_months'];

    $start = new DateTime($product['purchase_date']);
    $now   = new DateTime();

    $points = 12;
    for ($i = 0; $i <= $points; $i++) {
        $months = (int)round(($life / $points) * $i);
        $date   = (clone $start)->modify("+{$months} months");
        $value  = book_value_at($cost, $salvage, $life, $months);
        $bookValueSeries[] = round($value);
        $bookValueLabels[] = $date->format('M y');
    }

    $monthsElapsed = ($now->format('Y') - $start->format('Y')) * 12
                   + ($now->format('n') - $start->format('n'));
    $bookValueNow  = book_value_at($cost, $salvage, $life, max(0, $monthsElapsed));
}

/* ============================================================
   LIFECYCLE
   ============================================================ */
$lifecycle = [];

if ($product['purchase_date']) {
    $lifecycle[] = [
        'icon'  => 'fa-shopping-cart',
        'color' => 'var(--accent)',
        'label' => 'Purchased',
        'date'  => $product['purchase_date'],
        'note'  => '₱' . number_format((float)$product['unit_cost'], 2),
    ];
}

if ($product['warranty_until']) {
    $lifecycle[] = [
        'icon'  => 'fa-shield-alt',
        'color' => 'var(--green)',
        'label' => 'Warranty until',
        'date'  => $product['warranty_until'],
        'note'  => (function () use ($product) {
            $days = (int)((strtotime($product['warranty_until']) - time()) / 86400);
            if ($days < 0)  return 'expired';
            if ($days < 30) return "expires in {$days}d";
            return 'in ' . round($days / 30) . ' months';
        })(),
    ];
}

if ($product['product_type'] === 'software' && $product['license_expires_at']) {
    $lifecycle[] = [
        'icon'  => 'fa-key',
        'color' => 'var(--purple)',
        'label' => 'License expires',
        'date'  => $product['license_expires_at'],
        'note'  => $product['license_vendor'] ?? '',
    ];
}

foreach ($transactions as $t) {
    if (in_array($t['type'], ['inbound'], true)) continue;
    $lifecycle[] = [
        'icon'  => match($t['type']) {
            'outbound'        => 'fa-share',
            'damaged'         => 'fa-exclamation-triangle',
            'for_repair'      => 'fa-tools',
            'for_replacement' => 'fa-exchange-alt',
            'disposed'        => 'fa-archive',
            'returned'        => 'fa-undo',
            default           => 'fa-circle',
        },
        'color' => match($t['type']) {
            'outbound'        => 'var(--accent)',
            'damaged'         => 'var(--red)',
            'for_repair'      => 'var(--yellow)',
            'for_replacement' => 'var(--purple)',
            'disposed'        => 'var(--text-muted)',
            'returned'        => 'var(--green)',
            default           => 'var(--text-muted)',
        },
        'label' => ucfirst(str_replace('_',' ',$t['type'])),
        'date'  => substr($t['created_at'], 0, 10),
        'note'  => ($t['reference_no'] ? $t['reference_no'] . ' · ' : '') . ($t['remarks'] ?? ''),
    ];
}

usort($lifecycle, fn($a, $b) => strtotime($a['date']) - strtotime($b['date']));

$statusClass = match($product['status']) {
    'in_stock'        => 'success',
    'low_stock'       => 'warning',
    'out_of_stock'    => 'danger',
    'damaged'         => 'danger',
    'for_repair'      => 'info',
    'for_replacement' => 'purple',
    'disposed'        => 'muted',
    default           => 'muted',
};

$typeClass = match($product['product_type']) {
    'hardware'   => 'info',
    'software'   => 'purple',
    'consumable' => 'muted',
    default      => 'muted',
};

$stageClass = match($product['lifecycle_stage']) {
    'acquired'  => 'info',
    'in_use'    => 'success',
    'in_repair' => 'warning',
    'retired'   => 'muted',
    default     => 'muted',
};

include 'header.php';
?>

<?php if (isset($_GET['flash']) && $_GET['flash'] === 'disposed'): ?>
    <div class="banner success" data-autohide>
        <i class="fas fa-check-circle"></i>
        <span>Product disposed. All stock removed, history preserved.</span>
    </div>
<?php endif; ?>

<?php if (isset($_GET['error'])): ?>
    <div class="banner danger">
        <i class="fas fa-exclamation-circle"></i>
        <span>
            <?php if ($_GET['error'] === 'held'): ?>
                Cannot dispose — users are still holding units.
            <?php elseif ($_GET['error'] === 'failed'): ?>
                Disposal failed. Check the error log.
            <?php else: ?>
                Something went wrong.
            <?php endif; ?>
        </span>
    </div>
<?php endif; ?>

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
            <?php foreach ($errors as $e): ?>
                <div><?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="flex-between mb-16">
    <div>
        <h2 style="margin:0 0 4px;font-size:20px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <?= htmlspecialchars($product['name']) ?>
            <span class="badge <?= $typeClass ?>"><?= ucfirst($product['product_type']) ?></span>
            <span class="badge <?= $stageClass ?>"><?= ucwords(str_replace('_',' ',$product['lifecycle_stage'])) ?></span>
        </h2>
        <p class="text-muted" style="margin:0;font-size:13px;">
            <code><?= htmlspecialchars($product['sku']) ?></code>
            <?php if ($product['category_name']): ?> · <?= htmlspecialchars($product['category_name']) ?><?php endif; ?>
        </p>
    </div>
    <div class="flex">
        <a href="inventory.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
        <a href="transactions.php?product=<?= (int)$product['id'] ?>" class="btn btn-primary">
            <i class="fas fa-exchange-alt"></i> Transactions
        </a>
    </div>
</div>

<div class="banner info">
    <i class="fas fa-lock"></i>
    <span>
        SKU, name, and product type are immutable. Stock and user assignments change only through
        <strong>transactions</strong>. Lifecycle, pricing, client, and license are adjustable below.
    </span>
</div>

<!-- KPI row -->
<div class="kpi-grid" style="grid-template-columns:repeat(7,1fr);">
    <div class="kpi-card">
        <span class="label">Stock On Hand</span>
        <span class="value <?= $product['stock'] <= 0 ? 'text-red' : ($product['stock'] <= $product['reorder_level'] ? 'text-orange' : '') ?>">
            <?= (int)$product['stock'] ?>
        </span>
        <span class="trend"><span class="badge <?= $statusClass ?>"><?= ucwords(str_replace('_',' ',$product['status'])) ?></span></span>
    </div>
    <div class="kpi-card">
        <span class="label">Held by Users</span>
        <span class="value text-bright"><?= $totalHeld ?></span>
        <span class="trend">
            <?php if ($currentHolders): ?>
                across <?= count($currentHolders) ?> user<?= count($currentHolders) === 1 ? '' : 's' ?>
            <?php else: ?>
                nobody
            <?php endif; ?>
        </span>
    </div>
    <div class="kpi-card">
        <span class="label">Damaged Units</span>
        <span class="value <?= $totalDamaged > 0 ? 'text-red' : '' ?>"><?= $totalDamaged ?></span>
        <span class="trend"><?= $totalDamaged > 0 ? 'out of usable stock' : 'none' ?></span>
    </div>
    <div class="kpi-card">
        <span class="label">Disposed Units</span>
        <span class="value <?= $totalDisposed > 0 ? 'text-muted' : '' ?>"><?= $totalDisposed ?></span>
        <span class="trend"><?= $totalDisposed > 0 ? 'removed from system' : 'none' ?></span>
    </div>
    <div class="kpi-card">
        <span class="label">Unit Cost</span>
        <span class="value" style="font-size:20px;">₱<?= number_format((float)$product['unit_cost'], 2) ?></span>
        <span class="trend">Reorder at <?= (int)$product['reorder_level'] ?></span>
    </div>
    <div class="kpi-card">
        <span class="label">Unit Price</span>
        <span class="value" style="font-size:20px;">₱<?= number_format((float)$product['unit_price'], 2) ?></span>
    </div>
    <div class="kpi-card">
        <span class="label">Book Value (now)</span>
        <span class="value" style="font-size:20px;">
            <?= $bookValueNow !== null ? '₱' . number_format($bookValueNow, 2) : '—' ?>
        </span>
        <?php if ($product['purchase_date']): ?>
            <span class="trend">since <?= htmlspecialchars($product['purchase_date']) ?></span>
        <?php endif; ?>
    </div>
</div>

<!-- Currently Held By -->
<div class="card">
    <div class="flex-between mb-16">
        <h3 style="margin:0;"><i class="fas fa-users"></i> Currently Held By</h3>
        <a href="transactions.php?action=new&product=<?= (int)$product['id'] ?>" class="btn btn-outline" style="padding:6px 12px;font-size:12px;">
            <i class="fas fa-share"></i> Log Outbound
        </a>
    </div>

    <p class="text-muted" style="font-size:12px;margin-bottom:16px;">
        Assignments are created by <strong>outbound transactions</strong> and removed by
        <strong>returned</strong>, <strong>damaged</strong>, or <strong>disposed</strong> transactions.
    </p>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
        <div>
            <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:0.4px;margin-bottom:8px;">
                Active Holdings
            </div>
            <?php if (!$currentHolders): ?>
                <div style="padding:12px 14px;background:var(--bg-alt);border:1px solid var(--border);border-radius:var(--radius);">
                    <span class="text-muted">No units currently held by any user.</span>
                </div>
            <?php else: ?>
                <?php foreach ($currentHolders as $h): ?>
                    <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:var(--bg-alt);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:8px;">
                        <div style="width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#fff;background:<?= htmlspecialchars($h['avatar_color'] ?: '#888') ?>;">
                            <?= htmlspecialchars($h['avatar'] ?: strtoupper(substr($h['full_name'], 0, 2))) ?>
                        </div>
                        <div style="flex:1;">
                            <div class="text-bright fw-600"><?= htmlspecialchars($h['full_name']) ?></div>
                            <div class="text-muted" style="font-size:11px;">since <?= htmlspecialchars($h['first_assigned']) ?></div>
                        </div>
                        <div style="text-align:right;">
                            <div class="text-bright fw-600" style="font-size:18px;"><?= (int)$h['total_qty'] ?></div>
                            <div class="text-muted" style="font-size:10px;">unit<?= (int)$h['total_qty'] === 1 ? '' : 's' ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div>
            <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:0.4px;margin-bottom:8px;">
                Assignment History
            </div>
            <?php if (!$assignmentHistory): ?>
                <p class="text-muted" style="font-size:13px;">No assignments yet.</p>
            <?php else: ?>
                <div style="max-height:280px;overflow-y:auto;">
                    <?php foreach ($assignmentHistory as $a): ?>
                        <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid rgba(48,54,61,0.4);font-size:13px;">
                            <div style="flex:1;">
                                <div class="text-bright">
                                    <?= htmlspecialchars($a['user_name'] ?? '—') ?>
                                    <span class="text-muted" style="font-size:11px;">· qty <?= (int)$a['quantity'] ?></span>
                                </div>
                                <div class="text-muted" style="font-size:11px;"><?= htmlspecialchars($a['assigned_at']) ?></div>
                            </div>
                            <span class="badge <?= $a['status'] === 'active' ? 'success' : ($a['status'] === 'damaged' ? 'danger' : ($a['status'] === 'disposed' ? 'muted' : 'muted')) ?>">
                                <?= ucfirst($a['status']) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Damaged Units -->
<?php if ($damagedUnits): ?>
<div class="card">
    <div class="flex-between mb-16">
        <h3 style="margin:0;color:var(--red);">
            <i class="fas fa-exclamation-triangle"></i>
            Damaged Units
        </h3>
        <span class="badge danger"><?= $totalDamaged ?> unit<?= $totalDamaged === 1 ? '' : 's' ?></span>
    </div>

    <p class="text-muted" style="font-size:12px;margin-bottom:16px;">
        These units are no longer in usable stock. They were
        <strong>held by a user</strong> at the time they were damaged.
    </p>

    <table>
        <thead>
            <tr>
                <th>Unit</th>
                <th>Quantity</th>
                <th>Held By</th>
                <th>Damaged</th>
                <th>Transaction</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($damagedUnits as $d): ?>
                <tr>
                    <td class="text-muted" style="font-size:12px;">Asset #<?= (int)$d['asset_id'] ?></td>
                    <td class="text-red fw-600"><?= (int)$d['quantity'] ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div style="width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;background:<?= htmlspecialchars($d['avatar_color'] ?: '#888') ?>;">
                                <?= htmlspecialchars($d['avatar'] ?: ($d['full_name'] ? strtoupper(substr($d['full_name'], 0, 2)) : '—')) ?>
                            </div>
                            <span class="text-bright"><?= htmlspecialchars($d['full_name'] ?? '—') ?></span>
                        </div>
                    </td>
                    <td class="text-muted" style="font-size:12px;">
                        <?= htmlspecialchars($d['damaged_at'] ?? $d['assigned_at']) ?>
                    </td>
                    <td class="text-muted" style="font-size:12px;">
                        <?= htmlspecialchars($d['txn_code'] ?? '—') ?>
                    </td>
                    <td class="text-muted" style="font-size:12px;">
                        <?= htmlspecialchars($d['remarks'] ?? '—') ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Disposed Units -->
<?php if ($disposedUnits): ?>
<div class="card">
    <div class="flex-between mb-16">
        <h3 style="margin:0;color:var(--text-muted);">
            <i class="fas fa-archive"></i>
            Disposed Units
        </h3>
        <span class="badge muted"><?= $totalDisposed ?> unit<?= $totalDisposed === 1 ? '' : 's' ?></span>
    </div>

    <p class="text-muted" style="font-size:12px;margin-bottom:16px;">
        These units have been permanently removed from the system — sold, recycled, or destroyed.
        They cannot be restored. The transaction log preserves the full history.
    </p>

    <table>
        <thead>
            <tr>
                <th>Unit</th>
                <th>Quantity</th>
                <th>Was Held By</th>
                <th>Disposed</th>
                <th>Transaction</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($disposedUnits as $d): ?>
                <tr>
                    <td class="text-muted" style="font-size:12px;">Asset #<?= (int)$d['asset_id'] ?></td>
                    <td class="text-muted fw-600"><?= (int)$d['quantity'] ?></td>
                    <td>
                        <?php if ($d['full_name']): ?>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <div style="width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;background:<?= htmlspecialchars($d['avatar_color'] ?: '#888') ?>;">
                                    <?= htmlspecialchars($d['avatar'] ?: strtoupper(substr($d['full_name'], 0, 2))) ?>
                                </div>
                                <span class="text-bright"><?= htmlspecialchars($d['full_name']) ?></span>
                            </div>
                        <?php else: ?>
                            <span class="text-muted" style="font-size:12px;">Warehouse</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted" style="font-size:12px;">
                        <?= htmlspecialchars($d['disposed_at'] ?? $d['assigned_at']) ?>
                    </td>
                    <td class="text-muted" style="font-size:12px;">
                        <?= htmlspecialchars($d['txn_code'] ?? '—') ?>
                    </td>
                    <td class="text-muted" style="font-size:12px;">
                        <?= htmlspecialchars($d['remarks'] ?? '—') ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Book value chart + lifecycle -->
<div class="chart-grid">
    <div class="chart-card">
        <div class="chart-header"><h3>Book Value Over Time</h3></div>
        <?php if ($bookValueSeries): ?>
            <div class="chart-placeholder">
                <?= render_line_chart(
                    [['name' => 'Book Value', 'color' => '#4ec9b0', 'values' => $bookValueSeries]],
                    $bookValueLabels,
                    ['width' => 600, 'height' => 200]
                ) ?>
            </div>
        <?php elseif ($product['product_type'] === 'software'): ?>
            <p class="text-muted" style="padding:20px;">Software is not depreciated — it's a license with an expiry date.</p>
        <?php else: ?>
            <p class="text-muted" style="padding:20px;">No purchase date set.</p>
        <?php endif; ?>
    </div>

    <div class="chart-card">
        <div class="chart-header"><h3>Lifecycle</h3></div>
        <ol class="timeline">
            <?php foreach ($lifecycle as $ev): ?>
                <li class="timeline-item">
                    <span class="timeline-dot" style="background:<?= $ev['color'] ?>;">
                        <i class="fas <?= $ev['icon'] ?>"></i>
                    </span>
                    <div class="timeline-content">
                        <div class="timeline-label"><?= htmlspecialchars($ev['label']) ?></div>
                        <div class="timeline-meta"><?= htmlspecialchars($ev['date']) ?></div>
                        <?php if ($ev['note']): ?>
                            <div class="timeline-note"><?= htmlspecialchars($ev['note']) ?></div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
            <?php if (!$lifecycle): ?>
                <li class="text-muted" style="list-style:none;">No lifecycle events yet.</li>
            <?php endif; ?>
        </ol>
    </div>
</div>

<!-- License card -->
<?php if ($product['product_type'] === 'software'): ?>
<div class="card">
    <h3><i class="fas fa-key"></i> License</h3>
    <table>
        <tbody>
            <tr><th style="width:180px;">Vendor</th><td><?= $product['license_vendor'] ? htmlspecialchars($product['license_vendor']) : '<span class="text-muted">—</span>' ?></td></tr>
            <tr><th>License Key</th><td><code><?= $product['license_key'] ? htmlspecialchars($product['license_key']) : '—' ?></code></td></tr>
            <tr><th>Seats</th><td><?= $product['license_seats'] ? (int)$product['license_seats'] : '<span class="text-muted">—</span>' ?></td></tr>
            <tr>
                <th>Expires</th>
                <td>
                    <?php if ($product['license_expires_at']):
                        $days  = (int)((strtotime($product['license_expires_at']) - time()) / 86400);
                        $badge = $days < 0 ? 'danger' : ($days < 30 ? 'warning' : 'success');
                        $label = $days < 0 ? 'expired ' . abs($days) . ' days ago'
                               : ($days < 30 ? "expires in {$days} days" : "in " . round($days / 30) . " months");
                    ?>
                        <span class="badge <?= $badge ?>"><?= htmlspecialchars($product['license_expires_at']) ?></span>
                        <span class="text-muted" style="font-size:12px;margin-left:8px;"><?= $label ?></span>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Client & Location -->
<div class="card">
    <h3><i class="fas fa-building"></i> Client &amp; Location</h3>
    <table>
        <tbody>
            <tr>
                <th style="width:180px;">Ownership</th>
                <td>
                    <?php if ($product['ownership'] === 'client'): ?>
                        <span class="badge warning">Client-supplied</span>
                    <?php else: ?>
                        <span class="badge success">Company-owned</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Client</th>
                <td>
                    <?php if ($product['client_name']): ?>
                        <strong class="text-bright"><?= htmlspecialchars($product['client_name']) ?></strong>
                        <?php if ($product['client_contact']): ?>
                            <div class="text-muted" style="font-size:12px;">
                                <?= htmlspecialchars($product['client_contact']) ?>
                                <?php if ($product['client_email']): ?> · <?= htmlspecialchars($product['client_email']) ?><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr><th>Location</th><td><?= $product['location'] ? htmlspecialchars($product['location']) : '<span class="text-muted">—</span>' ?></td></tr>
            <tr><th>Tools Included</th><td><?= $product['tools_included'] ? htmlspecialchars($product['tools_included']) : '<span class="text-muted">—</span>' ?></td></tr>
        </tbody>
    </table>
</div>

<!-- Adjust form -->
<?php if ($product['status'] !== 'disposed'): ?>
<div class="card">
    <h3>Adjust Pricing &amp; Lifecycle</h3>
    <form method="POST">
        <input type="hidden" name="adjust" value="1">

        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;">
            <div class="form-group">
                <label>Reorder Level</label>
                <div class="input-wrap">
                    <i class="fas fa-exclamation-triangle icon"></i>
                    <input type="number" name="reorder_level" min="0" required value="<?= (int)$product['reorder_level'] ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Unit Cost (₱)</label>
                <div class="input-wrap">
                    <i class="fas fa-peso-sign icon"></i>
                    <input type="number" name="unit_cost" step="0.01" min="0" required value="<?= htmlspecialchars($product['unit_cost']) ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Unit Price (₱)</label>
                <div class="input-wrap">
                    <i class="fas fa-tag icon"></i>
                    <input type="number" name="unit_price" step="0.01" min="0" required value="<?= htmlspecialchars($product['unit_price']) ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Lifecycle Stage</label>
                <div class="input-wrap">
                    <i class="fas fa-stream icon"></i>
                    <select name="lifecycle_stage" style="padding-left:40px;">
                        <?php
                        $stages = ['acquired' => 'Acquired', 'in_use' => 'In Use', 'in_repair' => 'In Repair', 'retired' => 'Retired'];
                        foreach ($stages as $k => $label):
                        ?>
                            <option value="<?= $k ?>" <?= $product['lifecycle_stage'] === $k ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php if ($product['product_type'] !== 'software'): ?>
            <div class="form-group">
                <label>Purchase Date</label>
                <div class="input-wrap">
                    <i class="fas fa-calendar icon"></i>
                    <input type="date" name="purchase_date" value="<?= htmlspecialchars($product['purchase_date'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Useful Life (months)</label>
                <div class="input-wrap">
                    <i class="fas fa-hourglass-half icon"></i>
                    <input type="number" name="useful_life_months" min="1" value="<?= (int)($product['useful_life_months'] ?? 36) ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Salvage Value (₱)</label>
                <div class="input-wrap">
                    <i class="fas fa-coins icon"></i>
                    <input type="number" name="salvage_value" step="0.01" min="0" value="<?= htmlspecialchars($product['salvage_value'] ?? '0') ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Warranty Until</label>
                <div class="input-wrap">
                    <i class="fas fa-shield-alt icon"></i>
                    <input type="date" name="warranty_until" value="<?= htmlspecialchars($product['warranty_until'] ?? '') ?>">
                </div>
            </div>
            <?php endif; ?>

            <?php if ($product['product_type'] === 'software'): ?>
            <div class="form-group">
                <label>License Vendor</label>
                <div class="input-wrap">
                    <i class="fas fa-industry icon"></i>
                    <input type="text" name="license_vendor" value="<?= htmlspecialchars($product['license_vendor'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label>License Key</label>
                <div class="input-wrap">
                    <i class="fas fa-key icon"></i>
                    <input type="text" name="license_key" value="<?= htmlspecialchars($product['license_key'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Seats</label>
                <div class="input-wrap">
                    <i class="fas fa-users icon"></i>
                    <input type="number" name="license_seats" min="0" value="<?= (int)($product['license_seats'] ?? 0) ?>">
                </div>
            </div>
            <div class="form-group">
                <label>License Expires</label>
                <div class="input-wrap">
                    <i class="fas fa-calendar-times icon"></i>
                    <input type="date" name="license_expires_at" value="<?= htmlspecialchars($product['license_expires_at'] ?? '') ?>">
                </div>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label>Client</label>
                <div class="input-wrap">
                    <i class="fas fa-building icon"></i>
                    <select name="client_id" style="padding-left:40px;">
                        <option value="">— None —</option>
                        <?php foreach ($allClients as $cl): ?>
                            <option value="<?= (int)$cl['id'] ?>" <?= (int)($product['client_id'] ?? 0) === (int)$cl['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cl['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Location</label>
                <div class="input-wrap">
                    <i class="fas fa-map-marker-alt icon"></i>
                    <input type="text" name="location" value="<?= htmlspecialchars($product['location'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Tools Included</label>
                <div class="input-wrap">
                    <i class="fas fa-toolbox icon"></i>
                    <input type="text" name="tools_included" value="<?= htmlspecialchars($product['tools_included'] ?? '') ?>">
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Save Changes
        </button>
    </form>
</div>
<?php endif; ?>

<!-- Transaction history -->
<div class="table-card">
    <div class="table-header">
        <h3>Transaction History</h3>
        <a href="transactions.php?product=<?= (int)$product['id'] ?>" class="btn btn-outline">
            <i class="fas fa-plus"></i> New Transaction
        </a>
    </div>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Qty</th>
                <th>Held By</th>
                <th>Unit Cost</th>
                <th>Unit Price</th>
                <th>Reference</th>
                <th>By</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$transactions): ?>
            <tr><td colspan="9" class="text-muted">No transactions recorded for this product yet.</td></tr>
        <?php else: foreach ($transactions as $t):
            $tc = match($t['type']) {
                'inbound'        => 'success',
                'outbound'       => 'info',
                'damaged'        => 'danger',
                'for_repair'     => 'warning',
                'for_replacement'=> 'purple',
                'disposed'       => 'muted',
                'returned'       => 'success',
                default          => 'muted',
            };
            $isAdd = in_array($t['type'], ['inbound','returned'], true);
        ?>
            <tr>
                <td class="text-muted" style="font-size:12px;"><?= htmlspecialchars($t['created_at']) ?></td>
                <td><span class="badge <?= $tc ?>"><?= ucfirst(str_replace('_',' ',$t['type'])) ?></span></td>
                <td class="fw-600 <?= $isAdd ? 'text-green' : 'text-red' ?>"><?= $isAdd ? '+' : '−' ?><?= (int)$t['quantity'] ?></td>
                <td class="text-muted" style="font-size:12px;">
                    <?= $t['from_user_name'] ? htmlspecialchars($t['from_user_name']) : '—' ?>
                </td>
                <td class="text-muted">₱<?= number_format((float)$t['unit_cost'], 2) ?></td>
                <td class="text-muted">₱<?= number_format((float)$t['unit_price'], 2) ?></td>
                <td class="text-muted" style="font-size:12px;"><?= htmlspecialchars($t['reference_no'] ?? '—') ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($t['user_name'] ?? '—') ?></td>
                <td class="text-muted" style="font-size:12px;"><?= htmlspecialchars($t['remarks'] ?? '—') ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<!-- Danger Zone -->
<?php if ($isSuperadmin): ?>
<div class="card" style="border-color:rgba(244,71,71,0.3);">
    <h3 style="color:var(--red);"><i class="fas fa-exclamation-triangle"></i> Danger Zone</h3>

    <?php if ($product['status'] === 'disposed'): ?>
        <div class="banner muted" style="background:rgba(139,148,158,0.1);border:1px solid var(--border);color:var(--text-muted);margin:0;">
            <i class="fas fa-archive"></i>
            <span>This product is already disposed. Disposed products cannot be hard-deleted — the audit trail is preserved.</span>
        </div>

    <?php elseif ($txnRefCount > 0 || $assetRefCount > 0): ?>

        <p class="text-muted mb-16" style="font-size:13px;">
            This product has transaction history and <strong>cannot be deleted</strong> without breaking
            the audit trail. To remove it from active inventory, dispose all remaining units.
            The history is preserved but the product will no longer appear in Active Inventory.
        </p>

        <?php if ($heldByUsers > 0): ?>
            <div class="banner danger" style="margin:0;">
                <i class="fas fa-user-lock"></i>
                <div>
                    <strong>Cannot dispose.</strong>
                    <?= $heldByUsers ?> unit<?= $heldByUsers === 1 ? '' : 's' ?> are currently held by users.
                    Log <strong>returned</strong> transactions first.
                </div>
            </div>

        <?php elseif ((int)$product['stock'] <= 0): ?>
            <div class="banner muted" style="background:rgba(139,148,158,0.1);border:1px solid var(--border);color:var(--text-muted);margin:0;">
                <i class="fas fa-info-circle"></i>
                <span>No stock remaining. This product is inactive but not yet disposed.</span>
            </div>

        <?php else: ?>
            <form method="POST" action="dispose-product.php?id=<?= (int)$product['id'] ?>"
                  onsubmit="return confirm('Dispose all <?= (int)$product['stock'] ?> remaining unit(s) of this product?\n\nThis will:\n- Remove all stock from inventory\n- Mark the product as Disposed\n- Preserve the full transaction history\n\nThis cannot be undone.');">
                <button type="submit" class="btn" style="background:var(--red);color:#fff;">
                    <i class="fas fa-archive"></i>
                    Dispose <?= (int)$product['stock'] ?> Remaining Unit<?= (int)$product['stock'] === 1 ? '' : 's' ?>
                </button>
                <p class="text-muted" style="font-size:11px;margin-top:10px;">
                    Removes all remaining stock from inventory. Audit trail preserved.
                </p>
            </form>
        <?php endif; ?>

    <?php else: ?>
        <p class="text-muted mb-16" style="font-size:13px;">
            This product has no transactions or assignments. It can be permanently deleted.
        </p>

        <a href="product-delete.php?id=<?= (int)$product['id'] ?>" class="btn"
           style="background:var(--red);color:#fff;"
           onclick="return confirm('Permanently delete this product? This cannot be undone.');">
            <i class="fas fa-trash-alt"></i> Delete this product
        </a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include 'footer.php'; ?>
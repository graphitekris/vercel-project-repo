<?php
require 'config.php';
require 'auth.php';

$user = require_login();
$isPrivileged = in_array($user['role'], ['admin', 'superadmin'], true);

$pageTitle = $isPrivileged ? 'Transactions' : 'My Transactions';
$activeNav = 'transactions';

$flash = '';
$flashType = 'success';
$errors = [];

$action    = $_GET['action'] ?? 'list';
$productId = (int)($_GET['product'] ?? 0);

/* ============================================================
   AJAX — current holdings per user for a product
   ============================================================ */
if (($_GET['ajax'] ?? '') === 'holdings') {
    header('Content-Type: application/json');

    $pId = (int)($_GET['product'] ?? 0);
    if ($pId <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Invalid product.']);
        exit;
    }

    try {
        $stmt = db()->prepare('
            SELECT a.assigned_to AS user_id,
                   COALESCE(SUM(a.quantity), 0) AS held
            FROM assets a
            WHERE a.product_id = ? AND a.status = "active"
            GROUP BY a.assigned_to
        ');
        $stmt->execute([$pId]);

        $holdings = [];
        foreach ($stmt->fetchAll() as $row) {
            $holdings[(int)$row['user_id']] = (int)$row['held'];
        }

        echo json_encode(['ok' => true, 'holdings' => $holdings]);
    } catch (Throwable $e) {
        error_log('Holdings lookup failed: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Lookup failed.']);
    }
    exit;
}

/* ============================================================
   CREATE TRANSACTION
   ============================================================ */
if ($action === 'new' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$isPrivileged) {
        http_response_code(403);
        exit('Access denied.');
    }

    $pId        = (int)($_POST['product_id'] ?? 0);
    $type       = $_POST['type'] ?? '';
    $quantity   = (int)($_POST['quantity'] ?? 0);
    $reference  = trim($_POST['reference_no'] ?? '');
    $remarks    = trim($_POST['remarks'] ?? '');
    $unitSource = $_POST['unit_source'] ?? '';
    $fromUserId = (int)($_POST['from_user_id'] ?? 0) ?: null;

    if ($unitSource === 'warehouse') {
        $fromUserId = null;
    }

    $allocations = [];
    $rawUserIds  = $_POST['alloc_user'] ?? [];
    $rawQtys     = $_POST['alloc_qty']  ?? [];
    if (is_array($rawUserIds) && is_array($rawQtys)) {
        foreach ($rawUserIds as $i => $uid) {
            $uid = (int)$uid;
            $qty = (int)($rawQtys[$i] ?? 0);
            if ($uid > 0 && $qty > 0) {
                $allocations[] = ['user_id' => $uid, 'qty' => $qty];
            }
        }
    }

    // Disposed is handled on the product page, not here.
    $validTypes      = ['inbound','outbound','damaged','for_repair','for_replacement','returned'];
    $allocationTypes = ['outbound','returned'];
    $heldByTypes     = ['damaged','for_repair','for_replacement'];

    if ($pId <= 0)                            $errors[] = 'Please select a product.';
    if (!in_array($type, $validTypes, true))  $errors[] = 'Please select a valid transaction type.';
    if ($quantity <= 0)                       $errors[] = 'Quantity must be at least 1.';

    $sourceApplies = in_array($type, $heldByTypes, true);

    if ($sourceApplies) {
        if (!in_array($unitSource, ['warehouse', 'user'], true)) {
            $errors[] = 'Please specify the source of the unit(s).';
        }
        if ($unitSource === 'user' && $fromUserId === null) {
            $errors[] = 'Please select which user is holding the unit(s).';
        }
    } else {
        $unitSource = '';
        $fromUserId = null;
    }

    if ($fromUserId !== null) {
        $stmt = db()->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$fromUserId]);
        if (!$stmt->fetch()) {
            $errors[] = 'The selected holder does not exist.';
        }
    }

    if (in_array($type, $allocationTypes, true)) {
        if ($type === 'returned' && empty($allocations)) {
            $errors[] = 'Returned transactions must specify which user(s) are returning the units.';
        }

        if (!empty($allocations)) {
            $sumAlloc = array_sum(array_column($allocations, 'qty'));

            if ($sumAlloc > $quantity) {
                $errors[] = "User allocations ({$sumAlloc}) cannot exceed the transaction quantity ({$quantity}).";
            }
            if ($type === 'outbound' && $sumAlloc > 0 && $sumAlloc !== $quantity) {
                $errors[] = "Outbound allocations must total exactly {$quantity}. Currently: {$sumAlloc}.";
            }
            if ($type === 'returned' && $sumAlloc !== $quantity) {
                $errors[] = "Returned allocations must total exactly {$quantity}. Currently: {$sumAlloc}.";
            }

            $seen = [];
            foreach ($allocations as $a) {
                if (isset($seen[$a['user_id']])) {
                    $errors[] = 'The same user appears in more than one allocation row. Combine them.';
                    break;
                }
                $seen[$a['user_id']] = true;
            }
        }
    }

    if (!$errors) {
        try {
            db()->beginTransaction();

            $stmt = db()->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([$pId]);
            $product = $stmt->fetch();

            if (!$product) throw new RuntimeException('Product not found.');
            if ($product['status'] === 'disposed' && !in_array($type, ['inbound','returned'], true)) {
                throw new RuntimeException('Disposed products can only receive "returned" or "inbound" transactions.');
            }

            $unitCost  = (float)$product['unit_cost'];
            $unitPrice = (float)$product['unit_price'];

            $isUserHeld = ($unitSource === 'user' && $fromUserId !== null);

            if (in_array($type, ['inbound','returned'], true)) {
                $delta = $quantity;
            } elseif ($isUserHeld) {
                $delta = 0;
            } else {
                $delta = -$quantity;
            }

            $newStock = (int)$product['stock'] + $delta;

            if ($newStock < 0) {
                throw new RuntimeException("Not enough stock. Available: {$product['stock']}, requested: {$quantity}.");
            }

            if ($type === 'returned' && !empty($allocations)) {
                foreach ($allocations as $a) {
                    $uid = (int)$a['user_id'];
                    $qty = (int)$a['qty'];

                    $stmt = db()->prepare('
                        SELECT COALESCE(SUM(quantity), 0)
                        FROM assets
                        WHERE product_id = ? AND assigned_to = ? AND status = "active"
                    ');
                    $stmt->execute([$pId, $uid]);
                    $held = (int)$stmt->fetchColumn();

                    if ($held < $qty) {
                        $uStmt = db()->prepare('SELECT full_name FROM users WHERE id = ? LIMIT 1');
                        $uStmt->execute([$uid]);
                        $name = $uStmt->fetchColumn() ?: "user #{$uid}";
                        throw new RuntimeException(
                            "Cannot return {$qty} unit(s) — {$name} only holds {$held} of this product."
                        );
                    }
                }
            }

            if ($isUserHeld) {
                $stmt = db()->prepare('
                    SELECT COALESCE(SUM(quantity), 0)
                    FROM assets
                    WHERE product_id = ? AND assigned_to = ? AND status = "active"
                ');
                $stmt->execute([$pId, $fromUserId]);
                $held = (int)$stmt->fetchColumn();

                if ($held < $quantity) {
                    $uStmt = db()->prepare('SELECT full_name FROM users WHERE id = ? LIMIT 1');
                    $uStmt->execute([$fromUserId]);
                    $name = $uStmt->fetchColumn() ?: "user #{$fromUserId}";
                    throw new RuntimeException(
                        "Cannot log {$quantity} unit(s) as {$type} — {$name} only holds {$held}."
                    );
                }
            }

            $stmt = db()->prepare('
                SELECT
                    COALESCE(SUM(CASE WHEN status = "active"  THEN quantity ELSE 0 END), 0) AS active_held,
                    COALESCE(SUM(CASE WHEN status = "damaged" THEN quantity ELSE 0 END), 0) AS damaged_held,
                    COALESCE(SUM(CASE WHEN status = "disposed" THEN quantity ELSE 0 END), 0) AS disposed_held
                FROM assets
                WHERE product_id = ?
            ');
            $stmt->execute([$pId]);
            $remaining = $stmt->fetch();

            $activeHeld   = (int)$remaining['active_held'];
            $damagedHeld  = (int)$remaining['damaged_held'];
            $disposedHeld = (int)$remaining['disposed_held'];

            $futureDamaged  = $damagedHeld;
            $futureDisposed = $disposedHeld;

            if ($isUserHeld) {
                $futureDamaged += $quantity;
            }

            $totalRemaining = $newStock + $activeHeld + $futureDamaged + $futureDisposed;

            if ($totalRemaining <= 0) {
                $newStatus = in_array($type, ['damaged','for_repair','for_replacement'], true)
                    ? $type
                    : 'out_of_stock';
            } elseif ($newStock <= 0 && $activeHeld <= 0 && $futureDamaged > 0 && $futureDisposed === 0) {
                $newStatus = 'damaged';
            } else {
                if ($newStock <= 0)                                 $newStatus = 'out_of_stock';
                elseif ($newStock <= (int)$product['reorder_level']) $newStatus = 'low_stock';
                else                                                $newStatus = 'in_stock';
            }

            $code = 'TXN-' . str_pad(
                (string)(((int)db()->query('SELECT COALESCE(MAX(id), 0) FROM transactions')->fetchColumn()) + 1),
                5, '0', STR_PAD_LEFT
            );

            db()->prepare('
                INSERT INTO transactions
                    (txn_code, type, product_id, quantity, unit_cost, unit_price,
                     reference_no, remarks, created_by, from_user_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([
                $code, $type, $pId, $quantity, $unitCost, $unitPrice,
                $reference ?: null, $remarks ?: null, $user['id'], $fromUserId,
            ]);
            $txnId = (int)db()->lastInsertId();

            db()->prepare('UPDATE products SET stock = ?, status = ? WHERE id = ?')
                ->execute([$newStock, $newStatus, $pId]);

            if ($isUserHeld) {
                $assetStatus = 'damaged';
                $remaining   = $quantity;

                $stmt = db()->prepare('
                    SELECT id, quantity FROM assets
                    WHERE product_id = ? AND assigned_to = ? AND status = "active"
                    ORDER BY id ASC
                ');
                $stmt->execute([$pId, $fromUserId]);

                foreach ($stmt->fetchAll() as $row) {
                    if ($remaining <= 0) break;

                    $take = min($remaining, (int)$row['quantity']);
                    $remaining -= $take;

                    if ($take === (int)$row['quantity']) {
                        db()->prepare('UPDATE assets SET status = ?, transaction_id = ? WHERE id = ?')
                            ->execute([$assetStatus, $txnId, $row['id']]);
                    } else {
                        db()->prepare('UPDATE assets SET quantity = quantity - ? WHERE id = ?')
                            ->execute([$take, $row['id']]);

                        db()->prepare('
                            INSERT INTO assets (product_id, assigned_to, assigned_at, status, quantity, transaction_id, remarks)
                            VALUES (?, ?, CURDATE(), ?, ?, ?, ?)
                        ')->execute([$pId, $fromUserId, $assetStatus, $take, $txnId, $remarks ?: null]);
                    }
                }
            }

            if (!empty($allocations) && in_array($type, $allocationTypes, true)) {
                foreach ($allocations as $a) {
                    $uid = (int)$a['user_id'];
                    $qty = (int)$a['qty'];

                    if ($type === 'outbound') {
                        db()->prepare('
                            INSERT INTO assets (product_id, assigned_to, assigned_at, status, quantity, transaction_id, remarks)
                            VALUES (?, ?, CURDATE(), "active", ?, ?, ?)
                        ')->execute([$pId, $uid, $qty, $txnId, $remarks ?: null]);

                    } else {
                        $remaining = $qty;

                        $stmt = db()->prepare('
                            SELECT id, quantity FROM assets
                            WHERE product_id = ? AND assigned_to = ? AND status = "active"
                            ORDER BY id ASC
                        ');
                        $stmt->execute([$pId, $uid]);

                        foreach ($stmt->fetchAll() as $row) {
                            if ($remaining <= 0) break;

                            $take = min($remaining, (int)$row['quantity']);
                            $remaining -= $take;

                            if ($take === (int)$row['quantity']) {
                                db()->prepare('UPDATE assets SET status = "returned", transaction_id = ? WHERE id = ?')
                                    ->execute([$txnId, $row['id']]);
                            } else {
                                db()->prepare('UPDATE assets SET quantity = quantity - ? WHERE id = ?')
                                    ->execute([$take, $row['id']]);

                                db()->prepare('
                                    INSERT INTO assets (product_id, assigned_to, assigned_at, status, quantity, transaction_id, remarks)
                                    VALUES (?, ?, CURDATE(), "returned", ?, ?, ?)
                                ')->execute([$pId, $uid, $take, $txnId, $remarks ?: null]);
                            }
                        }
                    }
                }
            }

            db()->commit();

            $allocMsg = '';
            if (!empty($allocations)) {
                $allocMsg = ' — allocated to ' . count($allocations) . ' user(s)';
            }
            $fromMsg = '';
            if ($isUserHeld) {
                $uStmt = db()->prepare('SELECT full_name FROM users WHERE id = ? LIMIT 1');
                $uStmt->execute([$fromUserId]);
                $uname = $uStmt->fetchColumn() ?: 'unknown';
                $fromMsg = " — held by {$uname}";
            } elseif ($sourceApplies && $unitSource === 'warehouse') {
                $fromMsg = ' — from warehouse';
            }

            log_action($user['id'], $user['username'], $user['role'],
                "Logged {$type} {$quantity} × {$product['sku']} ({$code}){$fromMsg}{$allocMsg}",
                'transactions', 'success');

            header('Location: transactions.php?flash=created');
            exit;

        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            error_log('Transaction failed: ' . $e->getMessage());
            $errors[] = $e->getMessage();
        }
    }
}

if (isset($_GET['flash']) && $_GET['flash'] === 'created') {
    $flash = 'Transaction recorded. Stock and status updated.';
}

function recalculateStatus(string $lastType, int $stock, int $reorderLevel): string {
    if (in_array($lastType, ['damaged','for_repair','for_replacement'], true)) {
        if ($stock > 0) {
            return $stock <= $reorderLevel ? 'low_stock' : 'in_stock';
        }
        return $lastType;
    }
    if ($stock <= 0)             return 'out_of_stock';
    if ($stock <= $reorderLevel) return 'low_stock';
    return 'in_stock';
}

/* ============================================================
   LOAD DATA
   ============================================================ */
$products = [];
if ($isPrivileged) {
    $products = db()->query('
        SELECT id, sku, name, stock, unit_cost, unit_price, status
        FROM products
        WHERE status != "disposed"
        ORDER BY name
    ')->fetchAll();
}

$allUsers = db()->query('
    SELECT id, full_name, role FROM users
    WHERE status = "active"
    ORDER BY full_name
')->fetchAll();

$preloadedHoldings = [];
$preloadedHolderCount = 0;
if ($productId > 0) {
    $stmt = db()->prepare('
        SELECT assigned_to AS user_id, COALESCE(SUM(quantity), 0) AS held
        FROM assets
        WHERE product_id = ? AND status = "active"
        GROUP BY assigned_to
    ');
    $stmt->execute([$productId]);
    foreach ($stmt->fetchAll() as $row) {
        $preloadedHoldings[(int)$row['user_id']] = (int)$row['held'];
    }
    $preloadedHolderCount = count($preloadedHoldings);
}

$filterType   = $_GET['type']   ?? '';
$filterDate   = $_GET['date']   ?? '';
$filterUser   = (int)($_GET['user'] ?? 0);

$where = []; $params = [];
if (!$isPrivileged) {
    $where[]  = 't.created_by = ?';
    $params[] = (int)$user['id'];
} elseif ($filterUser > 0) {
    $where[]  = 't.created_by = ?';
    $params[] = $filterUser;
}
if ($filterType !== '') {
    $where[]  = 't.type = ?';
    $params[] = $filterType;
}
if ($filterDate !== '') {
    $where[]  = 'DATE(t.created_at) = ?';
    $params[] = $filterDate;
}
if ($productId > 0) {
    $where[]  = 't.product_id = ?';
    $params[] = $productId;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = db()->prepare("
    SELECT t.*, p.sku, p.name AS product_name,
           u.full_name AS user_name,
           fu.full_name AS from_user_name
    FROM transactions t
    LEFT JOIN products p ON p.id = t.product_id
    LEFT JOIN users u    ON u.id = t.created_by
    LEFT JOIN users fu   ON fu.id = t.from_user_id
    {$whereSql}
    ORDER BY t.created_at DESC, t.id DESC
    LIMIT 500
");
$stmt->execute($params);
$transactions = $stmt->fetchAll();

$allocationsByTxn = [];
if (!empty($transactions)) {
    $txnIds = array_column($transactions, 'id');
    $placeholders = implode(',', array_fill(0, count($txnIds), '?'));

    $stmt = db()->prepare("
        SELECT a.transaction_id, a.quantity, a.status,
               u.id AS user_id, u.full_name
        FROM assets a
        JOIN users u ON u.id = a.assigned_to
        WHERE a.transaction_id IN ($placeholders)
        ORDER BY u.full_name
    ");
    $stmt->execute($txnIds);
    foreach ($stmt->fetchAll() as $row) {
        $allocationsByTxn[(int)$row['transaction_id']][] = $row;
    }
}

$users = [];
if ($isPrivileged) {
    $users = db()->query('SELECT id, full_name FROM users ORDER BY full_name')->fetchAll();
}

include 'header.php';
?>

<?php if ($flash): ?>
    <div class="banner success" data-autohide>
        <i class="fas fa-check-circle"></i>
        <span><?= htmlspecialchars($flash) ?></span>
    </div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="banner danger">
        <i class="fas fa-exclamation-circle"></i>
        <div>
            <strong>Please fix the following:</strong>
            <ul style="margin:6px 0 0 18px;">
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<?php if ($action === 'list'): ?>

<div class="banner info">
    <i class="fas fa-info-circle"></i>
    <span>
        <?php if ($isPrivileged): ?>
            Every stock movement is recorded as a <strong>transaction</strong>. Damage, repair,
            and replacement also record <strong>where the unit came from</strong> — warehouse or a user.
            <strong>Disposal</strong> is a separate action on the product page.
        <?php else: ?>
            <strong>Your transactions.</strong> These are the stock movements you've recorded.
        <?php endif; ?>
    </span>
</div>

<div class="flex-between mb-16">
    <div class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" placeholder="Search transactions..." data-filter-target="#txnTable" />
    </div>
    <div class="flex">
        <a href="transactions.php" class="btn btn-outline">Clear Filters</a>
        <?php if ($isPrivileged): ?>
            <a href="transactions.php?action=new<?= $productId > 0 ? '&product=' . $productId : '' ?>" class="btn btn-primary">
                <i class="fas fa-plus"></i> New Transaction
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <form method="GET" style="display:grid;grid-template-columns:repeat(<?= $isPrivileged ? 4 : 3 ?>,1fr);gap:12px;align-items:end;">
        <input type="hidden" name="product" value="<?= $productId > 0 ? (int)$productId : '' ?>">

        <div class="form-group" style="margin:0;">
            <label for="type">Type</label>
            <div class="input-wrap">
                <i class="fas fa-filter icon"></i>
                <select id="type" name="type" style="padding-left:40px;">
                    <option value="">All Types</option>
                    <?php foreach (['inbound','outbound','damaged','for_repair','for_replacement','disposed','returned'] as $t): ?>
                        <option value="<?= $t ?>" <?= $filterType === $t ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $t)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-group" style="margin:0;">
            <label for="date">Date</label>
            <div class="input-wrap">
                <i class="fas fa-calendar icon"></i>
                <input type="date" id="date" name="date" value="<?= htmlspecialchars($filterDate) ?>">
            </div>
        </div>

        <?php if ($isPrivileged): ?>
        <div class="form-group" style="margin:0;">
            <label for="user">User</label>
            <div class="input-wrap">
                <i class="fas fa-user icon"></i>
                <select id="user" name="user" style="padding-left:40px;">
                    <option value="">All Users</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= $filterUser === (int)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php endif; ?>

        <div>
            <button type="submit" class="btn btn-primary" style="width:100%;">
                <i class="fas fa-filter"></i> Apply Filters
            </button>
        </div>
    </form>
</div>

<div class="table-card">
    <table id="txnTable">
        <thead>
            <tr>
                <th>Code</th>
                <th>Date</th>
                <th>Type</th>
                <th>Product</th>
                <th>Qty</th>
                <th>Held By / Allocated To</th>
                <th>Unit Cost</th>
                <th>Reference</th>
                <th>By</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$transactions): ?>
            <tr><td colspan="10" class="text-muted">No transactions match the current filters.</td></tr>
        <?php else: foreach ($transactions as $t):
            $typeClass = match($t['type']) {
                'inbound'        => 'success',
                'outbound'       => 'info',
                'damaged'        => 'danger',
                'for_repair'     => 'warning',
                'for_replacement'=> 'purple',
                'disposed'       => 'muted',
                'returned'       => 'success',
                default          => 'muted',
            };
            $isAddition = in_array($t['type'], ['inbound','returned'], true);
            $alloc = $allocationsByTxn[(int)$t['id']] ?? [];
            $hasFrom = !empty($t['from_user_name']);
            $hasAlloc = !empty($alloc);
        ?>
            <tr>
                <td class="text-bright fw-600" style="font-size:12px;"><?= htmlspecialchars($t['txn_code']) ?></td>
                <td class="text-muted" style="font-size:12px;white-space:nowrap;"><?= htmlspecialchars($t['created_at']) ?></td>
                <td><span class="badge <?= $typeClass ?>"><?= ucfirst(str_replace('_', ' ', $t['type'])) ?></span></td>
                <td>
                    <?php if ($isPrivileged): ?>
                        <a href="product.php?id=<?= (int)$t['product_id'] ?>" style="color:var(--accent-hover);">
                            <?= htmlspecialchars($t['product_name'] ?? '—') ?>
                        </a>
                    <?php else: ?>
                        <?= htmlspecialchars($t['product_name'] ?? '—') ?>
                    <?php endif; ?>
                    <div class="text-muted" style="font-size:11px;"><?= htmlspecialchars($t['sku'] ?? '') ?></div>
                </td>
                <td class="fw-600 <?= $isAddition ? 'text-green' : 'text-red' ?>">
                    <?= $isAddition ? '+' : '−' ?><?= (int)$t['quantity'] ?>
                </td>
                <td style="font-size:12px;">
                    <?php if ($hasFrom): ?>
                        <div class="text-bright">
                            <i class="fas fa-user" style="font-size:10px;color:var(--text-muted);"></i>
                            <?= htmlspecialchars($t['from_user_name']) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($hasAlloc): ?>
                        <?php
                        $shown = array_slice($alloc, 0, 3);
                        $extra = count($alloc) - count($shown);
                        ?>
                        <?php foreach ($shown as $a): ?>
                            <div class="text-bright">
                                <i class="fas fa-share" style="font-size:10px;color:var(--text-muted);"></i>
                                <?= htmlspecialchars($a['full_name']) ?>
                                <span class="text-muted">×<?= (int)$a['quantity'] ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($extra > 0): ?>
                            <div class="text-muted">+<?= $extra ?> more</div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!$hasFrom && !$hasAlloc): ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="text-muted">₱<?= number_format((float)$t['unit_cost'], 2) ?></td>
                <td class="text-muted" style="font-size:12px;"><?= htmlspecialchars($t['reference_no'] ?? '—') ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($t['user_name'] ?? '—') ?></td>
                <td class="text-muted" style="font-size:12px;"><?= htmlspecialchars($t['remarks'] ?? '—') ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php elseif ($action === 'new'): ?>

<?php if (!$isPrivileged): header('Location: transactions.php'); exit; endif; ?>

<div class="card" style="max-width:860px;margin:0 auto;">
    <div class="flex-between mb-20">
        <h3 style="margin:0;">Record Transaction</h3>
        <a href="transactions.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
    </div>

    <div class="banner info">
        <i class="fas fa-info-circle"></i>
        <span>
            Saving updates the product's <strong>stock</strong> and recalculates its <strong>status</strong>.
            For damage, repair, and replacement, specify <strong>where the unit came from</strong> — the
            warehouse, or a user. <strong>Disposal</strong> is done from the product page.
        </span>
    </div>

    <form method="POST" action="transactions.php?action=new" id="txnForm">

        <div class="form-group">
            <label for="product_id">Product <span class="text-red">*</span></label>
            <div class="input-wrap">
                <i class="fas fa-cube icon"></i>
                <select id="product_id" name="product_id" required style="padding-left:40px;">
                    <option value="">— Select a product —</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"
                                data-stock="<?= (int)$p['stock'] ?>"
                            <?= ($productId === (int)$p['id'] || (int)($_POST['product_id'] ?? 0) === (int)$p['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['sku']) ?> — <?= htmlspecialchars($p['name']) ?>
                            (stock: <?= (int)$p['stock'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="form-group">
                <label for="type">Transaction Type <span class="text-red">*</span></label>
                <div class="input-wrap">
                    <i class="fas fa-tag icon"></i>
                    <select id="type" name="type" required style="padding-left:40px;" onchange="updateFieldVisibility()">
                        <option value="">— Select type —</option>
                        <option value="inbound"          <?= ($_POST['type'] ?? '') === 'inbound'          ? 'selected' : '' ?>>Inbound (+ stock)</option>
                        <option value="outbound"         <?= ($_POST['type'] ?? '') === 'outbound'         ? 'selected' : '' ?>>Outbound (− stock, allocate to users)</option>
                        <option value="damaged"          <?= ($_POST['type'] ?? '') === 'damaged'          ? 'selected' : '' ?>>Damaged (− stock)</option>
                        <option value="for_repair"       <?= ($_POST['type'] ?? '') === 'for_repair'       ? 'selected' : '' ?>>For Repair (− stock)</option>
                        <option value="for_replacement"  <?= ($_POST['type'] ?? '') === 'for_replacement'  ? 'selected' : '' ?>>For Replacement (− stock)</option>
                        <option value="returned"         <?= ($_POST['type'] ?? '') === 'returned'         ? 'selected' : '' ?>>Returned (+ stock, from users)</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="quantity">Quantity <span class="text-red">*</span></label>
                <div class="input-wrap">
                    <i class="fas fa-sort-numeric-up icon"></i>
                    <input type="number" id="quantity" name="quantity" min="1" required
                           value="<?= (int)($_POST['quantity'] ?? 1) ?>"
                           oninput="updateAllocationTotal()">
                </div>
            </div>
        </div>

        <div id="heldBySection" style="display:none;margin-top:8px;padding:16px;background:var(--bg-alt);border:1px solid var(--border);border-radius:var(--radius);">

            <div style="font-weight:600;color:var(--text-bright);font-size:13px;margin-bottom:12px;">
                <i class="fas fa-box"></i> Where is this unit coming from? <span class="text-red">*</span>
            </div>

            <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
                <label class="type-card" style="flex:1;min-width:220px;display:flex;gap:12px;padding:12px 16px;border:1px solid var(--border);border-radius:var(--radius);cursor:pointer;background:transparent;">
                    <input type="radio" name="unit_source" value="warehouse"
                           style="accent-color:var(--accent);margin-top:4px;"
                           onchange="onSourceChange()">
                    <div>
                        <div style="font-weight:600;color:var(--text-bright);font-size:13px;">
                            <i class="fas fa-warehouse"></i> Warehouse stock
                        </div>
                        <div class="text-muted" style="font-size:11px;margin-top:2px;">
                            The unit is currently in the warehouse, not with anyone.
                        </div>
                    </div>
                </label>

                <label class="type-card" style="flex:1;min-width:220px;display:flex;gap:12px;padding:12px 16px;border:1px solid var(--border);border-radius:var(--radius);cursor:pointer;background:transparent;">
                    <input type="radio" name="unit_source" value="user"
                           style="accent-color:var(--accent);margin-top:4px;"
                           onchange="onSourceChange()">
                    <div>
                        <div style="font-weight:600;color:var(--text-bright);font-size:13px;">
                            <i class="fas fa-user"></i> Held by a user
                        </div>
                        <div class="text-muted" style="font-size:11px;margin-top:2px;">
                            The unit is with an employee. Specify who below.
                        </div>
                    </div>
                </label>
            </div>

            <div id="fromUserWrap" style="display:none;">
                <label style="display:block;font-size:12px;font-weight:600;margin-bottom:6px;color:var(--text);">
                    Held By User <span class="text-red">*</span>
                </label>
                <div class="input-wrap">
                    <i class="fas fa-user icon"></i>
                    <select id="from_user_id" name="from_user_id" style="padding-left:40px;">
                        <option value="">— Select user —</option>
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"
                                <?= (int)($_POST['from_user_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($u['role']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div id="allocationSection" style="display:none;margin-top:8px;padding:16px;background:var(--bg-alt);border:1px solid var(--border);border-radius:var(--radius);">

            <div class="flex-between mb-12">
                <div>
                    <div style="font-weight:600;color:var(--text-bright);font-size:13px;">
                        <i class="fas fa-users"></i> Distribute to Users
                    </div>
                    <div class="text-muted" style="font-size:11px;margin-top:2px;" id="allocHint"></div>
                </div>
                <button type="button" class="btn btn-outline" style="padding:4px 10px;font-size:12px;" onclick="addAllocationRow()">
                    <i class="fas fa-plus"></i> Add User
                </button>
            </div>

            <div id="allocationRows"></div>

            <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--border);display:flex;justify-content:space-between;font-size:13px;">
                <span class="text-muted">Allocated:</span>
                <strong id="allocTotal" class="text-bright">0 of 1</strong>
            </div>

            <div id="allocError" style="display:none;margin-top:8px;color:var(--red);font-size:12px;"></div>
        </div>

        <div class="form-group" style="margin-top:16px;">
            <label for="reference_no">Reference Number</label>
            <div class="input-wrap">
                <i class="fas fa-hashtag icon"></i>
                <input type="text" id="reference_no" name="reference_no"
                       placeholder="e.g. PO-2026-441, IS-2026-889"
                       value="<?= htmlspecialchars($_POST['reference_no'] ?? '') ?>">
            </div>
        </div>

        <div class="form-group">
            <label for="remarks">Remarks</label>
            <div class="input-wrap">
                <i class="fas fa-comment icon"></i>
                <input type="text" id="remarks" name="remarks"
                       placeholder="Optional notes"
                       value="<?= htmlspecialchars($_POST['remarks'] ?? '') ?>">
            </div>
        </div>

        <div class="flex mt-12" style="gap:10px;">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Record Transaction
            </button>
            <a href="transactions.php" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>

<script>
    window.CURRENT_HOLDINGS = <?= json_encode($preloadedHoldings) ?>;
    window.PRELOADED_HOLDER_COUNT = <?= (int)$preloadedHolderCount ?>;
</script>

<template id="allocRowTemplate">
    <div class="alloc-row" style="display:grid;grid-template-columns:1fr auto 100px 40px;gap:8px;margin-bottom:8px;align-items:center;">
        <select name="alloc_user[]" class="alloc-user"
                style="padding:9px 12px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);color:var(--text-bright);font-size:13px;outline:none;"
                onchange="onUserChange(this)">
            <option value="">— Select user —</option>
            <?php foreach ($allUsers as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($u['role']) ?>)</option>
            <?php endforeach; ?>
        </select>
        <span class="alloc-holds" style="font-size:11px;color:var(--text-muted);white-space:nowrap;min-width:60px;text-align:right;"></span>
        <input type="number" name="alloc_qty[]" class="alloc-qty" min="1" value="1"
               style="padding:9px 12px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);color:var(--text-bright);font-size:13px;outline:none;"
               oninput="updateAllocationTotal()">
        <button type="button" class="btn btn-ghost" style="color:var(--red);padding:4px 8px;" onclick="removeAllocRow(this)">
            <i class="fas fa-times"></i>
        </button>
    </div>
</template>

<script>
var holdingsCache = window.CURRENT_HOLDINGS || {};
var holderCount   = window.PRELOADED_HOLDER_COUNT || 0;

function updateFieldVisibility() {
    var type = document.getElementById('type').value;
    var heldBySection = document.getElementById('heldBySection');
    var allocSection  = document.getElementById('allocationSection');
    var allocHint     = document.getElementById('allocHint');

    var heldByTypes = ['damaged','for_repair','for_replacement'];
    var allocTypes  = ['outbound','returned'];

    if (heldByTypes.includes(type)) {
        heldBySection.style.display = 'block';

        var wh = document.querySelector('input[name="unit_source"][value="warehouse"]');
        var us = document.querySelector('input[name="unit_source"][value="user"]');

        if (wh) wh.disabled = false;
        if (us) us.disabled = (holderCount <= 0);

        if (us && us.disabled && us.checked) {
            if (wh) wh.checked = true;
            onSourceChange();
        }
    } else {
        heldBySection.style.display = 'none';
        document.querySelectorAll('input[name="unit_source"]').forEach(function (r) {
            r.checked = false;
            r.disabled = false;
        });
    }

    if (allocTypes.includes(type)) {
        allocSection.style.display = 'block';
        if (type === 'outbound') {
            allocHint.textContent = 'Assign to users (required if units went to a person). Leave empty for transfers, sales, or removals.';
        } else {
            allocHint.textContent = 'Required. Each user can only return what they currently hold.';
        }
    } else {
        allocSection.style.display = 'none';
    }

    paintSourceCards();

    refreshHoldings();
    updateAllocationTotal();
}

function onSourceChange() {
    var source = document.querySelector('input[name="unit_source"]:checked');
    var fromUserWrap = document.getElementById('fromUserWrap');
    var fromUser = document.getElementById('from_user_id');

    if (!source) return;

    if (source.value === 'user') {
        fromUserWrap.style.display = 'block';
        fromUser.setAttribute('required', 'required');
    } else {
        fromUserWrap.style.display = 'none';
        fromUser.removeAttribute('required');
        fromUser.value = '';
    }

    paintSourceCards();
}

function paintSourceCards() {
    document.querySelectorAll('input[name="unit_source"]').forEach(function (r) {
        var card = r.closest('label');
        if (!card) return;
        if (r.checked) {
            card.style.borderColor = 'var(--accent)';
            card.style.background  = 'var(--accent-soft)';
        } else {
            card.style.borderColor = 'var(--border)';
            card.style.background  = 'transparent';
        }
    });
}

function refreshHoldings() {
    var type = document.getElementById('type').value;
    var pid  = document.getElementById('product_id').value;

    if (type !== 'returned' || !pid) {
        holdingsCache = {};
        applyHoldingsToRows();
        return;
    }

    fetch('transactions.php?ajax=holdings&product=' + encodeURIComponent(pid))
        .then(function (r) { return r.json(); })
        .then(function (data) {
            holdingsCache = (data.ok && data.holdings) ? data.holdings : {};
            applyHoldingsToRows();
        })
        .catch(function () {
            holdingsCache = {};
            applyHoldingsToRows();
        });
}

function applyHoldingsToRows() {
    var type = document.getElementById('type').value;
    var rows = document.querySelectorAll('.alloc-row');

    rows.forEach(function (row) {
        var select   = row.querySelector('.alloc-user');
        var qtyInput = row.querySelector('.alloc-qty');
        var hint     = row.querySelector('.alloc-holds');
        var uid      = select.value;

        if (type !== 'returned' || !uid) {
            hint.textContent = '';
            qtyInput.removeAttribute('max');
            return;
        }

        var held = holdingsCache[uid] ?? 0;
        hint.textContent = 'holds ' + held;
        hint.style.color = held === 0 ? 'var(--red)' : 'var(--text-muted)';

        qtyInput.max = held;
        if (parseInt(qtyInput.value) > held) {
            qtyInput.value = held > 0 ? held : 1;
        }
    });
}

function onUserChange(selectEl) {
    var type = document.getElementById('type').value;
    var row = selectEl.closest('.alloc-row');
    var qtyInput = row.querySelector('.alloc-qty');
    var hint = row.querySelector('.alloc-holds');
    var uid = selectEl.value;

    if (type !== 'returned') {
        hint.textContent = '';
        qtyInput.removeAttribute('max');
        updateAllocationTotal();
        return;
    }

    if (!uid) {
        hint.textContent = '';
        qtyInput.removeAttribute('max');
        updateAllocationTotal();
        return;
    }

    var held = holdingsCache[uid] ?? 0;
    hint.textContent = 'holds ' + held;
    hint.style.color = held === 0 ? 'var(--red)' : 'var(--text-muted)';

    qtyInput.max = held;
    if (parseInt(qtyInput.value) > held) {
        qtyInput.value = held > 0 ? held : 1;
    }

    updateAllocationTotal();
}

function addAllocationRow() {
    var tmpl = document.getElementById('allocRowTemplate');
    var clone = tmpl.content.cloneNode(true);
    document.getElementById('allocationRows').appendChild(clone);
    applyHoldingsToRows();
    updateAllocationTotal();
}

function removeAllocRow(btn) {
    btn.closest('.alloc-row').remove();
    updateAllocationTotal();
}

function updateAllocationTotal() {
    var qty  = parseInt(document.getElementById('quantity').value) || 0;
    var rows = document.querySelectorAll('.alloc-row');
    var sum  = 0;
    var type = document.getElementById('type').value;

    rows.forEach(function (row) {
        var uid = row.querySelector('.alloc-user').value;
        var q = parseInt(row.querySelector('.alloc-qty').value) || 0;
        if (uid) sum += q;
    });

    document.getElementById('allocTotal').textContent = sum + ' of ' + qty;

    var errEl = document.getElementById('allocError');
    errEl.style.display = 'none';

    if (type === 'outbound' && sum > 0 && sum !== qty) {
        errEl.style.display = 'block';
        errEl.textContent = 'Outbound allocation must equal ' + qty + '. Currently: ' + sum + '.';
    } else if (type === 'returned' && rows.length > 0 && sum !== qty) {
        errEl.style.display = 'block';
        errEl.textContent = 'Returned allocation must equal ' + qty + '. Currently: ' + sum + '.';
    } else if (sum > qty) {
        errEl.style.display = 'block';
        errEl.textContent = 'Allocation exceeds transaction quantity.';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    updateFieldVisibility();

    document.getElementById('product_id').addEventListener('change', function () {
        var pid = this.value;
        if (!pid) { holderCount = 0; updateFieldVisibility(); return; }

        fetch('transactions.php?ajax=holdings&product=' + encodeURIComponent(pid))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.ok && data.holdings) {
                    holderCount = Object.keys(data.holdings).length;
                } else {
                    holderCount = 0;
                }
                updateFieldVisibility();
            })
            .catch(function () {
                holderCount = 0;
                updateFieldVisibility();
            });
    });
});
</script>

<?php endif; ?>

<?php include 'footer.php'; ?>
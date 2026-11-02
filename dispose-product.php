<?php
require 'config.php';
require 'auth.php';

$user = require_role('admin', 'superadmin');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: inventory.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: product.php?id=' . $id);
    exit;
}

$stmt = db()->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    header('Location: inventory.php');
    exit;
}

if ($product['status'] === 'disposed') {
    header('Location: product.php?id=' . $id);
    exit;
}

if ((int)$product['stock'] <= 0) {
    // Nothing to dispose
    header('Location: product.php?id=' . $id);
    exit;
}

// SAP-style: cannot dispose while users hold units
$stmt = db()->prepare('
    SELECT COALESCE(SUM(quantity), 0)
    FROM assets
    WHERE product_id = ? AND status = "active"
');
$stmt->execute([$id]);
$heldByUsers = (int)$stmt->fetchColumn();

if ($heldByUsers > 0) {
    log_action($user['id'], $user['username'], $user['role'],
        "Attempted to dispose {$product['sku']} — blocked ({$heldByUsers} units held by users)",
        'inventory', 'warning');
    header('Location: product.php?id=' . $id . '&error=held');
    exit;
}

$quantity = (int)$product['stock'];

try {
    db()->beginTransaction();

    $stmt = db()->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
    $stmt->execute([$id]);
    $product = $stmt->fetch();

    if (!$product || (int)$product['stock'] <= 0) {
        db()->rollBack();
        header('Location: product.php?id=' . $id);
        exit;
    }

    $quantity = (int)$product['stock'];

    $code = 'TXN-' . str_pad(
        (string)(((int)db()->query('SELECT COALESCE(MAX(id), 0) FROM transactions')->fetchColumn()) + 1),
        5, '0', STR_PAD_LEFT
    );

    db()->prepare('
        INSERT INTO transactions
            (txn_code, type, product_id, quantity, unit_cost, unit_price,
             reference_no, remarks, created_by, from_user_id)
        VALUES (?, "disposed", ?, ?, ?, ?, NULL, ?, ?, NULL)
    ')->execute([
        $code,
        $id,
        $quantity,
        (float)$product['unit_cost'],
        (float)$product['unit_price'],
        'Disposed via product page',
        $user['id'],
    ]);
    $txnId = (int)db()->lastInsertId();

    db()->prepare('UPDATE products SET stock = 0, status = "disposed" WHERE id = ?')
        ->execute([$id]);

    db()->prepare('
        INSERT INTO assets (product_id, assigned_to, assigned_at, status, quantity, transaction_id, remarks)
        VALUES (?, NULL, CURDATE(), "disposed", ?, ?, ?)
    ')->execute([$id, $quantity, $txnId, 'Disposed via product page']);

    db()->commit();

    log_action($user['id'], $user['username'], $user['role'],
        "Disposed {$quantity} × {$product['sku']} ({$code})", 'inventory', 'warning');

} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    error_log('Dispose failed: ' . $e->getMessage());
    header('Location: product.php?id=' . $id . '&error=failed');
    exit;
}

header('Location: product.php?id=' . $id . '&flash=disposed');
exit;
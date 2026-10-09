<?php
require 'config.php';
require 'auth.php';

$user = require_role('superadmin');

$pageTitle = 'Delete Product';
$activeNav = 'inventory';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: inventory.php'); exit; }

$errors = [];

/* Load product */
$stmt = db()->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) { header('Location: inventory.php'); exit; }

/* Reference counts */
$stmt = db()->prepare('SELECT COUNT(*) FROM transactions WHERE product_id = ?');
$stmt->execute([$id]);
$txnRefCount = (int)$stmt->fetchColumn();

$stmt = db()->prepare('SELECT COUNT(*) FROM assets WHERE product_id = ?');
$stmt->execute([$id]);
$assetRefCount = (int)$stmt->fetchColumn();

/* Compute eligibility */
$isDisposed  = ($product['status'] === 'disposed');
$hasRefs     = ($txnRefCount > 0 || $assetRefCount > 0);
$canDelete   = (!$isDisposed && !$hasRefs);

/* Handle submission */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $confirmSku = trim($_POST['confirm_sku'] ?? '');

    if (!$canDelete) {
        $errors[] = 'This product cannot be deleted. It has history or is already disposed.';
    } elseif ($confirmSku !== $product['sku']) {
        $errors[] = 'The SKU you typed does not match. Type "' . $product['sku'] . '" exactly to confirm.';
    } else {
        try {
            db()->beginTransaction();

            // Log BEFORE deleting, with the full record preserved
            $snapshot = [
                'id'              => (int)$product['id'],
                'sku'             => $product['sku'],
                'name'            => $product['name'],
                'product_type'    => $product['product_type'],
                'category_id'     => $product['category_id'],
                'stock'           => (int)$product['stock'],
                'unit_cost'       => $product['unit_cost'],
                'unit_price'      => $product['unit_price'],
                'ownership'       => $product['ownership'],
                'status'          => $product['status'],
                'purchase_date'   => $product['purchase_date'],
                'warranty_until'  => $product['warranty_until'],
                'created_at'      => $product['created_at'],
            ];

            log_action(
                $user['id'],
                $user['username'],
                $user['role'],
                "Permanently deleted product {$product['sku']} — {$product['name']}. Snapshot: " . json_encode($snapshot),
                'inventory',
                'warning'
            );

            // Delete any attached assets (should be zero, but be safe)
            db()->prepare('DELETE FROM assets WHERE product_id = ?')->execute([$id]);

            // Delete the product
            db()->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);

            db()->commit();

            header('Location: inventory.php?flash=deleted');
            exit;

        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            error_log('Product delete failed: ' . $e->getMessage());
            $errors[] = 'Delete failed. Please try again.';
        }
    }
}

include 'header.php';
?>

<?php if ($errors): ?>
    <div class="banner danger">
        <i class="fas fa-exclamation-circle"></i>
        <div>
            <ul style="margin:0 0 0 18px;">
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<div class="card" style="max-width:640px;margin:0 auto;border-color:rgba(244,71,71,0.4);">
    <h3 style="color:var(--red);margin-bottom:8px;">
        <i class="fas fa-trash-alt"></i> Delete Product
    </h3>
    <p class="text-muted mb-20" style="font-size:13px;">
        This action is permanent. The product row will be removed from the database.
        An audit entry will be written so the deletion is traceable.
    </p>

    <?php if (!$canDelete): ?>

        <div class="banner danger">
            <i class="fas fa-lock"></i>
            <div>
                <strong>This product cannot be deleted.</strong>
                <ul style="margin:6px 0 0 18px;">
                    <?php if ($isDisposed): ?>
                        <li>The product is already disposed.</li>
                    <?php endif; ?>
                    <?php if ($txnRefCount > 0): ?>
                        <li><?= (int)$txnRefCount ?> transaction<?= $txnRefCount === 1 ? '' : 's' ?> reference this product.</li>
                    <?php endif; ?>
                    <?php if ($assetRefCount > 0): ?>
                        <li><?= (int)$assetRefCount ?> assignment<?= $assetRefCount === 1 ? '' : 's' ?> in the assets table.</li>
                    <?php endif; ?>
                </ul>
                <p style="margin:8px 0 0;">Dispose the product instead.</p>
            </div>
        </div>

        <a href="product.php?id=<?= (int)$product['id'] ?>" class="btn btn-outline">
            <i class="fas fa-arrow-left"></i> Back to product
        </a>

    <?php else: ?>

        <table style="margin-bottom:20px;">
            <tbody>
                <tr><th style="width:140px;">SKU</th><td class="text-bright fw-600"><?= htmlspecialchars($product['sku']) ?></td></tr>
                <tr><th>Name</th><td><?= htmlspecialchars($product['name']) ?></td></tr>
                <tr><th>Type</th><td><?= ucfirst($product['product_type']) ?></td></tr>
                <tr><th>Stock</th><td><?= (int)$product['stock'] ?></td></tr>
                <tr><th>Status</th><td><span class="badge muted"><?= ucwords(str_replace('_',' ',$product['status'])) ?></span></td></tr>
            </tbody>
        </table>

        <form method="POST">
            <div class="form-group">
                <label for="confirm_sku">
                    Type <code><?= htmlspecialchars($product['sku']) ?></code> to confirm:
                </label>
                <div class="input-wrap">
                    <i class="fas fa-keyboard icon"></i>
                    <input type="text" id="confirm_sku" name="confirm_sku"
                           placeholder="Type the SKU exactly"
                           required autofocus autocomplete="off">
                </div>
            </div>

            <div class="flex" style="gap:10px;margin-top:16px;">
                <button type="submit" class="btn"
                        style="background:var(--red);color:#fff;">
                    <i class="fas fa-trash-alt"></i> Yes, delete permanently
                </button>
                <a href="product.php?id=<?= (int)$product['id'] ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>

    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
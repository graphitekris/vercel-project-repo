<?php
require 'config.php';
require 'auth.php';

$user = require_role('admin', 'superadmin');
$isSuperadmin = ($user['role'] === 'superadmin');

$pageTitle = 'Inventory';
$activeNav = 'inventory';

$flash = '';
$flashType = 'success';
$errors = [];

$action = $_GET['action'] ?? 'list';
$showDisposed = isset($_GET['show']) && $_GET['show'] === 'disposed';

/* ============================================================
   AJAX HANDLERS — Manage Lists (clients + categories)
   ============================================================ */
if (isset($_POST['manage']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $manage = $_POST['manage'];

    try {
        /* ---------- ADD CLIENT ---------- */
        if ($manage === 'add_client') {
            $name  = trim($_POST['name'] ?? '');
            $email = trim($_POST['contact_email'] ?? '');
            $phone = trim($_POST['contact_phone'] ?? '');

            if ($name === '')                     throw new RuntimeException('Client name is required.');
            if (strlen($name) > 120)              throw new RuntimeException('Client name is too long.');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Contact email is not valid.');
            }

            $stmt = db()->prepare('SELECT id FROM clients WHERE name = ? LIMIT 1');
            $stmt->execute([$name]);
            if ($stmt->fetch()) throw new RuntimeException('A client with that name already exists.');

            db()->prepare('
                INSERT INTO clients (name, contact_email, contact_phone)
                VALUES (?, ?, ?)
            ')->execute([$name, $email ?: null, $phone ?: null]);

            $newId = (int)db()->lastInsertId();

            log_action($user['id'], $user['username'], $user['role'],
                "Created client {$name}", 'system', 'success');

            echo json_encode([
                'ok' => true,
                'client' => [
                    'id' => $newId,
                    'name' => $name,
                    'contact_email' => $email,
                    'contact_phone' => $phone,
                ],
            ]);
            exit;
        }

        /* ---------- REMOVE CLIENT ---------- */
        if ($manage === 'remove_client') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid client.');

            $stmt = db()->prepare('SELECT name FROM clients WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $c = $stmt->fetch();
            if (!$c) throw new RuntimeException('Client not found.');

            $stmt = db()->prepare('SELECT COUNT(*) FROM products WHERE client_id = ?');
            $stmt->execute([$id]);
            $count = (int)$stmt->fetchColumn();

            if ($count > 0) {
                throw new RuntimeException("Cannot delete — {$count} product(s) reference this client.");
            }

            db()->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
            log_action($user['id'], $user['username'], $user['role'],
                "Deleted client {$c['name']}", 'system', 'warning');

            echo json_encode(['ok' => true, 'id' => $id]);
            exit;
        }

        /* ---------- ADD CATEGORY ---------- */
        if ($manage === 'add_category') {
            $name        = trim($_POST['name'] ?? '');
            $lifeMonths  = (int)($_POST['default_life_months'] ?? 36);
            $salvagePct  = (float)($_POST['default_salvage_pct'] ?? 10);
            $reorder     = (int)($_POST['default_reorder'] ?? 10);
            $warranty    = (int)($_POST['default_warranty_months'] ?? 12);

            if ($name === '')                     throw new RuntimeException('Category name is required.');
            if (strlen($name) > 80)               throw new RuntimeException('Category name is too long.');
            if ($lifeMonths < 0)                  throw new RuntimeException('Life months cannot be negative.');
            if ($salvagePct < 0 || $salvagePct > 100) throw new RuntimeException('Salvage % must be 0–100.');
            if ($reorder < 0)                     throw new RuntimeException('Reorder cannot be negative.');
            if ($warranty < 0)                    throw new RuntimeException('Warranty months cannot be negative.');

            $stmt = db()->prepare('SELECT id FROM categories WHERE name = ? LIMIT 1');
            $stmt->execute([$name]);
            if ($stmt->fetch()) throw new RuntimeException('A category with that name already exists.');

            db()->prepare('
                INSERT INTO categories
                    (name, default_life_months, default_salvage_pct, default_reorder, default_warranty_months)
                VALUES (?, ?, ?, ?, ?)
            ')->execute([$name, $lifeMonths, $salvagePct, $reorder, $warranty]);

            $newId = (int)db()->lastInsertId();

            log_action($user['id'], $user['username'], $user['role'],
                "Created category {$name}", 'system', 'success');

            echo json_encode([
                'ok' => true,
                'category' => [
                    'id' => $newId,
                    'name' => $name,
                    'default_life_months' => $lifeMonths,
                    'default_salvage_pct' => $salvagePct,
                    'default_reorder' => $reorder,
                    'default_warranty_months' => $warranty,
                ],
            ]);
            exit;
        }

        /* ---------- REMOVE CATEGORY ---------- */
        if ($manage === 'remove_category') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid category.');

            $stmt = db()->prepare('SELECT name FROM categories WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $c = $stmt->fetch();
            if (!$c) throw new RuntimeException('Category not found.');

            $stmt = db()->prepare('SELECT COUNT(*) FROM products WHERE category_id = ?');
            $stmt->execute([$id]);
            $count = (int)$stmt->fetchColumn();

            if ($count > 0) {
                throw new RuntimeException("Cannot delete — {$count} product(s) use this category.");
            }

            db()->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
            log_action($user['id'], $user['username'], $user['role'],
                "Deleted category {$c['name']}", 'system', 'warning');

            echo json_encode(['ok' => true, 'id' => $id]);
            exit;
        }

        throw new RuntimeException('Unknown manage action.');

    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        exit;
    }
}

/* ============================================================
   CREATE PRODUCT
   ============================================================ */
if ($action === 'new' && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['manage'])) {
    $sku           = trim($_POST['sku'] ?? '');
    $name          = trim($_POST['name'] ?? '');
    $productType   = $_POST['product_type'] ?? 'hardware';
    $categoryId    = (int)($_POST['category_id'] ?? 0) ?: null;
    $reorder       = (int)($_POST['reorder_level'] ?? 10);
    $unitCost      = (float)($_POST['unit_cost'] ?? 0);
    $unitPrice     = (float)($_POST['unit_price'] ?? 0);

    $ownership     = $_POST['ownership'] ?? 'company';
    $clientId      = (int)($_POST['client_id'] ?? 0) ?: null;
    $location      = trim($_POST['location'] ?? '') ?: null;
    $tools         = trim($_POST['tools_included'] ?? '') ?: null;

    $purchaseDate  = trim($_POST['purchase_date'] ?? '') ?: null;
    $lifeMonths    = (int)($_POST['useful_life_months'] ?? 0) ?: null;
    $salvage       = (float)($_POST['salvage_value'] ?? 0) ?: null;
    $warranty      = trim($_POST['warranty_until'] ?? '') ?: null;

    $isLicensed    = ($productType === 'software') ? 1 : 0;
    $licVendor     = trim($_POST['license_vendor'] ?? '') ?: null;
    $licKey        = trim($_POST['license_key'] ?? '') ?: null;
    $licSeats      = (int)($_POST['license_seats'] ?? 0) ?: null;
    $licExpires    = trim($_POST['license_expires_at'] ?? '') ?: null;

    $validTypes      = ['hardware','software','consumable'];
    $validOwnership  = ['company','client'];

    if ($sku === '')                                  $errors[] = 'SKU is required.';
    if ($name === '')                                 $errors[] = 'Product name is required.';
    if (!in_array($productType, $validTypes, true))   $errors[] = 'Invalid product type.';
    if (!in_array($ownership, $validOwnership, true)) $errors[] = 'Invalid ownership.';
    if ($reorder < 0)                                 $errors[] = 'Reorder level cannot be negative.';
    if ($unitCost < 0)                                $errors[] = 'Unit cost cannot be negative.';
    if ($unitPrice < 0)                               $errors[] = 'Unit price cannot be negative.';
    if ($ownership === 'client' && $clientId === null) $errors[] = 'Choose a client when ownership is "Client-supplied".';

    if (!$errors) {
        $stmt = db()->prepare('SELECT id FROM products WHERE sku = ? LIMIT 1');
        $stmt->execute([$sku]);

        if ($stmt->fetch()) {
            $errors[] = 'That SKU already exists.';
        } else {
            db()->prepare('
                INSERT INTO products
                    (sku, name, product_type, category_id, stock, reorder_level,
                     unit_cost, unit_price, status, lifecycle_stage,
                     ownership, client_id, location, tools_included,
                     purchase_date, useful_life_months, salvage_value, warranty_until,
                     is_licensed, license_vendor, license_key, license_seats, license_expires_at)
                VALUES (?, ?, ?, ?, 0, ?, ?, ?, "out_of_stock", "acquired",
                        ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?, ?)
            ')->execute([
                $sku, $name, $productType, $categoryId, $reorder, $unitCost, $unitPrice,
                $ownership, $clientId, $location, $tools,
                $purchaseDate, $lifeMonths, $salvage, $warranty,
                $isLicensed, $licVendor, $licKey, $licSeats, $licExpires,
            ]);

            log_action($user['id'], $user['username'], $user['role'],
                "Created {$productType} product {$sku} — {$name}" .
                ($ownership === 'client' ? ' (client-supplied)' : ''),
                'inventory', 'success');

            header('Location: inventory.php?flash=created');
            exit;
        }
    }
}

if (isset($_GET['flash'])) {
    $flash = match($_GET['flash']) {
        'created' => 'Product created. Log an inbound transaction to add stock.',
        'deleted' => 'Product permanently deleted.',
        default   => '',
    };
}

/* ============================================================
   LOAD DATA
   ============================================================ */
$categories = db()->query('
    SELECT id, name, default_life_months, default_salvage_pct, default_reorder, default_warranty_months
    FROM categories
    ORDER BY name
')->fetchAll();

$clients = db()->query('SELECT id, name, contact_email FROM clients ORDER BY name')->fetchAll();

$products = db()->query('
    SELECT p.*, c.name AS category_name, cl.name AS client_name
    FROM products p
    LEFT JOIN categories c  ON c.id = p.category_id
    LEFT JOIN clients cl    ON cl.id = p.client_id
    WHERE p.status ' . ($showDisposed ? '= "disposed"' : '!= "disposed"') . '
    ORDER BY p.name
')->fetchAll();

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
        Products are <strong>created once and referenced forever</strong>. SKU and name are
        frozen after creation. Stock and status change only through transactions.
        Use <strong>Manage Lists</strong> to add clients and categories without leaving this page.
    </span>
</div>

<!-- MANAGE LISTS PANEL -->
<div class="card" style="padding:0;overflow:hidden;">
    <div class="flex-between" style="padding:14px 20px;cursor:pointer;" onclick="toggleManage()">
        <h3 style="margin:0;display:flex;align-items:center;gap:8px;">
            <i class="fas fa-tools"></i> Manage Lists
            <span class="text-muted" style="font-weight:400;font-size:12px;">
                (clients, categories)
            </span>
        </h3>
        <i class="fas fa-chevron-down" id="manageChevron" style="color:var(--text-muted);"></i>
    </div>

    <div id="managePanel" style="display:none;padding:0 20px 20px;border-top:1px solid var(--border);">
        <div id="manageMsg" style="display:none;padding:10px 14px;border-radius:var(--radius);margin:14px 0 0;font-size:13px;"></div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:16px;">

            <!-- ============ CLIENTS ============ -->
            <div>
                <h4 style="font-size:13px;text-transform:uppercase;letter-spacing:0.4px;color:var(--text-muted);margin-bottom:12px;">
                    <i class="fas fa-building"></i> Clients
                </h4>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px;">
                    <div style="grid-column:1 / -1;">
                        <label style="display:block;font-size:11px;color:var(--text-muted);margin-bottom:4px;">Client name</label>
                        <input type="text" id="newClientName" placeholder="e.g. ACME Corporation"
                               style="width:100%;padding:9px 12px;background:var(--bg-alt);border:1px solid var(--border);border-radius:var(--radius);color:var(--text-bright);font-size:13px;outline:none;"
                               onkeydown="if(event.key==='Enter'){event.preventDefault();addClient();}">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;color:var(--text-muted);margin-bottom:4px;">Contact email <span style="opacity:0.6;">(optional)</span></label>
                        <input type="email" id="newClientEmail" placeholder="name@example.com"
                               style="width:100%;padding:9px 12px;background:var(--bg-alt);border:1px solid var(--border);border-radius:var(--radius);color:var(--text-bright);font-size:13px;outline:none;"
                               onkeydown="if(event.key==='Enter'){event.preventDefault();addClient();}">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;color:var(--text-muted);margin-bottom:4px;">Phone <span style="opacity:0.6;">(optional)</span></label>
                        <input type="text" id="newClientPhone" placeholder="+63 …"
                               style="width:100%;padding:9px 12px;background:var(--bg-alt);border:1px solid var(--border);border-radius:var(--radius);color:var(--text-bright);font-size:13px;outline:none;"
                               onkeydown="if(event.key==='Enter'){event.preventDefault();addClient();}">
                    </div>
                    <button type="button" class="btn btn-primary" style="grid-column:1 / -1;padding:9px 14px;font-size:13px;" onclick="addClient()">
                        <i class="fas fa-plus"></i> Add Client
                    </button>
                </div>

                <div id="clientList">
                    <?php foreach ($clients as $cl): ?>
                        <div class="manage-row" data-id="<?= (int)$cl['id'] ?>"
                             style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:var(--bg-alt);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:6px;font-size:13px;">
                            <span>
                                <strong class="text-bright"><?= htmlspecialchars($cl['name']) ?></strong>
                                <?php if ($cl['contact_email']): ?>
                                    <span class="text-muted" style="font-size:11px;"> · <?= htmlspecialchars($cl['contact_email']) ?></span>
                                <?php endif; ?>
                            </span>
                            <button type="button" class="btn btn-ghost" style="padding:2px 8px;color:var(--red);"
                                    onclick="removeClient(<?= (int)$cl['id'] ?>, '<?= htmlspecialchars($cl['name'], ENT_QUOTES) ?>')">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$clients): ?>
                        <p class="text-muted" style="font-size:12px;">No clients yet.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ============ CATEGORIES ============ -->
            <div>
                <h4 style="font-size:13px;text-transform:uppercase;letter-spacing:0.4px;color:var(--text-muted);margin-bottom:12px;">
                    <i class="fas fa-tag"></i> Categories
                </h4>

                <div style="margin-bottom:14px;">
                    <label style="display:block;font-size:11px;color:var(--text-muted);margin-bottom:4px;">Category name</label>
                    <input type="text" id="newCatName" placeholder="e.g. Server Hardware"
                           style="width:100%;padding:9px 12px;background:var(--bg-alt);border:1px solid var(--border);border-radius:var(--radius);color:var(--text-bright);font-size:13px;outline:none;margin-bottom:10px;">

                    <div style="font-size:11px;color:var(--text-muted);margin-bottom:6px;font-weight:600;text-transform:uppercase;letter-spacing:0.3px;">
                        Defaults for new products
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                        <div>
                            <label style="display:block;font-size:11px;color:var(--text-muted);margin-bottom:4px;">Useful Life (months)</label>
                            <input type="number" id="newCatLife" min="0" placeholder="36" value="36"
                                   style="width:100%;padding:9px 12px;background:var(--bg-alt);border:1px solid var(--border);border-radius:var(--radius);color:var(--text-bright);font-size:13px;outline:none;">
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;color:var(--text-muted);margin-bottom:4px;">Salvage Value (%)</label>
                            <input type="number" id="newCatSalvage" min="0" max="100" step="0.01" placeholder="10" value="10"
                                   style="width:100%;padding:9px 12px;background:var(--bg-alt);border:1px solid var(--border);border-radius:var(--radius);color:var(--text-bright);font-size:13px;outline:none;">
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;color:var(--text-muted);margin-bottom:4px;">Reorder Level</label>
                            <input type="number" id="newCatReorder" min="0" placeholder="10" value="10"
                                   style="width:100%;padding:9px 12px;background:var(--bg-alt);border:1px solid var(--border);border-radius:var(--radius);color:var(--text-bright);font-size:13px;outline:none;">
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;color:var(--text-muted);margin-bottom:4px;">Warranty (months)</label>
                            <input type="number" id="newCatWarranty" min="0" placeholder="12" value="12"
                                   style="width:100%;padding:9px 12px;background:var(--bg-alt);border:1px solid var(--border);border-radius:var(--radius);color:var(--text-bright);font-size:13px;outline:none;">
                        </div>
                    </div>

                    <p class="text-muted" style="font-size:11px;margin-top:8px;line-height:1.5;">
                        When a user picks this category on the Add Product form, these values auto-fill. Useful life and salvage drive depreciation.
                    </p>

                    <button type="button" class="btn btn-primary" style="width:100%;padding:9px 14px;font-size:13px;margin-top:10px;" onclick="addCategory()">
                        <i class="fas fa-plus"></i> Add Category
                    </button>
                </div>

                <div id="categoryList">
                    <?php foreach ($categories as $c): ?>
                        <div class="manage-row" data-id="<?= (int)$c['id'] ?>"
                             style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:var(--bg-alt);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:6px;font-size:13px;">
                            <span>
                                <strong class="text-bright"><?= htmlspecialchars($c['name']) ?></strong>
                                <span class="text-muted" style="font-size:11px;">
                                    · <?= (int)$c['default_life_months'] ?>mo life, <?= (float)$c['default_salvage_pct'] ?>% salvage
                                </span>
                            </span>
                            <button type="button" class="btn btn-ghost" style="padding:2px 8px;color:var(--red);"
                                    onclick="removeCategory(<?= (int)$c['id'] ?>, '<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>')">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$categories): ?>
                        <p class="text-muted" style="font-size:12px;">No categories yet.</p>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<div class="flex-between mb-16">
    <div class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" placeholder="Search products or SKU..." data-filter-target="#inventoryTable" />
    </div>
    <div class="flex">
        <a href="inventory.php<?= $showDisposed ? '' : '?show=disposed' ?>" class="btn btn-outline">
            <i class="fas fa-archive"></i>
            <?= $showDisposed ? 'Show Active' : 'Show Disposed' ?>
        </a>
        <button class="btn btn-outline" onclick="exportTableCSV('#inventoryTable', 'inventory.csv')">
            <i class="fas fa-download"></i> Export CSV
        </button>
        <a href="inventory.php?action=new" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add Product
        </a>
    </div>
</div>

<div class="table-card">
    <table id="inventoryTable">
        <thead>
            <tr>
                <th>SKU</th>
                <th>Product</th>
                <th>Type</th>
                <th>Ownership</th>
                <th>Category</th>
                <th>Client</th>
                <th>Stock</th>
                <th>Status</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$products): ?>
            <tr><td colspan="9" class="text-muted"><?= $showDisposed ? 'No disposed products.' : 'No active products yet.' ?></td></tr>
        <?php else: foreach ($products as $p):
            $statusClass = match($p['status']) {
                'in_stock'        => 'success',
                'low_stock'       => 'warning',
                'out_of_stock'    => 'danger',
                'damaged'         => 'danger',
                'for_repair'      => 'info',
                'for_replacement' => 'purple',
                'disposed'        => 'muted',
                default           => 'muted',
            };
            $typeClass = match($p['product_type']) {
                'hardware'   => 'info',
                'software'   => 'purple',
                'consumable' => 'muted',
                default      => 'muted',
            };
            $ownerClass = $p['ownership'] === 'client' ? 'warning' : 'success';
        ?>
            <tr>
                <td class="text-bright fw-600"><?= htmlspecialchars($p['sku']) ?></td>
                <td><?= htmlspecialchars($p['name']) ?></td>
                <td><span class="badge <?= $typeClass ?>"><?= ucfirst($p['product_type']) ?></span></td>
                <td><span class="badge <?= $ownerClass ?>"><?= $p['ownership'] === 'client' ? 'Client-supplied' : 'Company' ?></span></td>
                <td class="text-muted"><?= htmlspecialchars($p['category_name'] ?? '—') ?></td>
                <td class="text-muted" style="font-size:12px;"><?= htmlspecialchars($p['client_name'] ?? '—') ?></td>
                <td class="<?= $p['stock'] <= $p['reorder_level'] && $p['stock'] > 0 ? 'text-orange fw-600' : ($p['stock'] <= 0 ? 'text-red fw-600' : '') ?>">
                    <?= (int)$p['stock'] ?>
                </td>
                <td><span class="badge <?= $statusClass ?>"><?= ucwords(str_replace('_', ' ', $p['status'])) ?></span></td>
                <td style="text-align:right;white-space:nowrap;">
                    <a href="product.php?id=<?= (int)$p['id'] ?>" class="btn btn-outline" style="padding:4px 10px;font-size:12px;">
                        <i class="fas fa-eye"></i> View
                    </a>
                    <a href="transactions.php?product=<?= (int)$p['id'] ?>" class="btn btn-outline" style="padding:4px 10px;font-size:12px;">
                        <i class="fas fa-exchange-alt"></i>
                    </a>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php /* =========================================================
   CREATE FORM
   ========================================================= */ ?>
<?php elseif ($action === 'new'): ?>

<div class="card">
    <div class="flex-between mb-20">
        <h3 style="margin:0;">Add New Product</h3>
        <a href="inventory.php" class="btn btn-outline">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>

    <form method="POST" action="inventory.php?action=new" id="productForm">

        <div class="form-group">
            <label>Product Type <span class="text-red">*</span></label>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <?php
                $selectedType = $_POST['product_type'] ?? 'hardware';
                $types = [
                    'hardware'   => ['Hardware',   'fa-server',   'Physical equipment: PCs, monitors, printers, tools'],
                    'software'   => ['Software',   'fa-key',      'Licensed software: apps, OS, subscriptions'],
                    'consumable' => ['Consumable', 'fa-box-open', 'Supplies that get used up: paper, toner, cables'],
                ];
                foreach ($types as $key => [$label, $icon, $desc]):
                ?>
                    <label class="type-card" style="flex:1;min-width:220px;display:flex;gap:12px;padding:14px 16px;border:1px solid <?= $selectedType === $key ? 'var(--accent)' : 'var(--border)' ?>;border-radius:var(--radius);cursor:pointer;background:<?= $selectedType === $key ? 'var(--accent-soft)' : 'transparent' ?>;">
                        <input type="radio" name="product_type" value="<?= $key ?>"
                               <?= $selectedType === $key ? 'checked' : '' ?>
                               style="accent-color:var(--accent);margin-top:4px;" onchange="updateType()">
                        <div>
                            <div style="font-weight:600;color:var(--text-bright);font-size:13px;">
                                <i class="fas <?= $icon ?>"></i> <?= $label ?>
                            </div>
                            <div class="text-muted" style="font-size:11px;margin-top:2px;"><?= $desc ?></div>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="form-group">
                <label for="sku">SKU <span class="text-red">*</span></label>
                <div class="input-wrap">
                    <i class="fas fa-barcode icon"></i>
                    <input type="text" id="sku" name="sku" required placeholder="e.g. IT-LPTP-DELL"
                           value="<?= htmlspecialchars($_POST['sku'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label for="name">Product Name <span class="text-red">*</span></label>
                <div class="input-wrap">
                    <i class="fas fa-cube icon"></i>
                    <input type="text" id="name" name="name" required placeholder="e.g. Dell Latitude 5540 Laptop"
                           value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label for="category_id">Category</label>
                <div class="input-wrap">
                    <i class="fas fa-tag icon"></i>
                    <select id="category_id" name="category_id" style="padding-left:40px;" onchange="applyCategoryDefaults()">
                        <option value="">— None —</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"
                                    data-life="<?= (int)$c['default_life_months'] ?>"
                                    data-salvage="<?= htmlspecialchars($c['default_salvage_pct']) ?>"
                                    data-reorder="<?= (int)$c['default_reorder'] ?>"
                                    data-warranty="<?= (int)$c['default_warranty_months'] ?>"
                                <?= (int)($_POST['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="reorder_level">Reorder Level</label>
                <div class="input-wrap">
                    <i class="fas fa-exclamation-triangle icon"></i>
                    <input type="number" id="reorder_level" name="reorder_level" min="0"
                           value="<?= (int)($_POST['reorder_level'] ?? 10) ?>">
                </div>
            </div>
            <div class="form-group">
                <label for="unit_cost">Unit Cost (₱)</label>
                <div class="input-wrap">
                    <i class="fas fa-peso-sign icon"></i>
                    <input type="number" id="unit_cost" name="unit_cost" min="0" step="0.01"
                           value="<?= htmlspecialchars($_POST['unit_cost'] ?? '0.00') ?>">
                </div>
            </div>
            <div class="form-group">
                <label for="unit_price">Unit Price (₱)</label>
                <div class="input-wrap">
                    <i class="fas fa-tag icon"></i>
                    <input type="number" id="unit_price" name="unit_price" min="0" step="0.01"
                           value="<?= htmlspecialchars($_POST['unit_price'] ?? '0.00') ?>">
                </div>
            </div>
        </div>

        <h3 style="margin:24px 0 12px;font-size:13px;text-transform:uppercase;letter-spacing:0.4px;color:var(--text-muted);">
            Ownership &amp; Assignment
        </h3>

        <?php $selOwnership = $_POST['ownership'] ?? 'company'; ?>

        <div class="form-group">
            <label>Ownership <span class="text-red">*</span></label>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <label class="type-card" style="flex:1;min-width:220px;display:flex;gap:12px;padding:14px 16px;border:1px solid <?= $selOwnership === 'company' ? 'var(--accent)' : 'var(--border)' ?>;border-radius:var(--radius);cursor:pointer;background:<?= $selOwnership === 'company' ? 'var(--accent-soft)' : 'transparent' ?>;">
                    <input type="radio" name="ownership" value="company"
                           <?= $selOwnership === 'company' ? 'checked' : '' ?>
                           style="accent-color:var(--accent);margin-top:4px;" onchange="updateOwnership()">
                    <div>
                        <div style="font-weight:600;color:var(--text-bright);font-size:13px;">
                            <i class="fas fa-building"></i> Company-owned
                        </div>
                        <div class="text-muted" style="font-size:11px;margin-top:2px;">Purchased by us. We own it, we depreciate it.</div>
                    </div>
                </label>
                <label class="type-card" style="flex:1;min-width:220px;display:flex;gap:12px;padding:14px 16px;border:1px solid <?= $selOwnership === 'client' ? 'var(--accent)' : 'var(--border)' ?>;border-radius:var(--radius);cursor:pointer;background:<?= $selOwnership === 'client' ? 'var(--accent-soft)' : 'transparent' ?>;">
                    <input type="radio" name="ownership" value="client"
                           <?= $selOwnership === 'client' ? 'checked' : '' ?>
                           style="accent-color:var(--accent);margin-top:4px;" onchange="updateOwnership()">
                    <div>
                        <div style="font-weight:600;color:var(--text-bright);font-size:13px;">
                            <i class="fas fa-handshake"></i> Client-supplied
                        </div>
                        <div class="text-muted" style="font-size:11px;margin-top:2px;">Provided by the client. We hold it, we don't own it.</div>
                    </div>
                </label>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
            <div class="form-group">
                <label for="client_id">Client <span class="text-muted" style="font-weight:400;" id="clientHint"></span></label>
                <div class="input-wrap">
                    <i class="fas fa-building icon"></i>
                    <select id="client_id" name="client_id" style="padding-left:40px;">
                        <option value="">— None —</option>
                        <?php foreach ($clients as $cl): ?>
                            <option value="<?= (int)$cl['id'] ?>" <?= (int)($_POST['client_id'] ?? 0) === (int)$cl['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cl['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="location">Location <span class="text-muted" style="font-weight:400;">(optional)</span></label>
                <div class="input-wrap">
                    <i class="fas fa-map-marker-alt icon"></i>
                    <input type="text" id="location" name="location"
                           placeholder="e.g. Room 302, Head Office"
                           value="<?= htmlspecialchars($_POST['location'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label for="tools_included">Tools Included <span class="text-muted" style="font-weight:400;">(optional)</span></label>
                <div class="input-wrap">
                    <i class="fas fa-toolbox icon"></i>
                    <input type="text" id="tools_included" name="tools_included"
                           placeholder="e.g. Charger, case, cable"
                           value="<?= htmlspecialchars($_POST['tools_included'] ?? '') ?>">
                </div>
            </div>
        </div>

        <div id="hardwareFields">
            <h3 style="margin:24px 0 12px;font-size:13px;text-transform:uppercase;letter-spacing:0.4px;color:var(--text-muted);">
                Lifecycle &amp; Warranty
            </h3>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
                <div class="form-group">
                    <label for="purchase_date">Purchase Date <span class="text-muted" style="font-weight:400;">(optional)</span></label>
                    <div class="input-wrap">
                        <i class="fas fa-calendar icon"></i>
                        <input type="date" id="purchase_date" name="purchase_date"
                               value="<?= htmlspecialchars($_POST['purchase_date'] ?? '') ?>">
                    </div>
                    <p class="text-muted" style="font-size:11px;margin-top:4px;">Needed for depreciation</p>
                </div>
                <div class="form-group">
                    <label for="useful_life_months">Useful Life (months)</label>
                    <div class="input-wrap">
                        <i class="fas fa-hourglass-half icon"></i>
                        <input type="number" id="useful_life_months" name="useful_life_months" min="0"
                               value="<?= (int)($_POST['useful_life_months'] ?? 36) ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label for="salvage_value">Salvage Value (₱) <span class="text-muted" style="font-weight:400;">(optional)</span></label>
                    <div class="input-wrap">
                        <i class="fas fa-coins icon"></i>
                        <input type="number" id="salvage_value" name="salvage_value" min="0" step="0.01"
                               value="<?= htmlspecialchars($_POST['salvage_value'] ?? '0.00') ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label for="warranty_until">Warranty Until <span class="text-muted" style="font-weight:400;">(optional)</span></label>
                    <div class="input-wrap">
                        <i class="fas fa-shield-alt icon"></i>
                        <input type="date" id="warranty_until" name="warranty_until"
                               value="<?= htmlspecialchars($_POST['warranty_until'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>

        <div id="softwareFields">
            <h3 style="margin:24px 0 12px;font-size:13px;text-transform:uppercase;letter-spacing:0.4px;color:var(--text-muted);">
                License Details
            </h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="form-group">
                    <label for="license_vendor">Vendor</label>
                    <div class="input-wrap">
                        <i class="fas fa-industry icon"></i>
                        <input type="text" id="license_vendor" name="license_vendor"
                               placeholder="e.g. Microsoft"
                               value="<?= htmlspecialchars($_POST['license_vendor'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label for="license_key">License Key</label>
                    <div class="input-wrap">
                        <i class="fas fa-key icon"></i>
                        <input type="text" id="license_key" name="license_key"
                               placeholder="e.g. XXXXX-XXXXX-XXXXX"
                               value="<?= htmlspecialchars($_POST['license_key'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label for="license_seats">Seats</label>
                    <div class="input-wrap">
                        <i class="fas fa-users icon"></i>
                        <input type="number" id="license_seats" name="license_seats" min="0"
                               value="<?= (int)($_POST['license_seats'] ?? 0) ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label for="license_expires_at">License Expires</label>
                    <div class="input-wrap">
                        <i class="fas fa-calendar-times icon"></i>
                        <input type="date" id="license_expires_at" name="license_expires_at"
                               value="<?= htmlspecialchars($_POST['license_expires_at'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="flex mt-12" style="gap:10px;margin-top:20px;">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Create Product
            </button>
            <a href="inventory.php" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>

<script>
function updateType() {
    var type = document.querySelector('input[name="product_type"]:checked').value;
    var hw   = document.getElementById('hardwareFields');
    var sw   = document.getElementById('softwareFields');

    if (type === 'software') {
        hw.style.display = 'none';
        sw.style.display = 'block';
    } else if (type === 'consumable') {
        hw.style.display = 'none';
        sw.style.display = 'none';
    } else {
        hw.style.display = 'block';
        sw.style.display = 'none';
    }

    paintCards('product_type');
}
function updateOwnership() {
    var ownership = document.querySelector('input[name="ownership"]:checked').value;
    var hint = document.getElementById('clientHint');
    var clientSelect = document.getElementById('client_id');

    if (ownership === 'client') {
        hint.textContent = '(required)';
        hint.style.color = 'var(--red)';
        clientSelect.setAttribute('required', 'required');
    } else {
        hint.textContent = '(optional)';
        hint.style.color = '';
        clientSelect.removeAttribute('required');
    }

    paintCards('ownership');
}
function paintCards(name) {
    document.querySelectorAll('input[name="' + name + '"]').forEach(function (r) {
        var card = r.closest('label');
        if (r.checked) {
            card.style.borderColor = 'var(--accent)';
            card.style.background  = 'var(--accent-soft)';
        } else {
            card.style.borderColor = 'var(--border)';
            card.style.background  = 'transparent';
        }
    });
}
function applyCategoryDefaults() {
    var sel = document.getElementById('category_id');
    var opt = sel.options[sel.selectedIndex];
    if (!opt) return;

    if (opt.dataset.life)     document.getElementById('useful_life_months').value = opt.dataset.life;
    if (opt.dataset.reorder)  document.getElementById('reorder_level').value      = opt.dataset.reorder;

    if (opt.dataset.warranty) {
        var w = parseInt(opt.dataset.warranty, 10);
        if (w > 0) {
            var d = new Date();
            d.setMonth(d.getMonth() + w);
            document.getElementById('warranty_until').value = d.toISOString().slice(0, 10);
        } else {
            document.getElementById('warranty_until').value = '';
        }
    }

    var cost = parseFloat(document.getElementById('unit_cost').value || '0');
    if (opt.dataset.salvage && cost > 0) {
        document.getElementById('salvage_value').value =
            (cost * parseFloat(opt.dataset.salvage) / 100).toFixed(2);
    }
}
document.addEventListener('DOMContentLoaded', function () {
    updateType();
    updateOwnership();
});
</script>

<?php endif; ?>

<!-- AJAX script (list view only) -->
<?php if ($action === 'list'): ?>
<script>
function toggleManage() {
    var panel = document.getElementById('managePanel');
    var chev  = document.getElementById('manageChevron');
    var open  = panel.style.display !== 'none';
    panel.style.display = open ? 'none' : 'block';
    chev.className = open ? 'fas fa-chevron-down' : 'fas fa-chevron-up';
}

function showMsg(text, isError) {
    var el = document.getElementById('manageMsg');
    el.textContent = text;
    el.style.display = 'block';
    el.style.background = isError ? 'rgba(244,71,71,0.1)' : 'rgba(78,201,176,0.1)';
    el.style.border = '1px solid ' + (isError ? 'var(--red)' : 'var(--green)');
    el.style.color  = isError ? 'var(--red)' : 'var(--green)';
    clearTimeout(window.msgTimer);
    window.msgTimer = setTimeout(function () { el.style.display = 'none'; }, 3500);
}

function ajaxPost(data) {
    var form = new URLSearchParams();
    for (var k in data) form.append(k, data[k]);
    return fetch('inventory.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: form.toString(),
    }).then(function (r) { return r.json(); });
}

function addClient() {
    var nameEl  = document.getElementById('newClientName');
    var emailEl = document.getElementById('newClientEmail');
    var phoneEl = document.getElementById('newClientPhone');

    var name  = nameEl.value.trim();
    var email = emailEl.value.trim();
    var phone = phoneEl.value.trim();

    if (!name) { showMsg('Client name is required.', true); return; }

    ajaxPost({ manage: 'add_client', name: name, contact_email: email, contact_phone: phone }).then(function (res) {
        if (!res.ok) { showMsg(res.msg, true); return; }

        var list = document.getElementById('clientList');
        var empty = list.querySelector('p.text-muted');
        if (empty) empty.remove();

        var emailHtml = res.client.contact_email
            ? ' <span class="text-muted" style="font-size:11px;"> · ' + escapeHtml(res.client.contact_email) + '</span>'
            : '';

        var div = document.createElement('div');
        div.className = 'manage-row';
        div.dataset.id = res.client.id;
        div.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:var(--bg-alt);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:6px;font-size:13px;';
        div.innerHTML =
            '<span><strong class="text-bright">' + escapeHtml(res.client.name) + '</strong>' + emailHtml + '</span>' +
            '<button type="button" class="btn btn-ghost" style="padding:2px 8px;color:var(--red);" ' +
            'onclick="removeClient(' + res.client.id + ', \'' + escapeJs(res.client.name) + '\')">' +
            '<i class="fas fa-times"></i></button>';
        list.appendChild(div);

        var select = document.getElementById('client_id');
        if (select) {
            var opt = document.createElement('option');
            opt.value = res.client.id;
            opt.textContent = res.client.name;
            select.appendChild(opt);
        }

        nameEl.value = '';
        emailEl.value = '';
        phoneEl.value = '';
        showMsg('Client added.', false);
    });
}

function removeClient(id, name) {
    if (!confirm('Delete client "' + name + '"?')) return;
    ajaxPost({ manage: 'remove_client', id: id }).then(function (res) {
        if (!res.ok) { showMsg(res.msg, true); return; }
        var row = document.querySelector('#clientList .manage-row[data-id="' + id + '"]');
        if (row) row.remove();
        var select = document.getElementById('client_id');
        if (select) {
            var opt = select.querySelector('option[value="' + id + '"]');
            if (opt) opt.remove();
        }
        showMsg('Client deleted.', false);
    });
}

function addCategory() {
    var name = document.getElementById('newCatName').value.trim();
    if (!name) { showMsg('Category name is required.', true); return; }

    var payload = {
        manage: 'add_category',
        name: name,
        default_life_months:     document.getElementById('newCatLife').value || 36,
        default_salvage_pct:     document.getElementById('newCatSalvage').value || 10,
        default_reorder:         document.getElementById('newCatReorder').value || 10,
        default_warranty_months: document.getElementById('newCatWarranty').value || 12,
    };

    ajaxPost(payload).then(function (res) {
        if (!res.ok) { showMsg(res.msg, true); return; }
        var list = document.getElementById('categoryList');
        var empty = list.querySelector('p.text-muted');
        if (empty) empty.remove();

        var c = res.category;
        var div = document.createElement('div');
        div.className = 'manage-row';
        div.dataset.id = c.id;
        div.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:var(--bg-alt);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:6px;font-size:13px;';
        div.innerHTML =
            '<span><strong class="text-bright">' + escapeHtml(c.name) + '</strong>' +
            '<span class="text-muted" style="font-size:11px;"> · ' + c.default_life_months + 'mo life, ' + c.default_salvage_pct + '% salvage</span></span>' +
            '<button type="button" class="btn btn-ghost" style="padding:2px 8px;color:var(--red);" ' +
            'onclick="removeCategory(' + c.id + ', \'' + escapeJs(c.name) + '\')">' +
            '<i class="fas fa-times"></i></button>';
        list.appendChild(div);

        var catSelect = document.getElementById('category_id');
        if (catSelect) {
            var opt = document.createElement('option');
            opt.value = c.id;
            opt.textContent = c.name;
            opt.dataset.life = c.default_life_months;
            opt.dataset.salvage = c.default_salvage_pct;
            opt.dataset.reorder = c.default_reorder;
            opt.dataset.warranty = c.default_warranty_months;
            catSelect.appendChild(opt);
        }

        document.getElementById('newCatName').value = '';
        showMsg('Category added.', false);
    });
}

function removeCategory(id, name) {
    if (!confirm('Delete category "' + name + '"?')) return;
    ajaxPost({ manage: 'remove_category', id: id }).then(function (res) {
        if (!res.ok) { showMsg(res.msg, true); return; }
        var row = document.querySelector('#categoryList .manage-row[data-id="' + id + '"]');
        if (row) row.remove();
        var select = document.getElementById('category_id');
        if (select) {
            var opt = select.querySelector('option[value="' + id + '"]');
            if (opt) opt.remove();
        }
        showMsg('Category deleted.', false);
    });
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (m) {
        return ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' })[m];
    });
}
function escapeJs(s) {
    return String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
}
</script>
<?php endif; ?>

<?php include 'footer.php'; ?>
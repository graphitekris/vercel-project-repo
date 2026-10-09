<?php
require 'config.php';
require 'auth.php';
require 'queries.php';

$user = require_role('user');   // Only users have "My Assets"
$pageTitle = 'My Assets';
$activeNav = 'assets';

$stmt = db()->prepare('
    SELECT p.id AS product_id, p.name AS product_name, p.sku,
           p.warranty_until, p.lifecycle_stage, p.location, p.tools_included,
           SUM(a.quantity) AS total_qty,
           MIN(a.assigned_at) AS first_assigned,
           GROUP_CONCAT(DISTINCT a.remarks SEPARATOR "; ") AS remarks
    FROM assets a
    JOIN products p ON p.id = a.product_id
    WHERE a.assigned_to = ? AND a.status = "active"
    GROUP BY p.id
    ORDER BY first_assigned DESC
');
$stmt->execute([$user['id']]);
$myAssets = $stmt->fetchAll();

include 'header.php';
?>

<h2 style="margin-bottom:20px;font-size:18px;">My Assigned Assets</h2>

<div class="banner info">
    <i class="fas fa-laptop"></i>
    <span>
        These are the assets currently assigned to you.
        If something is wrong with an item, use <strong>Submit Report</strong>.
    </span>
</div>

<div class="flex-between mb-16">
    <div class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" placeholder="Search assets or SKU..." data-filter-target="#assetsTable" />
    </div>
    <a href="submit-report.php" class="btn btn-primary">
        <i class="fas fa-flag"></i> Report an Issue
    </a>
</div>

<div class="table-card">
    <table id="assetsTable">
        <thead>
            <tr>
                <th>Asset</th>
                <th>SKU</th>
                <th>Qty</th>
                <th>Assigned Since</th>
                <th>Warranty</th>
                <th>Location</th>
                <th>Stage</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$myAssets): ?>
                <tr><td colspan="8" class="text-muted">No assets assigned to you yet.</td></tr>
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
                    <td class="text-muted"><?= htmlspecialchars($a['location'] ?? '—') ?></td>
                    <td><span class="badge <?= $stageClass ?>"><?= ucwords(str_replace('_',' ',$a['lifecycle_stage'])) ?></span></td>
                    <td style="text-align:right;">
                        <a href="submit-report.php?asset=<?= (int)$a['product_id'] ?>" class="btn btn-outline" style="padding:4px 10px;font-size:12px;">
                            <i class="fas fa-flag"></i> Report
                        </a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php include 'footer.php'; ?>
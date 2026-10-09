<?php
require 'config.php';
require 'auth.php';

$user = require_login();

$pageTitle = 'Submit a Report';
$activeNav = 'submit-report';

$errors = [];
$flash  = '';

$isPrivileged = in_array($user['role'], ['admin','superadmin'], true);

/* Assets this user can attach a report to */
if ($isPrivileged) {
    $attachable = db()->query('
        SELECT id, sku, name FROM products
        WHERE status != "disposed"
        ORDER BY name
    ')->fetchAll();
} else {
    // Only the products this user has active assignments to
    $stmt = db()->prepare('
        SELECT DISTINCT p.id, p.sku, p.name
        FROM assets a
        JOIN products p ON p.id = a.product_id
        WHERE a.assigned_to = ? AND a.status = "active"
        ORDER BY p.name
    ');
    $stmt->execute([$user['id']]);
    $attachable = $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type      = $_POST['type'] ?? 'issue';
    $title     = trim($_POST['title'] ?? '');
    $desc      = trim($_POST['description'] ?? '');
    $productId = (int)($_POST['product_id'] ?? 0) ?: null;

    $validTypes = ['issue','request'];

    if (!in_array($type, $validTypes, true)) $errors[] = 'Invalid report type.';
    if ($title === '')                        $errors[] = 'Title is required.';
    if (strlen($title) > 180)                 $errors[] = 'Title is too long (max 180 characters).';

    if (!$errors) {
        try {
            $next = (int) db()->query("SELECT COALESCE(MAX(id), 0) + 1 FROM reports")->fetchColumn();
            $code = 'RPT-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);

            db()->prepare("
                INSERT INTO reports (report_code, source, type, title, remarks, status, created_by)
                VALUES (?, 'user', ?, ?, ?, 'pending', ?)
            ")->execute([
                $code, $type, $title, $desc ?: null, $user['id'],
            ]);

            log_action($user['id'], $user['username'], $user['role'],
                "Submitted {$type} report {$code} — {$title}",
                'reports', 'success');

            header('Location: submit-report.php?flash=submitted');
            exit;

        } catch (Throwable $e) {
            error_log('Submit report failed: ' . $e->getMessage());
            $errors[] = 'Could not submit your report. Please try again.';
        }
    }
}

if (isset($_GET['flash']) && $_GET['flash'] === 'submitted') {
    $flash = 'Report submitted. An Admin will review it.';
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

<div class="banner info">
    <i class="fas fa-flag"></i>
    <span>
        <strong>Submit a report.</strong> Use this form to report a problem (issue)
        or request a new item (request). Reports go to the Admin queue and you'll
        see the resolution on your dashboard.
    </span>
</div>

<div class="card" style="max-width:640px;margin:0 auto;">
    <div class="flex-between mb-20">
        <h3 style="margin:0;">New Report</h3>
        <a href="dashboard.php" class="btn btn-outline">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>

    <form method="POST">

        <div class="form-group">
            <label>Type <span class="text-red">*</span></label>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <?php $selectedType = $_POST['type'] ?? 'issue'; ?>
                <label style="flex:1;min-width:200px;display:flex;gap:12px;padding:14px 16px;border:1px solid <?= $selectedType === 'issue' ? 'var(--accent)' : 'var(--border)' ?>;border-radius:var(--radius);cursor:pointer;background:<?= $selectedType === 'issue' ? 'var(--accent-soft)' : 'transparent' ?>;">
                    <input type="radio" name="type" value="issue"
                           <?= $selectedType === 'issue' ? 'checked' : '' ?>
                           style="accent-color:var(--accent);margin-top:4px;" onchange="paintType(this)">
                    <div>
                        <div style="font-weight:600;color:var(--text-bright);font-size:13px;">
                            <i class="fas fa-exclamation-triangle"></i> Issue
                        </div>
                        <div class="text-muted" style="font-size:11px;margin-top:2px;">
                            Something is broken or not working.
                        </div>
                    </div>
                </label>
                <label style="flex:1;min-width:200px;display:flex;gap:12px;padding:14px 16px;border:1px solid <?= $selectedType === 'request' ? 'var(--accent)' : 'var(--border)' ?>;border-radius:var(--radius);cursor:pointer;background:<?= $selectedType === 'request' ? 'var(--accent-soft)' : 'transparent' ?>;">
                    <input type="radio" name="type" value="request"
                           <?= $selectedType === 'request' ? 'checked' : '' ?>
                           style="accent-color:var(--accent);margin-top:4px;" onchange="paintType(this)">
                    <div>
                        <div style="font-weight:600;color:var(--text-bright);font-size:13px;">
                            <i class="fas fa-plus-circle"></i> Request
                        </div>
                        <div class="text-muted" style="font-size:11px;margin-top:2px;">
                            Asking for something new or a change.
                        </div>
                    </div>
                </label>
            </div>
        </div>

        <div class="form-group">
            <label for="title">Title <span class="text-red">*</span></label>
            <div class="input-wrap">
                <i class="fas fa-heading icon"></i>
                <input type="text" id="title" name="title" required maxlength="180"
                       placeholder="e.g. My keyboard has a broken key"
                       value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" />
            </div>
        </div>

        <div class="form-group">
            <label for="description">Description</label>
            <div class="input-wrap">
                <i class="fas fa-align-left icon"></i>
                <input type="text" id="description" name="description"
                       placeholder="Optional details — what happened, when, error messages"
                       value="<?= htmlspecialchars($_POST['description'] ?? '') ?>" />
            </div>
        </div>

        <div class="form-group">
            <label for="product_id">Related Asset</label>
            <div class="input-wrap">
                <i class="fas fa-cube icon"></i>
                <select id="product_id" name="product_id" style="padding-left:40px;">
                    <option value="">— Not linked to a specific asset —</option>
                    <?php foreach ($attachable as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"
                            <?= (int)($_POST['product_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['sku']) ?> — <?= htmlspecialchars($p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <p class="text-muted" style="font-size:11px;margin-top:4px;">
                <?php if ($isPrivileged): ?>
                    Pick any product to link this report to.
                <?php else: ?>
                    Only your own assigned assets appear here.
                <?php endif; ?>
            </p>
        </div>

        <div class="flex" style="gap:10px;margin-top:12px;">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i> Submit Report
            </button>
            <a href="dashboard.php" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>

<script>
function paintType(radio) {
    document.querySelectorAll('input[name="type"]').forEach(function (r) {
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
</script>

<?php include 'footer.php'; ?>
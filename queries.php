<?php
require_once __DIR__ . '/config.php';

function q_inventory_value(): float {
    return (float) db()->query(
        "SELECT COALESCE(SUM(stock * unit_cost), 0) FROM products WHERE status != 'disposed'"
    )->fetchColumn();
}

function q_total_transactions(): int {
    return (int) db()->query("SELECT COUNT(*) FROM transactions")->fetchColumn();
}

function q_open_reports(): int {
    return (int) db()->query("SELECT COUNT(*) FROM reports WHERE status = 'pending'")->fetchColumn();
}

function q_low_stock(): array {
    return db()->query("
        SELECT sku, name, stock, reorder_level, status
        FROM products
        WHERE status IN ('low_stock','out_of_stock')
        ORDER BY FIELD(status, 'out_of_stock','low_stock'), (reorder_level - stock) DESC
    ")->fetchAll();
}

function q_user_assets(int $userId): array {
    $stmt = db()->prepare("
        SELECT a.*, p.name AS product_name, p.sku
        FROM assets a
        JOIN products p ON p.id = a.product_id
        WHERE a.assigned_to = ? AND a.status = 'active'
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function q_my_open_reports(int $userId): int {
    $stmt = db()->prepare("
        SELECT COUNT(*) FROM reports
        WHERE created_by = ? AND status = 'pending'
    ");
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function q_my_transactions(int $userId, int $limit = 50): array {
    $stmt = db()->prepare("
        SELECT t.*, p.name AS product_name
        FROM transactions t
        JOIN products p ON p.id = t.product_id
        WHERE t.created_by = ?
        ORDER BY t.created_at DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit,  PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}
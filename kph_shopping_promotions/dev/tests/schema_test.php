<?php
// ============================================================
// KameraPH | Commerce | Schema test suite
// ============================================================
// Verifies the migration manifest, tables, key columns, indexes,
// foreign-key rules and seed data. Requires MySQL.
//
// Usage: C:\xampp\php\php.exe kph_shopping_promotions\dev\tests\schema_test.php
// ============================================================

require_once __DIR__ . '/_lib.php';

suiteStart('Schema — migrations, columns, indexes, FKs, seed');

$pdo = db();

function columns(string $table): array
{
    $out = [];
    foreach (db()->query("SHOW COLUMNS FROM `$table`") as $c) {
        $out[$c['Field']] = $c;
    }
    return $out;
}

function hasIndex(string $table, string $index): bool
{
    $s = db()->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $s->execute([$table, $index]);
    return (int) $s->fetchColumn() > 0;
}

function fkDeleteRule(string $table, string $column): ?string
{
    $s = db()->prepare(
        "SELECT rc.DELETE_RULE
           FROM information_schema.REFERENTIAL_CONSTRAINTS rc
           JOIN information_schema.KEY_COLUMN_USAGE k
             ON k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
            AND k.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
          WHERE rc.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.COLUMN_NAME = ?
          LIMIT 1"
    );
    $s->execute([$table, $column]);
    $v = $s->fetchColumn();
    return $v === false ? null : (string) $v;
}

try {
    echo "-- schema_migrations...\n";
    $applied = $pdo->query("SELECT Version FROM `schema_migrations` ORDER BY Version")->fetchAll(PDO::FETCH_COLUMN);
    $expected = [
        '2026_11_admin_audit',
        '2026_11_catalog',
        '2026_11_orders',
        '2026_11_schema_migrations',
        '2026_11_sellers',
        '2026_11_support',
        '2026_11_user_activity',
    ];
    check('all 7 migrations are recorded', $applied === $expected, implode(', ', $applied));

    echo "-- Tables...\n";
    foreach (['schema_migrations', 'category', 'product', 'checkout_session', 'orders', 'order_items',
              'order_status_history', 'order_payment_history', 'seller_application',
              'seller_application_history', 'support_ticket', 'admin_audit_log', 'user_activity'] as $t) {
        check("table `$t` exists", (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($t))->fetchColumn() === 1);
    }

    echo "-- Key columns...\n";
    $productCols = columns('product');
    check('product.IsApproved exists', isset($productCols['IsApproved']));
    check('product.ApprovalStatus exists', isset($productCols['ApprovalStatus']));
    check('product.SellerID exists', isset($productCols['SellerID']));
    check('product.Status exists', isset($productCols['Status']));

    $orderCols = columns('orders');
    check('orders.SellerKey exists', isset($orderCols['SellerKey']));
    check('orders.SellerID exists', isset($orderCols['SellerID']));
    check('orders.CheckoutKey exists', isset($orderCols['CheckoutKey']));
    check('orders.PaymentStatus exists', isset($orderCols['PaymentStatus']));
    check('orders.OrderStatus exists', isset($orderCols['OrderStatus']));

    check('checkout_session.RequestHash exists', isset(columns('checkout_session')['RequestHash']));
    check('seller_application.CanReapply exists', isset(columns('seller_application')['CanReapply']));
    check('seller_application.ActiveFlag exists', isset(columns('seller_application')['ActiveFlag']));
    check('order_payment_history.Reference exists', isset(columns('order_payment_history')['Reference']));
    check('admin_audit_log.AdminID exists', isset(columns('admin_audit_log')['AdminID']));
    check('user_activity.Metadata exists', isset(columns('user_activity')['Metadata']));

    echo "-- Indexes...\n";
    check('idx_user_activity_CreatedAt exists', hasIndex('user_activity', 'idx_user_activity_CreatedAt'));
    check('idx_user_activity_User_Date exists', hasIndex('user_activity', 'idx_user_activity_User_Date'));
    check('idx_user_activity_Type exists', hasIndex('user_activity', 'idx_user_activity_Type'));

    echo "-- Foreign keys...\n";
    check('product.SellerID is ON DELETE RESTRICT', fkDeleteRule('product', 'SellerID') === 'RESTRICT', (string) fkDeleteRule('product', 'SellerID'));
    check('orders.SellerID is ON DELETE RESTRICT', fkDeleteRule('orders', 'SellerID') === 'RESTRICT', (string) fkDeleteRule('orders', 'SellerID'));
    check('order_items -> orders', fkDeleteRule('order_items', 'OrderID') === 'CASCADE', (string) fkDeleteRule('order_items', 'OrderID'));

    echo "-- Seed...\n";
    check('4 categories seeded', (int) $pdo->query("SELECT COUNT(*) FROM `category`")->fetchColumn() === 4);
    check('8 approved products seeded', (int) $pdo->query("SELECT COUNT(*) FROM `product` WHERE IsApproved = 1")->fetchColumn() === 8);
    check('seed ProductIDs 101-104 and 201-204 exist', (int) $pdo->query("SELECT COUNT(*) FROM `product` WHERE ProductID IN (101,102,103,104,201,202,203,204)")->fetchColumn() === 8);

} finally {
    suiteEnd();
}
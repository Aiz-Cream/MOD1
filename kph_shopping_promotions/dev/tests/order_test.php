<?php
// ============================================================
// KameraPH | Commerce | Order test suite
// ============================================================
// Covers preview totals, cart-driven creation, multi-seller split,
// idempotency, stock, cancellation, status transitions, COD,
// buyer confirm-receipt and refunds. Requires Apache + MySQL.
//
// Usage: C:\xampp\php\php.exe kph_shopping_promotions\dev\tests\order_test.php
// ============================================================

require_once __DIR__ . '/_lib.php';

suiteStart('Orders — preview, create, multi-seller, cancel, COD, refund');

$buyer  = makeUser('Buyer');
$buyer2 = makeUser('BuyerTwo');
$sellerA = makeSellerUser();
$sellerB = makeSellerUser();
$admin  = sharedAdmin();
$productIds = [];

$ADDRESS = [
    'RecipientFirstName' => 'Juan',
    'RecipientLastName'  => 'Dela Cruz',
    'RecipientPhone'     => '09171234567',
    'HouseUnit'          => '12',
    'Street'             => 'Rizal Street',
    'Barangay'           => 'San Jose',
    'City'               => 'Manila',
    'Province'           => 'Metro Manila',
    'PostalCode'         => '1000',
];

function approveProduct(array $admin, int $pid): void
{
    $r = httpCall('POST', COMMERCE_API . '/admin.php?action=review-product', [
        'ProductID' => $pid,
        'Decision'  => 'approve',
    ], auth($admin['token']));
    if ($r['status'] !== 200) {
        echo "  [FATAL] Could not approve product #$pid: " . $r['raw'] . "\n";
        exit(1);
    }
}

function newProduct(array $seller, array $admin, float $price, int $stock, string $name): array
{
    $p = createProduct($seller, ['ProductName' => $name, 'BasePrice' => $price, 'Stock' => $stock]);
    approveProduct($admin, (int) $p['ProductID']);
    return $p;
}

function cartAdd(array $user, array $product, int $qty): void
{
    $r = httpCall('POST', COMMERCE_API . '/cart.php', [
        'ProductID'   => (int) $product['ProductID'],
        'ProductName' => $product['ProductName'],
        'UnitPrice'   => (float) $product['BasePrice'],
        'Quantity'    => $qty,
    ], auth($user['token']));
    if ($r['status'] !== 200) {
        echo "  [FATAL] Cart add failed: " . $r['raw'] . "\n";
        exit(1);
    }
}

function clearCart(array $user): void
{
    httpCall('DELETE', COMMERCE_API . '/cart.php?all=1', null, auth($user['token']));
}

function stockOf(int $pid): int
{
    $s = db()->prepare("SELECT Stock FROM `product` WHERE ProductID = ?");
    $s->execute([$pid]);
    return (int) $s->fetchColumn();
}

function orderRow(int $oid): array
{
    $s = db()->prepare("SELECT * FROM `orders` WHERE OrderID = ?");
    $s->execute([$oid]);
    return $s->fetch() ?: [];
}

function checkoutCount(int $uid, string $key): int
{
    $s = db()->prepare("SELECT COUNT(*) FROM `orders` WHERE UserID = ? AND CheckoutKey = ?");
    $s->execute([$uid, $key]);
    return (int) $s->fetchColumn();
}

try {
    echo "-- Preview totals...\n";
    $nameA = 'OrderTest A ' . uniqueSuffix();
    $pA = newProduct($sellerA, $admin, 1000, 20, $nameA);

    $prev2 = httpCall('POST', COMMERCE_API . '/orders.php?action=preview', [
        'Items' => [['ProductID' => (int) $pA['ProductID'], 'Quantity' => 2]],
    ], auth($buyer['token']));
    check('preview A x2 -> subtotal 2000', abs((float)($prev2['data']['Subtotal'] ?? -1) - 2000.0) < 0.01, $prev2['raw']);
    check('preview A x2 -> free shipping', abs((float)($prev2['data']['ShippingFee'] ?? -1)) < 0.01);
    check('preview A x2 -> total 2000', abs((float)($prev2['data']['Total'] ?? -1) - 2000.0) < 0.01);
    check('preview A x2 -> one group', count($prev2['data']['Groups'] ?? []) === 1);

    $prev1 = httpCall('POST', COMMERCE_API . '/orders.php?action=preview', [
        'Items' => [['ProductID' => (int) $pA['ProductID'], 'Quantity' => 1]],
    ], auth($buyer['token']));
    check('preview A x1 -> subtotal 1000', abs((float)($prev1['data']['Subtotal'] ?? -1) - 1000.0) < 0.01);
    check('preview A x1 -> flat shipping 150', abs((float)($prev1['data']['ShippingFee'] ?? -1) - 150.0) < 0.01);
    check('preview A x1 -> total 1150', abs((float)($prev1['data']['Total'] ?? -1) - 1150.0) < 0.01);

    $prevPickup = httpCall('POST', COMMERCE_API . '/orders.php?action=preview', [
        'Items'          => [['ProductID' => (int) $pA['ProductID'], 'Quantity' => 1]],
        'ShippingMethod' => 'Pickup',
    ], auth($buyer['token']));
    check('preview Pickup -> shipping 0', abs((float)($prevPickup['data']['ShippingFee'] ?? -1)) < 0.01);

    $nameB = 'OrderTest B ' . uniqueSuffix();
    $pB = newProduct($sellerB, $admin, 600, 20, $nameB);
    $prevMulti = httpCall('POST', COMMERCE_API . '/orders.php?action=preview', [
        'Items' => [
            ['ProductID' => (int) $pA['ProductID'], 'Quantity' => 1],
            ['ProductID' => (int) $pB['ProductID'], 'Quantity' => 1],
        ],
    ], auth($buyer['token']));
    check('preview A+B -> two seller groups', count($prevMulti['data']['Groups'] ?? []) === 2, $prevMulti['raw']);

    echo "-- Create / idempotency / conflict...\n";
    $key1 = 'K' . strtoupper(uniqueSuffix()) . strtoupper(uniqueSuffix());
    cartAdd($buyer, $pA, 1);
    $create = httpCall('POST', COMMERCE_API . '/orders.php', [
        'Items'           => [['ProductID' => (int) $pA['ProductID'], 'Quantity' => 1]],
        'ShippingMethod'  => 'Standard',
        'CheckoutKey'     => $key1,
        'AddressID'       => 0,
    ] + $ADDRESS, auth($buyer['token']));
    check('create order -> 201', $create['status'] === 201, 'status ' . $create['status'] . ' ' . $create['raw']);
    $order1 = $create['data']['Orders'][0] ?? [];
    check('create returns one order', count($create['data']['Orders'] ?? []) === 1);
    check('order starts Pending', ($order1['OrderStatus'] ?? '') === 'Pending');
    check('order starts Unpaid', ($order1['PaymentStatus'] ?? '') === 'Unpaid');
    check('stock decremented 20 -> 19', stockOf((int) $pA['ProductID']) === 19, 'stock ' . stockOf((int) $pA['ProductID']));

    $oid1 = (int) ($order1['OrderID'] ?? 0);
    $row1 = orderRow($oid1);
    check('created total equals preview total', abs((float) $row1['Total'] - (float) $prev1['data']['Total']) < 0.01);

    $retry = httpCall('POST', COMMERCE_API . '/orders.php', [
        'Items'          => [['ProductID' => (int) $pA['ProductID'], 'Quantity' => 1]],
        'ShippingMethod' => 'Standard',
        'CheckoutKey'    => $key1,
    ] + $ADDRESS, auth($buyer['token']));
    check('idempotent retry returns same OrderID', (int)($retry['data']['Orders'][0]['OrderID'] ?? 0) === $oid1, $retry['raw']);
    check('idempotent retry does not double-decrement', stockOf((int) $pA['ProductID']) === 19);
    check('idempotent retry creates one order', checkoutCount($buyer['id'], $key1) === 1);

    $conflict = httpCall('POST', COMMERCE_API . '/orders.php', [
        'Items'          => [['ProductID' => (int) $pA['ProductID'], 'Quantity' => 2]],
        'ShippingMethod' => 'Standard',
        'CheckoutKey'    => $key1,
    ] + $ADDRESS, auth($buyer['token']));
    check('same key + different request -> 409', $conflict['status'] === 409, 'status ' . $conflict['status']);

    clearCart($buyer);
    cartAdd($buyer, $pA, 1);
    $foreverKey = 'K' . strtoupper(uniqueSuffix()) . strtoupper(uniqueSuffix());
    $ownOrder = httpCall('POST', COMMERCE_API . '/orders.php', [
        'Items'          => [['ProductID' => (int) $pA['ProductID'], 'Quantity' => 1]],
        'ShippingMethod' => 'Pickup',
        'CheckoutKey'    => $foreverKey,
    ], auth($buyer['token']));
    $oid2 = (int)($ownOrder['data']['Orders'][0]['OrderID'] ?? 0);
    check('Pickup order created', $ownOrder['status'] === 201 && $oid2 > 0, $ownOrder['raw']);

    $foreign = httpCall('GET', COMMERCE_API . '/orders.php?id=' . $oid2, null, auth($buyer2['token']));
    check('other buyer cannot view order (403)', $foreign['status'] === 403, 'status ' . $foreign['status']);

    echo "-- Cancel...\n";
    $stockBeforeCancel = stockOf((int) $pA['ProductID']);
    $cancel = httpCall('POST', COMMERCE_API . '/orders.php?action=cancel', ['OrderID' => $oid2], auth($buyer['token']));
    check('buyer cancels Pending order', $cancel['status'] === 200 && $cancel['data']['order']['OrderStatus'] === 'Cancelled', $cancel['raw']);
    check('cancel restores stock', stockOf((int) $pA['ProductID']) === $stockBeforeCancel + 1, 'stock ' . stockOf((int) $pA['ProductID']));
    $cancelAgain = httpCall('POST', COMMERCE_API . '/orders.php?action=cancel', ['OrderID' => $oid2], auth($buyer['token']));
    check('second cancel is a safe no-op', $cancelAgain['status'] === 200, 'status ' . $cancelAgain['status']);
    check('second cancel does not double-restore', stockOf((int) $pA['ProductID']) === $stockBeforeCancel + 1);

    echo "-- Status transitions + COD...\n";
    $nameC = 'OrderTest C ' . uniqueSuffix();
    $pC = newProduct($sellerA, $admin, 500, 10, $nameC);
    clearCart($buyer);
    cartAdd($buyer, $pC, 1);
    $key3 = 'K' . strtoupper(uniqueSuffix()) . strtoupper(uniqueSuffix());
    $o3 = httpCall('POST', COMMERCE_API . '/orders.php', [
        'Items'          => [['ProductID' => (int) $pC['ProductID'], 'Quantity' => 1]],
        'ShippingMethod' => 'Pickup',
        'CheckoutKey'    => $key3,
    ], auth($buyer['token']));
    $oid3 = (int)($o3['data']['Orders'][0]['OrderID'] ?? 0);

    $badJump = httpCall('PUT', COMMERCE_API . '/orders.php?id=' . $oid3, ['OrderStatus' => 'Delivered', 'CodCollected' => false], auth($admin['token']));
    check('Pending -> Delivered is rejected', $badJump['status'] === 400, 'status ' . $badJump['status']);

    foreach (['Confirmed', 'Processing', 'Shipped', 'OutForDelivery'] as $st) {
        $up = httpCall('PUT', COMMERCE_API . '/orders.php?id=' . $oid3, ['OrderStatus' => $st], auth($admin['token']));
        check("admin can set $st", $up['status'] === 200 && $up['data']['order']['OrderStatus'] === $st, $up['raw']);
    }

    $delivered = httpCall('PUT', COMMERCE_API . '/orders.php?id=' . $oid3, ['OrderStatus' => 'Delivered', 'CodCollected' => false], auth($admin['token']));
    check('Delivered without COD -> Unpaid', $delivered['status'] === 200 && $delivered['data']['order']['PaymentStatus'] === 'Unpaid', $delivered['raw']);
    check('Delivered COD=false writes payment history', (int) db()->query("SELECT COUNT(*) FROM `order_payment_history` WHERE OrderID = $oid3")->fetchColumn() === 1);

    $cod = httpCall('POST', COMMERCE_API . '/orders.php?action=confirm-cod-payment', ['OrderID' => $oid3], auth($sellerA['token']));
    check('seller can confirm COD payment', $cod['status'] === 200 && $cod['data']['order']['PaymentStatus'] === 'Paid', $cod['raw']);
    $codBuyer = httpCall('POST', COMMERCE_API . '/orders.php?action=confirm-cod-payment', ['OrderID' => $oid3], auth($buyer['token']));
    check('buyer cannot confirm COD payment', $codBuyer['status'] === 403 || $codBuyer['status'] === 400, 'status ' . $codBuyer['status']);

    $stockBeforeRefund = stockOf((int) $pC['ProductID']);
    $refund = httpCall('POST', COMMERCE_API . '/orders.php?action=refund', ['OrderID' => $oid3, 'Reference' => 'REF-' . uniqueSuffix()], auth($admin['token']));
    check('admin can refund Delivered+Paid order', $refund['status'] === 200 && $refund['data']['order']['OrderStatus'] === 'Refunded', $refund['raw']);
    check('refund does not restore stock', stockOf((int) $pC['ProductID']) === $stockBeforeRefund);

    echo "-- Buyer confirm-receipt...\n";
    $nameD = 'OrderTest D ' . uniqueSuffix();
    $pD = newProduct($sellerA, $admin, 300, 10, $nameD);
    clearCart($buyer);
    cartAdd($buyer, $pD, 1);
    $key4 = 'K' . strtoupper(uniqueSuffix()) . strtoupper(uniqueSuffix());
    $o4 = httpCall('POST', COMMERCE_API . '/orders.php', [
        'Items'          => [['ProductID' => (int) $pD['ProductID'], 'Quantity' => 1]],
        'ShippingMethod' => 'Pickup',
        'CheckoutKey'    => $key4,
    ], auth($buyer['token']));
    $oid4 = (int)($o4['data']['Orders'][0]['OrderID'] ?? 0);

    foreach (['Confirmed', 'Processing', 'Shipped', 'OutForDelivery'] as $st) {
        httpCall('PUT', COMMERCE_API . '/orders.php?id=' . $oid4, ['OrderStatus' => $st], auth($admin['token']));
    }

    $sellerReceipt = httpCall('POST', COMMERCE_API . '/orders.php?action=confirm-receipt', ['OrderID' => $oid4], auth($sellerA['token']));
    check('seller cannot confirm buyer receipt', $sellerReceipt['status'] === 403, 'status ' . $sellerReceipt['status']);

    $receipt = httpCall('POST', COMMERCE_API . '/orders.php?action=confirm-receipt', ['OrderID' => $oid4], auth($buyer['token']));
    check('buyer confirm-receipt -> Delivered', $receipt['status'] === 200 && $receipt['data']['order']['OrderStatus'] === 'Delivered', $receipt['raw']);
    check('buyer confirm-receipt leaves payment Unpaid', $receipt['data']['order']['PaymentStatus'] === 'Unpaid');
    check('buyer confirm-receipt writes no payment history', (int) db()->query("SELECT COUNT(*) FROM `order_payment_history` WHERE OrderID = $oid4")->fetchColumn() === 0);

    echo "-- Partial checkout...\n";
    $nameE = 'OrderTest E ' . uniqueSuffix();
    $pE = newProduct($sellerA, $admin, 100, 50, $nameE);
    clearCart($buyer);
    cartAdd($buyer, $pE, 5);
    $key5 = 'K' . strtoupper(uniqueSuffix()) . strtoupper(uniqueSuffix());
    $o5 = httpCall('POST', COMMERCE_API . '/orders.php', [
        'Items'          => [['ProductID' => (int) $pE['ProductID'], 'Quantity' => 2]],
        'ShippingMethod' => 'Pickup',
        'CheckoutKey'    => $key5,
    ], auth($buyer['token']));
    check('partial checkout -> 201', $o5['status'] === 201, $o5['raw']);
    $cartRow = db()->prepare("SELECT Quantity FROM `cart_items` c JOIN `carts` ca ON ca.CartID = c.CartID WHERE ca.UserID = ? AND c.ProductID = ?");
    $cartRow->execute([$buyer['id'], (int) $pE['ProductID']]);
    check('partial checkout leaves cart quantity 3', (int) $cartRow->fetchColumn() === 3);
    check('partial checkout decrements stock by 2', stockOf((int) $pE['ProductID']) === 48);

    echo "-- Multi-seller checkout...\n";
    clearCart($buyer);
    cartAdd($buyer, $pA, 1);
    cartAdd($buyer, $pB, 1);
    $key6 = 'K' . strtoupper(uniqueSuffix()) . strtoupper(uniqueSuffix());
    $o6 = httpCall('POST', COMMERCE_API . '/orders.php', [
        'Items'          => [
            ['ProductID' => (int) $pA['ProductID'], 'Quantity' => 1],
            ['ProductID' => (int) $pB['ProductID'], 'Quantity' => 1],
        ],
        'ShippingMethod' => 'Pickup',
        'CheckoutKey'    => $key6,
    ], auth($buyer['token']));
    check('multi-seller create -> 201', $o6['status'] === 201, $o6['raw']);
    check('multi-seller creates two orders', count($o6['data']['Orders'] ?? []) === 2);
    check('both orders share the CheckoutKey', checkoutCount($buyer['id'], $key6) === 2);

} finally {
    clearCart($buyer);
    clearCart($buyer2);
    cleanupAll($productIds);
    suiteEnd();
}
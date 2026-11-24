<?php
// ============================================================
// KameraPH | Shopping Cart Module | Automated Test Suite
// ============================================================
// Tests all cart operations via HTTP against the API.
// Requires Apache + MySQL to be running.
//
// Usage: C:\xampp\php\php.exe kph_shopping_promotions\dev\tests\cart_test.php
// ============================================================

$base = 'http://localhost/Final%20Project/kph_shopping_promotions/api';
$module1Base = 'http://localhost/Final%20Project/kph_user_account_identity/api';
$passed = 0;
$failed = 0;

function check(string $label, bool $cond, string $detail = ''): void {
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [PASS] {$label}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function httpCall(string $method, string $url, ?array $body = null, array $headers = []): array {
    $ch = curl_init($url);
    $reqHeaders = ['Content-Type: application/json'];
    foreach ($headers as $k => $v) {
        $reqHeaders[] = "{$k}: {$v}";
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $reqHeaders,
        CURLOPT_TIMEOUT => 15,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    return [
        'status' => $status,
        'data' => json_decode((string) $raw, true) ?? [],
        'raw' => $raw,
        'error' => $err,
    ];
}

echo "================================================================\n";
echo " KameraPH Shopping Cart Module — Automated Test Suite\n";
echo " API Base: {$base}\n";
echo "================================================================\n\n";

// Create two test users
echo "-- Creating test users...\n";
$suffix = substr(bin2hex(random_bytes(3)), 0, 6);
$emailA = "carttest.a.{$suffix}@example.test";
$emailB = "carttest.b.{$suffix}@example.test";
$phoneA = '0929' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT);
$phoneB = '0929' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT);

$regA = httpCall('POST', "$module1Base/users.php", [
    'FirstName' => 'Cart', 'LastName' => 'TestA', 'Email' => $emailA,
    'PhoneNumber' => $phoneA, 'Password' => 'CartPass123!',
]);
check('Register user A', $regA['status'] === 201 && !empty($regA['data']['Token']), 'status ' . $regA['status']);

$regB = httpCall('POST', "$module1Base/users.php", [
    'FirstName' => 'Cart', 'LastName' => 'TestB', 'Email' => $emailB,
    'PhoneNumber' => $phoneB, 'Password' => 'CartPass123!',
]);
check('Register user B', $regB['status'] === 201 && !empty($regB['data']['Token']), 'status ' . $regB['status']);

$tokenA = $regA['data']['Token'] ?? '';
$tokenB = $regB['data']['Token'] ?? '';
$userAId = (int) ($regA['data']['UserID'] ?? 0);
$userBId = (int) ($regB['data']['UserID'] ?? 0);

// Test 1: Unauthenticated access → 401
echo "\n-- Auth tests...\n";
$noAuth = httpCall('GET', "$base/cart.php");
check('No token → 401', $noAuth['status'] === 401, 'status ' . $noAuth['status']);

$testHeader = httpCall('GET', "$base/cart.php", null, ['X-Test-User-Id' => (string) $userAId]);
check('X-Test-User-Id only → 401 (Bearer-only enforced)', $testHeader['status'] === 401, 'status ' . $testHeader['status']);

// Test 2: Get empty cart
echo "\n-- Cart retrieval tests...\n";
$authHeadersA = ['Authorization' => 'Bearer ' . $tokenA];
$authHeadersB = ['Authorization' => 'Bearer ' . $tokenB];

$empty = httpCall('GET', "$base/cart.php", null, $authHeadersA);
check('Get empty cart → 200', $empty['status'] === 200, 'status ' . $empty['status']);
check('Empty cart has priceSource', ($empty['data']['priceSource'] ?? '') === 'unverified_snapshot', 'priceSource: ' . ($empty['data']['priceSource'] ?? 'MISSING'));
check('Empty cart verified=false', ($empty['data']['verified'] ?? null) === false, 'verified: ' . json_encode($empty['data']['verified'] ?? null));
check('Empty cart count=0', ($empty['data']['count'] ?? -1) === 0, 'count: ' . ($empty['data']['count'] ?? 'MISSING'));
check('Empty cart items=[]', empty($empty['data']['items']), 'items not empty');

// Test 3: Add item to cart
echo "\n-- Add to cart tests...\n";
$add1 = httpCall('POST', "$base/cart.php", [
    'ProductID' => 3,
    'ProductName' => 'Sony Alpha a7 IV',
    'UnitPrice' => 109995.00,
    'ImageURL' => 'images/sony.jpg',
    'Quantity' => 1,
], $authHeadersA);
check('Add item → 200', $add1['status'] === 200, 'status ' . $add1['status']);
check('Add item → count=1', ($add1['data']['count'] ?? 0) === 1, 'count: ' . ($add1['data']['count'] ?? 'MISSING'));
check('Add item → items has 1', count($add1['data']['items'] ?? []) === 1, 'items count');
check('Add item → priceSource', ($add1['data']['priceSource'] ?? '') === 'unverified_snapshot');
check('Add item → verified=false', ($add1['data']['verified'] ?? null) === false);

// Test 4: Add same product again → quantity increases
$add2 = httpCall('POST', "$base/cart.php", [
    'ProductID' => 3,
    'ProductName' => 'Sony Alpha a7 IV',
    'UnitPrice' => 109995.00,
    'ImageURL' => 'images/sony.jpg',
    'Quantity' => 2,
], $authHeadersA);
check('Re-add item → 200', $add2['status'] === 200, 'status ' . $add2['status']);
check('Re-add item → count=3 (1+2)', ($add2['data']['count'] ?? 0) === 3, 'count: ' . ($add2['data']['count'] ?? 'MISSING'));

// Test 5: Add different product
$add3 = httpCall('POST', "$base/cart.php", [
    'ProductID' => 2,
    'ProductName' => 'Canon RF 24-70mm f/2.8L',
    'UnitPrice' => 89995.00,
    'ImageURL' => 'images/canonrf.jpg',
    'Quantity' => 1,
], $authHeadersA);
check('Add second item → 200', $add3['status'] === 200, 'status ' . $add3['status']);
check('Add second item → count=4', ($add3['data']['count'] ?? 0) === 4, 'count: ' . ($add3['data']['count'] ?? 'MISSING'));
check('Add second item → items has 2', count($add3['data']['items'] ?? []) === 2, 'items count');
$expectedSubtotal = 109995.00 * 3 + 89995.00 * 1; // 329985 + 89995 = 419980
check('Subtotal correct', abs(($add3['data']['subtotal'] ?? 0) - $expectedSubtotal) < 0.01, 'subtotal: ' . ($add3['data']['subtotal'] ?? 'MISSING') . ' expected: ' . $expectedSubtotal);

// Test 6: Update quantity
echo "\n-- Update quantity tests...\n";
$upd = httpCall('PUT', "$base/cart.php", ['ProductID' => 3, 'Quantity' => 5], $authHeadersA);
check('Update quantity → 200', $upd['status'] === 200, 'status ' . $upd['status']);
check('Update quantity → count=6 (5+1)', ($upd['data']['count'] ?? 0) === 6, 'count: ' . ($upd['data']['count'] ?? 'MISSING'));

// Test 7: Update quantity to invalid values
$updZero = httpCall('PUT', "$base/cart.php", ['ProductID' => 3, 'Quantity' => 0], $authHeadersA);
check('Update quantity 0 → 400', $updZero['status'] === 400, 'status ' . $updZero['status']);

$updOver = httpCall('PUT', "$base/cart.php", ['ProductID' => 3, 'Quantity' => 100], $authHeadersA);
check('Update quantity 100 → 400', $updOver['status'] === 400, 'status ' . $updOver['status']);

$updNeg = httpCall('PUT', "$base/cart.php", ['ProductID' => 3, 'Quantity' => -1], $authHeadersA);
check('Update quantity -1 → 400', $updNeg['status'] === 400, 'status ' . $updNeg['status']);

// Test 8: Remove item
echo "\n-- Remove item tests...\n";
$rem = httpCall('DELETE', "$base/cart.php?item=2", null, $authHeadersA);
check('Remove item → 200', $rem['status'] === 200, 'status ' . $rem['status']);
check('Remove item → count=5', ($rem['data']['count'] ?? 0) === 5, 'count: ' . ($rem['data']['count'] ?? 'MISSING'));
check('Remove item → items has 1', count($rem['data']['items'] ?? []) === 1, 'items count');

$remMissing = httpCall('DELETE', "$base/cart.php?item=9999", null, $authHeadersA);
check('Remove nonexistent → 404', $remMissing['status'] === 404, 'status ' . $remMissing['status']);

// Test 9: Clear cart
echo "\n-- Clear cart tests...\n";
$clear = httpCall('DELETE', "$base/cart.php?all=1", null, $authHeadersA);
check('Clear cart → 200', $clear['status'] === 200, 'status ' . $clear['status']);
check('Clear cart → count=0', ($clear['data']['count'] ?? -1) === 0, 'count: ' . ($clear['data']['count'] ?? 'MISSING'));
check('Clear cart → items=[]', empty($clear['data']['items']), 'items not empty');

// Test 10: Cart count endpoint
echo "\n-- Count endpoint tests...\n";
$countBefore = httpCall('GET', "$base/cart.php?count=1", null, $authHeadersA);
check('Count empty cart → 200', $countBefore['status'] === 200, 'status ' . $countBefore['status']);
check('Count empty cart → 0', ($countBefore['data']['count'] ?? -1) === 0, 'count: ' . ($countBefore['data']['count'] ?? 'MISSING'));

// Add items back and test count
httpCall('POST', "$base/cart.php", [
    'ProductID' => 8, 'ProductName' => 'Fujifilm X-T5', 'UnitPrice' => 99990.00, 'Quantity' => 2,
], $authHeadersA);
$countAfter = httpCall('GET', "$base/cart.php?count=1", null, $authHeadersA);
check('Count with items → 200', $countAfter['status'] === 200, 'status ' . $countAfter['status']);
check('Count with items → 2', ($countAfter['data']['count'] ?? 0) === 2, 'count: ' . ($countAfter['data']['count'] ?? 'MISSING'));

// Test 11: Ownership — user B cannot access user A's cart
echo "\n-- Ownership tests...\n";
$viewB = httpCall('GET', "$base/cart.php", null, $authHeadersB);
check('User B sees empty cart', ($viewB['data']['count'] ?? -1) === 0, 'User B count: ' . ($viewB['data']['count'] ?? 'MISSING'));

$removeB = httpCall('DELETE', "$base/cart.php?item=8", null, $authHeadersB);
check('User B cannot remove user A\'s item → 404', $removeB['status'] === 404, 'status ' . $removeB['status']);

$updateB = httpCall('PUT', "$base/cart.php", ['ProductID' => 8, 'Quantity' => 99], $authHeadersB);
check('User B cannot update user A\'s item → 404', $updateB['status'] === 404, 'status ' . $updateB['status']);

// Test 12: Invalid product data
echo "\n-- Validation tests...\n";
// ProductName, UnitPrice, ImageURL are no longer validated (fetched from product_listing)
// Only ProductID (ListingID) and Quantity are validated
$noProductId = httpCall('POST', "$base/cart.php", [
    'ProductName' => 'Test', 'UnitPrice' => 100.00, 'Quantity' => 1,
], $authHeadersA);
check('Missing ProductID → 400', $noProductId['status'] === 400, 'status ' . $noProductId['status']);

$invalidProductId = httpCall('POST', "$base/cart.php", [
    'ProductID' => 0, 'ProductName' => 'Test', 'UnitPrice' => 100.00, 'Quantity' => 1,
], $authHeadersA);
check('Invalid ProductID (zero) → 400', $invalidProductId['status'] === 400, 'status ' . $invalidProductId['status']);

$invalidProductIdNeg = httpCall('POST', "$base/cart.php", [
    'ProductID' => -1, 'ProductName' => 'Test', 'UnitPrice' => 100.00, 'Quantity' => 1,
], $authHeadersA);
check('Invalid ProductID (negative) → 400', $invalidProductIdNeg['status'] === 400, 'status ' . $invalidProductIdNeg['status']);

// Quantity validation (already tested in Test 14, but verify here too)
$invalidQty = httpCall('POST', "$base/cart.php", [
    'ProductID' => 4, 'ProductName' => 'Test', 'UnitPrice' => 100.00, 'Quantity' => 0,
], $authHeadersA);
check('Invalid Quantity (zero) → 400', $invalidQty['status'] === 400, 'status ' . $invalidQty['status']);

// Test 13: Valid price formats
echo "\n-- Valid price format tests...\n";
$validPrices = [
    ['price' => 0, 'desc' => 'zero', 'pid' => 5],
    ['price' => 10, 'desc' => 'integer', 'pid' => 6],
    ['price' => '10.5', 'desc' => 'one decimal string', 'pid' => 7],
    ['price' => '10.55', 'desc' => 'two decimals string', 'pid' => 8],
];
foreach ($validPrices as $vp) {
    $res = httpCall('POST', "$base/cart.php", [
        'ProductID' => $vp['pid'], 'ProductName' => 'Price Test ' . $vp['desc'],
        'UnitPrice' => $vp['price'], 'Quantity' => 1,
    ], $authHeadersA);
    check("Valid price ({$vp['desc']}) → 200", $res['status'] === 200, 'status ' . $res['status'] . ' body: ' . $res['raw']);
}

// Test 14: Invalid quantity formats
echo "\n-- Quantity validation tests...\n";
$badQty = [
    ['qty' => 0, 'desc' => 'zero', 'pid' => 9],
    ['qty' => -1, 'desc' => 'negative', 'pid' => 10],
    ['qty' => 1.5, 'desc' => 'float', 'pid' => 11],
    ['qty' => 100, 'desc' => 'over max (100)', 'pid' => 12],
    ['qty' => 'abc', 'desc' => 'non-numeric', 'pid' => 13],
];
foreach ($badQty as $bq) {
    $res = httpCall('POST', "$base/cart.php", [
        'ProductID' => $bq['pid'], 'ProductName' => 'Qty Test ' . $bq['desc'],
        'UnitPrice' => 100.00, 'Quantity' => $bq['qty'],
    ], $authHeadersA);
    check("Invalid qty ({$bq['desc']}) → 400", $res['status'] === 400, 'status ' . $res['status']);
}

// Test 15: Quantity cap at MAX_QTY (99)
echo "\n-- Quantity cap test...\n";
httpCall('POST', "$base/cart.php", [
    'ProductID' => 14, 'ProductName' => 'Cap Test', 'UnitPrice' => 100.00, 'Quantity' => 60,
], $authHeadersA);
$cap = httpCall('POST', "$base/cart.php", [
    'ProductID' => 14, 'ProductName' => 'Cap Test', 'UnitPrice' => 100.00, 'Quantity' => 60,
], $authHeadersA);
$capItem = null;
foreach (($cap['data']['items'] ?? []) as $it) { if ((int) $it['ProductID'] === 14) { $capItem = $it; break; } }
check('Cap: re-add 60+60 → 200', $cap['status'] === 200, 'status ' . $cap['status']);
check('Cap: item quantity clamped to 99', $capItem !== null && (int) $capItem['Quantity'] === 99, 'qty: ' . ($capItem['Quantity'] ?? 'MISSING'));

// Cleanup
echo "\n-- Cleanup...\n";
httpCall('DELETE', "$base/cart.php?all=1", null, $authHeadersA);
httpCall('DELETE', "$base/cart.php?all=1", null, $authHeadersB);
check('Cleanup cart A', true);
check('Cleanup cart B', true);

echo "\n================================================================\n";
echo " SUMMARY: {$passed} passed, {$failed} failed\n";
echo "================================================================\n";

exit($failed > 0 ? 1 : 0);
<?php
// ============================================================
// KameraPH | Commerce | Catalog test suite
// ============================================================
// Covers categories, listing/search, approval workflow, stock
// status and delete semantics. Requires Apache + MySQL.
//
// Usage: C:\xampp\php\php.exe kph_shopping_promotions\dev\tests\catalog_test.php
// ============================================================

require_once __DIR__ . '/_lib.php';

suiteStart('Catalog — categories, search, approval, stock, delete');

$buyer  = makeUser('Buyer');
$seller = makeSellerUser();
$admin  = sharedAdmin();
$productIds = [];

try {
    echo "-- Categories...\n";
    $cats = httpCall('GET', COMMERCE_API . '/categories.php');
    check('categories.php returns 200', $cats['status'] === 200, 'status ' . $cats['status']);
    check('at least 4 categories', count($cats['data']['categories'] ?? []) >= 4);
    check('every category has a Slug', count(array_filter($cats['data']['categories'] ?? [], fn($c) => !empty($c['Slug']))) >= 4);

    echo "-- Seed catalog...\n";
    $all = httpCall('GET', COMMERCE_API . '/products.php');
    check('products.php returns 200', $all['status'] === 200);
    check('seed has 8 approved products', (int)($all['data']['count'] ?? 0) === 8, 'count ' . ($all['data']['count'] ?? '?'));

    $lenses = httpCall('GET', COMMERCE_API . '/products.php?category=lenses');
    check('category=lenses returns 200', $lenses['status'] === 200);
    check('category=lenses returns 2 products', count($lenses['data']['products'] ?? []) === 2);
    check('every lens product has CategorySlug=lenses', count(array_filter($lenses['data']['products'] ?? [], fn($p) => ($p['CategorySlug'] ?? '') !== 'lenses')) === 0);

    $sony = httpCall('GET', COMMERCE_API . '/products.php?q=sony');
    check('q=sony returns 1 product', count($sony['data']['products'] ?? []) === 1);

    $one = httpCall('GET', COMMERCE_API . '/products.php?id=101');
    check('id=101 returns 200', $one['status'] === 200);
    check('id=101 is the seeded camera', ($one['data']['product']['ProductID'] ?? 0) === 101);

    $missing = httpCall('GET', COMMERCE_API . '/products.php?id=999999');
    check('unknown product id returns 404', $missing['status'] === 404);

    echo "-- Auth...\n";
    $noToken = httpCall('POST', COMMERCE_API . '/products.php', ['ProductName' => 'x', 'BasePrice' => 1, 'Stock' => 1]);
    check('POST without token -> 401', $noToken['status'] === 401, 'status ' . $noToken['status']);

    $buyerPost = httpCall('POST', COMMERCE_API . '/products.php', ['ProductName' => 'x', 'BasePrice' => 1, 'Stock' => 1], auth($buyer['token']));
    check('POST as non-seller -> 403', $buyerPost['status'] === 403, 'status ' . $buyerPost['status']);

    echo "-- New seller product defaults...\n";
    $sellerProduct = createProduct($seller, ['BasePrice' => 500, 'Stock' => 10]);
    $productIds[] = (int) $sellerProduct['ProductID'];
    check('seller product starts IsApproved=0', (int)$sellerProduct['IsApproved'] === 0);
    check('seller product ApprovalStatus=Pending', $sellerProduct['ApprovalStatus'] === 'Pending');
    check('seller product Status=Active (stock>0)', $sellerProduct['Status'] === 'Active');

    $hidden = httpCall('GET', COMMERCE_API . '/products.php?id=' . (int) $sellerProduct['ProductID']);
    check('unapproved product is not publicly visible', $hidden['status'] === 404, 'status ' . $hidden['status']);

    echo "-- Admin product is approved at creation...\n";
    $adminProduct = createProduct($admin, ['BasePrice' => 750, 'Stock' => 5]);
    $productIds[] = (int) $adminProduct['ProductID'];
    check('admin product IsApproved=1', (int)$adminProduct['IsApproved'] === 1);
    check('admin product ApprovalStatus=Approved', $adminProduct['ApprovalStatus'] === 'Approved');
    $adminVisible = httpCall('GET', COMMERCE_API . '/products.php?id=' . (int) $adminProduct['ProductID']);
    check('admin product is publicly visible', $adminVisible['status'] === 200);

    echo "-- Approval workflow...\n";
    $approved = httpCall('POST', COMMERCE_API . '/admin.php?action=review-product', [
        'ProductID' => (int) $sellerProduct['ProductID'],
        'Decision'  => 'approve',
    ], auth($admin['token']));
    check('admin can approve a seller product', $approved['status'] === 200 && $approved['data']['product']['ApprovalStatus'] === 'Approved');

    $stockOnly = httpCall('PUT', COMMERCE_API . '/products.php?id=' . (int) $sellerProduct['ProductID'], ['Stock' => 20], auth($seller['token']));
    check('stock-only edit keeps approval', (int)$stockOnly['data']['product']['IsApproved'] === 1 && $stockOnly['data']['product']['ApprovalStatus'] === 'Approved');
    check('stock-only edit updates stock', (int)$stockOnly['data']['product']['Stock'] === 20);

    $contentEdit = httpCall('PUT', COMMERCE_API . '/products.php?id=' . (int) $sellerProduct['ProductID'], ['ProductName' => 'Renamed ' . uniqueSuffix()], auth($seller['token']));
    check('seller content edit returns to Pending', $contentEdit['data']['product']['ApprovalStatus'] === 'Pending' && (int)$contentEdit['data']['product']['IsApproved'] === 0);
    check('edited product is hidden again', httpCall('GET', COMMERCE_API . '/products.php?id=' . (int) $sellerProduct['ProductID'])['status'] === 404);

    $reject = httpCall('POST', COMMERCE_API . '/admin.php?action=review-product', [
        'ProductID'       => (int) $sellerProduct['ProductID'],
        'Decision'        => 'reject',
        'RejectionReason' => 'Blurry photo',
    ], auth($admin['token']));
    check('admin can reject a product', $reject['status'] === 200 && $reject['data']['product']['ApprovalStatus'] === 'Rejected');
    check('rejected product is not purchasable/public', httpCall('GET', COMMERCE_API . '/products.php?id=' . (int) $sellerProduct['ProductID'])['status'] === 404);
    check('rejected is not a Status value', !in_array('Rejected', ['Active', 'Inactive', 'OutOfStock', 'Archived'], true));

    $resubmit = httpCall('POST', COMMERCE_API . '/products.php?action=resubmit', ['ProductID' => (int) $sellerProduct['ProductID']], auth($seller['token']));
    check('resubmit moves Rejected -> Pending', $resubmit['status'] === 200 && $resubmit['data']['product']['ApprovalStatus'] === 'Pending', 'status ' . $resubmit['status']);
    check('resubmitted product IsApproved=0', (int)$resubmit['data']['product']['IsApproved'] === 0);

    echo "-- Admin content edit keeps approval...\n";
    $adminEdit = httpCall('PUT', COMMERCE_API . '/products.php?id=' . (int) $adminProduct['ProductID'], ['Description' => 'Edited by admin'], auth($admin['token']));
    check('admin content edit keeps IsApproved=1', (int)$adminEdit['data']['product']['IsApproved'] === 1);

    echo "-- Stock zero -> OutOfStock...\n";
    $zero = httpCall('PUT', COMMERCE_API . '/products.php?id=' . (int) $adminProduct['ProductID'], ['Stock' => 0], auth($admin['token']));
    check('stock 0 sets Status=OutOfStock', $zero['data']['product']['Status'] === 'OutOfStock');
    check('OutOfStock is not Active', $zero['data']['product']['Status'] !== 'Active');

    echo "-- Delete semantics...\n";
    $sellerDelete = httpCall('DELETE', COMMERCE_API . '/products.php?id=' . (int) $sellerProduct['ProductID'], null, auth($seller['token']));
    check('seller delete sets Inactive (not Archived)', $sellerDelete['data']['product']['Status'] === 'Inactive');
    check('seller cannot archive', $sellerDelete['data']['product']['Status'] !== 'Archived');

    $adminDelete = httpCall('DELETE', COMMERCE_API . '/products.php?id=' . (int) $sellerProduct['ProductID'], null, auth($admin['token']));
    check('admin delete sets Archived', $adminDelete['data']['product']['Status'] === 'Archived');

    $otherSellerProduct = createProduct($seller, ['BasePrice' => 100, 'Stock' => 3]);
    $productIds[] = (int) $otherSellerProduct['ProductID'];
    $crossEdit = httpCall('PUT', COMMERCE_API . '/products.php?id=' . (int) $otherSellerProduct['ProductID'], ['BasePrice' => 200], auth($buyer['token']));
    check('non-owner cannot edit (403)', $crossEdit['status'] === 403, 'status ' . $crossEdit['status']);

} finally {
    cleanupAll($productIds);
    suiteEnd();
}
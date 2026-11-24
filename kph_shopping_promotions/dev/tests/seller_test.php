<?php
// ============================================================
// KameraPH | Commerce | Seller test suite
// ============================================================
// Covers application submit/review, role grant, suspension,
// reactivation, termination and re-application rules.
//
// Usage: C:\xampp\php\php.exe kph_shopping_promotions\dev\tests\seller_test.php
// ============================================================

require_once __DIR__ . '/_lib.php';

suiteStart('Sellers — application, approval, suspend, terminate');

$admin   = sharedAdmin();
$buyer   = makeUser('Buyer');
$seller  = makeSellerUser();
$other   = makeSellerUser();
$productIds = [];

function hasSellerRole(int $uid): bool
{
    $s = db()->prepare(
        "SELECT COUNT(*) FROM `user_role` ur JOIN `role` r ON r.RoleID = ur.RoleID
          WHERE ur.UserID = ? AND r.RoleName = 'seller'"
    );
    $s->execute([$uid]);
    return (int) $s->fetchColumn() > 0;
}

try {
    echo "-- Approved seller basics...\n";
    $app = httpCall('GET', COMMERCE_API . '/sellers.php?application', null, auth($seller['token']));
    check('seller sees own application', $app['status'] === 200 && ($app['data']['application']['Status'] ?? '') === 'Approved', $app['raw']);
    check('approval grants the seller role', hasSellerRole($seller['id']));

    $dup = httpCall('POST', COMMERCE_API . '/sellers.php?action=apply', [
        'StoreName'       => 'Duplicate ' . uniqueSuffix(),
        'ContactNumber'   => $seller['phone'],
        'StoreDescription'=> 'Duplicate store.',
    ], auth($seller['token']));
    check('duplicate apply while Approved -> 400', $dup['status'] === 400, 'status ' . $dup['status']);

    $nonAdminReview = httpCall('POST', COMMERCE_API . '/sellers.php?action=review', [
        'ApplicationID' => $seller['applicationId'],
        'Decision'      => 'approve',
    ], auth($buyer['token']));
    check('non-admin cannot review applications', $nonAdminReview['status'] === 403, 'status ' . $nonAdminReview['status']);

    echo "-- Seller can manage own Pending product...\n";
    $own = createProduct($seller, ['ProductName' => 'Seller Own ' . uniqueSuffix(), 'BasePrice' => 400, 'Stock' => 5]);
    $productIds[] = (int) $own['ProductID'];
    check('seller product starts Pending', $own['ApprovalStatus'] === 'Pending');
    $editOwn = httpCall('PUT', COMMERCE_API . '/products.php?id=' . (int) $own['ProductID'], ['BasePrice' => 450], auth($seller['token']));
    check('seller can edit own Pending product', $editOwn['status'] === 200, $editOwn['raw']);
    check('edit keeps it unapproved', (int)$editOwn['data']['product']['IsApproved'] === 0);

    echo "-- Suspension...\n";
    $suspend = httpCall('POST', COMMERCE_API . '/admin.php?action=suspend-seller', [
        'UserID' => $seller['id'],
        'Reason' => 'Policy violation',
    ], auth($admin['token']));
    check('admin can suspend a seller', $suspend['status'] === 200 && $suspend['data']['application']['Status'] === 'Suspended', $suspend['raw']);

    $suspendedProducts = httpCall('GET', COMMERCE_API . '/sellers.php?products', null, auth($seller['token']));
    check('suspended seller cannot open dashboard products', $suspendedProducts['status'] === 403, 'status ' . $suspendedProducts['status']);

    $suspendedCreate = httpCall('POST', COMMERCE_API . '/products.php', ['ProductName' => 'Blocked', 'BasePrice' => 10, 'Stock' => 1], auth($seller['token']));
    check('suspended seller cannot create products', $suspendedCreate['status'] === 403, 'status ' . $suspendedCreate['status']);

    $reactivate = httpCall('POST', COMMERCE_API . '/admin.php?action=reactivate-seller', ['UserID' => $seller['id']], auth($admin['token']));
    check('admin can reactivate a seller', $reactivate['status'] === 200 && $reactivate['data']['application']['Status'] === 'Approved', $reactivate['raw']);
    check('reactivated seller keeps the seller role', hasSellerRole($seller['id']));

    echo "-- Ownership...\n";
    $cross = httpCall('PUT', COMMERCE_API . '/products.php?id=' . (int) $own['ProductID'], ['BasePrice' => 999], auth($other['token']));
    check('other seller cannot edit this product', $cross['status'] === 403, 'status ' . $cross['status']);

    echo "-- Termination...\n";
    $approveOwn = httpCall('POST', COMMERCE_API . '/admin.php?action=review-product', [
        'ProductID' => (int) $own['ProductID'],
        'Decision'  => 'approve',
    ], auth($admin['token']));
    check('admin approves the seller product', $approveOwn['status'] === 200);

    $terminate = httpCall('POST', COMMERCE_API . '/admin.php?action=terminate-seller', [
        'UserID' => $seller['id'],
        'Reason' => 'Terminated in test',
    ], auth($admin['token']));
    check('admin can terminate a seller', $terminate['status'] === 200, $terminate['raw']);
    check('termination removes the seller role', !hasSellerRole($seller['id']));

    $afterTerminate = httpCall('GET', COMMERCE_API . '/products.php?id=' . (int) $own['ProductID']);
    check('terminated seller product is hidden', $afterTerminate['status'] === 404, 'status ' . $afterTerminate['status']);

    $reapply = httpCall('POST', COMMERCE_API . '/sellers.php?action=apply', [
        'StoreName'     => 'Again ' . uniqueSuffix(),
        'ContactNumber' => $seller['phone'],
    ], auth($seller['token']));
    check('terminated seller cannot re-apply', $reapply['status'] === 400, 'status ' . $reapply['status']);

    echo "-- Rejection with CanReapply=1...\n";
    $seller2 = makeUser('SellerTwo');
    $apply2 = httpCall('POST', COMMERCE_API . '/sellers.php?action=apply', [
        'StoreName'     => 'Reject Me ' . uniqueSuffix(),
        'ContactNumber' => $seller2['phone'],
    ], auth($seller2['token']));
    $appId2 = (int)($apply2['data']['application']['ApplicationID'] ?? 0);
    check('second seller applied', $apply2['status'] === 201 && $appId2 > 0, $apply2['raw']);

    $reject2 = httpCall('POST', COMMERCE_API . '/sellers.php?action=review', [
        'ApplicationID'   => $appId2,
        'Decision'        => 'reject',
        'RejectionReason' => 'Incomplete details',
    ], auth($admin['token']));
    check('admin can reject an application', $reject2['status'] === 200 && $reject2['data']['application']['Status'] === 'Rejected', $reject2['raw']);

    $reapply2 = httpCall('POST', COMMERCE_API . '/sellers.php?action=apply', [
        'StoreName'     => 'Reject Me Again ' . uniqueSuffix(),
        'ContactNumber' => $seller2['phone'],
    ], auth($seller2['token']));
    check('rejected seller with CanReapply=1 can re-apply', $reapply2['status'] === 201, 'status ' . $reapply2['status'] . ' ' . $reapply2['raw']);

} finally {
    cleanupAll($productIds);
    suiteEnd();
}
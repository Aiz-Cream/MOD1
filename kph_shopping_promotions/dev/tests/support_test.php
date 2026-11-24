<?php
// ============================================================
// KameraPH | Commerce | Support test suite
// ============================================================
// Covers ticket creation, owner-only access, admin listing and
// status updates (with audit), and order-ownership validation.
//
// Usage: C:\xampp\php\php.exe kph_shopping_promotions\dev\tests\support_test.php
// ============================================================

require_once __DIR__ . '/_lib.php';

suiteStart('Support — tickets, access control, admin updates');

$admin = sharedAdmin();
$buyer = makeUser('Buyer');
$other = makeUser('Other');
$ticketIds = [];

try {
    echo "-- Auth...\n";
    $noToken = httpCall('GET', COMMERCE_API . '/support.php');
    check('GET without token -> 401', $noToken['status'] === 401, 'status ' . $noToken['status']);

    echo "-- Create...\n";
    $create = httpCall('POST', COMMERCE_API . '/support.php', [
        'Subject'  => 'Where is my order?',
        'Category' => 'payment',
        'Message'  => 'I paid yesterday but the order still says unpaid.',
    ], auth($buyer['token']));
    check('buyer can create a ticket', $create['status'] === 201, $create['raw']);
    $ticket = $create['data']['ticket'] ?? [];
    $tid = (int) ($ticket['TicketID'] ?? 0);
    $ticketIds[] = $tid;
    check('ticket starts Open', ($ticket['Status'] ?? '') === 'Open');
    check('ContactEmail defaults to the account email', ($ticket['ContactEmail'] ?? '') === $buyer['email'], $ticket['ContactEmail'] ?? '');

    $badCategory = httpCall('POST', COMMERCE_API . '/support.php', [
        'Subject'  => 'Bad category',
        'Category' => 'nonsense',
        'Message'  => 'Should be rejected.',
    ], auth($buyer['token']));
    check('invalid category -> 400', $badCategory['status'] === 400, 'status ' . $badCategory['status']);

    $foreignOrder = httpCall('POST', COMMERCE_API . '/support.php', [
        'Subject'  => 'Foreign order',
        'Category' => 'other',
        'Message'  => 'This order is not mine.',
        'OrderID'  => 999999,
    ], auth($buyer['token']));
    check('foreign/unknown OrderID -> 400', $foreignOrder['status'] === 400, 'status ' . $foreignOrder['status']);

    echo "-- Owner-only access...\n";
    $ownView = httpCall('GET', COMMERCE_API . '/support.php?id=' . $tid, null, auth($buyer['token']));
    check('owner can view own ticket', $ownView['status'] === 200, 'status ' . $ownView['status']);

    $foreignView = httpCall('GET', COMMERCE_API . '/support.php?id=' . $tid, null, auth($other['token']));
    check('other buyer cannot view ticket (403)', $foreignView['status'] === 403, 'status ' . $foreignView['status']);

    $ownList = httpCall('GET', COMMERCE_API . '/support.php', null, auth($buyer['token']));
    check('buyer list contains own ticket', count($ownList['data']['tickets'] ?? []) >= 1);

    echo "-- Admin...\n";
    $all = httpCall('GET', COMMERCE_API . '/support.php?all=1', null, auth($admin['token']));
    check('admin can list all tickets', $all['status'] === 200 && count($all['data']['tickets'] ?? []) >= 1, $all['raw']);

    $buyerAll = httpCall('GET', COMMERCE_API . '/support.php?all=1', null, auth($buyer['token']));
    check('buyer cannot use admin listing (403)', $buyerAll['status'] === 403, 'status ' . $buyerAll['status']);

    $auditBefore = (int) db()->query("SELECT COUNT(*) FROM `admin_audit_log` WHERE EntityType = 'support_ticket' AND EntityID = $tid")->fetchColumn();

    $update = httpCall('PUT', COMMERCE_API . '/support.php?id=' . $tid, [
        'Status' => 'Resolved',
        'Note'   => 'Refund issued.',
    ], auth($admin['token']));
    check('admin can resolve a ticket', $update['status'] === 200 && $update['data']['ticket']['Status'] === 'Resolved', $update['raw']);

    $auditAfter = (int) db()->query("SELECT COUNT(*) FROM `admin_audit_log` WHERE EntityType = 'support_ticket' AND EntityID = $tid")->fetchColumn();
    check('status update writes an admin audit row', $auditAfter === $auditBefore + 1, "$auditBefore -> $auditAfter");

    $badUpdate = httpCall('PUT', COMMERCE_API . '/support.php?id=' . $tid, ['Status' => 'Closed'], auth($admin['token']));
    check('invalid status -> 400', $badUpdate['status'] === 400, 'status ' . $badUpdate['status']);

} finally {
    cleanupAll();
    suiteEnd();
}
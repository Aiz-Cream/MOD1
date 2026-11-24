<?php
// ============================================================
// KameraPH | Module 1 | Activity test suite
// ============================================================
// Covers behaviour-event recording, metadata allowlist/size,
// user isolation, tab filtering, clearing and the 12-month
// cleanup script. Requires Apache + MySQL.
//
// Usage: C:\xampp\php\php.exe kph_user_account_identity\dev\tests\activity_test.php
// ============================================================

require_once __DIR__ . '/../../../kph_shopping_promotions/dev/tests/_lib.php';

suiteStart('Activity — events, allowlist, isolation, cleanup');

$user  = makeUser('Watcher');
$other = makeUser('WatcherTwo');

try {
    echo "-- Record...\n";
    $post = httpCall('POST', MODULE1_API . '/activity.php', [
        'EventType'    => 'product_viewed',
        'EntityType'   => 'product',
        'EntityID'     => 101,
        'Description'  => 'Viewed Sony Alpha',
        'Metadata'     => ['product_id' => 101, 'secret_token' => 'do-not-store'],
        'UserID'       => $other['id'],
    ], auth($user['token']));
    check('POST activity -> 201', $post['status'] === 201, $post['raw']);
    $activityId = (int) ($post['data']['ActivityID'] ?? 0);

    $row = db()->prepare("SELECT * FROM `user_activity` WHERE ActivityID = ?");
    $row->execute([$activityId]);
    $stored = $row->fetch();
    check('event is stored for the token user', (int)($stored['UserID'] ?? 0) === $user['id']);
    check('client-supplied UserID is ignored', (int)($stored['UserID'] ?? 0) !== $other['id']);
    check('unknown metadata key is stripped', strpos((string)($stored['Metadata'] ?? ''), 'secret_token') === false, (string)($stored['Metadata'] ?? ''));
    check('allowed metadata key is kept', strpos((string)($stored['Metadata'] ?? ''), 'product_id') !== false);

    $unknown = httpCall('POST', MODULE1_API . '/activity.php', [
        'EventType' => 'totally_unknown',
        'Metadata'  => ['x' => 1],
    ], auth($user['token']));
    check('unknown EventType -> 400', $unknown['status'] === 400, 'status ' . $unknown['status']);

    $tooBig = httpCall('POST', MODULE1_API . '/activity.php', [
        'EventType' => 'product_viewed',
        'Metadata'  => ['product_id' => 101, 'category_slug' => str_repeat('a', 3000)],
    ], auth($user['token']));
    check('oversized metadata -> 400', $tooBig['status'] === 400, 'status ' . $tooBig['status']);

    echo "-- Tabs...\n";
    httpCall('POST', MODULE1_API . '/activity.php', [
        'EventType' => 'product_searched',
        'Metadata'  => ['query' => 'sony', 'result_count' => 1],
    ], auth($user['token']));

    $viewed = httpCall('GET', MODULE1_API . '/activity.php?type=viewed', null, auth($user['token']));
    check('viewed tab returns the product_viewed event', count($viewed['data']['activity'] ?? []) === 1, $viewed['raw']);

    $searches = httpCall('GET', MODULE1_API . '/activity.php?type=searches', null, auth($user['token']));
    check('searches tab returns the product_searched event', count($searches['data']['activity'] ?? []) === 1, $searches['raw']);

    $all = httpCall('GET', MODULE1_API . '/activity.php?type=all', null, auth($user['token']));
    check('all tab returns both events', count($all['data']['activity'] ?? []) === 2, 'count ' . count($all['data']['activity'] ?? []));

    echo "-- Isolation...\n";
    $otherAll = httpCall('GET', MODULE1_API . '/activity.php?type=all', null, auth($other['token']));
    check('other user sees none of these events', count($otherAll['data']['activity'] ?? []) === 0, 'count ' . count($otherAll['data']['activity'] ?? []));

    $noToken = httpCall('POST', MODULE1_API . '/activity.php', ['EventType' => 'product_viewed']);
    check('POST without token -> 401', $noToken['status'] === 401, 'status ' . $noToken['status']);

    $logins = httpCall('GET', MODULE1_API . '/activity.php?type=logins', null, auth($user['token']));
    check('logins tab still works', $logins['status'] === 200, 'status ' . $logins['status']);

    echo "-- Clear...\n";
    $clearSearches = httpCall('DELETE', MODULE1_API . '/activity.php?type=searches', null, auth($user['token']));
    check('clearing Searches works', $clearSearches['status'] === 200, $clearSearches['raw']);
    $afterClear = httpCall('GET', MODULE1_API . '/activity.php?type=all', null, auth($user['token']));
    check('clearing Searches leaves other tabs', count($afterClear['data']['activity'] ?? []) === 1, 'count ' . count($afterClear['data']['activity'] ?? []));

    $clearLogins = httpCall('DELETE', MODULE1_API . '/activity.php?type=logins', null, auth($user['token']));
    check('login history cannot be cleared here', $clearLogins['status'] === 400, 'status ' . $clearLogins['status']);

    echo "-- 12-month cleanup script...\n";
    $pdo = db();
    $old = $pdo->prepare(
        "INSERT INTO `user_activity` (UserID, EventType, Description, CreatedAt)
         VALUES (?, 'product_viewed', 'Old event', DATE_SUB(NOW(), INTERVAL 24 MONTH))"
    );
    $old->execute([$user['id']]);
    $oldId = (int) $pdo->lastInsertId();

    $loginsBefore = (int) $pdo->query("SELECT COUNT(*) FROM `login_history` WHERE UserID = " . $user['id'])->fetchColumn();
    $ordersBefore = (int) $pdo->query("SELECT COUNT(*) FROM `orders`")->fetchColumn();

    $script = __DIR__ . '/../scripts/cleanup_activity.php';
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --months=12';
    exec($cmd . ' 2>&1', $out, $code);

    check('cleanup script exits 0', $code === 0, implode(' ', $out));

    $gone = $pdo->prepare("SELECT COUNT(*) FROM `user_activity` WHERE ActivityID = ?");
    $gone->execute([$oldId]);
    check('old event is deleted', (int) $gone->fetchColumn() === 0);

    $kept = $pdo->prepare("SELECT COUNT(*) FROM `user_activity` WHERE UserID = ? AND EventType = 'product_viewed'");
    $kept->execute([$user['id']]);
    check('recent event is kept', (int) $kept->fetchColumn() === 1);

    $loginsAfter = (int) $pdo->query("SELECT COUNT(*) FROM `login_history` WHERE UserID = " . $user['id'])->fetchColumn();
    check('cleanup never touches login_history', $loginsAfter === $loginsBefore, "$loginsBefore -> $loginsAfter");

    $ordersAfter = (int) $pdo->query("SELECT COUNT(*) FROM `orders`")->fetchColumn();
    check('cleanup never touches orders', $ordersAfter === $ordersBefore);

} finally {
    cleanupAll();
    suiteEnd();
}
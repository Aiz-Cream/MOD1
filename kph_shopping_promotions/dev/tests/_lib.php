<?php
// ============================================================
// KameraPH | Shared test helpers
// ============================================================
// Used by the ecommerce test suites. Each suite requires this
// file, creates its own throw-away users, and calls
// cleanupUsers() at the end so it leaves the dev database clean.
// ============================================================

require_once __DIR__ . '/../../../kph_user_account_identity/config/Database.php';
require_once __DIR__ . '/../../../kph_user_account_identity/classes/Role.php';
require_once __DIR__ . '/../../../kph_user_account_identity/classes/UserRole.php';

$GLOBALS['__passed'] = 0;
$GLOBALS['__failed'] = 0;

const MODULE1_API = 'http://localhost/Final%20Project/kph_user_account_identity/api';
const COMMERCE_API = 'http://localhost/Final%20Project/kph_shopping_promotions/api';

function check(string $label, bool $cond, string $detail = ''): void
{
    if ($cond) {
        $GLOBALS['__passed']++;
        echo "  [PASS] {$label}\n";
    } else {
        $GLOBALS['__failed']++;
        echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function httpCall(string $method, string $url, ?array $body = null, array $headers = []): array
{
    $ch = curl_init($url);
    $reqHeaders = ['Content-Type: application/json'];
    foreach ($headers as $k => $v) {
        $reqHeaders[] = "{$k}: {$v}";
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $reqHeaders,
        CURLOPT_TIMEOUT        => 30,
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
        'data'   => json_decode((string) $raw, true) ?? [],
        'raw'    => $raw,
        'error'  => $err,
    ];
}

function auth(string $token, array $headers = []): array
{
    $headers['Authorization'] = 'Bearer ' . $token;
    return $headers;
}

function db(): PDO
{
    return Database::getConnection();
}

function suiteStart(string $title): void
{
    echo "================================================================\n";
    echo " {$title}\n";
    echo "================================================================\n\n";
}

function suiteEnd(): void
{
    $failed = (int) $GLOBALS['__failed'];
    echo "\n----------------------------------------------------------------\n";
    echo " Passed: {$GLOBALS['__passed']}   Failed: {$failed}\n";
    echo "----------------------------------------------------------------\n";
    exit($failed === 0 ? 0 : 1);
}

function uniqueSuffix(): string
{
    return substr(bin2hex(random_bytes(4)), 0, 8);
}

function randomPhone(): string
{
    return '0929' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT);
}

// Registers a buyer and returns [id, token, email, phone].
function makeUser(string $tag = 'Test'): array
{
    $suffix = uniqueSuffix();
    $email = strtolower($tag) . ".{$suffix}@example.test";
    $phone = randomPhone();

    $res = httpCall('POST', MODULE1_API . '/users.php', [
        'FirstName'   => $tag,
        'LastName'    => 'Test',
        'Email'       => $email,
        'PhoneNumber' => $phone,
        'Password'    => 'TestPass123!',
    ]);

    if ($res['status'] !== 201 || empty($res['data']['Token'])) {
        echo "  [FATAL] Could not create test user: " . $res['raw'] . "\n";
        exit(1);
    }

    return trackUser([
        'id'    => (int) $res['data']['UserID'],
        'token' => $res['data']['Token'],
        'email' => $email,
        'phone' => $phone,
    ]);
}

// Creates a user and grants the admin role directly (so tests never
// touch the seeded production admin account). One per suite.
function sharedAdmin(): array
{
    if (empty($GLOBALS['__admin'])) {
        $user = makeUser('Admin');
        $roles = new UserRole();
        $roles->assignByName($user['id'], 'admin');
        $GLOBALS['__admin'] = $user;
    }
    return $GLOBALS['__admin'];
}

function trackUser(array $user): array
{
    $GLOBALS['__users'][] = $user;
    return $user;
}

// Deletes every user this suite created (including the shared admin).
function cleanupAll(array $productIds = []): void
{
    cleanupUsers($GLOBALS['__users'] ?? [], $productIds);
}

function makeSellerUser(): array
{
    $user = makeUser('Seller');
    $suffix = uniqueSuffix();
    $res = httpCall('POST', COMMERCE_API . '/sellers.php?action=apply', [
        'StoreName'         => 'Test Store ' . $suffix,
        'ContactNumber'     => $user['phone'],
        'StoreDescription'  => 'Automated test store.',
        'BusinessAddress'   => '123 Test Street, Test City',
        'ProductCategory'   => 'Cameras',
        'SellerType'        => 'Individual',
    ], auth($user['token']));

    if ($res['status'] !== 201 || empty($res['data']['application'])) {
        echo "  [FATAL] Could not submit seller application: " . $res['raw'] . "\n";
        exit(1);
    }
    $user['applicationId'] = (int) $res['data']['application']['ApplicationID'];

    $approve = httpCall('POST', COMMERCE_API . '/sellers.php?action=review', [
        'ApplicationID' => $user['applicationId'],
        'Decision'      => 'approve',
    ], auth(sharedAdmin()['token']));

    if ($approve['status'] !== 200) {
        echo "  [FATAL] Could not approve seller application: " . $approve['raw'] . "\n";
        exit(1);
    }
    return $user;
}

function createProduct(array $seller, array $fields): array
{
    $payload = array_merge([
        'ProductName' => 'Test Product ' . uniqueSuffix(),
        'BasePrice'   => 1000,
        'Stock'       => 10,
    ], $fields);
    $res = httpCall('POST', COMMERCE_API . '/products.php', $payload, auth($seller['token']));
    if ($res['status'] !== 201 || empty($res['data']['product'])) {
        echo "  [FATAL] Could not create product: " . $res['raw'] . "\n";
        exit(1);
    }
    return $res['data']['product'];
}

// Deletes every row a test user owns, then the user itself. Safe to
// call once per user at the end of a suite.
function cleanupUsers(array $users, array $productIds = []): void
{
    $pdo = db();
    foreach ($users as $u) {
        if (empty($u['id'])) {
            continue;
        }
        $uid = (int) $u['id'];

        $pdo->prepare("DELETE FROM `admin_audit_log` WHERE AdminID = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM `support_ticket` WHERE UserID = ?")->execute([$uid]);
        $pdo->prepare("DELETE oi FROM `order_items` oi JOIN `orders` o ON o.OrderID = oi.OrderID WHERE o.UserID = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM `order_status_history` WHERE OrderID IN (SELECT OrderID FROM `orders` WHERE UserID = ?)")->execute([$uid]);
        $pdo->prepare("DELETE FROM `order_status_history` WHERE ChangedByUserID = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM `order_payment_history` WHERE OrderID IN (SELECT OrderID FROM `orders` WHERE UserID = ?)")->execute([$uid]);
        $pdo->prepare("DELETE FROM `order_payment_history` WHERE ConfirmedByUserID = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM `orders` WHERE UserID = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM `checkout_session` WHERE UserID = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM `cart_items` WHERE CartID IN (SELECT CartID FROM `carts` WHERE UserID = ?)")->execute([$uid]);
        $pdo->prepare("DELETE FROM `carts` WHERE UserID = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM `user_activity` WHERE UserID = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM `seller_application_history` WHERE ApplicationID IN (SELECT ApplicationID FROM `seller_application` WHERE UserID = ?)")->execute([$uid]);
        $pdo->prepare("DELETE FROM `seller_application_history` WHERE ChangedByUserID = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM `seller_application` WHERE UserID = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM `product` WHERE SellerID = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM `user_role` WHERE UserID = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM `user_profile` WHERE UserID = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM `user_address` WHERE UserID = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM `login_history` WHERE UserID = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM `verification_token` WHERE UserID = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM `user` WHERE UserID = ?")->execute([$uid]);
    }

    foreach ($productIds as $pid) {
        $pdo->prepare("DELETE FROM `product` WHERE ProductID = ?")->execute([(int) $pid]);
    }
}
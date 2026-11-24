<?php
// ============================================================
// KameraPH | Shopping (commerce) | Orders API
// Endpoint: /api/orders.php
// Methods: GET, POST, PUT
// Auth: Bearer-only (X-Test-User-Id is never trusted)
// ============================================================

require_once __DIR__ . '/../../kph_user_account_identity/api/helpers.php';
require_once __DIR__ . '/../classes/Order.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$order = new Order();

// Seller or admin actor (admins bypass the seller-application check).
function order_actor(): array
{
    $user = requireBearerUser();
    if (isAdminUser($user)) {
        return ['user' => $user, 'isAdmin' => true];
    }
    return ['user' => requireBearerSeller(), 'isAdmin' => false];
}

try {
    switch ($method) {
        case 'GET':
            $user = requireBearerUser();
            $isAdmin = isAdminUser($user);

            if (isset($_GET['id'])) {
                $id = (int)$_GET['id'];
                if ($id <= 0) {
                    respond(400, ['error' => 'Order id must be a positive integer.']);
                }
                respond(200, ['order' => $order->get($id, (int)$user['UserID'], $isAdmin)]);
            }

            $filters = [];
            if (isset($_GET['status']) && trim((string)$_GET['status']) !== '') {
                $filters['status'] = $_GET['status'];
            }
            if (isset($_GET['checkout']) && trim((string)$_GET['checkout']) !== '') {
                $filters['checkout'] = $_GET['checkout'];
            }
            $orders = $order->list((int)$user['UserID'], $filters);
            respond(200, ['orders' => $orders, 'count' => count($orders)]);
            break;

        case 'POST':
            $user = requireBearerUser();
            $userId = (int)$user['UserID'];
            $action = $_GET['action'] ?? '';
            $body = jsonBody();

            if ($action === 'preview') {
                respond(200, $order->preview($userId, $body));
            }

            if ($action === 'cancel') {
                $id = isset($body['OrderID']) ? (int)$body['OrderID'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
                if ($id <= 0) {
                    respond(400, ['error' => 'OrderID is required.']);
                }
                respond(200, ['order' => $order->cancel($id, $userId, isAdminUser($user))]);
            }

            if ($action === 'confirm-receipt') {
                $id = isset($body['OrderID']) ? (int)$body['OrderID'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
                if ($id <= 0) {
                    respond(400, ['error' => 'OrderID is required.']);
                }
                respond(200, ['order' => $order->confirmReceipt($id, $userId)]);
            }

            if ($action === 'confirm-cod-payment') {
                $actor = order_actor();
                $id = isset($body['OrderID']) ? (int)$body['OrderID'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
                if ($id <= 0) {
                    respond(400, ['error' => 'OrderID is required.']);
                }
                respond(200, ['order' => $order->confirmCodPayment($id, (int)$actor['user']['UserID'], $actor['isAdmin'])]);
            }

            if ($action === 'refund') {
                $admin = requireBearerAdmin();
                $id = isset($body['OrderID']) ? (int)$body['OrderID'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
                if ($id <= 0) {
                    respond(400, ['error' => 'OrderID is required.']);
                }
                respond(200, ['order' => $order->refund($id, (int)$admin['UserID'], $body)]);
            }

            // Default: create one order per seller.
            respond(201, $order->create($userId, $body));
            break;

        case 'PUT':
            $actor = order_actor();
            $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            $body = jsonBody();
            if ($id <= 0) {
                $id = isset($body['OrderID']) ? (int)$body['OrderID'] : 0;
            }
            if ($id <= 0) {
                respond(400, ['error' => 'Order id is required.']);
            }
            respond(200, ['order' => $order->updateStatus($id, (int)$actor['user']['UserID'], $actor['isAdmin'], $body)]);
            break;

        default:
            respond(405, ['error' => 'Method not allowed.']);
    }
} catch (Throwable $e) {
    handleApiException($e);
}
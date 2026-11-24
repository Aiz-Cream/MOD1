<?php
// ============================================================
// KameraPH | Shopping (commerce) | Sellers API
// Endpoint: /api/sellers.php
// Methods: GET, POST
// Auth: Bearer-only
// ============================================================

require_once __DIR__ . '/../../kph_user_account_identity/api/helpers.php';
require_once __DIR__ . '/../classes/SellerApplication.php';
require_once __DIR__ . '/../classes/Product.php';
require_once __DIR__ . '/../classes/Order.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$sellers = new SellerApplication();

// Seller orders with their items (seller dashboard).
function seller_orders(int $sellerId): array
{
    $db = Database::getConnection();
    $stmt = $db->prepare("SELECT * FROM `orders` WHERE SellerID = ? ORDER BY CreatedAt DESC, OrderID DESC");
    $stmt->execute([$sellerId]);
    $orders = $stmt->fetchAll();
    foreach ($orders as &$order) {
        $stmt = $db->prepare("SELECT * FROM `order_items` WHERE OrderID = ? ORDER BY OrderItemID ASC");
        $stmt->execute([(int)$order['OrderID']]);
        $order['Items'] = $stmt->fetchAll();
    }
    unset($order);
    return $orders;
}

try {
    switch ($method) {
        case 'GET':
            if (isset($_GET['applications'])) {
                $admin = requireBearerAdmin();
                $filters = [];
                if (isset($_GET['status']) && trim((string)$_GET['status']) !== '') {
                    $filters['status'] = $_GET['status'];
                }
                respond(200, ['applications' => $sellers->list($filters)]);
            }

            if (isset($_GET['application'])) {
                $user = requireBearerUser();
                $app = $sellers->getLatest((int)$user['UserID']);
                respond(200, ['application' => $app]);
            }

            if (isset($_GET['products'])) {
                $seller = requireBearerSeller();
                $products = (new Product())->listBySeller((int)$seller['UserID']);
                respond(200, ['products' => $products, 'count' => count($products)]);
            }

            if (isset($_GET['orders'])) {
                $seller = requireBearerSeller();
                $orders = seller_orders((int)$seller['UserID']);
                respond(200, ['orders' => $orders, 'count' => count($orders)]);
            }

            respond(400, ['error' => 'Specify applications, application, products or orders.']);
            break;

        case 'POST':
            $action = $_GET['action'] ?? '';
            $body = jsonBody();

            if ($action === 'apply') {
                $user = requireBearerUser();
                respond(201, ['application' => $sellers->submit((int)$user['UserID'], $body)]);
            }

            if ($action === 'review') {
                $admin = requireBearerAdmin();
                $id = isset($body['ApplicationID']) ? (int)$body['ApplicationID'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
                if ($id <= 0) {
                    respond(400, ['error' => 'ApplicationID is required.']);
                }
                respond(200, ['application' => $sellers->review($id, (int)$admin['UserID'], $body['Decision'] ?? '', $body['RejectionReason'] ?? null)]);
            }

            if ($action === 'suspend') {
                $admin = requireBearerAdmin();
                $uid = isset($body['UserID']) ? (int)$body['UserID'] : 0;
                if ($uid <= 0) {
                    respond(400, ['error' => 'UserID is required.']);
                }
                respond(200, ['application' => $sellers->suspend($uid, (int)$admin['UserID'], $body['Reason'] ?? 'Suspended by admin.')]);
            }

            if ($action === 'reactivate') {
                $admin = requireBearerAdmin();
                $uid = isset($body['UserID']) ? (int)$body['UserID'] : 0;
                if ($uid <= 0) {
                    respond(400, ['error' => 'UserID is required.']);
                }
                respond(200, ['application' => $sellers->reactivate($uid, (int)$admin['UserID'])]);
            }

            if ($action === 'terminate') {
                $admin = requireBearerAdmin();
                $uid = isset($body['UserID']) ? (int)$body['UserID'] : 0;
                if ($uid <= 0) {
                    respond(400, ['error' => 'UserID is required.']);
                }
                respond(200, ['application' => $sellers->terminate($uid, (int)$admin['UserID'], $body['Reason'] ?? 'Terminated by admin.')]);
            }

            if ($action === 'resubmit-product') {
                $seller = requireBearerSeller();
                $pid = isset($body['ProductID']) ? (int)$body['ProductID'] : 0;
                if ($pid <= 0) {
                    respond(400, ['error' => 'ProductID is required.']);
                }
                respond(200, ['product' => (new Product())->resubmit($pid, (int)$seller['UserID'])]);
            }

            respond(400, ['error' => 'Unknown action.']);
            break;

        default:
            respond(405, ['error' => 'Method not allowed.']);
    }
} catch (Throwable $e) {
    handleApiException($e);
}
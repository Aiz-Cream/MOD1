<?php
// ============================================================
// KameraPH | Shopping (commerce) | Admin API
// Endpoint: /api/admin.php
// Methods: GET, POST
// Auth: Bearer admin only (requireBearerAdmin)
// ============================================================

require_once __DIR__ . '/../../kph_user_account_identity/api/helpers.php';
require_once __DIR__ . '/../../kph_user_account_identity/classes/User.php';
require_once __DIR__ . '/../classes/Product.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../classes/SellerApplication.php';
require_once __DIR__ . '/../classes/SupportTicket.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// All orders (admin view) with items and buyer email.
function admin_orders(): array
{
    $db = Database::getConnection();
    $stmt = $db->query(
        "SELECT o.*, u.Email AS BuyerEmail
         FROM `orders` o JOIN `user` u ON u.UserID = o.UserID
         ORDER BY o.CreatedAt DESC, o.OrderID DESC"
    );
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
    $admin = requireBearerAdmin();
    $adminId = (int)$admin['UserID'];

    if ($method === 'GET') {
        $section = $_GET['section'] ?? '';

        switch ($section) {
            case 'users':
                $filters = [];
                if (isset($_GET['status']) && trim((string)$_GET['status']) !== '') {
                    $filters['status'] = $_GET['status'];
                }
                if (isset($_GET['q']) && trim((string)$_GET['q']) !== '') {
                    $filters['search'] = $_GET['q'];
                }
                respond(200, ['users' => (new User())->getAll($filters)]);

            case 'seller-applications':
                $filters = [];
                if (isset($_GET['status']) && trim((string)$_GET['status']) !== '') {
                    $filters['status'] = $_GET['status'];
                }
                respond(200, ['applications' => (new SellerApplication())->list($filters)]);

            case 'products':
                $filters = [];
                if (isset($_GET['approval']) && trim((string)$_GET['approval']) !== '') {
                    $filters['approval'] = $_GET['approval'];
                }
                respond(200, ['products' => (new Product())->listAll($filters)]);

            case 'orders':
                respond(200, ['orders' => admin_orders()]);

            case 'support-tickets':
                $filters = [];
                if (isset($_GET['status']) && trim((string)$_GET['status']) !== '') {
                    $filters['status'] = $_GET['status'];
                }
                respond(200, ['tickets' => (new SupportTicket())->listAll($filters)]);

            default:
                respond(400, ['error' => 'Specify a section: users, seller-applications, products, orders or support-tickets.']);
        }
    }

    if ($method === 'POST') {
        $action = $_GET['action'] ?? '';
        $body = jsonBody();

        if ($action === 'review-product') {
            $pid = isset($body['ProductID']) ? (int)$body['ProductID'] : 0;
            if ($pid <= 0) {
                respond(400, ['error' => 'ProductID is required.']);
            }
            respond(200, ['product' => (new Product())->review($pid, $adminId, $body['Decision'] ?? '', $body['RejectionReason'] ?? null)]);
        }

        if ($action === 'refund') {
            $oid = isset($body['OrderID']) ? (int)$body['OrderID'] : 0;
            if ($oid <= 0) {
                respond(400, ['error' => 'OrderID is required.']);
            }
            respond(200, ['order' => (new Order())->refund($oid, $adminId, $body)]);
        }

        if ($action === 'review-seller') {
            $aid = isset($body['ApplicationID']) ? (int)$body['ApplicationID'] : 0;
            if ($aid <= 0) {
                respond(400, ['error' => 'ApplicationID is required.']);
            }
            respond(200, ['application' => (new SellerApplication())->review($aid, $adminId, $body['Decision'] ?? '', $body['RejectionReason'] ?? null)]);
        }

        if ($action === 'suspend-seller') {
            $uid = isset($body['UserID']) ? (int)$body['UserID'] : 0;
            if ($uid <= 0) {
                respond(400, ['error' => 'UserID is required.']);
            }
            respond(200, ['application' => (new SellerApplication())->suspend($uid, $adminId, $body['Reason'] ?? 'Suspended by admin.')]);
        }

        if ($action === 'reactivate-seller') {
            $uid = isset($body['UserID']) ? (int)$body['UserID'] : 0;
            if ($uid <= 0) {
                respond(400, ['error' => 'UserID is required.']);
            }
            respond(200, ['application' => (new SellerApplication())->reactivate($uid, $adminId)]);
        }

        if ($action === 'terminate-seller') {
            $uid = isset($body['UserID']) ? (int)$body['UserID'] : 0;
            if ($uid <= 0) {
                respond(400, ['error' => 'UserID is required.']);
            }
            respond(200, ['application' => (new SellerApplication())->terminate($uid, $adminId, $body['Reason'] ?? 'Terminated by admin.')]);
        }

        if ($action === 'update-ticket') {
            $tid = isset($body['TicketID']) ? (int)$body['TicketID'] : 0;
            if ($tid <= 0) {
                respond(400, ['error' => 'TicketID is required.']);
            }
            respond(200, ['ticket' => (new SupportTicket())->updateStatus($tid, $adminId, $body['Status'] ?? '', $body['Note'] ?? null)]);
        }

        if ($action === 'suspend-user') {
            $uid = isset($body['UserID']) ? (int)$body['UserID'] : 0;
            if ($uid <= 0) {
                respond(400, ['error' => 'UserID is required.']);
            }
            respond(200, ['user' => (new User())->suspend($uid, $body['Reason'] ?? 'Suspended by admin.', $adminId)]);
        }

        if ($action === 'reactivate-user') {
            $uid = isset($body['UserID']) ? (int)$body['UserID'] : 0;
            if ($uid <= 0) {
                respond(400, ['error' => 'UserID is required.']);
            }
            respond(200, ['user' => (new User())->reactivate($uid)]);
        }

        respond(400, ['error' => 'Unknown action.']);
    }

    respond(405, ['error' => 'Method not allowed.']);
} catch (Throwable $e) {
    handleApiException($e);
}
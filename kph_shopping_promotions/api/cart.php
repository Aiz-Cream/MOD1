<?php
// ============================================================
// KameraPH | Shopping Cart Module | API Endpoint
// Endpoint: /api/cart.php
// Methods: GET, POST, PUT, DELETE
// Authentication: Bearer token ONLY (X-Test-User-Id is REJECTED)
//
// This endpoint enforces Bearer-only auth by validating the token
// directly rather than relying on requireUser() which would
// silently accept X-Test-User-Id as a fallback.
// ============================================================

require_once __DIR__ . '/../../kph_user_account_identity/api/helpers.php';
require_once __DIR__ . '/../classes/Cart.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$cart = new Cart();

try {
    // Bearer-only authentication: validate token directly.
    // Ignore any X-Test-User-Id header — it is never trusted here.
    $token = getBearerToken();
    if ($token === null) {
        respond(401, ['error' => 'Authentication required.']);
    }
    $tokenModel = new VerificationToken();
    $userId = $tokenModel->verifySession($token);
    if ($userId === null) {
        respond(401, ['error' => 'Authentication required.']);
    }
    $userModel = new User();
    $user = $userModel->getById($userId);
    if ($user === null) {
        respond(401, ['error' => 'Authentication required.']);
    }
    $userId = (int) $user['UserID'];

    switch ($method) {
        case 'GET':
            if (isset($_GET['count'])) {
                $count = $cart->count($userId);
                respond(200, ['count' => $count]);
            } else {
                $view = $cart->getView($userId);
                respond(200, $view);
            }
            break;

        case 'POST':
            $body = jsonBody();
            $view = $cart->add($userId, $body);
            respond(200, $view);
            break;

        case 'PUT':
            $body = jsonBody();
            $productId = isset($body['ProductID']) ? (int) $body['ProductID'] : 0;
            if ($productId <= 0) {
                respond(400, ['error' => 'ProductID is required and must be a positive integer.']);
            }
            $quantity = $body['Quantity'] ?? null;
            if ($quantity === null || !is_numeric($quantity) || (int) $quantity != $quantity) {
                respond(400, ['error' => 'Quantity must be an integer between 1 and 99.']);
            }
            $quantity = (int) $quantity;
            if ($quantity < 1) {
                respond(400, ['error' => 'Quantity must be at least 1.']);
            }
            $view = $cart->updateQuantity($userId, $productId, $quantity);
            respond(200, $view);
            break;

        case 'DELETE':
            if (isset($_GET['all']) && $_GET['all'] === '1') {
                $view = $cart->clear($userId);
                respond(200, [
                    'message' => 'Cart cleared.',
                    'priceSource' => 'unverified_snapshot',
                    'verified' => false,
                    'items' => [],
                    'count' => 0,
                    'subtotal' => 0,
                    'total' => 0,
                ]);
            } else {
                $productId = isset($_GET['item']) ? (int) $_GET['item'] : 0;
                if ($productId <= 0) {
                    respond(400, ['error' => 'Valid ProductID (item parameter) is required.']);
                }
                $view = $cart->removeItem($userId, $productId);
                respond(200, $view);
            }
            break;

        default:
            respond(405, ['error' => 'Method not allowed.']);
    }
} catch (Throwable $e) {
    handleApiException($e);
}
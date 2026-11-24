<?php
// ============================================================
// KameraPH | Shopping (commerce) | Products API
// Endpoint: /api/products.php
// Methods: GET (public), POST/PUT/DELETE (Bearer seller/admin)
// Auth: Bearer-only for writes (X-Test-User-Id is never trusted)
// ============================================================

require_once __DIR__ . '/../../kph_user_account_identity/api/helpers.php';
require_once __DIR__ . '/../classes/Product.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$product = new Product();

// Resolves the acting user to {user, isAdmin}. Non-admins must hold an
// active Approved seller application.
function product_actor(): array
{
    $user = requireBearerUser();
    if (isAdminUser($user)) {
        return ['user' => $user, 'isAdmin' => true];
    }
    return ['user' => requireBearerSeller(), 'isAdmin' => false];
}

function handle_image_upload(): void
{
    if (!isset($_FILES['Image']) || !is_array($_FILES['Image'])) {
        respond(400, ['error' => 'No image file was uploaded.']);
    }
    $file = $_FILES['Image'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        respond(400, ['error' => 'Image upload failed.']);
    }
    if ((int)$file['size'] <= 0 || (int)$file['size'] > 2 * 1024 * 1024) {
        respond(400, ['error' => 'Image must be non-empty and at most 2 MB.']);
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        respond(400, ['error' => 'Only JPG, PNG and WEBP images are allowed.']);
    }
    $info = getimagesize($file['tmp_name']);
    if ($info === false) {
        respond(400, ['error' => 'The uploaded file is not a valid image.']);
    }

    $dir = __DIR__ . '/../../kph_user_account_identity/web/uploads/products';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        respond(500, ['error' => 'Upload directory is not available.']);
    }

    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        respond(500, ['error' => 'Could not store the uploaded image.']);
    }

    respond(200, ['ImageURL' => 'uploads/products/' . $name]);
}

try {
    switch ($method) {
        case 'GET':
            if (isset($_GET['id'])) {
                $id = (int)$_GET['id'];
                if ($id <= 0) {
                    respond(400, ['error' => 'Product id must be a positive integer.']);
                }
                $row = $product->getById($id);
                if ($row === null) {
                    respond(404, ['error' => 'Product not found.']);
                }
                respond(200, ['product' => $row]);
            }

            $filters = [];
            if (isset($_GET['category']) && trim((string)$_GET['category']) !== '') {
                $filters['category'] = $_GET['category'];
            }
            if (isset($_GET['q'])) {
                $filters['q'] = $_GET['q'];
            }
            if (isset($_GET['limit'])) {
                $filters['limit'] = $_GET['limit'];
            }
            if (isset($_GET['offset'])) {
                $filters['offset'] = $_GET['offset'];
            }
            $items = $product->list($filters);
            respond(200, ['products' => $items, 'count' => count($items)]);
            break;

        case 'POST':
            $action = $_GET['action'] ?? '';
            $actor = product_actor();
            $userId = (int)$actor['user']['UserID'];

            if ($action === 'upload-image') {
                handle_image_upload();
            }

            if ($action === 'resubmit') {
                $body = jsonBody();
                $id = isset($body['ProductID']) ? (int)$body['ProductID'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
                if ($id <= 0) {
                    respond(400, ['error' => 'ProductID is required.']);
                }
                respond(200, ['product' => $product->resubmit($id, $userId)]);
            }

            $body = jsonBody();
            respond(201, ['product' => $product->create($userId, $actor['isAdmin'], $body)]);
            break;

        case 'PUT':
            $actor = product_actor();
            $userId = (int)$actor['user']['UserID'];
            $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            if ($id <= 0) {
                $body = jsonBody();
                $id = isset($body['ProductID']) ? (int)$body['ProductID'] : 0;
            }
            if ($id <= 0) {
                respond(400, ['error' => 'Product id is required.']);
            }
            $body = jsonBody();
            respond(200, ['product' => $product->update($id, $userId, $actor['isAdmin'], $body)]);
            break;

        case 'DELETE':
            $actor = product_actor();
            $userId = (int)$actor['user']['UserID'];
            $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            if ($id <= 0) {
                respond(400, ['error' => 'Product id is required.']);
            }
            $target = $actor['isAdmin'] ? Product::STATUS_ARCHIVED : Product::STATUS_INACTIVE;
            respond(200, ['product' => $product->setStatus($id, $userId, $actor['isAdmin'], $target)]);
            break;

        default:
            respond(405, ['error' => 'Method not allowed.']);
    }
} catch (Throwable $e) {
    handleApiException($e);
}
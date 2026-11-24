<?php
// ============================================================
// KameraPH | Shopping (commerce) | Categories API
// Endpoint: /api/categories.php
// Methods: GET
// Auth: public
// ============================================================

require_once __DIR__ . '/../../kph_user_account_identity/api/helpers.php';
require_once __DIR__ . '/../classes/Category.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method !== 'GET') {
        respond(405, ['error' => 'Method not allowed.']);
    }

    $category = new Category();
    respond(200, ['categories' => $category->getAllActive()]);
} catch (Throwable $e) {
    handleApiException($e);
}
<?php
// ============================================================
// KameraPH | Shopping (commerce) | Support API
// Endpoint: /api/support.php
// Methods: GET, POST, PUT
// Auth: Bearer-only
// ============================================================

require_once __DIR__ . '/../../kph_user_account_identity/api/helpers.php';
require_once __DIR__ . '/../classes/SupportTicket.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$support = new SupportTicket();

try {
    switch ($method) {
        case 'GET':
            if (isset($_GET['all'])) {
                $admin = requireBearerAdmin();
                $filters = [];
                if (isset($_GET['status']) && trim((string)$_GET['status']) !== '') {
                    $filters['status'] = $_GET['status'];
                }
                respond(200, ['tickets' => $support->listAll($filters)]);
            }

            $user = requireBearerUser();
            if (isset($_GET['id'])) {
                $id = (int)$_GET['id'];
                if ($id <= 0) {
                    respond(400, ['error' => 'Ticket id must be a positive integer.']);
                }
                $ticket = $support->getById($id);
                if ($ticket === null) {
                    respond(404, ['error' => 'Ticket not found.']);
                }
                if (!isAdminUser($user) && (int)$ticket['UserID'] !== (int)$user['UserID']) {
                    respond(403, ['error' => 'You cannot view this ticket.']);
                }
                respond(200, ['ticket' => $ticket]);
            }

            respond(200, ['tickets' => $support->listByUser((int)$user['UserID'])]);
            break;

        case 'POST':
            $user = requireBearerUser();
            $body = jsonBody();
            if (!empty($body['ContactEmail'])) {
                $body['ContactEmail'] = $body['ContactEmail'];
            } else {
                $body['ContactEmail'] = $user['Email'];
            }
            respond(201, ['ticket' => $support->create((int)$user['UserID'], $body)]);
            break;

        case 'PUT':
            $admin = requireBearerAdmin();
            $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            $body = jsonBody();
            if ($id <= 0) {
                $id = isset($body['TicketID']) ? (int)$body['TicketID'] : 0;
            }
            if ($id <= 0) {
                respond(400, ['error' => 'Ticket id is required.']);
            }
            respond(200, ['ticket' => $support->updateStatus($id, (int)$admin['UserID'], $body['Status'] ?? '', $body['Note'] ?? null)]);
            break;

        default:
            respond(405, ['error' => 'Method not allowed.']);
    }
} catch (Throwable $e) {
    handleApiException($e);
}
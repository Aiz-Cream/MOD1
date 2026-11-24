<?php
// ============================================================
// KameraPH | Shopping (commerce) | SupportTicket
// ============================================================
// Help-centre support tickets: create, owner/admin view, admin
// status updates. Every admin status change is written to
// admin_audit_log in the same transaction.
// ============================================================

require_once __DIR__ . '/../../kph_user_account_identity/config/Database.php';
require_once __DIR__ . '/../../kph_user_account_identity/classes/Validator.php';
require_once __DIR__ . '/../../kph_user_account_identity/classes/Exceptions.php';

class SupportTicket
{
    private PDO $db;

    public const OPEN = 'Open';
    public const IN_PROGRESS = 'InProgress';
    public const RESOLVED = 'Resolved';

    public const CATEGORIES = ['account', 'payment', 'product', 'other'];

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function create(int $userId, array $data): array
    {
        $subject = Validator::text($data['Subject'] ?? null, 'Subject', 200);
        $category = Validator::oneOf($data['Category'] ?? null, self::CATEGORIES, 'Category');
        $message = Validator::text($data['Message'] ?? null, 'Message', 5000);
        $email = Validator::email($data['ContactEmail'] ?? null);

        $orderId = null;
        if (isset($data['OrderID']) && $data['OrderID'] !== null && (int)$data['OrderID'] > 0) {
            $orderId = (int)$data['OrderID'];
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM `orders` WHERE OrderID = ? AND UserID = ?");
            $stmt->execute([$orderId, $userId]);
            if ((int)$stmt->fetchColumn() === 0) {
                throw new InvalidArgumentException("The selected order does not belong to you.");
            }
        }

        $stmt = $this->db->prepare(
            "INSERT INTO `support_ticket` (UserID, Subject, Category, Message, OrderID, ContactEmail, Status)
             VALUES (?, ?, ?, ?, ?, ?, 'Open')"
        );
        $stmt->execute([$userId, $subject, $category, $message, $orderId, $email]);
        return $this->getById((int)$this->db->lastInsertId());
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM `support_ticket` WHERE TicketID = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function listByUser(int $userId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM `support_ticket` WHERE UserID = ? ORDER BY CreatedAt DESC, TicketID DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function listAll(array $filters = []): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = "t.Status = ?";
            $params[] = $filters['status'];
        }
        $sql = "SELECT t.*, u.FirstName, u.LastName, u.Email AS UserEmail
                FROM `support_ticket` t
                JOIN `user` u ON u.UserID = t.UserID"
            . ($where ? " WHERE " . implode(' AND ', $where) : "")
            . " ORDER BY t.CreatedAt DESC, t.TicketID DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // Admin-only status update; writes status + admin_audit_log in one tx.
    public function updateStatus(int $id, int $adminId, string $status, ?string $note): array
    {
        $status = Validator::oneOf($status, [self::OPEN, self::IN_PROGRESS, self::RESOLVED], 'Status');
        $note = $note === null ? null : Validator::text($note, 'Note', 255, false);

        $this->db->beginTransaction();
        try {
            $ticket = $this->getById($id);
            if ($ticket === null) {
                throw new OutOfBoundsException("Ticket not found.");
            }
            $stmt = $this->db->prepare(
                "UPDATE `support_ticket` SET Status = ?, UpdatedAt = NOW() WHERE TicketID = ?"
            );
            $stmt->execute([$status, $id]);

            $stmt = $this->db->prepare(
                "INSERT INTO `admin_audit_log` (AdminID, ActionType, EntityType, EntityID, Details, IPAddress)
                 VALUES (?, 'support_status', 'support_ticket', ?, ?, ?)"
            );
            $stmt->execute([$adminId, $id, 'Status -> ' . $status . ($note ? ' (' . $note . ')' : ''), $_SERVER['REMOTE_ADDR'] ?? null]);

            $this->db->commit();
            return $this->getById($id);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
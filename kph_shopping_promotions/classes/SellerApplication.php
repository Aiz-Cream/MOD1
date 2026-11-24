<?php
// ============================================================
// KameraPH | Shopping (commerce) | SellerApplication
// ============================================================
// Become-a-seller applications, admin review, suspension,
// reactivation and termination.
//
// At most one active (Pending/Approved) application per user is
// enforced by UNIQUE(UserID, ActiveFlag). Rejected/Suspended rows
// have ActiveFlag NULL and may be re-applied to (if CanReapply=1).
// ============================================================

require_once __DIR__ . '/../../kph_user_account_identity/config/Database.php';
require_once __DIR__ . '/../../kph_user_account_identity/classes/Validator.php';
require_once __DIR__ . '/../../kph_user_account_identity/classes/Exceptions.php';
require_once __DIR__ . '/../../kph_user_account_identity/classes/UserRole.php';
require_once __DIR__ . '/../../kph_user_account_identity/classes/Role.php';

class SellerApplication
{
    private PDO $db;
    private UserRole $roles;

    public const PENDING = 'Pending';
    public const APPROVED = 'Approved';
    public const REJECTED = 'Rejected';
    public const SUSPENDED = 'Suspended';

    public function __construct()
    {
        $this->db = Database::getConnection();
        $this->roles = new UserRole();
    }

    // ---- reads -------------------------------------------------------------

    public function getLatest(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM `seller_application` WHERE UserID = ? ORDER BY ApplicationID DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    public function getById(int $applicationId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM `seller_application` WHERE ApplicationID = ?");
        $stmt->execute([$applicationId]);
        return $stmt->fetch() ?: null;
    }

    public function getStatus(int $userId): ?string
    {
        $app = $this->getLatest($userId);
        return $app ? $app['Status'] : null;
    }

    public function list(array $filters = []): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = "Status = ?";
            $params[] = $filters['status'];
        }
        $sql = "SELECT sa.*, u.FirstName, u.LastName, u.Email
                FROM `seller_application` sa
                JOIN `user` u ON u.UserID = sa.UserID"
            . ($where ? " WHERE " . implode(' AND ', $where) : "")
            . " ORDER BY sa.SubmittedAt DESC, sa.ApplicationID DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ---- writes ------------------------------------------------------------

    public function submit(int $userId, array $data): array
    {
        $this->requireCanApply($userId);

        $storeName = Validator::text($data['StoreName'] ?? null, 'Store name', 150);
        $contact = Validator::phone($data['ContactNumber'] ?? null, 'Contact number');
        $description = Validator::text($data['StoreDescription'] ?? null, 'Store description', 2000, false);
        $businessAddress = Validator::text($data['BusinessAddress'] ?? null, 'Business address', 500, false);
        $productCategory = Validator::text($data['ProductCategory'] ?? null, 'Product category', 100, false);
        $sellerType = Validator::text($data['SellerType'] ?? null, 'Seller type', 50, false);

        $stmt = $this->db->prepare(
            "INSERT INTO `seller_application`
               (UserID, StoreName, StoreDescription, BusinessAddress, ProductCategory, SellerType, ContactNumber, Status, CanReapply)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', 0)"
        );
        $stmt->execute([$userId, $storeName, $description, $businessAddress, $productCategory, $sellerType, $contact]);
        $id = (int)$this->db->lastInsertId();
        $this->addHistory($id, null, self::PENDING, $userId, 'Application submitted');
        $this->audit($userId, 'seller_application_submit', 'seller_application', $id, 'Submitted application #' . $id);
        return $this->getById($id);
    }

    public function review(int $applicationId, int $adminId, string $decision, ?string $reason): array
    {
        $app = $this->getById($applicationId);
        if ($app === null) {
            throw new OutOfBoundsException("Application not found.");
        }
        if (!in_array($app['Status'], [self::PENDING, self::SUSPENDED], true)) {
            throw new InvalidArgumentException("Only pending or suspended applications can be reviewed.");
        }
        $decision = Validator::oneOf($decision, ['approve', 'reject'], 'Decision');

        if ($decision === 'approve') {
            $stmt = $this->db->prepare(
                "UPDATE `seller_application` SET Status = 'Approved', RejectionReason = NULL, ReviewedByAdminID = ?, ReviewedAt = NOW() WHERE ApplicationID = ?"
            );
            $stmt->execute([$adminId, $applicationId]);
            // Grant the seller role (idempotent).
            if (!$this->roles->hasRole((int)$app['UserID'], 'seller')) {
                $this->roles->assignByName((int)$app['UserID'], 'seller');
            }
            $this->addHistory($applicationId, $app['Status'], self::APPROVED, $adminId, 'Approved by admin');
            $this->audit($adminId, 'seller_application_review', 'seller_application', $applicationId, 'Approved application #' . $applicationId);
        } else {
            $reason = Validator::text($reason, 'Rejection reason', 1000);
            $stmt = $this->db->prepare(
                "UPDATE `seller_application` SET Status = 'Rejected', RejectionReason = ?, CanReapply = 1, ReviewedByAdminID = ?, ReviewedAt = NOW() WHERE ApplicationID = ?"
            );
            $stmt->execute([$reason, $adminId, $applicationId]);
            $this->addHistory($applicationId, $app['Status'], self::REJECTED, $adminId, 'Rejected: ' . $reason);
            $this->audit($adminId, 'seller_application_review', 'seller_application', $applicationId, 'Rejected application #' . $applicationId . ': ' . $reason);
        }
        return $this->getById($applicationId);
    }

    public function suspend(int $userId, int $adminId, string $reason): array
    {
        $app = $this->getLatest($userId);
        if ($app === null || $app['Status'] !== self::APPROVED) {
            throw new InvalidArgumentException("This user is not an active seller.");
        }
        $reason = Validator::text($reason, 'Reason', 1000);
        $stmt = $this->db->prepare(
            "UPDATE `seller_application` SET Status = 'Suspended', RejectionReason = ?, ReviewedByAdminID = ?, ReviewedAt = NOW() WHERE ApplicationID = ?"
        );
        $stmt->execute([$reason, $adminId, $app['ApplicationID']]);
        $this->addHistory((int)$app['ApplicationID'], self::APPROVED, self::SUSPENDED, $adminId, 'Suspended: ' . $reason);
        $this->audit($adminId, 'seller_suspend', 'seller_application', (int)$app['ApplicationID'], 'Suspended seller #' . $userId . ': ' . $reason);
        return $this->getLatest($userId);
    }

    public function reactivate(int $userId, int $adminId): array
    {
        $app = $this->getLatest($userId);
        if ($app === null || $app['Status'] !== self::SUSPENDED) {
            throw new InvalidArgumentException("This seller is not suspended.");
        }
        $stmt = $this->db->prepare(
            "UPDATE `seller_application` SET Status = 'Approved', RejectionReason = NULL, ReviewedByAdminID = ?, ReviewedAt = NOW() WHERE ApplicationID = ?"
        );
        $stmt->execute([$adminId, $app['ApplicationID']]);
        if (!$this->roles->hasRole((int)$userId, 'seller')) {
            $this->roles->assignByName($userId, 'seller');
        }
        $this->addHistory((int)$app['ApplicationID'], self::SUSPENDED, self::APPROVED, $adminId, 'Reactivated by admin');
        $this->audit($adminId, 'seller_reactivate', 'seller_application', (int)$app['ApplicationID'], 'Reactivated seller #' . $userId);
        return $this->getLatest($userId);
    }

    // Termination: remove the seller role, keep history, set the seller's
    // active products Inactive. Re-application is blocked (CanReapply=0).
    public function terminate(int $userId, int $adminId, string $reason): array
    {
        $app = $this->getLatest($userId);
        if ($app === null) {
            throw new OutOfBoundsException("Seller application not found.");
        }
        if (!in_array($app['Status'], [self::APPROVED, self::SUSPENDED], true)) {
            throw new InvalidArgumentException("This seller cannot be terminated in its current status.");
        }
        $reason = Validator::text($reason, 'Reason', 1000);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "UPDATE `seller_application` SET Status = 'Rejected', RejectionReason = ?, CanReapply = 0, ReviewedByAdminID = ?, ReviewedAt = NOW() WHERE ApplicationID = ?"
            );
            $stmt->execute(['Terminated: ' . $reason, $adminId, $app['ApplicationID']]);

            $role = (new Role())->getByName('seller');
            if ($role && $this->roles->hasRole($userId, 'seller')) {
                $this->roles->remove($userId, (int)$role['RoleID'], $adminId);
            }

            // Active products become Inactive (archived/inactive stay).
            $stmt = $this->db->prepare(
                "UPDATE `product` SET Status = 'Inactive', UpdatedAt = NOW()
                 WHERE SellerID = ? AND Status IN ('Active','OutOfStock')"
            );
            $stmt->execute([$userId]);

            $this->addHistory((int)$app['ApplicationID'], $app['Status'], self::REJECTED, $adminId, 'Terminated: ' . $reason);
            $this->audit($adminId, 'seller_terminate', 'seller_application', (int)$app['ApplicationID'], 'Terminated seller #' . $userId . ': ' . $reason);

            $this->db->commit();
            return $this->getLatest($userId);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    // ---- helpers -----------------------------------------------------------

    private function requireCanApply(int $userId): void
    {
        $stmt = $this->db->prepare(
            "SELECT Status FROM `seller_application` WHERE UserID = ? AND Status IN ('Pending','Approved')"
        );
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn() !== false) {
            throw new InvalidArgumentException("You already have an active seller application.");
        }
        $latest = $this->getLatest($userId);
        if ($latest && $latest['Status'] === self::REJECTED && (int)$latest['CanReapply'] === 0) {
            throw new InvalidArgumentException("Your seller application was rejected and re-application is closed.");
        }
    }

    private function addHistory(int $applicationId, ?string $from, string $to, ?int $changedBy, ?string $note): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO `seller_application_history` (ApplicationID, FromStatus, ToStatus, ChangedByUserID, Note)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$applicationId, $from, $to, $changedBy, $note]);
    }

    private function audit(?int $adminId, string $action, string $entityType, ?int $entityId, ?string $details): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO `admin_audit_log` (AdminID, ActionType, EntityType, EntityID, Details, IPAddress)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$adminId, $action, $entityType, $entityId, $details, $_SERVER['REMOTE_ADDR'] ?? null]);
    }
}
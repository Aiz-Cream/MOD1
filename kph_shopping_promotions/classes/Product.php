<?php
// ============================================================
// KameraPH | Shopping (commerce) | Product
// ============================================================
// Product catalog: list/filter/search (public), owner/admin CRUD,
// stock handling, approval workflow, and archival.
//
// Status vs ApprovalStatus (kept separate on purpose):
//   Status         = availability/archival only: Active / Inactive /
//                    OutOfStock / Archived
//   ApprovalStatus = moderation workflow: Pending / Approved / Rejected
// Public visibility requires Status IN (Active, OutOfStock) AND
// IsApproved = 1 AND the seller (if any) has an Approved application.
// ============================================================

require_once __DIR__ . '/../../kph_user_account_identity/config/Database.php';
require_once __DIR__ . '/../../kph_user_account_identity/classes/Validator.php';
require_once __DIR__ . '/Category.php';
require_once __DIR__ . '/AzureProductAdapter.php';

class Product
{
    private PDO $db;
    private ?AzureProductAdapter $azureAdapter = null;

    public const STATUS_ACTIVE = 'Active';
    public const STATUS_INACTIVE = 'Inactive';
    public const STATUS_OUTOFSTOCK = 'OutOfStock';
    public const STATUS_ARCHIVED = 'Archived';

    public const APPROVAL_PENDING = 'Pending';
    public const APPROVAL_APPROVED = 'Approved';
    public const APPROVAL_REJECTED = 'Rejected';

    public const ALL_STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_OUTOFSTOCK, self::STATUS_ARCHIVED];
    public const CONTENT_FIELDS = ['ProductName', 'Description', 'CategoryID', 'BasePrice', 'CompareAtPrice', 'ImageURL'];

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    private function isAzure(): bool
    {
        $config = Database::getConfig();
        $host = $config['host'] ?? '';
        $env = $config['env'] ?? getenv('DB_ENV') ?: '';
        return strpos($host, 'mysql.database.azure.com') !== false || strtolower($env) === 'azure';
    }

    private function getAzureAdapter(): AzureProductAdapter
    {
        if ($this->azureAdapter === null) {
            $this->azureAdapter = new AzureProductAdapter();
        }
        return $this->azureAdapter;
    }

    // ---- reads -------------------------------------------------------------

    // Public catalog: approved, active/out-of-stock, approved seller (or platform).
    public function list(array $filters = []): array
    {
        if ($this->isAzure()) {
            return $this->getAzureAdapter()->list($filters);
        }

        $where = ["p.IsApproved = 1", "p.Status IN ('Active','OutOfStock')"];
        $params = [];

        if (!empty($filters['category'])) {
            $slug = Validator::text($filters['category'], 'Category', 120);
            $where[] = "c.Slug = ?";
            $params[] = $slug;
        }
        if (isset($filters['q']) && trim((string)$filters['q']) !== '') {
            $q = trim((string)$filters['q']);
            $like = '%' . $this->escapeLike($q) . '%';
            $where[] = "(p.ProductName LIKE ? OR p.Description LIKE ?)";
            array_push($params, $like, $like);
        }
        $where[] = "(p.SellerID IS NULL OR EXISTS (SELECT 1 FROM `seller_application` sa WHERE sa.UserID = p.SellerID AND sa.Status = 'Approved'))";

        $limit = 50;
        if (isset($filters['limit'])) {
            $limit = (int)$filters['limit'];
            if ($limit < 1 || $limit > 100) {
                $limit = 50;
            }
        }
        $offset = 0;
        if (isset($filters['offset']) && (int)$filters['offset'] > 0) {
            $offset = (int)$filters['offset'];
        }

        $sql = "SELECT p.*, c.Name AS CategoryName, c.Slug AS CategorySlug
                FROM `product` p
                LEFT JOIN `category` c ON c.CategoryID = p.CategoryID
                WHERE " . implode(' AND ', $where) . "
                ORDER BY p.ProductID ASC
                LIMIT $limit OFFSET $offset";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // Any product row (used by owners/admins and internal logic).
    public function getRawById(int $id): ?array
    {
        if ($this->isAzure()) {
            return $this->getAzureAdapter()->getRawById($id);
        }

        $stmt = $this->db->prepare(
            "SELECT p.*, c.Name AS CategoryName, c.Slug AS CategorySlug
             FROM `product` p
             LEFT JOIN `category` c ON c.CategoryID = p.CategoryID
             WHERE p.ProductID = ?"
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    // Public single product (visibility rules applied).
    public function getById(int $id): ?array
    {
        if ($this->isAzure()) {
            return $this->getAzureAdapter()->getById($id);
        }

        $row = $this->getRawById($id);
        if ($row === null || !$this->isVisible($row)) {
            return null;
        }
        return $row;
    }

    // All products for one seller (any status/approval) — seller dashboard.
    public function listBySeller(int $sellerId): array
    {
        if ($this->isAzure()) {
            return $this->getAzureAdapter()->listBySeller($sellerId);
        }

        $stmt = $this->db->prepare(
            "SELECT p.*, c.Name AS CategoryName, c.Slug AS CategorySlug
             FROM `product` p
             LEFT JOIN `category` c ON c.CategoryID = p.CategoryID
             WHERE p.SellerID = ?
             ORDER BY p.ProductID DESC"
        );
        $stmt->execute([$sellerId]);
        return $stmt->fetchAll();
    }

    // All products including unapproved (admin).
    public function listAll(array $filters = []): array
    {
        if ($this->isAzure()) {
            return $this->getAzureAdapter()->listAll($filters);
        }

        $where = [];
        $params = [];
        if (!empty($filters['approval'])) {
            $where[] = "p.ApprovalStatus = ?";
            $params[] = $filters['approval'];
        }
        if (!empty($filters['q'])) {
            $like = '%' . $this->escapeLike(trim((string)$filters['q'])) . '%';
            $where[] = "p.ProductName LIKE ?";
            $params[] = $like;
        }
        $sql = "SELECT p.*, c.Name AS CategoryName, c.Slug AS CategorySlug
                FROM `product` p
                LEFT JOIN `category` c ON c.CategoryID = p.CategoryID"
            . ($where ? " WHERE " . implode(' AND ', $where) : '')
            . " ORDER BY p.ProductID DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ---- writes ------------------------------------------------------------

    public function create(int $actorId, bool $isAdmin, array $data): array
    {
        $fields = $this->validatePayload($data, false);

        $stock = $fields['Stock'];
        $status = $stock > 0 ? self::STATUS_ACTIVE : self::STATUS_OUTOFSTOCK;
        $approval = $isAdmin ? self::APPROVAL_APPROVED : self::APPROVAL_PENDING;
        $isApproved = $isAdmin ? 1 : 0;

        $sellerId = null;
        if (!$isAdmin) {
            $sellerId = $actorId;
        } elseif (isset($data['SellerID']) && (int)$data['SellerID'] > 0) {
            $sellerId = (int)$data['SellerID'];
            if (!$this->sellerApproved($sellerId)) {
                throw new InvalidArgumentException("The selected seller is not an approved seller.");
            }
        }

        $stmt = $this->db->prepare(
            "INSERT INTO `product`
               (CategoryID, SellerID, ProductName, Description, BasePrice, CompareAtPrice, ImageURL, Stock, Status, IsApproved, ApprovalStatus)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $fields['CategoryID'] ?? null,
            $sellerId,
            $fields['ProductName'],
            $fields['Description'] ?? null,
            $fields['BasePrice'],
            $fields['CompareAtPrice'] ?? null,
            $fields['ImageURL'] ?? null,
            $stock,
            $status,
            $isApproved,
            $approval,
        ]);
        return $this->getRawById((int)$this->db->lastInsertId());
    }

    public function update(int $id, int $actorId, bool $isAdmin, array $data): array
    {
        $old = $this->getRawById($id);
        if ($old === null) {
            throw new OutOfBoundsException("Product not found.");
        }
        if (!$isAdmin && (int)$old['SellerID'] !== $actorId) {
            throw new ForbiddenException("You can only edit your own products.");
        }

        $fields = $this->validatePayload($data, true);
        if (empty($fields)) {
            throw new InvalidArgumentException("Nothing to update.");
        }

        // Did a content field actually change?
        $contentChanged = false;
        foreach (self::CONTENT_FIELDS as $key) {
            if (array_key_exists($key, $fields) && !$this->valuesEqual($old[$key] ?? null, $fields[$key])) {
                $contentChanged = true;
                break;
            }
        }

        // Approval: a seller content edit on an approved product needs reapproval.
        // Stock-only edits and admin edits keep approval.
        $approvalStatus = $old['ApprovalStatus'];
        $isApproved = (int)$old['IsApproved'];
        if (!$isAdmin && $contentChanged && $old['ApprovalStatus'] === self::APPROVAL_APPROVED) {
            $approvalStatus = self::APPROVAL_PENDING;
            $isApproved = 0;
        }

        // Stock -> status only for Active/OutOfStock; Inactive/Archived untouched.
        $status = $old['Status'];
        if (array_key_exists('Stock', $fields)) {
            $status = $this->stockStatus($old['Status'], (int)$fields['Stock']);
        }

        $sets = [];
        $params = [];
        foreach ($fields as $key => $value) {
            $sets[] = "`$key` = ?";
            $params[] = $value;
        }
        $sets[] = "`Status` = ?";
        $params[] = $status;
        $sets[] = "`ApprovalStatus` = ?";
        $params[] = $approvalStatus;
        $sets[] = "`IsApproved` = ?";
        $params[] = $isApproved;
        $sets[] = "`UpdatedAt` = NOW()";
        $params[] = $id;

        $stmt = $this->db->prepare("UPDATE `product` SET " . implode(', ', $sets) . " WHERE ProductID = ?");
        $stmt->execute($params);

        return $this->getRawById($id);
    }

    // Rejected -> Pending (owner seller only). Editing alone never resubmits.
    public function resubmit(int $id, int $actorId): array
    {
        $old = $this->getRawById($id);
        if ($old === null) {
            throw new OutOfBoundsException("Product not found.");
        }
        if ((int)$old['SellerID'] !== $actorId) {
            throw new ForbiddenException("You can only resubmit your own products.");
        }
        if ($old['ApprovalStatus'] !== self::APPROVAL_REJECTED) {
            throw new InvalidArgumentException("Only a rejected product can be resubmitted.");
        }

        $stmt = $this->db->prepare(
            "UPDATE `product`
             SET ApprovalStatus = 'Pending', IsApproved = 0, RejectionReason = NULL,
                 ReviewedByAdminID = NULL, ReviewedAt = NULL, UpdatedAt = NOW()
             WHERE ProductID = ?"
        );
        $stmt->execute([$id]);
        return $this->getRawById($id);
    }

    // Availability/archival. Sellers may set Active/Inactive (not Archived).
    public function setStatus(int $id, int $actorId, bool $isAdmin, string $status): array
    {
        $status = Validator::oneOf($status, self::ALL_STATUSES, 'Status');
        $old = $this->getRawById($id);
        if ($old === null) {
            throw new OutOfBoundsException("Product not found.");
        }
        if (!$isAdmin && (int)$old['SellerID'] !== $actorId) {
            throw new ForbiddenException("You can only change your own products.");
        }
        if (!$isAdmin && $status === self::STATUS_ARCHIVED) {
            throw new ForbiddenException("Sellers cannot archive products.");
        }
        if ($status === self::STATUS_ACTIVE && (int)$old['Stock'] <= 0) {
            throw new InvalidArgumentException("Cannot set a product to Active while stock is zero.");
        }

        $stmt = $this->db->prepare("UPDATE `product` SET Status = ?, UpdatedAt = NOW() WHERE ProductID = ?");
        $stmt->execute([$status, $id]);
        return $this->getRawById($id);
    }

    // Admin moderation: approve or reject. Writes admin_audit_log.
    public function review(int $id, int $adminId, string $decision, ?string $reason): array
    {
        $old = $this->getRawById($id);
        if ($old === null) {
            throw new OutOfBoundsException("Product not found.");
        }
        $decision = Validator::oneOf($decision, ['approve', 'reject'], 'Decision');

        if ($decision === 'approve') {
            $stmt = $this->db->prepare(
                "UPDATE `product`
                 SET ApprovalStatus = 'Approved', IsApproved = 1, RejectionReason = NULL,
                     ReviewedByAdminID = ?, ReviewedAt = NOW(), UpdatedAt = NOW()
                 WHERE ProductID = ?"
            );
            $stmt->execute([$adminId, $id]);
            $this->audit($adminId, 'product_review', 'product', $id, 'Approved product #' . $id);
        } else {
            $reason = Validator::text($reason, 'Rejection reason', 500);
            $stmt = $this->db->prepare(
                "UPDATE `product`
                 SET ApprovalStatus = 'Rejected', IsApproved = 0, RejectionReason = ?,
                     ReviewedByAdminID = ?, ReviewedAt = NOW(), UpdatedAt = NOW()
                 WHERE ProductID = ?"
            );
            $stmt->execute([$reason, $adminId, $id]);
            $this->audit($adminId, 'product_review', 'product', $id, 'Rejected product #' . $id . ': ' . $reason);
        }
        return $this->getRawById($id);
    }

    // ---- helpers -----------------------------------------------------------

    public function isVisible(array $row): bool
    {
        if ((int)$row['IsApproved'] !== 1) {
            return false;
        }
        if (!in_array($row['Status'], [self::STATUS_ACTIVE, self::STATUS_OUTOFSTOCK], true)) {
            return false;
        }
        if ($row['SellerID'] === null) {
            return true;
        }
        return $this->sellerApproved((int)$row['SellerID']);
    }

    public function sellerApproved(int $sellerId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM `seller_application` WHERE UserID = ? AND Status = 'Approved'"
        );
        $stmt->execute([$sellerId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    // Only Active/OutOfStock transition on stock change; others preserved.
    public function stockStatus(string $currentStatus, int $stock): string
    {
        if (in_array($currentStatus, [self::STATUS_ACTIVE, self::STATUS_OUTOFSTOCK], true)) {
            return $stock > 0 ? self::STATUS_ACTIVE : self::STATUS_OUTOFSTOCK;
        }
        return $currentStatus;
    }

    private function validatePayload(array $data, bool $partial): array
    {
        $fields = [];

        if (!$partial || Validator::has($data, 'ProductName')) {
            $fields['ProductName'] = Validator::text($data['ProductName'] ?? null, 'ProductName', 255);
        }
        if (Validator::has($data, 'Description')) {
            $fields['Description'] = Validator::text($data['Description'], 'Description', 5000, false);
        }
        if (Validator::has($data, 'CategoryID')) {
            $raw = $data['CategoryID'];
            if ($raw === null || $raw === '') {
                $fields['CategoryID'] = null;
            } else {
                $categoryId = (int)$raw;
                if ($categoryId <= 0 || !(new Category())->exists($categoryId)) {
                    throw new InvalidArgumentException("CategoryID does not exist.");
                }
                $fields['CategoryID'] = $categoryId;
            }
        }
        if (!$partial || Validator::has($data, 'BasePrice')) {
            $fields['BasePrice'] = $this->validatePrice($data['BasePrice'] ?? null, 'BasePrice');
        }
        if (Validator::has($data, 'CompareAtPrice')) {
            $raw = $data['CompareAtPrice'];
            $fields['CompareAtPrice'] = ($raw === null || $raw === '') ? null : $this->validatePrice($raw, 'CompareAtPrice');
            if ($fields['CompareAtPrice'] !== null && isset($fields['BasePrice']) && $fields['CompareAtPrice'] < $fields['BasePrice']) {
                throw new InvalidArgumentException("CompareAtPrice must not be lower than BasePrice.");
            }
        }
        if (Validator::has($data, 'ImageURL')) {
            $fields['ImageURL'] = Validator::fileUrl($data['ImageURL'], 'ImageURL', false);
        }
        if (!$partial || Validator::has($data, 'Stock')) {
            $fields['Stock'] = $this->validateStock($data['Stock'] ?? null);
        }

        return $fields;
    }

    private function validatePrice($value, string $label): float
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException("$label must be a non-negative number.");
        }
        $str = (string)$value;
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $str)) {
            throw new InvalidArgumentException("$label must have at most 2 decimal places.");
        }
        $price = (float)$value;
        if ($price < 0 || $price > 9999999999.99) {
            throw new InvalidArgumentException("$label is out of range.");
        }
        return round($price, 2);
    }

    private function validateStock($value): int
    {
        if (is_int($value)) {
            $stock = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $stock = (int)$value;
        } else {
            throw new InvalidArgumentException("Stock must be a whole number.");
        }
        if ($stock < 0 || $stock > 1000000) {
            throw new InvalidArgumentException("Stock must be between 0 and 1000000.");
        }
        return $stock;
    }

    private function valuesEqual($a, $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }
        if ($a === null || $b === null) {
            return false;
        }
        return (string)$a === (string)$b;
    }

    private function escapeLike(string $q): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
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
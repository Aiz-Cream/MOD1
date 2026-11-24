<?php
// ============================================================
// KameraPH | Module 1 | Ecommerce | ActivityEvent
// ============================================================
// Server-side behaviour events stored in `user_activity`.
//
// The client may send metadata, but only an event-specific allowlist of
// keys is stored (unknown keys are stripped) and the encoded metadata
// is capped at 2 KB. The UserID always comes from the Bearer token.
// ============================================================

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/Validator.php';

class ActivityEvent
{
    private PDO $db;

    // Activity page tabs -> event types (null = all).
    public const TABS = [
        'all'      => null,
        'viewed'   => ['product_viewed'],
        'searches' => ['product_searched'],
        'shopping' => ['cart_added', 'cart_removed', 'wishlist_added', 'wishlist_removed', 'checkout_started'],
        'orders'   => ['order_placed', 'order_cancelled'],
        'account'  => ['profile_updated', 'address_saved', 'seller_application_submitted'],
    ];

    // Event -> allowed metadata keys (see plan §9.7).
    private const METADATA_ALLOWLIST = [
        'product_viewed'              => ['product_id', 'category_slug'],
        'product_searched'            => ['query', 'category_slug', 'result_count'],
        'category_viewed'             => ['category_slug'],
        'cart_added'                  => ['product_id', 'quantity'],
        'cart_removed'                => ['product_id', 'quantity'],
        'wishlist_added'              => ['product_id'],
        'wishlist_removed'            => ['product_id'],
        'checkout_started'            => ['item_count', 'subtotal'],
        'order_placed'                => ['checkout_key', 'order_count', 'total'],
        'order_cancelled'             => ['order_id'],
        'profile_updated'             => ['fields'],
        'address_saved'               => ['address_id', 'action'],
        'seller_application_submitted' => ['application_id'],
    ];

    private const MAX_METADATA_BYTES = 2048;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public static function isKnownType(string $type): bool
    {
        return isset(self::METADATA_ALLOWLIST[$type]);
    }

    public function track(int $userId, array $data): int
    {
        $type = Validator::text($data['EventType'] ?? null, 'EventType', 40);
        if (!isset(self::METADATA_ALLOWLIST[$type])) {
            throw new InvalidArgumentException("Unknown activity type.");
        }

        $entityType = Validator::text($data['EntityType'] ?? null, 'EntityType', 30, false);
        $entityId = null;
        if (isset($data['EntityID']) && $data['EntityID'] !== null && (int)$data['EntityID'] > 0) {
            $entityId = (int)$data['EntityID'];
        }
        $description = Validator::text($data['Description'] ?? null, 'Description', 255, false);
        $metadata = $this->sanitizeMetadata($type, $data['Metadata'] ?? null);

        $stmt = $this->db->prepare(
            "INSERT INTO `user_activity` (UserID, EventType, EntityType, EntityID, Description, Metadata)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$userId, $type, $entityType, $entityId, $description, $metadata]);
        return (int)$this->db->lastInsertId();
    }

    public function list(int $userId, string $tab, int $limit = 50, int $offset = 0): array
    {
        $types = self::TABS[$tab] ?? null;
        $sql = "SELECT * FROM `user_activity` WHERE UserID = ?";
        $params = [$userId];
        if ($types !== null) {
            $in = implode(',', array_fill(0, count($types), '?'));
            $sql .= " AND EventType IN ($in)";
            $params = array_merge($params, $types);
        }
        $sql .= " ORDER BY CreatedAt DESC, ActivityID DESC LIMIT $limit OFFSET $offset";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // Admin only: activity of every user, with the username attached.
    public function listAll(string $tab, int $limit = 50, int $offset = 0): array
    {
        $types = self::TABS[$tab] ?? null;
        $sql = "SELECT a.*, COALESCE(p.Username, u.Email) AS Username
                FROM `user_activity` a
                LEFT JOIN `user` u ON u.UserID = a.UserID
                LEFT JOIN `user_profile` p ON p.UserID = a.UserID";
        $params = [];
        if ($types !== null) {
            $in = implode(',', array_fill(0, count($types), '?'));
            $sql .= " WHERE a.EventType IN ($in)";
            $params = $types;
        }
        $sql .= " ORDER BY a.CreatedAt DESC, a.ActivityID DESC LIMIT $limit OFFSET $offset";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // Clears the user's own activity for a tab. Never touches login_history.
    public function clear(int $userId, string $tab): int
    {
        $types = self::TABS[$tab] ?? null;
        if ($types === null) {
            $stmt = $this->db->prepare("DELETE FROM `user_activity` WHERE UserID = ?");
            $stmt->execute([$userId]);
            return (int)$stmt->rowCount();
        }
        $in = implode(',', array_fill(0, count($types), '?'));
        $stmt = $this->db->prepare("DELETE FROM `user_activity` WHERE UserID = ? AND EventType IN ($in)");
        $stmt->execute(array_merge([$userId], $types));
        return (int)$stmt->rowCount();
    }

    private function sanitizeMetadata(string $type, $metadata): ?string
    {
        if ($metadata === null || $metadata === '') {
            return null;
        }
        if (!is_array($metadata)) {
            $decoded = json_decode((string)$metadata, true);
            if (!is_array($decoded)) {
                throw new InvalidArgumentException("Metadata must be a JSON object.");
            }
            $metadata = $decoded;
        }

        $clean = [];
        foreach (self::METADATA_ALLOWLIST[$type] as $key) {
            if (array_key_exists($key, $metadata)) {
                $clean[$key] = $metadata[$key];
            }
        }
        if (empty($clean)) {
            return null;
        }

        // Checkout keys are masked before storage.
        if (isset($clean['checkout_key']) && is_string($clean['checkout_key'])) {
            $clean['checkout_key'] = $this->maskKey($clean['checkout_key']);
        }

        $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new InvalidArgumentException("Metadata could not be encoded.");
        }
        if (strlen($json) > self::MAX_METADATA_BYTES) {
            throw new InvalidArgumentException("Activity metadata is too large.");
        }
        return $json;
    }

    private function maskKey(string $key): string
    {
        return strlen($key) <= 8 ? '********' : substr($key, 0, 8) . '...';
    }
}
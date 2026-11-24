<?php
// ============================================================
// KameraPH | Shopping Cart Module | Cart
// ============================================================
// Business logic for shopping cart operations.
// Reuses Module 1's Database connection (single PDO, no duplication).
//
// Uses Azure unified schema:
//   - cart (CartID, UserID, SessionToken, CreatedAt, UpdatedAt)
//   - cart_item (CartItemID, CartID, ListingID, Quantity, AddedAt)
//   - product_listing (ListingID, Title, Price, ...)
//   - listing_image (ImageID, ListingID, ImageURL, IsPrimary)
// ============================================================

require_once __DIR__ . '/../../kph_user_account_identity/config/Database.php';
require_once __DIR__ . '/CartItem.php';
require_once __DIR__ . '/CatalogProvider.php';

class Cart
{
    public const MAX_QTY = 99;
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Get or create a cart for a user. Returns the CartID.
     * Uses a transaction to avoid race conditions on first cart creation.
     */
    public function getOrCreate(int $userId): int
    {
        // Try to find an existing cart first.
        $stmt = $this->db->prepare("SELECT CartID FROM cart WHERE UserID = ?");
        $stmt->execute([$userId]);
        $cartId = $stmt->fetchColumn();

        if ($cartId !== false) {
            return (int) $cartId;
        }

        // Create a new cart atomically.
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("INSERT INTO cart (UserID) VALUES (?)");
            $stmt->execute([$userId]);
            $cartId = (int) $this->db->lastInsertId();
            $this->db->commit();
            return $cartId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            // Another request may have created it concurrently.
            $stmt = $this->db->prepare("SELECT CartID FROM cart WHERE UserID = ?");
            $stmt->execute([$userId]);
            $cartId = $stmt->fetchColumn();
            if ($cartId !== false) {
                return (int) $cartId;
            }
            throw $e;
        }
    }

    /**
     * Get all items in a user's cart.
     *
     * @return array CartView {items[], count, subtotal, total, priceSource, verified}
     */
    public function getView(int $userId): array
    {
        $cartId = $this->getOrCreate($userId);

        $stmt = $this->db->prepare(
            "SELECT ci.CartItemID, ci.CartID, ci.ListingID as ProductID, pl.Title as ProductName, 
                    pl.Price as UnitPrice, li.ImageURL, ci.Quantity
             FROM cart_item ci
             JOIN product_listing pl ON pl.ListingID = ci.ListingID
             LEFT JOIN listing_image li ON li.ListingID = pl.ListingID AND li.IsPrimary = 1
             WHERE ci.CartID = ?
             ORDER BY ci.AddedAt ASC, ci.CartItemID ASC"
        );
        $stmt->execute([$cartId]);
        $rows = $stmt->fetchAll();

        $items = [];
        $count = 0;
        $subtotal = 0.0;

        foreach ($rows as $row) {
            $item = CartItem::fromRow($row);
            $items[] = $item->toArray();
            $count += $item->Quantity;
            $subtotal += $item->getSubtotal();
        }

        return [
            'priceSource' => 'unverified_snapshot',
            'verified' => false,
            'items' => $items,
            'count' => $count,
            'subtotal' => round($subtotal, 2),
            'total' => round($subtotal, 2), // total == subtotal; future: add tax/shipping
        ];
    }

    /**
     * Get just the cart count (total quantity of all items).
     */
    public function count(int $userId): int
    {
        $cartId = $this->getOrCreate($userId);

        $stmt = $this->db->prepare("SELECT SUM(Quantity) as total FROM cart_item WHERE CartID = ?");
        $stmt->execute([$cartId]);
        $row = $stmt->fetch();
        return $row && $row['total'] !== null ? (int) $row['total'] : 0;
    }

    /**
     * Add a product to the cart or increase quantity of an existing item.
     * Uses atomic upsert with quantity cap enforcement inside a transaction.
     *
     * @param int $userId
     * @param array $productData {
     *     ProductID: int (ListingID in Azure),
     *     ProductName: string (ignored - fetched from product_listing),
     *     UnitPrice: float (ignored - fetched from product_listing),
     *     ImageURL?: ?string (ignored - fetched from listing_image),
     *     Quantity?: int (default 1)
     * }
     * @return array Updated cart view
     */
    public function add(int $userId, array $productData): array
    {
        $this->validateProductData($productData);
        $cartId = $this->getOrCreate($userId);

        $listingId = (int) $productData['ProductID'];
        $addQty = isset($productData['Quantity']) ? (int) $productData['Quantity'] : 1;
        $addQty = max(1, min(self::MAX_QTY, $addQty));

        // CatalogProvider integration point (currently a no-op).
        CatalogProvider::validateStock($listingId, $addQty);

        $this->db->beginTransaction();
        try {
            // Atomic upsert: if item exists, add to quantity (capped at MAX_QTY).
            $stmt = $this->db->prepare(
                "INSERT INTO cart_item (CartID, ListingID, Quantity)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     Quantity = LEAST(Quantity + VALUES(Quantity), " . self::MAX_QTY . "),
                     AddedAt = NOW()"
            );
            $stmt->execute([$cartId, $listingId, $addQty]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->getView($userId);
    }

    /**
     * Update the quantity of an existing cart item.
     * Sets the quantity to the specified value (replaces, not adds).
     *
     * @param int $userId
     * @param int $productId (ListingID)
     * @param int $quantity
     * @return array Updated cart view
     * @throws OutOfBoundsException if item not found
     * @throws InvalidArgumentException if quantity invalid
     */
    public function updateQuantity(int $userId, int $productId, int $quantity): array
    {
        if ($quantity < 1 || $quantity > self::MAX_QTY) {
            throw new InvalidArgumentException("Quantity must be between 1 and " . self::MAX_QTY . ".");
        }

        $cartId = $this->getOrCreate($userId);

        $stmt = $this->db->prepare("SELECT CartItemID FROM cart_item WHERE CartID = ? AND ListingID = ?");
        $stmt->execute([$cartId, $productId]);
        $itemId = $stmt->fetchColumn();

        if ($itemId === false) {
            throw new OutOfBoundsException("Item not found in cart.");
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("UPDATE cart_item SET Quantity = ? WHERE CartItemID = ?");
            $stmt->execute([$quantity, $itemId]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->getView($userId);
    }

    /**
     * Remove a specific item from the cart.
     *
     * @param int $userId
     * @param int $productId (ListingID)
     * @return array Updated cart view
     * @throws OutOfBoundsException if item not found
     */
    public function removeItem(int $userId, int $productId): array
    {
        $cartId = $this->getOrCreate($userId);

        $stmt = $this->db->prepare("SELECT CartItemID FROM cart_item WHERE CartID = ? AND ListingID = ?");
        $stmt->execute([$cartId, $productId]);
        $itemId = $stmt->fetchColumn();

        if ($itemId === false) {
            throw new OutOfBoundsException("Item not found in cart.");
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("DELETE FROM cart_item WHERE CartItemID = ?");
            $stmt->execute([$itemId]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->getView($userId);
    }

    /**
     * Clear all items from a user's cart.
     *
     * @param int $userId
     * @return array Empty cart view
     */
    public function clear(int $userId): array
    {
        $cartId = $this->getOrCreate($userId);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("DELETE FROM cart_item WHERE CartID = ?");
            $stmt->execute([$cartId]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->getView($userId);
    }

    /**
     * Validate product data supplied by the client.
     * Only ListingID (ProductID) and Quantity are used; other fields are fetched from product_listing.
     *
     * @param array $data
     * @throws InvalidArgumentException
     */
    private function validateProductData(array $data): void
    {
        if (!isset($data['ProductID']) || !is_numeric($data['ProductID']) || (int) $data['ProductID'] <= 0) {
            throw new InvalidArgumentException("ProductID (ListingID) must be a positive integer.");
        }

        if (isset($data['Quantity'])) {
            // Reject non-integer values (e.g. 1.5, 'abc') before casting.
            if (!is_numeric($data['Quantity']) || (int) $data['Quantity'] != $data['Quantity']) {
                throw new InvalidArgumentException("Quantity must be a whole number.");
            }
            $qty = (int) $data['Quantity'];
            if ($qty < 1 || $qty > self::MAX_QTY) {
                throw new InvalidArgumentException("Quantity must be between 1 and " . self::MAX_QTY . ".");
            }
        }
    }
}
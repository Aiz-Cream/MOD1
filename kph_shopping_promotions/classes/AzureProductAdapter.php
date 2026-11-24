<?php
// ============================================================
// KameraPH | Shopping (commerce) | Azure Product Adapter
// ============================================================
// Adapter for reading products from Azure unified database.
// Maps product_listing + listing_image + product_model + category
// to the frontend-expected product format.
// Used when DB_ENV=azure or connecting to Azure MySQL.
// ============================================================

require_once __DIR__ . '/../../kph_user_account_identity/config/Database.php';
require_once __DIR__ . '/../../kph_user_account_identity/classes/Validator.php';

class AzureProductAdapter
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Check if we're connected to Azure.
     */
    private function isAzure(): bool
    {
        $config = Database::getConfig();
        $host = $config['host'] ?? '';
        $env = $config['env'] ?? getenv('DB_ENV') ?: '';
        return strpos($host, 'mysql.database.azure.com') !== false || strtolower($env) === 'azure';
    }

    /**
     * Build the base query for visible listings.
     * Only returns Live listings from verified sellers.
     */
    private function buildVisibleWhere(): array
    {
        $where = [
            "pl.Status = 'Live'",
            "pl.Quantity > 0"
        ];

        // Seller verification: seller_profile.VerificationStatus = 'Verified'
        // Platform listings (SellerID IS NULL) are always allowed
        $where[] = "(pl.SellerID IS NULL OR EXISTS (
            SELECT 1 FROM seller_profile sp
            WHERE sp.SellerID = pl.SellerID
            AND sp.VerificationStatus = 'Verified'
        ))";

        return $where;
    }

    /**
     * Get primary image for a listing.
     * Uses listing_image table with IsPrimary=1, ordered by DisplayOrder.
     */
    private function getPrimaryImageSubquery(): string
    {
        return "(SELECT li.ImageURL FROM listing_image li
                WHERE li.ListingID = pl.ListingID
                AND li.IsPrimary = 1
                ORDER BY li.DisplayOrder ASC
                LIMIT 1)";
    }

    /**
     * Get category slug for a listing via product_model.
     */
    private function getCategoryJoin(): string
    {
        return "LEFT JOIN product_model pm ON pm.ModelID = pl.ModelID
                LEFT JOIN category c ON c.CategoryID = pm.CategoryID";
    }

    /**
     * Map Azure listing row to frontend product format.
     */
    private function mapRow(array $row): array
    {
        $stock = (int)($row['Quantity'] ?? 0);
        // Generate slug from CategoryName if not available
        $categoryName = $row['CategoryName'] ?? '';
        // Match SQL slug generation: replace & with 'and', then non-alphanumeric with -
        $slug = $categoryName ? strtolower(preg_replace('/[^a-z0-9]+/i', '-', str_replace('&', 'and', $categoryName))) : null;
        $slug = $slug ? trim($slug, '-') : null;

        return [
            'ProductID'       => (int)$row['ListingID'],
            'ProductName'     => $row['Title'] ?? '',
            'Description'     => $row['Description'] ?? null,
            'CategoryID'      => isset($row['CategoryID']) ? (int)$row['CategoryID'] : null,
            'CategoryName'    => $categoryName,
            'CategorySlug'    => $slug,
            'BasePrice'       => (float)$row['Price'],
            'CompareAtPrice'  => $row['OriginalPrice'] !== null ? (float)$row['OriginalPrice'] : null,
            'ImageURL'        => $row['ImageURL'] ?? null,
            'Stock'           => $stock,
            'Status'          => $stock > 0 ? 'Active' : 'OutOfStock',
            'IsApproved'      => 1,
            'ApprovalStatus'  => 'Approved',
            'SellerID'        => isset($row['SellerID']) ? (int)$row['SellerID'] : null,
            'Condition'       => $row['Condition'] ?? null,
            'WeightGrams'     => isset($row['WeightGrams']) ? (int)$row['WeightGrams'] : null,
            'ListedAt'        => $row['ListedAt'] ?? null,
        ];
    }

    // ---- Public catalog methods ---------------------------------------------

    /**
     * List products for public catalog.
     * Filters: category (slug), q (search), limit, offset.
     */
    public function list(array $filters = []): array
    {
        $where = $this->buildVisibleWhere();
        $params = [];

        if (!empty($filters['category'])) {
            $slug = Validator::text($filters['category'], 'Category', 120);
            // Azure category table doesn't have Slug column; match against generated slug from CategoryName
            $where[] = "LOWER(REPLACE(REPLACE(REPLACE(c.CategoryName, ' ', '-'), '&', 'and'), '/', '-')) = ?";
            $params[] = strtolower($slug);
        }

        if (isset($filters['q']) && trim((string)$filters['q']) !== '') {
            $q = trim((string)$filters['q']);
            $like = '%' . $this->escapeLike($q) . '%';
            $where[] = "(pl.Title LIKE ? OR pl.Description LIKE ?)";
            array_push($params, $like, $like);
        }

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

        $sql = "SELECT
                    pl.ListingID, pl.SellerID, pl.ModelID, pl.Title, pl.Description,
                    pl.Price, pl.OriginalPrice, pl.Condition, pl.Quantity,
                    pl.WeightGrams, pl.LengthCm, pl.WidthCm, pl.HeightCm,
                    pl.InsuredValue, pl.Status, pl.ListedAt, pl.UpdatedAt,
                    c.CategoryID, c.CategoryName,
                    " . $this->getPrimaryImageSubquery() . " AS ImageURL
                FROM product_listing pl
                " . $this->getCategoryJoin() . "
                WHERE " . implode(' AND ', $where) . "
                ORDER BY pl.ListedAt DESC, pl.ListingID ASC
                LIMIT $limit OFFSET $offset";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return array_map([$this, 'mapRow'], $rows);
    }

    /**
     * Get a single product by ListingID (public visibility rules applied).
     */
    public function getById(int $listingId): ?array
    {
        $where = $this->buildVisibleWhere();
        $where[] = "pl.ListingID = ?";

        $sql = "SELECT
                    pl.ListingID, pl.SellerID, pl.ModelID, pl.Title, pl.Description,
                    pl.Price, pl.OriginalPrice, pl.Condition, pl.Quantity,
                    pl.WeightGrams, pl.LengthCm, pl.WidthCm, pl.HeightCm,
                    pl.InsuredValue, pl.Status, pl.ListedAt, pl.UpdatedAt,
                    c.CategoryID, c.CategoryName,
                    " . $this->getPrimaryImageSubquery() . " AS ImageURL
                FROM product_listing pl
                " . $this->getCategoryJoin() . "
                WHERE " . implode(' AND ', $where);

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$listingId]);
        $row = $stmt->fetch();

        return $row ? $this->mapRow($row) : null;
    }

    /**
     * Get raw product by ListingID (no visibility filter - for sellers/admins).
     */
    public function getRawById(int $listingId): ?array
    {
        $sql = "SELECT
                    pl.ListingID, pl.SellerID, pl.ModelID, pl.Title, pl.Description,
                    pl.Price, pl.OriginalPrice, pl.Condition, pl.Quantity,
                    pl.WeightGrams, pl.LengthCm, pl.WidthCm, pl.HeightCm,
                    pl.InsuredValue, pl.Status, pl.ListedAt, pl.UpdatedAt,
                    c.CategoryID, c.CategoryName,
                    " . $this->getPrimaryImageSubquery() . " AS ImageURL
                FROM product_listing pl
                " . $this->getCategoryJoin() . "
                WHERE pl.ListingID = ?";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$listingId]);
        $row = $stmt->fetch();

        return $row ? $this->mapRow($row) : null;
    }

    /**
     * List all products for a seller (any status).
     */
    public function listBySeller(int $sellerId): array
    {
        $sql = "SELECT
                    pl.ListingID, pl.SellerID, pl.ModelID, pl.Title, pl.Description,
                    pl.Price, pl.OriginalPrice, pl.Condition, pl.Quantity,
                    pl.WeightGrams, pl.LengthCm, pl.WidthCm, pl.HeightCm,
                    pl.InsuredValue, pl.Status, pl.ListedAt, pl.UpdatedAt,
                    c.CategoryID, c.CategoryName,
                    " . $this->getPrimaryImageSubquery() . " AS ImageURL
                FROM product_listing pl
                " . $this->getCategoryJoin() . "
                WHERE pl.SellerID = ?
                ORDER BY pl.ListedAt DESC, pl.ListingID ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$sellerId]);
        $rows = $stmt->fetchAll();

        return array_map([$this, 'mapRow'], $rows);
    }

    /**
     * List all products (admin) - no visibility filter.
     */
    public function listAll(array $filters = []): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "pl.Status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['q'])) {
            $like = '%' . $this->escapeLike(trim((string)$filters['q'])) . '%';
            $where[] = "(pl.Title LIKE ? OR pl.Description LIKE ?)";
            array_push($params, $like, $like);
        }

        $sql = "SELECT
                    pl.ListingID, pl.SellerID, pl.ModelID, pl.Title, pl.Description,
                    pl.Price, pl.OriginalPrice, pl.Condition, pl.Quantity,
                    pl.WeightGrams, pl.LengthCm, pl.WidthCm, pl.HeightCm,
                    pl.InsuredValue, pl.Status, pl.ListedAt, pl.UpdatedAt,
                    c.CategoryID, c.CategoryName,
                    " . $this->getPrimaryImageSubquery() . " AS ImageURL
                FROM product_listing pl
                " . $this->getCategoryJoin() . ""
            . ($where ? " WHERE " . implode(' AND ', $where) : '')
            . " ORDER BY pl.ListedAt DESC, pl.ListingID ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return array_map([$this, 'mapRow'], $rows);
    }

    // ---- Helper methods -----------------------------------------------------

    private function escapeLike(string $q): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
    }
}
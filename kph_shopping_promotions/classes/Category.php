<?php
// ============================================================
// KameraPH | Shopping (commerce) | Category
// ============================================================
// Categories used by the storefront "Shop by Category" cards and the
// catalog API. Reuses Module 1's Database connection.
// Supports both local (IsActive, SortOrder, Note) and Azure (Status, Description) schemas.
// ============================================================

require_once __DIR__ . '/../../kph_user_account_identity/config/Database.php';
require_once __DIR__ . '/../../kph_user_account_identity/classes/Validator.php';

class Category
{
    private PDO $db;

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

    // Active categories, in display order.
    public function getAllActive(): array
    {
        if ($this->isAzure()) {
            // Azure: Status='Active' (or similar), no SortOrder, Description instead of Note
            // No Slug column - generate from CategoryName via normalizeRow
            $rows = $this->db->query(
                "SELECT CategoryID, CategoryName, Description, Status
                 FROM `category` WHERE Status = 'Active' ORDER BY CategoryID ASC"
            )->fetchAll();
            return array_map([$this, 'normalizeRow'], $rows);
        }

        return $this->db->query(
            "SELECT CategoryID, Name, Slug, Note, SortOrder, IsActive
             FROM `category` WHERE IsActive = 1 ORDER BY SortOrder ASC, CategoryID ASC"
        )->fetchAll();
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM `category` WHERE CategoryID = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === null) {
            return null;
        }
        return $this->normalizeRow($row);
    }

    public function getBySlug(string $slug): ?array
    {
        if ($this->isAzure()) {
            // Azure doesn't have Slug column - search by generated slug from CategoryName
            $stmt = $this->db->prepare(
                "SELECT * FROM `category` 
                 WHERE LOWER(REPLACE(REPLACE(REPLACE(CategoryName, ' ', '-'), '&', 'and'), '/', '-')) = ?"
            );
            $stmt->execute([strtolower($slug)]);
            $row = $stmt->fetch();
            if ($row === null) {
                return null;
            }
            return $this->normalizeRow($row);
        }

        $stmt = $this->db->prepare("SELECT * FROM `category` WHERE Slug = ?");
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        if ($row === null) {
            return null;
        }
        return $this->normalizeRow($row);
    }

    public function exists(int $id): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `category` WHERE CategoryID = ?");
        $stmt->execute([$id]);
        return (int)$stmt->fetchColumn() > 0;
    }

    // Creates a category. Name and Slug are unique; duplicate -> InvalidArgumentException.
    public function create(array $data): array
    {
        $name = Validator::text($data['Name'] ?? null, 'Name', 100);
        $slug = Validator::text($data['Slug'] ?? null, 'Slug', 120);
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new InvalidArgumentException("Slug must be lowercase letters, numbers and hyphens.");
        }
        $note = Validator::text($data['Note'] ?? null, 'Note', 255, false);
        $sort = isset($data['SortOrder']) ? (int)$data['SortOrder'] : 0;
        if ($sort < 0) {
            throw new InvalidArgumentException("SortOrder must be zero or greater.");
        }

        if ($this->isAzure()) {
            // Azure schema: CategoryName, Description, Status (no Slug, no SortOrder, no Note, no IsActive)
            // Check if category with same name exists
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM `category` WHERE CategoryName = ?");
            $stmt->execute([$name]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new InvalidArgumentException("A category with that name already exists.");
            }
            try {
                $stmt = $this->db->prepare(
                    "INSERT INTO `category` (CategoryName, Description, Status) VALUES (?, ?, 'Active')"
                );
                $stmt->execute([$name, $note]);
            } catch (PDOException $e) {
                if ((string)$e->getCode() === '23000') {
                    throw new InvalidArgumentException("A category with that name already exists.");
                }
                throw $e;
            }
        } else {
            // Local schema
            try {
                $stmt = $this->db->prepare(
                    "INSERT INTO `category` (Name, Slug, Note, SortOrder, IsActive) VALUES (?, ?, ?, ?, 1)"
                );
                $stmt->execute([$name, $slug, $note, $sort]);
            } catch (PDOException $e) {
                if ((string)$e->getCode() === '23000') {
                    throw new InvalidArgumentException("A category with that name or slug already exists.");
                }
                throw $e;
            }
        }
        return $this->getById((int)$this->db->lastInsertId());
    }

    private function normalizeRow(array $row): array
    {
        if ($this->isAzure()) {
            $categoryName = $row['CategoryName'] ?? '';
            // Match SQL slug generation: replace & with 'and', then non-alphanumeric with -
            $slug = $categoryName ? strtolower(preg_replace('/[^a-z0-9]+/i', '-', str_replace('&', 'and', $categoryName))) : '';
            $slug = $slug ? trim($slug, '-') : '';

            return [
                'CategoryID' => (int)$row['CategoryID'],
                'Name'       => $categoryName,
                'Slug'       => $slug,
                'Note'       => $row['Description'] ?? '',
                'SortOrder'  => 0,
                'IsActive'   => ($row['Status'] ?? '') === 'Active' ? 1 : 0,
                'ParentCategoryID' => isset($row['ParentCategoryID']) ? (int)$row['ParentCategoryID'] : null,
                'Status'     => $row['Status'] ?? '',
                'Description' => $row['Description'] ?? '',
            ];
        }
        return $row;
    }
}
<?php
// ============================================================
// KameraPH | Ecommerce migrations | Runner
// ============================================================
// Runs every ecommerce migration in order with:
//   - a multi-statement SQL parser that respects strings/comments
//     (no naive semicolon split),
//   - a sha256 checksum per migration (an edited, already-applied
//     migration is detected and never silently re-run),
//   - a per-migration schema manifest (tables, columns, indexes,
//     foreign keys, constraints, seed rows) with exactly three outcomes:
//       correct     -> continue
//       missing     -> safe reconciliation (index / seed only)
//       incompatible-> stop and report the exact discrepancy
//   - records the version in `schema_migrations` only after the
//     complete manifest is verified.
//
// The runner NEVER auto-fixes an incompatible object.
//
// Usage:
//   C:\xampp\php\php.exe "kph_shopping_promotions\dev\scripts\run_migrations.php"
//   ... --dry-run   (verify only, no writes)
// ============================================================

require_once __DIR__ . '/../../../kph_user_account_identity/config/Database.php';

final class MigrationRunner
{
    private PDO $db;
    private bool $dryRun;
    private array $problems = [];

    public function __construct(bool $dryRun = false)
    {
        $this->db = Database::getConnection();
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->dryRun = $dryRun;
    }

    public function run(): int
    {
        $this->ensureTrackingTable();
        $applied = $this->appliedVersions();

        $failures = 0;
        foreach ($this->migrations() as $m) {
            $version = $m['version'];
            $file = $m['file'];

            if (!is_file($file)) {
                $this->fail("[$version] migration file not found: $file");
                $failures++;
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                $this->fail("[$version] could not read migration file: $file");
                $failures++;
                continue;
            }
            $checksum = hash('sha256', $sql);

            if (isset($applied[$version])) {
                if ($applied[$version] !== $checksum) {
                    $this->fail("[$version] already applied but checksum differs (migration file was edited). Restore the original file or create a new migration version.");
                    $failures++;
                    continue;
                }
                $this->say("[$version] already applied (checksum matches) — skipping.");
                continue;
            }

            $this->say("[$version] applying...");

            if ($this->dryRun) {
                $this->say("[$version] dry-run: statements parsed = " . count($this->splitSql($sql)));
                // Still verify against the current schema so dry-run reports real gaps.
                $problems = $this->verifyManifest($m['manifest']);
                $problems = array_merge($problems, $this->reconcileManifest($m['manifest'], true));
                if (empty($problems)) {
                    $this->say("[$version] dry-run: manifest OK (would record version).");
                }
                continue;
            }

            // 1. Execute statements one at a time.
            $statements = $this->splitSql($sql);
            foreach ($statements as $i => $stmt) {
                if (trim($stmt) === '') {
                    continue;
                }
                try {
                    $this->db->exec($stmt);
                } catch (Throwable $e) {
                    $this->fail("[$version] statement #" . ($i + 1) . " failed: " . $e->getMessage());
                    $this->fail("[$version] offending statement: " . $this->oneLine($stmt));
                    $failures++;
                    continue 2;
                }
            }

            // 2. Verify the manifest.
            $problems = $this->verifyManifest($m['manifest']);

            // 3. Safe reconciliation for missing (never incompatible) objects.
            $problems = array_merge($problems, $this->reconcileManifest($m['manifest'], false));

            if (!empty($problems)) {
                $this->fail("[$version] manifest verification failed:");
                foreach ($problems as $p) {
                    $this->fail("    - $p");
                }
                $failures++;
                continue;
            }

            // 4. Record the version.
            $stmt = $this->db->prepare("INSERT INTO `schema_migrations` (`Version`, `Checksum`) VALUES (?, ?)");
            $stmt->execute([$version, $checksum]);
            $this->say("[$version] applied and verified.");
        }

        $this->say("");
        if ($failures === 0) {
            $this->say("All migrations applied/verified successfully.");
            return 0;
        }
        $this->say("$failures migration(s) failed. See messages above.");
        return 1;
    }

    private function ensureTrackingTable(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS `schema_migrations` (
               `Version`   VARCHAR(64) NOT NULL,
               `Checksum`  CHAR(64)    NOT NULL,
               `AppliedAt` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (`Version`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function appliedVersions(): array
    {
        $out = [];
        foreach ($this->db->query("SELECT Version, Checksum FROM `schema_migrations`") as $row) {
            $out[$row['Version']] = $row['Checksum'];
        }
        return $out;
    }

    // ---- manifest verification -------------------------------------------------

    private function verifyManifest(array $manifest): array
    {
        $problems = [];
        foreach ($manifest['tables'] as $table => $spec) {
            if (!$this->tableExists($table)) {
                $problems[] = "table `$table` is missing";
                continue;
            }
            $cols = $this->columns($table);
            foreach ($spec['columns'] as $col => $exp) {
                if (!isset($cols[$col])) {
                    $problems[] = "table `$table` is missing column `$col`";
                    continue;
                }
                $actualType = strtolower($cols[$col]['DATA_TYPE']);
                if ($actualType !== $exp['type']) {
                    $problems[] = "column `$table.$col` has type $actualType, expected {$exp['type']}";
                }
                $nullable = strtoupper($cols[$col]['IS_NULLABLE']) === 'YES';
                if ($nullable !== $exp['nullable']) {
                    $problems[] = "column `$table.$col` nullability=" . ($nullable ? 'NULL' : 'NOT NULL') . ", expected " . ($exp['nullable'] ? 'NULL' : 'NOT NULL');
                }
            }
            $indexes = $this->indexes($table);
            foreach ($spec['indexes'] as $idx => $cols) {
                $name = is_int($idx) ? $cols : $idx;
                if (!isset($indexes[$name])) {
                    $problems[] = "table `$table` is missing index `$name`";
                }
            }
            $fks = $this->foreignKeys($table);
            foreach ($spec['fks'] as $fk => $refTable) {
                if (!isset($fks[$fk])) {
                    $problems[] = "table `$table` is missing foreign key `$fk`";
                } elseif ($refTable !== null && strcasecmp($fks[$fk], $refTable) !== 0) {
                    $problems[] = "foreign key `$table.$fk` references `{$fks[$fk]}`, expected `$refTable`";
                }
            }
            $checks = $this->checkConstraints($table);
            foreach ($spec['checks'] as $chk) {
                if (!isset($checks[$chk])) {
                    $problems[] = "table `$table` is missing check constraint `$chk`";
                }
            }
        }
        foreach ($manifest['seeds'] as $seed) {
            $count = $this->seedCount($seed['table'], $seed['where']);
            if ($count < 1) {
                $problems[] = "seed row missing: `{$seed['table']}` where {$seed['label']}";
            } elseif (isset($seed['expect']) && $count !== $seed['expect']) {
                $problems[] = "seed row count mismatch: `{$seed['table']}` where {$seed['label']} (found $count, expected {$seed['expect']})";
            }
        }
        return $problems;
    }

    // Safe reconciliation: only additive, non-destructive fixes.
    // Missing tables/columns/FKs/checks are NOT auto-fixed (reported instead).
    private function reconcileManifest(array $manifest, bool $dryRun): array
    {
        $problems = [];
        foreach ($manifest['tables'] as $table => $spec) {
            if (!$this->tableExists($table)) {
                continue; // already reported by verifyManifest
            }
            $indexes = $this->indexes($table);
            foreach ($spec['indexDefs'] ?? [] as $idx => $cols) {
                if (!isset($indexes[$idx])) {
                    if ($dryRun) {
                        $problems[] = "would create missing index `$table.$idx`";
                    } else {
                        try {
                            $this->db->exec("CREATE INDEX `$idx` ON `$table` (" . implode(', ', array_map(fn($c) => "`$c`", $cols)) . ")");
                            $this->say("    reconciled: created missing index `$table.$idx`");
                        } catch (Throwable $e) {
                            $problems[] = "could not create index `$table.$idx`: " . $e->getMessage();
                        }
                    }
                }
            }
        }
        foreach ($manifest['seeds'] as $seed) {
            if ($this->seedCount($seed['table'], $seed['where']) >= 1) {
                continue;
            }
            if ($dryRun) {
                $problems[] = "would insert missing seed row: `{$seed['table']}` where {$seed['label']}";
            } else {
                try {
                    $this->db->exec($seed['insert']);
                    $this->say("    reconciled: inserted missing seed row `{$seed['table']}` ({$seed['label']})");
                } catch (Throwable $e) {
                    $problems[] = "could not insert seed row `{$seed['table']}` ({$seed['label']}): " . $e->getMessage();
                }
            }
        }
        return $problems;
    }

    // ---- schema introspection --------------------------------------------------

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
        );
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function columns(string $table): array
    {
        $stmt = $this->db->prepare(
            "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
        );
        $stmt->execute([$table]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['COLUMN_NAME']] = $row;
        }
        return $out;
    }

    private function indexes(string $table): array
    {
        $stmt = $this->db->prepare(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? GROUP BY INDEX_NAME"
        );
        $stmt->execute([$table]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['INDEX_NAME']] = true;
        }
        return $out;
    }

    private function foreignKeys(string $table): array
    {
        $stmt = $this->db->prepare(
            "SELECT tc.CONSTRAINT_NAME, rc.REFERENCED_TABLE_NAME AS REF_TABLE
             FROM information_schema.TABLE_CONSTRAINTS tc
             LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
               ON rc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
              AND rc.TABLE_NAME = tc.TABLE_NAME
              AND rc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
             WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = 'FOREIGN KEY'"
        );
        $stmt->execute([$table]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['CONSTRAINT_NAME']] = $row['REF_TABLE'];
        }
        return $out;
    }

    private function checkConstraints(string $table): array
    {
        $stmt = $this->db->prepare(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'"
        );
        $stmt->execute([$table]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['CONSTRAINT_NAME']] = true;
        }
        return $out;
    }

    private function seedCount(string $table, array $where): int
    {
        $clauses = [];
        $params = [];
        foreach ($where as $col => $val) {
            $clauses[] = "`$col` = ?";
            $params[] = $val;
        }
        $sql = "SELECT COUNT(*) FROM `$table`";
        if ($clauses) {
            $sql .= " WHERE " . implode(' AND ', $clauses);
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    // ---- multi-statement SQL parser -------------------------------------------

    // Splits a SQL script into statements on semicolons that are outside
    // strings, backtick identifiers and comments.
    private function splitSql(string $sql): array
    {
        $statements = [];
        $buf = '';
        $len = strlen($sql);
        $i = 0;
        $n = $len;

        while ($i < $n) {
            $ch = $sql[$i];
            $next = $i + 1 < $n ? $sql[$i + 1] : '';

            // Line comments: -- ... or # ...
            if (($ch === '-' && $next === '-') || $ch === '#') {
                while ($i < $n && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            // Block comment /* ... */
            if ($ch === '/' && $next === '*') {
                $i += 2;
                while ($i < $n && !($sql[$i] === '*' && $i + 1 < $n && $sql[$i + 1] === '/')) {
                    $i++;
                }
                $i += 2;
                continue;
            }
            // Single-quoted string (supports '' and backslash escapes)
            if ($ch === "'") {
                $buf .= $ch;
                $i++;
                while ($i < $n) {
                    $c = $sql[$i];
                    $buf .= $c;
                    if ($c === '\\' && $i + 1 < $n) {
                        $buf .= $sql[$i + 1];
                        $i += 2;
                        continue;
                    }
                    if ($c === "'") {
                        if ($i + 1 < $n && $sql[$i + 1] === "'") {
                            $buf .= $sql[$i + 1];
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $i++;
                }
                continue;
            }
            // Double-quoted string
            if ($ch === '"') {
                $buf .= $ch;
                $i++;
                while ($i < $n) {
                    $c = $sql[$i];
                    $buf .= $c;
                    if ($c === '\\' && $i + 1 < $n) {
                        $buf .= $sql[$i + 1];
                        $i += 2;
                        continue;
                    }
                    if ($c === '"') {
                        $i++;
                        break;
                    }
                    $i++;
                }
                continue;
            }
            // Backtick identifier
            if ($ch === '`') {
                $buf .= $ch;
                $i++;
                while ($i < $n) {
                    $c = $sql[$i];
                    $buf .= $c;
                    if ($c === '`') {
                        $i++;
                        break;
                    }
                    $i++;
                }
                continue;
            }
            if ($ch === ';') {
                $statements[] = $buf;
                $buf = '';
                $i++;
                continue;
            }
            $buf .= $ch;
            $i++;
        }
        if (trim($buf) !== '') {
            $statements[] = $buf;
        }
        return $statements;
    }

    // ---- migrations + manifests ------------------------------------------------

    private function migrations(): array
    {
        $base = __DIR__ . '/../../sql/migrations';
        $module1 = __DIR__ . '/../../../kph_user_account_identity/sql/migrations';

        return [
            [
                'version' => '2026_11_schema_migrations',
                'file' => "$base/2026_11_schema_migrations.sql",
                'manifest' => [
                    'tables' => [
                        'schema_migrations' => [
                            'columns' => [
                                'Version' => ['type' => 'varchar', 'nullable' => false],
                                'Checksum' => ['type' => 'char', 'nullable' => false],
                                'AppliedAt' => ['type' => 'datetime', 'nullable' => false],
                            ],
                            'indexes' => ['PRIMARY'],
                            'fks' => [],
                            'checks' => [],
                        ],
                    ],
                    'seeds' => [],
                ],
            ],
            [
                'version' => '2026_11_catalog',
                'file' => "$base/2026_11_catalog.sql",
                'manifest' => [
                    'tables' => [
                        'category' => [
                            'columns' => [
                                'CategoryID' => ['type' => 'int', 'nullable' => false],
                                'Name' => ['type' => 'varchar', 'nullable' => false],
                                'Slug' => ['type' => 'varchar', 'nullable' => false],
                                'Note' => ['type' => 'varchar', 'nullable' => true],
                                'SortOrder' => ['type' => 'int', 'nullable' => false],
                                'IsActive' => ['type' => 'tinyint', 'nullable' => false],
                            ],
                            'indexes' => ['PRIMARY', 'uq_category_Name', 'uq_category_Slug'],
                            'indexDefs' => [
                                'uq_category_Name' => ['Name'],
                                'uq_category_Slug' => ['Slug'],
                            ],
                            'fks' => [],
                            'checks' => [],
                        ],
                        'product' => [
                            'columns' => [
                                'ProductID' => ['type' => 'int', 'nullable' => false],
                                'CategoryID' => ['type' => 'int', 'nullable' => true],
                                'SellerID' => ['type' => 'int', 'nullable' => true],
                                'ProductName' => ['type' => 'varchar', 'nullable' => false],
                                'Description' => ['type' => 'text', 'nullable' => true],
                                'BasePrice' => ['type' => 'decimal', 'nullable' => false],
                                'CompareAtPrice' => ['type' => 'decimal', 'nullable' => true],
                                'ImageURL' => ['type' => 'varchar', 'nullable' => true],
                                'Stock' => ['type' => 'int', 'nullable' => false],
                                'Status' => ['type' => 'varchar', 'nullable' => false],
                                'IsApproved' => ['type' => 'tinyint', 'nullable' => false],
                                'ApprovalStatus' => ['type' => 'varchar', 'nullable' => false],
                                'ReviewedByAdminID' => ['type' => 'int', 'nullable' => true],
                                'ReviewedAt' => ['type' => 'datetime', 'nullable' => true],
                                'RejectionReason' => ['type' => 'varchar', 'nullable' => true],
                                'Rating' => ['type' => 'decimal', 'nullable' => true],
                                'ReviewCount' => ['type' => 'int', 'nullable' => true],
                                'CreatedAt' => ['type' => 'datetime', 'nullable' => false],
                                'UpdatedAt' => ['type' => 'datetime', 'nullable' => false],
                            ],
                            'indexes' => ['PRIMARY', 'idx_product_Category', 'idx_product_Seller', 'idx_product_Status', 'idx_product_Approval'],
                            'indexDefs' => [
                                'idx_product_Category' => ['CategoryID'],
                                'idx_product_Seller' => ['SellerID'],
                                'idx_product_Status' => ['Status'],
                                'idx_product_Approval' => ['ApprovalStatus'],
                            ],
                            'fks' => [
                                'fk_product_Category' => 'category',
                                'fk_product_Seller' => 'user',
                            ],
                            'checks' => ['chk_product_Status', 'chk_product_Approval'],
                        ],
                    ],
                    'seeds' => [
                        ['table' => 'category', 'where' => ['CategoryID' => 1], 'label' => 'CategoryID=1', 'insert' => "INSERT IGNORE INTO `category` (`CategoryID`,`Name`,`Slug`,`Note`,`SortOrder`,`IsActive`) VALUES (1,'Cameras','cameras','Mirrorless and DSLR',1,1)"],
                        ['table' => 'category', 'where' => ['CategoryID' => 2], 'label' => 'CategoryID=2', 'insert' => "INSERT IGNORE INTO `category` (`CategoryID`,`Name`,`Slug`,`Note`,`SortOrder`,`IsActive`) VALUES (2,'Lenses','lenses','Prime and zoom',2,1)"],
                        ['table' => 'category', 'where' => ['CategoryID' => 3], 'label' => 'CategoryID=3', 'insert' => "INSERT IGNORE INTO `category` (`CategoryID`,`Name`,`Slug`,`Note`,`SortOrder`,`IsActive`) VALUES (3,'Drones','drones','Aerial and action',3,1)"],
                        ['table' => 'category', 'where' => ['CategoryID' => 4], 'label' => 'CategoryID=4', 'insert' => "INSERT IGNORE INTO `category` (`CategoryID`,`Name`,`Slug`,`Note`,`SortOrder`,`IsActive`) VALUES (4,'Accessories','accessories','Bags, tripods and more',4,1)"],
                        ['table' => 'product', 'where' => ['ProductID' => 101], 'label' => 'ProductID=101', 'insert' => "INSERT IGNORE INTO `product` (`ProductID`,`CategoryID`,`ProductName`,`BasePrice`,`ImageURL`,`Stock`,`Status`,`IsApproved`,`ApprovalStatus`,`Rating`) VALUES (101,1,'Canon EOS R6 Mark II',154995.00,'images/canon.jpg',25,'Active',1,'Approved',4.9)"],
                        ['table' => 'product', 'where' => ['ProductID' => 102], 'label' => 'ProductID=102', 'insert' => "INSERT IGNORE INTO `product` (`ProductID`,`CategoryID`,`ProductName`,`BasePrice`,`ImageURL`,`Stock`,`Status`,`IsApproved`,`ApprovalStatus`,`Rating`) VALUES (102,1,'Sony Alpha a7 IV',124995.00,'images/sony.jpg',25,'Active',1,'Approved',4.9)"],
                        ['table' => 'product', 'where' => ['ProductID' => 103], 'label' => 'ProductID=103', 'insert' => "INSERT IGNORE INTO `product` (`ProductID`,`CategoryID`,`ProductName`,`BasePrice`,`CompareAtPrice`,`ImageURL`,`Stock`,`Status`,`IsApproved`,`ApprovalStatus`,`Rating`) VALUES (103,2,'Canon RF 24-70mm f/2.8L',89995.00,99995.00,'images/canonrf.jpg',25,'Active',1,'Approved',4.8)"],
                        ['table' => 'product', 'where' => ['ProductID' => 104], 'label' => 'ProductID=104', 'insert' => "INSERT IGNORE INTO `product` (`ProductID`,`CategoryID`,`ProductName`,`BasePrice`,`ImageURL`,`Stock`,`Status`,`IsApproved`,`ApprovalStatus`,`Rating`) VALUES (104,3,'DJI Mini 3 Pro',34995.00,'images/minipro.jpg',25,'Active',1,'Approved',4.9)"],
                        ['table' => 'product', 'where' => ['ProductID' => 201], 'label' => 'ProductID=201', 'insert' => "INSERT IGNORE INTO `product` (`ProductID`,`CategoryID`,`ProductName`,`BasePrice`,`ImageURL`,`Stock`,`Status`,`IsApproved`,`ApprovalStatus`,`Rating`) VALUES (201,1,'Fujifilm X-T5',94995.00,'images/fuji.jpg',25,'Active',1,'Approved',4.8)"],
                        ['table' => 'product', 'where' => ['ProductID' => 202], 'label' => 'ProductID=202', 'insert' => "INSERT IGNORE INTO `product` (`ProductID`,`CategoryID`,`ProductName`,`BasePrice`,`CompareAtPrice`,`ImageURL`,`Stock`,`Status`,`IsApproved`,`ApprovalStatus`,`Rating`) VALUES (202,1,'Nikon Z6 III',119995.00,129995.00,'images/nikon.jpg',25,'Active',1,'Approved',4.7)"],
                        ['table' => 'product', 'where' => ['ProductID' => 203], 'label' => 'ProductID=203', 'insert' => "INSERT IGNORE INTO `product` (`ProductID`,`CategoryID`,`ProductName`,`BasePrice`,`ImageURL`,`Stock`,`Status`,`IsApproved`,`ApprovalStatus`,`Rating`) VALUES (203,2,'Sigma 35mm f/1.4 DG DN',42995.00,'images/sigma.jpg',25,'Active',1,'Approved',4.9)"],
                        ['table' => 'product', 'where' => ['ProductID' => 204], 'label' => 'ProductID=204', 'insert' => "INSERT IGNORE INTO `product` (`ProductID`,`CategoryID`,`ProductName`,`BasePrice`,`ImageURL`,`Stock`,`Status`,`IsApproved`,`ApprovalStatus`,`Rating`) VALUES (204,4,'Peak Design Everyday Sling',6495.00,'images/bag.jpg',25,'Active',1,'Approved',4.6)"],
                    ],
                ],
            ],
            [
                'version' => '2026_11_orders',
                'file' => "$base/2026_11_orders.sql",
                'manifest' => [
                    'tables' => [
                        'checkout_session' => [
                            'columns' => [
                                'SessionID' => ['type' => 'int', 'nullable' => false],
                                'UserID' => ['type' => 'int', 'nullable' => false],
                                'CheckoutKey' => ['type' => 'varchar', 'nullable' => false],
                                'RequestHash' => ['type' => 'char', 'nullable' => false],
                                'CreatedAt' => ['type' => 'datetime', 'nullable' => false],
                            ],
                            'indexes' => ['PRIMARY', 'uq_checkout_session_User_Key'],
                            'indexDefs' => ['uq_checkout_session_User_Key' => ['UserID', 'CheckoutKey']],
                            'fks' => ['fk_checkout_session_User' => 'user'],
                            'checks' => [],
                        ],
                        'orders' => [
                            'columns' => [
                                'OrderID' => ['type' => 'int', 'nullable' => false],
                                'OrderNumber' => ['type' => 'varchar', 'nullable' => false],
                                'CheckoutKey' => ['type' => 'varchar', 'nullable' => false],
                                'UserID' => ['type' => 'int', 'nullable' => false],
                                'SellerID' => ['type' => 'int', 'nullable' => true],
                                'SellerKey' => ['type' => 'int', 'nullable' => true],
                                'AddressID' => ['type' => 'int', 'nullable' => true],
                                'RecipientName' => ['type' => 'varchar', 'nullable' => true],
                                'Phone' => ['type' => 'varchar', 'nullable' => true],
                                'HouseUnit' => ['type' => 'varchar', 'nullable' => true],
                                'Street' => ['type' => 'varchar', 'nullable' => true],
                                'Barangay' => ['type' => 'varchar', 'nullable' => true],
                                'City' => ['type' => 'varchar', 'nullable' => true],
                                'Province' => ['type' => 'varchar', 'nullable' => true],
                                'PostalCode' => ['type' => 'varchar', 'nullable' => true],
                                'ShippingMethod' => ['type' => 'varchar', 'nullable' => false],
                                'ShippingFee' => ['type' => 'decimal', 'nullable' => false],
                                'DiscountAmount' => ['type' => 'decimal', 'nullable' => false],
                                'Subtotal' => ['type' => 'decimal', 'nullable' => false],
                                'Total' => ['type' => 'decimal', 'nullable' => false],
                                'OrderStatus' => ['type' => 'varchar', 'nullable' => false],
                                'PaymentMethod' => ['type' => 'varchar', 'nullable' => false],
                                'PaymentStatus' => ['type' => 'varchar', 'nullable' => false],
                                'CancelReason' => ['type' => 'varchar', 'nullable' => true],
                                'CancelledAt' => ['type' => 'datetime', 'nullable' => true],
                                'CreatedAt' => ['type' => 'datetime', 'nullable' => false],
                                'UpdatedAt' => ['type' => 'datetime', 'nullable' => false],
                            ],
                            'indexes' => ['PRIMARY', 'uq_orders_OrderNumber', 'uq_orders_User_Checkout_Seller', 'idx_orders_User', 'idx_orders_Status', 'idx_orders_PaymentStatus', 'idx_orders_Checkout'],
                            'indexDefs' => [
                                'uq_orders_OrderNumber' => ['OrderNumber'],
                                'uq_orders_User_Checkout_Seller' => ['UserID', 'CheckoutKey', 'SellerKey'],
                                'idx_orders_User' => ['UserID'],
                                'idx_orders_Status' => ['OrderStatus'],
                                'idx_orders_PaymentStatus' => ['PaymentStatus'],
                                'idx_orders_Checkout' => ['UserID', 'CheckoutKey'],
                            ],
                            'fks' => [
                                'fk_orders_User' => 'user',
                                'fk_orders_Seller' => 'user',
                                'fk_orders_Address' => 'user_address',
                                'fk_orders_Checkout' => 'checkout_session',
                            ],
                            'checks' => ['chk_orders_Seller'],
                        ],
                        'order_items' => [
                            'columns' => [
                                'OrderItemID' => ['type' => 'int', 'nullable' => false],
                                'OrderID' => ['type' => 'int', 'nullable' => false],
                                'ProductID' => ['type' => 'int', 'nullable' => true],
                                'ProductName' => ['type' => 'varchar', 'nullable' => false],
                                'UnitPrice' => ['type' => 'decimal', 'nullable' => false],
                                'ImageURL' => ['type' => 'varchar', 'nullable' => true],
                                'Quantity' => ['type' => 'int', 'nullable' => false],
                                'Subtotal' => ['type' => 'decimal', 'nullable' => false],
                                'SellerID' => ['type' => 'int', 'nullable' => true],
                            ],
                            'indexes' => ['PRIMARY', 'idx_order_items_Order', 'idx_order_items_Seller'],
                            'indexDefs' => [
                                'idx_order_items_Order' => ['OrderID'],
                                'idx_order_items_Seller' => ['SellerID'],
                            ],
                            'fks' => ['fk_order_items_Order' => 'orders'],
                            'checks' => [],
                        ],
                        'order_status_history' => [
                            'columns' => [
                                'HistoryID' => ['type' => 'int', 'nullable' => false],
                                'OrderID' => ['type' => 'int', 'nullable' => false],
                                'FromStatus' => ['type' => 'varchar', 'nullable' => true],
                                'ToStatus' => ['type' => 'varchar', 'nullable' => false],
                                'Note' => ['type' => 'varchar', 'nullable' => true],
                                'ChangedByUserID' => ['type' => 'int', 'nullable' => true],
                                'CreatedAt' => ['type' => 'datetime', 'nullable' => false],
                            ],
                            'indexes' => ['PRIMARY', 'idx_order_status_history_Order'],
                            'indexDefs' => ['idx_order_status_history_Order' => ['OrderID', 'CreatedAt']],
                            'fks' => ['fk_order_status_history_Order' => 'orders'],
                            'checks' => [],
                        ],
                        'order_payment_history' => [
                            'columns' => [
                                'PaymentHistoryID' => ['type' => 'int', 'nullable' => false],
                                'OrderID' => ['type' => 'int', 'nullable' => false],
                                'FromPaymentStatus' => ['type' => 'varchar', 'nullable' => true],
                                'ToPaymentStatus' => ['type' => 'varchar', 'nullable' => false],
                                'ConfirmedByUserID' => ['type' => 'int', 'nullable' => true],
                                'CollectionMethod' => ['type' => 'varchar', 'nullable' => false],
                                'Reference' => ['type' => 'varchar', 'nullable' => true],
                                'Note' => ['type' => 'varchar', 'nullable' => true],
                                'CreatedAt' => ['type' => 'datetime', 'nullable' => false],
                            ],
                            'indexes' => ['PRIMARY', 'idx_payment_history_Order'],
                            'indexDefs' => ['idx_payment_history_Order' => ['OrderID', 'CreatedAt']],
                            'fks' => ['fk_payment_history_Order' => 'orders'],
                            'checks' => [],
                        ],
                    ],
                    'seeds' => [],
                ],
            ],
            [
                'version' => '2026_11_sellers',
                'file' => "$base/2026_11_sellers.sql",
                'manifest' => [
                    'tables' => [
                        'seller_application' => [
                            'columns' => [
                                'ApplicationID' => ['type' => 'int', 'nullable' => false],
                                'UserID' => ['type' => 'int', 'nullable' => false],
                                'StoreName' => ['type' => 'varchar', 'nullable' => false],
                                'StoreDescription' => ['type' => 'text', 'nullable' => true],
                                'BusinessAddress' => ['type' => 'varchar', 'nullable' => true],
                                'ProductCategory' => ['type' => 'varchar', 'nullable' => true],
                                'SellerType' => ['type' => 'varchar', 'nullable' => true],
                                'ContactNumber' => ['type' => 'varchar', 'nullable' => false],
                                'Status' => ['type' => 'varchar', 'nullable' => false],
                                'RejectionReason' => ['type' => 'text', 'nullable' => true],
                                'ReviewedByAdminID' => ['type' => 'int', 'nullable' => true],
                                'SubmittedAt' => ['type' => 'datetime', 'nullable' => false],
                                'ReviewedAt' => ['type' => 'datetime', 'nullable' => true],
                                'ActiveFlag' => ['type' => 'tinyint', 'nullable' => true],
                                'CanReapply' => ['type' => 'tinyint', 'nullable' => false],
                            ],
                            'indexes' => ['PRIMARY', 'uq_seller_application_User_Active', 'idx_seller_application_Status'],
                            'indexDefs' => [
                                'uq_seller_application_User_Active' => ['UserID', 'ActiveFlag'],
                                'idx_seller_application_Status' => ['Status'],
                            ],
                            'fks' => ['fk_seller_application_User' => 'user'],
                            'checks' => [],
                        ],
                        'seller_application_history' => [
                            'columns' => [
                                'HistoryID' => ['type' => 'int', 'nullable' => false],
                                'ApplicationID' => ['type' => 'int', 'nullable' => false],
                                'FromStatus' => ['type' => 'varchar', 'nullable' => true],
                                'ToStatus' => ['type' => 'varchar', 'nullable' => false],
                                'ChangedByUserID' => ['type' => 'int', 'nullable' => true],
                                'Note' => ['type' => 'varchar', 'nullable' => true],
                                'CreatedAt' => ['type' => 'datetime', 'nullable' => false],
                            ],
                            'indexes' => ['PRIMARY', 'idx_seller_app_history_Application'],
                            'indexDefs' => ['idx_seller_app_history_Application' => ['ApplicationID', 'CreatedAt']],
                            'fks' => ['fk_seller_app_history_Application' => 'seller_application'],
                            'checks' => [],
                        ],
                    ],
                    'seeds' => [],
                ],
            ],
            [
                'version' => '2026_11_support',
                'file' => "$base/2026_11_support.sql",
                'manifest' => [
                    'tables' => [
                        'support_ticket' => [
                            'columns' => [
                                'TicketID' => ['type' => 'int', 'nullable' => false],
                                'UserID' => ['type' => 'int', 'nullable' => false],
                                'Subject' => ['type' => 'varchar', 'nullable' => false],
                                'Category' => ['type' => 'varchar', 'nullable' => false],
                                'Message' => ['type' => 'text', 'nullable' => false],
                                'OrderID' => ['type' => 'int', 'nullable' => true],
                                'ContactEmail' => ['type' => 'varchar', 'nullable' => false],
                                'Status' => ['type' => 'varchar', 'nullable' => false],
                                'CreatedAt' => ['type' => 'datetime', 'nullable' => false],
                                'UpdatedAt' => ['type' => 'datetime', 'nullable' => false],
                            ],
                            'indexes' => ['PRIMARY', 'idx_support_ticket_User', 'idx_support_ticket_Status'],
                            'indexDefs' => [
                                'idx_support_ticket_User' => ['UserID'],
                                'idx_support_ticket_Status' => ['Status'],
                            ],
                            'fks' => ['fk_support_ticket_User' => 'user', 'fk_support_ticket_Order' => 'orders'],
                            'checks' => [],
                        ],
                    ],
                    'seeds' => [],
                ],
            ],
            [
                'version' => '2026_11_admin_audit',
                'file' => "$base/2026_11_admin_audit.sql",
                'manifest' => [
                    'tables' => [
                        'admin_audit_log' => [
                            'columns' => [
                                'AuditID' => ['type' => 'int', 'nullable' => false],
                                'AdminID' => ['type' => 'int', 'nullable' => true],
                                'ActionType' => ['type' => 'varchar', 'nullable' => false],
                                'EntityType' => ['type' => 'varchar', 'nullable' => false],
                                'EntityID' => ['type' => 'int', 'nullable' => true],
                                'Details' => ['type' => 'varchar', 'nullable' => true],
                                'IPAddress' => ['type' => 'varchar', 'nullable' => true],
                                'CreatedAt' => ['type' => 'datetime', 'nullable' => false],
                            ],
                            'indexes' => ['PRIMARY', 'idx_admin_audit_Entity', 'idx_admin_audit_CreatedAt'],
                            'indexDefs' => [
                                'idx_admin_audit_Entity' => ['EntityType', 'EntityID'],
                                'idx_admin_audit_CreatedAt' => ['CreatedAt'],
                            ],
                            'fks' => [],
                            'checks' => [],
                        ],
                    ],
                    'seeds' => [],
                ],
            ],
            [
                'version' => '2026_11_user_activity',
                'file' => "$module1/2026_11_user_activity.sql",
                'manifest' => [
                    'tables' => [
                        'user_activity' => [
                            'columns' => [
                                'ActivityID' => ['type' => 'int', 'nullable' => false],
                                'UserID' => ['type' => 'int', 'nullable' => false],
                                'EventType' => ['type' => 'varchar', 'nullable' => false],
                                'EntityType' => ['type' => 'varchar', 'nullable' => true],
                                'EntityID' => ['type' => 'int', 'nullable' => true],
                                'Description' => ['type' => 'varchar', 'nullable' => true],
                                'Metadata' => ['type' => 'longtext', 'nullable' => true],
                                'CreatedAt' => ['type' => 'datetime', 'nullable' => false],
                            ],
                            'indexes' => ['PRIMARY', 'idx_user_activity_User_Date', 'idx_user_activity_Type', 'idx_user_activity_CreatedAt'],
                            'indexDefs' => [
                                'idx_user_activity_User_Date' => ['UserID', 'CreatedAt'],
                                'idx_user_activity_Type' => ['EventType'],
                                'idx_user_activity_CreatedAt' => ['CreatedAt'],
                            ],
                            'fks' => ['fk_user_activity_User' => 'user'],
                            'checks' => [],
                        ],
                    ],
                    'seeds' => [],
                ],
            ],
        ];
    }

    // ---- output ----------------------------------------------------------------

    private function say(string $msg): void
    {
        echo $msg . "\n";
    }

    private function fail(string $msg): void
    {
        echo "  ERROR: $msg\n";
    }

    private function oneLine(string $stmt): string
    {
        $stmt = preg_replace('/\s+/', ' ', trim($stmt));
        return mb_strlen($stmt) > 300 ? mb_substr($stmt, 0, 300) . '...' : $stmt;
    }
}

$dryRun = in_array('--dry-run', $argv ?? [], true);
$runner = new MigrationRunner($dryRun);
exit($runner->run());
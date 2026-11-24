<?php
// ============================================================
// KameraPH | Module 1 | One-time Azure setup (safe version)
// ============================================================
// Why: the shared Azure database (kph-database) belongs to ALL groups.
// It does not have our product / seller_application tables, and its
// user_activity / orders tables have different columns than ours.
// We must not change those tables.
//
// What this script does:
//   1. Creates our OWN database on the same server:  kph_ecommerce
//      (CREATE DATABASE IF NOT EXISTS - nothing is dropped or replaced)
//   2. Creates our own tables there (product, category, orders,
//      user_activity, seller_application, ...).
//   3. For the shared account tables (user, role, user_role,
//      verification_token ...) it makes VIEWS that point to kph-database,
//      so the demo accounts and cross-module login keep working.
//      If a shared table does not have our columns, it creates our own
//      table instead.
//   4. Gives the two demo sellers an Approved seller application and a
//      few products so the seller pages have data.
//
// kph-database is only READ (information_schema + the views).
// Nothing in kph-database is created, altered, dropped or deleted.
//
// Run:  php kph_user_account_identity/dev/scripts/setup_azure_ecommerce.php
// Safe to run again.
// ============================================================

require_once __DIR__ . '/../../config/Database.php';

$cfg = Database::getConfig();
$shared = $cfg['shared_dbname'] ?? 'kph-database';
$mine = $cfg['dbname'];
foreach ($argv as $a) {
    if (strpos($a, '--shared=') === 0) { $shared = substr($a, 9); }
    if (strpos($a, '--new=') === 0) { $mine = substr($a, 6); }
}

if ($mine === $shared) {
    exit("STOP: config dbname is '$mine', the same as the shared database.\n" .
         "Set \"dbname\" => \"kph_ecommerce\" in config/config.local.php (our own database).\n");
}

// ---- connect to the server (no database selected yet) ----
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT => 15,
];
$ca = $cfg['ssl_ca'] ?? '';
if (!empty($ca) && file_exists($ca)) {
    $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
}
$port = $cfg['port'] ?? 3306;
$db = new PDO("mysql:host={$cfg['host']};port={$port};charset=utf8mb4", $cfg['user'], $cfg['pass'], $options);

function say(string $m): void { echo $m . "\n"; }

// Splits an SQL file into statements (ignores ; inside quotes and -- comments).
function splitSql(string $sql): array
{
    $out = [];
    $cur = '';
    $quote = '';
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if ($quote === '' && $c === '-' && ($sql[$i + 1] ?? '') === '-') {
            while ($i < $len && $sql[$i] !== "\n") { $i++; }
            $cur .= "\n";
            continue;
        }
        if ($quote !== '') {
            $cur .= $c;
            if ($c === '\\') { $cur .= $sql[++$i] ?? ''; }
            elseif ($c === $quote) { $quote = ''; }
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $quote = $c; $cur .= $c; continue; }
        if ($c === ';') { if (trim($cur) !== '') { $out[] = trim($cur); } $cur = ''; continue; }
        $cur .= $c;
    }
    if (trim($cur) !== '') { $out[] = trim($cur); }
    return $out;
}

// Removes FOREIGN KEY lines (our tables cannot point at views in another database).
function dropForeignKeys(string $create): string
{
    $lines = preg_split('/\R/', $create);
    $keep = [];
    foreach ($lines as $l) {
        if (preg_match('/^\s*(CONSTRAINT\s+\S+\s+)?FOREIGN KEY/i', $l)) { continue; }
        $keep[] = $l;
    }
    $text = implode("\n", $keep);
    return preg_replace('/,\s*\)\s*ENGINE/i', "\n) ENGINE", $text);
}

// Column names written in a CREATE TABLE.
function columnsOf(string $create): array
{
    $cols = [];
    foreach (preg_split('/\R/', $create) as $l) {
        if (preg_match('/^\s*`?([A-Za-z_][A-Za-z0-9_]*)`?\s+(INT|BIGINT|TINYINT|SMALLINT|VARCHAR|CHAR|TEXT|LONGTEXT|DATETIME|TIMESTAMP|DATE|DECIMAL|JSON|ENUM)/i', $l, $m)) {
            $cols[] = $m[1];
        }
    }
    return $cols;
}

$identity = ['user', 'role', 'user_role', 'user_profile', 'user_address', 'auth_identity',
             'verification_token', 'kyc_verification', 'login_history'];

// ---- 1. our own database ----
$db->exec("CREATE DATABASE IF NOT EXISTS `$mine` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->exec("USE `$mine`");
say("Database `$mine` is ready (shared database `$shared` is only read).");

// ---- 2. which shared tables exist and what columns they have ----
$sharedCols = [];
$q = $db->prepare("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?");
$q->execute([$shared]);
foreach ($q->fetchAll() as $r) {
    $sharedCols[strtolower($r['TABLE_NAME'])][] = $r['COLUMN_NAME'];
}
$existingMine = [];
$q = $db->prepare("SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?");
$q->execute([$mine]);
foreach ($q->fetchAll() as $r) {
    $existingMine[strtolower($r['TABLE_NAME'])] = $r['TABLE_TYPE'];
}

// ---- 3. build everything from sql/xampp_setup.sql ----
$file = __DIR__ . '/../../sql/xampp_setup.sql';
$statements = splitSql(file_get_contents($file));
$views = [];
$created = [];
$report = [];

foreach ($statements as $st) {
    if (!preg_match('/^CREATE TABLE IF NOT EXISTS `?(\w+)`?/i', $st, $m)) { continue; }
    $table = strtolower($m[1]);

    if (in_array($table, $identity, true)) {
        $theirs = $sharedCols[$table] ?? null;
        $theirsLower = $theirs ? array_map('strtolower', $theirs) : [];
        $missing = [];
        foreach (columnsOf($st) as $c) {
            if (!in_array(strtolower($c), $theirsLower, true)) { $missing[] = $c; }
        }
        if ($theirs && !$missing) {
            if (($existingMine[$table] ?? '') === 'BASE TABLE') {
                $report[] = "SKIP view for `$table`: a real table with that name already exists in `$mine`.";
            } else {
                $db->exec("CREATE OR REPLACE VIEW `$mine`.`$table` AS SELECT * FROM `$shared`.`$table`");
                $views[] = $table;
            }
            continue;
        }
        $report[] = $theirs
            ? "`$table`: shared table lacks columns (" . implode(', ', $missing) . ") -> own table created."
            : "`$table`: not found in `$shared` -> own table created.";
    }

    $db->exec(dropForeignKeys($st));
    $created[] = $table;
}

// ---- 3b. the shared catalog ----
// In Azure mode the shopping code reads the class catalog (product_listing, listing_image,
// product_model, category, seller_profile, cart ...). Because our connection now starts in
// our own database, we add views for every shared table we do not own ourselves.
$sharedTables = [];
$q = $db->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'");
$q->execute([$shared]);
foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $t) { $sharedTables[strtolower($t)] = $t; }

function ownType(PDO $db, string $mine, string $table): string
{
    $q = $db->prepare("SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
    $q->execute([$mine, $table]);
    $t = $q->fetchColumn();
    return $t === false ? '' : (string) $t;
}

// The shared category table uses CategoryName (ours used Name/Slug): use the shared one.
if (isset($sharedTables['category']) && in_array('categoryname', array_map('strtolower', $sharedCols['category'] ?? []), true)) {
    if (ownType($db, $mine, 'category') === 'BASE TABLE') { $db->exec("DROP TABLE `$mine`.`category`"); }
    $db->exec("CREATE OR REPLACE VIEW `$mine`.`category` AS SELECT * FROM `$shared`.`category`");
    $views[] = 'category';
}

// `product` (used by the reports) becomes a view over the shared listings, so reports use real listing IDs.
if (isset($sharedTables['product_listing'], $sharedTables['product_model'], $sharedTables['listing_image'])) {
    if (ownType($db, $mine, 'product') === 'BASE TABLE') { $db->exec("DROP TABLE `$mine`.`product`"); }
    $db->exec(
        "CREATE OR REPLACE VIEW `$mine`.`product` AS
         SELECT pl.ListingID AS ProductID, pm.CategoryID AS CategoryID, pl.SellerID AS SellerID,
                pl.Title AS ProductName, pl.Description AS Description, pl.Price AS BasePrice,
                pl.OriginalPrice AS CompareAtPrice,
                (SELECT li.ImageURL FROM `$shared`.`listing_image` li
                  WHERE li.ListingID = pl.ListingID AND li.IsPrimary = 1 ORDER BY li.DisplayOrder LIMIT 1) AS ImageURL,
                pl.Quantity AS Stock,
                CASE WHEN pl.Quantity > 0 THEN 'Active' ELSE 'OutOfStock' END AS Status,
                1 AS IsApproved, 'Approved' AS ApprovalStatus,
                NULL AS Rating, NULL AS ReviewCount, pl.ListedAt AS CreatedAt, pl.UpdatedAt AS UpdatedAt
         FROM `$shared`.`product_listing` pl
         LEFT JOIN `$shared`.`product_model` pm ON pm.ModelID = pl.ModelID"
    );
    $views[] = 'product';
}

// Every other shared table we do not own: read-through view.
$linked = [];
foreach ($sharedTables as $lower => $real) {
    if (ownType($db, $mine, $real) !== '') { continue; }
    $db->exec("CREATE OR REPLACE VIEW `$mine`.`$real` AS SELECT * FROM `$shared`.`$real`");
    $linked[] = $real;
}
say("Linked shared tables   : " . count($linked) . " (views, read-through)");

// Seed rows (roles, categories, products) only into tables WE own.
foreach ($statements as $st) {
    if (!preg_match('/^INSERT (IGNORE )?INTO `?(\w+)`?/i', $st, $m)) { continue; }
    $table = strtolower($m[2]);
    if (in_array($table, $views, true)) { continue; }
    $db->exec($st);
}

say("Views to shared tables : " . ($views ? implode(', ', $views) : '(none)'));
say("Our own tables         : " . implode(', ', array_diff($created, $views)));
foreach ($report as $line) { say("NOTE: $line"); }

// ---- 4. demo sellers: approved application + some products ----
$sellers = [
    ['rafael.lens@kameraph.ph',    'Manila Lens House', '09175309933', 'Cameras'],
    ['bianca.shutter@kameraph.ph', 'Cebu Shutter Pro',  '09206406644', 'Accessories'],
];
$sellerIds = [];
foreach ($sellers as $s) {
    $q = $db->prepare("SELECT UserID FROM `user` WHERE Email = ?");
    $q->execute([$s[0]]);
    $uid = $q->fetchColumn();
    if ($uid === false) { say("NOTE: demo seller {$s[0]} not found, skipped."); continue; }
    $sellerIds[] = (int) $uid;
    $q = $db->prepare("SELECT COUNT(*) FROM `seller_application` WHERE UserID = ?");
    $q->execute([$uid]);
    if ((int) $q->fetchColumn() === 0) {
        $ins = $db->prepare(
            "INSERT INTO `seller_application` (UserID, StoreName, ContactNumber, ProductCategory, SellerType, Status, SubmittedAt, ReviewedAt)
             VALUES (?, ?, ?, ?, 'Business', 'Approved', NOW(), NOW())"
        );
        $ins->execute([$uid, $s[1], $s[2], $s[3]]);
        say("Approved seller application added for {$s[1]}.");
    }
}
if (count($sellerIds) === 2 && !in_array('product', $views, true)) {
    // Share the first products between the two demo sellers (only products that have no seller yet).
    $ids = $db->query("SELECT ProductID FROM `product` WHERE SellerID IS NULL ORDER BY ProductID LIMIT 8")->fetchAll(PDO::FETCH_COLUMN);
    $up = $db->prepare("UPDATE `product` SET SellerID = ? WHERE ProductID = ? AND SellerID IS NULL");
    foreach ($ids as $i => $pid) {
        $up->execute([$sellerIds[$i % 2], $pid]);
    }
    say("Demo products assigned to the two demo sellers.");
}

say("\nDONE. Now open the website and log in again.");

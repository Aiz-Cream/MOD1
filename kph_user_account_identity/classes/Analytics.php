<?php
// ============================================================
// KameraPH | Behaviour analytics + recommendations
// Reads the `user_activity` event store (and `product` for seller scoping).
// Roles: customer (own data), seller (own products), admin (platform).
// ============================================================
require_once __DIR__ . '/../config/Database.php';

class Analytics
{
    private PDO $db;
    // product_id lives in the JSON metadata of each event.
    private const PID = "CAST(JSON_UNQUOTE(JSON_EXTRACT(a.Metadata,'$.product_id')) AS UNSIGNED)";

    public function __construct() { $this->db = Database::getConnection(); }

    private function isAzure(): bool
    {
        $config = Database::getConfig();
        $host = $config['host'] ?? '';
        $env = $config['env'] ?? getenv('DB_ENV') ?: '';
        return strpos($host, 'mysql.database.azure.com') !== false || strtolower($env) === 'azure';
    }

    // Unified Azure DB stores listings in `product_listing` + `product_model` rather than `product`.
    private function productTable(): string
    {
        if ($this->isAzure()) {
            return "(SELECT pl.ListingID AS ProductID, pl.Title AS ProductName, pl.SellerID,
                            CASE WHEN pl.Status = 'Live' AND pl.Quantity > 0 THEN 'Active' ELSE pl.Status END AS Status,
                            pm.CategoryID
                     FROM `product_listing` pl
                     LEFT JOIN `product_model` pm ON pm.ModelID = pl.ModelID)";
        }
        return "`product`";
    }

    private function q(string $sql, array $p = []): array
    {
        $s = $this->db->prepare($sql);
        $s->execute($p);
        return $s->fetchAll();
    }

    // Optional seller filter: only products owned by that seller.
    private function sellerJoin(?int $sellerId, array &$p): string
    {
        $pt = $this->productTable();
        if ($sellerId === null) return "LEFT JOIN $pt pr ON pr.ProductID = " . self::PID;
        $p[] = $sellerId;
        return "JOIN $pt pr ON pr.ProductID = " . self::PID . " AND pr.SellerID = ?";
    }

    // ---------------- ANALYSIS (admin: $sellerId=null, seller: own id) ----------------

    public function summary(?int $sellerId, int $days = 30): array
    {
        $p = [];
        $j = $this->sellerJoin($sellerId, $p);
        $p[] = $days;
        $rows = $this->q(
            "SELECT a.EventType, COUNT(*) AS total, COUNT(DISTINCT a.UserID) AS users
             FROM `user_activity` a $j
             WHERE a.CreatedAt >= NOW() - INTERVAL ? DAY
               AND a.EventType IN ('product_viewed','cart_added','wishlist_added','checkout_started','order_placed')
             GROUP BY a.EventType", $p);
        $by = [];
        foreach ($rows as $r) $by[$r['EventType']] = (int)$r['total'];
        $views = $by['product_viewed'] ?? 0;
        $carts = $by['cart_added'] ?? 0;
        return [
            'days' => $days, 'events' => $by,
            'view_to_cart_rate' => $views ? round($carts / $views * 100, 1) : 0,
            'cart_to_order_rate' => $carts ? round(($by['order_placed'] ?? 0) / $carts * 100, 1) : 0,
            'active_users' => $sellerId === null ? $this->activeUsers($days) : null,
        ];
    }

    public function topViewed(?int $sellerId, int $limit = 10, int $days = 30): array
    {
        $p = [];
        $j = $this->sellerJoin($sellerId, $p);
        array_push($p, $days, $limit);
        return $this->q(
            "SELECT " . self::PID . " AS product_id, pr.ProductName, COUNT(*) AS views,
                    COUNT(DISTINCT a.UserID) AS unique_viewers
             FROM `user_activity` a $j
             WHERE a.EventType='product_viewed' AND a.CreatedAt >= NOW() - INTERVAL ? DAY
             GROUP BY product_id, pr.ProductName
             ORDER BY views DESC LIMIT ?", $p);
    }

    // Searches are not tied to a seller, so this is platform-wide (admin).
    public function popularSearches(int $limit = 10, int $days = 30): array
    {
        return $this->q(
            "SELECT LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(Metadata,'$.query')))) AS term,
                    COUNT(*) AS searches,
                    ROUND(AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(Metadata,'$.result_count')) AS UNSIGNED)),1) AS avg_results
             FROM `user_activity`
             WHERE EventType='product_searched' AND Metadata IS NOT NULL
               AND CreatedAt >= NOW() - INTERVAL ? DAY
             GROUP BY term HAVING term IS NOT NULL AND term <> ''
             ORDER BY searches DESC LIMIT ?", [$days, $limit]);
    }

    // Abandoned = added to cart > $hours ago, never removed and no order placed afterwards.
    public function abandonedCarts(?int $sellerId, int $hours = 24, int $limit = 20): array
    {
        $p = [];
        $j = $this->sellerJoin($sellerId, $p);
        array_push($p, $hours, $limit);
        return $this->q(
            "SELECT " . self::PID . " AS product_id, pr.ProductName,
                    COUNT(DISTINCT a.UserID) AS abandoned_by_users, COUNT(*) AS add_events
             FROM `user_activity` a $j
             WHERE a.EventType='cart_added' AND a.CreatedAt < NOW() - INTERVAL ? HOUR
               AND NOT EXISTS (SELECT 1 FROM `user_activity` o
                               WHERE o.UserID=a.UserID AND o.CreatedAt > a.CreatedAt
                                 AND o.EventType='order_placed')
               AND NOT EXISTS (SELECT 1 FROM `user_activity` r
                               WHERE r.UserID=a.UserID AND r.CreatedAt > a.CreatedAt
                                 AND r.EventType='cart_removed'
                                 AND JSON_UNQUOTE(JSON_EXTRACT(r.Metadata,'$.product_id'))
                                   = JSON_UNQUOTE(JSON_EXTRACT(a.Metadata,'$.product_id')))
             GROUP BY product_id, pr.ProductName
             ORDER BY abandoned_by_users DESC LIMIT ?", $p);
    }

    // ---------------- CUSTOMER ----------------

    public function recentlyViewed(int $userId, int $limit = 10): array
    {
        $pt = $this->productTable();
        return $this->q(
            "SELECT p.ProductID AS product_id, p.ProductName, MAX(a.CreatedAt) AS last_viewed
             FROM `user_activity` a JOIN $pt p ON p.ProductID = " . self::PID . "
             WHERE a.UserID=? AND a.EventType='product_viewed'
             GROUP BY p.ProductID, p.ProductName ORDER BY last_viewed DESC LIMIT ?", [$userId, $limit]);
    }

    // ---------------- RECOMMENDATIONS ----------------

    // "Customers who viewed this also viewed": co-view counts across users.
    public function alsoViewed(int $productId, int $limit = 6): array
    {
        $pt = $this->productTable();
        return $this->q(
            "SELECT p.ProductID AS product_id, p.ProductName, COUNT(DISTINCT b.UserID) AS shared_viewers
             FROM `user_activity` a
             JOIN `user_activity` b ON b.UserID=a.UserID AND b.EventType='product_viewed'
             JOIN $pt p ON p.ProductID = CAST(JSON_UNQUOTE(JSON_EXTRACT(b.Metadata,'$.product_id')) AS UNSIGNED)
             WHERE a.EventType='product_viewed'
               AND CAST(JSON_UNQUOTE(JSON_EXTRACT(a.Metadata,'$.product_id')) AS UNSIGNED) = ?
               AND p.ProductID <> ? AND p.Status='Active'
             GROUP BY p.ProductID, p.ProductName
             ORDER BY shared_viewers DESC LIMIT ?", [$productId, $productId, $limit]);
    }

    // Personalised: score products by (a) what similar users interacted with,
    // weighted view=1, wishlist=2, cart=3, and (b) the user's favourite categories.
    // Already-viewed/-carted products are excluded. Falls back to trending.
    public function recommendForUser(int $userId, int $limit = 6): array
    {
        $pt = $this->productTable();
        $rows = $this->q(
            "SELECT p.ProductID AS product_id, p.ProductName,
                    SUM(CASE o.EventType WHEN 'cart_added' THEN 3 WHEN 'wishlist_added' THEN 2 ELSE 1 END) AS score
             FROM `user_activity` me
             JOIN `user_activity` peer ON peer.UserID <> me.UserID
                  AND peer.EventType IN ('product_viewed','cart_added','wishlist_added')
                  AND JSON_UNQUOTE(JSON_EXTRACT(peer.Metadata,'$.product_id'))
                    = JSON_UNQUOTE(JSON_EXTRACT(me.Metadata,'$.product_id'))
             JOIN `user_activity` o ON o.UserID = peer.UserID
                  AND o.EventType IN ('product_viewed','cart_added','wishlist_added')
             JOIN $pt p ON p.ProductID = CAST(JSON_UNQUOTE(JSON_EXTRACT(o.Metadata,'$.product_id')) AS UNSIGNED)
             WHERE me.UserID=? AND me.EventType IN ('product_viewed','cart_added','wishlist_added')
               AND p.Status='Active'
               AND p.ProductID NOT IN (
                   SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(x.Metadata,'$.product_id')) AS UNSIGNED)
                   FROM `user_activity` x WHERE x.UserID=? AND x.Metadata IS NOT NULL
                     AND JSON_EXTRACT(x.Metadata,'$.product_id') IS NOT NULL)
             GROUP BY p.ProductID, p.ProductName
             ORDER BY score DESC LIMIT ?", [$userId, $userId, $limit]);
        if (count($rows) >= $limit) return ['source' => 'similar_users', 'items' => $rows];

        // Fallback / top-up: trending products the user hasn't seen.
        $trend = $this->topViewed(null, $limit);
        $seen = array_column($rows, 'product_id');
        foreach ($trend as $t) {
            if (count($rows) >= $limit) break;
            if ($t['product_id'] && !in_array($t['product_id'], $seen)) $rows[] = $t;
        }
        return ['source' => count($seen) ? 'similar_users+trending' : 'trending', 'items' => $rows];
    }


    // ---------------- EXTRA REPORTS (role-specific views) ----------------

    // Per-product funnel: views, wishlists, carts, orders (seller: own products, admin: all).
    public function productPerformance(?int $sellerId, int $days = 30, int $limit = 10): array
    {
        $p = [];
        $j = $this->sellerJoin($sellerId, $p);
        array_push($p, $days, $limit);
        return $this->q(
            "SELECT " . self::PID . " AS product_id, pr.ProductName,
                    SUM(a.EventType='product_viewed') AS views,
                    SUM(a.EventType='wishlist_added') AS wishlists,
                    SUM(a.EventType='cart_added') AS carts,
                    SUM(a.EventType='order_placed') AS orders
             FROM `user_activity` a $j
             WHERE a.EventType IN ('product_viewed','wishlist_added','cart_added','order_placed')
               AND a.CreatedAt >= NOW() - INTERVAL ? DAY
             GROUP BY product_id, pr.ProductName
             HAVING product_id IS NOT NULL
             ORDER BY views DESC LIMIT ?", $p);
    }

    // Admin: platform-wide daily trend.
    public function dailyTrend(int $days = 14): array
    {
        return $this->q(
            "SELECT DATE(CreatedAt) AS day,
                    SUM(EventType='product_viewed') AS views,
                    SUM(EventType='cart_added') AS carts,
                    SUM(EventType='order_placed') AS orders,
                    COUNT(DISTINCT UserID) AS active_users
             FROM `user_activity`
             WHERE CreatedAt >= CURDATE() - INTERVAL ? DAY
             GROUP BY DATE(CreatedAt) ORDER BY day ASC", [$days]);
    }

    // Admin: which sellers get the attention and the sales.
    public function sellerLeaderboard(int $days = 30, int $limit = 10): array
    {
        $pt = $this->productTable();
        return $this->q(
            "SELECT pr.SellerID AS seller_id,
                    COUNT(DISTINCT pr.ProductID) AS products,
                    SUM(a.EventType='product_viewed') AS views,
                    SUM(a.EventType='cart_added') AS carts,
                    SUM(a.EventType='order_placed') AS orders
             FROM `user_activity` a
             JOIN $pt pr ON pr.ProductID = " . self::PID . "
             WHERE a.EventType IN ('product_viewed','cart_added','order_placed')
               AND a.CreatedAt >= NOW() - INTERVAL ? DAY
             GROUP BY pr.SellerID ORDER BY views DESC LIMIT ?", [$days, $limit]);
    }

    // Admin: distinct active shoppers in the period.
    public function activeUsers(int $days = 30): int
    {
        $r = $this->q("SELECT COUNT(DISTINCT UserID) AS n FROM `user_activity` WHERE CreatedAt >= NOW() - INTERVAL ? DAY", [$days]);
        return (int)($r[0]['n'] ?? 0);
    }

    // Customer: own counts only.
    public function mySummary(int $userId, int $days = 30): array
    {
        $rows = $this->q(
            "SELECT EventType, COUNT(*) AS n FROM `user_activity`
             WHERE UserID=? AND CreatedAt >= NOW() - INTERVAL ? DAY
               AND EventType IN ('product_viewed','product_searched','wishlist_added','cart_added','order_placed')
             GROUP BY EventType", [$userId, $days]);
        $by = [];
        foreach ($rows as $r) $by[$r['EventType']] = (int)$r['n'];
        return ['days' => $days, 'events' => $by];
    }

    // Customer: things I put in my cart but never ordered or removed.
    public function myAbandoned(int $userId, int $limit = 6): array
    {
        $pt = $this->productTable();
        return $this->q(
            "SELECT p.ProductID AS product_id, p.ProductName, MAX(a.CreatedAt) AS last_added
             FROM `user_activity` a JOIN $pt p ON p.ProductID = " . self::PID . "
             WHERE a.UserID=? AND a.EventType='cart_added'
               AND NOT EXISTS (SELECT 1 FROM `user_activity` o WHERE o.UserID=a.UserID
                               AND o.CreatedAt > a.CreatedAt AND o.EventType='order_placed')
               AND NOT EXISTS (SELECT 1 FROM `user_activity` r WHERE r.UserID=a.UserID
                               AND r.CreatedAt > a.CreatedAt AND r.EventType='cart_removed'
                               AND JSON_UNQUOTE(JSON_EXTRACT(r.Metadata,'$.product_id'))
                                 = JSON_UNQUOTE(JSON_EXTRACT(a.Metadata,'$.product_id')))
             GROUP BY p.ProductID, p.ProductName ORDER BY last_added DESC LIMIT ?", [$userId, $limit]);
    }


    // ---------------- BEHAVIOUR -> INTERESTS ----------------
    // Turns raw log lines ("user viewed Fujifilm X-T5") into a conclusion
    // ("interested in Fujifilm cameras"). Brand = first word of the product
    // name; category comes from the product's category. Each action has a
    // weight (view 1, wishlist 3, cart 4, order 5); a user counts as
    // "interested" once their weighted score reaches INTEREST_MIN.
    private const INTEREST_MIN = 3;

    private function interestRows(?int $sellerId, ?int $userId, int $days): array
    {
        $p = [];
        $j = $this->sellerJoin($sellerId, $p);
        $catCol = $this->isAzure() ? 'c.CategoryName' : 'c.Name';
        $where = "";
        if ($userId !== null) { $where = " AND a.UserID = ?"; }
        $p[] = $days;
        if ($userId !== null) $p[] = $userId;
        return $this->q(
            "SELECT a.UserID, SUBSTRING_INDEX(pr.ProductName,' ',1) AS brand, $catCol AS category,
                    SUM(a.EventType='product_viewed') AS views,
                    SUM(a.EventType='wishlist_added') AS wishlists,
                    SUM(a.EventType='cart_added') AS carts,
                    SUM(a.EventType='order_placed') AS orders,
                    SUM(CASE a.EventType WHEN 'order_placed' THEN 5 WHEN 'cart_added' THEN 4
                                         WHEN 'wishlist_added' THEN 3 ELSE 1 END) AS score
             FROM `user_activity` a $j
             LEFT JOIN `category` c ON c.CategoryID = pr.CategoryID
             WHERE a.EventType IN ('product_viewed','wishlist_added','cart_added','order_placed')
               AND pr.ProductID IS NOT NULL
               AND a.CreatedAt >= NOW() - INTERVAL ? DAY $where
             GROUP BY a.UserID, brand, category", $p);
    }

    private function interestLabel(string $brand, ?string $cat): string
    {
        return $brand . ' ' . strtolower($cat ?: 'products');
    }

    // Customer: my own interests with the evidence behind each conclusion.
    public function myInterests(int $userId, int $days = 90): array
    {
        $out = [];
        foreach ($this->interestRows(null, $userId, $days) as $r) {
            if ((int)$r['score'] < self::INTEREST_MIN) continue;
            $ev = [];
            if ($r['views'])     $ev[] = 'viewed ' . $r['views'] . 'x';
            if ($r['wishlists']) $ev[] = 'wishlisted ' . $r['wishlists'] . 'x';
            if ($r['carts'])     $ev[] = 'added to cart ' . $r['carts'] . 'x';
            if ($r['orders'])    $ev[] = 'ordered ' . $r['orders'] . 'x';
            $out[] = ['interest' => $this->interestLabel($r['brand'], $r['category']), 'score' => (int)$r['score'],
                      'strength' => (int)$r['score'] >= 8 ? 'Strong' : ((int)$r['score'] >= 5 ? 'Growing' : 'Mild'),
                      'evidence' => implode(', ', $ev)];
        }
        usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($out, 0, 8);
    }

    // Seller (own products) or admin (all): how many different customers are
    // interested in each brand/category. Aggregated, with user IDs only.
    public function interestGroups(?int $sellerId, int $days = 30, int $limit = 12): array
    {
        $g = [];
        foreach ($this->interestRows($sellerId, null, $days) as $r) {
            if ((int)$r['score'] < self::INTEREST_MIN) continue;
            $k = $this->interestLabel($r['brand'], $r['category']);
            if (!isset($g[$k])) $g[$k] = ['interest' => $k, 'customers' => 0, 'total_score' => 0, 'buyers' => 0, 'user_ids' => []];
            $g[$k]['customers']++;
            $g[$k]['total_score'] += (int)$r['score'];
            if ((int)$r['orders'] > 0) $g[$k]['buyers']++;
            if (count($g[$k]['user_ids']) < 25) $g[$k]['user_ids'][] = (int)$r['UserID'];
        }
        $out = array_values($g);
        usort($out, fn($a, $b) => [$b['customers'], $b['total_score']] <=> [$a['customers'], $a['total_score']]);
        return array_slice($out, 0, $limit);
    }

    // ---------------- SEGMENTS + ALERTS (admin only) ----------------

    // Groups customers by behaviour over the last $days. Returns counts plus a
    // capped list of UserIDs per segment (IDs only, no activity details).
    public function segments(int $days = 30): array
    {
        $rows = $this->q(
            "SELECT UserID,
                    SUM(EventType='product_viewed') AS views,
                    SUM(EventType='cart_added') AS carts,
                    SUM(EventType='wishlist_added') AS wishes,
                    SUM(EventType='order_placed') AS orders,
                    MIN(CreatedAt) AS first_seen, MAX(CreatedAt) AS last_seen,
                    SUM(CreatedAt >= NOW() - INTERVAL ? DAY) AS recent
             FROM `user_activity` GROUP BY UserID", [$days]);
        $defs = [
            'buyers'          => 'Buyers (placed an order)',
            'cart_abandoners' => 'Cart abandoners (carted, never ordered)',
            'window_shoppers' => 'Window shoppers (5+ views, no cart)',
            'wishlisters'     => 'Wishlisters (wishlisted, no cart)',
            'new_users'       => 'New (first activity in last 7 days)',
            'inactive'        => 'Inactive (no activity in the period)',
        ];
        $seg = [];
        foreach ($defs as $k => $label) $seg[$k] = ['label' => $label, 'count' => 0, 'user_ids' => []];
        $add = function (string $k, int $uid) use (&$seg) {
            $seg[$k]['count']++;
            if (count($seg[$k]['user_ids']) < 25) $seg[$k]['user_ids'][] = $uid;
        };
        $weekAgo = strtotime('-7 days');
        foreach ($rows as $r) {
            $uid = (int)$r['UserID'];
            if ((int)$r['recent'] === 0) { $add('inactive', $uid); continue; }
            if ((int)$r['orders'] > 0) $add('buyers', $uid);
            elseif ((int)$r['carts'] > 0) $add('cart_abandoners', $uid);
            elseif ((int)$r['views'] >= 5) $add('window_shoppers', $uid);
            elseif ((int)$r['wishes'] > 0) $add('wishlisters', $uid);
            if (strtotime($r['first_seen']) >= $weekAgo) $add('new_users', $uid);
        }
        return ['days' => $days, 'segments' => array_values(array_map(
            function ($k, $v) { return ['key' => $k] + $v; }, array_keys($seg), $seg))];
    }

    // Flags unusual behaviour. Thresholds are simple and adjustable.
    public function alerts(): array
    {
        $out = [];
        $checks = [
            ['high_activity', 'High event rate (possible bot)', "COUNT(*)", "1=1", 'HOUR', 1, 150],
            ['search_flood',  'Search flooding', "COUNT(*)", "EventType='product_searched'", 'HOUR', 1, 40],
            ['cart_churn',    'Excessive cart adds (24h)', "COUNT(*)", "EventType='cart_added'", 'HOUR', 24, 25],
            ['order_burst',   'Many orders in 24h', "COUNT(*)", "EventType='order_placed'", 'HOUR', 24, 8],
            ['cancel_burst',  'Many cancellations (7d)', "COUNT(*)", "EventType='order_cancelled'", 'DAY', 7, 5],
        ];
        foreach ($checks as [$key, $label, $agg, $where, $unit, $n, $limit]) {
            $rows = $this->q(
                "SELECT UserID, $agg AS n FROM `user_activity`
                 WHERE $where AND CreatedAt >= NOW() - INTERVAL $n $unit
                 GROUP BY UserID HAVING n >= ? ORDER BY n DESC LIMIT 20", [$limit]);
            foreach ($rows as $r) {
                $out[] = ['type' => $key, 'severity' => 'warning', 'message' => $label,
                          'user_id' => (int)$r['UserID'], 'count' => (int)$r['n'], 'threshold' => $limit];
            }
        }
        // Platform-level spike: today's views vs the 7-day daily average.
        $v = $this->q(
            "SELECT SUM(CreatedAt >= CURDATE()) AS today,
                    SUM(CreatedAt < CURDATE() AND CreatedAt >= CURDATE() - INTERVAL 7 DAY) / 7 AS avg7
             FROM `user_activity` WHERE EventType='product_viewed'")[0] ?? null;
        if ($v && (float)$v['avg7'] >= 5 && (float)$v['today'] > 3 * (float)$v['avg7']) {
            $out[] = ['type' => 'traffic_spike', 'severity' => 'info', 'message' => 'Product views today are over 3x the 7-day daily average',
                      'user_id' => null, 'count' => (int)$v['today'], 'threshold' => round(3 * (float)$v['avg7'])];
        }
        return $out;
    }
}

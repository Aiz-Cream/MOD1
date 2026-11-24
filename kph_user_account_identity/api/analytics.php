<?php
// ================================================================
// /api/analytics.php   (Bearer auth)
//  Customer: ?report=recently_viewed | recommendations | also_viewed&product_id=ID
//  Seller:   ?scope=seller&report=summary|top_viewed|abandoned_carts   (own products only)
//  Admin:    ?scope=admin &report=summary|top_viewed|popular_searches|abandoned_carts
// ================================================================
require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/../classes/Analytics.php";

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "GET") respond(405, ["error" => "Method not allowed."]);

try {
    $an = new Analytics();
    $report = $_GET["report"] ?? "summary";
    $scope = $_GET["scope"] ?? "customer";
    $days = isset($_GET["days"]) ? max(1, min(365, (int)$_GET["days"])) : 30;
    $limit = isset($_GET["limit"]) ? max(1, min(50, (int)$_GET["limit"])) : 10;

    if ($scope === "admin" || $scope === "seller") {
        $sellerId = null;
        if ($scope === "admin") { requireBearerAdmin(); }
        else { $me = requireBearerSeller(); $sellerId = (int)$me["UserID"]; }

        switch ($report) {
            case "summary":         respond(200, $an->summary($sellerId, $days));
            case "top_viewed":      respond(200, ["items" => $an->topViewed($sellerId, $limit, $days)]);
            case "abandoned_carts": respond(200, ["items" => $an->abandonedCarts($sellerId, 24, $limit)]);
            case "performance":     respond(200, ["items" => $an->productPerformance($sellerId, $days, $limit)]);
            case "daily":
                if ($scope !== "admin") respond(403, ["error" => "Admin only."]);
                respond(200, ["items" => $an->dailyTrend(min($days, 60))]);
            case "sellers":
                if ($scope !== "admin") respond(403, ["error" => "Admin only."]);
                respond(200, ["items" => $an->sellerLeaderboard($days, $limit)]);
            case "interests":       respond(200, ["items" => $an->interestGroups($sellerId, $days, $limit)]);
            case "segments":
                if ($scope !== "admin") respond(403, ["error" => "Admin only."]);
                respond(200, $an->segments($days));
            case "alerts":
                if ($scope !== "admin") respond(403, ["error" => "Admin only."]);
                respond(200, ["alerts" => $an->alerts()]);
            case "popular_searches":
                if ($scope !== "admin") respond(403, ["error" => "Admin only."]);
                respond(200, ["items" => $an->popularSearches($limit, $days)]);
        }
        respond(400, ["error" => "Unknown report."]);
    }

    $me = requireBearerUser();
    $uid = (int)$me["UserID"];
    switch ($report) {
        case "recently_viewed":  respond(200, ["items" => $an->recentlyViewed($uid, $limit)]);
        case "my_interests":     respond(200, ["items" => $an->myInterests($uid, max($days, 90))]);
        case "my_summary":       respond(200, $an->mySummary($uid, $days));
        case "my_abandoned":     respond(200, ["items" => $an->myAbandoned($uid, $limit)]);
        case "trending":         respond(200, ["items" => $an->topViewed(null, $limit, $days)]);
        case "recommendations":  respond(200, $an->recommendForUser($uid, $limit));
        case "also_viewed":
            $pid = (int)($_GET["product_id"] ?? 0);
            if ($pid <= 0) respond(400, ["error" => "product_id required."]);
            respond(200, ["items" => $an->alsoViewed($pid, $limit)]);
    }
    respond(400, ["error" => "Unknown report."]);
} catch (Throwable $e) {
    handleApiException($e);
}

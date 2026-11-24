<?php
// ================================================================
// API Endpoint: /api/activity.php
// Module 1: User Account & Identity (+ commerce behaviour events)
// ================================================================
// GET:
//   ?type=logins            -> login history (unchanged shape; requireUser)
//   ?type=<activity tab>    -> user_activity events (Bearer-only)
// POST:  record an activity event (Bearer-only; UserID from token)
// DELETE: ?type=<tab>       -> clear that tab's events (never logins)
// ================================================================

require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/../classes/ActivityEvent.php";
require_once __DIR__ . "/../classes/LoginHistory.php";

$activity = new ActivityEvent();

$method = $_SERVER["REQUEST_METHOD"] ?? "GET";
$type = $_GET["type"] ?? "all";

try {
    if ($method === "GET") {
        // Login history keeps its original behaviour and response shape.
        if ($type === "logins") {
            $me = requireUser();
            $history = (new LoginHistory())->listForUser((int) $me["UserID"], 20);
            respond(200, $history);
        }

        $me = requireBearerUser();
        $tab = array_key_exists($type, ActivityEvent::TABS) ? $type : "all";
        $limit = isset($_GET["limit"]) ? max(1, min(100, (int) $_GET["limit"])) : 50;
        $offset = isset($_GET["offset"]) ? max(0, (int) $_GET["offset"]) : 0;
        // Admins see everyone's activity; buyers and sellers only ever see their own.
        $isAdmin = isAdminUser($me);
        $rows = $isAdmin
            ? $activity->listAll($tab, $limit, $offset)
            : $activity->list((int) $me["UserID"], $tab, $limit, $offset);
        respond(200, ["activity" => $rows, "scope" => $isAdmin ? "all" : "own"]);
    }

    if ($method === "POST") {
        $me = requireBearerUser();
        $body = jsonBody();
        // A client-supplied UserID is always ignored.
        $body["UserID"] = null;
        $id = $activity->track((int) $me["UserID"], $body);
        respond(201, ["ActivityID" => $id]);
    }

    if ($method === "DELETE") {
        $me = requireBearerUser();
        if ($type === "logins") {
            respond(400, ["error" => "Login history cannot be cleared from here."]);
        }
        $tab = array_key_exists($type, ActivityEvent::TABS) ? $type : "all";
        respond(200, ["deleted" => $activity->clear((int) $me["UserID"], $tab)]);
    }

    respond(405, ["error" => "Method not allowed."]);
} catch (Throwable $e) {
    handleApiException($e);
}
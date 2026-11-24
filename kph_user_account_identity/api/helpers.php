<?php
// ================================================================
// Shared API Helpers — Module 1: User Account & Identity
// ================================================================

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Test-User-Id, X-Module-Key");

if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../config/Database.php";
require_once __DIR__ . "/../classes/Exceptions.php";
require_once __DIR__ . "/../classes/Validator.php";
require_once __DIR__ . "/../classes/Crypto.php";
require_once __DIR__ . "/../classes/User.php";
require_once __DIR__ . "/../classes/UserProfile.php";
require_once __DIR__ . "/../classes/UserAddress.php";
require_once __DIR__ . "/../classes/Role.php";
require_once __DIR__ . "/../classes/UserRole.php";
require_once __DIR__ . "/../classes/KycVerification.php";
require_once __DIR__ . "/../classes/AuthIdentity.php";
require_once __DIR__ . "/../classes/VerificationToken.php";
require_once __DIR__ . "/../classes/LoginHistory.php";

function respond(int $status, $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function handleApiException(Throwable $e): void
{
    if ($e instanceof InvalidArgumentException) {
        respond(400, ["error" => $e->getMessage()]);
    }
    if ($e instanceof AuthenticationException) {
        respond(401, ["error" => $e->getMessage()]);
    }
    if ($e instanceof ForbiddenException) {
        respond(403, ["error" => $e->getMessage()]);
    }
    if ($e instanceof OutOfBoundsException) {
        respond(404, ["error" => $e->getMessage()]);
    }
    if ($e instanceof ConflictException) {
        respond(409, ["error" => $e->getMessage()]);
    }
    if ($e instanceof RuntimeException) {
        respond(400, ["error" => $e->getMessage()]);
    }
    respond(500, ["error" => "Server error: " . $e->getMessage()]);
}

function jsonBody(): array
{
    $raw = file_get_contents("php://input");
    if ($raw === false || trim($raw) === "") {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        respond(400, ["error" => "Invalid JSON body."]);
    }
    return $decoded;
}

function getRequestHeadersLower(): array
{
    $out = [];
    if (function_exists("getallheaders")) {
        foreach (getallheaders() as $k => $v) {
            $out[strtolower($k)] = $v;
        }
    }
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, "HTTP_") === 0) {
            $name = strtolower(str_replace("_", "-", substr($k, 5)));
            $out[$name] = $v;
        }
    }
    if (isset($_SERVER["REDIRECT_HTTP_AUTHORIZATION"]) && !isset($out["authorization"])) {
        $out["authorization"] = $_SERVER["REDIRECT_HTTP_AUTHORIZATION"];
    }
    return $out;
}

function getBearerToken(): ?string
{
    $headers = getRequestHeadersLower();
    $auth = $headers["authorization"] ?? "";
    if ($auth !== "" && stripos($auth, "Bearer ") === 0) {
        $token = trim(substr($auth, 7));
        return $token !== "" ? $token : null;
    }
    return null;
}

function currentUser(): ?array
{
    $headers = getRequestHeadersLower();
    $userId = null;

    // 1. Check Authorization: Bearer <token> against verification_token
    $token = getBearerToken();
    if ($token !== null) {
        $tokens = new VerificationToken();
        $userId = $tokens->verifySession($token);
    }

    // 2. Fallback to X-Test-User-Id header for standalone testing
    if ($userId === null && isset($headers["x-test-user-id"]) && $headers["x-test-user-id"] !== "") {
        $userId = (int) $headers["x-test-user-id"];
    }

    if (!$userId) {
        return null;
    }

    $userModel = new User();
    return $userModel->getById($userId);
}

function requireUser(): array
{
    $user = currentUser();
    if ($user === null) {
        respond(401, ["error" => "Authentication required."]);
    }
    return $user;
}

function isAdminUser(?array $user): bool
{
    if ($user === null) {
        return false;
    }
    $userRoles = new UserRole();
    return $userRoles->hasRole((int) $user["UserID"], "admin");
}

function requireAdmin(): array
{
    $user = requireUser();
    if (!isAdminUser($user)) {
        respond(403, ["error" => "Admin privileges required."]);
    }
    return $user;
}

// ---------------------------------------------------------------------------
// Bearer-only auth variants used by the commerce endpoints.
// Unlike requireUser(), these NEVER accept X-Test-User-Id, so a commerce
// endpoint cannot be reached by a spoofed test header.
// ---------------------------------------------------------------------------

function requireBearerUser(): array
{
    $token = getBearerToken();
    if ($token === null) {
        respond(401, ["error" => "Authentication required."]);
    }
    $tokens = new VerificationToken();
    $userId = $tokens->verifySession($token);
    if ($userId === null) {
        respond(401, ["error" => "Authentication required."]);
    }
    $user = (new User())->getById($userId);
    if ($user === null) {
        respond(401, ["error" => "Authentication required."]);
    }
    return $user;
}

function requireBearerAdmin(): array
{
    $user = requireBearerUser();
    $roles = new UserRole();
    if (!$roles->hasRole((int)$user["UserID"], "admin")) {
        respond(403, ["error" => "Admin privileges required."]);
    }
    return $user;
}

function isSellerUser(?array $user): bool
{
    if ($user === null) {
        return false;
    }
    return (new UserRole())->hasRole((int)$user["UserID"], "seller");
}

// Bearer user who currently holds an active, Approved seller application.
// A Suspended application is reported distinctly so the UI can explain why.
function requireBearerSeller(): array
{
    $user = requireBearerUser();
    $roles = new UserRole();
    if (!$roles->hasRole((int)$user["UserID"], "seller")) {
        respond(403, ["error" => "Seller privileges required."]);
    }

    $db = Database::getConnection();
    $stmt = $db->prepare(
        "SELECT Status FROM `seller_application` WHERE UserID = ? ORDER BY ApplicationID DESC LIMIT 1"
    );
    $stmt->execute([(int)$user["UserID"]]);
    $status = $stmt->fetchColumn();

    if ($status === false || $status === null) {
        respond(403, ["error" => "Seller privileges required."]);
    }
    if ($status === "Suspended") {
        respond(403, ["error" => "Seller account suspended."]);
    }
    if ($status !== "Approved") {
        respond(403, ["error" => "Seller privileges required."]);
    }
    return $user;
}

function hasModuleKey(): bool
{
    $headers = getRequestHeadersLower();
    $config = Database::getConfig();
    $sentKey = $headers["x-module-key"] ?? "";
    return $sentKey !== "" && isset($config["module_key"]) && hash_equals($config["module_key"], $sentKey);
}

function maskEmail(string $email): string
{
    $parts = explode("@", $email);
    if (count($parts) !== 2) {
        return $email;
    }
    $local = $parts[0];
    $domain = $parts[1];
    if (strlen($local) <= 2) {
        return substr($local, 0, 1) . "***@" . $domain;
    }
    return substr($local, 0, 2) . str_repeat("*", max(3, strlen($local) - 2)) . "@" . $domain;
}

function maskPhone(string $phone): string
{
    $len = strlen($phone);
    if ($len <= 6) {
        return $phone;
    }
    return substr($phone, 0, 4) . " *** " . substr($phone, -4);
}

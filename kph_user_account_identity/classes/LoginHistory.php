<?php
require_once __DIR__ . '/../config/Database.php';

// Records and reads the account login history (date/time, device, status).
// One row per successful authentication. Never stores passwords or tokens.
class LoginHistory
{
    // Best-effort device label from the User-Agent. Not a verified device identity.
    public static function parseDevice(?string $ua): string
    {
        $ua = (string)$ua;
        if ($ua === "") {
            return "Web Browser";
        }

        $os = "Unknown OS";
        if (stripos($ua, "Windows") !== false) {
            $os = "Windows";
        } elseif (stripos($ua, "iPhone") !== false || stripos($ua, "iPad") !== false) {
            $os = "iOS";
        } elseif (stripos($ua, "Android") !== false) {
            $os = "Android";
        } elseif (stripos($ua, "Mac OS") !== false || stripos($ua, "Macintosh") !== false) {
            $os = "macOS";
        } elseif (stripos($ua, "Linux") !== false) {
            $os = "Linux";
        }

        $browser = "Browser";
        if (stripos($ua, "Edg") !== false) {
            $browser = "Edge";
        } elseif (stripos($ua, "OPR") !== false || stripos($ua, "Opera") !== false) {
            $browser = "Opera";
        } elseif (stripos($ua, "Firefox") !== false) {
            $browser = "Firefox";
        } elseif (stripos($ua, "Chrome") !== false) {
            $browser = "Chrome";
        } elseif (stripos($ua, "Safari") !== false) {
            $browser = "Safari";
        }

        return $browser . " on " . $os;
    }

    // Records a successful login. Never throws: a logging failure must not break login.
    public static function record(int $userId, ?int $sessionId, ?string $userAgent): void
    {
        try {
            $db = Database::getConnection();
            $ua = $userAgent !== null ? mb_substr($userAgent, 0, 500) : null;
            $device = self::parseDevice($userAgent);
            $stmt = $db->prepare(
                "INSERT INTO `login_history` (UserID, SessionID, UserAgent, Device, Status)
                 VALUES (?, ?, ?, ?, 'Success')"
            );
            $stmt->execute([$userId, $sessionId, $ua, $device]);
        } catch (Throwable $e) {
            // Intentionally ignored: login history is best-effort.
        }
    }

    // Most recent logins first (newest first). Returns [] on failure.
    public static function listForUser(int $userId, int $limit = 15): array
    {
        try {
            $limit = max(1, min((int)$limit, 100));
            $db = Database::getConnection();
            $stmt = $db->prepare(
                "SELECT LoginID, SessionID, LoginAt, Device, Status
                 FROM `login_history`
                 WHERE UserID = ?
                 ORDER BY LoginAt DESC, LoginID DESC
                 LIMIT $limit"
            );
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }
}
<?php
// ============================================================
// KameraPH | Module 1 | Ecommerce | Activity retention cleanup
// ============================================================
// Deletes `user_activity` rows older than 12 months in bounded
// batches, keyed on CreatedAt (idx_user_activity_CreatedAt).
//
// It NEVER touches login_history, orders or order_status_history.
//
// Usage (XAMPP / Windows):
//   C:\xampp\php\php.exe cleanup_activity.php
//   C:\xampp\php\php.exe cleanup_activity.php --months=6 --max=5000
//   C:\xampp\php\php.exe cleanup_activity.php --dry-run
//
// Schedule with Windows Task Scheduler. If scheduling is not
// configured, retention is a documented operational step.
// ============================================================

require_once __DIR__ . '/../../config/Database.php';

$opts = getopt('', ['months::', 'max::', 'dry-run']);

$months = (isset($opts['months']) && $opts['months'] !== false && (int)$opts['months'] > 0)
    ? (int)$opts['months']
    : 12;
$max = (isset($opts['max']) && $opts['max'] !== false && (int)$opts['max'] > 0)
    ? (int)$opts['max']
    : 50000;
$dryRun = array_key_exists('dry-run', $opts);

$cutoff = date('Y-m-d H:i:s', strtotime("-$months months"));
if ($cutoff === false) {
    fwrite(STDERR, "Could not compute the cutoff date.\n");
    exit(1);
}

try {
    $db = Database::getConnection();

    if ($dryRun) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM `user_activity` WHERE CreatedAt < ?");
        $stmt->execute([$cutoff]);
        echo "Dry run: " . (int)$stmt->fetchColumn()
            . " user_activity row(s) older than $cutoff would be deleted.\n";
        exit(0);
    }

    $total = 0;
    while ($total < $max) {
        $limit = (int)min(1000, $max - $total);
        $stmt = $db->prepare("DELETE FROM `user_activity` WHERE CreatedAt < ? LIMIT $limit");
        $stmt->execute([$cutoff]);
        $deleted = (int)$stmt->rowCount();
        if ($deleted === 0) {
            break;
        }
        $total += $deleted;
    }

    echo "Deleted $total user_activity row(s) older than $cutoff.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Cleanup failed: " . $e->getMessage() . "\n");
    exit(1);
}
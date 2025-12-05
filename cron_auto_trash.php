<?php
/**
 * Cron job to automatically move delivered packages to trash after configured days
 * Run once daily
 * Crontab: 0 2 * * * /usr/bin/php /var/www/html/cron_auto_trash.php >> /var/www/html/logs/auto_trash.log 2>&1
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';

// Ensure this script only runs from command line/cron, not from browser
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This script can only be run from the command line.";
    exit(1);
}

// Create logs directory if it doesn't exist
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$logFile = $logDir . '/auto_trash.log';

function logMessage($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$message}\n";
    echo $logEntry;
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

logMessage("=== Starting auto-trash process ===");

// Get the configured number of days
$autoTrashDays = (int)getSetting('auto_trash_days', 30);
logMessage("Auto-trash configured for packages delivered more than {$autoTrashDays} days ago");

// Get delivered packages older than the configured days
$packages = getDeliveredPackagesForAutoTrash($autoTrashDays);

logMessage("Found " . count($packages) . " package(s) to move to trash");

if (count($packages)) {

$successCount = 0;
$errorCount = 0;

foreach ($packages as $package) {
    $trackingNumber = $package['tracking_number'];
    $deliveredDate = $package['delivered_date'];
    $daysAgo = floor((time() - strtotime($deliveredDate)) / 86400);

    logMessage("Moving to trash: {$trackingNumber} (Delivered {$daysAgo} days ago on {$deliveredDate})");

    try {
        $success = moveTrackingNumber($package['user_id'], $package['id'], 'trash');

        if ($success) {
            $successCount++;
            logMessage("  ✓ Successfully moved to trash");
        } else {
            $errorCount++;
            logMessage("  ✗ Failed to move to trash");
        }
    } catch (Exception $e) {
        $errorCount++;
        logMessage("  ✗ Exception: " . $e->getMessage());
    }
}

logMessage("=== Auto-trash complete ===");
logMessage("Successfully moved to trash: {$successCount}");
logMessage("Failed: {$errorCount}");
logMessage("");

}

// ============================================================================
// Delete old items from trash after 90 days
// ============================================================================

logMessage("=== Starting trash cleanup process ===");

$trashRetentionDays = (int)getSetting('trash_retention_days', 90);
logMessage("Trash retention configured for {$trashRetentionDays} days");

// Get tracking numbers in trash older than the configured days
$oldTrashItems = getTrackingNumbersInTrashOlderThan($trashRetentionDays);

logMessage("Found " . count($oldTrashItems) . " item(s) in trash to delete");

if (empty($oldTrashItems)) {
    logMessage("No items in trash need to be deleted. Exiting.");
    exit(0);
}

$deleteSuccessCount = 0;
$deleteErrorCount = 0;

foreach ($oldTrashItems as $item) {
    $trackingNumber = $item['tracking_number'];
    $movedToTrashDate = $item['updated_at'];
    $daysInTrash = floor((time() - strtotime($movedToTrashDate)) / 86400);

    logMessage("Deleting from trash: {$trackingNumber} (in trash for {$daysInTrash} days since {$movedToTrashDate})");

    try {
        // Delete associated events first
        $pdo = getDbConnection();

        // Delete tracking events
        $stmt = $pdo->prepare("DELETE FROM tracking_events WHERE tracking_number_id = ?");
        $stmt->execute([$item['id']]);
        $deletedEvents = $stmt->rowCount();
        logMessage("  Deleted {$deletedEvents} associated event(s)");

        // Delete the tracking number
        $success = deleteTrackingNumber($item['user_id'], $item['id']);

        if ($success) {
            $deleteSuccessCount++;
            logMessage("  ✓ Successfully deleted from trash");
        } else {
            $deleteErrorCount++;
            logMessage("  ✗ Failed to delete from trash");
        }
    } catch (Exception $e) {
        $deleteErrorCount++;
        logMessage("  ✗ Exception: " . $e->getMessage());
    }
}

logMessage("=== Trash cleanup complete ===");
logMessage("Successfully deleted from trash: {$deleteSuccessCount}");
logMessage("Failed to delete: {$deleteErrorCount}");
logMessage("");

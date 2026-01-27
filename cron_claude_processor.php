<?php
/**
 * Claude AI Package Name Processor
 *
 * This cronjob processes pending email analysis records by sending them to Claude AI
 * to generate descriptive package names. Runs every 5 minutes.
 *
 * Schedule: Every 5 minutes (see crontab for exact entry)
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/claude_api.php';

// Ensure this script can only be run from command line
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This script can only be run from the command line.";
    exit(1);
}

// Setup logging
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/claude_processor.log';

function logMessage($msg) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $message = "[$timestamp] $msg\n";
    file_put_contents($logFile, $message, FILE_APPEND);
    echo $message; // Also output to console for cron logging
}

logMessage("=== Starting Claude AI processor ===");

// Get pending records (batch of 50)
$pending = getPendingClaudeAnalysis(50);
$pendingCount = count($pending);
logMessage("Found $pendingCount pending analysis record(s)");

if ($pendingCount === 0) {
    logMessage("No pending records to process");
    logMessage("=== Claude AI processor complete ===");
    exit(0);
}

$processed = 0;
$failed = 0;
$ignored = 0;

foreach ($pending as $record) {
    $id = $record['id'];
    $trackingNumberId = $record['tracking_number_id'];
    $userId = $record['user_id'];
    $attempts = $record['processing_attempts'];

    logMessage("Processing record ID $id (tracking_number_id: $trackingNumberId, user_id: $userId, attempts: $attempts)");

    // Get user's Claude API key
    $apiKey = getUserSetting($userId, 'claude_api_key', '');

    if (empty($apiKey)) {
        logMessage("  User $userId has no Claude API key - marking as processed");
        markClaudeAnalysisProcessed($id);
        $processed++;
        continue;
    }

    // Call Claude API
    $result = analyzeEmailWithClaude(
        $apiKey,
        $record['email_subject'],
        $record['email_body']
    );

    if (!$result['success']) {
        // API call failed - increment attempts
        $errorMsg = substr($result['error'], 0, 500); // Truncate error message
        logMessage("  ✗ Claude API error: " . $errorMsg);
        incrementClaudeAnalysisAttempts($id, $errorMsg);

        // Check if max attempts reached (3 attempts)
        if ($attempts >= 2) {
            logMessage("  Max attempts (3) reached - marking as processed to avoid infinite retry");
            markClaudeAnalysisProcessed($id);
        }
        $failed++;
        continue;
    }

    if (isset($result['ignored']) && $result['ignored']) {
        // Claude said to ignore this email
        logMessage("  ℹ Claude returned IGNORE - no package name set");
        markClaudeAnalysisProcessed($id);
        $ignored++;
        continue;
    }

    // Update package name
    $packageName = $result['package_name'];
    logMessage("  ✓ Package name: \"$packageName\"");

    $updateSuccess = updatePackageNameFromClaude($trackingNumberId, $packageName);
    if ($updateSuccess) {
        markClaudeAnalysisProcessed($id);
        $processed++;
    } else {
        logMessage("  ✗ Failed to update package name in database");
        incrementClaudeAnalysisAttempts($id, "Failed to update database");
        $failed++;
    }
}

logMessage("=== Claude AI processor complete ===");
logMessage("Successfully processed: $processed");
logMessage("Ignored (no name): $ignored");
logMessage("Failed: $failed");

exit(0);

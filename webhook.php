<?php
/**
 * 17track Webhook Handler
 * Receives real-time tracking updates from 17track v2.4 API
 *
 * Setup in 17track Dashboard:
 * - Webhook URL: https://yourdomain.com/webhook.php
 * - Content Type: application/json
 * - Protected with .htaccess password authentication
 */

require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/tracking_api.php';

// Create logs directory if it doesn't exist
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$logFile = $logDir . '/webhook_requests.log';

// Log function
function logWebhook($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$message}\n";
    error_log($logFile.' '.$logEntry);
    //error_log($logEntry, 3, $logFile);
    if (file_exists($logFile)) {
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    } else {
        file_put_contents($logFile, $logEntry);
    }
}

// Main webhook handler
function handleWebhookRequest() {
    // Verify request method and content type
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        logWebhook("ERROR: Invalid request method: " . $_SERVER['REQUEST_METHOD']);
        http_response_code(405);
        return ['success' => false, 'error' => 'Method not allowed'];
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') === false) {
        logWebhook("ERROR: Invalid content type: {$contentType}");
        http_response_code(400);
        return ['success' => false, 'error' => 'Invalid content type'];
    }

    // Get raw POST data
    $rawInput = file_get_contents('php://input');
    if (!$rawInput) {
        logWebhook("ERROR: Empty request body");
        http_response_code(400);
        return ['success' => false, 'error' => 'Empty request body'];
    }

    // Parse JSON
    $webhookData = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logWebhook("ERROR: Invalid JSON - " . json_last_error_msg());
        http_response_code(400);
        return ['success' => false, 'error' => 'Invalid JSON'];
    }

    // Log incoming webhook
    logWebhook("RECEIVED: " . json_encode($webhookData));

    // Extract user_id from tag field
    $user_id = extractUserIdFromWebhook($webhookData);
    if (!$user_id) {
        logWebhook("ERROR: Cannot determine user from webhook - missing or invalid tag");
        http_response_code(200);
        return ['success' => false, 'error' => 'Cannot determine user'];
    }

    // Verify HMAC signature
    if (!verifyWebhookSignature($webhookData, $user_id, $rawInput)) {
        logWebhook("ERROR: HMAC signature verification failed for user {$user_id}");
        http_response_code(200);
        return ['success' => false, 'error' => 'Signature verification failed'];
    }

    logWebhook("INFO: Signature verified for user {$user_id}");

    // Process tracking events
    try {
        $result = processTrackingEvents($webhookData, $user_id);

        if ($result['success']) {
            logWebhook("SUCCESS: Processed tracking updates for user {$user_id}");
            http_response_code(200);
            return $result;
        } else {
            logWebhook("ERROR: " . ($result['error'] ?? 'Unknown error'));
            http_response_code(200); // Return 200 to prevent 17track retries
            return $result;
        }
    } catch (Exception $e) {
        logWebhook("EXCEPTION: " . $e->getMessage());
        http_response_code(200); // Return 200 to prevent 17track retries
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Extract user_id from webhook data (from tag field in tracking data)
function extractUserIdFromWebhook($webhookData) {
    if (!isset($webhookData['data'])) {
        return null;
    }

    $data = $webhookData['data'];
    $trackings = isset($data[0]) ? $data : [$data];

    // Get user_id from first tracking's tag field
    if (!empty($trackings) && isset($trackings[0]['tag'])) {
        $tag = $trackings[0]['tag'];
        // Tag is the user_id as string
        if (is_numeric($tag) && intval($tag) > 0) {
            return intval($tag);
        }
    }

    return 1;

    return null;
}

// Verify HMAC-SHA256 signature
function verifyWebhookSignature($webhookData, $user_id, $rawInput) {
    $secret = getUserSetting($user_id, '17track_api_key');

    if (!$secret || empty($secret)) {
        logWebhook("WARNING: No 17track_api_key configured for user {$user_id}");
        // Allow webhook to proceed if no secret is configured (for backward compatibility)
        return true;
    }

    // Get signature from headers
    $signature = $_SERVER['HTTP_SIGN'] ?? null;

    if (!$signature) {
        logWebhook("WARNING: No X-17track-Signature header in webhook");
        return false;
    }

    // Compute HMAC-SHA256
    $computed = hash('sha256', $rawInput.'/'.$secret);

    // Compare signatures (use hash_equals for timing attack protection)
    return hash_equals($computed, $signature);
}

// Process webhook events
function processTrackingEvents($webhookData, $user_id) {
    // 17track v2.4 webhook structure
    if (!isset($webhookData['data'])) {
        return ['success' => false, 'error' => 'Missing data field in webhook'];
    }

    $data = $webhookData['data'];

    // Handle both single tracking and array of trackings
    $trackings = isset($data[0]) ? $data : [$data];

    $successCount = 0;
    $errorCount = 0;

    foreach ($trackings as $tracking) {
        $result = processTrackingUpdate($tracking, $user_id);
        if ($result['success']) {
            $successCount++;
        } else {
            $errorCount++;
            logWebhook("  Failed to process: " . ($result['error'] ?? 'Unknown error'));
        }
    }

    return [
        'success' => true,
        'processed' => $successCount,
        'errors' => $errorCount
    ];
}

// Process a single tracking update
function processTrackingUpdate($trackingData, $user_id) {
    // Extract tracking number and carrier
    $trackingNumber = $trackingData['number'] ?? null;
    $carrierCode = $trackingData['carrier_code'] ?? $trackingData['carrier'] ?? null;

    if (!$trackingNumber) {
        return ['success' => false, 'error' => 'Missing tracking number'];
    }

    logWebhook("  Processing: {$trackingNumber} (carrier: {$carrierCode}) for user {$user_id}");

    // Find tracking in database for this specific user
    $tracking = findTrackingByNumber($user_id, $trackingNumber);

    if (!$tracking) {
        logWebhook("    WARNING: Tracking number not found for user {$user_id}");
        return ['success' => false, 'error' => 'Tracking number not found'];
    }

    try {
        $trackingId = $tracking['id'];

        // Use shared parsing function from tracking_api.php
        $parsedData = parseTrackInfo($trackingData);

        if (!$parsedData) {
            logWebhook("    ERROR: Failed to parse tracking data");
            return ['success' => false, 'error' => 'Failed to parse tracking data'];
        }

        // Build updates array from parsed data
        $updates = [
            'status' => $parsedData['status'],
            'is_permanent_status' => $parsedData['is_permanent_status'] ? 1 : 0,
            'raw_api_response' => json_encode($trackingData),
            'last_api_check' => date('Y-m-d H:i:s')
        ];

        if ($parsedData['raw_status']) {
            $updates['raw_status'] = $parsedData['raw_status'];
        }

        if ($parsedData['sub_status']) {
            $updates['sub_status'] = $parsedData['sub_status'];
        }

        // Always update estimated_delivery_date if present in parsed data (even if null)
        if (array_key_exists('estimated_delivery_date', $parsedData)) {
            $updates['estimated_delivery_date'] = $parsedData['estimated_delivery_date'];
        }

        if ($parsedData['delivered_date']) {
            $updates['delivered_date'] = $parsedData['delivered_date'];
        }

        // Update tracking number
        updateTrackingNumber($user_id, $trackingId, $updates);
        logWebhook("    Updated status and dates for #{$trackingId}");

        // Add events to database
        if (!empty($parsedData['events'])) {
            foreach ($parsedData['events'] as $event) {
                if ($event['date']) {
                    addTrackingEvent(
                        $trackingId,
                        $event['date'],
                        $event['status'],
                        $event['location'],
                        $event['description']
                    );
                }
            }
            logWebhook("    Added " . count($parsedData['events']) . " events");
        }

        // Update last event date
        if (!empty($parsedData['events'])) {
            $latestEvent = $parsedData['events'][0];
            if ($latestEvent['date']) {
                updateTrackingNumber($user_id, $trackingId, ['last_event_date' => $latestEvent['date']]);
            }
        }

        logWebhook("    SUCCESS: Updated tracking #{$trackingId}");
        return ['success' => true];

    } catch (PDOException $e) {
        error_log("Database error in webhook: " . $e->getMessage());
        logWebhook("    ERROR: Database error - " . $e->getMessage());
        return ['success' => false, 'error' => 'Database error'];
    }
}

// Find tracking by number for a specific user
function findTrackingByNumber($user_id, $trackingNumber) {
    $pdo = getDbConnection();

    try {
        $stmt = $pdo->prepare("SELECT * FROM tracking_numbers WHERE user_id = ? AND tracking_number = ?");
        $stmt->execute([$user_id, strtoupper(preg_replace('/\s+/', '', $trackingNumber))]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Error finding tracking: " . $e->getMessage());
        return null;
    }
}

// Get latest event for tracking
function getLatestTrackingEvent($trackingNumberId) {
    $pdo = getDbConnection();

    try {
        $stmt = $pdo->prepare("SELECT * FROM tracking_events WHERE tracking_number_id = ? ORDER BY event_date DESC LIMIT 1");
        $stmt->execute([$trackingNumberId]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Error getting latest event: " . $e->getMessage());
        return null;
    }
}

// Set proper headers
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Handle the request
$result = handleWebhookRequest();

// Always return 200 to prevent webhook retries, unless already set
if (http_response_code() === 200) {
    http_response_code(200);
}

echo json_encode($result);

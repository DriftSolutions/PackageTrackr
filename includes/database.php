<?php
require_once 'config.php';
require_once __DIR__ . '/carriers/CarrierRegistry.php';

// Add a new tracking number
function addTrackingNumber($user_id, $trackingNumber, $carrier = null, $packageName = null) {
    $pdo = getDbConnection();

    // Clean tracking number
    $trackingNumber = preg_replace('/\s+/', '', strtoupper($trackingNumber));

    error_log("addTrackingNumber: Input carrier = " . var_export($carrier, true));

    // Auto-detect carrier if not provided
    if (!$carrier) {
        $carrier = detectCarrier($trackingNumber);
        error_log("addTrackingNumber: Detected carrier = " . var_export($carrier, true));
        if (!$carrier) {
            return ['success' => false, 'error' => 'Could not detect carrier. Please specify manually.'];
        }
    }

    error_log("addTrackingNumber: Final carrier to insert = " . var_export($carrier, true));

    // Check if tracking number already exists for this user
    $stmt = $pdo->prepare("SELECT id, view_type FROM tracking_numbers WHERE user_id = ? AND tracking_number = ?");
    $stmt->execute([$user_id, $trackingNumber]);
    $existing = $stmt->fetch();

    if ($existing) {
        if ($existing['view_type'] === 'trash') {
            // Restore from trash
            $stmt = $pdo->prepare("UPDATE tracking_numbers SET view_type = 'current', updated_at = NOW() WHERE id = ? AND user_id = ?");
            $stmt->execute([$existing['id'], $user_id]);
            return ['success' => true, 'message' => 'Tracking number restored from trash', 'id' => $existing['id']];
        }
        return ['success' => false, 'error' => 'Tracking number already exists', 'id' => $existing['id']];
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO tracking_numbers (user_id, tracking_number, carrier, package_name, created_at)
                              VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$user_id, $trackingNumber, $carrier, $packageName]);
        $id = $pdo->lastInsertId();

        // Register with 17track immediately
        require_once 'tracking_api.php';
        error_log("addTrackingNumber: About to register with 17track. Carrier = " . var_export($carrier, true));
        $registerResult = register17TrackNumber($user_id, $trackingNumber, $carrier);
        error_log("addTrackingNumber: Register result = " . json_encode($registerResult));

        if (!$registerResult['success']) {
            error_log("Warning: Failed to register {$trackingNumber} with 17track: " . ($registerResult['error'] ?? 'Unknown error'));
            return [
                'success' => false,
                'error' => 'Failed to register with 17track: ' . ($registerResult['error'] ?? 'Unknown error'),
                'detail' => isset($registerResult['debug']) ? $registerResult['debug'] : null,
                'id' => $id
            ];
        }

        return ['success' => true, 'message' => 'Tracking number added and registered with 17track', 'id' => $id];
    } catch (PDOException $e) {
        error_log("Error adding tracking number: " . $e->getMessage());
        return ['success' => false, 'error' => 'Database error occurred'];
    }
}

// Get tracking numbers by view type for a specific user
function getTrackingNumbers($user_id, $viewType = 'current') {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT * FROM tracking_numbers WHERE user_id = ? AND view_type = ? ORDER BY `delivered_date` DESC, CASE WHEN `estimated_delivery_date` IS NOT NULL THEN `estimated_delivery_date` ELSE '9999-12-31' END ASC, last_event_date DESC, created_at DESC");
    $stmt->execute([$user_id, $viewType]);
    return $stmt->fetchAll();
}

// Get a single tracking number by ID (verifies user ownership)
function getTrackingNumberById($user_id, $id) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT * FROM tracking_numbers WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $user_id]);
    return $stmt->fetch();
}

// Update tracking number details (verifies user ownership)
function updateTrackingNumber($user_id, $id, $data) {
    $pdo = getDbConnection();

    $allowedFields = ['package_name', 'status', 'raw_status', 'sub_status', 'view_type', 'estimated_delivery_date', 'delivered_date',
                     'last_event_date', 'is_permanent_status', 'is_outgoing', 'raw_api_response', 'last_api_check'];

    $updateFields = [];
    $values = [];

    foreach ($data as $key => $value) {
        if (in_array($key, $allowedFields)) {
            $updateFields[] = "$key = ?";
            $values[] = $value;
        }
    }

    if (empty($updateFields)) {
        return false;
    }

    $values[] = $id;
    $values[] = $user_id;
    $sql = "UPDATE tracking_numbers SET " . implode(', ', $updateFields) . " WHERE id = ? AND user_id = ?";

    try {
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($values);
    } catch (PDOException $e) {
        error_log("Error updating tracking number: " . $e->getMessage());
        return false;
    }
}

// Move tracking number to different view
function moveTrackingNumber($user_id, $id, $viewType) {
    if (!in_array($viewType, ['current', 'archive', 'trash'])) {
        return false;
    }

    return updateTrackingNumber($user_id, $id, ['view_type' => $viewType]);
}

// Delete tracking number permanently (verifies user ownership)
function deleteTrackingNumber($user_id, $id) {
    $pdo = getDbConnection();

    try {
        $stmt = $pdo->prepare("DELETE FROM tracking_numbers WHERE id = ? AND user_id = ?");
        return $stmt->execute([$id, $user_id]);
    } catch (PDOException $e) {
        error_log("Error deleting tracking number: " . $e->getMessage());
        return false;
    }
}

// Add tracking event
function addTrackingEvent($trackingNumberId, $eventDate, $status, $location = null, $description = null) {
    $pdo = getDbConnection();

    try {
        // Use INSERT IGNORE to skip duplicates (based on unique key: tracking_number_id, event_date, status)
        $stmt = $pdo->prepare("INSERT IGNORE INTO tracking_events (tracking_number_id, event_date, status, location, description)
                              VALUES (?, ?, ?, ?, ?)");
        return $stmt->execute([$trackingNumberId, $eventDate, $status, $location, $description]);
    } catch (PDOException $e) {
        error_log("Error adding tracking event: " . $e->getMessage());
        return false;
    }
}

// Get tracking events for a tracking number
function getTrackingEvents($trackingNumberId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT * FROM tracking_events WHERE tracking_number_id = ? ORDER BY event_date DESC");
    $stmt->execute([$trackingNumberId]);
    return $stmt->fetchAll();
}

// Get tracking numbers that need updates
function getTrackingNumbersForUpdate() {
    $pdo = getDbConnection();

    $startHour = (int)getSetting('update_start_hour', 8);
    $endHour = (int)getSetting('update_end_hour', 22);
    $currentHour = (int)date('G');

    // Only return results if current time is within update window
    if ($currentHour < $startHour || $currentHour >= $endHour) {
        return [];
    }

    // Get tracking numbers that:
    // 1. Are not in permanent status
    // 2. Haven't been checked in the last hour OR never been checked
    // 3. Are in current view
    $stmt = $pdo->prepare("SELECT * FROM tracking_numbers
                          WHERE is_permanent_status = FALSE
                          AND view_type = 'current'
                          AND (last_api_check IS NULL OR last_api_check < DATE_SUB(NOW(), INTERVAL 1 HOUR))
                          ORDER BY last_api_check ASC LIMIT 50");
    $stmt->execute();
    return $stmt->fetchAll();
}

// Get delivered packages older than specified days
function getDeliveredPackagesForAutoTrash($days = 30) {
    $pdo = getDbConnection();

    $stmt = $pdo->prepare("SELECT * FROM tracking_numbers
                          WHERE view_type = 'current'
                          AND status LIKE '%Delivered%'
                          AND delivered_date IS NOT NULL
                          AND delivered_date < DATE_SUB(CURDATE(), INTERVAL ? DAY)");
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

// Get tracking numbers in trash older than specified days
function getTrackingNumbersInTrashOlderThan($days = 90) {
    $pdo = getDbConnection();

    $stmt = $pdo->prepare("SELECT * FROM tracking_numbers
                          WHERE view_type = 'trash'
                          AND updated_at IS NOT NULL
                          AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                          ORDER BY updated_at ASC");
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

// Get counts for each view
function getViewCounts() {
    $pdo = getDbConnection();

    $counts = [
        'current' => 0,
        'archive' => 0,
        'trash' => 0
    ];

    $stmt = $pdo->query("SELECT view_type, COUNT(*) as count FROM tracking_numbers GROUP BY view_type");
    $results = $stmt->fetchAll();

    foreach ($results as $row) {
        $counts[$row['view_type']] = (int)$row['count'];
    }

    return $counts;
}

// Database Connection
function getDbConnection() {
    static $pdo = null;

    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            die("Database connection failed. Please check your configuration.");
        }
    }

    return $pdo;
}

// Carrier detection from tracking number
function detectCarrier($trackingNumber) {
    $trackingNumber = preg_replace('/\s+/', '', strtoupper($trackingNumber));

    error_log("detectCarrier: Checking tracking number: '{$trackingNumber}'");

    $carrier = CarrierRegistry::getInstance()->detectCarrier($trackingNumber);

    if ($carrier) {
        error_log("detectCarrier: Detected " . $carrier->getName());
        return $carrier->getId();
    }

    error_log("detectCarrier: No carrier detected for $trackingNumber");
    return null;
}

// Get setting value from database
function getSetting($key, $default = null) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        return $result ? $result['setting_value'] : $default;
    } catch (PDOException $e) {
        error_log("Error getting setting '$key': " . $e->getMessage());
        return $default;
    }
}

// Update setting value in database
function setSetting($key, $value) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                              ON DUPLICATE KEY UPDATE setting_value = ?");
        return $stmt->execute([$key, $value, $value]);
    } catch (PDOException $e) {
        error_log("Error setting '$key': " . $e->getMessage());
        return false;
    }
}

// Get user-specific setting
function getUserSetting($user_id, $key, $default = null) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT setting_value FROM user_settings WHERE user_id = ? AND setting_key = ?");
        $stmt->execute([$user_id, $key]);
        $result = $stmt->fetch();
        return $result ? $result['setting_value'] : $default;
    } catch (PDOException $e) {
        error_log("Error getting user setting '$key' for user $user_id: " . $e->getMessage());
        return $default;
    }
}

// Set user-specific setting
function setUserSetting($user_id, $key, $value) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("INSERT INTO user_settings (user_id, setting_key, setting_value) VALUES (?, ?, ?)
                              ON DUPLICATE KEY UPDATE setting_value = ?");
        return $stmt->execute([$user_id, $key, $value, $value]);
    } catch (PDOException $e) {
        error_log("Error setting '$key' for user $user_id: " . $e->getMessage());
        return false;
    }
}

// ============================================================================
// Claude AI Integration Functions
// ============================================================================

// Queue email for Claude AI analysis
function addPendingClaudeAnalysis($tracking_number_id, $user_id, $email_subject, $email_body) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("INSERT INTO pending_claude_analysis
                              (tracking_number_id, user_id, email_subject, email_body, created_at)
                              VALUES (?, ?, ?, ?, NOW())");
        return $stmt->execute([$tracking_number_id, $user_id, $email_subject, $email_body]);
    } catch (PDOException $e) {
        error_log("Error adding pending Claude analysis: " . $e->getMessage());
        return false;
    }
}

// Get unprocessed Claude analysis records
function getPendingClaudeAnalysis($limit = 50) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT * FROM pending_claude_analysis
                              WHERE processed_at IS NULL
                              AND processing_attempts < 3
                              ORDER BY created_at ASC
                              LIMIT ?");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting pending Claude analysis: " . $e->getMessage());
        return [];
    }
}

// Mark Claude analysis as processed and delete record
function markClaudeAnalysisProcessed($id) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("DELETE FROM pending_claude_analysis WHERE id = ?");
        return $stmt->execute([$id]);
    } catch (PDOException $e) {
        error_log("Error marking Claude analysis as processed: " . $e->getMessage());
        return false;
    }
}

// Increment processing attempts on failure
function incrementClaudeAnalysisAttempts($id, $error_message) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("UPDATE pending_claude_analysis
                              SET processing_attempts = processing_attempts + 1,
                                  last_error = ?
                              WHERE id = ?");
        return $stmt->execute([$error_message, $id]);
    } catch (PDOException $e) {
        error_log("Error incrementing Claude analysis attempts: " . $e->getMessage());
        return false;
    }
}

// Update package name from Claude analysis
function updatePackageNameFromClaude($tracking_number_id, $package_name) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("UPDATE tracking_numbers
                              SET package_name = ?
                              WHERE id = ?");
        return $stmt->execute([$package_name, $tracking_number_id]);
    } catch (PDOException $e) {
        error_log("Error updating package name from Claude: " . $e->getMessage());
        return false;
    }
}

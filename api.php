<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/database.php';
require_once 'includes/tracking_api.php';

header('Content-Type: application/json');

// Require authentication
requireAuth();
$user_id = getCurrentUserId();

$action = $_GET['action'] ?? $_POST['action'] ?? null;

if (!$action) {
    echo json_encode(['success' => false, 'error' => 'No action specified']);
    exit;
}

switch ($action) {
    case 'add':
        handleAdd();
        break;

    case 'update_name':
        handleUpdateName();
        break;

    case 'move':
        handleMove();
        break;

    case 'delete':
        handleDelete();
        break;

    case 'details':
        handleDetails();
        break;

    case 'refresh':
        handleRefresh();
        break;

    case 'toggle_outgoing':
        handleToggleOutgoing();
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}

// Handle adding new tracking number
function handleAdd() {
    global $user_id;

    $trackingNumber = $_POST['tracking_number'] ?? null;
    $carrier = $_POST['carrier'] ?? null;
    $packageName = $_POST['package_name'] ?? null;

    if (!$trackingNumber) {
        echo json_encode(['success' => false, 'error' => 'Tracking number is required']);
        return;
    }

    // Check if user has API key configured
    $apiKey = getUserSetting($user_id, '17track_api_key', '');
    if (empty($apiKey)) {
        echo json_encode(['success' => false, 'error' => 'Please configure your 17track API key in Settings before adding tracking numbers']);
        return;
    }

    $result = addTrackingNumber($user_id, $trackingNumber, $carrier ?: null, $packageName ?: null);
    echo json_encode($result);
}

// Handle updating package name
function handleUpdateName() {
    global $user_id;

    $id = $_POST['id'] ?? null;
    $packageName = $_POST['package_name'] ?? '';

    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'ID is required']);
        return;
    }

    $success = updateTrackingNumber($user_id, $id, ['package_name' => $packageName]);
    echo json_encode(['success' => $success]);
}

// Handle moving to different view
function handleMove() {
    global $user_id;

    $id = $_POST['id'] ?? null;
    $view = $_POST['view'] ?? null;

    if (!$id || !$view) {
        echo json_encode(['success' => false, 'error' => 'ID and view are required']);
        return;
    }

    $success = moveTrackingNumber($user_id, $id, $view);
    echo json_encode(['success' => $success]);
}

// Handle permanent deletion
function handleDelete() {
    global $user_id;

    $id = $_POST['id'] ?? null;

    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'ID is required']);
        return;
    }

    $success = deleteTrackingNumber($user_id, $id);
    echo json_encode(['success' => $success]);
}

// Handle getting tracking details
function handleDetails() {
    global $user_id;

    $id = $_GET['id'] ?? null;

    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'ID is required']);
        return;
    }

    $tracking = getTrackingNumberById($user_id, $id);

    if (!$tracking) {
        echo json_encode(['success' => false, 'error' => 'Tracking number not found']);
        return;
    }

    // Format sub_status if present
    if (!empty($tracking['sub_status'])) {
        $tracking['formatted_sub_status'] = format17TrackSubStatus($tracking['sub_status']);
    }

    // Get status color based on raw_status (with formatted status as fallback)
    $tracking['status_color'] = getStatusColor($tracking['raw_status'] ?? null, $tracking['status'] ?? null);

    $events = getTrackingEvents($id);

    echo json_encode([
        'success' => true,
        'tracking' => $tracking,
        'events' => $events
    ]);
}

// Handle refreshing tracking information
function handleRefresh() {
    global $user_id;

    $id = $_GET['id'] ?? null;

    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'ID is required']);
        return;
    }

    $result = updateTrackingInfo($user_id, $id);

    // Include debug info in response if API failed (helpful for troubleshooting)
    if (!$result['success'] && isset($result['debug'])) {
        $result['debug_info'] = $result['debug'];
    }

    echo json_encode($result);
}

// Handle toggling outgoing shipment status
function handleToggleOutgoing() {
    global $user_id;

    $id = $_POST['id'] ?? null;

    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'ID is required']);
        return;
    }

    $tracking = getTrackingNumberById($user_id, $id);
    if (!$tracking) {
        echo json_encode(['success' => false, 'error' => 'Tracking number not found']);
        return;
    }

    $newStatus = !$tracking['is_outgoing'];
    $success = updateTrackingNumber($user_id, $id, ['is_outgoing' => $newStatus]);
    echo json_encode(['success' => $success, 'is_outgoing' => $newStatus]);
}

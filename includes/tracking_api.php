<?php
require_once 'config.php';
require_once 'database.php';
require_once __DIR__ . '/carriers/CarrierRegistry.php';

// Fetch tracking information from 17track.net API
function fetchTrackingInfo($user_id, $trackingNumber, $carrier) {
    $apiKey = getUserSetting($user_id, '17track_api_key');

    if (!$apiKey || empty($apiKey)) {
        $error = 'API key not configured';
        error_log("17track API key not configured for user $user_id");
        return ['success' => false, 'error' => $error, 'debug' => 'Please configure 17track API key in settings'];
    }

    // Convert carrier to 17track carrier code
    $carrierCode = get17TrackCarrierCode($carrier);

    $curl = curl_init();

    // 17track requires POST request with JSON body
    $postData = [
        [
            'number' => $trackingNumber,
            'carrier' => $carrierCode
        ]
    ];

    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.17track.net/track/v2.4/gettrackinfo",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_HTTPHEADER => [
            "17token: {$apiKey}",
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS => json_encode($postData)
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($err) {
        error_log("17track API cURL Error: " . $err);
        return ['success' => false, 'error' => 'API request failed', 'debug' => 'cURL Error: ' . $err, 'tracking' => $trackingNumber, 'carrier' => $carrier];
    }

    if ($httpCode !== 200) {
        error_log("17track API HTTP Error: " . $httpCode . " Response: " . $response);
        $debugInfo = "HTTP Error {$httpCode}";
        if ($response) {
            $debugInfo .= " - Response: " . substr($response, 0, 200);
        }
        return ['success' => false, 'error' => 'API returned error code: ' . $httpCode, 'debug' => $debugInfo, 'tracking' => $trackingNumber, 'carrier' => $carrier];
    }

    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("17track API JSON Error: " . json_last_error_msg());
        return ['success' => false, 'error' => 'Invalid JSON response', 'debug' => 'JSON Error: ' . json_last_error_msg() . ' - Response: ' . substr($response, 0, 200), 'tracking' => $trackingNumber];
    }

    // Check API response code
    if (!isset($data['code']) || $data['code'] != 0) {
        $errorMsg = $data['msg'] ?? 'Unknown error';
        error_log("17track API Error: Code " . ($data['code'] ?? 'unknown') . " - " . $errorMsg);
        return ['success' => false, 'error' => '17track API error: ' . $errorMsg, 'debug' => 'API Code: ' . ($data['code'] ?? 'unknown') . ' - ' . $errorMsg, 'tracking' => $trackingNumber];
    }

    // Log the response structure for debugging estimated delivery dates
    if (isset($data['data']['accepted']) && !empty($data['data']['accepted'])) {
        error_log("17track Response Structure - First tracking accepted[0] keys: " . implode(', ', array_keys($data['data']['accepted'][0])));
        if (isset($data['data']['accepted'][0]['track_info'])) {
            error_log("17track track_info keys: " . implode(', ', array_keys($data['data']['accepted'][0]['track_info'])));
        }
    }

    return ['success' => true, 'data' => $data, 'raw' => $response];
}

// Register a tracking number with 17track (if needed)
function register17TrackNumber($user_id, $trackingNumber, $carrier) {
    $apiKey = getUserSetting($user_id, '17track_api_key');

    if (!$apiKey || empty($apiKey)) {
        return ['success' => false, 'error' => 'API key not configured', 'debug' => 'Please configure 17track API key in settings'];
    }

    $carrierCode = get17TrackCarrierCode($carrier);

    // Skip registration if carrier has no 17track code
    if ($carrierCode === 0) {
        error_log("Skipping 17track registration for {$trackingNumber}: carrier '{$carrier}' has no 17track code");
        return ['success' => true, 'skipped' => true, 'reason' => 'Carrier not supported by 17track'];
    }

    $curl = curl_init();

    // Include user_id as tag so webhook knows which user this belongs to
    $postData = [
        [
            'number' => $trackingNumber,
            'carrier' => $carrierCode,
            'auto_detection' => true,
            'tag' => (string)$user_id  // Tag with user ID for webhook routing
        ]
    ];

    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.17track.net/track/v2.4/register",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_HTTPHEADER => [
            "17token: {$apiKey}",
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS => json_encode($postData)
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($err) {
        error_log("17track Register Error: " . $err);
        return ['success' => false, 'error' => 'Failed to register', 'debug' => 'cURL Error: ' . $err, 'tracking' => $trackingNumber, 'carrier' => $carrier];
    }

    if ($httpCode !== 200) {
        error_log("17track Register HTTP Error: " . $httpCode . " Response: " . $response);
        $debugInfo = "HTTP Error {$httpCode}";
        if ($response) {
            $debugInfo .= " - Response: " . substr($response, 0, 200);
        }
        return ['success' => false, 'error' => 'Failed to register, code: ' . $httpCode, 'debug' => $debugInfo, 'tracking' => $trackingNumber, 'carrier' => $carrier];
    }

    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("17track Register JSON Error: " . json_last_error_msg());
        return ['success' => false, 'error' => 'Invalid JSON response', 'debug' => 'JSON Error: ' . json_last_error_msg() . ' - Response: ' . substr($response, 0, 200), 'tracking' => $trackingNumber];
    }

    // Check API response code
    if (isset($data['code']) && $data['code'] != 0) {
        $errorMsg = $data['msg'] ?? 'Unknown error';
        error_log("17track Register API Error: Code " . $data['code'] . " - " . $errorMsg);
        return ['success' => false, 'error' => 'API error: ' . $errorMsg, 'debug' => 'API Code: ' . $data['code'] . ' - ' . $errorMsg, 'tracking' => $trackingNumber];
    }

    return ['success' => true, 'data' => $data];
}

// Convert carrier name to 17track carrier code
// Codes from: https://res.17track.net/asset/carrier/info/apicarrier.all.json
function get17TrackCarrierCode($carrier) {
    return CarrierRegistry::getInstance()->get17TrackCode($carrier);
}

// Parse track_info object from 17track (shared between API and webhook)
function parseTrackInfo($tracking) {
    $result = [
        'status' => 'Unknown',
        'raw_status' => null,
        'sub_status' => null,
        'estimated_delivery_date' => null,
        'delivered_date' => null,
        'events' => [],
        'is_permanent_status' => false,
        'local_number' => null
    ];

    // Extract status from track_info
    if (!isset($tracking['track_info'])) {
        error_log("parseTrackInfo: No track_info in tracking data");
        return $result;
    }

    $trackInfo = $tracking['track_info'];

    // Extract local tracking number from misc_info if present
    if (isset($trackInfo['misc_info']['local_number']) && !empty($trackInfo['misc_info']['local_number'])) {
        $result['local_number'] = $trackInfo['misc_info']['local_number'];
    }

    // Get latest status
    if (isset($trackInfo['latest_status'])) {
        $latestStatus = $trackInfo['latest_status'];
        if (isset($latestStatus['status'])) {
            $result['raw_status'] = $latestStatus['status']; // Store raw status from API
            $result['status'] = format17TrackStatus($latestStatus['status']);
        }
        // Get sub_status (raw value from 17track)
        if (isset($latestStatus['sub_status'])) {
            $result['sub_status'] = $latestStatus['sub_status'];
        }
    }

    // Extract estimated delivery from v2.4 API response
    // Primary location: track_info.time_metrics.estimated_delivery_date.from
    if (isset($trackInfo['time_metrics']['estimated_delivery_date']['from'])) {
        $result['estimated_delivery_date'] = parseApiDate($trackInfo['time_metrics']['estimated_delivery_date']['from']);
    } elseif (isset($trackInfo['time_metrics']['estimated_delivery_date']['to'])) {
        // Fallback to 'to' field if 'from' is not available
        $result['estimated_delivery_date'] = parseApiDate($trackInfo['time_metrics']['estimated_delivery_date']['to']);
    } else {
        $result['estimated_delivery_date'] = null;
    }
//error_log(print_r($result, TRUE));

    // Log what we found for debugging
    if (empty($result['estimated_delivery_date'])) {
        error_log("parseTrackInfo: No estimated delivery date found.");
        if (isset($trackInfo['time_metrics'])) {
            error_log("  time_metrics keys: " . implode(', ', array_keys((array)$trackInfo['time_metrics'])));
        }
        error_log("  trackInfo keys: " . implode(', ', array_keys((array)$trackInfo)));
    }

    // Extract tracking events
    if (isset($trackInfo['tracking']['providers']) && is_array($trackInfo['tracking']['providers'])) {
        foreach ($trackInfo['tracking']['providers'] as $provider) {
            if (isset($provider['events']) && is_array($provider['events'])) {
                foreach ($provider['events'] as $event) {
                    $result['events'][] = parseTrackingEvent($event);
                }
            }
        }
    }

    // Check if permanent status
    $result['is_permanent_status'] = isPermanentStatus($result['status']);

    // If delivered, try to find delivery date
    if ($result['is_permanent_status'] && stripos($result['status'], 'Delivered') !== false) {
        if (!empty($result['events'])) {
            foreach ($result['events'] as $event) {
                if (stripos($event['status'], 'Delivered') !== false && $event['date']) {
                    $result['delivered_date'] = $event['date'];
                    break;
                }
            }
        }
    }

    return $result;
}

// Parse 17track API response and extract relevant information
function parseTrackingResponse($apiResponse) {
    // Debug: Check available keys in response
    if (!isset($apiResponse['data'])) {
        error_log("parseTrackingResponse: No 'data' key in response. Available keys: " . implode(', ', array_keys($apiResponse)));
        return null;
    }

    $data = $apiResponse['data'];
    error_log("parseTrackingResponse: Data keys: " . implode(', ', array_keys((array)$data)));

    if (isset($data['code']) && isset($data['data'])) {
        $data = $data['data'];
    }

    // Check for accepted or other possible response structures
    if (isset($data['accepted']) && !empty($data['accepted'])) {
        $tracking = $data['accepted'][0];
    } elseif (isset($data[0])) {
        // Sometimes 17track returns data directly as array
        $tracking = $data[0];
    } else {
        // Check if data itself has track_info (v2.4 format may return single object)
        if (isset($data['track_info'])) {
            $tracking = $data;
        } else {
            error_log("parseTrackingResponse: Could not find accepted array or tracking data. Data structure: " . json_encode($data));
            return null;
        }
    }

    // Use shared parsing function
    return parseTrackInfo($tracking);
}


// Parse a single tracking event from 17track
function parseTrackingEvent($event) {
    $parsed = [
        'date' => null,
        'status' => 'Unknown',
        'location' => null,
        'description' => null
    ];

    // Extract date
    if (isset($event['time_iso'])) {
        $parsed['date'] = parseApiDate($event['time_iso']);
    } elseif (isset($event['time_utc'])) {
        $parsed['date'] = parseApiDate($event['time_utc']);
    }

    // Extract status
    if (isset($event['stage']) && !empty($event['stage'])) {
        $parsed['status'] = format17TrackStatus($event['stage']);
    } else if (isset($event['sub_status'])) {
        $parsed['status'] = format17TrackStatus($event['sub_status']);
    }

    // Extract location
    if (isset($event['location'])) {
        $parsed['location'] = $event['location'];
    } elseif (isset($event['address'])) {
        $parsed['location'] = $event['address'];
    }

    // Extract description
    if (isset($event['description'])) {
        $parsed['description'] = format17TrackStatus($event['description']);
    }

    return $parsed;
}

// Update tracking information for a specific tracking number
function updateTrackingInfo($user_id, $trackingNumberId) {
    $tracking = getTrackingNumberById($user_id, $trackingNumberId);

    if (!$tracking) {
        return ['success' => false, 'error' => 'Tracking number not found'];
    }

    // Register with 17track first (idempotent operation)
    $registerResult = register17TrackNumber($user_id, $tracking['tracking_number'], $tracking['carrier']);

    if (!$registerResult['success']) {
        error_log("Registration failed for {$tracking['tracking_number']}: " . $registerResult['error']);
        // Continue anyway - tracking may already be registered
    }

    // Small delay to let 17track process
    sleep(2);

    // Fetch from API
    $apiResponse = fetchTrackingInfo($user_id, $tracking['tracking_number'], $tracking['carrier']);

    if (!$apiResponse['success']) {
        return $apiResponse;
    }

    // Parse response
    $parsedData = parseTrackingResponse($apiResponse);

    if (!$parsedData) {
        error_log("Failed to parse response for tracking {$tracking['id']}: " . json_encode($apiResponse));
        return [
            'success' => false,
            'error' => 'Could not parse API response',
            'debug' => 'Response structure did not match expected format. Check logs for details.',
            'response_keys' => isset($apiResponse['data']) ? array_keys((array)$apiResponse['data']) : 'no data key'
        ];
    }

    // Update tracking number
    $updateData = [
        'status' => $parsedData['status'],
        'is_permanent_status' => $parsedData['is_permanent_status'],
        'raw_api_response' => json_encode($apiResponse['data']),
        'last_api_check' => date('Y-m-d H:i:s')
    ];

    if ($parsedData['raw_status']) {
        $updateData['raw_status'] = $parsedData['raw_status'];
    }

    if ($parsedData['sub_status']) {
        $updateData['sub_status'] = $parsedData['sub_status'];
    }

    // Always update estimated_delivery_date if present in parsed data (even if null)
    if (array_key_exists('estimated_delivery_date', $parsedData)) {
        $updateData['estimated_delivery_date'] = $parsedData['estimated_delivery_date'];
    }

    // Always update delivered_date if present in parsed data (even if null)
    if (array_key_exists('delivered_date', $parsedData)) {
        $updateData['delivered_date'] = $parsedData['delivered_date'];
    }

    // Get the latest event date
    if (!empty($parsedData['events'])) {
        $latestEvent = $parsedData['events'][0];
        if ($latestEvent['date']) {
            $updateData['last_event_date'] = $latestEvent['date'];
        }

        // Add events to database
        foreach ($parsedData['events'] as $event) {
            if ($event['date']) {
                addTrackingEvent(
                    $trackingNumberId,
                    $event['date'],
                    $event['status'],
                    $event['location'],
                    $event['description']
                );
            }
        }
    }

    updateTrackingNumber($user_id, $trackingNumberId, $updateData);

    // Check for local tracking number and add it if it doesn't exist
    if (!empty($parsedData['local_number'])) {
        $localNumber = $parsedData['local_number'];
        error_log("Found local tracking number: {$localNumber}");

        // Add the local number with the same package name as the original (also registers with 17track)
        $result = addTrackingNumber($user_id, $localNumber, null, $tracking['package_name']);

        if ($result['success']) {
            error_log("Added local number to system with ID #{$result['id']}");
        } else if (isset($result['id'])) {
            // Already exists but has an ID
            error_log("Local number already exists in system (ID #{$result['id']})");
        } else {
            error_log("Failed to add local number: " . ($result['error'] ?? 'Unknown error'));
        }
    }

    return ['success' => true, 'data' => $parsedData];
}

// Get carrier logo URL
function getCarrierLogo($carrier) {
    return CarrierRegistry::getInstance()->getCarrierLogo($carrier);
}

// Get tracking URL for a carrier
function getTrackingUrl($trackingNumber, $carrier) {
    return CarrierRegistry::getInstance()->getTrackingUrl($trackingNumber, $carrier);
}

// Permanent status check
function isPermanentStatus($status) {
    $permanentStatuses = [
        'Delivered',
        'Exception',
    ];

    foreach ($permanentStatuses as $permStatus) {
        if (strcasecmp($status, $permStatus) !== false || strcasecmp($status, $permStatus.'_', strlen($permStatus) + 1) !== false) {
            return true;
        }
    }

    return false;
}

// Format 17track status string to human-readable status (v2.4 API)
// Handles the 9 main statuses from latest_status.status
// Reference: https://asset.17track.net/api/document/v2_en/index.html
function format17TrackStatus($statusText) {
    if (!$statusText) {
        return 'Unknown';
    }
if (strpos($statusText, ' ') !== FALSE) {
	return $statusText;
}

$n = strpos($statusText, '_');
if ($n !== FALSE) {
	$statusText = substr($statusText, 0, $n);
}

    // Map 9 main status values to human-readable format
    $statusMap = [
        'NotFound' => 'Not Found',
        'InfoReceived' => 'Information Received',
        'InTransit' => 'In Transit',
        'Expired' => 'Expired',
        'AvailableForPickup' => 'Available for Pickup',
        'OutForDelivery' => 'Out for Delivery',
        'DeliveryFailure' => 'Delivery Failed',
        'Delivered' => 'Delivered',
        'Exception' => 'Exception'
    ];
	if (isset($statusMap[$statusText])) {
		return $statusMap[$statusText];
	}

$has_upper = preg_match("/[A-Z]/", $statusText);
$has_lower = preg_match("/[a-z]/", $statusText);
if ($has_upper && $has_lower) {
	// Break it into individual words
	$ret = '';
	for ($i=0; $i < strlen($statusText); $i++) {
		$ch = $statusText[$i];
		if (ctype_upper($ch) && !empty($ret)) {
			$ret .= ' ';
		}
		$ret .= $ch;
	}
	return $ret;
}

    return $statusText;
}

// Format 17track sub-status string to human-readable description (v2.4 API)
// Handles the ~30 sub-statuses from latest_status.sub_status
// Reference: https://api.17track.net/en/doc?version=v2.4&anchor=sub-status-of-the-shipping-status
function format17TrackSubStatus($subStatusText) {
    if (!$subStatusText) {
        return null;
    }

    // If already contains spaces, assume it's already formatted
    if (strpos($subStatusText, ' ') !== false) {
        return $subStatusText;
    }

    // Map sub-status codes to human-readable descriptions
    // Organized by main status category
    $subStatusMap = [
        // NotFound sub-statuses
//        'NotFound_Other' => 'Not found',
        'NotFound_InvalidNumber' => 'Invalid tracking number',
        'NotFound_NoData' => 'No tracking data available',

        // InfoReceived sub-statuses
        'InfoReceived_Other' => 'Shipment information received',
        'InfoReceived_OnTheWay' => 'Shipment on the way to carrier',
        'InfoReceived_Received' => 'Shipment received by carrier',

        // InTransit sub-statuses
//        'InTransit_Other' => 'In transit',
        'InTransit_PickUp' => 'Picked up',
        'InTransit_Departure' => 'Departed from facility',
        'InTransit_Arrival' => 'Arrived at facility',
        'InTransit_CustomsClearance' => 'Customs clearance',
        'InTransit_CustomsClearanceComplete' => 'Customs clearance completed',
        'InTransit_CustomsException' => 'Customs clearance exception',

        // AvailableForPickup sub-statuses
  //      'AvailableForPickup_Other' => 'Available for pickup',
        'AvailableForPickup_ArrivedAtPickupPoint' => 'Arrived at pickup point',
        'AvailableForPickup_ReadyForPickup' => 'Ready for pickup',

        // OutForDelivery sub-statuses
//        'OutForDelivery_Other' => 'Out for delivery',
//        'OutForDelivery_OnTheWay' => 'On the way to deliver',

        // DeliveryFailure sub-statuses
//        'DeliveryFailure_Other' => 'Delivery failed',
        'DeliveryFailure_NoOne' => 'No one available to receive',
        'DeliveryFailure_Security' => 'Security issue',
        'DeliveryFailure_Rejected' => 'Delivery rejected',
        'DeliveryFailure_InvalidAddress' => 'Invalid delivery address',

        // Delivered sub-statuses
//        'Delivered_Other' => 'Delivered',
        'Delivered_InMailbox' => 'Delivered to mailbox',
        'Delivered_PickedUp' => 'Picked up by recipient',
        'Delivered_Signed' => 'Delivered and signed',

        // Exception sub-statuses
//        'Exception_Other' => 'Exception occurred',
        'Exception_Damage' => 'Package damaged',
        'Exception_Lost' => 'Package lost',
        'Exception_Returning' => 'Returning to sender',
        'Exception_Returned' => 'Returned to sender',
        'Exception_Delayed' => 'Shipment delayed',

        // Expired sub-statuses
//        'Expired_Other' => 'Tracking expired',
        'Expired_Undeliverable' => 'Undeliverable',
    ];

    if (isset($subStatusMap[$subStatusText])) {
        return $subStatusMap[$subStatusText];
    }

    return null;
}

// Parse various date formats from API
function parseApiDate($dateString) {
    if (!$dateString) {
        return null;
    }

    try {
        $date = new DateTime($dateString);
        return $date->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        error_log("Error parsing date '$dateString': " . $e->getMessage());
        return null;
    }
}

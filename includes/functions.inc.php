<?php
// Utility Functions

// Format date for display
function formatDate($date, $format = 'M j, Y') {
    if (!$date) return null;

    if (is_string($date)) {
        $date = new DateTime($date);
    }

    return $date->format($format);
}

// Format datetime for display
function formatDateTime($datetime, $format = 'M j, Y g:i A') {
    if (!$datetime) return null;

    if (is_string($datetime)) {
        $datetime = new DateTime($datetime);
    }

    return $datetime->format($format);
}

// Send email with standard headers
function send_email($to, $subject, $content, $from = '') {
    if (empty($from)) {
        $from = EMAIL_FROM;
    }
    $headers = "FROM: ".EMAIL_FROM_NAME." <".$from.">\r\n";

    return mail($to, $subject, $content, $headers, '-f'.$from);
}

// Get Bootstrap color class for status (for badges, borders, etc.)
// Takes raw_status from 17track API (one of 9 main statuses)
// Falls back to formatted status if raw_status is not available
// Returns: 'success', 'primary', 'warning', 'danger', 'info', or 'secondary'
function getStatusColor($raw_status, $formatted_status = null) {
    // If raw_status is not available, try to infer from formatted status
    if (empty($raw_status) && !empty($formatted_status)) {
        $status_lower = strtolower($formatted_status);

        if (stripos($status_lower, 'delivered') !== false) {
            return 'success';
        } elseif (stripos($status_lower, 'out for delivery') !== false ||
                  stripos($status_lower, 'in transit') !== false ||
                  stripos($status_lower, 'available for pickup') !== false) {
            return 'primary';
        } elseif (stripos($status_lower, 'information received') !== false ||
                  stripos($status_lower, 'not found') !== false) {
            return 'warning';
        } elseif (stripos($status_lower, 'exception') !== false ||
                  stripos($status_lower, 'delivery failure') !== false) {
            return 'danger';
        } elseif (stripos($status_lower, 'expired') !== false) {
            return 'secondary';
        }

        return 'info';
    }

    if (empty($raw_status)) {
        return 'info';
    }

    switch ($raw_status) {
        case 'Delivered':
            return 'success';

        case 'InTransit':
        case 'OutForDelivery':
        case 'AvailableForPickup':
            return 'primary';

        case 'InfoReceived':
        case 'NotFound':
            return 'warning';

        case 'Exception':
        case 'DeliveryFailure':
            return 'danger';

        case 'Expired':
            return 'secondary';

        default:
            return 'info';
    }
}

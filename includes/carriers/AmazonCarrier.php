<?php
require_once __DIR__ . '/Carrier.php';

/**
 * Amazon Logistics Carrier Implementation
 * Note: Currently disabled in detection but kept for API code reference
 */
class AmazonCarrier extends Carrier {
    public function getName(): string {
        return 'Amazon';
    }

    public function getId(): string {
        return 'Amazon';
    }

    public function get17TrackCode(): int {
        return 0; // Amazon orders are not tracked via 17track
    }

    public function getTrackingPatterns(): array {
        // Amazon Order ID format: 123-1234567-1234567 (3 digits, 7 digits, 7 digits)
        return [
            '[0-9]{3}-[0-9]{7}-[0-9]{7}'
        ];
    }

    public function getLogoPath(): string {
        return 'images/amazon.png';
    }

    public function getTrackingUrl(string $trackingNumber): string {
        // Link to Amazon order details page (requires login)
        return 'https://www.amazon.com/gp/your-account/order-details?orderID=' . urlencode($trackingNumber);
    }

    public function getDetectionPriority(): int {
        return 95; // Very high priority - specific pattern
    }

    public function isEnabled(): bool {
        return true;
    }
}

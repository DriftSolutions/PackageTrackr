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
        return 100308;
    }

    public function getTrackingPatterns(): array {
        // Amazon: TBA followed by 12-16 digits
        return [
            'TBA[0-9]{12,16}'
        ];
    }

    public function getLogoPath(): string {
        return 'images/amazon.png';
    }

    public function getTrackingUrl(string $trackingNumber): string {
        // Amazon doesn't have a direct tracking URL that works without login
        return '';
    }

    public function getDetectionPriority(): int {
        return 95; // Very high priority - specific pattern
    }

    /**
     * Amazon carrier is currently disabled for auto-detection
     * Enable by adding to CarrierRegistry
     */
    public function isEnabled(): bool {
        return false;
    }
}

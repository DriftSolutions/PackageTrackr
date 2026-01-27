<?php
require_once __DIR__ . '/Carrier.php';

/**
 * UPS Carrier Implementation
 */
class UpsCarrier extends Carrier {
    public function getName(): string {
        return 'UPS';
    }

    public function getId(): string {
        return 'UPS';
    }

    public function get17TrackCode(): int {
        return 100002;
    }

    public function getTrackingPatterns(): array {
        // UPS: 1Z followed by 16 alphanumeric characters
        return [
            '1Z[A-Z0-9]{16}'
        ];
    }

    public function getLogoPath(): string {
        return 'images/ups.png';
    }

    public function getTrackingUrl(string $trackingNumber): string {
        return 'https://www.ups.com/track?tracknum=' . urlencode($trackingNumber);
    }

    public function getDetectionPriority(): int {
        return 80; // High priority - very specific pattern
    }
}

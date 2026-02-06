<?php
require_once __DIR__ . '/Carrier.php';
require_once __DIR__ . '/../tracking_number_data/TrackingNumberDetector.php';

/**
 * UPS Carrier Implementation
 */
class UpsCarrier extends Carrier {
    private static ?TrackingNumberDetector $detector = null;

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

    public function matchesTrackingNumber(string $trackingNumber): bool {
        if (self::$detector === null) {
            self::$detector = new TrackingNumberDetector();
        }

        $trackingNumber = preg_replace('/\s+/', '', strtoupper($trackingNumber));
        $results = self::$detector->detect($trackingNumber);

        foreach ($results as $result) {
            if ($result['courier_code'] === 'ups') {
                return true;
            }
        }

        return false;
    }

    public function getDetectionPriority(): int {
        return 100; // Highest priority - detector-based with checksum validation
    }
}

<?php
require_once __DIR__ . '/Carrier.php';
require_once __DIR__ . '/../tracking_number_data/TrackingNumberDetector.php';

/**
 * USPS Carrier Implementation
 */
class UspsCarrier extends Carrier {
    private static ?TrackingNumberDetector $detector = null;

    public function getName(): string {
        return 'USPS';
    }

    public function getId(): string {
        return 'USPS';
    }

    public function get17TrackCode(): int {
        return 21051;
    }

    public function getTrackingPatterns(): array {
        // USPS has multiple tracking number formats:
        // - 22 digits starting with 94, 93, 92, or 95
        // - 16 digits starting with 70, 14, 23, or 03
        // - 10 digits starting with M0 or 82
        // - International format: 2 letters + 9 digits + 2 letters (but NOT ending in CN)
        return [
            '(?:94|93|92|95)[0-9]{20}',
            '(?:70|14|23|03)[0-9]{14}',
            '(?:M0|82)[0-9]{8}',
            '[A-Z]{2}[0-9]{9}(?!CN)[A-Z]{2}'  // International, but not China Post (CN)
        ];
    }

    public function getLogoPath(): string {
        return 'images/usps.png';
    }

    public function getTrackingUrl(string $trackingNumber): string {
        return 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . urlencode($trackingNumber);
    }

    public function matchesTrackingNumber(string $trackingNumber): bool {
        if (self::$detector === null) {
            self::$detector = new TrackingNumberDetector();
        }

        $trackingNumber = preg_replace('/\s+/', '', strtoupper($trackingNumber));
        $results = self::$detector->detect($trackingNumber);

        foreach ($results as $result) {
            if ($result['courier_code'] === 'usps' || $result['courier_code'] === 's10') {
                return true;
            }
        }

        return false;
    }

    public function getDetectionPriority(): int {
        return 99; // High priority - detector-based with checksum validation
    }
}

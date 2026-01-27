<?php
require_once __DIR__ . '/Carrier.php';

/**
 * FedEx Carrier Implementation
 */
class FedexCarrier extends Carrier {
    public function getName(): string {
        return 'FedEx';
    }

    public function getId(): string {
        return 'FedEx';
    }

    public function get17TrackCode(): int {
        return 100003;
    }

    public function getTrackingPatterns(): array {
        // FedEx tracking number formats:
        // - 12 digits
        // - 15 digits
        // - 22 digits starting with 96
        return [
            '96[0-9]{20}',       // Most specific - check first
            '[0-9]{15}',         // 15 digits
            '[0-9]{12}'          // 12 digits - most generic
        ];
    }

    public function getLogoPath(): string {
        return 'images/fedex.png';
    }

    public function getTrackingUrl(string $trackingNumber): string {
        return 'https://www.fedex.com/fedextrack/?trknbr=' . urlencode($trackingNumber);
    }

    public function getDetectionPriority(): int {
        return 20; // Lower priority - generic patterns that could match other carriers
    }
}

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
        // - 12 digits (Express) - exclude year-like prefixes (19xx, 20xx) to avoid date false positives
        // - 15 digits starting with 0-3 or 6-7 (Ground)
        // - 20 digits starting with 00 (Ground 96)
        // - 22 digits starting with 96 (SmartPost)
        return [
            '96[0-9]{20}',           // SmartPost - 22 digits starting with 96
            '00[0-9]{18}',           // Ground 96 - 20 digits starting with 00
            '[0-36-7][0-9]{14}',     // Ground - 15 digits starting with 0-3 or 6-7
            '(?!19|20)[0-9]{12}'     // Express - 12 digits, not starting with 19 or 20
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

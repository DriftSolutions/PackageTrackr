<?php
require_once __DIR__ . '/Carrier.php';

/**
 * China Post Carrier Implementation
 */
class ChinaPostCarrier extends Carrier {
    public function getName(): string {
        return 'China Post';
    }

    public function getId(): string {
        return 'China Post';
    }

    public function get17TrackCode(): int {
        return 3011;
    }

    public function getTrackingPatterns(): array {
        // China Post tracking number formats:
        // - 2 letters + 9 digits + CN (e.g., RA123456789CN)
        // - ZC followed by 11 digits (e.g., ZC59828236999)
        return [
            '[A-Z]{2}[0-9]{9}CN',
            'ZC[0-9]{11}'
        ];
    }

    public function getLogoPath(): string {
        return 'images/chinapost.png';
    }

    public function getTrackingUrl(string $trackingNumber): string {
        return 'https://www.17track.net/?nums=' . urlencode($trackingNumber);
    }

    public function getDetectionPriority(): int {
        return 70; // Higher than USPS to catch CN endings first
    }
}

<?php
require_once __DIR__ . '/Carrier.php';

/**
 * YANWEN Carrier Implementation
 */
class YanwenCarrier extends Carrier {
    public function getName(): string {
        return 'YANWEN';
    }

    public function getId(): string {
        return 'YANWEN';
    }

    public function get17TrackCode(): int {
        return 190012;
    }

    public function getTrackingPatterns(): array {
        // YANWEN tracking number formats:
        // - 2 letters + 9 digits + YP (e.g., UK882095905YP)
        return [
            '[A-Z]{2}[0-9]{9}YP'
        ];
    }

    public function getLogoPath(): string {
        return 'images/yanwen.png';
    }

    public function getTrackingUrl(string $trackingNumber): string {
        return 'https://www.17track.net/?nums=' . urlencode($trackingNumber);
    }

    public function getDetectionPriority(): int {
        return 75; // Similar priority to other Chinese carriers
    }
}

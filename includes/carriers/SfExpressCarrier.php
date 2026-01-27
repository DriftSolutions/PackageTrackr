<?php
require_once __DIR__ . '/Carrier.php';

/**
 * SF Express Carrier Implementation
 */
class SfExpressCarrier extends Carrier {
    public function getName(): string {
        return 'SF Express';
    }

    public function getId(): string {
        return 'SF Express';
    }

    public function get17TrackCode(): int {
        return 100012;
    }

    public function getTrackingPatterns(): array {
        // SF Express tracking number formats:
        // - SF followed by 12-15 digits
        // - 12 digits starting with specific prefixes (268, 118, 518, 688, 888, 588, 388, 689)
        return [
            'SF[0-9]{12,15}',
            '(?:268|118|518|688|888|588|388|689)[0-9]{9}'
        ];
    }

    public function getLogoPath(): string {
        return 'images/sfexpress.png';
    }

    public function getTrackingUrl(string $trackingNumber): string {
        return 'https://www.17track.net/?nums=' . urlencode($trackingNumber);
    }

    public function getDetectionPriority(): int {
        return 75; // Higher than FedEx to catch SF prefixes first
    }
}

<?php
require_once __DIR__ . '/Carrier.php';

/**
 * UniUni Carrier Implementation
 */
class UniUniCarrier extends Carrier {
    public function getName(): string {
        return 'UniUni';
    }

    public function getId(): string {
        return 'UniUni';
    }

    public function get17TrackCode(): int {
        return 100134;
    }

    public function getTrackingPatterns(): array {
        // UniUni tracking number formats:
        // - UU followed by 17 alphanumeric characters (e.g., UUS61X0450988753059)
        return [
            'UU[A-Z0-9]{17}'
        ];
    }

    public function getLogoPath(): string {
        return 'images/uniuni.png';
    }

    public function getTrackingUrl(string $trackingNumber): string {
        return 'https://www.17track.net/?nums=' . urlencode($trackingNumber);
    }

    public function getDetectionPriority(): int {
        return 85; // High priority - specific UU prefix
    }
}

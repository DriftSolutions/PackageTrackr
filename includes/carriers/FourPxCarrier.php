<?php
require_once __DIR__ . '/Carrier.php';

class FourPxCarrier extends Carrier {
    public function getName(): string {
        return '4PX';
    }

    public function getId(): string {
        return '4PX';
    }

    public function get17TrackCode(): int {
        return 190094;
    }

    public function getTrackingPatterns(): array {
        return [
            '4PX[0-9]{10,15}CN',
        ];
    }

    public function getLogoPath(): string {
        return 'images/carriers/4px.png';
    }

    public function getTrackingUrl(string $trackingNumber): string {
        return 'https://www.17track.net/?nums=' . urlencode($trackingNumber);
    }

    public function getDetectionPriority(): int {
        return 72;
    }
}

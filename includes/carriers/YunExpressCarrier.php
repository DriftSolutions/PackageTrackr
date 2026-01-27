<?php
require_once __DIR__ . '/Carrier.php';

/**
 * YunExpress Carrier Implementation
 */
class YunExpressCarrier extends Carrier {
    public function getName(): string {
        return 'YunExpress';
    }

    public function getId(): string {
        return 'YunExpress';
    }

    public function get17TrackCode(): int {
        return 190008;
    }

    public function getTrackingPatterns(): array {
        // YunExpress: YT followed by 16 digits
        return [
            'YT[0-9]{16}'
        ];
    }

    public function getLogoPath(): string {
        return 'images/yunexpress.png';
    }

    public function getTrackingUrl(string $trackingNumber): string {
        return 'https://www.yunexpress.com/track/?number=' . urlencode($trackingNumber);
    }

    public function getDetectionPriority(): int {
        return 90; // High priority - very specific pattern
    }
}

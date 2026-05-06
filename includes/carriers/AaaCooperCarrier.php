<?php
require_once __DIR__ . '/Carrier.php';

/**
 * AAA Cooper Transportation Carrier Implementation
 *
 * Manual-only carrier - tracking patterns are intentionally empty to prevent
 * auto-detection, since AAA Cooper tracking numbers are short numeric strings
 * that would cause false positives with other carriers.
 */
class AaaCooperCarrier extends Carrier {
    public function getName(): string {
        return 'AAA Cooper';
    }

    public function getId(): string {
        return 'AAACooper';
    }

    public function get17TrackCode(): int {
        return 100343;
    }

    public function getTrackingPatterns(): array {
        // Intentionally empty - AAA Cooper uses short numeric tracking numbers
        // that would match too many other carriers. Must be selected manually.
        return [];
    }

    public function getLogoPath(): string {
        return 'images/carriers/aaacooper.png';
    }

    public function getTrackingUrl(string $trackingNumber): string {
        return 'https://www.aaacooper.com/workspace/action-trac?id=' . urlencode($trackingNumber) . '&PG=ProNumber';
//        return 'https://www.aaacooper.com/pwb/Transit/ProTrackResults.aspx?ProNum=' . urlencode($trackingNumber) . '&AllAccounts=true';
    }
}

<?php
/**
 * Abstract base class for shipping carriers
 *
 * Each carrier implementation should extend this class and provide
 * carrier-specific tracking number patterns, API codes, URLs, etc.
 */
abstract class Carrier {
    /**
     * Get the carrier's display name
     * @return string
     */
    abstract public function getName(): string;

    /**
     * Get the carrier's internal identifier (used in database)
     * @return string
     */
    abstract public function getId(): string;

    /**
     * Get the 17track.net carrier code
     * Reference: https://res.17track.net/asset/carrier/info/apicarrier.all.json
     * @return int 0 for auto-detect
     */
    abstract public function get17TrackCode(): int;

    /**
     * Get regex patterns for recognizing this carrier's tracking numbers
     * Patterns should NOT include delimiters (^$) - they will be wrapped appropriately
     * @return array Array of regex patterns (without delimiters)
     */
    abstract public function getTrackingPatterns(): array;

    /**
     * Get the path to the carrier's logo image
     * @return string
     */
    abstract public function getLogoPath(): string;

    /**
     * Get the tracking URL for a tracking number
     * @param string $trackingNumber
     * @return string
     */
    abstract public function getTrackingUrl(string $trackingNumber): string;

    /**
     * Get the detection priority (higher = checked first)
     * Use higher values for carriers with more specific patterns
     * to avoid false matches with generic patterns
     * @return int
     */
    public function getDetectionPriority(): int {
        return 0;
    }

    /**
     * Validate if a tracking number matches this carrier's patterns
     * @param string $trackingNumber
     * @return bool
     */
    public function matchesTrackingNumber(string $trackingNumber): bool {
        $trackingNumber = preg_replace('/\s+/', '', strtoupper($trackingNumber));

        foreach ($this->getTrackingPatterns() as $pattern) {
            if (preg_match('/^' . $pattern . '$/i', $trackingNumber)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract tracking numbers from text that match this carrier
     * @param string $text
     * @return array Array of tracking numbers
     */
    public function extractTrackingNumbers(string $text): array {
        $matches = [];

        foreach ($this->getTrackingPatterns() as $pattern) {
            preg_match_all('/\b(' . $pattern . ')\b/i', $text, $found);
            foreach ($found[1] as $match) {
                $matches[] = strtoupper($match);
            }
        }

        return array_unique($matches);
    }
}

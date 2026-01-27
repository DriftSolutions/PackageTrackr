<?php
/**
 * Carrier Registry - Singleton that manages all carrier implementations
 *
 * Usage:
 *   $registry = CarrierRegistry::getInstance();
 *   $carrier = $registry->getCarrier('UPS');
 *   $carrier = $registry->detectCarrier('1Z999AA10123456784');
 */

require_once __DIR__ . '/Carrier.php';
require_once __DIR__ . '/UpsCarrier.php';
require_once __DIR__ . '/UspsCarrier.php';
require_once __DIR__ . '/FedexCarrier.php';
require_once __DIR__ . '/YunExpressCarrier.php';
require_once __DIR__ . '/ChinaPostCarrier.php';
require_once __DIR__ . '/SfExpressCarrier.php';
require_once __DIR__ . '/AmazonCarrier.php';

class CarrierRegistry {
    private static ?CarrierRegistry $instance = null;

    /** @var Carrier[] */
    private array $carriers = [];

    /** @var Carrier[] Carriers sorted by detection priority (highest first) */
    private array $sortedCarriers = [];

    private function __construct() {
        $this->registerDefaultCarriers();
    }

    /**
     * Get the singleton instance
     */
    public static function getInstance(): CarrierRegistry {
        if (self::$instance === null) {
            self::$instance = new CarrierRegistry();
        }
        return self::$instance;
    }

    /**
     * Register the default set of carriers
     */
    private function registerDefaultCarriers(): void {
        // Register all built-in carriers
        // Note: Amazon is registered but won't be used for auto-detection (see detectCarrier)
        $this->register(new UpsCarrier());
        $this->register(new UspsCarrier());
        $this->register(new FedexCarrier());
        $this->register(new YunExpressCarrier());
        $this->register(new ChinaPostCarrier());
        $this->register(new SfExpressCarrier());
        $this->register(new AmazonCarrier());
    }

    /**
     * Register a carrier
     */
    public function register(Carrier $carrier): void {
        $this->carriers[$carrier->getId()] = $carrier;
        $this->updateSortedCarriers();
    }

    /**
     * Update the sorted carriers array based on priority
     */
    private function updateSortedCarriers(): void {
        $this->sortedCarriers = $this->carriers;
        usort($this->sortedCarriers, function(Carrier $a, Carrier $b) {
            return $b->getDetectionPriority() - $a->getDetectionPriority();
        });
    }

    /**
     * Get a carrier by ID/name
     * @param string $carrierId The carrier ID (e.g., 'UPS', 'FedEx')
     * @return Carrier|null
     */
    public function getCarrier(string $carrierId): ?Carrier {
        return $this->carriers[$carrierId] ?? null;
    }

    /**
     * Get all registered carriers
     * @return Carrier[]
     */
    public function getAllCarriers(): array {
        return $this->carriers;
    }

    /**
     * Get all enabled carriers (for dropdown menus, etc.)
     * @return Carrier[]
     */
    public function getEnabledCarriers(): array {
        return array_filter($this->carriers, function(Carrier $carrier) {
            return !method_exists($carrier, 'isEnabled') || $carrier->isEnabled();
        });
    }

    /**
     * Detect the carrier for a tracking number
     * @param string $trackingNumber
     * @return Carrier|null
     */
    public function detectCarrier(string $trackingNumber): ?Carrier {
        $trackingNumber = preg_replace('/\s+/', '', strtoupper($trackingNumber));

        // Check carriers in priority order
        foreach ($this->sortedCarriers as $carrier) {
            // Skip disabled carriers for auto-detection
            if (method_exists($carrier, 'isEnabled') && !$carrier->isEnabled()) {
                continue;
            }

            if ($carrier->matchesTrackingNumber($trackingNumber)) {
                return $carrier;
            }
        }

        return null;
    }

    /**
     * Detect carrier and return its ID (for backward compatibility)
     * @param string $trackingNumber
     * @return string|null
     */
    public function detectCarrierId(string $trackingNumber): ?string {
        $carrier = $this->detectCarrier($trackingNumber);
        return $carrier ? $carrier->getId() : null;
    }

    /**
     * Get the 17track carrier code for a carrier
     * @param string $carrierId
     * @return int 0 for auto-detect if carrier not found
     */
    public function get17TrackCode(string $carrierId): int {
        $carrier = $this->getCarrier($carrierId);
        return $carrier ? $carrier->get17TrackCode() : 0;
    }

    /**
     * Get the logo path for a carrier
     * @param string $carrierId
     * @return string Empty string if carrier not found
     */
    public function getCarrierLogo(string $carrierId): string {
        $carrier = $this->getCarrier($carrierId);
        return $carrier ? $carrier->getLogoPath() : '';
    }

    /**
     * Get the tracking URL for a carrier and tracking number
     * @param string $trackingNumber
     * @param string $carrierId
     * @return string Empty string if carrier not found
     */
    public function getTrackingUrl(string $trackingNumber, string $carrierId): string {
        $carrier = $this->getCarrier($carrierId);
        return $carrier ? $carrier->getTrackingUrl($trackingNumber) : '';
    }

    /**
     * Extract all tracking numbers from text
     * @param string $text
     * @return array Array of ['carrier' => Carrier, 'number' => string]
     */
    public function extractTrackingNumbers(string $text): array {
        // Handle HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/\s+/', ' ', $text);

        $results = [];
        $foundNumbers = []; // Track found numbers to avoid duplicates

        // Extract in priority order so more specific patterns match first
        foreach ($this->sortedCarriers as $carrier) {
            // Skip disabled carriers
            if (method_exists($carrier, 'isEnabled') && !$carrier->isEnabled()) {
                continue;
            }

            $numbers = $carrier->extractTrackingNumbers($text);
            foreach ($numbers as $number) {
                // Skip if we already found this number with another carrier
                if (isset($foundNumbers[$number])) {
                    continue;
                }

                $foundNumbers[$number] = true;
                $results[] = [
                    'carrier' => $carrier->getId(),
                    'number' => $number
                ];
            }
        }

        return $results;
    }

    /**
     * Get carrier options for HTML select dropdown
     * @param bool $includeAutoDetect Include an auto-detect option
     * @return array Array of ['value' => string, 'label' => string]
     */
    public function getCarrierOptions(bool $includeAutoDetect = true): array {
        $options = [];

        if ($includeAutoDetect) {
            $options[] = ['value' => '', 'label' => 'Auto-detect'];
        }

        foreach ($this->getEnabledCarriers() as $carrier) {
            $options[] = [
                'value' => $carrier->getId(),
                'label' => $carrier->getName()
            ];
        }

        return $options;
    }
}

<?php

/**
 * TrackingNumberDetector
 *
 * Detects shipping courier from a tracking number using the tracking_number_data
 * JSON specification files. Implements regex matching and all checksum algorithms
 * (mod7, mod10, s10, sum_product_with_weightings_and_modulo, mod_37_36, luhn).
 *
 * Usage:
 *   $detector = new TrackingNumberDetector();
 *   $results = $detector->detect('1Z5R89390357567127');
 *   // Returns array of matches, each with courier_code, courier_name, etc.
 */
class TrackingNumberDetector
{
    /** @var array Loaded courier definitions */
    private $couriers = [];

    /** @var string Path to the couriers JSON directory */
    private $couriersDir;

    /**
     * @param string|null $couriersDir Path to the couriers/ directory. Defaults to ./couriers/
     */
    public function __construct($couriersDir = null)
    {
        $this->couriersDir = $couriersDir ?: __DIR__ . '/couriers';
        $this->loadCouriers();
    }

    /**
     * Detect which courier(s) a tracking number belongs to.
     *
     * @param string $trackingNumber The tracking number to identify
     * @return array Array of matching results, each containing:
     *   - courier_code: string
     *   - courier_name: string
     *   - tracking_number_id: string|null
     *   - tracking_number_name: string
     *   - tracking_url: string|null (with %s replaced by the tracking number)
     *   - partners: array of partner courier IDs
     */
    public function detect($trackingNumber)
    {
        $trackingNumber = trim($trackingNumber);
        $results = [];

        foreach ($this->couriers as $courier) {
            foreach ($courier['tracking_numbers'] as $tnDef) {
                $match = $this->matchTrackingNumber($trackingNumber, $tnDef);
                if ($match === null) {
                    continue;
                }

                if (!$this->validateChecksum($match, $tnDef)) {
                    continue;
                }

                if (!$this->validateAdditionalRequirements($match, $tnDef)) {
                    continue;
                }

                $cleanNumber = preg_replace('/\s+/', '', $trackingNumber);
                $trackingUrl = null;
                if (!empty($tnDef['tracking_url'])) {
                    $trackingUrl = sprintf($tnDef['tracking_url'], $cleanNumber);
                }

                $partners = [];
                if (!empty($tnDef['partners'])) {
                    foreach ($tnDef['partners'] as $partner) {
                        if ($this->partnerMatches($match, $partner)) {
                            $partners[] = [
                                'partner_id' => $partner['partner_id'],
                                'partner_type' => $partner['partner_type'] ?? null,
                                'description' => $partner['description'] ?? null,
                            ];
                        }
                    }
                }

                $results[] = [
                    'courier_code' => $courier['courier_code'],
                    'courier_name' => $courier['name'],
                    'tracking_number_id' => $tnDef['id'] ?? null,
                    'tracking_number_name' => $tnDef['name'],
                    'tracking_url' => $trackingUrl,
                    'partners' => $partners,
                ];
            }
        }

        return $results;
    }

    /**
     * Load all courier JSON files from the couriers directory.
     */
    private function loadCouriers()
    {
        $files = glob($this->couriersDir . '/*.json');
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && isset($data['tracking_numbers'])) {
                $this->couriers[] = $data;
            }
        }
    }

    /**
     * Try to match a tracking number against a tracking number definition.
     *
     * @return array|null Named group matches, or null if no match
     */
    private function matchTrackingNumber($trackingNumber, $tnDef)
    {
        $pattern = $tnDef['regex'];
        if (is_array($pattern)) {
            $pattern = implode('', $pattern);
        }

        $regex = '/^' . $pattern . '$/';

        if (!preg_match($regex, $trackingNumber, $matches)) {
            return null;
        }

        // Extract only named groups
        $named = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $named[$key] = $value;
            }
        }
        return $named;
    }

    /**
     * Validate the checksum for a matched tracking number.
     *
     * @return bool True if valid (or no checksum required)
     */
    private function validateChecksum($match, $tnDef)
    {
        if (empty($tnDef['validation']['checksum'])) {
            return true;
        }

        $checksumInfo = $tnDef['validation']['checksum'];
        $algorithm = $checksumInfo['name'];

        if (!isset($match['SerialNumber']) || !isset($match['CheckDigit'])) {
            return true;
        }

        $serialNumber = preg_replace('/\s/', '', $match['SerialNumber']);
        $checkDigit = preg_replace('/\s/', '', $match['CheckDigit']);

        // Apply serial number formatting (prepend_if)
        $serialNumber = $this->formatSerialNumber(
            $serialNumber,
            $tnDef['validation']['serial_number_format'] ?? null
        );

        switch ($algorithm) {
            case 'mod7':
                return $this->validatesMod7($serialNumber, $checkDigit);
            case 'mod10':
                return $this->validatesMod10($serialNumber, $checkDigit, $checksumInfo);
            case 's10':
                return $this->validatesS10($serialNumber, $checkDigit);
            case 'sum_product_with_weightings_and_modulo':
                return $this->validatesSumProduct($serialNumber, $checkDigit, $checksumInfo);
            case 'mod_37_36':
                return $this->validatesMod3736($serialNumber, $checkDigit);
            case 'luhn':
                return $this->validatesLuhn($serialNumber, $checkDigit);
            default:
                return true;
        }
    }

    /**
     * Apply serial number formatting rules (e.g. prepend_if).
     */
    private function formatSerialNumber($serialNumber, $formatInfo)
    {
        if (!$formatInfo) {
            return $serialNumber;
        }

        if (isset($formatInfo['prepend_if'])) {
            $prep = $formatInfo['prepend_if'];
            if (preg_match('/' . $prep['matches_regex'] . '/', $serialNumber)) {
                return $prep['content'] . $serialNumber;
            }
        }

        return $serialNumber;
    }

    /**
     * Validate additional requirements (e.g. S10 country code must exist).
     */
    private function validateAdditionalRequirements($match, $tnDef)
    {
        if (empty($tnDef['validation']['additional']['exists'])) {
            return true;
        }

        $requiredFields = $tnDef['validation']['additional']['exists'];

        foreach ($requiredFields as $fieldName) {
            if (empty($tnDef['additional'])) {
                return false;
            }

            $found = false;
            foreach ($tnDef['additional'] as $additionalDef) {
                if ($additionalDef['name'] !== $fieldName) {
                    continue;
                }

                $groupName = $additionalDef['regex_group_name'];
                if (!isset($match[$groupName])) {
                    return false;
                }

                $value = preg_replace('/\s/', '', $match[$groupName]);

                foreach ($additionalDef['lookup'] as $entry) {
                    if (isset($entry['matches']) && $entry['matches'] === $value) {
                        $found = true;
                        break 2;
                    }
                    if (isset($entry['matches_regex']) && preg_match('/^' . $entry['matches_regex'] . '$/', $value)) {
                        $found = true;
                        break 2;
                    }
                }
            }

            if (!$found) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a partner definition matches the current tracking number.
     */
    private function partnerMatches($match, $partner)
    {
        if (empty($partner['validation']['matches_all'])) {
            // No conditions means it always matches
            return true;
        }

        foreach ($partner['validation']['matches_all'] as $condition) {
            $groupName = $condition['regex_group_name'];
            if (!isset($match[$groupName])) {
                return false;
            }

            $value = preg_replace('/\s/', '', $match[$groupName]);

            if (isset($condition['matches'])) {
                if ($value !== $condition['matches']) {
                    return false;
                }
            }

            if (isset($condition['matches_regex'])) {
                if (!preg_match('/^' . $condition['matches_regex'] . '$/', $value)) {
                    return false;
                }
            }
        }

        return true;
    }

    // =========================================================================
    // Checksum Algorithms
    // =========================================================================

    /**
     * Mod 7 checksum. Used by DHL Express.
     */
    private function validatesMod7($sequence, $checkDigit)
    {
        return (intval($sequence) % 7) === intval($checkDigit);
    }

    /**
     * Mod 10 checksum. Used by many carriers.
     * Supports: reverse, odds_multiplier, evens_multiplier.
     * Letters are converted to numbers via (ord(char) - 3) % 10.
     */
    private function validatesMod10($sequence, $checkDigit, $extras = [])
    {
        $total = 0;
        $chars = str_split($sequence);

        if (!empty($extras['reverse'])) {
            $chars = array_reverse($chars);
        }

        foreach ($chars as $i => $c) {
            if (ctype_digit($c)) {
                $x = intval($c);
            } else {
                $x = (ord($c) - 3) % 10;
            }

            if (isset($extras['odds_multiplier']) && ($i % 2 === 1)) {
                $x *= intval($extras['odds_multiplier']);
            } elseif (isset($extras['evens_multiplier']) && ($i % 2 === 0)) {
                $x *= intval($extras['evens_multiplier']);
            }

            $total += $x;
        }

        $check = $total % 10;
        if ($check !== 0) {
            $check = 10 - $check;
        }

        return $check === intval($checkDigit);
    }

    /**
     * S10 International Standard checksum.
     * Uses fixed weighting [8,6,4,2,3,5,9,7].
     * Special handling: remainder 1 → 0, remainder 0 → 5, else 11 - remainder.
     */
    private function validatesS10($sequence, $checkDigit)
    {
        $weighting = [8, 6, 4, 2, 3, 5, 9, 7];
        $total = 0;
        $chars = str_split($sequence);

        for ($i = 0; $i < count($weighting) && $i < count($chars); $i++) {
            $total += intval($chars[$i]) * $weighting[$i];
        }

        $remainder = $total % 11;
        if ($remainder === 1) {
            $check = 0;
        } elseif ($remainder === 0) {
            $check = 5;
        } else {
            $check = 11 - $remainder;
        }

        return $check === intval($checkDigit);
    }

    /**
     * Sum product with weightings and modulo.
     * Used by FedEx Express (12), FedEx Express (34), FedEx Ground GSN.
     */
    private function validatesSumProduct($sequence, $checkDigit, $extras)
    {
        $weightings = $extras['weightings'] ?? [];
        $modulo1 = $extras['modulo1'];
        $modulo2 = $extras['modulo2'];

        $total = 0;
        $chars = str_split($sequence);

        for ($i = 0; $i < count($weightings) && $i < count($chars); $i++) {
            $total += intval($chars[$i]) * $weightings[$i];
        }

        return ($total % $modulo1 % $modulo2) === intval($checkDigit);
    }

    /**
     * Mod 37/36 checksum. Used by DPD.
     * Check digit can be 0-9 or A-Z.
     */
    private function validatesMod3736($sequence, $checkDigit)
    {
        $mod = 36;
        $weights = [
            'A' => 10, 'B' => 11, 'C' => 12, 'D' => 13, 'E' => 14, 'F' => 15,
            'G' => 16, 'H' => 17, 'I' => 18, 'J' => 19, 'K' => 20, 'L' => 21,
            'M' => 22, 'N' => 23, 'O' => 24, 'P' => 25, 'Q' => 26, 'R' => 27,
            'S' => 28, 'T' => 29, 'U' => 30, 'V' => 31, 'W' => 32, 'X' => 33,
            'Y' => 34, 'Z' => 35,
        ];

        $cd = $mod;
        $chars = str_split($sequence);

        foreach ($chars as $char) {
            if (ctype_alpha($char)) {
                $val = $weights[strtoupper($char)];
            } else {
                $val = intval($char);
            }

            $cd = $val + $cd;
            if ($cd > $mod) {
                $cd -= $mod;
            }
            $cd *= 2;
            if ($cd > $mod) {
                $cd -= ($mod + 1);
            }
        }

        $cd = ($mod + 1) - $cd;
        if ($cd === $mod) {
            $cd = 0;
        }

        if ($cd >= 10) {
            $computed = array_search($cd, $weights);
            if ($computed === false) {
                return false;
            }
        } else {
            $computed = (string)$cd;
        }

        return $computed === $checkDigit;
    }

    /**
     * Luhn checksum algorithm. Used by Old Dominion.
     * Doubles even-indexed digits (from right, 0-indexed after reversal).
     */
    private function validatesLuhn($sequence, $checkDigit)
    {
        $total = 0;
        $chars = array_reverse(str_split($sequence));

        foreach ($chars as $i => $c) {
            $x = intval($c);

            if ($i % 2 === 0) {
                $x *= 2;
            }

            if ($x > 9) {
                $x -= 9;
            }

            $total += $x;
        }

        $check = $total % 10;
        if ($check !== 0) {
            $check = 10 - $check;
        }

        return $check === intval($checkDigit);
    }
}

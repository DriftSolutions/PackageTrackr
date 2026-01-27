<?php
require_once 'config.php';

/**
 * Send email content to Claude API for package name extraction
 *
 * @param string $api_key User's Claude API key
 * @param string $email_subject Email subject line
 * @param string $email_body Email body content
 * @return array ['success' => bool, 'package_name' => string|null, 'error' => string, 'ignored' => bool]
 */
function analyzeEmailWithClaude($api_key, $email_subject, $email_body) {
    $url = 'https://api.anthropic.com/v1/messages';

    // Build the prompt for Claude
    $prompt = buildClaudePrompt($email_subject, $email_body);

    $data = [
        'model' => 'claude-haiku-4-5-20251001',
        'max_tokens' => 200, // Short response needed
        'messages' => [
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ]
    ];

    $headers = [
        'Content-Type: application/json',
        'x-api-key: ' . $api_key,
        'anthropic-version: 2023-06-01'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return [
            'success' => false,
            'error' => 'cURL error: ' . $curlError,
            'package_name' => null,
            'ignored' => false
        ];
    }

    if ($httpCode !== 200) {
        return [
            'success' => false,
            'error' => "Claude API returned HTTP $httpCode: " . substr($response, 0, 200),
            'package_name' => null,
            'ignored' => false
        ];
    }

    $responseData = json_decode($response, true);

    if (!$responseData || !isset($responseData['content'][0]['text'])) {
        return [
            'success' => false,
            'error' => 'Invalid response from Claude API',
            'package_name' => null,
            'ignored' => false
        ];
    }

    $claudeResponse = trim($responseData['content'][0]['text']);

    // Parse Claude's response
    return parseClaudeResponse($claudeResponse);
}

/**
 * Build the prompt for Claude to analyze email content
 *
 * @param string $email_subject Email subject line
 * @param string $email_body Email body content
 * @return string Formatted prompt
 */
function buildClaudePrompt($email_subject, $email_body) {
    // Escape and limit email content to prevent prompt injection and token limits
    $email_subject = substr($email_subject, 0, 500);
    $email_body = substr($email_body, 0, 10000); // Limit to ~3000 tokens max

    return <<<PROMPT
Analyze this shipping notification email and extract a concise package name following these EXACT rules:

RULES:
1. If 1-2 specific items are mentioned, use format: "Sender: item(s)"
   Example: "Amazon: iPhone Case" or "Best Buy: Laptop, Mouse"

2. If no specific items but there's an order number, use format: "Sender: Order #number"
   Example: "Walmart: Order #12345"

3. If neither items nor order number, use just: "Sender"
   Example: "Etsy Shop Name"

4. For emails from UPS/FedEx/USPS: Extract the ACTUAL merchant/sender mentioned in the email, NOT the shipping company
   Example: If FedEx email says "shipment from Apple", use "Apple" not "FedEx"

5. If you cannot determine sender, items, or order number with confidence, respond with exactly: "IGNORE"

6. Keep response concise (under 50 characters if possible)

EMAIL SUBJECT:
$email_subject

EMAIL BODY:
$email_body

RESPOND WITH ONLY THE PACKAGE NAME OR "IGNORE" - NO EXPLANATION:
PROMPT;
}

/**
 * Parse Claude's response to extract package name
 *
 * @param string $response Claude API response text
 * @return array ['success' => bool, 'package_name' => string|null, 'ignored' => bool]
 */
function parseClaudeResponse($response) {
    $response = trim($response);

    // If Claude says to ignore
    if (strtoupper($response) === 'IGNORE') {
        return [
            'success' => true,
            'package_name' => null,
            'ignored' => true,
            'error' => null
        ];
    }

    // Truncate if too long (database field is VARCHAR(255))
    if (strlen($response) > 255) {
        $response = substr($response, 0, 252) . '...';
    }

    return [
        'success' => true,
        'package_name' => $response,
        'ignored' => false,
        'error' => null
    ];
}

<?php
/**
 * Cron job to monitor IMAP inbox for tracking numbers
 * Run every 15 minutes
 * Crontab: * /15 * * * * /usr/bin/php /var/www/html/cron_imap_monitor.php >> /var/www/html/logs/imap_monitor.log 2>&1
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tracking_api.php';
require_once __DIR__ . '/includes/carriers/CarrierRegistry.php';

// Ensure this script only runs from command line/cron, not from browser
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This script can only be run from the command line.";
    exit(1);
}

// Create logs directory if it doesn't exist
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$logFile = $logDir . '/imap_monitor.log';

function logMessage($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$message}\n";
    echo $logEntry;
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

logMessage("=== Starting IMAP monitoring ===");

// Get IMAP settings
$imapServer = getSetting('imap_server');
$imapPort = getSetting('imap_port', '993');
$imapEmail = getSetting('imap_email');
$imapPassword = getSetting('imap_password');
$imapFolder = getSetting('imap_folder', 'INBOX');

// Validate settings
if (!$imapServer || !$imapEmail || !$imapPassword ||
    $imapEmail === 'your-email@gmail.com' || $imapPassword === 'your-app-password') {
    logMessage("IMAP settings not configured. Exiting.");
    exit(0);
}

// Check if IMAP extension is available
if (!function_exists('imap_open')) {
    logMessage("ERROR: PHP IMAP extension is not installed. Please install it with: apt-get install php-imap");
    exit(1);
}

// Connect to IMAP server
$mailbox = "{" . $imapServer . ":" . $imapPort . "/imap/ssl/novalidate-cert}" . $imapFolder;
logMessage("Connecting to: {$mailbox}");

$imap = @imap_open($mailbox, $imapEmail, $imapPassword);

if (!$imap) {
    logMessage("ERROR: Failed to connect to IMAP server: " . imap_last_error());
    exit(1);
}

logMessage("Successfully connected to IMAP server");

// Search for unread emails
$emails = imap_search($imap, 'UNSEEN');
//$emails = imap_search($imap, 'ALL');

if (!$emails) {
    logMessage("No unread emails found");
    imap_close($imap);
    exit(0);
}

logMessage("Found " . count($emails) . " unread email(s)");

$addedCount = 0;
$skippedCount = 0;
$domain = substr(TRACKING_EMAIL, strpos(TRACKING_EMAIL, '@') + 1);

foreach ($emails as $emailNum) {
    $header = imap_headerinfo($imap, $emailNum);
    $subject = isset($header->subject) ? $header->subject : '';
	if (strncasecmp($subject, 'FW:', 3) == 0) {
		$subject = trim(substr($subject, 3));
	} else if (strncasecmp($subject, 'FWD:', 4) == 0) {
		$subject = trim(substr($subject, 4));
	}
	$from_fedex = false;

    // Extract From address to determine which user this tracking number belongs to
    $fromAddress = '';
    if (isset($header->from) && is_array($header->from)) {
        foreach ($header->from as $from) {
            if (isset($from->mailbox) && isset($from->host)) {
                $fromAddress = $from->mailbox . '@' . $from->host;
                break;
            }
        }
    }
	if (isset($header->in_reply_to)) {
		$from_fedex = (strstr($header->in_reply_to, 'fedex.com') !== FALSE);
	}

    logMessage("Processing email #$emailNum: From: '$fromAddress' Subject: '$subject'");

    // Look up user by email address
    $user = getUserByEmail($fromAddress);
    if (!$user) {
        logMessage("  No user found with email: $fromAddress");
        $skippedCount++;
        // Mark as read and delete
        imap_setflag_full($imap, $emailNum, "\\Seen");
        imap_delete($imap, $emailNum);
        continue;
    }

    $userId = $user['id'];
    logMessage("  Found user: " . $user['email'] . " (ID: $userId)");

    // Get the email body, properly handling MIME structure
    $body = getEmailBody($imap, $emailNum);

    // Extract tracking numbers from email body
    $trackingNumbers = extractTrackingNumbers($subject."\n".$body);

    if (empty($trackingNumbers)) {
        logMessage("  No tracking numbers found in email");
        //logMessage("  (Email body preview: " . substr($body, 0, 150) . "...)");
        logMessage("  (Email body: " . $body . "...)");
        send_email($fromAddress, 'No Tracking Numbers Found', "No tracking numbers found in email: $subject");
        $skippedCount++;
        // Mark as read and delete
        imap_setflag_full($imap, $emailNum, "\\Seen");
        imap_delete($imap, $emailNum);
        continue;
    }

    logMessage("  Found " . count($trackingNumbers) . " tracking number(s)");

    // Add each tracking number
	$addedmsg = '';
	$existsmsg = '';
	$newlyAdded = 0;
    foreach ($trackingNumbers as $trackingInfo) {
        $carrier = $trackingInfo['carrier'];
        $trackingNumber = $trackingInfo['number'];

        logMessage("    Adding {$carrier} tracking: {$trackingNumber}");

	$addsubject = $subject;
	if ($from_fedex && stristr($subject, 'Your shipment is on the way') !== FALSE) {
		$company = '';
		$n = stripos($body, '>Your shipment from');
		$n2 = stripos($body, 'is on the way', (int)$n);
		if ($n !== FALSE && $n2 !== FALSE) {
			$n += 19;
			$company = ucwords(strtolower(trim(substr($body, $n, min(100, $n2 - $n)))));
			if ($company == 'Lowe\'s Companies, Inc.') {
				$company = 'Lowe\'s';
			}
			$addsubject = 'FedEx: '.$company;
		}
//		file_put_contents('logs/ups.txt', $body);
		$n = stripos($body, '>We have a scheduled delivery date for your shipment from');
		$n2 = stripos($body, '.</p>', (int)$n);
		if (empty($company) && $n !== FALSE && $n2 !== FALSE) {
			$n += 57;
			$company = ucwords(strtolower(trim(substr($body, $n, min(100, $n2 - $n)))));
			if ($company == 'Lowe\'s Companies, Inc.') {
				$company = 'Lowe\'s';
			}
			$addsubject = 'FedEx: '.$company;
		}
	}
	if (stristr($subject, 'UPS Update:') !== FALSE) {
		$n = stripos($body, 'From <strong>');
		$n2 = strpos($body, '</strong>', (int)$n);
		if ($n !== FALSE && $n2 !== FALSE) {
			$n += 13;
			$addsubject = 'UPS: '.ucwords(strtolower(trim(substr($body, $n, min(100, $n2 - $n)))));
		} else if (stristr($body, 'Amazon.com') !== FALSE) {
			$addsubject = 'UPS: Amazon.com';
		}
	}

        // Extract domain from TRACKING_EMAIL for link
        $trackingLink = "https://{$domain}/?highlight=" . urlencode($trackingNumber);

        $result = addTrackingNumber($userId, $trackingNumber, $carrier, $addsubject);

        if ($result['success']) {
            $trackingNumberId = $result['id']; // Store ID before it gets overwritten
            logMessage("    Registering with 17track...");
            $registerResult = register17TrackNumber($userId, $trackingNumber, $carrier);
            logMessage("    ✓ Successfully added");
            $addedCount++;
            $newlyAdded++;

            $addedmsg .= "{$carrier}: {$trackingNumber} - {$trackingLink}\n\n";

            // Handle Amazon email status updates
            if ($carrier === 'Amazon') {
                $amazonData = getAmazonStatusFromSubject($subject);
                if ($amazonData) {
                    updateTrackingNumber($userId, $trackingNumberId, $amazonData);
                    logMessage("    Set Amazon status to: {$amazonData['status']}");
                }
            }

            // If user has Claude API key, queue email for AI analysis
            $claudeApiKey = getUserSetting($userId, 'claude_api_key', '');
            if (!empty($claudeApiKey)) {
                addPendingClaudeAnalysis($trackingNumberId, $userId, $subject, $body);
                logMessage("    Queued for Claude AI analysis");
            }
        } else {
            // Check if the error is about the tracking number already existing
            if (stristr($result['error'], 'already exists') !== FALSE) {
                logMessage("    ℹ Already exists");
	        $existsmsg .= "{$carrier}: {$trackingNumber} - {$trackingLink}\n\n";

                // Handle Amazon email status updates for existing tracking numbers
                if ($carrier === 'Amazon' && isset($result['id'])) {
                    $amazonData = getAmazonStatusFromSubject($subject);
                    if ($amazonData) {
                        updateTrackingNumber($userId, $result['id'], $amazonData);
                        logMessage("    Updated Amazon status to: {$amazonData['status']}");
                    }
                }
            } else {
                logMessage("    ✗ " . $result['error']);
                $addedmsg .= "{$carrier}: {$trackingNumber} - Error: " . $result['error'] . "\n";
            }
        }
    }

    // Only send email if new tracking numbers were added
	if ($newlyAdded > 0) {
		$prefix = "Found " . $newlyAdded . " new tracking number(s) in email " . $subject . ":\n\n";
		send_email($fromAddress, 'Tracking Number(s) Added', $prefix . $addedmsg);
		logMessage("  Sent notification email to $fromAddress");
	} else if (!empty($existsmsg)) {
		$prefix = "Found already existing tracking number(s) in email " . $subject . ":\n\n";
		send_email($fromAddress, 'Found Existing Tracking Number(s)', $prefix . $existsmsg);
		logMessage("  Sent notification email to $fromAddress");
	}

    // Mark as read and delete the email
    imap_setflag_full($imap, $emailNum, "\\Seen");
    imap_delete($imap, $emailNum);
    logMessage("  Email marked for deletion");
}

// Expunge deleted messages
imap_expunge($imap);
imap_close($imap);

logMessage("=== IMAP monitoring complete ===");
logMessage("Tracking numbers added: {$addedCount}");
logMessage("Emails processed: " . count($emails));
logMessage("");

/**
 * Get email body, properly handling MIME structure
 */
function getEmailBody($imap, $emailNum) {
    // Get the email structure
    $structure = imap_fetchstructure($imap, $emailNum);

    // Initialize body
    $body = '';

    // Handle different email structures
    if (!isset($structure->parts)) {
        // Simple email without parts
        $body = imap_body($imap, $emailNum);
    } else {
        // Multipart email - extract text/plain part first, then text/html
        foreach ($structure->parts as $partNum => $part) {
            $partNum = $partNum + 1; // imap_fetchbody uses 1-based indexing

            if ($part->type == 0) {
                // text/plain
                $body = imap_fetchbody($imap, $emailNum, $partNum);

                // Decode based on encoding
                if ($part->encoding == 3) {
                    $body = imap_base64($body);
                } elseif ($part->encoding == 4) {
                    $body = imap_qprint($body);
                }

                // If we found text/plain, use it and stop
                if (!empty($body)) {
                    break;
                }
            } elseif ($part->type == 1 && isset($part->parts)) {
                // multipart/alternative - recursively extract text/plain from subparts
                foreach ($part->parts as $subPartNum => $subPart) {
                    $subPartNum = $subPartNum + 1;
                    $fullPartNum = $partNum . '.' . $subPartNum;

                    if ($subPart->type == 0) {
                        // text/plain subpart
                        $body = imap_fetchbody($imap, $emailNum, $fullPartNum);

                        // Decode based on encoding
                        if ($subPart->encoding == 3) {
                            $body = imap_base64($body);
                        } elseif ($subPart->encoding == 4) {
                            $body = imap_qprint($body);
                        }

                        if (!empty($body)) {
                            break 2; // Break out of both loops
                        }
                    }
                }
            }
        }
    }

    return $body;
}

/**
 * Extract tracking numbers from email body
 */
function extractTrackingNumbers($text) {
    return CarrierRegistry::getInstance()->extractTrackingNumbers($text);
}

/**
 * Determine Amazon order status and related fields from email subject line
 * Returns array with status, raw_status, and date fields as appropriate
 */
function getAmazonStatusFromSubject($subject) {
    // Strip common email forwarding/reply prefixes (can appear multiple times)
    // Handles: Fwd:, Fw:, FW:, Forward:, Forwarded:, Re:, etc.
    $subject = preg_replace('/^(\s*(fwd|fw|forward|forwarded|re)\s*:\s*)+/i', '', $subject);

    $today = date('Y-m-d');

    if (stripos($subject, 'Ordered:') === 0) {
        return [
            'status' => 'Information Received',
            'raw_status' => 'InfoReceived'
        ];
    }
    if (stripos($subject, 'Shipped:') === 0) {
        return [
            'status' => 'In Transit',
            'raw_status' => 'InTransit'
        ];
    }
    if (stripos($subject, 'Delivery update:') === 0) {
        return [
            'status' => 'In Transit',
            'raw_status' => 'InTransit'
        ];
    }
    if (stripos($subject, 'Now arriving today:') === 0 || stripos($subject, 'Out for delivery:') === 0) {
        return [
            'status' => 'Out for Delivery',
            'raw_status' => 'OutForDelivery',
            'estimated_delivery_date' => $today
        ];
    }
    if (stripos($subject, 'Delivered:') === 0) {
        return [
            'status' => 'Delivered',
            'raw_status' => 'Delivered',
            'delivered_date' => $today,
            'is_permanent_status' => 1
        ];
    }
    return null;
}

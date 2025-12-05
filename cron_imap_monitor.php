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
    $trackingNumbers = extractTrackingNumbers($body);

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
	$newlyAdded = 0;
    foreach ($trackingNumbers as $trackingInfo) {
        $carrier = $trackingInfo['carrier'];
        $trackingNumber = $trackingInfo['number'];

        logMessage("    Adding {$carrier} tracking: {$trackingNumber}");

	$addsubject = $subject;
	if ($from_fedex && stristr($subject, 'Your shipment is on the way') !== FALSE) {
		$n = stripos($body, '>Your shipment from');
		$n2 = strpos($body, 'is on the way', (int)$n);
		if ($n !== FALSE && $n2 !== FALSE) {
			$n += 19;
			$company = ucwords(strtolower(trim(substr($body, $n, min(100, $n2 - $n)))));
			if ($company == 'Lowe\'s Companies, Inc.') {
				$company = 'Lowe\'s';
			}
			$addsubject = 'FedEx: '.$company;
//			print "subject: $addsubject\n";
//		} else if (stristr($body, 'Amazon.com') !== FALSE) {
//			$addsubject = 'FedEx: Amazon.com';
		}
//		file_put_contents('logs/ups.txt', $body);
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

        $result = addTrackingNumber($userId, $trackingNumber, $carrier, $addsubject);

        if ($result['success']) {
            logMessage("    Registering with 17track...");
            $result = register17TrackNumber($userId, $trackingNumber, $carrier);
            logMessage("    ✓ Successfully added");
            $addedCount++;
            $newlyAdded++;
            $addedmsg .= "{$carrier}: {$trackingNumber}\n";
        } else {
            // Check if the error is about the tracking number already existing
            if (stristr($result['error'], 'already exists') !== FALSE) {
                logMessage("    ℹ Already exists");
                // Don't add to message for already existing tracking numbers
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
    $trackingNumbers = [];

    // Handle HTML entities and decode them (converts &lt; to <, &gt; to >, etc.)
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);

    // Don't remove HTML tags - tracking numbers may be inside <a> tags or other HTML elements
    // Just normalize whitespace and leave the content intact

    // Normalize whitespace
    $text = preg_replace('/\s+/', ' ', $text);

    // UPS pattern: 1Z followed by 16 alphanumeric characters
    preg_match_all('/\b(1Z[A-Z0-9]{16})\b/i', $text, $upsMatches);
    foreach ($upsMatches[1] as $match) {
        $trackingNumbers[] = [
            'carrier' => 'UPS',
            'number' => strtoupper($match)
        ];
    }

    // USPS patterns
    // 20-22 digit format
    preg_match_all('/\b((?:94|93|92|95)[0-9]{20})\b/', $text, $uspsMatches1);
    foreach ($uspsMatches1[1] as $match) {
        $trackingNumbers[] = [
            'carrier' => 'USPS',
            'number' => $match
        ];
    }

    // 13-15 digit format
    preg_match_all('/\b((?:70|14|23|03)[0-9]{14})\b/', $text, $uspsMatches2);
    foreach ($uspsMatches2[1] as $match) {
        $trackingNumbers[] = [
            'carrier' => 'USPS',
            'number' => $match
        ];
    }

    // Letter format
    preg_match_all('/\b([A-Z]{2}[0-9]{9}[A-Z]{2})\b/', $text, $uspsMatches3);
    foreach ($uspsMatches3[1] as $match) {
        $trackingNumbers[] = [
            'carrier' => 'USPS',
            'number' => $match
        ];
    }

    // FedEx patterns - more specific to avoid false matches
    // 22 digits starting with 96 (most specific)
    preg_match_all('/\b(96[0-9]{20})\b/', $text, $fedexMatches3);
    foreach ($fedexMatches3[1] as $match) {
        $trackingNumbers[] = [
            'carrier' => 'FedEx',
            'number' => $match
        ];
    }

    // 15 digits (check this before 12-digit to prefer longer matches)
    preg_match_all('/\b([0-9]{15})\b/', $text, $fedexMatches2);
    foreach ($fedexMatches2[1] as $match) {
        $trackingNumbers[] = [
            'carrier' => 'FedEx',
            'number' => $match
        ];
    }

    // 12 digits - most generic, so check last to avoid false positives
    preg_match_all('/\b([0-9]{12})\b/', $text, $fedexMatches1);
    foreach ($fedexMatches1[1] as $match) {
        // Skip if already matched as USPS (patterns: 70, 14, 23, 03, 94, 93, 92, 95)
        if (!preg_match('/^(70|14|23|03|94|93|92|95)/', $match)) {
            $trackingNumbers[] = [
                'carrier' => 'FedEx',
                'number' => $match
            ];
        }
    }

    // Remove duplicates and return
    $unique = [];
    foreach ($trackingNumbers as $tracking) {
        $key = $tracking['carrier'] . '-' . $tracking['number'];
        $unique[$key] = $tracking;
    }

    return array_values($unique);
}

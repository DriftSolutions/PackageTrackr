<?php
require_once 'config.php';
require_once 'database.php';

// Start session with secure settings
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => SESSION_TIMEOUT,
        'path' => '/',
        'httponly' => true,
        'secure' => true,
        'samesite' => 'Lax'
    ]);
    session_start();

    // Server-side idle timeout: drop sessions that have been inactive too long
    // (the remember-me check below can still restore the login if the user opted in)
    $idleTimeout = defined('SESSION_IDLE_TIMEOUT') ? SESSION_IDLE_TIMEOUT : 2 * 60 * 60;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $idleTimeout) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_activity'] = time();

    // Check for remember-me token if no active session
    // The cookie contains the raw token; we look it up by its hash in the database.
    if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
        $rawToken = $_COOKIE['remember_token'];
        $tokenHash = hashRememberToken($rawToken);
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT rt.id, rt.user_id, u.email FROM remember_tokens rt
                              JOIN users u ON rt.user_id = u.id
                              WHERE rt.token = ? AND rt.expires_at > NOW()");
        $stmt->execute([$tokenHash]);
        $tokenRecord = $stmt->fetch();

        if ($tokenRecord) {
            // Restore session from remember token (new session ID to prevent fixation)
            session_regenerate_id(true);
            $_SESSION['user_id'] = $tokenRecord['user_id'];
            $_SESSION['email'] = $tokenRecord['email'];
            $_SESSION['login_time'] = time();

            // Update last used time
            $stmt = $pdo->prepare("UPDATE remember_tokens SET last_used_at = NOW() WHERE id = ?");
            $stmt->execute([$tokenRecord['id']]);
        } else {
            // The remember-me cookie is invalid or expired. Remove the (stale) entry
            // from the database and clear the cookie so we don't keep checking it.
            setRememberCookie('', time() - 3600);
            $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE token = ?");
            $stmt->execute([$tokenHash]);
        }
    }
}

/**
 * Hash a remember-me token for database storage.
 * The raw token is only ever held in the HttpOnly cookie; the DB stores the hash.
 */
function hashRememberToken($rawToken) {
    return hash('sha256', $rawToken);
}

/**
 * Set (or clear) the remember-me cookie with the same flags as the session cookie.
 */
function setRememberCookie($value, $expires) {
    setcookie('remember_token', $value, [
        'expires' => $expires,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

/**
 * Hash a password reset token for database storage.
 * The raw token is only ever sent in the reset email link; the DB stores the hash.
 */
function hashPasswordResetToken($rawToken) {
    return hash('sha256', $rawToken);
}

/**
 * Get the CSRF token for the current session, creating it if needed.
 */
function getCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Hidden form field carrying the CSRF token, for use inside <form method="POST">.
 */
function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(getCsrfToken()) . '">';
}

/**
 * Check that the request is a POST carrying a valid CSRF token
 * (as a csrf_token form field or an X-CSRF-Token header).
 */
function isValidCsrfRequest() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['csrf_token'])) {
        return false;
    }
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

// Login throttling limits: failed attempts allowed within LOGIN_ATTEMPT_WINDOW seconds
define('LOGIN_MAX_ATTEMPTS_PER_EMAIL', 5);
define('LOGIN_MAX_ATTEMPTS_PER_IP', 20);
define('LOGIN_ATTEMPT_WINDOW', 15 * 60);

/**
 * Check whether login attempts for this email or client IP are currently throttled.
 */
function isLoginThrottled($email, $ip) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT
                                  SUM(email = ?) AS email_failures,
                                  SUM(ip_address = ?) AS ip_failures
                              FROM login_attempts
                              WHERE attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
                                AND (email = ? OR ip_address = ?)");
        $stmt->execute([$email, $ip, LOGIN_ATTEMPT_WINDOW, $email, $ip]);
        $row = $stmt->fetch();
        return (int)$row['email_failures'] >= LOGIN_MAX_ATTEMPTS_PER_EMAIL
            || (int)$row['ip_failures'] >= LOGIN_MAX_ATTEMPTS_PER_IP;
    } catch (PDOException $e) {
        error_log("Error checking login throttle: " . $e->getMessage());
        return false;
    }
}

/**
 * Record a failed login attempt, and prune entries older than the throttle window.
 */
function recordFailedLogin($email, $ip) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)");
        $stmt->execute([$email, $ip]);
        $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? SECOND)");
        $stmt->execute([LOGIN_ATTEMPT_WINDOW]);
    } catch (PDOException $e) {
        error_log("Error recording failed login: " . $e->getMessage());
    }
}

/**
 * Clear failed login attempts for an email after a successful login.
 */
function clearFailedLogins($email) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE email = ?");
        $stmt->execute([$email]);
    } catch (PDOException $e) {
        error_log("Error clearing failed logins: " . $e->getMessage());
    }
}

/**
 * Delete expired remember-me tokens for a specific user.
 * Called on login for housekeeping so the table doesn't accumulate old entries.
 */
function cleanupExpiredRememberTokens($user_id) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE user_id = ? AND expires_at <= NOW()");
    $stmt->execute([$user_id]);
}

// Check if user is authenticated
function isAuthenticated() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Get current authenticated user ID
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

// Get current authenticated user
function getCurrentUser() {
    $user_id = getCurrentUserId();
    if (!$user_id) {
        return null;
    }
    return getUserById($user_id);
}

// Register a new user
function registerUser($email, $password) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Invalid email address'];
    }

    if (strlen($password) < 8) {
        return ['success' => false, 'error' => 'Password must be at least 8 characters'];
    }

    $pdo = getDbConnection();

    // Check if user already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return ['success' => false, 'error' => 'Email already registered'];
    }

    // Generate verification token
    $verification_token = bin2hex(random_bytes(32));
    $verification_expires = date('Y-m-d H:i:s', time() + VERIFICATION_TOKEN_EXPIRY);
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, verification_token, verification_token_expires)
                              VALUES (?, ?, ?, ?)");
        $stmt->execute([$email, $password_hash, $verification_token, $verification_expires]);
        $user_id = $pdo->lastInsertId();

        return [
            'success' => true,
            'user_id' => $user_id,
            'email' => $email,
            'verification_token' => $verification_token,
            'message' => 'Registration successful. Please verify your email.'
        ];
    } catch (PDOException $e) {
        error_log("Error registering user: " . $e->getMessage());
        return ['success' => false, 'error' => 'Registration failed'];
    }
}

// Verify user email with token
function verifyUserEmail($token) {
    $pdo = getDbConnection();

    $stmt = $pdo->prepare("SELECT id, email FROM users
                          WHERE verification_token = ?
                          AND is_verified = FALSE
                          AND verification_token_expires > NOW()");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'error' => 'Invalid or expired verification token'];
    }

    try {
        $stmt = $pdo->prepare("UPDATE users SET is_verified = TRUE, verification_token = NULL, verification_token_expires = NULL
                              WHERE id = ?");
        $stmt->execute([$user['id']]);

        return [
            'success' => true,
            'user_id' => $user['id'],
            'email' => $user['email'],
            'message' => 'Email verified successfully. You can now log in.'
        ];
    } catch (PDOException $e) {
        error_log("Error verifying email: " . $e->getMessage());
        return ['success' => false, 'error' => 'Verification failed'];
    }
}

// Login user
function loginUser($email, $password, $remember_me = false) {
    $pdo = getDbConnection();

    $throttleEmail = strtolower(trim($email));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (isLoginThrottled($throttleEmail, $ip)) {
        return ['success' => false, 'error' => 'Too many failed login attempts. Please try again in 15 minutes.'];
    }

    $stmt = $pdo->prepare("SELECT id, email, password_hash, is_verified FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        recordFailedLogin($throttleEmail, $ip);
        return ['success' => false, 'error' => 'Invalid email or password'];
    }

    if (!password_verify($password, $user['password_hash'])) {
        recordFailedLogin($throttleEmail, $ip);
        return ['success' => false, 'error' => 'Invalid email or password'];
    }

    if (!$user['is_verified']) {
        return ['success' => false, 'error' => 'Please verify your email before logging in'];
    }

    clearFailedLogins($throttleEmail);

    // Clean up any expired remember-me tokens for this user (housekeeping)
    cleanupExpiredRememberTokens($user['id']);

    // Set session (new session ID and CSRF token to prevent fixation)
    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['login_time'] = time();

    // Generate and store remember-me token if requested (30 days)
    // We store only a SHA-256 hash of the token in the database. The raw value lives only in the cookie.
    if ($remember_me) {
        $remember_token = bin2hex(random_bytes(32)); // raw value for cookie only
        $tokenHash = hashRememberToken($remember_token);
        $expires_at = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60));
        $device_info = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 255);

        $stmt = $pdo->prepare("INSERT INTO remember_tokens (user_id, token, device_info, expires_at)
                              VALUES (?, ?, ?, ?)");
        $stmt->execute([$user['id'], $tokenHash, $device_info, $expires_at]);

        // Set remember-me cookie (30 days) — raw token only
        setRememberCookie($remember_token, time() + (30 * 24 * 60 * 60));
    }

    return [
        'success' => true,
        'user_id' => $user['id'],
        'email' => $user['email'],
        'message' => 'Login successful'
    ];
}

// Logout user
function logoutUser() {
    // Clear remember-me token from database if user is logged in
    // Cookie holds raw token; DB stores the hash.
    if (isset($_COOKIE['remember_token'])) {
        $pdo = getDbConnection();
        $tokenHash = hashRememberToken($_COOKIE['remember_token']);
        $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE token = ?");
        $stmt->execute([$tokenHash]);
    }

    // Clear remember-me cookie
    setRememberCookie('', time() - 3600);

    // Destroy session
    session_destroy();
    return true;
}

// Get user by ID from database
function getUserById($user_id) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT id, email, is_verified, created_at, theme FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch();
}

// Get user by email address
function getUserByEmail($email) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT id, email, is_verified, created_at FROM users WHERE email = ?");
    $stmt->execute([$email]);
    return $stmt->fetch();
}

// Request password reset - sends link to user's email
function requestPasswordReset($email) {
    $pdo = getDbConnection();

    // Check if user exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        // Don't reveal if email exists for security
        return ['success' => true, 'message' => 'If an account exists with this email, a reset link has been sent'];
    }

    // Generate reset token (raw value goes in the email link; DB stores only its hash)
    $reset_token = bin2hex(random_bytes(32));
    $reset_expires = date('Y-m-d H:i:s', time() + PASSWORD_RESET_TOKEN_EXPIRY);

    try {
        $stmt = $pdo->prepare("UPDATE users SET password_reset_token = ?, password_reset_token_expires = ? WHERE id = ?");
        $stmt->execute([hashPasswordResetToken($reset_token), $reset_expires, $user['id']]);

        return [
            'success' => true,
            'user_id' => $user['id'],
            'email' => $email,
            'reset_token' => $reset_token,
            'message' => 'If an account exists with this email, a reset link has been sent'
        ];
    } catch (PDOException $e) {
        error_log("Error requesting password reset: " . $e->getMessage());
        return ['success' => false, 'error' => 'An error occurred'];
    }
}

// Verify password reset token and reset password
function resetPassword($token, $newPassword) {
    if (strlen($newPassword) < 8) {
        return ['success' => false, 'error' => 'Password must be at least 8 characters'];
    }

    $pdo = getDbConnection();

    $stmt = $pdo->prepare("SELECT id, email FROM users
                          WHERE password_reset_token = ?
                          AND password_reset_token_expires > NOW()");
    $stmt->execute([hashPasswordResetToken($token)]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'error' => 'Invalid or expired reset token'];
    }

    $password_hash = password_hash($newPassword, PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, password_reset_token = NULL, password_reset_token_expires = NULL
                              WHERE id = ?");
        $stmt->execute([$password_hash, $user['id']]);

        // On password reset, invalidate *all* remember-me tokens for this user (security best practice)
        $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
        $stmt->execute([$user['id']]);

        return [
            'success' => true,
            'user_id' => $user['id'],
            'email' => $user['email'],
            'message' => 'Password reset successfully. You can now log in.'
        ];
    } catch (PDOException $e) {
        error_log("Error resetting password: " . $e->getMessage());
        return ['success' => false, 'error' => 'Password reset failed'];
    }
}

// Require authentication - redirect to login if not authenticated
function requireAuth() {
    if (!isAuthenticated()) {
        header('Location: login.php');
        exit;
    }
}

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

    // Check for remember-me token if no active session
    if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
        $token = $_COOKIE['remember_token'];
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT rt.id, rt.user_id, u.email FROM remember_tokens rt
                              JOIN users u ON rt.user_id = u.id
                              WHERE rt.token = ? AND rt.expires_at > NOW()");
        $stmt->execute([$token]);
        $tokenRecord = $stmt->fetch();

        if ($tokenRecord) {
            // Restore session from remember token
            $_SESSION['user_id'] = $tokenRecord['user_id'];
            $_SESSION['email'] = $tokenRecord['email'];
            $_SESSION['login_time'] = time();

            // Update last used time
            $stmt = $pdo->prepare("UPDATE remember_tokens SET last_used_at = NOW() WHERE id = ?");
            $stmt->execute([$tokenRecord['id']]);
        }
    }
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

    $stmt = $pdo->prepare("SELECT id, email, password_hash, is_verified FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'error' => 'Invalid email or password'];
    }

    if (!$user['is_verified']) {
        return ['success' => false, 'error' => 'Please verify your email before logging in'];
    }

    if (!password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'error' => 'Invalid email or password'];
    }

    // Set session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['login_time'] = time();

    // Generate and store remember-me token if requested (30 days)
    if ($remember_me) {
        $remember_token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60));
        $device_info = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 255);

        $stmt = $pdo->prepare("INSERT INTO remember_tokens (user_id, token, device_info, expires_at)
                              VALUES (?, ?, ?, ?)");
        $stmt->execute([$user['id'], $remember_token, $device_info, $expires_at]);

        // Set remember-me cookie (30 days)
        setcookie('remember_token', $remember_token, time() + (30 * 24 * 60 * 60), '/', '', true, true);
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
    if (isset($_COOKIE['remember_token'])) {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE token = ?");
        $stmt->execute([$_COOKIE['remember_token']]);
    }

    // Clear remember-me cookie
    setcookie('remember_token', '', time() - 3600, '/', '', true, true);

    // Destroy session
    session_destroy();
    return true;
}

// Get user by ID from database
function getUserById($user_id) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT id, email, is_verified, created_at FROM users WHERE id = ?");
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

    // Generate reset token
    $reset_token = bin2hex(random_bytes(32));
    $reset_expires = date('Y-m-d H:i:s', time() + PASSWORD_RESET_TOKEN_EXPIRY);

    try {
        $stmt = $pdo->prepare("UPDATE users SET password_reset_token = ?, password_reset_token_expires = ? WHERE id = ?");
        $stmt->execute([$reset_token, $reset_expires, $user['id']]);

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
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'error' => 'Invalid or expired reset token'];
    }

    $password_hash = password_hash($newPassword, PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, password_reset_token = NULL, password_reset_token_expires = NULL
                              WHERE id = ?");
        $stmt->execute([$password_hash, $user['id']]);

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

<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

// If already logged in, redirect to home
if (isAuthenticated()) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';
$token = $_GET['token'] ?? '';
$token_valid = false;

// Verify token on page load
if ($token) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT id FROM users WHERE password_reset_token = ? AND password_reset_token_expires > NOW()");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        $token_valid = true;
    } else {
        $error = 'Invalid or expired reset token. Please request a new password reset.';
    }
}

// Handle password reset form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token_valid) {
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    if (empty($password) || empty($password_confirm)) {
        $error = 'Both password fields are required';
    } elseif ($password !== $password_confirm) {
        $error = 'Passwords do not match';
    } else {
        // Reset password
        $result = resetPassword($token, $password);

        if ($result['success']) {
            $success = 'Password reset successfully! You can now log in with your new password.';
            $token_valid = false; // Hide the form after success
        } else {
            $error = $result['error'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - <?= SITE_NAME ?></title>
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .auth-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 400px;
            padding: 40px;
        }
        .auth-container h1 {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 10px;
            color: #333;
        }
        .auth-container .subtitle {
            font-size: 14px;
            color: #999;
            margin-bottom: 30px;
        }
        .form-control {
            padding: 12px;
            font-size: 14px;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .btn-submit {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 12px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 5px;
            width: 100%;
            margin-top: 10px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            color: white;
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        .alert {
            margin-bottom: 20px;
            border-radius: 5px;
        }
        .login-link {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
        }
        .login-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        .login-link a:hover {
            text-decoration: underline;
        }
        .form-label {
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 8px;
            color: #333;
        }
        .success-message {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <h1><i class="bi bi-key"></i> Reset Password</h1>
        <p class="subtitle">Create a new password for your account</p>

        <?php if ($success): ?>
            <div class="success-message">
                <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert">
                <i class="bi bi-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($token_valid): ?>
            <form method="POST">
                <div>
                    <label for="password" class="form-label">New Password</label>
                    <input type="password" id="password" name="password" class="form-control" required
                           minlength="8" placeholder="At least 8 characters">
                </div>

                <div>
                    <label for="password_confirm" class="form-label">Confirm Password</label>
                    <input type="password" id="password_confirm" name="password_confirm" class="form-control" required
                           minlength="8">
                </div>

                <button type="submit" class="btn-submit">Reset Password</button>

                <div class="login-link">
                    Back to <a href="login.php">Login</a>
                </div>
            </form>
        <?php else: ?>
            <div class="login-link">
                <a href="login.php"><i class="bi bi-arrow-left"></i> Back to Login</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

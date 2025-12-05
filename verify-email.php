<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

$error = '';
$success = '';
$token = $_GET['token'] ?? '';

// If already logged in, redirect to home
if (isAuthenticated()) {
    header('Location: index.php');
    exit;
}

// Verify email if token provided
if ($token) {
    $result = verifyUserEmail($token);
    if ($result['success']) {
        $success = $result['message'];
    } else {
        $error = $result['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification - <?= SITE_NAME ?></title>
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
        .message-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 500px;
            padding: 40px;
            text-align: center;
        }
        .message-container h1 {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #333;
        }
        .success-icon {
            font-size: 60px;
            color: #28a745;
            margin-bottom: 20px;
        }
        .error-icon {
            font-size: 60px;
            color: #dc3545;
            margin-bottom: 20px;
        }
        .message-text {
            font-size: 16px;
            color: #666;
            margin-bottom: 30px;
            line-height: 1.6;
        }
        .btn-link {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 12px 30px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: transform 0.2s;
        }
        .btn-link:hover {
            transform: translateY(-2px);
            color: white;
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
    </style>
</head>
<body>
    <div class="message-container">
        <?php if ($success): ?>
            <div class="success-icon">
                <i class="bi bi-check-circle"></i>
            </div>
            <h1>Email Verified!</h1>
            <p class="message-text"><?php echo htmlspecialchars($success); ?></p>
            <a href="login.php" class="btn-link">Go to Login</a>
        <?php elseif ($error): ?>
            <div class="error-icon">
                <i class="bi bi-exclamation-circle"></i>
            </div>
            <h1>Verification Failed</h1>
            <p class="message-text"><?php echo htmlspecialchars($error); ?></p>
            <a href="register.php" class="btn-link">Back to Registration</a>
        <?php else: ?>
            <div class="error-icon">
                <i class="bi bi-exclamation-circle"></i>
            </div>
            <h1>No Token Provided</h1>
            <p class="message-text">Please check your email for the verification link.</p>
            <a href="login.php" class="btn-link">Go to Login</a>
        <?php endif; ?>
    </div>
</body>
</html>

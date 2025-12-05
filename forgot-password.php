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
$email_sent = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $h_captcha_response = $_POST['h-captcha-response'] ?? '';

    // Validate hCaptcha
    if (!$h_captcha_response) {
        $error = 'Please complete the hCaptcha verification';
    } elseif (empty($email)) {
        $error = 'Email address is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address';
    } else {
        // Verify hCaptcha with server
        $verify_url = 'https://hcaptcha.com/siteverify';
        $verify_data = [
            'secret' => HCAPTCHA_SECRET,
            'response' => $h_captcha_response
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $verify_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($verify_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            $error = 'hCaptcha verification failed. Please try again.';
        } else {
            $captcha_result = json_decode($response, true);
            if (!isset($captcha_result['success']) || !$captcha_result['success']) {
                $error = 'hCaptcha verification failed. Please try again.';
            } else {
                // Request password reset
                $result = requestPasswordReset($email);
            }
        }
    }

    if (!$error) {
        // Request password reset
        $result = requestPasswordReset($email);

        // Send email if user exists
        if (isset($result['reset_token']) && isset($result['user_id'])) {
            $reset_link = 'https://' . $_SERVER['HTTP_HOST'] . '/reset-password.php?token=' . $result['reset_token'];
            $email_subject = 'Reset Your Password - ' . SITE_NAME;
            $email_body = "Hello,\n\n";
            $email_body .= "We received a request to reset the password for your " . SITE_NAME . " account.\n\n";
            $email_body .= "Click the link below to reset your password:\n\n";
            $email_body .= $reset_link . "\n\n";
            $email_body .= "This link will expire in 24 hours.\n\n";
            $email_body .= "If you did not request this reset, you can safely ignore this email.\n\n";
            $email_body .= "Best regards,\n" . SITE_NAME . " Team";

            send_email($email, $email_subject, $email_body);
        }

        // Always show the same message for security (don't reveal if email exists)
        $success = 'If an account exists with this email, a password reset link has been sent.';
        $email_sent = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - <?= SITE_NAME ?></title>
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <script src="https://js.hcaptcha.com/1/api.js"></script>
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
        .h-captcha {
            display: flex;
            justify-content: center;
            margin: 15px 0;
        }
        .h-captcha iframe {
            display: block !important;
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <h1><i class="bi bi-key"></i> Reset Password</h1>
        <p class="subtitle">Enter your email to receive a reset link</p>

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

        <?php if (!$email_sent): ?>
            <form method="POST">
                <div>
                    <label for="email" class="form-label">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" required
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>

                <div style="margin-bottom: 15px;">
                    <div class="h-captcha" data-sitekey="<?php echo htmlspecialchars(HCAPTCHA_SITEKEY); ?>"></div>
                </div>

                <button type="submit" class="btn-submit">Send Reset Link</button>

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

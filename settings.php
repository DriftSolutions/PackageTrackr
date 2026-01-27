<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/database.php';

// Require authentication
requireAuth();
$user = getCurrentUser();
$user_id = $user['id'];

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $apiKey = $_POST['17track_api_key'] ?? '';
    $claudeApiKey = $_POST['claude_api_key'] ?? '';

    if (empty($apiKey)) {
        $error = '17track Security Key is required';
    } else {
        // Save 17track API key
        $setApiKey = setUserSetting($user_id, '17track_api_key', $apiKey);

        // Save Claude API key (can be empty)
        $setClaudeKey = setUserSetting($user_id, 'claude_api_key', $claudeApiKey);

        if ($setApiKey && $setClaudeKey) {
            $success = 'Settings saved successfully';
        } else {
            $error = 'Failed to save settings';
        }
    }
}

// Load current settings
$apiKey = getUserSetting($user_id, '17track_api_key', '');
$claudeApiKey = getUserSetting($user_id, 'claude_api_key', '');
$secret = getUserSetting($user_id, '17track_secret', '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - <?= SITE_NAME ?></title>
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link id="theme-link" href="https://cdnjs.cloudflare.com/ajax/libs/bootswatch/5.3.8/cosmo/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        .settings-container {
            max-width: 600px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .settings-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 30px;
            margin-bottom: 20px;
        }
        .settings-card h2 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 10px;
            color: #333;
        }
        .settings-card p.subtitle {
            color: #999;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }
        .form-control {
            padding: 12px;
            font-size: 14px;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .form-text {
            font-size: 12px;
            color: #999;
            margin-top: -10px;
            margin-bottom: 15px;
        }
        .btn-save {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 12px 30px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 5px;
            cursor: pointer;
            width: 100%;
            transition: transform 0.2s;
        }
        .btn-save:hover {
            transform: translateY(-2px);
            color: white;
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        .alert {
            margin-bottom: 20px;
            border-radius: 5px;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body style="background-color: #f8f9fa;">
    <div class="settings-container">
        <a href="/" class="back-link"><i class="bi bi-arrow-left"></i> Back to Tracking</a>

        <div class="settings-card">
            <h2><i class="bi bi-gear"></i> Settings</h2>
            <p class="subtitle">We use the 17track API to track packages, you will need to create an account with them to get an API key to track your packages. They allow up to 100 packages per month for free and you can find your security key <a href="https://admin.17track.net/api/settings">here</a>. Set the WebHook URL to <code>https://<?= htmlspecialchars($_SERVER['HTTP_HOST']) ?>/webhook.php</code> and version to <code>V 2.4</code>. Set up filters in your email client to forward shipping emails to <code><?= htmlspecialchars(TRACKING_EMAIL) ?></code> if you want them to be added automatically.</p>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div>
                    <label for="apiKey" class="form-label">17track Security Key</label>
                    <input type="password" id="apiKey" name="17track_api_key" class="form-control" required
                           value="<?php echo htmlspecialchars($apiKey); ?>">
                </div>

                <div>
                    <label for="claudeApiKey" class="form-label">
                        Claude API Key (Optional)
                        <small class="text-muted">- For AI-powered package name generation</small>
                    </label>
                    <input type="password" id="claudeApiKey" name="claude_api_key" class="form-control"
                           value="<?php echo htmlspecialchars($claudeApiKey); ?>"
                           placeholder="sk-ant-...">
                    <div class="form-text">
                        Get your API key from <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a>.
                        When set, new tracking numbers will automatically get descriptive names based on email content.
                        Costs approximately $1 per 1,000 packages analyzed.
                    </div>
                </div>

                <button type="submit" class="btn-save">Save Settings</button>
            </form>
        </div>

        <div class="settings-card">
            <h3>Account Information</h3>
            <p>
                <strong>Email:</strong> <?php echo htmlspecialchars($user['email']); ?><br>
                <strong>Account Status:</strong> <span class="badge bg-success">Verified</span><br>
                <strong>Member Since:</strong> <?php echo date('M j, Y', strtotime($user['created_at'])); ?>
            </p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script src="app.js"></script>
</body>
</html>

<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/carriers/CarrierRegistry.php';

$userTheme = 'light';
if (isAuthenticated()) {
    $user = getCurrentUser();
    $userTheme = $user['theme'] ?? 'light';
}

// Get all carriers and sort them
$registry = CarrierRegistry::getInstance();
$carriers = $registry->getAllCarriers();

usort($carriers, function($a, $b) {
    return strcmp($a->getName(), $b->getName());
});
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $userTheme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supported Carriers - <?= SITE_NAME ?></title>
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link id="theme-link" href="https://cdnjs.cloudflare.com/ajax/libs/bootswatch/5.3.8/cosmo/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        .carriers-container {
            max-width: 800px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .carriers-card {
            background: var(--bs-body-bg);
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--bs-border-color);
            padding: 30px;
            margin-bottom: 20px;
        }
        .carriers-card h2 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--bs-body-color);
        }
        .carriers-card p.subtitle {
            color: var(--bs-secondary-color, #999);
            margin-bottom: 30px;
            font-size: 14px;
        }
        .carriers-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0;
        }
        .carrier-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid var(--bs-border-color);
        }
        .carrier-item:nth-last-child(-n+2) {
            border-bottom: none;
        }
        @media (max-width: 576px) {
            .carriers-grid {
                grid-template-columns: 1fr;
            }
            .carrier-item:last-child {
                border-bottom: none;
            }
            .carrier-item:nth-last-child(2) {
                border-bottom: 1px solid var(--bs-border-color);
            }
        }
        .carrier-logo {
            width: 60px;
            height: 40px;
            object-fit: contain;
            margin-right: 20px;
        }
        .carrier-name {
            font-size: 16px;
            font-weight: 500;
            color: var(--bs-body-color);
        }
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: var(--bs-primary);
            text-decoration: none;
            font-weight: 600;
        }
        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="carriers-container">
        <a href="/" class="back-link"><i class="bi bi-arrow-left"></i> Back to Tracking</a>

        <div class="carriers-card">
            <h2><i class="bi bi-truck"></i> Supported Carriers</h2>
            <p class="subtitle">We support tracking packages from the following carriers.</p>

            <div class="carriers-grid">
                <?php foreach ($carriers as $carrier): ?>
                    <div class="carrier-item">
                        <?php if ($carrier->getLogoPath()): ?>
                            <img src="<?= htmlspecialchars($carrier->getLogoPath()) ?>" alt="<?= htmlspecialchars($carrier->getName()) ?>" class="carrier-logo">
                        <?php else: ?>
                            <div class="carrier-logo"></div>
                        <?php endif; ?>
                        <span class="carrier-name"><?= htmlspecialchars($carrier->getName()) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

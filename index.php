<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/database.php';
require_once 'includes/tracking_api.php';

// Require authentication
requireAuth();
$user = getCurrentUser();
$user_id = $user['id'];

// Check if user has 17track API key set
$apiKey = getUserSetting($user_id, '17track_api_key', '');
$hasApiKey = !empty($apiKey);

$currentView = $_GET['view'] ?? 'current';
if (!in_array($currentView, ['current', 'archive', 'trash'])) {
    $currentView = 'current';
}

$statusFilter = $_GET['status'] ?? 'all';

$trackingNumbers = getTrackingNumbers($user_id, $currentView);
// For now, show counts for current user only - update getViewCounts if needed
// This would require modifying the getViewCounts function to accept user_id
$viewCounts = getViewCounts();

// Collect unique statuses from current tracking numbers
$uniqueStatuses = [];
foreach ($trackingNumbers as $tracking) {
    if (!in_array($tracking['status'], $uniqueStatuses)) {
        $uniqueStatuses[] = $tracking['status'];
    }
}

// Filter tracking numbers by status if a status filter is applied
if ($statusFilter !== 'all' && !empty($uniqueStatuses)) {
    $trackingNumbers = array_filter($trackingNumbers, function($tracking) use ($statusFilter) {
        return $tracking['status'] === $statusFilter;
    });
}

// Helper function to get status class from status string
function getStatusClass($status) {
    if (stripos($status, 'Delivered') !== false) {
        return 'delivered';
    } elseif (stripos($status, 'Out for Delivery') !== false ||
              stripos($status, 'In Transit') !== false) {
        return 'in-transit';
    } elseif (stripos($status, 'Information Received') !== false) {
        return 'pending';
    } elseif (stripos($status, 'Error') !== false ||
              stripos($status, 'Exception') !== false) {
        return 'error';
    }
    return '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= SITE_NAME ?></title>
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <!--<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">-->
    <link id="theme-link" href="https://cdnjs.cloudflare.com/ajax/libs/bootswatch/5.3.8/cosmo/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bs-body-color: var(--bs-secondary);
        }

        .navbar-brand {
            font-family: 'IBM Plex Sans', sans-serif;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .navbar-brand img {
            height: 24px;
            width: 24px;
        }

        .view-tabs .nav-link {
            color: var(--bs-secondary)
            border: none;
            border-bottom: 3px solid transparent;
            font-weight: 500;
        }

        .view-tabs .nav-link.active {
            color: var(--bs-primary);
            background-color: transparent;
            border-bottom-color: var(--bs-primary);
        }

        .view-tabs .nav-link:hover {
            border-bottom-color: var(--bs-primary);
            background-color: transparent;
        }

        .badge-count {
            font-size: 0.75rem;
            padding: 0.25em 0.5em;
        }

        .tracking-card {
            transition: box-shadow 0.2s;
            border-left: 4px solid var(--bs-border-color);
        }

        .tracking-card:hover {
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.1);
        }

        .tracking-card.delivered {
            border-left-color: var(--bs-success);
        }

        .tracking-card.in-transit {
            border-left-color: var(--bs-primary);
        }

        .tracking-card.pending {
            border-left-color: var(--bs-warning);
        }

        .tracking-card.error {
            border-left-color: var(--bs-danger);
        }

        .delivery-date.delivered-date {
            color: var(--bs-success);
            font-weight: 600;
        }

        .delivery-date.has-estimate {
            color: var(--bs-primary);
            font-weight: 600;
        }

        .delivery-date.no-estimate {
            color: var(--bs-secondary);
        }

        .carrier-badge {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .carrier-logo {
            height: 50px;
            width: 50px;
            object-fit: contain;
            filter: drop-shadow(0 1px 2px rgba(0,0,0,0.1));
        }

        .package-name-input {
            border: 1px dashed var(--bs-border-color);
/*            background-color: transparent; */
        }

        .package-name-input:focus {
            border-style: solid;
            border-color: var(--bs-primary);
        }

        .tracking-number {
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
        }

        .tracking-number-link {
/*
            color: var(--bs-primary);
            text-decoration: none;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
*/
        }

        .tracking-number-link:hover {
            text-decoration: underline;
            color: var(--bs-primary);
        }

        .btn-action {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }

        .empty-state {
            padding: 3rem 1rem;
            text-align: center;
            color: var(--bs-secondary);
        }

        .empty-state i {
            font-size: 4rem;
            opacity: 0.3;
        }

        .status-badge {
            font-size: 0.75rem;
            font-weight: 500;
        }

        .last-update {
            font-size: 0.75rem;
            color: var(--bs-secondary);
        }

        .quick-track-section {
            background: linear-gradient(135deg, var(--bs-primary) 0%, var(--bs-primary-rgb-values, #0d6efd) 100%);
            padding: 1rem 0;
            margin-bottom: 2rem;
        }

        .quick-track-input-group {
/*            max-width: 600px; */
        }

        .quick-track-input-group .form-control {
            font-size: 1.1rem;
            padding: 0.75rem 1rem;
        }

        .quick-track-input-group .btn {
            font-size: 1.75rem;
            font-weight: 600;
            padding: 0.75rem 2rem;
        }

        .status-filter-container {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 20px;
            align-items: center;
        }

        .status-filter-btn {
            padding: 6px 14px;
            font-size: 13px;
            border-radius: 4px;
            border: 1px solid #ddd;
            background: white;
            color: #333;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }

        .status-filter-btn:hover {
            border-color: #999;
        }

        .status-filter-btn.active {
            color: white;
            border: none;
        }

        .status-filter-btn.active.delivered {
            background-color: var(--bs-success);
        }

        .status-filter-btn.active.in-transit {
            background-color: var(--bs-primary);
        }

        .status-filter-btn.active.pending {
            background-color: var(--bs-warning);
        }

        .status-filter-btn.active.error {
            background-color: var(--bs-danger);
        }

        .status-filter-btn.all {
            background-color: var(--bs-secondary);
            color: white;
            border: none;
        }

        .status-filter-btn.all:hover {
            background-color: var(--bs-secondary);
            opacity: 0.8;
        }

        .status-filter-label {
            font-weight: 600;
            font-size: 13px;
            margin-right: 8px;
            color: #666;
        }

        /* Highlight animation for tracking card */
        @keyframes highlightPulse {
            0%, 100% {
                box-shadow: 0 0 0 0 rgba(7, 193, 255, 0);
                border-color: rgba(0, 0, 0, 0.125);
            }
            50% {
                box-shadow: 0 0 20px 5px rgba(7, 193, 255, 0.6);
                border-color: #07c1ff;
            }
        }

        .card.highlighted {
            animation: highlightPulse 2s ease-in-out 3;
            scroll-margin-top: 100px;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container d-flex align-items-center">
            <a class="navbar-brand" href="index.php">
                <img src="favicon.png" alt="<?= SITE_NAME ?> Logo">
                <?= SITE_NAME ?>
            </a>
            <div class="ms-auto d-flex gap-2 align-items-center">
                <div class="dropdown">
                    <button class="btn btn-light btn-sm dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars(substr($user['email'], 0, 30)); ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                        <li><a class="dropdown-item" href="settings.php"><i class="bi bi-gear"></i> Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                    </ul>
                </div>
                <?php if ($hasApiKey): ?>
                    <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addTrackingModal">
                        <i class="bi bi-plus-lg"></i> Add Tracking Number
                    </button>
                <?php else: ?>
                    <a href="settings.php" class="btn btn-warning btn-sm">
                        <i class="bi bi-exclamation-triangle"></i> Configure API Key
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Quick Track Section -->
    <?php if ($hasApiKey): ?>
        <div class="quick-track-section">
            <div class="container">
                <form id="quickTrackForm" onsubmit="quickTrackSubmit(event)">
                    <div class="input-group quick-track-input-group">
                        <input type="text"
                               class="form-control"
                               id="quickTrackInput"
                               placeholder="Enter tracking number..."
                               autocomplete="off">
                        <button class="btn btn-success" type="submit">
                            <i class="bi bi-search"></i> Track it!
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php else: ?>
        <div class="quick-track-section">
            <div class="container">
                <div class="alert alert-warning mb-0">
                    <i class="bi bi-exclamation-triangle"></i>
                    <strong>API Key Required:</strong> You need to configure your 17track API key in
                    <a href="settings.php" class="alert-link">Settings</a> to start tracking packages.
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="container mt-4">
        <ul class="nav nav-tabs view-tabs mb-4">
            <li class="nav-item">
                <a class="nav-link <?= $currentView === 'current' ? 'active' : '' ?>" href="?view=current">
                    <i class="bi bi-inbox"></i> Current
                    <?php if ($viewCounts['current'] > 0): ?>
                        <span class="badge rounded-pill bg-primary badge-count"><?= $viewCounts['current'] ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentView === 'archive' ? 'active' : '' ?>" href="?view=archive">
                    <i class="bi bi-archive"></i> Archive
                    <?php /* if ($viewCounts['archive'] > 0): ?>
                        <span class="badge bg-secondary badge-count"><?= $viewCounts['archive'] ?></span>
                    <?php endif; */ ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentView === 'trash' ? 'active' : '' ?>" href="?view=trash">
                    <i class="bi bi-trash"></i> Trash
                    <?php /* if ($viewCounts['trash'] > 0): ?>
                        <span class="badge bg-danger badge-count"><?= $viewCounts['trash'] ?></span>
                    <?php endif; */ ?>
                </a>
            </li>
        </ul>

        <?php if ($currentView === 'trash'): ?>
            <div class="alert alert-info" role="alert">
                <i class="bi bi-info-circle"></i>
                <strong>Notice:</strong> Packages in the trash will be automatically deleted after 90 days.
            </div>
        <?php endif; ?>

        <?php if (!empty($uniqueStatuses) && $currentView !== 'trash'): ?>
            <div class="status-filter-container">
                <a href="?view=<?= $currentView ?>&status=all" class="status-filter-btn <?= $statusFilter === 'all' ? 'active all' : '' ?>">
                    All
                </a>
                <?php foreach ($uniqueStatuses as $status):
                    $statusClass = getStatusClass($status);
                    $isActive = $statusFilter === $status;
                ?>
                    <a href="?view=<?= $currentView ?>&status=<?= urlencode($status) ?>"
                       class="status-filter-btn <?= $isActive ? 'active ' . $statusClass : '' ?>">
                        <?= htmlspecialchars($status) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div id="trackingList">
            <?php if (empty($trackingNumbers)): ?>
                <div class="empty-state">
                    <i class="bi bi-inbox"></i>
                    <h4 class="mt-3">No packages in <?= ucfirst($currentView) ?></h4>
                    <p>Your <?= $currentView ?> list is empty.</p>
                </div>
            <?php else: ?>
                <?php foreach ($trackingNumbers as $tracking): ?>
                    <?php
                    // Determine color based on raw_status (with formatted status as fallback)
                    $badgeColor = getStatusColor($tracking['raw_status'] ?? null, $tracking['status'] ?? null);

                    // Map color to card class for border styling
                    $cardClass = 'tracking-card';
                    switch ($badgeColor) {
                        case 'success':
                            $cardClass .= ' delivered';
                            break;
                        case 'primary':
                            $cardClass .= ' in-transit';
                            break;
                        case 'warning':
                            $cardClass .= ' pending';
                            break;
                        case 'danger':
                            $cardClass .= ' error';
                            break;
                    }

                    // Determine what date to display and its styling
                    $isDelivered = stripos($tracking['status'], 'Delivered') !== false;
                    $hasEstimate = !empty($tracking['estimated_delivery_date']);

                    if ($isDelivered && !empty($tracking['delivered_date'])) {
                        $displayDate = formatDate($tracking['delivered_date']);
                        $dateLabel = 'Delivered: ';
                        $dateClass = 'delivered-date';
                    } elseif ($hasEstimate) {
			if (date('Y-m-d') == $tracking['estimated_delivery_date']) {
                            $displayDate = 'Today';
			} else if (date('Y-m-d', time() + 86400) == $tracking['estimated_delivery_date']) {
                            $displayDate = 'Tomorrow';
                        } else {
                            $displayDate = formatDate($tracking['estimated_delivery_date']);
                        }
                        $dateLabel = 'Est. Delivery: ';
                        $dateClass = 'has-estimate';
                    } else {
                        $displayDate = formatDate($tracking['created_at']);
                        $dateLabel = 'Added: ';
                        $dateClass = 'no-estimate';
                    }
                    ?>
                    <div class="card mb-3 <?= $cardClass ?>" data-tracking-id="<?= $tracking['id'] ?>" data-tracking-number="<?= htmlspecialchars($tracking['tracking_number']) ?>">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <div class="carrier-badge">
                                        <img src="<?= getCarrierLogo($tracking['carrier']) ?>"
                                             alt="<?= htmlspecialchars($tracking['carrier']) ?>"
                                             class="carrier-logo"
                                             title="<?= htmlspecialchars($tracking['carrier']) ?>">
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <input type="text"
                                                   class="form-control form-control-sm package-name-input mb-2"
                                                   placeholder="Package name (optional)"
                                                   value="<?= htmlspecialchars($tracking['package_name'] ?? '') ?>"
                                                   data-tracking-id="<?= $tracking['id'] ?>">
                                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                                <?php if ($tracking['is_outgoing']): ?>
                                                     <span class="badge bg-warning status-badge">Outgoing</span>
                                                <?php endif; ?>
                                                <span class="badge bg-secondary"><?= $tracking['carrier'] ?></span>
						<?php
							$track_url = getTrackingUrl($tracking['tracking_number'], $tracking['carrier']);
							if (!empty($track_url)) {
						?>
                                                <a href="<?= getTrackingUrl($tracking['tracking_number'], $tracking['carrier']) ?>"
                                                   class="tracking-number-link"
                                                   target="_blank"
                                                   rel="noopener noreferrer">
                                                    <?= htmlspecialchars($tracking['tracking_number']) ?>
                                                </a>
						<?php
						} else {
							echo htmlspecialchars($tracking['tracking_number']);
						}
						?>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <div class="delivery-date <?= $dateClass ?> mb-1">
                                                        <i class="bi bi-calendar-event"></i>
                                                        <?= $dateLabel ?>
                                                        <?= $displayDate ?>
                                                    </div>
                                                    <div class="mb-1">
                                                        <span class="badge bg-<?= $badgeColor ?> status-badge">
                                                            <?php
echo htmlspecialchars($tracking['status']);
if (!empty($tracking['sub_status'])) {
	$str = format17TrackSubStatus($tracking['sub_status']);
	if (!empty($str)) {
		echo ': '.htmlspecialchars(format17TrackSubStatus($str));
	}
}
								?>
                                                        </span>
                                                    </div>
                                                    <?php if ($tracking['last_event_date']): ?>
                                                        <div class="last-update">
                                                            <i class="bi bi-clock"></i> Last update: <?= formatDateTime($tracking['last_event_date']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <div class="btn-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.25rem;">
                                        <button class="btn btn-outline-primary btn-action"
                                                onclick="viewDetails(<?= $tracking['id'] ?>)"
                                                title="View Details">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button class="btn btn-outline-<?= $tracking['is_outgoing'] ? 'warning' : 'secondary' ?> btn-action"
                                                onclick="toggleOutgoing(<?= $tracking['id'] ?>)"
                                                title="<?= $tracking['is_outgoing'] ? 'Unmark as Outgoing' : 'Mark as Outgoing' ?>">
                                            <i class="bi bi-send"></i>
                                        </button>
                                        <?php if ($currentView === 'current'): ?>
                                            <button class="btn btn-outline-secondary btn-action"
                                                    onclick="moveToView(<?= $tracking['id'] ?>, 'archive')"
                                                    title="Archive">
                                                <i class="bi bi-archive"></i>
                                            </button>
                                            <button class="btn btn-outline-danger btn-action"
                                                    onclick="moveToView(<?= $tracking['id'] ?>, 'trash')"
                                                    title="Move to Trash">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        <?php elseif ($currentView === 'archive'): ?>
                                            <button class="btn btn-outline-primary btn-action"
                                                    onclick="moveToView(<?= $tracking['id'] ?>, 'current')"
                                                    title="Restore to Current">
                                                <i class="bi bi-arrow-left"></i>
                                            </button>
                                            <button class="btn btn-outline-danger btn-action"
                                                    onclick="moveToView(<?= $tracking['id'] ?>, 'trash')"
                                                    title="Move to Trash">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        <?php elseif ($currentView === 'trash'): ?>
                                            <button class="btn btn-outline-primary btn-action"
                                                    onclick="moveToView(<?= $tracking['id'] ?>, 'current')"
                                                    title="Restore">
                                                <i class="bi bi-arrow-counterclockwise"></i>
                                            </button>
                                            <button class="btn btn-outline-danger btn-action"
                                                    onclick="deletePermanently(<?= $tracking['id'] ?>)"
                                                    title="Delete Permanently">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Tracking Modal -->
    <div class="modal fade" id="addTrackingModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Tracking Number</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="addTrackingForm">
                        <div class="mb-3">
                            <label for="trackingNumber" class="form-label">Tracking Number *</label>
                            <input type="text" class="form-control" id="trackingNumber" required>
                        </div>
                        <div class="mb-3">
                            <label for="carrier" class="form-label">Carrier</label>
                            <select class="form-select" id="carrier">
                                <option value="">Auto-detect</option>
                                <option value="UPS">UPS</option>
                                <option value="USPS">USPS</option>
                                <option value="FedEx">FedEx</option>
                                <option value="YunExpress">YunExpress</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="packageName" class="form-label">Package Name (Optional)</label>
                            <input type="text" class="form-control" id="packageName" placeholder="e.g., New laptop">
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="addTracking()">Add</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Details Modal -->
    <div class="modal fade" id="detailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Tracking Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailsContent">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script src="app.js"></script>
    <script>
        // Handle highlight parameter to scroll to and highlight a specific tracking number
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const highlightTracking = urlParams.get('highlight');

            if (highlightTracking) {
                // Find the card with this tracking number
                const cards = document.querySelectorAll('[data-tracking-number]');
                for (const card of cards) {
                    if (card.dataset.trackingNumber === highlightTracking) {
                        // Scroll to the card with some delay to ensure page is fully loaded
                        setTimeout(() => {
                            card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            card.classList.add('highlighted');

                            // Remove the highlighted class after animation completes
                            setTimeout(() => {
                                card.classList.remove('highlighted');
                            }, 6000); // 3 pulses × 2 seconds
                        }, 500);
                        break;
                    }
                }
            }
        });
    </script>
</body>
</html>

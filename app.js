// Package name auto-save with debounce
let saveTimeout = null;

document.addEventListener('DOMContentLoaded', function() {
    // Setup package name inputs
    const packageNameInputs = document.querySelectorAll('.package-name-input');
    packageNameInputs.forEach(input => {
        input.addEventListener('input', function() {
            clearTimeout(saveTimeout);
            const trackingId = this.dataset.trackingId;
            const packageName = this.value;

            saveTimeout = setTimeout(() => {
                updatePackageName(trackingId, packageName);
            }, 1000);
        });
    });
});

// Quick track submit from header
function quickTrackSubmit(event) {
    event.preventDefault();

    const trackingNumber = document.getElementById('quickTrackInput').value.trim();

    if (!trackingNumber) {
        showAlert('Please enter a tracking number', 'danger');
        return;
    }

    const btn = event.target.querySelector('button');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Adding...';

    const formData = new FormData();
    formData.append('action', 'add');
    formData.append('tracking_number', trackingNumber);

    fetch('api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert(data.message || 'Tracking number added and registered with 17track', 'success');
            document.getElementById('quickTrackInput').value = '';
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showAlert(data.error || 'Failed to add tracking number', 'danger');
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('An error occurred', 'danger');
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    });
}

// Add new tracking number
function addTracking() {
    const trackingNumber = document.getElementById('trackingNumber').value.trim();
    const carrier = document.getElementById('carrier').value;
    const packageName = document.getElementById('packageName').value.trim();

    if (!trackingNumber) {
        showAlert('Please enter a tracking number', 'danger');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'add');
    formData.append('tracking_number', trackingNumber);
    if (carrier) formData.append('carrier', carrier);
    if (packageName) formData.append('package_name', packageName);

    fetch('api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert(data.message || 'Tracking number added successfully', 'success');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showAlert(data.error || 'Failed to add tracking number', 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('An error occurred', 'danger');
    });
}

// Update package name
function updatePackageName(trackingId, packageName) {
    const formData = new FormData();
    formData.append('action', 'update_name');
    formData.append('id', trackingId);
    formData.append('package_name', packageName);

    fetch('api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            console.error('Failed to update package name');
        }
    })
    .catch(error => {
        console.error('Error:', error);
    });
}

// Move tracking number to different view
function moveToView(trackingId, view) {
    const formData = new FormData();
    formData.append('action', 'move');
    formData.append('id', trackingId);
    formData.append('view', view);

    fetch('api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const card = document.querySelector(`[data-tracking-id="${trackingId}"]`);
            if (card) {
                card.style.transition = 'opacity 0.3s';
                card.style.opacity = '0';
                setTimeout(() => {
                    card.remove();
                    checkEmptyState();
                }, 300);
            }
        } else {
            showAlert(data.error || 'Failed to move package', 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('An error occurred', 'danger');
    });
}

// Delete tracking number permanently
function deletePermanently(trackingId) {
    if (!confirm('Are you sure you want to permanently delete this tracking number? This action cannot be undone.')) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', trackingId);

    fetch('api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const card = document.querySelector(`[data-tracking-id="${trackingId}"]`);
            if (card) {
                card.style.transition = 'opacity 0.3s';
                card.style.opacity = '0';
                setTimeout(() => {
                    card.remove();
                    checkEmptyState();
                }, 300);
            }
        } else {
            showAlert(data.error || 'Failed to delete package', 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('An error occurred', 'danger');
    });
}

// View tracking details
function viewDetails(trackingId) {
    const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
    modal.show();

    const detailsContent = document.getElementById('detailsContent');
    detailsContent.innerHTML = `
        <div class="text-center">
            <div class="spinner-border" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>
    `;

    fetch(`api.php?action=details&id=${trackingId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayTrackingDetails(data.tracking, data.events);
            } else {
                detailsContent.innerHTML = `
                    <div class="alert alert-danger">
                        ${data.error || 'Failed to load details'}
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            detailsContent.innerHTML = `
                <div class="alert alert-danger">
                    An error occurred while loading details
                </div>
            `;
        });
}

// Display tracking details in modal
function displayTrackingDetails(tracking, events) {
    const detailsContent = document.getElementById('detailsContent');

    // Use status color from API response (determined by getStatusColor() in PHP)
    const badgeColor = tracking.status_color || 'info';

    let eventsHtml = '';
    if (events && events.length > 0) {
        eventsHtml = `
            <h6 class="mt-4 mb-3">Tracking History</h6>
            <div class="timeline">
        `;

        events.forEach((event, index) => {
            eventsHtml += `
                <div class="card mb-2 ${index === 0 ? 'border-primary' : ''}">
                    <div class="card-body py-2">
                        <div class="row">
                            <div class="col-md-3">
                                <small class="text-muted">${formatEventDate(event.event_date)}</small>
                            </div>
                            <div class="col-md-9">
                                <strong>${escapeHtml(event.status)}</strong>
                                ${event.location ? `<br><small class="text-muted"><i class="bi bi-geo-alt"></i> ${escapeHtml(event.location)}</small>` : ''}
                                ${event.description ? `<br><small>${escapeHtml(event.description)}</small>` : ''}
                            </div>
                        </div>
                    </div>
                </div>
            `;
        });

        eventsHtml += '</div>';
    } else {
        eventsHtml = `
            <div class="alert alert-info mt-4">
                No tracking events available yet.
            </div>
        `;
    }

    detailsContent.innerHTML = `
        <div class="row mb-3">
            <div class="col-md-6">
                <h6>Tracking Number</h6>
                <p class="font-monospace">${escapeHtml(tracking.tracking_number)}</p>
            </div>
            <div class="col-md-6">
                <h6>Carrier</h6>
                <p><span class="badge bg-secondary">${escapeHtml(tracking.carrier)}</span></p>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <h6>Status</h6>
                <p>
                    <span class="badge bg-${badgeColor}">
                        ${escapeHtml(tracking.status)}${tracking.formatted_sub_status ? ': ' + escapeHtml(tracking.formatted_sub_status) : ''}
                    </span>
                </p>
            </div>
            <div class="col-md-6">
                <h6>Package Name</h6>
                <p>${tracking.package_name ? escapeHtml(tracking.package_name) : '<em class="text-muted">Not specified</em>'}</p>
            </div>
        </div>

        ${tracking.estimated_delivery_date ? `
        <div class="row mb-3">
            <div class="col-md-6">
                <h6>Estimated Delivery</h6>
                <p>${formatDisplayDate(tracking.estimated_delivery_date)}</p>
            </div>
        </div>
        ` : ''}

        ${tracking.delivered_date ? `
        <div class="row mb-3">
            <div class="col-md-6">
                <h6>Delivered Date</h6>
                <p>${formatDisplayDate(tracking.delivered_date)}</p>
            </div>
        </div>
        ` : ''}

        <div class="row mb-3">
            <div class="col-md-6">
                <h6>Added</h6>
                <p>${formatDisplayDate(tracking.created_at)}</p>
            </div>
            <div class="col-md-6">
                <h6>Last Updated</h6>
                <p>${tracking.updated_at ? formatDisplayDate(tracking.updated_at) : 'Never'}</p>
            </div>
        </div>

        ${eventsHtml}

        <div class="mt-4">
            <button class="btn btn-primary btn-sm" onclick="refreshTracking(${tracking.id})">
                <i class="bi bi-arrow-clockwise"></i> Refresh Tracking
            </button>
        </div>
    `;
}

// Refresh tracking information
function refreshTracking(trackingId) {
    const btn = event.target.closest('button');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Refreshing...';

    fetch(`api.php?action=refresh&id=${trackingId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showAlert('Tracking information updated', 'success');
                setTimeout(() => window.location.reload(), 1000);
            } else {
                showAlert(data.error || 'Failed to refresh tracking', 'danger');
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlert('An error occurred', 'danger');
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        });
}

// Show alert message
function showAlert(message, type) {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3`;
    alertDiv.style.zIndex = '9999';
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;

    document.body.appendChild(alertDiv);

    setTimeout(() => {
        alertDiv.remove();
    }, 5000);
}

// Check if list is empty and show empty state
function checkEmptyState() {
    const trackingList = document.getElementById('trackingList');
    const cards = trackingList.querySelectorAll('.tracking-card');

    if (cards.length === 0) {
        const currentView = new URLSearchParams(window.location.search).get('view') || 'current';
        trackingList.innerHTML = `
            <div class="empty-state">
                <i class="bi bi-inbox"></i>
                <h4 class="mt-3">No packages in ${currentView.charAt(0).toUpperCase() + currentView.slice(1)}</h4>
                <p>Your ${currentView} list is empty.</p>
            </div>
        `;
    }
}

// Utility functions
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatEventDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit'
    });
}

function formatDisplayDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    });
}

// Toggle outgoing shipment status
function toggleOutgoing(trackingId) {
    const formData = new FormData();
    formData.append('action', 'toggle_outgoing');
    formData.append('id', trackingId);

    fetch('api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const card = document.querySelector(`[data-tracking-id="${trackingId}"]`);
            const btn = card.querySelector('button[title*="Outgoing"]');
            const badgeContainer = card.querySelector('.d-flex.align-items-center.gap-2');

            if (data.is_outgoing) {
                showAlert('Marked as outgoing shipment', 'success');
                // Update button appearance
                btn.classList.remove('btn-outline-secondary');
                btn.classList.add('btn-outline-warning');
                btn.title = 'Unmark as Outgoing';

                // Add badge to the left of carrier badge
                let outgoingBadge = badgeContainer.querySelector('.badge.bg-warning');
                if (!outgoingBadge) {
                    outgoingBadge = document.createElement('span');
                    outgoingBadge.className = 'badge bg-warning status-badge';
                    outgoingBadge.textContent = 'Outgoing';
                    badgeContainer.insertBefore(outgoingBadge, badgeContainer.firstChild);
                }
            } else {
                showAlert('Unmarked as outgoing shipment', 'success');
                // Update button appearance
                btn.classList.remove('btn-outline-warning');
                btn.classList.add('btn-outline-secondary');
                btn.title = 'Mark as Outgoing';

                // Remove badge
                const outgoingBadge = badgeContainer.querySelector('.badge.bg-warning');
                if (outgoingBadge) {
                    outgoingBadge.remove();
                }
            }
        } else {
            showAlert(data.error || 'Failed to toggle outgoing status', 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('An error occurred', 'danger');
    });
}

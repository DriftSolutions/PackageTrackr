<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Logout must be a POST with a valid CSRF token so other sites can't log users out
if (!isValidCsrfRequest()) {
    header('Location: index.php');
    exit;
}

// Logout user
logoutUser();

// Redirect to login
header('Location: login.php');
exit;

<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Logout user
logoutUser();

// Redirect to login
header('Location: login.php');
exit;

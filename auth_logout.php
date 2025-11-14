<?php
// auth_logout.php
require __DIR__ . '/inc/auth.php';

// End session and redirect to login
logout_user();
flash_set('ok', 'You have been logged out.');
header('Location: auth_login.php');
exit;

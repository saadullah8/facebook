<?php
// Special logout file - doesn't include config.php
session_name('social_app_session');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Store username for message
$username = $_SESSION['username'] ?? 'Guest';

// Clear all session variables
$_SESSION = array();

// Delete session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Start a fresh session for logout message
session_name('social_app_session');
session_start();
$_SESSION['logout_message'] = "Goodbye $username! You have been logged out successfully.";

// Redirect to login page
header('Location: login.php');
exit();

<?php

/**
 * Main Configuration File (ROOT LEVEL)
 * This is the ONLY file you need to include in every PHP file
 * It loads everything in the correct order
 */

// =========== DEFINE CONSTANTS ===========
define('ROOT_PATH', realpath(__DIR__));
define('BASE_URL', 'http://localhost/social-app');
define('SITE_NAME', 'SocialApp');
define('TIMEZONE', 'Asia/Kolkata');

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'social_app_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// File upload configuration
define('UPLOAD_DIR', ROOT_PATH . '/uploads/');
define('UPLOAD_URL', BASE_URL . '/uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

// Set timezone
date_default_timezone_set(TIMEZONE);

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check SSL
define('IS_SSL', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443);

// =========== START SESSION ===========
// Check if we're on logout page
$current_file = basename($_SERVER['PHP_SELF']);

if ($current_file !== 'logout.php') {
    session_name('social_app_session');

    // Start session only if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Session regeneration for security (every 30 minutes)
    if (!isset($_SESSION['created'])) {
        $_SESSION['created'] = time();
    } elseif (time() - $_SESSION['created'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['created'] = time();
    }
}


// =========== INCLUDE DATABASE CONNECTION ===========
require_once ROOT_PATH . '/config/database.php';

// =========== INCLUDE FUNCTIONS ===========
require_once ROOT_PATH . '/includes/functions.php';  // This will be the main functions file

// =========== AUTO-CREATE UPLOAD DIRECTORIES ===========
$dirs = [
    UPLOAD_DIR,
    UPLOAD_DIR . 'profile_pics/',
    UPLOAD_DIR . 'post_images/',
    UPLOAD_DIR . 'message_files/'
];

foreach ($dirs as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
}

// Mark config loaded
define('CONFIG_LOADED', true);

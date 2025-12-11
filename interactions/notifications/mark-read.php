<?php
require_once '../../config.php';

// Check authentication
if (!isLoggedIn()) {
    header('Location: ../../auth/login.php');
    exit();
}

$currentUser = getCurrentUser();
if (!$currentUser) {
    header('Location: ../../auth/logout.php');
    exit();
}

// Get notification ID
$notificationId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$redirectUrl = isset($_GET['redirect']) ? sanitize($_GET['redirect']) : 'notifications.php';

if ($notificationId > 0) {
    try {
        // Mark single notification as read
        $stmt = db()->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$notificationId, $currentUser['id']]);
        $_SESSION['success'] = 'Notification marked as read.';
    } catch (PDOException $e) {
        error_log("Mark read error: " . $e->getMessage());
        $_SESSION['error'] = 'Failed to mark notification as read.';
    }
} else {
    $_SESSION['error'] = 'Invalid notification.';
}

// Redirect back
header('Location: ' . $redirectUrl);
exit();

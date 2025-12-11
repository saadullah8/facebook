<?php
require_once __DIR__ . '/../config.php';

// Check authentication
if (!isLoggedIn()) {
    setFlashMessage('error', 'Please log in to continue.');
    header('Location: ../auth/login.php');
    exit();
}

// Check if friend ID is provided
if (!isset($_POST['friend_id']) || !is_numeric($_POST['friend_id'])) {
    setFlashMessage('error', 'Invalid friend ID.');
    header('Location: friends.php');
    exit();
}

// Check CSRF token
if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
    setFlashMessage('error', 'Invalid security token.');
    header('Location: friends.php');
    exit();
}

$friendId = intval($_POST['friend_id']);
$currentUser = getCurrentUser();

try {
    // Get friend's details
    $friendStmt = db()->prepare("
        SELECT username, full_name 
        FROM users 
        WHERE id = ?
        LIMIT 1
    ");
    $friendStmt->execute([$friendId]);
    $friend = $friendStmt->fetch();

    if (!$friend) {
        setFlashMessage('error', 'Friend not found.');
        header('Location: friends.php');
        exit();
    }

    // Remove friendship
    $stmt = db()->prepare("
        DELETE FROM friendships 
        WHERE (
            (user1_id = ? AND user2_id = ?) OR 
            (user1_id = ? AND user2_id = ?)
        )
    ");
    $deleted = $stmt->execute([$currentUser['id'], $friendId, $friendId, $currentUser['id']]);

    if ($deleted) {
        // Create notification for the removed friend
        try {
            $notificationStmt = db()->prepare("
                INSERT INTO notifications (user_id, type, message, created_at) 
                VALUES (?, 'friend_removed', ?, NOW())
            ");
            $notificationStmt->execute([
                $friendId,
                $currentUser['username'] . " removed you from their friends list."
            ]);
        } catch (Exception $e) {
            // Non-critical error
            error_log("Notification error in remove-friend.php: " . $e->getMessage());
        }

        setFlashMessage('success', 'Successfully removed ' . htmlspecialchars($friend['full_name']) . ' from your friends list.');
    } else {
        setFlashMessage('error', 'Failed to remove friend. You may not be friends.');
    }
} catch (PDOException $e) {
    error_log("Remove friend error: " . $e->getMessage());
    setFlashMessage('error', 'Database error: Failed to remove friend.');
}

header('Location: friends.php');
exit();

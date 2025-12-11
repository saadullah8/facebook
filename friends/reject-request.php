<?php
require_once __DIR__ . '/../config.php';

// Check authentication
if (!isLoggedIn()) {
    setFlashMessage('error', 'Please log in to continue.');
    header('Location: ../auth/login.php');
    exit();
}

// Check if request ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setFlashMessage('error', 'Invalid request ID.');
    header('Location: friend-requests.php');
    exit();
}

$requestId = intval($_GET['id']);
$currentUser = getCurrentUser();

try {
    // Get request details
    $stmt = db()->prepare("
        SELECT f.*, u.username as sender_username, u.full_name as sender_name 
        FROM friendships f 
        JOIN users u ON f.user1_id = u.id 
        WHERE f.id = ? AND f.user2_id = ? AND f.status = 'pending'
        LIMIT 1
    ");
    $stmt->execute([$requestId, $currentUser['id']]);
    $request = $stmt->fetch();

    if (!$request) {
        setFlashMessage('error', 'Friend request not found or already processed.');
        header('Location: friend-requests.php');
        exit();
    }

    // Update friendship status to rejected
    $updateStmt = db()->prepare("UPDATE friendships SET status = 'rejected', updated_at = NOW() WHERE id = ?");
    $updateStmt->execute([$requestId]);

    // Optional: Create notification for the sender
    try {
        $notificationStmt = db()->prepare("
            INSERT INTO notifications (user_id, type, message, created_at) 
            VALUES (?, 'friend_request_rejected', ?, NOW())
        ");
        $notificationStmt->execute([
            $request['user1_id'],
            $currentUser['username'] . " declined your friend request."
        ]);
    } catch (Exception $e) {
        // Non-critical error
        error_log("Notification error in reject-request.php: " . $e->getMessage());
    }

    setFlashMessage('success', 'Friend request from ' . htmlspecialchars($request['sender_name']) . ' has been declined.');
} catch (PDOException $e) {
    error_log("Reject friend request error: " . $e->getMessage());
    setFlashMessage('error', 'Failed to decline friend request. Please try again.');
}

header('Location: friend-requests.php');
exit();

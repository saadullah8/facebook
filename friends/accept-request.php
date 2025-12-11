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

// Check if user exists
if (!$currentUser) {
    setFlashMessage('error', 'User not found.');
    header('Location: ../auth/logout.php');
    exit();
}

try {
    // Start transaction for data consistency
    db()->beginTransaction();

    // Get request details with additional checks
    $stmt = db()->prepare("
        SELECT 
            f.*, 
            u.id as sender_id,
            u.username as sender_username, 
            u.full_name as sender_name,
            u.email as sender_email
        FROM friendships f 
        JOIN users u ON f.user1_id = u.id 
        WHERE f.id = ? 
        AND f.user2_id = ? 
        AND f.status = 'pending'
        LIMIT 1
    ");
    $stmt->execute([$requestId, $currentUser['id']]);
    $request = $stmt->fetch();

    if (!$request) {
        db()->rollBack();
        setFlashMessage('error', 'Friend request not found, already processed, or you don\'t have permission.');
        header('Location: friend-requests.php');
        exit();
    }

    // Check if friendship already exists (accepted)
    $checkStmt = db()->prepare("
        SELECT id FROM friendships 
        WHERE (
            (user1_id = ? AND user2_id = ?) OR 
            (user1_id = ? AND user2_id = ?)
        ) 
        AND status = 'accepted'
        LIMIT 1
    ");
    $checkStmt->execute([
        $currentUser['id'],
        $request['sender_id'],
        $request['sender_id'],
        $currentUser['id']
    ]);

    if ($checkStmt->fetch()) {
        db()->rollBack();
        setFlashMessage('error', 'You are already friends with ' . htmlspecialchars($request['sender_name']) . '.');
        header('Location: friend-requests.php');
        exit();
    }

    // Update friendship status to accepted
    $updateStmt = db()->prepare("
        UPDATE friendships 
        SET status = 'accepted', 
            updated_at = NOW() 
        WHERE id = ?
    ");
    $updateResult = $updateStmt->execute([$requestId]);

    if (!$updateResult) {
        db()->rollBack();
        setFlashMessage('error', 'Failed to update friendship status.');
        header('Location: friend-requests.php');
        exit();
    }

    // Create notification for the sender
    try {
        $notificationStmt = db()->prepare("
            INSERT INTO notifications 
            (user_id, type, message, created_at) 
            VALUES (?, 'friend_request_accepted', ?, NOW())
        ");
        $notificationStmt->execute([
            $request['sender_id'],
            htmlspecialchars($currentUser['username']) . " accepted your friend request."
        ]);
    } catch (Exception $e) {
        // Log but don't fail if notifications table doesn't exist
        error_log("Notification creation error (non-critical): " . $e->getMessage());
    }

    // Also create notification for current user
    try {
        $selfNotificationStmt = db()->prepare("
            INSERT INTO notifications 
            (user_id, type, message, created_at) 
            VALUES (?, 'friend_added', ?, NOW())
        ");
        $selfNotificationStmt->execute([
            $currentUser['id'],
            "You are now friends with " . htmlspecialchars($request['sender_name']) . "."
        ]);
    } catch (Exception $e) {
        error_log("Self notification error: " . $e->getMessage());
    }

    // Update user's friend count cache (optional optimization)
    try {
        // You can add a friend_count column to users table and update it here
        // For now, we'll just log it
        error_log("Friend request accepted: User " . $currentUser['id'] . " accepted request from " . $request['sender_id']);
    } catch (Exception $e) {
        // Non-critical error
    }

    // Commit transaction
    db()->commit();

    // Set success message with user's name
    $successMessage = 'Friend request accepted! You are now friends with ' .
        htmlspecialchars($request['sender_name']) .
        ' (@' . htmlspecialchars($request['sender_username']) . ').';

    setFlashMessage('success', $successMessage);

    // Redirect with success
    header('Location: friend-requests.php');
    exit();
} catch (PDOException $e) {
    // Rollback on error
    if (db()->inTransaction()) {
        db()->rollBack();
    }

    error_log("Accept friend request error: " . $e->getMessage());

    // User-friendly error message
    $errorMessage = 'Database error: ' . $e->getMessage();
    if (strpos($e->getMessage(), 'friendships') !== false) {
        $errorMessage = 'Error processing friend request. Please try again.';
    }

    setFlashMessage('error', $errorMessage);
    header('Location: friend-requests.php');
    exit();
} catch (Exception $e) {
    // General exception handling
    error_log("General error in accept-request.php: " . $e->getMessage());
    setFlashMessage('error', 'An unexpected error occurred.');
    header('Location: friend-requests.php');
    exit();
}

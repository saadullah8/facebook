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

// Get comment ID
$commentId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$redirectUrl = isset($_GET['redirect']) ? sanitize($_GET['redirect']) : '../../index.php';

if ($commentId <= 0) {
    header('Location: ' . $redirectUrl);
    exit();
}

try {
    // Get comment details
    $stmt = db()->prepare("
        SELECT c.*, p.user_id as post_owner_id, p.privacy 
        FROM comments c 
        JOIN posts p ON c.post_id = p.id 
        WHERE c.id = ?
    ");
    $stmt->execute([$commentId]);
    $comment = $stmt->fetch();

    if (!$comment) {
        $_SESSION['error'] = 'Comment not found.';
        header('Location: ' . $redirectUrl);
        exit();
    }

    // Check if user can delete (comment owner or post owner)
    $canDelete = ($comment['user_id'] == $currentUser['id'] || $comment['post_owner_id'] == $currentUser['id']);

    if (!$canDelete) {
        $_SESSION['error'] = 'You are not authorized to delete this comment.';
        header('Location: ' . $redirectUrl);
        exit();
    }

    // Delete the comment
    $deleteStmt = db()->prepare("DELETE FROM comments WHERE id = ?");
    $deleteStmt->execute([$commentId]);

    $_SESSION['success'] = 'Comment deleted successfully.';
} catch (PDOException $e) {
    error_log("Delete comment error: " . $e->getMessage());
    $_SESSION['error'] = 'Failed to delete comment.';
}

// Redirect back
header('Location: ' . $redirectUrl);
exit();

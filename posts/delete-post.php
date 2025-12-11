<?php
require_once __DIR__ . '/../config.php';

// Check authentication
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Set JSON header
header('Content-Type: application/json');

// Get POST data
$postId = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
$csrfToken = $_POST['csrf_token'] ?? '';

if ($postId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid post ID']);
    exit();
}

if (empty($csrfToken) || !validateCsrfToken($csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit();
}

$currentUser = getCurrentUser();

try {
    // Check if post exists and belongs to user
    $checkStmt = db()->prepare("SELECT id, user_id, image FROM posts WHERE id = ?");
    $checkStmt->execute([$postId]);
    $post = $checkStmt->fetch();

    if (!$post) {
        echo json_encode(['success' => false, 'message' => 'Post not found']);
        exit();
    }

    // Check if user owns the post or is admin
    if ($post['user_id'] != $currentUser['id']) {
        // Check if user is admin (optional)
        $adminCheck = db()->prepare("SELECT is_admin FROM users WHERE id = ?");
        $adminCheck->execute([$currentUser['id']]);
        $user = $adminCheck->fetch();

        if (!($user['is_admin'] ?? 0)) {
            echo json_encode(['success' => false, 'message' => 'You can only delete your own posts']);
            exit();
        }
    }

    // Start transaction
    db()->beginTransaction();

    // Delete post image if exists
    if (!empty($post['image'])) {
        $imagePath = UPLOAD_DIR . 'post_images/' . $post['image'];
        if (file_exists($imagePath)) {
            unlink($imagePath);
        }
    }

    // Delete post comments
    $deleteComments = db()->prepare("DELETE FROM comments WHERE post_id = ?");
    $deleteComments->execute([$postId]);

    // Delete post likes
    $deleteLikes = db()->prepare("DELETE FROM likes WHERE post_id = ?");
    $deleteLikes->execute([$postId]);

    // Delete post shares
    $deleteShares = db()->prepare("DELETE FROM shares WHERE post_id = ?");
    $deleteShares->execute([$postId]);

    // Delete post notifications
    $deleteNotifications = db()->prepare("DELETE FROM notifications WHERE link LIKE ?");
    $deleteNotifications->execute(["%post=$postId%"]);

    // Finally delete the post
    $deletePost = db()->prepare("DELETE FROM posts WHERE id = ?");
    $deletePost->execute([$postId]);

    // Commit transaction
    db()->commit();

    echo json_encode(['success' => true, 'message' => 'Post deleted successfully']);
} catch (PDOException $e) {
    // Rollback on error
    if (db()->inTransaction()) {
        db()->rollBack();
    }

    error_log("Delete post error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to delete post']);
}

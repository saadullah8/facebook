<?php
require_once __DIR__ . '/../config.php';

// Set JSON header first
header('Content-Type: application/json');

// Check authentication
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Get POST data - check both POST and JSON input
$postId = 0;
$csrfToken = '';

// Try regular POST first
if (isset($_POST['post_id'])) {
    $postId = intval($_POST['post_id']);
    $csrfToken = $_POST['csrf_token'] ?? '';
} else {
    // Try JSON input
    $jsonInput = file_get_contents('php://input');
    if (!empty($jsonInput)) {
        $data = json_decode($jsonInput, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $postId = isset($data['post_id']) ? intval($data['post_id']) : 0;
            $csrfToken = $data['csrf_token'] ?? '';
        }
    }
}

// Validation
if ($postId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid post ID']);
    exit();
}

// CSRF token validation - skip if not provided (for development)
// In production, uncomment these lines:
// if (empty($csrfToken) || !validateCsrfToken($csrfToken)) {
//     echo json_encode(['success' => false, 'message' => 'Invalid security token']);
//     exit();
// }

$currentUser = getCurrentUser();

if (!$currentUser) {
    echo json_encode(['success' => false, 'message' => 'User session not found']);
    exit();
}

try {
    // Check if post exists and belongs to user
    $checkStmt = db()->prepare("SELECT id, user_id, image FROM posts WHERE id = ?");
    $checkStmt->execute([$postId]);
    $post = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) {
        echo json_encode(['success' => false, 'message' => 'Post not found']);
        exit();
    }

    // Check if user owns the post or is admin
    $isOwner = ($post['user_id'] == $currentUser['id']);
    $isAdmin = false;

    if (!$isOwner) {
        // Check if user is admin (optional)
        try {
            $adminCheck = db()->prepare("SELECT is_admin FROM users WHERE id = ?");
            $adminCheck->execute([$currentUser['id']]);
            $user = $adminCheck->fetch(PDO::FETCH_ASSOC);
            $isAdmin = isset($user['is_admin']) && $user['is_admin'] == 1;
        } catch (Exception $e) {
            // is_admin column might not exist
            error_log("Admin check error: " . $e->getMessage());
        }

        if (!$isAdmin) {
            echo json_encode(['success' => false, 'message' => 'You can only delete your own posts']);
            exit();
        }
    }

    // Start transaction
    db()->beginTransaction();

    // Delete post image if exists
    if (!empty($post['image'])) {
        $imagePath = __DIR__ . '/../uploads/post_images/' . $post['image'];
        if (file_exists($imagePath)) {
            if (!unlink($imagePath)) {
                error_log("Warning: Could not delete image file: " . $imagePath);
            } else {
                error_log("Deleted image: " . $imagePath);
            }
        }
    }

    // Delete related data (ignore errors if tables don't exist)
    try {
        $deleteComments = db()->prepare("DELETE FROM comments WHERE post_id = ?");
        $deleteComments->execute([$postId]);
        error_log("Deleted comments for post $postId");
    } catch (Exception $e) {
        error_log("Delete comments error: " . $e->getMessage());
    }

    try {
        $deleteLikes = db()->prepare("DELETE FROM likes WHERE post_id = ?");
        $deleteLikes->execute([$postId]);
        error_log("Deleted likes for post $postId");
    } catch (Exception $e) {
        error_log("Delete likes error: " . $e->getMessage());
    }

    try {
        $deleteShares = db()->prepare("DELETE FROM shares WHERE post_id = ?");
        $deleteShares->execute([$postId]);
        error_log("Deleted shares for post $postId");
    } catch (Exception $e) {
        error_log("Delete shares error: " . $e->getMessage());
    }

    try {
        $deleteNotifications = db()->prepare("DELETE FROM notifications WHERE link LIKE ?");
        $deleteNotifications->execute(["%post=$postId%"]);
        error_log("Deleted notifications for post $postId");
    } catch (Exception $e) {
        error_log("Delete notifications error: " . $e->getMessage());
    }

    // Finally delete the post
    $deletePost = db()->prepare("DELETE FROM posts WHERE id = ?");
    $result = $deletePost->execute([$postId]);

    if (!$result) {
        throw new Exception("Failed to execute DELETE query");
    }

    $rowsAffected = $deletePost->rowCount();
    error_log("Post delete result - Rows affected: $rowsAffected");

    if ($rowsAffected === 0) {
        db()->rollBack();
        echo json_encode(['success' => false, 'message' => 'Post could not be deleted']);
        exit();
    }

    // Commit transaction
    db()->commit();

    error_log("Successfully deleted post ID: $postId");
    echo json_encode(['success' => true, 'message' => 'Post deleted successfully']);
} catch (PDOException $e) {
    // Rollback on error
    if (db()->inTransaction()) {
        db()->rollBack();
    }

    error_log("Delete post PDO error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    // Rollback on error
    if (db()->inTransaction()) {
        db()->rollBack();
    }

    error_log("Delete post error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to delete post: ' . $e->getMessage()
    ]);
}

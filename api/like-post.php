<?php
require_once '../config.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'Method not allowed']));
}

// Check authentication
if (!isLoggedIn()) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Not authenticated']));
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate input
if (!isset($input['post_id']) || !isset($input['csrf_token'])) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Missing required fields']));
}

// Validate CSRF token
if (!validateCsrfToken($input['csrf_token'])) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Invalid security token']));
}

$postId = intval($input['post_id']);
$userId = getCurrentUserId();

try {
    // Check if user already liked the post
    $checkStmt = db()->prepare("SELECT id FROM likes WHERE user_id = ? AND post_id = ?");
    $checkStmt->execute([$userId, $postId]);
    $existingLike = $checkStmt->fetch();

    if ($existingLike) {
        // Unlike the post
        $deleteStmt = db()->prepare("DELETE FROM likes WHERE user_id = ? AND post_id = ?");
        $deleteStmt->execute([$userId, $postId]);

        echo json_encode([
            'success' => true,
            'action' => 'unliked',
            'message' => 'Post unliked',
            'likes_count' => getLikesCount($postId)
        ]);
    } else {
        // Like the post
        $insertStmt = db()->prepare("INSERT INTO likes (user_id, post_id, created_at) VALUES (?, ?, NOW())");
        $insertStmt->execute([$userId, $postId]);

        // Create notification for post owner
        createLikeNotification($postId, $userId);

        echo json_encode([
            'success' => true,
            'action' => 'liked',
            'message' => 'Post liked',
            'likes_count' => getLikesCount($postId)
        ]);
    }
} catch (PDOException $e) {
    error_log("Like error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}

// Helper function to get likes count
function getLikesCount($postId)
{
    try {
        $stmt = db()->prepare("SELECT COUNT(*) as count FROM likes WHERE post_id = ?");
        $stmt->execute([$postId]);
        $result = $stmt->fetch();
        return $result['count'] ?? 0;
    } catch (PDOException $e) {
        return 0;
    }
}

// Helper function to create notification
function createLikeNotification($postId, $likerId)
{
    try {
        // Get post owner
        $stmt = db()->prepare("SELECT user_id FROM posts WHERE id = ?");
        $stmt->execute([$postId]);
        $post = $stmt->fetch();

        if ($post && $post['user_id'] != $likerId) {
            // Insert notification
            $notifStmt = db()->prepare("
                INSERT INTO notifications (user_id, type, from_user_id, post_id, created_at) 
                VALUES (?, 'like', ?, ?, NOW())
            ");
            $notifStmt->execute([$post['user_id'], $likerId, $postId]);
        }
    } catch (PDOException $e) {
        error_log("Create notification error: " . $e->getMessage());
    }
}

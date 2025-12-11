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
if (!isset($input['post_id']) || !isset($input['comment']) || !isset($input['csrf_token'])) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Missing required fields']));
}

// Validate CSRF token
if (!validateCsrfToken($input['csrf_token'])) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Invalid security token']));
}

$postId = intval($input['post_id']);
$comment = sanitize($input['comment']);
$userId = getCurrentUserId();

// Validate comment
if (empty($comment)) {
    echo json_encode(['success' => false, 'message' => 'Comment cannot be empty']);
    exit();
}

if (strlen($comment) > 1000) {
    echo json_encode(['success' => false, 'message' => 'Comment too long (max 1000 characters)']);
    exit();
}

try {
    // Check if post exists and user can comment
    $postStmt = db()->prepare("
        SELECT p.*, u.username 
        FROM posts p 
        JOIN users u ON p.user_id = u.id 
        WHERE p.id = ?
    ");
    $postStmt->execute([$postId]);
    $post = $postStmt->fetch();

    if (!$post) {
        echo json_encode(['success' => false, 'message' => 'Post not found']);
        exit();
    }

    // Check privacy settings
    if ($post['user_id'] != $userId && $post['privacy'] === 'private') {
        echo json_encode(['success' => false, 'message' => 'Cannot comment on private post']);
        exit();
    }

    if ($post['user_id'] != $userId && $post['privacy'] === 'friends') {
        // Check if friends
        $friendStmt = db()->prepare("
            SELECT id FROM friendships 
            WHERE ((user1_id = ? AND user2_id = ?) OR (user1_id = ? AND user2_id = ?)) 
            AND status = 'accepted'
        ");
        $friendStmt->execute([$userId, $post['user_id'], $post['user_id'], $userId]);

        if (!$friendStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Cannot comment on friends-only post']);
            exit();
        }
    }

    // Insert comment
    $insertStmt = db()->prepare("
        INSERT INTO comments (user_id, post_id, comment, created_at) 
        VALUES (?, ?, ?, NOW())
    ");
    $insertStmt->execute([$userId, $postId, $comment]);

    $commentId = db()->lastInsertId();

    // Create notification for post owner (if not commenting on own post)
    if ($post['user_id'] != $userId) {
        $notifStmt = db()->prepare("
            INSERT INTO notifications (user_id, type, from_user_id, post_id, created_at) 
            VALUES (?, 'comment', ?, ?, NOW())
        ");
        $notifStmt->execute([$post['user_id'], $userId, $postId]);
    }

    // Get comment data for response
    $commentStmt = db()->prepare("
        SELECT c.*, u.username, u.full_name, u.profile_pic 
        FROM comments c 
        JOIN users u ON c.user_id = u.id 
        WHERE c.id = ?
    ");
    $commentStmt->execute([$commentId]);
    $commentData = $commentStmt->fetch();

    // Format date
    $commentData['formatted_time'] = formatTimeAgo($commentData['created_at']);
    $commentData['profile_pic_url'] = getProfilePic($commentData['user_id']);

    // Get updated comment count
    $countStmt = db()->prepare("SELECT COUNT(*) as count FROM comments WHERE post_id = ?");
    $countStmt->execute([$postId]);
    $countResult = $countStmt->fetch();

    echo json_encode([
        'success' => true,
        'message' => 'Comment added',
        'comment' => $commentData,
        'comment_count' => $countResult['count'] ?? 0
    ]);
} catch (PDOException $e) {
    error_log("Add comment error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}

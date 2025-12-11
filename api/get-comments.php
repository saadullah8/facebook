<?php
require_once '../config.php';

// Check authentication
if (!isLoggedIn()) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Not authenticated']));
}

// Get post ID from query parameter
if (!isset($_GET['post_id'])) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Post ID required']));
}

$postId = intval($_GET['post_id']);
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;

try {
    // Check if post exists and user can view
    $postStmt = db()->prepare("SELECT * FROM posts WHERE id = ?");
    $postStmt->execute([$postId]);
    $post = $postStmt->fetch();

    if (!$post) {
        echo json_encode(['success' => false, 'message' => 'Post not found']);
        exit();
    }

    $userId = getCurrentUserId();

    // Check privacy settings
    if ($post['user_id'] != $userId) {
        if ($post['privacy'] === 'private') {
            echo json_encode(['success' => false, 'message' => 'Cannot view private post']);
            exit();
        }

        if ($post['privacy'] === 'friends') {
            $friendStmt = db()->prepare("
                SELECT id FROM friendships 
                WHERE ((user1_id = ? AND user2_id = ?) OR (user1_id = ? AND user2_id = ?)) 
                AND status = 'accepted'
            ");
            $friendStmt->execute([$userId, $post['user_id'], $post['user_id'], $userId]);

            if (!$friendStmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Cannot view friends-only post']);
                exit();
            }
        }
    }

    // Get comments
    $stmt = db()->prepare("
        SELECT c.*, u.username, u.full_name, u.profile_pic 
        FROM comments c 
        JOIN users u ON c.user_id = u.id 
        WHERE c.post_id = ? 
        ORDER BY c.created_at ASC 
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$postId, $limit, $offset]);
    $comments = $stmt->fetchAll();

    // Format comments
    foreach ($comments as &$comment) {
        $comment['formatted_time'] = formatTimeAgo($comment['created_at']);
        $comment['profile_pic_url'] = getProfilePic($comment['user_id']);
        $comment['can_delete'] = ($comment['user_id'] == $userId || $post['user_id'] == $userId);
    }

    // Get total comment count
    $countStmt = db()->prepare("SELECT COUNT(*) as count FROM comments WHERE post_id = ?");
    $countStmt->execute([$postId]);
    $countResult = $countStmt->fetch();

    echo json_encode([
        'success' => true,
        'comments' => $comments,
        'total_count' => $countResult['count'] ?? 0,
        'has_more' => (($offset + count($comments)) < ($countResult['count'] ?? 0))
    ]);
} catch (PDOException $e) {
    error_log("Get comments error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}

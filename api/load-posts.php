<?php
require_once '../config.php';

// Check authentication
if (!isLoggedIn()) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Not authenticated']));
}

// Get parameters
$userId = getCurrentUserId();
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
$profileUserId = isset($_GET['profile_user_id']) ? intval($_GET['profile_user_id']) : 0;

try {
    if ($profileUserId > 0) {
        // Load posts for specific profile
        // Check if user can view profile posts
        $canView = ($profileUserId == $userId);

        if (!$canView) {
            // Check friendship status
            $friendStmt = db()->prepare("
                SELECT status FROM friendships 
                WHERE ((user1_id = ? AND user2_id = ?) OR (user1_id = ? AND user2_id = ?)) 
                AND status = 'accepted'
            ");
            $friendStmt->execute([$userId, $profileUserId, $profileUserId, $userId]);
            $canView = ($friendStmt->fetch() !== false);
        }

        if (!$canView) {
            echo json_encode(['success' => false, 'message' => 'Cannot view profile posts']);
            exit();
        }

        $stmt = db()->prepare("
            SELECT 
                p.*, 
                u.username, 
                u.full_name, 
                u.profile_pic,
                COUNT(DISTINCT l.id) as like_count,
                COUNT(DISTINCT c.id) as comment_count,
                MAX(CASE WHEN l.user_id = ? THEN 1 ELSE 0 END) as user_liked
            FROM posts p
            JOIN users u ON p.user_id = u.id
            LEFT JOIN likes l ON p.id = l.post_id
            LEFT JOIN comments c ON p.id = c.post_id
            WHERE p.user_id = ?
            AND (p.privacy = 'public' OR p.privacy = 'friends')
            GROUP BY p.id
            ORDER BY p.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$userId, $profileUserId, $limit, $offset]);
    } else {
        // Load posts for home feed
        $stmt = db()->prepare("
            SELECT 
                p.*, 
                u.username, 
                u.full_name, 
                u.profile_pic,
                COUNT(DISTINCT l.id) as like_count,
                COUNT(DISTINCT c.id) as comment_count,
                MAX(CASE WHEN l.user_id = ? THEN 1 ELSE 0 END) as user_liked
            FROM posts p
            JOIN users u ON p.user_id = u.id
            LEFT JOIN likes l ON p.id = l.post_id
            LEFT JOIN comments c ON p.id = c.post_id
            WHERE (
                p.user_id = ? -- User's own posts
                OR p.privacy = 'public' -- Public posts
                OR (
                    p.privacy = 'friends' 
                    AND EXISTS (
                        SELECT 1 FROM friendships f 
                        WHERE (
                            (f.user1_id = ? AND f.user2_id = p.user_id) 
                            OR (f.user1_id = p.user_id AND f.user2_id = ?)
                        ) 
                        AND f.status = 'accepted'
                    )
                )
            )
            GROUP BY p.id
            ORDER BY p.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$userId, $userId, $userId, $userId, $limit, $offset]);
    }

    $posts = $stmt->fetchAll();

    // Format posts for response
    foreach ($posts as &$post) {
        $post['formatted_time'] = formatTimeAgo($post['created_at']);
        $post['profile_pic_url'] = getProfilePic($post['user_id']);
        $post['can_delete'] = ($post['user_id'] == $userId);

        if (!empty($post['image'])) {
            $post['image_url'] = BASE_URL . '/uploads/post_images/' . $post['image'];
        }
    }

    // Check if there are more posts
    $totalStmt = db()->prepare(
        "
        SELECT COUNT(*) as total FROM posts 
        WHERE " . ($profileUserId > 0 ? "user_id = $profileUserId" : "1=1")
    );
    $totalStmt->execute();
    $totalResult = $totalStmt->fetch();
    $totalPosts = $totalResult['total'] ?? 0;

    echo json_encode([
        'success' => true,
        'posts' => $posts,
        'has_more' => (($offset + count($posts)) < $totalPosts),
        'total_posts' => $totalPosts
    ]);
} catch (PDOException $e) {
    error_log("Load posts error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}

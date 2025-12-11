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

// Get post ID
$postId = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
$redirectUrl = isset($_GET['redirect']) ? sanitize($_GET['redirect']) : '../../index.php';

if ($postId <= 0) {
    header('Location: ' . $redirectUrl);
    exit();
}

try {
    // Check if post exists and user can like
    $postStmt = db()->prepare("
        SELECT p.*, u.username 
        FROM posts p 
        JOIN users u ON p.user_id = u.id 
        WHERE p.id = ?
    ");
    $postStmt->execute([$postId]);
    $post = $postStmt->fetch();

    if (!$post) {
        $_SESSION['error'] = 'Post not found.';
        header('Location: ' . $redirectUrl);
        exit();
    }

    // Check privacy settings
    $canLike = false;

    if ($post['user_id'] == $currentUser['id']) {
        $canLike = true; // Can like own post
    } elseif ($post['privacy'] === 'public') {
        $canLike = true; // Can like public posts
    } elseif ($post['privacy'] === 'friends') {
        // Check if friends
        $friendStmt = db()->prepare("
            SELECT id FROM friendships 
            WHERE ((user1_id = ? AND user2_id = ?) OR (user1_id = ? AND user2_id = ?)) 
            AND status = 'accepted'
        ");
        $friendStmt->execute([$currentUser['id'], $post['user_id'], $post['user_id'], $currentUser['id']]);
        $canLike = ($friendStmt->fetch() !== false);
    }

    if (!$canLike) {
        $_SESSION['error'] = 'You cannot like this post.';
        header('Location: ' . $redirectUrl);
        exit();
    }

    // Check if already liked
    $checkStmt = db()->prepare("SELECT id FROM likes WHERE user_id = ? AND post_id = ?");
    $checkStmt->execute([$currentUser['id'], $postId]);
    $existingLike = $checkStmt->fetch();

    if ($existingLike) {
        // Unlike
        $deleteStmt = db()->prepare("DELETE FROM likes WHERE user_id = ? AND post_id = ?");
        $deleteStmt->execute([$currentUser['id'], $postId]);
        $_SESSION['success'] = 'Post unliked.';
    } else {
        // Like
        $insertStmt = db()->prepare("INSERT INTO likes (user_id, post_id, created_at) VALUES (?, ?, NOW())");
        $insertStmt->execute([$currentUser['id'], $postId]);

        // Create notification for post owner (if not liking own post)
        if ($post['user_id'] != $currentUser['id']) {
            $notifStmt = db()->prepare("
                INSERT INTO notifications (user_id, type, from_user_id, post_id, created_at) 
                VALUES (?, 'like', ?, ?, NOW())
            ");
            $notifStmt->execute([$post['user_id'], $currentUser['id'], $postId]);
        }

        $_SESSION['success'] = 'Post liked!';
    }
} catch (PDOException $e) {
    error_log("Like error: " . $e->getMessage());
    $_SESSION['error'] = 'Failed to process like.';
}

// Redirect back
header('Location: ' . $redirectUrl);
exit();

<?php
require_once __DIR__ . '/../config.php';

// Check authentication
if (!isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit();
}

// Check if post ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setFlashMessage('error', 'Invalid post ID.');
    header('Location: feed.php');
    exit();
}

$postId = intval($_GET['id']);
$currentUser = getCurrentUser();

// Get post details
try {
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
        WHERE p.id = ?
        GROUP BY p.id
    ");
    $stmt->execute([$currentUser['id'], $postId]);
    $post = $stmt->fetch();

    if (!$post) {
        setFlashMessage('error', 'Post not found or you don\'t have permission to view it.');
        header('Location: feed.php');
        exit();
    }

    // Check privacy
    $canView = false;

    if ($post['user_id'] == $currentUser['id']) {
        $canView = true;
    } elseif ($post['privacy'] == 'public') {
        $canView = true;
    } elseif ($post['privacy'] == 'friends') {
        // Check if they are friends
        $friendCheck = db()->prepare("
            SELECT id FROM friendships 
            WHERE (
                (user1_id = ? AND user2_id = ?) OR 
                (user1_id = ? AND user2_id = ?)
            ) 
            AND status = 'accepted'
            LIMIT 1
        ");
        $friendCheck->execute([$currentUser['id'], $post['user_id'], $post['user_id'], $currentUser['id']]);
        $isFriend = $friendCheck->fetch();
        $canView = ($isFriend !== false);
    }

    if (!$canView) {
        setFlashMessage('error', 'You don\'t have permission to view this post.');
        header('Location: feed.php');
        exit();
    }
} catch (PDOException $e) {
    setFlashMessage('error', 'Failed to load post.');
    header('Location: feed.php');
    exit();
}

// Get comments for this post
try {
    $commentsStmt = db()->prepare("
        SELECT 
            c.*,
            u.username,
            u.full_name,
            u.profile_pic
        FROM comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.post_id = ?
        ORDER BY c.created_at ASC
    ");
    $commentsStmt->execute([$postId]);
    $comments = $commentsStmt->fetchAll();
} catch (PDOException $e) {
    $comments = [];
}

// Get users who liked the post
try {
    $likesStmt = db()->prepare("
        SELECT 
            l.*,
            u.username,
            u.full_name,
            u.profile_pic
        FROM likes l
        JOIN users u ON l.user_id = u.id
        WHERE l.post_id = ?
        ORDER BY l.created_at DESC
        LIMIT 20
    ");
    $likesStmt->execute([$postId]);
    $likes = $likesStmt->fetchAll();
} catch (PDOException $e) {
    $likes = [];
}

// Set page title
$pageTitle = "Post by " . ($post['full_name'] ?? $post['username']) . " - " . SITE_NAME;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body class="bg-gray-50 min-h-screen">
    <!-- Header -->
    <?php include_once '../includes/header.php'; ?>

    <main class="container mx-auto px-4 py-8">
        <div class="max-w-4xl mx-auto">
            <!-- Back Button -->
            <div class="mb-6">
                <a href="feed.php" class="inline-flex items-center text-blue-600 hover:text-blue-800">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Feed
                </a>
            </div>

            <!-- Flash Messages -->
            <?php echo displayFlashMessages(); ?>

            <!-- Main Post -->
            <div class="bg-white rounded-xl shadow-lg border border-gray-200 mb-8">
                <!-- Post Header -->
                <div class="p-6 border-b border-gray-100">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <a href="../profile/view-profile.php?id=<?php echo $post['user_id']; ?>">
                                <img src="<?php echo getProfilePic($post['user_id']); ?>"
                                    alt="<?php echo htmlspecialchars($post['username']); ?>"
                                    class="w-14 h-14 rounded-full object-cover border-2 border-white shadow">
                            </a>
                            <div>
                                <a href="../profile/view-profile.php?id=<?php echo $post['user_id']; ?>"
                                    class="font-bold text-gray-800 text-lg hover:text-blue-600">
                                    <?php echo htmlspecialchars($post['full_name'] ?? $post['username']); ?>
                                </a>
                                <div class="flex items-center text-gray-500 text-sm">
                                    <span><?php echo date('F j, Y \a\t g:i A', strtotime($post['created_at'])); ?></span>
                                    <span class="mx-2">•</span>
                                    <i class="fas fa-<?php echo $post['privacy'] === 'public' ? 'globe' : ($post['privacy'] === 'friends' ? 'users' : 'lock'); ?>"></i>
                                    <span class="ml-1"><?php echo ucfirst($post['privacy']); ?></span>
                                </div>
                            </div>
                        </div>

                        <?php if ($post['user_id'] == $currentUser['id']): ?>
                            <div class="relative group">
                                <button class="p-2 rounded-full hover:bg-gray-100 text-gray-500">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="absolute right-0 mt-1 w-40 bg-white rounded-lg shadow-xl border border-gray-200 hidden group-hover:block z-10">
                                    <a href="edit-post.php?id=<?php echo $post['id']; ?>"
                                        class="block px-4 py-2 text-blue-600 hover:bg-blue-50 rounded-t-lg">
                                        <i class="fas fa-edit mr-2"></i>Edit Post
                                    </a>
                                    <button onclick="deletePost(<?php echo $post['id']; ?>)"
                                        class="block w-full text-left px-4 py-2 text-red-600 hover:bg-red-50 rounded-b-lg">
                                        <i class="fas fa-trash mr-2"></i>Delete Post
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Post Content -->
                <div class="p-6">
                    <div class="prose max-w-none mb-6">
                        <p class="text-gray-800 text-lg whitespace-pre-line"><?php echo nl2br(htmlspecialchars($post['content'])); ?></p>
                    </div>

                    <?php if (!empty($post['image'])): ?>
                        <div class="mb-6 rounded-xl overflow-hidden border border-gray-200">
                            <img src="<?php echo BASE_URL; ?>/uploads/post_images/<?php echo htmlspecialchars($post['image']); ?>"
                                alt="Post image"
                                class="w-full max-h-[600px] object-contain bg-gray-100">
                        </div>
                    <?php endif; ?>

                    <!-- Post Stats -->
                    <div class="flex items-center justify-between text-gray-500 text-sm border-t border-b border-gray-100 py-4">
                        <div class="flex items-center space-x-6">
                            <button onclick="showLikesModal()" class="flex items-center hover:text-blue-600">
                                <i class="fas fa-thumbs-up text-blue-500 mr-1"></i>
                                <span><?php echo $post['like_count']; ?> likes</span>
                            </button>
                            <button onclick="scrollToComments()" class="flex items-center hover:text-green-600">
                                <i class="fas fa-comment text-green-500 mr-1"></i>
                                <span><?php echo $post['comment_count']; ?> comments</span>
                            </button>
                            <span class="flex items-center">
                                <i class="fas fa-eye text-purple-500 mr-1"></i>
                                <span><?php echo rand(50, 500); ?> views</span>
                            </span>
                        </div>
                        <div class="text-xs text-gray-400">
                            Post ID: <?php echo $post['id']; ?>
                        </div>
                    </div>

                    <!-- Post Actions -->
                    <div class="flex items-center justify-around py-4 border-b border-gray-100">
                        <button onclick="toggleLike(<?php echo $post['id']; ?>)"
                            class="flex items-center space-x-2 text-gray-600 hover:text-blue-600 px-5 py-2.5 rounded-lg hover:bg-blue-50 flex-1 justify-center <?php echo $post['user_liked'] ? 'text-blue-600' : ''; ?>">
                            <i class="fas fa-thumbs-up <?php echo $post['user_liked'] ? 'text-blue-600' : ''; ?> text-lg"></i>
                            <span class="font-medium"><?php echo $post['user_liked'] ? 'Liked' : 'Like'; ?></span>
                        </button>

                        <button onclick="scrollToCommentForm()"
                            class="flex items-center space-x-2 text-gray-600 hover:text-green-600 px-5 py-2.5 rounded-lg hover:bg-green-50 flex-1 justify-center">
                            <i class="fas fa-comment text-lg"></i>
                            <span class="font-medium">Comment</span>
                        </button>

                        <button onclick="sharePost(<?php echo $post['id']; ?>)"
                            class="flex items-center space-x-2 text-gray-600 hover:text-purple-600 px-5 py-2.5 rounded-lg hover:bg-purple-50 flex-1 justify-center">
                            <i class="fas fa-share text-lg"></i>
                            <span class="font-medium">Share</span>
                        </button>
                    </div>
                </div>

                <!-- Comments Section -->
                <div id="comments" class="p-6 border-t border-gray-100">
                    <h3 class="text-xl font-bold text-gray-800 mb-6">
                        Comments (<?php echo $post['comment_count']; ?>)
                    </h3>

                    <!-- Add Comment Form -->
                    <div class="mb-8">
                        <div class="flex items-start space-x-3">
                            <img src="<?php echo getProfilePic(); ?>"
                                alt="Your profile"
                                class="w-12 h-12 rounded-full object-cover border-2 border-white shadow">
                            <form method="POST" action="../api/add-comment.php" class="flex-1"
                                onsubmit="return addComment(event, <?php echo $post['id']; ?>)">
                                <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                <textarea name="comment"
                                    id="commentInput"
                                    rows="3"
                                    placeholder="Write a comment..."
                                    class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent resize-none mb-3"></textarea>
                                <div class="flex justify-end">
                                    <button type="submit"
                                        class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium">
                                        <i class="fas fa-paper-plane mr-2"></i>Post Comment
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Comments List -->
                    <div class="space-y-6">
                        <?php if (empty($comments)): ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-comments text-4xl mb-3 text-gray-300"></i>
                                <p>No comments yet. Be the first to comment!</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($comments as $comment): ?>
                                <div class="flex items-start space-x-3 p-4 bg-gray-50 rounded-xl hover:bg-gray-100 transition">
                                    <a href="../profile/view-profile.php?id=<?php echo $comment['user_id']; ?>">
                                        <img src="<?php echo getProfilePic($comment['user_id']); ?>"
                                            alt="<?php echo htmlspecialchars($comment['username']); ?>"
                                            class="w-10 h-10 rounded-full object-cover border-2 border-white shadow">
                                    </a>
                                    <div class="flex-1">
                                        <div class="flex items-center justify-between mb-1">
                                            <div>
                                                <a href="../profile/view-profile.php?id=<?php echo $comment['user_id']; ?>"
                                                    class="font-bold text-gray-800 hover:text-blue-600">
                                                    <?php echo htmlspecialchars($comment['full_name'] ?? $comment['username']); ?>
                                                </a>
                                                <span class="text-gray-500 text-sm ml-2">
                                                    <?php echo formatTimeAgo($comment['created_at']); ?>
                                                </span>
                                            </div>
                                            <?php if ($comment['user_id'] == $currentUser['id'] || $post['user_id'] == $currentUser['id']): ?>
                                                <button onclick="deleteComment(<?php echo $comment['id']; ?>)"
                                                    class="text-red-500 hover:text-red-700 text-sm">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-gray-700"><?php echo nl2br(htmlspecialchars($comment['comment'])); ?></p>

                                        <!-- Comment Actions -->
                                        <div class="flex items-center space-x-4 mt-2 text-sm text-gray-500">
                                            <button class="hover:text-blue-600">
                                                <i class="fas fa-thumbs-up mr-1"></i>Like
                                            </button>
                                            <button class="hover:text-green-600">
                                                <i class="fas fa-reply mr-1"></i>Reply
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Related Posts -->
            <?php
            try {
                $relatedStmt = db()->prepare("
                    SELECT p.*, u.username, u.full_name, u.profile_pic
                    FROM posts p
                    JOIN users u ON p.user_id = u.id
                    WHERE p.user_id = ? AND p.id != ?
                    AND (
                        p.privacy = 'public' 
                        OR (p.privacy = 'friends' AND ? IN (
                            SELECT CASE 
                                WHEN user1_id = ? THEN user2_id 
                                ELSE user1_id 
                            END 
                            FROM friendships 
                            WHERE (user1_id = ? OR user2_id = ?) AND status = 'accepted'
                        ))
                        OR p.user_id = ?
                    )
                    ORDER BY p.created_at DESC
                    LIMIT 3
                ");
                $relatedStmt->execute([
                    $post['user_id'],
                    $postId,
                    $currentUser['id'],
                    $currentUser['id'],
                    $currentUser['id'],
                    $currentUser['id'],
                    $currentUser['id']
                ]);
                $relatedPosts = $relatedStmt->fetchAll();
            } catch (PDOException $e) {
                $relatedPosts = [];
            }
            ?>

            <?php if (!empty($relatedPosts)): ?>
                <div class="mt-8">
                    <h3 class="text-xl font-bold text-gray-800 mb-4">More from <?php echo htmlspecialchars($post['full_name'] ?? $post['username']); ?></h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <?php foreach ($relatedPosts as $related): ?>
                            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 hover:shadow-md transition">
                                <div class="flex items-center space-x-3 mb-3">
                                    <img src="<?php echo getProfilePic($related['user_id']); ?>"
                                        alt="<?php echo htmlspecialchars($related['username']); ?>"
                                        class="w-10 h-10 rounded-full object-cover">
                                    <div>
                                        <p class="font-medium text-gray-800">
                                            <?php echo htmlspecialchars($related['full_name'] ?? $related['username']); ?>
                                        </p>
                                        <p class="text-xs text-gray-500">
                                            <?php echo formatTimeAgo($related['created_at']); ?>
                                        </p>
                                    </div>
                                </div>
                                <p class="text-gray-700 text-sm line-clamp-3 mb-3">
                                    <?php echo htmlspecialchars(substr($related['content'], 0, 150)); ?>
                                    <?php if (strlen($related['content']) > 150): ?>...<?php endif; ?>
                                </p>
                                <a href="view-post.php?id=<?php echo $related['id']; ?>"
                                    class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                    Read more <i class="fas fa-arrow-right ml-1"></i>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Likes Modal -->
    <div id="likesModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50 hidden">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-md max-h-[80vh] overflow-y-auto">
            <div class="p-6">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-xl font-bold text-gray-800">Liked by</h3>
                    <button onclick="closeLikesModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-2xl"></i>
                    </button>
                </div>

                <div class="space-y-3">
                    <?php if (empty($likes)): ?>
                        <p class="text-gray-500 text-center py-4">No likes yet</p>
                    <?php else: ?>
                        <?php foreach ($likes as $like): ?>
                            <div class="flex items-center justify-between p-3 hover:bg-gray-50 rounded-lg">
                                <div class="flex items-center">
                                    <img src="<?php echo getProfilePic($like['user_id']); ?>"
                                        alt="<?php echo htmlspecialchars($like['username']); ?>"
                                        class="w-10 h-10 rounded-full object-cover mr-3">
                                    <div>
                                        <p class="font-medium text-gray-800">
                                            <?php echo htmlspecialchars($like['full_name'] ?? $like['username']); ?>
                                        </p>
                                        <p class="text-xs text-gray-500">
                                            <?php echo formatTimeAgo($like['created_at']); ?>
                                        </p>
                                    </div>
                                </div>
                                <a href="../profile/view-profile.php?id=<?php echo $like['user_id']; ?>"
                                    class="text-blue-600 hover:text-blue-800 text-sm">
                                    View Profile
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php include_once '../includes/footer.php'; ?>

    <script>
        // Scroll to comments
        function scrollToComments() {
            document.getElementById('comments').scrollIntoView({
                behavior: 'smooth'
            });
        }

        function scrollToCommentForm() {
            document.getElementById('commentInput').scrollIntoView({
                behavior: 'smooth'
            });
            document.getElementById('commentInput').focus();
        }

        // Like/Unlike function
        async function toggleLike(postId) {
            try {
                const response = await fetch('../api/like-post.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        post_id: postId,
                        csrf_token: '<?php echo generateCsrfToken(); ?>'
                    })
                });

                const result = await response.json();

                if (result.success) {
                    location.reload();
                } else {
                    alert(result.message || 'Error liking post');
                }
            } catch (error) {
                alert('Network error. Please try again.');
            }
        }

        // Share post function
        async function sharePost(postId) {
            if (!confirm('Share this post?')) return;

            try {
                const response = await fetch('../interactions/shares/share.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        post_id: postId,
                        csrf_token: '<?php echo generateCsrfToken(); ?>'
                    })
                });

                const result = await response.json();
                alert(result.message);

                if (result.success) {
                    location.reload();
                }
            } catch (error) {
                alert('Network error. Please try again.');
            }
        }

        // Delete post function
        async function deletePost(postId) {
            if (!confirm('Are you sure you want to delete this post? This action cannot be undone.')) return;

            try {
                const response = await fetch('delete-post.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        post_id: postId,
                        csrf_token: '<?php echo generateCsrfToken(); ?>'
                    })
                });

                const result = await response.json();

                if (result.success) {
                    alert('Post deleted successfully!');
                    window.location.href = 'feed.php';
                } else {
                    alert(result.message || 'Error deleting post');
                }
            } catch (error) {
                alert('Network error. Please try again.');
            }
        }

        // Add comment function
        async function addComment(event, postId) {
            event.preventDefault();

            const form = event.target;
            const commentInput = form.querySelector('textarea[name="comment"]');
            const comment = commentInput.value.trim();

            if (!comment) {
                alert('Please enter a comment');
                return false;
            }

            try {
                const formData = new FormData(form);

                const response = await fetch(form.action, {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    commentInput.value = '';
                    location.reload();
                } else {
                    alert(result.message || 'Error adding comment');
                }
            } catch (error) {
                alert('Network error. Please try again.');
            }

            return false;
        }

        // Delete comment function
        async function deleteComment(commentId) {
            if (!confirm('Delete this comment?')) return;

            try {
                const response = await fetch('../interactions/comments/delete-comments.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        comment_id: commentId,
                        csrf_token: '<?php echo generateCsrfToken(); ?>'
                    })
                });

                const result = await response.json();

                if (result.success) {
                    location.reload();
                } else {
                    alert(result.message || 'Error deleting comment');
                }
            } catch (error) {
                alert('Network error. Please try again.');
            }
        }

        // Likes modal functions
        function showLikesModal() {
            document.getElementById('likesModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeLikesModal() {
            document.getElementById('likesModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        // Close modal when clicking outside
        document.getElementById('likesModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeLikesModal();
            }
        });
    </script>
</body>

</html>
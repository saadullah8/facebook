<?php
require_once __DIR__ . '/../config.php';

// Check authentication
if (!isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit();
}

$currentUser = getCurrentUser();

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Get total posts count for pagination
try {
    $countStmt = db()->prepare("
        SELECT COUNT(*) as total 
        FROM posts p
        WHERE (
            p.user_id = ? 
            OR p.privacy = 'public'
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
    ");
    $countStmt->execute([$currentUser['id'], $currentUser['id'], $currentUser['id']]);
    $totalResult = $countStmt->fetch();
    $totalPosts = $totalResult['total'] ?? 0;
    $totalPages = ceil($totalPosts / $limit);
} catch (PDOException $e) {
    $totalPosts = 0;
    $totalPages = 1;
}

// Get posts for feed
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
        WHERE (
            p.user_id = ? 
            OR p.privacy = 'public'
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
    $stmt->bindValue(1, $currentUser['id'], PDO::PARAM_INT);
    $stmt->bindValue(2, $currentUser['id'], PDO::PARAM_INT);
    $stmt->bindValue(3, $currentUser['id'], PDO::PARAM_INT);
    $stmt->bindValue(4, $currentUser['id'], PDO::PARAM_INT);
    $stmt->bindValue(5, $limit, PDO::PARAM_INT);
    $stmt->bindValue(6, $offset, PDO::PARAM_INT);
    $stmt->execute();

    $posts = $stmt->fetchAll();
} catch (PDOException $e) {
    $posts = [];
    setFlashMessage('error', 'Failed to load posts.');
}

// Set page title
$pageTitle = "Feed - " . SITE_NAME;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    animation: {
                        'fade-in': 'fadeIn 0.5s ease-in-out',
                        'slide-up': 'slideUp 0.3s ease-out'
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': {
                                opacity: '0'
                            },
                            '100%': {
                                opacity: '1'
                            }
                        },
                        slideUp: {
                            '0%': {
                                transform: 'translateY(10px)',
                                opacity: '0'
                            },
                            '100%': {
                                transform: 'translateY(0)',
                                opacity: '1'
                            }
                        }
                    }
                }
            }
        }
    </script>
</head>

<body class="bg-gray-50 min-h-screen">
    <!-- Header -->
    <?php include_once '../includes/header.php'; ?>

    <main class="container mx-auto px-4 py-8">
        <div class="max-w-4xl mx-auto">
            <!-- Page Header -->
            <div class="mb-8">
                <div class="flex justify-between items-center">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-800">News Feed</h1>
                        <p class="text-gray-600">Latest posts from you and your friends</p>
                    </div>
                    <a href="create-post.php"
                        class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg font-medium">
                        <i class="fas fa-plus mr-2"></i>Create Post
                    </a>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php echo displayFlashMessages(); ?>

            <!-- Posts Feed -->
            <div class="space-y-6">
                <?php if (empty($posts)): ?>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-8 text-center">
                        <i class="fas fa-newspaper text-gray-300 text-6xl mb-4"></i>
                        <h3 class="text-xl font-bold text-gray-700 mb-2">No posts yet</h3>
                        <p class="text-gray-500 mb-4">Be the first to share something!</p>
                        <a href="create-post.php" class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-medium">
                            <i class="fas fa-plus mr-2"></i>Create First Post
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($posts as $post): ?>
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 animate-fade-in">
                            <!-- Post Header -->
                            <div class="flex items-center justify-between mb-4">
                                <div class="flex items-center space-x-3">
                                    <a href="../profile/view-profile.php?id=<?php echo $post['user_id']; ?>">
                                        <img src="<?php echo getProfilePic($post['user_id']); ?>"
                                            alt="<?php echo htmlspecialchars($post['username']); ?>"
                                            class="w-12 h-12 rounded-full object-cover border-2 border-white shadow">
                                    </a>
                                    <div>
                                        <a href="../profile/view-profile.php?id=<?php echo $post['user_id']; ?>"
                                            class="font-bold text-gray-800 hover:text-blue-600">
                                            <?php echo htmlspecialchars($post['full_name'] ?? $post['username']); ?>
                                        </a>
                                        <div class="flex items-center text-gray-500 text-sm">
                                            <span><?php echo date('M j, Y \a\t g:i A', strtotime($post['created_at'])); ?></span>
                                            <span class="mx-2">•</span>
                                            <i class="fas fa-<?php echo $post['privacy'] === 'public' ? 'globe' : ($post['privacy'] === 'friends' ? 'users' : 'lock'); ?> text-xs"></i>
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
                                            <a href="view-post.php?id=<?php echo $post['id']; ?>"
                                                class="block px-4 py-2 text-gray-700 hover:bg-gray-100 rounded-t-lg">
                                                <i class="fas fa-eye mr-2"></i>View Post
                                            </a>
                                            <a href="edit-post.php?id=<?php echo $post['id']; ?>"
                                                class="block px-4 py-2 text-blue-600 hover:bg-blue-50">
                                                <i class="fas fa-edit mr-2"></i>Edit
                                            </a>
                                            <button onclick="deletePost(<?php echo $post['id']; ?>)"
                                                class="block w-full text-left px-4 py-2 text-red-600 hover:bg-red-50 rounded-b-lg">
                                                <i class="fas fa-trash mr-2"></i>Delete
                                            </button>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Post Content -->
                            <div class="mb-6">
                                <p class="text-gray-800 whitespace-pre-line text-lg"><?php echo nl2br(htmlspecialchars($post['content'])); ?></p>
                                <?php if (!empty($post['image'])): ?>
                                    <div class="mt-4 rounded-lg overflow-hidden">
                                        <img src="<?php echo BASE_URL; ?>/uploads/post_images/<?php echo htmlspecialchars($post['image']); ?>"
                                            alt="Post image"
                                            class="w-full max-h-96 object-contain bg-gray-100">
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Post Stats -->
                            <div class="flex items-center justify-between text-gray-500 text-sm border-t border-b border-gray-100 py-3 mb-3">
                                <div class="flex items-center space-x-6">
                                    <span class="flex items-center">
                                        <i class="fas fa-thumbs-up text-blue-500 mr-1"></i>
                                        <?php echo $post['like_count']; ?> likes
                                    </span>
                                    <span class="flex items-center">
                                        <i class="fas fa-comment text-green-500 mr-1"></i>
                                        <?php echo $post['comment_count']; ?> comments
                                    </span>
                                    <span class="flex items-center">
                                        <i class="fas fa-share text-purple-500 mr-1"></i>
                                        0 shares
                                    </span>
                                </div>
                                <a href="view-post.php?id=<?php echo $post['id']; ?>" class="text-blue-600 hover:text-blue-800 text-sm">
                                    <i class="fas fa-external-link-alt mr-1"></i>View Full Post
                                </a>
                            </div>

                            <!-- Post Actions -->
                            <div class="flex items-center justify-around border-b border-gray-100 pb-4 mb-4">
                                <button onclick="toggleLike(<?php echo $post['id']; ?>)"
                                    class="flex items-center space-x-2 text-gray-600 hover:text-blue-600 px-4 py-2 rounded-lg hover:bg-blue-50 <?php echo $post['user_liked'] ? 'text-blue-600' : ''; ?>">
                                    <i class="fas fa-thumbs-up <?php echo $post['user_liked'] ? 'text-blue-600' : ''; ?>"></i>
                                    <span class="font-medium"><?php echo $post['user_liked'] ? 'Liked' : 'Like'; ?></span>
                                </button>
                                <a href="view-post.php?id=<?php echo $post['id']; ?>#comments"
                                    class="flex items-center space-x-2 text-gray-600 hover:text-green-600 px-4 py-2 rounded-lg hover:bg-green-50">
                                    <i class="fas fa-comment"></i>
                                    <span class="font-medium">Comment</span>
                                </a>
                                <button onclick="sharePost(<?php echo $post['id']; ?>)"
                                    class="flex items-center space-x-2 text-gray-600 hover:text-purple-600 px-4 py-2 rounded-lg hover:bg-purple-50">
                                    <i class="fas fa-share"></i>
                                    <span class="font-medium">Share</span>
                                </button>
                            </div>

                            <!-- Quick Comment -->
                            <div class="flex items-center space-x-3">
                                <img src="<?php echo getProfilePic(); ?>"
                                    alt="Your profile"
                                    class="w-10 h-10 rounded-full object-cover">
                                <form method="POST" action="../api/add-comment.php" class="flex-1"
                                    onsubmit="return addQuickComment(event, <?php echo $post['id']; ?>)">
                                    <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                    <input type="text"
                                        name="comment"
                                        placeholder="Write a comment..."
                                        class="w-full px-4 py-2 bg-gray-100 border border-gray-300 rounded-full focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="mt-8 flex justify-center">
                    <div class="inline-flex items-center space-x-2 bg-white rounded-lg shadow-sm border border-gray-200 p-2">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>"
                                class="px-4 py-2 text-gray-700 hover:bg-gray-100 rounded-lg">
                                <i class="fas fa-chevron-left mr-2"></i>Previous
                            </a>
                        <?php endif; ?>

                        <div class="flex items-center space-x-1">
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <?php if ($i == $page): ?>
                                    <span class="px-4 py-2 bg-blue-600 text-white rounded-lg font-medium">
                                        <?php echo $i; ?>
                                    </span>
                                <?php else: ?>
                                    <a href="?page=<?php echo $i; ?>"
                                        class="px-4 py-2 text-gray-700 hover:bg-gray-100 rounded-lg">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </div>

                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?php echo $page + 1; ?>"
                                class="px-4 py-2 text-gray-700 hover:bg-gray-100 rounded-lg">
                                Next<i class="fas fa-chevron-right ml-2"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php include_once '../includes/footer.php'; ?>

    <script>
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
            if (!confirm('Are you sure you want to delete this post?')) return;

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
                    location.reload();
                } else {
                    alert(result.message || 'Error deleting post');
                }
            } catch (error) {
                alert('Network error. Please try again.');
            }
        }

        // Add quick comment
        async function addQuickComment(event, postId) {
            event.preventDefault();

            const form = event.target;
            const commentInput = form.querySelector('input[name="comment"]');
            const comment = commentInput.value.trim();

            if (!comment) return false;

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
    </script>
</body>

</html>
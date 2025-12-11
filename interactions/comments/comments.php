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

$errors = [];
$success = '';

// Handle comment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $postId = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $comment = sanitize($_POST['comment'] ?? '');
        $redirectUrl = sanitize($_POST['redirect_url'] ?? '../../index.php');

        // Validate inputs
        if ($postId <= 0) {
            $errors[] = 'Invalid post.';
        }

        if (empty($comment)) {
            $errors[] = 'Comment cannot be empty.';
        }

        if (strlen($comment) > 1000) {
            $errors[] = 'Comment is too long (max 1000 characters).';
        }

        // If no errors, add comment
        if (empty($errors)) {
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
                    $errors[] = 'Post not found.';
                } else {
                    // Check privacy settings
                    $canComment = false;

                    if ($post['user_id'] == $currentUser['id']) {
                        $canComment = true; // Can comment on own post
                    } elseif ($post['privacy'] === 'public') {
                        $canComment = true; // Can comment on public posts
                    } elseif ($post['privacy'] === 'friends') {
                        // Check if friends
                        $friendStmt = db()->prepare("
                            SELECT id FROM friendships 
                            WHERE ((user1_id = ? AND user2_id = ?) OR (user1_id = ? AND user2_id = ?)) 
                            AND status = 'accepted'
                        ");
                        $friendStmt->execute([$currentUser['id'], $post['user_id'], $post['user_id'], $currentUser['id']]);
                        $canComment = ($friendStmt->fetch() !== false);
                    }

                    if (!$canComment) {
                        $errors[] = 'You cannot comment on this post.';
                    } else {
                        // Insert comment
                        $insertStmt = db()->prepare("
                            INSERT INTO comments (user_id, post_id, comment, created_at) 
                            VALUES (?, ?, ?, NOW())
                        ");
                        $insertStmt->execute([$currentUser['id'], $postId, $comment]);

                        // Create notification for post owner (if not commenting on own post)
                        if ($post['user_id'] != $currentUser['id']) {
                            $notifStmt = db()->prepare("
                                INSERT INTO notifications (user_id, type, from_user_id, post_id, created_at) 
                                VALUES (?, 'comment', ?, ?, NOW())
                            ");
                            $notifStmt->execute([$post['user_id'], $currentUser['id'], $postId]);
                        }

                        $success = 'Comment added successfully!';

                        // Redirect back
                        header("Location: $redirectUrl");
                        exit();
                    }
                }
            } catch (PDOException $e) {
                error_log("Add comment error: " . $e->getMessage());
                $errors[] = 'Failed to add comment. Please try again.';
            }
        }
    }
}

// If GET request or error, show form
$postId = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
$redirectUrl = isset($_GET['redirect']) ? sanitize($_GET['redirect']) : '../../index.php';

// Get post info
$postInfo = null;
if ($postId > 0) {
    try {
        $stmt = db()->prepare("
            SELECT p.*, u.username, u.full_name 
            FROM posts p 
            JOIN users u ON p.user_id = u.id 
            WHERE p.id = ?
        ");
        $stmt->execute([$postId]);
        $postInfo = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Get post error: " . $e->getMessage());
    }
}

if (!$postInfo) {
    header('Location: ../../index.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Comment - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body class="bg-gray-50">
    <!-- Navigation -->
    <?php include '../../includes/header.php'; ?>

    <main class="container mx-auto px-4 py-8 max-w-2xl">
        <!-- Back Button -->
        <div class="mb-6">
            <a href="<?php echo htmlspecialchars($redirectUrl); ?>"
                class="inline-flex items-center text-blue-600 hover:text-blue-800">
                <i class="fas fa-arrow-left mr-2"></i> Back
            </a>
        </div>

        <!-- Post Preview -->
        <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
            <div class="flex items-start space-x-3">
                <img src="<?php echo getProfilePic($postInfo['user_id']); ?>"
                    alt="<?php echo htmlspecialchars($postInfo['full_name']); ?>"
                    class="w-10 h-10 rounded-full object-cover">
                <div class="flex-1">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="font-bold text-gray-800">
                                <?php echo htmlspecialchars($postInfo['full_name']); ?>
                            </h3>
                            <p class="text-gray-500 text-sm">
                                <?php echo formatTimeAgo($postInfo['created_at']); ?>
                            </p>
                        </div>
                        <span class="text-xs px-2 py-1 bg-gray-100 text-gray-600 rounded-full">
                            <i class="fas fa-<?php echo $postInfo['privacy'] === 'public' ? 'globe' : ($postInfo['privacy'] === 'friends' ? 'users' : 'lock'); ?> mr-1"></i>
                            <?php echo ucfirst($postInfo['privacy']); ?>
                        </span>
                    </div>
                    <div class="mt-3">
                        <p class="text-gray-800 whitespace-pre-line"><?php echo nl2br(htmlspecialchars($postInfo['content'])); ?></p>
                        <?php if (!empty($postInfo['image'])): ?>
                            <div class="mt-4">
                                <img src="../../uploads/post_images/<?php echo htmlspecialchars($postInfo['image']); ?>"
                                    alt="Post image"
                                    class="w-full rounded-lg max-h-64 object-cover">
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Comment Form -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h1 class="text-2xl font-bold text-gray-900 mb-6">Add Comment</h1>

            <!-- Error Messages -->
            <?php if (!empty($errors)): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-circle text-red-500 mr-3 mt-1"></i>
                        <div>
                            <p class="text-red-700 font-medium">Please fix the following errors:</p>
                            <ul class="mt-2 text-red-600 list-disc list-inside">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Success Message -->
            <?php if ($success): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle text-green-500 mr-3"></i>
                        <p class="text-green-700"><?php echo htmlspecialchars($success); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Form -->
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="post_id" value="<?php echo $postId; ?>">
                <input type="hidden" name="redirect_url" value="<?php echo htmlspecialchars($redirectUrl); ?>">

                <div class="mb-6">
                    <label for="comment" class="block text-sm font-medium text-gray-700 mb-2">
                        Your Comment
                    </label>
                    <textarea id="comment"
                        name="comment"
                        rows="4"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition resize-none"
                        placeholder="Write your comment here..."
                        required><?php echo htmlspecialchars($_POST['comment'] ?? ''); ?></textarea>
                    <div class="flex justify-between items-center mt-1">
                        <p class="text-gray-500 text-sm">Share your thoughts</p>
                        <span id="charCount" class="text-sm text-gray-500">0/1000</span>
                    </div>
                </div>

                <div class="flex items-center justify-between pt-6 border-t border-gray-200">
                    <a href="<?php echo htmlspecialchars($redirectUrl); ?>"
                        class="px-6 py-3 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition font-medium">
                        Cancel
                    </a>
                    <button type="submit"
                        class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-medium">
                        <i class="fas fa-paper-plane mr-2"></i> Post Comment
                    </button>
                </div>
            </form>
        </div>

        <!-- Recent Comments -->
        <div class="bg-white rounded-xl shadow-sm p-6 mt-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Recent Comments</h3>
            <div class="space-y-4">
                <?php
                try {
                    $stmt = db()->prepare("
                        SELECT c.*, u.username, u.full_name, u.profile_pic 
                        FROM comments c 
                        JOIN users u ON c.user_id = u.id 
                        WHERE c.post_id = ? 
                        ORDER BY c.created_at DESC 
                        LIMIT 5
                    ");
                    $stmt->execute([$postId]);
                    $comments = $stmt->fetchAll();

                    if (empty($comments)): ?>
                        <div class="text-center py-6">
                            <i class="fas fa-comment text-gray-300 text-3xl mb-3"></i>
                            <p class="text-gray-500">No comments yet. Be the first to comment!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($comments as $comment): ?>
                            <div class="flex items-start space-x-3 p-3 hover:bg-gray-50 rounded-lg transition">
                                <img src="<?php echo getProfilePic($comment['user_id']); ?>"
                                    alt="<?php echo htmlspecialchars($comment['full_name']); ?>"
                                    class="w-8 h-8 rounded-full object-cover">
                                <div class="flex-1">
                                    <div class="flex items-center justify-between">
                                        <h4 class="font-medium text-gray-800">
                                            <?php echo htmlspecialchars($comment['full_name']); ?>
                                        </h4>
                                        <span class="text-gray-500 text-sm">
                                            <?php echo formatTimeAgo($comment['created_at']); ?>
                                        </span>
                                    </div>
                                    <p class="text-gray-700 mt-1"><?php echo nl2br(htmlspecialchars($comment['comment'])); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                <?php endif;
                } catch (PDOException $e) {
                    echo '<p class="text-gray-500 text-center py-4">Error loading comments.</p>';
                }
                ?>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <?php include '../../includes/footer.php'; ?>

    <script>
        // Character counter
        const commentTextarea = document.getElementById('comment');
        const charCount = document.getElementById('charCount');

        function updateCharCount() {
            const length = commentTextarea.value.length;
            charCount.textContent = `${length}/1000`;

            if (length > 1000) {
                charCount.classList.remove('text-gray-500');
                charCount.classList.add('text-red-500');
            } else {
                charCount.classList.remove('text-red-500');
                charCount.classList.add('text-gray-500');
            }
        }

        commentTextarea.addEventListener('input', updateCharCount);
        updateCharCount(); // Initial call
    </script>
</body>

</html>
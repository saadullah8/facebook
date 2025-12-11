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

// Handle share submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $postId = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $content = sanitize($_POST['content'] ?? '');
        $privacy = sanitize($_POST['privacy'] ?? 'public');

        // Validate inputs
        if ($postId <= 0) {
            $errors[] = 'Invalid post.';
        }

        // Get original post
        try {
            $postStmt = db()->prepare("
                SELECT p.*, u.username, u.full_name 
                FROM posts p 
                JOIN users u ON p.user_id = u.id 
                WHERE p.id = ?
            ");
            $postStmt->execute([$postId]);
            $originalPost = $postStmt->fetch();

            if (!$originalPost) {
                $errors[] = 'Original post not found.';
            } else {
                // Check if user can share
                $canShare = false;

                if ($originalPost['user_id'] == $currentUser['id']) {
                    $canShare = true; // Can share own post
                } elseif ($originalPost['privacy'] === 'public') {
                    $canShare = true; // Can share public posts
                } elseif ($originalPost['privacy'] === 'friends') {
                    // Check if friends
                    $friendStmt = db()->prepare("
                        SELECT id FROM friendships 
                        WHERE ((user1_id = ? AND user2_id = ?) OR (user1_id = ? AND user2_id = ?)) 
                        AND status = 'accepted'
                    ");
                    $friendStmt->execute([$currentUser['id'], $originalPost['user_id'], $originalPost['user_id'], $currentUser['id']]);
                    $canShare = ($friendStmt->fetch() !== false);
                }

                if (!$canShare) {
                    $errors[] = 'You cannot share this post.';
                }
            }
        } catch (PDOException $e) {
            error_log("Get post error: " . $e->getMessage());
            $errors[] = 'Failed to load post.';
        }

        // If no errors, create share post
        if (empty($errors)) {
            try {
                // Create new post (share)
                $shareContent = "Shared " . ($originalPost['full_name'] ?? $originalPost['username']) . "'s post:\n\n";
                $shareContent .= $originalPost['content'];

                if (!empty($content)) {
                    $shareContent .= "\n\n" . $currentUser['full_name'] . " said: " . $content;
                }

                // Insert shared post
                $insertStmt = db()->prepare("
                    INSERT INTO posts (user_id, content, image, privacy, created_at) 
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $insertStmt->execute([
                    $currentUser['id'],
                    $shareContent,
                    $originalPost['image'], // Share the image too
                    $privacy
                ]);

                $newPostId = db()->lastInsertId();

                // Create notification for original post owner
                if ($originalPost['user_id'] != $currentUser['id']) {
                    $notifStmt = db()->prepare("
                        INSERT INTO notifications (user_id, type, from_user_id, post_id, created_at) 
                        VALUES (?, 'share', ?, ?, NOW())
                    ");
                    $notifStmt->execute([$originalPost['user_id'], $currentUser['id'], $newPostId]);
                }

                $success = 'Post shared successfully!';

                // Redirect to new post
                header("Location: ../../index.php?share_success=1");
                exit();
            } catch (PDOException $e) {
                error_log("Share error: " . $e->getMessage());
                $errors[] = 'Failed to share post. Please try again.';
            }
        }
    }
}

// If GET request, show form
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
    header('Location: ' . $redirectUrl);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Share Post - <?php echo SITE_NAME; ?></title>
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

        <!-- Original Post -->
        <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
            <h2 class="text-lg font-bold text-gray-800 mb-4">Original Post</h2>
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

        <!-- Share Form -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h1 class="text-2xl font-bold text-gray-900 mb-6">Share This Post</h1>

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

                <div class="mb-6">
                    <label for="content" class="block text-sm font-medium text-gray-700 mb-2">
                        Add a comment (optional)
                    </label>
                    <textarea id="content"
                        name="content"
                        rows="3"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition resize-none"
                        placeholder="Add your thoughts about this post..."><?php echo htmlspecialchars($_POST['content'] ?? ''); ?></textarea>
                    <p class="text-gray-500 text-sm mt-1">This will be added to your shared post</p>
                </div>

                <div class="mb-6">
                    <label for="privacy" class="block text-sm font-medium text-gray-700 mb-2">
                        Privacy Settings
                    </label>
                    <select id="privacy"
                        name="privacy"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition">
                        <option value="public">
                            <i class="fas fa-globe"></i> Public - Anyone can see this
                        </option>
                        <option value="friends">
                            <i class="fas fa-users"></i> Friends - Only friends can see this
                        </option>
                        <option value="private">
                            <i class="fas fa-lock"></i> Only Me - Only you can see this
                        </option>
                    </select>
                </div>

                <div class="flex items-center justify-between pt-6 border-t border-gray-200">
                    <a href="<?php echo htmlspecialchars($redirectUrl); ?>"
                        class="px-6 py-3 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition font-medium">
                        Cancel
                    </a>
                    <button type="submit"
                        class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-medium">
                        <i class="fas fa-share mr-2"></i> Share Post
                    </button>
                </div>
            </form>
        </div>
    </main>

    <!-- Footer -->
    <?php include '../../includes/footer.php'; ?>
</body>

</html>
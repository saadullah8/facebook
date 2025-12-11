<?php
// index.php - HOME PAGE
require_once __DIR__ . '/config.php';

// Check authentication - SIMPLIFIED
if (!isLoggedIn()) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header('Location: auth/login.php');
    exit();
}

// Get current user
$currentUser = getCurrentUser();
if (!$currentUser) {
    header('Location: auth/logout.php');
    exit();
}

// Handle new post creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_post'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        setFlashMessage('error', 'Invalid security token.');
    } else {
        $content = sanitize($_POST['content'] ?? '');
        $privacy = sanitize($_POST['privacy'] ?? 'public');

        // Validate post content
        if (empty($content)) {
            setFlashMessage('error', 'Post content cannot be empty.');
        } else {
            try {
                // Handle image upload if present
                $imageFilename = null;
                if (isset($_FILES['post_image']) && $_FILES['post_image']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $uploadResult = validateFileUpload($_FILES['post_image'], 'post_image');
                    if ($uploadResult['success']) {
                        $imageFilename = $uploadResult['filename'];
                    } else {
                        setFlashMessage('error', $uploadResult['message']);
                    }
                }

                // Insert post into database
                $stmt = db()->prepare("INSERT INTO posts (user_id, content, image, privacy, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$currentUser['id'], $content, $imageFilename, $privacy]);

                setFlashMessage('success', 'Post created successfully!');

                // Redirect to clear POST data
                header('Location: index.php');
                exit();
            } catch (PDOException $e) {
                error_log("Create post error: " . $e->getMessage());
                setFlashMessage('error', 'Failed to create post. Please try again.');
            }
        }
    }
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
        LIMIT 20
    ");
    $stmt->execute([$currentUser['id'], $currentUser['id'], $currentUser['id'], $currentUser['id']]);
    $posts = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Get posts error: " . $e->getMessage());
    $posts = [];
    setFlashMessage('error', 'Failed to load posts.');
}

// Get friend suggestions
try {
    $stmt = db()->prepare("
        SELECT u.id, u.username, u.full_name, u.profile_pic, u.bio
        FROM users u
        WHERE u.id != ?
        AND u.id NOT IN (
            SELECT 
                CASE 
                    WHEN user1_id = ? THEN user2_id
                    ELSE user1_id
                END
            FROM friendships 
            WHERE user1_id = ? OR user2_id = ?
        )
        ORDER BY RAND()
        LIMIT 5
    ");
    $stmt->execute([$currentUser['id'], $currentUser['id'], $currentUser['id'], $currentUser['id']]);
    $friendSuggestions = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Get friend suggestions error: " . $e->getMessage());
    $friendSuggestions = [];
}

// Get unread messages count
$unreadMessagesCount = getUnreadMessagesCount();

// Get pending friend requests count
$pendingRequestsCount = getPendingFriendRequestsCount();

// Get unread notifications count
try {
    $stmt = db()->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$currentUser['id']]);
    $unreadNotifications = $stmt->fetch();
    $unreadNotificationsCount = $unreadNotifications['count'] ?? 0;
} catch (PDOException $e) {
    $unreadNotificationsCount = 0;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#3b82f6',
                        secondary: '#8b5cf6',
                        dark: '#1f2937',
                        light: '#f9fafb'
                    },
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body class="bg-gray-50 min-h-screen">
    <!-- Navigation Header -->
    <header class="bg-white shadow-md sticky top-0 z-50">
        <div class="container mx-auto px-4 py-3">
            <div class="flex items-center justify-between">
                <!-- Logo -->
                <div class="flex items-center space-x-2">
                    <div class="bg-gradient-to-r from-blue-500 to-purple-600 p-2 rounded-lg">
                        <i class="fas fa-users text-white text-xl"></i>
                    </div>
                    <a href="index.php" class="text-xl font-bold text-gray-800"><?php echo SITE_NAME; ?></a>
                </div>

                <!-- Search Bar -->
                <div class="hidden md:flex flex-1 max-w-xl mx-8">
                    <div class="relative w-full">
                        <input type="text" placeholder="Search..." class="w-full px-4 py-2 pl-10 bg-gray-100 border border-gray-300 rounded-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    </div>
                </div>

                <!-- Navigation Icons -->
                <div class="flex items-center space-x-4">
                    <!-- Home -->
                    <a href="index.php" class="p-2 rounded-full hover:bg-gray-100 text-blue-600">
                        <i class="fas fa-home text-xl"></i>
                    </a>

                    <!-- Friend Requests -->
                    <div class="relative">
                        <a href="friends/friend-requests.php" class="p-2 rounded-full hover:bg-gray-100 text-gray-600">
                            <i class="fas fa-user-friends text-xl"></i>
                            <?php if ($pendingRequestsCount > 0): ?>
                                <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center">
                                    <?php echo $pendingRequestsCount; ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    </div>

                    <!-- Messages -->
                    <div class="relative">
                        <a href="messages/inbox.php" class="p-2 rounded-full hover:bg-gray-100 text-gray-600">
                            <i class="fas fa-envelope text-xl"></i>
                            <?php if ($unreadMessagesCount > 0): ?>
                                <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center">
                                    <?php echo $unreadMessagesCount; ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    </div>

                    <!-- Notifications -->
                    <div class="relative">
                        <a href="interactions/notifications/notifications.php" class="p-2 rounded-full hover:bg-gray-100 text-gray-600">
                            <i class="fas fa-bell text-xl"></i>
                            <?php if ($unreadNotificationsCount > 0): ?>
                                <span class="absolute -top-1 -right-1 bg-yellow-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center">
                                    <?php echo $unreadNotificationsCount; ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    </div>

                    <!-- User Profile Dropdown -->
                    <div class="relative group">
                        <button class="flex items-center space-x-2 focus:outline-none">
                            <img src="<?php echo getProfilePic(); ?>" alt="Profile" class="w-8 h-8 rounded-full object-cover border-2 border-gray-300">
                            <span class="hidden md:inline text-sm font-medium text-gray-700">
                                <?php echo htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']); ?>
                            </span>
                            <i class="fas fa-chevron-down text-gray-500"></i>
                        </button>

                        <!-- Dropdown Menu -->
                        <div class="absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-xl border border-gray-200 hidden group-hover:block animate-fade-in z-50">
                            <div class="py-2">
                                <a href="profile/profile.php" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-user-circle mr-3 text-gray-400"></i>My Profile
                                </a>
                                <a href="friends/friends.php" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-users mr-3 text-gray-400"></i>Friends
                                </a>
                                <a href="interactions/notifications/notifications.php" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-bell mr-3 text-gray-400"></i>Notifications
                                </a>
                                <a href="profile/edit-profile.php" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-cog mr-3 text-gray-400"></i>Settings
                                </a>
                                <div class="border-t border-gray-200 my-2"></div>
                                <a href="auth/logout.php" class="flex items-center px-4 py-2 text-red-600 hover:bg-red-50">
                                    <i class="fas fa-sign-out-alt mr-3"></i>Logout
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="container mx-auto px-4 py-6">
        <div class="flex flex-col lg:flex-row gap-6">
            <!-- Left Sidebar -->
            <aside class="lg:w-1/4 space-y-6">
                <!-- User Profile Card -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
                    <div class="text-center">
                        <img src="<?php echo getProfilePic(); ?>" alt="Profile" class="w-20 h-20 rounded-full object-cover border-4 border-white shadow-md mx-auto -mt-10">
                        <h2 class="text-lg font-bold mt-3 text-gray-800">
                            <?php echo htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']); ?>
                        </h2>
                        <p class="text-gray-500 text-sm">@<?php echo htmlspecialchars($currentUser['username']); ?></p>
                        <?php echo getOnlineStatusBadge(); ?>
                    </div>
                    <div class="mt-4 space-y-3">
                        <a href="profile/profile.php" class="flex items-center justify-center p-2 bg-gray-50 hover:bg-gray-100 rounded-lg text-gray-700">
                            <i class="fas fa-user-circle mr-2"></i>View Profile
                        </a>
                        <a href="friends/friends.php" class="flex items-center justify-center p-2 bg-gray-50 hover:bg-gray-100 rounded-lg text-gray-700">
                            <i class="fas fa-users mr-2"></i>Friends (<?php echo countFriends(); ?>)
                        </a>
                        <a href="messages/inbox.php" class="flex items-center justify-center p-2 bg-gray-50 hover:bg-gray-100 rounded-lg text-gray-700">
                            <i class="fas fa-envelope mr-2"></i>Messages
                            <?php if ($unreadMessagesCount > 0): ?>
                                <span class="ml-2 bg-red-500 text-white text-xs px-2 py-1 rounded-full">
                                    <?php echo $unreadMessagesCount; ?> new
                                </span>
                            <?php endif; ?>
                        </a>
                    </div>
                </div>

                <!-- Friend Suggestions -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">
                        <i class="fas fa-user-plus mr-2 text-blue-500"></i>People You May Know
                    </h3>
                    <div class="space-y-4">
                        <?php if (empty($friendSuggestions)): ?>
                            <p class="text-gray-500 text-sm text-center py-4">No suggestions available.</p>
                        <?php else: ?>
                            <?php foreach ($friendSuggestions as $suggestion): ?>
                                <div class="flex items-center justify-between p-3 hover:bg-gray-50 rounded-lg">
                                    <div class="flex items-center">
                                        <img src="<?php echo getProfilePic($suggestion['id']); ?>" alt="<?php echo htmlspecialchars($suggestion['username']); ?>" class="w-10 h-10 rounded-full object-cover">
                                        <div class="ml-3">
                                            <h4 class="text-sm font-medium text-gray-800"><?php echo htmlspecialchars($suggestion['full_name']); ?></h4>
                                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($suggestion['bio'] ?? 'No bio yet'); ?></p>
                                        </div>
                                    </div>
                                    <button onclick="sendFriendRequest(<?php echo $suggestion['id']; ?>)" class="text-xs bg-blue-50 text-blue-600 hover:bg-blue-100 px-3 py-1 rounded-full">
                                        <i class="fas fa-user-plus mr-1"></i> Add
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <a href="friends/friends.php" class="block text-center mt-4 text-blue-600 hover:text-blue-800 text-sm font-medium">
                        <i class="fas fa-arrow-right mr-1"></i> View All Friends
                    </a>
                </div>
            </aside>

            <!-- Main Content -->
            <div class="lg:w-2/4 space-y-6">
                <!-- Flash Messages -->
                <?php echo displayFlashMessages(); ?>

                <!-- Create Post Card -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
                    <div class="flex items-center space-x-3 mb-4">
                        <img src="<?php echo getProfilePic(); ?>" alt="Your profile" class="w-10 h-10 rounded-full object-cover">
                        <div class="flex-1">
                            <button onclick="openPostModal()" class="w-full text-left px-4 py-3 bg-gray-50 hover:bg-gray-100 rounded-full text-gray-500">
                                What's on your mind, <?php echo htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']); ?>?
                            </button>
                        </div>
                    </div>
                    <div class="flex items-center justify-around border-t border-gray-100 pt-4">
                        <button onclick="openPostModal()" class="flex items-center text-gray-600 hover:text-blue-600">
                            <i class="fas fa-video text-red-500 mr-2"></i>
                            <span class="text-sm font-medium">Live Video</span>
                        </button>
                        <button onclick="openPostModal()" class="flex items-center text-gray-600 hover:text-green-600">
                            <i class="fas fa-photo-video text-green-500 mr-2"></i>
                            <span class="text-sm font-medium">Photo/Video</span>
                        </button>
                        <button onclick="openPostModal()" class="flex items-center text-gray-600 hover:text-yellow-600">
                            <i class="fas fa-laugh-beam text-yellow-500 mr-2"></i>
                            <span class="text-sm font-medium">Feeling/Activity</span>
                        </button>
                    </div>
                </div>

                <!-- Posts Feed -->
                <div id="posts-feed">
                    <?php if (empty($posts)): ?>
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-8 text-center">
                            <i class="fas fa-newspaper text-gray-300 text-5xl mb-4"></i>
                            <h3 class="text-xl font-bold text-gray-700 mb-2">No posts yet</h3>
                            <p class="text-gray-500 mb-4">Be the first to share something!</p>
                            <button onclick="openPostModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-full">
                                <i class="fas fa-plus mr-2"></i> Create First Post
                            </button>
                        </div>
                    <?php else: ?>
                        <?php foreach ($posts as $post): ?>
                            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mb-6 animate-fade-in">
                                <!-- Post Header -->
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center space-x-3">
                                        <a href="profile/view-profile.php?id=<?php echo $post['user_id']; ?>">
                                            <img src="<?php echo getProfilePic($post['user_id']); ?>" alt="<?php echo htmlspecialchars($post['username']); ?>" class="w-10 h-10 rounded-full object-cover">
                                        </a>
                                        <div>
                                            <a href="profile/view-profile.php?id=<?php echo $post['user_id']; ?>" class="font-bold text-gray-800 hover:text-blue-600">
                                                <?php echo htmlspecialchars($post['full_name'] ?? $post['username']); ?>
                                            </a>
                                            <div class="flex items-center text-gray-500 text-sm">
                                                <span><?php echo formatTimeAgo($post['created_at']); ?></span>
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
                                            <div class="absolute right-0 mt-1 w-32 bg-white rounded-lg shadow-lg border border-gray-200 hidden group-hover:block z-10">
                                                <button onclick="deletePost(<?php echo $post['id']; ?>)" class="block w-full text-left px-4 py-2 text-red-600 hover:bg-red-50 rounded-t-lg">
                                                    <i class="fas fa-trash mr-2"></i> Delete
                                                </button>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Post Content -->
                                <div class="mb-4">
                                    <p class="text-gray-800 whitespace-pre-line"><?php echo nl2br(htmlspecialchars($post['content'])); ?></p>
                                    <?php if (!empty($post['image'])): ?>
                                        <div class="mt-4">
                                            <img src="<?php echo BASE_URL; ?>/uploads/post_images/<?php echo htmlspecialchars($post['image']); ?>" alt="Post image" class="w-full rounded-lg max-h-96 object-cover">
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Post Stats -->
                                <div class="flex items-center justify-between text-gray-500 text-sm border-b border-gray-100 pb-3 mb-3">
                                    <div class="flex items-center space-x-4">
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
                                </div>

                                <!-- Post Actions -->
                                <div class="flex items-center justify-around border-b border-gray-100 pb-3 mb-3">
                                    <button onclick="toggleLike(<?php echo $post['id']; ?>)" class="flex items-center space-x-2 text-gray-600 hover:text-blue-600 <?php echo $post['user_liked'] ? 'text-blue-600' : ''; ?>">
                                        <i class="fas fa-thumbs-up <?php echo $post['user_liked'] ? 'text-blue-600' : ''; ?>"></i>
                                        <span class="font-medium"><?php echo $post['user_liked'] ? 'Liked' : 'Like'; ?></span>
                                    </button>
                                    <button onclick="toggleCommentBox(<?php echo $post['id']; ?>)" class="flex items-center space-x-2 text-gray-600 hover:text-green-600">
                                        <i class="fas fa-comment"></i>
                                        <span class="font-medium">Comment</span>
                                    </button>
                                    <button onclick="sharePost(<?php echo $post['id']; ?>)" class="flex items-center space-x-2 text-gray-600 hover:text-purple-600">
                                        <i class="fas fa-share"></i>
                                        <span class="font-medium">Share</span>
                                    </button>
                                </div>

                                <!-- Comments Section -->
                                <div id="comments-<?php echo $post['id']; ?>" class="hidden">
                                    <!-- Comments will load here via AJAX -->
                                </div>

                                <!-- Add Comment -->
                                <div id="add-comment-<?php echo $post['id']; ?>" class="hidden mt-4">
                                    <div class="flex items-center space-x-3">
                                        <img src="<?php echo getProfilePic(); ?>" alt="Your profile" class="w-8 h-8 rounded-full object-cover">
                                        <div class="flex-1">
                                            <form onsubmit="addComment(event, <?php echo $post['id']; ?>)" class="flex space-x-2">
                                                <input type="text" id="comment-input-<?php echo $post['id']; ?>" placeholder="Write a comment..." class="flex-1 px-4 py-2 bg-gray-50 border border-gray-300 rounded-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-full">
                                                    <i class="fas fa-paper-plane"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Sidebar -->
            <aside class="lg:w-1/4 space-y-6">
                <!-- Online Friends -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">
                        <i class="fas fa-circle text-green-500 mr-2"></i>Online Friends
                    </h3>
                    <div class="space-y-3">
                        <?php
                        try {
                            $stmt = db()->prepare("
                                SELECT DISTINCT u.id, u.username, u.full_name, u.profile_pic
                                FROM users u
                                JOIN friendships f ON (
                                    (f.user1_id = ? AND f.user2_id = u.id) OR 
                                    (f.user1_id = u.id AND f.user2_id = ?)
                                )
                                WHERE f.status = 'accepted'
                                AND u.is_online = 1
                                AND u.id != ?
                                LIMIT 5
                            ");
                            $stmt->execute([$currentUser['id'], $currentUser['id'], $currentUser['id']]);
                            $onlineFriends = $stmt->fetchAll();
                        } catch (PDOException $e) {
                            $onlineFriends = [];
                        }
                        ?>
                        <?php if (empty($onlineFriends)): ?>
                            <p class="text-gray-500 text-sm text-center py-4">No friends online.</p>
                        <?php else: ?>
                            <?php foreach ($onlineFriends as $friend): ?>
                                <div class="flex items-center justify-between p-2 hover:bg-gray-50 rounded-lg">
                                    <div class="flex items-center">
                                        <div class="relative">
                                            <img src="<?php echo getProfilePic($friend['id']); ?>" alt="<?php echo htmlspecialchars($friend['username']); ?>" class="w-10 h-10 rounded-full object-cover">
                                            <span class="absolute bottom-0 right-0 w-3 h-3 bg-green-500 border-2 border-white rounded-full"></span>
                                        </div>
                                        <div class="ml-3">
                                            <h4 class="text-sm font-medium text-gray-800"><?php echo htmlspecialchars($friend['full_name']); ?></h4>
                                            <p class="text-xs text-gray-500">Online now</p>
                                        </div>
                                    </div>
                                    <a href="messages/chat.php?id=<?php echo $friend['id']; ?>" class="text-blue-600 hover:text-blue-800 text-sm">
                                        <i class="fas fa-comment"></i>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Links -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">Quick Links</h3>
                    <div class="space-y-2">
                        <a href="friends/friends.php" class="flex items-center p-2 hover:bg-gray-50 rounded-lg text-gray-700">
                            <i class="fas fa-users text-blue-500 mr-3"></i>Friends
                        </a>
                        <a href="messages/inbox.php" class="flex items-center p-2 hover:bg-gray-50 rounded-lg text-gray-700">
                            <i class="fas fa-envelope text-green-500 mr-3"></i>Messages
                        </a>
                        <a href="interactions/notifications/notifications.php" class="flex items-center p-2 hover:bg-gray-50 rounded-lg text-gray-700">
                            <i class="fas fa-bell text-yellow-500 mr-3"></i>Notifications
                        </a>
                        <a href="profile/edit-profile.php" class="flex items-center p-2 hover:bg-gray-50 rounded-lg text-gray-700">
                            <i class="fas fa-cog text-purple-500 mr-3"></i>Settings
                        </a>
                    </div>
                </div>

                <!-- Recent Notifications -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">
                        <i class="fas fa-bell mr-2 text-yellow-500"></i>Recent Notifications
                    </h3>
                    <div class="space-y-3">
                        <?php
                        try {
                            $stmt = db()->prepare("
                                SELECT * FROM notifications 
                                WHERE user_id = ? 
                                ORDER BY created_at DESC 
                                LIMIT 3
                            ");
                            $stmt->execute([$currentUser['id']]);
                            $notifications = $stmt->fetchAll();
                        } catch (PDOException $e) {
                            $notifications = [];
                        }
                        ?>
                        <?php if (empty($notifications)): ?>
                            <p class="text-gray-500 text-sm text-center py-4">No notifications.</p>
                        <?php else: ?>
                            <?php foreach ($notifications as $notification): ?>
                                <div class="flex items-start p-2 hover:bg-gray-50 rounded-lg <?php echo $notification['is_read'] == 0 ? 'bg-blue-50' : ''; ?>">
                                    <div class="flex-shrink-0 mt-1">
                                        <?php if (strpos($notification['type'], 'friend') !== false): ?>
                                            <div class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center">
                                                <i class="fas fa-user-plus text-blue-600 text-sm"></i>
                                            </div>
                                        <?php elseif (strpos($notification['type'], 'like') !== false): ?>
                                            <div class="w-8 h-8 rounded-full bg-red-100 flex items-center justify-center">
                                                <i class="fas fa-heart text-red-600 text-sm"></i>
                                            </div>
                                        <?php elseif (strpos($notification['type'], 'comment') !== false): ?>
                                            <div class="w-8 h-8 rounded-full bg-green-100 flex items-center justify-center">
                                                <i class="fas fa-comment text-green-600 text-sm"></i>
                                            </div>
                                        <?php else: ?>
                                            <div class="w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center">
                                                <i class="fas fa-bell text-gray-600 text-sm"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="ml-3">
                                        <p class="text-sm text-gray-800"><?php echo htmlspecialchars($notification['message']); ?></p>
                                        <p class="text-xs text-gray-400 mt-1"><?php echo formatTimeAgo($notification['created_at']); ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <a href="interactions/notifications/notifications.php" class="block text-center mt-2 text-blue-600 hover:text-blue-800 text-sm font-medium">
                            <i class="fas fa-arrow-right mr-1"></i> View All Notifications
                        </a>
                    </div>
                </div>
            </aside>
        </div>
    </main>

    <!-- Create Post Modal -->
    <div id="postModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50 hidden">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <div class="p-6">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-xl font-bold text-gray-800">Create Post</h3>
                    <button onclick="closePostModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-2xl"></i>
                    </button>
                </div>

                <div class="flex items-center space-x-3 mb-6">
                    <img src="<?php echo getProfilePic(); ?>" alt="Your profile" class="w-12 h-12 rounded-full object-cover">
                    <div>
                        <h4 class="font-bold text-gray-800"><?php echo htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']); ?></h4>
                        <select id="privacy" name="privacy" class="text-sm border-none bg-gray-100 rounded-lg px-3 py-1">
                            <option value="public"><i class="fas fa-globe"></i> Public</option>
                            <option value="friends"><i class="fas fa-users"></i> Friends</option>
                            <option value="private"><i class="fas fa-lock"></i> Only Me</option>
                        </select>
                    </div>
                </div>

                <form id="createPostForm" method="POST" enctype="multipart/form-data" action="posts/create-post.php">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">

                    <textarea name="content" rows="5" placeholder="What's on your mind?" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none mb-4"></textarea>

                    <div id="imagePreview" class="mb-4 hidden">
                        <div class="relative">
                            <img id="previewImage" class="w-full rounded-lg max-h-64 object-cover">
                            <button type="button" onclick="removeImage()" class="absolute top-2 right-2 bg-red-500 text-white p-2 rounded-full hover:bg-red-600">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>

                    <input type="file" id="postImage" name="post_image" accept="image/*" class="hidden" onchange="previewImage(this)">

                    <div class="border-t border-gray-200 pt-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-4">
                                <label for="postImage" class="cursor-pointer flex items-center text-gray-600 hover:text-green-600">
                                    <i class="fas fa-photo-video text-green-500 text-xl mr-2"></i>
                                    <span>Photo/Video</span>
                                </label>
                            </div>
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-full font-medium">
                                <i class="fas fa-paper-plane mr-2"></i> Post
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-white border-t border-gray-200 mt-12">
        <div class="container mx-auto px-4 py-6">
            <div class="text-center text-gray-400 text-sm">
                <p>© <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script>
        // Post Modal Functions
        function openPostModal() {
            document.getElementById('postModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closePostModal() {
            document.getElementById('postModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
            document.getElementById('createPostForm').reset();
            removeImage();
        }

        function previewImage(input) {
            const preview = document.getElementById('imagePreview');
            const image = document.getElementById('previewImage');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    image.src = e.target.result;
                    preview.classList.remove('hidden');
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function removeImage() {
            document.getElementById('imagePreview').classList.add('hidden');
            document.getElementById('postImage').value = '';
        }

        // AJAX Functions
        async function toggleLike(postId) {
            try {
                const response = await fetch('api/like-post.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        post_id: postId,
                        csrf_token: '<?php echo generateCsrfToken(); ?>'
                    })
                });
                const result = await response.json();
                if (result.success) location.reload();
                else alert(result.message);
            } catch (error) {
                alert('Network error');
            }
        }

        async function sharePost(postId) {
            if (!confirm('Share this post?')) return;
            try {
                const response = await fetch('interactions/shares/share.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        post_id: postId,
                        csrf_token: '<?php echo generateCsrfToken(); ?>'
                    })
                });
                const result = await response.json();
                alert(result.message);
                if (result.success) location.reload();
            } catch (error) {
                alert('Network error');
            }
        }

        async function sendFriendRequest(userId) {
            if (!confirm('Send friend request?')) return;
            try {
                const response = await fetch('friends/send-request.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        user_id: userId,
                        csrf_token: '<?php echo generateCsrfToken(); ?>'
                    })
                });
                const result = await response.json();
                alert(result.message);
                if (result.success) location.reload();
            } catch (error) {
                alert('Network error');
            }
        }

        async function deletePost(postId) {
            if (!confirm('Delete this post?')) return;
            try {
                const response = await fetch('posts/delete-post.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        post_id: postId,
                        csrf_token: '<?php echo generateCsrfToken(); ?>'
                    })
                });
                const result = await response.json();
                alert(result.message);
                if (result.success) location.reload();
            } catch (error) {
                alert('Network error');
            }
        }

        function toggleCommentBox(postId) {
            const commentBox = document.getElementById('add-comment-' + postId);
            commentBox.classList.toggle('hidden');
        }

        async function addComment(event, postId) {
            event.preventDefault();
            const commentInput = document.getElementById('comment-input-' + postId);
            const comment = commentInput.value.trim();
            if (!comment) return;

            try {
                const response = await fetch('api/add-comment.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        post_id: postId,
                        comment: comment,
                        csrf_token: '<?php echo generateCsrfToken(); ?>'
                    })
                });
                const result = await response.json();
                if (result.success) {
                    commentInput.value = '';
                    location.reload();
                } else {
                    alert(result.message);
                }
            } catch (error) {
                alert('Network error');
            }
        }

        // Close modal when clicking outside
        document.getElementById('postModal').addEventListener('click', function(e) {
            if (e.target === this) closePostModal();
        });
    </script>
</body>

</html>
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

// Mark all as read if requested
if (isset($_GET['mark_all_read'])) {
    try {
        $stmt = db()->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$currentUser['id']]);
        $_SESSION['success'] = 'All notifications marked as read.';
        header('Location: notifications.php');
        exit();
    } catch (PDOException $e) {
        error_log("Mark all read error: " . $e->getMessage());
    }
}

// Get notifications
$notifications = [];
$unreadCount = 0;

try {
    // Get notifications
    $stmt = db()->prepare("
        SELECT n.*, 
               u.username as from_username, 
               u.full_name as from_full_name,
               u.profile_pic as from_profile_pic,
               p.content as post_content,
               p.image as post_image
        FROM notifications n
        LEFT JOIN users u ON n.from_user_id = u.id
        LEFT JOIN posts p ON n.post_id = p.id
        WHERE n.user_id = ?
        ORDER BY n.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$currentUser['id']]);
    $notifications = $stmt->fetchAll();

    // Get unread count
    $countStmt = db()->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    $countStmt->execute([$currentUser['id']]);
    $countResult = $countStmt->fetch();
    $unreadCount = $countResult['count'] ?? 0;
} catch (PDOException $e) {
    error_log("Get notifications error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .notification-unread {
            background-color: #f0f9ff;
            border-left: 4px solid #3b82f6;
        }
    </style>
</head>

<body class="bg-gray-50">
    <!-- Navigation -->
    <?php include '../../includes/header.php'; ?>

    <main class="container mx-auto px-4 py-8 max-w-4xl">
        <!-- Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">Notifications</h1>
            <p class="text-gray-600 mt-2">Stay updated with what's happening</p>
        </div>

        <!-- Stats & Actions -->
        <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <div class="text-center">
                        <div class="text-2xl font-bold text-blue-600"><?php echo $unreadCount; ?></div>
                        <div class="text-sm text-gray-500">Unread</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-gray-700"><?php echo count($notifications); ?></div>
                        <div class="text-sm text-gray-500">Total</div>
                    </div>
                </div>

                <div class="flex space-x-3">
                    <?php if ($unreadCount > 0): ?>
                        <a href="?mark_all_read=1"
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-medium">
                            <i class="fas fa-check-double mr-2"></i> Mark All Read
                        </a>
                    <?php endif; ?>
                    <a href="clear-all.php"
                        class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition font-medium">
                        <i class="fas fa-trash-alt mr-2"></i> Clear All
                    </a>
                </div>
            </div>
        </div>

        <!-- Notifications List -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <?php if (empty($notifications)): ?>
                <div class="text-center py-12">
                    <div class="inline-block p-4 bg-gray-100 rounded-full mb-4">
                        <i class="fas fa-bell text-gray-400 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-700 mb-2">No notifications yet</h3>
                    <p class="text-gray-500">When you get notifications, they'll appear here.</p>
                </div>
            <?php else: ?>
                <div class="divide-y divide-gray-200">
                    <?php foreach ($notifications as $notification): ?>
                        <div class="p-6 hover:bg-gray-50 transition <?php echo $notification['is_read'] ? '' : 'notification-unread'; ?>">
                            <div class="flex items-start space-x-4">
                                <!-- Icon -->
                                <div class="flex-shrink-0">
                                    <?php if ($notification['type'] === 'like'): ?>
                                        <div class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center">
                                            <i class="fas fa-thumbs-up text-blue-600 text-xl"></i>
                                        </div>
                                    <?php elseif ($notification['type'] === 'comment'): ?>
                                        <div class="w-12 h-12 rounded-full bg-green-100 flex items-center justify-center">
                                            <i class="fas fa-comment text-green-600 text-xl"></i>
                                        </div>
                                    <?php elseif ($notification['type'] === 'friend_request'): ?>
                                        <div class="w-12 h-12 rounded-full bg-purple-100 flex items-center justify-center">
                                            <i class="fas fa-user-plus text-purple-600 text-xl"></i>
                                        </div>
                                    <?php elseif ($notification['type'] === 'friend_accept'): ?>
                                        <div class="w-12 h-12 rounded-full bg-green-100 flex items-center justify-center">
                                            <i class="fas fa-user-check text-green-600 text-xl"></i>
                                        </div>
                                    <?php elseif ($notification['type'] === 'share'): ?>
                                        <div class="w-12 h-12 rounded-full bg-yellow-100 flex items-center justify-center">
                                            <i class="fas fa-share text-yellow-600 text-xl"></i>
                                        </div>
                                    <?php else: ?>
                                        <div class="w-12 h-12 rounded-full bg-gray-100 flex items-center justify-center">
                                            <i class="fas fa-bell text-gray-600 text-xl"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Content -->
                                <div class="flex-1">
                                    <div class="flex items-start justify-between">
                                        <div>
                                            <p class="text-gray-800">
                                                <?php if ($notification['type'] === 'like'): ?>
                                                    <span class="font-medium"><?php echo htmlspecialchars($notification['from_full_name'] ?? 'Someone'); ?></span> liked your post
                                                <?php elseif ($notification['type'] === 'comment'): ?>
                                                    <span class="font-medium"><?php echo htmlspecialchars($notification['from_full_name'] ?? 'Someone'); ?></span> commented on your post
                                                <?php elseif ($notification['type'] === 'friend_request'): ?>
                                                    <span class="font-medium"><?php echo htmlspecialchars($notification['from_full_name'] ?? 'Someone'); ?></span> sent you a friend request
                                                <?php elseif ($notification['type'] === 'friend_accept'): ?>
                                                    <span class="font-medium"><?php echo htmlspecialchars($notification['from_full_name'] ?? 'Someone'); ?></span> accepted your friend request
                                                <?php elseif ($notification['type'] === 'share'): ?>
                                                    <span class="font-medium"><?php echo htmlspecialchars($notification['from_full_name'] ?? 'Someone'); ?></span> shared your post
                                                <?php else: ?>
                                                    New notification
                                                <?php endif; ?>
                                            </p>

                                            <?php if (!empty($notification['post_content'])): ?>
                                                <p class="text-gray-500 text-sm mt-1 truncate max-w-md">
                                                    "<?php echo htmlspecialchars(substr($notification['post_content'], 0, 100)); ?>..."
                                                </p>
                                            <?php endif; ?>

                                            <p class="text-gray-400 text-xs mt-2">
                                                <i class="far fa-clock mr-1"></i>
                                                <?php echo formatTimeAgo($notification['created_at']); ?>
                                            </p>
                                        </div>

                                        <!-- Actions -->
                                        <div class="flex space-x-2">
                                            <?php if (!$notification['is_read']): ?>
                                                <a href="mark-read.php?id=<?php echo $notification['id']; ?>"
                                                    class="text-blue-600 hover:text-blue-800 text-sm">
                                                    <i class="fas fa-check"></i> Mark read
                                                </a>
                                            <?php endif; ?>

                                            <?php if ($notification['type'] === 'friend_request' && $notification['from_user_id']): ?>
                                                <a href="../../friends/accept-request.php?user_id=<?php echo $notification['from_user_id']; ?>&redirect=<?php echo urlencode('notifications.php'); ?>"
                                                    class="text-green-600 hover:text-green-800 text-sm ml-3">
                                                    <i class="fas fa-check"></i> Accept
                                                </a>
                                            <?php endif; ?>

                                            <?php if ($notification['post_id']): ?>
                                                <a href="../../index.php?view_post=<?php echo $notification['post_id']; ?>"
                                                    class="text-blue-600 hover:text-blue-800 text-sm ml-3">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Pagination Note -->
        <?php if (count($notifications) >= 50): ?>
            <div class="text-center mt-6 text-gray-500 text-sm">
                <i class="fas fa-info-circle mr-1"></i>
                Showing 50 most recent notifications
            </div>
        <?php endif; ?>
    </main>

    <!-- Footer -->
    <?php include '../../includes/footer.php'; ?>
</body>

</html>
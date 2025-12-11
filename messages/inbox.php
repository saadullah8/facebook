<?php
require_once __DIR__ . '/../config.php';

// Check authentication
if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit();
}

$currentUser = getCurrentUser();
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/auth/logout.php');
    exit();
}

// Get all conversations
try {
    $stmt = db()->prepare("
        SELECT 
            u.id as user_id,
            u.username,
            u.full_name,
            u.profile_pic,
            u.is_online,
            m.message,
            m.created_at as last_message_time,
            m.is_read,
            m.sender_id,
            COUNT(CASE WHEN m2.is_read = 0 AND m2.receiver_id = ? THEN 1 END) as unread_count
        FROM users u
        INNER JOIN (
            SELECT 
                CASE 
                    WHEN sender_id = ? THEN receiver_id
                    ELSE sender_id
                END as other_user_id,
                MAX(created_at) as max_time
            FROM messages
            WHERE sender_id = ? OR receiver_id = ?
            GROUP BY other_user_id
        ) last_msgs ON u.id = last_msgs.other_user_id
        INNER JOIN messages m ON (
            (m.sender_id = ? AND m.receiver_id = u.id) OR 
            (m.sender_id = u.id AND m.receiver_id = ?)
        ) AND m.created_at = last_msgs.max_time
        LEFT JOIN messages m2 ON (
            (m2.sender_id = u.id AND m2.receiver_id = ?) OR 
            (m2.sender_id = ? AND m2.receiver_id = u.id)
        ) AND m2.is_read = 0 AND m2.receiver_id = ?
        WHERE u.id != ?
        GROUP BY u.id
        ORDER BY m.created_at DESC
    ");
    $stmt->execute([
        $currentUser['id'],
        $currentUser['id'],
        $currentUser['id'],
        $currentUser['id'],
        $currentUser['id'],
        $currentUser['id'],
        $currentUser['id'],
        $currentUser['id'],
        $currentUser['id'],
        $currentUser['id']
    ]);
    $conversations = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Get conversations error: " . $e->getMessage());
    $conversations = [];
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
    <title>Messages - <?php echo SITE_NAME; ?></title>
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
                    <a href="<?php echo BASE_URL; ?>/index.php" class="text-xl font-bold text-gray-800"><?php echo SITE_NAME; ?></a>
                </div>

                <!-- Navigation Icons -->
                <div class="flex items-center space-x-4">
                    <!-- Home -->
                    <a href="<?php echo BASE_URL; ?>/index.php" class="p-2 rounded-full hover:bg-gray-100 text-gray-600">
                        <i class="fas fa-home text-xl"></i>
                    </a>

                    <!-- Friend Requests -->
                    <div class="relative">
                        <a href="<?php echo BASE_URL; ?>/friends/friend-requests.php" class="p-2 rounded-full hover:bg-gray-100 text-gray-600">
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
                        <a href="<?php echo BASE_URL; ?>/messages/inbox.php" class="p-2 rounded-full hover:bg-gray-100 text-blue-600">
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
                        <a href="<?php echo BASE_URL; ?>/interactions/notifications/notifications.php" class="p-2 rounded-full hover:bg-gray-100 text-gray-600">
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
                                <a href="<?php echo BASE_URL; ?>/profile/profile.php" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-user-circle mr-3 text-gray-400"></i>My Profile
                                </a>
                                <a href="<?php echo BASE_URL; ?>/friends/friends.php" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-users mr-3 text-gray-400"></i>Friends
                                </a>
                                <a href="<?php echo BASE_URL; ?>/interactions/notifications/notifications.php" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-bell mr-3 text-gray-400"></i>Notifications
                                </a>
                                <a href="<?php echo BASE_URL; ?>/profile/edit-profile.php" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-cog mr-3 text-gray-400"></i>Settings
                                </a>
                                <div class="border-t border-gray-200 my-2"></div>
                                <a href="<?php echo BASE_URL; ?>/auth/logout.php" class="flex items-center px-4 py-2 text-red-600 hover:bg-red-50">
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
                        <a href="<?php echo BASE_URL; ?>/index.php" class="flex items-center justify-center p-2 bg-gray-50 hover:bg-gray-100 rounded-lg text-gray-700">
                            <i class="fas fa-home mr-2"></i>Back to Home
                        </a>
                        <a href="<?php echo BASE_URL; ?>/friends/friends.php" class="flex items-center justify-center p-2 bg-gray-50 hover:bg-gray-100 rounded-lg text-gray-700">
                            <i class="fas fa-users mr-2"></i>Friends (<?php echo countFriends(); ?>)
                        </a>
                    </div>
                </div>

                <!-- New Message Button -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
                    <button onclick="openNewMessageModal()" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-3 rounded-full font-medium">
                        <i class="fas fa-plus mr-2"></i> New Message
                    </button>
                </div>

                <!-- Quick Links -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">Quick Links</h3>
                    <div class="space-y-2">
                        <a href="<?php echo BASE_URL; ?>/friends/friends.php" class="flex items-center p-2 hover:bg-gray-50 rounded-lg text-gray-700">
                            <i class="fas fa-users text-blue-500 mr-3"></i>Friends
                        </a>
                        <a href="<?php echo BASE_URL; ?>/interactions/notifications/notifications.php" class="flex items-center p-2 hover:bg-gray-50 rounded-lg text-gray-700">
                            <i class="fas fa-bell text-yellow-500 mr-3"></i>Notifications
                        </a>
                        <a href="<?php echo BASE_URL; ?>/profile/edit-profile.php" class="flex items-center p-2 hover:bg-gray-50 rounded-lg text-gray-700">
                            <i class="fas fa-cog text-purple-500 mr-3"></i>Settings
                        </a>
                    </div>
                </div>
            </aside>

            <!-- Main Content -->
            <div class="lg:w-3/4">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <!-- Header -->
                    <div class="border-b border-gray-200 p-4">
                        <h2 class="text-xl font-bold text-gray-800">
                            <i class="fas fa-inbox mr-2 text-blue-500"></i>Messages
                        </h2>
                        <p class="text-gray-500 text-sm mt-1">Your conversations</p>
                    </div>

                    <!-- Conversations List -->
                    <div class="divide-y divide-gray-100">
                        <?php if (empty($conversations)): ?>
                            <div class="p-8 text-center">
                                <i class="fas fa-comments text-gray-300 text-5xl mb-4"></i>
                                <h3 class="text-xl font-bold text-gray-700 mb-2">No messages yet</h3>
                                <p class="text-gray-500 mb-4">Start a conversation with your friends!</p>
                                <button onclick="openNewMessageModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-full">
                                    <i class="fas fa-plus mr-2"></i> Send Message
                                </button>
                            </div>
                        <?php else: ?>
                            <?php foreach ($conversations as $conversation): ?>
                                <a href="chat.php?id=<?php echo $conversation['user_id']; ?>" class="flex items-center p-4 hover:bg-gray-50 transition-colors duration-200">
                                    <div class="relative">
                                        <img src="<?php echo getProfilePic($conversation['user_id']); ?>" alt="<?php echo htmlspecialchars($conversation['username']); ?>" class="w-12 h-12 rounded-full object-cover">
                                        <?php if ($conversation['is_online'] == 1): ?>
                                            <span class="absolute bottom-0 right-0 w-3 h-3 bg-green-500 border-2 border-white rounded-full"></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="ml-4 flex-1">
                                        <div class="flex justify-between items-start">
                                            <div>
                                                <h4 class="font-bold text-gray-800">
                                                    <?php echo htmlspecialchars($conversation['full_name'] ?? $conversation['username']); ?>
                                                    <?php if ($conversation['unread_count'] > 0): ?>
                                                        <span class="ml-2 bg-red-500 text-white text-xs px-2 py-1 rounded-full">
                                                            <?php echo $conversation['unread_count']; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </h4>
                                                <p class="text-sm text-gray-600 mt-1 truncate max-w-md">
                                                    <?php if ($conversation['sender_id'] == $currentUser['id']): ?>
                                                        <span class="text-gray-400">You: </span>
                                                    <?php endif; ?>
                                                    <?php echo htmlspecialchars($conversation['message']); ?>
                                                </p>
                                            </div>
                                            <div class="text-right">
                                                <span class="text-xs text-gray-400">
                                                    <?php echo formatTimeAgo($conversation['last_message_time']); ?>
                                                </span>
                                                <?php if ($conversation['sender_id'] != $currentUser['id'] && $conversation['is_read'] == 0): ?>
                                                    <div class="mt-2 w-2 h-2 bg-blue-500 rounded-full ml-auto"></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- New Message Modal -->
    <div id="newMessageModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50 hidden">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
            <div class="p-6">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-xl font-bold text-gray-800">New Message</h3>
                    <button onclick="closeNewMessageModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-2xl"></i>
                    </button>
                </div>

                <div class="mb-4">
                    <input type="text" id="searchUsers" placeholder="Search friends..." class="w-full px-4 py-3 bg-gray-50 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <div id="usersList" class="space-y-2 max-h-96 overflow-y-auto">
                    <!-- Users will be loaded here via AJAX -->
                </div>
            </div>
        </div>
    </div>

    <script>
        // Modal Functions
        function openNewMessageModal() {
            document.getElementById('newMessageModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            searchUsers('');
        }

        function closeNewMessageModal() {
            document.getElementById('newMessageModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        // Search Users Function
        async function searchUsers(query) {
            try {
                const response = await fetch('search-users.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        query: query,
                        csrf_token: '<?php echo generateCsrfToken(); ?>'
                    })
                });
                const users = await response.json();
                displayUsers(users);
            } catch (error) {
                console.error('Search error:', error);
            }
        }

        function displayUsers(users) {
            const container = document.getElementById('usersList');
            if (users.length === 0) {
                container.innerHTML = '<p class="text-center text-gray-500 py-4">No friends found.</p>';
                return;
            }

            container.innerHTML = users.map(user => `
                <a href="chat.php?id=${user.id}" class="flex items-center p-3 hover:bg-gray-50 rounded-lg transition-colors duration-200">
                    <img src="${user.profile_pic}" alt="${user.username}" class="w-10 h-10 rounded-full object-cover">
                    <div class="ml-3">
                        <h4 class="font-medium text-gray-800">${user.full_name}</h4>
                        <p class="text-sm text-gray-500">@${user.username}</p>
                    </div>
                </a>
            `).join('');
        }

        // Event Listeners
        document.getElementById('searchUsers').addEventListener('input', function(e) {
            searchUsers(e.target.value);
        });

        document.getElementById('newMessageModal').addEventListener('click', function(e) {
            if (e.target === this) closeNewMessageModal();
        });

        // Initial load
        searchUsers('');
    </script>
</body>

</html>
<?php
require_once '../config.php';
require_once '../auth/check_session.php';

// Require authentication
requireAuth();

// Get current user
$currentUser = getCurrentUser();
if (!$currentUser) {
    header('Location: ../auth/logout.php');
    exit();
}

// Get user ID from URL
$viewUserId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($viewUserId <= 0) {
    header('Location: profile.php');
    exit();
}

// Get user to view
try {
    $stmt = db()->prepare("
        SELECT id, username, email, full_name, profile_pic, bio, is_online, last_seen, created_at 
        FROM users 
        WHERE id = ?
    ");
    $stmt->execute([$viewUserId]);
    $viewUser = $stmt->fetch();

    if (!$viewUser) {
        header('Location: profile.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Get view user error: " . $e->getMessage());
    header('Location: profile.php');
    exit();
}

// Check friendship status
$isFriend = false;
$friendRequestSent = false;
$friendRequestPending = false;

if ($viewUserId != $currentUser['id']) {
    try {
        $stmt = db()->prepare("
            SELECT status, action_user_id 
            FROM friendships 
            WHERE (user1_id = ? AND user2_id = ?) 
               OR (user1_id = ? AND user2_id = ?)
        ");
        $stmt->execute([$currentUser['id'], $viewUserId, $viewUserId, $currentUser['id']]);
        $friendship = $stmt->fetch();

        if ($friendship) {
            if ($friendship['status'] === 'accepted') {
                $isFriend = true;
            } elseif ($friendship['status'] === 'pending') {
                $friendRequestPending = true;
                $friendRequestSent = ($friendship['action_user_id'] == $currentUser['id']);
            }
        }
    } catch (PDOException $e) {
        error_log("Check friendship error: " . $e->getMessage());
    }
}

// Get user's posts (only if friends or public)
$canViewPosts = ($viewUserId == $currentUser['id']) || $isFriend;

try {
    $sql = "
        SELECT p.*, 
               COUNT(DISTINCT l.id) as like_count,
               COUNT(DISTINCT c.id) as comment_count
        FROM posts p
        LEFT JOIN likes l ON p.id = l.post_id
        LEFT JOIN comments c ON p.id = c.post_id
        WHERE p.user_id = ?
        AND (p.privacy = 'public' " . ($isFriend ? "OR p.privacy = 'friends'" : "") . ")
        GROUP BY p.id
        ORDER BY p.created_at DESC
        LIMIT 10
    ";

    $stmt = db()->prepare($sql);
    $stmt->execute([$viewUserId]);
    $userPosts = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Get view user posts error: " . $e->getMessage());
    $userPosts = [];
}

// Get mutual friends
try {
    $stmt = db()->prepare("
        SELECT u.id, u.username, u.full_name, u.profile_pic, u.is_online
        FROM users u
        JOIN friendships f1 ON (
            (f1.user1_id = ? AND f1.user2_id = u.id) OR 
            (f1.user1_id = u.id AND f1.user2_id = ?)
        )
        JOIN friendships f2 ON (
            (f2.user1_id = ? AND f2.user2_id = u.id) OR 
            (f2.user1_id = u.id AND f2.user2_id = ?)
        )
        WHERE f1.status = 'accepted'
        AND f2.status = 'accepted'
        AND u.id != ?
        AND u.id != ?
        LIMIT 6
    ");
    $stmt->execute([$currentUser['id'], $currentUser['id'], $viewUserId, $viewUserId, $currentUser['id'], $viewUserId]);
    $mutualFriends = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Get mutual friends error: " . $e->getMessage());
    $mutualFriends = [];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($viewUser['full_name']); ?> - Profile</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .profile-cover {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
    </style>
</head>

<body class="bg-gray-50">
    <!-- Navigation -->
    <?php include '../includes/header.php'; ?>

    <main class="container mx-auto px-4 py-6">
        <!-- Profile Header -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden mb-6">
            <!-- Cover Photo -->
            <div class="profile-cover h-48 md:h-64 relative"></div>

            <!-- Profile Info -->
            <div class="px-6 pb-6">
                <div class="flex flex-col md:flex-row items-start md:items-end -mt-16 md:-mt-20">
                    <!-- Profile Picture -->
                    <div class="relative">
                        <img src="<?php echo getProfilePic($viewUser['id']); ?>"
                            alt="<?php echo htmlspecialchars($viewUser['full_name']); ?>"
                            class="w-32 h-32 md:w-40 md:h-40 rounded-full border-4 border-white shadow-lg object-cover">
                        <?php if ($viewUser['is_online'] == 1): ?>
                            <span class="absolute bottom-2 right-2 w-4 h-4 bg-green-500 border-2 border-white rounded-full"></span>
                        <?php endif; ?>
                    </div>

                    <!-- User Info -->
                    <div class="md:ml-6 mt-4 md:mt-0 flex-1">
                        <div class="flex flex-col md:flex-row md:items-center justify-between">
                            <div>
                                <h1 class="text-2xl md:text-3xl font-bold text-gray-900">
                                    <?php echo htmlspecialchars($viewUser['full_name']); ?>
                                    <?php if ($viewUser['id'] == $currentUser['id']): ?>
                                        <span class="text-sm font-normal text-gray-500">(You)</span>
                                    <?php endif; ?>
                                </h1>
                                <p class="text-gray-600">@<?php echo htmlspecialchars($viewUser['username']); ?></p>
                                <div class="flex items-center mt-2 space-x-4">
                                    <?php echo getOnlineStatusBadge($viewUser['id']); ?>
                                    <?php if (!empty($mutualFriends)): ?>
                                        <span class="text-gray-500 text-sm">
                                            <i class="fas fa-user-friends mr-1"></i>
                                            <?php echo count($mutualFriends); ?> mutual friends
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="mt-4 md:mt-0 flex space-x-3">
                                <?php if ($viewUser['id'] == $currentUser['id']): ?>
                                    <a href="profile.php"
                                        class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg font-medium transition flex items-center">
                                        <i class="fas fa-user mr-2"></i> My Profile
                                    </a>
                                <?php else: ?>
                                    <?php if ($isFriend): ?>
                                        <button onclick="removeFriend(<?php echo $viewUser['id']; ?>)"
                                            class="bg-red-100 hover:bg-red-200 text-red-700 px-5 py-2 rounded-lg font-medium transition flex items-center">
                                            <i class="fas fa-user-times mr-2"></i> Unfriend
                                        </button>
                                        <a href="../messages/chat.php?id=<?php echo $viewUser['id']; ?>"
                                            class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg font-medium transition flex items-center">
                                            <i class="fas fa-envelope mr-2"></i> Message
                                        </a>
                                    <?php elseif ($friendRequestPending): ?>
                                        <?php if ($friendRequestSent): ?>
                                            <button class="bg-gray-100 text-gray-700 px-5 py-2 rounded-lg font-medium flex items-center" disabled>
                                                <i class="fas fa-clock mr-2"></i> Request Sent
                                            </button>
                                            <button onclick="cancelFriendRequest(<?php echo $viewUser['id']; ?>)"
                                                class="bg-red-100 hover:bg-red-200 text-red-700 px-5 py-2 rounded-lg font-medium transition flex items-center">
                                                <i class="fas fa-times mr-2"></i> Cancel Request
                                            </button>
                                        <?php else: ?>
                                            <button onclick="acceptFriendRequest(<?php echo $viewUser['id']; ?>)"
                                                class="bg-green-600 hover:bg-green-700 text-white px-5 py-2 rounded-lg font-medium transition flex items-center">
                                                <i class="fas fa-check mr-2"></i> Accept Request
                                            </button>
                                            <button onclick="rejectFriendRequest(<?php echo $viewUser['id']; ?>)"
                                                class="bg-red-100 hover:bg-red-200 text-red-700 px-5 py-2 rounded-lg font-medium transition flex items-center">
                                                <i class="fas fa-times mr-2"></i> Reject
                                            </button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <button onclick="sendFriendRequest(<?php echo $viewUser['id']; ?>)"
                                            class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg font-medium transition flex items-center">
                                            <i class="fas fa-user-plus mr-2"></i> Add Friend
                                        </button>
                                        <a href="../messages/chat.php?id=<?php echo $viewUser['id']; ?>"
                                            class="bg-gray-100 hover:bg-gray-200 text-gray-800 px-5 py-2 rounded-lg font-medium transition flex items-center">
                                            <i class="fas fa-envelope mr-2"></i> Message
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Bio -->
                        <div class="mt-4">
                            <p class="text-gray-700">
                                <?php echo nl2br(htmlspecialchars($viewUser['bio'] ?? 'No bio yet.')); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex flex-col lg:flex-row gap-6">
            <!-- Left Sidebar -->
            <div class="lg:w-1/3 space-y-6">
                <!-- About Card -->
                <div class="bg-white rounded-xl shadow-sm p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                        <i class="fas fa-info-circle text-blue-500 mr-2"></i>
                        About
                    </h3>
                    <div class="space-y-4">
                        <div class="flex items-center text-gray-700">
                            <i class="fas fa-user-circle text-gray-400 mr-3 w-5"></i>
                            <span>Member since <?php echo date('F Y', strtotime($viewUser['created_at'])); ?></span>
                        </div>
                        <div class="flex items-center text-gray-700">
                            <i class="fas fa-clock text-gray-400 mr-3 w-5"></i>
                            <span>
                                Last active:
                                <?php
                                $lastSeen = strtotime($viewUser['last_seen']);
                                $now = time();
                                $diff = $now - $lastSeen;

                                if ($diff < 60) {
                                    echo 'Just now';
                                } elseif ($diff < 3600) {
                                    echo floor($diff / 60) . ' minutes ago';
                                } elseif ($diff < 86400) {
                                    echo floor($diff / 3600) . ' hours ago';
                                } else {
                                    echo date('M j, Y', $lastSeen);
                                }
                                ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Mutual Friends -->
                <?php if (!empty($mutualFriends) && $viewUser['id'] != $currentUser['id']): ?>
                    <div class="bg-white rounded-xl shadow-sm p-6">
                        <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                            <i class="fas fa-user-friends text-green-500 mr-2"></i>
                            Mutual Friends
                        </h3>
                        <div class="grid grid-cols-3 gap-3">
                            <?php foreach ($mutualFriends as $friend): ?>
                                <a href="view-profile.php?id=<?php echo $friend['id']; ?>"
                                    class="group block text-center">
                                    <div class="relative inline-block">
                                        <img src="<?php echo getProfilePic($friend['id']); ?>"
                                            alt="<?php echo htmlspecialchars($friend['full_name']); ?>"
                                            class="w-16 h-16 rounded-full object-cover group-hover:opacity-90 transition">
                                        <?php if ($friend['is_online'] == 1): ?>
                                            <span class="absolute bottom-1 right-1 w-3 h-3 bg-green-500 border-2 border-white rounded-full"></span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-xs text-gray-700 mt-1 truncate">
                                        <?php echo htmlspecialchars(explode(' ', $friend['full_name'])[0]); ?>
                                    </p>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <a href="../friends/friends.php?mutual=<?php echo $viewUser['id']; ?>"
                            class="block text-center mt-4 text-blue-600 hover:text-blue-800 text-sm font-medium">
                            <i class="fas fa-arrow-right mr-1"></i> View All Mutual Friends
                        </a>
                    </div>
                <?php endif; ?>

                <!-- Photos -->
                <?php
                try {
                    $stmt = db()->prepare("
                        SELECT image FROM posts 
                        WHERE user_id = ? AND image IS NOT NULL 
                        ORDER BY created_at DESC 
                        LIMIT 6
                    ");
                    $stmt->execute([$viewUserId]);
                    $photos = $stmt->fetchAll(PDO::FETCH_COLUMN);
                } catch (PDOException $e) {
                    error_log("Get photos error: " . $e->getMessage());
                    $photos = [];
                }

                if (!empty($photos)): ?>
                    <div class="bg-white rounded-xl shadow-sm p-6">
                        <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                            <i class="fas fa-images text-purple-500 mr-2"></i>
                            Photos
                        </h3>
                        <div class="grid grid-cols-3 gap-2">
                            <?php foreach ($photos as $photo): ?>
                                <a href="../uploads/post_images/<?php echo htmlspecialchars($photo); ?>"
                                    target="_blank"
                                    class="block aspect-square overflow-hidden rounded-lg">
                                    <img src="../uploads/post_images/<?php echo htmlspecialchars($photo); ?>"
                                        alt="Photo"
                                        class="w-full h-full object-cover hover:scale-105 transition duration-300">
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Main Content -->
            <div class="lg:w-2/3">
                <?php if ($viewUser['id'] == $currentUser['id'] || $isFriend): ?>
                    <!-- Posts -->
                    <div class="space-y-6">
                        <?php if (empty($userPosts)): ?>
                            <div class="bg-white rounded-xl shadow-sm p-8 text-center">
                                <i class="fas fa-newspaper text-gray-300 text-5xl mb-4"></i>
                                <h3 class="text-xl font-bold text-gray-700 mb-2">No posts yet</h3>
                                <p class="text-gray-500">This user hasn't shared any posts.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($userPosts as $post): ?>
                                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
                                    <!-- Post Header -->
                                    <div class="flex items-center justify-between mb-4">
                                        <div class="flex items-center space-x-3">
                                            <a href="view-profile.php?id=<?php echo $viewUser['id']; ?>">
                                                <img src="<?php echo getProfilePic($viewUser['id']); ?>"
                                                    alt="<?php echo htmlspecialchars($viewUser['username']); ?>"
                                                    class="w-10 h-10 rounded-full object-cover">
                                            </a>
                                            <div>
                                                <a href="view-profile.php?id=<?php echo $viewUser['id']; ?>"
                                                    class="font-bold text-gray-800 hover:text-blue-600 transition">
                                                    <?php echo htmlspecialchars($viewUser['full_name']); ?>
                                                </a>
                                                <div class="flex items-center text-gray-500 text-sm">
                                                    <span><?php echo date('M j, Y \a\t g:i A', strtotime($post['created_at'])); ?></span>
                                                    <span class="mx-2">•</span>
                                                    <i class="fas fa-<?php echo $post['privacy'] === 'public' ? 'globe' : ($post['privacy'] === 'friends' ? 'users' : 'lock'); ?> text-xs"></i>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Post Content -->
                                    <div class="mb-4">
                                        <p class="text-gray-800 whitespace-pre-line"><?php echo nl2br(htmlspecialchars($post['content'])); ?></p>
                                        <?php if (!empty($post['image'])): ?>
                                            <div class="mt-4">
                                                <img src="../uploads/post_images/<?php echo htmlspecialchars($post['image']); ?>"
                                                    alt="Post image"
                                                    class="w-full rounded-lg max-h-96 object-cover">
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
                                        </div>
                                    </div>

                                    <!-- Post Actions -->
                                    <div class="flex items-center justify-around">
                                        <button onclick="toggleLike(<?php echo $post['id']; ?>)"
                                            class="flex items-center space-x-2 text-gray-600 hover:text-blue-600 transition">
                                            <i class="fas fa-thumbs-up"></i>
                                            <span class="font-medium">Like</span>
                                        </button>
                                        <button onclick="toggleCommentBox(<?php echo $post['id']; ?>)"
                                            class="flex items-center space-x-2 text-gray-600 hover:text-green-600 transition">
                                            <i class="fas fa-comment"></i>
                                            <span class="font-medium">Comment</span>
                                        </button>
                                        <button class="flex items-center space-x-2 text-gray-600 hover:text-purple-600 transition">
                                            <i class="fas fa-share"></i>
                                            <span class="font-medium">Share</span>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <!-- Private Profile Message -->
                    <div class="bg-white rounded-xl shadow-sm p-8 text-center">
                        <div class="inline-block p-4 bg-gray-100 rounded-full mb-4">
                            <i class="fas fa-lock text-gray-400 text-3xl"></i>
                        </div>
                        <h3 class="text-xl font-bold text-gray-800 mb-2">This profile is private</h3>
                        <p class="text-gray-600 mb-6">
                            You need to be friends with <?php echo htmlspecialchars($viewUser['full_name']); ?> to see their posts.
                        </p>
                        <?php if (!$friendRequestPending && !$isFriend): ?>
                            <button onclick="sendFriendRequest(<?php echo $viewUser['id']; ?>)"
                                class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-medium transition inline-flex items-center">
                                <i class="fas fa-user-plus mr-2"></i> Send Friend Request
                            </button>
                        <?php elseif ($friendRequestSent): ?>
                            <div class="inline-flex items-center px-6 py-3 bg-gray-100 text-gray-700 rounded-lg">
                                <i class="fas fa-clock mr-2"></i> Friend Request Pending
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <?php include '../includes/footer.php'; ?>

    <script>
        // Friend request functions
        async function sendFriendRequest(userId) {
            try {
                const response = await fetch('../friends/send-request.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        user_id: userId,
                        csrf_token: '<?php echo generateCsrfToken(); ?>'
                    })
                });

                const result = await response.json();

                if (result.success) {
                    alert('Friend request sent!');
                    location.reload();
                } else {
                    alert(result.message || 'Error sending friend request');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Network error. Please try again.');
            }
        }

        async function cancelFriendRequest(userId) {
            if (!confirm('Cancel friend request?')) return;

            try {
                const response = await fetch('../friends/cancel-request.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        user_id: userId,
                        csrf_token: '<?php echo generateCsrfToken(); ?>'
                    })
                });

                const result = await response.json();

                if (result.success) {
                    alert('Friend request cancelled.');
                    location.reload();
                } else {
                    alert(result.message || 'Error cancelling request');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Network error. Please try again.');
            }
        }

        async function acceptFriendRequest(userId) {
            try {
                const response = await fetch('../friends/accept-request.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        user_id: userId,
                        csrf_token: '<?php echo generateCsrfToken(); ?>'
                    })
                });

                const result = await response.json();

                if (result.success) {
                    alert('Friend request accepted!');
                    location.reload();
                } else {
                    alert(result.message || 'Error accepting request');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Network error. Please try again.');
            }
        }

        async function rejectFriendRequest(userId) {
            if (!confirm('Reject friend request?')) return;

            try {
                const response = await fetch('../friends/reject-request.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        user_id: userId,
                        csrf_token: '<?php echo generateCsrfToken(); ?>'
                    })
                });

                const result = await response.json();

                if (result.success) {
                    alert('Friend request rejected.');
                    location.reload();
                } else {
                    alert(result.message || 'Error rejecting request');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Network error. Please try again.');
            }
        }

        async function removeFriend(userId) {
            if (!confirm('Remove this friend?')) return;

            try {
                const response = await fetch('../friends/remove-friend.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        user_id: userId,
                        csrf_token: '<?php echo generateCsrfToken(); ?>'
                    })
                });

                const result = await response.json();

                if (result.success) {
                    alert('Friend removed.');
                    location.reload();
                } else {
                    alert(result.message || 'Error removing friend');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Network error. Please try again.');
            }
        }
    </script>
</body>

</html>
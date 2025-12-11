<?php
require_once __DIR__ . '/../config.php';

// Check authentication
if (!isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit();
}

$currentUser = getCurrentUser();

// Handle friend removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_friend'])) {
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        setFlashMessage('error', 'Invalid security token.');
    } else {
        $friendId = intval($_POST['friend_id']);

        try {
            // Remove friendship from database
            $stmt = db()->prepare("DELETE FROM friendships WHERE 
                (user1_id = ? AND user2_id = ?) OR 
                (user1_id = ? AND user2_id = ?)");
            $stmt->execute([$currentUser['id'], $friendId, $friendId, $currentUser['id']]);

            setFlashMessage('success', 'Friend removed successfully.');
            header('Location: friends.php');
            exit();
        } catch (PDOException $e) {
            setFlashMessage('error', 'Error removing friend: ' . $e->getMessage());
        }
    }
}

// Get all friends
try {
    $stmt = db()->prepare("
        SELECT u.id, u.username, u.full_name, u.profile_pic, u.bio, u.is_online, u.last_seen
        FROM users u
        INNER JOIN friendships f ON 
            (f.user1_id = ? AND f.user2_id = u.id) OR 
            (f.user1_id = u.id AND f.user2_id = ?)
        WHERE f.status = 'accepted'
        ORDER BY u.is_online DESC, u.full_name ASC
    ");
    $stmt->execute([$currentUser['id'], $currentUser['id']]);
    $friends = $stmt->fetchAll();
} catch (PDOException $e) {
    $friends = [];
    setFlashMessage('error', 'Failed to load friends.');
}

// Get friend count
$friendCount = count($friends);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Friends - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body class="bg-gray-50 min-h-screen">
    <!-- Header -->
    <?php include_once '../includes/header.php'; ?>

    <main class="container mx-auto px-4 py-8">
        <div class="max-w-6xl mx-auto">
            <!-- Page Header -->
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-gray-800 mb-2">Friends</h1>
                <p class="text-gray-600">You have <?php echo $friendCount; ?> friends</p>
            </div>

            <!-- Flash Messages -->
            <?php echo displayFlashMessages(); ?>

            <!-- Friends Grid -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-xl font-bold text-gray-800">All Friends</h2>
                    <div class="flex space-x-4">
                        <a href="friend-requests.php" class="flex items-center text-blue-600 hover:text-blue-800">
                            <i class="fas fa-user-clock mr-2"></i>
                            <span>Friend Requests</span>
                        </a>
                        <a href="find-friends.php" class="flex items-center text-green-600 hover:text-green-800">
                            <i class="fas fa-user-plus mr-2"></i>
                            <span>Find Friends</span>
                        </a>
                    </div>
                </div>

                <?php if (empty($friends)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-users text-gray-300 text-6xl mb-4"></i>
                        <h3 class="text-xl font-bold text-gray-700 mb-2">No friends yet</h3>
                        <p class="text-gray-500 mb-6">Start adding friends to connect with people.</p>
                        <a href="find-friends.php" class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-full font-medium">
                            <i class="fas fa-user-plus mr-2"></i> Find Friends
                        </a>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($friends as $friend): ?>
                            <div class="bg-gray-50 rounded-xl p-4 hover:bg-gray-100 transition duration-200">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center">
                                        <div class="relative">
                                            <img src="<?php echo getProfilePic($friend['id']); ?>"
                                                alt="<?php echo htmlspecialchars($friend['full_name']); ?>"
                                                class="w-16 h-16 rounded-full object-cover border-2 border-white shadow">
                                            <?php if ($friend['is_online'] == 1): ?>
                                                <span class="absolute bottom-0 right-0 w-4 h-4 bg-green-500 border-2 border-white rounded-full"></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="ml-4">
                                            <h3 class="font-bold text-gray-800">
                                                <?php echo htmlspecialchars($friend['full_name']); ?>
                                            </h3>
                                            <p class="text-sm text-gray-500">@<?php echo htmlspecialchars($friend['username']); ?></p>
                                            <p class="text-xs text-gray-400">
                                                <?php if ($friend['is_online'] == 1): ?>
                                                    <span class="text-green-500">Online now</span>
                                                <?php else: ?>
                                                    Last seen: <?php echo formatTimeAgo($friend['last_seen']); ?>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <?php if (!empty($friend['bio'])): ?>
                                    <p class="text-sm text-gray-600 mb-4 line-clamp-2">
                                        <?php echo htmlspecialchars($friend['bio']); ?>
                                    </p>
                                <?php endif; ?>

                                <div class="flex space-x-2">
                                    <a href="../messages/chat.php?id=<?php echo $friend['id']; ?>"
                                        class="flex-1 flex items-center justify-center bg-blue-50 text-blue-600 hover:bg-blue-100 px-3 py-2 rounded-lg text-sm font-medium transition">
                                        <i class="fas fa-comment mr-2"></i> Message
                                    </a>
                                    <a href="../profile/view-profile.php?id=<?php echo $friend['id']; ?>"
                                        class="flex-1 flex items-center justify-center bg-gray-100 text-gray-700 hover:bg-gray-200 px-3 py-2 rounded-lg text-sm font-medium transition">
                                        <i class="fas fa-eye mr-2"></i> View
                                    </a>
                                    <form method="POST" class="flex-1" onsubmit="return confirm('Are you sure you want to remove this friend?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                        <input type="hidden" name="friend_id" value="<?php echo $friend['id']; ?>">
                                        <button type="submit" name="remove_friend"
                                            class="w-full flex items-center justify-center bg-red-50 text-red-600 hover:bg-red-100 px-3 py-2 rounded-lg text-sm font-medium transition">
                                            <i class="fas fa-user-times mr-2"></i> Remove
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Friend Stats -->
            <div class="mt-8 grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-gradient-to-r from-blue-50 to-blue-100 rounded-xl p-6">
                    <div class="flex items-center">
                        <div class="bg-blue-100 p-3 rounded-lg mr-4">
                            <i class="fas fa-users text-blue-600 text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Total Friends</p>
                            <p class="text-3xl font-bold text-gray-800"><?php echo $friendCount; ?></p>
                        </div>
                    </div>
                </div>

                <div class="bg-gradient-to-r from-green-50 to-green-100 rounded-xl p-6">
                    <div class="flex items-center">
                        <div class="bg-green-100 p-3 rounded-lg mr-4">
                            <i class="fas fa-user-check text-green-600 text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Online Friends</p>
                            <p class="text-3xl font-bold text-gray-800">
                                <?php echo count(array_filter($friends, fn($f) => $f['is_online'] == 1)); ?>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="bg-gradient-to-r from-purple-50 to-purple-100 rounded-xl p-6">
                    <div class="flex items-center">
                        <div class="bg-purple-100 p-3 rounded-lg mr-4">
                            <i class="fas fa-clock text-purple-600 text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Pending Requests</p>
                            <p class="text-3xl font-bold text-gray-800"><?php echo getPendingFriendRequestsCount(); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php include_once '../includes/footer.php'; ?>
</body>

</html>
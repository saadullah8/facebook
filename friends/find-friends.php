<?php
require_once __DIR__ . '/../config.php';

// Check authentication
if (!isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit();
}

$currentUser = getCurrentUser();

// Handle search
$search = '';
$users = [];

if (isset($_GET['search'])) {
    $search = sanitize($_GET['search']);

    try {
        if (!empty($search)) {
            // Search for users
            $stmt = db()->prepare("
                SELECT u.id, u.username, u.full_name, u.profile_pic, u.bio, u.created_at
                FROM users u
                WHERE u.id != ?
                AND (u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)
                AND u.id NOT IN (
                    SELECT 
                        CASE 
                            WHEN user1_id = ? THEN user2_id
                            ELSE user1_id
                        END
                    FROM friendships 
                    WHERE user1_id = ? OR user2_id = ?
                )
                ORDER BY u.created_at DESC
                LIMIT 20
            ");
            $searchTerm = "%{$search}%";
            $stmt->execute([
                $currentUser['id'],
                $searchTerm,
                $searchTerm,
                $searchTerm,
                $currentUser['id'],
                $currentUser['id'],
                $currentUser['id']
            ]);
        } else {
            // Show suggested users
            $stmt = db()->prepare("
                SELECT u.id, u.username, u.full_name, u.profile_pic, u.bio, u.created_at
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
                LIMIT 10
            ");
            $stmt->execute([$currentUser['id'], $currentUser['id'], $currentUser['id'], $currentUser['id']]);
        }

        $users = $stmt->fetchAll();
    } catch (PDOException $e) {
        setFlashMessage('error', 'Failed to search users.');
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find Friends - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="csrf-token" content="<?php echo generateCsrfToken(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body class="bg-gray-50 min-h-screen">
    <!-- Header -->
    <?php include_once '../includes/header.php'; ?>

    <main class="container mx-auto px-4 py-8">
        <div class="max-w-6xl mx-auto">
            <!-- Page Header -->
            <input type="hidden" id="csrf_token" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <div class="mb-8">
                <div class="flex justify-between items-center">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-800 mb-2">Find Friends</h1>
                        <p class="text-gray-600">Connect with people you may know</p>
                    </div>
                    <a href="friends.php" class="flex items-center text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>
                        <span>Back to Friends</span>
                    </a>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php echo displayFlashMessages(); ?>

            <!-- Search Bar -->
            <div class="mb-8">
                <form method="GET" class="max-w-2xl mx-auto">
                    <div class="relative">
                        <input type="text"
                            name="search"
                            value="<?php echo htmlspecialchars($search); ?>"
                            placeholder="Search by name, username, or email..."
                            class="w-full px-6 py-4 pl-14 bg-white border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent shadow-sm">
                        <i class="fas fa-search absolute left-5 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        <button type="submit"
                            class="absolute right-3 top-1/2 transform -translate-y-1/2 bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
                            Search
                        </button>
                    </div>
                </form>
            </div>

            <!-- Users Grid -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-xl font-bold text-gray-800">
                        <?php if (!empty($search)): ?>
                            Search Results for "<?php echo htmlspecialchars($search); ?>"
                        <?php else: ?>
                            People You May Know
                        <?php endif; ?>
                    </h2>
                    <p class="text-gray-500"><?php echo count($users); ?> people found</p>
                </div>

                <?php if (empty($users)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-user-friends text-gray-300 text-6xl mb-4"></i>
                        <h3 class="text-xl font-bold text-gray-700 mb-2">No users found</h3>
                        <p class="text-gray-500">Try searching with different keywords.</p>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($users as $user): ?>
                            <div class="bg-gray-50 rounded-xl p-4 hover:bg-gray-100 transition duration-200">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center">
                                        <img src="<?php echo getProfilePic($user['id']); ?>"
                                            alt="<?php echo htmlspecialchars($user['full_name']); ?>"
                                            class="w-16 h-16 rounded-full object-cover border-2 border-white shadow mr-4">
                                        <div>
                                            <h3 class="font-bold text-gray-800">
                                                <?php echo htmlspecialchars($user['full_name']); ?>
                                            </h3>
                                            <p class="text-sm text-gray-500">@<?php echo htmlspecialchars($user['username']); ?></p>
                                            <p class="text-xs text-gray-400">
                                                Joined <?php echo date('M Y', strtotime($user['created_at'])); ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <?php if (!empty($user['bio'])): ?>
                                    <p class="text-sm text-gray-600 mb-4 line-clamp-2">
                                        <?php echo htmlspecialchars($user['bio']); ?>
                                    </p>
                                <?php endif; ?>

                                <div class="flex space-x-2">
                                    <button onclick="sendFriendRequest(<?php echo $user['id']; ?>)"
                                        class="flex-1 flex items-center justify-center bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg text-sm font-medium transition">
                                        <i class="fas fa-user-plus mr-2"></i> Add Friend
                                    </button>
                                    <a href="../profile/view-profile.php?id=<?php echo $user['id']; ?>"
                                        class="flex-1 flex items-center justify-center bg-gray-100 text-gray-700 hover:bg-gray-200 px-3 py-2 rounded-lg text-sm font-medium transition">
                                        <i class="fas fa-eye mr-2"></i> View Profile
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <?php include_once '../includes/footer.php'; ?>

    <script>
        async function sendFriendRequest(userId) {
            if (!confirm('Send friend request to this user?')) return;

            try {
                const response = await fetch('send-request.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
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
                alert('Network error. Please try again.');
            }
        }

        function sendFriendRequest(userId) {
            if (!confirm('Send friend request to this user?')) return;

            // Get CSRF token
            const csrfToken = document.getElementById('csrf_token').value;

            // Create a form and submit it via AJAX
            const formData = new FormData();
            formData.append('user_id', userId);
            formData.append('csrf_token', csrfToken);

            fetch('send-request.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        alert(result.message);
                        location.reload();
                    } else {
                        alert(result.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Network error. Please try again.');
                });
        }
    </script>
</body>

</html>
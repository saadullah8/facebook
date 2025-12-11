<?php
require_once __DIR__ . '/../config.php';

// Check authentication
if (!isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit();
}

$currentUser = getCurrentUser();

// Get pending friend requests
try {
    $stmt = db()->prepare("
        SELECT 
            f.id as request_id, 
            f.created_at, 
            u.id as user_id, 
            u.username, 
            u.full_name, 
            u.profile_pic, 
            u.bio
        FROM friendships f
        JOIN users u ON f.user1_id = u.id
        WHERE f.user2_id = ? AND f.status = 'pending'
        ORDER BY f.created_at DESC
    ");
    $stmt->execute([$currentUser['id']]);
    $friendRequests = $stmt->fetchAll();
} catch (PDOException $e) {
    $friendRequests = [];
    setFlashMessage('error', 'Failed to load friend requests.');
}

$requestCount = count($friendRequests);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Friend Requests - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="csrf-token" content="<?php echo generateCsrfToken(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                        <h1 class="text-3xl font-bold text-gray-800 mb-2">Friend Requests</h1>
                        <p class="text-gray-600">
                            You have <?php echo $requestCount; ?> pending friend request<?php echo $requestCount != 1 ? 's' : ''; ?>
                        </p>
                    </div>
                    <div class="flex items-center space-x-4">
                        <a href="friends.php" class="flex items-center text-blue-600 hover:text-blue-800">
                            <i class="fas fa-users mr-2"></i>
                            <span>All Friends</span>
                        </a>
                        <a href="find-friends.php" class="flex items-center text-green-600 hover:text-green-800">
                            <i class="fas fa-user-plus mr-2"></i>
                            <span>Find Friends</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php echo displayFlashMessages(); ?>

            <!-- Friend Requests -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <?php if (empty($friendRequests)): ?>
                    <div class="text-center py-16">
                        <i class="fas fa-user-clock text-gray-300 text-6xl mb-4"></i>
                        <h3 class="text-xl font-bold text-gray-700 mb-2">No friend requests</h3>
                        <p class="text-gray-500 mb-6">When someone sends you a friend request, it will appear here.</p>
                        <a href="find-friends.php" class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-medium">
                            <i class="fas fa-user-plus mr-2"></i> Find Friends
                        </a>
                    </div>
                <?php else: ?>
                    <div class="divide-y divide-gray-100">
                        <?php foreach ($friendRequests as $request): ?>
                            <div class="p-6 hover:bg-gray-50 transition duration-200">
                                <div class="flex items-start justify-between">
                                    <div class="flex items-start space-x-4">
                                        <a href="../profile/view-profile.php?id=<?php echo $request['user_id']; ?>" class="flex-shrink-0">
                                            <img src="<?php echo getProfilePic($request['user_id']); ?>" 
                                                 alt="<?php echo htmlspecialchars($request['full_name']); ?>"
                                                 class="w-20 h-20 rounded-full object-cover border-2 border-white shadow">
                                        </a>
                                        <div class="flex-1">
                                            <div class="flex items-start justify-between">
                                                <div>
                                                    <a href="../profile/view-profile.php?id=<?php echo $request['user_id']; ?>" 
                                                       class="font-bold text-gray-800 text-lg hover:text-blue-600">
                                                        <?php echo htmlspecialchars($request['full_name']); ?>
                                                    </a>
                                                    <p class="text-gray-500">@<?php echo htmlspecialchars($request['username']); ?></p>
                                                    <p class="text-sm text-gray-400 mt-1">
                                                        <i class="fas fa-clock mr-1"></i>
                                                        Sent <?php echo formatTimeAgo($request['created_at']); ?>
                                                    </p>
                                                </div>
                                            </div>
                                            
                                            <?php if (!empty($request['bio'])): ?>
                                                <p class="text-gray-600 text-sm mt-3">
                                                    <?php echo htmlspecialchars($request['bio']); ?>
                                                </p>
                                            <?php endif; ?>
                                            
                                            <div class="flex items-center space-x-3 mt-4">
                                                <a href="../messages/chat.php?id=<?php echo $request['user_id']; ?>"
                                                   class="inline-flex items-center text-blue-600 hover:text-blue-800 text-sm">
                                                    <i class="fas fa-comment mr-1"></i>
                                                    Send Message
                                                </a>
                                                <span class="text-gray-300">•</span>
                                                <a href="../profile/view-profile.php?id=<?php echo $request['user_id']; ?>"
                                                   class="inline-flex items-center text-gray-600 hover:text-gray-800 text-sm">
                                                    <i class="fas fa-eye mr-1"></i>
                                                    View Profile
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="flex flex-col space-y-2 ml-4">
                                        <a href="accept-request.php?id=<?php echo $request['request_id']; ?>"
                                           class="inline-flex items-center justify-center bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg font-medium transition whitespace-nowrap min-w-[100px]">
                                            <i class="fas fa-check mr-2"></i> Accept
                                        </a>
                                        <a href="reject-request.php?id=<?php echo $request['request_id']; ?>"
                                           class="inline-flex items-center justify-center bg-red-50 hover:bg-red-100 text-red-600 px-5 py-2.5 rounded-lg font-medium transition whitespace-nowrap min-w-[100px]"
                                           onclick="return confirm('Decline friend request from <?php echo addslashes($request['full_name']); ?>?')">
                                            <i class="fas fa-times mr-2"></i> Decline
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Quick Actions -->
            <?php if ($requestCount > 0): ?>
                <div class="mt-8 bg-gradient-to-r from-blue-50 to-blue-100 rounded-xl p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">Quick Actions</h3>
                    <div class="flex flex-wrap gap-4">
                        <form method="POST" action="accept-all-requests.php" class="inline" 
                              onsubmit="return confirm('Accept all <?php echo $requestCount; ?> friend requests?')">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                            <button type="submit" 
                                    class="inline-flex items-center bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-lg font-medium">
                                <i class="fas fa-check-double mr-2"></i> Accept All (<?php echo $requestCount; ?>)
                            </button>
                        </form>
                        <form method="POST" action="reject-all-requests.php" class="inline"
                              onsubmit="return confirm('Reject all <?php echo $requestCount; ?> friend requests?')">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                            <button type="submit" 
                                    class="inline-flex items-center bg-red-600 hover:bg-red-700 text-white px-6 py-3 rounded-lg font-medium">
                                <i class="fas fa-times-circle mr-2"></i> Reject All (<?php echo $requestCount; ?>)
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Empty state when no requests -->
            <?php if (empty($friendRequests)): ?>
                <div class="mt-8 bg-gradient-to-r from-green-50 to-green-100 rounded-xl p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">Want more friends?</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <a href="find-friends.php" 
                           class="flex flex-col items-center justify-center p-4 bg-white rounded-lg shadow-sm hover:shadow-md transition">
                            <div class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center mb-3">
                                <i class="fas fa-search text-blue-600"></i>
                            </div>
                            <p class="font-medium text-gray-800">Find Friends</p>
                            <p class="text-sm text-gray-500 text-center mt-1">Search for people you may know</p>
                        </a>
                        <a href="../profile/edit-profile.php" 
                           class="flex flex-col items-center justify-center p-4 bg-white rounded-lg shadow-sm hover:shadow-md transition">
                            <div class="w-12 h-12 rounded-full bg-green-100 flex items-center justify-center mb-3">
                                <i class="fas fa-user-edit text-green-600"></i>
                            </div>
                            <p class="font-medium text-gray-800">Update Profile</p>
                            <p class="text-sm text-gray-500 text-center mt-1">Complete your profile to get more requests</p>
                        </a>
                        <a href="../posts/create-post.php" 
                           class="flex flex-col items-center justify-center p-4 bg-white rounded-lg shadow-sm hover:shadow-md transition">
                            <div class="w-12 h-12 rounded-full bg-purple-100 flex items-center justify-center mb-3">
                                <i class="fas fa-share-square text-purple-600"></i>
                            </div>
                            <p class="font-medium text-gray-800">Share Posts</p>
                            <p class="text-sm text-gray-500 text-center mt-1">Active users get more friend requests</p>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php include_once '../includes/footer.php'; ?>
    
    <script>
        // Confirm before declining requests
        document.addEventListener('DOMContentLoaded', function() {
            const declineLinks = document.querySelectorAll('a[href*="reject-request.php"]');
            declineLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    if (!confirm('Are you sure you want to decline this friend request?')) {
                        e.preventDefault();
                    }
                });
            });
        });
    </script>
</body>
</html>
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

// Get user's posts
try {
    $stmt = db()->prepare("
        SELECT p.*, 
               COUNT(DISTINCT l.id) as like_count,
               COUNT(DISTINCT c.id) as comment_count
        FROM posts p
        LEFT JOIN likes l ON p.id = l.post_id
        LEFT JOIN comments c ON p.id = c.post_id
        WHERE p.user_id = ?
        GROUP BY p.id
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$currentUser['id']]);
    $userPosts = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Get user posts error: " . $e->getMessage());
    $userPosts = [];
}

// Get user's friends
try {
    $stmt = db()->prepare("
        SELECT u.id, u.username, u.full_name, u.profile_pic, u.is_online, u.last_seen
        FROM users u
        JOIN friendships f ON (
            (f.user1_id = ? AND f.user2_id = u.id) OR 
            (f.user1_id = u.id AND f.user2_id = ?)
        )
        WHERE f.status = 'accepted'
        ORDER BY u.is_online DESC, u.full_name ASC
        LIMIT 9
    ");
    $stmt->execute([$currentUser['id'], $currentUser['id']]);
    $userFriends = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Get friends error: " . $e->getMessage());
    $userFriends = [];
}

// Get friend count
$friendCount = count($userFriends);

// Helper function to get profile picture
function getProfilePicForUser($userId = null) {
    if ($userId === null) {
        // Get current user's profile pic from session
        return isset($_SESSION['profile_pic']) ? '../uploads/profile_pics/' . $_SESSION['profile_pic'] : '../assets/images/default-avatar.jpg';
    }
    
    // In a real app, you'd fetch from database
    // For now, return default
    return '../assets/images/default-avatar.jpg';
}

// Helper function for online status badge
function getOnlineStatusBadgeForUser($userId) {
    // In a real app, check database for online status
    // For now, return a simple badge
    return '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                <span class="w-2 h-2 mr-1 bg-green-500 rounded-full"></span>
                Online
            </span>';
}

// Helper function to get first name
function getFirstName($fullName) {
    if (empty($fullName)) {
        return 'User';
    }
    $parts = explode(' ', $fullName);
    return $parts[0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']); ?> - Profile</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .profile-cover {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .profile-pic {
            border: 5px solid white;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Simple Navigation (since includes/header.php might not exist yet) -->
    <nav class="bg-white shadow-md">
        <div class="container mx-auto px-4 py-3 flex justify-between items-center">
            <a href="../index.php" class="text-xl font-bold text-blue-600"><?php echo SITE_NAME; ?></a>
            <div class="flex items-center space-x-4">
                <a href="../index.php" class="text-gray-600 hover:text-blue-600">Home</a>
                <a href="profile.php" class="text-blue-600 font-medium">Profile</a>
                <a href="../auth/logout.php" class="text-red-600 hover:text-red-800">Logout</a>
            </div>
        </div>
    </nav>

    <main class="container mx-auto px-4 py-6">
        <!-- Profile Header -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden mb-6">
            <!-- Cover Photo -->
            <div class="profile-cover h-48 md:h-64 relative">
                <div class="absolute inset-0 bg-black bg-opacity-20"></div>
                <button class="absolute top-4 right-4 bg-white bg-opacity-90 hover:bg-opacity-100 text-gray-800 px-4 py-2 rounded-full text-sm font-medium transition">
                    <i class="fas fa-camera mr-2"></i>Update Cover
                </button>
            </div>
            
            <!-- Profile Info -->
            <div class="px-6 pb-6">
                <div class="flex flex-col md:flex-row items-start md:items-end -mt-16 md:-mt-20">
                    <!-- Profile Picture -->
                    <div class="relative">
                        <img src="<?php echo getProfilePicForUser($currentUser['id']); ?>" 
                             alt="<?php echo htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']); ?>"
                             class="w-32 h-32 md:w-40 md:h-40 rounded-full profile-pic object-cover">
                        <button onclick="openAvatarUpload()" 
                                class="absolute bottom-2 right-2 bg-blue-600 text-white p-2 rounded-full hover:bg-blue-700 transition">
                            <i class="fas fa-camera text-sm"></i>
                        </button>
                    </div>
                    
                    <!-- User Info -->
                    <div class="md:ml-6 mt-4 md:mt-0 flex-1">
                        <div class="flex flex-col md:flex-row md:items-center justify-between">
                            <div>
                                <h1 class="text-2xl md:text-3xl font-bold text-gray-900">
                                    <?php echo htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']); ?>
                                </h1>
                                <p class="text-gray-600">@<?php echo htmlspecialchars($currentUser['username']); ?></p>
                                <div class="flex items-center mt-2 space-x-4">
                                    <?php echo getOnlineStatusBadgeForUser($currentUser['id']); ?>
                                    <span class="text-gray-500 text-sm">
                                        <i class="fas fa-user-friends mr-1"></i>
                                        <?php echo $friendCount; ?> friends
                                    </span>
                                    <span class="text-gray-500 text-sm">
                                        <i class="fas fa-newspaper mr-1"></i>
                                        <?php echo count($userPosts); ?> posts
                                    </span>
                                </div>
                            </div>
                            
                            <!-- Action Buttons -->
                            <div class="mt-4 md:mt-0 flex space-x-3">
                                <a href="edit-profile.php" 
                                   class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg font-medium transition flex items-center">
                                    <i class="fas fa-edit mr-2"></i> Edit Profile
                                </a>
                                <a href="../friends/friends.php" 
                                   class="bg-gray-100 hover:bg-gray-200 text-gray-800 px-5 py-2 rounded-lg font-medium transition flex items-center">
                                    <i class="fas fa-users mr-2"></i> Friends
                                </a>
                            </div>
                        </div>
                        
                        <!-- Bio -->
                        <div class="mt-4">
                            <p class="text-gray-700">
                                <?php echo nl2br(htmlspecialchars($currentUser['bio'] ?? 'No bio yet. Tell people about yourself!')); ?>
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
                            <i class="fas fa-envelope text-gray-400 mr-3 w-5"></i>
                            <span><?php echo htmlspecialchars($currentUser['email']); ?></span>
                        </div>
                        <div class="flex items-center text-gray-700">
                            <i class="fas fa-user-circle text-gray-400 mr-3 w-5"></i>
                            <span>Member since <?php echo date('F Y', strtotime($currentUser['created_at'])); ?></span>
                        </div>
                        <div class="flex items-center text-gray-700">
                            <i class="fas fa-clock text-gray-400 mr-3 w-5"></i>
                            <span>
                                Last seen: 
                                <?php 
                                $lastSeen = strtotime($currentUser['last_seen']);
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

                <!-- Friends Card -->
                <div class="bg-white rounded-xl shadow-sm p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-bold text-gray-800 flex items-center">
                            <i class="fas fa-users text-green-500 mr-2"></i>
                            Friends
                        </h3>
                        <a href="../friends/friends.php" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                            See all
                        </a>
                    </div>
                    
                    <?php if (empty($userFriends)): ?>
                        <div class="text-center py-6">
                            <i class="fas fa-user-friends text-gray-300 text-3xl mb-3"></i>
                            <p class="text-gray-500">No friends yet</p>
                            <a href="../friends/friends.php" class="text-blue-600 hover:text-blue-800 text-sm mt-2 inline-block">
                                Find friends
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="grid grid-cols-3 gap-3">
                            <?php foreach ($userFriends as $friend): ?>
                                <a href="view-profile.php?id=<?php echo $friend['id']; ?>" 
                                   class="group block text-center">
                                    <div class="relative inline-block">
                                        <img src="<?php echo getProfilePicForUser($friend['id']); ?>" 
                                             alt="<?php echo htmlspecialchars($friend['full_name'] ?? $friend['username']); ?>"
                                             class="w-16 h-16 rounded-full object-cover group-hover:opacity-90 transition">
                                        <?php if ($friend['is_online'] == 1): ?>
                                            <span class="absolute bottom-1 right-1 w-3 h-3 bg-green-500 border-2 border-white rounded-full"></span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-xs text-gray-700 mt-1 truncate">
                                        <?php echo htmlspecialchars(getFirstName($friend['full_name'] ?? $friend['username'])); ?>
                                    </p>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Photos Card -->
                <div class="bg-white rounded-xl shadow-sm p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                        <i class="fas fa-images text-purple-500 mr-2"></i>
                        Photos
                    </h3>
                    <div class="grid grid-cols-3 gap-2">
                        <?php
                        try {
                            $stmt = db()->prepare("
                                SELECT image FROM posts 
                                WHERE user_id = ? AND image IS NOT NULL 
                                ORDER BY created_at DESC 
                                LIMIT 6
                            ");
                            $stmt->execute([$currentUser['id']]);
                            $photos = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        } catch (PDOException $e) {
                            error_log("Get photos error: " . $e->getMessage());
                            $photos = [];
                        }
                        
                        if (empty($photos)): ?>
                            <div class="col-span-3 text-center py-4">
                                <i class="fas fa-image text-gray-300 text-2xl mb-2"></i>
                                <p class="text-gray-500 text-sm">No photos yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($photos as $photo): ?>
                                <?php if (!empty($photo)): ?>
                                    <a href="../uploads/post_images/<?php echo htmlspecialchars($photo); ?>" 
                                       target="_blank"
                                       class="block aspect-square overflow-hidden rounded-lg">
                                        <img src="../uploads/post_images/<?php echo htmlspecialchars($photo); ?>" 
                                             alt="Photo"
                                             class="w-full h-full object-cover hover:scale-105 transition duration-300">
                                    </a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="lg:w-2/3">
                <!-- Create Post Card -->
                <div class="bg-white rounded-xl shadow-sm p-4 mb-6">
                    <div class="flex items-center space-x-3">
                        <img src="<?php echo getProfilePicForUser(); ?>" 
                             alt="Your profile" 
                             class="w-10 h-10 rounded-full object-cover">
                        <button onclick="window.location.href='../index.php'" 
                                class="flex-1 text-left px-4 py-3 bg-gray-50 hover:bg-gray-100 rounded-full text-gray-500 transition">
                            What's on your mind, <?php echo htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']); ?>?
                        </button>
                    </div>
                    <div class="flex items-center justify-around mt-4 pt-4 border-t border-gray-100">
                        <button onclick="window.location.href='../index.php'" 
                                class="flex items-center text-gray-600 hover:text-blue-600 transition">
                            <i class="fas fa-photo-video text-green-500 mr-2"></i>
                            <span class="text-sm font-medium">Photo/Video</span>
                        </button>
                        <button onclick="window.location.href='../index.php'" 
                                class="flex items-center text-gray-600 hover:text-red-600 transition">
                            <i class="fas fa-video text-red-500 mr-2"></i>
                            <span class="text-sm font-medium">Live Video</span>
                        </button>
                        <button onclick="window.location.href='../index.php'" 
                                class="flex items-center text-gray-600 hover:text-yellow-600 transition">
                            <i class="fas fa-laugh-beam text-yellow-500 mr-2"></i>
                            <span class="text-sm font-medium">Feeling/Activity</span>
                        </button>
                    </div>
                </div>

                <!-- Posts -->
                <div class="space-y-6">
                    <?php if (empty($userPosts)): ?>
                        <div class="bg-white rounded-xl shadow-sm p-8 text-center">
                            <i class="fas fa-newspaper text-gray-300 text-5xl mb-4"></i>
                            <h3 class="text-xl font-bold text-gray-700 mb-2">No posts yet</h3>
                            <p class="text-gray-500 mb-4">Share your first post!</p>
                            <button onclick="window.location.href='../index.php'" 
                                    class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-full transition">
                                <i class="fas fa-plus mr-2"></i> Create First Post
                            </button>
                        </div>
                    <?php else: ?>
                        <?php foreach ($userPosts as $post): ?>
                            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
                                <!-- Post Header -->
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center space-x-3">
                                        <a href="profile.php">
                                            <img src="<?php echo getProfilePicForUser($currentUser['id']); ?>" 
                                                 alt="<?php echo htmlspecialchars($currentUser['username']); ?>"
                                                 class="w-10 h-10 rounded-full object-cover">
                                        </a>
                                        <div>
                                            <a href="profile.php" class="font-bold text-gray-800 hover:text-blue-600 transition">
                                                <?php echo htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']); ?>
                                            </a>
                                            <div class="flex items-center text-gray-500 text-sm">
                                                <span><?php echo date('M j, Y \a\t g:i A', strtotime($post['created_at'])); ?></span>
                                                <span class="mx-2">•</span>
                                                <i class="fas fa-<?php echo $post['privacy'] === 'public' ? 'globe' : ($post['privacy'] === 'friends' ? 'users' : 'lock'); ?> text-xs"></i>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if ($post['user_id'] == $currentUser['id']): ?>
                                        <div class="relative group">
                                            <button class="p-2 rounded-full hover:bg-gray-100 text-gray-500">
                                                <i class="fas fa-ellipsis-h"></i>
                                            </button>
                                            <div class="absolute right-0 mt-1 w-32 bg-white rounded-lg shadow-lg border border-gray-200 hidden group-hover:block z-10">
                                                <button onclick="deletePost(<?php echo $post['id']; ?>)" 
                                                        class="block w-full text-left px-4 py-2 text-red-600 hover:bg-red-50 rounded-t-lg">
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
            </div>
        </div>
    </main>

    <!-- Avatar Upload Modal -->
    <div id="avatarModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50 hidden">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
            <div class="p-6">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-xl font-bold text-gray-800">Update Profile Picture</h3>
                    <button onclick="closeAvatarUpload()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-2xl"></i>
                    </button>
                </div>

                <form id="avatarForm" enctype="multipart/form-data" action="upload-avatar.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    
                    <div class="text-center mb-6">
                        <div class="relative inline-block">
                            <img id="avatarPreview" 
                                 src="<?php echo getProfilePicForUser(); ?>" 
                                 alt="Profile Preview"
                                 class="w-32 h-32 rounded-full object-cover mx-auto border-4 border-white shadow-lg">
                            <div id="avatarSpinner" class="hidden absolute inset-0 bg-white bg-opacity-80 rounded-full flex items-center justify-center">
                                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                            </div>
                        </div>
                    </div>

                    <input type="file" 
                           id="avatarInput" 
                           name="avatar" 
                           accept="image/*" 
                           class="hidden" 
                           onchange="previewAvatar(this)">

                    <div class="space-y-4">
                        <label for="avatarInput" 
                               class="block w-full bg-blue-50 border-2 border-dashed border-blue-200 rounded-xl py-8 text-center cursor-pointer hover:bg-blue-100 transition">
                            <i class="fas fa-cloud-upload-alt text-blue-500 text-3xl mb-3"></i>
                            <p class="text-blue-700 font-medium">Click to upload photo</p>
                            <p class="text-blue-500 text-sm mt-1">JPG, PNG or GIF (Max 5MB)</p>
                        </label>

                        <div class="flex space-x-3">
                            <button type="button" 
                                    onclick="document.getElementById('avatarInput').click()" 
                                    class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-800 py-3 rounded-lg font-medium transition">
                                Choose File
                            </button>
                            <button type="submit" 
                                    id="uploadBtn"
                                    class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-3 rounded-lg font-medium transition">
                                Upload
                            </button>
                        </div>
                    </div>
                </form>

                <div id="avatarMessage" class="mt-4 hidden"></div>
            </div>
        </div>
    </div>

    <!-- Simple Footer -->
    <footer class="bg-white border-t border-gray-200 mt-8 py-6">
        <div class="container mx-auto px-4 text-center text-gray-500 text-sm">
            <p>© <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. All rights reserved.</p>
        </div>
    </footer>

    <script>
        // Avatar Upload Functions
        function openAvatarUpload() {
            document.getElementById('avatarModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeAvatarUpload() {
            document.getElementById('avatarModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
            document.getElementById('avatarInput').value = '';
        }

        function previewAvatar(input) {
            const preview = document.getElementById('avatarPreview');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Handle avatar form submission
        document.getElementById('avatarForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            // Show loading
            const spinner = document.getElementById('avatarSpinner');
            const uploadBtn = document.getElementById('uploadBtn');
            spinner.classList.remove('hidden');
            uploadBtn.disabled = true;
            uploadBtn.innerHTML = 'Uploading...';
            
            try {
                const response = await fetch('upload-avatar.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showMessage(result.message, 'success');
                    // Update profile picture on page
                    document.getElementById('avatarPreview').src = result.new_url + '?t=' + new Date().getTime();
                    // Close modal after 2 seconds
                    setTimeout(() => {
                        closeAvatarUpload();
                        location.reload();
                    }, 2000);
                } else {
                    showMessage(result.message, 'error');
                }
            } catch (error) {
                showMessage('Network error. Please try again.', 'error');
                console.error('Upload error:', error);
            } finally {
                spinner.classList.add('hidden');
                uploadBtn.disabled = false;
                uploadBtn.innerHTML = 'Upload';
            }
        });

        function showMessage(text, type) {
            const messageDiv = document.getElementById('avatarMessage');
            messageDiv.innerHTML = `
                <div class="p-4 rounded-lg ${type === 'success' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}">
                    <div class="flex items-center">
                        <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} mr-3"></i>
                        <span>${text}</span>
                    </div>
                </div>
            `;
            messageDiv.classList.remove('hidden');
        }

        // Close modal when clicking outside
        document.getElementById('avatarModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeAvatarUpload();
            }
        });

        // Placeholder functions for post interactions
        function toggleLike(postId) {
            alert('Like functionality will be implemented soon! Post ID: ' + postId);
        }

        function toggleCommentBox(postId) {
            alert('Comment functionality will be implemented soon! Post ID: ' + postId);
        }

        function deletePost(postId) {
            if (confirm('Are you sure you want to delete this post?')) {
                alert('Post deletion will be implemented soon! Post ID: ' + postId);
            }
        }
    </script>
</body>
</html>
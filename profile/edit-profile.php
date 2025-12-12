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

$errors = [];
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        // Sanitize inputs
        $full_name = trim($_POST['full_name'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $profile_visibility = $_POST['profile_visibility'] ?? 'public';
        $show_online_status = isset($_POST['show_online_status']) ? 1 : 0;

        // Validate inputs
        if (empty($full_name)) {
            $errors[] = 'Full name is required.';
        } elseif (strlen($full_name) < 2) {
            $errors[] = 'Full name must be at least 2 characters.';
        } elseif (strlen($full_name) > 100) {
            $errors[] = 'Full name must be less than 100 characters.';
        }

        if (strlen($bio) > 500) {
            $errors[] = 'Bio must be less than 500 characters.';
        }

        // Validate profile visibility
        $validVisibility = ['public', 'friends', 'private'];
        if (!in_array($profile_visibility, $validVisibility)) {
            $profile_visibility = 'public';
        }

        // If no errors, update profile
        if (empty($errors)) {
            try {
                // Check if columns exist before updating
                $updateFields = "full_name = ?, bio = ?, updated_at = NOW()";
                $params = [$full_name, $bio];

                // Try to update profile_visibility and show_online_status if columns exist
                try {
                    $checkStmt = db()->query("SHOW COLUMNS FROM users LIKE 'profile_visibility'");
                    if ($checkStmt->rowCount() > 0) {
                        $updateFields .= ", profile_visibility = ?";
                        $params[] = $profile_visibility;
                    }

                    $checkStmt = db()->query("SHOW COLUMNS FROM users LIKE 'show_online_status'");
                    if ($checkStmt->rowCount() > 0) {
                        $updateFields .= ", show_online_status = ?";
                        $params[] = $show_online_status;
                    }
                } catch (Exception $e) {
                    error_log("Column check error: " . $e->getMessage());
                }

                $params[] = $currentUser['id'];

                $stmt = db()->prepare("UPDATE users SET $updateFields WHERE id = ?");
                $result = $stmt->execute($params);

                if ($result) {
                    // Update session
                    $_SESSION['full_name'] = $full_name;

                    // Refresh current user data
                    $currentUser['full_name'] = $full_name;
                    $currentUser['bio'] = $bio;

                    setFlashMessage('success', 'Profile updated successfully!');
                    header('Location: profile.php');
                    exit();
                } else {
                    $errors[] = 'Failed to update profile. Please try again.';
                }
            } catch (PDOException $e) {
                error_log("Update profile error: " . $e->getMessage());
                $errors[] = 'Failed to update profile. Please try again.';
            }
        }
    }
}

// Get current settings
try {
    $stmt = db()->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$currentUser['id']]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($userData) {
        $currentUser = array_merge($currentUser, $userData);
    }
} catch (PDOException $e) {
    error_log("Get user data error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body class="bg-gray-50">
    <!-- Navigation -->
    <?php include '../includes/header.php'; ?>

    <main class="container mx-auto px-4 py-8">
        <div class="max-w-4xl mx-auto">
            <!-- Header -->
            <div class="mb-8">
                <a href="profile.php" class="inline-flex items-center text-blue-600 hover:text-blue-800 mb-4">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Profile
                </a>
                <h1 class="text-3xl font-bold text-gray-900">Edit Profile</h1>
                <p class="text-gray-600 mt-2">Update your personal information</p>
            </div>

            <div class="flex flex-col lg:flex-row gap-8">
                <!-- Left Column - Profile Picture -->
                <div class="lg:w-1/3">
                    <div class="bg-white rounded-xl shadow-sm p-6 sticky top-6">
                        <h3 class="text-lg font-bold text-gray-800 mb-4">Profile Picture</h3>
                        <div class="text-center">
                            <div class="relative inline-block">
                                <img src="<?php echo getProfilePic($currentUser['id']); ?>"
                                    alt="Profile"
                                    id="currentProfilePic"
                                    class="w-32 h-32 rounded-full object-cover border-4 border-white shadow-lg mx-auto">
                                <button onclick="openAvatarModal()"
                                    class="absolute bottom-0 right-0 bg-blue-600 text-white p-2 rounded-full hover:bg-blue-700 transition shadow-md">
                                    <i class="fas fa-camera"></i>
                                </button>
                            </div>
                            <p class="text-gray-500 text-sm mt-4">
                                Click the camera icon to change your profile picture
                            </p>
                        </div>

                        <!-- Account Info -->
                        <div class="mt-8 pt-8 border-t border-gray-200">
                            <h4 class="font-medium text-gray-700 mb-3">Account Information</h4>
                            <div class="space-y-3">
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-600">Username:</span>
                                    <span class="font-medium"><?php echo htmlspecialchars($currentUser['username']); ?></span>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-600">Email:</span>
                                    <span class="font-medium text-right break-all"><?php echo htmlspecialchars($currentUser['email']); ?></span>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-600">Member since:</span>
                                    <span class="font-medium"><?php echo date('M d, Y', strtotime($currentUser['created_at'])); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column - Edit Form -->
                <div class="lg:w-2/3">
                    <div class="bg-white rounded-xl shadow-sm">
                        <!-- Success/Error Messages -->
                        <?php if ($success): ?>
                            <div class="m-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                                <div class="flex items-center">
                                    <i class="fas fa-check-circle text-green-500 mr-3"></i>
                                    <p class="text-green-700"><?php echo htmlspecialchars($success); ?></p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($errors)): ?>
                            <div class="m-6 p-4 bg-red-50 border border-red-200 rounded-lg">
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

                        <!-- Edit Form -->
                        <form method="POST" action="" class="p-6">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">

                            <!-- Full Name -->
                            <div class="mb-6">
                                <label for="full_name" class="block text-sm font-medium text-gray-700 mb-2">
                                    Full Name <span class="text-red-500">*</span>
                                </label>
                                <input type="text"
                                    id="full_name"
                                    name="full_name"
                                    value="<?php echo htmlspecialchars($currentUser['full_name'] ?? ''); ?>"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition"
                                    placeholder="Enter your full name"
                                    required
                                    maxlength="100">
                                <p class="text-gray-500 text-sm mt-1">This is the name that will be displayed on your profile</p>
                            </div>

                            <!-- Bio -->
                            <div class="mb-6">
                                <label for="bio" class="block text-sm font-medium text-gray-700 mb-2">
                                    Bio
                                </label>
                                <textarea id="bio"
                                    name="bio"
                                    rows="4"
                                    maxlength="500"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition resize-none"
                                    placeholder="Tell people about yourself..."><?php echo htmlspecialchars($currentUser['bio'] ?? ''); ?></textarea>
                                <div class="flex justify-between items-center mt-1">
                                    <p class="text-gray-500 text-sm">Share a little about yourself</p>
                                    <span id="bioCounter" class="text-sm text-gray-500">0/500</span>
                                </div>
                            </div>

                            <!-- Privacy Settings -->
                            <div class="mb-8">
                                <h3 class="text-lg font-medium text-gray-800 mb-4">Privacy Settings</h3>
                                <div class="space-y-4">
                                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                        <div>
                                            <h4 class="font-medium text-gray-800">Profile Visibility</h4>
                                            <p class="text-gray-600 text-sm">Who can see your profile</p>
                                        </div>
                                        <select name="profile_visibility" class="border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500">
                                            <option value="public" <?php echo ($currentUser['profile_visibility'] ?? 'public') === 'public' ? 'selected' : ''; ?>>Public</option>
                                            <option value="friends" <?php echo ($currentUser['profile_visibility'] ?? '') === 'friends' ? 'selected' : ''; ?>>Friends Only</option>
                                            <option value="private" <?php echo ($currentUser['profile_visibility'] ?? '') === 'private' ? 'selected' : ''; ?>>Private</option>
                                        </select>
                                    </div>

                                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                        <div>
                                            <h4 class="font-medium text-gray-800">Activity Status</h4>
                                            <p class="text-gray-600 text-sm">Show when you're online</p>
                                        </div>
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox"
                                                name="show_online_status"
                                                class="sr-only peer"
                                                <?php echo ($currentUser['show_online_status'] ?? 1) ? 'checked' : ''; ?>>
                                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="flex items-center justify-between pt-6 border-t border-gray-200">
                                <a href="profile.php"
                                    class="px-6 py-3 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition font-medium">
                                    Cancel
                                </a>
                                <button type="submit"
                                    class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-medium">
                                    <i class="fas fa-save mr-2"></i> Save Changes
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Danger Zone -->
                    <div class="mt-8 bg-white rounded-xl shadow-sm border border-red-200 overflow-hidden">
                        <div class="p-6">
                            <h3 class="text-lg font-bold text-red-700 mb-4 flex items-center">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                Danger Zone
                            </h3>
                            <p class="text-gray-600 mb-6">These actions are permanent and cannot be undone.</p>

                            <div class="space-y-4">
                                <div class="flex items-center justify-between p-4 bg-red-50 rounded-lg">
                                    <div>
                                        <h4 class="font-medium text-gray-800">Change Password</h4>
                                        <p class="text-gray-600 text-sm">Update your account password</p>
                                    </div>
                                    <button onclick="window.location.href='change-password.php'" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition">
                                        Change Password
                                    </button>
                                </div>

                                <div class="flex items-center justify-between p-4 bg-red-50 rounded-lg">
                                    <div>
                                        <h4 class="font-medium text-gray-800">Delete Account</h4>
                                        <p class="text-gray-600 text-sm">Permanently delete your account and all data</p>
                                    </div>
                                    <button onclick="confirmDeleteAccount()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                                        Delete Account
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
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
                    <button onclick="closeAvatarModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-2xl"></i>
                    </button>
                </div>

                <form id="avatarForm" enctype="multipart/form-data">
                    <div class="text-center mb-6">
                        <div class="relative inline-block">
                            <img id="avatarPreview"
                                src="<?php echo getProfilePic($currentUser['id']); ?>"
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

                        <button type="submit"
                            id="uploadBtn"
                            class="w-full bg-blue-600 hover:bg-blue-700 text-white py-3 rounded-lg font-medium transition">
                            <i class="fas fa-upload mr-2"></i> Upload Photo
                        </button>
                    </div>
                </form>

                <div id="avatarMessage" class="mt-4 hidden"></div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <?php include '../includes/footer.php'; ?>

    <script>
        // Bio character counter
        const bioTextarea = document.getElementById('bio');
        const bioCounter = document.getElementById('bioCounter');

        function updateBioCounter() {
            const length = bioTextarea.value.length;
            bioCounter.textContent = `${length}/500`;

            if (length > 500) {
                bioCounter.classList.remove('text-gray-500');
                bioCounter.classList.add('text-red-500');
            } else {
                bioCounter.classList.remove('text-red-500');
                bioCounter.classList.add('text-gray-500');
            }
        }

        bioTextarea.addEventListener('input', updateBioCounter);
        updateBioCounter(); // Initial call

        // Avatar Modal Functions
        function openAvatarModal() {
            document.getElementById('avatarModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeAvatarModal() {
            document.getElementById('avatarModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
            document.getElementById('avatarInput').value = '';
            document.getElementById('avatarMessage').classList.add('hidden');
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

        // Avatar form submission
        document.getElementById('avatarForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const fileInput = document.getElementById('avatarInput');
            if (!fileInput.files || !fileInput.files[0]) {
                showAvatarMessage('Please select an image first', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('avatar', fileInput.files[0]);

            const spinner = document.getElementById('avatarSpinner');
            const uploadBtn = document.getElementById('uploadBtn');
            spinner.classList.remove('hidden');
            uploadBtn.disabled = true;
            uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Uploading...';

            try {
                const response = await fetch('upload-avatar.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    showAvatarMessage(result.message, 'success');
                    // Update all profile pictures on page
                    const newUrl = result.new_url + '?t=' + Date.now();
                    document.getElementById('currentProfilePic').src = newUrl;
                    document.getElementById('avatarPreview').src = newUrl;

                    setTimeout(() => {
                        closeAvatarModal();
                    }, 1500);
                } else {
                    showAvatarMessage(result.message, 'error');
                }
            } catch (error) {
                showAvatarMessage('Network error. Please try again.', 'error');
                console.error('Upload error:', error);
            } finally {
                spinner.classList.add('hidden');
                uploadBtn.disabled = false;
                uploadBtn.innerHTML = '<i class="fas fa-upload mr-2"></i> Upload Photo';
            }
        });

        function showAvatarMessage(text, type) {
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
                closeAvatarModal();
            }
        });

        // Delete account confirmation
        function confirmDeleteAccount() {
            if (confirm('Are you absolutely sure you want to delete your account? This action CANNOT be undone!\n\nAll your posts, friends, messages, and data will be permanently deleted.')) {
                if (confirm('Last chance! Are you really sure? Type your username to confirm.')) {
                    // In a real app, you'd implement this
                    alert('Account deletion would be implemented here. This requires additional confirmation steps.');
                }
            }
        }
    </script>
</body>

</html>
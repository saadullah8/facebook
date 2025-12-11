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
        $full_name = sanitize($_POST['full_name'] ?? '');
        $bio = sanitize($_POST['bio'] ?? '');

        // Validate inputs
        if (empty($full_name)) {
            $errors[] = 'Full name is required.';
        }

        if (strlen($bio) > 500) {
            $errors[] = 'Bio must be less than 500 characters.';
        }

        // If no errors, update profile
        if (empty($errors)) {
            try {
                $stmt = db()->prepare("UPDATE users SET full_name = ?, bio = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$full_name, $bio, $currentUser['id']]);

                // Update session
                $_SESSION['full_name'] = $full_name;

                $success = 'Profile updated successfully!';
                $currentUser['full_name'] = $full_name;
                $currentUser['bio'] = $bio;
            } catch (PDOException $e) {
                error_log("Update profile error: " . $e->getMessage());
                $errors[] = 'Failed to update profile. Please try again.';
            }
        }
    }
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
                                <img src="<?php echo getProfilePic(); ?>"
                                    alt="Profile"
                                    class="w-32 h-32 rounded-full object-cover border-4 border-white shadow-lg mx-auto">
                                <button onclick="window.location.href='upload-avatar.php'"
                                    class="absolute bottom-0 right-0 bg-blue-600 text-white p-2 rounded-full hover:bg-blue-700 transition shadow-md">
                                    <i class="fas fa-camera"></i>
                                </button>
                            </div>
                            <p class="text-gray-500 text-sm mt-4">
                                Click the camera icon to change your profile picture
                            </p>
                            <a href="upload-avatar.php"
                                class="inline-block mt-4 text-blue-600 hover:text-blue-800 font-medium">
                                <i class="fas fa-edit mr-2"></i> Change Photo
                            </a>
                        </div>

                        <!-- Account Info -->
                        <div class="mt-8 pt-8 border-t border-gray-200">
                            <h4 class="font-medium text-gray-700 mb-3">Account Information</h4>
                            <div class="space-y-3">
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Username:</span>
                                    <span class="font-medium"><?php echo htmlspecialchars($currentUser['username']); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Email:</span>
                                    <span class="font-medium"><?php echo htmlspecialchars($currentUser['email']); ?></span>
                                </div>
                                <div class="flex justify-between">
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
                                    required>
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
                                        <select class="border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500">
                                            <option value="public">Public</option>
                                            <option value="friends">Friends Only</option>
                                            <option value="private">Private</option>
                                        </select>
                                    </div>

                                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                        <div>
                                            <h4 class="font-medium text-gray-800">Activity Status</h4>
                                            <p class="text-gray-600 text-sm">Show when you're online</p>
                                        </div>
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" class="sr-only peer" checked>
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
                                    Save Changes
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
                                        <h4 class="font-medium text-gray-800">Deactivate Account</h4>
                                        <p class="text-gray-600 text-sm">Temporarily disable your account</p>
                                    </div>
                                    <button class="px-4 py-2 border border-red-300 text-red-600 rounded-lg hover:bg-red-50 transition">
                                        Deactivate
                                    </button>
                                </div>

                                <div class="flex items-center justify-between p-4 bg-red-50 rounded-lg">
                                    <div>
                                        <h4 class="font-medium text-gray-800">Delete Account</h4>
                                        <p class="text-gray-600 text-sm">Permanently delete your account and all data</p>
                                    </div>
                                    <button class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
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
    </script>
</body>

</html>
<?php
require_once __DIR__ . '/../config.php';

// Check authentication
if (!isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit();
}

$currentUser = getCurrentUser();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        setFlashMessage('error', 'Invalid security token.');
        header('Location: create-post.php');
        exit();
    }

    $content = sanitize($_POST['content'] ?? '');
    $privacy = sanitize($_POST['privacy'] ?? 'public');

    // Validate content
    if (empty(trim($content))) {
        setFlashMessage('error', 'Post content cannot be empty.');
        header('Location: create-post.php');
        exit();
    }

    // Validate privacy setting
    $allowedPrivacy = ['public', 'friends', 'private'];
    if (!in_array($privacy, $allowedPrivacy)) {
        $privacy = 'public';
    }

    $imageFilename = null;

    // Handle image upload if present
    if (isset($_FILES['post_image']) && $_FILES['post_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploadResult = validateFileUpload($_FILES['post_image'], 'post_image');

        if ($uploadResult['success']) {
            $imageFilename = $uploadResult['filename'];
        } else {
            setFlashMessage('error', $uploadResult['message']);
            header('Location: create-post.php');
            exit();
        }
    }

    try {
        // Insert post into database
        $stmt = db()->prepare("
            INSERT INTO posts (user_id, content, image, privacy, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");

        $stmt->execute([$currentUser['id'], $content, $imageFilename, $privacy]);
        $postId = db()->lastInsertId();

        setFlashMessage('success', 'Post created successfully!');

        // Redirect to view the new post or feed
        header('Location: view-post.php?id=' . $postId);
        exit();
    } catch (PDOException $e) {
        error_log("Create post error: " . $e->getMessage());
        setFlashMessage('error', 'Failed to create post. Please try again.');
    }
}

// Set page title
$pageTitle = "Create New Post - " . SITE_NAME;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        #imagePreview img {
            max-height: 400px;
            object-fit: contain;
        }
    </style>
</head>

<body class="bg-gray-50 min-h-screen">
    <!-- Header -->
    <?php include_once '../includes/header.php'; ?>

    <main class="container mx-auto px-4 py-8">
        <div class="max-w-2xl mx-auto">
            <!-- Page Header -->
            <div class="mb-8">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-800">Create New Post</h1>
                        <p class="text-gray-600 mt-2">Share your thoughts with friends</p>
                    </div>
                    <a href="../index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Feed
                    </a>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php echo displayFlashMessages(); ?>

            <!-- Create Post Form -->
            <div class="bg-white rounded-xl shadow-lg border border-gray-200">
                <form method="POST" enctype="multipart/form-data" class="p-6">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">

                    <!-- User Info -->
                    <div class="flex items-center mb-6">
                        <img src="<?php echo getProfilePic(); ?>"
                            alt="Your profile"
                            class="w-12 h-12 rounded-full object-cover border-2 border-white shadow">
                        <div class="ml-4">
                            <h3 class="font-bold text-gray-800">
                                <?php echo htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']); ?>
                            </h3>
                            <div class="mt-1">
                                <select name="privacy"
                                    class="text-sm border-none bg-gray-100 rounded-lg px-3 py-1 focus:ring-2 focus:ring-blue-500">
                                    <option value="public">
                                        <i class="fas fa-globe"></i> Public
                                    </option>
                                    <option value="friends">
                                        <i class="fas fa-users"></i> Friends
                                    </option>
                                    <option value="private">
                                        <i class="fas fa-lock"></i> Only Me
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Content Textarea -->
                    <div class="mb-6">
                        <textarea name="content"
                            id="postContent"
                            rows="6"
                            placeholder="What's on your mind, <?php echo htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']); ?>?"
                            class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent resize-none"
                            required></textarea>
                        <div class="flex justify-between items-center mt-2 text-sm text-gray-500">
                            <span id="charCount">0 characters</span>
                            <span>Maximum 5000 characters</span>
                        </div>
                    </div>

                    <!-- Image Preview -->
                    <div id="imagePreview" class="mb-6 hidden">
                        <div class="relative rounded-lg overflow-hidden border border-gray-300">
                            <img id="previewImage" class="w-full">
                            <button type="button"
                                onclick="removeImage()"
                                class="absolute top-3 right-3 bg-red-500 hover:bg-red-600 text-white p-2 rounded-full shadow-lg">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>

                    <!-- File Input (Hidden) -->
                    <input type="file"
                        id="postImage"
                        name="post_image"
                        accept="image/*"
                        class="hidden"
                        onchange="previewImage(this)">

                    <!-- Actions -->
                    <div class="border-t border-gray-200 pt-6">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-4">
                                <label for="postImage"
                                    class="cursor-pointer flex items-center text-gray-600 hover:text-green-600 px-4 py-2 rounded-lg hover:bg-green-50">
                                    <i class="fas fa-photo-video text-green-500 text-xl mr-2"></i>
                                    <span>Photo/Video</span>
                                </label>
                                <button type="button"
                                    onclick="addFeeling()"
                                    class="flex items-center text-gray-600 hover:text-yellow-600 px-4 py-2 rounded-lg hover:bg-yellow-50">
                                    <i class="fas fa-laugh-beam text-yellow-500 text-xl mr-2"></i>
                                    <span>Feeling/Activity</span>
                                </button>
                                <button type="button"
                                    onclick="addLocation()"
                                    class="flex items-center text-gray-600 hover:text-red-600 px-4 py-2 rounded-lg hover:bg-red-50">
                                    <i class="fas fa-map-marker-alt text-red-500 text-xl mr-2"></i>
                                    <span>Location</span>
                                </button>
                            </div>
                            <div class="flex space-x-3">
                                <button type="button"
                                    onclick="window.history.back()"
                                    class="px-6 py-3 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 font-medium">
                                    Cancel
                                </button>
                                <button type="submit"
                                    class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium shadow hover:shadow-lg transition">
                                    <i class="fas fa-paper-plane mr-2"></i>Post
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Tips -->
            <div class="mt-8 bg-blue-50 border border-blue-200 rounded-xl p-6">
                <h3 class="text-lg font-bold text-blue-800 mb-3">
                    <i class="fas fa-lightbulb mr-2"></i>Posting Tips
                </h3>
                <ul class="space-y-2 text-blue-700">
                    <li class="flex items-start">
                        <i class="fas fa-check-circle mt-1 mr-2 text-green-500"></i>
                        <span>Keep your posts positive and respectful</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-check-circle mt-1 mr-2 text-green-500"></i>
                        <span>Share high-quality images for better engagement</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-check-circle mt-1 mr-2 text-green-500"></i>
                        <span>Use privacy settings to control who sees your posts</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-check-circle mt-1 mr-2 text-green-500"></i>
                        <span>Tag friends when relevant</span>
                    </li>
                </ul>
            </div>
        </div>
    </main>

    <?php include_once '../includes/footer.php'; ?>

    <script>
        // Character counter
        const textarea = document.getElementById('postContent');
        const charCount = document.getElementById('charCount');

        textarea.addEventListener('input', function() {
            const length = this.value.length;
            charCount.textContent = length + ' characters';

            if (length > 5000) {
                charCount.style.color = 'red';
            } else if (length > 4000) {
                charCount.style.color = 'orange';
            } else {
                charCount.style.color = 'green';
            }
        });

        // Image preview
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
            document.getElementById('previewImage').src = '';
        }

        function addFeeling() {
            const feelings = ['😊 Happy', '😢 Sad', '😮 Surprised', '😍 Excited', '😎 Cool', '🤔 Thinking', '🥳 Celebrating'];
            const randomFeeling = feelings[Math.floor(Math.random() * feelings.length)];
            const textarea = document.getElementById('postContent');
            textarea.value += ' Feeling ' + randomFeeling + ' ';
            textarea.focus();
        }

        function addLocation() {
            const textarea = document.getElementById('postContent');
            textarea.value += ' 📍 At my favorite place ';
            textarea.focus();
        }

        // Prevent form submission with Enter key
        textarea.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && e.ctrlKey) {
                document.querySelector('form').submit();
            }
        });
    </script>
</body>

</html>
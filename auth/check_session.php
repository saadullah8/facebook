<?php

/**
 * Authentication Helper Functions
 * DO NOT include anything here - config.php includes everything
 */

// =========== AUTHENTICATION FUNCTIONS ===========

if (!function_exists('isLoggedIn')) {
    function isLoggedIn()
    {
        return isset($_SESSION['user_id']) && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }
}

if (!function_exists('requireAuth')) {
    function requireAuth($redirectUrl = 'auth/login.php')
    {
        if (!isLoggedIn()) {
            $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'] ?? 'index.php';
            $_SESSION['error'] = 'Please log in to access this page.';
            header('Location: ' . $redirectUrl);
            exit();
        }
    }
}

if (!function_exists('getCurrentUserId')) {
    function getCurrentUserId()
    {
        return $_SESSION['user_id'] ?? null;
    }
}

if (!function_exists('getCurrentUser')) {
    function getCurrentUser()
    {
        static $user = null;

        if ($user === null && isLoggedIn()) {
            try {
                $stmt = db()->prepare("SELECT id, username, email, full_name, profile_pic, bio, is_online, last_seen, created_at FROM users WHERE id = ?");
                $stmt->execute([getCurrentUserId()]);
                $user = $stmt->fetch();
            } catch (PDOException $e) {
                error_log("Get user error: " . $e->getMessage());
                return null;
            }
        }

        return $user;
    }
}

// =========== PROFILE FUNCTIONS ===========

if (!function_exists('getProfilePic')) {
    function getProfilePic($userId = null)
    {
        if ($userId === null) {
            // Current user's profile pic
            $user = getCurrentUser();
            $profilePic = $user['profile_pic'] ?? 'default-avatar.jpg';
        } else {
            // Other user's profile pic
            try {
                $stmt = db()->prepare("SELECT profile_pic FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
                $profilePic = $user['profile_pic'] ?? 'default-avatar.jpg';
            } catch (PDOException $e) {
                error_log("Get profile pic error: " . $e->getMessage());
                $profilePic = 'default-avatar.jpg';
            }
        }

        // Check if file exists
        $filePath = UPLOAD_DIR . 'profile_pics/' . $profilePic;
        if (!file_exists($filePath) || $profilePic === 'default-avatar.jpg') {
            return BASE_URL . '/assets/images/default-avatar.jpg';
        }

        return BASE_URL . '/uploads/profile_pics/' . $profilePic;
    }
}

if (!function_exists('getOnlineStatusBadge')) {
    function getOnlineStatusBadge($userId = null)
    {
        if ($userId === null) {
            $user = getCurrentUser();
            $isOnline = $user['is_online'] ?? 0;
        } else {
            try {
                $stmt = db()->prepare("SELECT is_online FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
                $isOnline = $user['is_online'] ?? 0;
            } catch (PDOException $e) {
                error_log("Get online status error: " . $e->getMessage());
                $isOnline = 0;
            }
        }

        if ($isOnline == 1) {
            return '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                    <span class="w-2 h-2 mr-1 bg-green-500 rounded-full"></span>
                    Online
                </span>';
        } else {
            return '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                    <span class="w-2 h-2 mr-1 bg-gray-400 rounded-full"></span>
                    Offline
                </span>';
        }
    }
}

// =========== FRIEND FUNCTIONS ===========

if (!function_exists('countFriends')) {
    function countFriends()
    {
        if (!isLoggedIn()) return 0;

        try {
            $stmt = db()->prepare("
            SELECT COUNT(*) as count FROM friendships 
            WHERE (user1_id = ? OR user2_id = ?) 
            AND status = 'accepted'
        ");
            $stmt->execute([getCurrentUserId(), getCurrentUserId()]);
            $result = $stmt->fetch();
            return $result['count'] ?? 0;
        } catch (PDOException $e) {
            error_log("Count friends error: " . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('getPendingFriendRequestsCount')) {
    function getPendingFriendRequestsCount()
    {
        if (!isLoggedIn()) return 0;

        try {
            $stmt = db()->prepare("
            SELECT COUNT(*) as count FROM friendships 
            WHERE user2_id = ? AND status = 'pending'
        ");
            $stmt->execute([getCurrentUserId()]);
            $result = $stmt->fetch();
            return $result['count'] ?? 0;
        } catch (PDOException $e) {
            error_log("Get pending requests error: " . $e->getMessage());
            return 0;
        }
    }
}

// =========== MESSAGE FUNCTIONS ===========

if (!function_exists('getUnreadMessagesCount')) {
    function getUnreadMessagesCount()
    {
        if (!isLoggedIn()) return 0;

        try {
            $stmt = db()->prepare("
            SELECT COUNT(*) as count FROM messages 
            WHERE receiver_id = ? AND is_read = 0
        ");
            $stmt->execute([getCurrentUserId()]);
            $result = $stmt->fetch();
            return $result['count'] ?? 0;
        } catch (PDOException $e) {
            error_log("Get unread messages error: " . $e->getMessage());
            return 0;
        }
    }
}

// =========== FLASH MESSAGE FUNCTIONS ===========

if (!function_exists('setFlashMessage')) {
    function setFlashMessage($type, $message)
    {
        if (!isset($_SESSION['flash_messages'])) {
            $_SESSION['flash_messages'] = [];
        }

        $_SESSION['flash_messages'][] = [
            'type' => $type,
            'message' => $message,
            'time' => time()
        ];
    }
}

if (!function_exists('displayFlashMessages')) {
    function displayFlashMessages()
    {
        if (empty($_SESSION['flash_messages'])) {
            return '';
        }

        $output = '';
        $currentTime = time();

        foreach ($_SESSION['flash_messages'] as $key => $flash) {
            // Remove old messages (older than 10 seconds)
            if (($currentTime - $flash['time']) > 10) {
                unset($_SESSION['flash_messages'][$key]);
                continue;
            }

            $colorClasses = [
                'success' => 'bg-green-50 border-green-200 text-green-800',
                'error' => 'bg-red-50 border-red-200 text-red-800',
                'info' => 'bg-blue-50 border-blue-200 text-blue-800',
                'warning' => 'bg-yellow-50 border-yellow-200 text-yellow-800'
            ];

            $iconClasses = [
                'success' => 'fas fa-check-circle text-green-500',
                'error' => 'fas fa-exclamation-circle text-red-500',
                'info' => 'fas fa-info-circle text-blue-500',
                'warning' => 'fas fa-exclamation-triangle text-yellow-500'
            ];

            $type = $flash['type'];
            $classes = $colorClasses[$type] ?? $colorClasses['info'];
            $icon = $iconClasses[$type] ?? $iconClasses['info'];

            $output .= '
        <div class="mb-4 p-4 border rounded-xl flex items-start ' . $classes . ' animate-fade-in">
            <i class="' . $icon . ' mt-1 mr-3"></i>
            <div class="flex-1">
                <p class="font-medium">' . htmlspecialchars($flash['message']) . '</p>
            </div>
            <button type="button" onclick="this.parentElement.remove()" class="ml-4 text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>';

            // Remove message after displaying
            unset($_SESSION['flash_messages'][$key]);
        }

        // Clean up array keys
        $_SESSION['flash_messages'] = array_values($_SESSION['flash_messages']);

        return $output;
    }
}

// =========== HELPER FUNCTIONS ===========

if (!function_exists('getFirstName')) {
    function getFirstName($fullName)
    {
        if (empty($fullName)) {
            return 'User';
        }
        $parts = explode(' ', $fullName);
        return $parts[0];
    }
}

if (!function_exists('formatTimeAgo')) {
    function formatTimeAgo($timestamp)
    {
        $time = strtotime($timestamp);
        $now = time();
        $diff = $now - $time;

        if ($diff < 60) {
            return 'Just now';
        } elseif ($diff < 3600) {
            $minutes = floor($diff / 60);
            return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else {
            return date('M j, Y', $time);
        }
    }
}

// =========== VALIDATION FUNCTIONS ===========

if (!function_exists('validateFileUpload')) {
    function validateFileUpload($file, $type = 'post_image')
    {
        $result = [
            'success' => false,
            'message' => '',
            'filename' => ''
        ];

        // Check if file was uploaded
        if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            $result['message'] = 'No file was uploaded.';
            return $result;
        }

        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File size exceeds server limit.',
                UPLOAD_ERR_FORM_SIZE => 'File size exceeds form limit.',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION => 'File upload stopped by extension.'
            ];
            $result['message'] = $errorMessages[$file['error']] ?? 'Unknown upload error.';
            return $result;
        }

        // Check file size
        if ($file['size'] > MAX_FILE_SIZE) {
            $result['message'] = 'File is too large. Maximum size is ' . (MAX_FILE_SIZE / (1024 * 1024)) . 'MB.';
            return $result;
        }

        // Check file type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, ALLOWED_IMAGE_TYPES)) {
            $result['message'] = 'Invalid file type. Allowed types: JPEG, PNG, GIF, WebP.';
            return $result;
        }

        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '_' . time() . '.' . $extension;

        // Determine upload directory
        switch ($type) {
            case 'profile_pic':
                $uploadDir = UPLOAD_DIR . 'profile_pics/';
                break;
            case 'post_image':
                $uploadDir = UPLOAD_DIR . 'post_images/';
                break;
            case 'message_file':
                $uploadDir = UPLOAD_DIR . 'message_files/';
                break;
            default:
                $uploadDir = UPLOAD_DIR . 'uploads/';
        }

        // Create directory if it doesn't exist
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // Move uploaded file
        $destination = $uploadDir . $filename;
        if (move_uploaded_file($file['tmp_name'], $destination)) {
            $result['success'] = true;
            $result['filename'] = $filename;
            $result['message'] = 'File uploaded successfully.';
        } else {
            $result['message'] = 'Failed to move uploaded file.';
        }

        return $result;
    }
}

// Auto-clean old flash messages
if (!empty($_SESSION['flash_messages'])) {
    $currentTime = time();
    $_SESSION['flash_messages'] = array_filter($_SESSION['flash_messages'], function ($flash) use ($currentTime) {
        return ($currentTime - $flash['time']) <= 10;
    });
}

// Update user activity
if (isLoggedIn()) {
    $currentTime = time();
    $lastUpdate = $_SESSION['last_activity_update'] ?? 0;

    if (($currentTime - $lastUpdate) > 300) { // 5 minutes
        try {
            $stmt = db()->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?");
            $stmt->execute([getCurrentUserId()]);
            $_SESSION['last_activity_update'] = $currentTime;
        } catch (PDOException $e) {
            error_log("Update activity error: " . $e->getMessage());
        }
    }
}

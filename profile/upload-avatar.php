<?php
// upload-avatar.php
require_once '../config.php';
require_once '../auth/check_session.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Verify CSRF token
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// Check if file was uploaded
if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
    exit;
}

$file = $_FILES['avatar'];

// Validate file
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
$maxSize = 5 * 1024 * 1024; // 5MB

if (!in_array($file['type'], $allowedTypes)) {
    echo json_encode(['success' => false, 'message' => 'Only JPG, PNG, and GIF files are allowed']);
    exit;
}

if ($file['size'] > $maxSize) {
    echo json_encode(['success' => false, 'message' => 'File size must be less than 5MB']);
    exit;
}

// Generate unique filename
$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = 'avatar_' . $userId . '_' . time() . '.' . $extension;
$uploadPath = '../uploads/profile_pics/' . $filename;

// Create directory if it doesn't exist
if (!is_dir('../uploads/profile_pics/')) {
    mkdir('../uploads/profile_pics/', 0777, true);
}

// Move uploaded file
if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
    // Update database
    try {
        $stmt = db()->prepare("UPDATE users SET profile_pic = ? WHERE id = ?");
        $stmt->execute([$filename, $userId]);
        
        // Update session
        $_SESSION['profile_pic'] = $filename;
        
        echo json_encode([
            'success' => true,
            'message' => 'Profile picture updated successfully',
            'new_url' => '../uploads/profile_pics/' . $filename
        ]);
    } catch (PDOException $e) {
        unlink($uploadPath); // Delete file if database update fails
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to upload file']);
}
<?php
// upload-avatar.php
require_once '../config.php';
require_once '../auth/check_session.php';

// Set JSON header
header('Content-Type: application/json');

// Check authentication
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$currentUser = getCurrentUser();

if (!$currentUser) {
    echo json_encode(['success' => false, 'message' => 'User session not found']);
    exit;
}

$userId = $currentUser['id'];

// Check if file was uploaded
if (!isset($_FILES['avatar'])) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded']);
    exit;
}

$file = $_FILES['avatar'];

// Check for upload errors
if ($file['error'] !== UPLOAD_ERR_OK) {
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize in php.ini',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE in HTML form',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload'
    ];

    $errorMsg = $errorMessages[$file['error']] ?? 'Unknown upload error';
    echo json_encode(['success' => false, 'message' => $errorMsg]);
    exit;
}

// Validate file exists and has content
if (!is_uploaded_file($file['tmp_name']) || $file['size'] == 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid file upload']);
    exit;
}

// Get actual file MIME type using finfo
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

// Validate file type
$allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($mimeType, $allowedTypes)) {
    echo json_encode([
        'success' => false,
        'message' => 'Only JPG, PNG, GIF, and WebP images are allowed'
    ]);
    exit;
}

// Validate file size (5MB max)
$maxSize = 5 * 1024 * 1024; // 5MB
if ($file['size'] > $maxSize) {
    echo json_encode([
        'success' => false,
        'message' => 'File size must be less than 5MB'
    ]);
    exit;
}

// Validate image dimensions (optional but recommended)
$imageInfo = getimagesize($file['tmp_name']);
if ($imageInfo === false) {
    echo json_encode(['success' => false, 'message' => 'Invalid image file']);
    exit;
}

// Check minimum dimensions (optional)
$minWidth = 100;
$minHeight = 100;
if ($imageInfo[0] < $minWidth || $imageInfo[1] < $minHeight) {
    echo json_encode([
        'success' => false,
        'message' => "Image must be at least {$minWidth}x{$minHeight} pixels"
    ]);
    exit;
}

try {
    // Get old profile picture to delete later
    $oldPicStmt = db()->prepare("SELECT profile_pic FROM users WHERE id = ?");
    $oldPicStmt->execute([$userId]);
    $oldPic = $oldPicStmt->fetchColumn();

    // Map MIME type to extension
    $extensionMap = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp'
    ];

    $extension = $extensionMap[$mimeType] ?? 'jpg';

    // Generate unique filename
    $filename = 'avatar_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;

    // Define upload directory
    $uploadDir = __DIR__ . '/../uploads/profile_pics/';
    $uploadPath = $uploadDir . $filename;

    // Create directory if it doesn't exist
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            echo json_encode(['success' => false, 'message' => 'Failed to create upload directory']);
            exit;
        }
    }

    // Verify directory is writable
    if (!is_writable($uploadDir)) {
        echo json_encode(['success' => false, 'message' => 'Upload directory is not writable']);
        exit;
    }

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
        echo json_encode(['success' => false, 'message' => 'Failed to move uploaded file']);
        exit;
    }

    // Optional: Resize/optimize image (requires GD library)
    try {
        resizeImage($uploadPath, $uploadPath, 500, 500); // Resize to max 500x500
    } catch (Exception $e) {
        error_log("Image resize warning: " . $e->getMessage());
        // Continue even if resize fails
    }

    // Update database
    $stmt = db()->prepare("UPDATE users SET profile_pic = ?, updated_at = NOW() WHERE id = ?");
    $result = $stmt->execute([$filename, $userId]);

    if (!$result) {
        // Delete uploaded file if database update fails
        unlink($uploadPath);
        echo json_encode(['success' => false, 'message' => 'Failed to update database']);
        exit;
    }

    // Update session
    $_SESSION['profile_pic'] = $filename;

    // Delete old profile picture (if it exists and is not default)
    if (!empty($oldPic) && $oldPic !== 'default-avatar.png') {
        $oldPath = $uploadDir . $oldPic;
        if (file_exists($oldPath)) {
            unlink($oldPath);
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Profile picture updated successfully',
        'new_url' => '../uploads/profile_pics/' . $filename,
        'filename' => $filename
    ]);
} catch (PDOException $e) {
    // Delete file if it was uploaded but database update failed
    if (isset($uploadPath) && file_exists($uploadPath)) {
        unlink($uploadPath);
    }

    error_log("Upload avatar database error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
} catch (Exception $e) {
    // Delete file if any other error occurred
    if (isset($uploadPath) && file_exists($uploadPath)) {
        unlink($uploadPath);
    }

    error_log("Upload avatar error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Upload failed: ' . $e->getMessage()]);
}

/**
 * Resize image to fit within max dimensions while maintaining aspect ratio
 */
function resizeImage($sourcePath, $destPath, $maxWidth, $maxHeight)
{
    if (!extension_loaded('gd')) {
        throw new Exception("GD library not available");
    }

    $imageInfo = getimagesize($sourcePath);
    if ($imageInfo === false) {
        throw new Exception("Invalid image file");
    }

    list($origWidth, $origHeight, $imageType) = $imageInfo;

    // Don't upscale images
    if ($origWidth <= $maxWidth && $origHeight <= $maxHeight) {
        return; // Image is already small enough
    }

    // Calculate new dimensions
    $ratio = min($maxWidth / $origWidth, $maxHeight / $origHeight);
    $newWidth = round($origWidth * $ratio);
    $newHeight = round($origHeight * $ratio);

    // Create source image
    switch ($imageType) {
        case IMAGETYPE_JPEG:
            $sourceImage = imagecreatefromjpeg($sourcePath);
            break;
        case IMAGETYPE_PNG:
            $sourceImage = imagecreatefrompng($sourcePath);
            break;
        case IMAGETYPE_GIF:
            $sourceImage = imagecreatefromgif($sourcePath);
            break;
        case IMAGETYPE_WEBP:
            $sourceImage = imagecreatefromwebp($sourcePath);
            break;
        default:
            throw new Exception("Unsupported image type");
    }

    if (!$sourceImage) {
        throw new Exception("Failed to create image from source");
    }

    // Create new image
    $newImage = imagecreatetruecolor($newWidth, $newHeight);

    // Preserve transparency for PNG and GIF
    if ($imageType == IMAGETYPE_PNG || $imageType == IMAGETYPE_GIF) {
        imagealphablending($newImage, false);
        imagesavealpha($newImage, true);
        $transparent = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
        imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
    }

    // Resize
    imagecopyresampled(
        $newImage,
        $sourceImage,
        0,
        0,
        0,
        0,
        $newWidth,
        $newHeight,
        $origWidth,
        $origHeight
    );

    // Save resized image
    switch ($imageType) {
        case IMAGETYPE_JPEG:
            imagejpeg($newImage, $destPath, 90);
            break;
        case IMAGETYPE_PNG:
            imagepng($newImage, $destPath, 9);
            break;
        case IMAGETYPE_GIF:
            imagegif($newImage, $destPath);
            break;
        case IMAGETYPE_WEBP:
            imagewebp($newImage, $destPath, 90);
            break;
    }

    // Free memory
    imagedestroy($sourceImage);
    imagedestroy($newImage);
}

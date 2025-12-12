<?php
// send-request.php - COMPATIBLE VERSION
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

error_log("=== Friend Request Script Called ===");

// ---- 1. Ensure logged in ----
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please login first']);
    exit;
}

$currentUser = getCurrentUser();
$currentUserId = (int)$currentUser['id'];
error_log("Current user ID: $currentUserId");

// ---- 2. Get target user_id ----
$userId = 0;

if (!empty($_POST['user_id'])) {
    $userId = (int)$_POST['user_id'];
} elseif (!empty($_GET['user_id'])) {
    $userId = (int)$_GET['user_id'];
} else {
    $jsonInput = file_get_contents('php://input');
    error_log("Raw input: " . $jsonInput);

    if (!empty($jsonInput)) {
        $data = json_decode($jsonInput, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($data['user_id'])) {
            $userId = (int)$data['user_id'];
        }
    }
}

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid User ID. Please provide a valid user ID.'
    ]);
    exit;
}

// Cannot send request to self
if ($userId == $currentUserId) {
    echo json_encode(['success' => false, 'message' => 'You cannot send friend request to yourself']);
    exit;
}

try {
    // ---- Check if action_user_id column exists ----
    $columns = db()->query("SHOW COLUMNS FROM friendships LIKE 'action_user_id'")->fetchAll();
    $hasActionUserId = !empty($columns);
    error_log("action_user_id column exists: " . ($hasActionUserId ? 'YES' : 'NO'));

    // ---- 3. Check if target user exists ----
    $userCheck = db()->prepare("SELECT id, username, full_name FROM users WHERE id = ?");
    $userCheck->execute([$userId]);
    $target = $userCheck->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        error_log("User ID $userId not found in database");
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    $targetName = $target['full_name'] ?: $target['username'];
    error_log("Target user found: $targetName (ID: $userId)");

    // ---- 4. Check existing friendship ----
    $selectSql = "
        SELECT id, status, user1_id, user2_id" . ($hasActionUserId ? ", action_user_id" : "") . "
        FROM friendships 
        WHERE (user1_id = ? AND user2_id = ?)
           OR (user1_id = ? AND user2_id = ?)
        LIMIT 1
    ";

    $q = db()->prepare($selectSql);
    $q->execute([$currentUserId, $userId, $userId, $currentUserId]);
    $existing = $q->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $status = $existing['status'];
        error_log("Existing friendship found: Status=$status");

        if ($status === 'pending') {
            // Check who sent the request
            if ($hasActionUserId) {
                $whoSent = ($existing['action_user_id'] == $currentUserId) ? 'me' : 'them';
            } else {
                // Fallback: assume user1_id is always the sender
                $whoSent = ($existing['user1_id'] == $currentUserId) ? 'me' : 'them';
            }

            if ($whoSent == 'me') {
                echo json_encode(['success' => false, 'message' => 'You have already sent a friend request to this user']);
            } else {
                echo json_encode(['success' => false, 'message' => 'This user has already sent you a friend request. Please check your friend requests.']);
            }
            exit;
        } elseif ($status === 'accepted') {
            echo json_encode(['success' => false, 'message' => 'You are already friends']);
            exit;
        } elseif ($status === 'rejected') {
            // Update rejected to pending
            if ($hasActionUserId) {
                $update = db()->prepare("
                    UPDATE friendships 
                    SET status='pending', 
                        action_user_id=?,
                        user1_id=?,
                        user2_id=?,
                        updated_at=NOW() 
                    WHERE id=?
                ");
                $update->execute([$currentUserId, $currentUserId, $userId, $existing['id']]);
            } else {
                $update = db()->prepare("
                    UPDATE friendships 
                    SET status='pending',
                        user1_id=?,
                        user2_id=?,
                        updated_at=NOW() 
                    WHERE id=?
                ");
                $update->execute([$currentUserId, $userId, $existing['id']]);
            }

            error_log("Updated rejected friendship ID {$existing['id']} to pending");
            echo json_encode(['success' => true, 'message' => "Friend request sent to $targetName"]);
            exit;
        }
    }

    // ---- 5. Create new friend request ----
    error_log("Creating new friend request...");

    if ($hasActionUserId) {
        $insert = db()->prepare("
            INSERT INTO friendships (user1_id, user2_id, action_user_id, status, created_at, updated_at)
            VALUES (?, ?, ?, 'pending', NOW(), NOW())
        ");
        $result = $insert->execute([$currentUserId, $userId, $currentUserId]);
    } else {
        $insert = db()->prepare("
            INSERT INTO friendships (user1_id, user2_id, status, created_at, updated_at)
            VALUES (?, ?, 'pending', NOW(), NOW())
        ");
        $result = $insert->execute([$currentUserId, $userId]);
    }

    if ($result) {
        $friendshipId = db()->lastInsertId();
        error_log("Success! Created friendship ID: $friendshipId");

        // Create notification for target user
        try {
            $notifStmt = db()->prepare("
                INSERT INTO notifications (user_id, type, message, created_at) 
                VALUES (?, 'friend_request', ?, NOW())
            ");
            $notifStmt->execute([
                $userId,
                $currentUser['username'] . " sent you a friend request."
            ]);
        } catch (Exception $e) {
            error_log("Notification error (non-critical): " . $e->getMessage());
        }

        echo json_encode([
            'success' => true,
            'message' => "Friend request sent to $targetName",
            'friendship_id' => $friendshipId,
            'target_name' => $targetName
        ]);
    } else {
        error_log("Failed to insert friendship");
        echo json_encode(['success' => false, 'message' => 'Failed to send friend request']);
    }
} catch (Exception $e) {
    error_log("Error in send-request.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error occurred',
        'error' => $e->getMessage(),
        'details' => 'Check server error logs for more information'
    ]);
}

error_log("=== Script completed ===");

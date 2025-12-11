<?php
// send-request.php - SIMPLIFIED WORKING VERSION
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // For debugging, remove in production
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Simple error logging
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

// Check all possible input methods
if (!empty($_POST['user_id'])) {
    $userId = (int)$_POST['user_id'];
    error_log("Got user_id from POST: $userId");
} elseif (!empty($_GET['user_id'])) {
    $userId = (int)$_GET['user_id'];
    error_log("Got user_id from GET: $userId");
} else {
    // Try JSON input
    $jsonInput = file_get_contents('php://input');
    error_log("Raw input: " . $jsonInput);

    if (!empty($jsonInput)) {
        $data = json_decode($jsonInput, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($data['user_id'])) {
            $userId = (int)$data['user_id'];
            error_log("Got user_id from JSON: $userId");
        }
    }
}

// Debug all inputs
error_log("POST data: " . print_r($_POST, true));
error_log("GET data: " . print_r($_GET, true));

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid User ID. Please provide a valid user ID.',
        'debug' => [
            'received_user_id' => $userId,
            'post_data' => $_POST,
            'get_data' => $_GET
        ]
    ]);
    exit;
}

// Cannot send request to self
if ($userId == $currentUserId) {
    echo json_encode(['success' => false, 'message' => 'You cannot send friend request to yourself']);
    exit;
}

try {
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
    $q = db()->prepare("
        SELECT id, status, user1_id, user2_id 
        FROM friendships 
        WHERE (user1_id = ? AND user2_id = ?)
           OR (user1_id = ? AND user2_id = ?)
        LIMIT 1
    ");
    $q->execute([$currentUserId, $userId, $userId, $currentUserId]);
    $existing = $q->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $status = $existing['status'];
        $whoSent = ($existing['user1_id'] == $currentUserId) ? 'me' : 'them';
        error_log("Existing friendship found: Status=$status, Sent by=$whoSent");

        if ($status === 'pending') {
            $message = ($whoSent == 'me')
                ? 'You have already sent a friend request to this user'
                : 'This user has already sent you a friend request';
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        } elseif ($status === 'accepted') {
            echo json_encode(['success' => false, 'message' => 'You are already friends']);
            exit;
        } elseif ($status === 'rejected') {
            // Update rejected to pending
            $update = db()->prepare("UPDATE friendships SET status='pending', updated_at=NOW() WHERE id=?");
            $update->execute([$existing['id']]);

            error_log("Updated rejected friendship ID {$existing['id']} to pending");
            echo json_encode(['success' => true, 'message' => "Friend request sent to $targetName"]);
            exit;
        }
    }

    // ---- 5. Create new friend request ----
    error_log("Creating new friend request...");
    $insert = db()->prepare("
        INSERT INTO friendships (user1_id, user2_id, status, created_at, updated_at)
        VALUES (?, ?, 'pending', NOW(), NOW())
    ");

    $result = $insert->execute([$currentUserId, $userId]);

    if ($result) {
        $friendshipId = db()->lastInsertId();
        error_log("Success! Created friendship ID: $friendshipId");

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
    error_log("Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error occurred',
        'error' => $e->getMessage()
    ]);
}

error_log("=== Script completed ===");

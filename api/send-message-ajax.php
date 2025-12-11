<?php
require_once '../config.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'Method not allowed']));
}

// Check authentication
if (!isLoggedIn()) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Not authenticated']));
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate input
if (!isset($input['receiver_id']) || !isset($input['message']) || !isset($input['csrf_token'])) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Missing required fields']));
}

// Validate CSRF token
if (!validateCsrfToken($input['csrf_token'])) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Invalid security token']));
}

$receiverId = intval($input['receiver_id']);
$message = sanitize($input['message']);
$senderId = getCurrentUserId();

// Validate message
if (empty($message)) {
    echo json_encode(['success' => false, 'message' => 'Message cannot be empty']);
    exit();
}

if (strlen($message) > 2000) {
    echo json_encode(['success' => false, 'message' => 'Message too long (max 2000 characters)']);
    exit();
}

// Check if trying to send to self
if ($senderId == $receiverId) {
    echo json_encode(['success' => false, 'message' => 'Cannot send message to yourself']);
    exit();
}

try {
    // Check if receiver exists
    $userStmt = db()->prepare("SELECT id FROM users WHERE id = ?");
    $userStmt->execute([$receiverId]);
    $receiver = $userStmt->fetch();

    if (!$receiver) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit();
    }

    // Check if users are friends (optional - you can remove this for public messaging)
    $friendStmt = db()->prepare("
        SELECT id FROM friendships 
        WHERE ((user1_id = ? AND user2_id = ?) OR (user1_id = ? AND user2_id = ?)) 
        AND status = 'accepted'
    ");
    $friendStmt->execute([$senderId, $receiverId, $receiverId, $senderId]);

    if (!$friendStmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'You can only message friends']);
        exit();
    }

    // Insert message
    $insertStmt = db()->prepare("
        INSERT INTO messages (sender_id, receiver_id, message, created_at) 
        VALUES (?, ?, ?, NOW())
    ");
    $insertStmt->execute([$senderId, $receiverId, $message]);

    $messageId = db()->lastInsertId();

    // Get sender info for response
    $senderStmt = db()->prepare("SELECT username, full_name, profile_pic FROM users WHERE id = ?");
    $senderStmt->execute([$senderId]);
    $senderInfo = $senderStmt->fetch();

    // Format response
    $response = [
        'success' => true,
        'message' => 'Message sent',
        'message_id' => $messageId,
        'message_data' => [
            'id' => $messageId,
            'sender_id' => $senderId,
            'receiver_id' => $receiverId,
            'message' => $message,
            'created_at' => date('Y-m-d H:i:s'),
            'formatted_time' => 'Just now',
            'sender_name' => $senderInfo['full_name'] ?? $senderInfo['username'],
            'sender_profile_pic' => getProfilePic($senderId)
        ]
    ];

    echo json_encode($response);
} catch (PDOException $e) {
    error_log("Send message error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}

<?php
require_once '../config.php';

// Check authentication
if (!isLoggedIn()) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Not authenticated']));
}

// Get parameters
$userId = getCurrentUserId();
$otherUserId = isset($_GET['other_user_id']) ? intval($_GET['other_user_id']) : 0;
$lastMessageId = isset($_GET['last_message_id']) ? intval($_GET['last_message_id']) : 0;
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;

try {
    if ($otherUserId > 0) {
        // Get new messages from specific user
        $stmt = db()->prepare("
            SELECT m.*, 
                   u_sender.username as sender_username, 
                   u_sender.full_name as sender_full_name,
                   u_sender.profile_pic as sender_profile_pic,
                   u_receiver.username as receiver_username,
                   u_receiver.full_name as receiver_full_name
            FROM messages m
            JOIN users u_sender ON m.sender_id = u_sender.id
            JOIN users u_receiver ON m.receiver_id = u_receiver.id
            WHERE (
                (m.sender_id = ? AND m.receiver_id = ?) OR 
                (m.sender_id = ? AND m.receiver_id = ?)
            )
            AND m.id > ?
            ORDER BY m.created_at ASC
            LIMIT ?
        ");
        $stmt->execute([$userId, $otherUserId, $otherUserId, $userId, $lastMessageId, $limit]);
        $messages = $stmt->fetchAll();

        // Mark messages as read
        if (!empty($messages)) {
            $updateStmt = db()->prepare("
                UPDATE messages 
                SET is_read = 1 
                WHERE receiver_id = ? 
                AND sender_id = ? 
                AND is_read = 0
            ");
            $updateStmt->execute([$userId, $otherUserId]);
        }
    } else {
        // Get unread messages count for all conversations
        $stmt = db()->prepare("
            SELECT 
                CASE 
                    WHEN sender_id = ? THEN receiver_id
                    ELSE sender_id
                END as other_user_id,
                COUNT(*) as unread_count,
                MAX(created_at) as last_message_time
            FROM messages
            WHERE (sender_id = ? OR receiver_id = ?)
            AND is_read = 0
            AND receiver_id = ?
            GROUP BY other_user_id
        ");
        $stmt->execute([$userId, $userId, $userId, $userId]);
        $unreadMessages = $stmt->fetchAll();

        // Get recent conversations
        $convStmt = db()->prepare("
            SELECT 
                CASE 
                    WHEN m.sender_id = ? THEN m.receiver_id
                    ELSE m.sender_id
                END as other_user_id,
                u.username,
                u.full_name,
                u.profile_pic,
                MAX(m.created_at) as last_message_time,
                SUM(CASE WHEN m.receiver_id = ? AND m.is_read = 0 THEN 1 ELSE 0 END) as unread_count
            FROM messages m
            JOIN users u ON (
                CASE 
                    WHEN m.sender_id = ? THEN m.receiver_id
                    ELSE m.sender_id
                END = u.id
            )
            WHERE m.sender_id = ? OR m.receiver_id = ?
            GROUP BY other_user_id, u.username, u.full_name, u.profile_pic
            ORDER BY last_message_time DESC
            LIMIT 10
        ");
        $convStmt->execute([$userId, $userId, $userId, $userId, $userId]);
        $conversations = $convStmt->fetchAll();

        // Format conversations
        foreach ($conversations as &$conv) {
            $conv['profile_pic_url'] = getProfilePic($conv['other_user_id']);
            $conv['last_message_time_formatted'] = formatTimeAgo($conv['last_message_time']);
        }

        $messages = [
            'conversations' => $conversations,
            'total_unread' => array_sum(array_column($unreadMessages, 'unread_count'))
        ];
    }

    // Format messages for response
    if (isset($messages[0]) && is_array($messages[0]) && isset($messages[0]['id'])) {
        foreach ($messages as &$msg) {
            $msg['formatted_time'] = formatTimeAgo($msg['created_at']);
            $msg['is_own'] = ($msg['sender_id'] == $userId);
            $msg['sender_profile_pic_url'] = getProfilePic($msg['sender_id']);
        }
    }

    echo json_encode([
        'success' => true,
        'data' => $messages,
        'timestamp' => time()
    ]);
} catch (PDOException $e) {
    error_log("Check messages error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}

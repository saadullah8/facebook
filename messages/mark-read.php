<?php
require_once __DIR__ . '/../config.php';
$user_id = $_SESSION['user_id'];
$message_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($message_id > 0) {
    // Verify the message belongs to the current user
    $check_sql = "SELECT id FROM messages WHERE id = ? AND receiver_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ii", $message_id, $user_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows == 1) {
        // Mark as read
        $update_sql = "UPDATE messages SET is_read = 1 WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("i", $message_id);

        if ($update_stmt->execute()) {
            // Redirect back to previous page or inbox
            $redirect = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'inbox.php';
            header("Location: " . $redirect);
            exit();
        } else {
            die("Error marking message as read: " . $conn->error);
        }
    } else {
        die("Message not found or you don't have permission to mark it as read!");
    }
} else {
    die("Invalid message ID!");
}

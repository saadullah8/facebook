<?php
require_once __DIR__ . '/../config.php';

$user_id = $_SESSION['user_id'];
$message_id = isset($_GET['message_id']) ? intval($_GET['message_id']) : 0;

if ($message_id > 0) {
    // Get the specific message
    $sql = "SELECT m.*, u1.username as sender_name, u2.username as receiver_name 
            FROM messages m 
            JOIN users u1 ON m.sender_id = u1.id 
            JOIN users u2 ON m.receiver_id = u2.id 
            WHERE m.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $message_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $message = $result->fetch_assoc();

        // Mark as read if current user is the receiver
        if ($message['receiver_id'] == $user_id && !$message['is_read']) {
            $update_sql = "UPDATE messages SET is_read = 1 WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("i", $message_id);
            $update_stmt->execute();
        }
    } else {
        die("Message not found!");
    }
} else {
    die("No message specified!");
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Message - Messaging System</title>
    <style>
        body {
            font-family: Arial;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }

        .message-header {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .message-content {
            padding: 20px;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            background: white;
        }

        .message-meta {
            color: #6c757d;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .btn {
            padding: 8px 16px;
            text-decoration: none;
            border-radius: 4px;
            display: inline-block;
        }

        .btn-primary {
            background: #007bff;
            color: white;
        }

        .btn-primary:hover {
            background: #0056b3;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .navigation {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }

        .reply-form {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
        }

        .message-info {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }

        .info-item {
            padding: 5px 0;
        }

        .label {
            font-weight: bold;
        }
    </style>
</head>

<body>
    <div class="navigation">
        <a href="inbox.php" class="btn btn-secondary">← Back to Inbox</a>
        <div>
            <?php if ($message['sender_id'] != $user_id): ?>
                <a href="send-message.php?reply_to=<?php echo $message['sender_id']; ?>&subject=Re: <?php echo urlencode($message['subject']); ?>" class="btn btn-primary">Reply</a>
            <?php endif; ?>
        </div>
    </div>

    <h1><?php echo $message['subject'] ?: '(No Subject)'; ?></h1>

    <div class="message-header">
        <div class="message-info">
            <div class="info-item">
                <span class="label">From:</span> <?php echo $message['sender_name']; ?>
            </div>
            <div class="info-item">
                <span class="label">To:</span> <?php echo $message['receiver_name']; ?>
            </div>
            <div class="info-item">
                <span class="label">Date:</span> <?php echo date('F j, Y \a\t g:i A', strtotime($message['created_at'])); ?>
            </div>
            <div class="info-item">
                <span class="label">Status:</span>
                <?php echo $message['is_read'] ? 'Read' : 'Unread'; ?>
            </div>
        </div>
    </div>

    <div class="message-content">
        <?php echo nl2br(htmlspecialchars($message['message'])); ?>
    </div>

    <div style="margin-top: 30px; text-align: center;">
        <a href="send-message.php?reply_to=<?php echo $message['sender_id']; ?>&subject=Re: <?php echo urlencode($message['subject']); ?>" class="btn btn-primary">Reply to this Message</a>
        <a href="inbox.php" class="btn btn-secondary">Return to Inbox</a>
    </div>
</body>

</html>
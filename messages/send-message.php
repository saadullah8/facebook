<?php
require_once __DIR__ . '/../config.php';
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$error = '';
$success = '';

// Get all users except current user for recipient dropdown
$users_sql = "SELECT id, username FROM users WHERE id != ? ORDER BY username";
$users_stmt = $conn->prepare($users_sql);
$users_stmt->bind_param("i", $user_id);
$users_stmt->execute();
$users_result = $users_stmt->get_result();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $receiver_id = sanitize($_POST['receiver_id']);
    $subject = sanitize($_POST['subject']);
    $message = sanitize($_POST['message']);

    if (empty($message)) {
        $error = "Message cannot be empty!";
    } else {
        // Insert message
        $sql = "INSERT INTO messages (sender_id, receiver_id, subject, message) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iiss", $user_id, $receiver_id, $subject, $message);

        if ($stmt->execute()) {
            $success = "Message sent successfully!";
            // Clear form
            $_POST = array();
        } else {
            $error = "Error sending message: " . $conn->error;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send Message - Messaging System</title>
    <style>
        body {
            font-family: Arial;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }

        .error {
            color: red;
            margin-bottom: 15px;
            padding: 10px;
            background: #ffe6e6;
        }

        .success {
            color: green;
            margin-bottom: 15px;
            padding: 10px;
            background: #e6ffe6;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        input[type="text"],
        select,
        textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        textarea {
            height: 200px;
            resize: vertical;
        }

        .btn {
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 4px;
            border: none;
            cursor: pointer;
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
    </style>
</head>

<body>
    <div class="navigation">
        <a href="inbox.php" class="btn btn-secondary">← Back to Inbox</a>
        <h2>Send New Message</h2>
        <div></div>
    </div>

    <?php if ($error): ?>
        <div class="error"><?php echo $error; ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="success"><?php echo $success; ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="form-group">
            <label for="receiver_id">To:</label>
            <select name="receiver_id" id="receiver_id" required>
                <option value="">Select Recipient</option>
                <?php while ($user = $users_result->fetch_assoc()): ?>
                    <option value="<?php echo $user['id']; ?>" <?php echo isset($_POST['receiver_id']) && $_POST['receiver_id'] == $user['id'] ? 'selected' : ''; ?>>
                        <?php echo $user['username']; ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="subject">Subject:</label>
            <input type="text" name="subject" id="subject" value="<?php echo isset($_POST['subject']) ? $_POST['subject'] : ''; ?>" placeholder="Optional subject">
        </div>

        <div class="form-group">
            <label for="message">Message:</label>
            <textarea name="message" id="message" required placeholder="Type your message here..."><?php echo isset($_POST['message']) ? $_POST['message'] : ''; ?></textarea>
        </div>

        <div style="display: flex; gap: 10px;">
            <button type="submit" class="btn btn-primary">Send Message</button>
            <button type="reset" class="btn btn-secondary">Clear Form</button>
        </div>
    </form>

    <script>
        // Auto-resize textarea
        const textarea = document.getElementById('message');
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });
    </script>
</body>

</html>
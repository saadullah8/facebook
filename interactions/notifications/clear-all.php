<?php
require_once '../../config.php';

// Check authentication
if (!isLoggedIn()) {
    header('Location: ../../auth/login.php');
    exit();
}

$currentUser = getCurrentUser();
if (!$currentUser) {
    header('Location: ../../auth/logout.php');
    exit();
}

// Handle clear all
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['error'] = 'Invalid security token.';
    } else {
        try {
            $stmt = db()->prepare("DELETE FROM notifications WHERE user_id = ?");
            $stmt->execute([$currentUser['id']]);
            $_SESSION['success'] = 'All notifications cleared.';
        } catch (PDOException $e) {
            error_log("Clear notifications error: " . $e->getMessage());
            $_SESSION['error'] = 'Failed to clear notifications.';
        }
    }

    header('Location: notifications.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clear All Notifications - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body class="bg-gray-50">
    <!-- Navigation -->
    <?php include '../../includes/header.php'; ?>

    <main class="container mx-auto px-4 py-8 max-w-md">
        <div class="bg-white rounded-xl shadow-sm p-8 text-center">
            <!-- Warning Icon -->
            <div class="inline-block p-4 bg-red-100 rounded-full mb-6">
                <i class="fas fa-exclamation-triangle text-red-600 text-3xl"></i>
            </div>

            <!-- Message -->
            <h2 class="text-2xl font-bold text-gray-900 mb-4">Clear All Notifications?</h2>
            <p class="text-gray-600 mb-6">
                This action cannot be undone. All your notifications will be permanently deleted.
            </p>

            <!-- Form -->
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">

                <div class="flex space-x-4 justify-center">
                    <a href="notifications.php"
                        class="px-6 py-3 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition font-medium">
                        Cancel
                    </a>
                    <button type="submit"
                        class="px-6 py-3 bg-red-600 text-white rounded-lg hover:bg-red-700 transition font-medium">
                        <i class="fas fa-trash-alt mr-2"></i> Clear All
                    </button>
                </div>
            </form>

            <!-- Note -->
            <p class="text-gray-500 text-sm mt-6">
                <i class="fas fa-info-circle mr-1"></i>
                This will delete all notifications, including unread ones.
            </p>
        </div>
    </main>

    <!-- Footer -->
    <?php include '../../includes/footer.php'; ?>
</body>

</html>
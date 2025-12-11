<?php
// Include ONLY config.php - it includes everything else
require_once '../config.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

$error = '';
$success = '';
$token_valid = false;
$user_id = 0;

// Validate reset token
if (isset($_GET['token']) && isset($_GET['id'])) {
    $token = $_GET['token'];
    $user_id = intval($_GET['id']);

    try {
        // Check if token exists and is not expired
        $stmt = db()->prepare("SELECT id, reset_token, reset_token_expires FROM users WHERE id = :id AND reset_token_expires > NOW()");
        $stmt->execute(['id' => $user_id]);
        $user = $stmt->fetch();

        if ($user && password_verify($token, $user['reset_token'])) {
            $token_valid = true;
            $_SESSION['reset_user_id'] = $user_id;
            $_SESSION['reset_token'] = $token;
        } else {
            $error = 'Invalid or expired reset token. Please request a new password reset link.';
        }
    } catch (PDOException $e) {
        error_log("Reset token validation error: " . $e->getMessage());
        $error = 'An error occurred. Please try again.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $error = 'Invalid reset link. Please request a new password reset link.';
}

// Handle password reset form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Validate session
        if (!isset($_SESSION['reset_user_id']) || !isset($_SESSION['reset_token'])) {
            $error = 'Session expired. Please request a new password reset link.';
        } else {
            // Validate passwords
            if (empty($password) || empty($confirm_password)) {
                $error = 'Please enter both password fields.';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters long.';
            } elseif ($password !== $confirm_password) {
                $error = 'Passwords do not match.';
            } else {
                try {
                    $user_id = $_SESSION['reset_user_id'];
                    $token = $_SESSION['reset_token'];

                    // Verify token again before resetting
                    $stmt = db()->prepare("SELECT id, reset_token, reset_token_expires FROM users WHERE id = :id AND reset_token_expires > NOW()");
                    $stmt->execute(['id' => $user_id]);
                    $user = $stmt->fetch();

                    if ($user && password_verify($token, $user['reset_token'])) {
                        // Hash new password
                        $password_hash = password_hash($password, PASSWORD_DEFAULT);

                        // Update password and clear reset token
                        $updateStmt = db()->prepare("UPDATE users SET password = :password, reset_token = NULL, reset_token_expires = NULL, reset_requested_at = NULL WHERE id = :id");
                        $updateStmt->execute([
                            'password' => $password_hash,
                            'id' => $user_id
                        ]);

                        // Clear session
                        unset($_SESSION['reset_user_id']);
                        unset($_SESSION['reset_token']);

                        $success = 'Password has been reset successfully! You can now <a href="login.php" class="text-blue-600 hover:text-blue-800 font-semibold">login</a> with your new password.';
                        $token_valid = false; // Hide form after success
                    } else {
                        $error = 'Invalid or expired reset token. Please request a new password reset link.';
                    }
                } catch (PDOException $e) {
                    error_log("Password reset error: " . $e->getMessage());
                    $error = 'An error occurred. Please try again.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body class="bg-gradient-to-br from-blue-50 to-purple-50 min-h-screen flex items-center justify-center p-4">
    <div class="max-w-md w-full">
        <!-- Header -->
        <div class="text-center mb-8">
            <div class="inline-block p-4 bg-gradient-to-r from-blue-500 to-purple-600 rounded-2xl shadow-lg mb-4">
                <i class="fas fa-lock text-white text-4xl"></i>
            </div>
            <h1 class="text-3xl font-bold text-gray-800">Set New Password</h1>
            <p class="text-gray-600 mt-2">Create a new secure password</p>
        </div>

        <!-- Reset Password Card -->
        <div class="bg-white rounded-2xl shadow-xl p-8">
            <!-- Error Message -->
            <?php if ($error): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl flex items-start">
                    <i class="fas fa-exclamation-circle text-red-500 mt-1 mr-3"></i>
                    <div>
                        <p class="text-red-700 font-medium">Error</p>
                        <p class="text-red-600 text-sm"><?php echo htmlspecialchars($error); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Success Message -->
            <?php if ($success): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-xl flex items-start">
                    <i class="fas fa-check-circle text-green-500 mt-1 mr-3"></i>
                    <div>
                        <p class="text-green-700 font-medium">Success</p>
                        <p class="text-green-600 text-sm"><?php echo $success; ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($token_valid && !$success): ?>
                <!-- Reset Password Form -->
                <form method="POST" action="" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">

                    <!-- Password Field -->
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-lock mr-2"></i>New Password
                        </label>
                        <div class="relative">
                            <input type="password" id="password" name="password"
                                class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
                                placeholder="Enter new password (min. 8 characters)"
                                required minlength="8">
                            <i class="fas fa-lock absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                            <button type="button" onclick="togglePassword('password')" class="absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <p class="text-gray-500 text-sm mt-2">Must be at least 8 characters long.</p>
                    </div>

                    <!-- Confirm Password Field -->
                    <div>
                        <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-lock mr-2"></i>Confirm New Password
                        </label>
                        <div class="relative">
                            <input type="password" id="confirm_password" name="confirm_password"
                                class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
                                placeholder="Confirm new password"
                                required minlength="8">
                            <i class="fas fa-lock absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                            <button type="button" onclick="togglePassword('confirm_password')" class="absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Password Strength Meter -->
                    <div class="space-y-2">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600">Password Strength:</span>
                            <span id="password-strength-text" class="font-medium">Weak</span>
                        </div>
                        <div class="h-2 bg-gray-200 rounded-full overflow-hidden">
                            <div id="password-strength-bar" class="h-full bg-red-500 w-1/4"></div>
                        </div>
                        <div class="text-xs text-gray-500 space-y-1">
                            <p class="flex items-center"><i class="fas fa-check-circle text-green-500 mr-1"></i> At least 8 characters</p>
                            <p class="flex items-center"><i class="fas fa-times-circle text-red-300 mr-1"></i> Include uppercase letter</p>
                            <p class="flex items-center"><i class="fas fa-times-circle text-red-300 mr-1"></i> Include number or symbol</p>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit"
                        class="w-full bg-gradient-to-r from-blue-500 to-purple-600 text-white font-semibold py-3 px-4 rounded-xl hover:from-blue-600 hover:to-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-all duration-200 shadow-md hover:shadow-lg">
                        <i class="fas fa-save mr-2"></i>Reset Password
                    </button>
                </form>
            <?php elseif (!$success): ?>
                <!-- Invalid Token Message -->
                <div class="text-center py-8">
                    <div class="text-yellow-500 text-5xl mb-4">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-800 mb-2">Invalid Reset Link</h3>
                    <p class="text-gray-600 mb-6">Your password reset link is invalid or has expired.</p>
                    <a href="forgot-password.php" class="inline-block bg-gradient-to-r from-blue-500 to-purple-600 text-white font-semibold py-3 px-6 rounded-xl hover:from-blue-600 hover:to-purple-700 transition-all duration-200">
                        <i class="fas fa-redo mr-2"></i>Request New Link
                    </a>
                </div>
            <?php endif; ?>

            <!-- Back to Login -->
            <div class="mt-8 text-center">
                <p class="text-gray-600">
                    Remember your password?
                    <a href="login.php" class="text-blue-600 hover:text-blue-800 font-semibold ml-1">Back to Login</a>
                </p>
            </div>
        </div>

        <!-- Footer -->
        <div class="mt-6 text-center text-gray-500 text-sm">
            <p>© <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. All rights reserved.</p>
            <p class="mt-1">
                <a href="#" class="hover:text-gray-700">Privacy Policy</a> •
                <a href="#" class="hover:text-gray-700">Terms of Service</a>
            </p>
        </div>
    </div>

    <script>
        function togglePassword(fieldId) {
            const passwordInput = document.getElementById(fieldId);
            const eyeIcon = passwordInput.nextElementSibling.querySelector('i');

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.classList.remove('fa-eye');
                eyeIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                eyeIcon.classList.remove('fa-eye-slash');
                eyeIcon.classList.add('fa-eye');
            }
        }

        // Password strength checker
        document.getElementById('password').addEventListener('input', function(e) {
            const password = e.target.value;
            const strengthBar = document.getElementById('password-strength-bar');
            const strengthText = document.getElementById('password-strength-text');

            let strength = 0;
            let color = 'bg-red-500';

            // Length check
            if (password.length >= 8) strength += 25;

            // Uppercase check
            if (/[A-Z]/.test(password)) strength += 25;

            // Lowercase check
            if (/[a-z]/.test(password)) strength += 25;

            // Number/Symbol check
            if (/[0-9!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password)) strength += 25;

            // Update UI
            strengthBar.style.width = strength + '%';

            if (strength <= 25) {
                strengthText.textContent = 'Weak';
                strengthBar.className = 'h-full bg-red-500';
            } else if (strength <= 50) {
                strengthText.textContent = 'Fair';
                strengthBar.className = 'h-full bg-yellow-500';
            } else if (strength <= 75) {
                strengthText.textContent = 'Good';
                strengthBar.className = 'h-full bg-blue-500';
            } else {
                strengthText.textContent = 'Strong';
                strengthBar.className = 'h-full bg-green-500';
            }

            // Update requirement icons
            const requirements = document.querySelectorAll('.text-xs .flex.items-center');
            requirements[1].querySelector('i').className = password.length >= 8 ?
                'fas fa-check-circle text-green-500 mr-1' : 'fas fa-times-circle text-red-300 mr-1';
            requirements[2].querySelector('i').className = /[A-Z]/.test(password) ?
                'fas fa-check-circle text-green-500 mr-1' : 'fas fa-times-circle text-red-300 mr-1';
            requirements[3].querySelector('i').className = /[0-9!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password) ?
                'fas fa-check-circle text-green-500 mr-1' : 'fas fa-times-circle text-red-300 mr-1';
        });
    </script>
</body>

</html>
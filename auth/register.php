<?php
                                            // Include ONLY config.php
                                            require_once '../config.php';
// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

$errors = [];
$success = '';

// Handle registration form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        // Sanitize inputs
        $username = sanitize($_POST['username'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $full_name = sanitize($_POST['full_name'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Validate inputs
        if (empty($username)) {
            $errors[] = 'Username is required.';
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
            $errors[] = 'Username must be 3-20 characters and contain only letters, numbers, and underscores.';
        }

        if (empty($email)) {
            $errors[] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        if (empty($full_name)) {
            $errors[] = 'Full name is required.';
        }

        if (empty($password)) {
            $errors[] = 'Password is required.';
        } elseif (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters long.';
        } elseif ($password !== $confirm_password) {
            $errors[] = 'Passwords do not match.';
        }

        // If no errors, proceed with registration
        if (empty($errors)) {
            try {
                // Check if username or email already exists
                $checkStmt = db()->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                $checkStmt->execute([$username, $email]);

                if ($checkStmt->fetch()) {
                    $errors[] = 'Username or email already exists.';
                } else {
                    // Hash password
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                    // Insert new user
                    $insertStmt = db()->prepare("INSERT INTO users (username, email, password, full_name, created_at) VALUES (?, ?, ?, ?, NOW())");
                    $insertStmt->execute([$username, $email, $hashedPassword, $full_name]);

                    $userId = db()->lastInsertId();

                    // Auto-login after registration
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['username'] = $username;
                    $_SESSION['email'] = $email;
                    $_SESSION['profile_pic'] = 'default-avatar.jpg';
                    $_SESSION['logged_in'] = true;
                    $_SESSION['login_time'] = time();

                    // Set success message and redirect
                    $_SESSION['success'] = 'Registration successful! Welcome to ' . SITE_NAME . '!';
                    header('Location: ../index.php');
                    exit();
                }
            } catch (PDOException $e) {
                error_log("Registration error: " . $e->getMessage());
                $errors[] = 'An error occurred during registration. Please try again.';
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
    <title>Register - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body class="bg-gradient-to-br from-green-50 to-blue-50 min-h-screen flex items-center justify-center p-4">
    <div class="max-w-lg w-full">
        <!-- Header -->
        <div class="text-center mb-8">
            <div class="inline-block p-4 bg-gradient-to-r from-green-500 to-blue-600 rounded-2xl shadow-lg mb-4">
                <i class="fas fa-user-plus text-white text-4xl"></i>
            </div>
            <h1 class="text-3xl font-bold text-gray-800">Join <?php echo SITE_NAME; ?></h1>
            <p class="text-gray-600 mt-2">Create your account in seconds</p>
        </div>

        <!-- Registration Card -->
        <div class="bg-white rounded-2xl shadow-xl p-8">
            <!-- Error Messages -->
            <?php if (!empty($errors)): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-circle text-red-500 mt-1 mr-3"></i>
                        <div>
                            <p class="text-red-700 font-medium">Please fix the following errors:</p>
                            <ul class="mt-2 text-red-600 text-sm list-disc list-inside">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Success Message -->
            <?php if ($success): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-xl flex items-start">
                    <i class="fas fa-check-circle text-green-500 mt-1 mr-3"></i>
                    <div>
                        <p class="text-green-700 font-medium">Success</p>
                        <p class="text-green-600 text-sm"><?php echo htmlspecialchars($success); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Registration Form -->
            <form method="POST" action="" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">

                <!-- Full Name Field -->
                <div>
                    <label for="full_name" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-user-circle mr-2"></i>Full Name
                    </label>
                    <div class="relative">
                        <input type="text" id="full_name" name="full_name"
                            value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>"
                            class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200"
                            placeholder="Enter your full name"
                            required>
                        <i class="fas fa-user-circle absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    </div>
                </div>

                <!-- Username Field -->
                <div>
                    <label for="username" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-at mr-2"></i>Username
                    </label>
                    <div class="relative">
                        <input type="text" id="username" name="username"
                            value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                            class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200"
                            placeholder="Choose a username (3-20 chars)"
                            pattern="[a-zA-Z0-9_]{3,20}"
                            title="3-20 characters, letters, numbers, and underscores only"
                            required>
                        <i class="fas fa-at absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">Only letters, numbers, and underscores allowed</p>
                </div>

                <!-- Email Field -->
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-envelope mr-2"></i>Email Address
                    </label>
                    <div class="relative">
                        <input type="email" id="email" name="email"
                            value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                            class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200"
                            placeholder="Enter your email address"
                            required>
                        <i class="fas fa-envelope absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    </div>
                </div>

                <!-- Password Field -->
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-lock mr-2"></i>Password
                    </label>
                    <div class="relative">
                        <input type="password" id="password" name="password"
                            class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200"
                            placeholder="Create a password (min 8 chars)"
                            minlength="8"
                            required>
                        <i class="fas fa-lock absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        <button type="button" onclick="togglePassword('password')" class="absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">Minimum 8 characters</p>
                </div>

                <!-- Confirm Password Field -->
                <div>
                    <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-lock mr-2"></i>Confirm Password
                    </label>
                    <div class="relative">
                        <input type="password" id="confirm_password" name="confirm_password"
                            class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200"
                            placeholder="Confirm your password"
                            minlength="8"
                            required>
                        <i class="fas fa-lock absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        <button type="button" onclick="togglePassword('confirm_password')" class="absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <!-- Terms Agreement -->
                <div class="flex items-start">
                    <input type="checkbox" id="terms" name="terms"
                        class="h-4 w-4 text-green-600 rounded focus:ring-green-500 border-gray-300 mt-1"
                        required>
                    <label for="terms" class="ml-2 text-sm text-gray-600">
                        I agree to the
                        <a href="#" class="text-green-600 hover:text-green-800 font-medium">Terms of Service</a>
                        and
                        <a href="#" class="text-green-600 hover:text-green-800 font-medium">Privacy Policy</a>
                    </label>
                </div>

                <!-- Submit Button -->
                <button type="submit"
                    class="w-full bg-gradient-to-r from-green-500 to-blue-600 text-white font-semibold py-3 px-4 rounded-xl hover:from-green-600 hover:to-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-all duration-200 shadow-md hover:shadow-lg">
                    <i class="fas fa-user-plus mr-2"></i>Create Account
                </button>
            </form>

            <!-- Divider -->
            <div class="my-8 flex items-center">
                <div class="flex-1 border-t border-gray-300"></div>
                <div class="px-4 text-gray-500 text-sm">or sign up with</div>
                <div class="flex-1 border-t border-gray-300"></div>
            </div>

            <!-- Social Registration Buttons -->
            <div class="grid grid-cols-2 gap-4">
                <button type="button" class="flex items-center justify-center px-4 py-3 border border-gray-300 rounded-xl hover:bg-gray-50 transition duration-200">
                    <i class="fab fa-google text-red-500 mr-2"></i>
                    <span class="text-sm font-medium">Google</span>
                </button>
                <button type="button" class="flex items-center justify-center px-4 py-3 border border-gray-300 rounded-xl hover:bg-gray-50 transition duration-200">
                    <i class="fab fa-facebook text-blue-600 mr-2"></i>
                    <span class="text-sm font-medium">Facebook</span>
                </button>
            </div>

            <!-- Login Link -->
            <div class="mt-8 text-center">
                <p class="text-gray-600">
                    Already have an account?
                    <a href="login.php" class="text-green-600 hover:text-green-800 font-semibold ml-1">Sign in here</a>
                </p>
            </div>
        </div>

        <!-- Footer -->
        <div class="mt-6 text-center text-gray-500 text-sm">
            <p>© <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. All rights reserved.</p>
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
    </script>
</body>

</html>
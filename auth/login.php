<?php
// Include config.php
require_once __DIR__ . '/../config.php';

// Check if user is already logged in and redirect
if (isLoggedIn()) {
    header('Location: ../index.php');
    exit();
}

$error = '';
$success = '';
$debug_info = '';

// Check for logout message
if (isset($_SESSION['logout_message'])) {
    $success = $_SESSION['logout_message'];
    unset($_SESSION['logout_message']);
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // For debugging - show POST data
    if (isset($_GET['debug'])) {
        $debug_info .= "<pre>POST Data: " . print_r($_POST, true) . "</pre>";
    }

    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $username = sanitize($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        // Validate input
        if (empty($username) || empty($password)) {
            $error = 'Please enter both username and password.';
        } else {
            try {
                // Check if user exists - FIXED QUERY
                $stmt = db()->prepare("SELECT id, username, email, password, profile_pic FROM users WHERE username = ? OR email = ?");
                $stmt->execute([$username, $username]);
                $user = $stmt->fetch();

                // Debug: Show user data if found
                if (isset($_GET['debug'])) {
                    $debug_info .= "<pre>User query result: " . print_r($user, true) . "</pre>";
                }

                if ($user) {
                    // Debug: Show password check
                    if (isset($_GET['debug'])) {
                        $debug_info .= "<pre>Password provided: " . $password . "</pre>";
                        $debug_info .= "<pre>Password hash in DB: " . $user['password'] . "</pre>";
                        $debug_info .= "<pre>Password verify result: " . (password_verify($password, $user['password']) ? 'TRUE' : 'FALSE') . "</pre>";
                    }

                    if (password_verify($password, $user['password'])) {
                        // Regenerate session ID for security
                        session_regenerate_id(true);

                        // Set session variables
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['profile_pic'] = $user['profile_pic'];
                        $_SESSION['logged_in'] = true;
                        $_SESSION['login_time'] = time();
                        $_SESSION['created'] = time(); // For session regeneration

                        // Debug: Show session data
                        if (isset($_GET['debug'])) {
                            $debug_info .= "<pre>Session data after login: " . print_r($_SESSION, true) . "</pre>";
                        }

                        // Update last login time
                        $updateStmt = db()->prepare("UPDATE users SET is_online = 1, last_seen = NOW() WHERE id = ?");
                        $updateStmt->execute([$user['id']]);

                        // Set success message
                        setFlashMessage('success', 'Login successful! Welcome back.');

                        // Redirect to intended page or homepage
                        $redirect = $_SESSION['redirect_url'] ?? '../index.php';
                        unset($_SESSION['redirect_url']);

                        if (!isset($_GET['debug'])) {
                            header('Location: ' . $redirect);
                            exit();
                        } else {
                            $debug_info .= "<p>Would redirect to: " . $redirect . "</p>";
                        }
                    } else {
                        $error = 'Invalid username/email or password.';
                    }
                } else {
                    $error = 'Invalid username/email or password.';
                }
            } catch (PDOException $e) {
                error_log("Login error: " . $e->getMessage());
                $error = 'An error occurred. Please try again.';
                if (isset($_GET['debug'])) {
                    $debug_info .= "<pre>Database error: " . $e->getMessage() . "</pre>";
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
    <title>Login - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .debug-panel {
            background: #f0f0f0;
            border: 2px solid #ccc;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
            font-family: monospace;
            font-size: 12px;
        }
    </style>
</head>

<body class="bg-gradient-to-br from-blue-50 to-purple-50 min-h-screen flex items-center justify-center p-4">
    <div class="max-w-md w-full">
        <!-- Debug Panel (only shows if debug=1 in URL) -->
        <?php if (isset($_GET['debug'])): ?>
            <div class="debug-panel">
                <h3>Debug Information</h3>
                <?php echo $debug_info; ?>
            </div>
        <?php endif; ?>

        <!-- Header -->
        <div class="text-center mb-8">
            <div class="inline-block p-4 bg-gradient-to-r from-blue-500 to-purple-600 rounded-2xl shadow-lg mb-4">
                <i class="fas fa-users text-white text-4xl"></i>
            </div>
            <h1 class="text-3xl font-bold text-gray-800">Welcome Back</h1>
            <p class="text-gray-600 mt-2">Sign in to your <?php echo SITE_NAME; ?> account</p>
            <?php if (!isset($_GET['debug'])): ?>
               
            <?php endif; ?>
        </div>

        <!-- Login Card -->
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
                        <p class="text-green-600 text-sm"><?php echo htmlspecialchars($success); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST" action="<?php echo isset($_GET['debug']) ? '?debug=1' : ''; ?>" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">

                <!-- Username/Email Field -->
                <div>
                    <label for="username" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-user mr-2"></i>Username or Email
                    </label>
                    <div class="relative">
                        <input type="text" id="username" name="username"
                            value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                            class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
                            placeholder="Enter username or email"
                            required>
                        <i class="fas fa-user absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    </div>
                </div>

                <!-- Password Field -->
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-lock mr-2"></i>Password
                    </label>
                    <div class="relative">
                        <input type="password" id="password" name="password"
                            class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
                            placeholder="Enter your password"
                            required>
                        <i class="fas fa-lock absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        <button type="button" onclick="togglePassword()" class="absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit"
                    class="w-full bg-gradient-to-r from-blue-500 to-purple-600 text-white font-semibold py-3 px-4 rounded-xl hover:from-blue-600 hover:to-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-all duration-200 shadow-md hover:shadow-lg">
                    <i class="fas fa-sign-in-alt mr-2"></i>Sign In
                </button>
            </form>

            <!-- Registration Link -->
            <div class="mt-8 text-center">
                <p class="text-gray-600">
                    Don't have an account?
                    <a href="register.php" class="text-blue-600 hover:text-blue-800 font-semibold ml-1">Sign up now</a>
                </p>
                
            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
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
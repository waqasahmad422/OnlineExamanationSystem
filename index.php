<?php
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once './app/config.php';
require_once './app/helpers.php';
require_once './app/auth.php';

// Check if user is already logged in
if (is_logged_in() && check_session_validity()) {
    $role = $_SESSION['role'];
    if ($role == 'admin') {
        redirect('./admin/dashboard.php');
    } elseif ($role == 'teacher') {
        redirect('./teacher/dashboard.php');
    } elseif ($role == 'student') {
        redirect('./student/dashboard.php');
    }
}

// Initialize admin contact variables with defaults
$admin_mobile = '+92 123 4567890';
$admin_email = 'admin@exam.com';
$admin_name = 'System Administrator';

// Fetch admin contact information from database including mobile_number
try {
    global $conn;
    $query = "SELECT full_name, email, mobile_number FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id ASC LIMIT 1";
    $result = mysqli_query($conn, $query);

    if ($result && mysqli_num_rows($result) > 0) {
        $admin = mysqli_fetch_assoc($result);
        $admin_name = $admin['full_name'] ?? $admin_name;
        $admin_email = $admin['email'] ?? $admin_email;
        $admin_mobile = $admin['mobile_number'] ?? $admin_mobile; // Get mobile_number from database
    }

    if ($result)
        mysqli_free_result($result);
} catch (Exception $e) {
    error_log("Failed to fetch admin contact: " . $e->getMessage());
}

// Check if role is selected
$selected_role = $_GET['role'] ?? '';
$valid_roles = ['admin', 'teacher', 'student'];

if (in_array($selected_role, $valid_roles)) {
    // Role is selected, show login form
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
        if (!verify_csrf_token($_POST['csrf_token'])) {
            set_message('danger', 'Invalid security token. Please try again.');
        } else {
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';

            // Use role-specific login function
            $result = attempt_login_with_role($username, $password, $selected_role);

            if ($result['success']) {
                $logged_in_role = $result['role'];

                if ($logged_in_role == 'admin') {
                    redirect('./admin/dashboard.php');
                } elseif ($logged_in_role == 'teacher') {
                    redirect('./teacher/dashboard.php');
                } elseif ($logged_in_role == 'student') {
                    redirect('./student/dashboard.php');
                }
            } else {
                set_message('danger', $result['message']);
            }
        }
    }

    $csrf_token = generate_csrf_token();
    $show_login_form = true;
} else {
    // No role selected, show role selection
    $show_login_form = false;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Examination System</title>
    <link href="./assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="./assets/fontawesome/css/all.css">
    <link rel="stylesheet" href="./assets/css/style.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html,
        body {
            width: 100%;
            height: 100%;
            overflow: hidden;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #025F11, #028C1A);
        }

        /* Main Container */
        .main-container {
            width: 100%;
            height: 100%;
            position: relative;
            overflow: hidden;
        }

        /* SECTION 1: Role Selection Screen */
        .role-selection-screen {
            width: 100%;
            height: 100%;
            background: white;
            display: flex;
            flex-direction: column;
            position: absolute;
            top: 0;
            left: 0;
            transition: all 0.5s ease;
            z-index: 10;
        }

        .role-selection-screen.hidden {
            opacity: 0;
            visibility: hidden;
            transform: translateY(-20px);
        }

        /* Role Selection Header */
        .role-header {
            background: #025F11;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
            flex-shrink: 0;
        }

        .role-logo-section {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        /* In the .role-logo-section img styles */
        .role-logo-section img {
            height: 50px;
            width: auto;
            /* Add these lines */
            background-color: white;
            padding: 5px;
            border-radius: 50%;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.8);
        }

        .system-name {
            font-size: 1.4rem;
            font-weight: bold;
        }

        .contact-section {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255, 255, 255, 0.15);
            padding: 8px 20px;
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .contact-section:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-2px);
        }

        .contact-section i {
            font-size: 1.1rem;
        }

        .contact-details h5 {
            margin: 0;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .contact-details p {
            margin: 0;
            font-size: 0.85rem;
            opacity: 0.9;
        }

        /* Role Selection Content */
        .role-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
            overflow: hidden;
        }

        /* Center Logo */
        .center-logo {
            margin-bottom: 25px;
            text-align: center;
        }

        .center-logo img {
            height: 140px;
            width: auto;
            border-radius: 50%;
            box-shadow: 0 5px 15px rgba(2, 95, 17, 0.3);
            border: 5px solid white;
        }

        /* Welcome Message */
        .welcome-message {
            text-align: center;
            margin-bottom: 30px;
            padding: 0 20px;
        }

        .welcome-title {
            font-size: 2.6rem;
            color: #025F11;
            margin-bottom: 10px;
            font-weight: 700;
        }

        .welcome-subtitle {
            color: #666;
            font-size: 1.1rem;
            max-width: 600px;
            line-height: 1.5;
        }

        /* Role Selection Grid */
        .role-grid {
            width: 100%;
            max-width: 900px;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0;
            margin: 0 auto;
        }

        .role-item {
            background: white;
            padding: 25px 15px;
            text-align: center;
            color: #333;
            border-right: 1px solid #eaeaea;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            cursor: pointer;
        }

        .role-item:last-child {
            border-right: none;
        }

        .role-item:hover {
            background: #025F11;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(2, 95, 17, 0.2);
        }

        .role-item:hover .role-icon {
            background: white;
            color: #025F11;
            transform: scale(1.1);
        }

        .role-item:hover .role-description {
            color: rgba(255, 255, 255, 0.9);
        }

        .role-icon {
            width: 70px;
            height: 70px;
            background: #025F11;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            font-size: 1.8rem;
            color: white;
            transition: all 0.3s ease;
        }

        .role-title {
            font-size: 1.4rem;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .role-description {
            color: #666;
            font-size: 0.9rem;
            line-height: 1.4;
            max-width: 220px;
            transition: color 0.3s ease;
        }

        /* Role Selection Footer */
        .role-footer {
            background: #025F11;
            color: white;
            text-align: center;
            padding: 12px;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        .role-footer strong {
            font-weight: 600;
        }

        /* SECTION 2: Login Form Screen - FULL SCREEN WITH #025F11 BACKGROUND */
        .login-screen {
            width: 100%;
            height: 100%;
            position: absolute;
            top: 0;
            left: 0;
            background: #025F11;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: all 0.5s ease;
            z-index: 20;
        }

        .login-screen.active {
            opacity: 1;
            visibility: visible;
        }

        /* Login Card */
        .login-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 480px;
            overflow: hidden;
            transform: translateY(20px) scale(0.95);
            transition: transform 0.5s ease 0.2s;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .login-screen.active .login-card {
            transform: translateY(0) scale(1);
        }

        /* Login Header - #025F11 Background */
        .login-header {
            background: #025F11;
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
        }

        .back-to-roles {
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: white;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .back-to-roles:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-50%) scale(1.1);
        }

        .login-header-icon {
            color: white;
            font-size: 3rem;
            margin-bottom: 15px;
            text-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }

        .login-header h2 {
            margin: 0;
            font-size: 1.8rem;
            font-weight: 700;
            color: white;
        }

        .login-header p {
            margin: 10px 0 0;
            font-size: 14px;
            color: rgba(255, 255, 255, 0.9);
        }

        /* Login Body */
        .login-body {
            padding: 30px;
            background: white;
        }

        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .form-label i {
            color: #025F11;
            margin-right: 8px;
        }

        .form-control-lg {
            padding: 12px 15px;
            font-size: 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .form-control-lg:focus {
            border-color: #025F11;
            box-shadow: 0 0 0 0.2rem rgba(2, 95, 17, 0.25);
        }

        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            padding: 5px;
            z-index: 10;
        }

        .password-toggle:hover {
            color: #025F11;
        }

        .password-wrapper {
            position: relative;
        }

        .password-wrapper input {
            padding-right: 40px;
        }

        .btn-primary {
            background: #025F11;
            border: none;
            padding: 12px;
            font-weight: 600;
            font-size: 16px;
            border-radius: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(2, 95, 17, 0.3);
        }

        .btn-primary:hover {
            background: #028C1A;
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(2, 95, 17, 0.4);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        /* Contact Modal */
        .contact-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .contact-modal-content {
            background: white;
            padding: 25px;
            border-radius: 10px;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .contact-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }

        .contact-modal-title {
            font-size: 1.3rem;
            color: #025F11;
            font-weight: 600;
        }

        .contact-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #666;
            cursor: pointer;
            transition: color 0.3s;
        }

        .contact-close:hover {
            color: #025F11;
        }

        .contact-info-item {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .contact-info-item i {
            color: #025F11;
            font-size: 1.2rem;
            width: 24px;
        }

        .contact-info-text h6 {
            margin: 0 0 5px 0;
            font-size: 0.9rem;
            color: #333;
        }

        .contact-info-text p {
            margin: 0;
            font-size: 0.9rem;
            color: #666;
        }

        /* PHP Fallback Login Page */
        .php-login-page {
            width: 100%;
            height: 100%;
            background: #025F11;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .php-login-page .login-card {
            max-width: 480px;
        }

        /* Alert Messages */
        .alert {
            border-radius: 8px;
            border: none;
            padding: 12px 15px;
            margin-bottom: 20px;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        /* Login Info Text */
        .login-info {
            color: #666;
            font-size: 0.9rem;
        }

        .login-info i {
            color: #025F11;
            margin-right: 5px;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .welcome-title {
                font-size: 2.2rem;
            }

            .center-logo img {
                height: 120px;
            }

            .role-grid {
                max-width: 750px;
            }

            .login-card {
                max-width: 450px;
            }
        }

        @media (max-width: 768px) {
            .role-header {
                padding: 12px 20px;
                flex-direction: column;
                gap: 12px;
            }

            .role-logo-section {
                flex-direction: column;
                text-align: center;
                gap: 8px;
            }

            .role-logo-section img {
                height: 40px;
            }

            .system-name {
                font-size: 1.2rem;
            }

            .welcome-title {
                font-size: 1.8rem;
            }

            .welcome-subtitle {
                font-size: 1rem;
            }

            .center-logo img {
                height: 100px;
            }

            .role-grid {
                grid-template-columns: 1fr;
                max-width: 400px;
            }

            .role-item {
                border-right: none;
                border-bottom: 1px solid #eaeaea;
            }

            .role-item:last-child {
                border-bottom: none;
            }

            .login-card {
                max-width: 400px;
            }

            .login-header {
                padding: 25px 20px;
            }

            .login-body {
                padding: 25px 20px;
            }
        }

        @media (max-width: 576px) {
            .role-content {
                padding: 15px;
            }

            .welcome-title {
                font-size: 1.5rem;
            }

            .welcome-subtitle {
                font-size: 0.9rem;
            }

            .center-logo img {
                height: 80px;
                border-width: 3px;
            }

            .role-icon {
                width: 60px;
                height: 60px;
                font-size: 1.6rem;
            }

            .role-title {
                font-size: 1.2rem;
            }

            .login-card {
                max-width: 350px;
                margin: 0 15px;
            }

            .login-header h2 {
                font-size: 1.5rem;
            }

            .login-header-icon {
                font-size: 2.5rem;
            }

            .back-to-roles {
                width: 35px;
                height: 35px;
                left: 15px;
            }
        }

        @media (max-height: 700px) {
            .center-logo {
                margin-bottom: 15px;
            }

            .center-logo img {
                height: 100px;
            }

            .welcome-title {
                font-size: 2rem;
                margin-bottom: 8px;
            }

            .welcome-subtitle {
                font-size: 1rem;
                margin-bottom: 20px;
            }

            .role-item {
                padding: 15px 10px;
            }

            .role-icon {
                width: 55px;
                height: 55px;
                font-size: 1.5rem;
                margin-bottom: 10px;
            }

            .login-card {
                max-width: 420px;
            }

            .login-header {
                padding: 25px;
            }

            .login-body {
                padding: 25px;
            }
        }

        /* Animation for login card */
        @keyframes cardAppear {
            0% {
                opacity: 0;
                transform: translateY(30px) scale(0.9);
            }

            100% {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .login-screen.active .login-card {
            animation: cardAppear 0.5s ease-out;
        }
    </style>
</head>

<body>
    <div class="main-container">
        <?php if (!$show_login_form): ?>
            <!-- SECTION 1: Role Selection Screen -->
            <div class="role-selection-screen" id="roleSelectionScreen">
                <!-- Header -->
                <div class="role-header">
                    <div class="role-logo-section">
                        <img src="./assets/images/kp_logo.png" alt="System Logo">
                        <div class="system-name">Online Examination System</div>
                    </div>

                    <div class="contact-section" id="contactButton">
                        <i class="fas fa-headset"></i>
                        <div class="contact-details">
                            <h5>Admin Contact</h5>
                            <p><?php echo htmlspecialchars($admin_mobile); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Content Area -->
                <div class="role-content">
                    <!-- Center Logo -->
                    <div class="center-logo">
                        <img src="./assets/images/logo.jpeg" alt="Center Logo">
                    </div>

                    <!-- Welcome Message -->
                    <div class="welcome-message">
                        <h1 class="welcome-title">Welcome to Online Examination System</h1>
                        <p class="welcome-subtitle">Secure, reliable, and efficient platform for conducting online
                            examinations</p>
                    </div>

                    <!-- Role Selection Grid -->
                    <div class="role-grid">
                        <div class="role-item" onclick="showLoginForm('admin')">
                            <div class="role-icon">
                                <i class="fas fa-user-shield"></i>
                            </div>
                            <div class="role-title">Admin</div>
                            <div class="role-description">System administration and management</div>
                        </div>

                        <div class="role-item" onclick="showLoginForm('teacher')">
                            <div class="role-icon">
                                <i class="fas fa-chalkboard-teacher"></i>
                            </div>
                            <div class="role-title">Teacher</div>
                            <div class="role-description">Create and manage examinations</div>
                        </div>

                        <div class="role-item" onclick="showLoginForm('student')">
                            <div class="role-icon">
                                <i class="fas fa-user-graduate"></i>
                            </div>
                            <div class="role-title">Student</div>
                            <div class="role-description">Take exams and view results</div>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="role-footer">
                    <i class="fas fa-copyright"></i> All Rights Reserved by <strong>GDC Ekkghund 2025</strong>
                </div>
            </div>

            <!-- SECTION 2: Login Form Screen (Hidden by default) -->
            <div class="login-screen" id="loginScreen">
                <!-- Login Card Only - No Header/Footer -->
                <div class="login-card">
                    <div class="login-header">
                        <button class="back-to-roles" onclick="hideLoginForm()">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <div class="login-header-icon">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        <h2 id="loginRoleTitle">Admin Login</h2>
                        <p id="loginRoleMessage">Welcome! Please login to continue as Admin</p>
                    </div>
                    <div class="login-body">
                        <form method="POST" action="" id="loginForm">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            <input type="hidden" name="role" id="selectedRole" value="">

                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-user"></i> Username or Email</label>
                                <input type="text" name="username" class="form-control form-control-lg" required autofocus>
                            </div>

                            <div class="mb-4">
                                <label class="form-label"><i class="fas fa-lock"></i> Password</label>
                                <div class="password-wrapper">
                                    <input type="password" name="password" id="passwordField"
                                        class="form-control form-control-lg" required>
                                    <button type="button" id="togglePassword" class="password-toggle">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <button type="submit" name="login" class="btn btn-primary btn-lg w-100" id="loginButton">
                                <i class="fas fa-sign-in-alt"></i> Login as Admin
                            </button>
                        </form>

                        <div class="mt-4 text-center">
                            <small class="login-info">
                                <i class="fas fa-info-circle"></i> <span id="loginRoleInfo">Only Admin credentials are
                                    allowed</span>
                            </small>
                        </div>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- PHP Fallback for Direct URL Access -->
            <div class="php-login-page">
                <div class="login-card">
                    <div class="login-header">
                        <button class="back-to-roles" onclick="window.location.href = window.location.pathname;">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <div class="login-header-icon">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        <h2><?php echo ucfirst($selected_role); ?> Login</h2>
                        <p>Welcome! Please login to continue as <?php echo ucfirst($selected_role); ?></p>
                    </div>
                    <div class="login-body">
                        <?php
                        $message = get_message();
                        if ($message): ?>
                            <div class="alert alert-<?php echo $message['type']; ?> alert-dismissible fade show">
                                <?php echo htmlspecialchars($message['text']); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="" id="loginForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-user"></i> Username or Email</label>
                                <input type="text" name="username" class="form-control form-control-lg" required autofocus>
                            </div>

                            <div class="mb-4">
                                <label class="form-label"><i class="fas fa-lock"></i> Password</label>
                                <div class="password-wrapper">
                                    <input type="password" name="password" id="passwordField2"
                                        class="form-control form-control-lg" required>
                                    <button type="button" id="togglePassword2" class="password-toggle">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <button type="submit" name="login" class="btn btn-primary btn-lg w-100">
                                <i class="fas fa-sign-in-alt"></i> Login as <?php echo ucfirst($selected_role); ?>
                            </button>
                        </form>

                        <div class="mt-4 text-center">
                            <small class="login-info">
                                <i class="fas fa-info-circle"></i> Only <?php echo ucfirst($selected_role); ?> credentials
                                are allowed
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Contact Modal (Shared) -->
        <div class="contact-modal" id="contactModal">
            <div class="contact-modal-content">
                <div class="contact-modal-header">
                    <div class="contact-modal-title">Contact Information</div>
                    <button class="contact-close" id="closeContact">&times;</button>
                </div>
                <div class="contact-info">
                    <div class="contact-info-item">
                        <i class="fas fa-phone"></i>
                        <div class="contact-info-text">
                            <h6>Contact Number</h6>
                            <p><?php echo htmlspecialchars($admin_mobile); ?></p>
                        </div>
                    </div>
                    <div class="contact-info-item">
                        <i class="fas fa-envelope"></i>
                        <div class="contact-info-text">
                            <h6>Email Address</h6>
                            <p><?php echo htmlspecialchars($admin_email); ?></p>
                        </div>
                    </div>
                    <div class="contact-info-item">
                        <i class="fas fa-user-shield"></i>
                        <div class="contact-info-text">
                            <h6>Admin Name</h6>
                            <p><?php echo htmlspecialchars($admin_name); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="./assets/js/bootstrap.bundle.min.js"></script>
    <script>
        function showLoginForm(role) {
            // Update login form content based on role
            document.getElementById('loginRoleTitle').textContent = role.charAt(0).toUpperCase() + role.slice(1) + ' Login';
            document.getElementById('loginRoleMessage').textContent = 'Welcome! Please login to continue as ' + role.charAt(0).toUpperCase() + role.slice(1);
            document.getElementById('loginRoleInfo').textContent = 'Only ' + role.charAt(0).toUpperCase() + role.slice(1) + ' credentials are allowed';
            document.getElementById('selectedRole').value = role;
            document.getElementById('loginButton').innerHTML = '<i class="fas fa-sign-in-alt"></i> Login as ' + role.charAt(0).toUpperCase() + role.slice(1);

            // Hide role selection screen, show login screen
            document.getElementById('roleSelectionScreen').classList.add('hidden');
            setTimeout(() => {
                document.getElementById('loginScreen').classList.add('active');
                // Focus on username field
                document.querySelector('#loginScreen input[name="username"]').focus();
            }, 300);
        }

        function hideLoginForm() {
            // Hide login screen, show role selection screen
            document.getElementById('loginScreen').classList.remove('active');
            setTimeout(() => {
                document.getElementById('roleSelectionScreen').classList.remove('hidden');
            }, 300);
        }

        document.addEventListener('DOMContentLoaded', function () {
            // Password toggle for JavaScript version
            const togglePassword = document.getElementById('togglePassword');
            if (togglePassword) {
                const passwordField = document.getElementById('passwordField');
                const eyeIcon = togglePassword.querySelector('i');

                togglePassword.addEventListener('click', function () {
                    const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordField.setAttribute('type', type);

                    if (type === 'text') {
                        eyeIcon.classList.remove('fa-eye');
                        eyeIcon.classList.add('fa-eye-slash');
                    } else {
                        eyeIcon.classList.remove('fa-eye-slash');
                        eyeIcon.classList.add('fa-eye');
                    }
                });
            }

            // Password toggle for PHP fallback version
            const togglePassword2 = document.getElementById('togglePassword2');
            if (togglePassword2) {
                const passwordField2 = document.getElementById('passwordField2');
                const eyeIcon2 = togglePassword2.querySelector('i');

                togglePassword2.addEventListener('click', function () {
                    const type = passwordField2.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordField2.setAttribute('type', type);

                    if (type === 'text') {
                        eyeIcon2.classList.remove('fa-eye');
                        eyeIcon2.classList.add('fa-eye-slash');
                    } else {
                        eyeIcon2.classList.remove('fa-eye-slash');
                        eyeIcon2.classList.add('fa-eye');
                    }
                });
            }

            // Contact modal functionality
            const contactButton = document.getElementById('contactButton');
            const contactModal = document.getElementById('contactModal');
            const closeContact = document.getElementById('closeContact');

            if (contactButton) {
                contactButton.addEventListener('click', function () {
                    contactModal.style.display = 'flex';
                });
            }

            if (closeContact) {
                closeContact.addEventListener('click', function () {
                    contactModal.style.display = 'none';
                });
            }

            if (contactModal) {
                contactModal.addEventListener('click', function (e) {
                    if (e.target === contactModal) {
                        contactModal.style.display = 'none';
                    }
                });
            }

            // Handle form submission for AJAX version
            const loginForm = document.getElementById('loginForm');
            if (loginForm && !<?php echo $show_login_form ? 'true' : 'false'; ?>) {
                loginForm.addEventListener('submit', function (e) {
                    // Add role parameter to form action for PHP processing
                    const selectedRole = document.getElementById('selectedRole').value;
                    if (selectedRole) {
                        const url = new URL(window.location.href);
                        url.searchParams.set('role', selectedRole);
                        this.action = url.toString();
                    }
                });
            }
        });
    </script>
</body>

</html>
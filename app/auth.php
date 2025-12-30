<?php
require_once 'config.php';
require_once 'helpers.php';

function attempt_login($username, $password) {
    global $conn;
    
    $username = sanitize_input($username);
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $check_attempts = mysqli_prepare($conn, "SELECT COUNT(*) as attempts FROM audit_logs WHERE action = 'login_failed' AND ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    mysqli_stmt_bind_param($check_attempts, "s", $ip_address);
    mysqli_stmt_execute($check_attempts);
    $attempts_result = mysqli_stmt_get_result($check_attempts);
    $attempts_row = mysqli_fetch_assoc($attempts_result);
    mysqli_stmt_close($check_attempts);
    
    if ($attempts_row['attempts'] >= MAX_LOGIN_ATTEMPTS) {
        return ['success' => false, 'message' => 'Too many login attempts. Please try again after 15 minutes.'];
    }
    
    $stmt = mysqli_prepare($conn, "SELECT id, username, password, full_name, email, role, department_id, batch_id, semester, is_active FROM users WHERE username = ? OR email = ?");
    mysqli_stmt_bind_param($stmt, "ss", $username, $username);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if (!$user) {
        log_audit(null, 'login_failed', 'users', null, null, "Username: $username");
        return ['success' => false, 'message' => 'Invalid username or password'];
    }
    
    if (!$user['is_active']) {
        return ['success' => false, 'message' => 'Your account is inactive. Please contact admin.'];
    }
    
    if (password_verify($password, $user['password'])) {
        $session_token = generate_session_token();
        
        $update_stmt = mysqli_prepare($conn, "UPDATE users SET session_token = ?, last_login = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($update_stmt, "si", $session_token, $user['id']);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
        
        session_regenerate_id(true);
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['department_id'] = $user['department_id'];
        $_SESSION['batch_id'] = $user['batch_id'];
        $_SESSION['semester'] = $user['semester'];
        $_SESSION['session_token'] = $session_token;
        
        log_audit($user['id'], 'login_success', 'users', $user['id'], null, null);
        
        return ['success' => true, 'role' => $user['role']];
    } else {
        log_audit(null, 'login_failed', 'users', null, null, "Username: $username");
        return ['success' => false, 'message' => 'Invalid username or password'];
    }
}

// NEW FUNCTION: Role-specific login
function attempt_login_with_role($username, $password, $required_role) {
    global $conn;
    
    $username = sanitize_input($username);
    $required_role = sanitize_input($required_role);
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $check_attempts = mysqli_prepare($conn, "SELECT COUNT(*) as attempts FROM audit_logs WHERE action = 'login_failed' AND ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    mysqli_stmt_bind_param($check_attempts, "s", $ip_address);
    mysqli_stmt_execute($check_attempts);
    $attempts_result = mysqli_stmt_get_result($check_attempts);
    $attempts_row = mysqli_fetch_assoc($attempts_result);
    mysqli_stmt_close($check_attempts);
    
    if ($attempts_row['attempts'] >= MAX_LOGIN_ATTEMPTS) {
        return ['success' => false, 'message' => 'Too many login attempts. Please try again after 15 minutes.'];
    }
    
    // Modified query to check for specific role
    $stmt = mysqli_prepare($conn, "SELECT id, username, password, full_name, email, role, department_id, batch_id, semester, is_active FROM users WHERE (username = ? OR email = ?) AND role = ?");
    mysqli_stmt_bind_param($stmt, "sss", $username, $username, $required_role);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if (!$user) {
        log_audit(null, 'login_failed', 'users', null, null, "Username: $username, Role: $required_role");
        return ['success' => false, 'message' => 'Invalid credentials for ' . ucfirst($required_role) . ' access.'];
    }
    
    if (!$user['is_active']) {
        return ['success' => false, 'message' => 'Your account is inactive. Please contact admin.'];
    }
    
    if (password_verify($password, $user['password'])) {
        $session_token = generate_session_token();
        
        $update_stmt = mysqli_prepare($conn, "UPDATE users SET session_token = ?, last_login = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($update_stmt, "si", $session_token, $user['id']);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
        
        session_regenerate_id(true);
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['department_id'] = $user['department_id'];
        $_SESSION['batch_id'] = $user['batch_id'];
        $_SESSION['semester'] = $user['semester'];
        $_SESSION['session_token'] = $session_token;
        
        log_audit($user['id'], 'login_success', 'users', $user['id'], null, "Role: $required_role");
        
        return ['success' => true, 'role' => $user['role']];
    } else {
        log_audit(null, 'login_failed', 'users', null, null, "Username: $username, Role: $required_role");
        return ['success' => false, 'message' => 'Invalid credentials for ' . ucfirst($required_role) . ' access.'];
    }
}

function check_session_validity() {
    if (!is_logged_in()) {
        return false;
    }
    
    global $conn;
    $user_id = $_SESSION['user_id'];
    $session_token = $_SESSION['session_token'];
    
    $stmt = mysqli_prepare($conn, "SELECT session_token FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if (!$user || $user['session_token'] !== $session_token) {
        logout_user();
        return false;
    }
    
    return true;
}

function logout_user() {
    if (isset($_SESSION['user_id'])) {
        global $conn;
        $user_id = $_SESSION['user_id'];
        
        $stmt = mysqli_prepare($conn, "UPDATE users SET session_token = NULL WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        
        log_audit($user_id, 'logout', 'users', $user_id, null, null);
    }
    
    session_destroy();
    session_start();
}
?>
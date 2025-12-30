<?php
// profile.php
require_once '../app/config.php';
require_once '../app/helpers.php';
require_once '../app/auth.php';

require_role(['admin']);
check_session_validity();

$page_title = 'Admin Profile';

// Get current admin data
$admin_id = $_SESSION['user_id'];
$admin_query = mysqli_prepare($conn, "
    SELECT u.*, d.name as department_name 
    FROM users u 
    LEFT JOIN departments d ON u.department_id = d.id 
    WHERE u.id = ? AND u.role = 'admin'
");
mysqli_stmt_bind_param($admin_query, "i", $admin_id);
mysqli_stmt_execute($admin_query);
$admin_result = mysqli_stmt_get_result($admin_query);
$admin_data = mysqli_fetch_assoc($admin_result);

if (!$admin_data) {
    set_message('danger', 'Admin profile not found.');
    redirect('./dashboard.php');
    exit;
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_message('danger', 'Invalid security token');
        redirect('./profile.php');
        exit;
    }

    $full_name = sanitize_input($_POST['full_name'] ?? '');
    $email = sanitize_input($_POST['email'] ?? '');
    $username = sanitize_input($_POST['username'] ?? '');
    
    // Check if username already exists (excluding current admin)
    $check_username = mysqli_prepare($conn, "SELECT id FROM users WHERE username = ? AND id != ?");
    mysqli_stmt_bind_param($check_username, "si", $username, $admin_id);
    mysqli_stmt_execute($check_username);
    $username_exists = mysqli_stmt_get_result($check_username)->num_rows > 0;

    if ($username_exists) {
        set_message('danger', 'Username already exists. Please choose a different one.');
        redirect('./profile.php');
        exit;
    }

    // Check if email already exists (excluding current admin)
    $check_email = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? AND id != ?");
    mysqli_stmt_bind_param($check_email, "si", $email, $admin_id);
    mysqli_stmt_execute($check_email);
    $email_exists = mysqli_stmt_get_result($check_email)->num_rows > 0;

    if ($email_exists) {
        set_message('danger', 'Email already exists. Please use a different email.');
        redirect('./profile.php');
        exit;
    }

    // Update profile
    $update_query = mysqli_prepare($conn, "
        UPDATE users 
        SET username = ?, full_name = ?, email = ?, updated_at = NOW() 
        WHERE id = ?
    ");
    mysqli_stmt_bind_param($update_query, "sssi", $username, $full_name, $email, $admin_id);
    
    if (mysqli_stmt_execute($update_query)) {
        // Update session data
        $_SESSION['username'] = $username;
        $_SESSION['full_name'] = $full_name;
        
        set_message('success', 'Profile updated successfully!');
    } else {
        set_message('danger', 'Failed to update profile. Please try again.');
    }
    
    redirect('./profile.php');
    exit;
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_message('danger', 'Invalid security token');
        redirect('./profile.php');
        exit;
    }

    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Verify current password
    $verify_query = mysqli_prepare($conn, "SELECT password FROM users WHERE id = ?");
    mysqli_stmt_bind_param($verify_query, "i", $admin_id);
    mysqli_stmt_execute($verify_query);
    $verify_result = mysqli_stmt_get_result($verify_query);
    $user = mysqli_fetch_assoc($verify_result);

    if (!$user || !password_verify($current_password, $user['password'])) {
        set_message('danger', 'Current password is incorrect.');
        redirect('./profile.php');
        exit;
    }

    if ($new_password !== $confirm_password) {
        set_message('danger', 'New passwords do not match.');
        redirect('./profile.php');
        exit;
    }

    if (strlen($new_password) < 6) {
        set_message('danger', 'New password must be at least 6 characters long.');
        redirect('./profile.php');
        exit;
    }

    // Update password
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    $password_query = mysqli_prepare($conn, "UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($password_query, "si", $hashed_password, $admin_id);
    
    if (mysqli_stmt_execute($password_query)) {
        set_message('success', 'Password changed successfully!');
    } else {
        set_message('danger', 'Failed to change password. Please try again.');
    }
    
    redirect('./profile.php');
    exit;
}

$csrf_token = generate_csrf_token();

include '../templates/header.php';
include '../templates/sidebar_admin.php';
?>

<div class="main-content">
    <div class="top-navbar d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="fas fa-user-cog"></i> Admin Profile</h4>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary fs-6">
                <i class="fas fa-shield-alt me-1"></i> Administrator
            </span>
        </div>
    </div>

    <div class="content-area">
        <div class="row">
            <!-- Profile Information -->
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-id-card me-2"></i> Profile Information</h5>
                    </div>
                    <div class="card-body text-center">
                        <div class="mb-4">
                            <div class="avatar-placeholder bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-3" 
                                 style="width: 100px; height: 100px; font-size: 2.5rem;">
                                <i class="fas fa-user-shield"></i>
                            </div>
                            <h4 class="mb-1"><?= htmlspecialchars($admin_data['full_name']); ?></h4>
                            <p class="text-muted mb-2">@<?= htmlspecialchars($admin_data['username']); ?></p>
                            <span class="badge bg-success fs-6">
                                <i class="fas fa-circle me-1"></i> Active
                            </span>
                        </div>

                        <div class="profile-stats">
                            <div class="row text-center">
                                <div class="col-6">
                                    <div class="border-end">
                                        <h5 class="mb-1 text-primary"><?= date('M Y', strtotime($admin_data['created_at'])); ?></h5>
                                        <small class="text-muted">Joined</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <h5 class="mb-1 text-primary">Admin</h5>
                                    <small class="text-muted">Role</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i> Quick Stats</h6>
                    </div>
                    <div class="card-body">
                        <?php
                        // Get stats
                        $total_users = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM users"))['count'];
                        $total_teachers = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM users WHERE role = 'teacher'"))['count'];
                        $total_students = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM users WHERE role = 'student'"))['count'];
                        $total_exams = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM exams"))['count'];
                        ?>
                        <div class="list-group list-group-flush">
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span><i class="fas fa-users text-primary me-2"></i> Total Users</span>
                                <span class="badge bg-primary rounded-pill"><?= $total_users; ?></span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span><i class="fas fa-chalkboard-teacher text-info me-2"></i> Teachers</span>
                                <span class="badge bg-info rounded-pill"><?= $total_teachers; ?></span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span><i class="fas fa-user-graduate text-success me-2"></i> Students</span>
                                <span class="badge bg-success rounded-pill"><?= $total_students; ?></span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span><i class="fas fa-file-alt text-warning me-2"></i> Total Exams</span>
                                <span class="badge bg-warning rounded-pill"><?= $total_exams; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Edit Profile & Change Password -->
            <div class="col-md-8">
                <!-- Edit Profile Form -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-edit me-2"></i> Edit Profile</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Username</label>
                                    <input type="text" name="username" class="form-control" 
                                           value="<?= htmlspecialchars($admin_data['username']); ?>" required>
                                    <div class="form-text">Your unique username for login</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Full Name</label>
                                    <input type="text" name="full_name" class="form-control" 
                                           value="<?= htmlspecialchars($admin_data['full_name']); ?>" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email Address</label>
                                <input type="email" name="email" class="form-control" 
                                       value="<?= htmlspecialchars($admin_data['email']); ?>">
                                <div class="form-text">Your contact email address</div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Role</label>
                                    <input type="text" class="form-control" value="Administrator" readonly disabled>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Account Created</label>
                                    <input type="text" class="form-control" 
                                           value="<?= date('F j, Y', strtotime($admin_data['created_at'])); ?>" readonly disabled>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Last Updated</label>
                                <input type="text" class="form-control" 
                                       value="<?= $admin_data['updated_at'] ? date('F j, Y g:i A', strtotime($admin_data['updated_at'])) : 'Never'; ?>" 
                                       readonly disabled>
                            </div>
                            <button type="submit" name="update_profile" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i> Update Profile
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Change Password Form -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-key me-2"></i> Change Password</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">
                            <div class="mb-3">
                                <label class="form-label">Current Password</label>
                                <input type="password" name="current_password" class="form-control" required placeholder="Enter your current password">
                                <div class="form-text">Enter your current password for verification</div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">New Password</label>
                                    <div class="input-group">
                                        <input type="password" name="new_password" id="newPassword" class="form-control" required minlength="6" placeholder="Enter new password">
                                        <button type="button" class="btn btn-outline-secondary toggle-password" data-target="newPassword">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <div class="form-text">Minimum 6 characters</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Confirm New Password</label>
                                    <div class="input-group">
                                        <input type="password" name="confirm_password" id="confirmPassword" class="form-control" required minlength="6" placeholder="Confirm new password">
                                        <button type="button" class="btn btn-outline-secondary toggle-password" data-target="confirmPassword">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" name="change_password" class="btn btn-warning">
                                <i class="fas fa-key me-2"></i> Change Password
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.toggle-password {
    border-left: 0;
    border-color: #ced4da;
}

.toggle-password:hover {
    background-color: #f8f9fa;
    color:black;
}

.input-group .form-control:focus {
    z-index: 3;
}

.input-group > .btn {
    z-index: 2;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Password toggle functionality
    const toggleButtons = document.querySelectorAll('.toggle-password');
    
    toggleButtons.forEach(button => {
        button.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const passwordInput = document.getElementById(targetId);
            const icon = this.querySelector('i');
            
            // Toggle password visibility
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            // Toggle eye icon
            if (type === 'text') {
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
    });
    
    // Prevent form submission when pressing Enter on toggle buttons
    toggleButtons.forEach(button => {
        button.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.click();
            }
        });
    });
});
</script>

<?php include '../templates/footer.php'; ?>
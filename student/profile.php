<?php
require_once '../app/config.php';
require_once '../app/helpers.php';
require_once '../app/auth.php';

require_role(['student']);
check_session_validity();

$page_title = 'Student Profile';
$student_id = $_SESSION['user_id'];

// Fetch student info
$student_query = mysqli_prepare($conn, "
    SELECT u.*, d.name AS department_name, b.name AS batch_name
    FROM users u
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN batches b ON u.batch_id = b.id
    WHERE u.id = ? AND u.role = 'student'
");
mysqli_stmt_bind_param($student_query, "i", $student_id);
mysqli_stmt_execute($student_query);
$result = mysqli_stmt_get_result($student_query);
$student = mysqli_fetch_assoc($result);

if (!$student) {
    set_message('danger', 'Student profile not found.');
    redirect('./dashboard.php');
    exit;
}

/* ===========================
        UPDATE PROFILE
   =========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {

    if (!verify_csrf_token($_POST['csrf_token'])) {
        set_message('danger', 'Invalid CSRF Token');
        redirect('./profile.php');
        exit;
    }

    $full_name  = sanitize_input($_POST['full_name']);
    $email      = sanitize_input($_POST['email']);
    $username   = sanitize_input($_POST['username']);

    // Check username uniqueness
    $check_user = mysqli_prepare($conn, "SELECT id FROM users WHERE username = ? AND id != ?");
    mysqli_stmt_bind_param($check_user, "si", $username, $student_id);
    mysqli_stmt_execute($check_user);
    if (mysqli_stmt_get_result($check_user)->num_rows > 0) {
        set_message('danger', 'Username already exists.');
        redirect('./profile.php');
        exit;
    }

    // Check email uniqueness
    $check_email = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? AND id != ?");
    mysqli_stmt_bind_param($check_email, "si", $email, $student_id);
    mysqli_stmt_execute($check_email);
    if (mysqli_stmt_get_result($check_email)->num_rows > 0) {
        set_message('danger', 'Email already exists.');
        redirect('./profile.php');
        exit;
    }

    // Update profile
    $update = mysqli_prepare($conn, "
        UPDATE users 
        SET full_name = ?, username = ?, email = ?, updated_at = NOW()
        WHERE id = ?
    ");
    mysqli_stmt_bind_param($update, "sssi", $full_name, $username, $email, $student_id);

    if (mysqli_stmt_execute($update)) {
        $_SESSION['full_name'] = $full_name;
        $_SESSION['username']  = $username;
        set_message('success', 'Profile updated successfully.');
    } else {
        set_message('danger', 'Failed to update profile.');
    }

    redirect('./profile.php');
    exit;
}

/* ===========================
        CHANGE PASSWORD
   =========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {

    if (!verify_csrf_token($_POST['csrf_token'])) {
        set_message('danger', 'Invalid CSRF Token');
        redirect('./profile.php');
        exit;
    }

    $new_password      = $_POST['new_password'];
    $confirm_password  = $_POST['confirm_password'];

    if ($new_password !== $confirm_password) {
        set_message('danger', 'New passwords do not match.');
        redirect('./profile.php');
        exit;
    }

    if (strlen($new_password) < 6) {
        set_message('danger', 'Password must be at least 6 characters.');
        redirect('./profile.php');
        exit;
    }

    // Update password (no current password required)
    $hashed = password_hash($new_password, PASSWORD_DEFAULT);

    $pwd = mysqli_prepare($conn, "
        UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?
    ");
    mysqli_stmt_bind_param($pwd, "si", $hashed, $student_id);

    if (mysqli_stmt_execute($pwd)) {
        set_message('success', 'Password updated successfully.');
    } else {
        set_message('danger', 'Failed to update password.');
    }

    redirect('./profile.php');
    exit;
}

$csrf_token = generate_csrf_token();

include '../templates/header.php';
include '../templates/sidebar_student.php';
?>

<div class="main-content">
    <div class="top-navbar d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="fas fa-user"></i> Student Profile</h4>
        <span class="text-muted">Welcome, <?= htmlspecialchars($_SESSION['full_name']); ?></span>
    </div>

    <div class="content-area">
        <div class="row">

            <!-- LEFT COLUMN -->
            <div class="col-md-4">

                <!-- Profile Info -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-id-card"></i> Profile Information</h5>
                    </div>

                    <div class="card-body text-center">
                        <div class="avatar-placeholder bg-primary text-white rounded-circle 
                             d-inline-flex align-items-center justify-content-center mb-3"
                             style="width: 100px; height: 100px; font-size: 2.5rem;">
                            <i class="fas fa-user-graduate"></i>
                        </div>

                        <h4 class="mb-1"><?= htmlspecialchars($student['full_name']); ?></h4>
                        <p class="text-muted">@<?= htmlspecialchars($student['username']); ?></p>
                        <span class="badge bg-success">Active</span>

                        <hr>

                        <p class="mb-1"><strong>Department:</strong> <?= $student['department_name'] ?: 'N/A'; ?></p>
                        <p class="mb-1"><strong>Batch:</strong> <?= $student['batch_name'] ?: 'N/A'; ?></p>
                        <p class="mb-1"><strong>Joined:</strong> <?= date('M Y', strtotime($student['created_at'])); ?></p>
                    </div>
                </div>
            </div>

            <!-- RIGHT COLUMN -->
            <div class="col-md-8">

                <!-- Update Profile -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="fas fa-edit"></i> Edit Profile</h5>
                    </div>

                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label>Full Name</label>
                                    <input type="text" name="full_name" class="form-control"
                                           value="<?= htmlspecialchars($student['full_name']); ?>" required>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label>Username</label>
                                    <input type="text" name="username" class="form-control"
                                           value="<?= htmlspecialchars($student['username']); ?>" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label>Email</label>
                                <input type="email" name="email" class="form-control"
                                       value="<?= htmlspecialchars($student['email']); ?>">
                            </div>

                            <button type="submit" name="update_profile" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update Profile
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Change Password -->
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-key"></i> Change Password</h5>
                    </div>

                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label>New Password</label>
                                    <input type="password" name="new_password" class="form-control"
                                           minlength="6" required>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label>Confirm Password</label>
                                    <input type="password" name="confirm_password" class="form-control"
                                           minlength="6" required>
                                </div>
                            </div>

                            <button type="submit" name="change_password" class="btn btn-warning">
                                <i class="fas fa-key"></i> Update Password
                            </button>
                        </form>
                    </div>
                </div>

            </div> <!-- RIGHT COL END -->

        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>

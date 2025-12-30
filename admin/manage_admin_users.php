<?php
require_once '../app/config.php';
require_once '../app/helpers.php';
require_once '../app/auth.php';
require_once '../app/admin_handlers.php';

require_role(['admin']);
check_session_validity();

$page_title = 'Manage Admin Users';

// Validation function for username and name
function validate_input($input, $type) {
    // Remove extra whitespace
    $input = trim($input);
    
    if ($type === 'username') {
        // Username: only alphanumeric characters (no underscores, no dots)
        if (!preg_match('/^[a-zA-Z0-9]+$/', $input)) {
            return "Username can only contain letters and numbers (no spaces, underscores, dots, or special characters)";
        }
        if (strlen($input) < 3) {
            return "Username must be at least 3 characters long";
        }
        if (strlen($input) > 30) {
            return "Username cannot exceed 30 characters";
        }
    }
    
    if ($type === 'name') {
        // Name: only letters, spaces, and basic punctuation
        if (!preg_match('/^[a-zA-Z\s\'\-\.]+$/', $input)) {
            return "Name can only contain letters, spaces, apostrophes, hyphens, and dots";
        }
        if (strlen($input) < 2) {
            return "Name must be at least 2 characters long";
        }
        if (strlen($input) > 100) {
            return "Name cannot exceed 100 characters";
        }
    }
    
    if ($type === 'email') {
        // Email: basic email validation
        if (!empty($input) && !filter_var($input, FILTER_VALIDATE_EMAIL)) {
            return "Please enter a valid email address";
        }
    }
    
    if ($type === 'mobile') {
        // Mobile number: allow various formats
        if (!empty($input)) {
            // Remove all non-digit characters for validation
            $clean_number = preg_replace('/[^0-9]/', '', $input);
            if (strlen($clean_number) < 10 || strlen($clean_number) > 15) {
                return "Mobile number should be 10-15 digits long";
            }
        }
    }
    
    return true;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_message('danger', 'Invalid security token');
        redirect('./manage_admin_users.php');
        exit;
    }

    // CREATE ADMIN
    if (isset($_POST['create_user'])) {
        // Validate inputs
        $username = sanitize_input($_POST['username'] ?? '');
        $full_name = sanitize_input($_POST['full_name'] ?? '');
        $email = sanitize_input($_POST['email'] ?? '');
        $mobile_number = sanitize_input($_POST['mobile_number'] ?? '');
        
        $username_validation = validate_input($username, 'username');
        $name_validation = validate_input($full_name, 'name');
        $email_validation = validate_input($email, 'email');
        $mobile_validation = validate_input($mobile_number, 'mobile');
        
        if ($username_validation !== true) {
            set_message('danger', $username_validation);
            redirect('./manage_admin_users.php');
            exit;
        }
        
        if ($name_validation !== true) {
            set_message('danger', $name_validation);
            redirect('./manage_admin_users.php');
            exit;
        }
        
        if ($email_validation !== true) {
            set_message('danger', $email_validation);
            redirect('./manage_admin_users.php');
            exit;
        }
        
        if ($mobile_validation !== true) {
            set_message('danger', $mobile_validation);
            redirect('./manage_admin_users.php');
            exit;
        }
        
        // Check if username already exists
        $check_sql = "SELECT id FROM users WHERE username = '" . mysqli_real_escape_string($conn, $username) . "'";
        $check_result = mysqli_query($conn, $check_sql);
        if (mysqli_num_rows($check_result) > 0) {
            set_message('danger', 'Username "' . htmlspecialchars($username) . '" already exists. Please choose a different username.');
            redirect('./manage_admin_users.php');
            exit;
        }
        
        // Check if email already exists (if provided)
        if (!empty($email)) {
            $email_check_sql = "SELECT id FROM users WHERE email = '" . mysqli_real_escape_string($conn, $email) . "'";
            $email_check_result = mysqli_query($conn, $email_check_sql);
            if (mysqli_num_rows($email_check_result) > 0) {
                set_message('danger', 'Email "' . htmlspecialchars($email) . '" already exists. Please use a different email address.');
                redirect('./manage_admin_users.php');
                exit;
            }
        }
        
        // Check if mobile number already exists (if provided)
        if (!empty($mobile_number)) {
            $mobile_check_sql = "SELECT id FROM users WHERE mobile_number = '" . mysqli_real_escape_string($conn, $mobile_number) . "'";
            $mobile_check_result = mysqli_query($conn, $mobile_check_sql);
            if (mysqli_num_rows($mobile_check_result) > 0) {
                set_message('danger', 'Mobile number "' . htmlspecialchars($mobile_number) . '" already exists. Please use a different mobile number.');
                redirect('./manage_admin_users.php');
                exit;
            }
        }
        
        $result = create_user(
            $username,
            $_POST['password'] ?? '',
            $full_name,
            $email,
            $mobile_number,
            'admin',
            null, // department_id
            null, // batch_id
            null, // semester
            null  // roll_number
        );
        
        if ($result['success']) {
            // FIXED: Use custom success message
            set_message('success', 'Admin user "' . htmlspecialchars($username) . '" created successfully!');
        } else {
            set_message('danger', $result['message']);
        }
        redirect('./manage_admin_users.php');
        exit;
    }

    // EDIT ADMIN
    if (isset($_POST['edit_user'])) {
        $id = intval($_POST['edit_user_id']);
        $username = sanitize_input($_POST['username'] ?? '');
        $full_name = sanitize_input($_POST['full_name'] ?? '');
        $email = sanitize_input($_POST['email'] ?? '');
        $mobile_number = sanitize_input($_POST['mobile_number'] ?? '');
        
        // Validate inputs
        $username_validation = validate_input($username, 'username');
        $name_validation = validate_input($full_name, 'name');
        $email_validation = validate_input($email, 'email');
        $mobile_validation = validate_input($mobile_number, 'mobile');
        
        if ($username_validation !== true) {
            set_message('danger', $username_validation);
            redirect('./manage_admin_users.php');
            exit;
        }
        
        if ($name_validation !== true) {
            set_message('danger', $name_validation);
            redirect('./manage_admin_users.php');
            exit;
        }
        
        if ($email_validation !== true) {
            set_message('danger', $email_validation);
            redirect('./manage_admin_users.php');
            exit;
        }
        
        if ($mobile_validation !== true) {
            set_message('danger', $mobile_validation);
            redirect('./manage_admin_users.php');
            exit;
        }
        
        // Check if username already exists (excluding current user)
        $check_sql = "SELECT id FROM users WHERE username = '" . mysqli_real_escape_string($conn, $username) . "' AND id != " . $id;
        $check_result = mysqli_query($conn, $check_sql);
        if (mysqli_num_rows($check_result) > 0) {
            set_message('danger', 'Username "' . htmlspecialchars($username) . '" already exists. Please choose a different username.');
            redirect('./manage_admin_users.php');
            exit;
        }
        
        // Check if email already exists (excluding current user, if provided)
        if (!empty($email)) {
            $email_check_sql = "SELECT id FROM users WHERE email = '" . mysqli_real_escape_string($conn, $email) . "' AND id != " . $id;
            $email_check_result = mysqli_query($conn, $email_check_sql);
            if (mysqli_num_rows($email_check_result) > 0) {
                set_message('danger', 'Email "' . htmlspecialchars($email) . '" already exists. Please use a different email address.');
                redirect('./manage_admin_users.php');
                exit;
            }
        }
        
        // Check if mobile number already exists (excluding current user, if provided)
        if (!empty($mobile_number)) {
            $mobile_check_sql = "SELECT id FROM users WHERE mobile_number = '" . mysqli_real_escape_string($conn, $mobile_number) . "' AND id != " . $id;
            $mobile_check_result = mysqli_query($conn, $mobile_check_sql);
            if (mysqli_num_rows($mobile_check_result) > 0) {
                set_message('danger', 'Mobile number "' . htmlspecialchars($mobile_number) . '" already exists. Please use a different mobile number.');
                redirect('./manage_admin_users.php');
                exit;
            }
        }

        $sql = "UPDATE users SET
                    username = '" . mysqli_real_escape_string($conn, $username) . "',
                    full_name = '" . mysqli_real_escape_string($conn, $full_name) . "',
                    email = '" . mysqli_real_escape_string($conn, $email) . "',
                    mobile_number = '" . mysqli_real_escape_string($conn, $mobile_number) . "'
                WHERE id = " . $id . " AND role = 'admin'";
        
        try {
            mysqli_query($conn, $sql);
            set_message('success', 'Admin updated successfully');
        } catch (mysqli_sql_exception $e) {
            // Handle any database errors
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                set_message('danger', 'Duplicate entry found. Username, email or mobile number already exists.');
            } else {
                set_message('danger', 'Database error: ' . htmlspecialchars($e->getMessage()));
            }
        }
        redirect('./manage_admin_users.php');
        exit;
    }

    // DELETE ADMIN
    if (isset($_POST['delete_user'])) {
        $id = intval($_POST['delete_user_id']);
        mysqli_query($conn, "DELETE FROM users WHERE id = $id AND role = 'admin'");
        set_message('success', 'Admin deleted');
        redirect('./manage_admin_users.php');
        exit;
    }

    // TOGGLE STATUS
    if (isset($_POST['toggle_status'])) {
        $user_id = intval($_POST['user_id']);
        $is_active = intval($_POST['is_active']);
        $new_status = $is_active ? 0 : 1;
        mysqli_query($conn, "UPDATE users SET is_active = $new_status WHERE id = $user_id AND role = 'admin'");
        set_message('success', 'Admin status updated');
        redirect('./manage_admin_users.php');
        exit;
    }
}

// Load admin users only
$users = mysqli_query($conn, "SELECT * FROM users WHERE role = 'admin' ORDER BY created_at DESC");

$csrf_token = generate_csrf_token();

include '../templates/header.php';
include '../templates/sidebar_admin.php';
?>

<div class="main-content">
    <div class="top-navbar d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="fas fa-user-shield"></i> Manage Admin Users</h4>
        <div class="d-flex align-items-center gap-2">
            <input id="tableSearch" class="form-control form-control-sm" placeholder="Search admins..." style="min-width:220px; padding:13px;">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal" style="width:250px">
                <i class="fas fa-plus"></i> Add Admin
            </button>
        </div>
    </div>

    <!-- Display messages -->
    <?php if (function_exists('has_messages') && has_messages()): ?>
        <div class="mb-3">
            <?php 
            if (function_exists('display_messages')) {
                display_messages();
            }
            ?>
        </div>
    <?php endif; ?>

    <div class="content-area">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Admin List</span>
                <small class="text-muted">Showing filtered results</small>
            </div>
            <div class="card-body table-responsive">
                <table class="table table-striped table-sm" id="usersTable">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Mobile</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="userTableBody">
                        <?php while ($user = mysqli_fetch_assoc($users)): ?>
                        <tr>
                            <td><?= htmlspecialchars($user['username']); ?></td>
                            <td><?= htmlspecialchars($user['full_name']); ?></td>
                            <td><?= htmlspecialchars($user['email']); ?></td>
                            <td><?= htmlspecialchars($user['mobile_number']); ?></td>
                            <td>
                                <?php if ($user['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $user['last_login'] ? date('d/m/Y H:i', strtotime($user['last_login'])) : 'Never'; ?></td>
                            <td><?= date('d/m/Y H:i', strtotime($user['created_at'])); ?></td>
                            <td>
                                <!-- Status toggle -->
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">
                                    <input type="hidden" name="user_id" value="<?= intval($user['id']); ?>">
                                    <input type="hidden" name="is_active" value="<?= intval($user['is_active']); ?>">
                                    <button type="submit" name="toggle_status" class="btn btn-sm"
                                        title="<?= $user['is_active'] ? 'Deactivate' : 'Activate'; ?>"
                                        style="border:0; background:transparent; padding:0; margin-right:6px;">
                                        <?php if ($user['is_active']): ?>
                                            <i class="fas fa-toggle-on" style="color:green; font-size:1.2rem;"></i>
                                        <?php else: ?>
                                            <i class="fas fa-toggle-off" style="color:#dc3545; font-size:1.2rem;"></i>
                                        <?php endif; ?>
                                    </button>
                                </form>

                                <!-- Edit icon -->
                                <button class="btn btn-sm" type="button" onclick='openEditModal(<?= json_encode($user, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>)'
                                    title="Edit" style="border:0; background:transparent; padding:0; margin-right:8px;">
                                    <i class="fas fa-edit" style="color:#0d6efd;"></i>
                                </button>

                                <!-- Delete icon -->
                                <button class="btn btn-sm" type="button" onclick="openDeleteModal(<?= intval($user['id']); ?>)"
                                    title="Delete" style="border:0; background:transparent; padding:0;">
                                    <i class="fas fa-trash" style="color:#dc3545;"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>

                <div id="noResults" class="text-center text-muted" style="display:none;">No admin users found.</div>
            </div>
        </div>
    </div>
</div>

<!-- Create Admin Modal -->
<div class="modal fade" id="createUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="createForm" onsubmit="return validateCreateForm()">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Admin</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input name="username" id="create_username" class="form-control" required
                               placeholder="Enter username (e.g., johnsmith)"
                               oninput="validateUsername(this, 'create_username_error')">
                        <div class="text-danger small mt-1" id="create_username_error" style="display:none;"></div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input name="full_name" id="create_full_name" class="form-control" required
                               placeholder="Enter full name (e.g., John Smith)"
                               oninput="validateName(this, 'create_name_error')">
                        <div class="text-danger small mt-1" id="create_name_error" style="display:none;"></div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input name="email" id="create_email" type="email" class="form-control" required
                               placeholder="Enter email address (e.g., john@example.com)"
                               oninput="validateEmail(this, 'create_email_error')">
                        <div class="text-danger small mt-1" id="create_email_error" style="display:none;"></div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Mobile Number</label>
                        <input name="mobile_number" id="create_mobile_number" class="form-control"
                               placeholder="Enter mobile number (e.g., +92 300 1234567)"
                               oninput="validateMobile(this, 'create_mobile_error')">
                        <div class="text-danger small mt-1" id="create_mobile_error" style="display:none;"></div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input name="password" id="create_password" type="password" class="form-control" required
                                   placeholder="Enter password (min. 6 characters)">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('create_password', 'create_password_eye')">
                                <i id="create_password_eye" class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
                    <button class="btn btn-primary" name="create_user" type="submit">Create Admin</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Admin Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="editForm" onsubmit="return validateEditForm()">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">
                <input type="hidden" name="edit_user_id" id="edit_user_id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Admin</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input name="username" id="edit_username" class="form-control" required
                               placeholder="Enter username (e.g., johnsmith)"
                               oninput="validateUsername(this, 'edit_username_error')">
                        <div class="text-danger small mt-1" id="edit_username_error" style="display:none;"></div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input name="full_name" id="edit_full_name" class="form-control" required
                               placeholder="Enter full name (e.g., John Smith)"
                               oninput="validateName(this, 'edit_name_error')">
                        <div class="text-danger small mt-1" id="edit_name_error" style="display:none;"></div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input name="email" id="edit_email" type="email" class="form-control" required
                               placeholder="Enter email address (e.g., john@example.com)"
                               oninput="validateEmail(this, 'edit_email_error')">
                        <div class="text-danger small mt-1" id="edit_email_error" style="display:none;"></div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Mobile Number</label>
                        <input name="mobile_number" id="edit_mobile_number" class="form-control"
                               placeholder="Enter mobile number (e.g., +92 300 1234567)"
                               oninput="validateMobile(this, 'edit_mobile_error')">
                        <div class="text-danger small mt-1" id="edit_mobile_error" style="display:none;"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
                    <button class="btn btn-primary" name="edit_user" type="submit">Update Admin</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirm Modal -->
<div class="modal fade" id="deleteUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">
                <input type="hidden" name="delete_user_id" id="delete_user_id">
                <div class="modal-header">
                    <h5 class="modal-title text-danger">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">Are you sure you want to permanently delete this admin user? This cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
                    <button class="btn btn-danger" name="delete_user" type="submit">Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Toggle password visibility
function togglePassword(inputId, eyeIconId) {
    const passwordInput = document.getElementById(inputId);
    const eyeIcon = document.getElementById(eyeIconId);
    
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

// Validation functions
function validateUsername(input, errorElementId) {
    const value = input.value.trim();
    const errorElement = document.getElementById(errorElementId);
    
    if (value.length < 3) {
        errorElement.textContent = "Username must be at least 3 characters long";
        errorElement.style.display = 'block';
        input.classList.add('is-invalid');
        return false;
    }
    
    if (value.length > 30) {
        errorElement.textContent = "Username cannot exceed 30 characters";
        errorElement.style.display = 'block';
        input.classList.add('is-invalid');
        return false;
    }
    
    // Only letters and numbers allowed (no underscores, no dots)
    if (!/^[a-zA-Z0-9]+$/.test(value)) {
        errorElement.textContent = "Username can only contain letters and numbers (no spaces, underscores, dots, or special characters)";
        errorElement.style.display = 'block';
        input.classList.add('is-invalid');
        return false;
    }
    
    errorElement.style.display = 'none';
    input.classList.remove('is-invalid');
    return true;
}

function validateName(input, errorElementId) {
    const value = input.value.trim();
    const errorElement = document.getElementById(errorElementId);
    
    if (value.length < 2) {
        errorElement.textContent = "Name must be at least 2 characters long";
        errorElement.style.display = 'block';
        input.classList.add('is-invalid');
        return false;
    }
    
    if (value.length > 100) {
        errorElement.textContent = "Name cannot exceed 100 characters";
        errorElement.style.display = 'block';
        input.classList.add('is-invalid');
        return false;
    }
    
    if (!/^[a-zA-Z\s'\-\.]+$/.test(value)) {
        errorElement.textContent = "Name can only contain letters, spaces, apostrophes, hyphens, and dots";
        errorElement.style.display = 'block';
        input.classList.add('is-invalid');
        return false;
    }
    
    errorElement.style.display = 'none';
    input.classList.remove('is-invalid');
    return true;
}

function validateEmail(input, errorElementId) {
    const value = input.value.trim();
    const errorElement = document.getElementById(errorElementId);
    
    if (value === '') {
        errorElement.textContent = "Email is required";
        errorElement.style.display = 'block';
        input.classList.add('is-invalid');
        return false;
    }
    
    // Basic email validation
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(value)) {
        errorElement.textContent = "Please enter a valid email address";
        errorElement.style.display = 'block';
        input.classList.add('is-invalid');
        return false;
    }
    
    errorElement.style.display = 'none';
    input.classList.remove('is-invalid');
    return true;
}

function validateMobile(input, errorElementId) {
    const value = input.value.trim();
    const errorElement = document.getElementById(errorElementId);
    
    // Mobile is optional, so if empty, it's valid
    if (value === '') {
        errorElement.style.display = 'none';
        input.classList.remove('is-invalid');
        return true;
    }
    
    // Remove all non-digit characters for validation
    const cleanNumber = value.replace(/[^0-9]/g, '');
    
    if (cleanNumber.length < 10 || cleanNumber.length > 15) {
        errorElement.textContent = "Mobile number should be 10-15 digits long";
        errorElement.style.display = 'block';
        input.classList.add('is-invalid');
        return false;
    }
    
    errorElement.style.display = 'none';
    input.classList.remove('is-invalid');
    return true;
}

function validateCreateForm() {
    const usernameValid = validateUsername(document.getElementById('create_username'), 'create_username_error');
    const nameValid = validateName(document.getElementById('create_full_name'), 'create_name_error');
    const emailValid = validateEmail(document.getElementById('create_email'), 'create_email_error');
    const mobileValid = validateMobile(document.getElementById('create_mobile_number'), 'create_mobile_error');
    
    // Password validation
    const password = document.getElementById('create_password').value;
    if (password.length < 6) {
        alert('Password must be at least 6 characters long');
        return false;
    }
    
    return usernameValid && nameValid && emailValid && mobileValid;
}

function validateEditForm() {
    const usernameValid = validateUsername(document.getElementById('edit_username'), 'edit_username_error');
    const nameValid = validateName(document.getElementById('edit_full_name'), 'edit_name_error');
    const emailValid = validateEmail(document.getElementById('edit_email'), 'edit_email_error');
    const mobileValid = validateMobile(document.getElementById('edit_mobile_number'), 'edit_mobile_error');
    
    return usernameValid && nameValid && emailValid && mobileValid;
}

document.addEventListener('DOMContentLoaded', function () {
    const tableSearch = document.getElementById('tableSearch');
    const rows = Array.from(document.querySelectorAll('#userTableBody tr'));
    const noResults = document.getElementById('noResults');

    function applySearch() {
        const q = tableSearch.value.trim().toLowerCase();
        let visibleCount = 0;

        rows.forEach(row => {
            const tds = Array.from(row.querySelectorAll('td')).map(td => td.textContent.toLowerCase());
            const visible = tds.some(txt => txt.includes(q));
            row.style.display = visible ? '' : 'none';
            if (visible) visibleCount++;
        });

        noResults.style.display = visibleCount === 0 ? 'block' : 'none';
    }

    tableSearch.addEventListener('input', applySearch);
});

// Edit modal populate & show
function openEditModal(user) {
    const modal = new bootstrap.Modal(document.getElementById('editUserModal'));
    document.getElementById('edit_user_id').value = user.id || '';
    document.getElementById('edit_username').value = user.username || '';
    document.getElementById('edit_full_name').value = user.full_name || '';
    document.getElementById('edit_email').value = user.email || '';
    document.getElementById('edit_mobile_number').value = user.mobile_number || '';
    
    // Clear validation errors
    document.getElementById('edit_username_error').style.display = 'none';
    document.getElementById('edit_name_error').style.display = 'none';
    document.getElementById('edit_email_error').style.display = 'none';
    document.getElementById('edit_mobile_error').style.display = 'none';
    
    modal.show();
}

// Delete modal show
function openDeleteModal(id) {
    document.getElementById('delete_user_id').value = id;
    const modal = new bootstrap.Modal(document.getElementById('deleteUserModal'));
    modal.show();
}
</script>

<?php include '../templates/footer.php'; ?>
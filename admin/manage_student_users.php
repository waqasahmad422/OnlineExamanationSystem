<?php
require_once '../app/config.php';
require_once '../app/helpers.php';
require_once '../app/auth.php';
require_once '../app/admin_handlers.php';

require_role(['admin']);
check_session_validity();

$page_title = 'Manage Student Users';

// Validation function for username, name and roll number
function validate_input($input, $type)
{
    // Remove extra whitespace
    $input = trim($input);

    if ($type === 'username') {
        // Username: only alphanumeric characters (no underscores, no dots, no hyphens)
        if (!preg_match('/^[a-zA-Z0-9]+$/', $input)) {
            return "Username can only contain letters and numbers (no spaces, underscores, dots, hyphens, or special characters)";
        }
        if (strlen($input) < 3) {
            return "Username must be at least 3 characters long";
        }
        if (strlen($input) > 30) {
            return "Username cannot exceed 30 characters";
        }
    }

    if ($type === 'name') {
        // Name: only letters and spaces allowed (no special characters, dots, hyphens, underscores)
        if (!preg_match('/^[a-zA-Z\s]+$/', $input)) {
            return "Name can only contain letters and spaces (no numbers, special characters, dots, hyphens, or underscores)";
        }
        if (strlen($input) < 2) {
            return "Name must be at least 2 characters long";
        }
        if (strlen($input) > 100) {
            return "Name cannot exceed 100 characters";
        }
    }

    if ($type === 'rollno') {
        // Roll number: alphanumeric with optional hyphens (no underscores, no dots)
        if (!empty($input) && !preg_match('/^[a-zA-Z0-9\-]+$/', $input)) {
            return "Roll number can only contain letters, numbers, and hyphens (no underscores or dots)";
        }
        if (!empty($input) && strlen($input) > 20) {
            return "Roll number cannot exceed 20 characters";
        }
    }

    if ($type === 'email') {
        // Email: basic email validation
        if (!empty($input) && !filter_var($input, FILTER_VALIDATE_EMAIL)) {
            return "Please enter a valid email address";
        }
    }

    return true;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_message('danger', 'Invalid security token');
        redirect('./manage_student_users.php');
        exit;
    }

    // CREATE STUDENT
    if (isset($_POST['create_user'])) {
        // Validate inputs
        $username = sanitize_input($_POST['username'] ?? '');
        $full_name = sanitize_input($_POST['full_name'] ?? '');
        $email = sanitize_input($_POST['email'] ?? '');
        $roll_number = sanitize_input($_POST['roll_number'] ?? '') ?: null;

        $username_validation = validate_input($username, 'username');
        $name_validation = validate_input($full_name, 'name');
        $email_validation = validate_input($email, 'email');
        $rollno_validation = validate_input($roll_number ?? '', 'rollno');

        if ($username_validation !== true) {
            set_message('danger', $username_validation);
            redirect('./manage_student_users.php');
            exit;
        }

        if ($name_validation !== true) {
            set_message('danger', $name_validation);
            redirect('./manage_student_users.php');
            exit;
        }

        if ($email_validation !== true) {
            set_message('danger', $email_validation);
            redirect('./manage_student_users.php');
            exit;
        }

        if ($rollno_validation !== true) {
            set_message('danger', $rollno_validation);
            redirect('./manage_student_users.php');
            exit;
        }

        // Check if username already exists
        $check_sql = "SELECT id FROM users WHERE username = '" . mysqli_real_escape_string($conn, $username) . "'";
        $check_result = mysqli_query($conn, $check_sql);
        if (mysqli_num_rows($check_result) > 0) {
            set_message('danger', 'Username "' . htmlspecialchars($username) . '" already exists. Please choose a different username.');
            redirect('./manage_student_users.php');
            exit;
        }

        // Check if email already exists (if provided)
        if (!empty($email)) {
            $email_check_sql = "SELECT id FROM users WHERE email = '" . mysqli_real_escape_string($conn, $email) . "'";
            $email_check_result = mysqli_query($conn, $email_check_sql);
            if (mysqli_num_rows($email_check_result) > 0) {
                set_message('danger', 'Email "' . htmlspecialchars($email) . '" already exists. Please use a different email address.');
                redirect('./manage_student_users.php');
                exit;
            }
        }

        // If roll number is provided, check for duplicate in same department and batch
        if ($roll_number) {
            $department_id = $_POST['department_id'] ? intval($_POST['department_id']) : null;
            $batch_id = $_POST['batch_id'] ? intval($_POST['batch_id']) : null;

            $roll_check_sql = "SELECT id FROM users WHERE roll_number = '" . mysqli_real_escape_string($conn, $roll_number) . "'";
            if ($department_id) {
                $roll_check_sql .= " AND department_id = " . $department_id;
            }
            if ($batch_id) {
                $roll_check_sql .= " AND batch_id = " . $batch_id;
            }
            $roll_check_result = mysqli_query($conn, $roll_check_sql);
            if (mysqli_num_rows($roll_check_result) > 0) {
                set_message('danger', 'Roll number "' . htmlspecialchars($roll_number) . '" already exists in this department/batch combination.');
                redirect('./manage_student_users.php');
                exit;
            }
        }

        $result = create_user(
            $username,
            $_POST['password'] ?? '',
            $full_name,
            $email,
            null,
            'student',
            $_POST['department_id'] ?: null,
            $_POST['batch_id'] ?: null,
            $_POST['semester'] ?: null,
            $roll_number
        );

        if ($result['success']) {
            // FIXED: Use custom success message
            set_message('success', 'Student user "' . htmlspecialchars($username) . '" created successfully!');
            redirect('./manage_student_users.php');
            exit;
        } else {
            set_message('danger', $result['message']);
            redirect('./manage_student_users.php');
            exit;
        }
    }

    // EDIT STUDENT
    if (isset($_POST['edit_user'])) {
        $id = intval($_POST['edit_user_id']);
        $username = sanitize_input($_POST['username'] ?? '');
        $full_name = sanitize_input($_POST['full_name'] ?? '');
        $email = sanitize_input($_POST['email'] ?? '');
        $roll_number = sanitize_input($_POST['roll_number'] ?? '') ?: null;

        // Validate inputs
        $username_validation = validate_input($username, 'username');
        $name_validation = validate_input($full_name, 'name');
        $email_validation = validate_input($email, 'email');
        $rollno_validation = validate_input($roll_number ?? '', 'rollno');

        if ($username_validation !== true) {
            set_message('danger', $username_validation);
            redirect('./manage_student_users.php');
            exit;
        }

        if ($name_validation !== true) {
            set_message('danger', $name_validation);
            redirect('./manage_student_users.php');
            exit;
        }

        if ($email_validation !== true) {
            set_message('danger', $email_validation);
            redirect('./manage_student_users.php');
            exit;
        }

        if ($rollno_validation !== true) {
            set_message('danger', $rollno_validation);
            redirect('./manage_student_users.php');
            exit;
        }

        // Check if username already exists (excluding current user)
        $check_sql = "SELECT id FROM users WHERE username = '" . mysqli_real_escape_string($conn, $username) . "' AND id != " . $id;
        $check_result = mysqli_query($conn, $check_sql);
        if (mysqli_num_rows($check_result) > 0) {
            set_message('danger', 'Username "' . htmlspecialchars($username) . '" already exists. Please choose a different username.');
            redirect('./manage_student_users.php');
            exit;
        }

        // Check if email already exists (excluding current user, if provided)
        if (!empty($email)) {
            $email_check_sql = "SELECT id FROM users WHERE email = '" . mysqli_real_escape_string($conn, $email) . "' AND id != " . $id;
            $email_check_result = mysqli_query($conn, $email_check_sql);
            if (mysqli_num_rows($email_check_result) > 0) {
                set_message('danger', 'Email "' . htmlspecialchars($email) . '" already exists. Please use a different email address.');
                redirect('./manage_student_users.php');
                exit;
            }
        }

        // If roll number is provided, check for duplicate in same department and batch (excluding current user)
        if ($roll_number) {
            $department_id = $_POST['department_id'] ? intval($_POST['department_id']) : null;
            $batch_id = $_POST['batch_id'] ? intval($_POST['batch_id']) : null;

            $roll_check_sql = "SELECT id FROM users WHERE roll_number = '" . mysqli_real_escape_string($conn, $roll_number) . "' AND id != " . $id;
            if ($department_id) {
                $roll_check_sql .= " AND department_id = " . $department_id;
            }
            if ($batch_id) {
                $roll_check_sql .= " AND batch_id = " . $batch_id;
            }
            $roll_check_result = mysqli_query($conn, $roll_check_sql);
            if (mysqli_num_rows($roll_check_result) > 0) {
                set_message('danger', 'Roll number "' . htmlspecialchars($roll_number) . '" already exists in this department/batch combination.');
                redirect('./manage_student_users.php');
                exit;
            }
        }

        $department_id = $_POST['department_id'] ?: null;
        $batch_id = $_POST['batch_id'] ?: null;
        $semester = $_POST['semester'] ?: null;

        $sql = "UPDATE users SET
                    username = '" . mysqli_real_escape_string($conn, $username) . "',
                    full_name = '" . mysqli_real_escape_string($conn, $full_name) . "',
                    email = '" . mysqli_real_escape_string($conn, $email) . "',
                    department_id = " . ($department_id ? intval($department_id) : "NULL") . ",
                    batch_id = " . ($batch_id ? intval($batch_id) : "NULL") . ",
                    semester = " . ($semester !== '' && $semester !== null ? intval($semester) : "NULL") . ",
                    roll_number = " . ($roll_number !== null ? ("'" . mysqli_real_escape_string($conn, $roll_number) . "'") : "NULL") . "
                WHERE id = " . $id . " AND role = 'student'";

        try {
            mysqli_query($conn, $sql);
            set_message('success', 'Student updated successfully');
        } catch (mysqli_sql_exception $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                set_message('danger', 'Duplicate entry found. Username, email, or roll number already exists.');
            } else {
                set_message('danger', 'Database error: ' . htmlspecialchars($e->getMessage()));
            }
        }
        redirect('./manage_student_users.php');
        exit;
    }

    // DELETE STUDENT
    if (isset($_POST['delete_user'])) {
        $id = intval($_POST['delete_user_id']);
        mysqli_query($conn, "DELETE FROM users WHERE id = $id AND role = 'student'");
        set_message('success', 'Student deleted');
        redirect('./manage_student_users.php');
        exit;
    }

    // TOGGLE STATUS
    if (isset($_POST['toggle_status'])) {
        $user_id = intval($_POST['user_id']);
        $is_active = intval($_POST['is_active']);
        $new_status = $is_active ? 0 : 1;
        mysqli_query($conn, "UPDATE users SET is_active = $new_status WHERE id = $user_id AND role = 'student'");
        set_message('success', 'Student status updated');
        redirect('./manage_student_users.php');
        exit;
    }
}

// Load departments and batches for dropdowns
$departments = mysqli_query($conn, "SELECT * FROM departments WHERE is_active = 1 ORDER BY name");
$batches = mysqli_query($conn, "SELECT * FROM batches WHERE is_active = 1 ORDER BY name");

// Load student users with department and batch info
$users = mysqli_query($conn, "SELECT u.*, d.name as dept_name, b.name as batch_name
    FROM users u
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN batches b ON u.batch_id = b.id
    WHERE u.role = 'student'
    ORDER BY u.created_at DESC");

$csrf_token = generate_csrf_token();

// Prepare arrays for client-side filtering
mysqli_data_seek($departments, 0);
$departments_arr = [];
while ($d = mysqli_fetch_assoc($departments)) {
    $departments_arr[] = $d;
}
mysqli_data_seek($batches, 0);
$batches_arr = [];
while ($b = mysqli_fetch_assoc($batches)) {
    $batches_arr[] = $b;
}
// reset pointers for selects
mysqli_data_seek($departments, 0);
mysqli_data_seek($batches, 0);

include '../templates/header.php';
include '../templates/sidebar_admin.php';
?>

<!-- HTML and JavaScript remain the same, only PHP fixes were needed -->

<div class="main-content">
    <div class="top-navbar d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="fas fa-user-graduate"></i> Manage Student Users</h4>
        <div class="d-flex align-items-center gap-2">
            <input id="tableSearch" class="form-control form-control-sm" placeholder="Search students..."
                style="min-width:220px; padding:13px;">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal"
                style="width:250px">
                <i class="fas fa-plus"></i> Add Student
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

    <!-- Department and Batch Filters at the Top -->
    <div class=" gx-3 m-4">
        <div class="col-md-5">
            <div class="card">
                <div class="card-header">Department Filter</div>
                <div class="card-body">
                    <div id="deptFilter" class="btn-group flex-wrap" role="tablist">
                        <button class="btn btn-outline-primary btn-sm me-1 mb-1 active" data-dept="">All
                            Departments</button>
                        <?php foreach ($departments_arr as $d): ?>
                            <button class="btn btn-outline-primary btn-sm me-1 mb-1" data-dept="<?= intval($d['id']); ?>">
                                <?= htmlspecialchars($d['name']); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card" id="batchCard" style="display:none;">
                <div class="card-header">Batch Filter</div>
                <div class="card-body">
                    <div id="batchFilter" class="btn-group flex-wrap" role="tablist">
                        <!-- Dynamically populated -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="content-area">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Student List</span>
                <small class="text-muted">Showing filtered results</small>
            </div>
            <div class="card-body">
                <div class="table-responsive" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                    <table class="table table-striped table-sm" id="usersTable">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Department</th>
                                <th>Batch</th>
                                <th>Semester</th>
                                <th>Roll No</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="userTableBody">
                            <?php 
                            // Reset users pointer
                            mysqli_data_seek($users, 0);
                            $hasStudents = false;
                            while ($user = mysqli_fetch_assoc($users)): 
                                $hasStudents = true;
                            ?>
                                <tr data-department="<?= $user['department_id'] ? intval($user['department_id']) : ''; ?>"
                                    data-batch="<?= $user['batch_id'] ? intval($user['batch_id']) : ''; ?>">
                                    <td><?= htmlspecialchars($user['username']); ?></td>
                                    <td><?= htmlspecialchars($user['full_name']); ?></td>
                                    <td><?= htmlspecialchars($user['dept_name'] ?? 'N/A'); ?></td>
                                    <td><?= htmlspecialchars($user['batch_name'] ?? 'N/A'); ?></td>
                                    <td><?= htmlspecialchars($user['semester'] ?? 'N/A'); ?></td>
                                    <td><?= htmlspecialchars($user['roll_number'] ?? 'N/A'); ?></td>
                                    <td><?= htmlspecialchars($user['email'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php if ($user['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <!-- Status toggle -->
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">
                                            <input type="hidden" name="user_id" value="<?= intval($user['id']); ?>">
                                            <input type="hidden" name="is_active"
                                                value="<?= intval($user['is_active']); ?>">
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
                                        <button class="btn btn-sm" type="button"
                                            onclick='openEditModal(<?= json_encode($user, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                            title="Edit"
                                            style="border:0; background:transparent; padding:0; margin-right:8px;">
                                            <i class="fas fa-edit" style="color:#0d6efd;"></i>
                                        </button>

                                        <!-- Delete icon -->
                                        <button class="btn btn-sm" type="button"
                                            onclick="openDeleteModal(<?= intval($user['id']); ?>)" title="Delete"
                                            style="border:0; background:transparent; padding:0;">
                                            <i class="fas fa-trash" style="color:#dc3545;"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                            <?php if (!$hasStudents): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted">No student users found. Click "Add Student" to create one.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div id="noResults" class="text-center text-muted" style="display:none;">No student users found.</div>
            </div>
        </div>
    </div>
</div>

<!-- Create Student Modal -->
<div class="modal fade" id="createUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="createForm" onsubmit="return validateCreateForm()">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input name="username" id="create_username" class="form-control" required
                            placeholder="Enter username (e.g., johnsmith)"
                            oninput="validateUsername(this, 'create_username_error')"
                            onkeypress="return restrictUsernameInput(event)">
                        <div class="text-danger small mt-1" id="create_username_error" style="display:none;"></div>
                        
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input name="full_name" id="create_full_name" class="form-control" required
                            placeholder="Enter full name (e.g., John Smith)"
                            oninput="validateName(this, 'create_name_error')"
                            onkeypress="return restrictNameInput(event)">
                        <div class="text-danger small mt-1" id="create_name_error" style="display:none;"></div>
                        
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Email</label>
                        <input name="email" id="create_email" type="email" class="form-control"
                            placeholder="Enter email address (e.g., john@example.com)"
                            oninput="validateEmail(this, 'create_email_error')">
                        <div class="text-danger small mt-1" id="create_email_error" style="display:none;"></div>
                        
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input name="password" id="create_password" type="password" class="form-control" required
                                placeholder="Enter password (minimum 6 characters)">
                            <button class="btn btn-outline-secondary" type="button"
                                onclick="togglePassword('create_password', 'create_password_eye')">
                                <i id="create_password_eye" class="fas fa-eye"></i>
                            </button>
                        </div>
                       
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Department</label>
                        <select name="department_id" id="create_department" class="form-select"
                            onchange="filterBatches(this.value, 'create')">
                            <option value="">Select Department</option>
                            <?php mysqli_data_seek($departments, 0);
                            while ($d = mysqli_fetch_assoc($departments)): ?>
                                <option value="<?= intval($d['id']); ?>"><?= htmlspecialchars($d['name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Batch</label>
                        <select name="batch_id" id="create_batch" class="form-select">
                            <option value="">Select Batch</option>
                            <?php mysqli_data_seek($batches, 0);
                            while ($b = mysqli_fetch_assoc($batches)): ?>
                                <option value="<?= intval($b['id']); ?>" 
                                        data-department="<?= intval($b['department_id']); ?>">
                                    <?= htmlspecialchars($b['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Semester</label>
                        <select name="semester" id="create_semester" class="form-select">
                            <option value="">Select Semester</option>
                            <?php for ($i = 1; $i <= 8; $i++): ?>
                                <option value="<?= $i; ?>">Semester <?= $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Roll Number</label>
                        <input name="roll_number" id="create_roll_number" class="form-control"
                            placeholder="Enter roll number (e.g., 21-CS-001)"
                            oninput="validateRollNumber(this, 'create_roll_error')">
                        <div class="text-danger small mt-1" id="create_roll_error" style="display:none;"></div>
                        
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
                    <button class="btn btn-primary" name="create_user" type="submit">Create Student</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Student Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="editForm" onsubmit="return validateEditForm()">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">
                <input type="hidden" name="edit_user_id" id="edit_user_id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input name="username" id="edit_username" class="form-control" required
                            placeholder="Enter username (e.g., johnsmith)"
                            oninput="validateUsername(this, 'edit_username_error')"
                            onkeypress="return restrictUsernameInput(event)">
                        <div class="text-danger small mt-1" id="edit_username_error" style="display:none;"></div>
                        
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input name="full_name" id="edit_full_name" class="form-control" required
                            placeholder="Enter full name (e.g., John Smith)"
                            oninput="validateName(this, 'edit_name_error')"
                            onkeypress="return restrictNameInput(event)">
                        <div class="text-danger small mt-1" id="edit_name_error" style="display:none;"></div>
                        
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Email</label>
                        <input name="email" id="edit_email" type="email" class="form-control"
                            placeholder="Enter email address (e.g., john@example.com)"
                            oninput="validateEmail(this, 'edit_email_error')">
                        <div class="text-danger small mt-1" id="edit_email_error" style="display:none;"></div>
                       
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Department</label>
                        <select name="department_id" id="edit_department" class="form-select"
                            onchange="filterBatches(this.value, 'edit')">
                            <option value="">Select Department</option>
                            <?php mysqli_data_seek($departments, 0);
                            while ($d = mysqli_fetch_assoc($departments)): ?>
                                <option value="<?= intval($d['id']); ?>"><?= htmlspecialchars($d['name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Batch</label>
                        <select name="batch_id" id="edit_batch" class="form-select">
                            <option value="">Select Batch</option>
                            <?php mysqli_data_seek($batches, 0);
                            while ($b = mysqli_fetch_assoc($batches)): ?>
                                <option value="<?= intval($b['id']); ?>" 
                                        data-department="<?= intval($b['department_id']); ?>">
                                    <?= htmlspecialchars($b['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Semester</label>
                        <select name="semester" id="edit_semester" class="form-select">
                            <option value="">Select Semester</option>
                            <?php for ($i = 1; $i <= 8; $i++): ?>
                                <option value="<?= $i; ?>">Semester <?= $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Roll Number</label>
                        <input name="roll_number" id="edit_roll" class="form-control"
                            placeholder="Enter roll number (e.g., 21-CS-001)"
                            oninput="validateRollNumber(this, 'edit_roll_error')">
                        <div class="text-danger small mt-1" id="edit_roll_error" style="display:none;"></div>
                        
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
                    <button class="btn btn-primary" name="edit_user" type="submit">Update Student</button>
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
                    <p class="mb-0">Are you sure you want to permanently delete this student? This cannot be undone.</p>
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
    // Data from PHP
    const DEPARTMENTS = <?= json_encode($departments_arr); ?>;
    const BATCHES = <?= json_encode($batches_arr); ?>;

    // Input restriction functions
    function restrictUsernameInput(event) {
        const char = String.fromCharCode(event.keyCode || event.which);
        // Only allow letters (A-Z, a-z) and numbers (0-9)
        if (!/^[a-zA-Z0-9]$/.test(char)) {
            event.preventDefault();
            return false;
        }
        return true;
    }

    function restrictNameInput(event) {
        const char = String.fromCharCode(event.keyCode || event.which);
        // Only allow letters (A-Z, a-z) and space
        if (!/^[a-zA-Z\s]$/.test(char)) {
            event.preventDefault();
            return false;
        }
        return true;
    }

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

    // Filter batches based on selected department
    function filterBatches(deptId, formType) {
        const selectId = formType === 'edit' ? 'edit_batch' : 'create_batch';
        const batchSelect = document.getElementById(selectId);
        
        // Get current selected value
        const currentSelected = batchSelect.value;
        
        // Show/hide options based on department
        Array.from(batchSelect.options).forEach(option => {
            if (option.value === '') return; // Skip "Select Batch" option
            
            const optionDept = option.getAttribute('data-department') || '';
            
            if (deptId && optionDept !== deptId) {
                option.style.display = 'none';
                option.disabled = true;
            } else {
                option.style.display = 'block';
                option.disabled = false;
            }
        });
        
        // If current selection is invalid, reset it
        const selectedOption = batchSelect.options[batchSelect.selectedIndex];
        if (selectedOption && selectedOption.disabled) {
            batchSelect.value = '';
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

        // Only letters and numbers allowed (no underscores, no dots, no hyphens)
        if (!/^[a-zA-Z0-9]+$/.test(value)) {
            errorElement.textContent = "Username can only contain letters and numbers (no spaces, underscores, dots, hyphens, or special characters)";
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

        // Only letters and spaces allowed (no special characters, dots, hyphens, underscores)
        if (!/^[a-zA-Z\s]+$/.test(value)) {
            errorElement.textContent = "Name can only contain letters and spaces (no numbers, special characters, dots, hyphens, or underscores)";
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

        // Email is optional, so if empty, it's valid
        if (value === '') {
            errorElement.style.display = 'none';
            input.classList.remove('is-invalid');
            return true;
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

    function validateRollNumber(input, errorElementId) {
        const value = input.value.trim();
        const errorElement = document.getElementById(errorElementId);

        if (value === '') {
            errorElement.style.display = 'none';
            input.classList.remove('is-invalid');
            return true;
        }

        if (value.length > 20) {
            errorElement.textContent = "Roll number cannot exceed 20 characters";
            errorElement.style.display = 'block';
            input.classList.add('is-invalid');
            return false;
        }

        // Only letters, numbers, and hyphens allowed (no underscores, no dots)
        if (!/^[a-zA-Z0-9\-]+$/.test(value)) {
            errorElement.textContent = "Roll number can only contain letters, numbers, and hyphens (no underscores or dots)";
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
        const rollValid = validateRollNumber(document.getElementById('create_roll_number'), 'create_roll_error');

        // Password validation
        const password = document.getElementById('create_password').value;
        if (password.length < 6) {
            alert('Password must be at least 6 characters long');
            return false;
        }

        return usernameValid && nameValid && emailValid && rollValid;
    }

    function validateEditForm() {
        const usernameValid = validateUsername(document.getElementById('edit_username'), 'edit_username_error');
        const nameValid = validateName(document.getElementById('edit_full_name'), 'edit_name_error');
        const emailValid = validateEmail(document.getElementById('edit_email'), 'edit_email_error');
        const rollValid = validateRollNumber(document.getElementById('edit_roll'), 'edit_roll_error');

        return usernameValid && nameValid && emailValid && rollValid;
    }

    // Edit modal populate
    function openEditModal(user) {
        const modal = new bootstrap.Modal(document.getElementById('editUserModal'));
        
        // Set all form values
        document.getElementById('edit_user_id').value = user.id || '';
        document.getElementById('edit_username').value = user.username || '';
        document.getElementById('edit_full_name').value = user.full_name || '';
        document.getElementById('edit_email').value = user.email || '';
        document.getElementById('edit_department').value = user.department_id || '';
        document.getElementById('edit_semester').value = user.semester || '';
        document.getElementById('edit_roll').value = user.roll_number || '';
        
        // First filter batches based on department
        filterBatches(user.department_id, 'edit');
        
        // Then set the batch value
        document.getElementById('edit_batch').value = user.batch_id || '';
        
        // Clear validation errors
        document.getElementById('edit_username_error').style.display = 'none';
        document.getElementById('edit_name_error').style.display = 'none';
        document.getElementById('edit_email_error').style.display = 'none';
        document.getElementById('edit_roll_error').style.display = 'none';

        modal.show();
    }

    // Delete modal show
    function openDeleteModal(id) {
        document.getElementById('delete_user_id').value = id;
        const modal = new bootstrap.Modal(document.getElementById('deleteUserModal'));
        modal.show();
    }

    document.addEventListener('DOMContentLoaded', function () {
        const tableSearch = document.getElementById('tableSearch');
        const rows = Array.from(document.querySelectorAll('#userTableBody tr'));
        const noResults = document.getElementById('noResults');
        const deptFilterButtons = Array.from(document.querySelectorAll('#deptFilter button'));
        const batchCard = document.getElementById('batchCard');
        const batchFilter = document.getElementById('batchFilter');

        let activeDept = '';
        let activeBatch = '';

        // Populate batch filter when department is selected
        function populateBatchFilter(deptId) {
            batchFilter.innerHTML = '';
            // "All" button for batches
            const allBtn = document.createElement('button');
            allBtn.className = 'btn btn-outline-primary btn-sm me-1 mb-1 active';
            allBtn.dataset.batch = '';
            allBtn.innerText = 'All Batches';
            allBtn.onclick = function () {
                Array.from(batchFilter.querySelectorAll('button')).forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                activeBatch = '';
                applyFilters();
            };
            batchFilter.appendChild(allBtn);

            if (deptId) {
                const filtered = BATCHES.filter(b => String(b.department_id) === String(deptId));
                if (filtered.length === 0) {
                    const no = document.createElement('div');
                    no.className = 'text-muted small';
                    no.innerText = 'No batches';
                    batchFilter.appendChild(no);
                } else {
                    filtered.forEach(b => {
                        const btn = document.createElement('button');
                        btn.className = 'btn btn-outline-primary btn-sm me-1 mb-1';
                        btn.dataset.batch = b.id;
                        btn.innerText = b.name;
                        btn.onclick = function () {
                            Array.from(batchFilter.querySelectorAll('button')).forEach(bb => bb.classList.remove('active'));
                            this.classList.add('active');
                            activeBatch = this.dataset.batch || '';
                            applyFilters();
                        };
                        batchFilter.appendChild(btn);
                    });
                }
                batchCard.style.display = 'block';
            } else {
                batchCard.style.display = 'none';
            }
        }

        function applyFilters() {
            const q = tableSearch.value.trim().toLowerCase();
            let visibleCount = 0;

            rows.forEach(row => {
                const rowDept = row.getAttribute('data-department') || '';
                const rowBatch = row.getAttribute('data-batch') || '';
                const tds = Array.from(row.querySelectorAll('td')).map(td => td.textContent.toLowerCase());

                let visible = true;

                // Apply department filter
                if (activeDept !== '' && rowDept !== activeDept) {
                    visible = false;
                }

                // Apply batch filter
                if (visible && activeBatch !== '' && rowBatch !== activeBatch) {
                    visible = false;
                }

                // Apply search filter
                if (visible && q) {
                    visible = tds.some(txt => txt.includes(q));
                }

                row.style.display = visible ? '' : 'none';
                if (visible) visibleCount++;
            });

            noResults.style.display = visibleCount === 0 ? 'block' : 'none';
        }

        // Department filter
        deptFilterButtons.forEach(btn => {
            btn.addEventListener('click', function () {
                deptFilterButtons.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                activeDept = this.dataset.dept || '';
                activeBatch = '';
                populateBatchFilter(activeDept);
                applyFilters();
            });
        });

        // Search filter
        tableSearch.addEventListener('input', applyFilters);

        // Initial filter
        applyFilters();
        
        // Reset create modal when closed
        document.getElementById('createUserModal').addEventListener('hidden.bs.modal', function () {
            document.getElementById('createForm').reset();
            // Reset batch filter
            const createBatchSelect = document.getElementById('create_batch');
            Array.from(createBatchSelect.options).forEach(option => {
                if (option.value !== '') {
                    option.style.display = 'block';
                    option.disabled = false;
                }
            });
            // Clear validation errors
            document.getElementById('create_username_error').style.display = 'none';
            document.getElementById('create_name_error').style.display = 'none';
            document.getElementById('create_email_error').style.display = 'none';
            document.getElementById('create_roll_error').style.display = 'none';
        });
        
        // Reset edit modal when closed
        document.getElementById('editUserModal').addEventListener('hidden.bs.modal', function () {
            // Reset batch filter
            const editBatchSelect = document.getElementById('edit_batch');
            Array.from(editBatchSelect.options).forEach(option => {
                if (option.value !== '') {
                    option.style.display = 'block';
                    option.disabled = false;
                }
            });
        });
    });
</script>

<?php include '../templates/footer.php'; ?>
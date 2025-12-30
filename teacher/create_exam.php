<?php
// teacher/create_exam.php
require_once '../app/config.php';
require_once '../app/helpers.php';
require_once '../app/auth.php';
require_once '../app/teacher_handlers.php';

require_role(['teacher']);

// Set Karachi timezone for entire application
date_default_timezone_set('Asia/Karachi');

$teacher_id = intval($_SESSION['user_id']);
$csrf_token = generate_csrf_token();

// Helper: safe display messages
function render_messages_fallback() {
    if (function_exists('display_messages')) {
        display_messages();
        return;
    }
    if (!empty($_SESSION['flash_messages']) && is_array($_SESSION['flash_messages'])) {
        foreach ($_SESSION['flash_messages'] as $type => $msg) {
            echo '<div class="alert alert-' . htmlspecialchars($type) . ' alert-dismissible">' . htmlspecialchars($msg) . '</div>';
        }
        unset($_SESSION['flash_messages']);
    }
}

// Function to check if datetime is in the past
function is_datetime_in_past($datetime_str) {
    if (empty($datetime_str)) {
        return false;
    }
    
    try {
        $input_datetime = new DateTime($datetime_str, new DateTimeZone('Asia/Karachi'));
        $current_datetime = new DateTime('now', new DateTimeZone('Asia/Karachi'));
        
        return $input_datetime < $current_datetime;
    } catch (Exception $e) {
        error_log("Error checking datetime: " . $e->getMessage());
        return false;
    }
}

// ===== POST Actions =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {

    // SIMPLE datetime conversion function
    function to_mysql_dt($input) {
        if (empty($input)) {
            return null;
        }
        
        // Simple conversion: "YYYY-MM-DDTHH:MM" to "YYYY-MM-DD HH:MM:00"
        $mysql_datetime = str_replace('T', ' ', $input) . ':00';
        
        return $mysql_datetime;
    }

    // ---------- CREATE EXAM ----------
    if (isset($_POST['create_exam'])) {
        // Get and sanitize inputs
        $title = sanitize_input($_POST['title'] ?? '');
        $paper_code = sanitize_input($_POST['paper_code'] ?? '');
        $paper_password_raw = $_POST['paper_password'] ?? '';
        $description = sanitize_input($_POST['description'] ?? '');
        $department_id = intval($_POST['department_id'] ?? 0);
        $batch_id = intval($_POST['batch_id'] ?? 0);
        $semester = intval($_POST['semester'] ?? 0);
        $mega_exam_id = !empty($_POST['mega_exam_id']) ? intval($_POST['mega_exam_id']) : null;
        $start_datetime_input = $_POST['start_datetime'] ?? '';
        $end_datetime_input = $_POST['end_datetime'] ?? '';
        $duration_minutes = intval($_POST['duration_minutes'] ?? 0);
        $total_marks = intval($_POST['total_marks'] ?? 0);
        $enable_time_window = isset($_POST['enable_time_window']) ? 1 : 0;

        // Convert datetime inputs only if time window is enabled
        if ($enable_time_window) {
            $start_datetime = to_mysql_dt($start_datetime_input);
            $end_datetime = to_mysql_dt($end_datetime_input);
        } else {
            $start_datetime = null;
            $end_datetime = null;
        }

        // Validation
        $errors = [];
        
        if (empty($title)) $errors[] = 'Paper title is required.';
        if (empty($paper_code)) $errors[] = 'Paper code is required.';
        if ($department_id <= 0) $errors[] = 'Department is required.';
        if ($batch_id <= 0) $errors[] = 'Batch is required.';
        if ($semester <= 0) $errors[] = 'Semester is required.';
        if ($enable_time_window) {
            if (empty($start_datetime_input)) $errors[] = 'Start datetime is required when time window is enabled.';
            if (empty($end_datetime_input)) $errors[] = 'End datetime is required when time window is enabled.';
            
            // Check if datetime is in the past ONLY when time window is enabled
            if (!empty($start_datetime_input) && is_datetime_in_past($start_datetime_input)) {
                $errors[] = 'Start datetime cannot be in the past.';
            }
            
            // Check if end time is after start time
            if (!empty($start_datetime_input) && !empty($end_datetime_input)) {
                $start_time = strtotime($start_datetime_input);
                $end_time = strtotime($end_datetime_input);
                if ($end_time <= $start_time) {
                    $errors[] = 'End time must be after start time.';
                }
            }
        }
        if ($duration_minutes <= 0) $errors[] = 'Duration is required.';
        if ($total_marks <= 0) $errors[] = 'Total marks is required.';

        if (!empty($errors)) {
            set_message('danger', implode(' ', $errors));
            redirect('./create_exam.php');
        }

        // Insert paper using handler function
        $result = create_exam(
            $title,
            $paper_code,
            $paper_password_raw,
            $description,
            $department_id,
            $batch_id,
            $semester,
            $teacher_id,
            $mega_exam_id,
            $start_datetime,
            $end_datetime,
            $duration_minutes,
            $total_marks,
            0  // passing_marks
        );

        if ($result['success']) {
            $new_id = $result['exam_id'];
            log_audit($teacher_id, 'create_exam', 'exams', $new_id, null, "Created paper");
            set_message('success', 'Paper created successfully.');
        } else {
            set_message('danger', 'Failed to create paper: ' . $result['message']);
        }
        redirect('./create_exam.php');
    }

    // ---------- UPDATE EXAM ----------
    if (isset($_POST['update_exam'])) {
        $exam_id = intval($_POST['exam_id'] ?? 0);
        if ($exam_id <= 0) {
            set_message('danger', 'Invalid paper ID.');
            redirect('./create_exam.php');
        }

        // Verify ownership
        $chk = mysqli_prepare($conn, "SELECT id FROM exams WHERE id = ? AND teacher_id = ? LIMIT 1");
        mysqli_stmt_bind_param($chk, "ii", $exam_id, $teacher_id);
        mysqli_stmt_execute($chk);
        mysqli_stmt_store_result($chk);
        if (mysqli_stmt_num_rows($chk) == 0) {
            mysqli_stmt_close($chk);
            set_message('danger', 'Permission denied or paper not found.');
            redirect('./create_exam.php');
        }
        mysqli_stmt_close($chk);

        $title = sanitize_input($_POST['title'] ?? '');
        $paper_code = sanitize_input($_POST['paper_code'] ?? '');
        $paper_password_raw = $_POST['paper_password'] ?? '';
        $description = sanitize_input($_POST['description'] ?? '');
        $department_id = intval($_POST['department_id'] ?? 0);
        $batch_id = intval($_POST['batch_id'] ?? 0);
        $semester = intval($_POST['semester'] ?? 0);
        $mega_exam_id = !empty($_POST['mega_exam_id']) ? intval($_POST['mega_exam_id']) : null;
        $start_datetime_input = $_POST['start_datetime'] ?? '';
        $end_datetime_input = $_POST['end_datetime'] ?? '';
        $duration_minutes = intval($_POST['duration_minutes'] ?? 0);
        $total_marks = intval($_POST['total_marks'] ?? 0);
        $passing_marks = 0;
        $enable_time_window = isset($_POST['enable_time_window']) ? 1 : 0;
        
        // Convert datetime inputs only if time window is enabled
        if ($enable_time_window) {
            $start_datetime = to_mysql_dt($start_datetime_input);
            $end_datetime = to_mysql_dt($end_datetime_input);
        } else {
            $start_datetime = null;
            $end_datetime = null;
        }

        // Validation
        $errors = [];
        
        if (empty($title)) $errors[] = 'Paper title is required.';
        if (empty($paper_code)) $errors[] = 'Paper code is required.';
        if ($department_id <= 0) $errors[] = 'Department is required.';
        if ($batch_id <= 0) $errors[] = 'Batch is required.';
        if ($semester <= 0) $errors[] = 'Semester is required.';
        if ($enable_time_window) {
            if (empty($start_datetime_input)) $errors[] = 'Start datetime is required when time window is enabled.';
            if (empty($end_datetime_input)) $errors[] = 'End datetime is required when time window is enabled.';
            
            // Check if datetime is in the past ONLY when time window is enabled
            if (!empty($start_datetime_input) && is_datetime_in_past($start_datetime_input)) {
                $errors[] = 'Start datetime cannot be in the past.';
            }
            
            // Check if end time is after start time
            if (!empty($start_datetime_input) && !empty($end_datetime_input)) {
                $start_time = strtotime($start_datetime_input);
                $end_time = strtotime($end_datetime_input);
                if ($end_time <= $start_time) {
                    $errors[] = 'End time must be after start time.';
                }
            }
        }
        if ($duration_minutes <= 0) $errors[] = 'Duration is required.';
        if ($total_marks <= 0) $errors[] = 'Total marks is required.';

        if (!empty($errors)) {
            set_message('danger', implode(' ', $errors));
            redirect('./create_exam.php');
        }

        // Update paper using handler function
        $result = update_exam(
            $exam_id,
            $title,
            $paper_code,
            $paper_password_raw,
            $description,
            $department_id,
            $batch_id,
            $semester,
            $mega_exam_id,
            $start_datetime,
            $end_datetime,
            $duration_minutes,
            $total_marks,
            $passing_marks,
            $teacher_id
        );

        if ($result['success']) {
            log_audit($teacher_id, 'update_exam', 'exams', $exam_id, null, "Updated paper");
            set_message('success', 'Paper updated successfully.');
        } else {
            set_message('danger', 'Failed to update paper: ' . $result['message']);
        }
        redirect('./create_exam.php');
    }

    // ---------- DELETE EXAM ----------
    if (isset($_POST['delete_exam'])) {
        $exam_id = intval($_POST['exam_id'] ?? 0);
        if ($exam_id <= 0) {
            set_message('danger', 'Invalid paper id.');
            redirect('./create_exam.php');
        }

        // Verify ownership
        $chk = mysqli_prepare($conn, "SELECT id FROM exams WHERE id = ? AND teacher_id = ? LIMIT 1");
        mysqli_stmt_bind_param($chk, "ii", $exam_id, $teacher_id);
        mysqli_stmt_execute($chk);
        mysqli_stmt_store_result($chk);
        if (mysqli_stmt_num_rows($chk) == 0) {
            mysqli_stmt_close($chk);
            set_message('danger', 'Permission denied or paper not found.');
            redirect('./create_exam.php');
        }
        mysqli_stmt_close($chk);

        // Cascade deletion
        mysqli_begin_transaction($conn);
        try {
            // Remove sections/questions/options/answers for this paper
            $sres = mysqli_query($conn, "SELECT id FROM exam_sections WHERE exam_id = " . intval($exam_id));
            while ($srow = mysqli_fetch_assoc($sres)) {
                $section_id = intval($srow['id']);
                $qres = mysqli_query($conn, "SELECT id FROM questions WHERE section_id = $section_id");
                while ($qrow = mysqli_fetch_assoc($qres)) {
                    $qid = intval($qrow['id']);
                    mysqli_query($conn, "DELETE FROM mcq_options WHERE question_id = $qid");
                    mysqli_query($conn, "DELETE FROM student_answers WHERE question_id = $qid");
                    mysqli_query($conn, "DELETE FROM questions WHERE id = $qid");
                }
                mysqli_query($conn, "DELETE FROM exam_sections WHERE id = $section_id");
            }

            // Remove evaluations / results / sessions
            mysqli_query($conn, "DELETE e FROM evaluations e JOIN student_exam_sessions s ON e.session_id = s.id WHERE s.exam_id = $exam_id");
            mysqli_query($conn, "DELETE FROM results WHERE exam_id = $exam_id");
            mysqli_query($conn, "DELETE FROM student_exam_sessions WHERE exam_id = $exam_id");

            // Delete paper
            $stmt = mysqli_prepare($conn, "DELETE FROM exams WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $exam_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            mysqli_commit($conn);
            log_audit($teacher_id, 'delete_exam', 'exams', $exam_id, null, 'Deleted paper and related data');
            set_message('success', 'Paper deleted (and related data).');
        } catch (Exception $e) {
            mysqli_rollback($conn);
            set_message('danger', 'Failed to delete paper: ' . $e->getMessage());
        }

        redirect('./create_exam.php');
    }
}

// ===== Fetch data for selects and list =====
$departments = mysqli_query($conn, "SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name ASC");
$batches = mysqli_query($conn, "SELECT id, name, year, department_id FROM batches WHERE is_active = 1 ORDER BY year DESC");
$mega_exams = mysqli_query($conn, "SELECT id, title, mega_exam_code FROM mega_exams ORDER BY created_at DESC");
$exams = mysqli_query($conn, "SELECT e.*, 
    me.title as mega_exam_title,
    CASE 
        WHEN e.is_approved = 1 THEN 'Approved'
        ELSE 'Pending'
    END as approval_status
    FROM exams e 
    LEFT JOIN mega_exams me ON e.mega_exam_id = me.id
    WHERE e.teacher_id = " . intval($teacher_id) . " 
    ORDER BY e.start_datetime DESC");

include '../templates/header.php';
include '../templates/sidebar_teacher.php';
?>

<style>
.hidden { display: none; }
.form-inline-grid { display:grid; grid-template-columns: repeat(2, 1fr); gap:12px; }
@media(max-width:700px){ .form-inline-grid{grid-template-columns:1fr} }
.card { margin-bottom: 1rem; }
.btn-icon { padding: 0.25rem 0.5rem; font-size: 0.875rem; }
.table th { font-weight: 600; }
.alert { margin-bottom: 1rem; }
.time-window-fields { margin-top: 10px; border-left: 3px solid #0d6efd; padding-left: 15px; }
</style>

<div class="main-content">
    <div class="top-navbar d-flex justify-content-between align-items-center mb-3">
        <h4><i class="fas fa-file-alt"></i> Papers</h4>
        <button class="btn btn-primary" id="btnCreate"><i class="fas fa-plus"></i> Add Paper</button>
    </div>

    <div class="content-area">
        <?php render_messages_fallback(); ?>

        <!-- Papers Table -->
        <div id="examTable" class="card">
            <div class="card-header">Your Papers</div>
            <div class="card-body table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Paper Title</th>
                            <th>Code</th>
                            <th>Department</th>
                            <th>Batch</th>
                            <th>Semester</th>
                            <th>Total Marks</th>
                            <th>Mega Exam</th>
                            <th>Time Window</th>
                            <th>Status</th>
                            <th>Start (PKT)</th>
                            <th>End (PKT)</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = mysqli_fetch_assoc($exams)): 
                            // Check if time window exists
                            $has_time_window = (!empty($row['start_datetime']) && !empty($row['end_datetime']));
                            
                            // Format dates for display if time window exists
                            $start_display = '-';
                            $end_display = '-';
                            $start_js = '';
                            $end_js = '';
                            
                            if ($has_time_window) {
                                try {
                                    $start_dt = new DateTime($row['start_datetime'], new DateTimeZone('Asia/Karachi'));
                                    $end_dt = new DateTime($row['end_datetime'], new DateTimeZone('Asia/Karachi'));
                                    
                                    $start_display = $start_dt->format('Y-m-d H:i');
                                    $end_display = $end_dt->format('Y-m-d H:i');
                                    $start_js = $start_dt->format('Y-m-d\TH:i');
                                    $end_js = $end_dt->format('Y-m-d\TH:i');
                                } catch (Exception $e) {
                                    error_log("Date parsing error: " . $e->getMessage());
                                }
                            }
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($row['title']) ?></td>
                            <td><?= htmlspecialchars($row['exam_code']) ?></td>
                            <td>
                                <?php
                                $dname = '';
                                $dq = mysqli_query($conn, "SELECT name FROM departments WHERE id = " . intval($row['department_id']));
                                if ($dq && $drow = mysqli_fetch_assoc($dq)) $dname = $drow['name'];
                                echo htmlspecialchars($dname);
                                ?>
                            </td>
                            <td>
                                <?php
                                $bname = '';
                                $bq = mysqli_query($conn, "SELECT name, year FROM batches WHERE id = " . intval($row['batch_id']));
                                if ($bq && $brow = mysqli_fetch_assoc($bq)) $bname = $brow['name'] . ' (' . $brow['year'] . ')';
                                echo htmlspecialchars($bname);
                                ?>
                            </td>
                            <td><?= intval($row['semester']) ?></td>
                            <td><span class="badge bg-primary"><?= intval($row['total_marks']) ?></span></td>
                            <td>
                                <?php if (!empty($row['mega_exam_title'])): ?>
                                    <span class="badge bg-info"><?= htmlspecialchars($row['mega_exam_title']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($has_time_window): ?>
                                    <span class="badge bg-success">Enabled</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Always Available</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $row['is_approved'] ? 'bg-success' : 'bg-warning' ?>">
                                    <?= htmlspecialchars($row['approval_status']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($start_display) ?></td>
                            <td><?= htmlspecialchars($end_display) ?></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-primary btn-icon btnEdit" 
                                            data-exam='<?= htmlspecialchars(json_encode([
                                                'id' => $row['id'],
                                                'title' => $row['title'],
                                                'exam_code' => $row['exam_code'],
                                                'description' => $row['description'],
                                                'department_id' => $row['department_id'],
                                                'batch_id' => $row['batch_id'],
                                                'semester' => $row['semester'],
                                                'duration_minutes' => $row['duration_minutes'],
                                                'total_marks' => $row['total_marks'],
                                                'mega_exam_id' => $row['mega_exam_id'],
                                                'has_time_window' => $has_time_window,
                                                'start_datetime' => $start_js,
                                                'end_datetime' => $end_js
                                            ]), ENT_QUOTES, 'UTF-8') ?>' 
                                            title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>

                                    <form method="POST" style="display:inline-block;" onsubmit="return confirm('Delete this paper and all related data?');">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="exam_id" value="<?= intval($row['id']) ?>">
                                        <button type="submit" name="delete_exam" class="btn btn-outline-danger btn-icon" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>

                                    <a href="manage_sections.php?exam_id=<?= intval($row['id']) ?>" class="btn btn-outline-secondary btn-icon" title="Manage Sections">
                                        <i class="fas fa-list"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                <?php if (mysqli_num_rows($exams) === 0): ?>
                    <p class="text-muted text-center">You have not created any papers yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Paper Form -->
        <div id="examFormBox" class="card hidden">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong id="formTitle">Create Paper</strong>
                <button class="btn-close" id="closeForm"></button>
            </div>

            <div class="card-body">
                <form id="examForm" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" id="exam_id" name="exam_id" value="">

                    <!-- Mega Exam Field - First Row -->
                    <div class="mb-3">
                        <label class="form-label">Mega Exam</label>
                        <select name="mega_exam_id" id="mega_exam_id" class="form-select">
                            <option value="">-- Select Mega Exam (Optional) --</option>
                            <?php mysqli_data_seek($mega_exams, 0); while ($mega = mysqli_fetch_assoc($mega_exams)): ?>
                                <option value="<?= intval($mega['id']) ?>"><?= htmlspecialchars($mega['title']) ?> (<?= htmlspecialchars($mega['mega_exam_code']) ?>)</option>
                            <?php endwhile; ?>
                        </select>
                        <small class="text-muted">Link this paper to a mega exam</small>
                    </div>

                    <!-- All other fields in grid layout -->
                    <div class="form-inline-grid mb-3">
                        <div>
                            <label class="form-label">Paper Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" id="title" class="form-control" 
                                   pattern="[A-Za-z\s]+" 
                                   title="Only letters and spaces allowed" 
                                   maxlength="60" required>
                        </div>

                        <div>
                            <label class="form-label">Paper Code <span class="text-danger">*</span></label>
                            <input type="text" name="paper_code" id="paper_code" class="form-control" 
                                   maxlength="30" required>
                        </div>

                        <div>
                            <label class="form-label">Department <span class="text-danger">*</span></label>
                            <select name="department_id" id="department_id" class="form-select" required>
                                <option value="">-- Select Department --</option>
                                <?php mysqli_data_seek($departments, 0); while ($d = mysqli_fetch_assoc($departments)): ?>
                                    <option value="<?= intval($d['id']) ?>"><?= htmlspecialchars($d['name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div>
                            <label class="form-label">Batch <span class="text-danger">*</span></label>
                            <select name="batch_id" id="batch_id" class="form-select" required>
                                <option value="">-- Select Batch --</option>
                                <?php mysqli_data_seek($batches, 0); while ($b = mysqli_fetch_assoc($batches)): ?>
                                    <option value="<?= intval($b['id']) ?>" data-dept="<?= intval($b['department_id']) ?>"><?= htmlspecialchars($b['name']) ?> (<?= intval($b['year']) ?>)</option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div>
                            <label class="form-label">Semester <span class="text-danger">*</span></label>
                            <select name="semester" id="semester" class="form-select" required>
                                <option value="">-- Select Semester --</option>
                                <?php for($i = 1; $i <= 8; $i++): ?>
                                    <option value="<?= $i ?>">Semester <?= $i ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div>
                            <label class="form-label">Duration (minutes) <span class="text-danger">*</span></label>
                            <input type="number" min="1" max="999" name="duration_minutes" id="duration_minutes" 
                                   class="form-control" required>
                        </div>

                        <div>
                            <label class="form-label">Total Marks <span class="text-danger">*</span></label>
                            <input type="number" min="1" max="100" name="total_marks" id="total_marks" 
                                   class="form-control" required>
                        </div>

                        <div>
                            <label class="form-label">Password</label>
                            <input type="text" name="paper_password" id="paper_password" class="form-control" placeholder="Enter password for paper access">
                            <small class="text-muted" id="passwordHelp">Leave empty for no password</small>
                        </div>
                    </div>

                    <!-- Time Window Toggle -->
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="enable_time_window" id="enable_time_window" value="1" checked>
                            <label class="form-check-label" for="enable_time_window">
                                <strong>Enable Time Window</strong>
                            </label>
                            <small class="text-muted d-block">If checked, students can only attempt during specified time. If unchecked, paper is always available.</small>
                        </div>
                    </div>

                    <!-- DateTime Fields (Shown by default) -->
                    <div id="timeWindowFields" class="time-window-fields">
                        <div class="form-inline-grid mb-3">
                            <div>
                                <label class="form-label">Start Date & Time (PKT) <span class="text-danger">*</span></label>
                                <input type="datetime-local" name="start_datetime" id="start_datetime" class="form-control">
                                <small class="text-muted">Pakistan Standard Time (Asia/Karachi)</small>
                            </div>
                            <div>
                                <label class="form-label">End Date & Time (PKT) <span class="text-danger">*</span></label>
                                <input type="datetime-local" name="end_datetime" id="end_datetime" class="form-control">
                                <small class="text-muted">Pakistan Standard Time (Asia/Karachi)</small>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" id="createBtn" name="create_exam">Create Paper</button>
                        <button type="submit" class="btn btn-success hidden" id="updateBtn" name="update_exam">Update Paper</button>
                        <button type="button" class="btn btn-secondary" id="cancelBtn">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>

<?php include '../templates/footer.php'; ?>

<script>
// UI toggles
const btnCreate = document.getElementById('btnCreate');
const examTable = document.getElementById('examTable');
const examFormBox = document.getElementById('examFormBox');
const closeForm = document.getElementById('closeForm');
const cancelBtn = document.getElementById('cancelBtn');
const formTitle = document.getElementById('formTitle');
const createBtn = document.getElementById('createBtn');
const updateBtn = document.getElementById('updateBtn');
const passwordHelp = document.getElementById('passwordHelp');
const timeWindowToggle = document.getElementById('enable_time_window');
const timeWindowFields = document.getElementById('timeWindowFields');

// Set minimum datetime to current time (local time = Karachi time)
function setMinDateTime() {
    const now = new Date();
    
    // Get local time (which is Karachi time)
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    
    const minDateTime = `${year}-${month}-${day}T${hours}:${minutes}`;
    
    // Set min attribute for datetime inputs
    document.getElementById('start_datetime').min = minDateTime;
    document.getElementById('end_datetime').min = minDateTime;
}

// Toggle time window fields
function toggleTimeWindowFields() {
    if (timeWindowToggle.checked) {
        timeWindowFields.classList.remove('hidden');
        document.getElementById('start_datetime').required = true;
        document.getElementById('end_datetime').required = true;
        setMinDateTime();
    } else {
        timeWindowFields.classList.add('hidden');
        document.getElementById('start_datetime').required = false;
        document.getElementById('end_datetime').required = false;
        // Clear values when disabled
        document.getElementById('start_datetime').value = '';
        document.getElementById('end_datetime').value = '';
    }
}

function showForm(mode = 'create') {
    examTable.classList.add('hidden');
    examFormBox.classList.remove('hidden');
    if (mode === 'create') {
        formTitle.innerText = 'Create Paper';
        createBtn.classList.remove('hidden');
        updateBtn.classList.add('hidden');
        document.getElementById('examForm').reset();
        document.getElementById('exam_id').value = '';
        document.getElementById('enable_time_window').checked = true; // Default checked
        passwordHelp.textContent = 'Leave empty for no password';
        filterBatches();
        toggleTimeWindowFields(); // Initialize toggle state
    } else {
        formTitle.innerText = 'Edit Paper';
        createBtn.classList.add('hidden');
        updateBtn.classList.remove('hidden');
        passwordHelp.textContent = 'Enter new password to change, leave empty to keep current password';
    }
}

function hideForm() {
    examTable.classList.remove('hidden');
    examFormBox.classList.add('hidden');
}

// Event listeners
btnCreate?.addEventListener('click', () => showForm('create'));
closeForm?.addEventListener('click', hideForm);
cancelBtn?.addEventListener('click', hideForm);
timeWindowToggle?.addEventListener('change', toggleTimeWindowFields);

// Populate form on Edit
document.querySelectorAll('.btnEdit').forEach(btn => {
    btn.addEventListener('click', () => {
        const raw = btn.getAttribute('data-exam');
        let d;
        try { 
            d = JSON.parse(raw); 
        } catch (e) { 
            alert('Invalid paper data'); 
            return; 
        }

        // Fill all fields with existing data
        document.getElementById('exam_id').value = d.id || '';
        document.getElementById('title').value = d.title || '';
        document.getElementById('paper_code').value = d.exam_code || '';
        document.getElementById('paper_password').value = '';
        document.getElementById('mega_exam_id').value = d.mega_exam_id || '';
        document.getElementById('department_id').value = d.department_id || '';
        
        filterBatches();
        setTimeout(() => {
            document.getElementById('batch_id').value = d.batch_id || '';
        }, 100);
        
        document.getElementById('semester').value = d.semester || '';
        document.getElementById('duration_minutes').value = d.duration_minutes || '';
        document.getElementById('total_marks').value = d.total_marks || '';
        
        // Handle time window toggle
        const timeWindowEnabled = d.has_time_window || false;
        document.getElementById('enable_time_window').checked = timeWindowEnabled;
        
        // Set datetime values if they exist
        if (d.start_datetime) {
            document.getElementById('start_datetime').value = d.start_datetime || '';
        }
        if (d.end_datetime) {
            document.getElementById('end_datetime').value = d.end_datetime || '';
        }
        
        // Toggle fields visibility
        toggleTimeWindowFields();

        showForm('edit');
        window.scrollTo({top:0,behavior:'smooth'});
    });
});

// Filter batches by selected department
const deptSelect = document.getElementById('department_id');
const batchSelect = document.getElementById('batch_id');

function filterBatches(){
    const selDept = deptSelect.value;
    const options = batchSelect.querySelectorAll('option[data-dept]');
    options.forEach(opt => {
        if (!selDept || selDept === '') {
            opt.style.display = '';
        } else {
            opt.style.display = (opt.getAttribute('data-dept') === selDept) ? '' : 'none';
        }
    });
    // Reset if currently selected option is hidden
    if (batchSelect.selectedOptions.length && batchSelect.selectedOptions[0].style.display === 'none') {
        batchSelect.value = '';
    }
}
deptSelect?.addEventListener('change', filterBatches);
window.addEventListener('load', filterBatches);

// Form validation
document.getElementById('examForm')?.addEventListener('submit', function(e) {
    const title = document.getElementById('title').value;
    const paperCode = document.getElementById('paper_code').value;
    const semester = document.getElementById('semester').value;
    const duration = document.getElementById('duration_minutes').value;
    const totalMarks = document.getElementById('total_marks').value;
    const timeWindowEnabled = document.getElementById('enable_time_window').checked;
    const start = document.getElementById('start_datetime').value;
    const end = document.getElementById('end_datetime').value;
    
    // Paper Title validation
    const titleRegex = /^[A-Za-z\s]+$/;
    if (!titleRegex.test(title)) {
        e.preventDefault();
        alert('Paper Title: Only letters and spaces are allowed. Numbers and special characters are not permitted.');
        document.getElementById('title').focus();
        return false;
    }
    
    if (title.length > 60) {
        e.preventDefault();
        alert('Paper Title: Maximum 60 characters allowed.');
        document.getElementById('title').focus();
        return false;
    }
    
    // Paper Code validation
    if (paperCode.length > 30) {
        e.preventDefault();
        alert('Paper Code: Maximum 30 characters allowed.');
        document.getElementById('paper_code').focus();
        return false;
    }
    
    // Semester validation
    if (!semester || semester < 1 || semester > 8) {
        e.preventDefault();
        alert('Please select a valid semester (1-8).');
        document.getElementById('semester').focus();
        return false;
    }
    
    // Duration validation
    if (!duration || duration < 1 || duration > 999) {
        e.preventDefault();
        alert('Duration: Please enter a positive number (1-999 minutes).');
        document.getElementById('duration_minutes').focus();
        return false;
    }
    
    // Total Marks validation
    if (!totalMarks || totalMarks < 1 || totalMarks > 100) {
        e.preventDefault();
        alert('Total Marks: Please enter a value between 1 and 100.');
        document.getElementById('total_marks').focus();
        return false;
    }
    
    // DateTime validation (ONLY if time window is ENABLED)
    if (timeWindowEnabled) {
        // Check if datetime fields are filled when time window is enabled
        if (!start || !end) {
            e.preventDefault();
            alert('Please fill both start and end datetime fields when time window is enabled.');
            return false;
        }
        
        const startTime = new Date(start).getTime();
        const endTime = new Date(end).getTime();
        const now = new Date().getTime();
        
        if (endTime <= startTime) {
            e.preventDefault();
            alert('End time must be after start time.');
            return false;
        }
        
        if (startTime < now) {
            e.preventDefault();
            alert('Start time cannot be in the past.');
            return false;
        }
    }
    // If time window is DISABLED, no datetime validation needed
});

// Real-time validation
document.getElementById('title')?.addEventListener('input', function(e) {
    this.value = this.value.replace(/[^A-Za-z\s]/g, '');
});

document.getElementById('paper_code')?.addEventListener('input', function(e) {
    if (this.value.length > 30) {
        this.value = this.value.substring(0, 30);
    }
});

document.getElementById('duration_minutes')?.addEventListener('input', function(e) {
    if (this.value > 999) this.value = 999;
    if (this.value < 1) this.value = 1;
});

document.getElementById('total_marks')?.addEventListener('input', function(e) {
    if (this.value > 100) this.value = 100;
    if (this.value < 1) this.value = 1;
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    setMinDateTime();
    filterBatches();
});
</script>
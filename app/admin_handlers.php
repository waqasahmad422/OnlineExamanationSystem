<?php
// admin_handlers.php
// Full set of admin helper functions including exam CRUD with `paper_name`

require_once 'config.php';
require_once 'helpers.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

/**
 * -------------------------
 * USERS
 * -------------------------
 */

function create_user($username, $password, $full_name, $email, $mobile_number, $role, $department_id = null, $batch_id = null, $semester = null, $roll_number = null) {
    global $conn;

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    $stmt = mysqli_prepare($conn, "INSERT INTO users (username, password, full_name, email, mobile_number, role, department_id, batch_id, semester, roll_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) return ['success' => false, 'message' => 'Prepare failed: ' . mysqli_error($conn)];

    mysqli_stmt_bind_param($stmt, "ssssssiiis", $username, $hashed_password, $full_name, $email, $mobile_number, $role, $department_id, $batch_id, $semester, $roll_number);

    if (mysqli_stmt_execute($stmt)) {
        $user_id = mysqli_insert_id($conn);
        log_audit($_SESSION['user_id'] ?? null, 'create_user', 'users', $user_id, null, "Created user: $username");
        mysqli_stmt_close($stmt);
        return ['success' => true, 'user_id' => $user_id];
    } else {
        $err = mysqli_error($conn);
        mysqli_stmt_close($stmt);
        return ['success' => false, 'message' => 'Failed to create user: ' . $err];
    }
}

function update_user($user_id, $full_name, $email, $mobile_number, $is_active, $department_id = null, $batch_id = null, $semester = null, $roll_number = null) {
    global $conn;

    $stmt = mysqli_prepare($conn, "UPDATE users SET full_name = ?, email = ?, mobile_number = ?, is_active = ?, department_id = ?, batch_id = ?, semester = ?, roll_number = ? WHERE id = ?");
    if (!$stmt) return ['success' => false, 'message' => 'Prepare failed: ' . mysqli_error($conn)];

    // types: s, s, s, i, i, i, i, s, i
    mysqli_stmt_bind_param($stmt, "sssiiiisi", $full_name, $email, $mobile_number, $is_active, $department_id, $batch_id, $semester, $roll_number, $user_id);

    if (mysqli_stmt_execute($stmt)) {
        log_audit($_SESSION['user_id'] ?? null, 'update_user', 'users', $user_id, null, "Updated user ID: $user_id");
        mysqli_stmt_close($stmt);
        return ['success' => true];
    } else {
        $err = mysqli_error($conn);
        mysqli_stmt_close($stmt);
        return ['success' => false, 'message' => 'Failed to update user: ' . $err];
    }
}

function delete_user($user_id) {
    global $conn;

    $stmt = mysqli_prepare($conn, "DELETE FROM users WHERE id = ?");
    if (!$stmt) return ['success' => false, 'message' => 'Prepare failed: ' . mysqli_error($conn)];

    mysqli_stmt_bind_param($stmt, "i", $user_id);

    if (mysqli_stmt_execute($stmt)) {
        log_audit($_SESSION['user_id'] ?? null, 'delete_user', 'users', $user_id, null, "Deleted user ID: $user_id");
        mysqli_stmt_close($stmt);
        return ['success' => true];
    } else {
        $err = mysqli_error($conn);
        mysqli_stmt_close($stmt);
        return ['success' => false, 'message' => 'Failed to delete user: ' . $err];
    }
}

/**
 * -------------------------
 * DEPARTMENTS & BATCHES
 * -------------------------
 */

function create_department($name, $code) {
    global $conn;

    $stmt = mysqli_prepare($conn, "INSERT INTO departments (name, code) VALUES (?, ?)");
    if (!$stmt) return ['success' => false, 'message' => 'Prepare failed: ' . mysqli_error($conn)];

    mysqli_stmt_bind_param($stmt, "ss", $name, $code);

    if (mysqli_stmt_execute($stmt)) {
        $dept_id = mysqli_insert_id($conn);
        log_audit($_SESSION['user_id'] ?? null, 'create_department', 'departments', $dept_id, null, "Created department: $name");
        mysqli_stmt_close($stmt);
        return ['success' => true, 'dept_id' => $dept_id];
    } else {
        $err = mysqli_error($conn);
        mysqli_stmt_close($stmt);
        return ['success' => false, 'message' => 'Failed to create department: ' . $err];
    }
}

function create_batch($name, $year, $department_id) {
    global $conn;

    $stmt = mysqli_prepare($conn, "INSERT INTO batches (name, year, department_id) VALUES (?, ?, ?)");
    if (!$stmt) return ['success' => false, 'message' => 'Prepare failed: ' . mysqli_error($conn)];

    mysqli_stmt_bind_param($stmt, "sii", $name, $year, $department_id);

    if (mysqli_stmt_execute($stmt)) {
        $batch_id = mysqli_insert_id($conn);
        log_audit($_SESSION['user_id'] ?? null, 'create_batch', 'batches', $batch_id, null, "Created batch: $name");
        mysqli_stmt_close($stmt);
        return ['success' => true, 'batch_id' => $batch_id];
    } else {
        $err = mysqli_error($conn);
        mysqli_stmt_close($stmt);
        return ['success' => false, 'message' => 'Failed to create batch: ' . $err];
    }
}

/**
 * -------------------------
 * EXAMS (with paper_name)
 * -------------------------
 */

/**
 * create_exam
 * Required: title, paper_name, exam_code, exam_password, department_id, batch_id, semester, teacher_id, start_datetime, end_datetime, duration_minutes
 * Optional: description, total_marks, passing_marks, max_violations, is_active
 */
function create_exam($title, $paper_name, $exam_code, $exam_password, $description, $department_id, $batch_id, $semester, $teacher_id, $start_datetime, $end_datetime, $duration_minutes, $total_marks = 0, $passing_marks = 0, $max_violations = 3, $is_active = 1) {
    global $conn;

    // Hash exam password for security
    $hashed_password = password_hash($exam_password, PASSWORD_DEFAULT);

    $stmt = mysqli_prepare($conn, "
        INSERT INTO exams
        (title, paper_name, exam_code, exam_password, description, department_id, batch_id, semester, teacher_id, total_marks, passing_marks, start_datetime, end_datetime, duration_minutes, max_violations, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) return ['success' => false, 'message' => 'Prepare failed: ' . mysqli_error($conn)];

    // types: 7 strings, 9 ints -> 16 total
    $types = "sssssssiiiiiiiii"; // 7 s, 9 i
    mysqli_stmt_bind_param($stmt, $types,
        $title,
        $paper_name,
        $exam_code,
        $hashed_password,
        $description,
        $department_id,
        $batch_id,
        $semester,
        $teacher_id,
        $total_marks,
        $passing_marks,
        $start_datetime,
        $end_datetime,
        $duration_minutes,
        $max_violations,
        $is_active
    );

    if (mysqli_stmt_execute($stmt)) {
        $exam_id = mysqli_insert_id($conn);
        log_audit($_SESSION['user_id'] ?? null, 'create_exam', 'exams', $exam_id, null, "Created exam: $title (paper: $paper_name)");
        mysqli_stmt_close($stmt);
        return ['success' => true, 'exam_id' => $exam_id];
    } else {
        $err = mysqli_error($conn);
        mysqli_stmt_close($stmt);
        return ['success' => false, 'message' => 'Failed to create exam: ' . $err];
    }
}

/**
 * update_exam
 * Update an existing exam (you can set new paper_name here)
 */
function update_exam($exam_id, $title, $paper_name, $description, $department_id, $batch_id, $semester, $start_datetime, $end_datetime, $duration_minutes, $total_marks = 0, $passing_marks = 0, $max_violations = 3, $is_active = 1) {
    global $conn;

    $stmt = mysqli_prepare($conn, "
        UPDATE exams SET
            title = ?,
            paper_name = ?,
            description = ?,
            department_id = ?,
            batch_id = ?,
            semester = ?,
            start_datetime = ?,
            end_datetime = ?,
            duration_minutes = ?,
            total_marks = ?,
            passing_marks = ?,
            max_violations = ?,
            is_active = ?
        WHERE id = ?
    ");
    if (!$stmt) return ['success' => false, 'message' => 'Prepare failed: ' . mysqli_error($conn)];

    // types: s s s i i i s s i i i i i -> count 14 (3 strings + 3 ints + 2 strings + 6 ints?) let's compute:
    // title(s), paper_name(s), description(s), department_id(i), batch_id(i), semester(i), start_datetime(s), end_datetime(s), duration_minutes(i), total_marks(i), passing_marks(i), max_violations(i), is_active(i), exam_id(i)
    $types = "sssiiissiiiii i"; // remove spaces and correct: "sssiiissiiiii i" -> properly: "sssiiissiiiiii"
    // Build correct types: 3 s -> "sss", 3 i -> "iii", 2 s -> "ss", 6 i -> "iiiiii" => combined "sssiii ss iiiiii" -> final "sssiiissiiiiii"
    $types = "sssiiissiiiiii";

    mysqli_stmt_bind_param($stmt, $types,
        $title,
        $paper_name,
        $description,
        $department_id,
        $batch_id,
        $semester,
        $start_datetime,
        $end_datetime,
        $duration_minutes,
        $total_marks,
        $passing_marks,
        $max_violations,
        $is_active,
        $exam_id
    );

    if (mysqli_stmt_execute($stmt)) {
        log_audit($_SESSION['user_id'] ?? null, 'update_exam', 'exams', $exam_id, null, "Updated exam ID: $exam_id (paper: $paper_name)");
        mysqli_stmt_close($stmt);
        return ['success' => true];
    } else {
        $err = mysqli_error($conn);
        mysqli_stmt_close($stmt);
        return ['success' => false, 'message' => 'Failed to update exam: ' . $err];
    }
}

/**
 * delete_exam
 */
function delete_exam($exam_id) {
    global $conn;

    $stmt = mysqli_prepare($conn, "DELETE FROM exams WHERE id = ?");
    if (!$stmt) return ['success' => false, 'message' => 'Prepare failed: ' . mysqli_error($conn)];

    mysqli_stmt_bind_param($stmt, "i", $exam_id);

    if (mysqli_stmt_execute($stmt)) {
        log_audit($_SESSION['user_id'] ?? null, 'delete_exam', 'exams', $exam_id, null, "Deleted exam ID: $exam_id");
        mysqli_stmt_close($stmt);
        return ['success' => true];
    } else {
        $err = mysqli_error($conn);
        mysqli_stmt_close($stmt);
        return ['success' => false, 'message' => 'Failed to delete exam: ' . $err];
    }
}

/**
 * get_exam_by_id
 */
function get_exam_by_id($exam_id) {
    global $conn;

    $stmt = mysqli_prepare($conn, "SELECT * FROM exams WHERE id = ?");
    if (!$stmt) return null;

    mysqli_stmt_bind_param($stmt, "i", $exam_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $exam = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    return $exam ? $exam : null;
}

/**
 * list_exams (basic)
 * $filters: associative array e.g. ['department_id' => 1, 'is_active' => 1]
 */
function list_exams($filters = []) {
    global $conn;

    $sql = "SELECT e.*, d.name AS department_name, b.name AS batch_name, u.full_name AS teacher_name
            FROM exams e
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN batches b ON e.batch_id = b.id
            LEFT JOIN users u ON e.teacher_id = u.id
            WHERE 1=1";

    $params = [];
    $types = "";

    if (isset($filters['department_id'])) {
        $sql .= " AND e.department_id = ?";
        $types .= "i";
        $params[] = $filters['department_id'];
    }
    if (isset($filters['batch_id'])) {
        $sql .= " AND e.batch_id = ?";
        $types .= "i";
        $params[] = $filters['batch_id'];
    }
    if (isset($filters['is_active'])) {
        $sql .= " AND e.is_active = ?";
        $types .= "i";
        $params[] = $filters['is_active'];
    }
    if (isset($filters['is_approved'])) {
        $sql .= " AND e.is_approved = ?";
        $types .= "i";
        $params[] = $filters['is_approved'];
    }

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return ['success' => false, 'message' => 'Prepare failed: ' . mysqli_error($conn)];

    if (!empty($params)) {
        // bind params dynamically
        $bind_names[] = $types;
        for ($i = 0; $i < count($params); $i++) {
            $bind_name = 'bind' . $i;
            $$bind_name = $params[$i];
            $bind_names[] = &$$bind_name;
        }
        call_user_func_array([$stmt, 'bind_param'], $bind_names);
    }

    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
    }
    mysqli_stmt_close($stmt);
    return ['success' => true, 'data' => $rows];
}

/**
 * -------------------------
 * APPROVALS
 * -------------------------
 */

function approve_exam($exam_id) {
    global $conn;

    $admin_id = $_SESSION['user_id'] ?? null;
    $stmt = mysqli_prepare($conn, "UPDATE exams SET is_approved = 1, approved_by = ?, approved_at = NOW() WHERE id = ?");
    if (!$stmt) return ['success' => false, 'message' => 'Prepare failed: ' . mysqli_error($conn)];

    mysqli_stmt_bind_param($stmt, "ii", $admin_id, $exam_id);

    if (mysqli_stmt_execute($stmt)) {
        log_audit($admin_id, 'approve_exam', 'exams', $exam_id, null, "Approved exam ID: $exam_id");
        mysqli_stmt_close($stmt);
        return ['success' => true];
    } else {
        $err = mysqli_error($conn);
        mysqli_stmt_close($stmt);
        return ['success' => false, 'message' => 'Failed to approve exam: ' . $err];
    }
}

function approve_result($result_id) {
    global $conn;

    $admin_id = $_SESSION['user_id'] ?? null;
    $stmt = mysqli_prepare($conn, "UPDATE results SET is_approved = 1, approved_by = ?, approved_at = NOW() WHERE id = ?");
    if (!$stmt) return ['success' => false, 'message' => 'Prepare failed: ' . mysqli_error($conn)];

    mysqli_stmt_bind_param($stmt, "ii", $admin_id, $result_id);

    if (mysqli_stmt_execute($stmt)) {
        log_audit($admin_id, 'approve_result', 'results', $result_id, null, "Approved result ID: $result_id");
        mysqli_stmt_close($stmt);
        return ['success' => true];
    } else {
        $err = mysqli_error($conn);
        mysqli_stmt_close($stmt);
        return ['success' => false, 'message' => 'Failed to approve result: ' . $err];
    }
}

?>
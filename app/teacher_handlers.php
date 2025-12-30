<?php
// app/teacher_handlers.php
require_once 'config.php';
require_once 'helpers.php';

/* =======================================================================
   TEACHER HANDLER FUNCTIONS
   ======================================================================= */

/**
 * Create a new exam
 * Parameters: 14 parameters
 */
function create_exam($title, $exam_code, $exam_password, $description, $dept_id, $batch_id, $semester, $teacher_id, $mega_exam_id, $start_datetime, $end_datetime, $duration_minutes, $total_marks, $passing_marks = 0) {
    global $conn;

    // Set Karachi timezone
    date_default_timezone_set('Asia/Karachi');

    $hashed_password = !empty($exam_password) ? password_hash($exam_password, PASSWORD_DEFAULT) : '';

    // Handle NULL mega_exam_id
    if (empty($mega_exam_id)) {
        $mega_exam_id = null;
    }

    // Handle datetime fields - they can be null if time window is disabled
    $start_datetime_mysql = null;
    $end_datetime_mysql = null;

    // Only validate datetime if both are provided (time window is enabled)
    if (!empty($start_datetime) && !empty($end_datetime)) {
        try {
            $start_dt = new DateTime($start_datetime, new DateTimeZone('Asia/Karachi'));
            $end_dt = new DateTime($end_datetime, new DateTimeZone('Asia/Karachi'));
            
            $start_datetime_mysql = $start_dt->format('Y-m-d H:i:s');
            $end_datetime_mysql = $end_dt->format('Y-m-d H:i:s');
            
            // Validate datetime logic
            if ($end_dt <= $start_dt) {
                return ['success' => false, 'message' => 'End datetime must be after start datetime'];
            }
            
            $now = new DateTime('now', new DateTimeZone('Asia/Karachi'));
            if ($start_dt < $now) {
                return ['success' => false, 'message' => 'Start datetime cannot be in the past'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Invalid datetime format: ' . $e->getMessage()];
        }
    } else if (!empty($start_datetime) || !empty($end_datetime)) {
        // If only one datetime is provided (partial input)
        return ['success' => false, 'message' => 'Both start and end datetime must be provided if time window is enabled'];
    }
    // If both are empty/null, that's OK (time window disabled)

    $sql = "INSERT INTO exams 
        (title, exam_code, exam_password, description, department_id, batch_id, semester, teacher_id, mega_exam_id, start_datetime, end_datetime, duration_minutes, total_marks, passing_marks, is_active, is_approved, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, NOW(), NOW())";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['success' => false, 'message' => 'Database preparation failed: ' . mysqli_error($conn)];
    }

    // Bind parameters properly - use "ssssiiiiisssiii" if datetimes are strings, or handle NULLs
    // When binding NULL values, we need to use a different approach
    if ($start_datetime_mysql === null && $end_datetime_mysql === null) {
        // Both datetimes are NULL
        $bind_result = mysqli_stmt_bind_param($stmt, "ssssiiiiissiii",
            $title,                    // s - string
            $exam_code,               // s - string
            $hashed_password,         // s - string
            $description,             // s - string
            $dept_id,                 // i - integer
            $batch_id,                // i - integer
            $semester,                // i - integer
            $teacher_id,              // i - integer
            $mega_exam_id,            // i - integer (can be NULL)
            $start_datetime_mysql,    // s - string (NULL)
            $end_datetime_mysql,      // s - string (NULL)
            $duration_minutes,        // i - integer
            $total_marks,             // i - integer
            $passing_marks            // i - integer
        );
    } else {
        // Datetimes are not NULL
        $bind_result = mysqli_stmt_bind_param($stmt, "ssssiiiiissiii",
            $title,                    // s - string
            $exam_code,               // s - string
            $hashed_password,         // s - string
            $description,             // s - string
            $dept_id,                 // i - integer
            $batch_id,                // i - integer
            $semester,                // i - integer
            $teacher_id,              // i - integer
            $mega_exam_id,            // i - integer (can be NULL)
            $start_datetime_mysql,    // s - string
            $end_datetime_mysql,      // s - string
            $duration_minutes,        // i - integer
            $total_marks,             // i - integer
            $passing_marks            // i - integer
        );
    }

    if (!$bind_result) {
        mysqli_stmt_close($stmt);
        return ['success' => false, 'message' => 'Parameter binding failed'];
    }

    // For NULL values, we need to use a different approach
    if ($start_datetime_mysql === null) {
        mysqli_stmt_send_long_data($stmt, 9, ''); // Send empty string for NULL
    }
    if ($end_datetime_mysql === null) {
        mysqli_stmt_send_long_data($stmt, 10, ''); // Send empty string for NULL
    }

    if (mysqli_stmt_execute($stmt)) {
        $exam_id = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        return ['success' => true, 'exam_id' => $exam_id];
    } else {
        $err = mysqli_error($conn);
        mysqli_stmt_close($stmt);
        return ['success' => false, 'message' => 'Database insertion failed: ' . $err];
    }
}

/**
 * Update an exam
 * Parameters: 15 parameters (added passing_marks)
 */
function update_exam($exam_id, $title, $exam_code, $exam_password, $description, $dept_id, $batch_id, $semester, $mega_exam_id, $start_datetime, $end_datetime, $duration_minutes, $total_marks, $passing_marks, $teacher_id) {
    global $conn;

    // Set Karachi timezone
    date_default_timezone_set('Asia/Karachi');

    // Verify ownership
    $check_stmt = mysqli_prepare($conn, "SELECT id FROM exams WHERE id = ? AND teacher_id = ?");
    if (!$check_stmt) {
        return ['success' => false, 'message' => 'Database preparation failed'];
    }
    
    mysqli_stmt_bind_param($check_stmt, "ii", $exam_id, $teacher_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);

    if (mysqli_num_rows($check_result) == 0) {
        mysqli_stmt_close($check_stmt);
        return ['success' => false, 'message' => 'Exam not found or access denied'];
    }
    mysqli_stmt_close($check_stmt);

    // Handle NULL mega_exam_id
    if (empty($mega_exam_id)) {
        $mega_exam_id = null;
    }

    // Handle datetime fields - they can be null if time window is disabled
    $start_datetime_mysql = null;
    $end_datetime_mysql = null;

    // Only validate datetime if both are provided (time window is enabled)
    if (!empty($start_datetime) && !empty($end_datetime)) {
        try {
            $start_dt = new DateTime($start_datetime, new DateTimeZone('Asia/Karachi'));
            $end_dt = new DateTime($end_datetime, new DateTimeZone('Asia/Karachi'));
            
            $start_datetime_mysql = $start_dt->format('Y-m-d H:i:s');
            $end_datetime_mysql = $end_dt->format('Y-m-d H:i:s');
            
            if ($end_dt <= $start_dt) {
                return ['success' => false, 'message' => 'End datetime must be after start datetime'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Invalid datetime format: ' . $e->getMessage()];
        }
    } else if (!empty($start_datetime) || !empty($end_datetime)) {
        // If only one datetime is provided (partial input)
        return ['success' => false, 'message' => 'Both start and end datetime must be provided if time window is enabled'];
    }
    // If both are empty/null, that's OK (time window disabled)

    // Build update query
    if (!empty($exam_password)) {
        $hashed_password = password_hash($exam_password, PASSWORD_DEFAULT);
        $sql = "UPDATE exams SET 
            title = ?, exam_code = ?, exam_password = ?, description = ?, 
            department_id = ?, batch_id = ?, semester = ?, mega_exam_id = ?, 
            start_datetime = ?, end_datetime = ?, duration_minutes = ?, total_marks = ?, passing_marks = ?,
            updated_at = NOW() WHERE id = ? AND teacher_id = ?";
        
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return ['success' => false, 'message' => mysqli_error($conn)];
        }
        
        // Type string: "sssiiiissiiiii" - 15 parameters
        mysqli_stmt_bind_param($stmt, "sssiiiissiiiii",
            $title,               // s - string
            $exam_code,           // s - string
            $hashed_password,     // s - string
            $description,         // s - string
            $dept_id,             // i - integer
            $batch_id,            // i - integer
            $semester,            // i - integer
            $mega_exam_id,        // i - integer (can be NULL)
            $start_datetime_mysql,// s - string (can be NULL)
            $end_datetime_mysql,  // s - string (can be NULL)
            $duration_minutes,    // i - integer
            $total_marks,         // i - integer
            $passing_marks,       // i - integer
            $exam_id,             // i - integer
            $teacher_id           // i - integer
        );
    } else {
        $sql = "UPDATE exams SET 
            title = ?, exam_code = ?, description = ?, 
            department_id = ?, batch_id = ?, semester = ?, mega_exam_id = ?, 
            start_datetime = ?, end_datetime = ?, duration_minutes = ?, total_marks = ?, passing_marks = ?,
            updated_at = NOW() WHERE id = ? AND teacher_id = ?";
            
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return ['success' => false, 'message' => mysqli_error($conn)];
        }
        
        // Type string: "sssiiiissiiiii" - 15 parameters
        mysqli_stmt_bind_param($stmt, "sssiiiissiiiii",
            $title,               // s - string
            $exam_code,           // s - string
            $description,         // s - string
            $dept_id,             // i - integer
            $batch_id,            // i - integer
            $semester,            // i - integer
            $mega_exam_id,        // i - integer (can be NULL)
            $start_datetime_mysql,// s - string (can be NULL)
            $end_datetime_mysql,  // s - string (can be NULL)
            $duration_minutes,    // i - integer
            $total_marks,         // i - integer
            $passing_marks,       // i - integer
            $exam_id,             // i - integer
            $teacher_id           // i - integer
        );
    }

    // For NULL values
    if ($start_datetime_mysql === null) {
        mysqli_stmt_send_long_data($stmt, 8, ''); // Send empty string for NULL
    }
    if ($end_datetime_mysql === null) {
        mysqli_stmt_send_long_data($stmt, 9, ''); // Send empty string for NULL
    }

    if (mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['success' => true];
    } else {
        $err = mysqli_error($conn);
        mysqli_stmt_close($stmt);
        return ['success' => false, 'message' => $err];
    }
}

// Rest of the functions remain the same...
/**
 * Create exam section
 */
function create_exam_section($exam_id, $title, $section_type, $duration_minutes, $marks_per_question, $total_marks, $section_order, $instructions) {
    global $conn;

    $sql = "INSERT INTO exam_sections (exam_id, title, section_type, duration_minutes, marks_per_question, total_marks, section_order, instructions)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['success' => false, 'message' => mysqli_error($conn)];
    }

    mysqli_stmt_bind_param($stmt, "issidiis",
        $exam_id, $title, $section_type, $duration_minutes, $marks_per_question, $total_marks, $section_order, $instructions
    );

    if (mysqli_stmt_execute($stmt)) {
        $section_id = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        return ['success' => true, 'section_id' => $section_id];
    } else {
        $err = mysqli_error($conn);
        mysqli_stmt_close($stmt);
        return ['success' => false, 'message' => $err];
    }
}

/**
 * Create question
 */
function create_question($section_id, $question_text, $question_type, $correct_answer, $marks, $question_order) {
    global $conn;

    $sql = "INSERT INTO questions (section_id, question_text, question_type, correct_answer, marks, question_order)
            VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['success' => false, 'message' => mysqli_error($conn)];
    }

    mysqli_stmt_bind_param($stmt, "isssdi",
        $section_id, $question_text, $question_type, $correct_answer, $marks, $question_order
    );

    if (mysqli_stmt_execute($stmt)) {
        $question_id = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        return ['success' => true, 'question_id' => $question_id];
    } else {
        $err = mysqli_error($conn);
        mysqli_stmt_close($stmt);
        return ['success' => false, 'message' => $err];
    }
}

/**
 * Create MCQ option
 */
function create_mcq_option($question_id, $option_text, $option_order, $is_correct) {
    global $conn;

    $sql = "INSERT INTO mcq_options (question_id, option_text, option_order, is_correct) VALUES (?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['success' => false, 'message' => mysqli_error($conn)];
    }

    mysqli_stmt_bind_param($stmt, "isii", $question_id, $option_text, $option_order, $is_correct);

    $ok = mysqli_stmt_execute($stmt);
    $err = $ok ? null : mysqli_error($conn);
    mysqli_stmt_close($stmt);

    return ['success' => $ok, 'message' => $err];
}

/**
 * Delete a question
 */
function delete_question($question_id, $teacher_id) {
    global $conn;

    // Verify ownership
    $check_stmt = mysqli_prepare($conn, "
        SELECT q.id FROM questions q
        JOIN exam_sections s ON q.section_id = s.id
        JOIN exams e ON s.exam_id = e.id
        WHERE q.id = ? AND e.teacher_id = ?
    ");
    mysqli_stmt_bind_param($check_stmt, "ii", $question_id, $teacher_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);

    if (mysqli_num_rows($check_result) == 0) {
        mysqli_stmt_close($check_stmt);
        return ['success' => false, 'message' => 'Question not found or access denied'];
    }
    mysqli_stmt_close($check_stmt);

    // Begin transaction
    mysqli_begin_transaction($conn);

    try {
        // Delete MCQ options
        $del_opts = mysqli_prepare($conn, "DELETE FROM mcq_options WHERE question_id = ?");
        mysqli_stmt_bind_param($del_opts, "i", $question_id);
        mysqli_stmt_execute($del_opts);
        mysqli_stmt_close($del_opts);

        // Delete student answers
        $del_answers = mysqli_prepare($conn, "DELETE FROM student_answers WHERE question_id = ?");
        mysqli_stmt_bind_param($del_answers, "i", $question_id);
        mysqli_stmt_execute($del_answers);
        mysqli_stmt_close($del_answers);

        // Delete the question
        $del_question = mysqli_prepare($conn, "DELETE FROM questions WHERE id = ?");
        mysqli_stmt_bind_param($del_question, "i", $question_id);
        $success = mysqli_stmt_execute($del_question);
        mysqli_stmt_close($del_question);

        if ($success) {
            mysqli_commit($conn);
            return ['success' => true];
        } else {
            mysqli_rollback($conn);
            return ['success' => false, 'message' => mysqli_error($conn)];
        }
    } catch (Exception $e) {
        mysqli_rollback($conn);
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Delete an exam section
 */
function delete_exam_section($section_id, $teacher_id) {
    global $conn;

    // Verify ownership
    $check_stmt = mysqli_prepare($conn, "
        SELECT s.id FROM exam_sections s
        JOIN exams e ON s.exam_id = e.id
        WHERE s.id = ? AND e.teacher_id = ?
    ");
    mysqli_stmt_bind_param($check_stmt, "ii", $section_id, $teacher_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);

    if (mysqli_num_rows($check_result) == 0) {
        mysqli_stmt_close($check_stmt);
        return ['success' => false, 'message' => 'Section not found or access denied'];
    }
    mysqli_stmt_close($check_stmt);

    // Begin transaction
    mysqli_begin_transaction($conn);

    try {
        // Get all questions in this section
        $q_stmt = mysqli_prepare($conn, "SELECT id FROM questions WHERE section_id = ?");
        mysqli_stmt_bind_param($q_stmt, "i", $section_id);
        mysqli_stmt_execute($q_stmt);
        $q_result = mysqli_stmt_get_result($q_stmt);

        // Delete each question and its related data
        while ($question = mysqli_fetch_assoc($q_result)) {
            $question_id = $question['id'];

            // Delete MCQ options
            $del_opts = mysqli_prepare($conn, "DELETE FROM mcq_options WHERE question_id = ?");
            mysqli_stmt_bind_param($del_opts, "i", $question_id);
            mysqli_stmt_execute($del_opts);
            mysqli_stmt_close($del_opts);

            // Delete student answers
            $del_answers = mysqli_prepare($conn, "DELETE FROM student_answers WHERE question_id = ?");
            mysqli_stmt_bind_param($del_answers, "i", $question_id);
            mysqli_stmt_execute($del_answers);
            mysqli_stmt_close($del_answers);

            // Delete the question
            $del_question = mysqli_prepare($conn, "DELETE FROM questions WHERE id = ?");
            mysqli_stmt_bind_param($del_question, "i", $question_id);
            mysqli_stmt_execute($del_question);
            mysqli_stmt_close($del_question);
        }
        mysqli_stmt_close($q_stmt);

        // Delete the section
        $del_section = mysqli_prepare($conn, "DELETE FROM exam_sections WHERE id = ?");
        mysqli_stmt_bind_param($del_section, "i", $section_id);
        $success = mysqli_stmt_execute($del_section);
        mysqli_stmt_close($del_section);

        if ($success) {
            mysqli_commit($conn);
            return ['success' => true];
        } else {
            mysqli_rollback($conn);
            return ['success' => false, 'message' => mysqli_error($conn)];
        }
    } catch (Exception $e) {
        mysqli_rollback($conn);
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Evaluate an answer
 */
function evaluate_answer($answer_id, $marks_obtained, $teacher_id) {
    global $conn;

    // Verify ownership
    $check_stmt = mysqli_prepare($conn, "
        SELECT sa.id FROM student_answers sa
        JOIN questions q ON sa.question_id = q.id
        JOIN exam_sections s ON q.section_id = s.id
        JOIN exams e ON s.exam_id = e.id
        WHERE sa.id = ? AND e.teacher_id = ?
    ");
    mysqli_stmt_bind_param($check_stmt, "ii", $answer_id, $teacher_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);

    if (mysqli_num_rows($check_result) == 0) {
        mysqli_stmt_close($check_stmt);
        return ['success' => false, 'message' => 'Answer not found or access denied'];
    }
    mysqli_stmt_close($check_stmt);

    $sql = "UPDATE student_answers SET marks_obtained = ?, evaluated_at = NOW() WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['success' => false, 'message' => mysqli_error($conn)];
    }

    mysqli_stmt_bind_param($stmt, "di", $marks_obtained, $answer_id);
    $ok = mysqli_stmt_execute($stmt);
    $err = $ok ? null : mysqli_error($conn);
    mysqli_stmt_close($stmt);

    return ['success' => $ok, 'message' => $err];
}

/**
 * Complete evaluation
 */
function complete_evaluation($session_id, $teacher_id, $feedback) {
    global $conn;

    $session_id = intval($session_id);

    // Verify session exists and teacher has access
    $sres = mysqli_prepare($conn, "
        SELECT ses.student_id, ses.exam_id 
        FROM student_exam_sessions ses
        JOIN exams e ON ses.exam_id = e.id
        WHERE ses.id = ? AND e.teacher_id = ?
    ");
    mysqli_stmt_bind_param($sres, "ii", $session_id, $teacher_id);
    mysqli_stmt_execute($sres);
    $sres_get = mysqli_stmt_get_result($sres);
    if (!$sres_get || mysqli_num_rows($sres_get) == 0) {
        mysqli_stmt_close($sres);
        return ['success' => false, 'message' => 'Session not found or access denied'];
    }
    $session = mysqli_fetch_assoc($sres_get);
    mysqli_stmt_close($sres);

    // Calculate totals
    $sql = "SELECT COALESCE(SUM(q.marks),0) AS total_marks, COALESCE(SUM(sa.marks_obtained),0) AS marks_obtained
            FROM student_answers sa
            JOIN questions q ON sa.question_id = q.id
            WHERE sa.session_id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $session_id);
    mysqli_stmt_execute($stmt);
    $gres = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($gres);
    mysqli_stmt_close($stmt);

    $total_marks = (float) $row['total_marks'];
    $marks_obtained = (float) $row['marks_obtained'];
    $percentage = ($total_marks > 0) ? ($marks_obtained / $total_marks * 100) : 0.0;
    $grade = calculate_grade($percentage);

    // Get passing marks
    $er = mysqli_prepare($conn, "SELECT passing_marks FROM exams WHERE id = ?");
    mysqli_stmt_bind_param($er, "i", $session['exam_id']);
    mysqli_stmt_execute($er);
    $er_get = mysqli_stmt_get_result($er);
    $exam_row = mysqli_fetch_assoc($er_get);
    mysqli_stmt_close($er);
    $passing_marks = isset($exam_row['passing_marks']) ? (float) $exam_row['passing_marks'] : 0.0;
    $status = ($marks_obtained >= $passing_marks) ? 'pass' : 'fail';

    // Insert/update evaluations
    $stmt2 = mysqli_prepare($conn, "
        INSERT INTO evaluations (session_id, teacher_id, total_marks, marks_obtained, feedback, evaluated_at, status)
        VALUES (?, ?, ?, ?, ?, NOW(), ?)
        ON DUPLICATE KEY UPDATE
            teacher_id = VALUES(teacher_id),
            total_marks = VALUES(total_marks),
            marks_obtained = VALUES(marks_obtained),
            feedback = VALUES(feedback),
            evaluated_at = NOW(),
            status = VALUES(status)
    ");
    mysqli_stmt_bind_param($stmt2, "iiddss", $session_id, $teacher_id, $total_marks, $marks_obtained, $feedback, $status);
    $ok = mysqli_stmt_execute($stmt2);
    $err = $ok ? null : mysqli_error($conn);
    mysqli_stmt_close($stmt2);
    if (!$ok) {
        return ['success' => false, 'message' => $err];
    }

    // Insert/update results
    $stmt3 = mysqli_prepare($conn, "
        INSERT INTO results (session_id, student_id, exam_id, total_marks, marks_obtained, percentage, grade, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            total_marks = VALUES(total_marks),
            marks_obtained = VALUES(marks_obtained),
            percentage = VALUES(percentage),
            grade = VALUES(grade),
            status = VALUES(status)
    ");
    $student_i = intval($session['student_id']);
    $exam_i = intval($session['exam_id']);
    mysqli_stmt_bind_param($stmt3, "iiidddss", $session_id, $student_i, $exam_i, $total_marks, $marks_obtained, $percentage, $grade, $status);
    $ok2 = mysqli_stmt_execute($stmt3);
    $err2 = $ok2 ? null : mysqli_error($conn);
    mysqli_stmt_close($stmt3);
    if (!$ok2) {
        return ['success' => false, 'message' => $err2];
    }

    // Mark session completed
    $up = mysqli_prepare($conn, "UPDATE student_exam_sessions SET status = 'completed' WHERE id = ?");
    mysqli_stmt_bind_param($up, "i", $session_id);
    mysqli_stmt_execute($up);
    mysqli_stmt_close($up);

    return [
        'success' => true,
        'total_marks' => $total_marks,
        'marks_obtained' => $marks_obtained,
        'percentage' => $percentage,
        'grade' => $grade,
        'status' => $status
    ];
}

/**
 * Update a question
 */
function update_question($question_id, $question_text, $question_type, $correct_answer, $marks, $question_order, $teacher_id) {
    global $conn;

    $check_stmt = mysqli_prepare($conn, "
        SELECT q.id FROM questions q
        JOIN exam_sections s ON q.section_id = s.id
        JOIN exams e ON s.exam_id = e.id
        WHERE q.id = ? AND e.teacher_id = ?
    ");
    mysqli_stmt_bind_param($check_stmt, "ii", $question_id, $teacher_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);

    if (mysqli_num_rows($check_result) == 0) {
        mysqli_stmt_close($check_stmt);
        return ['success' => false, 'message' => 'Question not found or access denied'];
    }
    mysqli_stmt_close($check_stmt);

    $update_stmt = mysqli_prepare($conn, "
        UPDATE questions SET question_text = ?, question_type = ?, correct_answer = ?, marks = ?, question_order = ? WHERE id = ?
    ");
    mysqli_stmt_bind_param($update_stmt, "sssdii", $question_text, $question_type, $correct_answer, $marks, $question_order, $question_id);

    if (mysqli_stmt_execute($update_stmt)) {
        mysqli_stmt_close($update_stmt);
        return ['success' => true];
    } else {
        $error = mysqli_error($conn);
        mysqli_stmt_close($update_stmt);
        return ['success' => false, 'message' => $error];
    }
}

/**
 * Update an exam section
 */
function update_exam_section($section_id, $title, $section_type, $duration_minutes, $marks_per_question, $total_marks, $section_order, $instructions, $teacher_id) {
    global $conn;

    // Verify ownership
    $check_stmt = mysqli_prepare($conn, "
        SELECT s.id FROM exam_sections s
        JOIN exams e ON s.exam_id = e.id
        WHERE s.id = ? AND e.teacher_id = ?
    ");
    mysqli_stmt_bind_param($check_stmt, "ii", $section_id, $teacher_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);

    if (mysqli_num_rows($check_result) == 0) {
        mysqli_stmt_close($check_stmt);
        return ['success' => false, 'message' => 'Section not found or access denied'];
    }
    mysqli_stmt_close($check_stmt);

    $update_stmt = mysqli_prepare($conn, "
        UPDATE exam_sections 
        SET title = ?, section_type = ?, duration_minutes = ?, marks_per_question = ?, total_marks = ?, section_order = ?, instructions = ?
        WHERE id = ?
    ");

    mysqli_stmt_bind_param($update_stmt, "ssidiisi",
        $title, $section_type, $duration_minutes, $marks_per_question, $total_marks, $section_order, $instructions, $section_id
    );

    if (mysqli_stmt_execute($update_stmt)) {
        mysqli_stmt_close($update_stmt);
        return ['success' => true];
    } else {
        $error = mysqli_error($conn);
        mysqli_stmt_close($update_stmt);
        return ['success' => false, 'message' => $error];
    }
}

/**
 * Get teacher's exams
 */
function get_teacher_exams($teacher_id) {
    global $conn;

    $sql = "SELECT e.*, d.name as department_name, b.name as batch_name, b.year as batch_year
            FROM exams e
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN batches b ON e.batch_id = b.id
            WHERE e.teacher_id = ?
            ORDER BY e.created_at DESC";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }

    mysqli_stmt_bind_param($stmt, "i", $teacher_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $exams = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $exams[] = $row;
    }

    mysqli_stmt_close($stmt);
    return $exams;
}

/**
 * Check if teacher owns the exam
 */
function teacher_owns_exam($exam_id, $teacher_id) {
    global $conn;

    $sql = "SELECT id FROM exams WHERE id = ? AND teacher_id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "ii", $exam_id, $teacher_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $count = mysqli_stmt_num_rows($stmt);
    mysqli_stmt_close($stmt);

    return $count > 0;
}

/**
 * Get exam sections
 */
function get_exam_sections($exam_id, $teacher_id) {
    global $conn;

    // Verify ownership
    if (!teacher_owns_exam($exam_id, $teacher_id)) {
        return [];
    }

    $sql = "SELECT * FROM exam_sections WHERE exam_id = ? ORDER BY section_order ASC";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }

    mysqli_stmt_bind_param($stmt, "i", $exam_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $sections = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $sections[] = $row;
    }

    mysqli_stmt_close($stmt);
    return $sections;
}

/**
 * Get section questions
 */
function get_section_questions($section_id, $teacher_id) {
    global $conn;

    // Verify ownership
    $check_stmt = mysqli_prepare($conn, "
        SELECT s.id FROM exam_sections s
        JOIN exams e ON s.exam_id = e.id
        WHERE s.id = ? AND e.teacher_id = ?
    ");
    mysqli_stmt_bind_param($check_stmt, "ii", $section_id, $teacher_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);

    if (mysqli_num_rows($check_result) == 0) {
        mysqli_stmt_close($check_stmt);
        return [];
    }
    mysqli_stmt_close($check_stmt);

    $sql = "SELECT q.*, 
                   (SELECT COUNT(*) FROM mcq_options mo WHERE mo.question_id = q.id) as option_count
            FROM questions q 
            WHERE q.section_id = ? 
            ORDER BY q.question_order ASC";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }

    mysqli_stmt_bind_param($stmt, "i", $section_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $questions = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $questions[] = $row;
    }

    mysqli_stmt_close($stmt);
    return $questions;
}

/**
 * Get question options
 */
function get_question_options($question_id, $teacher_id) {
    global $conn;

    // Verify ownership
    $check_stmt = mysqli_prepare($conn, "
        SELECT q.id FROM questions q
        JOIN exam_sections s ON q.section_id = s.id
        JOIN exams e ON s.exam_id = e.id
        WHERE q.id = ? AND e.teacher_id = ?
    ");
    mysqli_stmt_bind_param($check_stmt, "ii", $question_id, $teacher_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);

    if (mysqli_num_rows($check_result) == 0) {
        mysqli_stmt_close($check_stmt);
        return [];
    }
    mysqli_stmt_close($check_stmt);

    $sql = "SELECT * FROM mcq_options WHERE question_id = ? ORDER BY option_order ASC";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }

    mysqli_stmt_bind_param($stmt, "i", $question_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $options = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $options[] = $row;
    }

    mysqli_stmt_close($stmt);
    return $options;
}
?>
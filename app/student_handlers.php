<?php
require_once 'config.php';
require_once 'helpers.php';

function start_exam_session($student_id, $exam_id, $exam_password_input) {
    global $conn;
    
    $stmt = mysqli_prepare($conn, "SELECT exam_password FROM exams WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $exam_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $exam = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if (!$exam || !password_verify($exam_password_input, $exam['exam_password'])) {
        return ['success' => false, 'message' => 'Invalid exam password'];
    }
    
    $check = mysqli_query($conn, "SELECT id FROM student_exam_sessions WHERE student_id = $student_id AND exam_id = $exam_id");
    if (mysqli_num_rows($check) > 0) {
        return ['success' => false, 'message' => 'You have already taken this exam'];
    }
    
    $session_token = generate_session_token();
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $stmt = mysqli_prepare($conn, "INSERT INTO student_exam_sessions (student_id, exam_id, session_token, started_at, ip_address, user_agent) VALUES (?, ?, ?, NOW(), ?, ?)");
    mysqli_stmt_bind_param($stmt, "iisss", $student_id, $exam_id, $session_token, $ip_address, $user_agent);
    
    if (mysqli_stmt_execute($stmt)) {
        $session_id = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        log_audit($student_id, 'start_exam', 'student_exam_sessions', $session_id, null, "Started exam ID: $exam_id");
        return ['success' => true, 'session_id' => $session_id, 'session_token' => $session_token];
    } else {
        mysqli_stmt_close($stmt);
        return ['success' => false, 'message' => 'Failed to start exam session'];
    }
}

function save_answer($session_id, $question_id, $answer_text = null, $selected_option_id = null) {
    global $conn;
    
    $stmt = mysqli_prepare($conn, "INSERT INTO student_answers (session_id, question_id, answer_text, selected_option_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE answer_text = ?, selected_option_id = ?, saved_at = CURRENT_TIMESTAMP");
    mysqli_stmt_bind_param($stmt, "iisisi", $session_id, $question_id, $answer_text, $selected_option_id, $answer_text, $selected_option_id);
    
    if (mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        
        if ($selected_option_id) {
            $check = mysqli_query($conn, "SELECT is_correct FROM mcq_options WHERE id = $selected_option_id");
            if ($check && $row = mysqli_fetch_assoc($check)) {
                $question_data = mysqli_query($conn, "SELECT marks FROM questions WHERE id = $question_id");
                $q = mysqli_fetch_assoc($question_data);
                $marks = $row['is_correct'] ? $q['marks'] : 0;
                $is_correct = $row['is_correct'];
                
                mysqli_query($conn, "UPDATE student_answers SET is_correct = $is_correct, marks_obtained = $marks WHERE session_id = $session_id AND question_id = $question_id");
            }
        }
        
        return ['success' => true];
    } else {
        mysqli_stmt_close($stmt);
        return ['success' => false];
    }
}

function submit_exam($session_id, $status = 'completed') {
    global $conn;
    
    $stmt = mysqli_prepare($conn, "UPDATE student_exam_sessions SET submitted_at = NOW(), status = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "si", $status, $session_id);
    
    if (mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        log_audit($_SESSION['user_id'] ?? null, 'submit_exam', 'student_exam_sessions', $session_id, null, "Exam submitted with status: $status");
        return ['success' => true];
    } else {
        mysqli_stmt_close($stmt);
        return ['success' => false];
    }
}

function log_violation($session_id, $violation_type) {
    global $conn;
    
    mysqli_query($conn, "UPDATE student_exam_sessions SET violation_count = violation_count + 1 WHERE id = $session_id");
    
    $result = mysqli_query($conn, "SELECT violation_count, exam_id FROM student_exam_sessions WHERE id = $session_id");
    $session = mysqli_fetch_assoc($result);
    
    $exam_data = mysqli_query($conn, "SELECT max_violations FROM exams WHERE id = {$session['exam_id']}");
    $exam = mysqli_fetch_assoc($exam_data);
    
    if ($session['violation_count'] >= $exam['max_violations']) {
        submit_exam($session_id, 'auto_submitted');
        return ['auto_submit' => true];
    }
    
    return ['auto_submit' => false];
}
// Add these corrected functions to student_handlers.php

function calculate_section_results($session_id, $section_id) {
    global $conn;
    
    // Get all MCQ questions in the section with their correct answers from mcq_options
    $stmt = mysqli_prepare($conn, "
        SELECT q.id, q.marks, q.question_type, 
               sa.selected_option_id, sa.answer_text,
               mo.id as correct_option_id
        FROM questions q 
        LEFT JOIN student_answers sa ON q.id = sa.question_id AND sa.session_id = ?
        LEFT JOIN mcq_options mo ON mo.question_id = q.id AND mo.is_correct = 1
        WHERE q.section_id = ?
    ");
    mysqli_stmt_bind_param($stmt, "ii", $session_id, $section_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $questions = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
    
    $total_questions = 0;
    $mcq_questions = 0;
    $correct_answers = 0;
    $total_marks = 0;
    $score = 0;
    
    // Group by question to handle multiple options
    $processed_questions = [];
    foreach ($questions as $question) {
        $qid = $question['id'];
        if (!isset($processed_questions[$qid])) {
            $processed_questions[$qid] = $question;
            $total_questions++;
            $total_marks += intval($question['marks']);
            
            if ($question['question_type'] === 'mcq') {
                $mcq_questions++;
                // Check if selected option matches correct option
                if ($question['selected_option_id'] && $question['correct_option_id'] && 
                    intval($question['selected_option_id']) === intval($question['correct_option_id'])) {
                    $correct_answers++;
                    $score += intval($question['marks']);
                }
            } else {
                // For descriptive questions, assume they get full marks (or adjust as needed)
                if (!empty($question['answer_text'])) {
                    $score += intval($question['marks']);
                }
            }
        }
    }
    
    $percentage = $total_marks > 0 ? round(($score / $total_marks) * 100, 2) : 0;
    
    return [
        'total_questions' => $total_questions,
        'mcq_questions' => $mcq_questions,
        'correct_answers' => $correct_answers,
        'total_marks' => $total_marks,
        'score' => $score,
        'percentage' => $percentage
    ];
}

function is_section_completed($session_id, $section_id) {
    global $conn;
    
    $stmt = mysqli_prepare($conn, "
        SELECT COUNT(*) as answered 
        FROM student_answers sa 
        JOIN questions q ON sa.question_id = q.id 
        WHERE sa.session_id = ? AND q.section_id = ?
    ");
    mysqli_stmt_bind_param($stmt, "ii", $session_id, $section_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $answered = $result ? mysqli_fetch_assoc($result) : ['answered' => 0];
    
    $stmt2 = mysqli_prepare($conn, "SELECT COUNT(*) as total FROM questions WHERE section_id = ?");
    mysqli_stmt_bind_param($stmt2, "i", $section_id);
    mysqli_stmt_execute($stmt2);
    $result2 = mysqli_stmt_get_result($stmt2);
    $total = $result2 ? mysqli_fetch_assoc($result2) : ['total' => 0];
    
    return intval($answered['answered']) >= intval($total['total']);
}

function mark_section_completed($session_id, $section_id) {
    global $conn;
    
    // Create a table to track section completion if it doesn't exist
    $create_table = "
        CREATE TABLE IF NOT EXISTS section_completion (
            id INT PRIMARY KEY AUTO_INCREMENT,
            session_id INT,
            section_id INT,
            completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (session_id) REFERENCES student_exam_sessions(id),
            FOREIGN KEY (section_id) REFERENCES exam_sections(id),
            UNIQUE KEY unique_session_section (session_id, section_id)
        )
    ";
    mysqli_query($conn, $create_table);
    
    // Mark section as completed
    $stmt = mysqli_prepare($conn, "
        INSERT INTO section_completion (session_id, section_id) 
        VALUES (?, ?) 
        ON DUPLICATE KEY UPDATE completed_at = CURRENT_TIMESTAMP
    ");
    mysqli_stmt_bind_param($stmt, "ii", $session_id, $section_id);
    return mysqli_stmt_execute($stmt);
}
?>

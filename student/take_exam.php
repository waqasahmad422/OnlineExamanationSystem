<?php
// take_exam.php (with enhanced time persistence and resume functionality)

require_once '../app/config.php';
require_once '../app/helpers.php';
require_once '../app/auth.php';
require_once '../app/student_handlers.php';

require_role(['student']);

$exam_id = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : 0;
$student_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;

if ($exam_id <= 0 || $student_id <= 0) {
    set_message('danger', 'Invalid request.');
    redirect('./exams.php');
    exit;
}

// Helper: json response and exit
function json_response($arr)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr);
    exit;
}

// Helper: format time in seconds to MM:SS
function format_time_php($seconds)
{
    $seconds = max(0, intval($seconds));
    $minutes = floor($seconds / 60);
    $secs = $seconds % 60;
    return sprintf("%02d:%02d", $minutes, $secs);
}

// --------------------
// Handle POST actions (AJAX + form submits)
// --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Start exam
    if (isset($_POST['start_exam']) && verify_csrf_token($_POST['csrf_token'])) {
        $exam_password = isset($_POST['exam_password']) ? $_POST['exam_password'] : '';
        $result = start_exam_session($student_id, $exam_id, $exam_password);

        if (isset($result['success']) && $result['success']) {
            $_SESSION['exam_session_id'] = $result['session_id'];
            $_SESSION['exam_session_token'] = $result['session_token'];
            $_SESSION['exam_start_time'] = time();
            $_SESSION['total_exam_duration'] = intval($result['exam_data']['duration_minutes']) * 60;
            $_SESSION['exam_started'] = true;
            $_SESSION['current_exam_id'] = $exam_id;

            redirect($_SERVER['PHP_SELF'] . '?exam_id=' . $exam_id . '&session=' . intval($result['session_id']));
            exit;
        } else {
            set_message('danger', $result['message'] ?? 'Failed to start exam.');
            redirect($_SERVER['PHP_SELF'] . '?exam_id=' . $exam_id);
            exit;
        }
    }

    // Save answer (AJAX/autosave)
    if (isset($_POST['action']) && $_POST['action'] === 'save_answer') {
        $session_id = isset($_POST['session_id']) ? intval($_POST['session_id']) : 0;
        $question_id = isset($_POST['question_id']) ? intval($_POST['question_id']) : 0;
        $answer_text = isset($_POST['answer_text']) ? trim($_POST['answer_text']) : null;
        $selected_option = isset($_POST['selected_option']) ? intval($_POST['selected_option']) : null;

        if ($session_id <= 0 || $question_id <= 0) {
            json_response(['success' => false, 'message' => 'Invalid session or question.']);
        }

        $ok = save_answer($session_id, $question_id, $answer_text, $selected_option);

        if ($ok) {
            json_response(['success' => true, 'message' => 'saved']);
        } else {
            json_response(['success' => false, 'message' => 'Failed to save answer.']);
        }
    }

    // Submit exam (final)
    if (isset($_POST['submit_exam']) && verify_csrf_token($_POST['csrf_token'])) {
        $session_id = isset($_POST['session_id']) ? intval($_POST['session_id']) : 0;
        if ($session_id <= 0) {
            set_message('danger', 'Invalid session.');
            redirect('./exams.php');
            exit;
        }

        submit_exam($session_id, 'completed');

        $_SESSION['exam_submitted'] = true;
        $_SESSION['submitted_exam_id'] = $exam_id;

        unset($_SESSION['exam_start_time']);
        unset($_SESSION['total_exam_duration']);
        unset($_SESSION['exam_started']);
        unset($_SESSION['current_exam_id']);

        redirect('./results.php?exam_id=' . $exam_id);
        exit;
    }

    // Proceed to next section (auto-submit section)
    if (isset($_POST['submit_section']) && verify_csrf_token($_POST['csrf_token'])) {
        $session_id = isset($_POST['session_id']) ? intval($_POST['session_id']) : 0;
        $current_section_id = isset($_POST['current_section_id']) ? intval($_POST['current_section_id']) : 0;

        if ($session_id <= 0 || $current_section_id <= 0) {
            set_message('danger', 'Invalid parameters.');
            redirect('./exams.php');
            exit;
        }

        // Mark current section as completed
        mark_section_completed($session_id, $current_section_id);

        // Find next section
        $stmt_sections = mysqli_prepare($conn, "SELECT * FROM exam_sections WHERE exam_id = ? ORDER BY section_order");
        mysqli_stmt_bind_param($stmt_sections, "i", $exam_id);
        mysqli_stmt_execute($stmt_sections);
        $res_sections = mysqli_stmt_get_result($stmt_sections);
        $sections = $res_sections ? mysqli_fetch_all($res_sections, MYSQLI_ASSOC) : [];

        $next_section_id = 0;
        $current_section_index = array_search($current_section_id, array_column($sections, 'id'));
        if ($current_section_index !== false && isset($sections[$current_section_index + 1])) {
            $next_section_id = intval($sections[$current_section_index + 1]['id']);
        }

        if ($next_section_id > 0) {
            redirect($_SERVER['PHP_SELF'] . '?exam_id=' . $exam_id . '&session=' . $session_id . '&section=' . $next_section_id);
        } else {
            redirect($_SERVER['PHP_SELF'] . '?exam_id=' . $exam_id . '&session=' . $session_id . '&section=' . $current_section_id . '&show_completion=true');
        }
        exit;
    }

    // Final submission from completion screen
    if (isset($_POST['final_submit_exam']) && verify_csrf_token($_POST['csrf_token'])) {
        $session_id = isset($_POST['session_id']) ? intval($_POST['session_id']) : 0;
        if ($session_id <= 0) {
            set_message('danger', 'Invalid session.');
            redirect('./exams.php');
            exit;
        }

        submit_exam($session_id, 'completed');

        $_SESSION['exam_submitted'] = true;
        $_SESSION['submitted_exam_id'] = $exam_id;

        unset($_SESSION['exam_start_time']);
        unset($_SESSION['total_exam_duration']);
        unset($_SESSION['exam_started']);
        unset($_SESSION['current_exam_id']);

        redirect('./results.php?exam_id=' . $exam_id);
        exit;
    }

    // Get question (AJAX navigation)
    if (isset($_POST['action']) && $_POST['action'] === 'get_question') {
        $session_id = isset($_POST['session_id']) ? intval($_POST['session_id']) : 0;
        $section_id = isset($_POST['section_id']) ? intval($_POST['section_id']) : 0;
        $question_num = isset($_POST['question_num']) ? intval($_POST['question_num']) : 1;

        if ($session_id <= 0 || $section_id <= 0 || $question_num <= 0) {
            json_response(['success' => false, 'message' => 'Invalid parameters.']);
        }

        $offset = max(0, $question_num - 1);
        $stmt = mysqli_prepare($conn, "SELECT * FROM questions WHERE section_id = ? ORDER BY question_order LIMIT 1 OFFSET ?");
        mysqli_stmt_bind_param($stmt, "ii", $section_id, $offset);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $question = $res ? mysqli_fetch_assoc($res) : null;

        if (!$question) {
            json_response(['success' => false, 'message' => 'Question not found.']);
        }

        $response = [
            'success' => true,
            'question' => $question,
            'question_num' => $question_num
        ];

        if ($question['question_type'] === 'mcq') {
            $opt_stmt = mysqli_prepare($conn, "SELECT id, option_text, option_order FROM mcq_options WHERE question_id = ? ORDER BY option_order");
            mysqli_stmt_bind_param($opt_stmt, "i", $question['id']);
            mysqli_stmt_execute($opt_stmt);
            $opt_res = mysqli_stmt_get_result($opt_stmt);
            $options = $opt_res ? mysqli_fetch_all($opt_res, MYSQLI_ASSOC) : [];
            $response['options'] = $options;
        }

        $ans_stmt = mysqli_prepare($conn, "SELECT * FROM student_answers WHERE session_id = ? AND question_id = ? LIMIT 1");
        mysqli_stmt_bind_param($ans_stmt, "ii", $session_id, $question['id']);
        mysqli_stmt_execute($ans_stmt);
        $ans_res = mysqli_stmt_get_result($ans_stmt);
        $saved = $ans_res ? mysqli_fetch_assoc($ans_res) : null;
        $response['saved_answer'] = $saved;

        json_response($response);
    }

    // Handle security violation time storage
    if (isset($_POST['store_section_time']) && isset($_POST['session_id']) && isset($_POST['section_time_remaining'])) {
        $session_id = intval($_POST['session_id']);
        $section_time_remaining = intval($_POST['section_time_remaining']);

        $stmt = mysqli_prepare($conn, "UPDATE student_exam_sessions SET section_time_remaining = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $section_time_remaining, $session_id);
        mysqli_stmt_execute($stmt);

        redirect('./exams.php');
        exit;
    }

    // Update section time remaining (AJAX - for real-time persistence) - CORRECTED VERSION
    if (isset($_POST['action']) && $_POST['action'] === 'update_section_time') {
        $session_id = isset($_POST['session_id']) ? intval($_POST['session_id']) : 0;
        $section_time_remaining = isset($_POST['section_time_remaining']) ? intval($_POST['section_time_remaining']) : 0;

        if ($session_id <= 0) {
            json_response(['success' => false, 'message' => 'Invalid session.']);
        }

        // Get current section duration for validation
        $stmt_check = mysqli_prepare($conn, "
            SELECT es.duration_minutes 
            FROM student_exam_sessions ses
            JOIN exam_sections es ON es.exam_id = ses.exam_id
            WHERE ses.id = ? 
            ORDER BY es.section_order LIMIT 1
        ");
        mysqli_stmt_bind_param($stmt_check, "i", $session_id);
        mysqli_stmt_execute($stmt_check);
        $res_check = mysqli_stmt_get_result($stmt_check);
        $check_data = $res_check ? mysqli_fetch_assoc($res_check) : null;

        $max_allowed_time = $check_data ? (intval($check_data['duration_minutes']) * 60) : 3600; // Default to 1 hour if not found

        // Validate and cap the time
        if ($section_time_remaining > $max_allowed_time) {
            // If time is larger than allowed, cap it
            $section_time_remaining = $max_allowed_time;
        }

        // Ensure it's not negative
        $section_time_remaining = max(0, $section_time_remaining);

        $stmt = mysqli_prepare($conn, "UPDATE student_exam_sessions SET section_time_remaining = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $section_time_remaining, $session_id);
        $result = mysqli_stmt_execute($stmt);

        if ($result) {
            json_response(['success' => true, 'message' => 'Time updated']);
        } else {
            json_response(['success' => false, 'message' => 'Failed to update time']);
        }
    }
}

// --------------------
// Page rendering (GET)
// --------------------

// Fetch exam (approved) with all related information
$stmt_exam = mysqli_prepare($conn, "
    SELECT 
        e.*, 
        me.title AS mega_exam_title, 
        me.mega_exam_code,
        d.name AS department_name,
        b.name AS batch_name,
        b.year AS batch_year,
        u.full_name AS teacher_name
    FROM exams e 
    LEFT JOIN mega_exams me ON e.mega_exam_id = me.id 
    LEFT JOIN departments d ON e.department_id = d.id 
    LEFT JOIN batches b ON e.batch_id = b.id 
    LEFT JOIN users u ON e.teacher_id = u.id 
    WHERE e.id = ? AND e.is_approved = 1 LIMIT 1
");
mysqli_stmt_bind_param($stmt_exam, "i", $exam_id);
mysqli_stmt_execute($stmt_exam);
$res_exam = mysqli_stmt_get_result($stmt_exam);
$exam_data = $res_exam ? mysqli_fetch_assoc($res_exam) : null;

if (!$exam_data) {
    set_message('danger', 'Exam not found or not approved.');
    redirect('./exams.php');
    exit;
}

// Student info with department and batch information
$stmt_student = mysqli_prepare($conn, "
    SELECT 
        u.id, 
        u.full_name, 
        u.username, 
        u.roll_number,
        u.semester,
        d.name AS department_name,
        b.name AS batch_name,
        b.year AS batch_year
    FROM users u 
    LEFT JOIN departments d ON u.department_id = d.id 
    LEFT JOIN batches b ON u.batch_id = b.id 
    WHERE u.id = ? LIMIT 1
");
mysqli_stmt_bind_param($stmt_student, "i", $student_id);
mysqli_stmt_execute($stmt_student);
$res_student = mysqli_stmt_get_result($stmt_student);
$student_data = $res_student ? mysqli_fetch_assoc($res_student) : null;

if (!$student_data) {
    set_message('danger', 'Student not found.');
    redirect('./exams.php');
    exit;
}

// College information
$college_info = [
    'name' => 'Govt Degree College Ekkaghund',
    'logo' => '../assets/images/logo.jpeg'
];

// If session not provided, show start screen
if (!isset($_GET['session'])) {
    $csrf_token = generate_csrf_token();
    include '../templates/header.php';
    ?>
    <div class="main-content">
        <div class="content-area">
            <div class="card" style="max-width: 800px; margin: 30px auto;">
                <!-- College Header -->
                <div class="card-header text-center bg-green">
                    <div class="logo-head">
                        <div>
                            <?php if (file_exists($college_info['logo'])): ?>
                                <img src="<?php echo $college_info['logo']; ?>" alt="College Logo"
                                    style="height: 60px; margin-bottom: 10px;">
                            <?php endif; ?>
                        </div>
                        <div>
                            <h4 class="mb-1"><?php echo htmlspecialchars($college_info['name']); ?></h4>
                        </div>
                    </div>

                    <div class="row mt-2">
                        <div class="col-4 text-start">
                            <strong>Dept:</strong> <?php echo htmlspecialchars($exam_data['department_name'] ?? 'N/A'); ?>
                        </div>
                        <div class="col-4 text-center">
                            <strong>Mega Exam:</strong>
                            <?php echo htmlspecialchars($exam_data['mega_exam_title'] ?? 'N/A'); ?>
                        </div>
                        <div class="col-4 text-end">
                            <strong>Batch:</strong> <?php echo htmlspecialchars($exam_data['batch_name'] ?? 'N/A'); ?>
                        </div>
                    </div>

                    <div class="row mt-2">
                        <div class="col-4 text-start">
                            <strong>Paper:</strong> <?php echo htmlspecialchars($exam_data['title']); ?>
                        </div>
                        <div class="col-4 text-center">
                            <?php if (!empty($exam_data['semester'])): ?>
                                <strong>Semester:</strong> <?php echo intval($exam_data['semester']); ?>
                            <?php endif; ?>
                        </div>
                        <div class="col-4 text-end">
                            <strong>Duration:</strong> <?php echo intval($exam_data['duration_minutes']); ?> minutes
                        </div>
                    </div>

                    <div class="row mt-2">
                        <div class="col-4 text-start">
                            <strong>Student:</strong> <?php echo htmlspecialchars($student_data['full_name']); ?>
                        </div>
                        <div class="col-4 text-center">
                            <strong>Roll No:</strong>
                            <?php echo htmlspecialchars($student_data['roll_number'] ?? $student_data['username']); ?>
                        </div>
                        <div class="col-4 text-end">
                            <strong>Total Marks:</strong> <?php echo intval($exam_data['total_marks']); ?>
                        </div>
                    </div>

                    <?php if (!empty($exam_data['passing_marks'])): ?>
                        <div class="row mt-2">
                            <div class="col-12 text-center">
                                <div class="badge bg-warning text-dark">
                                    <i class="fas fa-check-circle"></i>
                                    Passing Marks: <?php echo intval($exam_data['passing_marks']); ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card-body">
                    <form method="POST" class="mt-3">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <div class="mb-3">
                            <label class="form-label">Enter Exam Password</label>
                            <input type="password" name="exam_password" class="form-control" required>
                        </div>
                        <button type="submit" name="start_exam" class="btn btn-primary btn-lg w-100">
                            <i class="fas fa-play"></i> Start Exam
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
    include '../templates/footer.php';
    exit;
}

// --------------------
// Load in-progress session
// --------------------
$session_id = isset($_GET['session']) ? intval($_GET['session']) : 0;
if ($session_id <= 0) {
    set_message('danger', 'Invalid session.');
    redirect('./exams.php');
    exit;
}

$stmt_ses = mysqli_prepare($conn, "SELECT * FROM student_exam_sessions WHERE id = ? AND student_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt_ses, "ii", $session_id, $student_id);
mysqli_stmt_execute($stmt_ses);
$res_ses = mysqli_stmt_get_result($stmt_ses);
$session_data = $res_ses ? mysqli_fetch_assoc($res_ses) : null;

if (!$session_data || $session_data['status'] !== 'in_progress') {
    set_message('danger', 'Invalid or inactive session.');
    redirect('./exams.php');
    exit;
}

// --------------------
// Load sections (into array)
// --------------------
$stmt_sections = mysqli_prepare($conn, "SELECT * FROM exam_sections WHERE exam_id = ? ORDER BY section_order");
mysqli_stmt_bind_param($stmt_sections, "i", $exam_id);
mysqli_stmt_execute($stmt_sections);
$res_sections = mysqli_stmt_get_result($stmt_sections);
$sections = $res_sections ? mysqli_fetch_all($res_sections, MYSQLI_ASSOC) : [];

if (empty($sections)) {
    set_message('danger', 'No sections found for this exam.');
    redirect('./exams.php');
    exit;
}

// Determine current section
$current_section_id = isset($_GET['section']) ? intval($_GET['section']) : 0;
if ($current_section_id <= 0) {
    $current_section_id = intval($sections[0]['id']);
}

// find current section data
$current_section = null;
foreach ($sections as $s) {
    if (intval($s['id']) === $current_section_id) {
        $current_section = $s;
        break;
    }
}
if (!$current_section) {
    $current_section = $sections[0];
    $current_section_id = intval($current_section['id']);
}

// Find next section
$next_section = null;
$current_section_index = array_search($current_section_id, array_column($sections, 'id'));
if ($current_section_index !== false && isset($sections[$current_section_index + 1])) {
    $next_section = $sections[$current_section_index + 1];
}

// Check if we should show final completion screen (only for last section)
$show_final_completion = false;
if (isset($_GET['show_completion']) && $_GET['show_completion'] === 'true' && !$next_section) {
    $show_final_completion = true;
}

// Check if current section is completed
$is_section_completed = is_section_completed($session_id, $current_section_id);

// If section is completed and there's a next section, auto-redirect to next section
if ($is_section_completed && $next_section && !$show_final_completion) {
    redirect($_SERVER['PHP_SELF'] . '?exam_id=' . $exam_id . '&session=' . $session_id . '&section=' . $next_section['id']);
    exit;
}

// CORRECTED TIME PERSISTENCE LOGIC - FIX FOR THE 255:00 ISSUE
$section_time_remaining = 0;
$is_resumed_exam = false;

// Get current section duration in seconds
$section_duration_seconds = intval($current_section['duration_minutes']) * 60;

// Check if we have stored time from database (primary method)
$stmt_time = mysqli_prepare($conn, "SELECT section_time_remaining, started_at, current_section_id FROM student_exam_sessions WHERE id = ?");
mysqli_stmt_bind_param($stmt_time, "i", $session_id);
mysqli_stmt_execute($stmt_time);
$res_time = mysqli_stmt_get_result($stmt_time);
$time_data = $res_time ? mysqli_fetch_assoc($res_time) : null;

// Determine if this is the same section or a new section
$previous_section_id = isset($time_data['current_section_id']) ? intval($time_data['current_section_id']) : 0;
$is_same_section = ($previous_section_id === $current_section_id);

if ($time_data) {
    // Always update current section in database
    $stmt_update_section = mysqli_prepare($conn, "UPDATE student_exam_sessions SET current_section_id = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt_update_section, "ii", $current_section_id, $session_id);
    mysqli_stmt_execute($stmt_update_section);

    if ($is_same_section && !empty($time_data['section_time_remaining'])) {
        // Same section: Use stored remaining time but validate it
        $stored_value = intval($time_data['section_time_remaining']);

        // Cap at section duration (this fixes the 255:00 issue)
        if ($stored_value > $section_duration_seconds) {
            // If stored value is too large, use section duration
            $section_time_remaining = $section_duration_seconds;
        } else {
            $section_time_remaining = max(0, $stored_value);
        }

        $is_resumed_exam = true;

        // Update the started_at time to NOW for this session
        $stmt_update_start = mysqli_prepare($conn, "UPDATE student_exam_sessions SET started_at = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($stmt_update_start, "i", $session_id);
        mysqli_stmt_execute($stmt_update_start);

    } else {
        // Different section or no stored time: Start fresh for this section
        $section_time_remaining = $section_duration_seconds;
        $is_resumed_exam = false;

        // Calculate elapsed time if we want to track overall exam time
        if (!empty($time_data['started_at'])) {
            $started_at = strtotime($time_data['started_at']);
            $current_time = time();
            $elapsed_time = $current_time - $started_at;

            // If elapsed time is very long, it might be a resumed exam
            if ($elapsed_time > 60) { // More than 1 minute
                $is_resumed_exam = true;
            }
        }

        // Store the initial time for this section
        $stmt_update = mysqli_prepare($conn, "UPDATE student_exam_sessions SET section_time_remaining = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt_update, "ii", $section_time_remaining, $session_id);
        mysqli_stmt_execute($stmt_update);
    }
} else {
    // Should not happen, but as fallback
    $section_time_remaining = $section_duration_seconds;

    // Store initial time in database
    $stmt_update = mysqli_prepare($conn, "UPDATE student_exam_sessions SET section_time_remaining = ?, current_section_id = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt_update, "iii", $section_time_remaining, $current_section_id, $session_id);
    mysqli_stmt_execute($stmt_update);
}

// Final safety check: Ensure time never exceeds section duration
$section_time_remaining = min($section_duration_seconds, max(0, $section_time_remaining));

// Calculate comprehensive results for all sections (for final completion screen)
$total_mcq_score = 0;
$total_mcq_marks = 0;
$total_correct_answers = 0;
$total_mcq_questions = 0;
$section_results = [];

if ($show_final_completion) {
    foreach ($sections as $section) {
        $section_result = calculate_section_results($session_id, $section['id']);
        if ($section_result) {
            $section_results[$section['id']] = $section_result;

            if ($section['section_type'] === 'mcq') {
                $total_mcq_score += $section_result['score'] ?? 0;
                $total_mcq_marks += $section_result['total_marks'] ?? 0;
                $total_correct_answers += $section_result['correct_answers'] ?? 0;
                $total_mcq_questions += $section_result['total_questions'] ?? 0;
            }
        }
    }

    $overall_percentage = $total_mcq_marks > 0 ? round(($total_mcq_score / $total_mcq_marks) * 100, 2) : 0;
    $is_passed = $total_mcq_score >= ($exam_data['passing_marks'] ?? 0);
}

// --------------------
// Load questions for current section (only if not showing final completion)
// --------------------
$questions = [];
$total_questions = 0;
$current_question_num = 1;
$current_question = null;
$options = [];
$saved = null;

if (!$show_final_completion) {
    $stmt_qs = mysqli_prepare($conn, "SELECT * FROM questions WHERE section_id = ? ORDER BY question_order");
    mysqli_stmt_bind_param($stmt_qs, "i", $current_section_id);
    mysqli_stmt_execute($stmt_qs);
    $res_qs = mysqli_stmt_get_result($stmt_qs);
    $questions = $res_qs ? mysqli_fetch_all($res_qs, MYSQLI_ASSOC) : [];

    $total_questions = count($questions);
    if ($total_questions === 0) {
        set_message('danger', 'No questions found in the selected section.');
        redirect('./exams.php');
        exit;
    }

    // Current question number (1-based)
    $current_question_num = isset($_GET['q']) ? intval($_GET['q']) : 1;
    if ($current_question_num < 1 || $current_question_num > $total_questions) {
        $current_question_num = 1;
    }

    // current question
    $current_question = $questions[$current_question_num - 1];

    // if MCQ, fetch options
    if ($current_question['question_type'] === 'mcq') {
        $stmt_opts = mysqli_prepare($conn, "SELECT id, option_text, option_order FROM mcq_options WHERE question_id = ? ORDER BY option_order");
        mysqli_stmt_bind_param($stmt_opts, "i", $current_question['id']);
        mysqli_stmt_execute($stmt_opts);
        $res_opts = mysqli_stmt_get_result($stmt_opts);
        $options = $res_opts ? mysqli_fetch_all($res_opts, MYSQLI_ASSOC) : [];
    }

    // saved answer (if any)
    $stmt_saved = mysqli_prepare($conn, "SELECT * FROM student_answers WHERE session_id = ? AND question_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt_saved, "ii", $session_id, $current_question['id']);
    mysqli_stmt_execute($stmt_saved);
    $res_saved = mysqli_stmt_get_result($stmt_saved);
    $saved = $res_saved ? mysqli_fetch_assoc($res_saved) : null;
}

// csrf token
$csrf_token = generate_csrf_token();

// Render page
include '../templates/header.php';
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Exam - <?php echo htmlspecialchars($exam_data['title']); ?></title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/fontawesome/all.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .option-item {
            cursor: pointer;
            transition: all 0.15s ease;
            padding: .5rem;
            border-radius: .25rem;
            margin-bottom: .25rem;
        }

        .option-item.selected {
            background: #0d6efd;
            color: #fff;
        }

        .exam-interface {
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }

        .answer-input {
            width: 100%;
            min-height: 150px;
        }

        .section-completion-card {
            max-width: 500px;
            margin: 20px auto;
        }

        .exam-disabled {
            opacity: 0.6;
            pointer-events: none;
        }

        .completion-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }

        /* New Header Styles */
        .college-header {
            background: linear-gradient(135deg, #044209 0%, #035216 100%);
            color: white;
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .college-logo {
            height: 60px;
            width: 60px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid white;
        }

        .info-row {
            margin: 10px 0;
            font-size: 0.95rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            padding-bottom: 8px;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .current-time {
            font-size: 1.3rem;
            font-weight: bold;
            background: rgba(255, 255, 255, 0.2);
            padding: 8px 15px;
            border-radius: 5px;
            display: inline-block;
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }

        /* Final Completion Styles */
        .final-completion-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin: 20px auto;
            max-width: 800px;
        }

        .final-completion-header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 30px 20px;
            text-align: center;
            border-radius: 15px 15px 0 0;
        }

        .stats-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
            margin-bottom: 20px;
        }

        .stats-card:hover {
            transform: translateY(-5px);
        }

        .mcq-summary {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white;
        }

        .score-badge-large {
            font-size: 2rem;
            padding: 15px 25px;
            border-radius: 50px;
        }

        .section-breakdown-item {
            border-left: 4px solid #007bff;
            transition: all 0.3s ease;
        }

        .section-breakdown-item:hover {
            border-left-color: #0056b3;
            background-color: #f8f9fa;
        }

        .final-submit-section {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            text-align: center;
            margin-top: 30px;
        }

        .pulse {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.05);
            }

            100% {
                transform: scale(1);
            }
        }

        .exam-header-info {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            border-left: 4px solid #007bff;
        }

        .question-counter {
            font-size: 1.1rem;
            font-weight: bold;
            color: #495057;
        }

        /* Security overlay styles */
        .security-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            color: white;
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 10000;
            text-align: center;
        }

        .security-message {
            background: #dc3545;
            padding: 30px;
            border-radius: 10px;
            max-width: 500px;
        }

        /* Fullscreen modal styles */
        .fullscreen-modal .modal-content {
            border: none;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }

        .fullscreen-modal .modal-header {
            border-bottom: 2px solid #e9ecef;
            padding: 25px 30px;
        }

        .fullscreen-modal .modal-body {
            padding: 20px;
        }

        .fullscreen-modal .modal-footer {
            border-top: 2px solid #e9ecef;
            padding: 25px 30px;
        }

        .fullscreen-btn {
            padding: 15px 30px;
            font-size: 1.2rem;
            font-weight: bold;
        }
    </style>
</head>

<body class="exam-mode exam-interface">

    <!-- Security Violation Overlay -->
    <div class="security-overlay" id="securityOverlay" style="display: none;">
        <div class="security-message">
            <i class="fas fa-ban fa-4x mb-3"></i>
            <h2>Security Violation Detected</h2>
            <p class="lead">You have violated exam security rules.</p>
            <p>Your exam has been terminated. You will be redirected shortly.</p>
            <div class="spinner-border mt-3" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>
    </div>

    <!-- Fullscreen Modal - Shows for EVERY section -->
    <div class="modal fade fullscreen-modal" id="fullscreenModal" tabindex="-1" data-bs-backdrop="static"
        data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <?php
                        $isFirstSection = $current_section_id == $sections[0]['id'];
                        echo $isFirstSection ? 'Start Exam' : 'Continue to Next Section';
                        ?>
                    </h5>
                    <?php if (!empty($exam_data['mega_exam_title'])): ?>
                        <span class="badge bg-light text-primary">
                            <i class="fas fa-layer-group"></i>
                            <?= htmlspecialchars($exam_data['mega_exam_title']) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="modal-body text-center">
                    <?php if ($isFirstSection): ?>
                        <h4 class="mb-3"><?php echo htmlspecialchars($exam_data['title']); ?></h4>
                        <p class="lead mb-2">You are about to start your exam. Fullscreen mode is required.</p>
                    <?php else: ?>
                        <i class="fas fa-arrow-right fa-4x text-success mb-3"></i>
                        <h4 class="mb-3">Next Section: <?php echo htmlspecialchars($current_section['title']); ?></h4>
                        <p class="lead mb-4">Continue to next section.</p>
                    <?php endif; ?>

                    <?php if ($is_resumed_exam): ?>
                        <div class="alert alert-info text-start">
                            <h6 class="alert-heading"><i class="fas fa-info-circle me-2"></i>Resumed Exam</h6>
                            <p class="mb-0">Time Remaining:
                                <strong><?php echo format_time_php($section_time_remaining); ?></strong>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary btn-lg fullscreen-btn w-100" id="fullscreenButton">
                        <?php echo $isFirstSection ? 'Start Exam in Fullscreen' : 'Start Section in Fullscreen'; ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay" style="display: none;">
        <div class="text-center">
            <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;"></div>
            <p class="mt-3">Moving to next section...</p>
        </div>
    </div>

    <div class="container-fluid p-3">
        <?php if ($show_final_completion): ?>
            <!-- FINAL COMPLETION VIEW -->
            <div class="final-completion-card">
                <div class="final-completion-header">
                    <h3 class="mb-0">Successfully Completed Paper</h3>
                </div>

                <div class="card-body p-5">
                    <?php if ($total_mcq_questions > 0): ?>
                        <!-- MCQ Summary Section -->
                        <div class="mcq-summary-section mb-5">
                            <h3 class="text-center mb-4 text-primary">
                                <i class="fas fa-list-alt me-2"></i> MCQ Sections Summary
                            </h3>

                            <!-- Main MCQ Score Card -->
                            <div class="card mcq-summary text-white mb-4">
                                <div class="card-body text-center p-4">
                                    <div class="row align-items-center">
                                        <div class="col-md-6">
                                            <h2 class="fw-bold mb-2">
                                                <?php echo $total_mcq_score; ?>/<?php echo $total_mcq_marks; ?>
                                            </h2>
                                            <h5 class="mb-3">Total MCQ Marks Obtained</h5>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="row">
                                                <div class="col-6 text-center">
                                                    <h3 class="fw-bold"><?php echo $total_correct_answers; ?></h3>
                                                    <p class="mb-0">Correct Answers</p>
                                                </div>
                                                <div class="col-6 text-center">
                                                    <h3 class="fw-bold"><?php echo $total_mcq_questions; ?></h3>
                                                    <p class="mb-0">Total Questions</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Pass/Fail Status -->
                            <?php if (!empty($exam_data['passing_marks'])): ?>
                                <div class="row mb-4">
                                    <div class="col-12">
                                        <div
                                            class="card <?php echo $is_passed ? 'bg-success' : 'bg-danger'; ?> text-white text-center pulse">
                                            <div class="card-body py-4">
                                                <h2 class="mb-3">
                                                    <?php if ($is_passed): ?>
                                                        <i class="fas fa-check-circle me-2"></i> PASSED - Congratulations!
                                                    <?php else: ?>
                                                        <i class="fas fa-times-circle me-2"></i> FAILED - Better Luck Next Time!
                                                    <?php endif; ?>
                                                </h2>
                                                <h4 class="mb-0">
                                                    Passing Marks: <?php echo $exam_data['passing_marks']; ?> |
                                                    Your Score: <?php echo $total_mcq_score; ?> |
                                                    Margin:
                                                    <?php
                                                    $margin = $total_mcq_score - $exam_data['passing_marks'];
                                                    if ($margin >= 0) {
                                                        echo "<span class='badge bg-light text-success'>+" . $margin . " marks above</span>";
                                                    } else {
                                                        echo "<span class='badge bg-light text-danger'>" . $margin . " marks below</span>";
                                                    }
                                                    ?>
                                                </h4>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <!-- Final Submission Button -->
                    <div class="final-submit-section">
                        <form method="POST" class="d-inline-block">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
                            <input type="hidden" name="final_submit_exam" value="1">
                            <button type="submit" class="btn btn-light btn-lg px-5 py-3">
                                <i class="fas fa-paper-plane me-2"></i> FINAL SUBMIT PAPER
                            </button>
                        </form>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- REGULAR EXAM INTERFACE -->

            <!-- New Improved Header -->
            <div class="college-header">
                <div class="row align-items-center mb-3">
                    <!-- Logo and College Name -->
                    <div class="col-md-6">
                        <div class="d-flex align-items-center">
                            <?php if (file_exists($college_info['logo'])): ?>
                                <img src="<?php echo $college_info['logo']; ?>" alt="College Logo" class="college-logo me-3">
                            <?php endif; ?>
                            <div>
                                <h4 class="mb-0"><?php echo htmlspecialchars($college_info['name']); ?></h4>
                                <p class="mb-0 text-light">Online Examination System</p>
                            </div>
                        </div>
                    </div>

                    <!-- Current Time -->
                    <div class="col-md-6 text-end">
                        <div class="current-time">
                            <i class="fas fa-clock me-2"></i>
                            <span id="sectionTimer"><?php echo format_time_php($section_time_remaining); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Department, Paper, Semester Row -->
                <div class="row info-row">
                    <div class="col-md-4">
                        <strong>Department:</strong> <?php echo htmlspecialchars($exam_data['department_name'] ?? 'N/A'); ?>
                    </div>
                    <div class="col-md-4 text-center">
                        <strong>Paper:</strong> <?php echo htmlspecialchars($exam_data['title']); ?>
                    </div>
                    <div class="col-md-4 text-end">
                        <?php if (!empty($exam_data['semester'])): ?>
                            <strong>Semester:</strong> <?php echo intval($exam_data['semester']); ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Student Info Row -->
                <div class="row info-row">
                    <div class="col-md-4">
                        <strong>Student:</strong> <?php echo htmlspecialchars($student_data['full_name']); ?>
                    </div>
                    <div class="col-md-4 text-center">
                        <strong>Roll No:</strong>
                        <?php echo htmlspecialchars($student_data['roll_number'] ?? $student_data['username']); ?>
                    </div>
                    <div class="col-md-4 text-end">
                        <strong>Duration:</strong> <?php echo intval($current_section['duration_minutes']); ?> minutes
                    </div>
                </div>

                <!-- Current Section Info -->
                <div class="row info-row">
                    <div class="col-12 text-center">
                        <div class="badge bg-warning text-dark fs-6">
                            <i class="fas fa-layer-group me-1"></i>
                            Current Section: <?php echo htmlspecialchars($current_section['title']); ?>
                            (<?php echo htmlspecialchars($current_section['section_type']); ?>)
                        </div>
                    </div>
                </div>
            </div>

            <!-- Question Navigation Info -->
            <div class="exam-header-info">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <div class="question-counter">
                            <i class="fas fa-question-circle me-2"></i>
                            Question: <?php echo $current_question_num; ?>/<?php echo $total_questions; ?>
                        </div>
                    </div>
                    <div class="col-md-6 text-end">
                        <div class="text-muted">
                            <i class="fas fa-book me-2"></i>
                            Section: <?php echo htmlspecialchars($current_section['title']); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Question Content -->
            <div class="row">
                <div class="col-12">
                    <div class="card p-3">
                        <div class="mb-2"><strong id="questionNumber">Question <?php echo $current_question_num; ?> of
                                <?php echo $total_questions; ?></strong></div>
                        <div class="mb-3" id="questionText">
                            <?php echo nl2br(htmlspecialchars($current_question['question_text'])); ?>
                        </div>

                        <form id="answerForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="session_id" id="sessionId" value="<?php echo $session_id; ?>">
                            <input type="hidden" name="question_id" id="questionId"
                                value="<?php echo intval($current_question['id']); ?>">
                            <input type="hidden" name="action" value="save_answer">
                            <div id="questionContent">
                                <?php if ($current_question['question_type'] === 'mcq'): ?>
                                    <?php foreach ($options as $opt): ?>
                                        <?php $isSelected = ($saved && isset($saved['selected_option_id']) && intval($saved['selected_option_id']) === intval($opt['id'])); ?>
                                        <div class="option-item <?php echo $isSelected ? 'selected' : ''; ?>"
                                            data-opt-id="<?php echo intval($opt['id']); ?>">
                                            <input type="radio" name="selected_option" value="<?php echo intval($opt['id']); ?>"
                                                style="display:none;" <?php echo $isSelected ? 'checked' : ''; ?>>
                                            <?php echo htmlspecialchars($opt['option_text']); ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <textarea name="answer_text" id="answerText" class="answer-input form-control"
                                        placeholder="Type your answer here..."><?php echo htmlspecialchars($saved['answer_text'] ?? ''); ?></textarea>
                                <?php endif; ?>
                            </div>
                        </form>

                        <div class="d-flex justify-content-between mt-3">
                            <div>
                                <button id="prevBtn" class="btn btn-secondary" <?php echo $current_question_num <= 1 ? 'disabled' : ''; ?>><i class="fas fa-arrow-left"></i> Previous</button>
                            </div>
                            <div>
                                <button id="nextBtn" class="btn btn-primary" <?php echo $current_question_num >= $total_questions ? 'style="display:none;"' : ''; ?>>Next <i
                                        class="fas fa-arrow-right"></i></button>
                                <button id="submitSectionBtn" class="btn btn-warning" <?php echo $current_question_num < $total_questions ? 'style="display:none;"' : ''; ?> onclick="submitCurrentSection()"><i
                                        class="fas fa-check"></i> Submit Section</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Hidden section submit form -->
    <form id="sectionSubmitForm" method="POST" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
        <input type="hidden" name="current_section_id" value="<?php echo $current_section_id; ?>">
        <input type="hidden" name="submit_section" value="1">
    </form>

    <!-- Hidden auto-submit form for security violations -->
    <form id="autoSubmitForm" method="POST" action="" style="display:none;">
        <input type="hidden" name="security_violation" value="1">
        <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
        <input type="hidden" name="section_time_remaining" id="sectionTimeRemaining" value="">
        <input type="hidden" name="store_section_time" value="1">
    </form>

    <script src="../assets/js/bootstrap.bundle.min.js"></script>

    <script>
        // === SECURITY CONTROL FLAGS ===
        const currentQuestionNumInit = <?php echo json_encode($current_question_num); ?>;
        const totalQuestions = <?php echo json_encode($total_questions); ?>;
        let currentQuestionNum = currentQuestionNumInit;
        const currentSectionId = <?php echo json_encode($current_section_id); ?>;
        const sessionId = <?php echo json_encode($session_id); ?>;
        const examId = <?php echo json_encode($exam_id); ?>;
        const isFirstSection = <?php echo ($current_section_id == $sections[0]['id']) ? 'true' : 'false'; ?>;
        const sectionDurationSeconds = <?php echo $section_duration_seconds; ?>;

        // === SECURITY STATE ===
        let isFullscreen = false;
        let examStarted = false;
        let securityViolated = false;
        let isSectionTransition = false;
        let enteringFullscreen = false;
        let initialSecuritySetup = true; // Grace period for initial setup
        let setupComplete = false;

        // === TIME MANAGEMENT ===
        let sectionTimeRemaining = <?php echo min($section_duration_seconds, max(0, $section_time_remaining)); ?>;
        let sectionTimerInterval;
        let lastSavedTime = sectionTimeRemaining;

        // === IMMEDIATE TERMINATION SETTINGS ===
        let resizeAttempts = 0;
        let blurCount = 0;
        const maxResizeAttempts = 1;
        const maxBlurCount = 1;

        // === UTILITY FUNCTIONS ===
        function formatTime(seconds) {
            seconds = Math.max(0, parseInt(seconds, 10));
            const minutes = Math.floor(seconds / 60);
            const secs = seconds % 60;
            return `${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
        }

        function updateTimeInDatabase(timeRemaining) {
            if (timeRemaining > sectionDurationSeconds || timeRemaining < 0) {
                console.error('Invalid time value attempted:', timeRemaining);
                return;
            }

            const fd = new FormData();
            fd.append('action', 'update_section_time');
            fd.append('session_id', sessionId);
            fd.append('section_time_remaining', timeRemaining);

            fetch('', {
                method: 'POST',
                body: fd,
                keepalive: true
            }).catch(err => {
                console.error('Failed to update time:', err);
            });
        }

        // === IMMEDIATE SECURITY VIOLATION TERMINATION ===
        function detectSecurityViolation(violationType) {
            // Don't trigger during initial setup or if already violated
            if (initialSecuritySetup || securityViolated) {
                console.log(`Ignoring violation during setup: ${violationType}`);
                return;
            }

            console.log(`SECURITY VIOLATION: ${violationType}`);
            securityViolated = true;

            // Save final time to database
            updateTimeInDatabase(sectionTimeRemaining);
            document.getElementById('sectionTimeRemaining').value = sectionTimeRemaining;

            // Stop timer
            if (sectionTimerInterval) {
                clearInterval(sectionTimerInterval);
            }

            // Show violation overlay
            document.getElementById('securityOverlay').style.display = 'flex';

            // Submit the violation form
            setTimeout(() => {
                document.getElementById('autoSubmitForm').submit();
            }, 100);
        }

        // === FULLSCREEN HANDLING ===
        function enterFullscreen() {
            enteringFullscreen = true;
            console.log('Starting fullscreen request...');

            const el = document.documentElement;
            let promise;

            if (el.requestFullscreen) {
                promise = el.requestFullscreen();
            } else if (el.webkitRequestFullscreen) {
                promise = el.webkitRequestFullscreen();
            } else if (el.msRequestFullscreen) {
                promise = el.msRequestFullscreen();
            } else if (el.mozRequestFullScreen) {
                promise = el.mozRequestFullScreen();
            } else {
                enteringFullscreen = false;
                throw new Error('Fullscreen API not supported');
            }

            return promise;
        }

        // === FULLSCREEN MONITORING ===
        function handleFullscreenChange() {
            const fullscreenElement = document.fullscreenElement ||
                document.webkitFullscreenElement ||
                document.mozFullScreenElement ||
                document.msFullscreenElement;
            isFullscreen = !!fullscreenElement;

            console.log('Fullscreen state changed:', isFullscreen, 'enteringFullscreen:', enteringFullscreen);

            if (isFullscreen && enteringFullscreen) {
                // Successfully entered fullscreen
                enteringFullscreen = false;
                console.log('Successfully entered fullscreen mode');
            }

            // Check if leaves fullscreen after exam started
            if (!isFullscreen && examStarted && !securityViolated && !enteringFullscreen && setupComplete) {
                console.log('Fullscreen exit detected - TERMINATING EXAM');
                detectSecurityViolation('Left fullscreen mode');
            }
        }

        // === SETUP STRICT SECURITY LISTENERS ===
        function setupSecurityListeners() {
            console.log('Setting up security listeners...');

            // Fullscreen monitoring
            document.addEventListener('fullscreenchange', handleFullscreenChange);
            document.addEventListener('webkitfullscreenchange', handleFullscreenChange);
            document.addEventListener('mozfullscreenchange', handleFullscreenChange);
            document.addEventListener('MSFullscreenChange', handleFullscreenChange);

            // Window resize
            window.addEventListener('resize', function () {
                if (!examStarted || securityViolated || initialSecuritySetup) return;
                console.log('Window resize detected - TERMINATING');
                resizeAttempts++;
                if (resizeAttempts >= maxResizeAttempts) {
                    detectSecurityViolation('Window resizing');
                }
            });

            // Tab/window blur
            window.addEventListener('blur', function () {
                if (!examStarted || securityViolated || initialSecuritySetup) return;
                console.log('Window blur/tab switch detected - TERMINATING');
                blurCount++;
                if (blurCount >= maxBlurCount) {
                    detectSecurityViolation('Tab switching/window blur');
                }
            });

            // Prevent page reload/close
            window.addEventListener('beforeunload', function (e) {
                if (examStarted && !securityViolated && !isSectionTransition && !initialSecuritySetup) {
                    updateTimeInDatabase(sectionTimeRemaining);
                    detectSecurityViolation('Page reload/close attempt');
                    e.preventDefault();
                    e.returnValue = '';
                }
            });

            // Context menu restriction
            document.addEventListener('contextmenu', e => {
                const isExamContent = e.target.closest('.option-item') ||
                    e.target.closest('textarea') ||
                    e.target.closest('input') ||
                    e.target.closest('button') ||
                    e.target.closest('.card') ||
                    e.target.closest('.exam-interface');

                if (!isExamContent && examStarted && !securityViolated && !initialSecuritySetup) {
                    e.preventDefault();
                    detectSecurityViolation('Right-click context menu');
                }
            });

            // Copy/paste restriction
            document.addEventListener('copy', e => {
                if (examStarted && !securityViolated && !initialSecuritySetup) {
                    e.preventDefault();
                    detectSecurityViolation('Copy attempt');
                }
            });

            document.addEventListener('cut', e => {
                if (examStarted && !securityViolated && !initialSecuritySetup) {
                    e.preventDefault();
                    detectSecurityViolation('Cut attempt');
                }
            });

            document.addEventListener('paste', e => {
                if (examStarted && !securityViolated && !initialSecuritySetup) {
                    e.preventDefault();
                    detectSecurityViolation('Paste attempt');
                }
            });

            // Keyboard shortcuts blocking
            document.addEventListener('keydown', function (e) {
                if (!examStarted || securityViolated || initialSecuritySetup) return;

                const isTypingElement = e.target.tagName === 'TEXTAREA' ||
                    e.target.tagName === 'INPUT';

                if (isTypingElement) return;

                // Prevent reload shortcuts
                if (e.key === 'F5' || (e.ctrlKey && e.key === 'r') || (e.ctrlKey && e.shiftKey && e.key === 'R')) {
                    e.preventDefault();
                    detectSecurityViolation('Page reload attempt');
                }

                // Prevent F11
                if (e.key === 'F11') {
                    e.preventDefault();
                }

                // Prevent developer tools
                if (e.ctrlKey && e.shiftKey && (e.key === 'I' || e.key === 'J' || e.key === 'C')) {
                    e.preventDefault();
                    detectSecurityViolation('Developer tools attempt');
                }

                // Prevent print
                if (e.ctrlKey && e.key === 'p') {
                    e.preventDefault();
                    detectSecurityViolation('Print attempt');
                }

                // Prevent screenshot
                if (e.key === 'PrintScreen') {
                    e.preventDefault();
                    detectSecurityViolation('Screenshot attempt');
                }
            });

            // Additional security
            document.addEventListener('dragstart', e => {
                if (examStarted && !securityViolated && !initialSecuritySetup) {
                    e.preventDefault();
                    detectSecurityViolation('Drag and drop attempt');
                }
            });

            document.addEventListener('drop', e => {
                if (examStarted && !securityViolated && !initialSecuritySetup) {
                    e.preventDefault();
                    detectSecurityViolation('Drop attempt');
                }
            });

            // End grace period after 2 seconds
            setTimeout(() => {
                initialSecuritySetup = false;
                setupComplete = true;
                console.log('Security setup complete - strict monitoring active');
            }, 2000);

            console.log('Security listeners set up with grace period');
        }

        // === TIMER FUNCTIONS ===
        function startExamTimer() {
            const sectionEl = document.getElementById('sectionTimer');
            if (sectionEl) {
                sectionEl.textContent = formatTime(sectionTimeRemaining);
                sectionTimerInterval = setInterval(() => {
                    sectionTimeRemaining--;
                    sectionEl.textContent = formatTime(sectionTimeRemaining);

                    // Save time every 30 seconds
                    if (sectionTimeRemaining % 30 === 0 && sectionTimeRemaining !== lastSavedTime) {
                        if (sectionTimeRemaining <= sectionDurationSeconds && sectionTimeRemaining >= 0) {
                            updateTimeInDatabase(sectionTimeRemaining);
                            lastSavedTime = sectionTimeRemaining;
                        } else {
                            console.error('Invalid time value:', sectionTimeRemaining);
                            sectionTimeRemaining = Math.max(0, Math.min(sectionDurationSeconds, sectionTimeRemaining));
                        }
                    }

                    if (sectionTimeRemaining <= 0) {
                        clearInterval(sectionTimerInterval);
                        updateTimeInDatabase(0);
                        submitCurrentSection();
                    }
                }, 1000);
            }
        }

        // === START EXAM WITH STRICT SECURITY ===
        function startExam() {
            console.log('Starting exam with improved security...');
            enteringFullscreen = true;
            initialSecuritySetup = true;

            enterFullscreen().then(() => {
                console.log('Fullscreen entered successfully');
                
                // Give fullscreen time to stabilize
                setTimeout(() => {
                    examStarted = true;
                    enteringFullscreen = false;
                    
                    // Setup security listeners
                    setupSecurityListeners();
                    
                    // Hide modal and enable interface
                    const fullscreenModal = bootstrap.Modal.getInstance(document.getElementById('fullscreenModal'));
                    if (fullscreenModal) {
                        fullscreenModal.hide();
                    }
                    
                    document.querySelector('.exam-interface').classList.remove('exam-disabled');
                    
                    // Start timer
                    startExamTimer();
                    
                    console.log('Exam started successfully');
                    
                }, 500);
                
            }).catch((error) => {
                console.error('Fullscreen failed:', error);
                enteringFullscreen = false;
                initialSecuritySetup = false;
                
                // If fullscreen fails, still start exam but with warning
                examStarted = true;
                alert('WARNING: Fullscreen could not be enabled. Any security violation will terminate your exam immediately.');
                
                // Setup security listeners anyway
                setupSecurityListeners();
                
                // Hide modal
                const fullscreenModal = bootstrap.Modal.getInstance(document.getElementById('fullscreenModal'));
                if (fullscreenModal) {
                    fullscreenModal.hide();
                }
                
                document.querySelector('.exam-interface').classList.remove('exam-disabled');
                startExamTimer();
            });
        }

        // === SAVE ANSWER ===
        function saveAnswer() {
            const form = document.getElementById('answerForm');
            const fd = new FormData(form);
            fd.set('action', 'save_answer');

            return fetch('', {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            })
                .then(r => r.json().catch(() => ({ success: false })))
                .then(data => {
                    if (data && data.success) {
                        return true;
                    } else {
                        console.error('Save error:', data?.message);
                        return false;
                    }
                })
                .catch(err => {
                    console.error('Save error', err);
                    return false;
                });
        }

        // === SUBMIT SECTION ===
        function submitCurrentSection() {
            console.log('Submitting section...');
            isSectionTransition = true;

            updateTimeInDatabase(sectionTimeRemaining);
            document.getElementById('loadingOverlay').style.display = 'flex';

            saveAnswer().then(() => {
                setTimeout(() => {
                    document.getElementById('sectionSubmitForm').submit();
                }, 500);
            });
        }

        // === NAVIGATION BETWEEN QUESTIONS ===
        function navigateToQuestion(questionNum) {
            if (questionNum < 1 || questionNum > totalQuestions) return;

            saveAnswer().then(() => {
                const fd = new FormData();
                fd.append('action', 'get_question');
                fd.append('session_id', sessionId);
                fd.append('section_id', currentSectionId);
                fd.append('question_num', questionNum);

                const qc = document.getElementById('questionContent');
                qc.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';

                fetch('', { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (!data || !data.success) {
                            alert(data.message || 'Error loading question');
                            location.reload();
                            return;
                        }

                        currentQuestionNum = questionNum;
                        document.getElementById('questionNumber').textContent = `Question ${data.question_num} of ${totalQuestions}`;
                        document.querySelector('.question-counter').innerHTML = `<i class="fas fa-question-circle me-2"></i>Question: ${data.question_num}/${totalQuestions}`;
                        document.getElementById('questionText').innerHTML = (data.question.question_text || '').replace(/\n/g, '<br>');
                        document.getElementById('questionId').value = data.question.id;

                        let html = '';
                        if (data.question.question_type === 'mcq') {
                            data.options.forEach(opt => {
                                const selected = data.saved_answer && data.saved_answer.selected_option_id == opt.id;
                                html += `<div class="option-item ${selected ? 'selected' : ''}" data-opt-id="${opt.id}"><input type="radio" name="selected_option" value="${opt.id}" style="display:none;" ${selected ? 'checked' : ''}>${opt.option_text}</div>`;
                            });
                        } else {
                            const savedText = data.saved_answer ? (data.saved_answer.answer_text || '') : '';
                            html = `<textarea name="answer_text" id="answerText" class="answer-input form-control" placeholder="Type your answer here...">${savedText}</textarea>`;
                        }

                        qc.innerHTML = html;
                        bindQuestionEvents();
                        updateNavigationButtons();
                    })
                    .catch(err => {
                        console.error(err);
                        alert('Error loading question');
                        location.reload();
                    });
            });
        }

        // === UI HELPERS ===
        function bindQuestionEvents() {
            document.querySelectorAll('.option-item').forEach(el => {
                el.addEventListener('click', function (e) {
                    document.querySelectorAll('.option-item').forEach(x => x.classList.remove('selected'));
                    this.classList.add('selected');
                    const radio = this.querySelector('input[type="radio"]');
                    if (radio) radio.checked = true;
                    saveAnswer();
                });
            });

            const ta = document.getElementById('answerText');
            if (ta) {
                let timer = null;
                ta.addEventListener('input', function () {
                    clearTimeout(timer);
                    timer = setTimeout(() => {
                        saveAnswer();
                    }, 1000);
                });
            }
        }

        function updateNavigationButtons() {
            document.getElementById('prevBtn').disabled = currentQuestionNum <= 1;
            if (currentQuestionNum >= totalQuestions) {
                document.getElementById('nextBtn').style.display = 'none';
                document.getElementById('submitSectionBtn').style.display = 'inline-block';
            } else {
                document.getElementById('nextBtn').style.display = 'inline-block';
                document.getElementById('submitSectionBtn').style.display = 'none';
            }
        }

        // === INITIALIZE EXAM ===
        document.addEventListener('DOMContentLoaded', function () {
            console.log('Initializing exam interface...');

            <?php if (!$show_final_completion): ?>
                bindQuestionEvents();
                updateNavigationButtons();

                document.getElementById('prevBtn').addEventListener('click', () => {
                    if (currentQuestionNum > 1) navigateToQuestion(currentQuestionNum - 1);
                });

                document.getElementById('nextBtn').addEventListener('click', () => {
                    if (currentQuestionNum < totalQuestions) navigateToQuestion(currentQuestionNum + 1);
                });

                document.getElementById('submitSectionBtn').addEventListener('click', (e) => {
                    e.preventDefault();
                    submitCurrentSection();
                });

                // Show fullscreen modal
                const fullscreenModal = new bootstrap.Modal(document.getElementById('fullscreenModal'));
                fullscreenModal.show();
                document.querySelector('.exam-interface').classList.add('exam-disabled');

                // Fullscreen button
                document.getElementById('fullscreenButton').addEventListener('click', function (e) {
                    e.preventDefault();
                    this.disabled = true;
                    this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Entering Fullscreen...';
                    startExam();
                });

            <?php endif; ?>
        });

        // Debug function
        window.getExamStatus = function () {
            return {
                examStarted: examStarted,
                securityViolated: securityViolated,
                isFullscreen: isFullscreen,
                sectionTimeRemaining: sectionTimeRemaining,
                initialSecuritySetup: initialSecuritySetup,
                setupComplete: setupComplete,
                violations: {
                    resizeAttempts: resizeAttempts,
                    blurCount: blurCount
                }
            };
        };
    </script>
</body>

</html>
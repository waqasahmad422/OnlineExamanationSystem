<?php
require_once '../app/config.php';
require_once '../app/helpers.php';
require_once '../app/auth.php';
require_once '../app/teacher_handlers.php';

require_role(['teacher']);
date_default_timezone_set('Asia/Karachi');

$teacher_id = intval($_SESSION['user_id']);
$csrf_token = generate_csrf_token();
$page_title = "Evaluate Student Answers";

// Enhanced audit logging function with real-time tracking
function log_evaluation_action($conn, $teacher_id, $action, $session_id = null, $details = '') {
    $stmt = mysqli_prepare($conn, "
        INSERT INTO audit_logs (user_id, action, table_name, record_id, details, ip_address, user_agent) 
        VALUES (?, ?, 'evaluations', ?, ?, ?, ?)
    ");
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    mysqli_stmt_bind_param($stmt, "ississ", $teacher_id, $action, $session_id, $details, $ip_address, $user_agent);
    mysqli_stmt_execute($stmt);
}

// Validate session_id parameter
if (!isset($_GET['session_id']) || empty($_GET['session_id'])) {
    set_message('error', 'Invalid session.');
    redirect('./evaluate.php');
    exit;
}

$session_id = intval($_GET['session_id']);
$edit_mode = isset($_GET['edit']) ? true : false;

// Log access to evaluation page
log_evaluation_action($conn, $teacher_id, 'VIEW_EVALUATION_FORM', $session_id, 
    ($edit_mode ? 'Edit mode' : 'New evaluation') . ' for session ' . $session_id);

/* -----------------------
   Handle form submission with enhanced logging
------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $answer_marks = $_POST['answer_marks'] ?? [];
    $grand_total_possible = floatval($_POST['grand_total_possible'] ?? 0);
    $marks_obtained = 0;

    // Track individual mark changes for detailed logging
    $individual_changes = [];
    $total_questions = count($answer_marks);
    $updated_answers = 0;

    // Process marks for each answer with real-time change tracking
    foreach ($answer_marks as $answer_id => $marks) {
        $answer_id = intval($answer_id);
        $marks = floatval($marks);
        $marks_obtained += $marks;
        
        // Get previous marks and question details for detailed logging
        $prev_marks_query = mysqli_prepare($conn, "
            SELECT sa.marks_obtained, q.question_text, q.marks as max_marks, q.question_type 
            FROM student_answers sa 
            JOIN questions q ON sa.question_id = q.id 
            WHERE sa.id = ?
        ");
        mysqli_stmt_bind_param($prev_marks_query, "i", $answer_id);
        mysqli_stmt_execute($prev_marks_query);
        $prev_marks_result = mysqli_stmt_get_result($prev_marks_query);
        
        $prev_marks = 0;
        $question_text = '';
        $max_marks = 0;
        $question_type = '';
        
        if ($prev_row = mysqli_fetch_assoc($prev_marks_result)) {
            $prev_marks = floatval($prev_row['marks_obtained'] ?? 0);
            $question_text = $prev_row['question_text'] ?? 'Unknown Question';
            $max_marks = floatval($prev_row['max_marks'] ?? 0);
            $question_type = $prev_row['question_type'] ?? 'unknown';
        }
        
        // Log individual mark change immediately when detected
        if (abs($prev_marks - $marks) > 0.01) {
            $updated_answers++;
            
            // Create detailed change description
            $short_question = strlen($question_text) > 40 ? substr($question_text, 0, 40) . '...' : $question_text;
            $change_details = "Question: {$short_question} | Type: " . strtoupper($question_type) . 
                            " | Marks changed: {$prev_marks} → {$marks}/{$max_marks}";
            
            // Log this individual change immediately with timestamp
            log_evaluation_action($conn, $teacher_id, 'MARK_UPDATE', $session_id, $change_details);
            $individual_changes[] = $change_details;
        }
        
        // Update marks in database
        $update_marks = mysqli_prepare($conn, "
            UPDATE student_answers 
            SET marks_obtained = ? 
            WHERE id = ? AND session_id = ?
        ");
        mysqli_stmt_bind_param($update_marks, "dii", $marks, $answer_id, $session_id);
        mysqli_stmt_execute($update_marks);
    }

    // Handle evaluation record
    $eval_q = mysqli_prepare($conn, "SELECT id, marks_obtained as prev_marks FROM evaluations WHERE session_id = ? AND teacher_id = ? LIMIT 1");
    mysqli_stmt_bind_param($eval_q, "ii", $session_id, $teacher_id);
    mysqli_stmt_execute($eval_q);
    $eval_res = mysqli_stmt_get_result($eval_q);

    $is_update = false;
    $prev_total_marks = 0;
    $success = false;

    if (mysqli_num_rows($eval_res) > 0) {
        // Update existing evaluation
        $eval = mysqli_fetch_assoc($eval_res);
        $prev_total_marks = floatval($eval['prev_marks'] ?? 0);
        $is_update = true;
        
        $update = mysqli_prepare($conn, "
            UPDATE evaluations 
            SET total_marks = ?, marks_obtained = ?, evaluated_at = NOW(), status = 'completed' 
            WHERE id = ?
        ");
        mysqli_stmt_bind_param($update, "ddi", $grand_total_possible, $marks_obtained, $eval['id']);
        $success = mysqli_stmt_execute($update);
        
        // Log total marks change if significant
        if (abs($prev_total_marks - $marks_obtained) > 0.1) {
            log_evaluation_action($conn, $teacher_id, 'TOTAL_MARKS_UPDATE', $session_id, 
                "Total marks updated: {$prev_total_marks} → {$marks_obtained}/{$grand_total_possible}");
        }
    } else {
        // Insert new evaluation
        $insert = mysqli_prepare($conn, "
            INSERT INTO evaluations (session_id, teacher_id, total_marks, marks_obtained, evaluated_at, status) 
            VALUES (?, ?, ?, ?, NOW(), 'completed')
        ");
        mysqli_stmt_bind_param($insert, "iidd", $session_id, $teacher_id, $grand_total_possible, $marks_obtained);
        $success = mysqli_stmt_execute($insert);
    }

    if ($success) {
        // Log final evaluation summary
        $action = $is_update ? 'EVALUATION_UPDATED' : 'EVALUATION_CREATED';
        
        $student_name = $session_info['full_name'] ?? 'Unknown Student';
        $roll_number = $session_info['roll_number'] ?? 'Unknown';
        $exam_title = $session_info['exam_title'] ?? 'Unknown Exam';
        
        $summary_details = "Evaluation completed for {$student_name} (Roll: {$roll_number}) - {$exam_title}. " .
                          "{$updated_answers}/{$total_questions} questions updated. " .
                          "Final score: {$marks_obtained}/{$grand_total_possible}";
        
        log_evaluation_action($conn, $teacher_id, $action, $session_id, $summary_details);
        
        set_message('success', 'Evaluation saved successfully.');
    } else {
        $error_msg = mysqli_error($conn);
        log_evaluation_action($conn, $teacher_id, 'EVALUATION_ERROR', $session_id, "Failed to save evaluation: {$error_msg}");
        set_message('error', 'Error saving evaluation. Please try again.');
    }
    
    redirect('./evaluate.php');
    exit;
}

// Fetch session + student + exam details
$session_q = mysqli_prepare($conn, "
    SELECT 
        ses.*, 
        u.full_name, 
        u.roll_number, 
        u.semester, 
        d.name AS department_name, 
        b.name AS batch_name, 
        e.title AS exam_title, 
        e.total_marks AS exam_total_marks,
        e.passing_marks,
        me.title AS mega_exam_title,
        me.mega_exam_code
    FROM student_exam_sessions ses
    JOIN users u ON ses.student_id = u.id
    JOIN exams e ON ses.exam_id = e.id
    JOIN mega_exams me ON e.mega_exam_id = me.id
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN batches b ON u.batch_id = b.id
    WHERE ses.id = ? AND e.teacher_id = ? 
    LIMIT 1
");
mysqli_stmt_bind_param($session_q, "ii", $session_id, $teacher_id);
mysqli_stmt_execute($session_q);
$session_res = mysqli_stmt_get_result($session_q);

if (!$session_res || mysqli_num_rows($session_res) === 0) {
    set_message('error', 'Session not found or access denied.');
    redirect('./evaluate.php');
    exit;
}
$session_info = mysqli_fetch_assoc($session_res);

// Fetch ALL questions for this exam
$all_questions_q = mysqli_prepare($conn, "
    SELECT 
        q.id AS question_id,
        q.question_text,
        LOWER(TRIM(q.question_type)) AS question_type,
        q.marks AS question_marks,
        COALESCE(es.title, 'General') AS section_name,
        sa.id AS answer_id,
        sa.answer_text,
        sa.selected_option_id,
        sa.is_correct,
        sa.marks_obtained,
        sa.saved_at
    FROM questions q
    JOIN exam_sections es ON q.section_id = es.id
    JOIN exams e ON es.exam_id = e.id
    JOIN student_exam_sessions ses ON e.id = ses.exam_id
    LEFT JOIN student_answers sa ON sa.question_id = q.id AND sa.session_id = ses.id
    WHERE ses.id = ? AND e.teacher_id = ?
    ORDER BY COALESCE(es.id, 0), q.question_order, q.id
");
mysqli_stmt_bind_param($all_questions_q, "ii", $session_id, $teacher_id);
mysqli_stmt_execute($all_questions_q);
$all_questions_res = mysqli_stmt_get_result($all_questions_q);

// Fetch existing evaluation data if in edit mode
$existing_eval_marks = [];
if ($edit_mode) {
    $existing_marks_q = mysqli_prepare($conn, "
        SELECT id, marks_obtained 
        FROM student_answers 
        WHERE session_id = ?
    ");
    mysqli_stmt_bind_param($existing_marks_q, "i", $session_id);
    mysqli_stmt_execute($existing_marks_q);
    $existing_marks_res = mysqli_stmt_get_result($existing_marks_q);
    
    while ($row = mysqli_fetch_assoc($existing_marks_res)) {
        $existing_eval_marks[intval($row['id'])] = floatval($row['marks_obtained']);
    }
}

// Group questions by section and calculate totals
$sections = [];
$total_mcq_marks = 0;
$total_descriptive_marks = 0;
$total_mcq_obtained = 0;
$total_descriptive_obtained = 0;
$total_questions = 0;
$mcq_questions = 0;
$descriptive_questions = 0;

while ($question = mysqli_fetch_assoc($all_questions_res)) {
    $section_name = $question['section_name'] ?? 'General';
    
    $question_type_db = strtolower(trim($question['question_type'] ?? ''));
    $detected_type = ($question_type_db === 'mcq') ? 'mcq' : 'descriptive';
    
    if ($detected_type === 'mcq') {
        $mcq_questions++;
        $total_mcq_marks += floatval($question['question_marks'] ?? 0);
        $existing_marks = $existing_eval_marks[intval($question['answer_id'] ?? 0)] ?? $question['marks_obtained'] ?? null;
        $is_correct = intval($question['is_correct'] ?? 0) === 1;
        $qmarks = floatval($question['question_marks'] ?? 0);
        $obtained = $existing_marks ?? ($is_correct ? $qmarks : 0.0);
        $total_mcq_obtained += $obtained;
    } else {
        $descriptive_questions++;
        $total_descriptive_marks += floatval($question['question_marks'] ?? 0);
        $existing_marks = $existing_eval_marks[intval($question['answer_id'] ?? 0)] ?? $question['marks_obtained'] ?? null;
        $total_descriptive_obtained += floatval($existing_marks ?? 0);
    }
    
    $total_questions++;
    $sections[$section_name][] = array_merge($question, ['detected_type' => $detected_type]);
}

// Calculate final totals
$grand_total_possible = $total_mcq_marks + $total_descriptive_marks;
$grand_total_obtained = $total_mcq_obtained + $total_descriptive_obtained;
$percentage = $grand_total_possible > 0 ? ($grand_total_obtained / $grand_total_possible) * 100 : 0;
$is_passed = $grand_total_obtained >= floatval($session_info['passing_marks'] ?? 0);

// MCQ helper functions
function fetch_mcq_options($conn, $qid) {
    $options = [];
    $stmt = mysqli_prepare($conn, "
        SELECT id, option_text, option_order, is_correct 
        FROM mcq_options 
        WHERE question_id = ? 
        ORDER BY option_order ASC, id ASC
    ");
    
    if (!$stmt) return $options;
    
    mysqli_stmt_bind_param($stmt, "i", $qid);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($res)) {
        $options[intval($row['id'])] = [
            'text' => $row['option_text'],
            'is_correct' => intval($row['is_correct']),
            'order' => intval($row['option_order'])
        ];
    }
    
    return $options;
}

function map_options_to_letters($options_by_id) {
    $ids = array_keys($options_by_id);
    usort($ids, function($a, $b) use ($options_by_id) {
        $orderA = $options_by_id[$a]['order'];
        $orderB = $options_by_id[$b]['order'];
        return $orderA != $orderB ? $orderA - $orderB : $a - $b;
    });
    
    $map_id_to_letter = [];
    $map_id_to_text = [];
    foreach ($ids as $index => $oid) {
        $map_id_to_letter[$oid] = chr(65 + $index);
        $map_id_to_text[$oid] = $options_by_id[$oid]['text'];
    }
    return [$map_id_to_letter, $map_id_to_text];
}

include '../templates/header.php';
include '../templates/sidebar_teacher.php';
?>

<style>
.paper-header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
.paper-title { flex: 1; text-align: center; }
.paper-left, .paper-right { width: 220px; }
.option-correct { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 0.75rem; border-radius: 0.5rem; margin-bottom: 0.5rem; }
.option-selected-wrong { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 0.75rem; border-radius: 0.5rem; margin-bottom: 0.5rem; }
.option-neutral { background: #f8f9fa; border: 1px solid #dee2e6; color: #495057; padding: 0.75rem; border-radius: 0.5rem; margin-bottom: 0.5rem; }
.question-card { border-radius: 10px; margin-bottom: 1.5rem; border: 1px solid #dee2e6; }
.mcq-option { display: flex; gap: 0.75rem; align-items: flex-start; }
.mcq-letter { font-weight: 700; width: 28px; text-align: center; flex-shrink: 0; }
.small-muted { font-size: 0.875rem; color: #6c757d; }
.answer-box { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 1rem; min-height: 100px; white-space: pre-wrap; }
.marks-input { width: 120px !important; }
.section-badge { font-size: 0.8rem; margin-left: 0.5rem; }
.no-answer-alert { background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 0.75rem; border-radius: 0.375rem; margin: 0.5rem 0; }
.marks-summary-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; color: white; }
.marks-breakdown { background: #f8f9fa; border-radius: 10px; padding: 1.5rem; }
.progress { height: 20px; border-radius: 10px; }
.progress-bar { border-radius: 10px; }
.mega-exam-badge { background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%); color: white; font-size: 0.8rem; padding: 0.25rem 0.75rem; border-radius: 15px; margin-left: 0.5rem; }
.real-time-change { background: #e7f3ff; border-left: 4px solid #007bff; padding: 0.5rem; margin: 0.25rem 0; border-radius: 0.25rem; font-size: 0.875rem; }
</style>

<div class="main-content">
    <div class="top-navbar mb-3">
        <h4><i class="fas fa-edit"></i> Evaluate Answers <?= $edit_mode ? '(Edit Mode)' : '' ?></h4>
    </div>
    
    <div class="content-area">
        <!-- Session Header -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="paper-header">
                    <div class="paper-left text-start">
                        <div class="fw-bold"><?= htmlspecialchars($session_info['department_name'] ?? 'Department') ?></div>
                        <div class="small text-muted">Batch: <?= htmlspecialchars($session_info['batch_name'] ?? 'N/A') ?></div>
                    </div>
                    <div class="paper-title">
                        <h3 class="mb-1"><?= htmlspecialchars($session_info['exam_title'] ?? 'Exam Title') ?></h3>
                        <div class="small text-muted">
                            <span class="mega-exam-badge">
                                <i class="fas fa-layer-group"></i> 
                                <?= htmlspecialchars($session_info['mega_exam_title'] ?? 'Mega Exam') ?>
                                (<?= htmlspecialchars($session_info['mega_exam_code'] ?? 'Code') ?>)
                            </span>
                            | Total: <?= htmlspecialchars($session_info['exam_total_marks'] ?? '0') ?> marks
                        </div>
                    </div>
                    <div class="paper-right text-end">
                        <div class="fw-bold">Evaluation</div>
                        <div class="small text-muted"><?= $edit_mode ? 'Editing' : 'New' ?> Assessment</div>
                    </div>
                </div>
                
                <div class="row mt-4 text-center">
                    <div class="col-md-3"><div class="fw-bold text-primary">Student</div><div><?= htmlspecialchars($session_info['full_name']) ?></div></div>
                    <div class="col-md-2"><div class="fw-bold text-primary">Roll No</div><div><?= htmlspecialchars($session_info['roll_number']) ?></div></div>
                    <div class="col-md-2"><div class="fw-bold text-primary">Semester</div><div><?= htmlspecialchars($session_info['semester'] ?? 'N/A') ?></div></div>
                    <div class="col-md-3"><div class="fw-bold text-primary">Submitted On</div><div><?= format_datetime($session_info['submitted_at']) ?></div></div>
                    <div class="col-md-2"><div class="fw-bold text-primary">Status</div><div><span class="badge bg-<?= $session_info['status'] === 'submitted' ? 'warning' : 'info' ?>"><?= ucfirst($session_info['status'] ?? 'unknown') ?></span></div></div>
                </div>
            </div>
        </div>

        <!-- Marks Summary -->
        <div class="card marks-summary-card mb-4">
            <div class="card-body">
                <div class="row text-center text-white">
                    <div class="col-md-3 mb-3"><div class="h4 mb-1"><?= number_format($grand_total_obtained, 2) ?></div><div class="small">Obtained Marks</div><div class="small">out of <?= number_format($grand_total_possible, 2) ?></div></div>
                    <div class="col-md-3 mb-3"><div class="h4 mb-1"><?= number_format($percentage, 2) ?>%</div><div class="small">Percentage</div><div class="small"><span class="badge bg-<?= $is_passed ? 'success' : 'danger' ?>"><?= $is_passed ? 'PASS' : 'FAIL' ?></span></div></div>
                    <div class="col-md-3 mb-3"><div class="h4 mb-1"><?= $total_questions ?></div><div class="small">Total Questions</div><div class="small"><?= $mcq_questions ?> MCQ + <?= $descriptive_questions ?> Descriptive</div></div>
                    <div class="col-md-3 mb-3"><div class="h4 mb-1"><?= number_format($session_info['passing_marks'] ?? 0, 2) ?></div><div class="small">Passing Marks</div><div class="small"><?= number_format(($session_info['passing_marks'] ?? 0) / $grand_total_possible * 100, 1) ?>% required</div></div>
                </div>
                <div class="mt-3">
                    <div class="d-flex justify-content-between mb-1"><small>Progress: <?= number_format($grand_total_obtained, 2) ?> / <?= number_format($grand_total_possible, 2) ?></small><small><?= number_format($percentage, 2) ?>%</small></div>
                    <div class="progress"><div class="progress-bar bg-<?= $percentage >= 50 ? 'success' : ($percentage >= 33 ? 'warning' : 'danger') ?>" style="width: <?= $percentage ?>%"></div></div>
                </div>
            </div>
        </div>

        <!-- Marks Breakdown -->
        <div class="card mb-4">
            <div class="card-header bg-light"><h5 class="mb-0"><i class="fas fa-chart-pie"></i> Marks Breakdown</h5></div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-6">
                        <div class="marks-breakdown">
                            <h6 class="text-primary">MCQ Section</h6>
                            <div class="h4 text-primary"><?= number_format($total_mcq_obtained, 2) ?> / <?= number_format($total_mcq_marks, 2) ?></div>
                            <div class="small text-muted"><?= $mcq_questions ?> Questions | <?= $total_mcq_marks > 0 ? number_format(($total_mcq_obtained / $total_mcq_marks) * 100, 2) : '0' ?>%</div>
                            <div class="progress mt-2" style="height: 8px;"><div class="progress-bar bg-primary" style="width: <?= $total_mcq_marks > 0 ? ($total_mcq_obtained / $total_mcq_marks) * 100 : 0 ?>%"></div></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="marks-breakdown">
                            <h6 class="text-success">Descriptive Section</h6>
                            <div class="h4 text-success"><?= number_format($total_descriptive_obtained, 2) ?> / <?= number_format($total_descriptive_marks, 2) ?></div>
                            <div class="small text-muted"><?= $descriptive_questions ?> Questions | <?= $total_descriptive_marks > 0 ? number_format(($total_descriptive_obtained / $total_descriptive_marks) * 100, 2) : '0' ?>%</div>
                            <div class="progress mt-2" style="height: 8px;"><div class="progress-bar bg-success" style="width: <?= $total_descriptive_marks > 0 ? ($total_descriptive_obtained / $total_descriptive_marks) * 100 : 0 ?>%"></div></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Evaluation Form -->
        <form method="POST" id="evaluationForm">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="session_id" value="<?= $session_id ?>">
            <input type="hidden" name="grand_total_possible" value="<?= $grand_total_possible ?>">

            <?php if (count($sections) > 0): ?>
                <?php foreach ($sections as $section_name => $questions): ?>
                    <?php
                    $section_type = 'mixed';
                    $has_mcq = false;
                    $has_descriptive = false;
                    foreach ($questions as $question) {
                        if ($question['detected_type'] === 'mcq') $has_mcq = true;
                        if ($question['detected_type'] === 'descriptive') $has_descriptive = true;
                    }
                    if ($has_mcq && !$has_descriptive) $section_type = 'mcq';
                    if (!$has_mcq && $has_descriptive) $section_type = 'descriptive';
                    ?>
                    <div class="card question-card">
                        <div class="card-header <?= $section_type === 'mcq' ? 'bg-primary' : ($section_type === 'descriptive' ? 'bg-success' : 'bg-warning') ?> text-white">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><?= htmlspecialchars($section_name) ?></strong>
                                    <span class="section-badge badge bg-light text-dark">
                                        <?= $section_type === 'mcq' ? 'MCQ Section' : ($section_type === 'descriptive' ? 'Descriptive Section' : 'Mixed Section') ?>
                                    </span>
                                </div>
                                <div class="small"><?= count($questions) ?> question<?= count($questions) !== 1 ? 's' : '' ?></div>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php $q_no = 1; ?>
                            <?php foreach ($questions as $q): ?>
                                <?php
                                $question_id = intval($q['question_id']);
                                $answer_id = intval($q['answer_id'] ?? 0);
                                $qtext = $q['question_text'] ?? '';
                                $student_answer_text = trim($q['answer_text'] ?? '');
                                $selected_option_id = intval($q['selected_option_id'] ?? 0);
                                $is_correct = intval($q['is_correct'] ?? 0) === 1;
                                $existing_marks = $existing_eval_marks[$answer_id] ?? $q['marks_obtained'] ?? null;
                                $qmarks = floatval($q['question_marks'] ?? 0);
                                $question_type = $q['detected_type'];
                                $has_student_answer = !empty($student_answer_text) || !empty($selected_option_id);
                                ?>

                                <?php if ($question_type === 'mcq'): ?>
                                    <?php
                                    $options_by_id = fetch_mcq_options($conn, $question_id);
                                    list($map_id_to_letter, $map_id_to_text) = map_options_to_letters($options_by_id);
                                    $correct_option_id = null;
                                    foreach ($options_by_id as $oid => $opt) {
                                        if ($opt['is_correct']) {
                                            $correct_option_id = $oid;
                                            break;
                                        }
                                    }
                                    $obtained = $existing_marks ?? ($is_correct ? $qmarks : 0.0);
                                    ?>
                                    <!-- MCQ Question -->
                                    <div class="mb-4 p-3 border rounded bg-light">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <div class="flex-grow-1"><strong>Q<?= $q_no++ ?>:</strong> <?= nl2br(htmlspecialchars($qtext)) ?></div>
                                            <div class="text-end">
                                                <span class="badge <?= $obtained > 0 ? 'bg-success' : 'bg-danger' ?>"><?= $obtained > 0 ? 'Correct' : 'Incorrect' ?></span>
                                                <div class="small text-muted mt-1"><?= $qmarks ?> marks</div>
                                            </div>
                                        </div>
                                        <div class="mt-3">
                                            <strong>Options:</strong>
                                            <div class="mt-2">
                                                <?php if (!empty($options_by_id)): ?>
                                                    <?php
                                                    $display_list = [];
                                                    foreach ($map_id_to_letter as $oid => $letter) {
                                                        $display_list[$letter] = $oid;
                                                    }
                                                    ksort($display_list);
                                                    foreach ($display_list as $letter => $oid):
                                                        $text = $map_id_to_text[$oid] ?? '';
                                                        $is_correct_opt = ($options_by_id[$oid]['is_correct'] ?? 0) == 1;
                                                        $is_selected_opt = ($selected_option_id == $oid);
                                                        $cls = 'option-neutral';
                                                        if ($is_correct_opt) $cls = 'option-correct';
                                                        if ($is_selected_opt && !$is_correct_opt) $cls = 'option-selected-wrong';
                                                        if ($is_selected_opt && $is_correct_opt) $cls = 'option-correct';
                                                    ?>
                                                        <div class="mcq-option <?= $cls ?>">
                                                            <div class="mcq-letter"><?= $letter ?>.</div>
                                                            <div class="flex-fill">
                                                                <div><?= nl2br(htmlspecialchars($text)) ?></div>
                                                                <div class="small-muted">
                                                                    <?php if ($is_selected_opt) echo '<em>✓ Student selected</em>'; ?>
                                                                    <?php if ($is_correct_opt) echo ' • <strong>✓ Correct answer</strong>'; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <div class="p-2 rounded option-neutral">No options available</div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <input type="hidden" name="answer_marks[<?= $answer_id ?>]" value="<?= htmlspecialchars($obtained) ?>">
                                        <div class="mt-3 p-2 bg-white rounded border">
                                            <small><strong>Marks awarded:</strong> <span class="fw-bold text-<?= $obtained > 0 ? 'success' : 'danger' ?>"><?= number_format($obtained, 2) ?> / <?= $qmarks ?></span>
                                            <?php if ($obtained > 0): ?><span class="text-success ms-2"><i class="fas fa-check-circle"></i> Auto-graded</span><?php endif; ?></small>
                                        </div>
                                    </div>

                                <?php else: ?>
                                    <!-- Descriptive Question -->
                                    <div class="mb-4 p-3 border rounded bg-white">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <div class="flex-grow-1"><strong>Q<?= $q_no++ ?>:</strong> <?= nl2br(htmlspecialchars($qtext)) ?></div>
                                            <div class="text-end">
                                                <span class="badge bg-info">Descriptive</span>
                                                <div class="small text-muted mt-1">Max: <?= $qmarks ?> marks</div>
                                            </div>
                                        </div>
                                        <div class="mt-3">
                                            <strong>Student Answer:</strong>
                                            <div class="answer-box mt-1">
                                                <?php if ($has_student_answer && !empty($student_answer_text)): ?>
                                                    <?= nl2br(htmlspecialchars($student_answer_text)) ?>
                                                <?php else: ?>
                                                    <div class="no-answer-alert">
                                                        <i class="fas fa-exclamation-triangle"></i> 
                                                        <strong>No answer provided by student</strong>
                                                        <div class="small mt-1">Student did not attempt this question.</div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="mt-3">
                                            <div class="d-flex align-items-center">
                                                <label class="me-3 mb-0 fw-bold">Assign Marks:</label>
                                                <input type="number" name="answer_marks[<?= $answer_id ?>]" min="0" max="<?= $qmarks ?>" step="0.5" class="form-control marks-input" value="<?= htmlspecialchars($existing_marks ?? '0') ?>" required>
                                                <div class="ms-3"><span class="text-muted fw-bold">/ <?= $qmarks ?> marks</span></div>
                                            </div>
                                            <?php if (isset($existing_marks)): ?>
                                                <div class="small text-success mt-1"><i class="fas fa-history"></i> Previously awarded: <?= $existing_marks ?> marks</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> No questions found for this exam session.</div>
            <?php endif; ?>

            <!-- Submit Button -->
            <div class="mb-5">
                <button type="submit" class="btn btn-success btn-lg w-100 py-3">
                    <i class="fas fa-check-circle"></i> 
                    <?= $edit_mode ? 'Update Evaluation' : 'Submit Evaluation' ?>
                </button>
                <div class="text-center mt-2">
                    <a href="./evaluate.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Back to Evaluations</a>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// Real-time marks calculation and change tracking
document.addEventListener('DOMContentLoaded', function() {
    const marksInputs = document.querySelectorAll('input[name^="answer_marks"]');
    let changeTimers = {};
    
    // Track individual mark changes in real-time
    marksInputs.forEach(input => {
        // Store initial value
        input.setAttribute('data-initial-value', input.value);
        input.setAttribute('data-last-logged-value', input.value);
        
        input.addEventListener('input', function() {
            const currentValue = this.value;
            const lastLoggedValue = this.getAttribute('data-last-logged-value');
            const maxMarks = this.getAttribute('max');
            
            // Clear existing timer for this input
            if (changeTimers[this.name]) {
                clearTimeout(changeTimers[this.name]);
            }
            
            // Set new timer to log after user stops typing for 1.5 seconds
            changeTimers[this.name] = setTimeout(() => {
                if (currentValue !== lastLoggedValue) {
                    logRealTimeChange(this, lastLoggedValue, currentValue, maxMarks);
                    this.setAttribute('data-last-logged-value', currentValue);
                }
            }, 1500);
            
            updateMarksSummary();
        });
        
        // Also log on blur (when user leaves the field)
        input.addEventListener('blur', function() {
            const currentValue = this.value;
            const lastLoggedValue = this.getAttribute('data-last-logged-value');
            const maxMarks = this.getAttribute('max');
            
            if (currentValue !== lastLoggedValue) {
                logRealTimeChange(this, lastLoggedValue, currentValue, maxMarks);
                this.setAttribute('data-last-logged-value', currentValue);
            }
        });
    });
    
    function logRealTimeChange(input, previousValue, newValue, maxMarks) {
        const questionCard = input.closest('.mb-4');
        if (questionCard) {
            const questionTextElem = questionCard.querySelector('strong');
            let questionText = 'Unknown Question';
            if (questionTextElem) {
                // Extract question text (remove "Q1:", "Q2:", etc.)
                questionText = questionTextElem.textContent.replace(/^Q\d+:\s*/, '');
                questionText = questionText.length > 40 ? questionText.substring(0, 40) + '...' : questionText;
            }
            
            // Determine question type
            let questionType = 'DESCRIPTIVE';
            const badge = questionCard.querySelector('.badge');
            if (badge && !badge.textContent.includes('Descriptive')) {
                questionType = 'MCQ';
            }
            
            // Send AJAX request to log the real-time change
            const formData = new FormData();
            formData.append('action', 'log_mark_change');
            formData.append('session_id', <?= $session_id ?>);
            formData.append('teacher_id', <?= $teacher_id ?>);
            formData.append('question_text', questionText);
            formData.append('question_type', questionType);
            formData.append('previous_marks', previousValue);
            formData.append('new_marks', newValue);
            formData.append('max_marks', maxMarks);
            formData.append('csrf_token', '<?= $csrf_token ?>');
            
            fetch('../app/ajax_handler.php', {
                method: 'POST',
                body: formData
            }).then(response => {
                if (response.ok) {
                    // Show visual feedback for the change
                    showChangeFeedback(input, previousValue, newValue);
                }
            }).catch(error => {
                console.error('Error logging mark change:', error);
            });
        }
    }
    
    function showChangeFeedback(input, previousValue, newValue) {
        // Create or update change indicator
        let changeIndicator = input.parentNode.querySelector('.real-time-change');
        if (!changeIndicator) {
            changeIndicator = document.createElement('div');
            changeIndicator.className = 'real-time-change';
            input.parentNode.appendChild(changeIndicator);
        }
        
        changeIndicator.innerHTML = `<i class="fas fa-sync-alt"></i> Mark updated: ${previousValue} → ${newValue} (${new Date().toLocaleTimeString()})`;
        
        // Remove indicator after 5 seconds
        setTimeout(() => {
            if (changeIndicator && changeIndicator.parentNode) {
                changeIndicator.remove();
            }
        }, 5000);
    }
    
    function updateMarksSummary() {
        let totalObtained = 0;
        let mcqObtained = 0;
        let descriptiveObtained = 0;
        
        marksInputs.forEach(input => {
            const marks = parseFloat(input.value) || 0;
            totalObtained += marks;
            
            const questionCard = input.closest('.mb-4');
            if (questionCard) {
                const badge = questionCard.querySelector('.badge');
                if (badge && badge.textContent.includes('Descriptive')) {
                    descriptiveObtained += marks;
                } else {
                    mcqObtained += marks;
                }
            }
        });
        
        console.log('Current Total:', totalObtained, 'MCQ:', mcqObtained, 'Descriptive:', descriptiveObtained);
    }
    
    // Initial summary update
    updateMarksSummary();
});
</script>

<?php include '../templates/footer.php'; ?>
<?php
require_once '../app/config.php';
require_once '../app/helpers.php';
require_once '../app/auth.php';
require_once '../app/teacher_handlers.php';

require_role(['teacher']);

$page_title = 'Manage Exam Sections';
$exam_id = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : 0;

$exam_res = mysqli_prepare($conn, "SELECT * FROM exams WHERE id = ? AND teacher_id = ?");
mysqli_stmt_bind_param($exam_res, "ii", $exam_id, $_SESSION['user_id']);
mysqli_stmt_execute($exam_res);
$exam_result = mysqli_stmt_get_result($exam_res);
$exam_data = mysqli_fetch_assoc($exam_result);
mysqli_stmt_close($exam_res);

if (!$exam_data) {
    set_message('danger', 'Exam not found or access denied');
    redirect('./dashboard.php');
}

$exam_total_marks = intval($exam_data['total_marks'] ?? 0);
$exam_duration = intval($exam_data['duration_minutes'] ?? 0);

// Helper: get sum of section total_marks
function get_total_section_marks($conn, $exam_id, $exclude_section_id = null)
{
    if ($exclude_section_id) {
        $stmt = mysqli_prepare($conn, "SELECT COALESCE(SUM(total_marks),0) AS sum_marks FROM exam_sections WHERE exam_id = ? AND id != ?");
        mysqli_stmt_bind_param($stmt, "ii", $exam_id, $exclude_section_id);
    } else {
        $stmt = mysqli_prepare($conn, "SELECT COALESCE(SUM(total_marks),0) AS sum_marks FROM exam_sections WHERE exam_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $exam_id);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $r = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    return intval($r['sum_marks'] ?? 0);
}

// Helper: get sum of section durations
function get_total_section_duration($conn, $exam_id, $exclude_section_id = null)
{
    if ($exclude_section_id) {
        $stmt = mysqli_prepare($conn, "SELECT COALESCE(SUM(duration_minutes),0) AS sum_duration FROM exam_sections WHERE exam_id = ? AND id != ?");
        mysqli_stmt_bind_param($stmt, "ii", $exam_id, $exclude_section_id);
    } else {
        $stmt = mysqli_prepare($conn, "SELECT COALESCE(SUM(duration_minutes),0) AS sum_duration FROM exam_sections WHERE exam_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $exam_id);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $r = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    return intval($r['sum_duration'] ?? 0);
}

// Fetch existing sections
$sections_res = mysqli_prepare($conn, "SELECT * FROM exam_sections WHERE exam_id = ? ORDER BY section_order");
mysqli_stmt_bind_param($sections_res, "i", $exam_id);
mysqli_stmt_execute($sections_res);
$sections = mysqli_stmt_get_result($sections_res);
mysqli_stmt_close($sections_res);

// Helper to compute next section order
function get_next_section_order($conn, $exam_id)
{
    $stmt = mysqli_prepare($conn, "SELECT COALESCE(MAX(section_order), 0) + 1 AS next_order FROM exam_sections WHERE exam_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $exam_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $r = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    return intval($r['next_order'] ?? 1);
}

$csrf_token = generate_csrf_token();

// ===== POST Actions =====
if ($_SERVER['REQUEST_METHOD'] == 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {

    $sanitize_title = function ($v) {
        $v = trim($v);
        $v = preg_replace('/[^A-Za-z0-9 ]+/', '', $v);
        return mb_substr($v, 0, 30);
    };

    // CREATE SECTION
    if (isset($_POST['add_section'])) {
        $title = $sanitize_title($_POST['title'] ?? '');
        $section_type = ($_POST['section_type'] === 'descriptive') ? 'descriptive' : 'mcq';
        $duration_minutes = intval($_POST['duration_minutes'] ?? 0);
        $marks_per_question = ($section_type === 'mcq') ? floatval($_POST['marks_per_question'] ?? 0) : 0;
        $total_marks = intval($_POST['total_marks'] ?? 0);

        $errors = [];
        if ($title === '' || mb_strlen($title) > 30) {
            $errors[] = 'Section title is required and must be at most 30 characters.';
        }
        if ($duration_minutes < 0) {
            $errors[] = 'Duration cannot be negative.';
        }
        if ($section_type === 'mcq') {
            if ($marks_per_question < 1 || $marks_per_question > 100) {
                $errors[] = 'Marks per question for MCQ must be between 1 and 100.';
            }
        }
        if ($total_marks < 1 || $total_marks > 100) {
            $errors[] = 'Total marks must be between 1 and 100.';
        }

        // Check if section duration exceeds remaining exam duration
        if (empty($errors)) {
            $existing_duration_sum = get_total_section_duration($conn, $exam_id, null);
            $remaining_duration = $exam_duration - $existing_duration_sum;
            
            if ($duration_minutes > $remaining_duration) {
                $errors[] = "Section duration exceeds remaining exam duration. Remaining time available: {$remaining_duration} minutes.";
            }
        }

        // Check if section marks exceed remaining exam marks
        if (empty($errors)) {
            $existing_sum = get_total_section_marks($conn, $exam_id, null);
            $remaining = $exam_total_marks - $existing_sum;
            if ($remaining <= 0) {
                $errors[] = 'All exam marks are already allocated to sections.';
            } elseif ($total_marks > $remaining) {
                $errors[] = "Section marks exceed remaining exam marks. Remaining marks available: {$remaining}.";
            }
        }

        if (!empty($errors)) {
            set_message('danger', implode(' ', $errors));
            redirect($_SERVER['REQUEST_URI']);
        }

        $next_order = get_next_section_order($conn, $exam_id);
        $res = create_exam_section(
            $exam_id,
            $title,
            $section_type,
            $duration_minutes,
            $marks_per_question,
            $total_marks,
            $next_order,
            ''
        );

        set_message($res['success'] ? 'success' : 'danger', $res['success'] ? 'Section added successfully!' : ('Failed: ' . ($res['message'] ?? '')));
        redirect($_SERVER['REQUEST_URI']);
    }

    // UPDATE SECTION - FIXED FOR DESCRIPTIVE
    if (isset($_POST['edit_section'])) {
        $section_id = intval($_POST['section_id'] ?? 0);
        if ($section_id <= 0) {
            set_message('danger', 'Invalid section ID.');
            redirect($_SERVER['REQUEST_URI']);
        }

        $title = $sanitize_title($_POST['title'] ?? '');
        $section_type = ($_POST['section_type'] === 'descriptive') ? 'descriptive' : 'mcq';
        $duration_minutes = intval($_POST['duration_minutes'] ?? 0);
        $total_marks = intval($_POST['total_marks'] ?? 0);

        // Only get marks_per_question for MCQ sections
        if ($section_type === 'mcq') {
            $marks_per_question = floatval($_POST['marks_per_question'] ?? 0);
        } else {
            $marks_per_question = 0; // Descriptive sections don't need this
        }

        $errors = [];
        if ($title === '' || mb_strlen($title) > 30) {
            $errors[] = 'Section title is required and must be at most 30 characters.';
        }
        if ($duration_minutes < 0) {
            $errors[] = 'Duration cannot be negative.';
        }
        
        // Only validate marks_per_question for MCQ sections
        if ($section_type === 'mcq') {
            if ($marks_per_question < 1 || $marks_per_question > 100) {
                $errors[] = 'Marks per question for MCQ must be between 1 and 100.';
            }
        }
        
        if ($total_marks < 1 || $total_marks > 100) {
            $errors[] = 'Total marks must be between 1 and 100.';
        }

        // Check if section duration exceeds remaining exam duration
        if (empty($errors)) {
            $existing_duration_sum_excluding = get_total_section_duration($conn, $exam_id, $section_id);
            $remaining_duration = $exam_duration - $existing_duration_sum_excluding;
            
            if ($duration_minutes > $remaining_duration) {
                $errors[] = "Section duration exceeds remaining exam duration. Remaining time available for this section: {$remaining_duration} minutes.";
            }
        }

        // Check if section marks exceed remaining exam marks
        if (empty($errors)) {
            $existing_sum_excluding = get_total_section_marks($conn, $exam_id, $section_id);
            $remaining = $exam_total_marks - $existing_sum_excluding;
            if ($total_marks > $remaining) {
                $errors[] = "Section marks exceed remaining exam marks. Remaining marks available for this section: {$remaining}.";
            }
        }

        if (!empty($errors)) {
            set_message('danger', implode(' ', $errors));
            redirect($_SERVER['REQUEST_URI']);
        }

        $stmtOrder = mysqli_prepare($conn, "SELECT section_order FROM exam_sections WHERE id = ? AND exam_id = ?");
        mysqli_stmt_bind_param($stmtOrder, "ii", $section_id, $exam_id);
        mysqli_stmt_execute($stmtOrder);
        $resOrder = mysqli_stmt_get_result($stmtOrder);
        $rowOrder = mysqli_fetch_assoc($resOrder);
        mysqli_stmt_close($stmtOrder);
        $section_order = intval($rowOrder['section_order'] ?? 0);

        $res = update_exam_section(
            $section_id,
            $title,
            $section_type,
            $duration_minutes,
            $marks_per_question,
            $total_marks,
            $section_order,
            '',
            $_SESSION['user_id']
        );

        set_message($res['success'] ? 'success' : 'danger', $res['success'] ? 'Section updated!' : ('Failed: ' . ($res['message'] ?? '')));
        redirect($_SERVER['REQUEST_URI']);
    }

    // DELETE SECTION
    if (isset($_POST['delete_section'])) {
        $section_id = intval($_POST['section_id']);
        $res = delete_exam_section($section_id, $_SESSION['user_id']);
        set_message($res['success'] ? 'success' : 'danger', $res['success'] ? 'Section deleted!' : ('Failed: ' . ($res['message'] ?? '')));
        redirect($_SERVER['REQUEST_URI']);
    }

    // ADD QUESTION
    if (isset($_POST['add_question'])) {
        $section_id = intval($_POST['section_id']);
        $question_text = sanitize_input($_POST['question_text'] ?? '');
        $question_type = ($_POST['question_type'] === 'mcq') ? 'mcq' : 'descriptive';
        $marks = floatval($_POST['marks'] ?? 0);
        $question_order = intval($_POST['question_order'] ?? 0);

        if ($question_type === 'descriptive') {
            if ($marks < 1 || $marks > 100) {
                set_message('danger', 'Question marks must be between 1 and 100.');
                redirect($_SERVER['REQUEST_URI']);
            }
        } else {
            $stmt = mysqli_prepare($conn, "SELECT marks_per_question FROM exam_sections WHERE id = ? AND exam_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $section_id, $exam_id);
            mysqli_stmt_execute($stmt);
            $r = mysqli_stmt_get_result($stmt);
            $sec = mysqli_fetch_assoc($r);
            mysqli_stmt_close($stmt);
            $marks = floatval($sec['marks_per_question'] ?? 0);
        }

        if ($question_text === '') {
            set_message('danger', 'Question text cannot be empty.');
            redirect($_SERVER['REQUEST_URI']);
        }

        if ($question_order <= 0) {
            set_message('danger', 'Invalid question order.');
            redirect($_SERVER['REQUEST_URI']);
        }

        $res = create_question(
            $section_id,
            $question_text,
            $question_type,
            sanitize_input($_POST['correct_answer'] ?? ''),
            $marks,
            $question_order
        );

        if ($res['success'] && $question_type === 'mcq') {
            for ($i = 1; $i <= 4; $i++) {
                if (isset($_POST["option_$i"])) {
                    $opt_text = sanitize_input($_POST["option_$i"]);
                    $is_correct = (isset($_POST['correct_option']) && intval($_POST['correct_option']) === $i) ? 1 : 0;
                    create_mcq_option($res['question_id'], $opt_text, $i, $is_correct);
                }
            }
        }
        set_message($res['success'] ? 'success' : 'danger', $res['success'] ? 'Question added!' : ('Failed: ' . ($res['message'] ?? '')));
        redirect($_SERVER['REQUEST_URI']);
    }

    // EDIT QUESTION
    if (isset($_POST['edit_question'])) {
        $question_id = intval($_POST['question_id']);
        $question_text = sanitize_input($_POST['question_text'] ?? '');
        $question_type = ($_POST['question_type'] === 'mcq') ? 'mcq' : 'descriptive';
        $marks = floatval($_POST['marks'] ?? 0);
        $question_order = intval($_POST['question_order'] ?? 0);

        if ($question_type === 'descriptive') {
            if ($marks < 1 || $marks > 100) {
                set_message('danger', 'Question marks must be between 1 and 100.');
                redirect($_SERVER['REQUEST_URI']);
            }
        } else {
            $stmt = mysqli_prepare($conn, "SELECT s.marks_per_question FROM questions q JOIN exam_sections s ON q.section_id = s.id WHERE q.id = ?");
            mysqli_stmt_bind_param($stmt, "i", $question_id);
            mysqli_stmt_execute($stmt);
            $r = mysqli_stmt_get_result($stmt);
            $sec = mysqli_fetch_assoc($r);
            mysqli_stmt_close($stmt);
            $marks = floatval($sec['marks_per_question'] ?? $marks);
        }

        if ($question_text === '') {
            set_message('danger', 'Question text cannot be empty.');
            redirect($_SERVER['REQUEST_URI']);
        }

        if ($question_order <= 0) {
            set_message('danger', 'Invalid question order.');
            redirect($_SERVER['REQUEST_URI']);
        }

        $res = update_question(
            $question_id,
            $question_text,
            $question_type,
            sanitize_input($_POST['correct_answer'] ?? ''),
            $marks,
            $question_order,
            $_SESSION['user_id']
        );

        if ($res['success'] && $question_type === 'mcq') {
            $del = mysqli_prepare($conn, "DELETE FROM mcq_options WHERE question_id = ?");
            mysqli_stmt_bind_param($del, "i", $question_id);
            mysqli_stmt_execute($del);
            mysqli_stmt_close($del);

            for ($i = 1; $i <= 4; $i++) {
                if (isset($_POST["option_$i"])) {
                    $opt_text = sanitize_input($_POST["option_$i"]);
                    $is_correct = (isset($_POST['correct_option']) && intval($_POST['correct_option']) === $i) ? 1 : 0;
                    create_mcq_option($question_id, $opt_text, $i, $is_correct);
                }
            }
        }

        set_message($res['success'] ? 'success' : 'danger', $res['success'] ? 'Question updated!' : ('Failed: ' . ($res['message'] ?? '')));
        redirect($_SERVER['REQUEST_URI']);
    }

    // DELETE QUESTION
    if (isset($_POST['delete_question'])) {
        $question_id = intval($_POST['question_id']);
        $res = delete_question($question_id, $_SESSION['user_id']);
        set_message($res['success'] ? 'success' : 'danger', $res['success'] ? 'Question deleted!' : ('Failed: ' . ($res['message'] ?? '')));
        redirect($_SERVER['REQUEST_URI']);
    }
}

// Re-fetch sections for display
$sections_res = mysqli_prepare($conn, "SELECT * FROM exam_sections WHERE exam_id = ? ORDER BY section_order");
mysqli_stmt_bind_param($sections_res, "i", $exam_id);
mysqli_stmt_execute($sections_res);
$sections = mysqli_stmt_get_result($sections_res);
mysqli_stmt_close($sections_res);

include '../templates/header.php';
include '../templates/sidebar_teacher.php';
?>

<div class="main-content">
    <div class="top-navbar d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="fas fa-list"></i>
            <?php echo htmlspecialchars($exam_data['paper_name'] ?? $exam_data['title']); ?></h4>
        <div>
            <a href="view_paper.php?exam_id=<?php echo $exam_id; ?>" class="btn btn-info me-2">
                <i class="fas fa-eye"></i> View Paper
            </a>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSectionModal">
                <i class="fas fa-plus"></i> Add Section
            </button>
        </div>
    </div>

    <div class="content-area">
        <?php display_messages(); ?>
        
        <!-- Exam Summary Card -->
        <div class="card mb-4 bg-light">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <strong>Total Exam Duration:</strong> <?php echo $exam_duration; ?> minutes
                    </div>
                    <div class="col-md-4">
                        <strong>Total Exam Marks:</strong> <?php echo $exam_total_marks; ?>
                    </div>
                    <div class="col-md-4">
                        <strong>Allocated Duration:</strong> 
                        <?php 
                        $allocated_duration = get_total_section_duration($conn, $exam_id, null);
                        echo $allocated_duration . '/' . $exam_duration . ' minutes';
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <?php while ($section = mysqli_fetch_assoc($sections)):
            $qstmt = mysqli_prepare($conn, "SELECT * FROM questions WHERE section_id = ? ORDER BY question_order");
            mysqli_stmt_bind_param($qstmt, "i", $section['id']);
            mysqli_stmt_execute($qstmt);
            $qres = mysqli_stmt_get_result($qstmt);
            $questions = mysqli_fetch_all($qres, MYSQLI_ASSOC);
            mysqli_stmt_close($qstmt);

            $current_total_marks = 0;
            if ($section['section_type'] === 'descriptive') {
                foreach ($questions as $q) {
                    $current_total_marks += $q['marks'];
                }
            }
            ?>
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <strong><?php echo htmlspecialchars($section['title']); ?></strong>
                        <span class="badge bg-info ms-2"><?php echo ucfirst($section['section_type']); ?></span>
                        <span class="badge bg-secondary ms-2"><?php echo intval($section['duration_minutes']); ?> min</span>
                        <?php if ($section['section_type'] === 'mcq'): ?>
                            <span class="badge bg-primary ms-2"><?php echo $section['marks_per_question']; ?> marks per question</span>
                        <?php else: ?>
                            <span class="badge bg-primary ms-2">Total: <?php echo $current_total_marks; ?>/<?php echo $section['total_marks']; ?> marks</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <button class="btn btn-sm btn-warning me-2" data-bs-toggle="modal" data-bs-target="#editSectionModal<?php echo $section['id']; ?>">Edit</button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this section and all its questions?');">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="section_id" value="<?php echo $section['id']; ?>">
                            <button type="submit" name="delete_section" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                        <button class="btn btn-sm btn-success ms-2" data-bs-toggle="modal" data-bs-target="#addQuestionModal<?php echo $section['id']; ?>">Add Question</button>
                    </div>
                </div>

                <div class="card-body">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Question</th>
                                <th>Type</th>
                                <th>Marks</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($questions as $q): ?>
                                <tr>
                                    <td><?php echo intval($q['question_order']); ?></td>
                                    <td><?php echo htmlspecialchars(mb_substr($q['question_text'], 0, 120)); ?><?php echo (mb_strlen($q['question_text']) > 120 ? '...' : ''); ?></td>
                                    <td><?php echo ucfirst($q['question_type']); ?></td>
                                    <td><?php echo $q['marks']; ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editQuestionModal<?php echo $q['id']; ?>">Edit</button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this question?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                                            <button type="submit" name="delete_question" class="btn btn-sm btn-danger">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
</div>

<!-- Add Section Modal -->
<div class="modal fade" id="addSectionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="formAddSection">
                <div class="modal-header">
                    <h5 class="modal-title">Add Section</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                    <div class="mb-3">
                        <label class="form-label">Section Title</label>
                        <input type="text" name="title" class="form-control" maxlength="30" pattern="[A-Za-z0-9 ]{1,30}" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Section Type</label>
                        <select name="section_type" class="form-select" required id="sectionType" onchange="toggleMarksField('new')">
                            <option value="mcq">MCQ</option>
                            <option value="descriptive">Descriptive</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <?php
                        $existing_duration_sum = get_total_section_duration($conn, $exam_id, null);
                        $remaining_duration = $exam_duration - $existing_duration_sum;
                        if ($remaining_duration < 0) $remaining_duration = 0;
                        ?>
                        <label class="form-label">Duration (minutes) (remaining available: <?php echo $remaining_duration; ?>)</label>
                        <input type="number" name="duration_minutes" class="form-control" min="0" max="<?php echo max(0, $remaining_duration); ?>" value="0" required>
                    </div>

                    <div class="mb-3 marks-field" id="marksFieldNew">
                        <label class="form-label">Marks per question</label>
                        <input type="number" name="marks_per_question" class="form-control" step="0.5" min="1" max="100" value="1" required>
                    </div>

                    <div class="mb-3">
                        <?php
                        $existing_sum = get_total_section_marks($conn, $exam_id, null);
                        $remaining = $exam_total_marks - $existing_sum;
                        if ($remaining < 0) $remaining = 0;
                        ?>
                        <label class="form-label">Total marks (remaining available: <?php echo $remaining; ?>)</label>
                        <input type="number" name="total_marks" class="form-control" min="1" max="<?php echo max(1, $remaining); ?>" value="<?php echo min($exam_total_marks, max(1, $remaining)); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Section order (auto)</label>
                        <input type="number" name="section_order" class="form-control" value="<?php echo get_next_section_order($conn, $exam_id); ?>" readonly>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_section" class="btn btn-primary">Add Section</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Section Modals -->
<?php mysqli_data_seek($sections, 0); ?>
<?php while ($section = mysqli_fetch_assoc($sections)): ?>
    <div class="modal fade" id="editSectionModal<?php echo $section['id']; ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Section</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="section_id" value="<?php echo $section['id']; ?>">

                        <div class="mb-3">
                            <label class="form-label">Title</label>
                            <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($section['title']); ?>" maxlength="30" pattern="[A-Za-z0-9 ]{1,30}" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Section Type</label>
                            <select name="section_type" class="form-select" required id="sectionType<?php echo $section['id']; ?>" onchange="toggleMarksField(<?php echo $section['id']; ?>)">
                                <option value="mcq" <?php echo $section['section_type'] == 'mcq' ? 'selected' : ''; ?>>MCQ</option>
                                <option value="descriptive" <?php echo $section['section_type'] == 'descriptive' ? 'selected' : ''; ?>>Descriptive</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <?php
                            $existing_duration_sum_excl = get_total_section_duration($conn, $exam_id, $section['id']);
                            $remaining_duration_for_this = $exam_duration - $existing_duration_sum_excl;
                            ?>
                            <label class="form-label">Duration (minutes) (remaining available: <?php echo $remaining_duration_for_this; ?>)</label>
                            <input type="number" name="duration_minutes" class="form-control" value="<?php echo intval($section['duration_minutes']); ?>" min="0" max="<?php echo max(0, $remaining_duration_for_this); ?>" required>
                        </div>

                        <div class="mb-3 marks-field" id="marksField<?php echo $section['id']; ?>" style="<?php echo $section['section_type'] == 'descriptive' ? 'display:none;' : ''; ?>">
                            <label class="form-label">Marks per question</label>
                            <input type="number" name="marks_per_question" class="form-control" step="0.5" min="1" max="100" value="<?php echo $section['marks_per_question']; ?>" <?php echo $section['section_type'] == 'mcq' ? 'required' : ''; ?>>
                        </div>

                        <div class="mb-3">
                            <?php
                            $existing_sum_excl = get_total_section_marks($conn, $exam_id, $section['id']);
                            $remaining_for_this = $exam_total_marks - $existing_sum_excl;
                            ?>
                            <label class="form-label">Total marks (remaining available: <?php echo $remaining_for_this; ?>)</label>
                            <input type="number" name="total_marks" class="form-control" value="<?php echo intval($section['total_marks']); ?>" min="1" max="100" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Section order (auto)</label>
                            <input type="number" name="section_order" class="form-control" value="<?php echo intval($section['section_order']); ?>" readonly>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="edit_section" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endwhile; ?>

<!-- Add Question Modals -->
<?php mysqli_data_seek($sections, 0); ?>
<?php while ($section = mysqli_fetch_assoc($sections)):
    $next_question_order = 1;
    $stmt = mysqli_prepare($conn, "SELECT COALESCE(MAX(question_order), 0) + 1 AS next_order FROM questions WHERE section_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $section['id']);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    $next_question_order = intval($row['next_order'] ?? 1);
    ?>
    <div class="modal fade" id="addQuestionModal<?php echo $section['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Add Question to <?php echo htmlspecialchars($section['title']); ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="section_id" value="<?php echo $section['id']; ?>">
                        <input type="hidden" name="question_type" value="<?php echo $section['section_type']; ?>">

                        <div class="mb-3">
                            <label class="form-label">Question Text</label>
                            <textarea name="question_text" class="form-control" rows="3" required></textarea>
                        </div>

                        <?php if ($section['section_type'] === 'mcq'): ?>
                            <div class="mb-3">
                                <label class="form-label">Options</label>
                                <?php for ($i = 1; $i <= 4; $i++): ?>
                                    <input type="text" name="option_<?php echo $i; ?>" class="form-control mb-2" placeholder="Option <?php echo $i; ?>" required>
                                <?php endfor; ?>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Correct Option</label>
                                <select name="correct_option" class="form-select" required>
                                    <?php for ($i = 1; $i <= 4; $i++): ?>
                                        <option value="<?php echo $i; ?>">Option <?php echo $i; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Marks (auto-set from section)</label>
                                <input type="number" class="form-control" value="<?php echo $section['marks_per_question']; ?>" readonly>
                                <input type="hidden" name="marks" value="<?php echo $section['marks_per_question']; ?>">
                            </div>
                        <?php else: ?>
                            <div class="mb-3">
                                <label class="form-label">Marks</label>
                                <input type="number" name="marks" class="form-control" step="0.5" min="1" max="100" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Correct Answer (for teacher reference)</label>
                                <input type="text" name="correct_answer" class="form-control">
                            </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label">Question Order</label>
                            <input type="number" name="question_order" class="form-control" min="1" value="<?php echo $next_question_order; ?>" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_question" class="btn btn-primary">Add Question</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endwhile; ?>

<!-- Edit Question Modals -->
<?php mysqli_data_seek($sections, 0); ?>
<?php while ($section = mysqli_fetch_assoc($sections)):
    $qstmt = mysqli_prepare($conn, "SELECT * FROM questions WHERE section_id = ? ORDER BY question_order");
    mysqli_stmt_bind_param($qstmt, "i", $section['id']);
    mysqli_stmt_execute($qstmt);
    $qres = mysqli_stmt_get_result($qstmt);
    $questions = mysqli_fetch_all($qres, MYSQLI_ASSOC);
    mysqli_stmt_close($qstmt);

    foreach ($questions as $q):
        ?>
        <div class="modal fade" id="editQuestionModal<?php echo $q['id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <form method="POST">
                        <div class="modal-header">
                            <h5 class="modal-title">Edit Question</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">

                            <div class="mb-3">
                                <label class="form-label">Question Text</label>
                                <textarea name="question_text" class="form-control" rows="3" required><?php echo htmlspecialchars($q['question_text']); ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Marks</label>
                                <input type="number" name="marks" class="form-control" step="0.5" min="1" max="100" value="<?php echo $q['marks']; ?>" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Order</label>
                                <input type="number" name="question_order" class="form-control" min="1" value="<?php echo $q['question_order']; ?>" required>
                            </div>

                            <input type="hidden" name="question_type" value="<?php echo $q['question_type']; ?>">

                            <?php if ($q['question_type'] === 'mcq'):
                                $opt_stmt = mysqli_prepare($conn, "SELECT * FROM mcq_options WHERE question_id = ? ORDER BY option_order");
                                mysqli_stmt_bind_param($opt_stmt, "i", $q['id']);
                                mysqli_stmt_execute($opt_stmt);
                                $opt_res = mysqli_stmt_get_result($opt_stmt);
                                $opts = [];
                                while ($opt = mysqli_fetch_assoc($opt_res)) {
                                    $opts[] = $opt;
                                }
                                mysqli_stmt_close($opt_stmt);
                                ?>
                                <div class="mb-3">
                                    <label class="form-label">Options</label>
                                    <?php for ($oi = 0; $oi < 4; $oi++):
                                        $text = isset($opts[$oi]) ? $opts[$oi]['option_text'] : '';
                                        ?>
                                        <input type="text" name="option_<?php echo $oi + 1; ?>" class="form-control mb-2" placeholder="Option <?php echo $oi + 1; ?>" value="<?php echo htmlspecialchars($text); ?>" required>
                                    <?php endfor; ?>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Correct Option</label>
                                    <select name="correct_option" class="form-select" required>
                                        <?php for ($oi = 0; $oi < 4; $oi++):
                                            $sel = (isset($opts[$oi]) && intval($opts[$oi]['is_correct']) === 1) ? 'selected' : ''; ?>
                                            <option value="<?php echo $oi + 1; ?>" <?php echo $sel; ?>>Option <?php echo $oi + 1; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            <?php else: ?>
                                <div class="mb-3">
                                    <label class="form-label">Correct Answer (for teacher reference)</label>
                                    <input type="text" name="correct_answer" class="form-control" value="<?php echo htmlspecialchars($q['correct_answer']); ?>">
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="edit_question" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endwhile; ?>

<script>
    function toggleMarksField(sectionId) {
        const isNew = sectionId === 'new';
        const selectElement = document.getElementById(isNew ? 'sectionType' : 'sectionType' + sectionId);
        const marksField = document.getElementById(isNew ? 'marksFieldNew' : 'marksField' + sectionId);
        if (!selectElement || !marksField) return;
        const marksInput = marksField.querySelector('input[name="marks_per_question"]');
        if (selectElement.value === 'descriptive') {
            marksField.style.display = 'none';
            if (marksInput) {
                marksInput.removeAttribute('required');
                marksInput.value = '0';
                marksInput.disabled = true;
            }
        } else {
            marksField.style.display = 'block';
            if (marksInput) {
                marksInput.setAttribute('required', 'required');
                marksInput.disabled = false;
                if (marksInput.value === '0' || marksInput.value === '') marksInput.value = '1';
            }
        }
    }

    // Initialize all modals on page load
    document.addEventListener('DOMContentLoaded', function () {
        // Initialize new section modal
        toggleMarksField('new');
        
        // Initialize all edit section modals
        const editModals = document.querySelectorAll('[id^="editSectionModal"]');
        editModals.forEach(modal => {
            const sectionId = modal.id.replace('editSectionModal', '');
            if (sectionId) {
                toggleMarksField(sectionId);
            }
        });

        // Input validation
        document.addEventListener('input', function (e) {
            const el = e.target;
            if (!el) return;
            if (el.type === 'number') {
                if (el.value.indexOf('-') !== -1) {
                    el.value = el.value.replace(/-/g, '');
                }
                const min = el.getAttribute('min');
                const max = el.getAttribute('max');
                if (min !== null && el.value !== '' && Number(el.value) < Number(min)) {
                    el.value = min;
                }
                if (max !== null && el.value !== '' && Number(el.value) > Number(max)) {
                    el.value = max;
                }
            }
        });

        // Form validation
        const addForm = document.getElementById('formAddSection');
        if (addForm) {
            addForm.addEventListener('submit', function (e) {
                const totalMarksInput = addForm.querySelector('input[name="total_marks"]');
                const maxMarksAllowed = parseInt(totalMarksInput.getAttribute('max') || '<?php echo $exam_total_marks; ?>', 10);
                const marksVal = parseInt(totalMarksInput.value || '0', 10);
                
                const durationInput = addForm.querySelector('input[name="duration_minutes"]');
                const maxDurationAllowed = parseInt(durationInput.getAttribute('max') || '<?php echo $exam_duration; ?>', 10);
                const durationVal = parseInt(durationInput.value || '0', 10);
                
                let errors = [];
                
                if (marksVal > maxMarksAllowed) {
                    errors.push('Section marks exceed remaining exam marks. You can allocate up to ' + maxMarksAllowed + ' marks.');
                }
                
                if (durationVal > maxDurationAllowed) {
                    errors.push('Section duration exceeds remaining exam duration. You can allocate up to ' + maxDurationAllowed + ' minutes.');
                }
                
                if (errors.length > 0) {
                    e.preventDefault();
                    alert(errors.join('\n'));
                    return false;
                }
                return true;
            });
        }
    });
</script>

<?php include '../templates/footer.php'; ?>
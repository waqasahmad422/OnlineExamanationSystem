<?php
require_once '../app/config.php';
require_once '../app/helpers.php';
require_once '../app/auth.php';

require_role(['student']);

// Set correct timezone
date_default_timezone_set('Asia/Karachi');

$page_title = 'Available Exams';

$student_id     = intval($_SESSION['user_id']);
$department_id  = intval($_SESSION['department_id']);
$batch_id       = intval($_SESSION['batch_id']);
$semester       = intval($_SESSION['semester']);

/* ============================================================
   FETCH ALL EXAMS WITH DEPT + TEACHER + MEGA EXAM (One Query)
=============================================================== */
$query = "
    SELECT 
        e.*,
        d.name AS dept_name,
        u.full_name AS teacher_name,
        me.title AS mega_exam_title,
        me.mega_exam_code
    FROM exams e
    INNER JOIN departments d ON e.department_id = d.id
    INNER JOIN users u ON e.teacher_id = u.id
    LEFT JOIN mega_exams me ON e.mega_exam_id = me.id
    WHERE 
        e.department_id = $department_id AND 
        e.batch_id = $batch_id AND 
        e.semester = $semester AND 
        e.is_approved = 1
    ORDER BY e.created_at DESC
";

$exams = mysqli_query($conn, $query);

include '../templates/header.php';
include '../templates/sidebar_student.php';
?>

<div class="main-content">
    <div class="top-navbar">
        <h4 class="mb-0"><i class="fas fa-file-alt"></i> Available Exams</h4>
    </div>

    <div class="content-area">
        <div class="row">

<?php
$current_time = time();

while ($exam = mysqli_fetch_assoc($exams)):

    $exam_id = $exam['id'];

    // Check if exam has time window (not NULL dates)
    $has_time_window = (!empty($exam['start_datetime']) && !empty($exam['end_datetime']));
    
    if ($has_time_window) {
        // Exam has specific time window
        $start_time = strtotime($exam['start_datetime']);
        $end_time   = strtotime($exam['end_datetime']);
        
        $is_upcoming = $current_time < $start_time;
        $is_expired = $current_time > $end_time;
        $is_available = !$is_upcoming && !$is_expired;
    } else {
        // Exam is always available (no time window)
        $is_upcoming = false;
        $is_expired = false;
        $is_available = true;
        $start_time = null;
        $end_time = null;
    }

    /* ============================================================
       FETCH QUESTION COUNTS + MARKS IN ONE QUERY
    =============================================================== */
    $q_stats = mysqli_query($conn, "
        SELECT 
            question_type,
            COUNT(*) AS total_questions,
            SUM(marks) AS total_marks
        FROM questions q
        INNER JOIN exam_sections s ON q.section_id = s.id
        WHERE s.exam_id = $exam_id
        GROUP BY question_type
    ");

    $mcq_count = 0;
    $descriptive_count = 0;
    $total_questions = 0;

    while ($row = mysqli_fetch_assoc($q_stats)) {
        $total_questions += $row['total_questions'];
        if ($row['question_type'] == 'mcq') {
            $mcq_count = $row['total_questions'];
        } else {
            $descriptive_count = $row['total_questions'];
        }
    }

    /* ============================================================
       CHECK STUDENT EXAM SESSION
    =============================================================== */
    $session_q = mysqli_query($conn, "
        SELECT id, status
        FROM student_exam_sessions
        WHERE student_id = $student_id AND exam_id = $exam_id
        LIMIT 1
    ");
    $exam_session = mysqli_fetch_assoc($session_q);

    $already_completed = $exam_session && $exam_session['status'] == 'completed';
    $in_progress       = $exam_session && $exam_session['status'] == 'in_progress';
    $has_session       = $exam_session ? true : false;
?>

            <div class="col-md-6">
                <div class="card shadow-sm mb-3">
                    <div class="card-body">

                        <!-- Mega Exam Name -->
                        <?php if (!empty($exam['mega_exam_title'])): ?>
                        <div class="mb-2">
                            <span class="badge bg-info text-dark">
                                <i class="fas fa-layer-group"></i>
                                <?= htmlspecialchars($exam['mega_exam_title']) ?>
                                <?php if (!empty($exam['mega_exam_code'])): ?>
                                    <small>(<?= htmlspecialchars($exam['mega_exam_code']) ?>)</small>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php endif; ?>

                        <!-- Exam Title + Status -->
                        <div class="d-flex justify-content-between mb-3">
                            <h5 class="fw-bold text-primary mb-0"><?= htmlspecialchars($exam['title']); ?></h5>

                            <?php if ($already_completed): ?>
                                <span class="badge bg-success"><i class="fas fa-check-circle"></i> Completed</span>
                            <?php elseif ($in_progress): ?>
                                <span class="badge bg-warning"><i class="fas fa-clock"></i> In Progress</span>
                            <?php elseif ($has_time_window && $is_upcoming): ?>
                                <span class="badge bg-info"><i class="fas fa-clock"></i> Upcoming</span>
                            <?php elseif ($has_time_window && $is_expired): ?>
                                <span class="badge bg-danger"><i class="fas fa-times-circle"></i> Expired</span>
                            <?php elseif ($has_time_window && $is_available): ?>
                                <span class="badge bg-success"><i class="fas fa-play-circle"></i> Available</span>
                            <?php else: ?>
                                <span class="badge bg-primary"><i class="fas fa-infinity"></i> Always Available</span>
                            <?php endif; ?>
                        </div>

                        <!-- Basic Exam Info -->
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <small class="text-muted"><i class="fas fa-user-tie"></i> Teacher</small>
                                <p class="mb-1 fw-semibold"><?= $exam['teacher_name']; ?></p>
                            </div>
                            <div class="col-6">
                                <small class="text-muted"><i class="fas fa-clock"></i> Duration</small>
                                <p class="mb-1 fw-semibold"><?= $exam['duration_minutes']; ?> mins</p>
                            </div>
                            <div class="col-6">
                                <small class="text-muted"><i class="fas fa-star"></i> Total Marks</small>
                                <p class="mb-1 fw-semibold"><?= $exam['total_marks']; ?></p>
                            </div>
                            <div class="col-6">
                                <small class="text-muted"><i class="fas fa-question-circle"></i> Questions</small>
                                <p class="mb-1 fw-semibold"><?= $total_questions; ?></p>
                            </div>
                        </div>

                        <!-- Question breakdown -->
                        <div class="mb-3">
                            <small class="text-muted"><i class="fas fa-tasks"></i> Question Types</small><br>
                            <?php if ($mcq_count > 0): ?>
                                <span class="badge bg-success">MCQs: <?= $mcq_count; ?></span>
                            <?php endif; ?>

                            <?php if ($descriptive_count > 0): ?>
                                <span class="badge bg-warning">Descriptive: <?= $descriptive_count; ?></span>
                            <?php endif; ?>
                        </div>

                        <!-- Schedule -->
                        <div class="mb-3">
                            <small class="text-muted"><i class="fas fa-calendar-alt"></i> Exam Schedule</small>
                            <div class="small">
                                <?php if ($has_time_window): ?>
                                    <div class="d-flex justify-content-between">
                                        <span>Start:</span>
                                        <span><?= date('M d, Y g:i A', $start_time); ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>End:</span>
                                        <span><?= date('M d, Y g:i A', $end_time); ?></span>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center text-primary">
                                        <i class="fas fa-infinity"></i> No Time Restrictions<br>
                                        <small class="text-muted">Start anytime, complete within <?= $exam['duration_minutes']; ?> minutes</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="text-center pt-2">

                            <?php if ($already_completed): ?>
                                <a href="results.php?exam_id=<?= $exam_id; ?>" class="btn btn-success w-100">
                                    <i class="fas fa-chart-bar"></i> View Results
                                </a>

                            <?php elseif ($in_progress): ?>
                                <a href="take_exam.php?exam_id=<?= $exam_id; ?>&session=<?= $exam_session['id']; ?>" 
                                   class="btn btn-warning w-100">
                                   <i class="fas fa-play-circle"></i> Resume Exam
                                </a>

                            <?php elseif ($has_time_window && $is_upcoming): ?>
                                <button class="btn btn-secondary w-100" disabled>
                                    <i class="fas fa-clock"></i> Starts Soon
                                </button>

                            <?php elseif ($has_time_window && $is_expired): ?>
                                <button class="btn btn-outline-danger w-100" disabled>
                                    <i class="fas fa-times-circle"></i> Exam Expired
                                </button>
                                <?php if ($has_session): ?>
                                    <small><a href="results.php?exam_id=<?= $exam_id; ?>">View Attempt</a></small>
                                <?php endif; ?>

                            <?php else: ?>
                                <?php if ($has_session && !$in_progress): ?>
                                    <div class="btn-group w-100">
                                        <a href="take_exam.php?exam_id=<?= $exam_id; ?>" class="btn btn-primary">
                                            <i class="fas fa-redo"></i> Start Fresh
                                        </a>
                                        <a href="take_exam.php?exam_id=<?= $exam_id; ?>&session=<?= $exam_session['id']; ?>" 
                                           class="btn btn-outline-primary">
                                           <i class="fas fa-play-circle"></i> Resume
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <a href="take_exam.php?exam_id=<?= $exam_id; ?>" class="btn btn-success w-100">
                                        <i class="fas fa-play"></i> Start Exam
                                    </a>
                                <?php endif; ?>
                            <?php endif; ?>

                        </div>

                    </div>
                </div>
            </div>

<?php endwhile; ?>

<?php if (mysqli_num_rows($exams) === 0): ?>
    <div class="col-12">
        <div class="card text-center py-5">
            <i class="fas fa-file-alt fa-3x text-muted mb-3"></i>
            <h5 class="text-muted">No exams available</h5>
            <p class="text-muted">Please check again later.</p>
        </div>
    </div>
<?php endif; ?>

        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
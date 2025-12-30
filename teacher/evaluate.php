<?php
require_once '../app/config.php';
require_once '../app/helpers.php';
require_once '../app/auth.php';
require_once '../app/teacher_handlers.php';

require_role(['teacher']);
$teacher_id = intval($_SESSION['user_id']);
$page_title = "Evaluate Answers";

// Add audit logging function
function log_evaluation_action($conn, $teacher_id, $action, $session_id = null, $details = '') {
    $stmt = mysqli_prepare($conn, "
        INSERT INTO audit_logs (user_id, action, table_name, record_id, details, ip_address, user_agent) 
        VALUES (?, ?, 'evaluations', ?, ?, ?, ?)
    ");
    $table_name = 'evaluations';
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    mysqli_stmt_bind_param($stmt, "ississ", $teacher_id, $action, $session_id, $details, $ip_address, $user_agent);
    mysqli_stmt_execute($stmt);
}

// Log page access
log_evaluation_action($conn, $teacher_id, 'VIEW_EVALUATION_PAGE', null, 'Accessed evaluation dashboard');

include '../templates/header.php';
include '../templates/sidebar_teacher.php';

// Fetch all mega exams accessible by this teacher
$mega_exam_query = mysqli_prepare($conn, "
    SELECT DISTINCT me.id, me.title, me.mega_exam_code
    FROM mega_exams me
    JOIN exams e ON me.id = e.mega_exam_id
    WHERE e.teacher_id = ?
    ORDER BY me.created_at DESC
");
mysqli_stmt_bind_param($mega_exam_query, "i", $teacher_id);
mysqli_stmt_execute($mega_exam_query);
$mega_exam_result = mysqli_stmt_get_result($mega_exam_query);

// Get first mega exam ID for default selection
$first_mega_exam_id = null;
$mega_exams = [];
if(mysqli_num_rows($mega_exam_result) > 0) {
    while($mega_exam = mysqli_fetch_assoc($mega_exam_result)) {
        $mega_exams[] = $mega_exam;
        if($first_mega_exam_id === null) {
            $first_mega_exam_id = $mega_exam['id'];
        }
    }
}

// Fetch exams for the first mega exam
$first_exam_id = null;
$exams = [];
if($first_mega_exam_id) {
    $exam_query = mysqli_prepare($conn, "
        SELECT id, title, semester, is_approved 
        FROM exams 
        WHERE teacher_id = ? AND mega_exam_id = ?
        ORDER BY created_at DESC
    ");
    mysqli_stmt_bind_param($exam_query, "ii", $teacher_id, $first_mega_exam_id);
    mysqli_stmt_execute($exam_query);
    $exam_result = mysqli_stmt_get_result($exam_query);
    
    if(mysqli_num_rows($exam_result) > 0) {
        while($exam = mysqli_fetch_assoc($exam_result)) {
            $exams[] = $exam;
            if($first_exam_id === null) {
                $first_exam_id = $exam['id'];
            }
        }
    }
}

// Fetch all evaluation sessions
$query = mysqli_prepare($conn, "
    SELECT 
        ses.id, 
        ses.submitted_at, 
        e.id as exam_id,
        e.title AS paper_title,
        e.semester,
        e.is_approved,
        me.id as mega_exam_id,
        me.title as mega_exam_title,
        u.full_name, 
        u.roll_number, 
        d.name AS dept_name,
        b.name AS batch_name,
        b.year AS batch_year,
        ev.status AS eval_status
    FROM student_exam_sessions ses
    JOIN exams e ON ses.exam_id = e.id
    JOIN mega_exams me ON e.mega_exam_id = me.id
    JOIN users u ON ses.student_id = u.id
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN batches b ON u.batch_id = b.id
    LEFT JOIN evaluations ev ON ev.session_id = ses.id AND ev.teacher_id = ?
    WHERE e.teacher_id = ?
    ORDER BY me.title, e.title, ses.submitted_at DESC
");
mysqli_stmt_bind_param($query, "ii", $teacher_id, $teacher_id);
mysqli_stmt_execute($query);
$sessions_result = mysqli_stmt_get_result($query);

// Organize sessions by mega exam and exam
$all_sessions = [];
$sessions_by_mega_exam = [];
$sessions_by_exam = [];

if(mysqli_num_rows($sessions_result) > 0) {
    while($row = mysqli_fetch_assoc($sessions_result)) {
        $all_sessions[] = $row;
        $sessions_by_mega_exam[$row['mega_exam_id']][] = $row;
        $sessions_by_exam[$row['exam_id']][] = $row;
    }
}
?>

<div class="main-content">
    <div class="top-navbar mb-3">
        <h4><i class="fas fa-edit"></i> Evaluate Answers</h4>
    </div>

    <div class="content-area">
        <!-- Mega Exam Selection Tabs -->
        <div class="card mb-4">
            <div class="card-header">Select Mega Exam</div>
            <div class="card-body">
                <ul class="nav nav-tabs" id="megaExamTabs" role="tablist">
                    <?php if(!empty($mega_exams)): ?>
                        <?php foreach($mega_exams as $index => $mega_exam): ?>
                            <?php $active_class = $index === 0 ? 'active' : ''; ?>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?= $active_class ?>" 
                                        data-mega-exam-id="<?= $mega_exam['id'] ?>"
                                        onclick="selectMegaExam(<?= $mega_exam['id'] ?>, this)">
                                    <?= htmlspecialchars($mega_exam['title']) ?>
                                    <small class="text-muted ms-1">(<?= htmlspecialchars($mega_exam['mega_exam_code']) ?>)</small>
                                </button>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" type="button">
                                No mega exams found
                            </button>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <!-- Paper Selection Tabs -->
        <div class="card mb-4">
            <div class="card-header">Select Exam Paper</div>
            <div class="card-body">
                <ul class="nav nav-tabs" id="examTabs" role="tablist">
                    <?php if(!empty($exams)): ?>
                        <?php foreach($exams as $index => $exam): ?>
                            <?php $active_class = $index === 0 ? 'active' : ''; ?>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?= $active_class ?>" 
                                        data-exam-id="<?= $exam['id'] ?>"
                                        onclick="selectExam(<?= $exam['id'] ?>, this)">
                                    <?= htmlspecialchars($exam['title']) ?>
                                    <?php if($exam['is_approved']): ?>
                                        <span class="badge bg-success ms-1">Published</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning ms-1">Draft</span>
                                    <?php endif; ?>
                                </button>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" type="button">
                                No papers found for this mega exam
                            </button>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Evaluation Sessions</div>
            <div class="card-body table-responsive">
                <table class="table table-striped" id="evaluationTable">
                    <thead>
                        <tr>
                            <th>Roll No</th>
                            <th>Mega Exam</th>
                            <th>Paper</th>
                            <th>Student</th>
                            <th>Department</th>
                            <th>Batch</th>
                            <th>Semester</th>
                            <th>Submitted At</th>
                            <th>Evaluation Status</th>
                            <th>Result Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="sessionsTableBody">
                        <?php if(!empty($all_sessions) && $first_exam_id): ?>
                            <?php 
                            // Show sessions for the first exam by default
                            $display_sessions = array_filter($all_sessions, function($session) use ($first_exam_id) {
                                return $session['exam_id'] == $first_exam_id;
                            });
                            ?>
                            <?php if(!empty($display_sessions)): ?>
                                <?php foreach($display_sessions as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['roll_number']) ?></td>
                                        <td>
                                            <span class="fw-bold text-primary"><?= htmlspecialchars($row['mega_exam_title']) ?></span>
                                        </td>
                                        <td><?= htmlspecialchars($row['paper_title']) ?></td>
                                        <td><?= htmlspecialchars($row['full_name']) ?></td>
                                        <td><?= htmlspecialchars($row['dept_name']) ?></td>
                                        <td>
                                            <?= htmlspecialchars($row['batch_name'] ?? 'N/A') ?>
                                            <?php if(isset($row['batch_year'])): ?>
                                                (<?= htmlspecialchars($row['batch_year']) ?>)
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($row['semester']) ?></td>
                                        <td><?= format_datetime($row['submitted_at']) ?></td>
                                        <td>
                                            <?php if($row['eval_status'] === 'completed'): ?>
                                                <span class="badge bg-success">Evaluated</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if($row['is_approved']): ?>
                                                <span class="badge bg-success">Published</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Unpublished</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if($row['eval_status'] === 'completed'): ?>
                                                <a href="evaluate_answers.php?session_id=<?= $row['id'] ?>&edit=1" class="btn btn-warning btn-sm">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                            <?php else: ?>
                                                <a href="evaluate_answers.php?session_id=<?= $row['id'] ?>" class="btn btn-primary btn-sm">
                                                    <i class="fas fa-edit"></i> Evaluate
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="11" class="text-center text-muted">No evaluation sessions found for selected exam</td>
                                </tr>
                            <?php endif; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="11" class="text-center text-muted">No evaluation sessions found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// Store all sessions data for filtering
const allSessions = <?= json_encode($all_sessions) ?>;
const sessionsByExam = <?= json_encode($sessions_by_exam) ?>;

// Store current selections
let currentMegaExamId = <?= $first_mega_exam_id ?: 'null' ?>;
let currentExamId = <?= $first_exam_id ?: 'null' ?>;

function selectMegaExam(megaExamId, element) {
    // Update active tab
    document.querySelectorAll('#megaExamTabs .nav-link').forEach(tab => {
        tab.classList.remove('active');
    });
    element.classList.add('active');
    
    currentMegaExamId = megaExamId;
    
    // Load exams for this mega exam via AJAX
    loadExamsForMegaExam(megaExamId);
}

function selectExam(examId, element) {
    // Update active tab
    document.querySelectorAll('#examTabs .nav-link').forEach(tab => {
        tab.classList.remove('active');
    });
    element.classList.add('active');
    
    currentExamId = examId;
    
    // Filter and display sessions for this exam
    filterSessionsByExam(examId);
}

function loadExamsForMegaExam(megaExamId) {
    fetch(`../app/ajax_handler.php?action=get_exams_by_mega_exam&mega_exam_id=${megaExamId}&teacher_id=<?= $teacher_id ?>`)
        .then(response => response.json())
        .then(data => {
            const examTabsContainer = document.getElementById('examTabs');
            
            if (data.success && data.exams.length > 0) {
                let tabsHtml = '';
                data.exams.forEach((exam, index) => {
                    const activeClass = index === 0 ? 'active' : '';
                    const badge = exam.is_approved ? 
                        '<span class="badge bg-success ms-1">Published</span>' : 
                        '<span class="badge bg-warning ms-1">Draft</span>';
                    
                    tabsHtml += `
                        <li class="nav-item" role="presentation">
                            <button class="nav-link ${activeClass}" 
                                    data-exam-id="${exam.id}"
                                    onclick="selectExam(${exam.id}, this)">
                                ${exam.title} ${badge}
                            </button>
                        </li>
                    `;
                });
                examTabsContainer.innerHTML = tabsHtml;
                
                // Auto-select first exam and filter sessions
                if (data.exams.length > 0) {
                    currentExamId = data.exams[0].id;
                    filterSessionsByExam(data.exams[0].id);
                }
            } else {
                examTabsContainer.innerHTML = `
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" type="button">
                            No papers found for this mega exam
                        </button>
                    </li>
                `;
                // Clear table
                document.getElementById('sessionsTableBody').innerHTML = 
                    '<tr><td colspan="11" class="text-center text-muted">No evaluation sessions found</td></tr>';
            }
        })
        .catch(error => {
            console.error('Error loading exams:', error);
        });
}

function filterSessionsByExam(examId) {
    const tableBody = document.getElementById('sessionsTableBody');
    
    // Filter sessions for the selected exam
    const filteredSessions = allSessions.filter(session => session.exam_id == examId);
    
    if (filteredSessions.length > 0) {
        let tableHtml = '';
        
        filteredSessions.forEach(session => {
            const evalStatus = session.eval_status === 'completed' ? 
                '<span class="badge bg-success">Evaluated</span>' : 
                '<span class="badge bg-warning">Pending</span>';
                
            const resultStatus = session.is_approved ? 
                '<span class="badge bg-success">Published</span>' : 
                '<span class="badge bg-secondary">Unpublished</span>';
            
            const actionBtn = session.eval_status === 'completed' ? 
                `<a href="evaluate_answers.php?session_id=${session.id}&edit=1" class="btn btn-warning btn-sm">
                    <i class="fas fa-edit"></i> Edit
                </a>` :
                `<a href="evaluate_answers.php?session_id=${session.id}" class="btn btn-primary btn-sm">
                    <i class="fas fa-edit"></i> Evaluate
                </a>`;
            
            tableHtml += `
                <tr>
                    <td>${escapeHtml(session.roll_number)}</td>
                    <td><span class="fw-bold text-primary">${escapeHtml(session.mega_exam_title)}</span></td>
                    <td>${escapeHtml(session.paper_title)}</td>
                    <td>${escapeHtml(session.full_name)}</td>
                    <td>${escapeHtml(session.dept_name)}</td>
                    <td>${escapeHtml(session.batch_name || 'N/A')} ${session.batch_year ? `(${session.batch_year})` : ''}</td>
                    <td>${escapeHtml(session.semester)}</td>
                    <td>${escapeHtml(session.submitted_at)}</td>
                    <td>${evalStatus}</td>
                    <td>${resultStatus}</td>
                    <td>${actionBtn}</td>
                </tr>
            `;
        });
        
        tableBody.innerHTML = tableHtml;
    } else {
        tableBody.innerHTML = 
            '<tr><td colspan="11" class="text-center text-muted">No evaluation sessions found for selected exam</td></tr>';
    }
}

// Helper function to escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Initialize with first mega exam and exam
document.addEventListener('DOMContentLoaded', function() {
    if (currentMegaExamId && currentExamId) {
        filterSessionsByExam(currentExamId);
    }
});
</script>

<?php include '../templates/footer.php'; ?>
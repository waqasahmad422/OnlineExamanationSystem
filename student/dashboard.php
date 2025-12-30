<?php
require_once '../app/config.php';
require_once '../app/helpers.php';
require_once '../app/auth.php';

require_role(['student']);

// Set correct timezone (matching available exams page)
date_default_timezone_set('Asia/Karachi');

$page_title = 'Student Dashboard';
$student_id = intval($_SESSION['user_id']);
$department_id = intval($_SESSION['department_id']);
$batch_id = intval($_SESSION['batch_id']);
$semester = intval($_SESSION['semester']);

// AVAILABLE EXAMS: Count exams that are currently accessible
$available_query = mysqli_prepare($conn, "
    SELECT COUNT(*) as total 
    FROM exams e
    WHERE e.department_id = ? 
    AND e.batch_id = ? 
    AND e.semester = ? 
    AND e.is_approved = 1 
    AND (
        -- Exams with time window: must be within start and end times
        (e.start_datetime IS NOT NULL 
         AND e.end_datetime IS NOT NULL 
         AND NOW() BETWEEN e.start_datetime AND e.end_datetime)
        OR 
        -- Exams without time restrictions: always available
        (e.start_datetime IS NULL OR e.end_datetime IS NULL)
    )
    AND NOT EXISTS (
        SELECT 1 
        FROM student_exam_sessions ses 
        WHERE ses.exam_id = e.id 
        AND ses.student_id = ? 
        AND ses.status = 'completed'
    )
");
mysqli_stmt_bind_param($available_query, "iiii", $department_id, $batch_id, $semester, $student_id);
mysqli_stmt_execute($available_query);
$available_result = mysqli_stmt_get_result($available_query);
$stats['available'] = mysqli_fetch_assoc($available_result)['total'];

// UPCOMING EXAMS: Count exams with future start dates (time window only)
$upcoming_query = mysqli_prepare($conn, "
    SELECT COUNT(*) as total 
    FROM exams e
    WHERE e.department_id = ? 
    AND e.batch_id = ? 
    AND e.semester = ? 
    AND e.is_approved = 1 
    AND e.start_datetime IS NOT NULL 
    AND e.end_datetime IS NOT NULL
    AND e.start_datetime > NOW()
    AND NOT EXISTS (
        SELECT 1 
        FROM student_exam_sessions ses 
        WHERE ses.exam_id = e.id 
        AND ses.student_id = ? 
        AND ses.status = 'completed'
    )
");
mysqli_stmt_bind_param($upcoming_query, "iiii", $department_id, $batch_id, $semester, $student_id);
mysqli_stmt_execute($upcoming_query);
$upcoming_result = mysqli_stmt_get_result($upcoming_query);
$stats['upcoming'] = mysqli_fetch_assoc($upcoming_result)['total'];

// EXPIRED/MISSED EXAMS: Count exams with past end dates (time window only)
$expired_query = mysqli_prepare($conn, "
    SELECT COUNT(*) as total 
    FROM exams e
    WHERE e.department_id = ? 
    AND e.batch_id = ? 
    AND e.semester = ? 
    AND e.is_approved = 1 
    AND e.end_datetime IS NOT NULL 
    AND e.end_datetime < NOW()
    AND NOT EXISTS (
        SELECT 1 
        FROM student_exam_sessions ses 
        WHERE ses.exam_id = e.id 
        AND ses.student_id = ? 
        AND ses.status = 'completed'
    )
");
mysqli_stmt_bind_param($expired_query, "iiii", $department_id, $batch_id, $semester, $student_id);
mysqli_stmt_execute($expired_query);
$expired_result = mysqli_stmt_get_result($expired_query);
$stats['expired'] = mysqli_fetch_assoc($expired_result)['total'];

// COMPLETED EXAMS: Count exams student has completed
$completed_query = mysqli_prepare($conn, "
    SELECT COUNT(*) as total 
    FROM student_exam_sessions ses
    JOIN exams e ON ses.exam_id = e.id
    WHERE ses.student_id = ? 
    AND ses.status = 'completed'
    AND e.department_id = ?
    AND e.batch_id = ?
    AND e.semester = ?
    AND e.is_approved = 1
");
mysqli_stmt_bind_param($completed_query, "iiii", $student_id, $department_id, $batch_id, $semester);
mysqli_stmt_execute($completed_query);
$completed_result = mysqli_stmt_get_result($completed_query);
$stats['completed'] = mysqli_fetch_assoc($completed_result)['total'];

// PUBLISHED RESULTS: Count completed evaluations
$results_query = mysqli_prepare($conn, "
    SELECT COUNT(*) as total 
    FROM student_exam_sessions ses
    JOIN exams e ON ses.exam_id = e.id 
    JOIN evaluations ev ON ev.session_id = ses.id
    WHERE ses.student_id = ? 
    AND ses.status = 'completed'
    AND e.department_id = ?
    AND e.batch_id = ?
    AND e.semester = ?
    AND e.is_approved = 1
    AND ev.status = 'completed'
");
mysqli_stmt_bind_param($results_query, "iiii", $student_id, $department_id, $batch_id, $semester);
mysqli_stmt_execute($results_query);
$results_result = mysqli_stmt_get_result($results_query);
$stats['results'] = mysqli_fetch_assoc($results_result)['total'];

// RECENT ACTIVITY
$recent_query = mysqli_prepare($conn, "
    SELECT e.title, ses.started_at, ses.status 
    FROM student_exam_sessions ses 
    JOIN exams e ON ses.exam_id = e.id 
    WHERE ses.student_id = ? 
    AND e.department_id = ?
    AND e.batch_id = ?
    AND e.semester = ?
    ORDER BY ses.started_at DESC 
    LIMIT 5
");
mysqli_stmt_bind_param($recent_query, "iiii", $student_id, $department_id, $batch_id, $semester);
mysqli_stmt_execute($recent_query);
$recent = mysqli_stmt_get_result($recent_query);

include '../templates/header.php';
include '../templates/sidebar_student.php';
?>

<div class="main-content">
    <div class="top-navbar">
        <h4 class="mb-0"><i class="fas fa-tachometer-alt"></i> Student Dashboard</h4>
        <span class="text-muted">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
    </div>
    
    <div class="content-area">
        <!-- First Row of Cards -->
        <div class="row">
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-card-icon" style="background: #e3f2fd; color: #2196f3;">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <h3><?php echo $stats['available']; ?></h3>
                    <p>Available Exams</p>
                    <small class="text-muted">Exams you can take now</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-card-icon" style="background: #fff3e0; color: #ff9800;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <h3><?php echo $stats['upcoming']; ?></h3>
                    <p>Upcoming Exams</p>
                    <small class="text-muted">Starting soon</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-card-icon" style="background: #ffebee; color: #f44336;">
                        <i class="fas fa-hourglass-end"></i>
                    </div>
                    <h3><?php echo $stats['expired']; ?></h3>
                    <p>Expired/Missed</p>
                    <small class="text-muted">Time window passed</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-card-icon" style="background: #e8f5e9; color: #4caf50;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3><?php echo $stats['completed']; ?></h3>
                    <p>Completed Exams</p>
                    <small class="text-muted">Successfully submitted</small>
                </div>
            </div>
        </div>
        
        <!-- Second Row of Cards -->
        <div class="row mt-3">
            <div class="col-md-6">
                <div class="stats-card">
                    <div class="stats-card-icon" style="background: #f3e5f5; color: #9c27b0;">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <h3><?php echo $stats['results']; ?></h3>
                    <p>Published Results</p>
                    <small class="text-muted">Evaluations completed</small>
                </div>
            </div>
            <div class="col-md-6">
                <div class="stats-card">
                    <div class="stats-card-icon" style="background: #e0f2f1; color: #009688;">
                        <i class="fas fa-percentage"></i>
                    </div>
                    <h3>
                        <?php 
                        // Calculate completion rate
                        $total_exams = $stats['available'] + $stats['upcoming'] + $stats['expired'] + $stats['completed'];
                        if ($total_exams > 0) {
                            $completion_rate = round(($stats['completed'] / $total_exams) * 100);
                        } else {
                            $completion_rate = 0;
                        }
                        echo $completion_rate . '%';
                        ?>
                    </h3>
                    <p>Completion Rate</p>
                    <small class="text-muted"><?php echo $stats['completed']; ?> of <?php echo $total_exams; ?> exams</small>
                </div>
            </div>
        </div>
        
        <!-- Recent Activity Table -->
        <div class="card mt-4">
            <div class="card-header">
                <i class="fas fa-history me-2"></i>Recent Activity
            </div>
            <div class="card-body">
                <?php if (mysqli_num_rows($recent) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Exam Title</th>
                                <th>Started At</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($rec = mysqli_fetch_assoc($recent)): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($rec['title']); ?></td>
                                <td>
                                    <?php 
                                    if ($rec['started_at']) {
                                        echo date('M d, Y g:i A', strtotime($rec['started_at']));
                                    } else {
                                        echo 'Not started';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    $badge_class = '';
                                    $status_text = '';
                                    switch($rec['status']) {
                                        case 'completed': 
                                            $badge_class = 'bg-success';
                                            $status_text = 'Completed';
                                            break;
                                        case 'in_progress': 
                                            $badge_class = 'bg-warning';
                                            $status_text = 'In Progress';
                                            break;
                                        case 'auto_submitted': 
                                            $badge_class = 'bg-info';
                                            $status_text = 'Auto Submitted';
                                            break;
                                        default: 
                                            $badge_class = 'bg-secondary';
                                            $status_text = ucfirst(str_replace('_', ' ', $rec['status']));
                                    }
                                    ?>
                                    <span class="badge <?php echo $badge_class; ?>">
                                        <?php echo $status_text; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($rec['status'] == 'completed'): ?>
                                        <a href="results.php" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    <?php elseif ($rec['status'] == 'in_progress'): ?>
                                        <a href="take_exam.php" class="btn btn-sm btn-outline-warning">
                                            <i class="fas fa-play"></i> Resume
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">No action</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-clipboard-list fa-3x mb-3"></i>
                    <p class="mb-0">No exam activity yet.</p>
                    <small>Start by taking an available exam!</small>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Quick Stats Summary -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-chart-pie me-2"></i>Exam Summary
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-3 border-end">
                                <h6 class="text-muted">Total Exams</h6>
                                <h4><?php echo $total_exams; ?></h4>
                            </div>
                            <div class="col-md-3 border-end">
                                <h6 class="text-muted">Pending</h6>
                                <h4><?php echo $stats['available'] + $stats['upcoming']; ?></h4>
                            </div>
                            <div class="col-md-3 border-end">
                                <h6 class="text-muted">Missed</h6>
                                <h4><?php echo $stats['expired']; ?></h4>
                            </div>
                            <div class="col-md-3">
                                <h6 class="text-muted">Completion Rate</h6>
                                <h4 class="text-success"><?php echo $completion_rate; ?>%</h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
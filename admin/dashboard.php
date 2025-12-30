<?php
require_once '../app/config.php';
require_once '../app/helpers.php';
require_once '../app/auth.php';

require_role(['admin']);
check_session_validity();

$page_title = 'Admin Dashboard';

// Basic statistics
$stats = [];
$stmt = mysqli_query($conn, "SELECT COUNT(*) as total FROM users WHERE role = 'student'");
$stats['students'] = mysqli_fetch_assoc($stmt)['total'];

$stmt = mysqli_query($conn, "SELECT COUNT(*) as total FROM users WHERE role = 'teacher'");
$stats['teachers'] = mysqli_fetch_assoc($stmt)['total'];

$stmt = mysqli_query($conn, "SELECT COUNT(*) as total FROM exams");
$stats['papers'] = mysqli_fetch_assoc($stmt)['total'];

$stmt = mysqli_query($conn, "SELECT COUNT(*) as total FROM exams WHERE is_approved = 0");
$stats['pending_approvals'] = mysqli_fetch_assoc($stmt)['total'];

// Mega Exam count
$stmt = mysqli_query($conn, "SELECT COUNT(*) as total FROM mega_exams");
$stats['mega_exams'] = mysqli_fetch_assoc($stmt)['total'];

// Today's activity stats
$today = date('Y-m-d');
$stmt = mysqli_query($conn, "SELECT COUNT(*) as total FROM exams WHERE DATE(created_at) = '$today'");
$stats['today_papers'] = mysqli_fetch_assoc($stmt)['total'];

$stmt = mysqli_query($conn, "SELECT COUNT(*) as total FROM student_exam_sessions WHERE DATE(started_at) = '$today'");
$stats['today_attempts'] = mysqli_fetch_assoc($stmt)['total'];

// System alerts - exams starting soon (within 24 hours)
$tomorrow = date('Y-m-d H:i:s', strtotime('+1 day'));
$stmt = mysqli_query($conn, "
    SELECT COUNT(*) as total 
    FROM exams 
    WHERE is_approved = 1 
    AND start_datetime BETWEEN NOW() AND '$tomorrow'
    AND start_datetime > NOW()
");
$stats['starting_soon'] = mysqli_fetch_assoc($stmt)['total'];

// Active exams right now
$stmt = mysqli_query($conn, "
    SELECT COUNT(*) as total 
    FROM exams 
    WHERE is_approved = 1 
    AND NOW() BETWEEN start_datetime AND end_datetime
");
$stats['active_now'] = mysqli_fetch_assoc($stmt)['total'];

// Recent results with performance data - improved query with better filtering
$recent_results_query = mysqli_query($conn, "
    SELECT 
        e.id as exam_id,
        e.title as exam_title,
        d.name as department_name,
        b.name as batch_name,
        e.semester,
        u.full_name as teacher_name,
        e.is_approved,
        e.start_datetime,
        e.end_datetime,
        me.title as mega_exam_title,
        me.mega_exam_code,
        e.total_marks,
        e.passing_marks,
        
        -- Student count and performance
        COUNT(DISTINCT ses.student_id) as total_students,
        COUNT(DISTINCT CASE WHEN ses.status IN ('completed', 'auto_submitted') THEN ses.student_id END) as attempted_students,
        
        -- Result statistics
        COUNT(DISTINCT ev.id) as evaluated_count,
        AVG(CASE WHEN ev.status = 'completed' THEN (ev.marks_obtained / ev.total_marks * 100) ELSE NULL END) as avg_percentage,
        SUM(CASE WHEN ev.status = 'completed' AND (ev.marks_obtained / ev.total_marks * 100) >= 35 THEN 1 ELSE 0 END) as passed_students,
        SUM(CASE WHEN ev.status = 'completed' AND (ev.marks_obtained / ev.total_marks * 100) < 35 THEN 1 ELSE 0 END) as failed_students,
        
        -- Exam status for quick identification
        CASE 
            WHEN NOW() BETWEEN e.start_datetime AND e.end_datetime THEN 'active'
            WHEN e.end_datetime < NOW() THEN 'completed' 
            ELSE 'upcoming'
        END as exam_status
        
    FROM exams e
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN batches b ON e.batch_id = b.id
    LEFT JOIN users u ON e.teacher_id = u.id
    LEFT JOIN mega_exams me ON e.mega_exam_id = me.id
    LEFT JOIN student_exam_sessions ses ON e.id = ses.exam_id
    LEFT JOIN evaluations ev ON ses.id = ev.session_id AND ev.status = 'completed'
    
    WHERE e.is_approved = 1  -- Only show approved exams for better relevance
    
    GROUP BY e.id, e.title, d.name, b.name, e.semester, u.full_name, e.is_approved, 
             e.start_datetime, e.end_datetime, me.title, me.mega_exam_code, e.total_marks, e.passing_marks
             
    ORDER BY 
        CASE 
            WHEN NOW() BETWEEN e.start_datetime AND e.end_datetime THEN 1  -- Active first
            WHEN e.start_datetime > NOW() THEN 2  -- Upcoming next
            ELSE 3  -- Completed last
        END,
        e.created_at DESC 
    LIMIT 8
");

$recent_results = [];
while ($row = mysqli_fetch_assoc($recent_results_query)) {
    $recent_results[] = $row;
}

// Quick alerts for admin
$alerts = [];

// Check for exams needing immediate attention
$urgent_approvals = mysqli_query($conn, "
    SELECT COUNT(*) as count 
    FROM exams 
    WHERE is_approved = 0 
    AND start_datetime <= DATE_ADD(NOW(), INTERVAL 1 DAY)
");
$urgent_count = mysqli_fetch_assoc($urgent_approvals)['count'];
if ($urgent_count > 0) {
    $alerts[] = [
        'type' => 'warning',
        'message' => "$urgent_count exams need approval within 24 hours",
        'link' => './exam_approvals.php'
    ];
}

// Check for active exams
if ($stats['active_now'] > 0) {
    $active_count = $stats['active_now'];
    $alerts[] = [
        'type' => 'info',
        'message' => "$active_count exams are live right now",
        'link' => './exams.php?status=active'
    ];
}

// Check for exams starting soon
if ($stats['starting_soon'] > 0) {
    $starting_count = $stats['starting_soon'];
    $alerts[] = [
        'type' => 'primary',
        'message' => "$starting_count exams starting within 24 hours",
        'link' => './exams.php?status=upcoming'
    ];
}

include '../templates/header.php';
include '../templates/sidebar_admin.php';
?>

<div class="main-content">
    <div class="top-navbar">
        <h4 class="mb-0"><i class="fas fa-tachometer-alt"></i> Admin Dashboard</h4>
        <div>
            <span class="text-muted">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
            <span class="badge bg-success ms-2">
                <i class="fas fa-circle me-1"></i> Live
            </span>
        </div>
    </div>

    <div class="content-area">
        <!-- Admin Alerts -->
        <?php if (!empty($alerts)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="alert-container">
                    <?php foreach ($alerts as $alert): ?>
                    <div class="alert alert-<?= $alert['type'] ?> alert-dismissible fade show mb-2" role="alert">
                        <i class="fas fa-bell me-2"></i>
                        <?= $alert['message'] ?>
                        <a href="<?= $alert['link'] ?>" class="alert-link ms-2">View →</a>
                        <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Basic Statistics Row with Clickable Cards -->
        <div class="row">
            <!-- Students Card -->
            <div class="col-md-4">
                <a href="./manage_student_users.php" class="card-link" style="text-decoration: none;">
                    <div class="stats-card clickable-card">
                        <div class="stats-card-icon" style="background: #e3f2fd; color: #2196f3;">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <h3><?php echo $stats['students']; ?></h3>
                        <p>Total Students</p>
                        <?php if ($stats['today_attempts'] > 0): ?>
                        <small class="text-success">
                            <i class="fas fa-arrow-up"></i> <?= $stats['today_attempts'] ?> attempts today
                        </small>
                        <?php endif; ?>
                        
                    </div>
                </a>
            </div>
            
            <!-- Teachers Card -->
            <div class="col-md-4">
                <a href="./manage_teacher_users.php" class="card-link" style="text-decoration: none;">
                    <div class="stats-card clickable-card">
                        <div class="stats-card-icon" style="background: #f3e5f5; color: #9c27b0;">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                        <h3><?php echo $stats['teachers']; ?></h3>
                        <p>Total Teachers</p>
                       
                    </div>
                </a>
            </div>
            
            <!-- Mega Exams Card -->
            <div class="col-md-4">
                <a href="./mega_exams.php" class="card-link" style="text-decoration: none;">
                    <div class="stats-card clickable-card">
                        <div class="stats-card-icon" style="background: #e1f5fe; color: #0288d1;">
                            <i class="fas fa-layer-group"></i>
                        </div>
                        <h3><?php echo $stats['mega_exams']; ?></h3>
                        <p>Mega Exams</p>
                       
                    </div>
                </a>
            </div>            
        </div>

        <!-- Second Row with Additional Cards -->
        <div class="row mt-4">
            <!-- Pending Approvals Card -->
            <div class="col-md-4">
                <a href="./exam_approvals.php" class="card-link" style="text-decoration: none;">
                    <div class="stats-card clickable-card">
                        <div class="stats-card-icon" style="background: #fff3e0; color: #ff9800;">
                            <i class="fas fa-clock"></i>
                        </div>
                        <h3><?php echo $stats['pending_approvals']; ?></h3>
                        <p>Pending Approvals</p>
                        <?php if ($urgent_count > 0): ?>
                        <small class="text-danger">
                            <i class="fas fa-exclamation-triangle"></i> <?= $urgent_count ?> urgent
                        </small>
                        <?php endif; ?>
                        
                    </div>
                </a>
            </div>
            
            
            <!-- Results Card -->
            <div class="col-md-4">
                <a href="./results.php" class="card-link" style="text-decoration: none;">
                    <div class="stats-card clickable-card">
                        <div class="stats-card-icon" style="background: #f0f4ff; color: #673ab7;">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                        <h3><?php echo count($recent_results); ?></h3>
                        <p>Recent Results</p>
                        
                    </div>
                </a>
            </div>
        </div>

        <div class="row mt-4">
            <!-- Recent Papers with Enhanced Features -->
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-list"></i> Recent Exam Papers</span>
                        <div>
                            <div class="btn-group btn-group-sm">
                                <a href="./exam_approvals.php" class="btn btn-outline-warning">
                                    <i class="fas fa-tasks"></i> Approvals (<?= $stats['pending_approvals'] ?>)
                                </a>
                                <a href="./mega_exams.php" class="btn btn-outline-info">
                                    <i class="fas fa-layer-group"></i> Mega Exams
                                </a>
                                <a href="./exams.php" class="btn btn-outline-primary">View All</a>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Paper Title</th>
                                        <th>Department</th>
                                        <th>Status</th>
                                        <th>Progress</th>
                                        <th>Performance</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_results as $paper): 
                                        $attempted_percentage = $paper['total_students'] > 0 ? 
                                            ($paper['attempted_students'] / $paper['total_students']) * 100 : 0;
                                        
                                        $pass_percentage = $paper['evaluated_count'] > 0 ? 
                                            ($paper['passed_students'] / $paper['evaluated_count']) * 100 : 0;
                                        
                                        $avg_percentage = $paper['avg_percentage'] ? number_format($paper['avg_percentage'], 1) : 'N/A';
                                        
                                        // Status badge with better visual cues
                                        $status_badge = '';
                                        if ($paper['exam_status'] == 'active') {
                                            $status_badge = '<span class="badge bg-success"><i class="fas fa-play me-1"></i>Live</span>';
                                        } elseif ($paper['exam_status'] == 'upcoming') {
                                            $status_badge = '<span class="badge bg-info"><i class="fas fa-clock me-1"></i>Upcoming</span>';
                                        } else {
                                            $status_badge = '<span class="badge bg-secondary"><i class="fas fa-check me-1"></i>Completed</span>';
                                        }
                                    ?>
                                        <tr>
                                            <td>
                                                <div>
                                                    <strong class="d-block"><?= htmlspecialchars($paper['exam_title']); ?></strong>
                                                    <small class="text-muted">
                                                        Sem <?= $paper['semester']; ?> • 
                                                        <?= htmlspecialchars($paper['batch_name']); ?>
                                                    </small>
                                                    <?php if (!empty($paper['mega_exam_title'])): ?>
                                                    <br>
                                                    <small class="text-primary">
                                                        <i class="fas fa-layer-group me-1"></i>
                                                        <?= htmlspecialchars($paper['mega_exam_title']) ?>
                                                    </small>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($paper['department_name']); ?>
                                                <br>
                                                <small class="text-muted">By: <?= htmlspecialchars($paper['teacher_name']); ?></small>
                                            </td>
                                            <td>
                                                <?= $status_badge ?>
                                                <br>
                                                <small class="text-muted">
                                                    <?= date('M d', strtotime($paper['start_datetime'])) ?> - 
                                                    <?= date('M d', strtotime($paper['end_datetime'])) ?>
                                                </small>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="progress flex-grow-1 me-2" style="height: 8px;">
                                                        <div class="progress-bar bg-primary" 
                                                             style="width: <?= $attempted_percentage ?>%"
                                                             title="<?= number_format($attempted_percentage, 1) ?>% attempted">
                                                        </div>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?= $paper['attempted_students'] ?>/<?= $paper['total_students'] ?>
                                                    </small>
                                                </div>
                                                <small class="text-muted d-block mt-1">
                                                    <?= number_format($attempted_percentage, 1) ?>% attempted
                                                </small>
                                            </td>
                                            <td>
                                                <?php if ($paper['evaluated_count'] > 0): ?>
                                                    <div class="text-center">
                                                        <span class="fw-bold d-block <?= ($paper['avg_percentage'] >= 35) ? 'text-success' : 'text-danger' ?>">
                                                            <?= $avg_percentage ?>%
                                                        </span>
                                                        <small class="text-muted">
                                                            <?= number_format($pass_percentage, 1) ?>% pass
                                                        </small>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted">No data</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="./exam_approvals.php?exam_id=<?= $paper['exam_id'] ?>" 
                                                       class="btn btn-outline-primary" title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if ($paper['evaluated_count'] > 0): ?>
                                                        <a href="./results.php?exam_id=<?= $paper['exam_id'] ?>" 
                                                           class="btn btn-outline-success" title="View Results">
                                                            <i class="fas fa-chart-bar"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($paper['exam_status'] == 'active'): ?>
                                                        <a href="./monitor_exam.php?exam_id=<?= $paper['exam_id'] ?>" 
                                                           class="btn btn-outline-warning" title="Monitor Live">
                                                            <i class="fas fa-desktop"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Clickable card styles */
.card-link {
    transition: transform 0.2s ease;
}

.card-link:hover {
    text-decoration: none;
    transform: translateY(-5px);
}

.stats-card.clickable-card {
    cursor: pointer;
    transition: all 0.3s ease;
    border: 2px solid transparent;
    position: relative;
    overflow: hidden;
}

.stats-card.clickable-card:hover {
    border-color: rgba(0, 123, 255, 0.3);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
}

.stats-card.clickable-card .card-hover-text {
    position: absolute;
    bottom: 10px;
    right: 15px;
    font-size: 12px;
    color: #0d6efd;
    opacity: 0;
    transform: translateY(10px);
    transition: all 0.3s ease;
}

.stats-card.clickable-card:hover .card-hover-text {
    opacity: 1;
    transform: translateY(0);
}

.stats-card.clickable-card .stats-card-icon {
    transition: all 0.3s ease;
}

.stats-card.clickable-card:hover .stats-card-icon {
    transform: scale(1.1);
}
</style>

<?php include '../templates/footer.php'; ?>
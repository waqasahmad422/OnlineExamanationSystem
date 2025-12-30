<?php
require_once '../app/config.php';
require_once '../app/helpers.php';
require_once '../app/auth.php';

require_role(['teacher']);

$page_title = 'Teacher Dashboard';
$teacher_id = $_SESSION['user_id'];

/* ===== DASHBOARD STATS ===== */
$my_exams = mysqli_query($conn, "SELECT COUNT(*) as total FROM exams WHERE teacher_id = $teacher_id");
$stats['my_exams'] = mysqli_fetch_assoc($my_exams)['total'];

$pending = mysqli_query($conn, "SELECT COUNT(DISTINCT ses.id) as total 
    FROM student_exam_sessions ses 
    JOIN exams e ON ses.exam_id = e.id 
    WHERE e.teacher_id = $teacher_id 
    AND ses.status = 'completed' 
    AND NOT EXISTS (
        SELECT 1 FROM evaluations ev 
        WHERE ev.session_id = ses.id 
        AND ev.status = 'completed'
    )");

$stats['pending_eval'] = mysqli_fetch_assoc($pending)['total'];

/* ===== FETCH EXAMS WITH FULL DETAILS ===== */
$exams = mysqli_query($conn, "
    SELECT 
        e.*, 
        d.name AS dept_name, 
        b.name AS batch_name 
    FROM exams e
    JOIN departments d ON e.department_id = d.id
    JOIN batches b ON e.batch_id = b.id
    WHERE e.teacher_id = $teacher_id
    ORDER BY e.created_at DESC
    LIMIT 10
");

include '../templates/header.php';
include '../templates/sidebar_teacher.php';
?>

<div class="main-content">
    <div class="top-navbar">
        <h4 class="mb-0"><i class="fas fa-tachometer-alt"></i> Teacher Dashboard</h4>
        <span class="text-muted">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
    </div>
    
    <div class="content-area">
        <div class="row">
            <div class="col-md-6">
                <div class="stats-card">
                    <div class="stats-card-icon" style="background: #e3f2fd; color: #2196f3;">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <h3><?php echo $stats['my_exams']; ?></h3>
                    <p>My Papers</p>
                </div>
            </div>

            <div class="col-md-6">
                <div class="stats-card">
                    <div class="stats-card-icon" style="background: #fff3e0; color: #ff9800;">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <h3><?php echo $stats['pending_eval']; ?></h3>
                    <p>Pending Evaluations</p>
                </div>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">My Papers</div>
            <div class="card-body">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Paper Title</th>
                            <th>Department</th>
                            <th>Batch</th>
                            <th>Semester</th>
                            <th>Start Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php while ($exam = mysqli_fetch_assoc($exams)): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($exam['title']); ?></td>
                            <td><?php echo htmlspecialchars($exam['dept_name']); ?></td>
                            <td><?php echo htmlspecialchars($exam['batch_name']); ?></td>
                            <td><?php echo htmlspecialchars($exam['semester']); ?></td>
                            <td><?php echo format_datetime($exam['start_datetime']); ?></td>

                            <td>
                                <?php if ($exam['is_approved']): ?>
                                    <span class="badge bg-success">Approved</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">Pending</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <a href="./manage_sections.php?exam_id=<?php echo $exam['id']; ?>" 
                                   class="btn btn-sm btn-primary">Manage</a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<?php include '../templates/footer.php'; ?>
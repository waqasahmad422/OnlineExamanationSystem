<?php
require_once '../app/config.php';
require_once '../app/helpers.php';
require_once '../app/auth.php';

require_role(['student']);

$page_title = 'My Results';
$student_id = $_SESSION['user_id'];

// FIXED: Modified query to include mega exam information and group by mega exam
$results_query = mysqli_prepare($conn, "
    SELECT 
        ses.id AS session_id,
        e.id AS exam_id,
        e.title AS exam_title,
        e.total_marks AS exam_total_marks,
        ev.marks_obtained,
        ev.total_marks AS evaluation_total,
        e.passing_marks,
        ev.status,
        e.is_approved,
        ev.evaluated_at,
        t.full_name AS teacher_name,
        me.id AS mega_exam_id,
        me.title AS mega_exam_title,
        me.mega_exam_code,
        -- Calculate percentage for status determination
        (ev.marks_obtained / ev.total_marks * 100) AS percentage
    FROM student_exam_sessions ses
    JOIN exams e ON ses.exam_id = e.id
    JOIN evaluations ev ON ev.session_id = ses.id
    JOIN users t ON e.teacher_id = t.id
    LEFT JOIN mega_exams me ON e.mega_exam_id = me.id
    WHERE ses.student_id = ? 
    AND ev.status = 'completed'  -- Only show completed evaluations
    ORDER BY 
        me.created_at DESC,      -- Show recent mega exams first
        e.is_approved DESC,      -- Show approved results first
        ev.evaluated_at DESC
");
mysqli_stmt_bind_param($results_query, "i", $student_id);
mysqli_stmt_execute($results_query);
$results = mysqli_stmt_get_result($results_query);

// Organize results by mega exam
$results_by_mega_exam = [];
$all_results = [];

while ($result = mysqli_fetch_assoc($results)) {
    $mega_exam_id = $result['mega_exam_id'] ?? 'no_mega_exam';
    $mega_exam_title = $result['mega_exam_title'] ?? 'General Papers';
    $mega_exam_code = $result['mega_exam_code'] ?? '';
    
    if (!isset($results_by_mega_exam[$mega_exam_id])) {
        $results_by_mega_exam[$mega_exam_id] = [
            'mega_exam_title' => $mega_exam_title,
            'mega_exam_code' => $mega_exam_code,
            'papers' => [],
            'total_papers' => 0,
            'total_obtained' => 0,
            'total_marks' => 0
        ];
    }
    
    $results_by_mega_exam[$mega_exam_id]['papers'][] = $result;
    $results_by_mega_exam[$mega_exam_id]['total_papers']++;
    $results_by_mega_exam[$mega_exam_id]['total_obtained'] += $result['marks_obtained'];
    $results_by_mega_exam[$mega_exam_id]['total_marks'] += $result['evaluation_total'];
    
    $all_results[] = $result;
}

// Calculate overall percentages for each mega exam
foreach ($results_by_mega_exam as $mega_exam_id => &$mega_exam_data) {
    $mega_exam_data['overall_percentage'] = $mega_exam_data['total_marks'] > 0 ? 
        ($mega_exam_data['total_obtained'] / $mega_exam_data['total_marks'] * 100) : 0;
}

include '../templates/header.php';
include '../templates/sidebar_student.php';
?>

<div class="main-content">
    <div class="top-navbar">
        <h4 class="mb-0"><i class="fas fa-chart-bar"></i> My Results</h4>
    </div>
    
    <div class="content-area">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>My Results by Mega Exam</span>
                <div>
                    <span class="badge bg-success me-2">
                        <i class="fas fa-check-circle"></i> Official
                    </span>
                    <span class="badge bg-warning text-dark">
                        <i class="fas fa-clock"></i> Unofficial
                    </span>
                </div>
            </div>
            <div class="card-body">
                <?php if (count($all_results) > 0): ?>
                
                    <?php foreach ($results_by_mega_exam as $mega_exam_id => $mega_exam_data): 
                        $mega_exam_title = $mega_exam_data['mega_exam_title'];
                        $mega_exam_code = $mega_exam_data['mega_exam_code'];
                        $papers = $mega_exam_data['papers'];
                        $overall_percentage = $mega_exam_data['overall_percentage'];
                    ?>
                    
                    <!-- Mega Exam Header -->
                    <div class="mega-exam-section mb-5">
                        <div class="mega-exam-header bg-light p-3 rounded mb-3">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <h5 class="mb-1 text-primary">
                                        <i class="fas fa-layer-group"></i>
                                        <?= htmlspecialchars($mega_exam_title) ?>
                                        <?php if (!empty($mega_exam_code)): ?>
                                            <small class="text-muted">(<?= htmlspecialchars($mega_exam_code) ?>)</small>
                                        <?php endif; ?>
                                    </h5>
                                    <p class="mb-0 text-muted">
                                        <?= count($papers) ?> Paper<?= count($papers) > 1 ? 's' : '' ?> | 
                                        Overall Performance: 
                                        <span class="fw-bold <?= $overall_percentage >= 35 ? 'text-success' : 'text-danger' ?>">
                                            <?= number_format($overall_percentage, 2) ?>%
                                        </span>
                                    </p>
                                </div>
                                <div class="col-md-4 text-end">
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar <?= $overall_percentage >= 35 ? 'bg-success' : 'bg-danger' ?>" 
                                             style="width: <?= min($overall_percentage, 100) ?>%"
                                             role="progressbar">
                                            <span class="fw-bold"><?= number_format($overall_percentage, 1) ?>%</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Papers Table for this Mega Exam -->
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Paper</th>
                                        <th>Teacher</th>
                                        <th>Obtained Marks</th>
                                        <th>Total Marks</th>
                                        <th>Percentage</th>
                                        <th>Status</th>
                                        <th>Result Type</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($papers as $result): 
                                        $percentage = floatval($result['percentage']);
                                        
                                        // Fail if percentage less than 35%
                                        $is_pass = ($percentage >= 35);
                                        
                                        // Calculate grade
                                        if ($percentage >= 80) {
                                            $grade = 'A+';
                                            $grade_class = 'bg-success';
                                        } elseif ($percentage >= 70) {
                                            $grade = 'A';
                                            $grade_class = 'bg-primary';
                                        } elseif ($percentage >= 60) {
                                            $grade = 'B';
                                            $grade_class = 'bg-info';
                                        } elseif ($percentage >= 50) {
                                            $grade = 'C';
                                            $grade_class = 'bg-warning';
                                        } elseif ($percentage >= 35) {
                                            $grade = 'D';
                                            $grade_class = 'bg-secondary';
                                        } else {
                                            $grade = 'F';
                                            $grade_class = 'bg-danger';
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($result['exam_title']); ?></strong>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?php echo htmlspecialchars($result['teacher_name']); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary fs-6">
                                                <?php echo number_format($result['marks_obtained'], 2); ?>
                                            </span>
                                        </td>
                                        <td><?php echo number_format($result['evaluation_total'], 2); ?></td>
                                        <td>
                                            <span class="fw-bold <?= $percentage >= 35 ? 'text-success' : 'text-danger' ?>">
                                                <?php echo number_format($percentage, 2); ?>%
                                            </span>
                                            <br>
                                            <span class="badge <?php echo $grade_class; ?> small"><?php echo $grade; ?></span>
                                        </td>
                                        <td>
                                            <?php if ($is_pass): ?>
                                                <span class="badge bg-success">PASS</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">FAIL</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($result['is_approved']): ?>
                                                <span class="badge bg-success">
                                                    <i class="fas fa-check-circle"></i> Official
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">
                                                    <i class="fas fa-clock"></i> Unofficial
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small>
                                                <?php echo date('M d, Y', strtotime($result['evaluated_at'])); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <!-- Always show DMC button but indicate if unofficial -->
                                            <a href="print_dmc.php?session_id=<?php echo $result['session_id']; ?>" 
                                               class="btn btn-sm btn-outline-primary" target="_blank"
                                               title="<?= $result['is_approved'] ? 'Official Result' : 'Unofficial - Pending Approval' ?>">
                                                <i class="fas fa-print"></i> DMC
                                                <?php if (!$result['is_approved']): ?>
                                                    <small class="text-warning">*</small>
                                                <?php endif; ?>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <?php endforeach; ?>
                
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-file-invoice fa-4x text-muted mb-3"></i>
                        <h5 class="text-muted">No Results Available</h5>
                        <p class="text-muted mb-4">Your results will appear here once they are evaluated by your teacher.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.mega-exam-header {
    border-left: 4px solid #007bff;
}
.progress {
    background-color: #e9ecef;
    border-radius: 10px;
    overflow: hidden;
}
.progress-bar {
    border-radius: 10px;
    font-size: 0.8rem;
}
.mega-exam-section {
    border-bottom: 2px solid #dee2e6;
    padding-bottom: 2rem;
}
.mega-exam-section:last-child {
    border-bottom: none;
    padding-bottom: 0;
}
</style>

<?php include '../templates/footer.php'; ?>
<?php
require_once '../app/config.php';
require_once '../app/helpers.php';
require_once '../app/auth.php';
require_once '../app/admin_handlers.php';

require_role(['admin']);

$page_title = 'Paper Approvals';

// Handle POST Approvals
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'])) {

    if (isset($_POST['approve_paper'])) {
        $paper_id = intval($_POST['paper_id']);
        $result = approve_exam($paper_id);

        if ($result['success']) {
            set_message('success', 'Paper approved successfully.');
        } else {
            set_message('danger', $result['message'] ?? 'Failed to approve paper.');
        }

        redirect('./exam_approvals.php');
    }
}

/* ============================
   Fetch Mega Exams for Filtering
============================= */
$mega_exams_query = "
    SELECT 
        me.id,
        me.title,
        me.mega_exam_code,
        COUNT(e.id) as total_papers,
        SUM(CASE WHEN e.is_approved = 0 THEN 1 ELSE 0 END) as pending_papers
    FROM mega_exams me
    LEFT JOIN exams e ON me.id = e.mega_exam_id
    GROUP BY me.id, me.title, me.mega_exam_code
    ORDER BY me.title ASC
";

$mega_exams = mysqli_query($conn, $mega_exams_query);

// Get selected mega exam from URL
$selected_mega_exam_id = null;
if (isset($_GET['mega_exam_id']) && !empty($_GET['mega_exam_id'])) {
    $selected_mega_exam_id = intval($_GET['mega_exam_id']);
}

/* ============================
   Fetch Pending Papers (with mega exam filter)
============================= */
$pending_papers_where = "e.is_approved = 0";
if ($selected_mega_exam_id) {
    $pending_papers_where .= " AND e.mega_exam_id = $selected_mega_exam_id";
}

$pending_papers_query = "
    SELECT e.*, 
           u.full_name AS teacher_name,
           d.name AS dept_name,
           b.name AS batch_name,
           me.title as mega_exam_title,
           me.mega_exam_code
    FROM exams e
    JOIN users u ON e.teacher_id = u.id
    JOIN departments d ON e.department_id = d.id
    JOIN batches b ON e.batch_id = b.id
    LEFT JOIN mega_exams me ON e.mega_exam_id = me.id
    WHERE $pending_papers_where
    ORDER BY e.created_at DESC
";

$pending_papers = mysqli_query($conn, $pending_papers_query);

/* ============================
   Fetch Approved Papers (with mega exam filter)
============================= */
$approved_papers_where = "e.is_approved = 1";
if ($selected_mega_exam_id) {
    $approved_papers_where .= " AND e.mega_exam_id = $selected_mega_exam_id";
}

$approved_papers_query = "
    SELECT e.*, 
           u.full_name AS teacher_name,
           d.name AS dept_name,
           b.name AS batch_name,
           a.full_name AS approver_name,
           me.title as mega_exam_title,
           me.mega_exam_code
    FROM exams e
    JOIN users u ON e.teacher_id = u.id
    JOIN departments d ON e.department_id = d.id
    JOIN batches b ON e.batch_id = b.id
    LEFT JOIN users a ON e.approved_by = a.id
    LEFT JOIN mega_exams me ON e.mega_exam_id = me.id
    WHERE $approved_papers_where
    ORDER BY e.approved_at DESC
    LIMIT 10
";

$approved_papers = mysqli_query($conn, $approved_papers_query);

$csrf_token = generate_csrf_token();

include '../templates/header.php';
include '../templates/sidebar_admin.php';
?>

<div class="main-content">
    <div class="top-navbar">
        <h4 class="mb-0"><i class="fas fa-check-circle"></i> Paper Approvals</h4>
        <?php if ($selected_mega_exam_id): ?>
            <?php 
            // Get selected mega exam name
            $selected_mega_exam_name = '';
            mysqli_data_seek($mega_exams, 0);
            while ($mega_exam = mysqli_fetch_assoc($mega_exams)) {
                if ($mega_exam['id'] == $selected_mega_exam_id) {
                    $selected_mega_exam_name = $mega_exam['title'];
                    break;
                }
            }
            ?>
            <small class="text-muted">Filtered by: <?= htmlspecialchars($selected_mega_exam_name) ?></small>
        <?php endif; ?>
    </div>

    <div class="content-area">

        <!-- Mega Exam Filter Tabs -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <i class="fas fa-filter"></i> Filter by Mega Exam
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <ul class="nav nav-pills" id="megaExamTabs" role="tablist">
                        <!-- All Papers Tab -->
                        <li class="nav-item" role="presentation">
                            <a class="nav-link <?= !$selected_mega_exam_id ? 'active' : '' ?>" 
                               href="?"
                               role="tab">
                                <i class="fas fa-layer-group"></i> All Papers
                                <span class="badge bg-secondary ms-1">
                                    <?php 
                                    $all_pending = mysqli_query($conn, "SELECT COUNT(*) as count FROM exams WHERE is_approved = 0");
                                    $all_pending_count = mysqli_fetch_assoc($all_pending)['count'];
                                    echo $all_pending_count;
                                    ?>
                                </span>
                            </a>
                        </li>
                        
                        <!-- Individual Mega Exam Tabs -->
                        <?php mysqli_data_seek($mega_exams, 0); ?>
                        <?php while ($mega_exam = mysqli_fetch_assoc($mega_exams)): 
                            $is_active = ($selected_mega_exam_id == $mega_exam['id']);
                            $badge_class = $mega_exam['pending_papers'] > 0 ? 'bg-danger' : 'bg-success';
                        ?>
                        <li class="nav-item" role="presentation">
                            <a class="nav-link <?= $is_active ? 'active' : '' ?>" 
                               href="?mega_exam_id=<?= $mega_exam['id'] ?>"
                               role="tab">
                                <i class="fas fa-cubes"></i>
                                <?= htmlspecialchars($mega_exam['title']) ?>
                                <?php if (!empty($mega_exam['mega_exam_code'])): ?>
                                    <small class="ms-1">(<?= htmlspecialchars($mega_exam['mega_exam_code']) ?>)</small>
                                <?php endif; ?>
                                <span class="badge <?= $badge_class ?> ms-1">
                                    <?= $mega_exam['pending_papers'] ?>
                                </span>
                            </a>
                        </li>
                        <?php endwhile; ?>
                    </ul>
                </div>
                
                <?php if ($selected_mega_exam_id): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        Showing papers only from 
                        <strong><?= htmlspecialchars($selected_mega_exam_name) ?></strong>.
                        <a href="?" class="float-end">Show all papers</a>
                    </div>
                <?php else: ?>
                    <div class="alert alert-light">
                        <i class="fas fa-info-circle"></i>
                        Showing papers from all mega exams. Click on a mega exam tab to filter papers.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pending Papers -->
        <div class="card mb-4">
            <div class="card-header bg-warning text-dark">
                <i class="fas fa-clock"></i> Pending Approvals
                <?php if (mysqli_num_rows($pending_papers) > 0): ?>
                    <span class="badge bg-danger ms-2"><?= mysqli_num_rows($pending_papers); ?> pending</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (mysqli_num_rows($pending_papers) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover">
                        <thead class="table-warning">
                            <tr>
                                <th>Paper Title</th>
                                <th>Mega Exam</th>
                                <th>Department</th>
                                <th>Batch</th>
                                <th>Semester</th>
                                <th>Teacher</th>
                                <th>Time Window</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($paper = mysqli_fetch_assoc($pending_papers)): 
                                // Check if paper has time window (not NULL dates)
                                $has_time_window = (!empty($paper['start_datetime']) && !empty($paper['end_datetime']));
                                
                                // Format dates for display if time window exists
                                $time_display = '-';
                                if ($has_time_window) {
                                    try {
                                        $start_dt = new DateTime($paper['start_datetime'], new DateTimeZone('Asia/Karachi'));
                                        $time_display = $start_dt->format('Y-m-d H:i');
                                    } catch (Exception $e) {
                                        $time_display = 'Invalid date';
                                    }
                                }
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($paper['title']); ?></strong>
                                    <br><small class="text-muted"><?= htmlspecialchars($paper['exam_code']); ?></small>
                                </td>
                                <td>
                                    <?php if (!empty($paper['mega_exam_title'])): ?>
                                        <span class="badge bg-info">
                                            <i class="fas fa-layer-group"></i>
                                            <?= htmlspecialchars($paper['mega_exam_title']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($paper['dept_name']); ?></td>
                                <td><?= htmlspecialchars($paper['batch_name']); ?></td>
                                <td>
                                    <span class="badge bg-secondary">Sem <?= $paper['semester']; ?></span>
                                </td>
                                <td><?= htmlspecialchars($paper['teacher_name']); ?></td>
                                <td>
                                    <?php if ($has_time_window): ?>
                                        <small><?= $time_display; ?></small>
                                        <br><small class="text-muted">Time Window Enabled</small>
                                    <?php else: ?>
                                        <span class="badge bg-success">Always Available</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">
                                        <input type="hidden" name="paper_id" value="<?= $paper['id']; ?>">
                                        <button type="submit" name="approve_paper" class="btn btn-sm btn-success">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                        <p class="text-muted">
                            <?php if ($selected_mega_exam_id): ?>
                                No pending paper approvals for <?= htmlspecialchars($selected_mega_exam_name) ?>.
                            <?php else: ?>
                                No pending paper approvals.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Approved Papers -->
        <div class="card">
            <div class="card-header bg-success text-white">
                <i class="fas fa-check-circle"></i> Recently Approved Papers
                <?php if ($selected_mega_exam_id): ?>
                    <span class="badge bg-light text-dark ms-2">Filtered</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (mysqli_num_rows($approved_papers) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover">
                        <thead class="table-success">
                            <tr>
                                <th>Paper Title</th>
                                <th>Mega Exam</th>
                                <th>Department</th>
                                <th>Batch</th>
                                <th>Semester</th>
                                <th>Teacher</th>
                                <th>Time Window</th>
                                <th>Approved By</th>
                                <th>Approved At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($paper = mysqli_fetch_assoc($approved_papers)): 
                                // Check if paper has time window (not NULL dates)
                                $has_time_window = (!empty($paper['start_datetime']) && !empty($paper['end_datetime']));
                                
                                // Format dates for display if time window exists
                                $time_display = '-';
                                if ($has_time_window) {
                                    try {
                                        $start_dt = new DateTime($paper['start_datetime'], new DateTimeZone('Asia/Karachi'));
                                        $time_display = $start_dt->format('Y-m-d H:i');
                                    } catch (Exception $e) {
                                        $time_display = 'Invalid date';
                                    }
                                }
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($paper['title']); ?></strong>
                                    <br><small class="text-muted"><?= htmlspecialchars($paper['exam_code']); ?></small>
                                </td>
                                <td>
                                    <?php if (!empty($paper['mega_exam_title'])): ?>
                                        <span class="badge bg-info">
                                            <i class="fas fa-layer-group"></i>
                                            <?= htmlspecialchars($paper['mega_exam_title']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($paper['dept_name']); ?></td>
                                <td><?= htmlspecialchars($paper['batch_name']); ?></td>
                                <td>
                                    <span class="badge bg-secondary">Sem <?= $paper['semester']; ?></span>
                                </td>
                                <td><?= htmlspecialchars($paper['teacher_name']); ?></td>
                                <td>
                                    <?php if ($has_time_window): ?>
                                        <small><?= $time_display; ?></small>
                                        <br><small class="text-muted">Time Window Enabled</small>
                                    <?php else: ?>
                                        <span class="badge bg-success">Always Available</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small><?= !empty($paper['approver_name']) ? htmlspecialchars($paper['approver_name']) : 'System'; ?></small>
                                </td>
                                <td>
                                    <small><?= !empty($paper['approved_at']) ? format_datetime($paper['approved_at']) : 'N/A'; ?></small>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                        <p class="text-muted">
                            <?php if ($selected_mega_exam_id): ?>
                                No approved papers found for <?= htmlspecialchars($selected_mega_exam_name) ?>.
                            <?php else: ?>
                                No approved papers yet.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<style>
.nav-pills .nav-link {
    margin-right: 5px;
    margin-bottom: 5px;
}
.badge {
    font-size: 0.7em;
}
.table th {
    font-weight: 600;
}
.table td {
    vertical-align: middle;
}
</style>

<?php include '../templates/footer.php'; ?>
<?php
require_once '../app/config.php';
require_once '../app/helpers.php';
require_once '../app/auth.php';
require_once '../app/admin_handlers.php';

require_role(['admin']);

$page_title = 'Manage Batches';
$message = get_message();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && verify_csrf_token($_POST['csrf_token'])) {

    // Create Batch
    if (isset($_POST['create_batch'])) {
        $result = create_batch(
            sanitize_input($_POST['name']),
            intval($_POST['year']),
            intval($_POST['department_id'])
        );
        set_message($result['success'] ? 'success' : 'danger',
                    $result['success'] ? 'Batch created successfully' : $result['message']);
    }

    // Edit Batch
    if (isset($_POST['edit_batch'])) {
        $batch_id = intval($_POST['batch_id']);
        $name = sanitize_input($_POST['name']);
        $year = intval($_POST['year']);

        $stmt = $conn->prepare("UPDATE batches SET name=?, year=? WHERE id=?");
        $stmt->bind_param("sii", $name, $year, $batch_id);
        $stmt->execute();

        set_message('success', 'Batch updated successfully');
    }

    redirect('manage_batches.php');
}

// Delete Batch
if (isset($_GET['delete'])) {
    $batch_id = intval($_GET['delete']);
    mysqli_query($conn, "DELETE FROM batches WHERE id = $batch_id");
    set_message('success', 'Batch deleted successfully');
    redirect('manage_batches.php');
}

$departments = mysqli_query($conn, "SELECT * FROM departments WHERE is_active = 1");

$batches = mysqli_query($conn, "SELECT b.*, d.name as dept_name,
COUNT(u.id) as student_count 
FROM batches b
JOIN departments d ON b.department_id = d.id
LEFT JOIN users u ON u.batch_id = b.id
GROUP BY b.id ORDER BY b.year DESC, b.name");

$csrf_token = generate_csrf_token();

include '../templates/header.php';
include '../templates/sidebar_admin.php';
?>

<div class="main-content">
    <div class="top-navbar d-flex justify-content-between">
        <h4><i class="fas fa-layer-group"></i> Manage Batches</h4>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createBatchModal">
            <i class="fas fa-plus"></i> Add Batch
        </button>
    </div>

    <div class="content-area">

        <?php if ($message): ?>
        <div class="alert alert-<?= $message['type']; ?> alert-dismissible fade show">
            <?= htmlspecialchars($message['text']); ?>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">All Batches</div>
            <div class="card-body table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Year</th>
                            <th>Department</th>
                            <th>Students</th>
                            <th>Status</th>
                            <th width="140" class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($batch = mysqli_fetch_assoc($batches)): ?>
                        <tr>
                            <td><?= htmlspecialchars($batch['name']); ?></td>
                            <td><?= $batch['year']; ?></td>
                            <td><?= htmlspecialchars($batch['dept_name']); ?></td>
                            <td><?= $batch['student_count']; ?></td>
                            <td>
                                <span class="badge <?= $batch['is_active'] ? 'bg-success' : 'bg-danger'; ?>">
                                    <?= $batch['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td class="d-flex gap-4">
                                <button class="btn btn-sm btn-warning"
                                    data-bs-toggle="modal"
                                    data-bs-target="#editBatchModal<?= $batch['id']; ?>">
                                    <i class="fas fa-edit"></i>
                                </button>

                                <a onclick="return confirm('Are you sure?')"
                                   href="?delete=<?= $batch['id']; ?>"
                                   class="btn btn-sm btn-danger">
                                   <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>

                        <!-- EDIT MODAL -->
                        <div class="modal fade" id="editBatchModal<?= $batch['id']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <form method="POST">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5>Edit Batch</h5>
                                            <button class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">
                                            <input type="hidden" name="batch_id" value="<?= $batch['id']; ?>">

                                            <label class="form-label">Batch Name</label>
                                            <input type="text" name="name" class="form-control"
                                                   value="<?= htmlspecialchars($batch['name']); ?>"
                                                   pattern="[A-Za-z0-9 ]{1,50}" maxlength="50" required>

                                            <label class="form-label mt-2">Year</label>
                                            <input type="text" name="year" class="form-control"
                                                   value="<?= $batch['year']; ?>"
                                                   pattern="[0-9]{1,4}" maxlength="4" required>
                                        </div>
                                        <div class="modal-footer">
                                            <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                            <button type="submit" name="edit_batch" class="btn btn-primary">Save</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- CREATE MODAL -->
<div class="modal fade" id="createBatchModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header">
                    <h5>Create Batch</h5>
                    <button class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">

                    <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">

                    <label class="form-label">Batch Name</label>
                    <input type="text" name="name" class="form-control"
                           pattern="[A-Za-z0-9 ]{1,50}" maxlength="50" required>

                    <label class="form-label mt-2">Year</label>
                    <input type="text" name="year" class="form-control"
                           pattern="[0-9]{1,4}" maxlength="4" required
                           value="<?= date('Y'); ?>">

                    <label class="form-label mt-2">Department</label>
                    <select name="department_id" class="form-select" required>
                        <option value="">Select Department</option>
                        <?php mysqli_data_seek($departments, 0);
                        while($dept = mysqli_fetch_assoc($departments)): ?>
                            <option value="<?= $dept['id']; ?>">
                                <?= htmlspecialchars($dept['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="create_batch" class="btn btn-primary">Save</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php include '../templates/footer.php'; ?>

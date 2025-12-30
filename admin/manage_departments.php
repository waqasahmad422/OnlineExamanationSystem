<?php
require_once '../app/config.php';
require_once '../app/helpers.php';
require_once '../app/auth.php';

require_role(['admin']);
check_session_validity();

$page_title = 'Manage Departments';

// CRUD FUNCTIONS INLINE HERE
function create_department($name, $code) {
    global $conn;
    $stmt = mysqli_prepare($conn, "INSERT INTO departments (name, code) VALUES (?, ?)");
    mysqli_stmt_bind_param($stmt, "ss", $name, $code);

    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true, 'message' => 'Department Created Successfully'];
    }
    return ['success' => false, 'message' => 'Failed to Create Department'];
}

function update_department($dept_id, $name, $code) {
    global $conn;
    $stmt = mysqli_prepare($conn, "UPDATE departments SET name = ?, code = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "ssi", $name, $code, $dept_id);

    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true, 'message' => 'Department Updated Successfully'];
    }
    return ['success' => false, 'message' => 'Failed to Update Department'];
}

function delete_department($dept_id) {
    global $conn;
    $stmt = mysqli_prepare($conn, "DELETE FROM departments WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $dept_id);

    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true, 'message' => 'Department Deleted Successfully'];
    }
    return ['success' => false, 'message' => 'Failed to Delete Department'];
}

// Handle Form Submit
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    if (isset($_POST['create_department'])) {
        $result = create_department($_POST['name'], $_POST['code']);
        set_message($result['success'] ? 'success' : 'danger', $result['message']);
    }

    if (isset($_POST['update_department'])) {
        $result = update_department($_POST['dept_id'], $_POST['name'], $_POST['code']);
        set_message($result['success'] ? 'success' : 'danger', $result['message']);
    }

    if (isset($_POST['delete_department'])) {
        $result = delete_department($_POST['dept_id']);
        set_message($result['success'] ? 'success' : 'danger', $result['message']);
    }

    redirect('./manage_departments.php');
}

// Load Data
$departments = mysqli_query($conn, "SELECT * FROM departments ORDER BY name");
include '../templates/header.php';
include '../templates/sidebar_admin.php';
?>

<style>
.modal { z-index: 10550 !important; }
.modal-backdrop { z-index: 10545 !important; }
</style>

<div class="main-content">
    <div class="top-navbar">
        <h4 class="mb-0"><i class="fas fa-building"></i> Manage Departments</h4>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createDeptModal">
            + Add Department
        </button>
    </div>

    <div class="content-area">

        <div class="row">
            <?php while ($dept = mysqli_fetch_assoc($departments)): ?>
            <div class="col-md-4 mb-3">
                <div class="stats-card">
                    <h5><?= $dept['name']; ?></h5>
                    <p><strong>Code:</strong> <?= $dept['code']; ?></p>

                    <button class="btn btn-warning btn-sm"
                        data-bs-toggle="modal"
                        data-bs-target="#edit<?= $dept['id']; ?>">
                        Edit
                    </button>

                    <button class="btn btn-danger btn-sm"
                        data-bs-toggle="modal"
                        data-bs-target="#delete<?= $dept['id']; ?>">
                        Delete
                    </button>
                </div>
            </div>

            <!-- Edit Modal -->
            <div class="modal fade" id="edit<?= $dept['id']; ?>">
                <div class="modal-dialog">
                    <form class="modal-content" method="POST">
                        <input type="hidden" name="dept_id" value="<?= $dept['id']; ?>">
                        <div class="modal-header">
                            <h5>Edit Department</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <label>Name</label>
                            <input type="text" name="name" class="form-control"
                                   value="<?= $dept['name']; ?>"
                                   pattern="[A-Za-z ]{1,25}" maxlength="25" required>

                            <label>Code</label>
                            <input type="text" name="code" class="form-control"
                                   value="<?= $dept['code']; ?>"
                                   pattern="[A-Za-z0-9]{1,25}" maxlength="25" required>
                        </div>
                        <div class="modal-footer">
                            <button type="submit" name="update_department" class="btn btn-warning">Update</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Delete Modal -->
            <div class="modal fade" id="delete<?= $dept['id']; ?>">
                <div class="modal-dialog">
                    <form class="modal-content" method="POST">
                        <input type="hidden" name="dept_id" value="<?= $dept['id']; ?>">
                        <div class="modal-header">
                            <h5 class="text-danger">Delete Department</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            Confirm delete: <strong><?= $dept['name']; ?></strong> ?
                        </div>
                        <div class="modal-footer">
                            <button type="submit" name="delete_department" class="btn btn-danger">Delete</button>
                        </div>
                    </form>
                </div>
            </div>

            <?php endwhile; ?>
        </div>
    </div>
</div>

<!-- Create Modal -->
<div class="modal fade" id="createDeptModal">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <div class="modal-header">
                <h5>Create Department</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label>Name</label>
                <input type="text" name="name" class="form-control"
                       pattern="[A-Za-z ]{1,25}" maxlength="25" required>

                <label>Code</label>
                <input type="text" name="code" class="form-control"
                       pattern="[A-Za-z0-9]{1,25}" maxlength="25" required>
            </div>
            <div class="modal-footer">
                <button type="submit" name="create_department" class="btn btn-primary">Create</button>
            </div>
        </form>
    </div>
</div>

<?php include '../templates/footer.php'; ?>

<!-- BLOCK NUMBERS IN NAME INPUT -->
<script>
document.addEventListener("input", function(e) {
    if (e.target.name === "name") {
        e.target.value = e.target.value.replace(/[^A-Za-z ]/g, '');
    }
});
</script>

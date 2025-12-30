<?php
// admin/mega_exams.php
require_once '../app/config.php';
require_once '../app/helpers.php';
require_once '../app/auth.php';
require_once '../app/admin_handlers.php';

require_role(['admin']);
date_default_timezone_set('Asia/Karachi');

$admin_id = intval($_SESSION['user_id']);
$csrf_token = generate_csrf_token();

// Helper: safe display messages
function render_messages_fallback() {
    if (function_exists('display_messages')) {
        display_messages();
        return;
    }
    if (!empty($_SESSION['flash_messages']) && is_array($_SESSION['flash_messages'])) {
        foreach ($_SESSION['flash_messages'] as $type => $msg) {
            echo '<div class="alert alert-' . htmlspecialchars($type) . ' alert-dismissible">' . htmlspecialchars($msg) . '</div>';
        }
        unset($_SESSION['flash_messages']);
    }
}

// ===== POST Actions: CREATE / UPDATE / DELETE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {

    // ---------- CREATE EXAM ----------
    if (isset($_POST['create_mega_exam'])) {
        $title = sanitize_input($_POST['title'] ?? '');
        $mega_exam_code = sanitize_input($_POST['mega_exam_code'] ?? '');

        if (!$title || !$mega_exam_code) {
            set_message('danger', 'Please fill all required fields correctly.');
            redirect('./mega_exams.php');
        }

        $check_stmt = mysqli_prepare($conn, "SELECT id FROM mega_exams WHERE mega_exam_code = ?");
        mysqli_stmt_bind_param($check_stmt, "s", $mega_exam_code);
        mysqli_stmt_execute($check_stmt);
        mysqli_stmt_store_result($check_stmt);
        if (mysqli_stmt_num_rows($check_stmt) > 0) {
            mysqli_stmt_close($check_stmt);
            set_message('danger', 'Exam code already exists. Please use a unique code.');
            redirect('./mega_exams.php');
        }
        mysqli_stmt_close($check_stmt);

        $stmt = mysqli_prepare($conn, "INSERT INTO mega_exams (title, mega_exam_code, admin_id, total_exams) VALUES (?, ?, ?, 0)");
        mysqli_stmt_bind_param($stmt, "ssi", $title, $mega_exam_code, $admin_id);
        if (mysqli_stmt_execute($stmt)) {
            $new_id = mysqli_insert_id($conn);
            log_audit($admin_id, 'create_mega_exam', 'mega_exams', $new_id, null, "Created exam: $title");
            set_message('success', 'Exam created successfully.');
        } else {
            set_message('danger', 'Failed to create exam: ' . mysqli_error($conn));
        }
        mysqli_stmt_close($stmt);
        redirect('./mega_exams.php');
    }

    // ---------- UPDATE EXAM ----------
    if (isset($_POST['update_mega_exam'])) {
        $mega_exam_id = intval($_POST['mega_exam_id'] ?? 0);
        if ($mega_exam_id <= 0) {
            set_message('danger', 'Invalid exam ID.');
            redirect('./mega_exams.php');
        }

        $title = sanitize_input($_POST['title'] ?? '');
        $mega_exam_code = sanitize_input($_POST['mega_exam_code'] ?? '');

        if (!$title || !$mega_exam_code) {
            set_message('danger', 'Please fill all required fields correctly for update.');
            redirect('./mega_exams.php');
        }

        $check_stmt = mysqli_prepare($conn, "SELECT id FROM mega_exams WHERE mega_exam_code = ? AND id != ?");
        mysqli_stmt_bind_param($check_stmt, "si", $mega_exam_code, $mega_exam_id);
        mysqli_stmt_execute($check_stmt);
        mysqli_stmt_store_result($check_stmt);
        if (mysqli_stmt_num_rows($check_stmt) > 0) {
            mysqli_stmt_close($check_stmt);
            set_message('danger', 'Exam code already exists. Please use a unique code.');
            redirect('./mega_exams.php');
        }
        mysqli_stmt_close($check_stmt);

        $stmt = mysqli_prepare($conn, "UPDATE mega_exams SET title = ?, mega_exam_code = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "ssi", $title, $mega_exam_code, $mega_exam_id);
        if (mysqli_stmt_execute($stmt)) {
            log_audit($admin_id, 'update_mega_exam', 'mega_exams', $mega_exam_id, null, "Updated exam: $title");
            set_message('success', 'Exam updated successfully.');
        } else {
            set_message('danger', 'Failed to update exam: ' . mysqli_error($conn));
        }
        mysqli_stmt_close($stmt);
        redirect('./mega_exams.php');
    }

    // ---------- DELETE EXAM (WITH CASCADE) ----------
    if (isset($_POST['delete_mega_exam'])) {
        $mega_exam_id = intval($_POST['mega_exam_id'] ?? 0);
        if ($mega_exam_id <= 0) {
            set_message('danger', 'Invalid exam ID.');
            redirect('./mega_exams.php');
        }

        // First get the exam title and count linked exams for logging
        $title_stmt = mysqli_prepare($conn, "SELECT title, mega_exam_code FROM mega_exams WHERE id = ?");
        mysqli_stmt_bind_param($title_stmt, "i", $mega_exam_id);
        mysqli_stmt_execute($title_stmt);
        mysqli_stmt_store_result($title_stmt);
        
        if (mysqli_stmt_num_rows($title_stmt) == 0) {
            mysqli_stmt_close($title_stmt);
            set_message('danger', 'Exam not found.');
            redirect('./mega_exams.php');
        }
        
        mysqli_stmt_bind_result($title_stmt, $exam_title, $exam_code);
        mysqli_stmt_fetch($title_stmt);
        mysqli_stmt_close($title_stmt);

        // Count linked exams for warning message
        $count_stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM exams WHERE mega_exam_id = ?");
        mysqli_stmt_bind_param($count_stmt, "i", $mega_exam_id);
        mysqli_stmt_execute($count_stmt);
        mysqli_stmt_bind_result($count_stmt, $linked_exams_count);
        mysqli_stmt_fetch($count_stmt);
        mysqli_stmt_close($count_stmt);

        // Start transaction for safe deletion
        mysqli_begin_transaction($conn);
        
        try {
            $total_deleted = 0;
            
            // If there are linked exams, delete them first
            if ($linked_exams_count > 0) {
                // First, get all exam IDs to log them
                $get_exams_stmt = mysqli_prepare($conn, "SELECT id, exam_code, title FROM exams WHERE mega_exam_id = ?");
                mysqli_stmt_bind_param($get_exams_stmt, "i", $mega_exam_id);
                mysqli_stmt_execute($get_exams_stmt);
                $exams_result = mysqli_stmt_get_result($get_exams_stmt);
                
                $deleted_exams = [];
                while ($exam_row = mysqli_fetch_assoc($exams_result)) {
                    $deleted_exams[] = $exam_row;
                }
                mysqli_stmt_close($get_exams_stmt);
                
                // Now delete all linked exams
                $delete_exams_stmt = mysqli_prepare($conn, "DELETE FROM exams WHERE mega_exam_id = ?");
                mysqli_stmt_bind_param($delete_exams_stmt, "i", $mega_exam_id);
                mysqli_stmt_execute($delete_exams_stmt);
                $total_deleted = mysqli_stmt_affected_rows($delete_exams_stmt);
                mysqli_stmt_close($delete_exams_stmt);
                
                // Log deletion of each exam
                foreach ($deleted_exams as $exam) {
                    log_audit($admin_id, 'delete_exam_cascade', 'exams', $exam['id'], null, 
                             "Deleted exam '{$exam['title']}' ({$exam['exam_code']}) due to mega exam deletion");
                }
            }
            
            // Now delete the mega exam itself
            $delete_mega_stmt = mysqli_prepare($conn, "DELETE FROM mega_exams WHERE id = ?");
            mysqli_stmt_bind_param($delete_mega_stmt, "i", $mega_exam_id);
            mysqli_stmt_execute($delete_mega_stmt);
            $mega_deleted = mysqli_stmt_affected_rows($delete_mega_stmt);
            mysqli_stmt_close($delete_mega_stmt);
            
            if ($mega_deleted > 0) {
                mysqli_commit($conn);
                
                // Log the mega exam deletion
                log_audit($admin_id, 'delete_mega_exam', 'mega_exams', $mega_exam_id, null, 
                         "Deleted mega exam: {$exam_title} ({$exam_code}) along with {$total_deleted} linked exam(s)");
                
                if ($total_deleted > 0) {
                    set_message('success', "Mega exam '{$exam_title}' and {$total_deleted} linked exam(s) deleted successfully.");
                } else {
                    set_message('success', "Mega exam '{$exam_title}' deleted successfully.");
                }
            } else {
                mysqli_rollback($conn);
                set_message('warning', "No changes made. The mega exam may have already been deleted.");
            }
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            set_message('danger', 'Failed to delete mega exam: ' . $e->getMessage());
        }
        
        redirect('./mega_exams.php');
    }
}

// ===== Fetch data =====
$mega_exams = mysqli_query($conn, "
    SELECT me.*, 
           u.full_name as admin_name,
           COUNT(e.id) as linked_exams_count
    FROM mega_exams me
    LEFT JOIN users u ON me.admin_id = u.id
    LEFT JOIN exams e ON me.id = e.mega_exam_id
    GROUP BY me.id
    ORDER BY me.created_at DESC
");

include '../templates/header.php';
include '../templates/sidebar_admin.php';
?>

<style>
.hidden { display: none; }
.form-inline-grid { display:grid; grid-template-columns: repeat(2, 1fr); gap:12px; }
@media(max-width:700px){ .form-inline-grid{grid-template-columns:1fr} }
.card { margin-bottom: 1rem; }
.btn-icon { padding: 0.25rem 0.5rem; font-size: 0.875rem; }
.badge-linked { background-color: #6f42c1; }
.alert a.alert-link { color: inherit; text-decoration: underline; font-weight: bold; }
.warning-badge { background-color: #ffc107; color: #000; }
</style>

<div class="main-content">
    <div class="top-navbar d-flex justify-content-between align-items-center mb-3">
        <h4><i class="fas fa-layer-group"></i> Mega Exams</h4>
        <button class="btn btn-primary" id="btnCreate"><i class="fas fa-plus"></i> Add Mega Exam</button>
    </div>

    <div class="content-area">
        <?php render_messages_fallback(); ?>

        <!-- Exams Table -->
        <div id="megaExamTable" class="card">
            <div class="card-header">Mega Exams Management</div>
            <div class="card-body table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Code</th>
                            <th>Created By</th>
                            <th>Linked Exams</th>
                            <th>Created At</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = mysqli_fetch_assoc($mega_exams)): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['title']) ?></td>
                            <td><code><?= htmlspecialchars($row['mega_exam_code']) ?></code></td>
                            <td><?= htmlspecialchars($row['admin_name']) ?></td>
                            <td>
                                <?php if ($row['linked_exams_count'] > 0): ?>
                                    <span class="badge warning-badge" title="These will be deleted automatically">
                                        <i class="fas fa-exclamation-triangle"></i> 
                                        <?= intval($row['linked_exams_count']) ?> exam(s)
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-linked">
                                        <?= intval($row['linked_exams_count']) ?> exam(s)
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($row['created_at']) ?></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-primary btn-icon btnEdit" 
                                            data-mega-exam='<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>' 
                                            title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" style="display:inline-block;" 
                                          onsubmit="return confirmCascadeDelete('<?= htmlspecialchars(addslashes($row['title'])) ?>', <?= intval($row['linked_exams_count']) ?>);">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="mega_exam_id" value="<?= intval($row['id']) ?>">
                                        <button type="submit" name="delete_mega_exam" class="btn btn-outline-danger btn-icon" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                    <a href="manage_mega_exam_exams.php?mega_exam_id=<?= intval($row['id']) ?>" 
                                       class="btn btn-outline-secondary btn-icon" 
                                       title="Manage Linked Exams">
                                        <i class="fas fa-list"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                <?php if (mysqli_num_rows($mega_exams) === 0): ?>
                    <p class="text-muted text-center">No mega exams found.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Exam Form -->
        <div id="megaExamFormBox" class="card hidden">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong id="formTitle">Create Mega Exam</strong>
                <button class="btn-close" id="closeForm"></button>
            </div>
            <div class="card-body">
                <form id="megaExamForm" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" id="mega_exam_id" name="mega_exam_id" value="">

                    <div class="form-inline-grid mb-3">
                        <div>
                            <label class="form-label">Exam Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" id="title" class="form-control" required pattern="[A-Za-z0-9 ]{1,30}" maxlength="30">
                            <small class="text-muted">Only letters, numbers and spaces. Max 30 characters.</small>
                        </div>

                        <div>
                            <label class="form-label">Exam Code <span class="text-danger">*</span></label>
                            <input type="text" name="mega_exam_code" id="mega_exam_code" class="form-control" required pattern="[A-Za-z0-9]{1,30}" maxlength="30">
                            <small class="text-muted">Only letters and numbers. Max 30 characters.</small>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" id="createBtn" name="create_mega_exam">Create Mega Exam</button>
                        <button type="submit" class="btn btn-success hidden" id="updateBtn" name="update_mega_exam">Update Mega Exam</button>
                        <button type="button" class="btn btn-secondary" id="cancelBtn">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>

<script>
// UI toggles
const btnCreate = document.getElementById('btnCreate');
const megaExamTable = document.getElementById('megaExamTable');
const megaExamFormBox = document.getElementById('megaExamFormBox');
const closeForm = document.getElementById('closeForm');
const cancelBtn = document.getElementById('cancelBtn');
const formTitle = document.getElementById('formTitle');
const createBtn = document.getElementById('createBtn');
const updateBtn = document.getElementById('updateBtn');

function showForm(mode = 'create') {
    megaExamTable.classList.add('hidden');
    megaExamFormBox.classList.remove('hidden');
    if (mode === 'create') {
        formTitle.innerText = 'Create Mega Exam';
        createBtn.classList.remove('hidden');
        updateBtn.classList.add('hidden');
        document.getElementById('megaExamForm').reset();
        document.getElementById('mega_exam_id').value = '';
    } else {
        formTitle.innerText = 'Edit Mega Exam';
        createBtn.classList.add('hidden');
        updateBtn.classList.remove('hidden');
    }
}

function hideForm() {
    megaExamTable.classList.remove('hidden');
    megaExamFormBox.classList.add('hidden');
}

btnCreate?.addEventListener('click', () => showForm('create'));
closeForm?.addEventListener('click', hideForm);
cancelBtn?.addEventListener('click', hideForm);

// Populate edit
document.querySelectorAll('.btnEdit').forEach(btn => {
    btn.addEventListener('click', () => {
        const raw = btn.getAttribute('data-mega-exam');
        let data;
        try { 
            data = JSON.parse(raw); 
        } catch (e) { 
            alert('Invalid exam data'); 
            return; 
        }
        
        document.getElementById('mega_exam_id').value = data.id || '';
        document.getElementById('title').value = data.title || '';
        document.getElementById('mega_exam_code').value = data.mega_exam_code || '';
        showForm('edit');
        window.scrollTo({top:0, behavior:'smooth'});
    });
});

// Client-side validation
document.getElementById('megaExamForm')?.addEventListener('submit', function(e) {
    const title = document.getElementById('title').value.trim();
    const code = document.getElementById('mega_exam_code').value.trim();
    const titlePattern = /^[A-Za-z0-9 ]{1,30}$/;
    const codePattern = /^[A-Za-z0-9]{1,30}$/;

    if (!titlePattern.test(title)) {
        e.preventDefault();
        alert('Exam Title must be letters, numbers and spaces only, max 30 characters.');
        return false;
    }
    if (!codePattern.test(code)) {
        e.preventDefault();
        alert('Exam Code must be letters and numbers only, max 30 characters.');
        return false;
    }
});

// Cascade delete confirmation
function confirmCascadeDelete(examTitle, linkedCount) {
    if (linkedCount > 0) {
        return confirm(`⚠️ DANGER: You are about to delete "${examTitle}"!\n\nThis will also delete ${linkedCount} linked exam(s) permanently.\n\nThis action cannot be undone!\n\nAre you absolutely sure?`);
    }
    
    return confirm(`Are you sure you want to delete "${examTitle}"?\n\nThis action cannot be undone.`);
}
</script>
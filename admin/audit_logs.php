<?php
require_once '../app/config.php';
require_once '../app/helpers.php';
require_once '../app/auth.php';

require_role(['admin']);

// Handle clear logs request
if (isset($_POST['clear_logs'])) {
    // First log the clear action BEFORE truncating
    $admin_id = $_SESSION['user_id'];
    $stmt = mysqli_prepare($conn, "
        INSERT INTO audit_logs (user_id, action, table_name, details, ip_address, user_agent) 
        VALUES (?, 'CLEAR_ALL_LOGS', 'audit_logs', 'All audit logs cleared by admin', ?, ?)
    ");
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    mysqli_stmt_bind_param($stmt, "iss", $admin_id, $ip_address, $user_agent);
    mysqli_stmt_execute($stmt);
    
    // Now truncate the table (this will also remove the log we just inserted)
    mysqli_query($conn, "TRUNCATE TABLE audit_logs");
    
    // Insert a fresh log after truncating to record that logs were cleared
    $stmt = mysqli_prepare($conn, "
        INSERT INTO audit_logs (user_id, action, table_name, details, ip_address, user_agent) 
        VALUES (?, 'SYSTEM', 'audit_logs', 'Audit logs initialized - all previous logs cleared', ?, ?)
    ");
    mysqli_stmt_bind_param($stmt, "iss", $admin_id, $ip_address, $user_agent);
    mysqli_stmt_execute($stmt);
    
    set_message('success', 'All audit logs have been cleared successfully.');
    header("Location: audit_logs.php");
    exit;
}

// Handle filter requests
$filter_action = $_GET['filter_action'] ?? '';
$filter_user = $_GET['filter_user'] ?? '';
$filter_date = $_GET['filter_date'] ?? '';

$page_title = 'Audit Logs';

// Build query with filters
$query = "
    SELECT a.*, u.full_name, u.role
    FROM audit_logs a 
    LEFT JOIN users u ON a.user_id = u.id 
    WHERE 1=1
";

$params = [];
$types = '';

if ($filter_action) {
    $query .= " AND a.action LIKE ?";
    $params[] = "%$filter_action%";
    $types .= 's';
}

if ($filter_user) {
    $query .= " AND u.full_name LIKE ?";
    $params[] = "%$filter_user%";
    $types .= 's';
}

if ($filter_date) {
    $query .= " AND DATE(a.created_at) = ?";
    $params[] = $filter_date;
    $types .= 's';
}

$query .= " ORDER BY a.created_at DESC LIMIT 500";

$stmt = mysqli_prepare($conn, $query);
if ($params) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$logs = mysqli_stmt_get_result($stmt);

// Get unique actions for filter dropdown
$actions_query = mysqli_query($conn, "SELECT DISTINCT action FROM audit_logs ORDER BY action");
$unique_actions = [];
while ($row = mysqli_fetch_assoc($actions_query)) {
    $unique_actions[] = $row['action'];
}

// Get unique dates for date filter
$dates_query = mysqli_query($conn, "SELECT DISTINCT DATE(created_at) as log_date FROM audit_logs ORDER BY log_date DESC LIMIT 30");
$unique_dates = [];
while ($row = mysqli_fetch_assoc($dates_query)) {
    $unique_dates[] = $row['log_date'];
}

include '../templates/header.php';
include '../templates/sidebar_admin.php';
?>

<style>
.audit-log-table td {
    vertical-align: top;
}
.badge-action {
    font-size: 0.75rem;
    padding: 0.35em 0.65em;
    font-weight: 600;
}

/* Dark backgrounds with WHITE text */
.badge-mark-update {
    background: linear-gradient(135deg, #17a2b8 0%, #138496 100%) !important;
    border: 1px solid #138496;
    color: white !important;
}

.badge-total-update {
    background: linear-gradient(135deg, #6f42c1 0%, #5a2d9c 100%) !important;
    border: 1px solid #5a2d9c;
    color: white !important;
}

.badge-evaluation {
    background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%) !important;
    border: 1px solid #1e7e34;
    color: white !important;
}

.badge-view {
    background: linear-gradient(135deg, #20c997 0%, #169b6b 100%) !important;
    border: 1px solid #169b6b;
    color: white !important;
}

.badge-create {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%) !important;
    border: 1px solid #0056b3;
    color: white !important;
}

.badge-delete {
    background: linear-gradient(135deg, #dc3545 0%, #c82333 100%) !important;
    border: 1px solid #c82333;
    color: white !important;
}

.badge-error {
    background: linear-gradient(135deg, #fd7e14 0%, #e55a00 100%) !important;
    border: 1px solid #e55a00;
    color: white !important;
}

.badge-login {
    background: linear-gradient(135deg, #198754 0%, #146c43 100%) !important;
    border: 1px solid #146c43;
    color: white !important;
}

.badge-success {
    background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%) !important;
    border: 1px solid #1e7e34;
    color: white !important;
}

.badge-user {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%) !important;
    border: 1px solid #0056b3;
    color: white !important;
}

.badge-log {
    background: linear-gradient(135deg, #495057 0%, #343a40 100%) !important;
    border: 1px solid #343a40;
    color: white !important;
}

/* Light backgrounds with BLACK text */
.badge-update {
    background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%) !important;
    border: 1px solid #e0a800;
    color: #000 !important;
}

.badge-clear {
    background: linear-gradient(135deg, #6c757d 0%, #545b62 100%) !important;
    border: 1px solid #545b62;
    color: #000 !important;
}

.badge-system {
    background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%) !important;
    border: 1px solid #90caf9;
    color: #000 !important;
}

.badge-logout {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%) !important;
    border: 1px solid #dee2e6;
    color: #000 !important;
}

.badge-auth {
    background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%) !important;
    border: 1px solid #90caf9;
    color: #000 !important;
}

.badge-secondary {
    background: linear-gradient(135deg, #6c757d 0%, #545b62 100%) !important;
    border: 1px solid #545b62;
    color: white !important;
}

.change-highlight {
    background-color: #fff3cd !important;
    border-left: 4px solid #ffc107;
}
.details-expandable {
    cursor: pointer;
    transition: background-color 0.2s;
}
.details-expandable:hover {
    background-color: #f8f9fa;
}
.expanded-details {
    background: #f8f9fa;
    border-radius: 0.375rem;
    padding: 0.75rem;
    margin-top: 0.5rem;
    border-left: 3px solid #007bff;
}
.action-cell {
    min-width: 200px;
}
.timestamp-cell {
    min-width: 160px;
}
.action-category {
    font-size: 0.7rem;
    opacity: 0.8;
    display: block;
    margin-top: 2px;
    color: #6c757d;
}
.action-icon {
    margin-right: 4px;
    width: 16px;
    text-align: center;
}
</style>

<div class="main-content">
    <div class="top-navbar">
        <h4 class="mb-0"><i class="fas fa-history"></i> Audit Logs</h4>
    </div>
    
    <div class="content-area">
        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-filter"></i> Filters & Controls</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Action Type</label>
                        <select name="filter_action" class="form-select">
                            <option value="">All Actions</option>
                            <optgroup label="Evaluation Actions">
                                <option value="MARK_UPDATE" <?= $filter_action === 'MARK_UPDATE' ? 'selected' : '' ?>>Mark Updates</option>
                                <option value="TOTAL_MARKS_UPDATE" <?= $filter_action === 'TOTAL_MARKS_UPDATE' ? 'selected' : '' ?>>Total Marks Updates</option>
                                <option value="EVALUATION_" <?= $filter_action === 'EVALUATION_' ? 'selected' : '' ?>>All Evaluations</option>
                                <option value="EVALUATION_CREATED" <?= $filter_action === 'EVALUATION_CREATED' ? 'selected' : '' ?>>Evaluation Created</option>
                                <option value="EVALUATION_UPDATED" <?= $filter_action === 'EVALUATION_UPDATED' ? 'selected' : '' ?>>Evaluation Updated</option>
                            </optgroup>
                            <optgroup label="View Actions">
                                <option value="VIEW_" <?= $filter_action === 'VIEW_' ? 'selected' : '' ?>>All View Actions</option>
                                <option value="VIEW_EVALUATION" <?= $filter_action === 'VIEW_EVALUATION' ? 'selected' : '' ?>>View Evaluation</option>
                                <option value="VIEW_EVALUATION_FORM" <?= $filter_action === 'VIEW_EVALUATION_FORM' ? 'selected' : '' ?>>View Evaluation Form</option>
                            </optgroup>
                            <optgroup label="Authentication">
                                <option value="LOGIN" <?= $filter_action === 'LOGIN' ? 'selected' : '' ?>>Login</option>
                                <option value="LOGOUT" <?= $filter_action === 'LOGOUT' ? 'selected' : '' ?>>Logout</option>
                                <option value="AUTH" <?= $filter_action === 'AUTH' ? 'selected' : '' ?>>Authentication</option>
                            </optgroup>
                            <optgroup label="System Actions">
                                <option value="CLEAR" <?= $filter_action === 'CLEAR' ? 'selected' : '' ?>>Clear Actions</option>
                                <option value="SYSTEM" <?= $filter_action === 'SYSTEM' ? 'selected' : '' ?>>System Actions</option>
                                <option value="SUCCESS" <?= $filter_action === 'SUCCESS' ? 'selected' : '' ?>>Success Actions</option>
                                <option value="USER" <?= $filter_action === 'USER' ? 'selected' : '' ?>>User Actions</option>
                                <option value="ERROR" <?= $filter_action === 'ERROR' ? 'selected' : '' ?>>Errors</option>
                            </optgroup>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">User Name</label>
                        <input type="text" name="filter_user" class="form-control" 
                               value="<?= htmlspecialchars($filter_user) ?>" 
                               placeholder="Search by user name...">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date</label>
                        <select name="filter_date" class="form-select">
                            <option value="">All Dates</option>
                            <?php foreach ($unique_dates as $date): ?>
                                <option value="<?= htmlspecialchars($date) ?>" 
                                    <?= $filter_date === $date ? 'selected' : '' ?>>
                                    <?= date('M j, Y', strtotime($date)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <div class="w-100">
                            <button type="submit" class="btn btn-primary me-2 w-100 mb-2">
                                <i class="fas fa-search"></i> Apply Filters
                            </button>
                            <a href="audit_logs.php" class="btn btn-outline-secondary w-100">
                                <i class="fas fa-times"></i> Clear Filters
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="fas fa-list-alt"></i> Activity Log 
                    <?php if ($filter_action || $filter_user || $filter_date): ?>
                        <small class="text-muted">(Filtered)</small>
                    <?php endif; ?>
                </span>

                <div>
                    <button type="button" class="btn btn-info btn-sm me-2" onclick="toggleMarkChanges()">
                        <i class="fas fa-eye"></i> Toggle Mark Changes
                    </button>
                    <form method="POST" onsubmit="return confirm('Are you sure you want to clear ALL audit logs? This action cannot be undone.');" class="d-inline">
                        <button type="submit" name="clear_logs" class="btn btn-danger btn-sm">
                            <i class="fas fa-trash-alt"></i> Clear All Logs
                        </button>
                    </form>
                </div>
            </div>

            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover audit-log-table">
                        <thead class="table-light">
                            <tr>
                                <th class="timestamp-cell">Timestamp</th>
                                <th>User (Role)</th>
                                <th class="action-cell">Action</th>
                                <th>Session ID</th>
                                <th>Details</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (mysqli_num_rows($logs) > 0): ?>
                                <?php while ($log = mysqli_fetch_assoc($logs)): ?>
                                <?php
                                $action = $log['action'];
                                $is_mark_change = strpos($action, 'MARK_UPDATE') !== false;
                                $is_evaluation = strpos($action, 'EVALUATION_') !== false;
                                $is_view = strpos($action, 'VIEW_') !== false;
                                $is_total_update = strpos($action, 'TOTAL_MARKS_UPDATE') !== false;
                                $is_create = strpos($action, 'CREATE') !== false || strpos($action, 'CREATED') !== false;
                                $is_update = strpos($action, 'UPDATE') !== false || strpos($action, 'UPDATED') !== false;
                                $is_delete = strpos($action, 'DELETE') !== false;
                                $is_error = strpos($action, 'ERROR') !== false;
                                $is_clear = strpos($action, 'CLEAR') !== false;
                                $is_system = $action === 'SYSTEM' || strpos($action, 'SYSTEM') !== false;
                                $is_login = strpos($action, 'LOGIN') !== false;
                                $is_logout = strpos($action, 'LOGOUT') !== false;
                                $is_auth = strpos($action, 'AUTH') !== false;
                                $is_success = strpos($action, 'SUCCESS') !== false;
                                $is_user = strpos($action, 'USER') !== false;
                                $is_log_action = strpos($action, 'LOG') !== false && !$is_login && !$is_logout;

                                // Determine badge class and icon
                                $badge_class = 'secondary';
                                $icon = 'fas fa-circle';
                                $category = 'General';

                                if ($is_system) {
                                    $badge_class = 'system';
                                    $icon = 'fas fa-cog';
                                    $category = 'System';
                                } elseif ($is_success) {
                                    $badge_class = 'success';
                                    $icon = 'fas fa-check-circle';
                                    $category = 'Success';
                                } elseif ($is_user) {
                                    $badge_class = 'user';
                                    $icon = 'fas fa-user';
                                    $category = 'User';
                                } elseif ($is_log_action) {
                                    $badge_class = 'log';
                                    $icon = 'fas fa-file-alt';
                                    $category = 'Log';
                                } elseif ($is_login) {
                                    $badge_class = 'login';
                                    $icon = 'fas fa-sign-in-alt';
                                    $category = 'Authentication';
                                } elseif ($is_logout) {
                                    $badge_class = 'logout';
                                    $icon = 'fas fa-sign-out-alt';
                                    $category = 'Authentication';
                                } elseif ($is_auth) {
                                    $badge_class = 'auth';
                                    $icon = 'fas fa-user-shield';
                                    $category = 'Authentication';
                                } elseif ($is_mark_change) {
                                    $badge_class = 'mark-update';
                                    $icon = 'fas fa-edit';
                                    $category = 'Mark Update';
                                } elseif ($is_total_update) {
                                    $badge_class = 'total-update';
                                    $icon = 'fas fa-calculator';
                                    $category = 'Total Marks';
                                } elseif ($is_evaluation) {
                                    $badge_class = 'evaluation';
                                    $icon = 'fas fa-check-circle';
                                    $category = 'Evaluation';
                                } elseif ($is_view) {
                                    $badge_class = 'view';
                                    $icon = 'fas fa-eye';
                                    $category = 'View';
                                } elseif ($is_create) {
                                    $badge_class = 'create';
                                    $icon = 'fas fa-plus-circle';
                                    $category = 'Create';
                                } elseif ($is_update) {
                                    $badge_class = 'update';
                                    $icon = 'fas fa-sync-alt';
                                    $category = 'Update';
                                } elseif ($is_delete) {
                                    $badge_class = 'delete';
                                    $icon = 'fas fa-trash-alt';
                                    $category = 'Delete';
                                } elseif ($is_error) {
                                    $badge_class = 'error';
                                    $icon = 'fas fa-exclamation-triangle';
                                    $category = 'Error';
                                } elseif ($is_clear) {
                                    $badge_class = 'clear';
                                    $icon = 'fas fa-broom';
                                    $category = 'Clear';
                                }

                                $row_class = $is_mark_change ? 'change-highlight' : '';

                                // Format action text for display
                                $display_action = $action;
                                if (strpos($action, 'EVALUATION_') === 0) {
                                    $display_action = str_replace('EVALUATION_', '', $action);
                                } elseif (strpos($action, 'VIEW_') === 0) {
                                    $display_action = str_replace('VIEW_', '', $action);
                                }
                                $display_action = str_replace('_', ' ', $display_action);
                                $display_action = ucwords(strtolower($display_action));
                                ?>
                                <tr class="<?= $row_class ?>" data-action-type="<?= $is_mark_change ? 'mark-change' : 'other' ?>">
                                    <td class="text-nowrap">
                                        <small class="text-muted d-block"><?php echo format_datetime($log['created_at']); ?></small>
                                        <small class="text-muted"><?php echo time_ago($log['created_at']); ?></small>
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($log['full_name'] ?? 'System'); ?></strong>
                                        </div>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars($log['role'] ?? 'N/A'); ?>
                                        </small>
                                    </td>
                                    <td class="action-cell">
                                        <span class="badge badge-<?= $badge_class ?> badge-action d-inline-flex align-items-center">
                                            <i class="<?= $icon ?> action-icon"></i>
                                            <?php echo htmlspecialchars($display_action); ?>
                                        </span>
                                        <small class="action-category text-muted">
                                            <?php echo htmlspecialchars($category); ?>
                                        </small>
                                        <?php if ($log['table_name']): ?>
                                            <br>
                                            <small class="text-muted">
                                                Table: <?php echo htmlspecialchars($log['table_name']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($log['record_id']): ?>
                                            <code><?php echo htmlspecialchars($log['record_id']); ?></code>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="max-width: 400px;">
                                        <div class="details-expandable" onclick="toggleDetails(this)">
                                            <small class="text-break"><?php echo htmlspecialchars($log['details'] ?? '-'); ?></small>
                                            <?php if (strlen($log['details'] ?? '') > 100): ?>
                                                <small class="text-primary d-block mt-1">
                                                    <i class="fas fa-chevron-down"></i> Click to expand
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <code class="small"><?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?></code>
                                        <?php if ($log['user_agent']): ?>
                                            <br>
                                            <small class="text-muted" title="<?php echo htmlspecialchars($log['user_agent']); ?>">
                                                <?php 
                                                $ua = htmlspecialchars($log['user_agent']);
                                                echo strlen($ua) > 25 ? substr($ua, 0, 25) . '...' : $ua;
                                                ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                        No audit logs found
                                        <?php if ($filter_action || $filter_user || $filter_date): ?>
                                            <div class="mt-2">
                                                <small>Try adjusting your filters</small>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Summary Statistics -->
                <?php
                $total_logs = mysqli_num_rows($logs);
                if ($total_logs > 0): 
                    mysqli_data_seek($logs, 0);
                    
                    $action_counts = [];
                    $user_counts = [];
                    $mark_change_count = 0;
                    $evaluation_count = 0;
                    $login_count = 0;
                    $logout_count = 0;
                    
                    while ($log = mysqli_fetch_assoc($logs)) {
                        $action = $log['action'];
                        $user = $log['full_name'] ?? 'System';
                        
                        $action_counts[$action] = ($action_counts[$action] ?? 0) + 1;
                        $user_counts[$user] = ($user_counts[$user] ?? 0) + 1;
                        
                        if (strpos($action, 'MARK_UPDATE') !== false) $mark_change_count++;
                        if (strpos($action, 'EVALUATION_') !== false) $evaluation_count++;
                        if (strpos($action, 'LOGIN') !== false) $login_count++;
                        if (strpos($action, 'LOGOUT') !== false) $logout_count++;
                    }
                    
                    arsort($action_counts);
                    arsort($user_counts);
                    
                    $top_user = key($user_counts);
                    $top_user_count = current($user_counts);
                    $top_action = key($action_counts);
                    $top_action_count = current($action_counts);
                ?>
                <div class="mt-4 p-3 bg-light rounded">
                    <h6><i class="fas fa-chart-bar"></i> Activity Summary</h6>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <strong>Total Logs:</strong> <span class="badge bg-primary"><?= $total_logs ?></span><br>
                                <strong>Mark Changes:</strong> <span class="badge bg-info"><?= $mark_change_count ?></span><br>
                                <strong>Evaluations:</strong> <span class="badge bg-success"><?= $evaluation_count ?></span><br>
                                <strong>Logins:</strong> <span class="badge login"><?= $login_count ?></span>
                                <strong>Logouts:</strong> <span class="badge logout"><?= $logout_count ?></span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <strong>Most Active User:</strong><br>
                            <div class="d-flex align-items-center mt-1">
                                <i class="fas fa-user-circle text-muted me-2"></i>
                                <span><?= htmlspecialchars($top_user) ?></span>
                                <span class="badge bg-secondary ms-2"><?= $top_user_count ?> actions</span>
                            </div>
                            <strong class="mt-2">Top Actions:</strong>
                            <ul class="mb-0">
                                <?php $count = 0; ?>
                                <?php foreach ($action_counts as $action => $action_count): ?>
                                    <?php if ($count++ < 4): ?>
                                        <li>
                                            <span class="badge bg-light text-dark"><?= $action_count ?></span>
                                            <?= htmlspecialchars($action) ?>
                                        </li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <div class="col-md-4">
                            <strong>Recent Activity Period:</strong><br>
                            <small class="text-muted">
                                <?php
                                mysqli_data_seek($logs, 0);
                                $first_log = mysqli_fetch_assoc($logs);
                                $last_log = $first_log;
                                while ($log = mysqli_fetch_assoc($logs)) {
                                    $last_log = $log;
                                }
                                ?>
                                From: <?= format_datetime($last_log['created_at']) ?><br>
                                To: <?= format_datetime($first_log['created_at']) ?>
                            </small>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function toggleDetails(element) {
    const details = element.querySelector('small:first-child').textContent;
    if (details.length > 100) {
        const expandedDiv = element.querySelector('.expanded-details');
        if (expandedDiv) {
            expandedDiv.remove();
            element.querySelector('.text-primary').innerHTML = '<i class="fas fa-chevron-down"></i> Click to expand';
        } else {
            const expanded = document.createElement('div');
            expanded.className = 'expanded-details';
            expanded.innerHTML = `<small>${details}</small>`;
            element.appendChild(expanded);
            element.querySelector('.text-primary').innerHTML = '<i class="fas fa-chevron-up"></i> Click to collapse';
        }
    }
}

function toggleMarkChanges() {
    const markChangeRows = document.querySelectorAll('tr[data-action-type="mark-change"]');
    const isVisible = markChangeRows[0]?.style.display !== 'none';
    
    markChangeRows.forEach(row => {
        row.style.display = isVisible ? 'none' : '';
    });
    
    const button = event.target;
    button.innerHTML = isVisible ? 
        '<i class="fas fa-eye"></i> Show Mark Changes' : 
        '<i class="fas fa-eye-slash"></i> Hide Mark Changes';
    
    // Show notification
    const action = isVisible ? 'hidden' : 'shown';
    showNotification(`Mark changes ${action}`, 'info');
}

function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
    notification.style.cssText = 'top: 20px; right: 20px; z-index: 1050; min-width: 300px;';
    notification.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        if (notification.parentNode) {
            notification.remove();
        }
    }, 3000);
}

// Auto-refresh every 30 seconds if on the page
setTimeout(() => {
    if (!document.hidden && window.location.search === '') {
        window.location.reload();
    }
}, 30000);

// Add keyboard shortcut to toggle mark changes
document.addEventListener('keydown', (e) => {
    if (e.ctrlKey && e.key === 'm') {
        e.preventDefault();
        toggleMarkChanges();
    }
});
</script>

<?php include '../templates/footer.php'; ?>
<style>
.sidebar {
    height: 100vh;
    overflow-y: auto;
}
</style>

<div class="sidebar">
    <div class="sidebar-header">
        <h4><i class="fas fa-graduation-cap"></i> Admin Panel</h4>
        <p style="margin: 5px 0 0; font-size: 14px;"><?php echo htmlspecialchars($_SESSION['full_name']); ?></p>
    </div>
    <ul class="sidebar-menu">
        <li><a href="../admin/dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
       
        <!-- ✅ Fixed Role-based Management Items -->
        <li><a href="../admin/manage_admin_users.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_admin_users.php' ? 'active' : ''; ?>"><i class="fas fa-user-shield"></i> Admins</a></li>
        <li><a href="../admin/manage_teacher_users.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_teacher_users.php' ? 'active' : ''; ?>"><i class="fas fa-chalkboard-teacher"></i> Teachers</a></li>
        <li><a href="../admin/manage_student_users.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_student_users.php' ? 'active' : ''; ?>"><i class="fas fa-user-graduate"></i> Students</a></li>

        <li><a href="../admin/manage_departments.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_departments.php' ? 'active' : ''; ?>"><i class="fas fa-building"></i> Departments</a></li>
        <li><a href="../admin/manage_batches.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_batches.php' ? 'active' : ''; ?>"><i class="fas fa-layer-group"></i> Batches</a></li>

        <!-- ✅ Fixed Manage Exam Item -->
        <li><a href="../admin/mega_exams.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'mega_exams.php' ? 'active' : ''; ?>"><i class="fas fa-book"></i> Manage Exam</a></li>

        <li><a href="../admin/exam_approvals.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'exam_approvals.php' ? 'active' : ''; ?>"><i class="fas fa-check-circle"></i> Paper Approvals</a></li>
        <li><a href="../admin/results.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'results.php' ? 'active' : ''; ?>"><i class="fas fa-chart-line"></i> Results</a></li>
        <li><a href="../admin/audit_logs.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'audit_logs.php' ? 'active' : ''; ?>"><i class="fas fa-history"></i> Audit Logs</a></li>

        <li><a href="../admin/profile.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : ''; ?>"><i class="fas fa-user"></i> Profile</a></li>
        <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
    </ul>
</div>
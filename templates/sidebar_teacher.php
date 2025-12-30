<div class="sidebar">
    <div class="sidebar-header">
        <h4><i class="fas fa-chalkboard-teacher"></i> Teacher Panel</h4>
        <p style="margin: 5px 0 0; font-size: 14px;"><?php echo htmlspecialchars($_SESSION['full_name']); ?></p>
    </div>

    <ul class="sidebar-menu">

        <li>
            <a href="../teacher/dashboard.php"
               class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
        </li>

        <li>
            <a href="../teacher/create_exam.php"
               class="<?php echo basename($_SERVER['PHP_SELF']) == 'create_exam.php' ? 'active' : ''; ?>">
                <i class="fas fa-plus-circle"></i> Create Paper
            </a>
        </li>

        <li>
            <a href="../teacher/manage_sections.php"
               class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_sections.php' ? 'active' : ''; ?>">
                <i class="fas fa-list"></i> Manage Papers
            </a>
        </li>

        <li>
            <a href="../teacher/evaluate.php"
               class="<?php echo basename($_SERVER['PHP_SELF']) == 'evaluate.php' ? 'active' : ''; ?>">
                <i class="fas fa-edit"></i> Evaluate Answers
            </a>
        </li>

        <li>
            <a href="../teacher/results.php"
               class="<?php echo basename($_SERVER['PHP_SELF']) == 'results.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-line"></i> Results
            </a>
        </li>

        <!-- ✅ NEW PROFILE MENU ITEM -->
        <li>
            <a href="../teacher/profile.php"
               class="<?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : ''; ?>">
                <i class="fas fa-user"></i> Profile
            </a>
        </li>

        <li>
            <a href="../logout.php">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </li>

    </ul>
</div>

<div class="sidebar">
    <div class="sidebar-header">
        <h4><i class="fas fa-user-graduate"></i> Student Panel</h4>
        <p style="margin: 5px 0 0; font-size: 14px;"><?php echo htmlspecialchars($_SESSION['full_name']); ?></p>
    </div>

    <ul class="sidebar-menu">

        <li>
            <a href="../student/dashboard.php"
               class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
        </li>

        <li>
            <a href="../student/exams.php"
               class="<?php echo basename($_SERVER['PHP_SELF']) == 'exams.php' ? 'active' : ''; ?>">
                <i class="fas fa-file-alt"></i> Available Papers
            </a>
        </li>

        <li>
            <a href="../student/results.php"
               class="<?php echo basename($_SERVER['PHP_SELF']) == 'results.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-bar"></i> My Results
            </a>
        </li>

        <!-- ✅ NEW PROFILE MENU ITEM -->
        <li>
            <a href="../student/profile.php"
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

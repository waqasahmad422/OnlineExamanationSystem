<?php
    require_once '../app/config.php';
    require_once '../app/helpers.php';
    require_once '../app/auth.php';

    require_role(['teacher']);

    $teacher_id = $_SESSION['user_id'];
    $page_title = "Student Results";

    // Handle result publishing
    if (isset($_POST['publish_results']) && isset($_POST['exam_id'])) {
        $exam_id_to_publish = intval($_POST['exam_id']);
        
        // Update the exam status to published (using is_approved field)
        $publish_query = mysqli_prepare($conn, "
            UPDATE exams 
            SET is_approved = 1 
            WHERE id = ? AND teacher_id = ?
        ");
        mysqli_stmt_bind_param($publish_query, "ii", $exam_id_to_publish, $teacher_id);
        $publish_success = mysqli_stmt_execute($publish_query);
        
        if ($publish_success) {
            $_SESSION['message'] = "Results published successfully! Students can now view their results.";
            $_SESSION['message_type'] = "success";
        } else {
            $_SESSION['message'] = "Error publishing results. Please try again.";
            $_SESSION['message_type'] = "error";
        }
        
        // Redirect to avoid form resubmission
        header("Location: results.php?exam_id=" . $exam_id_to_publish);
        exit();
    }

    // Function to get mega exams with their exams
    function getMegaExams($conn, $teacher_id) {
        // Fetch all mega exams with their completed exams
        $mega_exams_query = mysqli_prepare($conn, "
            SELECT 
                me.id as mega_exam_id,
                me.title as mega_exam_title,
                me.mega_exam_code,
                COUNT(DISTINCT e.id) as exam_count,
                GROUP_CONCAT(DISTINCT e.id) as exam_ids
            FROM mega_exams me
            JOIN exams e ON me.id = e.mega_exam_id
            JOIN student_exam_sessions ses ON e.id = ses.exam_id
            JOIN evaluations ev ON ev.session_id = ses.id
            WHERE e.teacher_id = ? 
            AND ev.status = 'completed'
            GROUP BY me.id
            ORDER BY me.title ASC
        ");
        mysqli_stmt_bind_param($mega_exams_query, "i", $teacher_id);
        mysqli_stmt_execute($mega_exams_query);
        $mega_exams_result = mysqli_stmt_get_result($mega_exams_query);

        $mega_exams = [];
        
        while ($row = mysqli_fetch_assoc($mega_exams_result)) {
            $mega_exam_id = $row['mega_exam_id'];
            $exam_ids = explode(',', $row['exam_ids']);
            
            $mega_exams[$mega_exam_id] = [
                'mega_exam_id' => $mega_exam_id,
                'mega_exam_title' => $row['mega_exam_title'],
                'mega_exam_code' => $row['mega_exam_code'],
                'exam_count' => $row['exam_count'],
                'exam_ids' => $exam_ids
            ];
        }

        return $mega_exams;
    }

    // Fetch available mega exams
    $available_mega_exams = getMegaExams($conn, $teacher_id);

    // Get selected mega exam from URL or default to first mega exam
    $selected_mega_exam_id = null;
    if (isset($_GET['mega_exam_id']) && !empty($_GET['mega_exam_id']) && isset($available_mega_exams[$_GET['mega_exam_id']])) {
        $selected_mega_exam_id = intval($_GET['mega_exam_id']);
    } elseif (count($available_mega_exams) > 0) {
        $selected_mega_exam_id = array_key_first($available_mega_exams);
    }

    // Fetch all departments for the selected mega exam
    if ($selected_mega_exam_id && isset($available_mega_exams[$selected_mega_exam_id])) {
        $exam_ids = $available_mega_exams[$selected_mega_exam_id]['exam_ids'];
        $placeholders = str_repeat('?,', count($exam_ids) - 1) . '?';
        
        $dept_query = mysqli_prepare($conn, "
            SELECT DISTINCT d.id, d.name 
            FROM departments d
            JOIN exams e ON e.department_id = d.id
            WHERE e.teacher_id = ? 
            AND e.mega_exam_id = ?
            AND e.id IN ($placeholders)
            ORDER BY d.name ASC
        ");
        
        $params = array_merge([$teacher_id, $selected_mega_exam_id], $exam_ids);
        $types = str_repeat('i', count($params));
        mysqli_stmt_bind_param($dept_query, $types, ...$params);
    } else {
        $dept_query = mysqli_prepare($conn, "
            SELECT DISTINCT d.id, d.name 
            FROM departments d
            JOIN exams e ON e.department_id = d.id
            WHERE e.teacher_id = ?
            ORDER BY d.name ASC
        ");
        mysqli_stmt_bind_param($dept_query, "i", $teacher_id);
    }
    
    mysqli_stmt_execute($dept_query);
    $departments = mysqli_stmt_get_result($dept_query);
    $departments_data = mysqli_fetch_all($departments, MYSQLI_ASSOC);

    // Get selected department from URL or default to first department
    $selected_dept_id = null;
    if (isset($_GET['dept_id']) && !empty($_GET['dept_id'])) {
        $selected_dept_id = intval($_GET['dept_id']);
    } elseif (count($departments_data) > 0) {
        $selected_dept_id = $departments_data[0]['id'];
    }

    // Fetch semesters for selected department and mega exam
    if ($selected_mega_exam_id && isset($available_mega_exams[$selected_mega_exam_id])) {
        $exam_ids = $available_mega_exams[$selected_mega_exam_id]['exam_ids'];
        $placeholders = str_repeat('?,', count($exam_ids) - 1) . '?';
        
        $semesters_query = mysqli_prepare($conn, "
            SELECT DISTINCT e.semester 
            FROM exams e
            WHERE e.teacher_id = ?
            AND e.mega_exam_id = ?
            AND e.department_id = ?
            AND e.id IN ($placeholders)
            ORDER BY e.semester ASC
        ");
        
        $params = array_merge([$teacher_id, $selected_mega_exam_id, $selected_dept_id], $exam_ids);
        $types = str_repeat('i', count($params));
        mysqli_stmt_bind_param($semesters_query, $types, ...$params);
    } else {
        $semesters_query = mysqli_prepare($conn, "
            SELECT DISTINCT e.semester 
            FROM exams e
            WHERE e.teacher_id = ?
            AND e.department_id = ?
            ORDER BY e.semester ASC
        ");
        mysqli_stmt_bind_param($semesters_query, "ii", $teacher_id, $selected_dept_id);
    }
    
    mysqli_stmt_execute($semesters_query);
    $semesters_result = mysqli_stmt_get_result($semesters_query);
    $semesters_data = mysqli_fetch_all($semesters_result, MYSQLI_ASSOC);

    // Function to get students data organized by papers for a specific semester
   function getStudentsDataByPapers($conn, $teacher_id, $dept_id, $semester, $mega_exam_id = null, $available_mega_exams = []) {
    if ($mega_exam_id && isset($available_mega_exams[$mega_exam_id])) {
        $exam_ids = $available_mega_exams[$mega_exam_id]['exam_ids'];
        $placeholders = str_repeat('?,', count($exam_ids) - 1) . '?';
        
        $query_str = "
            SELECT 
                u.id AS student_id,
                u.roll_number,
                u.full_name AS student_name,
                d.name AS department_name,
                e.semester,
                e.id AS exam_id,
                e.title AS exam_title,
                ev.marks_obtained,
                ev.total_marks,
                (ev.marks_obtained / ev.total_marks * 100) AS percentage,
                e.is_approved,
                ev.evaluated_at
            FROM users u
            JOIN student_exam_sessions ses ON u.id = ses.student_id
            JOIN exams e ON ses.exam_id = e.id
            JOIN departments d ON e.department_id = d.id
            JOIN evaluations ev ON ev.session_id = ses.id
            WHERE u.role = 'student'
            AND e.teacher_id = ?
            AND e.mega_exam_id = ?
            AND e.department_id = ?
            AND e.semester = ?
            AND e.id IN ($placeholders)
            AND ev.status = 'completed'
            ORDER BY e.title ASC, u.roll_number ASC
        ";
        
        $params = array_merge([$teacher_id, $mega_exam_id, $dept_id, $semester], $exam_ids);
        $param_types = str_repeat('i', count($params));
        
        $students_query = mysqli_prepare($conn, $query_str);
        mysqli_stmt_bind_param($students_query, $param_types, ...$params);
    } else {
        $students_query = mysqli_prepare($conn, "
            SELECT 
                u.id AS student_id,
                u.roll_number,
                u.full_name AS student_name,
                d.name AS department_name,
                e.semester,
                e.id AS exam_id,
                e.title AS exam_title,
                ev.marks_obtained,
                ev.total_marks,
                (ev.marks_obtained / ev.total_marks * 100) AS percentage,
                e.is_approved,
                ev.evaluated_at
            FROM users u
            JOIN student_exam_sessions ses ON u.id = ses.student_id
            JOIN exams e ON ses.exam_id = e.id
            JOIN departments d ON e.department_id = d.id
            JOIN evaluations ev ON ev.session_id = ses.id
            WHERE u.role = 'student'
            AND e.teacher_id = ?
            AND e.department_id = ?
            AND e.semester = ?
            AND ev.status = 'completed'
            ORDER BY e.title ASC, u.roll_number ASC
        ");
        mysqli_stmt_bind_param($students_query, "iii", $teacher_id, $dept_id, $semester);
    }
    
    mysqli_stmt_execute($students_query);
    $students_result = mysqli_stmt_get_result($students_query);

    // Organize data by papers
    $papers_data = [];
    $all_students = [];

    while ($row = mysqli_fetch_assoc($students_result)) {
        $exam_id = $row['exam_id'];
        $student_id = $row['student_id'];
        
        // Initialize paper data if not exists
        if (!isset($papers_data[$exam_id])) {
            $papers_data[$exam_id] = [
                'exam_title' => $row['exam_title'],
                'exam_id' => $exam_id,
                'is_approved' => $row['is_approved'],
                'students' => [],
                'total_students' => 0,
                'average_percentage' => 0
            ];
        }
        
        // Add student to this paper
        $papers_data[$exam_id]['students'][$student_id] = [
            'roll_number' => $row['roll_number'],
            'student_name' => $row['student_name'],
            'exam_title' => $row['exam_title'], // Add paper name to student data
            'marks_obtained' => $row['marks_obtained'],
            'total_marks' => $row['total_marks'],
            'percentage' => $row['percentage'],
            'evaluated_at' => $row['evaluated_at']
        ];
        $papers_data[$exam_id]['total_students']++;
    }

    // Calculate paper-specific positions for each paper
    foreach ($papers_data as $exam_id => &$paper) {
        // Sort students by percentage for this paper
        uasort($paper['students'], function($a, $b) {
            return $b['percentage'] <=> $a['percentage'];
        });

        // Assign positions for this paper
        $position = 1;
        foreach ($paper['students'] as $student_id => &$student_data) {
            $student_data['paper_position'] = $position++;
        }

        // Calculate average percentage for this paper
        $total_percentage = 0;
        $student_count = 0;
        
        foreach ($paper['students'] as $student) {
            $total_percentage += $student['percentage'];
            $student_count++;
        }
        
        $paper['average_percentage'] = $student_count > 0 ? $total_percentage / $student_count : 0;
    }

    return [
        'papers_data' => $papers_data,
        'total_papers' => count($papers_data),
        'total_students' => count($all_students)
    ];
}

    // Get data for all semesters when department is selected
    $all_semesters_data = [];
    if ($selected_dept_id && count($semesters_data) > 0) {
        foreach ($semesters_data as $semester_row) {
            $semester = $semester_row['semester'];
            $all_semesters_data[$semester] = getStudentsDataByPapers(
                $conn, 
                $teacher_id, 
                $selected_dept_id, 
                $semester, 
                $selected_mega_exam_id
            );
        }
    }

    include '../templates/header.php';
    include '../templates/sidebar_teacher.php';
    ?>

    <div class="main-content">
        <div class="top-navbar mb-3">
            <h4><i class="fas fa-chart-line"></i> Student Results</h4>
            <?php if ($selected_mega_exam_id && isset($available_mega_exams[$selected_mega_exam_id])): ?>
                <small class="text-muted">
                    Mega Exam: <?= htmlspecialchars($available_mega_exams[$selected_mega_exam_id]['mega_exam_title']) ?>
                </small>
            <?php endif; ?>
        </div>

        <div class="content-area">

            <div id="examTable" class="card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <span>Student Results</span>
                        <?php if ($selected_dept_id): ?>
                            <div class="d-flex align-items-center">
                                <small class="text-muted me-3">
                                    <strong>
                                        Department: <?= htmlspecialchars($departments_data[array_search($selected_dept_id, array_column($departments_data, 'id'))]['name']) ?>
                                        <?= $selected_mega_exam_id ? ' | ' . htmlspecialchars($available_mega_exams[$selected_mega_exam_id]['mega_exam_title']) : '' ?>
                                    </strong>
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card-body">
                    <!-- Mega Exam Tabs Navigation -->
                    <?php if (count($available_mega_exams) > 0): ?>
                    <div class="mb-4">
                        <h6 class="text-muted mb-2">Select Mega Exam:</h6>
                        <ul class="nav nav-pills" id="megaExamTabs" role="tablist">
                            <?php foreach ($available_mega_exams as $mega_exam_id => $mega_exam): 
                                $is_current = ($mega_exam_id === $selected_mega_exam_id);
                                $badge_class = 'bg-primary';
                            ?>
                            <li class="nav-item" role="presentation">
                                <a class="nav-link <?= $is_current ? 'active' : '' ?>" 
                                href="?mega_exam_id=<?= $mega_exam_id ?><?= $selected_dept_id ? '&dept_id=' . $selected_dept_id : '' ?>"
                                role="tab"
                                title="<?= htmlspecialchars($mega_exam['mega_exam_title']) ?> - <?= $mega_exam['exam_count'] ?> exam(s)">
                                    <i class="fas fa-layer-group me-1"></i>
                                    <?= htmlspecialchars($mega_exam['mega_exam_title']) ?>
                                    <?php if (!empty($mega_exam['mega_exam_code'])): ?>
                                        <small class="ms-1">(<?= htmlspecialchars($mega_exam['mega_exam_code']) ?>)</small>
                                    <?php endif; ?>
                                    <span class="badge <?= $badge_class ?> ms-1"><?= $mega_exam['exam_count'] ?></span>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <!-- Department Selection -->
                    <?php if (count($departments_data) > 0): ?>
                    <div class="mb-4">
                        <h6 class="text-muted mb-2">Select Department:</h6>
                        <select class="form-select" id="deptSelect" onchange="updateDepartmentSelection(this.value)">
                            <?php foreach ($departments_data as $dept): ?>
                            <option value="<?= $dept['id'] ?>" 
                                    <?= ($dept['id'] == $selected_dept_id) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dept['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <!-- Results for Each Semester -->
                    <div class="table-responsive">
                        <?php if ($selected_dept_id && count($all_semesters_data) > 0): ?>
                            
                            <?php foreach ($all_semesters_data as $semester => $semester_data): 
                                $papers_data = $semester_data['papers_data'];
                                $total_papers = $semester_data['total_papers'];
                                
                                if (count($papers_data) > 0):
                            ?>
                            
                            <div class="semester-section mb-5">
                                <!-- Semester Header -->
                                <div class="d-flex justify-content-between align-items-center mb-3 p-3 bg-light rounded">
                                    <h5 class="mb-0 text-primary">
                                        <i class="fas fa-graduation-cap"></i> Semester <?= $semester ?>
                                    </h5>
                                    <div>
                                        <span class="badge bg-info me-2">
                                            <?= $total_papers ?> Paper<?= $total_papers > 1 ? 's' : '' ?>
                                        </span>
                                    </div>
                                </div>

                                <!-- ALWAYS Show Paper Tabs - Even if only one paper -->
                                <div class="mb-4">
                                    <h6 class="text-muted mb-2">Select Subject:</h6>
                                    <ul class="nav nav-pills" id="paperTabs-<?= $semester ?>" role="tablist">
                                        <?php $paper_index = 0; ?>
                                        <?php foreach ($papers_data as $exam_id => $paper): 
                                            $is_active = ($paper_index === 0);
                                            $paper_slug = "paper-" . $semester . "-" . $exam_id;
                                        ?>
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link <?= $is_active ? 'active' : '' ?>" 
                                                id="tab-<?= $paper_slug ?>" 
                                                data-bs-toggle="tab" 
                                                data-bs-target="#<?= $paper_slug ?>" 
                                                type="button" 
                                                role="tab">
                                                <?= htmlspecialchars($paper['exam_title']) ?>
                                                <span class="badge bg-primary ms-1"><?= $paper['total_students'] ?></span>
                                            </button>
                                        </li>
                                        <?php $paper_index++; ?>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>

                                <!-- Paper Content -->
                                <div class="tab-content" id="paperContent-<?= $semester ?>">
                                    <?php $paper_index = 0; ?>
                                    <?php foreach ($papers_data as $exam_id => $paper): 
                                        $is_active = ($paper_index === 0);
                                        $paper_slug = "paper-" . $semester . "-" . $exam_id;
                                    ?>
                                    <div class="tab-pane fade <?= $is_active ? 'show active' : '' ?>" 
                                         id="<?= $paper_slug ?>" 
                                         role="tabpanel">
                                        
                                        <!-- Paper Header with Paper Name -->
                                        <div class="d-flex justify-content-between align-items-center mb-3 p-3 bg-primary text-white rounded">
                                            <h6 class="mb-0">
                                                <i class="fas fa-file-alt"></i> 
                                                <?= htmlspecialchars($paper['exam_title']) ?>
                                                <?php if (!$paper['is_approved']): ?>
                                                    <small class="text-warning ms-2">
                                                        <i class="fas fa-exclamation-triangle"></i> Unofficial Results
                                                    </small>
                                                <?php endif; ?>
                                            </h6>
                                            <div>
                                                <span class="badge bg-light text-dark me-2">
                                                    <?= $paper['total_students'] ?> Student<?= $paper['total_students'] > 1 ? 's' : '' ?>
                                                </span>
                                                <span class="badge bg-success">
                                                    Avg: <?= number_format($paper['average_percentage'], 2) ?>%
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Paper Results Table with Paper Name Column -->
                                        <table class="table table-striped table-hover table-bordered">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th>Roll No</th>
                                                    <th>Student Name</th>
                                                    <th>Paper Name</th>
                                                    <th class="text-center">Marks</th>
                                                    <th class="text-center">Total</th>
                                                    <th class="text-center">Percentage</th>
                                                    <th class="text-center">Position</th>
                                                    <th class="text-center">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($paper['students'] as $student_id => $student_data): ?>
                                                <tr>
                                                    <td class="fw-bold"><?= htmlspecialchars($student_data['roll_number']) ?></td>
                                                    <td><?= htmlspecialchars($student_data['student_name']) ?></td>
                                                    <td class="fw-bold text-info"><?= htmlspecialchars($student_data['exam_title']) ?></td>
                                                    <td class="text-center fw-bold">
                                                        <?= number_format($student_data['marks_obtained'], 0) ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <?= number_format($student_data['total_marks'], 0) ?>
                                                    </td>
                                                    <td class="text-center fw-bold <?= $student_data['percentage'] >= 35 ? 'text-success' : 'text-danger' ?>">
                                                        <?= number_format($student_data['percentage'], 2) ?>%
                                                    </td>
                                                    <td class="text-center fw-bold">
                                                        <?php if ($student_data['paper_position'] == 1): ?>
                                                            <span class="badge bg-warning text-dark">1st</span>
                                                        <?php elseif ($student_data['paper_position'] == 2): ?>
                                                            <span class="badge bg-secondary">2nd</span>
                                                        <?php elseif ($student_data['paper_position'] == 3): ?>
                                                            <span class="badge bg-danger">3rd</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-light text-dark"><?= $student_data['paper_position'] ?>th</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge <?= $student_data['percentage'] >= 35 ? 'bg-success' : 'bg-danger' ?>">
                                                            <?= $student_data['percentage'] >= 35 ? 'PASS' : 'FAIL' ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php $paper_index++; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-inbox fa-2x text-muted mb-3"></i>
                                <p class="text-muted">No results available for Semester <?= $semester ?></p>
                            </div>
                            <?php endif; ?>
                            
                            <?php endforeach; ?>

                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                <p class="text-muted">
                                    <?php if (count($available_mega_exams) === 0): ?>
                                        No mega exams available. Create and evaluate some exams first.
                                    <?php elseif (!$selected_mega_exam_id): ?>
                                        Please select a mega exam.
                                    <?php elseif (!$selected_dept_id): ?>
                                        Please select a department.
                                    <?php else: ?>
                                        No results found for selected criteria.
                                    <?php endif; ?>
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>

        </div>
    </div>

    <script>
    function updateDepartmentSelection(deptId) {
        const urlParams = new URLSearchParams(window.location.search);
        const megaExamId = urlParams.get('mega_exam_id');
        
        let newUrl = '?';
        if (megaExamId) newUrl += 'mega_exam_id=' + megaExamId + '&';
        newUrl += 'dept_id=' + deptId;
        
        window.location.href = newUrl;
    }
    </script>

    <?php include '../templates/footer.php'; ?>
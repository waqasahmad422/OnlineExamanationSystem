<?php
require_once '../app/config.php';
require_once '../app/helpers.php';
require_once '../app/auth.php';

require_role(['student']);

$session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
$student_id = $_SESSION['user_id'];

// Fetch result data from the correct tables
$result_query = mysqli_prepare($conn, "
    SELECT 
        ses.id AS session_id,
        e.id AS exam_id,
        e.title AS exam_title,
        e.total_marks AS exam_total_marks,
        e.passing_marks,
        e.is_approved,
        ev.marks_obtained,
        ev.total_marks AS evaluation_total,
        ev.feedback,
        ev.evaluated_at,
        u.full_name AS student_name,
        u.roll_number,
        u.email,
        u.semester,
        d.name AS department_name,
        b.name AS batch_name,
        t.full_name AS teacher_name,
        -- Calculate percentage
        (ev.marks_obtained / ev.total_marks * 100) AS percentage
    FROM student_exam_sessions ses
    JOIN exams e ON ses.exam_id = e.id
    JOIN evaluations ev ON ev.session_id = ses.id
    JOIN users u ON ses.student_id = u.id
    JOIN users t ON e.teacher_id = t.id
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN batches b ON u.batch_id = b.id
    WHERE ses.id = ? AND ses.student_id = ?
    LIMIT 1
");
mysqli_stmt_bind_param($result_query, "ii", $session_id, $student_id);
mysqli_stmt_execute($result_query);
$result_data = mysqli_stmt_get_result($result_query);

if (mysqli_num_rows($result_data) === 0) {
    die('<div class="alert alert-danger text-center m-5">Result not found or access denied.</div>');
}

$result = mysqli_fetch_assoc($result_data);

// Check if evaluation is completed
if (!$result) {
    die('<div class="alert alert-danger text-center m-5">Evaluation not completed yet.</div>');
}

// Calculate grade and status based on percentage
$percentage = floatval($result['percentage']);
$is_pass = ($percentage >= 35);

// Calculate grade
if ($percentage >= 80) {
    $grade = 'A+';
    $grade_class = 'success';
} elseif ($percentage >= 70) {
    $grade = 'A';
    $grade_class = 'primary';
} elseif ($percentage >= 60) {
    $grade = 'B';
    $grade_class = 'info';
} elseif ($percentage >= 50) {
    $grade = 'C';
    $grade_class = 'warning';
} elseif ($percentage >= 35) {
    $grade = 'D';
    $grade_class = 'secondary';
} else {
    $grade = 'F';
    $grade_class = 'danger';
}

// Fetch detailed marks breakdown
$marks_query = mysqli_prepare($conn, "
    SELECT 
        q.question_text,
        LOWER(TRIM(q.question_type)) AS question_type,
        q.marks AS question_marks,
        sa.marks_obtained,
        sa.answer_text,
        sa.selected_option_id,
        COALESCE(es.title, 'General') AS section_name
    FROM student_answers sa
    JOIN questions q ON sa.question_id = q.id
    LEFT JOIN exam_sections es ON q.section_id = es.id
    WHERE sa.session_id = ?
    ORDER BY COALESCE(es.id, 0), q.id
");
mysqli_stmt_bind_param($marks_query, "i", $session_id);
mysqli_stmt_execute($marks_query);
$marks_result = mysqli_stmt_get_result($marks_query);

// Calculate section-wise marks
$sections = [];
$total_mcq_marks = 0;
$total_descriptive_marks = 0;
$total_mcq_obtained = 0;
$total_descriptive_obtained = 0;

while ($mark = mysqli_fetch_assoc($marks_result)) {
    $section_name = $mark['section_name'];
    $question_type = $mark['question_type'];
    
    if (!isset($sections[$section_name])) {
        $sections[$section_name] = [
            'mcq_marks' => 0,
            'descriptive_marks' => 0,
            'mcq_obtained' => 0,
            'descriptive_obtained' => 0,
            'questions' => []
        ];
    }
    
    if ($question_type === 'mcq') {
        $sections[$section_name]['mcq_marks'] += floatval($mark['question_marks']);
        $sections[$section_name]['mcq_obtained'] += floatval($mark['marks_obtained']);
        $total_mcq_marks += floatval($mark['question_marks']);
        $total_mcq_obtained += floatval($mark['marks_obtained']);
    } else {
        $sections[$section_name]['descriptive_marks'] += floatval($mark['question_marks']);
        $sections[$section_name]['descriptive_obtained'] += floatval($mark['marks_obtained']);
        $total_descriptive_marks += floatval($mark['question_marks']);
        $total_descriptive_obtained += floatval($mark['marks_obtained']);
    }
    
    $sections[$section_name]['questions'][] = $mark;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Digital Mark Certificate</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @media print {
            .no-print { display: none !important; }
            .dmc-container { box-shadow: none !important; border: 1px solid #000 !important; }
            body { background: white !important; }
        }
        .dmc-container {
            max-width: 800px;
            margin: 20px auto;
            padding: 30px;
            border: 2px solid #2c3e50;
            border-radius: 10px;
            background: white;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .dmc-header {
            text-align: center;
            border-bottom: 3px double #2c3e50;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .dmc-header h1 {
            color: #2c3e50;
            margin: 0;
            font-size: 28px;
            font-weight: bold;
        }
        .dmc-header h3 {
            color: #7f8c8d;
            margin: 5px 0 0 0;
            font-size: 18px;
        }
        .dmc-info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            padding: 5px 0;
            border-bottom: 1px solid #ecf0f1;
        }
        .dmc-table {
            width: 100%;
            margin: 20px 0;
            border: 2px solid #2c3e50;
        }
        .dmc-table th {
            background: #2c3e50;
            color: white;
            text-align: center;
            padding: 12px;
            font-weight: bold;
        }
        .dmc-table td {
            padding: 12px;
            text-align: center;
            border: 1px solid #bdc3c7;
        }
        .signature-section {
            display: flex;
            justify-content: space-between;
            margin-top: 50px;
            padding-top: 20px;
            border-top: 2px solid #2c3e50;
        }
        .signature-box {
            text-align: center;
            width: 45%;
        }
        .signature-line {
            border-bottom: 1px solid #2c3e50;
            margin-bottom: 5px;
            padding-bottom: 25px;
        }
        .result-status {
            font-size: 18px;
            font-weight: bold;
            padding: 8px 15px;
            border-radius: 5px;
        }
        .marks-breakdown {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
        }
        .approval-badge {
            position: absolute;
            top: 20px;
            right: 20px;
            transform: rotate(15deg);
            opacity: 0.8;
        }
    </style>
</head>
<body>
    <div class="dmc-container position-relative">
        <?php if ($result['is_approved']): ?>
            <div class="approval-badge">
                <span class="badge bg-success fs-6 p-2">
                    <i class="fas fa-check-circle"></i> OFFICIAL
                </span>
            </div>
        <?php else: ?>
            <div class="approval-badge">
                <span class="badge bg-warning text-dark fs-6 p-2">
                    <i class="fas fa-clock"></i> UNOFFICIAL
                </span>
            </div>
        <?php endif; ?>
        
        <div class="dmc-header">
            <h1>DIGITAL MARK CERTIFICATE</h1>
            <h3>Online Examination System</h3>
        </div>
        
        <div class="dmc-info-row">
            <strong>Student Name:</strong> <?php echo htmlspecialchars($result['student_name']); ?>
        </div>
        
        <div class="dmc-info-row">
            <strong>Roll Number:</strong> <?php echo htmlspecialchars($result['roll_number']); ?>
        </div>
        
        <div class="dmc-info-row">
            <strong>Email:</strong> <?php echo htmlspecialchars($result['email']); ?>
        </div>
        
        <div class="dmc-info-row">
            <strong>Department:</strong> <?php echo htmlspecialchars($result['department_name']); ?>
            <strong>Batch:</strong> <?php echo htmlspecialchars($result['batch_name']); ?>
        </div>
        
        <div class="dmc-info-row">
            <strong>Semester:</strong> <?php echo htmlspecialchars($result['semester']); ?>
            <strong>Exam:</strong> <?php echo htmlspecialchars($result['exam_title']); ?>
        </div>
        
        <div class="dmc-info-row">
            <strong>Evaluated By:</strong> <?php echo htmlspecialchars($result['teacher_name']); ?>
            <strong>Date:</strong> <?php echo date('M d, Y', strtotime($result['evaluated_at'])); ?>
        </div>

        <!-- Main Result Summary -->
        <table class="dmc-table table table-bordered">
            <thead>
                <tr>
                    <th>Total Marks</th>
                    <th>Marks Obtained</th>
                    <th>Percentage</th>
                    <th>Grade</th>
                    <th>Result Status</th>
                </tr>
            </thead>
            <tbody>
                <tr class="text-center">
                    <td><?php echo number_format($result['evaluation_total'], 2); ?></td>
                    <td><?php echo number_format($result['marks_obtained'], 2); ?></td>
                    <td><?php echo number_format($percentage, 2); ?>%</td>
                    <td><span class="badge bg-<?php echo $grade_class; ?> fs-6"><?php echo $grade; ?></span></td>
                    <td>
                        <?php if ($is_pass): ?>
                            <span class="result-status text-success bg-light">PASS</span>
                        <?php else: ?>
                            <span class="result-status text-danger bg-light">FAIL</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Marks Breakdown -->
        <div class="marks-breakdown">
            <h5 class="text-center mb-3"><i class="fas fa-chart-bar"></i> Marks Breakdown</h5>
            <div class="row text-center">
                <div class="col-md-6">
                    <h6 class="text-primary">MCQ Section</h6>
                    <div class="h4 text-primary"><?php echo number_format($total_mcq_obtained, 2); ?> / <?php echo number_format($total_mcq_marks, 2); ?></div>
                    <div class="small text-muted">
                        <?php echo $total_mcq_marks > 0 ? number_format(($total_mcq_obtained / $total_mcq_marks) * 100, 2) : '0'; ?>%
                    </div>
                </div>
                <div class="col-md-6">
                    <h6 class="text-success">Descriptive Section</h6>
                    <div class="h4 text-success"><?php echo number_format($total_descriptive_obtained, 2); ?> / <?php echo number_format($total_descriptive_marks, 2); ?></div>
                    <div class="small text-muted">
                        <?php echo $total_descriptive_marks > 0 ? number_format(($total_descriptive_obtained / $total_descriptive_marks) * 100, 2) : '0'; ?>%
                    </div>
                </div>
            </div>
        </div>
        
        <?php if (!empty($result['feedback'])): ?>
        <div class="mt-4 p-3 bg-light rounded">
            <strong>Teacher's Feedback:</strong>
            <p class="mb-0 mt-2"><?php echo nl2br(htmlspecialchars($result['feedback'])); ?></p>
        </div>
        <?php endif; ?>
        
        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-line"></div>
                <p>Teacher's Signature</p>
            </div>
            <div class="signature-box">
                <div class="signature-line"></div>
                <p>Controller of Examinations</p>
            </div>
        </div>
        
        <div class="text-center mt-4 text-muted">
            <small>Generated on: <?php echo date('F d, Y \a\t h:i A'); ?></small>
            <?php if (!$result['is_approved']): ?>
                <br><small class="text-warning"><i class="fas fa-exclamation-triangle"></i> This is an unofficial result pending final approval</small>
            <?php endif; ?>
        </div>
        
        <div class="text-center mt-4 no-print">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="fas fa-print"></i> Print Certificate
            </button>
            <a href="./results.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Results
            </a>
        </div>
    </div>
</body>
</html>
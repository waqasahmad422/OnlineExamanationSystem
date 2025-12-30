<?php
require_once '../app/config.php';
require_once '../app/helpers.php';
require_once '../app/auth.php';

// FPDF library (download and place at app/lib/fpdf.php)
require_once __DIR__ . '/../app/lib/fpdf.php';

require_role(['teacher']);

$teacher_id = $_SESSION['user_id'];
$session_id = intval($_GET['session_id'] ?? 0);
if (!$session_id) {
    die('No session specified');
}

// Verify ownership: ensure the exam belongs to this teacher
$verify = mysqli_prepare($conn, "
    SELECT ses.*, e.title, e.passing_marks, u.full_name, u.roll_number
    FROM student_exam_sessions ses
    JOIN exams e ON ses.exam_id = e.id
    JOIN users u ON ses.student_id = u.id
    WHERE ses.id = ? AND e.teacher_id = ? LIMIT 1
");
mysqli_stmt_bind_param($verify, "ii", $session_id, $teacher_id);
mysqli_stmt_execute($verify);
$vres = mysqli_stmt_get_result($verify);
$session = mysqli_fetch_assoc($vres);
if (!$session) {
    die('Not found or permission denied');
}

// Fetch answers & marks
$ans_q = mysqli_prepare($conn, "
    SELECT sa.*, q.question_text, q.marks AS max_marks
    FROM student_answers sa
    JOIN questions q ON sa.question_id = q.id
    WHERE sa.session_id = ?
");
mysqli_stmt_bind_param($ans_q, "i", $session_id);
mysqli_stmt_execute($ans_q);
$answers = mysqli_stmt_get_result($ans_q);

$obtained = 0; $total = 0;
$rows = [];
while ($a = mysqli_fetch_assoc($answers)) {
    $mark = floatval($a['evaluated_marks'] ?? 0);
    $max = floatval($a['max_marks']);
    $obtained += $mark;
    $total += $max;
    $rows[] = [
        'q' => $a['question_text'],
        'mark' => $mark,
        'max' => $max
    ];
}

$percentage = $total > 0 ? ($obtained / $total) * 100 : 0;
$pass = $obtained >= floatval($session['passing_marks']);

// Create PDF
$pdf = new FPDF();
$pdf->AddPage();
$pdf->SetFont('Arial','B',16);
$pdf->Cell(0,10, 'Exam Result', 0, 1, 'C');

$pdf->SetFont('Arial','',12);
$pdf->Cell(0,8, "Student: {$session['full_name']} ({$session['roll_number']})", 0, 1);
$pdf->Cell(0,8, "Exam: {$session['title']}", 0, 1);
$pdf->Cell(0,8, "Submitted: {$session['submitted_at']}", 0, 1);
$pdf->Ln(4);

$pdf->SetFont('Arial','B',12);
$pdf->Cell(12,8,'#',1,0,'C');
$pdf->Cell(100,8,'Question',1,0);
$pdf->Cell(30,8,'Obtained',1,0,'C');
$pdf->Cell(30,8,'Max',1,1,'C');

$pdf->SetFont('Arial','',11);
$idx = 1;
foreach ($rows as $r) {
    $pdf->Cell(12,8,$idx++,1,0,'C');
    $pdf->Cell(100,8, (strlen($r['q'])>60 ? substr($r['q'],0,57).'...' : $r['q']),1,0);
    $pdf->Cell(30,8,number_format($r['mark'],2),1,0,'C');
    $pdf->Cell(30,8,number_format($r['max'],2),1,1,'C');
}

$pdf->Ln(4);
$pdf->SetFont('Arial','B',12);
$pdf->Cell(0,8, "Total Obtained: " . number_format($obtained,2), 0, 1);
$pdf->Cell(0,8, "Total Marks: " . number_format($total,2), 0, 1);
$pdf->Cell(0,8, "Percentage: " . number_format($percentage,2) . "%", 0, 1);
$pdf->Cell(0,8, "Result: " . ($pass ? 'PASS' : 'FAIL'), 0, 1);

$pdf->Ln(8);
$pdf->Cell(0,6, "Teacher Signature: __________________________", 0, 1);

$pdf->Output('I', "result_{$session['roll_number']}_{$session_id}.pdf");
exit;

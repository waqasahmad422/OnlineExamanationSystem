<?php
// app/ajax_handler.php
require_once 'config.php';
require_once 'auth.php';
require_once 'helpers.php';

if (isset($_GET['action']) && $_GET['action'] === 'get_exams_by_mega_exam') {
    require_role(['teacher']);
    
    $teacher_id = intval($_SESSION['user_id']);
    $mega_exam_id = intval($_GET['mega_exam_id']);
    
    $query = mysqli_prepare($conn, "
        SELECT id, title, semester, is_approved 
        FROM exams 
        WHERE teacher_id = ? AND mega_exam_id = ?
        ORDER BY created_at DESC
    ");
    mysqli_stmt_bind_param($query, "ii", $teacher_id, $mega_exam_id);
    mysqli_stmt_execute($query);
    $result = mysqli_stmt_get_result($query);
    
    $exams = [];
    while($exam = mysqli_fetch_assoc($result)) {
        $exams[] = $exam;
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'exams' => $exams
    ]);
    exit;
}
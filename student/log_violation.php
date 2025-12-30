<?php
require_once '../app/config.php';
require_once '../app/helpers.php';
require_once '../app/auth.php';
require_once '../app/student_handlers.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $session_id = isset($_POST['session_id']) ? intval($_POST['session_id']) : 0;
    $type = isset($_POST['type']) ? sanitize_input($_POST['type']) : '';
    
    if ($session_id > 0) {
        $result = log_violation($session_id, $type);
        echo json_encode($result);
    }
}
?>

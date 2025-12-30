<?php
require_once '../app/config.php';
require_once '../app/helpers.php';
require_once '../app/auth.php';

require_role(['admin']);
check_session_validity();

$page_title = "Student Results - Admin";

// Function to get mega exams with their exams
function getMegaExams($conn) {
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
        WHERE ev.status = 'completed'
        GROUP BY me.id
        ORDER BY me.title ASC
    ");
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
$available_mega_exams = getMegaExams($conn);

// Add "All Mega Exams" option
$all_mega_exams_option = [
    'mega_exam_id' => 'all',
    'mega_exam_title' => 'All Mega Exams',
    'mega_exam_code' => '',
    'exam_count' => array_sum(array_column($available_mega_exams, 'exam_count')),
    'exam_ids' => array_merge(...array_column($available_mega_exams, 'exam_ids'))
];

$available_mega_exams['all'] = $all_mega_exams_option;

// Get selected mega exam from URL or default to first mega exam
$selected_mega_exam_id = null;
if (isset($_GET['mega_exam_id']) && !empty($_GET['mega_exam_id'])) {
    if ($_GET['mega_exam_id'] === 'all' || isset($available_mega_exams[$_GET['mega_exam_id']])) {
        $selected_mega_exam_id = $_GET['mega_exam_id'];
    }
} elseif (count($available_mega_exams) > 0) {
    $selected_mega_exam_id = 'all'; // Default to "All Mega Exams"
}

// Handle Word export
if (isset($_POST['export_word'])) {
    // Set timezone to Pakistan (Karachi)
    date_default_timezone_set('Asia/Karachi');
    
    // Get selected mega exam from POST
    $export_mega_exam_id = $_POST['mega_exam_id'] ?? $selected_mega_exam_id;
    
    // Get all departments
    $all_depts_query = mysqli_prepare($conn, "
        SELECT DISTINCT d.id, d.name 
        FROM departments d
        JOIN exams e ON e.department_id = d.id
        ORDER BY d.name ASC
    ");
    mysqli_stmt_execute($all_depts_query);
    $all_depts_result = mysqli_stmt_get_result($all_depts_query);
    $all_departments = mysqli_fetch_all($all_depts_result, MYSQLI_ASSOC);
    
    // Convert logo to base64 for Word document
    $logo_path = realpath(dirname(__FILE__) . '/../assets/images/logo.jpeg');
    $logo_src = '';
    
    if (file_exists($logo_path)) {
        $logo_data = base64_encode(file_get_contents($logo_path));
        $logo_src = "data:image/jpeg;base64,{$logo_data}";
    }
    
    $word_content = "<html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:w='urn:schemas-microsoft-com:office:word' xmlns='http://www.w3.org/TR/REC-html40'>
    <head>
        <meta charset='utf-8'>
        <title>Student Results Report</title>
        <style>
            @page { size: landscape; margin: 0.3in; }
            body { font-family: Arial, sans-serif; margin: 0; padding: 15px; }
            .header { text-align: center; margin-bottom: 10px; border-bottom: 2px solid #333; padding-bottom: 5px; background-color: #28a745; color: white; padding: 10px; }
            .logo-container { display: flex; align-items: center; justify-content: center; margin-bottom: 5px; }
            .logo { width: 50px; height: 50px; margin-right: 10px; object-fit: contain; }
            .college-name { font-size: 18px; font-weight: bold; margin: 0; }
            .mega-exam { font-size: 14px; font-weight: bold; margin: 3px 0; }
            .department-header { background-color: #ffffff; padding: 6px; margin: 8px 0; font-weight: bold; font-size: 12px; border: 1px solid #333; }
            .semester-header { background-color: #e6e6e6; padding: 4px; margin: 6px 0; font-weight: bold; font-size: 11px; }
            table { width: 100%; border-collapse: collapse; margin: 6px 0; table-layout: fixed; }
            th, td { border: 1px solid #000; padding: 2px; text-align: center; font-size: 8px; overflow: hidden; }
            th { background-color: #f0f0f0; font-weight: bold; }
            .text-center { text-align: center; }
            .text-left { text-align: left; }
            .student-name { font-size: 7px; }
        </style>
    </head>
    <body>
        <div class='header'>
            <div class='logo-container'>";
    
    if (!empty($logo_src)) {
        $word_content .= "<img src='{$logo_src}' class='logo' alt='College Logo'>";
    }
    
    $mega_exam_title = $export_mega_exam_id === 'all' ? 'All Mega Exams' : 
        (isset($available_mega_exams[$export_mega_exam_id]) ? $available_mega_exams[$export_mega_exam_id]['mega_exam_title'] : 'All Exams');
    
    $word_content .= "<div>
                    <div class='college-name'>GOVT Degree College Ekkaghund District Mohmand</div>
                    <div class='mega-exam'>" . htmlspecialchars($mega_exam_title) . "</div>
                    <div>Student Results Report</div>
                    <div>Generated on: " . date('F j, Y g:i A') . "</div>
                </div>
            </div>
        </div>";
    
    // Function to get students data for Word
    function getStudentsDataForWord($conn, $dept_id, $semester, $mega_exam_id = null, $available_mega_exams = []) {
        if ($mega_exam_id && $mega_exam_id !== 'all' && isset($available_mega_exams[$mega_exam_id])) {
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
                AND e.department_id = ?
                AND e.semester = ?
                AND e.mega_exam_id = ?
                AND e.id IN ($placeholders)
                AND ev.status = 'completed'
                ORDER BY u.roll_number ASC, e.title ASC
            ";
            
            $params = array_merge([$dept_id, $semester, $mega_exam_id], $exam_ids);
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
                AND e.department_id = ?
                AND e.semester = ?
                AND ev.status = 'completed'
                ORDER BY u.roll_number ASC, e.title ASC
            ");
            mysqli_stmt_bind_param($students_query, "ii", $dept_id, $semester);
        }
        
        mysqli_stmt_execute($students_query);
        $students_result = mysqli_stmt_get_result($students_query);

        $students_data = [];
        $exam_list = [];

        while ($row = mysqli_fetch_assoc($students_result)) {
            $student_id = $row['student_id'];
            
            if (!isset($students_data[$student_id])) {
                $students_data[$student_id] = [
                    'roll_number' => $row['roll_number'],
                    'student_name' => $row['student_name'],
                    'department_name' => $row['department_name'],
                    'semester' => $row['semester'],
                    'papers' => [],
                    'total_obtained' => 0,
                    'total_marks' => 0
                ];
            }
            
            $students_data[$student_id]['papers'][$row['exam_id']] = [
                'exam_title' => $row['exam_title'],
                'marks_obtained' => $row['marks_obtained'],
                'total_marks' => $row['total_marks'],
                'percentage' => $row['percentage'],
                'is_approved' => $row['is_approved'],
                'evaluated_at' => $row['evaluated_at']
            ];
            
            if (!in_array($row['exam_title'], $exam_list)) {
                $exam_list[] = $row['exam_title'];
            }
            
            $students_data[$student_id]['total_obtained'] += $row['marks_obtained'];
            $students_data[$student_id]['total_marks'] += $row['total_marks'];
        }

        foreach ($students_data as $student_id => &$student) {
            $student['overall_percentage'] = $student['total_marks'] > 0 ? 
                ($student['total_obtained'] / $student['total_marks'] * 100) : 0;
        }

        uasort($students_data, function($a, $b) {
            return $b['overall_percentage'] <=> $a['overall_percentage'];
        });

        $position = 1;
        foreach ($students_data as $student_id => &$student) {
            $student['position'] = $position++;
        }

        return [
            'students_data' => $students_data,
            'exam_list' => $exam_list
        ];
    }
    
    // Loop through each department
    foreach ($all_departments as $dept) {
        $dept_id = $dept['id'];
        $dept_name = $dept['name'];
        
        // Get semesters for this department
        if ($export_mega_exam_id && $export_mega_exam_id !== 'all' && isset($available_mega_exams[$export_mega_exam_id])) {
            $exam_ids = $available_mega_exams[$export_mega_exam_id]['exam_ids'];
            $placeholders = str_repeat('?,', count($exam_ids) - 1) . '?';
            
            $semesters_query = mysqli_prepare($conn, "
                SELECT DISTINCT e.semester 
                FROM exams e
                WHERE e.mega_exam_id = ?
                AND e.department_id = ?
                AND e.id IN ($placeholders)
                ORDER BY e.semester ASC
            ");
            
            $params = array_merge([$export_mega_exam_id, $dept_id], $exam_ids);
            $types = str_repeat('i', count($params));
            mysqli_stmt_bind_param($semesters_query, $types, ...$params);
        } else {
            $semesters_query = mysqli_prepare($conn, "
                SELECT DISTINCT e.semester 
                FROM exams e
                WHERE e.department_id = ?
                ORDER BY e.semester ASC
            ");
            mysqli_stmt_bind_param($semesters_query, "i", $dept_id);
        }
        mysqli_stmt_execute($semesters_query);
        $semesters_result = mysqli_stmt_get_result($semesters_query);
        $semesters_data = mysqli_fetch_all($semesters_result, MYSQLI_ASSOC);
        
        if (count($semesters_data) > 0) {
            $word_content .= "<div class='department-header'>DEPARTMENT: $dept_name</div>";
            
            foreach ($semesters_data as $semester_row) {
                $semester = $semester_row['semester'];
                $semester_data = getStudentsDataForWord($conn, $dept_id, $semester, $export_mega_exam_id, $available_mega_exams);
                $students_data = $semester_data['students_data'];
                $exam_list = $semester_data['exam_list'];
                
                if (count($students_data) > 0) {
                    $word_content .= "<div class='semester-header'>Semester $semester</div>";
                    $word_content .= "<table>";
                    $word_content .= "<thead><tr>";
                    $word_content .= "<th style='width:7%'>Roll No</th>";
                    $word_content .= "<th style='width:10%'>Name</th>";
                    foreach ($exam_list as $exam) {
                        $short_exam = strlen($exam) > 8 ? substr($exam, 0, 7) . '.' : $exam;
                        $word_content .= "<th style='width:7%'>$short_exam</th>";
                    }
                    $word_content .= "<th style='width:7%'>Total</th>";
                    $word_content .= "<th style='width:7%'>%</th>";
                    $word_content .= "<th style='width:5%'>Pos</th>";
                    $word_content .= "<th style='width:7%'>Status</th>";
                    $word_content .= "</tr></thead>";
                    $word_content .= "<tbody>";
                    
                    foreach ($students_data as $student) {
                        $overall_percentage = $student['overall_percentage'];
                        $pass_status = $overall_percentage >= 35 ? 'PASS' : 'FAIL';
                        
                        $word_content .= "<tr>";
                        $word_content .= "<td>{$student['roll_number']}</td>";
                        
                        // Shorten student name if too long
                        $student_name = strlen($student['student_name']) > 15 ? 
                            substr($student['student_name'], 0, 13) . '..' : $student['student_name'];
                        $word_content .= "<td class='text-left student-name'>$student_name</td>";
                        
                        foreach ($exam_list as $exam_title) {
                            $paper_found = false;
                            $paper_marks = null;
                            
                            foreach ($student['papers'] as $paper) {
                                if ($paper['exam_title'] === $exam_title) {
                                    $paper_marks = $paper;
                                    $paper_found = true;
                                    break;
                                }
                            }
                            
                            if ($paper_found) {
                                $marks_obtained = intval($paper_marks['marks_obtained']);
                                $total_marks = intval($paper_marks['total_marks']);
                                $word_content .= "<td>$marks_obtained/$total_marks</td>";
                            } else {
                                $word_content .= "<td>-</td>";
                            }
                        }
                        
                        $total_obtained = intval($student['total_obtained']);
                        $total_marks = intval($student['total_marks']);
                        $total_percentage = number_format($student['overall_percentage'], 2);
                        $word_content .= "<td><strong>$total_obtained/$total_marks</strong></td>";
                        $word_content .= "<td><strong>$total_percentage%</strong></td>";
                        
                        $pos_text = $student['position'] . 
                            ($student['position'] == 1 ? 'st' : 
                             ($student['position'] == 2 ? 'nd' : 
                              ($student['position'] == 3 ? 'rd' : 'th')));
                        $word_content .= "<td>$pos_text</td>";
                        $word_content .= "<td>$pass_status</td>";
                        $word_content .= "</tr>";
                    }
                    $word_content .= "</tbody></table><br>";
                }
            }
        }
    }
    
    $word_content .= "</body></html>";
    
    header("Content-type: application/vnd.ms-word");
    header("Content-Disposition: attachment;Filename=student_results_" . ($export_mega_exam_id === 'all' ? 'all_mega_exams' : 'mega_exam_' . $export_mega_exam_id) . "_" . date('Y-m-d_H-i-s') . ".doc");
    echo $word_content;
    exit();
}

// Handle Excel export
if (isset($_POST['export_excel'])) {
    // Set timezone to Pakistan (Karachi)
    date_default_timezone_set('Asia/Karachi');
    
    // Get selected mega exam from POST
    $export_mega_exam_id = $_POST['mega_exam_id'] ?? $selected_mega_exam_id;
    
    // Get all departments
    $all_depts_query = mysqli_prepare($conn, "
        SELECT DISTINCT d.id, d.name 
        FROM departments d
        JOIN exams e ON e.department_id = d.id
        ORDER BY d.name ASC
    ");
    mysqli_stmt_execute($all_depts_query);
    $all_depts_result = mysqli_stmt_get_result($all_depts_query);
    $all_departments = mysqli_fetch_all($all_depts_result, MYSQLI_ASSOC);
    
    $mega_exam_title = $export_mega_exam_id === 'all' ? 'All Mega Exams' : 
        (isset($available_mega_exams[$export_mega_exam_id]) ? $available_mega_exams[$export_mega_exam_id]['mega_exam_title'] : 'All Exams');
    
    $excel_content = "Student Results Report\r\n";
    $excel_content .= "GOVT Degree College Ekkaghund District Mohmand\r\n";
    $excel_content .= $mega_exam_title . "\r\n";
    $excel_content .= "Generated on: " . date('F j, Y g:i A') . "\r\n\r\n";
    
    // Function to get students data for Excel
    function getStudentsDataForExcel($conn, $dept_id, $semester, $mega_exam_id = null, $available_mega_exams = []) {
        if ($mega_exam_id && $mega_exam_id !== 'all' && isset($available_mega_exams[$mega_exam_id])) {
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
                AND e.department_id = ?
                AND e.semester = ?
                AND e.mega_exam_id = ?
                AND e.id IN ($placeholders)
                AND ev.status = 'completed'
                ORDER BY u.roll_number ASC, e.title ASC
            ";
            
            $params = array_merge([$dept_id, $semester, $mega_exam_id], $exam_ids);
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
                AND e.department_id = ?
                AND e.semester = ?
                AND ev.status = 'completed'
                ORDER BY u.roll_number ASC, e.title ASC
            ");
            mysqli_stmt_bind_param($students_query, "ii", $dept_id, $semester);
        }
        
        mysqli_stmt_execute($students_query);
        $students_result = mysqli_stmt_get_result($students_query);

        $students_data = [];
        $exam_list = [];

        while ($row = mysqli_fetch_assoc($students_result)) {
            $student_id = $row['student_id'];
            
            if (!isset($students_data[$student_id])) {
                $students_data[$student_id] = [
                    'roll_number' => $row['roll_number'],
                    'student_name' => $row['student_name'],
                    'department_name' => $row['department_name'],
                    'semester' => $row['semester'],
                    'papers' => [],
                    'total_obtained' => 0,
                    'total_marks' => 0
                ];
            }
            
            $students_data[$student_id]['papers'][$row['exam_id']] = [
                'exam_title' => $row['exam_title'],
                'marks_obtained' => $row['marks_obtained'],
                'total_marks' => $row['total_marks'],
                'percentage' => $row['percentage'],
                'is_approved' => $row['is_approved'],
                'evaluated_at' => $row['evaluated_at']
            ];
            
            if (!in_array($row['exam_title'], $exam_list)) {
                $exam_list[] = $row['exam_title'];
            }
            
            $students_data[$student_id]['total_obtained'] += $row['marks_obtained'];
            $students_data[$student_id]['total_marks'] += $row['total_marks'];
        }

        foreach ($students_data as $student_id => &$student) {
            $student['overall_percentage'] = $student['total_marks'] > 0 ? 
                ($student['total_obtained'] / $student['total_marks'] * 100) : 0;
        }

        uasort($students_data, function($a, $b) {
            return $b['overall_percentage'] <=> $a['overall_percentage'];
        });

        $position = 1;
        foreach ($students_data as $student_id => &$student) {
            $student['position'] = $position++;
        }

        return [
            'students_data' => $students_data,
            'exam_list' => $exam_list
        ];
    }
    
    // Loop through each department
    foreach ($all_departments as $dept) {
        $dept_id = $dept['id'];
        $dept_name = $dept['name'];
        
        // Get semesters for this department
        if ($export_mega_exam_id && $export_mega_exam_id !== 'all' && isset($available_mega_exams[$export_mega_exam_id])) {
            $exam_ids = $available_mega_exams[$export_mega_exam_id]['exam_ids'];
            $placeholders = str_repeat('?,', count($exam_ids) - 1) . '?';
            
            $semesters_query = mysqli_prepare($conn, "
                SELECT DISTINCT e.semester 
                FROM exams e
                WHERE e.mega_exam_id = ?
                AND e.department_id = ?
                AND e.id IN ($placeholders)
                ORDER BY e.semester ASC
            ");
            
            $params = array_merge([$export_mega_exam_id, $dept_id], $exam_ids);
            $types = str_repeat('i', count($params));
            mysqli_stmt_bind_param($semesters_query, $types, ...$params);
        } else {
            $semesters_query = mysqli_prepare($conn, "
                SELECT DISTINCT e.semester 
                FROM exams e
                WHERE e.department_id = ?
                ORDER BY e.semester ASC
            ");
            mysqli_stmt_bind_param($semesters_query, "i", $dept_id);
        }
        mysqli_stmt_execute($semesters_query);
        $semesters_result = mysqli_stmt_get_result($semesters_query);
        $semesters_data = mysqli_fetch_all($semesters_result, MYSQLI_ASSOC);
        
        if (count($semesters_data) > 0) {
            $excel_content .= "DEPARTMENT: $dept_name\r\n";
            
            foreach ($semesters_data as $semester_row) {
                $semester = $semester_row['semester'];
                $semester_data = getStudentsDataForExcel($conn, $dept_id, $semester, $export_mega_exam_id, $available_mega_exams);
                $students_data = $semester_data['students_data'];
                $exam_list = $semester_data['exam_list'];
                
                if (count($students_data) > 0) {
                    $excel_content .= "Semester $semester\r\n";
                    
                    // Header row
                    $excel_content .= "Roll No\tName\t";
                    foreach ($exam_list as $exam) {
                        $short_exam = strlen($exam) > 10 ? substr($exam, 0, 9) . '.' : $exam;
                        $excel_content .= "$short_exam\t";
                    }
                    $excel_content .= "Total\tPercentage\tPosition\tStatus\r\n";
                    
                    // Data rows
                    foreach ($students_data as $student) {
                        $overall_percentage = $student['overall_percentage'];
                        $pass_status = $overall_percentage >= 35 ? 'PASS' : 'FAIL';
                        
                        $excel_content .= "{$student['roll_number']}\t{$student['student_name']}\t";
                        
                        foreach ($exam_list as $exam_title) {
                            $paper_found = false;
                            $paper_marks = null;
                            
                            foreach ($student['papers'] as $paper) {
                                if ($paper['exam_title'] === $exam_title) {
                                    $paper_marks = $paper;
                                    $paper_found = true;
                                    break;
                                }
                            }
                            
                            if ($paper_found) {
                                $marks_obtained = intval($paper_marks['marks_obtained']);
                                $total_marks = intval($paper_marks['total_marks']);
                                $excel_content .= "$marks_obtained/$total_marks\t";
                            } else {
                                $excel_content .= "-\t";
                            }
                        }
                        
                        $total_obtained = intval($student['total_obtained']);
                        $total_marks = intval($student['total_marks']);
                        $total_percentage = number_format($student['overall_percentage'], 2);
                        $pos_text = $student['position'] . 
                            ($student['position'] == 1 ? 'st' : 
                             ($student['position'] == 2 ? 'nd' : 
                              ($student['position'] == 3 ? 'rd' : 'th')));
                        
                        $excel_content .= "$total_obtained/$total_marks\t";
                        $excel_content .= "$total_percentage%\t";
                        $excel_content .= "$pos_text\t";
                        $excel_content .= "$pass_status\r\n";
                    }
                    $excel_content .= "\r\n";
                }
            }
            $excel_content .= "\r\n";
        }
    }
    
    header("Content-type: application/vnd.ms-excel");
    header("Content-Disposition: attachment;Filename=student_results_" . ($export_mega_exam_id === 'all' ? 'all_mega_exams' : 'mega_exam_' . $export_mega_exam_id) . "_" . date('Y-m-d_H-i-s') . ".xls");
    echo $excel_content;
    exit();
}

// Handle result publishing
if (isset($_POST['publish_results']) && isset($_POST['exam_id'])) {
    $exam_id_to_publish = intval($_POST['exam_id']);
    
    $publish_query = mysqli_prepare($conn, "
        UPDATE exams 
        SET is_approved = 1 
        WHERE id = ?
    ");
    mysqli_stmt_bind_param($publish_query, "i", $exam_id_to_publish);
    $publish_success = mysqli_stmt_execute($publish_query);
    
    if ($publish_success) {
        $_SESSION['message'] = "Results published successfully!";
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = "Error publishing results.";
        $_SESSION['message_type'] = "error";
    }
    
    header("Location: admin_results.php?exam_id=" . $exam_id_to_publish);
    exit();
}

// Handle result unpublishing
if (isset($_POST['unpublish_results']) && isset($_POST['exam_id'])) {
    $exam_id_to_unpublish = intval($_POST['exam_id']);
    
    $unpublish_query = mysqli_prepare($conn, "
        UPDATE exams 
        SET is_approved = 0 
        WHERE id = ?
    ");
    mysqli_stmt_bind_param($unpublish_query, "i", $exam_id_to_unpublish);
    $unpublish_success = mysqli_stmt_execute($unpublish_query);
    
    if ($unpublish_success) {
        $_SESSION['message'] = "Results unpublished successfully!";
        $_SESSION['message_type'] = "warning";
    } else {
        $_SESSION['message'] = "Error unpublishing results.";
        $_SESSION['message_type'] = "error";
    }
    
    header("Location: admin_results.php?exam_id=" . $exam_id_to_unpublish);
    exit();
}

// Fetch all departments for the selected mega exam
if ($selected_mega_exam_id && $selected_mega_exam_id !== 'all' && isset($available_mega_exams[$selected_mega_exam_id])) {
    $exam_ids = $available_mega_exams[$selected_mega_exam_id]['exam_ids'];
    $placeholders = str_repeat('?,', count($exam_ids) - 1) . '?';
    
    $dept_query = mysqli_prepare($conn, "
        SELECT DISTINCT d.id, d.name 
        FROM departments d
        JOIN exams e ON e.department_id = d.id
        WHERE e.mega_exam_id = ?
        AND e.id IN ($placeholders)
        ORDER BY d.name ASC
    ");
    
    $params = array_merge([$selected_mega_exam_id], $exam_ids);
    $types = str_repeat('i', count($params));
    mysqli_stmt_bind_param($dept_query, $types, ...$params);
} else {
    $dept_query = mysqli_prepare($conn, "
        SELECT DISTINCT d.id, d.name 
        FROM departments d
        JOIN exams e ON e.department_id = d.id
        ORDER BY d.name ASC
    ");
    mysqli_stmt_execute($dept_query);
}
mysqli_stmt_execute($dept_query);
$departments_result = mysqli_stmt_get_result($dept_query);
$departments_data = mysqli_fetch_all($departments_result, MYSQLI_ASSOC);

// Get selected department from URL or default to first department
$selected_dept_id = null;
if (isset($_GET['dept_id']) && !empty($_GET['dept_id'])) {
    $selected_dept_id = intval($_GET['dept_id']);
} elseif (count($departments_data) > 0) {
    $selected_dept_id = $departments_data[0]['id'];
}

// Fetch semesters for selected department and mega exam
if ($selected_mega_exam_id && $selected_mega_exam_id !== 'all' && isset($available_mega_exams[$selected_mega_exam_id])) {
    $exam_ids = $available_mega_exams[$selected_mega_exam_id]['exam_ids'];
    $placeholders = str_repeat('?,', count($exam_ids) - 1) . '?';
    
    $semesters_query = mysqli_prepare($conn, "
        SELECT DISTINCT e.semester 
        FROM exams e
        WHERE e.mega_exam_id = ?
        AND e.department_id = ?
        AND e.id IN ($placeholders)
        ORDER BY e.semester ASC
    ");
    
    $params = array_merge([$selected_mega_exam_id, $selected_dept_id], $exam_ids);
    $types = str_repeat('i', count($params));
    mysqli_stmt_bind_param($semesters_query, $types, ...$params);
} else {
    $semesters_query = mysqli_prepare($conn, "
        SELECT DISTINCT e.semester 
        FROM exams e
        WHERE e.department_id = ?
        ORDER BY e.semester ASC
    ");
    mysqli_stmt_bind_param($semesters_query, "i", $selected_dept_id);
}
mysqli_stmt_execute($semesters_query);
$semesters_result = mysqli_stmt_get_result($semesters_query);
$semesters_data = mysqli_fetch_all($semesters_result, MYSQLI_ASSOC);

// Function to get students data for a specific semester
function getStudentsData($conn, $dept_id, $semester, $mega_exam_id = null, $available_mega_exams = []) {
    if ($mega_exam_id && $mega_exam_id !== 'all' && isset($available_mega_exams[$mega_exam_id])) {
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
            AND e.department_id = ?
            AND e.semester = ?
            AND e.mega_exam_id = ?
            AND e.id IN ($placeholders)
            AND ev.status = 'completed'
            ORDER BY u.roll_number ASC, e.title ASC
        ";
        
        $params = array_merge([$dept_id, $semester, $mega_exam_id], $exam_ids);
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
            AND e.department_id = ?
            AND e.semester = ?
            AND ev.status = 'completed'
            ORDER BY u.roll_number ASC, e.title ASC
        ");
        mysqli_stmt_bind_param($students_query, "ii", $dept_id, $semester);
    }
    
    mysqli_stmt_execute($students_query);
    $students_result = mysqli_stmt_get_result($students_query);

    $students_data = [];
    $exam_list = [];

    while ($row = mysqli_fetch_assoc($students_result)) {
        $student_id = $row['student_id'];
        
        if (!isset($students_data[$student_id])) {
            $students_data[$student_id] = [
                'roll_number' => $row['roll_number'],
                'student_name' => $row['student_name'],
                'department_name' => $row['department_name'],
                'semester' => $row['semester'],
                'papers' => [],
                'total_obtained' => 0,
                'total_marks' => 0
            ];
        }
        
        $students_data[$student_id]['papers'][$row['exam_id']] = [
            'exam_title' => $row['exam_title'],
            'marks_obtained' => $row['marks_obtained'],
            'total_marks' => $row['total_marks'],
            'percentage' => $row['percentage'],
            'is_approved' => $row['is_approved'],
            'evaluated_at' => $row['evaluated_at']
        ];
        
        if (!in_array($row['exam_title'], $exam_list)) {
            $exam_list[] = $row['exam_title'];
        }
        
        $students_data[$student_id]['total_obtained'] += $row['marks_obtained'];
        $students_data[$student_id]['total_marks'] += $row['total_marks'];
    }

    foreach ($students_data as $student_id => &$student) {
        $student['overall_percentage'] = $student['total_marks'] > 0 ? 
            ($student['total_obtained'] / $student['total_marks'] * 100) : 0;
    }

    uasort($students_data, function($a, $b) {
        return $b['overall_percentage'] <=> $a['overall_percentage'];
    });

    $position = 1;
    foreach ($students_data as $student_id => &$student) {
        $student['position'] = $position++;
    }

    return [
        'students_data' => $students_data,
        'exam_list' => $exam_list
    ];
}

// Get data for all semesters when department is selected
$all_semesters_data = [];
if ($selected_dept_id) {
    foreach ($semesters_data as $semester_row) {
        $semester = $semester_row['semester'];
        $all_semesters_data[$semester] = getStudentsData($conn, $selected_dept_id, $semester, $selected_mega_exam_id, $available_mega_exams);
    }
}

include '../templates/header.php';
include '../templates/sidebar_admin.php';
?>

<div class="main-content">
    <div class="top-navbar mb-3">
        <div class="d-flex justify-content-between align-items-center" style="width: 100%;">
            <h4><i class="fas fa-chart-line"></i> Student Results</h4>
            <div class="d-flex gap-2">
                <!-- Word Export Button -->
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="mega_exam_id" value="<?= $selected_mega_exam_id ?>">
                    <button type="submit" name="export_word" class="btn btn-primary">
                        <i class="fas fa-file-word"></i> Word
                    </button>
                </form>
                <!-- Excel Export Button -->
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="mega_exam_id" value="<?= $selected_mega_exam_id ?>">
                    <button type="submit" name="export_excel" class="btn btn-success">
                        <i class="fas fa-file-excel"></i> Excel
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="content-area">
        <div id="examTable" class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <span>Student Results Overview</span>
                    <?php if ($selected_dept_id): ?>
                        <div class="d-flex align-items-center">
                            <small class="text-muted me-3">
                                <strong class="text-white">
                                    Department: <?= htmlspecialchars($departments_data[array_search($selected_dept_id, array_column($departments_data, 'id'))]['name']) ?>
                                    <?= $selected_mega_exam_id && $selected_mega_exam_id !== 'all' ? ' | ' . htmlspecialchars($available_mega_exams[$selected_mega_exam_id]['mega_exam_title']) : ' | All Mega Exams' ?>
                                </strong>
                            </small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card-body">
                <!-- Mega Exam Tabs -->
                <?php if (count($available_mega_exams) > 0): ?>
                <div class="mb-4">
                    <h6 class="text-muted mb-2">Select Mega Exam:</h6>
                    <ul class="nav nav-pills" id="megaExamTabs" role="tablist">
                        <?php foreach ($available_mega_exams as $mega_exam_id => $mega_exam): 
                            $is_current = ($mega_exam_id === $selected_mega_exam_id);
                            $badge_class = $mega_exam_id === 'all' ? 'bg-success' : 'bg-primary';
                        ?>
                        <li class="nav-item" role="presentation">
                            <a class="nav-link <?= $is_current ? 'active' : '' ?>" 
                               href="?mega_exam_id=<?= $mega_exam_id ?><?= $selected_dept_id ? '&dept_id=' . $selected_dept_id : '' ?>"
                               role="tab"
                               title="<?= htmlspecialchars($mega_exam['mega_exam_title']) ?> - <?= $mega_exam['exam_count'] ?> exam(s)">
                                <i class="fas fa-layer-group me-1"></i>
                                <?= htmlspecialchars($mega_exam['mega_exam_title']) ?>
                                <?php if (!empty($mega_exam['mega_exam_code']) && $mega_exam_id !== 'all'): ?>
                                    <small class="ms-1">(<?= htmlspecialchars($mega_exam['mega_exam_code']) ?>)</small>
                                <?php endif; ?>
                                <span class="badge <?= $badge_class ?> ms-1"><?= $mega_exam['exam_count'] ?></span>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <!-- Department Tabs -->
                <?php if (count($departments_data) > 0): ?>
                <div class="mb-4">
                    <h6 class="text-muted mb-2">Select Department:</h6>
                    <ul class="nav nav-pills" id="deptTabs" role="tablist">
                        <?php foreach ($departments_data as $dept): ?>
                        <li class="nav-item" role="presentation">
                            <a class="nav-link <?= ($dept['id'] == $selected_dept_id) ? 'active' : '' ?>" 
                               href="?<?= $selected_mega_exam_id ? 'mega_exam_id=' . $selected_mega_exam_id . '&' : '' ?>dept_id=<?= $dept['id'] ?>" 
                               role="tab">
                                <?= htmlspecialchars($dept['name']) ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <!-- Results Tables -->
                <div class="table-responsive">
                    <?php if ($selected_dept_id && count($all_semesters_data) > 0): ?>
                        
                        <?php foreach ($all_semesters_data as $semester => $semester_data): 
                            $students_data = $semester_data['students_data'];
                            $exam_list = $semester_data['exam_list'];
                            
                            if (count($students_data) > 0):
                        ?>
                        
                        <div class="semester-table mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-3 p-3 bg-light rounded">
                                <h5 class="mb-0 text-primary">
                                    <i class="fas fa-graduation-cap"></i> Semester <?= $semester ?>
                                </h5>
                                <div>
                                    <span class="badge bg-info me-2">
                                        <?= count($students_data) ?> Student<?= count($students_data) > 1 ? 's' : '' ?>
                                    </span>
                                    <span class="badge bg-secondary">
                                        <?= count($exam_list) ?> Paper<?= count($exam_list) > 1 ? 's' : '' ?>
                                    </span>
                                </div>
                            </div>

                            <table class="table table-striped table-hover table-bordered">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Roll No</th>
                                        <th>Student Name</th>
                                        <?php foreach ($exam_list as $exam_title): ?>
                                        <th class="text-center"><?= htmlspecialchars($exam_title) ?></th>
                                        <?php endforeach; ?>
                                        <th class="text-center">Total</th>
                                        <th class="text-center">Percentage</th>
                                        <th class="text-center">Position</th>
                                        <th class="text-center">Status</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach ($students_data as $student): 
                                        $overall_percentage = $student['overall_percentage'];
                                        $pass_status = $overall_percentage >= 35 ? 'PASS' : 'FAIL';
                                    ?>
                                    <tr>
                                        <td class="fw-bold"><?= htmlspecialchars($student['roll_number']) ?></td>
                                        <td><?= htmlspecialchars($student['student_name']) ?></td>
                                        
                                        <?php foreach ($exam_list as $exam_title): 
                                            $paper_found = false;
                                            $paper_marks = null;
                                            
                                            foreach ($student['papers'] as $paper) {
                                                if ($paper['exam_title'] === $exam_title) {
                                                    $paper_marks = $paper;
                                                    $paper_found = true;
                                                    break;
                                                }
                                            }
                                        ?>
                                            <?php if ($paper_found): ?>
                                                <td class="text-center">
                                                    <span class="<?= $paper_marks['percentage'] >= 35 ? 'text-success' : 'text-danger' ?>">
                                                        <strong><?= number_format($paper_marks['marks_obtained'], 0) ?></strong>
                                                        <small class="text-muted">/<?= number_format($paper_marks['total_marks'], 0) ?></small>
                                                    </span>
                                                </td>
                                            <?php else: ?>
                                                <td class="text-center text-muted">-</td>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        
                                        <td class="text-center fw-bold bg-light">
                                            <?= number_format($student['total_obtained'], 0) ?>/<?= number_format($student['total_marks'], 0) ?>
                                        </td>
                                        <td class="text-center fw-bold">
                                            <?= number_format($student['overall_percentage'], 2) ?>%
                                        </td>
                                        <td class="text-center fw-bold">
                                            <?php if ($student['position'] == 1): ?>
                                                <span class="badge bg-warning text-dark">1st</span>
                                            <?php elseif ($student['position'] == 2): ?>
                                                <span class="badge bg-secondary">2nd</span>
                                            <?php elseif ($student['position'] == 3): ?>
                                                <span class="badge bg-danger">3rd</span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-dark"><?= $student['position'] ?>th</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge <?= $pass_status == 'PASS' ? 'bg-success' : 'bg-danger' ?>">
                                                <?= $pass_status ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-inbox fa-2x text-muted mb-3"></i>
                            <p class="text-muted">No results for Semester <?= $semester ?></p>
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
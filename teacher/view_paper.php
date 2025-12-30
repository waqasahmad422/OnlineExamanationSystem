<?php
// teacher/view_paper.php
require_once '../app/config.php';
require_once '../app/helpers.php';
require_once '../app/auth.php';

require_role(['teacher']);

$page_title = 'View Paper';
$exam_id = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : 0;

// Fetch exam details with mega exam info
$exam_res = mysqli_prepare($conn, "
    SELECT e.*, d.name as dept_name, b.name as batch_name, b.year,
           me.title as mega_exam_title, me.mega_exam_code
    FROM exams e 
    LEFT JOIN departments d ON e.department_id = d.id 
    LEFT JOIN batches b ON e.batch_id = b.id 
    LEFT JOIN mega_exams me ON e.mega_exam_id = me.id
    WHERE e.id = ? AND e.teacher_id = ?
");
mysqli_stmt_bind_param($exam_res, "ii", $exam_id, $_SESSION['user_id']);
mysqli_stmt_execute($exam_res);
$exam_result = mysqli_stmt_get_result($exam_res);
$exam_data = mysqli_fetch_assoc($exam_result);
mysqli_stmt_close($exam_res);

if (!$exam_data) {
    set_message('danger', 'Paper not found or access denied');
    redirect('./dashboard.php');
}

// Fetch sections and questions
$sections_res = mysqli_prepare($conn, "
    SELECT es.*, 
           COUNT(q.id) as question_count,
           SUM(q.marks) as total_section_marks
    FROM exam_sections es 
    LEFT JOIN questions q ON es.id = q.section_id 
    WHERE es.exam_id = ? 
    GROUP BY es.id 
    ORDER BY es.section_order
");
mysqli_stmt_bind_param($sections_res, "i", $exam_id);
mysqli_stmt_execute($sections_res);
$sections = mysqli_stmt_get_result($sections_res);
mysqli_stmt_close($sections_res);

include '../templates/header.php';
include '../templates/sidebar_teacher.php';
?>

<div class="main-content">
    <div class="top-navbar d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="fas fa-file-alt"></i> Paper Preview</h4>
        <div>
            <a href="manage_sections.php?exam_id=<?php echo $exam_id; ?>" class="btn btn-primary me-2">
                <i class="fas fa-edit"></i> Manage Sections
            </a>
            <button class="btn btn-success" onclick="printPaper()">
                <i class="fas fa-print"></i> Print Paper
            </button>
        </div>
    </div>

    <div class="content-area">
        <!-- Paper Container for Print -->
        <div class="paper-container" id="paper-container">
            <!-- Paper Header -->
            <div class="paper-header text-center mb-4">
                <h1 class="college-name">GOVT Degree College Ekkaghund</h1>
                
                <!-- Mega Exam Information -->
                <?php if (!empty($exam_data['mega_exam_title'])): ?>
                <div class="mega-exam-info mt-2 mb-3">
                    <h3 class="mega-exam-title">
                        <i class="fas fa-layer-group"></i>
                        <?php echo htmlspecialchars($exam_data['mega_exam_title']); ?>
                        <?php if (!empty($exam_data['mega_exam_code'])): ?>
                            <small class="mega-exam-code">(<?php echo htmlspecialchars($exam_data['mega_exam_code']); ?>)</small>
                        <?php endif; ?>
                    </h3>
                </div>
                <?php endif; ?>
                
                <div class="paper-details mt-4">
                    <div class="row justify-content-center">
                        <div class="col-md-10">
                            <table class="paper-info-table">
                                <tr>
                                    <td width="33%" class="text-start"><strong>Dept:</strong> <?php echo htmlspecialchars($exam_data['dept_name'] ?? 'N/A'); ?></td>
                                    <td width="34%" class="text-center"><strong>Paper:</strong> <?php echo htmlspecialchars($exam_data['title']); ?></td>
                                    <td width="33%" class="text-end"><strong>Semester:</strong> <?php echo intval($exam_data['semester']); ?>th</td>
                                </tr>
                                <tr>
                                    <td class="text-start"><?php echo format_datetime($exam_data['start_datetime']); ?></td>
                                    <td class="text-center"><?php echo intval($exam_data['duration_minutes']); ?> minutes</td>
                                    <td class="text-end"><strong>Total Marks:</strong> <?php echo intval($exam_data['total_marks']); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($exam_data['description'])): ?>
                <div class="paper-description mt-3">
                    <p class="mb-0"><?php echo nl2br(htmlspecialchars($exam_data['description'])); ?></p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Sections -->
            <?php 
            $section_counter = 0;
            $section_letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
            while ($section = mysqli_fetch_assoc($sections)): 
                $section_counter++;
                $section_letter = $section_letters[$section_counter - 1] ?? $section_counter;
                
                // Fetch questions for this section
                $qstmt = mysqli_prepare($conn, "
                    SELECT q.*, 
                           GROUP_CONCAT(CONCAT(mo.option_order, '. ', mo.option_text) ORDER BY mo.option_order SEPARATOR '||') as mcq_options
                    FROM questions q 
                    LEFT JOIN mcq_options mo ON q.id = mo.question_id 
                    WHERE q.section_id = ? 
                    GROUP BY q.id 
                    ORDER BY q.question_order
                ");
                mysqli_stmt_bind_param($qstmt, "i", $section['id']);
                mysqli_stmt_execute($qstmt);
                $questions = mysqli_stmt_get_result($qstmt);
                mysqli_stmt_close($qstmt);
            ?>
            <div class="section-container mb-5">
                <div class="section-header text-center mb-3">
                    <h2 class="section-title">SECTION <?php echo $section_letter; ?></h2>
                </div>
                
                <?php if (!empty($section['instructions'])): ?>
                <div class="section-instructions mb-4 text-center">
                    <p class="mb-0"><strong>Instructions:</strong> <?php echo nl2br(htmlspecialchars($section['instructions'])); ?></p>
                </div>
                <?php endif; ?>

                <div class="questions-container">
                    <?php 
                    $question_counter = 0;
                    while ($question = mysqli_fetch_assoc($questions)): 
                        $question_counter++;
                    ?>
                    <div class="question-item mb-4">
                        <!-- Question Header -->
                        <div class="question-header mb-2">
                            <h4 class="question-number">
                                <strong>Q<?php echo $question_counter; ?>. Encircle the most appropriate choice:</strong>
                                <span class="marks">(<?php echo floatval($question['marks']); ?> marks)</span>
                            </h4>
                        </div>
                        
                        <!-- Question Text -->
                        <div class="question-text mb-3">
                            <div class="sub-question">
                                <strong><?php echo $question_counter; ?>.</strong> 
                                <?php echo nl2br(htmlspecialchars($question['question_text'])); ?>
                            </div>
                        </div>

                        <?php if ($section['section_type'] === 'mcq' && !empty($question['mcq_options'])): 
                            $options = explode('||', $question['mcq_options']);
                        ?>
                        <div class="mcq-options">
                            <div class="options-grid">
                                <?php 
                                $options_per_row = 2; // Two options per row
                                $total_options = count($options);
                                
                                for ($i = 0; $i < $total_options; $i += $options_per_row): 
                                ?>
                                <div class="options-row">
                                    <?php 
                                    for ($j = $i; $j < min($i + $options_per_row, $total_options); $j++): 
                                        $option = $options[$j];
                                        list($opt_num, $opt_text) = explode('. ', $option, 2);
                                    ?>
                                    <div class="option-column">
                                        <div class="option-item">
                                            <span class="option-number"><?php echo strtolower($opt_num); ?>.</span> 
                                            <span class="option-text"><?php echo htmlspecialchars($opt_text); ?></span>
                                        </div>
                                    </div>
                                    <?php endfor; ?>
                                </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <?php elseif ($section['section_type'] === 'descriptive'): ?>
                        <div class="descriptive-answer">
                            <div class="answer-space">
                                <p class="answer-placeholder">Answer space for descriptive question...</p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($question_counter < mysqli_num_rows($questions)): ?>
                    <hr class="question-divider">
                    <?php endif; ?>
                    
                    <?php endwhile; ?>
                </div>
            </div>
            <?php endwhile; ?>

            <?php if ($section_counter === 0): ?>
            <div class="alert alert-warning text-center">
                <i class="fas fa-exclamation-triangle"></i> No sections found for this paper.
                <a href="manage_sections.php?exam_id=<?php echo $exam_id; ?>" class="alert-link">Add sections</a> to create the paper.
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function printPaper() {
    // Create a new window for printing
    var printWindow = window.open('', '_blank');
    
    // Get the paper container HTML
    var paperContent = document.getElementById('paper-container').innerHTML;
    
    // Create the print document
    var printDocument = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Exam Paper - Print</title>
            <style>
                body {
                    font-family: 'Times New Roman', serif;
                    margin: 0;
                    padding: 20px;
                    line-height: 1.4;
                    color: #000;
                    background: #fff;
                }
                
                .paper-container {
                    max-width: 100%;
                    margin: 0 auto;
                }
                
                .college-name {
                    font-size: 24px;
                    font-weight: bold;
                    margin-bottom: 15px;
                    text-align: center;
                    text-transform: uppercase;
                    border-bottom: 2px solid #000;
                    padding-bottom: 10px;
                }
                
                .mega-exam-info {
                    margin: 10px 0;
                }
                
                .mega-exam-title {
                    font-size: 18px;
                    font-weight: bold;
                    text-align: center;
                    margin: 10px 0;
                    color: #333;
                }
                
                .mega-exam-code {
                    font-size: 14px;
                    font-weight: normal;
                    color: #666;
                }
                
                .paper-info-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 15px 0;
                    font-size: 14px;
                }
                
                .paper-info-table td {
                    padding: 4px 8px;
                    text-align: center;
                }
                
                .paper-info-table tr:first-child td {
                    font-weight: bold;
                }
                
                .section-container {
                    margin: 25px 0;
                    page-break-inside: avoid;
                }
                
                .section-title {
                    font-size: 18px;
                    font-weight: bold;
                    text-align: center;
                    text-transform: uppercase;
                    margin: 15px 0;
                    border-bottom: 1px solid #000;
                    padding-bottom: 5px;
                }
                
                .section-instructions {
                    font-size: 12px;
                    text-align: center;
                    margin: 10px 0;
                    font-style: italic;
                }
                
                .question-item {
                    margin: 20px 0;
                    page-break-inside: avoid;
                }
                
                .question-number {
                    font-size: 14px;
                    font-weight: bold;
                    margin-bottom: 8px;
                }
                
                .question-number strong {
                    font-weight: bold;
                }
                
                .marks {
                    font-size: 12px;
                    font-weight: normal;
                }
                
                .question-text {
                    font-size: 13px;
                    margin-bottom: 10px;
                    line-height: 1.4;
                }
                
                .sub-question {
                    font-size: 13px;
                    margin-left: 10px;
                }
                
                .sub-question strong {
                    font-weight: bold;
                }
                
                .mcq-options {
                    margin: 10px 0;
                }
                
                .options-grid {
                    width: 100%;
                }
                
                .options-row {
                    display: flex;
                    margin-bottom: 5px;
                }
                
                .option-column {
                    flex: 1;
                    padding: 0 10px;
                }
                
                .option-item {
                    display: flex;
                    align-items: flex-start;
                    margin-bottom: 5px;
                    font-size: 12px;
                }
                
                .option-number {
                    font-weight: bold;
                    margin-right: 6px;
                    min-width: 15px;
                }
                
                .option-text {
                    flex: 1;
                }
                
                .descriptive-answer {
                    margin-top: 10px;
                }
                
                .answer-space {
                    min-height: 100px;
                    border: 1px dashed #666;
                    padding: 10px;
                    margin-top: 5px;
                }
                
                .answer-placeholder {
                    color: #666;
                    font-style: italic;
                    margin: 0;
                    font-size: 11px;
                }
                
                .question-divider {
                    border-top: 1px solid #ccc;
                    margin: 15px 0;
                }
                
                @media print {
                    body {
                        padding: 15px;
                    }
                }
            </style>
        </head>
        <body>
            <div class="paper-container">
                ${paperContent}
            </div>
        </body>
        </html>
    `;
    
    // Write the content to the new window
    printWindow.document.open();
    printWindow.document.write(printDocument);
    printWindow.document.close();
    
    // Wait for content to load then print
    printWindow.onload = function() {
        printWindow.focus();
        printWindow.print();
        printWindow.onafterprint = function() {
            printWindow.close();
        };
    };
}
</script>

<style>
/* Screen styles */
.paper-container {
    max-width: 1000px;
    margin: 0 auto;
    padding: 20px;
    background-color: white;
    box-shadow: 0 0 10px rgba(0,0,0,0.1);
}

.college-name {
    font-size: 28px;
    font-weight: bold;
    margin-bottom: 10px;
    text-transform: uppercase;
    color: #2c3e50;
}

.mega-exam-info {
    margin: 15px 0;
    padding: 10px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 8px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.mega-exam-title {
    font-size: 22px;
    font-weight: bold;
    color: white;
    margin: 0;
    text-align: center;
}

.mega-exam-title i {
    margin-right: 10px;
    opacity: 0.9;
}

.mega-exam-code {
    font-size: 16px;
    font-weight: normal;
    opacity: 0.9;
    margin-left: 10px;
}

.paper-info-table {
    width: 100%;
    border-collapse: collapse;
}

.paper-info-table td {
    padding: 8px 15px;
    font-size: 16px;
    border: 1px solid #ddd;
}

.paper-info-table tr:first-child {
    background-color: #f8f9fa;
}

.paper-description {
    font-style: italic;
    font-size: 14px;
    margin-top: 15px;
    color: #666;
}

.section-container {
    margin-top: 30px;
    border: 1px solid #ddd;
    border-radius: 5px;
    padding: 20px;
    background: #fff;
}

.section-title {
    font-size: 22px;
    font-weight: bold;
    text-transform: uppercase;
    margin-bottom: 15px;
    padding-bottom: 8px;
    border-bottom: 2px solid #2c3e50;
    color: #2c3e50;
}

.section-instructions {
    font-size: 14px;
    padding: 12px;
    background-color: #e8f4fd;
    border-radius: 4px;
    border-left: 4px solid #3498db;
}

.question-item {
    margin-bottom: 25px;
}

.question-number {
    font-size: 18px;
    font-weight: bold;
    margin-bottom: 12px;
    color: #2c3e50;
}

.question-number strong {
    font-weight: bold;
}

.marks {
    font-size: 14px;
    color: #e74c3c;
    font-weight: normal;
    margin-left: 10px;
}

.question-text {
    font-size: 16px;
    line-height: 1.6;
    margin-bottom: 15px;
    color: #34495e;
}

.sub-question {
    font-size: 16px;
    margin-left: 15px;
}

.sub-question strong {
    font-weight: bold;
    color: #2c3e50;
}

.mcq-options {
    margin-top: 15px;
}

.options-grid {
    width: 100%;
}

.options-row {
    display: flex;
    margin-bottom: 8px;
}

.option-column {
    flex: 1;
    padding: 0 10px;
}

.option-item {
    display: flex;
    align-items: flex-start;
    padding: 10px 12px;
    border-radius: 4px;
    border: 1px solid #e1e8ed;
    background: #f8f9fa;
    transition: all 0.2s ease;
    min-height: 45px;
}

.option-item:hover {
    background-color: #e8f4fd;
    border-color: #3498db;
    transform: translateY(-1px);
}

.option-number {
    font-weight: bold;
    margin-right: 10px;
    min-width: 20px;
    color: #2c3e50;
}

.option-text {
    flex: 1;
    color: #34495e;
}

.descriptive-answer {
    margin-top: 15px;
}

.answer-space {
    min-height: 120px;
    border: 2px dashed #bdc3c7;
    border-radius: 4px;
    padding: 20px;
    background-color: #f8f9fa;
    transition: border-color 0.3s ease;
}

.answer-space:hover {
    border-color: #3498db;
}

.answer-placeholder {
    color: #95a5a6;
    font-style: italic;
    margin: 0;
    text-align: center;
}

.question-divider {
    border-top: 2px dashed #ecf0f1;
    margin: 25px 0;
}

/* Print styles for direct printing */
@media print {
    .top-navbar, .sidebar, .btn {
        display: none !important;
    }
    body {
        padding: 15px !important;
        background: white !important;
    }
    .paper-container {
        max-width: 100% !important;
        padding: 0 !important;
        box-shadow: none !important;
    }
    .college-name {
        font-size: 24px !important;
        color: black !important;
    }
    .mega-exam-info {
        background: none !important;
        box-shadow: none !important;
        margin: 10px 0 !important;
        padding: 0 !important;
    }
    .mega-exam-title {
        font-size: 18px !important;
        color: black !important;
    }
    .mega-exam-code {
        font-size: 14px !important;
        color: #666 !important;
    }
    .section-container {
        border: none !important;
        padding: 0 !important;
        margin: 20px 0 !important;
    }
    .section-title {
        font-size: 18px !important;
        color: black !important;
    }
    .question-number {
        font-size: 14px !important;
        color: black !important;
    }
    .question-number strong {
        font-weight: bold !important;
    }
    .question-text {
        font-size: 13px !important;
        color: black !important;
    }
    .sub-question {
        font-size: 13px !important;
    }
    .sub-question strong {
        font-weight: bold !important;
    }
    .option-item {
        border: 1px solid #ccc !important;
        background: white !important;
        padding: 5px 8px !important;
        min-height: auto !important;
    }
    .options-row {
        display: flex !important;
        margin-bottom: 5px !important;
    }
    .option-column {
        flex: 1 !important;
        padding: 0 10px !important;
    }
    .option-number {
        font-weight: bold !important;
    }
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .paper-container {
        padding: 15px;
    }
    
    .college-name {
        font-size: 22px;
    }
    
    .mega-exam-title {
        font-size: 18px;
    }
    
    .mega-exam-code {
        font-size: 14px;
    }
    
    .paper-info-table td {
        font-size: 14px;
        padding: 6px 8px;
    }
    
    .section-title {
        font-size: 18px;
    }
    
    .question-number {
        font-size: 16px;
    }
    
    .question-text {
        font-size: 14px;
    }
    
    .option-item {
        padding: 8px 10px;
    }
    
    .options-row {
        flex-direction: column;
    }
    
    .option-column {
        margin-bottom: 5px;
        padding: 0;
    }
}
</style>

<?php include '../templates/footer.php'; ?>
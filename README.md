# Online Examination System

A complete web-based examination system built with PHP, MySQLi, HTML, CSS, and JavaScript.

## Features

- **Role-Based Access Control**: Admin, Teacher, and Student roles
- **Exam Management**: Create exams with multiple sections (MCQ and Descriptive)
- **Section-wise Timing**: Each section has its own timer
- **Auto-Save**: Answers are automatically saved without AJAX using hidden iframe
- **Browser Security**: Fullscreen enforcement and violation detection
- **Teacher Evaluation**: Evaluate descriptive answers and provide feedback
- **Admin Approval**: Approve exams and results before publishing
- **Digital Mark Certificate (DMC)**: Printable student results

## Technology Stack

- **Backend**: PHP 8.2 with MySQLi
- **Database**: MySQL
- **Frontend**: Bootstrap 5, HTML5, CSS3, JavaScript
- **Color Theme**: #025F11 (Primary Green)

## Installation

1. Install dependencies:
   - PHP 8.2+
   - MySQL

2. Setup the database:
   ```bash
   ./setup.sh
   ```



## Default Login Credentials

### Admin
- Username: `admin`
- Password: `password`

### Teacher
- Username: `teacher1`
- Password: `password`

### Student
- Username: `student1`
- Password: `password`

## Database Structure

The system uses 12 tables:
- `users` - User accounts (admin, teacher, student)
- `departments` - Academic departments
- `batches` - Student batches
- `exams` - Exam details
- `exam_sections` - Exam sections with timing
- `questions` - Exam questions
- `mcq_options` - Multiple choice options
- `student_exam_sessions` - Active exam sessions
- `student_answers` - Student responses
- `evaluations` - Teacher evaluations
- `results` - Final results
- `audit_logs` - System activity logs

## Features by Role

### Admin
- Manage users (create, activate/deactivate)
- Manage departments and batches
- Approve exams created by teachers
- Approve results evaluated by teachers
- View audit logs

### Teacher
- Create exams with sections and questions
- Manage exam content
- Evaluate student descriptive answers
- Provide feedback and marks

### Student
- View available exams
- Take exams with section-wise navigation
- Auto-save functionality
- View approved results
- Print Digital Mark Certificate (DMC)

## Security Features

- Password hashing with `password_hash()`
- CSRF token protection on all forms
- SQL injection prevention with prepared statements
- Session management with token validation
- Rate limiting for login attempts
- Browser violation detection
- Fullscreen enforcement during exams

## Exam Flow

1. Student logs in with credentials
2. Enters Exam ID and Password
3. System validates eligibility
4. Exam starts with section-wise navigation
5. Answers auto-save every 35 seconds
6. Auto-submit on time expiry or violations
7. MCQs are auto-graded
8. Teacher evaluates descriptive answers
9. Admin approves results
10. Student can view and print DMC



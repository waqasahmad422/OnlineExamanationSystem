Got it! Here’s a **ready-to-use AI prompt for Replit** (free plan) that instructs an AI to generate your full **Online Examination System** project with PHP + MySQLi. It’s tailored for Replit’s environment (no paid features, XAMPP-like setup using Replit’s built-in PHP + MySQL):

---

## **REPLIT AI PROMPT – FULL ONLINE EXAM SYSTEM**

```
TASK: 

Create a **complete web-based Online Examination System** using **Pure PHP + MySQLi**, fully functional, designed to run on **Replit free plan**. Do NOT use any paid services or frameworks.  

The system must include:

- **Roles**: Admin, Teacher, Student
- **Core features**:
    - Multiple departments, batches, semesters
    - Daily Exam ID + Password login
    - Section-wise timing (MCQs & Descriptive)
    - Auto-save without AJAX (via hidden iframe)
    - Auto-submit when time ends
    - Browser lock + fullscreen enforcement
    - Teacher evaluation
    - Admin approval
    - Printable student DMC
- All actions must use **POST forms only** (no AJAX)

---

### SYSTEM STRUCTURE:

Create this **folder structure**:

online_exam/
├─ public/
│  ├─ index.php
│  ├─ exam.php
│  ├─ student/
│  │  ├─ dashboard.php
│  │  ├─ exams.php
│  │  ├─ take_exam.php
│  │  └─ results.php
│  ├─ teacher/
│  │  ├─ dashboard.php
│  │  ├─ create_exam.php
│  │  ├─ manage_sections.php
│  │  ├─ evaluate.php
│  │  └─ preview_exam.php
│  ├─ admin/
│  │  ├─ dashboard.php
│  │  ├─ manage_users.php
│  │  ├─ manage_departments.php
│  │  ├─ manage_batches.php
│  │  ├─ exam_approvals.php
│  │  └─ audit_logs.php
│  └─ assets/
│     ├─ css/
│     ├─ js/
│     └─ images/
├─ app/
│  ├─ config.php
│  ├─ helpers.php
│  ├─ auth.php
│  ├─ exam_handlers.php
│  ├─ teacher_handlers.php
│  ├─ admin_handlers.php
│  ├─ student_handlers.php
│  └─ result_handlers.php
├─ templates/
│  ├─ header.php
│  ├─ footer.php
│  ├─ sidebar_admin.php
│  ├─ sidebar_teacher.php
│  └─ sidebar_student.php
├─ sql/
│  ├─ schema.sql
│  └─ sample_data.sql
└─ README.md

---

### DATABASE (MySQLi for Replit):

Tables: 
`users`, `departments`, `batches`, `exams`, `exam_sections`, `questions`, `mcq_options`, 
`student_exam_sessions`, `student_answers`, `evaluations`, `results`, `audit_logs`.

Requirements:
- Primary & foreign keys, indexes
- Password hashing with `password_hash()`
- Sample data for testing
- Fully compatible with Replit MySQL environment

---

### FRONTEND:

- Bootstrap 5 layout
- Dashboards for Admin/Teacher/Student
- Exam interface:
    - Left panel: section list & navigation
    - Right panel: question + options or descriptive box
    - Timer, Save Draft, Submit Section buttons
    - Top bar: Exam title, section timer, total timer, fullscreen, violation counter

- JS:
    - Section timers, total exam timer
    - Fullscreen enforcement
    - Disable right-click and text copy
    - Auto-save via hidden iframe (every 30–40 sec)

---

### BACKEND:

- Pure PHP, modular (no OOP required)
- Use `mysqli_prepare()` for all queries
- Input sanitization
- CSRF token for every POST
- Session tokens and regeneration on login
- Block multiple device logins
- Rate-limit login attempts

---

### EXAM FLOW:

1. Student logs in → enters Exam ID + Password → validate eligibility
2. Section-wise exam flow with timers → auto-submit per section
3. Auto-save answers via hidden iframe
4. Browser violations logged → auto-submit after N violations
5. MCQs auto-graded, descriptive saved for teacher evaluation
6. Teacher evaluates → submits marks
7. Admin approves/rejects → results visible to student
8. Print-ready DMC with marks, feedback, signatures

---

### DELIVERABLES:

1. Full project code ready to run on Replit free plan
2. `sql/schema.sql` + `sample_data.sql`
3. README.md with setup & usage instructions
4. Fully functional dashboards
5. Exam interface + auto-save
6. Teacher evaluation module
7. Admin approval module
8. DMC printing page
```

---

This **prompt can be directly pasted into an AI on Replit** to generate a **complete PHP + MySQLi Online Exam System** compatible with the free plan.

If you want, I can **also write a version of this prompt with even simpler 7–8 step instructions** for the AI to follow, which may work faster on Replit.

Do you want me to do that?

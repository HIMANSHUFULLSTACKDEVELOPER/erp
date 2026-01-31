<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('admin')) {
    redirect('login.php');
}

// Handle student semester assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_student'])) {
    $student_id = $_POST['student_id'];
    $semester_id = $_POST['semester_id'];
    $section_id = $_POST['section_id'];
    $academic_year = $_POST['academic_year'];
    
    // Deactivate previous semester
    $conn->query("UPDATE student_semesters SET is_active = 0 WHERE student_id = $student_id");
    
    // Check if record exists
    $check = $conn->query("SELECT id FROM student_semesters 
                          WHERE student_id = $student_id AND semester_id = $semester_id 
                          AND academic_year = '$academic_year'");
    
    if ($check->num_rows > 0) {
        // Update existing
        $conn->query("UPDATE student_semesters 
                     SET section_id = $section_id, is_active = 1 
                     WHERE student_id = $student_id AND semester_id = $semester_id 
                     AND academic_year = '$academic_year'");
    } else {
        // Insert new
        $stmt = $conn->prepare("INSERT INTO student_semesters 
                               (student_id, semester_id, section_id, academic_year, is_active) 
                               VALUES (?, ?, ?, ?, 1)");
        $stmt->bind_param("iiis", $student_id, $semester_id, $section_id, $academic_year);
        $stmt->execute();
    }
    
    $_SESSION['success'] = "Student assigned to class successfully!";
    header("Location: classes.php");
    exit();
}

// Handle teacher subject assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_teacher'])) {
    $subject_id = $_POST['subject_id'];
    $teacher_id = $_POST['teacher_id'];
    $semester_id = $_POST['semester_id'];
    $section_id = $_POST['section_id'];
    $academic_year = $_POST['academic_year'];
    
    // Check if already assigned
    $check = $conn->query("SELECT id FROM subject_teachers 
                          WHERE subject_id = $subject_id AND semester_id = $semester_id 
                          AND section_id = $section_id AND academic_year = '$academic_year'");
    
    if ($check->num_rows > 0) {
        // Update existing
        $conn->query("UPDATE subject_teachers 
                     SET teacher_id = $teacher_id 
                     WHERE subject_id = $subject_id AND semester_id = $semester_id 
                     AND section_id = $section_id AND academic_year = '$academic_year'");
    } else {
        // Insert new
        $stmt = $conn->prepare("INSERT INTO subject_teachers 
                               (subject_id, teacher_id, semester_id, section_id, academic_year) 
                               VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iiiis", $subject_id, $teacher_id, $semester_id, $section_id, $academic_year);
        $stmt->execute();
    }
    
    $_SESSION['success'] = "Teacher assigned to subject successfully!";
    header("Location: classes.php");
    exit();
}

// Get current academic year
$current_year = date('Y');
$academic_year = "$current_year-" . ($current_year + 1);

// Get class composition
$classes = $conn->query("SELECT 
                        d.department_name,
                        sem.semester_name,
                        sec.section_name,
                        COUNT(DISTINCT ss.student_id) as student_count,
                        ss.semester_id,
                        ss.section_id
                        FROM student_semesters ss
                        JOIN semesters sem ON ss.semester_id = sem.semester_id
                        JOIN sections sec ON ss.section_id = sec.section_id
                        JOIN students s ON ss.student_id = s.student_id
                        JOIN departments d ON s.department_id = d.department_id
                        WHERE ss.is_active = 1
                        GROUP BY d.department_id, ss.semester_id, ss.section_id
                        ORDER BY d.department_name, sem.semester_number, sec.section_name");

// Get all students for assignment
$students = $conn->query("SELECT s.student_id, s.full_name, s.admission_number, 
                         d.department_name, ss.semester_id, ss.section_id
                         FROM students s
                         JOIN departments d ON s.department_id = d.department_id
                         LEFT JOIN student_semesters ss ON s.student_id = ss.student_id AND ss.is_active = 1
                         ORDER BY s.full_name");

// Get subjects for teacher assignment
$subjects = $conn->query("SELECT sub.subject_id, sub.subject_name, sub.subject_code,
                         d.department_name, sem.semester_name
                         FROM subjects sub
                         JOIN departments d ON sub.department_id = d.department_id
                         JOIN semesters sem ON sub.semester_id = sem.semester_id
                         ORDER BY d.department_name, sem.semester_number");

// Get teachers
$teachers = $conn->query("SELECT t.teacher_id, t.full_name, d.department_name
                         FROM teachers t
                         JOIN departments d ON t.department_id = d.department_id
                         ORDER BY t.full_name");

// Get sections
$sections = $conn->query("SELECT * FROM sections ORDER BY section_name");

// Get semesters
$semesters = $conn->query("SELECT * FROM semesters ORDER BY semester_number");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Classes - College ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --secondary: #8b5cf6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #1f2937;
            --gray: #6b7280;
            --light-gray: #f3f4f6;
            --white: #ffffff;
            --sidebar-width: 260px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--light-gray);
            color: var(--dark);
            overflow-x: hidden;
        }

        /* Animations */
        @keyframes slideInLeft {
            from {
                transform: translateX(-100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideDown {
            from {
                transform: translateY(-100%);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @keyframes scaleIn {
            from {
                transform: scale(0.9);
                opacity: 0;
            }
            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        .dashboard {
            display: flex;
            min-height: 100vh;
        }

        /* Mobile Menu Toggle */
        .menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: var(--primary);
            color: var(--white);
            border: none;
            width: 45px;
            height: 45px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 1.2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }

        .menu-toggle:hover {
            background: var(--primary-dark);
            transform: scale(1.1);
        }

        .menu-toggle:active {
            transform: scale(0.95);
        }

        .sidebar {
            width: var(--sidebar-width);
            background: var(--dark);
            color: var(--white);
            padding: 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: transform 0.3s ease;
            animation: slideInLeft 0.4s ease;
        }

        .sidebar-header {
            padding: 30px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .sidebar-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
        }

        .sidebar-header p {
            font-size: 0.85rem;
            opacity: 0.7;
            margin-top: 5px;
        }

        .sidebar-menu {
            padding: 20px 0;
        }

        .menu-item {
            padding: 15px 20px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            display: flex;
            align-items: center;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .menu-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 4px;
            background: var(--primary);
            transform: scaleY(0);
            transition: transform 0.3s ease;
        }

        .menu-item:hover::before,
        .menu-item.active::before {
            transform: scaleY(1);
        }

        .menu-item:hover, .menu-item.active {
            background: var(--primary);
            color: var(--white);
            padding-left: 25px;
        }

        .menu-item i {
            margin-right: 12px;
            width: 20px;
            text-align: center;
            transition: transform 0.3s ease;
        }

        .menu-item:hover i {
            transform: scale(1.2) rotate(5deg);
        }

        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 30px;
            transition: margin-left 0.3s ease;
            animation: fadeIn 0.5s ease;
        }

        .top-bar {
            background: var(--white);
            padding: 20px 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            animation: slideDown 0.5s ease;
            flex-wrap: wrap;
            gap: 15px;
        }

        .top-bar h1 {
            font-size: 1.8rem;
            font-weight: 700;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            animation: slideDown 0.5s ease;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border-left: 4px solid var(--success);
        }

        .card {
            background: var(--white);
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            transition: all 0.3s ease;
            animation: scaleIn 0.5s ease;
        }

        .card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .card-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .card-header h3 {
            font-size: 1.2rem;
            font-weight: 600;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-primary {
            background: var(--primary);
            color: var(--white);
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-success {
            background: var(--success);
            color: var(--white);
        }

        .btn-success:hover {
            background: #059669;
        }

        .btn-danger {
            background: var(--danger);
            color: var(--white);
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 0.85rem;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            text-align: left;
            padding: 12px;
            border-bottom: 2px solid var(--light-gray);
            font-weight: 600;
            color: var(--gray);
            font-size: 0.85rem;
            text-transform: uppercase;
        }

        .table td {
            padding: 15px 12px;
            border-bottom: 1px solid var(--light-gray);
        }

        .table tr {
            transition: all 0.3s ease;
        }

        .table tbody tr:hover {
            background: var(--light-gray);
            transform: scale(1.01);
        }

        .badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-block;
        }

        .badge:hover {
            transform: scale(1.1);
        }

        .badge.info {
            background: rgba(37, 99, 235, 0.1);
            color: var(--primary);
        }

        .badge.success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal.active {
            display: flex;
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background: var(--white);
            padding: 30px;
            border-radius: 12px;
            width: 100%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            animation: scaleIn 0.3s ease;
        }

        .modal-header {
            margin-bottom: 20px;
        }

        .modal-header h3 {
            font-size: 1.4rem;
            font-weight: 600;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .modal-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            animation: fadeIn 0.6s ease;
        }

        .tab {
            padding: 12px 24px;
            border: none;
            background: var(--light-gray);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            flex: 1;
            min-width: 150px;
        }

        .tab:hover {
            background: #e5e7eb;
            transform: translateY(-2px);
        }

        .tab.active {
            background: var(--primary);
            color: var(--white);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 999;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .sidebar-overlay.active {
            display: block;
            opacity: 1;
        }

        /* Responsive Design - Tablet */
        @media (max-width: 1024px) {
            .form-row {
                grid-template-columns: 1fr;
            }

            .top-bar h1 {
                font-size: 1.5rem;
            }

            .tabs {
                gap: 8px;
            }

            .tab {
                min-width: 120px;
                padding: 10px 16px;
                font-size: 0.9rem;
            }
        }

        /* Responsive Design - Mobile */
        @media (max-width: 768px) {
            .menu-toggle {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 80px 15px 15px;
            }

            .top-bar {
                padding: 15px;
                flex-direction: column;
                align-items: flex-start;
            }

            .top-bar h1 {
                font-size: 1.3rem;
            }

            .card {
                padding: 15px;
            }

            .card-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .tabs {
                flex-direction: column;
                width: 100%;
            }

            .tab {
                width: 100%;
                text-align: center;
            }

            .table {
                font-size: 0.85rem;
            }

            .table th,
            .table td {
                padding: 10px 8px;
            }

            /* Make table scrollable on mobile */
            .table-container {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            .modal-content {
                padding: 20px;
                max-height: 85vh;
            }

            .modal-header h3 {
                font-size: 1.2rem;
            }

            .form-control {
                padding: 10px;
            }

            .modal-footer {
                flex-direction: column-reverse;
            }

            .modal-footer .btn {
                width: 100%;
            }
        }

        /* Small Mobile Devices */
        @media (max-width: 480px) {
            .main-content {
                padding: 70px 10px 10px;
            }

            .top-bar {
                padding: 10px;
            }

            .top-bar h1 {
                font-size: 1.1rem;
            }

            .card {
                padding: 12px;
            }

            .card-header h3 {
                font-size: 1rem;
            }

            .btn {
                padding: 8px 16px;
                font-size: 0.85rem;
            }

            .btn-sm {
                padding: 6px 12px;
                font-size: 0.8rem;
            }

            .tab {
                padding: 10px 12px;
                font-size: 0.85rem;
            }

            .table {
                font-size: 0.75rem;
            }

            .table th,
            .table td {
                padding: 8px 4px;
            }

            .badge {
                padding: 4px 8px;
                font-size: 0.7rem;
            }

            .modal-content {
                padding: 15px;
            }

            .form-group {
                margin-bottom: 15px;
            }

            .form-control {
                font-size: 0.9rem;
            }
        }

        /* Landscape orientation */
        @media (max-height: 500px) and (orientation: landscape) {
            .modal-content {
                max-height: 95vh;
            }

            .sidebar {
                overflow-y: scroll;
            }
        }

        /* Table wrapper for horizontal scroll */
        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: 0 -15px;
            padding: 0 15px;
        }

        @media (max-width: 768px) {
            .table-wrapper {
                margin: 0 -10px;
                padding: 0 10px;
            }
        }
    </style>
</head>
<body>
    <button class="menu-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="sidebar-overlay" onclick="toggleSidebar()"></div>

    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>Admin Panel</h2>
                <p>College ERP System</p>
            </div>
            <nav class="sidebar-menu">
                <a href="admin_dashboard.php" class="menu-item" onclick="closeSidebarMobile()">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="manage_students.php" class="menu-item" onclick="closeSidebarMobile()">
                    <i class="fas fa-user-graduate"></i> Students
                </a>
                <a href="manage_teachers.php" class="menu-item" onclick="closeSidebarMobile()">
                    <i class="fas fa-chalkboard-teacher"></i> Teachers
                </a>
                <a href="manage_hod.php" class="menu-item" onclick="closeSidebarMobile()">
                    <i class="fas fa-user-tie"></i> HODs
                </a>
                <a href="manage_departments.php" class="menu-item" onclick="closeSidebarMobile()">
                    <i class="fas fa-building"></i> Departments
                </a>
                <a href="manage_courses.php" class="menu-item" onclick="closeSidebarMobile()">
                    <i class="fas fa-book"></i> Courses
                </a>
                <a href="manage_subjects.php" class="menu-item" onclick="closeSidebarMobile()">
                    <i class="fas fa-list"></i> Subjects
                </a>
                <a href="manage_parent.php" class="menu-item" onclick="closeSidebarMobile()">
                    <i class="fas fa-users"></i> Parents
                </a>
                <a href="classes.php" class="menu-item active" onclick="closeSidebarMobile()">
                    <i class="fas fa-door-open"></i> Classes
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-bar">
                <h1>Manage Classes</h1>
                <a href="../logout.php" class="btn btn-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php 
                    echo $_SESSION['success'];
                    unset($_SESSION['success']);
                    ?>
                </div>
            <?php endif; ?>

            <div class="tabs">
                <button class="tab active" onclick="showTab('classes')">
                    <i class="fas fa-door-open"></i> Class Composition
                </button>
                <button class="tab" onclick="showTab('students')">
                    <i class="fas fa-user-plus"></i> Assign Students
                </button>
                <button class="tab" onclick="showTab('teachers')">
                    <i class="fas fa-chalkboard-teacher"></i> Assign Teachers
                </button>
            </div>

            <!-- Classes Tab -->
            <div id="classes" class="tab-content active">
                <div class="card">
                    <div class="card-header">
                        <h3>Current Class Composition (<?php echo $academic_year; ?>)</h3>
                    </div>
                    <div class="table-wrapper">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Department</th>
                                    <th>Semester</th>
                                    <th>Section</th>
                                    <th>Students</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($classes->num_rows > 0): ?>
                                    <?php while($class = $classes->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo $class['department_name']; ?></td>
                                        <td><?php echo $class['semester_name']; ?></td>
                                        <td><span class="badge info">Section <?php echo $class['section_name']; ?></span></td>
                                        <td><span class="badge success"><?php echo $class['student_count']; ?> Students</span></td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" style="text-align: center; padding: 30px; color: var(--gray);">
                                            <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                            No classes configured yet. Start assigning students!
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Students Tab -->
            <div id="students" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Assign Students to Classes</h3>
                    </div>
                    <div class="table-wrapper">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Admission No.</th>
                                    <th>Name</th>
                                    <th>Department</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($student = $students->fetch_assoc()): ?>
                                <tr>
                                    <td><span class="badge info"><?php echo $student['admission_number']; ?></span></td>
                                    <td><?php echo $student['full_name']; ?></td>
                                    <td><?php echo $student['department_name']; ?></td>
                                    <td>
                                        <?php if ($student['semester_id']): ?>
                                            <span class="badge success">Assigned</span>
                                        <?php else: ?>
                                            <span style="color: var(--danger); font-weight: 500;">Not Assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-primary btn-sm" 
                                                onclick="openStudentModal(<?php echo $student['student_id']; ?>, '<?php echo addslashes($student['full_name']); ?>')">
                                            <i class="fas fa-user-plus"></i> Assign
                                        </button>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Teachers Tab -->
            <div id="teachers" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Assign Teachers to Subjects</h3>
                        <button class="btn btn-primary" onclick="openTeacherModal()">
                            <i class="fas fa-chalkboard-teacher"></i> Assign Teacher
                        </button>
                    </div>
                    <p style="padding: 20px; color: var(--gray);">
                        <i class="fas fa-info-circle"></i> Select a subject and assign a teacher to it for the current academic year.
                    </p>
                </div>
            </div>
        </main>
    </div>

    <!-- Student Assignment Modal -->
    <div id="studentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i> Assign Student to Class</h3>
            </div>
            <form method="POST">
                <input type="hidden" name="student_id" id="student_id">
                <input type="hidden" name="academic_year" value="<?php echo $academic_year; ?>">
                
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Student Name</label>
                    <input type="text" id="student_name" class="form-control" readonly>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-calendar"></i> Semester</label>
                    <select name="semester_id" class="form-control" required>
                        <option value="">-- Select Semester --</option>
                        <?php 
                        $semesters->data_seek(0);
                        while($sem = $semesters->fetch_assoc()): 
                        ?>
                        <option value="<?php echo $sem['semester_id']; ?>">
                            <?php echo $sem['semester_name']; ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-door-open"></i> Section</label>
                    <select name="section_id" class="form-control" required>
                        <option value="">-- Select Section --</option>
                        <?php 
                        $sections->data_seek(0);
                        while($sec = $sections->fetch_assoc()): 
                        ?>
                        <option value="<?php echo $sec['section_id']; ?>">
                            Section <?php echo $sec['section_name']; ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" onclick="closeStudentModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="assign_student" class="btn btn-success">
                        <i class="fas fa-check"></i> Assign
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Teacher Assignment Modal -->
    <div id="teacherModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-chalkboard-teacher"></i> Assign Teacher to Subject</h3>
            </div>
            <form method="POST">
                <input type="hidden" name="academic_year" value="<?php echo $academic_year; ?>">
                
                <div class="form-group">
                    <label><i class="fas fa-book"></i> Subject</label>
                    <select name="subject_id" class="form-control" required>
                        <option value="">-- Select Subject --</option>
                        <?php while($subject = $subjects->fetch_assoc()): ?>
                        <option value="<?php echo $subject['subject_id']; ?>">
                            <?php echo $subject['subject_code']; ?> - <?php echo $subject['subject_name']; ?> 
                            (<?php echo $subject['department_name']; ?>)
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-calendar"></i> Semester</label>
                    <select name="semester_id" id="sem_select" class="form-control" required>
                        <option value="">-- Select Semester --</option>
                        <?php 
                        $semesters->data_seek(0);
                        while($sem = $semesters->fetch_assoc()): 
                        ?>
                        <option value="<?php echo $sem['semester_id']; ?>">
                            <?php echo $sem['semester_name']; ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-door-open"></i> Section</label>
                    <select name="section_id" class="form-control" required>
                        <option value="">-- Select Section --</option>
                        <?php 
                        $sections->data_seek(0);
                        while($sec = $sections->fetch_assoc()): 
                        ?>
                        <option value="<?php echo $sec['section_id']; ?>">
                            Section <?php echo $sec['section_name']; ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-user"></i> Teacher</label>
                    <select name="teacher_id" class="form-control" required>
                        <option value="">-- Select Teacher --</option>
                        <?php while($teacher = $teachers->fetch_assoc()): ?>
                        <option value="<?php echo $teacher['teacher_id']; ?>">
                            <?php echo $teacher['full_name']; ?> (<?php echo $teacher['department_name']; ?>)
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" onclick="closeTeacherModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="assign_teacher" class="btn btn-success">
                        <i class="fas fa-check"></i> Assign
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
        }

        function closeSidebarMobile() {
            if (window.innerWidth <= 768) {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.querySelector('.sidebar-overlay');
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
            }
        }

        // Close sidebar when resizing to desktop
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.querySelector('.sidebar-overlay');
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
            }
        });

        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });

            // Show selected tab
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
        }

        function openStudentModal(studentId, studentName) {
            document.getElementById('student_id').value = studentId;
            document.getElementById('student_name').value = studentName;
            document.getElementById('studentModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeStudentModal() {
            document.getElementById('studentModal').classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        function openTeacherModal() {
            document.getElementById('teacherModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeTeacherModal() {
            document.getElementById('teacherModal').classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const studentModal = document.getElementById('studentModal');
            const teacherModal = document.getElementById('teacherModal');
            if (event.target == studentModal) {
                closeStudentModal();
            }
            if (event.target == teacherModal) {
                closeTeacherModal();
            }
        }

        // Close modal with ESC key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeStudentModal();
                closeTeacherModal();
            }
        });
    </script>
</body>
</html>
<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('parent')) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

// Get parent details
$sql = "SELECT p.* FROM parents p WHERE p.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$parent = $stmt->get_result()->fetch_assoc();

// Get linked students
$students = $conn->query("SELECT s.*, d.department_name 
                         FROM parent_student ps
                         JOIN students s ON ps.student_id = s.student_id
                         JOIN departments d ON s.department_id = d.department_id
                         WHERE ps.parent_id = {$parent['parent_id']}");

// Selected student
$selected_student_id = $_GET['student_id'] ?? null;
if (!$selected_student_id && $students->num_rows > 0) {
    $students->data_seek(0);
    $selected_student_id = $students->fetch_assoc()['student_id'];
}

// Get student info
$student_info = null;
$semester_history = null;

if ($selected_student_id) {
    $student_info = $conn->query("SELECT s.*, d.department_name, c.course_name
                                  FROM students s
                                  JOIN departments d ON s.department_id = d.department_id
                                  JOIN courses c ON s.course_id = c.course_id
                                  WHERE s.student_id = $selected_student_id")->fetch_assoc();
    
    // Get all semester enrollments
    $semester_history = $conn->query("SELECT 
        ss.*,
        sem.semester_name,
        sem.semester_number,
        sec.section_name,
        (SELECT COUNT(*) FROM student_subjects studsub 
         WHERE studsub.student_id = ss.student_id 
         AND studsub.semester_id = ss.semester_id
         AND studsub.status = 'active') as total_subjects
        FROM student_semesters ss
        JOIN semesters sem ON ss.semester_id = sem.semester_id
        LEFT JOIN sections sec ON ss.section_id = sec.section_id
        WHERE ss.student_id = $selected_student_id
        ORDER BY sem.semester_number DESC, ss.created_at DESC");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Semester History - Parent Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #6366f1;
            --secondary: #a855f7;
            --success: #22c55e;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #1e293b;
            --gray: #64748b;
            --light-gray: #f8fafc;
            --white: #ffffff;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--light-gray);
            color: var(--dark);
        }

        /* Animations */
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

        @keyframes slideIn {
            from {
                transform: translateX(-100%);
            }
            to {
                transform: translateX(0);
            }
        }

        @keyframes pulse {
            0%, 100% { 
                box-shadow: 0 0 0 3px var(--light-gray), 0 0 0 6px rgba(34, 197, 94, 0.1); 
            }
            50% { 
                box-shadow: 0 0 0 3px var(--light-gray), 0 0 0 10px rgba(34, 197, 94, 0.2); 
            }
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .dashboard {
            display: flex;
            min-height: 100vh;
        }

        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            border: none;
            width: 50px;
            height: 50px;
            border-radius: 12px;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.4);
            transition: all 0.3s;
        }

        .mobile-menu-toggle:active {
            transform: scale(0.95);
        }

        .mobile-menu-toggle i {
            font-size: 1.3rem;
        }

        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #1e293b 0%, #334155 100%);
            color: var(--white);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: transform 0.3s ease;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 35px 25px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            animation: slideIn 0.5s ease;
        }

        .parent-info {
            text-align: center;
        }

        .parent-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: rgba(255,255,255,0.25);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 700;
            margin: 0 auto 12px;
            border: 3px solid rgba(255,255,255,0.3);
            transition: all 0.3s;
        }

        .parent-avatar:hover {
            transform: scale(1.1) rotate(5deg);
        }

        .parent-name {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .parent-role {
            font-size: 0.85rem;
            opacity: 0.9;
            text-transform: capitalize;
        }

        .sidebar-menu {
            padding: 25px 0;
        }

        .menu-item {
            padding: 16px 25px;
            color: rgba(255,255,255,0.75);
            text-decoration: none;
            display: flex;
            align-items: center;
            transition: all 0.3s;
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
            transition: transform 0.3s;
        }

        .menu-item:hover::before,
        .menu-item.active::before {
            transform: scaleY(1);
        }

        .menu-item:hover, .menu-item.active {
            background: rgba(99, 102, 241, 0.2);
            color: var(--white);
            transform: translateX(5px);
        }

        .menu-item i {
            margin-right: 15px;
            width: 20px;
            text-align: center;
            transition: all 0.3s;
        }

        .menu-item:hover i {
            transform: scale(1.2);
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            animation: fadeIn 0.5s ease;
            transition: margin-left 0.3s ease;
        }

        .top-bar {
            background: var(--white);
            padding: 25px 30px;
            border-radius: 18px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            animation: fadeIn 0.6s ease;
        }

        .top-bar h1 {
            font-size: 2rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .student-selector {
            background: var(--white);
            padding: 20px 30px;
            border-radius: 18px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            animation: fadeIn 0.7s ease;
        }

        .student-selector select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid var(--light-gray);
            border-radius: 12px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .student-selector select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .student-selector select:hover {
            border-color: var(--primary);
        }

        .card {
            background: var(--white);
            padding: 30px;
            border-radius: 18px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin-bottom: 25px;
            animation: fadeIn 0.8s ease;
            transition: all 0.3s;
        }

        .card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }

        .card-header {
            display: flex;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--light-gray);
        }

        .card-header i {
            font-size: 1.5rem;
            color: var(--primary);
            margin-right: 15px;
            transition: all 0.3s;
        }

        .card:hover .card-header i {
            transform: scale(1.2) rotate(10deg);
        }

        .card-header h3 {
            font-size: 1.4rem;
            font-weight: 700;
        }

        .timeline {
            position: relative;
            padding-left: 40px;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            bottom: 0;
            width: 3px;
            background: var(--light-gray);
        }

        .timeline-item {
            position: relative;
            padding-bottom: 40px;
            animation: fadeIn 0.9s ease;
        }

        .timeline-item:last-child {
            padding-bottom: 0;
        }

        .timeline-dot {
            position: absolute;
            left: -35px;
            top: 0;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--primary);
            border: 4px solid var(--white);
            box-shadow: 0 0 0 3px var(--light-gray);
            transition: all 0.3s;
        }

        .timeline-dot.active {
            background: var(--success);
            animation: pulse 2s infinite;
        }

        .timeline-item:hover .timeline-dot {
            transform: scale(1.2);
        }

        .semester-card {
            background: var(--light-gray);
            padding: 25px;
            border-radius: 15px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .semester-card:hover {
            transform: translateX(5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
            background: #e2e8f0;
        }

        .semester-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .semester-name {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--dark);
            transition: all 0.3s;
        }

        .semester-card:hover .semester-name {
            color: var(--primary);
        }

        .semester-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            transition: all 0.3s;
        }

        .semester-badge:hover {
            transform: scale(1.05);
        }

        .semester-badge.active {
            background: rgba(34, 197, 94, 0.15);
            color: var(--success);
        }

        .semester-badge.completed {
            background: rgba(99, 102, 241, 0.15);
            color: var(--primary);
        }

        .semester-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .info-item {
            background: var(--white);
            padding: 12px;
            border-radius: 10px;
            transition: all 0.3s;
        }

        .info-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .info-label {
            font-size: 0.75rem;
            color: var(--gray);
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark);
        }

        .semester-details {
            display: none;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px dashed var(--light-gray);
            animation: slideDown 0.3s ease;
        }

        .semester-details.show {
            display: block;
        }

        .subject-list {
            display: grid;
            gap: 10px;
            margin-top: 15px;
        }

        .subject-item {
            background: var(--white);
            padding: 15px;
            border-radius: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s;
        }

        .subject-item:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        .subject-name {
            font-weight: 600;
        }

        .subject-code {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .back-btn {
            background: linear-gradient(135deg, var(--gray), #475569);
            color: var(--white);
            border: none;
            padding: 12px 26px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }

        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(71, 85, 105, 0.3);
        }

        .back-btn:active {
            transform: translateY(0);
        }

        .view-details-btn {
            background: var(--primary);
            color: var(--white);
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .view-details-btn:hover {
            background: var(--secondary);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }

        .view-details-btn:active {
            transform: translateY(0);
        }

        /* Sidebar Overlay for Mobile */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 999;
        }

        .sidebar-overlay.active {
            display: block;
        }

        /* Responsive Design */
        @media (max-width: 992px) {
            .top-bar h1 {
                font-size: 1.5rem;
            }

            .semester-info {
                grid-template-columns: repeat(2, 1fr);
            }

            .timeline {
                padding-left: 35px;
            }

            .timeline::before {
                left: 8px;
            }

            .timeline-dot {
                left: -32px;
                width: 20px;
                height: 20px;
            }
        }

        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
                box-shadow: 4px 0 15px rgba(0,0,0,0.3);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 20px 15px;
                padding-top: 80px;
            }

            .top-bar {
                flex-direction: column;
                gap: 15px;
                padding: 20px;
                text-align: center;
            }

            .top-bar h1 {
                font-size: 1.3rem;
            }

            .back-btn {
                width: 100%;
                text-align: center;
            }

            .student-selector {
                padding: 15px 20px;
            }

            .card {
                padding: 20px;
            }

            .card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .card-header h3 {
                font-size: 1.1rem;
            }

            .timeline {
                padding-left: 30px;
            }

            .timeline::before {
                left: 6px;
            }

            .timeline-dot {
                left: -28px;
                width: 18px;
                height: 18px;
                border-width: 3px;
            }

            .timeline-item {
                padding-bottom: 30px;
            }

            .semester-card {
                padding: 20px;
            }

            .semester-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .semester-name {
                font-size: 1.1rem;
            }

            .semester-info {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .info-item {
                padding: 10px;
            }

            .info-value {
                font-size: 1rem;
            }

            .subject-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 15px 10px;
                padding-top: 75px;
            }

            .top-bar {
                padding: 15px;
            }

            .top-bar h1 {
                font-size: 1.2rem;
            }

            .student-selector {
                padding: 12px 15px;
            }

            .student-selector select {
                font-size: 0.9rem;
                padding: 10px 12px;
            }

            .card {
                padding: 15px;
            }

            .card-header i {
                font-size: 1.2rem;
                margin-right: 10px;
            }

            .card-header h3 {
                font-size: 1rem;
            }

            .timeline {
                padding-left: 25px;
            }

            .timeline-dot {
                left: -25px;
                width: 16px;
                height: 16px;
            }

            .semester-card {
                padding: 15px;
            }

            .semester-name {
                font-size: 1rem;
            }

            .semester-badge {
                font-size: 0.7rem;
                padding: 4px 10px;
            }

            .info-label {
                font-size: 0.7rem;
            }

            .info-value {
                font-size: 0.95rem;
            }

            .view-details-btn {
                font-size: 0.8rem;
                padding: 6px 12px;
            }

            .subject-name {
                font-size: 0.9rem;
            }

            .subject-code {
                font-size: 0.8rem;
            }
        }
    </style>
    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
        }

        // Close sidebar when clicking on menu items on mobile
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.menu-item').forEach(item => {
                item.addEventListener('click', function() {
                    if (window.innerWidth <= 768) {
                        toggleSidebar();
                    }
                });
            });
        });

        function toggleSemesterDetails(semesterId) {
            const details = document.getElementById('details-' + semesterId);
            const btn = document.getElementById('btn-' + semesterId);
            
            if (details.classList.contains('show')) {
                details.classList.remove('show');
                btn.textContent = 'View Details';
            } else {
                // Hide all other details
                document.querySelectorAll('.semester-details').forEach(d => d.classList.remove('show'));
                document.querySelectorAll('.view-details-btn').forEach(b => b.textContent = 'View Details');
                
                details.classList.add('show');
                btn.textContent = 'Hide Details';
                
                // Load subjects if not already loaded
                if (!details.dataset.loaded) {
                    loadSemesterSubjects(<?php echo $selected_student_id; ?>, semesterId, details);
                }
            }
        }

        function loadSemesterSubjects(studentId, semesterId, container) {
            fetch(`get_semester_subjects.php?student_id=${studentId}&semester_id=${semesterId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.subjects && data.subjects.length > 0) {
                        const subjectList = container.querySelector('.subject-list');
                        subjectList.innerHTML = data.subjects.map(subject => `
                            <div class="subject-item">
                                <div>
                                    <div class="subject-name">${subject.subject_name}</div>
                                    <div class="subject-code">${subject.subject_code} • ${subject.credits} Credits</div>
                                </div>
                                <div style="text-align: right;">
                                    <div style="font-size: 0.85rem; color: var(--gray);">Teacher</div>
                                    <div style="font-weight: 600;">${subject.teacher_name || 'Not Assigned'}</div>
                                </div>
                            </div>
                        `).join('');
                    } else {
                        container.querySelector('.subject-list').innerHTML = '<p style="text-align: center; color: var(--gray); padding: 20px;">No subjects enrolled</p>';
                    }
                    container.dataset.loaded = 'true';
                })
                .catch(error => {
                    console.error('Error loading subjects:', error);
                    container.querySelector('.subject-list').innerHTML = '<p style="text-align: center; color: var(--danger); padding: 20px;">Error loading subjects</p>';
                });
        }
    </script>
</head>
<body>
    <button class="mobile-menu-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="sidebar-overlay" onclick="toggleSidebar()"></div>

    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="parent-info">
                    <div class="parent-avatar"><?php echo strtoupper(substr($parent['full_name'], 0, 1)); ?></div>
                    <div class="parent-name"><?php echo $parent['full_name']; ?></div>
                    <div class="parent-role"><?php echo ucfirst($parent['relation']); ?></div>
                </div>
            </div>
            <nav class="sidebar-menu">
                <a href="index.php" class="menu-item">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="children_attendance.php" class="menu-item">
                    <i class="fas fa-calendar-check"></i> Attendance
                </a>
                <a href="semester_history.php" class="menu-item active">
                    <i class="fas fa-history"></i> Semester History
                </a>
                <a href="children_subjects.php" class="menu-item">
                    <i class="fas fa-book"></i> Subjects
                </a>
                <a href="parent_profile.php" class="menu-item">
                    <i class="fas fa-user"></i> Profile
                </a>
                <a href="parent_settings.php" class="menu-item">
                    <i class="fas fa-cog"></i> Settings
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-bar">
                <h1>Semester History</h1>
                <a href="index.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            </div>

            <?php if ($students->num_rows > 1): ?>
            <div class="student-selector">
                <select onchange="window.location.href='semester_history.php?student_id=' + this.value">
                    <?php 
                    $students->data_seek(0);
                    while($student = $students->fetch_assoc()): 
                    ?>
                    <option value="<?php echo $student['student_id']; ?>" 
                            <?php echo ($student['student_id'] == $selected_student_id) ? 'selected' : ''; ?>>
                        <?php echo $student['full_name']; ?> (<?php echo $student['admission_number']; ?>)
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <?php endif; ?>

            <?php if ($student_info): ?>
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-user-graduate"></i>
                    <h3><?php echo $student_info['full_name']; ?></h3>
                </div>
                <p><strong>Admission Number:</strong> <?php echo $student_info['admission_number']; ?></p>
                <p><strong>Department:</strong> <?php echo $student_info['department_name']; ?></p>
                <p><strong>Course:</strong> <?php echo $student_info['course_name']; ?></p>
            </div>

            <div class="card">
                <div class="card-header">
                    <i class="fas fa-clock"></i>
                    <h3>Semester Timeline</h3>
                </div>
                
                <?php if ($semester_history && $semester_history->num_rows > 0): ?>
                <div class="timeline">
                    <?php while($sem = $semester_history->fetch_assoc()): ?>
                    <div class="timeline-item">
                        <div class="timeline-dot <?php echo $sem['is_active'] ? 'active' : ''; ?>"></div>
                        <div class="semester-card" onclick="toggleSemesterDetails(<?php echo $sem['id']; ?>)">
                            <div class="semester-header">
                                <div class="semester-name"><?php echo $sem['semester_name']; ?></div>
                                <span class="semester-badge <?php echo $sem['is_active'] ? 'active' : 'completed'; ?>">
                                    <?php echo $sem['is_active'] ? 'Current' : 'Completed'; ?>
                                </span>
                            </div>
                            
                            <div class="semester-info">
                                <div class="info-item">
                                    <div class="info-label">Academic Year</div>
                                    <div class="info-value"><?php echo $sem['academic_year']; ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Section</div>
                                    <div class="info-value"><?php echo $sem['section_name'] ?? 'N/A'; ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Subjects</div>
                                    <div class="info-value"><?php echo $sem['total_subjects']; ?></div>
                                </div>
                            </div>
                            
                            <div style="margin-top: 15px; text-align: right;">
                                <button class="view-details-btn" id="btn-<?php echo $sem['id']; ?>">View Details</button>
                            </div>
                            
                            <div class="semester-details" id="details-<?php echo $sem['id']; ?>">
                                <h4 style="margin-bottom: 15px; color: var(--primary);">Enrolled Subjects</h4>
                                <div class="subject-list">
                                    <p style="text-align: center; color: var(--gray); padding: 20px;">Loading subjects...</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
                <?php else: ?>
                <p style="text-align: center; color: var(--gray); padding: 40px;">No semester history available.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
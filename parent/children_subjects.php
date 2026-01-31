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

// Get student info and current subjects
$student_info = null;
$current_subjects = null;

if ($selected_student_id) {
    $student_info = $conn->query("SELECT s.*, d.department_name, c.course_name,
                                  sem.semester_name, sec.section_name
                                  FROM students s
                                  JOIN departments d ON s.department_id = d.department_id
                                  JOIN courses c ON s.course_id = c.course_id
                                  LEFT JOIN student_semesters ss ON s.student_id = ss.student_id AND ss.is_active = 1
                                  LEFT JOIN semesters sem ON ss.semester_id = sem.semester_id
                                  LEFT JOIN sections sec ON ss.section_id = sec.section_id
                                  WHERE s.student_id = $selected_student_id")->fetch_assoc();
    
    // Get current semester subjects
    $current_subjects = $conn->query("SELECT 
        sub.subject_id,
        sub.subject_name,
        sub.subject_code,
        sub.credits,
        t.full_name as teacher_name,
        t.designation,
        u.email as teacher_email,
        u.phone as teacher_phone
        FROM subjects sub
        JOIN student_semesters ss ON ss.student_id = $selected_student_id AND ss.is_active = 1
        LEFT JOIN subject_teachers st ON sub.subject_id = st.subject_id 
            AND st.semester_id = ss.semester_id 
            AND st.section_id = ss.section_id
        LEFT JOIN teachers t ON st.teacher_id = t.teacher_id
        LEFT JOIN users u ON t.user_id = u.user_id
        WHERE sub.department_id = {$student_info['department_id']} 
        AND sub.semester_id = ss.semester_id
        ORDER BY sub.subject_code");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subjects - Parent Portal</title>
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

        @keyframes shimmer {
            0% {
                background-position: -1000px 0;
            }
            100% {
                background-position: 1000px 0;
            }
        }

        @keyframes bounce {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-10px);
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

        .subjects-grid {
            display: grid;
            gap: 20px;
        }

        .subject-card {
            background: linear-gradient(135deg, var(--light-gray), #e2e8f0);
            padding: 25px;
            border-radius: 15px;
            border-left: 5px solid var(--primary);
            transition: all 0.3s;
            animation: fadeIn 0.9s ease;
        }

        .subject-card:hover {
            transform: translateX(5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
            background: linear-gradient(135deg, #e2e8f0, #cbd5e1);
        }

        .subject-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 20px;
        }

        .subject-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
            transition: all 0.3s;
        }

        .subject-card:hover .subject-title {
            color: var(--primary);
        }

        .subject-code {
            color: var(--gray);
            font-size: 0.9rem;
            font-weight: 600;
        }

        .credits-badge {
            background: var(--primary);
            color: var(--white);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 700;
            transition: all 0.3s;
        }

        .credits-badge:hover {
            transform: scale(1.05);
            background: var(--secondary);
        }

        .teacher-info {
            background: var(--white);
            padding: 15px;
            border-radius: 10px;
            margin-top: 15px;
            transition: all 0.3s;
        }

        .teacher-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .teacher-name {
            font-size: 1rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .teacher-name i {
            color: var(--primary);
            margin-right: 8px;
        }

        .teacher-designation {
            color: var(--gray);
            font-size: 0.85rem;
            margin-bottom: 10px;
        }

        .teacher-contact {
            display: flex;
            gap: 15px;
            font-size: 0.85rem;
            color: var(--gray);
            flex-wrap: wrap;
        }

        .teacher-contact i {
            margin-right: 5px;
            color: var(--primary);
            transition: all 0.3s;
        }

        .teacher-contact div:hover i {
            transform: scale(1.2);
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

        .semester-info-box {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-around;
            align-items: center;
            animation: fadeIn 0.7s ease;
            transition: all 0.3s;
        }

        .semester-info-box:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(99, 102, 241, 0.4);
        }

        .info-box-item {
            text-align: center;
            transition: all 0.3s;
        }

        .info-box-item:hover {
            transform: scale(1.05);
        }

        .info-box-label {
            font-size: 0.85rem;
            opacity: 0.9;
            margin-bottom: 5px;
        }

        .info-box-value {
            font-size: 1.5rem;
            font-weight: 800;
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

            .semester-info-box {
                flex-wrap: wrap;
                gap: 15px;
            }

            .info-box-item {
                flex: 1;
                min-width: 150px;
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

            .semester-info-box {
                flex-direction: column;
                gap: 15px;
                padding: 20px 15px;
            }

            .info-box-item {
                width: 100%;
            }

            .info-box-value {
                font-size: 1.3rem;
            }

            .subject-card {
                padding: 20px;
            }

            .subject-header {
                flex-direction: column;
                gap: 10px;
            }

            .subject-title {
                font-size: 1.1rem;
            }

            .teacher-contact {
                flex-direction: column;
                gap: 8px;
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

            .semester-info-box {
                padding: 15px;
            }

            .info-box-label {
                font-size: 0.75rem;
            }

            .info-box-value {
                font-size: 1.2rem;
            }

            .subject-card {
                padding: 15px;
            }

            .subject-title {
                font-size: 1rem;
            }

            .subject-code {
                font-size: 0.8rem;
            }

            .credits-badge {
                font-size: 0.75rem;
                padding: 4px 10px;
            }

            .teacher-info {
                padding: 12px;
            }

            .teacher-name {
                font-size: 0.9rem;
            }

            .teacher-designation {
                font-size: 0.8rem;
            }

            .teacher-contact {
                font-size: 0.8rem;
            }
        }
    </style>
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
                <a href="semester_history.php" class="menu-item">
                    <i class="fas fa-history"></i> Semester History
                </a>
                <a href="children_subjects.php" class="menu-item active">
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
                <h1>Current Subjects</h1>
                <a href="index.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            </div>

            <?php if ($students->num_rows > 1): ?>
            <div class="student-selector">
                <select onchange="window.location.href='children_subjects.php?student_id=' + this.value">
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
                
                <div class="semester-info-box">
                    <div class="info-box-item">
                        <div class="info-box-label">Current Semester</div>
                        <div class="info-box-value"><?php echo $student_info['semester_name'] ?? 'N/A'; ?></div>
                    </div>
                    <div class="info-box-item">
                        <div class="info-box-label">Section</div>
                        <div class="info-box-value"><?php echo $student_info['section_name'] ?? 'N/A'; ?></div>
                    </div>
                    <div class="info-box-item">
                        <div class="info-box-label">Total Subjects</div>
                        <div class="info-box-value"><?php echo $current_subjects ? $current_subjects->num_rows : 0; ?></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <i class="fas fa-book-open"></i>
                    <h3>Enrolled Subjects</h3>
                </div>
                
                <?php if ($current_subjects && $current_subjects->num_rows > 0): ?>
                <div class="subjects-grid">
                    <?php while($subject = $current_subjects->fetch_assoc()): ?>
                    <div class="subject-card">
                        <div class="subject-header">
                            <div>
                                <div class="subject-title"><?php echo $subject['subject_name']; ?></div>
                                <div class="subject-code"><?php echo $subject['subject_code']; ?></div>
                            </div>
                            <div class="credits-badge">
                                <?php echo $subject['credits']; ?> Credits
                            </div>
                        </div>
                        
                        <?php if ($subject['teacher_name']): ?>
                        <div class="teacher-info">
                            <div class="teacher-name">
                                <i class="fas fa-chalkboard-teacher"></i> <?php echo $subject['teacher_name']; ?>
                            </div>
                            <div class="teacher-designation"><?php echo $subject['designation'] ?? 'Faculty'; ?></div>
                            <div class="teacher-contact">
                                <?php if ($subject['teacher_email']): ?>
                                <div><i class="fas fa-envelope"></i> <?php echo $subject['teacher_email']; ?></div>
                                <?php endif; ?>
                                <?php if ($subject['teacher_phone']): ?>
                                <div><i class="fas fa-phone"></i> <?php echo $subject['teacher_phone']; ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="teacher-info">
                            <div class="teacher-name" style="color: var(--gray);">
                                <i class="fas fa-info-circle"></i> Teacher Not Assigned
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endwhile; ?>
                </div>
                <?php else: ?>
                <p style="text-align: center; color: var(--gray); padding: 40px;">No subjects enrolled for current semester.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
        }

        // Close sidebar when clicking on menu items on mobile
        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    toggleSidebar();
                }
            });
        });
    </script>
</body>
</html>
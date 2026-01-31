<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('admin')) {
    redirect('login.php');
}

// Get statistics
$stats = [];

// Total Students
$result = $conn->query("SELECT COUNT(*) as count FROM students");
$stats['students'] = $result->fetch_assoc()['count'];

// Total Teachers
$result = $conn->query("SELECT COUNT(*) as count FROM teachers");
$stats['teachers'] = $result->fetch_assoc()['count'];

// Total Departments
$result = $conn->query("SELECT COUNT(*) as count FROM departments");
$stats['departments'] = $result->fetch_assoc()['count'];

// Total Courses
$result = $conn->query("SELECT COUNT(*) as count FROM courses");
$stats['courses'] = $result->fetch_assoc()['count'];

// Recent registrations
$recent_students = $conn->query("SELECT s.full_name, s.admission_number, d.department_name, s.created_at 
                                 FROM students s 
                                 JOIN departments d ON s.department_id = d.department_id 
                                 ORDER BY s.created_at DESC LIMIT 5");

// Department-wise student count
$dept_stats = $conn->query("SELECT d.department_name, COUNT(s.student_id) as student_count 
                           FROM departments d 
                           LEFT JOIN students s ON d.department_id = s.department_id 
                           GROUP BY d.department_id");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - College ERP</title>
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

        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
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

        /* Sidebar */
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
            animation: fadeIn 0.6s ease;
        }

        .sidebar-header p {
            font-size: 0.85rem;
            opacity: 0.7;
            margin-top: 5px;
            animation: fadeIn 0.8s ease;
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

        /* Main Content */
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
            animation: slideInRight 0.5s ease;
            flex-wrap: wrap;
            gap: 15px;
        }

        .top-bar h1 {
            font-size: 1.8rem;
            font-weight: 700;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: var(--primary);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .user-avatar:hover {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }

        .logout-btn {
            background: var(--danger);
            color: var(--white);
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .logout-btn:hover {
            background: #dc2626;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }

        .logout-btn:active {
            transform: translateY(0);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--white);
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.3s ease;
            cursor: pointer;
            animation: scaleIn 0.5s ease;
            animation-fill-mode: backwards;
        }

        .stat-card:nth-child(1) { animation-delay: 0.1s; }
        .stat-card:nth-child(2) { animation-delay: 0.2s; }
        .stat-card:nth-child(3) { animation-delay: 0.3s; }
        .stat-card:nth-child(4) { animation-delay: 0.4s; }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
        }

        .stat-info h3 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
            transition: all 0.3s ease;
        }

        .stat-card:hover .stat-info h3 {
            animation: pulse 0.6s ease;
        }

        .stat-info p {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            transition: all 0.3s ease;
        }

        .stat-card:hover .stat-icon {
            transform: rotate(10deg) scale(1.1);
        }

        .stat-icon.blue { background: rgba(37, 99, 235, 0.1); color: var(--primary); }
        .stat-icon.purple { background: rgba(139, 92, 246, 0.1); color: var(--secondary); }
        .stat-icon.green { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .stat-icon.orange { background: rgba(245, 158, 11, 0.1); color: var(--warning); }

        /* Content Sections */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
        }

        .card {
            background: var(--white);
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            animation: fadeIn 0.6s ease;
        }

        .card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .card-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--light-gray);
        }

        .card-header h3 {
            font-size: 1.2rem;
            font-weight: 600;
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
            transition: background 0.3s ease;
        }

        .table tr {
            transition: all 0.3s ease;
        }

        .table tr:hover {
            background: var(--light-gray);
            transform: scale(1.01);
        }

        .badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
            transition: all 0.3s ease;
        }

        .badge:hover {
            transform: scale(1.1);
        }

        .badge.success { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .badge.info { background: rgba(37, 99, 235, 0.1); color: var(--primary); }

        .dept-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid var(--light-gray);
            transition: all 0.3s ease;
        }

        .dept-item:hover {
            padding-left: 10px;
            background: var(--light-gray);
            margin: 0 -10px;
            padding-right: 10px;
            border-radius: 8px;
        }

        .dept-item:last-child {
            border-bottom: none;
        }

        .dept-name {
            font-weight: 500;
        }

        .dept-count {
            background: var(--light-gray);
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .dept-item:hover .dept-count {
            background: var(--primary);
            color: var(--white);
            transform: scale(1.1);
        }

        /* Responsive Design - Tablet */
        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .top-bar h1 {
                font-size: 1.5rem;
            }
        }

        /* Responsive Design - Mobile */
        @media (max-width: 768px) {
            :root {
                --sidebar-width: 260px;
            }

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
                width: 100%;
            }

            .user-info {
                width: 100%;
                justify-content: space-between;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .stat-card {
                padding: 20px;
            }

            .stat-info h3 {
                font-size: 1.5rem;
            }

            .stat-icon {
                width: 50px;
                height: 50px;
                font-size: 1.2rem;
            }

            .card {
                padding: 15px;
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

            .sidebar-header {
                padding: 20px;
            }

            .sidebar-header h2 {
                font-size: 1.3rem;
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

            .user-info {
                font-size: 0.85rem;
            }

            .user-avatar {
                width: 35px;
                height: 35px;
                font-size: 0.9rem;
            }

            .logout-btn {
                padding: 8px 15px;
                font-size: 0.85rem;
            }

            .stat-card {
                padding: 15px;
            }

            .stat-info h3 {
                font-size: 1.3rem;
            }

            .stat-info p {
                font-size: 0.8rem;
            }

            .stat-icon {
                width: 40px;
                height: 40px;
                font-size: 1rem;
            }

            .card-header h3 {
                font-size: 1rem;
            }

            .menu-item {
                padding: 12px 15px;
                font-size: 0.9rem;
            }

            .dept-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .dept-count {
                align-self: flex-end;
            }
        }

        /* Landscape orientation */
        @media (max-height: 500px) and (orientation: landscape) {
            .sidebar {
                overflow-y: scroll;
            }

            .main-content {
                padding-top: 70px;
            }
        }

        /* Overlay for mobile menu */
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

        @media (max-width: 768px) {
            .sidebar-overlay {
                display: none;
            }

            .sidebar-overlay.active {
                display: block;
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
                <a href="index.php" class="menu-item active" onclick="closeSidebarMobile()">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                  <a href="dailyattandance.php" class="menu-item " onclick="closeSidebarMobile()">
                    <i class="fas fa-home"></i> Daily Report
                </a>  
                  <a href="consolidatereport.php" class="menu-item " onclick="closeSidebarMobile()">
                    <i class="fas fa-home"></i>Consolidated Report
                </a>
                    <a href="attandancereview.php" class="menu-item " onclick="closeSidebarMobile()">
                    <i class="fas fa-home"></i>Attendance Review
                </a> <a href="admin_class_attendance_report.php" class="menu-item " onclick="closeSidebarMobile()">
                    <i class="fas fa-home"></i>teacher Attendance
                </a>
                <a href="manage_students.php" class="menu-item" onclick="closeSidebarMobile()">
                    <i class="fas fa-user-graduate"></i> Students
                </a>
                <a href="manage_teachers.php" class="menu-item" onclick="closeSidebarMobile()">
                    <i class="fas fa-chalkboard-teacher"></i> Teachers
                </a>
                <a href="manage_departments.php" class="menu-item" onclick="closeSidebarMobile()">
                    <i class="fas fa-building"></i> Departments
                </a>
                <a href="manage_hod.php" class="menu-item" onclick="closeSidebarMobile()">
                    <i class="fas fa-user-tie"></i> HOD
                </a>
                <a href="manage_parent.php" class="menu-item" onclick="closeSidebarMobile()">
                    <i class="fas fa-users"></i> Parent
                </a>
                <a href="classes.php" class="menu-item" onclick="closeSidebarMobile()">
                    <i class="fas fa-door-open"></i> Classes
                </a>
                <a href="manage_courses.php" class="menu-item" onclick="closeSidebarMobile()">
                    <i class="fas fa-book"></i> Courses
                </a>
                <a href="manage_subjects.php" class="menu-item" onclick="closeSidebarMobile()">
                    <i class="fas fa-list"></i> Subjects
                </a>
                <a href="reports.php" class="menu-item" onclick="closeSidebarMobile()">
                    <i class="fas fa-chart-bar"></i> Reports
                </a>
                <a href="settings.php" class="menu-item" onclick="closeSidebarMobile()">
                    <i class="fas fa-cog"></i> Settings
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-bar">
                <h1>Dashboard Overview</h1>
                <div class="user-info">
                    <div class="user-avatar">A</div>
                    <div>
                        <strong>Administrator</strong>
                        <p style="font-size: 0.85rem; color: var(--gray);">admin@college.edu</p>
                    </div>
                    <a href="../logout.php"><button class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</button></a>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-info">
                        <h3><?php echo $stats['students']; ?></h3>
                        <p>Total Students</p>
                    </div>
                    <div class="stat-icon blue">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3><?php echo $stats['teachers']; ?></h3>
                        <p>Total Teachers</p>
                    </div>
                    <div class="stat-icon purple">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3><?php echo $stats['departments']; ?></h3>
                        <p>Departments</p>
                    </div>
                    <div class="stat-icon green">
                        <i class="fas fa-building"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3><?php echo $stats['courses']; ?></h3>
                        <p>Courses</p>
                    </div>
                    <div class="stat-icon orange">
                        <i class="fas fa-book"></i>
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <div class="card">
                    <div class="card-header">
                        <h3>Recent Student Registrations</h3>
                    </div>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Admission No.</th>
                                    <th>Name</th>
                                    <th>Department</th>
                                    <th>Registered On</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($student = $recent_students->fetch_assoc()): ?>
                                <tr>
                                    <td><span class="badge info"><?php echo $student['admission_number']; ?></span></td>
                                    <td><?php echo $student['full_name']; ?></td>
                                    <td><?php echo $student['department_name']; ?></td>
                                    <td><?php echo date('M d, Y', strtotime($student['created_at'])); ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>Department Statistics</h3>
                    </div>
                    <?php while($dept = $dept_stats->fetch_assoc()): ?>
                    <div class="dept-item">
                        <span class="dept-name"><?php echo $dept['department_name']; ?></span>
                        <span class="dept-count"><?php echo $dept['student_count']; ?> Students</span>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </main>
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
    </script>
</body>
</html>
<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('hod')) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

// Get HOD details and department
$sql = "SELECT t.*, d.department_name, d.department_id 
        FROM teachers t 
        JOIN departments d ON d.hod_id = t.user_id
        WHERE t.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$hod = $stmt->get_result()->fetch_assoc();

if (!$hod) {
    die("HOD profile not found or not assigned to any department.");
}

$dept_id = $hod['department_id'];

// Get report type
$report_type = isset($_GET['report']) ? $_GET['report'] : 'overview';

// Department Overview Stats
$total_students = $conn->query("SELECT COUNT(*) as count FROM students WHERE department_id = $dept_id")->fetch_assoc()['count'];
$total_teachers = $conn->query("SELECT COUNT(*) as count FROM teachers WHERE department_id = $dept_id")->fetch_assoc()['count'];
$total_subjects = $conn->query("SELECT COUNT(*) as count FROM subjects WHERE department_id = $dept_id")->fetch_assoc()['count'];

// Semester-wise student distribution
$semester_dist = $conn->query("SELECT sem.semester_name, COUNT(ss.student_id) as student_count
                              FROM semesters sem
                              LEFT JOIN student_semesters ss ON sem.semester_id = ss.semester_id
                              LEFT JOIN students s ON ss.student_id = s.student_id
                              WHERE (s.department_id = $dept_id OR s.department_id IS NULL)
                              AND (ss.is_active = 1 OR ss.is_active IS NULL)
                              GROUP BY sem.semester_id
                              ORDER BY sem.semester_number");

// Subject-wise attendance
$subject_attendance = $conn->query("SELECT 
                                    sub.subject_name,
                                    sub.subject_code,
                                    sem.semester_name,
                                    COUNT(a.attendance_id) as total_classes,
                                    SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                                    ROUND(SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / COUNT(a.attendance_id) * 100, 2) as attendance_percentage
                                    FROM subjects sub
                                    JOIN semesters sem ON sub.semester_id = sem.semester_id
                                    LEFT JOIN attendance a ON sub.subject_id = a.subject_id
                                    WHERE sub.department_id = $dept_id
                                    GROUP BY sub.subject_id
                                    HAVING total_classes > 0
                                    ORDER BY attendance_percentage ASC");

// Student attendance summary
$student_attendance = $conn->query("SELECT 
                                    s.student_id,
                                    s.full_name,
                                    s.admission_number,
                                    COUNT(a.attendance_id) as total_classes,
                                    SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                                    ROUND(SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / COUNT(a.attendance_id) * 100, 2) as attendance_percentage
                                    FROM students s
                                    LEFT JOIN attendance a ON s.student_id = a.student_id
                                    WHERE s.department_id = $dept_id
                                    GROUP BY s.student_id
                                    HAVING total_classes > 0
                                    ORDER BY attendance_percentage ASC");

// Teacher workload report
$teacher_workload = $conn->query("SELECT 
                                  t.full_name,
                                  t.designation,
                                  COUNT(DISTINCT st.subject_id) as subjects_count,
                                  COUNT(DISTINCT st.section_id) as sections_count,
                                  GROUP_CONCAT(DISTINCT sem.semester_name ORDER BY sem.semester_number SEPARATOR ', ') as semesters
                                  FROM teachers t
                                  LEFT JOIN subject_teachers st ON t.teacher_id = st.teacher_id
                                  LEFT JOIN semesters sem ON st.semester_id = sem.semester_id
                                  WHERE t.department_id = $dept_id
                                  GROUP BY t.teacher_id
                                  ORDER BY subjects_count DESC");

// Monthly attendance trend (last 6 months)
$monthly_trend = $conn->query("SELECT 
                               DATE_FORMAT(attendance_date, '%Y-%m') as month,
                               COUNT(*) as total_records,
                               SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
                               ROUND(SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) / COUNT(*) * 100, 2) as attendance_rate
                               FROM attendance
                               WHERE department_id = $dept_id
                               AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                               GROUP BY DATE_FORMAT(attendance_date, '%Y-%m')
                               ORDER BY month DESC");

// Section-wise statistics
$section_stats = $conn->query("SELECT 
                               sec.section_name,
                               sem.semester_name,
                               COUNT(DISTINCT ss.student_id) as student_count,
                               COUNT(DISTINCT st.subject_id) as subject_count,
                               COUNT(DISTINCT st.teacher_id) as teacher_count
                               FROM sections sec
                               LEFT JOIN student_semesters ss ON sec.section_id = ss.section_id
                               LEFT JOIN students s ON ss.student_id = s.student_id
                               LEFT JOIN semesters sem ON ss.semester_id = sem.semester_id
                               LEFT JOIN subject_teachers st ON sec.section_id = st.section_id AND sem.semester_id = st.semester_id
                               WHERE (s.department_id = $dept_id OR s.department_id IS NULL)
                               AND (ss.is_active = 1 OR ss.is_active IS NULL)
                               GROUP BY sec.section_id, sem.semester_id
                               HAVING student_count > 0
                               ORDER BY sem.semester_name, sec.section_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Reports - College ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #f97316;
            --secondary: #ea580c;
            --success: #22c55e;
            --warning: #eab308;
            --danger: #ef4444;
            --dark: #0c0a09;
            --gray: #78716c;
            --light-gray: #fafaf9;
            --white: #ffffff;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background: var(--light-gray);
            color: var(--dark);
        }

        .dashboard {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, var(--dark) 0%, #292524 100%);
            color: var(--white);
            padding: 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 30px 25px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
        }

        .hod-profile {
            text-align: center;
        }

        .hod-avatar {
            width: 75px;
            height: 75px;
            border-radius: 15px;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 700;
            margin: 0 auto 15px;
            border: 3px solid rgba(255,255,255,0.3);
        }

        .hod-name {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .hod-dept {
            font-size: 0.9rem;
            opacity: 0.9;
            font-weight: 500;
        }

        .hod-role {
            font-size: 0.8rem;
            opacity: 0.8;
            margin-top: 3px;
        }

        .sidebar-menu {
            padding: 25px 0;
        }

        .menu-item {
            padding: 16px 25px;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            display: flex;
            align-items: center;
            transition: all 0.3s;
            cursor: pointer;
            position: relative;
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

        .menu-item:hover::before, .menu-item.active::before {
            transform: scaleY(1);
        }

        .menu-item:hover, .menu-item.active {
            background: rgba(249, 115, 22, 0.1);
            color: var(--white);
        }

        .menu-item i {
            margin-right: 15px;
            width: 22px;
            text-align: center;
            font-size: 1.1rem;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
        }

        .top-bar {
            background: var(--white);
            padding: 25px 35px;
            border-radius: 20px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .top-bar-left h1 {
            font-size: 2.2rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 5px;
        }

        .top-bar-left p {
            color: var(--gray);
            font-size: 0.95rem;
        }

        .back-btn {
            background: linear-gradient(135deg, var(--gray), #57534e);
            color: var(--white);
            border: none;
            padding: 12px 28px;
            border-radius: 15px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-family: 'Outfit', sans-serif;
            box-shadow: 0 4px 15px rgba(120, 113, 108, 0.3);
            text-decoration: none;
            display: inline-block;
        }

        .back-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 25px rgba(120, 113, 108, 0.4);
        }

        /* Report Navigation */
        .report-nav {
            background: var(--white);
            padding: 20px;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .report-tabs {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .report-tab {
            padding: 12px 24px;
            border-radius: 12px;
            background: var(--light-gray);
            color: var(--gray);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .report-tab:hover {
            background: rgba(249, 115, 22, 0.1);
            color: var(--primary);
        }

        .report-tab.active {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--white);
            padding: 25px;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .stat-number.primary { color: var(--primary); }
        .stat-number.success { color: var(--success); }
        .stat-number.warning { color: var(--warning); }
        .stat-number.danger { color: var(--danger); }

        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Card */
        .card {
            background: var(--white);
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--light-gray);
        }

        .card-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            display: flex;
            align-items: center;
        }

        .card-header h2 i {
            color: var(--primary);
            margin-right: 12px;
        }

        /* Table */
        .table-responsive {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table thead {
            background: linear-gradient(135deg, rgba(249, 115, 22, 0.1), rgba(234, 88, 12, 0.1));
        }

        .table th {
            text-align: left;
            padding: 15px 12px;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.8rem;
            text-transform: uppercase;
        }

        .table td {
            padding: 15px 12px;
            border-bottom: 1px solid var(--light-gray);
        }

        .table tbody tr:hover {
            background: rgba(249, 115, 22, 0.03);
        }

        .badge {
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-success {
            background: rgba(34, 197, 94, 0.2);
            color: var(--success);
        }

        .badge-danger {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
        }

        .badge-warning {
            background: rgba(234, 179, 8, 0.2);
            color: var(--warning);
        }

        .badge-info {
            background: rgba(59, 130, 246, 0.2);
            color: #3b82f6;
        }

        .badge-primary {
            background: rgba(249, 115, 22, 0.2);
            color: var(--primary);
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--light-gray);
            border-radius: 10px;
            overflow: hidden;
            margin-top: 8px;
        }

        .progress-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.3s;
        }

        .progress-success { background: var(--success); }
        .progress-warning { background: var(--warning); }
        .progress-danger { background: var(--danger); }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 4rem;
            color: #e7e5e4;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 10px;
            color: var(--dark);
        }

        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 25px;
        }

        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        .btn-print {
            background: linear-gradient(135deg, var(--success), #16a34a);
            color: var(--white);
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-print:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(34, 197, 94, 0.4);
        }

        @media print {
            .sidebar, .report-nav, .back-btn, .btn-print {
                display: none !important;
            }
            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="hod-profile">
                    <div class="hod-avatar"><?php echo strtoupper(substr($hod['full_name'], 0, 1)); ?></div>
                    <div class="hod-name"><?php echo $hod['full_name']; ?></div>
                    <div class="hod-dept"><?php echo $hod['department_name']; ?></div>
                    <div class="hod-role">Head of Department</div>
                </div>
            </div>
                <nav class="sidebar-menu">
                <a href="index.php" class="menu-item active">
                    <div class="menu-icon">
                        <i class="fas fa-home"></i>
                    </div>
                    <span class="menu-text">Dashboard</span>
                    <div class="menu-indicator"></div>
                </a>
                <a href="manage_student_semesters.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <span class="menu-text">Students</span>
                    <div class="menu-indicator"></div>
                </a>
                <a href="attandancereview.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <span class="menu-text">Attendance Review</span>
                    <div class="menu-indicator"></div>
                </a>
                <a href="consolidatereport.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <span class="menu-text">Consolidated Report</span>
                    <div class="menu-indicator"></div>
                </a>
                <a href="sections.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-layer-group"></i>
                    </div>
                    <span class="menu-text">Sections</span>
                    <div class="menu-indicator"></div>
                </a>
                <a href="hod_classes.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-chalkboard"></i>
                    </div>
                    <span class="menu-text">Classes</span>
                    <div class="menu-indicator"></div>
                </a>
                <a href="manage_class_teachers.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <span class="menu-text">Class Teachers</span>
                    <div class="menu-indicator"></div>
                </a>
                <a href="manage_substitutes.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-exchange-alt"></i>
                    </div>
                    <span class="menu-text">Substitutes</span>
                    <div class="menu-indicator"></div>
                </a>
                <a href="dept_subjects.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-book"></i>
                    </div>
                    <span class="menu-text">Subjects</span>
                    <div class="menu-indicator"></div>
                </a>
                <a href="dept_subjects_teacher.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-book-reader"></i>
                    </div>
                    <span class="menu-text">Subject Teachers</span>
                    <div class="menu-indicator"></div>
                </a>
                <a href="dept_attendance.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <span class="menu-text">Attendance</span>
                    <div class="menu-indicator"></div>
                </a>
                <a href="dept_reports.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <span class="menu-text">Reports</span>
                    <div class="menu-indicator"></div>
                </a>
                <a href="hod_profile.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-user"></i>
                    </div>
                    <span class="menu-text">Profile</span>
                    <div class="menu-indicator"></div>
                </a>
                <a href="hod_setting.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-cog"></i>
                    </div>
                    <span class="menu-text">Settings</span>
                    <div class="menu-indicator"></div>
                </a>
            </nav>

        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-bar">
                <div class="top-bar-left">
                    <h1>Department Reports</h1>
                    <p>Comprehensive analytics for <?php echo $hod['department_name']; ?></p>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button onclick="window.print()" class="btn-print">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <a href="hod_dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back</a>
                </div>
            </div>

            <!-- Report Navigation -->
            <div class="report-nav">
                <div class="report-tabs">
                    <a href="?report=overview" class="report-tab <?php echo $report_type == 'overview' ? 'active' : ''; ?>">
                        <i class="fas fa-chart-pie"></i> Overview
                    </a>
                    <a href="?report=attendance" class="report-tab <?php echo $report_type == 'attendance' ? 'active' : ''; ?>">
                        <i class="fas fa-calendar-check"></i> Attendance
                    </a>
                    <a href="?report=students" class="report-tab <?php echo $report_type == 'students' ? 'active' : ''; ?>">
                        <i class="fas fa-user-graduate"></i> Students
                    </a>
                    <a href="?report=teachers" class="report-tab <?php echo $report_type == 'teachers' ? 'active' : ''; ?>">
                        <i class="fas fa-chalkboard-teacher"></i> Teachers
                    </a>
                </div>
            </div>

            <?php if ($report_type == 'overview'): ?>
                <!-- Overview Report -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number primary"><?php echo $total_students; ?></div>
                        <div class="stat-label">Total Students</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number success"><?php echo $total_teachers; ?></div>
                        <div class="stat-label">Faculty Members</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number warning"><?php echo $total_subjects; ?></div>
                        <div class="stat-label">Total Subjects</div>
                    </div>
                </div>

                <div class="content-grid">
                    <!-- Semester Distribution -->
                    <div class="card">
                        <div class="card-header">
                            <h2><i class="fas fa-chart-bar"></i> Student Distribution</h2>
                        </div>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Semester</th>
                                        <th>Students</th>
                                        <th>Distribution</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($sem = $semester_dist->fetch_assoc()): ?>
                                        <tr>
                                            <td><strong><?php echo $sem['semester_name']; ?></strong></td>
                                            <td><span class="badge badge-primary"><?php echo $sem['student_count']; ?> Students</span></td>
                                            <td>
                                                <?php 
                                                $percentage = $total_students > 0 ? ($sem['student_count'] / $total_students * 100) : 0;
                                                ?>
                                                <div class="progress-bar">
                                                    <div class="progress-fill progress-success" style="width: <?php echo $percentage; ?>%"></div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Section Statistics -->
                    <div class="card">
                        <div class="card-header">
                            <h2><i class="fas fa-layer-group"></i> Section Statistics</h2>
                        </div>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Semester</th>
                                        <th>Section</th>
                                        <th>Students</th>
                                        <th>Subjects</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    if ($section_stats->num_rows > 0):
                                        while($sec = $section_stats->fetch_assoc()): 
                                    ?>
                                        <tr>
                                            <td><?php echo $sec['semester_name']; ?></td>
                                            <td><span class="badge badge-info">Section <?php echo $sec['section_name']; ?></span></td>
                                            <td><?php echo $sec['student_count']; ?></td>
                                            <td><?php echo $sec['subject_count']; ?></td>
                                        </tr>
                                    <?php 
                                        endwhile;
                                    else:
                                    ?>
                                        <tr>
                                            <td colspan="4" style="text-align: center; color: var(--gray);">No section data available</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <?php elseif ($report_type == 'attendance'): ?>
                <!-- Attendance Report -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-chart-line"></i> Monthly Attendance Trend</h2>
                    </div>
                    <div class="table-responsive">
                        <?php if ($monthly_trend->num_rows > 0): ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th>Total Records</th>
                                        <th>Present</th>
                                        <th>Attendance Rate</th>
                                        <th>Trend</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($trend = $monthly_trend->fetch_assoc()): ?>
                                        <tr>
                                            <td><strong><?php echo date('F Y', strtotime($trend['month'] . '-01')); ?></strong></td>
                                            <td><?php echo $trend['total_records']; ?></td>
                                            <td><?php echo $trend['present_count']; ?></td>
                                            <td>
                                                <?php 
                                                $rate = $trend['attendance_rate'];
                                                $badge_class = $rate >= 75 ? 'badge-success' : ($rate >= 60 ? 'badge-warning' : 'badge-danger');
                                                ?>
                                                <span class="badge <?php echo $badge_class; ?>"><?php echo $rate; ?>%</span>
                                            </td>
                                            <td>
                                                <div class="progress-bar">
                                                    <?php 
                                                    $progress_class = $rate >= 75 ? 'progress-success' : ($rate >= 60 ? 'progress-warning' : 'progress-danger');
                                                    ?>
                                                    <div class="progress-fill <?php echo $progress_class; ?>" style="width: <?php echo $rate; ?>%"></div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-times"></i>
                                <h3>No Attendance Data</h3>
                                <p>No attendance records found for the last 6 months.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-book-open"></i> Subject-wise Attendance</h2>
                    </div>
                    <div class="table-responsive">
                        <?php if ($subject_attendance->num_rows > 0): ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Subject</th>
                                        <th>Code</th>
                                        <th>Semester</th>
                                        <th>Total Classes</th>
                                        <th>Present</th>
                                        <th>Attendance %</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($sub = $subject_attendance->fetch_assoc()): ?>
                                        <tr>
                                            <td><strong><?php echo $sub['subject_name']; ?></strong></td>
                                            <td><span class="badge badge-info"><?php echo $sub['subject_code']; ?></span></td>
                                            <td><?php echo $sub['semester_name']; ?></td>
                                            <td><?php echo $sub['total_classes']; ?></td>
                                            <td><?php echo $sub['present_count']; ?></td>
                                            <td>
                                                <?php 
                                                $percentage = $sub['attendance_percentage'];
                                                $badge_class = $percentage >= 75 ? 'badge-success' : ($percentage >= 60 ? 'badge-warning' : 'badge-danger');
                                                ?>
                                                <span class="badge <?php echo $badge_class; ?>"><?php echo $percentage; ?>%</span>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-book"></i>
                                <h3>No Subject Data</h3>
                                <p>No attendance data available for subjects.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($report_type == 'students'): ?>
                <!-- Student Report -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-graduation-cap"></i> Student Attendance Report</h2>
                    </div>
                    <div class="table-responsive">
                        <?php if ($student_attendance->num_rows > 0): ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Admission No.</th>
                                        <th>Student Name</th>
                                        <th>Total Classes</th>
                                        <th>Present</th>
                                        <th>Attendance %</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($student = $student_attendance->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo $student['admission_number']; ?></td>
                                            <td><strong><?php echo $student['full_name']; ?></strong></td>
                                            <td><?php echo $student['total_classes']; ?></td>
                                            <td><?php echo $student['present_count']; ?></td>
                                            <td>
                                                <?php 
                                                $percentage = $student['attendance_percentage'];
                                                $badge_class = $percentage >= 75 ? 'badge-success' : ($percentage >= 60 ? 'badge-warning' : 'badge-danger');
                                                ?>
                                                <span class="badge <?php echo $badge_class; ?>"><?php echo $percentage; ?>%</span>
                                            </td>
                                            <td>
                                                <?php if ($percentage >= 75): ?>
                                                    <span class="badge badge-success"><i class="fas fa-check"></i> Good</span>
                                                <?php elseif ($percentage >= 60): ?>
                                                    <span class="badge badge-warning"><i class="fas fa-exclamation"></i> Average</span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger"><i class="fas fa-times"></i> Poor</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-user-graduate"></i>
                                <h3>No Student Data</h3>
                                <p>No student attendance records available.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($report_type == 'teachers'): ?>
                <!-- Teacher Report -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-users"></i> Faculty Workload Report</h2>
                    </div>
                    <div class="table-responsive">
                        <?php if ($teacher_workload->num_rows > 0): ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Faculty Name</th>
                                        <th>Designation</th>
                                        <th>Subjects Assigned</th>
                                        <th>Sections</th>
                                        <th>Semesters</th>
                                        <th>Workload</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($teacher = $teacher_workload->fetch_assoc()): ?>
                                        <tr>
                                            <td><strong><?php echo $teacher['full_name']; ?></strong></td>
                                            <td><?php echo $teacher['designation'] ?? 'N/A'; ?></td>
                                            <td><span class="badge badge-primary"><?php echo $teacher['subjects_count']; ?> Subjects</span></td>
                                            <td><span class="badge badge-info"><?php echo $teacher['sections_count']; ?> Sections</span></td>
                                            <td><?php echo $teacher['semesters'] ?? 'N/A'; ?></td>
                                            <td>
                                                <?php 
                                                $workload = $teacher['subjects_count'];
                                                if ($workload == 0) {
                                                    echo '<span class="badge badge-danger">No Load</span>';
                                                } elseif ($workload <= 2) {
                                                    echo '<span class="badge badge-success">Light</span>';
                                                } elseif ($workload <= 4) {
                                                    echo '<span class="badge badge-warning">Moderate</span>';
                                                } else {
                                                    echo '<span class="badge badge-danger">Heavy</span>';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-chalkboard-teacher"></i>
                                <h3>No Teacher Data</h3>
                                <p>No teacher workload records available.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
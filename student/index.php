<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('student')) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

// Get student details
$sql = "SELECT s.*, d.department_name, c.course_name 
        FROM students s 
        JOIN departments d ON s.department_id = d.department_id 
        JOIN courses c ON s.course_id = c.course_id 
        WHERE s.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    die("Student record not found!");
}

// Get selected semester from URL or use current active semester
$selected_semester_id = isset($_GET['semester_id']) ? intval($_GET['semester_id']) : null;

// Get current active semester
$current_sem_query = "SELECT ss.*, sem.semester_name, sec.section_name 
                      FROM student_semesters ss 
                      JOIN semesters sem ON ss.semester_id = sem.semester_id 
                      LEFT JOIN sections sec ON ss.section_id = sec.section_id 
                      WHERE ss.student_id = ? AND ss.is_active = 1";
$stmt = $conn->prepare($current_sem_query);
$stmt->bind_param("i", $student['student_id']);
$stmt->execute();
$current_sem = $stmt->get_result()->fetch_assoc();

// If no semester selected, use current active semester
if (!$selected_semester_id && $current_sem) {
    $selected_semester_id = $current_sem['semester_id'];
}

// Get all semesters the student has been enrolled in
$all_semesters_query = "SELECT DISTINCT ss.semester_id, sem.semester_name, ss.academic_year, ss.is_active,
                        sec.section_name, sem.semester_number
                        FROM student_semesters ss 
                        JOIN semesters sem ON ss.semester_id = sem.semester_id 
                        LEFT JOIN sections sec ON ss.section_id = sec.section_id
                        WHERE ss.student_id = ?
                        ORDER BY sem.semester_number ASC";
$stmt = $conn->prepare($all_semesters_query);
$stmt->bind_param("i", $student['student_id']);
$stmt->execute();
$all_semesters = $stmt->get_result();

// Get semester info for selected semester
$selected_sem = null;
if ($selected_semester_id) {
    $selected_sem_query = "SELECT ss.*, sem.semester_name, sec.section_name 
                          FROM student_semesters ss 
                          JOIN semesters sem ON ss.semester_id = sem.semester_id 
                          LEFT JOIN sections sec ON ss.section_id = sec.section_id 
                          WHERE ss.student_id = ? AND ss.semester_id = ?";
    $stmt = $conn->prepare($selected_sem_query);
    $stmt->bind_param("ii", $student['student_id'], $selected_semester_id);
    $stmt->execute();
    $selected_sem = $stmt->get_result()->fetch_assoc();
}

// Get attendance summary for selected semester
$attendance_summary = null;
if ($selected_semester_id) {
    $attendance_query = "SELECT v.* FROM v_attendance_summary v
                        JOIN subjects sub ON v.subject_code = sub.subject_code
                        WHERE v.student_id = ? AND sub.semester_id = ?";
    $stmt = $conn->prepare($attendance_query);
    $stmt->bind_param("ii", $student['student_id'], $selected_semester_id);
    $stmt->execute();
    $attendance_summary = $stmt->get_result();
}

// Get enrolled subjects for selected semester
$subjects = null;
if ($selected_semester_id) {
    $subjects_query = "SELECT sub.subject_name, sub.subject_code, sub.credits, t.full_name as teacher_name 
                      FROM subjects sub 
                      LEFT JOIN subject_teachers st ON sub.subject_id = st.subject_id 
                         AND st.semester_id = ?
                      LEFT JOIN teachers t ON st.teacher_id = t.teacher_id
                      WHERE sub.department_id = ? AND sub.semester_id = ?";
    $stmt = $conn->prepare($subjects_query);
    $stmt->bind_param("iii", $selected_semester_id, $student['department_id'], $selected_semester_id);
    $stmt->execute();
    $subjects = $stmt->get_result();
}

// Calculate overall attendance
$overall_stats = null;
if ($selected_semester_id) {
    $stats_query = "SELECT 
                        COUNT(DISTINCT attendance_date) as total_days,
                        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
                        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count
                    FROM attendance 
                    WHERE student_id = ? AND semester_id = ?";
    $stmt = $conn->prepare($stats_query);
    $stmt->bind_param("ii", $student['student_id'], $selected_semester_id);
    $stmt->execute();
    $overall_stats = $stmt->get_result()->fetch_assoc();
    
    if ($overall_stats['total_days'] > 0) {
        $overall_stats['percentage'] = round(($overall_stats['present_count'] / $overall_stats['total_days']) * 100);
    } else {
        $overall_stats['percentage'] = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - College ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0ea5e9;
            --secondary: #06b6d4;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #0f172a;
            --gray: #64748b;
            --light-gray: #f1f5f9;
            --white: #ffffff;
            --purple: #8b5cf6;
            --purple-light: #a78bfa;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Manrope', sans-serif;
            background: var(--light-gray);
            color: var(--dark);
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.9);
            }
            to {
                opacity: 1;
                transform: scale(1);
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

        @keyframes shimmer {
            0% {
                background-position: -1000px 0;
            }
            100% {
                background-position: 1000px 0;
            }
        }

        .dashboard {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #0f172a 0%, #1e293b 100%);
            color: var(--white);
            padding: 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            box-shadow: 4px 0 20px rgba(0,0,0,0.1);
            animation: slideInRight 0.5s ease;
        }

        .sidebar-header {
            padding: 30px 25px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            animation: fadeInUp 0.6s ease;
        }

        .profile-section {
            text-align: center;
        }

        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 700;
            margin: 0 auto 15px;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .profile-avatar::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s;
        }

        .profile-avatar:hover {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 10px 30px rgba(14, 165, 233, 0.5);
        }

        .profile-avatar:hover::before {
            left: 100%;
        }

        .profile-name {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .profile-role {
            font-size: 0.85rem;
            opacity: 0.7;
        }

        .sidebar-menu {
            padding: 20px 0;
        }

        .menu-item {
            padding: 15px 25px;
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

        .menu-item:hover, .menu-item.active {
            background: rgba(14, 165, 233, 0.2);
            color: var(--white);
            transform: translateX(5px);
        }

        .menu-item:hover::before, .menu-item.active::before {
            transform: scaleY(1);
        }

        .menu-item i {
            margin-right: 15px;
            width: 20px;
            text-align: center;
            transition: transform 0.3s ease;
        }

        .menu-item:hover i {
            transform: scale(1.2) rotate(10deg);
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
        }

        .top-bar {
            background: var(--white);
            padding: 25px 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            animation: fadeInUp 0.5s ease;
        }

        .top-bar h1 {
            font-size: 2rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: shimmer 3s infinite;
            background-size: 1000px 100%;
        }

        .logout-btn {
            background: var(--danger);
            color: var(--white);
            border: none;
            padding: 12px 24px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            font-family: 'Manrope', sans-serif;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(239, 68, 68, 0.4);
            background: #dc2626;
        }

        .logout-btn i {
            transition: transform 0.3s ease;
        }

        .logout-btn:hover i {
            transform: translateX(3px);
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
            animation: fadeInUp 0.6s ease;
        }

        .action-card {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            padding: 25px;
            border-radius: 15px;
            color: var(--white);
            text-decoration: none;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .action-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            transform: scale(0);
            transition: transform 0.5s ease;
        }

        .action-card:hover::before {
            transform: scale(1);
        }

        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(14, 165, 233, 0.4);
        }

        .action-card.purple {
            background: linear-gradient(135deg, #8b5cf6, #a78bfa);
        }

        .action-card.purple:hover {
            box-shadow: 0 15px 40px rgba(139, 92, 246, 0.4);
        }

        .action-card.green {
            background: linear-gradient(135deg, #10b981, #34d399);
        }

        .action-card.green:hover {
            box-shadow: 0 15px 40px rgba(16, 185, 129, 0.4);
        }

        .action-card.orange {
            background: linear-gradient(135deg, #f59e0b, #fb923c);
        }

        .action-card.orange:hover {
            box-shadow: 0 15px 40px rgba(245, 158, 11, 0.4);
        }

        .action-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
            display: block;
            transition: transform 0.3s ease;
        }

        .action-card:hover .action-icon {
            transform: scale(1.2) rotate(10deg);
        }

        .action-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .action-desc {
            font-size: 0.85rem;
            opacity: 0.9;
        }

        /* Semester Selector */
        .semester-selector {
            background: var(--white);
            padding: 20px 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            gap: 20px;
            animation: fadeInUp 0.7s ease;
        }

        .semester-selector label {
            font-weight: 600;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .semester-selector label i {
            color: var(--primary);
            font-size: 1.2rem;
            transition: transform 0.3s ease;
        }

        .semester-selector:hover label i {
            transform: rotate(360deg);
        }

        .semester-select {
            flex: 1;
            max-width: 400px;
            padding: 12px 20px;
            border: 2px solid var(--light-gray);
            border-radius: 10px;
            font-family: 'Manrope', sans-serif;
            font-size: 1rem;
            font-weight: 500;
            color: var(--dark);
            background: var(--white);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .semester-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
        }

        .semester-select:hover {
            border-color: var(--primary);
        }

        .current-badge {
            background: linear-gradient(135deg, var(--success), #059669);
            color: var(--white);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 10px;
            animation: pulse 2s infinite;
        }

        /* Info Cards */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .info-card {
            background: var(--white);
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            animation: scaleIn 0.5s ease;
            animation-fill-mode: both;
        }

        .info-card:nth-child(1) { animation-delay: 0.1s; }
        .info-card:nth-child(2) { animation-delay: 0.2s; }
        .info-card:nth-child(3) { animation-delay: 0.3s; }
        .info-card:nth-child(4) { animation-delay: 0.4s; }
        .info-card:nth-child(5) { animation-delay: 0.5s; }

        .info-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .info-label {
            font-size: 0.85rem;
            color: var(--gray);
            margin-bottom: 8px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-label i {
            color: var(--primary);
        }

        .info-value {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark);
        }

        /* Content Cards */
        .card {
            background: var(--white);
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            animation: fadeInUp 0.8s ease;
            transition: all 0.3s ease;
        }

        .card:hover {
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
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
            transition: transform 0.3s ease;
        }

        .card:hover .card-header i {
            transform: scale(1.2) rotate(10deg);
        }

        .card-header h3 {
            font-size: 1.3rem;
            font-weight: 700;
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

        .table tbody tr:hover {
            background: var(--light-gray);
            transform: scale(1.01);
        }

        .badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .badge:hover {
            transform: scale(1.1);
        }

        .badge.success { 
            background: rgba(16, 185, 129, 0.15); 
            color: var(--success);
        }
        .badge.warning { 
            background: rgba(245, 158, 11, 0.15); 
            color: var(--warning);
        }
        .badge.danger { 
            background: rgba(239, 68, 68, 0.15); 
            color: var(--danger);
        }
        .badge.info { 
            background: rgba(14, 165, 233, 0.15); 
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
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            border-radius: 10px;
            transition: width 1s ease;
            position: relative;
            overflow: hidden;
        }

        .progress-fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            right: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: shimmer 2s infinite;
        }

        .attendance-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid var(--light-gray);
            transition: all 0.3s ease;
        }

        .attendance-item:hover {
            padding-left: 10px;
            background: var(--light-gray);
        }

        .attendance-item:last-child {
            border-bottom: none;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.3;
            animation: pulse 2s infinite;
        }

        .empty-state p {
            font-size: 1.1rem;
            margin-bottom: 10px;
        }

        .alert {
            background: rgba(245, 158, 11, 0.1);
            border-left: 4px solid var(--warning);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            animation: fadeInUp 0.5s ease;
        }

        .alert i {
            color: var(--warning);
            margin-right: 10px;
        }

        /* Loading Animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(14, 165, 233, 0.3);
            border-radius: 50%;
            border-top-color: var(--primary);
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
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
                <div class="profile-section">
                    <div class="profile-avatar"><?php echo strtoupper(substr($student['full_name'], 0, 1)); ?></div>
                    <div class="profile-name"><?php echo htmlspecialchars($student['full_name']); ?></div>
                    <div class="profile-role"><?php echo htmlspecialchars($student['admission_number']); ?></div>
                </div>
            </div>
            <nav class="sidebar-menu">
                <a href="index.php" class="menu-item active">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="my_attendance.php" class="menu-item">
                    <i class="fas fa-calendar-check"></i> My Attendance
                </a> <a href="detail_attandance.php" class="menu-item">
                    <i class="fas fa-calendar-check"></i> My Attendance Detail
                </a> 
                 <a href="totalday_attandance.php" class="menu-item">
                    <i class="fas fa-calendar-check"></i>  Monthly attandace report 
                </a>
                <a href="my_subjects.php" class="menu-item">
                    <i class="fas fa-book"></i> My Subjects
                </a>
                <a href="my_profile.php" class="menu-item">
                    <i class="fas fa-user"></i> My Profile
                </a>
                <a href="setting.php" class="menu-item">
                    <i class="fas fa-cog"></i> Settings
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-bar">
                <h1>Student Dashboard</h1>
                <a href="../logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <a href="overall_statistics.php" class="action-card">
                    <i class="fas fa-chart-pie action-icon"></i>
                    <div class="action-title">Overall Statistics</div>
                    <div class="action-desc">View detailed attendance stats</div>
                </a>
                <a href="recent_attendance.php" class="action-card purple">
                    <i class="fas fa-history action-icon"></i>
                    <div class="action-title">Recent Attendance</div>
                    <div class="action-desc">Check recent records</div>
                </a>
                <a href="today_attendance.php" class="action-card green">
                    <i class="fas fa-calendar-day action-icon"></i>
                    <div class="action-title">Today's Attendance</div>
                    <div class="action-desc">View today's status</div>
                </a>
                <a href="my_subjects.php" class="action-card orange">
                    <i class="fas fa-book-open action-icon"></i>
                    <div class="action-title">My Subjects</div>
                    <div class="action-desc">View all subjects</div>
                </a>
            </div>

            <?php if ($all_semesters->num_rows == 0): ?>
                <!-- No Semester Assigned Warning -->
                <div class="alert">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>No Semester Assigned</strong>
                    <p style="margin-top: 10px;">You have not been assigned to any semester yet. Please contact the administrator.</p>
                </div>
            <?php else: ?>
                <!-- Semester Selector -->
                <div class="semester-selector">
                    <label>
                        <i class="fas fa-calendar-alt"></i>
                        Select Semester:
                    </label>
                    <select class="semester-select" onchange="window.location.href='student_dashboard.php?semester_id=' + this.value">
                        <?php 
                        $all_semesters->data_seek(0);
                        while($sem = $all_semesters->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $sem['semester_id']; ?>" 
                                    <?php echo ($selected_semester_id == $sem['semester_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($sem['semester_name']); ?> 
                                (<?php echo htmlspecialchars($sem['academic_year']); ?>)
                                <?php echo $sem['is_active'] ? ' - Current' : ''; ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <?php if ($selected_sem && isset($selected_sem['is_active']) && $selected_sem['is_active']): ?>
                        <span class="current-badge">
                            <i class="fas fa-star"></i> Current Semester
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Info Cards -->
            <div class="info-grid">
                <div class="info-card">
                    <div class="info-label">
                        <i class="fas fa-building"></i>
                        Department
                    </div>
                    <div class="info-value"><?php echo htmlspecialchars($student['department_name']); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">
                        <i class="fas fa-graduation-cap"></i>
                        Course
                    </div>
                    <div class="info-value"><?php echo htmlspecialchars($student['course_name']); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">
                        <i class="fas fa-layer-group"></i>
                        Semester
                    </div>
                    <div class="info-value"><?php echo $selected_sem ? htmlspecialchars($selected_sem['semester_name']) : 'Not Assigned'; ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">
                        <i class="fas fa-users"></i>
                        Section
                    </div>
                    <div class="info-value"><?php echo ($selected_sem && $selected_sem['section_name']) ? htmlspecialchars($selected_sem['section_name']) : 'Not Assigned'; ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">
                        <i class="fas fa-calendar"></i>
                        Academic Year
                    </div>
                    <div class="info-value"><?php echo ($selected_sem && $selected_sem['academic_year']) ? htmlspecialchars($selected_sem['academic_year']) : 'N/A'; ?></div>
                </div>
            </div>

            <?php if ($selected_semester_id): ?>
                <!-- Attendance Summary -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-chart-pie"></i>
                        <h3>Attendance Summary</h3>
                    </div>
                    <?php if ($attendance_summary && $attendance_summary->num_rows > 0): ?>
                        <?php while($att = $attendance_summary->fetch_assoc()): ?>
                            <div class="attendance-item">
                                <div>
                                    <strong><?php echo htmlspecialchars($att['subject_name']); ?></strong>
                                    <div style="font-size: 0.85rem; color: var(--gray); margin-top: 5px;">
                                        <?php echo $att['present_count']; ?> / <?php echo $att['total_classes']; ?> classes
                                    </div>
                                </div>
                                <div style="text-align: right; min-width: 120px;">
                                    <?php 
                                    $percentage = $att['attendance_percentage'];
                                    $badge_class = $percentage >= 75 ? 'success' : ($percentage >= 60 ? 'warning' : 'danger');
                                    ?>
                                    <span class="badge <?php echo $badge_class; ?>"><?php echo $percentage; ?>%</span>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo $percentage; ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-times"></i>
                            <p>No attendance records found for this semester.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Enrolled Subjects -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-book-open"></i>
                        <h3>Enrolled Subjects</h3>
                    </div>
                    <?php if ($subjects && $subjects->num_rows > 0): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Subject Code</th>
                                <th>Subject Name</th>
                                <th>Credits</th>
                                <th>Faculty</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($subject = $subjects->fetch_assoc()): ?>
                            <tr>
                                <td><span class="badge info"><?php echo htmlspecialchars($subject['subject_code']); ?></span></td>
                                <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                <td><?php echo $subject['credits']; ?></td>
                                <td><?php echo $subject['teacher_name'] ? htmlspecialchars($subject['teacher_name']) : 'Not Assigned'; ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-book"></i>
                            <p>No subjects found for this semester.</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        // Add smooth scroll behavior
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelector(this.getAttribute('href')).scrollIntoView({
                    behavior: 'smooth'
                });
            });
        });

        // Add loading animation for semester change
        const semesterSelect = document.querySelector('.semester-select');
        if (semesterSelect) {
            semesterSelect.addEventListener('change', function() {
                this.style.opacity = '0.5';
                this.disabled = true;
            });
        }
    </script>
</body>
</html>
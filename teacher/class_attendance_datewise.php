<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('teacher')) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

// Get teacher and class details
$sql = "SELECT t.*, d.department_name, d.department_id 
        FROM teachers t 
        JOIN departments d ON t.department_id = d.department_id
        WHERE t.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();

$teacher_id = $teacher['teacher_id'];

// Check if teacher is a class teacher
$class_teacher_query = "SELECT * FROM v_class_teacher_details WHERE teacher_id = ? AND is_active = 1";
$stmt = $conn->prepare($class_teacher_query);
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$class_teacher = $stmt->get_result()->fetch_assoc();

if (!$class_teacher) {
    die("You are not assigned as a class teacher for any class.");
}

// Get selected date (default to today)
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Get attendance for selected date - grouped by student
$attendance_query = "SELECT 
                     s.student_id,
                     s.full_name, 
                     s.admission_number,
                     GROUP_CONCAT(
                         CONCAT(sub.subject_name, '||', sub.subject_code, '||', a.status, '||', t.full_name, '||', COALESCE(a.remarks, ''), '||', a.created_at)
                         SEPARATOR '###'
                     ) as attendance_details
                     FROM attendance a
                     JOIN students s ON a.student_id = s.student_id
                     JOIN subjects sub ON a.subject_id = sub.subject_id
                     JOIN teachers t ON a.marked_by = t.teacher_id
                     JOIN student_semesters ss ON s.student_id = ss.student_id
                     WHERE a.attendance_date = '$selected_date'
                     AND s.department_id = {$class_teacher['department_id']}
                     AND ss.semester_id = {$class_teacher['semester_id']}
                     AND ss.section_id = {$class_teacher['section_id']}
                     AND ss.is_active = 1
                     GROUP BY s.student_id, s.full_name, s.admission_number
                     ORDER BY s.full_name";
$attendance_data = $conn->query($attendance_query);

// Get statistics for selected date
$stats_query = "SELECT 
                COUNT(DISTINCT a.student_id) as students_marked,
                COUNT(DISTINCT a.subject_id) as subjects_covered,
                SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count
                FROM attendance a
                JOIN students s ON a.student_id = s.student_id
                JOIN student_semesters ss ON s.student_id = ss.student_id
                WHERE a.attendance_date = '$selected_date'
                AND s.department_id = {$class_teacher['department_id']}
                AND ss.semester_id = {$class_teacher['semester_id']}
                AND ss.section_id = {$class_teacher['section_id']}
                AND ss.is_active = 1";
$stats = $conn->query($stats_query)->fetch_assoc();

if (!$stats) {
    $stats = [
        'students_marked' => 0,
        'subjects_covered' => 0,
        'present_count' => 0,
        'absent_count' => 0,
        'late_count' => 0
    ];
}

// Get total students
$total_students_query = "SELECT COUNT(DISTINCT s.student_id) as count
                        FROM students s
                        JOIN student_semesters ss ON s.student_id = ss.student_id
                        WHERE s.department_id = {$class_teacher['department_id']}
                        AND ss.semester_id = {$class_teacher['semester_id']}
                        AND ss.section_id = {$class_teacher['section_id']}
                        AND ss.is_active = 1";
$total_students = $conn->query($total_students_query)->fetch_assoc()['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Date-wise Attendance - Class Teacher</title>
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

        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, var(--dark) 0%, #292524 100%);
            color: var(--white);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 30px 25px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
        }

        .teacher-profile {
            text-align: center;
        }

        .teacher-avatar {
            width: 75px;
            height: 75px;
            border-radius: 15px;
            background: rgba(255,255,255,0.2);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 700;
            margin: 0 auto 15px;
            border: 3px solid rgba(255,255,255,0.3);
        }

        .teacher-name {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .teacher-role {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .class-info {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(255,255,255,0.2);
        }

        .class-info-item {
            font-size: 0.85rem;
            opacity: 0.9;
            margin-bottom: 5px;
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
        }

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
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .top-bar h1 {
            font-size: 2.2rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 5px;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            margin-bottom: 20px;
            transition: all 0.3s;
        }

        .back-btn:hover {
            transform: translateX(-5px);
        }

        .back-btn i {
            margin-right: 8px;
        }

        .date-selector {
            background: var(--white);
            padding: 25px;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }

        .date-input-group {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .date-input-group input {
            padding: 12px 20px;
            border: 2px solid var(--light-gray);
            border-radius: 10px;
            font-family: 'Outfit', sans-serif;
            font-size: 1rem;
            transition: all 0.3s;
        }

        .date-input-group input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-family: 'Outfit', sans-serif;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(249, 115, 22, 0.3);
        }

        .quick-dates {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .quick-date-btn {
            padding: 8px 16px;
            background: var(--light-gray);
            border: 2px solid transparent;
            border-radius: 8px;
            cursor: pointer;
            font-family: 'Outfit', sans-serif;
            font-weight: 500;
            transition: all 0.3s;
        }

        .quick-date-btn:hover {
            background: var(--primary);
            color: var(--white);
            transform: translateY(-2px);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--white);
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            text-align: center;
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }

        .stat-circle {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 2.5rem;
            font-weight: 800;
        }

        .stat-circle.present {
            background: rgba(34, 197, 94, 0.15);
            color: var(--success);
        }

        .stat-circle.absent {
            background: rgba(239, 68, 68, 0.15);
            color: var(--danger);
        }

        .stat-circle.late {
            background: rgba(234, 179, 8, 0.15);
            color: var(--warning);
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.95rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 800;
        }

        .date-info-card {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            text-align: center;
            padding: 30px;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 8px 25px rgba(249, 115, 22, 0.3);
        }

        /* PERFECT STUDENT CARD */
        .attendance-cards-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }

        .student-card {
            background: var(--white);
            border-radius: 20px;
            padding: 0;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            transition: all 0.3s;
            overflow: hidden;
            border-left: 5px solid var(--primary);
        }

        .student-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 25px;
            border-bottom: 2px solid var(--light-gray);
        }

        .student-avatar {
            width: 70px;
            height: 70px;
            border-radius: 15px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            font-weight: 700;
            flex-shrink: 0;
        }

        .student-info {
            flex: 1;
        }

        .student-name {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .admission-number {
            font-size: 0.95rem;
            color: var(--gray);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .subjects-list {
            padding: 20px 25px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .subject-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px;
            background: var(--light-gray);
            border-radius: 12px;
            transition: all 0.2s;
        }

        .subject-row:hover {
            background: rgba(249, 115, 22, 0.05);
            transform: translateX(5px);
        }

        .subject-info {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
        }

        .subject-icon {
            width: 45px;
            height: 45px;
            border-radius: 10px;
            background: rgba(249, 115, 22, 0.1);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .subject-details {
            flex: 1;
        }

        .subject-name {
            font-size: 1rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 3px;
        }

        .subject-code {
            font-size: 0.85rem;
            color: var(--gray);
        }

        .status-badge {
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .status-badge.success {
            background: rgba(34, 197, 94, 0.15);
            color: var(--success);
        }

        .status-badge.danger {
            background: rgba(239, 68, 68, 0.15);
            color: var(--danger);
        }

        .status-badge.warning {
            background: rgba(234, 179, 8, 0.15);
            color: var(--warning);
        }

        .card-footer {
            padding: 20px 25px;
            border-top: 2px solid var(--light-gray);
            background: rgba(249, 115, 22, 0.02);
        }

        .view-details-btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            border: none;
            border-radius: 12px;
            font-family: 'Outfit', sans-serif;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .view-details-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(249, 115, 22, 0.4);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(5px);
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--white);
            border-radius: 20px;
            padding: 30px;
            max-width: 700px;
            width: 90%;
            max-height: 85vh;
            overflow-y: auto;
            animation: slideUp 0.3s ease-out;
        }

        @keyframes slideUp {
            from {
                transform: translateY(50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--light-gray);
        }

        .modal-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
        }

        .close-modal {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--light-gray);
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--gray);
            transition: all 0.3s;
        }

        .close-modal:hover {
            background: var(--danger);
            color: var(--white);
        }

        .modal-student-info {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 20px;
            background: var(--light-gray);
            border-radius: 15px;
            margin-bottom: 25px;
        }

        .detail-item {
            padding: 20px;
            background: var(--light-gray);
            border-radius: 15px;
            margin-bottom: 15px;
            border-left: 4px solid var(--primary);
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .detail-row:last-child {
            margin-bottom: 0;
        }

        .detail-label {
            font-size: 0.85rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .detail-value {
            font-size: 1rem;
            font-weight: 600;
            color: var(--dark);
        }

        .no-records {
            text-align: center;
            padding: 60px 40px;
            color: var(--gray);
            grid-column: 1 / -1;
        }

        .no-records i {
            font-size: 5rem;
            margin-bottom: 20px;
            opacity: 0.3;
            color: var(--primary);
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                position: relative;
                height: auto;
            }

            .main-content {
                margin-left: 0;
                padding: 20px;
            }

            .attendance-cards-container {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="teacher-profile">
                    <div class="teacher-avatar"><?php echo strtoupper(substr($teacher['full_name'], 0, 1)); ?></div>
                    <div class="teacher-name"><?php echo htmlspecialchars($teacher['full_name']); ?></div>
                    <div class="teacher-role">Class Teacher</div>
                    <div class="class-info">
                        <div class="class-info-item">
                            <i class="fas fa-building"></i> <?php echo htmlspecialchars($class_teacher['department_name']); ?>
                        </div>
                        <div class="class-info-item">
                            <i class="fas fa-layer-group"></i> <?php echo htmlspecialchars($class_teacher['semester_name']); ?>
                        </div>
                        <div class="class-info-item">
                            <i class="fas fa-users"></i> Section <?php echo htmlspecialchars($class_teacher['section_name']); ?>
                        </div>
                    </div>
                </div>
            </div>
            <nav class="sidebar-menu">
                <a href="class_teacher_dashboard.php" class="menu-item">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="class_students.php" class="menu-item">
                    <i class="fas fa-user-graduate"></i> My Students
                </a>
                <a href="class_attendance_records.php" class="menu-item">
                    <i class="fas fa-calendar-check"></i> Attendance Records
                </a>
                <a href="class_attendance_datewise.php" class="menu-item active">
                    <i class="fas fa-calendar-alt"></i> Date-wise Attendance
                </a>
                <a href="class_absent_students.php" class="menu-item">
                    <i class="fas fa-user-times"></i> Absent Students
                </a>
                <a href="class_reports.php" class="menu-item">
                    <i class="fas fa-chart-line"></i> Reports
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <a href="class_teacher_dashboard.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>

            <div class="top-bar">
                <h1><i class="fas fa-calendar-alt"></i> Date-wise Attendance</h1>
                <p>View attendance records by specific dates</p>
            </div>

            <!-- Date Selector -->
            <div class="date-selector">
                <div style="margin-bottom: 20px;">
                    <h3><i class="fas fa-calendar"></i> Select Date</h3>
                </div>
                <form method="GET" action="">
                    <div class="date-input-group">
                        <input type="date" name="date" value="<?php echo htmlspecialchars($selected_date); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> View Attendance
                        </button>
                    </div>
                </form>
                <div style="margin-top: 15px;">
                    <strong>Quick Select:</strong>
                    <div class="quick-dates" style="margin-top: 10px;">
                        <button class="quick-date-btn" onclick="window.location.href='?date=<?php echo date('Y-m-d'); ?>'">
                            <i class="fas fa-calendar-day"></i> Today
                        </button>
                        <button class="quick-date-btn" onclick="window.location.href='?date=<?php echo date('Y-m-d', strtotime('-1 day')); ?>'">
                            <i class="fas fa-calendar-minus"></i> Yesterday
                        </button>
                        <button class="quick-date-btn" onclick="window.location.href='?date=<?php echo date('Y-m-d', strtotime('-7 days')); ?>'">
                            <i class="fas fa-calendar-week"></i> Last Week
                        </button>
                    </div>
                </div>
            </div>

            <!-- Selected Date Info -->
            <div class="date-info-card">
                <h2>
                    <i class="fas fa-calendar-day"></i> <?php echo date('l, F d, Y', strtotime($selected_date)); ?>
                </h2>
                <p>Attendance records for this date</p>
            </div>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-circle present">
                        <?php echo $stats['present_count']; ?>
                    </div>
                    <div class="stat-label">Present</div>
                </div>
                <div class="stat-card">
                    <div class="stat-circle absent">
                        <?php echo $stats['absent_count']; ?>
                    </div>
                    <div class="stat-label">Absent</div>
                </div>
                <div class="stat-card">
                    <div class="stat-circle late">
                        <?php echo $stats['late_count']; ?>
                    </div>
                    <div class="stat-label">Late</div>
                </div>
            </div>

            <!-- Additional Stats -->
            <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['students_marked']; ?>/<?php echo $total_students; ?></div>
                    <div class="stat-label">Students Marked</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: var(--primary)"><?php echo $stats['subjects_covered']; ?></div>
                    <div class="stat-label">Subjects Covered</div>
                </div>
            </div>

            <!-- Student Cards -->
            <div style="margin-top: 40px;">
                <h2 style="margin-bottom: 25px; color: var(--dark);">
                    <i class="fas fa-users"></i> Student Attendance Records
                </h2>
                <div class="attendance-cards-container">
                    <?php 
                    if($attendance_data->num_rows > 0): 
                        while($student = $attendance_data->fetch_assoc()): 
                            $subjects = explode('###', $student['attendance_details']);
                    ?>
                    <div class="student-card">
                        <!-- Card Header -->
                        <div class="card-header">
                            <div class="student-avatar">
                                <?php echo strtoupper(substr($student['full_name'], 0, 1)); ?>
                            </div>
                            <div class="student-info">
                                <div class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></div>
                                <div class="admission-number">
                                    <i class="fas fa-id-badge"></i>
                                    <?php echo htmlspecialchars($student['admission_number']); ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Subjects List -->
                        <div class="subjects-list">
                            <?php 
                            foreach($subjects as $subject_data): 
                                $parts = explode('||', $subject_data);
                                $subject_name = $parts[0];
                                $subject_code = $parts[1];
                                $status = $parts[2];
                                
                                $status_class = $status == 'present' ? 'success' : 
                                              ($status == 'absent' ? 'danger' : 'warning');
                                $status_icon = $status == 'present' ? 'fa-check-circle' : 
                                             ($status == 'absent' ? 'fa-times-circle' : 'fa-clock');
                            ?>
                            <div class="subject-row">
                                <div class="subject-info">
                                    <div class="subject-icon">
                                        <i class="fas fa-book"></i>
                                    </div>
                                    <div class="subject-details">
                                        <div class="subject-name"><?php echo htmlspecialchars($subject_name); ?></div>
                                        <div class="subject-code"><?php echo htmlspecialchars($subject_code); ?></div>
                                    </div>
                                </div>
                                <span class="status-badge <?php echo $status_class; ?>">
                                    <i class="fas <?php echo $status_icon; ?>"></i>
                                    <?php echo ucfirst($status); ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Card Footer -->
                        <div class="card-footer">
                            <button class="view-details-btn" onclick='viewDetails(<?php echo json_encode($student); ?>)'>
                                <i class="fas fa-eye"></i>
                                View Full Details
                            </button>
                        </div>
                    </div>
                    <?php 
                        endwhile; 
                    else: 
                    ?>
                    <div class="no-records">
                        <i class="fas fa-calendar-times"></i>
                        <h3>No Attendance Records</h3>
                        <p>No attendance was marked on <?php echo date('F d, Y', strtotime($selected_date)); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal -->
    <div class="modal" id="detailsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Full Attendance Details</h2>
                <button class="close-modal" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="modalBody"></div>
        </div>
    </div>

    <script>
        function viewDetails(studentData) {
            const modal = document.getElementById('detailsModal');
            const modalBody = document.getElementById('modalBody');
            
            if (!studentData || !studentData.attendance_details) {
                return;
            }
            
            const subjects = studentData.attendance_details.split('###');
            
            let html = `
                <div class="modal-student-info">
                    <div class="student-avatar">
                        ${studentData.full_name.charAt(0).toUpperCase()}
                    </div>
                    <div class="student-info">
                        <div class="student-name">${studentData.full_name}</div>
                        <div class="admission-number">
                            <i class="fas fa-id-badge"></i>
                            ${studentData.admission_number}
                        </div>
                    </div>
                </div>
            `;
            
            subjects.forEach(subjectData => {
                const parts = subjectData.split('||');
                const subjectName = parts[0];
                const subjectCode = parts[1];
                const status = parts[2];
                const markedBy = parts[3];
                const remarks = parts[4];
                const time = parts[5];
                
                const statusClass = status === 'present' ? 'success' : 
                                  (status === 'absent' ? 'danger' : 'warning');
                const statusIcon = status === 'present' ? 'fa-check-circle' : 
                                 (status === 'absent' ? 'fa-times-circle' : 'fa-clock');
                
                const formattedTime = new Date(time).toLocaleTimeString('en-US', {
                    hour: '2-digit',
                    minute: '2-digit'
                });
                
                html += `
                    <div class="detail-item">
                        <h3 style="margin-bottom: 15px; color: var(--primary);">
                            <i class="fas fa-book"></i> ${subjectName}
                        </h3>
                        <div class="detail-row">
                            <span class="detail-label">
                                <i class="fas fa-code"></i> Subject Code
                            </span>
                            <span class="detail-value">${subjectCode}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">
                                <i class="fas fa-user-check"></i> Status
                            </span>
                            <span class="status-badge ${statusClass}">
                                <i class="fas ${statusIcon}"></i>
                                ${status.charAt(0).toUpperCase() + status.slice(1)}
                            </span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">
                                <i class="fas fa-user-tie"></i> Marked By
                            </span>
                            <span class="detail-value">${markedBy}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">
                                <i class="fas fa-clock"></i> Time
                            </span>
                            <span class="detail-value">${formattedTime}</span>
                        </div>
                        ${remarks && remarks.trim() !== '' ? `
                        <div class="detail-row" style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #ddd;">
                            <span class="detail-label">
                                <i class="fas fa-comment-dots"></i> Remarks
                            </span>
                            <span class="detail-value" style="font-weight: 400; font-style: italic;">${remarks}</span>
                        </div>
                        ` : ''}
                    </div>
                `;
            });
            
            modalBody.innerHTML = html;
            modal.classList.add('active');
        }

        function closeModal() {
            document.getElementById('detailsModal').classList.remove('active');
        }

        document.getElementById('detailsModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
    </script>
</body>
</html>
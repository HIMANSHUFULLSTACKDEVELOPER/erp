<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('teacher')) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

// Get teacher details
$sql = "SELECT t.*, d.department_name, d.department_id 
        FROM teachers t 
        JOIN departments d ON t.department_id = d.department_id
        WHERE t.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();

if (!$teacher) {
    die("Teacher profile not found.");
}

$teacher_id = $teacher['teacher_id'];
$dept_id = $teacher['department_id'];

// Check if teacher is a class teacher
$class_teacher_query = "SELECT * FROM v_class_teacher_details WHERE teacher_id = ? AND is_active = 1";
$stmt = $conn->prepare($class_teacher_query);
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$class_teacher = $stmt->get_result()->fetch_assoc();

if (!$class_teacher) {
    die("You are not assigned as a class teacher for any class.");
}

// Get class statistics
$class_stats = [];

// Total students in class
$result = $conn->query("SELECT COUNT(DISTINCT s.student_id) as count 
                       FROM students s
                       JOIN student_semesters ss ON s.student_id = ss.student_id
                       WHERE s.department_id = {$class_teacher['department_id']}
                       AND ss.semester_id = {$class_teacher['semester_id']}
                       AND ss.section_id = {$class_teacher['section_id']}
                       AND ss.is_active = 1");
$class_stats['students'] = $result->fetch_assoc()['count'];

// Average attendance
$result = $conn->query("SELECT ROUND(AVG(attendance_percentage), 2) as avg_attendance 
                       FROM v_attendance_summary vas
                       JOIN students s ON vas.student_id = s.student_id
                       JOIN student_semesters ss ON s.student_id = ss.student_id
                       WHERE s.department_id = {$class_teacher['department_id']}
                       AND ss.semester_id = {$class_teacher['semester_id']}
                       AND ss.section_id = {$class_teacher['section_id']}
                       AND ss.is_active = 1");
$avg_att = $result->fetch_assoc();
$class_stats['avg_attendance'] = $avg_att['avg_attendance'] ?? 0;

// Subjects for this semester
$result = $conn->query("SELECT COUNT(*) as count 
                       FROM subjects 
                       WHERE department_id = {$class_teacher['department_id']}
                       AND semester_id = {$class_teacher['semester_id']}");
$class_stats['subjects'] = $result->fetch_assoc()['count'];

// Students with low attendance (below 75%)
$result = $conn->query("SELECT COUNT(DISTINCT vas.student_id) as count
                       FROM v_attendance_summary vas
                       JOIN students s ON vas.student_id = s.student_id
                       JOIN student_semesters ss ON s.student_id = ss.student_id
                       WHERE s.department_id = {$class_teacher['department_id']}
                       AND ss.semester_id = {$class_teacher['semester_id']}
                       AND ss.section_id = {$class_teacher['section_id']}
                       AND ss.is_active = 1
                       AND vas.attendance_percentage < 75");
$class_stats['low_attendance'] = $result->fetch_assoc()['count'];

// Today's attendance count
$today = date('Y-m-d');
$result = $conn->query("SELECT 
                        COUNT(DISTINCT CASE WHEN a.status = 'present' THEN a.student_id END) as present_today,
                        COUNT(DISTINCT CASE WHEN a.status = 'absent' THEN a.student_id END) as absent_today
                       FROM attendance a
                       JOIN students s ON a.student_id = s.student_id
                       JOIN student_semesters ss ON s.student_id = ss.student_id
                       WHERE s.department_id = {$class_teacher['department_id']}
                       AND ss.semester_id = {$class_teacher['semester_id']}
                       AND ss.section_id = {$class_teacher['section_id']}
                       AND a.attendance_date = '$today'
                       AND ss.is_active = 1");
$today_stats = $result->fetch_assoc();
$class_stats['present_today'] = $today_stats['present_today'] ?? 0;
$class_stats['absent_today'] = $today_stats['absent_today'] ?? 0;

// Get student list
$students = $conn->query("SELECT s.*, u.email, u.phone,
                         c.course_name, c.course_code
                         FROM students s
                         JOIN users u ON s.user_id = u.user_id
                         JOIN courses c ON s.course_id = c.course_id
                         JOIN student_semesters ss ON s.student_id = ss.student_id
                         WHERE s.department_id = {$class_teacher['department_id']}
                         AND ss.semester_id = {$class_teacher['semester_id']}
                         AND ss.section_id = {$class_teacher['section_id']}
                         AND ss.is_active = 1
                         ORDER BY s.full_name");

// Get recent attendance records (last 10)
$recent_attendance = $conn->query("SELECT a.*, s.full_name, s.admission_number, sub.subject_name,
                                   t.full_name as marked_by_name
                                  FROM attendance a
                                  JOIN students s ON a.student_id = s.student_id
                                  JOIN subjects sub ON a.subject_id = sub.subject_id
                                  JOIN teachers t ON a.marked_by = t.teacher_id
                                  JOIN student_semesters ss ON s.student_id = ss.student_id
                                  WHERE s.department_id = {$class_teacher['department_id']}
                                  AND ss.semester_id = {$class_teacher['semester_id']}
                                  AND ss.section_id = {$class_teacher['section_id']}
                                  AND ss.is_active = 1
                                  ORDER BY a.attendance_date DESC, a.created_at DESC
                                  LIMIT 10");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Teacher Dashboard - College ERP</title>
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

        .teacher-profile {
            text-align: center;
        }

        .teacher-avatar {
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

        .teacher-name {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .teacher-role {
            font-size: 0.9rem;
            opacity: 0.9;
            font-weight: 500;
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

        .logout-btn {
            background: linear-gradient(135deg, var(--danger), #dc2626);
            color: var(--white);
            border: none;
            padding: 12px 28px;
            border-radius: 15px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-family: 'Outfit', sans-serif;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
        }

        .logout-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 25px rgba(239, 68, 68, 0.4);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--white);
            padding: 25px;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            opacity: 0.1;
            border-radius: 0 20px 0 100%;
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
        }

        .stat-icon.orange { background: rgba(249, 115, 22, 0.15); color: var(--primary); }
        .stat-icon.green { background: rgba(34, 197, 94, 0.15); color: var(--success); }
        .stat-icon.yellow { background: rgba(234, 179, 8, 0.15); color: var(--warning); }
        .stat-icon.blue { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }
        .stat-icon.red { background: rgba(239, 68, 68, 0.15); color: var(--danger); }
        .stat-icon.purple { background: rgba(168, 85, 247, 0.15); color: #a855f7; }

        .stat-number {
            font-size: 2.2rem;
            font-weight: 800;
            color: var(--dark);
            position: relative;
            z-index: 1;
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
            font-weight: 500;
            margin-top: 5px;
        }

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

        .card-header h3 {
            font-size: 1.4rem;
            font-weight: 700;
            display: flex;
            align-items: center;
        }

        .card-header h3 i {
            color: var(--primary);
            margin-right: 12px;
        }

        .card-header a {
            text-decoration: none;
            color: var(--primary);
            font-weight: 600;
            transition: all 0.3s;
        }

        .card-header a:hover {
            color: var(--secondary);
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
            padding: 18px 12px;
            border-bottom: 1px solid var(--light-gray);
        }

        .badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge.success { background: rgba(34, 197, 94, 0.15); color: var(--success); }
        .badge.danger { background: rgba(239, 68, 68, 0.15); color: var(--danger); }
        .badge.warning { background: rgba(234, 179, 8, 0.15); color: var(--warning); }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .action-btn {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            padding: 15px 20px;
            border-radius: 15px;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(249, 115, 22, 0.3);
        }

        .action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 25px rgba(249, 115, 22, 0.4);
        }

        .action-btn i {
            margin-right: 10px;
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
                    <div class="teacher-name"><?php echo $teacher['full_name']; ?></div>
                    <div class="teacher-role">Class Teacher</div>
                    <div class="class-info">
                        <div class="class-info-item">
                            <i class="fas fa-building"></i> <?php echo $class_teacher['department_name']; ?>
                        </div>
                        <div class="class-info-item">
                            <i class="fas fa-layer-group"></i> <?php echo $class_teacher['semester_name']; ?>
                        </div>
                        <div class="class-info-item">
                            <i class="fas fa-users"></i> Section <?php echo $class_teacher['section_name']; ?>
                        </div>
                    </div>
                </div>
            </div>
            <nav class="sidebar-menu">
                <a href="class_teacher_dashboard.php" class="menu-item active">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="class_students.php" class="menu-item">
                    <i class="fas fa-user-graduate"></i> My Students
                </a>
                <a href="class_attendance_records.php" class="menu-item">
                    <i class="fas fa-calendar-check"></i> Attendance Records
                </a>
                <a href="class_attendance_datewise.php" class="menu-item">
                    <i class="fas fa-calendar-alt"></i> Date-wise Attendance
                </a>
                <a href="class_absent_students.php" class="menu-item">
                    <i class="fas fa-user-times"></i> Absent Students
                </a>
                <a href="class_reports.php" class="menu-item">
                    <i class="fas fa-chart-line"></i> Reports
                </a>
                <a href="teacher_profile.php" class="menu-item">
                    <i class="fas fa-user"></i> Profile
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-bar">
                <div class="top-bar-left">
                    <h1>Class Teacher Dashboard</h1>
                    <p><?php echo $class_teacher['semester_name']; ?> - Section <?php echo $class_teacher['section_name']; ?> | Academic Year <?php echo $class_teacher['academic_year']; ?></p>
                </div>
                <a href="../logout.php"><button class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</button></a>
            </div>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $class_stats['students']; ?></div>
                            <div class="stat-label">Total Students</div>
                        </div>
                        <div class="stat-icon orange">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $class_stats['avg_attendance']; ?>%</div>
                            <div class="stat-label">Average Attendance</div>
                        </div>
                        <div class="stat-icon green">
                            <i class="fas fa-chart-line"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $class_stats['present_today']; ?></div>
                            <div class="stat-label">Present Today</div>
                        </div>
                        <div class="stat-icon blue">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $class_stats['absent_today']; ?></div>
                            <div class="stat-label">Absent Today</div>
                        </div>
                        <div class="stat-icon red">
                            <i class="fas fa-times-circle"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $class_stats['low_attendance']; ?></div>
                            <div class="stat-label">Low Attendance (<75%)</div>
                        </div>
                        <div class="stat-icon yellow">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $class_stats['subjects']; ?></div>
                            <div class="stat-label">Total Subjects</div>
                        </div>
                        <div class="stat-icon purple">
                            <i class="fas fa-book"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <a href="class_attendance_datewise.php" class="action-btn">
                    <i class="fas fa-calendar-alt"></i> View Date-wise Attendance
                </a>
                <a href="class_absent_students.php" class="action-btn">
                    <i class="fas fa-user-times"></i> View Absent Students
                </a>
                <a href="today_atten_whoeleday.php" class="action-btn">
                    <i class="fas fa-user-times"></i> Total Attendance Students
                </a>
                <a href="class_attendance_records.php" class="action-btn">
                    <i class="fas fa-list"></i> All Attendance Records
                </a>
                <a href="class_reports.php" class="action-btn">
                    <i class="fas fa-file-download"></i> Generate Reports
                </a>
            </div>

            <!-- Recent Attendance -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-clock"></i> Recent Attendance Records</h3>
                    <a href="class_attendance_records.php">View All <i class="fas fa-arrow-right"></i></a>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Student</th>
                            <th>Admission No.</th>
                            <th>Subject</th>
                            <th>Status</th>
                            <th>Marked By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($att = $recent_attendance->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo date('M d, Y', strtotime($att['attendance_date'])); ?></td>
                            <td><?php echo $att['full_name']; ?></td>
                            <td><?php echo $att['admission_number']; ?></td>
                            <td><?php echo $att['subject_name']; ?></td>
                            <td>
                                <?php 
                                $badge_class = $att['status'] == 'present' ? 'success' : ($att['status'] == 'absent' ? 'danger' : 'warning');
                                ?>
                                <span class="badge <?php echo $badge_class; ?>">
                                    <?php echo ucfirst($att['status']); ?>
                                </span>
                            </td>
                            <td><?php echo $att['marked_by_name']; ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Students Table -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-users"></i> Class Students</h3>
                    <a href="class_students.php">View All <i class="fas fa-arrow-right"></i></a>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Admission No.</th>
                            <th>Student Name</th>
                            <th>Course</th>
                            <th>Contact</th>
                            <th>Email</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $count = 0;
                        $students->data_seek(0); // Reset pointer
                        while($student = $students->fetch_assoc()): 
                            if($count >= 5) break;
                            $count++;
                        ?>
                        <tr>
                            <td><strong><?php echo $student['admission_number']; ?></strong></td>
                            <td><?php echo $student['full_name']; ?></td>
                            <td><?php echo $student['course_code']; ?></td>
                            <td><?php echo $student['phone'] ?: 'N/A'; ?></td>
                            <td><?php echo $student['email']; ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>
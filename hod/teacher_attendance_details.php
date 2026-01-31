<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('hod')) {
    redirect('login.php');
}

// Get parameters
$teacher_id = isset($_GET['teacher_id']) ? intval($_GET['teacher_id']) : 0;
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$selected_department = isset($_GET['department']) ? intval($_GET['department']) : 0;
$selected_semester = isset($_GET['semester']) ? intval($_GET['semester']) : 0;
$selected_section = isset($_GET['section']) ? intval($_GET['section']) : 0;

if (!$teacher_id) {
    redirect('admin_class_attendance_report.php');
}

// Get teacher information
$teacher_query = "SELECT t.*, u.email, u.phone, d.department_name 
                  FROM teachers t
                  JOIN users u ON t.user_id = u.user_id
                  JOIN departments d ON t.department_id = d.department_id
                  WHERE t.teacher_id = $teacher_id";
$teacher_result = $conn->query($teacher_query);
$teacher = $teacher_result->fetch_assoc();

if (!$teacher) {
    redirect('admin_class_attendance_report.php');
}

// Get subjects assigned to teacher with attendance status
$subjects_query = "SELECT DISTINCT
    sub.subject_id,
    sub.subject_name,
    sub.subject_code,
    sem.semester_name,
    sec.section_name,
    sec.section_id,
    sem.semester_id,
    
    -- Check if attendance is marked for this date
    (SELECT COUNT(*) 
     FROM attendance a 
     WHERE a.subject_id = sub.subject_id 
     AND a.marked_by = $teacher_id
     AND DATE(a.attendance_date) = '$selected_date'
     AND a.section_id = st.section_id
    ) as is_marked,
    
    -- Get total students in class
    (SELECT COUNT(DISTINCT s.student_id)
     FROM students s
     JOIN student_semesters ss ON s.student_id = ss.student_id
     WHERE ss.semester_id = sem.semester_id
     AND ss.section_id = st.section_id
     AND ss.is_active = 1
     AND s.department_id = t.department_id
    ) as total_students,
    
    -- Get present count
    (SELECT COUNT(*)
     FROM attendance a
     WHERE a.subject_id = sub.subject_id
     AND a.marked_by = $teacher_id
     AND DATE(a.attendance_date) = '$selected_date'
     AND a.section_id = st.section_id
     AND a.status = 'present'
    ) as present_count,
    
    -- Get absent count
    (SELECT COUNT(*)
     FROM attendance a
     WHERE a.subject_id = sub.subject_id
     AND a.marked_by = $teacher_id
     AND DATE(a.attendance_date) = '$selected_date'
     AND a.section_id = st.section_id
     AND a.status = 'absent'
    ) as absent_count

FROM subject_teachers st
JOIN subjects sub ON st.subject_id = sub.subject_id
JOIN semesters sem ON st.semester_id = sem.semester_id
JOIN sections sec ON st.section_id = sec.section_id
JOIN teachers t ON st.teacher_id = t.teacher_id
WHERE st.teacher_id = $teacher_id";

if ($selected_semester) {
    $subjects_query .= " AND st.semester_id = $selected_semester";
}
if ($selected_section) {
    $subjects_query .= " AND st.section_id = $selected_section";
}
if ($selected_department) {
    $subjects_query .= " AND sub.department_id = $selected_department";
}

$subjects_query .= " ORDER BY sem.semester_number, sec.section_name, sub.subject_name";
$subjects = $conn->query($subjects_query);

// Calculate statistics
$total_classes = 0;
$marked_classes = 0;
$pending_classes = 0;
$total_students_all = 0;
$total_present = 0;
$total_absent = 0;

$temp_result = $conn->query($subjects_query);
while ($row = $temp_result->fetch_assoc()) {
    $total_classes++;
    if ($row['is_marked'] > 0) {
        $marked_classes++;
        $total_present += $row['present_count'];
        $total_absent += $row['absent_count'];
    } else {
        $pending_classes++;
    }
    $total_students_all += $row['total_students'];
}

// Get recent attendance history
$history_query = "SELECT 
    DATE(a.attendance_date) as date,
    sub.subject_name,
    sub.subject_code,
    sec.section_name,
    COUNT(DISTINCT a.student_id) as total_marked,
    SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present,
    SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent
FROM attendance a
JOIN subjects sub ON a.subject_id = sub.subject_id
JOIN sections sec ON a.section_id = sec.section_id
WHERE a.marked_by = $teacher_id
AND DATE(a.attendance_date) >= DATE_SUB('$selected_date', INTERVAL 7 DAY)
AND DATE(a.attendance_date) <= '$selected_date'
GROUP BY DATE(a.attendance_date), a.subject_id, a.section_id
ORDER BY a.attendance_date DESC, sub.subject_name
LIMIT 10";
$history = $conn->query($history_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Attendance Details - <?php echo $teacher['full_name']; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
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
        }

        .dashboard {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--dark);
            color: var(--white);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
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
        }

        .menu-item:hover {
            background: var(--primary);
            color: var(--white);
            padding-left: 25px;
        }

        .menu-item i {
            margin-right: 12px;
            width: 20px;
            text-align: center;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 30px;
        }

        .top-bar {
            background: var(--white);
            padding: 20px 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .top-bar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .top-bar h1 {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .back-btn {
            background: var(--gray);
            color: var(--white);
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .back-btn:hover {
            background: var(--dark);
        }

        /* Teacher Profile Card */
        .teacher-profile {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 20px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 12px;
            color: var(--white);
        }

        .teacher-avatar-large {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--white);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 2rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }

        .teacher-info {
            flex: 1;
        }

        .teacher-info h2 {
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        .teacher-info p {
            opacity: 0.9;
            margin-bottom: 3px;
        }

        .teacher-info p i {
            margin-right: 8px;
            width: 20px;
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
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border-left: 4px solid var(--primary);
        }

        .stat-card.success {
            border-left-color: var(--success);
        }

        .stat-card.warning {
            border-left-color: var(--warning);
        }

        .stat-card.danger {
            border-left-color: var(--danger);
        }

        .stat-card h3 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-card p {
            color: var(--gray);
            font-size: 0.9rem;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }

        .card {
            background: var(--white);
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .card-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--light-gray);
        }

        .card-header h3 {
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Table */
        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: var(--light-gray);
        }

        th {
            text-align: left;
            padding: 12px 15px;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            color: var(--gray);
        }

        td {
            padding: 15px;
            border-bottom: 1px solid var(--light-gray);
        }

        tbody tr:hover {
            background: #fafafa;
        }

        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .badge-danger {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .badge-info {
            background: rgba(99, 102, 241, 0.1);
            color: var(--primary);
        }

        .badge-gray {
            background: var(--light-gray);
            color: var(--gray);
        }

        .action-btn {
            padding: 8px 16px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.85rem;
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

        /* Progress Bar */
        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--light-gray);
            border-radius: 10px;
            overflow: hidden;
            margin-top: 10px;
        }

        .progress-fill {
            height: 100%;
            background: var(--success);
            border-radius: 10px;
            transition: width 0.3s ease;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }

            .sidebar {
                transform: translateX(-100%);
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .teacher-profile {
                flex-direction: column;
                text-align: center;
            }

            .top-bar-header {
                flex-direction: column;
                gap: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2>Admin Panel</h2>
                <p>College ERP System</p>
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
                <div class="top-bar-header">
                    <h1>
                        <i class="fas fa-user-circle"></i>
                        Teacher Attendance Details
                    </h1>
                    <a href="admin_class_attendance_report.php?date=<?php echo $selected_date; ?>" class="back-btn">
                        <i class="fas fa-arrow-left"></i> Back to All Teachers
                    </a>
                </div>

                <!-- Teacher Profile -->
                <div class="teacher-profile">
                    <div class="teacher-avatar-large">
                        <?php echo strtoupper(substr($teacher['full_name'], 0, 2)); ?>
                    </div>
                    <div class="teacher-info">
                        <h2><?php echo $teacher['full_name']; ?></h2>
                        <p><i class="fas fa-building"></i><?php echo $teacher['department_name']; ?></p>
                        <p><i class="fas fa-envelope"></i><?php echo $teacher['email']; ?></p>
                        <p><i class="fas fa-phone"></i><?php echo $teacher['phone']; ?></p>
                        <p><i class="fas fa-calendar"></i>Date: <?php echo date('F d, Y', strtotime($selected_date)); ?></p>
                    </div>
                </div>
            </div>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h3><?php echo $total_classes; ?></h3>
                    <p>Total Classes</p>
                </div>
                <div class="stat-card success">
                    <h3><?php echo $marked_classes; ?></h3>
                    <p>Classes Marked</p>
                    <?php if($total_classes > 0): ?>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo ($marked_classes/$total_classes)*100; ?>%"></div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="stat-card warning">
                    <h3><?php echo $pending_classes; ?></h3>
                    <p>Pending Classes</p>
                </div>
                <div class="stat-card success">
                    <h3><?php echo $total_present; ?></h3>
                    <p>Total Present</p>
                </div>
                <div class="stat-card danger">
                    <h3><?php echo $total_absent; ?></h3>
                    <p>Total Absent</p>
                </div>
            </div>

            <!-- Classes Details -->
            <div class="card">
                <div class="card-header">
                    <h3>
                        <i class="fas fa-list"></i>
                        Classes Assigned
                    </h3>
                </div>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>SUBJECT</th>
                                <th>SEMESTER</th>
                                <th>SECTION</th>
                                <th>TOTAL STUDENTS</th>
                                <th>PRESENT</th>
                                <th>ABSENT</th>
                                <th>STATUS</th>
                                <th>ACTION</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($subjects->num_rows > 0): ?>
                                <?php while($subject = $subjects->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo $subject['subject_name']; ?></strong>
                                        <br>
                                        <small style="color: var(--gray);"><?php echo $subject['subject_code']; ?></small>
                                    </td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?php echo $subject['semester_name']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-gray">
                                            <?php echo $subject['section_name']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?php echo $subject['total_students']; ?></strong>
                                    </td>
                                    <td>
                                        <span style="color: var(--success); font-weight: 600;">
                                            <?php echo $subject['present_count']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="color: var(--danger); font-weight: 600;">
                                            <?php echo $subject['absent_count']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if($subject['is_marked'] > 0): ?>
                                            <span class="badge badge-success">
                                                <i class="fas fa-check-circle"></i> MARKED
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">
                                                <i class="fas fa-clock"></i> PENDING
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($subject['is_marked'] > 0): ?>
                                            <a href="view_class_attendance.php?subject_id=<?php echo $subject['subject_id']; ?>&section_id=<?php echo $subject['section_id']; ?>&date=<?php echo $selected_date; ?>" 
                                               class="action-btn btn-primary">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        <?php else: ?>
                                            <a href="mark_attendance.php?subject_id=<?php echo $subject['subject_id']; ?>&section_id=<?php echo $subject['section_id']; ?>&date=<?php echo $selected_date; ?>&teacher_id=<?php echo $teacher_id; ?>" 
                                               class="action-btn btn-success">
                                                <i class="fas fa-check"></i> Mark Now
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8">
                                        <div class="empty-state">
                                            <i class="fas fa-inbox"></i>
                                            <p>No classes assigned for the selected filters</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="card" style="margin-top: 20px;">
                <div class="card-header">
                    <h3>
                        <i class="fas fa-history"></i>
                        Recent Attendance History (Last 7 Days)
                    </h3>
                </div>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>DATE</th>
                                <th>SUBJECT</th>
                                <th>SECTION</th>
                                <th>TOTAL MARKED</th>
                                <th>PRESENT</th>
                                <th>ABSENT</th>
                                <th>ATTENDANCE %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($history->num_rows > 0): ?>
                                <?php while($record = $history->fetch_assoc()): 
                                    $attendance_percentage = $record['total_marked'] > 0 ? 
                                        round(($record['present'] / $record['total_marked']) * 100, 1) : 0;
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo date('M d, Y', strtotime($record['date'])); ?></strong>
                                        <br>
                                        <small style="color: var(--gray);">
                                            <?php echo date('l', strtotime($record['date'])); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <strong><?php echo $record['subject_name']; ?></strong>
                                        <br>
                                        <small style="color: var(--gray);"><?php echo $record['subject_code']; ?></small>
                                    </td>
                                    <td>
                                        <span class="badge badge-gray">
                                            <?php echo $record['section_name']; ?>
                                        </span>
                                    </td>
                                    <td><strong><?php echo $record['total_marked']; ?></strong></td>
                                    <td>
                                        <span style="color: var(--success); font-weight: 600;">
                                            <?php echo $record['present']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="color: var(--danger); font-weight: 600;">
                                            <?php echo $record['absent']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $attendance_percentage >= 75 ? 'badge-success' : 'badge-warning'; ?>">
                                            <?php echo $attendance_percentage; ?>%
                                        </span>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7">
                                        <div class="empty-state">
                                            <i class="fas fa-history"></i>
                                            <p>No recent attendance records found</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
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

// Check if teacher is a class teacher
$class_teacher_query = "SELECT * FROM v_class_teacher_details WHERE teacher_id = ? AND is_active = 1";
$stmt = $conn->prepare($class_teacher_query);
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$class_teacher = $stmt->get_result()->fetch_assoc();

if (!$class_teacher) {
    die("You are not assigned as a class teacher for any class.");
}

// Get filter parameters
$filter_date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$filter_date_to = $_GET['date_to'] ?? date('Y-m-d');
$filter_subject = $_GET['subject_id'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';

// Get subjects for this class
$subjects_query = "SELECT DISTINCT sub.subject_id, sub.subject_name, sub.subject_code
                   FROM subjects sub
                   WHERE sub.department_id = ? AND sub.semester_id = ?
                   ORDER BY sub.subject_name";
$stmt = $conn->prepare($subjects_query);
$stmt->bind_param("ii", $class_teacher['department_id'], $class_teacher['semester_id']);
$stmt->execute();
$subjects = $stmt->get_result();

// Build attendance query
$attendance_query = "SELECT a.*, s.full_name, s.admission_number, 
                     sub.subject_name, sub.subject_code,
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
                     AND a.attendance_date BETWEEN ? AND ?";

$params = [$filter_date_from, $filter_date_to];
$types = "ss";

if ($filter_subject !== 'all') {
    $attendance_query .= " AND a.subject_id = ?";
    $params[] = $filter_subject;
    $types .= "i";
}

if ($filter_status !== 'all') {
    $attendance_query .= " AND a.status = ?";
    $params[] = $filter_status;
    $types .= "s";
}

$attendance_query .= " ORDER BY a.attendance_date DESC, s.full_name";

$stmt = $conn->prepare($attendance_query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$attendance_records = $stmt->get_result();

// Get attendance statistics
$stats_query = "SELECT 
                COUNT(DISTINCT s.student_id) as total_students,
                COUNT(CASE WHEN a.status = 'present' THEN 1 END) as total_present,
                COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as total_absent,
                ROUND(COUNT(CASE WHEN a.status = 'present' THEN 1 END) * 100.0 / COUNT(*), 2) as attendance_rate
                FROM students s
                JOIN student_semesters ss ON s.student_id = ss.student_id
                LEFT JOIN attendance a ON s.student_id = a.student_id 
                    AND a.attendance_date BETWEEN ? AND ?
                WHERE s.department_id = {$class_teacher['department_id']}
                AND ss.semester_id = {$class_teacher['semester_id']}
                AND ss.section_id = {$class_teacher['section_id']}
                AND ss.is_active = 1";

$stmt = $conn->prepare($stats_query);
$stmt->bind_param("ss", $filter_date_from, $filter_date_to);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Attendance - College ERP</title>
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
            font-size: 2rem;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
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

        .filter-bar {
            background: var(--light-gray);
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 25px;
        }

        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--dark);
            font-size: 0.9rem;
        }

        .form-group input,
        .form-group select {
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-family: 'Outfit', sans-serif;
            font-size: 0.95rem;
            transition: all 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
        }

        .btn {
            padding: 12px 28px;
            border-radius: 15px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            transition: all 0.3s;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            box-shadow: 0 4px 15px rgba(249, 115, 22, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 25px rgba(249, 115, 22, 0.4);
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

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.3;
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
                <a href="class_teacher_dashboard.php" class="menu-item">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="class_students.php" class="menu-item">
                    <i class="fas fa-user-graduate"></i> My Students
                </a>
                <a href="class_attendance.php" class="menu-item active">
                    <i class="fas fa-calendar-check"></i> Attendance
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
                    <h1>Class Attendance</h1>
                    <p>View attendance records for your class</p>
                </div>
            </div>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total_students']; ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: var(--success);"><?php echo $stats['total_present']; ?></div>
                    <div class="stat-label">Present</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: var(--danger);"><?php echo $stats['total_absent']; ?></div>
                    <div class="stat-label">Absent</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: var(--primary);"><?php echo $stats['attendance_rate'] ?? 0; ?>%</div>
                    <div class="stat-label">Attendance Rate</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-filter"></i> Filters</h3>
                </div>
                <form method="GET" action="">
                    <div class="filter-row">
                        <div class="form-group">
                            <label>From Date</label>
                            <input type="date" name="date_from" value="<?php echo $filter_date_from; ?>">
                        </div>
                        <div class="form-group">
                            <label>To Date</label>
                            <input type="date" name="date_to" value="<?php echo $filter_date_to; ?>" max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                            <label>Subject</label>
                            <select name="subject_id">
                                <option value="all">All Subjects</option>
                                <?php 
                                $subjects->data_seek(0);
                                while($sub = $subjects->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $sub['subject_id']; ?>" <?php echo $filter_subject == $sub['subject_id'] ? 'selected' : ''; ?>>
                                        <?php echo $sub['subject_name']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="all">All</option>
                                <option value="present" <?php echo $filter_status == 'present' ? 'selected' : ''; ?>>Present</option>
                                <option value="absent" <?php echo $filter_status == 'absent' ? 'selected' : ''; ?>>Absent</option>
                            </select>
                        </div>
                        <div class="form-group" style="justify-content: flex-end;">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Apply Filters
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Attendance Records -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-list"></i> Attendance Records</h3>
                </div>

                <?php if ($attendance_records->num_rows > 0): ?>
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
                            <?php while($record = $attendance_records->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($record['attendance_date'])); ?></td>
                                <td><?php echo $record['full_name']; ?></td>
                                <td><?php echo $record['admission_number']; ?></td>
                                <td><?php echo $record['subject_name']; ?></td>
                                <td>
                                    <?php 
                                    $badge_class = $record['status'] == 'present' ? 'success' : ($record['status'] == 'absent' ? 'danger' : 'warning');
                                    ?>
                                    <span class="badge <?php echo $badge_class; ?>">
                                        <?php echo ucfirst($record['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo $record['marked_by_name']; ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h3>No Records Found</h3>
                        <p>No attendance records found for the selected filters.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
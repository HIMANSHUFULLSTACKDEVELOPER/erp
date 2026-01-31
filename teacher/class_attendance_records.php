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

// Filter parameters
$filter_date = isset($_GET['date']) ? $_GET['date'] : '';
$filter_subject = isset($_GET['subject']) ? $_GET['subject'] : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_teacher = isset($_GET['teacher']) ? $_GET['teacher'] : '';

// Build query
$where_conditions = [
    "s.department_id = {$class_teacher['department_id']}",
    "ss.semester_id = {$class_teacher['semester_id']}",
    "ss.section_id = {$class_teacher['section_id']}",
    "ss.is_active = 1"
];

if ($filter_date) {
    $where_conditions[] = "a.attendance_date = '$filter_date'";
}
if ($filter_subject) {
    $where_conditions[] = "a.subject_id = $filter_subject";
}
if ($filter_status) {
    $where_conditions[] = "a.status = '$filter_status'";
}
if ($filter_teacher) {
    $where_conditions[] = "a.marked_by = $filter_teacher";
}

$where_clause = implode(' AND ', $where_conditions);

// Get attendance records
$attendance_query = "SELECT a.*, s.full_name, s.admission_number, 
                     sub.subject_name, sub.subject_code,
                     t.full_name as marked_by_name
                     FROM attendance a
                     JOIN students s ON a.student_id = s.student_id
                     JOIN subjects sub ON a.subject_id = sub.subject_id
                     JOIN teachers t ON a.marked_by = t.teacher_id
                     JOIN student_semesters ss ON s.student_id = ss.student_id
                     WHERE $where_clause
                     ORDER BY a.attendance_date DESC, a.created_at DESC";

$attendance_records = $conn->query($attendance_query);

// Get subjects for filter
$subjects_query = "SELECT DISTINCT sub.subject_id, sub.subject_name, sub.subject_code
                   FROM subjects sub
                   WHERE sub.department_id = {$class_teacher['department_id']}
                   AND sub.semester_id = {$class_teacher['semester_id']}
                   ORDER BY sub.subject_name";
$subjects = $conn->query($subjects_query);

// Get teachers who have marked attendance for this class
$teachers_query = "SELECT DISTINCT t.teacher_id, t.full_name
                   FROM attendance a
                   JOIN teachers t ON a.marked_by = t.teacher_id
                   JOIN students s ON a.student_id = s.student_id
                   JOIN student_semesters ss ON s.student_id = ss.student_id
                   WHERE s.department_id = {$class_teacher['department_id']}
                   AND ss.semester_id = {$class_teacher['semester_id']}
                   AND ss.section_id = {$class_teacher['section_id']}
                   ORDER BY t.full_name";
$teachers = $conn->query($teachers_query);

// Get statistics
$stats_query = "SELECT 
                COUNT(*) as total_records,
                COUNT(DISTINCT a.student_id) as total_students,
                COUNT(DISTINCT a.attendance_date) as total_days,
                SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count
                FROM attendance a
                JOIN students s ON a.student_id = s.student_id
                JOIN student_semesters ss ON s.student_id = ss.student_id
                WHERE $where_clause";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Records - Class Teacher</title>
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

        .top-bar p {
            color: var(--gray);
            font-size: 0.95rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--white);
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 800;
            color: var(--primary);
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.85rem;
            margin-top: 5px;
        }

        .card {
            background: var(--white);
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }

        .filter-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
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
            padding: 12px;
            border: 2px solid var(--light-gray);
            border-radius: 10px;
            font-family: 'Outfit', sans-serif;
            transition: all 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
        }

        .filter-buttons {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-family: 'Outfit', sans-serif;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
        }

        .btn-secondary {
            background: var(--gray);
            color: var(--white);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        .table-container {
            overflow-x: auto;
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

        .table tr:hover {
            background: rgba(249, 115, 22, 0.05);
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
            color: var(--secondary);
        }

        .back-btn i {
            margin-right: 8px;
        }

        .no-records {
            text-align: center;
            padding: 40px;
            color: var(--gray);
        }

        .no-records i {
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
                <a href="class_attendance_records.php" class="menu-item active">
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
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <a href="class_teacher_dashboard.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>

            <div class="top-bar">
                <h1><i class="fas fa-list"></i> All Attendance Records</h1>
                <p>Complete attendance history for your class</p>
            </div>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($stats['total_records']); ?></div>
                    <div class="stat-label">Total Records</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total_days']; ?></div>
                    <div class="stat-label">Days Tracked</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: var(--success)"><?php echo number_format($stats['present_count']); ?></div>
                    <div class="stat-label">Present</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: var(--danger)"><?php echo number_format($stats['absent_count']); ?></div>
                    <div class="stat-label">Absent</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: var(--warning)"><?php echo number_format($stats['late_count']); ?></div>
                    <div class="stat-label">Late</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card">
                <h3 style="margin-bottom: 20px;"><i class="fas fa-filter"></i> Filter Records</h3>
                <form method="GET" action="">
                    <div class="filter-section">
                        <div class="form-group">
                            <label>Date</label>
                            <input type="date" name="date" value="<?php echo $filter_date; ?>">
                        </div>
                        <div class="form-group">
                            <label>Subject</label>
                            <select name="subject">
                                <option value="">All Subjects</option>
                                <?php while($subject = $subjects->fetch_assoc()): ?>
                                    <option value="<?php echo $subject['subject_id']; ?>" 
                                            <?php echo $filter_subject == $subject['subject_id'] ? 'selected' : ''; ?>>
                                        <?php echo $subject['subject_name']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="">All Status</option>
                                <option value="present" <?php echo $filter_status == 'present' ? 'selected' : ''; ?>>Present</option>
                                <option value="absent" <?php echo $filter_status == 'absent' ? 'selected' : ''; ?>>Absent</option>
                                <option value="late" <?php echo $filter_status == 'late' ? 'selected' : ''; ?>>Late</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Marked By</label>
                            <select name="teacher">
                                <option value="">All Teachers</option>
                                <?php while($t = $teachers->fetch_assoc()): ?>
                                    <option value="<?php echo $t['teacher_id']; ?>" 
                                            <?php echo $filter_teacher == $t['teacher_id'] ? 'selected' : ''; ?>>
                                        <?php echo $t['full_name']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="filter-buttons">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                        <a href="class_attendance_records.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Attendance Records Table -->
            <div class="card">
                <h3 style="margin-bottom: 20px;"><i class="fas fa-table"></i> Attendance Records</h3>
                <div class="table-container">
                    <?php if($attendance_records->num_rows > 0): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Student</th>
                                <th>Admission No.</th>
                                <th>Subject</th>
                                <th>Status</th>
                                <th>Marked By</th>
                                <th>Remarks</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($record = $attendance_records->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?php echo date('M d, Y', strtotime($record['attendance_date'])); ?></strong></td>
                                <td><?php echo $record['full_name']; ?></td>
                                <td><?php echo $record['admission_number']; ?></td>
                                <td><?php echo $record['subject_name']; ?></td>
                                <td>
                                    <?php 
                                    $badge_class = $record['status'] == 'present' ? 'success' : 
                                                  ($record['status'] == 'absent' ? 'danger' : 'warning');
                                    ?>
                                    <span class="badge <?php echo $badge_class; ?>">
                                        <?php echo ucfirst($record['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo $record['marked_by_name']; ?></td>
                                <td><?php echo $record['remarks'] ?: '-'; ?></td>
                                <td><?php echo date('h:i A', strtotime($record['created_at'])); ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="no-records">
                        <i class="fas fa-inbox"></i>
                        <h3>No Records Found</h3>
                        <p>No attendance records match your filter criteria.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
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

// Get filter parameters
$filter_semester = isset($_GET['semester']) ? $_GET['semester'] : '';
$filter_section = isset($_GET['section']) ? $_GET['section'] : '';
$filter_subject = isset($_GET['subject']) ? $_GET['subject'] : '';
$filter_date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filter_date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build attendance query with filters
$attendance_sql = "SELECT 
                    a.attendance_id,
                    a.attendance_date,
                    a.status,
                    a.remarks,
                    s.full_name as student_name,
                    s.admission_number,
                    sub.subject_name,
                    sub.subject_code,
                    sem.semester_name,
                    sec.section_name,
                    t.full_name as marked_by_name
                   FROM attendance a
                   JOIN students s ON a.student_id = s.student_id
                   JOIN subjects sub ON a.subject_id = sub.subject_id
                   JOIN semesters sem ON a.semester_id = sem.semester_id
                   LEFT JOIN sections sec ON a.section_id = sec.section_id
                   JOIN teachers t ON a.marked_by = t.teacher_id
                   WHERE a.department_id = ?";

$params = [$dept_id];
$types = "i";

if ($filter_semester) {
    $attendance_sql .= " AND a.semester_id = ?";
    $params[] = $filter_semester;
    $types .= "i";
}

if ($filter_section) {
    $attendance_sql .= " AND a.section_id = ?";
    $params[] = $filter_section;
    $types .= "i";
}

if ($filter_subject) {
    $attendance_sql .= " AND a.subject_id = ?";
    $params[] = $filter_subject;
    $types .= "i";
}

if ($filter_date_from) {
    $attendance_sql .= " AND a.attendance_date >= ?";
    $params[] = $filter_date_from;
    $types .= "s";
}

if ($filter_date_to) {
    $attendance_sql .= " AND a.attendance_date <= ?";
    $params[] = $filter_date_to;
    $types .= "s";
}

$attendance_sql .= " ORDER BY a.attendance_date DESC, s.admission_number LIMIT 100";

$attendance_stmt = $conn->prepare($attendance_sql);
$attendance_stmt->bind_param($types, ...$params);
$attendance_stmt->execute();
$attendance_records = $attendance_stmt->get_result();

// Get attendance statistics
$stats_sql = "SELECT 
                COUNT(*) as total_records,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count,
                ROUND(SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) / COUNT(*) * 100, 2) as attendance_percentage
              FROM attendance 
              WHERE department_id = ?";

$stats_params = [$dept_id];
$stats_types = "i";

if ($filter_semester) {
    $stats_sql .= " AND semester_id = ?";
    $stats_params[] = $filter_semester;
    $stats_types .= "i";
}

if ($filter_section) {
    $stats_sql .= " AND section_id = ?";
    $stats_params[] = $filter_section;
    $stats_types .= "i";
}

if ($filter_subject) {
    $stats_sql .= " AND subject_id = ?";
    $stats_params[] = $filter_subject;
    $stats_types .= "i";
}

if ($filter_date_from) {
    $stats_sql .= " AND attendance_date >= ?";
    $stats_params[] = $filter_date_from;
    $stats_types .= "s";
}

if ($filter_date_to) {
    $stats_sql .= " AND attendance_date <= ?";
    $stats_params[] = $filter_date_to;
    $stats_types .= "s";
}

$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->bind_param($stats_types, ...$stats_params);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();

// Get filter options
$semesters = $conn->query("SELECT semester_id, semester_name FROM semesters ORDER BY semester_number");
$sections = $conn->query("SELECT section_id, section_name FROM sections ORDER BY section_name");
$subjects = $conn->query("SELECT subject_id, subject_name, subject_code FROM subjects WHERE department_id = $dept_id ORDER BY subject_name");

// Student-wise attendance summary
$student_summary_sql = "SELECT 
                        s.student_id,
                        s.full_name,
                        s.admission_number,
                        COUNT(a.attendance_id) as total_classes,
                        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                        ROUND(SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / COUNT(a.attendance_id) * 100, 2) as attendance_percentage
                        FROM students s
                        LEFT JOIN attendance a ON s.student_id = a.student_id
                        WHERE s.department_id = ?";

$student_params = [$dept_id];
$student_types = "i";

if ($filter_semester) {
    $student_summary_sql .= " AND a.semester_id = ?";
    $student_params[] = $filter_semester;
    $student_types .= "i";
}

$student_summary_sql .= " GROUP BY s.student_id HAVING total_classes > 0 ORDER BY attendance_percentage ASC LIMIT 10";

$student_stmt = $conn->prepare($student_summary_sql);
$student_stmt->bind_param($student_types, ...$student_params);
$student_stmt->execute();
$student_summary = $student_stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Attendance - College ERP</title>
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
        .stat-number.danger { color: var(--danger); }
        .stat-number.warning { color: var(--warning); }

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

        /* Filters */
        .filters {
            background: var(--white);
            padding: 25px;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--dark);
            font-size: 0.85rem;
        }

        .form-control {
            padding: 10px 15px;
            border: 2px solid #e7e5e4;
            border-radius: 10px;
            font-family: 'Outfit', sans-serif;
            font-size: 0.9rem;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
        }

        .btn {
            padding: 12px 28px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-family: 'Outfit', sans-serif;
            font-size: 0.95rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            box-shadow: 0 4px 15px rgba(249, 115, 22, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(249, 115, 22, 0.4);
        }

        .btn-secondary {
            background: var(--gray);
            color: var(--white);
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
            grid-template-columns: 2fr 1fr;
            gap: 25px;
        }

        @media (max-width: 1200px) {
            .content-grid {
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
                    <h1>Attendance Records</h1>
                    <p>View and analyze attendance for <?php echo $hod['department_name']; ?></p>
                </div>
                <a href="hod_dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back</a>
            </div>

            <!-- Statistics -->
            <?php if ($stats['total_records'] > 0): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number primary"><?php echo $stats['total_records']; ?></div>
                    <div class="stat-label">Total Records</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number success"><?php echo $stats['present_count']; ?></div>
                    <div class="stat-label">Present</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number danger"><?php echo $stats['absent_count']; ?></div>
                    <div class="stat-label">Absent</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number warning"><?php echo $stats['late_count']; ?></div>
                    <div class="stat-label">Late</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number primary"><?php echo $stats['attendance_percentage']; ?>%</div>
                    <div class="stat-label">Attendance Rate</div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="filters">
                <h3 style="margin-bottom: 20px; font-weight: 700;"><i class="fas fa-filter" style="color: var(--primary); margin-right: 10px;"></i>Filter Records</h3>
                <form method="GET" action="">
                    <div class="filter-grid">
                        <div class="form-group">
                            <label>Semester</label>
                            <select name="semester" class="form-control">
                                <option value="">All Semesters</option>
                                <?php while($sem = $semesters->fetch_assoc()): ?>
                                    <option value="<?php echo $sem['semester_id']; ?>" 
                                        <?php echo $filter_semester == $sem['semester_id'] ? 'selected' : ''; ?>>
                                        <?php echo $sem['semester_name']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Section</label>
                            <select name="section" class="form-control">
                                <option value="">All Sections</option>
                                <?php while($sec = $sections->fetch_assoc()): ?>
                                    <option value="<?php echo $sec['section_id']; ?>"
                                        <?php echo $filter_section == $sec['section_id'] ? 'selected' : ''; ?>>
                                        Section <?php echo $sec['section_name']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Subject</label>
                            <select name="subject" class="form-control">
                                <option value="">All Subjects</option>
                                <?php while($sub = $subjects->fetch_assoc()): ?>
                                    <option value="<?php echo $sub['subject_id']; ?>"
                                        <?php echo $filter_subject == $sub['subject_id'] ? 'selected' : ''; ?>>
                                        <?php echo $sub['subject_name']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>From Date</label>
                            <input type="date" name="date_from" class="form-control" value="<?php echo $filter_date_from; ?>">
                        </div>
                        <div class="form-group">
                            <label>To Date</label>
                            <input type="date" name="date_to" class="form-control" value="<?php echo $filter_date_to; ?>">
                        </div>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply Filters</button>
                        <a href="dept_attendance.php" class="btn btn-secondary"><i class="fas fa-redo"></i> Reset</a>
                    </div>
                </form>
            </div>

            <div class="content-grid">
                <!-- Attendance Records -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-list"></i> Attendance Records</h2>
                    </div>
                    <div class="table-responsive">
                        <?php if ($attendance_records->num_rows > 0): ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Student</th>
                                        <th>Subject</th>
                                        <th>Semester</th>
                                        <th>Section</th>
                                        <th>Status</th>
                                        <th>Marked By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($record = $attendance_records->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo date('M d, Y', strtotime($record['attendance_date'])); ?></td>
                                            <td>
                                                <strong><?php echo $record['student_name']; ?></strong><br>
                                                <small style="color: var(--gray);"><?php echo $record['admission_number']; ?></small>
                                            </td>
                                            <td>
                                                <?php echo $record['subject_name']; ?><br>
                                                <span class="badge badge-info"><?php echo $record['subject_code']; ?></span>
                                            </td>
                                            <td><?php echo $record['semester_name']; ?></td>
                                            <td><?php echo $record['section_name'] ? 'Section ' . $record['section_name'] : 'N/A'; ?></td>
                                            <td>
                                                <?php 
                                                $status_class = $record['status'] == 'present' ? 'badge-success' : 
                                                               ($record['status'] == 'absent' ? 'badge-danger' : 'badge-warning');
                                                ?>
                                                <span class="badge <?php echo $status_class; ?>">
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
                                <i class="fas fa-calendar-times"></i>
                                <h3>No Records Found</h3>
                                <p>No attendance records match your filters.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Students with Low Attendance -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-exclamation-triangle"></i> Low Attendance</h2>
                    </div>
                    <?php if ($student_summary->num_rows > 0): ?>
                        <div style="max-height: 600px; overflow-y: auto;">
                            <?php while($student = $student_summary->fetch_assoc()): ?>
                                <div style="padding: 15px; border-bottom: 1px solid var(--light-gray);">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                        <strong><?php echo $student['full_name']; ?></strong>
                                        <span style="font-size: 1.5rem; font-weight: 700; color: <?php echo $student['attendance_percentage'] < 75 ? 'var(--danger)' : 'var(--warning)'; ?>">
                                            <?php echo $student['attendance_percentage']; ?>%
                                        </span>
                                    </div>
                                    <div style="font-size: 0.85rem; color: var(--gray);">
                                        <?php echo $student['admission_number']; ?> • 
                                        <?php echo $student['present_count']; ?>/<?php echo $student['total_classes']; ?> classes
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state" style="padding: 40px 20px;">
                            <i class="fas fa-check-circle" style="color: var(--success);"></i>
                            <p>All students have good attendance!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
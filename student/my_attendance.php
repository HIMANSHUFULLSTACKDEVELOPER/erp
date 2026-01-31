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
$current_sem_query = "SELECT ss.*, sem.semester_name 
                      FROM student_semesters ss 
                      JOIN semesters sem ON ss.semester_id = sem.semester_id 
                      WHERE ss.student_id = ? AND ss.is_active = 1";
$stmt = $conn->prepare($current_sem_query);
$stmt->bind_param("i", $student['student_id']);
$stmt->execute();
$current_sem = $stmt->get_result()->fetch_assoc();

if (!$selected_semester_id && $current_sem) {
    $selected_semester_id = $current_sem['semester_id'];
}

// Get all semesters
$all_semesters_query = "SELECT DISTINCT ss.semester_id, sem.semester_name, ss.academic_year, ss.is_active
                        FROM student_semesters ss 
                        JOIN semesters sem ON ss.semester_id = sem.semester_id 
                        WHERE ss.student_id = ?
                        ORDER BY sem.semester_number ASC";
$stmt = $conn->prepare($all_semesters_query);
$stmt->bind_param("i", $student['student_id']);
$stmt->execute();
$all_semesters = $stmt->get_result();

// Get selected subject
$selected_subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : null;

// Get attendance summary for selected semester
$attendance_summary = null;
if ($selected_semester_id) {
    $attendance_query = "SELECT v.*, sub.subject_id 
                        FROM v_attendance_summary v
                        JOIN subjects sub ON v.subject_code = sub.subject_code
                        WHERE v.student_id = ? AND sub.semester_id = ?";
    $stmt = $conn->prepare($attendance_query);
    $stmt->bind_param("ii", $student['student_id'], $selected_semester_id);
    $stmt->execute();
    $attendance_summary = $stmt->get_result();
}

// Get detailed attendance records for selected subject
$attendance_details = null;
if ($selected_subject_id && $selected_semester_id) {
    $details_query = "SELECT a.*, sub.subject_name, t.full_name as marked_by_name
                     FROM attendance a
                     JOIN subjects sub ON a.subject_id = sub.subject_id
                     LEFT JOIN teachers t ON a.marked_by = t.teacher_id
                     WHERE a.student_id = ? AND a.subject_id = ? AND a.semester_id = ?
                     ORDER BY a.attendance_date DESC";
    $stmt = $conn->prepare($details_query);
    $stmt->bind_param("iii", $student['student_id'], $selected_subject_id, $selected_semester_id);
    $stmt->execute();
    $attendance_details = $stmt->get_result();
}

// Calculate overall attendance for semester
$overall_attendance = null;
if ($selected_semester_id) {
    $overall_query = "SELECT 
                        COUNT(*) as total_classes,
                        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
                        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                        SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count,
                        ROUND(SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) / COUNT(*) * 100, 2) as percentage
                      FROM attendance a
                      JOIN subjects sub ON a.subject_id = sub.subject_id
                      WHERE a.student_id = ? AND sub.semester_id = ?";
    $stmt = $conn->prepare($overall_query);
    $stmt->bind_param("ii", $student['student_id'], $selected_semester_id);
    $stmt->execute();
    $overall_attendance = $stmt->get_result()->fetch_assoc();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Attendance - College ERP</title>
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
        }

        .sidebar-header {
            padding: 30px 25px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
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
            transition: all 0.3s;
            cursor: pointer;
        }

        .menu-item:hover, .menu-item.active {
            background: rgba(14, 165, 233, 0.2);
            color: var(--white);
            border-left: 4px solid var(--primary);
        }

        .menu-item i {
            margin-right: 15px;
            width: 20px;
            text-align: center;
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
        }

        .top-bar h1 {
            font-size: 2rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .logout-btn {
            background: var(--danger);
            color: var(--white);
            border: none;
            padding: 12px 24px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-family: 'Manrope', sans-serif;
            text-decoration: none;
            display: inline-block;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
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
            transition: all 0.3s;
        }

        .semester-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--white);
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(180deg, var(--primary), var(--secondary));
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 15px;
        }

        .stat-icon.success { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .stat-icon.danger { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
        .stat-icon.warning { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .stat-icon.primary { background: rgba(14, 165, 233, 0.1); color: var(--primary); }

        .stat-label {
            font-size: 0.85rem;
            color: var(--gray);
            margin-bottom: 8px;
            font-weight: 500;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--dark);
        }

        /* Cards */
        .card {
            background: var(--white);
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--light-gray);
        }

        .card-title {
            display: flex;
            align-items: center;
        }

        .card-title i {
            font-size: 1.5rem;
            color: var(--primary);
            margin-right: 15px;
        }

        .card-title h3 {
            font-size: 1.3rem;
            font-weight: 700;
        }

        .subject-card {
            background: var(--light-gray);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid transparent;
        }

        .subject-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            border-color: var(--primary);
        }

        .subject-card.selected {
            background: linear-gradient(135deg, rgba(14, 165, 233, 0.1), rgba(6, 182, 212, 0.1));
            border-color: var(--primary);
        }

        .subject-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .subject-name {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--dark);
        }

        .subject-code {
            font-size: 0.85rem;
            color: var(--gray);
            margin-top: 5px;
        }

        .badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 700;
        }

        .badge.success { background: rgba(16, 185, 129, 0.15); color: var(--success); }
        .badge.warning { background: rgba(245, 158, 11, 0.15); color: var(--warning); }
        .badge.danger { background: rgba(239, 68, 68, 0.15); color: var(--danger); }
        .badge.info { background: rgba(14, 165, 233, 0.15); color: var(--primary); }

        .attendance-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }

        .mini-stat {
            text-align: center;
            padding: 10px;
            background: var(--white);
            border-radius: 8px;
        }

        .mini-stat-value {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--dark);
        }

        .mini-stat-label {
            font-size: 0.75rem;
            color: var(--gray);
            margin-top: 5px;
        }

        .progress-bar {
            width: 100%;
            height: 10px;
            background: var(--light-gray);
            border-radius: 10px;
            overflow: hidden;
            margin-top: 15px;
        }

        .progress-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.5s;
        }

        .progress-fill.success { background: linear-gradient(90deg, var(--success), #059669); }
        .progress-fill.warning { background: linear-gradient(90deg, var(--warning), #d97706); }
        .progress-fill.danger { background: linear-gradient(90deg, var(--danger), #dc2626); }

        /* Attendance History Table */
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
            background: var(--light-gray);
        }

        .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-badge.present { background: rgba(16, 185, 129, 0.15); color: var(--success); }
        .status-badge.absent { background: rgba(239, 68, 68, 0.15); color: var(--danger); }
        .status-badge.late { background: rgba(245, 158, 11, 0.15); color: var(--warning); }

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

        .empty-state p {
            font-size: 1.1rem;
            margin-bottom: 10px;
        }

        .back-btn {
            background: var(--gray);
            color: var(--white);
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-family: 'Manrope', sans-serif;
            text-decoration: none;
            display: inline-block;
            font-size: 0.9rem;
        }

        .back-btn:hover {
            background: var(--dark);
        }

        .alert {
            background: rgba(245, 158, 11, 0.1);
            border-left: 4px solid var(--warning);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .alert i {
            color: var(--warning);
            margin-right: 10px;
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
                <a href="index.php" class="menu-item ">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="my_attendance.php" class="menu-item active">
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
                <h1>My Attendance</h1>
                <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>

            <?php if ($all_semesters->num_rows == 0): ?>
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
                    <select class="semester-select" onchange="window.location.href='my_attendance.php?semester_id=' + this.value">
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
                </div>

                <?php if ($overall_attendance && $overall_attendance['total_classes'] > 0): ?>
                    <!-- Overall Stats -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon primary">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <div class="stat-label">Total Classes</div>
                            <div class="stat-value"><?php echo $overall_attendance['total_classes']; ?></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon success">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="stat-label">Present</div>
                            <div class="stat-value"><?php echo $overall_attendance['present_count']; ?></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon danger">
                                <i class="fas fa-times-circle"></i>
                            </div>
                            <div class="stat-label">Absent</div>
                            <div class="stat-value"><?php echo $overall_attendance['absent_count']; ?></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon warning">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="stat-label">Late</div>
                            <div class="stat-value"><?php echo $overall_attendance['late_count']; ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!$selected_subject_id): ?>
                    <!-- Subject-wise Attendance -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">
                                <i class="fas fa-chart-bar"></i>
                                <h3>Subject-wise Attendance</h3>
                            </div>
                        </div>
                        <?php if ($attendance_summary && $attendance_summary->num_rows > 0): ?>
                            <?php while($att = $attendance_summary->fetch_assoc()): ?>
                                <div class="subject-card" onclick="window.location.href='my_attendance.php?semester_id=<?php echo $selected_semester_id; ?>&subject_id=<?php echo $att['subject_id']; ?>'">
                                    <div class="subject-header">
                                        <div>
                                            <div class="subject-name"><?php echo htmlspecialchars($att['subject_name']); ?></div>
                                            <div class="subject-code"><?php echo htmlspecialchars($att['subject_code']); ?></div>
                                        </div>
                                        <?php 
                                        $percentage = $att['attendance_percentage'];
                                        $badge_class = $percentage >= 75 ? 'success' : ($percentage >= 60 ? 'warning' : 'danger');
                                        ?>
                                        <span class="badge <?php echo $badge_class; ?>"><?php echo $percentage; ?>%</span>
                                    </div>
                                    <div class="attendance-stats">
                                        <div class="mini-stat">
                                            <div class="mini-stat-value"><?php echo $att['total_classes']; ?></div>
                                            <div class="mini-stat-label">Total</div>
                                        </div>
                                        <div class="mini-stat">
                                            <div class="mini-stat-value" style="color: var(--success);"><?php echo $att['present_count']; ?></div>
                                            <div class="mini-stat-label">Present</div>
                                        </div>
                                        <div class="mini-stat">
                                            <div class="mini-stat-value" style="color: var(--danger);"><?php echo $att['absent_count']; ?></div>
                                            <div class="mini-stat-label">Absent</div>
                                        </div>
                                    </div>
                                    <div class="progress-bar">
                                        <?php 
                                        $progress_class = $percentage >= 75 ? 'success' : ($percentage >= 60 ? 'warning' : 'danger');
                                        ?>
                                        <div class="progress-fill <?php echo $progress_class; ?>" style="width: <?php echo $percentage; ?>%"></div>
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
                <?php else: ?>
                    <!-- Detailed Attendance Records -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">
                                <i class="fas fa-history"></i>
                                <h3>Attendance History</h3>
                            </div>
                            <a href="my_attendance.php?semester_id=<?php echo $selected_semester_id; ?>" class="back-btn">
                                <i class="fas fa-arrow-left"></i> Back to Summary
                            </a>
                        </div>
                        <?php if ($attendance_details && $attendance_details->num_rows > 0): ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Subject</th>
                                        <th>Status</th>
                                        <th>Marked By</th>
                                        <th>Remarks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($record = $attendance_details->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo date('d M Y', strtotime($record['attendance_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($record['subject_name']); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $record['status']; ?>">
                                                <?php echo ucfirst($record['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $record['marked_by_name'] ? htmlspecialchars($record['marked_by_name']) : 'N/A'; ?></td>
                                        <td><?php echo $record['remarks'] ? htmlspecialchars($record['remarks']) : '-'; ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-times"></i>
                                <p>No attendance records found.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
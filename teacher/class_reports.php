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

// Get student-wise attendance summary
$student_attendance_query = "
    SELECT s.student_id, s.admission_number, s.full_name,
           COUNT(DISTINCT a.attendance_date) as total_classes,
           SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
           SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
           ROUND(SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / 
                 NULLIF(COUNT(DISTINCT a.attendance_date), 0), 2) as attendance_percentage
    FROM students s
    JOIN student_semesters ss ON s.student_id = ss.student_id
    LEFT JOIN attendance a ON s.student_id = a.student_id 
        AND a.semester_id = ss.semester_id
    WHERE s.department_id = {$class_teacher['department_id']}
    AND ss.semester_id = {$class_teacher['semester_id']}
    AND ss.section_id = {$class_teacher['section_id']}
    AND ss.is_active = 1
    GROUP BY s.student_id, s.admission_number, s.full_name
    ORDER BY s.full_name";

$student_attendance = $conn->query($student_attendance_query);

// Get subject-wise attendance
$subject_attendance_query = "
    SELECT sub.subject_name, sub.subject_code,
           COUNT(DISTINCT a.attendance_date) as total_classes,
           SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
           SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
           ROUND(SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / 
                 NULLIF(COUNT(*), 0), 2) as attendance_percentage
    FROM subjects sub
    LEFT JOIN attendance a ON sub.subject_id = a.subject_id
    LEFT JOIN students s ON a.student_id = s.student_id
    LEFT JOIN student_semesters ss ON s.student_id = ss.student_id 
        AND ss.semester_id = sub.semester_id
    WHERE sub.department_id = {$class_teacher['department_id']}
    AND sub.semester_id = {$class_teacher['semester_id']}
    AND (ss.section_id = {$class_teacher['section_id']} OR ss.section_id IS NULL)
    GROUP BY sub.subject_id, sub.subject_name, sub.subject_code
    ORDER BY sub.subject_name";

$subject_attendance = $conn->query($subject_attendance_query);

// Get defaulter list (students with < 75% attendance)
$defaulters_query = "
    SELECT s.student_id, s.admission_number, s.full_name, u.phone, u.email,
           COUNT(DISTINCT a.attendance_date) as total_classes,
           SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
           ROUND(SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / 
                 NULLIF(COUNT(DISTINCT a.attendance_date), 0), 2) as attendance_percentage
    FROM students s
    JOIN users u ON s.user_id = u.user_id
    JOIN student_semesters ss ON s.student_id = ss.student_id
    LEFT JOIN attendance a ON s.student_id = a.student_id 
        AND a.semester_id = ss.semester_id
    WHERE s.department_id = {$class_teacher['department_id']}
    AND ss.semester_id = {$class_teacher['semester_id']}
    AND ss.section_id = {$class_teacher['section_id']}
    AND ss.is_active = 1
    GROUP BY s.student_id, s.admission_number, s.full_name, u.phone, u.email
    HAVING attendance_percentage < 75
    ORDER BY attendance_percentage ASC";

$defaulters = $conn->query($defaulters_query);

// Get monthly attendance trend
$monthly_trend_query = "
    SELECT DATE_FORMAT(a.attendance_date, '%Y-%m') as month,
           COUNT(*) as total_records,
           SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
           ROUND(SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as percentage
    FROM attendance a
    JOIN students s ON a.student_id = s.student_id
    JOIN student_semesters ss ON s.student_id = ss.student_id
    WHERE s.department_id = {$class_teacher['department_id']}
    AND ss.semester_id = {$class_teacher['semester_id']}
    AND ss.section_id = {$class_teacher['section_id']}
    AND ss.is_active = 1
    AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(a.attendance_date, '%Y-%m')
    ORDER BY month DESC";

$monthly_trend = $conn->query($monthly_trend_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Reports - College ERP</title>
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

        .btn {
            padding: 12px 28px;
            border-radius: 15px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
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

        .btn-success {
            background: linear-gradient(135deg, var(--success), #16a34a);
            color: var(--white);
            box-shadow: 0 4px 15px rgba(34, 197, 94, 0.3);
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

        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--light-gray);
            border-radius: 10px;
            overflow: hidden;
            margin-top: 5px;
        }

        .progress-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.3s ease;
        }

        .progress-fill.high { background: var(--success); }
        .progress-fill.medium { background: var(--warning); }
        .progress-fill.low { background: var(--danger); }

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

        .report-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        @media print {
            .sidebar, .top-bar, .btn, .report-actions {
                display: none !important;
            }
            .main-content {
                margin-left: 0;
                padding: 20px;
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
                <a href="class_attendance.php" class="menu-item">
                    <i class="fas fa-calendar-check"></i> Attendance
                </a>
                <a href="class_reports.php" class="menu-item active">
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
                    <h1>Class Reports</h1>
                    <p>Comprehensive attendance and performance reports</p>
                </div>
                <div class="report-actions">
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <button onclick="exportToCSV()" class="btn btn-success">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </button>
                </div>
            </div>

            <!-- Monthly Trend -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-area"></i> Monthly Attendance Trend (Last 6 Months)</h3>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Total Records</th>
                            <th>Present</th>
                            <th>Attendance %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($trend = $monthly_trend->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?php echo date('F Y', strtotime($trend['month'] . '-01')); ?></strong></td>
                            <td><?php echo $trend['total_records']; ?></td>
                            <td><?php echo $trend['present_count']; ?></td>
                            <td>
                                <?php echo $trend['percentage']; ?>%
                                <div class="progress-bar">
                                    <div class="progress-fill <?php echo $trend['percentage'] >= 75 ? 'high' : ($trend['percentage'] >= 60 ? 'medium' : 'low'); ?>" 
                                         style="width: <?php echo $trend['percentage']; ?>%"></div>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Student-wise Report -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-user-graduate"></i> Student-wise Attendance Report</h3>
                </div>
                <?php if ($student_attendance->num_rows > 0): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Admission No.</th>
                                <th>Student Name</th>
                                <th>Total Classes</th>
                                <th>Present</th>
                                <th>Absent</th>
                                <th>Attendance %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($student = $student_attendance->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?php echo $student['admission_number']; ?></strong></td>
                                <td><?php echo $student['full_name']; ?></td>
                                <td><?php echo $student['total_classes']; ?></td>
                                <td><span class="badge success"><?php echo $student['present_count']; ?></span></td>
                                <td><span class="badge danger"><?php echo $student['absent_count']; ?></span></td>
                                <td>
                                    <?php 
                                    $percent = $student['attendance_percentage'] ?? 0;
                                    $badge_class = $percent >= 75 ? 'success' : ($percent >= 60 ? 'warning' : 'danger');
                                    ?>
                                    <span class="badge <?php echo $badge_class; ?>"><?php echo $percent; ?>%</span>
                                    <div class="progress-bar">
                                        <div class="progress-fill <?php echo $percent >= 75 ? 'high' : ($percent >= 60 ? 'medium' : 'low'); ?>" 
                                             style="width: <?php echo $percent; ?>%"></div>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h3>No Data Available</h3>
                        <p>No attendance data found for your class.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Subject-wise Report -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-book"></i> Subject-wise Attendance Report</h3>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Subject Code</th>
                            <th>Subject Name</th>
                            <th>Total Classes</th>
                            <th>Present</th>
                            <th>Absent</th>
                            <th>Attendance %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($subject = $subject_attendance->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?php echo $subject['subject_code']; ?></strong></td>
                            <td><?php echo $subject['subject_name']; ?></td>
                            <td><?php echo $subject['total_classes']; ?></td>
                            <td><span class="badge success"><?php echo $subject['present_count']; ?></span></td>
                            <td><span class="badge danger"><?php echo $subject['absent_count']; ?></span></td>
                            <td>
                                <?php 
                                $percent = $subject['attendance_percentage'] ?? 0;
                                $badge_class = $percent >= 75 ? 'success' : ($percent >= 60 ? 'warning' : 'danger');
                                ?>
                                <span class="badge <?php echo $badge_class; ?>"><?php echo $percent; ?>%</span>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Defaulter List -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-exclamation-triangle"></i> Attendance Defaulters (Below 75%)</h3>
                </div>
                <?php if ($defaulters->num_rows > 0): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Admission No.</th>
                                <th>Student Name</th>
                                <th>Phone</th>
                                <th>Email</th>
                                <th>Total Classes</th>
                                <th>Present</th>
                                <th>Attendance %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($defaulter = $defaulters->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?php echo $defaulter['admission_number']; ?></strong></td>
                                <td><?php echo $defaulter['full_name']; ?></td>
                                <td><?php echo $defaulter['phone'] ?: 'N/A'; ?></td>
                                <td><?php echo $defaulter['email']; ?></td>
                                <td><?php echo $defaulter['total_classes']; ?></td>
                                <td><span class="badge success"><?php echo $defaulter['present_count']; ?></span></td>
                                <td>
                                    <span class="badge danger"><?php echo $defaulter['attendance_percentage'] ?? 0; ?>%</span>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <h3>No Defaulters</h3>
                        <p>Great! All students have attendance above 75%.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        function exportToCSV() {
            // Create CSV content
            let csv = 'Student Attendance Report\n\n';
            csv += 'Admission No,Student Name,Total Classes,Present,Absent,Attendance %\n';
            
            // Get table data
            const table = document.querySelector('.card:nth-child(3) table');
            const rows = table.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const cols = row.querySelectorAll('td');
                if (cols.length > 0) {
                    csv += `${cols[0].textContent},${cols[1].textContent},${cols[2].textContent},${cols[3].textContent},${cols[4].textContent},${cols[5].textContent.split('%')[0]}\n`;
                }
            });
            
            // Download CSV
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'class_attendance_report.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>
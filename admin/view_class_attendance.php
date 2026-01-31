<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('admin')) {
    redirect('login.php');
}

// Get parameters
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;
$section_id = isset($_GET['section_id']) ? intval($_GET['section_id']) : 0;
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

if (!$subject_id || !$section_id) {
    redirect('admin_class_attendance_report.php');
}

// Get subject and class details
$class_query = "SELECT 
    sub.subject_id,
    sub.subject_name,
    sub.subject_code,
    sub.credits,
    sec.section_name,
    sem.semester_name,
    d.department_name,
    t.full_name as teacher_name,
    t.teacher_id,
    u.email as teacher_email
FROM subjects sub
JOIN sections sec ON sec.section_id = $section_id
JOIN semesters sem ON sub.semester_id = sem.semester_id
JOIN departments d ON sub.department_id = d.department_id
LEFT JOIN subject_teachers st ON sub.subject_id = st.subject_id AND st.section_id = $section_id
LEFT JOIN teachers t ON st.teacher_id = t.teacher_id
LEFT JOIN users u ON t.user_id = u.user_id
WHERE sub.subject_id = $subject_id";

$class_result = $conn->query($class_query);
$class_info = $class_result->fetch_assoc();

if (!$class_info) {
    redirect('admin_class_attendance_report.php');
}

// Get attendance records for this class on the selected date
$attendance_query = "SELECT 
    a.attendance_id,
    a.student_id,
    a.status,
    a.remarks,
    a.attendance_date,
    s.admission_number,
    s.full_name as student_name,
    srn.roll_number_display,
    d.department_name as student_department
FROM attendance a
JOIN students s ON a.student_id = s.student_id
JOIN departments d ON s.department_id = d.department_id
LEFT JOIN student_roll_numbers srn ON s.student_id = srn.student_id 
    AND srn.section_id = $section_id 
    AND srn.is_active = 1
WHERE a.subject_id = $subject_id
AND a.section_id = $section_id
AND DATE(a.attendance_date) = '$selected_date'
ORDER BY srn.roll_number, s.full_name";

$attendance_records = $conn->query($attendance_query);

// Calculate statistics
$total_students = 0;
$present_count = 0;
$absent_count = 0;
$late_count = 0;

$temp_result = $conn->query($attendance_query);
while ($row = $temp_result->fetch_assoc()) {
    $total_students++;
    switch ($row['status']) {
        case 'present':
            $present_count++;
            break;
        case 'absent':
            $absent_count++;
            break;
        case 'late':
            $late_count++;
            break;
    }
}

$attendance_percentage = $total_students > 0 ? round(($present_count / $total_students) * 100, 1) : 0;

// Get attendance history for this class (last 10 sessions)
$history_query = "SELECT 
    DATE(attendance_date) as date,
    COUNT(DISTINCT student_id) as total,
    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late
FROM attendance
WHERE subject_id = $subject_id
AND section_id = $section_id
GROUP BY DATE(attendance_date)
ORDER BY attendance_date DESC
LIMIT 10";
$history = $conn->query($history_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Class Attendance - <?php echo $class_info['subject_name']; ?></title>
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
            padding: 25px 30px;
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

        /* Class Info Card */
        .class-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 12px;
            color: var(--white);
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-item i {
            font-size: 1.2rem;
            opacity: 0.9;
        }

        .info-item div h4 {
            font-size: 0.85rem;
            opacity: 0.9;
            margin-bottom: 3px;
        }

        .info-item div p {
            font-size: 1rem;
            font-weight: 600;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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

        .stat-card.danger {
            border-left-color: var(--danger);
        }

        .stat-card.warning {
            border-left-color: var(--warning);
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

        .stat-card .percentage {
            font-size: 1.1rem;
            font-weight: 600;
            margin-top: 8px;
        }

        .stat-card.success .percentage {
            color: var(--success);
        }

        /* Action Bar */
        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            gap: 15px;
            flex-wrap: wrap;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
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

        .btn-secondary {
            background: var(--gray);
            color: var(--white);
        }

        .btn-secondary:hover {
            background: var(--dark);
        }

        /* Search Box */
        .search-box {
            position: relative;
        }

        .search-box input {
            padding: 10px 15px 10px 40px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            width: 300px;
        }

        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }

        /* Table Card */
        .table-card {
            background: var(--white);
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 20px;
        }

        .table-header {
            padding: 20px 25px;
            border-bottom: 1px solid var(--light-gray);
        }

        .table-header h3 {
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

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
            padding: 15px 20px;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            color: var(--gray);
        }

        td {
            padding: 15px 20px;
            border-bottom: 1px solid var(--light-gray);
        }

        tbody tr:hover {
            background: #fafafa;
        }

        .student-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .student-details h4 {
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .student-details p {
            font-size: 0.85rem;
            color: var(--gray);
        }

        .badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .badge-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .badge-danger {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .badge-info {
            background: rgba(99, 102, 241, 0.1);
            color: var(--primary);
        }

        .badge-gray {
            background: var(--light-gray);
            color: var(--gray);
        }

        /* Empty State */
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

        .empty-state h3 {
            font-size: 1.3rem;
            margin-bottom: 10px;
        }

        .empty-state p {
            font-size: 0.95rem;
        }

        /* Chart Container */
        .chart-container {
            height: 300px;
            padding: 20px;
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

            .class-info {
                grid-template-columns: 1fr;
            }

            .top-bar-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }

            .action-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .action-buttons {
                width: 100%;
            }

            .btn {
                flex: 1;
                justify-content: center;
            }

            .search-box input {
                width: 100%;
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
                <a href="index.php" class="menu-item">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="dailyattandance.php" class="menu-item">
                    <i class="fas fa-calendar-check"></i> Daily Report
                </a>
                <a href="consolidatereport.php" class="menu-item">
                    <i class="fas fa-chart-bar"></i> Consolidated Report
                </a>
                <a href="manage_students.php" class="menu-item">
                    <i class="fas fa-user-graduate"></i> Students
                </a>
                <a href="manage_teachers.php" class="menu-item">
                    <i class="fas fa-chalkboard-teacher"></i> Teachers
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-bar">
                <div class="top-bar-header">
                    <h1>
                        <i class="fas fa-clipboard-list"></i>
                        Class Attendance Details
                    </h1>
                    <?php if(isset($_GET['teacher_id'])): ?>
                    <a href="teacher_attendance_details.php?teacher_id=<?php echo $_GET['teacher_id']; ?>&date=<?php echo $selected_date; ?>" class="back-btn">
                        <i class="fas fa-arrow-left"></i> Back to Teacher Details
                    </a>
                    <?php else: ?>
                    <a href="admin_class_attendance_report.php?date=<?php echo $selected_date; ?>" class="back-btn">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                    <?php endif; ?>
                </div>

                <!-- Class Information -->
                <div class="class-info">
                    <div class="info-item">
                        <i class="fas fa-book"></i>
                        <div>
                            <h4>Subject</h4>
                            <p><?php echo $class_info['subject_name']; ?></p>
                        </div>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-code"></i>
                        <div>
                            <h4>Subject Code</h4>
                            <p><?php echo $class_info['subject_code']; ?></p>
                        </div>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-layer-group"></i>
                        <div>
                            <h4>Section</h4>
                            <p><?php echo $class_info['section_name']; ?></p>
                        </div>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-calendar-alt"></i>
                        <div>
                            <h4>Semester</h4>
                            <p><?php echo $class_info['semester_name']; ?></p>
                        </div>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-building"></i>
                        <div>
                            <h4>Department</h4>
                            <p><?php echo $class_info['department_name']; ?></p>
                        </div>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <div>
                            <h4>Teacher</h4>
                            <p><?php echo $class_info['teacher_name'] ?: 'Not Assigned'; ?></p>
                        </div>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-calendar"></i>
                        <div>
                            <h4>Date</h4>
                            <p><?php echo date('M d, Y', strtotime($selected_date)); ?></p>
                        </div>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-award"></i>
                        <div>
                            <h4>Credits</h4>
                            <p><?php echo $class_info['credits']; ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h3><?php echo $total_students; ?></h3>
                    <p>Total Students</p>
                </div>
                <div class="stat-card success">
                    <h3><?php echo $present_count; ?></h3>
                    <p>Present</p>
                    <div class="percentage"><?php echo $attendance_percentage; ?>%</div>
                </div>
                <div class="stat-card danger">
                    <h3><?php echo $absent_count; ?></h3>
                    <p>Absent</p>
                    <div class="percentage"><?php echo $total_students > 0 ? round(($absent_count/$total_students)*100, 1) : 0; ?>%</div>
                </div>
                <?php if($late_count > 0): ?>
                <div class="stat-card warning">
                    <h3><?php echo $late_count; ?></h3>
                    <p>Late</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Action Bar -->
            <div class="action-bar">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Search students...">
                </div>
                <div class="action-buttons">
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <button onclick="exportToExcel()" class="btn btn-success">
                        <i class="fas fa-file-excel"></i> Export to Excel
                    </button>
                    <a href="edit_attendance.php?subject_id=<?php echo $subject_id; ?>&section_id=<?php echo $section_id; ?>&date=<?php echo $selected_date; ?>" class="btn btn-secondary">
                        <i class="fas fa-edit"></i> Edit Attendance
                    </a>
                </div>
            </div>

            <!-- Attendance Records Table -->
            <div class="table-card">
                <div class="table-header">
                    <h3>
                        <i class="fas fa-users"></i>
                        Student Attendance Records
                    </h3>
                </div>

                <div class="table-container">
                    <table id="attendanceTable">
                        <thead>
                            <tr>
                                <th>ROLL NO.</th>
                                <th>STUDENT</th>
                                <th>ADMISSION NO.</th>
                                <th>DEPARTMENT</th>
                                <th>STATUS</th>
                                <th>REMARKS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($attendance_records->num_rows > 0): ?>
                                <?php while($record = $attendance_records->fetch_assoc()): 
                                    $initials = strtoupper(substr($record['student_name'], 0, 2));
                                ?>
                                <tr>
                                    <td>
                                        <span class="badge badge-gray">
                                            <?php echo $record['roll_number_display'] ?: 'N/A'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="student-info">
                                            <div class="student-avatar"><?php echo $initials; ?></div>
                                            <div class="student-details">
                                                <h4><?php echo $record['student_name']; ?></h4>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?php echo $record['admission_number']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $record['student_department']; ?></td>
                                    <td>
                                        <?php if($record['status'] == 'present'): ?>
                                            <span class="badge badge-success">
                                                <i class="fas fa-check-circle"></i> PRESENT
                                            </span>
                                        <?php elseif($record['status'] == 'absent'): ?>
                                            <span class="badge badge-danger">
                                                <i class="fas fa-times-circle"></i> ABSENT
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">
                                                <i class="fas fa-clock"></i> LATE
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small><?php echo $record['remarks'] ?: '-'; ?></small>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6">
                                        <div class="empty-state">
                                            <i class="fas fa-clipboard-list"></i>
                                            <h3>No Attendance Records Found</h3>
                                            <p>No attendance has been marked for this class on the selected date.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Attendance History -->
            <div class="table-card">
                <div class="table-header">
                    <h3>
                        <i class="fas fa-history"></i>
                        Attendance History (Last 10 Sessions)
                    </h3>
                </div>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>DATE</th>
                                <th>TOTAL STUDENTS</th>
                                <th>PRESENT</th>
                                <th>ABSENT</th>
                                <th>LATE</th>
                                <th>ATTENDANCE %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($history->num_rows > 0): ?>
                                <?php while($record = $history->fetch_assoc()): 
                                    $hist_percentage = $record['total'] > 0 ? 
                                        round(($record['present'] / $record['total']) * 100, 1) : 0;
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo date('M d, Y', strtotime($record['date'])); ?></strong>
                                        <br>
                                        <small style="color: var(--gray);">
                                            <?php echo date('l', strtotime($record['date'])); ?>
                                        </small>
                                    </td>
                                    <td><strong><?php echo $record['total']; ?></strong></td>
                                    <td>
                                        <span class="badge badge-success">
                                            <?php echo $record['present']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-danger">
                                            <?php echo $record['absent']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if($record['late'] > 0): ?>
                                            <span class="badge badge-warning">
                                                <?php echo $record['late']; ?>
                                            </span>
                                        <?php else: ?>
                                            <span>-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $hist_percentage >= 75 ? 'badge-success' : ($hist_percentage >= 50 ? 'badge-warning' : 'badge-danger'); ?>">
                                            <?php echo $hist_percentage; ?>%
                                        </span>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6">
                                        <div class="empty-state">
                                            <i class="fas fa-history"></i>
                                            <h3>No History Available</h3>
                                            <p>No previous attendance records found for this class.</p>
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

    <script>
        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const searchValue = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('#attendanceTable tbody tr');
            
            rows.forEach(row => {
                const studentName = row.querySelector('.student-details h4')?.textContent.toLowerCase() || '';
                const admissionNo = row.querySelectorAll('.badge-info')[0]?.textContent.toLowerCase() || '';
                const rollNo = row.querySelector('.badge-gray')?.textContent.toLowerCase() || '';
                
                if (studentName.includes(searchValue) || admissionNo.includes(searchValue) || rollNo.includes(searchValue)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });

        // Export to Excel functionality
        function exportToExcel() {
            const table = document.getElementById('attendanceTable');
            let html = table.outerHTML;
            const url = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
            const downloadLink = document.createElement("a");
            document.body.appendChild(downloadLink);
            downloadLink.href = url;
            downloadLink.download = 'attendance_<?php echo $class_info['subject_code']; ?>_<?php echo $selected_date; ?>.xls';
            downloadLink.click();
            document.body.removeChild(downloadLink);
        }

        // Print styles
        window.addEventListener('beforeprint', function() {
            document.querySelector('.sidebar').style.display = 'none';
            document.querySelector('.action-bar').style.display = 'none';
            document.querySelector('.back-btn').style.display = 'none';
        });

        window.addEventListener('afterprint', function() {
            document.querySelector('.sidebar').style.display = 'block';
            document.querySelector('.action-bar').style.display = 'flex';
            document.querySelector('.back-btn').style.display = 'inline-flex';
        });
    </script>
</body>
</html>
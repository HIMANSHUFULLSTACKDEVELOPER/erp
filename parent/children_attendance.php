<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('parent')) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

// Get parent details
$sql = "SELECT p.* FROM parents p WHERE p.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$parent = $stmt->get_result()->fetch_assoc();

// Get linked students
$students = $conn->query("SELECT s.*, d.department_name 
                         FROM parent_student ps
                         JOIN students s ON ps.student_id = s.student_id
                         JOIN departments d ON s.department_id = d.department_id
                         WHERE ps.parent_id = {$parent['parent_id']}");

// Selected student (default to first)
$selected_student_id = $_GET['student_id'] ?? null;
if (!$selected_student_id && $students->num_rows > 0) {
    $students->data_seek(0);
    $selected_student_id = $students->fetch_assoc()['student_id'];
}

// Get attendance data for selected student
$attendance_data = null;
$student_info = null;
$detailed_attendance = null;

if ($selected_student_id) {
    $student_info = $conn->query("SELECT s.*, d.department_name, c.course_name
                                  FROM students s
                                  JOIN departments d ON s.department_id = d.department_id
                                  JOIN courses c ON s.course_id = c.course_id
                                  WHERE s.student_id = $selected_student_id")->fetch_assoc();
    
    // Get attendance summary
    $attendance_data = $conn->query("SELECT * FROM v_attendance_summary 
                                    WHERE student_id = $selected_student_id 
                                    ORDER BY subject_name");
    
    // Get detailed attendance records
    $detailed_attendance = $conn->query("SELECT a.*, sub.subject_name, sub.subject_code, t.full_name as teacher_name
                                        FROM attendance a
                                        JOIN subjects sub ON a.subject_id = sub.subject_id
                                        JOIN teachers t ON a.marked_by = t.teacher_id
                                        WHERE a.student_id = $selected_student_id
                                        ORDER BY a.attendance_date DESC
                                        LIMIT 50");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Records - Parent Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #6366f1;
            --secondary: #a855f7;
            --success: #22c55e;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #1e293b;
            --gray: #64748b;
            --light-gray: #f8fafc;
            --white: #ffffff;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--light-gray);
            color: var(--dark);
        }

        /* Animations */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideIn {
            from {
                transform: translateX(-100%);
            }
            to {
                transform: translateX(0);
            }
        }

        @keyframes progressFill {
            from {
                width: 0;
            }
        }

        @keyframes bounce {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-10px);
            }
        }

        .dashboard {
            display: flex;
            min-height: 100vh;
        }

        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            border: none;
            width: 50px;
            height: 50px;
            border-radius: 12px;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.4);
            transition: all 0.3s;
        }

        .mobile-menu-toggle:active {
            transform: scale(0.95);
        }

        .mobile-menu-toggle i {
            font-size: 1.3rem;
        }

        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #1e293b 0%, #334155 100%);
            color: var(--white);
            padding: 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: transform 0.3s ease;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 35px 25px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            animation: slideIn 0.5s ease;
        }

        .parent-info {
            text-align: center;
        }

        .parent-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: rgba(255,255,255,0.25);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 700;
            margin: 0 auto 12px;
            border: 3px solid rgba(255,255,255,0.3);
            transition: all 0.3s;
        }

        .parent-avatar:hover {
            transform: scale(1.1) rotate(5deg);
        }

        .parent-name {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .parent-role {
            font-size: 0.85rem;
            opacity: 0.9;
            text-transform: capitalize;
        }

        .sidebar-menu {
            padding: 25px 0;
        }

        .menu-item {
            padding: 16px 25px;
            color: rgba(255,255,255,0.75);
            text-decoration: none;
            display: flex;
            align-items: center;
            transition: all 0.3s;
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
            transition: transform 0.3s;
        }

        .menu-item:hover::before,
        .menu-item.active::before {
            transform: scaleY(1);
        }

        .menu-item:hover, .menu-item.active {
            background: rgba(99, 102, 241, 0.2);
            color: var(--white);
            transform: translateX(5px);
        }

        .menu-item i {
            margin-right: 15px;
            width: 20px;
            text-align: center;
            transition: all 0.3s;
        }

        .menu-item:hover i {
            transform: scale(1.2);
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            animation: fadeIn 0.5s ease;
            transition: margin-left 0.3s ease;
        }

        .top-bar {
            background: var(--white);
            padding: 25px 30px;
            border-radius: 18px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            animation: fadeIn 0.6s ease;
        }

        .top-bar h1 {
            font-size: 2rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .student-selector {
            background: var(--white);
            padding: 20px 30px;
            border-radius: 18px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            animation: fadeIn 0.7s ease;
        }

        .student-selector select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid var(--light-gray);
            border-radius: 12px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1rem;
            font-weight: 600;
            color: var(--dark);
            background: var(--white);
            cursor: pointer;
            transition: all 0.3s;
        }

        .student-selector select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .student-selector select:hover {
            border-color: var(--primary);
        }

        .card {
            background: var(--white);
            padding: 30px;
            border-radius: 18px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin-bottom: 25px;
            animation: fadeIn 0.8s ease;
            transition: all 0.3s;
        }

        .card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
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
            transition: all 0.3s;
        }

        .card:hover .card-header i {
            transform: scale(1.2) rotate(10deg);
        }

        .card-header h3 {
            font-size: 1.4rem;
            font-weight: 700;
        }

        .attendance-grid {
            display: grid;
            gap: 20px;
        }

        .attendance-card {
            background: var(--light-gray);
            padding: 20px;
            border-radius: 12px;
            border-left: 4px solid var(--primary);
            transition: all 0.3s;
        }

        .attendance-card:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .attendance-card-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }

        .subject-name {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .subject-code {
            font-size: 0.85rem;
            color: var(--gray);
        }

        .attendance-stats {
            display: flex;
            gap: 15px;
            margin-top: 15px;
        }

        .stat-box {
            flex: 1;
            background: var(--white);
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            transition: all 0.3s;
        }

        .stat-box:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--gray);
            margin-bottom: 5px;
            text-transform: uppercase;
            font-weight: 600;
        }

        .stat-value {
            font-size: 1.3rem;
            font-weight: 800;
            color: var(--dark);
        }

        .progress-bar {
            width: 100%;
            height: 10px;
            background: var(--white);
            border-radius: 10px;
            overflow: hidden;
            margin-top: 10px;
        }

        .progress-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.8s ease;
            animation: progressFill 1s ease;
        }

        .progress-fill.success {
            background: linear-gradient(90deg, var(--success), #16a34a);
        }

        .progress-fill.warning {
            background: linear-gradient(90deg, var(--warning), #ea580c);
        }

        .progress-fill.danger {
            background: linear-gradient(90deg, var(--danger), #dc2626);
        }

        .badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 700;
            transition: all 0.3s;
        }

        .badge:hover {
            transform: scale(1.05);
        }

        .badge.success { background: rgba(34, 197, 94, 0.15); color: var(--success); }
        .badge.warning { background: rgba(245, 158, 11, 0.15); color: var(--warning); }
        .badge.danger { background: rgba(239, 68, 68, 0.15); color: var(--danger); }

        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
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
            padding: 16px 12px;
            border-bottom: 1px solid var(--light-gray);
        }

        .table tr {
            transition: all 0.3s;
        }

        .table tbody tr:hover {
            background: var(--light-gray);
            transform: scale(1.01);
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            transition: all 0.3s;
        }

        .status-badge:hover {
            transform: scale(1.1);
        }

        .status-present {
            background: rgba(34, 197, 94, 0.15);
            color: var(--success);
        }

        .status-absent {
            background: rgba(239, 68, 68, 0.15);
            color: var(--danger);
        }

        .status-late {
            background: rgba(245, 158, 11, 0.15);
            color: var(--warning);
        }

        .back-btn {
            background: linear-gradient(135deg, var(--gray), #475569);
            color: var(--white);
            border: none;
            padding: 12px 26px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }

        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(71, 85, 105, 0.3);
        }

        .back-btn:active {
            transform: translateY(0);
        }

        /* Sidebar Overlay for Mobile */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 999;
        }

        .sidebar-overlay.active {
            display: block;
        }

        /* Responsive Design */
        @media (max-width: 992px) {
            .top-bar h1 {
                font-size: 1.5rem;
            }

            .attendance-stats {
                flex-wrap: wrap;
            }

            .stat-box {
                min-width: calc(33.33% - 10px);
            }
        }

        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
                box-shadow: 4px 0 15px rgba(0,0,0,0.3);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 20px 15px;
                padding-top: 80px;
            }

            .top-bar {
                flex-direction: column;
                gap: 15px;
                padding: 20px;
                text-align: center;
            }

            .top-bar h1 {
                font-size: 1.3rem;
            }

            .back-btn {
                width: 100%;
                text-align: center;
            }

            .student-selector {
                padding: 15px 20px;
            }

            .card {
                padding: 20px;
            }

            .card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .card-header h3 {
                font-size: 1.1rem;
            }

            .attendance-card {
                padding: 15px;
            }

            .attendance-card-header {
                flex-direction: column;
                gap: 10px;
            }

            .subject-name {
                font-size: 1rem;
            }

            .attendance-stats {
                flex-direction: column;
                gap: 10px;
            }

            .stat-box {
                width: 100%;
            }

            .table-container {
                margin: 0 -20px;
                padding: 0 20px;
            }

            .table th,
            .table td {
                padding: 10px 8px;
                font-size: 0.85rem;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 15px 10px;
                padding-top: 75px;
            }

            .top-bar {
                padding: 15px;
            }

            .top-bar h1 {
                font-size: 1.2rem;
            }

            .student-selector {
                padding: 12px 15px;
            }

            .student-selector select {
                font-size: 0.9rem;
                padding: 10px 12px;
            }

            .card {
                padding: 15px;
            }

            .card-header i {
                font-size: 1.2rem;
                margin-right: 10px;
            }

            .card-header h3 {
                font-size: 1rem;
            }

            .attendance-card {
                padding: 12px;
            }

            .subject-name {
                font-size: 0.95rem;
            }

            .subject-code {
                font-size: 0.75rem;
            }

            .stat-label {
                font-size: 0.7rem;
            }

            .stat-value {
                font-size: 1.1rem;
            }

            .badge {
                padding: 4px 10px;
                font-size: 0.75rem;
            }

            .table th,
            .table td {
                padding: 8px 6px;
                font-size: 0.8rem;
            }

            .status-badge {
                font-size: 0.7rem;
                padding: 3px 8px;
            }
        }
    </style>
</head>
<body>
    <button class="mobile-menu-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="sidebar-overlay" onclick="toggleSidebar()"></div>

    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="parent-info">
                    <div class="parent-avatar"><?php echo strtoupper(substr($parent['full_name'], 0, 1)); ?></div>
                    <div class="parent-name"><?php echo $parent['full_name']; ?></div>
                    <div class="parent-role"><?php echo ucfirst($parent['relation']); ?></div>
                </div>
            </div>
            <nav class="sidebar-menu">
                <a href="index.php" class="menu-item">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="children_attendance.php" class="menu-item active">
                    <i class="fas fa-calendar-check"></i> Attendance
                </a>
                <a href="semester_history.php" class="menu-item">
                    <i class="fas fa-history"></i> Semester History
                </a>
                <a href="children_subjects.php" class="menu-item">
                    <i class="fas fa-book"></i> Subjects
                </a>
                <a href="parent_profile.php" class="menu-item">
                    <i class="fas fa-user"></i> Profile
                </a>
                <a href="parent_settings.php" class="menu-item">
                    <i class="fas fa-cog"></i> Settings
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-bar">
                <h1>Attendance Records</h1>
                <a href="index.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            </div>

            <?php if ($students->num_rows > 1): ?>
            <!-- Student Selector -->
            <div class="student-selector">
                <select onchange="window.location.href='children_attendance.php?student_id=' + this.value">
                    <?php 
                    $students->data_seek(0);
                    while($student = $students->fetch_assoc()): 
                    ?>
                    <option value="<?php echo $student['student_id']; ?>" 
                            <?php echo ($student['student_id'] == $selected_student_id) ? 'selected' : ''; ?>>
                        <?php echo $student['full_name']; ?> (<?php echo $student['admission_number']; ?>)
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <?php endif; ?>

            <?php if ($student_info): ?>
            <!-- Student Info Card -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-user-graduate"></i>
                    <h3><?php echo $student_info['full_name']; ?></h3>
                </div>
                <p><strong>Admission Number:</strong> <?php echo $student_info['admission_number']; ?></p>
                <p><strong>Department:</strong> <?php echo $student_info['department_name']; ?></p>
                <p><strong>Course:</strong> <?php echo $student_info['course_name']; ?></p>
            </div>

            <!-- Attendance Summary by Subject -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-chart-pie"></i>
                    <h3>Subject-wise Attendance Summary</h3>
                </div>
                <?php if ($attendance_data && $attendance_data->num_rows > 0): ?>
                <div class="attendance-grid">
                    <?php while($att = $attendance_data->fetch_assoc()): 
                        $percentage = $att['attendance_percentage'];
                        $badge_class = $percentage >= 75 ? 'success' : ($percentage >= 60 ? 'warning' : 'danger');
                        $progress_class = $percentage >= 75 ? 'success' : ($percentage >= 60 ? 'warning' : 'danger');
                    ?>
                    <div class="attendance-card">
                        <div class="attendance-card-header">
                            <div>
                                <div class="subject-name"><?php echo $att['subject_name']; ?></div>
                                <div class="subject-code"><?php echo $att['subject_code']; ?></div>
                            </div>
                            <span class="badge <?php echo $badge_class; ?>"><?php echo $percentage; ?>%</span>
                        </div>
                        
                        <div class="attendance-stats">
                            <div class="stat-box">
                                <div class="stat-label">Total Classes</div>
                                <div class="stat-value"><?php echo $att['total_classes']; ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-label">Present</div>
                                <div class="stat-value" style="color: var(--success);"><?php echo $att['present_count']; ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-label">Absent</div>
                                <div class="stat-value" style="color: var(--danger);"><?php echo $att['absent_count']; ?></div>
                            </div>
                        </div>
                        
                        <div class="progress-bar">
                            <div class="progress-fill <?php echo $progress_class; ?>" style="width: <?php echo $percentage; ?>%"></div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
                <?php else: ?>
                <p style="text-align: center; color: var(--gray); padding: 40px;">No attendance records found.</p>
                <?php endif; ?>
            </div>

            <!-- Detailed Attendance History -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-list"></i>
                    <h3>Recent Attendance History</h3>
                </div>
                <?php if ($detailed_attendance && $detailed_attendance->num_rows > 0): ?>
                <div class="table-container">
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
                            <?php while($record = $detailed_attendance->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo date('d M Y', strtotime($record['attendance_date'])); ?></td>
                                <td>
                                    <strong><?php echo $record['subject_name']; ?></strong><br>
                                    <small style="color: var(--gray);"><?php echo $record['subject_code']; ?></small>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $record['status']; ?>">
                                        <?php echo ucfirst($record['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo $record['teacher_name']; ?></td>
                                <td><?php echo $record['remarks'] ?? '-'; ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p style="text-align: center; color: var(--gray); padding: 40px;">No attendance history available.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
        }

        // Close sidebar when clicking on menu items on mobile
        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    toggleSidebar();
                }
            });
        });
    </script>
</body>
</html>
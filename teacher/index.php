<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('teacher')) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

// Get teacher details
$sql = "SELECT t.*, d.department_name 
        FROM teachers t 
        JOIN departments d ON t.department_id = d.department_id 
        WHERE t.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();

// Get current academic year (you may need to adjust this based on your system)
$current_academic_year = date('Y') . '-' . (date('Y') + 1);

// Get assigned subjects
$assigned_subjects = $conn->query("SELECT DISTINCT sub.subject_name, sub.subject_code, sub.subject_id,
                                   sem.semester_name, sem.semester_id, sec.section_name, sec.section_id, 
                                   st.academic_year,
                                   COUNT(DISTINCT ss.student_id) as student_count
                                   FROM subject_teachers st
                                   JOIN subjects sub ON st.subject_id = sub.subject_id
                                   JOIN semesters sem ON st.semester_id = sem.semester_id
                                   LEFT JOIN sections sec ON st.section_id = sec.section_id
                                   LEFT JOIN student_semesters ss ON ss.semester_id = st.semester_id 
                                        AND ss.section_id = st.section_id AND ss.is_active = 1
                                   WHERE st.teacher_id = {$teacher['teacher_id']}
                                   GROUP BY st.subject_id, st.semester_id, st.section_id");

// Get selected date or default to today
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$todays_attendance = $conn->query("SELECT sub.subject_name, sem.semester_name, sec.section_name,
                                   COUNT(DISTINCT ss.student_id) as total_students,
                                   COUNT(DISTINCT CASE WHEN a.status = 'present' THEN a.student_id END) as present_count,
                                   COUNT(DISTINCT CASE WHEN a.status = 'absent' THEN a.student_id END) as absent_count,
                                   COUNT(DISTINCT a.student_id) as marked_students
                                   FROM subject_teachers st
                                   JOIN subjects sub ON st.subject_id = sub.subject_id
                                   JOIN semesters sem ON st.semester_id = sem.semester_id
                                   LEFT JOIN sections sec ON st.section_id = sec.section_id
                                   LEFT JOIN student_semesters ss ON ss.semester_id = st.semester_id 
                                        AND ss.section_id = st.section_id AND ss.is_active = 1
                                   LEFT JOIN attendance a ON a.subject_id = st.subject_id 
                                        AND a.attendance_date = '$selected_date' AND a.marked_by = {$teacher['teacher_id']}
                                        AND a.student_id = ss.student_id
                                   WHERE st.teacher_id = {$teacher['teacher_id']}
                                   GROUP BY st.subject_id, st.semester_id, st.section_id");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard - College ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #8b5cf6;
            --secondary: #ec4899;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #18181b;
            --gray: #71717a;
            --light-gray: #fafafa;
            --white: #ffffff;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DM Sans', sans-serif;
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
            background: var(--dark);
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

        .teacher-info {
            text-align: center;
        }

        .teacher-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0 auto 12px;
            border: 3px solid rgba(255,255,255,0.3);
        }

        .teacher-name {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .teacher-designation {
            font-size: 0.85rem;
            opacity: 0.9;
        }

        .sidebar-menu {
            padding: 25px 0;
        }

        .menu-item {
            padding: 15px 25px;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            display: flex;
            align-items: center;
            transition: all 0.3s;
            cursor: pointer;
            border-left: 3px solid transparent;
        }

        .menu-item:hover, .menu-item.active {
            background: rgba(139, 92, 246, 0.1);
            color: var(--white);
            border-left-color: var(--primary);
        }

        .menu-item i {
            margin-right: 15px;
            width: 20px;
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
            padding: 25px 30px;
            border-radius: 20px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .top-bar h1 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .logout-btn {
            background: linear-gradient(135deg, var(--danger), #dc2626);
            color: var(--white);
            border: none;
            padding: 12px 28px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-family: 'DM Sans', sans-serif;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            padding: 25px;
            border-radius: 20px;
            color: var(--white);
            box-shadow: 0 4px 20px rgba(139, 92, 246, 0.3);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -30%;
            width: 200px;
            height: 200px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 5px;
            position: relative;
            z-index: 1;
        }

        .stat-label {
            font-size: 0.95rem;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        /* Cards */
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
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--light-gray);
        }

        .card-header i {
            font-size: 1.5rem;
            color: var(--primary);
            margin-right: 15px;
        }

        .card-header h3 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.4rem;
            font-weight: 700;
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
            letter-spacing: 0.5px;
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

        .badge.primary { 
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.2), rgba(236, 72, 153, 0.2)); 
            color: var(--primary); 
        }
        .badge.success { background: rgba(16, 185, 129, 0.15); color: var(--success); }
        .badge.warning { background: rgba(245, 158, 11, 0.15); color: var(--warning); }
        .badge.danger { background: rgba(239, 68, 68, 0.15); color: var(--danger); }
        .badge.info { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }

        .action-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
            font-size: 0.85rem;
            text-decoration: none;
            display: inline-block;
            color: var(--white);
        }

        .action-btn.primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.4);
        }

        /* Attendance Stats Display */
        .attendance-stats {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .stat-badge {
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .stat-badge i {
            font-size: 0.9rem;
        }

        .stat-badge.total {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }

        .stat-badge.present {
            background: rgba(16, 185, 129, 0.15);
            color: var(--success);
        }

        .stat-badge.absent {
            background: rgba(239, 68, 68, 0.15);
            color: var(--danger);
        }

        .stat-badge.percentage {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.2), rgba(236, 72, 153, 0.2));
            color: var(--primary);
            font-weight: 700;
        }

        /* Date Picker Section */
        .date-filter {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            padding: 20px;
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.1), rgba(236, 72, 153, 0.1));
            border-radius: 15px;
            border: 2px solid rgba(139, 92, 246, 0.2);
        }

        .date-filter label {
            font-weight: 600;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .date-filter label i {
            color: var(--primary);
            font-size: 1.2rem;
        }

        .date-filter input[type="date"] {
            padding: 10px 15px;
            border: 2px solid rgba(139, 92, 246, 0.3);
            border-radius: 10px;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.95rem;
            color: var(--dark);
            background: var(--white);
            transition: all 0.3s;
            cursor: pointer;
        }

        .date-filter input[type="date"]:hover {
            border-color: var(--primary);
        }

        .date-filter input[type="date"]:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        .date-filter button {
            padding: 10px 20px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .date-filter button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.4);
        }

        .date-filter .today-btn {
            padding: 10px 20px;
            background: rgba(139, 92, 246, 0.15);
            color: var(--primary);
            border: 2px solid var(--primary);
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .date-filter .today-btn:hover {
            background: var(--primary);
            color: var(--white);
        }

        .selected-date-display {
            margin-left: auto;
            padding: 10px 20px;
            background: var(--white);
            border-radius: 10px;
            font-weight: 600;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .selected-date-display i {
            font-size: 1.1rem;
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="teacher-info">
                    <div class="teacher-avatar"><?php echo strtoupper(substr($teacher['full_name'], 0, 1)); ?></div>
                    <div class="teacher-name"><?php echo $teacher['full_name']; ?></div>
                    <div class="teacher-designation"><?php echo $teacher['designation'] ?? 'Faculty Member'; ?></div>
                </div>
            </div>
            <nav class="sidebar-menu">
                <a href="index.php" class="menu-item active">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="mark_attendance.php" class="menu-item">
                    <i class="fas fa-calendar-check"></i> Mark Attendance
                </a>
                <a href="view_student_attendance.php" class="menu-item">
                    <i class="fas fa-users"></i> Attendance records 
                </a>
               
                  <a href="view_attendance_by_date.php" class="menu-item">
                    <i class="fas fa-users"></i> view attendance date
                </a>
                   <a href="view_attendance_by_class.php" class="menu-item">
                    <i class="fas fa-users"></i> view attendance class
                </a>

                   <a href="today_attadance.php" class="menu-item">
                    <i class="fas fa-users"></i> Day attendance 
                </a>
                <a href="my_classes.php" class="menu-item">
                    <i class="fas fa-chalkboard"></i> My Classes
                </a>
                <a href="view_students.php" class="menu-item">
                    <i class="fas fa-users"></i> Students
                </a>
                <a href="managestudentt.php" class="menu-item">
                    <i class="fas fa-users"></i> manage Students
                </a>
                <a href="teacher_profile.php" class="menu-item">
                    <i class="fas fa-user"></i> Profile
                </a>
                <a href="class_teacher_dashboard.php" class="menu-item">
                    <i class="fas fa-home"></i> class teacher 
                </a>
                <a href="settings.php" class="menu-item">
                    <i class="fas fa-cog"></i> Settings
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-bar">
                <h1>Teacher Dashboard</h1>
                <a href="../logout.php"><button class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</button></a>
            </div>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $assigned_subjects->num_rows; ?></div>
                    <div class="stat-label">Assigned Subjects</div>
                </div>
                <div class="stat-card" style="background: linear-gradient(135deg, #10b981, #059669);">
                    <div class="stat-number"><?php echo $teacher['department_name']; ?></div>
                    <div class="stat-label">Department</div>
                </div>
            </div>

            <!-- Assigned Subjects -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-book-open"></i>
                    <h3>My Subjects</h3>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Subject Code</th>
                            <th>Subject Name</th>
                            <th>Semester</th>
                            <th>Section</th>
                            <th>Students</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $assigned_subjects->data_seek(0); // Reset pointer
                        while($subject = $assigned_subjects->fetch_assoc()): 
                            // Build the Mark Attendance URL with all necessary parameters
                            $attendance_url = "mark_attendance.php?academic_year=" . urlencode($subject['academic_year']) . 
                                            "&subject_id=" . urlencode($subject['subject_id']) . 
                                            "&semester_id=" . urlencode($subject['semester_id']) . 
                                            "&date=" . date('Y-m-d');
                            
                            if (!empty($subject['section_id'])) {
                                $attendance_url .= "&section_id=" . urlencode($subject['section_id']);
                            }
                        ?>
                        <tr>
                            <td><span class="badge primary"><?php echo $subject['subject_code']; ?></span></td>
                            <td><?php echo $subject['subject_name']; ?></td>
                            <td><?php echo $subject['semester_name']; ?></td>
                            <td><?php echo $subject['section_name'] ?? 'All'; ?></td>
                            <td><span class="badge success"><?php echo $subject['student_count']; ?> Students</span></td>
                            <td>
                                <a href="<?php echo $attendance_url; ?>" class="action-btn primary">
                                    <i class="fas fa-calendar-check"></i> Mark Attendance
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Today's Attendance -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-calendar-day"></i>
                    <h3>Attendance Status - <?php echo date('F j, Y', strtotime($selected_date)); ?></h3>
                </div>

                <!-- Date Filter Section -->
                <form method="GET" action="" class="date-filter">
                    <label for="attendance-date">
                        <i class="fas fa-calendar-alt"></i>
                        Select Date:
                    </label>
                    <input 
                        type="date" 
                        id="attendance-date" 
                        name="date" 
                        value="<?php echo $selected_date; ?>"
                        max="<?php echo date('Y-m-d'); ?>"
                    >
                    <button type="submit">
                        <i class="fas fa-search"></i>
                        View Attendance
                    </button>
                    <?php if ($selected_date !== date('Y-m-d')): ?>
                    <a href="?date=<?php echo date('Y-m-d'); ?>" class="today-btn" style="text-decoration: none;">
                        <i class="fas fa-calendar-day"></i>
                        Today
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($selected_date === date('Y-m-d')): ?>
                    <div class="selected-date-display">
                        <i class="fas fa-check-circle"></i>
                        Viewing Today's Attendance
                    </div>
                    <?php endif; ?>
                </form>

                <table class="table">
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>Semester</th>
                            <th>Section</th>
                            <th>Attendance Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($att = $todays_attendance->fetch_assoc()): 
                            $attendance_percentage = $att['total_students'] > 0 
                                ? round(($att['present_count'] / $att['total_students']) * 100, 1) 
                                : 0;
                        ?>
                        <tr>
                            <td><?php echo $att['subject_name']; ?></td>
                            <td><?php echo $att['semester_name']; ?></td>
                            <td><?php echo $att['section_name'] ?? 'All'; ?></td>
                            <td>
                                <?php if ($att['marked_students'] > 0): ?>
                                    <div class="attendance-stats">
                                        <span class="stat-badge total">
                                            <i class="fas fa-users"></i>
                                            Total: <?php echo $att['total_students']; ?>
                                        </span>
                                        <span class="stat-badge present">
                                            <i class="fas fa-check-circle"></i>
                                            Present: <?php echo $att['present_count']; ?>
                                        </span>
                                        <span class="stat-badge absent">
                                            <i class="fas fa-times-circle"></i>
                                            Absent: <?php echo $att['absent_count']; ?>
                                        </span>
                                        <span class="stat-badge percentage">
                                            <i class="fas fa-chart-line"></i>
                                            <?php echo $attendance_percentage; ?>%
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <span class="badge warning">
                                        <i class="fas fa-exclamation-triangle"></i> No Attendance Marked
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>
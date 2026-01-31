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

// Get selected semester
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

// Get subjects for selected semester
$subjects = null;
$total_credits = 0;
if ($selected_semester_id) {
    $subjects_query = "SELECT sub.*, t.full_name as teacher_name, t.qualification, t.designation
                      FROM subjects sub 
                      LEFT JOIN subject_teachers st ON sub.subject_id = st.subject_id 
                         AND st.semester_id = ?
                      LEFT JOIN teachers t ON st.teacher_id = t.teacher_id
                      WHERE sub.department_id = ? AND sub.semester_id = ?
                      ORDER BY sub.subject_name";
    $stmt = $conn->prepare($subjects_query);
    $stmt->bind_param("iii", $selected_semester_id, $student['department_id'], $selected_semester_id);
    $stmt->execute();
    $subjects = $stmt->get_result();
    
    // Calculate total credits
    if ($subjects->num_rows > 0) {
        $subjects->data_seek(0);
        while($sub = $subjects->fetch_assoc()) {
            $total_credits += $sub['credits'];
        }
        $subjects->data_seek(0);
    }
}

// Get attendance summary for subjects
$attendance_data = [];
if ($selected_semester_id) {
    $att_query = "SELECT v.*, sub.subject_id 
                  FROM v_attendance_summary v
                  JOIN subjects sub ON v.subject_code = sub.subject_code
                  WHERE v.student_id = ? AND sub.semester_id = ?";
    $stmt = $conn->prepare($att_query);
    $stmt->bind_param("ii", $student['student_id'], $selected_semester_id);
    $stmt->execute();
    $att_result = $stmt->get_result();
    while($att = $att_result->fetch_assoc()) {
        $attendance_data[$att['subject_id']] = $att;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Subjects - College ERP</title>
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

        /* Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            background: rgba(14, 165, 233, 0.1);
            color: var(--primary);
        }

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

        /* Subject Cards */
        .subjects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }

        .subject-card {
            background: var(--white);
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            transition: all 0.3s;
        }

        .subject-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }

        .subject-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            padding: 25px;
            position: relative;
        }

        .subject-code-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background: rgba(255,255,255,0.2);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            backdrop-filter: blur(10px);
        }

        .subject-name {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .subject-credits {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .subject-body {
            padding: 25px;
        }

        .info-row {
            display: flex;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid var(--light-gray);
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--light-gray);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: var(--primary);
        }

        .info-content {
            flex: 1;
        }

        .info-label {
            font-size: 0.8rem;
            color: var(--gray);
            margin-bottom: 4px;
        }

        .info-value {
            font-size: 1rem;
            font-weight: 600;
            color: var(--dark);
        }

        .attendance-mini {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 2px solid var(--light-gray);
        }

        .attendance-mini-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .attendance-mini-label {
            font-size: 0.85rem;
            color: var(--gray);
            font-weight: 600;
        }

        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
        }

        .badge.success { background: rgba(16, 185, 129, 0.15); color: var(--success); }
        .badge.warning { background: rgba(245, 158, 11, 0.15); color: var(--warning); }
        .badge.danger { background: rgba(239, 68, 68, 0.15); color: var(--danger); }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--light-gray);
            border-radius: 10px;
            overflow: hidden;
            margin-top: 8px;
        }

        .progress-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.5s;
        }

        .progress-fill.success { background: linear-gradient(90deg, var(--success), #059669); }
        .progress-fill.warning { background: linear-gradient(90deg, var(--warning), #d97706); }
        .progress-fill.danger { background: linear-gradient(90deg, var(--danger), #dc2626); }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray);
            background: var(--white);
            border-radius: 15px;
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
                <a href="my_attendance.php" class="menu-item">
                    <i class="fas fa-calendar-check"></i> My Attendance
                </a> <a href="detail_attandance.php" class="menu-item">
                    <i class="fas fa-calendar-check"></i> My Attendance Detail
                </a> 
                 <a href="totalday_attandance.php" class="menu-item">
                    <i class="fas fa-calendar-check"></i>  Monthly attandace report 
                </a>
                <a href="my_subjects.php" class="menu-item active" >
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
                <h1>My Subjects</h1>
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
                    <select class="semester-select" onchange="window.location.href='my_subjects.php?semester_id=' + this.value">
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

                <?php if ($subjects && $subjects->num_rows > 0): ?>
                    <!-- Stats -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-book"></i>
                            </div>
                            <div class="stat-label">Total Subjects</div>
                            <div class="stat-value"><?php echo $subjects->num_rows; ?></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-graduation-cap"></i>
                            </div>
                            <div class="stat-label">Total Credits</div>
                            <div class="stat-value"><?php echo $total_credits; ?></div>
                        </div>
                    </div>

                    <!-- Subjects Grid -->
                    <div class="subjects-grid">
                        <?php 
                        $subjects->data_seek(0);
                        while($subject = $subjects->fetch_assoc()): 
                            $att_data = isset($attendance_data[$subject['subject_id']]) ? $attendance_data[$subject['subject_id']] : null;
                        ?>
                            <div class="subject-card">
                                <div class="subject-header">
                                    <span class="subject-code-badge"><?php echo htmlspecialchars($subject['subject_code']); ?></span>
                                    <div class="subject-name"><?php echo htmlspecialchars($subject['subject_name']); ?></div>
                                    <div class="subject-credits">
                                        <i class="fas fa-star"></i> <?php echo $subject['credits']; ?> Credits
                                    </div>
                                </div>
                                <div class="subject-body">
                                    <div class="info-row">
                                        <div class="info-icon">
                                            <i class="fas fa-chalkboard-teacher"></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Faculty</div>
                                            <div class="info-value">
                                                <?php echo $subject['teacher_name'] ? htmlspecialchars($subject['teacher_name']) : 'Not Assigned'; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php if ($subject['designation']): ?>
                                    <div class="info-row">
                                        <div class="info-icon">
                                            <i class="fas fa-id-badge"></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Designation</div>
                                            <div class="info-value"><?php echo htmlspecialchars($subject['designation']); ?></div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($subject['qualification']): ?>
                                    <div class="info-row">
                                        <div class="info-icon">
                                            <i class="fas fa-certificate"></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Qualification</div>
                                            <div class="info-value"><?php echo htmlspecialchars($subject['qualification']); ?></div>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($att_data): ?>
                                    <div class="attendance-mini">
                                        <div class="attendance-mini-header">
                                            <span class="attendance-mini-label">
                                                <i class="fas fa-chart-line"></i> Attendance
                                            </span>
                                            <?php 
                                            $percentage = $att_data['attendance_percentage'];
                                            $badge_class = $percentage >= 75 ? 'success' : ($percentage >= 60 ? 'warning' : 'danger');
                                            ?>
                                            <span class="badge <?php echo $badge_class; ?>"><?php echo $percentage; ?>%</span>
                                        </div>
                                        <div style="font-size: 0.85rem; color: var(--gray); margin-bottom: 8px;">
                                            <?php echo $att_data['present_count']; ?> / <?php echo $att_data['total_classes']; ?> classes
                                        </div>
                                        <div class="progress-bar">
                                            <?php 
                                            $progress_class = $percentage >= 75 ? 'success' : ($percentage >= 60 ? 'warning' : 'danger');
                                            ?>
                                            <div class="progress-fill <?php echo $progress_class; ?>" style="width: <?php echo $percentage; ?>%"></div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-book"></i>
                        <p>No subjects found for this semester.</p>
                        <small>Please contact your administrator if this is an error.</small>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
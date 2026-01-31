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

// Get assigned subjects with detailed information
$classes_sql = "SELECT DISTINCT 
                st.subject_id, st.semester_id, st.section_id,
                sub.subject_name, sub.subject_code, sub.credits,
                sem.semester_name, sem.semester_number,
                sec.section_name,
                st.academic_year,
                d.department_name,
                COUNT(DISTINCT ss.student_id) as student_count,
                COUNT(DISTINCT a.attendance_date) as total_classes_conducted,
                (SELECT COUNT(DISTINCT attendance_date) 
                 FROM attendance 
                 WHERE subject_id = st.subject_id 
                 AND semester_id = st.semester_id 
                 AND (section_id = st.section_id OR (section_id IS NULL AND st.section_id IS NULL))
                 AND marked_by = {$teacher['teacher_id']}) as my_classes_conducted
                FROM subject_teachers st
                JOIN subjects sub ON st.subject_id = sub.subject_id
                JOIN semesters sem ON st.semester_id = sem.semester_id
                JOIN departments d ON sub.department_id = d.department_id
                LEFT JOIN sections sec ON st.section_id = sec.section_id
                LEFT JOIN student_semesters ss ON ss.semester_id = st.semester_id 
                     AND (ss.section_id = st.section_id OR (ss.section_id IS NULL AND st.section_id IS NULL))
                     AND ss.is_active = 1
                LEFT JOIN attendance a ON a.subject_id = st.subject_id 
                     AND a.semester_id = st.semester_id
                     AND (a.section_id = st.section_id OR (a.section_id IS NULL AND st.section_id IS NULL))
                WHERE st.teacher_id = ?
                GROUP BY st.subject_id, st.semester_id, st.section_id
                ORDER BY sem.semester_number, sub.subject_name";

$stmt = $conn->prepare($classes_sql);
$stmt->bind_param("i", $teacher['teacher_id']);
$stmt->execute();
$classes = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Classes - College ERP</title>
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
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 30px 25px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
        }

        .logo {
            text-align: center;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--white);
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

        .back-btn {
            background: linear-gradient(135deg, var(--gray), #52525b);
            color: var(--white);
            border: none;
            padding: 12px 28px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }

        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.2);
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

        .stat-card.green {
            background: linear-gradient(135deg, #10b981, #059669);
            box-shadow: 0 4px 20px rgba(16, 185, 129, 0.3);
        }

        .stat-card.orange {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            box-shadow: 0 4px 20px rgba(245, 158, 11, 0.3);
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

        /* Class Cards Grid */
        .classes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
        }

        .class-card {
            background: var(--white);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            transition: all 0.3s;
        }

        .class-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }

        .class-card-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            padding: 25px;
            color: var(--white);
        }

        .class-code {
            font-size: 0.85rem;
            opacity: 0.9;
            margin-bottom: 8px;
            font-weight: 600;
            letter-spacing: 1px;
        }

        .class-name {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 12px;
            font-family: 'Space Grotesk', sans-serif;
        }

        .class-details {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            font-size: 0.9rem;
        }

        .class-detail-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .class-detail-item i {
            font-size: 0.9rem;
        }

        .class-card-body {
            padding: 25px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid var(--light-gray);
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .info-value {
            font-weight: 600;
            color: var(--dark);
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
        .badge.info { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }

        .class-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .action-btn {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-size: 0.9rem;
            text-decoration: none;
            text-align: center;
            display: inline-block;
        }

        .action-btn.primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
        }

        .action-btn.outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.4);
        }

        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: var(--gray);
            background: var(--white);
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .empty-state i {
            font-size: 5rem;
            margin-bottom: 20px;
            opacity: 0.3;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 10px;
            font-family: 'Space Grotesk', sans-serif;
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="logo">College ERP</div>
            </div>
            <nav class="sidebar-menu">
                <a href="index.php" class="menu-item">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="mark_attendance.php" class="menu-item">
                    <i class="fas fa-calendar-check"></i> Mark Attendance
                </a>
                <a href="my_classes.php" class="menu-item active">
                    <i class="fas fa-chalkboard"></i> My Classes
                </a>
                <a href="view_students.php" class="menu-item">
                    <i class="fas fa-users"></i> Students
                </a>
                
                <a href="teacher_profile.php" class="menu-item">
                    <i class="fas fa-user"></i> Profile
                </a>
                <a href="settings.php" class="menu-item ">
                    <i class="fas fa-cog"></i> Settings
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-bar">
                <h1>My Classes</h1>
                <a href="teacher_dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            </div>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $classes->num_rows; ?></div>
                    <div class="stat-label">Total Classes Assigned</div>
                </div>
                <div class="stat-card green">
                    <div class="stat-number">
                        <?php 
                        $classes->data_seek(0);
                        $total_students = 0;
                        while($class = $classes->fetch_assoc()) {
                            $total_students += $class['student_count'];
                        }
                        echo $total_students;
                        ?>
                    </div>
                    <div class="stat-label">Total Students</div>
                </div>
                <div class="stat-card orange">
                    <div class="stat-number"><?php echo $teacher['department_name']; ?></div>
                    <div class="stat-label">Department</div>
                </div>
            </div>

            <!-- Classes Grid -->
            <?php if ($classes->num_rows > 0): ?>
            <div class="classes-grid">
                <?php 
                $classes->data_seek(0);
                while($class = $classes->fetch_assoc()): 
                ?>
                <div class="class-card">
                    <div class="class-card-header">
                        <div class="class-code"><?php echo $class['subject_code']; ?></div>
                        <div class="class-name"><?php echo $class['subject_name']; ?></div>
                        <div class="class-details">
                            <div class="class-detail-item">
                                <i class="fas fa-book-open"></i>
                                <span><?php echo $class['semester_name']; ?></span>
                            </div>
                            <?php if ($class['section_name']): ?>
                            <div class="class-detail-item">
                                <i class="fas fa-users"></i>
                                <span>Section <?php echo $class['section_name']; ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="class-detail-item">
                                <i class="fas fa-award"></i>
                                <span><?php echo $class['credits']; ?> Credits</span>
                            </div>
                        </div>
                    </div>
                    <div class="class-card-body">
                        <div class="info-row">
                            <span class="info-label">Department</span>
                            <span class="info-value"><?php echo $class['department_name']; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Academic Year</span>
                            <span class="info-value"><?php echo $class['academic_year']; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Enrolled Students</span>
                            <span class="badge success">
                                <i class="fas fa-user-graduate"></i> <?php echo $class['student_count']; ?> Students
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Classes Conducted</span>
                            <span class="badge info">
                                <i class="fas fa-calendar-check"></i> <?php echo $class['my_classes_conducted']; ?> Classes
                            </span>
                        </div>

                        <div class="class-actions">
                            <a href="mark_attendance.php?subject_id=<?php echo $class['subject_id']; ?>&semester_id=<?php echo $class['semester_id']; ?>&section_id=<?php echo $class['section_id']; ?>&date=<?php echo date('Y-m-d'); ?>" 
                               class="action-btn primary">
                                <i class="fas fa-calendar-check"></i> Mark Attendance
                            </a>
                            <a href="view_students.php?subject_id=<?php echo $class['subject_id']; ?>&semester_id=<?php echo $class['semester_id']; ?>&section_id=<?php echo $class['section_id']; ?>" 
                               class="action-btn outline">
                                <i class="fas fa-users"></i> View Students
                            </a>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-chalkboard-teacher"></i>
                <h3>No Classes Assigned</h3>
                <p>You don't have any classes assigned yet. Please contact the administrator.</p>
            </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
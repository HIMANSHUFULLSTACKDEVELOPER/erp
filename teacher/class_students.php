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

// Get detailed student list with attendance
$students_query = "SELECT s.*, u.email, u.phone,
                  c.course_name, c.course_code,
                  COALESCE(AVG(vas.attendance_percentage), 0) as avg_attendance
                  FROM students s
                  JOIN users u ON s.user_id = u.user_id
                  JOIN courses c ON s.course_id = c.course_id
                  JOIN student_semesters ss ON s.student_id = ss.student_id
                  LEFT JOIN v_attendance_summary vas ON s.student_id = vas.student_id
                  WHERE s.department_id = {$class_teacher['department_id']}
                  AND ss.semester_id = {$class_teacher['semester_id']}
                  AND ss.section_id = {$class_teacher['section_id']}
                  AND ss.is_active = 1
                  GROUP BY s.student_id
                  ORDER BY s.full_name";

$students = $conn->query($students_query);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Students - College ERP</title>
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

        .search-bar {
            padding: 12px 20px;
            border: 2px solid var(--light-gray);
            border-radius: 12px;
            font-family: 'Outfit', sans-serif;
            font-size: 0.95rem;
            width: 300px;
            transition: all 0.3s;
        }

        .search-bar:focus {
            outline: none;
            border-color: var(--primary);
        }

        .student-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
        }

        .student-card {
            background: var(--white);
            border: 2px solid var(--light-gray);
            border-radius: 15px;
            padding: 25px;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .student-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }

        .student-card:hover {
            border-color: var(--primary);
            box-shadow: 0 8px 25px rgba(249, 115, 22, 0.15);
            transform: translateY(-5px);
        }

        .student-header {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }

        .student-avatar {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 700;
            margin-right: 15px;
        }

        .student-info h4 {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .student-info p {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .student-details {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--light-gray);
        }

        .detail-item {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
            font-size: 0.9rem;
        }

        .detail-item i {
            width: 25px;
            color: var(--primary);
        }

        .attendance-bar {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--light-gray);
        }

        .attendance-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .progress-bar {
            height: 8px;
            background: var(--light-gray);
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.3s;
        }

        .progress-fill.high {
            background: linear-gradient(90deg, var(--success), #16a34a);
        }

        .progress-fill.medium {
            background: linear-gradient(90deg, var(--warning), #ca8a04);
        }

        .progress-fill.low {
            background: linear-gradient(90deg, var(--danger), #dc2626);
        }

        .badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge.success { background: rgba(34, 197, 94, 0.15); color: var(--success); }
        .badge.warning { background: rgba(234, 179, 8, 0.15); color: var(--warning); }
        .badge.danger { background: rgba(239, 68, 68, 0.15); color: var(--danger); }

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
                <a href="class_students.php" class="menu-item active">
                    <i class="fas fa-user-graduate"></i> My Students
                </a>
                <a href="class_attendance.php" class="menu-item">
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
                    <h1>My Students</h1>
                    <p><?php echo $class_teacher['semester_name']; ?> - Section <?php echo $class_teacher['section_name']; ?></p>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-users"></i> Class Students (<?php echo $students->num_rows; ?>)</h3>
                    <input type="text" class="search-bar" id="searchInput" placeholder="Search students..." onkeyup="searchStudents()">
                </div>

                <?php if ($students->num_rows > 0): ?>
                    <div class="student-grid" id="studentGrid">
                        <?php while($student = $students->fetch_assoc()): 
                            $attendance = round($student['avg_attendance'], 2);
                            $att_class = $attendance >= 75 ? 'high' : ($attendance >= 60 ? 'medium' : 'low');
                            $badge_class = $attendance >= 75 ? 'success' : ($attendance >= 60 ? 'warning' : 'danger');
                        ?>
                        <div class="student-card" data-name="<?php echo strtolower($student['full_name']); ?>" data-admission="<?php echo strtolower($student['admission_number']); ?>">
                            <div class="student-header">
                                <div class="student-avatar">
                                    <?php echo strtoupper(substr($student['full_name'], 0, 1)); ?>
                                </div>
                                <div class="student-info">
                                    <h4><?php echo $student['full_name']; ?></h4>
                                    <p><?php echo $student['admission_number']; ?></p>
                                </div>
                            </div>

                            <div class="student-details">
                                <div class="detail-item">
                                    <i class="fas fa-graduation-cap"></i>
                                    <span><?php echo $student['course_name']; ?> (<?php echo $student['course_code']; ?>)</span>
                                </div>
                                <div class="detail-item">
                                    <i class="fas fa-envelope"></i>
                                    <span><?php echo $student['email']; ?></span>
                                </div>
                                <div class="detail-item">
                                    <i class="fas fa-phone"></i>
                                    <span><?php echo $student['phone'] ?: 'Not provided'; ?></span>
                                </div>
                                <div class="detail-item">
                                    <i class="fas fa-birthday-cake"></i>
                                    <span><?php echo date('M d, Y', strtotime($student['date_of_birth'])); ?></span>
                                </div>
                            </div>

                            <div class="attendance-bar">
                                <div class="attendance-label">
                                    <span>Overall Attendance</span>
                                    <span class="badge <?php echo $badge_class; ?>"><?php echo $attendance; ?>%</span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill <?php echo $att_class; ?>" style="width: <?php echo $attendance; ?>%"></div>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-user-graduate"></i>
                        <h3>No Students Found</h3>
                        <p>There are no students assigned to this class yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        function searchStudents() {
            const input = document.getElementById('searchInput');
            const filter = input.value.toLowerCase();
            const grid = document.getElementById('studentGrid');
            const cards = grid.getElementsByClassName('student-card');

            for (let i = 0; i < cards.length; i++) {
                const name = cards[i].getAttribute('data-name');
                const admission = cards[i].getAttribute('data-admission');
                
                if (name.includes(filter) || admission.includes(filter)) {
                    cards[i].style.display = '';
                } else {
                    cards[i].style.display = 'none';
                }
            }
        }
    </script>
</body>
</html>
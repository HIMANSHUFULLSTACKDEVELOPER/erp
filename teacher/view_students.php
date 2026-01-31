<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('teacher')) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

// Get teacher details
$sql = "SELECT teacher_id, department_id FROM teachers WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();

// Get filter parameters
$subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
$semester_id = isset($_GET['semester_id']) ? (int)$_GET['semester_id'] : 0;
$section_id = isset($_GET['section_id']) ? (int)$_GET['section_id'] : null;

// Get subject details if filters are set
$subject_details = null;
if ($subject_id && $semester_id) {
    $subject_sql = "SELECT 
                    sub.subject_name, sub.subject_code, sub.credits,
                    sem.semester_name,
                    sec.section_name,
                    d.department_name,
                    st.academic_year
                    FROM subject_teachers st
                    JOIN subjects sub ON st.subject_id = sub.subject_id
                    JOIN semesters sem ON st.semester_id = sem.semester_id
                    JOIN departments d ON sub.department_id = d.department_id
                    LEFT JOIN sections sec ON st.section_id = sec.section_id
                    WHERE st.subject_id = ? 
                    AND st.semester_id = ?
                    AND (st.section_id = ? OR (st.section_id IS NULL AND ? IS NULL))
                    AND st.teacher_id = ?";
    $stmt = $conn->prepare($subject_sql);
    $stmt->bind_param("iiiii", $subject_id, $semester_id, $section_id, $section_id, $teacher['teacher_id']);
    $stmt->execute();
    $subject_details = $stmt->get_result()->fetch_assoc();
}

// Get all classes taught by this teacher for dropdown
$classes_sql = "SELECT DISTINCT 
                st.subject_id, st.semester_id, st.section_id,
                sub.subject_name, sub.subject_code,
                sem.semester_name,
                sec.section_name
                FROM subject_teachers st
                JOIN subjects sub ON st.subject_id = sub.subject_id
                JOIN semesters sem ON st.semester_id = sem.semester_id
                LEFT JOIN sections sec ON st.section_id = sec.section_id
                WHERE st.teacher_id = ?
                ORDER BY sem.semester_number, sub.subject_name";
$stmt = $conn->prepare($classes_sql);
$stmt->bind_param("i", $teacher['teacher_id']);
$stmt->execute();
$all_classes = $stmt->get_result();

// Get students if filters are applied
$students = [];
if ($subject_id && $semester_id) {
    $students_sql = "SELECT 
                    s.student_id,
                    s.admission_number,
                    s.full_name,
                    s.profile_photo,
                    u.email,
                    u.phone,
                    sec.section_name,
                    COUNT(DISTINCT a.attendance_date) as total_classes,
                    SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                    SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                    SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
                    ROUND(
                        (SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / 
                        NULLIF(COUNT(DISTINCT a.attendance_date), 0)) * 100, 
                        2
                    ) as attendance_percentage
                    FROM students s
                    JOIN users u ON s.user_id = u.user_id
                    JOIN student_semesters ss ON s.student_id = ss.student_id
                    LEFT JOIN sections sec ON ss.section_id = sec.section_id
                    LEFT JOIN attendance a ON s.student_id = a.student_id 
                        AND a.subject_id = ?
                        AND a.semester_id = ?
                        AND (a.section_id = ? OR (a.section_id IS NULL AND ? IS NULL))
                    WHERE ss.semester_id = ?
                    AND (ss.section_id = ? OR (ss.section_id IS NULL AND ? IS NULL))
                    AND ss.is_active = 1
                    AND u.is_active = 1
                    GROUP BY s.student_id
                    ORDER BY s.full_name";
    
    $stmt = $conn->prepare($students_sql);
    $stmt->bind_param("iiiiiii", $subject_id, $semester_id, $section_id, $section_id, $semester_id, $section_id, $section_id);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Students - College ERP</title>
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

        /* Filter Section */
        .filter-section {
            background: var(--white);
            padding: 30px;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .filter-title {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 20px;
            color: var(--dark);
            font-family: 'Space Grotesk', sans-serif;
        }

        .filter-form {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 15px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--dark);
        }

        .form-group select {
            padding: 12px 15px;
            border: 2px solid #e4e4e7;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s;
            background: var(--white);
        }

        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
        }

        .filter-btn {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            border: none;
            padding: 12px 30px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }

        .filter-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(139, 92, 246, 0.4);
        }

        /* Subject Info Card */
        .subject-info {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            padding: 30px;
            border-radius: 20px;
            margin-bottom: 30px;
            color: var(--white);
            box-shadow: 0 4px 20px rgba(139, 92, 246, 0.3);
        }

        .subject-info h2 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.8rem;
            margin-bottom: 15px;
        }

        .subject-meta {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
            font-size: 0.95rem;
            opacity: 0.95;
        }

        .subject-meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Stats Cards */
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
            border-left: 4px solid var(--primary);
        }

        .stat-card.success {
            border-left-color: var(--success);
        }

        .stat-card.warning {
            border-left-color: var(--warning);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
        }

        /* Students Table */
        .students-container {
            background: var(--white);
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .table-header h3 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.5rem;
            color: var(--dark);
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            padding: 10px 15px 10px 40px;
            border: 2px solid #e4e4e7;
            border-radius: 12px;
            width: 300px;
            font-size: 0.95rem;
        }

        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }

        .students-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .students-table thead {
            background: var(--light-gray);
        }

        .students-table th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
        }

        .students-table td {
            padding: 15px;
            border-bottom: 1px solid var(--light-gray);
        }

        .students-table tbody tr {
            transition: all 0.3s;
        }

        .students-table tbody tr:hover {
            background: var(--light-gray);
        }

        .student-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .student-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 600;
            font-size: 1.1rem;
        }

        .student-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }

        .student-details h4 {
            font-size: 0.95rem;
            color: var(--dark);
            margin-bottom: 3px;
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
            display: inline-block;
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
            margin-top: 5px;
        }

        .progress-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.3s;
        }

        .progress-fill.high { background: var(--success); }
        .progress-fill.medium { background: var(--warning); }
        .progress-fill.low { background: var(--danger); }

        .action-buttons {
            display: flex;
            gap: 10px;
        }

        .action-btn {
            padding: 8px 12px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.3s;
            text-decoration: none;
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
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
        }

        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: var(--gray);
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

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }

            .sidebar {
                transform: translateX(-100%);
            }

            .filter-form {
                grid-template-columns: 1fr;
            }

            .search-box input {
                width: 100%;
            }

            .students-table {
                font-size: 0.85rem;
            }
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
                <a href="my_classes.php" class="menu-item">
                    <i class="fas fa-chalkboard"></i> My Classes
                </a>
                <a href="view_students.php" class="menu-item active">
                    <i class="fas fa-users"></i> Students
                </a>
                <a href="teacher_profile.php" class="menu-item">
                    <i class="fas fa-user"></i> Profile
                </a>
                <a href="settings.php" class="menu-item">
                    <i class="fas fa-cog"></i> Settings
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-bar">
                <h1>View Students</h1>
                <a href="my_classes.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Classes</a>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <div class="filter-title"><i class="fas fa-filter"></i> Select Class</div>
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label for="class_select">Choose a Class</label>
                        <select name="class_select" id="class_select" onchange="this.form.submit()">
                            <option value="">-- Select a class to view students --</option>
                            <?php while($class = $all_classes->fetch_assoc()): ?>
                                <option value="<?php echo $class['subject_id'] . '|' . $class['semester_id'] . '|' . ($class['section_id'] ?? ''); ?>"
                                    <?php echo ($subject_id == $class['subject_id'] && $semester_id == $class['semester_id'] && $section_id == $class['section_id']) ? 'selected' : ''; ?>>
                                    <?php echo $class['subject_code'] . ' - ' . $class['subject_name'] . ' (' . $class['semester_name'] . ($class['section_name'] ? ' - Section ' . $class['section_name'] : '') . ')'; ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </form>
            </div>

            <?php if ($subject_details): ?>
                <!-- Subject Info -->
                <div class="subject-info">
                    <h2><?php echo $subject_details['subject_name']; ?></h2>
                    <div class="subject-meta">
                        <div class="subject-meta-item">
                            <i class="fas fa-code"></i>
                            <span><?php echo $subject_details['subject_code']; ?></span>
                        </div>
                        <div class="subject-meta-item">
                            <i class="fas fa-book-open"></i>
                            <span><?php echo $subject_details['semester_name']; ?></span>
                        </div>
                        <?php if ($subject_details['section_name']): ?>
                        <div class="subject-meta-item">
                            <i class="fas fa-users"></i>
                            <span>Section <?php echo $subject_details['section_name']; ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="subject-meta-item">
                            <i class="fas fa-award"></i>
                            <span><?php echo $subject_details['credits']; ?> Credits</span>
                        </div>
                        <div class="subject-meta-item">
                            <i class="fas fa-building"></i>
                            <span><?php echo $subject_details['department_name']; ?></span>
                        </div>
                        <div class="subject-meta-item">
                            <i class="fas fa-calendar-alt"></i>
                            <span><?php echo $subject_details['academic_year']; ?></span>
                        </div>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo count($students); ?></div>
                        <div class="stat-label">Total Students</div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-number">
                            <?php 
                            $high_attendance = array_filter($students, function($s) {
                                return ($s['attendance_percentage'] ?? 0) >= 75;
                            });
                            echo count($high_attendance);
                            ?>
                        </div>
                        <div class="stat-label">Good Attendance (≥75%)</div>
                    </div>
                    <div class="stat-card warning">
                        <div class="stat-number">
                            <?php 
                            $low_attendance = array_filter($students, function($s) {
                                return ($s['attendance_percentage'] ?? 0) < 75 && ($s['attendance_percentage'] ?? 0) > 0;
                            });
                            echo count($low_attendance);
                            ?>
                        </div>
                        <div class="stat-label">Low Attendance (<75%)</div>
                    </div>
                </div>

                <!-- Students Table -->
                <div class="students-container">
                    <div class="table-header">
                        <h3>Students List</h3>
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" id="searchInput" placeholder="Search students..." onkeyup="searchStudents()">
                        </div>
                    </div>

                    <?php if (count($students) > 0): ?>
                    <table class="students-table" id="studentsTable">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Admission No.</th>
                                <th>Section</th>
                                <th>Contact</th>
                                <th>Attendance</th>
                                <th>Stats</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student): ?>
                            <tr>
                                <td>
                                    <div class="student-info">
                                        <div class="student-avatar">
                                            <?php if ($student['profile_photo']): ?>
                                                <img src="../uploads/<?php echo $student['profile_photo']; ?>" alt="">
                                            <?php else: ?>
                                                <?php echo strtoupper(substr($student['full_name'], 0, 1)); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="student-details">
                                            <h4><?php echo $student['full_name']; ?></h4>
                                            <p><?php echo $student['email']; ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td><strong><?php echo $student['admission_number']; ?></strong></td>
                                <td><?php echo $student['section_name'] ?? 'N/A'; ?></td>
                                <td><?php echo $student['phone'] ?? 'N/A'; ?></td>
                                <td>
                                    <?php 
                                    $percentage = $student['attendance_percentage'] ?? 0;
                                    $class_type = $percentage >= 75 ? 'high' : ($percentage >= 50 ? 'medium' : 'low');
                                    $badge_class = $percentage >= 75 ? 'success' : ($percentage >= 50 ? 'warning' : 'danger');
                                    ?>
                                    <span class="badge <?php echo $badge_class; ?>">
                                        <?php echo number_format($percentage, 1); ?>%
                                    </span>
                                    <div class="progress-bar">
                                        <div class="progress-fill <?php echo $class_type; ?>" 
                                             style="width: <?php echo $percentage; ?>%"></div>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-size: 0.85rem; color: var(--gray);">
                                        <div><i class="fas fa-check-circle" style="color: var(--success);"></i> 
                                            <?php echo $student['present_count']; ?> Present</div>
                                        <div><i class="fas fa-times-circle" style="color: var(--danger);"></i> 
                                            <?php echo $student['absent_count']; ?> Absent</div>
                                        <div><i class="fas fa-clock" style="color: var(--warning);"></i> 
                                            <?php echo $student['late_count']; ?> Late</div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-user-graduate"></i>
                        <h3>No Students Found</h3>
                        <p>No students are enrolled in this class yet.</p>
                    </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="empty-state" style="background: var(--white); border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.08);">
                    <i class="fas fa-users"></i>
                    <h3>Select a Class</h3>
                    <p>Please select a class from the dropdown above to view students.</p>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        // Parse URL parameters on page load
        window.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const subjectId = urlParams.get('subject_id');
            const semesterId = urlParams.get('semester_id');
            const sectionId = urlParams.get('section_id');
            
            if (subjectId && semesterId) {
                const selectValue = subjectId + '|' + semesterId + '|' + (sectionId || '');
                const selectElement = document.getElementById('class_select');
                selectElement.value = selectValue;
            }
        });

        // Handle class selection
        document.getElementById('class_select').addEventListener('change', function() {
            if (this.value) {
                const parts = this.value.split('|');
                const subjectId = parts[0];
                const semesterId = parts[1];
                const sectionId = parts[2];
                
                let url = '?subject_id=' + subjectId + '&semester_id=' + semesterId;
                if (sectionId) {
                    url += '&section_id=' + sectionId;
                }
                
                window.location.href = url;
            }
        });

        // Search functionality
        function searchStudents() {
            const input = document.getElementById('searchInput');
            const filter = input.value.toUpperCase();
            const table = document.getElementById('studentsTable');
            const tr = table.getElementsByTagName('tr');

            for (let i = 1; i < tr.length; i++) {
                const tdName = tr[i].getElementsByTagName('td')[0];
                const tdAdmission = tr[i].getElementsByTagName('td')[1];
                
                if (tdName || tdAdmission) {
                    const nameValue = tdName.textContent || tdName.innerText;
                    const admissionValue = tdAdmission.textContent || tdAdmission.innerText;
                    
                    if (nameValue.toUpperCase().indexOf(filter) > -1 || 
                        admissionValue.toUpperCase().indexOf(filter) > -1) {
                        tr[i].style.display = '';
                    } else {
                        tr[i].style.display = 'none';
                    }
                }
            }
        }
    </script>
</body>
</html>
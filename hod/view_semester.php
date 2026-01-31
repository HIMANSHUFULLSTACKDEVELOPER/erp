<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('hod')) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];
$student_id = $_GET['student_id'] ?? 0;
$semester_id = $_GET['semester_id'] ?? 0;

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
    die("HOD profile not found.");
}

$dept_id = $hod['department_id'];

// Get student details
$sql = "SELECT s.*, c.course_name, d.department_name, u.email, u.phone
        FROM students s
        JOIN courses c ON s.course_id = c.course_id
        JOIN departments d ON s.department_id = d.department_id
        JOIN users u ON s.user_id = u.user_id
        WHERE s.student_id = ? AND s.department_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $student_id, $dept_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    $_SESSION['error'] = "Student not found.";
    redirect('manage_students.php');
}

// Get semester details
$sql = "SELECT ss.*, sem.semester_name, sem.semester_number, sec.section_name
        FROM student_semesters ss
        JOIN semesters sem ON ss.semester_id = sem.semester_id
        LEFT JOIN sections sec ON ss.section_id = sec.section_id
        WHERE ss.student_id = ? AND ss.semester_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $student_id, $semester_id);
$stmt->execute();
$semester = $stmt->get_result()->fetch_assoc();

if (!$semester) {
    $_SESSION['error'] = "Semester not found for this student.";
    redirect("student_details.php?id=$student_id");
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_section':
                $new_section_id = $_POST['section_id'];
                $ss_id = $semester['id'];
                
                $sql = "UPDATE student_semesters SET section_id = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ii", $new_section_id, $ss_id);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Section updated successfully!";
                } else {
                    $_SESSION['error'] = "Error: " . $conn->error;
                }
                break;
                
            case 'assign_subjects':
                $subject_ids = $_POST['subject_ids'] ?? [];
                
                // First, remove existing subject assignments for this student-semester
                $conn->query("DELETE FROM student_subjects WHERE student_id = $student_id AND semester_id = $semester_id");
                
                // Then add new assignments
                if (!empty($subject_ids)) {
                    $success_count = 0;
                    foreach ($subject_ids as $subject_id) {
                        $sql = "INSERT INTO student_subjects (student_id, subject_id, semester_id) VALUES (?, ?, ?)";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("iii", $student_id, $subject_id, $semester_id);
                        if ($stmt->execute()) {
                            $success_count++;
                        }
                    }
                    $_SESSION['success'] = "$success_count subject(s) assigned successfully!";
                } else {
                    $_SESSION['error'] = "No subjects selected!";
                }
                break;
                
            case 'set_active':
                // Deactivate all semesters for this student
                $conn->query("UPDATE student_semesters SET is_active = 0 WHERE student_id = $student_id");
                
                // Activate this semester
                $ss_id = $semester['id'];
                $sql = "UPDATE student_semesters SET is_active = 1 WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $ss_id);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Semester set as active successfully!";
                } else {
                    $_SESSION['error'] = "Error: " . $conn->error;
                }
                break;
        }
        header("Location: view_semester.php?student_id=$student_id&semester_id=$semester_id");
        exit;
    }
}

// Get subjects for this semester
$subjects_query = "SELECT sub.*, 
                   CASE WHEN ss.subject_id IS NOT NULL THEN 1 ELSE 0 END as is_assigned
                   FROM subjects sub
                   LEFT JOIN student_subjects ss ON sub.subject_id = ss.subject_id 
                       AND ss.student_id = $student_id 
                       AND ss.semester_id = $semester_id
                   WHERE sub.department_id = $dept_id 
                   AND sub.semester_id = $semester_id
                   ORDER BY sub.subject_code";
$subjects = $conn->query($subjects_query);

// Get assigned subjects for display
$assigned_subjects_query = "SELECT sub.*
                            FROM student_subjects ss
                            JOIN subjects sub ON ss.subject_id = sub.subject_id
                            WHERE ss.student_id = $student_id 
                            AND ss.semester_id = $semester_id
                            ORDER BY sub.subject_code";
$assigned_subjects = $conn->query($assigned_subjects_query);

// Get all sections
$sections = $conn->query("SELECT * FROM sections ORDER BY section_name");

// Get attendance summary for this semester
$attendance_query = "SELECT 
                     COUNT(*) as total_classes,
                     SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
                     ROUND((SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as percentage
                     FROM attendance
                     WHERE student_id = $student_id 
                     AND semester_id = $semester_id";
$attendance_stats = $conn->query($attendance_query)->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $semester['semester_name']; ?> - <?php echo $student['full_name']; ?></title>
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
            background: var(--white);
            color: var(--dark);
            border: 2px solid var(--light-gray);
            padding: 12px 28px;
            border-radius: 15px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-family: 'Outfit', sans-serif;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .back-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        /* Alert */
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.1);
            color: var(--success);
            border: 2px solid var(--success);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border: 2px solid var(--danger);
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

        .badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge.primary { 
            background: linear-gradient(135deg, rgba(249, 115, 22, 0.2), rgba(234, 88, 12, 0.2));
            color: var(--primary); 
        }

        .badge.success { 
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.2), rgba(21, 128, 61, 0.2));
            color: var(--success); 
        }

        .badge.warning { 
            background: linear-gradient(135deg, rgba(234, 179, 8, 0.2), rgba(202, 138, 4, 0.2));
            color: var(--warning); 
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-family: 'Outfit', sans-serif;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(249, 115, 22, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #16a34a);
            color: var(--white);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(34, 197, 94, 0.3);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .info-box {
            background: var(--light-gray);
            padding: 20px;
            border-radius: 15px;
        }

        .info-box h4 {
            color: var(--gray);
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 10px;
        }

        .info-box p {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--dark);
        }

        /* Stats Row */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .mini-stat {
            background: var(--white);
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .mini-stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            background: linear-gradient(135deg, rgba(249, 115, 22, 0.15), rgba(234, 88, 12, 0.15));
            color: var(--primary);
        }

        .mini-stat-info h3 {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 3px;
        }

        .mini-stat-info p {
            font-size: 0.85rem;
            color: var(--gray);
            font-weight: 500;
        }

        /* Subject List */
        .subject-list {
            display: grid;
            gap: 15px;
        }

        .subject-item {
            background: var(--light-gray);
            padding: 15px 20px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .subject-info h4 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .subject-info p {
            font-size: 0.85rem;
            color: var(--gray);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: var(--white);
            border-radius: 20px;
            padding: 35px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 50px rgba(0,0,0,0.3);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-gray);
        }

        .modal-header h3 {
            font-size: 1.5rem;
            font-weight: 700;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray);
            transition: all 0.3s;
        }

        .close-btn:hover {
            color: var(--danger);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid var(--light-gray);
            border-radius: 12px;
            font-size: 1rem;
            font-family: 'Outfit', sans-serif;
            transition: all 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
        }

        .checkbox-group {
            max-height: 400px;
            overflow-y: auto;
            border: 2px solid var(--light-gray);
            border-radius: 12px;
            padding: 15px;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px;
            margin-bottom: 10px;
            border-radius: 8px;
            transition: all 0.3s;
            border: 2px solid transparent;
        }

        .checkbox-item:hover {
            background: rgba(249, 115, 22, 0.05);
            border-color: var(--primary);
        }

        .checkbox-item input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }

        .checkbox-item label {
            margin: 0;
            cursor: pointer;
            flex: 1;
            font-weight: 500;
        }

        .checkbox-item .subject-code {
            color: var(--primary);
            font-weight: 700;
        }

        .checkbox-item .subject-credits {
            color: var(--gray);
            font-size: 0.85rem;
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
                    <h1><?php echo $semester['semester_name']; ?></h1>
                    <p><?php echo $student['full_name']; ?> - <?php echo $student['admission_number']; ?></p>
                </div>
                <a href="student_details.php?id=<?php echo $student_id; ?>" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Student
                </a>
            </div>

            <!-- Alert Messages -->
            <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php 
                echo $_SESSION['success']; 
                unset($_SESSION['success']);
                ?>
            </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php 
                echo $_SESSION['error']; 
                unset($_SESSION['error']);
                ?>
            </div>
            <?php endif; ?>

            <!-- Semester Info -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-info-circle"></i> Semester Information</h3>
                    <?php if ($semester['is_active']): ?>
                    <span class="badge success"><i class="fas fa-check-circle"></i> Active Semester</span>
                    <?php else: ?>
                    <form method="POST" style="margin: 0;">
                        <input type="hidden" name="action" value="set_active">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-check"></i> Set as Active
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                
                <div class="info-grid">
                    <div class="info-box">
                        <h4>Section</h4>
                        <p><?php echo $semester['section_name'] ?: 'Not Assigned'; ?></p>
                    </div>
                    <div class="info-box">
                        <h4>Academic Year</h4>
                        <p><?php echo $semester['academic_year']; ?></p>
                    </div>
                    <div class="info-box">
                        <h4>Enrolled On</h4>
                        <p><?php echo date('d M Y', strtotime($semester['created_at'])); ?></p>
                    </div>
                    <div class="info-box">
                        <h4>Subjects Enrolled</h4>
                        <p><?php echo $assigned_subjects->num_rows; ?></p>
                    </div>
                </div>

                <div style="margin-top: 20px;">
                    <button class="btn btn-primary" onclick="openSectionModal()">
                        <i class="fas fa-edit"></i> Change Section
                    </button>
                </div>
            </div>

            <!-- Attendance Stats -->
            <?php if ($attendance_stats['total_classes'] > 0): ?>
            <div class="stats-row">
                <div class="mini-stat">
                    <div class="mini-stat-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="mini-stat-info">
                        <h3><?php echo $attendance_stats['total_classes']; ?></h3>
                        <p>Total Classes</p>
                    </div>
                </div>
                <div class="mini-stat">
                    <div class="mini-stat-icon">
                        <i class="fas fa-check"></i>
                    </div>
                    <div class="mini-stat-info">
                        <h3><?php echo $attendance_stats['present_count']; ?></h3>
                        <p>Present</p>
                    </div>
                </div>
                <div class="mini-stat">
                    <div class="mini-stat-icon">
                        <i class="fas fa-percent"></i>
                    </div>
                    <div class="mini-stat-info">
                        <h3><?php echo $attendance_stats['percentage']; ?>%</h3>
                        <p>Attendance Rate</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Subjects Section -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-book"></i> Enrolled Subjects</h3>
                    <button class="btn btn-success" onclick="openSubjectsModal()">
                        <i class="fas fa-edit"></i> Manage Subjects
                    </button>
                </div>

                <?php if ($assigned_subjects->num_rows > 0): ?>
                <div class="subject-list">
                    <?php while($subject = $assigned_subjects->fetch_assoc()): ?>
                    <div class="subject-item">
                        <div class="subject-info">
                            <h4><?php echo $subject['subject_name']; ?></h4>
                            <p>
                                <span class="badge primary"><?php echo $subject['subject_code']; ?></span>
                                <span style="margin-left: 10px; color: var(--gray);">
                                    <i class="fas fa-award"></i> <?php echo $subject['credits']; ?> Credits
                                </span>
                            </p>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
                <?php else: ?>
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-inbox" style="font-size: 3rem; color: var(--gray); margin-bottom: 15px;"></i>
                    <p style="color: var(--gray); font-size: 1.1rem;">No subjects assigned yet</p>
                    <button class="btn btn-success" onclick="openSubjectsModal()" style="margin-top: 15px;">
                        <i class="fas fa-plus"></i> Assign Subjects
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Change Section Modal -->
    <div id="sectionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Change Section</h3>
                <button class="close-btn" onclick="closeModal('sectionModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_section">
                
                <div class="form-group">
                    <label>Select New Section *</label>
                    <select name="section_id" class="form-control" required>
                        <option value="">Choose Section</option>
                        <?php 
                        while($sec = $sections->fetch_assoc()): 
                        ?>
                        <option value="<?php echo $sec['section_id']; ?>" <?php echo ($sec['section_id'] == $semester['section_id']) ? 'selected' : ''; ?>>
                            Section <?php echo $sec['section_name']; ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-save"></i> Update Section
                </button>
            </form>
        </div>
    </div>

    <!-- Manage Subjects Modal -->
    <div id="subjectsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Manage Subjects</h3>
                <button class="close-btn" onclick="closeModal('subjectsModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="assign_subjects">
                
                <div class="form-group">
                    <label>Select Subjects for <?php echo $semester['semester_name']; ?></label>
                    <div class="checkbox-group">
                        <?php 
                        $subjects->data_seek(0);
                        if ($subjects->num_rows > 0):
                            while($subject = $subjects->fetch_assoc()): 
                        ?>
                        <div class="checkbox-item">
                            <input type="checkbox" 
                                   name="subject_ids[]" 
                                   value="<?php echo $subject['subject_id']; ?>" 
                                   id="subject_<?php echo $subject['subject_id']; ?>"
                                   <?php echo $subject['is_assigned'] ? 'checked' : ''; ?>>
                            <label for="subject_<?php echo $subject['subject_id']; ?>">
                                <span class="subject-code"><?php echo $subject['subject_code']; ?></span> - 
                                <?php echo $subject['subject_name']; ?>
                                <span class="subject-credits">(<?php echo $subject['credits']; ?> credits)</span>
                            </label>
                        </div>
                        <?php 
                            endwhile;
                        else:
                        ?>
                        <p style="text-align: center; padding: 20px; color: var(--gray);">
                            No subjects available for this semester
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if ($subjects->num_rows > 0): ?>
                <button type="submit" class="btn btn-success" style="width: 100%;">
                    <i class="fas fa-check"></i> Save Subjects
                </button>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <script>
        function openSectionModal() {
            document.getElementById('sectionModal').classList.add('active');
        }

        function openSubjectsModal() {
            document.getElementById('subjectsModal').classList.add('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }
    </script>
</body>
</html>
<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('hod')) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

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
    die("HOD profile not found or not assigned to any department.");
}

$dept_id = $hod['department_id'];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_semester':
                $student_id = $_POST['student_id'];
                $semester_id = $_POST['semester_id'];
                $section_id = $_POST['section_id'];
                $academic_year = $_POST['academic_year'];
                
                // Deactivate previous active semester
                $conn->query("UPDATE student_semesters SET is_active = 0 WHERE student_id = $student_id");
                
                // Add new semester
                $sql = "INSERT INTO student_semesters (student_id, semester_id, section_id, academic_year, is_active) 
                        VALUES (?, ?, ?, ?, 1)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("iiis", $student_id, $semester_id, $section_id, $academic_year);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Student promoted to new semester successfully!";
                } else {
                    $_SESSION['error'] = "Error: " . $conn->error;
                }
                break;
                
            case 'update_section':
                $ss_id = $_POST['ss_id'];
                $section_id = $_POST['section_id'];
                
                $sql = "UPDATE student_semesters SET section_id = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ii", $section_id, $ss_id);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Section updated successfully!";
                } else {
                    $_SESSION['error'] = "Error: " . $conn->error;
                }
                break;
                
            case 'assign_subjects':
                $student_id = $_POST['student_id'];
                $semester_id = $_POST['semester_id'];
                $subject_ids = $_POST['subject_ids'] ?? [];
                
                // First, remove existing subject assignments for this student-semester
                $conn->query("DELETE FROM student_subjects WHERE student_id = $student_id AND semester_id = $semester_id");
                
                // Then add new assignments
                if (!empty($subject_ids)) {
                    foreach ($subject_ids as $subject_id) {
                        $sql = "INSERT INTO student_subjects (student_id, subject_id, semester_id) VALUES (?, ?, ?)";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("iii", $student_id, $subject_id, $semester_id);
                        $stmt->execute();
                    }
                    $_SESSION['success'] = "Subjects assigned successfully!";
                } else {
                    $_SESSION['error'] = "No subjects selected!";
                }
                break;
        }
        header("Location: manage_student_semesters.php");
        exit;
    }
}

// Get all students in department with their current semester info
$students_query = "SELECT s.*, c.course_name, 
                   u.email, u.phone,
                   COALESCE(ss.id, 0) as ss_id,
                   COALESCE(ss.semester_id, 0) as current_semester_id,
                   COALESCE(sem.semester_name, 'Not Assigned') as current_semester,
                   COALESCE(sem.semester_number, 0) as semester_number,
                   COALESCE(ss.section_id, 0) as section_id,
                   COALESCE(sec.section_name, 'N/A') as section_name,
                   COALESCE(ss.academic_year, '') as academic_year
                   FROM students s
                   JOIN courses c ON s.course_id = c.course_id
                   JOIN users u ON s.user_id = u.user_id
                   LEFT JOIN student_semesters ss ON s.student_id = ss.student_id AND ss.is_active = 1
                   LEFT JOIN semesters sem ON ss.semester_id = sem.semester_id
                   LEFT JOIN sections sec ON ss.section_id = sec.section_id
                   WHERE s.department_id = $dept_id
                   ORDER BY s.admission_year DESC, s.full_name";
$students = $conn->query($students_query);

// Get all semesters
$semesters = $conn->query("SELECT * FROM semesters ORDER BY semester_number");

// Get all sections
$sections = $conn->query("SELECT * FROM sections ORDER BY section_name");

// Get statistics
$total_students = $students->num_rows;

// Students by semester
$sem_stats = $conn->query("SELECT sem.semester_name, COUNT(ss.student_id) as count
                          FROM semesters sem
                          LEFT JOIN student_semesters ss ON sem.semester_id = ss.semester_id AND ss.is_active = 1
                          LEFT JOIN students s ON ss.student_id = s.student_id AND s.department_id = $dept_id
                          GROUP BY sem.semester_id
                          ORDER BY sem.semester_number");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Student Semesters - College ERP</title>
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

        .hod-role {
            font-size: 0.8rem;
            opacity: 0.8;
            margin-top: 3px;
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

        .logout-btn {
            background: linear-gradient(135deg, var(--danger), #dc2626);
            color: var(--white);
            border: none;
            padding: 12px 28px;
            border-radius: 15px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-family: 'Outfit', sans-serif;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
        }

        .logout-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 25px rgba(239, 68, 68, 0.4);
        }

        /* Alert Messages */
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

        /* Stats Cards */
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

        /* Table */
        .table-container {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            text-align: left;
            padding: 15px 12px;
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

        .table tbody tr {
            transition: all 0.3s;
        }

        .table tbody tr:hover {
            background: rgba(249, 115, 22, 0.05);
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

        .badge.danger { 
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(220, 38, 38, 0.2));
            color: var(--danger); 
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-family: 'Outfit', sans-serif;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
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

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.75rem;
        }

        .student-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
        }

        .student-details h4 {
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .student-details p {
            font-size: 0.8rem;
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
            color: var(--dark);
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
            max-height: 300px;
            overflow-y: auto;
            border: 2px solid var(--light-gray);
            border-radius: 12px;
            padding: 15px;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            margin-bottom: 8px;
            border-radius: 8px;
            transition: all 0.3s;
        }

        .checkbox-item:hover {
            background: rgba(249, 115, 22, 0.05);
        }

        .checkbox-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .checkbox-item label {
            margin: 0;
            cursor: pointer;
            flex: 1;
        }

        .action-btns {
            display: flex;
            gap: 10px;
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
                    <div class="hod-role">Head of Department</div>
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
                    <h1>Student Semester Management</h1>
                    <p><?php echo $hod['department_name']; ?> Department</p>
                </div>
                <a href="../logout.php"><button class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</button></a>
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

            <!-- Stats Row -->
            <div class="stats-row">
                <div class="mini-stat">
                    <div class="mini-stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="mini-stat-info">
                        <h3><?php echo $total_students; ?></h3>
                        <p>Total Students</p>
                    </div>
                </div>
                <?php
                $sem_stats_arr = [];
                $sem_stats->data_seek(0);
                while ($stat = $sem_stats->fetch_assoc()) {
                    $sem_stats_arr[] = $stat;
                }
                // Show top 3 semesters
                for ($i = 0; $i < min(3, count($sem_stats_arr)); $i++):
                ?>
                <div class="mini-stat">
                    <div class="mini-stat-icon">
                        <i class="fas fa-layer-group"></i>
                    </div>
                    <div class="mini-stat-info">
                        <h3><?php echo $sem_stats_arr[$i]['count']; ?></h3>
                        <p><?php echo $sem_stats_arr[$i]['semester_name']; ?></p>
                    </div>
                </div>
                <?php endfor; ?>
            </div>

            <!-- Students Table -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-list"></i> All Students with Semester Details</h3>
                </div>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Admission No.</th>
                                <th>Course</th>
                                <th>Current Semester</th>
                                <th>Section</th>
                                <th>Academic Year</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $students->data_seek(0);
                            while($student = $students->fetch_assoc()): 
                            ?>
                            <tr>
                                <td>
                                    <div class="student-info">
                                        <div class="student-avatar">
                                            <?php echo strtoupper(substr($student['full_name'], 0, 1)); ?>
                                        </div>
                                        <div class="student-details">
                                            <h4><?php echo $student['full_name']; ?></h4>
                                            <p><?php echo $student['email']; ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge primary"><?php echo $student['admission_number']; ?></span></td>
                                <td><?php echo $student['course_name']; ?></td>
                                <td>
                                    <?php if ($student['current_semester_id'] > 0): ?>
                                        <span class="badge success"><?php echo $student['current_semester']; ?></span>
                                    <?php else: ?>
                                        <span class="badge danger">Not Assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($student['section_id'] > 0): ?>
                                        <span class="badge primary">Section <?php echo $student['section_name']; ?></span>
                                    <?php else: ?>
                                        <span class="badge warning">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $student['academic_year'] ? $student['academic_year'] : 'N/A'; ?></td>
                                <td>
                                    <div class="action-btns">
                                        <button class="btn btn-primary btn-sm" onclick="openPromoteModal(<?php echo $student['student_id']; ?>, '<?php echo addslashes($student['full_name']); ?>', <?php echo $student['semester_number']; ?>)">
                                            <i class="fas fa-arrow-up"></i> Promote
                                        </button>
                                        <?php if ($student['current_semester_id'] > 0): ?>
                                        <button class="btn btn-success btn-sm" onclick="openSubjectsModal(<?php echo $student['student_id']; ?>, <?php echo $student['current_semester_id']; ?>, '<?php echo addslashes($student['full_name']); ?>', '<?php echo $student['current_semester']; ?>')">
                                            <i class="fas fa-book"></i> Subjects
                                        </button>
                                        <button class="btn btn-primary btn-sm" onclick="openSectionModal(<?php echo $student['ss_id']; ?>, <?php echo $student['section_id']; ?>, '<?php echo addslashes($student['full_name']); ?>')">
                                            <i class="fas fa-edit"></i> Section
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Promote Student Modal -->
    <div id="promoteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Promote Student to New Semester</h3>
                <button class="close-btn" onclick="closeModal('promoteModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_semester">
                <input type="hidden" name="student_id" id="promote_student_id">
                
                <div class="form-group">
                    <label>Student Name</label>
                    <input type="text" class="form-control" id="promote_student_name" readonly>
                </div>
                
                <div class="form-group">
                    <label>New Semester *</label>
                    <select name="semester_id" class="form-control" required id="promote_semester">
                        <option value="">Select Semester</option>
                        <?php 
                        $semesters->data_seek(0);
                        while($sem = $semesters->fetch_assoc()): 
                        ?>
                        <option value="<?php echo $sem['semester_id']; ?>"><?php echo $sem['semester_name']; ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Section *</label>
                    <select name="section_id" class="form-control" required>
                        <option value="">Select Section</option>
                        <?php 
                        $sections->data_seek(0);
                        while($sec = $sections->fetch_assoc()): 
                        ?>
                        <option value="<?php echo $sec['section_id']; ?>">Section <?php echo $sec['section_name']; ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Academic Year *</label>
                    <input type="text" name="academic_year" class="form-control" placeholder="e.g., 2025-2026" required>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-check"></i> Promote Student
                </button>
            </form>
        </div>
    </div>

    <!-- Update Section Modal -->
    <div id="sectionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Update Student Section</h3>
                <button class="close-btn" onclick="closeModal('sectionModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_section">
                <input type="hidden" name="ss_id" id="section_ss_id">
                
                <div class="form-group">
                    <label>Student Name</label>
                    <input type="text" class="form-control" id="section_student_name" readonly>
                </div>
                
                <div class="form-group">
                    <label>New Section *</label>
                    <select name="section_id" class="form-control" required id="section_select">
                        <option value="">Select Section</option>
                        <?php 
                        $sections->data_seek(0);
                        while($sec = $sections->fetch_assoc()): 
                        ?>
                        <option value="<?php echo $sec['section_id']; ?>">Section <?php echo $sec['section_name']; ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-save"></i> Update Section
                </button>
            </form>
        </div>
    </div>

    <!-- Assign Subjects Modal -->
    <div id="subjectsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Assign Subjects to Student</h3>
                <button class="close-btn" onclick="closeModal('subjectsModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="assign_subjects">
                <input type="hidden" name="student_id" id="subjects_student_id">
                <input type="hidden" name="semester_id" id="subjects_semester_id">
                
                <div class="form-group">
                    <label>Student Name</label>
                    <input type="text" class="form-control" id="subjects_student_name" readonly>
                </div>
                
                <div class="form-group">
                    <label>Semester</label>
                    <input type="text" class="form-control" id="subjects_semester_name" readonly>
                </div>
                
                <div class="form-group">
                    <label>Select Subjects</label>
                    <div class="checkbox-group" id="subjects_list">
                        <!-- Will be populated by JavaScript -->
                    </div>
                </div>
                
                <button type="submit" class="btn btn-success" style="width: 100%;">
                    <i class="fas fa-check"></i> Assign Subjects
                </button>
            </form>
        </div>
    </div>

    <script>
        function openPromoteModal(studentId, studentName, currentSemester) {
            document.getElementById('promote_student_id').value = studentId;
            document.getElementById('promote_student_name').value = studentName;
            
            // Set next semester as default if student has a current semester
            if (currentSemester > 0 && currentSemester < 8) {
                document.getElementById('promote_semester').value = currentSemester + 1;
            }
            
            document.getElementById('promoteModal').classList.add('active');
        }

        function openSectionModal(ssId, currentSection, studentName) {
            document.getElementById('section_ss_id').value = ssId;
            document.getElementById('section_student_name').value = studentName;
            document.getElementById('section_select').value = currentSection;
            document.getElementById('sectionModal').classList.add('active');
        }

        function openSubjectsModal(studentId, semesterId, studentName, semesterName) {
            document.getElementById('subjects_student_id').value = studentId;
            document.getElementById('subjects_semester_id').value = semesterId;
            document.getElementById('subjects_student_name').value = studentName;
            document.getElementById('subjects_semester_name').value = semesterName;
            
            // Fetch subjects for this semester
            fetchSubjects(studentId, semesterId);
            
            document.getElementById('subjectsModal').classList.add('active');
        }

        function fetchSubjects(studentId, semesterId) {
            const subjectsList = document.getElementById('subjects_list');
            subjectsList.innerHTML = '<p>Loading subjects...</p>';
            
            fetch(`get_subjects.php?semester_id=${semesterId}&student_id=${studentId}&dept_id=<?php echo $dept_id; ?>`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let html = '';
                        data.subjects.forEach(subject => {
                            html += `
                                <div class="checkbox-item">
                                    <input type="checkbox" 
                                           name="subject_ids[]" 
                                           value="${subject.subject_id}" 
                                           id="subject_${subject.subject_id}"
                                           ${subject.assigned ? 'checked' : ''}>
                                    <label for="subject_${subject.subject_id}">
                                        <strong>${subject.subject_code}</strong> - ${subject.subject_name} 
                                        <span style="color: var(--gray); font-size: 0.85rem;">(${subject.credits} credits)</span>
                                    </label>
                                </div>
                            `;
                        });
                        subjectsList.innerHTML = html;
                    } else {
                        subjectsList.innerHTML = '<p style="color: var(--danger);">Error loading subjects</p>';
                    }
                })
                .catch(error => {
                    subjectsList.innerHTML = '<p style="color: var(--danger);">Error loading subjects</p>';
                    console.error('Error:', error);
                });
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }
    </script>
</body>
</html>
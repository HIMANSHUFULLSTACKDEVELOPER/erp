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

// Get filter parameters
$filter_year = $_GET['year'] ?? '';
$filter_semester = $_GET['semester'] ?? '';

// Build WHERE clause for filters
$where_conditions = ["s.department_id = $dept_id"];
$join_type = "LEFT JOIN";

if (!empty($filter_year) || !empty($filter_semester)) {
    $join_type = "INNER JOIN";
    
    if (!empty($filter_year)) {
        $where_conditions[] = "ss_all.academic_year = '$filter_year'";
    }
    if (!empty($filter_semester)) {
        $where_conditions[] = "ss_all.semester_id = $filter_semester";
    }
}

$where_clause = implode(' AND ', $where_conditions);

// Get all students in department with their current semester info
$students_query = "SELECT DISTINCT s.*, c.course_name, 
                   u.email, u.phone,
                   COALESCE(ss_active.id, 0) as ss_id,
                   COALESCE(ss_active.semester_id, 0) as current_semester_id,
                   COALESCE(sem_active.semester_name, 'Not Assigned') as current_semester,
                   COALESCE(sem_active.semester_number, 0) as semester_number,
                   COALESCE(ss_active.section_id, 0) as section_id,
                   COALESCE(sec_active.section_name, 'N/A') as section_name,
                   COALESCE(ss_active.academic_year, '') as academic_year
                   FROM students s
                   JOIN courses c ON s.course_id = c.course_id
                   JOIN users u ON s.user_id = u.user_id
                   $join_type student_semesters ss_all ON s.student_id = ss_all.student_id
                   LEFT JOIN student_semesters ss_active ON s.student_id = ss_active.student_id AND ss_active.is_active = 1
                   LEFT JOIN semesters sem_active ON ss_active.semester_id = sem_active.semester_id
                   LEFT JOIN sections sec_active ON ss_active.section_id = sec_active.section_id
                   WHERE $where_clause
                   ORDER BY s.admission_year DESC, s.full_name";
$students = $conn->query($students_query);

// Get all semesters
$semesters = $conn->query("SELECT * FROM semesters ORDER BY semester_number");

// Get all sections
$sections = $conn->query("SELECT * FROM sections ORDER BY section_name");

// Get all unique academic years
$years_query = "SELECT DISTINCT academic_year 
                FROM student_semesters 
                WHERE student_id IN (SELECT student_id FROM students WHERE department_id = $dept_id)
                ORDER BY academic_year DESC";
$academic_years = $conn->query($years_query);

// Get statistics
$total_students_query = "SELECT COUNT(DISTINCT s.student_id) as total 
                         FROM students s 
                         WHERE s.department_id = $dept_id";
$total_result = $conn->query($total_students_query);
$total_students = $total_result->fetch_assoc()['total'];

// Students by semester
$sem_stats_query = "SELECT sem.semester_name, sem.semester_id, sem.semester_number,
                    COUNT(DISTINCT ss.student_id) as count
                    FROM semesters sem
                    LEFT JOIN student_semesters ss ON sem.semester_id = ss.semester_id
                    LEFT JOIN students s ON ss.student_id = s.student_id AND s.department_id = $dept_id";

if (!empty($filter_year)) {
    $sem_stats_query .= " AND ss.academic_year = '$filter_year'";
}

$sem_stats_query .= " GROUP BY sem.semester_id, sem.semester_name, sem.semester_number
                      ORDER BY sem.semester_number";

$sem_stats = $conn->query($sem_stats_query);
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

        html {
            scroll-behavior: smooth;
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

        /* Filters */
        .filters-section {
            background: var(--white);
            padding: 20px 30px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .filters-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filter-group label {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--dark);
        }

        .filter-select {
            padding: 10px 15px;
            border: 2px solid var(--light-gray);
            border-radius: 10px;
            font-family: 'Outfit', sans-serif;
            font-size: 0.95rem;
            transition: all 0.3s;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--primary);
        }

        .filter-btn {
            padding: 10px 20px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            transition: all 0.3s;
        }

        .filter-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(249, 115, 22, 0.3);
        }

        .clear-btn {
            padding: 10px 20px;
            background: var(--white);
            color: var(--gray);
            border: 2px solid var(--light-gray);
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            transition: all 0.3s;
        }

        .clear-btn:hover {
            border-color: var(--danger);
            color: var(--danger);
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
            white-space: nowrap;
        }

        .table td {
            padding: 18px 12px;
            border-bottom: 1px solid var(--light-gray);
            vertical-align: middle;
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
            margin: 2px;
            white-space: nowrap;
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

        .badge.info { 
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.2), rgba(37, 99, 235, 0.2));
            color: #3b82f6; 
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
            text-decoration: none;
            white-space: nowrap;
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

        .btn-info {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: var(--white);
        }

        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
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
            flex-shrink: 0;
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

        /* Semester Assignment Box */
        .semester-box {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.1), rgba(21, 128, 61, 0.05));
            border: 2px solid var(--success);
            border-radius: 12px;
            padding: 15px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .semester-box.not-assigned {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(220, 38, 38, 0.05));
            border: 2px solid var(--danger);
        }

        .semester-main {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 5px;
        }

        .semester-icon {
            width: 35px;
            height: 35px;
            background: var(--success);
            color: var(--white);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .semester-box.not-assigned .semester-icon {
            background: var(--danger);
        }

        .semester-info {
            flex: 1;
        }

        .semester-name {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--dark);
        }

        .semester-details {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 5px;
        }

        /* Action buttons column */
        .action-btns {
            display: flex;
            flex-direction: column;
            gap: 8px;
            align-items: flex-start;
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

        .history-timeline {
            max-height: 400px;
            overflow-y: auto;
        }

        .timeline-item {
            padding: 15px;
            border-left: 3px solid var(--primary);
            margin-left: 10px;
            margin-bottom: 15px;
            position: relative;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -8px;
            top: 20px;
            width: 13px;
            height: 13px;
            border-radius: 50%;
            background: var(--primary);
        }

        .timeline-item.active {
            border-left-color: var(--success);
            background: rgba(34, 197, 94, 0.05);
        }

        .timeline-item.active::before {
            background: var(--success);
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

            <!-- Filters Section -->
            <div class="filters-section">
                <form method="GET" action="">
                    <div class="filters-row">
                        <div class="filter-group">
                            <label><i class="fas fa-calendar"></i> Academic Year</label>
                            <select name="year" class="filter-select">
                                <option value="">All Years</option>
                                <?php 
                                $academic_years->data_seek(0);
                                while ($year = $academic_years->fetch_assoc()): 
                                ?>
                                <option value="<?php echo $year['academic_year']; ?>" <?php echo ($filter_year == $year['academic_year']) ? 'selected' : ''; ?>>
                                    <?php echo $year['academic_year']; ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label><i class="fas fa-layer-group"></i> Semester</label>
                            <select name="semester" class="filter-select">
                                <option value="">All Semesters</option>
                                <?php 
                                $semesters->data_seek(0);
                                while ($sem = $semesters->fetch_assoc()): 
                                ?>
                                <option value="<?php echo $sem['semester_id']; ?>" <?php echo ($filter_semester == $sem['semester_id']) ? 'selected' : ''; ?>>
                                    <?php echo $sem['semester_name']; ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label>&nbsp;</label>
                            <div style="display: flex; gap: 10px;">
                                <button type="submit" class="filter-btn">
                                    <i class="fas fa-filter"></i> Apply Filter
                                </button>
                                <a href="manage_student_semesters.php">
                                    <button type="button" class="clear-btn">
                                        <i class="fas fa-times"></i> Clear
                                    </button>
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

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
                foreach ($sem_stats_arr as $stat):
                ?>
                <div class="mini-stat">
                    <div class="mini-stat-icon">
                        <i class="fas fa-layer-group"></i>
                    </div>
                    <div class="mini-stat-info">
                        <h3><?php echo $stat['count']; ?></h3>
                        <p><?php echo $stat['semester_name']; ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Students Table -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-list"></i> Student Semester Management</h3>
                    <?php if (!empty($filter_year) || !empty($filter_semester)): ?>
                    <span class="badge warning">
                        <i class="fas fa-filter"></i> Filtered View
                    </span>
                    <?php endif; ?>
                </div>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width: 250px;">Student Details</th>
                                <th style="width: 120px;">Admission</th>
                                <th style="width: 150px;">Course</th>
                                <th style="width: 300px;">Current Semester Assignment</th>
                                <th style="width: 200px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if ($students->num_rows > 0):
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
                                <td>
                                    <span class="badge primary"><?php echo $student['admission_number']; ?></span>
                                </td>
                                <td><?php echo $student['course_name']; ?></td>
                                <td>
                                    <?php if ($student['current_semester_id'] > 0): ?>
                                        <div class="semester-box">
                                            <div class="semester-main">
                                                <div class="semester-icon">
                                                    <i class="fas fa-graduation-cap"></i>
                                                </div>
                                                <div class="semester-info">
                                                    <div class="semester-name"><?php echo $student['current_semester']; ?></div>
                                                </div>
                                                <span class="badge success">
                                                    <i class="fas fa-check-circle"></i> Active
                                                </span>
                                            </div>
                                            <div class="semester-details">
                                                <span class="badge info">
                                                    <i class="fas fa-users"></i> Section <?php echo $student['section_name']; ?>
                                                </span>
                                                <span class="badge primary">
                                                    <i class="fas fa-calendar"></i> <?php echo $student['academic_year']; ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="semester-box not-assigned">
                                            <div class="semester-main">
                                                <div class="semester-icon">
                                                    <i class="fas fa-exclamation-triangle"></i>
                                                </div>
                                                <div class="semester-info">
                                                    <div class="semester-name">No Semester Assigned</div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-btns">
                                        <button class="btn btn-primary btn-sm" onclick="openPromoteModal(<?php echo $student['student_id']; ?>, '<?php echo addslashes($student['full_name']); ?>', <?php echo $student['semester_number']; ?>)">
                                            <i class="fas fa-arrow-up"></i> Promote
                                        </button>
                                        
                                        <?php if ($student['current_semester_id'] > 0): ?>
                                        <button class="btn btn-success btn-sm" onclick="openSubjectsModal(<?php echo $student['student_id']; ?>, <?php echo $student['current_semester_id']; ?>, '<?php echo addslashes($student['full_name']); ?>', '<?php echo $student['current_semester']; ?>')">
                                            <i class="fas fa-book"></i> Assign Subjects
                                        </button>
                                        <button class="btn btn-primary btn-sm" onclick="openSectionModal(<?php echo $student['ss_id']; ?>, <?php echo $student['section_id']; ?>, '<?php echo addslashes($student['full_name']); ?>')">
                                            <i class="fas fa-edit"></i> Change Section
                                        </button>
                                        <?php endif; ?>
                                        
                                        <button class="btn btn-info btn-sm" onclick="viewFullHistory(<?php echo $student['student_id']; ?>, '<?php echo addslashes($student['full_name']); ?>')">
                                            <i class="fas fa-history"></i> View History
                                        </button>
                                        
                                        <a href="student_details.php?id=<?php echo $student['student_id']; ?>" class="btn btn-primary btn-sm">
                                            <i class="fas fa-eye"></i> Full Details
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php 
                                endwhile;
                            else:
                            ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 40px;">
                                    <i class="fas fa-inbox" style="font-size: 3rem; color: var(--gray); margin-bottom: 15px;"></i>
                                    <p style="color: var(--gray); font-size: 1.1rem;">No students found matching your filters</p>
                                </td>
                            </tr>
                            <?php endif; ?>
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
                <h3><i class="fas fa-arrow-up"></i> Promote Student to New Semester</h3>
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

    <!-- View History Modal -->
    <div id="historyModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-history"></i> Semester History</h3>
                <button class="close-btn" onclick="closeModal('historyModal')">&times;</button>
            </div>
            <div>
                <div class="form-group">
                    <label>Student Name</label>
                    <input type="text" class="form-control" id="history_student_name" readonly>
                </div>
                
                <div class="form-group">
                    <label>Complete Semester Journey</label>
                    <div class="history-timeline" id="history_timeline">
                        <!-- Will be populated by JavaScript -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Update Section Modal -->
    <div id="sectionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Update Student Section</h3>
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
                <h3><i class="fas fa-book"></i> Assign Subjects to Student</h3>
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
        // Save scroll position before page unload
        window.addEventListener('beforeunload', function() {
            sessionStorage.setItem('scrollPosition', window.scrollY);
        });

        // Restore scroll position on page load
        window.addEventListener('load', function() {
            const scrollPosition = sessionStorage.getItem('scrollPosition');
            if (scrollPosition) {
                window.scrollTo(0, parseInt(scrollPosition));
                sessionStorage.removeItem('scrollPosition');
            }
        });

        function openPromoteModal(studentId, studentName, currentSemester) {
            // Save current scroll position
            sessionStorage.setItem('scrollPosition', window.scrollY);
            
            document.getElementById('promote_student_id').value = studentId;
            document.getElementById('promote_student_name').value = studentName;
            
            if (currentSemester > 0 && currentSemester < 8) {
                document.getElementById('promote_semester').value = currentSemester + 1;
            }
            
            document.getElementById('promoteModal').classList.add('active');
        }

        function openSectionModal(ssId, currentSection, studentName) {
            // Save current scroll position
            sessionStorage.setItem('scrollPosition', window.scrollY);
            
            document.getElementById('section_ss_id').value = ssId;
            document.getElementById('section_student_name').value = studentName;
            document.getElementById('section_select').value = currentSection;
            document.getElementById('sectionModal').classList.add('active');
        }

        function openSubjectsModal(studentId, semesterId, studentName, semesterName) {
            // Save current scroll position
            sessionStorage.setItem('scrollPosition', window.scrollY);
            
            document.getElementById('subjects_student_id').value = studentId;
            document.getElementById('subjects_semester_id').value = semesterId;
            document.getElementById('subjects_student_name').value = studentName;
            document.getElementById('subjects_semester_name').value = semesterName;
            
            fetchSubjects(studentId, semesterId);
            
            document.getElementById('subjectsModal').classList.add('active');
        }

        function viewFullHistory(studentId, studentName) {
            document.getElementById('history_student_name').value = studentName;
            
            fetch(`get_student_history.php?student_id=${studentId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let html = '';
                        data.history.forEach(item => {
                            html += `
                                <div class="timeline-item ${item.is_active == 1 ? 'active' : ''}">
                                    <h4 style="margin-bottom: 8px; color: var(--dark);">
                                        ${item.semester_name}
                                        ${item.is_active == 1 ? '<span class="badge success" style="margin-left: 10px;"><i class="fas fa-check"></i> Current</span>' : ''}
                                    </h4>
                                    <p style="margin-bottom: 5px;"><strong>Section:</strong> ${item.section_name || 'N/A'}</p>
                                    <p style="margin-bottom: 5px;"><strong>Academic Year:</strong> ${item.academic_year}</p>
                                    <p style="margin-bottom: 5px;"><strong>Subjects:</strong> ${item.subject_count} enrolled</p>
                                    <p style="color: var(--gray); font-size: 0.85rem;">Enrolled: ${item.created_at}</p>
                                </div>
                            `;
                        });
                        document.getElementById('history_timeline').innerHTML = html || '<p>No history found</p>';
                    } else {
                        document.getElementById('history_timeline').innerHTML = '<p style="color: var(--danger);">Error loading history</p>';
                    }
                })
                .catch(error => {
                    document.getElementById('history_timeline').innerHTML = '<p style="color: var(--danger);">Error loading history</p>';
                    console.error('Error:', error);
                });
            
            document.getElementById('historyModal').classList.add('active');
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
                                           ${subject.assigned == 1 ? 'checked' : ''}>
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

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }
    </script>
</body>
</html>
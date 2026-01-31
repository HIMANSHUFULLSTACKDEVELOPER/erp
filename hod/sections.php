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
            case 'add_section':
                $section_name = trim($_POST['section_name']);
                $max_students = (int)$_POST['max_students'];
                $description = trim($_POST['description']);
                
                // Check if section already exists
                $check = $conn->query("SELECT section_id FROM sections WHERE section_name = '$section_name'");
                if ($check->num_rows > 0) {
                    $_SESSION['error'] = "Section '$section_name' already exists!";
                } else {
                    $sql = "INSERT INTO sections (section_name, max_students, description) VALUES (?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("sis", $section_name, $max_students, $description);
                    
                    if ($stmt->execute()) {
                        $_SESSION['success'] = "Section added successfully!";
                    } else {
                        $_SESSION['error'] = "Error: " . $conn->error;
                    }
                }
                break;
                
            case 'update_section':
                $section_id = (int)$_POST['section_id'];
                $section_name = trim($_POST['section_name']);
                $max_students = (int)$_POST['max_students'];
                $description = trim($_POST['description']);
                
                $sql = "UPDATE sections SET section_name = ?, max_students = ?, description = ? WHERE section_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sisi", $section_name, $max_students, $description, $section_id);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Section updated successfully!";
                } else {
                    $_SESSION['error'] = "Error: " . $conn->error;
                }
                break;
                
            case 'delete_section':
                $section_id = (int)$_POST['section_id'];
                
                // Check if section has students
                $check = $conn->query("SELECT COUNT(*) as count FROM student_semesters WHERE section_id = $section_id AND is_active = 1");
                $result = $check->fetch_assoc();
                
                if ($result['count'] > 0) {
                    $_SESSION['error'] = "Cannot delete section with active students! Please move students first.";
                } else {
                    if ($conn->query("DELETE FROM sections WHERE section_id = $section_id")) {
                        $_SESSION['success'] = "Section deleted successfully!";
                    } else {
                        $_SESSION['error'] = "Error: " . $conn->error;
                    }
                }
                break;
        }
        header("Location: sections.php");
        exit;
    }
}

// Get all sections with student counts
$sections_query = "SELECT 
                    s.*,
                    COUNT(DISTINCT CASE WHEN ss.is_active = 1 THEN ss.student_id END) as active_students,
                    COUNT(DISTINCT CASE WHEN ss.is_active = 1 AND st.department_id = $dept_id THEN ss.student_id END) as dept_students
                   FROM sections s
                   LEFT JOIN student_semesters ss ON s.section_id = ss.section_id
                   LEFT JOIN students st ON ss.student_id = st.student_id
                   GROUP BY s.section_id
                   ORDER BY s.section_name";
$sections = $conn->query($sections_query);

// Get statistics
$total_sections = $sections->num_rows;

// Students by section in this department
$section_stats = $conn->query("SELECT 
                                sec.section_name,
                                COUNT(DISTINCT ss.student_id) as count,
                                sem.semester_name
                               FROM sections sec
                               LEFT JOIN student_semesters ss ON sec.section_id = ss.section_id AND ss.is_active = 1
                               LEFT JOIN students s ON ss.student_id = s.student_id AND s.department_id = $dept_id
                               LEFT JOIN semesters sem ON ss.semester_id = sem.semester_id
                               GROUP BY sec.section_id, sem.semester_id
                               HAVING count > 0
                               ORDER BY sem.semester_number, sec.section_name");

// Get section-wise semester distribution for department
$section_sem_dist = $conn->query("SELECT 
                                    sec.section_name,
                                    sem.semester_name,
                                    COUNT(ss.student_id) as student_count
                                  FROM sections sec
                                  CROSS JOIN semesters sem
                                  LEFT JOIN student_semesters ss ON sec.section_id = ss.section_id 
                                      AND sem.semester_id = ss.semester_id 
                                      AND ss.is_active = 1
                                  LEFT JOIN students s ON ss.student_id = s.student_id AND s.department_id = $dept_id
                                  GROUP BY sec.section_id, sem.semester_id
                                  ORDER BY sec.section_name, sem.semester_number");

// Organize data for display
$section_semester_data = [];
while ($row = $section_sem_dist->fetch_assoc()) {
    $section_semester_data[$row['section_name']][$row['semester_name']] = $row['student_count'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Sections - College ERP</title>
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

        .top-bar-right {
            display: flex;
            gap: 15px;
            align-items: center;
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
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .mini-stat {
            background: var(--white);
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .mini-stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            background: linear-gradient(135deg, rgba(249, 115, 22, 0.15), rgba(234, 88, 12, 0.15));
            color: var(--primary);
        }

        .mini-stat-info h3 {
            font-size: 2rem;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 3px;
        }

        .mini-stat-info p {
            font-size: 0.9rem;
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

        /* Sections Grid */
        .sections-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .section-card {
            background: var(--white);
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            transition: all 0.3s;
            border: 2px solid transparent;
        }

        .section-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(249, 115, 22, 0.2);
            border-color: var(--primary);
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 15px;
        }

        .section-name {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            font-weight: 700;
        }

        .section-stats {
            margin: 20px 0;
        }

        .stat-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid var(--light-gray);
        }

        .stat-item:last-child {
            border-bottom: none;
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .stat-value {
            font-weight: 700;
            color: var(--dark);
            font-size: 1rem;
        }

        .section-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
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
            padding: 10px 18px;
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

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #dc2626);
            color: var(--white);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.75rem;
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
            max-width: 500px;
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

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

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
            background: linear-gradient(135deg, var(--success), #16a34a);
            transition: width 0.3s;
        }

        .progress-fill.warning {
            background: linear-gradient(135deg, var(--warning), #ca8a04);
        }

        .progress-fill.danger {
            background: linear-gradient(135deg, var(--danger), #dc2626);
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
                    <h1>Section Management</h1>
                    <p><?php echo $hod['department_name']; ?> Department</p>
                </div>
                <div class="top-bar-right">
                    <button class="btn btn-primary" onclick="openAddModal()">
                        <i class="fas fa-plus"></i> Add New Section
                    </button>
                    <a href="../logout.php"><button class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</button></a>
                </div>
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
                        <i class="fas fa-users-rectangle"></i>
                    </div>
                    <div class="mini-stat-info">
                        <h3><?php echo $total_sections; ?></h3>
                        <p>Total Sections</p>
                    </div>
                </div>
                <?php
                $section_stats->data_seek(0);
                $top_sections = [];
                while ($stat = $section_stats->fetch_assoc()) {
                    $key = $stat['section_name'];
                    if (!isset($top_sections[$key])) {
                        $top_sections[$key] = 0;
                    }
                    $top_sections[$key] += $stat['count'];
                }
                arsort($top_sections);
                $top_3 = array_slice($top_sections, 0, 3, true);
                foreach ($top_3 as $section => $count):
                ?>
                <div class="mini-stat">
                    <div class="mini-stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="mini-stat-info">
                        <h3><?php echo $count; ?></h3>
                        <p>Section <?php echo $section; ?> Students</p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Sections Grid -->
            <div class="sections-grid">
                <?php 
                $sections->data_seek(0);
                while($section = $sections->fetch_assoc()): 
                    $capacity_percent = $section['max_students'] > 0 ? ($section['dept_students'] / $section['max_students']) * 100 : 0;
                    $progress_class = $capacity_percent >= 90 ? 'danger' : ($capacity_percent >= 70 ? 'warning' : '');
                ?>
                <div class="section-card">
                    <div class="section-header">
                        <div class="section-name">
                            <div class="section-icon">
                                <?php echo $section['section_name']; ?>
                            </div>
                            <span>Section <?php echo $section['section_name']; ?></span>
                        </div>
                    </div>
                    
                    <div class="section-stats">
                        <div class="stat-item">
                            <span class="stat-label">Department Students</span>
                            <span class="stat-value"><?php echo $section['dept_students']; ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Total Students</span>
                            <span class="stat-value"><?php echo $section['active_students']; ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Max Capacity</span>
                            <span class="stat-value"><?php echo $section['max_students']; ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Capacity</span>
                            <span class="stat-value"><?php echo round($capacity_percent); ?>%</span>
                        </div>
                    </div>
                    
                    <div class="progress-bar">
                        <div class="progress-fill <?php echo $progress_class; ?>" style="width: <?php echo min($capacity_percent, 100); ?>%"></div>
                    </div>
                    
                    <?php if ($section['description']): ?>
                    <p style="margin-top: 15px; color: var(--gray); font-size: 0.85rem;">
                        <?php echo htmlspecialchars($section['description']); ?>
                    </p>
                    <?php endif; ?>
                    
                    <div class="section-actions">
                        <button class="btn btn-primary btn-sm" onclick='openEditModal(<?php echo json_encode($section); ?>)'>
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button class="btn btn-danger btn-sm" onclick="confirmDelete(<?php echo $section['section_id']; ?>, '<?php echo $section['section_name']; ?>', <?php echo $section['active_students']; ?>)">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>

            <!-- Section Distribution Table -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-bar"></i> Section-wise Student Distribution (<?php echo $hod['department_name']; ?>)</h3>
                </div>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Section</th>
                                <th>Sem 1</th>
                                <th>Sem 2</th>
                                <th>Sem 3</th>
                                <th>Sem 4</th>
                                <th>Sem 5</th>
                                <th>Sem 6</th>
                                <th>Sem 7</th>
                                <th>Sem 8</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($section_semester_data as $section => $semesters): 
                                $total = array_sum($semesters);
                            ?>
                            <tr>
                                <td><strong>Section <?php echo $section; ?></strong></td>
                                <?php for ($i = 1; $i <= 8; $i++): 
                                    $sem_name = "Semester $i";
                                    $count = $semesters[$sem_name] ?? 0;
                                ?>
                                <td>
                                    <?php if ($count > 0): ?>
                                        <span class="badge success"><?php echo $count; ?></span>
                                    <?php else: ?>
                                        <span style="color: var(--gray);">-</span>
                                    <?php endif; ?>
                                </td>
                                <?php endfor; ?>
                                <td><span class="badge primary"><?php echo $total; ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Section Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New Section</h3>
                <button class="close-btn" onclick="closeModal('addModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_section">
                
                <div class="form-group">
                    <label>Section Name *</label>
                    <input type="text" name="section_name" class="form-control" placeholder="e.g., D" maxlength="10" required>
                </div>
                
                <div class="form-group">
                    <label>Maximum Students *</label>
                    <input type="number" name="max_students" class="form-control" placeholder="e.g., 60" min="1" required>
                </div>
                
                <div class="form-group">
                    <label>Description (Optional)</label>
                    <textarea name="description" class="form-control" placeholder="Any additional information about this section..."></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-plus"></i> Add Section
                </button>
            </form>
        </div>
    </div>

    <!-- Edit Section Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Section</h3>
                <button class="close-btn" onclick="closeModal('editModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_section">
                <input type="hidden" name="section_id" id="edit_section_id">
                
                <div class="form-group">
                    <label>Section Name *</label>
                    <input type="text" name="section_name" id="edit_section_name" class="form-control" maxlength="10" required>
                </div>
                
                <div class="form-group">
                    <label>Maximum Students *</label>
                    <input type="number" name="max_students" id="edit_max_students" class="form-control" min="1" required>
                </div>
                
                <div class="form-group">
                    <label>Description (Optional)</label>
                    <textarea name="description" id="edit_description" class="form-control"></textarea>
                </div>
                
                <button type="submit" class="btn btn-success" style="width: 100%;">
                    <i class="fas fa-save"></i> Update Section
                </button>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Form -->
    <form method="POST" action="" id="deleteForm" style="display: none;">
        <input type="hidden" name="action" value="delete_section">
        <input type="hidden" name="section_id" id="delete_section_id">
    </form>

    <script>
        function openAddModal() {
            document.getElementById('addModal').classList.add('active');
        }

        function openEditModal(section) {
            document.getElementById('edit_section_id').value = section.section_id;
            document.getElementById('edit_section_name').value = section.section_name;
            document.getElementById('edit_max_students').value = section.max_students;
            document.getElementById('edit_description').value = section.description || '';
            document.getElementById('editModal').classList.add('active');
        }

        function confirmDelete(sectionId, sectionName, activeStudents) {
            if (activeStudents > 0) {
                alert(`Cannot delete Section ${sectionName}!\n\nThis section has ${activeStudents} active students.\nPlease move all students to another section first.`);
                return;
            }
            
            if (confirm(`Are you sure you want to delete Section ${sectionName}?\n\nThis action cannot be undone.`)) {
                document.getElementById('delete_section_id').value = sectionId;
                document.getElementById('deleteForm').submit();
            }
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
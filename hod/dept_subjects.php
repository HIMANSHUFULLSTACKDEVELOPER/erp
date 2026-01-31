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

// Handle Add Subject
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_subject'])) {
    $subject_name = $_POST['subject_name'];
    $subject_code = $_POST['subject_code'];
    $semester_id = $_POST['semester_id'];
    $credits = $_POST['credits'];
    
    $stmt = $conn->prepare("INSERT INTO subjects (subject_name, subject_code, department_id, semester_id, credits) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssiii", $subject_name, $subject_code, $dept_id, $semester_id, $credits);
    
    if ($stmt->execute()) {
        $success_msg = "Subject added successfully!";
    } else {
        $error_msg = "Error adding subject: " . $stmt->error;
    }
}

// Handle Delete Subject
if (isset($_GET['delete'])) {
    $subject_id = $_GET['delete'];
    
    $stmt = $conn->prepare("DELETE FROM subjects WHERE subject_id = ? AND department_id = ?");
    $stmt->bind_param("ii", $subject_id, $dept_id);
    
    if ($stmt->execute()) {
        $success_msg = "Subject deleted successfully!";
    } else {
        $error_msg = "Error deleting subject.";
    }
}

// Handle Edit Subject
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_subject'])) {
    $subject_id = $_POST['subject_id'];
    $subject_name = $_POST['subject_name'];
    $subject_code = $_POST['subject_code'];
    $semester_id = $_POST['semester_id'];
    $credits = $_POST['credits'];
    
    $stmt = $conn->prepare("UPDATE subjects SET subject_name = ?, subject_code = ?, semester_id = ?, credits = ? WHERE subject_id = ? AND department_id = ?");
    $stmt->bind_param("ssiiii", $subject_name, $subject_code, $semester_id, $credits, $subject_id, $dept_id);
    
    if ($stmt->execute()) {
        $success_msg = "Subject updated successfully!";
    } else {
        $error_msg = "Error updating subject: " . $stmt->error;
    }
}

// Get all subjects in department
$subjects = $conn->query("SELECT s.*, sem.semester_name, sem.semester_number
                         FROM subjects s
                         JOIN semesters sem ON s.semester_id = sem.semester_id
                         WHERE s.department_id = $dept_id
                         ORDER BY sem.semester_number, s.subject_name");

// Get subject count
$subject_count = $subjects->num_rows;

// Get all semesters for dropdown
$semesters = $conn->query("SELECT * FROM semesters ORDER BY semester_number");

// Get subjects grouped by semester
$subjects_by_semester = $conn->query("SELECT sem.semester_name, sem.semester_number, COUNT(s.subject_id) as subject_count
                                     FROM semesters sem
                                     LEFT JOIN subjects s ON s.semester_id = sem.semester_id AND s.department_id = $dept_id
                                     GROUP BY sem.semester_id
                                     ORDER BY sem.semester_number");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subject Management - College ERP</title>
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
        }

        .btn {
            padding: 12px 28px;
            border-radius: 15px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            box-shadow: 0 4px 15px rgba(249, 115, 22, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 25px rgba(249, 115, 22, 0.4);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #dc2626);
            color: var(--white);
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
        }

        .btn-danger:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 25px rgba(239, 68, 68, 0.4);
        }

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 15px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.15);
            color: var(--success);
            border: 2px solid rgba(34, 197, 94, 0.3);
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.15);
            color: var(--danger);
            border: 2px solid rgba(239, 68, 68, 0.3);
        }

        /* Stats Grid */
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
            text-align: center;
        }

        .stat-card.total {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.95rem;
            opacity: 0.9;
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

        .table tr:hover {
            background: rgba(249, 115, 22, 0.05);
        }

        .badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-primary {
            background: rgba(249, 115, 22, 0.15);
            color: var(--primary);
        }

        .badge-success {
            background: rgba(34, 197, 94, 0.15);
            color: var(--success);
        }

        .badge-warning {
            background: rgba(234, 179, 8, 0.15);
            color: var(--warning);
        }

        .action-btns {
            display: flex;
            gap: 10px;
        }

        .btn-icon {
            width: 35px;
            height: 35px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }

        .btn-icon.edit {
            background: rgba(234, 179, 8, 0.15);
            color: var(--warning);
        }

        .btn-icon.delete {
            background: rgba(239, 68, 68, 0.15);
            color: var(--danger);
        }

        .btn-icon:hover {
            transform: translateY(-3px);
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
            padding: 40px;
            border-radius: 25px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }

        .modal-header {
            margin-bottom: 25px;
        }

        .modal-header h2 {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
        }

        .close-btn {
            float: right;
            font-size: 2rem;
            font-weight: 700;
            color: var(--gray);
            cursor: pointer;
            line-height: 1;
            margin-top: -10px;
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
            font-family: 'Outfit', sans-serif;
            font-size: 1rem;
            transition: all 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .semester-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }

        .semester-box {
            padding: 20px;
            background: var(--light-gray);
            border-radius: 15px;
            text-align: center;
            transition: all 0.3s;
        }

        .semester-box:hover {
            background: rgba(249, 115, 22, 0.1);
            transform: translateY(-3px);
        }

        .semester-box .number {
            font-size: 2rem;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 5px;
        }

        .semester-box .label {
            font-size: 0.85rem;
            color: var(--gray);
            font-weight: 600;
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
                    <h1>Subject Management</h1>
                    <p>Manage subjects of <?php echo $hod['department_name']; ?> Department</p>
                </div>
                <div class="top-bar-right">
                    <button class="btn btn-primary" onclick="openAddModal()">
                        <i class="fas fa-plus"></i> Add Subject
                    </button>
                    <a href="../logout.php"><button class="btn btn-danger"><i class="fas fa-sign-out-alt"></i> Logout</button></a>
                </div>
            </div>

            <?php if (isset($success_msg)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success_msg; ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error_msg)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error_msg; ?>
                </div>
            <?php endif; ?>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card total">
                    <div class="stat-number"><?php echo $subject_count; ?></div>
                    <div class="stat-label">Total Subjects</div>
                </div>
                <?php 
                $subjects_by_semester->data_seek(0);
                $displayed = 0;
                while($sem = $subjects_by_semester->fetch_assoc()): 
                    if ($displayed >= 3) break;
                    $displayed++;
                ?>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $sem['subject_count']; ?></div>
                    <div class="stat-label"><?php echo $sem['semester_name']; ?></div>
                </div>
                <?php endwhile; ?>
            </div>

            <!-- Subjects by Semester Overview -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-bar"></i> Subjects Distribution</h3>
                </div>
                <div class="semester-grid">
                    <?php 
                    $subjects_by_semester->data_seek(0);
                    while($sem = $subjects_by_semester->fetch_assoc()): 
                    ?>
                    <div class="semester-box">
                        <div class="number"><?php echo $sem['subject_count']; ?></div>
                        <div class="label"><?php echo $sem['semester_name']; ?></div>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>

            <!-- All Subjects Table -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-list"></i> All Subjects</h3>
                </div>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Subject Code</th>
                                <th>Subject Name</th>
                                <th>Semester</th>
                                <th>Credits</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $subjects->data_seek(0);
                            while($subject = $subjects->fetch_assoc()): 
                            ?>
                            <tr>
                                <td><strong><?php echo $subject['subject_code']; ?></strong></td>
                                <td><?php echo $subject['subject_name']; ?></td>
                                <td>
                                    <span class="badge badge-primary"><?php echo $subject['semester_name']; ?></span>
                                </td>
                                <td><?php echo $subject['credits']; ?> Credits</td>
                                <td>
                                    <div class="action-btns">
                                        <button class="btn-icon edit" onclick='openEditModal(<?php echo json_encode($subject); ?>)'>
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn-icon delete" onclick="confirmDelete(<?php echo $subject['subject_id']; ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
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

    <!-- Add Subject Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span class="close-btn" onclick="closeAddModal()">&times;</span>
                <h2>Add New Subject</h2>
            </div>
            <form method="POST">
                <div class="form-group">
                    <label>Subject Name *</label>
                    <input type="text" name="subject_name" class="form-control" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Subject Code *</label>
                        <input type="text" name="subject_code" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Credits *</label>
                        <input type="number" name="credits" class="form-control" min="1" max="6" value="3" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Semester *</label>
                    <select name="semester_id" class="form-control" required>
                        <option value="">Select Semester</option>
                        <?php 
                        $semesters->data_seek(0);
                        while($sem = $semesters->fetch_assoc()): 
                        ?>
                        <option value="<?php echo $sem['semester_id']; ?>">
                            <?php echo $sem['semester_name']; ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <button type="submit" name="add_subject" class="btn btn-primary" style="width: 100%; margin-top: 10px;">
                    <i class="fas fa-plus"></i> Add Subject
                </button>
            </form>
        </div>
    </div>

    <!-- Edit Subject Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span class="close-btn" onclick="closeEditModal()">&times;</span>
                <h2>Edit Subject</h2>
            </div>
            <form method="POST">
                <input type="hidden" name="subject_id" id="edit_subject_id">
                
                <div class="form-group">
                    <label>Subject Name *</label>
                    <input type="text" name="subject_name" id="edit_subject_name" class="form-control" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Subject Code *</label>
                        <input type="text" name="subject_code" id="edit_subject_code" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Credits *</label>
                        <input type="number" name="credits" id="edit_credits" class="form-control" min="1" max="6" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Semester *</label>
                    <select name="semester_id" id="edit_semester_id" class="form-control" required>
                        <option value="">Select Semester</option>
                        <?php 
                        $semesters->data_seek(0);
                        while($sem = $semesters->fetch_assoc()): 
                        ?>
                        <option value="<?php echo $sem['semester_id']; ?>">
                            <?php echo $sem['semester_name']; ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <button type="submit" name="edit_subject" class="btn btn-primary" style="width: 100%; margin-top: 10px;">
                    <i class="fas fa-save"></i> Update Subject
                </button>
            </form>
        </div>
    </div>

    <script>
        function openAddModal() {
            document.getElementById('addModal').classList.add('active');
        }

        function closeAddModal() {
            document.getElementById('addModal').classList.remove('active');
        }

        function openEditModal(subject) {
            document.getElementById('edit_subject_id').value = subject.subject_id;
            document.getElementById('edit_subject_name').value = subject.subject_name;
            document.getElementById('edit_subject_code').value = subject.subject_code;
            document.getElementById('edit_credits').value = subject.credits;
            document.getElementById('edit_semester_id').value = subject.semester_id;
            document.getElementById('editModal').classList.add('active');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        function confirmDelete(subjectId) {
            if (confirm('Are you sure you want to delete this subject? This action cannot be undone.')) {
                window.location.href = 'dept_subjects.php?delete=' + subjectId;
            }
        }

        // Close modal on outside click
        window.onclick = function(event) {
            const addModal = document.getElementById('addModal');
            const editModal = document.getElementById('editModal');
            if (event.target == addModal) {
                closeAddModal();
            }
            if (event.target == editModal) {
                closeEditModal();
            }
        }
    </script>
</body>
</html>
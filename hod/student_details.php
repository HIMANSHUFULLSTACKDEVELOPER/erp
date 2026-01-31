<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('hod')) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];
$student_id = $_GET['id'] ?? 0;

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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_semester':
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
                
            case 'toggle_active':
                $ss_id = $_POST['ss_id'];
                
                // Deactivate all semesters for this student
                $conn->query("UPDATE student_semesters SET is_active = 0 WHERE student_id = $student_id");
                
                // Activate the selected semester
                $sql = "UPDATE student_semesters SET is_active = 1 WHERE id = ? AND student_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ii", $ss_id, $student_id);
                
                if ($stmt->execute()) {
                    echo json_encode(['success' => true, 'message' => 'Semester activated successfully!']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error: ' . $conn->error]);
                }
                exit;
                break;
        }
        header("Location: student_details.php?id=$student_id");
        exit;
    }
}

// Get all semesters for this student
$semesters_query = "SELECT ss.*, sem.semester_name, sem.semester_number, sec.section_name
                    FROM student_semesters ss
                    JOIN semesters sem ON ss.semester_id = sem.semester_id
                    LEFT JOIN sections sec ON ss.section_id = sec.section_id
                    WHERE ss.student_id = $student_id
                    ORDER BY sem.semester_number";
$student_semesters = $conn->query($semesters_query);

// Get available semesters
$all_semesters = $conn->query("SELECT * FROM semesters ORDER BY semester_number");

// Get all sections
$sections = $conn->query("SELECT * FROM sections ORDER BY section_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $student['full_name']; ?> - Details</title>
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

        .student-profile {
            display: flex;
            align-items: start;
            gap: 30px;
            margin-bottom: 30px;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 20px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: 700;
        }

        .profile-info h2 {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 15px;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-item i {
            color: var(--primary);
            width: 20px;
        }

        .info-item strong {
            color: var(--gray);
            font-size: 0.85rem;
            min-width: 140px;
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

        /* Semester Cards */
        .semesters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        .semester-card {
            background: var(--white);
            border: 2px solid var(--light-gray);
            border-radius: 15px;
            padding: 20px;
            transition: all 0.3s;
            cursor: pointer;
            position: relative;
        }

        .semester-card:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 20px rgba(249, 115, 22, 0.15);
            transform: translateY(-3px);
        }

        .semester-card.active {
            border-color: var(--success);
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.05), rgba(21, 128, 61, 0.05));
        }

        .semester-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .semester-card h4 {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--dark);
        }

        .semester-card-info {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .semester-card-info p {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            color: var(--gray);
        }

        .semester-card-info i {
            color: var(--primary);
            width: 16px;
        }

        .activate-btn {
            position: absolute;
            top: 15px;
            right: 15px;
            background: var(--white);
            border: 2px solid var(--primary);
            color: var(--primary);
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            opacity: 0;
        }

        .semester-card:hover .activate-btn {
            opacity: 1;
        }

        .semester-card.active .activate-btn {
            opacity: 0 !important;
        }

        .activate-btn:hover {
            background: var(--primary);
            color: var(--white);
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
                    <h1>Student Details</h1>
                    <p><?php echo $hod['department_name']; ?> Department</p>
                </div>
                <a href="manage_students.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Students
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

            <!-- Student Profile Card -->
            <div class="card">
                <div class="student-profile">
                    <div class="profile-avatar">
                        <?php echo strtoupper(substr($student['full_name'], 0, 1)); ?>
                    </div>
                    <div class="profile-info">
                        <h2><?php echo $student['full_name']; ?></h2>
                        <span class="badge primary"><?php echo $student['admission_number']; ?></span>
                        
                        <div class="info-grid">
                            <div class="info-item">
                                <i class="fas fa-envelope"></i>
                                <strong>Email:</strong>
                                <span><?php echo $student['email']; ?></span>
                            </div>
                            <div class="info-item">
                                <i class="fas fa-phone"></i>
                                <strong>Phone:</strong>
                                <span><?php echo $student['phone'] ?: 'N/A'; ?></span>
                            </div>
                            <div class="info-item">
                                <i class="fas fa-graduation-cap"></i>
                                <strong>Course:</strong>
                                <span><?php echo $student['course_name']; ?></span>
                            </div>
                            <div class="info-item">
                                <i class="fas fa-building"></i>
                                <strong>Department:</strong>
                                <span><?php echo $student['department_name']; ?></span>
                            </div>
                            <div class="info-item">
                                <i class="fas fa-calendar"></i>
                                <strong>Admission Year:</strong>
                                <span><?php echo $student['admission_year']; ?></span>
                            </div>
                            <div class="info-item">
                                <i class="fas fa-birthday-cake"></i>
                                <strong>Date of Birth:</strong>
                                <span><?php echo date('d M Y', strtotime($student['date_of_birth'])); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Semesters Section -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-layer-group"></i> Student Semesters</h3>
                    <button class="btn btn-primary" onclick="openPromoteModal()">
                        <i class="fas fa-plus"></i> Add New Semester
                    </button>
                </div>

                <div class="semesters-grid">
                    <?php 
                    if ($student_semesters->num_rows > 0):
                        while($sem = $student_semesters->fetch_assoc()): 
                    ?>
                    <div class="semester-card <?php echo $sem['is_active'] ? 'active' : ''; ?>" onclick="viewSemester(<?php echo $sem['semester_id']; ?>, event)">
                        <?php if (!$sem['is_active']): ?>
                        <button class="activate-btn" onclick="activateSemester(<?php echo $sem['id']; ?>, event)">
                            <i class="fas fa-check"></i> Activate
                        </button>
                        <?php endif; ?>
                        
                        <div class="semester-card-header">
                            <h4><?php echo $sem['semester_name']; ?></h4>
                            <?php if ($sem['is_active']): ?>
                            <span class="badge success"><i class="fas fa-check"></i> Active</span>
                            <?php endif; ?>
                        </div>
                        <div class="semester-card-info">
                            <p>
                                <i class="fas fa-layer-group"></i>
                                <strong>Section:</strong> <?php echo $sem['section_name'] ?: 'N/A'; ?>
                            </p>
                            <p>
                                <i class="fas fa-calendar-alt"></i>
                                <strong>Academic Year:</strong> <?php echo $sem['academic_year']; ?>
                            </p>
                            <p>
                                <i class="fas fa-clock"></i>
                                <strong>Enrolled:</strong> <?php echo date('d M Y', strtotime($sem['created_at'])); ?>
                            </p>
                        </div>
                    </div>
                    <?php 
                        endwhile;
                    else:
                    ?>
                    <div style="grid-column: 1/-1; text-align: center; padding: 40px;">
                        <i class="fas fa-inbox" style="font-size: 3rem; color: var(--gray); margin-bottom: 15px;"></i>
                        <p style="color: var(--gray); font-size: 1.1rem;">No semesters assigned yet</p>
                        <button class="btn btn-primary" onclick="openPromoteModal()" style="margin-top: 15px;">
                            <i class="fas fa-plus"></i> Add First Semester
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Semester Modal -->
    <div id="promoteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New Semester</h3>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_semester">
                
                <div class="form-group">
                    <label>Semester *</label>
                    <select name="semester_id" class="form-control" required>
                        <option value="">Select Semester</option>
                        <?php 
                        while($sem = $all_semesters->fetch_assoc()): 
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
                    <i class="fas fa-check"></i> Add Semester
                </button>
            </form>
        </div>
    </div>

    <script>
        function openPromoteModal() {
            document.getElementById('promoteModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('promoteModal').classList.remove('active');
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }

        function viewSemester(semesterId, event) {
            // Don't navigate if clicking the activate button
            if (event.target.closest('.activate-btn')) {
                return;
            }
            window.location.href = `view_semester.php?student_id=<?php echo $student_id; ?>&semester_id=${semesterId}`;
        }

        function activateSemester(ssId, event) {
            event.stopPropagation(); // Prevent card click
            
            if (!confirm('Are you sure you want to activate this semester? This will deactivate the current active semester.')) {
                return;
            }

            // Create FormData
            const formData = new FormData();
            formData.append('action', 'toggle_active');
            formData.append('ss_id', ssId);

            // Send AJAX request
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Reload page to show updated status
                    window.location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while activating the semester.');
            });
        }
    </script>
</body>
</html>
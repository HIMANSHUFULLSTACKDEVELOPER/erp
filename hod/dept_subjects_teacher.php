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
$success_msg = '';
$error_msg = '';

// Add Subject-Teacher Assignment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_assignment'])) {
    $subject_id = $_POST['subject_id'];
    $teacher_id = $_POST['teacher_id'];
    $semester_id = $_POST['semester_id'];
    $section_id = !empty($_POST['section_id']) ? $_POST['section_id'] : NULL;
    $academic_year = $_POST['academic_year'];
    
    // Check if assignment already exists
    $check_sql = "SELECT * FROM subject_teachers 
                  WHERE subject_id = ? AND teacher_id = ? AND semester_id = ? 
                  AND (section_id = ? OR (section_id IS NULL AND ? IS NULL))
                  AND academic_year = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("iiiiis", $subject_id, $teacher_id, $semester_id, $section_id, $section_id, $academic_year);
    $check_stmt->execute();
    $existing = $check_stmt->get_result()->fetch_assoc();
    
    if ($existing) {
        $error_msg = "This assignment already exists!";
    } else {
        $insert_sql = "INSERT INTO subject_teachers (subject_id, teacher_id, semester_id, section_id, academic_year) 
                      VALUES (?, ?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("iiiis", $subject_id, $teacher_id, $semester_id, $section_id, $academic_year);
        
        if ($insert_stmt->execute()) {
            $success_msg = "Subject-Teacher assignment added successfully!";
        } else {
            $error_msg = "Error adding assignment: " . $conn->error;
        }
    }
}

// Delete Subject-Teacher Assignment
if (isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    
    $delete_sql = "DELETE FROM subject_teachers WHERE id = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("i", $delete_id);
    
    if ($delete_stmt->execute()) {
        $success_msg = "Assignment deleted successfully!";
    } else {
        $error_msg = "Error deleting assignment: " . $conn->error;
    }
}

// Get all subject-teacher assignments for the department
$assignments_sql = "SELECT st.id, st.academic_year,
                    sub.subject_name, sub.subject_code,
                    t.full_name as teacher_name,
                    sem.semester_name,
                    sec.section_name,
                    st.created_at
                    FROM subject_teachers st
                    JOIN subjects sub ON st.subject_id = sub.subject_id
                    JOIN teachers t ON st.teacher_id = t.teacher_id
                    JOIN semesters sem ON st.semester_id = sem.semester_id
                    LEFT JOIN sections sec ON st.section_id = sec.section_id
                    WHERE sub.department_id = ?
                    ORDER BY sem.semester_number, sub.subject_name, sec.section_name";
$assignments_stmt = $conn->prepare($assignments_sql);
$assignments_stmt->bind_param("i", $dept_id);
$assignments_stmt->execute();
$assignments = $assignments_stmt->get_result();

// Get subjects for the department
$subjects = $conn->query("SELECT subject_id, subject_name, subject_code, semester_id 
                         FROM subjects 
                         WHERE department_id = $dept_id 
                         ORDER BY subject_name");

// Get teachers for the department
$teachers = $conn->query("SELECT teacher_id, full_name, designation 
                         FROM teachers 
                         WHERE department_id = $dept_id 
                         ORDER BY full_name");

// Get all semesters
$semesters = $conn->query("SELECT semester_id, semester_name, semester_number 
                          FROM semesters 
                          ORDER BY semester_number");

// Get all sections
$sections = $conn->query("SELECT section_id, section_name FROM sections ORDER BY section_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subject-Teacher Management - College ERP</title>
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

        .back-btn {
            background: linear-gradient(135deg, var(--gray), #57534e);
            color: var(--white);
            border: none;
            padding: 12px 28px;
            border-radius: 15px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-family: 'Outfit', sans-serif;
            box-shadow: 0 4px 15px rgba(120, 113, 108, 0.3);
            text-decoration: none;
            display: inline-block;
        }

        .back-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 25px rgba(120, 113, 108, 0.4);
        }

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 15px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            font-weight: 500;
        }

        .alert i {
            margin-right: 12px;
            font-size: 1.2rem;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.15);
            color: #15803d;
            border-left: 4px solid var(--success);
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.15);
            color: #b91c1c;
            border-left: 4px solid var(--danger);
        }

        /* Card */
        .card {
            background: var(--white);
            padding: 35px;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--light-gray);
        }

        .card-header h2 {
            font-size: 1.6rem;
            font-weight: 700;
            display: flex;
            align-items: center;
        }

        .card-header h2 i {
            color: var(--primary);
            margin-right: 15px;
        }

        /* Form */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--dark);
            font-size: 0.9rem;
        }

        .form-group label .required {
            color: var(--danger);
        }

        .form-control {
            padding: 12px 18px;
            border: 2px solid #e7e5e4;
            border-radius: 12px;
            font-family: 'Outfit', sans-serif;
            font-size: 0.95rem;
            transition: all 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
        }

        .btn {
            padding: 14px 32px;
            border: none;
            border-radius: 15px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-family: 'Outfit', sans-serif;
            font-size: 1rem;
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

        /* Table */
        .table-responsive {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table thead {
            background: linear-gradient(135deg, rgba(249, 115, 22, 0.1), rgba(234, 88, 12, 0.1));
        }

        .table th {
            text-align: left;
            padding: 18px 15px;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.85rem;
            text-transform: uppercase;
            border-bottom: 2px solid var(--light-gray);
        }

        .table td {
            padding: 18px 15px;
            border-bottom: 1px solid var(--light-gray);
        }

        .table tbody tr:hover {
            background: rgba(249, 115, 22, 0.03);
        }

        .badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-primary {
            background: linear-gradient(135deg, rgba(249, 115, 22, 0.2), rgba(234, 88, 12, 0.2));
            color: var(--primary);
        }

        .badge-success {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.2), rgba(22, 163, 74, 0.2));
            color: var(--success);
        }

        .badge-info {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.2), rgba(37, 99, 235, 0.2));
            color: #3b82f6;
        }

        .btn-delete {
            background: linear-gradient(135deg, var(--danger), #dc2626);
            color: var(--white);
            padding: 8px 16px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }

        .btn-delete:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 4rem;
            color: var(--light-gray);
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 10px;
            color: var(--dark);
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
                    <h1>Subject-Teacher Assignments</h1>
                    <p>Manage subject-teacher mappings for <?php echo $hod['department_name']; ?></p>
                </div>
                <a href="hod_dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            </div>

            <?php if ($success_msg): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $success_msg; ?>
                </div>
            <?php endif; ?>

            <?php if ($error_msg): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error_msg; ?>
                </div>
            <?php endif; ?>

            <!-- Add Assignment Form -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-plus-circle"></i> Add New Assignment</h2>
                </div>
                <form method="POST" action="">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Subject <span class="required">*</span></label>
                            <select name="subject_id" class="form-control" required>
                                <option value="">Select Subject</option>
                                <?php 
                                $subjects->data_seek(0);
                                while($subject = $subjects->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $subject['subject_id']; ?>">
                                        <?php echo $subject['subject_name']; ?> (<?php echo $subject['subject_code']; ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Teacher <span class="required">*</span></label>
                            <select name="teacher_id" class="form-control" required>
                                <option value="">Select Teacher</option>
                                <?php 
                                $teachers->data_seek(0);
                                while($teacher = $teachers->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $teacher['teacher_id']; ?>">
                                        <?php echo $teacher['full_name']; ?>
                                        <?php echo $teacher['designation'] ? '(' . $teacher['designation'] . ')' : ''; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Semester <span class="required">*</span></label>
                            <select name="semester_id" class="form-control" required>
                                <option value="">Select Semester</option>
                                <?php 
                                $semesters->data_seek(0);
                                while($semester = $semesters->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $semester['semester_id']; ?>">
                                        <?php echo $semester['semester_name']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Section (Optional)</label>
                            <select name="section_id" class="form-control">
                                <option value="">All Sections</option>
                                <?php 
                                $sections->data_seek(0);
                                while($section = $sections->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $section['section_id']; ?>">
                                        Section <?php echo $section['section_name']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Academic Year <span class="required">*</span></label>
                            <input type="text" name="academic_year" class="form-control" 
                                   placeholder="e.g., 2025-2026" 
                                   value="2025-2026" required>
                        </div>
                    </div>
                    <button type="submit" name="add_assignment" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Assignment
                    </button>
                </form>
            </div>

            <!-- Assignments List -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-list"></i> Current Assignments</h2>
                </div>
                <div class="table-responsive">
                    <?php if ($assignments->num_rows > 0): ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Subject</th>
                                    <th>Subject Code</th>
                                    <th>Teacher</th>
                                    <th>Semester</th>
                                    <th>Section</th>
                                    <th>Academic Year</th>
                                    <th>Added On</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($assignment = $assignments->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo $assignment['subject_name']; ?></strong></td>
                                        <td><span class="badge badge-info"><?php echo $assignment['subject_code']; ?></span></td>
                                        <td><?php echo $assignment['teacher_name']; ?></td>
                                        <td><span class="badge badge-primary"><?php echo $assignment['semester_name']; ?></span></td>
                                        <td>
                                            <?php 
                                            echo $assignment['section_name'] 
                                                ? '<span class="badge badge-success">Section ' . $assignment['section_name'] . '</span>' 
                                                : '<span class="badge" style="background: #e5e5e5; color: #666;">All Sections</span>'; 
                                            ?>
                                        </td>
                                        <td><?php echo $assignment['academic_year']; ?></td>
                                        <td><?php echo date('M d, Y', strtotime($assignment['created_at'])); ?></td>
                                        <td>
                                            <a href="?delete_id=<?php echo $assignment['id']; ?>" 
                                               class="btn-delete"
                                               onclick="return confirm('Are you sure you want to delete this assignment?')">
                                                <i class="fas fa-trash"></i> Delete
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-user-tie"></i>
                            <h3>No Assignments Yet</h3>
                            <p>Start by adding subject-teacher assignments using the form above.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
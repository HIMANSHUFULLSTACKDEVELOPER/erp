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
$teacher_id = $hod['teacher_id'];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'assign_class_teacher':
                $teacher = intval($_POST['teacher_id']);
                $semester = intval($_POST['semester_id']);
                $section = intval($_POST['section_id']);
                $academic_year = trim($_POST['academic_year']);
                $assigned_date = $_POST['assigned_date'];
                
                // Check if class teacher already exists for this combination
                $check = $conn->prepare("SELECT class_teacher_id FROM class_teachers 
                                        WHERE department_id = ? AND semester_id = ? 
                                        AND section_id = ? AND academic_year = ? AND is_active = 1");
                $check->bind_param("iiis", $dept_id, $semester, $section, $academic_year);
                $check->execute();
                $result = $check->get_result();
                
                if ($result->num_rows > 0) {
                    $_SESSION['error'] = "A class teacher is already assigned to this class!";
                } else {
                    $stmt = $conn->prepare("INSERT INTO class_teachers 
                                          (teacher_id, department_id, semester_id, section_id, academic_year, assigned_date) 
                                          VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("iiiiss", $teacher, $dept_id, $semester, $section, $academic_year, $assigned_date);
                    
                    if ($stmt->execute()) {
                        $_SESSION['success'] = "Class teacher assigned successfully!";
                    } else {
                        $_SESSION['error'] = "Failed to assign class teacher!";
                    }
                }
                break;
                
            case 'remove_class_teacher':
                $ct_id = intval($_POST['class_teacher_id']);
                $stmt = $conn->prepare("UPDATE class_teachers SET is_active = 0 WHERE class_teacher_id = ?");
                $stmt->bind_param("i", $ct_id);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Class teacher removed successfully!";
                } else {
                    $_SESSION['error'] = "Failed to remove class teacher!";
                }
                break;
        }
        header("Location: manage_class_teachers.php");
        exit;
    }
}

// Get all class teachers in department
$class_teachers = $conn->query("SELECT * FROM v_class_teacher_details WHERE department_id = $dept_id ORDER BY semester_id, section_id");

// Get available teachers in department
$available_teachers = $conn->query("SELECT t.teacher_id, t.full_name, t.designation 
                                   FROM teachers t 
                                   WHERE t.department_id = $dept_id");

// Get semesters
$semesters = $conn->query("SELECT * FROM semesters ORDER BY semester_number");

// Get sections
$sections = $conn->query("SELECT * FROM sections ORDER BY section_name");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Class Teachers - College ERP</title>
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

        .btn {
            padding: 12px 28px;
            border-radius: 15px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
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

        .btn-secondary {
            background: var(--gray);
            color: var(--white);
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 0.85rem;
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

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 12px 16px;
            border: 2px solid var(--light-gray);
            border-radius: 12px;
            font-family: 'Outfit', sans-serif;
            font-size: 0.95rem;
            transition: all 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            text-align: left;
            padding: 12px;
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

        .badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge.success { 
            background: rgba(34, 197, 94, 0.15);
            color: var(--success); 
        }

        .badge.primary { 
            background: rgba(249, 115, 22, 0.15);
            color: var(--primary); 
        }

        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.15);
            color: var(--success);
            border-left: 4px solid var(--success);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.15);
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--white);
            padding: 35px;
            border-radius: 20px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .modal-header h3 {
            font-size: 1.5rem;
            font-weight: 700;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray);
        }

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
                    <h1>Class Teachers Management</h1>
                    <p>Assign and manage class teachers for each section</p>
                </div>
                <button class="btn btn-primary" onclick="openModal()">
                    <i class="fas fa-plus"></i> Assign Class Teacher
                </button>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-users"></i> Current Class Teachers</h3>
                </div>

                <?php if ($class_teachers->num_rows > 0): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Teacher Name</th>
                                <th>Class</th>
                                <th>Academic Year</th>
                                <th>Students</th>
                                <th>Assigned Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($ct = $class_teachers->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <strong><?php echo $ct['teacher_name']; ?></strong><br>
                                    <small style="color: var(--gray);"><?php echo $ct['designation'] ?? 'N/A'; ?></small>
                                </td>
                                <td>
                                    <strong><?php echo $ct['semester_name']; ?> - Section <?php echo $ct['section_name']; ?></strong>
                                </td>
                                <td><?php echo $ct['academic_year']; ?></td>
                                <td>
                                    <span class="badge primary"><?php echo $ct['total_students']; ?> Students</span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($ct['assigned_date'])); ?></td>
                                <td>
                                    <span class="badge success">Active</span>
                                </td>
                                <td>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Remove this class teacher?');">
                                        <input type="hidden" name="action" value="remove_class_teacher">
                                        <input type="hidden" name="class_teacher_id" value="<?php echo $ct['class_teacher_id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">
                                            <i class="fas fa-times"></i> Remove
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-user-tie"></i>
                        <h3>No Class Teachers Assigned</h3>
                        <p>Click "Assign Class Teacher" to get started</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Add Class Teacher Modal -->
    <div class="modal" id="assignModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Assign Class Teacher</h3>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="assign_class_teacher">
                
                <div class="form-group">
                    <label>Select Teacher *</label>
                    <select name="teacher_id" required>
                        <option value="">Choose a teacher</option>
                        <?php 
                        $available_teachers->data_seek(0);
                        while($teacher = $available_teachers->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $teacher['teacher_id']; ?>">
                                <?php echo $teacher['full_name']; ?> 
                                <?php echo $teacher['designation'] ? '(' . $teacher['designation'] . ')' : ''; ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Semester *</label>
                        <select name="semester_id" required>
                            <option value="">Select semester</option>
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

                    <div class="form-group">
                        <label>Section *</label>
                        <select name="section_id" required>
                            <option value="">Select section</option>
                            <?php 
                            $sections->data_seek(0);
                            while($sec = $sections->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $sec['section_id']; ?>">
                                    Section <?php echo $sec['section_name']; ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Academic Year *</label>
                        <input type="text" name="academic_year" placeholder="e.g., 2025-2026" required>
                    </div>

                    <div class="form-group">
                        <label>Assigned Date *</label>
                        <input type="date" name="assigned_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>

                <div style="display: flex; gap: 15px; margin-top: 25px;">
                    <button type="submit" class="btn btn-primary" style="flex: 1;">
                        <i class="fas fa-check"></i> Assign Class Teacher
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal()" style="flex: 1;">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal() {
            document.getElementById('assignModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('assignModal').classList.remove('active');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('assignModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>
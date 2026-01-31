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
            case 'assign_substitute':
                $original_teacher = intval($_POST['original_teacher_id']);
                $substitute_teacher = intval($_POST['substitute_teacher_id']);
                $semester = intval($_POST['semester_id']);
                $section = intval($_POST['section_id']);
                $subject = !empty($_POST['subject_id']) ? intval($_POST['subject_id']) : NULL;
                $substitute_date = $_POST['substitute_date'];
                $reason = trim($_POST['reason']);
                
                $stmt = $conn->prepare("INSERT INTO substitute_teachers 
                                      (original_teacher_id, substitute_teacher_id, department_id, semester_id, 
                                       section_id, subject_id, substitute_date, reason, assigned_by) 
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iiiiiissi", $original_teacher, $substitute_teacher, $dept_id, 
                                 $semester, $section, $subject, $substitute_date, $reason, $teacher_id);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Substitute teacher assigned successfully!";
                } else {
                    $_SESSION['error'] = "Failed to assign substitute teacher!";
                }
                break;
                
            case 'delete_substitute':
                $sub_id = intval($_POST['substitute_id']);
                $stmt = $conn->prepare("DELETE FROM substitute_teachers WHERE substitute_id = ?");
                $stmt->bind_param("i", $sub_id);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Substitute assignment deleted successfully!";
                } else {
                    $_SESSION['error'] = "Failed to delete substitute assignment!";
                }
                break;
        }
        header("Location: manage_substitutes.php");
        exit;
    }
}

// Get filter parameters
$filter_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Get substitute assignments
$substitutes_query = "SELECT * FROM v_substitute_assignments 
                     WHERE substitute_date >= ?
                     ORDER BY substitute_date DESC";
$stmt = $conn->prepare($substitutes_query);
$stmt->bind_param("s", $filter_date);
$stmt->execute();
$substitutes = $stmt->get_result();

// Get available teachers in department
$available_teachers = $conn->query("SELECT t.teacher_id, t.full_name, t.designation 
                                   FROM teachers t 
                                   WHERE t.department_id = $dept_id");

// Get semesters
$semesters = $conn->query("SELECT * FROM semesters ORDER BY semester_number");

// Get sections
$sections = $conn->query("SELECT * FROM sections ORDER BY section_name");

// Get subjects
$subjects = $conn->query("SELECT * FROM subjects WHERE department_id = $dept_id ORDER BY subject_name");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Substitute Teachers - College ERP</title>
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

        .filter-bar {
            background: var(--white);
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            display: flex;
            gap: 15px;
            align-items: center;
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

        .badge.warning { 
            background: rgba(234, 179, 8, 0.15);
            color: var(--warning); 
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
            max-width: 700px;
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
                    <h1>Substitute Teachers</h1>
                    <p>Manage substitute teacher assignments for absent faculty</p>
                </div>
                <button class="btn btn-primary" onclick="openModal()">
                    <i class="fas fa-plus"></i> Assign Substitute
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

            <div class="filter-bar">
                <form method="GET" style="display: flex; gap: 15px; align-items: end; flex: 1;">
                    <div class="form-group" style="margin-bottom: 0; flex: 1;">
                        <label>Filter by Date</label>
                        <input type="date" name="date" value="<?php echo $filter_date; ?>">
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                </form>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-exchange-alt"></i> Substitute Assignments</h3>
                </div>

                <?php if ($substitutes->num_rows > 0): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Original Teacher</th>
                                <th>Substitute Teacher</th>
                                <th>Class/Subject</th>
                                <th>Reason</th>
                                <th>Assigned By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($sub = $substitutes->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <strong><?php echo date('M d, Y', strtotime($sub['substitute_date'])); ?></strong>
                                </td>
                                <td>
                                    <?php echo $sub['original_teacher_name']; ?>
                                </td>
                                <td>
                                    <span class="badge primary"><?php echo $sub['substitute_teacher_name']; ?></span>
                                </td>
                                <td>
                                    <strong><?php echo $sub['semester_name']; ?> - Sec <?php echo $sub['section_name']; ?></strong><br>
                                    <?php if ($sub['subject_name']): ?>
                                        <small style="color: var(--gray);"><?php echo $sub['subject_name']; ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo $sub['reason'] ?: 'N/A'; ?>
                                </td>
                                <td>
                                    <?php echo $sub['assigned_by_name']; ?>
                                </td>
                                <td>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this assignment?');">
                                        <input type="hidden" name="action" value="delete_substitute">
                                        <input type="hidden" name="substitute_id" value="<?php echo $sub['substitute_id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-exchange-alt"></i>
                        <h3>No Substitute Assignments</h3>
                        <p>No substitute assignments found for the selected date</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Assign Substitute Modal -->
    <div class="modal" id="assignModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Assign Substitute Teacher</h3>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="assign_substitute">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Original Teacher (Absent) *</label>
                        <select name="original_teacher_id" required>
                            <option value="">Select teacher</option>
                            <?php 
                            $available_teachers->data_seek(0);
                            while($teacher = $available_teachers->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $teacher['teacher_id']; ?>">
                                    <?php echo $teacher['full_name']; ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Substitute Teacher *</label>
                        <select name="substitute_teacher_id" required>
                            <option value="">Select substitute</option>
                            <?php 
                            $available_teachers->data_seek(0);
                            while($teacher = $available_teachers->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $teacher['teacher_id']; ?>">
                                    <?php echo $teacher['full_name']; ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
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

                <div class="form-group">
                    <label>Subject (Optional)</label>
                    <select name="subject_id">
                        <option value="">All subjects / General</option>
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
                    <label>Substitute Date *</label>
                    <input type="date" name="substitute_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>

                <div class="form-group">
                    <label>Reason for Absence</label>
                    <textarea name="reason" rows="3" placeholder="e.g., Medical leave, Personal emergency"></textarea>
                </div>

                <div style="display: flex; gap: 15px; margin-top: 25px;">
                    <button type="submit" class="btn btn-primary" style="flex: 1;">
                        <i class="fas fa-check"></i> Assign Substitute
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

        window.onclick = function(event) {
            const modal = document.getElementById('assignModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>
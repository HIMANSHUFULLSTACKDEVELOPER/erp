<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('admin')) {
    redirect('login.php');
}

// Get filter parameters
$selected_department = isset($_GET['department']) ? $_GET['department'] : '';
$selected_semester = isset($_GET['semester']) ? $_GET['semester'] : '';
$selected_section = isset($_GET['section']) ? $_GET['section'] : '';
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Get all departments for filter
$departments = $conn->query("SELECT department_id, department_name FROM departments ORDER BY department_name");

// Get all semesters
$semesters = $conn->query("SELECT semester_id, semester_name FROM semesters ORDER BY semester_number");

// Get sections based on selected department (or all)
if ($selected_department) {
    $sections = $conn->query("SELECT DISTINCT s.section_id, s.section_name 
                             FROM sections s 
                             JOIN student_semesters ss ON s.section_id = ss.section_id 
                             JOIN students st ON ss.student_id = st.student_id 
                             WHERE st.department_id = $selected_department
                             ORDER BY s.section_name");
} else {
    $sections = $conn->query("SELECT section_id, section_name FROM sections ORDER BY section_name");
}

// Build the query to get teachers' attendance status
$query = "SELECT DISTINCT
    t.teacher_id,
    t.full_name as teacher_name,
    u.email,
    d.department_name,
    
    -- Count total classes assigned to teacher
    (SELECT COUNT(DISTINCT st.subject_id) 
     FROM subject_teachers st 
     WHERE st.teacher_id = t.teacher_id";

if ($selected_semester) {
    $query .= " AND st.semester_id = $selected_semester";
}
if ($selected_section) {
    $query .= " AND st.section_id = $selected_section";
}

$query .= ") as total_classes,
    
    -- Count classes marked (attendance taken)
    (SELECT COUNT(DISTINCT a.subject_id)
     FROM attendance a
     WHERE a.marked_by = t.teacher_id
     AND DATE(a.attendance_date) = '$selected_date'";

if ($selected_department) {
    $query .= " AND a.department_id = $selected_department";
}
if ($selected_semester) {
    $query .= " AND a.semester_id = $selected_semester";
}
if ($selected_section) {
    $query .= " AND a.section_id = $selected_section";
}

$query .= ") as marked_classes,
    
    -- Count pending classes
    (SELECT COUNT(DISTINCT st.subject_id)
     FROM subject_teachers st
     WHERE st.teacher_id = t.teacher_id
     AND st.subject_id NOT IN (
         SELECT a.subject_id 
         FROM attendance a 
         WHERE a.marked_by = t.teacher_id 
         AND DATE(a.attendance_date) = '$selected_date'";

if ($selected_department) {
    $query .= " AND a.department_id = $selected_department";
}
if ($selected_semester) {
    $query .= " AND a.semester_id = $selected_semester";
}
if ($selected_section) {
    $query .= " AND a.section_id = $selected_section";
}

$query .= ")";

if ($selected_semester) {
    $query .= " AND st.semester_id = $selected_semester";
}
if ($selected_section) {
    $query .= " AND st.section_id = $selected_section";
}

$query .= ") as pending_classes,
    
    -- Get subjects assigned to teacher
    (SELECT GROUP_CONCAT(DISTINCT sub.subject_code ORDER BY sub.subject_code SEPARATOR ', ')
     FROM subject_teachers st
     JOIN subjects sub ON st.subject_id = sub.subject_id
     WHERE st.teacher_id = t.teacher_id";

if ($selected_semester) {
    $query .= " AND st.semester_id = $selected_semester";
}
if ($selected_section) {
    $query .= " AND st.section_id = $selected_section";
}

$query .= ") as subjects

FROM teachers t
JOIN users u ON t.user_id = u.user_id
JOIN departments d ON t.department_id = d.department_id
WHERE 1=1";

if ($selected_department) {
    $query .= " AND t.department_id = $selected_department";
}

if ($search) {
    $search_escaped = $conn->real_escape_string($search);
    $query .= " AND (t.full_name LIKE '%$search_escaped%' OR u.email LIKE '%$search_escaped%')";
}

$query .= " ORDER BY t.full_name";

$teachers = $conn->query($query);

// Calculate statistics
$total_teachers = 0;
$teachers_marked = 0;
$teachers_pending = 0;

$temp_result = $conn->query($query);
while ($row = $temp_result->fetch_assoc()) {
    $total_teachers++;
    if ($row['marked_classes'] > 0 && $row['marked_classes'] == $row['total_classes']) {
        $teachers_marked++;
    } else if ($row['pending_classes'] > 0) {
        $teachers_pending++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Attendance Status - College ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --secondary: #8b5cf6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #1f2937;
            --gray: #6b7280;
            --light-gray: #f3f4f6;
            --white: #ffffff;
            --sidebar-width: 260px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--light-gray);
            color: var(--dark);
        }

        .dashboard {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar - Same as your existing design */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--dark);
            color: var(--white);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 30px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .sidebar-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
        }

        .sidebar-header p {
            font-size: 0.85rem;
            opacity: 0.7;
            margin-top: 5px;
        }

        .sidebar-menu {
            padding: 20px 0;
        }

        .menu-item {
            padding: 15px 20px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            display: flex;
            align-items: center;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .menu-item:hover {
            background: var(--primary);
            color: var(--white);
            padding-left: 25px;
        }

        .menu-item i {
            margin-right: 12px;
            width: 20px;
            text-align: center;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 30px;
        }

        .top-bar {
            background: var(--white);
            padding: 20px 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .top-bar h1 {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
        }

        .back-btn {
            background: var(--gray);
            color: var(--white);
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .back-btn:hover {
            background: var(--dark);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--white);
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .stat-info h3 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-info p {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .stat-icon.blue { background: rgba(99, 102, 241, 0.1); color: var(--primary); }
        .stat-icon.green { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .stat-icon.orange { background: rgba(245, 158, 11, 0.1); color: var(--warning); }

        /* Filters */
        .filters-card {
            background: var(--white);
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 8px;
        }

        .form-group select,
        .form-group input {
            padding: 10px 15px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .form-group select:focus,
        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary);
            color: var(--white);
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-secondary {
            background: var(--gray);
            color: var(--white);
        }

        .btn-secondary:hover {
            background: var(--dark);
        }

        /* Table */
        .table-card {
            background: var(--white);
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .table-header {
            padding: 20px 25px;
            border-bottom: 1px solid var(--light-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-header h3 {
            font-size: 1.2rem;
            font-weight: 600;
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            padding: 10px 15px 10px 40px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            width: 300px;
        }

        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: var(--light-gray);
        }

        th {
            text-align: left;
            padding: 15px 20px;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            color: var(--gray);
        }

        td {
            padding: 15px 20px;
            border-bottom: 1px solid var(--light-gray);
        }

        tbody tr:hover {
            background: #fafafa;
        }

        .teacher-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .teacher-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: var(--primary);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1rem;
        }

        .teacher-details h4 {
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .teacher-details p {
            font-size: 0.85rem;
            color: var(--gray);
        }

        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .badge-danger {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .badge-info {
            background: rgba(99, 102, 241, 0.1);
            color: var(--primary);
        }

        .count-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 35px;
            height: 35px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
        }

        .count-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .count-warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .count-neutral {
            background: var(--light-gray);
            color: var(--dark);
        }

        .action-btn {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            background: var(--primary);
            color: var(--white);
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .action-btn:hover {
            background: var(--primary-dark);
            transform: scale(1.05);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }

            .sidebar {
                transform: translateX(-100%);
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .search-box input {
                width: 100%;
            }

            .table-container {
                overflow-x: scroll;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2>Admin Panel</h2>
                <p>College ERP System</p>
            </div>
            <nav class="sidebar-menu">
                <a href="index.php" class="menu-item">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="dailyattandance.php" class="menu-item">
                    <i class="fas fa-calendar-check"></i> Daily Report
                </a>
                <a href="consolidatereport.php" class="menu-item">
                    <i class="fas fa-chart-bar"></i> Consolidated Report
                </a>
                <a href="manage_students.php" class="menu-item">
                    <i class="fas fa-user-graduate"></i> Students
                </a>
                <a href="manage_teachers.php" class="menu-item">
                    <i class="fas fa-chalkboard-teacher"></i> Teachers
                </a>
                <a href="manage_departments.php" class="menu-item">
                    <i class="fas fa-building"></i> Departments
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-bar">
                <h1><i class="fas fa-clipboard-check"></i> All Teachers Status</h1>
                <a href="index.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-info">
                        <h3><?php echo $total_teachers; ?></h3>
                        <p>All Teachers</p>
                    </div>
                    <div class="stat-icon blue">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3><?php echo $teachers_marked; ?></h3>
                        <p>Marked</p>
                    </div>
                    <div class="stat-icon green">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3><?php echo $teachers_pending; ?></h3>
                        <p>Pending</p>
                    </div>
                    <div class="stat-icon orange">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <form method="GET" action="">
                    <div class="filters-grid">
                        <div class="form-group">
                            <label>Department</label>
                            <select name="department" id="department">
                                <option value="">All Departments</option>
                                <?php while($dept = $departments->fetch_assoc()): ?>
                                    <option value="<?php echo $dept['department_id']; ?>" 
                                        <?php echo $selected_department == $dept['department_id'] ? 'selected' : ''; ?>>
                                        <?php echo $dept['department_name']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Semester</label>
                            <select name="semester" id="semester">
                                <option value="">All Semesters</option>
                                <?php while($sem = $semesters->fetch_assoc()): ?>
                                    <option value="<?php echo $sem['semester_id']; ?>"
                                        <?php echo $selected_semester == $sem['semester_id'] ? 'selected' : ''; ?>>
                                        <?php echo $sem['semester_name']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Section</label>
                            <select name="section" id="section">
                                <option value="">All Sections</option>
                                <?php while($sec = $sections->fetch_assoc()): ?>
                                    <option value="<?php echo $sec['section_id']; ?>"
                                        <?php echo $selected_section == $sec['section_id'] ? 'selected' : ''; ?>>
                                        <?php echo $sec['section_name']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Date</label>
                            <input type="date" name="date" value="<?php echo $selected_date; ?>">
                        </div>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="admin_class_attendance_report.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Teachers Table -->
            <div class="table-card">
                <div class="table-header">
                    <h3>Complete list with attendance marking status</h3>
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search teachers..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>TEACHER</th>
                                <th>DEPARTMENT</th>
                                <th>CLASSES</th>
                                <th>MARKED</th>
                                <th>PENDING</th>
                                <th>SUBJECTS</th>
                                <th>STATUS</th>
                                <th>ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($teachers->num_rows > 0): ?>
                                <?php while($teacher = $teachers->fetch_assoc()): 
                                    $initials = strtoupper(substr($teacher['teacher_name'], 0, 2));
                                    $status = 'PENDING';
                                    $status_class = 'badge-warning';
                                    
                                    if($teacher['total_classes'] == 0) {
                                        $status = 'NO CLASSES';
                                        $status_class = 'badge-info';
                                    } else if($teacher['marked_classes'] == $teacher['total_classes']) {
                                        $status = 'COMPLETED';
                                        $status_class = 'badge-success';
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <div class="teacher-info">
                                            <div class="teacher-avatar"><?php echo $initials; ?></div>
                                            <div class="teacher-details">
                                                <h4><?php echo $teacher['teacher_name']; ?></h4>
                                                <p><?php echo $teacher['email']; ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?php echo $teacher['department_name']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="count-badge count-neutral">
                                            <?php echo $teacher['total_classes']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="count-badge count-success">
                                            <?php echo $teacher['marked_classes']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="count-badge count-warning">
                                            <?php echo $teacher['pending_classes']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small><?php echo $teacher['subjects'] ?: 'No subjects assigned'; ?></small>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $status_class; ?>">
                                            <?php echo $status; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="action-btn" 
                                                onclick="viewDetails(<?php echo $teacher['teacher_id']; ?>)"
                                                title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 40px;">
                                        <i class="fas fa-inbox" style="font-size: 3rem; color: var(--gray); margin-bottom: 10px;"></i>
                                        <p>No teachers found</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const searchValue = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const teacherName = row.querySelector('.teacher-details h4')?.textContent.toLowerCase() || '';
                const teacherEmail = row.querySelector('.teacher-details p')?.textContent.toLowerCase() || '';
                
                if (teacherName.includes(searchValue) || teacherEmail.includes(searchValue)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });

        // View details function
        function viewDetails(teacherId) {
            const date = '<?php echo $selected_date; ?>';
            const department = '<?php echo $selected_department; ?>';
            const semester = '<?php echo $selected_semester; ?>';
            const section = '<?php echo $selected_section; ?>';
            
            let url = `teacher_attendance_details.php?teacher_id=${teacherId}&date=${date}`;
            if (department) url += `&department=${department}`;
            if (semester) url += `&semester=${semester}`;
            if (section) url += `&section=${section}`;
            
            window.location.href = url;
        }

        // Auto-submit form when date changes
        document.querySelector('input[name="date"]').addEventListener('change', function() {
            this.form.submit();
        });
    </script>
</body>
</html>
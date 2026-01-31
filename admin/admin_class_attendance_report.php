<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('admin')) {
    redirect('login.php');
}

// Get filter parameters
$selected_department = isset($_GET['department']) ? intval($_GET['department']) : '';
$selected_semester = isset($_GET['semester']) ? intval($_GET['semester']) : '';
$selected_section = isset($_GET['section']) ? intval($_GET['section']) : '';
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Get all departments for filter
$departments = $conn->query("SELECT department_id, department_name FROM departments ORDER BY department_name");

// Get all semesters
$semesters = $conn->query("SELECT semester_id, semester_name, semester_number FROM semesters ORDER BY semester_number");

// Get sections
$sections = $conn->query("SELECT section_id, section_name FROM sections ORDER BY section_name");

// Build the query to get all classes with attendance status
$query = "SELECT DISTINCT
    sub.subject_id,
    sub.subject_name,
    sub.subject_code,
    d.department_name,
    d.department_id,
    sem.semester_name,
    sem.semester_number,
    sec.section_name,
    sec.section_id,
    t.full_name as teacher_name,
    t.teacher_id,
    st.academic_year,
    
    -- Count total students in this class
    (SELECT COUNT(DISTINCT s.student_id)
     FROM students s
     JOIN student_semesters ss ON s.student_id = ss.student_id
     WHERE ss.semester_id = sem.semester_id
     AND ss.section_id = sec.section_id
     AND s.department_id = d.department_id
     AND ss.is_active = 1
    ) as total_students,
    
    -- Check if attendance is marked for today
    (SELECT COUNT(DISTINCT a.student_id)
     FROM attendance a
     WHERE a.subject_id = sub.subject_id
     AND a.section_id = sec.section_id
     AND DATE(a.attendance_date) = '$selected_date'
    ) as attendance_marked,
    
    -- Count present
    (SELECT COUNT(*)
     FROM attendance a
     WHERE a.subject_id = sub.subject_id
     AND a.section_id = sec.section_id
     AND DATE(a.attendance_date) = '$selected_date'
     AND a.status = 'present'
    ) as present_count,
    
    -- Count absent
    (SELECT COUNT(*)
     FROM attendance a
     WHERE a.subject_id = sub.subject_id
     AND a.section_id = sec.section_id
     AND DATE(a.attendance_date) = '$selected_date'
     AND a.status = 'absent'
    ) as absent_count,
    
    -- Count late
    (SELECT COUNT(*)
     FROM attendance a
     WHERE a.subject_id = sub.subject_id
     AND a.section_id = sec.section_id
     AND DATE(a.attendance_date) = '$selected_date'
     AND a.status = 'late'
    ) as late_count

FROM subject_teachers st
JOIN subjects sub ON st.subject_id = sub.subject_id
JOIN semesters sem ON st.semester_id = sem.semester_id
JOIN sections sec ON st.section_id = sec.section_id
JOIN departments d ON sub.department_id = d.department_id
LEFT JOIN teachers t ON st.teacher_id = t.teacher_id
WHERE 1=1";

if ($selected_department) {
    $query .= " AND d.department_id = $selected_department";
}

if ($selected_semester) {
    $query .= " AND sem.semester_id = $selected_semester";
}

if ($selected_section) {
    $query .= " AND sec.section_id = $selected_section";
}

if ($search) {
    $search_escaped = $conn->real_escape_string($search);
    $query .= " AND (sub.subject_name LIKE '%$search_escaped%' 
                OR sub.subject_code LIKE '%$search_escaped%' 
                OR t.full_name LIKE '%$search_escaped%')";
}

$query .= " ORDER BY d.department_name, sem.semester_number, sec.section_name, sub.subject_name";

$classes = $conn->query($query);

// Calculate statistics
$total_classes = 0;
$marked_classes = 0;
$pending_classes = 0;

$temp_result = $conn->query($query);
while ($row = $temp_result->fetch_assoc()) {
    $total_classes++;
    if ($row['attendance_marked'] > 0) {
        $marked_classes++;
    } else {
        $pending_classes++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Department Classes - College ERP</title>
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: var(--dark);
            min-height: 100vh;
        }

        .dashboard {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
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

        /* Header Section */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .page-title {
            display: flex;
            align-items: center;
            gap: 15px;
            color: var(--white);
        }

        .page-title i {
            font-size: 2rem;
        }

        .page-title h1 {
            font-size: 2rem;
            font-weight: 700;
        }

        .back-btn {
            background: rgba(255,255,255,0.2);
            color: var(--white);
            border: 2px solid var(--white);
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .back-btn:hover {
            background: var(--white);
            color: var(--primary);
        }

        /* Stats Tabs */
        .stats-tabs {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .stat-tab {
            background: rgba(255,255,255,0.95);
            padding: 15px 25px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .stat-tab.active {
            background: var(--white);
            border-color: var(--primary);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }

        .stat-tab:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .stat-tab i {
            font-size: 1.3rem;
        }

        .stat-tab.all i { color: var(--primary); }
        .stat-tab.marked i { color: var(--success); }
        .stat-tab.pending i { color: var(--warning); }

        .stat-content h3 {
            font-size: 1.1rem;
            color: var(--dark);
            font-weight: 600;
        }

        .stat-content p {
            font-size: 0.85rem;
            color: var(--gray);
        }

        /* Search Bar */
        .search-bar {
            background: var(--white);
            padding: 12px 20px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .search-bar i {
            color: var(--gray);
            font-size: 1.1rem;
        }

        .search-bar input {
            flex: 1;
            border: none;
            outline: none;
            font-size: 0.95rem;
            color: var(--dark);
        }

        .search-bar input::placeholder {
            color: var(--gray);
        }

        /* Main Card */
        .main-card {
            background: var(--white);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
        }

        /* Filters */
        .filters-section {
            padding: 25px;
            background: var(--light-gray);
            border-bottom: 1px solid #e5e7eb;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 0.85rem;
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
            background: var(--white);
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
            margin-top: 15px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: var(--primary);
            color: var(--white);
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: var(--gray);
            color: var(--white);
        }

        .btn-secondary:hover {
            background: var(--dark);
        }

        /* Table */
        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: var(--white);
        }

        th {
            text-align: left;
            padding: 18px 20px;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 18px 20px;
            border-bottom: 1px solid var(--light-gray);
        }

        tbody tr {
            transition: all 0.3s ease;
        }

        tbody tr:hover {
            background: #fafafa;
        }

        .class-name {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.95rem;
        }

        .badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-success {
            background: rgba(16, 185, 129, 0.15);
            color: var(--success);
        }

        .badge-danger {
            background: rgba(239, 68, 68, 0.15);
            color: var(--danger);
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.15);
            color: var(--warning);
        }

        .badge-info {
            background: rgba(99, 102, 241, 0.15);
            color: var(--primary);
        }

        .badge-teal {
            background: rgba(20, 184, 166, 0.15);
            color: #14b8a6;
        }

        .badge-cyan {
            background: rgba(6, 182, 212, 0.15);
            color: #06b6d4;
        }

        .students-badge {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.9rem;
        }

        .count-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 45px;
            height: 45px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 1.1rem;
        }

        .count-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .count-danger {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .count-warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .status-badge {
            padding: 8px 20px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .status-pending {
            background: rgba(99, 102, 241, 0.1);
            color: var(--primary);
        }

        .status-marked {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        /* Action Button */
        .action-btn {
            background: var(--primary);
            color: var(--white);
            padding: 8px 16px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.85rem;
        }

        .action-btn:hover {
            background: var(--primary-dark);
            transform: scale(1.05);
        }

        /* Empty State */
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

        .empty-state h3 {
            font-size: 1.3rem;
            margin-bottom: 10px;
            color: var(--dark);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }

            .sidebar {
                transform: translateX(-100%);
            }

            .page-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }

            .stats-tabs {
                flex-direction: column;
            }

            .stat-tab {
                width: 100%;
            }

            .filters-grid {
                grid-template-columns: 1fr;
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
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title">
                    <i class="fas fa-graduation-cap"></i>
                    <h1>All Department Classes</h1>
                </div>
                <a href="index.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>

            <!-- Stats Tabs -->
            <div class="stats-tabs">
                <div class="stat-tab all active">
                    <i class="fas fa-list"></i>
                    <div class="stat-content">
                        <h3>All Classes (<?php echo $total_classes; ?>)</h3>
                    </div>
                </div>
                <div class="stat-tab marked">
                    <i class="fas fa-check-circle"></i>
                    <div class="stat-content">
                        <h3>Marked (<?php echo $marked_classes; ?>)</h3>
                    </div>
                </div>
                <div class="stat-tab pending">
                    <i class="fas fa-clock"></i>
                    <div class="stat-content">
                        <h3>Pending (<?php echo $pending_classes; ?>)</h3>
                    </div>
                </div>
            </div>

            <!-- Search Bar -->
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Search classes..." value="<?php echo htmlspecialchars($search); ?>">
            </div>

            <!-- Main Card -->
            <div class="main-card">
                <!-- Filters Section -->
                <div class="filters-section">
                    <form method="GET" action="">
                        <div class="filters-grid">
                            <div class="form-group">
                                <label>Department</label>
                                <select name="department" id="department">
                                    <option value="">All Departments</option>
                                    <?php 
                                    $departments->data_seek(0);
                                    while($dept = $departments->fetch_assoc()): 
                                    ?>
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
                                    <?php 
                                    $semesters->data_seek(0);
                                    while($sem = $semesters->fetch_assoc()): 
                                    ?>
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
                                    <?php 
                                    $sections->data_seek(0);
                                    while($sec = $sections->fetch_assoc()): 
                                    ?>
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
                            <a href="all_department_classes.php" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Table -->
                <div class="table-container">
                    <table id="classesTable">
                        <thead>
                            <tr>
                                <th>CLASS NAME</th>
                                <th>DEPARTMENT</th>
                                <th>YEAR</th>
                                <th>SEMESTER</th>
                                <th>SECTION</th>
                                <th>TEACHER</th>
                                <th>STUDENTS</th>
                                <th><i class="fas fa-check" style="color: #10b981;"></i> PRESENT</th>
                                <th><i class="fas fa-times" style="color: #ef4444;"></i> ABSENT</th>
                                <th><i class="fas fa-clock" style="color: #f59e0b;"></i> LATE</th>
                                <th>STATUS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($classes->num_rows > 0): ?>
                                <?php while($class = $classes->fetch_assoc()): 
                                    $is_marked = $class['attendance_marked'] > 0;
                                ?>
                                <tr>
                                    <td>
                                        <div class="class-name">
                                            <?php echo $class['subject_name']; ?>
                                            <?php if($class['teacher_name']): ?>
                                                (<?php echo explode(' ', $class['teacher_name'])[0] . '. ' . end(explode(' ', $class['teacher_name'])); ?>)
                                            <?php endif; ?>
                                        </div>
                                        <small style="color: var(--gray);"><?php echo $class['subject_code']; ?></small>
                                    </td>
                                    <td><?php echo $class['department_name']; ?></td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?php echo $class['academic_year'] ?: date('Y'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-teal">
                                            SEM <?php echo $class['semester_number']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-cyan">
                                            <?php echo $class['section_name']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $class['teacher_name'] ?: 'Not Assigned'; ?></td>
                                    <td>
                                        <span class="students-badge">
                                            <?php echo $class['total_students']; ?> STUDENTS
                                        </span>
                                    </td>
                                    <td>
                                        <span class="count-badge count-success">
                                            <i class="fas fa-check"></i> <?php echo $class['present_count']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="count-badge count-danger">
                                            <i class="fas fa-times"></i> <?php echo $class['absent_count']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="count-badge count-warning">
                                            <i class="fas fa-clock"></i> <?php echo $class['late_count']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if($is_marked): ?>
                                            <span class="status-badge status-marked">
                                                <i class="fas fa-check-circle"></i> MARKED
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge status-pending">
                                                <i class="fas fa-clock"></i> PENDING
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="11">
                                        <div class="empty-state">
                                            <i class="fas fa-inbox"></i>
                                            <h3>No Classes Found</h3>
                                            <p>No classes match your filter criteria</p>
                                        </div>
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
            const rows = document.querySelectorAll('#classesTable tbody tr');
            
            rows.forEach(row => {
                const className = row.querySelector('.class-name')?.textContent.toLowerCase() || '';
                const department = row.cells[1]?.textContent.toLowerCase() || '';
                const teacher = row.cells[5]?.textContent.toLowerCase() || '';
                
                if (className.includes(searchValue) || department.includes(searchValue) || teacher.includes(searchValue)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });

        // Tab filtering
        document.querySelectorAll('.stat-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.stat-tab').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                
                const rows = document.querySelectorAll('#classesTable tbody tr');
                const filter = this.classList.contains('marked') ? 'marked' : 
                             this.classList.contains('pending') ? 'pending' : 'all';
                
                rows.forEach(row => {
                    if (row.querySelector('.empty-state')) {
                        row.style.display = '';
                        return;
                    }
                    
                    const statusBadge = row.querySelector('.status-badge');
                    if (!statusBadge) return;
                    
                    const isMarked = statusBadge.classList.contains('status-marked');
                    const isPending = statusBadge.classList.contains('status-pending');
                    
                    if (filter === 'all') {
                        row.style.display = '';
                    } else if (filter === 'marked' && isMarked) {
                        row.style.display = '';
                    } else if (filter === 'pending' && isPending) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });
        });

        // Auto-submit form when date changes
        document.querySelector('input[name="date"]').addEventListener('change', function() {
            this.form.submit();
        });
    </script>
</body>
</html>
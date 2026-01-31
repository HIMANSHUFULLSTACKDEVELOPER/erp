<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('admin')) {
    redirect('login.php');
}

// Handle department deletion
if (isset($_POST['delete_department'])) {
    $department_id = $_POST['department_id'];
    $conn->query("DELETE FROM departments WHERE department_id = $department_id");
    $success_message = "Department deleted successfully!";
}

// Handle department addition
if (isset($_POST['add_department'])) {
    $department_name = $_POST['department_name'];
    $department_code = $_POST['department_code'];
    $hod_id = !empty($_POST['hod_id']) ? $_POST['hod_id'] : NULL;
    
    $stmt = $conn->prepare("INSERT INTO departments (department_name, department_code, hod_id) VALUES (?, ?, ?)");
    $stmt->bind_param("ssi", $department_name, $department_code, $hod_id);
    
    if ($stmt->execute()) {
        $success_message = "Department added successfully!";
    }
}

// Handle department update
if (isset($_POST['update_department'])) {
    $department_id = $_POST['department_id'];
    $department_name = $_POST['department_name'];
    $department_code = $_POST['department_code'];
    $hod_id = !empty($_POST['hod_id']) ? $_POST['hod_id'] : NULL;
    
    $stmt = $conn->prepare("UPDATE departments SET department_name=?, department_code=?, hod_id=? WHERE department_id=?");
    $stmt->bind_param("ssii", $department_name, $department_code, $hod_id, $department_id);
    
    if ($stmt->execute()) {
        $success_message = "Department updated successfully!";
    }
}

// Get all departments with HOD information
$departments = $conn->query("SELECT d.*, u.username as hod_name, t.full_name as hod_full_name,
                            (SELECT COUNT(*) FROM students WHERE department_id = d.department_id) as student_count,
                            (SELECT COUNT(*) FROM teachers WHERE department_id = d.department_id) as teacher_count
                            FROM departments d 
                            LEFT JOIN users u ON d.hod_id = u.user_id 
                            LEFT JOIN teachers t ON u.user_id = t.user_id
                            ORDER BY d.department_name");

// Get all users with role 'hod' or 'teacher' for HOD dropdown
$potential_hods = $conn->query("SELECT u.user_id, u.username, t.full_name 
                               FROM users u 
                               LEFT JOIN teachers t ON u.user_id = t.user_id
                               WHERE u.role IN ('hod', 'teacher') 
                               ORDER BY t.full_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Departments - College ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
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
            overflow-x: hidden;
        }

        /* Animations */
        @keyframes slideInLeft {
            from {
                transform: translateX(-100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideDown {
            from {
                transform: translateY(-100%);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes scaleIn {
            from {
                transform: scale(0.9);
                opacity: 0;
            }
            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }

        .dashboard {
            display: flex;
            min-height: 100vh;
        }

        /* Mobile Menu Toggle */
        .menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: var(--primary);
            color: var(--white);
            border: none;
            width: 45px;
            height: 45px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 1.2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }

        .menu-toggle:hover {
            background: var(--primary-dark);
            transform: scale(1.1);
        }

        .menu-toggle:active {
            transform: scale(0.95);
        }

        .sidebar {
            width: var(--sidebar-width);
            background: var(--dark);
            color: var(--white);
            padding: 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: transform 0.3s ease;
            animation: slideInLeft 0.4s ease;
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
            position: relative;
            overflow: hidden;
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
            transition: transform 0.3s ease;
        }

        .menu-item:hover::before,
        .menu-item.active::before {
            transform: scaleY(1);
        }

        .menu-item:hover, .menu-item.active {
            background: var(--primary);
            color: var(--white);
            padding-left: 25px;
        }

        .menu-item i {
            margin-right: 12px;
            width: 20px;
            text-align: center;
            transition: transform 0.3s ease;
        }

        .menu-item:hover i {
            transform: scale(1.2) rotate(5deg);
        }

        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 30px;
            transition: margin-left 0.3s ease;
            animation: fadeIn 0.5s ease;
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
            animation: slideDown 0.5s ease;
            flex-wrap: wrap;
            gap: 15px;
        }

        .top-bar h1 {
            font-size: 1.8rem;
            font-weight: 700;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-primary {
            background: var(--primary);
            color: var(--white);
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-danger {
            background: var(--danger);
            color: var(--white);
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-warning {
            background: var(--warning);
            color: var(--white);
        }

        .btn-warning:hover {
            background: #d97706;
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 0.9rem;
        }

        .card {
            background: var(--white);
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--light-gray);
        }

        .card-header h3 {
            font-size: 1.2rem;
            font-weight: 600;
        }

        .departments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            animation: fadeIn 0.6s ease;
        }

        .dept-card {
            background: var(--white);
            border: 2px solid var(--light-gray);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
            animation: scaleIn 0.5s ease;
            animation-fill-mode: backwards;
        }

        .dept-card:nth-child(1) { animation-delay: 0.1s; }
        .dept-card:nth-child(2) { animation-delay: 0.2s; }
        .dept-card:nth-child(3) { animation-delay: 0.3s; }
        .dept-card:nth-child(4) { animation-delay: 0.4s; }
        .dept-card:nth-child(5) { animation-delay: 0.5s; }
        .dept-card:nth-child(6) { animation-delay: 0.6s; }

        .dept-card:hover {
            border-color: var(--primary);
            box-shadow: 0 8px 24px rgba(37, 99, 235, 0.15);
            transform: translateY(-5px);
        }

        .dept-card-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }

        .dept-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: 1.5rem;
            transition: all 0.3s ease;
        }

        .dept-card:hover .dept-icon {
            transform: rotate(10deg) scale(1.1);
            animation: pulse 0.6s ease;
        }

        .dept-title {
            flex: 1;
            margin-left: 15px;
        }

        .dept-title h4 {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .dept-title .dept-code {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .dept-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin: 15px 0;
        }

        .stat-item {
            background: var(--light-gray);
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-item:hover {
            background: rgba(37, 99, 235, 0.1);
            transform: scale(1.05);
        }

        .stat-item .number {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }

        .stat-item .label {
            font-size: 0.8rem;
            color: var(--gray);
            margin-top: 5px;
        }

        .dept-hod {
            padding: 12px;
            background: rgba(139, 92, 246, 0.1);
            border-radius: 8px;
            margin: 10px 0;
            transition: all 0.3s ease;
        }

        .dept-card:hover .dept-hod {
            background: rgba(139, 92, 246, 0.15);
        }

        .dept-hod .label {
            font-size: 0.8rem;
            color: var(--gray);
            margin-bottom: 5px;
        }

        .dept-hod .name {
            font-weight: 600;
            color: var(--secondary);
        }

        .dept-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            padding: 20px;
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background: var(--white);
            padding: 30px;
            border-radius: 12px;
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            animation: scaleIn 0.3s ease;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h2 {
            font-size: 1.5rem;
            font-weight: 600;
        }

        .close {
            cursor: pointer;
            font-size: 1.5rem;
            color: var(--gray);
            transition: all 0.3s ease;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .close:hover {
            background: var(--light-gray);
            color: var(--danger);
            transform: rotate(90deg);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            animation: slideDown 0.5s ease;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border-left: 4px solid var(--success);
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 999;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .sidebar-overlay.active {
            display: block;
            opacity: 1;
        }

        /* Responsive Design - Tablet */
        @media (max-width: 1024px) {
            .departments-grid {
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            }

            .top-bar h1 {
                font-size: 1.5rem;
            }
        }

        /* Responsive Design - Mobile */
        @media (max-width: 768px) {
            .menu-toggle {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 80px 15px 15px;
            }

            .top-bar {
                padding: 15px;
                flex-direction: column;
                align-items: stretch;
            }

            .top-bar h1 {
                font-size: 1.3rem;
                margin-bottom: 10px;
            }

            .top-bar .btn {
                width: 100%;
            }

            .departments-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .dept-card {
                padding: 15px;
            }

            .dept-stats {
                gap: 8px;
            }

            .stat-item {
                padding: 10px;
            }

            .dept-actions {
                flex-direction: column;
            }

            .dept-actions form {
                width: 100%;
            }

            .modal-content {
                padding: 20px;
            }

            .modal-header h2 {
                font-size: 1.2rem;
            }
        }

        /* Small Mobile Devices */
        @media (max-width: 480px) {
            .main-content {
                padding: 70px 10px 10px;
            }

            .top-bar {
                padding: 10px;
            }

            .top-bar h1 {
                font-size: 1.1rem;
            }

            .btn {
                padding: 10px 16px;
                font-size: 0.9rem;
            }

            .btn-sm {
                padding: 6px 12px;
                font-size: 0.85rem;
            }

            .dept-card {
                padding: 12px;
            }

            .dept-card-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .dept-icon {
                width: 45px;
                height: 45px;
                font-size: 1.3rem;
                margin-bottom: 10px;
            }

            .dept-title {
                margin-left: 0;
            }

            .dept-title h4 {
                font-size: 1rem;
            }

            .stat-item .number {
                font-size: 1.3rem;
            }

            .modal-content {
                padding: 15px;
            }

            .form-group input,
            .form-group select {
                padding: 10px;
                font-size: 0.9rem;
            }
        }

        /* Landscape orientation */
        @media (max-height: 500px) and (orientation: landscape) {
            .modal-content {
                max-height: 95vh;
            }

            .sidebar {
                overflow-y: scroll;
            }
        }
    </style>
</head>
<body>
    <button class="menu-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="sidebar-overlay" onclick="toggleSidebar()"></div>

    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>Admin Panel</h2>
                <p>College ERP System</p>
            </div>
            <nav class="sidebar-menu">
              <a href="index.php" class="menu-item" onclick="closeSidebarMobile()">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="manage_students.php" class="menu-item" onclick="closeSidebarMobile()">
                    <i class="fas fa-user-graduate"></i> Students
                </a>
                <a href="manage_teachers.php" class="menu-item" onclick="closeSidebarMobile()">
                    <i class="fas fa-chalkboard-teacher"></i> Teachers
                </a>
                <a href="manage_departments.php" class="menu-item active" onclick="closeSidebarMobile()">
                    <i class="fas fa-building"></i> Departments
                </a>
                <a href="manage_courses.php" class="menu-item" onclick="closeSidebarMobile()">
                    <i class="fas fa-book"></i> Courses
                </a>
                <a href="manage_subjects.php" class="menu-item" onclick="closeSidebarMobile()">
                    <i class="fas fa-list"></i> Subjects
                </a>
                <a href="reports.php" class="menu-item" onclick="closeSidebarMobile()">
                    <i class="fas fa-chart-bar"></i> Reports
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-bar">
                <h1>Manage Departments</h1>
                <button class="btn btn-primary" onclick="openAddModal()">
                    <i class="fas fa-plus"></i> Add New Department
                </button>
            </div>

            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                </div>
            <?php endif; ?>

            <div class="departments-grid">
                <?php while($dept = $departments->fetch_assoc()): ?>
                <div class="dept-card">
                    <div class="dept-card-header">
                        <div class="dept-icon">
                            <i class="fas fa-building"></i>
                        </div>
                        <div class="dept-title">
                            <h4><?php echo $dept['department_name']; ?></h4>
                            <span class="dept-code">Code: <?php echo $dept['department_code']; ?></span>
                        </div>
                    </div>

                    <div class="dept-stats">
                        <div class="stat-item">
                            <div class="number"><?php echo $dept['student_count']; ?></div>
                            <div class="label"><i class="fas fa-user-graduate"></i> Students</div>
                        </div>
                        <div class="stat-item">
                            <div class="number"><?php echo $dept['teacher_count']; ?></div>
                            <div class="label"><i class="fas fa-chalkboard-teacher"></i> Teachers</div>
                        </div>
                    </div>

                    <div class="dept-hod">
                        <div class="label"><i class="fas fa-user-tie"></i> Head of Department</div>
                        <div class="name"><?php echo $dept['hod_full_name'] ?? 'Not Assigned'; ?></div>
                    </div>

                    <div class="dept-actions">
                        <button class="btn btn-warning btn-sm" onclick='editDepartment(<?php echo json_encode($dept); ?>)' style="flex: 1;">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <form method="POST" style="flex: 1;" onsubmit="return confirm('Are you sure you want to delete this department?');">
                            <input type="hidden" name="department_id" value="<?php echo $dept['department_id']; ?>">
                            <button type="submit" name="delete_department" class="btn btn-danger btn-sm" style="width: 100%;">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </form>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        </main>
    </div>

    <!-- Add Department Modal -->
    <div id="addDepartmentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-plus-circle"></i> Add New Department</h2>
                <span class="close" onclick="closeAddModal()">&times;</span>
            </div>
            <form method="POST">
                <div class="form-group">
                    <label><i class="fas fa-building"></i> Department Name *</label>
                    <input type="text" name="department_name" placeholder="e.g., Computer Science" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-code"></i> Department Code *</label>
                    <input type="text" name="department_code" placeholder="e.g., CS" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-user-tie"></i> Head of Department</label>
                    <select name="hod_id">
                        <option value="">Not Assigned</option>
                        <?php 
                        $potential_hods->data_seek(0);
                        while($hod = $potential_hods->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $hod['user_id']; ?>">
                                <?php echo $hod['full_name'] ?? $hod['username']; ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <button type="submit" name="add_department" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-plus"></i> Add Department
                </button>
            </form>
        </div>
    </div>

    <!-- Edit Department Modal -->
    <div id="editDepartmentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-edit"></i> Edit Department</h2>
                <span class="close" onclick="closeEditModal()">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="department_id" id="edit_department_id">
                <div class="form-group">
                    <label><i class="fas fa-building"></i> Department Name *</label>
                    <input type="text" name="department_name" id="edit_department_name" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-code"></i> Department Code *</label>
                    <input type="text" name="department_code" id="edit_department_code" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-user-tie"></i> Head of Department</label>
                    <select name="hod_id" id="edit_hod_id">
                        <option value="">Not Assigned</option>
                        <?php 
                        $potential_hods->data_seek(0);
                        while($hod = $potential_hods->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $hod['user_id']; ?>">
                                <?php echo $hod['full_name'] ?? $hod['username']; ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <button type="submit" name="update_department" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-save"></i> Update Department
                </button>
            </form>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
        }

        function closeSidebarMobile() {
            if (window.innerWidth <= 768) {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.querySelector('.sidebar-overlay');
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
            }
        }

        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.querySelector('.sidebar-overlay');
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
            }
        });

        function openAddModal() {
            document.getElementById('addDepartmentModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeAddModal() {
            document.getElementById('addDepartmentModal').classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        function editDepartment(dept) {
            document.getElementById('edit_department_id').value = dept.department_id;
            document.getElementById('edit_department_name').value = dept.department_name;
            document.getElementById('edit_department_code').value = dept.department_code;
            document.getElementById('edit_hod_id').value = dept.hod_id || '';
            document.getElementById('editDepartmentModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeEditModal() {
            document.getElementById('editDepartmentModal').classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const addModal = document.getElementById('addDepartmentModal');
            const editModal = document.getElementById('editDepartmentModal');
            if (event.target == addModal) {
                closeAddModal();
            }
            if (event.target == editModal) {
                closeEditModal();
            }
        }

        // Close modal with ESC key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeAddModal();
                closeEditModal();
            }
        });
    </script>
</body>
</html>
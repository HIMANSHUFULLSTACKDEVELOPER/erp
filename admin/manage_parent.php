<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('admin')) {
    redirect('login.php');
}

// Handle parent addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_parent'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $full_name = $_POST['full_name'];
    $relation = $_POST['relation'];
    $student_id = $_POST['student_id'];
    
    // Insert user
    $stmt = $conn->prepare("INSERT INTO users (username, password, email, phone, role) VALUES (?, ?, ?, ?, 'parent')");
    $stmt->bind_param("ssss", $username, $password_hash, $email, $phone);
    
    if ($stmt->execute()) {
        $user_id = $conn->insert_id;
        
        // Insert parent
        $stmt2 = $conn->prepare("INSERT INTO parents (user_id, full_name, relation) VALUES (?, ?, ?)");
        $stmt2->bind_param("iss", $user_id, $full_name, $relation);
        $stmt2->execute();
        $parent_id = $conn->insert_id;
        
        // Link parent to student
        $stmt3 = $conn->prepare("INSERT INTO parent_student (parent_id, student_id) VALUES (?, ?)");
        $stmt3->bind_param("ii", $parent_id, $student_id);
        $stmt3->execute();
        
        $_SESSION['success'] = "Parent added successfully!";
        $_SESSION['new_parent_credentials'] = ['username' => $username, 'password' => $password, 'name' => $full_name];
    } else {
        $_SESSION['error'] = "Error adding parent: " . $conn->error;
    }
    
    header("Location: manage_parent.php");
    exit();
}

// Handle parent deletion
if (isset($_GET['delete'])) {
    $parent_id = $_GET['delete'];
    
    // Get user_id
    $result = $conn->query("SELECT user_id FROM parents WHERE parent_id = $parent_id");
    $parent = $result->fetch_assoc();
    
    // Delete parent (cascade will handle parent_student and user)
    $conn->query("DELETE FROM users WHERE user_id = {$parent['user_id']}");
    
    $_SESSION['success'] = "Parent deleted successfully!";
    header("Location: manage_parent.php");
    exit();
}

// Get all parents with their children
$parents = $conn->query("SELECT p.parent_id, p.full_name, p.relation, 
                        u.username, u.email, u.phone, u.created_at,
                        GROUP_CONCAT(s.full_name SEPARATOR ', ') as children
                        FROM parents p
                        JOIN users u ON p.user_id = u.user_id
                        LEFT JOIN parent_student ps ON p.parent_id = ps.parent_id
                        LEFT JOIN students s ON ps.student_id = s.student_id
                        GROUP BY p.parent_id
                        ORDER BY p.created_at DESC");

// Get all students for dropdown
$students = $conn->query("SELECT student_id, full_name, admission_number FROM students ORDER BY full_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Parents - College ERP</title>
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

        /* Animations */
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

        @keyframes slideIn {
            from {
                transform: translateX(-100%);
            }
            to {
                transform: translateX(0);
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

        @keyframes shimmer {
            0% {
                background-position: -1000px 0;
            }
            100% {
                background-position: 1000px 0;
            }
        }

        .dashboard {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 260px;
            background: var(--dark);
            color: var(--white);
            padding: 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: transform 0.3s ease;
            animation: slideIn 0.3s ease;
        }

        .sidebar.mobile-hidden {
            transform: translateX(-100%);
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
        }

        .menu-item i {
            margin-right: 12px;
            width: 20px;
            text-align: center;
            transition: transform 0.3s ease;
        }

        .menu-item:hover i {
            transform: scale(1.2) rotate(10deg);
        }

        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: var(--primary);
            color: var(--white);
            border: none;
            padding: 12px 15px;
            border-radius: 8px;
            cursor: pointer;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }

        .mobile-menu-toggle:hover {
            background: var(--primary-dark);
            transform: scale(1.05);
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
        }

        .sidebar-overlay.active {
            display: block;
        }

        .main-content {
            flex: 1;
            margin-left: 260px;
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
            animation: fadeIn 0.5s ease;
        }

        .top-bar h1 {
            font-size: 1.8rem;
            font-weight: 700;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            animation: fadeIn 0.5s ease;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border-left: 4px solid var(--success);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }

        .alert-warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border-left: 4px solid var(--warning);
        }

        .credential-box {
            background: var(--dark);
            color: var(--white);
            padding: 20px;
            border-radius: 8px;
            margin-top: 15px;
            border: 2px solid var(--success);
            animation: pulse 1s ease;
        }

        .credential-box h4 {
            color: var(--warning);
            margin-bottom: 15px;
            font-size: 1.1rem;
        }

        .credential-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            background: rgba(255,255,255,0.1);
            border-radius: 6px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }

        .credential-item:hover {
            background: rgba(255,255,255,0.2);
            transform: scale(1.02);
        }

        .credential-item:last-child {
            margin-bottom: 0;
        }

        .credential-label {
            font-weight: 500;
            opacity: 0.8;
        }

        .credential-value {
            font-weight: 700;
            color: var(--warning);
            font-size: 1.1rem;
        }

        .card {
            background: var(--white);
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            animation: fadeIn 0.6s ease;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }

        .card-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .card-header h3 {
            font-size: 1.2rem;
            font-weight: 600;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255,255,255,0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn-primary {
            background: var(--primary);
            color: var(--white);
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(37, 99, 235, 0.3);
        }

        .btn-success {
            background: var(--success);
            color: var(--white);
        }

        .btn-success:hover {
            background: #059669;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
        }

        .btn-danger {
            background: var(--danger);
            color: var(--white);
        }

        .btn-danger:hover {
            background: #dc2626;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(239, 68, 68, 0.3);
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.85rem;
        }

        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        .table th {
            text-align: left;
            padding: 12px;
            border-bottom: 2px solid var(--light-gray);
            font-weight: 600;
            color: var(--gray);
            font-size: 0.85rem;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .table td {
            padding: 15px 12px;
            border-bottom: 1px solid var(--light-gray);
        }

        .table tbody tr {
            transition: all 0.3s ease;
        }

        .table tbody tr:hover {
            background: var(--light-gray);
            transform: scale(1.01);
        }

        .badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
            transition: all 0.3s ease;
        }

        .badge:hover {
            transform: scale(1.1);
        }

        .badge.info {
            background: rgba(37, 99, 235, 0.1);
            color: var(--primary);
        }

        .badge.success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal.active {
            display: flex;
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background: var(--white);
            padding: 30px;
            border-radius: 12px;
            width: 100%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            animation: pulse 0.5s ease;
        }

        .modal-header {
            margin-bottom: 20px;
        }

        .modal-header h3 {
            font-size: 1.4rem;
            font-weight: 600;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
            transform: scale(1.01);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .modal-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .info-note {
            background: rgba(37, 99, 235, 0.1);
            padding: 12px 15px;
            border-radius: 6px;
            color: var(--primary);
            font-size: 0.9rem;
            margin-top: 10px;
        }

        /* Tablet Styles */
        @media (max-width: 1024px) {
            .sidebar {
                width: 220px;
            }

            .main-content {
                margin-left: 220px;
            }

            .top-bar h1 {
                font-size: 1.5rem;
            }

            .form-row {
                grid-template-columns: 1fr;
            }
        }

        /* Mobile Styles */
        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.mobile-active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 80px 15px 15px;
            }

            .top-bar {
                flex-direction: column;
                gap: 15px;
                padding: 15px;
                text-align: center;
            }

            .top-bar h1 {
                font-size: 1.3rem;
            }

            .card {
                padding: 15px;
            }

            .card-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .credential-box {
                padding: 15px;
            }

            .credential-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }

            .credential-value {
                font-size: 1rem;
            }

            .table th,
            .table td {
                padding: 10px 8px;
                font-size: 0.85rem;
            }

            .btn {
                padding: 8px 15px;
                font-size: 0.9rem;
            }

            .btn-sm {
                padding: 5px 10px;
                font-size: 0.75rem;
            }

            .modal-content {
                padding: 20px;
            }

            .modal-footer {
                flex-direction: column;
            }

            .modal-footer .btn {
                width: 100%;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            /* Make table cards on mobile */
            .table-container {
                display: block;
            }

            .table thead {
                display: none;
            }

            .table,
            .table tbody,
            .table tr,
            .table td {
                display: block;
                width: 100%;
            }

            .table tr {
                margin-bottom: 15px;
                border: 1px solid var(--light-gray);
                border-radius: 8px;
                padding: 15px;
                background: var(--white);
            }

            .table td {
                text-align: right;
                padding: 10px 0;
                border-bottom: 1px solid var(--light-gray);
                position: relative;
                padding-left: 50%;
            }

            .table td:last-child {
                border-bottom: none;
                padding-left: 0;
            }

            .table td::before {
                content: attr(data-label);
                position: absolute;
                left: 0;
                width: 45%;
                padding-right: 10px;
                text-align: left;
                font-weight: 600;
                color: var(--gray);
            }

            .table td:last-child::before {
                display: none;
            }
        }

        /* Small Mobile */
        @media (max-width: 480px) {
            .top-bar h1 {
                font-size: 1.1rem;
            }

            .sidebar-header h2 {
                font-size: 1.2rem;
            }

            .menu-item {
                padding: 12px 15px;
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" onclick="toggleMobileMenu()">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" onclick="closeMobileMenu()"></div>

    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>Admin Panel</h2>
                <p>College ERP System</p>
            </div>
            <nav class="sidebar-menu">
                <a href="admin_dashboard.php" class="menu-item">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="manage_students.php" class="menu-item">
                    <i class="fas fa-user-graduate"></i> Students
                </a>
                <a href="manage_teachers.php" class="menu-item">
                    <i class="fas fa-chalkboard-teacher"></i> Teachers
                </a>
                <a href="manage_hod.php" class="menu-item">
                    <i class="fas fa-user-tie"></i> HODs
                </a>
                <a href="manage_departments.php" class="menu-item">
                    <i class="fas fa-building"></i> Departments
                </a>
                <a href="manage_courses.php" class="menu-item">
                    <i class="fas fa-book"></i> Courses
                </a>
                <a href="manage_subjects.php" class="menu-item">
                    <i class="fas fa-list"></i> Subjects
                </a>
                <a href="manage_parent.php" class="menu-item active">
                    <i class="fas fa-users"></i> Parents
                </a>
                <a href="classes.php" class="menu-item">
                    <i class="fas fa-door-open"></i> Classes
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-bar">
                <h1>Manage Parents</h1>
                <a href="../logout.php" class="btn btn-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <strong><i class="fas fa-check-circle"></i> Success!</strong><br>
                    <?php echo $_SESSION['success']; ?>
                    <?php if (isset($_SESSION['new_parent_credentials'])): ?>
                        <div class="credential-box">
                            <h4><i class="fas fa-key"></i> Login Credentials Created - SAVE THESE!</h4>
                            <div class="credential-item">
                                <span class="credential-label">Parent Name:</span>
                                <span class="credential-value"><?php echo $_SESSION['new_parent_credentials']['name']; ?></span>
                            </div>
                            <div class="credential-item">
                                <span class="credential-label">Username:</span>
                                <span class="credential-value"><?php echo $_SESSION['new_parent_credentials']['username']; ?></span>
                            </div>
                            <div class="credential-item">
                                <span class="credential-label">Password:</span>
                                <span class="credential-value"><?php echo $_SESSION['new_parent_credentials']['password']; ?></span>
                            </div>
                            <p style="margin-top: 15px; opacity: 0.9; font-size: 0.9rem;">
                                <i class="fas fa-exclamation-triangle"></i> <strong>Important:</strong> This password cannot be retrieved again. Make sure to save or share these credentials with the parent.
                            </p>
                        </div>
                        <?php unset($_SESSION['new_parent_credentials']); ?>
                    <?php endif; ?>
                    <?php unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <strong><i class="fas fa-exclamation-circle"></i> Error!</strong><br>
                    <?php 
                    echo $_SESSION['error'];
                    unset($_SESSION['error']);
                    ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h3>All Parents</h3>
                    <button class="btn btn-primary" onclick="openAddModal()">
                        <i class="fas fa-plus"></i> Add Parent
                    </button>
                </div>
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle"></i> <strong>Note:</strong> Parent passwords are encrypted and cannot be viewed after creation. Save the credentials when creating a new parent account.
                </div>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Username</th>
                                <th>Relation</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Children</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($parents->num_rows > 0): ?>
                                <?php while($parent = $parents->fetch_assoc()): ?>
                                <tr>
                                    <td data-label="Name"><strong><?php echo $parent['full_name']; ?></strong></td>
                                    <td data-label="Username"><span class="badge info"><?php echo $parent['username']; ?></span></td>
                                    <td data-label="Relation"><span class="badge success"><?php echo ucfirst($parent['relation']); ?></span></td>
                                    <td data-label="Email"><?php echo $parent['email']; ?></td>
                                    <td data-label="Phone"><?php echo $parent['phone']; ?></td>
                                    <td data-label="Children"><?php echo $parent['children'] ?: 'No children linked'; ?></td>
                                    <td data-label="Created"><?php echo date('M d, Y', strtotime($parent['created_at'])); ?></td>
                                    <td data-label="Actions">
                                        <a href="?delete=<?php echo $parent['parent_id']; ?>" 
                                           class="btn btn-danger btn-sm" 
                                           onclick="return confirm('Delete this parent? This action cannot be undone.')">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 30px; color: var(--gray);">
                                        <i class="fas fa-users" style="font-size: 3rem; opacity: 0.3; margin-bottom: 10px;"></i><br>
                                        No parents found. Add your first parent!
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Parent Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i> Add New Parent</h3>
            </div>
            <form method="POST">
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Full Name *</label>
                    <input type="text" name="full_name" class="form-control" placeholder="Enter full name" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-user-circle"></i> Username *</label>
                        <input type="text" name="username" class="form-control" placeholder="Choose username" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> Password *</label>
                        <input type="text" name="password" class="form-control" placeholder="Create password" required>
                        <small style="color: var(--gray); font-size: 0.85rem;">Use text field to see password clearly</small>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-envelope"></i> Email *</label>
                        <input type="email" name="email" class="form-control" placeholder="email@example.com" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-phone"></i> Phone *</label>
                        <input type="text" name="phone" class="form-control" placeholder="Phone number" required>
                    </div>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-heart"></i> Relation *</label>
                    <select name="relation" class="form-control" required>
                        <option value="">-- Select Relation --</option>
                        <option value="father">Father</option>
                        <option value="mother">Mother</option>
                        <option value="guardian">Guardian</option>
                    </select>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-user-graduate"></i> Link to Student *</label>
                    <select name="student_id" class="form-control" required>
                        <option value="">-- Select Student --</option>
                        <?php 
                        $students->data_seek(0);
                        while($student = $students->fetch_assoc()): 
                        ?>
                        <option value="<?php echo $student['student_id']; ?>">
                            <?php echo $student['full_name']; ?> (<?php echo $student['admission_number']; ?>)
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="info-note">
                    <i class="fas fa-info-circle"></i> <strong>Important:</strong> After creating the parent account, the username and password will be displayed. Save them immediately as the password cannot be retrieved later.
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" onclick="closeAddModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="add_parent" class="btn btn-success">
                        <i class="fas fa-check"></i> Add Parent
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleMobileMenu() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            sidebar.classList.toggle('mobile-active');
            overlay.classList.toggle('active');
        }

        function closeMobileMenu() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            sidebar.classList.remove('mobile-active');
            overlay.classList.remove('active');
        }

        function openAddModal() {
            document.getElementById('addModal').classList.add('active');
        }

        function closeAddModal() {
            document.getElementById('addModal').classList.remove('active');
        }

        window.onclick = function(event) {
            const modal = document.getElementById('addModal');
            if (event.target == modal) {
                closeAddModal();
            }
        }

        // Close mobile menu when clicking menu items
        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', closeMobileMenu);
        });
    </script>
</body>
</html>
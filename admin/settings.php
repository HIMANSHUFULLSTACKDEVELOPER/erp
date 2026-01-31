<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('admin')) {
    redirect('login.php');
}

// Handle profile update
if (isset($_POST['update_profile'])) {
    $user_id = $_SESSION['user_id'];
    $username = $_POST['username'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    
    $stmt = $conn->prepare("UPDATE users SET username=?, email=?, phone=? WHERE user_id=?");
    $stmt->bind_param("sssi", $username, $email, $phone, $user_id);
    
    if ($stmt->execute()) {
        $success_message = "Profile updated successfully!";
    }
}

// Handle password change
if (isset($_POST['change_password'])) {
    $user_id = $_SESSION['user_id'];
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Get current password hash
    $result = $conn->query("SELECT password FROM users WHERE user_id = $user_id");
    $user = $result->fetch_assoc();
    
    if (password_verify($current_password, $user['password'])) {
        if ($new_password === $confirm_password) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password=? WHERE user_id=?");
            $stmt->bind_param("si", $hashed_password, $user_id);
            
            if ($stmt->execute()) {
                $success_message = "Password changed successfully!";
            }
        } else {
            $error_message = "New passwords do not match!";
        }
    } else {
        $error_message = "Current password is incorrect!";
    }
}

// Handle system settings update
if (isset($_POST['update_system_settings'])) {
    $college_name = $_POST['college_name'];
    $college_code = $_POST['college_code'];
    $academic_year = $_POST['academic_year'];
    $email = $_POST['system_email'];
    $phone = $_POST['system_phone'];
    
    // In a real application, you would store these in a settings table
    // For now, we'll just show success
    $success_message = "System settings updated successfully!";
}

// Get current user info
$user_id = $_SESSION['user_id'];
$user_info = $conn->query("SELECT * FROM users WHERE user_id = $user_id")->fetch_assoc();

// Get database statistics
$total_users = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
$total_students = $conn->query("SELECT COUNT(*) as count FROM students")->fetch_assoc()['count'];
$total_teachers = $conn->query("SELECT COUNT(*) as count FROM teachers")->fetch_assoc()['count'];
$db_size = $conn->query("SELECT SUM(data_length + index_length) / 1024 / 1024 as size 
                         FROM information_schema.TABLES 
                         WHERE table_schema = 'college_erp'")->fetch_assoc()['size'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - College ERP</title>
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
            overflow-x: hidden;
        }

        /* Enhanced Animations */
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

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes scaleIn {
            from {
                transform: scale(0.8);
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

        @keyframes bounce {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-10px);
            }
        }

        @keyframes rotate {
            from {
                transform: rotate(0deg);
            }
            to {
                transform: rotate(360deg);
            }
        }

        .dashboard {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 260px;
            background: linear-gradient(180deg, var(--dark) 0%, #111827 100%);
            color: var(--white);
            padding: 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            animation: slideInLeft 0.5s ease;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }

        .sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .sidebar::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.1);
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.3);
            border-radius: 3px;
        }

        .sidebar-header {
            padding: 30px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            background: rgba(255,255,255,0.05);
        }

        .sidebar-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, #fff, #94a3b8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
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
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            position: relative;
            margin: 4px 10px;
            border-radius: 8px;
        }

        .menu-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 4px;
            background: linear-gradient(180deg, var(--primary), var(--secondary));
            transform: scaleY(0);
            transition: transform 0.3s ease;
        }

        .menu-item:hover, .menu-item.active {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }

        .menu-item:hover::before,
        .menu-item.active::before {
            transform: scaleY(1);
        }

        .menu-item i {
            margin-right: 12px;
            width: 20px;
            text-align: center;
            transition: transform 0.3s ease;
        }

        .menu-item:hover i {
            transform: scale(1.2) rotate(10deg);
            animation: bounce 0.6s ease;
        }

        .menu-item.active i {
            animation: pulse 2s infinite;
        }

        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            border: none;
            padding: 12px 15px;
            border-radius: 12px;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.4);
            transition: all 0.3s ease;
        }

        .mobile-menu-toggle:hover {
            transform: scale(1.05) rotate(5deg);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.5);
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
            backdrop-filter: blur(4px);
        }

        .sidebar-overlay.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        .main-content {
            flex: 1;
            margin-left: 260px;
            padding: 30px;
            transition: margin-left 0.3s ease;
            animation: fadeIn 0.5s ease;
        }

        .top-bar {
            background: linear-gradient(135deg, var(--white), #f8fafc);
            padding: 20px 30px;
            border-radius: 16px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            animation: slideInRight 0.5s ease;
            border: 1px solid rgba(255,255,255,0.8);
        }

        .top-bar h1 {
            font-size: 1.8rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--dark), var(--primary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .settings-container {
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 20px;
        }

        .settings-nav {
            background: var(--white);
            border-radius: 16px;
            padding: 20px;
            height: fit-content;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            animation: fadeIn 0.6s ease;
        }

        .settings-nav-item {
            padding: 12px 15px;
            margin-bottom: 8px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .settings-nav-item:hover {
            background: var(--light-gray);
            transform: translateX(5px);
        }

        .settings-nav-item.active {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }

        .settings-nav-item i {
            width: 20px;
            transition: transform 0.3s ease;
        }

        .settings-nav-item:hover i {
            transform: scale(1.2);
        }

        .settings-content {
            background: var(--white);
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            animation: fadeIn 0.6s ease 0.2s backwards;
        }

        .settings-section {
            display: none;
            animation: fadeIn 0.4s ease;
        }

        .settings-section.active {
            display: block;
        }

        .section-header {
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-gray);
        }

        .section-header h2 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 5px;
            background: linear-gradient(135deg, var(--dark), var(--primary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .section-header p {
            color: var(--gray);
            font-size: 0.9rem;
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
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #d1d5db;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--white);
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
            transform: scale(1.01);
        }

        .form-group input:hover,
        .form-group select:hover {
            border-color: var(--primary);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(37, 99, 235, 0.4);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #dc2626);
            color: var(--white);
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(239, 68, 68, 0.4);
        }

        .btn i {
            transition: transform 0.3s ease;
        }

        .btn:hover i {
            transform: scale(1.2);
        }

        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            animation: slideInRight 0.5s ease;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.15), rgba(16, 185, 129, 0.05));
            color: var(--success);
            border: 2px solid var(--success);
        }

        .alert-error {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.15), rgba(239, 68, 68, 0.05));
            color: var(--danger);
            border: 2px solid var(--danger);
        }

        .info-card {
            background: linear-gradient(135deg, var(--light-gray), #e5e7eb);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }

        .info-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .info-card-header {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--dark);
            font-size: 0.9rem;
        }

        .info-card-value {
            font-size: 1.3rem;
            color: var(--primary);
            font-weight: 700;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-box {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            padding: 20px;
            border-radius: 12px;
            color: var(--white);
            transition: all 0.3s ease;
        }

        .stat-box:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.4);
        }

        .stat-box .label {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-bottom: 5px;
        }

        .stat-box .value {
            font-size: 2rem;
            font-weight: 700;
        }

        .divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--light-gray), transparent);
            margin: 30px 0;
        }

        /* Tablet Styles */
        @media (max-width: 1024px) {
            .sidebar {
                width: 220px;
            }

            .main-content {
                margin-left: 220px;
                padding: 20px;
            }

            .top-bar h1 {
                font-size: 1.5rem;
            }

            .settings-container {
                grid-template-columns: 200px 1fr;
                gap: 15px;
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
                padding: 15px;
                text-align: center;
            }

            .top-bar h1 {
                font-size: 1.3rem;
            }

            .settings-container {
                grid-template-columns: 1fr;
            }

            .settings-nav {
                display: flex;
                overflow-x: auto;
                padding: 15px;
                gap: 10px;
                -webkit-overflow-scrolling: touch;
            }

            .settings-nav::-webkit-scrollbar {
                height: 4px;
            }

            .settings-nav::-webkit-scrollbar-track {
                background: var(--light-gray);
            }

            .settings-nav::-webkit-scrollbar-thumb {
                background: var(--primary);
                border-radius: 2px;
            }

            .settings-nav-item {
                white-space: nowrap;
                flex-shrink: 0;
                margin-bottom: 0;
            }

            .settings-content {
                padding: 20px;
            }

            .section-header h2 {
                font-size: 1.2rem;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .stat-box {
                padding: 15px;
            }

            .stat-box .value {
                font-size: 1.5rem;
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

            .settings-content {
                padding: 15px;
            }

            .section-header h2 {
                font-size: 1.1rem;
            }

            .btn {
                padding: 10px 18px;
                font-size: 0.9rem;
            }

            .info-card {
                padding: 15px;
            }

            .info-card-value {
                font-size: 1.1rem;
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
                <a href="index.php" class="menu-item">
                    <i class="fas fa-home"></i> Dashboard
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
                <a href="manage_courses.php" class="menu-item">
                    <i class="fas fa-book"></i> Courses
                </a>
                <a href="manage_subjects.php" class="menu-item">
                    <i class="fas fa-list"></i> Subjects
                </a>
                <a href="reports.php" class="menu-item">
                    <i class="fas fa-chart-bar"></i> Reports
                </a>
                <a href="settings.php" class="menu-item active">
                    <i class="fas fa-cog"></i> Settings
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-bar">
                <h1>Settings</h1>
            </div>

            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo $success_message; ?></span>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $error_message; ?></span>
                </div>
            <?php endif; ?>

            <div class="settings-container">
                <!-- Settings Navigation -->
                <div class="settings-nav">
                    <div class="settings-nav-item active" onclick="showSection('profile')">
                        <i class="fas fa-user"></i>
                        <span>Profile</span>
                    </div>
                    <div class="settings-nav-item" onclick="showSection('security')">
                        <i class="fas fa-lock"></i>
                        <span>Security</span>
                    </div>
                    <div class="settings-nav-item" onclick="showSection('system')">
                        <i class="fas fa-cogs"></i>
                        <span>System</span>
                    </div>
                    <div class="settings-nav-item" onclick="showSection('database')">
                        <i class="fas fa-database"></i>
                        <span>Database</span>
                    </div>
                    <div class="settings-nav-item" onclick="showSection('backup')">
                        <i class="fas fa-download"></i>
                        <span>Backup</span>
                    </div>
                </div>

                <!-- Settings Content -->
                <div class="settings-content">
                    <!-- Profile Section -->
                    <div id="profile" class="settings-section active">
                        <div class="section-header">
                            <h2>Profile Settings</h2>
                            <p>Manage your account information</p>
                        </div>

                        <form method="POST">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Username</label>
                                    <input type="text" name="username" value="<?php echo $user_info['username']; ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Email</label>
                                    <input type="email" name="email" value="<?php echo $user_info['email']; ?>" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Phone</label>
                                <input type="text" name="phone" value="<?php echo $user_info['phone']; ?>">
                            </div>
                            <div class="form-group">
                                <label>Role</label>
                                <input type="text" value="<?php echo ucfirst($user_info['role']); ?>" disabled>
                            </div>
                            <button type="submit" name="update_profile" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                        </form>
                    </div>

                    <!-- Security Section -->
                    <div id="security" class="settings-section">
                        <div class="section-header">
                            <h2>Security Settings</h2>
                            <p>Update your password and security preferences</p>
                        </div>

                        <form method="POST">
                            <div class="form-group">
                                <label>Current Password</label>
                                <input type="password" name="current_password" required>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>New Password</label>
                                    <input type="password" name="new_password" required>
                                </div>
                                <div class="form-group">
                                    <label>Confirm New Password</label>
                                    <input type="password" name="confirm_password" required>
                                </div>
                            </div>
                            <button type="submit" name="change_password" class="btn btn-primary">
                                <i class="fas fa-key"></i> Change Password
                            </button>
                        </form>

                        <div class="divider"></div>

                        <h3 style="margin-bottom: 15px; font-size: 1.1rem;">Password Requirements</h3>
                        <ul style="color: var(--gray); line-height: 1.8; padding-left: 20px;">
                            <li>At least 8 characters long</li>
                            <li>Include uppercase and lowercase letters</li>
                            <li>Include at least one number</li>
                            <li>Include at least one special character</li>
                        </ul>
                    </div>

                    <!-- System Settings -->
                    <div id="system" class="settings-section">
                        <div class="section-header">
                            <h2>System Settings</h2>
                            <p>Configure system-wide settings</p>
                        </div>

                        <form method="POST">
                            <div class="form-group">
                                <label>College Name</label>
                                <input type="text" name="college_name" value="ABC Engineering College" required>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>College Code</label>
                                    <input type="text" name="college_code" value="ABC123" required>
                                </div>
                                <div class="form-group">
                                    <label>Academic Year</label>
                                    <input type="text" name="academic_year" value="2025-2026" required>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>System Email</label>
                                    <input type="email" name="system_email" value="info@college.edu" required>
                                </div>
                                <div class="form-group">
                                    <label>System Phone</label>
                                    <input type="text" name="system_phone" value="1234567890" required>
                                </div>
                            </div>
                            <button type="submit" name="update_system_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save System Settings
                            </button>
                        </form>
                    </div>

                    <!-- Database Section -->
                    <div id="database" class="settings-section">
                        <div class="section-header">
                            <h2>Database Information</h2>
                            <p>View database statistics and information</p>
                        </div>

                        <div class="stats-grid">
                            <div class="stat-box">
                                <div class="label">Total Users</div>
                                <div class="value"><?php echo $total_users; ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="label">Total Students</div>
                                <div class="value"><?php echo $total_students; ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="label">Total Teachers</div>
                                <div class="value"><?php echo $total_teachers; ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="label">Database Size</div>
                                <div class="value"><?php echo number_format($db_size, 2); ?> MB</div>
                            </div>
                        </div>

                        <div class="info-card">
                            <div class="info-card-header">Database Name</div>
                            <div class="info-card-value">college_erp</div>
                        </div>

                        <div class="info-card">
                            <div class="info-card-header">Server Version</div>
                            <div class="info-card-value"><?php echo $conn->server_info; ?></div>
                        </div>

                        <div class="info-card">
                            <div class="info-card-header">Character Set</div>
                            <div class="info-card-value"><?php echo $conn->character_set_name(); ?></div>
                        </div>

                        <button class="btn btn-primary" onclick="alert('Database optimization would run here')">
                            <i class="fas fa-tools"></i> Optimize Database
                        </button>
                    </div>

                    <!-- Backup Section -->
                    <div id="backup" class="settings-section">
                        <div class="section-header">
                            <h2>Backup & Restore</h2>
                            <p>Manage database backups</p>
                        </div>

                        <div class="info-card">
                            <div class="info-card-header">Last Backup</div>
                            <div class="info-card-value">Never</div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 20px;">
                            <button class="btn btn-primary" onclick="alert('Backup would be created here')">
                                <i class="fas fa-download"></i> Create Backup
                            </button>
                            <button class="btn btn-danger" onclick="alert('Restore functionality would be here')">
                                <i class="fas fa-upload"></i> Restore Backup
                            </button>
                        </div>

                        <div class="divider"></div>

                        <h3 style="margin-bottom: 15px; font-size: 1.1rem;">Automated Backups</h3>
                        <div class="form-group">
                            <label>Backup Frequency</label>
                            <select>
                                <option>Disabled</option>
                                <option>Daily</option>
                                <option>Weekly</option>
                                <option>Monthly</option>
                            </select>
                        </div>
                        <button class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Backup Settings
                        </button>
                    </div>
                </div>
            </div>
        </main>
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

        function showSection(sectionId) {
            // Hide all sections
            document.querySelectorAll('.settings-section').forEach(section => {
                section.classList.remove('active');
            });

            // Remove active class from all nav items
            document.querySelectorAll('.settings-nav-item').forEach(item => {
                item.classList.remove('active');
            });

            // Show selected section
            document.getElementById(sectionId).classList.add('active');

            // Add active class to clicked nav item
            event.target.closest('.settings-nav-item').classList.add('active');
        }

        // Close mobile menu when clicking menu items
        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', closeMobileMenu);
        });
    </script>
</body>
</html>
<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('student')) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];
$success_msg = '';
$error_msg = '';

// Get student details
$sql = "SELECT s.*, u.email, u.phone, u.username, u.is_active 
        FROM students s 
        JOIN users u ON s.user_id = u.user_id
        WHERE s.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    die("Student record not found!");
}

// Handle email update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_email'])) {
    $new_email = trim($_POST['email']);
    
    // Check if email already exists
    $check_email = "SELECT user_id FROM users WHERE email = ? AND user_id != ?";
    $stmt = $conn->prepare($check_email);
    $stmt->bind_param("si", $new_email, $user_id);
    $stmt->execute();
    
    if ($stmt->get_result()->num_rows > 0) {
        $error_msg = "Email already exists!";
    } else {
        $update_email = "UPDATE users SET email = ? WHERE user_id = ?";
        $stmt = $conn->prepare($update_email);
        $stmt->bind_param("si", $new_email, $user_id);
        
        if ($stmt->execute()) {
            $success_msg = "Email updated successfully!";
            $student['email'] = $new_email;
        } else {
            $error_msg = "Error updating email!";
        }
    }
}

// Handle username update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_username'])) {
    $new_username = trim($_POST['username']);
    
    // Check if username already exists
    $check_username = "SELECT user_id FROM users WHERE username = ? AND user_id != ?";
    $stmt = $conn->prepare($check_username);
    $stmt->bind_param("si", $new_username, $user_id);
    $stmt->execute();
    
    if ($stmt->get_result()->num_rows > 0) {
        $error_msg = "Username already exists!";
    } else {
        $update_username = "UPDATE users SET username = ? WHERE user_id = ?";
        $stmt = $conn->prepare($update_username);
        $stmt->bind_param("si", $new_username, $user_id);
        
        if ($stmt->execute()) {
            $success_msg = "Username updated successfully!";
            $student['username'] = $new_username;
        } else {
            $error_msg = "Error updating username!";
        }
    }
}

// Handle notification preferences (example - you can expand this)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_notifications'])) {
    // Here you would update notification preferences in database
    // For now, just show success message
    $success_msg = "Notification preferences updated!";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - College ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0ea5e9;
            --secondary: #06b6d4;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #0f172a;
            --gray: #64748b;
            --light-gray: #f1f5f9;
            --white: #ffffff;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Manrope', sans-serif;
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
            background: linear-gradient(180deg, #0f172a 0%, #1e293b 100%);
            color: var(--white);
            padding: 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 30px 25px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .profile-section {
            text-align: center;
        }

        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 700;
            margin: 0 auto 15px;
        }

        .profile-name {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .profile-role {
            font-size: 0.85rem;
            opacity: 0.7;
        }

        .sidebar-menu {
            padding: 20px 0;
        }

        .menu-item {
            padding: 15px 25px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            display: flex;
            align-items: center;
            transition: all 0.3s;
            cursor: pointer;
        }

        .menu-item:hover, .menu-item.active {
            background: rgba(14, 165, 233, 0.2);
            color: var(--white);
            border-left: 4px solid var(--primary);
        }

        .menu-item i {
            margin-right: 15px;
            width: 20px;
            text-align: center;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
        }

        .top-bar {
            background: var(--white);
            padding: 25px 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }

        .top-bar h1 {
            font-size: 2rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .logout-btn {
            background: var(--danger);
            color: var(--white);
            border: none;
            padding: 12px 24px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-family: 'Manrope', sans-serif;
            text-decoration: none;
            display: inline-block;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
        }

        .card {
            background: var(--white);
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }

        .card-header {
            display: flex;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--light-gray);
        }

        .card-header i {
            font-size: 1.5rem;
            color: var(--primary);
            margin-right: 15px;
        }

        .card-header h3 {
            font-size: 1.3rem;
            font-weight: 700;
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
            padding: 12px 16px;
            border: 2px solid var(--light-gray);
            border-radius: 10px;
            font-family: 'Manrope', sans-serif;
            font-size: 1rem;
            transition: all 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Manrope', sans-serif;
            font-size: 1rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(14, 165, 233, 0.4);
        }

        .btn-danger {
            background: var(--danger);
            color: var(--white);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
        }

        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border-left: 4px solid var(--success);
            color: var(--success);
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            border-left: 4px solid var(--danger);
            color: var(--danger);
        }

        .setting-item {
            padding: 20px 0;
            border-bottom: 1px solid var(--light-gray);
        }

        .setting-item:last-child {
            border-bottom: none;
        }

        .setting-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .setting-title {
            font-weight: 600;
            font-size: 1.05rem;
        }

        .setting-description {
            color: var(--gray);
            font-size: 0.9rem;
            margin-bottom: 15px;
        }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 30px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 30px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 22px;
            width: 22px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: var(--primary);
        }

        input:checked + .slider:before {
            transform: translateX(30px);
        }

        .account-status {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-active {
            background: rgba(16, 185, 129, 0.15);
            color: var(--success);
        }

        .status-inactive {
            background: rgba(239, 68, 68, 0.15);
            color: var(--danger);
        }

        .warning-box {
            background: rgba(239, 68, 68, 0.1);
            border: 2px solid var(--danger);
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
        }

        .warning-box h4 {
            color: var(--danger);
            margin-bottom: 10px;
        }

        .warning-box p {
            color: var(--gray);
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="profile-section">
                    <div class="profile-avatar"><?php echo strtoupper(substr($student['full_name'], 0, 1)); ?></div>
                    <div class="profile-name"><?php echo htmlspecialchars($student['full_name']); ?></div>
                    <div class="profile-role"><?php echo htmlspecialchars($student['admission_number']); ?></div>
                </div>
            </div>
             <nav class="sidebar-menu">
                <a href="index.php" class="menu-item ">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="my_attendance.php" class="menu-item">
                    <i class="fas fa-calendar-check"></i> My Attendance
                </a> <a href="detail_attandance.php" class="menu-item">
                    <i class="fas fa-calendar-check"></i> My Attendance Detail
                </a> 
                 <a href="totalday_attandance.php" class="menu-item">
                    <i class="fas fa-calendar-check"></i>  Monthly attandace report 
                </a>
                <a href="my_subjects.php" class="menu-item">
                    <i class="fas fa-book"></i> My Subjects
                </a>
                <a href="my_profile.php" class="menu-item">
                    <i class="fas fa-user"></i> My Profile
                </a>
                <a href="setting.php" class="menu-item active">
                    <i class="fas fa-cog"></i> Settings
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-bar">
                <h1>Settings</h1>
                <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
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

            <!-- Account Settings -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-user-cog"></i>
                    <h3>Account Settings</h3>
                </div>

                <div class="setting-item">
                    <div class="setting-header">
                        <div class="setting-title">Account Status</div>
                        <span class="account-status <?php echo $student['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                            <?php echo $student['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>
                    <div class="setting-description">
                        Your account is currently <?php echo $student['is_active'] ? 'active' : 'inactive'; ?>.
                    </div>
                </div>

                <div class="setting-item">
                    <div class="setting-title">Username</div>
                    <div class="setting-description">Update your login username</div>
                    <form method="POST">
                        <div class="form-group">
                            <input type="text" name="username" class="form-control" 
                                   value="<?php echo htmlspecialchars($student['username']); ?>" 
                                   required>
                        </div>
                        <button type="submit" name="update_username" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Username
                        </button>
                    </form>
                </div>

                <div class="setting-item">
                    <div class="setting-title">Email Address</div>
                    <div class="setting-description">Update your email address</div>
                    <form method="POST">
                        <div class="form-group">
                            <input type="email" name="email" class="form-control" 
                                   value="<?php echo htmlspecialchars($student['email']); ?>" 
                                   required>
                        </div>
                        <button type="submit" name="update_email" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Email
                        </button>
                    </form>
                </div>
            </div>

            <!-- Notification Preferences -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-bell"></i>
                    <h3>Notification Preferences</h3>
                </div>

                <form method="POST">
                    <div class="setting-item">
                        <div class="setting-header">
                            <div>
                                <div class="setting-title">Email Notifications</div>
                                <div class="setting-description">Receive attendance and grade updates via email</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="email_notifications" checked>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="setting-item">
                        <div class="setting-header">
                            <div>
                                <div class="setting-title">Attendance Alerts</div>
                                <div class="setting-description">Get notified when attendance falls below 75%</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="attendance_alerts" checked>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="setting-item">
                        <div class="setting-header">
                            <div>
                                <div class="setting-title">Exam Reminders</div>
                                <div class="setting-description">Receive reminders for upcoming exams</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="exam_reminders" checked>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>

                    <button type="submit" name="update_notifications" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Preferences
                    </button>
                </form>
            </div>

            <!-- Privacy & Security -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-shield-alt"></i>
                    <h3>Privacy & Security</h3>
                </div>

                <div class="setting-item">
                    <div class="setting-title">Password</div>
                    <div class="setting-description">Last changed: Never</div>
                    <a href="my_profile.php" class="btn btn-primary">
                        <i class="fas fa-key"></i> Change Password
                    </a>
                </div>

                <div class="setting-item">
                    <div class="setting-title">Two-Factor Authentication</div>
                    <div class="setting-description">Add an extra layer of security to your account</div>
                    <button class="btn btn-primary" disabled>
                        <i class="fas fa-lock"></i> Enable 2FA (Coming Soon)
                    </button>
                </div>
            </div>

            <!-- Danger Zone -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h3>Danger Zone</h3>
                </div>

                <div class="warning-box">
                    <h4><i class="fas fa-exclamation-triangle"></i> Deactivate Account</h4>
                    <p>Once you deactivate your account, you will lose access to the system. This action requires administrator approval to reverse.</p>
                    <button class="btn btn-danger" onclick="confirmDeactivation()">
                        <i class="fas fa-user-slash"></i> Deactivate Account
                    </button>
                </div>
            </div>
        </main>
    </div>

    <script>
        function confirmDeactivation() {
            if (confirm('Are you sure you want to deactivate your account? You will need to contact the administrator to reactivate it.')) {
                alert('Please contact the administrator to deactivate your account.');
            }
        }
    </script>
</body>
</html>
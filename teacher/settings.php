<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('teacher')) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

// Get teacher details
$sql = "SELECT t.*, u.username, u.email, u.phone, u.is_active, d.department_name
        FROM teachers t 
        JOIN users u ON t.user_id = u.user_id
        JOIN departments d ON t.department_id = d.department_id 
        WHERE t.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();

// Handle notification preferences update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_notifications'])) {
    // In a real system, you'd save these to a preferences table
    $success_message = "Notification preferences updated successfully!";
}

// Handle email change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_email'])) {
    $new_email = $_POST['new_email'];
    $password = $_POST['password'];
    
    // Verify password
    $sql = "SELECT password FROM users WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if (password_verify($password, $result['password'])) {
        // Check if email already exists
        $check_sql = "SELECT user_id FROM users WHERE email = ? AND user_id != ?";
        $stmt = $conn->prepare($check_sql);
        $stmt->bind_param("si", $new_email, $user_id);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows > 0) {
            $email_error = "This email is already in use.";
        } else {
            $update_sql = "UPDATE users SET email = ? WHERE user_id = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("si", $new_email, $user_id);
            
            if ($stmt->execute()) {
                $email_success = "Email changed successfully!";
                $teacher['email'] = $new_email;
            } else {
                $email_error = "Error changing email.";
            }
        }
    } else {
        $email_error = "Incorrect password.";
    }
}

// Handle username change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_username'])) {
    $new_username = $_POST['new_username'];
    $password = $_POST['password_username'];
    
    // Verify password
    $sql = "SELECT password FROM users WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if (password_verify($password, $result['password'])) {
        // Check if username already exists
        $check_sql = "SELECT user_id FROM users WHERE username = ? AND user_id != ?";
        $stmt = $conn->prepare($check_sql);
        $stmt->bind_param("si", $new_username, $user_id);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows > 0) {
            $username_error = "This username is already taken.";
        } else {
            $update_sql = "UPDATE users SET username = ? WHERE user_id = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("si", $new_username, $user_id);
            
            if ($stmt->execute()) {
                $username_success = "Username changed successfully!";
                $teacher['username'] = $new_username;
            } else {
                $username_error = "Error changing username.";
            }
        }
    } else {
        $username_error = "Incorrect password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - College ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #8b5cf6;
            --secondary: #ec4899;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #18181b;
            --gray: #71717a;
            --light-gray: #fafafa;
            --white: #ffffff;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DM Sans', sans-serif;
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
            background: var(--dark);
            color: var(--white);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 30px 25px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
        }

        .logo {
            text-align: center;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--white);
        }

        .sidebar-menu {
            padding: 25px 0;
        }

        .menu-item {
            padding: 15px 25px;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            display: flex;
            align-items: center;
            transition: all 0.3s;
            cursor: pointer;
            border-left: 3px solid transparent;
        }

        .menu-item:hover, .menu-item.active {
            background: rgba(139, 92, 246, 0.1);
            color: var(--white);
            border-left-color: var(--primary);
        }

        .menu-item i {
            margin-right: 15px;
            width: 20px;
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
            padding: 25px 30px;
            border-radius: 20px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .top-bar h1 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .back-btn {
            background: linear-gradient(135deg, var(--gray), #52525b);
            color: var(--white);
            border: none;
            padding: 12px 28px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }

        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.2);
        }

        /* Settings Navigation */
        .settings-nav {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
            background: var(--white);
            padding: 20px;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            overflow-x: auto;
        }

        .settings-nav-item {
            padding: 12px 24px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 600;
            white-space: nowrap;
            border: 2px solid transparent;
            background: var(--light-gray);
            color: var(--gray);
        }

        .settings-nav-item.active {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
        }

        .settings-nav-item:hover {
            transform: translateY(-2px);
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
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.3rem;
            font-weight: 700;
        }

        /* Form */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e4e4e7;
            border-radius: 12px;
            font-size: 0.95rem;
            transition: all 0.3s;
            font-family: 'DM Sans', sans-serif;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        /* Toggle Switch */
        .toggle-switch {
            position: relative;
            width: 50px;
            height: 26px;
            display: inline-block;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .toggle-slider {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
        }

        input:checked + .toggle-slider:before {
            transform: translateX(24px);
        }

        /* Setting Item */
        .setting-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 0;
            border-bottom: 1px solid var(--light-gray);
        }

        .setting-item:last-child {
            border-bottom: none;
        }

        .setting-info h4 {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--dark);
        }

        .setting-info p {
            font-size: 0.9rem;
            color: var(--gray);
        }

        /* Buttons */
        .btn {
            padding: 12px 28px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.95rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(139, 92, 246, 0.4);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #dc2626);
            color: var(--white);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
        }

        /* Alert */
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
        }

        .alert i {
            margin-right: 12px;
            font-size: 1.2rem;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.15);
            color: var(--success);
            border-left: 4px solid var(--success);
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.15);
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }

        .alert-info {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
            border-left: 4px solid #3b82f6;
        }

        /* Tab Content */
        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .info-box {
            background: rgba(139, 92, 246, 0.1);
            border-left: 4px solid var(--primary);
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }

        .info-box p {
            margin: 0;
            color: var(--dark);
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="logo">College ERP</div>
            </div>
               <nav class="sidebar-menu">
                <a href="index.php" class="menu-item">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="mark_attendance.php" class="menu-item">
                    <i class="fas fa-calendar-check"></i> Mark Attendance
                </a>
                <a href="my_classes.php" class="menu-item">
                    <i class="fas fa-chalkboard"></i> My Classes
                </a>
                <a href="view_students.php" class="menu-item">
                    <i class="fas fa-users"></i> Students
                </a>
                
                <a href="teacher_profile.php" class="menu-item">
                    <i class="fas fa-user"></i> Profile
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
                <a href="teacher_dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            </div>

            <!-- Settings Navigation -->
            <div class="settings-nav">
                <div class="settings-nav-item active" onclick="switchTab('account')">
                    <i class="fas fa-user-circle"></i> Account
                </div>
                <div class="settings-nav-item" onclick="switchTab('notifications')">
                    <i class="fas fa-bell"></i> Notifications
                </div>
                <div class="settings-nav-item" onclick="switchTab('security')">
                    <i class="fas fa-shield-alt"></i> Security
                </div>
                <div class="settings-nav-item" onclick="switchTab('preferences')">
                    <i class="fas fa-sliders-h"></i> Preferences
                </div>
            </div>

            <!-- Account Settings -->
            <div id="account-tab" class="tab-content active">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-user-circle"></i>
                        <h3>Account Information</h3>
                    </div>
                    <div class="setting-item">
                        <div class="setting-info">
                            <h4>Username</h4>
                            <p>@<?php echo $teacher['username']; ?></p>
                        </div>
                    </div>
                    <div class="setting-item">
                        <div class="setting-info">
                            <h4>Email Address</h4>
                            <p><?php echo $teacher['email']; ?></p>
                        </div>
                    </div>
                    <div class="setting-item">
                        <div class="setting-info">
                            <h4>Department</h4>
                            <p><?php echo $teacher['department_name']; ?></p>
                        </div>
                    </div>
                    <div class="setting-item">
                        <div class="setting-info">
                            <h4>Account Status</h4>
                            <p><?php echo $teacher['is_active'] ? 'Active' : 'Inactive'; ?></p>
                        </div>
                    </div>
                </div>

                <!-- Change Email -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-envelope"></i>
                        <h3>Change Email</h3>
                    </div>
                    <?php if (isset($email_success)): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i>
                            <?php echo $email_success; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($email_error)): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle"></i>
                            <?php echo $email_error; ?>
                        </div>
                    <?php endif; ?>
                    <form method="POST" action="">
                        <div class="form-group">
                            <label>New Email Address</label>
                            <input type="email" name="new_email" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Confirm Password</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <button type="submit" name="change_email" class="btn btn-primary">
                            <i class="fas fa-save"></i> Change Email
                        </button>
                    </form>
                </div>

                <!-- Change Username -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-at"></i>
                        <h3>Change Username</h3>
                    </div>
                    <?php if (isset($username_success)): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i>
                            <?php echo $username_success; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($username_error)): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle"></i>
                            <?php echo $username_error; ?>
                        </div>
                    <?php endif; ?>
                    <form method="POST" action="">
                        <div class="form-group">
                            <label>New Username</label>
                            <input type="text" name="new_username" class="form-control" required pattern="[a-zA-Z0-9_]+" title="Only letters, numbers, and underscores allowed">
                        </div>
                        <div class="form-group">
                            <label>Confirm Password</label>
                            <input type="password" name="password_username" class="form-control" required>
                        </div>
                        <button type="submit" name="change_username" class="btn btn-primary">
                            <i class="fas fa-save"></i> Change Username
                        </button>
                    </form>
                </div>
            </div>

            <!-- Notification Settings -->
            <div id="notifications-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-bell"></i>
                        <h3>Notification Preferences</h3>
                    </div>
                    <?php if (isset($success_message)): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i>
                            <?php echo $success_message; ?>
                        </div>
                    <?php endif; ?>
                    <form method="POST" action="">
                        <div class="setting-item">
                            <div class="setting-info">
                                <h4>Email Notifications</h4>
                                <p>Receive updates and alerts via email</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="email_notifications" checked>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <div class="setting-item">
                            <div class="setting-info">
                                <h4>Attendance Reminders</h4>
                                <p>Get reminded to mark attendance for your classes</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="attendance_reminders" checked>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <div class="setting-item">
                            <div class="setting-info">
                                <h4>Assignment Notifications</h4>
                                <p>Alerts when students submit assignments</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="assignment_notifications" checked>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <div class="setting-item">
                            <div class="setting-info">
                                <h4>Low Attendance Alerts</h4>
                                <p>Notify when students have low attendance</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="low_attendance_alerts" checked>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <button type="submit" name="update_notifications" class="btn btn-primary" style="margin-top: 20px;">
                            <i class="fas fa-save"></i> Save Preferences
                        </button>
                    </form>
                </div>
            </div>

            <!-- Security Settings -->
            <div id="security-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-shield-alt"></i>
                        <h3>Security Settings</h3>
                    </div>
                    <div class="info-box">
                        <p><i class="fas fa-info-circle"></i> Keep your account secure by using a strong password and enabling additional security features.</p>
                    </div>
                    <div class="setting-item">
                        <div class="setting-info">
                            <h4>Change Password</h4>
                            <p>Update your password regularly for better security</p>
                        </div>
                        <a href="teacher_profile.php" class="btn btn-primary">
                            <i class="fas fa-key"></i> Change Password
                        </a>
                    </div>
                    <div class="setting-item">
                        <div class="setting-info">
                            <h4>Two-Factor Authentication</h4>
                            <p>Add an extra layer of security to your account</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="two_factor">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="setting-item">
                        <div class="setting-info">
                            <h4>Login Alerts</h4>
                            <p>Get notified of new login attempts</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="login_alerts" checked>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Preferences Settings -->
            <div id="preferences-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-sliders-h"></i>
                        <h3>Display Preferences</h3>
                    </div>
                    <div class="setting-item">
                        <div class="setting-info">
                            <h4>Theme</h4>
                            <p>Choose your preferred color theme</p>
                        </div>
                        <select class="form-control" style="max-width: 200px;">
                            <option>Light</option>
                            <option>Dark</option>
                            <option>Auto</option>
                        </select>
                    </div>
                    <div class="setting-item">
                        <div class="setting-info">
                            <h4>Language</h4>
                            <p>Select your preferred language</p>
                        </div>
                        <select class="form-control" style="max-width: 200px;">
                            <option>English</option>
                            <option>Hindi</option>
                        </select>
                    </div>
                    <div class="setting-item">
                        <div class="setting-info">
                            <h4>Date Format</h4>
                            <p>Choose how dates are displayed</p>
                        </div>
                        <select class="form-control" style="max-width: 200px;">
                            <option>DD/MM/YYYY</option>
                            <option>MM/DD/YYYY</option>
                            <option>YYYY-MM-DD</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary" style="margin-top: 20px;">
                        <i class="fas fa-save"></i> Save Preferences
                    </button>
                </div>
            </div>
        </main>
    </div>

    <script>
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active from all nav items
            document.querySelectorAll('.settings-nav-item').forEach(item => {
                item.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Add active to clicked nav item
            event.target.closest('.settings-nav-item').classList.add('active');
        }
    </script>
</body>
</html>
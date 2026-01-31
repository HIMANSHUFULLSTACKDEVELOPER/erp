<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('hod')) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];
$success_msg = '';
$error_msg = '';

// Get HOD details
$sql = "SELECT t.*, d.department_name, d.department_id, u.email, u.phone, u.username
        FROM teachers t 
        JOIN departments d ON d.hod_id = t.user_id
        JOIN users u ON t.user_id = u.user_id
        WHERE t.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$hod = $stmt->get_result()->fetch_assoc();

if (!$hod) {
    die("HOD profile not found or not assigned to any department.");
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $qualification = trim($_POST['qualification']);
    $designation = trim($_POST['designation']);
    
    // Update users table
    $sql = "UPDATE users SET email = ?, phone = ? WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssi", $email, $phone, $user_id);
    
    if ($stmt->execute()) {
        // Update teachers table
        $sql = "UPDATE teachers SET full_name = ?, qualification = ?, designation = ? WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssi", $full_name, $qualification, $designation, $user_id);
        
        if ($stmt->execute()) {
            $success_msg = "Profile updated successfully!";
            // Refresh data
            header("Location: hod_profile.php?success=1");
            exit();
        } else {
            $error_msg = "Error updating teacher profile.";
        }
    } else {
        $error_msg = "Error updating user profile.";
    }
}

if (isset($_GET['success'])) {
    $success_msg = "Profile updated successfully!";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HOD Profile - College ERP</title>
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
            text-decoration: none;
            display: inline-block;
        }

        .back-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 25px rgba(87, 83, 78, 0.4);
        }

        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-weight: 500;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.1);
            color: var(--success);
            border-left: 4px solid var(--success);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }

        /* Profile Card */
        .profile-grid {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 30px;
        }

        .profile-card {
            background: var(--white);
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            text-align: center;
        }

        .profile-avatar-large {
            width: 150px;
            height: 150px;
            border-radius: 25px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 4rem;
            font-weight: 800;
            margin: 0 auto 25px;
            box-shadow: 0 10px 30px rgba(249, 115, 22, 0.3);
        }

        .profile-name {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 8px;
            color: var(--dark);
        }

        .profile-designation {
            color: var(--primary);
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 5px;
        }

        .profile-dept {
            color: var(--gray);
            font-size: 0.95rem;
            margin-bottom: 30px;
        }

        .profile-info-item {
            display: flex;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid var(--light-gray);
        }

        .profile-info-item:last-child {
            border-bottom: none;
        }

        .profile-info-item i {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: rgba(249, 115, 22, 0.1);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
        }

        .profile-info-content {
            text-align: left;
            flex: 1;
        }

        .profile-info-label {
            font-size: 0.8rem;
            color: var(--gray);
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 3px;
        }

        .profile-info-value {
            font-size: 1rem;
            color: var(--dark);
            font-weight: 600;
        }

        /* Edit Form */
        .edit-card {
            background: var(--white);
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .card-header {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--light-gray);
        }

        .card-header h3 {
            font-size: 1.5rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            color: var(--dark);
        }

        .card-header h3 i {
            color: var(--primary);
            margin-right: 12px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.95rem;
        }

        .form-label i {
            color: var(--primary);
            margin-right: 8px;
        }

        .form-control {
            width: 100%;
            padding: 14px 18px;
            border: 2px solid var(--light-gray);
            border-radius: 12px;
            font-size: 1rem;
            font-family: 'Outfit', sans-serif;
            transition: all 0.3s;
            background: var(--white);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(249, 115, 22, 0.1);
        }

        .form-control:disabled {
            background: var(--light-gray);
            cursor: not-allowed;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            border: none;
            padding: 14px 32px;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Outfit', sans-serif;
            box-shadow: 0 4px 15px rgba(249, 115, 22, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 25px rgba(249, 115, 22, 0.4);
        }

        .btn-secondary {
            background: var(--white);
            color: var(--gray);
            border: 2px solid var(--light-gray);
            padding: 14px 32px;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Outfit', sans-serif;
            text-decoration: none;
            display: inline-block;
            margin-right: 15px;
        }

        .btn-secondary:hover {
            border-color: var(--gray);
            background: var(--light-gray);
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
                    <h1>My Profile</h1>
                    <p>View and manage your profile information</p>
                </div>
                <a href="index.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            </div>

            <?php if ($success_msg): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $success_msg; ?>
            </div>
            <?php endif; ?>

            <?php if ($error_msg): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error_msg; ?>
            </div>
            <?php endif; ?>

            <div class="profile-grid">
                <!-- Profile Card -->
                <div class="profile-card">
                    <div class="profile-avatar-large">
                        <?php echo strtoupper(substr($hod['full_name'], 0, 1)); ?>
                    </div>
                    <div class="profile-name"><?php echo $hod['full_name']; ?></div>
                    <div class="profile-designation"><?php echo $hod['designation'] ?? 'Head of Department'; ?></div>
                    <div class="profile-dept"><?php echo $hod['department_name']; ?></div>

                    <div style="margin-top: 30px;">
                        <div class="profile-info-item">
                            <i class="fas fa-envelope"></i>
                            <div class="profile-info-content">
                                <div class="profile-info-label">Email Address</div>
                                <div class="profile-info-value"><?php echo $hod['email']; ?></div>
                            </div>
                        </div>
                        <div class="profile-info-item">
                            <i class="fas fa-phone"></i>
                            <div class="profile-info-content">
                                <div class="profile-info-label">Phone Number</div>
                                <div class="profile-info-value"><?php echo $hod['phone'] ?? 'Not provided'; ?></div>
                            </div>
                        </div>
                        <div class="profile-info-item">
                            <i class="fas fa-user"></i>
                            <div class="profile-info-content">
                                <div class="profile-info-label">Username</div>
                                <div class="profile-info-value"><?php echo $hod['username']; ?></div>
                            </div>
                        </div>
                        <div class="profile-info-item">
                            <i class="fas fa-calendar"></i>
                            <div class="profile-info-content">
                                <div class="profile-info-label">Date of Joining</div>
                                <div class="profile-info-value">
                                    <?php echo $hod['date_of_joining'] ? date('M d, Y', strtotime($hod['date_of_joining'])) : 'Not provided'; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Edit Form -->
                <div class="edit-card">
                    <div class="card-header">
                        <h3><i class="fas fa-edit"></i> Edit Profile</h3>
                    </div>

                    <form method="POST" action="">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-user"></i> Full Name
                            </label>
                            <input type="text" name="full_name" class="form-control" 
                                   value="<?php echo htmlspecialchars($hod['full_name']); ?>" required>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-envelope"></i> Email Address
                                </label>
                                <input type="email" name="email" class="form-control" 
                                       value="<?php echo htmlspecialchars($hod['email']); ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-phone"></i> Phone Number
                                </label>
                                <input type="text" name="phone" class="form-control" 
                                       value="<?php echo htmlspecialchars($hod['phone'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-graduation-cap"></i> Qualification
                                </label>
                                <input type="text" name="qualification" class="form-control" 
                                       value="<?php echo htmlspecialchars($hod['qualification'] ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-briefcase"></i> Designation
                                </label>
                                <input type="text" name="designation" class="form-control" 
                                       value="<?php echo htmlspecialchars($hod['designation'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-building"></i> Department
                            </label>
                            <input type="text" class="form-control" 
                                   value="<?php echo htmlspecialchars($hod['department_name']); ?>" disabled>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-user-circle"></i> Username
                            </label>
                            <input type="text" class="form-control" 
                                   value="<?php echo htmlspecialchars($hod['username']); ?>" disabled>
                        </div>

                        <div style="margin-top: 35px;">
                            <button type="submit" name="update_profile" class="btn-primary">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('parent')) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

// Get parent and user details
$sql = "SELECT p.*, u.username, u.email, u.phone 
        FROM parents p 
        JOIN users u ON p.user_id = u.user_id 
        WHERE p.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$parent = $stmt->get_result()->fetch_assoc();

// Get linked students
$students = $conn->query("SELECT s.*, d.department_name, c.course_name
                         FROM parent_student ps
                         JOIN students s ON ps.student_id = s.student_id
                         JOIN departments d ON s.department_id = d.department_id
                         JOIN courses c ON s.course_id = c.course_id
                         WHERE ps.parent_id = {$parent['parent_id']}");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Parent Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #6366f1;
            --secondary: #a855f7;
            --success: #22c55e;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #1e293b;
            --gray: #64748b;
            --light-gray: #f8fafc;
            --white: #ffffff;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
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

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
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
            width: 50px;
            height: 50px;
            border-radius: 50%;
            font-size: 1.5rem;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.4);
            transition: all 0.3s;
        }

        .mobile-menu-toggle:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.5);
        }

        .mobile-menu-toggle:active {
            transform: scale(0.95);
        }

        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #1e293b 0%, #334155 100%);
            color: var(--white);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: transform 0.3s ease;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 35px 25px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            animation: slideInLeft 0.5s ease;
        }

        .parent-info {
            text-align: center;
        }

        .parent-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: rgba(255,255,255,0.25);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 700;
            margin: 0 auto 12px;
            border: 3px solid rgba(255,255,255,0.3);
            transition: all 0.3s;
        }

        .parent-avatar:hover {
            transform: scale(1.1) rotate(5deg);
            border-color: rgba(255,255,255,0.6);
        }

        .parent-name {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .parent-role {
            font-size: 0.85rem;
            opacity: 0.9;
            text-transform: capitalize;
        }

        .sidebar-menu {
            padding: 25px 0;
        }

        .menu-item {
            padding: 16px 25px;
            color: rgba(255,255,255,0.75);
            text-decoration: none;
            display: flex;
            align-items: center;
            transition: all 0.3s;
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
            background: var(--white);
            transform: scaleY(0);
            transition: transform 0.3s;
        }

        .menu-item:hover, .menu-item.active {
            background: rgba(99, 102, 241, 0.2);
            color: var(--white);
            padding-left: 30px;
        }

        .menu-item:hover::before, .menu-item.active::before {
            transform: scaleY(1);
        }

        .menu-item i {
            margin-right: 15px;
            width: 20px;
            text-align: center;
            transition: all 0.3s;
        }

        .menu-item:hover i {
            transform: scale(1.2);
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            animation: fadeIn 0.5s ease;
        }

        .top-bar {
            background: var(--white);
            padding: 25px 30px;
            border-radius: 18px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            animation: fadeIn 0.6s ease;
            flex-wrap: wrap;
            gap: 15px;
        }

        .top-bar h1 {
            font-size: 2rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .card {
            background: var(--white);
            padding: 30px;
            border-radius: 18px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin-bottom: 25px;
            animation: fadeIn 0.7s ease;
            transition: all 0.3s;
        }

        .card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
            transform: translateY(-5px);
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
            animation: pulse 2s infinite;
        }

        .card-header h3 {
            font-size: 1.4rem;
            font-weight: 700;
        }

        .profile-header {
            text-align: center;
            margin-bottom: 30px;
            animation: fadeIn 0.8s ease;
        }

        .profile-avatar-large {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: 800;
            margin: 0 auto 20px;
            border: 5px solid var(--white);
            box-shadow: 0 8px 25px rgba(99, 102, 241, 0.3);
            transition: all 0.4s;
        }

        .profile-avatar-large:hover {
            transform: scale(1.1) rotate(360deg);
            box-shadow: 0 12px 35px rgba(99, 102, 241, 0.5);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .info-item {
            background: var(--light-gray);
            padding: 20px;
            border-radius: 12px;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .info-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(99, 102, 241, 0.1), transparent);
            transition: left 0.5s;
        }

        .info-item:hover {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.05), rgba(168, 85, 247, 0.05));
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .info-item:hover::before {
            left: 100%;
        }

        .info-label {
            font-size: 0.85rem;
            color: var(--gray);
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .info-value {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark);
        }

        .children-list {
            display: grid;
            gap: 15px;
        }

        .child-item {
            background: var(--light-gray);
            padding: 20px;
            border-radius: 12px;
            border-left: 4px solid var(--primary);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s;
            animation: slideInLeft 0.5s ease;
        }

        .child-item:hover {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.08), rgba(168, 85, 247, 0.08));
            transform: translateX(10px);
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.2);
            border-left-width: 6px;
        }

        .child-avatar-small {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            font-weight: 700;
            flex-shrink: 0;
            transition: all 0.3s;
        }

        .child-item:hover .child-avatar-small {
            transform: scale(1.15) rotate(5deg);
        }

        .child-details h4 {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
            transition: color 0.3s;
        }

        .child-item:hover .child-details h4 {
            color: var(--primary);
        }

        .child-details p {
            font-size: 0.9rem;
            color: var(--gray);
            margin: 2px 0;
        }

        .child-details i {
            margin-right: 5px;
            color: var(--primary);
        }

        .back-btn {
            background: linear-gradient(135deg, var(--gray), #475569);
            color: var(--white);
            border: none;
            padding: 12px 26px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }

        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(71, 85, 105, 0.3);
            background: linear-gradient(135deg, #475569, var(--gray));
        }

        .back-btn:active {
            transform: translateY(0);
        }

        /* Tablet Styles */
        @media (max-width: 1024px) {
            .sidebar {
                width: 250px;
            }

            .main-content {
                margin-left: 250px;
                padding: 20px;
            }

            .top-bar h1 {
                font-size: 1.6rem;
            }

            .info-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
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

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 90px 15px 15px;
            }

            .top-bar {
                padding: 20px;
                flex-direction: column;
                align-items: flex-start;
            }

            .top-bar h1 {
                font-size: 1.5rem;
                margin-bottom: 10px;
            }

            .card {
                padding: 20px;
            }

            .profile-avatar-large {
                width: 100px;
                height: 100px;
                font-size: 2.5rem;
            }

            .info-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .child-item {
                flex-direction: column;
                text-align: center;
                padding: 15px;
            }

            .child-avatar-small {
                width: 60px;
                height: 60px;
                font-size: 1.5rem;
            }

            .card-header h3 {
                font-size: 1.2rem;
            }

            .sidebar-header {
                padding: 25px 20px;
            }

            .parent-avatar {
                width: 60px;
                height: 60px;
                font-size: 1.5rem;
            }

            .parent-name {
                font-size: 1.1rem;
            }
        }

        /* Small Mobile Styles */
        @media (max-width: 480px) {
            .main-content {
                padding: 80px 10px 10px;
            }

            .top-bar {
                padding: 15px;
            }

            .top-bar h1 {
                font-size: 1.3rem;
            }

            .card {
                padding: 15px;
                border-radius: 12px;
            }

            .profile-avatar-large {
                width: 80px;
                height: 80px;
                font-size: 2rem;
            }

            .info-item {
                padding: 15px;
            }

            .info-label {
                font-size: 0.75rem;
            }

            .info-value {
                font-size: 1rem;
            }

            .child-details h4 {
                font-size: 1rem;
            }

            .child-details p {
                font-size: 0.85rem;
            }

            .back-btn {
                padding: 10px 20px;
                font-size: 0.9rem;
                width: 100%;
                text-align: center;
            }

            .card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .card-header i {
                font-size: 1.2rem;
            }

            .card-header h3 {
                font-size: 1.1rem;
            }
        }

        /* Overlay for mobile menu */
        .overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .overlay.active {
            display: block;
            opacity: 1;
        }
    </style>
</head>
<body>
    <button class="mobile-menu-toggle" onclick="toggleMenu()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="overlay" onclick="toggleMenu()"></div>

    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="parent-info">
                    <div class="parent-avatar"><?php echo strtoupper(substr($parent['full_name'], 0, 1)); ?></div>
                    <div class="parent-name"><?php echo $parent['full_name']; ?></div>
                    <div class="parent-role"><?php echo ucfirst($parent['relation']); ?></div>
                </div>
            </div>
            <nav class="sidebar-menu">
                <a href="index.php" class="menu-item" onclick="closeMobileMenu()">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="children_attendance.php" class="menu-item" onclick="closeMobileMenu()">
                    <i class="fas fa-calendar-check"></i> Attendance
                </a>
                <a href="semester_history.php" class="menu-item" onclick="closeMobileMenu()">
                    <i class="fas fa-history"></i> Semester History
                </a>
                <a href="children_subjects.php" class="menu-item" onclick="closeMobileMenu()">
                    <i class="fas fa-book"></i> Subjects
                </a>
                <a href="parent_profile.php" class="menu-item active" onclick="closeMobileMenu()">
                    <i class="fas fa-user"></i> Profile
                </a>
                <a href="parent_settings.php" class="menu-item" onclick="closeMobileMenu()">
                    <i class="fas fa-cog"></i> Settings
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-bar">
                <h1>My Profile</h1>
                <a href="parent_dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            </div>

            <div class="card">
                <div class="profile-header">
                    <div class="profile-avatar-large"><?php echo strtoupper(substr($parent['full_name'], 0, 1)); ?></div>
                    <h2 style="font-size: 2rem; margin-bottom: 5px;"><?php echo $parent['full_name']; ?></h2>
                    <p style="color: var(--gray); font-size: 1.1rem; text-transform: capitalize;">
                        <?php echo $parent['relation']; ?>
                    </p>
                </div>

                <div class="card-header">
                    <i class="fas fa-id-card"></i>
                    <h3>Personal Information</h3>
                </div>

                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Full Name</div>
                        <div class="info-value"><?php echo $parent['full_name']; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Relationship</div>
                        <div class="info-value"><?php echo ucfirst($parent['relation']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Username</div>
                        <div class="info-value"><?php echo $parent['username']; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Email</div>
                        <div class="info-value" style="word-break: break-all;"><?php echo $parent['email']; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Phone</div>
                        <div class="info-value"><?php echo $parent['phone']; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Member Since</div>
                        <div class="info-value"><?php echo date('d M Y', strtotime($parent['created_at'])); ?></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <i class="fas fa-users"></i>
                    <h3>Linked Children</h3>
                </div>

                <?php if ($students->num_rows > 0): ?>
                <div class="children-list">
                    <?php while($student = $students->fetch_assoc()): ?>
                    <div class="child-item">
                        <div class="child-avatar-small"><?php echo strtoupper(substr($student['full_name'], 0, 1)); ?></div>
                        <div class="child-details">
                            <h4><?php echo $student['full_name']; ?></h4>
                            <p><i class="fas fa-id-card"></i> <?php echo $student['admission_number']; ?></p>
                            <p><i class="fas fa-graduation-cap"></i> <?php echo $student['course_name']; ?></p>
                            <p><i class="fas fa-building"></i> <?php echo $student['department_name']; ?></p>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
                <?php else: ?>
                <p style="text-align: center; color: var(--gray); padding: 40px;">No children linked to your account.</p>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        function toggleMenu() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.overlay');
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
        }

        function closeMobileMenu() {
            if (window.innerWidth <= 768) {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.querySelector('.overlay');
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
            }
        }
    </script>
</body>
</html>
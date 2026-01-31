<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('admin')) {
    redirect('login.php');
}

// Handle course deletion
if (isset($_POST['delete_course'])) {
    $course_id = $_POST['course_id'];
    $conn->query("DELETE FROM courses WHERE course_id = $course_id");
    $success_message = "Course deleted successfully!";
}

// Handle course addition
if (isset($_POST['add_course'])) {
    $course_name = $_POST['course_name'];
    $course_code = $_POST['course_code'];
    $duration_years = $_POST['duration_years'];
    
    $stmt = $conn->prepare("INSERT INTO courses (course_name, course_code, duration_years) VALUES (?, ?, ?)");
    $stmt->bind_param("ssi", $course_name, $course_code, $duration_years);
    
    if ($stmt->execute()) {
        $success_message = "Course added successfully!";
    }
}

// Handle course update
if (isset($_POST['update_course'])) {
    $course_id = $_POST['course_id'];
    $course_name = $_POST['course_name'];
    $course_code = $_POST['course_code'];
    $duration_years = $_POST['duration_years'];
    
    $stmt = $conn->prepare("UPDATE courses SET course_name=?, course_code=?, duration_years=? WHERE course_id=?");
    $stmt->bind_param("ssii", $course_name, $course_code, $duration_years, $course_id);
    
    if ($stmt->execute()) {
        $success_message = "Course updated successfully!";
    }
}

// Get all courses with student count
$courses = $conn->query("SELECT c.*, 
                        (SELECT COUNT(*) FROM students WHERE course_id = c.course_id) as student_count
                        FROM courses c 
                        ORDER BY c.course_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Courses - College ERP</title>
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

        @keyframes float {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-10px);
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
            transition: all 0.3s ease;
        }

        .courses-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
            animation: fadeIn 0.6s ease;
        }

        .course-card {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 12px;
            padding: 25px;
            color: var(--white);
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            animation: scaleIn 0.5s ease;
            animation-fill-mode: backwards;
        }

        .course-card:nth-child(1) { animation-delay: 0.1s; }
        .course-card:nth-child(2) { animation-delay: 0.2s; }
        .course-card:nth-child(3) { animation-delay: 0.3s; }
        .course-card:nth-child(4) { animation-delay: 0.4s; }
        .course-card:nth-child(5) { animation-delay: 0.5s; }
        .course-card:nth-child(6) { animation-delay: 0.6s; }

        .course-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200px;
            height: 200px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            transition: all 0.5s ease;
        }

        .course-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 24px rgba(37, 99, 235, 0.3);
        }

        .course-card:hover::before {
            transform: scale(1.5);
            opacity: 0.5;
        }

        .course-header {
            position: relative;
            z-index: 1;
        }

        .course-icon {
            width: 60px;
            height: 60px;
            background: rgba(255,255,255,0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }

        .course-card:hover .course-icon {
            transform: rotate(10deg) scale(1.1);
            animation: float 2s ease-in-out infinite;
        }

        .course-title {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .course-code {
            opacity: 0.9;
            font-size: 0.95rem;
        }

        .course-info {
            position: relative;
            z-index: 1;
            margin: 20px 0;
            padding: 15px;
            background: rgba(255,255,255,0.1);
            border-radius: 8px;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }

        .course-card:hover .course-info {
            background: rgba(255,255,255,0.15);
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .info-row:last-child {
            margin-bottom: 0;
        }

        .info-label {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .info-value {
            font-weight: 600;
            font-size: 1.1rem;
        }

        .course-actions {
            position: relative;
            z-index: 1;
            display: flex;
            gap: 10px;
        }

        .course-actions .btn {
            flex: 1;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.3);
        }

        .course-actions .btn:hover {
            background: rgba(255,255,255,0.3);
            border-color: rgba(255,255,255,0.5);
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
            .courses-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
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

            .courses-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .course-card {
                padding: 20px;
            }

            .course-title {
                font-size: 1.1rem;
            }

            .course-icon {
                width: 50px;
                height: 50px;
                font-size: 1.5rem;
            }

            .modal-content {
                padding: 20px;
            }

            .modal-header h2 {
                font-size: 1.2rem;
            }

            .course-actions {
                flex-direction: column;
            }

            .course-actions form {
                width: 100%;
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

            .course-card {
                padding: 15px;
            }

            .course-title {
                font-size: 1rem;
            }

            .course-code {
                font-size: 0.85rem;
            }

            .course-icon {
                width: 45px;
                height: 45px;
                font-size: 1.3rem;
            }

            .info-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }

            .info-value {
                font-size: 1rem;
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
                <a href="manage_departments.php" class="menu-item" onclick="closeSidebarMobile()">
                    <i class="fas fa-building"></i> Departments
                </a>
                <a href="manage_courses.php" class="menu-item active" onclick="closeSidebarMobile()">
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
                <h1>Manage Courses</h1>
                <button class="btn btn-primary" onclick="openAddModal()">
                    <i class="fas fa-plus"></i> Add New Course
                </button>
            </div>

            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                </div>
            <?php endif; ?>

            <div class="courses-grid">
                <?php while($course = $courses->fetch_assoc()): ?>
                <div class="course-card">
                    <div class="course-header">
                        <div class="course-icon">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        <div class="course-title"><?php echo $course['course_name']; ?></div>
                        <div class="course-code"><?php echo $course['course_code']; ?></div>
                    </div>

                    <div class="course-info">
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-clock"></i> Duration</span>
                            <span class="info-value"><?php echo $course['duration_years']; ?> Years</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-users"></i> Students</span>
                            <span class="info-value"><?php echo $course['student_count']; ?></span>
                        </div>
                    </div>

                    <div class="course-actions">
                        <button class="btn btn-sm" onclick='editCourse(<?php echo json_encode($course); ?>)'>
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <form method="POST" style="flex: 1;" onsubmit="return confirm('Are you sure you want to delete this course?');">
                            <input type="hidden" name="course_id" value="<?php echo $course['course_id']; ?>">
                            <button type="submit" name="delete_course" class="btn btn-sm" style="width: 100%;">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </form>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        </main>
    </div>

    <!-- Add Course Modal -->
    <div id="addCourseModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-plus-circle"></i> Add New Course</h2>
                <span class="close" onclick="closeAddModal()">&times;</span>
            </div>
            <form method="POST">
                <div class="form-group">
                    <label><i class="fas fa-book"></i> Course Name *</label>
                    <input type="text" name="course_name" placeholder="e.g., Bachelor of Engineering" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-code"></i> Course Code *</label>
                    <input type="text" name="course_code" placeholder="e.g., BE" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-calendar-alt"></i> Duration (Years) *</label>
                    <input type="number" name="duration_years" min="1" max="10" value="4" required>
                </div>
                <button type="submit" name="add_course" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-plus"></i> Add Course
                </button>
            </form>
        </div>
    </div>

    <!-- Edit Course Modal -->
    <div id="editCourseModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-edit"></i> Edit Course</h2>
                <span class="close" onclick="closeEditModal()">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="course_id" id="edit_course_id">
                <div class="form-group">
                    <label><i class="fas fa-book"></i> Course Name *</label>
                    <input type="text" name="course_name" id="edit_course_name" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-code"></i> Course Code *</label>
                    <input type="text" name="course_code" id="edit_course_code" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-calendar-alt"></i> Duration (Years) *</label>
                    <input type="number" name="duration_years" id="edit_duration_years" min="1" max="10" required>
                </div>
                <button type="submit" name="update_course" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-save"></i> Update Course
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
            document.getElementById('addCourseModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeAddModal() {
            document.getElementById('addCourseModal').classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        function editCourse(course) {
            document.getElementById('edit_course_id').value = course.course_id;
            document.getElementById('edit_course_name').value = course.course_name;
            document.getElementById('edit_course_code').value = course.course_code;
            document.getElementById('edit_duration_years').value = course.duration_years;
            document.getElementById('editCourseModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeEditModal() {
            document.getElementById('editCourseModal').classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        window.onclick = function(event) {
            const addModal = document.getElementById('addCourseModal');
            const editModal = document.getElementById('editCourseModal');
            if (event.target == addModal) {
                closeAddModal();
            }
            if (event.target == editModal) {
                closeEditModal();
            }
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeAddModal();
                closeEditModal();
            }
        });
    </script>
</body>
</html>
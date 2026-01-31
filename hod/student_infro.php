<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('hod')) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

// Get HOD details and department
$sql = "SELECT t.*, d.department_name, d.department_id 
        FROM teachers t 
        JOIN departments d ON d.hod_id = t.user_id
        WHERE t.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$hod = $stmt->get_result()->fetch_assoc();

if (!$hod) {
    die("HOD profile not found or not assigned to any department.");
}

$dept_id = $hod['department_id'];

// Get all academic years
$academic_years_query = "SELECT DISTINCT academic_year FROM student_semesters WHERE academic_year IS NOT NULL ORDER BY academic_year DESC";
$academic_years = $conn->query($academic_years_query);

// Get all semesters
$semesters = $conn->query("SELECT * FROM semesters ORDER BY semester_number");

// Get all sections for this department
$sections_query = "SELECT DISTINCT sec.* FROM sections sec
                   JOIN student_semesters ss ON sec.section_id = ss.section_id
                   JOIN students s ON ss.student_id = s.student_id
                   WHERE s.department_id = $dept_id
                   ORDER BY sec.section_name";
$sections = $conn->query($sections_query);

// Build the WHERE conditions
$where_conditions = ["s.department_id = ?"];
$params = [$dept_id];
$types = "i";

// Academic Year Filter
if (isset($_GET['academic_year']) && !empty($_GET['academic_year'])) {
    $where_conditions[] = "ss.academic_year = ?";
    $params[] = $_GET['academic_year'];
    $types .= "s";
}

// Semester Filter
if (isset($_GET['semester_id']) && !empty($_GET['semester_id'])) {
    $where_conditions[] = "ss.semester_id = ?";
    $params[] = $_GET['semester_id'];
    $types .= "i";
}

// Section Filter
if (isset($_GET['section_id']) && !empty($_GET['section_id'])) {
    $where_conditions[] = "ss.section_id = ?";
    $params[] = $_GET['section_id'];
    $types .= "i";
}

// Search Filter
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $where_conditions[] = "(s.full_name LIKE ? OR s.admission_number LIKE ?)";
    $search_term = "%" . $_GET['search'] . "%";
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "ss";
}

$where_clause = implode(" AND ", $where_conditions);

// Main query
$sql = "SELECT DISTINCT s.*, 
        sem.semester_name, 
        sem.semester_id as current_semester_id,
        sec.section_name,
        sec.section_id as current_section_id,
        ss.academic_year,
        d.department_name,
        COALESCE(srn.roll_number_display, 'Not Assigned') as roll_number,
        u.email,
        u.phone
        FROM students s
        INNER JOIN student_semesters ss ON s.student_id = ss.student_id AND ss.is_active = 1
        LEFT JOIN semesters sem ON ss.semester_id = sem.semester_id
        LEFT JOIN sections sec ON ss.section_id = sec.section_id
        LEFT JOIN departments d ON s.department_id = d.department_id
        LEFT JOIN student_roll_numbers srn ON s.student_id = srn.student_id 
            AND srn.semester_id = ss.semester_id 
            AND srn.section_id = ss.section_id 
            AND srn.is_active = 1
        LEFT JOIN users u ON s.user_id = u.user_id
        WHERE $where_clause
        ORDER BY s.full_name ASC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$students = $stmt->get_result();

$total_students = $students->num_rows;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Information - College ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #ff6b35;
            --primary-dark: #e85d2a;
            --secondary: #f7931e;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --dark: #1a1a1a;
            --gray-dark: #2d3748;
            --gray: #64748b;
            --gray-light: #e2e8f0;
            --white: #ffffff;
            --bg-main: #f8fafc;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.08);
            --shadow-lg: 0 10px 40px rgba(0, 0, 0, 0.12);
            --shadow-xl: 0 20px 60px rgba(0, 0, 0, 0.15);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg-main);
            color: var(--dark);
            overflow-x: hidden;
        }

        /* Sidebar Styles */
        .sidebar {
            width: 280px;
            height: 100vh;
            background: var(--dark);
            position: fixed;
            left: 0;
            top: 0;
            overflow-y: auto;
            z-index: 999;
            transition: transform 0.3s ease;
        }

        .sidebar-header {
            padding: 32px 20px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
        }

        .hod-profile {
            text-align: center;
        }

        .hod-avatar {
            width: 80px;
            height: 80px;
            margin: 0 auto 16px;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .hod-avatar span {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.25);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 700;
        }

        .avatar-ring {
            position: absolute;
            width: 80px;
            height: 80px;
            border: 3px solid rgba(255, 255, 255, 0.4);
            border-radius: 50%;
            animation: pulse-ring 2s infinite;
        }

        @keyframes pulse-ring {
            0% { transform: scale(1); opacity: 1; }
            100% { transform: scale(1.3); opacity: 0; }
        }

        .hod-info {
            color: var(--white);
        }

        .hod-name {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .hod-dept {
            font-size: 14px;
            opacity: 0.95;
            margin-bottom: 8px;
        }

        .hod-role {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.2);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .sidebar-menu {
            padding: 24px 0;
        }

        .menu-item {
            display: flex;
            align-items: center;
            padding: 14px 20px;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            transition: all 0.3s;
            gap: 14px;
            position: relative;
        }

        .menu-item:hover,
        .menu-item.active {
            background: rgba(255, 107, 53, 0.1);
            color: var(--white);
        }

        .menu-item.active::before {
            content: '';
            position: absolute;
            left: 0;
            width: 4px;
            height: 60%;
            background: var(--primary);
            border-radius: 0 4px 4px 0;
        }

        .menu-icon {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-md);
            background: rgba(255, 255, 255, 0.05);
        }

        .menu-item.active .menu-icon {
            background: rgba(255, 107, 53, 0.2);
        }

        .menu-indicator {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--primary);
            opacity: 0;
        }

        .menu-item.active .menu-indicator {
            opacity: 1;
        }

        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .logout-link {
            display: flex;
            align-items: center;
            gap: 12px;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            padding: 12px 16px;
            border-radius: var(--radius-md);
            transition: all 0.3s;
        }

        .logout-link:hover {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
        }

        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 24px;
            min-height: 100vh;
        }

        /* Page Header */
        .page-header {
            background: linear-gradient(135deg, rgba(255, 107, 53, 0.1), rgba(247, 147, 30, 0.1));
            padding: 32px;
            border-radius: var(--radius-xl);
            margin-bottom: 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 400px;
            height: 400px;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><defs><linearGradient id="grad"><stop offset="0%" stop-color="%23ff6b35"/><stop offset="100%" stop-color="%23f7931e"/></linearGradient></defs><circle cx="100" cy="100" r="80" fill="none" stroke="url(%23grad)" stroke-width="2" opacity="0.1"/></svg>');
            opacity: 0.3;
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }

        .page-title {
            font-size: 36px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .page-subtitle {
            color: var(--gray);
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .student-count {
            background: var(--white);
            padding: 16px 24px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
            color: var(--primary);
        }

        .student-count i {
            font-size: 24px;
        }

        /* Filters Section */
        .filters-section {
            background: var(--white);
            padding: 28px;
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-md);
            margin-bottom: 28px;
        }

        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filter-group label {
            font-weight: 600;
            color: var(--gray-dark);
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-select,
        .filter-input {
            padding: 12px 16px;
            border: 2px solid var(--gray-light);
            border-radius: var(--radius-md);
            font-size: 14px;
            transition: all 0.3s;
            background: var(--white);
        }

        .filter-select:focus,
        .filter-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(255, 107, 53, 0.1);
        }

        .search-group {
            grid-column: span 2;
        }

        .filter-actions {
            display: flex;
            gap: 12px;
        }

        .btn-filter,
        .btn-reset {
            padding: 12px 24px;
            border-radius: var(--radius-md);
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
        }

        .btn-filter {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            border: none;
        }

        .btn-filter:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 107, 53, 0.4);
        }

        .btn-reset {
            background: var(--gray-light);
            color: var(--gray-dark);
            border: none;
        }

        .btn-reset:hover {
            background: var(--gray);
            color: var(--white);
        }

        /* Students Grid */
        .students-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
            margin-bottom: 40px;
        }

        /* Student Card */
        .student-card {
            position: relative;
            background: var(--white);
            border-radius: var(--radius-xl);
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            animation: fadeInUp 0.6s ease-out backwards;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .student-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-xl);
        }

        .card-background {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 120px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            opacity: 0.9;
        }

        .card-background::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 200px;
            height: 200px;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" fill="rgba(255,255,255,0.1)"/></svg>');
            animation: float 4s ease-in-out infinite;
        }

        .card-content {
            position: relative;
            padding: 24px;
        }

        .student-avatar-wrapper {
            position: relative;
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
        }

        .student-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.9), rgba(255, 255, 255, 0.7));
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 800;
            border: 4px solid var(--white);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
            position: relative;
            z-index: 2;
        }

        .avatar-pulse {
            position: absolute;
            top: 0;
            left: 0;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            border: 3px solid var(--primary);
            animation: pulse-ring 2s infinite;
        }

        .student-details {
            text-align: center;
            margin-top: 60px;
        }

        .student-name {
            font-size: 20px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 16px;
            font-family: 'Poppins', sans-serif;
        }

        .detail-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 0;
            border-bottom: 1px solid var(--gray-light);
            font-size: 14px;
            color: var(--gray-dark);
        }

        .detail-row:last-of-type {
            border-bottom: none;
        }

        .detail-row i {
            color: var(--primary);
            width: 20px;
            text-align: center;
        }

        .card-actions {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid var(--gray-light);
        }

        .btn-view {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            border: none;
            border-radius: var(--radius-md);
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s;
        }

        .btn-view:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 107, 53, 0.4);
        }

        /* No Results */
        .no-results {
            grid-column: 1 / -1;
            text-align: center;
            padding: 80px 20px;
            background: var(--white);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-md);
        }

        .no-results i {
            font-size: 80px;
            color: var(--gray-light);
            margin-bottom: 20px;
        }

        .no-results h3 {
            font-size: 24px;
            color: var(--gray-dark);
            margin-bottom: 8px;
        }

        .no-results p {
            color: var(--gray);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            animation: fadeIn 0.3s ease;
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background-color: var(--white);
            border-radius: var(--radius-xl);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-xl);
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 24px 28px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            border-radius: var(--radius-xl) var(--radius-xl) 0 0;
        }

        .modal-header h2 {
            font-size: 24px;
            font-weight: 700;
        }

        .modal-close {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: var(--white);
            font-size: 24px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 32px 28px;
        }

        .modal-student-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            font-weight: 800;
            margin: 0 auto 20px;
            box-shadow: 0 8px 24px rgba(255, 107, 53, 0.3);
        }

        .modal-student-name {
            text-align: center;
            font-size: 28px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 8px;
        }

        .modal-student-id {
            text-align: center;
            font-size: 16px;
            color: var(--gray);
            margin-bottom: 32px;
        }

        .modal-details-grid {
            display: grid;
            gap: 20px;
        }

        .modal-detail-item {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            padding: 16px;
            background: var(--bg-main);
            border-radius: var(--radius-md);
            transition: all 0.3s;
        }

        .modal-detail-item:hover {
            background: rgba(255, 107, 53, 0.05);
            transform: translateX(4px);
        }

        .modal-detail-icon {
            width: 40px;
            height: 40px;
            min-width: 40px;
            border-radius: var(--radius-md);
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .modal-detail-content {
            flex: 1;
        }

        .modal-detail-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .modal-detail-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
            word-break: break-word;
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
            width: 50px;
            height: 50px;
            border-radius: 50%;
            font-size: 20px;
            cursor: pointer;
            box-shadow: var(--shadow-lg);
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .students-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .mobile-menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .main-content {
                margin-left: 0;
                padding: 80px 16px 16px;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
                padding: 24px;
            }

            .page-title {
                font-size: 24px;
            }

            .student-count {
                width: 100%;
                justify-content: center;
            }

            .filters-form {
                grid-template-columns: 1fr;
            }

            .search-group {
                grid-column: auto;
            }

            .filter-actions {
                flex-direction: column;
            }

            .btn-filter,
            .btn-reset {
                width: 100%;
                justify-content: center;
            }

            .students-grid {
                grid-template-columns: 1fr;
            }

            .modal-content {
                width: 95%;
                margin: 10px;
            }

            .modal-body {
                padding: 24px 16px;
            }
        }

        @media (max-width: 480px) {
            .page-title {
                font-size: 20px;
            }

            .student-card {
                margin-bottom: 16px;
            }

            .modal-student-name {
                font-size: 22px;
            }

            .modal-detail-value {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="dashboard">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="hod-profile">
                    <div class="hod-avatar">
                        <span><?php echo strtoupper(substr($hod['full_name'], 0, 1)); ?></span>
                        <div class="avatar-ring"></div>
                    </div>
                    <div class="hod-info">
                        <div class="hod-name"><?php echo $hod['full_name']; ?></div>
                        <div class="hod-dept"><?php echo $hod['department_name']; ?></div>
                        <div class="hod-role">
                            <i class="fas fa-crown"></i> Head of Department
                        </div>
                    </div>
                </div>
            </div>
            
            <nav class="sidebar-menu">
                <a href="index.php" class="menu-item">
                    <div class="menu-icon"><i class="fas fa-home"></i></div>
                    <span class="menu-text">Dashboard</span>
                </a>
                <a href="student_infro.php" class="menu-item active">
                    <div class="menu-icon"><i class="fas fa-user-graduate"></i></div>
                    <span class="menu-text">Students Info</span>
                    <div class="menu-indicator"></div>
                </a>
                <a href="manage_student_semesters.php" class="menu-item">
                    <div class="menu-icon"><i class="fas fa-users"></i></div>
                    <span class="menu-text">Manage Students</span>
                </a>
                <a href="attandancereview.php" class="menu-item">
                    <div class="menu-icon"><i class="fas fa-clipboard-check"></i></div>
                    <span class="menu-text">Attendance</span>
                </a>
            </nav>

            <div class="sidebar-footer">
                <a href="../logout.php" class="logout-link">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </aside>

        <main class="main-content">
            <div class="page-header">
                <div class="header-content">
                    <h1 class="page-title">
                        <i class="fas fa-users-class"></i>
                        Student Information
                    </h1>
                    <p class="page-subtitle">
                        <i class="fas fa-building"></i>
                        <?php echo $hod['department_name']; ?> Department
                    </p>
                </div>
                <div class="header-actions">
                    <div class="student-count">
                        <i class="fas fa-user-graduate"></i>
                        <span><?php echo $total_students; ?> Students</span>
                    </div>
                </div>
            </div>

            <div class="filters-section">
                <form method="GET" action="student_infro.php" class="filters-form" id="filtersForm">
                    <div class="filter-group">
                        <label for="academic_year">
                            <i class="fas fa-calendar-alt"></i>
                            Academic Year
                        </label>
                        <select name="academic_year" id="academic_year" class="filter-select">
                            <option value="">All Years</option>
                            <?php 
                            $academic_years->data_seek(0);
                            while($year = $academic_years->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $year['academic_year']; ?>" 
                                    <?php echo (isset($_GET['academic_year']) && $_GET['academic_year'] == $year['academic_year']) ? 'selected' : ''; ?>>
                                    <?php echo $year['academic_year']; ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="semester_id">
                            <i class="fas fa-book"></i>
                            Semester
                        </label>
                        <select name="semester_id" id="semester_id" class="filter-select">
                            <option value="">All Semesters</option>
                            <?php 
                            $semesters->data_seek(0);
                            while($sem = $semesters->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $sem['semester_id']; ?>"
                                    <?php echo (isset($_GET['semester_id']) && $_GET['semester_id'] == $sem['semester_id']) ? 'selected' : ''; ?>>
                                    <?php echo $sem['semester_name']; ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="section_id">
                            <i class="fas fa-layer-group"></i>
                            Section
                        </label>
                        <select name="section_id" id="section_id" class="filter-select">
                            <option value="">All Sections</option>
                            <?php 
                            $sections->data_seek(0);
                            while($sec = $sections->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $sec['section_id']; ?>"
                                    <?php echo (isset($_GET['section_id']) && $_GET['section_id'] == $sec['section_id']) ? 'selected' : ''; ?>>
                                    <?php echo $sec['section_name']; ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="filter-group search-group">
                        <label for="search">
                            <i class="fas fa-search"></i>
                            Search
                        </label>
                        <input type="text" name="search" id="search" class="filter-input" 
                            placeholder="Name or Admission No..." 
                            value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn-filter">
                            <i class="fas fa-filter"></i>
                            Apply Filters
                        </button>
                        <a href="student_infro.php" class="btn-reset">
                            <i class="fas fa-redo"></i>
                            Reset
                        </a>
                    </div>
                </form>
            </div>

            <div class="students-grid">
                <?php if ($students->num_rows > 0): ?>
                    <?php while($student = $students->fetch_assoc()): ?>
                        <div class="student-card" data-student-id="<?php echo $student['student_id']; ?>">
                            <div class="card-background"></div>
                            <div class="card-content">
                                <div class="student-avatar-wrapper">
                                    <div class="student-avatar">
                                        <?php echo strtoupper(substr($student['full_name'], 0, 1)); ?>
                                    </div>
                                    <div class="avatar-pulse"></div>
                                </div>
                                
                                <div class="student-details">
                                    <h3 class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></h3>
                                    
                                    <div class="detail-row">
                                        <i class="fas fa-id-card"></i>
                                        <span><?php echo htmlspecialchars($student['admission_number']); ?></span>
                                    </div>
                                    
                                    
                                    <div class="detail-row">
                                        <i class="fas fa-building"></i>
                                        <span><?php echo htmlspecialchars($student['department_name']); ?></span>
                                    </div>
                                    
                                    <div class="detail-row">
                                        <i class="fas fa-book-open"></i>
                                        <span><?php echo htmlspecialchars($student['semester_name'] ?? 'Not Assigned'); ?></span>
                                    </div>
                                    
                                    <div class="detail-row">
                                        <i class="fas fa-users"></i>
                                        <span>Section: <?php echo htmlspecialchars($student['section_name'] ?? 'Not Assigned'); ?></span>
                                    </div>
                                    
                                    <div class="detail-row">
                                        <i class="fas fa-calendar"></i>
                                        <span><?php echo htmlspecialchars($student['academic_year'] ?? 'N/A'); ?></span>
                                    </div>
                                </div>

                                <div class="card-actions">
                                    <button class="btn-view" 
                                        data-student-id="<?php echo $student['student_id']; ?>"
                                        data-name="<?php echo htmlspecialchars($student['full_name'], ENT_QUOTES); ?>"
                                        data-admission="<?php echo htmlspecialchars($student['admission_number'], ENT_QUOTES); ?>"
                                        data-email="<?php echo htmlspecialchars($student['email'] ?? 'N/A', ENT_QUOTES); ?>"
                                        data-phone="<?php echo htmlspecialchars($student['phone'] ?? 'N/A', ENT_QUOTES); ?>"
                                        data-department="<?php echo htmlspecialchars($student['department_name'], ENT_QUOTES); ?>"
                                        data-semester="<?php echo htmlspecialchars($student['semester_name'] ?? 'N/A', ENT_QUOTES); ?>"
                                        data-section="<?php echo htmlspecialchars($student['section_name'] ?? 'N/A', ENT_QUOTES); ?>"
                                        data-roll="<?php echo htmlspecialchars($student['roll_number'], ENT_QUOTES); ?>"
                                        data-dob="<?php echo htmlspecialchars($student['date_of_birth'] ?? 'N/A', ENT_QUOTES); ?>"
                                        data-address="<?php echo htmlspecialchars($student['address'] ?? 'N/A', ENT_QUOTES); ?>">
                                        <i class="fas fa-eye"></i>
                                        View Details
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="no-results">
                        <i class="fas fa-users-slash"></i>
                        <h3>No Students Found</h3>
                        <p>Try adjusting your filters or search criteria</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Student Details Modal -->
    <div id="studentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Student Details</h2>
                <button class="modal-close" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="modalBody">
                <!-- Content will be populated by JavaScript -->
            </div>
        </div>
    </div>

    <script>
        // Toggle Sidebar for Mobile
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('active');
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggleBtn = document.querySelector('.mobile-menu-toggle');
            
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !toggleBtn.contains(event.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });

        // Initialize filters
        document.addEventListener('DOMContentLoaded', function() {
            initializeFilters();
            initializeViewButtons();
        });

        function initializeFilters() {
            const form = document.getElementById('filtersForm');
            const selects = form.querySelectorAll('select');
            
            selects.forEach(select => {
                select.addEventListener('change', function() {
                    console.log('Filter changed:', this.name, '=', this.value);
                    form.submit();
                });
            });
        }

        // Initialize View Buttons
        function initializeViewButtons() {
            const viewButtons = document.querySelectorAll('.btn-view');
            
            viewButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    const studentData = {
                        id: this.dataset.studentId,
                        name: this.dataset.name,
                        admission: this.dataset.admission,
                        email: this.dataset.email,
                        phone: this.dataset.phone,
                        department: this.dataset.department,
                        semester: this.dataset.semester,
                        section: this.dataset.section,
                        roll: this.dataset.roll,
                        dob: this.dataset.dob,
                        address: this.dataset.address
                    };
                    
                    viewStudent(studentData);
                });
            });
        }

        // View Student Details
        function viewStudent(data) {
            console.log('Viewing student:', data);
            
            const modal = document.getElementById('studentModal');
            const modalBody = document.getElementById('modalBody');
            const modalTitle = document.getElementById('modalTitle');
            
            modalTitle.textContent = 'Student Details';
            
            const modalContent = `
                <div class="modal-student-avatar">
                    ${data.name.charAt(0).toUpperCase()}
                </div>
                <h3 class="modal-student-name">${data.name}</h3>
                <p class="modal-student-id">${data.admission}</p>
                
                <div class="modal-details-grid">
                    <div class="modal-detail-item">
                        <div class="modal-detail-icon">
                            <i class="fas fa-id-card"></i>
                        </div>
                        <div class="modal-detail-content">
                            <div class="modal-detail-label">Admission Number</div>
                            <div class="modal-detail-value">${data.admission}</div>
                        </div>
                    </div>
                    
                    <div class="modal-detail-item">
                        <div class="modal-detail-icon">
                            <i class="fas fa-hashtag"></i>
                        </div>
                        <div class="modal-detail-content">
                            <div class="modal-detail-label">Roll Number</div>
                            <div class="modal-detail-value">${data.roll}</div>
                        </div>
                    </div>
                    
                    <div class="modal-detail-item">
                        <div class="modal-detail-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <div class="modal-detail-content">
                            <div class="modal-detail-label">Email</div>
                            <div class="modal-detail-value">${data.email}</div>
                        </div>
                    </div>
                    
                    <div class="modal-detail-item">
                        <div class="modal-detail-icon">
                            <i class="fas fa-phone"></i>
                        </div>
                        <div class="modal-detail-content">
                            <div class="modal-detail-label">Phone</div>
                            <div class="modal-detail-value">${data.phone}</div>
                        </div>
                    </div>
                    
                    <div class="modal-detail-item">
                        <div class="modal-detail-icon">
                            <i class="fas fa-building"></i>
                        </div>
                        <div class="modal-detail-content">
                            <div class="modal-detail-label">Department</div>
                            <div class="modal-detail-value">${data.department}</div>
                        </div>
                    </div>
                    
                    <div class="modal-detail-item">
                        <div class="modal-detail-icon">
                            <i class="fas fa-book-open"></i>
                        </div>
                        <div class="modal-detail-content">
                            <div class="modal-detail-label">Semester</div>
                            <div class="modal-detail-value">${data.semester}</div>
                        </div>
                    </div>
                    
                    <div class="modal-detail-item">
                        <div class="modal-detail-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="modal-detail-content">
                            <div class="modal-detail-label">Section</div>
                            <div class="modal-detail-value">${data.section}</div>
                        </div>
                    </div>
                    
                    <div class="modal-detail-item">
                        <div class="modal-detail-icon">
                            <i class="fas fa-birthday-cake"></i>
                        </div>
                        <div class="modal-detail-content">
                            <div class="modal-detail-label">Date of Birth</div>
                            <div class="modal-detail-value">${data.dob}</div>
                        </div>
                    </div>
                    
                    <div class="modal-detail-item">
                        <div class="modal-detail-icon">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <div class="modal-detail-content">
                            <div class="modal-detail-label">Address</div>
                            <div class="modal-detail-value">${data.address}</div>
                        </div>
                    </div>
                </div>
            `;
            
            modalBody.innerHTML = modalContent;
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        // Close Modal
        function closeModal() {
            const modal = document.getElementById('studentModal');
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }

        // Close modal on outside click
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('studentModal');
            if (event.target === modal) {
                closeModal();
            }
        });

        // Close modal on ESC key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
            }
        });
    </script>
</body>
</html>
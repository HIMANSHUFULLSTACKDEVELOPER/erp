<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('hod')) {
    redirect('login.php');
}

// Get filter parameters
$selected_department = isset($_GET['department']) ? $_GET['department'] : '';
$selected_semester = isset($_GET['semester']) ? $_GET['semester'] : '';
$selected_section = isset($_GET['section']) ? $_GET['section'] : '';
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Get all departments for filter
$departments = $conn->query("SELECT department_id, department_name FROM departments ORDER BY department_name");

// Get all semesters
$semesters = $conn->query("SELECT semester_id, semester_name FROM semesters ORDER BY semester_number");

// Get sections based on selected department (or all)
if ($selected_department) {
    $sections = $conn->query("SELECT DISTINCT s.section_id, s.section_name 
                             FROM sections s 
                             JOIN student_semesters ss ON s.section_id = ss.section_id 
                             JOIN students st ON ss.student_id = st.student_id 
                             WHERE st.department_id = $selected_department
                             ORDER BY s.section_name");
} else {
    $sections = $conn->query("SELECT section_id, section_name FROM sections ORDER BY section_name");
}

// Build the query to get teachers' attendance status
$query = "SELECT DISTINCT
    t.teacher_id,
    t.full_name as teacher_name,
    u.email,
    d.department_name,
    
    -- Count total classes assigned to teacher
    (SELECT COUNT(DISTINCT st.subject_id) 
     FROM subject_teachers st 
     WHERE st.teacher_id = t.teacher_id";

if ($selected_semester) {
    $query .= " AND st.semester_id = $selected_semester";
}
if ($selected_section) {
    $query .= " AND st.section_id = $selected_section";
}

$query .= ") as total_classes,
    
    -- Count classes marked (attendance taken)
    (SELECT COUNT(DISTINCT a.subject_id)
     FROM attendance a
     WHERE a.marked_by = t.teacher_id
     AND DATE(a.attendance_date) = '$selected_date'";

if ($selected_department) {
    $query .= " AND a.department_id = $selected_department";
}
if ($selected_semester) {
    $query .= " AND a.semester_id = $selected_semester";
}
if ($selected_section) {
    $query .= " AND a.section_id = $selected_section";
}

$query .= ") as marked_classes,
    
    -- Count pending classes
    (SELECT COUNT(DISTINCT st.subject_id)
     FROM subject_teachers st
     WHERE st.teacher_id = t.teacher_id
     AND st.subject_id NOT IN (
         SELECT a.subject_id 
         FROM attendance a 
         WHERE a.marked_by = t.teacher_id 
         AND DATE(a.attendance_date) = '$selected_date'";

if ($selected_department) {
    $query .= " AND a.department_id = $selected_department";
}
if ($selected_semester) {
    $query .= " AND a.semester_id = $selected_semester";
}
if ($selected_section) {
    $query .= " AND a.section_id = $selected_section";
}

$query .= ")";

if ($selected_semester) {
    $query .= " AND st.semester_id = $selected_semester";
}
if ($selected_section) {
    $query .= " AND st.section_id = $selected_section";
}

$query .= ") as pending_classes,
    
    -- Get subjects assigned to teacher
    (SELECT GROUP_CONCAT(DISTINCT sub.subject_code ORDER BY sub.subject_code SEPARATOR ', ')
     FROM subject_teachers st
     JOIN subjects sub ON st.subject_id = sub.subject_id
     WHERE st.teacher_id = t.teacher_id";

if ($selected_semester) {
    $query .= " AND st.semester_id = $selected_semester";
}
if ($selected_section) {
    $query .= " AND st.section_id = $selected_section";
}

$query .= ") as subjects

FROM teachers t
JOIN users u ON t.user_id = u.user_id
JOIN departments d ON t.department_id = d.department_id
WHERE 1=1";

if ($selected_department) {
    $query .= " AND t.department_id = $selected_department";
}

if ($search) {
    $search_escaped = $conn->real_escape_string($search);
    $query .= " AND (t.full_name LIKE '%$search_escaped%' OR u.email LIKE '%$search_escaped%')";
}

$query .= " ORDER BY t.full_name";

$teachers = $conn->query($query);

// Calculate statistics
$total_teachers = 0;
$teachers_marked = 0;
$teachers_pending = 0;

$temp_result = $conn->query($query);
while ($row = $temp_result->fetch_assoc()) {
    $total_teachers++;
    if ($row['marked_classes'] > 0 && $row['marked_classes'] == $row['total_classes']) {
        $teachers_marked++;
    } else if ($row['pending_classes'] > 0) {
        $teachers_pending++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Attendance Status - College ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --primary-light: #818cf8;
            --secondary: #8b5cf6;
            --success: #10b981;
            --success-dark: #059669;
            --warning: #f59e0b;
            --warning-dark: #d97706;
            --danger: #ef4444;
            --dark: #1f2937;
            --dark-light: #374151;
            --gray: #6b7280;
            --light-gray: #f3f4f6;
            --border: #e5e7eb;
            --white: #ffffff;
            --sidebar-width: 280px;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            background-attachment: fixed;
            color: var(--dark);
            line-height: 1.6;
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

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.9);
            }
            to {
                opacity: 1;
                transform: scale(1);
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

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--dark) 0%, #111827 100%);
            color: var(--white);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: var(--shadow-xl);
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .sidebar::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 3px;
        }

        .sidebar::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .sidebar-header {
            padding: 30px 25px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(99, 102, 241, 0.1);
            animation: slideInLeft 0.5s ease-out;
        }

        .sidebar-header h2 {
            font-size: 1.6rem;
            font-weight: 700;
            background: linear-gradient(135deg, #fff 0%, #a5b4fc 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 5px;
        }

        .sidebar-header p {
            font-size: 0.85rem;
            opacity: 0.7;
            color: var(--white);
        }

        .sidebar-menu {
            padding: 20px 0;
        }

        .menu-item {
            padding: 16px 25px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            display: flex;
            align-items: center;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            position: relative;
            overflow: hidden;
            margin: 4px 12px;
            border-radius: 12px;
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
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .menu-item:hover {
            background: rgba(99, 102, 241, 0.15);
            color: var(--white);
            transform: translateX(5px);
        }

        .menu-item:hover::before {
            transform: scaleY(1);
        }

        .menu-item i {
            margin-right: 15px;
            width: 22px;
            text-align: center;
            font-size: 1.1rem;
            transition: transform 0.3s ease;
        }

        .menu-item:hover i {
            transform: scale(1.2) rotate(5deg);
        }

        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: var(--white);
            border: none;
            width: 50px;
            height: 50px;
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .mobile-menu-toggle:hover {
            transform: scale(1.05);
            box-shadow: var(--shadow-xl);
        }

        .mobile-menu-toggle i {
            font-size: 1.3rem;
            color: var(--primary);
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            backdrop-filter: blur(4px);
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 30px;
            animation: fadeIn 0.6s ease-out;
            transition: margin-left 0.3s ease;
        }

        .top-bar {
            background: var(--white);
            padding: 25px 30px;
            border-radius: 16px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow-lg);
            animation: slideInRight 0.5s ease-out;
            transition: all 0.3s ease;
        }

        .top-bar:hover {
            box-shadow: var(--shadow-xl);
            transform: translateY(-2px);
        }

        .top-bar h1 {
            font-size: 1.9rem;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .top-bar h1 i {
            color: var(--primary);
            animation: pulse 2s infinite;
        }

        .back-btn {
            background: linear-gradient(135deg, var(--gray) 0%, var(--dark) 100%);
            color: var(--white);
            border: none;
            padding: 12px 24px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: var(--shadow);
        }

        .back-btn:hover {
            transform: translateX(-5px) scale(1.02);
            box-shadow: var(--shadow-lg);
            background: linear-gradient(135deg, var(--dark) 0%, #000 100%);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--white);
            padding: 30px;
            border-radius: 16px;
            box-shadow: var(--shadow-md);
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            animation: scaleIn 0.5s ease-out backwards;
        }

        .stat-card:nth-child(1) { animation-delay: 0.1s; }
        .stat-card:nth-child(2) { animation-delay: 0.2s; }
        .stat-card:nth-child(3) { animation-delay: 0.3s; }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s;
        }

        .stat-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: var(--shadow-xl);
        }

        .stat-card:hover::before {
            left: 100%;
        }

        .stat-info h3 {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 8px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .stat-info p {
            color: var(--gray);
            font-size: 0.95rem;
            font-weight: 500;
        }

        .stat-icon {
            width: 70px;
            height: 70px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            transition: all 0.3s ease;
        }

        .stat-card:hover .stat-icon {
            transform: rotate(10deg) scale(1.1);
        }

        .stat-icon.blue { 
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(99, 102, 241, 0.2) 100%);
            color: var(--primary);
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.2);
        }
        .stat-icon.green { 
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(16, 185, 129, 0.2) 100%);
            color: var(--success);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.2);
        }
        .stat-icon.orange { 
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, rgba(245, 158, 11, 0.2) 100%);
            color: var(--warning);
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.2);
        }

        /* Filters */
        .filters-card {
            background: var(--white);
            padding: 30px;
            border-radius: 16px;
            box-shadow: var(--shadow-md);
            margin-bottom: 25px;
            animation: fadeIn 0.6s ease-out 0.3s backwards;
            transition: all 0.3s ease;
        }

        .filters-card:hover {
            box-shadow: var(--shadow-lg);
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-group select,
        .form-group input {
            padding: 12px 16px;
            border: 2px solid var(--border);
            border-radius: 10px;
            font-size: 0.95rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: var(--white);
        }

        .form-group select:hover,
        .form-group input:hover {
            border-color: var(--primary-light);
        }

        .form-group select:focus,
        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
            transform: translateY(-2px);
        }

        .filter-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: var(--shadow);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: var(--white);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
        }

        .btn-secondary {
            background: linear-gradient(135deg, var(--gray) 0%, var(--dark-light) 100%);
            color: var(--white);
        }

        .btn-secondary:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
            background: linear-gradient(135deg, var(--dark-light) 0%, var(--dark) 100%);
        }

        .btn:active {
            transform: translateY(-1px);
        }

        /* Table */
        .table-card {
            background: var(--white);
            border-radius: 16px;
            box-shadow: var(--shadow-md);
            overflow: hidden;
            animation: fadeIn 0.6s ease-out 0.4s backwards;
            transition: all 0.3s ease;
        }

        .table-card:hover {
            box-shadow: var(--shadow-xl);
        }

        .table-header {
            padding: 25px 30px;
            border-bottom: 2px solid var(--light-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            background: linear-gradient(135deg, #f8f9ff 0%, #ffffff 100%);
        }

        .table-header h3 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--dark);
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            padding: 12px 18px 12px 48px;
            border: 2px solid var(--border);
            border-radius: 10px;
            width: 320px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
            width: 360px;
        }

        .search-box i {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }

        .search-box input:focus + i {
            color: var(--primary);
        }

        .table-container {
            overflow-x: auto;
        }

        .table-container::-webkit-scrollbar {
            height: 8px;
        }

        .table-container::-webkit-scrollbar-track {
            background: var(--light-gray);
        }

        .table-container::-webkit-scrollbar-thumb {
            background: var(--gray);
            border-radius: 4px;
        }

        .table-container::-webkit-scrollbar-thumb:hover {
            background: var(--dark);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: linear-gradient(135deg, #f8f9ff 0%, #f3f4f6 100%);
        }

        th {
            text-align: left;
            padding: 18px 22px;
            font-weight: 700;
            font-size: 0.8rem;
            text-transform: uppercase;
            color: var(--gray);
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--border);
        }

        td {
            padding: 20px 22px;
            border-bottom: 1px solid var(--light-gray);
            transition: all 0.3s ease;
        }

        tbody tr {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        tbody tr:hover {
            background: linear-gradient(90deg, rgba(99, 102, 241, 0.03) 0%, transparent 100%);
            transform: scale(1.01);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05);
        }

        .teacher-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .teacher-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
            transition: all 0.3s ease;
        }

        tbody tr:hover .teacher-avatar {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 6px 16px rgba(99, 102, 241, 0.4);
        }

        .teacher-details h4 {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 4px;
            color: var(--dark);
        }

        .teacher-details p {
            font-size: 0.85rem;
            color: var(--gray);
        }

        .badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
        }

        .badge:hover {
            transform: scale(1.05);
        }

        .badge-success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(16, 185, 129, 0.15) 100%);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .badge-warning {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, rgba(245, 158, 11, 0.15) 100%);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .badge-danger {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(239, 68, 68, 0.15) 100%);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .badge-info {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(99, 102, 241, 0.15) 100%);
            color: var(--primary);
            border: 1px solid rgba(99, 102, 241, 0.2);
        }

        .count-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 45px;
            height: 45px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 1.1rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .count-badge:hover {
            transform: scale(1.15) rotate(5deg);
        }

        .count-success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(16, 185, 129, 0.2) 100%);
            color: var(--success);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15);
        }

        .count-warning {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, rgba(245, 158, 11, 0.2) 100%);
            color: var(--warning);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.15);
        }

        .count-neutral {
            background: linear-gradient(135deg, var(--light-gray) 0%, #e5e7eb 100%);
            color: var(--dark);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .action-btn {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: var(--white);
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }

        .action-btn:hover {
            transform: translateY(-4px) scale(1.1);
            box-shadow: 0 8px 20px rgba(99, 102, 241, 0.4);
            background: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%);
        }

        .action-btn:active {
            transform: translateY(-2px) scale(1.05);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state i {
            font-size: 4rem;
            color: var(--gray);
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state p {
            font-size: 1.1rem;
            color: var(--gray);
            font-weight: 500;
        }

        /* Loading Animation */
        .loading {
            display: none;
            text-align: center;
            padding: 40px;
        }

        .loading-spinner {
            border: 4px solid var(--light-gray);
            border-top: 4px solid var(--primary);
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 1024px) {
            .filters-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            :root {
                --sidebar-width: 280px;
            }

            .mobile-menu-toggle {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .sidebar-overlay.active {
                display: block;
            }

            .main-content {
                margin-left: 0;
                padding: 20px 15px;
                padding-top: 80px;
            }

            .top-bar {
                flex-direction: column;
                gap: 15px;
                text-align: center;
                padding: 20px;
            }

            .top-bar h1 {
                font-size: 1.5rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .stat-card {
                padding: 25px 20px;
            }

            .stat-info h3 {
                font-size: 2rem;
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .filter-actions {
                justify-content: stretch;
            }

            .filter-actions .btn {
                flex: 1;
                justify-content: center;
            }

            .table-header {
                flex-direction: column;
                gap: 15px;
            }

            .search-box input {
                width: 100%;
            }

            .search-box input:focus {
                width: 100%;
            }

            table {
                font-size: 0.85rem;
            }

            th, td {
                padding: 12px 15px;
            }

            .teacher-avatar {
                width: 40px;
                height: 40px;
                font-size: 0.9rem;
            }

            .count-badge {
                min-width: 38px;
                height: 38px;
                font-size: 0.95rem;
            }

            .action-btn {
                width: 38px;
                height: 38px;
            }
        }

        @media (max-width: 480px) {
            .top-bar h1 {
                font-size: 1.3rem;
            }

            .stat-icon {
                width: 55px;
                height: 55px;
                font-size: 1.5rem;
            }

            .teacher-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .badge {
                padding: 6px 12px;
                font-size: 0.7rem;
            }
        }

        /* Print Styles */
        @media print {
            .sidebar,
            .mobile-menu-toggle,
            .back-btn,
            .filter-actions,
            .search-box,
            .action-btn {
                display: none !important;
            }

            .main-content {
                margin-left: 0;
            }

            .stat-card,
            .filters-card,
            .table-card {
                box-shadow: none;
                border: 1px solid var(--border);
            }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <!-- Mobile Menu Toggle -->
        <button class="mobile-menu-toggle" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Sidebar Overlay -->
        <div class="sidebar-overlay" onclick="toggleSidebar()"></div>

        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>Admin Panel</h2>
                <p>College ERP System</p>
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
                <h1><i class="fas fa-clipboard-check"></i> All Teachers Status</h1>
                <a href="index.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-info">
                        <h3><?php echo $total_teachers; ?></h3>
                        <p>All Teachers</p>
                    </div>
                    <div class="stat-icon blue">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3><?php echo $teachers_marked; ?></h3>
                        <p>Marked</p>
                    </div>
                    <div class="stat-icon green">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3><?php echo $teachers_pending; ?></h3>
                        <p>Pending</p>
                    </div>
                    <div class="stat-icon orange">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <form method="GET" action="">
                    <div class="filters-grid">
                        <div class="form-group">
                            <label>Department</label>
                            <select name="department" id="department">
                                <option value="">All Departments</option>
                                <?php while($dept = $departments->fetch_assoc()): ?>
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
                                <?php while($sem = $semesters->fetch_assoc()): ?>
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
                                <?php while($sec = $sections->fetch_assoc()): ?>
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
                        <a href="admin_class_attendance_report.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Teachers Table -->
            <div class="table-card">
                <div class="table-header">
                    <h3>Complete List with Attendance Status</h3>
                    <div class="search-box">
                        <input type="text" id="searchInput" placeholder="Search teachers..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                        <i class="fas fa-search"></i>
                    </div>
                </div>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>TEACHER</th>
                                <th>DEPARTMENT</th>
                                <th>CLASSES</th>
                                <th>MARKED</th>
                                <th>PENDING</th>
                                <th>SUBJECTS</th>
                                <th>STATUS</th>
                                <th>ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody id="teacherTableBody">
                            <?php if($teachers->num_rows > 0): ?>
                                <?php while($teacher = $teachers->fetch_assoc()): 
                                    $initials = strtoupper(substr($teacher['teacher_name'], 0, 2));
                                    $status = 'PENDING';
                                    $status_class = 'badge-warning';
                                    
                                    if($teacher['total_classes'] == 0) {
                                        $status = 'NO CLASSES';
                                        $status_class = 'badge-info';
                                    } else if($teacher['marked_classes'] == $teacher['total_classes']) {
                                        $status = 'COMPLETED';
                                        $status_class = 'badge-success';
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <div class="teacher-info">
                                            <div class="teacher-avatar"><?php echo $initials; ?></div>
                                            <div class="teacher-details">
                                                <h4><?php echo $teacher['teacher_name']; ?></h4>
                                                <p><?php echo $teacher['email']; ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?php echo $teacher['department_name']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="count-badge count-neutral">
                                            <?php echo $teacher['total_classes']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="count-badge count-success">
                                            <?php echo $teacher['marked_classes']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="count-badge count-warning">
                                            <?php echo $teacher['pending_classes']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small><?php echo $teacher['subjects'] ?: 'No subjects assigned'; ?></small>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $status_class; ?>">
                                            <?php echo $status; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="action-btn" 
                                                onclick="viewDetails(<?php echo $teacher['teacher_id']; ?>)"
                                                title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8">
                                        <div class="empty-state">
                                            <i class="fas fa-inbox"></i>
                                            <p>No teachers found</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Loading State -->
                <div class="loading" id="loadingState">
                    <div class="loading-spinner"></div>
                    <p>Loading data...</p>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Mobile Sidebar Toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
        }

        // Close sidebar when clicking on menu items on mobile
        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', () => {
                if (window.innerWidth <= 768) {
                    toggleSidebar();
                }
            });
        });

        // Search functionality with debounce
        let searchTimeout;
        document.getElementById('searchInput').addEventListener('input', function(e) {
            clearTimeout(searchTimeout);
            
            searchTimeout = setTimeout(() => {
                const searchValue = e.target.value.toLowerCase();
                const rows = document.querySelectorAll('#teacherTableBody tr');
                let visibleCount = 0;
                
                rows.forEach(row => {
                    const teacherName = row.querySelector('.teacher-details h4')?.textContent.toLowerCase() || '';
                    const teacherEmail = row.querySelector('.teacher-details p')?.textContent.toLowerCase() || '';
                    
                    if (teacherName.includes(searchValue) || teacherEmail.includes(searchValue)) {
                        row.style.display = '';
                        visibleCount++;
                        // Fade in animation
                        row.style.animation = 'fadeIn 0.3s ease-out';
                    } else {
                        row.style.display = 'none';
                    }
                });

                // Show empty state if no results
                const emptyState = document.querySelector('.empty-state');
                if (visibleCount === 0 && !emptyState) {
                    const tbody = document.getElementById('teacherTableBody');
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="8">
                                <div class="empty-state">
                                    <i class="fas fa-search"></i>
                                    <p>No teachers match your search</p>
                                </div>
                            </td>
                        </tr>
                    `;
                }
            }, 300);
        });

        // View details function
        function viewDetails(teacherId) {
            const date = '<?php echo $selected_date; ?>';
            const department = '<?php echo $selected_department; ?>';
            const semester = '<?php echo $selected_semester; ?>';
            const section = '<?php echo $selected_section; ?>';
            
            let url = `teacher_attendance_details.php?teacher_id=${teacherId}&date=${date}`;
            if (department) url += `&department=${department}`;
            if (semester) url += `&semester=${semester}`;
            if (section) url += `&section=${section}`;
            
            window.location.href = url;
        }

        // Auto-submit form when date changes
        document.querySelector('input[name="date"]').addEventListener('change', function() {
            showLoading();
            this.form.submit();
        });

        // Show loading state
        function showLoading() {
            document.getElementById('loadingState').style.display = 'block';
            document.querySelector('.table-container').style.opacity = '0.5';
        }

        // Smooth scroll to top
        window.addEventListener('scroll', function() {
            const scrollBtn = document.querySelector('.scroll-to-top');
            if (window.pageYOffset > 300) {
                if (!scrollBtn) {
                    const btn = document.createElement('button');
                    btn.className = 'scroll-to-top';
                    btn.innerHTML = '<i class="fas fa-arrow-up"></i>';
                    btn.onclick = () => window.scrollTo({ top: 0, behavior: 'smooth' });
                    document.body.appendChild(btn);
                }
            } else {
                if (scrollBtn) {
                    scrollBtn.remove();
                }
            }
        });

        // Add scroll to top button styles
        const style = document.createElement('style');
        style.textContent = `
            .scroll-to-top {
                position: fixed;
                bottom: 30px;
                right: 30px;
                width: 50px;
                height: 50px;
                border-radius: 50%;
                background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
                color: white;
                border: none;
                cursor: pointer;
                box-shadow: var(--shadow-lg);
                z-index: 999;
                transition: all 0.3s ease;
                animation: fadeIn 0.3s ease-out;
            }
            .scroll-to-top:hover {
                transform: translateY(-5px);
                box-shadow: var(--shadow-xl);
            }
        `;
        document.head.appendChild(style);

        // Animate stats on page load
        document.addEventListener('DOMContentLoaded', function() {
            const statNumbers = document.querySelectorAll('.stat-info h3');
            statNumbers.forEach((stat, index) => {
                const finalValue = parseInt(stat.textContent);
                let currentValue = 0;
                const increment = finalValue / 50;
                const timer = setInterval(() => {
                    currentValue += increment;
                    if (currentValue >= finalValue) {
                        stat.textContent = finalValue;
                        clearInterval(timer);
                    } else {
                        stat.textContent = Math.floor(currentValue);
                    }
                }, 20 + (index * 10));
            });
        });

        // Handle window resize
        let resizeTimeout;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                if (window.innerWidth > 768) {
                    document.getElementById('sidebar').classList.remove('active');
                    document.querySelector('.sidebar-overlay').classList.remove('active');
                }
            }, 250);
        });
    </script>
</body>
</html>
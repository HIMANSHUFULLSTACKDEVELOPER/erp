<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('parent')) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

// Get parent details
$sql = "SELECT p.* FROM parents p WHERE p.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$parent = $stmt->get_result()->fetch_assoc();

// Get linked students with current semester info
$students_query = "SELECT s.*, d.department_name, c.course_name,
                   sem.semester_name, sec.section_name,
                   ss.academic_year
                   FROM parent_student ps
                   JOIN students s ON ps.student_id = s.student_id
                   JOIN departments d ON s.department_id = d.department_id
                   JOIN courses c ON s.course_id = c.course_id
                   LEFT JOIN student_semesters ss ON s.student_id = ss.student_id AND ss.is_active = 1
                   LEFT JOIN semesters sem ON ss.semester_id = sem.semester_id
                   LEFT JOIN sections sec ON ss.section_id = sec.section_id
                   WHERE ps.parent_id = {$parent['parent_id']}";
$students = $conn->query($students_query);

// Get recent attendance activities for all children
$recent_activities = [];
if ($students->num_rows > 0) {
    $student_ids = [];
    $students->data_seek(0);
    while($s = $students->fetch_assoc()) {
        $student_ids[] = $s['student_id'];
    }
    $ids = implode(',', $student_ids);
    
    $recent_activities = $conn->query("SELECT 
        a.attendance_date,
        a.status,
        s.full_name as student_name,
        sub.subject_name,
        sub.subject_code,
        t.full_name as teacher_name
        FROM attendance a
        JOIN students s ON a.student_id = s.student_id
        JOIN subjects sub ON a.subject_id = sub.subject_id
        JOIN teachers t ON a.marked_by = t.teacher_id
        WHERE a.student_id IN ($ids)
        ORDER BY a.attendance_date DESC, a.created_at DESC
        LIMIT 10");
}

// Get low attendance alerts
$low_attendance_alerts = [];
if ($students->num_rows > 0) {
    $students->data_seek(0);
    while($s = $students->fetch_assoc()) {
        $low_att = $conn->query("SELECT 
            subject_name, 
            subject_code, 
            attendance_percentage,
            present_count,
            total_classes
            FROM v_attendance_summary 
            WHERE student_id = {$s['student_id']} 
            AND attendance_percentage < 75
            ORDER BY attendance_percentage ASC");
        
        while($att = $low_att->fetch_assoc()) {
            $low_attendance_alerts[] = array_merge($att, ['student_name' => $s['full_name']]);
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parent Dashboard - College ERP</title>
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

        @keyframes bounce {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-10px);
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
            border-radius: 12px;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.4);
            transition: all 0.3s;
        }

        .mobile-menu-toggle:active {
            transform: scale(0.95);
        }

        .mobile-menu-toggle i {
            font-size: 1.3rem;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #1e293b 0%, #334155 100%);
            color: var(--white);
            padding: 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: transform 0.3s ease;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 35px 25px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            animation: slideIn 0.5s ease;
        }

        .parent-info {
            text-align: center;
        }

        .parent-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: rgba(255,255,255,0.25);
            backdrop-filter: blur(10px);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 700;
            margin: 0 auto 12px;
            border: 3px solid rgba(255,255,255,0.3);
            transition: all 0.3s;
            animation: fadeIn 0.6s ease;
        }

        .parent-avatar:hover {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 8px 20px rgba(0,0,0,0.3);
        }

        .parent-name {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 5px;
            animation: fadeIn 0.7s ease;
        }

        .parent-role {
            font-size: 0.85rem;
            opacity: 0.9;
            text-transform: capitalize;
            animation: fadeIn 0.8s ease;
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
            transition: transform 0.3s;
        }

        .menu-item:hover::before,
        .menu-item.active::before {
            transform: scaleY(1);
        }

        .menu-item:hover, .menu-item.active {
            background: rgba(99, 102, 241, 0.2);
            color: var(--white);
            transform: translateX(5px);
        }

        .menu-item i {
            margin-right: 15px;
            width: 20px;
            text-align: center;
            font-size: 1.05rem;
            transition: all 0.3s;
        }

        .menu-item:hover i {
            transform: scale(1.2);
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            animation: fadeIn 0.5s ease;
            transition: margin-left 0.3s ease;
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
        }

        .top-bar h1 {
            font-size: 2rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .logout-btn {
            background: linear-gradient(135deg, var(--danger), #dc2626);
            color: var(--white);
            border: none;
            padding: 12px 26px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-family: 'Plus Jakarta Sans', sans-serif;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
        }

        .logout-btn:active {
            transform: translateY(0);
        }

        /* Children Cards */
        .children-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .child-card {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            padding: 25px;
            border-radius: 18px;
            color: var(--white);
            box-shadow: 0 8px 25px rgba(99, 102, 241, 0.3);
            position: relative;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s;
            animation: fadeIn 0.7s ease;
        }

        .child-card:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 12px 35px rgba(99, 102, 241, 0.4);
        }

        .child-card:active {
            transform: translateY(-2px) scale(1);
        }

        .child-card::before {
            content: '';
            position: absolute;
            top: -50px;
            right: -50px;
            width: 150px;
            height: 150px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            transition: all 0.5s;
        }

        .child-card:hover::before {
            transform: scale(1.5);
        }

        .child-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: rgba(255,255,255,0.25);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 15px;
            position: relative;
            z-index: 1;
            transition: all 0.3s;
        }

        .child-card:hover .child-avatar {
            transform: scale(1.1) rotate(360deg);
        }

        .child-name {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 5px;
            position: relative;
            z-index: 1;
        }

        .child-info {
            font-size: 0.9rem;
            opacity: 0.95;
            margin-bottom: 3px;
            position: relative;
            z-index: 1;
            transition: all 0.3s;
        }

        .child-info i {
            margin-right: 8px;
            transition: all 0.3s;
        }

        .child-card:hover .child-info i {
            transform: translateX(5px);
        }

        /* Quick Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--white);
            padding: 25px;
            border-radius: 18px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            gap: 20px;
            transition: all 0.3s;
            animation: fadeIn 0.8s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            transition: all 0.3s;
        }

        .stat-card:hover .stat-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .stat-icon.primary {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(168, 85, 247, 0.1));
            color: var(--primary);
        }

        .stat-icon.success {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.1), rgba(16, 185, 129, 0.1));
            color: var(--success);
        }

        .stat-icon.warning {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(251, 146, 60, 0.1));
            color: var(--warning);
        }

        .stat-content h4 {
            font-size: 0.85rem;
            color: var(--gray);
            font-weight: 600;
            margin-bottom: 5px;
        }

        .stat-content .stat-value {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--dark);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray);
            background: var(--white);
            border-radius: 18px;
            animation: fadeIn 0.9s ease;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.3;
            animation: bounce 2s infinite;
        }

        .empty-state h3 {
            font-size: 1.3rem;
            margin-bottom: 10px;
        }

        .card {
            background: var(--white);
            padding: 30px;
            border-radius: 18px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin-bottom: 25px;
            animation: fadeIn 1s ease;
            transition: all 0.3s;
        }

        .card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--light-gray);
        }

        .card-title {
            display: flex;
            align-items: center;
        }

        .card-title i {
            font-size: 1.5rem;
            color: var(--primary);
            margin-right: 15px;
            transition: all 0.3s;
        }

        .card:hover .card-title i {
            transform: scale(1.2) rotate(10deg);
        }

        .card-title h3 {
            font-size: 1.4rem;
            font-weight: 700;
        }

        .welcome-message {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            padding: 40px;
            border-radius: 18px;
            margin-bottom: 30px;
            box-shadow: 0 8px 25px rgba(99, 102, 241, 0.3);
            animation: fadeIn 0.5s ease;
        }

        .welcome-message h2 {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .welcome-message p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        /* Activity List Styles */
        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .activity-item {
            display: flex;
            gap: 15px;
            padding: 15px;
            background: var(--light-gray);
            border-radius: 12px;
            transition: all 0.3s;
        }

        .activity-item:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            background: #e2e8f0;
        }

        .activity-icon {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
            transition: all 0.3s;
        }

        .activity-item:hover .activity-icon {
            transform: scale(1.1);
        }

        .activity-details {
            flex: 1;
        }

        .activity-title {
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 4px;
            font-size: 0.95rem;
        }

        .activity-desc {
            color: var(--gray);
            font-size: 0.85rem;
            margin-bottom: 4px;
        }

        .activity-time {
            color: var(--gray);
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .activity-time i {
            font-size: 0.7rem;
        }

        /* Alert List Styles */
        .alert-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .alert-item {
            padding: 15px;
            border-radius: 12px;
            border-left: 4px solid;
            transition: all 0.3s;
        }

        .alert-item:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        .alert-item.alert-danger {
            background: rgba(239, 68, 68, 0.1);
            border-color: var(--danger);
        }

        .alert-item.alert-warning {
            background: rgba(245, 158, 11, 0.1);
            border-color: var(--warning);
        }

        .alert-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .alert-header strong {
            color: var(--dark);
            font-size: 0.95rem;
        }

        .alert-percentage {
            font-weight: 800;
            font-size: 1.1rem;
            color: var(--danger);
        }

        .alert-subject {
            color: var(--dark);
            font-size: 0.9rem;
            margin-bottom: 5px;
            font-weight: 600;
        }

        .alert-stats {
            color: var(--gray);
            font-size: 0.8rem;
        }

        /* Quick Actions Grid */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .action-card {
            background: linear-gradient(135deg, var(--light-gray), #e2e8f0);
            padding: 25px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            gap: 20px;
            text-decoration: none;
            color: var(--dark);
            transition: all 0.3s;
            border-left: 5px solid;
        }

        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(0,0,0,0.15);
        }

        .action-card:active {
            transform: translateY(-2px);
        }

        .action-card.action-primary {
            border-color: var(--primary);
        }

        .action-card.action-primary:hover {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(168, 85, 247, 0.1));
        }

        .action-card.action-secondary {
            border-color: var(--secondary);
        }

        .action-card.action-secondary:hover {
            background: linear-gradient(135deg, rgba(168, 85, 247, 0.1), rgba(168, 85, 247, 0.05));
        }

        .action-card.action-success {
            border-color: var(--success);
        }

        .action-card.action-success:hover {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.1), rgba(34, 197, 94, 0.05));
        }

        .action-card.action-warning {
            border-color: var(--warning);
        }

        .action-card.action-warning:hover {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(245, 158, 11, 0.05));
        }

        .action-icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            background: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            flex-shrink: 0;
            transition: all 0.3s;
        }

        .action-card:hover .action-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .action-card.action-primary .action-icon {
            color: var(--primary);
        }

        .action-card.action-secondary .action-icon {
            color: var(--secondary);
        }

        .action-card.action-success .action-icon {
            color: var(--success);
        }

        .action-card.action-warning .action-icon {
            color: var(--warning);
        }

        .action-content h4 {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--dark);
        }

        .action-content p {
            font-size: 0.85rem;
            color: var(--gray);
            margin: 0;
        }

        .two-column-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .quick-actions-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .children-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            }
        }

        @media (max-width: 992px) {
            .two-column-layout {
                grid-template-columns: 1fr;
            }

            .top-bar h1 {
                font-size: 1.5rem;
            }

            .welcome-message h2 {
                font-size: 1.5rem;
            }

            .welcome-message p {
                font-size: 1rem;
            }
        }

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
                padding: 20px 15px;
                padding-top: 80px;
            }

            .top-bar {
                flex-direction: column;
                gap: 15px;
                padding: 20px;
                text-align: center;
            }

            .top-bar h1 {
                font-size: 1.3rem;
            }

            .logout-btn {
                width: 100%;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .quick-actions-grid {
                grid-template-columns: 1fr;
            }

            .children-grid {
                grid-template-columns: 1fr;
            }

            .welcome-message {
                padding: 25px 20px;
            }

            .welcome-message h2 {
                font-size: 1.3rem;
            }

            .welcome-message p {
                font-size: 0.9rem;
            }

            .card {
                padding: 20px;
            }

            .card-title h3 {
                font-size: 1.1rem;
            }

            .action-card {
                flex-direction: column;
                text-align: center;
            }

            .action-icon {
                width: 50px;
                height: 50px;
                font-size: 1.5rem;
            }

            .child-card {
                padding: 20px;
            }

            .child-avatar {
                width: 50px;
                height: 50px;
                font-size: 1.2rem;
            }

            .child-name {
                font-size: 1.1rem;
            }

            .child-info {
                font-size: 0.85rem;
            }

            .stat-card {
                flex-direction: row;
                padding: 20px;
            }

            .stat-icon {
                width: 50px;
                height: 50px;
                font-size: 1.3rem;
            }

            .stat-value {
                font-size: 1.5rem !important;
            }

            .activity-item {
                flex-direction: column;
                text-align: center;
            }

            .activity-icon {
                margin: 0 auto;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 15px 10px;
                padding-top: 75px;
            }

            .top-bar {
                padding: 15px;
            }

            .top-bar h1 {
                font-size: 1.2rem;
            }

            .card {
                padding: 15px;
            }

            .card-title i {
                font-size: 1.2rem;
                margin-right: 10px;
            }

            .card-title h3 {
                font-size: 1rem;
            }

            .stat-content h4 {
                font-size: 0.75rem;
            }

            .stat-value {
                font-size: 1.3rem !important;
            }

            .action-content h4 {
                font-size: 1rem;
            }

            .action-content p {
                font-size: 0.8rem;
            }

            .alert-percentage {
                font-size: 1rem;
            }

            .alert-subject {
                font-size: 0.85rem;
            }
        }

        /* Sidebar Overlay for Mobile */
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

        @media (max-width: 768px) {
            .sidebar {
                box-shadow: 4px 0 15px rgba(0,0,0,0.3);
            }
        }
    </style>
</head>
<body>
    <button class="mobile-menu-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="sidebar-overlay" onclick="toggleSidebar()"></div>

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
                <a href="index.php" class="menu-item active">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="children_attendance.php" class="menu-item">
                    <i class="fas fa-calendar-check"></i> Attendance
                </a>
                <a href="semester_history.php" class="menu-item">
                    <i class="fas fa-history"></i> Semester History
                </a>
                <a href="children_subjects.php" class="menu-item">
                    <i class="fas fa-book"></i> Subjects
                </a>
                <a href="parent_profile.php" class="menu-item">
                    <i class="fas fa-user"></i> Profile
                </a>
                <a href="parent_settings.php" class="menu-item">
                    <i class="fas fa-cog"></i> Settings
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-bar">
                <h1>Parent Dashboard</h1>
                <a href="../logout.php"><button class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</button></a>
            </div>

            <?php if ($students->num_rows > 0): ?>
                <!-- Welcome Message -->
                <div class="welcome-message">
                    <h2>Welcome, <?php echo $parent['full_name']; ?>!</h2>
                    <p>Monitor your child's academic progress and attendance in one place.</p>
                </div>

                <!-- Quick Stats -->
                <?php
                $total_students = $students->num_rows;
                $students->data_seek(0);
                $first_student = $students->fetch_assoc();
                
                // Get overall attendance for first student
                $overall_att = $conn->query("SELECT 
                    AVG(attendance_percentage) as avg_attendance
                    FROM v_attendance_summary 
                    WHERE student_id = {$first_student['student_id']}")->fetch_assoc();
                
                // Get total subjects
                $total_subjects = $conn->query("SELECT COUNT(*) as count FROM student_subjects 
                    WHERE student_id = {$first_student['student_id']} AND status = 'active'")->fetch_assoc()['count'];
                ?>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <div class="stat-content">
                            <h4>Total Children</h4>
                            <div class="stat-value"><?php echo $total_students; ?></div>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon success">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="stat-content">
                            <h4>Average Attendance</h4>
                            <div class="stat-value"><?php echo round($overall_att['avg_attendance'] ?? 0); ?>%</div>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon warning">
                            <i class="fas fa-book-open"></i>
                        </div>
                        <div class="stat-content">
                            <h4>Active Subjects</h4>
                            <div class="stat-value"><?php echo $total_subjects; ?></div>
                        </div>
                    </div>
                </div>

                <!-- Children Cards -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="fas fa-users"></i>
                            <h3>Your Children</h3>
                        </div>
                    </div>
                    <div class="children-grid">
                        <?php 
                        $students->data_seek(0);
                        while($child = $students->fetch_assoc()): 
                        ?>
                        <div class="child-card" onclick="window.location.href='student_detail.php?id=<?php echo $child['student_id']; ?>'">
                            <div class="child-avatar"><?php echo strtoupper(substr($child['full_name'], 0, 1)); ?></div>
                            <div class="child-name"><?php echo $child['full_name']; ?></div>
                            <div class="child-info"><i class="fas fa-id-card"></i> <?php echo $child['admission_number']; ?></div>
                            <div class="child-info"><i class="fas fa-graduation-cap"></i> <?php echo $child['course_name']; ?></div>
                            <div class="child-info"><i class="fas fa-building"></i> <?php echo $child['department_name']; ?></div>
                            <?php if ($child['semester_name']): ?>
                            <div class="child-info"><i class="fas fa-calendar-alt"></i> <?php echo $child['semester_name']; ?> - <?php echo $child['section_name'] ?? 'No Section'; ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>

                <!-- Two Column Layout for Recent Activity and Alerts -->
                <div class="two-column-layout">
                    
                    <!-- Recent Activity -->
                    <div class="card" style="margin-bottom: 0;">
                        <div class="card-header">
                            <div class="card-title">
                                <i class="fas fa-clock"></i>
                                <h3>Recent Activity</h3>
                            </div>
                        </div>
                        
                        <?php if ($recent_activities && $recent_activities->num_rows > 0): ?>
                        <div class="activity-list">
                            <?php while($activity = $recent_activities->fetch_assoc()): 
                                $status_color = $activity['status'] == 'present' ? 'var(--success)' : 
                                              ($activity['status'] == 'absent' ? 'var(--danger)' : 'var(--warning)');
                                $status_icon = $activity['status'] == 'present' ? 'fa-check-circle' : 
                                             ($activity['status'] == 'absent' ? 'fa-times-circle' : 'fa-clock');
                            ?>
                            <div class="activity-item">
                                <div class="activity-icon" style="background: <?php echo $status_color; ?>15; color: <?php echo $status_color; ?>">
                                    <i class="fas <?php echo $status_icon; ?>"></i>
                                </div>
                                <div class="activity-details">
                                    <div class="activity-title"><?php echo $activity['student_name']; ?></div>
                                    <div class="activity-desc">
                                        <?php echo ucfirst($activity['status']); ?> - <?php echo $activity['subject_code']; ?>
                                    </div>
                                    <div class="activity-time">
                                        <i class="fas fa-calendar"></i> <?php echo date('d M Y', strtotime($activity['attendance_date'])); ?>
                                    </div>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        </div>
                        <?php else: ?>
                        <p style="text-align: center; color: var(--gray); padding: 40px;">No recent activity</p>
                        <?php endif; ?>
                    </div>

                    <!-- Attendance Alerts -->
                    <div class="card" style="margin-bottom: 0;">
                        <div class="card-header">
                            <div class="card-title">
                                <i class="fas fa-exclamation-triangle"></i>
                                <h3>Attendance Alerts</h3>
                            </div>
                        </div>
                        
                        <?php if (!empty($low_attendance_alerts)): ?>
                        <div class="alert-list">
                            <?php foreach(array_slice($low_attendance_alerts, 0, 5) as $alert): 
                                $alert_level = $alert['attendance_percentage'] < 60 ? 'danger' : 'warning';
                            ?>
                            <div class="alert-item alert-<?php echo $alert_level; ?>">
                                <div class="alert-header">
                                    <strong><?php echo $alert['student_name']; ?></strong>
                                    <span class="alert-percentage"><?php echo round($alert['attendance_percentage']); ?>%</span>
                                </div>
                                <div class="alert-subject"><?php echo $alert['subject_name']; ?></div>
                                <div class="alert-stats">
                                    <?php echo $alert['present_count']; ?>/<?php echo $alert['total_classes']; ?> classes attended
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div style="text-align: center; padding: 40px; color: var(--success);">
                            <i class="fas fa-check-circle" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.5;"></i>
                            <p style="font-weight: 600;">All Good! No attendance alerts.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="fas fa-bolt"></i>
                            <h3>Quick Actions</h3>
                        </div>
                    </div>
                    
                    <div class="quick-actions-grid">
                        <a href="children_attendance.php" class="action-card action-primary">
                            <div class="action-icon">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <div class="action-content">
                                <h4>View Attendance</h4>
                                <p>Check detailed attendance records</p>
                            </div>
                        </a>

                        <a href="semester_history.php" class="action-card action-secondary">
                            <div class="action-icon">
                                <i class="fas fa-history"></i>
                            </div>
                            <div class="action-content">
                                <h4>Semester History</h4>
                                <p>View academic timeline</p>
                            </div>
                        </a>

                        <a href="children_subjects.php" class="action-card action-success">
                            <div class="action-icon">
                                <i class="fas fa-book-open"></i>
                            </div>
                            <div class="action-content">
                                <h4>Current Subjects</h4>
                                <p>See enrolled subjects</p>
                            </div>
                        </a>

                        <a href="parent_settings.php" class="action-card action-warning">
                            <div class="action-icon">
                                <i class="fas fa-cog"></i>
                            </div>
                            <div class="action-content">
                                <h4>Settings</h4>
                                <p>Update your profile</p>
                            </div>
                        </a>
                    </div>
                </div>

            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-user-graduate"></i>
                    <h3>No Children Linked</h3>
                    <p>No student records are currently linked to your account. Please contact the administrator.</p>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
        }

        // Close sidebar when clicking on menu items on mobile
        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    toggleSidebar();
                }
            });
        });
    </script>
</body>
</html>
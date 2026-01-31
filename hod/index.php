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

// Department statistics
$stats = [];

// Total Students in Department
$result = $conn->query("SELECT COUNT(*) as count FROM students WHERE department_id = $dept_id");
$stats['students'] = $result->fetch_assoc()['count'];

// Total Teachers in Department
$result = $conn->query("SELECT COUNT(*) as count FROM teachers WHERE department_id = $dept_id");
$stats['teachers'] = $result->fetch_assoc()['count'];

// Total Subjects in Department
$result = $conn->query("SELECT COUNT(*) as count FROM subjects WHERE department_id = $dept_id");
$stats['subjects'] = $result->fetch_assoc()['count'];

// Average Attendance
$result = $conn->query("SELECT ROUND(AVG(attendance_percentage), 2) as avg_attendance 
                       FROM v_attendance_summary vas
                       JOIN students s ON vas.student_id = s.student_id
                       WHERE s.department_id = $dept_id");
$avg_att = $result->fetch_assoc();
$stats['avg_attendance'] = $avg_att['avg_attendance'] ?? 0;

// Department teachers
$teachers = $conn->query("SELECT t.full_name, t.designation, t.qualification, u.email, u.phone
                         FROM teachers t
                         JOIN users u ON t.user_id = u.user_id
                         WHERE t.department_id = $dept_id");

// Department subjects by semester
$subjects_by_sem = $conn->query("SELECT sem.semester_name, COUNT(sub.subject_id) as subject_count
                                FROM semesters sem
                                LEFT JOIN subjects sub ON sub.semester_id = sem.semester_id 
                                     AND sub.department_id = $dept_id
                                GROUP BY sem.semester_id
                                ORDER BY sem.semester_number");

// Recent students
$recent_students = $conn->query("SELECT s.full_name, s.admission_number, s.created_at
                                FROM students s
                                WHERE s.department_id = $dept_id
                                ORDER BY s.created_at DESC
                                LIMIT 5");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HOD Dashboard - College ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
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
    --transition-fast: 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    --transition-base: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    --transition-slow: 0.5s cubic-bezier(0.4, 0, 0.2, 1);
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

/* ===========================
   MOBILE HEADER
   =========================== */
.mobile-header {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    height: 60px;
    background: var(--white);
    box-shadow: var(--shadow-md);
    z-index: 1000;
    padding: 0 16px;
    align-items: center;
    justify-content: space-between;
}

.menu-toggle {
    background: none;
    border: none;
    font-size: 24px;
    color: var(--dark);
    cursor: pointer;
    padding: 8px;
    border-radius: var(--radius-sm);
    transition: var(--transition-fast);
}

.menu-toggle:hover {
    background: var(--gray-light);
}

.menu-toggle:active {
    transform: scale(0.95);
}

.mobile-logo .logo-text {
    font-family: 'Poppins', sans-serif;
    font-size: 18px;
    font-weight: 700;
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.mobile-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    color: var(--white);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 14px;
}

.sidebar-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 998;
    opacity: 0;
    transition: opacity var(--transition-base);
}

.sidebar-overlay.active {
    opacity: 1;
}

/* ===========================
   SIDEBAR
   =========================== */
.sidebar {
    width: 280px;
    height: 100vh;
    background: var(--dark);
    position: fixed;
    left: 0;
    top: 0;
    overflow-y: auto;
    overflow-x: hidden;
    z-index: 999;
    transition: transform var(--transition-base);
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
    padding: 32px 20px;
    background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
    position: relative;
    overflow: hidden;
}

.sidebar-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 200px;
    height: 200px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 50%;
    animation: float 6s ease-in-out infinite;
}

@keyframes float {
    0%, 100% {
        transform: translateY(0) rotate(0deg);
    }
    50% {
        transform: translateY(-20px) rotate(180deg);
    }
}

.hod-profile {
    position: relative;
    z-index: 1;
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
    backdrop-filter: blur(10px);
    color: var(--white);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    font-weight: 700;
    z-index: 2;
    position: relative;
    animation: fadeInScale 0.5s ease-out;
}

@keyframes fadeInScale {
    from {
        opacity: 0;
        transform: scale(0.8);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

.avatar-ring {
    position: absolute;
    top: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 80px;
    height: 80px;
    border: 3px solid rgba(255, 255, 255, 0.4);
    border-radius: 50%;
    animation: pulse-ring 2s ease-out infinite;
}

@keyframes pulse-ring {
    0% {
        transform: translateX(-50%) scale(1);
        opacity: 1;
    }
    100% {
        transform: translateX(-50%) scale(1.3);
        opacity: 0;
    }
}

.hod-info {
    text-align: center;
    color: var(--white);
}

.hod-name {
    font-size: 20px;
    font-weight: 700;
    margin-bottom: 4px;
    font-family: 'Poppins', sans-serif;
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
    backdrop-filter: blur(10px);
}

.hod-role i {
    font-size: 10px;
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
    position: relative;
    transition: all var(--transition-fast);
    cursor: pointer;
    gap: 14px;
}

.menu-item::before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%) scaleY(0);
    width: 4px;
    height: 60%;
    background: var(--primary);
    border-radius: 0 4px 4px 0;
    transition: transform var(--transition-fast);
}

.menu-item:hover::before,
.menu-item.active::before {
    transform: translateY(-50%) scaleY(1);
}

.menu-icon {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: var(--radius-md);
    background: rgba(255, 255, 255, 0.05);
    transition: all var(--transition-fast);
}

.menu-item:hover .menu-icon,
.menu-item.active .menu-icon {
    background: rgba(255, 107, 53, 0.2);
    transform: translateY(-2px);
}

.menu-item:hover .menu-icon i,
.menu-item.active .menu-icon i {
    color: var(--primary);
    transform: scale(1.1);
}

.menu-icon i {
    font-size: 18px;
    transition: all var(--transition-fast);
}

.menu-text {
    flex: 1;
    font-weight: 500;
    font-size: 14px;
}

.menu-item:hover,
.menu-item.active {
    background: rgba(255, 107, 53, 0.08);
    color: var(--white);
}

.menu-indicator {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--primary);
    opacity: 0;
    transform: scale(0);
    transition: all var(--transition-fast);
}

.menu-item.active .menu-indicator {
    opacity: 1;
    transform: scale(1);
}

.sidebar-footer {
    padding: 20px;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    margin-top: auto;
}

.logout-link {
    display: flex;
    align-items: center;
    gap: 12px;
    color: rgba(255, 255, 255, 0.7);
    text-decoration: none;
    padding: 12px 16px;
    border-radius: var(--radius-md);
    transition: all var(--transition-fast);
    font-weight: 500;
}

.logout-link:hover {
    background: rgba(239, 68, 68, 0.2);
    color: var(--danger);
    transform: translateX(4px);
}

.logout-link i {
    font-size: 18px;
}

/* ===========================
   MAIN CONTENT
   =========================== */
.dashboard {
    display: flex;
}

.main-content {
    flex: 1;
    margin-left: 280px;
    padding: 24px;
    min-height: 100vh;
    transition: margin-left var(--transition-base);
}

/* ===========================
   TOP BAR
   =========================== */
.top-bar {
    background: var(--white);
    padding: 28px 32px;
    border-radius: var(--radius-xl);
    margin-bottom: 28px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: var(--shadow-md);
    position: relative;
    overflow: hidden;
    animation: slideDown 0.5s ease-out;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.top-bar::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--primary), var(--secondary));
}

.page-title {
    font-size: 32px;
    font-weight: 800;
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    margin-bottom: 6px;
    font-family: 'Poppins', sans-serif;
}

.page-subtitle {
    color: var(--gray);
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.page-subtitle i {
    color: var(--primary);
}

.top-bar-right {
    display: flex;
    align-items: center;
    gap: 20px;
}

.quick-stats {
    display: flex;
    gap: 16px;
}

.quick-stat-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    background: var(--gray-light);
    border-radius: var(--radius-md);
    font-size: 13px;
    color: var(--gray-dark);
    font-weight: 600;
    transition: all var(--transition-fast);
}

.quick-stat-item:hover {
    background: var(--primary);
    color: var(--white);
    transform: translateY(-2px);
}

.quick-stat-item i {
    font-size: 16px;
}

.logout-btn {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: linear-gradient(135deg, var(--danger), #dc2626);
    color: var(--white);
    border: none;
    padding: 12px 24px;
    border-radius: var(--radius-md);
    cursor: pointer;
    font-weight: 600;
    font-size: 14px;
    text-decoration: none;
    box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
    transition: all var(--transition-fast);
    position: relative;
    overflow: hidden;
}

.logout-btn::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 0;
    height: 0;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.2);
    transform: translate(-50%, -50%);
    transition: width 0.6s, height 0.6s;
}

.logout-btn:hover::before {
    width: 300px;
    height: 300px;
}

.logout-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 25px rgba(239, 68, 68, 0.4);
}

.logout-btn:active {
    transform: translateY(-1px);
}

/* ===========================
   STATS GRID
   =========================== */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 24px;
    margin-bottom: 28px;
}

.stat-card {
    background: var(--white);
    padding: 28px;
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-md);
    position: relative;
    overflow: hidden;
    transition: all var(--transition-base);
    animation: fadeInUp 0.6s ease-out backwards;
    animation-delay: var(--delay);
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

.stat-card::before {
    content: '';
    position: absolute;
    top: -2px;
    left: -2px;
    right: -2px;
    bottom: -2px;
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    border-radius: var(--radius-xl);
    opacity: 0;
    transition: opacity var(--transition-base);
    z-index: -1;
}

.stat-card:hover {
    transform: translateY(-8px);
    box-shadow: var(--shadow-xl);
}

.stat-card:hover::before {
    opacity: 0.1;
}

.stat-icon-wrapper {
    width: 64px;
    height: 64px;
    border-radius: var(--radius-lg);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    margin-bottom: 20px;
    position: relative;
    transition: all var(--transition-base);
}

.stat-card:hover .stat-icon-wrapper {
    transform: scale(1.1) rotate(5deg);
}

.icon-bg {
    position: absolute;
    width: 100%;
    height: 100%;
    border-radius: var(--radius-lg);
    opacity: 0.15;
    animation: pulse 2s ease-in-out infinite;
}

@keyframes pulse {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.05);
    }
}

.stat-icon-wrapper.orange {
    background: linear-gradient(135deg, rgba(255, 107, 53, 0.2), rgba(247, 147, 30, 0.2));
    color: var(--primary);
}

.stat-icon-wrapper.orange .icon-bg {
    background: var(--primary);
}

.stat-icon-wrapper.green {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(5, 150, 105, 0.2));
    color: var(--success);
}

.stat-icon-wrapper.green .icon-bg {
    background: var(--success);
}

.stat-icon-wrapper.yellow {
    background: linear-gradient(135deg, rgba(245, 158, 11, 0.2), rgba(217, 119, 6, 0.2));
    color: var(--warning);
}

.stat-icon-wrapper.yellow .icon-bg {
    background: var(--warning);
}

.stat-icon-wrapper.blue {
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.2), rgba(37, 99, 235, 0.2));
    color: var(--info);
}

.stat-icon-wrapper.blue .icon-bg {
    background: var(--info);
}

.stat-number {
    font-size: 40px;
    font-weight: 800;
    color: var(--dark);
    margin-bottom: 8px;
    font-family: 'Poppins', sans-serif;
    line-height: 1;
}

.stat-label {
    color: var(--gray);
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 16px;
}

.stat-trend {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: var(--gray-light);
    border-radius: 20px;
    font-size: 12px;
    color: var(--gray-dark);
    font-weight: 600;
}

.stat-trend.success {
    background: rgba(16, 185, 129, 0.1);
    color: var(--success);
}

.stat-trend i {
    font-size: 10px;
}

/* ===========================
   CONTENT GRID
   =========================== */
.content-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 24px;
}

.card {
    background: var(--white);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-md);
    overflow: hidden;
    transition: all var(--transition-base);
    animation: fadeIn 0.8s ease-out;
}

@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

.card:hover {
    box-shadow: var(--shadow-lg);
}

.card-header {
    padding: 24px 28px;
    border-bottom: 2px solid var(--gray-light);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.card-title {
    display: flex;
    align-items: center;
    gap: 14px;
}

.title-icon {
    width: 44px;
    height: 44px;
    border-radius: var(--radius-md);
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    color: var(--white);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}

.card-title h3 {
    font-size: 20px;
    font-weight: 700;
    color: var(--dark);
    font-family: 'Poppins', sans-serif;
}

.card-actions {
    display: flex;
    gap: 8px;
}

.icon-btn {
    width: 36px;
    height: 36px;
    border-radius: var(--radius-sm);
    border: none;
    background: var(--gray-light);
    color: var(--gray-dark);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all var(--transition-fast);
}

.icon-btn:hover {
    background: var(--primary);
    color: var(--white);
    transform: rotate(180deg);
}

.card-body {
    padding: 28px;
}

/* ===========================
   TABLE
   =========================== */
.table-wrapper {
    overflow-x: auto;
}

.table {
    width: 100%;
    border-collapse: collapse;
}

.table thead tr {
    border-bottom: 2px solid var(--gray-light);
}

.table th {
    text-align: left;
    padding: 14px 16px;
    font-weight: 700;
    color: var(--gray);
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.table-row {
    border-bottom: 1px solid var(--gray-light);
    transition: all var(--transition-fast);
}

.table-row:hover {
    background: rgba(255, 107, 53, 0.03);
    transform: scale(1.01);
}

.table td {
    padding: 18px 16px;
    font-size: 14px;
    color: var(--gray-dark);
}

.teacher-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.teacher-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    color: var(--white);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 14px;
    flex-shrink: 0;
}

.designation-badge {
    display: inline-block;
    padding: 6px 14px;
    background: var(--gray-light);
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    color: var(--gray-dark);
}

.contact-link {
    color: var(--primary);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 500;
    transition: all var(--transition-fast);
}

.contact-link:hover {
    color: var(--secondary);
    gap: 8px;
}

/* ===========================
   SIDEBAR CARDS
   =========================== */
.sidebar-cards {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.subjects-list,
.students-list {
    display: flex;
    flex-direction: column;
}

.subject-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 0;
    border-bottom: 1px solid var(--gray-light);
    transition: all var(--transition-fast);
}

.subject-item:last-child {
    border-bottom: none;
}

.subject-item:hover {
    padding-left: 8px;
    background: rgba(255, 107, 53, 0.03);
}

.subject-name {
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 600;
    color: var(--dark);
}

.subject-name i {
    color: var(--primary);
    font-size: 16px;
}

.subject-count {
    display: flex;
    align-items: center;
    gap: 6px;
}

.count-badge {
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    color: var(--white);
    padding: 4px 10px;
    border-radius: 12px;
    font-weight: 700;
    font-size: 13px;
}

.count-label {
    font-size: 12px;
    color: var(--gray);
}

.student-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 0;
    border-bottom: 1px solid var(--gray-light);
    transition: all var(--transition-fast);
}

.student-item:last-child {
    border-bottom: none;
}

.student-item:hover {
    transform: translateX(4px);
    background: rgba(255, 107, 53, 0.03);
}

.student-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    color: var(--white);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 16px;
    flex-shrink: 0;
}

.student-info {
    flex: 1;
}

.student-name {
    font-weight: 600;
    color: var(--dark);
    font-size: 14px;
    margin-bottom: 4px;
}

.student-id {
    font-size: 12px;
    color: var(--gray);
}

.student-date {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: var(--gray);
    font-weight: 500;
}

.student-date i {
    color: var(--primary);
}

/* ===========================
   RESPONSIVE DESIGN
   =========================== */

/* Tablet */
@media (max-width: 1024px) {
    .content-grid {
        grid-template-columns: 1fr;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

/* Mobile */
@media (max-width: 768px) {
    .mobile-header {
        display: flex;
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
        padding: 16px;
        padding-top: 76px;
    }
    
    .top-bar {
        flex-direction: column;
        align-items: flex-start;
        gap: 20px;
        padding: 20px;
    }
    
    .top-bar-right {
        width: 100%;
        flex-direction: column;
        gap: 12px;
    }
    
    .quick-stats {
        width: 100%;
        flex-wrap: wrap;
    }
    
    .quick-stat-item {
        flex: 1;
        min-width: calc(50% - 8px);
        justify-content: center;
    }
    
    .logout-btn {
        width: 100%;
        justify-content: center;
    }
    
    .btn-text {
        display: inline;
    }
    
    .page-title {
        font-size: 24px;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    
    .stat-card {
        padding: 20px;
    }
    
    .stat-number {
        font-size: 32px;
    }
    
    .content-grid {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    
    .card-header {
        padding: 20px;
    }
    
    .card-body {
        padding: 20px;
    }
    
    .card-title h3 {
        font-size: 18px;
    }
    
    .table-wrapper {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    .table {
        min-width: 600px;
    }
    
    .table th,
    .table td {
        padding: 12px 10px;
        font-size: 13px;
    }
}

@media (max-width: 480px) {
    .page-title {
        font-size: 20px;
    }
    
    .stat-number {
        font-size: 28px;
    }
    
    .stat-icon-wrapper {
        width: 52px;
        height: 52px;
        font-size: 24px;
    }
    
    .quick-stat-item {
        min-width: 100%;
    }
}

/* ===========================
   LOADING ANIMATIONS
   =========================== */
@keyframes shimmer {
    0% {
        background-position: -1000px 0;
    }
    100% {
        background-position: 1000px 0;
    }
}

.loading {
    animation: shimmer 2s infinite;
    background: linear-gradient(
        to right,
        var(--gray-light) 0%,
        #e0e0e0 20%,
        var(--gray-light) 40%,
        var(--gray-light) 100%
    );
    background-size: 1000px 100%;
}

/* ===========================
   PRINT STYLES
   =========================== */
@media print {
    .sidebar,
    .mobile-header,
    .sidebar-overlay,
    .logout-btn,
    .card-actions {
        display: none !important;
    }
    
    .main-content {
        margin-left: 0;
        padding: 0;
    }
    
    .card,
    .stat-card {
        box-shadow: none;
        border: 1px solid var(--gray-light);
        page-break-inside: avoid;
    }
}
</style>
<script>
    // Dashboard JavaScript with animations and mobile menu functionality

document.addEventListener('DOMContentLoaded', function() {
    initMobileMenu();
    initCounterAnimations();
    initScrollAnimations();
    initTableAnimations();
    initTooltips();
    initCardHoverEffects();
});

// ===========================
// MOBILE MENU
// ===========================
function initMobileMenu() {
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    
    if (menuToggle && sidebar && sidebarOverlay) {
        // Toggle menu
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
            this.querySelector('i').classList.toggle('fa-bars');
            this.querySelector('i').classList.toggle('fa-times');
        });
        
        // Close on overlay click
        sidebarOverlay.addEventListener('click', function() {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
            menuToggle.querySelector('i').classList.remove('fa-times');
            menuToggle.querySelector('i').classList.add('fa-bars');
        });
        
        // Close on menu item click (mobile)
        if (window.innerWidth <= 768) {
            const menuItems = document.querySelectorAll('.menu-item');
            menuItems.forEach(item => {
                item.addEventListener('click', function() {
                    sidebar.classList.remove('active');
                    sidebarOverlay.classList.remove('active');
                    menuToggle.querySelector('i').classList.remove('fa-times');
                    menuToggle.querySelector('i').classList.add('fa-bars');
                });
            });
        }
    }
}

// ===========================
// COUNTER ANIMATIONS
// ===========================
function initCounterAnimations() {
    const counters = document.querySelectorAll('.stat-number');
    
    const animateCounter = (counter) => {
        const target = parseFloat(counter.getAttribute('data-target'));
        const duration = 2000; // 2 seconds
        const increment = target / (duration / 16); // 60fps
        let current = 0;
        
        const updateCounter = () => {
            current += increment;
            if (current < target) {
                // Check if it's a decimal number
                if (target % 1 !== 0) {
                    counter.textContent = current.toFixed(2);
                } else {
                    counter.textContent = Math.ceil(current);
                }
                requestAnimationFrame(updateCounter);
            } else {
                // Final value
                if (target % 1 !== 0) {
                    counter.textContent = target.toFixed(2);
                } else {
                    counter.textContent = target;
                }
            }
        };
        
        updateCounter();
    };
    
    // Intersection Observer for triggering animation when visible
    const observerOptions = {
        threshold: 0.5,
        rootMargin: '0px'
    };
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting && !entry.target.classList.contains('animated')) {
                entry.target.classList.add('animated');
                animateCounter(entry.target);
            }
        });
    }, observerOptions);
    
    counters.forEach(counter => {
        observer.observe(counter);
    });
}

// ===========================
// SCROLL ANIMATIONS
// ===========================
function initScrollAnimations() {
    const elements = document.querySelectorAll('.card, .stat-card');
    
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, observerOptions);
    
    elements.forEach(element => {
        observer.observe(element);
    });
}

// ===========================
// TABLE ROW ANIMATIONS
// ===========================
function initTableAnimations() {
    const tableRows = document.querySelectorAll('.table-row');
    
    tableRows.forEach((row, index) => {
        row.style.animationDelay = `${index * 0.05}s`;
        row.style.animation = 'fadeInLeft 0.5s ease-out forwards';
    });
}

// Define fadeInLeft animation if not in CSS
const style = document.createElement('style');
style.textContent = `
    @keyframes fadeInLeft {
        from {
            opacity: 0;
            transform: translateX(-20px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }
`;
document.head.appendChild(style);

// ===========================
// TOOLTIPS
// ===========================
function initTooltips() {
    const tooltipElements = document.querySelectorAll('[title]');
    
    tooltipElements.forEach(element => {
        element.addEventListener('mouseenter', function(e) {
            const title = this.getAttribute('title');
            if (!title) return;
            
            // Create tooltip
            const tooltip = document.createElement('div');
            tooltip.className = 'custom-tooltip';
            tooltip.textContent = title;
            document.body.appendChild(tooltip);
            
            // Position tooltip
            const rect = this.getBoundingClientRect();
            tooltip.style.cssText = `
                position: fixed;
                top: ${rect.top - tooltip.offsetHeight - 10}px;
                left: ${rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2)}px;
                background: var(--dark);
                color: var(--white);
                padding: 8px 12px;
                border-radius: 8px;
                font-size: 12px;
                font-weight: 500;
                z-index: 10000;
                pointer-events: none;
                animation: tooltipFadeIn 0.2s ease-out;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            `;
            
            // Store tooltip reference
            this._tooltip = tooltip;
            
            // Remove title to prevent default tooltip
            this._originalTitle = title;
            this.removeAttribute('title');
        });
        
        element.addEventListener('mouseleave', function() {
            if (this._tooltip) {
                this._tooltip.style.animation = 'tooltipFadeOut 0.2s ease-out';
                setTimeout(() => {
                    if (this._tooltip && this._tooltip.parentNode) {
                        this._tooltip.parentNode.removeChild(this._tooltip);
                    }
                }, 200);
                this._tooltip = null;
            }
            
            // Restore title
            if (this._originalTitle) {
                this.setAttribute('title', this._originalTitle);
            }
        });
    });
    
    // Add tooltip animations
    const tooltipStyle = document.createElement('style');
    tooltipStyle.textContent = `
        @keyframes tooltipFadeIn {
            from {
                opacity: 0;
                transform: translateY(5px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes tooltipFadeOut {
            from {
                opacity: 1;
                transform: translateY(0);
            }
            to {
                opacity: 0;
                transform: translateY(5px);
            }
        }
    `;
    document.head.appendChild(tooltipStyle);
}

// ===========================
// CARD HOVER EFFECTS
// ===========================
function initCardHoverEffects() {
    const cards = document.querySelectorAll('.stat-card, .card');
    
    cards.forEach(card => {
        card.addEventListener('mouseenter', function(e) {
            this.style.transition = 'all 0.3s cubic-bezier(0.4, 0, 0.2, 1)';
        });
        
        // 3D tilt effect on mouse move
        card.addEventListener('mousemove', function(e) {
            if (window.innerWidth <= 768) return; // Disable on mobile
            
            const rect = this.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            
            const centerX = rect.width / 2;
            const centerY = rect.height / 2;
            
            const rotateX = (y - centerY) / 20;
            const rotateY = (centerX - x) / 20;
            
            this.style.transform = `perspective(1000px) rotateX(${rotateX}deg) rotateY(${rotateY}deg) translateY(-8px)`;
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = '';
        });
    });
}

// ===========================
// REFRESH BUTTON ANIMATION
// ===========================
const refreshButtons = document.querySelectorAll('.icon-btn');
refreshButtons.forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        const icon = this.querySelector('i');
        
        // Add spinning class
        icon.style.animation = 'spin 1s linear';
        
        setTimeout(() => {
            icon.style.animation = '';
        }, 1000);
    });
});

// Add spin animation
const spinStyle = document.createElement('style');
spinStyle.textContent = `
    @keyframes spin {
        from {
            transform: rotate(0deg);
        }
        to {
            transform: rotate(360deg);
        }
    }
`;
document.head.appendChild(spinStyle);

// ===========================
// SMOOTH SCROLL
// ===========================
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function(e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            target.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    });
});

// ===========================
// LOADING STATE
// ===========================
function showLoading(element) {
    element.classList.add('loading');
    element.style.pointerEvents = 'none';
}

function hideLoading(element) {
    element.classList.remove('loading');
    element.style.pointerEvents = '';
}

// ===========================
// UTILITY FUNCTIONS
// ===========================

// Format numbers with commas
function formatNumber(num) {
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

// Debounce function for performance
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Handle window resize
let resizeTimer;
window.addEventListener('resize', debounce(function() {
    // Reinitialize mobile menu if needed
    const sidebar = document.getElementById('sidebar');
    if (window.innerWidth > 768 && sidebar) {
        sidebar.classList.remove('active');
        document.getElementById('sidebarOverlay')?.classList.remove('active');
        const menuToggle = document.getElementById('menuToggle');
        if (menuToggle) {
            menuToggle.querySelector('i').classList.remove('fa-times');
            menuToggle.querySelector('i').classList.add('fa-bars');
        }
    }
}, 250));

// ===========================
// PERFORMANCE MONITORING
// ===========================
if ('PerformanceObserver' in window) {
    const perfObserver = new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) {
            if (entry.duration > 100) {
                console.warn('Long task detected:', entry);
            }
        }
    });
    
    try {
        perfObserver.observe({ entryTypes: ['longtask'] });
    } catch (e) {
        // Longtask API not supported
    }
}

// ===========================
// ACCESSIBILITY
// ===========================

// Keyboard navigation for menu
document.addEventListener('keydown', function(e) {
    // ESC key closes mobile menu
    if (e.key === 'Escape') {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const menuToggle = document.getElementById('menuToggle');
        
        if (sidebar && sidebar.classList.contains('active')) {
            sidebar.classList.remove('active');
            overlay?.classList.remove('active');
            if (menuToggle) {
                menuToggle.querySelector('i').classList.remove('fa-times');
                menuToggle.querySelector('i').classList.add('fa-bars');
            }
        }
    }
});

// Focus trap for mobile menu
function trapFocus(element) {
    const focusableElements = element.querySelectorAll(
        'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled])'
    );
    const firstFocusable = focusableElements[0];
    const lastFocusable = focusableElements[focusableElements.length - 1];
    
    element.addEventListener('keydown', function(e) {
        if (e.key !== 'Tab') return;
        
        if (e.shiftKey) {
            if (document.activeElement === firstFocusable) {
                lastFocusable.focus();
                e.preventDefault();
            }
        } else {
            if (document.activeElement === lastFocusable) {
                firstFocusable.focus();
                e.preventDefault();
            }
        }
    });
}

const sidebar = document.getElementById('sidebar');
if (sidebar) {
    trapFocus(sidebar);
}

// ===========================
// PAGE VISIBILITY
// ===========================
document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        // Page is hidden - pause animations if needed
        console.log('Page hidden');
    } else {
        // Page is visible - resume animations
        console.log('Page visible');
    }
});

// ===========================
// CONSOLE WELCOME MESSAGE
// ===========================
console.log(
    '%c🎓 HOD Dashboard v1.0 %c\nWelcome to the College ERP System',
    'font-size: 20px; font-weight: bold; color: #ff6b35;',
    'font-size: 12px; color: #64748b;'
);

// Export functions for potential use in other scripts
window.dashboardUtils = {
    showLoading,
    hideLoading,
    formatNumber,
    debounce
};
</script>

</head>
<body>
    <div class="dashboard">
        <!-- Mobile Header -->
        <div class="mobile-header">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <div class="mobile-logo">
                <span class="logo-text">HOD Portal</span>
            </div>
            <div class="mobile-profile">
                <div class="mobile-avatar"><?php echo strtoupper(substr($hod['full_name'], 0, 1)); ?></div>
            </div>
        </div>

        <!-- Sidebar Overlay -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <!-- Sidebar -->
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
                </a> <a href="student_infro.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <span class="menu-text">Students infro</span>
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
                <a href="sectioninfro.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-chalkboard"></i>
                    </div>
                    <span class="menu-text">Classes Infro</span>
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

            <div class="sidebar-footer">
                <a href="../logout.php" class="logout-link">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-bar">
                <div class="top-bar-left">
                    <h1 class="page-title">Department Overview</h1>
                    <p class="page-subtitle">
                        <i class="fas fa-building"></i>
                        <?php echo $hod['department_name']; ?> Department
                    </p>
                </div>
                <div class="top-bar-right">
                    <div class="quick-stats">
                        <div class="quick-stat-item">
                            <i class="fas fa-calendar-alt"></i>
                            <span><?php echo date('M d, Y'); ?></span>
                        </div>
                    </div>
                    <a href="../logout.php" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i>
                        <span class="btn-text">Logout</span>
                    </a>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card" style="--delay: 0.1s">
                    <div class="stat-icon-wrapper orange">
                        <i class="fas fa-user-graduate"></i>
                        <div class="icon-bg"></div>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number" data-target="<?php echo $stats['students']; ?>">0</div>
                        <div class="stat-label">Total Students</div>
                    </div>
                    <div class="stat-trend">
                        <i class="fas fa-arrow-up"></i>
                        <span>Active</span>
                    </div>
                </div>

                <div class="stat-card" style="--delay: 0.2s">
                    <div class="stat-icon-wrapper green">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <div class="icon-bg"></div>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number" data-target="<?php echo $stats['teachers']; ?>">0</div>
                        <div class="stat-label">Faculty Members</div>
                    </div>
                    <div class="stat-trend">
                        <i class="fas fa-users"></i>
                        <span>Teaching</span>
                    </div>
                </div>

                <div class="stat-card" style="--delay: 0.3s">
                    <div class="stat-icon-wrapper yellow">
                        <i class="fas fa-book"></i>
                        <div class="icon-bg"></div>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number" data-target="<?php echo $stats['subjects']; ?>">0</div>
                        <div class="stat-label">Total Subjects</div>
                    </div>
                    <div class="stat-trend">
                        <i class="fas fa-check-circle"></i>
                        <span>Courses</span>
                    </div>
                </div>

                <div class="stat-card" style="--delay: 0.4s">
                    <div class="stat-icon-wrapper blue">
                        <i class="fas fa-chart-line"></i>
                        <div class="icon-bg"></div>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number" data-target="<?php echo $stats['avg_attendance']; ?>">0</div>
                        <div class="stat-label">Avg. Attendance</div>
                    </div>
                    <div class="stat-trend success">
                        <i class="fas fa-arrow-up"></i>
                        <span><?php echo $stats['avg_attendance']; ?>%</span>
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <div class="card faculty-card">
                    <div class="card-header">
                        <div class="card-title">
                            <div class="title-icon">
                                <i class="fas fa-users"></i>
                            </div>
                            <h3>Faculty Members</h3>
                        </div>
                        <div class="card-actions">
                            <button class="icon-btn" title="Refresh">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-wrapper">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Designation</th>
                                        <th>Qualification</th>
                                        <th>Contact</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($teacher = $teachers->fetch_assoc()): ?>
                                    <tr class="table-row">
                                        <td>
                                            <div class="teacher-info">
                                                <div class="teacher-avatar">
                                                    <?php echo strtoupper(substr($teacher['full_name'], 0, 1)); ?>
                                                </div>
                                                <strong><?php echo $teacher['full_name']; ?></strong>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="designation-badge">
                                                <?php echo $teacher['designation'] ?? 'N/A'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo $teacher['qualification'] ?? 'N/A'; ?></td>
                                        <td>
                                            <a href="mailto:<?php echo $teacher['email']; ?>" class="contact-link">
                                                <i class="fas fa-envelope"></i>
                                                <?php echo $teacher['email']; ?>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="sidebar-cards">
                    <div class="card subjects-card">
                        <div class="card-header">
                            <div class="card-title">
                                <div class="title-icon">
                                    <i class="fas fa-list"></i>
                                </div>
                                <h3>Subjects by Semester</h3>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="subjects-list">
                                <?php while($sem = $subjects_by_sem->fetch_assoc()): ?>
                                <div class="subject-item">
                                    <div class="subject-name">
                                        <i class="fas fa-folder"></i>
                                        <span><?php echo $sem['semester_name']; ?></span>
                                    </div>
                                    <div class="subject-count">
                                        <span class="count-badge"><?php echo $sem['subject_count']; ?></span>
                                        <span class="count-label">subjects</span>
                                    </div>
                                </div>
                                <?php endwhile; ?>
                            </div>
                        </div>
                    </div>

                    <div class="card recent-students-card">
                        <div class="card-header">
                            <div class="card-title">
                                <div class="title-icon">
                                    <i class="fas fa-user-plus"></i>
                                </div>
                                <h3>Recent Students</h3>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="students-list">
                                <?php while($student = $recent_students->fetch_assoc()): ?>
                                <div class="student-item">
                                    <div class="student-avatar">
                                        <?php echo strtoupper(substr($student['full_name'], 0, 1)); ?>
                                    </div>
                                    <div class="student-info">
                                        <div class="student-name"><?php echo $student['full_name']; ?></div>
                                        <div class="student-id"><?php echo $student['admission_number']; ?></div>
                                    </div>
                                    <div class="student-date">
                                        <i class="fas fa-calendar"></i>
                                        <?php echo date('M d', strtotime($student['created_at'])); ?>
                                    </div>
                                </div>
                                <?php endwhile; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="assets/js/dashboard.js"></script>
</body>
</html>
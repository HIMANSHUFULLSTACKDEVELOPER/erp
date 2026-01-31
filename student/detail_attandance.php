<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('student')) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

// Get student details
$sql = "SELECT s.*, d.department_name, c.course_name 
        FROM students s 
        JOIN departments d ON s.department_id = d.department_id 
        JOIN courses c ON s.course_id = c.course_id 
        WHERE s.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    die("Student record not found!");
}

// Get current active semester
$current_sem_query = "SELECT ss.*, sem.semester_name, sec.section_name 
                      FROM student_semesters ss 
                      JOIN semesters sem ON ss.semester_id = sem.semester_id 
                      LEFT JOIN sections sec ON ss.section_id = sec.section_id 
                      WHERE ss.student_id = ? AND ss.is_active = 1";
$stmt = $conn->prepare($current_sem_query);
$stmt->bind_param("i", $student['student_id']);
$stmt->execute();
$current_sem = $stmt->get_result()->fetch_assoc();

// Get selected date for daily view (default to today)
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$selected_date_display = date('l, j F Y', strtotime($selected_date));
$is_today = ($selected_date === date('Y-m-d'));

// Calculate overall attendance statistics
$stats_query = "SELECT 
                    COUNT(*) as total_classes,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count
                FROM attendance 
                WHERE student_id = ?";

if ($current_sem) {
    $stats_query .= " AND semester_id = ?";
    $stmt = $conn->prepare($stats_query);
    $stmt->bind_param("ii", $student['student_id'], $current_sem['semester_id']);
} else {
    $stmt = $conn->prepare($stats_query);
    $stmt->bind_param("i", $student['student_id']);
}

$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();

// Calculate percentage
$total_classes = $stats['total_classes'] > 0 ? $stats['total_classes'] : 1;
$attendance_percentage = round(($stats['present_count'] / $total_classes) * 100);

// Calculate days needed to reach 75%
$days_to_75 = 0;
if ($attendance_percentage < 75) {
    $current_present = $stats['present_count'];
    $current_total = $total_classes;
    while ((($current_present / $current_total) * 100) < 75) {
        $current_present++;
        $current_total++;
        $days_to_75++;
    }
}

// Calculate current streak
$streak_query = "SELECT attendance_date, status 
                 FROM attendance 
                 WHERE student_id = ? 
                 ORDER BY attendance_date DESC 
                 LIMIT 30";
$stmt = $conn->prepare($streak_query);
$stmt->bind_param("i", $student['student_id']);
$stmt->execute();
$recent_attendance = $stmt->get_result();

$current_streak = 0;
$last_status = null;
while ($row = $recent_attendance->fetch_assoc()) {
    if ($last_status === null) {
        $last_status = $row['status'];
        if ($last_status == 'present') {
            $current_streak = 1;
        }
    } else {
        if ($row['status'] == 'present' && $last_status == 'present') {
            $current_streak++;
        } else {
            break;
        }
    }
}

// Get daily attendance for selected date
$daily_attendance_query = "SELECT a.*, sub.subject_name, sub.subject_code, t.full_name as teacher_name,
                           TIME_FORMAT(a.created_at, '%h:%i %p') as marked_time
                           FROM attendance a
                           JOIN subjects sub ON a.subject_id = sub.subject_id
                           JOIN teachers t ON a.marked_by = t.teacher_id
                           WHERE a.student_id = ? AND a.attendance_date = ?
                           ORDER BY a.created_at DESC";
$stmt = $conn->prepare($daily_attendance_query);
$stmt->bind_param("is", $student['student_id'], $selected_date);
$stmt->execute();
$daily_records = $stmt->get_result();

$daily_present = 0;
$daily_absent = 0;
$daily_late = 0;
$daily_array = [];

while($record = $daily_records->fetch_assoc()) {
    $daily_array[] = $record;
    switch($record['status']) {
        case 'present': $daily_present++; break;
        case 'absent': $daily_absent++; break;
        case 'late': $daily_late++; break;
    }
}

// Get recent attendance records (last 20 entries)
$recent_records_query = "SELECT a.*, sub.subject_name, sub.subject_code, t.full_name as teacher_name,
                         DAYNAME(a.attendance_date) as day_name,
                         TIME_FORMAT(a.created_at, '%h:%i %p') as marked_time
                         FROM attendance a
                         JOIN subjects sub ON a.subject_id = sub.subject_id
                         JOIN teachers t ON a.marked_by = t.teacher_id
                         WHERE a.student_id = ?
                         ORDER BY a.attendance_date DESC, a.created_at DESC
                         LIMIT 20";
$stmt = $conn->prepare($recent_records_query);
$stmt->bind_param("i", $student['student_id']);
$stmt->execute();
$recent_records = $stmt->get_result();

// Get subject-wise attendance
$subject_stats_query = "SELECT 
                        sub.subject_name,
                        sub.subject_code,
                        COUNT(*) as total_classes,
                        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                        SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                        SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count
                        FROM attendance a
                        JOIN subjects sub ON a.subject_id = sub.subject_id
                        WHERE a.student_id = ?";

if ($current_sem) {
    $subject_stats_query .= " AND a.semester_id = ?";
}

$subject_stats_query .= " GROUP BY sub.subject_id, sub.subject_name, sub.subject_code
                         ORDER BY sub.subject_name";

if ($current_sem) {
    $stmt = $conn->prepare($subject_stats_query);
    $stmt->bind_param("ii", $student['student_id'], $current_sem['semester_id']);
} else {
    $stmt = $conn->prepare($subject_stats_query);
    $stmt->bind_param("i", $student['student_id']);
}

$stmt->execute();
$subject_stats = $stmt->get_result();

// Get monthly attendance trend
$monthly_trend_query = "SELECT 
                        DATE_FORMAT(attendance_date, '%Y-%m') as month,
                        DATE_FORMAT(attendance_date, '%b %Y') as month_name,
                        COUNT(*) as total_classes,
                        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count
                        FROM attendance
                        WHERE student_id = ?";

if ($current_sem) {
    $monthly_trend_query .= " AND semester_id = ?";
}

$monthly_trend_query .= " GROUP BY DATE_FORMAT(attendance_date, '%Y-%m')
                         ORDER BY month DESC
                         LIMIT 6";

if ($current_sem) {
    $stmt = $conn->prepare($monthly_trend_query);
    $stmt->bind_param("ii", $student['student_id'], $current_sem['semester_id']);
} else {
    $stmt = $conn->prepare($monthly_trend_query);
    $stmt->bind_param("i", $student['student_id']);
}

$stmt->execute();
$monthly_trend = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Analytics - College ERP</title>
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
            --purple: #8b5cf6;
            --purple-light: #a78bfa;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Manrope', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 30px;
        }

        .container {
            max-width: 1600px;
            margin: 0 auto;
        }

        .back-btn {
            background: var(--white);
            color: var(--purple);
            border: 2px solid var(--purple);
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .back-btn:hover {
            background: var(--purple);
            color: var(--white);
            transform: translateY(-2px);
        }

        /* Tab Navigation */
        .tab-navigation {
            background: var(--white);
            border-radius: 15px;
            padding: 10px;
            margin-bottom: 30px;
            display: flex;
            gap: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            flex-wrap: wrap;
        }

        .tab-btn {
            flex: 1;
            min-width: 200px;
            padding: 15px 25px;
            background: transparent;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.95rem;
            color: var(--gray);
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .tab-btn:hover {
            background: var(--light-gray);
        }

        .tab-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: var(--white);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .tab-btn i {
            font-size: 1.2rem;
        }

        /* Tab Content */
        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.5s;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .stats-card {
            background: var(--white);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            margin-bottom: 30px;
        }

        /* Student Info Banner */
        .student-info-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: var(--white);
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .student-details {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .detail-label {
            font-size: 0.75rem;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .detail-value {
            font-size: 1rem;
            font-weight: 700;
        }

        .semester-badge {
            background: rgba(255, 255, 255, 0.2);
            padding: 10px 20px;
            border-radius: 25px;
            font-weight: 700;
            backdrop-filter: blur(10px);
        }

        /* Section Titles */
        .section-title {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .section-title i {
            color: var(--purple);
        }

        /* Overall Stats Circle */
        .stats-overview {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 40px;
            margin-bottom: 40px;
        }

        .circular-progress {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .progress-ring {
            position: relative;
            width: 300px;
            height: 300px;
        }

        .progress-ring svg {
            transform: rotate(-90deg);
        }

        .progress-ring circle {
            fill: none;
            stroke-width: 20;
        }

        .progress-ring .bg {
            stroke: #f3f4f6;
        }

        .progress-ring .progress {
            stroke: url(#gradient);
            stroke-linecap: round;
            transition: stroke-dashoffset 1s ease;
        }

        .progress-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
        }

        .progress-percentage {
            font-size: 4rem;
            font-weight: 800;
            background: linear-gradient(135deg, #ec4899, #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .progress-label {
            font-size: 1rem;
            color: var(--gray);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        /* Stats Bars */
        .stats-bars {
            display: flex;
            flex-direction: column;
            gap: 25px;
        }

        .stat-item {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .stat-label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.95rem;
            color: var(--gray);
            font-weight: 600;
        }

        .stat-label i {
            color: var(--purple);
            font-size: 1.2rem;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 800;
        }

        .stat-value.blue { color: #3b82f6; }
        .stat-value.green { color: #10b981; }
        .stat-value.red { color: #ef4444; }
        .stat-value.orange { color: #f59e0b; }

        .progress-bar-container {
            width: 100%;
            height: 14px;
            background: #f3f4f6;
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-bar-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 1s ease;
        }

        .progress-bar-fill.green {
            background: linear-gradient(90deg, #10b981, #34d399);
        }

        .progress-bar-fill.red {
            background: linear-gradient(90deg, #ef4444, #f87171);
        }

        .progress-bar-fill.gray {
            background: linear-gradient(90deg, #9ca3af, #d1d5db);
        }

        /* Alert Boxes */
        .alert-box {
            padding: 20px 25px;
            border-radius: 12px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 15px;
            border-left: 5px solid;
        }

        .alert-box.warning {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border-color: var(--warning);
        }

        .alert-box.success {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            border-color: var(--success);
        }

        .alert-box i {
            font-size: 1.8rem;
        }

        .alert-box.warning i { color: var(--warning); }
        .alert-box.success i { color: var(--success); }

        .alert-text {
            font-weight: 600;
            font-size: 1rem;
        }

        .alert-box.warning .alert-text { color: #92400e; }
        .alert-box.success .alert-text { color: #065f46; }

        /* Info Cards Grid */
        .info-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .info-card {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 25px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            gap: 20px;
            transition: all 0.3s;
        }

        .info-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }

        .info-icon {
            width: 65px;
            height: 65px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            flex-shrink: 0;
        }

        .info-icon.purple {
            background: linear-gradient(135deg, #ec4899, #8b5cf6);
            color: var(--white);
        }

        .info-icon.orange {
            background: linear-gradient(135deg, #f59e0b, #fb923c);
            color: var(--white);
        }

        .info-icon.blue {
            background: linear-gradient(135deg, #3b82f6, #60a5fa);
            color: var(--white);
        }

        .info-icon.green {
            background: linear-gradient(135deg, #10b981, #34d399);
            color: var(--white);
        }

        .info-content h3 {
            font-size: 0.85rem;
            color: var(--gray);
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-content p {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--dark);
        }

        /* Date Picker Section */
        .date-selector {
            background: linear-gradient(135deg, #f8f9ff 0%, #f0f0ff 100%);
            padding: 20px 25px;
            border-radius: 12px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .date-selector label {
            font-weight: 700;
            color: var(--purple);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1rem;
        }

        .date-selector input[type="date"] {
            padding: 12px 18px;
            border: 2px solid var(--purple);
            border-radius: 10px;
            font-family: 'Manrope', sans-serif;
            font-weight: 600;
            color: var(--dark);
            cursor: pointer;
            transition: all 0.3s;
            font-size: 0.95rem;
        }

        .date-selector input[type="date"]:focus {
            outline: none;
            box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.2);
        }

        .quick-date-btns {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .quick-btn {
            padding: 10px 20px;
            background: var(--white);
            border: 2px solid var(--purple);
            color: var(--purple);
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 0.9rem;
        }

        .quick-btn:hover, .quick-btn.active {
            background: var(--purple);
            color: var(--white);
            transform: translateY(-2px);
        }

        /* Daily Stats Cards */
        .daily-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .daily-stat-card {
            padding: 25px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            gap: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            color: var(--white);
        }

        .daily-stat-card.total {
            background: linear-gradient(135deg, #667eea, #764ba2);
        }

        .daily-stat-card.present {
            background: linear-gradient(135deg, #10b981, #34d399);
        }

        .daily-stat-card.absent {
            background: linear-gradient(135deg, #ef4444, #f87171);
        }

        .daily-stat-card.late {
            background: linear-gradient(135deg, #f59e0b, #fb923c);
        }

        .daily-stat-icon {
            font-size: 2.5rem;
            opacity: 0.9;
        }

        .daily-stat-info h3 {
            font-size: 0.85rem;
            opacity: 0.9;
            margin-bottom: 5px;
        }

        .daily-stat-info p {
            font-size: 2.2rem;
            font-weight: 800;
        }

        /* Attendance Cards Grid */
        .attendance-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .attendance-card {
            background: linear-gradient(135deg, #ffffff, #f8f9fa);
            padding: 25px;
            border-radius: 15px;
            border-left: 5px solid var(--purple);
            transition: all 0.3s;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }

        .attendance-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.15);
        }

        .subject-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }

        .subject-info .subject-name {
            font-weight: 800;
            color: var(--dark);
            font-size: 1.2rem;
            margin-bottom: 8px;
        }

        .subject-code {
            font-size: 0.8rem;
            color: var(--gray);
            background: var(--light-gray);
            padding: 5px 12px;
            border-radius: 8px;
            display: inline-block;
        }

        .time-badge {
            background: var(--purple);
            color: var(--white);
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 0.8rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .teacher-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 15px 0;
            padding: 12px;
            background: var(--light-gray);
            border-radius: 10px;
        }

        .teacher-info i {
            color: var(--purple);
            font-size: 1.1rem;
        }

        .teacher-name {
            font-size: 0.95rem;
            color: var(--dark);
            font-weight: 600;
        }

        .status-section {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 2px dashed #e5e7eb;
        }

        .status-badge {
            padding: 10px 18px;
            border-radius: 25px;
            font-weight: 700;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
        }

        .status-badge i {
            font-size: 1rem;
        }

        .status-badge.present {
            background: linear-gradient(135deg, #10b981, #34d399);
            color: var(--white);
        }

        .status-badge.absent {
            background: linear-gradient(135deg, #ef4444, #f87171);
            color: var(--white);
        }

        .status-badge.late {
            background: linear-gradient(135deg, #f59e0b, #fb923c);
            color: var(--white);
        }

        .remarks-box {
            margin-top: 12px;
            padding: 12px;
            background: var(--white);
            border-left: 3px solid var(--purple);
            border-radius: 8px;
        }

        .remarks-label {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--purple);
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .remarks-text {
            font-size: 0.9rem;
            color: var(--dark);
            line-height: 1.5;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 5rem;
            margin-bottom: 20px;
            opacity: 0.3;
            animation: float 3s ease-in-out infinite;
        }

        .empty-state h2 {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--warning);
            margin-bottom: 10px;
        }

        .empty-state p {
            font-size: 1.1rem;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }

        /* Recent Records Table */
        .table-container {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 15px;
            padding: 2px;
            overflow: hidden;
            margin-bottom: 30px;
        }

        .table-wrapper {
            background: var(--white);
            border-radius: 13px;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        thead th {
            color: var(--white);
            font-weight: 700;
            padding: 18px 15px;
            text-align: left;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        thead th i {
            margin-right: 8px;
        }

        tbody tr {
            border-bottom: 1px solid #f3f4f6;
            transition: background 0.3s;
        }

        tbody tr:hover {
            background: #faf5ff;
        }

        tbody td {
            padding: 18px 15px;
            color: var(--dark);
            font-weight: 500;
        }

        .subject-name-cell {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .date-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .date-day {
            font-weight: 700;
            color: var(--dark);
        }

        .date-full {
            font-size: 0.85rem;
            color: var(--gray);
        }

        .time-info {
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--gray);
            font-size: 0.9rem;
        }

        .time-info i {
            color: var(--purple);
        }

        /* Subject Stats Table */
        .subject-table {
            width: 100%;
            border-collapse: collapse;
        }

        .subject-table thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .subject-table th {
            color: var(--white);
            padding: 18px 15px;
            text-align: left;
            font-weight: 700;
            font-size: 0.85rem;
            text-transform: uppercase;
        }

        .subject-table tbody tr {
            border-bottom: 1px solid #f3f4f6;
            transition: background 0.3s;
        }

        .subject-table tbody tr:hover {
            background: #faf5ff;
        }

        .subject-table td {
            padding: 18px 15px;
            color: var(--dark);
        }

        .percentage-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 0.85rem;
            display: inline-block;
        }

        .percentage-badge.excellent {
            background: linear-gradient(135deg, #10b981, #34d399);
            color: var(--white);
        }

        .percentage-badge.good {
            background: linear-gradient(135deg, #3b82f6, #60a5fa);
            color: var(--white);
        }

        .percentage-badge.warning {
            background: linear-gradient(135deg, #f59e0b, #fb923c);
            color: var(--white);
        }

        .percentage-badge.danger {
            background: linear-gradient(135deg, #ef4444, #f87171);
            color: var(--white);
        }

        /* Monthly Trend */
        .monthly-trend {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .month-card {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            transition: all 0.3s;
        }

        .month-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }

        .month-name {
            font-size: 0.9rem;
            color: var(--gray);
            margin-bottom: 10px;
            font-weight: 600;
        }

        .month-percentage {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .month-details {
            font-size: 0.75rem;
            color: var(--gray);
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .stats-overview {
                grid-template-columns: 1fr;
            }

            .circular-progress {
                margin-bottom: 20px;
            }
        }

        @media (max-width: 768px) {
            body {
                padding: 15px;
            }

            .stats-card {
                padding: 25px;
            }

            .section-title {
                font-size: 1.4rem;
            }

            .tab-btn {
                min-width: 150px;
                padding: 12px 20px;
                font-size: 0.85rem;
            }

            .progress-ring {
                width: 250px;
                height: 250px;
            }

            .progress-percentage {
                font-size: 3rem;
            }

            .attendance-grid {
                grid-template-columns: 1fr;
            }

            .info-cards {
                grid-template-columns: 1fr;
            }

            .date-selector {
                flex-direction: column;
                align-items: flex-start;
            }

            .student-details {
                gap: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>

        <!-- Student Info Banner -->
        <div class="student-info-banner">
            <div class="student-details">
                <div class="detail-item">
                    <div class="detail-label"><i class="fas fa-user"></i> Name</div>
                    <div class="detail-value"><?php echo htmlspecialchars($student['full_name']); ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label"><i class="fas fa-id-card"></i> Roll No</div>
                    <div class="detail-value"><?php echo htmlspecialchars($student['roll_number']); ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label"><i class="fas fa-building"></i> Department</div>
                    <div class="detail-value"><?php echo htmlspecialchars($student['department_name']); ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label"><i class="fas fa-graduation-cap"></i> Course</div>
                    <div class="detail-value"><?php echo htmlspecialchars($student['course_name']); ?></div>
                </div>
            </div>
            <?php if ($current_sem): ?>
            <div class="semester-badge">
                <i class="fas fa-book-open"></i> <?php echo htmlspecialchars($current_sem['semester_name']); ?>
                <?php if ($current_sem['section_name']): ?>
                    - <?php echo htmlspecialchars($current_sem['section_name']); ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Tab Navigation -->
        <div class="tab-navigation">
            <button class="tab-btn active" onclick="openTab('overall')">
                <i class="fas fa-chart-pie"></i>
                Overall Statistics
            </button>
            <button class="tab-btn" onclick="openTab('daily')">
                <i class="fas fa-calendar-day"></i>
                Daily View
            </button>
            <button class="tab-btn" onclick="openTab('recent')">
                <i class="fas fa-history"></i>
                Recent Records
            </button>
        </div>

        <!-- Overall Statistics Tab -->
        <div id="overall" class="tab-content active">
            <div class="stats-card">
                <h2 class="section-title">
                    <i class="fas fa-chart-line"></i>
                    Attendance Overview
                </h2>

                <div class="stats-overview">
                    <div class="circular-progress">
                        <div class="progress-ring">
                            <svg width="300" height="300">
                                <defs>
                                    <linearGradient id="gradient" x1="0%" y1="0%" x2="100%" y2="100%">
                                        <stop offset="0%" style="stop-color:#ec4899;stop-opacity:1" />
                                        <stop offset="100%" style="stop-color:#8b5cf6;stop-opacity:1" />
                                    </linearGradient>
                                </defs>
                                <circle class="bg" cx="150" cy="150" r="130"></circle>
                                <circle class="progress" cx="150" cy="150" r="130"
                                        stroke-dasharray="<?php echo 2 * 3.14159 * 130; ?>"
                                        stroke-dashoffset="<?php echo 2 * 3.14159 * 130 * (1 - $attendance_percentage / 100); ?>">
                                </circle>
                            </svg>
                            <div class="progress-text">
                                <div class="progress-percentage"><?php echo $attendance_percentage; ?>%</div>
                                <div class="progress-label">OVERALL</div>
                            </div>
                        </div>
                    </div>

                    <div class="stats-bars">
                        <div class="stat-item">
                            <div class="stat-header">
                                <span class="stat-label">
                                    <i class="fas fa-calendar-alt"></i> Total Classes
                                </span>
                                <span class="stat-value blue"><?php echo $stats['total_classes']; ?></span>
                            </div>
                        </div>

                        <div class="stat-item">
                            <div class="stat-header">
                                <span class="stat-label">
                                    <i class="fas fa-check-circle"></i> Present
                                </span>
                                <span class="stat-value green"><?php echo $stats['present_count']; ?></span>
                            </div>
                            <div class="progress-bar-container">
                                <div class="progress-bar-fill green" style="width: <?php echo ($stats['present_count'] / $total_classes * 100); ?>%"></div>
                            </div>
                        </div>

                        <div class="stat-item">
                            <div class="stat-header">
                                <span class="stat-label">
                                    <i class="fas fa-times-circle"></i> Absent
                                </span>
                                <span class="stat-value red"><?php echo $stats['absent_count']; ?></span>
                            </div>
                            <div class="progress-bar-container">
                                <div class="progress-bar-fill red" style="width: <?php echo ($stats['absent_count'] / $total_classes * 100); ?>%"></div>
                            </div>
                        </div>

                        <div class="stat-item">
                            <div class="stat-header">
                                <span class="stat-label">
                                    <i class="fas fa-clock"></i> Late
                                </span>
                                <span class="stat-value orange"><?php echo $stats['late_count']; ?></span>
                            </div>
                            <div class="progress-bar-container">
                                <div class="progress-bar-fill gray" style="width: <?php echo ($stats['late_count'] / $total_classes * 100); ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($attendance_percentage < 75): ?>
                <div class="alert-box warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span class="alert-text">⚠️ Attendance below 75% - Attend <?php echo $days_to_75; ?> more <?php echo $days_to_75 == 1 ? 'class' : 'classes'; ?> to reach minimum requirement</span>
                </div>
                <?php else: ?>
                <div class="alert-box success">
                    <i class="fas fa-check-circle"></i>
                    <span class="alert-text">✅ Excellent! Your attendance is above the 75% requirement</span>
                </div>
                <?php endif; ?>

                <div class="info-cards">
                    <div class="info-card">
                        <div class="info-icon purple">
                            <i class="fas fa-bullseye"></i>
                        </div>
                        <div class="info-content">
                            <h3>To Reach 75%</h3>
                            <p><?php echo $days_to_75; ?> <?php echo $days_to_75 == 1 ? 'Class' : 'Classes'; ?></p>
                        </div>
                    </div>

                    <div class="info-card">
                        <div class="info-icon orange">
                            <i class="fas fa-fire-flame-curved"></i>
                        </div>
                        <div class="info-content">
                            <h3>Current Streak</h3>
                            <p><?php echo $current_streak; ?> Days 🔥</p>
                        </div>
                    </div>

                    <div class="info-card">
                        <div class="info-icon blue">
                            <i class="fas fa-percent"></i>
                        </div>
                        <div class="info-content">
                            <h3>Attendance Rate</h3>
                            <p><?php echo $attendance_percentage; ?>%</p>
                        </div>
                    </div>

                    <div class="info-card">
                        <div class="info-icon green">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="info-content">
                            <h3>Attended Classes</h3>
                            <p><?php echo $stats['present_count']; ?>/<?php echo $stats['total_classes']; ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Subject-wise Attendance -->
            <div class="stats-card">
                <h2 class="section-title">
                    <i class="fas fa-book"></i>
                    Subject-wise Breakdown
                </h2>

                <?php if ($subject_stats->num_rows > 0): ?>
                <div class="table-container">
                    <div class="table-wrapper">
                        <table class="subject-table">
                            <thead>
                                <tr>
                                    <th>Subject</th>
                                    <th>Total</th>
                                    <th>Present</th>
                                    <th>Absent</th>
                                    <th>Late</th>
                                    <th>Percentage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($subject = $subject_stats->fetch_assoc()): 
                                    $subject_percentage = round(($subject['present_count'] / $subject['total_classes']) * 100);
                                    $badge_class = $subject_percentage >= 85 ? 'excellent' : ($subject_percentage >= 75 ? 'good' : ($subject_percentage >= 60 ? 'warning' : 'danger'));
                                ?>
                                <tr>
                                    <td>
                                        <div class="subject-name-cell">
                                            <span class="subject-name"><?php echo htmlspecialchars($subject['subject_name']); ?></span>
                                            <span class="subject-code"><?php echo htmlspecialchars($subject['subject_code']); ?></span>
                                        </div>
                                    </td>
                                    <td><strong><?php echo $subject['total_classes']; ?></strong></td>
                                    <td><span style="color: var(--success); font-weight: 700;"><?php echo $subject['present_count']; ?></span></td>
                                    <td><span style="color: var(--danger); font-weight: 700;"><?php echo $subject['absent_count']; ?></span></td>
                                    <td><span style="color: var(--warning); font-weight: 700;"><?php echo $subject['late_count']; ?></span></td>
                                    <td>
                                        <span class="percentage-badge <?php echo $badge_class; ?>">
                                            <?php echo $subject_percentage; ?>%
                                        </span>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-book-open"></i>
                    <p>No subject data available</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Monthly Trend -->
            <div class="stats-card">
                <h2 class="section-title">
                    <i class="fas fa-chart-area"></i>
                    Monthly Attendance Trend
                </h2>

                <?php 
                $monthly_trend->data_seek(0);
                if ($monthly_trend->num_rows > 0): 
                ?>
                <div class="monthly-trend">
                    <?php while($month = $monthly_trend->fetch_assoc()): 
                        $month_percentage = round(($month['present_count'] / $month['total_classes']) * 100);
                        $month_color = $month_percentage >= 75 ? '#10b981' : '#ef4444';
                    ?>
                    <div class="month-card">
                        <div class="month-name"><?php echo $month['month_name']; ?></div>
                        <div class="month-percentage" style="color: <?php echo $month_color; ?>;">
                            <?php echo $month_percentage; ?>%
                        </div>
                        <div class="month-details">
                            <?php echo $month['present_count']; ?>/<?php echo $month['total_classes']; ?> classes
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-times"></i>
                    <p>No monthly trend data available</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Daily View Tab -->
        <div id="daily" class="tab-content">
            <div class="stats-card">
                <h2 class="section-title">
                    <i class="fas fa-calendar-day"></i>
                    Daily Attendance View
                </h2>

                <div class="date-selector">
                    <label for="attendance-date">
                        <i class="fas fa-calendar-alt"></i>
                        Select Date:
                    </label>
                    <input type="date" 
                           id="attendance-date" 
                           value="<?php echo $selected_date; ?>" 
                           max="<?php echo date('Y-m-d'); ?>"
                           onchange="changeDate(this.value)">
                    <div class="quick-date-btns">
                        <button class="quick-btn <?php echo $is_today ? 'active' : ''; ?>" 
                                onclick="changeDate('<?php echo date('Y-m-d'); ?>')">
                            <i class="fas fa-calendar-day"></i> Today
                        </button>
                        <button class="quick-btn" 
                                onclick="changeDate('<?php echo date('Y-m-d', strtotime('-1 day')); ?>')">
                            <i class="fas fa-calendar-minus"></i> Yesterday
                        </button>
                    </div>
                </div>

                <?php if (!empty($daily_array)): ?>
                    <div class="daily-stats">
                        <div class="daily-stat-card total">
                            <div class="daily-stat-icon">
                                <i class="fas fa-list-check"></i>
                            </div>
                            <div class="daily-stat-info">
                                <h3>Total Classes</h3>
                                <p><?php echo count($daily_array); ?></p>
                            </div>
                        </div>

                        <div class="daily-stat-card present">
                            <div class="daily-stat-icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="daily-stat-info">
                                <h3>Present</h3>
                                <p><?php echo $daily_present; ?></p>
                            </div>
                        </div>

                        <div class="daily-stat-card absent">
                            <div class="daily-stat-icon">
                                <i class="fas fa-times-circle"></i>
                            </div>
                            <div class="daily-stat-info">
                                <h3>Absent</h3>
                                <p><?php echo $daily_absent; ?></p>
                            </div>
                        </div>

                        <div class="daily-stat-card late">
                            <div class="daily-stat-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="daily-stat-info">
                                <h3>Late</h3>
                                <p><?php echo $daily_late; ?></p>
                            </div>
                        </div>
                    </div>

                    <h3 style="font-size: 1.3rem; font-weight: 700; color: var(--dark); margin-bottom: 20px;">
                        <i class="fas fa-calendar"></i> Attendance for <?php echo $selected_date_display; ?>
                    </h3>

                    <div class="attendance-grid">
                        <?php foreach($daily_array as $record): ?>
                        <div class="attendance-card">
                            <div class="subject-header">
                                <div class="subject-info">
                                    <div class="subject-name"><?php echo htmlspecialchars($record['subject_name']); ?></div>
                                    <span class="subject-code"><?php echo htmlspecialchars($record['subject_code']); ?></span>
                                </div>
                                <div class="time-badge">
                                    <i class="fas fa-clock"></i>
                                    <?php echo $record['marked_time']; ?>
                                </div>
                            </div>

                            <div class="teacher-info">
                                <i class="fas fa-user-tie"></i>
                                <span class="teacher-name"><?php echo htmlspecialchars($record['teacher_name']); ?></span>
                            </div>

                            <div class="status-section">
                                <?php
                                $status_icon = '';
                                $status_class = strtolower($record['status']);
                                
                                switch($record['status']) {
                                    case 'present':
                                        $status_icon = 'fa-check-circle';
                                        break;
                                    case 'absent':
                                        $status_icon = 'fa-times-circle';
                                        break;
                                    case 'late':
                                        $status_icon = 'fa-clock';
                                        break;
                                }
                                ?>
                                <span class="status-badge <?php echo $status_class; ?>">
                                    <i class="fas <?php echo $status_icon; ?>"></i>
                                    <?php echo strtoupper($record['status']); ?>
                                </span>

                                <?php if ($record['remarks']): ?>
                                    <div class="remarks-box">
                                        <div class="remarks-label">
                                            <i class="fas fa-comment-dots"></i> Remarks
                                        </div>
                                        <div class="remarks-text"><?php echo htmlspecialchars($record['remarks']); ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h2>No Attendance Recorded</h2>
                        <p>
                            <?php if ($is_today): ?>
                                Your attendance hasn't been marked yet for today. Check back later!
                            <?php else: ?>
                                No attendance records found for <?php echo $selected_date_display; ?>
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Records Tab -->
        <div id="recent" class="tab-content">
            <div class="stats-card">
                <h2 class="section-title">
                    <i class="fas fa-history"></i>
                    Recent Attendance History
                </h2>

                <?php if ($recent_records->num_rows > 0): ?>
                <div class="table-container">
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th><i class="fas fa-calendar-alt"></i> Date & Day</th>
                                    <th><i class="fas fa-book"></i> Subject</th>
                                    <th><i class="fas fa-clock"></i> Time</th>
                                    <th><i class="fas fa-user-tie"></i> Marked By</th>
                                    <th><i class="fas fa-check-circle"></i> Status</th>
                                    <th><i class="fas fa-comment"></i> Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($record = $recent_records->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <div class="date-info">
                                            <span class="date-day"><?php echo $record['day_name']; ?></span>
                                            <span class="date-full"><?php echo date('d M Y', strtotime($record['attendance_date'])); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="subject-name-cell">
                                            <span class="subject-name"><?php echo htmlspecialchars($record['subject_name']); ?></span>
                                            <span class="subject-code"><?php echo htmlspecialchars($record['subject_code']); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="time-info">
                                            <i class="fas fa-clock"></i>
                                            <span><?php echo $record['marked_time']; ?></span>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($record['teacher_name']); ?></td>
                                    <td>
                                        <?php
                                        $status_icon = '';
                                        $status_class = strtolower($record['status']);
                                        
                                        switch($record['status']) {
                                            case 'present':
                                                $status_icon = 'fa-check-circle';
                                                break;
                                            case 'absent':
                                                $status_icon = 'fa-times-circle';
                                                break;
                                            case 'late':
                                                $status_icon = 'fa-clock';
                                                break;
                                        }
                                        ?>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <i class="fas <?php echo $status_icon; ?>"></i>
                                            <?php echo strtoupper($record['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $record['remarks'] ? htmlspecialchars($record['remarks']) : '-'; ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h2>No Records Found</h2>
                    <p>You don't have any attendance records yet.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function openTab(tabName) {
            // Hide all tab contents
            const tabContents = document.getElementsByClassName('tab-content');
            for (let i = 0; i < tabContents.length; i++) {
                tabContents[i].classList.remove('active');
            }

            // Remove active class from all buttons
            const tabButtons = document.getElementsByClassName('tab-btn');
            for (let i = 0; i < tabButtons.length; i++) {
                tabButtons[i].classList.remove('active');
            }

            // Show selected tab and mark button as active
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
        }

        function changeDate(date) {
            window.location.href = '?date=' + date;
        }

        // Update active date button
        document.addEventListener('DOMContentLoaded', function() {
            const dateInput = document.getElementById('attendance-date');
            if (dateInput) {
                const selectedDate = dateInput.value;
                const today = '<?php echo date('Y-m-d'); ?>';
                const yesterday = '<?php echo date('Y-m-d', strtotime('-1 day')); ?>';

                document.querySelectorAll('.quick-btn').forEach(btn => {
                    btn.classList.remove('active');
                });

                if (selectedDate === today) {
                    document.querySelectorAll('.quick-btn')[0].classList.add('active');
                } else if (selectedDate === yesterday) {
                    document.querySelectorAll('.quick-btn')[1].classList.add('active');
                }
            }
        });
    </script>
</body>
</html>
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

// Calculate overall attendance statistics - Count unique days
$stats_query = "SELECT 
                    COUNT(DISTINCT attendance_date) as total_days,
                    COUNT(DISTINCT CASE 
                        WHEN status = 'present' THEN attendance_date 
                    END) as present_days,
                    COUNT(DISTINCT CASE 
                        WHEN status = 'absent' AND attendance_date NOT IN (
                            SELECT DISTINCT attendance_date FROM attendance 
                            WHERE student_id = ? AND status = 'present'";

if ($current_sem) {
    $stats_query .= " AND semester_id = ?";
}

$stats_query .= "        ) THEN attendance_date 
                    END) as absent_days,
                    COUNT(DISTINCT CASE 
                        WHEN status = 'late' AND attendance_date NOT IN (
                            SELECT DISTINCT attendance_date FROM attendance 
                            WHERE student_id = ? AND status IN ('present', 'absent')";

if ($current_sem) {
    $stats_query .= " AND semester_id = ?";
}

$stats_query .= "        ) THEN attendance_date 
                    END) as late_days
                FROM attendance 
                WHERE student_id = ?";

if ($current_sem) {
    $stats_query .= " AND semester_id = ?";
}

if ($current_sem) {
    $stmt = $conn->prepare($stats_query);
    $stmt->bind_param("iiiiii", 
        $student['student_id'], $current_sem['semester_id'],
        $student['student_id'], $current_sem['semester_id'],
        $student['student_id'], $current_sem['semester_id']
    );
} else {
    $stmt = $conn->prepare($stats_query);
    $stmt->bind_param("iii", 
        $student['student_id'],
        $student['student_id'],
        $student['student_id']
    );
}

$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();

// Calculate percentage based on days
$total_days = $stats['total_days'] > 0 ? $stats['total_days'] : 1;
$attendance_percentage = round(($stats['present_days'] / $total_days) * 100);

// Calculate days needed to reach 75%
$days_to_75 = 0;
if ($attendance_percentage < 75) {
    $current_present = $stats['present_days'];
    $current_total = $total_days;
    while ((($current_present / $current_total) * 100) < 75) {
        $current_present++;
        $current_total++;
        $days_to_75++;
    }
}

// Calculate current streak
$streak_query = "SELECT attendance_date, 
                 CASE 
                     WHEN MAX(CASE WHEN status = 'present' THEN 1 ELSE 0 END) = 1 THEN 'present'
                     ELSE 'absent'
                 END as day_status
                 FROM attendance 
                 WHERE student_id = ? 
                 GROUP BY attendance_date
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
        $last_status = $row['day_status'];
        if ($last_status == 'present') {
            $current_streak = 1;
        }
    } else {
        if ($row['day_status'] == 'present' && $last_status == 'present') {
            $current_streak++;
        } else {
            break;
        }
    }
}

// Get subject-wise attendance - still show class-level for subjects
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

// Get monthly attendance trend - by days
$monthly_trend_query = "SELECT 
                        DATE_FORMAT(attendance_date, '%Y-%m') as month,
                        DATE_FORMAT(attendance_date, '%b %Y') as month_name,
                        COUNT(DISTINCT attendance_date) as total_days,
                        COUNT(DISTINCT CASE WHEN status = 'present' THEN attendance_date END) as present_days
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

// Get weekly pattern (day-wise) - by days
$weekly_pattern_query = "SELECT 
                         DAYNAME(attendance_date) as day_name,
                         DAYOFWEEK(attendance_date) as day_num,
                         COUNT(DISTINCT attendance_date) as total_days,
                         COUNT(DISTINCT CASE WHEN status = 'present' THEN attendance_date END) as present_days
                         FROM attendance
                         WHERE student_id = ?";

if ($current_sem) {
    $weekly_pattern_query .= " AND semester_id = ?";
}

$weekly_pattern_query .= " GROUP BY day_name, day_num
                          ORDER BY day_num";

if ($current_sem) {
    $stmt = $conn->prepare($weekly_pattern_query);
    $stmt->bind_param("ii", $student['student_id'], $current_sem['semester_id']);
} else {
    $stmt = $conn->prepare($weekly_pattern_query);
    $stmt->bind_param("i", $student['student_id']);
}

$stmt->execute();
$weekly_pattern = $stmt->get_result();

// Get best and worst attendance month - by days
$best_worst_query = "SELECT 
                     DATE_FORMAT(attendance_date, '%b %Y') as month_name,
                     COUNT(DISTINCT attendance_date) as total_days,
                     COUNT(DISTINCT CASE WHEN status = 'present' THEN attendance_date END) as present_days,
                     ROUND((COUNT(DISTINCT CASE WHEN status = 'present' THEN attendance_date END) / COUNT(DISTINCT attendance_date)) * 100) as percentage
                     FROM attendance
                     WHERE student_id = ?";

if ($current_sem) {
    $best_worst_query .= " AND semester_id = ?";
}

$best_worst_query .= " GROUP BY DATE_FORMAT(attendance_date, '%Y-%m')
                      HAVING total_days >= 5
                      ORDER BY percentage DESC";

if ($current_sem) {
    $stmt = $conn->prepare($best_worst_query);
    $stmt->bind_param("ii", $student['student_id'], $current_sem['semester_id']);
} else {
    $stmt = $conn->prepare($best_worst_query);
    $stmt->bind_param("i", $student['student_id']);
}

$stmt->execute();
$month_performance = $stmt->get_result();
$months_array = [];
while($month = $month_performance->fetch_assoc()) {
    $months_array[] = $month;
}

$best_month = !empty($months_array) ? $months_array[0] : null;
$worst_month = !empty($months_array) ? end($months_array) : null;

// Get roll number
$roll_query = "SELECT roll_number_display FROM student_roll_numbers 
               WHERE student_id = ? AND is_active = 1 LIMIT 1";
$stmt = $conn->prepare($roll_query);
$stmt->bind_param("i", $student['student_id']);
$stmt->execute();
$roll_result = $stmt->get_result()->fetch_assoc();
$roll_number = $roll_result ? $roll_result['roll_number_display'] : 'N/A';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Overall Statistics - College ERP</title>
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

        .stats-card {
            background: var(--white);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            margin-bottom: 30px;
        }

        .stats-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .stats-title {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 2rem;
            font-weight: 800;
            color: var(--dark);
        }

        .stats-title i {
            color: var(--purple);
        }

        .student-info-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: var(--white);
            padding: 25px;
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

        .stats-content {
            display: grid;
            grid-template-columns: 1fr 2fr;
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

        .stats-bars {
            display: flex;
            flex-direction: column;
            gap: 30px;
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
            font-size: 0.9rem;
            color: var(--gray);
            font-weight: 600;
        }

        .stat-label i {
            color: var(--purple);
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
            height: 12px;
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

        .alert-box {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border-left: 4px solid var(--warning);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .alert-box.success {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            border-left-color: var(--success);
        }

        .alert-box i {
            font-size: 1.5rem;
        }

        .alert-box.success i {
            color: var(--success);
        }

        .alert-box i {
            color: var(--warning);
        }

        .alert-text {
            font-weight: 600;
            color: #92400e;
        }

        .alert-box.success .alert-text {
            color: #065f46;
        }

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
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
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
        }

        .info-content p {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--dark);
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: var(--purple);
        }

        .subject-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 40px;
        }

        .subject-table thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .subject-table th {
            color: var(--white);
            padding: 15px;
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
            padding: 15px;
            color: var(--dark);
        }

        .subject-name-cell {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .subject-name {
            font-weight: 700;
        }

        .subject-code {
            font-size: 0.8rem;
            color: var(--gray);
            background: var(--light-gray);
            padding: 4px 10px;
            border-radius: 8px;
            display: inline-block;
            width: fit-content;
        }

        .percentage-badge {
            padding: 6px 14px;
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

        .monthly-trend {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 40px;
        }

        .month-card {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 20px;
            border-radius: 12px;
            text-align: center;
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

        .weekly-pattern {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
            margin-bottom: 40px;
        }

        .day-card {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            transition: all 0.3s;
        }

        .day-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }

        .day-name {
            font-size: 0.85rem;
            color: var(--gray);
            margin-bottom: 10px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .day-percentage {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .day-classes {
            font-size: 0.75rem;
            color: var(--gray);
        }

        .performance-cards {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 40px;
        }

        .performance-card {
            padding: 25px;
            border-radius: 15px;
            color: var(--white);
        }

        .performance-card.best {
            background: linear-gradient(135deg, #10b981, #34d399);
        }

        .performance-card.worst {
            background: linear-gradient(135deg, #ef4444, #f87171);
        }

        .performance-card h3 {
            font-size: 1rem;
            margin-bottom: 15px;
            opacity: 0.9;
        }

        .performance-month {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .performance-stats {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        @media (max-width: 1200px) {
            .stats-content {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            body {
                padding: 15px;
            }

            .stats-card {
                padding: 20px;
            }

            .stats-title {
                font-size: 1.5rem;
            }

            .progress-ring {
                width: 250px;
                height: 250px;
            }

            .progress-percentage {
                font-size: 3rem;
            }

            .performance-cards {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>

        <div class="stats-card">
            <div class="stats-header">
                <h1 class="stats-title">
                    <i class="fas fa-chart-line"></i>
                    Overall Statistics & Analytics
                </h1>
            </div>

            <div class="student-info-banner">
                <div class="student-details">
                    <div class="detail-item">
                        <div class="detail-label">Student Name</div>
                        <div class="detail-value"><?php echo htmlspecialchars($student['full_name']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Roll Number</div>
                        <div class="detail-value"><?php echo htmlspecialchars($roll_number); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Department</div>
                        <div class="detail-value"><?php echo htmlspecialchars($student['department_name']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Course</div>
                        <div class="detail-value"><?php echo htmlspecialchars($student['course_name']); ?></div>
                    </div>
                </div>
                <?php if ($current_sem): ?>
                <div class="semester-badge">
                    <i class="fas fa-book"></i> <?php echo htmlspecialchars($current_sem['semester_name']); ?>
                    <?php if ($current_sem['section_name']): ?>
                        - <?php echo htmlspecialchars($current_sem['section_name']); ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Overall Statistics -->
            <div class="stats-content">
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
                                <i class="fas fa-calendar"></i> Total Days
                            </span>
                            <span class="stat-value blue"><?php echo $stats['total_days']; ?></span>
                        </div>
                    </div>

                    <div class="stat-item">
                        <div class="stat-header">
                            <span class="stat-label">
                                <i class="fas fa-check-circle"></i> Present Days
                            </span>
                            <span class="stat-value green"><?php echo $stats['present_days']; ?></span>
                        </div>
                        <div class="progress-bar-container">
                            <div class="progress-bar-fill green" style="width: <?php echo ($stats['present_days'] / $total_days * 100); ?>%"></div>
                        </div>
                    </div>

                    <div class="stat-item">
                        <div class="stat-header">
                            <span class="stat-label">
                                <i class="fas fa-times-circle"></i> Absent Days
                            </span>
                            <span class="stat-value red"><?php echo $stats['absent_days']; ?></span>
                        </div>
                        <div class="progress-bar-container">
                            <div class="progress-bar-fill red" style="width: <?php echo ($stats['absent_days'] / $total_days * 100); ?>%"></div>
                        </div>
                    </div>

                    <div class="stat-item">
                        <div class="stat-header">
                            <span class="stat-label">
                                <i class="fas fa-clock"></i> Late Days
                            </span>
                            <span class="stat-value orange"><?php echo $stats['late_days']; ?></span>
                        </div>
                        <div class="progress-bar-container">
                            <div class="progress-bar-fill gray" style="width: <?php echo ($stats['late_days'] / $total_days * 100); ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($attendance_percentage < 75): ?>
            <div class="alert-box">
                <i class="fas fa-exclamation-circle"></i>
                <span class="alert-text">⚠️ Attendance below 75% - Attend <?php echo $days_to_75; ?> more days to reach the minimum requirement</span>
            </div>
            <?php else: ?>
            <div class="alert-box success">
                <i class="fas fa-check-circle"></i>
                <span class="alert-text">✅ Excellent! Your attendance is above the minimum requirement of 75%</span>
            </div>
            <?php endif; ?>

            <!-- Quick Stats Cards -->
            <div class="info-cards">
                <div class="info-card">
                    <div class="info-icon purple">
                        <i class="fas fa-target"></i>
                    </div>
                    <div class="info-content">
                        <h3>Days to 75%</h3>
                        <p><?php echo $days_to_75; ?> <?php echo $days_to_75 == 1 ? 'day' : 'days'; ?></p>
                    </div>
                </div>

                <div class="info-card">
                    <div class="info-icon orange">
                        <i class="fas fa-fire"></i>
                    </div>
                    <div class="info-content">
                        <h3>Current Streak</h3>
                        <p><?php echo $current_streak; ?> days 🔥</p>
                    </div>
                </div>

                <div class="info-card">
                    <div class="info-icon blue">
                        <i class="fas fa-percentage"></i>
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
                        <h3>Days Attended</h3>
                        <p><?php echo $stats['present_days']; ?>/<?php echo $stats['total_days']; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Subject-wise Attendance -->
        <div class="stats-card">
            <h2 class="section-title">
                <i class="fas fa-book-open"></i>
                Subject-wise Attendance
            </h2>

            <?php if ($subject_stats->num_rows > 0): ?>
            <table class="subject-table">
                <thead>
                    <tr>
                        <th>Subject</th>
                        <th>Total Classes</th>
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
            <?php else: ?>
            <p style="text-align: center; color: var(--gray); padding: 40px;">No subject data available</p>
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
                    $month_percentage = round(($month['present_days'] / $month['total_days']) * 100);
                    $month_color = $month_percentage >= 75 ? '#10b981' : '#ef4444';
                ?>
                <div class="month-card">
                    <div class="month-name"><?php echo $month['month_name']; ?></div>
                    <div class="month-percentage" style="color: <?php echo $month_color; ?>;">
                        <?php echo $month_percentage; ?>%
                    </div>
                    <div class="month-details">
                        <?php echo $month['present_days']; ?>/<?php echo $month['total_days']; ?> days
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
            <?php else: ?>
            <p style="text-align: center; color: var(--gray); padding: 40px;">No monthly trend data available</p>
            <?php endif; ?>
        </div>

        <!-- Weekly Pattern -->
        <div class="stats-card">
            <h2 class="section-title">
                <i class="fas fa-calendar-week"></i>
                Weekly Attendance Pattern
            </h2>

            <?php if ($weekly_pattern->num_rows > 0): ?>
            <div class="weekly-pattern">
                <?php while($day = $weekly_pattern->fetch_assoc()): 
                    $day_percentage = round(($day['present_days'] / $day['total_days']) * 100);
                    $day_color = $day_percentage >= 75 ? '#10b981' : '#ef4444';
                ?>
                <div class="day-card">
                    <div class="day-name"><?php echo $day['day_name']; ?></div>
                    <div class="day-percentage" style="color: <?php echo $day_color; ?>;">
                        <?php echo $day_percentage; ?>%
                    </div>
                    <div class="day-classes">
                        <?php echo $day['present_days']; ?>/<?php echo $day['total_days']; ?> days
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
            <?php else: ?>
            <p style="text-align: center; color: var(--gray); padding: 40px;">No weekly pattern data available</p>
            <?php endif; ?>
        </div>

        <!-- Best and Worst Performance -->
        <?php if ($best_month && $worst_month && count($months_array) > 1): ?>
        <div class="stats-card">
            <h2 class="section-title">
                <i class="fas fa-trophy"></i>
                Performance Highlights
            </h2>

            <div class="performance-cards">
                <div class="performance-card best">
                    <h3><i class="fas fa-award"></i> Best Month</h3>
                    <div class="performance-month"><?php echo $best_month['month_name']; ?></div>
                    <div class="performance-stats">
                        <?php echo $best_month['percentage']; ?>% attendance
                        (<?php echo $best_month['present_days']; ?>/<?php echo $best_month['total_days']; ?> days)
                    </div>
                </div>

                <div class="performance-card worst">
                    <h3><i class="fas fa-chart-line-down"></i> Needs Improvement</h3>
                    <div class="performance-month"><?php echo $worst_month['month_name']; ?></div>
                    <div class="performance-stats">
                        <?php echo $worst_month['percentage']; ?>% attendance
                        (<?php echo $worst_month['present_days']; ?>/<?php echo $worst_month['total_days']; ?> days)
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
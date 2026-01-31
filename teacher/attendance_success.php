<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('teacher')) {
    redirect('login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get teacher details
try {
    $sql = "SELECT t.* FROM teachers t WHERE t.user_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $teacher = $stmt->get_result()->fetch_assoc();
    
    if (!$teacher) {
        die("Teacher profile not found.");
    }
} catch (Exception $e) {
    die("Error fetching teacher: " . $e->getMessage());
}

// Get and validate parameters from URL
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;
$semester_id = isset($_GET['semester_id']) ? intval($_GET['semester_id']) : 0;
$section_id = isset($_GET['section_id']) && $_GET['section_id'] !== '' ? intval($_GET['section_id']) : null;
$attendance_date = isset($_GET['date']) ? $_GET['date'] : '';
$academic_year = isset($_GET['academic_year']) ? $_GET['academic_year'] : '';

// Validate required parameters
if (!$subject_id || !$semester_id || !$attendance_date) {
    header("Location: mark_attendance.php?error=missing_params");
    exit();
}

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $attendance_date)) {
    header("Location: mark_attendance.php?error=invalid_date");
    exit();
}

// Initialize variables
$subject_info = null;
$students = null;
$total_students = 0;
$present_count = 0;
$absent_count = 0;
$present_percentage = 0;
$absent_percentage = 0;

try {
    // Get subject info
    $subject_info_sql = "SELECT sub.subject_name, sub.subject_code, sem.semester_name, sec.section_name
                         FROM subjects sub
                         JOIN semesters sem ON sub.semester_id = sem.semester_id
                         LEFT JOIN sections sec ON sec.section_id = ?
                         WHERE sub.subject_id = ?";
    $subject_info_stmt = $conn->prepare($subject_info_sql);
    if (!$subject_info_stmt) {
        throw new Exception("Failed to prepare subject query: " . $conn->error);
    }
    $subject_info_stmt->bind_param("ii", $section_id, $subject_id);
    $subject_info_stmt->execute();
    $subject_info = $subject_info_stmt->get_result()->fetch_assoc();
    
    if (!$subject_info) {
        throw new Exception("Subject information not found");
    }

    // Get attendance data with student details (REMOVED marked_at column)
    $attendance_sql = "SELECT s.student_id, s.full_name, s.admission_number,
                       srn.roll_number_display, a.status
                       FROM students s
                       JOIN student_semesters ss ON s.student_id = ss.student_id
                       LEFT JOIN student_roll_numbers srn ON s.student_id = srn.student_id 
                           AND srn.semester_id = ss.semester_id 
                           AND srn.section_id = ss.section_id
                           AND srn.academic_year = ss.academic_year
                       LEFT JOIN attendance a ON s.student_id = a.student_id 
                           AND a.subject_id = ? 
                           AND a.attendance_date = ?
                       WHERE ss.semester_id = ?
                       AND ss.is_active = 1";

    if ($section_id) {
        $attendance_sql .= " AND ss.section_id = ?";
    }

    $attendance_sql .= " ORDER BY srn.roll_number, s.full_name";

    $attendance_stmt = $conn->prepare($attendance_sql);
    if (!$attendance_stmt) {
        throw new Exception("Failed to prepare attendance query: " . $conn->error);
    }
    
    if ($section_id) {
        $attendance_stmt->bind_param("isii", $subject_id, $attendance_date, $semester_id, $section_id);
    } else {
        $attendance_stmt->bind_param("isi", $subject_id, $attendance_date, $semester_id);
    }
    $attendance_stmt->execute();
    $students = $attendance_stmt->get_result();

    // Calculate statistics
    if ($students && $students->num_rows > 0) {
        $students->data_seek(0);
        while($stat = $students->fetch_assoc()) {
            $total_students++;
            if ($stat['status'] == 'present') {
                $present_count++;
            } elseif ($stat['status'] == 'absent') {
                $absent_count++;
            }
        }
    }

    if ($total_students > 0) {
        $present_percentage = round(($present_count / $total_students) * 100, 1);
        $absent_percentage = round(($absent_count / $total_students) * 100, 1);
    }

} catch (Exception $e) {
    die("Database Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Marked Successfully</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --secondary: #8b5cf6;
            --success: #22c55e;
            --success-dark: #16a34a;
            --danger: #ef4444;
            --dark: #0f172a;
            --gray: #64748b;
            --light: #f8fafc;
            --white: #ffffff;
            --border: #e2e8f0;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .container {
            max-width: 900px;
            width: 100%;
        }

        @keyframes successPulse {
            0% {
                transform: scale(0);
                opacity: 0;
            }
            50% {
                transform: scale(1.1);
            }
            100% {
                transform: scale(1);
                opacity: 1;
            }
        }

        @keyframes checkmark {
            0% {
                stroke-dashoffset: 100;
            }
            100% {
                stroke-dashoffset: 0;
            }
        }

        .success-card {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            border-radius: 32px;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            animation: successPulse 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .success-header {
            background: linear-gradient(135deg, var(--success), var(--success-dark));
            padding: 3rem 2rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .success-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: ripple 3s ease-out infinite;
        }

        @keyframes ripple {
            0% {
                transform: scale(0.8);
                opacity: 1;
            }
            100% {
                transform: scale(1.2);
                opacity: 0;
            }
        }

        .success-icon {
            width: 120px;
            height: 120px;
            margin: 0 auto 1.5rem;
            position: relative;
            z-index: 1;
        }

        .success-icon svg {
            width: 100%;
            height: 100%;
        }

        .checkmark-circle {
            stroke: var(--white);
            stroke-width: 3;
            fill: none;
            stroke-dasharray: 314;
            stroke-dashoffset: 314;
            animation: checkmark 0.6s ease-out 0.2s forwards;
        }

        .checkmark-check {
            stroke: var(--white);
            stroke-width: 4;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
            stroke-dasharray: 80;
            stroke-dashoffset: 80;
            animation: checkmark 0.4s ease-out 0.6s forwards;
        }

        .success-title {
            font-size: 2rem;
            font-weight: 900;
            color: var(--white);
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
        }

        .success-subtitle {
            font-size: 1.1rem;
            color: rgba(255, 255, 255, 0.9);
            font-weight: 500;
            position: relative;
            z-index: 1;
        }

        .info-section {
            padding: 2rem;
            background: var(--light);
        }

        .class-info {
            background: var(--white);
            border-radius: 20px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border);
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: var(--gray);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-label i {
            color: var(--primary);
        }

        .info-value {
            font-weight: 700;
            color: var(--dark);
            font-size: 1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-box {
            background: var(--white);
            border-radius: 16px;
            padding: 1.25rem;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            position: relative;
            overflow: hidden;
        }

        .stat-box::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }

        .stat-box.total::before {
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }

        .stat-box.present::before {
            background: var(--success);
        }

        .stat-box.absent::before {
            background: var(--danger);
        }

        .stat-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .stat-box.total .stat-icon {
            color: var(--primary);
        }

        .stat-box.present .stat-icon {
            color: var(--success);
        }

        .stat-box.absent .stat-icon {
            color: var(--danger);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 900;
            margin-bottom: 0.25rem;
        }

        .stat-box.total .stat-number {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .stat-box.present .stat-number {
            color: var(--success);
        }

        .stat-box.absent .stat-number {
            color: var(--danger);
        }

        .stat-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-percent {
            font-size: 0.95rem;
            font-weight: 700;
            margin-top: 0.25rem;
        }

        .stat-box.present .stat-percent {
            color: var(--success);
        }

        .stat-box.absent .stat-percent {
            color: var(--danger);
        }

        .action-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .action-btn {
            padding: 1.25rem;
            border-radius: 16px;
            border: none;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            text-decoration: none;
            font-family: 'Poppins', sans-serif;
        }

        .btn-update {
            background: linear-gradient(135deg, #f59e0b, #f97316);
            color: var(--white);
            box-shadow: 0 6px 20px rgba(245, 158, 11, 0.35);
        }

        .btn-update:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(245, 158, 11, 0.45);
        }

        .btn-back {
            background: var(--white);
            color: var(--dark);
            border: 2px solid var(--border);
        }

        .btn-back:hover {
            background: var(--light);
            transform: translateY(-2px);
        }

        .action-btn i {
            font-size: 1.25rem;
        }

        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }

            .success-header {
                padding: 2rem 1.5rem;
            }

            .success-icon {
                width: 100px;
                height: 100px;
            }

            .success-title {
                font-size: 1.5rem;
            }

            .success-subtitle {
                font-size: 1rem;
            }

            .info-section {
                padding: 1.5rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 0.875rem;
            }

            .action-buttons {
                grid-template-columns: 1fr;
            }

            .info-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="success-card">
            <div class="success-header">
                <div class="success-icon">
                    <svg viewBox="0 0 100 100">
                        <circle class="checkmark-circle" cx="50" cy="50" r="45"/>
                        <path class="checkmark-check" d="M25,50 L40,65 L75,30"/>
                    </svg>
                </div>
                <h1 class="success-title">✓ Attendance Marked!</h1>
                <p class="success-subtitle">Successfully saved attendance records</p>
            </div>

            <div class="info-section">
                <div class="class-info">
                    <div class="info-row">
                        <span class="info-label">
                            <i class="fas fa-book"></i>
                            Subject
                        </span>
                        <span class="info-value">
                            <?php echo htmlspecialchars($subject_info['subject_code'] . ' - ' . $subject_info['subject_name']); ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">
                            <i class="fas fa-graduation-cap"></i>
                            Class
                        </span>
                        <span class="info-value">
                            <?php 
                            echo htmlspecialchars($subject_info['semester_name']);
                            if (!empty($subject_info['section_name'])) {
                                echo ' - ' . htmlspecialchars($subject_info['section_name']);
                            }
                            ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">
                            <i class="fas fa-calendar"></i>
                            Date
                        </span>
                        <span class="info-value">
                            <?php echo date('F j, Y', strtotime($attendance_date)); ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">
                            <i class="fas fa-clock"></i>
                            Time
                        </span>
                        <span class="info-value">
                            <?php echo date('g:i A'); ?>
                        </span>
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-box total">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-number"><?php echo $total_students; ?></div>
                        <div class="stat-label">Total Students</div>
                    </div>
                    <div class="stat-box present">
                        <div class="stat-icon">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <div class="stat-number"><?php echo $present_count; ?></div>
                        <div class="stat-label">Present</div>
                        <div class="stat-percent"><?php echo $present_percentage; ?>%</div>
                    </div>
                    <div class="stat-box absent">
                        <div class="stat-icon">
                            <i class="fas fa-user-times"></i>
                        </div>
                        <div class="stat-number"><?php echo $absent_count; ?></div>
                        <div class="stat-label">Absent</div>
                        <div class="stat-percent"><?php echo $absent_percentage; ?>%</div>
                    </div>
                </div>

                <div class="action-buttons">
                    <a href="update_attendance.php?subject_id=<?php echo $subject_id; ?>&semester_id=<?php echo $semester_id; ?>&section_id=<?php echo ($section_id ?? ''); ?>&date=<?php echo $attendance_date; ?>&academic_year=<?php echo urlencode($academic_year); ?>" class="action-btn btn-update">
                        <i class="fas fa-edit"></i>
                        <span>Update Attendance</span>
                    </a>
                    <a href="mark_attendance.php?academic_year=<?php echo urlencode($academic_year); ?>" class="action-btn btn-back">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back to Dashboard</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
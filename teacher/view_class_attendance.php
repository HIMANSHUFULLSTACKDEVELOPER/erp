<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('teacher')) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

// Get teacher details
$sql = "SELECT t.* FROM teachers t WHERE t.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();

if (!$teacher) {
    die("Teacher profile not found.");
}

// Get available academic years
$academic_years_sql = "SELECT DISTINCT academic_year 
                       FROM subject_teachers 
                       WHERE teacher_id = ?
                       ORDER BY academic_year DESC";
$stmt = $conn->prepare($academic_years_sql);
$stmt->bind_param("i", $teacher['teacher_id']);
$stmt->execute();
$academic_years_result = $stmt->get_result();

// Get default academic year (most recent)
$default_year = null;
if ($academic_years_result->num_rows > 0) {
    $first_year = $academic_years_result->fetch_assoc();
    $default_year = $first_year['academic_year'];
    $academic_years_result->data_seek(0);
}

// Get selected filters
$selected_academic_year = $_GET['academic_year'] ?? $default_year ?? date('Y') . '-' . (date('Y') + 1);
$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_subject_id = $_GET['subject_id'] ?? null;
$selected_semester_id = $_GET['semester_id'] ?? null;
$selected_section_id = $_GET['section_id'] ?? null;

// Get assigned subjects for dropdown
$subjects_sql = "SELECT DISTINCT st.subject_id, sub.subject_name, sub.subject_code, 
                 st.semester_id, sem.semester_name, st.section_id, sec.section_name, st.academic_year
                 FROM subject_teachers st
                 JOIN subjects sub ON st.subject_id = sub.subject_id
                 JOIN semesters sem ON st.semester_id = sem.semester_id
                 LEFT JOIN sections sec ON st.section_id = sec.section_id
                 WHERE st.teacher_id = ? AND st.academic_year = ?
                 ORDER BY semester_name, subject_name";

$stmt = $conn->prepare($subjects_sql);
$stmt->bind_param("is", $teacher['teacher_id'], $selected_academic_year);
$stmt->execute();
$assigned_subjects = $stmt->get_result();

// Get attendance records based on filters
$attendance_records = [];
$selected_subject_info = null;

if ($selected_subject_id && $selected_semester_id) {
    // Get subject info
    $subject_info_sql = "SELECT sub.subject_name, sub.subject_code, sem.semester_name, sec.section_name
                         FROM subjects sub
                         JOIN semesters sem ON sub.semester_id = sem.semester_id
                         LEFT JOIN sections sec ON sec.section_id = ?
                         WHERE sub.subject_id = ?";
    $subject_info_stmt = $conn->prepare($subject_info_sql);
    $subject_info_stmt->bind_param("ii", $selected_section_id, $selected_subject_id);
    $subject_info_stmt->execute();
    $selected_subject_info = $subject_info_stmt->get_result()->fetch_assoc();

    // Get attendance records for selected date and subject
    $attendance_sql = "SELECT a.*, s.admission_number, s.full_name,
                       srn.roll_number_display,
                       t.full_name as marked_by_name,
                       a.created_at as marked_time
                       FROM attendance a
                       JOIN students s ON a.student_id = s.student_id
                       LEFT JOIN student_roll_numbers srn ON s.student_id = srn.student_id 
                           AND srn.semester_id = a.semester_id 
                           AND srn.section_id = a.section_id
                       LEFT JOIN teachers t ON a.marked_by = t.teacher_id
                       WHERE a.subject_id = ? 
                       AND a.semester_id = ?
                       AND a.attendance_date = ?";
    
    if ($selected_section_id) {
        $attendance_sql .= " AND a.section_id = ?";
    }
    
    $attendance_sql .= " ORDER BY srn.roll_number, s.full_name";
    
    $attendance_stmt = $conn->prepare($attendance_sql);
    if ($selected_section_id) {
        $attendance_stmt->bind_param("iisi", $selected_subject_id, $selected_semester_id, $selected_date, $selected_section_id);
    } else {
        $attendance_stmt->bind_param("iis", $selected_subject_id, $selected_semester_id, $selected_date);
    }
    $attendance_stmt->execute();
    $attendance_records = $attendance_stmt->get_result();
}

// Calculate statistics
$total_students = 0;
$present_count = 0;
$absent_count = 0;
$late_count = 0;

if ($attendance_records && $attendance_records->num_rows > 0) {
    $attendance_records->data_seek(0);
    while($record = $attendance_records->fetch_assoc()) {
        $total_students++;
        if ($record['status'] == 'present') {
            $present_count++;
        } elseif ($record['status'] == 'absent') {
            $absent_count++;
        } elseif ($record['status'] == 'late') {
            $late_count++;
        }
    }
}

$present_percentage = $total_students > 0 ? round(($present_count / $total_students) * 100, 1) : 0;
$absent_percentage = $total_students > 0 ? round(($absent_count / $total_students) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Attendance - College ERP</title>
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
            --primary-light: #818cf8;
            --secondary: #8b5cf6;
            --accent: #ec4899;
            --success: #22c55e;
            --success-dark: #16a34a;
            --danger: #ef4444;
            --danger-dark: #dc2626;
            --warning: #f59e0b;
            --info: #3b82f6;
            --dark: #0f172a;
            --gray: #64748b;
            --gray-light: #cbd5e1;
            --light: #f8fafc;
            --white: #ffffff;
            --border: #e2e8f0;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: var(--dark);
            padding: 1rem;
        }

        .main-container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Header */
        .page-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .header-title {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .header-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            color: var(--white);
            box-shadow: 0 8px 20px rgba(99, 102, 241, 0.4);
        }

        .header-text h1 {
            font-size: 1.75rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.25rem;
        }

        .header-text p {
            color: var(--gray);
            font-size: 0.9rem;
            font-weight: 500;
        }

        .back-button {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            padding: 0.875rem 1.75rem;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.625rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
        }

        .back-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(99, 102, 241, 0.4);
        }

        /* Filter Card */
        .filter-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15);
        }

        .filter-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .filter-header i {
            font-size: 1.5rem;
            color: var(--primary);
        }

        .filter-header h2 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--dark);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.25rem;
        }

        .form-field {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-field label {
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-field label i {
            color: var(--primary);
        }

        .form-input {
            padding: 0.875rem 1rem;
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 0.95rem;
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            transition: all 0.3s ease;
            background: var(--white);
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }

        select.form-input {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%236366f1' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.875rem center;
            background-size: 1.25rem;
            padding-right: 3rem;
        }

        /* Stats Cards */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
        }

        .stat-card.total::before {
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }

        .stat-card.present::before {
            background: linear-gradient(90deg, var(--success), var(--success-dark));
        }

        .stat-card.absent::before {
            background: linear-gradient(90deg, var(--danger), var(--danger-dark));
        }

        .stat-card.late::before {
            background: linear-gradient(90deg, var(--warning), #d97706);
        }

        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            margin-bottom: 1rem;
        }

        .stat-card.total .stat-icon {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.15), rgba(139, 92, 246, 0.15));
            color: var(--primary);
        }

        .stat-card.present .stat-icon {
            background: rgba(34, 197, 94, 0.15);
            color: var(--success);
        }

        .stat-card.absent .stat-icon {
            background: rgba(239, 68, 68, 0.15);
            color: var(--danger);
        }

        .stat-card.late .stat-icon {
            background: rgba(245, 158, 11, 0.15);
            color: var(--warning);
        }

        .stat-value {
            font-size: 2.25rem;
            font-weight: 900;
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .stat-card.total .stat-value {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .stat-card.present .stat-value {
            color: var(--success);
        }

        .stat-card.absent .stat-value {
            color: var(--danger);
        }

        .stat-card.late .stat-value {
            color: var(--warning);
        }

        .stat-label {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-percentage {
            font-size: 1.125rem;
            font-weight: 700;
            margin-top: 0.5rem;
        }

        .stat-card.present .stat-percentage {
            color: var(--success);
        }

        .stat-card.absent .stat-percentage {
            color: var(--danger);
        }

        /* Attendance Table Card */
        .attendance-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15);
            overflow: hidden;
        }

        .attendance-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            padding: 1.75rem 2rem;
            color: var(--white);
        }

        .attendance-header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .attendance-title {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .attendance-title h2 {
            font-size: 1.5rem;
            font-weight: 800;
        }

        .subject-badge {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            padding: 0.5rem 1.25rem;
            border-radius: 50px;
            font-weight: 700;
            font-size: 0.875rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .date-badge {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            padding: 0.5rem 1.25rem;
            border-radius: 50px;
            font-weight: 700;
            font-size: 0.875rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Table Styles */
        .table-container {
            padding: 1.5rem;
            max-height: 60vh;
            overflow-y: auto;
        }

        .attendance-table {
            width: 100%;
            border-collapse: collapse;
        }

        .attendance-table thead th {
            background: var(--primary);
            color: var(--white);
            padding: 1rem;
            text-align: left;
            font-weight: 700;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .attendance-table tbody tr {
            border-bottom: 2px solid var(--border);
            transition: all 0.3s ease;
        }

        .attendance-table tbody tr:hover {
            background: var(--light);
            transform: translateX(4px);
        }

        .attendance-table tbody td {
            padding: 1.25rem 1rem;
        }

        .roll-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 12px;
            font-weight: 900;
            font-size: 1rem;
            color: var(--white);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }

        .student-name {
            font-weight: 700;
            font-size: 1.05rem;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .student-id {
            font-size: 0.8rem;
            color: var(--gray);
            font-weight: 500;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border-radius: 50px;
            font-weight: 700;
            font-size: 0.875rem;
            text-transform: uppercase;
        }

        .status-badge.present {
            background: rgba(34, 197, 94, 0.15);
            color: var(--success);
        }

        .status-badge.absent {
            background: rgba(239, 68, 68, 0.15);
            color: var(--danger);
        }

        .status-badge.late {
            background: rgba(245, 158, 11, 0.15);
            color: var(--warning);
        }

        .status-badge i {
            font-size: 1rem;
        }

        .marked-info {
            font-size: 0.8rem;
            color: var(--gray);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
        }

        .empty-icon {
            font-size: 5rem;
            color: var(--gray-light);
            margin-bottom: 1.5rem;
            opacity: 0.5;
        }

        .empty-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.75rem;
        }

        .empty-text {
            color: var(--gray);
            font-size: 1rem;
        }

        /* Scrollbar */
        .table-container::-webkit-scrollbar {
            width: 10px;
        }

        .table-container::-webkit-scrollbar-track {
            background: var(--light);
            border-radius: 10px;
        }

        .table-container::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--primary), var(--secondary));
            border-radius: 10px;
        }

        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 0.625rem 1.25rem;
            border-radius: 12px;
            border: none;
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-family: 'Poppins', sans-serif;
            text-decoration: none;
        }

        .action-btn.export {
            background: var(--info);
            color: var(--white);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .action-btn.export:hover {
            background: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
        }

        .action-btn.edit {
            background: var(--warning);
            color: var(--white);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }

        .action-btn.edit:hover {
            background: #d97706;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4);
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            body {
                padding: 0.75rem;
            }

            .header-content {
                flex-direction: column;
                align-items: flex-start;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .stats-container {
                grid-template-columns: 1fr;
            }

            .attendance-header-content {
                flex-direction: column;
                align-items: flex-start;
            }

            .quick-actions {
                width: 100%;
                flex-direction: column;
            }

            .action-btn {
                width: 100%;
                justify-content: center;
            }

            .table-container {
                padding: 1rem;
            }

            .attendance-table {
                font-size: 0.875rem;
            }

            .attendance-table thead th,
            .attendance-table tbody td {
                padding: 0.75rem 0.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="header-content">
                <div class="header-title">
                    <div class="header-icon">
                        <i class="fas fa-eye"></i>
                    </div>
                    <div class="header-text">
                        <h1>View Attendance</h1>
                        <p><?php echo htmlspecialchars($selected_academic_year); ?></p>
                    </div>
                </div>
                <a href="index.php" class="back-button">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Dashboard</span>
                </a>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="filter-card">
            <div class="filter-header">
                <i class="fas fa-filter"></i>
                <h2>Filter Attendance</h2>
            </div>
            <form method="GET" action="" id="filterForm">
                <div class="filter-grid">
                    <div class="form-field">
                        <label>
                            <i class="fas fa-calendar-alt"></i>
                            Academic Year
                        </label>
                        <select name="academic_year" id="academic_year" class="form-input" required>
                            <?php 
                            $academic_years_result->data_seek(0);
                            while($year = $academic_years_result->fetch_assoc()): 
                            ?>
                                <option value="<?php echo htmlspecialchars($year['academic_year']); ?>" 
                                        <?php echo ($year['academic_year'] == $selected_academic_year) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year['academic_year']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-field">
                        <label>
                            <i class="fas fa-book"></i>
                            Subject & Class
                        </label>
                        <select name="subject_select" id="subject_select" class="form-input" required>
                            <option value="">-- Select Subject --</option>
                            <?php 
                            $assigned_subjects->data_seek(0);
                            while($subject = $assigned_subjects->fetch_assoc()): 
                                $option_value = $subject['subject_id'] . '|' . $subject['semester_id'] . '|' . ($subject['section_id'] ?? '');
                                $is_selected = ($selected_subject_id == $subject['subject_id'] && 
                                               $selected_semester_id == $subject['semester_id'] &&
                                               $selected_section_id == ($subject['section_id'] ?? null)) ? 'selected' : '';
                            ?>
                                <option value="<?php echo htmlspecialchars($option_value); ?>" <?php echo $is_selected; ?>>
                                    <?php echo htmlspecialchars($subject['subject_code'] . ' - ' . $subject['subject_name'] . ' (' . $subject['semester_name'] . ($subject['section_name'] ? ' - ' . $subject['section_name'] : '') . ')'); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-field">
                        <label>
                            <i class="fas fa-calendar-day"></i>
                            Date
                        </label>
                        <input type="date" name="date" id="attendance_date" class="form-input" 
                               value="<?php echo htmlspecialchars($selected_date); ?>" 
                               max="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
                <input type="hidden" name="subject_id" id="subject_id" value="<?php echo $selected_subject_id ?? ''; ?>">
                <input type="hidden" name="semester_id" id="semester_id" value="<?php echo $selected_semester_id ?? ''; ?>">
                <input type="hidden" name="section_id" id="section_id" value="<?php echo $selected_section_id ?? ''; ?>">
            </form>
        </div>

        <?php if ($selected_subject_id && $attendance_records && $attendance_records->num_rows > 0): ?>
            <!-- Statistics Cards -->
            <div class="stats-container">
                <div class="stat-card total">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-value"><?php echo $total_students; ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
                <div class="stat-card present">
                    <div class="stat-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-value"><?php echo $present_count; ?></div>
                    <div class="stat-label">Present</div>
                    <div class="stat-percentage"><?php echo $present_percentage; ?>%</div>
                </div>
                <div class="stat-card absent">
                    <div class="stat-icon">
                        <i class="fas fa-user-times"></i>
                    </div>
                    <div class="stat-value"><?php echo $absent_count; ?></div>
                    <div class="stat-label">Absent</div>
                    <div class="stat-percentage"><?php echo $absent_percentage; ?>%</div>
                </div>
                <?php if ($late_count > 0): ?>
                <div class="stat-card late">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-value"><?php echo $late_count; ?></div>
                    <div class="stat-label">Late</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Attendance Table Card -->
            <div class="attendance-card">
                <div class="attendance-header">
                    <div class="attendance-header-content">
                        <div class="attendance-title">
                            <h2>Attendance Records</h2>
                            <?php if($selected_subject_info): ?>
                                <span class="subject-badge">
                                    <?php echo htmlspecialchars($selected_subject_info['subject_code']); ?>
                                </span>
                            <?php endif; ?>
                            <span class="date-badge">
                                <i class="fas fa-calendar"></i>
                                <?php echo date('M d, Y', strtotime($selected_date)); ?>
                            </span>
                        </div>
                        <div class="quick-actions">
                            <a href="mark_attendance.php?subject_id=<?php echo $selected_subject_id; ?>&semester_id=<?php echo $selected_semester_id; ?><?php echo $selected_section_id ? '&section_id=' . $selected_section_id : ''; ?>&date=<?php echo $selected_date; ?>&academic_year=<?php echo urlencode($selected_academic_year); ?>" class="action-btn edit">
                                <i class="fas fa-edit"></i>
                                Edit Attendance
                            </a>
                        </div>
                    </div>
                </div>

                <div class="table-container">
                    <table class="attendance-table">
                        <thead>
                            <tr>
                                <th style="width: 10%;">Roll No.</th>
                                <th style="width: 30%;">Student Name</th>
                                <th style="width: 15%;">Admission ID</th>
                                <th style="width: 15%;">Status</th>
                                <th style="width: 30%;">Marked By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $attendance_records->data_seek(0);
                            while($record = $attendance_records->fetch_assoc()): 
                            ?>
                                <tr>
                                    <td>
                                        <span class="roll-badge">
                                            <?php echo $record['roll_number_display'] ? htmlspecialchars($record['roll_number_display']) : '-'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="student-name"><?php echo htmlspecialchars($record['full_name']); ?></div>
                                    </td>
                                    <td>
                                        <div class="student-id"><?php echo htmlspecialchars($record['admission_number']); ?></div>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $record['status']; ?>">
                                            <?php if($record['status'] == 'present'): ?>
                                                <i class="fas fa-check-circle"></i> Present
                                            <?php elseif($record['status'] == 'absent'): ?>
                                                <i class="fas fa-times-circle"></i> Absent
                                            <?php else: ?>
                                                <i class="fas fa-clock"></i> Late
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="marked-info">
                                            <?php echo htmlspecialchars($record['marked_by_name'] ?? 'N/A'); ?>
                                            <br>
                                            <small><?php echo date('h:i A', strtotime($record['marked_time'])); ?></small>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php elseif ($selected_subject_id): ?>
            <div class="attendance-card">
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <h3 class="empty-title">No Attendance Records</h3>
                    <p class="empty-text">No attendance has been marked for this date and class.</p>
                    <br>
                    <a href="mark_attendance.php?subject_id=<?php echo $selected_subject_id; ?>&semester_id=<?php echo $selected_semester_id; ?><?php echo $selected_section_id ? '&section_id=' . $selected_section_id : ''; ?>&date=<?php echo $selected_date; ?>&academic_year=<?php echo urlencode($selected_academic_year); ?>" class="action-btn edit">
                        <i class="fas fa-plus"></i>
                        Mark Attendance Now
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="attendance-card">
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-hand-pointer"></i>
                    </div>
                    <h3 class="empty-title">Select Filters</h3>
                    <p class="empty-text">Please select an academic year, subject, and date above to view attendance records.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Form handlers
        document.getElementById('academic_year').addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });

        document.getElementById('subject_select').addEventListener('change', function() {
            if (this.value) {
                const parts = this.value.split('|');
                document.getElementById('subject_id').value = parts[0];
                document.getElementById('semester_id').value = parts[1];
                document.getElementById('section_id').value = parts[2] || '';
                document.getElementById('filterForm').submit();
            }
        });

        document.getElementById('attendance_date').addEventListener('change', function() {
            if (document.getElementById('subject_select').value) {
                document.getElementById('filterForm').submit();
            }
        });
    </script>
</body>
</html>
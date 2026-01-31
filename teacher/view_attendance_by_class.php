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

// Get default academic year
$default_year = null;
if ($academic_years_result->num_rows > 0) {
    $first_year = $academic_years_result->fetch_assoc();
    $default_year = $first_year['academic_year'];
    $academic_years_result->data_seek(0);
}

$selected_academic_year = $_GET['academic_year'] ?? $default_year ?? date('Y') . '-' . (date('Y') + 1);

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

// Get selected subject
$selected_subject_id = $_GET['subject_id'] ?? null;
$selected_semester_id = $_GET['semester_id'] ?? null;
$selected_section_id = $_GET['section_id'] ?? null;
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

$selected_subject_info = null;
$attendance_by_date = [];
$overall_stats = null;

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

    // Get attendance records grouped by date
    $attendance_sql = "SELECT 
                          a.attendance_date,
                          COUNT(DISTINCT a.student_id) as total_students,
                          SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                          SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                          SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
                          MAX(a.created_at) as marked_time
                       FROM attendance a
                       WHERE a.subject_id = ?
                       AND a.semester_id = ?
                       AND a.attendance_date BETWEEN ? AND ?";
    
    if ($selected_section_id) {
        $attendance_sql .= " AND a.section_id = ?";
    }
    
    $attendance_sql .= " GROUP BY a.attendance_date
                        ORDER BY a.attendance_date DESC";
    
    $attendance_stmt = $conn->prepare($attendance_sql);
    if ($selected_section_id) {
        $attendance_stmt->bind_param("iissi", $selected_subject_id, $selected_semester_id, $date_from, $date_to, $selected_section_id);
    } else {
        $attendance_stmt->bind_param("iiss", $selected_subject_id, $selected_semester_id, $date_from, $date_to);
    }
    $attendance_stmt->execute();
    $attendance_by_date = $attendance_stmt->get_result();

    // Calculate overall statistics
    $total_days = 0;
    $total_present = 0;
    $total_absent = 0;
    $total_late = 0;
    $total_records = 0;

    if ($attendance_by_date->num_rows > 0) {
        $attendance_by_date->data_seek(0);
        while($record = $attendance_by_date->fetch_assoc()) {
            $total_days++;
            $total_present += $record['present_count'];
            $total_absent += $record['absent_count'];
            $total_late += $record['late_count'];
            $total_records += $record['total_students'];
        }
    }

    $overall_percentage = $total_records > 0 ? round(($total_present / $total_records) * 100, 1) : 0;
    
    $overall_stats = [
        'total_days' => $total_days,
        'total_records' => $total_records,
        'total_present' => $total_present,
        'total_absent' => $total_absent,
        'total_late' => $total_late,
        'overall_percentage' => $overall_percentage
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Attendance by Class - College ERP</title>
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
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
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
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15);
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
            gap: 1.5rem;
        }

        .header-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: var(--white);
            box-shadow: 0 8px 20px rgba(99, 102, 241, 0.4);
        }

        .header-text h1 {
            font-size: 2rem;
            font-weight: 900;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }

        .header-text p {
            color: var(--gray);
            font-size: 1rem;
            font-weight: 600;
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
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 1.75rem;
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
            height: 5px;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
        }

        .stat-card.days::before { background: linear-gradient(90deg, var(--primary), var(--secondary)); }
        .stat-card.records::before { background: linear-gradient(90deg, var(--info), #2563eb); }
        .stat-card.present::before { background: linear-gradient(90deg, var(--success), #16a34a); }
        .stat-card.absent::before { background: linear-gradient(90deg, var(--danger), #dc2626); }

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

        .stat-card.days .stat-icon {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.15), rgba(139, 92, 246, 0.15));
            color: var(--primary);
        }
        .stat-card.records .stat-icon {
            background: rgba(59, 130, 246, 0.15);
            color: var(--info);
        }
        .stat-card.present .stat-icon {
            background: rgba(34, 197, 94, 0.15);
            color: var(--success);
        }
        .stat-card.absent .stat-icon {
            background: rgba(239, 68, 68, 0.15);
            color: var(--danger);
        }

        .stat-value {
            font-size: 2.25rem;
            font-weight: 900;
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .stat-card.days .stat-value {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .stat-card.records .stat-value { color: var(--info); }
        .stat-card.present .stat-value { color: var(--success); }
        .stat-card.absent .stat-value { color: var(--danger); }

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
            color: var(--success);
        }

        /* Records Card */
        .records-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15);
            overflow: hidden;
        }

        .records-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            padding: 1.75rem 2rem;
            color: var(--white);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .records-title {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .records-title h2 {
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

        .records-body {
            padding: 1.5rem;
            max-height: 60vh;
            overflow-y: auto;
        }

        /* Date Records */
        .date-record {
            background: var(--white);
            border: 2px solid var(--border);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.25rem;
            transition: all 0.3s ease;
        }

        .date-record:hover {
            border-color: var(--primary);
            box-shadow: 0 8px 24px rgba(99, 102, 241, 0.15);
            transform: translateY(-2px);
        }

        .date-record-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--border);
        }

        .date-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .date-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: 1.25rem;
        }

        .date-text h3 {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .date-text p {
            font-size: 0.875rem;
            color: var(--gray);
            font-weight: 500;
        }

        .date-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 1rem;
        }

        .mini-stat {
            text-align: center;
            padding: 0.75rem;
            border-radius: 12px;
        }

        .mini-stat.present { background: rgba(34, 197, 94, 0.1); }
        .mini-stat.absent { background: rgba(239, 68, 68, 0.1); }
        .mini-stat.late { background: rgba(245, 158, 11, 0.1); }
        .mini-stat.total { background: rgba(59, 130, 246, 0.1); }

        .mini-stat-value {
            font-size: 1.5rem;
            font-weight: 800;
            margin-bottom: 0.25rem;
        }

        .mini-stat.present .mini-stat-value { color: var(--success); }
        .mini-stat.absent .mini-stat-value { color: var(--danger); }
        .mini-stat.late .mini-stat-value { color: var(--warning); }
        .mini-stat.total .mini-stat-value { color: var(--info); }

        .mini-stat-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--gray);
            text-transform: uppercase;
        }

        .date-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .action-btn {
            flex: 1;
            padding: 0.75rem 1rem;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.875rem;
            text-align: center;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .action-btn.view {
            background: var(--info);
            color: var(--white);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .action-btn.view:hover {
            background: #2563eb;
            transform: translateY(-2px);
        }

        .action-btn.edit {
            background: var(--warning);
            color: var(--white);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }

        .action-btn.edit:hover {
            background: #d97706;
            transform: translateY(-2px);
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
        .records-body::-webkit-scrollbar {
            width: 10px;
        }

        .records-body::-webkit-scrollbar-track {
            background: var(--light);
            border-radius: 10px;
        }

        .records-body::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--primary), var(--secondary));
            border-radius: 10px;
        }

        @media (max-width: 768px) {
            .page-header { padding: 1.5rem; }
            .header-content { flex-direction: column; }
            .filter-grid { grid-template-columns: 1fr; }
            .stats-container { grid-template-columns: 1fr; }
            .date-record-header { flex-direction: column; align-items: flex-start; }
            .date-actions { flex-direction: column; }
            .action-btn { width: 100%; }
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
                        <i class="fas fa-book-open"></i>
                    </div>
                    <div class="header-text">
                        <h1>Attendance by Class</h1>
                        <p>View all attendance records for a specific class/subject</p>
                    </div>
                </div>
                <a href="index.php" class="back-button">
                    <i class="fas fa-arrow-left"></i>
                    Dashboard
                </a>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="filter-card">
            <div class="filter-header">
                <i class="fas fa-sliders-h"></i>
                <h2>Select Class & Date Range</h2>
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
                            From Date
                        </label>
                        <input type="date" name="date_from" id="date_from" class="form-input" 
                               value="<?php echo htmlspecialchars($date_from); ?>" 
                               max="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-field">
                        <label>
                            <i class="fas fa-calendar-day"></i>
                            To Date
                        </label>
                        <input type="date" name="date_to" id="date_to" class="form-input" 
                               value="<?php echo htmlspecialchars($date_to); ?>" 
                               max="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
                <input type="hidden" name="subject_id" id="subject_id" value="<?php echo $selected_subject_id ?? ''; ?>">
                <input type="hidden" name="semester_id" id="semester_id" value="<?php echo $selected_semester_id ?? ''; ?>">
                <input type="hidden" name="section_id" id="section_id" value="<?php echo $selected_section_id ?? ''; ?>">
            </form>
        </div>

        <?php if ($selected_subject_id && $overall_stats && $overall_stats['total_days'] > 0): ?>
            <!-- Statistics -->
            <div class="stats-container">
                <div class="stat-card days">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-value"><?php echo $overall_stats['total_days']; ?></div>
                    <div class="stat-label">Days Marked</div>
                </div>
                <div class="stat-card records">
                    <div class="stat-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="stat-value"><?php echo $overall_stats['total_records']; ?></div>
                    <div class="stat-label">Total Records</div>
                </div>
                <div class="stat-card present">
                    <div class="stat-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-value"><?php echo $overall_stats['total_present']; ?></div>
                    <div class="stat-label">Present</div>
                    <div class="stat-percentage"><?php echo $overall_stats['overall_percentage']; ?>%</div>
                </div>
                <div class="stat-card absent">
                    <div class="stat-icon">
                        <i class="fas fa-user-times"></i>
                    </div>
                    <div class="stat-value"><?php echo $overall_stats['total_absent']; ?></div>
                    <div class="stat-label">Absent</div>
                </div>
            </div>

            <!-- Records Card -->
            <div class="records-card">
                <div class="records-header">
                    <div class="records-title">
                        <h2>Attendance Records</h2>
                        <?php if($selected_subject_info): ?>
                            <span class="subject-badge">
                                <?php echo htmlspecialchars($selected_subject_info['subject_code']); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="records-body">
                    <?php 
                    $attendance_by_date->data_seek(0);
                    while($record = $attendance_by_date->fetch_assoc()): 
                        $percentage = $record['total_students'] > 0 ? round(($record['present_count'] / $record['total_students']) * 100, 1) : 0;
                    ?>
                        <div class="date-record">
                            <div class="date-record-header">
                                <div class="date-info">
                                    <div class="date-icon">
                                        <i class="fas fa-calendar"></i>
                                    </div>
                                    <div class="date-text">
                                        <h3><?php echo date('F j, Y', strtotime($record['attendance_date'])); ?></h3>
                                        <p><?php echo date('l', strtotime($record['attendance_date'])); ?> • Marked at <?php echo date('h:i A', strtotime($record['marked_time'])); ?></p>
                                    </div>
                                </div>
                            </div>

                            <div class="date-stats">
                                <div class="mini-stat total">
                                    <div class="mini-stat-value"><?php echo $record['total_students']; ?></div>
                                    <div class="mini-stat-label">Total</div>
                                </div>
                                <div class="mini-stat present">
                                    <div class="mini-stat-value"><?php echo $record['present_count']; ?></div>
                                    <div class="mini-stat-label">Present</div>
                                </div>
                                <div class="mini-stat absent">
                                    <div class="mini-stat-value"><?php echo $record['absent_count']; ?></div>
                                    <div class="mini-stat-label">Absent</div>
                                </div>
                                <?php if($record['late_count'] > 0): ?>
                                <div class="mini-stat late">
                                    <div class="mini-stat-value"><?php echo $record['late_count']; ?></div>
                                    <div class="mini-stat-label">Late</div>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="date-actions">
                                <a href="view_class_attendance.php?subject_id=<?php echo $selected_subject_id; ?>&semester_id=<?php echo $selected_semester_id; ?><?php echo $selected_section_id ? '&section_id=' . $selected_section_id : ''; ?>&date=<?php echo $record['attendance_date']; ?>&academic_year=<?php echo urlencode($selected_academic_year); ?>" class="action-btn view">
                                    <i class="fas fa-eye"></i>
                                    View Details
                                </a>
                                <a href="mark_attendance.php?subject_id=<?php echo $selected_subject_id; ?>&semester_id=<?php echo $selected_semester_id; ?><?php echo $selected_section_id ? '&section_id=' . $selected_section_id : ''; ?>&date=<?php echo $record['attendance_date']; ?>&academic_year=<?php echo urlencode($selected_academic_year); ?>" class="action-btn edit">
                                    <i class="fas fa-edit"></i>
                                    Edit
                                </a>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>

        <?php elseif ($selected_subject_id): ?>
            <div class="records-card">
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <h3 class="empty-title">No Attendance Records</h3>
                    <p class="empty-text">No attendance has been marked for this class in the selected date range.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="records-card">
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-hand-pointer"></i>
                    </div>
                    <h3 class="empty-title">Select a Class</h3>
                    <p class="empty-text">Please select a subject and class above to view attendance records.</p>
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

        document.getElementById('date_from').addEventListener('change', function() {
            if (document.getElementById('subject_select').value) {
                document.getElementById('filterForm').submit();
            }
        });

        document.getElementById('date_to').addEventListener('change', function() {
            if (document.getElementById('subject_select').value) {
                document.getElementById('filterForm').submit();
            }
        });
    </script>
</body>
</html>
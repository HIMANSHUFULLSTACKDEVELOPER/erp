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

// Get assigned subjects for dropdown
$subjects_sql = "SELECT DISTINCT st.subject_id, sub.subject_name, sub.subject_code, 
                 st.semester_id, sem.semester_name, st.section_id, sec.section_name
                 FROM subject_teachers st
                 JOIN subjects sub ON st.subject_id = sub.subject_id
                 JOIN semesters sem ON st.semester_id = sem.semester_id
                 LEFT JOIN sections sec ON st.section_id = sec.section_id
                 WHERE st.teacher_id = ?
                 ORDER BY sem.semester_number, sub.subject_name";
$stmt = $conn->prepare($subjects_sql);
$stmt->bind_param("i", $teacher['teacher_id']);
$stmt->execute();
$assigned_subjects = $stmt->get_result();

// Initialize variables
$students_attendance = [];
$selected_subject_info = null;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

// Get student-wise attendance summary
if (isset($_GET['subject_id']) && isset($_GET['semester_id'])) {
    $subject_id = intval($_GET['subject_id']);
    $semester_id = intval($_GET['semester_id']);
    $section_id = isset($_GET['section_id']) && $_GET['section_id'] !== '' ? intval($_GET['section_id']) : null;

    // Get subject info
    $subject_info_sql = "SELECT sub.subject_name, sub.subject_code, sem.semester_name, sec.section_name
                         FROM subjects sub
                         JOIN semesters sem ON sub.semester_id = sem.semester_id
                         LEFT JOIN sections sec ON sec.section_id = ?
                         WHERE sub.subject_id = ?";
    $subject_info_stmt = $conn->prepare($subject_info_sql);
    $subject_info_stmt->bind_param("ii", $section_id, $subject_id);
    $subject_info_stmt->execute();
    $selected_subject_info = $subject_info_stmt->get_result()->fetch_assoc();

    // Get total number of days attendance was marked in the date range
    $total_days_sql = "SELECT COUNT(DISTINCT attendance_date) as total_days
                       FROM attendance
                       WHERE subject_id = ? 
                       AND semester_id = ?
                       AND attendance_date BETWEEN ? AND ?";
    
    if ($section_id) {
        $total_days_sql .= " AND section_id = ?";
        $total_days_stmt = $conn->prepare($total_days_sql);
        $total_days_stmt->bind_param("iissi", $subject_id, $semester_id, $date_from, $date_to, $section_id);
    } else {
        $total_days_stmt = $conn->prepare($total_days_sql);
        $total_days_stmt->bind_param("iiss", $subject_id, $semester_id, $date_from, $date_to);
    }
    $total_days_stmt->execute();
    $total_days_result = $total_days_stmt->get_result()->fetch_assoc();
    $total_days = $total_days_result['total_days'];

    // Get student-wise attendance statistics
    $student_stats_sql = "SELECT 
                            s.student_id,
                            s.admission_number,
                            s.full_name,
                            COUNT(DISTINCT a.attendance_date) as total_days_marked,
                            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                            SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
                            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT a.attendance_date), 0)) * 100, 2) as attendance_percentage
                          FROM student_semesters ss
                          JOIN students s ON ss.student_id = s.student_id
                          LEFT JOIN attendance a ON s.student_id = a.student_id 
                              AND a.subject_id = ? 
                              AND a.semester_id = ?
                              AND a.attendance_date BETWEEN ? AND ?";
    
    if ($section_id) {
        $student_stats_sql .= " AND a.section_id = ?";
    }
    
    $student_stats_sql .= " WHERE ss.semester_id = ? AND ss.is_active = 1";
    
    if ($section_id) {
        $student_stats_sql .= " AND ss.section_id = ?";
    }
    
    $student_stats_sql .= " GROUP BY s.student_id, s.admission_number, s.full_name
                           ORDER BY s.admission_number";
    
    $student_stats_stmt = $conn->prepare($student_stats_sql);
    
    if ($section_id) {
        $student_stats_stmt->bind_param("iissiii", $subject_id, $semester_id, $date_from, $date_to, $section_id, $semester_id, $section_id);
    } else {
        $student_stats_stmt->bind_param("iissi", $subject_id, $semester_id, $date_from, $date_to, $semester_id);
    }
    
    $student_stats_stmt->execute();
    $students_attendance = $student_stats_stmt->get_result();
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv' && isset($_GET['subject_id'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="student_attendance_summary_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Roll No.', 'Student Name', 'Total Days', 'Present', 'Absent', 'Late', 'Attendance %', 'Status']);
    
    $students_attendance->data_seek(0);
    while ($student = $students_attendance->fetch_assoc()) {
        $percentage = $student['attendance_percentage'] ?? 0;
        $status = $percentage >= 75 ? 'Good' : ($percentage >= 60 ? 'Average' : 'Low');
        
        fputcsv($output, [
            $student['admission_number'],
            $student['full_name'],
            $student['total_days_marked'],
            $student['present_count'],
            $student['absent_count'],
            $student['late_count'],
            $percentage . '%',
            $status
        ]);
    }
    
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student-wise Attendance - College ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #7c3aed;
            --primary-dark: #6d28d9;
            --secondary: #ec4899;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --dark: #1f2937;
            --gray: #6b7280;
            --light: #f9fafb;
            --white: #ffffff;
            --border: #e5e7eb;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--light);
            color: var(--dark);
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            padding: 2rem;
            border-radius: 20px;
            margin-bottom: 2rem;
            box-shadow: 0 10px 30px rgba(124, 58, 237, 0.2);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            font-size: 2rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            color: var(--white);
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }

        /* Card */
        .card {
            background: var(--white);
            border-radius: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
            overflow: hidden;
        }

        .card-header {
            padding: 1.5rem 2rem;
            background: var(--white);
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .card-title {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--dark);
        }

        .card-title i {
            color: var(--primary);
            font-size: 1.5rem;
        }

        .card-body {
            padding: 2rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--white);
            color: var(--dark);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border: 1px solid var(--border);
            text-align: center;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
        }

        .stat-card.success {
            background: linear-gradient(135deg, #ffffff 0%, #f0fdf4 100%);
            border-color: #10b981;
        }

        .stat-card.danger {
            background: linear-gradient(135deg, #ffffff 0%, #fef2f2 100%);
            border-color: #ef4444;
        }

        .stat-card.warning {
            background: linear-gradient(135deg, #ffffff 0%, #fffbeb 100%);
            border-color: #f59e0b;
        }

        .stat-card.info {
            background: linear-gradient(135deg, #ffffff 0%, #eff6ff 100%);
            border-color: #3b82f6;
        }

        .stat-card i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            display: inline-block;
            width: 60px;
            height: 60px;
            line-height: 60px;
            border-radius: 50%;
            background: rgba(0, 0, 0, 0.05);
        }

        .stat-card.success i {
            color: #10b981;
            background: rgba(16, 185, 129, 0.1);
        }

        .stat-card.danger i {
            color: #ef4444;
            background: rgba(239, 68, 68, 0.1);
        }

        .stat-card.warning i {
            color: #f59e0b;
            background: rgba(245, 158, 11, 0.1);
        }

        .stat-card.info i {
            color: #3b82f6;
            background: rgba(59, 130, 246, 0.1);
        }

        .stat-value {
            font-size: 3rem;
            font-weight: 800;
            margin: 1rem 0 0.5rem 0;
            line-height: 1;
            color: var(--dark);
        }

        .stat-label {
            font-size: 1rem;
            font-weight: 600;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Form */
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--dark);
            font-size: 0.95rem;
        }

        .form-control {
            padding: 0.875rem 1rem;
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s;
            font-family: 'Inter', sans-serif;
            background: var(--white);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
        }

        select.form-control {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1.25rem;
            padding-right: 3rem;
        }

        /* Search Box */
        .search-box {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .search-box input {
            width: 100%;
            padding: 1rem 1rem 1rem 3rem;
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s;
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
        }

        .search-box i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
            font-size: 1.2rem;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.95rem;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: var(--white);
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
        }

        .btn-export {
            background: var(--info);
            color: var(--white);
        }

        .btn-export:hover {
            background: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        /* Table */
        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead th {
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

        tbody td {
            padding: 1rem;
            border-bottom: 1px solid var(--border);
        }

        tbody tr {
            transition: all 0.2s;
        }

        tbody tr:hover {
            background: var(--light);
        }

        tbody tr.hidden {
            display: none;
        }

        /* Status Badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.875rem;
        }

        .status-badge.good {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge.average {
            background: #fef3c7;
            color: #92400e;
        }

        .status-badge.low {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Count Badge */
        .count-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            font-weight: 700;
            font-size: 0.95rem;
        }

        .count-badge.present {
            background: #d1fae5;
            color: #065f46;
        }

        .count-badge.absent {
            background: #fee2e2;
            color: #991b1b;
        }

        .count-badge.late {
            background: #fef3c7;
            color: #92400e;
        }

        /* Percentage Display */
        .percentage {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary);
        }

        /* Subject Info Badge */
        .subject-info {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            padding: 0.5rem 1.25rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.9rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 5rem;
            opacity: 0.3;
            margin-bottom: 1.5rem;
            display: block;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .empty-state p {
            font-size: 1rem;
        }

        /* No Results Message */
        .no-results {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--gray);
            display: none;
        }

        .no-results.show {
            display: block;
        }

        .no-results i {
            font-size: 3rem;
            opacity: 0.3;
            margin-bottom: 1rem;
        }

        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 1rem;
            }

            .card-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .filter-form {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                width: 100%;
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            table {
                font-size: 0.875rem;
            }

            thead th, tbody td {
                padding: 0.75rem 0.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <h1>
                    <i class="fas fa-user-graduate"></i>
                    Student-wise Attendance
                </h1>
                <a href="attendance_history.php<?php echo isset($_GET['subject_id']) ? '?subject_id=' . $_GET['subject_id'] . '&semester_id=' . $_GET['semester_id'] . (isset($_GET['section_id']) ? '&section_id=' . $_GET['section_id'] : '') : ''; ?>" class="back-btn">
                    <i class="fas fa-arrow-left"></i>
                    Back to History
                </a>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="card">
            <div class="card-header" style="background: var(--white); border-bottom: none; padding-bottom: 0;">
                <div class="card-title" style="color: var(--dark);">
                    <i class="fas fa-filter" style="color: var(--primary);"></i>
                    Filter Options
                </div>
            </div>
            <div class="card-body">
                <form method="GET" action="" id="filterForm">
                    <div class="filter-form">
                        <div class="form-group">
                            <label for="subject_select">Subject & Class</label>
                            <select name="subject_select" id="subject_select" class="form-control" required>
                                <option value="">-- Select Subject & Class --</option>
                                <?php 
                                $assigned_subjects->data_seek(0);
                                while($subject = $assigned_subjects->fetch_assoc()): 
                                    $option_value = $subject['subject_id'] . '|' . $subject['semester_id'] . '|' . ($subject['section_id'] ?? '');
                                    $is_selected = (isset($_GET['subject_id']) && 
                                                   $_GET['subject_id'] == $subject['subject_id'] && 
                                                   $_GET['semester_id'] == $subject['semester_id'] &&
                                                   ($_GET['section_id'] ?? '') == ($subject['section_id'] ?? '')) ? 'selected' : '';
                                ?>
                                    <option value="<?php echo htmlspecialchars($option_value); ?>" <?php echo $is_selected; ?>>
                                        <?php echo htmlspecialchars($subject['subject_code'] . ' - ' . $subject['subject_name'] . ' (' . $subject['semester_name'] . ($subject['section_name'] ? ' - ' . $subject['section_name'] : '') . ')'); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="date_from">From Date</label>
                            <input type="date" name="date_from" id="date_from" class="form-control" 
                                   value="<?php echo htmlspecialchars($date_from); ?>" 
                                   max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                            <label for="date_to">To Date</label>
                            <input type="date" name="date_to" id="date_to" class="form-control" 
                                   value="<?php echo htmlspecialchars($date_to); ?>" 
                                   max="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                    <input type="hidden" name="subject_id" id="subject_id" value="<?php echo $_GET['subject_id'] ?? ''; ?>">
                    <input type="hidden" name="semester_id" id="semester_id" value="<?php echo $_GET['semester_id'] ?? ''; ?>">
                    <input type="hidden" name="section_id" id="section_id" value="<?php echo $_GET['section_id'] ?? ''; ?>">
                </form>
            </div>
        </div>

        <?php if (isset($_GET['subject_id']) && isset($students_attendance)): ?>
            <!-- Counting Cards -->
            <?php
            // Calculate totals for counting cards
            $total_records = 0;
            $total_present = 0;
            $total_absent = 0;
            $total_late = 0;
            
            if ($students_attendance && $students_attendance->num_rows > 0) {
                $students_attendance->data_seek(0);
                while($student = $students_attendance->fetch_assoc()) {
                    $total_records += ($student['total_days_marked'] ?? 0);
                    $total_present += ($student['present_count'] ?? 0);
                    $total_absent += ($student['absent_count'] ?? 0);
                    $total_late += ($student['late_count'] ?? 0);
                }
            }
            ?>
            <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); margin-bottom: 2rem;">
                <div class="stat-card info">
                    <i class="fas fa-clipboard-list"></i>
                    <div class="stat-value"><?php echo $total_records; ?></div>
                    <div class="stat-label">Total Records</div>
                </div>
                <div class="stat-card success">
                    <i class="fas fa-check-circle"></i>
                    <div class="stat-value"><?php echo $total_present; ?></div>
                    <div class="stat-label">Present</div>
                </div>
                <div class="stat-card danger">
                    <i class="fas fa-times-circle"></i>
                    <div class="stat-value"><?php echo $total_absent; ?></div>
                    <div class="stat-label">Absent</div>
                </div>
                <?php if ($total_late > 0): ?>
                <div class="stat-card warning">
                    <i class="fas fa-clock"></i>
                    <div class="stat-value"><?php echo $total_late; ?></div>
                    <div class="stat-label">Late</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Student Attendance Summary Card -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-table"></i>
                        Student Attendance Summary
                        <?php if($selected_subject_info): ?>
                            <span class="subject-info">
                                <i class="fas fa-book"></i>
                                <?php echo htmlspecialchars($selected_subject_info['subject_code']); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="action-buttons">
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-export">
                            <i class="fas fa-download"></i>
                            Export to CSV
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($students_attendance && $students_attendance->num_rows > 0): ?>
                        <!-- Search Box -->
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" id="studentSearch" placeholder="Search by roll number or student name...">
                        </div>

                        <div class="table-container">
                            <table id="studentTable">
                                <thead>
                                    <tr>
                                        <th style="width: 12%;">Roll Number</th>
                                        <th style="width: 25%;">Student Name</th>
                                        <th style="width: 12%;">Total Days</th>
                                        <th style="width: 10%;">Present</th>
                                        <th style="width: 10%;">Absent</th>
                                        <th style="width: 10%;">Late</th>
                                        <th style="width: 13%;">Attendance %</th>
                                        <th style="width: 12%;">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $students_attendance->data_seek(0);
                                    while($student = $students_attendance->fetch_assoc()): 
                                        $percentage = $student['attendance_percentage'] ?? 0;
                                        $status = $percentage >= 75 ? 'good' : ($percentage >= 60 ? 'average' : 'low');
                                        $status_text = $percentage >= 75 ? 'Good' : ($percentage >= 60 ? 'Average' : 'Low');
                                        $status_icon = $percentage >= 75 ? 'check-circle' : ($percentage >= 60 ? 'exclamation-circle' : 'times-circle');
                                    ?>
                                        <tr class="student-row" data-roll="<?php echo htmlspecialchars(strtolower($student['admission_number'])); ?>" data-name="<?php echo htmlspecialchars(strtolower($student['full_name'])); ?>">
                                            <td><strong><?php echo htmlspecialchars($student['admission_number']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                            <td><strong><?php echo $student['total_days_marked'] ?? 0; ?></strong></td>
                                            <td>
                                                <span class="count-badge present">
                                                    <?php echo $student['present_count'] ?? 0; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="count-badge absent">
                                                    <?php echo $student['absent_count'] ?? 0; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="count-badge late">
                                                    <?php echo $student['late_count'] ?? 0; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="percentage"><?php echo number_format($percentage, 2); ?>%</span>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo $status; ?>">
                                                    <i class="fas fa-<?php echo $status_icon; ?>"></i>
                                                    <?php echo $status_text; ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- No Results Message -->
                        <div class="no-results" id="noResults">
                            <i class="fas fa-search"></i>
                            <h3>No students found</h3>
                            <p>Try a different search term</p>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-users"></i>
                            <h3>No Students Found</h3>
                            <p>No students enrolled for this subject in the selected date range.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-body">
                    <div class="empty-state">
                        <i class="fas fa-search"></i>
                        <h3>Select Filters</h3>
                        <p>Please select a subject and date range to view student attendance summary.</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Handle subject selection change
        document.getElementById('subject_select').addEventListener('change', function() {
            if (this.value) {
                const parts = this.value.split('|');
                document.getElementById('subject_id').value = parts[0];
                document.getElementById('semester_id').value = parts[1];
                document.getElementById('section_id').value = parts[2] || '';
                document.getElementById('filterForm').submit();
            }
        });

        // Handle date change
        const dateInputs = document.querySelectorAll('#date_from, #date_to');
        dateInputs.forEach(input => {
            input.addEventListener('change', function() {
                const subjectSelect = document.getElementById('subject_select');
                if (subjectSelect.value) {
                    document.getElementById('filterForm').submit();
                }
            });
        });

        // Live search functionality
        const searchInput = document.getElementById('studentSearch');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase().trim();
                const studentRows = document.querySelectorAll('.student-row');
                const noResults = document.getElementById('noResults');
                let visibleCount = 0;

                studentRows.forEach(row => {
                    const rollNumber = row.getAttribute('data-roll');
                    const studentName = row.getAttribute('data-name');
                    
                    if (rollNumber.includes(searchTerm) || studentName.includes(searchTerm)) {
                        row.classList.remove('hidden');
                        visibleCount++;
                    } else {
                        row.classList.add('hidden');
                    }
                });

                // Show/hide no results message
                if (visibleCount === 0 && searchTerm !== '') {
                    noResults.classList.add('show');
                } else {
                    noResults.classList.remove('show');
                }
            });
        }
    </script>
</body>
</html>
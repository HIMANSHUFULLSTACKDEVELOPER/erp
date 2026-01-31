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
$attendance_records = [];
$selected_subject_info = null;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$student_filter = isset($_GET['student_filter']) ? $_GET['student_filter'] : '';

// Get attendance history for selected class
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

    // Build attendance history query
    $history_sql = "SELECT a.attendance_date, a.status, s.student_id, s.admission_number, s.full_name,
                    t.full_name as marked_by_name, a.created_at, a.remarks
                    FROM attendance a
                    JOIN students s ON a.student_id = s.student_id
                    JOIN teachers t ON a.marked_by = t.teacher_id
                    WHERE a.subject_id = ? 
                    AND a.semester_id = ?
                    AND a.attendance_date BETWEEN ? AND ?";
    
    $params = [$subject_id, $semester_id, $date_from, $date_to];
    $types = "iiss";
    
    if ($section_id) {
        $history_sql .= " AND a.section_id = ?";
        $params[] = $section_id;
        $types .= "i";
    }
    
    if (!empty($student_filter)) {
        $history_sql .= " AND (s.full_name LIKE ? OR s.admission_number LIKE ?)";
        $search_term = "%$student_filter%";
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= "ss";
    }
    
    $history_sql .= " ORDER BY a.attendance_date DESC, s.admission_number";
    
    $history_stmt = $conn->prepare($history_sql);
    $history_stmt->bind_param($types, ...$params);
    $history_stmt->execute();
    $attendance_records = $history_stmt->get_result();

    // Get attendance statistics
    $stats_sql = "SELECT 
                    COUNT(DISTINCT a.attendance_date) as total_days,
                    COUNT(DISTINCT a.student_id) as total_students,
                    COUNT(*) as total_records,
                    SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                    SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                    SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count
                  FROM attendance a
                  WHERE a.subject_id = ? 
                  AND a.semester_id = ?
                  AND a.attendance_date BETWEEN ? AND ?";
    
    if ($section_id) {
        $stats_sql .= " AND a.section_id = ?";
    }
    
    $stats_stmt = $conn->prepare($stats_sql);
    if ($section_id) {
        $stats_stmt->bind_param("iissi", $subject_id, $semester_id, $date_from, $date_to, $section_id);
    } else {
        $stats_stmt->bind_param("iiss", $subject_id, $semester_id, $date_from, $date_to);
    }
    $stats_stmt->execute();
    $stats = $stats_stmt->get_result()->fetch_assoc();
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv' && isset($_GET['subject_id'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="attendance_history_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Roll No.', 'Student Name', 'Status', 'Marked By', 'Marked At', 'Remarks']);
    
    $attendance_records->data_seek(0);
    while ($record = $attendance_records->fetch_assoc()) {
        fputcsv($output, [
            $record['attendance_date'],
            $record['admission_number'],
            $record['full_name'],
            ucfirst($record['status']),
            $record['marked_by_name'],
            date('Y-m-d H:i', strtotime($record['created_at'])),
            $record['remarks'] ?? ''
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
    <title>Attendance History - College ERP</title>
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
            background: var(--light);
            border-bottom: 2px solid var(--border);
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            padding: 1.5rem;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.2);
        }

        .stat-card.success {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .stat-card.danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }

        .stat-card.warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }

        .stat-card.info {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
        }

        .stat-card i {
            font-size: 2rem;
            opacity: 0.9;
            margin-bottom: 0.5rem;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            margin: 0.5rem 0;
        }

        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
            font-weight: 500;
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
            box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.1);
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

        .btn-success {
            background: var(--success);
            color: var(--white);
        }

        .btn-success:hover {
            background: #059669;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
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
            background: var(--light);
            padding: 1rem;
            text-align: left;
            font-weight: 700;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray);
            border-bottom: 2px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        tbody td {
            padding: 1rem;
            border-bottom: 1px solid var(--border);
        }

        tbody tr:hover {
            background: var(--light);
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

        .status-badge.present {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge.absent {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-badge.late {
            background: #fef3c7;
            color: #92400e;
        }

        .status-badge i {
            font-size: 0.875rem;
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

        /* Date Group Header */
        .date-group-header {
            background: var(--light);
            padding: 0.75rem 1rem;
            font-weight: 700;
            color: var(--dark);
            border-left: 4px solid var(--primary);
            margin-top: 1rem;
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
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <h1>
                    <i class="fas fa-history"></i>
                    Attendance History
                </h1>
                <a href="mark_attendance.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i>
                    Back to Attendance
                </a>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-filter"></i>
                    Filter Records
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
                        <div class="form-group">
                            <label for="student_filter">Search Student</label>
                            <input type="text" name="student_filter" id="student_filter" class="form-control" 
                                   placeholder="Name or Roll No." 
                                   value="<?php echo htmlspecialchars($student_filter); ?>">
                        </div>
                    </div>
                    <input type="hidden" name="subject_id" id="subject_id" value="<?php echo $_GET['subject_id'] ?? ''; ?>">
                    <input type="hidden" name="semester_id" id="semester_id" value="<?php echo $_GET['semester_id'] ?? ''; ?>">
                    <input type="hidden" name="section_id" id="section_id" value="<?php echo $_GET['section_id'] ?? ''; ?>">
                </form>
            </div>
        </div>

        <?php if (isset($_GET['subject_id']) && isset($stats)): ?>
            <!-- Statistics Card -->
            <div class="stats-grid">
                <div class="stat-card info">
                    <i class="fas fa-calendar-alt"></i>
                    <div class="stat-value"><?php echo $stats['total_days']; ?></div>
                    <div class="stat-label">Total Days</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-users"></i>
                    <div class="stat-value"><?php echo $stats['total_students']; ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
                <div class="stat-card success">
                    <i class="fas fa-check-circle"></i>
                    <div class="stat-value"><?php echo $stats['present_count']; ?></div>
                    <div class="stat-label">Present Records</div>
                </div>
                <div class="stat-card danger">
                    <i class="fas fa-times-circle"></i>
                    <div class="stat-value"><?php echo $stats['absent_count']; ?></div>
                    <div class="stat-label">Absent Records</div>
                </div>
                <?php if ($stats['late_count'] > 0): ?>
                <div class="stat-card warning">
                    <i class="fas fa-clock"></i>
                    <div class="stat-value"><?php echo $stats['late_count']; ?></div>
                    <div class="stat-label">Late Records</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- History Records Card -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-list"></i>
                        Attendance Records
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
                        <a href="view_student_attendance.php?subject_id=<?php echo $_GET['subject_id']; ?>&semester_id=<?php echo $_GET['semester_id']; ?><?php echo isset($_GET['section_id']) ? '&section_id=' . $_GET['section_id'] : ''; ?>" class="btn btn-primary">
                            <i class="fas fa-chart-bar"></i>
                            Student-wise View
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($attendance_records && $attendance_records->num_rows > 0): ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th style="width: 12%;">Date</th>
                                        <th style="width: 12%;">Roll No.</th>
                                        <th style="width: 25%;">Student Name</th>
                                        <th style="width: 15%;">Status</th>
                                        <th style="width: 18%;">Marked By</th>
                                        <th style="width: 18%;">Marked At</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $current_date = '';
                                    $attendance_records->data_seek(0);
                                    while($record = $attendance_records->fetch_assoc()): 
                                        // Group by date
                                        if ($current_date != $record['attendance_date']) {
                                            $current_date = $record['attendance_date'];
                                            echo '<tr><td colspan="6" class="date-group-header">';
                                            echo '<i class="fas fa-calendar-day"></i> ';
                                            echo date('l, F j, Y', strtotime($current_date));
                                            echo '</td></tr>';
                                        }
                                    ?>
                                        <tr>
                                            <td><?php echo date('M d, Y', strtotime($record['attendance_date'])); ?></td>
                                            <td><strong><?php echo htmlspecialchars($record['admission_number']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($record['full_name']); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo $record['status']; ?>">
                                                    <?php if ($record['status'] == 'present'): ?>
                                                        <i class="fas fa-check-circle"></i>
                                                    <?php elseif ($record['status'] == 'absent'): ?>
                                                        <i class="fas fa-times-circle"></i>
                                                    <?php else: ?>
                                                        <i class="fas fa-clock"></i>
                                                    <?php endif; ?>
                                                    <?php echo ucfirst($record['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($record['marked_by_name']); ?></td>
                                            <td><?php echo date('M d, Y H:i', strtotime($record['created_at'])); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-clipboard-list"></i>
                            <h3>No Records Found</h3>
                            <p>No attendance records found for the selected filters.</p>
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
                        <p>Please select a subject and date range to view attendance history.</p>
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

        // Handle student filter with debounce
        let filterTimeout;
        document.getElementById('student_filter').addEventListener('input', function() {
            const subjectSelect = document.getElementById('subject_select');
            if (subjectSelect.value) {
                clearTimeout(filterTimeout);
                filterTimeout = setTimeout(() => {
                    document.getElementById('filterForm').submit();
                }, 500);
            }
        });
    </script>
</body>
</html>
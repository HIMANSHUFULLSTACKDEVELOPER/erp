<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('teacher')) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];
$student_id = $_GET['student_id'] ?? 0;
$subject_id = $_GET['subject_id'] ?? 0;

// Get teacher details
$sql = "SELECT t.*, d.department_name 
        FROM teachers t 
        JOIN departments d ON t.department_id = d.department_id 
        WHERE t.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();

// Get student details
$student_query = "SELECT s.*, u.email, u.phone, d.department_name, c.course_name,
                  ss.academic_year, sem.semester_name, sec.section_name
                  FROM students s
                  JOIN users u ON s.user_id = u.user_id
                  JOIN departments d ON s.department_id = d.department_id
                  JOIN courses c ON s.course_id = c.course_id
                  LEFT JOIN student_semesters ss ON s.student_id = ss.student_id AND ss.is_active = 1
                  LEFT JOIN semesters sem ON ss.semester_id = sem.semester_id
                  LEFT JOIN sections sec ON ss.section_id = sec.section_id
                  WHERE s.student_id = $student_id";
$student = $conn->query($student_query)->fetch_assoc();

// Get subject details
$subject_query = "SELECT * FROM subjects WHERE subject_id = $subject_id";
$subject = $conn->query($subject_query)->fetch_assoc();

// Get detailed attendance records
$attendance_query = "SELECT a.*, t.full_name as marked_by_name
                     FROM attendance a
                     JOIN teachers t ON a.marked_by = t.teacher_id
                     WHERE a.student_id = $student_id 
                     AND a.subject_id = $subject_id
                     ORDER BY a.attendance_date DESC";
$attendance_records = $conn->query($attendance_query);

// Get attendance summary
$summary_query = "SELECT 
                 COUNT(*) as total_classes,
                 SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
                 SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                 SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count,
                 ROUND((SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as attendance_percentage
                 FROM attendance
                 WHERE student_id = $student_id AND subject_id = $subject_id";
$summary = $conn->query($summary_query)->fetch_assoc();

// Calculate monthly attendance
$monthly_query = "SELECT 
                  DATE_FORMAT(attendance_date, '%Y-%m') as month,
                  COUNT(*) as total,
                  SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present
                  FROM attendance
                  WHERE student_id = $student_id AND subject_id = $subject_id
                  GROUP BY DATE_FORMAT(attendance_date, '%Y-%m')
                  ORDER BY month DESC
                  LIMIT 6";
$monthly_data = $conn->query($monthly_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Details - College ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #8b5cf6;
            --secondary: #ec4899;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #18181b;
            --gray: #71717a;
            --light-gray: #fafafa;
            --white: #ffffff;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--light-gray);
            color: var(--dark);
            padding: 30px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .top-bar {
            background: var(--white);
            padding: 25px 30px;
            border-radius: 20px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .top-bar h1 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .back-btn {
            background: linear-gradient(135deg, var(--gray), #52525b);
            color: var(--white);
            border: none;
            padding: 12px 28px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-family: 'DM Sans', sans-serif;
            text-decoration: none;
            display: inline-block;
        }

        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(82, 82, 91, 0.4);
        }

        /* Student Profile Card */
        .profile-card {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            padding: 40px;
            border-radius: 20px;
            color: var(--white);
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(139, 92, 246, 0.3);
            display: flex;
            align-items: center;
            gap: 30px;
        }

        .student-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: 700;
            border: 4px solid rgba(255,255,255,0.3);
            flex-shrink: 0;
        }

        .student-info {
            flex: 1;
        }

        .student-name {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .student-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .detail-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .detail-item i {
            width: 20px;
            text-align: center;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--white);
            padding: 25px;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 1.8rem;
        }

        .stat-icon.primary { background: linear-gradient(135deg, rgba(139, 92, 246, 0.2), rgba(236, 72, 153, 0.2)); color: var(--primary); }
        .stat-icon.success { background: rgba(16, 185, 129, 0.15); color: var(--success); }
        .stat-icon.danger { background: rgba(239, 68, 68, 0.15); color: var(--danger); }
        .stat-icon.warning { background: rgba(245, 158, 11, 0.15); color: var(--warning); }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--dark);
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--gray);
        }

        /* Card */
        .card {
            background: var(--white);
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 25px;
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
        }

        .card-title h3 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.4rem;
            font-weight: 700;
        }

        /* Monthly Chart */
        .monthly-chart {
            display: grid;
            gap: 15px;
        }

        .month-item {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .month-label {
            min-width: 100px;
            font-weight: 600;
            color: var(--gray);
        }

        .month-bar {
            flex: 1;
            height: 40px;
            background: var(--light-gray);
            border-radius: 10px;
            overflow: hidden;
            position: relative;
        }

        .month-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding-right: 15px;
            color: var(--white);
            font-weight: 600;
            transition: width 0.5s;
        }

        /* Table */
        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            text-align: left;
            padding: 12px;
            border-bottom: 2px solid var(--light-gray);
            font-weight: 600;
            color: var(--gray);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table td {
            padding: 18px 12px;
            border-bottom: 1px solid var(--light-gray);
        }

        .table tbody tr:hover {
            background: rgba(139, 92, 246, 0.05);
        }

        .badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge.success { background: rgba(16, 185, 129, 0.15); color: var(--success); }
        .badge.danger { background: rgba(239, 68, 68, 0.15); color: var(--danger); }
        .badge.warning { background: rgba(245, 158, 11, 0.15); color: var(--warning); }

        .print-btn {
            background: linear-gradient(135deg, var(--success), #059669);
            color: var(--white);
            border: none;
            padding: 10px 24px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-family: 'DM Sans', sans-serif;
        }

        .print-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
        }

        @media print {
            .back-btn, .print-btn { display: none; }
            body { background: white; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="top-bar">
            <h1>Student Details</h1>
            <div style="display: flex; gap: 15px;">
                <button class="print-btn" onclick="window.print()">
                    <i class="fas fa-print"></i> Print Report
                </button>
                <a href="view_students.php?subject_id=<?php echo $subject_id; ?>" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
            </div>
        </div>

        <!-- Student Profile -->
        <div class="profile-card">
            <div class="student-avatar">
                <?php echo strtoupper(substr($student['full_name'], 0, 1)); ?>
            </div>
            <div class="student-info">
                <div class="student-name"><?php echo $student['full_name']; ?></div>
                <div class="student-details">
                    <div class="detail-item">
                        <i class="fas fa-id-card"></i>
                        <span><strong>Admission No:</strong> <?php echo $student['admission_number']; ?></span>
                    </div>
                    <div class="detail-item">
                        <i class="fas fa-building"></i>
                        <span><strong>Department:</strong> <?php echo $student['department_name']; ?></span>
                    </div>
                    <div class="detail-item">
                        <i class="fas fa-book"></i>
                        <span><strong>Course:</strong> <?php echo $student['course_name']; ?></span>
                    </div>
                    <div class="detail-item">
                        <i class="fas fa-layer-group"></i>
                        <span><strong>Semester:</strong> <?php echo $student['semester_name'] ?? 'N/A'; ?></span>
                    </div>
                    <div class="detail-item">
                        <i class="fas fa-users"></i>
                        <span><strong>Section:</strong> <?php echo $student['section_name'] ?? 'N/A'; ?></span>
                    </div>
                    <div class="detail-item">
                        <i class="fas fa-envelope"></i>
                        <span><?php echo $student['email']; ?></span>
                    </div>
                    <div class="detail-item">
                        <i class="fas fa-phone"></i>
                        <span><?php echo $student['phone'] ?? 'N/A'; ?></span>
                    </div>
                    <div class="detail-item">
                        <i class="fas fa-chalkboard"></i>
                        <span><strong>Subject:</strong> <?php echo $subject['subject_name']; ?> (<?php echo $subject['subject_code']; ?>)</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Attendance Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon primary">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="stat-number"><?php echo $summary['total_classes']; ?></div>
                <div class="stat-label">Total Classes</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-number"><?php echo $summary['present_count']; ?></div>
                <div class="stat-label">Present</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon danger">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="stat-number"><?php echo $summary['absent_count']; ?></div>
                <div class="stat-label">Absent</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon warning">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-number"><?php echo $summary['late_count']; ?></div>
                <div class="stat-label">Late</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon <?php echo $summary['attendance_percentage'] >= 75 ? 'success' : ($summary['attendance_percentage'] >= 60 ? 'warning' : 'danger'); ?>">
                    <i class="fas fa-percentage"></i>
                </div>
                <div class="stat-number"><?php echo $summary['attendance_percentage']; ?>%</div>
                <div class="stat-label">Attendance Percentage</div>
            </div>
        </div>

        <!-- Monthly Attendance -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-chart-bar"></i>
                    <h3>Monthly Attendance Trend</h3>
                </div>
            </div>
            <div class="monthly-chart">
                <?php while($month = $monthly_data->fetch_assoc()): 
                    $month_percentage = round(($month['present'] / $month['total']) * 100, 2);
                    $month_name = date('F Y', strtotime($month['month'] . '-01'));
                ?>
                <div class="month-item">
                    <div class="month-label"><?php echo $month_name; ?></div>
                    <div class="month-bar">
                        <div class="month-bar-fill" style="width: <?php echo $month_percentage; ?>%">
                            <?php echo $month_percentage; ?>%
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        </div>

        <!-- Detailed Attendance Records -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-list"></i>
                    <h3>Attendance Records</h3>
                </div>
            </div>
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Day</th>
                        <th>Status</th>
                        <th>Marked By</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $sr_no = 1;
                    while($record = $attendance_records->fetch_assoc()): 
                        $status_badge = '';
                        switch($record['status']) {
                            case 'present':
                                $status_badge = 'success';
                                break;
                            case 'absent':
                                $status_badge = 'danger';
                                break;
                            case 'late':
                                $status_badge = 'warning';
                                break;
                        }
                    ?>
                    <tr>
                        <td><?php echo $sr_no++; ?></td>
                        <td><?php echo date('d M, Y', strtotime($record['attendance_date'])); ?></td>
                        <td><?php echo date('l', strtotime($record['attendance_date'])); ?></td>
                        <td>
                            <span class="badge <?php echo $status_badge; ?>">
                                <?php echo ucfirst($record['status']); ?>
                            </span>
                        </td>
                        <td><?php echo $record['marked_by_name']; ?></td>
                        <td><?php echo $record['remarks'] ?? '-'; ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
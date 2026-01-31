<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('student')) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

// Get student details
$sql = "SELECT s.*, d.department_name, c.course_name,
        (SELECT roll_number_display FROM student_roll_numbers srn 
         WHERE srn.student_id = s.student_id AND srn.is_active = 1 LIMIT 1) as roll_number
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

// Get filter parameters
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$selected_year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Parse month and year
$month_parts = explode('-', $selected_month);
$filter_year = isset($month_parts[0]) ? $month_parts[0] : $selected_year;
$filter_month = isset($month_parts[1]) ? $month_parts[1] : date('m');

// Get monthly statistics - Count unique days with at least one present
$monthly_stats_query = "SELECT 
                        COUNT(DISTINCT attendance_date) as total_days,
                        COUNT(DISTINCT CASE 
                            WHEN status = 'present' THEN attendance_date 
                        END) as present_days,
                        COUNT(DISTINCT CASE 
                            WHEN status = 'absent' AND attendance_date NOT IN (
                                SELECT DISTINCT attendance_date FROM attendance 
                                WHERE student_id = ? AND status = 'present'
                                AND YEAR(attendance_date) = ? 
                                AND MONTH(attendance_date) = ?
                            ) THEN attendance_date 
                        END) as absent_days
                        FROM attendance 
                        WHERE student_id = ? 
                        AND YEAR(attendance_date) = ? 
                        AND MONTH(attendance_date) = ?";

$stmt = $conn->prepare($monthly_stats_query);
$stmt->bind_param("iiiiii", 
    $student['student_id'], $filter_year, $filter_month,
    $student['student_id'], $filter_year, $filter_month
);
$stmt->execute();
$monthly_stats = $stmt->get_result()->fetch_assoc();

$total_days = $monthly_stats['total_days'] > 0 ? $monthly_stats['total_days'] : 0;
$present_count = $monthly_stats['present_days'];
$absent_count = $monthly_stats['absent_days'];
$late_count = 0; // Not counting late separately for day-level view

// Calculate percentage based on days
$attendance_percentage = $total_days > 0 ? round(($present_count / $total_days) * 100, 2) : 0;

// Get detailed attendance for the selected month
$detailed_attendance_query = "SELECT 
                              a.attendance_date,
                              a.status,
                              a.remarks,
                              sub.subject_name,
                              sub.subject_code,
                              t.full_name as teacher_name,
                              DAYNAME(a.attendance_date) as day_name,
                              TIME_FORMAT(a.created_at, '%h:%i %p') as marked_time
                              FROM attendance a
                              JOIN subjects sub ON a.subject_id = sub.subject_id
                              JOIN teachers t ON a.marked_by = t.teacher_id
                              WHERE a.student_id = ?
                              AND YEAR(a.attendance_date) = ?
                              AND MONTH(a.attendance_date) = ?
                              ORDER BY a.attendance_date DESC, a.created_at DESC";

$stmt = $conn->prepare($detailed_attendance_query);
$stmt->bind_param("iii", $student['student_id'], $filter_year, $filter_month);
$stmt->execute();
$detailed_records = $stmt->get_result();

// Get yearly comparison data - Count unique days with present status
$yearly_comparison_query = "SELECT 
                            DATE_FORMAT(attendance_date, '%M %Y') as month_name,
                            DATE_FORMAT(attendance_date, '%Y-%m') as month_key,
                            COUNT(DISTINCT attendance_date) as total_days,
                            COUNT(DISTINCT CASE 
                                WHEN status = 'present' THEN attendance_date 
                            END) as present_days
                            FROM attendance
                            WHERE student_id = ?
                            AND YEAR(attendance_date) = ?
                            GROUP BY DATE_FORMAT(attendance_date, '%Y-%m')
                            ORDER BY month_key DESC";

$stmt = $conn->prepare($yearly_comparison_query);
$stmt->bind_param("ii", $student['student_id'], $selected_year);
$stmt->execute();
$yearly_data = $stmt->get_result();

// Get available years for dropdown
$years_query = "SELECT DISTINCT YEAR(attendance_date) as year 
                FROM attendance 
                WHERE student_id = ? 
                ORDER BY year DESC";
$stmt = $conn->prepare($years_query);
$stmt->bind_param("i", $student['student_id']);
$stmt->execute();
$available_years = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Report - <?php echo htmlspecialchars($student['full_name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 30px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }

        .card-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-title i {
            font-size: 1.3rem;
        }

        /* Student Info */
        .student-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .info-label {
            font-size: 0.85rem;
            color: #667eea;
            font-weight: 600;
        }

        .info-value {
            font-size: 1rem;
            color: #333;
            font-weight: 500;
        }

        /* Filter Section */
        .filter-section {
            display: flex;
            gap: 20px;
            align-items: end;
            flex-wrap: wrap;
        }

        .form-group {
            flex: 1;
            min-width: 200px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 0.9rem;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 1rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Inter', sans-serif;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        }

        .stat-icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .stat-label {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 8px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 800;
        }

        .stat-card.total { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .stat-card.present { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); color: white; }
        .stat-card.absent { background: linear-gradient(135deg, #eb3349 0%, #f45c43 100%); color: white; }
        .stat-card.late { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; }
        .stat-card.percentage { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; }

        /* Table */
        .table-container {
            overflow-x: auto;
            border-radius: 15px;
            margin-top: 20px;
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
            padding: 15px;
            text-align: left;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        tbody tr {
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.2s;
        }

        tbody tr:hover {
            background: #f8f9ff;
        }

        tbody td {
            padding: 15px;
            color: #333;
            font-size: 0.95rem;
        }

        .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
            display: inline-block;
            text-transform: uppercase;
        }

        .status-badge.present {
            background: #d4edda;
            color: #155724;
        }

        .status-badge.absent {
            background: #f8d7da;
            color: #721c24;
        }

        .status-badge.late {
            background: #fff3cd;
            color: #856404;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.3;
        }

        .empty-state p {
            font-size: 1.1rem;
            color: #666;
        }

        /* Yearly Comparison */
        .comparison-table {
            margin-top: 20px;
        }

        .comparison-table table {
            min-width: 600px;
        }

        .percentage-cell {
            font-weight: 700;
        }

        .percentage-cell.good {
            color: #38ef7d;
        }

        .percentage-cell.warning {
            color: #f5576c;
        }

        .back-btn {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
            padding: 10px 20px;
            border-radius: 10px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            margin-bottom: 20px;
            transition: all 0.3s;
        }

        .back-btn:hover {
            background: #667eea;
            color: white;
            transform: translateY(-2px);
        }

        @media (max-width: 768px) {
            body {
                padding: 15px;
            }

            .card {
                padding: 20px;
            }

            .card-title {
                font-size: 1.2rem;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 15px;
            }

            .stat-value {
                font-size: 2rem;
            }

            .filter-section {
                flex-direction: column;
            }

            .form-group {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>

        <!-- Student Information Card -->
        <div class="card">
            <h2 class="card-title">
                <i class="fas fa-chart-bar"></i>
                Attendance Report
            </h2>
            <div class="student-info">
                <div class="info-item">
                    <span class="info-label">Name:</span>
                    <span class="info-value"><?php echo htmlspecialchars($student['full_name']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Roll Number:</span>
                    <span class="info-value"><?php echo htmlspecialchars($student['roll_number'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Class/Section:</span>
                    <span class="info-value"><?php echo htmlspecialchars($current_sem['section_name'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Department:</span>
                    <span class="info-value"><?php echo htmlspecialchars($student['department_name']); ?></span>
                </div>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="card">
            <h2 class="card-title">
                <i class="fas fa-filter"></i>
                Filter Report
            </h2>
            <form method="GET" action="" class="filter-section">
                <div class="form-group">
                    <label for="month">Select Month:</label>
                    <input type="month" id="month" name="month" value="<?php echo $selected_month; ?>" max="<?php echo date('Y-m'); ?>">
                </div>
                <div class="form-group">
                    <label for="year">Select Year:</label>
                    <select id="year" name="year">
                        <?php
                        $available_years->data_seek(0);
                        if ($available_years->num_rows > 0) {
                            while ($year_row = $available_years->fetch_assoc()) {
                                $year = $year_row['year'];
                                echo "<option value='$year'" . ($year == $selected_year ? " selected" : "") . ">$year</option>";
                            }
                        } else {
                            // Default years if no data
                            for ($y = date('Y'); $y >= date('Y') - 5; $y--) {
                                echo "<option value='$y'" . ($y == $selected_year ? " selected" : "") . ">$y</option>";
                            }
                        }
                        ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> View Report
                </button>
            </form>
        </div>

        <!-- Statistics Grid -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-icon">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="stat-label">Total Days</div>
                <div class="stat-value"><?php echo $total_days; ?></div>
            </div>

            <div class="stat-card present">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-label">Present</div>
                <div class="stat-value"><?php echo $present_count; ?></div>
            </div>

            <div class="stat-card absent">
                <div class="stat-icon">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="stat-label">Absent</div>
                <div class="stat-value"><?php echo $absent_count; ?></div>
            </div>

            <div class="stat-card late">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-label">Late</div>
                <div class="stat-value"><?php echo $late_count; ?></div>
            </div>

            <div class="stat-card percentage">
                <div class="stat-icon">
                    <i class="fas fa-percentage"></i>
                </div>
                <div class="stat-label">Attendance %</div>
                <div class="stat-value"><?php echo $attendance_percentage; ?>%</div>
            </div>
        </div>

        <!-- Detailed Attendance Table -->
        <div class="card">
            <h2 class="card-title">
                <i class="fas fa-list"></i>
                Detailed Attendance for <?php echo date('F Y', strtotime($selected_month . '-01')); ?>
            </h2>

            <?php if ($detailed_records->num_rows > 0): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Day</th>
                            <th>Class</th>
                            <th>Status</th>
                            <th>Remarks</th>
                            <th>Marked By</th>
                            <th>Marked At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($record = $detailed_records->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?php echo date('d M Y', strtotime($record['attendance_date'])); ?></strong></td>
                            <td><?php echo $record['day_name']; ?></td>
                            <td>
                                <?php echo htmlspecialchars($record['subject_name']); ?><br>
                                <small style="color: #999;"><?php echo htmlspecialchars($record['subject_code']); ?></small>
                            </td>
                            <td>
                                <span class="status-badge <?php echo strtolower($record['status']); ?>">
                                    <?php echo ucfirst($record['status']); ?>
                                </span>
                            </td>
                            <td><?php echo $record['remarks'] ? htmlspecialchars($record['remarks']) : '-'; ?></td>
                            <td>
                                <i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($record['teacher_name']); ?>
                            </td>
                            <td><?php echo $record['marked_time']; ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-calendar-times"></i>
                <p>No attendance records found for <?php echo date('F Y', strtotime($selected_month . '-01')); ?></p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Yearly Comparison -->
        <div class="card">
            <h2 class="card-title">
                <i class="fas fa-chart-line"></i>
                Yearly Comparison - <?php echo $selected_year; ?>
            </h2>

            <?php 
            $yearly_data->data_seek(0);
            if ($yearly_data->num_rows > 0): 
            ?>
            <div class="table-container comparison-table">
                <table>
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Total Days</th>
                            <th>Present</th>
                            <th>Attendance %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($year_record = $yearly_data->fetch_assoc()): 
                            $year_percentage = round(($year_record['present_days'] / $year_record['total_days']) * 100, 2);
                            $percentage_class = $year_percentage >= 75 ? 'good' : 'warning';
                        ?>
                        <tr>
                            <td><strong><?php echo $year_record['month_name']; ?></strong></td>
                            <td><?php echo $year_record['total_days']; ?></td>
                            <td><?php echo $year_record['present_days']; ?></td>
                            <td class="percentage-cell <?php echo $percentage_class; ?>">
                                <?php echo $year_percentage; ?>%
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-chart-bar"></i>
                <p>No data available for <?php echo $selected_year; ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Auto-sync month and year
        document.getElementById('month').addEventListener('change', function() {
            const monthValue = this.value;
            if (monthValue) {
                const year = monthValue.split('-')[0];
                document.getElementById('year').value = year;
            }
        });

        // Print functionality (optional)
        function printReport() {
            window.print();
        }
    </script>
</body>
</html>
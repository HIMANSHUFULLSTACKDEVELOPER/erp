<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('hod')) {
    redirect('login.php');
}

// Get filter parameters
$filter_academic_year = isset($_GET['academic_year']) ? $_GET['academic_year'] : '2025-2026';
$filter_semester = isset($_GET['semester']) ? $_GET['semester'] : '1';
$filter_start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$filter_end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Get all semesters
$semesters_query = "SELECT semester_id, semester_name FROM semesters ORDER BY semester_number";
$semesters_result = $conn->query($semesters_query);

// Get all sections for the selected semester
$sections_query = "
    SELECT DISTINCT 
        sec.section_id,
        sec.section_name,
        d.department_name
    FROM sections sec
    JOIN student_roll_numbers srn ON sec.section_id = srn.section_id
    JOIN departments d ON srn.department_id = d.department_id
    WHERE srn.semester_id = ? AND srn.is_active = 1
    ORDER BY sec.section_name
";
$sections_stmt = $conn->prepare($sections_query);
$sections_stmt->bind_param('i', $filter_semester);
$sections_stmt->execute();
$sections_result = $sections_stmt->get_result();

$sections = [];
while ($section = $sections_result->fetch_assoc()) {
    $sections[] = $section;
}

// Generate date range
$start = new DateTime($filter_start_date);
$end = new DateTime($filter_end_date);
$interval = new DateInterval('P1D');
$date_range = new DatePeriod($start, $interval, $end->modify('+1 day'));

// Get attendance data for each date and section
$report_data = [];
$section_totals = [];
$date_totals = [];

foreach ($date_range as $date) {
    $current_date = $date->format('Y-m-d');
    $report_row = [
        'date' => $current_date,
        'sections' => []
    ];
    
    $daily_total = 0;
    
    foreach ($sections as $section) {
        // Get total students in this section
        $total_query = "
            SELECT COUNT(DISTINCT srn.student_id) as total
            FROM student_roll_numbers srn
            WHERE srn.section_id = ? 
            AND srn.semester_id = ?
            AND srn.is_active = 1
        ";
        $total_stmt = $conn->prepare($total_query);
        $total_stmt->bind_param('ii', $section['section_id'], $filter_semester);
        $total_stmt->execute();
        $total_result = $total_stmt->get_result();
        $total_students = $total_result->fetch_assoc()['total'];
        
        // Get attendance count for this section on this date
        // Count distinct students who were marked present (first attendance of the day)
        $attendance_query = "
            SELECT COUNT(DISTINCT a.student_id) as present_count
            FROM attendance a
            WHERE a.section_id = ?
            AND a.semester_id = ?
            AND DATE(a.attendance_date) = ?
            AND a.status = 'present'
        ";
        $attendance_stmt = $conn->prepare($attendance_query);
        $attendance_stmt->bind_param('iis', $section['section_id'], $filter_semester, $current_date);
        $attendance_stmt->execute();
        $attendance_result = $attendance_stmt->get_result();
        $present_count = $attendance_result->fetch_assoc()['present_count'];
        
        $report_row['sections'][$section['section_name']] = $present_count;
        $daily_total += $present_count;
        
        // Update section totals
        if (!isset($section_totals[$section['section_name']])) {
            $section_totals[$section['section_name']] = [
                'total' => 0,
                'max_possible' => 0
            ];
        }
        $section_totals[$section['section_name']]['total'] += $present_count;
        $section_totals[$section['section_name']]['max_possible'] += $total_students;
    }
    
    $report_row['total'] = $daily_total;
    
    // Calculate total possible attendance for this date
    $total_possible = 0;
    foreach ($sections as $section) {
        $total_query = "
            SELECT COUNT(DISTINCT srn.student_id) as total
            FROM student_roll_numbers srn
            WHERE srn.section_id = ? 
            AND srn.semester_id = ?
            AND srn.is_active = 1
        ";
        $total_stmt = $conn->prepare($total_query);
        $total_stmt->bind_param('ii', $section['section_id'], $filter_semester);
        $total_stmt->execute();
        $total_result = $total_stmt->get_result();
        $total_possible += $total_result->fetch_assoc()['total'];
    }
    
    $report_row['percentage'] = $total_possible > 0 ? round(($daily_total / $total_possible) * 100, 2) : 0;
    
    $date_totals[] = $report_row['percentage'];
    $report_data[] = $report_row;
}

// Calculate grand totals
$grand_total = 0;
$grand_max_possible = 0;
foreach ($section_totals as $section_name => $data) {
    $grand_total += $data['total'];
    $grand_max_possible += $data['max_possible'];
}
$grand_percentage = $grand_max_possible > 0 ? round(($grand_total / $grand_max_possible) * 100, 2) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consolidated Attendance Report - College ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --secondary: #8b5cf6;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --dark: #1f2937;
            --gray: #6b7280;
            --light-gray: #f3f4f6;
            --white: #ffffff;
            --gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1600px;
            margin: 0 auto;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.2);
            color: var(--white);
            padding: 10px 20px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            margin-bottom: 20px;
        }

        .back-btn:hover {
            background: var(--white);
            color: var(--primary);
            transform: translateX(-5px);
        }

        .filter-section {
            background: rgba(255,255,255,0.95);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .filter-section h3 {
            color: var(--primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.3rem;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filter-group label {
            color: var(--dark);
            font-weight: 600;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .filter-group input,
        .filter-group select {
            padding: 10px 12px;
            border: 2px solid var(--light-gray);
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--primary);
        }

        .btn-generate {
            background: var(--primary);
            color: var(--white);
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-generate:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(99, 102, 241, 0.3);
        }

        .report-section {
            background: var(--white);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .report-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 3px solid var(--primary);
        }

        .report-header h1 {
            font-size: 2rem;
            color: var(--dark);
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .report-header h2 {
            font-size: 1.5rem;
            color: var(--primary);
            margin-bottom: 10px;
        }

        .report-header p {
            color: var(--gray);
            font-size: 1rem;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .btn-action {
            padding: 10px 25px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-excel {
            background: var(--success);
            color: var(--white);
        }

        .btn-excel:hover {
            background: #059669;
            transform: translateY(-2px);
        }

        .btn-print {
            background: var(--gray);
            color: var(--white);
        }

        .btn-print:hover {
            background: #4b5563;
            transform: translateY(-2px);
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        thead {
            background: var(--gradient);
        }

        th {
            padding: 15px 10px;
            text-align: center;
            font-weight: 600;
            color: var(--white);
            font-size: 0.9rem;
            text-transform: uppercase;
            border: 1px solid rgba(255,255,255,0.3);
        }

        tbody tr {
            transition: all 0.3s ease;
        }

        tbody tr:nth-child(even) {
            background: var(--light-gray);
        }

        tbody tr:hover {
            background: rgba(102, 126, 234, 0.1);
            transform: scale(1.01);
        }

        td {
            padding: 12px 10px;
            text-align: center;
            border: 1px solid #e5e7eb;
            color: var(--dark);
        }

        .percentage {
            font-weight: 700;
            padding: 5px 10px;
            border-radius: 5px;
        }

        .percentage.excellent {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }

        .percentage.good {
            background: rgba(245, 158, 11, 0.2);
            color: var(--warning);
        }

        .percentage.poor {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
        }

        .grand-total-row {
            background: var(--gradient) !important;
            font-weight: 700;
        }

        .grand-total-row td {
            color: var(--white) !important;
            font-size: 1.1rem;
            border-color: rgba(255,255,255,0.3);
        }

        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            .filter-section,
            .action-buttons,
            .back-btn {
                display: none !important;
            }
            
            .report-section {
                box-shadow: none;
                padding: 20px;
            }
        }

        @media (max-width: 768px) {
            body {
                padding: 10px;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .report-header h1 {
                font-size: 1.5rem;
            }

            .report-header h2 {
                font-size: 1.2rem;
            }

            th, td {
                padding: 8px 5px;
                font-size: 0.8rem;
            }

            .action-buttons {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>

        <div class="filter-section">
            <h3><i class="fas fa-filter"></i> Filter Report</h3>
            <form method="GET" action="">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label><i class="fas fa-graduation-cap"></i> Academic Year</label>
                        <select name="academic_year" required>
                            <option value="2025-2026" <?php echo $filter_academic_year == '2025-2026' ? 'selected' : ''; ?>>2025-2026</option>
                            <option value="2024-2025" <?php echo $filter_academic_year == '2024-2025' ? 'selected' : ''; ?>>2024-2025</option>
                            <option value="2023-2024" <?php echo $filter_academic_year == '2023-2024' ? 'selected' : ''; ?>>2023-2024</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label><i class="fas fa-book"></i> Semester</label>
                        <select name="semester" required>
                            <?php 
                            $semesters_result->data_seek(0);
                            while($sem = $semesters_result->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $sem['semester_id']; ?>" 
                                    <?php echo $filter_semester == $sem['semester_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($sem['semester_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label><i class="fas fa-calendar-alt"></i> Start Date</label>
                        <input type="date" name="start_date" value="<?php echo $filter_start_date; ?>" required>
                    </div>

                    <div class="filter-group">
                        <label><i class="fas fa-calendar-check"></i> End Date</label>
                        <input type="date" name="end_date" value="<?php echo $filter_end_date; ?>" required>
                    </div>
                </div>
                <button type="submit" class="btn-generate">
                    <i class="fas fa-chart-line"></i> Generate Report
                </button>
            </form>
        </div>

        <div class="report-section">
            <div class="report-header">
                <h1>CONSOLIDATED REPORT</h1>
                <h2>Semester <?php echo $filter_semester; ?> - Academic Year <?php echo $filter_academic_year; ?></h2>
                <p>Date Range: <strong><?php echo date('d M Y', strtotime($filter_start_date)); ?></strong> to <strong><?php echo date('d M Y', strtotime($filter_end_date)); ?></strong></p>
            </div>

            <div class="action-buttons">
                <button class="btn-action btn-excel" onclick="exportToExcel()">
                    <i class="fas fa-file-excel"></i> Export to Excel
                </button>
                <button class="btn-action btn-print" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>

            <div class="table-container">
                <table id="reportTable">
                    <thead>
                        <tr>
                            <th>SR NO</th>
                            <th>DATE</th>
                            <?php foreach ($sections as $section): ?>
                                <th><?php echo strtoupper(htmlspecialchars($section['section_name'])); ?></th>
                            <?php endforeach; ?>
                            <th>TOTAL</th>
                            <th>%</th>
                            <th>REMARK</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $sr_no = 1;
                        foreach ($report_data as $row): 
                            $percentage_class = $row['percentage'] >= 75 ? 'excellent' : ($row['percentage'] >= 50 ? 'good' : 'poor');
                        ?>
                        <tr>
                            <td><?php echo $sr_no++; ?></td>
                            <td><strong><?php echo date('d/m/Y', strtotime($row['date'])); ?></strong></td>
                            <?php foreach ($sections as $section): ?>
                                <td><?php echo $row['sections'][$section['section_name']] ?? 0; ?></td>
                            <?php endforeach; ?>
                            <td><strong><?php echo $row['total']; ?></strong></td>
                            <td><span class="percentage <?php echo $percentage_class; ?>"><?php echo $row['percentage']; ?>%</span></td>
                            <td>-</td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <tr class="grand-total-row">
                            <td colspan="2"><i class="fas fa-calculator"></i> GRAND TOTAL</td>
                            <?php foreach ($sections as $section): ?>
                                <td><?php echo $section_totals[$section['section_name']]['total'] ?? 0; ?></td>
                            <?php endforeach; ?>
                            <td><?php echo $grand_total; ?></td>
                            <td><?php echo $grand_percentage; ?>%</td>
                            <td>-</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script>
        function exportToExcel() {
            const table = document.getElementById('reportTable');
            const wb = XLSX.utils.table_to_book(table, {sheet: "Attendance Report"});
            const filename = `Attendance_Report_${new Date().toISOString().split('T')[0]}.xlsx`;
            XLSX.writeFile(wb, filename);
        }

        // Animation on load
        document.addEventListener('DOMContentLoaded', function() {
            const rows = document.querySelectorAll('tbody tr:not(.grand-total-row)');
            rows.forEach((row, index) => {
                setTimeout(() => {
                    row.style.opacity = '0';
                    row.style.transform = 'translateY(20px)';
                    row.style.transition = 'all 0.3s ease';
                    setTimeout(() => {
                        row.style.opacity = '1';
                        row.style.transform = 'translateY(0)';
                    }, 50);
                }, index * 30);
            });
        });
    </script>
</body>
</html>
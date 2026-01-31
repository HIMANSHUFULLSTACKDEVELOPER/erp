<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('admin')) {
    redirect('login.php');
}

// Get filter parameters
$filter_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$filter_admission_year = isset($_GET['admission_year']) ? $_GET['admission_year'] : '';
$filter_department = isset($_GET['department']) ? $_GET['department'] : '';
$filter_academic_year = isset($_GET['academic_year']) ? $_GET['academic_year'] : '';
$filter_semester = isset($_GET['semester']) ? $_GET['semester'] : '';

// Get all departments
$departments_query = "SELECT department_id, department_name FROM departments ORDER BY department_name";
$departments_result = $conn->query($departments_query);

// Get all semesters
$semesters_query = "SELECT semester_id, semester_name FROM semesters ORDER BY semester_number";
$semesters_result = $conn->query($semesters_query);

// Build the main query - Count ALL students from roll, then check their attendance
// For 1st lecture: Count students with ANY 'present' attendance in morning (before 12 PM)
// For 5th lecture: Count students with ANY 'present' attendance in afternoon (after 12 PM)
$summary_query = "
    SELECT 
        ? as attendance_date,
        d.department_name,
        s.admission_year,
        sem.semester_id,
        sem.semester_name,
        sec.section_name,
        COUNT(DISTINCT srn.student_id) as total_students,
        COUNT(DISTINCT CASE 
            WHEN a1.status = 'present' 
            THEN a1.student_id 
        END) as present_1st_lecture,
        COUNT(DISTINCT CASE 
            WHEN a2.status = 'present' 
            THEN a2.student_id 
        END) as present_5th_lecture
    FROM student_roll_numbers srn
    JOIN students s ON srn.student_id = s.student_id
    JOIN departments d ON srn.department_id = d.department_id
    JOIN semesters sem ON srn.semester_id = sem.semester_id
    JOIN sections sec ON srn.section_id = sec.section_id
    
    -- Join for morning attendance (1st lecture period - before 12 PM)
    LEFT JOIN attendance a1 ON a1.student_id = srn.student_id 
        AND DATE(a1.attendance_date) = ?
        AND a1.semester_id = srn.semester_id
        AND a1.section_id = srn.section_id
        AND HOUR(a1.created_at) < 12
    
    -- Join for afternoon attendance (5th lecture period - 12 PM onwards)
    LEFT JOIN attendance a2 ON a2.student_id = srn.student_id 
        AND DATE(a2.attendance_date) = ?
        AND a2.semester_id = srn.semester_id
        AND a2.section_id = srn.section_id
        AND HOUR(a2.created_at) >= 12
    
    WHERE srn.is_active = 1
    " . ($filter_admission_year ? " AND s.admission_year = ?" : "") . "
    " . ($filter_department ? " AND d.department_id = ?" : "") . "
    " . ($filter_semester ? " AND sem.semester_id = ?" : "") . "
    GROUP BY d.department_id, s.admission_year, sem.semester_id, sec.section_id
    ORDER BY d.department_name, sec.section_name
";

$stmt = $conn->prepare($summary_query);
if ($stmt) {
    // Build parameters array dynamically
    // Date appears 3 times in query now (display, morning join, afternoon join)
    $bind_params = [$filter_date, $filter_date, $filter_date];
    $bind_types = 'sss';
    
    if ($filter_admission_year) {
        $bind_params[] = $filter_admission_year;
        $bind_types .= 'i';
    }
    if ($filter_department) {
        $bind_params[] = $filter_department;
        $bind_types .= 'i';
    }
    if ($filter_semester) {
        $bind_params[] = $filter_semester;
        $bind_types .= 'i';
    }
    
    $stmt->bind_param($bind_types, ...$bind_params);
    $stmt->execute();
    $summary_result = $stmt->get_result();
} else {
    die("Query preparation failed: " . $conn->error);
}

// Calculate grand totals
$grand_total_students = 0;
$grand_total_1st = 0;
$grand_total_5th = 0;

$summary_data = [];
while ($row = $summary_result->fetch_assoc()) {
    $summary_data[] = $row;
    $grand_total_students += $row['total_students'];
    $grand_total_1st += $row['present_1st_lecture'];
    $grand_total_5th += $row['present_5th_lecture'];
}

// Get distinct admission years
$years_query = "SELECT DISTINCT admission_year FROM students ORDER BY admission_year DESC";
$years_result = $conn->query($years_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Attendance Report - College ERP</title>
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
            max-width: 1400px;
            margin: 0 auto;
        }

        .header {
            background: var(--white);
            border-radius: 20px;
            padding: 30px;
            text-align: center;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .header h1 {
            color: var(--primary);
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .header .subtitle {
            color: var(--gray);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 1.1rem;
        }

        .filters-section {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.95) 0%, rgba(118, 75, 162, 0.95) 100%);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filter-group label {
            color: var(--white);
            font-weight: 600;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .filter-group input,
        .filter-group select {
            padding: 12px 15px;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            background: var(--white);
            color: var(--dark);
            transition: all 0.3s ease;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }

        .button-group {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--white);
            color: var(--primary);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255,255,255,0.3);
        }

        .btn-secondary {
            background: rgba(255,255,255,0.2);
            color: var(--white);
            border: 2px solid var(--white);
        }

        .btn-secondary:hover {
            background: var(--white);
            color: var(--primary);
            transform: translateY(-2px);
        }

        .active-filters {
            background: rgba(255,255,255,0.15);
            border-left: 4px solid var(--white);
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .active-filters h4 {
            color: var(--white);
            font-size: 0.9rem;
            margin-bottom: 10px;
            font-weight: 600;
        }

        .filter-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .filter-tag {
            background: var(--white);
            color: var(--primary);
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .table-container {
            background: var(--white);
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
        }

        thead {
            background: var(--gradient);
        }

        th {
            padding: 15px;
            text-align: center;
            font-weight: 600;
            color: var(--white);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        tbody tr {
            border-bottom: 1px solid var(--light-gray);
            transition: all 0.3s ease;
        }

        tbody tr:hover {
            background: rgba(102, 126, 234, 0.05);
        }

        td {
            padding: 15px;
            text-align: center;
            color: var(--dark);
        }

        .section-name {
            font-weight: 600;
            color: var(--primary);
        }

        .percentage {
            font-weight: 700;
        }

        .percentage.zero {
            color: var(--danger);
        }

        .percentage.low {
            color: var(--warning);
        }

        .percentage.good {
            color: var(--success);
        }

        .grand-total-row {
            background: var(--gradient);
            font-weight: 700;
            color: var(--white);
        }

        .grand-total-row td {
            color: var(--white);
            font-size: 1.1rem;
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

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 4rem;
            color: var(--light-gray);
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 10px;
            color: var(--dark);
        }

        @media (max-width: 768px) {
            body {
                padding: 10px;
            }

            .header h1 {
                font-size: 1.5rem;
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .button-group {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .table-container {
                padding: 10px;
            }

            th, td {
                padding: 10px 5px;
                font-size: 0.85rem;
            }
        }

        @media (max-width: 480px) {
            .header {
                padding: 20px;
            }

            .header h1 {
                font-size: 1.2rem;
            }

            .filters-section {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>

        <div class="header">
            <h1>Nagpur Institute of Technology, Nagpur</h1>
            <div class="subtitle">
                <i class="fas fa-chart-bar"></i>
                <span>Daily Attendance Report</span>
            </div>
        </div>

        <div class="filters-section">
            <form method="GET" action="">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label>
                            <i class="fas fa-calendar"></i> Date:
                        </label>
                        <input type="date" name="date" value="<?php echo htmlspecialchars($filter_date); ?>" required>
                    </div>

                    <div class="filter-group">
                        <label>
                            <i class="fas fa-graduation-cap"></i> Admission Year:
                        </label>
                        <select name="admission_year">
                            <option value="">All Years</option>
                            <?php 
                            $years_result->data_seek(0);
                            while($year = $years_result->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $year['admission_year']; ?>" 
                                    <?php echo $filter_admission_year == $year['admission_year'] ? 'selected' : ''; ?>>
                                    <?php echo $year['admission_year'] . '-' . ($year['admission_year'] + 1); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>
                            <i class="fas fa-building"></i> Department:
                        </label>
                        <select name="department">
                            <option value="">All Departments</option>
                            <?php 
                            $departments_result->data_seek(0);
                            while($dept = $departments_result->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $dept['department_id']; ?>" 
                                    <?php echo $filter_department == $dept['department_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['department_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>
                            <i class="fas fa-calendar-alt"></i> Academic Year:
                        </label>
                        <select name="academic_year">
                            <option value="">All Years</option>
                            <option value="1" <?php echo $filter_academic_year == '1' ? 'selected' : ''; ?>>1st Year</option>
                            <option value="2" <?php echo $filter_academic_year == '2' ? 'selected' : ''; ?>>2nd Year</option>
                            <option value="3" <?php echo $filter_academic_year == '3' ? 'selected' : ''; ?>>3rd Year</option>
                            <option value="4" <?php echo $filter_academic_year == '4' ? 'selected' : ''; ?>>4th Year</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>
                            <i class="fas fa-book"></i> Semester:
                        </label>
                        <select name="semester">
                            <option value="">All Semesters</option>
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
                </div>

                <div class="button-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <a href="dailyattandance.php?date=<?php echo date('Y-m-d'); ?>" class="btn btn-secondary">
                        <i class="fas fa-sync-alt"></i> Reset
                    </a>
                </div>
            </form>

            <?php if ($filter_date || $filter_admission_year || $filter_department || $filter_academic_year || $filter_semester): ?>
            <div class="active-filters">
                <h4><i class="fas fa-filter"></i> Active Filters:</h4>
                <div class="filter-tags">
                    <?php if ($filter_date): ?>
                        <span class="filter-tag">Date: <?php echo date('d M Y', strtotime($filter_date)); ?></span>
                    <?php endif; ?>
                    <?php if ($filter_admission_year): ?>
                        <span class="filter-tag">Admission Year: <?php echo $filter_admission_year . '-' . ($filter_admission_year + 1); ?></span>
                    <?php endif; ?>
                    <?php if ($filter_department): 
                        $dept_query = $conn->query("SELECT department_name FROM departments WHERE department_id = $filter_department");
                        $dept_name = $dept_query->fetch_assoc()['department_name'];
                    ?>
                        <span class="filter-tag">Department: <?php echo htmlspecialchars($dept_name); ?></span>
                    <?php endif; ?>
                    <?php if ($filter_academic_year): ?>
                        <span class="filter-tag">Year: <?php echo $filter_academic_year; ?></span>
                    <?php endif; ?>
                    <?php if ($filter_semester): 
                        $sem_query = $conn->query("SELECT semester_name FROM semesters WHERE semester_id = $filter_semester");
                        $sem_name = $sem_query->fetch_assoc()['semester_name'];
                    ?>
                        <span class="filter-tag">Semester: <?php echo htmlspecialchars($sem_name); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="table-container">
            <?php if (count($summary_data) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>DATE</th>
                        <th>DEPARTMENT</th>
                        <th>YEAR</th>
                        <th>SEMESTER</th>
                        <th>SECTION</th>
                        <th>TOTAL STUDENTS<br>ON ROLL</th>
                        <th colspan="2">PRESENT (1ST LECTURE)</th>
                        <th colspan="2">PRESENT (5TH LECTURE)</th>
                    </tr>
                    <tr>
                        <th></th>
                        <th></th>
                        <th></th>
                        <th></th>
                        <th></th>
                        <th></th>
                        <th>COUNT</th>
                        <th>%</th>
                        <th>COUNT</th>
                        <th>%</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($summary_data as $row): 
                        $percentage_1st = $row['total_students'] > 0 ? round(($row['present_1st_lecture'] / $row['total_students']) * 100) : 0;
                        $percentage_5th = $row['total_students'] > 0 ? round(($row['present_5th_lecture'] / $row['total_students']) * 100) : 0;
                        
                        $class_1st = $percentage_1st == 0 ? 'zero' : ($percentage_1st < 75 ? 'low' : 'good');
                        $class_5th = $percentage_5th == 0 ? 'zero' : ($percentage_5th < 75 ? 'low' : 'good');
                    ?>
                    <tr>
                        <td><?php echo date('d-M-y', strtotime($row['attendance_date'])); ?></td>
                        <td><?php echo htmlspecialchars($row['department_name']); ?></td>
                        <td><?php echo $row['admission_year']; ?></td>
                        <td><?php echo $row['semester_id']; ?></td>
                        <td class="section-name"><?php echo htmlspecialchars($row['section_name']); ?></td>
                        <td><strong><?php echo $row['total_students']; ?></strong></td>
                        <td><strong><?php echo $row['present_1st_lecture']; ?></strong></td>
                        <td><span class="percentage <?php echo $class_1st; ?>"><?php echo $percentage_1st; ?>%</span></td>
                        <td><strong><?php echo $row['present_5th_lecture']; ?></strong></td>
                        <td><span class="percentage <?php echo $class_5th; ?>"><?php echo $percentage_5th; ?>%</span></td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <tr class="grand-total-row">
                        <td colspan="5"><i class="fas fa-calculator"></i> GRAND TOTAL</td>
                        <td><?php echo $grand_total_students; ?></td>
                        <td><?php echo $grand_total_1st; ?></td>
                        <td><?php echo $grand_total_students > 0 ? round(($grand_total_1st / $grand_total_students) * 100) : 0; ?>%</td>
                        <td><?php echo $grand_total_5th; ?></td>
                        <td><?php echo $grand_total_students > 0 ? round(($grand_total_5th / $grand_total_students) * 100) : 0; ?>%</td>
                    </tr>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>No Student Data Found</h3>
                <p>No students are enrolled for the selected filters. Please check if students are assigned to sections and semesters.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Auto-submit form when date changes
        document.querySelector('input[name="date"]').addEventListener('change', function() {
            this.form.submit();
        });

        // Add animation on load
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
                }, index * 50);
            });
        });
    </script>
</body>
</html>
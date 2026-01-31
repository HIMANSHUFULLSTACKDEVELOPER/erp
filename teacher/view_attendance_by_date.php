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

// Get filters
$selected_academic_year = $_GET['academic_year'] ?? $default_year ?? date('Y') . '-' . (date('Y') + 1);
$selected_date = $_GET['date'] ?? date('Y-m-d');

// Get all attendance for selected date
$date_attendance_sql = "SELECT 
                            a.subject_id,
                            a.semester_id,
                            a.section_id,
                            sub.subject_name,
                            sub.subject_code,
                            sem.semester_name,
                            sec.section_name,
                            COUNT(DISTINCT a.student_id) as total_students,
                            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                            SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
                            MAX(a.created_at) as last_marked_time
                         FROM attendance a
                         JOIN subjects sub ON a.subject_id = sub.subject_id
                         JOIN semesters sem ON a.semester_id = sem.semester_id
                         LEFT JOIN sections sec ON a.section_id = sec.section_id
                         JOIN subject_teachers st ON a.subject_id = st.subject_id 
                             AND a.semester_id = st.semester_id
                             AND (a.section_id = st.section_id OR (a.section_id IS NULL AND st.section_id IS NULL))
                         WHERE st.teacher_id = ?
                         AND a.attendance_date = ?
                         AND st.academic_year = ?
                         GROUP BY a.subject_id, a.semester_id, a.section_id, sub.subject_name, sub.subject_code, sem.semester_name, sec.section_name
                         ORDER BY sem.semester_name, sub.subject_name";

$stmt = $conn->prepare($date_attendance_sql);
$stmt->bind_param("iss", $teacher['teacher_id'], $selected_date, $selected_academic_year);
$stmt->execute();
$date_attendance_result = $stmt->get_result();

// Calculate statistics
$overall_total = 0;
$overall_present = 0;
$overall_absent = 0;
$overall_late = 0;
$classes_marked = 0;

if ($date_attendance_result->num_rows > 0) {
    $date_attendance_result->data_seek(0);
    while($class = $date_attendance_result->fetch_assoc()) {
        $overall_total += $class['total_students'];
        $overall_present += $class['present_count'];
        $overall_absent += $class['absent_count'];
        $overall_late += $class['late_count'];
        $classes_marked++;
    }
}

$overall_percentage = $overall_total > 0 ? round(($overall_present / $overall_total) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Attendance by Date - College ERP</title>
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

        /* Filters Card */
        .filters-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .filters-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .filters-header i {
            font-size: 1.5rem;
            color: var(--primary);
        }

        .filters-header h2 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--dark);
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .filter-field {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-field label {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-field label i {
            color: var(--primary);
        }

        .filter-input {
            padding: 0.875rem 1rem;
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 1rem;
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            background: var(--white);
            transition: all 0.3s ease;
        }

        .filter-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }

        select.filter-input {
            cursor: pointer;
        }

        /* Quick Date Buttons */
        .quick-dates {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }

        .quick-date-btn {
            padding: 0.625rem 1.25rem;
            background: var(--light);
            border: 2px solid var(--border);
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
            color: var(--dark);
        }

        .quick-date-btn:hover,
        .quick-date-btn.active {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        /* Overall Stats - Same as today's page */
        .overall-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
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

        .stat-card.classes::before {
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }

        .stat-card.total::before {
            background: linear-gradient(90deg, var(--info), #2563eb);
        }

        .stat-card.present::before {
            background: linear-gradient(90deg, var(--success), #16a34a);
        }

        .stat-card.absent::before {
            background: linear-gradient(90deg, var(--danger), #dc2626);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin-bottom: 1rem;
        }

        .stat-card.classes .stat-icon {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.15), rgba(139, 92, 246, 0.15));
            color: var(--primary);
        }

        .stat-card.total .stat-icon {
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
            font-size: 2.5rem;
            font-weight: 900;
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .stat-card.classes .stat-value {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .stat-card.total .stat-value {
            color: var(--info);
        }

        .stat-card.present .stat-value {
            color: var(--success);
        }

        .stat-card.absent .stat-value {
            color: var(--danger);
        }

        .stat-label {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-percentage {
            font-size: 1.25rem;
            font-weight: 700;
            margin-top: 0.5rem;
        }

        .stat-card.present .stat-percentage {
            color: var(--success);
        }

        /* Classes Grid - Same as today's page */
        .classes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .class-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 1.75rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .class-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
            border-color: var(--primary);
        }

        .class-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.25rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--border);
        }

        .class-info h3 {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .class-meta {
            font-size: 0.875rem;
            color: var(--gray);
            font-weight: 500;
        }

        .attendance-time {
            background: rgba(99, 102, 241, 0.1);
            color: var(--primary);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .class-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .mini-stat {
            text-align: center;
            padding: 0.75rem;
            border-radius: 12px;
            background: var(--light);
        }

        .mini-stat.present {
            background: rgba(34, 197, 94, 0.1);
        }

        .mini-stat.absent {
            background: rgba(239, 68, 68, 0.1);
        }

        .mini-stat.late {
            background: rgba(245, 158, 11, 0.1);
        }

        .mini-stat-value {
            font-size: 1.5rem;
            font-weight: 800;
            margin-bottom: 0.25rem;
        }

        .mini-stat.present .mini-stat-value {
            color: var(--success);
        }

        .mini-stat.absent .mini-stat-value {
            color: var(--danger);
        }

        .mini-stat.late .mini-stat-value {
            color: var(--warning);
        }

        .mini-stat-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--gray);
            text-transform: uppercase;
        }

        .class-actions {
            display: flex;
            gap: 0.75rem;
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

        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--light);
            border-radius: 50px;
            overflow: hidden;
            margin-bottom: 0.5rem;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--success), #16a34a);
            border-radius: 50px;
            transition: width 0.5s ease;
        }

        .progress-text {
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--success);
            text-align: right;
        }

        /* Empty State */
        .empty-state {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 4rem 2rem;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .empty-icon {
            font-size: 5rem;
            color: var(--gray);
            opacity: 0.3;
            margin-bottom: 1.5rem;
        }

        .empty-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.75rem;
        }

        .empty-text {
            color: var(--gray);
            font-size: 1rem;
            margin-bottom: 1.5rem;
        }

        .empty-action {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            padding: 1rem 2rem;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
        }

        .empty-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(99, 102, 241, 0.4);
        }

        @media (max-width: 768px) {
            .page-header {
                padding: 1.5rem;
            }

            .header-content {
                flex-direction: column;
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .overall-stats {
                grid-template-columns: 1fr;
            }

            .classes-grid {
                grid-template-columns: 1fr;
            }

            .quick-dates {
                flex-direction: column;
            }

            .quick-date-btn {
                width: 100%;
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
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="header-text">
                        <h1>Attendance by Date</h1>
                        <p>View attendance records for any specific date</p>
                    </div>
                </div>
                <a href="index.php" class="back-button">
                    <i class="fas fa-arrow-left"></i>
                    Dashboard
                </a>
            </div>
        </div>

        <!-- Filters Card -->
        <div class="filters-card">
            <div class="filters-header">
                <i class="fas fa-filter"></i>
                <h2>Select Date & Academic Year</h2>
            </div>
            <form method="GET" action="" id="dateFilterForm">
                <div class="filters-grid">
                    <div class="filter-field">
                        <label>
                            <i class="fas fa-calendar-alt"></i>
                            Academic Year
                        </label>
                        <select name="academic_year" class="filter-input" onchange="this.form.submit()">
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
                    <div class="filter-field">
                        <label>
                            <i class="fas fa-calendar-day"></i>
                            Select Date
                        </label>
                        <input type="date" name="date" class="filter-input" 
                               value="<?php echo htmlspecialchars($selected_date); ?>" 
                               max="<?php echo date('Y-m-d'); ?>"
                               onchange="this.form.submit()">
                    </div>
                </div>

                <!-- Quick Date Selection -->
                <div class="quick-dates">
                    <button type="button" class="quick-date-btn" onclick="setDate('<?php echo date('Y-m-d'); ?>')">
                        <i class="fas fa-calendar-day"></i> Today
                    </button>
                    <button type="button" class="quick-date-btn" onclick="setDate('<?php echo date('Y-m-d', strtotime('-1 day')); ?>')">
                        <i class="fas fa-calendar-minus"></i> Yesterday
                    </button>
                    <button type="button" class="quick-date-btn" onclick="setDate('<?php echo date('Y-m-d', strtotime('-7 days')); ?>')">
                        <i class="fas fa-calendar-week"></i> 1 Week Ago
                    </button>
                    <button type="button" class="quick-date-btn" onclick="setDate('<?php echo date('Y-m-d', strtotime('-30 days')); ?>')">
                        <i class="fas fa-calendar"></i> 1 Month Ago
                    </button>
                </div>
            </form>
        </div>

        <?php if ($classes_marked > 0): ?>
            <!-- Overall Statistics -->
            <div class="overall-stats">
                <div class="stat-card classes">
                    <div class="stat-icon">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <div class="stat-value"><?php echo $classes_marked; ?></div>
                    <div class="stat-label">Classes Marked</div>
                </div>
                <div class="stat-card total">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-value"><?php echo $overall_total; ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
                <div class="stat-card present">
                    <div class="stat-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-value"><?php echo $overall_present; ?></div>
                    <div class="stat-label">Present</div>
                    <div class="stat-percentage"><?php echo $overall_percentage; ?>%</div>
                </div>
                <div class="stat-card absent">
                    <div class="stat-icon">
                        <i class="fas fa-user-times"></i>
                    </div>
                    <div class="stat-value"><?php echo $overall_absent; ?></div>
                    <div class="stat-label">Absent</div>
                </div>
            </div>

            <!-- Classes Grid -->
            <div class="classes-grid">
                <?php 
                $date_attendance_result->data_seek(0);
                while($class = $date_attendance_result->fetch_assoc()): 
                    $percentage = $class['total_students'] > 0 ? round(($class['present_count'] / $class['total_students']) * 100, 1) : 0;
                ?>
                    <div class="class-card">
                        <div class="class-header">
                            <div class="class-info">
                                <h3><?php echo htmlspecialchars($class['subject_code']); ?></h3>
                                <div class="class-meta">
                                    <?php echo htmlspecialchars($class['subject_name']); ?><br>
                                    <?php echo htmlspecialchars($class['semester_name']); ?>
                                    <?php if($class['section_name']): ?>
                                        - <?php echo htmlspecialchars($class['section_name']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="attendance-time">
                                <i class="fas fa-clock"></i>
                                <?php echo date('h:i A', strtotime($class['last_marked_time'])); ?>
                            </div>
                        </div>

                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $percentage; ?>%"></div>
                        </div>
                        <div class="progress-text"><?php echo $percentage; ?>% Present</div>

                        <div class="class-stats">
                            <div class="mini-stat present">
                                <div class="mini-stat-value"><?php echo $class['present_count']; ?></div>
                                <div class="mini-stat-label">Present</div>
                            </div>
                            <div class="mini-stat absent">
                                <div class="mini-stat-value"><?php echo $class['absent_count']; ?></div>
                                <div class="mini-stat-label">Absent</div>
                            </div>
                            <?php if($class['late_count'] > 0): ?>
                            <div class="mini-stat late">
                                <div class="mini-stat-value"><?php echo $class['late_count']; ?></div>
                                <div class="mini-stat-label">Late</div>
                            </div>
                            <?php else: ?>
                            <div class="mini-stat">
                                <div class="mini-stat-value"><?php echo $class['total_students']; ?></div>
                                <div class="mini-stat-label">Total</div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="class-actions">
                            <a href="view_class_attendance.php?subject_id=<?php echo $class['subject_id']; ?>&semester_id=<?php echo $class['semester_id']; ?><?php echo $class['section_id'] ? '&section_id=' . $class['section_id'] : ''; ?>&date=<?php echo $selected_date; ?>&academic_year=<?php echo urlencode($selected_academic_year); ?>" class="action-btn view">
                                <i class="fas fa-eye"></i>
                                View Details
                            </a>
                            <a href="mark_attendance.php?subject_id=<?php echo $class['subject_id']; ?>&semester_id=<?php echo $class['semester_id']; ?><?php echo $class['section_id'] ? '&section_id=' . $class['section_id'] : ''; ?>&date=<?php echo $selected_date; ?>&academic_year=<?php echo urlencode($selected_academic_year); ?>" class="action-btn edit">
                                <i class="fas fa-edit"></i>
                                Edit
                            </a>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>

        <?php else: ?>
            <!-- Empty State -->
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <h3 class="empty-title">No Attendance Records Found</h3>
                <p class="empty-text">
                    No attendance has been marked for <strong><?php echo date('F j, Y', strtotime($selected_date)); ?></strong>
                </p>
                <a href="mark_attendance.php?date=<?php echo $selected_date; ?>&academic_year=<?php echo urlencode($selected_academic_year); ?>" class="empty-action">
                    <i class="fas fa-plus-circle"></i>
                    Mark Attendance for This Date
                </a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function setDate(date) {
            const form = document.getElementById('dateFilterForm');
            const dateInput = form.querySelector('input[name="date"]');
            dateInput.value = date;
            form.submit();
        }
    </script>
</body>
</html>
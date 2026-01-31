<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('teacher')) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

// Get teacher details
$sql = "SELECT t.*, d.department_name 
        FROM teachers t 
        JOIN departments d ON t.department_id = d.department_id 
        WHERE t.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();

// Get assigned subjects for filter
$assigned_subjects_query = "SELECT DISTINCT sub.subject_id, sub.subject_name, sub.subject_code, 
                            sem.semester_id, sem.semester_name, 
                            sec.section_id, sec.section_name, st.academic_year
                            FROM subject_teachers st
                            JOIN subjects sub ON st.subject_id = sub.subject_id
                            JOIN semesters sem ON st.semester_id = sem.semester_id
                            LEFT JOIN sections sec ON st.section_id = sec.section_id
                            WHERE st.teacher_id = {$teacher['teacher_id']}
                            ORDER BY sem.semester_number, sub.subject_name";
$assigned_subjects = $conn->query($assigned_subjects_query);

// Get filter parameters
$selected_subject = $_GET['subject_id'] ?? '';
$selected_semester = $_GET['semester_id'] ?? '';
$selected_section = $_GET['section_id'] ?? '';

// Build query to get students
$students_data = [];
if ($selected_subject && $selected_semester) {
    $section_condition = $selected_section ? "AND ss.section_id = $selected_section" : "";
    
    $students_query = "SELECT DISTINCT s.student_id, s.admission_number, s.full_name, 
                      s.date_of_birth, d.department_name,
                      sem.semester_name, sec.section_name,
                      u.email, u.phone
                      FROM students s
                      JOIN users u ON s.user_id = u.user_id
                      JOIN departments d ON s.department_id = d.department_id
                      JOIN student_semesters ss ON s.student_id = ss.student_id
                      JOIN semesters sem ON ss.semester_id = sem.semester_id
                      LEFT JOIN sections sec ON ss.section_id = sec.section_id
                      WHERE ss.semester_id = $selected_semester 
                      AND ss.is_active = 1
                      $section_condition
                      ORDER BY s.admission_number";
    
    $students_result = $conn->query($students_query);
    
    // Get attendance data for each student
    while ($student = $students_result->fetch_assoc()) {
        $student_id = $student['student_id'];
        
        // Get attendance summary
        $att_query = "SELECT 
                     COUNT(*) as total_classes,
                     SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
                     SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                     SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count,
                     ROUND((SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as attendance_percentage
                     FROM attendance
                     WHERE student_id = $student_id 
                     AND subject_id = $selected_subject
                     AND semester_id = $selected_semester";
        
        $att_result = $conn->query($att_query);
        $attendance = $att_result->fetch_assoc();
        
        $student['attendance_data'] = $attendance;
        $students_data[] = $student;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Students - College ERP</title>
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
        }

        .dashboard {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: var(--dark);
            color: var(--white);
            padding: 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 30px 25px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
        }

        .teacher-info {
            text-align: center;
        }

        .teacher-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0 auto 12px;
            border: 3px solid rgba(255,255,255,0.3);
        }

        .teacher-name {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .teacher-designation {
            font-size: 0.85rem;
            opacity: 0.9;
        }

        .sidebar-menu {
            padding: 25px 0;
        }

        .menu-item {
            padding: 15px 25px;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            display: flex;
            align-items: center;
            transition: all 0.3s;
            cursor: pointer;
            border-left: 3px solid transparent;
        }

        .menu-item:hover, .menu-item.active {
            background: rgba(139, 92, 246, 0.1);
            color: var(--white);
            border-left-color: var(--primary);
        }

        .menu-item i {
            margin-right: 15px;
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
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

        /* Filter Section */
        .filter-section {
            background: var(--white);
            padding: 30px;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .filter-header {
            display: flex;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--light-gray);
        }

        .filter-header i {
            font-size: 1.5rem;
            color: var(--primary);
            margin-right: 15px;
        }

        .filter-header h3 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.4rem;
            font-weight: 700;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--gray);
            font-size: 0.9rem;
        }

        .form-group select {
            padding: 12px 16px;
            border: 2px solid #e4e4e7;
            border-radius: 12px;
            font-family: 'DM Sans', sans-serif;
            font-size: 1rem;
            transition: all 0.3s;
            background: var(--white);
        }

        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        .filter-btn {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            border: none;
            padding: 12px 32px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-family: 'DM Sans', sans-serif;
            font-size: 1rem;
        }

        .filter-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(139, 92, 246, 0.4);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            padding: 25px;
            border-radius: 20px;
            color: var(--white);
            box-shadow: 0 4px 20px rgba(139, 92, 246, 0.3);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -30%;
            width: 200px;
            height: 200px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 5px;
            position: relative;
            z-index: 1;
        }

        .stat-label {
            font-size: 0.95rem;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        /* Table Card */
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

        .table-container {
            overflow-x: auto;
        }

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
            white-space: nowrap;
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

        .badge.primary { 
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.2), rgba(236, 72, 153, 0.2)); 
            color: var(--primary); 
        }
        .badge.success { background: rgba(16, 185, 129, 0.15); color: var(--success); }
        .badge.warning { background: rgba(245, 158, 11, 0.15); color: var(--warning); }
        .badge.danger { background: rgba(239, 68, 68, 0.15); color: var(--danger); }

        .progress-bar {
            width: 100px;
            height: 8px;
            background: #e4e4e7;
            border-radius: 10px;
            overflow: hidden;
            display: inline-block;
        }

        .progress-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.3s;
        }

        .progress-fill.high { background: linear-gradient(90deg, var(--success), #059669); }
        .progress-fill.medium { background: linear-gradient(90deg, var(--warning), #d97706); }
        .progress-fill.low { background: linear-gradient(90deg, var(--danger), #dc2626); }

        .action-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
            font-size: 0.85rem;
            text-decoration: none;
            display: inline-block;
        }

        .action-btn.primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.4);
        }

        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray);
        }

        .no-data i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .no-data h3 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.5rem;
            margin-bottom: 10px;
        }

        .export-btn {
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

        .export-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="teacher-info">
                    <div class="teacher-avatar"><?php echo strtoupper(substr($teacher['full_name'], 0, 1)); ?></div>
                    <div class="teacher-name"><?php echo $teacher['full_name']; ?></div>
                    <div class="teacher-designation"><?php echo $teacher['designation'] ?? 'Faculty Member'; ?></div>
                </div>
            </div>
            <nav class="sidebar-menu">
                <a href="index.php" class="menu-item">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="mark_attendance.php" class="menu-item">
                    <i class="fas fa-calendar-check"></i> Mark Attendance
                </a>
                <a href="view_student_attendance.php" class="menu-item">
                    <i class="fas fa-chart-line"></i> Attendance Records
                </a>
                <a href="my_classes.php" class="menu-item">
                    <i class="fas fa-chalkboard"></i> My Classes
                </a>
                <a href="view_students.php" class="menu-item active">
                    <i class="fas fa-users"></i> Students
                </a>
                <a href="teacher_profile.php" class="menu-item">
                    <i class="fas fa-user"></i> Profile
                </a>
                <a href="settings.php" class="menu-item">
                    <i class="fas fa-cog"></i> Settings
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-bar">
                <h1>View Students</h1>
                <a href="index.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <div class="filter-header">
                    <i class="fas fa-filter"></i>
                    <h3>Filter Students</h3>
                </div>
                <form method="GET" action="">
                    <div class="filter-grid">
                        <div class="form-group">
                            <label for="subject_id">Select Subject *</label>
                            <select name="subject_id" id="subject_id" required onchange="updateSemesterSection(this.value)">
                                <option value="">-- Choose Subject --</option>
                                <?php 
                                $assigned_subjects->data_seek(0);
                                while($subject = $assigned_subjects->fetch_assoc()): 
                                ?>
                                <option value="<?php echo $subject['subject_id']; ?>" 
                                        data-semester="<?php echo $subject['semester_id']; ?>"
                                        data-section="<?php echo $subject['section_id'] ?? ''; ?>"
                                        <?php echo ($selected_subject == $subject['subject_id']) ? 'selected' : ''; ?>>
                                    <?php echo $subject['subject_code']; ?> - <?php echo $subject['subject_name']; ?> 
                                    (<?php echo $subject['semester_name']; ?> - <?php echo $subject['section_name'] ?? 'All'; ?>)
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <input type="hidden" name="semester_id" id="semester_id" value="<?php echo $selected_semester; ?>">
                        <input type="hidden" name="section_id" id="section_id" value="<?php echo $selected_section; ?>">
                    </div>
                    <button type="submit" class="filter-btn">
                        <i class="fas fa-search"></i> View Students
                    </button>
                </form>
            </div>

            <?php if (!empty($students_data)): ?>
                <!-- Stats -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo count($students_data); ?></div>
                        <div class="stat-label">Total Students</div>
                    </div>
                    <div class="stat-card" style="background: linear-gradient(135deg, #10b981, #059669);">
                        <div class="stat-number">
                            <?php 
                            $avg_attendance = 0;
                            foreach($students_data as $s) {
                                $avg_attendance += $s['attendance_data']['attendance_percentage'] ?? 0;
                            }
                            $avg_attendance = count($students_data) > 0 ? round($avg_attendance / count($students_data), 2) : 0;
                            echo $avg_attendance . '%';
                            ?>
                        </div>
                        <div class="stat-label">Average Attendance</div>
                    </div>
                </div>

                <!-- Students Table -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="fas fa-graduation-cap"></i>
                            <h3>Class Students</h3>
                        </div>
                        <button class="export-btn" onclick="exportToCSV()">
                            <i class="fas fa-download"></i> Export to CSV
                        </button>
                    </div>
                    <div class="table-container">
                        <table class="table" id="studentsTable">
                            <thead>
                                <tr>
                                    <th>Roll No.</th>
                                    <th>Admission No.</th>
                                    <th>Student Name</th>
                                    <th>Department</th>
                                    <th>Semester</th>
                                    <th>Section</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Total Classes</th>
                                    <th>Present</th>
                                    <th>Absent</th>
                                    <th>Late</th>
                                    <th>Attendance %</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $roll_no = 1;
                                foreach($students_data as $student): 
                                    $att = $student['attendance_data'];
                                    $percentage = $att['attendance_percentage'] ?? 0;
                                    $progress_class = $percentage >= 75 ? 'high' : ($percentage >= 60 ? 'medium' : 'low');
                                    $status_class = $percentage >= 75 ? 'success' : ($percentage >= 60 ? 'warning' : 'danger');
                                ?>
                                <tr>
                                    <td><strong><?php echo $roll_no++; ?></strong></td>
                                    <td><span class="badge primary"><?php echo $student['admission_number']; ?></span></td>
                                    <td><strong><?php echo $student['full_name']; ?></strong></td>
                                    <td><?php echo $student['department_name']; ?></td>
                                    <td><?php echo $student['semester_name']; ?></td>
                                    <td><?php echo $student['section_name'] ?? 'N/A'; ?></td>
                                    <td><?php echo $student['email']; ?></td>
                                    <td><?php echo $student['phone'] ?? 'N/A'; ?></td>
                                    <td><?php echo $att['total_classes'] ?? 0; ?></td>
                                    <td><span class="badge success"><?php echo $att['present_count'] ?? 0; ?></span></td>
                                    <td><span class="badge danger"><?php echo $att['absent_count'] ?? 0; ?></span></td>
                                    <td><span class="badge warning"><?php echo $att['late_count'] ?? 0; ?></span></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <strong><?php echo $percentage; ?>%</strong>
                                            <div class="progress-bar">
                                                <div class="progress-fill <?php echo $progress_class; ?>" 
                                                     style="width: <?php echo $percentage; ?>%"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($percentage >= 75): ?>
                                            <span class="badge success">Good</span>
                                        <?php elseif ($percentage >= 60): ?>
                                            <span class="badge warning">Average</span>
                                        <?php else: ?>
                                            <span class="badge danger">Low</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="student_detail.php?student_id=<?php echo $student['student_id']; ?>&subject_id=<?php echo $selected_subject; ?>" 
                                           class="action-btn primary">
                                            <i class="fas fa-eye"></i> View Details
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php elseif (isset($_GET['subject_id'])): ?>
                <div class="card">
                    <div class="no-data">
                        <i class="fas fa-user-slash"></i>
                        <h3>No Students Found</h3>
                        <p>No students are enrolled in this subject/section.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="no-data">
                        <i class="fas fa-search"></i>
                        <h3>Select a Subject</h3>
                        <p>Please select a subject from the filter above to view students.</p>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        function updateSemesterSection(subjectId) {
            const select = document.getElementById('subject_id');
            const selectedOption = select.options[select.selectedIndex];
            
            document.getElementById('semester_id').value = selectedOption.getAttribute('data-semester');
            document.getElementById('section_id').value = selectedOption.getAttribute('data-section');
        }

        function exportToCSV() {
            const table = document.getElementById('studentsTable');
            let csv = [];
            
            // Get headers
            const headers = [];
            table.querySelectorAll('thead th').forEach(th => {
                headers.push(th.textContent.trim());
            });
            csv.push(headers.join(','));
            
            // Get data rows
            table.querySelectorAll('tbody tr').forEach(tr => {
                const row = [];
                tr.querySelectorAll('td').forEach((td, index) => {
                    // Skip the last column (Action)
                    if (index < tr.querySelectorAll('td').length - 1) {
                        let text = td.textContent.trim();
                        // Remove extra whitespace
                        text = text.replace(/\s+/g, ' ');
                        // Escape commas and quotes
                        text = text.replace(/"/g, '""');
                        row.push('"' + text + '"');
                    }
                });
                csv.push(row.join(','));
            });
            
            // Create download
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            
            link.setAttribute('href', url);
            link.setAttribute('download', 'students_attendance_' + new Date().getTime() + '.csv');
            link.style.visibility = 'hidden';
            
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    </script>
</body>
</html>
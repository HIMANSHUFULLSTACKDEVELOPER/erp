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

// Get parameters
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;
$semester_id = isset($_GET['semester_id']) ? intval($_GET['semester_id']) : 0;
$section_id = isset($_GET['section_id']) && $_GET['section_id'] !== '' ? intval($_GET['section_id']) : null;
$attendance_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$academic_year = isset($_GET['academic_year']) ? $_GET['academic_year'] : '';

// Handle update submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_attendance'])) {
    $attendance_data = $_POST['attendance'] ?? [];
    
    $conn->begin_transaction();
    
    try {
        foreach ($attendance_data as $student_id => $status) {
            $student_id = intval($student_id);
            
            // Check if attendance exists
            $check_sql = "SELECT attendance_id FROM attendance 
                          WHERE student_id = ? AND subject_id = ? AND attendance_date = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("iis", $student_id, $subject_id, $attendance_date);
            $check_stmt->execute();
            $exists = $check_stmt->get_result()->fetch_assoc();

            if ($exists) {
                // Update existing
                $update_sql = "UPDATE attendance SET status = ?, marked_by = ? 
                              WHERE attendance_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("sii", $status, $teacher['teacher_id'], $exists['attendance_id']);
                $update_stmt->execute();
            }
        }
        
        $conn->commit();
        
        // Redirect to success page
        $redirect_url = "attendance_success.php?subject_id=" . $subject_id . 
                       "&semester_id=" . $semester_id . 
                       "&date=" . $attendance_date . 
                       "&academic_year=" . urlencode($academic_year) . 
                       "&updated=1";
        
        if ($section_id) {
            $redirect_url .= "&section_id=" . $section_id;
        }
        
        header("Location: " . $redirect_url);
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Error updating attendance: " . $e->getMessage();
    }
}

// Get subject info
$subject_info_sql = "SELECT sub.subject_name, sub.subject_code, sem.semester_name, sec.section_name
                     FROM subjects sub
                     JOIN semesters sem ON sub.semester_id = sem.semester_id
                     LEFT JOIN sections sec ON sec.section_id = ?
                     WHERE sub.subject_id = ?";
$subject_info_stmt = $conn->prepare($subject_info_sql);
$subject_info_stmt->bind_param("ii", $section_id, $subject_id);
$subject_info_stmt->execute();
$subject_info = $subject_info_stmt->get_result()->fetch_assoc();

// Get attendance records
$attendance_sql = "SELECT s.student_id, s.full_name, s.admission_number,
                   srn.roll_number_display, a.status
                   FROM students s
                   JOIN student_semesters ss ON s.student_id = ss.student_id
                   LEFT JOIN student_roll_numbers srn ON s.student_id = srn.student_id 
                       AND srn.semester_id = ss.semester_id 
                       AND srn.section_id = ss.section_id
                       AND srn.academic_year = ss.academic_year
                   LEFT JOIN attendance a ON s.student_id = a.student_id 
                       AND a.subject_id = ? 
                       AND a.attendance_date = ?
                   WHERE ss.semester_id = ?
                   AND ss.is_active = 1";

if ($section_id) {
    $attendance_sql .= " AND ss.section_id = ?";
}

$attendance_sql .= " ORDER BY srn.roll_number, s.full_name";

$attendance_stmt = $conn->prepare($attendance_sql);
if ($section_id) {
    $attendance_stmt->bind_param("isii", $subject_id, $attendance_date, $semester_id, $section_id);
} else {
    $attendance_stmt->bind_param("isi", $subject_id, $attendance_date, $semester_id);
}
$attendance_stmt->execute();
$students = $attendance_stmt->get_result();

// Calculate statistics
$total_students = 0;
$present_count = 0;
$absent_count = 0;

$students->data_seek(0);
while($stat = $students->fetch_assoc()) {
    $total_students++;
    if ($stat['status'] == 'present') {
        $present_count++;
    } elseif ($stat['status'] == 'absent') {
        $absent_count++;
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
    <title>Update Attendance</title>
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
            --secondary: #8b5cf6;
            --success: #22c55e;
            --danger: #ef4444;
            --warning: #f59e0b;
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
            padding: 1.5rem;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Header */
        .page-header {
            background: rgba(255, 255, 255, 0.98);
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
            gap: 1.5rem;
            flex-wrap: wrap;
        }

        .header-left {
            flex: 1;
        }

        .header-title {
            font-size: 2rem;
            font-weight: 900;
            background: linear-gradient(135deg, var(--warning), #f97316);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .header-subtitle {
            color: var(--gray);
            font-size: 1rem;
            font-weight: 500;
        }

        .back-btn {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            padding: 0.875rem 1.75rem;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.625rem;
            transition: all 0.3s ease;
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.35);
        }

        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(99, 102, 241, 0.45);
        }

        /* Alert */
        .alert {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            border-radius: 16px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-left: 4px solid var(--warning);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }

        .alert i {
            font-size: 1.5rem;
            color: var(--warning);
        }

        .alert-text {
            font-weight: 600;
            color: var(--dark);
        }

        /* Stats */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 1.75rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
        }

        .stat-card.total::before {
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }

        .stat-card.present::before {
            background: var(--success);
        }

        .stat-card.absent::before {
            background: var(--danger);
        }

        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }

        .stat-card.total .stat-icon { color: var(--primary); }
        .stat-card.present .stat-icon { color: var(--success); }
        .stat-card.absent .stat-icon { color: var(--danger); }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 900;
            margin-bottom: 0.5rem;
        }

        .stat-card.total .stat-number {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .stat-card.present .stat-number { color: var(--success); }
        .stat-card.absent .stat-number { color: var(--danger); }

        .stat-label {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Attendance Card */
        .attendance-card {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15);
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, var(--warning), #f97316);
            padding: 1.75rem 2rem;
            color: var(--white);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .card-header h2 {
            font-size: 1.5rem;
            font-weight: 800;
        }

        .bulk-actions {
            display: flex;
            gap: 0.75rem;
        }

        .bulk-btn {
            padding: 0.625rem 1.25rem;
            border-radius: 10px;
            border: none;
            font-weight: 700;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-family: 'Poppins', sans-serif;
        }

        .btn-all-present {
            background: var(--success);
            color: var(--white);
        }

        .btn-all-absent {
            background: var(--danger);
            color: var(--white);
        }

        .bulk-btn:hover {
            transform: translateY(-2px);
            opacity: 0.9;
        }

        .students-list {
            padding: 1.5rem;
            max-height: 55vh;
            overflow-y: auto;
        }

        .student-item {
            background: var(--white);
            border: 2px solid var(--border);
            border-radius: 16px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1.25rem;
            transition: all 0.3s ease;
        }

        .student-item:hover {
            transform: translateX(4px);
            border-color: var(--warning);
            box-shadow: 0 8px 24px rgba(245, 158, 11, 0.15);
        }

        .student-info {
            display: flex;
            align-items: center;
            gap: 1.25rem;
            flex: 1;
        }

        .roll-badge {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--warning), #f97316);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 1.125rem;
            color: var(--white);
            box-shadow: 0 6px 18px rgba(245, 158, 11, 0.35);
            flex-shrink: 0;
        }

        .student-details {
            flex: 1;
        }

        .student-name {
            font-weight: 700;
            font-size: 1.05rem;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .student-id {
            font-size: 0.85rem;
            color: var(--gray);
            font-weight: 500;
        }

        .attendance-toggle {
            display: flex;
            gap: 0.625rem;
            flex-shrink: 0;
        }

        .toggle-btn {
            width: 70px;
            height: 70px;
            border-radius: 14px;
            border: 3px solid var(--border);
            background: var(--white);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .toggle-btn input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .toggle-btn i {
            font-size: 1.75rem;
            margin-bottom: 0.25rem;
            transition: all 0.3s ease;
        }

        .toggle-btn span {
            font-size: 0.75rem;
            font-weight: 800;
            letter-spacing: 0.5px;
        }

        .toggle-present {
            color: var(--gray);
        }

        .toggle-present:hover {
            border-color: var(--success);
            background: rgba(34, 197, 94, 0.05);
        }

        .toggle-present.active {
            background: var(--success);
            border-color: var(--success);
            color: var(--white);
            box-shadow: 0 6px 20px rgba(34, 197, 94, 0.4);
            transform: scale(1.05);
        }

        .toggle-absent {
            color: var(--gray);
        }

        .toggle-absent:hover {
            border-color: var(--danger);
            background: rgba(239, 68, 68, 0.05);
        }

        .toggle-absent.active {
            background: var(--danger);
            border-color: var(--danger);
            color: var(--white);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
            transform: scale(1.05);
        }

        .submit-section {
            padding: 1.5rem 2rem;
            background: var(--light);
            border-top: 2px solid var(--border);
        }

        .submit-btn {
            width: 100%;
            padding: 1.25rem;
            background: linear-gradient(135deg, var(--warning), #f97316);
            color: var(--white);
            border: none;
            border-radius: 14px;
            font-weight: 900;
            font-size: 1.125rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            font-family: 'Poppins', sans-serif;
            box-shadow: 0 8px 24px rgba(245, 158, 11, 0.35);
        }

        .submit-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 32px rgba(245, 158, 11, 0.45);
        }

        /* Mobile */
        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }

            .page-header {
                padding: 1.5rem;
            }

            .header-content {
                flex-direction: column;
                align-items: flex-start;
            }

            .back-btn {
                width: 100%;
                justify-content: center;
            }

            .header-title {
                font-size: 1.5rem;
            }

            .stats-container {
                grid-template-columns: 1fr;
            }

            .card-header {
                padding: 1.5rem;
                flex-direction: column;
                align-items: stretch;
            }

            .bulk-actions {
                width: 100%;
                flex-direction: column;
            }

            .bulk-btn {
                width: 100%;
                justify-content: center;
            }

            .student-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .student-info {
                width: 100%;
            }

            .attendance-toggle {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="page-header">
            <div class="header-content">
                <div class="header-left">
                    <h1 class="header-title">
                        <i class="fas fa-edit"></i>
                        Update Attendance
                    </h1>
                    <p class="header-subtitle"><?php echo htmlspecialchars($subject_info['subject_code'] . ' - ' . $subject_info['subject_name']); ?></p>
                </div>
                <a href="attendance_success.php?subject_id=<?php echo $subject_id; ?>&semester_id=<?php echo $semester_id; ?>&section_id=<?php echo $section_id ?? ''; ?>&date=<?php echo $attendance_date; ?>&academic_year=<?php echo urlencode($academic_year); ?>" class="back-btn">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back</span>
                </a>
            </div>
        </div>

        <!-- Alert -->
        <div class="alert">
            <i class="fas fa-info-circle"></i>
            <span class="alert-text">You are updating attendance for <strong><?php echo date('F j, Y', strtotime($attendance_date)); ?></strong></span>
        </div>

        <!-- Statistics -->
        <div class="stats-container">
            <div class="stat-card total">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-number" id="totalCount"><?php echo $total_students; ?></div>
                <div class="stat-label">Total</div>
            </div>
            <div class="stat-card present">
                <div class="stat-icon"><i class="fas fa-user-check"></i></div>
                <div class="stat-number" id="presentCount"><?php echo $present_count; ?></div>
                <div class="stat-label">Present</div>
            </div>
            <div class="stat-card absent">
                <div class="stat-icon"><i class="fas fa-user-times"></i></div>
                <div class="stat-number" id="absentCount"><?php echo $absent_count; ?></div>
                <div class="stat-label">Absent</div>
            </div>
        </div>

        <!-- Attendance Form -->
        <form method="POST" action="" id="updateForm">
            <div class="attendance-card">
                <div class="card-header">
                    <h2>✏️ Update Student Records</h2>
                    <div class="bulk-actions">
                        <button type="button" class="bulk-btn btn-all-present" onclick="markAllPresent()">
                            <i class="fas fa-check-double"></i>
                            All Present
                        </button>
                        <button type="button" class="bulk-btn btn-all-absent" onclick="markAllAbsent()">
                            <i class="fas fa-times"></i>
                            All Absent
                        </button>
                    </div>
                </div>

                <div class="students-list">
                    <?php 
                    $students->data_seek(0);
                    while($student = $students->fetch_assoc()): 
                    ?>
                        <div class="student-item">
                            <div class="student-info">
                                <div class="roll-badge">
                                    <?php echo $student['roll_number_display'] ? htmlspecialchars($student['roll_number_display']) : '-'; ?>
                                </div>
                                <div class="student-details">
                                    <div class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></div>
                                    <div class="student-id">ID: <?php echo htmlspecialchars($student['admission_number']); ?></div>
                                </div>
                            </div>
                            <div class="attendance-toggle">
                                <label class="toggle-btn toggle-present <?php echo ($student['status'] == 'present') ? 'active' : ''; ?>">
                                    <input type="radio" 
                                           name="attendance[<?php echo $student['student_id']; ?>]" 
                                           value="present"
                                           <?php echo ($student['status'] == 'present') ? 'checked' : ''; ?>
                                           onchange="updateButton(this)">
                                    <i class="fas fa-check"></i>
                                    <span>Present</span>
                                </label>
                                <label class="toggle-btn toggle-absent <?php echo ($student['status'] == 'absent') ? 'active' : ''; ?>">
                                    <input type="radio" 
                                           name="attendance[<?php echo $student['student_id']; ?>]" 
                                           value="absent"
                                           <?php echo ($student['status'] == 'absent') ? 'checked' : ''; ?>
                                           onchange="updateButton(this)">
                                    <i class="fas fa-times"></i>
                                    <span>Absent</span>
                                </label>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>

                <div class="submit-section">
                    <button type="submit" name="update_attendance" class="submit-btn">
                        <i class="fas fa-check-circle"></i>
                        <span>Save Changes</span>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>
        function updateButton(radio) {
            const item = radio.closest('.student-item');
            const presentBtn = item.querySelector('.toggle-present');
            const absentBtn = item.querySelector('.toggle-absent');
            
            presentBtn.classList.remove('active');
            absentBtn.classList.remove('active');
            
            if (radio.value === 'present') {
                presentBtn.classList.add('active');
            } else {
                absentBtn.classList.add('active');
            }
            
            updateStats();
        }

        function updateStats() {
            const total = document.querySelectorAll('input[value="present"]').length;
            const present = document.querySelectorAll('input[value="present"]:checked').length;
            const absent = document.querySelectorAll('input[value="absent"]:checked').length;
            
            document.getElementById('totalCount').textContent = total;
            document.getElementById('presentCount').textContent = present;
            document.getElementById('absentCount').textContent = absent;
        }

        function markAllPresent() {
            document.querySelectorAll('input[value="present"]').forEach(radio => {
                radio.checked = true;
                updateButton(radio);
            });
        }

        function markAllAbsent() {
            document.querySelectorAll('input[value="absent"]').forEach(radio => {
                radio.checked = true;
                updateButton(radio);
            });
        }
    </script>
</body>
</html>
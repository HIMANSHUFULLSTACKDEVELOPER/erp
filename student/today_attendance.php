<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('student')) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

// Get student details
$sql = "SELECT s.*, d.department_name, c.course_name 
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

// Get selected date (default to today)
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$selected_date_display = date('l, j F Y', strtotime($selected_date));
$is_today = ($selected_date === date('Y-m-d'));

// Check if attendance is marked for selected date
$attendance_query = "SELECT a.*, sub.subject_name, sub.subject_code, t.full_name as teacher_name,
                     TIME_FORMAT(a.created_at, '%h:%i %p') as marked_time
                     FROM attendance a
                     JOIN subjects sub ON a.subject_id = sub.subject_id
                     JOIN teachers t ON a.marked_by = t.teacher_id
                     WHERE a.student_id = ? AND a.attendance_date = ?
                     ORDER BY a.created_at DESC";
$stmt = $conn->prepare($attendance_query);
$stmt->bind_param("is", $student['student_id'], $selected_date);
$stmt->execute();
$attendance_records = $stmt->get_result();

$is_marked = $attendance_records->num_rows > 0;

// Count attendance by status
$present_count = 0;
$absent_count = 0;
$late_count = 0;

$records_array = [];
while($record = $attendance_records->fetch_assoc()) {
    $records_array[] = $record;
    switch($record['status']) {
        case 'present':
            $present_count++;
            break;
        case 'absent':
            $absent_count++;
            break;
        case 'late':
            $late_count++;
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_today ? "Today's" : "Daily"; ?> Attendance - College ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0ea5e9;
            --secondary: #06b6d4;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #0f172a;
            --gray: #64748b;
            --light-gray: #f1f5f9;
            --white: #ffffff;
            --purple: #8b5cf6;
            --purple-light: #a78bfa;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Manrope', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 30px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .back-btn {
            background: var(--white);
            color: var(--purple);
            border: 2px solid var(--purple);
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .back-btn:hover {
            background: var(--purple);
            color: var(--white);
            transform: translateY(-2px);
        }

        .today-card {
            background: var(--white);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .header-left i {
            color: var(--purple);
            font-size: 2rem;
        }

        .page-title {
            font-size: 2rem;
            font-weight: 800;
            color: var(--dark);
        }

        .date-picker-section {
            display: flex;
            align-items: center;
            gap: 15px;
            background: linear-gradient(135deg, #f8f9ff 0%, #f0f0ff 100%);
            padding: 15px 20px;
            border-radius: 12px;
        }

        .date-picker-section label {
            font-weight: 600;
            color: var(--purple);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .date-picker-section input[type="date"] {
            padding: 10px 15px;
            border: 2px solid var(--purple);
            border-radius: 8px;
            font-family: 'Manrope', sans-serif;
            font-weight: 600;
            color: var(--dark);
            cursor: pointer;
            transition: all 0.3s;
        }

        .date-picker-section input[type="date"]:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.2);
        }

        .quick-date-btns {
            display: flex;
            gap: 10px;
        }

        .quick-btn {
            padding: 8px 16px;
            background: var(--white);
            border: 2px solid var(--purple);
            color: var(--purple);
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 0.85rem;
        }

        .quick-btn:hover, .quick-btn.active {
            background: var(--purple);
            color: var(--white);
        }

        .student-info-banner {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: var(--white);
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .student-details {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .detail-label {
            font-size: 0.75rem;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .detail-value {
            font-size: 1rem;
            font-weight: 700;
        }

        .date-badge {
            background: rgba(255, 255, 255, 0.2);
            padding: 10px 20px;
            border-radius: 25px;
            font-weight: 700;
            backdrop-filter: blur(10px);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            padding: 25px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            gap: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .stat-card.total {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: var(--white);
        }

        .stat-card.present {
            background: linear-gradient(135deg, #10b981, #34d399);
            color: var(--white);
        }

        .stat-card.absent {
            background: linear-gradient(135deg, #ef4444, #f87171);
            color: var(--white);
        }

        .stat-card.late {
            background: linear-gradient(135deg, #f59e0b, #fb923c);
            color: var(--white);
        }

        .stat-icon {
            font-size: 2.5rem;
            opacity: 0.9;
        }

        .stat-info {
            flex: 1;
        }

        .stat-label {
            font-size: 0.85rem;
            opacity: 0.9;
            margin-bottom: 5px;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 800;
        }

        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 5rem;
            margin-bottom: 20px;
            opacity: 0.3;
            animation: float 3s ease-in-out infinite;
        }

        .empty-state h2 {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--warning);
            margin-bottom: 10px;
        }

        .empty-state p {
            font-size: 1.1rem;
        }

        .attendance-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }

        .attendance-card {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 25px;
            border-radius: 15px;
            border-left: 5px solid var(--purple);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .attendance-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }

        .attendance-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: rgba(139, 92, 246, 0.05);
            border-radius: 0 0 0 100%;
        }

        .subject-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }

        .subject-info {
            flex: 1;
        }

        .subject-name {
            font-weight: 700;
            color: var(--dark);
            font-size: 1.2rem;
            margin-bottom: 5px;
        }

        .subject-code {
            font-size: 0.8rem;
            color: var(--gray);
            background: var(--white);
            padding: 4px 12px;
            border-radius: 8px;
            display: inline-block;
        }

        .time-badge {
            background: var(--purple);
            color: var(--white);
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .teacher-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 15px 0;
            padding: 12px;
            background: var(--white);
            border-radius: 10px;
        }

        .teacher-info i {
            color: var(--purple);
        }

        .teacher-name {
            font-size: 0.9rem;
            color: var(--gray);
            font-weight: 600;
        }

        .status-section {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 2px dashed #dee2e6;
        }

        .status-badge {
            padding: 10px 18px;
            border-radius: 25px;
            font-weight: 700;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
        }

        .status-badge i {
            font-size: 1rem;
        }

        .status-badge.present {
            background: linear-gradient(135deg, #10b981, #34d399);
            color: var(--white);
        }

        .status-badge.absent {
            background: linear-gradient(135deg, #ef4444, #f87171);
            color: var(--white);
        }

        .status-badge.late {
            background: linear-gradient(135deg, #f59e0b, #fb923c);
            color: var(--white);
        }

        .remarks-box {
            margin-top: 12px;
            padding: 12px;
            background: var(--white);
            border-left: 3px solid var(--purple);
            border-radius: 8px;
        }

        .remarks-label {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--purple);
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .remarks-text {
            font-size: 0.9rem;
            color: var(--dark);
            line-height: 1.5;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }

        @media (max-width: 768px) {
            body {
                padding: 15px;
            }

            .today-card {
                padding: 20px;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .card-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .date-picker-section {
                flex-direction: column;
                align-items: flex-start;
                width: 100%;
            }

            .quick-date-btns {
                width: 100%;
                overflow-x: auto;
            }

            .student-info-banner {
                padding: 20px;
            }

            .student-details {
                gap: 15px;
            }

            .attendance-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>

        <div class="today-card">
            <div class="card-header">
                <div class="header-left">
                    <i class="fas fa-calendar-check"></i>
                    <h1 class="page-title">Daily Attendance</h1>
                </div>

                <div class="date-picker-section">
                    <label for="attendance-date">
                        <i class="fas fa-calendar-alt"></i>
                        Select Date:
                    </label>
                    <input type="date" 
                           id="attendance-date" 
                           value="<?php echo $selected_date; ?>" 
                           max="<?php echo date('Y-m-d'); ?>"
                           onchange="changeDate(this.value)">
                    <div class="quick-date-btns">
                        <button class="quick-btn <?php echo $is_today ? 'active' : ''; ?>" 
                                onclick="changeDate('<?php echo date('Y-m-d'); ?>')">
                            Today
                        </button>
                        <button class="quick-btn" 
                                onclick="changeDate('<?php echo date('Y-m-d', strtotime('-1 day')); ?>')">
                            Yesterday
                        </button>
                    </div>
                </div>
            </div>

            <div class="student-info-banner">
                <div class="student-details">
                    <div class="detail-item">
                        <div class="detail-label">Student Name</div>
                        <div class="detail-value"><?php echo htmlspecialchars($student['full_name']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Roll Number</div>
                        <div class="detail-value"><?php echo htmlspecialchars($student['roll_number']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Department</div>
                        <div class="detail-value"><?php echo htmlspecialchars($student['department_name']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Course</div>
                        <div class="detail-value"><?php echo htmlspecialchars($student['course_name']); ?></div>
                    </div>
                </div>
                <div class="date-badge">
                    <i class="fas fa-calendar-day"></i> <?php echo $selected_date_display; ?>
                </div>
            </div>

            <?php if ($is_marked): ?>
                <div class="stats-grid">
                    <div class="stat-card total">
                        <div class="stat-icon">
                            <i class="fas fa-list-check"></i>
                        </div>
                        <div class="stat-info">
                            <div class="stat-label">Total Classes</div>
                            <div class="stat-value"><?php echo count($records_array); ?></div>
                        </div>
                    </div>

                    <div class="stat-card present">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-info">
                            <div class="stat-label">Present</div>
                            <div class="stat-value"><?php echo $present_count; ?></div>
                        </div>
                    </div>

                    <div class="stat-card absent">
                        <div class="stat-icon">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div class="stat-info">
                            <div class="stat-label">Absent</div>
                            <div class="stat-value"><?php echo $absent_count; ?></div>
                        </div>
                    </div>

                    <div class="stat-card late">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-info">
                            <div class="stat-label">Late</div>
                            <div class="stat-value"><?php echo $late_count; ?></div>
                        </div>
                    </div>
                </div>

                <div class="attendance-grid">
                    <?php foreach($records_array as $record): ?>
                    <div class="attendance-card">
                        <div class="subject-header">
                            <div class="subject-info">
                                <div class="subject-name"><?php echo htmlspecialchars($record['subject_name']); ?></div>
                                <span class="subject-code"><?php echo htmlspecialchars($record['subject_code']); ?></span>
                            </div>
                            <div class="time-badge">
                                <i class="fas fa-clock"></i>
                                <?php echo $record['marked_time']; ?>
                            </div>
                        </div>

                        <div class="teacher-info">
                            <i class="fas fa-user-tie"></i>
                            <span class="teacher-name"><?php echo htmlspecialchars($record['teacher_name']); ?></span>
                        </div>

                        <div class="status-section">
                            <?php
                            $status_icon = '';
                            $status_class = strtolower($record['status']);
                            
                            switch($record['status']) {
                                case 'present':
                                    $status_icon = 'fa-check-circle';
                                    break;
                                case 'absent':
                                    $status_icon = 'fa-times-circle';
                                    break;
                                case 'late':
                                    $status_icon = 'fa-clock';
                                    break;
                            }
                            ?>
                            <span class="status-badge <?php echo $status_class; ?>">
                                <i class="fas <?php echo $status_icon; ?>"></i>
                                <?php echo strtoupper($record['status']); ?>
                            </span>

                            <?php if ($record['remarks']): ?>
                                <div class="remarks-box">
                                    <div class="remarks-label">
                                        <i class="fas fa-comment-dots"></i> Remarks
                                    </div>
                                    <div class="remarks-text"><?php echo htmlspecialchars($record['remarks']); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-times"></i>
                    <h2>No Attendance Recorded</h2>
                    <p>
                        <?php if ($is_today): ?>
                            Your teacher hasn't marked attendance yet. Please check back later.
                        <?php else: ?>
                            No attendance records found for <?php echo $selected_date_display; ?>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function changeDate(date) {
            window.location.href = '?date=' + date;
        }

        // Highlight current active date button
        document.addEventListener('DOMContentLoaded', function() {
            const dateInput = document.getElementById('attendance-date');
            const selectedDate = dateInput.value;
            const today = '<?php echo date('Y-m-d'); ?>';
            const yesterday = '<?php echo date('Y-m-d', strtotime('-1 day')); ?>';

            // Update button states
            document.querySelectorAll('.quick-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            if (selectedDate === today) {
                document.querySelectorAll('.quick-btn')[0].classList.add('active');
            } else if (selectedDate === yesterday) {
                document.querySelectorAll('.quick-btn')[1].classList.add('active');
            }
        });
    </script>
</body>
</html>
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

// Get recent attendance records (last 20 entries)
$attendance_query = "SELECT a.*, sub.subject_name, sub.subject_code, t.full_name as teacher_name,
                     DAYNAME(a.attendance_date) as day_name,
                     TIME_FORMAT(a.created_at, '%h:%i %p') as marked_time
                     FROM attendance a
                     JOIN subjects sub ON a.subject_id = sub.subject_id
                     JOIN teachers t ON a.marked_by = t.teacher_id
                     WHERE a.student_id = ?
                     ORDER BY a.attendance_date DESC, a.created_at DESC
                     LIMIT 20";
$stmt = $conn->prepare($attendance_query);
$stmt->bind_param("i", $student['student_id']);
$stmt->execute();
$attendance_records = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recent Attendance - College ERP</title>
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
            max-width: 1600px;
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

        .records-card {
            background: var(--white);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }

        .records-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .records-header i {
            color: var(--purple);
            font-size: 2rem;
        }

        .records-title {
            font-size: 2rem;
            font-weight: 800;
            color: var(--dark);
        }

        .student-info {
            display: flex;
            gap: 30px;
            padding: 20px;
            background: linear-gradient(135deg, #f8f9ff 0%, #f0f0ff 100%);
            border-radius: 15px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-item i {
            color: var(--purple);
            font-size: 1.2rem;
        }

        .info-label {
            font-weight: 600;
            color: var(--gray);
            font-size: 0.85rem;
        }

        .info-value {
            font-weight: 700;
            color: var(--dark);
            font-size: 1rem;
        }

        .table-container {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 15px;
            padding: 2px;
            overflow: hidden;
        }

        .table-wrapper {
            background: var(--white);
            border-radius: 13px;
            overflow-x: auto;
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
            color: var(--white);
            font-weight: 700;
            padding: 20px 15px;
            text-align: left;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            white-space: nowrap;
        }

        thead th i {
            margin-right: 8px;
        }

        tbody tr {
            border-bottom: 1px solid #f3f4f6;
            transition: background 0.3s;
        }

        tbody tr:hover {
            background: #faf5ff;
        }

        tbody tr:last-child {
            border-bottom: none;
        }

        tbody td {
            padding: 18px 15px;
            color: var(--dark);
            font-weight: 500;
            font-size: 0.9rem;
        }

        .subject-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .subject-name {
            font-weight: 700;
            color: var(--dark);
        }

        .subject-code {
            font-size: 0.75rem;
            color: var(--gray);
            background: var(--light-gray);
            padding: 4px 10px;
            border-radius: 8px;
            display: inline-block;
            width: fit-content;
        }

        .time-info {
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--gray);
            font-size: 0.85rem;
        }

        .time-info i {
            color: var(--purple);
        }

        .date-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .date-day {
            font-weight: 700;
            color: var(--dark);
        }

        .date-full {
            font-size: 0.85rem;
            color: var(--gray);
        }

        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .status-badge i {
            font-size: 0.8rem;
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

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.3;
        }

        .empty-state p {
            font-size: 1.2rem;
            font-weight: 600;
        }

        .total-records {
            background: var(--purple);
            color: var(--white);
            padding: 10px 20px;
            border-radius: 25px;
            font-weight: 700;
            font-size: 0.9rem;
        }

        @media (max-width: 768px) {
            body {
                padding: 15px;
            }

            .records-card {
                padding: 20px;
            }

            .records-title {
                font-size: 1.5rem;
            }

            .student-info {
                gap: 15px;
            }

            .table-wrapper {
                overflow-x: auto;
            }

            thead th {
                font-size: 0.7rem;
                padding: 15px 10px;
            }

            tbody td {
                padding: 15px 10px;
                font-size: 0.85rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>

        <div class="records-card">
            <div class="records-header">
                <div class="header-left">
                    <i class="fas fa-history"></i>
                    <h1 class="records-title">Recent Attendance Records</h1>
                </div>
                <?php if ($attendance_records->num_rows > 0): ?>
                <div class="total-records">
                    <i class="fas fa-list"></i> Showing <?php echo $attendance_records->num_rows; ?> Records
                </div>
                <?php endif; ?>
            </div>

            <div class="student-info">
                <div class="info-item">
                    <i class="fas fa-user-graduate"></i>
                    <div>
                        <div class="info-label">Student Name</div>
                        <div class="info-value"><?php echo htmlspecialchars($student['full_name']); ?></div>
                    </div>
                </div>
                <div class="info-item">
                    <i class="fas fa-id-card"></i>
                    <div>
                        <div class="info-label">Roll Number</div>
                        <div class="info-value"><?php echo htmlspecialchars($student['roll_number']); ?></div>
                    </div>
                </div>
                <div class="info-item">
                    <i class="fas fa-building"></i>
                    <div>
                        <div class="info-label">Department</div>
                        <div class="info-value"><?php echo htmlspecialchars($student['department_name']); ?></div>
                    </div>
                </div>
                <div class="info-item">
                    <i class="fas fa-graduation-cap"></i>
                    <div>
                        <div class="info-label">Course</div>
                        <div class="info-value"><?php echo htmlspecialchars($student['course_name']); ?></div>
                    </div>
                </div>
            </div>

            <div class="table-container">
                <div class="table-wrapper">
                    <?php if ($attendance_records->num_rows > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th><i class="fas fa-calendar-alt"></i> DATE & DAY</th>
                                <th><i class="fas fa-book"></i> SUBJECT</th>
                                <th><i class="fas fa-clock"></i> TIME</th>
                                <th><i class="fas fa-user-tie"></i> MARKED BY</th>
                                <th><i class="fas fa-check-circle"></i> STATUS</th>
                                <th><i class="fas fa-comment"></i> REMARKS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Reset pointer to beginning
                            $attendance_records->data_seek(0);
                            while($record = $attendance_records->fetch_assoc()): 
                            ?>
                            <tr>
                                <td>
                                    <div class="date-info">
                                        <span class="date-day"><?php echo $record['day_name']; ?></span>
                                        <span class="date-full"><?php echo date('d M Y', strtotime($record['attendance_date'])); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="subject-info">
                                        <span class="subject-name"><?php echo htmlspecialchars($record['subject_name']); ?></span>
                                        <span class="subject-code"><?php echo htmlspecialchars($record['subject_code']); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="time-info">
                                        <i class="fas fa-clock"></i>
                                        <span><?php echo $record['marked_time']; ?></span>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($record['teacher_name']); ?></td>
                                <td>
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
                                </td>
                                <td><?php echo $record['remarks'] ? htmlspecialchars($record['remarks']) : '-'; ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <p>No attendance records found.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
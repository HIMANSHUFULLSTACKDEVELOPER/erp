<?php
date_default_timezone_set('Asia/Kolkata');
require_once '../config.php';

if (!isLoggedIn() || !hasRole('teacher')) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

// ─── Get teacher details ───
$sql = "SELECT t.*, d.department_name, d.department_id 
        FROM teachers t 
        JOIN departments d ON t.department_id = d.department_id
        WHERE t.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();

if (!$teacher) {
    die("Teacher profile not found.");
}

$teacher_id = $teacher['teacher_id'];

// ─── Check if teacher is a class teacher ───
$class_teacher_query = "SELECT * FROM v_class_teacher_details WHERE teacher_id = ? AND is_active = 1";
$stmt = $conn->prepare($class_teacher_query);
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$class_teacher = $stmt->get_result()->fetch_assoc();

if (!$class_teacher) {
    die("You are not assigned as a class teacher for any class.");
}

// ─── DATE HANDLING ───
// This file references itself. Use basename so the URL always works.
$self = basename($_SERVER['SCRIPT_FILENAME']);

$today = date('Y-m-d');
$view_date = $today; // default

if (isset($_GET['date']) && $_GET['date'] !== '') {
    $input = trim($_GET['date']);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) {
        $p = explode('-', $input);
        if (checkdate((int)$p[1], (int)$p[2], (int)$p[0])) {
            $view_date = $input;
        }
    }
}

// Compute prev / next using mktime (no strtotime needed)
$vp = explode('-', $view_date);
$view_year  = (int)$vp[0];
$view_month = (int)$vp[1];
$view_day   = (int)$vp[2];

$prev_date    = date('Y-m-d', mktime(0, 0, 0, $view_month, $view_day - 1, $view_year));
$next_date    = date('Y-m-d', mktime(0, 0, 0, $view_month, $view_day + 1, $view_year));
$display_date = date('d M Y', mktime(0, 0, 0, $view_month, $view_day, $view_year));

$dept_id    = $class_teacher['department_id'];
$semester_id = $class_teacher['semester_id'];
$section_id  = $class_teacher['section_id'];

// ─── Get ALL students in this class ───
$all_students_query = "
    SELECT s.student_id, s.full_name, s.admission_number, u.phone, u.email
    FROM students s
    JOIN users u ON s.user_id = u.user_id
    JOIN student_semesters ss ON s.student_id = ss.student_id
    WHERE s.department_id = ?
      AND ss.semester_id = ?
      AND ss.section_id = ?
      AND ss.is_active = 1
    ORDER BY s.full_name ASC
";
$stmt = $conn->prepare($all_students_query);
$stmt->bind_param("iii", $dept_id, $semester_id, $section_id);
$stmt->execute();
$all_students_result = $stmt->get_result();

$all_students = [];
while ($row = $all_students_result->fetch_assoc()) {
    $all_students[$row['student_id']] = $row;
}
$total_students = count($all_students);

// ─── Get ALL subjects for this department + semester ───
$subjects_query = "
    SELECT subject_id, subject_name, subject_code
    FROM subjects
    WHERE department_id = ? AND semester_id = ?
    ORDER BY subject_code ASC
";
$stmt = $conn->prepare($subjects_query);
$stmt->bind_param("ii", $dept_id, $semester_id);
$stmt->execute();
$subjects_result = $stmt->get_result();

$subjects = [];
while ($row = $subjects_result->fetch_assoc()) {
    $subjects[$row['subject_id']] = $row;
}

// ─── Get attendance records for the selected date ───
$attendance_query = "
    SELECT a.student_id, a.subject_id, a.status, a.remarks,
           sub.subject_name, sub.subject_code,
           t.full_name AS marked_by_name
    FROM attendance a
    JOIN subjects sub ON a.subject_id = sub.subject_id
    JOIN teachers t ON a.marked_by = t.teacher_id
    JOIN student_semesters ss ON a.student_id = ss.student_id
    JOIN students s ON a.student_id = s.student_id
    WHERE s.department_id = ?
      AND ss.semester_id = ?
      AND ss.section_id = ?
      AND ss.is_active = 1
      AND a.attendance_date = ?
    ORDER BY sub.subject_code ASC, s.full_name ASC
";
$stmt = $conn->prepare($attendance_query);
$stmt->bind_param("iiis", $dept_id, $semester_id, $section_id, $view_date);
$stmt->execute();
$attendance_result = $stmt->get_result();

// ─── Build data structures ───
$attendance_map = [];
$subject_stats  = [];
$student_summary = [];

foreach ($subjects as $sub_id => $sub) {
    $subject_stats[$sub_id] = ['present' => 0, 'absent' => 0, 'late' => 0, 'marked' => 0];
}
foreach ($all_students as $stu_id => $stu) {
    $student_summary[$stu_id] = ['present' => 0, 'absent' => 0, 'late' => 0, 'total_marked' => 0];
}

while ($row = $attendance_result->fetch_assoc()) {
    $sid   = $row['student_id'];
    $subid = $row['subject_id'];

    $attendance_map[$sid][$subid] = [
        'status'    => $row['status'],
        'remarks'   => $row['remarks'],
        'marked_by' => $row['marked_by_name']
    ];

    if (isset($subject_stats[$subid])) {
        $subject_stats[$subid][$row['status']]++;
        $subject_stats[$subid]['marked']++;
    }
    if (isset($student_summary[$sid])) {
        $student_summary[$sid][$row['status']]++;
        $student_summary[$sid]['total_marked']++;
    }
}

// ─── Active subjects (only those with at least one record today) ───
$active_subjects = [];
foreach ($subject_stats as $sub_id => $stats) {
    if ($stats['marked'] > 0) {
        $active_subjects[$sub_id] = $subjects[$sub_id];
    }
}

// ─── Classify students ───
$present_students   = [];
$absent_students    = [];
$late_students      = [];
$mixed_students     = [];
$no_record_students = [];

foreach ($all_students as $sid => $stu) {
    $s = $student_summary[$sid];
    if ($s['total_marked'] === 0) {
        $no_record_students[] = $sid;
    } elseif ($s['absent'] > 0 && $s['present'] === 0 && $s['late'] === 0) {
        $absent_students[] = $sid;
    } elseif ($s['present'] > 0 && $s['absent'] === 0 && $s['late'] === 0) {
        $present_students[] = $sid;
    } elseif ($s['late'] > 0 && $s['present'] === 0 && $s['absent'] === 0) {
        $late_students[] = $sid;
    } else {
        $mixed_students[] = $sid;
    }
}

$total_present   = count($present_students);
$total_absent    = count($absent_students);
$total_late      = count($late_students);
$total_mixed     = count($mixed_students);
$total_no_record = count($no_record_students);
$total_marked    = $total_present + $total_absent + $total_late + $total_mixed;

$effectively_present = 0;
foreach ($all_students as $sid => $stu) {
    $s = $student_summary[$sid];
    if ($s['present'] > 0 || $s['late'] > 0) {
        $effectively_present++;
    }
}
$attendance_pct = $total_marked > 0 ? round(($effectively_present / $total_marked) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Today's Attendance - Class Teacher</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #f97316;
            --secondary: #ea580c;
            --success: #22c55e;
            --warning: #eab308;
            --danger: #ef4444;
            --dark: #0c0a09;
            --gray: #78716c;
            --light-gray: #fafaf9;
            --white: #ffffff;
            --bg: #f5f3f0;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Outfit',sans-serif; background:var(--bg); color:var(--dark); min-height:100vh; }

        /* SIDEBAR */
        .sidebar {
            width:280px; background:linear-gradient(180deg,var(--dark) 0%,#292524 100%);
            color:var(--white); position:fixed; height:100vh; overflow-y:auto; z-index:100;
        }
        .sidebar-header { padding:30px 25px; background:linear-gradient(135deg,var(--primary),var(--secondary)); }
        .teacher-profile { text-align:center; }
        .teacher-avatar {
            width:75px; height:75px; border-radius:15px; background:rgba(255,255,255,0.2);
            backdrop-filter:blur(10px); color:var(--white); display:flex; align-items:center;
            justify-content:center; font-size:2rem; font-weight:700; margin:0 auto 15px;
            border:3px solid rgba(255,255,255,0.3);
        }
        .teacher-name { font-size:1.3rem; font-weight:700; margin-bottom:5px; }
        .teacher-role { font-size:0.9rem; opacity:0.9; font-weight:500; }
        .class-info { margin-top:15px; padding-top:15px; border-top:1px solid rgba(255,255,255,0.2); }
        .class-info-item { font-size:0.85rem; opacity:0.9; margin-bottom:5px; }
        .sidebar-menu { padding:25px 0; }
        .menu-item {
            padding:16px 25px; color:rgba(255,255,255,0.7); text-decoration:none;
            display:flex; align-items:center; transition:all 0.3s; cursor:pointer; position:relative;
        }
        .menu-item::before {
            content:''; position:absolute; left:0; top:0; height:100%; width:4px;
            background:var(--primary); transform:scaleY(0); transition:transform 0.3s;
        }
        .menu-item:hover::before, .menu-item.active::before { transform:scaleY(1); }
        .menu-item:hover, .menu-item.active { background:rgba(249,115,22,0.1); color:var(--white); }
        .menu-item i { margin-right:15px; width:22px; text-align:center; font-size:1.1rem; }

        /* MAIN */
        .main-content { margin-left:280px; padding:30px; min-height:100vh; }

        /* TOP BAR */
        .top-bar {
            background:var(--white); padding:22px 30px; border-radius:20px; margin-bottom:24px;
            display:flex; justify-content:space-between; align-items:center;
            box-shadow:0 4px 20px rgba(0,0,0,0.07); flex-wrap:wrap; gap:12px;
        }
        .top-bar-left h1 {
            font-size:1.9rem; font-weight:800;
            background:linear-gradient(135deg,var(--primary),var(--secondary));
            -webkit-background-clip:text; -webkit-text-fill-color:transparent; margin-bottom:4px;
        }
        .top-bar-left p { color:var(--gray); font-size:0.9rem; }
        .top-bar-right { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }

        /* DATE NAV */
        .date-nav {
            display:flex; align-items:center; gap:8px;
            background:#f5f3f0; padding:8px 14px; border-radius:12px;
        }
        .date-nav a.nav-btn {
            background:var(--white); border:1px solid #e7e5e4; color:var(--dark);
            width:34px; height:34px; border-radius:8px; cursor:pointer;
            display:flex; align-items:center; justify-content:center;
            transition:all 0.2s; text-decoration:none; font-size:0.85rem;
        }
        .date-nav a.nav-btn:hover { background:var(--primary); color:var(--white); border-color:var(--primary); }
        .date-nav .current-date { font-weight:600; font-size:0.9rem; color:var(--dark); min-width:100px; text-align:center; }

        .logout-btn {
            background:linear-gradient(135deg,var(--danger),#dc2626); color:var(--white);
            border:none; padding:10px 22px; border-radius:12px; cursor:pointer;
            font-weight:600; font-family:'Outfit',sans-serif; font-size:0.88rem;
            transition:all 0.3s; box-shadow:0 4px 12px rgba(239,68,68,0.3);
            text-decoration:none; display:inline-flex; align-items:center; gap:6px;
        }
        .logout-btn:hover { transform:translateY(-2px); box-shadow:0 6px 18px rgba(239,68,68,0.4); }

        /* SUMMARY CARDS */
        .summary-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:16px; margin-bottom:24px; }
        .sum-card {
            background:var(--white); border-radius:16px; padding:20px 18px;
            box-shadow:0 4px 16px rgba(0,0,0,0.06); display:flex; align-items:center;
            gap:14px; transition:transform 0.2s;
        }
        .sum-card:hover { transform:translateY(-2px); }
        .sum-icon {
            width:46px; height:46px; border-radius:12px; display:flex;
            align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0;
        }
        .sum-icon.orange  { background:rgba(249,115,22,0.12);  color:var(--primary); }
        .sum-icon.green   { background:rgba(34,197,94,0.12);   color:var(--success); }
        .sum-icon.red     { background:rgba(239,68,68,0.12);   color:var(--danger); }
        .sum-icon.yellow  { background:rgba(234,179,8,0.12);   color:var(--warning); }
        .sum-icon.blue    { background:rgba(59,130,246,0.12);  color:#3b82f6; }
        .sum-icon.purple  { background:rgba(168,85,247,0.12);  color:#a855f7; }
        .sum-text .sum-number { font-size:1.7rem; font-weight:800; color:var(--dark); line-height:1.1; }
        .sum-text .sum-label  { font-size:0.78rem; color:var(--gray); font-weight:500; margin-top:2px; }

        /* SECTION CARDS */
        .section-card { background:var(--white); border-radius:18px; box-shadow:0 4px 18px rgba(0,0,0,0.07); margin-bottom:22px; overflow:hidden; }
        .section-card-header {
            padding:18px 24px; display:flex; align-items:center; justify-content:space-between;
            border-bottom:2px solid #f5f3f0; flex-wrap:wrap; gap:8px;
        }
        .section-card-header h3 { font-size:1.15rem; font-weight:700; display:flex; align-items:center; gap:10px; }
        .section-card-header h3 i { color:var(--primary); }
        .badge {
            padding:5px 12px; border-radius:20px; font-size:0.75rem; font-weight:600;
            display:inline-flex; align-items:center; gap:5px;
        }
        .badge.green  { background:rgba(34,197,94,0.12);  color:var(--success); }
        .badge.red    { background:rgba(239,68,68,0.12);  color:var(--danger); }
        .badge.yellow { background:rgba(234,179,8,0.12);  color:var(--warning); }
        .badge.blue   { background:rgba(59,130,246,0.12); color:#3b82f6; }
        .badge.gray   { background:rgba(120,113,108,0.1); color:var(--gray); }
        .badge.orange { background:rgba(249,115,22,0.12); color:var(--primary); }
        .section-card-body { padding:0; }

        /* STUDENT TABLE */
        .student-table { width:100%; border-collapse:collapse; }
        .student-table thead th {
            text-align:left; padding:11px 20px; background:#faf9f7; font-size:0.76rem;
            font-weight:600; color:var(--gray); text-transform:uppercase;
            letter-spacing:0.5px; border-bottom:2px solid #f0eeec; white-space:nowrap;
        }
        .student-table tbody td { padding:13px 20px; border-bottom:1px solid #f0eeec; font-size:0.88rem; color:var(--dark); }
        .student-table tbody tr:last-child td { border-bottom:none; }
        .student-table tbody tr:hover { background:#fef9f5; }
        .student-name-cell { display:flex; align-items:center; gap:10px; }
        .stu-avatar {
            width:34px; height:34px; border-radius:9px; display:flex; align-items:center;
            justify-content:center; font-weight:700; font-size:0.85rem; color:var(--white); flex-shrink:0;
        }
        .stu-avatar.green  { background:linear-gradient(135deg,#22c55e,#16a34a); }
        .stu-avatar.red    { background:linear-gradient(135deg,#ef4444,#dc2626); }
        .stu-avatar.yellow { background:linear-gradient(135deg,#eab308,#ca8a04); }
        .stu-avatar.blue   { background:linear-gradient(135deg,#3b82f6,#2563eb); }
        .stu-avatar.gray   { background:linear-gradient(135deg,#78716c,#57534e); }
        .stu-info .stu-name { font-weight:600; font-size:0.9rem; }
        .stu-info .stu-adm  { font-size:0.76rem; color:var(--gray); }

        /* SUBJECT PILLS */
        .subject-pills { display:flex; flex-wrap:wrap; gap:6px; }
        .subj-pill {
            padding:4px 10px; border-radius:14px; font-size:0.74rem; font-weight:600;
            display:inline-flex; align-items:center; gap:4px;
        }
        .subj-pill.present { background:rgba(34,197,94,0.1);  color:#16a34a; }
        .subj-pill.absent  { background:rgba(239,68,68,0.1);  color:#dc2626; }
        .subj-pill.late    { background:rgba(234,179,8,0.1);  color:#ca8a04; }

        /* SUBJECT SUMMARY TABLE */
        .subj-summary-table { width:100%; border-collapse:collapse; }
        .subj-summary-table thead th {
            text-align:center; padding:10px 14px;
            background:linear-gradient(135deg,var(--primary),var(--secondary));
            color:var(--white); font-size:0.78rem; font-weight:600;
            text-transform:uppercase; letter-spacing:0.4px;
        }
        .subj-summary-table thead th:first-child { text-align:left; }
        .subj-summary-table tbody td {
            padding:11px 14px; border-bottom:1px solid #f0eeec;
            font-size:0.85rem; text-align:center; color:var(--dark);
        }
        .subj-summary-table tbody td:first-child { text-align:left; font-weight:600; }
        .subj-summary-table tbody tr:last-child td { border-bottom:none; }
        .subj-summary-table tbody tr:hover { background:#fef9f5; }
        .pct-badge { padding:3px 10px; border-radius:12px; font-size:0.78rem; font-weight:700; display:inline-block; }
        .pct-badge.good { background:rgba(34,197,94,0.12);  color:var(--success); }
        .pct-badge.ok   { background:rgba(234,179,8,0.12);  color:var(--warning); }
        .pct-badge.bad  { background:rgba(239,68,68,0.12);  color:var(--danger); }

        /* EMPTY STATE */
        .empty-state { text-align:center; padding:50px 20px; color:var(--gray); }
        .empty-state i { font-size:2.5rem; margin-bottom:12px; color:#d6d3d1; }
        .empty-state p { font-size:0.95rem; }

        /* PRINT */
        .print-btn {
            background:var(--white); border:2px solid #e7e5e4; color:var(--dark);
            padding:8px 18px; border-radius:10px; cursor:pointer; font-weight:600;
            font-family:'Outfit',sans-serif; font-size:0.85rem; transition:all 0.2s;
            display:inline-flex; align-items:center; gap:6px;
        }
        .print-btn:hover { border-color:var(--primary); color:var(--primary); }

        @media print {
            .sidebar, .top-bar-right, .print-btn { display:none !important; }
            .main-content { margin-left:0 !important; padding:20px !important; }
            .section-card { box-shadow:none; border:1px solid #ddd; margin-bottom:16px; }
            body { background:white; }
        }
        @media (max-width:768px) {
            .sidebar { transform:translateX(-100%); }
            .main-content { margin-left:0; padding:16px; }
            .student-table thead th, .student-table tbody td { padding:10px 12px; font-size:0.8rem; }
        }
    </style>
</head>
<body>
<div style="display:flex; min-height:100vh;">

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="sidebar-header">
        <div class="teacher-profile">
            <div class="teacher-avatar"><?php echo strtoupper(substr($class_teacher['teacher_name'], 0, 1)); ?></div>
            <div class="teacher-name"><?php echo htmlspecialchars($class_teacher['teacher_name']); ?></div>
            <div class="teacher-role">Class Teacher</div>
            <div class="class-info">
                <div class="class-info-item"><i class="fas fa-building"></i> <?php echo htmlspecialchars($class_teacher['department_name']); ?></div>
                <div class="class-info-item"><i class="fas fa-layer-group"></i> <?php echo htmlspecialchars($class_teacher['semester_name']); ?></div>
                <div class="class-info-item"><i class="fas fa-users"></i> Section <?php echo htmlspecialchars($class_teacher['section_name']); ?></div>
            </div>
        </div>
    </div>
    <nav class="sidebar-menu">
        <a href="class_teacher_dashboard.php" class="menu-item"><i class="fas fa-home"></i> Dashboard</a>
        <a href="class_students.php" class="menu-item"><i class="fas fa-user-graduate"></i> My Students</a>
        <a href="class_attendance_records.php" class="menu-item"><i class="fas fa-calendar-check"></i> Attendance Records</a>
        <a href="class_attendance_datewise.php" class="menu-item"><i class="fas fa-calendar-alt"></i> Date-wise Attendance</a>
        <a href="<?php echo $self; ?>" class="menu-item active"><i class="fas fa-clipboard-check"></i> Today's Attendance</a>
        <a href="class_absent_students.php" class="menu-item"><i class="fas fa-user-times"></i> Absent Students</a>
        <a href="class_reports.php" class="menu-item"><i class="fas fa-chart-line"></i> Reports</a>
        <a href="teacher_profile.php" class="menu-item"><i class="fas fa-user"></i> Profile</a>
    </nav>
</aside>

<!-- MAIN CONTENT -->
<main class="main-content">

    <!-- TOP BAR -->
    <div class="top-bar">
        <div class="top-bar-left">
            <h1>Today's Full-Day Attendance</h1>
            <p><?php echo htmlspecialchars($class_teacher['semester_name']); ?> &middot; Section <?php echo htmlspecialchars($class_teacher['section_name']); ?> &middot; Academic Year <?php echo htmlspecialchars($class_teacher['academic_year']); ?></p>
        </div>
        <div class="top-bar-right">
            <!-- Date Navigation -->
            <div class="date-nav">
                <a href="<?php echo $self; ?>?date=<?php echo $prev_date; ?>" class="nav-btn" title="Previous day"><i class="fas fa-chevron-left"></i></a>
                <span class="current-date"><?php echo $display_date; ?></span>
                <a href="<?php echo $self; ?>?date=<?php echo $next_date; ?>" class="nav-btn" title="Next day"><i class="fas fa-chevron-right"></i></a>
                <?php if ($view_date !== $today): ?>
                <a href="<?php echo $self; ?>" class="nav-btn" style="background:var(--primary); color:white; border-color:var(--primary);" title="Back to today"><i class="fas fa-redo" style="font-size:0.7rem;"></i></a>
                <?php endif; ?>
            </div>
            <button class="print-btn" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <!-- SUMMARY CARDS -->
    <div class="summary-grid">
        <div class="sum-card">
            <div class="sum-icon orange"><i class="fas fa-users"></i></div>
            <div class="sum-text">
                <div class="sum-number"><?php echo $total_students; ?></div>
                <div class="sum-label">Total Students</div>
            </div>
        </div>
        <div class="sum-card">
            <div class="sum-icon green"><i class="fas fa-check-circle"></i></div>
            <div class="sum-text">
                <div class="sum-number"><?php echo $total_present; ?></div>
                <div class="sum-label">All Present</div>
            </div>
        </div>
        <div class="sum-card">
            <div class="sum-icon red"><i class="fas fa-times-circle"></i></div>
            <div class="sum-text">
                <div class="sum-number"><?php echo $total_absent; ?></div>
                <div class="sum-label">All Absent</div>
            </div>
        </div>
        <div class="sum-card">
            <div class="sum-icon yellow"><i class="fas fa-clock"></i></div>
            <div class="sum-text">
                <div class="sum-number"><?php echo $total_late; ?></div>
                <div class="sum-label">Late Only</div>
            </div>
        </div>
        <div class="sum-card">
            <div class="sum-icon blue"><i class="fas fa-exchange-alt"></i></div>
            <div class="sum-text">
                <div class="sum-number"><?php echo $total_mixed; ?></div>
                <div class="sum-label">Mixed</div>
            </div>
        </div>
        <div class="sum-card">
            <div class="sum-icon purple"><i class="fas fa-percentage"></i></div>
            <div class="sum-text">
                <div class="sum-number"><?php echo $attendance_pct; ?>%</div>
                <div class="sum-label">Attendance Rate</div>
            </div>
        </div>
    </div>

    <?php if (empty($active_subjects)): ?>
        <!-- NO ATTENDANCE RECORDED -->
        <div class="section-card">
            <div class="empty-state">
                <i class="fas fa-clipboard"></i>
                <p><strong>No attendance has been recorded for <?php echo $display_date; ?></strong><br>
                   Check back after teachers mark attendance for this day.</p>
            </div>
        </div>
    <?php else: ?>

    <!-- PRESENT STUDENTS -->
    <div class="section-card">
        <div class="section-card-header">
            <h3><i class="fas fa-check-circle" style="color:var(--success);"></i> Present Students</h3>
            <span class="badge green"><i class="fas fa-user"></i> <?php echo $total_present; ?> Student<?php echo $total_present !== 1 ? 's' : ''; ?></span>
        </div>
        <div class="section-card-body">
            <?php if (empty($present_students)): ?>
                <div class="empty-state" style="padding:30px;"><p style="color:var(--gray);">No students marked present in all subjects.</p></div>
            <?php else: ?>
            <table class="student-table">
                <thead><tr><th>#</th><th>Student</th><th>Subjects</th><th>Contact</th></tr></thead>
                <tbody>
                    <?php $i=1; foreach($present_students as $sid): $stu=$all_students[$sid]; ?>
                    <tr>
                        <td style="color:var(--gray);font-weight:600;"><?php echo $i++; ?></td>
                        <td>
                            <div class="student-name-cell">
                                <div class="stu-avatar green"><?php echo strtoupper(substr($stu['full_name'],0,1)); ?></div>
                                <div class="stu-info">
                                    <div class="stu-name"><?php echo htmlspecialchars($stu['full_name']); ?></div>
                                    <div class="stu-adm">Adm: <?php echo htmlspecialchars($stu['admission_number']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="subject-pills">
                                <?php foreach($active_subjects as $subid=>$sub):
                                    if(isset($attendance_map[$sid][$subid])): ?>
                                    <span class="subj-pill <?php echo $attendance_map[$sid][$subid]['status']; ?>">
                                        <i class="fas fa-check" style="font-size:0.6rem;"></i>
                                        <?php echo htmlspecialchars($sub['subject_code']); ?>
                                    </span>
                                <?php endif; endforeach; ?>
                            </div>
                        </td>
                        <td style="color:var(--gray);font-size:0.82rem;"><?php echo htmlspecialchars($stu['phone'] ?: 'N/A'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- ABSENT STUDENTS -->
    <div class="section-card">
        <div class="section-card-header">
            <h3><i class="fas fa-times-circle" style="color:var(--danger);"></i> Absent Students</h3>
            <span class="badge red"><i class="fas fa-user"></i> <?php echo $total_absent; ?> Student<?php echo $total_absent !== 1 ? 's' : ''; ?></span>
        </div>
        <div class="section-card-body">
            <?php if (empty($absent_students)): ?>
                <div class="empty-state" style="padding:30px;"><p style="color:var(--gray);">No students marked absent in all subjects.</p></div>
            <?php else: ?>
            <table class="student-table">
                <thead><tr><th>#</th><th>Student</th><th>Subjects (Absent)</th><th>Contact</th><th>Parent Email</th></tr></thead>
                <tbody>
                    <?php $i=1; foreach($absent_students as $sid): $stu=$all_students[$sid]; ?>
                    <tr>
                        <td style="color:var(--gray);font-weight:600;"><?php echo $i++; ?></td>
                        <td>
                            <div class="student-name-cell">
                                <div class="stu-avatar red"><?php echo strtoupper(substr($stu['full_name'],0,1)); ?></div>
                                <div class="stu-info">
                                    <div class="stu-name"><?php echo htmlspecialchars($stu['full_name']); ?></div>
                                    <div class="stu-adm">Adm: <?php echo htmlspecialchars($stu['admission_number']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="subject-pills">
                                <?php foreach($active_subjects as $subid=>$sub):
                                    if(isset($attendance_map[$sid][$subid])): ?>
                                    <span class="subj-pill absent">
                                        <i class="fas fa-times" style="font-size:0.6rem;"></i>
                                        <?php echo htmlspecialchars($sub['subject_code']); ?>
                                    </span>
                                <?php endif; endforeach; ?>
                            </div>
                        </td>
                        <td style="color:var(--gray);font-size:0.82rem;"><?php echo htmlspecialchars($stu['phone'] ?: 'N/A'); ?></td>
                        <td style="color:var(--gray);font-size:0.8rem;">
                            <?php
                                $pq = "SELECT u.email FROM parents p
                                        JOIN parent_student ps ON p.parent_id = ps.parent_id
                                        JOIN users u ON p.user_id = u.user_id
                                        WHERE ps.student_id = ?";
                                $pstmt = $conn->prepare($pq);
                                $pstmt->bind_param("i", $sid);
                                $pstmt->execute();
                                $prow = $pstmt->get_result()->fetch_assoc();
                                echo $prow ? '<a href="mailto:'.$prow['email'].'" style="color:var(--primary);">' . htmlspecialchars($prow['email']) . '</a>' : 'N/A';
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- LATE STUDENTS -->
    <?php if (!empty($late_students)): ?>
    <div class="section-card">
        <div class="section-card-header">
            <h3><i class="fas fa-clock" style="color:var(--warning);"></i> Late Students</h3>
            <span class="badge yellow"><i class="fas fa-user"></i> <?php echo $total_late; ?> Student<?php echo $total_late !== 1 ? 's' : ''; ?></span>
        </div>
        <div class="section-card-body">
            <table class="student-table">
                <thead><tr><th>#</th><th>Student</th><th>Subjects (Late)</th><th>Contact</th></tr></thead>
                <tbody>
                    <?php $i=1; foreach($late_students as $sid): $stu=$all_students[$sid]; ?>
                    <tr>
                        <td style="color:var(--gray);font-weight:600;"><?php echo $i++; ?></td>
                        <td>
                            <div class="student-name-cell">
                                <div class="stu-avatar yellow"><?php echo strtoupper(substr($stu['full_name'],0,1)); ?></div>
                                <div class="stu-info">
                                    <div class="stu-name"><?php echo htmlspecialchars($stu['full_name']); ?></div>
                                    <div class="stu-adm">Adm: <?php echo htmlspecialchars($stu['admission_number']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="subject-pills">
                                <?php foreach($active_subjects as $subid=>$sub):
                                    if(isset($attendance_map[$sid][$subid])): ?>
                                    <span class="subj-pill late">
                                        <i class="fas fa-clock" style="font-size:0.6rem;"></i>
                                        <?php echo htmlspecialchars($sub['subject_code']); ?>
                                    </span>
                                <?php endif; endforeach; ?>
                            </div>
                        </td>
                        <td style="color:var(--gray);font-size:0.82rem;"><?php echo htmlspecialchars($stu['phone'] ?: 'N/A'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- MIXED ATTENDANCE -->
    <?php if (!empty($mixed_students)): ?>
    <div class="section-card">
        <div class="section-card-header">
            <h3><i class="fas fa-exchange-alt" style="color:#3b82f6;"></i> Mixed Attendance</h3>
            <span class="badge blue"><i class="fas fa-user"></i> <?php echo $total_mixed; ?> Student<?php echo $total_mixed !== 1 ? 's' : ''; ?></span>
        </div>
        <div class="section-card-body">
            <table class="student-table">
                <thead><tr><th>#</th><th>Student</th><th>Subject-wise Status</th><th>Contact</th></tr></thead>
                <tbody>
                    <?php $i=1; foreach($mixed_students as $sid): $stu=$all_students[$sid]; ?>
                    <tr>
                        <td style="color:var(--gray);font-weight:600;"><?php echo $i++; ?></td>
                        <td>
                            <div class="student-name-cell">
                                <div class="stu-avatar blue"><?php echo strtoupper(substr($stu['full_name'],0,1)); ?></div>
                                <div class="stu-info">
                                    <div class="stu-name"><?php echo htmlspecialchars($stu['full_name']); ?></div>
                                    <div class="stu-adm">Adm: <?php echo htmlspecialchars($stu['admission_number']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="subject-pills">
                                <?php foreach($active_subjects as $subid=>$sub):
                                    if(isset($attendance_map[$sid][$subid])):
                                        $st = $attendance_map[$sid][$subid]['status'];
                                        $ic = $st==='present' ? 'fa-check' : ($st==='absent' ? 'fa-times' : 'fa-clock');
                                ?>
                                    <span class="subj-pill <?php echo $st; ?>">
                                        <i class="fas <?php echo $ic; ?>" style="font-size:0.6rem;"></i>
                                        <?php echo htmlspecialchars($sub['subject_code']); ?>
                                    </span>
                                <?php endif; endforeach; ?>
                            </div>
                        </td>
                        <td style="color:var(--gray);font-size:0.82rem;"><?php echo htmlspecialchars($stu['phone'] ?: 'N/A'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- NO RECORD STUDENTS -->
    <?php if (!empty($no_record_students)): ?>
    <div class="section-card">
        <div class="section-card-header">
            <h3><i class="fas fa-question-circle" style="color:var(--gray);"></i> No Attendance Record</h3>
            <span class="badge gray"><i class="fas fa-user"></i> <?php echo $total_no_record; ?> Student<?php echo $total_no_record !== 1 ? 's' : ''; ?></span>
        </div>
        <div class="section-card-body">
            <table class="student-table">
                <thead><tr><th>#</th><th>Student</th><th>Contact</th><th>Email</th></tr></thead>
                <tbody>
                    <?php $i=1; foreach($no_record_students as $sid): $stu=$all_students[$sid]; ?>
                    <tr>
                        <td style="color:var(--gray);font-weight:600;"><?php echo $i++; ?></td>
                        <td>
                            <div class="student-name-cell">
                                <div class="stu-avatar gray"><?php echo strtoupper(substr($stu['full_name'],0,1)); ?></div>
                                <div class="stu-info">
                                    <div class="stu-name"><?php echo htmlspecialchars($stu['full_name']); ?></div>
                                    <div class="stu-adm">Adm: <?php echo htmlspecialchars($stu['admission_number']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td style="color:var(--gray);font-size:0.82rem;"><?php echo htmlspecialchars($stu['phone'] ?: 'N/A'); ?></td>
                        <td style="color:var(--gray);font-size:0.8rem;"><?php echo htmlspecialchars($stu['email']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- SUBJECT-WISE SUMMARY -->
    <div class="section-card">
        <div class="section-card-header">
            <h3><i class="fas fa-book"></i> Subject-wise Summary</h3>
            <span class="badge orange"><i class="fas fa-layer-group"></i> <?php echo count($active_subjects); ?> Subject<?php echo count($active_subjects)!==1 ? 's' : ''; ?></span>
        </div>
        <div class="section-card-body">
            <table class="subj-summary-table">
                <thead>
                    <tr>
                        <th style="text-align:left;">Subject</th>
                        <th><i class="fas fa-check-circle"></i> Present</th>
                        <th><i class="fas fa-times-circle"></i> Absent</th>
                        <th><i class="fas fa-clock"></i> Late</th>
                        <th><i class="fas fa-users"></i> Marked</th>
                        <th><i class="fas fa-percentage"></i> %</th>
                        <th style="text-align:left;"><i class="fas fa-user"></i> Marked By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($active_subjects as $subid=>$sub):
                        $stats = $subject_stats[$subid];
                        $pct = $stats['marked']>0 ? round(($stats['present']+$stats['late'])/$stats['marked']*100,1) : 0;
                        $pctClass = $pct>=75 ? 'good' : ($pct>=50 ? 'ok' : 'bad');
                        $marked_by_set = [];
                        foreach($attendance_map as $sid2=>$submap) {
                            if(isset($submap[$subid])) { $marked_by_set[$submap[$subid]['marked_by']]=true; }
                        }
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($sub['subject_code']); ?></strong>
                            <div style="font-size:0.76rem;color:var(--gray);font-weight:400;"><?php echo htmlspecialchars($sub['subject_name']); ?></div>
                        </td>
                        <td style="color:var(--success);font-weight:700;"><?php echo $stats['present']; ?></td>
                        <td style="color:var(--danger);font-weight:700;"><?php echo $stats['absent']; ?></td>
                        <td style="color:var(--warning);font-weight:700;"><?php echo $stats['late']; ?></td>
                        <td style="font-weight:600;"><?php echo $stats['marked']; ?></td>
                        <td><span class="pct-badge <?php echo $pctClass; ?>"><?php echo $pct; ?>%</span></td>
                        <td style="font-size:0.8rem;color:var(--gray);"><?php echo implode(', ', array_keys($marked_by_set)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; // end active_subjects check ?>

</main>
</div>
</body>
</html>
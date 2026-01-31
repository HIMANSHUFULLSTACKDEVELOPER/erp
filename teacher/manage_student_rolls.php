<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config.php';

if (!isLoggedIn() || !hasRole('teacher')) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

// Get teacher details
$sql = "SELECT t.*, d.department_name, d.department_id 
        FROM teachers t 
        JOIN departments d ON t.department_id = d.department_id
        WHERE t.user_id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();

if (!$teacher) {
    die("Teacher profile not found.");
}

$teacher_id = $teacher['teacher_id'];

// Check if teacher is a class teacher
$class_teacher_query = "SELECT * FROM v_class_teacher_details WHERE teacher_id = ? AND is_active = 1";
$stmt = $conn->prepare($class_teacher_query);
if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$class_teacher = $stmt->get_result()->fetch_assoc();

if (!$class_teacher) {
    die("You are not assigned as a class teacher for any class.");
}

$message = '';
$error = '';

// Handle roll number assignment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_rolls'])) {
    $semester_id = $class_teacher['semester_id'];
    $section_id = $class_teacher['section_id'];
    $department_id = $class_teacher['department_id'];
    $academic_year = $class_teacher['academic_year'];
    $section_name = $class_teacher['section_name']; // Get section name for prefix
    
    // Get all students in this semester and section who don't have roll numbers
    $students_query = "SELECT s.student_id, s.full_name
                      FROM students s
                      JOIN student_semesters ss ON s.student_id = ss.student_id
                      LEFT JOIN student_roll_numbers srn ON s.student_id = srn.student_id 
                          AND srn.semester_id = ss.semester_id 
                          AND srn.section_id = ss.section_id
                          AND srn.academic_year = ?
                      WHERE s.department_id = ?
                      AND ss.semester_id = ?
                      AND ss.section_id = ?
                      AND ss.is_active = 1
                      AND srn.roll_id IS NULL
                      ORDER BY s.full_name";
    
    $stmt = $conn->prepare($students_query);
    $stmt->bind_param("siii", $academic_year, $department_id, $semester_id, $section_id);
    $stmt->execute();
    $students_result = $stmt->get_result();
    
    if ($students_result->num_rows == 0) {
        $message = "All students already have roll numbers assigned!";
    } else {
        $conn->begin_transaction();
        
        try {
            // Find the highest existing roll number for this section/semester
            $max_roll_query = "SELECT COALESCE(MAX(roll_number), 0) as max_roll 
                              FROM student_roll_numbers 
                              WHERE semester_id = ? AND section_id = ? AND academic_year = ?";
            $max_stmt = $conn->prepare($max_roll_query);
            $max_stmt->bind_param("iis", $semester_id, $section_id, $academic_year);
            $max_stmt->execute();
            $max_result = $max_stmt->get_result()->fetch_assoc();
            $roll_number = $max_result['max_roll'] + 1;
            
            $assigned_count = 0;
            
            while ($student = $students_result->fetch_assoc()) {
                $student_id = $student['student_id'];
                
                // Create formatted roll number with section prefix (e.g., IT-01)
                $formatted_roll = $section_name . '-' . str_pad($roll_number, 2, '0', STR_PAD_LEFT);
                
                // Insert new roll number
                $insert_query = "INSERT INTO student_roll_numbers 
                               (student_id, semester_id, section_id, department_id, roll_number, roll_number_display, academic_year, assigned_date)
                               VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE())";
                $insert_stmt = $conn->prepare($insert_query);
                $insert_stmt->bind_param("iiiisss", $student_id, $semester_id, $section_id, $department_id, $roll_number, $formatted_roll, $academic_year);
                $insert_stmt->execute();
                $assigned_count++;
                $roll_number++;
            }
            
            $conn->commit();
            // Redirect with success message
            header("Location: manage_student_rolls.php?assigned=1&count=$assigned_count");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error assigning roll numbers: " . $e->getMessage();
        }
    }
}

// Handle individual roll number update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_roll'])) {
    $roll_id = $_POST['roll_id'];
    $formatted_roll = strtoupper(trim($_POST['new_roll_number'])); // Get full formatted roll like IT-01
    $semester_id = $class_teacher['semester_id'];
    $section_id = $class_teacher['section_id'];
    $academic_year = $class_teacher['academic_year'];
    
    // Extract numeric part from formatted roll (e.g., IT-01 -> 1)
    $parts = explode('-', $formatted_roll);
    if (count($parts) == 2) {
        $numeric_roll = intval($parts[1]);
    } else {
        $error = "Invalid roll number format! Use format like IT-01, CE-02, etc.";
        $numeric_roll = 0;
    }
    
    if ($numeric_roll > 0) {
        // Check if the new roll number already exists for another student in the same section/semester
        $check_duplicate = "SELECT roll_id FROM student_roll_numbers 
                            WHERE roll_number_display = ? 
                            AND semester_id = ? 
                            AND section_id = ? 
                            AND academic_year = ?
                            AND roll_id != ?";
        $check_stmt = $conn->prepare($check_duplicate);
        $check_stmt->bind_param("siisi", $formatted_roll, $semester_id, $section_id, $academic_year, $roll_id);
        $check_stmt->execute();
        $duplicate = $check_stmt->get_result()->fetch_assoc();
        
        if ($duplicate) {
            $error = "Roll number $formatted_roll is already assigned to another student!";
        } else {
            $update_query = "UPDATE student_roll_numbers 
                            SET roll_number = ?, roll_number_display = ? 
                            WHERE roll_id = ?";
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param("isi", $numeric_roll, $formatted_roll, $roll_id);
            
            if ($stmt->execute()) {
                $message = "Roll number updated successfully!";
                // Redirect to avoid form resubmission
                header("Location: manage_student_rolls.php?success=1");
                exit();
            } else {
                $error = "Error updating roll number: " . $conn->error;
            }
        }
    }
}

// Handle roll number deletion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_roll'])) {
    $roll_id = $_POST['roll_id'];
    
    $delete_query = "DELETE FROM student_roll_numbers WHERE roll_id = ?";
    $stmt = $conn->prepare($delete_query);
    $stmt->bind_param("i", $roll_id);
    
    if ($stmt->execute()) {
        $message = "Roll number deleted successfully!";
        // Redirect to avoid form resubmission
        header("Location: manage_student_rolls.php?deleted=1");
        exit();
    } else {
        $error = "Error deleting roll number: " . $conn->error;
    }
}

// Show success messages from redirects
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $message = "Roll number updated successfully!";
}
if (isset($_GET['deleted']) && $_GET['deleted'] == 1) {
    $message = "Roll number deleted successfully!";
}
if (isset($_GET['assigned']) && $_GET['assigned'] == 1) {
    $assigned_count = isset($_GET['count']) ? $_GET['count'] : 0;
    $message = "Successfully assigned roll numbers to $assigned_count students!";
}
if (isset($_GET['manual_assigned']) && $_GET['manual_assigned'] == 1) {
    $message = "Roll number assigned successfully!";
}

// Handle manual roll number assignment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['manual_assign'])) {
    try {
        $student_id = $_POST['student_id'];
        $formatted_roll = strtoupper(trim($_POST['manual_roll_number'])); // Get full formatted roll like IT-01
        $semester_id = $class_teacher['semester_id'];
        $section_id = $class_teacher['section_id'];
        $department_id = $class_teacher['department_id'];
        $academic_year = $class_teacher['academic_year'];
        
        // Validate input
        if (empty($formatted_roll)) {
            $error = "Roll number cannot be empty!";
        } else {
            // Extract numeric part from formatted roll (e.g., IT-01 -> 1)
            $parts = explode('-', $formatted_roll);
            if (count($parts) == 2 && !empty($parts[0]) && !empty($parts[1])) {
                $numeric_roll = intval($parts[1]);
                
                if ($numeric_roll > 0) {
                    // Check if roll number already exists
                    $check_duplicate = "SELECT roll_id, roll_number_display FROM student_roll_numbers 
                                        WHERE roll_number_display = ? 
                                        AND semester_id = ? 
                                        AND section_id = ? 
                                        AND academic_year = ?";
                    $check_stmt = $conn->prepare($check_duplicate);
                    if (!$check_stmt) {
                        throw new Exception("Database error: " . $conn->error);
                    }
                    $check_stmt->bind_param("siis", $formatted_roll, $semester_id, $section_id, $academic_year);
                    $check_stmt->execute();
                    $duplicate = $check_stmt->get_result()->fetch_assoc();
                    
                    if ($duplicate) {
                        $error = "Roll number $formatted_roll is already assigned to another student!";
                    } else {
                        // Insert new roll number
                        $insert_query = "INSERT INTO student_roll_numbers 
                                        (student_id, semester_id, section_id, department_id, roll_number, roll_number_display, academic_year, assigned_date)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE())";
                        $insert_stmt = $conn->prepare($insert_query);
                        if (!$insert_stmt) {
                            throw new Exception("Database error: " . $conn->error);
                        }
                        $insert_stmt->bind_param("iiiisss", $student_id, $semester_id, $section_id, $department_id, $numeric_roll, $formatted_roll, $academic_year);
                        
                        if ($insert_stmt->execute()) {
                            header("Location: manage_student_rolls.php?manual_assigned=1");
                            exit();
                        } else {
                            throw new Exception("Error executing insert: " . $insert_stmt->error);
                        }
                    }
                } else {
                    $error = "Invalid roll number! Numeric part must be greater than 0.";
                }
            } else {
                $error = "Invalid roll number format! Use format like IT-01, CE-02, CSEA-15";
            }
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Get students with their roll numbers
try {
    $students_query = "SELECT 
        s.student_id,
        s.admission_number,
        s.full_name,
        srn.roll_id,
        srn.roll_number,
        srn.roll_number_display,
        srn.assigned_date
    FROM students s
    JOIN student_semesters ss ON s.student_id = ss.student_id
    LEFT JOIN student_roll_numbers srn ON s.student_id = srn.student_id 
        AND srn.semester_id = ss.semester_id 
        AND srn.section_id = ss.section_id
        AND srn.academic_year = '{$class_teacher['academic_year']}'
    WHERE s.department_id = {$class_teacher['department_id']}
    AND ss.semester_id = {$class_teacher['semester_id']}
    AND ss.section_id = {$class_teacher['section_id']}
    AND ss.is_active = 1
    ORDER BY srn.roll_number IS NULL, srn.roll_number, s.full_name";

    $students = $conn->query($students_query);
    
    if (!$students) {
        throw new Exception("Query failed: " . $conn->error);
    }
    
    // Count statistics
    $total_students = 0;
    $assigned_rolls = 0;
    $students->data_seek(0);
    while ($row = $students->fetch_assoc()) {
        $total_students++;
        if ($row['roll_number']) {
            $assigned_rolls++;
        }
    }
    $students->data_seek(0);
} catch (Exception $e) {
    die("Database error: " . $e->getMessage() . "<br><br>Please make sure you have imported the student_roll_numbers_complete.sql file first!");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Student Roll Numbers - College ERP</title>
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
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Outfit', sans-serif;
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
            background: linear-gradient(180deg, var(--dark) 0%, #292524 100%);
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

        .teacher-profile {
            text-align: center;
        }

        .teacher-avatar {
            width: 75px;
            height: 75px;
            border-radius: 15px;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 700;
            margin: 0 auto 15px;
            border: 3px solid rgba(255,255,255,0.3);
        }

        .teacher-name {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .teacher-role {
            font-size: 0.9rem;
            opacity: 0.9;
            font-weight: 500;
        }

        .class-info {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(255,255,255,0.2);
        }

        .class-info-item {
            font-size: 0.85rem;
            opacity: 0.9;
            margin-bottom: 5px;
        }

        .sidebar-menu {
            padding: 25px 0;
        }

        .menu-item {
            padding: 16px 25px;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            display: flex;
            align-items: center;
            transition: all 0.3s;
            cursor: pointer;
            position: relative;
        }

        .menu-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 4px;
            background: var(--primary);
            transform: scaleY(0);
            transition: transform 0.3s;
        }

        .menu-item:hover::before, .menu-item.active::before {
            transform: scaleY(1);
        }

        .menu-item:hover, .menu-item.active {
            background: rgba(249, 115, 22, 0.1);
            color: var(--white);
        }

        .menu-item i {
            margin-right: 15px;
            width: 22px;
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
            padding: 25px 35px;
            border-radius: 20px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .top-bar-left h1 {
            font-size: 2.2rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 5px;
        }

        .top-bar-left p {
            color: var(--gray);
            font-size: 0.95rem;
        }

        .btn {
            padding: 12px 28px;
            border-radius: 15px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            transition: all 0.3s;
            font-size: 0.95rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            box-shadow: 0 4px 15px rgba(249, 115, 22, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 25px rgba(249, 115, 22, 0.4);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #dc2626);
            color: var(--white);
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #16a34a);
            color: var(--white);
            box-shadow: 0 4px 15px rgba(34, 197, 94, 0.3);
        }

        .btn-small {
            padding: 8px 16px;
            font-size: 0.85rem;
        }

        /* Alert Messages */
        .alert {
            padding: 18px 24px;
            border-radius: 15px;
            margin-bottom: 25px;
            font-weight: 500;
            display: flex;
            align-items: center;
            animation: slideDown 0.3s ease;
        }

        .alert i {
            margin-right: 12px;
            font-size: 1.2rem;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.15);
            color: var(--success);
            border-left: 4px solid var(--success);
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.15);
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--white);
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            opacity: 0.1;
            border-radius: 0 20px 0 100%;
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
        }

        .stat-icon.orange { background: rgba(249, 115, 22, 0.15); color: var(--primary); }
        .stat-icon.green { background: rgba(34, 197, 94, 0.15); color: var(--success); }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--dark);
            position: relative;
            z-index: 1;
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.95rem;
            font-weight: 500;
            margin-top: 5px;
        }

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

        .card-header h3 {
            font-size: 1.4rem;
            font-weight: 700;
            display: flex;
            align-items: center;
        }

        .card-header h3 i {
            color: var(--primary);
            margin-right: 12px;
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
        }

        .table td {
            padding: 18px 12px;
            border-bottom: 1px solid var(--light-gray);
        }

        .table tr:hover {
            background: rgba(249, 115, 22, 0.05);
        }

        .badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge.success { background: rgba(34, 197, 94, 0.15); color: var(--success); }
        .badge.danger { background: rgba(239, 68, 68, 0.15); color: var(--danger); }
        .badge.warning { background: rgba(234, 179, 8, 0.15); color: var(--warning); }

        .roll-input {
            width: 80px;
            padding: 8px 12px;
            border: 2px solid var(--light-gray);
            border-radius: 10px;
            font-family: 'Outfit', sans-serif;
            font-weight: 600;
            text-align: center;
            transition: all 0.3s;
        }

        .roll-input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .action-buttons {
            display: flex;
            gap: 10px;
        }

        .icon-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px;
            border-radius: 8px;
            transition: all 0.3s;
            font-size: 1rem;
        }

        .icon-btn:hover {
            background: var(--light-gray);
        }

        .icon-btn.edit { color: var(--primary); }
        .icon-btn.delete { color: var(--danger); }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            animation: fadeIn 0.3s;
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: var(--white);
            padding: 35px;
            border-radius: 20px;
            max-width: 500px;
            width: 90%;
            animation: slideUp 0.3s;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-header {
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark);
            margin: 0;
        }

        .modal-body {
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
        }

        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--light-gray);
            border-radius: 10px;
            font-family: 'Outfit', sans-serif;
            font-size: 1rem;
            transition: all 0.3s;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .modal-footer {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="teacher-profile">
                    <div class="teacher-avatar"><?php echo strtoupper(substr($teacher['full_name'], 0, 1)); ?></div>
                    <div class="teacher-name"><?php echo $teacher['full_name']; ?></div>
                    <div class="teacher-role">Class Teacher</div>
                    <div class="class-info">
                        <div class="class-info-item">
                            <i class="fas fa-building"></i> <?php echo $class_teacher['department_name']; ?>
                        </div>
                        <div class="class-info-item">
                            <i class="fas fa-layer-group"></i> <?php echo $class_teacher['semester_name']; ?>
                        </div>
                        <div class="class-info-item">
                            <i class="fas fa-users"></i> Section <?php echo $class_teacher['section_name']; ?>
                        </div>
                    </div>
                </div>
            </div>
            <nav class="sidebar-menu">
                <a href="class_teacher_dashboard.php" class="menu-item">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="class_students.php" class="menu-item">
                    <i class="fas fa-user-graduate"></i> My Students
                </a>
                <a href="manage_student_rolls.php" class="menu-item active">
                    <i class="fas fa-list-ol"></i> Roll Numbers
                </a>
                <a href="class_attendance.php" class="menu-item">
                    <i class="fas fa-calendar-check"></i> Attendance
                </a>
                <a href="class_reports.php" class="menu-item">
                    <i class="fas fa-chart-line"></i> Reports
                </a>
                <a href="teacher_profile.php" class="menu-item">
                    <i class="fas fa-user"></i> Profile
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-bar">
                <div class="top-bar-left">
                    <h1>Manage Roll Numbers</h1>
                    <p><?php echo $class_teacher['semester_name']; ?> - Section <?php echo $class_teacher['section_name']; ?> | Academic Year <?php echo $class_teacher['academic_year']; ?></p>
                </div>
                <a href="../logout.php"><button class="btn btn-danger"><i class="fas fa-sign-out-alt"></i> Logout</button></a>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $total_students; ?></div>
                            <div class="stat-label">Total Students</div>
                        </div>
                        <div class="stat-icon orange">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $assigned_rolls; ?></div>
                            <div class="stat-label">Rolls Assigned</div>
                        </div>
                        <div class="stat-icon green">
                            <i class="fas fa-list-ol"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Roll Numbers Table -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-list-ol"></i> Student Roll Numbers</h3>
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="assign_rolls" class="btn btn-success" 
                                onclick="return confirm('This will assign roll numbers to all students who don\'t have one. Continue?')">
                            <i class="fas fa-plus-circle"></i> Auto-Assign Roll Numbers
                        </button>
                    </form>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Roll No.</th>
                            <th>Admission No.</th>
                            <th>Student Name</th>
                            <th>Assigned Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($student = $students->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <?php if ($student['roll_number']): ?>
                                    <strong style="font-size: 1.2rem; color: var(--primary);"><?php echo $student['roll_number_display']; ?></strong>
                                <?php else: ?>
                                    <span class="badge warning">Not Assigned</span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo $student['admission_number']; ?></strong></td>
                            <td><?php echo $student['full_name']; ?></td>
                            <td><?php echo $student['assigned_date'] ? date('M d, Y', strtotime($student['assigned_date'])) : '-'; ?></td>
                            <td>
                                <?php if ($student['roll_number']): ?>
                                    <span class="badge success">Assigned</span>
                                <?php else: ?>
                                    <span class="badge danger">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <?php if ($student['roll_number']): ?>
                                        <button class="icon-btn edit" onclick="openEditModal(<?php echo $student['roll_id']; ?>, <?php echo $student['roll_number']; ?>, '<?php echo addslashes($student['full_name']); ?>', '<?php echo $student['roll_number_display']; ?>')" title="Edit Roll Number">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this roll number?')">
                                            <input type="hidden" name="roll_id" value="<?php echo $student['roll_id']; ?>">
                                            <button type="submit" name="delete_roll" class="icon-btn delete" title="Delete Roll Number">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button class="btn btn-small btn-success" onclick="openAssignModal(<?php echo $student['student_id']; ?>, '<?php echo addslashes($student['full_name']); ?>')">
                                            <i class="fas fa-plus"></i> Assign
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal" onclick="if(event.target === this) closeEditModal()">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Roll Number</h3>
                <button type="button" onclick="closeEditModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--gray);">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" id="editRollForm">
                <div class="modal-body">
                    <input type="hidden" name="roll_id" id="edit_roll_id">
                    <div class="form-group">
                        <label>Student Name</label>
                        <input type="text" id="edit_student_name" disabled style="background: var(--light-gray);">
                    </div>
                    <div class="form-group">
                        <label>Current Roll Number</label>
                        <input type="text" id="current_roll_number" disabled style="background: var(--light-gray);">
                    </div>
                    <div class="form-group">
                        <label>New Roll Number * (Format: SECTION-NUMBER)</label>
                        <input type="text" name="new_roll_number" id="edit_roll_number" required autofocus placeholder="IT-01, IT-02, CE-01, etc." style="text-transform: uppercase;">
                        <small style="color: var(--gray); margin-top: 5px; display: block;">
                            <i class="fas fa-info-circle"></i> Examples: <strong>IT-01</strong>, <strong>CE-15</strong>, <strong>CSEA-23</strong>
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" onclick="closeEditModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="update_roll" class="btn btn-success">
                        <i class="fas fa-check"></i> Update
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Manual Assign Modal -->
    <div id="assignModal" class="modal" onclick="if(event.target === this) closeAssignModal()">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Assign Roll Number</h3>
                <button type="button" onclick="closeAssignModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--gray);">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" id="assignRollForm">
                <div class="modal-body">
                    <input type="hidden" name="student_id" id="assign_student_id">
                    <div class="form-group">
                        <label>Student Name</label>
                        <input type="text" id="assign_student_name" disabled style="background: var(--light-gray);">
                    </div>
                    <div class="form-group">
                        <label>Roll Number * (Format: SECTION-NUMBER)</label>
                        <input type="text" name="manual_roll_number" id="manual_roll_number" required autofocus placeholder="IT-01, IT-02, CE-01, etc." style="text-transform: uppercase;">
                        <small style="color: var(--gray); margin-top: 5px; display: block;">
                            <i class="fas fa-info-circle"></i> Examples: <strong>IT-01</strong>, <strong>CE-15</strong>, <strong>CSEA-23</strong>
                        </small>
                    </div>
                    <div style="background: rgba(249, 115, 22, 0.1); padding: 12px; border-radius: 10px; margin-top: 15px;">
                        <small style="color: var(--primary);">
                            <i class="fas fa-info-circle"></i> Make sure this roll number is not already assigned to another student.
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" onclick="closeAssignModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="manual_assign" class="btn btn-success">
                        <i class="fas fa-check"></i> Assign
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openEditModal(rollId, rollNumber, studentName, rollNumberDisplay) {
            document.getElementById('edit_roll_id').value = rollId;
            document.getElementById('edit_roll_number').value = '';
            document.getElementById('current_roll_number').value = rollNumberDisplay;
            document.getElementById('edit_student_name').value = studentName;
            document.getElementById('editModal').classList.add('show');
            
            // Focus on the input after a short delay
            setTimeout(() => {
                document.getElementById('edit_roll_number').focus();
            }, 100);
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('show');
            document.getElementById('editRollForm').reset();
        }

        function openAssignModal(studentId, studentName) {
            document.getElementById('assign_student_id').value = studentId;
            document.getElementById('assign_student_name').value = studentName;
            document.getElementById('assignModal').classList.add('show');
            
            // Focus on the input after a short delay
            setTimeout(() => {
                document.getElementById('manual_roll_number').focus();
            }, 100);
        }

        function closeAssignModal() {
            document.getElementById('assignModal').classList.remove('show');
            document.getElementById('assignRollForm').reset();
        }

        // Close modal when pressing Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeEditModal();
                closeAssignModal();
            }
        });

        // Auto-hide success messages after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(function() {
                    alert.style.display = 'none';
                }, 500);
            });
        }, 5000);
    </script>
</body>
</html>
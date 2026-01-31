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

// Get available academic years first to determine default
$academic_years_sql_temp = "SELECT DISTINCT academic_year 
                            FROM subject_teachers 
                            WHERE teacher_id = ?
                            ORDER BY academic_year DESC";
$stmt_temp = $conn->prepare($academic_years_sql_temp);
$stmt_temp->bind_param("i", $teacher['teacher_id']);
$stmt_temp->execute();
$academic_years_temp = $stmt_temp->get_result();

// Get the most recent academic year as default
$default_year = null;
if ($academic_years_temp->num_rows > 0) {
    $first_year = $academic_years_temp->fetch_assoc();
    $default_year = $first_year['academic_year'];
}

// Get selected academic year (use most recent if not specified)
$selected_academic_year = $_GET['academic_year'] ?? $_POST['academic_year'] ?? $default_year ?? date('Y') . '-' . (date('Y') + 1);

// Handle attendance submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_attendance'])) {
    $subject_id = intval($_POST['subject_id']);
    $semester_id = intval($_POST['semester_id']);
    $section_id = !empty($_POST['section_id']) ? intval($_POST['section_id']) : null;
    $attendance_date = $_POST['attendance_date'];
    $attendance_data = $_POST['attendance'] ?? [];

    $success = true;
    $conn->begin_transaction();
    
    try {
        foreach ($attendance_data as $student_id => $status) {
            $student_id = intval($student_id);
            
            // Check if attendance already exists
            $check_sql = "SELECT attendance_id FROM attendance 
                          WHERE student_id = ? AND subject_id = ? AND attendance_date = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("iis", $student_id, $subject_id, $attendance_date);
            $check_stmt->execute();
            $exists = $check_stmt->get_result()->fetch_assoc();

            if ($exists) {
                // Update existing attendance
                $update_sql = "UPDATE attendance SET status = ?, marked_by = ? 
                              WHERE attendance_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("sii", $status, $teacher['teacher_id'], $exists['attendance_id']);
                $update_stmt->execute();
            } else {
                // Insert new attendance
                if ($section_id) {
                    $insert_sql = "INSERT INTO attendance (student_id, subject_id, semester_id, department_id, section_id, attendance_date, status, marked_by) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $insert_stmt = $conn->prepare($insert_sql);
                    $insert_stmt->bind_param("iiiisssi", $student_id, $subject_id, $semester_id, $teacher['department_id'], $section_id, $attendance_date, $status, $teacher['teacher_id']);
                } else {
                    $insert_sql = "INSERT INTO attendance (student_id, subject_id, semester_id, department_id, attendance_date, status, marked_by) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?)";
                    $insert_stmt = $conn->prepare($insert_sql);
                    $insert_stmt->bind_param("iiiissi", $student_id, $subject_id, $semester_id, $teacher['department_id'], $attendance_date, $status, $teacher['teacher_id']);
                }
                $insert_stmt->execute();
            }
        }
        
        $conn->commit();
        
        // Redirect to success page with proper parameters
        $redirect_url = "attendance_success.php?subject_id=" . urlencode($subject_id) . 
                       "&semester_id=" . urlencode($semester_id) . 
                       "&date=" . urlencode($attendance_date) .
                       "&academic_year=" . urlencode($selected_academic_year);
        
        if ($section_id) {
            $redirect_url .= "&section_id=" . urlencode($section_id);
        }
        
        header("Location: " . $redirect_url);
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Error marking attendance: " . $e->getMessage();
    }
}

// Get available academic years from subject_teachers table
$academic_years_sql = "SELECT DISTINCT academic_year 
                       FROM subject_teachers 
                       WHERE teacher_id = ?
                       ORDER BY academic_year DESC";
$stmt = $conn->prepare($academic_years_sql);
$stmt->bind_param("i", $teacher['teacher_id']);
$stmt->execute();
$academic_years_result = $stmt->get_result();

// Get assigned subjects for dropdown (filtered by academic year)
$subjects_sql = "
    SELECT DISTINCT st.subject_id, sub.subject_name, sub.subject_code, 
           st.semester_id, sem.semester_name, st.section_id, sec.section_name, st.academic_year
    FROM subject_teachers st
    JOIN subjects sub ON st.subject_id = sub.subject_id
    JOIN semesters sem ON st.semester_id = sem.semester_id
    LEFT JOIN sections sec ON st.section_id = sec.section_id
    WHERE st.teacher_id = ? AND st.academic_year = ?
    ORDER BY semester_name, subject_name";

$stmt = $conn->prepare($subjects_sql);
$stmt->bind_param("is", $teacher['teacher_id'], $selected_academic_year);
$stmt->execute();
$assigned_subjects = $stmt->get_result();

// Get students for selected class
$students = [];
$selected_subject_info = null;
if (isset($_GET['subject_id']) && isset($_GET['semester_id'])) {
    $subject_id = intval($_GET['subject_id']);
    $semester_id = intval($_GET['semester_id']);
    $section_id = isset($_GET['section_id']) && $_GET['section_id'] !== '' ? intval($_GET['section_id']) : null;
    $attendance_date = $_GET['date'] ?? date('Y-m-d');

    // Get subject info
    $subject_info_sql = "SELECT sub.subject_name, sub.subject_code, sem.semester_name, sec.section_name
                         FROM subjects sub
                         JOIN semesters sem ON sub.semester_id = sem.semester_id
                         LEFT JOIN sections sec ON sec.section_id = ?
                         WHERE sub.subject_id = ?";
    $subject_info_stmt = $conn->prepare($subject_info_sql);
    $subject_info_stmt->bind_param("ii", $section_id, $subject_id);
    $subject_info_stmt->execute();
    $selected_subject_info = $subject_info_stmt->get_result()->fetch_assoc();

    // Get students enrolled in this subject with roll numbers
    $students_sql = "SELECT DISTINCT s.student_id, s.admission_number, s.full_name,
                     srn.roll_number_display,
                     (SELECT status FROM attendance 
                      WHERE student_id = s.student_id 
                      AND subject_id = ? 
                      AND attendance_date = ? 
                      LIMIT 1) as current_status
                     FROM students s
                     JOIN student_semesters ss ON s.student_id = ss.student_id
                     LEFT JOIN student_roll_numbers srn ON s.student_id = srn.student_id 
                         AND srn.semester_id = ss.semester_id 
                         AND srn.section_id = ss.section_id
                         AND srn.academic_year = ss.academic_year
                     WHERE ss.semester_id = ?
                     AND ss.is_active = 1";
    
    if ($section_id) {
        $students_sql .= " AND ss.section_id = ?";
    }
    
    $students_sql .= " ORDER BY srn.roll_number, s.full_name";
    
    $students_stmt = $conn->prepare($students_sql);
    if ($section_id) {
        $students_stmt->bind_param("isii", $subject_id, $attendance_date, $semester_id, $section_id);
    } else {
        $students_stmt->bind_param("isi", $subject_id, $attendance_date, $semester_id);
    }
    $students_stmt->execute();
    $students = $students_stmt->get_result();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mark Attendance - College ERP</title>
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
            --primary-light: #818cf8;
            --secondary: #8b5cf6;
            --accent: #ec4899;
            --success: #22c55e;
            --success-dark: #16a34a;
            --danger: #ef4444;
            --danger-dark: #dc2626;
            --warning: #f59e0b;
            --dark: #0f172a;
            --gray: #64748b;
            --gray-light: #cbd5e1;
            --light: #f8fafc;
            --white: #ffffff;
            --border: #e2e8f0;
            --shadow: rgba(0, 0, 0, 0.1);
            --shadow-lg: rgba(0, 0, 0, 0.15);
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: var(--dark);
            padding: 1rem;
        }

        .main-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Glassmorphism Header */
        .page-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
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
            gap: 1rem;
        }

        .header-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            color: var(--white);
            box-shadow: 0 8px 20px rgba(99, 102, 241, 0.4);
        }

        .header-text h1 {
            font-size: 1.75rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.25rem;
        }

        .header-text p {
            color: var(--gray);
            font-size: 0.9rem;
            font-weight: 500;
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

        /* Alert */
        .alert {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 16px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }

        .alert-error {
            border-left: 4px solid var(--danger);
        }

        .alert-error i {
            color: var(--danger);
            font-size: 1.5rem;
        }

        .alert-text {
            flex: 1;
            font-weight: 600;
            color: var(--dark);
        }

        /* Filter Card */
        .filter-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15);
        }

        .filter-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .filter-header i {
            font-size: 1.5rem;
            color: var(--primary);
        }

        .filter-header h2 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--dark);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.25rem;
        }

        .form-field {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-field label {
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-field label i {
            color: var(--primary);
        }

        .form-input {
            padding: 0.875rem 1rem;
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 0.95rem;
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            transition: all 0.3s ease;
            background: var(--white);
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }

        select.form-input {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%236366f1' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.875rem center;
            background-size: 1.25rem;
            padding-right: 3rem;
        }

        /* Stats Cards */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 1.5rem;
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
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
        }

        .stat-card.total::before {
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }

        .stat-card.present::before {
            background: linear-gradient(90deg, var(--success), var(--success-dark));
        }

        .stat-card.absent::before {
            background: linear-gradient(90deg, var(--danger), var(--danger-dark));
        }

        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            margin-bottom: 1rem;
        }

        .stat-card.total .stat-icon {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.15), rgba(139, 92, 246, 0.15));
            color: var(--primary);
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
            font-size: 2.25rem;
            font-weight: 900;
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .stat-card.total .stat-value {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
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
            font-size: 1.125rem;
            font-weight: 700;
            margin-top: 0.5rem;
        }

        .stat-card.present .stat-percentage {
            color: var(--success);
        }

        .stat-card.absent .stat-percentage {
            color: var(--danger);
        }

        /* Attendance Main Card */
        .attendance-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15);
            overflow: hidden;
        }

        .attendance-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            padding: 1.75rem 2rem;
            color: var(--white);
        }

        .attendance-header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .attendance-title {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .attendance-title h2 {
            font-size: 1.5rem;
            font-weight: 800;
        }

        .subject-badge {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            padding: 0.5rem 1.25rem;
            border-radius: 50px;
            font-weight: 700;
            font-size: 0.875rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 0.75rem 1.25rem;
            border-radius: 12px;
            border: none;
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-family: 'Poppins', sans-serif;
        }

        .action-btn-present {
            background: var(--success);
            color: var(--white);
            box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3);
        }

        .action-btn-present:hover {
            background: var(--success-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(34, 197, 94, 0.4);
        }

        .action-btn-absent {
            background: var(--danger);
            color: var(--white);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }

        .action-btn-absent:hover {
            background: var(--danger-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
        }

        /* Students List */
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
            gap: 1.25rem;
            transition: all 0.3s ease;
            position: relative;
        }

        .student-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 5px;
            background: linear-gradient(180deg, var(--primary), var(--secondary));
            border-radius: 16px 0 0 16px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .student-item:hover {
            border-color: var(--primary);
            box-shadow: 0 8px 24px rgba(99, 102, 241, 0.15);
            transform: translateX(4px);
        }

        .student-item:hover::before {
            opacity: 1;
        }

        .roll-badge {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 1.125rem;
            color: var(--white);
            box-shadow: 0 6px 18px rgba(99, 102, 241, 0.35);
            flex-shrink: 0;
        }

        .student-details {
            flex: 1;
            min-width: 0;
        }

        .student-name {
            font-weight: 700;
            font-size: 1.05rem;
            color: var(--dark);
            margin-bottom: 0.25rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .student-id {
            font-size: 0.8rem;
            color: var(--gray);
            font-weight: 500;
        }

        /* Toggle Buttons */
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
            transition: all 0.3s ease;
        }

        .toggle-present {
            color: var(--gray-light);
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
            color: var(--gray-light);
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

        /* Submit Button */
        .submit-section {
            padding: 1.5rem 2rem;
            background: var(--light);
            border-top: 2px solid var(--border);
        }

        .submit-btn {
            width: 100%;
            padding: 1.125rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            border: none;
            border-radius: 14px;
            font-weight: 800;
            font-size: 1.05rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            font-family: 'Poppins', sans-serif;
            box-shadow: 0 8px 24px rgba(99, 102, 241, 0.35);
        }

        .submit-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 32px rgba(99, 102, 241, 0.45);
        }

        .submit-btn i {
            font-size: 1.25rem;
        }

        /* Scrollbar */
        .students-list::-webkit-scrollbar {
            width: 10px;
        }

        .students-list::-webkit-scrollbar-track {
            background: var(--light);
            border-radius: 10px;
        }

        .students-list::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--primary), var(--secondary));
            border-radius: 10px;
        }

        .students-list::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, var(--primary-dark), var(--secondary));
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
        }

        .empty-icon {
            font-size: 5rem;
            color: var(--gray-light);
            margin-bottom: 1.5rem;
            opacity: 0.5;
        }

        .empty-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.75rem;
        }

        .empty-text {
            color: var(--gray);
            font-size: 1rem;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            body {
                padding: 0.75rem;
            }

            .page-header {
                padding: 1.25rem;
            }

            .header-icon {
                width: 50px;
                height: 50px;
                font-size: 1.5rem;
            }

            .header-text h1 {
                font-size: 1.35rem;
            }

            .header-content {
                flex-direction: column;
                align-items: flex-start;
            }

            .back-button {
                width: 100%;
                justify-content: center;
            }

            .filter-card {
                padding: 1.5rem;
            }

            .filter-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .stats-container {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .stat-card {
                padding: 1.25rem;
            }

            .stat-value {
                font-size: 2rem;
            }

            .attendance-header {
                padding: 1.5rem;
            }

            .attendance-header-content {
                flex-direction: column;
                align-items: flex-start;
            }

            .action-buttons {
                width: 100%;
                flex-direction: column;
            }

            .action-btn {
                width: 100%;
                justify-content: center;
            }

            .students-list {
                padding: 1rem;
                max-height: 50vh;
            }

            .student-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .student-details {
                width: 100%;
            }

            .student-name {
                white-space: normal;
            }

            .attendance-toggle {
                width: 100%;
                justify-content: center;
            }

            .toggle-btn {
                flex: 1;
                max-width: 100px;
            }

            .submit-section {
                padding: 1.25rem;
            }
        }

        @media (max-width: 480px) {
            .header-text h1 {
                font-size: 1.2rem;
            }

            .roll-badge {
                width: 50px;
                height: 50px;
                font-size: 1rem;
            }

            .toggle-btn {
                width: 65px;
                height: 65px;
            }

            .toggle-btn i {
                font-size: 1.5rem;
            }

            .stat-icon {
                width: 48px;
                height: 48px;
                font-size: 1.5rem;
            }

            .stat-value {
                font-size: 1.75rem;
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
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <div class="header-text">
                        <h1>Mark Attendance</h1>
                        <p><?php echo htmlspecialchars($selected_academic_year); ?></p>
                    </div>
                </div>
                <a href="index.php" class="back-button">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Dashboard</span>
                </a>
            </div>
        </div>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span class="alert-text"><?php echo htmlspecialchars($error_message); ?></span>
            </div>
        <?php endif; ?>

        <!-- Filter Card -->
        <div class="filter-card">
            <div class="filter-header">
                <i class="fas fa-sliders-h"></i>
                <h2>Select Class & Date</h2>
            </div>
            <form method="GET" action="" id="filterForm">
                <div class="filter-grid">
                    <div class="form-field">
                        <label>
                            <i class="fas fa-calendar-alt"></i>
                            Academic Year
                        </label>
                        <select name="academic_year" id="academic_year" class="form-input" required>
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
                    <div class="form-field">
                        <label>
                            <i class="fas fa-book"></i>
                            Subject & Class
                        </label>
                        <select name="subject_select" id="subject_select" class="form-input" required>
                            <option value="">-- Select Subject --</option>
                            <?php 
                            $assigned_subjects->data_seek(0);
                            while($subject = $assigned_subjects->fetch_assoc()): 
                                $option_value = $subject['subject_id'] . '|' . $subject['semester_id'] . '|' . ($subject['section_id'] ?? '');
                                $is_selected = (isset($_GET['subject_id']) && 
                                               $_GET['subject_id'] == $subject['subject_id'] && 
                                               $_GET['semester_id'] == $subject['semester_id'] &&
                                               ($_GET['section_id'] ?? '') == ($subject['section_id'] ?? '')) ? 'selected' : '';
                            ?>
                                <option value="<?php echo htmlspecialchars($option_value); ?>" <?php echo $is_selected; ?>>
                                    <?php echo htmlspecialchars($subject['subject_code'] . ' - ' . $subject['subject_name'] . ' (' . $subject['semester_name'] . ($subject['section_name'] ? ' - ' . $subject['section_name'] : '') . ')'); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-field">
                        <label>
                            <i class="fas fa-calendar-day"></i>
                            Date
                        </label>
                        <input type="date" name="date" id="attendance_date" class="form-input" 
                               value="<?php echo htmlspecialchars($_GET['date'] ?? date('Y-m-d')); ?>" 
                               max="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
                <input type="hidden" name="subject_id" id="subject_id" value="<?php echo $_GET['subject_id'] ?? ''; ?>">
                <input type="hidden" name="semester_id" id="semester_id" value="<?php echo $_GET['semester_id'] ?? ''; ?>">
                <input type="hidden" name="section_id" id="section_id" value="<?php echo $_GET['section_id'] ?? ''; ?>">
            </form>
        </div>

        <?php if (isset($_GET['subject_id']) && $students && $students->num_rows > 0): ?>
            <?php
            // Calculate statistics
            $total_students = $students->num_rows;
            $present_count = 0;
            $absent_count = 0;
            $students->data_seek(0);
            while($stat_student = $students->fetch_assoc()) {
                if ($stat_student['current_status'] == 'present' || !$stat_student['current_status']) {
                    $present_count++;
                } elseif ($stat_student['current_status'] == 'absent') {
                    $absent_count++;
                }
            }
            $present_percentage = $total_students > 0 ? round(($present_count / $total_students) * 100, 1) : 0;
            $absent_percentage = $total_students > 0 ? round(($absent_count / $total_students) * 100, 1) : 0;
            ?>

            <!-- Statistics Cards -->
            <div class="stats-container">
                <div class="stat-card total">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-value" id="totalCount"><?php echo $total_students; ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
                <div class="stat-card present">
                    <div class="stat-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-value" id="presentCount"><?php echo $present_count; ?></div>
                    <div class="stat-label">Present</div>
                    <div class="stat-percentage" id="presentPercentage"><?php echo $present_percentage; ?>%</div>
                </div>
                <div class="stat-card absent">
                    <div class="stat-icon">
                        <i class="fas fa-user-times"></i>
                    </div>
                    <div class="stat-value" id="absentCount"><?php echo $absent_count; ?></div>
                    <div class="stat-label">Absent</div>
                    <div class="stat-percentage" id="absentPercentage"><?php echo $absent_percentage; ?>%</div>
                </div>
            </div>

            <!-- Attendance Card -->
            <div class="attendance-card">
                <div class="attendance-header">
                    <div class="attendance-header-content">
                        <div class="attendance-title">
                            <h2>Mark Student Attendance</h2>
                            <?php if($selected_subject_info): ?>
                                <span class="subject-badge">
                                    <?php echo htmlspecialchars($selected_subject_info['subject_code']); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="action-buttons">
                            <button type="button" class="action-btn action-btn-present" onclick="markAllPresent()">
                                <i class="fas fa-check-double"></i>
                                All Present
                            </button>
                            <button type="button" class="action-btn action-btn-absent" onclick="markAllAbsent()">
                                <i class="fas fa-times"></i>
                                All Absent
                            </button>
                        </div>
                    </div>
                </div>

                <form method="POST" action="" id="attendanceForm">
                    <input type="hidden" name="subject_id" value="<?php echo htmlspecialchars($_GET['subject_id']); ?>">
                    <input type="hidden" name="semester_id" value="<?php echo htmlspecialchars($_GET['semester_id']); ?>">
                    <input type="hidden" name="section_id" value="<?php echo htmlspecialchars($_GET['section_id'] ?? ''); ?>">
                    <input type="hidden" name="attendance_date" value="<?php echo htmlspecialchars($_GET['date'] ?? date('Y-m-d')); ?>">
                    <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($selected_academic_year); ?>">

                    <div class="students-list">
                        <?php 
                        $students->data_seek(0);
                        while($student = $students->fetch_assoc()): 
                        ?>
                            <div class="student-item">
                                <div class="roll-badge">
                                    <?php echo $student['roll_number_display'] ? htmlspecialchars($student['roll_number_display']) : '-'; ?>
                                </div>
                                <div class="student-details">
                                    <div class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></div>
                                    <div class="student-id">ID: <?php echo htmlspecialchars($student['admission_number']); ?></div>
                                </div>
                                <div class="attendance-toggle">
                                    <label class="toggle-btn toggle-present <?php echo ($student['current_status'] == 'present' || !$student['current_status']) ? 'active' : ''; ?>">
                                        <input type="radio" 
                                               name="attendance[<?php echo $student['student_id']; ?>]" 
                                               value="present"
                                               <?php echo ($student['current_status'] == 'present' || !$student['current_status']) ? 'checked' : ''; ?>
                                               onchange="updateAttendance(this)">
                                        <i class="fas fa-check"></i>
                                        <span>Present</span>
                                    </label>
                                    <label class="toggle-btn toggle-absent <?php echo ($student['current_status'] == 'absent') ? 'active' : ''; ?>">
                                        <input type="radio" 
                                               name="attendance[<?php echo $student['student_id']; ?>]" 
                                               value="absent"
                                               <?php echo ($student['current_status'] == 'absent') ? 'checked' : ''; ?>
                                               onchange="updateAttendance(this)">
                                        <i class="fas fa-times"></i>
                                        <span>Absent</span>
                                    </label>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>

                    <div class="submit-section">
                        <button type="submit" name="submit_attendance" class="submit-btn">
                            <i class="fas fa-save"></i>
                            <span>Save Attendance</span>
                        </button>
                    </div>
                </form>
            </div>
        <?php elseif (isset($_GET['subject_id'])): ?>
            <div class="attendance-card">
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-users-slash"></i>
                    </div>
                    <h3 class="empty-title">No Students Found</h3>
                    <p class="empty-text">There are no students enrolled in this class.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="attendance-card">
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-hand-pointer"></i>
                    </div>
                    <h3 class="empty-title">Select a Class</h3>
                    <p class="empty-text">Please select an academic year, subject, and date above to begin marking attendance.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Update button states and statistics
        function updateAttendance(radio) {
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
            
            const presentPct = total > 0 ? ((present / total) * 100).toFixed(1) : 0;
            const absentPct = total > 0 ? ((absent / total) * 100).toFixed(1) : 0;
            
            document.getElementById('totalCount').textContent = total;
            document.getElementById('presentCount').textContent = present;
            document.getElementById('absentCount').textContent = absent;
            document.getElementById('presentPercentage').textContent = presentPct + '%';
            document.getElementById('absentPercentage').textContent = absentPct + '%';
        }

        function markAllPresent() {
            document.querySelectorAll('input[value="present"]').forEach(radio => {
                radio.checked = true;
                updateAttendance(radio);
            });
        }

        function markAllAbsent() {
            document.querySelectorAll('input[value="absent"]').forEach(radio => {
                radio.checked = true;
                updateAttendance(radio);
            });
        }

        // Form handlers
        document.getElementById('academic_year').addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });

        document.getElementById('subject_select').addEventListener('change', function() {
            if (this.value) {
                const parts = this.value.split('|');
                document.getElementById('subject_id').value = parts[0];
                document.getElementById('semester_id').value = parts[1];
                document.getElementById('section_id').value = parts[2] || '';
                document.getElementById('filterForm').submit();
            }
        });

        document.getElementById('attendance_date').addEventListener('change', function() {
            if (document.getElementById('subject_select').value) {
                document.getElementById('filterForm').submit();
            }
        });
    </script>
</body>
</html>
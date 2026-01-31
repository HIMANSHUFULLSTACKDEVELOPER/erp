<?php
require_once '../config.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !hasRole('hod')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];

// Get HOD's department
$sql = "SELECT d.department_id 
        FROM teachers t 
        JOIN departments d ON d.hod_id = t.user_id
        WHERE t.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$hod = $result->fetch_assoc();

if (!$hod) {
    echo json_encode(['success' => false, 'message' => 'Department not found']);
    exit;
}

$dept_id = $hod['department_id'];
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;
$semester_id = isset($_GET['semester_id']) ? intval($_GET['semester_id']) : 0;
$section_id = isset($_GET['section_id']) && $_GET['section_id'] !== '' ? intval($_GET['section_id']) : null;
$academic_year = isset($_GET['academic_year']) ? $conn->real_escape_string($_GET['academic_year']) : '';

if (!$subject_id || !$semester_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

// Get students enrolled in this semester and section
if ($section_id) {
    $section_condition = "AND ss.section_id = $section_id";
} else {
    $section_condition = "AND (ss.section_id IS NULL OR ss.section_id = 0)";
}

$query = "SELECT DISTINCT
          s.student_id,
          s.admission_number,
          s.full_name,
          u.email,
          u.phone
          FROM students s
          JOIN users u ON s.user_id = u.user_id
          JOIN student_semesters ss ON s.student_id = ss.student_id
          WHERE s.department_id = $dept_id
          AND ss.semester_id = $semester_id
          $section_condition
          AND ss.is_active = 1
          ORDER BY s.full_name";

$result = $conn->query($query);

// Debug: If no results and academic_year is provided, try with academic year filter
if ($result->num_rows == 0 && !empty($academic_year)) {
    $query = "SELECT DISTINCT
              s.student_id,
              s.admission_number,
              s.full_name,
              u.email,
              u.phone
              FROM students s
              JOIN users u ON s.user_id = u.user_id
              JOIN student_semesters ss ON s.student_id = ss.student_id
              WHERE s.department_id = $dept_id
              AND ss.semester_id = $semester_id
              $section_condition
              AND ss.academic_year = '$academic_year'
              AND ss.is_active = 1
              ORDER BY s.full_name";
    
    $result = $conn->query($query);
}

$students = [];
$error_message = '';

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
} else {
    $error_message = $conn->error;
}

echo json_encode([
    'success' => true,
    'students' => $students,
    'count' => count($students),
    'debug' => [
        'subject_id' => $subject_id,
        'semester_id' => $semester_id,
        'section_id' => $section_id,
        'academic_year' => $academic_year,
        'dept_id' => $dept_id,
        'query' => $query,
        'error' => $error_message
    ]
]);
?>
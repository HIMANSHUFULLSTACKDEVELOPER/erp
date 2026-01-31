<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('hod')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$semester_id = $_GET['semester_id'] ?? 0;
$student_id = $_GET['student_id'] ?? 0;
$dept_id = $_GET['dept_id'] ?? 0;

if (!$semester_id || !$student_id || !$dept_id) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

// Get all subjects for this semester and department
$sql = "SELECT s.*, 
        CASE WHEN ss.subject_id IS NOT NULL THEN 1 ELSE 0 END as assigned
        FROM subjects s
        LEFT JOIN student_subjects ss ON s.subject_id = ss.subject_id 
            AND ss.student_id = ? 
            AND ss.semester_id = ?
        WHERE s.semester_id = ? AND s.department_id = ?
        ORDER BY s.subject_code";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iiii", $student_id, $semester_id, $semester_id, $dept_id);
$stmt->execute();
$result = $stmt->get_result();

$subjects = [];
while ($row = $result->fetch_assoc()) {
    $subjects[] = $row;
}

echo json_encode(['success' => true, 'subjects' => $subjects]);
?>
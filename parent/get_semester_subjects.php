<?php
require_once '../config.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !hasRole('parent')) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$student_id = $_GET['student_id'] ?? null;
$semester_id = $_GET['semester_id'] ?? null;

if (!$student_id || !$semester_id) {
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

// Get subjects for the semester
$query = "SELECT 
    sub.subject_id,
    sub.subject_name,
    sub.subject_code,
    sub.credits,
    t.full_name as teacher_name,
    studsub.status,
    studsub.grade
    FROM subjects sub
    LEFT JOIN student_subjects studsub ON sub.subject_id = studsub.subject_id 
        AND studsub.student_id = ? AND studsub.semester_id = ?
    LEFT JOIN subject_teachers st ON sub.subject_id = st.subject_id 
        AND st.semester_id = ?
    LEFT JOIN teachers t ON st.teacher_id = t.teacher_id
    WHERE sub.semester_id = ?
    AND (studsub.student_id IS NOT NULL OR sub.department_id = (SELECT department_id FROM students WHERE student_id = ?))
    ORDER BY sub.subject_code";

$stmt = $conn->prepare($query);
$stmt->bind_param("iiiii", $student_id, $semester_id, $semester_id, $semester_id, $student_id);
$stmt->execute();
$result = $stmt->get_result();

$subjects = [];
while ($row = $result->fetch_assoc()) {
    $subjects[] = $row;
}

echo json_encode(['subjects' => $subjects]);
?>
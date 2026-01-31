<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('hod')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$student_id = $_GET['student_id'] ?? 0;

if (!$student_id) {
    echo json_encode(['success' => false, 'error' => 'Missing student_id']);
    exit;
}

// Get complete semester history for this student
$sql = "SELECT 
            ss.*,
            sem.semester_name,
            sem.semester_number,
            sec.section_name,
            COUNT(DISTINCT studsub.subject_id) as subject_count,
            DATE_FORMAT(ss.created_at, '%M %d, %Y at %h:%i %p') as created_at
        FROM student_semesters ss
        JOIN semesters sem ON ss.semester_id = sem.semester_id
        LEFT JOIN sections sec ON ss.section_id = sec.section_id
        LEFT JOIN student_subjects studsub ON ss.student_id = studsub.student_id 
            AND ss.semester_id = studsub.semester_id
        WHERE ss.student_id = ?
        GROUP BY ss.id
        ORDER BY sem.semester_number ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();

$history = [];
while ($row = $result->fetch_assoc()) {
    $history[] = $row;
}

echo json_encode(['success' => true, 'history' => $history]);
?>
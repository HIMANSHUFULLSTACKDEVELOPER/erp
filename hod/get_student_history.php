<?php
require_once '../config.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !hasRole('hod')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$student_id = $_GET['student_id'] ?? 0;

if (!$student_id) {
    echo json_encode(['success' => false, 'message' => 'Student ID required']);
    exit;
}

// Get all semesters for this student with subject count
$query = "SELECT ss.*, 
          sem.semester_name, 
          sem.semester_number,
          sec.section_name,
          COUNT(DISTINCT sub.subject_id) as subject_count
          FROM student_semesters ss
          JOIN semesters sem ON ss.semester_id = sem.semester_id
          LEFT JOIN sections sec ON ss.section_id = sec.section_id
          LEFT JOIN student_subjects sub ON ss.student_id = sub.student_id AND ss.semester_id = sub.semester_id
          WHERE ss.student_id = ?
          GROUP BY ss.id
          ORDER BY sem.semester_number DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();

$history = [];
while ($row = $result->fetch_assoc()) {
    $history[] = [
        'semester_name' => $row['semester_name'],
        'semester_number' => $row['semester_number'],
        'section_name' => $row['section_name'],
        'academic_year' => $row['academic_year'],
        'is_active' => $row['is_active'],
        'subject_count' => $row['subject_count'],
        'created_at' => date('d M Y', strtotime($row['created_at']))
    ];
}

echo json_encode([
    'success' => true,
    'history' => $history
]);
?>
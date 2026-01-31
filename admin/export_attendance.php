<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('admin')) {
    redirect('login.php');
}

// Get filter parameters
$department_filter = isset($_GET['department']) ? $_GET['department'] : '';
$semester_filter = isset($_GET['semester']) ? $_GET['semester'] : '';
$section_filter = isset($_GET['section']) ? $_GET['section'] : '';
$subject_filter = isset($_GET['subject']) ? $_GET['subject'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$student_search = isset($_GET['student']) ? $_GET['student'] : '';

// Build query with same filters
$where_conditions = ["1=1"];
$params = [];
$types = "";

if ($department_filter) {
    $where_conditions[] = "a.department_id = ?";
    $params[] = $department_filter;
    $types .= "i";
}

if ($semester_filter) {
    $where_conditions[] = "a.semester_id = ?";
    $params[] = $semester_filter;
    $types .= "i";
}

if ($section_filter) {
    $where_conditions[] = "a.section_id = ?";
    $params[] = $section_filter;
    $types .= "i";
}

if ($subject_filter) {
    $where_conditions[] = "a.subject_id = ?";
    $params[] = $subject_filter;
    $types .= "i";
}

if ($date_from) {
    $where_conditions[] = "a.attendance_date >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if ($date_to) {
    $where_conditions[] = "a.attendance_date <= ?";
    $params[] = $date_to;
    $types .= "s";
}

if ($student_search) {
    $where_conditions[] = "(s.full_name LIKE ? OR s.admission_number LIKE ?)";
    $search_param = "%$student_search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

$where_clause = implode(" AND ", $where_conditions);

$query = "SELECT 
    a.attendance_date,
    s.full_name as student_name,
    s.admission_number,
    d.department_name,
    sem.semester_name,
    sec.section_name,
    sub.subject_name,
    sub.subject_code,
    a.status,
    t.full_name as marked_by_name,
    a.remarks,
    a.created_at
FROM attendance a
JOIN students s ON a.student_id = s.student_id
JOIN subjects sub ON a.subject_id = sub.subject_id
JOIN departments d ON a.department_id = d.department_id
JOIN semesters sem ON a.semester_id = sem.semester_id
LEFT JOIN sections sec ON a.section_id = sec.section_id
JOIN teachers t ON a.marked_by = t.teacher_id
WHERE $where_clause
ORDER BY a.attendance_date DESC, s.full_name ASC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment;filename="attendance_records_' . date('Y-m-d') . '.xls"');
header('Cache-Control: max-age=0');

// Output Excel content
echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
echo '<head>';
echo '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';
echo '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>';
echo '<x:Name>Attendance Records</x:Name>';
echo '<x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet>';
echo '</x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->';
echo '</head>';
echo '<body>';
echo '<table border="1">';

// Table Header
echo '<tr style="background-color: #2563eb; color: white; font-weight: bold;">';
echo '<th>Date</th>';
echo '<th>Student Name</th>';
echo '<th>Admission No.</th>';
echo '<th>Department</th>';
echo '<th>Semester</th>';
echo '<th>Section</th>';
echo '<th>Subject Code</th>';
echo '<th>Subject Name</th>';
echo '<th>Status</th>';
echo '<th>Marked By</th>';
echo '<th>Remarks</th>';
echo '<th>Created At</th>';
echo '</tr>';

// Table Data
while ($row = $result->fetch_assoc()) {
    echo '<tr>';
    echo '<td>' . date('Y-m-d', strtotime($row['attendance_date'])) . '</td>';
    echo '<td>' . htmlspecialchars($row['student_name']) . '</td>';
    echo '<td>' . htmlspecialchars($row['admission_number']) . '</td>';
    echo '<td>' . htmlspecialchars($row['department_name']) . '</td>';
    echo '<td>' . htmlspecialchars($row['semester_name']) . '</td>';
    echo '<td>' . ($row['section_name'] ?? '-') . '</td>';
    echo '<td>' . htmlspecialchars($row['subject_code']) . '</td>';
    echo '<td>' . htmlspecialchars($row['subject_name']) . '</td>';
    echo '<td>' . ucfirst($row['status']) . '</td>';
    echo '<td>' . htmlspecialchars($row['marked_by_name']) . '</td>';
    echo '<td>' . ($row['remarks'] ?? '-') . '</td>';
    echo '<td>' . date('Y-m-d H:i:s', strtotime($row['created_at'])) . '</td>';
    echo '</tr>';
}

echo '</table>';
echo '</body>';
echo '</html>';

$stmt->close();
$conn->close();
?>
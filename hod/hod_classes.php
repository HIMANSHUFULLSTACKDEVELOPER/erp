<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('hod')) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

// Get HOD details and department
$sql = "SELECT t.*, d.department_name, d.department_id 
        FROM teachers t 
        JOIN departments d ON d.hod_id = t.user_id
        WHERE t.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$hod = $stmt->get_result()->fetch_assoc();

if (!$hod) {
    die("HOD profile not found or not assigned to any department.");
}

$dept_id = $hod['department_id'];

// Get all semesters with subjects for this department
$semesters_query = "SELECT DISTINCT sem.semester_id, sem.semester_name, sem.semester_number
                    FROM semesters sem
                    JOIN subjects sub ON sem.semester_id = sub.semester_id
                    WHERE sub.department_id = $dept_id
                    ORDER BY sem.semester_number";
$semesters = $conn->query($semesters_query);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Classes Management - College ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #f97316;
            --secondary: #ea580c;
            --success: #22c55e;
            --warning: #eab308;
            --danger: #ef4444;
            --info: #3b82f6;
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

        .hod-profile {
            text-align: center;
        }

        .hod-avatar {
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

        .hod-name {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .hod-dept {
            font-size: 0.9rem;
            opacity: 0.9;
            font-weight: 500;
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

        .back-btn {
            background: linear-gradient(135deg, var(--gray), #57534e);
            color: var(--white);
            border: none;
            padding: 12px 28px;
            border-radius: 15px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-family: 'Outfit', sans-serif;
            text-decoration: none;
            display: inline-block;
        }

        .back-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 25px rgba(0,0,0,0.2);
        }

        /* Semester Cards */
        .semester-grid {
            display: grid;
            gap: 25px;
        }

        .semester-card {
            background: var(--white);
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        .semester-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            padding: 25px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .semester-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            display: flex;
            align-items: center;
        }

        .semester-header h2 i {
            margin-right: 12px;
        }

        .semester-badge {
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            padding: 8px 18px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .subjects-container {
            padding: 30px;
        }

        .subject-item {
            background: var(--light-gray);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            transition: all 0.3s;
            border: 2px solid transparent;
        }

        .subject-item:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 15px rgba(249, 115, 22, 0.1);
        }

        .subject-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(0,0,0,0.05);
        }

        .subject-info h3 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .subject-code {
            color: var(--gray);
            font-size: 0.9rem;
            font-weight: 500;
        }

        .subject-credits {
            background: linear-gradient(135deg, var(--success), #16a34a);
            color: var(--white);
            padding: 8px 18px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .sections-grid {
            display: grid;
            gap: 15px;
        }

        .section-card {
            background: var(--white);
            border-radius: 12px;
            padding: 20px;
            border-left: 4px solid var(--primary);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .section-name {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary);
        }

        .view-students-btn {
            background: linear-gradient(135deg, var(--info), #2563eb);
            color: var(--white);
            border: none;
            padding: 8px 16px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.3s;
            font-family: 'Outfit', sans-serif;
        }

        .view-students-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
        }

        .teacher-info {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
        }

        .teacher-avatar {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1rem;
        }

        .teacher-details {
            flex: 1;
        }

        .teacher-name {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.95rem;
        }

        .teacher-designation {
            color: var(--gray);
            font-size: 0.8rem;
        }

        .no-teacher {
            color: var(--danger);
            font-size: 0.9rem;
            font-weight: 500;
            padding: 10px;
            background: rgba(239, 68, 68, 0.1);
            border-radius: 8px;
            text-align: center;
        }

        .student-count {
            color: var(--gray);
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .student-count i {
            color: var(--primary);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(5px);
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: var(--white);
            border-radius: 20px;
            padding: 0;
            width: 90%;
            max-width: 800px;
            max-height: 80vh;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            animation: modalSlideIn 0.3s;
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            padding: 25px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
        }

        .close-modal {
            background: rgba(255,255,255,0.2);
            border: none;
            color: var(--white);
            width: 35px;
            height: 35px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 1.2rem;
            transition: all 0.3s;
        }

        .close-modal:hover {
            background: rgba(255,255,255,0.3);
        }

        .modal-body {
            padding: 30px;
            max-height: calc(80vh - 100px);
            overflow-y: auto;
        }

        .students-table {
            width: 100%;
            border-collapse: collapse;
        }

        .students-table th {
            text-align: left;
            padding: 12px;
            border-bottom: 2px solid var(--light-gray);
            font-weight: 600;
            color: var(--gray);
            font-size: 0.85rem;
            text-transform: uppercase;
        }

        .students-table td {
            padding: 15px 12px;
            border-bottom: 1px solid var(--light-gray);
        }

        .students-table tr:hover {
            background: var(--light-gray);
        }

        .student-name {
            font-weight: 600;
            color: var(--dark);
        }

        .admission-no {
            color: var(--gray);
            font-size: 0.85rem;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: var(--gray);
        }

        .no-data i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.3;
        }

        .empty-state {
            text-align: center;
            padding: 60px 30px;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.3;
        }

        .empty-state h3 {
            font-size: 1.3rem;
            margin-bottom: 10px;
            color: var(--dark);
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="hod-profile">
                    <div class="hod-avatar"><?php echo strtoupper(substr($hod['full_name'], 0, 1)); ?></div>
                    <div class="hod-name"><?php echo $hod['full_name']; ?></div>
                    <div class="hod-dept"><?php echo $hod['department_name']; ?></div>
                </div>
            </div>
                 <nav class="sidebar-menu">
                <a href="index.php" class="menu-item active">
                    <div class="menu-icon">
                        <i class="fas fa-home"></i>
                    </div>
                    <span class="menu-text">Dashboard</span>
                    <div class="menu-indicator"></div>
                </a>
                <a href="manage_student_semesters.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <span class="menu-text">Students</span>
                    <div class="menu-indicator"></div>
                </a>
                <a href="attandancereview.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <span class="menu-text">Attendance Review</span>
                    <div class="menu-indicator"></div>
                </a>
                <a href="consolidatereport.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <span class="menu-text">Consolidated Report</span>
                    <div class="menu-indicator"></div>
                </a>
                <a href="sections.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-layer-group"></i>
                    </div>
                    <span class="menu-text">Sections</span>
                    <div class="menu-indicator"></div>
                </a>
                <a href="hod_classes.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-chalkboard"></i>
                    </div>
                    <span class="menu-text">Classes</span>
                    <div class="menu-indicator"></div>
                </a>
                <a href="manage_class_teachers.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <span class="menu-text">Class Teachers</span>
                    <div class="menu-indicator"></div>
                </a>
                <a href="manage_substitutes.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-exchange-alt"></i>
                    </div>
                    <span class="menu-text">Substitutes</span>
                    <div class="menu-indicator"></div>
                </a>
                <a href="dept_subjects.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-book"></i>
                    </div>
                    <span class="menu-text">Subjects</span>
                    <div class="menu-indicator"></div>
                </a>
                <a href="dept_subjects_teacher.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-book-reader"></i>
                    </div>
                    <span class="menu-text">Subject Teachers</span>
                    <div class="menu-indicator"></div>
                </a>
                <a href="dept_attendance.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <span class="menu-text">Attendance</span>
                    <div class="menu-indicator"></div>
                </a>
                <a href="dept_reports.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <span class="menu-text">Reports</span>
                    <div class="menu-indicator"></div>
                </a>
                <a href="hod_profile.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-user"></i>
                    </div>
                    <span class="menu-text">Profile</span>
                    <div class="menu-indicator"></div>
                </a>
                <a href="hod_setting.php" class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-cog"></i>
                    </div>
                    <span class="menu-text">Settings</span>
                    <div class="menu-indicator"></div>
                </a>
            </nav>

        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-bar">
                <div class="top-bar-left">
                    <h1>Classes Management</h1>
                    <p>View all subjects, teachers, and students by semester</p>
                </div>
                <a href="index.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            </div>

            <!-- Semesters -->
            <div class="semester-grid">
                <?php 
                $semesters->data_seek(0);
                while($semester = $semesters->fetch_assoc()): 
                    $sem_id = $semester['semester_id'];
                    
                    // Get subjects for this semester
                    $subjects_query = "SELECT * FROM subjects 
                                      WHERE department_id = $dept_id AND semester_id = $sem_id 
                                      ORDER BY subject_name";
                    $subjects = $conn->query($subjects_query);
                    
                    if($subjects->num_rows == 0) continue;
                ?>
                <div class="semester-card">
                    <div class="semester-header">
                        <h2><i class="fas fa-graduation-cap"></i> <?php echo $semester['semester_name']; ?></h2>
                        <div class="semester-badge"><?php echo $subjects->num_rows; ?> Subjects</div>
                    </div>
                    
                    <div class="subjects-container">
                        <?php while($subject = $subjects->fetch_assoc()): 
                            $subject_id = $subject['subject_id'];
                        ?>
                        <div class="subject-item">
                            <div class="subject-header">
                                <div class="subject-info">
                                    <h3><?php echo $subject['subject_name']; ?></h3>
                                    <div class="subject-code"><?php echo $subject['subject_code']; ?></div>
                                </div>
                                <div class="subject-credits">
                                    <i class="fas fa-star"></i> <?php echo $subject['credits']; ?> Credits
                                </div>
                            </div>
                            
                            <div class="sections-grid">
                                <?php
                                // Get all sections for this subject
                                $sections_query = "SELECT DISTINCT 
                                                  sec.section_id, 
                                                  sec.section_name,
                                                  st.academic_year
                                                  FROM subject_teachers st
                                                  LEFT JOIN sections sec ON st.section_id = sec.section_id
                                                  WHERE st.subject_id = $subject_id 
                                                  AND st.semester_id = $sem_id
                                                  ORDER BY sec.section_name";
                                $sections = $conn->query($sections_query);
                                
                                if($sections->num_rows == 0):
                                ?>
                                    <div class="no-teacher">
                                        <i class="fas fa-exclamation-triangle"></i> No teacher assigned for this subject
                                    </div>
                                <?php else: ?>
                                    <?php while($section = $sections->fetch_assoc()): 
                                        $section_id = $section['section_id'];
                                        $academic_year = $section['academic_year'];
                                        
                                        // Get teacher for this section
                                        $teacher_query = "SELECT t.full_name, t.designation, st.teacher_id
                                                         FROM subject_teachers st
                                                         JOIN teachers t ON st.teacher_id = t.teacher_id
                                                         WHERE st.subject_id = $subject_id 
                                                         AND st.semester_id = $sem_id
                                                         AND st.section_id = " . ($section_id ?? 'NULL') . "
                                                         AND st.academic_year = '$academic_year'
                                                         LIMIT 1";
                                        $teacher_result = $conn->query($teacher_query);
                                        $teacher = $teacher_result->fetch_assoc();
                                        
                                        // Get student count
                                        $student_count_query = "SELECT COUNT(*) as count 
                                                               FROM student_semesters ss
                                                               WHERE ss.semester_id = $sem_id
                                                               AND ss.section_id = " . ($section_id ?? 'NULL') . "
                                                               AND ss.is_active = 1
                                                               AND ss.student_id IN (SELECT student_id FROM students WHERE department_id = $dept_id)";
                                        $count_result = $conn->query($student_count_query);
                                        $student_count = $count_result->fetch_assoc()['count'];
                                    ?>
                                    <div class="section-card">
                                        <div class="section-header">
                                            <div class="section-name">
                                                <i class="fas fa-users"></i> Section <?php echo $section['section_name'] ?? 'General'; ?>
                                            </div>
                                            <button class="view-students-btn" 
                                                    onclick="viewStudents(<?php echo $subject_id; ?>, <?php echo $sem_id; ?>, <?php echo $section_id ?? 'null'; ?>, '<?php echo $subject['subject_name']; ?>', '<?php echo $section['section_name'] ?? 'General'; ?>', '<?php echo $academic_year; ?>')">
                                                <i class="fas fa-eye"></i> View Students
                                            </button>
                                        </div>
                                        
                                        <?php if($teacher): ?>
                                        <div class="teacher-info">
                                            <div class="teacher-avatar">
                                                <?php echo strtoupper(substr($teacher['full_name'], 0, 1)); ?>
                                            </div>
                                            <div class="teacher-details">
                                                <div class="teacher-name"><?php echo $teacher['full_name']; ?></div>
                                                <div class="teacher-designation"><?php echo $teacher['designation'] ?? 'Faculty'; ?></div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div class="student-count">
                                            <i class="fas fa-user-graduate"></i>
                                            <span><?php echo $student_count; ?> Students Enrolled</span>
                                        </div>
                                    </div>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
                <?php endwhile; ?>
                
                <?php if($conn->query("SELECT COUNT(*) as count FROM subjects WHERE department_id = $dept_id")->fetch_assoc()['count'] == 0): ?>
                <div class="empty-state">
                    <i class="fas fa-book-open"></i>
                    <h3>No Subjects Found</h3>
                    <p>There are no subjects assigned to your department yet.</p>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Students Modal -->
    <div id="studentsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle"><i class="fas fa-users"></i> Students List</h2>
                <button class="close-modal" onclick="closeModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" id="modalBody">
                <div class="no-data">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Loading students...</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        function viewStudents(subjectId, semesterId, sectionId, subjectName, sectionName, academicYear) {
            const modal = document.getElementById('studentsModal');
            const modalTitle = document.getElementById('modalTitle');
            const modalBody = document.getElementById('modalBody');
            
            modalTitle.innerHTML = `<i class="fas fa-users"></i> ${subjectName} - Section ${sectionName}`;
            modalBody.innerHTML = '<div class="no-data"><i class="fas fa-spinner fa-spin"></i><p>Loading students...</p></div>';
            
            modal.classList.add('active');
            
            // Build URL
            let url = `get_class_students.php?subject_id=${subjectId}&semester_id=${semesterId}`;
            if(sectionId) url += `&section_id=${sectionId}`;
            if(academicYear) url += `&academic_year=${encodeURIComponent(academicYear)}`;
            
            console.log('Fetching from:', url);
            
            // Fetch students via AJAX
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    console.log('Response:', data);
                    
                    if(data.success) {
                        if(data.students.length > 0) {
                            let html = '<table class="students-table"><thead><tr><th>Admission No.</th><th>Name</th><th>Email</th><th>Phone</th></tr></thead><tbody>';
                            
                            data.students.forEach(student => {
                                html += `
                                    <tr>
                                        <td><span class="admission-no">${student.admission_number}</span></td>
                                        <td><span class="student-name">${student.full_name}</span></td>
                                        <td>${student.email}</td>
                                        <td>${student.phone || 'N/A'}</td>
                                    </tr>
                                `;
                            });
                            
                            html += '</tbody></table>';
                            modalBody.innerHTML = html;
                        } else {
                            let debugInfo = '';
                            if(data.debug) {
                                debugInfo = `<div style="margin-top: 15px; padding: 15px; background: #f5f5f5; border-radius: 8px; text-align: left; font-size: 0.85rem;">
                                    <strong>Debug Info:</strong><br>
                                    Subject ID: ${data.debug.subject_id}<br>
                                    Semester ID: ${data.debug.semester_id}<br>
                                    Section ID: ${data.debug.section_id || 'NULL'}<br>
                                    Academic Year: ${data.debug.academic_year || 'Not set'}<br>
                                    Department ID: ${data.debug.dept_id}<br>
                                    ${data.debug.error ? '<span style="color: red;">SQL Error: ' + data.debug.error + '</span>' : ''}
                                </div>`;
                            }
                            modalBody.innerHTML = `<div class="no-data">
                                <i class="fas fa-user-slash"></i>
                                <p>No students enrolled in this class yet.</p>
                                <p style="font-size: 0.85rem; color: #999;">Please make sure students are assigned to this semester and section.</p>
                                ${debugInfo}
                            </div>`;
                        }
                    } else {
                        modalBody.innerHTML = `<div class="no-data">
                            <i class="fas fa-exclamation-circle"></i>
                            <p>Error loading students: ${data.message || 'Unknown error'}</p>
                        </div>`;
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    modalBody.innerHTML = `<div class="no-data">
                        <i class="fas fa-exclamation-circle"></i>
                        <p>Error loading students.</p>
                        <p style="font-size: 0.85rem; color: #999;">${error.message}</p>
                    </div>`;
                });
        }
        
        function closeModal() {
            document.getElementById('studentsModal').classList.remove('active');
        }
        
        // Close modal on outside click
        window.onclick = function(event) {
            const modal = document.getElementById('studentsModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>
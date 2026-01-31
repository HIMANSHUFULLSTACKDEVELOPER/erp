<?php
require_once 'config.php';

// If user is already logged in, redirect to appropriate dashboard
if (isLoggedIn()) {
    switch ($_SESSION['role']) {
        case 'admin':
            redirect('admin/index.php');
            break;
        case 'hod':
            redirect('hod/index.php');
            break;
        case 'teacher':
            redirect('teacher/index.php');
            break;
        case 'student':
            redirect('student/index.php');
            break;
        case 'parent':
            redirect('parent/index.php');
            break;
        default:
            redirect('login.php');
    }
} else {
    // Not logged in, redirect to login page
    redirect('login.php');
}
?>
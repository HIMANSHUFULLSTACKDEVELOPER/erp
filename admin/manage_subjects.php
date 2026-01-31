<?php
require_once '../config.php';

if (!isLoggedIn() || !hasRole('admin')) {
    redirect('login.php');
}

// Handle subject deletion
if (isset($_POST['delete_subject'])) {
    $subject_id = $_POST['subject_id'];
    $conn->query("DELETE FROM subjects WHERE subject_id = $subject_id");
    $success_message = "Subject deleted successfully!";
}

// Handle subject addition
if (isset($_POST['add_subject'])) {
    $subject_name = $_POST['subject_name'];
    $subject_code = $_POST['subject_code'];
    $department_id = $_POST['department_id'];
    $semester_id = $_POST['semester_id'];
    $credits = $_POST['credits'];
    
    $stmt = $conn->prepare("INSERT INTO subjects (subject_name, subject_code, department_id, semester_id, credits) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssiii", $subject_name, $subject_code, $department_id, $semester_id, $credits);
    
    if ($stmt->execute()) {
        $success_message = "Subject added successfully!";
    }
}

// Handle subject update
if (isset($_POST['update_subject'])) {
    $subject_id = $_POST['subject_id'];
    $subject_name = $_POST['subject_name'];
    $subject_code = $_POST['subject_code'];
    $department_id = $_POST['department_id'];
    $semester_id = $_POST['semester_id'];
    $credits = $_POST['credits'];
    
    $stmt = $conn->prepare("UPDATE subjects SET subject_name=?, subject_code=?, department_id=?, semester_id=?, credits=? WHERE subject_id=?");
    $stmt->bind_param("ssiiii", $subject_name, $subject_code, $department_id, $semester_id, $credits, $subject_id);
    
    if ($stmt->execute()) {
        $success_message = "Subject updated successfully!";
    }
}

// Get all subjects with department and semester info
$subjects = $conn->query("SELECT s.*, d.department_name, d.department_code, sem.semester_name 
                         FROM subjects s 
                         JOIN departments d ON s.department_id = d.department_id 
                         JOIN semesters sem ON s.semester_id = sem.semester_id 
                         ORDER BY d.department_name, sem.semester_number, s.subject_name");

// Get departments and semesters for the form
$departments = $conn->query("SELECT * FROM departments ORDER BY department_name");
$semesters = $conn->query("SELECT * FROM semesters ORDER BY semester_number");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Subjects - College ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --secondary: #8b5cf6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #1f2937;
            --gray: #6b7280;
            --light-gray: #f3f4f6;
            --white: #ffffff;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--light-gray);
            color: var(--dark);
            overflow-x: hidden;
        }

        /* Enhanced Animations */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInLeft {
            from {
                transform: translateX(-100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }

        @keyframes rotate {
            from {
                transform: rotate(0deg);
            }
            to {
                transform: rotate(360deg);
            }
        }

        @keyframes bounce {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-10px);
            }
        }

        @keyframes shake {
            0%, 100% {
                transform: translateX(0);
            }
            25% {
                transform: translateX(-5px);
            }
            75% {
                transform: translateX(5px);
            }
        }

        @keyframes scaleIn {
            from {
                transform: scale(0.8);
                opacity: 0;
            }
            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        .dashboard {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 260px;
            background: linear-gradient(180deg, var(--dark) 0%, #111827 100%);
            color: var(--white);
            padding: 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            animation: slideInLeft 0.5s ease;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }

        .sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .sidebar::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.1);
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.3);
            border-radius: 3px;
        }

        .sidebar-header {
            padding: 30px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            background: rgba(255,255,255,0.05);
        }

        .sidebar-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, #fff, #94a3b8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .sidebar-header p {
            font-size: 0.85rem;
            opacity: 0.7;
            margin-top: 5px;
        }

        .sidebar-menu {
            padding: 20px 0;
        }

        .menu-item {
            padding: 15px 20px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            display: flex;
            align-items: center;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            position: relative;
            overflow: hidden;
            margin: 4px 10px;
            border-radius: 8px;
        }

        .menu-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 4px;
            background: linear-gradient(180deg, var(--primary), var(--secondary));
            transform: scaleY(0);
            transition: transform 0.3s ease;
        }

        .menu-item::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, transparent, rgba(255,255,255,0.1));
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .menu-item:hover::before,
        .menu-item.active::before {
            transform: scaleY(1);
        }

        .menu-item:hover::after {
            opacity: 1;
        }

        .menu-item:hover, .menu-item.active {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }

        .menu-item i {
            margin-right: 12px;
            width: 20px;
            text-align: center;
            transition: transform 0.3s ease;
        }

        .menu-item:hover i {
            transform: scale(1.2) rotate(10deg);
            animation: bounce 0.6s ease;
        }

        .menu-item.active i {
            animation: pulse 2s infinite;
        }

        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            border: none;
            padding: 12px 15px;
            border-radius: 12px;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.4);
            transition: all 0.3s ease;
        }

        .mobile-menu-toggle:hover {
            transform: scale(1.05) rotate(5deg);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.5);
        }

        .mobile-menu-toggle:active {
            transform: scale(0.95);
        }

        .mobile-menu-toggle i {
            transition: transform 0.3s ease;
        }

        .mobile-menu-toggle:hover i {
            animation: rotate 0.5s ease;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 999;
            backdrop-filter: blur(4px);
            transition: opacity 0.3s ease;
        }

        .sidebar-overlay.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        .main-content {
            flex: 1;
            margin-left: 260px;
            padding: 30px;
            transition: margin-left 0.3s ease;
            animation: fadeIn 0.5s ease;
        }

        .top-bar {
            background: linear-gradient(135deg, var(--white), #f8fafc);
            padding: 20px 30px;
            border-radius: 16px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05), 0 1px 3px rgba(0,0,0,0.1);
            animation: slideInRight 0.5s ease;
            flex-wrap: wrap;
            gap: 15px;
            border: 1px solid rgba(255,255,255,0.8);
        }

        .top-bar:hover {
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }

        .top-bar h1 {
            font-size: 1.8rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--dark), var(--primary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255,255,255,0.5);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(37, 99, 235, 0.4);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #dc2626);
            color: var(--white);
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(239, 68, 68, 0.4);
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 0.9rem;
        }

        .btn i {
            transition: transform 0.3s ease;
        }

        .btn:hover i {
            transform: scale(1.2);
        }

        .card {
            background: var(--white);
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05), 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            animation: scaleIn 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(0,0,0,0.05);
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.1);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-gray);
            flex-wrap: wrap;
            gap: 15px;
        }

        .card-header h3 {
            font-size: 1.2rem;
            font-weight: 600;
            position: relative;
            padding-left: 15px;
        }

        .card-header h3::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 70%;
            background: linear-gradient(180deg, var(--primary), var(--secondary));
            border-radius: 2px;
        }

        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 12px;
        }

        .table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            min-width: 800px;
        }

        .table th {
            text-align: left;
            padding: 12px;
            background: linear-gradient(135deg, var(--light-gray), #e5e7eb);
            border-bottom: 2px solid var(--primary);
            font-weight: 600;
            color: var(--dark);
            font-size: 0.85rem;
            text-transform: uppercase;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .table th:first-child {
            border-top-left-radius: 12px;
        }

        .table th:last-child {
            border-top-right-radius: 12px;
        }

        .table td {
            padding: 15px 12px;
            border-bottom: 1px solid var(--light-gray);
            transition: background 0.3s ease;
        }

        .table tbody tr {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
        }

        .table tbody tr:hover {
            background: linear-gradient(135deg, #f8fafc, var(--light-gray));
            transform: scale(1.01);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        .badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .badge:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }

        .badge.success { 
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(16, 185, 129, 0.1)); 
            color: var(--success); 
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        .badge.info { 
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.2), rgba(37, 99, 235, 0.1)); 
            color: var(--primary); 
            border: 1px solid rgba(37, 99, 235, 0.3);
        }
        .badge.purple { 
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.2), rgba(139, 92, 246, 0.1)); 
            color: var(--secondary); 
            border: 1px solid rgba(139, 92, 246, 0.3);
        }
        .badge.warning { 
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.2), rgba(245, 158, 11, 0.1)); 
            color: var(--warning); 
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            overflow-y: auto;
            padding: 20px;
            backdrop-filter: blur(8px);
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background: var(--white);
            padding: 30px;
            border-radius: 20px;
            width: 100%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            animation: scaleIn 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }

        .modal-content::-webkit-scrollbar {
            width: 8px;
        }

        .modal-content::-webkit-scrollbar-track {
            background: var(--light-gray);
            border-radius: 4px;
        }

        .modal-content::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 4px;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-gray);
        }

        .modal-header h2 {
            font-size: 1.5rem;
            font-weight: 600;
            background: linear-gradient(135deg, var(--dark), var(--primary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .close {
            cursor: pointer;
            font-size: 1.5rem;
            color: var(--gray);
            transition: all 0.3s ease;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
        }

        .close:hover {
            color: var(--danger);
            background: rgba(239, 68, 68, 0.1);
            transform: rotate(90deg);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #d1d5db;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--white);
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
            transform: scale(1.01);
        }

        .form-group input:hover,
        .form-group select:hover {
            border-color: var(--primary);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            animation: slideInRight 0.5s ease;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .alert i {
            font-size: 1.2rem;
        }

        .alert-success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.15), rgba(16, 185, 129, 0.05));
            color: var(--success);
            border: 2px solid var(--success);
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .filter-section {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-section select,
        .filter-section input {
            padding: 10px 15px;
            border: 2px solid #d1d5db;
            border-radius: 10px;
            transition: all 0.3s ease;
            background: var(--white);
        }

        .filter-section input:focus,
        .filter-section select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
        }

        .filter-section input:hover,
        .filter-section select:hover {
            border-color: var(--primary);
        }

        /* Tablet Styles */
        @media (max-width: 1024px) {
            .sidebar {
                width: 220px;
            }

            .main-content {
                margin-left: 220px;
                padding: 20px;
            }

            .top-bar h1 {
                font-size: 1.5rem;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .card {
                padding: 20px;
            }
        }

        /* Mobile Styles */
        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.mobile-active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 80px 15px 15px;
            }

            .top-bar {
                flex-direction: column;
                padding: 15px;
                text-align: center;
                border-radius: 12px;
            }

            .top-bar h1 {
                font-size: 1.3rem;
            }

            .card {
                padding: 15px;
                border-radius: 12px;
            }

            .card-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .filter-section {
                width: 100%;
                flex-direction: column;
            }

            .filter-section input,
            .filter-section select {
                width: 100%;
            }

            .btn {
                padding: 10px 18px;
                font-size: 0.9rem;
            }

            .btn-sm {
                padding: 6px 12px;
                font-size: 0.8rem;
            }

            .modal-content {
                padding: 20px;
                margin: 20px 0;
                border-radius: 16px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            /* Mobile Table Cards */
            .table-container {
                display: block;
            }

            .table thead {
                display: none;
            }

            .table,
            .table tbody,
            .table tr,
            .table td {
                display: block;
                width: 100%;
            }

            .table tr {
                margin-bottom: 15px;
                border: 2px solid var(--light-gray);
                border-radius: 12px;
                padding: 15px;
                background: var(--white);
                box-shadow: 0 4px 8px rgba(0,0,0,0.06);
                transition: all 0.3s ease;
            }

            .table tr:hover {
                box-shadow: 0 8px 16px rgba(0,0,0,0.12);
                transform: translateY(-2px);
            }

            .table td {
                text-align: right;
                padding: 12px 0;
                border-bottom: 1px solid var(--light-gray);
                position: relative;
                padding-left: 50%;
            }

            .table td:last-child {
                border-bottom: none;
                padding-left: 0;
            }

            .table td::before {
                content: attr(data-label);
                position: absolute;
                left: 0;
                width: 45%;
                padding-right: 10px;
                text-align: left;
                font-weight: 600;
                color: var(--gray);
                text-transform: uppercase;
                font-size: 0.75rem;
            }

            .table td:last-child::before {
                display: none;
            }

            .action-buttons {
                justify-content: flex-start;
            }

            .action-buttons .btn {
                flex: 1;
                min-width: 80px;
                justify-content: center;
            }
        }

        /* Small Mobile */
        @media (max-width: 480px) {
            .top-bar h1 {
                font-size: 1.1rem;
            }

            .sidebar-header h2 {
                font-size: 1.2rem;
            }

            .menu-item {
                padding: 12px 15px;
                font-size: 0.9rem;
            }

            .modal-header h2 {
                font-size: 1.2rem;
            }

            .modal-content {
                padding: 15px;
            }

            .btn {
                padding: 8px 14px;
            }
        }

        /* Loading Animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: var(--white);
            animation: rotate 1s linear infinite;
        }

        /* Tooltip */
        [data-tooltip] {
            position: relative;
        }

        [data-tooltip]::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%) translateY(-5px);
            background: var(--dark);
            color: var(--white);
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease, transform 0.3s ease;
        }

        [data-tooltip]:hover::after {
            opacity: 1;
            transform: translateX(-50%) translateY(-10px);
        }
    </style>
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" onclick="toggleMobileMenu()">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" onclick="closeMobileMenu()"></div>

    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>Admin Panel</h2>
                <p>College ERP System</p>
            </div>
            <nav class="sidebar-menu">
                <a href="index.php" class="menu-item">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="manage_students.php" class="menu-item">
                    <i class="fas fa-user-graduate"></i> Students
                </a>
                <a href="manage_teachers.php" class="menu-item">
                    <i class="fas fa-chalkboard-teacher"></i> Teachers
                </a>
                <a href="manage_departments.php" class="menu-item">
                    <i class="fas fa-building"></i> Departments
                </a>
                <a href="manage_courses.php" class="menu-item">
                    <i class="fas fa-book"></i> Courses
                </a>
                <a href="manage_subjects.php" class="menu-item active">
                    <i class="fas fa-list"></i> Subjects
                </a>
                <a href="reports.php" class="menu-item">
                    <i class="fas fa-chart-bar"></i> Reports
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-bar">
                <h1>Manage Subjects</h1>
                <button class="btn btn-primary" onclick="openAddModal()">
                    <i class="fas fa-plus"></i> Add New Subject
                </button>
            </div>

            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> 
                    <span><?php echo $success_message; ?></span>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h3>All Subjects</h3>
                    <div class="filter-section">
                        <input type="text" id="searchInput" placeholder="🔍 Search subjects...">
                        <select id="deptFilter">
                            <option value="">All Departments</option>
                            <?php 
                            $departments->data_seek(0);
                            while($dept = $departments->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $dept['department_name']; ?>"><?php echo $dept['department_name']; ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                <div class="table-container">
                    <table class="table" id="subjectsTable">
                        <thead>
                            <tr>
                                <th>Subject Code</th>
                                <th>Subject Name</th>
                                <th>Department</th>
                                <th>Semester</th>
                                <th>Credits</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($subject = $subjects->fetch_assoc()): ?>
                            <tr>
                                <td data-label="Subject Code"><span class="badge info"><?php echo $subject['subject_code']; ?></span></td>
                                <td data-label="Subject Name"><strong><?php echo $subject['subject_name']; ?></strong></td>
                                <td data-label="Department"><span class="badge purple"><?php echo $subject['department_name']; ?></span></td>
                                <td data-label="Semester"><span class="badge warning"><?php echo $subject['semester_name']; ?></span></td>
                                <td data-label="Credits"><span class="badge success"><?php echo $subject['credits']; ?> Credits</span></td>
                                <td data-label="Actions">
                                    <div class="action-buttons">
                                        <button class="btn btn-primary btn-sm" onclick='editSubject(<?php echo json_encode($subject); ?>)' data-tooltip="Edit Subject">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this subject?');">
                                            <input type="hidden" name="subject_id" value="<?php echo $subject['subject_id']; ?>">
                                            <button type="submit" name="delete_subject" class="btn btn-danger btn-sm" data-tooltip="Delete Subject">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Subject Modal -->
    <div id="addSubjectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add New Subject</h2>
                <span class="close" onclick="closeAddModal()">&times;</span>
            </div>
            <form method="POST">
                <div class="form-group">
                    <label>Subject Name *</label>
                    <input type="text" name="subject_name" placeholder="e.g., Engineering Mathematics I" required>
                </div>
                <div class="form-group">
                    <label>Subject Code *</label>
                    <input type="text" name="subject_code" placeholder="e.g., IT101" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Department *</label>
                        <select name="department_id" required>
                            <option value="">Select Department</option>
                            <?php 
                            $departments->data_seek(0);
                            while($dept = $departments->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $dept['department_id']; ?>"><?php echo $dept['department_name']; ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Semester *</label>
                        <select name="semester_id" required>
                            <option value="">Select Semester</option>
                            <?php 
                            $semesters->data_seek(0);
                            while($sem = $semesters->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $sem['semester_id']; ?>"><?php echo $sem['semester_name']; ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Credits *</label>
                    <input type="number" name="credits" min="1" max="10" value="3" required>
                </div>
                <button type="submit" name="add_subject" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-plus"></i> Add Subject
                </button>
            </form>
        </div>
    </div>

    <!-- Edit Subject Modal -->
    <div id="editSubjectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit Subject</h2>
                <span class="close" onclick="closeEditModal()">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="subject_id" id="edit_subject_id">
                <div class="form-group">
                    <label>Subject Name *</label>
                    <input type="text" name="subject_name" id="edit_subject_name" required>
                </div>
                <div class="form-group">
                    <label>Subject Code *</label>
                    <input type="text" name="subject_code" id="edit_subject_code" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Department *</label>
                        <select name="department_id" id="edit_department_id" required>
                            <option value="">Select Department</option>
                            <?php 
                            $departments->data_seek(0);
                            while($dept = $departments->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $dept['department_id']; ?>"><?php echo $dept['department_name']; ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Semester *</label>
                        <select name="semester_id" id="edit_semester_id" required>
                            <option value="">Select Semester</option>
                            <?php 
                            $semesters->data_seek(0);
                            while($sem = $semesters->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $sem['semester_id']; ?>"><?php echo $sem['semester_name']; ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Credits *</label>
                    <input type="number" name="credits" id="edit_credits" min="1" max="10" required>
                </div>
                <button type="submit" name="update_subject" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-save"></i> Update Subject
                </button>
            </form>
        </div>
    </div>

    <script>
        function toggleMobileMenu() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            sidebar.classList.toggle('mobile-active');
            overlay.classList.toggle('active');
        }

        function closeMobileMenu() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            sidebar.classList.remove('mobile-active');
            overlay.classList.remove('active');
        }

        function openAddModal() {
            document.getElementById('addSubjectModal').classList.add('active');
        }

        function closeAddModal() {
            document.getElementById('addSubjectModal').classList.remove('active');
        }

        function editSubject(subject) {
            document.getElementById('edit_subject_id').value = subject.subject_id;
            document.getElementById('edit_subject_name').value = subject.subject_name;
            document.getElementById('edit_subject_code').value = subject.subject_code;
            document.getElementById('edit_department_id').value = subject.department_id;
            document.getElementById('edit_semester_id').value = subject.semester_id;
            document.getElementById('edit_credits').value = subject.credits;
            document.getElementById('editSubjectModal').classList.add('active');
        }

        function closeEditModal() {
            document.getElementById('editSubjectModal').classList.remove('active');
        }

        // Search and filter functionality
        document.getElementById('searchInput').addEventListener('keyup', filterTable);
        document.getElementById('deptFilter').addEventListener('change', filterTable);

        function filterTable() {
            const searchValue = document.getElementById('searchInput').value.toLowerCase();
            const deptValue = document.getElementById('deptFilter').value.toLowerCase();
            const tableRows = document.querySelectorAll('#subjectsTable tbody tr');
            
            tableRows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const matchesSearch = text.includes(searchValue);
                const matchesDept = deptValue === '' || text.includes(deptValue);
                row.style.display = (matchesSearch && matchesDept) ? '' : 'none';
            });
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const addModal = document.getElementById('addSubjectModal');
            const editModal = document.getElementById('editSubjectModal');
            if (event.target == addModal) {
                closeAddModal();
            }
            if (event.target == editModal) {
                closeEditModal();
            }
        }

        // Close mobile menu when clicking menu items
        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', closeMobileMenu);
        });

        // Add smooth scroll behavior
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    </script>
</body>
</html>
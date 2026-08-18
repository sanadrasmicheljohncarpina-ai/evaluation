<?php
session_start();
$role = $_SESSION['role'] ?? '';
session_destroy();

$redirects = [
    'superadmin'          => 'index/admin/admin_login.php',
    'admin'                => 'index/admin/admin_login.php',
    'executive_assistant'  => 'executive/executive_login.php',
    'school_head'           => 'index/school_head/school_head_login.php',
    'teacher'               => 'index/faculty/faculty_login.php',
    'staff'                 => 'index/faculty/faculty_login.php',
    'student'               => 'index/student/student_login.php',
];
$dest = $redirects[$role] ?? 'index/admin/admin_login.php';
header("Location: ../$dest");
exit;
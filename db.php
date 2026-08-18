<?php
// student/db.php
// Place this file inside: htdocs/index/student/
// Include in every student PHP file with: require_once 'db.php';

$mysqli = new mysqli("localhost", "root", "", "evaluation", 3306);
if ($mysqli->connect_errno) {
    die("Database connection failed: " . $mysqli->connect_error);
}
$mysqli->set_charset("utf8mb4");

// Absolute path to the shared image folder
// student/ is one level inside index/, so go up one level
// dirname(__DIR__) from student/ = htdocs/index/
define('UPLOAD_DIR', dirname(__DIR__) . '/image/');

// Relative URL used in HTML <img src=""> tags inside student pages
define('UPLOAD_URL', '../image/');
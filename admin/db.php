<?php
// admin/db.php
// Place this file inside: htdocs/index/admin/
// Include in every admin PHP file with: require_once 'db.php';

$mysqli = new mysqli("localhost", "root", "", "evaluation", 3306);

if ($mysqli->connect_errno) {
    die("Database connection failed: " . $mysqli->connect_error);
}

// This must run on every successful connection, not just on failure.
$mysqli->set_charset("utf8mb4");

// Absolute path to the shared image folder
// admin/ is one level inside index/, so go up one level with dirname(__DIR__)
// dirname(__DIR__) from admin/ = htdocs/index/
define('UPLOAD_DIR', dirname(__DIR__) . '/image/');

// Relative URL used in HTML <img src=""> tags inside admin pages
define('UPLOAD_URL', '../image/');
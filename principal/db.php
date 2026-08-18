<?php
// principal/db.php
// Place this file inside: htdocs/index/principal/
// Include in every principal PHP file with: require_once 'db.php';

$mysqli = new mysqli("localhost", "root", "", "evaluation", 3306);
if ($mysqli->connect_errno) {
    die("Database connection failed: " . $mysqli->connect_error);
}
$mysqli->set_charset("utf8mb4");

// Absolute path to the shared image folder
// principal/ is one level inside index/, so go up one level
// dirname(__DIR__) from principal/ = htdocs/index/
define('UPLOAD_DIR', dirname(__DIR__) . '/image/');

// Relative URL used in HTML <img src=""> tags inside principal pages
define('UPLOAD_URL', '../image/');

// ---- Self-healing schema check (matches your ALTER TABLE-on-load pattern) ----
// Confirms education_level is VARCHAR (not the old 3-value ENUM) and that
// employee_id exists, in case this environment hasn't been migrated yet.
$col = $mysqli->query("SHOW COLUMNS FROM users LIKE 'education_level'")->fetch_assoc();
if ($col && stripos($col['Type'], 'enum') !== false) {
    $mysqli->query("ALTER TABLE users MODIFY COLUMN education_level VARCHAR(20) NULL");
}
$mysqli->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS employee_id VARCHAR(50) NULL");

// ---- Shared service layer ----
require_once dirname(__DIR__) . '/shared/AuthenticationService.php';
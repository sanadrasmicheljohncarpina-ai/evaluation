<?php
// student/update_photo.php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: student_login.php"); exit;
}

$student_id = $_SESSION['user_id'];

if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $allowed   = ['image/jpeg','image/png','image/webp','image/gif'];
    $file_type = mime_content_type($_FILES['photo']['tmp_name']);
    $file_size = $_FILES['photo']['size'];

    if (in_array($file_type, $allowed) && $file_size <= 10 * 1024 * 1024) {
        $ext      = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
        $filename = uniqid('stu_', true) . '.' . strtolower($ext);

        if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

        if (move_uploaded_file($_FILES['photo']['tmp_name'], UPLOAD_DIR . $filename)) {
            // Delete old photo
            $old = $mysqli->prepare("SELECT photo FROM users WHERE id=? LIMIT 1");
            $old->bind_param("i", $student_id);
            $old->execute();
            $oldRow = $old->get_result()->fetch_assoc();
            $old->close();
            if (!empty($oldRow['photo']) && file_exists(UPLOAD_DIR . $oldRow['photo'])) {
                @unlink(UPLOAD_DIR . $oldRow['photo']);
            }

            // Save new photo
            $upd = $mysqli->prepare("UPDATE users SET photo=? WHERE id=?");
            $upd->bind_param("si", $filename, $student_id);
            $upd->execute();
            $upd->close();
        }
    }
}

header("Location: student_dashboard.php");
exit;
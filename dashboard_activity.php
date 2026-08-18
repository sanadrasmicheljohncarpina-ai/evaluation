<?php
// dashboard_activity.php — feeds the "System Audits" list on admin_dashboard.php
session_start();
require_once 'db.php';

if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin','superadmin','registrar'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}
header('Content-Type: application/json');

// Same table faculty_dashboard.php / staff_dashboard.php already write to
// on designation updates, plus any future automated ("System Automator")
// events logged with user_id = 0.
$mysqli->query("CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50) NOT NULL DEFAULT 'designation_update',
    user_id INT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    extra_data TEXT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_unread (is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$limit = 20; // how many rows to feed the scrollable list

$rows = [];
$q = $mysqli->query("SELECT id, type, user_id, message, extra_data, created_at FROM notifications ORDER BY created_at DESC LIMIT " . (int)$limit);
if ($q) {
    while ($r = $q->fetch_assoc()) {
        $extra = json_decode($r['extra_data'] ?? '', true) ?: [];
        $role  = $extra['role'] ?? null;

        if ((int)$r['user_id'] === 0) {
            // Automated / backend-triggered event
            $actor = 'System Automator';
            $icon  = 'fa-robot';
            $color = '#4ade80';
        } elseif ($role === 'teacher') {
            $actor = $extra['full_name'] ?? 'Teacher';
            $icon  = 'fa-chalkboard-user';
            $color = '#0d9488';
        } elseif ($role === 'staff') {
            $actor = $extra['full_name'] ?? 'Staff';
            $icon  = 'fa-id-card-clip';
            $color = '#2b6cb0';
        } else {
            $actor = 'Admin';
            $icon  = 'fa-user-shield';
            $color = '#60a5fa';
        }

        $rows[] = [
            'id'         => (int)$r['id'],
            'text'       => $r['message'],
            'meta'       => $actor,
            'icon'       => $icon,
            'color'      => $color,
            'time_label' => date('M j, g:ia', strtotime($r['created_at'])),
        ];
    }
}

echo json_encode(['activity' => $rows]);
if ($mysqli->ping()) $mysqli->close();
<?php
/**
 * notify_role_change.php
 * ──────────────────────────────────────────────────────────────────────────
 * Called via POST (JSON or form) by the faculty/staff user dashboard
 * whenever a user saves a role/designation update.
 *
 * Expected POST body (JSON or form-encoded):
 *   {
 *     "user_id":         123,          // int  — the user whose role changed
 *     "old_role":        "faculty",    // string
 *     "new_role":        "faculty",    // string (role column may stay "faculty")
 *     "old_designation": "Teacher",    // string|null
 *     "new_designation": "Registrar"   // string|null — their new sub-role/title
 *   }
 *
 * Returns JSON { success: true } or { error: "..." }
 *
 * ── SETUP ─────────────────────────────────────────────────────────────────
 * Run this SQL once to create the log table:
 *
 *   CREATE TABLE IF NOT EXISTS role_change_log (
 *     id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *     user_id          INT NOT NULL,
 *     old_role         VARCHAR(60)  NOT NULL DEFAULT '',
 *     new_role         VARCHAR(60)  NOT NULL DEFAULT '',
 *     old_designation  VARCHAR(120) NULL,
 *     new_designation  VARCHAR(120) NULL,
 *     changed_at       DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     INDEX idx_changed (changed_at),
 *     INDEX idx_user    (user_id)
 *   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 */

session_start();
require_once 'db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

/* ── Auth: must be a logged-in user (any role may update their own record) */
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

/* ── Parse body (supports JSON or form POST) ── */
$body = file_get_contents('php://input');
$data = $body ? json_decode($body, true) : $_POST;

$userId         = (int)   ($data['user_id']         ?? $_SESSION['user_id']);
$oldRole        = trim(   ($data['old_role']         ?? '') );
$newRole        = trim(   ($data['new_role']         ?? '') );
$oldDesignation = trim(   ($data['old_designation']  ?? '') ) ?: null;
$newDesignation = trim(   ($data['new_designation']  ?? '') ) ?: null;

/* ── Users may only log their own changes (admins may log any) ── */
$isAdmin = in_array($_SESSION['role'] ?? '', ['admin', 'superadmin', 'registrar']);
if (!$isAdmin && $userId !== (int)$_SESSION['user_id']) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

/* ── Ensure the table exists (auto-create if missing) ── */
$mysqli->query("
    CREATE TABLE IF NOT EXISTS role_change_log (
        id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id          INT NOT NULL,
        old_role         VARCHAR(60)  NOT NULL DEFAULT '',
        new_role         VARCHAR(60)  NOT NULL DEFAULT '',
        old_designation  VARCHAR(120) NULL,
        new_designation  VARCHAR(120) NULL,
        changed_at       DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_changed (changed_at),
        INDEX idx_user    (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* ── Insert the log entry ── */
$stmt = $mysqli->prepare("
    INSERT INTO role_change_log (user_id, old_role, new_role, old_designation, new_designation)
    VALUES (?, ?, ?, ?, ?)
");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'DB prepare failed: ' . $mysqli->error]);
    exit;
}
$stmt->bind_param('issss', $userId, $oldRole, $newRole, $oldDesignation, $newDesignation);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    http_response_code(500);
    echo json_encode(['error' => 'Insert failed']);
    exit;
}

/* ── Also push to audit_log if it exists ── */
$al = $mysqli->query("SHOW TABLES LIKE 'audit_log'");
if ($al && $al->num_rows > 0) {
    // Fetch the user's name for a readable log entry
    $nameQ = $mysqli->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
    $nameQ->bind_param('i', $userId);
    $nameQ->execute();
    $nameRow = $nameQ->get_result()->fetch_assoc();
    $nameQ->close();

    $fullName   = $nameRow['full_name'] ?? "User #{$userId}";
    $oldLabel   = $oldDesignation ?: ucfirst($oldRole);
    $newLabel   = $newDesignation ?: ucfirst($newRole);
    $actionText = "{$fullName} changed role: {$oldLabel} → {$newLabel}";

    $alStmt = $mysqli->prepare("INSERT INTO audit_log (action, performed_by, created_at) VALUES (?, ?, NOW())");
    if ($alStmt) {
        $alStmt->bind_param('ss', $actionText, $fullName);
        $alStmt->execute();
        $alStmt->close();
    }
}

echo json_encode(['success' => true, 'logged_at' => date('c')]);

if ($mysqli->ping()) $mysqli->close();
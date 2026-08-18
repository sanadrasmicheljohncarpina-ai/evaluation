<?php
/**
 * dashboard_counts.php
 * Real-time JSON endpoint for the admin dashboard.
 * Returns live user/eval counts + recent activity feed (role changes, evaluation
 * submissions, new users).
 *
 * Called by the admin dashboard every 10 seconds via fetch().
 * Also used by the notification poller to push role-change alerts.
 *
 * Pass ?mark_read=1 to mark all current role-change notifications as seen
 * (updates the session's "last seen" timestamp so the unread badge clears).
 *
 * ── System Audits box (feed_full) ──────────────────────────────────────────
 * Only ever contains two kinds of entries:
 *   - type=role_change → meta "Admin Engine"     (a user changed their OWN
 *                         role from their own dashboard — role_change_log)
 *   - type=audit       → meta "System Automator" (evaluation submissions —
 *                         who submitted, plus a running total — pulled live
 *                         from evaluation_tracker)
 * "New user registered" events are intentionally excluded from feed_full;
 * they only show up in the bell dropdown (feed), not the audit box.
 */
session_start();
require_once 'db.php';

// Guard — only authenticated admins
if (
    empty($_SESSION['user_id']) ||
    !in_array($_SESSION['role'] ?? '', ['admin', 'superadmin', 'registrar'])
) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
header('Cache-Control: no-store');

/* ── Mark as read (bell "Mark all read" button) ─────────────────────────── */
if (isset($_GET['mark_read'])) {
    $_SESSION['rcl_last_seen'] = time();
}

/* ── Counts ──────────────────────────────────────────────────────────────── */
$counts = [
    'total'    => 0,
    'teacher'  => 0,
    'staff'    => 0,
    'students' => 0,
    'evals'    => 0,
    'evals_submitted' => 0,
];

$rows = [
    ['teacher',  "SELECT COUNT(*) AS c FROM users WHERE role='teacher'"],
    ['staff',    "SELECT COUNT(*) AS c FROM users WHERE role='staff'"],
    ['students', "SELECT COUNT(*) AS c FROM users WHERE role='student'"],
    ['evals',    "SELECT COUNT(*) AS c FROM evaluation_tracker"],
    ['evals_submitted', "SELECT COUNT(*) AS c FROM evaluation_tracker WHERE status IN ('submitted','approved','archived')"],
];
foreach ($rows as [$key, $sql]) {
    $r = $mysqli->query($sql);
    if ($r) $counts[$key] = (int)($r->fetch_assoc()['c'] ?? 0);
}
$counts['total']         = $counts['teacher'] + $counts['staff'] + $counts['students'];
$counts['faculty_staff'] = $counts['teacher'] + $counts['staff']; // for the "Teacher" card

/* ── Recent Activity Feed ────────────────────────────────────────────────── */
// $feed holds EVERYTHING (role changes, evaluation submissions, new registrations) —
// this is what powers the bell dropdown.
//
// $auditFeed holds ONLY role_change + audit entries — this is what powers the
// two-column "System Audits" box on the dashboard (System Automator / Admin Engine).

$feed = [];

/* ── 1. role_change_log → "Admin Engine" (self-service role changes) ── */
$rclExists = $mysqli->query("SHOW TABLES LIKE 'role_change_log'");
if ($rclExists && $rclExists->num_rows > 0) {
    $q = $mysqli->query("
        SELECT rcl.user_id, rcl.old_role, rcl.new_role,
               rcl.old_designation, rcl.new_designation,
               rcl.changed_at, u.full_name
        FROM role_change_log rcl
        LEFT JOIN users u ON u.id = rcl.user_id
        ORDER BY rcl.changed_at DESC
        LIMIT 20
    ");
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $ts  = strtotime($row['changed_at']);
            $ago = _timeAgo($ts);

            $oldLabel = _formatRole($row['old_role'], $row['old_designation']);
            $newLabel = _formatRole($row['new_role'], $row['new_designation']);

            $feed[] = [
                'id'       => 'rcl_' . $row['user_id'] . '_' . $ts,
                'type'     => 'role_change',
                'text'     => htmlspecialchars($row['full_name']) . ' updated their role from ' . htmlspecialchars($oldLabel) . ' → ' . htmlspecialchars($newLabel),
                'meta'     => 'Admin Engine',
                'time'     => $ago,
                'ts'       => $ts,
                'color'    => '#f59e0b',
                'icon'     => 'fa-user-pen',
                'user'     => htmlspecialchars($row['full_name']),
                'new_role' => htmlspecialchars($newLabel),
            ];
        }
    }
}

/* ── Optional level filter for the System Automator column ── */
/* ?level=junior_high | senior_high | college — leave unset for all levels */
$levelFilter = $_GET['level'] ?? '';
$validLevels = ['junior_high', 'senior_high', 'college'];
if (!in_array($levelFilter, $validLevels, true)) $levelFilter = '';

/* ── 2. evaluation_tracker → "System Automator" (evaluation submissions) ── */
// Individual submission events: who submitted, and what kind of evaluation.
$levelClause = $levelFilter ? " AND et.level = '" . $mysqli->real_escape_string($levelFilter) . "'" : "";
$evSubQ = $mysqli->query("
    SELECT et.id, et.eval_bucket, et.form_type, et.status, et.submitted_at, et.level,
           u.full_name AS student_name
    FROM evaluation_tracker et
    LEFT JOIN users u ON u.id = et.student_id
    WHERE et.status IN ('submitted','approved','archived')" . $levelClause . "
    ORDER BY et.submitted_at DESC
    LIMIT 10
");
if ($evSubQ) {
    while ($row = $evSubQ->fetch_assoc()) {
        $ts          = strtotime($row['submitted_at']);
        $studentName = $row['student_name'] ?: 'A student';
        $bucket      = $row['eval_bucket'] ?: 'Teacher';
        $levelLabel  = _formatLevel($row['level']);

        $feed[] = [
            'id'    => 'evt_' . $row['id'],
            'type'  => 'audit',
            'text'  => htmlspecialchars($studentName) . ' submitted a ' . htmlspecialchars($bucket) . ' evaluation',
            'meta'  => 'System Automator' . ($levelLabel ? ' · ' . $levelLabel : ''),
            'time'  => _timeAgo($ts),
            'ts'    => $ts,
            'color' => '#14b8a6',
            'icon'  => 'fa-file-circle-check',
            'level' => $row['level'],
        ];
    }
}

// Rolling total-submitted summary entry — surfaces the running count you asked for.
$totalSubQ = $mysqli->query("
    SELECT COUNT(*) AS c, MAX(submitted_at) AS latest
    FROM evaluation_tracker
    WHERE status IN ('submitted','approved','archived')" . $levelClause . "
");
if ($totalSubQ) {
    $tRow           = $totalSubQ->fetch_assoc();
    $totalSubmitted = (int)($tRow['c'] ?? 0);
    if ($totalSubmitted > 0) {
        $ts = $tRow['latest'] ? strtotime($tRow['latest']) : time();
        $levelSuffix = $levelFilter ? ' (' . _formatLevel($levelFilter) . ')' : '';
        $feed[] = [
            'id'    => 'evt_total_' . $totalSubmitted . '_' . $levelFilter,
            'type'  => 'audit',
            'text'  => $totalSubmitted . ' evaluation' . ($totalSubmitted === 1 ? '' : 's') . ' submitted so far' . $levelSuffix,
            'meta'  => 'System Automator',
            'time'  => _timeAgo($ts),
            'ts'    => $ts,
            'color' => '#14b8a6',
            'icon'  => 'fa-chart-line',
        ];
    }
}

/* ── 3. Synthetic from users → bell dropdown only, NOT the audit box ── */
$q = $mysqli->query("SELECT full_name, role, created_at FROM users ORDER BY created_at DESC LIMIT 5");
if ($q) {
    while ($row = $q->fetch_assoc()) {
        $ts = strtotime($row['created_at']);
        $feed[] = [
            'id'    => 'usr_' . md5($row['full_name'] . $ts),
            'type'  => 'new_user',
            'text'  => 'New ' . ucfirst($row['role']) . ' account registered: ' . htmlspecialchars($row['full_name']),
            'meta'  => 'User Registration',
            'time'  => _timeAgo($ts),
            'ts'    => $ts,
            'color' => '#22c55e',
            'icon'  => 'fa-user-plus',
        ];
    }
}

// Sort combined feed by timestamp descending.
usort($feed, fn($a, $b) => $b['ts'] - $a['ts']);

// Bell dropdown: top 6 of EVERYTHING (role changes, submissions, new registrations).
$feedShort = array_slice($feed, 0, 6);

// System Audits box: ONLY role_change ("Admin Engine") + audit ("System Automator"),
// new_user registrations are deliberately left out here.
$auditFeed = array_values(array_filter($feed, fn($e) => in_array($e['type'], ['role_change', 'audit'])));
$auditFeed = array_slice($auditFeed, 0, 12);

/* ── Unread role-change count (for badge) ── */
$unreadRoleChanges = 0;
if ($rclExists && $rclExists->num_rows > 0) {
    $lastSeen = $_SESSION['rcl_last_seen'] ?? 0;
    $q = $mysqli->query("SELECT COUNT(*) AS c FROM role_change_log WHERE UNIX_TIMESTAMP(changed_at) > " . (int)$lastSeen);
    if ($q) $unreadRoleChanges = (int)($q->fetch_assoc()['c'] ?? 0);
}

echo json_encode([
    'counts'              => $counts,
    'feed'                => $feedShort,
    'feed_full'           => $auditFeed,
    'unread_role_changes' => $unreadRoleChanges,
    'server_time'         => time(),
]);

/* ── Helpers ── */
function _timeAgo(int $ts): string {
    $diff = time() - $ts;
    if ($diff < 60)   return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', $ts);
}
function _formatRole(string $role, ?string $designation): string {
    if (!empty($designation)) return ucwords(str_replace('_', ' ', $designation));
    return ucfirst($role);
}
function _formatLevel(?string $level): string {
    return match ($level) {
        'junior_high' => 'Junior High',
        'senior_high' => 'Senior High',
        'college'     => 'College',
        default       => '',
    };
}
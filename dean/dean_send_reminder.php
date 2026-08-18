<?php
// dean/dean_send_reminder.php
//
// Backing endpoint for the Evaluation Tracker's "Send Reminder" (single
// student, GET link) and "Send Bulk Reminder" (selected students, POST/
// AJAX) actions. Every reminder is logged to evaluation_reminders
// (self-healing table, created in db.php the same way employee_id is).
//
// IMPORTANT SCOPE NOTE: this app has no email/SMS/push infrastructure
// anywhere (no mail(), no PHPMailer, no notifications table) — so a
// "reminder" here is an in-system record the dean side can see and rate-
// limit against, not an outbound message to the student. If/when a real
// notification channel exists (e.g. a student-facing notifications table,
// or an SMTP-backed mailer), send it from inside the loop below, right
// after the INSERT.

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => false,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
require_once 'db.php';
require_once dirname(__DIR__) . '/shared/system_settings_service.php';

const REMINDER_COOLDOWN_HOURS = 24;

// ── AUTH GUARD ────────────────────────────────────────────
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'dean') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        header("Location: dean_login.php");
        exit;
    }
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
    exit;
}
$deanId = (int)$_SESSION['user_id'];
$isGet  = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET');

// ── CURRENT PERIOD ────────────────────────────────────────
$settings      = get_system_settings($mysqli);
$period_id_int = $settings['period_id'] ?? 0;

function reminder_fail(bool $isGet, string $msg, int $code = 400) {
    global $mysqli;
    if ($isGet) {
        $qs = http_build_query(['reminder_error' => $msg]);
        header("Location: dean_evaluation_tracker.php?$qs");
    } else {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $msg]);
    }
    $mysqli->close();
    exit;
}

if ($period_id_int <= 0) {
    reminder_fail($isGet, 'No active evaluation period.');
}

// ── READ INPUT ────────────────────────────────────────────
// GET: the plain "Send Reminder" link (?student_id=123) — works even
// without JavaScript, and redirects back to the tracker afterward.
// POST: AJAX, from either a single row's button or "Send Bulk Reminder".
// Body is JSON: { student_ids: [...], csrf_token: "..." }.
$studentIds = [];

if ($isGet) {
    if (!empty($_GET['student_id'])) {
        $studentIds = [(int)$_GET['student_id']];
    }
} else {
    $raw = json_decode(file_get_contents('php://input'), true);
    if (!is_array($raw)) { $raw = []; }
    $token = (string)($raw['csrf_token'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token) || $token === '') {
        reminder_fail(false, 'Session expired. Please refresh and try again.', 403);
    }
    $studentIds = array_map('intval', $raw['student_ids'] ?? []);
}

$studentIds = array_values(array_unique(array_filter($studentIds, fn($id) => $id > 0)));
if (empty($studentIds)) {
    reminder_fail($isGet, 'No students selected.');
}

// ── VALIDATE RECIPIENTS — must be real, approved, active students ─────
// (mirrors the WHERE clause dean_evaluation_tracker.php uses to decide
// who's in scope, so a reminder can never target someone the tracker
// itself wouldn't have listed)
$ph = implode(',', array_fill(0, count($studentIds), '?'));
$stmt = $mysqli->prepare("
    SELECT id, full_name FROM users
    WHERE id IN ($ph) AND role='student' AND is_active=1 AND account_status='approved'
");
$stmt->bind_param(str_repeat('i', count($studentIds)), ...$studentIds);
$stmt->execute();
$validRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$nameById = [];
foreach ($validRows as $r) { $nameById[(int)$r['id']] = $r['full_name']; }

// ── EXCLUDE STUDENTS WHO ALREADY SUBMITTED THIS PERIOD ─────────────────
$submittedIds = [];
if (!empty($nameById)) {
    $ids  = array_keys($nameById);
    $ph2  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $mysqli->prepare("
        SELECT DISTINCT evaluator_id FROM evaluation_tracker
        WHERE eval_type='student' AND level='college' AND status IN ('submitted','approved')
          AND period_id=? AND evaluator_id IN ($ph2)
    ");
    $stmt->bind_param('i' . str_repeat('i', count($ids)), $period_id_int, ...$ids);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $submittedIds[] = (int)$r['evaluator_id'];
    }
    $stmt->close();
}

// ── COOLDOWN — most recent reminder per recipient, this period ────────
$lastSentAt = [];
if (!empty($nameById)) {
    $ids  = array_keys($nameById);
    $ph3  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $mysqli->prepare("
        SELECT recipient_id, MAX(created_at) v FROM evaluation_reminders
        WHERE period_id=? AND recipient_id IN ($ph3)
        GROUP BY recipient_id
    ");
    $stmt->bind_param('i' . str_repeat('i', count($ids)), $period_id_int, ...$ids);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $lastSentAt[(int)$r['recipient_id']] = $r['v'];
    }
    $stmt->close();
}

// ── SEND (= LOG) EACH REMINDER ─────────────────────────────────────────
$sent    = [];
$skipped = [];
$insStmt = $mysqli->prepare("
    INSERT INTO evaluation_reminders (period_id, sender_id, recipient_id, eval_type, level)
    VALUES (?, ?, ?, 'student', 'college')
");

foreach ($studentIds as $sid) {
    if (!isset($nameById[$sid])) {
        $skipped[] = ['id' => $sid, 'name' => null, 'reason' => 'Student not found or not eligible.'];
        continue;
    }
    $name = $nameById[$sid];

    if (in_array($sid, $submittedIds, true)) {
        $skipped[] = ['id' => $sid, 'name' => $name, 'reason' => 'Already submitted.'];
        continue;
    }

    if (isset($lastSentAt[$sid])) {
        $elapsedHrs = (time() - strtotime($lastSentAt[$sid])) / 3600;
        if ($elapsedHrs < REMINDER_COOLDOWN_HOURS) {
            $nextAt = date('M j, g:i A', strtotime($lastSentAt[$sid]) + REMINDER_COOLDOWN_HOURS * 3600);
            $skipped[] = ['id' => $sid, 'name' => $name, 'reason' => "Already reminded recently — next reminder available $nextAt."];
            continue;
        }
    }

    $insStmt->bind_param('iii', $period_id_int, $deanId, $sid);
    $insStmt->execute();
    $sent[] = ['id' => $sid, 'name' => $name, 'sent_at' => date('M j, Y g:i A'), 'sent_at_iso' => date('c')];
}
$insStmt->close();
$mysqli->close();

// ── RESPOND ─────────────────────────────────────────────────────────
if ($isGet) {
    $qs = http_build_query([
        'reminder_sent'    => count($sent),
        'reminder_skipped' => count($skipped),
    ]);
    header("Location: dean_evaluation_tracker.php?$qs");
    exit;
}

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'sent'    => $sent,
    'skipped' => $skipped,
]);

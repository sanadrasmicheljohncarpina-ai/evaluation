<?php
// principal_send_reminder.php
// Companion endpoint for principal_evaluation_tracker.php's "Send Reminder"
// / "Send Bulk Reminder" actions — flagged in that file's own comments as
// "NOT included here yet." This closes that gap.
//
// Contract (matches the front-end JS already in
// principal_evaluation_tracker.php exactly — nothing there needs to change
// except the one CSRF-token addition to the GET fallback link, patched
// alongside this file):
//
//   POST (JS path), Content-Type: application/json
//     body:     { student_ids: [int, ...], csrf_token: string }
//     response: { success: true, sent: [{id, name, sent_at}], skipped: [{id, name, reason}] }
//             | { success: false, error: string }
//
//   GET (no-JS fallback), from the plain <a href="principal_send_reminder.php?student_id=...">
//     query:    ?student_id=<int>&csrf_token=<string>
//     behavior: same logic for a single id, then redirects back to
//     principal_evaluation_tracker.php with ?reminder_sent=<n>&reminder_skipped=<n>
//     or ?reminder_error=<msg> — the exact query params the tracker page's
//     own JS already reads on load to show the toast.
//
// Writes to evaluation_reminders(recipient_id, period_id, sender_id,
// created_at) — the same table/columns the tracker page itself creates
// and reads from (CREATE TABLE IF NOT EXISTS block at its top), so no
// schema change needed here.
//
// ── ASSUMPTIONS (flagging since I haven't seen dean_send_reminder.php,
// which the tracker's comments say this should mirror) ──────────────────
//   1. principal_common.php exposes: $mysqli, $settings, $structureActive,
//      $period_id_int, $scopeAcademicLevels, $scopeGrades, and the
//      safe_rows()/safe_scalar()/esc_list() helpers — same globals
//      principal_evaluation_tracker.php already relies on without
//      redefining them itself (unlike dean_evaluation_tracker.php, which
//      DOES define safe_scalar()/safe_rows() locally — suggesting
//      Principal's copies live in principal_common.php instead). If
//      principal_common.php doesn't actually define esc_list(), it's a
//      one-liner: implode(',', array_map(fn($v) => "'".$mysqli->real_escape_string($v)."'", $list)).
//   2. A reminder is refused (skipped, not silently sent) for: a student
//      outside this principal's Basic Ed scope, a student who already
//      submitted this period's evaluation, and a student reminded within
//      the last REMINDER_COOLDOWN_HOURS. The skip 'reason' strings are
//      worded to read naturally in the toast's existing
//      `${s.name} (${s.reason})` template.
//   3. The auth guard (role === 'principal', logged in) lives inside
//      principal_common.php, matching principal_evaluations.php and
//      principal_evaluation_tracker.php, which both require_once it
//      without a separate guard of their own.

require_once 'principal_common.php';

header('Content-Type: application/json');

const REMINDER_COOLDOWN_HOURS = 24; // must match principal_evaluation_tracker.php

function reminder_csrf_ok($sent): bool {
    return is_string($sent) && $sent !== '' && hash_equals($_SESSION['csrf_token'] ?? '', $sent);
}

// Sends reminders for the given (not-yet-scope-checked) student ids.
// Every id is re-validated against this principal's actual Basic Ed scope
// here — never trust ids from the request alone.
function send_reminders_for(mysqli $mysqli, array $studentIds, int $senderId, int $periodId, array $scopeAcademicLevels, array $scopeGrades): array {
    $sent = []; $skipped = [];
    if (empty($studentIds)) return ['sent' => $sent, 'skipped' => $skipped];

    $ph = implode(',', array_fill(0, count($studentIds), '?'));
    $levelIn = esc_list($mysqli, $scopeAcademicLevels);
    $gradeIn = esc_list($mysqli, $scopeGrades);
    $rows = safe_rows($mysqli, "
        SELECT id, full_name FROM users
        WHERE role='student' AND is_active=1 AND account_status='approved'
          AND academic_level IN ($levelIn) AND grade_level IN ($gradeIn)
          AND id IN ($ph)
    ", str_repeat('i', count($studentIds)), $studentIds);
    $byId = [];
    foreach ($rows as $r) { $byId[(int)$r['id']] = $r['full_name']; }

    foreach ($studentIds as $sid) {
        $sid = (int)$sid;

        if (!isset($byId[$sid])) {
            $skipped[] = ['id' => $sid, 'name' => null, 'reason' => 'not in your scope'];
            continue;
        }
        $name = $byId[$sid];

        if ($periodId <= 0) {
            $skipped[] = ['id' => $sid, 'name' => $name, 'reason' => 'no active period'];
            continue;
        }

        // Already submitted this period's evaluation? Don't nag them.
        $already = safe_scalar($mysqli, "
            SELECT 1 FROM evaluation_tracker
            WHERE eval_type='student' AND status IN ('submitted','approved')
              AND period_id=? AND evaluator_id=? LIMIT 1
        ", 'ii', [$periodId, $sid]);
        if ($already) {
            $skipped[] = ['id' => $sid, 'name' => $name, 'reason' => 'already submitted'];
            continue;
        }

        // Cooldown — one reminder per student per period per 24h.
        $lastSent = safe_scalar($mysqli, "
            SELECT MAX(created_at) FROM evaluation_reminders
            WHERE recipient_id=? AND period_id=?
        ", 'ii', [$sid, $periodId]);
        if ($lastSent && (time() - strtotime($lastSent)) / 3600 < REMINDER_COOLDOWN_HOURS) {
            $skipped[] = ['id' => $sid, 'name' => $name, 'reason' => 'reminded recently'];
            continue;
        }

        $ins = $mysqli->prepare("INSERT INTO evaluation_reminders (recipient_id, period_id, sender_id, created_at) VALUES (?, ?, ?, NOW())");
        $ins->bind_param("iii", $sid, $periodId, $senderId);
        $ins->execute();
        $ins->close();

        $sent[] = ['id' => $sid, 'name' => $name, 'sent_at' => date('M j, g:i A')];
    }
    return ['sent' => $sent, 'skipped' => $skipped];
}

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

// ── POST (JS) path ───────────────────────────────────────────────────
if ($isPost) {
    $body  = json_decode(file_get_contents('php://input'), true);
    $token = is_array($body) ? ($body['csrf_token'] ?? '') : '';

    if (!reminder_csrf_ok($token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => "Your session expired or the request looked invalid. Please refresh and try again."]);
        exit;
    }

    $ids = is_array($body) ? array_values(array_unique(array_filter(array_map('intval', $body['student_ids'] ?? [])))) : [];
    if (empty($ids)) {
        echo json_encode(['success' => false, 'error' => 'No students selected.']);
        exit;
    }
    if (!$structureActive) {
        echo json_encode(['success' => false, 'error' => 'Basic Education is not the active academic structure right now.']);
        exit;
    }

    $result = send_reminders_for($mysqli, $ids, (int)$_SESSION['user_id'], $period_id_int, $scopeAcademicLevels, $scopeGrades);
    $mysqli->close();
    echo json_encode(['success' => true, 'sent' => $result['sent'], 'skipped' => $result['skipped']]);
    exit;
}

// ── GET (no-JS) fallback path — single student, redirect back with counts ──
$studentId = (int)($_GET['student_id'] ?? 0);
$token     = $_GET['csrf_token'] ?? '';

if (!reminder_csrf_ok($token)) {
    header('Location: principal_evaluation_tracker.php?reminder_error=' . urlencode("Your session expired. Please try again."));
    exit;
}
if ($studentId <= 0) {
    header('Location: principal_evaluation_tracker.php?reminder_error=' . urlencode("No student specified."));
    exit;
}
if (!$structureActive) {
    header('Location: principal_evaluation_tracker.php?reminder_error=' . urlencode("Basic Education is not the active academic structure right now."));
    exit;
}

$result = send_reminders_for($mysqli, [$studentId], (int)$_SESSION['user_id'], $period_id_int, $scopeAcademicLevels, $scopeGrades);
$mysqli->close();

$sentN = count($result['sent']);
$skipN = count($result['skipped']);

if ($sentN === 0 && $skipN > 0) {
    $reason = $result['skipped'][0]['reason'] ?? 'could not be reminded';
    header('Location: principal_evaluation_tracker.php?reminder_error=' . urlencode("Reminder not sent: $reason."));
    exit;
}

header('Location: principal_evaluation_tracker.php?reminder_sent=' . $sentN . '&reminder_skipped=' . $skipN);
exit;
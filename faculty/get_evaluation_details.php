<?php
/**
 * get_evaluation_details.php
 *
 * AJAX endpoint for the Teacher/Staff "Evaluations Received" -> View Details
 * modal (see faculty_dashboard.php / staff_dashboard.php openEvalDetails()).
 *
 * Given ?tracker_id=<evaluation_tracker.id>, returns everything needed to
 * render one evaluation in detail:
 *   - target name / eval type / period / submitted date / overall score
 *   - category-level averages
 *   - question-by-question scores (question text/category as stored on the
 *     evaluation_questions row at read time -- see NOTE below)
 *   - the written comment (evaluation_tracker.remarks), or null
 *
 * Evaluator identity is never selected, joined, or returned anywhere in
 * this file -- the UI-facing anonymity rule from the spec is enforced here,
 * not just in the front end.
 *
 * Security: a tracker_id only resolves if it belongs to the *logged-in*
 * user as target_user_id. Any other id (someone else's evaluation, or a
 * tracker with no matching row) returns a generic "not found" error --
 * never a distinguishable "exists but isn't yours" message.
 */

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

header('Content-Type: application/json');

function json_fail(string $message, int $http_status = 400): void {
    http_response_code($http_status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

// ── AUTH ─────────────────────────────────────────────────────
// This endpoint is shared by both the Teacher and Staff dashboards, so it
// accepts either role rather than hardcoding one (unlike each dashboard's
// own session_bootstrap.php).
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['teacher', 'staff'], true)) {
    json_fail('Not authorized.', 401);
}
$user_id = (int)$_SESSION['user_id'];

$tracker_id = isset($_GET['tracker_id']) ? (int)$_GET['tracker_id'] : 0;
if ($tracker_id <= 0) {
    json_fail('Missing or invalid evaluation.', 400);
}

// ── SAME LABEL MAP/HELPERS THE DASHBOARDS USE ──────────────────
// Kept local (not shared/) since faculty_dashboard.php / staff_dashboard.php
// currently define these inline too -- duplicated on purpose to avoid
// coupling this read-only endpoint to either dashboard's bootstrap file.
function eval_type_label(?string $eval_type, ?string $peer_group = null): string {
    switch ($eval_type) {
        case 'student':               return 'Student Evaluation';
        case 'peer':
        case 'faculty_peer':
        case 'staff_peer':            return 'Peer Evaluation' . ($peer_group ? ' (' . $peer_group . ')' : '');
        case 'school_head':           return 'School Head Evaluation';
        case 'supervisor_to_teacher':
        case 'supervisor_to_staff':
        case 'supervisor_to_ea':      return 'Supervisor Evaluation';
        default:                      return ucwords(str_replace('_', ' ', $eval_type ?: 'Evaluation'));
    }
}

// ── FETCH THE TRACKER ROW — SCOPED TO THIS USER AS TARGET ─────
// Deliberately does NOT select evaluator_id, evaluator_department, or any
// other evaluator-identifying column -- anonymity is enforced by omission,
// not by hiding a value that was fetched.
$stmt = $mysqli->prepare("
    SELECT et.id, et.eval_type, et.peer_group, et.score, et.remarks,
           et.submitted_at, et.target_user_id,
           ep.period_label, ep.semester, ep.school_year,
           u.full_name AS target_name
    FROM evaluation_tracker et
    LEFT JOIN evaluation_periods ep ON ep.id = et.period_id
    JOIN users u ON u.id = et.target_user_id
    WHERE et.id = ? AND et.target_user_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $tracker_id, $user_id);
$stmt->execute();
$tracker = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$tracker) {
    // Covers: wrong id, someone else's evaluation, or archived/purged row.
    json_fail('This evaluation could not be found.', 404);
}

// ── PERIOD LABEL ────────────────────────────────────────────
// Mirrors the "$period_label ?? $semester" fallback pattern already used
// in the dashboards, extended to combine school_year + semester when both
// are present (e.g. "2026-2027 · Summer") since evaluation_periods carries
// both columns per shared/system_settings_service.php.
$period_label = null;
if (!empty($tracker['school_year']) && !empty($tracker['semester'])) {
    $period_label = $tracker['school_year'] . ' · ' . $tracker['semester'];
} else {
    $period_label = $tracker['period_label'] ?? $tracker['semester'] ?? null;
}

// ── QUESTION-BY-QUESTION RESULTS ────────────────────────────
// Pulled from questionnaire_answers (the actual submitted answers) LEFT
// JOINed to evaluation_questions for display text/category. This is
// intentionally a LEFT JOIN, not an INNER JOIN: per the spec, a question
// can be edited or removed from the live questionnaire after evaluations
// referencing it were already submitted. The submitted score must still
// show even if the question row itself is gone later -- it just falls
// back to a placeholder label instead of silently disappearing or being
// re-matched against today's active questionnaire.
$qStmt = $mysqli->prepare("
    SELECT qa.question_id, qa.answer_score,
           eq.question_text, eq.category
    FROM questionnaire_answers qa
    LEFT JOIN evaluation_questions eq ON eq.id = qa.question_id
    WHERE qa.tracker_id = ?
    ORDER BY eq.category ASC, eq.id ASC
");
$qStmt->bind_param("i", $tracker_id);
$qStmt->execute();
$qRes = $qStmt->get_result();

$questions   = [];
$cat_totals  = []; // category => ['sum' => x, 'count' => y]

while ($row = $qRes->fetch_assoc()) {
    $category = $row['category'] ?? 'General';
    $questions[] = [
        'question_id'   => (int)$row['question_id'],
        'category'      => $category,
        'question_text' => $row['question_text'] ?? '(This question is no longer available)',
        'score'         => (int)$row['answer_score'],
    ];

    if (!isset($cat_totals[$category])) $cat_totals[$category] = ['sum' => 0, 'count' => 0];
    $cat_totals[$category]['sum']   += (int)$row['answer_score'];
    $cat_totals[$category]['count'] += 1;
}
$qStmt->close();

$categories = [];
foreach ($cat_totals as $cat => $t) {
    $categories[] = [
        'category' => $cat,
        'avg'      => $t['count'] ? round($t['sum'] / $t['count'], 2) : 0,
    ];
}

// ── OVERALL SCORE ────────────────────────────────────────────
// faculty_dashboard.php's submit handler computes an overall average and
// writes it to evaluation_tracker.score at submission time, so that column
// is trustworthy for teacher-target rows. staff_dashboard.php's submit_peer
// handler never populates that column at all (its own recent_subs/all_subs
// queries always recompute the average from questionnaire_answers on the
// fly instead) -- so for staff-target rows et.score is reliably NULL. This
// falls back to the same on-the-fly average from the answers already
// fetched above whenever the stored score is missing, rather than showing
// a false 0.00.
$answer_sum   = array_sum(array_column($questions, 'score'));
$answer_count = count($questions);
$computed_avg = $answer_count ? round($answer_sum / $answer_count, 2) : 0.0;
$overall_score = $tracker['score'] !== null ? (float)$tracker['score'] : $computed_avg;

// ── RESPONSE ────────────────────────────────────────────────
echo json_encode([
    'ok'               => true,
    'target_name'      => $tracker['target_name'],
    'eval_type_label'  => eval_type_label($tracker['eval_type'], $tracker['peer_group']),
    'period_label'     => $period_label,
    'submitted_at'     => $tracker['submitted_at'] ? date('F j, Y', strtotime($tracker['submitted_at'])) : null,
    'overall_score'    => $overall_score,
    'categories'       => $categories,
    'questions'        => $questions,
    'comment'          => (isset($tracker['remarks']) && trim((string)$tracker['remarks']) !== '') ? $tracker['remarks'] : null,
]);

$mysqli->close();
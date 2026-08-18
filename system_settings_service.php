<?php
/**
 * system_settings_service.php
 * ─────────────────────────────────────────────────────────────────────────
 * SINGLE SOURCE OF TRUTH for academic/evaluation configuration.
 *
 * The Executive Assistant (admin_dashboard.php) is the only writer of this
 * configuration. Every role dashboard (Dean today; Principal, Teacher,
 * Staff, Student next) is a READ-ONLY consumer via get_system_settings().
 *
 * Include this AFTER db.php (needs $mysqli) and BEFORE you use any of its
 * functions. Safe to include multiple times per request (has its own guard).
 *
 * WHY THIS FILE ALSO TOUCHES `evaluation_periods`:
 * Today, student_dashboard.php gates evaluation submission (and stamps
 * evaluation_tracker.period_id) purely off `evaluation_periods.is_active` —
 * it does not know `system_settings` exists. If we only used
 * `system_settings` to drive what the Dean *displays*, an admin could set
 * "Force Closed" and the Dean would show that, while students could still
 * submit, because nothing told `evaluation_periods` to close too. That is a
 * lie-to-the-Dean bug, not just a display bug.
 *
 * ss_sync_evaluation_period() closes that gap by keeping exactly one active
 * row in `evaluation_periods` in agreement with whatever the Executive
 * Assistant just configured. Call it once, right after a "System & Period"
 * settings save. Every dashboard's queries (including the existing student
 * submission gate) then stay correct without needing their own changes.
 * ─────────────────────────────────────────────────────────────────────────
 */

if (!defined('SYSTEM_SETTINGS_SERVICE_LOADED')) {
define('SYSTEM_SETTINGS_SERVICE_LOADED', true);

// ems_get_active_period() already exists in EvaluationAnalyticsService.php —
// reuse it here instead of a second "what's the active period" query.
// __DIR__ always resolves to shared/ regardless of which dashboard includes
// this file, so this works from dean/, principal/, etc. unchanged.
require_once __DIR__ . '/EvaluationAnalyticsService.php';

// ── STATIC REFERENCE DATA ───────────────────────────────────────────────

function ss_structure_labels(): array {
    return [
        'college' => 'College',
        'jhs'     => 'Junior High School',
        'shs'     => 'Senior High School',
    ];
}

function ss_structure_terms(): array {
    return [
        'college' => ['1st Semester', '2nd Semester', 'Summer'],
        'jhs'     => ['School Year'],
        'shs'     => ['School Year'],
    ];
}

function ss_defaults(): array {
    return [
        'acad_year'      => date('Y') . '-' . (date('Y') + 1),
        'acad_structure' => 'college',
        'acad_term'      => '1st Semester',
        'auto_schedule'  => 1,
        'control_mode'   => 'schedule',   // schedule | open | closed
        'eval_start'     => '',
        'eval_end'       => '',
        'maintenance'    => 0,
        'rule_only_during_period' => 1,
        'rule_edit_after_submit'  => 0,
        'rule_one_submission'     => 1,
        'rule_require_all'        => 1,
        'rule_auto_lock'          => 1,
        'rule_countdown'          => 1,
        'rule_prevent_late'       => 1,
        'publish_state'           => 'published', // draft | published
        'notify_eval_open'        => 1,
        'notify_eval_closing'     => 1,
        'notify_faculty_complete' => 1,
        'notify_reminders'        => 0,
    ];
}

// ── RAW SETTINGS (from system_settings key/value table) ────────────────

function ss_raw(mysqli $mysqli): array {
    $sys = ss_defaults();

    $stbl = $mysqli->query("SHOW TABLES LIKE 'system_settings'");
    if ($stbl && $stbl->num_rows > 0) {
        $sr = $mysqli->query("SELECT setting_key, setting_value FROM system_settings");
        if ($sr) {
            while ($row = $sr->fetch_assoc()) {
                $sys[$row['setting_key']] = $row['setting_value'];
            }
        }
    }

    // Guard against a stale/invalid Structure+Term combination left in the DB.
    $terms = ss_structure_terms();
    if (!isset($terms[$sys['acad_structure']])) $sys['acad_structure'] = 'college';
    if (!in_array($sys['acad_term'], $terms[$sys['acad_structure']], true)) {
        $sys['acad_term'] = $terms[$sys['acad_structure']][0];
    }

    return $sys;
}

// ── RESOLVED STATUS (identical logic to admin_dashboard.php's
//    compute_eval_health / compute_eval_status — moved here so there is
//    exactly one implementation every dashboard shares) ──────────────────

function ss_health(array $sys): array {
    $base = ['duration_days' => null, 'remaining_days' => null, 'elapsed_days' => null, 'days_until_start' => null];

    if (!empty($sys['maintenance'])) {
        return array_merge($base, ['label' => 'MAINTENANCE', 'cls' => 'gray',
            'headline' => 'System is in maintenance mode.', 'sub' => 'All non-admin access is locked.']);
    }
    if (($sys['publish_state'] ?? 'published') === 'draft') {
        return array_merge($base, ['label' => 'DRAFT', 'cls' => 'gray',
            'headline' => 'Evaluation is saved as a draft.', 'sub' => 'Not visible to students or faculty yet.']);
    }

    $mode = $sys['control_mode'] ?? 'schedule';
    if ($mode === 'open') {
        return array_merge($base, ['label' => 'LIVE · FORCED OPEN', 'cls' => 'green',
            'headline' => 'Evaluation is manually forced open.', 'sub' => 'Accepting submissions regardless of the schedule.']);
    }
    if ($mode === 'closed') {
        return array_merge($base, ['label' => 'CLOSED · FORCED', 'cls' => 'red',
            'headline' => 'Evaluation is manually forced closed.', 'sub' => 'Blocking submissions regardless of the schedule.']);
    }
    if (empty($sys['auto_schedule'])) {
        return array_merge($base, ['label' => 'CLOSED · MANUAL', 'cls' => 'gray',
            'headline' => 'Automatic scheduling is off.', 'sub' => 'Status will not change until a schedule is enabled.']);
    }

    $start = $sys['eval_start'] ?? '';
    $end   = $sys['eval_end']   ?? '';
    if (!$start || !$end) {
        return array_merge($base, ['label' => 'NOT CONFIGURED', 'cls' => 'gray',
            'headline' => 'No evaluation window is configured.', 'sub' => 'Set an opening and closing date to enable scheduling.']);
    }
    $ts = strtotime($start); $te = strtotime($end);
    if ($ts === false || $te === false) {
        return array_merge($base, ['label' => 'NOT CONFIGURED', 'cls' => 'gray',
            'headline' => 'The configured dates could not be read.', 'sub' => 'Re-check the opening and closing date fields.']);
    }

    $now = time();
    $duration_days = max(0, (int)round(($te - $ts) / 86400));

    if ($now < $ts) {
        $days = max(1, (int)ceil(($ts - $now) / 86400));
        return array_merge($base, ['label' => 'SCHEDULED', 'cls' => 'yellow', 'duration_days' => $duration_days,
            'days_until_start' => $days,
            'headline' => 'Evaluation has not started yet.', 'sub' => 'Starts in ' . $days . ' day' . ($days === 1 ? '' : 's') . '.']);
    }
    if ($now > $te) {
        $days = max(1, (int)floor(($now - $te) / 86400));
        return array_merge($base, ['label' => 'CLOSED', 'cls' => 'red', 'duration_days' => $duration_days,
            'elapsed_days' => $days,
            'headline' => 'Evaluation period has ended.', 'sub' => 'Ended ' . ($days === 1 ? 'yesterday' : $days . ' days ago') . '.']);
    }
    $remaining = max(0, (int)ceil(($te - $now) / 86400));
    return array_merge($base, ['label' => 'LIVE', 'cls' => 'green', 'duration_days' => $duration_days,
        'remaining_days' => $remaining,
        'headline' => 'Evaluation is currently accepting submissions.', 'sub' => $remaining . ' day' . ($remaining === 1 ? '' : 's') . ' remaining.']);
}

function ss_status(array $sys): array {
    $h = ss_health($sys);
    $map = ['green' => 'open', 'red' => 'closed', 'yellow' => 'amber', 'gray' => 'gray'];
    $label = $h['label'];
    if ($label === 'LIVE') $label = 'OPEN';
    if ($label === 'LIVE · FORCED OPEN') $label = 'FORCED OPEN';
    if ($label === 'CLOSED · FORCED') $label = 'FORCED CLOSED';
    if ($label === 'SCHEDULED') $label = 'UPCOMING';
    if ($label === 'CLOSED') $label = 'CLOSED · ENDED';
    if ($label === 'DRAFT') $label = 'DRAFT · HIDDEN';
    return ['label' => $label, 'cls' => $map[$h['cls']] ?? 'gray'];
}

// Plain-language headline + sub-message any dashboard can show, keyed off
// the same 5 states called out for the Dean dashboard (Scheduled / Open /
// Closed / Force Open / Force Closed) plus the extra system-level states
// (Draft / Maintenance / Not Configured) that can also legitimately occur.
function ss_status_message(array $health): array {
    switch ($health['label']) {
        case 'LIVE':
            return ['headline' => 'Evaluation is currently open.', 'sub' => $health['remaining_days'] . ' day' . ($health['remaining_days'] === 1 ? '' : 's') . ' remaining.'];
        case 'LIVE · FORCED OPEN':
            return ['headline' => 'Evaluation has been manually opened by the Executive Assistant.', 'sub' => 'Accepting submissions regardless of the schedule.'];
        case 'CLOSED · FORCED':
            return ['headline' => 'Evaluation has been temporarily closed by the Executive Assistant.', 'sub' => 'Blocking submissions regardless of the schedule.'];
        case 'SCHEDULED':
            return ['headline' => 'Evaluation has not started.', 'sub' => 'Opens in ' . $health['days_until_start'] . ' day' . ($health['days_until_start'] === 1 ? '' : 's') . '.'];
        case 'CLOSED':
            return ['headline' => 'Evaluation period has ended.', 'sub' => 'Submissions are no longer accepted.'];
        case 'DRAFT':
            return ['headline' => 'Evaluation is saved as a draft.', 'sub' => 'Not visible yet.'];
        case 'MAINTENANCE':
            return ['headline' => 'System is in maintenance mode.', 'sub' => 'Access is temporarily locked.'];
        default:
            return ['headline' => $health['headline'] ?? 'Evaluation status unavailable.', 'sub' => $health['sub'] ?? ''];
    }
}

// ── SELF-HEALING SCHEMA ──────────────────────────────────────────────────
// `evaluation_periods.semester` was created as ENUM('1st Semester','2nd
// Semester','Summer') — it predates JHS/SHS ever being selectable, so it
// has no 'School Year' member. Widen it idempotently, same pattern as the
// ALTER-TABLE-on-load already used in dean_dashboard.php.
function ss_ensure_schema(mysqli $mysqli): void {
    $res = @$mysqli->query("SHOW COLUMNS FROM evaluation_periods LIKE 'semester'");
    $col = $res ? $res->fetch_assoc() : null;
    if ($col && strpos($col['Type'], "'School Year'") === false) {
        @$mysqli->query("ALTER TABLE evaluation_periods MODIFY semester ENUM('1st Semester','2nd Semester','Summer','School Year') NOT NULL");
    }
}

// ── SYNC BRIDGE: system_settings  →  evaluation_periods ────────────────
// Call this once after a "System & Period" settings save. Returns the
// evaluation_periods.id that is now the single active row (or null if the
// current settings resolve to nothing being open/scheduled at all).
function ss_sync_evaluation_period(mysqli $mysqli, array $sys): ?int {
    ss_ensure_schema($mysqli);

    $health = ss_health($sys);
    $shouldBeOpen = in_array($health['label'], ['LIVE', 'LIVE · FORCED OPEN'], true);

    $year = $sys['acad_year'] !== '' ? $sys['acad_year'] : ss_defaults()['acad_year'];
    $term = $sys['acad_term'];
    $label = trim($year . ' — ' . $term);
    $start = $sys['eval_start'] ? date('Y-m-d', strtotime($sys['eval_start'])) : null;
    $end   = $sys['eval_end']   ? date('Y-m-d', strtotime($sys['eval_end']))   : null;

    $stmt = $mysqli->prepare("SELECT id FROM evaluation_periods WHERE school_year=? AND semester=? LIMIT 1");
    $stmt->bind_param("ss", $year, $term);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $active = $shouldBeOpen ? 1 : 0;

    if ($row) {
        $id = (int)$row['id'];
        $upd = $mysqli->prepare("UPDATE evaluation_periods SET period_label=?, date_start=?, date_end=?, is_active=? WHERE id=?");
        $upd->bind_param("sssii", $label, $start, $end, $active, $id);
        $upd->execute();
        $upd->close();
    } else {
        $ins = $mysqli->prepare("INSERT INTO evaluation_periods (period_label, school_year, semester, date_start, date_end, is_active) VALUES (?,?,?,?,?,?)");
        $ins->bind_param("sssssi", $label, $year, $term, $start, $end, $active);
        $ins->execute();
        $id = $mysqli->insert_id;
        $ins->close();
    }

    // Enforce a single active row so every dashboard's
    // `WHERE is_active=1 LIMIT 1` keeps meaning exactly what it always has.
    if ($shouldBeOpen) {
        $mysqli->query("UPDATE evaluation_periods SET is_active=0 WHERE id<>" . (int)$id);
    } else {
        $mysqli->query("UPDATE evaluation_periods SET is_active=0 WHERE id=" . (int)$id);
    }

    return $shouldBeOpen ? $id : null;
}

function ss_active_period_id(mysqli $mysqli): ?int {
    $period = ems_get_active_period($mysqli);
    return $period ? (int)$period['id'] : null;
}

// ── GENERIC SCHEDULE-DRIVEN NOTIFICATIONS ───────────────────────────────
// Shared across dashboards. Role-specific notices (e.g. "Engineering has
// pending submissions") stay in each dashboard, since only that dashboard
// knows its own analytics.
function ss_schedule_notifications(array $health): array {
    $out = [];
    if ($health['label'] === 'LIVE' && $health['remaining_days'] !== null && $health['remaining_days'] <= 2) {
        $out[] = 'Evaluation closes in ' . $health['remaining_days'] . ' day' . ($health['remaining_days'] === 1 ? '' : 's') . '.';
    }
    if ($health['label'] === 'SCHEDULED' && $health['days_until_start'] !== null && $health['days_until_start'] <= 1) {
        $out[] = 'Evaluation opens ' . ($health['days_until_start'] === 1 ? 'tomorrow' : 'today') . '.';
    }
    if ($health['label'] === 'CLOSED · FORCED') {
        $out[] = 'Evaluation has been manually closed by the Executive Assistant.';
    }
    if ($health['label'] === 'LIVE · FORCED OPEN') {
        $out[] = 'Evaluation has been manually opened by the Executive Assistant.';
    }
    return $out;
}

// ── MAIN ENTRY POINT ─────────────────────────────────────────────────────
// This is the ONLY function most dashboards should ever need to call.
function get_system_settings(mysqli $mysqli): array {
    $sys    = ss_raw($mysqli);
    $health = ss_health($sys);
    $status = ss_status($sys);
    $msg    = ss_status_message($health);

    return [
        'raw'                      => $sys, // escape hatch for anything not modeled below
        'academic_year'            => $sys['acad_year'],
        'academic_structure'       => $sys['acad_structure'],
        'academic_structure_label' => ss_structure_labels()[$sys['acad_structure']] ?? 'College',
        'academic_term'            => $sys['acad_term'],
        'eval_start'               => $sys['eval_start'],
        'eval_end'                 => $sys['eval_end'],
        'status'                   => $status,   // ['label','cls'] — short badge
        'health'                   => $health,   // richer detail — durations, counts
        'message'                  => $msg,      // ['headline','sub'] — plain language
        'countdown_enabled'        => !empty($sys['rule_countdown']),
        'is_open_for_submission'   => in_array($health['label'], ['LIVE', 'LIVE · FORCED OPEN'], true),
        'notifications'            => ss_schedule_notifications($health),
        'period_id'                => ss_active_period_id($mysqli), // the id evaluation_tracker.period_id joins against
    ];
}

} // SYSTEM_SETTINGS_SERVICE_LOADED guard
<?php
/*
 * ══════════════════════════════════════════════════════════════════════
 * shared/ea_personnel_service.php
 * ══════════════════════════════════════════════════════════════════════
 * Single source of truth for reading Faculty / Staff / Executive
 * Assistant / Student data, per Dean Module refactor Step 17. Used by
 * dean_dashboard.php and dean_evaluation.php (and anywhere else in the
 * Dean module that needs a roster) so there is exactly one query per
 * role, not one per page.
 *
 * There is no separate "Personnel Registry" table — confirmed against
 * admin/manage_privileged_accounts.php: Executive Assistant, Faculty
 * (role='teacher'), Staff, Student, Principal, and Dean accounts all
 * live in the single `users` table, gated by role + account_status
 * ('pending'/'approved'/'blocked') + is_active. College scoping for
 * Faculty/Staff comes from the `user_year_levels` junction table.
 *
 * Confirmed decisions (do not re-derive):
 *   - Student college status uses `education_level = 'college'`, NOT
 *     the `year_level` pattern-matching used elsewhere in
 *     manage_privileged_accounts.php for its own filter UI.
 *   - "Executive Assistant" means role='executive_assistant'
 *     specifically (the main/system-admin-facing account type) — NOT
 *     'superadmin', which gates the admin page itself and is never
 *     evaluated.
 *   - "Included/visible in evaluation" has no dedicated column yet;
 *     account_status='approved' AND is_active=1 stands in for it.
 *
 * Still open (do not guess further — surface to the user instead):
 *   - No "evaluation_status" / "last_evaluation_date" column exists for
 *     Faculty/Staff/EA. That's the DEAN's own evaluation of them
 *     (Step 10) — a different flow from the student->teacher
 *     evaluations the original dashboard tracked via evaluation_tracker
 *     (eval_type='student'). Every row defaults to 'not_started' until
 *     the eval_type/table for Dean-initiated evaluations is confirmed.
 *   - "Program" isn't a column on teacher/staff rows (only `course` on
 *     students) — left null pending confirmation of where Faculty
 *     program assignment lives.
 *
 *   - ⚠ ROLE MISMATCH FOR EXECUTIVE ASSISTANT (found while fixing the
 *     dean_evaluate.php routing bug — not yet resolved, needs a human
 *     to confirm against the real DB): this file's ea_get_executive_
 *     assistants() below queries WHERE role = 'executive_assistant'.
 *     But dean_evaluate.php's $tabConfig for the 'executive_assistant'
 *     tab looks the target person up with role = 'system_admin', and
 *     dean_evaluation.php has a comment claiming EA accounts are
 *     "stored with role='system_admin'". Only one of these can match
 *     the actual users.role column. If the true value is
 *     'system_admin', ea_get_executive_assistants() below is silently
 *     returning zero rows (or the wrong rows) today. If the true value
 *     is 'executive_assistant', then dean_evaluate.php's target lookup
 *     for that tab will always 404 with "Person not found" even after
 *     the routing fix. Check the real column value and make both files
 *     agree — this doc-comment shouldn't be treated as the tiebreaker,
 *     since it's the same kind of unverified claim as the other file's.
 * ══════════════════════════════════════════════════════════════════════
 */

if (!defined('COLLEGE_LEVELS')) {
    define('COLLEGE_LEVELS', ['1st Year College', '2nd Year College', '3rd Year College', '4th Year College']);
}

/** Shared row-shape helper: everyone gets evaluation_status defaulted
 *  to 'not_started' until Dean-initiated evaluation tracking exists. */
function ea_rows(mysqli $mysqli, string $sql, string $types, array $params): array {
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    foreach ($rows as &$r) {
        $r['evaluation_status']    = $r['evaluation_status'] ?? 'not_started'; // TODO(EA-integration): Dean-evaluation tracker
        $r['last_evaluation_date'] = $r['last_evaluation_date'] ?? null;
    }
    return $rows;
}

/** Faculty = role='teacher', approved+active, scoped to College via
 *  user_year_levels. "Program" isn't in the schema for teacher/staff
 *  (course is student-only) — left null pending confirmation. */
function ea_get_faculty(mysqli $mysqli, int $periodId): array {
    $ph = implode(',', array_fill(0, count(COLLEGE_LEVELS), '?'));
    return ea_rows($mysqli, "
        SELECT DISTINCT u.id, u.full_name, u.photo, u.department, u.designation AS position, NULL AS program
        FROM users u
        WHERE u.role = 'teacher' AND u.account_status = 'approved' AND u.is_active = 1
          AND EXISTS (SELECT 1 FROM user_year_levels uyl WHERE uyl.user_id = u.id AND uyl.year_level IN ($ph))
        ORDER BY u.full_name ASC
    ", str_repeat('s', count(COLLEGE_LEVELS)), COLLEGE_LEVELS);
}

/** Staff = approved and active staff; no College year-level requirement. */
function ea_get_staff(mysqli $mysqli, int $periodId): array {
    return ea_rows($mysqli, "
        SELECT DISTINCT u.id, u.full_name, u.photo, u.department, u.designation AS position
        FROM users u
        WHERE u.role = 'staff'
          AND u.account_status = 'approved'
          AND u.is_active = 1
        ORDER BY u.full_name ASC
    ", '', []);
}
/** Executive Assistant = role='executive_assistant' specifically (the
 *  main/system-admin account type) — not 'superadmin'. No College
 *  scoping; EAs aren't attached to a division.
 *
 *  ⚠ See the role-mismatch note in the file header — verify this
 *  role value against dean_evaluate.php's $tabConfig before relying
 *  on either. */
function ea_get_executive_assistants(mysqli $mysqli, int $periodId): array {
    return ea_rows($mysqli, "
        SELECT id, full_name, photo, designation AS position, role AS system_role
        FROM users
        WHERE role = 'executive_assistant' AND account_status = 'approved' AND is_active = 1
        ORDER BY full_name ASC
    ", '', []);
}

/**
 * Read-only — the Dean never stores or duplicates student records
 * (Step 7). Used only for the Higher Ed Student Participation card.
 * Filters on education_level (confirmed authoritative column), not the
 * year_level pattern-matching used in manage_privileged_accounts.php.
 */
function ea_get_students(mysqli $mysqli, int $periodId): array {
    return ea_rows($mysqli, "
        SELECT id, full_name
        FROM users
        WHERE role = 'student' AND account_status = 'approved' AND is_active = 1
          AND education_level = 'college'
    ", '', []);
}

/**
 * Builds the link to dean_evaluate.php for a given roster row.
 *
 * dean_evaluate.php (as of the Step-17 rewrite) routes purely on
 * ?tab=<faculty|staff|executive_assistant>&user_id=<id> — it does not
 * read a `form` parameter at all. $role here is always the same $tab
 * value dean_evaluation.php is already using ('faculty' | 'staff' |
 * 'executive_assistant'), so it's passed straight through instead of
 * mapped through a separate form-name lookup — that mapping was the
 * source of the "Unknown evaluation type." bug: this function was
 * still emitting the old form=faculty_evaluation_form style URL that
 * dean_evaluate.php stopped understanding once it was rewritten to
 * read ?tab=. One less place for the two files to drift out of sync.
 *
 * Role must be one of: 'faculty', 'staff', 'executive_assistant'.
 */
function ea_questionnaire_route(string $role, int $targetUserId): string {
    $validTabs = ['faculty', 'staff', 'executive_assistant'];
    $tab = in_array($role, $validTabs, true) ? $role : 'faculty';
    return "dean_evaluate.php?tab={$tab}&user_id={$targetUserId}";
}
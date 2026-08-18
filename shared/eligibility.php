<?php
/**
 * eligibility.php
 *
 * Independent eligibility checks for evaluation_tracker submissions.
 */

/**
 * canStudentEvaluate()
 *
 * Student evaluating a teacher/staff member.
 */
function canStudentEvaluate(mysqli $mysqli, int $studentId, int $targetUserId): array {
    // 1. Get the student's own education_level and year_level.
    //    The live users table does not contain a section column.
    $stmt = $mysqli->prepare(
        "SELECT education_level, year_level
         FROM users
         WHERE id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return [false, 'Unable to verify your student profile.'];
    }

    $stmt->bind_param("i", $studentId);
    $stmt->execute();

    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (
        !$student ||
        empty($student['education_level']) ||
        empty($student['year_level'])
    ) {
        return [
            false,
            'Your year level is not set on your profile. Please contact the registrar before submitting an evaluation.'
        ];
    }

    $studentLevel = $student['education_level'];
    $studentYear  = $student['year_level'];

    // education_level VOCABULARY MISMATCH: users.education_level stores
    // slugs ('junior_high', 'senior_high', 'elementary', 'college'), but
    // teaching_assignments.education_level stores admin-facing labels
    // ('Basic Education', 'College'). They must be normalized to the same
    // bucket before comparing -- this mirrors the mapping used in
    // student_dashboard.php's display query, so a person who is shown as
    // eligible there is never rejected here, and vice versa.
    $eduBucketMap = [
        'elementary'           => ['basic education'],
        'junior_high'          => ['basic education'],
        'senior_high'          => ['basic education'],
        'basic education'      => ['basic education'],
        'college'              => ['college', 'higher education', 'college / university', 'college/university'],
        'higher education'     => ['college', 'higher education', 'college / university', 'college/university'],
        'college / university' => ['college', 'higher education', 'college / university', 'college/university'],
        'college/university'   => ['college', 'higher education', 'college / university', 'college/university'],
    ];
    $levelKey      = strtolower(trim($studentLevel));
    $levelVariants = $eduBucketMap[$levelKey] ?? [$levelKey];

    // 2. Confirm the target is a real, active teacher/staff account.
    $stmt = $mysqli->prepare(
        "SELECT role, is_active
         FROM users
         WHERE id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return [false, 'Unable to verify the selected evaluator target.'];
    }

    $stmt->bind_param("i", $targetUserId);
    $stmt->execute();

    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$target) {
        return [false, 'The selected faculty/staff member could not be found.'];
    }

    if (empty($target['is_active'])) {
        return [false, 'This account is not currently active.'];
    }

    if (!in_array($target['role'], ['teacher', 'staff'], true)) {
        return [false, 'Student evaluations can only be submitted for faculty or staff members.'];
    }

    // 3. The target must be assigned to this student's year level via
    //    EITHER source -- exactly the same two sources and OR logic the
    //    Evaluate list itself uses:
    //      - teaching_assignments: education_level (normalized) + year_level
    //      - user_year_levels: year_level only (how non-teaching staff,
    //        e.g. Registrar/Cashier/Librarian/Nurse, get scoped -- they
    //        never get a teaching_assignments row)
    //    There is no blanket "staff with no teaching assignments are
    //    eligible for everyone" case -- that would let a student submit
    //    for staff who were never actually shown to them, and it isn't
    //    what "assigned to your year level" is supposed to mean.
    $placeholders = implode(',', array_fill(0, count($levelVariants), '?'));

    $stmt = $mysqli->prepare(
        "SELECT
            EXISTS(
                SELECT 1 FROM teaching_assignments ta
                WHERE ta.user_id = ?
                  AND LOWER(TRIM(ta.education_level)) IN ($placeholders)
                  AND LOWER(TRIM(ta.year_level)) = LOWER(TRIM(?))
            )
            OR
            EXISTS(
                SELECT 1 FROM user_year_levels uyl
                WHERE uyl.user_id = ?
                  AND LOWER(TRIM(uyl.year_level)) = LOWER(TRIM(?))
            )
         AS is_assigned"
    );

    if (!$stmt) {
        return [false, 'Unable to verify the teaching assignment.'];
    }

    $params = array_merge(
        [$targetUserId],
        $levelVariants,
        [$studentYear, $targetUserId, $studentYear]
    );
    $types = 'i' . str_repeat('s', count($levelVariants)) . 's' . 'i' . 's';

    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    $row       = $stmt->get_result()->fetch_assoc();
    $isAssigned = (bool)($row['is_assigned'] ?? false);
    $stmt->close();

    if (!$isAssigned) {
        return [
            false,
            'You are not eligible to evaluate this faculty/staff member because they are not assigned to your education level and year level.'
        ];
    }

    return [true, $studentLevel];
}

/**
 * canPeerEvaluate()
 *
 * Faculty/staff evaluating another faculty/staff member (eval_type = 'peer').
 * Deliberately has NO education-level restriction — per requirements, faculty
 * and staff can evaluate anyone they've worked with or encountered at school,
 * regardless of which level(s) either of them is assigned to. This only
 * guards against basic nonsense: evaluating yourself, or a target that isn't
 * actually a faculty/staff account.
 *
 * Usage:
 *   [$ok, $result] = canPeerEvaluate($mysqli, $evaluatorId, $targetUserId);
 *   if (!$ok) { // $result is the error message }
 */
function canPeerEvaluate(mysqli $mysqli, int $evaluatorId, int $targetUserId): array {
    if ($evaluatorId === $targetUserId) {
        return [false, 'You cannot submit a peer evaluation for yourself.'];
    }

    $stmt = $mysqli->prepare("SELECT id, role FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $targetUserId);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$target) {
        return [false, 'The selected colleague could not be found.'];
    }
    if (!in_array($target['role'], ['teacher', 'staff'], true)) {
        return [false, 'Peer evaluations can only be submitted for faculty or staff members.'];
    }

    return [true, null];
}

/**
 * canSchoolHeadEvaluate()
 *
 * School Head evaluating a faculty/staff member (eval_type = 'school_head').
 * Like canPeerEvaluate(), deliberately has NO education-level restriction —
 * the School Head evaluates faculty and staff system-wide, not scoped to a
 * particular year level. Only guards against a missing/inactive/unapproved
 * target, or a target that isn't actually a faculty/staff account.
 *
 * Usage:
 *   [$ok, $result] = canSchoolHeadEvaluate($mysqli, $schoolHeadId, $targetUserId);
 *   if (!$ok) { // $result is the error message }
 */
function canSchoolHeadEvaluate(mysqli $mysqli, int $schoolHeadId, int $targetUserId): array {
    $stmt = $mysqli->prepare("SELECT id, role, is_active, account_status FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $targetUserId);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$target) {
        return [false, 'The selected personnel record could not be found.'];
    }
    if (!in_array($target['role'], ['teacher', 'staff'], true)) {
        return [false, 'School Head evaluations can only be submitted for faculty or staff members.'];
    }
    if (empty($target['is_active']) || $target['account_status'] !== 'approved') {
        return [false, 'This account is not currently active/approved and cannot be evaluated.'];
    }

    return [true, null];
}
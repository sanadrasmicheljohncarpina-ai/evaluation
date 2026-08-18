<?php
// shared/EvaluationAnalyticsService.php
//
// Analytics helpers for the Principal & Dean dashboards, built against:
//   evaluation_tracker      - one row per evaluation record (evaluator -> target)
//   questionnaire_answers   - per-question responses, linked via tracker_id
//   evaluation_periods      - school year / semester windows (is_active flag)
//   users                   - accounts (role, account_status, education_level, department)
//
// Every function takes $mysqli as the first argument, same pattern as
// AuthenticationService.php — no connection handling in here.
//
// ASSUMPTIONS BAKED IN (flag any of these if they're wrong so I can adjust):
//   1. "Expected" evaluator pool = active users (account_status = 'approved')
//      whose role/education_level/department match the scope being measured.
//      This was your call in our last exchange.
//   2. A tracker row counts as "done" when status IN ('submitted','approved').
//      'draft' and 'archived' rows don't count toward completion/participation.
//   3. Principal scope = education_level IN ('junior_high','senior_high').
//      Dean scope = education_level = 'college', optionally narrowed by department.
//      (I haven't seen how Principal/Dean pages currently filter by level —
//      if there's an existing convention elsewhere in the app, tell me and
//      I'll match it instead of this assumption.)
//   4. One evaluator can submit multiple tracker rows per period (different
//      targets/forms), so participation counts DISTINCT evaluator_id, not
//      row count. Completion, by contrast, is measured per tracker row
//      (i.e. "of the evaluations that exist, how many are finalized"),
//      since that's the only completion signal evaluation_tracker.status gives us.

/**
 * Fetch the currently active evaluation period, if any.
 */
function ems_get_active_period(mysqli $mysqli): ?array
{
    $res = $mysqli->query(
        "SELECT * FROM evaluation_periods WHERE is_active = 1 ORDER BY id DESC LIMIT 1"
    );
    return ($res && $res->num_rows) ? $res->fetch_assoc() : null;
}

/**
 * Fetch a specific evaluation period by id (e.g. for a period-switcher dropdown).
 */
function ems_get_period(mysqli $mysqli, int $periodId): ?array
{
    $stmt = $mysqli->prepare("SELECT * FROM evaluation_periods WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $periodId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * List all evaluation periods, most recent first (for a dashboard dropdown).
 */
function ems_list_periods(mysqli $mysqli): array
{
    $res = $mysqli->query("SELECT * FROM evaluation_periods ORDER BY id DESC");
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    return $rows;
}

/**
 * Count of active users matching a role/level/department scope — this is
 * the "expected" denominator used by ems_participation_rate().
 *
 * @param array $filters Optional keys: role, education_level (string or array), department
 */
function ems_count_active_users(mysqli $mysqli, array $filters = []): int
{
    $where = ["account_status = 'approved'"];
    $types = "";
    $params = [];

    if (!empty($filters['role'])) {
        $where[] = "role = ?";
        $types .= "s";
        $params[] = $filters['role'];
    }
    if (!empty($filters['education_level'])) {
        if (is_array($filters['education_level'])) {
            $placeholders = implode(',', array_fill(0, count($filters['education_level']), '?'));
            $where[] = "education_level IN ($placeholders)";
            foreach ($filters['education_level'] as $lvl) {
                $types .= "s";
                $params[] = $lvl;
            }
        } else {
            $where[] = "education_level = ?";
            $types .= "s";
            $params[] = $filters['education_level'];
        }
    }
    // Faculty/staff college-scoping does NOT live in a flat column on
    // `users` (there is no populated academic_level/education_level for
    // role='teacher'/'staff' — confirmed via GROUP BY on the live DB).
    // It lives in the user_year_levels junction table, written by the
    // Super Admin's "Assign Year Levels" action in
    // manage_privileged_accounts.php. 'college' expands to the four
    // college year_level strings; any other value is matched exactly,
    // so a single grade (e.g. 'Grade 11') also works as a filter.
    if (!empty($filters['academic_level'])) {
        $collegeLevels = ['1st Year College','2nd Year College','3rd Year College','4th Year College'];
        $levels = ($filters['academic_level'] === 'college') ? $collegeLevels : [$filters['academic_level']];
        $placeholders = implode(',', array_fill(0, count($levels), '?'));
        $where[] = "EXISTS (SELECT 1 FROM user_year_levels uyl WHERE uyl.user_id = users.id AND uyl.year_level IN ($placeholders))";
        foreach ($levels as $lvl) {
            $types .= "s";
            $params[] = $lvl;
        }
    }
    if (!empty($filters['department'])) {
        $where[] = "department = ?";
        $types .= "s";
        $params[] = $filters['department'];
    }
    if (!empty($filters['course'])) {
        $where[] = "course = ?";
        $types .= "s";
        $params[] = $filters['course'];
    }

    $sql = "SELECT COUNT(*) AS c FROM users WHERE " . implode(' AND ', $where);
    $stmt = $mysqli->prepare($sql);
    if ($types !== "") {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
}

/**
 * Participation rate: distinct evaluators who have a submitted/approved
 * tracker row in this period (matching scope) vs the expected active-user
 * pool for that same scope.
 *
 * @param array $scope Optional keys: level, evaluator_department, evaluator_course,
 *   eval_bucket, eval_type (e.g. 'student' to count only student evaluators),
 *   evaluator_role (e.g. 'student' — scopes the expected/denominator pool;
 *   without it, 'expected' counts every active user at that education_level,
 *   not just the role actually being measured)
 * @return array{actual: int, expected: int, percent: float}
 */
function ems_participation_rate(mysqli $mysqli, int $periodId, array $scope = []): array
{
    $where = ["t.period_id = ?", "t.status IN ('submitted','approved')"];
    $types = "i";
    $params = [$periodId];
    $join = "";

    if (!empty($scope['level'])) {
        $where[] = "t.level = ?";
        $types .= "s";
        $params[] = $scope['level'];
    }
    if (!empty($scope['evaluator_department'])) {
        $where[] = "t.evaluator_department = ?";
        $types .= "s";
        $params[] = $scope['evaluator_department'];
    }
    if (!empty($scope['eval_bucket'])) {
        $where[] = "t.eval_bucket = ?";
        $types .= "s";
        $params[] = $scope['eval_bucket'];
    }
    if (!empty($scope['eval_type'])) {
        $where[] = "t.eval_type = ?";
        $types .= "s";
        $params[] = $scope['eval_type'];
    }
    // evaluation_tracker has no evaluator_course column (only
    // evaluator_department), so a course scope has to join back to users.
    if (!empty($scope['evaluator_course'])) {
        $join = "JOIN users eu ON eu.id = t.evaluator_id";
        $where[] = "eu.course = ?";
        $types .= "s";
        $params[] = $scope['evaluator_course'];
    }

    $sql = "SELECT COUNT(DISTINCT t.evaluator_id) AS c
            FROM evaluation_tracker t
            $join
            WHERE " . implode(' AND ', $where);
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $actual = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    $expected = ems_count_active_users($mysqli, [
        'role'             => $scope['evaluator_role'] ?? null,
        'education_level'  => $scope['level'] ?? null,
        'department'       => $scope['evaluator_department'] ?? null,
        'course'           => $scope['evaluator_course'] ?? null,
    ]);

    return [
        'actual'   => $actual,
        'expected' => $expected,
        'percent'  => $expected > 0 ? round(($actual / $expected) * 100, 1) : 0.0,
    ];
}

/**
 * Target-side completion: of the ACTIVE users matching a role/level/
 * department/course scope, how many have received at least one
 * submitted/approved evaluation this period. This is a different question
 * from ems_completion_rate() (which measures tracker ROWS, i.e. "of the
 * evaluations that exist, how many are finalized") — this measures PEOPLE,
 * i.e. "of the faculty who should be getting evaluated, how many actually
 * have been." That's what a Dean's Program/Department Analytics and
 * Faculty Evaluation Completion cards need.
 *
 * @param array $scope Optional keys: role, education_level (students),
 *   academic_level (faculty/staff — same users table, different column),
 *   department, course
 * @return array{completed: int, total: int, percent: float}
 */
function ems_target_completion_rate(mysqli $mysqli, int $periodId, array $scope = []): array
{
    $where = ["t.period_id = ?", "t.status IN ('submitted','approved')"];
    $types = "i";
    $params = [$periodId];
    $join = "JOIN users u ON u.id = t.target_user_id";

    if (!empty($scope['role'])) {
        $where[] = "u.role = ?";
        $types .= "s";
        $params[] = $scope['role'];
    }
    if (!empty($scope['education_level'])) {
        $where[] = "u.education_level = ?";
        $types .= "s";
        $params[] = $scope['education_level'];
    }
    if (!empty($scope['academic_level'])) {
        // Same fix as ems_count_active_users(): college-scoping for
        // teacher/staff comes from user_year_levels, not a flat column.
        $collegeLevels = ['1st Year College','2nd Year College','3rd Year College','4th Year College'];
        $levels = ($scope['academic_level'] === 'college') ? $collegeLevels : [$scope['academic_level']];
        $placeholders = implode(',', array_fill(0, count($levels), '?'));
        $where[] = "EXISTS (SELECT 1 FROM user_year_levels uyl WHERE uyl.user_id = u.id AND uyl.year_level IN ($placeholders))";
        foreach ($levels as $lvl) {
            $types .= "s";
            $params[] = $lvl;
        }
    }
    if (!empty($scope['department'])) {
        $where[] = "u.department = ?";
        $types .= "s";
        $params[] = $scope['department'];
    }
    if (!empty($scope['course'])) {
        $where[] = "u.course = ?";
        $types .= "s";
        $params[] = $scope['course'];
    }

    $sql = "SELECT COUNT(DISTINCT t.target_user_id) AS c
            FROM evaluation_tracker t
            $join
            WHERE " . implode(' AND ', $where);
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $completed = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    $total = ems_count_active_users($mysqli, [
        'role'             => $scope['role'] ?? null,
        'education_level'  => $scope['education_level'] ?? null,
        'academic_level'   => $scope['academic_level'] ?? null,
        'department'       => $scope['department'] ?? null,
        'course'           => $scope['course'] ?? null,
    ]);

    return [
        'completed' => $completed,
        'total'     => $total,
        'percent'   => $total > 0 ? round(($completed / $total) * 100, 1) : 0.0,
    ];
}

/**
 * Average score on the EVALUATOR side of a scope — e.g. "average score
 * given by students in this program." Complements ems_evaluatee_summary(),
 * which averages per person being evaluated rather than per evaluator group.
 *
 * Reads from questionnaire_answers (per-question scores), not
 * evaluation_tracker.score — that column is never populated by the
 * student submission handler, so AVG(t.score) always returns null.
 *
 * @param array $scope Optional keys: evaluator_course, evaluator_department, level, eval_bucket
 */
function ems_average_score(mysqli $mysqli, int $periodId, array $scope = []): ?float
{
    $where = ["t.period_id = ?", "t.status IN ('submitted','approved')"];
    $types = "i";
    $params = [$periodId];
    $join = "";

    if (!empty($scope['level'])) {
        $where[] = "t.level = ?";
        $types .= "s";
        $params[] = $scope['level'];
    }
    if (!empty($scope['evaluator_department'])) {
        $where[] = "t.evaluator_department = ?";
        $types .= "s";
        $params[] = $scope['evaluator_department'];
    }
    if (!empty($scope['eval_bucket'])) {
        $where[] = "t.eval_bucket = ?";
        $types .= "s";
        $params[] = $scope['eval_bucket'];
    }
    if (!empty($scope['eval_type'])) {
        $where[] = "t.eval_type = ?";
        $types .= "s";
        $params[] = $scope['eval_type'];
    }
    if (!empty($scope['evaluator_course'])) {
        $join .= " JOIN users eu ON eu.id = t.evaluator_id";
        $where[] = "eu.course = ?";
        $types .= "s";
        $params[] = $scope['evaluator_course'];
    }

    $sql = "SELECT AVG(qa.answer_score) AS v
            FROM evaluation_tracker t
            JOIN questionnaire_answers qa ON qa.tracker_id = t.id
            $join
            WHERE " . implode(' AND ', $where);
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row && $row['v'] !== null ? round((float)$row['v'], 2) : null;
}

/**
 * Completion %: of the tracker rows that exist for this period (matching
 * scope), what share are submitted/approved rather than sitting in draft.
 *
 * @param array $scope Optional keys: level, eval_bucket
 * @return array{completed: int, total: int, percent: float}
 */
function ems_completion_rate(mysqli $mysqli, int $periodId, array $scope = []): array
{
    $where = ["period_id = ?"];
    $types = "i";
    $params = [$periodId];

    if (!empty($scope['level'])) {
        $where[] = "level = ?";
        $types .= "s";
        $params[] = $scope['level'];
    }
    if (!empty($scope['eval_bucket'])) {
        $where[] = "eval_bucket = ?";
        $types .= "s";
        $params[] = $scope['eval_bucket'];
    }

    $sql = "SELECT
                COUNT(*) AS total,
                SUM(status IN ('submitted','approved')) AS completed
            FROM evaluation_tracker
            WHERE " . implode(' AND ', $where);
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $total = (int)($row['total'] ?? 0);
    $completed = (int)($row['completed'] ?? 0);

    return [
        'completed' => $completed,
        'total'     => $total,
        'percent'   => $total > 0 ? round(($completed / $total) * 100, 1) : 0.0,
    ];
}

/**
 * Per-evaluatee summary for a period/scope: average score and number of
 * evaluations received. This is the "evaluatee summaries" table the
 * dashboards need — one row per person being evaluated.
 *
 * @param array $scope Optional keys: level, department, eval_bucket
 */
function ems_evaluatee_summary(mysqli $mysqli, int $periodId, array $scope = []): array
{
    $where = ["t.period_id = ?", "t.status IN ('submitted','approved')"];
    $types = "i";
    $params = [$periodId];

    if (!empty($scope['level'])) {
        $where[] = "t.level = ?";
        $types .= "s";
        $params[] = $scope['level'];
    }
    if (!empty($scope['department'])) {
        $where[] = "u.department = ?";
        $types .= "s";
        $params[] = $scope['department'];
    }
    if (!empty($scope['eval_bucket'])) {
        $where[] = "t.eval_bucket = ?";
        $types .= "s";
        $params[] = $scope['eval_bucket'];
    }

    $sql = "SELECT
                u.id, u.full_name, u.department, u.designation,
                COUNT(t.id) AS evaluations_received,
                ROUND(AVG(t.score), 2) AS average_score
            FROM evaluation_tracker t
            JOIN users u ON u.id = t.target_user_id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY u.id, u.full_name, u.department, u.designation
            ORDER BY u.full_name";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($r = $result->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
    return $rows;
}

/**
 * Per-question drill-down for a single evaluatee, pulled from
 * questionnaire_answers via the tracker rows that target them.
 * Useful for a "view details" click-through from the evaluatee summary table.
 */
function ems_evaluatee_question_breakdown(mysqli $mysqli, int $periodId, int $targetUserId): array
{
    $sql = "SELECT
                qa.question_id,
                ROUND(AVG(qa.answer_score), 2) AS average_score,
                COUNT(qa.id) AS response_count
            FROM questionnaire_answers qa
            JOIN evaluation_tracker t ON t.id = qa.tracker_id
            WHERE t.period_id = ? AND t.target_user_id = ? AND t.status IN ('submitted','approved')
            GROUP BY qa.question_id
            ORDER BY qa.question_id";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("ii", $periodId, $targetUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($r = $result->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
    return $rows;
}
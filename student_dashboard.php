<?php
// session_bootstrap.php — include this BEFORE session_start() everywhere
session_set_cookie_params([
    'lifetime' => 0,        // session cookie, dies when browser closes
    'path'     => '/',      // available across the whole site, not just /admin/
    'domain'   => '',       // let the browser infer it — avoids localhost vs IP mismatches
    'secure'   => false,    // set true only if you're on https
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
require_once 'db.php';
require_once '../shared/eligibility.php';
require_once '../shared/EvaluationContextService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: student_login.php"); exit;
}

$student_id   = $_SESSION['user_id'];
$student_name = $_SESSION['full_name'];

// ── FETCH STUDENT'S OWN PHOTO + EDUCATION LEVEL ───────────────
// Moved up here (was previously fetched further down, AFTER the
// submission handler) because the server-side eligibility re-check in
// the submission handler now needs $student_level/$student_year_level
// too -- it can no longer rely purely on shared/eligibility.php.
$phRes = $mysqli->prepare("SELECT photo, education_level, year_level FROM users WHERE id=? LIMIT 1");
$phRes->bind_param("i", $student_id);
$phRes->execute();
$phRow = $phRes->get_result()->fetch_assoc();
$phRes->close();
$student_photo      = $phRow['photo'] ?? '';
$student_level      = $phRow['education_level'] ?? null;
$student_year_level = $phRow['year_level'] ?? null;

// ── DESIGNATION TOKEN → ADMIN target_type MAPPING ─────────────
// Mirrors admin/questionnaire.php's 3-bucket model exactly:
//   - Teacher     -> shared pool in `evaluation_questions` (target_type='Teacher')
//   - Staff       -> PER-PERSON questions in `user_questions`
//   - Multi-Role  -> shared pool in `evaluation_questions`
//
// NOTE: admin/questionnaire.php's actual bucket name is 'Teacher', not
// 'Faculty' -- $system_categories there is ['Teacher','Staff','Multi-Role'],
// and every evaluation_questions row it writes uses target_type='Teacher'.
// This file previously used 'Faculty' as the bucket key/value throughout,
// which never matched any row in the table, so the questions lookup for
// every teacher always came back empty ("No questions have been set up").
//
// STUDENT-FACING DISPLAY CATEGORIES (distinct from the target_type bucket
// names above, which are only used for question lookups): the landing
// page groups evaluatees into "Faculty" (Teacher / Staff subsections),
// "Multi-Role", and "School Head".
//
// "Teacher" vs "Staff" is still resolved via resolveUserTarget()
// (designation-based), exactly as before. A Staff user with zero
// user_year_levels rows is non-teaching staff, eligible to every
// student, and stays under the plain "Staff" subsection. A Staff user
// with one or more user_year_levels rows (an active teaching
// assignment) is pulled OUT of Staff entirely and shown under the
// separate "Multi-Role" category instead, scoped to only the matching
// year level(s) -- see the GROUP INTO STUDENT-FACING CATEGORIES block
// further down for the actual rule, and get_questions below for why
// Multi-Role people get the Multi-Role questionnaire exclusively
// (not the ordinary per-person Staff one).
$token_to_target = [
    'Teacher'         => 'Teacher',
    'Faculty'         => 'Teacher',
    'Adviser'         => 'Teacher',
    'Coordinator'     => 'Teacher',
    'Department Head' => 'Teacher',
    'Registrar'       => 'Staff',
    'Cashier'         => 'Staff',
    'Bookkeeper'      => 'Staff',
    'Librarian'       => 'Staff',
    'Guidance'        => 'Staff',
    'Nurse'           => 'Staff',
    'Personnel'       => 'Staff',
    'Staff'           => 'Staff',
];
$keyword_to_target = [
    'registrar'   => 'Staff',
    'cashier'     => 'Staff',
    'bookkeeper'  => 'Staff',
    'librarian'   => 'Staff',
    'guidance'    => 'Staff',
    'nurse'       => 'Staff',
    'teacher'     => 'Teacher',
    'faculty'     => 'Teacher',
    'instructor'  => 'Teacher',
    'professor'   => 'Teacher',
    'adviser'     => 'Teacher',
    'advisor'     => 'Teacher',
    'coordinator' => 'Teacher',
    'head'        => 'Teacher',
    'principal'   => 'Teacher',
    'dean'        => 'Teacher',
    'tutor'       => 'Teacher',
];

// Resolve a single designation token to 'Teacher' or 'Staff'.
// Identical logic to admin/questionnaire.php's resolveTarget().
function resolveTarget($raw_token, $token_to_target, $keyword_to_target, $role = 'teacher') {
    $raw_token = trim($raw_token);
    if (isset($token_to_target[$raw_token])) return $token_to_target[$raw_token];
    $lower = strtolower($raw_token);
    foreach ($keyword_to_target as $keyword => $mapped) {
        if (strpos($lower, $keyword) !== false) return $mapped;
    }
    return ($role === 'teacher') ? 'Teacher' : 'Staff';
}

// Resolve a user's designation string (possibly comma-separated) down
// to their PRIMARY bucket -- 'Faculty' or 'Staff' -- using the first
// recognizable token. Identical to admin's per-user resolution.
function resolveUserTarget($designation, $role, $token_to_target, $keyword_to_target) {
    $raw = trim($designation ?? '');
    if ($raw === '') $raw = ($role === 'teacher') ? 'Teacher' : 'Personnel';
    $tokens = array_filter(array_map('trim', preg_split('/\\s*[,\\/|;]+\\s*/', $raw)), fn($t) => $t !== '');
    if (empty($tokens)) $tokens = [$raw];
    return resolveTarget($tokens[0], $token_to_target, $keyword_to_target, $role);
}

function userHasAdditionalRole(array $u): bool {
    return ec_has_additional_role($u);
}

// ── ROLE / ASSIGNMENT-BASED ELIGIBILITY ────────────────────────
// Base Teacher/Staff visibility and Multi-Role visibility are deliberately
// independent:
//   • Teacher/teaching staff -> only matching teaching assignments.
//   • Staff with no teaching assignment -> institution-wide Staff context.
//   • Staff with a teaching assignment -> Staff context only at matching levels.
//   • Multi-Role -> always visible across applicable year levels when the
//     person has an additional responsibility, regardless of their teaching
//     assignment.  This does NOT create or replace the base context.
function normalizeLevelVariants($education_level) {
    $level_key = strtolower(trim($education_level ?? ''));
    $edu_bucket_map = [
        'elementary'             => ['basic education'],
        'junior_high'            => ['basic education'],
        'senior_high'            => ['basic education'],
        'basic education'        => ['basic education'],
        'college'                => ['college', 'higher education', 'college / university', 'college/university'],
        'higher education'       => ['college', 'higher education', 'college / university', 'college/university'],
        'college / university'   => ['college', 'higher education', 'college / university', 'college/university'],
        'college/university'     => ['college', 'higher education', 'college / university', 'college/university'],
    ];
    return array_values(array_unique(array_map('strtolower', $edu_bucket_map[$level_key] ?? [$level_key])));
}

function isMatchedViaAssignment($mysqli, $target_id, $level_variants, $student_year_level) {
    if (empty($level_variants) || $student_year_level === null || $student_year_level === '') return false;
    $placeholders = implode(',', array_fill(0, count($level_variants), '?'));
    $stmt = $mysqli->prepare(
        "SELECT 1 FROM (
            SELECT ta.user_id FROM teaching_assignments ta
            WHERE ta.user_id = ?
              AND LOWER(TRIM(ta.education_level)) IN ($placeholders)
              AND LOWER(TRIM(ta.year_level)) = LOWER(TRIM(?))
            UNION
            SELECT uyl.user_id FROM user_year_levels uyl
            WHERE uyl.user_id = ?
              AND LOWER(TRIM(uyl.year_level)) = LOWER(TRIM(?))
        ) AS matched"
    );
    $types  = 'i' . str_repeat('s', count($level_variants)) . 's' . 'is';
    $params = array_merge([$target_id], $level_variants, [$student_year_level, $target_id, $student_year_level]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $matches = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $matches;
}

function hasAnyYearLevelAssignment($mysqli, $target_id) {
    $stmt = $mysqli->prepare(
        "SELECT 1 FROM (
            SELECT user_id FROM user_year_levels WHERE user_id=?
            UNION ALL
            SELECT user_id FROM teaching_assignments WHERE user_id=?
        ) x LIMIT 1"
    );
    $stmt->bind_param('ii', $target_id, $target_id);
    $stmt->execute();
    $has_any = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $has_any;
}

function canStudentEvaluateTarget(
    $mysqli,
    $student_level,
    $student_year_level,
    $target_id,
    $evaluation_context = ''
) {
    $stmt = $mysqli->prepare(
        "SELECT role, secondary_role, designation, is_active
         FROM users
         WHERE id=?
         LIMIT 1"
    );

    $stmt->bind_param('i', $target_id);
    $stmt->execute();

    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$target || !(int)$target['is_active']) {
        return [
            false,
            'This person is not available for evaluation.'
        ];
    }

    /*
     * SCHOOL HEAD
     */
    if (in_array($target['role'], ['principal', 'dean'], true)) {
        return [
            $evaluation_context === 'school_head' || $evaluation_context === '',
            null
        ];
    }

    /*
     * DETERMINE AVAILABLE CONTEXTS
     */
    $has_teacher = ec_has_teacher_function($target);
    $has_staff   = ec_has_staff_function($target);

    /*
     * MULTI-ROLE
     *
     * A person qualifies when:
     *   1. They have an additional role, OR
     *   2. They are Staff and have a teaching/year-level assignment.
     */
    if ($evaluation_context === 'multi_role') {

        $has_multi_role =
            ec_has_additional_role($target)
            ||
            (
                $has_staff
                && hasAnyYearLevelAssignment($mysqli, $target_id)
            );

        return [
            $has_multi_role,
            $has_multi_role
                ? null
                : 'This person is not configured for a Multi-Role evaluation.'
        ];
    }

    /*
     * TEACHER / STAFF CONTEXT VALIDATION
     */
    if ($evaluation_context === 'teacher' && !$has_teacher) {
        return [
            false,
            'This person is not configured for a Teacher evaluation.'
        ];
    }

    if ($evaluation_context === 'staff' && !$has_staff) {
        return [
            false,
            'This person is not configured for a Staff evaluation.'
        ];
    }

    /*
     * If no context was explicitly supplied, determine the primary
     * context from the person's available functions.
     */
    if ($evaluation_context === '') {
        $evaluation_context = $has_teacher
            ? 'teacher'
            : 'staff';
    }

    /*
     * Student account must have education level and year level.
     */
    if (!$student_level || !$student_year_level) {
        return [
            false,
            'Your education level / year level is not set on your account. Please contact the registrar.'
        ];
    }

    /*
     * Check whether the target has ANY teaching/year-level assignment.
     */
    $has_assignment = hasAnyYearLevelAssignment(
        $mysqli,
        $target_id
    );

    /*
     * STAFF WITH NO ASSIGNMENT
     *
     * Non-teaching staff are institution-wide.
     *
     * Teachers without an assignment are NOT automatically
     * available to everybody.
     */
    if (!$has_assignment) {

        if ($evaluation_context === 'staff' && $has_staff) {
            return [true, null];
        }

        return [
            false,
            'This teacher is not assigned to a year level.'
        ];
    }

    /*
     * TEACHER / TEACHING STAFF
     *
     * Must match student's education level + year level.
     */
    $matched = isMatchedViaAssignment(
        $mysqli,
        $target_id,
        normalizeLevelVariants($student_level),
        $student_year_level
    );

    return [
        $matched,
        $matched
            ? null
            : 'This person is not assigned to your education level / year level.'
    ];
}

// ── ACTIVE EVALUATION PERIOD ──────────────────────────────────
// Fetched once up front so the Dashboard, Guidelines, and the submit
// handler below all agree on whether evaluations are currently open.
$activePeriodRow = $mysqli->query("SELECT id FROM evaluation_periods WHERE is_active=1 LIMIT 1")->fetch_assoc();
$period_is_open  = (bool)$activePeriodRow;
$ctxCol=$mysqli->query("SHOW COLUMNS FROM evaluation_tracker LIKE 'evaluation_context'");
if ($ctxCol && $ctxCol->num_rows===0) {
    $mysqli->query("ALTER TABLE evaluation_tracker ADD COLUMN evaluation_context VARCHAR(30) NOT NULL DEFAULT 'teacher'");
    $mysqli->query("UPDATE evaluation_tracker et JOIN users u ON u.id=et.target_user_id SET et.evaluation_context=CASE WHEN u.role IN ('principal','dean') THEN 'school_head' WHEN u.role='staff' THEN 'staff' WHEN u.role='teacher' THEN 'teacher' ELSE et.evaluation_context END");
}

// ── HANDLE EVALUATION SUBMISSION ─────────────────────────────
$submit_error   = '';
$submit_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_evaluation'])) {
    $target_id = intval($_POST['target_user_id']);
    $evaluation_context = strtolower(trim($_POST['evaluation_context'] ?? 'teacher'));
    if (!in_array($evaluation_context,['teacher','staff','multi_role','school_head'],true)) $evaluation_context='teacher';

    // SERVER-SIDE ELIGIBILITY RE-CHECK. The Evaluate view only ever renders
    // buttons for people already filtered into $all_users below, but
    // nothing previously stopped a POST with an arbitrary target_user_id
    // from being submitted directly, bypassing that filter entirely.
    // canStudentEvaluateTarget() (defined above) re-runs the EXACT same
    // rule the display query uses -- Teacher/Teaching-Staff must match
    // the student's education level + year level via teaching_assignments
    // or user_year_levels; non-teaching staff (zero user_year_levels
    // rows, ever) is always eligible; Principal/Dean is a singleton
    // bypass -- so a manually changed target_id can never slip through
    // with a person the student isn't actually authorized to evaluate.
    [$eligible, $eligibility_result] = ($target_id > 0) ? canStudentEvaluateTarget($mysqli,$student_level,$student_year_level,$target_id,$evaluation_context) : [false,null];
    $ctxStmt=$mysqli->prepare("SELECT role,secondary_role,designation FROM users WHERE id=? AND is_active=1 LIMIT 1");
    $ctxStmt->bind_param('i',$target_id); $ctxStmt->execute(); $ctxRow=$ctxStmt->get_result()->fetch_assoc(); $ctxStmt->close();
    $has_teacher_context=$ctxRow && ec_has_teacher_function($ctxRow);
    $has_staff_context=$ctxRow && ec_has_staff_function($ctxRow);
    $has_multi_context=$ctxRow && userHasAdditionalRole($ctxRow);
    $context_allowed=($evaluation_context==='teacher'&&$has_teacher_context)||($evaluation_context==='staff'&&$has_staff_context)||($evaluation_context==='multi_role'&&$has_multi_context)||($evaluation_context==='school_head'&&$ctxRow&&in_array($ctxRow['role'],['principal','dean'],true));

    if ($target_id <= 0) {
        $submit_error = "Invalid submission data. Please try again.";
    } elseif (!$eligible) {
        $submit_error = $eligibility_result;
    } elseif (!$context_allowed) {
        $submit_error = 'This evaluation context is not available for this person.';
    } elseif (!$period_is_open) {
        $submit_error = "No evaluation period is currently open. Please check back later.";
    } else {
        $period_id = (int)$activePeriodRow['id'];

        $chk = $mysqli->prepare(
            "SELECT id FROM evaluation_tracker WHERE evaluator_id=? AND target_user_id=? AND period_id=? AND evaluation_context=?"
        );
        $chk->bind_param("iiis",$student_id,$target_id,$period_id,$evaluation_context);
        $chk->execute();
        $chk->store_result();
        $already_done = $chk->num_rows > 0;
        $chk->close();

        if ($already_done) {
            $submit_error = "You have already evaluated this person this period.";
        } else {
            $ratings = $_POST['rating'] ?? [];
            $comment = trim($_POST['comment'] ?? '');
            if (empty($ratings)) {
                $submit_error = "Please answer all questions before submitting.";
            } else {
                try {
                    $mysqli->begin_transaction();

                    $trk = $mysqli->prepare(
                        "INSERT INTO evaluation_tracker (evaluator_id,target_user_id,remarks,eval_type,period_id,evaluation_context,status,submitted_at) VALUES (?, ?, ?, 'student', ?, ?, 'submitted', NOW())"
                    );
                    $trk->bind_param("iisis",$student_id,$target_id,$comment,$period_id,$evaluation_context);
                    $trk->execute();
                    $tracker_id = $mysqli->insert_id;
                    $trk->close();

                    // NOTE: `rating[q_id]` keys come straight from whichever
                    // table loadQuestions() pulled them from (evaluation_questions
                    // for Faculty/Multi-Role, user_questions for Staff). Both
                    // tables have their own independent auto-increment id
                    // space, so if a Multi-Role person is ALSO a Staff member,
                    // it is possible for a user_questions.id and an
                    // evaluation_questions.id to collide numerically. That
                    // edge case isn't fully solved here -- it needs either a
                    // `question_source` column on questionnaire_answers or a
                    // prefixed key scheme end-to-end. Flagging this rather
                    // than silently shipping a subtle mis-attribution bug.
$ins = $mysqli->prepare(
    "INSERT INTO questionnaire_answers
     (
        tracker_id,
        question_id,
        question_source,
        user_question_id,
        answer_score,
        submitted_at
     )
     VALUES (?, ?, ?, ?, ?, NOW())"
);

foreach ($ratings as $question_key => $rating) {

    /*
     * Question keys now look like:
     *   evaluation:15
     *   user:7
     */

    $parts = explode(':', $question_key, 2);

    if (count($parts) !== 2) {
        throw new Exception("Invalid question reference.");
    }

    $source = $parts[0];
    $q_id   = intval($parts[1]);

    if (!in_array($source, ['evaluation', 'user'], true) || $q_id <= 0) {
        throw new Exception("Invalid question reference.");
    }

    $score = max(1, min(5, floatval($rating)));

    $question_id      = null;
    $user_question_id = null;

    if ($source === 'evaluation') {
        $question_id = $q_id;
    } else {
        $user_question_id = $q_id;
    }

    $ins->bind_param(
        "iisid",
        $tracker_id,
        $question_id,
        $source,
        $user_question_id,
        $score
    );

    if (!$ins->execute()) {
        throw new Exception(
            "Failed to save questionnaire answer: " . $ins->error
        );
    }
}

$ins->close();

                    $mysqli->commit();
                    $submit_success = "Evaluation submitted successfully. Thank you!";

                } catch (Exception $e) {
                    $mysqli->rollback();
                    $submit_error = "Submission failed: " . $e->getMessage();
                }
            }
        }
    }
}

// ── FETCH QUESTIONS FOR A TARGET (AJAX) ──────────────────────
// Reads from the SAME source(s) the admin actually writes to for this
// person's resolved bucket:
//   - Multi-Role -> evaluation_questions (shared pool, target_type='Multi-Role')
//                   ONLY -- a Staff-role user with an active teaching
//                   assignment (>=1 user_year_levels row) gets the
//                   Multi-Role questionnaire exclusively, not the
//                   ordinary per-person Staff questionnaire, mirroring
//                   the Multi-Role display category below.
//   - Staff      -> user_questions (per-person, keyed by user_id)
//   - Teacher    -> evaluation_questions (shared pool, target_type='Teacher')
if (isset($_GET['get_questions'])) {
    header('Content-Type: application/json');
    try {
        $target_id = intval($_GET['target_id']);

        $userRow = $mysqli->query(
            "SELECT designation, role, secondary_role FROM users WHERE id=$target_id AND is_active=1 LIMIT 1"
        )->fetch_assoc();

        if (!$userRow) throw new Exception("User not found.");

        $designation = $userRow['designation'] ?? '';
        $role        = $userRow['role'] ?? 'teacher';

        // ── PRINCIPAL / DEAN → SCHOOL HEAD ───────────────────────
        // Questionnaire stores Principal and Dean as per-user question sets.
        if (in_array($role, ['principal', 'dean'], true)) {
            $pd_target_type = ucfirst($role);
            $pdq = $mysqli->prepare(
                "SELECT id, question_text, category,
                        'user' AS question_source
                 FROM user_questions
                 WHERE user_id = ? AND target_type = ? AND eval_type = 'school_head'
                 ORDER BY category, id"
            );
            $pdq->bind_param("is", $target_id, $pd_target_type);
            $pdq->execute();
            $pd_questions = $pdq->get_result()->fetch_all(MYSQLI_ASSOC);
            $pdq->close();

            if (empty($pd_questions)) {
                throw new Exception(
                    "No questions have been set up for this person yet. " .
                    "Please ask the admin to add questions under Questionnaire → School Head → $pd_target_type."
                );
            }

            echo json_encode(['success' => true, 'questions' => $pd_questions]);
            exit;
        }

        $requested_context = $_GET['context'] ?? '';
$has_teacher_context = ec_has_teacher_function($userRow);
$has_staff_context = ec_has_staff_function($userRow);
$has_assignment = hasAnyYearLevelAssignment($mysqli, $target_id);
$is_multi_role = userHasAdditionalRole($userRow) || ($has_staff_context && $has_assignment);
        if ($requested_context === 'multi_role') {
            if (!$is_multi_role) throw new Exception('This person is not configured for a Multi-Role evaluation.');
            $q=$mysqli->prepare("SELECT id,question_text,category,'evaluation' AS question_source FROM evaluation_questions WHERE target_type='Multi-Role' AND eval_type='student' ORDER BY category,id");
            $q->execute(); $questions=$q->get_result()->fetch_all(MYSQLI_ASSOC); $q->close();
            if (empty($questions)) throw new Exception('No Multi-Role questions have been set up yet.');
            echo json_encode(['success'=>true,'questions'=>$questions]); exit;
        }
        if ($requested_context === 'staff') {
            if (!$has_staff_context) throw new Exception('This person is not configured for a Staff evaluation.');
            $q=$mysqli->prepare("SELECT id,question_text,category,'user' AS question_source FROM user_questions WHERE user_id=? AND target_type='Staff' AND eval_type='student' ORDER BY category,id");
            $q->bind_param('i',$target_id); $q->execute(); $questions=$q->get_result()->fetch_all(MYSQLI_ASSOC); $q->close();
            if (empty($questions)) throw new Exception('No Staff questions have been set up for this person yet.');
            echo json_encode(['success'=>true,'questions'=>$questions]); exit;
        }
        if ($requested_context === 'teacher') {
            if (!$has_teacher_context) throw new Exception('This person is not configured for a Teacher evaluation.');
            $q=$mysqli->prepare("SELECT id,question_text,category,'evaluation' AS question_source FROM evaluation_questions WHERE target_type='Teacher' AND eval_type='student' ORDER BY category,id");
            $q->execute(); $questions=$q->get_result()->fetch_all(MYSQLI_ASSOC); $q->close();
            if (empty($questions)) throw new Exception('No Teacher questions have been set up yet.');
            echo json_encode(['success'=>true,'questions'=>$questions]); exit;
        }
        $primary = $has_staff_context && !$has_teacher_context ? 'Staff' : 'Teacher';

        $questions = [];

        if ($primary === 'Staff') {
            // FIXED: this query was previously truncated with a literal "..."
            // left in the SQL string, which threw a mysqli_sql_exception
            // (mysqli_report is set to MYSQLI_REPORT_STRICT above) every time
            // a Staff-bucket person was opened for evaluation. Completed it
            // to match the shape of the Faculty/Multi-Role queries below,
            // including the missing ORDER BY.
          $uq = $mysqli->prepare(
    "SELECT id, question_text, category,
            'user' AS question_source
     FROM user_questions
     WHERE user_id = ? AND eval_type = 'student'
     ORDER BY category, id"
);
            $uq->bind_param("i", $target_id);
            $uq->execute();
            $questions = array_merge($questions, $uq->get_result()->fetch_all(MYSQLI_ASSOC));
            $uq->close();
        } else {
      $qs = $mysqli->prepare(
    "SELECT id, question_text, category,
            'evaluation' AS question_source
     FROM evaluation_questions
     WHERE target_type = 'Teacher' AND eval_type = 'student'
     ORDER BY category, id"
);
            $qs->execute();
            $questions = array_merge($questions, $qs->get_result()->fetch_all(MYSQLI_ASSOC));
            $qs->close();
        }

        if (empty($questions)) {
            $hint = $primary === 'Staff'
                ? "Questionnaire → Staff → this person individually"
                : "Questionnaire → Teacher";
            throw new Exception(
                "No questions have been set up for this person yet. " .
                "Please ask the admin to add questions under $hint."
            );
        }

        echo json_encode(['success' => true, 'questions' => $questions]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── FETCH EVALUATEES ──────────────────────────────────────────────
// Build contexts per person instead of first filtering people and then
// guessing their category.  This is the authoritative student visibility rule.
$grouped = ['Faculty'=>['Teacher'=>[],'Staff'=>[]], 'Multi-Role'=>[]];

$ures = $mysqli->query("SELECT id, full_name, designation, photo, role, secondary_role
                        FROM users
                        WHERE role IN ('teacher','staff','faculty')
                          AND is_active=1
                          AND (account_status='approved' OR source='admin_nologin')
                        ORDER BY full_name ASC");
if (!$ures) throw new Exception('Failed to load evaluation personnel: '.$mysqli->error);

$level_variants = normalizeLevelVariants($student_level);
foreach ($ures->fetch_all(MYSQLI_ASSOC) as $u) {
$has_teacher = ec_has_teacher_function($u);
$has_staff   = ec_has_staff_function($u);
$has_assign  = hasAnyYearLevelAssignment($mysqli, (int)$u['id']);
$is_multi    = ec_has_additional_role($u) || ($has_staff && $has_assign);
    $base_match  = ($student_level && $student_year_level && $has_assign)
        ? isMatchedViaAssignment($mysqli, (int)$u['id'], $level_variants, $student_year_level)
        : false;

    // Base Teacher context: only assigned year levels.
    if ($has_teacher && $base_match) {
        $grouped['Faculty']['Teacher'][] = $u;
    }

    // Base Staff context: institution-wide only when there is no teaching
    // assignment; otherwise only at the assigned year levels.
    if ($has_staff && (!$has_assign || $base_match)) {
        $grouped['Faculty']['Staff'][] = $u;
    }

    // Multi-Role context: additional responsibility is independent of the
    // person's teaching assignment and therefore visible across applicable
    // student year levels.  This is the critical rule for Teacher/Staff +
    // additional role when the base assignment does not match this student.
    if ($is_multi) {
        $grouped['Multi-Role'][] = $u;
    }
}

// Attach teaching assignments for informative cards only.
if (!empty($grouped['Multi-Role'])) {
    $mr_ids = array_column($grouped['Multi-Role'], 'id');
    $ph = implode(',', array_fill(0,count($mr_ids),'?'));
    $stmt = $mysqli->prepare("SELECT user_id, GROUP_CONCAT(DISTINCT year_level ORDER BY year_level SEPARATOR ', ') levels FROM user_year_levels WHERE user_id IN ($ph) GROUP BY user_id");
    $stmt->bind_param(str_repeat('i',count($mr_ids)), ...$mr_ids);
    $stmt->execute();
    $levels=[];
    foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $levels[(int)$r['user_id']]=$r['levels'];
    $stmt->close();
    foreach($grouped['Multi-Role'] as &$u) $u['teaching_levels']=$levels[(int)$u['id']] ?? '';
    unset($u);
}

// ── SINGLETON SCHOOL HEAD CATEGORY (Principal + Dean) ─────────
// Principal and Dean aren't tied to teaching_assignments/user_year_levels
// like Teacher/Staff -- there's exactly ONE active user per role, and
// every student (regardless of education_level/year_level) can evaluate
// them. They're grouped into a single "School Head" category (matching
// the admin's "School Head Evaluation" questionnaire bucket), with each
// person's `designation` stamped as their role ("Principal"/"Dean") so
// they're distinguishable on the card -- but only when designation is
// blank, so an admin-set title (e.g. "Principal, Senior High") isn't
// clobbered.
$school_head_members = [];
foreach (['principal' => 'Principal', 'dean' => 'Dean'] as $role_value => $role_label) {
    $singleton = $mysqli->prepare(
        "SELECT id, full_name, designation, photo, role
         FROM users
         WHERE role = ? AND is_active = 1
         LIMIT 1"
    );
    $singleton->bind_param("s", $role_value);
    $singleton->execute();
    $singleton_row = $singleton->get_result()->fetch_assoc();
    $singleton->close();

    if ($singleton_row) {
        if (trim($singleton_row['designation'] ?? '') === '') {
            $singleton_row['designation'] = $role_label;
        }
        $all_users[]            = $singleton_row;
        $school_head_members[]  = $singleton_row;
    }
}
if (!empty($school_head_members)) {
    $grouped['School Head'] = $school_head_members;
}

// Remove Faculty entirely if both Teacher and Staff are empty, so
// students only see categories with people in them.
if (empty($grouped['Faculty']['Teacher']) && empty($grouped['Faculty']['Staff'])) {
    unset($grouped['Faculty']);
}

// ── FETCH ALREADY EVALUATED IDs (CURRENT PERIOD ONLY) ─────────
// evaluation_tracker.period_id scopes a submission to one evaluation
// period, and the submit handler's dedupe check above is already
// period-scoped (evaluator_id + target_user_id + period_id). But this
// lookup previously had NO period_id filter -- it pulled every person
// this student has EVER evaluated, in any past period. That meant once
// a period ended and a new one opened, every teacher/staff member would
// still show as "Done" (Evaluate button hidden, Completed/Pending/
// Progress stuck) forever, even though a fresh evaluation for the new
// period was both expected and allowed by the backend. Scoping this to
// $activePeriodRow's id makes "Done" reset for each new period.
$done_ids=[];
if($period_is_open){
    $current_period_id=(int)$activePeriodRow['id'];
    $dres=$mysqli->prepare("SELECT target_user_id,evaluation_context FROM evaluation_tracker WHERE evaluator_id=? AND period_id=?");
    $dres->bind_param('ii',$student_id,$current_period_id); $dres->execute(); $dres->bind_result($done_target_id,$done_context);
    while($dres->fetch()) $done_ids[$done_target_id.'|'.($done_context?:'teacher')]=true;
    $dres->close();
}

// ── FETCH EVALUATION HISTORY (for the History view) ──────────
// One row per past submission by this student, with the target's
// name/photo/designation and the average score they gave, so the
// History view doesn't need another round trip.
$history = [];
$hres = $mysqli->prepare(
    "SELECT et.id, et.target_user_id, u.full_name, u.designation, u.photo,
            et.remarks, et.status, et.submitted_at,
            AVG(qa.answer_score) AS avg_score, COUNT(qa.id) AS answer_count
     FROM evaluation_tracker et
     JOIN users u ON u.id = et.target_user_id
     LEFT JOIN questionnaire_answers qa ON qa.tracker_id = et.id
     WHERE et.evaluator_id = ?
     GROUP BY et.id
     ORDER BY et.submitted_at DESC"
);
$hres->bind_param("i", $student_id);
$hres->execute();
$history = $hres->get_result()->fetch_all(MYSQLI_ASSOC);
$hres->close();

// ── GROUP ICONS / COLORS (matches admin questionnaire's 3 buckets) ──
$group_icons = [
    'Faculty'            => 'fa-people-group',
    'Teacher'            => 'fa-chalkboard-user',
    'Staff'              => 'fa-briefcase',
    'Multi-Role'         => 'fa-people-arrows',
    'School Head'        => 'fa-user-tie',
];
$group_colors = [
    'Faculty'            => '#00E5FF',
    'Teacher'            => '#00E5FF',
    'Staff'              => '#10b981',
    'Multi-Role'         => '#F59E0B',
    'School Head'        => '#8B5CF6',
];

// Progress counts evaluation contexts, not unique accounts.
$total_evaluatees=count($grouped['Faculty']['Teacher']??[])+count($grouped['Faculty']['Staff']??[])+count($grouped['Multi-Role']??[])+count($grouped['School Head']??[]);
$total_done=count($done_ids);
$total_pending     = max(0, $total_evaluatees - $total_done);
$pct              = $total_evaluatees > 0 ? round(($total_done / $total_evaluatees) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI — Student Evaluation</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
:root{
    --dark:#0A192F;--mid:#172A45;--inner:#0F1F3D;
    --gold:#D97706;--gold-h:#F59E0B;
    --light:#E0E6F0;--muted:#A0B3C6;
    --border:rgba(255,255,255,0.08);--radius:10px;
    --sidebar-w:230px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--dark);color:var(--light);min-height:100vh;}

/* ── TOPNAV ── */
.topnav{background:var(--mid);border-bottom:1px solid var(--border);padding:14px 32px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;}
.nav-brand{display:flex;align-items:center;gap:12px;}
.hamburger-btn{display:none;background:none;border:1px solid var(--border);color:var(--light);font-size:16px;width:38px;height:38px;border-radius:8px;cursor:pointer;align-items:center;justify-content:center;}
.hamburger-btn:hover{border-color:var(--gold);color:var(--gold-h);}
.nav-logo{width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid var(--gold);}
.nav-right{display:flex;align-items:center;gap:16px;}

/* Profile dropdown */
.nav-profile{position:relative;}
.profile-trigger{display:flex;align-items:center;gap:10px;cursor:pointer;padding:6px 10px;border-radius:var(--radius);transition:background .2s;}
.profile-trigger:hover{background:rgba(255,255,255,.06);}
.profile-avatar{width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid var(--gold);flex-shrink:0;}
.profile-avatar-ph{width:36px;height:36px;border-radius:50%;background:var(--inner);border:2px solid var(--gold);display:flex;align-items:center;justify-content:center;color:var(--gold);font-size:15px;flex-shrink:0;}
.profile-name{font-size:13px;font-weight:600;color:var(--light);max-width:120px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.profile-caret{font-size:11px;color:var(--muted);transition:transform .2s;}
.profile-dropdown{position:absolute;top:calc(100% + 8px);right:0;background:var(--mid);border:1px solid var(--border);border-radius:12px;width:240px;box-shadow:0 12px 40px rgba(0,0,0,.5);z-index:100;display:none;overflow:hidden;}
.profile-dropdown.open{display:block;animation:fadeDown .18s ease;}
@keyframes fadeDown{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.profile-dd-header{padding:16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;}
.profile-dd-avatar{width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid var(--gold);flex-shrink:0;}
.profile-dd-avatar-ph{width:44px;height:44px;border-radius:50%;background:var(--inner);border:2px solid var(--gold);display:flex;align-items:center;justify-content:center;color:var(--gold);font-size:18px;flex-shrink:0;}
.profile-dd-name{font-size:13px;font-weight:700;color:#fff;line-height:1.3;}
.profile-dd-role{font-size:11px;color:var(--muted);text-transform:capitalize;}
.profile-dd-body{padding:10px;}
.profile-dd-btn{width:100%;padding:10px 12px;border-radius:8px;border:none;background:none;color:var(--light);font-size:13px;font-family:'DM Sans',sans-serif;cursor:pointer;display:flex;align-items:center;gap:10px;transition:background .18s;text-align:left;}
.profile-dd-btn:hover{background:rgba(255,255,255,.06);}
.profile-dd-btn i{width:16px;text-align:center;color:var(--muted);}
.profile-dd-divider{height:1px;background:var(--border);margin:6px 0;}
.profile-dd-btn.logout{color:#f87171;}
.profile-dd-btn.logout i{color:#f87171;}

/* ── PHOTO MODAL ── */
.photo-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:300;display:none;align-items:center;justify-content:center;padding:20px;}
.photo-modal-overlay.open{display:flex;}
.photo-modal{background:var(--mid);border:1px solid var(--border);border-radius:18px;width:100%;max-width:400px;box-shadow:0 20px 60px rgba(0,0,0,.6);overflow:hidden;}
.photo-modal-header{padding:20px 24px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.photo-modal-title{font-family:'Rajdhani',sans-serif;font-size:20px;font-weight:700;color:#fff;}
.photo-modal-body{padding:24px;}
.photo-upload-circle{width:110px;height:110px;border-radius:50%;border:3px dashed rgba(217,119,6,.5);display:flex;align-items:center;justify-content:center;margin:0 auto 20px;cursor:pointer;transition:border-color .2s;overflow:hidden;position:relative;background:var(--inner);}
.photo-upload-circle:hover{border-color:var(--gold);}
.photo-upload-circle img{width:100%;height:100%;object-fit:cover;display:none;border-radius:50%;}
.photo-upload-circle .upload-icon{color:var(--muted);font-size:32px;transition:color .2s;}
.photo-upload-circle:hover .upload-icon{color:var(--gold);}
.photo-upload-hint{text-align:center;font-size:12px;color:var(--muted);margin-bottom:20px;}
.photo-upload-hint span{color:var(--gold-h);font-weight:600;cursor:pointer;}
.photo-modal-footer{padding:0 24px 24px;display:flex;gap:10px;}
.btn-save-photo{flex:1;padding:11px;background:var(--gold);border:none;border-radius:var(--radius);color:#fff;font-size:14px;font-weight:700;cursor:pointer;font-family:'DM Sans',sans-serif;}
.btn-save-photo:hover{background:var(--gold-h);}
.btn-skip-photo{flex:1;padding:11px;background:var(--inner);border:1px solid var(--border);border-radius:var(--radius);color:var(--muted);font-size:14px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;}

/* ── APP SHELL: SIDEBAR + MAIN ── */
.app-shell{display:flex;align-items:flex-start;}

.sidebar{width:var(--sidebar-w);flex-shrink:0;background:var(--mid);border-right:1px solid var(--border);min-height:calc(100vh - 69px);position:sticky;top:69px;padding:20px 0;}
.side-section-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1.3px;color:var(--muted);padding:0 20px;margin-bottom:8px;}
.side-nav-item{display:flex;align-items:center;gap:12px;padding:11px 20px;color:var(--light);font-size:13.5px;font-weight:600;cursor:pointer;border-left:3px solid transparent;transition:all .18s;}
.side-nav-item i{width:18px;text-align:center;color:var(--muted);font-size:15px;transition:color .18s;}
.side-nav-item:hover{background:rgba(255,255,255,.05);}
.side-nav-item.active{background:rgba(217,119,6,.12);border-left-color:var(--gold);color:#fff;}
.side-nav-item.active i{color:var(--gold-h);}
.side-nav-badge{margin-left:auto;background:rgba(217,119,6,.2);color:var(--gold-h);font-size:10px;font-weight:700;border-radius:20px;padding:2px 8px;}

.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:79;}
.sidebar-overlay.open{display:block;}

.main{flex:1;min-width:0;max-width:1000px;margin:0 auto;padding:36px 28px;}
.page-title{font-family:'Rajdhani',sans-serif;font-size:28px;font-weight:700;color:#fff;margin-bottom:4px;}
.page-sub{font-size:13px;color:var(--muted);margin-bottom:32px;}

.view-content{display:none;}
.view-content.active{display:block;animation:fadeIn .18s ease;}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}

/* ── DASHBOARD VIEW ── */
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:28px;}
.stat-card{background:var(--mid);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;}
.stat-card i{font-size:18px;color:var(--gold-h);margin-bottom:10px;display:block;}
.stat-num{font-family:'Rajdhani',sans-serif;font-size:26px;font-weight:700;color:#fff;line-height:1;}
.stat-lbl{font-size:12px;color:var(--muted);margin-top:6px;}
.period-pill{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;border-radius:20px;padding:4px 12px;margin-bottom:20px;}
.period-pill.open{background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.3);color:#4ade80;}
.period-pill.closed{background:rgba(240,84,84,.1);border:1px solid rgba(240,84,84,.3);color:#fca5a5;}
.dash-cta{background:var(--mid);border:1px solid var(--border);border-radius:14px;padding:26px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;}
.dash-cta-icon{width:52px;height:52px;border-radius:12px;background:rgba(217,119,6,.15);display:flex;align-items:center;justify-content:center;font-size:22px;color:var(--gold-h);flex-shrink:0;}
.dash-cta-text{flex:1;min-width:180px;}
.dash-cta-text h3{font-family:'Rajdhani',sans-serif;font-size:17px;color:#fff;margin-bottom:3px;}
.dash-cta-text p{font-size:12.5px;color:var(--muted);}
.btn-primary-cta{padding:11px 22px;background:var(--gold);border:none;border-radius:var(--radius);color:#fff;font-size:13.5px;font-weight:700;cursor:pointer;white-space:nowrap;}
.btn-primary-cta:hover{background:var(--gold-h);}

.progress-wrap{background:var(--mid);border:1px solid var(--border);border-radius:var(--radius);padding:16px 20px;margin-bottom:28px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;}
.progress-label{font-size:13px;color:var(--muted);white-space:nowrap;}
.progress-label span{color:var(--gold-h);font-weight:700;}
.progress-bar-bg{flex:1;min-width:120px;height:8px;background:rgba(255,255,255,.08);border-radius:20px;overflow:hidden;}
.progress-bar-fill{height:100%;background:linear-gradient(90deg,var(--gold),var(--gold-h));border-radius:20px;transition:width .4s ease;}
.progress-pct{font-size:12px;font-weight:700;color:var(--gold-h);white-space:nowrap;}
.alert{border-radius:8px;padding:12px 16px;font-size:13px;margin-bottom:20px;display:flex;align-items:center;gap:8px;}
.alert-success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#86efac;}
.alert-error{background:rgba(240,84,84,.1);border:1px solid rgba(240,84,84,.3);color:#fca5a5;}
.section-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-bottom:14px;}

/* ── CATEGORY CARDS ── */
.category-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:16px;margin-bottom:10px;}
.cat-btn{background:var(--mid);border:1px solid var(--border);border-radius:14px;padding:24px 18px 20px;text-align:center;cursor:pointer;transition:all .22s;position:relative;user-select:none;}
.cat-btn:hover{transform:translateY(-3px);box-shadow:0 6px 20px rgba(0,0,0,.35);}
.cat-btn.active{transform:translateY(-3px);box-shadow:0 6px 20px rgba(0,0,0,.35);}
.cat-btn.all-done{opacity:.55;}
.cat-icon{font-size:32px;margin-bottom:11px;}
.cat-name{font-family:'Rajdhani',sans-serif;font-size:18px;font-weight:700;color:#fff;margin-bottom:3px;}
.cat-meta{font-size:12px;color:var(--muted);}
.cat-done-pill{margin-top:9px;display:inline-block;background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.3);color:#4ade80;border-radius:20px;font-size:10px;font-weight:700;padding:2px 9px;}
.cat-active-arrow{position:absolute;bottom:-10px;left:50%;transform:translateX(-50%);width:0;height:0;border-left:9px solid transparent;border-right:9px solid transparent;border-top:10px solid var(--gold);display:none;filter:drop-shadow(0 2px 4px rgba(0,0,0,.4));}
.cat-btn.active .cat-active-arrow{display:block;}

/* ── MEMBERS PANEL ── */
.members-panel{display:none;border-radius:14px;margin-bottom:32px;overflow:hidden;animation:slideDown .22s ease;background:var(--mid);border:1px solid var(--border);}
.members-panel.open{display:block;}
@keyframes slideDown{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
.panel-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;}
.panel-header-icon{font-size:16px;}
.panel-header-title{font-family:'Rajdhani',sans-serif;font-size:17px;font-weight:700;color:#fff;}
.panel-header-count{font-size:12px;color:var(--muted);margin-left:2px;}
.panel-close-btn{margin-left:auto;background:none;border:none;color:var(--muted);font-size:18px;cursor:pointer;padding:4px 8px;border-radius:6px;transition:color .2s;}
.panel-close-btn:hover{color:#fff;}

/* ── SUBGROUP (Teacher / Staff inside the Faculty panel) ── */
.subgroup-header{display:flex;align-items:center;gap:8px;padding:14px 18px 6px;font-family:'Rajdhani',sans-serif;font-size:14px;font-weight:700;color:#fff;}
.subgroup-header i{font-size:13px;}
.subgroup-header .subgroup-count{font-size:11px;font-weight:500;color:var(--muted);margin-left:2px;}
.subgroup-divider{height:1px;background:var(--border);margin:4px 18px 0;}

.members-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;padding:10px 18px 18px;}
.person-card{background:var(--inner);border:1px solid var(--border);border-radius:12px;padding:18px 14px;text-align:center;transition:all .22s;position:relative;}
.person-card:hover:not(.done){border-color:var(--gold);transform:translateY(-2px);box-shadow:0 6px 20px rgba(0,0,0,.4);}
.person-card.done{opacity:.55;}
.person-avatar{width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid var(--border);margin:0 auto 10px;display:block;background:var(--mid);}
.person-avatar-ph{width:64px;height:64px;border-radius:50%;background:var(--mid);border:2px solid var(--border);margin:0 auto 10px;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:22px;}
.person-name{font-size:13px;font-weight:600;color:#fff;margin-bottom:3px;line-height:1.3;}
.person-desig{font-size:11px;color:var(--muted);margin-bottom:2px;}
.person-teaching-line{font-size:10.5px;color:var(--gold-h);font-weight:600;margin-bottom:2px;}
.done-badge{position:absolute;top:9px;right:9px;background:rgba(34,197,94,.2);border:1px solid rgba(34,197,94,.4);color:#4ade80;border-radius:20px;font-size:10px;font-weight:700;padding:2px 8px;}
.eval-btn{margin-top:11px;width:100%;padding:8px;background:var(--gold);border:none;border-radius:7px;color:#fff;font-size:12px;font-weight:700;cursor:pointer;transition:background .2s;}
.eval-btn:hover{background:var(--gold-h);}
.eval-btn:disabled{background:var(--inner);color:var(--muted);cursor:not-allowed;}

/* ── REMINDER BANNER (Dean/EA → college students only) ── */
.reminder-banner{background:rgba(139,92,246,.1);border:1px solid rgba(139,92,246,.3);border-radius:12px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:flex-start;gap:12px;}
.reminder-banner i.bell-ic{color:#a78bfa;font-size:16px;margin-top:2px;}
.reminder-banner .rb-body{flex:1;}
.reminder-banner .rb-title{font-size:13px;font-weight:700;color:#fff;margin-bottom:2px;}
.reminder-banner .rb-msg{font-size:12.5px;color:var(--muted);line-height:1.5;}
.reminder-banner .rb-meta{font-size:11px;color:#a78bfa;margin-top:4px;}
.reminder-banner .rb-dismiss{background:none;border:none;color:var(--muted);cursor:pointer;font-size:14px;padding:4px 6px;flex-shrink:0;}
.reminder-banner .rb-dismiss:hover{color:#fff;}

/* ── HISTORY VIEW ── */
.history-list{display:flex;flex-direction:column;gap:12px;}
.history-card{background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:16px 18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;}
.history-avatar{width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid var(--border);flex-shrink:0;}
.history-avatar-ph{width:48px;height:48px;border-radius:50%;background:var(--inner);border:2px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:18px;flex-shrink:0;}
.history-info{flex:1;min-width:160px;}
.history-name{font-size:14px;font-weight:700;color:#fff;}
.history-desig{font-size:11.5px;color:var(--muted);}
.history-comment{font-size:12px;color:var(--muted);margin-top:4px;font-style:italic;max-width:420px;}
.history-meta{display:flex;flex-direction:column;align-items:flex-end;gap:4px;}
.history-score{display:flex;align-items:center;gap:6px;font-family:'Rajdhani',sans-serif;font-weight:700;color:var(--gold-h);font-size:15px;}
.history-date{font-size:11px;color:var(--muted);}

/* ── GUIDELINES VIEW ── */
.gl-card{background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:20px 22px;margin-bottom:16px;}
.gl-card h3{font-family:'Rajdhani',sans-serif;font-size:16px;color:#fff;margin-bottom:10px;display:flex;align-items:center;gap:8px;}
.gl-card h3 i{color:var(--gold-h);}
.gl-card p, .gl-card li{font-size:13px;color:var(--muted);line-height:1.7;}
.gl-card ul{padding-left:18px;}
.gl-scale-row{display:flex;align-items:center;gap:10px;padding:6px 0;}
.gl-scale-num{width:26px;height:26px;border-radius:6px;background:var(--gold);color:#fff;font-weight:700;font-size:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}

/* ── EVAL MODAL ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:200;display:none;align-items:center;justify-content:center;padding:20px;}
.modal-overlay.open{display:flex;}
.modal{background:var(--mid);border:1px solid var(--border);border-radius:18px;width:100%;max-width:640px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.6);}
.modal-header{padding:24px 28px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:14px;position:sticky;top:0;background:var(--mid);z-index:1;}
.modal-avatar{width:52px;height:52px;border-radius:50%;object-fit:cover;border:2px solid var(--gold);}
.modal-avatar-ph{width:52px;height:52px;border-radius:50%;background:var(--inner);border:2px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:20px;}
.modal-name{font-family:'Rajdhani',sans-serif;font-size:20px;font-weight:700;color:#fff;}
.modal-desig{font-size:12px;color:var(--muted);}
.modal-close{margin-left:auto;background:none;border:none;color:var(--muted);font-size:18px;cursor:pointer;padding:4px 8px;border-radius:6px;transition:color .2s;}
.modal-close:hover{color:#fff;}
.modal-body{padding:24px 28px;}
.q-category{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:var(--gold-h);margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid rgba(217,119,6,.18);}
.q-item{margin-bottom:18px;background:var(--inner);border-radius:10px;padding:14px 16px;}
.q-text{font-size:14px;color:var(--light);margin-bottom:12px;line-height:1.5;display:flex;gap:6px;align-items:flex-start;}
.q-num-badge{color:var(--gold-h);font-weight:700;font-size:14px;flex-shrink:0;min-width:22px;}
.rating-row{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}
.r-btn{flex:1;min-width:66px;padding:8px 4px;background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:7px;color:var(--muted);font-size:13px;font-weight:700;cursor:pointer;transition:all .18s;text-align:center;}
.r-btn:hover{border-color:var(--gold);color:var(--gold-h);}
.r-btn.selected{background:var(--gold);border-color:var(--gold);color:#fff;}
.r-val{font-size:10px;display:block;margin-top:2px;font-weight:400;}
.comment-box{margin-top:24px;background:var(--inner);border-radius:10px;padding:16px;}
.comment-label{font-size:12px;font-weight:700;color:var(--gold-h);margin-bottom:10px;display:flex;align-items:center;gap:7px;}
.comment-optional{font-size:11px;color:var(--muted);font-weight:400;}
.comment-textarea{width:100%;background:var(--dark);border:1px solid var(--border);border-radius:8px;color:var(--light);padding:12px 14px;font-size:13px;font-family:'DM Sans',sans-serif;resize:vertical;outline:none;transition:border-color .2s;line-height:1.5;}
.comment-textarea:focus{border-color:var(--gold);}
.comment-textarea::placeholder{color:rgba(160,179,198,.4);}
.scale-legend{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:18px;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:8px;padding:10px 14px;}
.legend-item{font-size:11px;color:var(--muted);display:flex;align-items:center;gap:5px;}
.legend-dot{width:18px;height:18px;border-radius:4px;background:var(--gold);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;flex-shrink:0;}
.modal-footer{padding:16px 28px 24px;display:flex;gap:12px;}
.btn-submit{flex:1;padding:13px;background:var(--gold);border:none;border-radius:var(--radius);color:#fff;font-size:15px;font-weight:700;cursor:pointer;transition:background .2s;}
.btn-submit:hover{background:var(--gold-h);}
.btn-cancel-modal{padding:13px 22px;background:var(--inner);border:1px solid var(--border);border-radius:var(--radius);color:var(--light);font-size:14px;font-weight:600;cursor:pointer;transition:background .2s;}
.btn-cancel-modal:hover{background:rgba(255,255,255,.06);}
.loading-qs{text-align:center;padding:40px;color:var(--muted);}
.loading-qs i{font-size:28px;animation:spin 1s linear infinite;display:block;margin-bottom:10px;}
@keyframes spin{to{transform:rotate(360deg)}}
.empty{text-align:center;padding:48px 20px;color:var(--muted);}
.empty i{font-size:36px;margin-bottom:12px;display:block;opacity:.3;}

@media(max-width:900px){
    .sidebar{position:fixed;top:0;left:0;height:100vh;z-index:80;transform:translateX(-100%);transition:transform .22s ease;padding-top:80px;}
    .sidebar.open{transform:translateX(0);box-shadow:0 0 40px rgba(0,0,0,.5);}
    .hamburger-btn{display:flex;}
    .main{padding:24px 16px;max-width:100%;}
}
@media(max-width:600px){
    .category-grid{grid-template-columns:1fr;}
    .members-grid{grid-template-columns:repeat(auto-fill,minmax(130px,1fr));}
    .modal-body,.modal-header,.modal-footer{padding-left:18px;padding-right:18px;}
    .topnav{padding:12px 16px;}
    .progress-wrap{flex-direction:column;align-items:flex-start;gap:8px;}
    .history-meta{align-items:flex-start;width:100%;}
}
</style>
</head>
<body>

<nav class="topnav">
    <div class="nav-brand">
        <button class="hamburger-btn" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
        <img class="nav-logo" src="../image/pbi_logo" alt="PBI" onerror="this.style.display='none'"/>
    </div>
    <div class="nav-right">
        <div class="nav-profile" id="navProfile">
            <div class="profile-trigger" onclick="toggleProfileDD()">
                <?php if ($student_photo): ?>
                <img class="profile-avatar" src="../image/<?= htmlspecialchars($student_photo) ?>" alt=""/>
                <?php else: ?>
                <div class="profile-avatar-ph"><i class="fa-solid fa-user"></i></div>
                <?php endif; ?>
                <span class="profile-name"><?= htmlspecialchars($student_name) ?></span>
                <i class="fa-solid fa-chevron-down profile-caret" id="profileCaret"></i>
            </div>
            <div class="profile-dropdown" id="profileDropdown">
                <div class="profile-dd-header">
                    <?php if ($student_photo): ?>
                    <img class="profile-dd-avatar" src="../image/<?= htmlspecialchars($student_photo) ?>" alt=""/>
                    <?php else: ?>
                    <div class="profile-dd-avatar-ph"><i class="fa-solid fa-user"></i></div>
                    <?php endif; ?>
                    <div>
                        <div class="profile-dd-name"><?= htmlspecialchars($student_name) ?></div>
                        <div class="profile-dd-role">Student<?= $student_year_level ? ' · ' . htmlspecialchars($student_year_level) : '' ?></div>
                    </div>
                </div>
                <div class="profile-dd-body">
                    <button class="profile-dd-btn" onclick="openPhotoModal()">
                        <i class="fa-solid fa-camera"></i> Update Profile Photo
                    </button>
                    <div class="profile-dd-divider"></div>
                    <a href="../logout.php" class="profile-dd-btn logout" style="text-decoration:none;">
                        <i class="fa-solid fa-right-from-bracket"></i> Log out
                    </a>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- PHOTO UPLOAD MODAL -->
<div class="photo-modal-overlay" id="photoModal">
    <div class="photo-modal">
        <div class="photo-modal-header">
            <div class="photo-modal-title">Profile Photo</div>
            <button style="background:none;border:none;color:var(--muted);font-size:18px;cursor:pointer;" onclick="closePhotoModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="photo-modal-body">
            <form method="POST" action="update_photo.php" enctype="multipart/form-data" id="photoForm">
                <div class="photo-upload-circle" id="photoCircle" onclick="document.getElementById('photoFileInput').click()">
                    <img id="photoPreviewImg" src="<?= $student_photo ? '../image/'.htmlspecialchars($student_photo) : '' ?>"
                         style="<?= $student_photo ? 'display:block' : '' ?>"/>
                    <i class="fa-solid fa-camera upload-icon" id="uploadIconEl" style="<?= $student_photo ? 'display:none' : '' ?>"></i>
                </div>
                <div class="photo-upload-hint">
                    Click to choose a photo<br>
                    <span onclick="document.getElementById('photoFileInput').click()">Browse files</span>
                    &nbsp;·&nbsp; JPG, PNG, WebP · Max 10MB
                </div>
                <input type="file" id="photoFileInput" name="photo" accept="image/jpeg,image/png,image/webp,image/gif"
                       onchange="previewPhoto(this)" style="display:none"/>
            </form>
        </div>
        <div class="photo-modal-footer">
            <button class="btn-skip-photo" onclick="closePhotoModal()">Skip / Cancel</button>
            <button class="btn-save-photo" onclick="submitPhoto()"><i class="fa-solid fa-check"></i> Save Photo</button>
        </div>
    </div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <div class="side-section-label">Evaluations</div>
        <div class="side-nav-item active" id="nav-dashboard" onclick="switchView('dashboard')">
            <i class="fa-solid fa-house"></i> Dashboard
        </div>
        <div class="side-nav-item" id="nav-evaluate" onclick="switchView('evaluate')">
            <i class="fa-solid fa-star-half-stroke"></i> Evaluation
            <?php if ($total_pending > 0): ?><span class="side-nav-badge"><?= $total_pending ?></span><?php endif; ?>
        </div>
        <div class="side-nav-item" id="nav-history" onclick="switchView('history')">
            <i class="fa-solid fa-clock-rotate-left"></i> Evaluation History
        </div>
        <div class="side-nav-item" id="nav-guidelines" onclick="switchView('guidelines')">
            <i class="fa-solid fa-circle-info"></i> Guidelines
        </div>
    </aside>

    <div class="main">

        <?php if ($submit_success): ?>
        <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($submit_success) ?></div>
        <?php endif; ?>
        <?php if ($submit_error): ?>
        <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($submit_error) ?></div>
        <?php endif; ?>
        <?php if (!$student_year_level): ?>
        <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> Your account has no year level set. Please contact the admin so faculty/staff can be assigned to you correctly.</div>
        <?php endif; ?>

        <!-- ══════════════ DASHBOARD VIEW ══════════════ -->
        <div class="view-content active" id="view-dashboard">
            <div class="page-title">
                Welcome, <?= htmlspecialchars(explode(' ', $student_name)[0]) ?>
                <?php if ($student_year_level): ?>
                <span class="year-level-badge" style="font-size:13px;font-weight:600;color:var(--muted);"> · <?= htmlspecialchars($student_year_level) ?></span>
                <?php endif; ?>
            </div>
            <div class="page-sub">Here's an overview of your faculty &amp; staff evaluations.</div>

            <div class="period-pill <?= $period_is_open ? 'open' : 'closed' ?>">
                <i class="fa-solid <?= $period_is_open ? 'fa-lock-open' : 'fa-lock' ?>"></i>
                Evaluation period is currently <?= $period_is_open ? 'OPEN' : 'CLOSED' ?>
            </div>

            <div class="stat-grid">
                <div class="stat-card"><i class="fa-solid fa-users"></i><div class="stat-num"><?= $total_evaluatees ?></div><div class="stat-lbl">To evaluate</div></div>
                <div class="stat-card"><i class="fa-solid fa-circle-check"></i><div class="stat-num"><?= $total_done ?></div><div class="stat-lbl">Completed</div></div>
                <div class="stat-card"><i class="fa-solid fa-hourglass-half"></i><div class="stat-num"><?= $total_pending ?></div><div class="stat-lbl">Pending</div></div>
                <div class="stat-card"><i class="fa-solid fa-percent"></i><div class="stat-num"><?= $pct ?>%</div><div class="stat-lbl">Progress</div></div>
            </div>

            <?php if ($total_evaluatees > 0): ?>
            <div class="progress-wrap">
                <div class="progress-label">Progress: <span><?= $total_done ?> of <?= $total_evaluatees ?></span> evaluated</div>
                <div class="progress-bar-bg"><div class="progress-bar-fill" style="width:<?= $pct ?>%"></div></div>
                <div class="progress-pct"><?= $pct ?>%</div>
            </div>
            <?php endif; ?>

            <div class="dash-cta">
                <div class="dash-cta-icon"><i class="fa-solid fa-star-half-stroke"></i></div>
                <div class="dash-cta-text">
                    <h3><?= $total_pending > 0 ? "You have $total_pending evaluation" . ($total_pending !== 1 ? 's' : '') . " left" : "All evaluations complete" ?></h3>
                    <p><?= $total_pending > 0 ? 'Head over to the Evaluate section to keep going.' : 'Thank you for completing all your evaluations!' ?></p>
                </div>
                <button class="btn-primary-cta" onclick="switchView('evaluate')">
                    <i class="fa-solid fa-arrow-right"></i> Go to Evaluate
                </button>
            </div>
        </div>

        <!-- ══════════════ EVALUATE VIEW ══════════════ -->
        <div class="view-content" id="view-evaluate">
            <div class="page-title">Faculty &amp; Staff Evaluation</div>
            <div class="page-sub">Select a category to see who is available for evaluation. A person may appear in more than one evaluation context when they have additional responsibilities.</div>

            <?php
            // Build the top-level display list: "Faculty" (Teacher + Staff
            // combined, for the card/stats only -- the panel below still
            // separates them into subsections), "Multi-Role", and
            // "School Head".
            $top_level = [];
            if (isset($grouped['Faculty'])) {
                $teachers=array_map(fn($p)=>array_merge($p,['_evaluation_context'=>'teacher']),$grouped['Faculty']['Teacher']);
                $staffers=array_map(fn($p)=>array_merge($p,['_evaluation_context'=>'staff']),$grouped['Faculty']['Staff']);
                $top_level['Faculty']=array_merge($teachers,$staffers);
            }
            if (!empty($grouped['Multi-Role'])) {
                $top_level['Multi-Role']=array_map(fn($p)=>array_merge($p,['_evaluation_context'=>'multi_role']),$grouped['Multi-Role']);
            }
            if (!empty($grouped['School Head'])) {
                $top_level['School Head']=array_map(fn($p)=>array_merge($p,['_evaluation_context'=>'school_head']),$grouped['School Head']);
            }
            ?>

            <?php if (empty($top_level)): ?>
            <div class="empty"><i class="fa-solid fa-users-slash"></i><p>No faculty or staff available for evaluation yet.</p></div>
            <?php else: ?>

            <div class="section-label">Choose a category</div>
            <div class="category-grid">
                <?php foreach ($top_level as $group_name => $persons):
                    $slug     = strtolower(str_replace([' ','-'], '_', $group_name));
                    $total    = count($persons);
                    $done_ct=count(array_filter($persons,fn($p)=>isset($done_ids[$p['id'].'|'.($p['_evaluation_context']??'teacher')])));
                    $all_done = ($total > 0 && $done_ct === $total);
                    $icon     = $group_icons[$group_name] ?? 'fa-user';
                    $color    = $group_colors[$group_name] ?? '#D97706';
                ?>
                <div class="cat-btn <?= $all_done ? 'all-done' : '' ?>"
                     id="catbtn_<?= $slug ?>" onclick="togglePanel('<?= $slug ?>')"
                     style="border-color:<?= $color ?>33;">
                    <div class="cat-icon" style="color:<?= $color ?>;"><i class="fa-solid <?= $icon ?>"></i></div>
                    <div class="cat-name"><?= htmlspecialchars($group_name) ?></div>
                    <div class="cat-meta"><?= $total ?> member<?= $total !== 1 ? 's' : '' ?></div>
                    <?php if ($done_ct > 0): ?>
                    <div class="cat-done-pill"><i class="fa-solid fa-check"></i> <?= $done_ct ?>/<?= $total ?> done</div>
                    <?php endif; ?>
                    <div class="cat-active-arrow" style="border-top-color:<?= $color ?>;"></div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php
            // Reusable person-card renderer, used by the Faculty
            // subsections, the Multi-Role panel, and the School Head
            // panel below. $p['teaching_levels'], when present (Multi-Role
            // people only), renders as a "Teaching Assignment: ..." line.
            function render_person_card($p, $done_ids, $period_is_open) {
                $context=$p['_evaluation_context']??'teacher';
                $is_done=isset($done_ids[$p['id'].'|'.$context]);
                $eval_args = json_encode([
                    'id'    => $p['id'],
                    'name'  => $p['full_name'],
                    'desig' => $p['designation'] ?: ($p['role'] === 'teacher' ? 'Teacher' : 'Personnel'),
                    'photo'=>$p['photo']?'../image/'.$p['photo']:'',
                    'context'=>$p['_evaluation_context']??'teacher',
                ]);
                $teaching_levels = trim($p['teaching_levels'] ?? '');
                ob_start();
                ?>
                <div class="person-card <?= $is_done ? 'done' : '' ?>">
                    <?php if ($is_done): ?>
                    <span class="done-badge"><i class="fa-solid fa-check"></i> Done</span>
                    <?php endif; ?>
                    <?php if ($p['photo']): ?>
                    <img class="person-avatar" src="../image/<?= htmlspecialchars($p['photo']) ?>"
                         alt="<?= htmlspecialchars($p['full_name']) ?>"/>
                    <?php else: ?>
                    <div class="person-avatar-ph"><i class="fa-solid fa-user"></i></div>
                    <?php endif; ?>
                    <div class="person-name"><?= htmlspecialchars($p['full_name']) ?></div>
                    <div class="person-desig"><?= htmlspecialchars($p['designation'] ?: '—') ?></div>
                    <?php if ($teaching_levels !== ''): ?>
                    <div class="person-teaching-line">Teaching Assignment: <?= htmlspecialchars($teaching_levels) ?></div>
                    <?php endif; ?>

                    <?php if (!$is_done): ?>
                    <button type="button" class="eval-btn" onclick="openEvalFromData(this)"
                        <?= !$period_is_open ? 'disabled title="No evaluation period is currently open"' : '' ?>
                        data-eval='<?= htmlspecialchars($eval_args, ENT_QUOTES) ?>'>
                        <i class="fa-solid fa-star-half-stroke"></i> Evaluate
                    </button>
                    <?php endif; ?>
                </div>
                <?php
                return ob_get_clean();
            }
            ?>

            <?php foreach ($top_level as $group_name => $persons):
                $slug  = strtolower(str_replace([' ','-'], '_', $group_name));
                $icon  = $group_icons[$group_name] ?? 'fa-user';
                $color = $group_colors[$group_name] ?? '#D97706';
            ?>
            <div class="members-panel" id="panel_<?= $slug ?>" style="border-color:<?= $color ?>44;">
                <div class="panel-header">
                    <i class="fa-solid <?= $icon ?> panel-header-icon" style="color:<?= $color ?>;"></i>
                    <span class="panel-header-title"><?= htmlspecialchars($group_name) ?></span>
                    <span class="panel-header-count">&mdash; <?= count($persons) ?> member<?= count($persons) !== 1 ? 's' : '' ?></span>
                    <button class="panel-close-btn" onclick="closePanel('<?= $slug ?>')">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <?php if ($group_name === 'Faculty'): ?>
                    <?php
                    // Faculty splits into Teacher / Staff subsections in the
                    // SAME panel -- students never see a separate Faculty
                    // sub-tab, they just scroll through both groups here.
                    $subgroups = [
                        'Teacher'=>array_map(fn($p)=>array_merge($p,['_evaluation_context'=>'teacher']),$grouped['Faculty']['Teacher']),
                        'Staff'=>array_map(fn($p)=>array_merge($p,['_evaluation_context'=>'staff']),$grouped['Faculty']['Staff']),
                    ];
                    foreach ($subgroups as $sub_name => $sub_persons):
                        if (empty($sub_persons)) continue;
                        $sub_icon  = $group_icons[$sub_name] ?? 'fa-user';
                        $sub_color = $group_colors[$sub_name] ?? '#D97706';
                    ?>
                    <div class="subgroup-header" style="color:<?= $sub_color ?>;">
                        <i class="fa-solid <?= $sub_icon ?>"></i>
                        <span style="color:#fff;"><?= htmlspecialchars($sub_name) ?></span>
                        <span class="subgroup-count">(<?= count($sub_persons) ?>)</span>
                    </div>
                    <div class="members-grid">
                        <?php foreach ($sub_persons as $p) echo render_person_card($p, $done_ids, $period_is_open); ?>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="members-grid">
                        <?php foreach ($persons as $p) echo render_person_card($p, $done_ids, $period_is_open); ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <?php endif; ?>
        </div>

        <!-- ══════════════ HISTORY VIEW ══════════════ -->
        <div class="view-content" id="view-history">
            <div class="page-title">Evaluation History</div>
            <div class="page-sub">Faculty and staff you've already evaluated.</div>

            <?php if (empty($history)): ?>
            <div class="empty"><i class="fa-solid fa-clock-rotate-left"></i><p>You haven't submitted any evaluations yet.</p></div>
            <?php else: ?>
            <div class="history-list">
                <?php foreach ($history as $h):
                    $avg = $h['avg_score'] !== null ? round($h['avg_score'], 1) : null;
                ?>
                <div class="history-card">
                    <?php if ($h['photo']): ?>
                    <img class="history-avatar" src="../image/<?= htmlspecialchars($h['photo']) ?>" alt=""/>
                    <?php else: ?>
                    <div class="history-avatar-ph"><i class="fa-solid fa-user"></i></div>
                    <?php endif; ?>
                    <div class="history-info">
                        <div class="history-name"><?= htmlspecialchars($h['full_name']) ?></div>
                        <div class="history-desig"><?= htmlspecialchars($h['designation'] ?: '—') ?></div>
                        <?php if (!empty($h['remarks'])): ?>
                        <div class="history-comment">"<?= htmlspecialchars(mb_strimwidth($h['remarks'], 0, 120, '…')) ?>"</div>
                        <?php endif; ?>
                    </div>
                    <div class="history-meta">
                        <?php if ($avg !== null): ?>
                        <div class="history-score"><i class="fa-solid fa-star"></i> <?= $avg ?> / 5</div>
                        <?php endif; ?>
                        <div class="history-date"><?= htmlspecialchars(date('M j, Y', strtotime($h['submitted_at']))) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- ══════════════ GUIDELINES VIEW ══════════════ -->
        <div class="view-content" id="view-guidelines">
            <div class="page-title">Evaluation Guidelines</div>
            <div class="page-sub">How to evaluate faculty and staff fairly and accurately.</div>

            <div class="gl-card">
                <h3><i class="fa-solid fa-scale-balanced"></i> Rating Scale</h3>
                <div class="gl-scale-row"><div class="gl-scale-num">5</div><p><strong>Always</strong> — consistently demonstrates this</p></div>
                <div class="gl-scale-row"><div class="gl-scale-num">4</div><p><strong>Often</strong> — usually demonstrates this</p></div>
                <div class="gl-scale-row"><div class="gl-scale-num">3</div><p><strong>Sometimes</strong> — demonstrates this about half the time</p></div>
                <div class="gl-scale-row"><div class="gl-scale-num">2</div><p><strong>Rarely</strong> — seldom demonstrates this</p></div>
                <div class="gl-scale-row"><div class="gl-scale-num">1</div><p><strong>Never</strong> — does not demonstrate this</p></div>
            </div>

            <div class="gl-card">
                <h3><i class="fa-solid fa-circle-check"></i> Rules</h3>
                <ul>
                    <li>You can only evaluate faculty and staff assigned to your education level.</li>
                    <li>Each person can only be evaluated once per evaluation period.</li>
                    <li>Submissions cannot be edited once submitted, so review your ratings before sending.</li>
                    <li>Evaluations can only be submitted while an evaluation period is open.</li>
                </ul>
            </div>

            <div class="gl-card">
                <h3><i class="fa-solid fa-shield-halved"></i> Confidentiality</h3>
                <p>Your individual ratings and comments are used to help faculty and staff improve. Please answer honestly and constructively.</p>
            </div>
        </div>

    </div>
</div>

<!-- EVALUATION MODAL -->
<div class="modal-overlay" id="evalModal">
    <div class="modal">
        <div class="modal-header">
            <div id="modalAvatarWrap"></div>
            <div>
                <div class="modal-name" id="modalName"></div>
                <div class="modal-desig" id="modalDesig"></div>
            </div>
            <button class="modal-close" onclick="closeEval()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="student_dashboard.php" id="evalForm">
            <input type="hidden" name="submit_evaluation" value="1"/>
            <input type="hidden" name="target_user_id" id="targetUserId"/>
            <input type="hidden" name="evaluation_context" id="evaluationContext" value="teacher"/>
            <div class="modal-body" id="modalBody">
                <div class="loading-qs"><i class="fa-solid fa-spinner"></i> Loading questions...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel-modal" onclick="closeEval()">Cancel</button>
                <button type="submit" class="btn-submit">
                    <i class="fa-solid fa-paper-plane"></i> Submit Evaluation
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let activePanel     = null;
let questionsLoaded = false;

// ── Sidebar view switching ──
function switchView(view) {
    document.querySelectorAll('.view-content').forEach(v => v.classList.remove('active'));
    document.getElementById('view-' + view)?.classList.add('active');
    document.querySelectorAll('.side-nav-item').forEach(i => i.classList.remove('active'));
    document.getElementById('nav-' + view)?.classList.add('active');
    closeSidebarMobile();
    window.scrollTo({top: 0, behavior: 'smooth'});
}

// ── Mobile sidebar drawer ──
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('open');
}
function closeSidebarMobile() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('open');
}

// ── Profile dropdown ──
function toggleProfileDD() {
    const dd    = document.getElementById('profileDropdown');
    const caret = document.getElementById('profileCaret');
    dd.classList.toggle('open');
    caret.style.transform = dd.classList.contains('open') ? 'rotate(180deg)' : '';
}
document.addEventListener('click', function(e) {
    if (!document.getElementById('navProfile').contains(e.target)) {
        document.getElementById('profileDropdown').classList.remove('open');
        document.getElementById('profileCaret').style.transform = '';
    }
});

// ── Photo modal ──
function openPhotoModal() {
    document.getElementById('profileDropdown').classList.remove('open');
    document.getElementById('photoModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closePhotoModal() {
    document.getElementById('photoModal').classList.remove('open');
    document.body.style.overflow = '';
}
function previewPhoto(input) {
    if (input.files && input.files[0]) {
        const r = new FileReader();
        r.onload = e => {
            const img = document.getElementById('photoPreviewImg');
            const ic  = document.getElementById('uploadIconEl');
            img.src = e.target.result;
            img.style.display = 'block';
            ic.style.display  = 'none';
        };
        r.readAsDataURL(input.files[0]);
    }
}
function submitPhoto() {
    const fileInput = document.getElementById('photoFileInput');
    if (!fileInput.files || !fileInput.files[0]) { closePhotoModal(); return; }
    document.getElementById('photoForm').submit();
}
document.getElementById('photoModal').addEventListener('click', function(e) {
    if (e.target === this) closePhotoModal();
});

// ── Category panel toggle (Evaluate view) ──
function togglePanel(slug) {
    if (activePanel && activePanel !== slug) _closePanel(activePanel);
    if (activePanel === slug) { _closePanel(slug); activePanel = null; }
    else { _openPanel(slug); activePanel = slug; }
}
function _openPanel(slug) {
    document.getElementById('panel_' + slug)?.classList.add('open');
    document.getElementById('catbtn_' + slug)?.classList.add('active');
    setTimeout(() => document.getElementById('panel_' + slug)?.scrollIntoView({behavior:'smooth',block:'nearest'}), 50);
}
function _closePanel(slug) {
    document.getElementById('panel_' + slug)?.classList.remove('open');
    document.getElementById('catbtn_' + slug)?.classList.remove('active');
}
function closePanel(slug) { _closePanel(slug); if (activePanel === slug) activePanel = null; }

// ── Eval modal ──
function openEvalFromData(btn) {
    const d = JSON.parse(btn.getAttribute('data-eval'));
    openEval(d.id,d.name,d.desig,d.photo,d.context||'teacher');
}
function openEval(id,name,desig,photo,context){
    questionsLoaded = false;
    document.getElementById('targetUserId').value=id;
    const ctxInput=document.getElementById('evaluationContext'); if(ctxInput)ctxInput.value=context||'teacher';
    document.getElementById('modalName').textContent  = name;
    document.getElementById('modalDesig').textContent = desig || 'Faculty / Staff';
    const wrap = document.getElementById('modalAvatarWrap');
    if (photo) {
        const img = document.createElement('img');
        img.className = 'modal-avatar'; img.src = photo; img.alt = name;
        img.onerror = () => img.outerHTML = '<div class="modal-avatar-ph"><i class="fa-solid fa-user"></i></div>';
        wrap.innerHTML = ''; wrap.appendChild(img);
    } else {
        wrap.innerHTML = '<div class="modal-avatar-ph"><i class="fa-solid fa-user"></i></div>';
    }
    document.getElementById('modalBody').innerHTML =
        '<div class="loading-qs"><i class="fa-solid fa-spinner"></i> Loading questions...</div>';
    document.getElementById('evalModal').classList.add('open');
    document.body.style.overflow = 'hidden';
    loadQuestions(id,context||'teacher');
}
function closeEval() {
    document.getElementById('evalModal').classList.remove('open');
    document.body.style.overflow = '';
    questionsLoaded = false;
}

function loadQuestions(id,context){
    fetch(`student_dashboard.php?get_questions=1&target_id=${id}&context=${encodeURIComponent(context||'teacher')}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                document.getElementById('modalBody').innerHTML =
                    `<div class="empty"><i class="fa-solid fa-triangle-exclamation"></i>
                     <p style="color:#fca5a5;margin-top:10px;font-size:13px">${data.error}</p></div>`;
                return;
            }
            const questions = data.questions;
            const legend = `<div class="scale-legend">
                <div class="legend-item"><div class="legend-dot">5</div> Always</div>
                <div class="legend-item"><div class="legend-dot">4</div> Often</div>
                <div class="legend-item"><div class="legend-dot">3</div> Sometimes</div>
                <div class="legend-item"><div class="legend-dot">2</div> Rarely</div>
                <div class="legend-item"><div class="legend-dot">1</div> Never</div>
            </div>`;
            const labels  = {5:'Always',4:'Often',3:'Sometimes',2:'Rarely',1:'Never'};
            const grouped = {};
            questions.forEach(q => {
                const cat = q.category || 'General';
                if (!grouped[cat]) grouped[cat] = [];
                grouped[cat].push(q);
            });
            let html = legend;
            let qNum = 1;
            for (const [cat, qs] of Object.entries(grouped)) {
                html += `<div class="q-category"><i class="fa-solid fa-layer-group" style="margin-right:5px;font-size:10px"></i>${cat}</div>`;
                qs.forEach(q => {
                    html += `<div class="q-item">
                        <div class="q-text"><span class="q-num-badge">${qNum++}.</span> ${q.question_text}</div>
                        <div class="rating-row">
                            ${[5,4,3,2,1].map(v =>
                               `<button type="button"
    class="r-btn"
    data-qkey="${q.question_source}:${q.id}"
    data-val="${v}"
    onclick="selectRating(this,'${q.question_source}:${q.id}',${v})">
                                    ${v}<span class="r-val">${labels[v]}</span>
                                </button>`
                            ).join('')}
                        </div>
                        <input type="hidden"
       name="rating[${q.question_source}:${q.id}]"
       id="r_${q.question_source}_${q.id}"
       value=""/>
                    </div>`;
                });
            }
            html += `<div class="comment-box">
                <div class="comment-label">
                    <i class="fa-solid fa-comment-dots"></i>
                    Comments, Suggestions &amp; Areas for Improvement
                    <span class="comment-optional">(Optional)</span>
                </div>
                <textarea name="comment" class="comment-textarea"
                    placeholder="Share your thoughts, suggestions, or concerns about this person's performance..."
                    rows="4"></textarea>
            </div>`;
            document.getElementById('modalBody').innerHTML = html;
            questionsLoaded = true;
        })
        .catch(err => {
            document.getElementById('modalBody').innerHTML =
                `<div class="empty"><i class="fa-solid fa-triangle-exclamation"></i>
                 <p style="color:#fca5a5;margin-top:10px;font-size:13px">
                    Failed to load questions.<br><small>${err.message}</small></p></div>`;
        });
}

function selectRating(btn, qkey, val) {
    document.querySelectorAll(`.r-btn[data-qkey="${qkey}"]`)
        .forEach(b => b.classList.remove('selected'));

    btn.classList.add('selected');

    const safeKey = qkey.replace(':', '_');
    const h = document.getElementById(`r_${safeKey}`);

    if (h) {
        h.value = val;
    }
}

document.getElementById('evalModal').addEventListener('click', function(e) {
    if (e.target === this) closeEval();
});

document.getElementById('evalForm').addEventListener('submit', function(e) {
    if (!questionsLoaded) { e.preventDefault(); alert('Questions are still loading. Please wait.'); return; }
    const hiddens    = this.querySelectorAll('input[type="hidden"][name^="rating["]');
    if (!hiddens.length) { e.preventDefault(); alert('No questions found. Please close and try again.'); return; }
    const unanswered = [...hiddens].filter(h => !h.value);
    if (unanswered.length) { e.preventDefault(); alert(`Please answer all questions. (${unanswered.length} remaining)`); return; }
});

// If the page reloaded after a submit-evaluation POST (e.g. validation
// error/success), keep the user on the Evaluate view instead of bouncing
// them back to Dashboard, since that's where the alert is relevant.
<?php if ($submit_success || $submit_error): ?>
switchView('evaluate');
<?php endif; ?>
</script>
</body>
</html>
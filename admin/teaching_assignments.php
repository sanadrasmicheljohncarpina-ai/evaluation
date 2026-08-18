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
require_once '../shared/EvaluationContextService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin','superadmin'])) {
    header("Location: admin_login.php"); exit;
}
echo "<div style='padding:40px;font-family:sans-serif;color:#E0E6F0;background:#0A192F;min-height:100vh;'>Teaching Assignment management is being rebuilt. Contact the system administrator for manual assignment changes in the meantime.</div>";
exit;

$student_id   = $_SESSION['user_id'];
$student_name = $_SESSION['full_name'];

// ── DESIGNATION TOKEN → ADMIN target_type MAPPING ─────────────
// Mirrors admin/questionnaire.php's 3-bucket model exactly:
//   - Faculty     -> shared pool in `evaluation_questions`
//   - Staff       -> PER-PERSON questions in `user_questions`
//   - Multi-Role  -> shared pool in `evaluation_questions`
$system_categories = ['Faculty', 'Staff', 'Multi-Role'];

$token_to_target = [
    'Teacher'         => 'Faculty',
    'Faculty'         => 'Faculty',
    'Adviser'         => 'Faculty',
    'Coordinator'     => 'Faculty',
    'Department Head' => 'Faculty',
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
    'teacher'     => 'Faculty',
    'faculty'     => 'Faculty',
    'instructor'  => 'Faculty',
    'professor'   => 'Faculty',
    'adviser'     => 'Faculty',
    'advisor'     => 'Faculty',
    'coordinator' => 'Faculty',
    'head'        => 'Faculty',
    'principal'   => 'Faculty',
    'dean'        => 'Faculty',
    'tutor'       => 'Faculty',
];

// Resolve a single designation token to 'Faculty' or 'Staff'.
// Identical logic to admin/questionnaire.php's resolveTarget().
function resolveTarget($raw_token, $token_to_target, $keyword_to_target, $role = 'teacher') {
    $raw_token = trim($raw_token);
    if (isset($token_to_target[$raw_token])) return $token_to_target[$raw_token];
    $lower = strtolower($raw_token);
    foreach ($keyword_to_target as $keyword => $mapped) {
        if (strpos($lower, $keyword) !== false) return $mapped;
    }
    return ($role === 'teacher') ? 'Faculty' : 'Staff';
}

// Resolve a user's designation string (possibly comma-separated) down
// to their PRIMARY bucket -- 'Faculty' or 'Staff' -- using the first
// recognizable token. Identical to admin's per-user resolution.
function resolveUserTarget($designation, $role, $token_to_target, $keyword_to_target) {
    $raw = trim($designation ?? '');
    if ($raw === '') $raw = ($role === 'teacher') ? 'Teacher' : 'Personnel';
    $tokens = array_filter(array_map('trim', explode(',', $raw)), fn($t) => $t !== '');
    if (empty($tokens)) $tokens = [$raw];
    return resolveTarget($tokens[0], $token_to_target, $keyword_to_target, $role);
}

// A user is Multi-Role if their comma-separated designation tokens
// resolve to 2+ distinct buckets (Faculty AND Staff). Identical to
// admin/questionnaire.php's isMultiRole().
function isMultiRoleUser($designation, $token_to_target, $keyword_to_target, $role = 'teacher') {
    return ec_has_additional_role([
        'designation' => $designation,
        'role' => $role,
        'secondary_role' => ''
    ]);
}

// ── ACTIVE EVALUATION PERIOD ──────────────────────────────────
// Fetched once up front so the Dashboard, Guidelines, and the submit
// handler below all agree on whether evaluations are currently open.
$activePeriodRow = $mysqli->query("SELECT id FROM evaluation_periods WHERE is_active=1 LIMIT 1")->fetch_assoc();
$period_is_open  = (bool)$activePeriodRow;

// ── HANDLE EVALUATION SUBMISSION ─────────────────────────────
$submit_error   = '';
$submit_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_evaluation'])) {
    $target_id = intval($_POST['target_user_id']);

    if ($target_id <= 0) {
        $submit_error = "Invalid submission data. Please try again.";
    } elseif (!$period_is_open) {
        $submit_error = "No evaluation period is currently open. Please check back later.";
    } else {
        $period_id = (int)$activePeriodRow['id'];

        $chk = $mysqli->prepare(
            "SELECT id FROM evaluation_tracker WHERE evaluator_id=? AND target_user_id=? AND period_id=?"
        );
        $chk->bind_param("iii", $student_id, $target_id, $period_id);
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
                        "INSERT INTO evaluation_tracker (evaluator_id, target_user_id, remarks, eval_type, period_id, status, submitted_at)
                         VALUES (?, ?, ?, 'student', ?, 'submitted', NOW())"
                    );
                    $trk->bind_param("iisi", $student_id, $target_id, $comment, $period_id);
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
                        "INSERT INTO questionnaire_answers (tracker_id, question_id, answer_score, submitted_at)
                         VALUES (?, ?, ?, NOW())"
                    );
                    foreach ($ratings as $q_id => $rating) {
                        $q_id  = intval($q_id);
                        $score = max(1, min(5, floatval($rating)));
                        $ins->bind_param("iid", $tracker_id, $q_id, $score);
                        $ins->execute();
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
//   - Staff bucket      -> user_questions (per-person, keyed by user_id)
//   - Faculty bucket     -> evaluation_questions (shared pool, target_type='Faculty')
//   - Multi-Role (extra) -> evaluation_questions (shared pool, target_type='Multi-Role'),
//                           merged in addition to the primary bucket's questions
if (isset($_GET['get_questions'])) {
    header('Content-Type: application/json');
    try {
        $target_id = intval($_GET['target_id']);

        $userRow = $mysqli->query(
            "SELECT designation, role FROM users WHERE id=$target_id AND is_active=1 LIMIT 1"
        )->fetch_assoc();

        if (!$userRow) throw new Exception("User not found.");

        $designation = $userRow['designation'] ?? '';
        $role        = $userRow['role'] ?? 'teacher';
        $primary     = resolveUserTarget($designation, $role, $token_to_target, $keyword_to_target);
        $is_mr       = isMultiRoleUser($designation, $token_to_target, $keyword_to_target, $role);

        $questions = [];

        if ($primary === 'Staff') {
            // FIXED: this query was previously truncated with a literal "..."
            // left in the SQL string, which threw a mysqli_sql_exception
            // (mysqli_report is set to MYSQLI_REPORT_STRICT above) every time
            // a Staff-bucket person was opened for evaluation. Completed it
            // to match the shape of the Faculty/Multi-Role queries below,
            // including the missing ORDER BY.
            $uq = $mysqli->prepare(
                "SELECT id, question_text, category
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
                "SELECT id, question_text, category
                 FROM evaluation_questions
                 WHERE target_type = 'Faculty' AND eval_type = 'student'
                 ORDER BY category, id"
            );
            $qs->execute();
            $questions = array_merge($questions, $qs->get_result()->fetch_all(MYSQLI_ASSOC));
            $qs->close();
        }

        if ($is_mr) {
            $mrq = $mysqli->prepare(
                "SELECT id, question_text, category
                 FROM evaluation_questions
                 WHERE target_type = 'Multi-Role' AND eval_type = 'student'
                 ORDER BY category, id"
            );
            $mrq->execute();
            $questions = array_merge($questions, $mrq->get_result()->fetch_all(MYSQLI_ASSOC));
            $mrq->close();
        }

        if (empty($questions)) {
            $hint = $primary === 'Staff'
                ? "Questionnaire → Staff → this person individually"
                : "Questionnaire → Faculty";
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

// ── FETCH STUDENT'S OWN PHOTO + EDUCATION LEVEL ───────────────
$phRes = $mysqli->prepare("SELECT photo, education_level, year_level, section FROM users WHERE id=? LIMIT 1");
$phRes->bind_param("i", $student_id);
$phRes->execute();
$phRow = $phRes->get_result()->fetch_assoc();
$phRes->close();
$student_photo      = $phRow['photo'] ?? '';
$student_level      = $phRow['education_level'] ?? null;
$student_year_level = $phRow['year_level'] ?? null;
$student_section    = $phRow['section'] ?? null;

// ── FETCH EVALUATEES ─────────────────────────────────────────
// `teaching_assignments` is now the SOLE authoritative source for who a
// student is allowed to evaluate. A teacher/staff member only appears
// here if a row in `teaching_assignments` actually assigns them to this
// student's education_level + year_level + section -- NOT because of
// their role alone, and NOT via any per-user "levels" side table. This
// replaces the old `user_year_levels` join and the old query that
// ignored section entirely.
//
// The student's own education_level, year_level, and section are read
// from `users` only to know WHO the student is -- they are never used
// to filter the teacher/staff side of the query. Eligibility itself is
// determined solely by matching rows in `teaching_assignments`.
//
// A teaching_assignments row with a NULL section is treated as covering
// the WHOLE year level (e.g. a subject teacher assigned to all sections
// of Grade 10), while a row with a specific section only matches
// students in that exact section (e.g. an adviser assigned to one
// section). If the student has no section on file, only level-wide
// (section IS NULL) assignments are matched, since there's no section
// value to compare against a section-specific row.
//
// `u.role IN ('teacher','staff')` is kept only as a defensive filter
// alongside the join -- it never substitutes for the join, and a role
// alone (without a matching teaching_assignments row) is never
// sufficient to make someone appear here.
//
// If the admin-side tracker (student_tracker.php) is also meant to
// reflect this, it needs the same `teaching_assignments` join (including
// the section-matching logic below) so the two views stay in agreement.
$all_users = [];
if ($student_level && $student_year_level) {
    if ($student_section) {
        $res = $mysqli->prepare(
            "SELECT DISTINCT u.id, u.full_name, u.designation, u.photo, u.role
             FROM teaching_assignments ta
             JOIN users u ON u.id = ta.user_id
             WHERE ta.education_level = ?
               AND ta.year_level = ?
               AND (ta.section IS NULL OR ta.section = ?)
               AND u.role IN ('teacher','staff')
               AND u.is_active = 1
             ORDER BY u.full_name ASC"
        );
        $res->bind_param("sss", $student_level, $student_year_level, $student_section);
    } else {
        $res = $mysqli->prepare(
            "SELECT DISTINCT u.id, u.full_name, u.designation, u.photo, u.role
             FROM teaching_assignments ta
             JOIN users u ON u.id = ta.user_id
             WHERE ta.education_level = ?
               AND ta.year_level = ?
               AND ta.section IS NULL
               AND u.role IN ('teacher','staff')
               AND u.is_active = 1
             ORDER BY u.full_name ASC"
        );
        $res->bind_param("ss", $student_level, $student_year_level);
    }
    $res->execute();
    $all_users = $res->get_result()->fetch_all(MYSQLI_ASSOC);
    $res->close();
}
// ── GROUP INTO THE 3 ADMIN TARGET TYPES ───────────────────────
// A multi-role person appears under BOTH their primary bucket AND
// Multi-Role, matching how the admin dashboard itself displays them
// (evaluated under primary role, and independently listed as
// Multi-Role too).
$grouped = [];
foreach ($system_categories as $cat) $grouped[$cat] = [];

foreach ($all_users as $u) {
    $designation = $u['designation'] ?? '';
    $role        = $u['role'] ?? 'teacher';

    $target = resolveUserTarget($designation, $role, $token_to_target, $keyword_to_target);
    if (isset($grouped[$target])) $grouped[$target][] = $u;

    if (isMultiRoleUser($designation, $token_to_target, $keyword_to_target, $role)) {
        $grouped['Multi-Role'][] = $u;
    }
}

// Remove empty groups so students only see categories with people in them
$grouped = array_filter($grouped, fn($g) => !empty($g));

// ── FETCH ALREADY EVALUATED IDs ──────────────────────────────
$done_ids = [];
$dres = $mysqli->prepare(
    "SELECT target_user_id FROM evaluation_tracker WHERE evaluator_id=?"
);
$dres->bind_param("i", $student_id);
$dres->execute();
$dres->bind_result($done_id);
while ($dres->fetch()) $done_ids[] = $done_id;
$dres->close();

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
    'Faculty'    => 'fa-chalkboard-user',
    'Staff'      => 'fa-briefcase',
    'Multi-Role' => 'fa-layer-group',
];
$group_colors = [
    'Faculty'    => '#00E5FF',
    'Staff'      => '#10b981',
    'Multi-Role' => '#F59E0B',
];

// total_evaluatees / progress counts are based on the flat, de-duplicated
// $all_users list (not $grouped, where multi-role people can appear
// twice for display) so the percentage still matches student_tracker.php.
$total_evaluatees = count($all_users);
$total_done       = count($done_ids);
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

.members-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;padding:18px;}
.person-card{background:var(--inner);border:1px solid var(--border);border-radius:12px;padding:18px 14px;text-align:center;transition:all .22s;position:relative;}
.person-card:hover:not(.done){border-color:var(--gold);transform:translateY(-2px);box-shadow:0 6px 20px rgba(0,0,0,.4);}
.person-card.done{opacity:.55;}
.person-avatar{width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid var(--border);margin:0 auto 10px;display:block;background:var(--mid);}
.person-avatar-ph{width:64px;height:64px;border-radius:50%;background:var(--mid);border:2px solid var(--border);margin:0 auto 10px;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:22px;}
.person-name{font-size:13px;font-weight:600;color:#fff;margin-bottom:3px;line-height:1.3;}
.person-desig{font-size:11px;color:var(--muted);margin-bottom:2px;}
.done-badge{position:absolute;top:9px;right:9px;background:rgba(34,197,94,.2);border:1px solid rgba(34,197,94,.4);color:#4ade80;border-radius:20px;font-size:10px;font-weight:700;padding:2px 8px;}
.eval-btn{margin-top:11px;width:100%;padding:8px;background:var(--gold);border:none;border-radius:7px;color:#fff;font-size:12px;font-weight:700;cursor:pointer;transition:background .2s;}
.eval-btn:hover{background:var(--gold-h);}
.eval-btn:disabled{background:var(--inner);color:var(--muted);cursor:not-allowed;}

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
            <i class="fa-solid fa-star-half-stroke"></i> Evaluate Faculty/Staff
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
            <div class="page-sub">Select a category to see who is available for evaluation. Each person can only be evaluated once.</div>

            <?php if (empty($grouped)): ?>
            <div class="empty"><i class="fa-solid fa-users-slash"></i><p>No faculty or staff available for evaluation yet.</p></div>
            <?php else: ?>

            <div class="section-label">Choose a category</div>
            <div class="category-grid">
                <?php foreach ($grouped as $group_name => $persons):
                    $slug     = strtolower(str_replace([' ','-'], '_', $group_name));
                    $total    = count($persons);
                    $done_ct  = count(array_filter($persons, fn($p) => in_array($p['id'], $done_ids)));
                    $all_done = ($done_ct === $total);
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

            <?php foreach ($grouped as $group_name => $persons):
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

                <div class="members-grid">
                    <?php foreach ($persons as $p):
                        $is_done = in_array($p['id'], $done_ids);
                        $eval_args = json_encode([
                            'id'    => $p['id'],
                            'name'  => $p['full_name'],
                            'desig' => $p['designation'] ?: ($p['role'] === 'teacher' ? 'Teacher' : 'Personnel'),
                            'photo' => $p['photo'] ? '../image/' . $p['photo'] : '',
                        ]);
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

                        <?php if (!$is_done): ?>
                        <button type="button" class="eval-btn" onclick="openEvalFromData(this)"
                            <?= !$period_is_open ? 'disabled title="No evaluation period is currently open"' : '' ?>
                            data-eval='<?= htmlspecialchars($eval_args, ENT_QUOTES) ?>'>
                            <i class="fa-solid fa-star-half-stroke"></i> Evaluate
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
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
                    <li>You can only evaluate faculty and staff assigned to teach your education level, year level, and section.</li>
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
    openEval(d.id, d.name, d.desig, d.photo);
}
function openEval(id, name, desig, photo) {
    questionsLoaded = false;
    document.getElementById('targetUserId').value = id;
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
    loadQuestions(id);
}
function closeEval() {
    document.getElementById('evalModal').classList.remove('open');
    document.body.style.overflow = '';
    questionsLoaded = false;
}

function loadQuestions(id) {
    fetch(`student_dashboard.php?get_questions=1&target_id=${id}`)
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
                                `<button type="button" class="r-btn" data-qid="${q.id}" data-val="${v}"
                                    onclick="selectRating(this,${q.id},${v})">
                                    ${v}<span class="r-val">${labels[v]}</span>
                                </button>`
                            ).join('')}
                        </div>
                        <input type="hidden" name="rating[${q.id}]" id="r_${q.id}" value=""/>
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

function selectRating(btn, qid, val) {
    document.querySelectorAll(`.r-btn[data-qid="${qid}"]`).forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    const h = document.getElementById(`r_${qid}`);
    if (h) h.value = val;
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
<?php $mysqli->close(); ?>
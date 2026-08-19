<?php
// principal_evaluate.php
// The per-person evaluation form. Reached from principal_evaluations.php
// via ?tid=<user_id>. Renders the live question set from
// questionnaire_questions (routed through questionnaire_forms by
// eval_type), and on submit writes one evaluation_tracker row + one
// questionnaire_answers row per question.
//
// Forms used: id=5 'supervisor_to_teacher' (sector=Teacher),
//             id=6 'supervisor_to_staff'   (sector=Staff)
// Both are shared with Dean's future evaluate flow — same schema,
// different evaluator/scope. See project notes on the shared Evaluation
// Engine convention.

require_once 'principal_common.php';

$evaluator_id = (int)$_SESSION['user_id'];
$tid = isset($_GET['tid']) ? (int)$_GET['tid'] : 0;

if ($tid <= 0) {
    header("Location: principal_evaluations.php");
    exit;
}

// ── LOAD TARGET ────────────────────────────────────────────
// Teacher/Staff are scoped to Basic Education via user_year_levels (the
// year levels a Super Admin has assigned them). Executive Assistant is NOT
// scoped that way — it's the admin role itself, evaluable regardless of
// grade level, so it needs its own lookup path.
$requestedBucket = $_GET['bucket'] ?? null;
if (!in_array($requestedBucket, ['Teacher', 'Staff', 'Executive Assistant'], true)) $requestedBucket = null;

if ($requestedBucket === 'Executive Assistant') {
    $stmt = $mysqli->prepare("
        SELECT id, full_name, designation, photo, role, secondary_role, year_level
        FROM users
        WHERE id=? AND role='superadmin' AND is_active=1 AND account_status='approved'
        LIMIT 1
    ");
    $stmt->bind_param("i", $tid);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} else {
    // NOTE: scoped via user_year_levels (what "Assign Year Level(s)" on the
    // accounts page actually writes to), NOT users.academic_level — that
    // column exists but nothing populates it, so filtering on it excludes
    // everyone. Same fix as principal_evaluations.php's roster query.
    $scopeYearLevels = array_map(fn($g) => "Grade {$g}", $scopeGrades);
    $scopeYearLevelsIn = esc_list($mysqli, $scopeYearLevels);
    $stmt = $mysqli->prepare("
        SELECT id, full_name, designation, photo, role, secondary_role
        FROM users
        WHERE id=? AND role IN ('teacher','staff') AND is_active=1 AND account_status='approved'
          AND EXISTS (
              SELECT 1 FROM user_year_levels uyl
              WHERE uyl.user_id = users.id AND uyl.year_level IN ($scopeYearLevelsIn)
          )
        LIMIT 1
    ");
    $stmt->bind_param("i", $tid);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Not found under Teacher/Staff — try EA in case tid legitimately
    // belongs to an EA and no bucket param was passed (direct link, etc).
    if (!$target) {
        $stmt = $mysqli->prepare("
            SELECT id, full_name, designation, photo, role, secondary_role, year_level
            FROM users
            WHERE id=? AND role='superadmin' AND is_active=1 AND account_status='approved'
            LIMIT 1
        ");
        $stmt->bind_param("i", $tid);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

// Derive academic_level for the record we'll write later (junior_high /
// senior_high / '') from year_level, same pattern used elsewhere — there is
// no stored academic_level column being read here anymore.
if ($target) {
    $targetGradeNum = null;
    if (preg_match('/^Grade\s*(7|8|9|10|11|12)\b/i', $target['year_level'] ?? '', $m)) $targetGradeNum = $m[1];
    $target['academic_level'] = in_array($targetGradeNum, ['7', '8', '9', '10'], true) ? 'junior_high'
        : (in_array($targetGradeNum, ['11', '12'], true) ? 'senior_high' : '');
}

if (!$target) {
    html_head_open('PBI — Not Found');
    render_principal_sidebar('evaluations', $me, $scopeLabel, $photo_src);
    ?>
    <main class="main">
        <a class="back-link" href="principal_evaluations.php"><i class="fa-solid fa-arrow-left"></i> Back to Evaluations</a>
        <div class="section"><p class="empty-note">That person wasn't found, or isn't within your scope.</p></div>
    </main>
    </body></html>
    <?php
    $mysqli->close();
    exit;
}

// ── RESOLVE BUCKET / FORM ───────────────────────────────────
// Three categories: Teacher, Staff, Executive Assistant. For a Multi-Role
// person (teacher + secondary staff, or vice versa), the ?bucket= param
// from the roster tab they clicked from decides which questionnaire loads
// — that's what lets the same person be evaluated once as Teacher and
// separately as Staff. Falls back to primary role if no bucket was passed
// (e.g. a bookmarked/direct link).
if ($target['role'] === 'executive_assistant') {
    $bucket = 'Executive Assistant';
} elseif ($requestedBucket === 'Staff' && $target['secondary_role'] === 'staff') {
    $bucket = 'Staff';
} elseif ($requestedBucket === 'Teacher' && $target['secondary_role'] === 'teacher') {
    $bucket = 'Teacher';
} else {
    $bucket = $target['role'] === 'teacher' ? 'Teacher' : 'Staff';
}
$evalTypeMap = ['Teacher' => 'supervisor_to_teacher', 'Staff' => 'supervisor_to_staff', 'Executive Assistant' => 'supervisor_to_ea'];
$evalType = $evalTypeMap[$bucket];

$formStmt = $mysqli->prepare("SELECT id, title FROM questionnaire_forms WHERE eval_type=? AND is_active=1 LIMIT 1");
$formStmt->bind_param("s", $evalType);
$formStmt->execute();
$form = $formStmt->get_result()->fetch_assoc();
$formStmt->close();

if (!$form) {
    html_head_open('PBI — Form Not Configured');
    render_principal_sidebar('evaluations', $me, $scopeLabel, $photo_src);
    ?>
    <main class="main">
        <a class="back-link" href="principal_evaluations.php"><i class="fa-solid fa-arrow-left"></i> Back to Evaluations</a>
        <div class="section"><p class="empty-note">No active <?= htmlspecialchars($bucket) ?> evaluation form is configured yet. Contact your administrator.</p></div>
    </main>
    </body></html>
    <?php
    $mysqli->close();
    exit;
}
$form_id = (int)$form['id'];

// ── ALREADY EVALUATED THIS PERIOD? ──────────────────────────
$already_id = null;
if ($period_id_int) {
    $chk = $mysqli->prepare("SELECT id FROM evaluation_tracker WHERE evaluator_id=? AND target_user_id=? AND eval_type=? AND period_id=? LIMIT 1");
    $chk->bind_param("iisi", $evaluator_id, $tid, $evalType, $period_id_int);
    $chk->execute();
    $already = $chk->get_result()->fetch_assoc();
    $chk->close();
    if ($already) $already_id = (int)$already['id'];
}

// ── LOAD QUESTIONS ───────────────────────────────────────────
$qstmt = $mysqli->prepare("SELECT id, question_no, question, type, max_score, is_required FROM questionnaire_questions WHERE form_id=? ORDER BY question_no ASC, id ASC");
$qstmt->bind_param("i", $form_id);
$qstmt->execute();
$questions = $qstmt->get_result()->fetch_all(MYSQLI_ASSOC);
$qstmt->close();

$errors = [];
$success = false;

// ── HANDLE SUBMISSION ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$already_id) {
    if (!$period_id_int) {
        $errors[] = "There's no active evaluation period — submissions are closed.";
    } else {
        $answers = [];
        $scoreSum = 0.0;
        $scoreCount = 0;

        foreach ($questions as $q) {
            $qid = (int)$q['id'];
            $field = 'q_' . $qid;
            $val = trim($_POST[$field] ?? '');

            if ($q['is_required'] && $val === '') {
                $errors[] = "Please answer: " . $q['question'];
                continue;
            }
            if ($val === '') continue;

            if ($q['type'] === 'rating') {
                $score = (float)$val;
                $max = (float)($q['max_score'] ?? 5.00);
                if ($score < 1 || $score > $max) {
                    $errors[] = "Rating out of range for: " . $q['question'];
                    continue;
                }
                $answers[] = ['question_id' => $qid, 'answer_text' => null, 'answer_score' => $score];
                $scoreSum += $score;
                $scoreCount++;
            } else {
                $answers[] = ['question_id' => $qid, 'answer_text' => $val, 'answer_score' => null];
            }
        }

        $overallRemarks = trim($_POST['overall_remarks'] ?? '');

        if (empty($errors)) {
            $overallScore = $scoreCount > 0 ? round($scoreSum / $scoreCount, 2) : null;

            $mysqli->begin_transaction();
            try {
                $ins = $mysqli->prepare("
                    INSERT INTO evaluation_tracker
                        (evaluator_id, target_user_id, eval_bucket, level, form_type, form_id, period_id, score, remarks, eval_type, status)
                    VALUES (?,?,?,?,?,?,?,?,?,?, 'submitted')
                ");
                $ins->bind_param(
                    "iisssiidss",
                    $evaluator_id, $tid, $bucket, $target['academic_level'], $form['title'], $form_id, $period_id_int, $overallScore, $overallRemarks, $evalType
                );
                $ins->execute();
                $tracker_id = $mysqli->insert_id;
                $ins->close();

                if (!empty($answers)) {
                    $ains = $mysqli->prepare("INSERT INTO questionnaire_answers (tracker_id, question_id, answer_text, answer_score) VALUES (?,?,?,?)");
                    foreach ($answers as $a) {
                        $ains->bind_param("iisd", $tracker_id, $a['question_id'], $a['answer_text'], $a['answer_score']);
                        $ains->execute();
                    }
                    $ains->close();
                }

                $mysqli->commit();
                $_SESSION['toast'] = "Evaluation submitted for " . $target['full_name'] . ".";
                header("Location: principal_evaluations.php");
                exit;
            } catch (mysqli_sql_exception $e) {
                $mysqli->rollback();
                $errors[] = "Something went wrong saving this evaluation. Nothing was recorded — please try again.";
            }
        }
    }
}

$mysqli->close();

$tPhoto = !empty($target['photo']) ? '../image/' . $target['photo'] : '../image/pbi_logo';

html_head_open('PBI — Evaluate ' . ($target['full_name'] ?? ''));
?>
<style>
/* Card-based question layout, matching the reference mockup's structure
   (boxed card per question, pill-style rating buttons) but kept in the
   Principal portal's amber accent instead of a literal violet, to stay
   consistent with the rest of this portal (sidebar, buttons, badges).
   Say the word if you actually want the violet swapped in instead. */
.eval-q-card{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:22px 24px;box-shadow:var(--shadow);margin-bottom:16px;}
.eval-q-category{font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--amber-h);margin-bottom:6px;}
.eval-q-text{font-size:15px;color:var(--light);font-weight:600;margin-bottom:16px;line-height:1.5;}
.eval-q-text .req-star{color:var(--danger);}
.eval-rating-row{display:flex;gap:12px;flex-wrap:wrap;}
.eval-rating-opt{flex:1;min-width:70px;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:14px 10px;cursor:pointer;font-size:14px;font-weight:600;color:var(--light);transition:border-color .15s,background .15s;}
.eval-rating-opt input{position:absolute;opacity:0;pointer-events:none;}
.eval-rating-opt:hover{border-color:rgba(217,154,43,.4);}
.eval-rating-opt:has(input:checked){border-color:var(--amber);border-width:2px;background:rgba(217,154,43,.1);color:#fff;}
.eval-rating-scale-note{font-size:11px;color:var(--muted);margin-top:10px;}
.eval-comment-box{width:100%;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:14px 16px;color:var(--light);font-size:14px;font-family:'DM Sans',sans-serif;resize:vertical;}
.eval-comment-box:focus{outline:none;border-color:var(--amber);}
.eval-submit-btn{margin-top:6px;}
</style>
<?php render_principal_sidebar('evaluations', $me, $scopeLabel, $photo_src); ?>
<main class="main">
    <a class="back-link" href="principal_evaluations.php"><i class="fa-solid fa-arrow-left"></i> Back to Evaluations</a>

    <div class="section">
        <div class="profile-card">
            <img class="profile-photo-lg" src="<?= htmlspecialchars($tPhoto) ?>" alt="">
            <div>
                <div class="page-title" style="font-size:22px;"><?= htmlspecialchars($target['full_name']) ?></div>
                <div class="page-sub"><?= htmlspecialchars($target['designation'] ?: $bucket) ?></div>
            </div>
            <span class="pill <?= $bucket === 'Teacher' ? 'good' : 'warn' ?>" style="margin-left:auto;"><?= htmlspecialchars($bucket) ?></span>
        </div>
    </div>

    <?php if ($already_id): ?>
    <div class="section">
        <div class="alert success" style="margin-bottom:0;">
            <i class="fa-solid fa-circle-check"></i> You've already submitted an evaluation for <?= htmlspecialchars($target['full_name']) ?> this period.
        </div>
    </div>
    <?php else: ?>

    <?php foreach ($errors as $err): ?>
    <div class="alert error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($err) ?></div>
    <?php endforeach; ?>

    <?php if (!$period_id_int): ?>
    <div class="alert error"><i class="fa-solid fa-clock"></i> No active evaluation period. You can review the form below, but submission is disabled until an admin opens a period.</div>
    <?php endif; ?>

    <div class="section" style="background:transparent;border:none;box-shadow:none;padding:0 0 8px;">
        <h2 style="margin-bottom:18px;"><i class="fa-solid fa-clipboard-list"></i> <?= htmlspecialchars($form['title']) ?></h2>

        <?php if (empty($questions)): ?>
        <p class="empty-note">No questions have been added to this form yet.</p>
        <?php else: ?>
        <form method="post">
            <?php foreach ($questions as $i => $q): $field = 'q_' . $q['id']; $posted = $_POST[$field] ?? ''; ?>
            <div class="eval-q-card">
                <div class="eval-q-text">
                    <?= $i + 1 ?>. <?= htmlspecialchars($q['question']) ?><?= $q['is_required'] ? ' <span class="req-star">*</span>' : '' ?>
                </div>

                <?php if ($q['type'] === 'rating'):
                    $max = (int)round((float)($q['max_score'] ?? 5)); ?>
                <div class="eval-rating-row">
                    <?php for ($n = 1; $n <= $max; $n++): ?>
                    <label class="eval-rating-opt">
                        <input type="radio" name="<?= $field ?>" value="<?= $n ?>" <?= (string)$posted === (string)$n ? 'checked' : '' ?> <?= $q['is_required'] ? 'required' : '' ?>>
                        <?= $n ?>
                    </label>
                    <?php endfor; ?>
                </div>
                <div class="eval-rating-scale-note">1 = Needs Improvement · <?= $max ?> = Outstanding</div>

                <?php elseif ($q['type'] === 'yes_no'): ?>
                <div class="eval-rating-row">
                    <label class="eval-rating-opt">
                        <input type="radio" name="<?= $field ?>" value="Yes" <?= $posted === 'Yes' ? 'checked' : '' ?> <?= $q['is_required'] ? 'required' : '' ?>> Yes
                    </label>
                    <label class="eval-rating-opt">
                        <input type="radio" name="<?= $field ?>" value="No" <?= $posted === 'No' ? 'checked' : '' ?> <?= $q['is_required'] ? 'required' : '' ?>> No
                    </label>
                </div>

                <?php else: /* text or fallback */ ?>
                <textarea name="<?= $field ?>" rows="3" class="eval-comment-box" <?= $q['is_required'] ? 'required' : '' ?>><?= htmlspecialchars($posted) ?></textarea>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <div class="eval-q-card">
                <div class="eval-q-category">Comments / Suggestions</div>
                <textarea name="overall_remarks" rows="3" class="eval-comment-box" placeholder="Any additional feedback..."><?= htmlspecialchars($_POST['overall_remarks'] ?? '') ?></textarea>
            </div>

            <button class="btn-primary eval-submit-btn" type="submit" <?= !$period_id_int ? 'disabled style="opacity:.5;cursor:not-allowed;"' : '' ?>>
                <i class="fa-solid fa-paper-plane"></i> Submit Evaluation
            </button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</main>
</body>
</html>
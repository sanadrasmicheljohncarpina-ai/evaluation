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

if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: faculty_login.php"); exit;
}

$user_id     = $_SESSION['user_id'];
$full_name   = $_SESSION['full_name'];
$designation = $_SESSION['designation'] ?? 'Teacher';
$page        = $_GET['page'] ?? 'dashboard';

// ── CSRF TOKEN ────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function csrf_check(): bool {
    return isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
}

// ── FETCH PROFILE PHOTO ───────────────────────────────────────
$phRes = $mysqli->prepare("SELECT photo FROM users WHERE id=? LIMIT 1");
$phRes->bind_param("i", $user_id);
$phRes->execute();
$faculty_photo = $phRes->get_result()->fetch_assoc()['photo'] ?? '';
$phRes->close();

// ── VALID SPECIFIC YEAR LEVELS ───────────────────────────────────
// Same exact list & string values as admin/manage_privileged_accounts.php
// uses for students and for teacher/staff assignment — keeping these
// identical is what lets a faculty member's picks match real students.
$year_levels = [
    'Grade 7','Grade 8','Grade 9','Grade 10',
    'Grade 11','Grade 12',
    '1st Year College','2nd Year College','3rd Year College','4th Year College',
];

// ── ENSURE user_year_levels TABLE EXISTS ────────────────────────
// Shared junction table with the admin page — a person can have more
// than one specific year level checked (e.g. Grade 8 AND Grade 10).
$mysqli->query("
    CREATE TABLE IF NOT EXISTS user_year_levels (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        year_level VARCHAR(30) NOT NULL,
        UNIQUE KEY uniq_user_year (user_id, year_level),
        CONSTRAINT fk_uyl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )
");

// ── FETCH MY ASSIGNED TEACHING LEVEL(S) ─────────────────────────
// A faculty member can be assigned to more than one SPECIFIC year
// level at once (e.g. Grade 8 AND Grade 10), so this is a list, not
// a single value.
$my_levels = [];
$lvlQ = $mysqli->prepare("SELECT year_level FROM user_year_levels WHERE user_id=?");
$lvlQ->bind_param("i", $user_id);
$lvlQ->execute();
$lvlRes = $lvlQ->get_result();
while ($lr = $lvlRes->fetch_assoc()) $my_levels[] = $lr['year_level'];
$lvlQ->close();

// ── ACTIVE PERIOD ─────────────────────────────────────────────
$period = null;
$pr = $mysqli->query("SELECT * FROM evaluation_periods WHERE is_active=1 LIMIT 1");
if ($pr) $period = $pr->fetch_assoc();

// ── MY EVALUATION RESULTS ─────────────────────────────────────
$my_avg   = null;
$my_total = 0;
$my_scores = [];

if ($period) {
    $period_id_int = (int)$period['id'];
    $res_stmt = $mysqli->prepare("
        SELECT AVG(qa.answer_score) as avg_score, COUNT(DISTINCT et.id) as total
        FROM questionnaire_answers qa
        JOIN evaluation_tracker et ON et.id = qa.tracker_id
        WHERE et.target_user_id = ? AND et.period_id = ?
    ");
    $res_stmt->bind_param("ii", $user_id, $period_id_int);
} else {
    $res_stmt = $mysqli->prepare("
        SELECT AVG(qa.answer_score) as avg_score, COUNT(DISTINCT et.id) as total
        FROM questionnaire_answers qa
        JOIN evaluation_tracker et ON et.id = qa.tracker_id
        WHERE et.target_user_id = ?
    ");
    $res_stmt->bind_param("i", $user_id);
}
$res_stmt->execute();
$res = $res_stmt->get_result();
if ($res) {
    $row      = $res->fetch_assoc();
    $my_avg   = $row['avg_score'] !== null ? round($row['avg_score'], 2) : null;
    $my_total = $row['total'] ?? 0;
}
$res_stmt->close();

if ($period) {
    $period_id_int = (int)$period['id'];
    $cat_stmt = $mysqli->prepare("
        SELECT eq.category, AVG(qa.answer_score) as avg_cat, COUNT(qa.id) as cnt
        FROM questionnaire_answers qa
        JOIN evaluation_tracker et ON et.id = qa.tracker_id
        JOIN evaluation_questions eq ON eq.id = qa.question_id
        WHERE et.target_user_id = ? AND et.period_id = ?
        GROUP BY eq.category
    ");
    $cat_stmt->bind_param("ii", $user_id, $period_id_int);
} else {
    $cat_stmt = $mysqli->prepare("
        SELECT eq.category, AVG(qa.answer_score) as avg_cat, COUNT(qa.id) as cnt
        FROM questionnaire_answers qa
        JOIN evaluation_tracker et ON et.id = qa.tracker_id
        JOIN evaluation_questions eq ON eq.id = qa.question_id
        WHERE et.target_user_id = ?
        GROUP BY eq.category
    ");
    $cat_stmt->bind_param("i", $user_id);
}
$cat_stmt->execute();
$cat_res = $cat_stmt->get_result();
if ($cat_res) $my_scores = $cat_res->fetch_all(MYSQLI_ASSOC);
$cat_stmt->close();

// ── DESIGNATION → QUESTION TARGET_TYPE MAPPING ────────────────
// Same mapping staff_dashboard.php uses, so a colleague's free-text
// designation resolves to the same category and the same Teacher/Staff
// grouping on both portals.
$system_categories = ['Faculty','Registrar','Cashier','Bookkeeper','Librarian','Guidance','Nurse','Personnel'];
$token_to_target   = [
    'Teacher'=>'Faculty','Faculty'=>'Faculty','Registrar'=>'Registrar','Cashier'=>'Cashier',
    'Bookkeeper'=>'Bookkeeper','Librarian'=>'Librarian','Guidance'=>'Guidance','Nurse'=>'Nurse',
    'Personnel'=>'Personnel','Staff'=>'Personnel','Adviser'=>'Faculty','Coordinator'=>'Faculty',
    'Department Head'=>'Faculty',
];
function resolve_target_type($desig, $map, $cats, $fallback_role = null) {
    $tokens = array_filter(array_map('trim', explode(',', $desig ?? '')), fn($t) => $t !== '');
    // Pass 1: exact token match against the map (e.g. "Bookkeeper", "Teacher")
    // or an exact system category (e.g. "Registrar").
    foreach ($tokens as $tok) {
        if (isset($map[$tok])) return $map[$tok];
        if (in_array($tok, $cats)) return $tok;
    }
    // Pass 2: free-text designations don't always match a token exactly
    // (e.g. "Math Teacher", "Senior Teacher", "Head Librarian"). Fall back
    // to a case-insensitive substring match against the same map keys so
    // these aren't silently miscategorized as generic Personnel.
    foreach ($tokens as $tok) {
        foreach ($map as $key => $target) {
            if (stripos($tok, $key) !== false) return $target;
        }
        foreach ($cats as $cat) {
            if (stripos($tok, $cat) !== false) return $cat;
        }
    }
    // No usable designation text (empty/unset, common for newly
    // self-registered teachers who haven't set one yet on their Profile
    // page) — fall back to the account's actual role instead of silently
    // defaulting everyone to Personnel/Staff.
    if ($fallback_role === 'teacher') return 'Faculty';
    return 'Personnel';
}

// NOTE ON "TEACHER vs STAFF": mirrors staff_dashboard.php's peer-evaluation
// grouping. A colleague's account role (teacher/staff) isn't always the
// same as how they should be grouped for peer evaluation, so we resolve
// the group off the same designation map used everywhere else, not off
// the raw account role column — except as a fallback when the designation
// text doesn't resolve to anything (see resolve_target_type() above).
function resolve_peer_group($desig, $map, $cats, $fallback_role = null) {
    return resolve_target_type($desig, $map, $cats, $fallback_role) === 'Faculty' ? 'teacher' : 'staff';
}
function eval_type_label($eval_type, $peer_group = null) {
    switch ($eval_type) {
        case 'student':               return 'Student Evaluation';
        case 'peer':
        case 'faculty_peer':          return 'Peer Evaluation' . ($peer_group ? ' (' . $peer_group . ')' : '');
        case 'school_head':           return 'School Head Evaluation';
        case 'supervisor_to_teacher':
        case 'supervisor_to_staff':
        case 'supervisor_to_ea':      return 'Supervisor Evaluation';
        default:                      return ucwords(str_replace('_', ' ', $eval_type ?: 'Evaluation'));
    }
}
$peer_group_labels = ['teacher' => 'Teacher', 'staff' => 'Staff'];

// ── ADD peer_group COLUMN TO evaluation_tracker (idempotent) ──
// Shared table with the staff dashboard — the column may already exist
// if staff_dashboard.php has run first.
$colChk = $mysqli->query("SHOW COLUMNS FROM evaluation_tracker LIKE 'peer_group'");
if ($colChk && $colChk->num_rows === 0) {
    $mysqli->query("ALTER TABLE evaluation_tracker ADD COLUMN peer_group VARCHAR(20) NULL AFTER eval_type");
}

// ── PEER EVALUATION ───────────────────────────────────────────
// Step 1: pick a designation group (Teacher or Staff) — a teacher may
// need to peer-evaluate either a fellow teacher or a staff member they
// worked with. Step 2: pick a specific person from that filtered list.
$peers_all  = [];   // all eligible peers (teachers + staff), unfiltered — used for the sidebar badge count
$peers      = [];   // peers filtered down to the selected group (Teacher/Staff)
$done_peers = [];
$peer_group = null; // 'teacher' | 'staff' | null (Step 1 not yet completed)

if ($page === 'peer') {
    $pr2 = $mysqli->prepare("SELECT id, full_name, designation, photo, role FROM users WHERE role IN ('teacher','staff') AND is_active=1 AND id != ? ORDER BY full_name ASC");
    $pr2->bind_param("i", $user_id);
    $pr2->execute();
    $pr2res = $pr2->get_result();
    if ($pr2res) $peers_all = $pr2res->fetch_all(MYSQLI_ASSOC);
    $pr2->close();

    if ($period) {
        $period_id_int = (int)$period['id'];
        $dpStmt = $mysqli->prepare("SELECT target_user_id FROM evaluation_tracker WHERE evaluator_id=? AND period_id=? AND eval_type='faculty_peer'");
        $dpStmt->bind_param("ii", $user_id, $period_id_int);
        $dpStmt->execute();
        $dp = $dpStmt->get_result();
        if ($dp) while ($r = $dp->fetch_assoc()) $done_peers[] = $r['target_user_id'];
        $dpStmt->close();
    }

    if (isset($_GET['group']) && in_array($_GET['group'], ['teacher', 'staff'], true)) {
        $peer_group = $_GET['group'];
        $peers = array_values(array_filter($peers_all, function ($p) use ($peer_group, $token_to_target, $system_categories) {
            return resolve_peer_group($p['designation'] ?? '', $token_to_target, $system_categories, $p['role'] ?? null) === $peer_group;
        }));
    }
}

// ── PEER EVAL FORM ────────────────────────────────────────────
$peer_target       = null;
$peer_questions    = [];
$peer_form_id      = 0;
$peer_eval_group   = null;  // group carried over from Step 1, validated below
$peer_group_error  = '';    // set when tid/group don't match, shown in the invalid-target view

if ($page === 'peer_eval' && isset($_GET['tid'])) {
    $tid = intval($_GET['tid']);
    $req_group = $_GET['group'] ?? null;

    $tu  = $mysqli->prepare("SELECT * FROM users WHERE id=? AND role IN ('teacher','staff') AND is_active=1 LIMIT 1");
    $tu->bind_param("i", $tid); $tu->execute();
    $peer_target = $tu->get_result()->fetch_assoc(); $tu->close();

    if ($peer_target && !in_array($req_group, ['teacher', 'staff'], true)) {
        $peer_group_error = "Please select a designation.";
        $peer_target = null;
    } elseif ($peer_target) {
        $actual_group = resolve_peer_group($peer_target['designation'] ?? '', $token_to_target, $system_categories, $peer_target['role'] ?? null);
        if ($actual_group !== $req_group) {
            $peer_group_error = "The selected user does not belong to the selected designation.";
            $peer_target = null;
        } else {
            $peer_eval_group = $req_group;
        }
    }

    if ($peer_target) {
// 1) form lookup
$fu = $mysqli->prepare("SELECT id FROM questionnaire_forms WHERE eval_type='peer' AND is_active=1 LIMIT 1");

// 2) "already evaluated" lookup (Step 2 peer grid)
$dpStmt = $mysqli->prepare("SELECT target_user_id FROM evaluation_tracker WHERE evaluator_id=? AND period_id=? AND eval_type='peer'");

// 3) the actual insert
$eval_type  = 'peer';

// 4) duplicate-submission check
$dup = $mysqli->prepare("SELECT id FROM evaluation_tracker WHERE evaluator_id=? AND target_user_id=? AND period_id=? AND eval_type='peer' LIMIT 1");

        // Questionnaire Management assigns questions differently per bucket:
        // Staff targets get questions assigned per individual user (the
        // "Assign Questions" feature -> user_questions, keyed by user_id),
        // while Teacher targets use the shared evaluation_questions pool
        // keyed off the EA's 'Teacher' bucket (not the granular designation
        // category resolve_target_type() returns, e.g. 'Registrar'/'Nurse' -
        // the admin tool never stores questions under those values).
        if ($peer_eval_group === 'staff') {
            $qs = $mysqli->prepare("SELECT * FROM user_questions WHERE user_id=? AND eval_type='peer' ORDER BY category ASC, sort_order ASC, id ASC");
            $qs->bind_param("i", $tid); $qs->execute();
        } else {
            $qs = $mysqli->prepare("SELECT * FROM evaluation_questions WHERE target_type='Teacher' AND eval_type='peer' ORDER BY category ASC, id ASC");
            $qs->execute();
        }
        $peer_questions = $qs->get_result()->fetch_all(MYSQLI_ASSOC); $qs->close();
    }
}

// ── NOTIFICATIONS TABLE ───────────────────────────────────────
$mysqli->query("CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50) NOT NULL DEFAULT 'designation_update',
    user_id INT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    extra_data TEXT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_unread (is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── ROLE CHANGE LOG TABLE ────────────────────────────────────
// This is what powers the admin dashboard's "System Audits" box and bell.
$mysqli->query("CREATE TABLE IF NOT EXISTS role_change_log (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT NOT NULL,
    old_role         VARCHAR(60)  NOT NULL DEFAULT '',
    new_role         VARCHAR(60)  NOT NULL DEFAULT '',
    old_designation  VARCHAR(120) NULL,
    new_designation  VARCHAR(120) NULL,
    changed_at       DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_changed (changed_at),
    INDEX idx_user    (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── AUTO-CREATE user_preferences table ───────────────────────
// Shared with the staff dashboard — idempotent, so safe to run here too.
$mysqli->query("CREATE TABLE IF NOT EXISTS user_preferences (
    user_id INT UNSIGNED PRIMARY KEY,
    email_on_designation_update TINYINT(1) NOT NULL DEFAULT 1,
    email_on_new_evaluation     TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── ENSURE A UNIQUE CONSTRAINT BACKS THE DUPLICATE-EVAL CHECK ──
// The application-level duplicate check below (SELECT ... then INSERT) has
// a race window between two near-simultaneous submissions. This unique
// index makes the DB itself the source of truth: a second insert for the
// same (evaluator, target, eval_type, period) fails with a duplicate-key
// error instead of silently creating a second row. Shared with the staff
// dashboard's evaluation_tracker usage — idempotent, so safe to run here too.
$idxChk = $mysqli->query("SHOW INDEX FROM evaluation_tracker WHERE Key_name = 'uniq_eval_submission'");
if ($idxChk && $idxChk->num_rows === 0) {
    $mysqli->query("ALTER TABLE evaluation_tracker ADD UNIQUE INDEX uniq_eval_submission (evaluator_id, target_user_id, eval_type, period_id)");
}

// ── SUBMIT PEER EVAL ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_peer'])) {
    if (!csrf_check()) {
        $_SESSION['toast_error'] = "Your session expired or the request could not be verified. Please try again.";
        header("Location: faculty_dashboard.php?page=peer"); exit;
    }

$submitted_group = $_POST['group'] ?? '';
    $tid     = intval($_POST['target_id']);
    $fid     = intval($_POST['form_id']);
    $pid     = intval($_POST['period_id']);
    $ratings = $_POST['ratings'] ?? [];
    $comment = trim($_POST['comment'] ?? '');

    // ── Validation (per the Peer Evaluation designation-selection update) ──
    if (empty($submitted_group) || !in_array($submitted_group, ['teacher', 'staff'], true)) {
        $_SESSION['toast_error'] = "Please select a designation.";
        header("Location: faculty_dashboard.php?page=peer"); exit;
    }

    // Eligibility check — peer evaluations have no level restriction (per requirements,
    // faculty/staff can evaluate anyone they've worked with), but this still blocks
    // self-evaluation and invalid targets before anything gets inserted.
    [$eligible, $eligMsg] = canPeerEvaluate($mysqli, $user_id, $tid);

    if (!$eligible) {
        $_SESSION['toast_error'] = $eligMsg;
        header("Location: faculty_dashboard.php?page=peer&group=" . urlencode($submitted_group)); exit;
    }

    // canPeerEvaluate() only checks role, not is_active — re-verify the
    // target is still an active teacher/staff account before inserting
    // anything, and confirm it still belongs to the group that was chosen
    // in Step 1 (designations can change between selecting and submitting).
    $tchk = $mysqli->prepare("SELECT id, designation, role FROM users WHERE id=? AND role IN ('teacher','staff') AND is_active=1 LIMIT 1");
    $tchk->bind_param("i", $tid); $tchk->execute();
    $tchkRow = $tchk->get_result()->fetch_assoc(); $tchk->close();
    if (!$tchkRow) {
        $_SESSION['toast_error'] = "The selected colleague is no longer available.";
        header("Location: faculty_dashboard.php?page=peer&group=" . urlencode($submitted_group)); exit;
    }
    if (resolve_peer_group($tchkRow['designation'] ?? '', $token_to_target, $system_categories, $tchkRow['role'] ?? null) !== $submitted_group) {
        $_SESSION['toast_error'] = "The selected user does not belong to the selected designation.";
        header("Location: faculty_dashboard.php?page=peer&group=" . urlencode($submitted_group)); exit;
    }

    // Gate on an active evaluation period -- no period open, no submissions.
    if (!$pid) {
        $_SESSION['toast_error'] = "No evaluation period is currently open.";
        header("Location: faculty_dashboard.php?page=peer&group=" . urlencode($submitted_group)); exit;
    }

    // Only accept ratings for question IDs that actually belong to this
    // target's EA-assigned peer question set — anything else in the POST is
    // ignored rather than trusted, so a tampered request can't insert stray
    // rows. Mirrors the display-side fetch: per-user user_questions for
    // Staff targets, shared 'Teacher'-bucket evaluation_questions otherwise.
    if ($submitted_group === 'staff') {
        $validQStmt = $mysqli->prepare("SELECT id FROM user_questions WHERE user_id=? AND eval_type='peer'");
        $validQStmt->bind_param("i", $tid);
    } else {
        $validQStmt = $mysqli->prepare("SELECT id FROM evaluation_questions WHERE target_type='Teacher' AND eval_type='peer'");
    }
    $validQStmt->execute();
    $validQRes = $validQStmt->get_result();
    $valid_question_ids = [];
    if ($validQRes) while ($vq = $validQRes->fetch_assoc()) $valid_question_ids[] = (int)$vq['id'];
    $validQStmt->close();

    $ratings = array_filter($ratings, function ($val, $qid) use ($valid_question_ids) {
        return in_array((int)$qid, $valid_question_ids, true);
    }, ARRAY_FILTER_USE_BOTH);

    if (empty($ratings) || count($ratings) !== count($valid_question_ids)) {
        $_SESSION['toast_error'] = "Please rate all questions before submitting.";
        header("Location: faculty_dashboard.php?page=peer_eval&tid=" . $tid . "&group=" . urlencode($submitted_group)); exit;
    }

    $dup = $mysqli->prepare("SELECT id FROM evaluation_tracker WHERE evaluator_id=? AND target_user_id=? AND period_id=? AND eval_type='faculty_peer' LIMIT 1");
    $dup->bind_param("iii", $user_id, $tid, $pid); $dup->execute(); $dup->store_result();

    if ($dup->num_rows === 0) {
        $dup->close();
        try {
            $mysqli->begin_transaction();
            $overall    = count($ratings) ? round(array_sum($ratings)/count($ratings), 2) : 0;
            $eval_type  = 'faculty_peer';
            $peer_group_label = $peer_group_labels[$submitted_group] ?? ucfirst($submitted_group);
$ins = $mysqli->prepare("INSERT INTO evaluation_tracker (evaluator_id,target_user_id,form_id,period_id,eval_type,peer_group,score,remarks,status,submitted_at) VALUES (?,?,?,?,?,?,?,?,'submitted',NOW())");
            $ins->bind_param("iiiissds", $user_id, $tid, $fid, $pid, $eval_type, $peer_group_label, $overall, $comment); $ins->execute();
            $tracker_id = $mysqli->insert_id; $ins->close();

            $ri = $mysqli->prepare("INSERT INTO questionnaire_answers (tracker_id,question_id,answer_score,submitted_at) VALUES (?,?,?,NOW())");
            foreach ($ratings as $qid => $rating) {
                $qid = intval($qid); $rating = min(5, max(1, intval($rating)));
                $ri->bind_param("iii", $tracker_id, $qid, $rating); $ri->execute();
            }
            $ri->close();
            $mysqli->commit();

            // Notify the evaluated faculty member — anonymously, no evaluator identity attached.
            $notif_msg = "You have received a new peer evaluation.";
            $nins = $mysqli->prepare("INSERT INTO notifications (type, user_id, message) VALUES ('evaluation_received', ?, ?)");
            $nins->bind_param("is", $tid, $notif_msg);
            $nins->execute(); $nins->close();

            $_SESSION['toast'] = "Peer evaluation submitted!";
        } catch (Exception $e) {
            $mysqli->rollback();
            error_log('[faculty_dashboard] submit_peer failed for evaluator=' . $user_id . ' target=' . $tid . ': ' . $e->getMessage());
            // A duplicate-key error from the unique index lands here too (a
            // race between two near-simultaneous submissions) — same
            // user-facing message either way, no internal detail leaked.
            $_SESSION['toast_error'] = (($mysqli->errno ?? 0) === 1062)
                ? "You already evaluated this faculty member this period."
                : "Submission failed. Please try again.";
        }
    } else {
        $dup->close();
        $_SESSION['toast_error'] = "You already evaluated this faculty member this period.";
    }
    header("Location: faculty_dashboard.php?page=peer&group=" . urlencode($submitted_group)); exit;
}

// ── UPDATE DESIGNATION ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_designation'])) {
    if (!csrf_check()) {
        $_SESSION['toast_error'] = "Your session expired or the request could not be verified. Please try again.";
        header("Location: faculty_dashboard.php?page=profile"); exit;
    }
    $new_desig = trim($_POST['new_designation'] ?? '');
    $old_desig = $designation;

    if (empty($new_desig)) {
        $_SESSION['toast_error'] = "Designation cannot be empty.";
    } elseif ($new_desig === $old_desig) {
        $_SESSION['toast_error'] = "That's already your current designation.";
    } else {
        $stmt = $mysqli->prepare("UPDATE users SET designation=? WHERE id=?");
        $stmt->bind_param("si", $new_desig, $user_id);
        $stmt->execute(); $stmt->close();

        $_SESSION['designation'] = $new_desig;
        $designation = $new_desig;

        $message = htmlspecialchars($full_name) . " updated their designation from \"{$old_desig}\" to \"{$new_desig}\".";
        $extra = json_encode(['user_id'=>$user_id,'full_name'=>$full_name,'role'=>'teacher','old_desig'=>$old_desig,'new_desig'=>$new_desig]);
        $nstmt = $mysqli->prepare("INSERT INTO notifications (type, user_id, message, extra_data) VALUES ('designation_update', ?, ?, ?)");
        $nstmt->bind_param("iss", $user_id, $message, $extra);
        $nstmt->execute(); $nstmt->close();

        // Also log to role_change_log so it shows up in the admin dashboard's
        // System Audits box and notification bell.
        $rstmt = $mysqli->prepare("INSERT INTO role_change_log (user_id, old_role, new_role, old_designation, new_designation) VALUES (?, 'faculty', 'faculty', ?, ?)");
        $rstmt->bind_param("iss", $user_id, $old_desig, $new_desig);
        $rstmt->execute(); $rstmt->close();

        $_SESSION['toast'] = "Your designation has been updated to \"$new_desig\".";
    }
    header("Location: faculty_dashboard.php?page=profile"); exit;
}

// ── UPDATE TEACHING LEVEL(S) ──────────────────────────────────
// Multi-select — a faculty member can be assigned to more than one
// specific year level at once (e.g. teaches both Grade 8 and Grade 10).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_levels'])) {
    if (!csrf_check()) {
        $_SESSION['toast_error'] = "Your session expired or the request could not be verified. Please try again.";
        header("Location: faculty_dashboard.php?page=profile"); exit;
    }
    $selected = array_values(array_intersect($_POST['levels'] ?? [], $year_levels));

    $mysqli->begin_transaction();
    try {
        $del = $mysqli->prepare("DELETE FROM user_year_levels WHERE user_id=?");
        $del->bind_param("i", $user_id);
        $del->execute();
        $del->close();

        if (!empty($selected)) {
            $ins = $mysqli->prepare("INSERT INTO user_year_levels (user_id, year_level) VALUES (?, ?)");
            foreach ($selected as $lvl) {
                $ins->bind_param("is", $user_id, $lvl);
                $ins->execute();
            }
            $ins->close();
        }
        $mysqli->commit();

        $_SESSION['toast'] = !empty($selected)
            ? "Your teaching level(s) updated to: " . implode(', ', $selected) . "."
            : "Your teaching level assignment has been cleared. Students won't be able to evaluate you until a level is set.";
    } catch (Exception $e) {
        $mysqli->rollback();
        error_log('[faculty_dashboard] update_levels failed for user_id=' . $user_id . ': ' . $e->getMessage());
        $_SESSION['toast_error'] = "Failed to update teaching levels. Please try again.";
    }
    header("Location: faculty_dashboard.php?page=profile"); exit;
}

// ── DOCUMENTS ─────────────────────────────────────────────────
$documents = [];
if ($page === 'documents') {
    $docs = $mysqli->query("SELECT * FROM system_documents WHERE visibility IN ('Faculty','All') ORDER BY uploaded_at DESC");
    if ($docs) $documents = $docs->fetch_all(MYSQLI_ASSOC);
}

// ── RECENT SUBMISSIONS ────────────────────────────────────────
// faculty_dashboard.php — recent_subs
$rsStmt = $mysqli->prepare("
    SELECT et.id AS tracker_id,
           (SELECT AVG(qa.answer_score) FROM questionnaire_answers qa WHERE qa.tracker_id = et.id) AS overall_score,
           et.eval_type, et.peer_group, et.submitted_at, ep.period_label, ep.semester
    FROM evaluation_tracker et
    LEFT JOIN evaluation_periods ep ON ep.id = et.period_id
    WHERE et.target_user_id = ?
    ORDER BY et.submitted_at DESC LIMIT 5
");
$rsStmt->bind_param("i", $user_id);
$rsStmt->execute();
$rs = $rsStmt->get_result();
if ($rs) $recent_subs = $rs->fetch_all(MYSQLI_ASSOC);
$rsStmt->close();

// ── MARK NOTIFICATIONS READ ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_notifications_read'])) {
    if (!csrf_check()) {
        header("Location: faculty_dashboard.php?page=" . urlencode($page)); exit;
    }
    $mr = $mysqli->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0");
    $mr->bind_param("i", $user_id);
    $mr->execute(); $mr->close();
    header("Location: faculty_dashboard.php?page=" . urlencode($page)); exit;
}

// ── MY NOTIFICATIONS (bell) ──────────────────────────────────
$my_notifications = [];
$unread_count     = 0;
$nq = $mysqli->prepare("SELECT type, message, is_read, created_at FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 15");
$nq->bind_param("i", $user_id);
$nq->execute();
$nres = $nq->get_result();
if ($nres) $my_notifications = $nres->fetch_all(MYSQLI_ASSOC);
$nq->close();
foreach ($my_notifications as $n) if (empty($n['is_read'])) $unread_count++;

$desig_suggestions = ['Teacher','Registrar','Cashier','Bookkeeper','Librarian','Guidance','Nurse','Personnel','Adviser','Coordinator','Department Head'];

$toast       = $_SESSION['toast']       ?? ''; unset($_SESSION['toast']);
$toast_error = $_SESSION['toast_error'] ?? ''; unset($_SESSION['toast_error']);

// Helpers
$first_name = explode(',', $full_name)[0] ?? $full_name;
$parts      = explode(' ', trim($full_name));
$initials   = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
$perf_label = $my_avg === null ? '—' : ($my_avg >= 4 ? 'Excellent' : ($my_avg >= 3 ? 'Good' : 'Needs Improvement'));
$perf_color = $my_avg === null ? '#6b7280' : ($my_avg >= 4 ? '#4ade80' : ($my_avg >= 3 ? '#facc15' : '#f87171'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Faculty Dashboard — PBI</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<style>
:root{
    --dark:#0A192F; --mid:#172A45; --inner:#0F1F3D;
    --accent:#2B6CB0; --hover:#4C78B8;
    --teal:#0D9488; --teal-light:rgba(13,148,136,.15); --teal-hover:#14B8A6;
    --light:#E0E6F0; --muted:#A0B3C6;
    --danger:#F05454; --success:#22C55E;
    --border:rgba(255,255,255,0.08);
    --radius:12px; --shadow:0 4px 24px rgba(0,0,0,.35);
    --sidebar-w:240px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--dark);color:var(--light);min-height:100vh;display:flex;}
.sidebar{width:var(--sidebar-w);flex-shrink:0;background:var(--mid);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:40;transition:transform .3s;}
.sidebar-brand{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;cursor:pointer;transition:background .2s;position:relative;}
.sidebar-brand:hover{background:rgba(255,255,255,.04);}
.brand-avatar{width:42px;height:42px;border-radius:50%;flex-shrink:0;border:2px solid var(--teal);box-shadow:0 0 10px rgba(13,148,136,.3);overflow:hidden;background:var(--inner);display:flex;align-items:center;justify-content:center;}
.brand-avatar img{width:100%;height:100%;object-fit:cover;display:block;}
.brand-avatar .brand-initials{font-family:'Rajdhani',sans-serif;font-size:15px;font-weight:700;color:var(--teal-hover);line-height:1;}
.sidebar-title{font-family:'Rajdhani',sans-serif;font-size:14px;font-weight:700;letter-spacing:.4px;color:#fff;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:118px;}
.sidebar-sub{font-size:11px;color:var(--teal-hover);letter-spacing:.2px;margin-top:2px;font-weight:600;}
.sidebar-caret{font-size:11px;color:var(--muted);flex-shrink:0;transition:transform .2s;}
.sidebar-profile-dropdown{margin:0 12px;max-height:0;opacity:0;overflow:hidden;background:var(--inner);border-radius:10px;transition:max-height .2s ease,opacity .2s ease,margin .2s ease;}
.sidebar-profile-dropdown.open{max-height:320px;opacity:1;margin:8px 12px 10px;border:1px solid var(--border);}
.sidebar-nav{flex:1;padding:14px 12px;overflow-y:auto;}
.nav-section-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:var(--muted);padding:0 10px;margin-bottom:5px;margin-top:18px;}
.nav-section-label:first-child{margin-top:0;}
.nav-link{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:9px;color:var(--muted);text-decoration:none;font-size:13.5px;font-weight:600;transition:all .2s;margin-bottom:2px;}
.nav-link:hover{background:rgba(255,255,255,.05);color:var(--light);}
.nav-link.active{background:rgba(13,148,136,.16);color:#fff;}
.nav-link.active i{color:var(--teal-hover);}
.nav-link i{font-size:14px;width:17px;text-align:center;flex-shrink:0;}
.nav-badge{margin-left:auto;background:var(--teal);color:#fff;font-size:10px;font-weight:700;padding:1px 7px;border-radius:20px;}
.sidebar-footer{padding:14px 16px;border-top:1px solid var(--border);}
.btn-logout-side{display:flex;align-items:center;gap:8px;width:100%;padding:9px 13px;border:1px solid rgba(240,84,84,.3);background:rgba(240,84,84,.07);border-radius:9px;color:#f87171;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;transition:all .2s;font-family:'DM Sans',sans-serif;}
.btn-logout-side:hover{background:rgba(240,84,84,.16);}
.top-nav{position:fixed;top:0;left:var(--sidebar-w);right:0;height:58px;z-index:30;background:var(--mid);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 26px;box-shadow:var(--shadow);}
.nav-page-title{font-family:'Rajdhani',sans-serif;font-size:19px;font-weight:700;letter-spacing:.4px;color:#fff;}
.period-badge{background:rgba(13,148,136,.14);border:1px solid rgba(13,148,136,.28);color:var(--teal-hover);padding:5px 13px;border-radius:20px;font-size:12px;font-weight:600;display:flex;align-items:center;gap:6px;}
.hamburger{display:none;background:none;border:none;color:var(--light);font-size:20px;cursor:pointer;padding:4px;}
.main{margin-left:var(--sidebar-w);margin-top:58px;padding:26px 28px;flex:1;min-height:calc(100vh - 58px);}
.toast{border-radius:9px;padding:11px 18px;font-size:13px;margin-bottom:20px;display:flex;align-items:center;gap:9px;animation:fadeUp .3s ease;}
.toast-success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.28);color:#86efac;}
.toast-error{background:rgba(240,84,84,.1);border:1px solid rgba(240,84,84,.28);color:#fca5a5;}
@keyframes fadeUp{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.welcome-bar{background:linear-gradient(135deg, var(--mid) 0%, rgba(13,148,136,.12) 100%);border:1px solid rgba(13,148,136,.18);border-radius:var(--radius);padding:24px 28px;margin-bottom:22px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;}
.welcome-text h2{font-family:'Rajdhani',sans-serif;font-size:22px;font-weight:700;color:#fff;margin-bottom:5px;letter-spacing:.3px;}
.welcome-text p{font-size:13px;color:var(--muted);line-height:1.5;}
.welcome-text p strong{color:var(--teal-hover);}
.score-chip{background:rgba(13,148,136,.13);border:1px solid rgba(13,148,136,.25);border-radius:12px;padding:16px 26px;text-align:center;min-width:120px;flex:1 1 auto;max-width:340px;flex-shrink:0;align-self:stretch;display:flex;flex-direction:column;align-items:center;justify-content:center;}
.score-chip .sc-val{font-family:'Rajdhani',sans-serif;font-size:44px;font-weight:700;color:var(--teal-hover);line-height:1;}
.score-chip .sc-lbl{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-top:8px;}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px;}
.stat-card{background:var(--mid);border:1px solid var(--border);border-radius:var(--radius);padding:20px 22px;}
.stat-card-lbl{font-size:11px;text-transform:uppercase;letter-spacing:.9px;color:var(--muted);margin-bottom:12px;font-weight:700;}
.stat-card-val{font-size:28px;font-weight:700;color:#fff;line-height:1;}
.stat-card-val.teal{color:var(--teal-hover);}
.stat-card-val.gold{color:#F59E0B;}
.stat-card-val.sm{font-size:18px;margin-top:2px;}
.section-card{background:var(--mid);border:1px solid var(--border);border-radius:var(--radius);padding:22px 24px;margin-bottom:20px;}
.section-title{font-family:'Rajdhani',sans-serif;font-size:17px;font-weight:700;color:#fff;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.section-title i{font-size:16px;}
.cat-row{display:flex;align-items:center;gap:12px;margin-bottom:11px;}
.cat-name{font-size:13px;color:var(--light);width:200px;flex-shrink:0;}
.cat-bar-bg{flex:1;height:6px;background:rgba(255,255,255,.06);border-radius:4px;overflow:hidden;}
.cat-bar-fill{height:100%;border-radius:4px;transition:width .5s ease;}
.cat-score{font-size:13px;font-weight:700;width:36px;text-align:right;flex-shrink:0;}
.eval-item{
    background:var(--inner);
    border:1px solid var(--border);
    border-radius:10px;
    padding:14px 18px;
    margin-bottom:8px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    flex-wrap:wrap;
    gap:10px;
}
.eval-item-left .anon{font-size:13px;color:var(--light);font-weight:600;display:flex;align-items:center;gap:5px;}
.eval-item-left .meta{font-size:12px;color:var(--muted);margin-top:2px;}
.btn-view-details{background:rgba(13,148,136,.13);border:1px solid rgba(13,148,136,.3);color:var(--teal-hover);font-size:11px;font-weight:700;padding:5px 12px;border-radius:20px;cursor:pointer;white-space:nowrap;font-family:'DM Sans',sans-serif;}
.btn-view-all-evals{
    width:100%;
    display:flex;
    align-items:center;
    gap:10px;
    background:rgba(43,108,176,.12);
    border:1px solid rgba(43,108,176,.3);
    color:#5b9bd8;
    font-size:13.5px;
    font-weight:700;
    padding:12px 16px;
    border-radius:10px;
    cursor:pointer;
    font-family:'DM Sans',sans-serif;
    transition:background .2s;
}
.btn-view-all-evals:hover{background:rgba(43,108,176,.2);}

.eval-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:300;display:none;align-items:center;justify-content:center;padding:20px;}
.eval-modal-overlay.open{display:flex;}
.eval-modal{background:var(--mid);border:1px solid var(--border);border-radius:18px;width:100%;max-width:640px;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.6);}
.eval-modal-header{padding:20px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.eval-modal-title{font-family:'Rajdhani',sans-serif;font-size:19px;font-weight:700;color:#fff;}
.eval-modal-close{background:none;border:none;color:var(--muted);font-size:18px;cursor:pointer;}
.eval-modal-body{padding:22px 24px;overflow-y:auto;}
.eval-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px;}
.eval-info-item{background:var(--inner);border:1px solid var(--border);border-radius:10px;padding:12px 14px;}
.eval-info-label{font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);font-weight:700;margin-bottom:5px;}
.eval-info-value{font-size:13.5px;color:#fff;font-weight:600;}
.eval-modal-loading{text-align:center;padding:50px 20px;color:var(--muted);}
.eval-modal-loading i{font-size:26px;margin-bottom:10px;display:block;}
.eval-q-star{color:#374151;font-size:14px;}
.eval-q-star.filled{color:#facc15;}
.eval-comment-box{background:var(--inner);border:1px solid var(--border);border-radius:10px;padding:15px 17px;font-size:13.5px;color:var(--light);line-height:1.6;font-style:italic;}
.eval-comment-box.empty{color:var(--muted);font-style:normal;}
.score-pill{padding:4px 13px;border-radius:20px;font-size:13px;font-weight:700;}
.empty-state{text-align:center;padding:44px 20px;color:var(--muted);}
.empty-state i{font-size:36px;opacity:.25;display:block;margin-bottom:12px;}
.empty-state p{font-size:13px;}
.peer-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:16px;}
.peer-card{background:var(--mid);border:2px solid var(--border);border-radius:var(--radius);padding:18px 12px;display:flex;flex-direction:column;align-items:center;gap:8px;text-align:center;transition:all .2s;}
.peer-card:hover:not(.done){border-color:var(--teal);transform:translateY(-3px);box-shadow:0 6px 20px rgba(0,0,0,.3);}
.peer-card.done{opacity:.6;cursor:not-allowed;border-color:rgba(34,197,94,.3);}
.peer-photo{width:68px;height:68px;border-radius:50%;object-fit:cover;border:2px solid var(--border);}
.peer-photo-ph{width:68px;height:68px;border-radius:50%;background:var(--inner);border:2px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:22px;}
.peer-name{font-size:13px;font-weight:600;color:#fff;line-height:1.3;}
.peer-desig{font-size:11px;color:var(--muted);}
.done-badge{background:rgba(34,197,94,.14);color:#4ade80;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:700;}
.btn-eval-peer{width:100%;padding:7px 0;border:none;border-radius:7px;background:var(--teal);color:#fff;font-size:12px;font-weight:700;cursor:pointer;transition:background .2s;font-family:'DM Sans',sans-serif;}
.btn-eval-peer:hover{background:var(--teal-hover);}
.btn-eval-peer:disabled{opacity:.45;cursor:not-allowed;}
.back-link{display:inline-flex;align-items:center;gap:7px;background:var(--inner);border:1px solid var(--border);color:var(--light);padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;margin-bottom:18px;transition:background .2s;}
.back-link:hover{background:var(--accent);}
.eval-header-bar{background:var(--mid);border:1px solid var(--border);border-radius:var(--radius);padding:20px 24px;margin-bottom:20px;display:flex;align-items:center;gap:16px;}
.eval-photo-lg{width:58px;height:58px;border-radius:50%;object-fit:cover;border:2px solid var(--teal);}
.eval-photo-ph{width:58px;height:58px;border-radius:50%;background:var(--inner);border:2px solid var(--teal);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:20px;}
.eval-name{font-family:'Rajdhani',sans-serif;font-size:18px;font-weight:700;color:#fff;}
.eval-desig{font-size:12px;color:var(--muted);margin-top:2px;}
.scale-legend-bar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:22px;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:10px;padding:12px 16px;}
.legend-pill{display:flex;align-items:center;gap:7px;padding:5px 13px;border-radius:8px;background:rgba(13,148,136,.12);border:1px solid rgba(13,148,136,.22);font-size:13px;font-weight:700;color:var(--teal-hover);}
.legend-pill .l-num{font-size:15px;font-weight:800;}
.legend-pill .l-lbl{font-size:11px;font-weight:600;color:var(--muted);}
.q-category-header{display:flex;align-items:center;gap:8px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1.5px;color:var(--teal-hover);margin:22px 0 10px;padding-bottom:8px;border-bottom:1px solid rgba(13,148,136,.18);}
.q-card-new{background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:18px 20px;margin-bottom:10px;}
.q-no-new{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:5px;font-weight:700;}
.q-text-new{font-size:14px;font-weight:600;color:#fff;margin-bottom:14px;line-height:1.5;}
.rating-row-new{display:grid;grid-template-columns:repeat(5,1fr);gap:7px;}
.r-btn-new{padding:11px 5px;background:var(--inner);border:1px solid var(--border);border-radius:8px;color:var(--muted);font-size:14px;font-weight:800;cursor:pointer;transition:all .18s;text-align:center;font-family:'DM Sans',sans-serif;}
.r-btn-new .r-lbl-new{display:block;font-size:10px;font-weight:600;margin-top:3px;letter-spacing:.4px;}
.r-btn-new:hover{border-color:var(--teal);color:var(--teal-hover);background:rgba(13,148,136,.1);}
.r-btn-new.sel{background:var(--teal);border-color:var(--teal);color:#fff;}
.r-btn-new.sel .r-lbl-new{color:rgba(255,255,255,.8);}
.comment-box-new{background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:18px 20px;margin-bottom:10px;display:flex;gap:14px;align-items:flex-start;}
.comment-box-icon{color:var(--teal-hover);font-size:17px;margin-top:2px;flex-shrink:0;}
.comment-box-inner{flex:1;}
.comment-box-label{font-size:13px;font-weight:700;color:var(--teal-hover);margin-bottom:9px;}
.comment-textarea-new{width:100%;background:var(--inner);border:1px solid var(--border);border-radius:8px;color:var(--light);padding:11px 13px;font-size:13px;font-family:'DM Sans',sans-serif;resize:vertical;outline:none;transition:border-color .2s;line-height:1.5;min-height:88px;}
.comment-textarea-new:focus{border-color:var(--teal);}
.comment-textarea-new::placeholder{color:rgba(160,179,198,.38);}
.submit-row-new{background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:15px 20px;display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;}
.btn-cancel-new{padding:10px 24px;background:var(--inner);border:1px solid var(--border);border-radius:var(--radius);color:var(--light);font-size:14px;font-weight:600;cursor:pointer;transition:background .2s;font-family:'DM Sans',sans-serif;}
.btn-cancel-new:hover{background:rgba(255,255,255,.06);}
.btn-submit-new{background:var(--teal);color:#fff;border:none;padding:11px 28px;border-radius:var(--radius);font-size:14px;font-weight:700;cursor:pointer;transition:all .2s;display:flex;align-items:center;gap:8px;font-family:'DM Sans',sans-serif;}
.btn-submit-new:hover{background:var(--teal-hover);transform:translateY(-1px);}
.profile-card{background:var(--mid);border:1px solid var(--border);border-radius:var(--radius);padding:26px;margin-bottom:20px;}
.profile-header{display:flex;align-items:center;gap:18px;margin-bottom:22px;padding-bottom:20px;border-bottom:1px solid var(--border);}
.profile-avatar-wrap{width:62px;height:62px;border-radius:50%;overflow:hidden;border:2.5px solid var(--teal);flex-shrink:0;background:var(--inner);display:flex;align-items:center;justify-content:center;color:var(--teal-hover);font-size:24px;}
.profile-avatar-wrap img{width:100%;height:100%;object-fit:cover;}
.profile-name{font-family:'Rajdhani',sans-serif;font-size:21px;font-weight:700;color:#fff;}
.profile-desig-badge{display:inline-flex;align-items:center;gap:5px;background:rgba(13,148,136,.14);border:1px solid rgba(13,148,136,.28);color:var(--teal-hover);font-size:12px;font-weight:700;padding:3px 12px;border-radius:20px;margin-top:6px;}
.fg-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:7px;display:block;}
.suggestion-chips{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:16px;}
.suggestion-chip{background:rgba(255,255,255,.04);border:1px solid var(--border);color:var(--muted);font-size:12px;font-weight:600;padding:5px 13px;border-radius:20px;cursor:pointer;transition:all .2s;}
.suggestion-chip:hover{background:rgba(13,148,136,.13);border-color:rgba(13,148,136,.35);color:var(--teal-hover);}
.suggestion-chip.is-current{background:rgba(13,148,136,.18);border-color:var(--teal);color:var(--teal-hover);cursor:default;}
.desig-input-row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
.desig-input{flex:1;min-width:200px;background:var(--inner);border:1px solid var(--border);color:var(--light);padding:11px 15px;border-radius:10px;font-size:14px;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .2s;}
.desig-input:focus{border-color:var(--teal);}
.btn-update-desig{background:var(--teal);color:#fff;border:none;padding:11px 22px;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:8px;transition:background .2s;font-family:'DM Sans',sans-serif;white-space:nowrap;}
.btn-update-desig:hover{background:var(--teal-hover);}
.info-note{background:rgba(43,108,176,.07);border:1px solid rgba(43,108,176,.18);border-radius:10px;padding:13px 17px;font-size:13px;color:var(--muted);display:flex;gap:10px;align-items:flex-start;margin-top:16px;}
.info-note i{color:#60a5fa;flex-shrink:0;margin-top:1px;}
.level-view-wrap{flex-shrink:0;background:var(--inner);border:1px solid var(--border);border-radius:12px;padding:14px 18px;min-width:220px;max-width:320px;transition:box-shadow .3s ease,border-color .3s ease;}
.level-view-wrap.flash{border-color:var(--teal);box-shadow:0 0 0 3px rgba(13,148,136,.25);}
.level-view-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);display:flex;align-items:center;gap:7px;margin-bottom:9px;}
.level-view-pills{display:flex;flex-wrap:wrap;gap:6px;}
.level-view-pill{background:rgba(13,148,136,.15);color:#5eead4;border:1px solid rgba(13,148,136,.35);font-size:12px;font-weight:700;padding:4px 12px;border-radius:20px;}
.level-view-empty{font-size:11.5px;color:#fcd34d;display:flex;align-items:center;gap:6px;line-height:1.5;}
.level-view-hint{font-size:11px;color:var(--muted);margin-top:9px;}
.toggle-switch{position:relative;display:inline-block;width:42px;height:24px;flex-shrink:0;cursor:pointer;}
.toggle-switch input{display:none;}
.toggle-slider{position:absolute;inset:0;background:var(--border);border-radius:20px;transition:background .2s;}
.toggle-slider::before{content:"";position:absolute;width:18px;height:18px;left:3px;top:3px;background:#fff;border-radius:50%;transition:transform .2s;}
.toggle-switch input:checked + .toggle-slider{background:var(--teal);}
.toggle-switch input:checked + .toggle-slider::before{transform:translateX(18px);}
.photo-upload-area{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-top:20px;padding-top:18px;border-top:1px solid var(--border);}
.photo-preview-circle{width:62px;height:62px;border-radius:50%;overflow:hidden;border:2px solid var(--teal);flex-shrink:0;background:var(--inner);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:22px;}
.photo-preview-circle img{width:100%;height:100%;object-fit:cover;}
.photo-upload-btn{display:inline-flex;align-items:center;gap:7px;background:rgba(13,148,136,.13);border:1px solid rgba(13,148,136,.35);color:var(--teal-hover);padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;}
.photo-upload-hint{font-size:11px;color:var(--muted);margin-top:5px;}
.doc-item{background:var(--inner);border:1px solid var(--border);border-radius:10px;padding:14px 18px;margin-bottom:8px;display:flex;align-items:center;gap:14px;transition:background .15s;}
.doc-item:hover{background:rgba(43,108,176,.07);}
.doc-icon{width:40px;height:40px;border-radius:8px;background:rgba(43,108,176,.18);display:flex;align-items:center;justify-content:center;color:var(--accent);font-size:17px;flex-shrink:0;}
.doc-name{font-size:14px;font-weight:600;color:#fff;}
.doc-meta{font-size:12px;color:var(--muted);margin-top:2px;}
.doc-dl{margin-left:auto;background:var(--accent);color:#fff;border:none;padding:6px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;transition:background .2s;white-space:nowrap;}
.doc-dl:hover{background:var(--hover);}
.no-period-warn{background:rgba(251,191,36,.07);border:1px solid rgba(251,191,36,.18);border-radius:10px;padding:16px 20px;margin-bottom:18px;display:flex;gap:10px;align-items:center;font-size:13px;color:#fcd34d;}

/* ── PEER EVAL — STEP 1: DESIGNATION SELECT ── */
.desig-select-chips{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:4px;}
.desig-select-chip{display:flex;align-items:center;gap:8px;padding:14px 22px;border-radius:10px;border:2px solid var(--border);background:var(--inner);color:var(--light);font-size:14px;font-weight:700;text-decoration:none;transition:all .2s;}
.desig-select-chip:hover{border-color:var(--teal);color:var(--teal-hover);background:rgba(13,148,136,.1);}
.desig-select-chip .dsc-count{color:var(--muted);font-weight:600;margin-left:2px;}
.peer-select-hint{font-size:12px;color:var(--muted);display:flex;align-items:center;gap:6px;margin-top:14px;}
.peer-select-hint.warn{color:#fcd34d;}

/* ── NOTIFICATION BELL ── */
.notif-wrap{position:relative;display:flex;align-items:center;}
.notif-btn{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.06);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:15px;cursor:pointer;transition:all .2s;position:relative;}
.notif-btn:hover,.notif-btn.has-unread{color:#facc15;border-color:rgba(250,204,21,.4);background:rgba(250,204,21,.08);}
.notif-badge{position:absolute;top:-4px;right:-4px;min-width:18px;height:18px;border-radius:9px;background:#ef4444;color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;padding:0 4px;border:2px solid var(--mid);}
.notif-dropdown{position:absolute;top:calc(100% + 10px);right:0;width:320px;background:var(--mid);border:1px solid var(--border);border-radius:14px;box-shadow:0 16px 48px rgba(0,0,0,.55);opacity:0;visibility:hidden;transform:translateY(-8px);transition:all .2s ease;z-index:120;overflow:hidden;}
.notif-dropdown.show{opacity:1;visibility:visible;transform:translateY(0);}
.notif-header{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid var(--border);}
.notif-header-title{font-size:13px;font-weight:700;color:#fff;display:flex;align-items:center;gap:7px;}
.notif-mark-read{font-size:11px;color:var(--teal-hover);cursor:pointer;font-weight:600;background:none;border:none;font-family:'DM Sans',sans-serif;padding:0;}
.notif-list{max-height:340px;overflow-y:auto;}
.notif-item{display:flex;align-items:flex-start;gap:10px;padding:11px 14px;border-bottom:1px solid rgba(255,255,255,.05);position:relative;}
.notif-item:last-child{border-bottom:none;}
.notif-item.unread{background:rgba(13,148,136,.07);}
.notif-item.unread::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--teal);border-radius:0 2px 2px 0;}
.notif-icon{width:30px;height:30px;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:12px;margin-top:1px;}
.notif-text{font-size:12px;color:var(--light);line-height:1.45;margin-bottom:3px;word-break:break-word;}
.notif-meta{font-size:11px;color:var(--muted);}
.notif-empty{text-align:center;padding:32px 16px;color:var(--muted);font-size:13px;}
.notif-empty i{font-size:28px;display:block;margin-bottom:8px;opacity:.2;}

/* ── SIDEBAR PROFILE DROPDOWN ── */
.profile-dd-btn{width:100%;padding:9px 10px;border-radius:10px;border:none;background:none;color:var(--light);font-size:13.5px;font-weight:600;font-family:'DM Sans',sans-serif;cursor:pointer;display:flex;align-items:center;gap:12px;transition:background .18s;text-align:left;text-decoration:none;}
.profile-dd-btn:hover{background:rgba(255,255,255,.06);}
.profile-dd-icon{width:34px;height:34px;border-radius:9px;background:rgba(255,255,255,.06);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:14px;flex-shrink:0;transition:all .18s;}
.profile-dd-btn:hover .profile-dd-icon{background:rgba(13,148,136,.16);color:var(--teal-hover);}
.profile-dd-divider{height:1px;background:var(--border);margin:6px 4px;}
.profile-dd-btn.logout{color:#f87171;}
.profile-dd-btn.logout .profile-dd-icon{background:rgba(240,84,84,.1);color:#f87171;}
.profile-dd-btn.logout:hover{background:rgba(240,84,84,.08);}
.profile-dd-btn.logout:hover .profile-dd-icon{background:rgba(240,84,84,.18);color:#f87171;}
.dd-appearance-val{margin-left:auto;font-size:11px;color:var(--muted);font-weight:700;background:rgba(255,255,255,.06);padding:3px 9px;border-radius:20px;flex-shrink:0;}
.profile-dd-btn:hover .dd-appearance-val{color:var(--teal-hover);}

/* ── PROFILE PHOTO MODAL ── */
.photo-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:300;display:none;align-items:center;justify-content:center;padding:20px;}
.photo-modal-overlay.open{display:flex;}
.photo-modal{background:var(--mid);border:1px solid var(--border);border-radius:18px;width:100%;max-width:400px;box-shadow:0 20px 60px rgba(0,0,0,.6);overflow:hidden;}
.photo-modal-header{padding:20px 24px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.photo-modal-title{font-family:'Rajdhani',sans-serif;font-size:20px;font-weight:700;color:#fff;}
.photo-modal-body{padding:24px;}
.photo-upload-circle{width:110px;height:110px;border-radius:50%;border:3px dashed rgba(13,148,136,.5);display:flex;align-items:center;justify-content:center;margin:0 auto 20px;cursor:pointer;transition:border-color .2s;overflow:hidden;position:relative;background:var(--inner);}
.photo-upload-circle:hover{border-color:var(--teal);}
.photo-upload-circle img{width:100%;height:100%;object-fit:cover;display:none;border-radius:50%;}
.photo-upload-circle .upload-icon-modal{color:var(--muted);font-size:32px;transition:color .2s;}
.photo-upload-circle:hover .upload-icon-modal{color:var(--teal);}
.photo-upload-hint-modal{text-align:center;font-size:12px;color:var(--muted);margin-bottom:20px;}
.photo-upload-hint-modal span{color:var(--teal-hover);font-weight:600;cursor:pointer;}
.photo-modal-footer{padding:0 24px 24px;display:flex;gap:10px;}
.btn-save-photo{flex:1;padding:11px;background:var(--teal);border:none;border-radius:var(--radius);color:#fff;font-size:14px;font-weight:700;cursor:pointer;font-family:'DM Sans',sans-serif;}
.btn-save-photo:hover{background:var(--teal-hover);}
.btn-skip-photo{flex:1;padding:11px;background:var(--inner);border:1px solid var(--border);border-radius:var(--radius);color:var(--muted);font-size:14px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;}

@media(max-width:1024px){.stats-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:900px){.sidebar{transform:translateX(-100%);}.sidebar.open{transform:translateX(0);}.top-nav{left:0;}.main{margin-left:0;}.hamburger{display:block;}}
@media(max-width:600px){.main{padding:16px;}.stats-grid{grid-template-columns:1fr 1fr;}.peer-grid{grid-template-columns:repeat(auto-fill,minmax(130px,1fr));}.desig-input-row{flex-direction:column;}.welcome-bar{padding:18px 20px;}.welcome-text h2{font-size:19px;}.desig-select-chips{flex-direction:column;}}
</style>
</head>
<body>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand" id="sidebarProfile" onclick="toggleSidebarDD(event)">
        <div class="brand-avatar">
            <?php if ($faculty_photo): ?>
            <img src="<?= UPLOAD_URL . htmlspecialchars($faculty_photo) ?>" alt=""/>
            <?php else: ?>
            <span class="brand-initials"><?= htmlspecialchars($initials) ?></span>
            <?php endif; ?>
        </div>
        <div style="overflow:hidden;flex:1;">
            <div class="sidebar-title"><?= htmlspecialchars($full_name) ?></div>
            <div class="sidebar-sub"><?= htmlspecialchars($designation) ?></div>
        </div>
        <i class="fa-solid fa-chevron-down sidebar-caret" id="sidebarCaret"></i>
    </div>
    <div class="sidebar-profile-dropdown" id="sidebarProfileDropdown">
        <button class="profile-dd-btn" onclick="openPhotoModal()">
            <span class="profile-dd-icon"><i class="fa-solid fa-camera"></i></span>
            Update Profile Photo
        </button>
        <a href="faculty_dashboard.php?page=profile" class="profile-dd-btn">
            <span class="profile-dd-icon"><i class="fa-solid fa-gear"></i></span>
            Settings
        </a>
        <a href="change_password.php" class="profile-dd-btn">
            <span class="profile-dd-icon"><i class="fa-solid fa-lock"></i></span>
            Change Password
        </a>
        <a href="faculty_dashboard.php?page=profile" class="profile-dd-btn" onclick="localStorage.setItem('pbi_open_level_dd','1')">
            <span class="profile-dd-icon"><i class="fa-solid fa-sliders"></i></span>
            My Teaching Level(s)
        </a>
        <button type="button" class="profile-dd-btn" id="appearanceBtn" onclick="toggleAppearance(event)">
            <span class="profile-dd-icon"><i class="fa-solid fa-palette"></i></span>
            Appearance
            <span class="dd-appearance-val" id="appearanceVal">Dark</span>
        </button>
        <div class="profile-dd-divider"></div>
        <a href="../logout.php" class="profile-dd-btn logout"
           onclick="return confirm('Log out of your faculty session?')">
            <span class="profile-dd-icon"><i class="fa-solid fa-power-off"></i></span>
            Sign Out
        </a>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="faculty_dashboard.php?page=dashboard" class="nav-link <?= $page==='dashboard'?'active':'' ?>">
            <i class="fa-solid fa-house"></i> Dashboard
        </a>
        <a href="faculty_dashboard.php?page=profile" class="nav-link <?= $page==='profile'?'active':'' ?>">
            <i class="fa-solid fa-id-badge"></i> My Profile &amp; Role
        </a>

        <div class="nav-section-label">Evaluation</div>
        <a href="faculty_dashboard.php?page=my_results" class="nav-link <?= $page==='my_results'?'active':'' ?>">
            <i class="fa-solid fa-chart-bar"></i> My Results
        </a>
        <a href="faculty_dashboard.php?page=peer" class="nav-link <?= in_array($page,['peer','peer_eval'])?'active':'' ?>">
            <i class="fa-solid fa-users-viewfinder"></i> Peer Evaluation
            <?php if ($page==='peer' && !empty($peers_all)): ?>
            <span class="nav-badge"><?= count($peers_all) - count($done_peers) ?></span>
            <?php endif; ?>
        </a>

        <div class="nav-section-label">Resources</div>
        <a href="faculty_dashboard.php?page=documents" class="nav-link <?= $page==='documents'?'active':'' ?>">
            <i class="fa-solid fa-folder-open"></i> Documents
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php" class="btn-logout-side"
           onclick="return confirm('Log out of your faculty session?')">
            <i class="fa-solid fa-power-off"></i> Log Out
        </a>
    </div>
</aside>
    
<!-- PROFILE PHOTO MODAL -->
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
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"/>
                <div class="photo-upload-circle" id="photoCircle" onclick="document.getElementById('photoFileInput').click()">
                    <img id="photoPreviewImg" src="<?= $faculty_photo ? UPLOAD_URL.htmlspecialchars($faculty_photo) : '' ?>"
                         style="<?= $faculty_photo ? 'display:block' : '' ?>"/>
                    <i class="fa-solid fa-camera upload-icon-modal" id="uploadIconEl" style="<?= $faculty_photo ? 'display:none' : '' ?>"></i>
                </div>
                <div class="photo-upload-hint-modal">
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

<!-- EVALUATION DETAILS MODAL -->
<div class="eval-modal-overlay" id="evalDetailsModal">
    <div class="eval-modal">
        <div class="eval-modal-header">
            <div class="eval-modal-title"><i class="fa-solid fa-star" style="color:var(--teal-hover);margin-right:8px;"></i>Evaluation Details</div>
            <button class="eval-modal-close" onclick="closeEvalDetails()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="eval-modal-body" id="evalDetailsBody">
            <div class="eval-modal-loading"><i class="fa-solid fa-spinner fa-spin"></i>Loading evaluation…</div>
        </div>
    </div>
</div>

<nav class="top-nav">
    <div style="display:flex;align-items:center;gap:13px;">
        <button class="hamburger" onclick="document.getElementById('sidebar').classList.toggle('open')">
            <i class="fa-solid fa-bars"></i>
        </button>
        <div class="nav-page-title">
            <?php $titles=['dashboard'=>'Dashboard','profile'=>'My Profile & Role','my_results'=>'My Results','peer'=>'Peer Evaluation','peer_eval'=>'Evaluate Peer','documents'=>'Documents'];
            echo $titles[$page] ?? 'Dashboard'; ?>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:12px;">
        <?php if ($period): ?>
        <div class="period-badge">
            <i class="fa-solid fa-calendar-check"></i>
            <?= htmlspecialchars($period['period_label'] ?? ($period['semester'] ?? '')) ?>
        </div>
        <?php endif; ?>

        <div class="notif-wrap" id="notifWrap">
            <button class="notif-btn <?= $unread_count>0?'has-unread':'' ?>" id="notifBtn" onclick="toggleNotifDropdown(event)" title="Notifications">
                <i class="fa-regular fa-bell"></i>
                <?php if ($unread_count > 0): ?>
                <span class="notif-badge show" id="notifBadge"><?= $unread_count > 99 ? '99+' : $unread_count ?></span>
                <?php endif; ?>
            </button>
            <div class="notif-dropdown" id="notifDropdown">
                <div class="notif-header">
                    <span class="notif-header-title"><i class="fa-solid fa-bell" style="color:var(--teal-hover);"></i> Notifications</span>
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"/>
                        <input type="hidden" name="mark_notifications_read" value="1"/>
                        <button type="submit" class="notif-mark-read">Mark all read</button>
                    </form>
                </div>
                <div class="notif-list">
                    <?php if (empty($my_notifications)): ?>
                    <div class="notif-empty"><i class="fa-regular fa-bell-slash"></i>No notifications yet.</div>
                    <?php else: foreach ($my_notifications as $n):
                        $is_eval = $n['type'] === 'evaluation_received';
                        $n_icon  = $is_eval ? 'fa-star' : 'fa-id-badge';
                        $n_color = $is_eval ? '#facc15' : '#2B6CB0';
                    ?>
                    <div class="notif-item <?= empty($n['is_read'])?'unread':'' ?>">
                        <div class="notif-icon" style="color:<?= $n_color ?>;background:<?= $n_color ?>22;"><i class="fa-solid <?= $n_icon ?>"></i></div>
                        <div style="flex:1;min-width:0;">
                            <div class="notif-text"><?= htmlspecialchars($n['message']) ?></div>
                            <div class="notif-meta"><?= date('M d, Y g:i A', strtotime($n['created_at'])) ?></div>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    </div>
</nav>

<main class="main">

<?php if ($toast): ?>
<div class="toast toast-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($toast) ?></div>
<?php endif; ?>
<?php if ($toast_error): ?>
<div class="toast toast-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($toast_error) ?></div>
<?php endif; ?>

<?php if ($page === 'dashboard'): ?>

<div class="welcome-bar">
    <div class="welcome-text">
        <h2>Welcome, <?= htmlspecialchars($first_name) ?>!</h2>
        <p>
            <strong><?= htmlspecialchars($designation) ?></strong>
            &nbsp;·&nbsp;
            <?= $period ? htmlspecialchars($period['period_label'] ?? $period['semester']) : 'No active evaluation period' ?>
        </p>
    </div>
    <div class="score-chip">
        <div class="sc-val"><?= $my_avg !== null ? number_format($my_avg, 2) : '—' ?></div>
        <div class="sc-lbl">Your Avg Score</div>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-card-lbl">Evaluations Received</div>
        <div class="stat-card-val teal"><?= $my_total ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-lbl">Overall Average</div>
        <div class="stat-card-val gold"><?= $my_avg !== null ? number_format($my_avg, 2) . ' / 5' : '—' ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-lbl">Performance Level</div>
        <div class="stat-card-val sm" style="color:<?= $perf_color ?>"><?= $perf_label ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-lbl">Current Period</div>
        <div class="stat-card-val sm" style="color:var(--teal-hover)">
            <?= $period ? htmlspecialchars($period['semester'] ?? '—') : 'None' ?>
        </div>
    </div>
</div>

<?php if (!empty($my_scores)): ?>
<div class="section-card">
    <div class="section-title"><i class="fa-solid fa-layer-group" style="color:var(--teal)"></i> Performance by Category</div>
    <?php foreach ($my_scores as $cs):
        $pct = round(($cs['avg_cat']/5)*100);
        $col = $cs['avg_cat']>=4?'#4ade80':($cs['avg_cat']>=3?'#facc15':'#f87171');
    ?>
    <div class="cat-row">
        <div class="cat-name"><?= htmlspecialchars($cs['category']) ?></div>
        <div class="cat-bar-bg"><div class="cat-bar-fill" style="width:<?= $pct ?>%;background:<?= $col ?>"></div></div>
        <div class="cat-score" style="color:<?= $col ?>"><?= number_format($cs['avg_cat'],2) ?></div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="section-card">
    <div class="section-title">
        <i class="fa-solid fa-clock-rotate-left" style="color:var(--accent)"></i> Evaluations Received
    </div>

    <button type="button" class="btn-view-all-evals" id="viewEvalsBtn" onclick="toggleRecentEvals()">
        <i class="fa-solid fa-eye"></i> View Evaluations Received
        <i class="fa-solid fa-chevron-down" id="recentEvalsCaret" style="transition:transform .2s;margin-left:auto;"></i>
    </button>

    <div id="recentEvalsList" style="display:none;margin-top:14px;">
    <?php if (empty($recent_subs)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-inbox"></i>
            <p>No evaluations received yet this period.</p>
        </div>
    <?php else: foreach ($recent_subs as $s):
            $col = $s['overall_score']>=4?'#4ade80':($s['overall_score']>=3?'#facc15':'#f87171');
            $period_lbl = $s['period_label'] ?? $s['semester'] ?? '';
        ?>
        <div class="eval-item eval-item-clickable" onclick="openEvalDetails(<?= (int)$s['tracker_id'] ?>)">
            <div class="eval-item-left">
                <div class="anon"><i class="fa-solid fa-eye-slash" style="color:var(--muted)"></i> Anonymous Evaluator</div>
                <div class="meta">
                    <?= date('M d, Y', strtotime($s['submitted_at'])) ?><?= $period_lbl ? ' · ' . htmlspecialchars($period_lbl) : '' ?>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
                <div class="score-pill" style="background:<?= $col ?>1a;color:<?= $col ?>;border:1px solid <?= $col ?>44">
                    <?= number_format($s['overall_score'],2) ?> / 5
                </div>
                <button type="button" class="btn-view-details" onclick="event.stopPropagation(); openEvalDetails(<?= (int)$s['tracker_id'] ?>)">
                    View Details <i class="fa-solid fa-chevron-right"></i>
                </button>
            </div>
        </div>
    <?php endforeach; endif; ?>
    </div>
</div>

<?php elseif ($page === 'profile'): ?>
    <div class="profile-header" style="justify-content:space-between;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:18px;">
            <div class="profile-avatar-wrap">
                <?php if ($faculty_photo): ?>
                <img src="<?= UPLOAD_URL . htmlspecialchars($faculty_photo) ?>" alt=""/>
                <?php else: ?>
                <i class="fa-solid fa-chalkboard-user"></i>
                <?php endif; ?>
            </div>
            <div>
                <div class="profile-name"><?= htmlspecialchars($full_name) ?></div>
                <div class="profile-desig-badge"><i class="fa-solid fa-id-badge"></i> <?= htmlspecialchars($designation) ?></div>
            </div>
        </div>

        <div class="level-view-wrap" id="levelDdWrap">
            <div class="level-view-label"><i class="fa-solid fa-layer-group"></i> My Teaching Level(s)</div>
            <div class="level-view-pills">
                <?php if (empty($my_levels)): ?>
                <span class="level-view-empty"><i class="fa-solid fa-triangle-exclamation"></i> No level assigned yet — contact the admin.</span>
                <?php else: foreach ($my_levels as $lvl): ?>
                <span class="level-view-pill"><?= htmlspecialchars($lvl) ?></span>
                <?php endforeach; endif; ?>
            </div>
            <div class="level-view-hint">Set by the admin — reach out to them to change this.</div>
        </div>
    </div>

    <label class="fg-label"><i class="fa-solid fa-tags" style="margin-right:5px"></i>Quick Pick</label>
    <div class="suggestion-chips">
        <?php foreach ($desig_suggestions as $d): ?>
        <span class="suggestion-chip <?= $d===$designation?'is-current':'' ?>"
              onclick="document.getElementById('desigInput').value='<?= htmlspecialchars(addslashes($d)) ?>'">
            <?= htmlspecialchars($d) ?>
        </span>
        <?php endforeach; ?>
    </div>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"/>
        <input type="hidden" name="update_designation" value="1"/>
        <label class="fg-label" style="margin-top:10px;">Update My Designation</label>
        <div class="desig-input-row">
            <input type="text" name="new_designation" id="desigInput" class="desig-input"
                   placeholder="Type or pick above…" value="<?= htmlspecialchars($designation) ?>" required/>
            <button type="submit" class="btn-update-desig">
                <i class="fa-solid fa-floppy-disk"></i> Save &amp; Notify Admin
            </button>
        </div>
    </form>

    <div class="info-note">
        <i class="fa-solid fa-circle-info"></i>
        <span>Updating your designation takes effect <strong style="color:var(--light)">immediately</strong> and changes which evaluation questions apply to you. The admin is notified automatically. To change your profile photo, use <strong style="color:var(--light)">Update Profile Photo</strong> from the sidebar menu.</span>
    </div>
</div>

<?php elseif ($page === 'my_results'): ?>

<div class="section-card">
    <div class="section-title">
        <i class="fa-solid fa-list" style="color:var(--accent)"></i> Evaluations Received (Anonymous)
    </div>

    <button type="button" class="btn-view-all-evals" id="viewAllEvalsBtn" onclick="toggleAllEvals()">
        <i class="fa-solid fa-eye"></i> View Evaluations Received
        <i class="fa-solid fa-chevron-down" id="allEvalsCaret" style="transition:transform .2s;margin-left:auto;"></i>
    </button>

    <div id="allEvalsList" style="display:none;margin-top:14px;">
    <?php
    $allStmt = $mysqli->prepare("
        SELECT et.id AS tracker_id,
               (SELECT AVG(qa.answer_score) FROM questionnaire_answers qa WHERE qa.tracker_id = et.id) AS overall_score,
               et.submitted_at, ep.period_label, ep.semester
        FROM evaluation_tracker et
        LEFT JOIN evaluation_periods ep ON ep.id = et.period_id
        WHERE et.target_user_id=?
        ORDER BY et.submitted_at DESC
    ");
    $allStmt->bind_param("i", $user_id);
    $allStmt->execute();
    $all_subs = $allStmt->get_result();
    if (!$all_subs || $all_subs->num_rows === 0):
    ?>
    <div class="empty-state"><i class="fa-solid fa-inbox"></i><p>No evaluations recorded yet.</p></div>
    <?php else: while ($s = $all_subs->fetch_assoc()):
        $col = $s['overall_score']>=4?'#4ade80':($s['overall_score']>=3?'#facc15':'#f87171');
        $period_lbl = $s['period_label'] ?? $s['semester'] ?? '';
    ?>
    <div class="eval-item eval-item-clickable" onclick="openEvalDetails(<?= (int)$s['tracker_id'] ?>)">
        <div class="eval-item-left">
            <div class="anon"><i class="fa-solid fa-eye-slash" style="color:var(--muted)"></i> Anonymous Evaluator</div>
            <div class="meta">
                <?= date('M d, Y g:i A', strtotime($s['submitted_at'])) ?><?= $period_lbl ? ' · ' . htmlspecialchars($period_lbl) : '' ?>
            </div>
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
            <div class="score-pill" style="background:<?= $col ?>1a;color:<?= $col ?>;border:1px solid <?= $col ?>44">
                <?= number_format($s['overall_score'],2) ?> / 5
            </div>
            <button type="button" class="btn-view-details" onclick="event.stopPropagation(); openEvalDetails(<?= (int)$s['tracker_id'] ?>)">
                View Details <i class="fa-solid fa-chevron-right"></i>
            </button>
        </div>
    </div>
    <?php endwhile; $allStmt->close(); endif; ?>
    </div>
</div>

<!-- ══════════ PEER EVALUATION — STEP 1: CHOOSE DESIGNATION ══════════ -->
<?php elseif ($page === 'peer' && $peer_group === null): ?>

<?php if (!$period): ?>
<div class="no-period-warn"><i class="fa-solid fa-clock"></i> No active evaluation period. Peer evaluation is currently closed.</div>
<?php endif; ?>

<div class="section-card">
    <div class="section-title"><i class="fa-solid fa-users" style="color:var(--teal)"></i> Peer Evaluation</div>
    <p style="font-size:13px;color:var(--muted);margin-bottom:20px;">First, choose which designation you'd like to evaluate. You'll then pick a specific person from that list.</p>

    <?php if (empty($peers_all)): ?>
    <div class="empty-state"><i class="fa-solid fa-users"></i><p>No other faculty members registered yet.</p></div>
    <?php else:
        $teacher_count = count(array_filter($peers_all, fn($p) => resolve_peer_group($p['designation'] ?? '', $token_to_target, $system_categories, $p['role'] ?? null) === 'teacher'));
        $staff_count   = count($peers_all) - $teacher_count;
    ?>
    <div class="fg-label" style="margin-bottom:10px;"><i class="fa-solid fa-bolt" style="margin-right:5px"></i>Step 1: Select Designation</div>
    <div class="desig-select-chips">
        <a href="faculty_dashboard.php?page=peer&group=teacher" class="desig-select-chip">
            <i class="fa-solid fa-chalkboard-user" style="color:var(--teal-hover)"></i> Teacher <span class="dsc-count">(<?= $teacher_count ?>)</span>
        </a>
        <a href="faculty_dashboard.php?page=peer&group=staff" class="desig-select-chip">
            <i class="fa-solid fa-briefcase" style="color:var(--teal-hover)"></i> Staff <span class="dsc-count">(<?= $staff_count ?>)</span>
        </a>
    </div>
    <div class="peer-select-hint"><i class="fa-solid fa-circle-info"></i> Please select a designation before proceeding.</div>
    <?php endif; ?>
</div>

<!-- ══════════ PEER EVALUATION — STEP 2: FILTERED PEER LIST ══════════ -->
<?php elseif ($page === 'peer' && $peer_group !== null): ?>

<a href="faculty_dashboard.php?page=peer" class="back-link"><i class="fa-solid fa-arrow-left"></i> Change Designation</a>

<?php if (!$period): ?>
<div class="no-period-warn"><i class="fa-solid fa-clock"></i> No active evaluation period. Peer evaluation is currently closed.</div>
<?php endif; ?>

<div class="section-card">
    <div class="section-title"><i class="fa-solid fa-users" style="color:var(--teal)"></i> Fellow <?= htmlspecialchars($peer_group_labels[$peer_group]) ?> Members</div>
    <p style="font-size:13px;color:var(--muted);margin-bottom:18px;">Select a colleague to evaluate. Your identity will be kept confidential.</p>
    <?php if (empty($peers)): ?>
    <div class="empty-state"><i class="fa-solid fa-users"></i><p>No registered <?= htmlspecialchars(strtolower($peer_group_labels[$peer_group])) ?> members found.</p></div>
    <?php else: ?>
    <div class="peer-grid">
        <?php foreach ($peers as $p): $done = in_array($p['id'], $done_peers); ?>
        <div class="peer-card <?= $done?'done':'' ?>">
            <?php if ($p['photo']): ?>
            <img class="peer-photo" src="<?= UPLOAD_URL . htmlspecialchars($p['photo']) ?>" alt=""/>
            <?php else: ?>
            <div class="peer-photo-ph"><i class="fa-solid fa-user"></i></div>
            <?php endif; ?>
            <div class="peer-name"><?= htmlspecialchars($p['full_name']) ?></div>
            <div class="peer-desig"><?= htmlspecialchars($p['designation'] ?: $peer_group_labels[$peer_group]) ?></div>
            <?php if ($done): ?>
            <div class="done-badge"><i class="fa-solid fa-circle-check"></i> Done</div>
            <?php else: ?>
            <button class="btn-eval-peer" <?= !$period?'disabled':'' ?>
                    onclick="window.location='faculty_dashboard.php?page=peer_eval&tid=<?= $p['id'] ?>&group=<?= urlencode($peer_group) ?>'">
                Evaluate
            </button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php $done_in_group = count(array_intersect(array_column($peers, 'id'), $done_peers)); ?>
    <?php if ($done_in_group > 0): ?>
    <div class="peer-select-hint warn"><i class="fa-solid fa-circle-check"></i> You've already evaluated <?= $done_in_group ?> of <?= count($peers) ?> <?= htmlspecialchars(strtolower($peer_group_labels[$peer_group])) ?> members this period.</div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php elseif ($page === 'peer_eval' && $peer_target): ?>

<a href="faculty_dashboard.php?page=peer&group=<?= urlencode($peer_eval_group) ?>" class="back-link">
    <i class="fa-solid fa-arrow-left"></i> Back to <?= htmlspecialchars($peer_group_labels[$peer_eval_group]) ?> List
</a>

<div class="eval-header-bar">
    <?php if ($peer_target['photo']): ?>
    <img class="eval-photo-lg" src="<?= UPLOAD_URL . htmlspecialchars($peer_target['photo']) ?>" alt=""/>
    <?php else: ?>
    <div class="eval-photo-ph"><i class="fa-solid fa-user"></i></div>
    <?php endif; ?>
    <div>
        <div class="eval-name"><?= htmlspecialchars($peer_target['full_name']) ?></div>
        <div class="eval-desig"><?= htmlspecialchars($peer_target['designation'] ?? 'Teacher') ?></div>
    </div>
</div>

<?php if (empty($peer_questions)): ?>
<div style="background:rgba(251,191,36,.07);border:1px solid rgba(251,191,36,.18);border-radius:10px;padding:18px;color:#fcd34d;font-size:13px;display:flex;gap:10px;">
    <i class="fa-solid fa-triangle-exclamation"></i> No peer evaluation questions set up yet. Please contact the admin.
</div>
<?php else: ?>

<div class="scale-legend-bar">
    <?php foreach ([5=>'Always',4=>'Often',3=>'Sometimes',2=>'Rarely',1=>'Never'] as $num=>$lbl): ?>
    <div class="legend-pill"><span class="l-num"><?= $num ?></span><span class="l-lbl"><?= $lbl ?></span></div>
    <?php endforeach; ?>
</div>

<form method="POST" action="faculty_dashboard.php?page=peer_eval&tid=<?= $peer_target['id'] ?>&group=<?= urlencode($peer_eval_group) ?>" id="evalForm">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"/>
    <input type="hidden" name="submit_peer" value="1"/>
    <input type="hidden" name="target_id"   value="<?= $peer_target['id'] ?>"/>
    <input type="hidden" name="form_id"     value="<?= $peer_form_id ?>"/>
    <input type="hidden" name="period_id"   value="<?= $period['id'] ?? 0 ?>"/>
    <input type="hidden" name="group"       value="<?= htmlspecialchars($peer_eval_group) ?>"/>

    <?php
    $grouped_qs = [];
    foreach ($peer_questions as $q) $grouped_qs[$q['category'] ?? 'General'][] = $q;
    $cat_icons  = ['Work Performance'=>'fa-briefcase','Professional Ethics'=>'fa-scale-balanced','Interpersonal Skills'=>'fa-handshake','Teaching'=>'fa-chalkboard-user','Communication'=>'fa-comments','General'=>'fa-layer-group'];
    $labels     = [5=>'ALWAYS',4=>'OFTEN',3=>'SOMETIMES',2=>'RARELY',1=>'NEVER'];
    $qno        = 1;
    foreach ($grouped_qs as $cat => $qs):
        $icon = $cat_icons[$cat] ?? 'fa-layer-group';
    ?>
    <div class="q-category-header"><i class="fa-solid <?= $icon ?>"></i><?= htmlspecialchars($cat) ?></div>
    <?php foreach ($qs as $q): ?>
    <div class="q-card-new">
        <div class="q-no-new">Question <?= $qno++ ?></div>
        <div class="q-text-new"><?= htmlspecialchars($q['question_text']) ?></div>
        <div class="rating-row-new" id="grp_<?= $q['id'] ?>">
            <?php foreach ([5,4,3,2,1] as $v): ?>
            <button type="button" class="r-btn-new" data-val="<?= $v ?>" onclick="rateNew(<?= $q['id'] ?>,<?= $v ?>)">
                <?= $v ?><span class="r-lbl-new"><?= $labels[$v] ?></span>
            </button>
            <?php endforeach; ?>
        </div>
        <input type="hidden" name="ratings[<?= $q['id'] ?>]" id="r_<?= $q['id'] ?>"/>
    </div>
    <?php endforeach; endforeach; ?>

    <div class="comment-box-new">
        <i class="fa-solid fa-comment-dots comment-box-icon"></i>
        <div class="comment-box-inner">
            <div class="comment-box-label">Comments &amp; Suggestions <span style="font-size:11px;color:var(--muted);font-weight:400">(Optional)</span></div>
            <textarea name="comment" class="comment-textarea-new" placeholder="Share your thoughts or suggestions…"></textarea>
        </div>
    </div>

    <div class="submit-row-new">
        <span style="font-size:13px;color:var(--muted);"><i class="fa-solid fa-circle-info" style="color:#60a5fa;margin-right:5px"></i>Rate 1 (Never) – 5 (Always). All questions required.</span>
        <div style="display:flex;gap:10px;">
            <button type="button" class="btn-cancel-new" onclick="window.location='faculty_dashboard.php?page=peer&group=<?= urlencode($peer_eval_group) ?>'">Cancel</button>
            <button type="submit" class="btn-submit-new" onclick="return checkAll()"><i class="fa-solid fa-paper-plane"></i> Submit Evaluation</button>
        </div>
    </div>
</form>
<?php endif; ?>

<!-- ══════════ PEER EVAL — INVALID / MISSING TARGET ══════════ -->
<?php elseif ($page === 'peer_eval' && !$peer_target): ?>

<a href="faculty_dashboard.php?page=peer" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Peer Selection</a>

<div style="background:rgba(240,84,84,.08);border:1px solid rgba(240,84,84,.25);border-radius:10px;padding:18px;color:#fca5a5;font-size:13px;display:flex;gap:10px;">
    <i class="fa-solid fa-circle-exclamation"></i>
    <?= htmlspecialchars($peer_group_error ?: "Selected user does not exist, is inactive, or is not a valid Teacher/Staff account. Please choose someone from the list.") ?>
</div>

<?php elseif ($page === 'documents'): ?>

<div class="section-card">
    <div class="section-title"><i class="fa-solid fa-folder-open" style="color:var(--accent)"></i> Faculty Documents</div>
    <?php if (empty($documents)): ?>
    <div class="empty-state"><i class="fa-solid fa-folder-open"></i><p>No documents uploaded for Faculty yet.</p></div>
    <?php else: foreach ($documents as $d):
        $ext      = strtolower(pathinfo($d['storage_name'] ?? '', PATHINFO_EXTENSION));
        $icon_map = ['pdf'=>'fa-file-pdf','doc'=>'fa-file-word','docx'=>'fa-file-word','xls'=>'fa-file-excel','xlsx'=>'fa-file-excel','ppt'=>'fa-file-powerpoint','pptx'=>'fa-file-powerpoint'];
        $icon     = $icon_map[$ext] ?? 'fa-file-lines';
    ?>
    <div class="doc-item">
        <div class="doc-icon"><i class="fa-solid <?= $icon ?>"></i></div>
        <div>
            <div class="doc-name"><?= htmlspecialchars($d['display_name'] ?? $d['title'] ?? '') ?></div>
            <div class="doc-meta"><?= htmlspecialchars($d['category']??'') ?> · <?= date('M d, Y', strtotime($d['uploaded_at'])) ?></div>
        </div>
        <a class="doc-dl" href="../admin/stored_docs/<?= htmlspecialchars($d['storage_name'] ?? '') ?>" target="_blank" download>
            <i class="fa-solid fa-download"></i> Download
        </a>
    </div>
    <?php endforeach; endif; ?>
</div>

<?php endif; ?>
</main>

<script>
function rateNew(qid, val) {
    document.querySelectorAll(`#grp_${qid} .r-btn-new`).forEach(b => {
        b.classList.toggle('sel', parseInt(b.dataset.val) === val);
    });
    document.getElementById(`r_${qid}`).value = val;
}

function toggleRecentEvals() {
    const list  = document.getElementById('recentEvalsList');
    const caret = document.getElementById('recentEvalsCaret');
    const isOpen = list.style.display !== 'none';
    list.style.display = isOpen ? 'none' : 'block';
    caret.style.transform = isOpen ? '' : 'rotate(180deg)';
}

function toggleAllEvals() {
    const list  = document.getElementById('allEvalsList');
    const caret = document.getElementById('allEvalsCaret');
    const isOpen = list.style.display !== 'none';
    list.style.display = isOpen ? 'none' : 'block';
    caret.style.transform = isOpen ? '' : 'rotate(180deg)';
}

function checkAll() {
    const inputs = document.querySelectorAll('#evalForm input[type="hidden"][name^="ratings"]');
    for (const i of inputs) {
        if (!i.value) { alert('Please rate all questions before submitting.'); return false; }
    }
    return true;
}
function openEvalDetails(trackerId) {
    const modal = document.getElementById('evalDetailsModal');
    const body  = document.getElementById('evalDetailsBody');
    body.innerHTML = '<div class="eval-modal-loading"><i class="fa-solid fa-spinner fa-spin"></i>Loading evaluation…</div>';
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';

    fetch('get_evaluation_details.php?tracker_id=' + encodeURIComponent(trackerId))
        .then(r => r.json())
        .then(data => {
            body.innerHTML = data.ok
                ? renderEvalDetails(data)
                : '<div class="eval-modal-loading"><i class="fa-solid fa-triangle-exclamation"></i>' + (data.error || 'Unable to load this evaluation.') + '</div>';
        })
        .catch(() => {
            body.innerHTML = '<div class="eval-modal-loading"><i class="fa-solid fa-triangle-exclamation"></i>Something went wrong loading this evaluation.</div>';
        });
}
function closeEvalDetails() {
    document.getElementById('evalDetailsModal').classList.remove('open');
    document.body.style.overflow = '';
}
document.getElementById('evalDetailsModal').addEventListener('click', function(e) {
    if (e.target === this) closeEvalDetails();
});

function starsHtml(score) {
    let h = '';
    for (let i = 1; i <= 5; i++) h += `<i class="fa-solid fa-star eval-q-star ${i <= score ? 'filled' : ''}"></i>`;
    return h;
}

function renderEvalDetails(data) {
    let html = `<div class="eval-info-grid">
        <div class="eval-info-item"><div class="eval-info-label">Evaluator</div><div class="eval-info-value"><i class="fa-solid fa-eye-slash" style="color:var(--muted);margin-right:5px"></i>Anonymous Evaluator</div></div>
        <div class="eval-info-item"><div class="eval-info-label">Period</div><div class="eval-info-value">${escapeHtml(data.period_label || '—')}</div></div>
        <div class="eval-info-item"><div class="eval-info-label">Submitted</div><div class="eval-info-value">${escapeHtml(data.submitted_at)}</div></div>
        <div class="eval-info-item"><div class="eval-info-label">Overall Score</div><div class="eval-info-value" style="color:var(--teal-hover)">${data.overall_score.toFixed(2)} / 5</div></div>
    </div>`;

    if (data.categories && data.categories.length) {
        html += `<div class="section-title" style="font-size:14px;margin-bottom:12px;"><i class="fa-solid fa-layer-group" style="color:var(--teal)"></i> Performance by Category</div>`;
        data.categories.forEach(c => {
            const pct = Math.round((c.avg / 5) * 100);
            const col = c.avg >= 4 ? '#4ade80' : (c.avg >= 3 ? '#facc15' : '#f87171');
            html += `<div class="cat-row">
                <div class="cat-name">${escapeHtml(c.category)}</div>
                <div class="cat-bar-bg"><div class="cat-bar-fill" style="width:${pct}%;background:${col}"></div></div>
                <div class="cat-score" style="color:${col}">${c.avg.toFixed(2)}</div>
            </div>`;
        });
    }

    if (data.questions && data.questions.length) {
        html += `<div class="section-title" style="font-size:14px;margin:20px 0 12px;"><i class="fa-solid fa-list-check" style="color:var(--accent)"></i> Question-by-Question Results</div>`;
        let lastCat = null;
        data.questions.forEach((q, idx) => {
            if (q.category !== lastCat) {
                html += `<div class="q-category-header" style="margin-top:${lastCat===null?'0':'18px'}">${escapeHtml(q.category)}</div>`;
                lastCat = q.category;
            }
            html += `<div class="q-card-new" style="padding:14px 16px;">
                <div class="q-no-new">Question ${idx + 1}</div>
                <div class="q-text-new" style="margin-bottom:8px;">${escapeHtml(q.question_text)}</div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <div>${starsHtml(q.score)}</div>
                    <div style="font-size:12px;color:var(--muted);font-weight:700;">Score: ${q.score} / 5</div>
                </div>
            </div>`;
        });
    }

    html += `<div class="section-title" style="font-size:14px;margin:20px 0 10px;"><i class="fa-solid fa-comment-dots" style="color:var(--teal-hover)"></i> Comments / Feedback</div>`;
    html += data.comment
        ? `<div class="eval-comment-box">"${escapeHtml(data.comment)}"</div>`
        : `<div class="eval-comment-box empty">No written feedback was provided.</div>`;

    return html;
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}
document.addEventListener('click', function(e) {
    const sb = document.getElementById('sidebar');
    if (sb && sb.classList.contains('open') && !sb.contains(e.target) && !e.target.closest('.hamburger')) {
        sb.classList.remove('open');
    }
});

// ── Sidebar profile dropdown ──
function toggleSidebarDD(e) {
    e.stopPropagation();
    const dd     = document.getElementById('sidebarProfileDropdown');
    const caret  = document.getElementById('sidebarCaret');
    dd.classList.toggle('open');
    caret.style.transform = dd.classList.contains('open') ? 'rotate(180deg)' : '';
}
document.addEventListener('click', function(e) {
    const sidebarProfile = document.getElementById('sidebarProfile');
    const dd = document.getElementById('sidebarProfileDropdown');
    if (sidebarProfile && dd && !sidebarProfile.contains(e.target) && !dd.contains(e.target)) {
        dd.classList.remove('open');
        document.getElementById('sidebarCaret').style.transform = '';
    }
});

// If the "My Teaching Level(s)" item was clicked from the sidebar dropdown,
// land on the profile page and scroll to / briefly highlight the read-only
// level card (levels are admin-assigned now, not self-editable).
document.addEventListener('DOMContentLoaded', function() {
    const wrap = document.getElementById('levelDdWrap');
    if (wrap && localStorage.getItem('pbi_open_level_dd') === '1') {
        localStorage.removeItem('pbi_open_level_dd');
        wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
        wrap.classList.add('flash');
        setTimeout(() => wrap.classList.remove('flash'), 1600);
    }
});

// ── Notification bell ──
function toggleNotifDropdown(e) {
    e.stopPropagation();
    document.getElementById('notifDropdown').classList.toggle('show');
}
document.addEventListener('click', function(e) {
    const wrap = document.getElementById('notifWrap');
    if (wrap && !wrap.contains(e.target)) {
        document.getElementById('notifDropdown')?.classList.remove('show');
    }
});

// ── Appearance toggle (Dark / Light) ──
function applyAppearance(mode) {
    document.body.classList.toggle('light-theme', mode === 'light');
    const val = document.getElementById('appearanceVal');
    if (val) val.textContent = mode === 'light' ? 'Light' : 'Dark';
}
function toggleAppearance(e) {
    e.stopPropagation();
    const current = localStorage.getItem('pbi_theme') || 'dark';
    const next = current === 'dark' ? 'light' : 'dark';
    localStorage.setItem('pbi_theme', next);
    applyAppearance(next);
}
document.addEventListener('DOMContentLoaded', function() {
    applyAppearance(localStorage.getItem('pbi_theme') || 'dark');
});

// ── Profile photo modal ──
function openPhotoModal() {
    document.getElementById('sidebarProfileDropdown').classList.remove('open');
    document.getElementById('sidebarCaret').style.transform = '';
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
</script>

<?php $mysqli->close(); ?>
</body>
</html>
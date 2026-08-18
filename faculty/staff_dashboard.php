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
        // ── AUTH GUARD ────────────────────────────────────────────────
        if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
            header("Location: faculty_login.php"); exit;
        }

        $user_id     = $_SESSION['user_id'];
        $full_name   = $_SESSION['full_name']   ?? 'Staff';
        $designation = $_SESSION['designation'] ?? 'Staff';
        $page        = $_GET['page'] ?? 'dashboard';

        // ── CSRF TOKEN ────────────────────────────────────────────────
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        $csrf_token = $_SESSION['csrf_token'];

        function csrf_check(): bool {
            return isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
        }

        // ── FETCH MY PHOTO ────────────────────────────────────────────
        $photo_url   = null;
        $staff_photo = '';
        $pq = $mysqli->prepare("SELECT photo FROM users WHERE id=? LIMIT 1");
        $pq->bind_param("i", $user_id); $pq->execute(); $pq->bind_result($photo_db); $pq->fetch(); $pq->close();
        if (!empty($photo_db)) { $photo_url = '../image/' . $photo_db; $staff_photo = $photo_db; }

        // ── VALID SPECIFIC YEAR LEVELS ───────────────────────────────────
        // Same exact list & string values as admin/manage_privileged_accounts.php
        // uses for students and for faculty/staff assignment — keeping these
        // identical is what lets a staff member's picks match real students.
        $year_levels = [
            'Grade 7','Grade 8','Grade 9','Grade 10',
            'Grade 11','Grade 12',
            '1st Year College','2nd Year College','3rd Year College','4th Year College',
        ];

        // ── FETCH MY ASSIGNED TEACHING LEVEL(S) ─────────────────────────
        // A staff member can be assigned to more than one SPECIFIC year
        // level at once (e.g. Grade 8 AND Grade 10), so this is a list, not
        // a single value.
        $my_levels = [];
        $lvlQ = $mysqli->prepare("SELECT year_level FROM user_year_levels WHERE user_id=?");
        $lvlQ->bind_param("i", $user_id);
        $lvlQ->execute();
        $lvlRes = $lvlQ->get_result();
        while ($lr = $lvlRes->fetch_assoc()) $my_levels[] = $lr['year_level'];
        $lvlQ->close();

        // ── AUTO-CREATE notifications table ──────────────────────────
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

        // ── AUTO-CREATE role_change_log table ────────────────────────
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
        $mysqli->query("CREATE TABLE IF NOT EXISTS user_preferences (
            user_id INT UNSIGNED PRIMARY KEY,
            email_on_designation_update TINYINT(1) NOT NULL DEFAULT 1,
            email_on_new_evaluation     TINYINT(1) NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // ── UPDATE MY DESIGNATION ─────────────────────────────────────
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_designation'])) {
            if (!csrf_check()) {
                $_SESSION['toast_error'] = "Your session expired or the request could not be verified. Please try again.";
                header("Location: staff_dashboard.php?page=profile"); exit;
            }
            $new_desig = trim($_POST['new_designation'] ?? '');
            $old_desig = $designation;

            if (empty($new_desig)) {
                $_SESSION['toast_error'] = "Designation cannot be empty.";
            } elseif ($new_desig === $old_desig) {
                $_SESSION['toast_error'] = "That is already your current designation.";
            } else {
                $stmt = $mysqli->prepare("UPDATE users SET designation=? WHERE id=?");
                $stmt->bind_param("si", $new_desig, $user_id);
                $stmt->execute(); $stmt->close();
                $designation = $new_desig;
                $_SESSION['designation'] = $new_desig;

                // Notify admin
                $message = $full_name . ' updated their designation from "' . $old_desig . '" to "' . $new_desig . '".';
                $extra   = json_encode(['user_id'=>$user_id,'full_name'=>$full_name,'role'=>'staff','old_desig'=>$old_desig,'new_desig'=>$new_desig]);
                $nstmt   = $mysqli->prepare("INSERT INTO notifications (type, user_id, message, extra_data) VALUES ('designation_update', ?, ?, ?)");
                $nstmt->bind_param("iss", $user_id, $message, $extra);
                $nstmt->execute(); $nstmt->close();

                // Also log to role_change_log so it shows up in the admin dashboard's
                // System Audits box and notification bell.
                $rstmt = $mysqli->prepare("INSERT INTO role_change_log (user_id, old_role, new_role, old_designation, new_designation) VALUES (?, 'staff', 'staff', ?, ?)");
                $rstmt->bind_param("iss", $user_id, $old_desig, $new_desig);
                $rstmt->execute(); $rstmt->close();

                $_SESSION['toast'] = "Your designation has been updated to \"$new_desig\" and the admin has been notified.";
            }
            header("Location: staff_dashboard.php?page=profile"); exit;
        }

        // ── UPDATE TEACHING LEVEL(S) ──────────────────────────────────
        // Multi-select — a staff member can be assigned to more than one
        // specific year level at once (e.g. teaches both Grade 8 and Grade 10).
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_levels'])) {
            if (!csrf_check()) {
                $_SESSION['toast_error'] = "Your session expired or the request could not be verified. Please try again.";
                header("Location: staff_dashboard.php?page=profile"); exit;
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
                error_log('[staff_dashboard] update_levels failed for user_id=' . $user_id . ': ' . $e->getMessage());
                $_SESSION['toast_error'] = "Failed to update teaching levels. Please try again.";
            }
            header("Location: staff_dashboard.php?page=profile"); exit;
        }

        // ── CHANGE PASSWORD ─────────────────────────────────────────────
        // ── MARK NOTIFICATIONS READ ──────────────────────────────────
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_notifications_read'])) {
            if (!csrf_check()) {
                header("Location: staff_dashboard.php?page=" . urlencode($page)); exit;
            }
            $mr = $mysqli->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0");
            $mr->bind_param("i", $user_id);
            $mr->execute(); $mr->close();
            header("Location: staff_dashboard.php?page=" . urlencode($page)); exit;
        }

        // ── ACTIVE PERIOD ─────────────────────────────────────────────
        $period = null;
        $pr = $mysqli->query("SELECT * FROM evaluation_periods WHERE is_active=1 LIMIT 1");
        if ($pr) $period = $pr->fetch_assoc();

        // ── MY EVALUATION RESULTS ─────────────────────────────────────
        // Reads from evaluation_tracker + questionnaire_answers (student evaluations of this staff)
        $my_avg    = null;
        $my_total  = 0;
        $my_scores = [];

        $res_stmt = $mysqli->prepare("
            SELECT AVG(qa.answer_score) as avg_score, COUNT(DISTINCT et.id) as total
            FROM evaluation_tracker et
            JOIN questionnaire_answers qa ON qa.tracker_id = et.id
            WHERE et.target_user_id = ?
        ");
        $res_stmt->bind_param("i", $user_id);
        $res_stmt->execute();
        $res = $res_stmt->get_result();
        if ($res) {
            $row      = $res->fetch_assoc();
            $my_avg   = $row['avg_score'] !== null ? round($row['avg_score'], 2) : null;
            $my_total = $row['total'] ?? 0;
        }
        $res_stmt->close();

        $cat_stmt = $mysqli->prepare("
            SELECT eq.category, AVG(qa.answer_score) as avg_cat
            FROM questionnaire_answers qa
            JOIN evaluation_tracker et ON et.id = qa.tracker_id
            JOIN evaluation_questions eq ON eq.id = qa.question_id
            WHERE et.target_user_id = ?
            GROUP BY eq.category
        ");
        $cat_stmt->bind_param("i", $user_id);
        $cat_stmt->execute();
        $cat_res = $cat_stmt->get_result();
        if ($cat_res) $my_scores = $cat_res->fetch_all(MYSQLI_ASSOC);
        $cat_stmt->close();

        // ── DESIGNATION → QUESTION TARGET_TYPE MAPPING ────────────────
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
            // No usable designation text (empty/unset) — fall back to the
            // account's actual role instead of silently defaulting to
            // Personnel/Staff. See the corrected note below: the users table
            // DOES have a literal role='teacher' vs role='staff' split (per
            // admin/manage_privileged_accounts.php and faculty_dashboard.php),
            // so this fallback is meaningful, not a no-op.
            if ($fallback_role === 'teacher') return 'Faculty';
            return 'Personnel';
        }

        // NOTE ON "TEACHER vs STAFF" (CORRECTED): the users table DOES have a
        // literal role='teacher' vs role='staff' split — confirmed by
        // admin/manage_privileged_accounts.php's $valid_roles list and by
        // faculty_dashboard.php's own auth guard ($_SESSION['role'] !== 'teacher').
        // The previous comment here ("single account role, no teacher/staff
        // split") was stale/incorrect and led this file's peer-list query to
        // filter on role='staff' only, which silently excluded every
        // role='teacher' account from Staff's peer evaluation pool. Grouping
        // still primarily goes off the free-text `designation` column (so a
        // staff member whose designation is "Coordinator" still shows under
        // "Teacher", matching the rest of the system), but now falls back to
        // the real account role when the designation text doesn't resolve to
        // anything (see resolve_target_type() above).
        function resolve_peer_group($desig, $map, $cats, $fallback_role = null) {
            return resolve_target_type($desig, $map, $cats, $fallback_role) === 'Faculty' ? 'teacher' : 'staff';
        }
        $peer_group_labels = ['teacher' => 'Teacher', 'staff' => 'Staff'];

        // ── ADD peer_group COLUMN TO evaluation_tracker (idempotent) ──
        // Stores which of the two designation groups (Teacher/Staff) the
        // evaluator picked in Step 1, alongside the existing tracker row.
        $colChk = $mysqli->query("SHOW COLUMNS FROM evaluation_tracker LIKE 'peer_group'");
        if ($colChk && $colChk->num_rows === 0) {
            $mysqli->query("ALTER TABLE evaluation_tracker ADD COLUMN peer_group VARCHAR(20) NULL AFTER eval_type");
        }

        // ── ENSURE A UNIQUE CONSTRAINT BACKS THE DUPLICATE-EVAL CHECK ──
        // The application-level duplicate check below (SELECT ... then INSERT)
        // has a race window between two near-simultaneous submissions. This
        // unique index makes the DB itself the source of truth: a second insert
        // for the same (evaluator, target, eval_type, period) will fail with a
        // duplicate-key error instead of silently creating a second row.
        $idxChk = $mysqli->query("SHOW INDEX FROM evaluation_tracker WHERE Key_name = 'uniq_eval_submission'");
        if ($idxChk && $idxChk->num_rows === 0) {
            $mysqli->query("ALTER TABLE evaluation_tracker ADD UNIQUE INDEX uniq_eval_submission (evaluator_id, target_user_id, eval_type, period_id)");
        }

        // ── PEER EVALUATION ───────────────────────────────────────────
        $peers_all  = [];   // all eligible peers, unfiltered — used for the sidebar badge count
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

            // Done if already has an eval_type='staff_peer' tracker entry for this person
            $dpStmt = $mysqli->prepare("SELECT target_user_id FROM evaluation_tracker WHERE evaluator_id=? AND eval_type='staff_peer'");
            $dpStmt->bind_param("i", $user_id);
            $dpStmt->execute();
            $dp = $dpStmt->get_result();
            if ($dp) while ($r = $dp->fetch_assoc()) $done_peers[] = $r['target_user_id'];
            $dpStmt->close();

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
        $peer_categories   = [];
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
                // Questionnaire Management assigns questions differently per bucket:
                // Staff targets get questions assigned per individual user (the
                // "Assign Questions" feature -> user_questions, keyed by user_id),
                // while Teacher targets use the shared evaluation_questions pool
                // keyed off the EA's 'Teacher' bucket (not the granular designation
                // category resolve_target_type() returns - the admin tool never
                // stores questions under those values).
                if ($peer_eval_group === 'staff') {
                    $qs = $mysqli->prepare("SELECT * FROM user_questions WHERE user_id=? AND eval_type='peer' ORDER BY category ASC, sort_order ASC, id ASC");
                    $qs->bind_param("i", $tid); $qs->execute();
                } else {
                    $qs = $mysqli->prepare("SELECT * FROM evaluation_questions WHERE target_type='Teacher' AND eval_type='peer' ORDER BY category ASC, id ASC");
                    $qs->execute();
                }
                $peer_questions = $qs->get_result()->fetch_all(MYSQLI_ASSOC); $qs->close();
                foreach ($peer_questions as $q) {
                    $cat = $q['category'] ?: 'General';
                    $peer_categories[$cat][] = $q;
                }
            }
        }

        // ── SUBMIT PEER EVAL ──────────────────────────────────────────
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_peer'])) {
            if (!csrf_check()) {
                $_SESSION['toast_error'] = "Your session expired or the request could not be verified. Please try again.";
                header("Location: staff_dashboard.php?page=peer"); exit;
            }
            $submitted_group = $_POST['group'] ?? '';
            $tid_raw         = $_POST['target_id'] ?? '';
            $ratings         = $_POST['ratings'] ?? [];
            $comments        = trim($_POST['comments'] ?? '');

            // ── Validation (per the Peer Evaluation designation-selection update) ──
            if (empty($submitted_group) || !in_array($submitted_group, ['teacher', 'staff'], true)) {
                $_SESSION['toast_error'] = "Please select a designation.";
                header("Location: staff_dashboard.php?page=peer"); exit;
            }
            if (empty($tid_raw)) {
                $_SESSION['toast_error'] = "Please select a user to evaluate.";
                header("Location: staff_dashboard.php?page=peer&group=" . urlencode($submitted_group)); exit;
            }
            $tid = intval($tid_raw);

            $tchk = $mysqli->prepare("SELECT id, designation, role FROM users WHERE id=? AND role IN ('teacher','staff') AND is_active=1 LIMIT 1");
            $tchk->bind_param("i", $tid); $tchk->execute();
            $tchkRow = $tchk->get_result()->fetch_assoc(); $tchk->close();

            if (!$tchkRow) {
                $_SESSION['toast_error'] = "The selected user does not exist.";
                header("Location: staff_dashboard.php?page=peer&group=" . urlencode($submitted_group)); exit;
            }
            if (resolve_peer_group($tchkRow['designation'] ?? '', $token_to_target, $system_categories, $tchkRow['role'] ?? null) !== $submitted_group) {
                $_SESSION['toast_error'] = "The selected user does not belong to the selected designation.";
                header("Location: staff_dashboard.php?page=peer&group=" . urlencode($submitted_group)); exit;
            }

            // Eligibility check — peer evaluations have no level restriction (per requirements,
            // staff/faculty can evaluate anyone they've worked with), but this still blocks
            // self-evaluation and invalid targets before anything gets inserted.
            [$eligible, $eligMsg] = canPeerEvaluate($mysqli, $user_id, $tid);

            if (!$eligible) {
                $_SESSION['toast_error'] = $eligMsg;
                header("Location: staff_dashboard.php?page=peer&group=" . urlencode($submitted_group)); exit;
            }

            // Gate on an active evaluation period -- no period open, no submissions.
            $periodStmt = $mysqli->query("SELECT id FROM evaluation_periods WHERE is_active=1 LIMIT 1");
            $activePeriod = $periodStmt ? $periodStmt->fetch_assoc() : null;
            if (!$activePeriod) {
                $_SESSION['toast_error'] = "No evaluation period is currently open.";
                header("Location: staff_dashboard.php?page=peer&group=" . urlencode($submitted_group)); exit;
            }
            $period_id = (int)$activePeriod['id'];

            // Only accept ratings for question IDs that actually belong to this
            // target's EA-assigned peer question set — anything else in the POST
            // is ignored rather than trusted, so a tampered request can't insert
            // stray rows. Mirrors the display-side fetch: per-user user_questions
            // for Staff targets, shared 'Teacher'-bucket evaluation_questions
            // otherwise.
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
                header("Location: staff_dashboard.php?page=peer_eval&tid=" . $tid . "&group=" . urlencode($submitted_group)); exit;
            }

            // Check duplicate for this period -- evaluator_id is this staff's user_id
            $dup = $mysqli->prepare("SELECT id FROM evaluation_tracker WHERE evaluator_id=? AND target_user_id=? AND eval_type='staff_peer' AND period_id=? LIMIT 1");
            $dup->bind_param("iii", $user_id, $tid, $period_id); $dup->execute(); $dup->store_result();

            if ($dup->num_rows === 0) {
                $dup->close();
                try {
                    $mysqli->begin_transaction();
$eval_type         = 'peer';
$overall           = count($ratings) ? round(array_sum($ratings)/count($ratings), 2) : 0;
$peer_group_label  = $peer_group_labels[$submitted_group] ?? ucfirst($submitted_group);

$trk = $mysqli->prepare("INSERT INTO evaluation_tracker (evaluator_id, target_user_id, form_id, period_id, eval_type, peer_group, score, remarks, status, submitted_at) VALUES (?,?,?,?,?,?,?,?,'submitted',NOW())");
$trk->bind_param("iiiissds", $user_id, $tid, $peer_form_id, $period_id, $eval_type, $peer_group_label, $overall, $comments);
$trk->execute();
$tracker_id = $mysqli->insert_id; $trk->close();

                    $ins = $mysqli->prepare("INSERT INTO questionnaire_answers (tracker_id, question_id, answer_score, submitted_at) VALUES (?,?,?,NOW())");
                    foreach ($ratings as $qid => $rating) {
                        $qid   = intval($qid);
                        $score = min(5, max(1, intval($rating)));
                        $ins->bind_param("iid", $tracker_id, $qid, $score);
                        $ins->execute();
                    }
                    $ins->close();
                    $mysqli->commit();

                    // Notify the evaluated staff member — anonymously, no evaluator identity attached.
                    $notif_msg = "You have received a new peer evaluation.";
                    $nins = $mysqli->prepare("INSERT INTO notifications (type, user_id, message) VALUES ('evaluation_received', ?, ?)");
                    $nins->bind_param("is", $tid, $notif_msg);
                    $nins->execute(); $nins->close();

                    $_SESSION['toast'] = "Peer evaluation submitted!";
                } catch (Exception $e) {
                    $mysqli->rollback();
                    error_log('[staff_dashboard] submit_peer failed for evaluator=' . $user_id . ' target=' . $tid . ': ' . $e->getMessage());
                    // A duplicate-key error from the new unique index lands here too
                    // (race between two near-simultaneous submissions) — same
                    // user-facing message either way, no internal detail leaked.
                    $_SESSION['toast_error'] = (($mysqli->errno ?? 0) === 1062)
                        ? "You already evaluated this staff member."
                        : "Submission failed. Please try again.";
                }
            } else {
                $dup->close();
                $_SESSION['toast_error'] = "You already evaluated this staff member.";
            }
            header("Location: staff_dashboard.php?page=peer&group=" . urlencode($submitted_group)); exit;
        }

        // ── DOCUMENTS ─────────────────────────────────────────────────
        $documents = [];
        if ($page === 'documents') {
            $docs = $mysqli->query("SELECT * FROM documents WHERE visibility IN ('Staff','All') AND is_archived=0 ORDER BY uploaded_at DESC");
            if ($docs) $documents = $docs->fetch_all(MYSQLI_ASSOC);
        }

        // ── RECENT SUBMISSIONS ────────────────────────────────────────
        $recent_subs = [];
        $rsStmt = $mysqli->prepare("
            SELECT et.id as tracker_id, et.submitted_at,
                   (SELECT AVG(qa.answer_score) FROM questionnaire_answers qa WHERE qa.tracker_id = et.id) as overall_score
            FROM evaluation_tracker et
            WHERE et.target_user_id = ?
            ORDER BY et.submitted_at DESC LIMIT 5
        ");
        $rsStmt->bind_param("i", $user_id);
        $rsStmt->execute();
        $rs = $rsStmt->get_result();
        if ($rs) $recent_subs = $rs->fetch_all(MYSQLI_ASSOC);
        $rsStmt->close();

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

        // ── DESIGNATION SUGGESTIONS ───────────────────────────────────
        $desig_suggestions = ['Personnel','Registrar','Cashier','Bookkeeper','Librarian','Guidance','Nurse','Coordinator','Teacher','Adviser','Department Head'];

        $toast       = $_SESSION['toast']       ?? ''; unset($_SESSION['toast']);
        $toast_error = $_SESSION['toast_error'] ?? ''; unset($_SESSION['toast_error']);

        // Helpers
        $first_name = explode(',', $full_name)[0] ?? $full_name;
        $parts      = explode(' ', trim($full_name));
        $initials   = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="UTF-8"/>
        <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
        <title>Staff Dashboard — PBI</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
        <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
        <style>
        :root{
            --dark:#0A192F;--mid:#172A45;--inner:#0F1F3D;
            --accent:#2B6CB0;--hover:#4C78B8;
            --teal:#6366F1;--teal-hover:#818CF8;
            --light:#E0E6F0;--muted:#A0B3C6;
            --danger:#F05454;--success:#22C55E;
            --border:rgba(255,255,255,0.08);--radius:10px;--shadow:0 4px 20px rgba(0,0,0,0.35);
            --sidebar-w:240px;
        }
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:'DM Sans',sans-serif;background:var(--dark);color:var(--light);min-height:100vh;display:flex;}

        /* SIDEBAR */
        .sidebar{width:var(--sidebar-w);flex-shrink:0;background:var(--mid);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:40;transition:transform .3s;}
        .sidebar-brand{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;cursor:pointer;transition:background .2s;position:relative;}
        .sidebar-brand:hover{background:rgba(255,255,255,.04);}
        .brand-avatar{width:42px;height:42px;border-radius:50%;flex-shrink:0;border:2px solid var(--teal);box-shadow:0 0 10px rgba(99,102,241,.3);overflow:hidden;background:var(--inner);display:flex;align-items:center;justify-content:center;}
        .brand-avatar img{width:100%;height:100%;object-fit:cover;display:block;}
        .brand-avatar .brand-initials{font-family:'Rajdhani',sans-serif;font-size:15px;font-weight:700;color:var(--teal-hover);line-height:1;}
        .sidebar-title{font-family:'Rajdhani',sans-serif;font-size:14px;font-weight:700;letter-spacing:.4px;color:#fff;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:118px;}
        .sidebar-sub{font-size:11px;color:var(--teal-hover);letter-spacing:.2px;margin-top:2px;font-weight:600;}
        .sidebar-caret{font-size:11px;color:var(--muted);flex-shrink:0;transition:transform .2s;}
        .sidebar-profile-dropdown{margin:0 12px;max-height:0;opacity:0;overflow:hidden;background:var(--inner);border-radius:10px;transition:max-height .2s ease,opacity .2s ease,margin .2s ease;}
        .sidebar-profile-dropdown.open{max-height:260px;opacity:1;margin:8px 12px 10px;border:1px solid var(--border);}
        .profile-dd-btn{width:100%;padding:10px 12px;border-radius:8px;border:none;background:none;color:var(--light);font-size:13px;font-family:'DM Sans',sans-serif;cursor:pointer;display:flex;align-items:center;gap:10px;transition:background .18s;text-align:left;text-decoration:none;}
        .profile-dd-btn:hover{background:rgba(255,255,255,.06);}
        .profile-dd-btn i{width:16px;text-align:center;color:var(--muted);}
        .profile-dd-divider{height:1px;background:var(--border);margin:6px 4px;}
        .profile-dd-btn.logout{color:#f87171;}
        .profile-dd-btn.logout i{color:#f87171;}
        .dd-appearance-val{margin-left:auto;font-size:11px;color:var(--muted);font-weight:700;background:rgba(255,255,255,.06);padding:3px 9px;border-radius:20px;flex-shrink:0;}
        .profile-dd-btn:hover .dd-appearance-val{color:var(--teal-hover);}
        .sidebar-nav{flex:1;padding:16px 10px;overflow-y:auto;}
        .nav-section-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:var(--muted);padding:0 8px;margin-bottom:6px;margin-top:16px;}
        .nav-section-label:first-child{margin-top:0;}
        .nav-link{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:14px;font-weight:600;transition:all .2s;margin-bottom:2px;}
        .nav-link:hover{background:rgba(255,255,255,.05);color:var(--light);}
        .nav-link.active{background:rgba(99,102,241,.18);color:var(--teal-hover);}
        .nav-link.active i{color:var(--teal);}
        .nav-link i{font-size:15px;width:18px;text-align:center;}
        .nav-link .badge{margin-left:auto;background:var(--teal);color:#fff;font-size:10px;font-weight:700;padding:1px 7px;border-radius:20px;}
        .sidebar-footer{padding:14px 16px;border-top:1px solid var(--border);}
        .btn-logout-side{display:flex;align-items:center;gap:7px;width:100%;padding:9px 12px;border:1px solid rgba(240,84,84,.3);background:rgba(240,84,84,.08);border-radius:8px;color:#f87171;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;transition:all .2s;font-family:'DM Sans',sans-serif;}
        .btn-logout-side:hover{background:rgba(240,84,84,.18);}
        .btn-view-all-evals{
        width:100%;
        display:flex;
        align-items:center;
        gap:10px;
        background:rgba(99,102,241,.12);
        border:1px solid rgba(99,102,241,.3);
        color:var(--teal-hover);
        font-size:13.5px;
        font-weight:700;
        padding:12px 16px;
        border-radius:10px;
        cursor:pointer;
        font-family:'DM Sans',sans-serif;
        transition:background .2s;
    }
    .btn-view-all-evals:hover{background:rgba(99,102,241,.2);}

        /* TOP NAV */
        .top-nav{position:fixed;top:0;left:var(--sidebar-w);right:0;height:60px;z-index:30;background:var(--mid);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 28px;box-shadow:var(--shadow);}
        .nav-page-title{font-family:'Rajdhani',sans-serif;font-size:20px;font-weight:700;letter-spacing:.5px;color:#fff;}
        .nav-right{display:flex;align-items:center;gap:14px;}
        .period-badge{background:rgba(99,102,241,.15);border:1px solid rgba(99,102,241,.3);color:var(--teal-hover);padding:5px 12px;border-radius:20px;font-size:12px;font-weight:600;display:flex;align-items:center;gap:6px;}
        .hamburger{display:none;background:none;border:none;color:var(--light);font-size:20px;cursor:pointer;}

        /* NOTIFICATION BELL */
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
        .notif-item.unread{background:rgba(99,102,241,.07);}
        .notif-item.unread::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--teal);border-radius:0 2px 2px 0;}
        .notif-icon{width:30px;height:30px;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:12px;margin-top:1px;}
        .notif-text{font-size:12px;color:var(--light);line-height:1.45;margin-bottom:3px;word-break:break-word;}
        .notif-meta{font-size:11px;color:var(--muted);}
        .notif-empty{text-align:center;padding:32px 16px;color:var(--muted);font-size:13px;}
        .notif-empty i{font-size:28px;display:block;margin-bottom:8px;opacity:.2;}

        /* PROFILE PHOTO MODAL */
        .photo-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:300;display:none;align-items:center;justify-content:center;padding:20px;}
        .photo-modal-overlay.open{display:flex;}
        .photo-modal{background:var(--mid);border:1px solid var(--border);border-radius:18px;width:100%;max-width:400px;box-shadow:0 20px 60px rgba(0,0,0,.6);overflow:hidden;}
        .photo-modal-header{padding:20px 24px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
        .photo-modal-title{font-family:'Rajdhani',sans-serif;font-size:20px;font-weight:700;color:#fff;}
        .photo-modal-body{padding:24px;}
        .photo-upload-circle{width:110px;height:110px;border-radius:50%;border:3px dashed rgba(99,102,241,.5);display:flex;align-items:center;justify-content:center;margin:0 auto 20px;cursor:pointer;transition:border-color .2s;overflow:hidden;position:relative;background:var(--inner);}
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

        /* MAIN */
        .main{margin-left:var(--sidebar-w);margin-top:60px;padding:28px;flex:1;min-height:calc(100vh - 60px);}

        /* TOAST */
        .toast{border-radius:8px;padding:12px 18px;font-size:13px;margin-bottom:22px;display:flex;align-items:center;gap:8px;animation:fadeIn .3s ease;}
        .toast-success{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:#86efac;}
        .toast-error{background:rgba(240,84,84,.12);border:1px solid rgba(240,84,84,.3);color:#fca5a5;}
        @keyframes fadeIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}

        /* DASHBOARD */
        .welcome-bar{background:linear-gradient(135deg,var(--mid) 0%,rgba(99,102,241,.15) 100%);border:1px solid rgba(99,102,241,.2);border-radius:14px;padding:24px 28px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;}
        .welcome-bar h2{font-family:'Rajdhani',sans-serif;font-size:24px;font-weight:700;color:#fff;margin-bottom:4px;}
        .welcome-bar p{font-size:13px;color:var(--muted);}
        .score-chip{background:rgba(99,102,241,.2);border:1px solid rgba(99,102,241,.35);border-radius:12px;padding:14px 22px;text-align:center;}
        .score-chip .big{font-size:32px;font-weight:700;color:var(--teal-hover);}
        .score-chip .lbl{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;}
        .stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px;margin-bottom:24px;}
        .stat-box{background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:18px 20px;}
        .stat-box-lbl{font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:8px;}
        .stat-box-val{font-size:26px;font-weight:700;color:#fff;}
        .stat-box-val.teal{color:var(--teal-hover);}
        .stat-box-val.gold{color:#F59E0B;}
        .section-card{background:var(--mid);border:1px solid var(--border);border-radius:14px;padding:22px 24px;margin-bottom:22px;}
        .section-card-title{font-family:'Rajdhani',sans-serif;font-size:18px;font-weight:700;color:#fff;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
        .cat-row{display:flex;align-items:center;gap:12px;margin-bottom:10px;}
        .cat-name{font-size:13px;color:var(--light);width:200px;flex-shrink:0;}
        .cat-bar-bg{flex:1;height:7px;background:rgba(255,255,255,.07);border-radius:4px;overflow:hidden;}
        .cat-bar-fill{height:100%;border-radius:4px;transition:width .5s ease;}
        .cat-score{font-size:13px;font-weight:700;width:36px;text-align:right;}
        .sub-item{background:var(--inner);border:1px solid var(--border);border-radius:8px;padding:12px 16px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;}
        .sub-meta{font-size:12px;color:var(--muted);}
        .sub-score-badge{padding:4px 12px;border-radius:20px;font-size:13px;font-weight:700;}
        .sub-item-clickable{cursor:pointer;transition:background .15s,border-color .15s;}
        .sub-item-clickable:hover{background:rgba(99,102,241,.08);border-color:rgba(99,102,241,.35);}
        .btn-view-details{background:rgba(99,102,241,.14);border:1px solid rgba(99,102,241,.3);color:var(--teal-hover);font-size:11px;font-weight:700;padding:5px 12px;border-radius:20px;cursor:pointer;white-space:nowrap;font-family:'DM Sans',sans-serif;}
        .btn-view-details:hover{background:var(--teal);color:#fff;}
        .empty-state{text-align:center;padding:48px 20px;color:var(--muted);}
        .empty-state i{font-size:38px;opacity:.3;display:block;margin-bottom:14px;}
        .back-link{display:inline-flex;align-items:center;gap:7px;background:var(--inner);border:1px solid var(--border);color:var(--light);padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;margin-bottom:18px;transition:background .2s;}
        .back-link:hover{background:var(--accent);}

        /* ══ EVALUATION DETAILS MODAL ══ */
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
        .eval-q-card{background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin-bottom:10px;}
        .eval-q-no{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:5px;font-weight:700;}
        .eval-q-text{font-size:14px;font-weight:600;color:#fff;margin-bottom:8px;line-height:1.5;}
        .eval-cat-header{display:flex;align-items:center;gap:8px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1.5px;color:var(--teal-hover);margin:18px 0 10px;padding-bottom:8px;border-bottom:1px solid rgba(99,102,241,.2);}
        .eval-cat-header:first-child{margin-top:0;}

        /* ══ MY PROFILE / ROLE ASSIGNMENT ══ */
        .profile-hero{background:linear-gradient(135deg,var(--mid) 0%,rgba(99,102,241,.18) 100%);border:1px solid rgba(99,102,241,.25);border-radius:16px;padding:28px;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;gap:22px;flex-wrap:wrap;}
        .profile-hero-left{display:flex;align-items:center;gap:22px;flex-wrap:wrap;}
        .profile-hero-avatar{width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid var(--teal);box-shadow:0 0 18px rgba(99,102,241,.4);flex-shrink:0;display:flex;align-items:center;justify-content:center;color:var(--teal-hover);font-size:30px;background:var(--inner);}
        .profile-hero-name{font-family:'Rajdhani',sans-serif;font-size:26px;font-weight:700;color:#fff;margin-bottom:6px;}
        .profile-hero-desig{display:inline-flex;align-items:center;gap:7px;background:rgba(99,102,241,.2);border:1px solid rgba(99,102,241,.35);color:var(--teal-hover);font-size:13px;font-weight:700;padding:5px 14px;border-radius:20px;}

        /* ROLE ASSIGNMENT CARD */
        .role-card{background:var(--mid);border:2px solid rgba(99,102,241,.3);border-radius:16px;padding:26px;margin-bottom:22px;position:relative;overflow:hidden;}
        .role-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--teal),var(--teal-hover));}
        .role-card-title{font-family:'Rajdhani',sans-serif;font-size:20px;font-weight:700;color:#fff;margin-bottom:4px;display:flex;align-items:center;gap:9px;}
        .role-card-sub{font-size:13px;color:var(--muted);margin-bottom:22px;}

        /* Quick-pick chips */
        .role-chips-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:10px;display:flex;align-items:center;gap:6px;}
        .role-chips{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px;}
        .role-chip{padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:2px solid var(--border);background:var(--inner);color:var(--muted);transition:all .22s;display:flex;align-items:center;gap:6px;}
        .role-chip:hover{border-color:var(--teal);color:var(--teal-hover);background:rgba(99,102,241,.1);}
        .role-chip.is-current{border-color:var(--teal);background:rgba(99,102,241,.18);color:var(--teal-hover);pointer-events:none;}
        .role-chip.is-current::after{content:'✓';margin-left:2px;font-size:12px;}

        /* Text input row */
        .custom-role-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:8px;display:flex;align-items:center;gap:6px;}
        .custom-role-row{display:flex;gap:10px;flex-wrap:wrap;}
        .custom-role-input{flex:1;min-width:220px;background:var(--inner);border:2px solid var(--border);color:var(--light);padding:13px 16px;border-radius:10px;font-size:14px;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .2s,box-shadow .2s;}
        .custom-role-input:focus{border-color:var(--teal);box-shadow:0 0 0 3px rgba(99,102,241,.2);}
        .custom-role-input::placeholder{color:var(--muted);}
        .btn-save-role{background:var(--teal);color:#fff;border:none;padding:13px 28px;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:8px;transition:all .2s;font-family:'DM Sans',sans-serif;white-space:nowrap;}
        .btn-save-role:hover{background:var(--teal-hover);transform:translateY(-1px);}

        .info-note{background:rgba(43,108,176,.08);border:1px solid rgba(43,108,176,.2);border-radius:10px;padding:14px 18px;font-size:13px;color:var(--muted);display:flex;gap:10px;align-items:flex-start;margin-top:20px;}
        .info-note i{color:#60a5fa;flex-shrink:0;margin-top:1px;}

        /* TEACHING LEVEL DROPDOWN (header quick-access, mirrors faculty dashboard UX) */
        .level-view-wrap{flex-shrink:0;background:var(--inner);border:1px solid var(--border);border-radius:12px;padding:14px 18px;min-width:220px;max-width:320px;transition:box-shadow .3s ease,border-color .3s ease;}
        .level-view-wrap.flash{border-color:var(--teal);box-shadow:0 0 0 3px rgba(13,148,136,.25);}
        .level-view-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);display:flex;align-items:center;gap:7px;margin-bottom:9px;}
        .level-view-pills{display:flex;flex-wrap:wrap;gap:6px;}
        .level-view-pill{background:rgba(13,148,136,.15);color:#5eead4;border:1px solid rgba(13,148,136,.35);font-size:12px;font-weight:700;padding:4px 12px;border-radius:20px;}
        .level-view-empty{font-size:11.5px;color:#fcd34d;display:flex;align-items:center;gap:6px;line-height:1.5;}
        .level-view-hint{font-size:11px;color:var(--muted);margin-top:9px;}

        /* Toggle switch for notification preferences */
        .toggle-switch{position:relative;display:inline-block;width:42px;height:24px;flex-shrink:0;cursor:pointer;}
        .toggle-switch input{display:none;}
        .toggle-slider{position:absolute;inset:0;background:var(--border);border-radius:20px;transition:background .2s;}
        .toggle-slider::before{content:"";position:absolute;width:18px;height:18px;left:3px;top:3px;background:#fff;border-radius:50%;transition:transform .2s;}
        .toggle-switch input:checked + .toggle-slider{background:var(--teal);}
        .toggle-switch input:checked + .toggle-slider::before{transform:translateX(18px);}

        /* STUDENT MINI CARDS */
        .students-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:16px;}
        .student-mini-card{background:var(--mid);border:2px solid var(--border);border-radius:12px;padding:16px 12px;display:flex;flex-direction:column;align-items:center;gap:6px;text-align:center;}
        .student-mini-photo{width:60px;height:60px;border-radius:50%;object-fit:cover;border:2px solid var(--border);}
        .student-mini-photo-ph{width:60px;height:60px;border-radius:50%;background:var(--inner);border:2px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:20px;}
        .student-mini-name{font-size:13px;font-weight:600;color:#fff;line-height:1.3;}
        .student-year-badge{font-size:10px;font-weight:700;color:#a5b4fc;background:rgba(99,102,241,.15);border:1px solid rgba(99,102,241,.3);border-radius:20px;padding:2px 10px;margin-top:2px;}

        /* ══ PEER EVALUATION — PHOTO CARD GRID ══ */
        .peer-card-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:16px;}
        .peer-card{background:var(--inner);border:1px solid var(--border);border-radius:12px;padding:20px 16px;display:flex;flex-direction:column;align-items:center;text-align:center;gap:4px;transition:border-color .2s,transform .2s;}
        .peer-card:hover{border-color:rgba(99,102,241,.4);transform:translateY(-2px);}
        .peer-card.is-done{opacity:.65;}
        .peer-card-photo{width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid var(--border);margin-bottom:8px;}
        .peer-card-photo-ph{display:flex;align-items:center;justify-content:center;background:var(--mid);color:var(--muted);font-size:22px;}
        .peer-card-name{font-size:14px;font-weight:700;color:#fff;line-height:1.3;}
        .peer-card-desig{font-size:12px;color:var(--teal-hover);margin-bottom:12px;}
        .peer-card-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;width:100%;padding:9px 0;border-radius:8px;border:none;background:var(--teal);color:#fff;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;transition:background .2s;font-family:'DM Sans',sans-serif;}
        .peer-card-btn:hover{background:var(--teal-hover);}
        .peer-card-btn.done{background:rgba(255,255,255,.06);color:var(--muted);cursor:not-allowed;}

        /* ══ PEER EVALUATION — DROPDOWN SELECTOR ══ */
        .peer-select-card{display:flex;flex-direction:column;gap:14px;}
        .peer-select-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;}
        .peer-select-input{flex:1;min-width:240px;background:var(--inner);border:2px solid var(--border);color:var(--light);padding:13px 16px;border-radius:10px;font-size:14px;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .2s,box-shadow .2s;appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23A0B3C6' stroke-width='2.5'><polyline points='6 9 12 15 18 9'/></svg>");background-repeat:no-repeat;background-position:right 14px center;padding-right:38px;}
        .peer-select-input:focus{border-color:var(--teal);box-shadow:0 0 0 3px rgba(99,102,241,.2);}
        .peer-select-input option:disabled{color:#5a6b80;}
        .peer-select-hint{font-size:12px;color:var(--muted);display:flex;align-items:center;gap:6px;}
        .peer-select-hint.warn{color:#fcd34d;}
        .btn-proceed-peer{background:var(--teal);color:#fff;border:none;padding:13px 26px;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:8px;transition:all .2s;font-family:'DM Sans',sans-serif;white-space:nowrap;}
        .btn-proceed-peer:hover:not(:disabled){background:var(--teal-hover);transform:translateY(-1px);}
        .btn-proceed-peer:disabled{opacity:.45;cursor:not-allowed;}

        /* PEER EVAL FORM */
        .eval-header-bar{background:var(--mid);border:1px solid var(--border);border-radius:14px;padding:20px 24px;margin-bottom:20px;display:flex;align-items:center;gap:16px;}
        .eval-photo-lg{width:60px;height:60px;border-radius:50%;object-fit:cover;border:2px solid var(--teal);}
        .eval-photo-ph{width:60px;height:60px;border-radius:50%;background:var(--inner);border:2px solid var(--teal);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:22px;}
        .eval-name{font-family:'Rajdhani',sans-serif;font-size:18px;font-weight:700;color:#fff;}
        .eval-desig{font-size:12px;color:var(--muted);}
        .scale-legend{position:sticky;top:60px;z-index:20;background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:10px 14px;margin-bottom:20px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;box-shadow:var(--shadow);}
        .scale-legend-item{display:flex;align-items:center;gap:7px;background:var(--inner);border:1px solid var(--border);border-radius:8px;padding:5px 10px;}
        .scale-legend-num{width:20px;height:20px;border-radius:5px;background:var(--teal);color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
        .scale-legend-lbl{font-size:12px;font-weight:600;color:var(--light);}
        .cat-group{margin-bottom:26px;}
        .cat-group-title{display:flex;align-items:center;gap:9px;font-family:'Rajdhani',sans-serif;font-size:15px;font-weight:700;color:var(--teal-hover);text-transform:uppercase;letter-spacing:1px;padding-bottom:8px;margin-bottom:14px;border-bottom:1px solid var(--border);}
        .q-card{background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:18px 20px;margin-bottom:14px;}
        .q-no{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:5px;}
        .q-text{font-size:14px;font-weight:600;color:#fff;margin-bottom:14px;line-height:1.5;}
        .star-group{display:flex;gap:8px;flex-wrap:wrap;flex:1;}
        .star-btn{flex:1;min-width:78px;display:flex;flex-direction:column;align-items:center;gap:3px;padding:9px 6px;border-radius:8px;border:2px solid var(--border);background:var(--inner);color:var(--muted);cursor:pointer;transition:all .18s;font-family:'DM Sans',sans-serif;}
        .star-btn .sb-num{font-size:14px;font-weight:700;}
        .star-btn .sb-txt{font-size:10px;font-weight:600;letter-spacing:.3px;text-transform:uppercase;}
        .star-btn:hover,.star-btn.sel{background:var(--teal);border-color:var(--teal);color:#fff;transform:translateY(-1px);}
        .comments-card{background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:18px 20px;margin-bottom:18px;}
        .comments-box{width:100%;min-height:90px;resize:vertical;background:var(--inner);border:1px solid var(--border);border-radius:8px;color:var(--light);font-family:'DM Sans',sans-serif;font-size:13px;padding:12px 14px;outline:none;transition:border-color .2s;}
        .comments-box:focus{border-color:var(--teal);}
        .comments-box::placeholder{color:var(--muted);}
        .submit-row{background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:18px 22px;display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;}
        .btn-submit{background:var(--teal);color:#fff;border:none;padding:12px 30px;border-radius:var(--radius);font-size:14px;font-weight:700;cursor:pointer;transition:all .2s;display:flex;align-items:center;gap:8px;font-family:'DM Sans',sans-serif;}
        .btn-submit:hover{background:var(--teal-hover);transform:translateY(-1px);}

        /* DOCUMENTS */
        .doc-item{background:var(--inner);border:1px solid var(--border);border-radius:10px;padding:14px 18px;margin-bottom:10px;display:flex;align-items:center;gap:14px;transition:background .15s;}
        .doc-item:hover{background:rgba(43,108,176,.08);}
        .doc-icon{width:40px;height:40px;border-radius:8px;background:rgba(43,108,176,.2);display:flex;align-items:center;justify-content:center;color:var(--accent);font-size:18px;flex-shrink:0;}
        .doc-name{font-size:14px;font-weight:600;color:#fff;}
        .doc-meta{font-size:12px;color:var(--muted);}
        .doc-dl{margin-left:auto;background:var(--accent);color:#fff;border:none;padding:6px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;transition:background .2s;white-space:nowrap;}
        .doc-dl:hover{background:var(--hover);}

        /* RESPONSIVE */
        @media(max-width:900px){.sidebar{transform:translateX(-100%);}.sidebar.open{transform:translateX(0);}.top-nav{left:0;}.main{margin-left:0;}.hamburger{display:block;}}
        @media(max-width:600px){.main{padding:16px;}.stats-grid{grid-template-columns:1fr 1fr;}.students-grid{grid-template-columns:repeat(auto-fill,minmax(130px,1fr));}.scale-legend{top:0;}.star-btn{min-width:60px;}.custom-role-row{flex-direction:column;}.role-chips{gap:6px;}.peer-select-row{flex-direction:column;}.btn-proceed-peer{width:100%;justify-content:center;}}
        </style>
        </head>
        <body>

        <!-- SIDEBAR -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-brand" id="sidebarProfile" onclick="toggleSidebarDD(event)">
                <div class="brand-avatar">
                    <?php if ($staff_photo): ?>
                    <img src="<?= htmlspecialchars($photo_url) ?>" alt="<?= htmlspecialchars($full_name) ?>"/>
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
                    <i class="fa-solid fa-camera"></i> Update Profile Photo
                </button>
                <a href="staff_dashboard.php?page=profile" class="profile-dd-btn">
                    <i class="fa-solid fa-gear"></i> Settings
                </a>
                <a href="staff_dashboard.php?page=profile" class="profile-dd-btn" onclick="localStorage.setItem('pbi_open_level_dd','1')">
                    <i class="fa-solid fa-sliders"></i> My Teaching Level(s)
                </a>
                <button type="button" class="profile-dd-btn" id="appearanceBtn" onclick="toggleAppearance(event)">
                    <i class="fa-solid fa-palette"></i>
                    Appearance
                    <span class="dd-appearance-val" id="appearanceVal">Dark</span>
                </button>
                <div class="profile-dd-divider"></div>
                <a href="../logout.php" class="profile-dd-btn logout"
                   onclick="return confirm('Log out of your staff session?')">
                    <i class="fa-solid fa-right-from-bracket"></i> Log out
                </a>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-section-label">Main</div>
                <a href="staff_dashboard.php?page=dashboard" class="nav-link <?= $page==='dashboard'?'active':'' ?>">
                    <i class="fa-solid fa-house"></i> Dashboard
                </a>
                <a href="staff_dashboard.php?page=profile" class="nav-link <?= $page==='profile'?'active':'' ?>">
                    <i class="fa-solid fa-id-badge"></i> My Profile &amp; Role
                </a>

                <div class="nav-section-label">Evaluation</div>
                <a href="staff_dashboard.php?page=my_results" class="nav-link <?= $page==='my_results'?'active':'' ?>">
                    <i class="fa-solid fa-chart-bar"></i> My Results
                </a>
                <a href="staff_dashboard.php?page=peer" class="nav-link <?= in_array($page,['peer','peer_eval'])?'active':'' ?>">
                    <i class="fa-solid fa-users-viewfinder"></i> Peer Evaluation
                    <?php if (!empty($peers_all) && $page==='peer'): ?>
                    <span class="badge"><?= count($peers_all) - count($done_peers) ?></span>
                    <?php endif; ?>
                </a>

                <div class="nav-section-label">Resources</div>
                <a href="staff_dashboard.php?page=documents" class="nav-link <?= $page==='documents'?'active':'' ?>">
                    <i class="fa-solid fa-folder-open"></i> Documents
                </a>
            </nav>

            <div class="sidebar-footer">
                <a href="../logout.php" class="btn-logout-side"
                   onclick="return confirm('Log out of your staff session?')">
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
                            <img id="photoPreviewImg" src="<?= $staff_photo ? htmlspecialchars($photo_url) : '' ?>"
                                 style="<?= $staff_photo ? 'display:block' : '' ?>"/>
                            <i class="fa-solid fa-camera upload-icon-modal" id="uploadIconEl" style="<?= $staff_photo ? 'display:none' : '' ?>"></i>
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

        <!-- TOP NAV -->
        <nav class="top-nav">
            <div style="display:flex;align-items:center;gap:14px;">
                <button class="hamburger" onclick="document.getElementById('sidebar').classList.toggle('open')">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="nav-page-title">
                    <?php
                    $titles = ['dashboard'=>'Dashboard','profile'=>'My Profile & Role','my_results'=>'My Evaluation Results','peer'=>'Peer Evaluation','peer_eval'=>'Evaluate Peer','documents'=>'Documents'];
                    echo $titles[$page] ?? 'Dashboard';
                    ?>
                </div>
            </div>
            <div class="nav-right">
                <?php if ($period): ?>
                <div class="period-badge">
                    <i class="fa-solid fa-calendar-check"></i>
                    <?= htmlspecialchars($period['period_label']) ?>
                </div>
                <?php endif; ?>

                <div class="notif-wrap" id="notifWrap">
                    <button class="notif-btn <?= $unread_count>0?'has-unread':'' ?>" id="notifBtn" onclick="toggleNotifDropdown(event)" title="Notifications">
                        <i class="fa-regular fa-bell"></i>
                        <?php if ($unread_count > 0): ?>
                        <span class="notif-badge" id="notifBadge"><?= $unread_count > 99 ? '99+' : $unread_count ?></span>
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

        <!-- MAIN -->
        <main class="main">

        <?php if ($toast): ?>
        <div class="toast toast-success"><i class="fa-solid fa-circle-check"></i><?= htmlspecialchars($toast) ?></div>
        <?php endif; ?>
        <?php if ($toast_error): ?>
        <div class="toast toast-error"><i class="fa-solid fa-circle-exclamation"></i><?= htmlspecialchars($toast_error) ?></div>
        <?php endif; ?>

        <!-- ══════════ DASHBOARD ══════════ -->
        <?php if ($page === 'dashboard'): ?>

        <div class="welcome-bar">
            <div>
                <h2>Welcome, <?= htmlspecialchars(explode(',', $full_name)[0] ?? $full_name) ?>!</h2>
                <p><?= htmlspecialchars($designation) ?> &nbsp;·&nbsp;
                <?= $period ? htmlspecialchars($period['period_label']) : 'No active evaluation period' ?></p>
            </div>
            <div class="score-chip">
                <div class="big"><?= $my_avg !== null ? number_format($my_avg, 2) : '—' ?></div>
                <div class="lbl">Your Avg Score</div>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-box"><div class="stat-box-lbl">Evaluations Received</div><div class="stat-box-val teal"><?= $my_total ?></div></div>
            <div class="stat-box"><div class="stat-box-lbl">Overall Average</div><div class="stat-box-val gold"><?= $my_avg !== null ? number_format($my_avg,2).' / 5' : '—' ?></div></div>
            <div class="stat-box"><div class="stat-box-lbl">Performance Level</div>
                <div class="stat-box-val" style="font-size:18px;color:<?= $my_avg===null?'#6b7280':($my_avg>=4?'#4ade80':($my_avg>=3?'#facc15':'#f87171')) ?>">
                    <?= $my_avg===null?'—':($my_avg>=4?'Excellent':($my_avg>=3?'Good':'Needs Improvement')) ?>
                </div></div>
            <div class="stat-box"><div class="stat-box-lbl">Current Period</div>
                <div class="stat-box-val" style="font-size:16px;color:var(--teal-hover)"><?= $period?htmlspecialchars($period['semester']):'None' ?></div></div>
        </div>

        <?php if (!empty($my_scores)): ?>
        <div class="section-card">
            <div class="section-card-title"><i class="fa-solid fa-layer-group" style="color:var(--teal)"></i> Performance by Category</div>
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
    <div class="section-card-title"><i class="fa-solid fa-clock-rotate-left" style="color:var(--accent)"></i> Evaluations Received</div>
    <button type="button" class="btn-view-all-evals" id="viewEvalsBtn" onclick="toggleRecentEvals()">
        <i class="fa-solid fa-eye"></i> View Evaluations Received
        <i class="fa-solid fa-chevron-down" id="recentEvalsCaret" style="transition:transform .2s;margin-left:auto;"></i>
    </button>
    <div id="recentEvalsList" style="display:none;margin-top:14px;">
    <?php if (empty($recent_subs)): ?>
    <div class="empty-state"><i class="fa-solid fa-inbox"></i><p>No evaluations received yet.</p></div>
    <?php else: foreach ($recent_subs as $s):
                $sc = $s['overall_score'];
                $col = $sc>=4?'#4ade80':($sc>=3?'#facc15':'#f87171');
            ?>
            <div class="sub-item sub-item-clickable" onclick="openEvalDetails(<?= (int)$s['tracker_id'] ?>)">
                <div>
                    <div style="font-size:13px;color:var(--light);font-weight:600;"><i class="fa-solid fa-eye-slash" style="color:var(--muted);margin-right:5px"></i>Anonymous Evaluator</div>
                    <div class="sub-meta"><?= date('M d, Y', strtotime($s['submitted_at'])) ?></div>
                </div>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
                    <div class="sub-score-badge" style="background:<?= $col ?>22;color:<?= $col ?>;border:1px solid <?= $col ?>44">
                        <?= $sc !== null ? number_format($sc,2) : '—' ?> / 5
                    </div>
                    <button type="button" class="btn-view-details" onclick="event.stopPropagation(); openEvalDetails(<?= (int)$s['tracker_id'] ?>)">
                        View Details <i class="fa-solid fa-chevron-right"></i>
                    </button>
                </div>
            </div>
    <?php endforeach; endif; ?>
    </div>
</div>
        <!-- ══════════ MY PROFILE & ROLE ══════════ -->
        <?php elseif ($page === 'profile'): ?>

        <!-- Hero card with photo, name, and current role. -->
        <div class="profile-hero">
            <div class="profile-hero-left">
                <?php if ($photo_url): ?>
                <img class="profile-hero-avatar" src="<?= htmlspecialchars($photo_url) ?>" alt="<?= htmlspecialchars($full_name) ?>" style="display:block;"/>
                <?php else: ?>
                <div class="profile-hero-avatar"><i class="fa-solid fa-briefcase"></i></div>
                <?php endif; ?>
                <div>
                    <div class="profile-hero-name"><?= htmlspecialchars($full_name) ?></div>
                    <div class="profile-hero-desig">
                        <i class="fa-solid fa-id-badge"></i> <?= htmlspecialchars($designation) ?>
                    </div>
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

        <!-- ROLE ASSIGNMENT CARD -->
        <div class="role-card">
            <div class="role-card-title">
                <i class="fa-solid fa-tags" style="color:var(--teal)"></i>
                Assign / Update My Role
            </div>
            <div class="role-card-sub">
                Your role determines which evaluation questions apply to you and how you appear in the questionnaire.
                Changes take effect immediately and the admin is notified.
            </div>

            <!-- Quick pick chips -->
            <div class="role-chips-label">
                <i class="fa-solid fa-bolt" style="color:var(--teal)"></i> Quick Pick
            </div>
            <div class="role-chips">
                <?php foreach ($desig_suggestions as $d): ?>
                <div class="role-chip <?= ($d === $designation) ? 'is-current' : '' ?>"
                     onclick="pickRole('<?= htmlspecialchars(addslashes($d)) ?>')">
                    <?= htmlspecialchars($d) ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Custom text input -->
            <div class="custom-role-label">
                <i class="fa-solid fa-pen-to-square" style="color:var(--teal)"></i> Or type a custom role
            </div>
            <form method="POST" id="roleForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"/>
                <input type="hidden" name="update_designation" value="1"/>
                <div class="custom-role-row">
                    <input type="text" name="new_designation" id="roleInput" class="custom-role-input"
                           placeholder="e.g. Department Head, Bookkeeper…"
                           value="<?= htmlspecialchars($designation) ?>" required/>
                    <button type="submit" class="btn-save-role">
                        <i class="fa-solid fa-floppy-disk"></i> Save &amp; Notify Admin
                    </button>
                </div>
            </form>

            <div class="info-note">
                <i class="fa-solid fa-circle-info"></i>
                <span>
                    Your role change is logged and sent to the admin automatically so they can confirm or reassign you if needed.
                </span>
            </div>
        </div>

        <!-- ══════════ MY RESULTS ══════════ -->
        <?php elseif ($page === 'my_results'): ?>

        <div class="section-card">
            <div class="section-card-title"><i class="fa-solid fa-chart-bar" style="color:var(--teal)"></i> Your Evaluation Summary</div>
            <div style="display:flex;gap:28px;flex-wrap:wrap;margin-bottom:20px;">
                <div>
                    <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:5px;">Overall Average</div>
                    <div style="font-size:36px;font-weight:700;color:<?= $my_avg===null?'#6b7280':($my_avg>=4?'#4ade80':($my_avg>=3?'#facc15':'#f87171')) ?>">
                        <?= $my_avg !== null ? number_format($my_avg,2) : '—' ?><span style="font-size:16px;color:var(--muted)"> / 5</span>
                    </div>
                </div>
                <div>
                    <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:5px;">Total Responses</div>
                    <div style="font-size:36px;font-weight:700;color:var(--teal-hover)"><?= $my_total ?></div>
                </div>
            </div>
            <?php if (!empty($my_scores)): ?>
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
            <?php else: ?>
            <div class="empty-state"><i class="fa-solid fa-chart-simple"></i><p>No category data available yet.</p></div>
            <?php endif; ?>
        </div>

        <div class="section-card">
<div class="section-card">
    <div class="section-card-title"><i class="fa-solid fa-list" style="color:var(--accent)"></i> Evaluations Received (Anonymous)</div>
    <button type="button" class="btn-view-all-evals" id="viewAllEvalsBtn" onclick="toggleAllEvals()">
        <i class="fa-solid fa-eye"></i> View Evaluations Received
        <i class="fa-solid fa-chevron-down" id="allEvalsCaret" style="transition:transform .2s;margin-left:auto;"></i>
    </button>
    <div id="allEvalsList" style="display:none;margin-top:14px;">
    <?php if (empty($recent_subs)): ?>
            <div class="empty-state"><i class="fa-solid fa-inbox"></i><p>No evaluations recorded yet.</p></div>
            <?php else:
                $allStmt = $mysqli->prepare("
                    SELECT et.id AS tracker_id, et.submitted_at,
                           (SELECT AVG(qa.answer_score) FROM questionnaire_answers qa WHERE qa.tracker_id = et.id) as overall_score
                    FROM evaluation_tracker et
                    WHERE et.target_user_id=?
                    ORDER BY et.submitted_at DESC
                ");
                $allStmt->bind_param("i", $user_id);
                $allStmt->execute();
                $all_subs = $allStmt->get_result();
                if ($all_subs) while ($s = $all_subs->fetch_assoc()):
                    $col = $s['overall_score']>=4?'#4ade80':($s['overall_score']>=3?'#facc15':'#f87171');
            ?>
            <div class="sub-item sub-item-clickable" onclick="openEvalDetails(<?= (int)$s['tracker_id'] ?>)">
                <div>
                    <div style="font-size:13px;color:var(--light);font-weight:600;"><i class="fa-solid fa-eye-slash" style="color:var(--muted);margin-right:5px"></i>Anonymous</div>
                    <div class="sub-meta"><?= date('M d, Y g:i A', strtotime($s['submitted_at'])) ?></div>
                </div>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
                    <div class="sub-score-badge" style="background:<?= $col ?>22;color:<?= $col ?>;border:1px solid <?= $col ?>44">
                        <?= $s['overall_score'] !== null ? number_format($s['overall_score'],2) : '—' ?> / 5
                    </div>
                    <button type="button" class="btn-view-details" onclick="event.stopPropagation(); openEvalDetails(<?= (int)$s['tracker_id'] ?>)">
                        View Details <i class="fa-solid fa-chevron-right"></i>
                    </button>
                </div>
            </div>
            <?php endwhile; $allStmt->close(); endif; ?>
        </div>

        <!-- ══════════ PEER EVALUATION — STEP 1: CHOOSE DESIGNATION ══════════ -->
        <?php elseif ($page === 'peer' && $peer_group === null): ?>

        <div class="section-card">
            <div class="section-card-title"><i class="fa-solid fa-users" style="color:var(--teal)"></i> Peer Evaluation</div>
            <p style="font-size:13px;color:var(--muted);margin-bottom:20px;">First, choose which designation you'd like to evaluate. You'll then pick a specific person from that list.</p>

            <?php if (empty($peers_all)): ?>
            <div class="empty-state"><i class="fa-solid fa-users"></i><p>No other staff members registered yet.</p></div>
            <?php else:
                $teacher_count = count(array_filter($peers_all, fn($p) => resolve_peer_group($p['designation'] ?? '', $token_to_target, $system_categories, $p['role'] ?? null) === 'teacher'));
                $staff_count   = count($peers_all) - $teacher_count;
            ?>
            <div class="role-chips-label"><i class="fa-solid fa-bolt" style="color:var(--teal)"></i> Step 1: Select Designation</div>
            <div class="role-chips" style="margin-bottom:4px;">
                <a href="staff_dashboard.php?page=peer&group=teacher" class="role-chip" style="text-decoration:none;padding:14px 22px;font-size:14px;">
                    <i class="fa-solid fa-chalkboard-user" style="margin-right:6px;color:var(--teal-hover)"></i> Teacher <span style="color:var(--muted);margin-left:6px;">(<?= $teacher_count ?>)</span>
                </a>
                <a href="staff_dashboard.php?page=peer&group=staff" class="role-chip" style="text-decoration:none;padding:14px 22px;font-size:14px;">
                    <i class="fa-solid fa-briefcase" style="margin-right:6px;color:var(--teal-hover)"></i> Staff <span style="color:var(--muted);margin-left:6px;">(<?= $staff_count ?>)</span>
                </a>
            </div>
            <div class="peer-select-hint"><i class="fa-solid fa-circle-info"></i> Please select a designation before proceeding.</div>
            <?php endif; ?>
        </div>

        <!-- ══════════ PEER EVALUATION — STEP 2: FILTERED USER LIST (CARD GRID) ══════════ -->
        <?php elseif ($page === 'peer' && $peer_group !== null): ?>

        <a href="staff_dashboard.php?page=peer" class="back-link"><i class="fa-solid fa-arrow-left"></i> Change Designation</a>

        <div class="section-card">
            <div class="section-card-title"><i class="fa-solid fa-users" style="color:var(--teal)"></i> Fellow <?= htmlspecialchars($peer_group_labels[$peer_group]) ?> Members</div>
            <p style="font-size:13px;color:var(--muted);margin-bottom:20px;">Select a colleague to evaluate. Your identity will be kept confidential.</p>

            <?php if (empty($peers)): ?>
            <div class="empty-state"><i class="fa-solid fa-users"></i><p>No registered <?= htmlspecialchars(strtolower($peer_group_labels[$peer_group])) ?> users found.</p></div>
            <?php else: ?>

            <!-- Cards populated dynamically from active users whose designation resolves
                 to the selected group (excluding self). Newly added accounts appear
                 automatically — no code change needed. -->
            <div class="peer-card-grid">
                <?php foreach ($peers as $p): $done = in_array($p['id'], $done_peers); ?>
                <div class="peer-card <?= $done ? 'is-done' : '' ?>">
                    <?php if (!empty($p['photo'])): ?>
                    <img class="peer-card-photo" src="../image/<?= htmlspecialchars($p['photo']) ?>" alt="<?= htmlspecialchars($p['full_name']) ?>"/>
                    <?php else: ?>
                    <div class="peer-card-photo peer-card-photo-ph"><i class="fa-solid fa-user"></i></div>
                    <?php endif; ?>
                    <div class="peer-card-name"><?= htmlspecialchars($p['full_name']) ?></div>
                    <div class="peer-card-desig"><?= htmlspecialchars($p['designation'] ?: $peer_group_labels[$peer_group]) ?></div>
                    <?php if ($done): ?>
                    <button type="button" class="peer-card-btn done" disabled><i class="fa-solid fa-circle-check"></i> Evaluated</button>
                    <?php else: ?>
                    <a href="staff_dashboard.php?page=peer_eval&tid=<?= (int)$p['id'] ?>&group=<?= urlencode($peer_group) ?>" class="peer-card-btn">Evaluate</a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <?php $done_in_group = count(array_intersect(array_column($peers, 'id'), $done_peers)); ?>
            <?php if ($done_in_group > 0): ?>
            <div class="peer-select-hint warn" style="margin-top:18px;"><i class="fa-solid fa-circle-check"></i> You've already evaluated <?= $done_in_group ?> of <?= count($peers) ?> <?= htmlspecialchars(strtolower($peer_group_labels[$peer_group])) ?> members this period.</div>
            <?php endif; ?>

            <?php endif; ?>
        </div>

        <!-- ══════════ PEER EVAL FORM ══════════ -->
        <?php elseif ($page === 'peer_eval' && $peer_target): ?>

        <a href="staff_dashboard.php?page=peer&group=<?= urlencode($peer_eval_group) ?>" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to <?= htmlspecialchars($peer_group_labels[$peer_eval_group]) ?> List</a>

        <div class="eval-header-bar">
            <?php if (!empty($peer_target['photo'])): ?>
            <img class="eval-photo-lg" src="<?= UPLOAD_URL . htmlspecialchars($peer_target['photo']) ?>" alt=""/>
            <?php else: ?>
            <div class="eval-photo-ph"><i class="fa-solid fa-user"></i></div>
            <?php endif; ?>
            <div>
                <div class="eval-name"><?= htmlspecialchars($peer_target['full_name']) ?></div>
                <div class="eval-desig"><?= htmlspecialchars($peer_target['designation'] ?? 'Staff') ?></div>
            </div>
        </div>

        <?php if (empty($peer_questions)): ?>
        <div style="background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.2);border-radius:10px;padding:18px;color:#fcd34d;font-size:13px;display:flex;gap:10px;">
            <i class="fa-solid fa-triangle-exclamation"></i> No peer evaluation questions set up yet. Please contact the admin.
        </div>
        <?php else: ?>

        <div class="scale-legend">
            <?php foreach ([5=>'Always',4=>'Often',3=>'Sometimes',2=>'Rarely',1=>'Never'] as $n=>$lbl): ?>
            <div class="scale-legend-item">
                <div class="scale-legend-num"><?= $n ?></div>
                <div class="scale-legend-lbl"><?= $lbl ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <form method="POST" action="staff_dashboard.php?page=peer_eval&tid=<?= $peer_target['id'] ?>&group=<?= urlencode($peer_eval_group) ?>" id="evalForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"/>
            <input type="hidden" name="submit_peer" value="1"/>
            <input type="hidden" name="target_id"   value="<?= $peer_target['id'] ?>"/>
            <input type="hidden" name="group"       value="<?= htmlspecialchars($peer_eval_group) ?>"/>

            <?php $qno = 1; foreach ($peer_categories as $cat_name => $cat_qs): ?>
            <div class="cat-group">
                <div class="cat-group-title"><i class="fa-solid fa-layer-group"></i> <?= htmlspecialchars($cat_name) ?></div>
                <?php foreach ($cat_qs as $q): $labels = [5=>'Always',4=>'Often',3=>'Sometimes',2=>'Rarely',1=>'Never']; ?>
                <div class="q-card">
                    <div class="q-no">Question <?= $qno++ ?></div>
                    <div class="q-text"><?= htmlspecialchars($q['question_text']) ?></div>
                    <div class="star-group" id="grp_<?= $q['id'] ?>">
                        <?php for ($r=5;$r>=1;$r--): ?>
                        <button type="button" class="star-btn" data-val="<?= $r ?>" onclick="rate(<?= $q['id'] ?>,<?= $r ?>)">
                            <span class="sb-num"><?= $r ?></span>
                            <span class="sb-txt"><?= $labels[$r] ?></span>
                        </button>
                        <?php endfor; ?>
                    </div>
                    <input type="hidden" name="ratings[<?= $q['id'] ?>]" id="r_<?= $q['id'] ?>" required/>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>

            <div class="comments-card">
                <div style="font-size:13px;font-weight:700;color:var(--teal-hover);margin-bottom:10px;"><i class="fa-solid fa-comment-dots"></i> Comments &amp; Suggestions <span style="font-size:11px;color:var(--muted);font-weight:500">(optional)</span></div>
                <textarea class="comments-box" name="comments" placeholder="Share your thoughts about this person's performance…"></textarea>
            </div>

            <div class="submit-row">
                <span style="font-size:13px;color:var(--muted);"><i class="fa-solid fa-circle-info" style="color:#60a5fa;margin-right:5px"></i>Rate each question 1 (Never) to 5 (Always). All required.</span>
                <button type="submit" class="btn-submit" onclick="return checkAll()"><i class="fa-solid fa-paper-plane"></i> Submit Evaluation</button>
            </div>
        </form>
        <?php endif; ?>

        <!-- ══════════ PEER EVAL — INVALID / MISSING TARGET ══════════ -->
        <?php elseif ($page === 'peer_eval' && !$peer_target): ?>

        <a href="staff_dashboard.php?page=peer" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Peer Selection</a>

        <div style="background:rgba(240,84,84,.08);border:1px solid rgba(240,84,84,.25);border-radius:10px;padding:18px;color:#fca5a5;font-size:13px;display:flex;gap:10px;">
            <i class="fa-solid fa-circle-exclamation"></i>
            <?= htmlspecialchars($peer_group_error ?: "Selected user does not exist, is inactive, or is not a valid Staff account. Please choose someone from the list.") ?>
        </div>

        <!-- ══════════ DOCUMENTS ══════════ -->
        <?php elseif ($page === 'documents'): ?>

        <div class="section-card">
            <div class="section-card-title"><i class="fa-solid fa-folder-open" style="color:var(--accent)"></i> Staff Documents</div>
            <?php if (empty($documents)): ?>
            <div class="empty-state"><i class="fa-solid fa-folder-open"></i><p>No documents uploaded for Staff yet.</p></div>
            <?php else: foreach ($documents as $d):
                $icons = ['pdf'=>'fa-file-pdf','doc'=>'fa-file-word','docx'=>'fa-file-word','xls'=>'fa-file-excel','xlsx'=>'fa-file-excel','ppt'=>'fa-file-powerpoint','pptx'=>'fa-file-powerpoint'];
                $ext   = strtolower(pathinfo($d['file_path']??'', PATHINFO_EXTENSION));
                $icon  = $icons[$ext] ?? 'fa-file';
            ?>
            <div class="doc-item">
                <div class="doc-icon"><i class="fa-solid <?= $icon ?>"></i></div>
                <div>
                    <div class="doc-name"><?= htmlspecialchars($d['title']) ?></div>
                    <div class="doc-meta"><?= htmlspecialchars($d['category']??'') ?> · <?= date('M d, Y', strtotime($d['uploaded_at'])) ?></div>
                </div>
                <?php if (!empty($d['file_path'])): ?>
                <a class="doc-dl" href="<?= htmlspecialchars($d['file_path']) ?>" target="_blank" download>
                    <i class="fa-solid fa-download"></i> Download
                </a>
                <?php endif; ?>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <?php endif; ?>
        </main>

        <script>
        // Quick-pick role chip → fill input
        function pickRole(name) {
            document.getElementById('roleInput').value = name;
            document.querySelectorAll('.role-chip').forEach(c => c.classList.remove('is-current'));
            event.target.classList.add('is-current');
        }

        // Star rating for peer eval
        function rate(qid, val) {
            document.querySelectorAll(`#grp_${qid} .star-btn`).forEach(b =>
                b.classList.toggle('sel', parseInt(b.dataset.val) === val)
            );
            document.getElementById(`r_${qid}`).value = val;
        }
        function checkAll() {
            const inputs = document.querySelectorAll('#evalForm input[type="hidden"][name^="ratings"]');
            for (const i of inputs) { if (!i.value) { alert('Please rate all questions.'); return false; } }
            return true;
        }

        // ── Evaluation details modal ──
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
                html += `<div class="section-card-title" style="font-size:14px;margin-bottom:12px;"><i class="fa-solid fa-layer-group" style="color:var(--teal)"></i> Performance by Category</div>`;
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
                html += `<div class="section-card-title" style="font-size:14px;margin:20px 0 12px;"><i class="fa-solid fa-list-check" style="color:var(--accent)"></i> Question-by-Question Results</div>`;
                let lastCat = null;
                data.questions.forEach((q, idx) => {
                    if (q.category !== lastCat) {
                        html += `<div class="eval-cat-header">${escapeHtml(q.category)}</div>`;
                        lastCat = q.category;
                    }
                    html += `<div class="eval-q-card">
                        <div class="eval-q-no">Question ${idx + 1}</div>
                        <div class="eval-q-text">${escapeHtml(q.question_text)}</div>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div>${starsHtml(q.score)}</div>
                            <div style="font-size:12px;color:var(--muted);font-weight:700;">Score: ${q.score} / 5</div>
                        </div>
                    </div>`;
                });
            }

            html += `<div class="section-card-title" style="font-size:14px;margin:20px 0 10px;"><i class="fa-solid fa-comment-dots" style="color:var(--teal-hover)"></i> Comments / Feedback</div>`;
            html += data.comment
                ? `<div class="eval-comment-box">"${escapeHtml(data.comment)}"</div>`
                : `<div class="eval-comment-box empty">No written feedback was provided.</div>`;

            return html;
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

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            const d = document.createElement('div');
            d.textContent = str;
            return d.innerHTML;
        }

        // Change-password confirm check
        const pwForm = document.getElementById('pwForm');
        if (pwForm) {
            pwForm.addEventListener('submit', function(e) {
                const newPw  = pwForm.querySelector('[name="new_password"]').value;
                const confPw = pwForm.querySelector('[name="confirm_password"]').value;
                if (newPw !== confPw) {
                    e.preventDefault();
                    alert('New password and confirmation do not match.');
                }
            });
        }

        // Mobile sidebar
        document.addEventListener('click', function(e) {
            const sb = document.getElementById('sidebar');
            if (sb.classList.contains('open') && !sb.contains(e.target) && !e.target.closest('.hamburger')) {
                sb.classList.remove('open');
            }
        });

        // ── Sidebar profile dropdown ──
        function toggleSidebarDD(e) {
            e.stopPropagation();
            const dd    = document.getElementById('sidebarProfileDropdown');
            const caret = document.getElementById('sidebarCaret');
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

        // If "My Teaching Level(s)" was clicked from the sidebar dropdown, land on
        // the profile page and scroll to / briefly highlight the read-only level
        // card (levels are admin-assigned now, not self-editable).
        document.addEventListener('DOMContentLoaded', function() {
            const wrap = document.getElementById('levelDdWrap');
            if (wrap && localStorage.getItem('pbi_open_level_dd') === '1') {
                localStorage.removeItem('pbi_open_level_dd');
                wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
                wrap.classList.add('flash');
                setTimeout(() => wrap.classList.remove('flash'), 1600);
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
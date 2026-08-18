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
require_once dirname(__DIR__) . '/shared/system_settings_service.php';
require_once dirname(__DIR__) . '/shared/ea_personnel_service.php';

// ── AUTH GUARD ────────────────────────────────────────────
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'dean') {
    header("Location: dean_login.php");
    exit;
}

// Faculty/Staff/EA/Student roster reads (ea_get_faculty(), ea_get_staff(),
// ea_get_executive_assistants(), ea_get_students()) live in
// shared/ea_personnel_service.php — see that file's header comment for
// the confirmed schema decisions and still-open TODOs (evaluation_status
// tracking, Faculty "program" field, questionnaire routing).

// ── PULL DEAN PROFILE ─────────────────────────────────────
$stmt = $mysqli->prepare("SELECT full_name, username, email, designation, photo, department FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── GLOBAL SYSTEM SETTINGS (single source of truth) ─────────────────────
// Everything about "what period is it, what's the status, what's the
// schedule" comes from here. The Dean dashboard never derives or stores
// any of this itself, and never hardcodes an academic year/term.
// (Step 14: Synchronization with System Settings)
$settings = get_system_settings($mysqli);

// A Dean oversees the Higher Education (internally "college") division.
// If the Executive Assistant has the active Academic Structure set to
// something else, the Dean must not show Higher Ed analytics as current.
$structureActive  = ($settings['academic_structure'] === 'college');
$period_id_int    = $settings['period_id'] ?? 0;
$evalOpen         = $settings['is_open_for_submission'];

// Step 8: internal value stays "college" everywhere; this is the only
// display label used in markup below.
const HIGHER_ED_LABEL = 'Higher Education';

// ── EVALUATION SUMMARY (Step 9 / Step 11 — Dashboard cards) ────────────
$facultyList = $staffList = $eaList = $studentList = [];
$facultyPending = $staffPending = $eaPending = 0;
$facultyCompleted = $staffCompleted = $eaCompleted = 0;
$overallCompletionPct = 0;
$studentParticipationPct = 0;
$totalAssigned = 0;

if ($structureActive) {
    $facultyList = ea_get_faculty($mysqli, $period_id_int);
    $staffList   = ea_get_staff($mysqli, $period_id_int);
    $eaList      = ea_get_executive_assistants($mysqli, $period_id_int);
    $studentList = ea_get_students($mysqli, $period_id_int);

    $countByStatus = function (array $rows, string $status): int {
        return count(array_filter($rows, fn($r) => ($r['evaluation_status'] ?? '') === $status));
    };

    $facultyCompleted = $countByStatus($facultyList, 'completed');
    $staffCompleted   = $countByStatus($staffList, 'completed');
    $eaCompleted      = $countByStatus($eaList, 'completed');

    $facultyPending = max(0, count($facultyList) - $facultyCompleted);
    $staffPending   = max(0, count($staffList) - $staffCompleted);
    $eaPending      = max(0, count($eaList) - $eaCompleted);

    $totalAssigned  = count($facultyList) + count($staffList) + count($eaList);
    $totalCompleted = $facultyCompleted + $staffCompleted + $eaCompleted;
    $overallCompletionPct = $totalAssigned > 0 ? (int) round($totalCompleted / $totalAssigned * 100) : 0;

    // Student participation stays informational only — Dean never manages
    // student accounts (Step 7), this is read-only context for the card.
    $studentSubmitted = $countByStatus($studentList, 'submitted');
    $studentParticipationPct = count($studentList) > 0
        ? (int) round($studentSubmitted / count($studentList) * 100)
        : 0;
}

// ── NOTIFICATIONS ─────────────────────────────────────────────────────
// Generic schedule-driven notices come straight from the shared service.
$notifications = $settings['notifications'];
if ($structureActive) {
    if ($evalOpen && ($facultyPending + $staffPending + $eaPending) > 0) {
        $remaining = $facultyPending + $staffPending + $eaPending;
        $notifications[] = "{$remaining} evaluation" . ($remaining === 1 ? '' : 's') . " still pending.";
    }
    if ($totalAssigned > 0 && $overallCompletionPct === 100) {
        $notifications[] = "All Higher Education evaluations are complete.";
    }
} else {
    $notifications[] = HIGHER_ED_LABEL . " is not the active academic structure right now.";
}
if (empty($notifications)) {
    $notifications[] = "No urgent items right now.";
}

// ── YOUR EVALUATION RATING (Phase 2, §7) ────────────────────────────
// The Dean's own average rating this period, from Teacher-submitted
// results. Same eval_type/eval_bucket placeholder convention used in
// dean_results.php — confirm the actual values your evaluation_tracker
// uses for Teacher → Dean submissions and adjust both files together.
const DEAN_RESULT_EVAL_TYPE   = 'teacher';
const DEAN_RESULT_EVAL_BUCKET = 'Dean';
$myRating = null;
if ($structureActive && $period_id_int > 0) {
    try {
        // bind_param() requires actual variables passed by reference — a
        // class constant or an array element (like $_SESSION['user_id'])
        // can't be passed directly, so copy them into plain local
        // variables first.
        $evalType   = DEAN_RESULT_EVAL_TYPE;
        $evalBucket = DEAN_RESULT_EVAL_BUCKET;
        $deanIdForRating = (int)$_SESSION['user_id'];

        $stmt = $mysqli->prepare("
            SELECT AVG(score) v FROM evaluation_tracker
            WHERE eval_type=? AND eval_bucket=? AND status IN ('submitted','approved')
              AND target_user_id=? AND period_id=?
        ");
        $stmt->bind_param("ssii", $evalType, $evalBucket, $deanIdForRating, $period_id_int);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $myRating = $row && $row['v'] !== null ? round((float)$row['v'], 2) : null;
    } catch (mysqli_sql_exception $e) {
        $myRating = null;
    }
}
$pendingEvaluationsTotal = $facultyPending + $staffPending + $eaPending;

$mysqli->close();
$photo_src = !empty($me['photo']) ? '../image/' . $me['photo'] : '../image/pbi_logo';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI — Dean Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
:root{--dark:#0A192F;--mid:#172A45;--inner:#0F1F3D;--violet:#7C5FD9;--violet-h:#9C85F0;--violet-dark:#5F45B8;--light:#E0E6F0;--muted:#A0B3C6;--radius:10px;--shadow:0 8px 32px rgba(0,0,0,0.45);--danger:#f05454;--good:#10B981;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{min-height:100vh;background:var(--dark);font-family:'DM Sans',sans-serif;color:var(--light);display:flex;}

.sidebar{width:250px;flex-shrink:0;background:rgba(23,42,69,.9);border-right:1px solid rgba(255,255,255,.08);min-height:100vh;padding:28px 20px;display:flex;flex-direction:column;}
.sb-profile{text-align:center;margin-bottom:26px;}
.sb-photo{width:72px;height:72px;border-radius:50%;object-fit:cover;border:2.5px solid var(--violet);box-shadow:0 0 18px rgba(124,95,217,.4);margin:0 auto 10px;display:block;}
.sb-name{font-weight:700;font-size:15px;color:#fff;}
.sb-role{font-size:11px;color:var(--violet-h);text-transform:uppercase;letter-spacing:.6px;margin-top:2px;}
.sb-scope{font-size:10px;color:var(--muted);margin-top:4px;}
.sb-nav{display:flex;flex-direction:column;gap:4px;margin-top:10px;}
.sb-nav a{display:flex;align-items:center;gap:10px;padding:11px 14px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:14px;font-weight:500;transition:background .2s,color .2s;}
.sb-nav a:hover,.sb-nav a.active{background:rgba(124,95,217,.15);color:#fff;}
.sb-nav a i{width:18px;text-align:center;color:var(--violet-h);}
.sb-logout{margin-top:auto;}
.sb-logout a{display:flex;align-items:center;gap:10px;padding:11px 14px;border-radius:8px;color:#fca5a5;text-decoration:none;font-size:14px;font-weight:500;transition:background .2s;}
.sb-logout a:hover{background:rgba(240,84,84,.12);}

.main{flex:1;padding:36px 44px;}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:28px;flex-wrap:wrap;gap:14px;}
.page-title{font-family:'Rajdhani',sans-serif;font-size:28px;font-weight:700;color:#fff;letter-spacing:1px;}
.page-sub{font-size:13px;color:var(--muted);margin-top:4px;}
.card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:30px;}
.stat-card{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:20px;box-shadow:var(--shadow);}
.stat-card i{color:var(--violet-h);font-size:20px;margin-bottom:10px;}
.stat-card .num{font-size:26px;font-weight:700;color:#fff;}
.stat-card .label{font-size:12px;color:var(--muted);margin-top:4px;}

.section{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:24px;box-shadow:var(--shadow);margin-bottom:26px;}
.section h2{font-family:'Rajdhani',sans-serif;font-size:19px;color:#fff;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.section h2 i{color:var(--violet-h);font-size:16px;}

.bar-wrap{background:rgba(255,255,255,.08);border-radius:6px;height:8px;width:100%;overflow:hidden;}
.bar-fill{height:100%;background:linear-gradient(90deg,var(--violet-dark),var(--violet-h));border-radius:6px;}
.pill{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;}
.pill.good{background:rgba(16,185,129,.14);color:var(--good);}
.pill.warn{background:rgba(124,95,217,.14);color:var(--violet-h);}
.pill.bad{background:rgba(240,84,84,.12);color:#fca5a5;}

.two-col{display:grid;grid-template-columns:1fr 1fr;gap:20px;}

.report-btns{display:flex;flex-wrap:wrap;gap:10px;}
.report-btns a, .qa-btns a{background:rgba(124,95,217,.12);border:1px solid rgba(124,95,217,.35);color:var(--violet-h);padding:10px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:7px;transition:background .2s;}
.report-btns a:hover, .qa-btns a:hover{background:rgba(124,95,217,.22);}

.notif-list{list-style:none;font-size:13px;}
.notif-list li{padding:10px 12px;border-radius:8px;background:rgba(255,255,255,.03);margin-bottom:8px;display:flex;align-items:center;gap:10px;}
.notif-list li i{color:var(--violet-h);}
.notif-list li:last-child{margin-bottom:0;}

.qa-btns{display:flex;flex-wrap:wrap;gap:10px;}

.period-badge{background:rgba(124,95,217,.14);border:1px solid rgba(124,95,217,.3);color:var(--violet-h);padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;display:flex;align-items:center;gap:7px;}
.period-badge.closed{background:rgba(240,84,84,.1);border-color:rgba(240,84,84,.3);color:#fca5a5;}
.period-badge.amber{background:rgba(217,119,6,.14);border-color:rgba(217,119,6,.3);color:#fbbf24;}
.period-badge.gray{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.12);color:var(--muted);}
.empty-note{color:var(--muted);font-size:13px;font-style:italic;}

.period-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin-bottom:16px;}
.period-field .period-field-label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:5px;}
.period-field .period-field-value{font-size:16px;font-weight:700;color:#fff;}
.period-message{font-size:13px;color:var(--muted);padding-top:14px;border-top:1px solid rgba(255,255,255,.06);}
.period-message strong{color:var(--light);}

.structure-note{display:flex;align-items:flex-start;gap:14px;padding:18px 20px;background:rgba(124,95,217,.08);border:1px solid rgba(124,95,217,.25);border-radius:12px;margin-bottom:26px;}
.structure-note i{color:var(--violet-h);font-size:20px;margin-top:2px;}
.structure-note p{font-size:13px;color:var(--light);line-height:1.6;}
.structure-note p b{color:#fff;}

.countdown-row{display:flex;gap:14px;margin-top:14px;}
.countdown-box{flex:1;background:rgba(10,25,47,.5);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:12px;text-align:center;}
.countdown-box .num{font-size:22px;font-weight:700;color:#fff;}
.countdown-box .lbl{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-top:2px;}

.stub-note{font-size:11px;color:var(--violet-h);background:rgba(124,95,217,.08);border:1px dashed rgba(124,95,217,.35);border-radius:8px;padding:8px 12px;margin-top:10px;}

@media(max-width:900px){.two-col{grid-template-columns:1fr;}}
@media(max-width:768px){body{flex-direction:column;}.sidebar{width:100%;min-height:auto;}}
</style>
</head>
<body>

<?php
$active = 'dashboard';
$sidebarScope = HIGHER_ED_LABEL . ' Division';
include __DIR__ . '/includes/dean_sidebar.php';
?>

<main class="main">
    <div class="page-header">
        <div>
            <div class="page-title">Welcome, <?= htmlspecialchars(explode(',', $me['full_name'] ?? 'Dean')[0]) ?></div>
            <div class="page-sub">Pandan Bay Institute — <?= HIGHER_ED_LABEL ?> Division Oversight</div>
        </div>
        <div class="period-badge <?= htmlspecialchars($settings['status']['cls']) ?>">
            <i class="fa-solid fa-calendar-check"></i>
            <?= htmlspecialchars($settings['academic_year']) ?> · <?= HIGHER_ED_LABEL ?> · <?= htmlspecialchars($settings['academic_term']) ?>
            — <?= htmlspecialchars($settings['status']['label']) ?>
        </div>
    </div>

    <?php if (!$structureActive): ?>
    <div class="structure-note">
        <i class="fa-solid fa-circle-info"></i>
        <p>
            <b><?= HIGHER_ED_LABEL ?> is not the active academic structure.</b><br>
            The current evaluation period is configured for <b><?= htmlspecialchars($settings['academic_structure_label']) ?></b>.
            <?= HIGHER_ED_LABEL ?> analytics are unavailable for this period.
        </p>
    </div>
    <?php endif; ?>

    <!-- ── CURRENT EVALUATION PERIOD ── -->
    <div class="section">
        <h2><i class="fa-solid fa-calendar-days"></i> Current Evaluation Period</h2>
        <div class="period-grid">
            <div class="period-field">
                <div class="period-field-label">Academic Year</div>
                <div class="period-field-value"><?= htmlspecialchars($settings['academic_year']) ?></div>
            </div>
            <div class="period-field">
                <div class="period-field-label">Academic Structure</div>
                <div class="period-field-value"><?= HIGHER_ED_LABEL ?></div>
            </div>
            <div class="period-field">
                <div class="period-field-label">Academic Term</div>
                <div class="period-field-value"><?= htmlspecialchars($settings['academic_term']) ?></div>
            </div>
            <div class="period-field">
                <div class="period-field-label">Status</div>
                <div class="period-field-value">
                    <span class="period-badge <?= htmlspecialchars($settings['status']['cls']) ?>" style="font-size:11px;">
                        <?= htmlspecialchars($settings['status']['label']) ?>
                    </span>
                </div>
            </div>
            <div class="period-field">
                <div class="period-field-label">Evaluation Opens</div>
                <div class="period-field-value"><?= $settings['eval_start'] ? htmlspecialchars(date('M j, Y', strtotime($settings['eval_start']))) : '—' ?></div>
            </div>
            <div class="period-field">
                <div class="period-field-label">Evaluation Closes</div>
                <div class="period-field-value"><?= $settings['eval_end'] ? htmlspecialchars(date('M j, Y', strtotime($settings['eval_end']))) : '—' ?></div>
            </div>
        </div>
        <div class="period-message">
            <strong><?= htmlspecialchars($settings['message']['headline']) ?></strong>
            <?= htmlspecialchars($settings['message']['sub']) ?>
        </div>

        <?php if ($settings['countdown_enabled'] && $evalOpen && $settings['eval_end']): ?>
        <div class="countdown-row" id="countdownRow" data-end="<?= htmlspecialchars($settings['eval_end']) ?>">
            <div class="countdown-box"><div class="num" id="cd-days">—</div><div class="lbl">Days</div></div>
            <div class="countdown-box"><div class="num" id="cd-hours">—</div><div class="lbl">Hours</div></div>
            <div class="countdown-box"><div class="num" id="cd-mins">—</div><div class="lbl">Minutes</div></div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($structureActive): ?>

    <!-- STATS (Phase 2, §7) — Teachers/Staff/EA awaiting evaluation, student
         submission progress, the Dean's own rating, and total pending. The
         old "Faculty/Staff Directory" cards are gone — Faculty/Staff record
         management is out of the Dean's scope now (see dean_evaluation.php,
         dean_evaluation_tracker.php). -->
    <div class="card-grid">
        <a href="dean_evaluation.php?tab=faculty" style="text-decoration:none;color:inherit;">
        <div class="stat-card"><i class="fa-solid fa-chalkboard-user"></i><div class="num"><?= $facultyPending ?></div><div class="label">Teachers Awaiting Evaluation</div></div>
        </a>
        <a href="dean_evaluation.php?tab=staff" style="text-decoration:none;color:inherit;">
        <div class="stat-card"><i class="fa-solid fa-id-badge"></i><div class="num"><?= $staffPending ?></div><div class="label">Staff Awaiting Evaluation</div></div>
        </a>
        <a href="dean_evaluation.php?tab=executive_assistant" style="text-decoration:none;color:inherit;">
        <div class="stat-card"><i class="fa-solid fa-user-tie"></i><div class="num"><?= $eaPending ?></div><div class="label">Executive Assistant Awaiting Evaluation</div></div>
        </a>
        <a href="dean_evaluation_tracker.php" style="text-decoration:none;color:inherit;">
        <div class="stat-card"><i class="fa-solid fa-user-graduate"></i><div class="num"><?= $studentParticipationPct ?>%</div><div class="label">Student Submission Progress</div></div>
        </a>
        <a href="dean_results.php" style="text-decoration:none;color:inherit;">
        <div class="stat-card"><i class="fa-solid fa-star"></i><div class="num"><?= $myRating !== null ? $myRating : '—' ?></div><div class="label">Your Evaluation Rating</div></div>
        </a>
        <div class="stat-card"><i class="fa-solid fa-hourglass-half"></i><div class="num"><?= $pendingEvaluationsTotal ?></div><div class="label">Pending Evaluations</div></div>
    </div>

    <?php if (empty($facultyList) && empty($staffList) && empty($eaList)): ?>
    <div class="stub-note">
        <i class="fa-solid fa-plug-circle-exclamation"></i>
        No approved &amp; active Teacher, Staff, or Executive Assistant accounts found for the current structure — check <code>account_status</code>/<code>is_active</code> and College year-level assignment in Manage Privileged Accounts.
    </div>
    <?php elseif ($facultyPending + $staffPending + $eaPending === count($facultyList) + count($staffList) + count($eaList)): ?>
    <div class="stub-note">
        <i class="fa-solid fa-clock-rotate-left"></i>
        Rosters are live, but Dean-evaluation status tracking isn't wired up yet — everyone shows as pending until the evaluation tracker's eval_type for Dean-initiated evaluations is confirmed (see REFACTOR NOTE at the top of this file).
    </div>
    <?php endif; ?>

    <!-- REPORTS — Higher Education evaluation analytics only. These types
         must match $validTypes in dean_reports.php exactly. -->
    <div class="section">
        <h2><i class="fa-solid fa-chart-line"></i> Reports &amp; Analytics</h2>
        <div class="report-btns">
            <a href="dean_reports.php?type=college_summary"><i class="fa-solid fa-file-lines"></i> <?= HIGHER_ED_LABEL ?> Summary</a>
            <a href="dean_reports.php?type=faculty_performance"><i class="fa-solid fa-chalkboard-user"></i> Teacher Performance Report</a>
            <a href="dean_reports.php?type=department_comparison"><i class="fa-solid fa-building-columns"></i> Department Comparison</a>
            <a href="dean_reports.php?type=program_analytics"><i class="fa-solid fa-book"></i> Program Analytics</a>
            <a href="dean_reports.php?type=accreditation_support"><i class="fa-solid fa-stamp"></i> Accreditation Support Report</a>
        </div>
    </div>

    <?php else: ?>
    <div class="section">
        <h2><i class="fa-solid fa-circle-info"></i> <?= HIGHER_ED_LABEL ?> Analytics</h2>
        <p class="empty-note">
            <?= HIGHER_ED_LABEL ?> analytics will resume automatically once the Executive Assistant sets the active
            Academic Structure back to College.
        </p>
    </div>
    <?php endif; ?>

    <!-- NOTIFICATIONS + QUICK ACTIONS -->
    <div class="two-col">
        <div class="section">
            <h2><i class="fa-solid fa-bell"></i> Notifications</h2>
            <ul class="notif-list">
                <?php foreach ($notifications as $n): ?>
                    <li><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($n) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="section">
            <h2><i class="fa-solid fa-bolt"></i> Quick Actions</h2>
            <div class="qa-btns">
                <a href="dean_evaluation.php"><i class="fa-solid fa-clipboard-check"></i> Go to Evaluation</a>
                <a href="dean_evaluation_tracker.php"><i class="fa-solid fa-satellite-dish"></i> Open Evaluation Tracker</a>
                <a href="dean_results.php"><i class="fa-solid fa-star-half-stroke"></i> View My Results</a>
                <a href="dean_reports.php"><i class="fa-solid fa-file-lines"></i> Generate Reports</a>
            </div>
        </div>
    </div>

</main>

<script>
(function(){
    const row = document.getElementById('countdownRow');
    if (!row) return;
    const end = new Date(row.dataset.end).getTime();
    function tick(){
        const now = Date.now();
        let diff = Math.max(0, end - now);
        const days = Math.floor(diff / 86400000); diff -= days * 86400000;
        const hours = Math.floor(diff / 3600000); diff -= hours * 3600000;
        const mins = Math.floor(diff / 60000);
        document.getElementById('cd-days').textContent = days;
        document.getElementById('cd-hours').textContent = hours;
        document.getElementById('cd-mins').textContent = mins;
    }
    tick();
    setInterval(tick, 30000);
})();
</script>
</body>
</html>
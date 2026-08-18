<?php
// admin/student_tracker.php
// ══════════════════════════════════════════════════════════════
// STUDENT EVALUATION TRACKER
//
// For each active student, shows how many of their REQUIRED
// faculty/staff they've evaluated this period, out of the total
// required — filterable by education level (Junior High / Senior
// High / College). "Required" = faculty/staff actually assigned to
// this student, using the SAME eligibility rule the student-facing
// Evaluate list and the submission gate (shared/eligibility.php's
// canStudentEvaluate()) use:
//   - teaching_assignments: education_level (normalized) + year_level
//   - user_year_levels: year_level only (how non-teaching staff --
//     Registrar, Cashier, Librarian, Nurse, etc. -- get scoped; they
//     never get a teaching_assignments row)
//
// This used to be computed from a `faculty_levels` table keyed only
// by education_level. That table is empty in this install and was
// never written to by anything else in the codebase -- it's a
// disconnected, orphaned data source, so "required" always came out
// 0 for every student regardless of what they actually had to do.
// It also filtered on role IN ('faculty','staff'), but 'faculty' is
// not a valid users.role value (the enum only has 'teacher','staff',
// ...) -- so even a populated faculty_levels table would have only
// ever counted staff, never teachers.
//
// The "done" count also referenced a `student_id` column that does
// not exist on evaluation_tracker (the real column is `evaluator_id`)
// and compared the current period's *id* against `period`, which is
// a free-text label column, not the `period_id` foreign key. Both
// together meant this query would either error out or silently match
// nothing, so "done" was never accurate either.
//
// This is the completion source the Certification of Ratings gate
// (certification.php) checks against — this page is the human-
// readable view of that same underlying data.
// ══════════════════════════════════════════════════════════════
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin','superadmin','executive_assistant'])) {
    header("Location: admin_login.php"); exit;
}
if ($_SESSION['role'] === 'admin') {
    require_once 'permissions.php';
    if (!admin_can_edit($mysqli, 'reports_analytics')) {
        die("You don't have access to this feature. Ask a Super Admin to enable it.");
    }
}
if ($_SESSION['role'] === 'executive_assistant') {
    $pres = $mysqli->query("SELECT admin_can_edit FROM admin_permissions WHERE feature_key='reports_analytics' LIMIT 1");
    $row  = $pres ? $pres->fetch_assoc() : null;
    if (!$row || !$row['admin_can_edit']) {
        die("You don't have access to this feature yet. Ask the System Admin to enable it.");
    }
}

$period    = $mysqli->query("SELECT id, period_label FROM evaluation_periods WHERE is_active=1 LIMIT 1")->fetch_assoc();
$period_id = $period['id'] ?? 0;

$levelFilter = $_GET['level'] ?? 'all';
if (!in_array($levelFilter, ['all','junior_high','senior_high','college'])) $levelFilter = 'all';
$levelLabels = ['junior_high'=>'Junior High School','senior_high'=>'Senior High School','college'=>'College'];

// education_level VOCABULARY MISMATCH: users.education_level stores slugs
// ('junior_high','senior_high','college'), but teaching_assignments.education_level
// stores admin-facing labels ('Basic Education','College'). Same normalization
// used in student_dashboard.php / eligibility.php's canStudentEvaluate() so a
// person counted as "required" here is exactly who the student can actually see.
$eduBucketMap = [
    'elementary'  => ['basic education'],
    'junior_high' => ['basic education'],
    'senior_high' => ['basic education'],
    'college'     => ['college', 'higher education', 'college / university', 'college/university'],
];

$whereLevel = '';
if ($levelFilter !== 'all') {
    $whereLevel = "AND education_level=?";
}

$students = [];
$sql = "
    SELECT id, full_name, photo, education_level, year_level
    FROM users
    WHERE role='student' AND is_active=1 $whereLevel
    ORDER BY education_level, full_name ASC
";
$stmt = $mysqli->prepare($sql);
if ($levelFilter !== 'all') {
    $stmt->bind_param("s", $levelFilter);
}
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Overall summary counts (independent of the current filter, always full picture)
$byLevel = [];
foreach (['junior_high','senior_high','college'] as $lvl) {
    $bstmt = $mysqli->prepare("SELECT COUNT(*) c FROM users WHERE role='student' AND is_active=1 AND education_level=?");
    $bstmt->bind_param("s", $lvl);
    $bstmt->execute();
    $byLevel[$lvl] = $bstmt->get_result()->fetch_assoc()['c'] ?? 0;
    $bstmt->close();
}

// ── Prepared statements reused per-student in the loop below ──
// Required: same OR-of-two-sources rule as the student-facing list.
$requiredStmt = $mysqli->prepare("
    SELECT COUNT(DISTINCT u.id) AS c
    FROM users u
    WHERE u.role IN ('teacher','staff')
      AND u.is_active = 1
      AND (
            u.id IN (
                SELECT ta.user_id
                FROM teaching_assignments ta
                WHERE LOWER(TRIM(ta.education_level)) IN (?,?,?,?)
                  AND LOWER(TRIM(ta.year_level)) = LOWER(TRIM(?))
            )
            OR
            u.id IN (
                SELECT uyl.user_id
                FROM user_year_levels uyl
                WHERE LOWER(TRIM(uyl.year_level)) = LOWER(TRIM(?))
            )
          )
");

// Done: evaluator_id (not student_id) + period_id (not the free-text period label).
$doneStmt = $mysqli->prepare("
    SELECT COUNT(DISTINCT target_user_id) AS c
    FROM evaluation_tracker
    WHERE evaluator_id = ? AND eval_type = 'student' AND period_id = ?
");
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"/>
<title>Student Evaluation Tracker — PBI Admin</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<style>
:root{--dark:#0A192F;--mid:#172A45;--inner:#0F1F3D;--accent:#2B6CB0;--light:#E0E6F0;--muted:#A0B3C6;--border:rgba(255,255,255,.08);}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--dark);color:var(--light);padding:28px;}
.back-btn{display:inline-flex;align-items:center;gap:8px;padding:9px 18px;background:var(--mid);border:1px solid var(--border);border-radius:8px;color:var(--light);font-size:13px;font-weight:600;text-decoration:none;margin-bottom:18px;transition:background .2s;}
.back-btn:hover{background:var(--accent);}
.page-title{font-family:'Rajdhani',sans-serif;font-size:26px;font-weight:700;margin-bottom:4px;}
.page-sub{font-size:13px;color:var(--muted);margin-bottom:24px;}
.summary-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:24px;}
.sum-card{background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:16px 18px;}
.sum-label{font-size:11px;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);margin-bottom:6px;}
.sum-value{font-size:24px;font-weight:700;color:#fff;}
.level-tabs{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;}
.level-tab{padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;border:1px solid var(--border);background:var(--mid);color:var(--muted);text-decoration:none;transition:all .2s;}
.level-tab:hover{color:var(--light);}
.level-tab.active{background:rgba(43,108,176,.2);border-color:rgba(43,108,176,.5);color:#93c5fd;}
.row{background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:14px 20px;display:flex;align-items:center;gap:16px;margin-bottom:10px;flex-wrap:wrap;}
.avatar{width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid var(--border);flex-shrink:0;}
.avatar-ph{width:40px;height:40px;border-radius:50%;background:var(--inner);display:flex;align-items:center;justify-content:center;color:var(--muted);flex-shrink:0;}
.info{flex:1;min-width:160px;}
.name{font-weight:700;font-size:14px;}
.lvl-pill{font-size:11px;color:var(--muted);display:inline-flex;align-items:center;gap:5px;padding:2px 9px;border-radius:20px;background:rgba(255,255,255,.05);border:1px solid var(--border);margin-top:3px;}
.bar-wrap{width:180px;}
.bar-bg{height:6px;background:rgba(255,255,255,.08);border-radius:3px;overflow:hidden;}
.bar-fill{height:100%;border-radius:3px;}
.pct{font-size:12px;color:var(--muted);margin-top:4px;}
.badge-complete{font-size:11px;font-weight:700;padding:4px 11px;border-radius:20px;background:rgba(74,222,128,.15);color:#4ade80;border:1px solid rgba(74,222,128,.3);}
.badge-incomplete{font-size:11px;font-weight:700;padding:4px 11px;border-radius:20px;background:rgba(248,113,113,.15);color:#f87171;border:1px solid rgba(248,113,113,.3);}
.no-data{text-align:center;padding:40px;color:var(--muted);background:var(--mid);border-radius:12px;border:1px solid var(--border);}
.no-period{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3);color:#f87171;padding:14px 18px;border-radius:10px;margin-bottom:20px;font-size:13px;}
</style></head><body>

<a href="admin_dashboard.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>

<div class="page-title">Student Evaluation Tracker</div>
<div class="page-sub"><?= $period ? 'Active period: '.htmlspecialchars($period['period_label']) : 'No active evaluation period — set one under Manage Periods.' ?></div>

<?php if (!$period_id): ?>
<div class="no-period"><i class="fa-solid fa-triangle-exclamation"></i> No evaluation period is currently active, so "done" counts below cannot be tied to a period. Activate a period under Manage Periods to get accurate tracking.</div>
<?php endif; ?>

<div class="summary-row">
    <div class="sum-card"><div class="sum-label">Junior High</div><div class="sum-value"><?= $byLevel['junior_high'] ?></div></div>
    <div class="sum-card"><div class="sum-label">Senior High</div><div class="sum-value"><?= $byLevel['senior_high'] ?></div></div>
    <div class="sum-card"><div class="sum-label">College</div><div class="sum-value"><?= $byLevel['college'] ?></div></div>
</div>

<div class="level-tabs">
    <a href="?level=all" class="level-tab <?= $levelFilter==='all'?'active':'' ?>">All Levels</a>
    <a href="?level=junior_high" class="level-tab <?= $levelFilter==='junior_high'?'active':'' ?>">Junior High School</a>
    <a href="?level=senior_high" class="level-tab <?= $levelFilter==='senior_high'?'active':'' ?>">Senior High School</a>
    <a href="?level=college" class="level-tab <?= $levelFilter==='college'?'active':'' ?>">College</a>
</div>

<?php if (empty($students)): ?>
<div class="no-data">No active students found for this filter.</div>
<?php else: foreach ($students as $s):

    $levelKey      = strtolower(trim($s['education_level'] ?? ''));
    $levelVariants = $eduBucketMap[$levelKey] ?? [$levelKey];
    // Pad/truncate to exactly 4 variants so the 4-placeholder prepared
    // statement above always gets the right number of bound params.
    while (count($levelVariants) < 4) $levelVariants[] = $levelVariants[0] ?? '';
    $levelVariants = array_slice($levelVariants, 0, 4);

    $studentYear = $s['year_level'] ?? '';

    $required = 0;
    if ($levelKey !== '' && $studentYear !== '') {
        $requiredStmt->bind_param(
            "ssssss",
            $levelVariants[0], $levelVariants[1], $levelVariants[2], $levelVariants[3],
            $studentYear, $studentYear
        );
        $requiredStmt->execute();
        $required = (int)($requiredStmt->get_result()->fetch_assoc()['c'] ?? 0);
    }

    $done = 0;
    if ($period_id) {
        $doneStmt->bind_param("ii", $s['id'], $period_id);
        $doneStmt->execute();
        $done = (int)($doneStmt->get_result()->fetch_assoc()['c'] ?? 0);
    }

    $pct      = $required > 0 ? round(($done/$required)*100) : 0;
    $complete = ($required > 0 && $done >= $required);
?>
<div class="row">
    <?php if ($s['photo']): ?><img class="avatar" src="../image/<?= htmlspecialchars($s['photo']) ?>" alt=""/>
    <?php else: ?><div class="avatar-ph"><i class="fa-solid fa-user"></i></div><?php endif; ?>
    <div class="info">
        <div class="name"><?= htmlspecialchars($s['full_name']) ?></div>
        <span class="lvl-pill"><?= htmlspecialchars($levelLabels[$s['education_level']] ?? $s['education_level']) ?><?= $studentYear ? ' · '.htmlspecialchars($studentYear) : '' ?></span>
    </div>
    <div class="bar-wrap">
        <div class="bar-bg"><div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $complete?'#4ade80':'#F59E0B' ?>"></div></div>
        <div class="pct"><?= $done ?> of <?= $required ?> evaluated (<?= $pct ?>%)</div>
    </div>
    <?php if ($complete): ?>
    <span class="badge-complete"><i class="fa-solid fa-circle-check"></i> Complete</span>
    <?php elseif ($required > 0 && $period_id): ?>
    <span class="badge-incomplete"><i class="fa-solid fa-circle-exclamation"></i> <?= $required - $done ?> remaining</span>
    <?php endif; ?>
</div>
<?php endforeach; endif; ?>

</body></html>
<?php
$requiredStmt->close();
$doneStmt->close();
$mysqli->close();
?>
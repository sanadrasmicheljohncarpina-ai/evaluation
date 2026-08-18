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

// ── AUTH GUARD ────────────────────────────────────────────
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'principal') {
    header("Location: principal_login.php");
    exit;
}

// ── SELF-HEALING SCHEMA (matches your ALTER-TABLE-on-load pattern) ─────
@$mysqli->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS academic_level VARCHAR(20) NULL");
@$mysqli->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS grade_level VARCHAR(10) NULL");
@$mysqli->query("
    CREATE TABLE IF NOT EXISTS user_year_levels (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        year_level VARCHAR(10) NOT NULL,
        UNIQUE KEY uniq_user_year (user_id, year_level)
    )
");

// ── SAFE QUERY HELPERS (never fatal-error the page on a schema mismatch) ─
// IMPORTANT: since PHP 8.1, mysqli defaults to exception mode
// (MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT). A bad prepare()/execute()
// (unknown column, bad table, etc.) THROWS mysqli_sql_exception instead of
// returning false — the `@` operator only suppresses warnings/notices, not
// exceptions, so it does nothing here on its own. The try/catch is what
// actually makes this helper degrade to null/"N/A" instead of a fatal error.
function safe_scalar(mysqli $mysqli, string $sql, string $types = '', array $params = []) {
    try {
        $stmt = @$mysqli->prepare($sql);
        if (!$stmt) return null;
        if ($types !== '') { $stmt->bind_param($types, ...$params); }
        if (!@$stmt->execute()) { $stmt->close(); return null; }
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row ? reset($row) : null;
    } catch (mysqli_sql_exception $e) {
        // Schema mismatch (unknown column/table, etc.) — degrade gracefully.
        return null;
    }
}
function esc_list(mysqli $mysqli, array $vals): string {
    if (empty($vals)) return "''";
    return "'" . implode("','", array_map([$mysqli, 'real_escape_string'], $vals)) . "'";
}

// ── PULL PRINCIPAL PROFILE ────────────────────────────────
$stmt = $mysqli->prepare("SELECT full_name, username, email, designation, photo, education_level FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── SCOPE (Principal must never see College) ────────────────
$myLevel = $me['education_level'] ?? 'both';
if ($myLevel === 'junior_high') {
    $scopeAcademicLevels = ['junior_high'];
    $scopeGrades = ['7', '8', '9', '10'];
} elseif ($myLevel === 'senior_high') {
    $scopeAcademicLevels = ['senior_high'];
    $scopeGrades = ['11', '12'];
} else {
    $scopeAcademicLevels = ['junior_high', 'senior_high'];
    $scopeGrades = ['7', '8', '9', '10', '11', '12'];
}
$scopeAcademicIn = esc_list($mysqli, $scopeAcademicLevels);
$scopeGradesIn   = esc_list($mysqli, $scopeGrades);

// ── GLOBAL SYSTEM SETTINGS (single source of truth) ─────────────────────
// Everything about "what period is it, what's the status, what's the
// schedule" comes from here. The Principal dashboard never derives or
// stores any of this itself, and never hardcodes an academic year/term —
// same convention as dean_dashboard.php (Step 14: Synchronization with
// System Settings).
$settings = get_system_settings($mysqli);

// A Principal oversees the Basic Education (Junior High / Senior High)
// division. If the Executive Assistant/Admin has the active Academic
// Structure set to College, the Principal must not show Basic Ed
// analytics as current.
$structureActive = ($settings['academic_structure'] !== 'college');
$period_id_int   = $settings['period_id'] ?? 0;
$evalOpen        = $settings['is_open_for_submission'];
$hasPeriod       = $period_id_int > 0;

// Step 8 (mirrored from Dean): internal value stays whatever admin uses;
// this is the only display label used in markup below.
const BASIC_ED_LABEL = 'Basic Education';

$daysRemaining = null;
if ($settings['eval_end']) {
    $diff = (strtotime($settings['eval_end']) - strtotime(date('Y-m-d')));
    $daysRemaining = (int)ceil($diff / 86400);
}

// ── HEADLINE STATS ─────────────────────────────────────────
$teacherCount = $staffCount = $studentCount = 0;
$studentsSubmitted = $targetsEvaluated = $staffEvaluated = 0;
$totalTargets = $evaluationCompletion = $pendingEvaluations = 0;
$studentParticipation = $staffCompletion = $staffPending = 0;
$reportsGenerated = 0; // no report-generation table yet — placeholder, matches original pattern
$gradeStats = [];
$teacherRows = [];
$topTeachers = $attentionTeachers = $pendingTeachers = [];

if ($structureActive) {
    $teacherCount = (int)(safe_scalar($mysqli, "
        SELECT COUNT(*) c FROM users
        WHERE role='teacher' AND is_active=1 AND account_status='approved'
          AND academic_level IN ($scopeAcademicIn)
    ") ?? 0);

    $staffCount = (int)(safe_scalar($mysqli, "
        SELECT COUNT(*) c FROM users
        WHERE role='staff' AND is_active=1 AND account_status='approved'
          AND academic_level IN ($scopeAcademicIn)
    ") ?? 0);

    $studentCount = (int)(safe_scalar($mysqli, "
        SELECT COUNT(*) c FROM users
        WHERE role='student' AND is_active=1 AND account_status='approved'
          AND grade_level IN ($scopeGradesIn)
    ") ?? 0);

    if ($hasPeriod) {
        $studentsSubmitted = (int)(safe_scalar($mysqli, "
            SELECT COUNT(DISTINCT et.evaluator_id) c
            FROM evaluation_tracker et
            INNER JOIN users u ON u.id = et.evaluator_id
            WHERE et.eval_type='student' AND et.period_id=?
              AND u.role='student' AND u.grade_level IN ($scopeGradesIn)
        ", "i", [$period_id_int]) ?? 0);

        $targetsEvaluated = (int)(safe_scalar($mysqli, "
            SELECT COUNT(DISTINCT et.target_user_id) c
            FROM evaluation_tracker et
            INNER JOIN users u ON u.id = et.target_user_id
            WHERE et.eval_type='student' AND et.period_id=?
              AND u.role IN ('teacher','staff') AND u.academic_level IN ($scopeAcademicIn)
        ", "i", [$period_id_int]) ?? 0);

        $staffEvaluated = (int)(safe_scalar($mysqli, "
            SELECT COUNT(DISTINCT et.target_user_id) c
            FROM evaluation_tracker et
            INNER JOIN users u ON u.id = et.target_user_id
            WHERE et.eval_type='student' AND et.period_id=?
              AND u.role='staff' AND u.academic_level IN ($scopeAcademicIn)
        ", "i", [$period_id_int]) ?? 0);
    }

    $totalTargets          = $teacherCount + $staffCount;
    $evaluationCompletion  = $totalTargets > 0 ? round($targetsEvaluated / $totalTargets * 100) : 0;
    $pendingEvaluations    = max(0, $totalTargets - $targetsEvaluated);
    $studentParticipation  = $studentCount > 0 ? round($studentsSubmitted / $studentCount * 100) : 0;
    $staffCompletion       = $staffCount > 0 ? round($staffEvaluated / $staffCount * 100) : 0;
    $staffPending          = max(0, $staffCount - $staffEvaluated);

    // ── GRADE-LEVEL ANALYTICS ───────────────────────────────────
    foreach ($scopeGrades as $grade) {
        $gradeStudents = (int)(safe_scalar($mysqli, "
            SELECT COUNT(*) c FROM users
            WHERE role='student' AND is_active=1 AND account_status='approved' AND grade_level=?
        ", "s", [$grade]) ?? 0);

        $gradeSubmitted = 0;
        $gradeTeachers = 0;
        $gradeTeachersEvaluated = 0;
        $gradeAvgPerformance = null;

        if ($hasPeriod) {
            $gradeSubmitted = (int)(safe_scalar($mysqli, "
                SELECT COUNT(DISTINCT et.evaluator_id) c
                FROM evaluation_tracker et
                INNER JOIN users u ON u.id = et.evaluator_id
                WHERE et.eval_type='student' AND et.period_id=?
                  AND u.role='student' AND u.grade_level=?
            ", "is", [$period_id_int, $grade]) ?? 0);
        }

        $gradeTeachers = (int)(safe_scalar($mysqli, "
            SELECT COUNT(DISTINCT uyl.user_id) c
            FROM user_year_levels uyl
            INNER JOIN users u ON u.id = uyl.user_id
            WHERE uyl.year_level=? AND u.role='teacher' AND u.is_active=1 AND u.account_status='approved'
        ", "s", [$grade]) ?? 0);

        if ($hasPeriod && $gradeTeachers > 0) {
            $gradeTeachersEvaluated = (int)(safe_scalar($mysqli, "
                SELECT COUNT(DISTINCT et.target_user_id) c
                FROM evaluation_tracker et
                INNER JOIN user_year_levels uyl ON uyl.user_id = et.target_user_id
                WHERE et.eval_type='student' AND et.period_id=? AND uyl.year_level=?
            ", "is", [$period_id_int, $grade]) ?? 0);
        }

        // Average rating for this grade. Confirmed via SHOW COLUMNS: the real
        // column is answer_score (decimal(5,2), nullable) — answer_value never
        // existed.
        $gradeAvgPerformance = safe_scalar($mysqli, "
            SELECT AVG(qa.answer_score) v
            FROM questionnaire_answers qa
            INNER JOIN evaluation_tracker et ON et.id = qa.tracker_id
            INNER JOIN users u ON u.id = et.evaluator_id
            WHERE et.eval_type='student' AND et.period_id=? AND u.grade_level=?
        ", "is", [$period_id_int, $grade]);

        $gradeStats[] = [
            'grade'              => $grade,
            'completion'         => $gradeTeachers > 0 ? round($gradeTeachersEvaluated / $gradeTeachers * 100) : 0,
            'participation'      => $gradeStudents > 0 ? round($gradeSubmitted / $gradeStudents * 100) : 0,
            'submission_progress'=> $gradeStudents > 0 ? round($gradeSubmitted / $gradeStudents * 100) : 0,
            'avg_performance'    => $gradeAvgPerformance !== null ? round((float)$gradeAvgPerformance, 2) : null,
            'students'           => $gradeStudents,
            'submitted'          => $gradeSubmitted,
        ];
    }

    // ── TEACHER OVERVIEW ─────────────────────────────────────────
    $tq = $mysqli->prepare("
        SELECT id, full_name, photo FROM users
        WHERE role='teacher' AND is_active=1 AND account_status='approved'
          AND academic_level IN ($scopeAcademicIn)
    ");
    if ($tq) {
        $tq->execute();
        $tres = $tq->get_result();
        while ($row = $tres->fetch_assoc()) {
            $tid = (int)$row['id'];
            $completed = $hasPeriod ? (int)(safe_scalar($mysqli, "
                SELECT COUNT(DISTINCT evaluator_id) c FROM evaluation_tracker
                WHERE eval_type='student' AND period_id=? AND target_user_id=?
            ", "ii", [$period_id_int, $tid]) ?? 0) : 0;

            $avgRating = safe_scalar($mysqli, "
                SELECT AVG(qa.answer_score) v
                FROM questionnaire_answers qa
                INNER JOIN evaluation_tracker et ON et.id = qa.tracker_id
                WHERE et.eval_type='student' AND et.target_user_id=?" . ($hasPeriod ? " AND et.period_id=?" : ""),
                $hasPeriod ? "ii" : "i",
                $hasPeriod ? [$tid, $period_id_int] : [$tid]
            );

            $teacherRows[] = [
                'id'        => $tid,
                'name'      => $row['full_name'],
                'completed' => $completed,
                'avg'       => $avgRating !== null ? round((float)$avgRating, 2) : null,
            ];
        }
        $tq->close();
    }
    usort($teacherRows, fn($a, $b) => ($b['avg'] ?? -1) <=> ($a['avg'] ?? -1));
    $topTeachers       = array_slice(array_filter($teacherRows, fn($t) => $t['avg'] !== null), 0, 5);
    $attentionTeachers = array_slice(array_filter(array_reverse($teacherRows), fn($t) => $t['avg'] !== null && $t['avg'] < 3.5), 0, 5);
    $pendingTeachers   = array_slice(array_filter($teacherRows, fn($t) => $t['completed'] === 0), 0, 8);
}

$mysqli->close();
$photo_src = !empty($me['photo']) ? '../image/' . $me['photo'] : '../image/pbi_logo';

// ── NOTIFICATIONS ─────────────────────────────────────────────────────
// Generic schedule-driven notices come straight from the shared service;
// Basic Ed–specific ones (grade completion, non-submitters) are layered
// on top, same pattern as dean_dashboard.php.
$notifications = $settings['notifications'];
if ($structureActive) {
    if ($hasPeriod && $daysRemaining !== null && $daysRemaining >= 0 && $daysRemaining <= 3) {
        $notifications[] = "Evaluation closes in {$daysRemaining} day" . ($daysRemaining === 1 ? '' : 's') . ".";
    }
    foreach ($gradeStats as $g) {
        if ($g['students'] > 0 && $g['completion'] < 70) {
            $notifications[] = "Grade {$g['grade']} completion is below target ({$g['completion']}%).";
        }
    }
    $notSubmitted = $studentCount - $studentsSubmitted;
    if ($hasPeriod && $notSubmitted > 0) {
        $notifications[] = "{$notSubmitted} student" . ($notSubmitted === 1 ? '' : 's') . " have not submitted.";
    }
    if ($totalTargets > 0 && $evaluationCompletion === 100) {
        $notifications[] = "Teacher and staff evaluations are complete.";
    }
} else {
    $notifications[] = BASIC_ED_LABEL . " is not the active academic structure right now.";
}
if (empty($notifications)) {
    $notifications[] = "No urgent items right now.";
}

$scopeLabel = $myLevel === 'both' ? 'Junior High & Senior High' : ($myLevel === 'junior_high' ? 'Junior High School' : 'Senior High School');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI — Principal Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
:root{--dark:#0A192F;--mid:#172A45;--inner:#0F1F3D;--amber:#d99a2b;--amber-h:#f0b84d;--amber-dark:#b8801f;--light:#E0E6F0;--muted:#A0B3C6;--radius:10px;--shadow:0 8px 32px rgba(0,0,0,0.45);--danger:#f05454;--good:#10B981;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{min-height:100vh;background:var(--dark);font-family:'DM Sans',sans-serif;color:var(--light);display:flex;}

/* SIDEBAR */
.sidebar{width:250px;flex-shrink:0;background:rgba(23,42,69,.9);border-right:1px solid rgba(255,255,255,.08);min-height:100vh;padding:28px 20px;display:flex;flex-direction:column;}
.sb-profile{text-align:center;margin-bottom:26px;}
.sb-photo{width:72px;height:72px;border-radius:50%;object-fit:cover;border:2.5px solid var(--amber);box-shadow:0 0 18px rgba(217,154,43,.4);margin:0 auto 10px;display:block;}
.sb-name{font-weight:700;font-size:15px;color:#fff;}
.sb-role{font-size:11px;color:var(--amber-h);text-transform:uppercase;letter-spacing:.6px;margin-top:2px;}
.sb-scope{font-size:10px;color:var(--muted);margin-top:4px;}
.sb-nav{display:flex;flex-direction:column;gap:4px;margin-top:10px;}
.sb-nav a{display:flex;align-items:center;gap:10px;padding:11px 14px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:14px;font-weight:500;transition:background .2s,color .2s;}
.sb-nav a:hover,.sb-nav a.active{background:rgba(217,154,43,.15);color:#fff;}
.sb-nav a i{width:18px;text-align:center;color:var(--amber-h);}
.sb-logout{margin-top:auto;}
.sb-logout a{display:flex;align-items:center;gap:10px;padding:11px 14px;border-radius:8px;color:#fca5a5;text-decoration:none;font-size:14px;font-weight:500;transition:background .2s;}
.sb-logout a:hover{background:rgba(240,84,84,.12);}

/* MAIN */
.main{flex:1;padding:36px 44px;}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:28px;flex-wrap:wrap;gap:14px;}
.page-title{font-family:'Rajdhani',sans-serif;font-size:28px;font-weight:700;color:#fff;letter-spacing:1px;}
.page-sub{font-size:13px;color:var(--muted);margin-top:4px;}
.card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:30px;}
.stat-card{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:20px;box-shadow:var(--shadow);}
.stat-card i{color:var(--amber-h);font-size:20px;margin-bottom:10px;}
.stat-card .num{font-size:26px;font-weight:700;color:#fff;}
.stat-card .label{font-size:12px;color:var(--muted);margin-top:4px;}

.section{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:24px;box-shadow:var(--shadow);margin-bottom:26px;}
.section h2{font-family:'Rajdhani',sans-serif;font-size:19px;color:#fff;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.section h2 i{color:var(--amber-h);font-size:16px;}

table.data{width:100%;border-collapse:collapse;font-size:13px;}
table.data th{text-align:left;color:var(--muted);font-weight:600;padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.08);text-transform:uppercase;font-size:11px;letter-spacing:.4px;}
table.data td{padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.05);}
table.data tr:last-child td{border-bottom:none;}
.bar-wrap{background:rgba(255,255,255,.08);border-radius:6px;height:8px;width:100%;overflow:hidden;}
.bar-fill{height:100%;background:linear-gradient(90deg,var(--amber-dark),var(--amber-h));border-radius:6px;}
.pill{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;}
.pill.good{background:rgba(16,185,129,.14);color:var(--good);}
.pill.warn{background:rgba(217,154,43,.14);color:var(--amber-h);}
.pill.bad{background:rgba(240,84,84,.12);color:#fca5a5;}

.two-col{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.mini-list{list-style:none;font-size:13px;}
.mini-list li{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.05);}
.mini-list li:last-child{border-bottom:none;}
.mini-list .name{color:var(--light);}
.mini-list .val{color:var(--amber-h);font-weight:600;}

.tracker-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;text-align:center;}
.tracker-item .big{font-size:22px;font-weight:700;color:#fff;}
.tracker-item .lbl{font-size:11px;color:var(--muted);margin-top:4px;text-transform:uppercase;letter-spacing:.4px;}

.report-btns{display:flex;flex-wrap:wrap;gap:10px;}
.report-btns a, .report-btns button, .qa-btns a{background:rgba(217,154,43,.12);border:1px solid rgba(217,154,43,.35);color:var(--amber-h);padding:10px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:7px;transition:background .2s;}
.report-btns a:hover, .report-btns button:hover, .qa-btns a:hover{background:rgba(217,154,43,.22);}

.notif-list{list-style:none;font-size:13px;}
.notif-list li{padding:10px 12px;border-radius:8px;background:rgba(255,255,255,.03);margin-bottom:8px;display:flex;align-items:center;gap:10px;}
.notif-list li i{color:var(--amber-h);}
.notif-list li:last-child{margin-bottom:0;}

.qa-btns{display:flex;flex-wrap:wrap;gap:10px;}

.period-badge{background:rgba(217,154,43,.14);border:1px solid rgba(217,154,43,.3);color:var(--amber-h);padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;display:flex;align-items:center;gap:7px;}
.period-badge.closed{background:rgba(240,84,84,.1);border-color:rgba(240,84,84,.3);color:#fca5a5;}
.period-badge.amber{background:rgba(217,154,43,.14);border-color:rgba(217,154,43,.3);color:var(--amber-h);}
.period-badge.gray{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.12);color:var(--muted);}
.empty-note{color:var(--muted);font-size:13px;font-style:italic;}

.period-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin-bottom:16px;}
.period-field .period-field-label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:5px;}
.period-field .period-field-value{font-size:16px;font-weight:700;color:#fff;}
.period-message{font-size:13px;color:var(--muted);padding-top:14px;border-top:1px solid rgba(255,255,255,.06);}
.period-message strong{color:var(--light);}

.structure-note{display:flex;align-items:flex-start;gap:14px;padding:18px 20px;background:rgba(217,154,43,.08);border:1px solid rgba(217,154,43,.25);border-radius:12px;margin-bottom:26px;}
.structure-note i{color:var(--amber-h);font-size:20px;margin-top:2px;}
.structure-note p{font-size:13px;color:var(--light);line-height:1.6;}
.structure-note p b{color:#fff;}

.countdown-row{display:flex;gap:14px;margin-top:14px;}
.countdown-box{flex:1;background:rgba(10,25,47,.5);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:12px;text-align:center;}
.countdown-box .num{font-size:22px;font-weight:700;color:#fff;}
.countdown-box .lbl{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-top:2px;}

.stub-note{font-size:11px;color:var(--amber-h);background:rgba(217,154,43,.08);border:1px dashed rgba(217,154,43,.35);border-radius:8px;padding:8px 12px;margin-top:10px;}

@media(max-width:900px){.two-col{grid-template-columns:1fr;}}
@media(max-width:768px){body{flex-direction:column;}.sidebar{width:100%;min-height:auto;}}
</style>
</head>
<body>

<aside class="sidebar">
    <div class="sb-profile">
        <img class="sb-photo" src="<?= htmlspecialchars($photo_src) ?>" alt="Profile"/>
        <div class="sb-name"><?= htmlspecialchars($me['full_name'] ?? 'Principal') ?></div>
        <div class="sb-role"><?= htmlspecialchars($me['designation'] ?? 'Principal') ?></div>
        <div class="sb-scope"><?= htmlspecialchars($scopeLabel) ?></div>
    </div>
    <nav class="sb-nav">
        <a href="principal_dashboard.php" class="active"><i class="fa-solid fa-gauge"></i> Dashboard</a>
        <a href="principal_evaluations.php"><i class="fa-solid fa-clipboard-list"></i> Evaluation</a>
        <a href="principal_evaluation_tracker.php"><i class="fa-solid fa-satellite-dish"></i> Evaluation Tracker</a>
        <a href="principal_reports.php"><i class="fa-solid fa-chart-line"></i> Reports</a>
        <a href="principal_account_settings.php"><i class="fa-solid fa-gear"></i> Account Settings</a>
    </nav>
    <div class="sb-logout">
        <a href="principal_logout.php"><i class="fa-solid fa-right-from-bracket"></i> Log Out</a>
    </div>
</aside>

<main class="main">
    <div class="page-header">
        <div>
            <div class="page-title">Welcome, <?= htmlspecialchars(explode(',', $me['full_name'] ?? 'Principal')[0]) ?></div>
            <div class="page-sub">Pandan Bay Institute — <?= htmlspecialchars($scopeLabel) ?> Oversight</div>
        </div>
        <div class="period-badge <?= htmlspecialchars($settings['status']['cls']) ?>">
            <i class="fa-solid fa-calendar-check"></i>
            <?= htmlspecialchars($settings['academic_year']) ?> · <?= htmlspecialchars($settings['academic_structure_label']) ?> · <?= htmlspecialchars($settings['academic_term']) ?>
            — <?= htmlspecialchars($settings['status']['label']) ?>
        </div>
    </div>

    <?php if (!$structureActive): ?>
    <div class="structure-note">
        <i class="fa-solid fa-circle-info"></i>
        <p>
            <b><?= BASIC_ED_LABEL ?> evaluations are currently inactive.</b><br>
            The current evaluation period is configured for <b><?= htmlspecialchars($settings['academic_structure_label']) ?></b>.
            Principal evaluation tracking will become available when the evaluation period is switched to <?= BASIC_ED_LABEL ?>.
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
                <div class="period-field-value"><?= BASIC_ED_LABEL ?></div>
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

    <!-- STATS -->
    <div class="card-grid">
        <a href="principal_evaluations.php?bucket=Teacher" style="text-decoration:none;color:inherit;">
        <div class="stat-card"><i class="fa-solid fa-chalkboard-user"></i><div class="num"><?= $teacherCount ?></div><div class="label">Teachers</div></div>
        </a>
        <a href="principal_evaluations.php?bucket=Staff" style="text-decoration:none;color:inherit;">
        <div class="stat-card"><i class="fa-solid fa-users"></i><div class="num"><?= $staffCount ?></div><div class="label">School Staff</div></div>
        </a>
        <div class="stat-card"><i class="fa-solid fa-user-graduate"></i><div class="num"><?= $studentParticipation ?>%</div><div class="label">Student Participation</div></div>
        <a href="principal_evaluation_tracker.php" style="text-decoration:none;color:inherit;">
        <div class="stat-card"><i class="fa-solid fa-clipboard-check"></i><div class="num"><?= $evaluationCompletion ?>%</div><div class="label">Evaluation Completion</div></div>
        </a>
        <a href="principal_evaluations.php" style="text-decoration:none;color:inherit;">
        <div class="stat-card"><i class="fa-solid fa-hourglass-half"></i><div class="num"><?= $pendingEvaluations ?></div><div class="label">Pending Evaluations</div></div>
        </a>
        <div class="stat-card"><i class="fa-solid fa-file-lines"></i><div class="num"><?= $reportsGenerated ?></div><div class="label">Reports Generated</div></div>
    </div>

    <?php if ($teacherCount === 0 && $staffCount === 0): ?>
    <div class="stub-note">
        <i class="fa-solid fa-plug-circle-exclamation"></i>
        No approved &amp; active Teacher or Staff accounts found for your scope — check <code>account_status</code>/<code>is_active</code> and grade-level/year-level assignment in Manage Privileged Accounts.
    </div>
    <?php elseif (!$hasPeriod): ?>
    <div class="stub-note">
        <i class="fa-solid fa-clock-rotate-left"></i>
        Rosters are live, but there's no active evaluation period from System &amp; Period settings yet — completion and participation figures stay at 0% until one is opened.
    </div>
    <?php endif; ?>

    <!-- GRADE-LEVEL ANALYTICS -->
    <div class="section">
        <h2><i class="fa-solid fa-layer-group"></i> Grade-Level Analytics</h2>
        <table class="data">
            <thead><tr><th>Grade</th><th>Evaluation Completion</th><th>Participation Rate</th><th>Submission Progress</th><th>Average Performance</th></tr></thead>
            <tbody>
            <?php foreach ($gradeStats as $g): ?>
                <tr>
                    <td>Grade <?= htmlspecialchars($g['grade']) ?></td>
                    <td style="min-width:140px;">
                        <div class="bar-wrap"><div class="bar-fill" style="width:<?= $g['completion'] ?>%"></div></div>
                        <span style="font-size:11px;color:var(--muted);"><?= $g['completion'] ?>%</span>
                    </td>
                    <td><?= $g['participation'] ?>%</td>
                    <td><?= $g['submitted'] ?> / <?= $g['students'] ?> students</td>
                    <td><?= $g['avg_performance'] !== null ? $g['avg_performance'] : '<span class="empty-note">N/A</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- TEACHER OVERVIEW -->
    <div class="section">
        <h2><i class="fa-solid fa-chalkboard-user"></i> Teacher Overview</h2>
        <div class="two-col">
            <div>
                <h3 style="font-size:13px;color:var(--muted);margin-bottom:10px;text-transform:uppercase;letter-spacing:.4px;">Top Performing Teachers</h3>
                <ul class="mini-list">
                    <?php if (empty($topTeachers)): ?>
                        <li class="empty-note">No rating data available yet.</li>
                    <?php else: foreach ($topTeachers as $t): ?>
                        <li><span class="name"><?= htmlspecialchars($t['name']) ?></span><span class="val"><?= $t['avg'] ?></span></li>
                    <?php endforeach; endif; ?>
                </ul>
                <h3 style="font-size:13px;color:var(--muted);margin:18px 0 10px;text-transform:uppercase;letter-spacing:.4px;">Teachers Requiring Attention</h3>
                <ul class="mini-list">
                    <?php if (empty($attentionTeachers)): ?>
                        <li class="empty-note">None below threshold.</li>
                    <?php else: foreach ($attentionTeachers as $t): ?>
                        <li><span class="name"><?= htmlspecialchars($t['name']) ?></span><span class="val" style="color:#fca5a5;"><?= $t['avg'] ?></span></li>
                    <?php endforeach; endif; ?>
                </ul>
            </div>
            <div>
                <h3 style="font-size:13px;color:var(--muted);margin-bottom:10px;text-transform:uppercase;letter-spacing:.4px;">Teachers with Pending Evaluations</h3>
                <ul class="mini-list">
                    <?php if (empty($pendingTeachers)): ?>
                        <li class="empty-note">Everyone has at least one evaluation submitted.</li>
                    <?php else: foreach ($pendingTeachers as $t): ?>
                        <li><span class="name"><?= htmlspecialchars($t['name']) ?></span><span class="pill warn">Pending</span></li>
                    <?php endforeach; endif; ?>
                </ul>
                <h3 style="font-size:13px;color:var(--muted);margin:18px 0 10px;text-transform:uppercase;letter-spacing:.4px;">Overall Teacher Completion</h3>
                <div class="bar-wrap"><div class="bar-fill" style="width:<?= $evaluationCompletion ?>%"></div></div>
                <span style="font-size:12px;color:var(--muted);"><?= $evaluationCompletion ?>% of <?= $teacherCount ?> teachers evaluated</span>
            </div>
        </div>
    </div>

    <!-- STAFF OVERVIEW -->
    <div class="section">
        <h2><i class="fa-solid fa-users"></i> School Staff Overview</h2>
        <div class="tracker-grid">
            <div class="tracker-item"><div class="big"><?= $staffCompletion ?>%</div><div class="lbl">Staff Evaluation Completion</div></div>
            <div class="tracker-item"><div class="big"><?= $staffCount ?></div><div class="lbl">Staff Under Review</div></div>
            <div class="tracker-item"><div class="big"><?= $staffPending ?></div><div class="lbl">Pending Evaluations</div></div>
        </div>
    </div>

    <!-- EVALUATION TRACKER -->
    <div class="section" id="tracker">
        <h2 style="justify-content:space-between;">
            <span><i class="fa-solid fa-satellite-dish"></i> Evaluation Tracker — Live Monitoring</span>
            <a href="principal_evaluation_tracker.php" style="font-size:12px;font-weight:600;color:var(--amber-h);text-decoration:none;">Open full tracker <i class="fa-solid fa-arrow-right"></i></a>
        </h2>
        <?php if ($hasPeriod): ?>
        <div class="tracker-grid">
            <div class="tracker-item"><div class="big"><?= $daysRemaining !== null ? $daysRemaining : '—' ?></div><div class="lbl">Days Remaining</div></div>
            <div class="tracker-item"><div class="big"><?= $evaluationCompletion ?>%</div><div class="lbl">Teachers Completed</div></div>
            <div class="tracker-item"><div class="big"><?= $studentParticipation ?>%</div><div class="lbl">Students Submitted</div></div>
            <div class="tracker-item"><div class="big"><?= $staffCompletion ?>%</div><div class="lbl">Staff Completed</div></div>
        </div>
        <?php else: ?>
        <p class="empty-note">No active evaluation period right now.</p>
        <?php endif; ?>
    </div>

    <!-- REPORTS -->
    <div class="section">
        <h2><i class="fa-solid fa-chart-line"></i> Reports &amp; Analytics</h2>
        <div class="report-btns">
            <a href="principal_reports.php?type=teacher_performance"><i class="fa-solid fa-file-lines"></i> Teacher Performance Summary</a>
            <a href="principal_reports.php?type=grade_comparison"><i class="fa-solid fa-scale-balanced"></i> Grade-Level Comparison</a>
            <a href="principal_reports.php?type=school_summary"><i class="fa-solid fa-school"></i> School Evaluation Summary</a>
            <a href="principal_reports.php?type=student_participation"><i class="fa-solid fa-user-graduate"></i> Student Participation Report</a>
            <a href="principal_reports.php?type=staff_performance"><i class="fa-solid fa-users"></i> Staff Performance Report</a>
            <a href="principal_reports.php?type=completion"><i class="fa-solid fa-clipboard-check"></i> Evaluation Completion Report</a>
        </div>
    </div>

    <?php else: ?>
    <div class="section">
        <h2><i class="fa-solid fa-circle-info"></i> <?= BASIC_ED_LABEL ?> Analytics</h2>
        <p class="empty-note">
            <?= BASIC_ED_LABEL ?> analytics will resume automatically once the Executive Assistant sets the active
            Academic Structure back to Basic Education.
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
                <a href="principal_reports.php"><i class="fa-solid fa-file-lines"></i> View Reports</a>
                <a href="principal_evaluation_tracker.php"><i class="fa-solid fa-gauge-high"></i> Monitor Progress</a>
                <a href="principal_evaluations.php"><i class="fa-solid fa-satellite-dish"></i> Open Evaluation</a>
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
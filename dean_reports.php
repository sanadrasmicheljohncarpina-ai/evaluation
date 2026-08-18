<?php
// dean/dean_reports.php
// Dean Reports & Analytics — College Division
// Same architecture as the other dean pages (self-healing schema,
// safe_scalar/safe_rows exception-catching, same dark theme, College-only
// scope). Handles ?type= for the five report links on dean_dashboard.php:
//   college_summary | faculty_performance | department_comparison |
//   program_analytics | accreditation_support
// With no ?type=, shows a picker menu (matches the dashboard's report
// button list).
//
// ── SCHEMA (confirmed via phpMyAdmin structure view, Aug 2026) ─────────
// evaluation_tracker.score is used directly for ratings — no join to a
// separate questionnaire_answers table. Every tracker query filters:
//   - status IN ('submitted','approved')  — excludes draft/archived rows
//   - eval_bucket = 'Faculty'             — excludes non-faculty buckets
//   - level = 'college'                   — belt-and-suspenders JHS/SHS guard
//
// ── REMAINING ASSUMPTIONS / KNOWN GAPS (flag these back to me if wrong) ─
// 1. "Completion" / "Fully Evaluated" throughout means "received >=1
//    valid evaluation this period" — not a percentage of an expected
//    roster, since no assignment/roster table is visible in this schema.
// 2. Reports are scoped to the currently active evaluation_periods row.
//    There's no report-generation/history table (dean_dashboard.php's
//    "Reports Generated" stat is a placeholder 0 for the same reason),
//    so these render live rather than being saved/logged anywhere.
// 3. Export here is browser Print (Ctrl/Cmd+P → Save as PDF) plus a CSV
//    download for tabular sections — no server-side PDF generation, to
//    avoid adding a new dependency without checking what's available.
// 4. Students don't carry a `department` value in this schema — Program
//    Analytics groups students by `course`; Department Comparison groups
//    faculty by `department`. Same split used on the other dean pages.
// ─────────────────────────────────────────────────────────────────────

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => false,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
require_once 'db.php';
require_once dirname(__DIR__) . '/shared/system_settings_service.php';
require_once dirname(__DIR__) . '/shared/ea_personnel_service.php'; // for COLLEGE_LEVELS — same college-scoping source of truth as the other Dean pages

// ── AUTH GUARD ────────────────────────────────────────────
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'dean') {
    header("Location: dean_login.php");
    exit;
}

// BUGFIX: every query below was filtering teacher/staff college-scoping on
// `academic_level='college'`, a column that's always NULL for those roles
// (same dead-column bug already fixed in dean_evaluation_tracker.php —
// real college membership lives in the `user_year_levels` junction table).
// That's why facultyCount and everything derived from it silently sat at
// 0 even with real approved College faculty in the system. Removed the
// now-pointless ALTER TABLE for that dead column and switched every
// query below to the EXISTS/user_year_levels pattern instead.
$collegePh = implode(',', array_fill(0, count(COLLEGE_LEVELS), '?'));
$collegeTypes = str_repeat('s', count(COLLEGE_LEVELS));

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
        return null;
    }
}
function safe_rows(mysqli $mysqli, string $sql, string $types = '', array $params = []): array {
    try {
        $stmt = @$mysqli->prepare($sql);
        if (!$stmt) return [];
        if ($types !== '') { $stmt->bind_param($types, ...$params); }
        if (!@$stmt->execute()) { $stmt->close(); return []; }
        $res = $stmt->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    } catch (mysqli_sql_exception $e) {
        return [];
    }
}

// ── DEAN PROFILE (for sidebar) ─────────────────────────────
$stmt = $mysqli->prepare("SELECT full_name, designation, photo FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();
$photo_src = !empty($me['photo']) ? UPLOAD_URL . $me['photo'] : UPLOAD_URL . 'pbi_logo';

// ── GLOBAL SYSTEM SETTINGS (single source of truth) ───────────────────
// Same call dean_dashboard.php / dean_evaluation.php make. This page must
// never derive its own period/structure state (it previously ran a
// private `evaluation_periods WHERE is_active=1` query, which could
// disagree with the rest of the Dean module).
$settings = get_system_settings($mysqli);
$structureActive = ($settings['academic_structure'] === 'college');
$period_id_int   = $settings['period_id'] ?? 0;
$hasPeriod       = $period_id_int > 0;
$periodLabel     = $hasPeriod ? trim(($settings['academic_term'] ?? '') . ' ' . ($settings['academic_year'] ?? '')) : 'No Active Period';

const HIGHER_ED_LABEL = 'Higher Education';

$validTypes = ['college_summary', 'faculty_performance', 'department_comparison', 'program_analytics', 'accreditation_support'];
$type = in_array($_GET['type'] ?? '', $validTypes, true) ? $_GET['type'] : '';

// ── HEADLINE NUMBERS ──────────────────────────────────────
$facultyCount = 0; $studentCount = 0;
$studentsSubmitted = 0; $facultyEvaluated = 0; $overallAvg = null;
$evaluationCompletion = 0; $studentParticipation = 0;

if ($structureActive) {
    $facultyCount = (int)(safe_scalar($mysqli, "
        SELECT COUNT(*) c FROM users u
        WHERE u.role='teacher' AND u.is_active=1 AND u.account_status='approved'
          AND EXISTS (SELECT 1 FROM user_year_levels uyl WHERE uyl.user_id = u.id AND uyl.year_level IN ($collegePh))
    ", $collegeTypes, COLLEGE_LEVELS) ?? 0);

    $studentCount = (int)(safe_scalar($mysqli, "
        SELECT COUNT(*) c FROM users
        WHERE role='student' AND is_active=1 AND account_status='approved' AND education_level='college'
    ") ?? 0);

    if ($hasPeriod) {
        $studentsSubmitted = (int)(safe_scalar($mysqli, "
            SELECT COUNT(DISTINCT et.evaluator_id) c
            FROM evaluation_tracker et
            INNER JOIN users u ON u.id = et.evaluator_id
            WHERE et.eval_type='student' AND et.eval_bucket='Faculty' AND et.level='college'
              AND et.status IN ('submitted','approved') AND et.period_id=?
              AND u.role='student' AND u.education_level='college'
        ", "i", [$period_id_int]) ?? 0);

        $facultyEvaluated = (int)(safe_scalar($mysqli, "
            SELECT COUNT(DISTINCT et.target_user_id) c
            FROM evaluation_tracker et
            INNER JOIN users u ON u.id = et.target_user_id
            WHERE et.eval_type='student' AND et.eval_bucket='Faculty' AND et.level='college'
              AND et.status IN ('submitted','approved') AND et.period_id=?
              AND u.role='teacher'
              AND EXISTS (SELECT 1 FROM user_year_levels uyl WHERE uyl.user_id = u.id AND uyl.year_level IN ($collegePh))
        ", "i" . $collegeTypes, array_merge([$period_id_int], COLLEGE_LEVELS)) ?? 0);

        $overallAvgRaw = safe_scalar($mysqli, "
            SELECT AVG(et.score) v
            FROM evaluation_tracker et
            INNER JOIN users u ON u.id = et.target_user_id
            WHERE et.eval_type='student' AND et.eval_bucket='Faculty' AND et.level='college'
              AND et.status IN ('submitted','approved') AND et.period_id=?
              AND u.role='teacher'
              AND EXISTS (SELECT 1 FROM user_year_levels uyl WHERE uyl.user_id = u.id AND uyl.year_level IN ($collegePh))
        ", "i" . $collegeTypes, array_merge([$period_id_int], COLLEGE_LEVELS));
        $overallAvg = $overallAvgRaw !== null ? round((float)$overallAvgRaw, 2) : null;
    }
    $evaluationCompletion = $facultyCount > 0 ? round($facultyEvaluated / $facultyCount * 100) : 0;
    $studentParticipation = $studentCount > 0 ? round($studentsSubmitted / $studentCount * 100) : 0;
}

// Defaults so the page still renders (with a structure-mismatch notice)
// when College isn't the active academic structure.
$facultyPerf = []; $topFaculty = []; $attentionFaculty = [];
$departmentStats = [];
$programStats = [];

if ($structureActive) {
    // ── PER-FACULTY ROSTER (for Faculty Performance report) ────────────────
    $facultyRoster = safe_rows($mysqli, "
        SELECT id, full_name, department, course, designation FROM users u
        WHERE role='teacher' AND is_active=1 AND account_status='approved'
          AND EXISTS (SELECT 1 FROM user_year_levels uyl WHERE uyl.user_id = u.id AND uyl.year_level IN ($collegePh))
        ORDER BY full_name
    ", $collegeTypes, COLLEGE_LEVELS);
    foreach ($facultyRoster as $row) {
        $fid = (int)$row['id'];
        $received = $hasPeriod ? (int)(safe_scalar($mysqli, "
            SELECT COUNT(DISTINCT evaluator_id) c FROM evaluation_tracker
            WHERE eval_type='student' AND eval_bucket='Faculty' AND level='college'
              AND status IN ('submitted','approved') AND period_id=? AND target_user_id=?
        ", "ii", [$period_id_int, $fid]) ?? 0) : 0;

        $avgRating = $hasPeriod ? safe_scalar($mysqli, "
            SELECT AVG(score) v FROM evaluation_tracker
            WHERE eval_type='student' AND eval_bucket='Faculty' AND level='college'
              AND status IN ('submitted','approved') AND target_user_id=? AND period_id=?
        ", "ii", [$fid, $period_id_int]) : null;

        $facultyPerf[] = [
            'name'        => $row['full_name'],
            'department'  => $row['department'] ?: '—',
            'course'      => $row['course'] ?: '—',
            'designation' => $row['designation'] ?: '—',
            'received'    => $received,
            'avg'         => $avgRating !== null ? round((float)$avgRating, 2) : null,
        ];
    }
    usort($facultyPerf, fn($a, $b) => ($b['avg'] ?? -1) <=> ($a['avg'] ?? -1));
    $topFaculty       = array_slice(array_filter($facultyPerf, fn($f) => $f['avg'] !== null), 0, 5);
    $attentionFaculty = array_slice(array_filter(array_reverse($facultyPerf), fn($f) => $f['avg'] !== null && $f['avg'] < 3.5), 0, 5);

    // ── DEPARTMENT COMPARISON ───────────────────────────────────
    $departments = array_column(safe_rows($mysqli, "
        SELECT DISTINCT department FROM users u
        WHERE role='teacher' AND is_active=1 AND account_status='approved'
          AND department IS NOT NULL AND department <> ''
          AND EXISTS (SELECT 1 FROM user_year_levels uyl WHERE uyl.user_id = u.id AND uyl.year_level IN ($collegePh))
        ORDER BY department
    ", $collegeTypes, COLLEGE_LEVELS), 'department');

    foreach ($departments as $dept) {
        $deptFaculty = (int)(safe_scalar($mysqli, "
            SELECT COUNT(*) c FROM users u
            WHERE role='teacher' AND is_active=1 AND account_status='approved' AND department=?
              AND EXISTS (SELECT 1 FROM user_year_levels uyl WHERE uyl.user_id = u.id AND uyl.year_level IN ($collegePh))
        ", "s" . $collegeTypes, array_merge([$dept], COLLEGE_LEVELS)) ?? 0);

        $deptEvaluated = 0;
        $deptAvg = null;
        if ($hasPeriod && $deptFaculty > 0) {
            $deptEvaluated = (int)(safe_scalar($mysqli, "
                SELECT COUNT(DISTINCT et.target_user_id) c
                FROM evaluation_tracker et
                INNER JOIN users u ON u.id = et.target_user_id
                WHERE et.eval_type='student' AND et.eval_bucket='Faculty' AND et.level='college'
                  AND et.status IN ('submitted','approved') AND et.period_id=?
                  AND u.role='teacher' AND u.department=?
            ", "is", [$period_id_int, $dept]) ?? 0);

            $deptAvgRaw = safe_scalar($mysqli, "
                SELECT AVG(et.score) v
                FROM evaluation_tracker et
                INNER JOIN users u ON u.id = et.target_user_id
                WHERE et.eval_type='student' AND et.eval_bucket='Faculty' AND et.level='college'
                  AND et.status IN ('submitted','approved') AND et.period_id=?
                  AND u.role='teacher' AND u.department=?
            ", "is", [$period_id_int, $dept]);
            $deptAvg = $deptAvgRaw !== null ? round((float)$deptAvgRaw, 2) : null;
        }

        $departmentStats[] = [
            'department'  => $dept,
            'faculty'     => $deptFaculty,
            'evaluated'   => $deptEvaluated,
            'completion'  => $deptFaculty > 0 ? round($deptEvaluated / $deptFaculty * 100) : 0,
            'avg'         => $deptAvg,
        ];
    }

    // ── PROGRAM ANALYTICS ───────────────────────────────────────
    $programs = array_column(safe_rows($mysqli, "
        SELECT DISTINCT course FROM users
        WHERE role='student' AND is_active=1 AND account_status='approved'
          AND education_level='college' AND course IS NOT NULL AND course <> ''
        ORDER BY course
    "), 'course');

    foreach ($programs as $program) {
        $progStudents = (int)(safe_scalar($mysqli, "
            SELECT COUNT(*) c FROM users
            WHERE role='student' AND is_active=1 AND account_status='approved'
              AND education_level='college' AND course=?
        ", "s", [$program]) ?? 0);

        $progFaculty = (int)(safe_scalar($mysqli, "
            SELECT COUNT(*) c FROM users u
            WHERE role='teacher' AND is_active=1 AND account_status='approved' AND course=?
              AND EXISTS (SELECT 1 FROM user_year_levels uyl WHERE uyl.user_id = u.id AND uyl.year_level IN ($collegePh))
        ", "s" . $collegeTypes, array_merge([$program], COLLEGE_LEVELS)) ?? 0);

        $progSubmitted = 0;
        $progAvg = null;
        if ($hasPeriod) {
            $progSubmitted = (int)(safe_scalar($mysqli, "
                SELECT COUNT(DISTINCT et.evaluator_id) c
                FROM evaluation_tracker et
                INNER JOIN users u ON u.id = et.evaluator_id
                WHERE et.eval_type='student' AND et.eval_bucket='Faculty' AND et.level='college'
                  AND et.status IN ('submitted','approved') AND et.period_id=?
                  AND u.role='student' AND u.course=?
            ", "is", [$period_id_int, $program]) ?? 0);

            $progAvgRaw = safe_scalar($mysqli, "
                SELECT AVG(et.score) v
                FROM evaluation_tracker et
                INNER JOIN users u ON u.id = et.evaluator_id
                WHERE et.eval_type='student' AND et.eval_bucket='Faculty' AND et.level='college'
                  AND et.status IN ('submitted','approved') AND et.period_id=?
                  AND u.role='student' AND u.course=?
            ", "is", [$period_id_int, $program]);
            $progAvg = $progAvgRaw !== null ? round((float)$progAvgRaw, 2) : null;
        }

        $programStats[] = [
            'program'       => $program,
            'students'      => $progStudents,
            'submitted'     => $progSubmitted,
            'participation' => $progStudents > 0 ? round($progSubmitted / $progStudents * 100) : 0,
            'faculty'       => $progFaculty,
            'avg'           => $progAvg,
        ];
    }
}

$mysqli->close();

// ── REPORT METADATA (for the picker + page titles) ──────────────────
$reportMeta = [
    'college_summary' => [
        'title' => 'College Evaluation Summary',
        'icon'  => 'fa-file-lines',
        'desc'  => 'Headline numbers for the whole College division this period.',
    ],
    'faculty_performance' => [
        'title' => 'Faculty Performance Report',
        'icon'  => 'fa-chalkboard-user',
        'desc'  => 'Every faculty member ranked by average rating, with top and attention lists.',
    ],
    'department_comparison' => [
        'title' => 'Department Comparison',
        'icon'  => 'fa-scale-balanced',
        'desc'  => 'Completion and average rating side-by-side across departments.',
    ],
    'program_analytics' => [
        'title' => 'Program Analytics',
        'icon'  => 'fa-book',
        'desc'  => 'Student participation and faculty rating broken down by program.',
    ],
    'accreditation_support' => [
        'title' => 'Accreditation Support Report',
        'icon'  => 'fa-stamp',
        'desc'  => 'A single combined document — division summary, departments, and programs.',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI — Reports<?= $type ? ' — ' . htmlspecialchars($reportMeta[$type]['title']) : '' ?></title>
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
.page-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:26px;flex-wrap:wrap;gap:14px;}
.page-title{font-family:'Rajdhani',sans-serif;font-size:30px;font-weight:700;color:#fff;letter-spacing:1px;}
.page-sub{font-size:13px;color:var(--muted);margin-top:4px;}
.back-link{color:var(--violet-h);font-size:13px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;margin-bottom:14px;}
.back-link:hover{text-decoration:underline;}

.period-badge{background:rgba(124,95,217,.14);border:1px solid rgba(124,95,217,.3);color:var(--violet-h);padding:8px 16px;border-radius:20px;font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px;}
.period-badge.closed{background:rgba(240,84,84,.1);border-color:rgba(240,84,84,.3);color:#fca5a5;}
.period-badge.amber{background:rgba(217,119,6,.14);border-color:rgba(217,119,6,.3);color:#fbbf24;}
.period-badge.gray{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.12);color:var(--muted);}

.structure-note{display:flex;align-items:flex-start;gap:14px;padding:18px 20px;background:rgba(124,95,217,.08);border:1px solid rgba(124,95,217,.25);border-radius:12px;margin-bottom:26px;}
.structure-note i{color:var(--violet-h);font-size:20px;margin-top:2px;}
.structure-note p{font-size:13px;color:var(--light);line-height:1.6;}
.structure-note p b{color:#fff;}

.action-btns{display:flex;gap:10px;}
.btn{background:rgba(124,95,217,.12);border:1px solid rgba(124,95,217,.35);color:var(--violet-h);padding:9px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:7px;transition:background .2s;font-family:'DM Sans',sans-serif;}
.btn:hover{background:rgba(124,95,217,.22);}
.btn-solid{background:var(--violet);border:none;color:#fff;box-shadow:0 4px 14px rgba(124,95,217,.35);}
.btn-solid:hover{background:var(--violet-h);}

/* Report picker cards */
.report-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px;}
.report-card{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:24px;box-shadow:var(--shadow);text-decoration:none;color:inherit;display:block;transition:transform .15s,border-color .15s;}
.report-card:hover{transform:translateY(-2px);border-color:rgba(124,95,217,.4);}
.report-card i{color:var(--violet-h);font-size:24px;margin-bottom:14px;}
.report-card h3{font-family:'Rajdhani',sans-serif;font-size:18px;color:#fff;margin-bottom:6px;}
.report-card p{font-size:12.5px;color:var(--muted);line-height:1.5;}

.card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:26px;}
.stat-card{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:20px;box-shadow:var(--shadow);}
.stat-card i{color:var(--violet-h);font-size:20px;margin-bottom:10px;}
.stat-card .num{font-size:28px;font-weight:700;color:#fff;}
.stat-card .label{font-size:12px;color:var(--muted);margin-top:4px;}

.section{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:24px;box-shadow:var(--shadow);margin-bottom:26px;}
.section h2{font-family:'Rajdhani',sans-serif;font-size:19px;color:#fff;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.section h2 i{color:var(--violet-h);font-size:16px;}
.section p.narrative{font-size:13px;color:var(--light);line-height:1.7;margin-bottom:14px;}

table.data{width:100%;border-collapse:collapse;font-size:13px;}
table.data th{text-align:left;color:var(--muted);font-weight:600;padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.08);text-transform:uppercase;font-size:11px;letter-spacing:.4px;}
table.data td{padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.05);}
table.data tr:last-child td{border-bottom:none;}
.bar-wrap{background:rgba(255,255,255,.08);border-radius:6px;height:8px;width:100%;overflow:hidden;}
.bar-fill{height:100%;background:linear-gradient(90deg,var(--violet-dark),var(--violet-h));border-radius:6px;}
.mini-list{list-style:none;font-size:13px;}
.mini-list li{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.05);}
.mini-list li:last-child{border-bottom:none;}
.mini-list .val{color:var(--violet-h);font-weight:600;}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.empty-note{color:var(--muted);font-size:13px;font-style:italic;}

@media(max-width:900px){.two-col{grid-template-columns:1fr;}}
@media(max-width:768px){body{flex-direction:column;}.sidebar{width:100%;min-height:auto;}}

@media print {
    .sidebar, .back-link, .action-btns, .period-badge { display:none !important; }
    body { display:block; background:#fff; color:#000; }
    .main { padding:0; }
    .section, .stat-card, .report-card { background:#fff !important; border:1px solid #ccc !important; box-shadow:none !important; color:#000 !important; }
    .page-title, .section h2, .stat-card .num, table.data th, .mini-list .val { color:#000 !important; }
    .page-sub, .stat-card .label, .muted, .empty-note { color:#444 !important; }
}
</style>
</head>
<body>

<?php
$active = 'reports';
$sidebarScope = 'College Division';
include __DIR__ . '/includes/dean_sidebar.php';
?>

<main class="main">

<?php if (!$structureActive): ?>
    <div class="page-header">
        <div>
            <div class="page-title">Reports &amp; Analytics</div>
            <div class="page-sub">College Division</div>
        </div>
        <div class="period-badge <?= htmlspecialchars($settings['status']['cls']) ?>">
            <i class="fa-solid fa-calendar-check"></i>
            <?= htmlspecialchars($settings['academic_year']) ?> · <?= htmlspecialchars($settings['academic_structure_label']) ?> · <?= htmlspecialchars($settings['academic_term']) ?>
            — <?= htmlspecialchars($settings['status']['label']) ?>
        </div>
    </div>
    <div class="structure-note">
        <i class="fa-solid fa-circle-info"></i>
        <p>
            <b><?= HIGHER_ED_LABEL ?> is not the active academic structure.</b><br>
            The current evaluation period is configured for <b><?= htmlspecialchars($settings['academic_structure_label']) ?></b>.
            Reports are unavailable until the Executive Assistant switches it back.
        </p>
    </div>
<?php elseif ($type === ''): ?>
    <!-- ═══════════════ REPORT PICKER ═══════════════ -->
    <div class="page-header">
        <div>
            <div class="page-title">Reports &amp; Analytics</div>
            <div class="page-sub">College Division — choose a report to generate</div>
        </div>
        <div class="period-badge <?= htmlspecialchars($settings['status']['cls']) ?>">
            <i class="fa-solid fa-calendar-check"></i>
            <?= htmlspecialchars($settings['academic_year']) ?> · <?= HIGHER_ED_LABEL ?> · <?= htmlspecialchars($settings['academic_term']) ?>
            — <?= htmlspecialchars($settings['status']['label']) ?>
        </div>
    </div>

    <div class="report-grid">
        <?php foreach ($reportMeta as $key => $meta): ?>
        <a href="dean_reports.php?type=<?= urlencode($key) ?>" class="report-card">
            <i class="fa-solid <?= $meta['icon'] ?>"></i>
            <h3><?= htmlspecialchars($meta['title']) ?></h3>
            <p><?= htmlspecialchars($meta['desc']) ?></p>
        </a>
        <?php endforeach; ?>
    </div>

<?php else: $meta = $reportMeta[$type]; ?>
    <!-- ═══════════════ REPORT VIEW ═══════════════ -->
    <a href="dean_reports.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> All Reports</a>
    <div class="page-header">
        <div>
            <div class="page-title"><?= htmlspecialchars($meta['title']) ?></div>
            <div class="page-sub">Pandan Bay Institute — College Division — <?= htmlspecialchars($periodLabel) ?> — generated <?= date('F j, Y') ?></div>
        </div>
        <div class="action-btns">
            <button class="btn btn-solid" onclick="window.print()"><i class="fa-solid fa-print"></i> Print / Save PDF</button>
            <?php if (in_array($type, ['faculty_performance','department_comparison','program_analytics'], true)): ?>
            <button class="btn" onclick="exportCsv()"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($type === 'college_summary'): ?>

        <div class="card-grid">
            <div class="stat-card"><i class="fa-solid fa-chalkboard-user"></i><div class="num"><?= $facultyCount ?></div><div class="label">Faculty Members</div></div>
            <div class="stat-card"><i class="fa-solid fa-user-graduate"></i><div class="num"><?= $studentCount ?></div><div class="label">Students</div></div>
            <div class="stat-card"><i class="fa-solid fa-clipboard-check"></i><div class="num"><?= $evaluationCompletion ?>%</div><div class="label">Faculty Evaluation Completion</div></div>
            <div class="stat-card"><i class="fa-solid fa-square-poll-vertical"></i><div class="num"><?= $studentParticipation ?>%</div><div class="label">Student Participation</div></div>
            <div class="stat-card"><i class="fa-solid fa-star"></i><div class="num"><?= $overallAvg !== null ? $overallAvg : '—' ?></div><div class="label">Overall Avg Rating</div></div>
        </div>

        <div class="section">
            <h2><i class="fa-solid fa-file-lines"></i> Summary</h2>
            <?php if (!$hasPeriod): ?>
                <p class="narrative">There is no active evaluation period right now, so completion and rating figures below are not applicable. Faculty and student headcounts still reflect current College division enrollment.</p>
            <?php else: ?>
                <p class="narrative">
                    During <?= htmlspecialchars($periodLabel) ?>, <?= $facultyEvaluated ?> of <?= $facultyCount ?> college faculty
                    (<?= $evaluationCompletion ?>%) have received at least one student evaluation, and <?= $studentsSubmitted ?> of
                    <?= $studentCount ?> college students (<?= $studentParticipation ?>%) have submitted at least one evaluation.
                    <?= $overallAvg !== null ? "The overall average faculty rating across the division is {$overallAvg}." : "No ratings have been recorded yet this period." ?>
                </p>
            <?php endif; ?>
            <div class="two-col">
                <div>
                    <h3 style="font-size:13px;color:var(--muted);margin-bottom:10px;text-transform:uppercase;letter-spacing:.4px;">By Department</h3>
                    <ul class="mini-list">
                        <?php if (empty($departmentStats)): ?><li class="empty-note">No department data yet.</li><?php endif; ?>
                        <?php foreach ($departmentStats as $d): ?>
                            <li><span><?= htmlspecialchars($d['department']) ?></span><span class="val"><?= $d['completion'] ?>%</span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div>
                    <h3 style="font-size:13px;color:var(--muted);margin-bottom:10px;text-transform:uppercase;letter-spacing:.4px;">By Program</h3>
                    <ul class="mini-list">
                        <?php if (empty($programStats)): ?><li class="empty-note">No program data yet.</li><?php endif; ?>
                        <?php foreach ($programStats as $p): ?>
                            <li><span><?= htmlspecialchars($p['program']) ?></span><span class="val"><?= $p['participation'] ?>%</span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>

    <?php elseif ($type === 'faculty_performance'): ?>

        <div class="section">
            <h2><i class="fa-solid fa-chalkboard-user"></i> All Faculty — Ranked by Average Rating</h2>
            <?php if (empty($facultyPerf)): ?>
                <p class="empty-note">No college faculty accounts found yet.</p>
            <?php else: ?>
            <table class="data" id="csvTable">
                <thead><tr><th>Faculty</th><th>Department</th><th>Program</th><th>Designation</th><th>Evaluations</th><th>Avg Rating</th></tr></thead>
                <tbody>
                <?php foreach ($facultyPerf as $f): ?>
                    <tr>
                        <td><?= htmlspecialchars($f['name']) ?></td>
                        <td><?= htmlspecialchars($f['department']) ?></td>
                        <td><?= htmlspecialchars($f['course']) ?></td>
                        <td><?= htmlspecialchars($f['designation']) ?></td>
                        <td><?= $f['received'] ?></td>
                        <td><?= $f['avg'] !== null ? $f['avg'] : 'N/A' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <div class="two-col">
            <div class="section">
                <h2><i class="fa-solid fa-arrow-trend-up"></i> Top Performing Faculty</h2>
                <ul class="mini-list">
                    <?php if (empty($topFaculty)): ?><li class="empty-note">No rating data available yet.</li><?php endif; ?>
                    <?php foreach ($topFaculty as $f): ?>
                        <li><span><?= htmlspecialchars($f['name']) ?></span><span class="val"><?= $f['avg'] ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="section">
                <h2><i class="fa-solid fa-arrow-trend-down"></i> Faculty Requiring Attention</h2>
                <ul class="mini-list">
                    <?php if (empty($attentionFaculty)): ?><li class="empty-note">None below threshold (3.5).</li><?php endif; ?>
                    <?php foreach ($attentionFaculty as $f): ?>
                        <li><span><?= htmlspecialchars($f['name']) ?></span><span class="val" style="color:#fca5a5;"><?= $f['avg'] ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

    <?php elseif ($type === 'department_comparison'): ?>

        <div class="section">
            <h2><i class="fa-solid fa-scale-balanced"></i> Department Comparison</h2>
            <?php if (empty($departmentStats)): ?>
                <p class="empty-note">No department data yet — faculty accounts need a department assigned.</p>
            <?php else: ?>
            <table class="data" id="csvTable">
                <thead><tr><th>Department</th><th>Faculty Count</th><th>Faculty Evaluated</th><th>Completion</th><th>Avg Rating</th></tr></thead>
                <tbody>
                <?php foreach ($departmentStats as $d): ?>
                    <tr>
                        <td><?= htmlspecialchars($d['department']) ?></td>
                        <td><?= $d['faculty'] ?></td>
                        <td><?= $d['evaluated'] ?></td>
                        <td style="min-width:140px;">
                            <div class="bar-wrap"><div class="bar-fill" style="width:<?= $d['completion'] ?>%"></div></div>
                            <span style="font-size:11px;color:var(--muted);"><?= $d['completion'] ?>%</span>
                        </td>
                        <td><?= $d['avg'] !== null ? $d['avg'] : 'N/A' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

    <?php elseif ($type === 'program_analytics'): ?>

        <div class="section">
            <h2><i class="fa-solid fa-book"></i> Program Analytics</h2>
            <?php if (empty($programStats)): ?>
                <p class="empty-note">No program data yet — students need a course/program assigned at registration.</p>
            <?php else: ?>
            <table class="data" id="csvTable">
                <thead><tr><th>Program</th><th>Students</th><th>Submitted</th><th>Participation</th><th>Faculty Count</th><th>Avg Rating</th></tr></thead>
                <tbody>
                <?php foreach ($programStats as $p): ?>
                    <tr>
                        <td><?= htmlspecialchars($p['program']) ?></td>
                        <td><?= $p['students'] ?></td>
                        <td><?= $p['submitted'] ?></td>
                        <td style="min-width:140px;">
                            <div class="bar-wrap"><div class="bar-fill" style="width:<?= $p['participation'] ?>%"></div></div>
                            <span style="font-size:11px;color:var(--muted);"><?= $p['participation'] ?>%</span>
                        </td>
                        <td><?= $p['faculty'] ?></td>
                        <td><?= $p['avg'] !== null ? $p['avg'] : 'N/A' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

    <?php elseif ($type === 'accreditation_support'): ?>

        <div class="section">
            <h2><i class="fa-solid fa-stamp"></i> Executive Summary</h2>
            <?php if (!$hasPeriod): ?>
                <p class="narrative">There is no active evaluation period right now. Figures below reflect current College division headcounts only.</p>
            <?php else: ?>
                <p class="narrative">
                    This report covers <?= htmlspecialchars($periodLabel) ?> for the College division of Pandan Bay Institute.
                    The division has <?= $facultyCount ?> active faculty across <?= count($departmentStats) ?> department(s)
                    and <?= $studentCount ?> active students across <?= count($programStats) ?> program(s).
                    Faculty evaluation completion stands at <?= $evaluationCompletion ?>% (<?= $facultyEvaluated ?>/<?= $facultyCount ?>),
                    and student participation at <?= $studentParticipation ?>% (<?= $studentsSubmitted ?>/<?= $studentCount ?>).
                    <?= $overallAvg !== null ? "The division-wide average faculty rating is {$overallAvg} out of 5." : '' ?>
                </p>
            <?php endif; ?>
            <div class="card-grid">
                <div class="stat-card"><i class="fa-solid fa-chalkboard-user"></i><div class="num"><?= $facultyCount ?></div><div class="label">Faculty Members</div></div>
                <div class="stat-card"><i class="fa-solid fa-user-graduate"></i><div class="num"><?= $studentCount ?></div><div class="label">Students</div></div>
                <div class="stat-card"><i class="fa-solid fa-clipboard-check"></i><div class="num"><?= $evaluationCompletion ?>%</div><div class="label">Completion</div></div>
                <div class="stat-card"><i class="fa-solid fa-star"></i><div class="num"><?= $overallAvg !== null ? $overallAvg : '—' ?></div><div class="label">Avg Rating</div></div>
            </div>
        </div>

        <div class="section">
            <h2><i class="fa-solid fa-building-columns"></i> Department Breakdown</h2>
            <?php if (empty($departmentStats)): ?>
                <p class="empty-note">No department data yet.</p>
            <?php else: ?>
            <table class="data">
                <thead><tr><th>Department</th><th>Faculty</th><th>Completion</th><th>Avg Rating</th></tr></thead>
                <tbody>
                <?php foreach ($departmentStats as $d): ?>
                    <tr>
                        <td><?= htmlspecialchars($d['department']) ?></td>
                        <td><?= $d['faculty'] ?></td>
                        <td><?= $d['completion'] ?>%</td>
                        <td><?= $d['avg'] !== null ? $d['avg'] : 'N/A' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <div class="section">
            <h2><i class="fa-solid fa-book"></i> Program Breakdown</h2>
            <?php if (empty($programStats)): ?>
                <p class="empty-note">No program data yet.</p>
            <?php else: ?>
            <table class="data">
                <thead><tr><th>Program</th><th>Students</th><th>Participation</th><th>Faculty</th><th>Avg Rating</th></tr></thead>
                <tbody>
                <?php foreach ($programStats as $p): ?>
                    <tr>
                        <td><?= htmlspecialchars($p['program']) ?></td>
                        <td><?= $p['students'] ?></td>
                        <td><?= $p['participation'] ?>%</td>
                        <td><?= $p['faculty'] ?></td>
                        <td><?= $p['avg'] !== null ? $p['avg'] : 'N/A' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

    <?php endif; ?>

<?php endif; ?>

</main>

<script>
function exportCsv() {
    const table = document.getElementById('csvTable');
    if (!table) return;
    const rows = Array.from(table.querySelectorAll('tr'));
    const csv = rows.map(row =>
        Array.from(row.querySelectorAll('th,td'))
            .map(cell => {
                let text = cell.innerText.replace(/\s+/g, ' ').trim();
                if (text.includes(',') || text.includes('"')) {
                    text = '"' + text.replace(/"/g, '""') + '"';
                }
                return text;
            })
            .join(',')
    ).join('\n');

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = document.title.replace(/[^a-z0-9]+/gi, '_').toLowerCase() + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}
</script>
</body>
</html>
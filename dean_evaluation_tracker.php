<?php
// session_bootstrap.php — include this BEFORE session_start() everywhere
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

// ── AUTH GUARD ────────────────────────────────────────────
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'dean') {
    header("Location: dean_login.php");
    exit;
}

// ── PHASE 2 REWRITE (kept) ───────────────────────────────────────────
// This page shows ONLY Higher Education student evaluation-submission
// participation — no evaluation scores. Students fetched the same way
// Manage Privileged Accounts does (role='student', account_status=
// 'approved', is_active=1).
//
// REDESIGN NOTE (mockup-driven): added Reset button, colored-initial
// avatars, sortable Student/Year Level columns, pagination
// (5/page), and Export List. No ID Number column — student accounts
// don't collect a student ID number at registration, so the mockup's
// "2024-123456" column was dropped rather than faked. The submission-
// status lookup was rewritten from one query per student to two bulk
// queries total, since pagination implies this page now expects
// hundreds of rows rather than a handful.
//
// FIX (this pass) — two compounding bugs that made College students never
// show up here at all:
//   1. This page was still selecting/filtering on `users.course`, a column
//      that was dropped from `users` in the DB redesign (student
//      course/program is no longer stored on the user row at all). Every
//      query referencing it was silently throwing and getting swallowed by
//      safe_rows()'s try/catch, returning an empty array — so NO students
//      showed up, not just a College-specific problem. The Program
//      dropdown/column/filter/export field are removed below; there is
//      currently no replacement source for student program, matching the
//      choice already made on dean_evaluate.php for the same removed
//      column.
//   2. College-membership was being decided by `education_level='college'`
//      alone. manage_privileged_accounts.php — the actual admin approval
//      flow — never checks education_level for students; it classifies
//      them purely from the year_level string via classify_student_level()
//      (pattern match: "college" in the string, or a leading "1st/2nd/3rd/
//      4th Year"). That's the real source of truth for "is this student
//      College", so this page now matches it (year_level pattern, with
//      education_level='college' kept as a harmless OR fallback) instead
//      of trusting education_level alone.

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
$settings = get_system_settings($mysqli);
$structureActive = ($settings['academic_structure'] === 'college');
$period_id_int   = $settings['period_id'] ?? 0;
$hasPeriod       = $period_id_int > 0;
$evalOpen        = $settings['is_open_for_submission'];

const HIGHER_ED_LABEL = 'Higher Education';
const REMINDER_COOLDOWN_HOURS = 24; // must match dean_send_reminder.php

// ── FILTER + SORT + PAGE INPUT (GET) ──────────────────────────────────
$search    = trim($_GET['search'] ?? '');
$yearLevel = trim($_GET['year_level'] ?? '');
$status    = trim($_GET['status'] ?? ''); // '', 'submitted', 'pending'
$validStatus = ['', 'submitted', 'pending'];
if (!in_array($status, $validStatus, true)) $status = '';

// SQL-native sort (Student/Program/Year Level). Status has no DB column
// of its own — it's derived below from evaluation_tracker — so a
// status sort is applied to the in-memory array instead (see below).
$sortableColumns = ['name' => 'full_name', 'year_level' => 'year_level'];
$sort = $_GET['sort'] ?? 'name';
if (!in_array($sort, array_merge(array_keys($sortableColumns), ['status']), true)) $sort = 'name';
$dir = (strtolower($_GET['dir'] ?? 'asc') === 'desc') ? 'desc' : 'asc';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 5;

// Fixed list — users.year_level values are still unconfirmed against the
// live schema; these match the snake_case style used for education_level
// elsewhere. Confirm with:
//   SELECT DISTINCT year_level FROM users WHERE role='student' AND education_level='college'
$yearLevelOptions = [
    '1st_year' => '1st Year',
    '2nd_year' => '2nd Year',
    '3rd_year' => '3rd Year',
    '4th_year' => '4th Year',
];

// Defaults so the page still renders (with the structure-mismatch notice)
// when Higher Education isn't the active academic structure.
$students           = [];
$pageStudents       = [];
$studentsAssigned   = 0;
$studentsSubmitted  = 0;
$pendingStudents    = 0;
$remainingStudents  = 0;
$completionPct      = 0;
$submittedPct       = 0;
$pendingPct         = 0;
$totalPages         = 1;

if ($structureActive) {
    // ── STUDENTS IN SCOPE — filtered by Search/Year Level at the SQL
    // level, sorted at the SQL level for the two native columns.
    // Fetched WITHOUT a LIMIT here on purpose: submission status (below)
    // and the Status filter both need the full filtered set before we
    // can know which page/count is correct, so pagination is applied
    // in-memory after that, same as the Faculty roster page.
    //
    // College membership: matches classify_student_level() in
    // manage_privileged_accounts.php — a leading "1st/2nd/3rd/4th Year"
    // or the literal word "college" anywhere in year_level. education_level
    // is kept as an OR fallback in case it's set, but is not trusted alone
    // since the admin approval flow never relies on it for students.
    $whereSql = "role='student' AND is_active=1 AND account_status='approved'
        AND (education_level='college'
             OR year_level REGEXP '^(1st|2nd|3rd|4th)[[:space:]]*Year'
             OR year_level LIKE '%College%')";
    $types = '';
    $params = [];
    if ($search !== '')    { $whereSql .= " AND full_name LIKE ?"; $types .= 's'; $params[] = '%' . $search . '%'; }
    if ($yearLevel !== '') { $whereSql .= " AND year_level=?";     $types .= 's'; $params[] = $yearLevel; }

    $orderSql = ($sort !== 'status') ? ($sortableColumns[$sort] . ' ' . strtoupper($dir)) : 'full_name ASC';

    $allRows = safe_rows($mysqli, "
        SELECT id, full_name, photo, year_level
        FROM users WHERE $whereSql ORDER BY $orderSql
    ", $types, $params);

    // ── SUBMISSION STATUS — two bulk queries total instead of one per
    // student. First, the id list we just fetched; then a single
    // GROUP BY query against evaluation_tracker for just those ids.
    $submittedMap = []; // evaluator_id => most recent submitted_at
    $allIds = array_column($allRows, 'id');
    if ($hasPeriod && !empty($allIds)) {
        $ph = implode(',', array_fill(0, count($allIds), '?'));
        $subRows = safe_rows($mysqli, "
            SELECT evaluator_id, MAX(submitted_at) v FROM evaluation_tracker
            WHERE eval_type='student' AND level='college'
              AND status IN ('submitted','approved')
              AND period_id=? AND evaluator_id IN ($ph)
            GROUP BY evaluator_id
        ", 'i' . str_repeat('i', count($allIds)), array_merge([$period_id_int], $allIds));
        foreach ($subRows as $sr) { $submittedMap[(int)$sr['evaluator_id']] = $sr['v']; }
    }

    foreach ($allRows as $s) {
        $sid = (int)$s['id'];
        $hasSubmitted = isset($submittedMap[$sid]);
        $students[] = [
            'id'           => $sid,
            'name'         => $s['full_name'],
            'photo'        => !empty($s['photo']) ? UPLOAD_URL . $s['photo'] : UPLOAD_URL . 'pbi_logo',
            'year_level'   => $yearLevelOptions[$s['year_level']] ?? ($s['year_level'] ?: '—'),
            'status'       => $hasSubmitted ? 'submitted' : 'pending',
            'submitted_at' => $submittedMap[$sid] ?? null,
        ];
    }

    // Status sort has no DB column to sort on — applied here instead.
    if ($sort === 'status') {
        usort($students, function ($a, $b) use ($dir) {
            $cmp = strcmp($a['status'], $b['status']);
            return $dir === 'desc' ? -$cmp : $cmp;
        });
    }

    // Status filter applied in-memory (after submission status is known).
    if ($status !== '') {
        $students = array_values(array_filter($students, fn($s) => $s['status'] === $status));
    }

    $studentsAssigned  = count($students);
    $studentsSubmitted = count(array_filter($students, fn($s) => $s['status'] === 'submitted'));
    $pendingStudents   = max(0, $studentsAssigned - $studentsSubmitted);
    $remainingStudents = $pendingStudents; // same figure, shown as its own card per spec
    $completionPct     = $studentsAssigned > 0 ? (int) round($studentsSubmitted / $studentsAssigned * 100) : 0;
    $submittedPct      = $completionPct;
    $pendingPct        = $studentsAssigned > 0 ? (100 - $completionPct) : 0;

    // ── EXPORT (CSV of the full filtered set, not just the visible page)
    // — must run before any HTML output.
    if (($_GET['export'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="dean_tracker_export_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Student', 'Year Level', 'Status', 'Submitted At']);
        foreach ($students as $s) {
            fputcsv($out, [
                $s['name'], $s['year_level'],
                $s['status'], $s['submitted_at'] ?: '',
            ]);
        }
        fclose($out);
        $mysqli->close();
        exit;
    }

    // ── PAGINATION (in-memory, same reasoning as the Status filter) ────
    $totalPages   = max(1, (int)ceil($studentsAssigned / $perPage));
    $page         = max(1, min($totalPages, $page));
    $pageStudents = array_slice($students, ($page - 1) * $perPage, $perPage);

    // ── LAST REMINDER SENT (for the page's rows only) — powers the
    // "Reminded X ago" state and the cooldown that dean_send_reminder.php
    // also enforces server-side.
    $lastReminderAt = [];
    $pageIds = array_column($pageStudents, 'id');
    if ($hasPeriod && !empty($pageIds)) {
        $ph = implode(',', array_fill(0, count($pageIds), '?'));
        $remRows = safe_rows($mysqli, "
            SELECT recipient_id, MAX(created_at) v FROM evaluation_reminders
            WHERE period_id=? AND recipient_id IN ($ph)
            GROUP BY recipient_id
        ", 'i' . str_repeat('i', count($pageIds)), array_merge([$period_id_int], $pageIds));
        foreach ($remRows as $rr) { $lastReminderAt[(int)$rr['recipient_id']] = $rr['v']; }
    }
    foreach ($pageStudents as &$ps) {
        $ps['last_reminded_at'] = $lastReminderAt[$ps['id']] ?? null;
        $ps['reminder_on_cooldown'] = $ps['last_reminded_at']
            && (time() - strtotime($ps['last_reminded_at'])) / 3600 < REMINDER_COOLDOWN_HOURS;
    }
    unset($ps);
}

$scopeParts = [];
if ($yearLevel !== '') $scopeParts[] = $yearLevelOptions[$yearLevel] ?? $yearLevel;
$scopeLabel = $scopeParts ? implode(' — ', $scopeParts) : HIGHER_ED_LABEL . ' Division';

// Rebuilds the current query string with overrides — used by sort
// headers and pagination links. Changing anything other than page
// resets back to page 1.
function tracker_qs(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    if (!isset($overrides['page'])) $params['page'] = 1;
    return htmlspecialchars('?' . http_build_query($params));
}
function tracker_sort_url(string $col, string $curSort, string $curDir): string {
    $newDir = ($curSort === $col && $curDir === 'asc') ? 'desc' : 'asc';
    return tracker_qs(['sort' => $col, 'dir' => $newDir]);
}
function tracker_sort_icon(string $col, string $curSort, string $curDir): string {
    if ($curSort !== $col) return 'fa-sort';
    return $curDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down';
}

// Deterministic initials + color for the avatar circle — computed from
// the real name, not stored/hardcoded per student.
function tracker_initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $first = $parts[0][0] ?? '';
    $last  = count($parts) > 1 ? $parts[count($parts) - 1][0] : '';
    return strtoupper($first . $last);
}
function tracker_avatar_color(string $name): string {
    $palette = ['#7C5FD9', '#2563EB', '#10B981', '#EA580C', '#DB2777', '#0891B2', '#CA8A04'];
    return $palette[crc32($name) % count($palette)];
}

// CSRF token for the reminder AJAX calls (same pattern as dean_evaluate.php / dean_login.php)
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$mysqli->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI — Evaluation Tracker</title>
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
.page-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:22px;flex-wrap:wrap;gap:14px;}
.page-title{font-family:'Rajdhani',sans-serif;font-size:30px;font-weight:700;color:#fff;letter-spacing:1px;}
.page-sub{font-size:13px;color:var(--muted);margin-top:4px;}

.period-badge{background:rgba(124,95,217,.14);border:1px solid rgba(124,95,217,.3);color:var(--violet-h);padding:8px 16px;border-radius:20px;font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px;}
.period-badge.closed{background:rgba(240,84,84,.1);border-color:rgba(240,84,84,.3);color:#fca5a5;}
.period-badge.amber{background:rgba(217,119,6,.14);border-color:rgba(217,119,6,.3);color:#fbbf24;}
.period-badge.gray{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.12);color:var(--muted);}

.structure-note{display:flex;align-items:flex-start;gap:14px;padding:18px 20px;background:rgba(124,95,217,.08);border:1px solid rgba(124,95,217,.25);border-radius:12px;margin-bottom:26px;}
.structure-note i{color:var(--violet-h);font-size:20px;margin-top:2px;}
.structure-note p{font-size:13px;color:var(--light);line-height:1.6;}
.structure-note p b{color:#fff;}

.filter-bar{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:18px 22px;box-shadow:var(--shadow);margin-bottom:22px;display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap;}
.filter-field{display:flex;flex-direction:column;gap:6px;}
.filter-field label{font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted);}
.filter-field select, .filter-field input[type=text]{background:rgba(10,25,47,.7);border:1px solid rgba(255,255,255,.12);border-radius:8px;color:var(--light);font-size:13px;font-family:'DM Sans',sans-serif;padding:9px 34px 9px 12px;outline:none;min-width:170px;}
.filter-field select{cursor:pointer;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23A0B3C6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;}
.filter-field input[type=text]{padding-right:34px;min-width:200px;}
.search-icon-wrap{position:relative;}
.search-icon-wrap i{position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px;pointer-events:none;}
.filter-field select:focus, .filter-field input:focus{border-color:var(--violet);}
.btn-apply{background:var(--violet);border:none;color:#fff;font-size:13px;font-weight:700;padding:10px 18px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;font-family:'DM Sans',sans-serif;box-shadow:0 4px 14px rgba(124,95,217,.35);transition:background .2s;height:38px;}
.btn-apply:hover{background:var(--violet-h);}
.btn-reset{background:transparent;border:1px solid rgba(255,255,255,.16);color:var(--muted);font-size:13px;font-weight:700;padding:10px 16px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;font-family:'DM Sans',sans-serif;height:38px;text-decoration:none;transition:all .2s;}
.btn-reset:hover{color:var(--light);border-color:rgba(255,255,255,.3);}

.card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:16px;margin-bottom:26px;}
.stat-card{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:20px;box-shadow:var(--shadow);}
.stat-card i{color:var(--violet-h);font-size:20px;margin-bottom:10px;}
.stat-card .num{font-size:28px;font-weight:700;color:#fff;}
.stat-card .label{font-size:12px;color:var(--muted);margin-top:4px;}
.stat-card .caption{font-size:11px;color:var(--muted);opacity:.75;margin-top:2px;font-style:italic;}

.section{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:24px;box-shadow:var(--shadow);margin-bottom:26px;}
.section-head{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;}
.section h2{font-family:'Rajdhani',sans-serif;font-size:19px;color:#fff;display:flex;align-items:center;gap:8px;}
.section h2 i{color:var(--violet-h);font-size:16px;}
.count-note{font-size:12px;color:var(--muted);}
.export-btn{background:rgba(124,95,217,.12);border:1px solid rgba(124,95,217,.35);color:var(--violet-h);padding:9px 14px;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:7px;transition:background .2s;}
.export-btn:hover{background:rgba(124,95,217,.22);}

.stub-note{font-size:11.5px;color:var(--violet-h);background:rgba(124,95,217,.08);border:1px dashed rgba(124,95,217,.35);border-radius:8px;padding:10px 14px;margin-bottom:16px;}

.bulk-banner{display:flex;align-items:flex-start;gap:12px;background:rgba(124,95,217,.1);border:1px solid rgba(124,95,217,.3);border-radius:12px;padding:16px 20px;margin-bottom:16px;}
.bulk-banner i{color:var(--violet-h);font-size:16px;margin-top:2px;}
.bulk-banner b{color:#fff;display:block;margin-bottom:2px;font-size:13px;}
.bulk-banner p{font-size:12.5px;color:var(--muted);}
.bulk-banner > div{flex:1;}
.btn-bulk-remind{background:var(--violet);border:none;color:#fff;padding:9px 16px;border-radius:8px;font-size:12.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:7px;white-space:nowrap;transition:background .2s;}
.btn-bulk-remind:hover:not(:disabled){background:var(--violet-dark);}
.btn-bulk-remind:disabled{opacity:.4;cursor:not-allowed;}

table.data{width:100%;border-collapse:collapse;font-size:13px;}
table.data th{text-align:left;color:var(--muted);font-weight:600;padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.08);text-transform:uppercase;font-size:11px;letter-spacing:.4px;white-space:nowrap;}
table.data th a{color:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
table.data th a:hover{color:var(--light);}
table.data td{padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.05);vertical-align:middle;}
table.data tr:last-child td{border-bottom:none;}
table.data th:first-child, table.data td:first-child{width:36px;}
.stu-name{display:flex;align-items:center;gap:10px;font-weight:600;color:#fff;}
.stu-avatar{width:32px;height:32px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:700;}
.status-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.status-pill.submitted{background:rgba(16,185,129,.14);color:var(--good);}
.status-pill.pending{background:rgba(160,179,198,.14);color:var(--muted);}
.btn-remind{background:var(--violet);border:none;color:#fff;padding:6px 12px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:background .2s;}
.btn-remind:hover{background:var(--violet-dark);}
.btn-remind.on-cooldown{background:rgba(160,179,198,.18);color:var(--muted);cursor:default;pointer-events:none;}
.btn-remind.sending{opacity:.6;pointer-events:none;}
.remind-meta{font-size:10.5px;color:var(--muted);margin-top:4px;}
.btn-more{background:transparent;border:none;color:var(--muted);font-size:16px;cursor:default;padding:4px 8px;}

.reminder-toast{display:flex;align-items:flex-start;gap:10px;background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.35);color:var(--good);border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:12.5px;line-height:1.6;}
.reminder-toast.has-skips{background:rgba(217,119,6,.1);border-color:rgba(217,119,6,.3);color:#fbbf24;}
.reminder-toast i{margin-top:2px;}
.reminder-toast b{display:block;color:#fff;margin-bottom:2px;}

.empty-note{color:var(--muted);font-size:13px;font-style:italic;}

.table-footer{display:flex;justify-content:space-between;align-items:center;padding:16px 4px 4px;font-size:12.5px;color:var(--muted);flex-wrap:wrap;gap:10px;}
.pagination{display:flex;align-items:center;gap:6px;}
.page-btn{min-width:30px;height:30px;padding:0 8px;display:flex;align-items:center;justify-content:center;border-radius:7px;background:var(--inner);border:1px solid rgba(255,255,255,.1);color:var(--muted);text-decoration:none;font-size:12.5px;font-weight:600;}
.page-btn.active{background:var(--violet);color:#fff;border-color:var(--violet);}
.page-btn.disabled{opacity:.35;pointer-events:none;}
.page-ellipsis{color:var(--muted);padding:0 4px;}

.info-banner{display:flex;align-items:flex-start;gap:12px;background:rgba(37,99,235,.1);border:1px solid rgba(37,99,235,.3);border-radius:12px;padding:16px 20px;margin-top:22px;}
.info-banner i{color:#60a5fa;font-size:16px;margin-top:2px;}
.info-banner b{color:#fff;display:block;margin-bottom:2px;}
.info-banner p{font-size:12.5px;color:var(--muted);}
.info-banner.closed{background:rgba(240,84,84,.08);border-color:rgba(240,84,84,.25);}
.info-banner.closed i{color:#fca5a5;}

@media(max-width:768px){body{flex-direction:column;}.sidebar{width:100%;min-height:auto;}.filter-bar{flex-direction:column;align-items:stretch;}}
</style>
</head>
<body>

<?php
$active = 'tracker';
$sidebarScope = HIGHER_ED_LABEL . ' Division';
include __DIR__ . '/includes/dean_sidebar.php';
?>

<main class="main">
    <div class="page-header">
        <div>
            <div class="page-title">Evaluation Tracker</div>
            <div class="page-sub">Monitor <?= HIGHER_ED_LABEL ?> student evaluation participation.</div>
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
            Tracker data is unavailable until the Executive Assistant switches it back.
        </p>
    </div>
    <?php else: ?>

    <!-- FILTER BAR -->
    <form class="filter-bar" method="GET" action="dean_evaluation_tracker.php">
        <div class="filter-field">
            <label for="search">Search</label>
            <div class="search-icon-wrap">
                <input type="text" id="search" name="search" placeholder="Student name..." value="<?= htmlspecialchars($search) ?>"/>
                <i class="fa-solid fa-magnifying-glass"></i>
            </div>
        </div>
        <div class="filter-field">
            <label for="year_level">Year Level</label>
            <select name="year_level" id="year_level">
                <option value="">All Year Levels</option>
                <?php foreach ($yearLevelOptions as $val => $lbl): ?>
                    <option value="<?= htmlspecialchars($val) ?>" <?= $yearLevel === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label for="status">Status</label>
            <select name="status" id="status">
                <option value="" <?= $status === '' ? 'selected' : '' ?>>All</option>
                <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Not Started</option>
                <option value="submitted" <?= $status === 'submitted' ? 'selected' : '' ?>>Submitted</option>
            </select>
        </div>
        <button type="submit" class="btn-apply"><i class="fa-solid fa-filter"></i> Apply</button>
        <a href="dean_evaluation_tracker.php" class="btn-reset"><i class="fa-solid fa-rotate-left"></i> Reset</a>
    </form>

    <!-- STAT CARDS -->
    <div class="card-grid">
        <div class="stat-card"><i class="fa-solid fa-users"></i><div class="num"><?= $studentsAssigned ?></div><div class="label">Total Students</div><div class="caption">All filtered students</div></div>
        <div class="stat-card"><i class="fa-solid fa-circle-check"></i><div class="num"><?= $studentsSubmitted ?></div><div class="label">Students Submitted</div><div class="caption"><?= $submittedPct ?>% of total</div></div>
        <div class="stat-card"><i class="fa-solid fa-hourglass-half"></i><div class="num"><?= $pendingStudents ?></div><div class="label">Pending Students</div><div class="caption"><?= $pendingPct ?>% of total</div></div>
        <div class="stat-card"><i class="fa-solid fa-chart-simple"></i><div class="num"><?= $completionPct ?>%</div><div class="label">Completion %</div><div class="caption">Overall completion</div></div>
        <div class="stat-card"><i class="fa-solid fa-user-clock"></i><div class="num"><?= $remainingStudents ?></div><div class="label">Remaining Students</div><div class="caption">Not yet submitted</div></div>
    </div>

    <!-- STUDENTS TO BE EVALUATED -->
    <div class="section">
        <div class="section-head">
            <h2><i class="fa-solid fa-user-graduate"></i> Students to be Evaluated</h2>
            <div style="display:flex;align-items:center;gap:14px;">
                <span class="count-note"><span id="selCount">0</span> of <?= $studentsAssigned ?> students</span>
                <a class="export-btn" href="<?= tracker_qs(['export' => 'csv']) ?>"><i class="fa-solid fa-download"></i> Export List</a>
            </div>
        </div>

        <?php if ($hasPeriod && !empty($pageStudents)): ?>
        <div class="bulk-banner">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <b>Students listed below are those who still need to complete the evaluation.</b>
                <p>You can send reminders or view student details.</p>
            </div>
            <button type="button" class="btn-bulk-remind" id="bulkRemindBtn" disabled>
                <i class="fa-solid fa-paper-plane"></i> Send Bulk Reminder
            </button>
        </div>
        <?php endif; ?>

        <?php if (!$hasPeriod): ?>
            <p class="empty-note">No active evaluation period right now.</p>
        <?php else: ?>
        <div class="stub-note">
            <i class="fa-solid fa-circle-info"></i>
            Reminders are logged in-system and rate-limited to one per student every <?= REMINDER_COOLDOWN_HOURS ?> hours. This app has no email/SMS system yet, so students won't get an outside message — the record just shows here. Selection is scoped to the current page only.
        </div>
        <div id="reminderToast" class="reminder-toast" style="display:none;"></div>
        <?php if (empty($pageStudents)): ?>
            <p class="empty-note">No students match the current filters.</p>
        <?php else: ?>
        <table class="data">
            <thead>
                <tr>
                    <th><input type="checkbox" id="selectAll" title="Select all pending on this page"/></th>
                    <th><a href="<?= tracker_sort_url('name', $sort, $dir) ?>">Student <i class="fa-solid <?= tracker_sort_icon('name', $sort, $dir) ?>"></i></a></th>
                    <th><a href="<?= tracker_sort_url('year_level', $sort, $dir) ?>">Year Level <i class="fa-solid <?= tracker_sort_icon('year_level', $sort, $dir) ?>"></i></a></th>
                    <th><a href="<?= tracker_sort_url('status', $sort, $dir) ?>">Status <i class="fa-solid <?= tracker_sort_icon('status', $sort, $dir) ?>"></i></a></th>
                    <th>Submitted At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pageStudents as $s): ?>
                <tr>
                    <td>
                        <?php if ($s['status'] !== 'submitted'): ?>
                        <input type="checkbox" class="rowSelect" value="<?= $s['id'] ?>"/>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="stu-name">
                            <span class="stu-avatar" style="background:<?= tracker_avatar_color($s['name']) ?>;"><?= htmlspecialchars(tracker_initials($s['name'])) ?></span>
                            <?= htmlspecialchars($s['name']) ?>
                        </div>
                    </td>
                    <td><?= htmlspecialchars($s['year_level']) ?></td>
                    <td>
                        <?php if ($s['status'] === 'submitted'): ?>
                            <span class="status-pill submitted"><i class="fa-solid fa-check" style="font-size:9px;"></i> Submitted</span>
                        <?php else: ?>
                            <span class="status-pill pending"><i class="fa-solid fa-hourglass-half" style="font-size:9px;"></i> Not Started</span>
                        <?php endif; ?>
                    </td>
                    <td class="empty-note"><?= $s['submitted_at'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($s['submitted_at']))) : '—' ?></td>
                    <td>
                        <?php if ($s['status'] !== 'submitted'):
                            $onCooldown = $s['reminder_on_cooldown'];
                        ?>
                        <a class="btn-remind<?= $onCooldown ? ' on-cooldown' : '' ?>"
                           href="dean_send_reminder.php?student_id=<?= $s['id'] ?>"
                           data-student-id="<?= $s['id'] ?>"
                           data-remind-link
                           <?= $onCooldown ? 'aria-disabled="true"' : '' ?>>
                            <i class="fa-solid fa-bell"></i>
                            <span class="remind-label"><?= $onCooldown ? 'Reminded' : 'Send Reminder' ?></span>
                        </a>
                        <div class="remind-meta" data-remind-meta><?= $s['last_reminded_at']
                            ? 'Last: ' . htmlspecialchars(date('M j, g:i A', strtotime($s['last_reminded_at'])))
                            : '' ?></div>
                        <?php endif; ?>
                        <button class="btn-more" title="More actions — not yet implemented">⋮</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="table-footer">
            <div>Showing <?= (($page - 1) * $perPage) + 1 ?> to <?= min($studentsAssigned, $page * $perPage) ?> of <?= $studentsAssigned ?> students</div>
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <a class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= tracker_qs(['page' => max(1, $page - 1)]) ?>"><i class="fa-solid fa-chevron-left"></i></a>
                <?php
                // First page, last page, current ±2, with "..." for gaps.
                $shown = [];
                for ($i = 1; $i <= $totalPages; $i++) {
                    if ($i === 1 || $i === $totalPages || abs($i - $page) <= 2) $shown[] = $i;
                }
                $prev = null;
                foreach ($shown as $i):
                    if ($prev !== null && $i - $prev > 1): ?>
                        <span class="page-ellipsis">…</span>
                    <?php endif; ?>
                    <a class="page-btn <?= $i === $page ? 'active' : '' ?>" href="<?= tracker_qs(['page' => $i]) ?>"><?= $i ?></a>
                <?php $prev = $i; endforeach; ?>
                <a class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= tracker_qs(['page' => min($totalPages, $page + 1)]) ?>"><i class="fa-solid fa-chevron-right"></i></a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($hasPeriod): ?>
    <div class="info-banner <?= $evalOpen ? '' : 'closed' ?>">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <b>Student evaluation is currently <?= $evalOpen ? 'open' : 'closed' ?>.</b>
            <p><?= $evalOpen
                ? 'Remind your students to complete their evaluation. Tracking updates automatically when submissions are recorded.'
                : 'No new submissions will be recorded until the evaluation window reopens.' ?></p>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</main>
<script>
(function(){
    const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;
    const selectAll = document.getElementById('selectAll');
    const bulkBtn = document.getElementById('bulkRemindBtn');
    const countEl = document.getElementById('selCount');
    const toast = document.getElementById('reminderToast');
    const rowBoxes = () => Array.from(document.querySelectorAll('.rowSelect'));

    function refresh(){
        const boxes = rowBoxes();
        const checked = boxes.filter(b => b.checked);
        if (countEl) countEl.textContent = checked.length;
        if (bulkBtn) bulkBtn.disabled = checked.length === 0;
        if (selectAll) selectAll.checked = boxes.length > 0 && checked.length === boxes.length;
    }
    if (selectAll) {
        selectAll.addEventListener('change', function(){
            rowBoxes().forEach(b => { b.checked = selectAll.checked; });
            refresh();
        });
    }
    rowBoxes().forEach(b => b.addEventListener('change', refresh));
    refresh();

    // ── REMINDER TOAST ──────────────────────────────────────
    function showToast(sent, skipped, errorMsg){
        if (!toast) return;
        toast.classList.toggle('has-skips', (skipped && skipped.length > 0) || !!errorMsg);
        if (errorMsg) {
            toast.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i><div><b>Couldn't send reminder</b>${escapeHtml(errorMsg)}</div>`;
        } else {
            let html = `<i class="fa-solid fa-paper-plane"></i><div><b>${sent.length} reminder${sent.length===1?'':'s'} sent</b>`;
            if (skipped && skipped.length) {
                html += `${skipped.length} skipped: ` + skipped.map(s => `${escapeHtml(s.name || 'Student #' + s.id)} (${escapeHtml(s.reason)})`).join('; ');
            } else {
                html += `Students will show as reminded below.`;
            }
            html += `</div>`;
            toast.innerHTML = html;
        }
        toast.style.display = 'flex';
    }
    function escapeHtml(str){
        const d = document.createElement('div');
        d.textContent = str == null ? '' : String(str);
        return d.innerHTML;
    }

    // No-JS fallback lands back here with ?reminder_sent=&reminder_skipped=
    // or ?reminder_error= — surface the same toast for that case.
    const params = new URLSearchParams(window.location.search);
    if (params.has('reminder_sent') || params.has('reminder_error')) {
        showToast(
            new Array(parseInt(params.get('reminder_sent') || '0', 10)).fill(0),
            new Array(parseInt(params.get('reminder_skipped') || '0', 10)).fill({id:0,name:null,reason:'skipped'}),
            params.get('reminder_error')
        );
    }

    // ── ROW STATE AFTER A SUCCESSFUL SEND ───────────────────
    function markRowReminded(studentId, sentAtLabel){
        const link = document.querySelector(`[data-remind-link][data-student-id="${studentId}"]`);
        if (!link) return;
        link.classList.remove('sending');
        link.classList.add('on-cooldown');
        link.setAttribute('aria-disabled', 'true');
        const label = link.querySelector('.remind-label');
        if (label) label.textContent = 'Reminded';
        const row = link.closest('tr');
        const meta = row ? row.querySelector('[data-remind-meta]') : null;
        if (meta) meta.textContent = 'Last: ' + sentAtLabel;
        const box = row ? row.querySelector('.rowSelect') : null;
        if (box) { box.checked = false; box.disabled = false; }
    }

    async function sendReminders(studentIds, triggerEl){
        if (!studentIds.length) return;
        if (triggerEl) { triggerEl.classList.add('sending'); triggerEl.disabled = true; }
        try {
            const res = await fetch('dean_send_reminder.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ student_ids: studentIds, csrf_token: CSRF_TOKEN })
            });
            const data = await res.json();
            if (!data.success) {
                showToast([], [], data.error || 'Something went wrong.');
                return;
            }
            data.sent.forEach(s => markRowReminded(s.id, s.sent_at));
            showToast(data.sent, data.skipped, null);
            refresh();
        } catch (e) {
            showToast([], [], 'Network error — please try again.');
        } finally {
            if (triggerEl) { triggerEl.classList.remove('sending'); triggerEl.disabled = false; }
        }
    }

    // Single-row "Send Reminder" links — progressively enhanced: without
    // JS the href still hits dean_send_reminder.php?student_id=… and
    // redirects back, so the feature works either way.
    document.querySelectorAll('[data-remind-link]').forEach(link => {
        link.addEventListener('click', function(e){
            if (link.classList.contains('on-cooldown')) { e.preventDefault(); return; }
            e.preventDefault();
            const id = parseInt(link.dataset.studentId, 10);
            link.classList.add('sending');
            sendReminders([id], link);
        });
    });

    // "Send Bulk Reminder" — selected checkboxes on the current page.
    if (bulkBtn) {
        bulkBtn.addEventListener('click', function(){
            const ids = rowBoxes().filter(b => b.checked).map(b => parseInt(b.value, 10));
            sendReminders(ids, bulkBtn);
        });
    }
})();
</script>
</body>
</html>
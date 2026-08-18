<?php
// principal_evaluation_tracker.php
// Redesigned to match dean_evaluation_tracker.php's actual design system
// and functional pattern (same underlying evaluation_tracker /
// evaluation_reminders schema, same filter-bar/stat-card/sortable-table/
// bulk-reminder UX) — kept in the Principal's amber theme instead of the
// Dean's violet, and scoped to Basic Ed (Grade 7-12) instead of College.
//
// Differences from the Dean version, on purpose:
//   - Grade Level (7-12, capped to this Principal's own JHS/SHS scope)
//     replaces Year Level as the single scope filter — no separate
//     School Level dropdown, matching Dean's one-filter-per-dimension
//     pattern instead of the two-dropdown layout from the earlier draft.
//   - No "ID Number" column — same reason as Dean's version: student
//     accounts don't collect one at registration, so it's not faked.
//   - Reminders write to evaluation_reminders(recipient_id, period_id,
//     created_at) — the SAME shared table/columns dean_send_reminder.php
//     uses (confirmed from its SELECT), not the different schema this
//     page used in a previous draft.
//   - This page still calls out to a companion `principal_send_reminder.php`
//     for the actual send, mirroring dean_send_reminder.php's contract
//     (POST JSON {student_ids, csrf_token} -> {success, sent[], skipped[]}
//     / GET ?student_id= fallback). That companion file is NOT included
//     here yet — see the note at the bottom of this response.

require_once 'principal_common.php';

$user_id = $_SESSION['user_id'];

const REMINDER_COOLDOWN_HOURS = 24; // must match principal_send_reminder.php

@$mysqli->query("
    CREATE TABLE IF NOT EXISTS evaluation_reminders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        recipient_id INT NOT NULL,
        period_id INT NOT NULL,
        sender_id INT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_recipient_period (recipient_id, period_id)
    )
");

// ── FILTER + SORT + PAGE INPUT (GET) ──────────────────────────────────
$search = trim($_GET['search'] ?? '');
$gradeFilter = trim($_GET['grade'] ?? '');
if ($gradeFilter !== '' && !in_array($gradeFilter, $scopeGrades, true)) $gradeFilter = '';
$status = trim($_GET['status'] ?? '');
if (!in_array($status, ['', 'submitted', 'pending'], true)) $status = '';

// Level filter (Junior High / Senior High) — only meaningful for a
// Principal whose own scope covers both ($myLevel === 'both'); a
// JHS-only or SHS-only Principal already has $scopeAcademicLevels
// pinned to a single level, so this stays empty/no-op for them.
$levelFilter = trim($_GET['level'] ?? '');
if (!in_array($levelFilter, ['', 'junior_high', 'senior_high'], true)) $levelFilter = '';
if ($myLevel !== 'both') $levelFilter = '';

$sortableColumns = ['name' => 'full_name', 'grade' => 'year_level'];
$sort = $_GET['sort'] ?? 'name';
if (!in_array($sort, array_merge(array_keys($sortableColumns), ['status']), true)) $sort = 'name';
$dir = (strtolower($_GET['dir'] ?? 'asc') === 'desc') ? 'desc' : 'asc';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$gradeOptions = [];
foreach ($scopeGrades as $g) $gradeOptions[$g] = "Grade {$g}";

$students = []; $pageStudents = [];
$studentsAssigned = 0; $studentsSubmitted = 0; $pendingStudents = 0; $remainingStudents = 0;
$completionPct = 0; $submittedPct = 0; $pendingPct = 0; $totalPages = 1;
$juniorHighCount = 0; $seniorHighCount = 0;

if ($structureActive) {
    // Junior/Senior High headline counts — always computed across the
    // Principal's full scope (ignores grade/level/status filters) so the
    // "All / Junior High / Senior High" tab badges and the two stat cards
    // stay stable while the table below is being filtered.
    // NOTE: students are matched by year_level (e.g. "Grade 7"), NOT by the
    // academic_level/grade_level columns. Those columns exist (added by the
    // self-healing schema above) but nothing in the registration or account
    // management flow ever writes to them — every student row has them as
    // NULL. year_level is the one field the accounts page actually keeps
    // current, so that's the single source of truth here too.
    if ($myLevel === 'both') {
        $juniorHighCount = (int)(safe_scalar($mysqli, "
            SELECT COUNT(*) c FROM users
            WHERE role='student' AND is_active=1 AND account_status='approved'
              AND year_level IN ('Grade 7','Grade 8','Grade 9','Grade 10')
        ") ?? 0);
        $seniorHighCount = (int)(safe_scalar($mysqli, "
            SELECT COUNT(*) c FROM users
            WHERE role='student' AND is_active=1 AND account_status='approved'
              AND year_level IN ('Grade 11','Grade 12')
        ") ?? 0);
    }

    // Narrow the Principal's full grade scope down by any grade/level filter,
    // then translate the surviving grade numbers into the canonical
    // "Grade N" strings that year_level actually stores.
    $targetGrades = $scopeGrades;
    if ($levelFilter === 'junior_high') $targetGrades = array_intersect($targetGrades, ['7', '8', '9', '10']);
    if ($levelFilter === 'senior_high') $targetGrades = array_intersect($targetGrades, ['11', '12']);
    if ($gradeFilter !== '') $targetGrades = array_intersect($targetGrades, [$gradeFilter]);
    $targetYearLevels = array_map(fn($g) => "Grade {$g}", array_values($targetGrades));

    $yearLevelInList = esc_list($mysqli, $targetYearLevels);
    $whereSql = "role='student' AND is_active=1 AND account_status='approved'
        AND year_level IN ($yearLevelInList)";
    $types = ''; $params = [];
    if ($search !== '') { $whereSql .= " AND full_name LIKE ?"; $types .= 's'; $params[] = '%' . $search . '%'; }

    $orderSql = ($sort !== 'status') ? ($sortableColumns[$sort] . ' ' . strtoupper($dir)) : 'full_name ASC';

    $allRows = safe_rows($mysqli, "
        SELECT id, full_name, year_level FROM users WHERE $whereSql ORDER BY $orderSql
    ", $types, $params);

    // ── SUBMISSION STATUS — one bulk query, same eval_type/status
    // semantics as Dean's (status IN submitted/approved, not just "any row").
    $submittedMap = [];
    $allIds = array_column($allRows, 'id');
    if ($hasPeriod && !empty($allIds)) {
        $ph = implode(',', array_fill(0, count($allIds), '?'));
        $subRows = safe_rows($mysqli, "
            SELECT evaluator_id, MAX(submitted_at) v FROM evaluation_tracker
            WHERE eval_type='student' AND status IN ('submitted','approved')
              AND period_id=? AND evaluator_id IN ($ph)
            GROUP BY evaluator_id
        ", 'i' . str_repeat('i', count($allIds)), array_merge([$period_id_int], $allIds));
        foreach ($subRows as $sr) { $submittedMap[(int)$sr['evaluator_id']] = $sr['v']; }
    }

    foreach ($allRows as $s) {
        $sid = (int)$s['id'];
        $hasSubmitted = isset($submittedMap[$sid]);
        $yl = $s['year_level'] ?? '';
        $gradeNum = null;
        if (preg_match('/^Grade\s*(7|8|9|10|11|12)\b/i', $yl, $m)) $gradeNum = $m[1];
        $academicLevel = in_array($gradeNum, ['7', '8', '9', '10'], true) ? 'junior_high'
            : (in_array($gradeNum, ['11', '12'], true) ? 'senior_high' : '');
        $students[] = [
            'id' => $sid, 'name' => $s['full_name'],
            'grade' => $yl !== '' ? $yl : '—',
            'academic_level' => $academicLevel,
            'status' => $hasSubmitted ? 'submitted' : 'pending',
            'submitted_at' => $submittedMap[$sid] ?? null,
        ];
    }

    if ($sort === 'status') {
        usort($students, function ($a, $b) use ($dir) {
            $cmp = strcmp($a['status'], $b['status']);
            return $dir === 'desc' ? -$cmp : $cmp;
        });
    }
    if ($status !== '') {
        $students = array_values(array_filter($students, fn($s) => $s['status'] === $status));
    }

    $studentsAssigned  = count($students);
    $studentsSubmitted = count(array_filter($students, fn($s) => $s['status'] === 'submitted'));
    $pendingStudents   = max(0, $studentsAssigned - $studentsSubmitted);
    $remainingStudents = $pendingStudents;
    $completionPct     = $studentsAssigned > 0 ? (int) round($studentsSubmitted / $studentsAssigned * 100) : 0;
    $submittedPct      = $completionPct;
    $pendingPct        = $studentsAssigned > 0 ? (100 - $completionPct) : 0;

    // ── EXPORT (full filtered set, ignores pagination) — before any output.
    if (($_GET['export'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="principal_tracker_export_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Student', 'Level', 'Grade Level', 'Status', 'Submitted At']);
        foreach ($students as $s) {
            $levelLabel = $s['academic_level'] === 'senior_high' ? 'Senior High' : ($s['academic_level'] === 'junior_high' ? 'Junior High' : '');
            fputcsv($out, [$s['name'], $levelLabel, $s['grade'], $s['status'], $s['submitted_at'] ?: '']);
        }
        fclose($out);
        $mysqli->close();
        exit;
    }

    $totalPages   = max(1, (int)ceil($studentsAssigned / $perPage));
    $page         = max(1, min($totalPages, $page));
    $pageStudents = array_slice($students, ($page - 1) * $perPage, $perPage);

    // ── LAST REMINDER SENT (page rows only) ──────────────────────────
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

$scopeLabel = $gradeFilter !== '' ? "Grade {$gradeFilter}" : ($myLevel === 'both' ? 'Junior High & Senior High' : ($myLevel === 'junior_high' ? 'Junior High School' : 'Senior High School'));

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
function tracker_initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $first = $parts[0][0] ?? '';
    $last  = count($parts) > 1 ? $parts[count($parts) - 1][0] : '';
    return strtoupper($first . $last);
}
function tracker_avatar_color(string $name): string {
    $palette = ['#d99a2b', '#2563EB', '#10B981', '#EA580C', '#DB2777', '#0891B2', '#7C5FD9'];
    return $palette[crc32($name) % count($palette)];
}

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
:root{--dark:#0A192F;--mid:#172A45;--inner:#0F1F3D;--amber:#d99a2b;--amber-h:#f0b84d;--amber-dark:#b8801f;--light:#E0E6F0;--muted:#A0B3C6;--radius:10px;--shadow:0 8px 32px rgba(0,0,0,0.45);--danger:#f05454;--good:#10B981;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{min-height:100vh;background:var(--dark);font-family:'DM Sans',sans-serif;color:var(--light);display:flex;}

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

.main{flex:1;padding:36px 44px;}
.page-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:22px;flex-wrap:wrap;gap:14px;}
.page-title{font-family:'Rajdhani',sans-serif;font-size:30px;font-weight:700;color:#fff;letter-spacing:1px;}
.page-sub{font-size:13px;color:var(--muted);margin-top:4px;}

.period-badge{background:rgba(217,154,43,.14);border:1px solid rgba(217,154,43,.3);color:var(--amber-h);padding:8px 16px;border-radius:20px;font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px;}
.period-badge.closed{background:rgba(240,84,84,.1);border-color:rgba(240,84,84,.3);color:#fca5a5;}
.period-badge.amber{background:rgba(217,119,6,.14);border-color:rgba(217,119,6,.3);color:#fbbf24;}
.period-badge.gray{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.12);color:var(--muted);}

.structure-note{display:flex;align-items:flex-start;gap:14px;padding:18px 20px;background:rgba(217,154,43,.08);border:1px solid rgba(217,154,43,.25);border-radius:12px;margin-bottom:26px;}
.structure-note i{color:var(--amber-h);font-size:20px;margin-top:2px;}
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
.filter-field select:focus, .filter-field input:focus{border-color:var(--amber);}
.btn-apply{background:var(--amber);border:none;color:#0A192F;font-size:13px;font-weight:700;padding:10px 18px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;font-family:'DM Sans',sans-serif;box-shadow:0 4px 14px rgba(217,154,43,.35);transition:background .2s;height:38px;}
.btn-apply:hover{background:var(--amber-h);}
.btn-reset{background:transparent;border:1px solid rgba(255,255,255,.16);color:var(--muted);font-size:13px;font-weight:700;padding:10px 16px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;font-family:'DM Sans',sans-serif;height:38px;text-decoration:none;transition:all .2s;}
.btn-reset:hover{color:var(--light);border-color:rgba(255,255,255,.3);}

.card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:16px;margin-bottom:26px;}
.stat-card{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:20px;box-shadow:var(--shadow);}
.stat-card i{color:var(--amber-h);font-size:20px;margin-bottom:10px;}
.stat-card .num{font-size:28px;font-weight:700;color:#fff;}
.stat-card .label{font-size:12px;color:var(--muted);margin-top:4px;}
.stat-card .caption{font-size:11px;color:var(--muted);opacity:.75;margin-top:2px;font-style:italic;}

.section{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:24px;box-shadow:var(--shadow);margin-bottom:26px;}
.section-head{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;}
.section h2{font-family:'Rajdhani',sans-serif;font-size:19px;color:#fff;display:flex;align-items:center;gap:8px;}
.section h2 i{color:var(--amber-h);font-size:16px;}
.count-note{font-size:12px;color:var(--muted);}
.export-btn{background:rgba(217,154,43,.12);border:1px solid rgba(217,154,43,.35);color:var(--amber-h);padding:9px 14px;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:7px;transition:background .2s;}
.export-btn:hover{background:rgba(217,154,43,.22);}

.stub-note{font-size:11.5px;color:var(--amber-h);background:rgba(217,154,43,.08);border:1px dashed rgba(217,154,43,.35);border-radius:8px;padding:10px 14px;margin-bottom:16px;}

.level-tabs{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;}
.level-tabs a{background:rgba(217,154,43,.12);border:1px solid rgba(217,154,43,.35);color:var(--amber-h);padding:9px 18px;border-radius:30px;font-size:12.5px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:7px;transition:background .2s;}
.level-tabs a:hover{background:rgba(217,154,43,.22);}
.level-tabs a.active{background:rgba(217,154,43,.32);color:#fff;}
.level-tabs a span{background:rgba(255,255,255,.15);border-radius:20px;padding:1px 9px;margin-left:2px;font-size:11px;}

.bulk-banner{display:flex;align-items:flex-start;gap:12px;background:rgba(217,154,43,.1);border:1px solid rgba(217,154,43,.3);border-radius:12px;padding:16px 20px;margin-bottom:16px;}
.bulk-banner i{color:var(--amber-h);font-size:16px;margin-top:2px;}
.bulk-banner b{color:#fff;display:block;margin-bottom:2px;font-size:13px;}
.bulk-banner p{font-size:12.5px;color:var(--muted);}
.bulk-banner > div{flex:1;}
.btn-bulk-remind{background:var(--amber);border:none;color:#0A192F;padding:9px 16px;border-radius:8px;font-size:12.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:7px;white-space:nowrap;transition:background .2s;}
.btn-bulk-remind:hover:not(:disabled){background:var(--amber-dark);}
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
.level-pill{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:rgba(37,99,235,.14);color:#60a5fa;}
.level-pill.senior{background:rgba(124,95,217,.16);color:#a78bfa;}
.status-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.status-pill.submitted{background:rgba(16,185,129,.14);color:var(--good);}
.status-pill.pending{background:rgba(160,179,198,.14);color:var(--muted);}
.btn-remind{background:var(--amber);border:none;color:#0A192F;padding:6px 12px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:background .2s;}
.btn-remind:hover{background:var(--amber-dark);}
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
.page-btn.active{background:var(--amber);color:#0A192F;border-color:var(--amber);}
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

<aside class="sidebar">
    <div class="sb-profile">
        <img class="sb-photo" src="<?= htmlspecialchars($photo_src) ?>" alt="Profile"/>
        <div class="sb-name"><?= htmlspecialchars($me['full_name'] ?? 'Principal') ?></div>
        <div class="sb-role"><?= htmlspecialchars($me['designation'] ?? 'Principal') ?></div>
        <div class="sb-scope"><?= htmlspecialchars($scopeLabel) ?></div>
    </div>
    <nav class="sb-nav">
        <a href="principal_dashboard.php"><i class="fa-solid fa-gauge"></i> Dashboard</a>
        <a href="principal_evaluations.php"><i class="fa-solid fa-clipboard-list"></i> Evaluation</a>
        <a href="principal_evaluation_tracker.php" class="active"><i class="fa-solid fa-satellite-dish"></i> Evaluation Tracker</a>
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
            <div class="page-title">Evaluation Tracker</div>
            <div class="page-sub">Monitor <?= BASIC_ED_LABEL ?> student evaluation participation.</div>
        </div>
        <?php render_period_badge($settings); ?>
    </div>

    <?php if (!$structureActive): ?>
    <?php render_scope_status($settings, 'tracker'); ?>
    <?php else: ?>

    <!-- FILTER BAR -->
    <form class="filter-bar" method="GET" action="principal_evaluation_tracker.php">
        <div class="filter-field">
            <label for="search">Search</label>
            <div class="search-icon-wrap">
                <input type="text" id="search" name="search" placeholder="Student name..." value="<?= htmlspecialchars($search) ?>"/>
                <i class="fa-solid fa-magnifying-glass"></i>
            </div>
        </div>
        <div class="filter-field">
            <label for="grade">Grade Level</label>
            <select name="grade" id="grade">
                <option value="">All Grades</option>
                <?php foreach ($gradeOptions as $val => $lbl): ?>
                    <option value="<?= htmlspecialchars($val) ?>" <?= $gradeFilter === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
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
        <?php if ($levelFilter !== ''): ?>
        <input type="hidden" name="level" value="<?= htmlspecialchars($levelFilter) ?>"/>
        <?php endif; ?>
        <button type="submit" class="btn-apply"><i class="fa-solid fa-filter"></i> Apply</button>
        <a href="principal_evaluation_tracker.php" class="btn-reset"><i class="fa-solid fa-rotate-left"></i> Reset</a>
    </form>

    <!-- STAT CARDS -->
    <div class="card-grid">
        <?php if ($myLevel === 'both'): ?>
        <div class="stat-card"><i class="fa-solid fa-child-reaching"></i><div class="num"><?= $juniorHighCount ?></div><div class="label">Junior High Students</div><div class="caption">Grades 7–10</div></div>
        <div class="stat-card"><i class="fa-solid fa-user-graduate"></i><div class="num"><?= $seniorHighCount ?></div><div class="label">Senior High Students</div><div class="caption">Grades 11–12</div></div>
        <?php endif; ?>
        <div class="stat-card"><i class="fa-solid fa-users"></i><div class="num"><?= $studentsAssigned ?></div><div class="label">Students Assigned</div><div class="caption">All filtered students</div></div>
        <div class="stat-card"><i class="fa-solid fa-circle-check"></i><div class="num"><?= $studentsSubmitted ?></div><div class="label">Students Submitted</div><div class="caption"><?= $submittedPct ?>% of total</div></div>
        <div class="stat-card"><i class="fa-solid fa-hourglass-half"></i><div class="num"><?= $pendingStudents ?></div><div class="label">Pending Students</div><div class="caption"><?= $pendingPct ?>% of total</div></div>
        <div class="stat-card"><i class="fa-solid fa-chart-simple"></i><div class="num"><?= $completionPct ?>%</div><div class="label">Completion %</div><div class="caption">Overall completion</div></div>
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

        <?php if ($myLevel === 'both'): ?>
        <div class="level-tabs">
            <a href="<?= tracker_qs(['level' => '']) ?>" class="<?= $levelFilter === '' ? 'active' : '' ?>">All <span><?= $juniorHighCount + $seniorHighCount ?></span></a>
            <a href="<?= tracker_qs(['level' => 'junior_high']) ?>" class="<?= $levelFilter === 'junior_high' ? 'active' : '' ?>">Junior High <span><?= $juniorHighCount ?></span></a>
            <a href="<?= tracker_qs(['level' => 'senior_high']) ?>" class="<?= $levelFilter === 'senior_high' ? 'active' : '' ?>">Senior High <span><?= $seniorHighCount ?></span></a>
        </div>
        <?php endif; ?>

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
                        <?php if ($myLevel === 'both'): ?>
                        <th>Level</th>
                        <?php endif; ?>
                        <th><a href="<?= tracker_sort_url('grade', $sort, $dir) ?>">Grade Level <i class="fa-solid <?= tracker_sort_icon('grade', $sort, $dir) ?>"></i></a></th>
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
                        <?php if ($myLevel === 'both'): ?>
                        <td><span class="level-pill<?= $s['academic_level'] === 'senior_high' ? ' senior' : '' ?>"><?= $s['academic_level'] === 'senior_high' ? 'Senior High' : 'Junior High' ?></span></td>
                        <?php endif; ?>
                        <td><?= htmlspecialchars($s['grade']) ?></td>
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
                               href="principal_send_reminder.php?student_id=<?= $s['id'] ?>&csrf_token=<?= urlencode($_SESSION['csrf_token']) ?>"
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
        <div class="info-banner <?= ($settings['is_open_for_submission'] ?? false) ? '' : 'closed' ?>">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <b>Student evaluation is currently <?= ($settings['is_open_for_submission'] ?? false) ? 'open' : 'closed' ?>.</b>
                <p><?= ($settings['is_open_for_submission'] ?? false)
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

        const params = new URLSearchParams(window.location.search);
        if (params.has('reminder_sent') || params.has('reminder_error')) {
            showToast(
                new Array(parseInt(params.get('reminder_sent') || '0', 10)).fill(0),
                new Array(parseInt(params.get('reminder_skipped') || '0', 10)).fill({id:0,name:null,reason:'skipped'}),
                params.get('reminder_error')
            );
    }

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
            const res = await fetch('principal_send_reminder.php', {
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

    document.querySelectorAll('[data-remind-link]').forEach(link => {
        link.addEventListener('click', function(e){
            if (link.classList.contains('on-cooldown')) { e.preventDefault(); return; }
            e.preventDefault();
            const id = parseInt(link.dataset.studentId, 10);
            link.classList.add('sending');
            sendReminders([id], link);
        });
    });

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
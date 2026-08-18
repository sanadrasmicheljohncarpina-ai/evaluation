<?php
// dean/dean_faculty.php
// Dean Faculty Roster — College Division
// Same architecture as dean_dashboard.php / dean_evaluation_tracker.php
// (self-healing schema, safe_scalar/safe_rows exception-catching, same
// dark theme, College-only scope).
//
// ── COLLEGE SCOPING (fixed — see dean_dashboard.php for the full writeup) ─
// Teacher/staff college-membership does NOT live in a flat `academic_level`
// column on `users` (confirmed empty for role='teacher'/'staff' via
// `SELECT role, education_level, COUNT(*) FROM users GROUP BY role,
// education_level`). It lives in the `user_year_levels` junction table,
// written by the Super Admin's "Assign Year Levels" action in
// manage_privileged_accounts.php. The roster query below now checks that
// table via EXISTS instead of the old (always-empty) academic_level column.
//
// ── SCHEMA (confirmed via phpMyAdmin structure view, Aug 2026) ─────────
// evaluation_tracker.score is used directly for ratings — no join to a
// separate questionnaire_answers table. Every tracker query filters:
//   - status IN ('submitted','approved')  — excludes draft/archived rows
//   - eval_bucket = 'Faculty'             — excludes non-faculty buckets
//   - level = 'college'                   — belt-and-suspenders JHS/SHS guard
//
// ── REMAINING ASSUMPTIONS / KNOWN GAPS (flag these back to me if wrong) ─
// 1. Stats shown here (evaluations received, avg rating) are scoped to
//    the currently active evaluation_periods row — this is a roster
//    "this period's standing" view, not lifetime history. A per-faculty
//    history/profile page would need its own drill-down (not built yet —
//    the "View" action below is a placeholder until that page exists).
// 2. Same College-only scope as the dashboard throughout.
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

// ── AUTH GUARD ────────────────────────────────────────────
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'dean') {
    header("Location: dean_login.php");
    exit;
}

const COLLEGE_LEVELS = ['1st Year College', '2nd Year College', '3rd Year College', '4th Year College'];

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

const HIGHER_ED_LABEL = 'Higher Education';

// ── FACULTY ROSTER (College-scoped via user_year_levels) ───────────────
$collegePlaceholders = implode(',', array_fill(0, count(COLLEGE_LEVELS), '?'));

$roster = [];
$ratedCount = 0;
$ratingSum = 0.0;

if ($structureActive) {
    $facultyRows = safe_rows($mysqli, "
        SELECT id, full_name, photo, employee_id, department, course, designation
        FROM users
        WHERE role='teacher' AND is_active=1 AND account_status='approved'
          AND EXISTS (
              SELECT 1 FROM user_year_levels uyl
              WHERE uyl.user_id = users.id AND uyl.year_level IN ($collegePlaceholders)
          )
        ORDER BY full_name
    ", str_repeat('s', count(COLLEGE_LEVELS)), COLLEGE_LEVELS);

    foreach ($facultyRows as $row) {
        $fid = (int)$row['id'];

        $received = $hasPeriod ? (int)(safe_scalar($mysqli, "
            SELECT COUNT(DISTINCT evaluator_id) c FROM evaluation_tracker
            WHERE eval_type='student' AND eval_bucket='Faculty' AND level='college'
              AND status IN ('submitted','approved')
              AND period_id=? AND target_user_id=?
        ", "ii", [$period_id_int, $fid]) ?? 0) : 0;

        $avgRating = $hasPeriod ? safe_scalar($mysqli, "
            SELECT AVG(score) v FROM evaluation_tracker
            WHERE eval_type='student' AND eval_bucket='Faculty' AND level='college'
              AND status IN ('submitted','approved')
              AND target_user_id=? AND period_id=?
        ", "ii", [$fid, $period_id_int]) : null;

        $avg = $avgRating !== null ? round((float)$avgRating, 2) : null;
        if ($avg !== null) { $ratedCount++; $ratingSum += $avg; }

        $roster[] = [
            'id'          => $fid,
            'name'        => $row['full_name'],
            'photo'       => !empty($row['photo']) ? UPLOAD_URL . $row['photo'] : UPLOAD_URL . 'pbi_logo',
            'employee_id' => $row['employee_id'] ?: '—',
            'department'  => $row['department'] ?: '—',
            'course'      => $row['course'] ?: '—',
            'designation' => $row['designation'] ?: '—',
            'received'    => $received,
            'avg'         => $avg,
            'status'      => $received > 0 ? 'completed' : 'pending',
        ];
    }
}

$totalFaculty  = count($roster);
$deptOptions   = array_values(array_unique(array_filter(array_map(fn($f) => $f['department'], $roster), fn($d) => $d !== '—')));
sort($deptOptions);
$progOptions   = array_values(array_unique(array_filter(array_map(fn($f) => $f['course'], $roster), fn($c) => $c !== '—')));
sort($progOptions);
$deptCount     = count($deptOptions);
$progCount     = count($progOptions);
$overallAvg    = $ratedCount > 0 ? round($ratingSum / $ratedCount, 2) : null;

$mysqli->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI — Faculty</title>
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
.page-header{margin-bottom:26px;}
.page-title{font-family:'Rajdhani',sans-serif;font-size:30px;font-weight:700;color:#fff;letter-spacing:1px;}
.page-sub{font-size:13px;color:var(--muted);margin-top:4px;}

.structure-note{display:flex;align-items:flex-start;gap:14px;padding:18px 20px;background:rgba(124,95,217,.08);border:1px solid rgba(124,95,217,.25);border-radius:12px;margin-bottom:26px;}
.structure-note i{color:var(--violet-h);font-size:20px;margin-top:2px;}
.structure-note p{font-size:13px;color:var(--light);line-height:1.6;}
.structure-note p b{color:#fff;}

.card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:22px;}
.stat-card{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:20px;box-shadow:var(--shadow);}
.stat-card i{color:var(--violet-h);font-size:20px;margin-bottom:10px;}
.stat-card .num{font-size:28px;font-weight:700;color:#fff;}
.stat-card .label{font-size:12px;color:var(--muted);margin-top:4px;}

.section{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:24px;box-shadow:var(--shadow);margin-bottom:26px;}
.section h2{font-family:'Rajdhani',sans-serif;font-size:19px;color:#fff;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.section h2 i{color:var(--violet-h);font-size:16px;}

.toolbar{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:18px;align-items:center;}
.toolbar .search-wrap{position:relative;flex:1;min-width:220px;}
.toolbar .search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px;}
.toolbar input[type=text],.toolbar select{background:rgba(10,25,47,.7);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:var(--light);font-size:13px;font-family:'DM Sans',sans-serif;padding:9px 12px;outline:none;}
.toolbar input[type=text]{padding-left:34px;width:100%;}
.toolbar input[type=text]:focus,.toolbar select:focus{border-color:var(--violet);}
.toolbar select{cursor:pointer;}
.toolbar .count-note{font-size:12px;color:var(--muted);margin-left:auto;white-space:nowrap;}

table.data{width:100%;border-collapse:collapse;font-size:13px;}
table.data th{text-align:left;color:var(--muted);font-weight:600;padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.08);text-transform:uppercase;font-size:11px;letter-spacing:.4px;cursor:pointer;user-select:none;white-space:nowrap;}
table.data th:hover{color:var(--light);}
table.data th .fa-sort,table.data th .fa-sort-up,table.data th .fa-sort-down{font-size:10px;margin-left:4px;color:var(--muted);}
table.data td{padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.05);vertical-align:middle;}
table.data tr:last-child td{border-bottom:none;}
table.data tr.hidden-row{display:none;}
.fac-name{display:flex;align-items:center;gap:10px;}
.fac-avatar{width:32px;height:32px;border-radius:50%;object-fit:cover;border:1.5px solid rgba(124,95,217,.4);flex-shrink:0;}
.pill{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;}
.pill.good{background:rgba(16,185,129,.14);color:var(--good);}
.pill.warn{background:rgba(124,95,217,.14);color:var(--violet-h);}
.view-link{color:var(--violet-h);font-size:12px;font-weight:600;text-decoration:none;}
.view-link:hover{text-decoration:underline;}
.no-results{color:var(--muted);font-size:13px;font-style:italic;padding:16px 0;text-align:center;}
.empty-note{color:var(--muted);font-size:13px;font-style:italic;}

@media(max-width:768px){body{flex-direction:column;}.sidebar{width:100%;min-height:auto;}}
</style>
</head>
<body>

<?php
$active = 'faculty';
$sidebarScope = 'College Division';
include __DIR__ . '/includes/dean_sidebar.php';
?>

<main class="main">
    <div class="page-header">
        <div class="page-title">Faculty</div>
        <div class="page-sub">College Division roster — <?= htmlspecialchars($settings['academic_year']) ?> · <?= htmlspecialchars($settings['academic_term']) ?><?= $hasPeriod ? '' : ' (no active evaluation period)' ?></div>
    </div>

    <?php if (!$structureActive): ?>
    <div class="structure-note">
        <i class="fa-solid fa-circle-info"></i>
        <p>
            <b><?= HIGHER_ED_LABEL ?> is not the active academic structure.</b><br>
            The current evaluation period is configured for <b><?= htmlspecialchars($settings['academic_structure_label']) ?></b>.
            The faculty roster is unavailable until the Executive Assistant switches it back.
        </p>
    </div>
    <?php else: ?>

    <!-- STATS -->
    <div class="card-grid">
        <div class="stat-card"><i class="fa-solid fa-chalkboard-user"></i><div class="num"><?= $totalFaculty ?></div><div class="label">Total Faculty</div></div>
        <div class="stat-card"><i class="fa-solid fa-building-columns"></i><div class="num"><?= $deptCount ?></div><div class="label">Departments</div></div>
        <div class="stat-card"><i class="fa-solid fa-book"></i><div class="num"><?= $progCount ?></div><div class="label">Programs</div></div>
        <div class="stat-card"><i class="fa-solid fa-star"></i><div class="num"><?= $overallAvg !== null ? $overallAvg : '—' ?></div><div class="label">Overall Avg Rating</div></div>
    </div>

    <!-- FACULTY TABLE -->
    <div class="section">
        <h2><i class="fa-solid fa-chalkboard-user"></i> Faculty Directory</h2>

        <?php if (empty($roster)): ?>
            <p class="empty-note">No college faculty accounts found yet. Assign a College year level to a teacher account from Manage Registrations to have them appear here.</p>
        <?php else: ?>

        <div class="toolbar">
            <div class="search-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="searchInput" placeholder="Search by name or employee ID...">
            </div>
            <select id="deptFilter">
                <option value="">All Departments</option>
                <?php foreach ($deptOptions as $d): ?>
                    <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="progFilter">
                <option value="">All Programs</option>
                <?php foreach ($progOptions as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="statusFilter">
                <option value="">All Statuses</option>
                <option value="completed">Has Evaluations</option>
                <option value="pending">Pending</option>
            </select>
            <span class="count-note" id="countNote"><?= count($roster) ?> of <?= count($roster) ?> faculty</span>
        </div>

        <table class="data" id="facultyTable">
            <thead>
                <tr>
                    <th data-key="name" data-type="text">Faculty <i class="fa-solid fa-sort"></i></th>
                    <th data-key="employee_id" data-type="text">Employee ID <i class="fa-solid fa-sort"></i></th>
                    <th data-key="department" data-type="text">Department <i class="fa-solid fa-sort"></i></th>
                    <th data-key="course" data-type="text">Program <i class="fa-solid fa-sort"></i></th>
                    <th data-key="designation" data-type="text">Designation <i class="fa-solid fa-sort"></i></th>
                    <th data-key="received" data-type="num">Evaluations <i class="fa-solid fa-sort"></i></th>
                    <th data-key="avg" data-type="num">Avg Rating <i class="fa-solid fa-sort"></i></th>
                    <th data-key="status" data-type="text">Status <i class="fa-solid fa-sort"></i></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($roster as $f): ?>
                <tr data-name="<?= htmlspecialchars(strtolower($f['name'])) ?>"
                    data-employee_id="<?= htmlspecialchars(strtolower($f['employee_id'])) ?>"
                    data-department="<?= htmlspecialchars($f['department']) ?>"
                    data-course="<?= htmlspecialchars($f['course']) ?>"
                    data-designation="<?= htmlspecialchars($f['designation']) ?>"
                    data-status="<?= $f['status'] ?>"
                    data-received="<?= $f['received'] ?>"
                    data-avg="<?= $f['avg'] ?? -1 ?>">
                    <td class="fac-name">
                        <img class="fac-avatar" src="<?= htmlspecialchars($f['photo']) ?>" alt=""/>
                        <?= htmlspecialchars($f['name']) ?>
                    </td>
                    <td><?= htmlspecialchars($f['employee_id']) ?></td>
                    <td><?= htmlspecialchars($f['department']) ?></td>
                    <td><?= htmlspecialchars($f['course']) ?></td>
                    <td><?= htmlspecialchars($f['designation']) ?></td>
                    <td><?= $f['received'] ?></td>
                    <td><?= $f['avg'] !== null ? $f['avg'] : '<span class="empty-note">N/A</span>' ?></td>
                    <td>
                        <?php if ($f['status'] === 'completed'): ?>
                            <span class="pill good">Evaluated</span>
                        <?php else: ?>
                            <span class="pill warn">Pending</span>
                        <?php endif; ?>
                    </td>
                    <td><a href="#" class="view-link" title="Faculty profile page coming soon">View</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="no-results" id="noResults" style="display:none;">No faculty match the current filters.</p>

        <?php endif; ?>
    </div>

    <?php endif; ?>
</main>

<script>
const searchInput  = document.getElementById('searchInput');
const deptFilter    = document.getElementById('deptFilter');
const progFilter    = document.getElementById('progFilter');
const statusFilter  = document.getElementById('statusFilter');
const table         = document.getElementById('facultyTable');
const countNote     = document.getElementById('countNote');
const noResults     = document.getElementById('noResults');

function applyFilters() {
    if (!table) return;
    const rows = table.querySelectorAll('tbody tr');
    const q = (searchInput.value || '').toLowerCase().trim();
    const dept = deptFilter.value;
    const prog = progFilter.value;
    const status = statusFilter.value;
    let visible = 0;

    rows.forEach(row => {
        const matchesSearch = !q || row.dataset.name.includes(q) || row.dataset.employee_id.includes(q);
        const matchesDept   = !dept || row.dataset.department === dept;
        const matchesProg   = !prog || row.dataset.course === prog;
        const matchesStatus = !status || row.dataset.status === status;
        const show = matchesSearch && matchesDept && matchesProg && matchesStatus;
        row.classList.toggle('hidden-row', !show);
        if (show) visible++;
    });

    countNote.textContent = `${visible} of ${rows.length} faculty`;
    noResults.style.display = visible === 0 ? 'block' : 'none';
    table.style.display = visible === 0 ? 'none' : 'table';
}

[searchInput, deptFilter, progFilter, statusFilter].forEach(el => {
    if (el) el.addEventListener('input', applyFilters);
});

// Sortable columns
let sortState = { key: null, dir: 1 };
document.querySelectorAll('#facultyTable th[data-key]').forEach(th => {
    th.addEventListener('click', () => {
        const key = th.dataset.key;
        const type = th.dataset.type;
        sortState.dir = (sortState.key === key) ? -sortState.dir : 1;
        sortState.key = key;

        document.querySelectorAll('#facultyTable th[data-key] i').forEach(i => i.className = 'fa-solid fa-sort');
        th.querySelector('i').className = sortState.dir === 1 ? 'fa-solid fa-sort-up' : 'fa-solid fa-sort-down';

        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        rows.sort((a, b) => {
            let av = a.dataset[key], bv = b.dataset[key];
            if (type === 'num') { av = parseFloat(av); bv = parseFloat(bv); }
            if (av < bv) return -1 * sortState.dir;
            if (av > bv) return 1 * sortState.dir;
            return 0;
        });
        rows.forEach(r => tbody.appendChild(r));
    });
});

applyFilters();
</script>
</body>
</html>
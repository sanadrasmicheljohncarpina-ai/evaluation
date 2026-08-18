<?php
// dean/dean_staff.php
// Dean Staff Directory — College Division
// Same architecture as dean_faculty.php / dean_dashboard.php (self-healing
// schema helpers, safe_scalar/safe_rows exception-catching, dark theme,
// College-only scope) — with one deliberate correction carried over from
// the dashboard fix:
//
// ── COLLEGE SCOPING (see dean_dashboard.php for the full writeup) ──────
// Teacher/staff college-membership does NOT live in a flat `academic_level`
// column on `users` (confirmed empty for role='teacher'/'staff' via
// `SELECT role, education_level, COUNT(*) FROM users GROUP BY role,
// education_level`). It lives in the `user_year_levels` junction table,
// written by the Super Admin's "Assign Year Levels" action in
// manage_privileged_accounts.php. Every query below that needs "is this
// staff member College?" checks that table via EXISTS/JOIN instead.
//
// ── KNOWN GAP ────────────────────────────────────────────────────────
// No evaluation_tracker rows exist for role='staff' — there's no eval
// model for staff yet (matches the note already on the Dashboard's Staff
// Overview section). This page is a directory + year-level/period view
// only, no ratings/completion columns. The "View" action is a placeholder
// until a staff profile/history page exists.
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

// ── STAFF DIRECTORY (College-scoped via user_year_levels) ──────────────
$collegePlaceholders = implode(',', array_fill(0, count(COLLEGE_LEVELS), '?'));

$staffRowsRaw = safe_rows($mysqli, "
    SELECT id, full_name, photo, employee_id, department, designation, assigned_period
    FROM users
    WHERE role='staff' AND is_active=1 AND account_status='approved'
      AND EXISTS (
          SELECT 1 FROM user_year_levels uyl
          WHERE uyl.user_id = users.id AND uyl.year_level IN ($collegePlaceholders)
      )
    ORDER BY full_name
", str_repeat('s', count(COLLEGE_LEVELS)), COLLEGE_LEVELS);

// Batch-fetch each staff member's assigned year level(s) for display —
// same shape as manage_privileged_accounts.php's $ylByUser mapping.
$ylByUser = [];
if (!empty($staffRowsRaw)) {
    $ids = array_column($staffRowsRaw, 'id');
    $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
    $ylRows = safe_rows($mysqli, "
        SELECT user_id, year_level FROM user_year_levels
        WHERE user_id IN ($idPlaceholders)
          AND year_level IN ($collegePlaceholders)
        ORDER BY year_level ASC
    ", str_repeat('i', count($ids)) . str_repeat('s', count(COLLEGE_LEVELS)), array_merge($ids, COLLEGE_LEVELS));
    foreach ($ylRows as $row) {
        $ylByUser[$row['user_id']][] = $row['year_level'];
    }
}

$roster = [];
foreach ($staffRowsRaw as $row) {
    $sid = (int)$row['id'];
    $roster[] = [
        'id'              => $sid,
        'name'            => $row['full_name'],
        'photo'           => !empty($row['photo']) ? UPLOAD_URL . $row['photo'] : UPLOAD_URL . 'pbi_logo',
        'employee_id'     => $row['employee_id'] ?: '—',
        'department'      => $row['department'] ?: '—',
        'designation'     => $row['designation'] ?: '—',
        'year_levels'     => $ylByUser[$sid] ?? [],
        'assigned_period' => $row['assigned_period'] ?: '—',
    ];
}

$totalStaff  = count($roster);
$deptOptions = array_values(array_unique(array_filter(array_map(fn($s) => $s['department'], $roster), fn($d) => $d !== '—')));
sort($deptOptions);
$periodOptions = array_values(array_unique(array_filter(array_map(fn($s) => $s['assigned_period'], $roster), fn($p) => $p !== '—')));
sort($periodOptions);
$deptCount = count($deptOptions);

$mysqli->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI — Staff</title>
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
.yl-pill{display:inline-flex;align-items:center;gap:5px;padding:2px 9px;border-radius:20px;font-size:10.5px;font-weight:700;background:rgba(43,108,176,.15);color:#93c5fd;margin:1px 3px 1px 0;white-space:nowrap;}
.view-link{color:var(--violet-h);font-size:12px;font-weight:600;text-decoration:none;}
.view-link:hover{text-decoration:underline;}
.no-results{color:var(--muted);font-size:13px;font-style:italic;padding:16px 0;text-align:center;}
.empty-note{color:var(--muted);font-size:13px;font-style:italic;}

@media(max-width:768px){body{flex-direction:column;}.sidebar{width:100%;min-height:auto;}}
</style>
</head>
<body>

<?php
$active = 'staff';
$sidebarScope = 'College Division';
include __DIR__ . '/includes/dean_sidebar.php';
?>

<main class="main">
    <div class="page-header">
        <div class="page-title">Staff</div>
        <div class="page-sub">College Division directory — no evaluation tracking yet, directory view only</div>
    </div>

    <!-- STATS -->
    <div class="card-grid">
        <div class="stat-card"><i class="fa-solid fa-id-badge"></i><div class="num"><?= $totalStaff ?></div><div class="label">Total Staff</div></div>
        <div class="stat-card"><i class="fa-solid fa-building-columns"></i><div class="num"><?= $deptCount ?></div><div class="label">Departments</div></div>
    </div>

    <!-- STAFF TABLE -->
    <div class="section">
        <h2><i class="fa-solid fa-id-badge"></i> Staff Directory</h2>

        <?php if (empty($roster)): ?>
            <p class="empty-note">No college staff accounts found yet. Assign a College year level to a staff account from Manage Registrations to have them appear here.</p>
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
            <select id="periodFilter">
                <option value="">All Periods</option>
                <?php foreach ($periodOptions as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="count-note" id="countNote"><?= count($roster) ?> of <?= count($roster) ?> staff</span>
        </div>

        <table class="data" id="staffTable">
            <thead>
                <tr>
                    <th data-key="name" data-type="text">Staff <i class="fa-solid fa-sort"></i></th>
                    <th data-key="employee_id" data-type="text">Employee ID <i class="fa-solid fa-sort"></i></th>
                    <th data-key="department" data-type="text">Department <i class="fa-solid fa-sort"></i></th>
                    <th data-key="designation" data-type="text">Designation <i class="fa-solid fa-sort"></i></th>
                    <th>Year Level(s)</th>
                    <th data-key="period" data-type="text">Period <i class="fa-solid fa-sort"></i></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($roster as $s): ?>
                <tr data-name="<?= htmlspecialchars(strtolower($s['name'])) ?>"
                    data-employee_id="<?= htmlspecialchars(strtolower($s['employee_id'])) ?>"
                    data-department="<?= htmlspecialchars($s['department']) ?>"
                    data-designation="<?= htmlspecialchars($s['designation']) ?>"
                    data-period="<?= htmlspecialchars($s['assigned_period']) ?>">
                    <td class="fac-name">
                        <img class="fac-avatar" src="<?= htmlspecialchars($s['photo']) ?>" alt=""/>
                        <?= htmlspecialchars($s['name']) ?>
                    </td>
                    <td><?= htmlspecialchars($s['employee_id']) ?></td>
                    <td><?= htmlspecialchars($s['department']) ?></td>
                    <td><?= htmlspecialchars($s['designation']) ?></td>
                    <td>
                        <?php if (!empty($s['year_levels'])): ?>
                            <?php foreach ($s['year_levels'] as $yl): ?>
                                <span class="yl-pill"><?= htmlspecialchars($yl) ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="empty-note">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($s['assigned_period'] !== '—'): ?>
                            <span class="pill good"><?= htmlspecialchars($s['assigned_period']) ?></span>
                        <?php else: ?>
                            <span class="pill warn">Unassigned</span>
                        <?php endif; ?>
                    </td>
                    <td><a href="#" class="view-link" title="Staff profile page coming soon">View</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="no-results" id="noResults" style="display:none;">No staff match the current filters.</p>

        <?php endif; ?>
    </div>

</main>

<script>
const searchInput  = document.getElementById('searchInput');
const deptFilter    = document.getElementById('deptFilter');
const periodFilter  = document.getElementById('periodFilter');
const table         = document.getElementById('staffTable');
const countNote     = document.getElementById('countNote');
const noResults     = document.getElementById('noResults');

function applyFilters() {
    if (!table) return;
    const rows = table.querySelectorAll('tbody tr');
    const q = (searchInput.value || '').toLowerCase().trim();
    const dept = deptFilter.value;
    const period = periodFilter.value;
    let visible = 0;

    rows.forEach(row => {
        const matchesSearch = !q || row.dataset.name.includes(q) || row.dataset.employee_id.includes(q);
        const matchesDept   = !dept || row.dataset.department === dept;
        const matchesPeriod = !period || row.dataset.period === period;
        const show = matchesSearch && matchesDept && matchesPeriod;
        row.classList.toggle('hidden-row', !show);
        if (show) visible++;
    });

    countNote.textContent = `${visible} of ${rows.length} staff`;
    noResults.style.display = visible === 0 ? 'block' : 'none';
    table.style.display = visible === 0 ? 'none' : 'table';
}

[searchInput, deptFilter, periodFilter].forEach(el => {
    if (el) el.addEventListener('input', applyFilters);
});

// Sortable columns
let sortState = { key: null, dir: 1 };
document.querySelectorAll('#staffTable th[data-key]').forEach(th => {
    th.addEventListener('click', () => {
        const key = th.dataset.key;
        sortState.dir = (sortState.key === key) ? -sortState.dir : 1;
        sortState.key = key;

        document.querySelectorAll('#staffTable th[data-key] i').forEach(i => i.className = 'fa-solid fa-sort');
        th.querySelector('i').className = sortState.dir === 1 ? 'fa-solid fa-sort-up' : 'fa-solid fa-sort-down';

        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        rows.sort((a, b) => {
            let av = a.dataset[key], bv = b.dataset[key];
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
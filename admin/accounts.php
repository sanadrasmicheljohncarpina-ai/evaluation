<?php
// admin/accounts.php
session_start();
require_once 'db.php';
require_once 'permissions.php';

// $can_edit = false means the logged-in Admin (vice) can VIEW this page but not
// add/edit/delete anything. Superadmin always gets true. Controlled from
// manage_permissions.php under the 'user_management' feature key.
$can_edit = admin_can_edit($mysqli, 'user_management');

$viewSector = $_GET['sector'] ?? 'Teacher';
$message    = '';

// ── DELETE ───────────────────────────────────────────────────
if (isset($_GET['delete_id'])) {
    if (!$can_edit) { header("Location: accounts.php?sector=" . urlencode($viewSector)); exit; }
    $id = intval($_GET['delete_id']);

    // Grab the photo filename first — the row won't exist to query after a successful delete
    $photo = null;
    $res = $mysqli->query("SELECT photo FROM users WHERE id=$id");
    if ($res && $row = $res->fetch_assoc()) {
        $photo = $row['photo'];
    }

    try {
        $stmt = $mysqli->prepare("DELETE FROM users WHERE id=?");
        $stmt->bind_param("i", $id);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            // Only touch the filesystem once the DB delete actually succeeded
            if ($photo && file_exists(UPLOAD_DIR . $photo)) {
                unlink(UPLOAD_DIR . $photo);
            }
            $_SESSION['toast'] = "Account removed successfully.";
        } elseif ($mysqli->errno === 1451) {
            $_SESSION['toast_error'] = "This account can't be removed — it still has evaluation records tied to it. Deactivate it instead.";
        } else {
            $_SESSION['toast_error'] = "Failed to remove account: " . $mysqli->error;
        }
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() === 1451) {
            $_SESSION['toast_error'] = "This account can't be removed — it still has evaluation records tied to it. Deactivate it instead.";
        } else {
            $_SESSION['toast_error'] = "Failed to remove account: " . $e->getMessage();
        }
    }
    header("Location: accounts.php?sector=" . urlencode($viewSector)); exit;
}

// ── ASSIGN DESIGNATION ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign') {
    if (!$can_edit) { header("Location: accounts.php?sector=" . urlencode($viewSector)); exit; }
    $uid   = intval($_POST['user_id']);
    $desig = $mysqli->real_escape_string(trim($_POST['designation']));
    $mysqli->query("UPDATE users SET designation='$desig' WHERE id=$uid");
    $_SESSION['toast'] = "Designation updated to '$desig' successfully.";
    header("Location: accounts.php?sector=" . urlencode($viewSector)); exit;
}

// ── TOGGLE ACTIVE ────────────────────────────────────────────
if (isset($_GET['toggle_id'])) {
    if (!$can_edit) { header("Location: accounts.php?sector=" . urlencode($viewSector)); exit; }
    $id = intval($_GET['toggle_id']);
    $mysqli->query("UPDATE users SET is_active = IF(is_active=1,0,1) WHERE id=$id");
    $_SESSION['toast'] = "Account status updated.";
    header("Location: accounts.php?sector=" . urlencode($viewSector)); exit;
}

// ── FETCH USERS ──────────────────────────────────────────────
// Excludes BOTH admin-created source types:
//   'admin_nologin' = personnel profiles with no login (managed in personnel_registry.php)
//   'admin'         = privileged login accounts created via manage_privileged_accounts.php
// Only shows users who registered themselves through the login page.
$sectorMap = [
    'Teacher' => ['role' => 'teacher'],
    'Staff'   => ['role' => 'staff'],
    'Student' => ['role' => 'student'],
];
$role = $sectorMap[$viewSector]['role'] ?? 'teacher';

$users = [];
$stmt  = $mysqli->prepare(
    "SELECT * FROM users
     WHERE role = ?
       AND (source IS NULL OR source NOT IN ('admin_nologin', 'admin'))
     ORDER BY full_name ASC"
);
$stmt->bind_param("s", $role);
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── COUNTS FOR TABS (also exclude admin & admin_nologin) ──────
$counts = [];
foreach (['Teacher'=>'teacher','Staff'=>'staff','Student'=>'student'] as $label => $r) {
    $cr = $mysqli->query(
        "SELECT COUNT(*) as c FROM users
         WHERE role='$r' AND (source IS NULL OR source NOT IN ('admin_nologin', 'admin'))"
    );
    $counts[$label] = $cr->fetch_assoc()['c'] ?? 0;
}

$desig_options = [
    'teacher' => ['Teacher','Registrar','Cashier','Bookkeeper','Librarian','Guidance','Nurse','Personnel'],
    'staff'   => ['Personnel','Registrar','Cashier','Bookkeeper','Librarian','Guidance','Nurse'],
    'student' => [],
];

// ── STUDENT LEVEL CLASSIFICATION (JHS / SHS / College) ───────
// Buckets students using their year_level text so we can filter
// the Student tab the same way we filter by status/designation.
function classify_student_level($year_level) {
    $yl = strtolower(trim($year_level ?? ''));
    if ($yl === '') return 'other';
    if (preg_match('/grade\s*(7|8|9|10)\b/', $yl)) return 'jhs';
    if (preg_match('/grade\s*(11|12)\b/', $yl))     return 'shs';
    return 'college';
}

// ── TEACHER/STAFF LEVEL ASSIGNMENTS (JHS / SHS / College) ────
// Pulls each user's assigned teaching level(s) from faculty_levels.
// Unlike students (one level each), a teacher/staff member can be
// assigned MULTIPLE levels at once (e.g. teaches both JHS and SHS),
// so a row can land in more than one filter bucket simultaneously.
$user_levels    = []; // user_id => ['junior_high','senior_high',...]
$lvl_db_to_key  = ['junior_high' => 'jhs', 'senior_high' => 'shs', 'college' => 'college'];
$lvl_key_label  = ['jhs' => 'JHS', 'shs' => 'SHS', 'college' => 'College'];

if (($role === 'teacher' || $role === 'staff') && !empty($users)) {
    $ids = array_map('intval', array_column($users, 'id'));
    $idList = implode(',', $ids);
    $lvlRes = $mysqli->query("SELECT user_id, level FROM faculty_levels WHERE user_id IN ($idList)");
    if ($lvlRes) {
        while ($row = $lvlRes->fetch_assoc()) {
            $user_levels[$row['user_id']][] = $row['level'];
        }
    }
}

$level_counts = ['jhs' => 0, 'shs' => 0, 'college' => 0, 'other' => 0];
if ($role === 'student') {
    foreach ($users as $u) {
        $level_counts[classify_student_level($u['year_level'] ?? '')]++;
    }
} elseif ($role === 'teacher' || $role === 'staff') {
    foreach ($users as $u) {
        $lvls = $user_levels[$u['id']] ?? [];
        if (empty($lvls)) { $level_counts['other']++; continue; }
        foreach ($lvls as $l) {
            if (isset($lvl_db_to_key[$l])) $level_counts[$lvl_db_to_key[$l]]++;
        }
    }
}

$toast       = $_SESSION['toast']       ?? ''; unset($_SESSION['toast']);
$toast_error = $_SESSION['toast_error'] ?? ''; unset($_SESSION['toast_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>User Management — PBI Admin</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<style>
:root{--dark:#0A192F;--mid:#172A45;--inner:#0F1F3D;--accent:#2B6CB0;--hover:#4C78B8;--teal:#0D9488;--gold:#D97706;--light:#E0E6F0;--muted:#A0B3C6;--danger:#F05454;--success:#22C55E;--border:rgba(255,255,255,0.08);--radius:10px;--shadow:0 4px 20px rgba(0,0,0,0.35);}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--dark);color:var(--light);min-height:100vh;padding:28px;}
.toast{position:fixed;top:20px;right:20px;z-index:999;background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.35);color:#86efac;padding:12px 20px;border-radius:8px;font-size:13px;display:flex;align-items:center;gap:8px;animation:slideIn .3s ease,fadeOut .4s ease 3s forwards;}
.toast.error{background:rgba(240,84,84,.15);border-color:rgba(240,84,84,.35);color:#fca5a5;}
@keyframes slideIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:none}}
@keyframes fadeOut{to{opacity:0;pointer-events:none}}
.page-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:28px;}
.page-header h1{font-family:'Rajdhani',sans-serif;font-size:28px;font-weight:700;letter-spacing:1px;color:#fff;margin-bottom:3px;}
.page-header p{font-size:13px;color:var(--muted);}

/* INFO BANNER */
.info-banner{background:rgba(43,108,176,.08);border:1px solid rgba(43,108,176,.2);border-radius:var(--radius);padding:12px 18px;margin-bottom:22px;display:flex;gap:12px;align-items:center;font-size:13px;color:#93c5fd;}
.info-banner i{flex-shrink:0;}

.sector-tabs{display:flex;gap:4px;background:var(--mid);border:1px solid var(--border);border-radius:var(--radius);padding:4px;margin-bottom:24px;width:fit-content;}
.sector-tab{padding:9px 22px;border:none;border-radius:7px;font-family:'DM Sans',sans-serif;font-size:13px;font-weight:600;cursor:pointer;transition:all .22s;background:transparent;color:var(--muted);display:flex;align-items:center;gap:7px;}
.sector-tab.active{background:var(--accent);color:#fff;box-shadow:0 2px 10px rgba(43,108,176,.35);}
.sector-tab:not(.active):hover{color:var(--light);background:rgba(255,255,255,.05);}
.tab-badge{background:rgba(255,255,255,.15);border-radius:20px;padding:1px 8px;font-size:11px;font-weight:700;}
.sector-tab.active .tab-badge{background:rgba(255,255,255,.25);}

/* LEVEL PILLS (JHS / SHS / College) — used for Student AND Teacher/Staff tabs */
.level-tabs{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;}
.level-tab{padding:7px 20px;border-radius:20px;border:1px solid var(--border);background:var(--inner);color:var(--muted);font-size:13px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .2s;display:inline-flex;align-items:center;gap:6px;}
.level-tab:hover{border-color:var(--teal);color:var(--light);}
.level-tab.active{background:var(--teal);border-color:var(--teal);color:#04211d;}
.level-tab .lvl-count{background:rgba(255,255,255,.18);border-radius:20px;padding:0 7px;font-size:11px;font-weight:700;}
.level-tab.active .lvl-count{background:rgba(0,0,0,.15);}

.stats-row{display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap;}
.stat-card{background:var(--mid);border:1px solid var(--border);border-radius:var(--radius);padding:16px 22px;flex:1;min-width:140px;}
.stat-label{font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:6px;}
.stat-value{font-size:26px;font-weight:700;color:#fff;}
.stat-value.active-color{color:#4ade80;}
.stat-value.inactive-color{color:#f87171;}
.toolbar{display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;align-items:center;}
.search-wrap{position:relative;flex:1;min-width:200px;}
.search-wrap i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px;}
.search-input{width:100%;padding:10px 13px 10px 38px;background:var(--inner);border:1px solid var(--border);border-radius:var(--radius);color:var(--light);font-size:14px;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .2s;}
.search-input::placeholder{color:rgba(160,179,198,.45);}
.search-input:focus{border-color:var(--accent);}
.filter-select{padding:10px 14px;background:var(--inner);border:1px solid var(--border);border-radius:var(--radius);color:var(--light);font-size:13px;font-family:'DM Sans',sans-serif;outline:none;cursor:pointer;}
.table-wrap{background:var(--mid);border:1px solid var(--border);border-radius:14px;overflow:hidden;}
table{width:100%;border-collapse:collapse;}
thead tr{background:var(--inner);}
thead th{padding:12px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);text-align:left;white-space:nowrap;}
tbody tr{border-bottom:1px solid var(--border);transition:background .15s;}
tbody tr:last-child{border-bottom:none;}
tbody tr:hover{background:rgba(43,108,176,.07);}
tbody td{padding:14px 16px;font-size:14px;vertical-align:middle;}
.user-cell{display:flex;align-items:center;gap:12px;}
.user-avatar{width:42px;height:42px;border-radius:50%;object-fit:cover;border:2px solid var(--border);background:var(--inner);flex-shrink:0;}
.user-avatar-placeholder{width:42px;height:42px;border-radius:50%;background:var(--inner);border:2px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:16px;flex-shrink:0;}
.user-name{font-weight:600;color:#fff;font-size:14px;}
.user-username{font-size:12px;color:var(--muted);}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-active{background:rgba(34,197,94,.15);color:#4ade80;}
.badge-inactive{background:rgba(240,84,84,.15);color:#f87171;}
.desig-form{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.desig-select{background:var(--inner);border:1px solid var(--border);color:var(--light);padding:5px 10px;border-radius:6px;font-size:12px;font-family:'DM Sans',sans-serif;outline:none;cursor:pointer;transition:border-color .2s;max-width:140px;}
.desig-select:focus{border-color:var(--teal);}
.btn-assign{background:var(--teal);color:#fff;border:none;padding:5px 12px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;transition:background .2s;white-space:nowrap;}
.btn-assign:hover{background:#14B8A6;}
.desig-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:rgba(43,108,176,.18);color:#93c5fd;margin-bottom:4px;}
.level-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:rgba(13,148,136,.18);color:#5eead4;}
.level-badge.unassigned{background:rgba(251,191,36,.15);color:#fbbf24;}
.level-badge-row{display:flex;gap:4px;flex-wrap:wrap;margin-top:5px;}
.action-wrap{display:flex;gap:6px;align-items:center;}
.btn-icon{background:none;border:1px solid var(--border);border-radius:6px;padding:6px 10px;color:var(--muted);cursor:pointer;font-size:13px;transition:all .2s;}
.btn-icon:hover{background:rgba(255,255,255,.06);color:var(--light);}
.btn-icon.danger:hover{border-color:var(--danger);color:var(--danger);}
.btn-icon.activate:hover{border-color:#4ade80;color:#4ade80;}
.empty-state{text-align:center;padding:60px 20px;color:var(--muted);}
.empty-state i{font-size:42px;margin-bottom:16px;display:block;opacity:.3;}
.empty-state p{font-size:14px;line-height:1.7;}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:100;display:none;align-items:center;justify-content:center;padding:20px;}
.modal-overlay.open{display:flex;}
.modal{background:var(--mid);border:1px solid var(--border);border-radius:16px;padding:28px;width:100%;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,.5);}
.modal-title{font-family:'Rajdhani',sans-serif;font-size:20px;font-weight:700;color:#fff;margin-bottom:6px;}
.modal-sub{font-size:13px;color:var(--muted);margin-bottom:22px;}
.modal-actions{display:flex;gap:10px;margin-top:22px;}
.btn-cancel{flex:1;padding:10px;background:var(--inner);border:1px solid var(--border);border-radius:var(--radius);color:var(--light);font-size:14px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;}
.btn-confirm-del{flex:1;padding:10px;background:var(--danger);border:none;border-radius:var(--radius);color:#fff;font-size:14px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;}
.btn-confirm-del:hover{background:#e03c3c;}
.needs-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;background:rgba(251,191,36,.15);color:#fbbf24;margin-left:6px;}
tbody tr.needs-desig{background:rgba(251,191,36,.04);}
tbody tr.needs-desig:hover{background:rgba(251,191,36,.08);}
@media(max-width:900px){.hide-mobile{display:none;}tbody td{padding:10px 12px;}body{padding:16px;}}
@media(max-width:600px){.sector-tabs{width:100%;}.sector-tab{flex:1;justify-content:center;padding:8px 10px;}.stats-row{gap:10px;}}
</style>
</head>
<body>

<?php if ($toast): ?>
<div class="toast"><i class="fa-solid fa-circle-check"></i><?= htmlspecialchars($toast) ?></div>
<?php endif; ?>
<?php if ($toast_error): ?>
<div class="toast error"><i class="fa-solid fa-circle-exclamation"></i><?= htmlspecialchars($toast_error) ?></div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1>User Management</h1>
        <p>Accounts registered through the login page — assign designations, activate, or remove users</p>
    </div>
</div>

<!-- INFO BANNER -->
<div class="info-banner">
    <i class="fa-solid fa-circle-info"></i>
    This page only shows accounts created through the <strong>registration page</strong>.
    Admin-added personnel are managed separately under <strong>Personnel Registry</strong>.
</div>

<!-- SECTOR TABS -->
<div class="sector-tabs">
    <?php $tabIcons=['Teacher'=>'fa-chalkboard-user','Staff'=>'fa-briefcase','Student'=>'fa-user-graduate'];
    foreach (['Teacher','Staff','Student'] as $sector): ?>
    <a href="accounts.php?sector=<?= $sector ?>" style="text-decoration:none;">
        <div class="sector-tab <?= $viewSector===$sector?'active':'' ?>">
            <i class="fa-solid <?= $tabIcons[$sector] ?>"></i> <?= $sector ?>
            <span class="tab-badge"><?= $counts[$sector] ?></span>
        </div>
    </a>
    <?php endforeach; ?>
</div>

<!-- LEVEL TABS: All / JHS / SHS / College — shown for Student, Teacher, and Staff -->
<?php if ($role === 'student' || $role === 'teacher' || $role === 'staff'): ?>
<div class="level-tabs" id="levelTabs">
    <button type="button" class="level-tab active" data-level="all" onclick="setLevelFilter('all',this)">
        All <span class="lvl-count"><?= count($users) ?></span>
    </button>
    <button type="button" class="level-tab" data-level="jhs" onclick="setLevelFilter('jhs',this)">
        JHS <span class="lvl-count"><?= $level_counts['jhs'] ?></span>
    </button>
    <button type="button" class="level-tab" data-level="shs" onclick="setLevelFilter('shs',this)">
        SHS <span class="lvl-count"><?= $level_counts['shs'] ?></span>
    </button>
    <button type="button" class="level-tab" data-level="college" onclick="setLevelFilter('college',this)">
        College <span class="lvl-count"><?= $level_counts['college'] ?></span>
    </button>
    <?php if (($role === 'teacher' || $role === 'staff') && $level_counts['other'] > 0): ?>
    <button type="button" class="level-tab" data-level="other" onclick="setLevelFilter('other',this)">
        Unassigned <span class="lvl-count"><?= $level_counts['other'] ?></span>
    </button>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- STATS -->
<?php
$activeCount   = count(array_filter($users, fn($u) => $u['is_active']));
$inactiveCount = count($users) - $activeCount;
$staffPending  = count(array_filter($users, fn($u) => $u['role']==='staff' && $u['designation']==='Personnel'));
?>
<div class="stats-row">
    <div class="stat-card"><div class="stat-label">Total <?= $viewSector ?></div><div class="stat-value"><?= count($users) ?></div></div>
    <div class="stat-card"><div class="stat-label">Active</div><div class="stat-value active-color"><?= $activeCount ?></div></div>
    <div class="stat-card"><div class="stat-label">Inactive</div><div class="stat-value inactive-color"><?= $inactiveCount ?></div></div>
    <?php if ($viewSector === 'Staff'): ?>
    <div class="stat-card"><div class="stat-label">Needs Designation</div><div class="stat-value" style="color:#fbbf24"><?= $staffPending ?></div></div>
    <?php endif; ?>
    <?php if (($role === 'teacher' || $role === 'staff') && $level_counts['other'] > 0): ?>
    <div class="stat-card"><div class="stat-label">No Level Assigned</div><div class="stat-value" style="color:#fbbf24"><?= $level_counts['other'] ?></div></div>
    <?php endif; ?>
</div>

<!-- TOOLBAR -->
<div class="toolbar">
    <div class="search-wrap">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input class="search-input" type="text" id="searchInput"
               placeholder="Search by name, username, or email..." oninput="filterTable()"/>
    </div>
    <select class="filter-select" id="statusFilter" onchange="filterTable()">
        <option value="all">All Status</option>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
    </select>
    <?php if ($viewSector !== 'Student'): ?>
    <select class="filter-select" id="desigFilter" onchange="filterTable()">
        <option value="all">All Designations</option>
        <?php foreach ($desig_options[$role] as $d): ?>
        <option value="<?= strtolower($d) ?>"><?= $d ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
</div>

<!-- TABLE -->
<div class="table-wrap">
    <table id="usersTable">
        <thead>
            <tr>
                <th>#</th>
                <th>Name</th>
                <th class="hide-mobile">Email</th>
                <th>Designation</th>
                <th>Status</th>
                <th class="hide-mobile">Joined</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($users)): ?>
        <tr><td colspan="7">
            <div class="empty-state">
                <i class="fa-solid fa-users-slash"></i>
                <p>No <?= strtolower($viewSector) ?> accounts registered yet.<br>
                They will appear here after registering through the login page.</p>
            </div>
        </td></tr>
        <?php else: foreach ($users as $i => $u):
            $is_pending = ($u['role']==='staff' && $u['designation']==='Personnel');
            $stu_level  = $role === 'student' ? classify_student_level($u['year_level'] ?? '') : '';
            $stu_level_labels = ['jhs'=>'JHS','shs'=>'SHS','college'=>'College','other'=>'Unassigned'];

            // Build this row's filter-bucket list — students get exactly one,
            // teacher/staff can land in several (or none, if unassigned).
            $row_level_keys = [];
            if ($role === 'student') {
                $row_level_keys[] = $stu_level;
            } elseif ($role === 'teacher' || $role === 'staff') {
                $raw_levels = $user_levels[$u['id']] ?? [];
                foreach ($raw_levels as $rl) {
                    if (isset($lvl_db_to_key[$rl])) $row_level_keys[] = $lvl_db_to_key[$rl];
                }
                if (empty($row_level_keys)) $row_level_keys[] = 'other';
            }
        ?>
        <tr class="<?= $is_pending?'needs-desig':'' ?>"
            data-name="<?= strtolower($u['full_name']) ?>"
            data-username="<?= strtolower($u['username']) ?>"
            data-email="<?= strtolower($u['email']??'') ?>"
            data-status="<?= $u['is_active']?'active':'inactive' ?>"
            data-desig="<?= strtolower($u['designation']??'') ?>"
            data-level="<?= implode(',', $row_level_keys) ?>">

            <td style="color:var(--muted);font-size:13px;"><?= $i+1 ?></td>

            <td>
                <div class="user-cell">
                    <?php if ($u['photo']): ?>
                    <img class="user-avatar" src="<?= UPLOAD_URL.htmlspecialchars($u['photo']) ?>" alt=""/>
                    <?php else: ?>
                    <div class="user-avatar-placeholder"><i class="fa-solid fa-user"></i></div>
                    <?php endif; ?>
                    <div>
                        <div class="user-name">
                            <?= htmlspecialchars($u['full_name']) ?>
                            <?php if ($is_pending): ?>
                            <span class="needs-badge"><i class="fa-solid fa-triangle-exclamation"></i> Assign</span>
                            <?php endif; ?>
                        </div>
                        <div class="user-username">@<?= htmlspecialchars($u['username']) ?></div>
                    </div>
                </div>
            </td>

            <td class="hide-mobile" style="color:var(--muted);font-size:13px;">
                <?= htmlspecialchars($u['email']??'—') ?>
            </td>

            <td>
                <?php if ($role === 'teacher' || $role === 'staff'): ?>
                <div>
                    <div class="desig-badge">
                        <i class="fa-solid <?= $role==='teacher'?'fa-chalkboard-user':'fa-briefcase' ?>"></i>
                        <?= htmlspecialchars($u['designation'] ?: 'Not set') ?>
                    </div>
                    <?php if ($can_edit): ?>
                    <form method="POST" class="desig-form" action="accounts.php?sector=<?= urlencode($viewSector) ?>">
                        <input type="hidden" name="action" value="assign"/>
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>"/>
                        <select name="designation" class="desig-select">
                            <?php foreach ($desig_options[$role] as $d): ?>
                            <option value="<?= $d ?>" <?= $u['designation']===$d?'selected':'' ?>><?= $d ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn-assign">Assign</button>
                    </form>
                    <?php endif; ?>

                    <!-- Teaching level(s), read-only here — assigned by the teacher/staff
                         member themselves from their own dashboard. -->
                    <div class="level-badge-row">
                        <?php if (empty($user_levels[$u['id']])): ?>
                        <span class="level-badge unassigned"><i class="fa-solid fa-triangle-exclamation"></i> No level assigned</span>
                        <?php else: foreach ($user_levels[$u['id']] as $rl): ?>
                        <span class="level-badge"><i class="fa-solid fa-graduation-cap"></i> <?= htmlspecialchars($lvl_key_label[$lvl_db_to_key[$rl] ?? ''] ?? ucfirst($rl)) ?></span>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <div>
                    <div class="level-badge">
                        <i class="fa-solid fa-graduation-cap"></i>
                        <?= $stu_level_labels[$stu_level] ?? 'Unassigned' ?>
                    </div>
                    <div style="font-size:12px;color:var(--muted);margin-top:4px;">
                        <?= htmlspecialchars($u['department']??'—') ?>
                        <?= $u['year_level']?' · '.htmlspecialchars($u['year_level']):'' ?>
                    </div>
                </div>
                <?php endif; ?>
            </td>

            <td>
                <?php if ($u['is_active']): ?>
                <span class="badge badge-active"><i class="fa-solid fa-circle" style="font-size:7px"></i> Active</span>
                <?php else: ?>
                <span class="badge badge-inactive"><i class="fa-solid fa-circle" style="font-size:7px"></i> Inactive</span>
                <?php endif; ?>
            </td>

            <td class="hide-mobile" style="font-size:13px;color:var(--muted);">
                <?= date('M d, Y', strtotime($u['created_at'])) ?>
            </td>

            <td>
                <div class="action-wrap">
                    <?php if ($can_edit): ?>
                    <a href="accounts.php?sector=<?= urlencode($viewSector) ?>&toggle_id=<?= $u['id'] ?>">
                        <button class="btn-icon activate" title="<?= $u['is_active']?'Deactivate':'Activate' ?>">
                            <i class="fa-solid <?= $u['is_active']?'fa-toggle-on':'fa-toggle-off' ?>"></i>
                        </button>
                    </a>
                    <button class="btn-icon danger" title="Delete"
                            onclick="confirmDelete(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['full_name'])) ?>')">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                    <?php else: ?>
                    <span style="font-size:11px;color:var(--muted);display:inline-flex;align-items:center;gap:5px;">
                        <i class="fa-solid fa-lock"></i> View only
                    </span>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<!-- DELETE MODAL -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal">
        <div class="modal-title">Remove Account</div>
        <div class="modal-sub" id="modalSubText">This will permanently delete the account.</div>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeModal()">Cancel</button>
            <a id="confirmDelLink" href="#">
                <button class="btn-confirm-del" style="width:100%">
                    <i class="fa-solid fa-trash-can"></i> Yes, Remove
                </button>
            </a>
        </div>
    </div>
</div>

<script>
let currentLevel = 'all';
function setLevelFilter(level, btn){
    currentLevel = level;
    document.querySelectorAll('.level-tab').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    filterTable();
}
function filterTable(){
    const q=document.getElementById('searchInput').value.toLowerCase();
    const s=document.getElementById('statusFilter').value;
    const d=document.getElementById('desigFilter')?.value||'all';
    const lvl=(typeof currentLevel!=='undefined')?currentLevel:'all';
    document.querySelectorAll('#usersTable tbody tr[data-name]').forEach(row=>{
        const nm=row.dataset.name.includes(q)||row.dataset.username.includes(q)||row.dataset.email.includes(q);
        const sm=s==='all'||row.dataset.status===s;
        const dm=d==='all'||row.dataset.desig.includes(d);
        // A teacher/staff row can carry MULTIPLE levels (e.g. "jhs,shs"), so check
        // whether the selected filter is any one of them, not an exact match.
        const rowLevels=(row.dataset.level||'').split(',').filter(Boolean);
        const lm=lvl==='all'||rowLevels.includes(lvl);
        row.style.display=nm&&sm&&dm&&lm?'':'none';
    });
}
function confirmDelete(id,name){
    document.getElementById('modalSubText').textContent=`Remove "${name}"? This cannot be undone.`;
    document.getElementById('confirmDelLink').href=`accounts.php?sector=<?= urlencode($viewSector) ?>&delete_id=${id}`;
    document.getElementById('deleteModal').classList.add('open');
}
function closeModal(){document.getElementById('deleteModal').classList.remove('open');}
document.getElementById('deleteModal').addEventListener('click',function(e){if(e.target===this)closeModal();});
</script>

<?php $mysqli->close(); ?>
</body>
</html>
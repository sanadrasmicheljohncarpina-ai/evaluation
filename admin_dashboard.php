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

// Guard
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin','superadmin','registrar'])) {
    header("Location: admin_login.php"); exit;
}

// Fetch admin info
$admin_photo    = null;
$admin_fullname = $_SESSION['full_name'] ?? 'Admin';

$admin_username = '';
$admin_email    = '';

$stmt = $mysqli->prepare("SELECT full_name, username, email, photo FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
    
if ($row) {
    $admin_fullname = $row['full_name'];
    $admin_username = $row['username'];
    $admin_email    = $row['email'] ?? '';
    $admin_photo    = $row['photo'];
    
}

$photo_src = $admin_photo ? '../image/' . htmlspecialchars($admin_photo) : null;
$parts     = explode(' ', trim($admin_fullname));
$initials  = strtoupper(substr($parts[0],0,1) . (isset($parts[1]) ? substr($parts[1],0,1) : ''));
// Display-only label for the role badge. Session/DB value stays 'superadmin' —
// only the text shown on screen changes.
function display_role($role) {
    $map = ['superadmin' => 'Executive Assistant', 'admin' => 'Admin', 'registrar' => 'Registrar'];
    return $map[$role] ?? ucfirst($role);
}

// Live counts
$totalUsers  = 0; $activeEvals = 0;
$eq = $mysqli->query("SELECT COUNT(*) as c FROM evaluation_tracker"); if ($eq) $activeEvals = (int)$eq->fetch_assoc()['c'];

// Sector breakdown (Teacher / Staff / Student) — only counts ACTIVE accounts.
// Self-registration has been removed; the system admin now creates every account
// directly, so inactive rows are stale leftover self-registered accounts that no
// longer represent real users and should not be counted.
$teacherCount = 0; $staffCount = 0; $studentCount = 0;
foreach (['teacher' => 'teacherCount', 'staff' => 'staffCount', 'student' => 'studentCount'] as $r => $var) {
    $cr = $mysqli->query(
        "SELECT COUNT(*) as c FROM users
         WHERE role='" . $mysqli->real_escape_string($r) . "' AND is_active=1"
    );
    if ($cr) $$var = (int)($cr->fetch_assoc()['c'] ?? 0);
}
$facultyCount = $teacherCount + $staffCount;
// "Total Users" only counts Faculty + Staff + Students (excludes admin/superadmin/registrar accounts)
$totalUsers = $facultyCount + $studentCount;

// Submission progress for the current evaluation period
$submittedCount = 0;
$subq = $mysqli->query("SELECT COUNT(DISTINCT evaluator_id) as c FROM evaluation_tracker WHERE status='submitted'");
if ($subq) $submittedCount = (int)$subq->fetch_assoc()['c'];
$submissionPct = $studentCount > 0 ? round(($submittedCount / $studentCount) * 100) : 0;

// ── Academic Structure → Term rules ──
// The Academic Term dropdown is constrained by the selected Academic Structure so
// invalid combinations (e.g. "Summer" for Junior High School) can't be saved.
$STRUCTURE_TERMS = [
    'college' => ['1st Semester', '2nd Semester', 'Summer'],
    'jhs'     => ['School Year'],
    'shs'     => ['School Year'],
];
$STRUCTURE_LABELS = [
    'college' => 'College',
    'jhs'     => 'Junior High School',
    'shs'     => 'Senior High School',
];

// Fetch system settings from DB (if table exists)
$sys = ss_raw($mysqli);[
    'acad_year'      => date('Y').'-'.(date('Y')+1),
    'acad_structure' => 'college',
    'acad_term'      => '1st Semester',
    'auto_schedule'  => 1,
    'control_mode'   => 'schedule',   // schedule | open | closed
    'eval_start'     => '',
    'eval_end'       => '',
    'maintenance'    => 0,
    'rule_only_during_period' => 1,
    'rule_edit_after_submit'  => 0,
    'rule_one_submission'     => 1,
    'rule_require_all'        => 1,
    'rule_auto_lock'          => 1,
    'rule_countdown'          => 1,
    'rule_prevent_late'       => 1,
    'publish_state'           => 'published', // draft | published
    'notify_eval_open'        => 1,
    'notify_eval_closing'     => 1,
    'notify_faculty_complete' => 1,
    'notify_reminders'        => 0,
];
$stbl = $mysqli->query("SHOW TABLES LIKE 'system_settings'");
if ($stbl && $stbl->num_rows > 0) {
    $sr = $mysqli->query("SELECT setting_key, setting_value FROM system_settings");
    if ($sr) while ($sr_row = $sr->fetch_assoc()) $sys[$sr_row['setting_key']] = $sr_row['setting_value'];
}
// Guard against a stale/invalid Structure+Term combination left over in the DB
if (!isset($STRUCTURE_TERMS[$sys['acad_structure']])) $sys['acad_structure'] = 'college';
if (!in_array($sys['acad_term'], $STRUCTURE_TERMS[$sys['acad_structure']], true)) {
    $sys['acad_term'] = $STRUCTURE_TERMS[$sys['acad_structure']][0];
}

// Handle settings save
$settings_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    $tab = $_POST['tab'] ?? 'profile';

    if ($tab === 'profile') {
        $new_fullname = trim($_POST['settings_fullname'] ?? '');
        $new_email    = trim($_POST['settings_email']    ?? '');
        if ($new_fullname && $new_email) {
            $upd = $mysqli->prepare("UPDATE users SET full_name=?, email=? WHERE id=?");
            $upd->bind_param("ssi", $new_fullname, $new_email, $_SESSION['user_id']);
            $upd->execute(); $upd->close();
            $_SESSION['full_name'] = $new_fullname;
            $admin_fullname = $new_fullname;
            $settings_msg = 'ok:Profile updated successfully.';
        } else { $settings_msg = 'error:Name and email are required.'; }
    }

    if ($tab === 'security') {
        $new_pw     = $_POST['settings_password']   ?? '';
        $confirm_pw = $_POST['settings_confirm_pw'] ?? '';
        if ($new_pw) {
            if (strlen($new_pw) < 8) { $settings_msg = 'error:Password must be at least 8 characters.'; }
            elseif ($new_pw !== $confirm_pw) { $settings_msg = 'error:Passwords do not match.'; }
            else {
                $hash = password_hash($new_pw, PASSWORD_DEFAULT);
                // Try password_hash column first, fallback to password
                $col_check = $mysqli->query("SHOW COLUMNS FROM users LIKE 'password_hash'");
                $col = ($col_check && $col_check->num_rows > 0) ? 'password_hash' : 'password';
                $upd = $mysqli->prepare("UPDATE users SET $col=? WHERE id=?");
                $upd->bind_param("si", $hash, $_SESSION['user_id']);
                $upd->execute(); $upd->close();
                $settings_msg = 'ok:Password changed successfully.';
            }
        } else { $settings_msg = 'error:Please enter a new password.'; }
    }

    if ($tab === 'system') {
        $acad_year      = trim($_POST['sys_acad_year'] ?? '');
        $acad_structure = $_POST['sys_acad_structure'] ?? 'college';
        if (!isset($STRUCTURE_TERMS[$acad_structure])) $acad_structure = 'college';

        // Server-side guard: never trust the submitted Term without checking it
        // against the submitted Structure, even though the dropdown is constrained
        // client-side too — this is what actually prevents an invalid combination
        // (e.g. Summer + Junior High School) from being persisted.
        $acad_term = $_POST['sys_acad_term'] ?? $STRUCTURE_TERMS[$acad_structure][0];
        if (!in_array($acad_term, $STRUCTURE_TERMS[$acad_structure], true)) {
            $acad_term = $STRUCTURE_TERMS[$acad_structure][0];
        }


        $control_mode = $_POST['sys_control_mode'] ?? 'schedule';
        if (!in_array($control_mode, ['schedule', 'open', 'closed'], true)) $control_mode = 'schedule';

        $maintenance = isset($_POST['sys_maintenance']) ? 1 : 0;
        $auto_schedule = isset($_POST['sys_auto_schedule']) ? 1 : 0;

        $eval_start = trim($_POST['eval_start_date'] ?? '');
        $eval_end   = trim($_POST['eval_end_date']   ?? '');

        $publish_state = $_POST['sys_publish_state'] ?? 'published';
        if (!in_array($publish_state, ['draft', 'published'], true)) $publish_state = 'published';

// Fetch system settings from DB (if table exists) — start from defaults,
// then let whatever ss_raw() returns override them.
$rule_only_during_period = isset($_POST['rule_only_during_period']) ? 1 : 0;
        $rule_edit_after_submit  = isset($_POST['rule_edit_after_submit'])  ? 1 : 0;
        $rule_one_submission     = isset($_POST['rule_one_submission'])     ? 1 : 0;
        $rule_require_all        = isset($_POST['rule_require_all'])        ? 1 : 0;
        $rule_auto_lock          = isset($_POST['rule_auto_lock'])          ? 1 : 0;
        $rule_countdown          = isset($_POST['rule_countdown'])          ? 1 : 0;
        $rule_prevent_late       = isset($_POST['rule_prevent_late'])       ? 1 : 0;

        $notify_eval_open        = isset($_POST['notify_eval_open'])        ? 1 : 0;
        $notify_eval_closing     = isset($_POST['notify_eval_closing'])     ? 1 : 0;
        $notify_faculty_complete = isset($_POST['notify_faculty_complete']) ? 1 : 0;
        $notify_reminders        = isset($_POST['notify_reminders'])        ? 1 : 0;

        $new_sys = [
            'acad_year'               => $acad_year,
            'acad_structure'          => $acad_structure,
            'acad_term'               => $acad_term,
            'auto_schedule'           => $auto_schedule,
            'control_mode'            => $control_mode,
            'eval_start'              => $eval_start,
            'eval_end'                => $eval_end,
            'maintenance'             => $maintenance,
            'rule_only_during_period' => $rule_only_during_period,
            'rule_edit_after_submit'  => $rule_edit_after_submit,
            'rule_one_submission'     => $rule_one_submission,
            'rule_require_all'        => $rule_require_all,
            'rule_auto_lock'          => $rule_auto_lock,
            'rule_countdown'          => $rule_countdown,
            'rule_prevent_late'       => $rule_prevent_late,
            'publish_state'           => $publish_state,
            'notify_eval_open'        => $notify_eval_open,
            'notify_eval_closing'     => $notify_eval_closing,
            'notify_faculty_complete' => $notify_faculty_complete,
            'notify_reminders'        => $notify_reminders,
        ];

        // Upsert into system_settings if table exists
        $stbl2 = $mysqli->query("SHOW TABLES LIKE 'system_settings'");
        if ($stbl2 && $stbl2->num_rows > 0) {
            foreach ($new_sys as $k => $v) {
                $mysqli->query("INSERT INTO system_settings (setting_key,setting_value) VALUES ('".
                    $mysqli->real_escape_string($k)."','".
                    $mysqli->real_escape_string($v)."') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            }
        }
        $sys = array_merge($sys, $new_sys);
        $settings_msg = 'ok:System settings saved.';
        ss_sync_evaluation_period($mysqli, $sys);
    }

    // Photo upload (any tab)
    if (!empty($_FILES['settings_photo']['name']) && $_FILES['settings_photo']['error'] === 0) {
        $ft      = mime_content_type($_FILES['settings_photo']['tmp_name']);
        $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
        if (in_array($ft, $allowed) && $_FILES['settings_photo']['size'] <= 5*1024*1024) {
            $ext  = pathinfo($_FILES['settings_photo']['name'], PATHINFO_EXTENSION);
            $dir  = __DIR__ . '/../image/';
            $fname= 'adm_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
            if (move_uploaded_file($_FILES['settings_photo']['tmp_name'], $dir.$fname)) {
                $upd = $mysqli->prepare("UPDATE users SET photo=? WHERE id=?");
                $upd->bind_param("si", $fname, $_SESSION['user_id']);
                $upd->execute(); $upd->close();
                $photo_src   = '../image/' . $fname;
                $admin_photo = $fname;
                if (!$settings_msg) $settings_msg = 'ok:Photo updated.';
            }
        } else { $settings_msg = 'error:Invalid file. Use JPG/PNG/WebP under 5MB.'; }
    }
}
$sm_parts = explode(':', $settings_msg, 2);
$sm_type  = $sm_parts[0] ?? '';
$sm_text  = $sm_parts[1] ?? '';

// ── Evaluation status: computed once, reused in the settings modal and the
// dashboard period box. Precedence: Maintenance > manual override (Force
// Open/Closed) > the automatic schedule > a manual "not automatic" fallback.
//
// compute_eval_health() returns the richer version used by the new "Current
// Configuration" summary panel: a color class, a short label, a plain-language
// headline + sub-message, and (when a schedule is configured) the derived
// duration/remaining/elapsed day counts so the admin never has to do the math.
function compute_eval_health(array $sys): array {
    $base = ['duration_days' => null, 'remaining_days' => null, 'elapsed_days' => null, 'days_until_start' => null];

    if (!empty($sys['maintenance'])) {
        return array_merge($base, ['label' => 'MAINTENANCE', 'cls' => 'gray',
            'headline' => 'System is in maintenance mode.', 'sub' => 'All non-admin access is locked.']);
    }
    if (($sys['publish_state'] ?? 'published') === 'draft') {
        return array_merge($base, ['label' => 'DRAFT', 'cls' => 'gray',
            'headline' => 'Evaluation is saved as a draft.', 'sub' => 'Not visible to students or faculty yet.']);
    }

    $mode = $sys['control_mode'] ?? 'schedule';
    if ($mode === 'open') {
        return array_merge($base, ['label' => 'LIVE · FORCED OPEN', 'cls' => 'green',
            'headline' => 'Evaluation is manually forced open.', 'sub' => 'Accepting submissions regardless of the schedule.']);
    }
    if ($mode === 'closed') {
        return array_merge($base, ['label' => 'CLOSED · FORCED', 'cls' => 'red',
            'headline' => 'Evaluation is manually forced closed.', 'sub' => 'Blocking submissions regardless of the schedule.']);
    }
    if (empty($sys['auto_schedule'])) {
        return array_merge($base, ['label' => 'CLOSED · MANUAL', 'cls' => 'gray',
            'headline' => 'Automatic scheduling is off.', 'sub' => 'Status will not change until a schedule is enabled.']);
    }

    $start = $sys['eval_start'] ?? '';
    $end   = $sys['eval_end']   ?? '';
    if (!$start || !$end) {
        return array_merge($base, ['label' => 'NOT CONFIGURED', 'cls' => 'gray',
            'headline' => 'No evaluation window is configured.', 'sub' => 'Set an opening and closing date to enable scheduling.']);
    }
    $ts = strtotime($start); $te = strtotime($end);
    if ($ts === false || $te === false) {
        return array_merge($base, ['label' => 'NOT CONFIGURED', 'cls' => 'gray',
            'headline' => 'The configured dates could not be read.', 'sub' => 'Re-check the opening and closing date fields.']);
    }

    $now = time();
    $duration_days = max(0, (int)round(($te - $ts) / 86400));

    if ($now < $ts) {
        $days = max(1, (int)ceil(($ts - $now) / 86400));
        return array_merge($base, ['label' => 'SCHEDULED', 'cls' => 'yellow', 'duration_days' => $duration_days,
            'days_until_start' => $days,
            'headline' => 'Evaluation has not started yet.', 'sub' => 'Starts in ' . $days . ' day' . ($days === 1 ? '' : 's') . '.']);
    }
    if ($now > $te) {
        $days = max(1, (int)floor(($now - $te) / 86400));
        return array_merge($base, ['label' => 'CLOSED', 'cls' => 'red', 'duration_days' => $duration_days,
            'elapsed_days' => $days,
            'headline' => 'Evaluation period has ended.', 'sub' => 'Ended ' . ($days === 1 ? 'yesterday' : $days . ' days ago') . '.']);
    }
    $remaining = max(0, (int)ceil(($te - $now) / 86400));
    return array_merge($base, ['label' => 'LIVE', 'cls' => 'green', 'duration_days' => $duration_days,
        'remaining_days' => $remaining,
        'headline' => 'Evaluation is currently accepting submissions.', 'sub' => $remaining . ' day' . ($remaining === 1 ? '' : 's') . ' remaining.']);
}
function compute_eval_status(array $sys): array {
    $h = compute_eval_health($sys);
    // Older call sites just want a short badge label/color; map the richer
    // health labels back onto the original badge vocabulary they expect.
    $map = ['green' => 'open', 'red' => 'closed', 'yellow' => 'amber', 'gray' => 'gray'];
    $label = $h['label'];
    if ($label === 'LIVE') $label = 'OPEN';
    if ($label === 'LIVE · FORCED OPEN') $label = 'FORCED OPEN';
    if ($label === 'CLOSED · FORCED') $label = 'FORCED CLOSED';
    if ($label === 'SCHEDULED') $label = 'UPCOMING';
    if ($label === 'CLOSED') $label = 'CLOSED · ENDED';
    if ($label === 'DRAFT') $label = 'DRAFT · HIDDEN';
    return ['label' => $label, 'cls' => $map[$h['cls']] ?? 'gray'];
}

$evalHealth = compute_eval_health($sys);
$evalStatus = compute_eval_status($sys);

// ── Derived values for the "Current Configuration" summary panel ──
$RULE_KEYS = ['rule_only_during_period','rule_edit_after_submit','rule_one_submission','rule_require_all','rule_auto_lock','rule_countdown','rule_prevent_late'];
$activeRuleCount = count(array_filter($RULE_KEYS, fn($k) => !empty($sys[$k])));
$totalRuleCount  = count($RULE_KEYS);
$CONTROL_MODE_LABELS = ['schedule' => 'Follow Schedule', 'open' => 'Force Open', 'closed' => 'Force Closed'];
$controlModeLabel = $CONTROL_MODE_LABELS[$sys['control_mode']] ?? 'Follow Schedule';

$NOTIFY_KEYS = ['notify_eval_open','notify_eval_closing','notify_faculty_complete','notify_reminders'];
$notifyOnCount = count(array_filter($NOTIFY_KEYS, fn($k) => !empty($sys[$k])));

// System Health checklist — each item is [label, color-class, status-text].
// "Questionnaires" has no dedicated table wired in yet, so it's approximated
// from evaluation-tracker activity; swap in a real questionnaire-count query
// once that table is available.
$HEALTH_ITEMS = [
    ['Evaluation Window', $evalHealth['cls'] === 'gray' ? 'gray' : $evalHealth['cls'], $evalHealth['label']],
    ['Questionnaires',    $activeEvals > 0 ? 'green' : 'gray',                          $activeEvals > 0 ? 'Published' : 'Not Configured'],
    ['Faculty Registry',  $facultyCount > 0 ? 'green' : 'red',                          $facultyCount > 0 ? 'Complete' : 'Empty'],
    ['Student Registry',  $studentCount > 0 ? 'green' : 'red',                          $studentCount > 0 ? 'Complete' : 'Empty'],
    ['Automatic Schedule', !empty($sys['auto_schedule']) ? 'green' : 'yellow',          !empty($sys['auto_schedule']) ? 'Enabled' : 'Disabled'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>PBI Admin Master Workspace</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Rajdhani:wght@600;700&display=swap" rel="stylesheet"/>
    <style>
    :root{
        --dark-blue:#16304F;--blue-mid:#1F3E64;--blue-accent:#3B82F6;
        --blue-hover:#5B9BFA;--light:#F7FAFF;--muted:#B7C6DC;
        --radius:8px;--shadow:0 4px 12px rgba(0,0,0,.3);
        --indicator-bg:rgba(59,130,246,.2);
        --sev-green:#A3BE8C;--sev-yellow:#EBCB8B;--sev-red:#BF616A;--sev-blue:#81A1C1;
        --page-bg:#19365A;--card-bg:#234A78;--card-border:#4A6E99;
        --text-dark:#F7FAFF;--text-dim:#B7C6DC;--track-bg:#2E4F74;
        --card-shadow:0 2px 4px rgba(0,0,0,.25),0 6px 16px rgba(0,0,0,.3);
    }
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'Inter',sans-serif;color:var(--text-dark);min-height:100vh;background:var(--page-bg);}
    a{text-decoration:none;color:inherit;}

    /* ── NAVBAR ── */
    .nude-nav{
        background:#19365A;padding:10px 24px;
        display:flex;align-items:center;justify-content:space-between;
        position:fixed;top:0;left:0;right:0;z-index:200;
        box-shadow:var(--shadow);border-bottom:1px solid rgba(255,255,255,.1);
        gap:20px;
    }
    .nude-nav .nav-container{flex-grow:0;}

    /* ── BRAND / AVATAR ── */
    .nav-brand{display:flex;align-items:center;gap:12px;flex-shrink:0;position:relative;}
    .brand-avatar-wrap{position:relative;cursor:pointer;}
    .brand-avatar{
        width:44px;height:44px;border-radius:50%;
        border:2px solid var(--blue-accent);
        box-shadow:0 0 8px rgba(59,130,246,.6);
        overflow:hidden;display:flex;align-items:center;justify-content:center;
        background:var(--blue-accent);flex-shrink:0;
        font-size:16px;font-weight:700;color:#fff;letter-spacing:.5px;
        transition:box-shadow .2s,border-color .2s;
    }
    .brand-avatar:hover{box-shadow:0 0 14px rgba(59,130,246,.9);border-color:#93C5FD;}
    .brand-avatar img{width:100%;height:100%;object-fit:cover;display:block;}
    /* small settings gear badge on avatar */
    .avatar-gear{
        position:absolute;bottom:-2px;right:-2px;
        width:16px;height:16px;border-radius:50%;
        background:var(--blue-accent);border:2px solid var(--blue-mid);
        display:flex;align-items:center;justify-content:center;
        font-size:8px;color:#fff;pointer-events:none;
    }

    .brand-text{display:flex;flex-direction:column;gap:2px;}
    .brand-name{font-size:15px;font-weight:700;line-height:1;color:#fff;white-space:nowrap;max-width:220px;overflow:hidden;text-overflow:ellipsis;}
    .brand-role{font-size:11px;color:var(--muted);letter-spacing:.8px;text-transform:uppercase;line-height:1;}
    #digital-clock{font-size:12px;font-weight:600;color:#81A1C1;line-height:1;margin-top:2px;}
    .nav-divider{width:1px;height:30px;background:rgba(255,255,255,.15);flex-shrink:0;}

    /* ── PROFILE DROPDOWN ── */
    .profile-dropdown{
        position:absolute;top:calc(100% + 12px);left:0;width:260px;
        background:var(--blue-mid);border:1px solid rgba(255,255,255,.12);
        border-radius:14px;box-shadow:0 16px 48px rgba(0,0,0,.55);
        opacity:0;visibility:hidden;transform:translateY(-8px);
        transition:all .25s cubic-bezier(.22,1,.36,1);z-index:9999;overflow:hidden;
    }
    .profile-dropdown.show{opacity:1;visibility:visible;transform:translateY(0);}
    .pd-head{
        padding:16px 16px 14px;display:flex;align-items:center;gap:11px;
        background:linear-gradient(135deg,rgba(59,130,246,.2),rgba(22,48,79,.3));
        border-bottom:1px solid rgba(255,255,255,.07);
    }
    .pd-head-avatar{
        width:46px;height:46px;border-radius:50%;overflow:hidden;flex-shrink:0;
        border:2px solid var(--blue-accent);
        background:var(--blue-accent);display:flex;align-items:center;justify-content:center;
        font-size:16px;font-weight:700;color:#fff;
    }
    .pd-head-avatar img{width:100%;height:100%;object-fit:cover;display:block;}
    .pd-head-name{font-size:13px;font-weight:700;color:#fff;line-height:1.3;}
    .pd-head-role{font-size:11px;color:var(--muted);}
    .pd-head-badge{display:inline-flex;align-items:center;gap:3px;margin-top:4px;font-size:10px;font-weight:600;padding:2px 8px;border-radius:20px;background:rgba(59,130,246,.3);color:#93C5FD;border:1px solid rgba(59,130,246,.35);text-transform:uppercase;}
    .pd-menu{padding:6px 0;}
    .pd-item{
        display:flex;align-items:center;gap:11px;padding:9px 16px;
        font-size:13px;font-weight:500;color:var(--light);cursor:pointer;
        transition:background .15s;border:none;background:none;
        width:100%;text-align:left;font-family:'Inter',sans-serif;
    }
    .pd-item:hover{background:rgba(255,255,255,.05);}
    .pd-icon{width:26px;height:26px;border-radius:6px;background:rgba(255,255,255,.06);color:var(--muted);display:flex;align-items:center;justify-content:center;font-size:11px;flex-shrink:0;transition:all .15s;}
    .pd-item:hover .pd-icon{background:rgba(59,130,246,.25);color:#93C5FD;}
    .pd-divider{height:1px;background:rgba(255,255,255,.07);margin:4px 12px;}
    .pd-item.danger{color:#D18C96;}
    .pd-item.danger .pd-icon{color:#BF616A;background:rgba(191,97,106,.1);}
    .pd-item.danger:hover{background:rgba(191,97,106,.07);}

    /* ── NAV MENU (now horizontal, lives in the top navbar) ── */
    .nav-container{display:flex;align-items:center;justify-content:flex-end;gap:14px;}
    .nav-menu{display:flex;flex-direction:row;align-items:center;list-style:none;position:relative;gap:4px;overflow-x:auto;scrollbar-width:none;-ms-overflow-style:none;}
    .nav-menu::-webkit-scrollbar{display:none;}
    .nav-menu li{list-style:none;flex-shrink:0;}
    .indicator{position:absolute;top:0;left:0;width:0;height:100%;background:var(--indicator-bg);border-radius:var(--radius);transition:transform .3s cubic-bezier(.25,.46,.45,.94),width .3s cubic-bezier(.25,.46,.45,.94);z-index:0;opacity:0;pointer-events:none;}
    .nav-item{display:flex;align-items:center;justify-content:center;gap:9px;color:#8FA3C4;font-size:13.5px;font-weight:600;padding:9px 15px;margin:0;border-radius:var(--radius);transition:all .3s;position:relative;z-index:1;white-space:nowrap;}
    .nav-item .icon{width:16px;text-align:center;}
    .nav-item:hover{color:#fff;background:rgba(255,255,255,.05);}
    .nav-item.active{color:#fff;font-weight:700;background:rgba(59,130,246,.18);}
    .nav-item.active .icon{color:#5B9BF5;}
    .nav-item.logout{color:#E8927C;font-weight:600;margin-left:0;}
    .nav-item.logout .icon{color:#E8927C;}
    .nav-item.logout:hover{background:rgba(208,135,112,.15);color:#fff;}

    /* ── SIDEBAR ── */
    .pbi-sidebar{
        position:fixed;top:0;left:0;bottom:0;width:230px;
        background:#16294A;padding:22px 14px 16px;
        display:flex;flex-direction:column;overflow-y:auto;z-index:150;
        border-right:1px solid rgba(255,255,255,.06);
    }
    .sidebar-brand{text-align:center;padding:0 6px 20px;margin-bottom:12px;border-bottom:1px solid rgba(255,255,255,.08);}
    .sidebar-logo{width:88px;height:88px;border-radius:50%;background:rgba(124,58,237,.12);border:2.5px solid #8B5CF6;box-shadow:0 0 18px rgba(139,92,246,.4);display:flex;align-items:center;justify-content:center;font-size:30px;color:#8B5CF6;margin:0 auto 14px;overflow:hidden;}
    .sidebar-logo img{width:100%;height:100%;object-fit:cover;display:block;}
    .sidebar-title{font-size:15px;font-weight:700;color:#fff;line-height:1.3;margin-bottom:4px;}
    .sidebar-subtitle{font-size:11px;color:#8FA3C4;line-height:1.4;}
    .sidebar-logout{margin-top:auto;padding-top:14px;border-top:1px solid rgba(255,255,255,.08);}

    /* ── NOTIFICATION BELL ── */
    .notif-wrap{position:relative;display:flex;align-items:center;margin-left:4px;}
    .notif-btn{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:15px;cursor:pointer;transition:all .2s;position:relative;}
    .notif-btn:hover,.notif-btn.has-unread{color:#EBCB8B;border-color:rgba(235,203,139,.4);background:rgba(235,203,139,.08);}
    .notif-badge{position:absolute;top:-4px;right:-4px;min-width:18px;height:18px;border-radius:9px;background:#BF616A;color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;padding:0 4px;border:2px solid var(--blue-mid);opacity:0;transform:scale(0);transition:opacity .2s,transform .2s;pointer-events:none;}
    .notif-badge.show{opacity:1;transform:scale(1);}

    /* Notification panel — fixed so it never breaks layout */
    .notif-dropdown{
        position:fixed;top:70px;right:16px;width:320px;
        background:var(--blue-mid);border:1px solid rgba(255,255,255,.1);
        border-radius:14px;box-shadow:0 16px 48px rgba(0,0,0,.6);
        opacity:0;visibility:hidden;transform:translateY(-8px);
        transition:all .25s cubic-bezier(.22,1,.36,1);z-index:9998;overflow:hidden;
    }
    .notif-dropdown.show{opacity:1;visibility:visible;transform:translateY(0);}
    .notif-header{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid rgba(255,255,255,.07);}
    .notif-header-title{font-size:13px;font-weight:700;color:#fff;display:flex;align-items:center;gap:7px;}
    .notif-mark-read{font-size:11px;color:var(--blue-accent);cursor:pointer;font-weight:600;background:none;border:none;font-family:'Inter',sans-serif;padding:0;}
    .notif-list{max-height:360px;overflow-y:auto;}
    .notif-list::-webkit-scrollbar{width:4px;}
    .notif-list::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:4px;}
    .notif-list::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,.22);}
    .notif-item{display:flex;align-items:flex-start;gap:10px;padding:11px 14px;border-bottom:1px solid rgba(255,255,255,.05);position:relative;transition:background .15s;}
    .notif-item:last-child{border-bottom:none;}
    .notif-item:hover{background:rgba(255,255,255,.03);}
    .notif-item.unread{background:rgba(59,130,246,.08);}
    .notif-item.unread::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--blue-accent);border-radius:0 2px 2px 0;}
    .notif-icon{width:30px;height:30px;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:12px;margin-top:1px;}
    .notif-text{font-size:12px;color:var(--light);line-height:1.45;margin-bottom:3px;word-break:break-word;}
    .notif-meta{font-size:11px;color:var(--muted);}
    .notif-dot{width:6px;height:6px;border-radius:50%;background:#81A1C1;flex-shrink:0;margin-top:4px;}
    .notif-empty{text-align:center;padding:32px 16px;color:var(--muted);font-size:13px;}
    .notif-empty i{font-size:28px;display:block;margin-bottom:8px;opacity:.2;}
    .notif-footer{padding:9px 14px;border-top:1px solid rgba(255,255,255,.07);text-align:center;font-size:11px;color:var(--muted);display:flex;align-items:center;justify-content:center;gap:8px;}
    .live-dot{display:inline-flex;align-items:center;gap:4px;font-size:11px;color:#A3BE8C;font-weight:600;padding:2px 8px;border-radius:20px;background:rgba(163,190,140,.08);border:1px solid rgba(163,190,140,.2);}
    .live-dot::before{content:'';width:6px;height:6px;border-radius:50%;background:#A3BE8C;display:inline-block;animation:livePulse 2s ease-in-out infinite;}
    @keyframes livePulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.8)}}
    @keyframes countFlash{0%{color:#fff}50%{color:#81A1C1}100%{color:#fff}}
    .count-updated{animation:countFlash .6s ease;}

    /* ── SETTINGS MODAL (light theme) ── */
    .modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px;opacity:0;visibility:hidden;transition:all .25s;}
    .modal-overlay.show{opacity:1;visibility:visible;}
    .settings-modal{background:var(--card-bg);border:1px solid var(--card-border);border-radius:18px;width:100%;max-width:580px;max-height:92vh;overflow-y:auto;box-shadow:0 24px 64px rgba(15,23,42,.35);transform:scale(.96);transition:transform .25s cubic-bezier(.22,1,.36,1);}
    .modal-overlay.show .settings-modal{transform:scale(1);}
    .sm-header{padding:22px 24px 18px;border-bottom:1px solid var(--card-border);display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,rgba(124,58,237,.08),transparent);}
    .sm-title{font-family:'Rajdhani',sans-serif;font-size:20px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--text-dark);}
    .sm-close{width:30px;height:30px;border-radius:7px;border:1px solid var(--card-border);background:var(--page-bg);color:var(--text-dim);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .2s;font-size:13px;}
    .sm-close:hover{background:#FCE9E9;color:#DC2626;border-color:#F5C2C2;}
    .sm-tabs{display:flex;gap:3px;padding:14px 24px 0;border-bottom:1px solid var(--card-border);flex-wrap:wrap;}
    .sm-tab{padding:7px 14px;font-size:12px;font-weight:600;color:var(--text-dim);cursor:pointer;border-radius:7px 7px 0 0;border:1px solid transparent;border-bottom:none;transition:all .2s;background:none;font-family:'Inter',sans-serif;}
    .sm-tab.active{background:var(--page-bg);color:var(--text-dark);border-color:var(--card-border);}
    .sm-tab:hover:not(.active){color:var(--text-dark);}
    .sm-body{padding:22px 24px;}
    .sm-section{display:none;}
    .sm-section.active{display:block;}

    /* form elements */
    .sf-group{margin-bottom:16px;}
    .sf-label{display:block;font-size:11px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:var(--text-dim);margin-bottom:6px;}
    .sf-input{width:100%;padding:9px 12px;background:var(--page-bg);border:1px solid var(--card-border);border-radius:var(--radius);color:var(--text-dark);font-size:13px;font-family:'Inter',sans-serif;outline:none;transition:border-color .2s,box-shadow .2s;}
    .sf-input:focus{border-color:#8B5CF6;box-shadow:0 0 0 3px rgba(139,92,246,.15);}
    .sf-input:disabled{opacity:.5;cursor:not-allowed;}
    .sf-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
    .sf-hint{font-size:11px;color:var(--text-dim);margin-top:4px;}
    .sf-alert{display:flex;align-items:center;gap:8px;padding:9px 13px;border-radius:8px;font-size:13px;margin-bottom:14px;}
    .sf-alert.ok{background:#EAF6E9;border:1px solid #BFE3BD;color:#227A22;}
    .sf-alert.err{background:#FCEBEB;border:1px solid #F3B9B9;color:#B02020;}

    /* photo row */
    .sf-photo-row{display:flex;align-items:center;gap:14px;padding:13px 14px;background:var(--page-bg);border:1px dashed #C7B4F5;border-radius:9px;margin-bottom:16px;cursor:pointer;}
    .sf-photo-preview{width:58px;height:58px;border-radius:50%;border:2px solid #8B5CF6;overflow:hidden;background:#8B5CF6;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:700;color:#fff;flex-shrink:0;}
    .sf-photo-preview img{width:100%;height:100%;object-fit:cover;display:block;}
    .sf-photo-info p{font-size:13px;font-weight:600;color:var(--text-dark);margin-bottom:2px;}
    .sf-photo-info span{font-size:11px;color:var(--text-dim);}
    .sf-choose-btn{display:inline-flex;align-items:center;gap:5px;margin-top:7px;padding:5px 12px;background:#F1EBFE;border:1px solid #D8C7FA;border-radius:6px;color:#7C3AED;font-size:11px;font-weight:600;cursor:pointer;transition:all .2s;}
    .sf-choose-btn:hover{background:#E5D9FB;}

    /* toggle switch */
    .sf-toggle-row{display:flex;align-items:center;justify-content:space-between;padding:11px 0;border-bottom:1px solid var(--card-border);gap:14px;}
    .sf-toggle-row:last-child{border-bottom:none;}
    .sf-toggle-label{font-size:13px;color:var(--text-dark);}
    .sf-toggle-sub{font-size:11px;color:var(--text-dim);margin-top:1px;}
    .toggle-sw{position:relative;width:40px;height:22px;flex-shrink:0;}
    .toggle-sw input{opacity:0;width:0;height:0;}
    .toggle-slider{position:absolute;cursor:pointer;inset:0;background:#D5DAE3;border-radius:22px;transition:.3s;}
    .toggle-slider::before{content:'';position:absolute;height:16px;width:16px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.3s;}
    .toggle-sw input:checked + .toggle-slider{background:#8B5CF6;}
    .toggle-sw input:checked + .toggle-slider::before{transform:translateX(18px);}

    /* period/semester section */
    .period-card{background:var(--page-bg);border:1px solid var(--card-border);border-radius:10px;padding:16px;margin-bottom:14px;}
    .period-card-title{font-size:12px;font-weight:700;color:#7C3AED;text-transform:uppercase;letter-spacing:.8px;margin-bottom:12px;display:flex;align-items:center;gap:6px;}
    .period-status{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px;white-space:nowrap;}
    .period-status.open{background:#E4F7E4;color:#1E8E1E;border:1px solid #BFE3BD;}
    .period-status.closed{background:#FBEAE6;color:#C2542F;border:1px solid #F0C7B9;}
    .period-status.amber{background:#FDF3DE;color:#B87A08;border:1px solid #F3DDA1;}
    .period-status.gray{background:#EEF1F6;color:var(--text-dim);border:1px solid var(--card-border);}
    .period-status.green{background:#E4F7E4;color:#1E8E1E;border:1px solid #BFE3BD;}
    .period-status.yellow{background:#FDF3DE;color:#B87A08;border:1px solid #F3DDA1;}
    .period-status.red{background:#FBEAE6;color:#C2542F;border:1px solid #F0C7B9;}
    .period-status.blue{background:#E9F1FE;color:#2563EB;border:1px solid #BFDBFE;}

    /* Evaluation Access: control-mode radio cards */
    .ctrl-modes{display:flex;flex-direction:column;gap:8px;}
    .ctrl-option{display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border:1px solid var(--card-border);border-radius:8px;background:var(--page-bg);cursor:pointer;transition:all .2s;}
    .ctrl-option:hover{border-color:#C7B4F5;}
    .ctrl-option.selected{border-color:#8B5CF6;background:#F1EBFE;}
    .ctrl-option input{margin-top:3px;accent-color:#8B5CF6;flex-shrink:0;}
    .ctrl-option-title{font-size:13px;font-weight:600;color:var(--text-dark);}
    .ctrl-option-sub{font-size:11px;color:var(--text-dim);margin-top:1px;}

    /* Submission Settings: grouped subsections within one card */
    .rule-subgroup{margin-bottom:16px;}
    .rule-subgroup-title{font-size:11px;font-weight:700;color:var(--text-dim);text-transform:uppercase;letter-spacing:.7px;margin-bottom:2px;padding-bottom:6px;border-bottom:1px solid var(--card-border);}

    /* Unsaved-changes indicator in the System & Period footer */
    .unsaved-indicator{display:none;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#B87A08;margin-right:auto;padding:5px 10px;border-radius:20px;background:#FDF3DE;border:1px solid #F3DDA1;}
    .unsaved-indicator.show{display:inline-flex;}
    .unsaved-indicator i{animation:livePulse 1.6s ease-in-out infinite;}

    /* ── CURRENT CONFIGURATION SUMMARY (top of System & Period tab) ── */
    .cfg-summary{background:linear-gradient(135deg,rgba(139,92,246,.08),var(--page-bg));border:1px solid var(--card-border);border-radius:12px;padding:18px;margin-bottom:16px;}
    .cfg-headline-row{display:flex;align-items:flex-start;gap:14px;margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid var(--card-border);}
    .cfg-headline-badge{flex-shrink:0;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:700;letter-spacing:.4px;white-space:nowrap;}
    .cfg-headline-text .cfg-headline{font-size:14px;font-weight:700;color:var(--text-dark);margin-bottom:2px;}
    .cfg-headline-text .cfg-sub{font-size:12px;color:var(--text-dim);}
    .cfg-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px 20px;margin-bottom:16px;}
    .cfg-item{display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:12px;padding:6px 0;border-bottom:1px solid var(--card-border);}
    .cfg-label{color:var(--text-dim);font-weight:600;}
    .cfg-value{color:var(--text-dark);font-weight:700;text-align:right;}
    .cfg-metric-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:10px;}
    .cfg-metric{background:var(--card-bg);border:1px solid var(--card-border);border-radius:8px;padding:10px 12px;text-align:center;}
    .cfg-metric-value{font-size:18px;font-weight:700;color:var(--text-dark);}
    .cfg-metric-label{font-size:10px;color:var(--text-dim);text-transform:uppercase;letter-spacing:.5px;margin-top:2px;}

    /* System Health checklist */
    .health-list{display:flex;flex-direction:column;gap:0;}
    .health-item{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 0;border-bottom:1px solid var(--card-border);font-size:13px;}
    .health-item:last-child{border-bottom:none;}
    .health-item-name{color:var(--text-dark);font-weight:600;}
    .health-item-status{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;}
    .health-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
    .health-dot.green{background:#22A722;box-shadow:0 0 6px rgba(34,167,34,.5);}
    .health-dot.yellow{background:#D9A62A;box-shadow:0 0 6px rgba(217,166,42,.5);}
    .health-dot.red{background:#DC4444;box-shadow:0 0 6px rgba(220,68,68,.5);}
    .health-dot.gray{background:#AEB6C4;}
    .health-item-status.green{color:#1E8E1E;}
    .health-item-status.yellow{color:#B87A08;}
    .health-item-status.red{color:#C2302F;}
    .health-item-status.gray{color:var(--text-dim);}

    /* Sticky so the primary action stays reachable on long tabs (System & Period)
       without having to scroll all the way to the bottom of the modal. Sticks
       to the bottom of .settings-modal, its nearest scrolling ancestor. */
    .sm-footer{position:sticky;bottom:0;padding:14px 24px;border-top:1px solid var(--card-border);display:flex;align-items:center;justify-content:flex-end;gap:10px;background:var(--card-bg);box-shadow:0 -8px 20px rgba(15,23,42,.06);z-index:5;}
    .sf-btn{padding:9px 20px;border-radius:var(--radius);font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;font-family:'Inter',sans-serif;}
    .sf-btn-cancel{background:var(--page-bg);border:1px solid var(--card-border);color:var(--text-dim);}
    .sf-btn-cancel:hover{background:var(--card-border);color:var(--text-dark);}
    .sf-btn-discard{background:#FBEAE6;border:1px solid #F0C7B9;color:#C2542F;}
    .sf-btn-discard:hover{background:#F6D8D0;color:#A5391B;}
    .sf-btn-save{background:#8B5CF6;border:1px solid transparent;color:#fff;box-shadow:0 3px 10px rgba(139,92,246,.35);}
    .sf-btn-save:hover{background:#7C3AED;}

    /* ── PAGES ── */
    .page-content{margin-top:72px;margin-left:0;padding:16px 20px;}
    .page{display:none;background:transparent;}
    .page:has(>iframe){background:#19365A;}
    .page.active{display:block;}
    .iframe-box{width:100%;height:calc(100vh - 92px);border:none;border-radius:var(--radius);background:#19365A;}

    /* ── DASHBOARD (light card theme) ── */
    .pbi-dashboard-container{padding:30px 4px;}
    .pbi-system-title{font-size:24px;font-weight:700;margin:0 0 5px;color:var(--text-dark);}
    .pbi-system-subtitle{font-size:14px;color:var(--text-dim);margin:0 0 30px;}
    /* ── STAT CARDS (top row) ── */
    .pbi-stats-row{display:flex;gap:18px;margin-bottom:22px;flex-wrap:wrap;}
    .pbi-stat-card{background:var(--card-bg);border:1px solid var(--card-border);border-top:3px solid var(--blue-accent);border-radius:12px;padding:18px 20px;flex:1;min-width:180px;position:relative;overflow:hidden;box-shadow:var(--card-shadow);transition:transform .2s ease,box-shadow .2s ease;}
    .pbi-stat-card:hover{transform:translateY(-2px);box-shadow:0 10px 24px rgba(15,23,42,.1);}
    .pbi-stat-card.stat-blue{border-top-color:#3B82F6;}
    .pbi-stat-card.stat-blue .pbi-stat-icon{background:#E8F0FE;color:#3B82F6;}
    .pbi-stat-card.stat-purple{border-top-color:#7C3AED;}
    .pbi-stat-card.stat-purple .pbi-stat-icon{background:#F1EBFE;color:#7C3AED;}
    .pbi-stat-card.stat-green{border-top-color:#059669;}
    .pbi-stat-card.stat-green .pbi-stat-icon{background:#E4F7F0;color:#059669;}
    .pbi-stat-card.stat-orange{border-top-color:#D97706;}
    .pbi-stat-card.stat-orange .pbi-stat-icon{background:#FDF0DF;color:#D97706;}
    .pbi-stat-icon{position:absolute;top:14px;right:14px;width:38px;height:38px;border-radius:10px;font-size:15px;display:flex;align-items:center;justify-content:center;}
    .pbi-stat-label{font-size:11px;font-weight:700;color:var(--text-dim);text-transform:uppercase;letter-spacing:.7px;margin-bottom:8px;}
    .pbi-stat-value{font-size:30px;font-weight:700;color:var(--text-dark);line-height:1;margin-bottom:6px;}
    .pbi-stat-sub{font-size:12px;color:var(--text-dim);}
    .pbi-stat-trend{font-size:11px;font-weight:700;margin-top:8px;display:inline-flex;align-items:center;gap:4px;}
    .pbi-stat-trend.up{color:#059669;}
    .pbi-stat-trend.flat{color:var(--text-dim);}

    /* ── EVALUATION PERIOD CARD ── */
    .pbi-period-box{background:var(--card-bg);border:1px solid var(--card-border);border-radius:12px;padding:18px 22px;margin-bottom:26px;box-shadow:var(--card-shadow);}
    .pbi-period-top{display:flex;align-items:center;flex-wrap:wrap;gap:18px 32px;margin-bottom:14px;}
    .pbi-period-block .pbi-period-label{font-size:11px;font-weight:700;color:var(--text-dim);text-transform:uppercase;letter-spacing:.7px;margin-bottom:4px;}
    .pbi-period-value{font-size:19px;font-weight:700;color:var(--text-dark);display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
    .pbi-period-sub{font-size:12px;color:var(--text-dim);margin-top:2px;}
    .pbi-progress-wrap{flex:1;min-width:220px;}
    .pbi-progress-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;font-size:12px;color:var(--text-dim);}
    .pbi-progress-top strong{color:var(--text-dark);font-size:13px;}
    .pbi-progress-track{width:100%;height:12px;border-radius:6px;background:var(--track-bg);overflow:hidden;}
    .pbi-progress-fill{height:100%;background:linear-gradient(90deg,#3B82F6,#60A5FA);border-radius:6px;transition:width .4s ease;}
    .pbi-progress-note{font-size:11px;color:var(--text-dim);margin-top:6px;}

    .pbi-sector-row{display:flex;gap:20px;margin-bottom:30px;}
    .pbi-sector-card{background:var(--card-bg);border:1px solid var(--card-border);border-top:4px solid var(--blue-accent);color:var(--text-dark);border-radius:12px;padding:18px 20px;box-shadow:var(--card-shadow);flex:1;display:flex;flex-direction:column;gap:10px;min-height:0;transition:transform .2s ease,box-shadow .2s ease;}
    .pbi-sector-card:hover{transform:translateY(-2px);box-shadow:0 10px 24px rgba(15,23,42,.1);}
    .pbi-sector-card.teacher-card{border-top-color:#3B82F6;}
    .pbi-sector-card.staff-card{border-top-color:#7C3AED;}
    .pbi-sector-card.student-card{border-top-color:#059669;}
    .pbi-sector-top{display:flex;align-items:center;justify-content:space-between;}
    .pbi-sector-card h3{margin:0;font-size:13px;font-weight:700;letter-spacing:.8px;color:var(--text-dim);text-transform:uppercase;}
    .pbi-sector-count{font-size:32px;font-weight:700;line-height:1;}
    .pbi-sector-card.teacher-card .pbi-sector-count{color:#3B82F6;}
    .pbi-sector-card.staff-card .pbi-sector-count{color:#7C3AED;}
    .pbi-sector-card.student-card .pbi-sector-count{color:#059669;}
    .pbi-sector-meta{display:flex;gap:14px;flex-wrap:wrap;font-size:11px;color:var(--text-dim);border-top:1px solid var(--card-border);padding-top:10px;}
    .pbi-sector-meta span{display:flex;align-items:center;gap:5px;}
    .pbi-sector-meta i{font-size:10px;opacity:.8;}
    .pbi-view-btn{background:#3B82F6;color:#fff;border:none;padding:8px 18px;font-size:13px;font-weight:600;border-radius:6px;cursor:pointer;transition:background .2s;align-self:flex-start;}
    .pbi-view-btn:hover{background:#2563EB;}
    .pbi-sector-card.staff-card .pbi-view-btn{background:#7C3AED;}
    .pbi-sector-card.staff-card .pbi-view-btn:hover{background:#6D28D9;}
    .pbi-sector-card.student-card .pbi-view-btn{background:#059669;}
    .pbi-sector-card.student-card .pbi-view-btn:hover{background:#047857;}
    .pbi-cards-row{display:flex;gap:20px;margin-bottom:30px;}
    .pbi-card{background:var(--card-bg);border:1px solid var(--card-border);border-radius:12px;padding:22px;min-width:200px;box-shadow:var(--card-shadow);flex:1;}
    .pbi-card-title{font-size:13px;font-weight:600;color:var(--text-dim);margin-bottom:10px;text-transform:uppercase;letter-spacing:.5px;}
    .pbi-card-value{font-size:32px;font-weight:700;color:var(--text-dark);}
    .pbi-dashboard-grid{display:grid;grid-template-columns:2fr 1fr;gap:25px;}
    .pbi-section-box{border:1px solid var(--card-border);padding:20px;border-radius:12px;background:var(--card-bg);box-shadow:var(--card-shadow);}
    .pbi-section-heading{font-size:18px;font-weight:600;margin:0;color:var(--text-dark);}
    .pbi-section-head-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;}
    .pbi-view-all-link{font-size:12px;font-weight:700;color:#3B82F6;}
    .pbi-view-all-link:hover{color:#2563EB;text-decoration:underline;}

    /* ── SYSTEM LOGS (flat table, matches the simple log view) ── */
    .logs-table-wrap{overflow-x:auto;}
    .logs-table{width:100%;border-collapse:collapse;font-size:12.5px;}
    .logs-table thead th{
        text-align:left;font-size:10.5px;font-weight:700;color:var(--text-dim);
        text-transform:uppercase;letter-spacing:.6px;padding:0 10px 10px;
        border-bottom:1px solid var(--card-border);white-space:nowrap;
    }
    .logs-table tbody td{padding:11px 10px;border-bottom:1px solid var(--card-border);vertical-align:top;color:var(--text-dark);}
    .logs-table tbody tr:last-child td{border-bottom:none;}
    .logs-table tbody tr:hover td{background:var(--page-bg);}
    .log-datetime{color:var(--text-dim);white-space:nowrap;}
    .log-action{display:inline-flex;align-items:center;gap:6px;font-weight:600;white-space:nowrap;}
    .log-action-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}
    .log-user{font-weight:600;white-space:nowrap;}
    .log-details{color:var(--text-dim);}
    .logs-empty{padding:24px;text-align:center;color:var(--text-dim);font-size:13px;}

    /* ── QUICK ACTIONS (simplified: icon + title + sub, matches the picture) ── */
    .pbi-actions-stack{display:flex;flex-direction:column;gap:10px;}
    .pbi-action-btn{background:var(--card-bg);color:var(--text-dark);border:1px solid var(--card-border);padding:13px 14px;font-size:14px;font-weight:600;text-align:left;border-radius:10px;cursor:pointer;transition:all .2s;display:flex;align-items:center;gap:12px;width:100%;font-family:'Inter',sans-serif;}
    .pbi-action-btn:hover{background:var(--page-bg);border-color:var(--blue-accent);transform:translateY(-1px);}
    .pbi-action-icon{width:36px;height:36px;border-radius:9px;background:#E8F0FE;color:#3B82F6;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}
    .pbi-action-text{display:flex;flex-direction:column;gap:1px;}
    .pbi-action-title{font-size:13.5px;font-weight:700;color:var(--text-dark);}
    .pbi-action-sub{font-size:11.5px;font-weight:500;color:var(--text-dim);}
    .pbi-action-btn .fa-chevron-right{margin-left:auto;color:var(--text-dim);font-size:12px;}
    .pbi-actions-stack>*:nth-child(1) .pbi-action-icon{background:#E8F0FE;color:#3B82F6;}
    .pbi-actions-stack>*:nth-child(2) .pbi-action-icon{background:#E4F7F0;color:#059669;}
    .pbi-actions-stack>*:nth-child(3) .pbi-action-icon{background:#F1EBFE;color:#7C3AED;}
    .pbi-actions-stack>*:nth-child(4) .pbi-action-icon{background:#FDF0DF;color:#D97706;}
    .pbi-dropdown-container{position:relative;width:100%;}
    .pbi-dropdown-menu{display:none;background:var(--card-bg);border:1px solid var(--card-border);border-radius:6px;margin-top:5px;width:100%;box-sizing:border-box;z-index:10;position:absolute;box-shadow:0 8px 24px rgba(15,23,42,.12);}
    .pbi-dropdown-menu.show{display:block;}
    .pbi-dropdown-item{display:flex;align-items:center;gap:10px;padding:12px 20px;color:var(--text-dark);font-size:14px;font-weight:600;border-bottom:1px solid var(--card-border);}
    .pbi-dropdown-item:last-child{border-bottom:none;}
    .pbi-dropdown-item:hover{background:var(--page-bg);color:#5B9BFA;}

    /* ── RESPONSIVE ── */
    @media(max-width:900px){.nav-item span{display:none;}.nav-item{padding:9px 11px;}.page-content{padding:20px;}.iframe-box{height:600px;}.pbi-dashboard-grid{grid-template-columns:1fr;}.logs-table{font-size:11px;}}
    @media(max-width:600px){.logs-table thead th:nth-child(4),.logs-table tbody td:nth-child(4){display:none;}}
    @media(max-width:550px){.sf-row{grid-template-columns:1fr;}.notif-dropdown{right:8px;width:calc(100vw - 16px);}}
    </style>
</head>
<body>

<!-- ═══ NAVBAR ═══════════════════════════════════════════════════════════ -->
<nav class="nude-nav">

    <!-- LEFT: horizontal nav links (moved out of the sidebar) -->
    <ul class="nav-menu">
        <div class="indicator"></div>
        <li><a href="#" id="link-dashboard" onclick="showPage('dashboard',this);return false;" class="nav-item"><i class="fa-solid fa-house icon"></i> <span>Dashboard</span></a></li>
        <li><a href="#" id="link-reports"   onclick="showPage('reports',this);return false;"   class="nav-item"><i class="fa-solid fa-file-signature icon"></i> <span>Questionnaire</span></a></li>
        <li><a href="#" id="link-add Users" onclick="showPage('personnel',this);return false;" class="nav-item"><i class="fa-solid fa-id-card-clip icon"></i> <span>Add Personnels</span></a></li>
        <li><a href="#" id="link-analytics" onclick="showPage('analytics',this);return false;" class="nav-item"><i class="fa-solid fa-chart-line icon"></i> <span>Reports &amp; Analytics</span></a></li>
        <?php if (($_SESSION['role'] ?? '') === 'superadmin'): ?>
        <li><a href="#" id="link-registrations" onclick="showPage('registrations',this);return false;" class="nav-item"><i class="fa-solid fa-user-lock icon"></i> <span>Manage Registrations</span></a></li>
        <?php endif; ?>
        <li><a href="#" id="link-tracker" onclick="showPage('tracker',this);return false;" class="nav-item"><i class="fa-solid fa-user-check icon"></i> <span>Evaluation Tracker</span></a></li>
    </ul>

    <!-- RIGHT: bell, divider, avatar + name + clock -->
    <div class="nav-container">
        <div class="notif-wrap" id="notifWrap">
            <button class="notif-btn" id="notifBtn" onclick="toggleNotifDropdown(event)" title="Notifications">
                <i class="fa-regular fa-bell"></i>
                <span class="notif-badge" id="notifBadge">0</span>
            </button>
        </div>

        <div class="nav-divider"></div>

        <div class="nav-brand">
            <div class="brand-avatar-wrap" id="avatarWrap" onclick="toggleProfileDropdown()">
                <div class="brand-avatar" id="brandAvatar">
                    <?php if ($photo_src): ?>
                        <img src="<?= $photo_src ?>" alt="<?= htmlspecialchars($admin_fullname) ?>" id="brandAvatarImg"
                             onerror="this.style.display='none';document.getElementById('brandAvatarInitials').style.display='flex';"/>
                        <span id="brandAvatarInitials" style="display:none;"><?= $initials ?></span>
                    <?php else: ?>
                        <img src="" alt="" id="brandAvatarImg" style="display:none;"/>
                        <span id="brandAvatarInitials"><?= $initials ?></span>
                    <?php endif; ?>
                </div>
                <div class="avatar-gear"><i class="fa-solid fa-gear"></i></div>
            </div>

            <div class="brand-text">
                <div class="brand-name"><?= htmlspecialchars($admin_fullname) ?></div>
                <div class="brand-role"><?= display_role($_SESSION['role'] ?? 'admin') ?></div>
                <span id="digital-clock"></span>
            </div>

            <!-- Profile dropdown — anchored to brand, opens downward -->
            <div class="profile-dropdown" id="profileDropdown" onclick="event.stopPropagation()">
                <div class="pd-head">
                    <div class="pd-head-avatar">
                        <?php if ($photo_src): ?>
                            <img src="<?= $photo_src ?>" alt="" id="pdHeadImg"/>
                        <?php else: ?>
                            <span id="pdHeadInitials"><?= $initials ?></span>
                            <img src="" alt="" id="pdHeadImg" style="display:none;"/>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="pd-head-name"><?= htmlspecialchars($admin_fullname) ?></div>
                        <div class="pd-head-role">@<?= htmlspecialchars($admin_username) ?></div>
                        <div class="pd-head-badge"><i class="fa-solid fa-shield-halved" style="font-size:8px;"></i> <?= display_role($_SESSION['role']??'admin') ?></div>
                    </div>
                </div>
                <div class="pd-menu">
                    <button class="pd-item" onclick="openSettings('profile')">
                        <span class="pd-icon"><i class="fa-solid fa-user-pen"></i></span> Edit Profile &amp; Photo
                    </button>
                    <button class="pd-item" onclick="openSettings('security')">
                        <span class="pd-icon"><i class="fa-solid fa-lock"></i></span> Change Password
                    </button>
                    <button class="pd-item" onclick="openSettings('system')">
                        <span class="pd-icon"><i class="fa-solid fa-sliders"></i></span> System &amp; Period Settings
                    </button>
                    <button class="pd-item" onclick="openSettings('appearance')">
                        <span class="pd-icon"><i class="fa-solid fa-palette"></i></span> Appearance
                    </button>
                    <div class="pd-divider"></div>
                    <a href="../logout.php" class="pd-item danger"
                       onclick="return confirm('Terminate your administrative session?')">
                        <span class="pd-icon"><i class="fa-solid fa-power-off"></i></span> Sign Out
                    </a>
                </div>
            </div>
        </div>
    </div>
</nav><!-- /.nude-nav -->

<!-- ═══ SIDEBAR — removed for now; nav links moved into the top navbar above ══ -->

<!-- Notification dropdown — OUTSIDE nav, position:fixed -->
<div class="notif-dropdown" id="notifDropdown">
    <div class="notif-header">
        <span class="notif-header-title"><i class="fa-solid fa-bell" style="color:var(--blue-accent);"></i> Notifications</span>
        <button class="notif-mark-read" onclick="markAllRead()">Mark all read</button>
    </div>
    <div class="notif-list" id="notifList">
        <div class="notif-empty"><i class="fa-regular fa-bell-slash"></i>Loading…</div>
    </div>
    <div class="notif-footer">Live updates &nbsp;<span class="live-dot">LIVE</span></div>
</div>

<!-- ═══ SETTINGS MODAL ═══════════════════════════════════════════════════ -->
<div class="modal-overlay" id="settingsOverlay" onclick="closeSettings()">
<div class="settings-modal" onclick="event.stopPropagation()">
    <div class="sm-header">
        <div class="sm-title"><i class="fa-solid fa-gear" style="margin-right:8px;color:var(--blue-accent);"></i>Settings</div>
        <button class="sm-close" onclick="closeSettings()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="sm-tabs">
        <button class="sm-tab active" id="stab-profile"    onclick="switchTab('profile')"><i class="fa-solid fa-user" style="margin-right:4px;"></i>Profile</button>
        <button class="sm-tab"        id="stab-security"   onclick="switchTab('security')"><i class="fa-solid fa-lock" style="margin-right:4px;"></i>Security</button>
        <button class="sm-tab"        id="stab-system"     onclick="switchTab('system')"><i class="fa-solid fa-sliders" style="margin-right:4px;"></i>System &amp; Period</button>
        <button class="sm-tab"        id="stab-appearance" onclick="switchTab('appearance')"><i class="fa-solid fa-palette" style="margin-right:4px;"></i>Appearance</button>
    </div>

    <?php if ($sm_text): ?>
    <div style="padding:0 24px;margin-top:14px;">
        <div class="sf-alert <?= $sm_type==='ok'?'ok':'err' ?>">
            <i class="fa-solid <?= $sm_type==='ok'?'fa-circle-check':'fa-circle-exclamation' ?>"></i>
            <?= htmlspecialchars($sm_text) ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── PROFILE TAB ── -->
    <form method="POST" action="" enctype="multipart/form-data" id="formProfile">
    <input type="hidden" name="action" value="save_settings"/>
    <input type="hidden" name="tab"    value="profile"/>
    <div class="sm-section active sm-body" id="ssec-profile">
        <div class="sf-photo-row" onclick="document.getElementById('sfPhotoInput').click()">
            <div class="sf-photo-preview">
                <?php if ($photo_src): ?>
                    <img src="<?= $photo_src ?>" alt="" id="sfPhotoImg"/>
                <?php else: ?>
                    <span id="sfPhotoInitials"><?= $initials ?></span>
                    <img src="" alt="" id="sfPhotoImg" style="display:none;"/>
                <?php endif; ?>
            </div>
            <div class="sf-photo-info">
                <p>Profile Photo</p>
                <span>JPG, PNG, WebP · max 5 MB</span><br>
                <span class="sf-choose-btn" onclick="event.stopPropagation();document.getElementById('sfPhotoInput').click()">
                    <i class="fa-solid fa-camera"></i> Change Photo
                </span>
            </div>
            <input type="file" id="sfPhotoInput" name="settings_photo" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none;" onchange="previewPhoto(this)"/>
        </div>
        <div class="sf-row">
            <div class="sf-group">
                <label class="sf-label">Full Name</label>
                <input class="sf-input" type="text" name="settings_fullname" value="<?= htmlspecialchars($admin_fullname) ?>" required/>
            </div>
            <div class="sf-group">
                <label class="sf-label">Username</label>
                <input class="sf-input" type="text" value="@<?= htmlspecialchars($admin_username) ?>" disabled/>
                <div class="sf-hint">Cannot be changed.</div>
            </div>
        </div>
        <div class="sf-group">
            <label class="sf-label">Email Address</label>
            <input class="sf-input" type="email" name="stteings_email" value="<?= htmlspecialchars($admin_email) ?>"/>
        </div>
        <div class="sf-group">
            <label class="sf-label">Role</label>
            <input class="sf-input" type="text" value="<?= display_role($_SESSION['role']??'admin') ?>" disabled/>
            <div class="sf-hint">Role changes require a System Admin.</div>
        </div>
    </div>
    <div class="sm-footer" id="footer-profile">
        <button type="button" class="sf-btn sf-btn-cancel" onclick="closeSettings()">Cancel</button>
        <button type="submit" class="sf-btn sf-btn-save"><i class="fa-solid fa-floppy-disk" style="margin-right:5px;"></i>Save Profile</button>
    </div>
    </form>

    <!-- ── SECURITY TAB ── -->
    <form method="POST" action="" id="formSecurity">
    <input type="hidden" name="action" value="save_settings"/>
    <input type="hidden" name="tab"    value="security"/>
    <div class="sm-section sm-body" id="ssec-security">
        <div style="padding:11px 13px;background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.25);border-radius:8px;margin-bottom:16px;font-size:13px;color:#93C5FD;display:flex;gap:9px;">
            <i class="fa-solid fa-circle-info" style="margin-top:1px;flex-shrink:0;"></i>
            Leave blank to keep your current password.
        </div>
        <div class="sf-group">
            <label class="sf-label">New Password</label>
            <div style="position:relative;">
                <input class="sf-input" type="password" id="sfPw1" name="settings_password" placeholder="Min. 8 characters" style="padding-right:38px;" oninput="pwStrength(this.value)"/>
                <button type="button" onclick="togglePw('sfPw1','sfEye1')" style="position:absolute;right:11px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;font-size:13px;"><i class="fa-solid fa-eye" id="sfEye1"></i></button>
            </div>
            <div style="height:3px;border-radius:2px;margin-top:5px;background:rgba(255,255,255,.08);transition:all .3s;" id="sfPwBar"></div>
            <div style="font-size:11px;margin-top:3px;" id="sfPwHint"></div>
        </div>
        <div class="sf-group">
            <label class="sf-label">Confirm Password</label>
            <div style="position:relative;">
                <input class="sf-input" type="password" id="sfPw2" name="settings_confirm_pw" placeholder="Repeat new password" style="padding-right:38px;"/>
                <button type="button" onclick="togglePw('sfPw2','sfEye2')" style="position:absolute;right:11px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;font-size:13px;"><i class="fa-solid fa-eye" id="sfEye2"></i></button>
            </div>
        </div>
    </div>
    <div class="sm-footer" id="footer-security" style="display:none;">
        <button type="button" class="sf-btn sf-btn-cancel" onclick="closeSettings()">Cancel</button>
        <button type="submit" class="sf-btn sf-btn-save"><i class="fa-solid fa-shield-halved" style="margin-right:5px;"></i>Change Password</button>
    </div>
    </form>

    <!-- ── SYSTEM & PERIOD TAB ── -->
    <form method="POST" action="" id="formSystem">
    <input type="hidden" name="action" value="save_settings"/>
    <input type="hidden" name="tab"    value="system"/>
    <div class="sm-section sm-body" id="ssec-system">

        <!-- Current Configuration — a read-at-a-glance summary so an admin
             doesn't have to open every card below just to answer "what's the
             system doing right now?" -->
        <div class="cfg-summary">
            <div class="cfg-headline-row">
                <span class="cfg-headline-badge period-status <?= $evalHealth['cls'] ?>"><?= htmlspecialchars($evalHealth['label']) ?></span>
                <div class="cfg-headline-text">
                    <div class="cfg-headline"><?= htmlspecialchars($evalHealth['headline']) ?></div>
                    <div class="cfg-sub"><?= htmlspecialchars($evalHealth['sub']) ?></div>
                </div>
            </div>

            <div class="cfg-grid">
                <div class="cfg-item"><span class="cfg-label">Academic Year</span><span class="cfg-value"><?= htmlspecialchars($sys['acad_year']) ?></span></div>
                <div class="cfg-item"><span class="cfg-label">Structure</span><span class="cfg-value"><?= htmlspecialchars($STRUCTURE_LABELS[$sys['acad_structure']] ?? 'College') ?></span></div>
                <div class="cfg-item"><span class="cfg-label">Current Term</span><span class="cfg-value"><?= htmlspecialchars($sys['acad_term']) ?></span></div>
                <div class="cfg-item"><span class="cfg-label">Submission Window</span><span class="cfg-value">
                    <?= $sys['eval_start'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($sys['eval_start']))) : '—' ?>
                    → <?= $sys['eval_end'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($sys['eval_end']))) : '—' ?>
                </span></div>
                <div class="cfg-item"><span class="cfg-label">Submission Mode</span><span class="cfg-value"><?= htmlspecialchars($controlModeLabel) ?></span></div>
                <div class="cfg-item"><span class="cfg-label">Student Editing</span><span class="cfg-value"><?= !empty($sys['rule_edit_after_submit']) ? 'Enabled' : 'Disabled' ?></span></div>
                <div class="cfg-item"><span class="cfg-label">Current Rule Set</span><span class="cfg-value"><?= $activeRuleCount ?> / <?= $totalRuleCount ?> Active Rules</span></div>
            </div>

            <div class="cfg-metric-row">
                <div class="cfg-metric"><div class="cfg-metric-value"><?= $evalHealth['duration_days'] !== null ? $evalHealth['duration_days'] : '—' ?></div><div class="cfg-metric-label">Duration (days)</div></div>
                <div class="cfg-metric"><div class="cfg-metric-value"><?php
                    if ($evalHealth['remaining_days'] !== null) echo $evalHealth['remaining_days'];
                    elseif ($evalHealth['days_until_start'] !== null) echo $evalHealth['days_until_start'];
                    elseif ($evalHealth['elapsed_days'] !== null) echo $evalHealth['elapsed_days'];
                    else echo '—';
                ?></div><div class="cfg-metric-label"><?php
                    if ($evalHealth['remaining_days'] !== null) echo 'Remaining';
                    elseif ($evalHealth['days_until_start'] !== null) echo 'Until Start';
                    elseif ($evalHealth['elapsed_days'] !== null) echo 'Days Ago (Ended)';
                    else echo 'Remaining';
                ?></div></div>
                <div class="cfg-metric"><div class="cfg-metric-value"><?= number_format($studentCount) ?></div><div class="cfg-metric-label">Participants</div></div>
                <div class="cfg-metric"><div class="cfg-metric-value"><?= number_format($submittedCount) ?></div><div class="cfg-metric-label">Completed</div></div>
            </div>
        </div>

        <!-- System Health — a quick pre-flight checklist covering the parts of
             the system that determine whether students can evaluate at all. -->
        <div class="period-card">
            <div class="period-card-title"><i class="fa-solid fa-heart-pulse"></i> System Health</div>
            <div class="health-list">
                <?php foreach ($HEALTH_ITEMS as [$hName, $hCls, $hText]): ?>
                <div class="health-item">
                    <span class="health-item-name"><?= htmlspecialchars($hName) ?></span>
                    <span class="health-item-status <?= $hCls ?>"><span class="health-dot <?= $hCls ?>"></span><?= htmlspecialchars($hText) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Academic Configuration -->
        <div class="period-card">
            <div class="period-card-title">
                <i class="fa-solid fa-graduation-cap"></i> Academic Configuration
            </div>
            <div class="sf-row">
                <div class="sf-group">
                    <label class="sf-label">Academic Year</label>
                    <input class="sf-input" type="text" name="sys_acad_year" value="<?= htmlspecialchars($sys['acad_year']) ?>" placeholder="e.g. 2026-2027"/>
                </div>
                <div class="sf-group">
                    <label class="sf-label">Academic Structure</label>
                    <select class="sf-input" id="sysAcadStructure" name="sys_acad_structure" onchange="onStructureChange()">
                        <?php foreach ($STRUCTURE_LABELS as $val => $label): ?>
                        <option value="<?= $val ?>" <?= ($sys['acad_structure']===$val)?'selected':'' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="sf-group" style="margin-bottom:0;">
                <label class="sf-label">Academic Term</label>
                <select class="sf-input" id="sysAcadTerm" name="sys_acad_term"></select>
                <div class="sf-hint">Choices update automatically with Academic Structure — prevents invalid combinations like Summer for Junior High School.</div>
            </div>
        </div>

        <!-- Evaluation Schedule -->
        <div class="period-card">
            <div class="period-card-title">
                <i class="fa-solid fa-calendar-days"></i> Evaluation Schedule
            </div>
            <div class="sf-row">
                <div class="sf-group">
                    <label class="sf-label">Evaluation Opens</label>
                    <input class="sf-input" type="datetime-local" name="eval_start_date" value="<?= htmlspecialchars($sys['eval_start'] ?? '') ?>"/>
                </div>
                <div class="sf-group">
                    <label class="sf-label">Evaluation Closes</label>
                    <input class="sf-input" type="datetime-local" name="eval_end_date" value="<?= htmlspecialchars($sys['eval_end'] ?? '') ?>"/>
                </div>
            </div>
            <div class="sf-toggle-row" style="border-bottom:none;padding-bottom:0;">
                <div><div class="sf-toggle-label">Automatic Schedule</div><div class="sf-toggle-sub">Open and close submissions automatically at the times above</div></div>
                <label class="toggle-sw"><input type="checkbox" name="sys_auto_schedule" <?= !empty($sys['auto_schedule'])?'checked':'' ?>><span class="toggle-slider"></span></label>
            </div>
        </div>

        <!-- Publish Evaluation -->
        <div class="period-card">
            <div class="period-card-title"><i class="fa-solid fa-eye"></i> Publish Evaluation</div>
            <div class="ctrl-modes" id="publishModes">
                <label class="ctrl-option" data-mode="draft">
                    <input type="radio" name="sys_publish_state" value="draft" <?= ($sys['publish_state']==='draft')?'checked':'' ?> onchange="onPublishChange()">
                    <div>
                        <div class="ctrl-option-title">Save as Draft</div>
                        <div class="ctrl-option-sub">Configure everything without making it visible to students or faculty yet</div>
                    </div>
                </label>
                <label class="ctrl-option" data-mode="published">
                    <input type="radio" name="sys_publish_state" value="published" <?= ($sys['publish_state']!=='draft')?'checked':'' ?> onchange="onPublishChange()">
                    <div>
                        <div class="ctrl-option-title">Publish Immediately</div>
                        <div class="ctrl-option-sub">Make the evaluation period visible, subject to the access rules below</div>
                    </div>
                </label>
            </div>
        </div>

        <!-- Evaluation Access -->
        <div class="period-card">
            <div class="period-card-title">
                <i class="fa-solid fa-toggle-on"></i> Evaluation Access
                <span class="period-status <?= $evalStatus['cls'] ?>" style="margin-left:auto;">
                    <i class="fa-solid fa-circle" style="font-size:7px;"></i>
                    <?= htmlspecialchars($evalStatus['label']) ?>
                </span>
            </div>
            <div class="ctrl-modes" id="ctrlModes">
                <label class="ctrl-option" data-mode="schedule">
                    <input type="radio" name="sys_control_mode" value="schedule" <?= ($sys['control_mode']==='schedule')?'checked':'' ?> onchange="onControlModeChange()">
                    <div>
                        <div class="ctrl-option-title">Follow Schedule</div>
                        <div class="ctrl-option-sub">Status is determined automatically from the evaluation window</div>
                    </div>
                </label>
                <label class="ctrl-option" data-mode="open">
                    <input type="radio" name="sys_control_mode" value="open" <?= ($sys['control_mode']==='open')?'checked':'' ?> onchange="onControlModeChange()">
                    <div>
                        <div class="ctrl-option-title">Force Open</div>
                        <div class="ctrl-option-sub">Allow submissions regardless of the scheduled period</div>
                    </div>
                </label>
                <label class="ctrl-option" data-mode="closed">
                    <input type="radio" name="sys_control_mode" value="closed" <?= ($sys['control_mode']==='closed')?'checked':'' ?> onchange="onControlModeChange()">
                    <div>
                        <div class="ctrl-option-title">Force Closed</div>
                        <div class="ctrl-option-sub">Block submissions regardless of the scheduled period</div>
                    </div>
                </label>
            </div>
        </div>

        <!-- Submission Settings (grouped) -->
        <div class="period-card">
            <div class="period-card-title"><i class="fa-solid fa-list-check"></i> Submission Settings</div>
            <?php
            $RULE_GROUPS = [
                'Submission Rules' => [
                    'rule_only_during_period' => ['Allow submissions only during the evaluation period', 'Blocks submissions outside the configured window'],
                    'rule_one_submission'     => ['Allow only one submission', 'Prevents duplicate or repeated submissions'],
                    'rule_prevent_late'       => ['Prevent submissions after the closing time', 'Hard stop once the window closes'],
                ],
                'Editing' => [
                    'rule_edit_after_submit'  => ['Allow students to edit after submission', 'Off by default to protect evaluation integrity'],
                    'rule_auto_lock'          => ['Automatically lock an evaluation after submission', 'Prevents further changes once submitted'],
                ],
                'Validation' => [
                    'rule_require_all'        => ['Require all required evaluations before submission', 'Blocks partial submissions'],
                ],
                'User Experience' => [
                    'rule_countdown'          => ['Show a countdown before the evaluation closes', 'Warns students the window is ending'],
                ],
            ];
            $groupNames = array_keys($RULE_GROUPS); $lastGroup = end($groupNames);
            foreach ($RULE_GROUPS as $groupName => $rules):
                $ruleKeys = array_keys($rules); $lastKey = end($ruleKeys);
            ?>
            <div class="rule-subgroup" <?= ($groupName===$lastGroup)?'style="margin-bottom:0;"':'' ?>>
                <div class="rule-subgroup-title"><?= htmlspecialchars($groupName) ?></div>
                <?php foreach ($rules as $key => $labelSub): [$label, $sub] = $labelSub; ?>
                <div class="sf-toggle-row" <?= ($key===$lastKey)?'style="border-bottom:none;"':'' ?>>
                    <div><div class="sf-toggle-label"><?= htmlspecialchars($label) ?></div><div class="sf-toggle-sub"><?= htmlspecialchars($sub) ?></div></div>
                    <label class="toggle-sw"><input type="checkbox" name="<?= $key ?>" <?= !empty($sys[$key])?'checked':'' ?>><span class="toggle-slider"></span></label>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Notifications -->
        <div class="period-card">
            <div class="period-card-title"><i class="fa-solid fa-bell"></i> Notifications</div>
            <?php
            $NOTIFS = [
                'notify_eval_open'        => ['Notify students when evaluation opens', 'Sent as soon as the period becomes accessible'],
                'notify_eval_closing'     => ['Notify students before closing', 'A heads-up reminder ahead of the deadline'],
                'notify_faculty_complete' => ['Notify faculty after completion', 'Sent once a student finishes their submissions'],
                'notify_reminders'        => ['Send reminder emails', 'Periodic nudges to students who have not submitted yet'],
            ];
            $notifKeys = array_keys($NOTIFS); $lastNotif = end($notifKeys);
            foreach ($NOTIFS as $key => $labelSub): [$label, $sub] = $labelSub;
            ?>
            <div class="sf-toggle-row" <?= ($key===$lastNotif)?'style="border-bottom:none;"':'' ?>>
                <div><div class="sf-toggle-label"><?= htmlspecialchars($label) ?></div><div class="sf-toggle-sub"><?= htmlspecialchars($sub) ?></div></div>
                <label class="toggle-sw"><input type="checkbox" name="<?= $key ?>" <?= !empty($sys[$key])?'checked':'' ?>><span class="toggle-slider"></span></label>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Maintenance -->
        <div class="sf-toggle-row">
            <div><div class="sf-toggle-label">Maintenance Mode</div><div class="sf-toggle-sub">Lock system for all non-admins; admins keep access</div></div>
            <label class="toggle-sw"><input type="checkbox" name="sys_maintenance" <?= !empty($sys['maintenance'])?'checked':'' ?>><span class="toggle-slider"></span></label>
        </div>
    </div>
    <div class="sm-footer" id="footer-system" style="display:none;">
        <span class="unsaved-indicator" id="unsavedIndicator"><i class="fa-solid fa-circle" style="font-size:7px;"></i> Unsaved changes</span>
        <button type="button" class="sf-btn sf-btn-discard" id="discardBtn" style="display:none;" onclick="discardSystemChanges()"><i class="fa-solid fa-rotate-left" style="margin-right:5px;"></i>Discard</button>
        <button type="button" class="sf-btn sf-btn-cancel" onclick="closeSettings()">Cancel</button>
        <button type="submit" class="sf-btn sf-btn-save"><i class="fa-solid fa-floppy-disk" style="margin-right:5px;"></i>Save System Settings</button>
    </div>
    </form>

    <!-- ── APPEARANCE TAB ── -->
    <div class="sm-section sm-body" id="ssec-appearance">
        <div class="sf-toggle-row">
            <div><div class="sf-toggle-label">Compact Navigation</div><div class="sf-toggle-sub">Show icons only in the navbar</div></div>
            <label class="toggle-sw"><input type="checkbox" id="togCompact" onchange="applyCompact(this.checked)"><span class="toggle-slider"></span></label>
        </div>
        <div class="sf-group" style="margin-top:16px;">
            <label class="sf-label">Accent Color</label>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:6px;">
                <?php foreach(['#5E81AC'=>'Ocean Blue','#7C3AED'=>'Violet','#059669'=>'Emerald','#DC2626'=>'Crimson','#D97706'=>'Amber'] as $hex=>$name): ?>
                <label style="cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:4px;">
                    <input type="radio" name="accent_color" value="<?= $hex ?>" style="display:none;" onchange="applyAccent('<?= $hex ?>')" <?= $hex==='#5E81AC'?'checked':'' ?>>
                    <span style="width:28px;height:28px;border-radius:50%;background:<?= $hex ?>;display:block;border:3px solid transparent;transition:border-color .2s;" class="clr-swatch" data-color="<?= $hex ?>"></span>
                    <span style="font-size:10px;color:var(--muted);"><?= $name ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="sm-footer" id="footer-appearance" style="display:none;">
        <button type="button" class="sf-btn sf-btn-save" onclick="closeSettings()">Done</button>
    </div>

</div><!-- /.settings-modal -->
</div><!-- /.modal-overlay -->

<!-- ═══ PAGE CONTENT ═══════════════════════════════════════════════════════ -->
<div class="page-content">
    <div id="dashboard" class="page">
        <div class="pbi-dashboard-container">

            <!-- ── STAT CARDS ── -->
            <div class="pbi-stats-row">
                <div class="pbi-stat-card stat-blue">
                    <i class="fa-solid fa-users pbi-stat-icon"></i>
                    <div class="pbi-stat-label">Total Users</div>
                    <div class="pbi-stat-value" id="cnt-total"><?= number_format($totalUsers) ?></div>
                    <div class="pbi-stat-sub">Faculty + Staff + Students</div>
                    <div class="pbi-stat-trend flat" id="trend-total"><i class="fa-solid fa-minus"></i> No change yet</div>
                </div>
                <div class="pbi-stat-card stat-purple">
                    <i class="fa-solid fa-chalkboard-user pbi-stat-icon"></i>
                    <div class="pbi-stat-label">Faculty</div>
                    <div class="pbi-stat-value" id="cnt-faculty"><?= number_format($facultyCount) ?></div>
                    <div class="pbi-stat-sub">Includes Staff accounts</div>
                    <div class="pbi-stat-trend flat" id="trend-faculty"><i class="fa-solid fa-minus"></i> No change yet</div>
                </div>
                <div class="pbi-stat-card stat-green">
                    <i class="fa-solid fa-graduation-cap pbi-stat-icon"></i>
                    <div class="pbi-stat-label">Students</div>
                    <div class="pbi-stat-value" id="cnt-students"><?= number_format($studentCount) ?></div>
                    <div class="pbi-stat-sub">Enrolled this period</div>
                    <div class="pbi-stat-trend flat" id="trend-students"><i class="fa-solid fa-minus"></i> No change yet</div>
                </div>
                <div class="pbi-stat-card stat-orange">
                    <i class="fa-solid fa-file-signature pbi-stat-icon"></i>
                    <div class="pbi-stat-label">Eval Records</div>
                    <div class="pbi-stat-value" id="cnt-evals"><?= number_format($activeEvals) ?></div>
                    <div class="pbi-stat-sub">Evaluation submissions</div>
                    <div class="pbi-stat-trend flat" id="trend-evals"><i class="fa-solid fa-minus"></i> No change yet</div>
                </div>
            </div>

            <!-- ── EVALUATION PERIOD ── -->
            <div class="pbi-period-box">
                <div class="pbi-period-top">
                    <div class="pbi-period-block">
                        <div class="pbi-period-label">Evaluation Period</div>
                        <div class="pbi-period-value">
                            <?= htmlspecialchars($sys['acad_year']) ?> · <?= htmlspecialchars($STRUCTURE_LABELS[$sys['acad_structure']] ?? 'College') ?> · <?= htmlspecialchars($sys['acad_term']) ?>
                            <span class="period-status <?= $evalStatus['cls'] ?>">
                                <i class="fa-solid fa-circle" style="font-size:7px;"></i>
                                <?= htmlspecialchars($evalStatus['label']) ?>
                            </span>
                        </div>
                        <div class="pbi-period-sub"><?= htmlspecialchars($sys['acad_term']) ?></div>
                    </div>
                    <div class="pbi-progress-wrap">
                        <div class="pbi-progress-top"><span>Submission Progress</span><strong id="progress-pct"><?= $submissionPct ?>%</strong></div>
                        <div class="pbi-progress-track"><div class="pbi-progress-fill" id="progress-fill" style="width:<?= min(100,$submissionPct) ?>%;"></div></div>
                        <div class="pbi-progress-note" id="progress-note"><?= $submittedCount ?> of <?= $studentCount ?> students submitted</div>
                    </div>
                </div>
            </div>

            <!-- ── SECTOR CARDS ── -->
            <div class="pbi-sector-row">
                <div class="pbi-sector-card teacher-card">
                    <div class="pbi-sector-top">
                        <h3>Teacher</h3>
                        <div class="pbi-sector-count" id="cnt-teacher"><?= $teacherCount ?></div>
                    </div>
                    <div class="pbi-sector-meta">
                        <span><i class="fa-solid fa-circle-check"></i> Active: <?= $teacherCount ?></span>
                        <span><i class="fa-solid fa-clock"></i> Last registered: —</span>
                    </div>
                    <button type="button" class="pbi-view-btn" onclick="openSector('Teacher')">View</button>
                </div>
                <div class="pbi-sector-card staff-card">
                    <div class="pbi-sector-top">
                        <h3>Staff</h3>
                        <div class="pbi-sector-count" id="cnt-staff"><?= $staffCount ?></div>
                    </div>
                    <div class="pbi-sector-meta">
                        <span><i class="fa-solid fa-circle-check"></i> Active: <?= $staffCount ?></span>
                        <span><i class="fa-solid fa-clock"></i> Last registered: —</span>
                    </div>
                    <button type="button" class="pbi-view-btn" onclick="openSector('Staff')">View</button>
                </div>
                <div class="pbi-sector-card student-card">
                    <div class="pbi-sector-top">
                        <h3>Student</h3>
                        <div class="pbi-sector-count" id="cnt-student"><?= $studentCount ?></div>
                    </div>
                    <div class="pbi-sector-meta">
                        <span><i class="fa-solid fa-circle-check"></i> Active: <?= $studentCount ?></span>
                        <span><i class="fa-solid fa-hourglass-half"></i> Pending evals: <?= max(0, $studentCount - $submittedCount) ?></span>
                    </div>
                    <button type="button" class="pbi-view-btn" onclick="openSector('Student')">View</button>
                </div>
            </div>

            <div class="pbi-dashboard-grid">
                <div class="pbi-section-box">
                    <div class="pbi-section-head-row">
                        <h2 class="pbi-section-heading">System Logs</h2>
                        <a href="#" onclick="showPage('tracker',document.getElementById('link-tracker'));return false;" class="pbi-view-all-link">View All Logs</a>
                    </div>
                    <div class="logs-table-wrap">
                        <table class="logs-table">
                            <thead>
                                <tr>
                                    <th>Date &amp; Time</th>
                                    <th>Action</th>
                                    <th>User</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody id="logsTableBody">
                                <tr><td colspan="4" class="logs-empty">Loading recent activity…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="pbi-section-box">
                    <h2 class="pbi-section-heading" style="margin-bottom:16px;">Quick Actions</h2>
                    <div class="pbi-actions-stack">
                        <button class="pbi-action-btn" onclick="openSettings('system')">
                            <span class="pbi-action-icon"><i class="fa-solid fa-calendar-check"></i></span>
                            <span class="pbi-action-text">
                                <span class="pbi-action-title">Open Evaluation Period</span>
                                <span class="pbi-action-sub">Open or close the evaluation period</span>
                            </span>
                            <i class="fa-solid fa-chevron-right"></i>
                        </button>
                        <button class="pbi-action-btn" onclick="showPage('personnel',document.getElementById('link-add Users'))">
                            <span class="pbi-action-icon"><i class="fa-solid fa-user-plus"></i></span>
                            <span class="pbi-action-text">
                                <span class="pbi-action-title">Add New Employee</span>
                                <span class="pbi-action-sub">Register a new faculty, staff or student</span>
                            </span>
                            <i class="fa-solid fa-chevron-right"></i>
                        </button>
                        <button class="pbi-action-btn" onclick="showPage('personnel',document.getElementById('link-add Users'))">
                            <span class="pbi-action-icon"><i class="fa-solid fa-file-import"></i></span>
                            <span class="pbi-action-text">
                                <span class="pbi-action-title">Import Users</span>
                                <span class="pbi-action-sub">Import users in bulk (Excel/CSV)</span>
                            </span>
                            <i class="fa-solid fa-chevron-right"></i>
                        </button>
                        <button class="pbi-action-btn" onclick="showPage('analytics',document.getElementById('link-analytics'))">
                            <span class="pbi-action-icon"><i class="fa-solid fa-chart-simple"></i></span>
                            <span class="pbi-action-text">
                                <span class="pbi-action-title">Generate Reports</span>
                                <span class="pbi-action-sub">Generate summary or detailed reports</span>
                            </span>
                            <i class="fa-solid fa-chevron-right"></i>
                        </button>
                        <?php if (($_SESSION['role'] ?? '') === 'superadmin'): ?>
                        <a class="pbi-action-btn" href="teaching_assignments.php">
                            <span class="pbi-action-icon" style="background:#E4F7F0;color:#059669;"><i class="fa-solid fa-chalkboard-user"></i></span>
                            <span class="pbi-action-text">
                                <span class="pbi-action-title">Manage Teaching Assignments</span>
                                <span class="pbi-action-sub">Assign teachers &amp; staff to sections</span>
                            </span>
                            <i class="fa-solid fa-chevron-right"></i>
                        </a>
                        <?php endif; ?>
                        <div class="pbi-dropdown-container">
                            <div class="pbi-action-btn" onclick="toggleRoleMenu()">
                                <span class="pbi-action-icon" style="background:#F1EBFE;color:#7C3AED;"><i class="fa-solid fa-shuffle"></i></span>
                                <span class="pbi-action-text">
                                    <span class="pbi-action-title">Switch System Role</span>
                                    <span class="pbi-action-sub">Change how you're viewing the system</span>
                                </span>
                                <i class="fa-solid fa-caret-down"></i>
                            </div>
                            <div class="pbi-dropdown-menu" id="quick-role-menu">
                                <a href="#" class="pbi-dropdown-item" onclick="switchRole('admin');return false;"><i class="fa-solid fa-user-shield"></i> Master Admin View</a>
                                <a href="#" class="pbi-dropdown-item" onclick="switchRole('evaluator');return false;"><i class="fa-solid fa-users-viewfinder"></i> Exit to Gateway Portal</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="reports"       class="page"><iframe src="questionnaire.php"              class="iframe-box"></iframe></div>
    <div id="personnel"     class="page"><iframe src="add_personnels.php"             class="iframe-box"></iframe></div>
    <div id="analytics"     class="page"><iframe src="admin_analytics.php"            class="iframe-box"></iframe></div>
    <div id="registrations" class="page"><iframe src="manage_privileged_accounts.php" class="iframe-box" id="registrationsFrame"></iframe></div>
    <div id="tracker"       class="page"><iframe src="evaluation_tracker.php"        class="iframe-box"></iframe></div>
</div>

<script>
/* ── CLOCK ── */
function updateClock(){const n=new Date(),t=[n.getHours(),n.getMinutes(),n.getSeconds()].map(x=>String(x).padStart(2,'0')).join(':');const e=document.getElementById('digital-clock');if(e)e.textContent=t;}
setInterval(updateClock,1000);updateClock();

/* ── PROFILE DROPDOWN ── */
let _pdOpen=false;
function toggleProfileDropdown(){
    _pdOpen=!_pdOpen;
    document.getElementById('profileDropdown').classList.toggle('show',_pdOpen);
}
document.addEventListener('click',function(e){
    if(!document.getElementById('avatarWrap')?.contains(e.target)&&!document.getElementById('profileDropdown')?.contains(e.target)){
        _pdOpen=false;document.getElementById('profileDropdown')?.classList.remove('show');
    }
    if(!document.getElementById('notifWrap')?.contains(e.target)&&!document.getElementById('notifDropdown')?.contains(e.target)){
        _notifOpen=false;document.getElementById('notifDropdown')?.classList.remove('show');
    }
    const dc=document.querySelector('.pbi-dropdown-container'),menu=document.getElementById('quick-role-menu');
    if(dc&&!dc.contains(e.target)&&menu)menu.classList.remove('show');
});

/* ── SETTINGS MODAL ── */
function openSettings(tab){
    _pdOpen=false;document.getElementById('profileDropdown').classList.remove('show');
    document.getElementById('settingsOverlay').classList.add('show');
    document.body.style.overflow='hidden';
    switchTab(tab||'profile');
}
function closeSettings(){
    if(_systemDirty && document.getElementById('ssec-system')?.classList.contains('active')){
        if(!confirm('You have unsaved changes in System & Period settings. Close without saving?')) return;
    }
    document.getElementById('settingsOverlay').classList.remove('show');document.body.style.overflow='';
}
function switchTab(tab){
    document.querySelectorAll('.sm-tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.sm-section').forEach(s=>s.classList.remove('active'));
    document.querySelectorAll('[id^="footer-"]').forEach(f=>f.style.display='none');
    document.getElementById('stab-'+tab)?.classList.add('active');
    document.getElementById('ssec-'+tab)?.classList.add('active');
    const footer=document.getElementById('footer-'+tab);
    if(footer)footer.style.display='flex';
}
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeSettings();});

/* ── NAV / PAGES ── */
function showPage(pageId,element){
    document.querySelectorAll('.page').forEach(p=>p.classList.remove('active'));
    document.getElementById(pageId)?.classList.add('active');
    document.querySelectorAll('.nav-menu .nav-item:not(.logout)').forEach(l=>l.classList.remove('active'));
    if(element&&!element.classList.contains('logout')){element.classList.add('active');moveIndicator(element);}
    localStorage.setItem('activePage',pageId);
}
function openSector(name){
    const frame = document.getElementById('registrationsFrame');
    if(frame) frame.src = 'manage_privileged_accounts.php?role=' + encodeURIComponent(name.toLowerCase());
    showPage('registrations', document.getElementById('link-registrations'));
}
function moveIndicator(el){
    const ind=document.querySelector('.indicator'),menu=document.querySelector('.nav-menu');
    if(!ind||!el||el.classList.contains('logout')||!menu){if(ind)ind.style.opacity=0;return;}
    const mr=menu.getBoundingClientRect(),er=el.getBoundingClientRect();
    ind.style.height=er.height+'px';
    ind.style.transform=`translateY(${er.top-mr.top}px)`;ind.style.opacity=1;
}
window.addEventListener('resize',()=>{const a=document.querySelector('.nav-item.active');if(a)moveIndicator(a);});

/* ── DASHBOARD DROPDOWN ── */
function toggleRoleMenu(){document.getElementById('quick-role-menu').classList.toggle('show');}
function switchRole(role){
    document.getElementById('quick-role-menu').classList.remove('show');
    if(role==='evaluator')window.location.href='choose_role.php';
    else alert('You are currently managing the Master Admin Dashboard panel.');
}

/* ── PHOTO PREVIEW ── */
function previewPhoto(input){
    if(!input.files||!input.files[0])return;
    const reader=new FileReader();
    reader.onload=e=>{
        const img=document.getElementById('sfPhotoImg'),ini=document.getElementById('sfPhotoInitials');
        img.src=e.target.result;img.style.display='block';if(ini)ini.style.display='none';
    };
    reader.readAsDataURL(input.files[0]);
}

/* ── PASSWORD STRENGTH ── */
function pwStrength(val){
    const bar=document.getElementById('sfPwBar'),hint=document.getElementById('sfPwHint');let s=0;
    if(val.length>=8)s++;if(/[A-Z]/.test(val))s++;if(/[0-9]/.test(val))s++;if(/[^A-Za-z0-9]/.test(val))s++;
    const c=['#BF616A','#f97316','#eab308','#A3BE8C'],l=['Weak','Fair','Good','Strong'];
    if(!val){bar.style.background='rgba(255,255,255,.08)';hint.textContent='';return;}
    bar.style.background=c[s-1]||c[0];hint.textContent=l[s-1]||'Weak';hint.style.color=c[s-1]||c[0];
}
function togglePw(id,iconId){const el=document.getElementById(id),ic=document.getElementById(iconId);el.type=el.type==='password'?'text':'password';ic.className=el.type==='password'?'fa-solid fa-eye':'fa-solid fa-eye-slash';}

/* ── APPEARANCE ── */
function applyAccent(color){document.documentElement.style.setProperty('--blue-accent',color);localStorage.setItem('pbi_accent',color);document.querySelectorAll('.clr-swatch').forEach(s=>s.style.borderColor=s.dataset.color===color?'#fff':'transparent');}
function applyCompact(on){document.querySelectorAll('.nav-item span').forEach(s=>s.style.display=on?'none':'');localStorage.setItem('pbi_compact',on?'1':'0');}

/* ── SYSTEM & PERIOD: dynamic Academic Term dropdown + control-mode highlight ──
   The valid Term choices depend on the selected Academic Structure. This now
   comes straight from the shared service (ss_structure_terms()) instead of a
   hardcoded JS object, so PHP and JS can never drift out of sync — add a new
   structure/term in one place (the service) and both sides pick it up. */
const TERM_OPTIONS = <?= json_encode(ss_structure_terms()) ?>;
function populateAcadTerms(selected){
    const structureSel = document.getElementById('sysAcadStructure');
    const termSel = document.getElementById('sysAcadTerm');
    if(!structureSel || !termSel) return;
    const options = TERM_OPTIONS[structureSel.value] || TERM_OPTIONS.college;
    termSel.innerHTML = '';
    options.forEach(opt=>{
        const o=document.createElement('option');
        o.value=opt; o.textContent=opt;
        termSel.appendChild(o);
    });
    if(selected && options.includes(selected)) termSel.value = selected;
    termSel.disabled = options.length === 1;
}
function onStructureChange(){ populateAcadTerms(); }
function onControlModeChange(){
    document.querySelectorAll('.ctrl-option').forEach(opt=>{
        opt.classList.toggle('selected', opt.querySelector('input').checked);
    });
}
function onPublishChange(){ onControlModeChange(); }
const INITIAL_ACAD_TERM = '<?= htmlspecialchars($sys['acad_term']) ?>';
populateAcadTerms(INITIAL_ACAD_TERM);
onControlModeChange();

/* ── UNSAVED CHANGES: System & Period tab ──
   Tracks real edits (input/change events only fire on an actual user
   interaction, so the flag never flips just from opening the tab or from the
   page's own initial render) so an admin can't accidentally lose
   configuration by closing the modal or navigating away without saving.
   Save writes to the server and reloads the page, which naturally clears all
   of this; Discard reverts the form in place without a reload. */
let _systemDirty = false;
function markSystemDirty(){
    if(_systemDirty) return;
    _systemDirty = true;
    document.getElementById('unsavedIndicator')?.classList.add('show');
    document.getElementById('discardBtn')?.style.setProperty('display','inline-flex');
}
function markSystemClean(){
    _systemDirty = false;
    document.getElementById('unsavedIndicator')?.classList.remove('show');
    const db=document.getElementById('discardBtn'); if(db) db.style.display='none';
}
function discardSystemChanges(){
    if(!_formSystemEl) return;
    _formSystemEl.reset(); // restores every plain input/select/checkbox to the values the page was rendered with
    populateAcadTerms(INITIAL_ACAD_TERM); // the Term dropdown is built dynamically, so reset() alone can't restore it
    onControlModeChange();
    onPublishChange();
    markSystemClean();
}
const _formSystemEl = document.getElementById('formSystem');
if(_formSystemEl){
    _formSystemEl.addEventListener('input', markSystemDirty);
    _formSystemEl.addEventListener('change', markSystemDirty);
}
// Only warns on an actual tab close/navigation while there is a real unsaved
// edit — never fires just because the settings modal happens to be open.
window.addEventListener('beforeunload', function(e){
    if(_systemDirty){ e.preventDefault(); e.returnValue = ''; }
});

/* ── DASHBOARD DATA: counts + bell + System Logs, all from dashboard_counts.php ──
   System Logs is now rendered as a single flat table (Date & Time / Action /
   User / Details), combining both feed types the backend already returns in
   `feed_full`:
     • type === 'audit'       → machine-generated events (schedules firing,
       syncs, reminders, etc.) — shown with "System" as the user unless the
       backend supplies an explicit actor.
     • type === 'role_change' → human-generated events (something a real
       admin/registrar/dean did) — shown under that person's name.
   Nothing here is hardcoded — every row rendered comes from that feed; the
   helpers below only add presentation: a severity color for the action dot
   (using an optional `severity` field from the backend, or `color` if the
   backend already supplies one, with a sensible type-based fallback). */
let _notifOpen=false,_prevCounts={};
function escH(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

const SEVERITY_COLORS = { green:'#059669', yellow:'#D97706', red:'#DC2626', blue:'#3B82F6', info:'#3B82F6' };
function activityColor(a){
    if(a.color) return a.color;                                  // backend-supplied color wins
    if(a.severity && SEVERITY_COLORS[a.severity]) return SEVERITY_COLORS[a.severity];
    return a.type==='role_change' ? '#7C3AED' : '#3B82F6';        // fallback: violet for people, blue for automation
}

function toggleNotifDropdown(e){
    e.stopPropagation();_notifOpen=!_notifOpen;
    document.getElementById('notifDropdown').classList.toggle('show',_notifOpen);
}
function markAllRead(){
    fetch('dashboard_counts.php?mark_read=1',{credentials:'same-origin'}).then(r=>r.ok?r.json():null).then(d=>{
        document.getElementById('notifBadge').classList.remove('show');
        document.getElementById('notifBtn').classList.remove('has-unread');
        if(d)renderDashboard(d); // repaint immediately with the fresh unread count
    }).catch(()=>{});
}

function renderTrend(id,delta){
    const el=document.getElementById(id);if(!el)return;
    if(delta===undefined||delta===null||delta===0){
        el.className='pbi-stat-trend flat';
        el.innerHTML='<i class="fa-solid fa-minus"></i> No change';
        return;
    }
    const up=delta>0;
    el.className='pbi-stat-trend '+(up?'up':'flat');
    el.innerHTML=`<i class="fa-solid ${up?'fa-arrow-up':'fa-arrow-down'}"></i> ${up?'+':''}${delta} this period`;
}

function renderCounts(counts){
    if(!counts)return;
    const map=[
        ['cnt-total',counts.total],['cnt-evals',counts.evals],
        ['cnt-faculty',counts.faculty],['cnt-students',counts.students],
        ['cnt-teacher',counts.teacher],['cnt-staff',counts.staff],['cnt-student',counts.students]
    ];
    map.forEach(([id,val])=>{
        const el=document.getElementById(id);if(!el||val===undefined)return;
        if(_prevCounts[id]!==undefined&&_prevCounts[id]!==val){el.classList.remove('count-updated');void el.offsetWidth;el.classList.add('count-updated');el.addEventListener('animationend',()=>el.classList.remove('count-updated'),{once:true});}
        el.textContent=val;_prevCounts[id]=val;
    });
    if(counts.submission_pct!==undefined){
        const fill=document.getElementById('progress-fill'),pct=document.getElementById('progress-pct'),note=document.getElementById('progress-note');
        if(fill)fill.style.width=Math.min(100,counts.submission_pct)+'%';
        if(pct)pct.textContent=counts.submission_pct+'%';
        if(note&&counts.submitted!==undefined&&counts.students!==undefined)note.textContent=counts.submitted+' of '+counts.students+' students submitted';
    }
    if(counts.trend_total!==undefined)renderTrend('trend-total',counts.trend_total);
    if(counts.trend_faculty!==undefined)renderTrend('trend-faculty',counts.trend_faculty);
    if(counts.trend_students!==undefined)renderTrend('trend-students',counts.trend_students);
    if(counts.trend_evals!==undefined)renderTrend('trend-evals',counts.trend_evals);
}
function renderBell(feed,unreadCount){
    const badge=document.getElementById('notifBadge'),btn=document.getElementById('notifBtn'),list=document.getElementById('notifList');
    if(unreadCount>0){badge.textContent=unreadCount>99?'99+':unreadCount;badge.classList.add('show');btn.classList.add('has-unread');}
    else{badge.classList.remove('show');btn.classList.remove('has-unread');}
    if(!feed||feed.length===0){list.innerHTML='<div class="notif-empty"><i class="fa-regular fa-bell-slash"></i>No recent activity</div>';return;}
    list.innerHTML=feed.map(e=>`
        <div class="notif-item ${e.type==='role_change'?'unread':''}">
            <div class="notif-icon" style="color:${e.color};background:${e.color}22;"><i class="fa-solid ${e.icon}"></i></div>
            <div style="flex:1;min-width:0;"><div class="notif-text">${escH(e.text)}</div><div class="notif-meta">${escH(e.meta)} · ${e.time}</div></div>
        </div>`).join('');
}
function renderLogsTable(feedFull){
    const body=document.getElementById('logsTableBody');if(!body)return;
    feedFull=feedFull||[];
    if(feedFull.length===0){
        body.innerHTML='<tr><td colspan="4" class="logs-empty">No recent activity yet.</td></tr>';
        return;
    }
    body.innerHTML=feedFull.map(a=>{
        const color=activityColor(a);
        const user=a.actor ? escH(a.actor) : (a.type==='role_change' ? 'System' : 'System Automator');
        return `
        <tr>
            <td class="log-datetime">${escH(a.date||a.time||'—')}</td>
            <td><span class="log-action"><span class="log-action-dot" style="background:${color};"></span>${escH(a.text)}</span></td>
            <td class="log-user">${user}</td>
            <td class="log-details">${escH(a.meta||'')}</td>
        </tr>`;
    }).join('');
}
function renderDashboard(d){
    renderCounts(d.counts);
    renderBell(d.feed, d.unread_role_changes);
    renderLogsTable(d.feed_full);
}
function refreshDashboard(){
    fetch('dashboard_counts.php',{credentials:'same-origin'}).then(r=>r.ok?r.json():null).then(d=>{if(d)renderDashboard(d);}).catch(()=>{});
}

/* ── POLLING ── */
setInterval(refreshDashboard,10000);
refreshDashboard();

/* ── INIT ── */
window.onload=function(){
    const saved=localStorage.getItem('activePage')||'dashboard';
    const link=document.getElementById('link-'+saved)||document.getElementById('link-dashboard');
    showPage(saved,link);
    const accent=localStorage.getItem('pbi_accent');
    const compact=localStorage.getItem('pbi_compact');
    if(accent)applyAccent(accent);
    if(compact==='1'){document.getElementById('togCompact').checked=true;applyCompact(true);}
    <?php if($settings_msg):?>openSettings('system');<?php endif;?>
};
</script>
</body>
</html>
<?php if($mysqli->ping())$mysqli->close();?>
<?php
// Student Evaluation Tracker
session_start();
require_once 'db.php';
require_once '../shared/EvaluationContextService.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin','superadmin','executive_assistant'], true)) {
    header('Location: admin_login.php');
    exit;
}

if ($_SESSION['role'] === 'admin') {
    require_once 'permissions.php';
    if (!admin_can_edit($mysqli, 'reports_analytics')) {
        die("You don't have access to this feature. Ask a Super Admin to enable it.");
    }
}

if ($_SESSION['role'] === 'executive_assistant') {
    $pres = $mysqli->query("SELECT admin_can_edit FROM admin_permissions WHERE feature_key='reports_analytics' LIMIT 1");
    $prow = $pres ? $pres->fetch_assoc() : null;
    if (!$prow || !$prow['admin_can_edit']) {
        die("You don't have access to this feature yet. Ask the System Admin to enable it.");
    }
}

function levelVariants(string $level): array {
    $level = strtolower(trim($level));
    if (in_array($level, ['junior_high','senior_high','elementary'], true)) return ['basic education'];
    if ($level === 'college') return ['college','higher education','college / university','college/university'];
    return [$level];
}

function trackerHasAssignment(mysqli $db, int $uid): bool {
    $st=$db->prepare("SELECT 1 FROM (SELECT user_id FROM user_year_levels WHERE user_id=? UNION ALL SELECT user_id FROM teaching_assignments WHERE user_id=?) x LIMIT 1");
    $st->bind_param('ii',$uid,$uid); $st->execute(); $ok=(bool)$st->get_result()->fetch_row(); $st->close(); return $ok;
}

function trackerMatchesAssignment(mysqli $db, int $uid, array $variants, string $year): bool {
    if (!$year || !$variants) return false;
    $ph=implode(',',array_fill(0,count($variants),'?'));
    $st=$db->prepare("SELECT 1 FROM (SELECT ta.user_id FROM teaching_assignments ta WHERE ta.user_id=? AND LOWER(TRIM(ta.education_level)) IN ($ph) AND LOWER(TRIM(ta.year_level))=LOWER(TRIM(?)) UNION SELECT uyl.user_id FROM user_year_levels uyl WHERE uyl.user_id=? AND LOWER(TRIM(uyl.year_level))=LOWER(TRIM(?))) x");
    $types='i'.str_repeat('s',count($variants)).'s' . 'is';
    $args=array_merge([$uid],$variants,[$year,$uid,$year]);
    $st->bind_param($types,...$args); $st->execute(); $ok=(bool)$st->get_result()->fetch_row(); $st->close(); return $ok;
}

function requiredCount(mysqli $db,array $student):int{
    $contexts=[];
    $year=trim((string)($student['year_level']??''));
    $level=trim((string)($student['education_level']??''));
    $variants=levelVariants($level);

    $h=$db->query("SELECT id FROM users WHERE role IN ('principal','dean') AND is_active=1 AND account_status='approved'");
    if($h){while($r=$h->fetch_assoc())$contexts[(int)$r['id'].'|school_head']=true;$h->free();}

    $s=$db->query("SELECT id,role,secondary_role,designation FROM users WHERE role IN ('teacher','staff','faculty') AND is_active=1 AND (account_status='approved' OR source='admin_nologin')");
    if($s){
        while($u=$s->fetch_assoc()){
            $uid=(int)$u['id'];
            $ht=ec_has_teacher_function($u); $hs=ec_has_staff_function($u); $mr=ec_has_additional_role($u);
            $hasAssign=trackerHasAssignment($db,$uid);
            $match=($year!=='' && $level!=='' && $hasAssign) ? trackerMatchesAssignment($db,$uid,$variants,$year) : false;
            if($ht && $match) $contexts[$uid.'|teacher']=true;
            if($hs && (!$hasAssign || $match)) $contexts[$uid.'|staff']=true;
            if($mr) $contexts[$uid.'|multi_role']=true;
        }
        $s->free();
    }
    return count($contexts);
}

function statusFor(int $done, int $required): string {
    if ($required <= 0 || $done <= 0) return 'not_started';
    if ($done >= $required) return 'completed';
    return 'in_progress';
}

$ctxCol=$mysqli->query("SHOW COLUMNS FROM evaluation_tracker LIKE 'evaluation_context'");
if($ctxCol&&$ctxCol->num_rows===0){
    $mysqli->query("ALTER TABLE evaluation_tracker ADD COLUMN evaluation_context VARCHAR(30) NOT NULL DEFAULT 'teacher'");
    $mysqli->query("UPDATE evaluation_tracker et JOIN users u ON u.id=et.target_user_id SET et.evaluation_context=CASE WHEN u.role IN ('principal','dean') THEN 'school_head' WHEN u.role='staff' THEN 'staff' WHEN u.role='teacher' THEN 'teacher' ELSE et.evaluation_context END");
}
$period=$mysqli->query("SELECT id, period_label FROM evaluation_periods WHERE is_active=1 LIMIT 1")->fetch_assoc();
$periodId = $period ? (int)$period['id'] : 0;

$level = $_GET['level'] ?? 'all';
if (!in_array($level, ['all','junior_high','senior_high','college'], true)) $level = 'all';

$status = $_GET['status'] ?? 'all';
if (!in_array($status, ['all','not_started','in_progress','completed'], true)) $status = 'all';

$search = trim($_GET['search'] ?? '');

$levels = [
    'junior_high' => 'Junior High School',
    'senior_high' => 'Senior High School',
    'college' => 'College'
];

$where = "role='student' AND is_active=1";
if ($level !== 'all') {
    $where .= " AND education_level='" . $mysqli->real_escape_string($level) . "'";
}
if ($search !== '') {
    $where .= " AND full_name LIKE '%" . $mysqli->real_escape_string($search) . "%'";
}

$res = $mysqli->query("SELECT id, full_name, photo, education_level, year_level FROM users WHERE $where ORDER BY education_level, full_name");
$students = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
if ($res) $res->free();

$rows = [];
foreach ($students as $student) {
    $required = requiredCount($mysqli, $student);
    $done = 0;
    $last = null;

    if ($periodId) {
        $stmt = $mysqli->prepare("SELECT COUNT(DISTINCT target_user_id,evaluation_context) AS done, MAX(submitted_at) AS last_submission FROM evaluation_tracker WHERE evaluator_id=? AND period_id=? AND eval_type='student' AND status='submitted'");
        if ($stmt) {
            $stmt->bind_param('ii', $student['id'], $periodId);
            $stmt->execute();
            $stmt->bind_result($doneValue, $lastValue);
            if ($stmt->fetch()) {
                $done = (int)$doneValue;
                $last = $lastValue;
            }
            $stmt->close();
        }
    }

    $state = statusFor($done, $required);
    if ($status !== 'all' && $state !== $status) continue;

    $percentage = $required > 0 ? min(100, round(($done / $required) * 100)) : 0;
    $rows[] = [
        'id' => (int)$student['id'],
        'full_name' => $student['full_name'],
        'photo' => $student['photo'],
        'education_level' => $student['education_level'],
        'year_level' => $student['year_level'],
        'required' => $required,
        'done' => min($done, $required),
        'state' => $state,
        'pct' => $percentage,
        'last' => $last
    ];
}

$summary = [
    'junior_high' => ['total'=>0,'completed'=>0,'pending'=>0],
    'senior_high' => ['total'=>0,'completed'=>0,'pending'=>0],
    'college' => ['total'=>0,'completed'=>0,'pending'=>0]
];

$summaryRes = $mysqli->query("SELECT id, education_level, year_level FROM users WHERE role='student' AND is_active=1");
if ($summaryRes) {
    while ($student = $summaryRes->fetch_assoc()) {
        $lvl = $student['education_level'];
        if (!isset($summary[$lvl])) continue;
        $summary[$lvl]['total']++;

        $required = requiredCount($mysqli, $student);
        $done = 0;
        if ($periodId) {
            $stmt = $mysqli->prepare("SELECT COUNT(DISTINCT target_user_id) FROM evaluation_tracker WHERE evaluator_id=? AND period_id=? AND eval_type='student' AND status='submitted'");
            if ($stmt) {
                $stmt->bind_param('ii', $student['id'], $periodId);
                $stmt->execute();
                $stmt->bind_result($doneValue);
                if ($stmt->fetch()) $done = (int)$doneValue;
                $stmt->close();
            }
        }

        if (statusFor($done, $required) === 'completed') $summary[$lvl]['completed']++;
        else $summary[$lvl]['pending']++;
    }
    $summaryRes->free();
}
?>

<style>
.et-wrap{width:100%;box-sizing:border-box;color:#f5f8ff;font-family:Arial,Helvetica,sans-serif;padding:18px 22px 30px;background:#193b63;border-radius:0;min-height:100%;}
.et-head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:18px}.et-title{display:flex;align-items:center;gap:10px;font-size:18px;font-weight:700}.et-title-icon{width:34px;height:34px;border:1px solid #3478d1;border-radius:8px;display:grid;place-items:center;color:#3d91ff}.et-sub{font-size:12px;color:#aebfd6;margin-top:6px}.et-updated{font-size:12px;color:#c2d0e2;white-space:nowrap;padding-top:8px}.et-updated span{color:#4e9cff;margin-right:5px}.et-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px}.et-card{background:#21466f;border:1px solid #315b87;border-radius:12px;padding:18px 20px;box-sizing:border-box}.et-card-top{display:flex;justify-content:space-between;align-items:center}.et-card-label{font-size:11px;text-transform:uppercase;color:#b7c6d8;font-weight:700;letter-spacing:.5px}.et-card-number{font-size:30px;font-weight:700;margin-top:8px}.et-card-icon{width:36px;height:36px;border:1px solid #3175cc;border-radius:8px;display:grid;place-items:center;color:#4c98ff}.et-card-line{height:1px;background:#315b87;margin:15px 0 11px}.et-card-foot{font-size:12px;color:#c0cee0}.et-card-foot b{font-weight:400}.et-card-foot .done{color:#8dc0ff}.et-card-foot .sep{margin:0 8px;color:#667f9c}.et-controls{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px}.et-levels{display:flex;gap:8px;flex-wrap:wrap}.et-btn{border:1px solid #315b87;background:#21466f;color:#cbd8e8;border-radius:8px;padding:10px 15px;font-size:12px;text-decoration:none;display:inline-block}.et-btn.active{background:#3283ef;border-color:#3283ef;color:white}.et-right{display:flex;gap:8px;align-items:center}.et-select,.et-search{border:1px solid #315b87;background:#21466f;color:#dce6f2;border-radius:8px;padding:10px 12px;font-size:12px;outline:none}.et-search{width:230px}.et-table{border:1px solid #315b87;background:#21466f;border-radius:12px;overflow:hidden}.et-table table{width:100%;border-collapse:collapse;table-layout:fixed}.et-table th{height:48px;text-align:left;padding:0 20px;font-size:10px;text-transform:uppercase;letter-spacing:.6px;color:#9fb2c9;background:#20456e;border-bottom:1px solid #315b87}.et-table td{padding:15px 20px;border-bottom:1px solid #315b87;font-size:13px;color:#edf3fb;vertical-align:middle}.et-table tr:last-child td{border-bottom:0}.et-student{display:flex;align-items:center;gap:12px}.et-avatar{width:38px;height:38px;border-radius:50%;background:#183b62;border:1px solid #315b87;display:grid;place-items:center;overflow:hidden;flex:none}.et-avatar img{width:100%;height:100%;object-fit:cover}.et-avatar svg{width:17px;height:17px;fill:#9eb5ce}.et-name{font-weight:700}.et-mini{font-size:10px;color:#8fa7c0;margin-top:4px}.et-level-pill{display:inline-block;padding:5px 11px;border-radius:14px;font-size:11px;font-weight:600;border:1px solid}.lvl-col{color:#31d6c2;border-color:#198f8c;background:rgba(25,143,140,.13)}.lvl-jhs{color:#4b91ff;border-color:#2869c4;background:rgba(40,105,196,.13)}.lvl-shs{color:#ff3fa5;border-color:#a62975;background:rgba(166,41,117,.12)}.et-status{display:inline-block;padding:7px 12px;border-radius:15px;font-size:10px;font-weight:700}.st-completed{color:#52e49a;background:#123d2b}.st-progress{color:#f4ad21;background:#4b3509}.st-start{color:#b4c2d3;background:#18314d}.et-progress{display:flex;align-items:center;gap:10px}.et-progress-text{min-width:28px;font-size:11px;color:#dce6f2}.et-bar{height:6px;background:#142b45;border-radius:8px;overflow:hidden;flex:1;min-width:90px}.et-fill{height:100%;border-radius:8px;background:#ffab1f}.et-arrow{font-size:22px;color:#a5b9d0;text-align:right}.et-empty{padding:40px;text-align:center;color:#9fb2c9}.et-note{font-size:10px;color:#8098b3;margin-top:8px}@media(max-width:900px){.et-cards{grid-template-columns:1fr}.et-controls{align-items:stretch;flex-direction:column}.et-right{width:100%}.et-search{width:100%}.et-table{overflow-x:auto}.et-table table{min-width:900px}}
</style>

<div class="et-wrap">
    <div class="et-head">
        <div>
            <div class="et-title"><span class="et-title-icon">🎓</span>Students</div>
            <div class="et-sub">Progress evaluating their required faculty/staff this period.</div>
        </div>
        <div class="et-updated"><span>⟳</span> Last updated: <?=htmlspecialchars(date('M j, Y g:i A'))?></div>
    </div>

    <div class="et-cards">
        <?php foreach (['junior_high'=>'JUNIOR HIGH','senior_high'=>'SENIOR HIGH','college'=>'COLLEGE'] as $key=>$label): ?>
            <div class="et-card">
                <div class="et-card-top">
                    <div>
                        <div class="et-card-label"><?=$label?></div>
                        <div class="et-card-number"><?=$summary[$key]['total']?></div>
                    </div>
                    <div class="et-card-icon">▣</div>
                </div>
                <div class="et-card-line"></div>
                <div class="et-card-foot"><span class="done"><?=$summary[$key]['completed']?> completed</span><span class="sep">|</span><span><?=$summary[$key]['pending']?> pending</span></div>
            </div>
        <?php endforeach; ?>
    </div>

    <form class="et-controls" method="get">
        <div class="et-levels">
            <a class="et-btn <?=$level==='all'?'active':''?>" href="?level=all&status=<?=urlencode($status)?>&search=<?=urlencode($search)?>">All Levels</a>
            <a class="et-btn <?=$level==='junior_high'?'active':''?>" href="?level=junior_high&status=<?=urlencode($status)?>&search=<?=urlencode($search)?>">Junior High School</a>
            <a class="et-btn <?=$level==='senior_high'?'active':''?>" href="?level=senior_high&status=<?=urlencode($status)?>&search=<?=urlencode($search)?>">Senior High School</a>
            <a class="et-btn <?=$level==='college'?'active':''?>" href="?level=college&status=<?=urlencode($status)?>&search=<?=urlencode($search)?>">College</a>
        </div>
        <div class="et-right">
            <select class="et-select" name="status" onchange="this.form.submit()">
                <option value="all" <?=$status==='all'?'selected':''?>>All Status</option>
                <option value="not_started" <?=$status==='not_started'?'selected':''?>>Not Started</option>
                <option value="in_progress" <?=$status==='in_progress'?'selected':''?>>In Progress</option>
                <option value="completed" <?=$status==='completed'?'selected':''?>>Completed</option>
            </select>
            <input class="et-search" type="text" name="search" value="<?=htmlspecialchars($search)?>" placeholder="🔍  Search student by name...">
            <input type="hidden" name="level" value="<?=htmlspecialchars($level)?>">
        </div>
    </form>

    <div class="et-table">
        <?php if (!$rows): ?>
            <div class="et-empty">No students match the selected filters.</div>
        <?php else: ?>
            <table>
                <thead><tr><th style="width:22%">STUDENT</th><th style="width:20%">LEVEL</th><th style="width:10%">REQUIRED</th><th style="width:11%">COMPLETED</th><th style="width:14%">STATUS</th><th style="width:18%">PROGRESS</th><th style="width:5%"></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $photo = trim((string)($r['photo'] ?? ''));
                    $stateClass = $r['state']==='completed' ? 'st-completed' : ($r['state']==='in_progress' ? 'st-progress' : 'st-start');
                    $stateLabel = $r['state']==='completed' ? 'Completed' : ($r['state']==='in_progress' ? 'In Progress' : 'Not Started');
                    $levelClass = $r['education_level']==='college' ? 'lvl-col' : ($r['education_level']==='senior_high' ? 'lvl-shs' : 'lvl-jhs');
                ?>
                    <tr>
                        <td>
                            <div class="et-student">
                                <div class="et-avatar">
                                    <?php if ($photo): ?><img src="<?=htmlspecialchars($photo)?>" alt=""><?php else: ?><svg viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-4.42 0-8 2.24-8 5v1h16v-1c0-2.76-3.58-5-8-5Z"/></svg><?php endif; ?>
                                </div>
                                <div><div class="et-name"><?=htmlspecialchars($r['full_name'])?></div><div class="et-mini"><?=htmlspecialchars($levels[$r['education_level']] ?? $r['education_level'])?></div></div>
                            </div>
                        </td>
                        <td><span class="et-level-pill <?=$levelClass?>"><?=htmlspecialchars($levels[$r['education_level']] ?? $r['education_level'])?></span></td>
                        <td><?=$r['required']?></td>
                        <td><?=$r['done']?> / <?=$r['required']?></td>
                        <td><span class="et-status <?=$stateClass?>"><?=$stateLabel?></span></td>
                        <td>
                            <div class="et-progress"><span class="et-progress-text"><?=$r['pct']?>%</span><div class="et-bar"><div class="et-fill" style="width:<?=$r['pct']?>%"></div></div></div>
                            <?php if ($r['last']): ?><div class="et-note">Last: <?=htmlspecialchars(date('M j, Y g:i A', strtotime($r['last'])))?></div><?php endif; ?>
                        </td>
                        <td class="et-arrow">›</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
<?php $mysqli->close(); ?>

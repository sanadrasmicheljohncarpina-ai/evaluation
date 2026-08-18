<?php
require_once 'principal_common.php';

// ── SCORE HELPERS (mirrors admin_analytics.php's scoreColor/scoreLabel) ──
function scoreColor($s) {
    if ($s === null) return '#6b7280';
    if ($s >= 4.5) return '#4ade80';
    if ($s >= 3.5) return '#86efac';
    if ($s >= 2.5) return '#facc15';
    if ($s >= 1.5) return '#fb923c';
    return '#f87171';
}
function scoreLabel($s) {
    if ($s === null) return '—';
    if ($s >= 4.5) return 'Excellent';
    if ($s >= 3.5) return 'Good';
    if ($s >= 2.5) return 'Satisfactory';
    if ($s >= 1.5) return 'Needs Improvement';
    return 'Poor';
}

// ── RENDER HELPERS ─────────────────────────────────────────────
function render_person_list(array $list, string $linkBase): void {
    if (empty($list)) {
        echo '<p class="empty-note">No one in scope yet.</p>';
        return;
    }
    foreach ($list as $p) {
        $pct   = $p['avg'] !== null ? ($p['avg'] / 5) * 100 : 0;
        $color = scoreColor($p['avg']);
        ?>
        <a class="person-row" href="<?= htmlspecialchars($linkBase) ?>?id=<?= (int)$p['id'] ?>">
            <img class="person-photo" src="<?= htmlspecialchars($p['photo']) ?>" alt="">
            <div class="person-info">
                <div class="person-name"><?= htmlspecialchars($p['name']) ?></div>
                <div class="person-meta"><?= (int)$p['completed'] ?> evaluation<?= $p['completed'] != 1 ? 's' : '' ?> received</div>
            </div>
            <div class="person-stats">
                <div class="pstat">
                    <div class="pstat-val" style="color:<?= $color ?>"><?= $p['avg'] !== null ? $p['avg'] : '—' ?></div>
                    <div class="pstat-lbl">Avg Rating</div>
                </div>
                <div class="score-bar-wrap">
                    <div class="score-bar-bg"><div class="score-bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div></div>
                </div>
            </div>
            <i class="fa-solid fa-chevron-right arrow-icon"></i>
        </a>
        <?php
    }
}

function render_standing_list(array $list): void {
    if (empty($list)) {
        echo '<p class="empty-note">No rating data yet.</p>';
        return;
    }
    foreach ($list as $i => $p) {
        $linkBase = $p['role'] === 'teacher' ? 'principal_teachers.php' : 'principal_staff.php';
        ?>
        <a class="standing-item" href="<?= $linkBase ?>?id=<?= (int)$p['id'] ?>">
            <span class="standing-rank"><?= $i + 1 ?></span>
            <img class="standing-photo" src="<?= htmlspecialchars($p['photo']) ?>" alt="">
            <span class="standing-info">
                <span class="standing-name"><?= htmlspecialchars($p['name']) ?></span>
                <span class="standing-role"><?= ucfirst($p['role']) ?></span>
            </span>
            <span class="standing-score" style="color:<?= scoreColor($p['avg']) ?>"><?= $p['avg'] ?></span>
        </a>
        <?php
    }
}

// ── REPORT TYPES ────────────────────────────────────────────────
$validTypes = [
    'school_summary'        => ['fa-school',           'School Evaluation Summary',   'A scope-wide snapshot of teacher, staff, and student evaluation activity.'],
    'teacher_performance'   => ['fa-chalkboard-user',   'Teacher Performance Summary',  'Every teacher in your scope, ranked by average student rating.'],
    'staff_performance'     => ['fa-users',             'Staff Performance Report',     'Every staff member in your scope, ranked by average student rating.'],
    'grade_comparison'      => ['fa-scale-balanced',    'Grade-Level Comparison',       'How completion, participation, and ratings compare across grade levels.'],
    'student_participation' => ['fa-user-graduate',     'Student Participation Report', 'Which grade levels have submitted their evaluations so far.'],
    'completion'            => ['fa-clipboard-check',   'Evaluation Completion Report', 'Teachers and staff who have not yet received any evaluation this period.'],
];
$type = $_GET['type'] ?? 'school_summary';
if (!isset($validTypes[$type])) { $type = 'school_summary'; }
[$typeIcon, $typeLabel, $typeDesc] = $validTypes[$type];

// ── shared totals used by more than one report ───────────────
$teacherCount = (int)(safe_scalar($mysqli, "
    SELECT COUNT(*) c FROM users WHERE role='teacher' AND is_active=1 AND account_status='approved' AND academic_level IN ($scopeAcademicIn)
") ?? 0);
$staffCount = (int)(safe_scalar($mysqli, "
    SELECT COUNT(*) c FROM users WHERE role='staff' AND is_active=1 AND account_status='approved' AND academic_level IN ($scopeAcademicIn)
") ?? 0);
$studentCount = (int)(safe_scalar($mysqli, "
    SELECT COUNT(*) c FROM users WHERE role='student' AND is_active=1 AND account_status='approved' AND grade_level IN ($scopeGradesIn)
") ?? 0);

$studentsSubmitted = $teachersEvaluated = $staffEvaluated = 0;
if ($period) {
    $studentsSubmitted = (int)(safe_scalar($mysqli, "
        SELECT COUNT(DISTINCT et.evaluator_id) c FROM evaluation_tracker et
        INNER JOIN users u ON u.id = et.evaluator_id
        WHERE et.eval_type='student' AND et.period_id=? AND u.role='student' AND u.grade_level IN ($scopeGradesIn)
    ", "i", [$period_id_int]) ?? 0);

    $teachersEvaluated = (int)(safe_scalar($mysqli, "
        SELECT COUNT(DISTINCT et.target_user_id) c FROM evaluation_tracker et
        INNER JOIN users u ON u.id = et.target_user_id
        WHERE et.eval_type='student' AND et.period_id=? AND u.role='teacher' AND u.academic_level IN ($scopeAcademicIn)
    ", "i", [$period_id_int]) ?? 0);

    $staffEvaluated = (int)(safe_scalar($mysqli, "
        SELECT COUNT(DISTINCT et.target_user_id) c FROM evaluation_tracker et
        INNER JOIN users u ON u.id = et.target_user_id
        WHERE et.eval_type='student' AND et.period_id=? AND u.role='staff' AND u.academic_level IN ($scopeAcademicIn)
    ", "i", [$period_id_int]) ?? 0);
}
$teacherCompletion     = $teacherCount > 0 ? round($teachersEvaluated / $teacherCount * 100) : 0;
$staffCompletion       = $staffCount > 0 ? round($staffEvaluated / $staffCount * 100) : 0;
$studentParticipation  = $studentCount > 0 ? round($studentsSubmitted / $studentCount * 100) : 0;

// ── report-specific data ──────────────────────────────────────
$teacherPerf = $staffPerf = $gradeRows = $pendingTeachers = $pendingStaff = [];
$topPerformers = $lowPerformers = [];

// school_summary now also builds these two lists so it can show standings,
// same as admin_analytics.php's top4/low4 panels.
if (in_array($type, ['teacher_performance', 'completion', 'school_summary'], true)) {
    $rows = safe_rows($mysqli, "
        SELECT id, full_name, photo FROM users
        WHERE role='teacher' AND is_active=1 AND account_status='approved' AND academic_level IN ($scopeAcademicIn)
        ORDER BY full_name
    ");
    foreach ($rows as $r) {
        $tid = (int)$r['id'];
        $completed = $period ? (int)(safe_scalar($mysqli, "
            SELECT COUNT(DISTINCT evaluator_id) c FROM evaluation_tracker WHERE eval_type='student' AND period_id=? AND target_user_id=?
        ", "ii", [$period_id_int, $tid]) ?? 0) : 0;
        $avg = safe_scalar($mysqli, "
            SELECT AVG(qa.answer_score) v FROM questionnaire_answers qa
            INNER JOIN evaluation_tracker et ON et.id = qa.tracker_id
            WHERE et.eval_type='student' AND et.target_user_id=?" . ($period ? " AND et.period_id=?" : ""),
            $period ? "ii" : "i", $period ? [$tid, $period_id_int] : [$tid]
        );
        $entry = [
            'id'        => $tid,
            'name'      => $r['full_name'],
            'photo'     => !empty($r['photo']) ? '../image/' . $r['photo'] : '../image/pbi_logo',
            'completed' => $completed,
            'avg'       => $avg !== null ? round((float)$avg, 2) : null,
        ];
        $teacherPerf[] = $entry;
        if ($completed === 0) $pendingTeachers[] = $entry;
    }
    usort($teacherPerf, fn($a, $b) => ($b['avg'] ?? -1) <=> ($a['avg'] ?? -1));
}

if (in_array($type, ['staff_performance', 'completion', 'school_summary'], true)) {
    $rows = safe_rows($mysqli, "
        SELECT id, full_name, photo FROM users
        WHERE role='staff' AND is_active=1 AND account_status='approved' AND academic_level IN ($scopeAcademicIn)
        ORDER BY full_name
    ");
    foreach ($rows as $r) {
        $sid = (int)$r['id'];
        $completed = $period ? (int)(safe_scalar($mysqli, "
            SELECT COUNT(DISTINCT evaluator_id) c FROM evaluation_tracker WHERE eval_type='student' AND period_id=? AND target_user_id=?
        ", "ii", [$period_id_int, $sid]) ?? 0) : 0;
        $avg = safe_scalar($mysqli, "
            SELECT AVG(qa.answer_score) v FROM questionnaire_answers qa
            INNER JOIN evaluation_tracker et ON et.id = qa.tracker_id
            WHERE et.eval_type='student' AND et.target_user_id=?" . ($period ? " AND et.period_id=?" : ""),
            $period ? "ii" : "i", $period ? [$sid, $period_id_int] : [$sid]
        );
        $entry = [
            'id'        => $sid,
            'name'      => $r['full_name'],
            'photo'     => !empty($r['photo']) ? '../image/' . $r['photo'] : '../image/pbi_logo',
            'completed' => $completed,
            'avg'       => $avg !== null ? round((float)$avg, 2) : null,
        ];
        $staffPerf[] = $entry;
        if ($completed === 0) $pendingStaff[] = $entry;
    }
    usort($staffPerf, fn($a, $b) => ($b['avg'] ?? -1) <=> ($a['avg'] ?? -1));
}

if ($type === 'school_summary') {
    $combined = array_merge(
        array_map(fn($t) => $t + ['role' => 'teacher'], $teacherPerf),
        array_map(fn($s) => $s + ['role' => 'staff'], $staffPerf)
    );
    $ranked = array_values(array_filter($combined, fn($p) => $p['avg'] !== null));
    usort($ranked, fn($a, $b) => $b['avg'] <=> $a['avg']);
    $topPerformers = array_slice($ranked, 0, 4);
    $lowPerformers = array_slice(array_reverse($ranked), 0, 4);
}

if (in_array($type, ['grade_comparison', 'student_participation'], true)) {
    foreach ($scopeGrades as $grade) {
        $gradeStudents = (int)(safe_scalar($mysqli, "
            SELECT COUNT(*) c FROM users WHERE role='student' AND is_active=1 AND account_status='approved' AND grade_level=?
        ", "s", [$grade]) ?? 0);

        $gradeSubmitted = $period ? (int)(safe_scalar($mysqli, "
            SELECT COUNT(DISTINCT et.evaluator_id) c FROM evaluation_tracker et
            INNER JOIN users u ON u.id = et.evaluator_id
            WHERE et.eval_type='student' AND et.period_id=? AND u.role='student' AND u.grade_level=?
        ", "is", [$period_id_int, $grade]) ?? 0) : 0;

        $gradeTeachers = (int)(safe_scalar($mysqli, "
            SELECT COUNT(DISTINCT uyl.user_id) c FROM user_year_levels uyl
            INNER JOIN users u ON u.id = uyl.user_id
            WHERE uyl.year_level=? AND u.role='teacher' AND u.is_active=1 AND u.account_status='approved'
        ", "s", [$grade]) ?? 0);

        $gradeTeachersEvaluated = 0;
        if ($period && $gradeTeachers > 0) {
            $gradeTeachersEvaluated = (int)(safe_scalar($mysqli, "
                SELECT COUNT(DISTINCT et.target_user_id) c FROM evaluation_tracker et
                INNER JOIN user_year_levels uyl ON uyl.user_id = et.target_user_id
                WHERE et.eval_type='student' AND et.period_id=? AND uyl.year_level=?
            ", "is", [$period_id_int, $grade]) ?? 0);
        }

        $gradeAvg = safe_scalar($mysqli, "
            SELECT AVG(qa.answer_score) v FROM questionnaire_answers qa
            INNER JOIN evaluation_tracker et ON et.id = qa.tracker_id
            INNER JOIN users u ON u.id = et.evaluator_id
            WHERE et.eval_type='student' AND et.period_id=? AND u.grade_level=?
        ", "is", [$period_id_int, $grade]);

        $gradeRows[] = [
            'grade'         => $grade,
            'students'      => $gradeStudents,
            'submitted'     => $gradeSubmitted,
            'participation' => $gradeStudents > 0 ? round($gradeSubmitted / $gradeStudents * 100) : 0,
            'completion'    => $gradeTeachers > 0 ? round($gradeTeachersEvaluated / $gradeTeachers * 100) : 0,
            'avg'           => $gradeAvg !== null ? round((float)$gradeAvg, 2) : null,
        ];
    }
}

html_head_open('PBI — ' . $typeLabel);
render_principal_sidebar('reports', $me, $scopeLabel, $photo_src);
?>
<style>
/* ── report type switcher (mirrors admin_analytics.php's eval-switcher) ── */
.report-switcher{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;}
.report-switcher a{display:flex;align-items:center;gap:8px;padding:10px 18px;border-radius:10px;font-size:13px;font-weight:600;color:var(--muted);text-decoration:none;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);transition:all .2s;}
.report-switcher a:hover{color:var(--light);background:rgba(255,255,255,.06);}
.report-switcher a.active{background:rgba(217,154,43,.18);border-color:rgba(217,154,43,.4);color:var(--amber-h);}

/* ── report banner (mirrors admin_analytics.php's eval-banner) ── */
.report-banner{display:flex;align-items:center;gap:14px;padding:14px 18px;border-radius:10px;margin-bottom:20px;background:rgba(217,154,43,.06);border:1px solid rgba(217,154,43,.2);}
.report-banner-icon{width:42px;height:42px;border-radius:10px;background:rgba(217,154,43,.12);border:1px solid rgba(217,154,43,.25);display:flex;align-items:center;justify-content:center;color:var(--amber-h);font-size:18px;flex-shrink:0;}
.report-banner-title{font-size:14px;font-weight:700;color:#fff;}
.report-banner-desc{font-size:12px;color:var(--muted);margin-top:2px;}

/* ── standings panels (mirrors admin_analytics.php's top4/low4) ── */
.standings-row{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px;}
.standing-panel{background:rgba(255,255,255,.02);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:18px;}
.standing-title{font-size:14px;font-weight:700;margin-bottom:12px;display:flex;align-items:center;gap:8px;}
.standing-title.top{color:#4ade80;}
.standing-title.low{color:#f87171;}
.standing-item{display:flex;align-items:center;gap:10px;padding:9px 4px;border-bottom:1px solid rgba(255,255,255,.05);text-decoration:none;color:inherit;}
.standing-item:last-child{border-bottom:none;}
.standing-rank{font-size:12px;font-weight:700;color:var(--muted);width:18px;text-align:center;flex-shrink:0;}
.standing-photo{width:34px;height:34px;border-radius:50%;object-fit:cover;border:1.5px solid rgba(255,255,255,.12);flex-shrink:0;}
.standing-info{flex:1;min-width:0;display:flex;flex-direction:column;}
.standing-name{font-size:13px;font-weight:600;color:#fff;}
.standing-role{font-size:11px;color:var(--muted);}
.standing-score{font-size:14px;font-weight:700;flex-shrink:0;}

/* ── clickable person list (mirrors admin_analytics.php's people-list) ── */
.people-list{display:flex;flex-direction:column;gap:10px;}
.person-row{display:flex;align-items:center;gap:14px;padding:14px 16px;background:rgba(255,255,255,.02);border:1px solid rgba(255,255,255,.08);border-radius:12px;text-decoration:none;color:inherit;transition:all .2s;}
.person-row:hover{border-color:rgba(217,154,43,.4);background:rgba(217,154,43,.05);}
.person-photo{width:44px;height:44px;border-radius:50%;object-fit:cover;border:1.5px solid rgba(255,255,255,.12);flex-shrink:0;}
.person-info{flex:1;min-width:0;}
.person-name{font-size:14px;font-weight:700;color:#fff;}
.person-meta{font-size:12px;color:var(--muted);margin-top:2px;}
.person-stats{display:flex;align-items:center;gap:14px;flex-shrink:0;}
.pstat{text-align:center;min-width:56px;}
.pstat-val{font-size:16px;font-weight:700;}
.pstat-lbl{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-top:2px;}
.score-bar-wrap{width:100px;}
.score-bar-bg{height:6px;background:rgba(255,255,255,.08);border-radius:3px;overflow:hidden;}
.score-bar-fill{height:100%;border-radius:3px;}
.arrow-icon{color:var(--muted);font-size:13px;flex-shrink:0;}

/* pending-list avatars inside the existing .mini-list */
.mini-list li a{display:flex;align-items:center;width:100%;text-decoration:none;color:inherit;}
.mini-list li img{width:26px;height:26px;border-radius:50%;object-fit:cover;margin-right:8px;flex-shrink:0;}
.mini-list li .name{flex:1;}

@media(max-width:900px){.standings-row{grid-template-columns:1fr;}}

/* ── print: clean report, no nav/buttons ── */
@media print{
  .sidebar, .report-switcher, .report-banner, .arrow-icon, .section:last-child{display:none!important;}
  body{background:#fff!important;color:#000!important;}
  .main{padding:0!important;}
  .section{background:#fff!important;border:1px solid #ccc!important;box-shadow:none!important;}
  .section, .section *{color:#000!important;}
  .person-row, .standing-item{border:1px solid #ddd!important;}
}
</style>

<main class="main">
    <div class="page-header">
        <div>
            <div class="page-title">Reports &amp; Analytics</div>
            <div class="page-sub"><?= htmlspecialchars($scopeLabel) ?></div>
        </div>
        <?php render_period_badge($settings); ?>
    </div>

    <!-- REPORT TYPE SWITCHER -->
    <div class="report-switcher">
        <?php foreach ($validTypes as $key => [$icon, $label, $desc]): ?>
            <a class="<?= $key === $type ? 'active' : '' ?>" href="principal_reports.php?type=<?= urlencode($key) ?>">
                <i class="fa-solid <?= htmlspecialchars($icon) ?>"></i> <?= htmlspecialchars($label) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- REPORT BANNER -->
    <div class="report-banner">
        <div class="report-banner-icon"><i class="fa-solid <?= htmlspecialchars($typeIcon) ?>"></i></div>
        <div>
            <div class="report-banner-title"><?= htmlspecialchars($typeLabel) ?></div>
            <div class="report-banner-desc"><?= htmlspecialchars($typeDesc) ?></div>
        </div>
    </div>

    <div class="section">
        <?php if ($type === 'school_summary'): ?>
            <div class="tracker-grid">
                <div class="tracker-item"><div class="big"><?= $teacherCount ?></div><div class="lbl">Teachers</div></div>
                <div class="tracker-item"><div class="big"><?= $staffCount ?></div><div class="lbl">Staff</div></div>
                <div class="tracker-item"><div class="big"><?= $studentCount ?></div><div class="lbl">Students</div></div>
                <div class="tracker-item"><div class="big"><?= $teacherCompletion ?>%</div><div class="lbl">Teacher Completion</div></div>
                <div class="tracker-item"><div class="big"><?= $staffCompletion ?>%</div><div class="lbl">Staff Completion</div></div>
                <div class="tracker-item"><div class="big"><?= $studentParticipation ?>%</div><div class="lbl">Student Participation</div></div>
            </div>
            <?php if (!$period): ?>
                <p class="empty-note" style="margin-top:16px;">There is no active evaluation period, so completion figures reflect the most recent totals only.</p>
            <?php endif; ?>

            <div class="standings-row">
                <div class="standing-panel">
                    <div class="standing-title top"><i class="fa-solid fa-trophy"></i> Top Performers</div>
                    <?php render_standing_list($topPerformers); ?>
                </div>
                <div class="standing-panel">
                    <div class="standing-title low"><i class="fa-solid fa-arrow-trend-up"></i> Areas for Improvement</div>
                    <?php render_standing_list($lowPerformers); ?>
                </div>
            </div>

        <?php elseif ($type === 'teacher_performance'): ?>
            <div class="people-list">
                <?php render_person_list($teacherPerf, 'principal_teachers.php'); ?>
            </div>

        <?php elseif ($type === 'staff_performance'): ?>
            <div class="people-list">
                <?php render_person_list($staffPerf, 'principal_staff.php'); ?>
            </div>

        <?php elseif ($type === 'grade_comparison'): ?>
            <table class="data">
                <thead><tr><th>Grade</th><th>Teacher Completion</th><th>Participation</th><th>Avg Performance</th></tr></thead>
                <tbody>
                <?php foreach ($gradeRows as $g): ?>
                    <tr>
                        <td>Grade <?= htmlspecialchars($g['grade']) ?></td>
                        <td style="min-width:140px;">
                            <div class="bar-wrap"><div class="bar-fill" style="width:<?= $g['completion'] ?>%"></div></div>
                            <span style="font-size:11px;color:var(--muted);"><?= $g['completion'] ?>%</span>
                        </td>
                        <td><?= $g['participation'] ?>%</td>
                        <td><?= $g['avg'] !== null ? '<span style="color:' . scoreColor($g['avg']) . ';font-weight:700;">' . $g['avg'] . '</span>' : '<span class="empty-note">N/A</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

        <?php elseif ($type === 'student_participation'): ?>
            <table class="data">
                <thead><tr><th>Grade</th><th>Students</th><th>Submitted</th><th>Not Yet Submitted</th><th>Participation</th></tr></thead>
                <tbody>
                <?php foreach ($gradeRows as $g): ?>
                    <tr>
                        <td>Grade <?= htmlspecialchars($g['grade']) ?></td>
                        <td><?= $g['students'] ?></td>
                        <td><?= $g['submitted'] ?></td>
                        <td><?= max(0, $g['students'] - $g['submitted']) ?></td>
                        <td style="min-width:140px;">
                            <div class="bar-wrap"><div class="bar-fill" style="width:<?= $g['participation'] ?>%"></div></div>
                            <span style="font-size:11px;color:var(--muted);"><?= $g['participation'] ?>%</span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

        <?php elseif ($type === 'completion'): ?>
            <div class="two-col">
                <div>
                    <h3 style="font-size:13px;color:var(--muted);margin-bottom:10px;text-transform:uppercase;letter-spacing:.4px;">Teachers Not Yet Evaluated (<?= count($pendingTeachers) ?>)</h3>
                    <ul class="mini-list">
                        <?php if (empty($pendingTeachers)): ?>
                            <li class="empty-note">Every teacher has at least one evaluation.</li>
                        <?php else: foreach ($pendingTeachers as $t): ?>
                            <li><a href="principal_teachers.php?id=<?= $t['id'] ?>">
                                <img src="<?= htmlspecialchars($t['photo']) ?>" alt="">
                                <span class="name"><?= htmlspecialchars($t['name']) ?></span>
                                <span class="pill warn">Pending</span>
                            </a></li>
                        <?php endforeach; endif; ?>
                    </ul>
                </div>
                <div>
                    <h3 style="font-size:13px;color:var(--muted);margin-bottom:10px;text-transform:uppercase;letter-spacing:.4px;">Staff Not Yet Evaluated (<?= count($pendingStaff) ?>)</h3>
                    <ul class="mini-list">
                        <?php if (empty($pendingStaff)): ?>
                            <li class="empty-note">Every staff member has at least one evaluation.</li>
                        <?php else: foreach ($pendingStaff as $s): ?>
                            <li><a href="principal_staff.php?id=<?= $s['id'] ?>">
                                <img src="<?= htmlspecialchars($s['photo']) ?>" alt="">
                                <span class="name"><?= htmlspecialchars($s['name']) ?></span>
                                <span class="pill warn">Pending</span>
                            </a></li>
                        <?php endforeach; endif; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="section">
        <button onclick="window.print()"><i class="fa-solid fa-print"></i> Print / Save as PDF</button>
    </div>
</main>
</body></html>
<?php $mysqli->close(); ?>
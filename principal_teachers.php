<?php
require_once 'principal_common.php';

$viewId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ═══════════════════════════════════════════════════════════
// DETAIL VIEW — a single teacher
// ═══════════════════════════════════════════════════════════
if ($viewId > 0) {
    $stmt = $mysqli->prepare("
        SELECT id, full_name, username, email, designation, photo, academic_level
        FROM users
        WHERE id=? AND role='teacher' AND academic_level IN ($scopeAcademicIn)
        LIMIT 1
    ");
    $stmt->bind_param("i", $viewId);
    $stmt->execute();
    $teacher = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$teacher) {
        html_head_open('PBI — Teacher Not Found');
        render_principal_sidebar('teachers', $me, $scopeLabel, $photo_src);
        ?>
        <main class="main">
            <a class="back-link" href="principal_teachers.php"><i class="fa-solid fa-arrow-left"></i> Back to Teachers</a>
            <div class="section"><p class="empty-note">That teacher wasn't found, or isn't within your scope.</p></div>
        </main>
        </body></html>
        <?php
        $mysqli->close();
        exit;
    }

    $tPhoto = !empty($teacher['photo']) ? '../image/' . $teacher['photo'] : '../image/pbi_logo';

    $assignedGrades = safe_rows($mysqli, "
        SELECT year_level FROM user_year_levels WHERE user_id=? ORDER BY year_level
    ", "i", [$viewId]);
    $assignedGradeList = array_column($assignedGrades, 'year_level');

    $completed = $period ? (int)(safe_scalar($mysqli, "
        SELECT COUNT(DISTINCT evaluator_id) c FROM evaluation_tracker
        WHERE eval_type='student' AND period_id=? AND target_user_id=?
    ", "ii", [$period_id_int, $viewId]) ?? 0) : 0;

    // Pool of possible evaluators = students in the teacher's assigned grade(s),
    // falling back to the principal's whole scope if no grades are assigned yet.
    $poolGrades = !empty($assignedGradeList) ? $assignedGradeList : $scopeGrades;
    $poolGradesIn = esc_list($mysqli, $poolGrades);
    $possibleEvaluators = (int)(safe_scalar($mysqli, "
        SELECT COUNT(*) c FROM users
        WHERE role='student' AND is_active=1 AND account_status='approved' AND grade_level IN ($poolGradesIn)
    ") ?? 0);
    $completionPct = $possibleEvaluators > 0 ? round($completed / $possibleEvaluators * 100) : 0;

    $avgRating = $period ? safe_scalar($mysqli, "
        SELECT AVG(qa.answer_score) v
        FROM questionnaire_answers qa
        INNER JOIN evaluation_tracker et ON et.id = qa.tracker_id
        WHERE et.eval_type='student' AND et.target_user_id=? AND et.period_id=?
    ", "ii", [$viewId, $period_id_int]) : safe_scalar($mysqli, "
        SELECT AVG(qa.answer_score) v
        FROM questionnaire_answers qa
        INNER JOIN evaluation_tracker et ON et.id = qa.tracker_id
        WHERE et.eval_type='student' AND et.target_user_id=?
    ", "i", [$viewId]);

    // Per-question breakdown, if a questionnaire_questions table exists.
    $questionBreakdown = safe_rows($mysqli, "
        SELECT qq.question_text AS question, AVG(qa.answer_score) avg_score, COUNT(qa.id) responses
        FROM questionnaire_answers qa
        INNER JOIN evaluation_tracker et ON et.id = qa.tracker_id
        INNER JOIN questionnaire_questions qq ON qq.id = qa.question_id
        WHERE et.eval_type='student' AND et.target_user_id=?" . ($period ? " AND et.period_id=?" : "") . "
        GROUP BY qq.id, qq.question_text
        ORDER BY qq.id
    ", $period ? "ii" : "i", $period ? [$viewId, $period_id_int] : [$viewId]);

    html_head_open('PBI — ' . ($teacher['full_name'] ?? 'Teacher'));
    render_principal_sidebar('teachers', $me, $scopeLabel, $photo_src);
    ?>
    <main class="main">
        <a class="back-link" href="principal_teachers.php"><i class="fa-solid fa-arrow-left"></i> Back to Teachers</a>

        <div class="section">
            <div class="profile-card">
                <img class="profile-photo-lg" src="<?= htmlspecialchars($tPhoto) ?>" alt="">
                <div>
                    <div class="page-title" style="font-size:22px;"><?= htmlspecialchars($teacher['full_name']) ?></div>
                    <div class="page-sub"><?= htmlspecialchars($teacher['designation'] ?? 'Teacher') ?> · <?= htmlspecialchars($teacher['email'] ?? '') ?></div>
                    <div class="page-sub">
                        <?= !empty($assignedGradeList) ? 'Grade ' . htmlspecialchars(implode(', ', $assignedGradeList)) : 'No grade level assigned yet' ?>
                    </div>
                </div>
            </div>

            <div class="tracker-grid">
                <div class="tracker-item"><div class="big"><?= $completionPct ?>%</div><div class="lbl">Evaluation Completion</div></div>
                <div class="tracker-item"><div class="big"><?= $completed ?> / <?= $possibleEvaluators ?></div><div class="lbl">Evaluations Received</div></div>
                <div class="tracker-item"><div class="big"><?= $avgRating !== null ? round((float)$avgRating, 2) : '—' ?></div><div class="lbl">Average Rating</div></div>
            </div>
        </div>

        <div class="section">
            <h2><i class="fa-solid fa-list-check"></i> Rating Breakdown by Question</h2>
            <?php if (empty($questionBreakdown)): ?>
                <p class="empty-note">No per-question breakdown is available yet for this teacher.</p>
            <?php else: ?>
                <table class="data">
                    <thead><tr><th>Question</th><th>Average Score</th><th>Responses</th></tr></thead>
                    <tbody>
                    <?php foreach ($questionBreakdown as $q): ?>
                        <tr>
                            <td><?= htmlspecialchars($q['question']) ?></td>
                            <td style="min-width:140px;">
                                <div class="bar-wrap"><div class="bar-fill" style="width:<?= min(100, round(((float)$q['avg_score']) / 5 * 100)) ?>%"></div></div>
                                <span style="font-size:11px;color:var(--muted);"><?= round((float)$q['avg_score'], 2) ?></span>
                            </td>
                            <td><?= (int)$q['responses'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </main>
    </body></html>
    <?php
    $mysqli->close();
    exit;
}

// ═══════════════════════════════════════════════════════════
// LIST VIEW — all teachers in scope, searchable / filterable
// ═══════════════════════════════════════════════════════════
$q  = trim($_GET['q'] ?? '');
$yr = trim($_GET['yr'] ?? '');

$types = '';
$params = [];
$joinYear = '';

if ($yr !== '' && in_array($yr, $scopeGrades, true)) {
    $joinYear = "INNER JOIN user_year_levels uyl ON uyl.user_id = u.id AND uyl.year_level = ?";
    $types .= 's';
    $params[] = $yr;
}

$sql = "
    SELECT u.id, u.full_name, u.photo FROM users u
    $joinYear
    WHERE u.role='teacher' AND u.is_active=1 AND u.account_status='approved'
      AND u.academic_level IN ($scopeAcademicIn)
";
if ($q !== '') {
    $sql .= " AND u.full_name LIKE ?";
    $types .= 's';
    $params[] = '%' . $q . '%';
}
$sql .= " ORDER BY u.full_name";
$teachers = safe_rows($mysqli, $sql, $types, $params);

$teacherRows = [];
foreach ($teachers as $row) {
    $tid = (int)$row['id'];
    $grades = array_column(safe_rows($mysqli, "SELECT year_level FROM user_year_levels WHERE user_id=? ORDER BY year_level", "i", [$tid]), 'year_level');

    $completed = $period ? (int)(safe_scalar($mysqli, "
        SELECT COUNT(DISTINCT evaluator_id) c FROM evaluation_tracker
        WHERE eval_type='student' AND period_id=? AND target_user_id=?
    ", "ii", [$period_id_int, $tid]) ?? 0) : 0;

    $avgRating = safe_scalar($mysqli, "
        SELECT AVG(qa.answer_score) v
        FROM questionnaire_answers qa
        INNER JOIN evaluation_tracker et ON et.id = qa.tracker_id
        WHERE et.eval_type='student' AND et.target_user_id=?" . ($period ? " AND et.period_id=?" : ""),
        $period ? "ii" : "i",
        $period ? [$tid, $period_id_int] : [$tid]
    );

    $teacherRows[] = [
        'id'        => $tid,
        'name'      => $row['full_name'],
        'photo'     => !empty($row['photo']) ? '../image/' . $row['photo'] : '../image/pbi_logo',
        'grades'    => $grades,
        'completed' => $completed,
        'avg'       => $avgRating !== null ? round((float)$avgRating, 2) : null,
    ];
}

html_head_open('PBI — Teachers');
render_principal_sidebar('teachers', $me, $scopeLabel, $photo_src);
?>
<main class="main">
    <div class="page-header">
        <div>
            <div class="page-title">Teachers</div>
            <div class="page-sub"><?= count($teacherRows) ?> teacher<?= count($teacherRows) === 1 ? '' : 's' ?> · <?= htmlspecialchars($scopeLabel) ?></div>
        </div>
        <?php render_period_badge($settings); ?>
    </div>

    <div class="section">
        <form class="search-box" method="get">
            <input type="text" name="q" placeholder="Search by name…" value="<?= htmlspecialchars($q) ?>">
            <select name="yr">
                <option value="">All Grades</option>
                <?php foreach ($scopeGrades as $g): ?>
                    <option value="<?= htmlspecialchars($g) ?>" <?= $yr === $g ? 'selected' : '' ?>>Grade <?= htmlspecialchars($g) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
            <?php if ($q !== '' || $yr !== ''): ?><a class="btn" href="principal_teachers.php"><i class="fa-solid fa-xmark"></i> Clear</a><?php endif; ?>
        </form>

        <table class="data">
            <thead><tr><th>Teacher</th><th>Grade(s)</th><th>Evaluations Received</th><th>Avg Rating</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($teacherRows)): ?>
                <tr><td colspan="5" class="empty-note">No teachers match this search.</td></tr>
            <?php else: foreach ($teacherRows as $t): ?>
                <tr class="row-link" onclick="window.location='principal_teachers.php?id=<?= $t['id'] ?>'">
                    <td><img class="avatar-sm" src="<?= htmlspecialchars($t['photo']) ?>" alt=""><?= htmlspecialchars($t['name']) ?></td>
                    <td><?= !empty($t['grades']) ? htmlspecialchars(implode(', ', $t['grades'])) : '<span class="empty-note">Unassigned</span>' ?></td>
                    <td><?= $t['completed'] ?></td>
                    <td><?= $t['avg'] !== null ? $t['avg'] : '<span class="empty-note">N/A</span>' ?></td>
                    <td><a class="btn" href="principal_teachers.php?id=<?= $t['id'] ?>"><i class="fa-solid fa-eye"></i> View</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</main>
</body></html>
<?php $mysqli->close(); ?>
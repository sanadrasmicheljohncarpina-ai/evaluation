    <?php
    require_once 'principal_common.php';

    $viewId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    // ═══════════════════════════════════════════════════════════
    // DETAIL VIEW — a single staff member
    // ═══════════════════════════════════════════════════════════
    if ($viewId > 0) {
        $stmt = $mysqli->prepare("
            SELECT id, full_name, username, email, designation, photo, academic_level
            FROM users
            WHERE id=? AND role='staff' AND academic_level IN ($scopeAcademicIn)
            LIMIT 1
        ");
        $stmt->bind_param("i", $viewId);
        $stmt->execute();
        $staffMember = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$staffMember) {
            html_head_open('PBI — Staff Not Found');
            render_principal_sidebar('staff', $me, $scopeLabel, $photo_src);
            ?>
            <main class="main">
                <a class="back-link" href="principal_staff.php"><i class="fa-solid fa-arrow-left"></i> Back to Staff</a>
                <div class="section"><p class="empty-note">That staff member wasn't found, or isn't within your scope.</p></div>
            </main>
            </body></html>
            <?php
            $mysqli->close();
            exit;
        }

        $sPhoto = !empty($staffMember['photo']) ? '../image/' . $staffMember['photo'] : '../image/pbi_logo';

        $completed = $period ? (int)(safe_scalar($mysqli, "
            SELECT COUNT(DISTINCT evaluator_id) c FROM evaluation_tracker
            WHERE eval_type='student' AND period_id=? AND target_user_id=?
        ", "ii", [$period_id_int, $viewId]) ?? 0) : 0;

        $possibleEvaluators = (int)(safe_scalar($mysqli, "
            SELECT COUNT(*) c FROM users
            WHERE role='student' AND is_active=1 AND account_status='approved' AND grade_level IN ($scopeGradesIn)
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

        $questionBreakdown = safe_rows($mysqli, "
            SELECT qq.question_text AS question, AVG(qa.answer_score) avg_score, COUNT(qa.id) responses
            FROM questionnaire_answers qa
            INNER JOIN evaluation_tracker et ON et.id = qa.tracker_id
            INNER JOIN questionnaire_questions qq ON qq.id = qa.question_id
            WHERE et.eval_type='student' AND et.target_user_id=?" . ($period ? " AND et.period_id=?" : "") . "
            GROUP BY qq.id, qq.question_text
            ORDER BY qq.id
        ", $period ? "ii" : "i", $period ? [$viewId, $period_id_int] : [$viewId]);

        html_head_open('PBI — ' . ($staffMember['full_name'] ?? 'Staff'));
        render_principal_sidebar('staff', $me, $scopeLabel, $photo_src);
        ?>
        <main class="main">
            <a class="back-link" href="principal_staff.php"><i class="fa-solid fa-arrow-left"></i> Back to Staff</a>

            <div class="section">
                <div class="profile-card">
                    <img class="profile-photo-lg" src="<?= htmlspecialchars($sPhoto) ?>" alt="">
                    <div>
                        <div class="page-title" style="font-size:22px;"><?= htmlspecialchars($staffMember['full_name']) ?></div>
                        <div class="page-sub"><?= htmlspecialchars($staffMember['designation'] ?? 'Staff') ?> · <?= htmlspecialchars($staffMember['email'] ?? '') ?></div>
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
                    <p class="empty-note">No per-question breakdown is available yet for this staff member.</p>
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
    // LIST VIEW — all staff in scope, searchable
    // ═══════════════════════════════════════════════════════════
    $q = trim($_GET['q'] ?? '');

    $sql = "
        SELECT id, full_name, photo FROM users
        WHERE role='staff' AND is_active=1 AND account_status='approved'
          AND academic_level IN ($scopeAcademicIn)
    ";
    $types = '';
    $params = [];
    if ($q !== '') {
        $sql .= " AND full_name LIKE ?";
        $types = 's';
        $params[] = '%' . $q . '%';
    }
    $sql .= " ORDER BY full_name";
    $staffList = safe_rows($mysqli, $sql, $types, $params);

    $staffRows = [];
    foreach ($staffList as $row) {
        $sid = (int)$row['id'];
        $completed = $period ? (int)(safe_scalar($mysqli, "
            SELECT COUNT(DISTINCT evaluator_id) c FROM evaluation_tracker
            WHERE eval_type='student' AND period_id=? AND target_user_id=?
        ", "ii", [$period_id_int, $sid]) ?? 0) : 0;

        $avgRating = safe_scalar($mysqli, "
            SELECT AVG(qa.answer_score) v
            FROM questionnaire_answers qa
            INNER JOIN evaluation_tracker et ON et.id = qa.tracker_id
            WHERE et.eval_type='student' AND et.target_user_id=?" . ($period ? " AND et.period_id=?" : ""),
            $period ? "ii" : "i",
            $period ? [$sid, $period_id_int] : [$sid]
        );

        $staffRows[] = [
            'id'        => $sid,
            'name'      => $row['full_name'],
            'photo'     => !empty($row['photo']) ? '../image/' . $row['photo'] : '../image/pbi_logo',
            'completed' => $completed,
            'avg'       => $avgRating !== null ? round((float)$avgRating, 2) : null,
        ];
    }

    html_head_open('PBI — Staff');
    render_principal_sidebar('staff', $me, $scopeLabel, $photo_src);
    ?>
    <main class="main">
        <div class="page-header">
            <div>
                <div class="page-title">School Staff</div>
                <div class="page-sub"><?= count($staffRows) ?> staff member<?= count($staffRows) === 1 ? '' : 's' ?> · <?= htmlspecialchars($scopeLabel) ?></div>
            </div>
            <?php render_period_badge($settings); ?>
        </div>

        <div class="section">
            <form class="search-box" method="get">
                <input type="text" name="q" placeholder="Search by name…" value="<?= htmlspecialchars($q) ?>">
                <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                <?php if ($q !== ''): ?><a class="btn" href="principal_staff.php"><i class="fa-solid fa-xmark"></i> Clear</a><?php endif; ?>
            </form>

            <table class="data">
                <thead><tr><th>Staff Member</th><th>Evaluations Received</th><th>Avg Rating</th><th></th></tr></thead>
                <tbody>
                <?php if (empty($staffRows)): ?>
                    <tr><td colspan="4" class="empty-note">No staff match this search.</td></tr>
                <?php else: foreach ($staffRows as $s): ?>
                    <tr class="row-link" onclick="window.location='principal_staff.php?id=<?= $s['id'] ?>'">
                        <td><img class="avatar-sm" src="<?= htmlspecialchars($s['photo']) ?>" alt=""><?= htmlspecialchars($s['name']) ?></td>
                        <td><?= $s['completed'] ?></td>
                        <td><?= $s['avg'] !== null ? $s['avg'] : '<span class="empty-note">N/A</span>' ?></td>
                        <td><a class="btn" href="principal_staff.php?id=<?= $s['id'] ?>"><i class="fa-solid fa-eye"></i> View</a></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </main>
    </body></html>
    <?php $mysqli->close(); ?>
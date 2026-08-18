<?php
// principal_evaluations.php
// Evaluation workspace — mirrors the Dean portal's Evaluation page:
//   - Faculty tab = Teacher + Staff merged into one roster, with a
//     Department filter and an Include filter (All / Teachers Only /
//     Staff Only) doing the narrowing that used to be separate tabs.
//   - Executive Assistant tab stays separate — not department-scoped,
//     same as Dean's.
//   - Search box, CSV export of the current filtered view, and
//     server-side pagination (5 per page), same as Dean's.
//
// Built on principal_common.php: 'principal' auth guard, Basic Education
// scope, centralized System Settings ($settings), shared sidebar/styles.
//
// eval_type values: supervisor_to_teacher / supervisor_to_staff /
// supervisor_to_ea — shared schema with Dean's evaluate flow.
//
// NOTE: this page must not derive academic year/structure/term/period/
// status itself. All of that comes from $settings (set in
// principal_common.php via get_system_settings()) — the same source the
// Dashboard uses. Only query the DB here for things that are genuinely
// page-specific: the roster and who's been evaluated.
//
// ASSUMPTIONS made while mirroring the Dean page (double check / adjust):
//   1. principal_evaluate.php accepts an optional "&view=1" to open a
//      completed evaluation read-only — Dean's route uses this
//      convention, mirrored here. If principal_evaluate.php doesn't
//      support it yet, the "View" link below will need that handling
//      added, or should be dropped until it does.
//   2. Dean's Action column always shows "Evaluate" (even once
//      completed) plus a separate "View" link when completed — this
//      lets a dean re-open/redo an evaluation post-submission. Mirrored
//      as-is here; the old behavior only showed "Submitted" text with no
//      link once done. Change back to "Submitted"-only if resubmission
//      shouldn't be allowed for principals.
//   3. Previously the Evaluate link had no gating on $hasPeriod (only a
//      banner warned submissions were closed). To match Dean's pattern
//      (action column swaps to "Evaluation closed"/no-link when the
//      period isn't open), this version now gates on $hasPeriod too.
//      That's a real behavior change, not just a layout one — flagging
//      it explicitly.
//   4. The page-local <style> block below assumes the shared stylesheet
//      (loaded via html_head_open()) does NOT already define
//      .eval-tabs/.filter-bar/.export-btn/.role-pill/.table-footer/
//      .pagination. If it does, delete the block and use those classes.

require_once 'principal_common.php';

$user_id = $_SESSION['user_id'];

$toast       = $_SESSION['toast']       ?? ''; unset($_SESSION['toast']);
$toast_error = $_SESSION['toast_error'] ?? ''; unset($_SESSION['toast_error']);

$bucket_to_evaltype = ['Teacher' => 'supervisor_to_teacher', 'Staff' => 'supervisor_to_staff', 'Executive Assistant' => 'supervisor_to_ea'];

// ── TAB (Faculty [Teacher+Staff merged] / Executive Assistant) ─────────
// Same merge rationale as the Dean portal: one "Faculty" roster with a
// Department filter and an Include filter (All/Teachers/Staff) replaces
// what used to be separate Teacher and Staff tabs. Executive Assistant
// stays its own tab — EAs aren't department-scoped, and resolve_buckets()
// below never assigns EA as anyone's secondary role, so EA rows can't
// bleed into Faculty.
$validTabs = ['faculty', 'executive_assistant'];
$tab = $_GET['tab'] ?? 'faculty';
if (!in_array($tab, $validTabs, true)) $tab = 'faculty';

$tabLabels = ['faculty' => 'Faculty', 'executive_assistant' => 'Executive Assistant'];
$tabIcons  = ['faculty' => 'fa-users', 'executive_assistant' => 'fa-user-tie'];

// Defaults so the page still renders (with an explanatory notice) when
// Basic Education isn't the active academic structure.
$all_users      = [];
$ea_users       = [];
$done_pairs     = [];
$facultyMerged  = [];
$eaList         = [];
$facultyToEvaluate = 0;
$eaToEvaluate      = 0;
$agg_total = 0; $agg_done = 0; $agg_pending = 0; $agg_completion = 0;

if ($structureActive) {
    // ── FETCH APPROVED TEACHER/STAFF WITHIN BASIC EDUCATION SCOPE ───────
    // NOTE: filtered by whether the Super Admin has assigned this person a
    // Grade level within the Principal's scope via the "Assign Year
    // Level(s)" action (manage_privileged_accounts.php) — which writes to
    // the user_year_levels table. This is NOT filtered by users.academic_level:
    // that column exists (self-healing schema) but nothing ever writes to
    // it, so every row has it as NULL. user_year_levels is the one place
    // Teacher/Staff scope assignment is actually recorded.
    $scopeYearLevels = array_map(fn($g) => "Grade {$g}", $scopeGrades);
    $scopeYearLevelsIn = esc_list($mysqli, $scopeYearLevels);
    $ures = $mysqli->prepare("
        SELECT id, full_name, designation, photo, role, secondary_role, department
        FROM users
        WHERE role IN ('teacher','staff') AND is_active=1 AND account_status='approved'
          AND EXISTS (
              SELECT 1 FROM user_year_levels uyl
              WHERE uyl.user_id = users.id AND uyl.year_level IN ($scopeYearLevelsIn)
          )
        ORDER BY full_name ASC
    ");
    $ures->execute();
    $all_users = $ures->get_result()->fetch_all(MYSQLI_ASSOC);
    $ures->close();

    // ── FETCH APPROVED EXECUTIVE ASSISTANTS ──────────────────────
    // EA is NOT scoped by academic_level — not tied to Junior/Senior High.
    $eaRes = $mysqli->prepare("
        SELECT id, full_name, designation, photo, department
        FROM users
        WHERE role='executive_assistant' AND is_active=1 AND account_status='approved'
        ORDER BY full_name ASC
    ");
    $eaRes->execute();
    $ea_users = $eaRes->get_result()->fetch_all(MYSQLI_ASSOC);
    $eaRes->close();

    // ── WHICH TARGETS HAS THIS PRINCIPAL ALREADY EVALUATED (this period)? ──
    // Uses $period_id_int from the centralized $settings — not a
    // page-local period lookup.
    if ($period_id_int) {
        $dstmt = $mysqli->prepare("SELECT target_user_id, eval_type, submitted_at FROM evaluation_tracker WHERE evaluator_id=? AND eval_type IN ('supervisor_to_teacher','supervisor_to_staff','supervisor_to_ea') AND period_id=?");
        $dstmt->bind_param("ii", $user_id, $period_id_int);
        $dstmt->execute();
        $dres = $dstmt->get_result();
        if ($dres) while ($r = $dres->fetch_assoc()) $done_pairs[$r['target_user_id'] . '|' . $r['eval_type']] = $r['submitted_at'];
        $dstmt->close();
    }

    // ── BUILD MERGED FACULTY ROSTER (Teacher + Staff) ───────────────────
    // One row per bucket a person belongs to — a Multi-Role person (e.g.
    // primary Teacher + secondary Staff) still appears once under each
    // applicable bucket, same as the old three-tab version did.
    //   role_label: what's DISPLAYED in the Role column ("Teacher"/"Staff").
    //   eval_type:  which evaluation_tracker row + which
    //     principal_evaluate.php form this row routes to — kept per-row
    //     because merging the display must not blur which underlying
    //     form a Staff (vs Teacher) row actually needs.
    foreach ($all_users as $u) {
        if ($u['role'] === 'teacher') {
            $row = $u; $row['role_label'] = 'Teacher'; $row['eval_type'] = 'supervisor_to_teacher';
            $facultyMerged[] = $row;
        }
        if ($u['role'] === 'staff') {
            $row = $u; $row['role_label'] = 'Staff'; $row['eval_type'] = 'supervisor_to_staff';
            $facultyMerged[] = $row;
        }
        if (!empty($u['secondary_role']) && in_array($u['secondary_role'], ['teacher', 'staff'], true)) {
            $secLabel    = $u['secondary_role'] === 'teacher' ? 'Teacher' : 'Staff';
            $secEvalType = $u['secondary_role'] === 'teacher' ? 'supervisor_to_teacher' : 'supervisor_to_staff';
            // Don't double-add if the primary role already produced this bucket.
            $already = ($secLabel === 'Teacher' && $u['role'] === 'teacher') || ($secLabel === 'Staff' && $u['role'] === 'staff');
            if (!$already) {
                $row = $u; $row['role_label'] = $secLabel; $row['eval_type'] = $secEvalType;
                $facultyMerged[] = $row;
            }
        }
    }
    usort($facultyMerged, fn($a, $b) => strcmp($a['full_name'], $b['full_name']));

    foreach ($ea_users as $u) {
        $row = $u; $row['role_label'] = 'Executive Assistant'; $row['eval_type'] = 'supervisor_to_ea';
        $eaList[] = $row;
    }

    // ── ATTACH EVALUATION STATUS TO EACH ROW ────────────────────────────
    $withStatus = function (array $rows) use ($done_pairs) {
        foreach ($rows as &$r) {
            $key = $r['id'] . '|' . $r['eval_type'];
            $r['evaluation_status']    = isset($done_pairs[$key]) ? 'completed' : 'not_started';
            $r['last_evaluation_date'] = $done_pairs[$key] ?? null;
        }
        unset($r);
        return $rows;
    };
    $facultyMerged = $withStatus($facultyMerged);
    $eaList        = $withStatus($eaList);

    $rosterByTab  = ['faculty' => $facultyMerged, 'executive_assistant' => $eaList];
    $activeRoster = $rosterByTab[$tab];

    // ── SUMMARY CARDS (aggregate across both tabs, same as Dean) ────────
    $countCompleted = fn(array $rows) => count(array_filter($rows, fn($r) => $r['evaluation_status'] === 'completed'));
    $facultyToEvaluate = count($facultyMerged);
    $eaToEvaluate      = count($eaList);
    $agg_total      = $facultyToEvaluate + $eaToEvaluate;
    $agg_done       = $countCompleted($facultyMerged) + $countCompleted($eaList);
    $agg_pending    = max(0, $agg_total - $agg_done);
    $agg_completion = $agg_total > 0 ? (int) round($agg_done / $agg_total * 100) : 0;

    // ── FILTER OPTIONS (built from real roster data, not hardcoded) ─────
    // Department dropdown only ever lists departments that actually exist
    // among current Faculty rows.
    $departmentOptions = [];
    foreach ($facultyMerged as $r) {
        $d = trim((string)($r['department'] ?? ''));
        if ($d !== '') $departmentOptions[$d] = true;
    }
    $departmentOptions = array_keys($departmentOptions);
    sort($departmentOptions);

    // "Include" options ARE a fixed 3-way set (All / Teachers / Staff) —
    // this isn't personnel data, it mirrors the two source roles the
    // merge itself is built from, so a fixed list here is legitimate,
    // unlike department.
    $includeOptions = ['all' => 'All Faculty', 'teacher' => 'Teachers Only', 'staff' => 'Staff Only'];

    // ── APPLY FILTERS + SEARCH (server-side, against the fetched roster) ──
    $deptFilter    = trim($_GET['dept'] ?? 'all');
    $includeFilter = $_GET['include'] ?? 'all';
    if (!in_array($includeFilter, array_keys($includeOptions), true)) $includeFilter = 'all';
    $search = trim($_GET['q'] ?? '');

    $filteredRoster = $activeRoster;
    if ($tab === 'faculty') {
        if ($includeFilter !== 'all') {
            $wantRole = $includeFilter === 'teacher' ? 'Teacher' : 'Staff';
            $filteredRoster = array_values(array_filter($filteredRoster, fn($r) => $r['role_label'] === $wantRole));
        }
        if ($deptFilter !== 'all' && $deptFilter !== '') {
            $filteredRoster = array_values(array_filter($filteredRoster, fn($r) => ($r['department'] ?? '') === $deptFilter));
        }
    }
    if ($search !== '') {
        $needle = mb_strtolower($search);
        $filteredRoster = array_values(array_filter($filteredRoster, function ($r) use ($needle) {
            $haystack = mb_strtolower(($r['full_name'] ?? '') . ' ' . ($r['department'] ?? '') . ' ' . ($r['designation'] ?? ''));
            return str_contains($haystack, $needle);
        }));
    }

    // ── EXPORT (CSV of the currently filtered roster) — must run before
    // any HTML output ────────────────────────────────────────────────────
    if (($_GET['export'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="principal_' . $tab . '_export_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        if ($tab === 'faculty') {
            fputcsv($out, ['Full Name', 'Department', 'Position', 'Role', 'Evaluation Status', 'Last Evaluation Date']);
            foreach ($filteredRoster as $r) {
                fputcsv($out, [
                    $r['full_name'], $r['department'] ?: '', $r['designation'] ?: '', $r['role_label'],
                    $r['evaluation_status'], $r['last_evaluation_date'] ?: '',
                ]);
            }
        } else {
            fputcsv($out, ['Full Name', 'Position', 'Role', 'Evaluation Status', 'Last Evaluation Date']);
            foreach ($filteredRoster as $r) {
                fputcsv($out, [
                    $r['full_name'], $r['designation'] ?: '', 'Executive Assistant',
                    $r['evaluation_status'], $r['last_evaluation_date'] ?: '',
                ]);
            }
        }
        fclose($out);
        $mysqli->close();
        exit;
    }

    // ── PAGINATION ────────────────────────────────────────────────────
    $perPage       = 5;
    $totalFiltered = count($filteredRoster);
    $totalPages    = max(1, (int) ceil($totalFiltered / $perPage));
    $page          = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
    $pageRoster    = array_slice($filteredRoster, ($page - 1) * $perPage, $perPage);
    $showingFrom   = $totalFiltered === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $showingTo     = min($totalFiltered, $page * $perPage);
}

// Helper to rebuild the current query string with one param overridden —
// used by filter controls, search, and pagination links. No DB
// dependency, so it's fine outside the $structureActive block.
function principal_eval_qs(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    // Changing a filter/search always resets back to page 1.
    if (!isset($overrides['page'])) $params['page'] = 1;
    return htmlspecialchars('?' . http_build_query($params));
}

$mysqli->close();

html_head_open('PBI — Principal Evaluations');
?>
<style>
/* Page-local additions to mirror the Dean portal's Evaluation layout.
   Reuses Principal's amber accent if the shared stylesheet defines
   --accent; falls back to a literal amber otherwise. If the shared
   stylesheet already ships .eval-tabs/.filter-bar/.export-btn/etc.,
   delete this block and use those instead (see assumption #4 above). */
:root{ --eval-accent: var(--accent, #D99A2B); }
.eval-tabs{display:flex;gap:4px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:4px;margin-bottom:22px;width:fit-content;flex-wrap:wrap;}
.eval-tab{padding:10px 22px;border-radius:8px;font-size:13px;font-weight:600;color:var(--muted,#A0B3C6);text-decoration:none;display:flex;align-items:center;gap:8px;transition:all .2s;}
.eval-tab.active{background:var(--eval-accent);color:#fff;}
.eval-tab:not(.active):hover{background:rgba(255,255,255,.05);}
.eval-tab .badge{background:rgba(255,255,255,.15);border-radius:20px;padding:1px 8px;font-size:11px;font-weight:700;}
.eval-tab.active .badge{background:rgba(255,255,255,.25);}
.filter-bar{display:flex;align-items:flex-end;gap:20px;flex-wrap:wrap;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:18px 20px;margin-bottom:18px;}
.filter-field{display:flex;flex-direction:column;gap:6px;}
.filter-field label{font-size:10.5px;font-weight:700;color:var(--muted,#A0B3C6);text-transform:uppercase;letter-spacing:.5px;}
.filter-field select{background:rgba(0,0,0,.2);border:1px solid rgba(255,255,255,.12);color:var(--light,#E0E6F0);padding:9px 12px;border-radius:8px;font-size:13px;min-width:190px;}
.filter-field select option{color:#0A192F;background:#fff;}
.filter-hint{font-size:10.5px;color:var(--muted,#A0B3C6);margin-top:2px;}
.search-wrap{flex:1;min-width:220px;position:relative;}
.search-wrap input{width:100%;background:rgba(0,0,0,.2);border:1px solid rgba(255,255,255,.12);color:inherit;padding:9px 36px 9px 12px;border-radius:8px;font-size:13px;}
.search-wrap i{position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--muted,#A0B3C6);font-size:13px;}
.export-btn{background:rgba(217,154,43,.14);border:1px solid rgba(217,154,43,.35);color:var(--eval-accent);padding:10px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:7px;white-space:nowrap;align-self:flex-end;}
.export-btn:hover{background:rgba(217,154,43,.22);}
.role-pill{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;background:rgba(255,255,255,.08);}
.table-footer{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;font-size:12.5px;color:var(--muted,#A0B3C6);flex-wrap:wrap;gap:10px;}
.pagination{display:flex;align-items:center;gap:6px;}
.page-btn{width:30px;height:30px;display:flex;align-items:center;justify-content:center;border-radius:7px;background:rgba(0,0,0,.2);border:1px solid rgba(255,255,255,.1);color:var(--muted,#A0B3C6);text-decoration:none;font-size:12.5px;font-weight:600;}
.page-btn.active{background:var(--eval-accent);color:#fff;border-color:var(--eval-accent);}
.page-btn.disabled{opacity:.35;pointer-events:none;}
</style>

<?php render_principal_sidebar('evaluations', $me, $scopeLabel, $photo_src); ?>

<main class="main">
    <div class="page-header">
        <div>
            <div class="page-title">Evaluation</div>
            <div class="page-sub">Evaluate Faculty &amp; Executive Assistants — <?= htmlspecialchars($scopeLabel) ?></div>
        </div>
        <?php render_period_badge($settings); ?>
    </div>

    <?php if ($toast): ?>
    <div class="alert success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($toast) ?></div>
    <?php endif; ?>
    <?php if ($toast_error): ?>
    <div class="alert error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($toast_error) ?></div>
    <?php endif; ?>

    <?php if (!$structureActive): ?>
        <?php render_scope_status($settings, 'evaluation'); ?>

    <!-- DASH-STATE STATS — page keeps its shape instead of disappearing;
         see render_scope_status() for why nothing is queryable right now. -->
    <div class="card-grid">
        <div class="stat-card"><i class="fa-solid fa-users"></i><div class="num">—</div><div class="label">Faculty to Evaluate (Teachers + Staff)</div></div>
        <div class="stat-card"><i class="fa-solid fa-user-tie"></i><div class="num">—</div><div class="label">Executive Assistants to Evaluate</div></div>
        <div class="stat-card"><i class="fa-solid fa-circle-check"></i><div class="num">—</div><div class="label">Completed Evaluations</div></div>
        <div class="stat-card"><i class="fa-solid fa-hourglass-half"></i><div class="num">—</div><div class="label">Pending Evaluations</div></div>
        <div class="stat-card"><i class="fa-solid fa-chart-simple"></i><div class="num">—</div><div class="label">Completion Percentage</div></div>
    </div>
    <div class="alert error" style="margin-top:-10px;"><i class="fa-solid fa-hourglass-half"></i> Waiting for <?= BASIC_ED_LABEL ?> evaluation period</div>

    <?php else: ?>

    <!-- SUMMARY CARDS -->
    <div class="card-grid">
        <div class="stat-card"><i class="fa-solid fa-users"></i><div class="num"><?= $facultyToEvaluate ?></div><div class="label">Faculty to Evaluate (Teachers + Staff)</div></div>
        <div class="stat-card"><i class="fa-solid fa-user-tie"></i><div class="num"><?= $eaToEvaluate ?></div><div class="label">Executive Assistants to Evaluate</div></div>
        <div class="stat-card"><i class="fa-solid fa-circle-check"></i><div class="num"><?= $agg_done ?></div><div class="label">Completed Evaluations</div></div>
        <div class="stat-card"><i class="fa-solid fa-hourglass-half"></i><div class="num"><?= $agg_pending ?></div><div class="label">Pending Evaluations</div></div>
        <div class="stat-card"><i class="fa-solid fa-chart-simple"></i><div class="num"><?= $agg_completion ?>%</div><div class="label">Completion Percentage</div></div>
    </div>

    <?php if (!$hasPeriod): ?>
    <div class="alert error"><i class="fa-solid fa-clock"></i> No active evaluation period. You can browse the roster, but submissions are closed until an admin opens a period.</div>
    <?php endif; ?>

    <!-- TABS -->
    <div class="eval-tabs">
        <?php foreach ($validTabs as $t): ?>
        <a class="eval-tab <?= $tab === $t ? 'active' : '' ?>" href="?tab=<?= $t ?>">
            <i class="fa-solid <?= $tabIcons[$t] ?>"></i> <?= $tabLabels[$t] ?>
            <span class="badge"><?= count($rosterByTab[$t]) ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- FILTER BAR -->
    <form class="filter-bar" method="get">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>"/>
        <?php if ($tab === 'faculty'): ?>
        <div class="filter-field">
            <label for="deptSelect">Department</label>
            <select id="deptSelect" name="dept" onchange="this.form.submit()">
                <option value="all" <?= $deptFilter === 'all' ? 'selected' : '' ?>>All Departments</option>
                <?php foreach ($departmentOptions as $d): ?>
                <option value="<?= htmlspecialchars($d) ?>" <?= $deptFilter === $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (empty($departmentOptions)): ?>
            <span class="filter-hint">No departments on file yet for current Faculty.</span>
            <?php endif; ?>
        </div>
        <div class="filter-field">
            <label for="includeSelect">Include</label>
            <select id="includeSelect" name="include" onchange="this.form.submit()">
                <?php foreach ($includeOptions as $val => $lbl): ?>
                <option value="<?= $val ?>" <?= $includeFilter === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
            </select>
            <span class="filter-hint">Teachers + Staff</span>
        </div>
        <?php endif; ?>
        <div class="search-wrap">
            <label style="font-size:10.5px;font-weight:700;color:var(--muted,#A0B3C6);text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:6px;">Search</label>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name or department..."/>
            <i class="fa-solid fa-magnifying-glass"></i>
        </div>
        <a class="export-btn" href="<?= principal_eval_qs(['export' => 'csv']) ?>">
            <i class="fa-solid fa-download"></i> Export List
        </a>
    </form>

    <!-- ROSTER TABLE -->
    <div class="section" style="padding:0;overflow:hidden;">
    <table class="data">
        <thead>
            <tr>
                <th>Profile</th>
                <th>Full Name</th>
                <?php if ($tab === 'faculty'): ?>
                    <th>Department</th><th>Position</th><th>Role</th>
                <?php else: ?>
                    <th>Position</th><th>Role</th>
                <?php endif; ?>
                <th>Evaluation Status</th>
                <th>Last Evaluation Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php $colspan = $tab === 'faculty' ? 8 : 7; ?>
        <?php if (empty($pageRoster)): ?>
        <tr><td colspan="<?= $colspan ?>">
            <p class="empty-note" style="text-align:center;padding:40px 0;">No <?= strtolower($tabLabels[$tab]) ?> match the current filters.</p>
        </td></tr>
        <?php else: foreach ($pageRoster as $p): ?>
        <tr>
            <td>
                <?php if (!empty($p['photo'])): ?>
                <img class="avatar-sm" src="../image/<?= htmlspecialchars($p['photo']) ?>" alt="">
                <?php else: ?>
                <div class="avatar-sm" style="display:inline-flex;align-items:center;justify-content:center;background:var(--inner,rgba(0,0,0,.2));color:var(--muted,#A0B3C6);"><i class="fa-solid fa-user" style="font-size:12px;"></i></div>
                <?php endif; ?>
            </td>
            <td style="font-weight:600;"><?= htmlspecialchars($p['full_name']) ?></td>
            <?php if ($tab === 'faculty'): ?>
                <td><?= !empty($p['department']) ? htmlspecialchars($p['department']) : '<span class="empty-note">—</span>' ?></td>
                <td><?= htmlspecialchars($p['designation'] ?: $p['role_label']) ?></td>
                <td><span class="role-pill"><?= htmlspecialchars($p['role_label']) ?></span></td>
            <?php else: ?>
                <td><?= htmlspecialchars($p['designation'] ?: 'Executive Assistant') ?></td>
                <td><span class="role-pill">Executive Assistant</span></td>
            <?php endif; ?>
            <td>
                <?php if ($p['evaluation_status'] === 'completed'): ?>
                <span class="pill good"><i class="fa-solid fa-circle-check" style="font-size:9px;"></i> Completed</span>
                <?php else: ?>
                <span class="pill warn"><i class="fa-solid fa-hourglass-half" style="font-size:9px;"></i> Not Started</span>
                <?php endif; ?>
            </td>
            <td><?= $p['last_evaluation_date'] ? htmlspecialchars(date('M j, Y', strtotime($p['last_evaluation_date']))) : '<span class="empty-note">—</span>' ?></td>
            <td>
                <?php if (!$hasPeriod): ?>
                    <span class="empty-note">Evaluation closed</span>
                <?php else: ?>
                    <a class="btn" href="principal_evaluate.php?tid=<?= (int)$p['id'] ?>&bucket=<?= urlencode($p['role_label']) ?>"><i class="fa-solid fa-pen-to-square"></i> Evaluate</a>
                    <?php if ($p['evaluation_status'] === 'completed'): ?>
                    <a class="btn" style="margin-left:6px;opacity:.75;" href="principal_evaluate.php?tid=<?= (int)$p['id'] ?>&bucket=<?= urlencode($p['role_label']) ?>&view=1"><i class="fa-solid fa-eye"></i> View</a>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?php if ($totalFiltered > 0): ?>
    <div class="table-footer">
        <div>Showing <?= $showingFrom ?> to <?= $showingTo ?> of <?= $totalFiltered ?> <?= strtolower($tabLabels[$tab]) ?> member<?= $totalFiltered === 1 ? '' : 's' ?></div>
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <a class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= principal_eval_qs(['page' => max(1, $page - 1)]) ?>"><i class="fa-solid fa-chevron-left"></i></a>
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a class="page-btn <?= $i === $page ? 'active' : '' ?>" href="<?= principal_eval_qs(['page' => $i]) ?>"><?= $i ?></a>
            <?php endfor; ?>
            <a class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= principal_eval_qs(['page' => min($totalPages, $page + 1)]) ?>"><i class="fa-solid fa-chevron-right"></i></a>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    </div>
    <?php if ($tab === 'faculty'): ?>
    <p class="filter-hint" style="margin-top:10px;">Faculty includes all teaching and non-teaching personnel (Teachers and Staff) under <?= htmlspecialchars($scopeLabel) ?>.</p>
    <?php endif; ?>

    <?php endif; ?>
</main>
</body>
</html>